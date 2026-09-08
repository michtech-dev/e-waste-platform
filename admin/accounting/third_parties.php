<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Tiers';
$activeNav = 'acc_third_parties';

$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? AccountingThirdParty::find($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'save' && acc_handle(function () use ($id) {
        if ($id) {
            AccountingThirdParty::update($id, $_POST);
            acc_flash_set('Tiers mis à jour.');
        } else {
            AccountingThirdParty::create($_POST);
            acc_flash_set('Tiers créé.');
        }
    })) { header('Location: third_parties.php'); exit; }
    if ($action === 'toggle' && acc_handle(function () use ($pdo, $id) {
        $pdo->prepare('UPDATE acc_third_parties SET is_active = NOT is_active WHERE id = :id')->execute(['id' => $id]);
        acc_flash_set('Statut du tiers mis à jour.');
    })) { header('Location: third_parties.php'); exit; }
    if ($action === 'delete' && acc_handle(function () use ($id) {
        AccountingThirdParty::delete($id);
        acc_flash_set('Tiers supprimé.');
    })) { header('Location: third_parties.php'); exit; }
    $editing = $id ? AccountingThirdParty::find($id) : $editing;
}

$fType = in_array($_GET['type'] ?? '', ['client', 'supplier'], true) ? $_GET['type'] : '';
$fQ    = trim($_GET['q'] ?? '');
$filters = array_filter(['type' => $fType, 'q' => $fQ], fn($v) => $v !== '');

$tiers       = AccountingThirdParty::search($filters + ['is_active' => '']);
$totalCount  = AccountingThirdParty::countSearch($filters + ['is_active' => '']);
$balances    = [];
foreach (AccountingReport::thirdPartyBalances(null, false) as $b) {
    $balances[(int)$b['id']] = $b;
}
$clientAccounts   = AccountingAccount::imputable();
$suggestClient    = AccountingThirdParty::suggestCode('client');
$suggestSupplier  = AccountingThirdParty::suggestCode('supplier');

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_third_parties');
acc_flash();
?>

<div class="card no-print">
  <form method="get" class="form-inline">
    <div class="form-row">
      <label>Recherche</label>
      <input name="q" value="<?= htmlspecialchars($fQ) ?>" placeholder="Code, nom, e-mail…">
    </div>
    <div class="form-row">
      <label>Type</label>
      <select name="type" onchange="this.form.submit()">
        <option value="">Tous</option>
        <option value="client" <?= $fType === 'client' ? 'selected' : '' ?>>Clients</option>
        <option value="supplier" <?= $fType === 'supplier' ? 'selected' : '' ?>>Fournisseurs</option>
      </select>
    </div>
    <button class="btn">Filtrer</button>
    <a class="btn secondary" href="third_parties.php">Réinitialiser</a>
  </form>
</div>

<div class="card">
  <div class="acc-toolbar">
    <h2><?= $editing ? 'Modifier le tiers « ' . htmlspecialchars($editing['code']) . ' »' : 'Nouveau tiers' ?></h2>
    <?php if ($editing): ?><a class="btn secondary small" href="third_parties.php">Annuler</a><?php endif; ?>
  </div>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <div class="entry-head-grid">
      <div class="form-row">
        <label>Code</label>
        <input name="code" maxlength="20" style="text-transform:uppercase;"
               placeholder="<?= htmlspecialchars($suggestClient . ' / ' . $suggestSupplier) ?>"
               value="<?= htmlspecialchars($editing['code'] ?? '') ?>">
      </div>
      <div class="form-row" style="grid-column: span 2;">
        <label>Nom / Raison sociale *</label>
        <input name="name" required maxlength="160" value="<?= htmlspecialchars($editing['name'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Type *</label>
        <select name="type">
          <?php foreach (AccountingThirdParty::TYPES as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= ($editing['type'] ?? 'client') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row" style="grid-column: span 2;">
        <label>Compte collectif (401000 / 411000)</label>
        <select name="account_id">
          <option value="">—</option>
          <?php foreach ($clientAccounts as $a): ?>
          <option value="<?= $a['id'] ?>" <?= (int)($editing['account_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($a['number'] . ' — ' . $a['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>N° TVA</label>
        <input name="vat_number" maxlength="30" value="<?= htmlspecialchars($editing['vat_number'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Téléphone</label>
        <input name="phone" maxlength="25" value="<?= htmlspecialchars($editing['phone'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>E-mail</label>
        <input type="email" name="email" maxlength="150" value="<?= htmlspecialchars($editing['email'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Ville</label>
        <input name="city" maxlength="80" value="<?= htmlspecialchars($editing['city'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Pays</label>
        <input name="country" maxlength="60" value="<?= htmlspecialchars($editing['country'] ?? 'Burundi') ?>">
      </div>
      <div class="form-row">
        <label>Délai règlement (jours)</label>
        <input type="number" name="payment_terms" min="0" max="365" value="<?= (int)($editing['payment_terms'] ?? 30) ?>">
      </div>
      <div class="form-row" style="grid-column: span 3;">
        <label>Adresse</label>
        <textarea name="address" rows="2"><?= htmlspecialchars($editing['address'] ?? '') ?></textarea>
      </div>
    </div>
    <button class="btn"><?= $editing ? 'Enregistrer' : 'Créer le tiers' ?></button>
  </form>
</div>

<div class="card">
  <div class="acc-toolbar">
    <h2>Tiers <span style="color:var(--ink-soft); font-weight:400;">(<?= $totalCount ?>)</span></h2>
    <div class="acc-actions no-print">
      <a class="btn secondary small" href="export.php?type=third_parties<?= $fType ? '&filter_type=' . $fType : '' ?>">Export CSV</a>
      <a class="btn secondary small" href="#" onclick="window.print(); return false;">Imprimer</a>
    </div>
  </div>
  <table class="datatable" data-per-page="15">
    <thead>
      <tr><th>Code</th><th>Nom</th><th>Type</th><th>Cpte collectif</th><th>Ville</th><th>Téléphone</th><th class="num">Solde</th><th>Actif</th><th class="no-print">Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($tiers as $t): $b = $balances[(int)$t['id']] ?? null; ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($t['code']) ?></td>
        <td><?= htmlspecialchars($t['name']) ?></td>
        <td><span class="pill <?= $t['type'] ?>"><?= AccountingThirdParty::TYPES[$t['type']] ?></span></td>
        <td class="mono"><?= htmlspecialchars($t['account_number'] ?? '—') ?></td>
        <td><?= htmlspecialchars($t['city'] ?: '—') ?></td>
        <td><?= htmlspecialchars($t['phone'] ?: '—') ?></td>
        <td class="num"><?= $b && ($b['debit'] || $b['credit']) ? Accounting::signedBalance((float)$b['balance']) : '—' ?></td>
        <td><span class="pill <?= $t['is_active'] ? 'validated' : 'draft' ?>"><?= $t['is_active'] ? 'Oui' : 'Non' ?></span></td>
        <td class="no-print" style="white-space:nowrap;">
          <a class="btn small secondary" href="?edit=<?= (int)$t['id'] ?>">Modifier</a>
          <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce tiers ?');">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn small danger">Supprimer</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
