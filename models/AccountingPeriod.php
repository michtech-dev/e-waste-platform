<?php
/**
 * Model : exercices comptables (acc_periods)
 */
require_once __DIR__ . '/../config/database.php';

class AccountingPeriod
{
    public static function all(): array
    {
        return Database::getConnection()
            ->query('SELECT * FROM acc_periods ORDER BY start_date DESC')
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM acc_periods WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Exercice ouvert par défaut : celui qui contient aujourd'hui, sinon le plus récent ouvert. */
    public static function current(): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            "SELECT * FROM acc_periods
             WHERE status = 'open' AND CURRENT_DATE BETWEEN start_date AND end_date
             ORDER BY start_date DESC LIMIT 1"
        );
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        $stmt = $pdo->query("SELECT * FROM acc_periods WHERE status = 'open' ORDER BY start_date DESC LIMIT 1");
        return $stmt->fetch() ?: null;
    }

    public static function hasEntries(int $id): bool
    {
        $stmt = Database::getConnection()->prepare('SELECT COUNT(*) FROM acc_entries WHERE period_id = :id');
        $stmt->execute(['id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Vérifie qu'un exercice ne chevauche pas un autre. */
    public static function overlaps(string $start, string $end, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM acc_periods WHERE start_date <= :end AND end_date >= :start';
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
        }
        $stmt = Database::getConnection()->prepare($sql);
        $params = ['start' => $start, 'end' => $end];
        if ($exceptId !== null) {
            $params['id'] = $exceptId;
        }
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @throws InvalidArgumentException données invalides
     * @throws DomainException chevauchement d'exercices
     */
    public static function create(array $d): int
    {
        [$code, $label, $start, $end] = self::assertValid($d);

        if ($code === '') {
            $code = substr($start, 0, 4);
        }
        $pdo = Database::getConnection();

        $exists = $pdo->prepare('SELECT COUNT(*) FROM acc_periods WHERE code = :c');
        $exists->execute(['c' => $code]);
        if ((int)$exists->fetchColumn() > 0) {
            throw new DomainException("Le code exercice « {$code} » existe déjà.");
        }
        if (self::overlaps($start, $end)) {
            throw new DomainException('Les dates de cet exercice chevauchent un exercice existant.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO acc_periods (code, label, start_date, end_date)
             VALUES (:code, :label, :start, :end) RETURNING id'
        );
        $stmt->execute(['code' => $code, 'label' => $label, 'start' => $start, 'end' => $end]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @throws InvalidArgumentException|DomainException|RuntimeException
     */
    public static function update(int $id, array $d): void
    {
        $period = self::find($id);
        if (!$period) {
            throw new RuntimeException('Exercice introuvable.');
        }
        if ($period['status'] === 'closed' && self::hasEntries($id)) {
            throw new RuntimeException('Un exercice clôturé contenant des écritures ne peut plus être modifié.');
        }

        [$code, $label, $start, $end] = self::assertValid($d);
        if ($code === '') {
            $code = substr($start, 0, 4);
        }

        $pdo = Database::getConnection();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM acc_periods WHERE code = :c AND id <> :id');
        $dup->execute(['c' => $code, 'id' => $id]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new DomainException("Le code exercice « {$code} » est déjà utilisé.");
        }
        if (self::overlaps($start, $end, $id)) {
            throw new DomainException('Les dates de cet exercice chevauchent un exercice existant.');
        }

        // Si les bornes changent, on vérifie que les écritures existantes restent dedans
        if ($period['status'] === 'closed') {
            $chk = $pdo->prepare(
                'SELECT COUNT(*) FROM acc_entries WHERE period_id = :id
                 AND (entry_date < :s OR entry_date > :e)'
            );
            $chk->execute(['id' => $id, 's' => $start, 'e' => $end]);
            if ((int)$chk->fetchColumn() > 0) {
                throw new DomainException('Des écritures existent en dehors des nouvelles dates.');
            }
        }

        $stmt = $pdo->prepare(
            'UPDATE acc_periods SET code = :code, label = :label, start_date = :start, end_date = :end
             WHERE id = :id'
        );
        $stmt->execute(['code' => $code, 'label' => $label, 'start' => $start, 'end' => $end, 'id' => $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['open', 'closed'], true)) {
            throw new InvalidArgumentException('Statut invalide.');
        }
        $stmt = Database::getConnection()
            ->prepare('UPDATE acc_periods SET status = :st WHERE id = :id');
        $stmt->execute(['st' => $status, 'id' => $id]);
    }

    /** @throws RuntimeException si l'exercice contient des écritures */
    public static function delete(int $id): void
    {
        if (self::hasEntries($id)) {
            throw new RuntimeException('Impossible de supprimer un exercice contenant des écritures.');
        }
        $stmt = Database::getConnection()->prepare('DELETE FROM acc_periods WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Clôture complète façon Sage :
     *  1. refus si pièces en brouillon dans l'exercice
     *  2. écriture OD de détermination du résultat (soldes des classes 6/7 → 120/129)
     *  3. création automatique de l'exercice suivant s'il n'existe pas
     *  4. passage de l'exercice au statut « closed »
     *
     * @return array{entry_id:?int, resultat:float, charges:float, produits:float, next_period:string}
     * @throws RuntimeException|DomainException|InvalidArgumentException
     */
    public static function closeFiscalYear(int $id, int $adminId): array
    {
        require_once __DIR__ . '/AccountingEntry.php';
        require_once __DIR__ . '/AccountingReport.php';

        $period = self::find($id);
        if (!$period) {
            throw new RuntimeException('Exercice introuvable.');
        }
        if ($period['status'] === 'closed') {
            throw new DomainException('Cet exercice est déjà clôturé.');
        }

        $pdo = Database::getConnection();

        // 1. Aucun brouillon ne doit subsister
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM acc_entries WHERE period_id = :id AND status = 'draft'");
        $stmt->execute(['id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new DomainException('Des pièces sont encore en brouillon : validez ou supprimez-les avant la clôture.');
        }

        // 2. Soldes cumulés des comptes de gestion à la date de clôture
        $is = AccountingReport::incomeStatement(null, $period['end_date']);
        $resultat = $is['resultat'];

        $lines = [];
        foreach (array_merge($is['charges'], $is['produits']) as $row) {
            if (abs($row['solde']) < 0.005) {
                continue;
            }
            $account = AccountingAccount::findByNumber($row['number']);
            if (!$account) {
                continue;
            }
            // ligne inverse pour solder le compte : solde débiteur → crédit, créditeur → débit
            $lines[] = [
                'account_id'     => (int)$account['id'],
                'third_party_id' => null,
                'label'          => 'Solde compte ' . $row['number'] . ' — clôture ' . $period['code'],
                'debit'          => $row['solde'] < 0 ? abs($row['solde']) : 0.0,
                'credit'         => $row['solde'] > 0 ? $row['solde'] : 0.0,
                'due_date'       => null,
            ];
        }

        // contrepartie : bénéfice crédité au 120000, perte débitée au 129000
        $resultNumber = $resultat >= 0 ? '120000' : '129000';
        $resultAccount = AccountingAccount::findByNumber($resultNumber);
        if (!$resultAccount) {
            throw new RuntimeException("Compte {$resultNumber} absent du plan comptable.");
        }
        if (abs($resultat) >= 0.005 || $lines) {
            $lines[] = [
                'account_id'     => (int)$resultAccount['id'],
                'third_party_id' => null,
                'label'          => ($resultat >= 0 ? 'Bénéfice' : 'Perte') . ' de l\'exercice ' . $period['code'],
                'debit'          => $resultat < 0 ? abs($resultat) : 0.0,
                'credit'         => $resultat > 0 ? $resultat : 0.0,
                'due_date'       => null,
            ];
        }

        $entryId = null;
        if ($lines) {
            $odJournal = AccountingJournal::findByCode('OD');
            if (!$odJournal) {
                throw new RuntimeException('Journal OD introuvable pour l\'écriture de clôture.');
            }
            $nextSeq = AccountingEntry::nextNumber((int)$period['id'], (int)$odJournal['id']);
            $entryId = AccountingEntry::create([
                'period_id'      => (int)$period['id'],
                'journal_id'     => (int)$odJournal['id'],
                'entry_number'   => 'CLOTURE' . $period['code'] . '-' . substr($nextSeq, -4),
                'entry_date'     => $period['end_date'],
                'reference'      => 'CLOTURE-' . $period['code'],
                'third_party_id' => 0,
                'label'          => 'Détermination du résultat — clôture exercice ' . $period['code'],
                'status'         => 'validated',
                'created_by'     => $adminId,
            ], array_map(fn($l) => [
                'account_id'     => $l['account_id'],
                'third_party_id' => 0,
                'label'          => $l['label'],
                'debit'          => $l['debit'] > 0 ? number_format($l['debit'], 2, '.', '') : '',
                'credit'         => $l['credit'] > 0 ? number_format($l['credit'], 2, '.', '') : '',
                'due_date'       => '',
            ], $lines));
            AccountingEntry::setStatus($entryId, 'validated');
        }

        // 3. Exercice suivant créé s'il manque
        $nextCode = (string)(((int)substr($period['end_date'], 0, 4)) + 1);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM acc_periods WHERE code = :c');
        $exists->execute(['c' => $nextCode]);
        if ((int)$exists->fetchColumn() === 0) {
            self::create([
                'code'       => $nextCode,
                'label'      => 'Exercice ' . $nextCode,
                'start_date' => $nextCode . '-01-01',
                'end_date'   => $nextCode . '-12-31',
            ]);
        } elseif ($nextCode !== $period['code']) {
            // l'exercice suivant existe : vérifier qu'il est ouvert
            $st = $pdo->prepare("SELECT status FROM acc_periods WHERE code = :c");
            $st->execute(['c' => $nextCode]);
            if ($st->fetchColumn() !== 'open') {
                throw new DomainException("L'exercice suivant ({$nextCode}) existe mais est clôturé.");
            }
        }

        // 4. Clôture effective
        self::setStatus($id, 'closed');

        return [
            'entry_id'  => $entryId,
            'resultat'  => $resultat,
            'charges'   => $is['total_charges'],
            'produits'  => $is['total_produits'],
            'next_period' => $nextCode,
        ];
    }

    private static function assertValid(array $d): array
    {
        $code  = trim((string)($d['code'] ?? ''));
        $label = trim((string)($d['label'] ?? ''));
        $start = trim((string)($d['start_date'] ?? ''));
        $end   = trim((string)($d['end_date'] ?? ''));

        if ($label === '') {
            throw new InvalidArgumentException('Le libellé est obligatoire.');
        }
        foreach (['start_date' => $start, 'end_date' => $end] as $field => $val) {
            $dt = DateTime::createFromFormat('Y-m-d', $val);
            if (!$dt || $dt->format('Y-m-d') !== $val) {
                throw new InvalidArgumentException("Date invalide pour {$field}.");
            }
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('La date de fin doit être postérieure à la date de début.');
        }
        return [$code, $label, $start, $end];
    }
}
