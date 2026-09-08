<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Bilan';
$activeNav = 'acc_bilan';

$fAsOf = trim($_GET['as_of'] ?? '');
if ($fAsOf === '') {
    $fAsOf = date('Y-m-d');
}

$bs = AccountingReport::balanceSheet($fAsOf);

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_bilan');
acc_flash();
?>

<div class="card no-print">
  <form method="get" class="form-inline">
    <div class="form-row">
      <label>Situation au</label>
      <input type="date" name="as_of" value="<?= htmlspecialchars($fAsOf) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <button class="btn">Afficher</button>
    <a class="btn secondary" href="balance_sheet.php">Aujourd'hui</a>
  </form>
</div>

<div class="print-only print-header">
  <h1>Bilan simplifié</h1>
  <p>Situation au <?= Accounting::dateFr($bs['as_of']) ?> — Montants en BIF</p>
</div>

<div class="card">
  <div class="acc-toolbar no-print">
    <h2>Actif / Passif au <?= Accounting::dateFr($bs['as_of']) ?></h2>
    <div class="acc-actions">
      <a class="btn secondary small" href="export.php?type=bilan&as_of=<?= urlencode($bs['as_of']) ?>">Export CSV</a>
      <a class="btn secondary small" target="_blank" href="print.php?type=bilan&as_of=<?= urlencode($bs['as_of']) ?>">Imprimer</a>
    </div>
  </div>

  <table>
    <thead>
      <tr><th style="width:16%;">Compte</th><th>ACTIF (soldes débiteurs)</th><th class="num" style="width:18%;">Montant net</th></tr>
    </thead>
    <tbody>
      <?php foreach ($bs['actif'] as $a): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($a['number']) ?></td>
        <td><?= htmlspecialchars($a['label']) ?> <span class="badge-class"><?= $a['class'] ?></span></td>
        <td class="num"><?= Accounting::money($a['amount']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="2">TOTAL ACTIF</td><td class="num"><?= Accounting::money($bs['total_actif']) ?></td></tr>
    </tfoot>
  </table>

  <table style="margin-top:26px;">
    <thead>
      <tr><th style="width:16%;">Compte</th><th>PASSIF (soldes créditeurs)</th><th class="num" style="width:18%;">Montant net</th></tr>
    </thead>
    <tbody>
      <?php foreach ($bs['passif'] as $p): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($p['number']) ?></td>
        <td><?= htmlspecialchars($p['label']) ?> <span class="badge-class"><?= $p['class'] ?></span></td>
        <td class="num"><?= Accounting::money($p['amount']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="2">TOTAL PASSIF</td><td class="num"><?= Accounting::money($bs['total_passif']) ?></td></tr>
    </tfoot>
  </table>

  <p style="margin-top:16px; font-size:13px;">
    Équilibre Actif = Passif :
    <?php if ($bs['equilibre']): ?>
      <span class="pill validated">✔ Équilibré</span>
    <?php else: ?>
      <span class="pill rejected">✘ Écart de <?= Accounting::money(abs($bs['total_actif'] - $bs['total_passif'])) ?></span>
    <?php endif; ?>
    &nbsp;— Résultat intégré : <?= $bs['resultat'] >= 0 ? 'bénéfice' : 'perte' ?> de <?= Accounting::money(abs($bs['resultat'])) ?>
  </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
