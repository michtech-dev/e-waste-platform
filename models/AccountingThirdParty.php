<?php
/**
 * Model : tiers clients / fournisseurs (acc_third_parties)
 */
require_once __DIR__ . '/../config/database.php';

class AccountingThirdParty
{
    public const TYPES = ['client' => 'Client', 'supplier' => 'Fournisseur', 'both' => 'Client & Fournisseur'];

    public static function search(array $f = [], int $limit = 0, int $offset = 0): array
    {
        [$where, $params] = self::buildWhere($f);
        $sql = "SELECT t.*, a.number AS account_number, a.label AS account_label
                FROM acc_third_parties t
                LEFT JOIN acc_accounts a ON a.id = t.account_id
                {$where}
                ORDER BY t.code";
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
        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM acc_third_parties t {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private static function buildWhere(array $f): array
    {
        $conds = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $conds[] = '(t.code ILIKE :q OR t.name ILIKE :q OR t.email ILIKE :q OR t.vat_number ILIKE :q)';
            $params['q'] = '%' . $f['q'] . '%';
        }
        if (!empty($f['type'])) {
            $conds[] = "(t.type = :type OR t.type = 'both')";
            $params['type'] = $f['type'];
        }
        if (isset($f['is_active']) && $f['is_active'] !== '') {
            $conds[] = 't.is_active = :act';
            $params['act'] = (bool)$f['is_active'];
        }
        return ['WHERE ' . implode(' AND ', $conds), $params];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM acc_third_parties WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Solde du tiers (lignes à son compte collectif le concernant). */
    public static function balance(int $id, bool $validatedOnly = true): float
    {
        $sql = 'SELECT COALESCE(SUM(l.debit - l.credit), 0)
                FROM acc_entry_lines l
                JOIN acc_entries e ON e.id = l.entry_id
                WHERE l.third_party_id = :id';
        if ($validatedOnly) {
            $sql .= " AND e.status = 'validated'";
        }
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    /** Prochain code disponible : C0001 / F0001 ... */
    public static function suggestCode(string $type): string
    {
        $prefix = $type === 'supplier' ? 'F' : 'C';
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(NULLIF(REGEXP_REPLACE(code, '\\D', '', 'g'), '')::BIGINT), 0) + 1
             FROM acc_third_parties WHERE code LIKE :p"
        );
        $stmt->execute(['p' => $prefix . '%']);
        return $prefix . str_pad((string)max(1, (int)$stmt->fetchColumn()), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @throws InvalidArgumentException|DomainException|RuntimeException
     */
    public static function create(array $d): int
    {
        [$code, $name, $type, $accountId] = self::assertValid($d);

        $pdo = Database::getConnection();
        if ($code === '') {
            $code = self::suggestCode($type === 'both' ? 'client' : $type);
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM acc_third_parties WHERE code = :c');
        $dup->execute(['c' => $code]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new DomainException("Le code tiers « {$code} » existe déjà.");
        }

        $stmt = $pdo->prepare(
            'INSERT INTO acc_third_parties
                (code, name, type, account_id, vat_number, phone, email, address, city, country, payment_terms)
             VALUES (:code, :name, :type, :acc, :vat, :phone, :email, :addr, :city, :country, :terms)
             RETURNING id'
        );
        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'acc'  => $accountId ?: null,
            'vat'  => self::nvl($d, 'vat_number'),
            'phone'=> self::nvl($d, 'phone'),
            'email'=> self::nvl($d, 'email'),
            'addr' => self::nvl($d, 'address'),
            'city' => self::nvl($d, 'city'),
            'country' => self::nvl($d, 'country', 'Burundi'),
            'terms'   => max(0, min(365, (int)($d['payment_terms'] ?? 30))),
        ]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @throws InvalidArgumentException|DomainException|RuntimeException
     */
    public static function update(int $id, array $d): void
    {
        $tp = self::find($id);
        if (!$tp) {
            throw new RuntimeException('Tiers introuvable.');
        }
        [$code, $name, $type, $accountId] = self::assertValid($d);

        $pdo = Database::getConnection();
        if ($code === '') {
            $code = $tp['code'];
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM acc_third_parties WHERE code = :c AND id <> :id');
        $dup->execute(['c' => $code, 'id' => $id]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new DomainException("Le code tiers « {$code} » est déjà utilisé.");
        }

        $stmt = $pdo->prepare(
            'UPDATE acc_third_parties SET
                code = :code, name = :name, type = :type, account_id = :acc,
                vat_number = :vat, phone = :phone, email = :email, address = :addr,
                city = :city, country = :country, payment_terms = :terms
             WHERE id = :id'
        );
        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'type' => $tp['type'] !== $type && self::isUsed($id) ? $tp['type'] : $type,
            'acc'  => $accountId ?: null,
            'vat'  => self::nvl($d, 'vat_number'),
            'phone'=> self::nvl($d, 'phone'),
            'email'=> self::nvl($d, 'email'),
            'addr' => self::nvl($d, 'address'),
            'city' => self::nvl($d, 'city'),
            'country' => self::nvl($d, 'country', 'Burundi'),
            'terms'   => max(0, min(365, (int)($d['payment_terms'] ?? 30))),
            'id'   => $id,
        ]);
    }

    /** @throws RuntimeException */
    public static function delete(int $id): void
    {
        if (!self::find($id)) {
            throw new RuntimeException('Tiers introuvable.');
        }
        if (self::isUsed($id)) {
            throw new RuntimeException('Ce tiers est utilisé dans des écritures : désactivez-le plutôt que de le supprimer.');
        }
        $stmt = Database::getConnection()->prepare('DELETE FROM acc_third_parties WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function isUsed(int $id): bool
    {
        $pdo = Database::getConnection();
        foreach ([
            'SELECT COUNT(*) FROM acc_entries WHERE third_party_id = :id',
            'SELECT COUNT(*) FROM acc_entry_lines WHERE third_party_id = :id',
        ] as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        }
        return false;
    }

    private static function assertValid(array $d): array
    {
        $code = strtoupper(trim((string)($d['code'] ?? '')));
        $name = trim((string)($d['name'] ?? ''));
        $type = trim((string)($d['type'] ?? 'client'));
        $accId = (int)($d['account_id'] ?? 0);

        if ($name === '') {
            throw new InvalidArgumentException('Le nom du tiers est obligatoire.');
        }
        if (!array_key_exists($type, self::TYPES)) {
            throw new InvalidArgumentException('Type de tiers invalide.');
        }
        if ($code !== '' && !preg_match('/^[A-Z0-9\-]{2,20}$/', $code)) {
            throw new InvalidArgumentException('Code tiers invalide (lettres/chiffres, 2-20 caractères).');
        }
        if ($accId && !AccountingAccount::find($accId)) {
            throw new InvalidArgumentException('Compte collectif introuvable.');
        }
        $email = trim((string)($d['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Adresse e-mail invalide.');
        }
        return [$code, $name, $type, $accId];
    }

    private static function nvl(array $d, string $key, string $default = ''): string
    {
        $v = trim((string)($d[$key] ?? ''));
        return $v !== '' ? $v : $default;
    }
}
