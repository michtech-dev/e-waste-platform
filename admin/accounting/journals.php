<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Journaux';
$activeNav = 'acc_journals';

$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? AccountingJournal::find($editId) : null;
$imputable = AccountingAccount::imputable();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'save' && acc_handle(function () use ($id) {
        if ($id) {
            AccountingJournal::update($id, $_POST);
            acc_flash_set('Journal mis à jour.');
        } else {
            AccountingJournal::create($_POST);
            acc_flash_set('Journal créé.');
        }
    })) { header('Location: journals.php'); exit; }
    if ($action === 'toggle' && acc_handle(function () use ($id) {
        $j = AccountingJournal::find($id);
        if (!$j) throw new RuntimeException('Journal introuvable.');
        $pdo->prepare('UPDATE acc_journals SET is_active = NOT is_active WHERE id = :id')
            ->execute(['id' => $id]);
        acc_flash_set('Statut du journal mis à jour.');
    })) { header('Location: journals.php'); exit; }
    if ($action === 'delete' && acc_handle(function () use ($id) {
        AccountingJournal::delete($id);
        acc_flash_set('Journal supprimé.');
    })) { header('Location: journals.php'); exit; }
    $editing = $id ? AccountingJournal::find($id) : $editing;
}

$journals = AccountingJournal::all();
$entriesPerJournal = [];
foreach ($pdo->query('SELECT journal_id, COUNT(*) AS n FROM acc_entries GROUP BY journal_id')->fetchAll() as $r) {
    $entriesPerJournal[(int)$r['journal_id']] = (int)$r['n'];
}

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_journals');
acc_flash();
?>

<div class="card">
  <div class="acc-toolbar">
    <h2><?= $editing ? 'Modifier le journal « ' . htmlspecialchars($editing['code']) . ' »' : 'Nouveau journal' ?></h2>
    <?php if ($editing): ?><a class="btn secondary small" href="journals.php">Annuler</a><?php endif; ?>
  </div>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <div class="entry-head-grid">
      <div class="form-row">
        <label>Code *</label>
        <input name="code" maxlength="5" required placeholder="ex. BQ" style="text-transform:uppercase;"
               value="<?= htmlspecialchars($editing['code'] ?? '') ?>">
      </div>
      <div class="form-row" style="grid-column: span 2;">
        <label>Libellé *</label>
        <input name="label" required maxlength="90" placeholder="ex. Journal de banque"
               value="<?= htmlspecialchars($editing['label'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Type *</label>
        <select name="type">
          <?php foreach (AccountingJournal::TYPES as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= ($editing['type'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>Compte débit par défaut</label>
        <select name="default_debit_account">
          <option value="">—</option>
          <?php foreach ($imputable as $a): ?>
          <option value="<?= $a['id'] ?>" <?= (int)($editing['default_debit_account'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($a['number'] . ' — ' . $a['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>Compte crédit par défaut</label>
        <select name="default_credit_account">
          <option value="">—</option>
          <?php foreach ($imputable as $a): ?>
          <option value="<?= $a['id'] ?>" <?= (int)($editing['default_credit_account'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($a['number'] . ' — ' . $a['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn"><?= $editing ? 'Enregistrer' : 'Créer le journal' ?></button>
  </form>
</div>

<div class="card">
  <table class="datatable" data-per-page="15">
    <thead>
      <tr><th>Code</th><th>Libellé</th><th>Type</th><th>Débit déf.</th><th>Crédit déf.</th><th class="num">Pièces</th><th>Actif</th><th class="no-print">Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($journals as $j): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($j['code']) ?></td>
        <td><?= htmlspecialchars($j['label']) ?></td>
        <td><span class="pill jtype"><?= AccountingJournal::TYPES[$j['type']] ?? $j['type'] ?></span></td>
        <td class="mono"><?= htmlspecialchars($j['debit_number'] ?? '—') ?></td>
        <td class="mono"><?= htmlspecialchars($j['credit_number'] ?? '—') ?></td>
        <td class="num"><?= $entriesPerJournal[(int)$j['id']] ?? 0 ?></td>
        <td><span class="pill <?= $j['is_active'] ? 'validated' : 'draft' ?>"><?= $j['is_active'] ? 'Oui' : 'Non' ?></span></td>
        <td class="no-print" style="white-space:nowrap;">
          <a class="btn small secondary" href="?edit=<?= (int)$j['id'] ?>">Modifier</a>
          <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
            <button class="btn small secondary"><?= $j['is_active'] ? 'Désactiver' : 'Activer' ?></button>
          </form>
          <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce journal ?');">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
            <button class="btn small danger">Supprimer</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
