<?php
/**
 * Impressions du module comptabilité (pages A4 autonomes).
 * Types : entry | ledger | balance | gjournal
 */
require_once __DIR__ . '/_init.php';

$type = $_GET['type'] ?? '';
$fFrom = trim((string)($_GET['date_from'] ?? '')) ?: null;
$fTo   = trim((string)($_GET['date_to'] ?? '')) ?: null;

$title = 'Document comptable';
ob_start();

switch ($type) {
    case 'entry': {
        $entry = AccountingEntry::find((int)($_GET['id'] ?? 0));
        if (!$entry) { die('Écriture introuvable'); }
        $lines = AccountingEntry::lines((int)$entry['id']);
        $title = "Pièce {$entry['entry_number']}";
        $totD = array_sum(array_column($lines, 'debit'));
        $totC = array_sum(array_column($lines, 'credit'));
        ?>
<h1 style="text-align:center; margin:0;">Pièce comptable <?= htmlspecialchars($entry['journal_code']) ?></h1>
<p style="text-align:center; margin:4px 0 16px;">
  N° <b><?= htmlspecialchars((string)$entry['entry_number']) ?></b> —
  <?= Accounting::dateFr($entry['entry_date']) ?> —
  Statut : <?= Accounting::statusLabel($entry['status']) ?>
</p>
<table style="width:100%; border-collapse:collapse;" class="print-table">
  <tr><td style="width:50%;"><b>Journal :</b> <?= htmlspecialchars($entry['journal_code']) ?> — <?= htmlspecialchars($entry['journal_label']) ?></td>
      <td><b>Exercice :</b> <?= htmlspecialchars($entry['period_code']) ?></td></tr>
  <tr><td><b>Tiers :</b> <?= htmlspecialchars(trim(($entry['third_code'] ?? '') . ' ' . ($entry['third_name'] ?? '')) ?: '—') ?></td>
      <td><b>Référence :</b> <?= htmlspecialchars($entry['reference'] ?? '—') ?></td></tr>
  <tr><td colspan="2"><b>Libellé :</b> <?= htmlspecialchars($entry['label']) ?></td></tr>
</table>
<table style="width:100%; border-collapse:collapse; margin-top:12px;">
  <thead><tr style="border-bottom:2px solid #000;">
    <th style="text-align:left;">Compte</th><th style="text-align:left;">Libellé ligne</th><th style="text-align:left;">Tiers</th>
    <th style="text-align:right;">Débit</th><th style="text-align:right;">Crédit</th>
  </tr></thead>
  <tbody>
    <?php foreach ($lines as $l): ?>
    <tr>
      <td><?= htmlspecialchars($l['account_number']) ?> — <?= htmlspecialchars(mb_substr($l['account_label'], 0, 32)) ?></td>
      <td><?= htmlspecialchars((string)($l['label'] ?: $entry['label'])) ?></td>
      <td><?= htmlspecialchars((string)($l['third_code'] ?? '')) ?></td>
      <td style="text-align:right;"><?= (float)$l['debit'] > 0 ? Accounting::money($l['debit']) : '' ?></td>
      <td style="text-align:right;"><?= (float)$l['credit'] > 0 ? Accounting::money($l['credit']) : '' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr style="border-top:2px solid #000; font-weight:bold;">
    <td colspan="3">Totaux</td>
    <td style="text-align:right;"><?= Accounting::money($totD) ?></td>
    <td style="text-align:right;"><?= Accounting::money($totC) ?></td>
  </tr></tfoot>
</table>
<p style="margin-top:26px; font-size:11px; color:#444;">
  Édité le <?= date('d/m/Y H:i') ?> par <?= htmlspecialchars($currentAdmin['full_name'] ?? '') ?> — Montants en BIF.
</p>
<?php
        break;
    }

    case 'ledger': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $data = AccountingReport::ledger($accountId, $fFrom, $fTo);
        if (!$data) { die('Compte introuvable'); }
        $title = "Grand livre {$data['account']['number']}";
        ?>
<h1 style="text-align:center; margin:0;">Grand livre des comptes</h1>
<p style="text-align:center; margin:4px 0 16px;">
  Compte <b><?= htmlspecialchars($data['account']['number']) ?></b> — <?= htmlspecialchars($data['account']['label']) ?><br>
  <?php if ($fFrom || $fTo): ?>Période : <?= $fFrom ? Accounting::dateFr($fFrom) : '…' ?> → <?= $fTo ? Accounting::dateFr($fTo) : '…' ?><?php endif; ?>
</p>
<?php if ($data['opening'] != 0.0): ?>
<p><b>Solde à nouveau :</b> <?= Accounting::signedBalance($data['opening']) ?></p>
<?php endif; ?>
<table style="width:100%; border-collapse:collapse;">
  <thead><tr style="border-bottom:2px solid #000;">
    <th style="text-align:left;">Date</th><th style="text-align:left;">Pièce</th><th style="text-align:left;">Jl</th>
    <th style="text-align:left;">Libellé</th><th style="text-align:left;">Lett.</th>
    <th style="text-align:right;">Débit</th><th style="text-align:right;">Crédit</th><th style="text-align:right;">Solde</th>
  </tr></thead>
  <tbody>
    <?php foreach ($data['rows'] as $r): ?>
    <tr>
      <td><?= Accounting::dateFr($r['entry_date']) ?></td>
      <td><?= htmlspecialchars((string)$r['entry_number']) ?></td>
      <td><?= htmlspecialchars($r['journal_code']) ?></td>
      <td><?= htmlspecialchars(mb_substr((string)($r['line_label'] ?: $r['entry_label']), 0, 40)) ?></td>
      <td><?= htmlspecialchars((string)$r['lettering']) ?></td>
      <td style="text-align:right;"><?= (float)$r['debit'] > 0 ? Accounting::money($r['debit']) : '' ?></td>
      <td style="text-align:right;"><?= (float)$r['credit'] > 0 ? Accounting::money($r['credit']) : '' ?></td>
      <td style="text-align:right;"><?= Accounting::signedBalance((float)$r['running']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr style="border-top:2px solid #000; font-weight:bold;">
    <td colspan="5">Totaux</td>
    <td style="text-align:right;"><?= Accounting::money($data['total_debit']) ?></td>
    <td style="text-align:right;"><?= Accounting::money($data['total_credit']) ?></td>
    <td style="text-align:right;"><?= Accounting::signedBalance($data['closing']) ?></td>
  </tr></tfoot>
</table>
<p style="margin-top:20px; font-size:11px; color:#444;">Édité le <?= date('d/m/Y H:i') ?> — Montants en BIF.</p>
<?php
        break;
    }

    case 'balance': {
        $rows = AccountingReport::trialBalance($fFrom, $fTo, (int)($_GET['class'] ?? 0) ?: null, empty($_GET['all']));
        $title = 'Balance générale';
        $tD = array_sum(array_column($rows, 'debit'));
        $tC = array_sum(array_column($rows, 'credit'));
        $tSD = array_sum(array_column($rows, 'closing_debit'));
        $tSC = array_sum(array_column($rows, 'closing_credit'));
        ?>
<h1 style="text-align:center; margin:0;">Balance générale</h1>
<p style="text-align:center; margin:4px 0 16px;">
  <?= $fFrom || $fTo ? 'Période du ' . ($fFrom ? Accounting::dateFr($fFrom) : 'origine') . ' au ' . ($fTo ? Accounting::dateFr($fTo) : date('d/m/Y')) : 'Depuis l\'origine' ?>
</p>
<table style="width:100%; border-collapse:collapse;">
  <thead><tr style="border-bottom:2px solid #000;">
    <th style="text-align:left;">Compte</th><th style="text-align:left;">Libellé</th>
    <th style="text-align:right;">Mouv. Débit</th><th style="text-align:right;">Mouv. Crédit</th>
    <th style="text-align:right;">Solde Déb.</th><th style="text-align:right;">Solde Créd.</th>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= htmlspecialchars($r['number']) ?></td>
      <td><?= htmlspecialchars(mb_substr($r['label'], 0, 45)) ?></td>
      <td style="text-align:right;"><?= Accounting::money($r['debit']) ?></td>
      <td style="text-align:right;"><?= Accounting::money($r['credit']) ?></td>
      <td style="text-align:right;"><?= $r['closing_debit'] > 0 ? Accounting::money($r['closing_debit']) : '' ?></td>
      <td style="text-align:right;"><?= $r['closing_credit'] > 0 ? Accounting::money($r['closing_credit']) : '' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr style="border-top:2px solid #000; font-weight:bold;">
    <td colspan="2">Totaux</td>
    <td style="text-align:right;"><?= Accounting::money($tD) ?></td>
    <td style="text-align:right;"><?= Accounting::money($tC) ?></td>
    <td style="text-align:right;"><?= Accounting::money($tSD) ?></td>
    <td style="text-align:right;"><?= Accounting::money($tSC) ?></td>
  </tr></tfoot>
</table>
<p style="margin-top:20px; font-size:11px; color:#444;">Édité le <?= date('d/m/Y H:i') ?> — Montants en BIF.</p>
<?php
        break;
    }

    case 'gjournal': {
        $rows = AccountingReport::generalJournal(
            (int)($_GET['journal_id'] ?? 0) ?: null, $fFrom, $fTo,
            in_array($_GET['status'] ?? '', ['draft', 'validated'], true) ? $_GET['status'] : 'validated'
        );
        $title = 'Journal général';
        $tD = 0.0; $tC = 0.0;
        ?>
<h1 style="text-align:center; margin:0;">Journal général<?= ($_GET['journal_id'] ?? 0) ? ' — journal sélectionné' : '' ?></h1>
<p style="text-align:center; margin:4px 0 16px;">
  <?= $fFrom || $fTo ? 'Du ' . ($fFrom ? Accounting::dateFr($fFrom) : '…') . ' au ' . ($fTo ? Accounting::dateFr($fTo) : date('d/m/Y')) : 'Toutes périodes' ?>
</p>
<table style="width:100%; border-collapse:collapse;">
  <thead><tr style="border-bottom:2px solid #000;">
    <th style="text-align:left;">Date</th><th>Jl</th><th>Pièce</th>
    <th style="text-align:left;">Compte / Libellé</th>
    <th style="text-align:right;">Débit</th><th style="text-align:right;">Crédit</th>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $l): $tD += (float)$l['debit']; $tC += (float)$l['credit']; ?>
    <tr>
      <td><?= Accounting::dateFr($l['entry_date']) ?></td>
      <td style="text-align:center;"><?= htmlspecialchars($l['journal_code']) ?></td>
      <td><?= htmlspecialchars((string)$l['entry_number']) ?></td>
      <td><?= htmlspecialchars($l['account_number'] . ' ' . mb_substr((string)$l['account_label'], 0, 24))
            . ' — ' . htmlspecialchars(mb_substr((string)($l['line_label'] ?: $l['entry_label']), 0, 38)) ?></td>
      <td style="text-align:right;"><?= (float)$l['debit'] > 0 ? Accounting::money($l['debit']) : '' ?></td>
      <td style="text-align:right;"><?= (float)$l['credit'] > 0 ? Accounting::money($l['credit']) : '' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr style="border-top:2px solid #000; font-weight:bold;">
    <td colspan="4">Totaux</td>
    <td style="text-align:right;"><?= Accounting::money($tD) ?></td>
    <td style="text-align:right;"><?= Accounting::money($tC) ?></td>
  </tr></tfoot>
</table>
<p style="margin-top:20px; font-size:11px; color:#444;">Édité le <?= date('d/m/Y H:i') ?> — Montants en BIF.</p>
<?php
        break;
    }

    case 'income': {
        $is = AccountingReport::incomeStatement($fFrom, $fTo);
        $title = 'Compte de résultat';
        ?>
<h1 style="text-align:center; margin:0;">Compte de résultat</h1>
<p style="text-align:center; margin:4px 0 16px;">
  <?= $fFrom || $fTo ? 'Période du ' . ($fFrom ? Accounting::dateFr($fFrom) : 'origine') . ' au ' . ($fTo ? Accounting::dateFr($fTo) : date('d/m/Y')) : 'Depuis l\'origine' ?>
</p>
<table style="width:100%; border-collapse:collapse;">
  <thead><tr style="border-bottom:2px solid #000;"><th style="text-align:left;width:18%;">Compte</th><th style="text-align:left;">Libellé</th><th style="text-align:right;width:22%;">Montant</th></tr></thead>
  <tbody>
    <tr><td colspan="3" style="font-weight:bold; background:#eee;">PRODUITS (classe 7)</td></tr>
    <?php foreach ($is['produits'] as $p): ?>
    <tr><td><?= htmlspecialchars($p['number']) ?></td><td><?= htmlspecialchars(mb_substr($p['label'], 0, 50)) ?></td>
        <td style="text-align:right;"><?= Accounting::money(max(0.0, $p['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <tr style="font-weight:bold;"><td colspan="2">Total des produits</td><td style="text-align:right;"><?= Accounting::money($is['total_produits']) ?></td></tr>
    <tr><td colspan="3" style="font-weight:bold; background:#eee;">CHARGES (classe 6)</td></tr>
    <?php foreach ($is['charges'] as $c): ?>
    <tr><td><?= htmlspecialchars($c['number']) ?></td><td><?= htmlspecialchars(mb_substr($c['label'], 0, 50)) ?></td>
        <td style="text-align:right;"><?= Accounting::money(max(0.0, $c['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <tr style="font-weight:bold;"><td colspan="2">Total des charges</td><td style="text-align:right;"><?= Accounting::money($is['total_charges']) ?></td></tr>
  </tbody>
  <tfoot><tr style="border-top:2px solid #000; font-weight:bold; font-size:14px;">
    <td colspan="2"><?= $is['resultat'] >= 0 ? 'BÉNÉFICE' : 'PERTE' ?></td>
    <td style="text-align:right;"><?= Accounting::money(abs($is['resultat'])) ?></td>
  </tr></tfoot>
</table>
<p style="margin-top:20px; font-size:11px; color:#444;">Édité le <?= date('d/m/Y H:i') ?> — Montants en BIF.</p>
<?php
        break;
    }

    case 'bilan': {
        $bs = AccountingReport::balanceSheet($_GET['as_of'] ?: date('Y-m-d'));
        $title = 'Bilan';
        ?>
<h1 style="text-align:center; margin:0;">Bilan simplifié</h1>
<p style="text-align:center; margin:4px 0 16px;">Situation au <?= Accounting::dateFr($bs['as_of']) ?></p>
<table style="width:49%; border-collapse:collapse; float:left;">
  <thead><tr style="border-bottom:2px solid #000;"><th colspan="2">ACTIF</th></tr>
  <tr><th style="text-align:left;">Compte</th><th style="text-align:right;">Net</th></tr></thead>
  <tbody>
    <?php foreach ($bs['actif'] as $a): ?>
    <tr><td><?= htmlspecialchars(mb_substr($a['number'] . ' ' . $a['label'], 0, 38)) ?></td>
        <td style="text-align:right;"><?= Accounting::money($a['amount']) ?></td></tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr style="border-top:2px solid #000; font-weight:bold;"><td>TOTAL ACTIF</td>
      <td style="text-align:right;"><?= Accounting::money($bs['total_actif']) ?></td></tr></tfoot>
</table>
<table style="width:49%; border-collapse:collapse; float:right;">
  <thead><tr style="border-bottom:2px solid #000;"><th colspan="2">PASSIF</th></tr>
  <tr><th style="text-align:left;">Compte</th><th style="text-align:right;">Net</th></tr></thead>
  <tbody>
    <?php foreach ($bs['passif'] as $p): ?>
    <tr><td><?= htmlspecialchars(mb_substr($p['number'] . ' ' . $p['label'], 0, 38)) ?></td>
        <td style="text-align:right;"><?= Accounting::money($p['amount']) ?></td></tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr style="border-top:2px solid #000; font-weight:bold;"><td>TOTAL PASSIF</td>
      <td style="text-align:right;"><?= Accounting::money($bs['total_passif']) ?></td></tr></tfoot>
</table>
<div style="clear:both;"></div>
<p style="margin-top:16px; font-weight:bold; color:<?= $bs['equilibre'] ? '#2E6E46' : '#96341F' ?>;">
  <?= $bs['equilibre'] ? 'Bilan équilibré.' : 'Déséquilibre détecté !' ?>
</p>
<p style="font-size:11px; color:#444;">Édité le <?= date('d/m/Y H:i') ?> — Montants en BIF.</p>
<?php
        break;
    }

    case 'aging': {
        $fType = in_array($_GET['filter_type'] ?? '', ['client', 'supplier'], true) ? $_GET['filter_type'] : null;
        $rows = AccountingReport::agedBalances($fType, $_GET['as_of'] ?: date('Y-m-d'));
        $title = 'Échéancier des tiers';
        $t = ['not_due' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0];
        foreach ($rows as $r) { foreach ($t as $k => $_) { $t[$k] += (float)$r[$k]; } }
        ?>
<h1 style="text-align:center; margin:0;">Échéancier des tiers<?= $fType ? ' — ' . ($fType === 'client' ? 'clients' : 'fournisseurs') : '' ?></h1>
<p style="text-align:center; margin:4px 0 16px;">Situation au <?= Accounting::dateFr($_GET['as_of'] ?: date('Y-m-d')) ?></p>
<table style="width:100%; border-collapse:collapse;">
  <thead><tr style="border-bottom:2px solid #000;">
    <th style="text-align:left;">Tiers</th><th style="text-align:right;">Non échu</th>
    <th style="text-align:right;">1-30 j</th><th style="text-align:right;">31-60 j</th>
    <th style="text-align:right;">61-90 j</th><th style="text-align:right;">&gt; 90 j</th><th style="text-align:right;">Total</th>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= htmlspecialchars($r['code'] . ' ' . mb_substr($r['name'], 0, 26)) ?></td>
      <td style="text-align:right;"><?= $r['not_due'] > 0 ? Accounting::money($r['not_due']) : '' ?></td>
      <td style="text-align:right;"><?= $r['d1_30'] > 0 ? Accounting::money($r['d1_30']) : '' ?></td>
      <td style="text-align:right;"><?= $r['d31_60'] > 0 ? Accounting::money($r['d31_60']) : '' ?></td>
      <td style="text-align:right;"><?= $r['d61_90'] > 0 ? Accounting::money($r['d61_90']) : '' ?></td>
      <td style="text-align:right; font-weight:bold;"><?= $r['d90_plus'] > 0 ? Accounting::money($r['d90_plus']) : '' ?></td>
      <td style="text-align:right;"><?= Accounting::money($r['total']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr style="border-top:2px solid #000; font-weight:bold;">
    <td>TOTAUX</td>
    <td style="text-align:right;"><?= Accounting::money($t['not_due']) ?></td>
    <td style="text-align:right;"><?= Accounting::money($t['d1_30']) ?></td>
    <td style="text-align:right;"><?= Accounting::money($t['d31_60']) ?></td>
    <td style="text-align:right;"><?= Accounting::money($t['d61_90']) ?></td>
    <td style="text-align:right;"><?= Accounting::money($t['d90_plus']) ?></td>
    <td style="text-align:right;"><?= Accounting::money(array_sum($t)) ?></td>
  </tr></tfoot>
</table>
<p style="margin-top:20px; font-size:11px; color:#444;">Créances non lettrées. Édité le <?= date('d/m/Y H:i') ?> — Montants en BIF.</p>
<?php
        break;
    }

    default:
        http_response_code(404);
        die('Type d\'impression inconnu');
}

$body = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Impression — <?= htmlspecialchars($title) ?></title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #111; margin: 30px auto; max-width: 1100px; }
  table th, table td { padding: 4px 8px; border-bottom: 1px solid #ccc; }
  thead tr, tfoot tr { border-bottom: none; }
  @media print {
    .print-btn { display: none; }
    @page { size: A4 portrait; margin: 14mm; }
  }
  @page { size: A4 portrait; margin: 14mm; }
  .print-btn { position: fixed; top: 10px; right: 10px; padding: 9px 18px; background: #16241C; color: #fff;
               border: none; border-radius: 7px; cursor: pointer; font-size: 13px; }
</style>
</head>
<body onload="window.print()">
<button class="print-btn" onclick="window.print()">🖨 Imprimer</button>
<?= $body ?>
</body>
</html>
