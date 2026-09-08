<?php
/**
 * Model : journaux comptables (acc_journals)
 */
require_once __DIR__ . '/../config/database.php';

class AccountingJournal
{
    public const TYPES = [
        'achats' => 'Achats',
        'ventes' => 'Ventes',
        'banque' => 'Banque',
        'caisse' => 'Caisse',
        'divers' => 'Opérations diverses (OD)',
    ];

    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT j.*, da.number AS debit_number, da.label AS debit_label,
                       ca.number AS credit_number, ca.label AS credit_label
                FROM acc_journals j
                LEFT JOIN acc_accounts da ON da.id = j.default_debit_account
                LEFT JOIN acc_accounts ca ON ca.id = j.default_credit_account';
        if ($activeOnly) {
            $sql .= ' WHERE j.is_active = TRUE';
        }
        $sql .= ' ORDER BY j.code';
        return Database::getConnection()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM acc_journals WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM acc_journals WHERE code = :c');
        $stmt->execute(['c' => $code]);
        return $stmt->fetch() ?: null;
    }

    public static function hasEntries(int $id): bool
    {
        $stmt = Database::getConnection()->prepare('SELECT COUNT(*) FROM acc_entries WHERE journal_id = :id');
        $stmt->execute(['id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @throws InvalidArgumentException|DomainException|RuntimeException
     */
    public static function create(array $d): int
    {
        [$code, $label, $type, $debitAcc, $creditAcc] = self::assertValid($d);

        $pdo = Database::getConnection();
        if (self::findByCode($code)) {
            throw new DomainException("Le code journal « {$code} » existe déjà.");
        }
        $stmt = $pdo->prepare(
            'INSERT INTO acc_journals (code, label, type, default_debit_account, default_credit_account)
             VALUES (:code, :label, :type, :da, :ca) RETURNING id'
        );
        $stmt->execute([
            'code' => $code, 'label' => $label, 'type' => $type,
            'da' => $debitAcc ?: null, 'ca' => $creditAcc ?: null,
        ]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @throws InvalidArgumentException|DomainException|RuntimeException
     */
    public static function update(int $id, array $d): void
    {
        if (!self::find($id)) {
            throw new RuntimeException('Journal introuvable.');
        }
        [$code, $label, $type, $debitAcc, $creditAcc] = self::assertValid($d);

        $pdo = Database::getConnection();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM acc_journals WHERE code = :c AND id <> :id');
        $dup->execute(['c' => $code, 'id' => $id]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new DomainException("Le code journal « {$code} » est déjà utilisé.");
        }

        // Le type ne change pas si le journal a des écritures (cohérence des états)
        $old = self::find($id);
        if ($old['type'] !== $type && self::hasEntries($id)) {
            throw new DomainException('Impossible de changer le type d\'un journal contenant des écritures.');
        }

        $stmt = $pdo->prepare(
            'UPDATE acc_journals SET code = :code, label = :label, type = :type,
                    default_debit_account = :da, default_credit_account = :ca
             WHERE id = :id'
        );
        $stmt->execute([
            'code' => $code, 'label' => $label, 'type' => $type,
            'da' => $debitAcc ?: null, 'ca' => $creditAcc ?: null, 'id' => $id,
        ]);
    }

    /** @throws RuntimeException */
    public static function delete(int $id): void
    {
        if (!self::find($id)) {
            throw new RuntimeException('Journal introuvable.');
        }
        if (self::hasEntries($id)) {
            throw new RuntimeException('Ce journal contient des écritures : désactivez-le plutôt que de le supprimer.');
        }
        $stmt = Database::getConnection()->prepare('DELETE FROM acc_journals WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private static function assertValid(array $d): array
    {
        $code  = strtoupper(trim((string)($d['code'] ?? '')));
        $label = trim((string)($d['label'] ?? ''));
        $type  = trim((string)($d['type'] ?? 'divers'));
        $da    = (int)($d['default_debit_account'] ?? 0);
        $ca    = (int)($d['default_credit_account'] ?? 0);

        if (!preg_match('/^[A-Z0-9]{2,5}$/', $code)) {
            throw new InvalidArgumentException('Le code journal doit contenir 2 à 5 lettres/chiffres (ex. BQ).');
        }
        if ($label === '') {
            throw new InvalidArgumentException('Le libellé est obligatoire.');
        }
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Type de journal invalide.');
        }
        foreach ([['débit par défaut', $da], ['crédit par défaut', $ca]] as [$name, $accId]) {
            if ($accId && !AccountingAccount::find($accId)) {
                throw new InvalidArgumentException("Compte {$name} introuvable.");
            }
        }
        return [$code, $label, $type, $da, $ca];
    }
}
