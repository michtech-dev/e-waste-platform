<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Écritures';
$activeNav = 'acc_entries';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'validate' && acc_handle(function () use ($id) {
        AccountingEntry::setStatus($id, 'validated');
        acc_flash_set("Pièce #{$id} validée.");
    })) { header('Location: entries.php?' . http_build_query($_GET)); exit; }
    if ($action === 'unvalidate' && acc_handle(function () use ($id) {
        AccountingEntry::setStatus($id, 'draft');
        acc_flash_set("Pièce #{$id} remise en brouillon.");
    })) { header('Location: entries.php?' . http_build_query($_GET)); exit; }
    if ($action === 'delete' && acc_handle(function () use ($id) {
        AccountingEntry::delete($id);
        acc_flash_set("Pièce #{$id} supprimée.");
    })) { header('Location: entries.php?' . http_build_query($_GET)); exit; }
}

// Filtres
$curPeriod = AccountingPeriod::current();
$fPeriod   = (int)($_GET['period_id'] ?? ($curPeriod['id'] ?? 0));
$fJournal  = (int)($_GET['journal_id'] ?? 0);
$fStatus   = in_array($_GET['status'] ?? '', ['draft', 'validated'], true) ? $_GET['status'] : '';
$fFrom     = trim($_GET['date_from'] ?? '');
$fTo       = trim($_GET['date_to'] ?? '');
$fQ        = trim($_GET['q'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;

$filters = [
    'period_id' => $fPeriod ?: null,
    'journal_id' => $fJournal ?: null,
    'status' => $fStatus,
    'date_from' => $fFrom ?: null,
    'date_to' => $fTo ?: null,
    'q' => $fQ ?: null,
];
$total   = AccountingEntry::countSearch($filters);
$entries = AccountingEntry::search($filters, $perPage, ($page - 1) * $perPage);
$totalPages = max(1, (int)ceil($total / $perPage));

$sumDebit = array_sum(array_column($entries, 'total_debit'));
$sumCredit = array_sum(array_column($entries, 'total_credit'));

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_entries');
acc_flash();
?>

<div class="card no-print">
  <form method="get" class="form-inline">
    <div class="form-row">
      <label>Exercice</label>
      <select name="period_id">
        <option value="">Tous</option>
        <?php foreach (AccountingPeriod::all() as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $fPeriod === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>Journal</label>
      <select name="journal_id">
        <option value="">Tous</option>
        <?php foreach (AccountingJournal::all() as $j): ?>
        <option value="<?= $j['id'] ?>" <?= $fJournal === (int)$j['id'] ? 'selected' : '' ?>><?= htmlspecialchars($j['code'] . ' — ' . $j['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>Statut</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">Tous</option>
        <option value="draft" <?= $fStatus === 'draft' ? 'selected' : '' ?>>Brouillons</option>
        <option value="validated" <?= $fStatus === 'validated' ? 'selected' : '' ?>>Validées</option>
      </select>
    </div>
    <div class="form-row"><label>Du</label><input type="date" name="date_from" value="<?= htmlspecialchars($fFrom) ?>"></div>
    <div class="form-row"><label>Au</label><input type="date" name="date_to" value="<?= htmlspecialchars($fTo) ?>"></div>
    <div class="form-row"><label>Recherche</label><input name="q" value="<?= htmlspecialchars($fQ) ?>" placeholder="Libellé, référence, n°…"></div>
    <button class="btn">Filtrer</button>
    <a class="btn secondary" href="entries.php">Réinitialiser</a>
  </form>
</div>

<div class="card">
  <div class="acc-toolbar">
    <h2><?= $total ?> pièce<?= $total > 1 ? 's' : '' ?></h2>
    <div class="acc-actions no-print">
      <a class="btn" href="entry_form.php">＋ Nouvelle pièce</a>
      <a class="btn secondary small" href="export.php?type=entries&<?= http_build_query(array_filter($_GET, fn($k) => in_array($k, ['period_id', 'journal_id', 'status', 'date_from', 'date_to', 'q'], true), ARRAY_FILTER_USE_KEY)) ?>">Export CSV</a>
      <a class="btn secondary small" href="#" onclick="window.print(); return false;">Imprimer</a>
    </div>
  </div>

  <?php if (empty($entries)): ?>
    <p class="empty">Aucune écriture ne correspond à ces filtres.</p>
  <?php else: ?>
  <table class="datatable" data-per-page="25">
    <thead>
      <tr>
        <th>Pièce</th><th>Journal</th><th data-type="date">Date</th><th>Libellé</th><th>Tiers</th>
        <th class="num">Débit</th><th class="num">Crédit</th><th>Réf.</th><th>Statut</th><th class="no-print">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($entries as $e): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars((string)$e['entry_number']) ?></td>
        <td><span class="pill jtype"><?= htmlspecialchars($e['journal_code']) ?></span></td>
        <td><?= Accounting::dateFr($e['entry_date']) ?></td>
        <td><?= htmlspecialchars(mb_substr((string)$e['label'], 0, 55)) ?></td>
        <td><?= htmlspecialchars($e['third_name'] ?? '—') ?></td>
        <td class="num amount-debit"><?= Accounting::money($e['total_debit']) ?></td>
        <td class="num amount-credit"><?= Accounting::money($e['total_credit']) ?></td>
        <td class="mono"><?= htmlspecialchars((string)$e['reference']) ?></td>
        <td><span class="pill <?= $e['status'] ?>"><?= Accounting::statusLabel($e['status']) ?></span></td>
        <td class="no-print" style="white-space:nowrap;">
          <a class="btn small secondary" href="print.php?type=entry&id=<?= (int)$e['id'] ?>" target="_blank">Aperçu</a>
          <?php if ($e['status'] === 'draft'): ?>
          <a class="btn small secondary" href="entry_form.php?id=<?= (int)$e['id'] ?>">Modifier</a>
          <form method="post" style="display:inline;" onsubmit="return confirm('Valider cette écriture ?');">
            <input type="hidden" name="action" value="validate"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button class="btn small">Valider</button>
          </form>
          <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce brouillon ?');">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button class="btn small danger">Suppr.</button>
          </form>
          <?php else: ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('Remettre cette écriture en brouillon ?');">
            <input type="hidden" name="action" value="unvalidate"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button class="btn small secondary">Dévalider</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="5">Totaux de la page affichée</td>
        <td class="num"><?= Accounting::money($sumDebit) ?></td>
        <td class="num"><?= Accounting::money($sumCredit) ?></td>
        <td colspan="3"></td></tr>
    </tfoot>
  </table>

  <?php if ($totalPages > 1): ?>
  <div class="dt-pager no-print" style="margin-top:14px;">
    <span class="dt-info">Page <?= $page ?> / <?= $totalPages ?></span>
    <span class="pages">
      <?php for ($p = 1; $p <= $totalPages; $p++):
          $qs = http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY));
      ?>
        <a class="btn small <?= $p === $page ? '' : 'secondary' ?>" href="?<?= $qs ?>&page=<?= $p ?>"><?= $p ?></a>
      <?php endfor; ?>
    </span>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
