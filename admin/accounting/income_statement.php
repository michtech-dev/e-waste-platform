<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Compte de résultat';
$activeNav = 'acc_income';

$fFrom = trim($_GET['date_from'] ?? '');
$fTo   = trim($_GET['date_to'] ?? '');
if ($fFrom === '' && $fTo === '' && ($cur = AccountingPeriod::current())) {
    $fFrom = $cur['start_date'];
    $fTo   = min(date('Y-m-d'), $cur['end_date']);
}

$is = AccountingReport::incomeStatement($fFrom ?: null, $fTo ?: null);
$qParams = array_filter(['date_from' => $fFrom, 'date_to' => $fTo]);

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_income');
acc_flash();
?>

<div class="card no-print">
  <form method="get" class="form-inline">
    <div class="form-row"><label>Du</label><input type="date" name="date_from" value="<?= htmlspecialchars($fFrom) ?>"></div>
    <div class="form-row"><label>Au</label><input type="date" name="date_to" value="<?= htmlspecialchars($fTo) ?>"></div>
    <button class="btn">Afficher</button>
    <a class="btn secondary" href="income_statement.php">Exercice courant</a>
  </form>
</div>

<div class="print-only print-header">
  <h1>Compte de résultat</h1>
  <p>Période du <?= Accounting::dateFr($fFrom ?: '1900-01-01') ?> au <?= Accounting::dateFr($fTo ?: date('Y-m-d')) ?> — Montants en BIF</p>
</div>

<div class="card">
  <div class="acc-toolbar no-print">
    <h2>Produits − Charges</h2>
    <div class="acc-actions">
      <a class="btn secondary small" href="export.php?type=income&<?= http_build_query($qParams) ?>">Export CSV</a>
      <a class="btn secondary small" target="_blank" href="print.php?type=income&<?= http_build_query($qParams) ?>">Imprimer</a>
    </div>
  </div>

  <table style="max-width:860px;">
    <thead>
      <tr><th style="width:18%;">Compte</th><th>Libellé</th><th class="num" style="width:20%;">Montant</th></tr>
    </thead>
    <tbody>
      <tr class="section-row"><td colspan="3">PRODUITS (classe 7)</td></tr>
      <?php if (empty($is['produits'])): ?>
      <tr><td colspan="3" class="empty">Aucun produit sur la période.</td></tr>
      <?php else: foreach ($is['produits'] as $p): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($p['number']) ?></td>
        <td><?= htmlspecialchars($p['label']) ?></td>
        <td class="num"><?= Accounting::money(max(0.0, $p['amount'])) ?></td>
      </tr>
      <?php endforeach; endif; ?>
      <tr style="font-weight:700;">
        <td colspan="2">Total des produits</td>
        <td class="num"><?= Accounting::money($is['total_produits']) ?></td>
      </tr>

      <tr class="section-row"><td colspan="3">CHARGES (classe 6)</td></tr>
      <?php if (empty($is['charges'])): ?>
      <tr><td colspan="3" class="empty">Aucune charge sur la période.</td></tr>
      <?php else: foreach ($is['charges'] as $c): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($c['number']) ?></td>
        <td><?= htmlspecialchars($c['label']) ?></td>
        <td class="num"><?= Accounting::money(max(0.0, $c['amount'])) ?></td>
      </tr>
      <?php endforeach; endif; ?>
      <tr style="font-weight:700;">
        <td colspan="2">Total des charges</td>
        <td class="num"><?= Accounting::money($is['total_charges']) ?></td>
      </tr>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2"><?= $is['resultat'] >= 0 ? 'BÉNÉFICE (produits − charges)' : 'PERTE (charges − produits)' ?></td>
        <td class="num" style="font-size:15px; <?= $is['resultat'] >= 0 ? 'color:var(--accent-dark);' : 'color:var(--brick);' ?>">
          <?= Accounting::money(abs($is['resultat'])) ?>
        </td>
      </tr>
    </tfoot>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
