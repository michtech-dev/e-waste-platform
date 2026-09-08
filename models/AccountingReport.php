<?php
/**
 * Model : états comptables façon Sage i7
 *  - Grand livre (avec soldes progressifs et à-nouveau)
 *  - Balance générale (totaux débit/crédit + soldes)
 *  - Journal général (lignes chronologiques)
 *  - Balances tiers (clients / fournisseurs)
 */
require_once __DIR__ . '/../config/database.php';

class AccountingReport
{
    /**
     * Comptes ayant eu un mouvement sur la fenêtre (pour le filtre du grand livre).
     * @return array<int, array>
     */
    public static function ledgerAccounts(?string $from = null, ?string $to = null): array
    {
        $params = [];
        $conds = ["e.status = 'validated'"];
        if ($from) { $conds[] = 'e.entry_date >= :from'; $params['from'] = $from; }
        if ($to)   { $conds[] = 'e.entry_date <= :to';   $params['to'] = $to; }
        $stmt = Database::getConnection()->prepare(
            "SELECT DISTINCT a.id, a.number, a.label
             FROM acc_accounts a
             JOIN acc_entry_lines l ON l.account_id = a.id
             JOIN acc_entries e ON e.id = l.entry_id
             WHERE " . implode(' AND ', $conds) . '
             ORDER BY a.number'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Grand livre d'un compte.
     * @return array{account:array, opening:float, rows:array, total_debit:float,
     *               total_credit:float, closing:float}|null
     */
    public static function ledger(int $accountId, ?string $from, ?string $to): ?array
    {
        $account = AccountingAccount::find($accountId);
        if (!$account) {
            return null;
        }

        // Solde à nouveau : mouvements validés antérieurs à la date de début
        $opening = 0.0;
        if ($from) {
            $opening = AccountingAccount::balance($accountId, null, self::prevDay($from));
        }

        $conds = ['l.account_id = :id', "e.status = 'validated'"];
        $params = ['id' => $accountId];
        if ($from) { $conds[] = 'e.entry_date >= :from'; $params['from'] = $from; }
        if ($to)   { $conds[] = 'e.entry_date <= :to';   $params['to'] = $to; }

        $stmt = Database::getConnection()->prepare(
            'SELECT l.id AS line_id, l.debit, l.credit, l.label AS line_label,
                    l.due_date, l.lettering,
                    e.id AS entry_id, e.entry_number, e.entry_date, e.reference,
                    e.label AS entry_label,
                    j.code AS journal_code,
                    tp.code AS third_code, tp.name AS third_name
             FROM acc_entry_lines l
             JOIN acc_entries e ON e.id = l.entry_id
             JOIN acc_journals j ON j.id = e.journal_id
             LEFT JOIN acc_third_parties tp ON tp.id = l.third_party_id
             WHERE ' . implode(' AND ', $conds) . '
             ORDER BY e.entry_date, e.id, l.line_no'
        );
        $stmt->execute($params);
        $raw = $stmt->fetchAll();

        $rows = [];
        $running = $opening;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($raw as $r) {
            $running += (float)$r['debit'] - (float)$r['credit'];
            $totalDebit  += (float)$r['debit'];
            $totalCredit += (float)$r['credit'];
            $r['running'] = round($running, 2);
            $rows[] = $r;
        }

        return [
            'account'      => $account,
            'opening'      => round($opening, 2),
            'rows'         => $rows,
            'total_debit'  => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'closing'      => round($opening + $totalDebit - $totalCredit, 2),
        ];
    }

    /**
     * Balance générale : totaux par compte sur la période + soldes.
     */
    public static function trialBalance(?string $from, ?string $to, ?int $class = null, bool $nonZeroOnly = true): array
    {
        $params = [];
        $conds = ["a.is_header = FALSE", "e.status = 'validated'"];
        if ($from) { $conds[] = 'e.entry_date >= :from'; $params['from'] = $from; }
        if ($to)   { $conds[] = 'e.entry_date <= :to';   $params['to'] = $to; }
        if ($class) { $conds[] = 'a.class = :cls'; $params['cls'] = $class; }

        // à nouveau (mouvements antérieurs à $from), uniquement si fenêtre bornée
        $openingExpr = '0';
        if ($from) {
            $openingExpr = 'COALESCE(o.amount, 0)';
            $oConds = ["e.status = 'validated'", 'e.entry_date < :ofrom', 'l.account_id = a.id'];
            $oParams = ['ofrom' => $from];
            $stmtO = Database::getConnection()->prepare(
                "SELECT l.account_id AS aid, SUM(l.debit - l.credit) AS amount
                 FROM acc_entry_lines l
                 JOIN acc_entries e ON e.id = l.entry_id
                 WHERE " . implode(' AND ', $oConds) . '
                 GROUP BY l.account_id'
            );
            $stmtO->execute($oParams);
            $openings = [];
            foreach ($stmtO->fetchAll() as $row) {
                $openings[(int)$row['aid']] = (float)$row['amount'];
            }
        } else {
            $openings = [];
        }

        $stmt = Database::getConnection()->prepare(
            "SELECT a.id, a.number, a.label, a.class,
                    COALESCE(SUM(l.debit), 0)  AS debit,
                    COALESCE(SUM(l.credit), 0) AS credit
             FROM acc_accounts a
             JOIN acc_entry_lines l ON l.account_id = a.id
             JOIN acc_entries e ON e.id = l.entry_id
             WHERE " . implode(' AND ', $conds) . '
             GROUP BY a.id, a.number, a.label, a.class
             ORDER BY a.number'
        );
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() as $r) {
            $opening = round($openings[(int)$r['id']] ?? 0.0, 2);
            $debit   = round((float)$r['debit'], 2);
            $credit  = round((float)$r['credit'], 2);
            $closing = round($opening + $debit - $credit, 2);
            $row = [
                'id'              => (int)$r['id'],
                'number'          => $r['number'],
                'label'           => $r['label'],
                'class'           => (int)$r['class'],
                'opening'         => $opening,
                'debit'           => $debit,
                'credit'          => $credit,
                'closing_debit'   => $closing > 0 ? $closing : 0.0,
                'closing_credit'  => $closing < 0 ? -$closing : 0.0,
            ];
            if ($nonZeroOnly && $debit == 0.0 && $credit == 0.0 && $opening == 0.0) {
                continue;
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Journal général : lignes chronologiques avec infos de pièce.
     */
    public static function generalJournal(
        ?int $journalId = null,
        ?string $from = null,
        ?string $to = null,
        ?string $status = null
    ): array {
        $conds = ['1=1'];
        $params = [];
        if ($journalId) { $conds[] = 'e.journal_id = :jid'; $params['jid'] = $journalId; }
        if ($from)      { $conds[] = 'e.entry_date >= :from'; $params['from'] = $from; }
        if ($to)        { $conds[] = 'e.entry_date <= :to'; $params['to'] = $to; }
        if ($status)    { $conds[] = 'e.status = :st'; $params['st'] = $status; }

        $stmt = Database::getConnection()->prepare(
            "SELECT l.line_no, l.debit, l.credit, l.label AS line_label, l.lettering,
                    l.lettered_at,
                    e.id AS entry_id, e.entry_number, e.entry_date, e.reference, e.status,
                    e.label AS entry_label,
                    j.code AS journal_code, j.label AS journal_label,
                    a.number AS account_number, a.label AS account_label,
                    tp.code AS third_code, tp.name AS third_name
             FROM acc_entry_lines l
             JOIN acc_entries e ON e.id = l.entry_id
             JOIN acc_journals j ON j.id = e.journal_id
             JOIN acc_accounts a ON a.id = l.account_id
             LEFT JOIN acc_third_parties tp ON tp.id = l.third_party_id
             WHERE " . implode(' AND ', $conds) . '
             ORDER BY e.entry_date, j.code, e.entry_number, l.line_no'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Soldes par tiers (clients/fournisseurs) via leurs lignes imputées. */
    public static function thirdPartyBalances(?string $type = null, bool $nonZeroOnly = true): array
    {
        $sql = "SELECT t.*, a.number AS account_number,
                       COALESCE(SUM(l.debit), 0)  AS debit,
                       COALESCE(SUM(l.credit), 0) AS credit,
                       COALESCE(SUM(l.debit - l.credit), 0) AS balance
                FROM acc_third_parties t
                LEFT JOIN acc_accounts a ON a.id = t.account_id
                LEFT JOIN acc_entry_lines l ON l.third_party_id = t.id
                LEFT JOIN acc_entries e ON e.id = l.entry_id AND e.status = 'validated'";
        $conds = [];
        $params = [];
        if ($type !== null && in_array($type, ['client', 'supplier', 'both'], true)) {
            $conds[] = "(t.type = :type OR t.type = 'both')";
            $params['type'] = $type;
        }
        if ($conds) {
            $sql .= ' WHERE ' . implode(' AND ', $conds);
        }
        $sql .= ' GROUP BY t.id, a.number ORDER BY t.code';

        $rows = Database::getConnection()->prepare($sql);
        $rows->execute($params);
        $out = [];
        foreach ($rows->fetchAll() as $r) {
            $r['debit']   = round((float)$r['debit'], 2);
            $r['credit']  = round((float)$r['credit'], 2);
            $r['balance'] = round((float)$r['balance'], 2);
            if ($nonZeroOnly && $r['debit'] == 0.0 && $r['credit'] == 0.0) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /** Indicateurs du tableau de bord comptable. */
    public static function dashboardStats(?int $periodId = null): array
    {
        $pdo = Database::getConnection();
        $periodCond = $periodId ? 'AND e.period_id = ' . (int)$periodId : '';
        $periodCondL = str_replace('e.period_id', 'e.period_id', $periodCond); // alias identique après jointure

        $stats = [];

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM acc_entries e WHERE 1=1 {$periodCond}"
        );
        $stats['entries_total'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM acc_entries e WHERE e.status = 'draft' {$periodCond}"
        );
        $stats['entries_draft'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM acc_entries e WHERE e.status = 'validated' {$periodCond}"
        );
        $stats['entries_validated'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query(
            "SELECT COALESCE(SUM(l.debit),0), COALESCE(SUM(l.credit),0)
             FROM acc_entry_lines l JOIN acc_entries e ON e.id = l.entry_id
             WHERE e.status = 'validated' {$periodCondL}"
        );
        [$d, $c] = $stmt->fetch(PDO::FETCH_NUM);
        $stats['total_debit']  = round((float)$d, 2);
        $stats['total_credit'] = round((float)$c, 2);

        $stats['accounts'] = (int)$pdo->query('SELECT COUNT(*) FROM acc_accounts')->fetchColumn();
        $stats['third_parties'] = (int)$pdo->query('SELECT COUNT(*) FROM acc_third_parties')->fetchColumn();
        $stats['journals'] = (int)$pdo->query('SELECT COUNT(*) FROM acc_journals')->fetchColumn();

        return $stats;
    }

    /* =====================================================
       ÉTATS FINANCIERS
       ===================================================== */

    /**
     * Compte de résultat : charges (classe 6) et produits (classe 7)
     * sur la fenêtre, par compte. Resultat = produits − charges.
     */
    public static function incomeStatement(?string $from, ?string $to): array
    {
        $params = [];
        $conds = ["a.is_header = FALSE", "e.status = 'validated'", 'a.class IN (6, 7)'];
        if ($from) { $conds[] = 'e.entry_date >= :from'; $params['from'] = $from; }
        if ($to)   { $conds[] = 'e.entry_date <= :to';   $params['to'] = $to; }

        $stmt = Database::getConnection()->prepare(
            "SELECT a.number, a.label, a.class,
                    COALESCE(SUM(l.debit), 0)  AS debit,
                    COALESCE(SUM(l.credit), 0) AS credit
             FROM acc_accounts a
             JOIN acc_entry_lines l ON l.account_id = a.id
             JOIN acc_entries e ON e.id = l.entry_id
             WHERE " . implode(' AND ', $conds) . '
             GROUP BY a.number, a.label, a.class
             ORDER BY a.number'
        );
        $stmt->execute($params);

        $charges = [];
        $produits = [];
        foreach ($stmt->fetchAll() as $r) {
            $debit  = round((float)$r['debit'], 2);
            $credit = round((float)$r['credit'], 2);
            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }
            $row = [
                'number' => $r['number'],
                'label'  => $r['label'],
                // solde brut débit − crédit (pour solder le compte en clôture)
                'solde'  => round($debit - $credit, 2),
                // montant signé naturel : charge > 0 en débit, produit > 0 en crédit
                'amount' => $r['class'] === 6 ? round($debit - $credit, 2)
                                              : round($credit - $debit, 2),
            ];
            if ((int)$r['class'] === 6) {
                $charges[] = $row;
            } else {
                $produits[] = $row;
            }
        }

        $totalCharges = array_sum(array_column($charges, 'amount'));
        $totalProduits = array_sum(array_column($produits, 'amount'));

        return [
            'charges'        => $charges,
            'produits'       => $produits,
            'total_charges'  => round($totalCharges, 2),
            'total_produits' => round($totalProduits, 2),
            'resultat'       => round($totalProduits - $totalCharges, 2), // >0 : bénéfice
        ];
    }

    /**
     * Bilan simplifié au {$asOf} : soldes de toutes les classes 1-5 depuis l'origine.
     * Le résultat des classes 6/7 est intégré aux capitaux propres (passif).
     */
    public static function balanceSheet(string $asOf): array
    {
        $tb = self::trialBalance(null, $asOf, null, false);

        $actif = [];
        $passif = [];
        $sumActif = 0.0;
        $sumPassif = 0.0;

        foreach ($tb as $row) {
            if ($row['class'] >= 6) {
                continue; // géré via le résultat
            }
            if ($row['closing_debit'] > 0) {
                $actif[] = [
                    'number' => $row['number'],
                    'label'  => $row['label'],
                    'class'  => $row['class'],
                    'amount' => $row['closing_debit'],
                ];
                $sumActif += $row['closing_debit'];
            }
            if ($row['closing_credit'] > 0) {
                $passif[] = [
                    'number' => $row['number'],
                    'label'  => $row['label'],
                    'class'  => $row['class'],
                    'amount' => $row['closing_credit'],
                ];
                $sumPassif += $row['closing_credit'];
            }
        }

        $is = self::incomeStatement(null, $asOf);
        $resultat = $is['resultat'];

        // Si le résultat est déjà matérialisé au 120/129 par une écriture de
        // clôture, on ne l'ajoute pas une seconde fois.
        $resultMaterialized = false;
        foreach ($tb as $row) {
            if (($row['class'] === 1)
                && (str_starts_with($row['number'], '120') || str_starts_with($row['number'], '129'))
                && (abs($row['closing_debit']) > 0.005 || abs($row['closing_credit']) > 0.005)) {
                $resultMaterialized = true;
                break;
            }
        }

        // Bénéfice → passif ; perte → actif (comme un compte 129 débiteur)
        if (!$resultMaterialized && abs($resultat) > 0.005) {
            $resultatLine = [
                'number' => $resultat >= 0 ? '120000' : '129000',
                'label'  => $resultat >= 0 ? 'Résultat de l\'exercice (bénéfice)' : 'Résultat de l\'exercice (perte)',
                'amount' => abs($resultat),
            ];
            if ($resultat >= 0) {
                $passif[] = ['number' => $resultatLine['number'], 'label' => $resultatLine['label'], 'class' => 1, 'amount' => $resultatLine['amount']];
                $sumPassif += $resultatLine['amount'];
            } else {
                $actif[] = ['number' => $resultatLine['number'], 'label' => $resultatLine['label'], 'class' => 1, 'amount' => $resultatLine['amount']];
                $sumActif += $resultatLine['amount'];
            }
        }

        usort($actif, fn($a, $b) => strcmp($a['number'], $b['number']));
        usort($passif, fn($a, $b) => strcmp($a['number'], $b['number']));

        return [
            'as_of'      => $asOf,
            'actif'      => $actif,
            'passif'     => $passif,
            'total_actif'  => round($sumActif, 2),
            'total_passif' => round($sumPassif, 2),
            'resultat'     => $resultat,
            'equilibre'    => abs($sumActif - $sumPassif) < 0.005,
        ];
    }

    /**
     * Échéancier tiers (balances âgées) : lignes NON lettrées réparties par
     * ancienneté de retard (échéance dépassée). Les règlements (lignes
     * créditrices côté client) imputent les buckets les plus anciens d'abord.
     */
    public static function agedBalances(?string $type = null, ?string $asOf = null): array
    {
        $asOf = $asOf ?: date('Y-m-d');
        $conds = ["e.status = 'validated'", 'l.lettering IS NULL', '(l.debit <> 0 OR l.credit <> 0)', 't.id IS NOT NULL', 'e.entry_date <= :asof'];
        $params = ['asof' => $asOf];
        if ($type !== null && in_array($type, ['client', 'supplier', 'both'], true)) {
            $conds[] = "(t.type = :type OR t.type = 'both')";
            $params['type'] = $type;
        }
        $stmt = Database::getConnection()->prepare(
            "SELECT t.id AS tp_id, t.code, t.name,
                    l.debit, l.credit,
                    COALESCE(l.due_date, e.entry_date) AS due
             FROM acc_entry_lines l
             JOIN acc_entries e ON e.id = l.entry_id
             JOIN acc_third_parties t ON t.id = l.third_party_id
             WHERE " . implode(' AND ', $conds) . '
             ORDER BY t.code, COALESCE(l.due_date, e.entry_date)'
        );
        $stmt->execute($params);

        $perTp = [];
        foreach ($stmt->fetchAll() as $r) {
            $id = (int)$r['tp_id'];
            $perTp[$id] ??= ['code' => $r['code'], 'name' => $r['name'], 'debits' => [], 'credits' => []];
            $amt = round((float)$r['debit'] - (float)$r['credit'], 2);
            if ($amt > 0) {
                $daysLate = (int)((strtotime($asOf) - strtotime((string)$r['due'])) / 86400);
                $bucket = match (true) {
                    $daysLate <= 0   => 'not_due',
                    $daysLate <= 30  => 'd1_30',
                    $daysLate <= 60  => 'd31_60',
                    $daysLate <= 90  => 'd61_90',
                    default          => 'd90_plus',
                };
                $perTp[$id]['debits'][] = ['bucket' => $bucket, 'amount' => $amt];
            } elseif ($amt < 0) {
                $perTp[$id]['credits'][] = -$amt;
            }
        }

        $bucketsOrder = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];
        $out = [];
        foreach ($perTp as $tp) {
            // imputation FIFO des règlements sur les créances les plus anciennes
            usort($tp['debits'], function ($a, $b) use ($bucketsOrder) {
                $ia = array_search($a['bucket'], $bucketsOrder, true);
                $ib = array_search($b['bucket'], $bucketsOrder, true);
                return $ib <=> $ia; // du plus ancien (d90_plus) au plus récent
            });
            $creditIdx = 0;
            $creditLeft = $tp['credits'][0] ?? 0.0;
            $totals = array_fill_keys($bucketsOrder, 0.0);
            foreach ($tp['debits'] as $d) {
                $amt = $d['amount'];
                while ($creditLeft > 0.005 && $amt > 0.005) {
                    $take = min($creditLeft, $amt);
                    $amt -= $take;
                    $creditLeft -= $take;
                }
                if ($amt > 0.005) {
                    $totals[$d['bucket']] += $amt;
                }
                if ($creditLeft <= 0.005 && isset($tp['credits'][$creditIdx + 1])) {
                    $creditIdx++;
                    $creditLeft = $tp['credits'][$creditIdx];
                }
            }
            $total = array_sum($totals);
            if ($total < 0.005) {
                continue;
            }
            $out[] = [
                'code' => $tp['code'],
                'name' => $tp['name'],
                'not_due' => round($totals['not_due'], 2),
                'd1_30'   => round($totals['d1_30'], 2),
                'd31_60'  => round($totals['d31_60'], 2),
                'd61_90'  => round($totals['d61_90'], 2),
                'd90_plus'=> round($totals['d90_plus'], 2),
                'total'   => round($total, 2),
            ];
        }
        return $out;
    }

    private static function prevDay(string $date): string
    {
        $dt = new DateTime($date);
        $dt->modify('-1 day');
        return $dt->format('Y-m-d');
    }
}
