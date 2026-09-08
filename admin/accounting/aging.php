<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Échéancier des tiers';
$activeNav = 'acc_aging';

$fType = in_array($_GET['type'] ?? '', ['client', 'supplier'], true) ? $_GET['type'] : null;
$fAsOf = trim($_GET['as_of'] ?? '') ?: date('Y-m-d');

$rows = AccountingReport::agedBalances($fType, $fAsOf);

$totals = ['not_due' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
foreach ($rows as $r) {
    foreach ($totals as $k => $_) {
        $totals[$k] += (float)$r[$k];
    }
}

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_aging');
acc_flash();
?>

<div class="card no-print">
  <form method="get" class="form-inline">
    <div class="form-row">
      <label>Type</label>
      <select name="type" onchange="this.form.submit()">
        <option value="">Clients &amp; Fournisseurs</option>
        <option value="client" <?= $fType === 'client' ? 'selected' : '' ?>>Clients uniquement</option>
        <option value="supplier" <?= $fType === 'supplier' ? 'selected' : '' ?>>Fournisseurs uniquement</option>
      </select>
    </div>
    <div class="form-row"><label>Situation au</label><input type="date" name="as_of" value="<?= htmlspecialchars($fAsOf) ?>"></div>
    <button class="btn">Afficher</button>
    <a class="btn secondary" href="aging.php">Réinitialiser</a>
  </form>
</div>

<div class="print-only print-header">
  <h1>Échéancier des tiers<?= $fType ? ' — ' . ($fType === 'client' ? 'clients' : 'fournisseurs') : '' ?></h1>
  <p>Situation au <?= Accounting::dateFr($fAsOf) ?> — créances non lettrées, montants en BIF</p>
</div>

<div class="card">
  <div class="acc-toolbar no-print">
    <h2><?= count($rows) ?> tiers avec encours</h2>
    <div class="acc-actions">
      <a class="btn secondary small" href="export.php?type=aging<?= $fType ? '&filter_type=' . $fType : '' ?>&as_of=<?= urlencode($fAsOf) ?>">Export CSV</a>
      <a class="btn secondary small" target="_blank" href="print.php?type=aging<?= $fType ? '&filter_type=' . $fType : '' ?>&as_of=<?= urlencode($fAsOf) ?>">Imprimer</a>
    </div>
  </div>

  <?php if (empty($rows)): ?>
  <p class="empty">Aucun encours ouvert au <?= Accounting::dateFr($fAsOf) ?>.</p>
  <?php else: ?>
  <table class="datatable" data-per-page="15">
    <thead>
      <tr>
        <th>Code</th><th>Tiers</th>
        <th class="num">Non échu</th>
        <th class="num">1-30 j</th><th class="num">31-60 j</th>
        <th class="num">61-90 j</th><th class="num">&gt; 90 j</th>
        <th class="num">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($r['code']) ?></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td class="num"><?= $r['not_due'] > 0 ? Accounting::money($r['not_due']) : '' ?></td>
        <td class="num <?= $r['d1_30'] > 0 ? 'amount-credit' : '' ?>"><?= $r['d1_30'] > 0 ? Accounting::money($r['d1_30']) : '' ?></td>
        <td class="num <?= $r['d31_60'] > 0 ? 'amount-credit' : '' ?>"><?= $r['d31_60'] > 0 ? Accounting::money($r['d31_60']) : '' ?></td>
        <td class="num <?= $r['d61_90'] > 0 ? 'amount-credit' : '' ?>" style="<?= $r['d61_90'] > 0 ? 'color:var(--amber);' : '' ?>"><?= $r['d61_90'] > 0 ? Accounting::money($r['d61_90']) : '' ?></td>
        <td class="num <?= $r['d90_plus'] > 0 ? 'sold-credit' : '' ?>" style="<?= $r['d90_plus'] > 0 ? 'color:var(--brick);font-weight:700;' : '' ?>"><?= $r['d90_plus'] > 0 ? Accounting::money($r['d90_plus']) : '' ?></td>
        <td class="num" style="font-weight:700;"><?= Accounting::money($r['total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2">TOTAUX</td>
        <td class="num"><?= Accounting::money($totals['not_due']) ?></td>
        <td class="num"><?= Accounting::money($totals['d1_30']) ?></td>
        <td class="num"><?= Accounting::money($totals['d31_60']) ?></td>
        <td class="num"><?= Accounting::money($totals['d61_90']) ?></td>
        <td class="num"><?= Accounting::money($totals['d90_plus']) ?></td>
        <td class="num"><?= Accounting::money($totals['total']) ?></td>
      </tr>
    </tfoot>
  </table>
  <p style="color:var(--ink-soft); font-size:12.5px;">
    Les lignes lettrées sont exclues ; les règlements non lettrés imputent d'abord les créances les plus anciennes.
  </p>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
