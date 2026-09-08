<?php
/**
 * Exports du module comptabilité.
 * Types : accounts | journals | third_parties | entries | ledger | balance | gjournal | fec
 * Format : CSV (; + BOM UTF-8, compatible Excel FR) ou FEC (txt) pour type=fec.
 */
require_once __DIR__ . '/_init.php';

$type = $_GET['type'] ?? '';
$dateStamp = date('Ymd_His');

switch ($type) {
    case 'accounts': {
        $fClass = (int)($_GET['class'] ?? 0);
        $rows = AccountingAccount::search(array_filter(['class' => $fClass], fn($v) => $v));
        Accounting::downloadCsv(
            "plan_comptable_{$dateStamp}.csv",
            ['Numero_Compte', 'Libelle', 'Classe', 'Type', 'Compte_Parent', 'Rubrique_Non_Imputable', 'Actif'],
            array_map(fn($a) => [
                $a['number'], $a['label'], $a['class'],
                Accounting::accountTypeLabel($a['type']),
                $a['parent_number'] ?? '', $a['is_header'] ? 'Oui' : 'Non',
                $a['is_active'] ? 'Oui' : 'Non',
            ], $rows)
        );
        // no break (downloadCsv exits)
    }

    case 'journals': {
        $rows = AccountingJournal::all();
        Accounting::downloadCsv(
            "journaux_{$dateStamp}.csv",
            ['Code', 'Libelle', 'Type', 'Compte_Debit_Defaut', 'Compte_Credit_Defaut', 'Actif'],
            array_map(fn($j) => [
                $j['code'], $j['label'],
                AccountingJournal::TYPES[$j['type']] ?? $j['type'],
                $j['debit_number'] ?? '', $j['credit_number'] ?? '',
                $j['is_active'] ? 'Oui' : 'Non',
            ], $rows)
        );
    }

    case 'third_parties': {
        $fType = in_array($_GET['filter_type'] ?? '', ['client', 'supplier'], true) ? $_GET['filter_type'] : null;
        $rows = AccountingThirdParty::search($fType ? ['type' => $fType] : []);
        $balances = [];
        foreach (AccountingReport::thirdPartyBalances(null, false) as $b) {
            $balances[(int)$b['id']] = $b;
        }
        Accounting::downloadCsv(
            "tiers_{$dateStamp}.csv",
            ['Code', 'Nom', 'Type', 'Compte_Collectif', 'N_TVA', 'Telephone', 'Email', 'Adresse', 'Ville', 'Pays', 'Delai_Jours', 'Solde', 'Actif'],
            array_map(function ($t) use ($balances) {
                $b = $balances[(int)$t['id']] ?? null;
                return [
                    $t['code'], $t['name'], AccountingThirdParty::TYPES[$t['type']],
                    $t['account_number'] ?? '', $t['vat_number'] ?? '', $t['phone'] ?? '',
                    $t['email'] ?? '', preg_replace('/\s+/', ' ', (string)$t['address']), $t['city'] ?? '',
                    $t['country'] ?? '', (int)$t['payment_terms'],
                    $b ? number_format((float)$b['balance'], 2, ',', '') : '0,00',
                    $t['is_active'] ? 'Oui' : 'Non',
                ];
            }, $rows)
        );
    }

    case 'entries': {
        $filters = [
            'period_id'  => (int)($_GET['period_id'] ?? 0) ?: null,
            'journal_id' => (int)($_GET['journal_id'] ?? 0) ?: null,
            'status'     => in_array($_GET['status'] ?? '', ['draft', 'validated'], true) ? $_GET['status'] : '',
            'date_from'  => trim((string)($_GET['date_from'] ?? '')) ?: null,
            'date_to'    => trim((string)($_GET['date_to'] ?? '')) ?: null,
            'q'          => trim((string)($_GET['q'] ?? '')) ?: null,
        ];
        $rows = AccountingEntry::search($filters, 0, 0); // tout
        Accounting::downloadCsv(
            "ecritures_{$dateStamp}.csv",
            ['Piece', 'Journal', 'Date', 'Reference', 'Tiers', 'Libelle', 'Total_Debit', 'Total_Credit', 'Statut', 'Nb_Lignes'],
            array_map(function ($e) {
                $stmt = Database::getConnection()->prepare('SELECT COUNT(*) FROM acc_entry_lines WHERE entry_id = :id');
                $stmt->execute(['id' => $e['id']]);
                return [
                    $e['entry_number'], $e['journal_code'], $e['entry_date'],
                    $e['reference'] ?? '', ($e['third_code'] ?? '') . ' ' . ($e['third_name'] ?? ''),
                    $e['label'],
                    number_format((float)$e['total_debit'], 2, ',', ''),
                    number_format((float)$e['total_credit'], 2, ',', ''),
                    Accounting::statusLabel($e['status']),
                    (int)$stmt->fetchColumn(),
                ];
            }, $rows)
        );
    }

    case 'ledger': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $data = AccountingReport::ledger($accountId, $_GET['date_from'] ?: null, $_GET['date_to'] ?: null);
        if (!$data) {
            acc_flash_set('', 'Compte introuvable pour l\'export.');
            header('Location: ledger.php');
            exit;
        }
        $out = [[
            'Solde à nouveau', '', '', '', '',
            number_format($data['opening'], 2, ',', ''), '', number_format($data['opening'], 2, ',', ''),
        ]];
        foreach ($data['rows'] as $r) {
            $out[] = [
                $r['entry_date'], $r['entry_number'], $r['journal_code'],
                $r['line_label'] ?: $r['entry_label'],
                ($r['third_code'] ?? '') . ' ' . ($r['third_name'] ?? ''),
                number_format((float)$r['debit'], 2, ',', ''),
                number_format((float)$r['credit'], 2, ',', ''),
                number_format((float)$r['running'], 2, ',', ''),
            ];
        }
        $out[] = ['Totaux', '', '', '', '',
            number_format($data['total_debit'], 2, ',', ''),
            number_format($data['total_credit'], 2, ',', ''),
            number_format($data['closing'], 2, ',', '')];
        Accounting::downloadCsv(
            "grand_livre_{$data['account']['number']}_{$dateStamp}.csv",
            ['Date', 'Piece', 'Journal', 'Libelle', 'Tiers', 'Debit', 'Credit', 'Solde_Progressif'],
            $out
        );
    }

    case 'balance': {
        $rows = AccountingReport::trialBalance(
            $_GET['date_from'] ?: null,
            $_GET['date_to'] ?: null,
            (int)($_GET['class'] ?? 0) ?: null,
            empty($_GET['all'])
        );
        Accounting::downloadCsv(
            "balance_generale_{$dateStamp}.csv",
            ['Compte', 'Libelle', 'Classe', 'A_Nouveau_Debit', 'A_Nouveau_Credit', 'Mouvements_Debit', 'Mouvements_Credit', 'Solde_Debit', 'Solde_Credit'],
            array_map(fn($r) => [
                $r['number'], $r['label'], $r['class'],
                number_format(max(0.0, $r['opening']), 2, ',', ''),
                number_format(max(0.0, -$r['opening']), 2, ',', ''),
                number_format($r['debit'], 2, ',', ''),
                number_format($r['credit'], 2, ',', ''),
                number_format($r['closing_debit'], 2, ',', ''),
                number_format($r['closing_credit'], 2, ',', ''),
            ], $rows)
        );
    }

    case 'gjournal': {
        $rows = AccountingReport::generalJournal(
            (int)($_GET['journal_id'] ?? 0) ?: null,
            $_GET['date_from'] ?: null,
            $_GET['date_to'] ?: null,
            in_array($_GET['status'] ?? '', ['draft', 'validated'], true) ? $_GET['status'] : null
        );
        Accounting::downloadCsv(
            "journal_general_{$dateStamp}.csv",
            ['Journal', 'Pièce', 'Date', 'Compte', 'Libellé_ligne', 'Libellé_pièce', 'Tiers', 'Débit', 'Crédit', 'Statut'],
            array_map(fn($l) => [
                $l['journal_code'], $l['entry_number'], $l['entry_date'],
                $l['account_number'] . ' ' . $l['account_label'],
                $l['line_label'] ?? '', $l['entry_label'],
                ($l['third_code'] ?? '') . ' ' . ($l['third_name'] ?? ''),
                number_format((float)$l['debit'], 2, ',', ''),
                number_format((float)$l['credit'], 2, ',', ''),
                Accounting::statusLabel($l['status']),
            ], $rows)
        );
    }

    case 'fec': {
        // Fichier des Écritures Comptables — norme française (art. A47 A-1 du LPF)
        // Écritures validées uniquement, toutes périodes ou fenêtre de dates.
        $from = $_GET['date_from'] ?: null;
        $to   = $_GET['date_to'] ?: null;
        $rows = AccountingReport::generalJournal(null, $from, $to, 'validated');

        if (ob_get_length()) { ob_end_clean(); }
        header('Content-Type: text/plain; charset=ISO-8859-1');
        header('Content-Disposition: attachment; filename="000000000FEC' . ($to ? str_replace('-', '', $to) : date('Ymd')) . '.txt"');

        $headers = [
            'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib',
            'CompAuxNum', 'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib',
            'Debit', 'Credit', 'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
        ];
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers, '|');
        foreach ($rows as $l) {
            // Le FEC n'autorise ni le séparateur ni les guillemets dans les champs
            $clean = fn($v) => str_replace(['"', '|'], [' ', '/'], (string)$v);
            fputcsv($out, [
                $clean($l['journal_code']),
                $clean($l['journal_label']),
                $clean($l['entry_number']),
                str_replace('-', '', $l['entry_date']),
                $clean($l['account_number']),
                $clean(mb_substr($l['account_label'], 0, 60)),
                $clean($l['third_code'] ?? ''),
                $clean(mb_substr($l['third_name'] ?? '', 0, 45)),
                $clean($l['reference'] ?: $l['entry_number']),
                str_replace('-', '', $l['entry_date']),
                $clean($l['line_label'] ?: $l['entry_label']),
                number_format((float)$l['debit'], 2, ',', ''),
                number_format((float)$l['credit'], 2, ',', ''),
                $clean($l['lettering'] ?? ''),
                !empty($l['lettered_at']) ? str_replace('-', '', substr((string)$l['lettered_at'], 0, 10)) : '',
                str_replace('-', '', $l['entry_date']),
                '', '',
            ], '|');
        }
        fclose($out);
        exit;
    }

    case 'income': {
        $is = AccountingReport::incomeStatement($_GET['date_from'] ?: null, $_GET['date_to'] ?: null);
        $out = [['PRODUITS (classe 7)', '', '']];
        foreach ($is['produits'] as $p) {
            $out[] = [$p['number'], $p['label'], number_format(max(0.0, $p['amount']), 2, ',', '')];
        }
        $out[] = ['TOTAL PRODUITS', '', number_format($is['total_produits'], 2, ',', '')];
        $out[] = ['', '', ''];
        $out[] = ['CHARGES (classe 6)', '', ''];
        foreach ($is['charges'] as $c) {
            $out[] = [$c['number'], $c['label'], number_format(max(0.0, $c['amount']), 2, ',', '')];
        }
        $out[] = ['TOTAL CHARGES', '', number_format($is['total_charges'], 2, ',', '')];
        $out[] = [$is['resultat'] >= 0 ? 'BENEFICE' : 'PERTE', '', number_format(abs($is['resultat']), 2, ',', '')];
        Accounting::downloadCsv(
            "compte_resultat_{$dateStamp}.csv",
            ['Compte', 'Libelle', 'Montant'],
            $out
        );
    }

    case 'bilan': {
        $bs = AccountingReport::balanceSheet($_GET['as_of'] ?: date('Y-m-d'));
        $out = [];
        foreach ($bs['actif'] as $a) {
            $out[] = [$a['number'], $a['label'], 'ACTIF', number_format($a['amount'], 2, ',', '')];
        }
        foreach ($bs['passif'] as $p) {
            $out[] = [$p['number'], $p['label'], 'PASSIF', number_format($p['amount'], 2, ',', '')];
        }
        $out[] = ['', 'TOTAL ACTIF', '', number_format($bs['total_actif'], 2, ',', '')];
        $out[] = ['', 'TOTAL PASSIF', '', number_format($bs['total_passif'], 2, ',', '')];
        Accounting::downloadCsv("bilan_{$bs['as_of']}.csv", ['Compte', 'Libelle', 'Cote', 'Montant_Net'], $out);
    }

    case 'aging': {
        $fType = in_array($_GET['filter_type'] ?? '', ['client', 'supplier'], true) ? $_GET['filter_type'] : null;
        $rows = AccountingReport::agedBalances($fType, $_GET['as_of'] ?: null);
        Accounting::downloadCsv(
            "echeancier_tiers_{$dateStamp}.csv",
            ['Code', 'Tiers', 'Non_Echu', 'Retard_1_30j', 'Retard_31_60j', 'Retard_61_90j', 'Retard_Plus_90j', 'Total'],
            array_map(fn($r) => [
                $r['code'], $r['name'],
                number_format($r['not_due'], 2, ',', ''),
                number_format($r['d1_30'], 2, ',', ''),
                number_format($r['d31_60'], 2, ',', ''),
                number_format($r['d61_90'], 2, ',', ''),
                number_format($r['d90_plus'], 2, ',', ''),
                number_format($r['total'], 2, ',', ''),
            ], $rows)
        );
    }

    default:
        http_response_code(404);
        acc_flash_set('', 'Type d\'export inconnu.');
        header('Location: index.php');
        exit;
}
