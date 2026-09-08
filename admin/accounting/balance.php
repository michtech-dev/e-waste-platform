<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Balance générale';
$activeNav = 'acc_balance';

$fFrom  = trim($_GET['date_from'] ?? '');
$fTo    = trim($_GET['date_to'] ?? '');
$fClass = (int)($_GET['class'] ?? 0);
$nonZero = !isset($_GET['all']);

$rows = AccountingReport::trialBalance(
    $fFrom ?: null,
    $fTo ?: null,
    $fClass ?: null,
    $nonZero
);

$sumOpeningD = $sumOpeningC = $sumDebit = $sumCredit = $sumClosingD = $sumClosingC = 0.0;
foreach ($rows as $r) {
    if ($r['opening'] > 0) { $sumOpeningD += $r['opening']; } else { $sumOpeningC += -$r['opening']; }
    $sumDebit   += $r['debit'];
    $sumCredit  += $r['credit'];
    $sumClosingD += $r['closing_debit'];
    $sumClosingC += $r['closing_credit'];
}

$qParams = array_filter(['date_from' => $fFrom, 'date_to' => $fTo, 'class' => $fClass, 'all' => $nonZero ? '' : '1']);
$balanced = abs($sumDebit - $sumCredit) < 0.005 && abs($sumClosingD - $sumClosingC) < 0.005;

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_balance');
acc_flash();
?>

<div class="card no-print">
  <form method="get" class="form-inline">
    <div class="form-row"><label>Du</label><input type="date" name="date_from" value="<?= htmlspecialchars($fFrom) ?>"></div>
    <div class="form-row"><label>Au</label><input type="date" name="date_to" value="<?= htmlspecialchars($fTo) ?>"></div>
    <div class="form-row">
      <label>Classe</label>
      <select name="class" onchange="this.form.submit()">
        <option value="">Toutes</option>
        <?php foreach (AccountingAccount::CLASS_LABELS as $c => $lbl): ?>
        <option value="<?= $c ?>" <?= $fClass === $c ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row" style="display:flex; align-items:flex-end;">
      <label style="display:flex; gap:7px; align-items:center; font-size:13px; margin:0;">
        <input type="checkbox" name="all" value="1" <?= !$nonZero ? 'checked' : '' ?>> Afficher tous les comptes
      </label>
    </div>
    <button class="btn">Afficher</button>
    <a class="btn secondary" href="balance.php">Réinitialiser</a>
  </form>
</div>

<div class="print-only print-header">
  <h1>Balance générale<?= $fFrom || $fTo ? ' ' . ($fFrom ? 'du ' . Accounting::dateFr($fFrom) : '') . ($fTo ? ' au ' . Accounting::dateFr($fTo) : '') : '' ?></h1>
  <p>Édité le <?= date('d/m/Y H:i') ?> — Montants en BIF</p>
</div>

<div class="card">
  <div class="acc-toolbar no-print">
    <h2><?= count($rows) ?> compte<?= count($rows) > 1 ? 's' : '' ?> mouvementé<?= count($rows) > 1 ? 's' : '' ?></h2>
    <div class="acc-actions">
      <a class="btn secondary small" href="export.php?type=balance&<?= http_build_query($qParams) ?>">Export CSV</a>
      <a class="btn secondary small" target="_blank" href="print.php?type=balance&<?= http_build_query(array_filter(['date_from' => $fFrom, 'date_to' => $fTo, 'class' => $fClass])) ?>">Imprimer</a>
    </div>
  </div>

  <?php if (empty($rows)): ?>
  <p class="empty">Aucun mouvement sur la période sélectionnée.</p>
  <?php else: ?>
  <table class="datatable" data-per-page="25">
    <thead>
      <tr>
        <th>Compte</th><th>Libellé</th><th>Cls</th>
        <th class="num">Solde à nouveau D</th><th class="num">Solde à nouveau C</th>
        <th class="num">Mouvements D</th><th class="num">Mouvements C</th>
        <th class="num">Solde D</th><th class="num">Solde C</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($r['number']) ?></td>
        <td><?= htmlspecialchars($r['label']) ?></td>
        <td><span class="badge-class"><?= $r['class'] ?></span></td>
        <td class="num"><?= $r['opening'] > 0 ? Accounting::money($r['opening']) : '' ?></td>
        <td class="num"><?= $r['opening'] < 0 ? Accounting::money(-$r['opening']) : '' ?></td>
        <td class="num amount-debit"><?= Accounting::money($r['debit']) ?></td>
        <td class="num amount-credit"><?= Accounting::money($r['credit']) ?></td>
        <td class="num sold-debit"><?= $r['closing_debit'] > 0 ? Accounting::money($r['closing_debit']) : '' ?></td>
        <td class="num sold-credit"><?= $r['closing_credit'] > 0 ? Accounting::money($r['closing_credit']) : '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3">Totaux</td>
        <td class="num"><?= Accounting::money($sumOpeningD) ?></td>
        <td class="num"><?= Accounting::money($sumOpeningC) ?></td>
        <td class="num"><?= Accounting::money($sumDebit) ?></td>
        <td class="num"><?= Accounting::money($sumCredit) ?></td>
        <td class="num"><?= Accounting::money($sumClosingD) ?></td>
        <td class="num"><?= Accounting::money($sumClosingC) ?></td>
      </tr>
    </tfoot>
  </table>

  <p style="margin-top:12px; font-size:13px;">
    Contrôle partie double :
    <?php if ($balanced): ?>
      <span class="pill validated">✔ Équilibrée (mouvements et soldes)</span>
    <?php else: ?>
      <span class="pill rejected">✘ Déséquilibre détecté</span>
    <?php endif; ?>
  </p>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
