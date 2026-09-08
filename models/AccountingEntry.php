<?php
/**
 * Model : écritures comptables (acc_entries + acc_entry_lines)
 * Partie double stricte, comme Sage i7 :
 *  - une pièce = en-tête + >= 2 lignes
 *  - chaque ligne est débit OU crédit (jamais les deux)
 *  - la somme des débits doit être égale à la somme des crédits
 */
require_once __DIR__ . '/../config/database.php';

class AccountingEntry
{
    private const EPSILON = 0.005;

    public static function search(array $f = [], int $limit = 25, int $offset = 0): array
    {
        [$where, $params] = self::buildWhere($f);
        $sql = "SELECT e.*, j.code AS journal_code, j.label AS journal_label,
                       p.code AS period_code, tp.code AS third_code, tp.name AS third_name,
                       COALESCE(t.debit, 0)  AS total_debit,
                       COALESCE(t.credit, 0) AS total_credit,
                       u.full_name AS created_by_name
                FROM acc_entries e
                JOIN acc_journals j ON j.id = e.journal_id
                JOIN acc_periods p  ON p.id = e.period_id
                LEFT JOIN acc_third_parties tp ON tp.id = e.third_party_id
                LEFT JOIN users u ON u.id = e.created_by
                LEFT JOIN (
                    SELECT entry_id, SUM(debit) AS debit, SUM(credit) AS credit
                    FROM acc_entry_lines GROUP BY entry_id
                ) t ON t.entry_id = e.id
                {$where}
                ORDER BY e.entry_date DESC, e.id DESC";
        if ($limit > 0) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $stmt = Database::getConnection()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        if ($limit > 0) {
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function countSearch(array $f = []): int
    {
        [$where, $params] = self::buildWhere($f);
        $stmt = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM acc_entries e
             JOIN acc_journals j ON j.id = e.journal_id
             {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private static function buildWhere(array $f): array
    {
        $conds = ['1=1'];
        $params = [];
        if (!empty($f['period_id'])) {
            $conds[] = 'e.period_id = :period';
            $params['period'] = (int)$f['period_id'];
        }
        if (!empty($f['journal_id'])) {
            $conds[] = 'e.journal_id = :journal';
            $params['journal'] = (int)$f['journal_id'];
        }
        if (!empty($f['status'])) {
            $conds[] = 'e.status = :status';
            $params['status'] = $f['status'];
        }
        if (!empty($f['date_from'])) {
            $conds[] = 'e.entry_date >= :from';
            $params['from'] = $f['date_from'];
        }
        if (!empty($f['date_to'])) {
            $conds[] = 'e.entry_date <= :to';
            $params['to'] = $f['date_to'];
        }
        if (!empty($f['q'])) {
            $conds[] = '(e.label ILIKE :q OR e.reference ILIKE :q OR CAST(e.entry_number AS TEXT) ILIKE :q)';
            $params['q'] = '%' . $f['q'] . '%';
        }
        return ['WHERE ' . implode(' AND ', $conds), $params];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT e.*, j.code AS journal_code, j.type AS journal_type, j.label AS journal_label,
                    p.code AS period_code, tp.code AS third_code, tp.name AS third_name,
                    u.full_name AS created_by_name
             FROM acc_entries e
             JOIN acc_journals j ON j.id = e.journal_id
             JOIN acc_periods p ON p.id = e.period_id
             LEFT JOIN acc_third_parties tp ON tp.id = e.third_party_id
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function lines(int $entryId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT l.*, a.number AS account_number, a.label AS account_label,
                    a.class AS account_class, tp.code AS third_code, tp.name AS third_name
             FROM acc_entry_lines l
             JOIN acc_accounts a ON a.id = l.account_id
             LEFT JOIN acc_third_parties tp ON tp.id = l.third_party_id
             WHERE l.entry_id = :id
             ORDER BY l.line_no'
        );
        $stmt->execute(['id' => $entryId]);
        return $stmt->fetchAll();
    }

    /** Prochain numéro de pièce pour un couple journal/exercice : ex ACH2026-0007 */
    public static function nextNumber(int $periodId, int $journalId): string
    {
        $pdo = Database::getConnection();
        $j = AccountingJournal::find($journalId);
        $p = AccountingPeriod::find($periodId);
        if (!$j || !$p) {
            return 'PIECE-0001';
        }
        $prefix = $j['code'] . substr((string)$p['code'], -4) . '-';
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(NULLIF(REGEXP_REPLACE(split_part(entry_number, '-', 2), '\\D', '', 'g'), '')::BIGINT), 0) + 1
             FROM acc_entries WHERE journal_id = :j AND period_id = :p"
        );
        $stmt->execute(['j' => $journalId, 'p' => $periodId]);
        return $prefix . str_pad((string)(int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array $header {period_id, journal_id, entry_number, entry_date, reference, third_party_id, label}
     * @param array $rawLines lignes brutes du formulaire
     * @return int id créé
     * @throws InvalidArgumentException|DomainException|RuntimeException
     */
    public static function create(array $header, array $rawLines): int
    {
        [$header, $lines] = self::validateAll($header, $rawLines);

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            // unicité du n° de pièce sur le couple exercice/journal
            $dup = $pdo->prepare(
                'SELECT COUNT(*) FROM acc_entries WHERE period_id = :p AND journal_id = :j AND entry_number = :n'
            );
            $dup->execute([
                'p' => $header['period_id'], 'j' => $header['journal_id'],
                'n' => $header['entry_number'],
            ]);
            if ((int)$dup->fetchColumn() > 0) {
                throw new DomainException("Le n° de pièce « {$header['entry_number']} » existe déjà dans ce journal.");
            }

            $stmt = $pdo->prepare(
                'INSERT INTO acc_entries
                    (period_id, journal_id, entry_number, entry_date, reference, third_party_id, label, status, created_by)
                 VALUES (:p, :j, :n, :d, :r, :t, :l, :s, :u)
                 RETURNING id'
            );
            $stmt->execute([
                'p' => $header['period_id'], 'j' => $header['journal_id'],
                'n' => $header['entry_number'], 'd' => $header['entry_date'],
                'r' => $header['reference'] !== '' ? $header['reference'] : null,
                't' => $header['third_party_id'] ?: null,
                'l' => $header['label'],
                's' => $header['status'],
                'u' => $header['created_by'] ?: null,
            ]);
            $entryId = (int)$stmt->fetchColumn();

            self::insertLines($pdo, $entryId, $lines);
            $pdo->commit();
            return $entryId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Modification autorisée uniquement sur une pièce brouillon. */
    public static function update(int $id, array $header, array $rawLines): void
    {
        $existing = self::find($id);
        if (!$existing) {
            throw new RuntimeException('Écriture introuvable.');
        }
        if ($existing['status'] === 'validated') {
            throw new RuntimeException('Une écriture validée ne peut plus être modifiée : dévalidez-la d\'abord.');
        }
        [$header, $lines] = self::validateAll($header, $rawLines);

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare(
                'SELECT COUNT(*) FROM acc_entries
                 WHERE period_id = :p AND journal_id = :j AND entry_number = :n AND id <> :id'
            );
            $dup->execute([
                'p' => $header['period_id'], 'j' => $header['journal_id'],
                'n' => $header['entry_number'], 'id' => $id,
            ]);
            if ((int)$dup->fetchColumn() > 0) {
                throw new DomainException("Le n° de pièce « {$header['entry_number']} » existe déjà dans ce journal.");
            }

            $stmt = $pdo->prepare(
                'UPDATE acc_entries SET
                    period_id = :p, journal_id = :j, entry_number = :n, entry_date = :d,
                    reference = :r, third_party_id = :t, label = :l, updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'p' => $header['period_id'], 'j' => $header['journal_id'],
                'n' => $header['entry_number'], 'd' => $header['entry_date'],
                'r' => $header['reference'] !== '' ? $header['reference'] : null,
                't' => $header['third_party_id'] ?: null,
                'l' => $header['label'], 'id' => $id,
            ]);

            $pdo->prepare('DELETE FROM acc_entry_lines WHERE entry_id = :id')->execute(['id' => $id]);
            self::insertLines($pdo, $id, $lines);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['draft', 'validated'], true)) {
            throw new InvalidArgumentException('Statut invalide.');
        }
        $entry = self::find($id);
        if (!$entry) {
            throw new RuntimeException('Écriture introuvable.');
        }
        if ($status === 'draft' && $entry['status'] === 'validated') {
            // dévalidation interdite si l'exercice est clôturé
            $period = AccountingPeriod::find((int)$entry['period_id']);
            if ($period && $period['status'] === 'closed') {
                throw new RuntimeException('Exercice clôturé : impossible de dévalider cette pièce.');
            }
        }
        if ($status === 'validated' && $entry['status'] === 'draft') {
            self::assertBalanced($id);
        }
        $stmt = Database::getConnection()
            ->prepare('UPDATE acc_entries SET status = :s, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['s' => $status, 'id' => $id]);
    }

    /** Suppression : uniquement en brouillon et hors exercice clôturé. */
    public static function delete(int $id): void
    {
        $entry = self::find($id);
        if (!$entry) {
            throw new RuntimeException('Écriture introuvable.');
        }
        if ($entry['status'] === 'validated') {
            throw new RuntimeException('Une écriture validée ne peut pas être supprimée : dévalidez-la d\'abord.');
        }
        $period = AccountingPeriod::find((int)$entry['period_id']);
        if ($period && $period['status'] === 'closed') {
            throw new RuntimeException('Exercice clôturé : suppression interdite.');
        }
        $stmt = Database::getConnection()->prepare('DELETE FROM acc_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /* =====================================================
       VALIDATIONS
       ===================================================== */

    /** Contrôle complet en-tête + lignes. Retourne [en-tête normalisé, lignes normalisées]. */
    private static function validateAll(array $h, array $rawLines): array
    {
        // --- En-tête ---
        $label = trim((string)($h['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('Le libellé de la pièce est obligatoire.');
        }
        $entryDate = trim((string)($h['entry_date'] ?? ''));
        $dt = DateTime::createFromFormat('Y-m-d', $entryDate);
        if (!$dt || $dt->format('Y-m-d') !== $entryDate) {
            throw new InvalidArgumentException('Date de pièce invalide.');
        }

        $periodId = (int)($h['period_id'] ?? 0);
        $period = AccountingPeriod::find($periodId);
        if (!$period) {
            throw new InvalidArgumentException('Exercice comptable introuvable.');
        }
        if ($period['status'] !== 'open') {
            throw new DomainException("L'exercice {$period['code']} est clôturé : saisie interdite.");
        }
        if ($entryDate < $period['start_date'] || $entryDate > $period['end_date']) {
            throw new DomainException(
                "La date de pièce doit être comprise entre {$period['start_date']} et {$period['end_date']}."
            );
        }

        $journalId = (int)($h['journal_id'] ?? 0);
        $journal = AccountingJournal::find($journalId);
        if (!$journal) {
            throw new InvalidArgumentException('Journal introuvable.');
        }
        if (!$journal['is_active']) {
            throw new DomainException("Le journal {$journal['code']} est désactivé.");
        }

        $thirdPartyId = (int)($h['third_party_id'] ?? 0);
        if ($thirdPartyId && !AccountingThirdParty::find($thirdPartyId)) {
            throw new InvalidArgumentException('Tiers introuvable.');
        }

        $number = strtoupper(trim((string)($h['entry_number'] ?? '')));
        if ($number === '') {
            $number = self::nextNumber($periodId, $journalId);
        }

        $reqStatus = ($h['status'] ?? 'draft');
        $header = [
            'period_id'      => $periodId,
            'journal_id'     => $journalId,
            'entry_number'   => $number,
            'entry_date'     => $entryDate,
            'reference'      => trim((string)($h['reference'] ?? '')),
            'third_party_id' => $thirdPartyId,
            'label'          => $label,
            'status'         => in_array($reqStatus, ['draft', 'validated'], true) ? $reqStatus : 'draft',
            'created_by'     => (int)($h['created_by'] ?? 0),
        ];

        // --- Lignes ---
        $lines = self::normalizeLines($rawLines);
        if (count($lines) < 2) {
            throw new DomainException('Une écriture doit comporter au moins deux lignes (partie double).');
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $line) {
            $account = AccountingAccount::find((int)$line['account_id']);
            if (!$account) {
                throw new InvalidArgumentException('Un compte saisi dans les lignes est introuvable.');
            }
            if (!$account['is_active']) {
                throw new DomainException("Le compte {$account['number']} est désactivé.");
            }
            if ($account['is_header']) {
                throw new DomainException("Le compte {$account['number']} (« {$account['label']} ») est une rubrique non imputable.");
            }
            if ($line['third_party_id'] && !AccountingThirdParty::find((int)$line['third_party_id'])) {
                throw new InvalidArgumentException('Tiers invalide sur une ligne.');
            }
            $totalDebit  += $line['debit'];
            $totalCredit += $line['credit'];
        }

        if (abs($totalDebit - $totalCredit) > self::EPSILON) {
            throw new DomainException(sprintf(
                'Écriture déséquilibrée : débits %.2f / crédits %.2f (écart %.2f).',
                $totalDebit, $totalCredit, abs($totalDebit - $totalCredit)
            ));
        }
        if ($totalDebit <= 0) {
            throw new DomainException('Le montant total de l\'écriture doit être supérieur à zéro.');
        }

        return [$header, $lines];
    }

    private static function normalizeLines(array $rawLines): array
    {
        $lines = [];
        foreach ($rawLines as $i => $raw) {
            $accountId = (int)($raw['account_id'] ?? 0);
            $debit  = self::toAmount($raw['debit'] ?? '');
            $credit = self::toAmount($raw['credit'] ?? '');

            if (!$accountId && $debit == 0.0 && $credit == 0.0) {
                continue; // ligne vide du formulaire
            }
            if (!$accountId) {
                throw new InvalidArgumentException('Ligne ' . ($i + 1) . ' : compte obligatoire.');
            }
            if ($debit > 0 && $credit > 0) {
                throw new DomainException('Ligne ' . ($i + 1) . ' : une ligne ne peut pas avoir un débit ET un crédit.');
            }
            if ($debit == 0.0 && $credit == 0.0) {
                throw new DomainException('Ligne ' . ($i + 1) . ' : le montant doit être supérieur à zéro.');
            }
            $due = trim((string)($raw['due_date'] ?? ''));
            if ($due !== '') {
                $ddt = DateTime::createFromFormat('Y-m-d', $due);
                if (!$ddt || $ddt->format('Y-m-d') !== $due) {
                    throw new InvalidArgumentException('Ligne ' . ($i + 1) . ' : échéance invalide.');
                }
            }
            $lines[] = [
                'account_id'     => $accountId,
                'third_party_id' => (int)($raw['third_party_id'] ?? 0) ?: null,
                'label'          => trim((string)($raw['label'] ?? '')),
                'debit'          => round($debit, 2),
                'credit'         => round($credit, 2),
                'due_date'       => $due !== '' ? $due : null,
            ];
        }
        // Tri des lignes par numéro de compte (convention Sage)
        if ($lines) {
            $ids = array_values(array_unique(array_column($lines, 'account_id')));
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = Database::getConnection()->prepare("SELECT id, number FROM acc_accounts WHERE id IN ({$in})");
            $stmt->execute($ids);
            $numbers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            usort($lines, static function (array $a, array $b) use ($numbers): int {
                return strcmp(
                    (string)($numbers[$a['account_id']] ?? ''),
                    (string)($numbers[$b['account_id']] ?? '')
                );
            });
        }
        return array_values($lines);
    }

    private static function insertLines(PDO $pdo, int $entryId, array $lines): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO acc_entry_lines
                (entry_id, line_no, account_id, third_party_id, label, debit, credit, due_date)
             VALUES (:eid, :no, :acc, :tp, :lbl, :deb, :cre, :due)'
        );
        foreach ($lines as $i => $l) {
            $stmt->execute([
                'eid' => $entryId,
                'no'  => $i + 1,
                'acc' => $l['account_id'],
                'tp'  => $l['third_party_id'],
                'lbl' => $l['label'] !== '' ? $l['label'] : null,
                'deb' => $l['debit'],
                'cre' => $l['credit'],
                'due' => $l['due_date'],
            ]);
        }
    }

    private static function assertBalanced(int $id): void
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT COALESCE(SUM(debit),0), COALESCE(SUM(credit),0) FROM acc_entry_lines WHERE entry_id = :id'
        );
        $stmt->execute(['id' => $id]);
        [$d, $c] = $stmt->fetch(PDO::FETCH_NUM);
        if (abs((float)$d - (float)$c) > self::EPSILON || (float)$d <= 0) {
            throw new RuntimeException('Impossible de valider : écriture déséquilibrée ou vide.');
        }
    }

    private static function toAmount(mixed $value): float
    {
        $v = strtolower(trim((string)$value));
        if ($v === '') {
            return 0.0;
        }
        // formats acceptés : 1234567.89 | 1 234 567,89 | 1,234,567.89
        $v = str_replace(["\xC2\xA0", ' '], '', $v);
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_contains($v, ',') && strrpos($v, ',') > strrpos($v, '.')
                ? str_replace('.', '', $v)
                : str_replace(',', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }
        if (!is_numeric($v)) {
            throw new InvalidArgumentException("Montant invalide : « " . trim((string)$value) . " ».");
        }
        return (float)$v;
    }
}
