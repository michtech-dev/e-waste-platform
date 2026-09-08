<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Journal général';
$activeNav = 'acc_gjournal';

$fJournal = (int)($_GET['journal_id'] ?? 0);
$fFrom  = trim($_GET['date_from'] ?? '');
$fTo    = trim($_GET['date_to'] ?? '');
$fStatus = in_array($_GET['status'] ?? '', ['draft', 'validated'], true) ? $_GET['status'] : 'validated';

$rows = AccountingReport::generalJournal(
    $fJournal ?: null,
    $fFrom ?: null,
    $fTo ?: null,
    $fStatus ?: null
);

// Regroupement par pièce (comme l'édition du journal dans Sage)
$grouped = [];
foreach ($rows as $r) {
    $key = (int)$r['entry_id'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'entry_number' => $r['entry_number'],
            'entry_date'   => $r['entry_date'],
            'journal_code' => $r['journal_code'],
            'label'        => $r['entry_label'],
            'reference'    => $r['reference'],
            'status'       => $r['status'],
            'lines'        => [],
            'debit'        => 0.0,
            'credit'       => 0.0,
        ];
    }
    $grouped[$key]['lines'][] = $r;
    $grouped[$key]['debit']  += (float)$r['debit'];
    $grouped[$key]['credit'] += (float)$r['credit'];
}

$totalDebit = array_sum(array_column($grouped, 'debit'));
$totalCredit = array_sum(array_column($grouped, 'credit'));

$qParams = array_filter([
    'journal_id' => $fJournal, 'date_from' => $fFrom,
    'date_to' => $fTo, 'status' => $fStatus,
]);

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_gjournal');
acc_flash();
?>

<div class="card no-print">
  <form method="get" class="form-inline">
    <div class="form-row">
      <label>Journal</label>
      <select name="journal_id" onchange="this.form.submit()">
        <option value="">Tous les journaux</option>
        <?php foreach (AccountingJournal::all() as $j): ?>
        <option value="<?= $j['id'] ?>" <?= $fJournal === (int)$j['id'] ? 'selected' : '' ?>><?= htmlspecialchars($j['code'] . ' — ' . $j['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Du</label><input type="date" name="date_from" value="<?= htmlspecialchars($fFrom) ?>"></div>
    <div class="form-row"><label>Au</label><input type="date" name="date_to" value="<?= htmlspecialchars($fTo) ?>"></div>
    <div class="form-row">
      <label>Statut</label>
      <select name="status" onchange="this.form.submit()">
        <option value="validated" <?= $fStatus === 'validated' ? 'selected' : '' ?>>Validées</option>
        <option value="draft" <?= $fStatus === 'draft' ? 'selected' : '' ?>>Brouillons</option>
        <option value="" <?= $fStatus === '' ? 'selected' : '' ?>>Tous</option>
      </select>
    </div>
    <button class="btn">Afficher</button>
    <a class="btn secondary" href="general_journal.php">Réinitialiser</a>
  </form>
</div>

<div class="print-only print-header">
  <h1>Journal général<?= $fFrom || $fTo ? ' — ' . ($fFrom ? 'du ' . Accounting::dateFr($fFrom) : '') . ($fTo ? ' au ' . Accounting::dateFr($fTo) : '') : '' ?></h1>
  <p>Édité le <?= date('d/m/Y H:i') ?> — Montants en BIF</p>
</div>

<div class="card">
  <div class="acc-toolbar no-print">
    <h2><?= count($grouped) ?> pièce<?= count($grouped) > 1 ? 's' : '' ?></h2>
    <div class="acc-actions">
      <a class="btn secondary small" href="export.php?type=gjournal&<?= http_build_query($qParams) ?>">Export CSV</a>
      <a class="btn secondary small" target="_blank" href="print.php?type=gjournal&<?= http_build_query($qParams) ?>">Imprimer</a>
    </div>
  </div>

  <?php if (empty($grouped)): ?>
  <p class="empty">Aucune écriture sur la période sélectionnée.</p>
  <?php else: ?>
  <?php foreach ($grouped as $g): ?>
  <table style="margin-bottom:18px;">
    <thead>
      <tr class="section-row">
        <th colspan="3">
          <?= Accounting::dateFr($g['entry_date']) ?> ·
          <span class="pill jtype"><?= htmlspecialchars($g['journal_code']) ?></span>
          Pièce <span class="mono"><?= htmlspecialchars((string)$g['entry_number']) ?></span>
          <?= $g['reference'] !== null ? '· Réf. ' . htmlspecialchars($g['reference']) : '' ?>
          <?= $g['status'] === 'draft' ? '<span class="pill draft">Brouillon</span>' : '' ?>
        </th>
      </tr>
      <tr><th style="width:18%;">Compte</th><th>Libellé</th><th class="num" style="width:14%;">Débit</th><th class="num" style="width:14%;">Crédit</th></tr>
    </thead>
    <tbody>
      <?php foreach ($g['lines'] as $l): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($l['account_number']) ?> <?= htmlspecialchars(mb_substr((string)$l['account_label'], 0, 30)) ?></td>
        <td><?= htmlspecialchars((string)($l['line_label'] ?: $l['entry_label'])) ?><?= $l['third_name'] ? ' <span class="pill client">' . htmlspecialchars($l['third_code']) . '</span>' : '' ?></td>
        <td class="num amount-debit"><?= (float)$l['debit'] > 0 ? Accounting::money($l['debit']) : '' ?></td>
        <td class="num amount-credit"><?= (float)$l['credit'] > 0 ? Accounting::money($l['credit']) : '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2"><em><?= htmlspecialchars((string)$g['label']) ?></em></td>
        <td class="num"><?= Accounting::money($g['debit']) ?></td>
        <td class="num"><?= Accounting::money($g['credit']) ?></td>
      </tr>
    </tfoot>
  </table>
  <?php endforeach; ?>

  <table>
    <tfoot>
      <tr>
        <td>TOTAUX GÉNÉRAUX</td><td></td>
        <td class="num"><?= Accounting::money($totalDebit) ?></td>
        <td class="num"><?= Accounting::money($totalCredit) ?></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
