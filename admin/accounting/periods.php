<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Exercices';
$activeNav = 'acc_periods';

$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? AccountingPeriod::find($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'save' && acc_handle(function () use ($id) {
        if ($id) {
            AccountingPeriod::update($id, $_POST);
            acc_flash_set('Exercice mis à jour.');
        } else {
            AccountingPeriod::create($_POST);
            acc_flash_set('Exercice créé.');
        }
    })) {
        header('Location: periods.php'); exit;
    }
    if ($action === 'toggle' && acc_handle(function () use ($id) {
        $p = AccountingPeriod::find($id);
        if (!$p) throw new RuntimeException('Exercice introuvable.');
        AccountingPeriod::setStatus($id, $p['status'] === 'open' ? 'closed' : 'open');
        acc_flash_set($p['status'] === 'open' ? "Exercice {$p['code']} clôturé." : "Exercice {$p['code']} réouvert.");
    })) { header('Location: periods.php'); exit; }
    if ($action === 'delete' && acc_handle(function () use ($id) {
        AccountingPeriod::delete($id);
        acc_flash_set('Exercice supprimé.');
    })) { header('Location: periods.php'); exit; }
    if ($action === 'closing' && acc_handle(function () use ($id, $currentAdmin) {
        $summary = AccountingPeriod::closeFiscalYear($id, (int)$currentAdmin['id']);
        $msg = sprintf(
            'Exercice clôturé. %s : %s. Exercice %s créé/vérifié.%s',
            $summary['resultat'] >= 0 ? 'Bénéfice' : 'Perte',
            Accounting::money(abs($summary['resultat'])),
            $summary['next_period'],
            $summary['entry_id'] ? " Écriture de clôture #{$summary['entry_id']} enregistrée." : ''
        );
        acc_flash_set($msg);
    })) { header('Location: periods.php'); exit; }
    // En cas d'erreur : repartir du formulaire d'édition
    $editId = $id ?: $editId;
    $editing = $editId ? AccountingPeriod::find($editId) : null;
}

$periods = AccountingPeriod::all();
$entriesPerPeriod = [];
foreach ($pdo->query('SELECT period_id, COUNT(*) AS n FROM acc_entries GROUP BY period_id')->fetchAll() as $r) {
    $entriesPerPeriod[(int)$r['period_id']] = (int)$r['n'];
}

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_periods');
acc_flash();
?>

<div class="card">
  <div class="acc-toolbar">
    <h2><?= $editing ? 'Modifier l\'exercice « ' . htmlspecialchars($editing['code']) . ' »' : 'Nouvel exercice comptable' ?></h2>
    <?php if ($editing): ?><a class="btn secondary small" href="periods.php">Annuler</a><?php endif; ?>
  </div>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <div class="entry-head-grid">
      <div class="form-row">
        <label>Code *</label>
        <input name="code" maxlength="9" placeholder="ex. 2026" value="<?= htmlspecialchars($editing['code'] ?? '') ?>">
      </div>
      <div class="form-row" style="grid-column: span 2;">
        <label>Libellé *</label>
        <input name="label" required maxlength="80" placeholder="ex. Exercice 2026" value="<?= htmlspecialchars($editing['label'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Début *</label>
        <input type="date" name="start_date" required value="<?= htmlspecialchars($editing['start_date'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Fin *</label>
        <input type="date" name="end_date" required value="<?= htmlspecialchars($editing['end_date'] ?? '') ?>">
      </div>
    </div>
    <button class="btn"><?= $editing ? 'Enregistrer' : 'Créer l\'exercice' ?></button>
  </form>
</div>

<div class="card">
  <table class="datatable" data-per-page="15">
    <thead>
      <tr><th>Code</th><th>Libellé</th><th data-type="date">Début</th><th data-type="date">Fin</th><th class="num">Pièces</th><th>Statut</th><th class="no-print">Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($periods as $p): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($p['code']) ?></td>
        <td><?= htmlspecialchars($p['label']) ?></td>
        <td><?= Accounting::dateFr($p['start_date']) ?></td>
        <td><?= Accounting::dateFr($p['end_date']) ?></td>
        <td class="num"><?= $entriesPerPeriod[(int)$p['id']] ?? 0 ?></td>
        <td><span class="pill <?= $p['status'] ?>"><?= $p['status'] === 'open' ? 'Ouvert' : 'Clôturé' ?></span></td>
        <td class="no-print" style="white-space:nowrap;">
          <a class="btn small secondary" href="?edit=<?= (int)$p['id'] ?>">Modifier</a>
          <?php if ($p['status'] === 'open'): ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('Clôturer l\'exercice <?= htmlspecialchars($p['code']) ?> ?\n\nLe résultat (classes 6/7) sera soldé vers le compte 120/129 et l\'exercice suivant sera créé automatiquement.');">
            <input type="hidden" name="action" value="closing"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn small">Clôturer</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn small secondary">Bloquer</button>
          </form>
          <?php else: ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('Réouvrir cet exercice clôturé ?');">
            <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn small secondary">Réouvrir</button>
          </form>
          <?php endif; ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer définitivement cet exercice ?');">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn small danger">Supprimer</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
