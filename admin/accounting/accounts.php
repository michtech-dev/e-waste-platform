<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Plan comptable';
$activeNav = 'acc_accounts';

$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? AccountingAccount::find($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'save' && acc_handle(function () use ($id) {
        if ($id) {
            AccountingAccount::update($id, $_POST);
            acc_flash_set('Compte mis à jour.');
        } else {
            AccountingAccount::create($_POST);
            acc_flash_set('Compte créé dans le plan comptable.');
        }
    })) { header('Location: accounts.php' . (!empty($_POST['back_class']) ? '?class=' . (int)$_POST['back_class'] : '')); exit; }
    if ($action === 'toggle' && acc_handle(function () use ($pdo, $id) {
        $pdo->prepare('UPDATE acc_accounts SET is_active = NOT is_active WHERE id = :id')->execute(['id' => $id]);
        acc_flash_set('Statut du compte mis à jour.');
    })) { header('Location: accounts.php'); exit; }
    if ($action === 'delete' && acc_handle(function () use ($id) {
        AccountingAccount::delete($id);
        acc_flash_set('Compte supprimé.');
    })) { header('Location: accounts.php'); exit; }
    $editing = $id ? AccountingAccount::find($id) : $editing;
}

// Filtres GET
$fClass  = (int)($_GET['class'] ?? 0);
$fType   = $_GET['type'] ?? '';
$fQ      = trim($_GET['q'] ?? '');
$filters = array_filter(['class' => $fClass, 'type' => $fType, 'q' => $fQ], fn($v) => $v !== '' && $v !== 0);

$accounts   = AccountingAccount::search($filters);
$totalCount = AccountingAccount::countSearch($filters);
$imputable  = AccountingAccount::imputable();

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_accounts');
acc_flash();
?>

<div class="card no-print">
  <form method="get" class="form-inline">
    <div class="form-row">
      <label>Recherche</label>
      <input name="q" value="<?= htmlspecialchars($fQ) ?>" placeholder="N° ou libellé…">
    </div>
    <div class="form-row">
      <label>Classe</label>
      <select name="class" onchange="this.form.submit()">
        <option value="">Toutes</option>
        <?php foreach (AccountingAccount::CLASS_LABELS as $c => $lbl): ?>
        <option value="<?= $c ?>" <?= $fClass === $c ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label>Type</label>
      <select name="type" onchange="this.form.submit()">
        <option value="">Tous</option>
        <?php foreach (AccountingAccount::TYPES as $t): ?>
        <option value="<?= $t ?>" <?= $fType === $t ? 'selected' : '' ?>><?= Accounting::accountTypeLabel($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn">Filtrer</button>
    <a class="btn secondary" href="accounts.php">Réinitialiser</a>
  </form>
</div>

<div class="card">
  <div class="acc-toolbar">
    <h2><?= $editing ? 'Modifier le compte « ' . htmlspecialchars($editing['number']) . ' »' : 'Nouveau compte' ?></h2>
    <?php if ($editing): ?><a class="btn secondary small" href="accounts.php">Annuler</a><?php endif; ?>
  </div>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <input type="hidden" name="back_class" value="<?= $fClass ?>">
    <div class="entry-head-grid">
      <div class="form-row">
        <label>N° de compte *</label>
        <input name="number" required pattern="[0-9]{3,20}" maxlength="20" placeholder="ex. 411000"
               style="font-family:var(--font-mono);" value="<?= htmlspecialchars($editing['number'] ?? '') ?>">
      </div>
      <div class="form-row" style="grid-column: span 3;">
        <label>Libellé *</label>
        <input name="label" required maxlength="160" placeholder="ex. Clients"
               value="<?= htmlspecialchars($editing['label'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Type</label>
        <select name="type">
          <?php foreach (AccountingAccount::TYPES as $t): ?>
          <option value="<?= $t ?>" <?= ($editing['type'] ?? 'general') === $t ? 'selected' : '' ?>><?= Accounting::accountTypeLabel($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>Compte parent (rubrique)</label>
        <select name="parent_number">
          <option value="">—</option>
          <?php foreach (AccountingAccount::search() as $a): ?>
            <?php if (!$a['is_header']) continue; ?>
          <option value="<?= htmlspecialchars($a['number']) ?>" <?= ($editing['parent_number'] ?? '') === $a['number'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($a['number'] . ' — ' . $a['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row" style="display:flex; align-items:flex-end; gap:18px;">
        <label style="display:flex; gap:7px; align-items:center; font-size:13px;">
          <input type="checkbox" name="is_header" value="1" <?= !empty($editing['is_header']) ? 'checked' : '' ?>>
          Rubrique non imputable
        </label>
        <label style="display:flex; gap:7px; align-items:center; font-size:13px;">
          <input type="hidden" name="is_active" value="0">
          <input type="checkbox" name="is_active" value="1" <?= !isset($editing) || !empty($editing['is_active']) ? 'checked' : '' ?>>
          Actif
        </label>
      </div>
    </div>
    <button class="btn"><?= $editing ? 'Enregistrer' : 'Créer le compte' ?></button>
  </form>
</div>

<div class="card">
  <div class="acc-toolbar">
    <h2>Plan comptable général <span style="color:var(--ink-soft); font-weight:400;">(<?= $totalCount ?> comptes)</span></h2>
    <div class="acc-actions no-print">
      <a class="btn secondary small" href="export.php?type=accounts<?= $fClass ? '&class=' . $fClass : '' ?>">Export CSV</a>
      <a class="btn secondary small" href="#" onclick="window.print(); return false;">Imprimer</a>
    </div>
  </div>
  <table class="datatable" data-per-page="15">
    <thead>
      <tr><th>N° compte</th><th>Libellé</th><th>Classe</th><th>Type</th><th>Parent</th><th>Rubrique</th><th>Actif</th><th class="no-print">Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($accounts as $a): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($a['number']) ?></td>
        <td><?= htmlspecialchars($a['label']) ?></td>
        <td><span class="badge-class"><?= $a['class'] ?></span></td>
        <td><?= Accounting::accountTypeLabel($a['type']) ?></td>
        <td class="mono"><?= htmlspecialchars($a['parent_number'] ?? '') ?></td>
        <td><?= $a['is_header'] ? 'Oui' : '—' ?></td>
        <td><span class="pill <?= $a['is_active'] ? 'validated' : 'draft' ?>"><?= $a['is_active'] ? 'Oui' : 'Non' ?></span></td>
        <td class="no-print" style="white-space:nowrap;">
          <a class="btn small secondary" href="?edit=<?= (int)$a['id'] ?><?= $fClass ? '&class=' . $fClass : '' ?>">Modifier</a>
          <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce compte ?');">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="btn small danger">Supprimer</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
