<?php
/**
 * Budgets prévisionnels (façon Sage i7) : un budget par exercice,
 * lignes par compte avec 12 dotations mensuelles, exécution vs réalisé.
 */

class AccountingBudget
{
    public const MONTHS = ['m1','m2','m3','m4','m5','m6','m7','m8','m9','m10','m11','m12'];
    private const MCOLS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];

    /** Libellés courts des mois (FR). */
    public static function monthLabels(): array
    {
        return self::MCOLS;
    }

    public static function all(): array
    {
        $stmt = Database::getConnection()->query(
            'SELECT b.*, p.code AS period_code, p.label AS period_label, p.status AS period_status,
                    COALESCE((SELECT SUM(m1+m2+m3+m4+m5+m6+m7+m8+m9+m10+m11+m12)
                              FROM acc_budget_lines l WHERE l.budget_id = b.id), 0) AS total_amount,
                    (SELECT COUNT(*) FROM acc_budget_lines l WHERE l.budget_id = b.id) AS line_count
             FROM acc_budgets b JOIN acc_periods p ON p.id = b.period_id
             ORDER BY p.start_date DESC, b.label'
        );
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    /** Budgets d'un exercice. */
    public static function forPeriod(int $periodId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM acc_budgets WHERE period_id = :p ORDER BY label'
        );
        $stmt->execute(['p' => $periodId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT b.*, p.code AS period_code, p.start_date, p.end_date
             FROM acc_budgets b JOIN acc_periods p ON p.id = b.period_id
             WHERE b.id = :i'
        );
        $stmt->execute(['i' => $id]);
        $row = $stmt->fetch();
        return $row ? self::cast($row) : null;
    }

    public static function create(array $d): int
    {
        $label = trim($d['label'] ?? '');
        $periodId = (int)($d['period_id'] ?? 0);
        if ($label === '') {
            throw new InvalidArgumentException('Le libellé du budget est obligatoire.');
        }
        if ($periodId <= 0 || !AccountingPeriod::find($periodId)) {
            throw new DomainException('Exercice comptable introuvable.');
        }
        $pdo = Database::getConnection();
        $dup = $pdo->prepare('SELECT id FROM acc_budgets WHERE period_id = :p AND LOWER(label) = LOWER(:l)');
        $dup->execute(['p' => $periodId, 'l' => $label]);
        if ($dup->fetch()) {
            throw new DomainException("Un budget « {$label} » existe déjà pour cet exercice.");
        }
        $stmt = $pdo->prepare(
            'INSERT INTO acc_budgets (period_id, label, created_by) VALUES (:p, :l, :u) RETURNING id'
        );
        $stmt->execute(['p' => $periodId, 'l' => $label, 'u' => (int)($d['created_by'] ?? 0) ?: null]);
        return (int)$stmt->fetchColumn();
    }

    public static function rename(int $id, string $label): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new InvalidArgumentException('Le libellé du budget est obligatoire.');
        }
        $b = self::find($id);
        if (!$b) {
            throw new RuntimeException('Budget introuvable.');
        }
        $pdo = Database::getConnection();
        $dup = $pdo->prepare(
            'SELECT id FROM acc_budgets WHERE period_id = :p AND LOWER(label) = LOWER(:l) AND id <> :i'
        );
        $dup->execute(['p' => $b['period_id'], 'l' => $label, 'i' => $id]);
        if ($dup->fetch()) {
            throw new DomainException("Un budget « {$label} » existe déjà pour cet exercice.");
        }
        $pdo->prepare('UPDATE acc_budgets SET label = :l WHERE id = :i')->execute(['l' => $label, 'i' => $id]);
    }

    public static function delete(int $id): void
    {
        $n = Database::getConnection()->prepare('DELETE FROM acc_budgets WHERE id = :i');
        $n->execute(['i' => $id]);
        if ($n->rowCount() === 0) {
            throw new RuntimeException('Budget introuvable.');
        }
    }

    /**
     * Remplace toutes les lignes du budget (transaction).
     * @param array $rows [[account_id, m1..m12]]
     */
    public static function setLines(int $budgetId, array $rows): int
    {
        $b = self::find($budgetId);
        if (!$b) {
            throw new RuntimeException('Budget introuvable.');
        }
        $clean = [];
        foreach ($rows as $r) {
            $accountId = (int)($r['account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }
            $acc = AccountingAccount::find($accountId);
            if (!$acc || $acc['is_header']) {
                throw new DomainException("Ligne budgétaire ignorée : compte imputable requis.");
            }
            $months = [];
            $total = 0.0;
            foreach (self::MONTHS as $i => $m) {
                $v = self::toAmount($r[$m] ?? '');
                if ($v < -9999999999.99) {
                    throw new DomainException('Montant budgétaire trop élevé.');
                }
                $months[$m] = round($v, 2);
                $total += abs($v);
            }
            if ($total < 0.005) {
                continue; // ligne vide → ignorée
            }
            $key = $accountId;
            if (isset($clean[$key])) {
                throw new DomainException("Le compte {$acc['number']} apparaît plusieurs fois.");
            }
            $clean[$key] = ['account_id' => $accountId] + $months;
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM acc_budget_lines WHERE budget_id = :b')->execute(['b' => $budgetId]);
            $stmt = $pdo->prepare(
                'INSERT INTO acc_budget_lines
                    (budget_id, account_id, m1,m2,m3,m4,m5,m6,m7,m8,m9,m10,m11,m12)
                 VALUES (:b,:a,:m1,:m2,:m3,:m4,:m5,:m6,:m7,:m8,:m9,:m10,:m11,:m12)'
            );
            foreach ($clean as $line) {
                $stmt->execute(['b' => $budgetId, 'a' => $line['account_id']]
                    + array_intersect_key($line, array_flip(self::MONTHS)));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return count($clean);
    }

    /** Lignes du budget avec le libellé des comptes. */
    public static function lines(int $budgetId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT l.*, a.number AS account_number, a.label AS account_label, a.class
             FROM acc_budget_lines l JOIN acc_accounts a ON a.id = l.account_id
             WHERE l.budget_id = :b ORDER BY a.number'
        );
        $stmt->execute(['b' => $budgetId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $total = 0.0;
            foreach (self::MONTHS as $m) {
                $r[$m] = (float)$r[$m];
                $total += $r[$m];
            }
            $r['total'] = round($total, 2);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Exécution budgétaire : budget mensuel vs réalisé (écritures validées
     * de l'exercice). Sens naturel : charges = débits-crédits (classe 6),
     * produits = crédits-débits (classe 7).
     * @return array{lines:array,totals:array,budget:array,realized:array}
     */
    public static function execution(int $budgetId, ?string $upToMonth = null): array
    {
        $b = self::find($budgetId);
        if (!$b) {
            throw new RuntimeException('Budget introuvable.');
        }
        // borne haute : n'évaluer que jusqu'au mois N (1..12)
        $lastMonth = 12;
        if ($upToMonth !== null && preg_match('/^\d{4}-\d{2}/', $upToMonth)) {
            $lastMonth = max(1, min(12, (int)substr($upToMonth, 5, 2)));
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT l.account_id, EXTRACT(MONTH FROM e.entry_date)::INT AS mois,
                    SUM(l.debit - l.credit) AS solde
             FROM acc_entry_lines l
             JOIN acc_entries e ON e.id = l.entry_id
             WHERE e.period_id = :p AND e.status = 'validated'
               AND EXTRACT(MONTH FROM e.entry_date)::INT <= :lm
             GROUP BY l.account_id, EXTRACT(MONTH FROM e.entry_date)::INT"
        );
        $stmt->execute(['p' => $b['period_id'], 'lm' => $lastMonth]);

        // réalisé[account_id][mois] en sens naturel
        $realizedRaw = [];
        foreach ($stmt->fetchAll() as $r) {
            $realizedRaw[(int)$r['account_id']][(int)$r['mois']] = (float)$r['solde'];
        }

        // classe de chaque mouvement pour l'inversion produits
        $classes = [];
        $q = $pdo->query('SELECT id, class FROM acc_accounts');
        foreach ($q->fetchAll() as $a) {
            $classes[(int)$a['id']] = (int)$a['class'];
        }

        $lines = [];
        $totBudget = array_fill(1, 12, 0.0);
        $totReal   = array_fill(1, 12, 0.0);
        foreach (self::lines($budgetId) as $bl) {
            $row = [
                'account_number' => $bl['account_number'],
                'account_label'  => $bl['account_label'],
                'class'          => $bl['class'],
                'budget'         => [],
                'realized'       => [],
                'variance'       => [],
                'total_budget'   => 0.0,
                'total_realized' => 0.0,
                'total_variance' => 0.0,
            ];
            for ($mo = 1; $mo <= 12; $mo++) {
                $bud = (float)$bl[self::MONTHS[$mo - 1]];
                $raw = $realizedRaw[(int)$bl['account_id']][$mo] ?? 0.0;
                // sens naturel
                $rea = ($classes[(int)$bl['account_id']] ?? 6) === 7 ? -$raw : $raw;
                $var = $bud - $rea;
                $row['budget'][$mo]   = round($bud, 2);
                $row['realized'][$mo] = round($rea, 2);
                $row['variance'][$mo] = round($var, 2);
                $row['total_budget']   = round($row['total_budget'] + $bud, 2);
                $row['total_realized'] = round($row['total_realized'] + $rea, 2);
                $totBudget[$mo] += $bud;
                $totReal[$mo]   += $rea;
            }
            $row['total_variance'] = round($row['total_budget'] - $row['total_realized'], 2);
            $lines[] = $row;
        }

        $totals = ['budget' => [], 'realized' => [], 'variance' => [], 'total_budget' => 0.0, 'total_realized' => 0.0];
        for ($mo = 1; $mo <= 12; $mo++) {
            $totals['budget'][$mo]   = round($totBudget[$mo], 2);
            $totals['realized'][$mo] = round($totReal[$mo], 2);
            $totals['variance'][$mo] = round($totBudget[$mo] - $totReal[$mo], 2);
            $totals['total_budget']   = round($totals['total_budget'] + $totBudget[$mo], 2);
            $totals['total_realized'] = round($totals['total_realized'] + $totReal[$mo], 2);
        }

        return [
            'budget'  => $b,
            'lines'   => $lines,
            'totals'  => $totals,
            'upto'    => $lastMonth,
        ];
    }

    private static function toAmount($v): float
    {
        if (is_string($v)) {
            $v = str_replace([' ', ',', "\u{00A0}"], ['', '.', ''], trim($v));
        }
        if (!is_numeric($v) && $v !== '' && $v !== null) {
            throw new InvalidArgumentException("Montant invalide : « {$v} ».");
        }
        return (float)($v ?: 0);
    }

    private static function cast(array $r): array
    {
        foreach (['total_amount'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = round((float)$r[$k], 2);
            }
        }
        $r['line_count'] = (int)($r['line_count'] ?? 0);
        return $r;
    }
}
