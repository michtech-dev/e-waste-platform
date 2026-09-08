<?php
/**
 * Model : plan comptable (acc_accounts) — façon Sage i7
 */
require_once __DIR__ . '/../config/database.php';

class AccountingAccount
{
    public const TYPES = ['general', 'client', 'supplier', 'bank', 'cash', 'other'];

    /** Comptes des classes imputables 1 à 5 + 6/7 charges-produits. */
    public const CLASS_LABELS = [
        1 => 'Classe 1 — Comptes de capitaux',
        2 => 'Classe 2 — Comptes d\'immobilisations',
        3 => 'Classe 3 — Comptes de stocks et en-cours',
        4 => 'Classe 4 — Comptes de tiers',
        5 => 'Classe 5 — Comptes financiers',
        6 => 'Classe 6 — Comptes de charges',
        7 => 'Classe 7 — Comptes de produits',
        8 => 'Classe 8 — Comptes spéciaux',
        9 => 'Classe 9 — Comptes analytiques',
    ];

    public static function search(array $f = [], int $limit = 0, int $offset = 0): array
    {
        [$where, $params] = self::buildWhere($f);
        $sql = "SELECT a.*, p.number AS parent_number_label
                FROM acc_accounts a
                LEFT JOIN acc_accounts p ON p.number = a.parent_number
                {$where}
                ORDER BY a.number";
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
        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM acc_accounts a {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private static function buildWhere(array $f): array
    {
        $conds = ['1=1'];
        $params = [];
        if (!empty($f['class'])) {
            $conds[] = 'a.class = :class';
            $params['class'] = (int)$f['class'];
        }
        if (!empty($f['type'])) {
            $conds[] = 'a.type = :type';
            $params['type'] = $f['type'];
        }
        if (!empty($f['q'])) {
            $conds[] = '(a.number ILIKE :q OR a.label ILIKE :q)';
            $params['q'] = '%' . $f['q'] . '%';
        }
        if (isset($f['is_active']) && $f['is_active'] !== '') {
            $conds[] = 'a.is_active = :act';
            $params['act'] = (bool)$f['is_active'];
        }
        if (!empty($f['imputable_only'])) {
            $conds[] = 'a.is_header = FALSE AND a.is_active = TRUE';
        }
        return ['WHERE ' . implode(' AND ', $conds), $params];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM acc_accounts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByNumber(string $number): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM acc_accounts WHERE number = :n');
        $stmt->execute(['n' => $number]);
        return $stmt->fetch() ?: null;
    }

    /** Liste courte pour les listes déroulantes de saisie. */
    public static function imputable(?string $type = null): array
    {
        $pdo = Database::getConnection();
        if ($type !== null && in_array($type, self::TYPES, true)) {
            $stmt = $pdo->prepare(
                "SELECT id, number, label FROM acc_accounts
                 WHERE is_header = FALSE AND is_active = TRUE AND type = :t
                 ORDER BY number"
            );
            $stmt->execute(['t' => $type]);
            return $stmt->fetchAll();
        }
        return $pdo->query(
            "SELECT id, number, label FROM acc_accounts
             WHERE is_header = FALSE AND is_active = TRUE
             ORDER BY number"
        )->fetchAll();
    }

    public static function isUsed(int $id): bool
    {
        $pdo = Database::getConnection();
        $checks = [
            'acc_entry_lines'      => 'account_id',
            'acc_journals'         => 'default_debit_account',
            'acc_journals'         => 'default_credit_account',
            'acc_third_parties'    => 'account_id',
        ];
        foreach ($checks as $table => $col) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = :id");
            $stmt->execute(['id' => $id]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        }
        // utilisée comme parent ?
        $me = self::find($id);
        if ($me) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM acc_accounts WHERE parent_number = :n');
            $stmt->execute(['n' => $me['number']]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @throws InvalidArgumentException|DomainException|RuntimeException
     */
    public static function create(array $d): int
    {
        [$number, $label, $class, $type, $parent, $isHeader, $isActive] = self::assertValid($d);

        $pdo = Database::getConnection();
        if (self::findByNumber($number)) {
            throw new DomainException("Le compte « {$number} » existe déjà dans le plan comptable.");
        }
        $stmt = $pdo->prepare(
            'INSERT INTO acc_accounts (number, label, class, type, parent_number, is_header, is_active)
             VALUES (:n, :l, :c, :t, :p, :h, :a) RETURNING id'
        );
        $stmt->bindValue('n', $number);
        $stmt->bindValue('l', $label);
        $stmt->bindValue('c', $class, PDO::PARAM_INT);
        $stmt->bindValue('t', $type);
        $stmt->bindValue('p', $parent !== '' ? $parent : null);
        $stmt->bindValue('h', $isHeader, PDO::PARAM_BOOL);
        $stmt->bindValue('a', $isActive, PDO::PARAM_BOOL);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * @throws InvalidArgumentException|DomainException|RuntimeException
     */
    public static function update(int $id, array $d): void
    {
        if (!self::find($id)) {
            throw new RuntimeException('Compte introuvable.');
        }
        [$number, $label, $class, $type, $parent, $isHeader, $isActive] = self::assertValid($d);

        $pdo = Database::getConnection();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM acc_accounts WHERE number = :n AND id <> :id');
        $dup->execute(['n' => $number, 'id' => $id]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new DomainException("Le numéro « {$number} » est déjà utilisé par un autre compte.");
        }
        if ($parent !== '') {
            if ($parent === $number) {
                throw new DomainException('Un compte ne peut pas être son propre parent.');
            }
            if (!self::findByNumber($parent)) {
                throw new DomainException("Le compte parent « {$parent} » n'existe pas.");
            }
        }

        // Désactivation interdite si le compte porte des écritures
        if (!$isActive && self::isUsed($id)) {
            // on autorise quand même la désactivation (les états restent corrects),
            // mais pas le changement de numéro si utilisé :
        }
        $old = self::find($id);
        if ($old['number'] !== $number && self::isUsed($id)) {
            throw new DomainException('Impossible de renuméroter un compte utilisé par des écritures ou paramètres.');
        }

        $stmt = $pdo->prepare(
            'UPDATE acc_accounts SET number = :n, label = :l, class = :c, type = :t,
                    parent_number = :p, is_header = :h, is_active = :a
             WHERE id = :id'
        );
        $stmt->bindValue('n', $number);
        $stmt->bindValue('l', $label);
        $stmt->bindValue('c', $class, PDO::PARAM_INT);
        $stmt->bindValue('t', $type);
        $stmt->bindValue('p', $parent !== '' ? $parent : null);
        $stmt->bindValue('h', $isHeader, PDO::PARAM_BOOL);
        $stmt->bindValue('a', $isActive, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @throws RuntimeException si le compte est référencé ailleurs
     */
    public static function delete(int $id): void
    {
        if (!self::find($id)) {
            throw new RuntimeException('Compte introuvable.');
        }
        if (self::isUsed($id)) {
            throw new RuntimeException('Ce compte est utilisé (écritures, journal, tiers ou compte parent). Désactivez-le plutôt que de le supprimer.');
        }
        $stmt = Database::getConnection()->prepare('DELETE FROM acc_accounts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Solde cumulé du compte (débit - crédit) sur une fenêtre de dates. */
    public static function balance(int $id, ?string $from = null, ?string $to = null, bool $validatedOnly = true): float
    {
        $conds = ['l.account_id = :id'];
        $params = ['id' => $id];
        if ($from) { $conds[] = 'e.entry_date >= :from'; $params['from'] = $from; }
        if ($to)   { $conds[] = 'e.entry_date <= :to';   $params['to'] = $to; }
        if ($validatedOnly) { $conds[] = "e.status = 'validated'"; }
        $stmt = Database::getConnection()->prepare(
            'SELECT COALESCE(SUM(l.debit - l.credit), 0)
             FROM acc_entry_lines l
             JOIN acc_entries e ON e.id = l.entry_id
             WHERE ' . implode(' AND ', $conds)
        );
        $stmt->execute($params);
        return round((float)$stmt->fetchColumn(), 2);
    }

    private static function assertValid(array $d): array
    {
        $number   = strtoupper(trim((string)($d['number'] ?? '')));
        $label    = trim((string)($d['label'] ?? ''));
        $type     = trim((string)($d['type'] ?? 'general'));
        $parent   = strtoupper(trim((string)($d['parent_number'] ?? '')));
        $isHeader = !empty($d['is_header']);
        $isActive = !isset($d['is_active']) || (bool)$d['is_active'];

        if (!preg_match('/^[0-9]{3,20}$/', $number)) {
            throw new InvalidArgumentException('Le numéro de compte doit contenir entre 3 et 20 chiffres (ex. 411000).');
        }
        if ($label === '') {
            throw new InvalidArgumentException('Le libellé du compte est obligatoire.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Type de compte invalide.');
        }
        $class = (int)$number[0];
        if ($class < 1 || $class > 9) {
            throw new InvalidArgumentException('Classe invalide : elle est déduite du premier chiffre.');
        }
        return [$number, $label, $class, $type, $parent, $isHeader, $isActive];
    }
}
