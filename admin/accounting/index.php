<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Tableau de bord';
$activeNav = 'acc_dashboard';

$period = AccountingPeriod::current();
$periodId = $period['id'] ?? null;
$stats = AccountingReport::dashboardStats($periodId);

$latest = AccountingEntry::search(['status' => ''], 8);
$journalsCount = [];
foreach (AccountingJournal::all() as $j) {
    $journalsCount[$j['type']] = ($journalsCount[$j['type']] ?? 0) + 1;
}

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_dashboard');
acc_flash();
?>

<?php if (!$period): ?>
<div class="alert error">Aucun exercice comptable ouvert. <a href="periods.php">Créer un exercice</a></div>
<?php else: ?>
<p style="margin-top:0;">
  Exercice courant : <b><?= htmlspecialchars($period['code']) ?></b>
  (<?= Accounting::dateFr($period['start_date']) ?> → <?= Accounting::dateFr($period['end_date']) ?>)
  — <span class="pill <?= $period['status'] ?>"><?= $period['status'] === 'open' ? 'Ouvert' : 'Clôturé' ?></span>
</p>

<div class="grid-stats">
  <div class="stat accent">
    <div class="value"><?= Accounting::money($stats['total_debit']) ?></div>
    <div class="label">Total débits validés (exercice)</div>
  </div>
  <div class="stat amber">
    <div class="value"><?= Accounting::money($stats['total_credit']) ?></div>
    <div class="label">Total crédits validés (exercice)</div>
  </div>
  <div class="stat blue">
    <div class="value"><?= $stats['entries_validated'] ?></div>
    <div class="label">Pièces validées</div>
  </div>
  <div class="stat brick">
    <div class="value"><?= $stats['entries_draft'] ?></div>
    <div class="label">Brouillons en attente</div>
  </div>
</div>

<div class="grid-stats">
  <div class="stat"><div class="value"><?= $stats['accounts'] ?></div><div class="label">Comptes du plan comptable</div></div>
  <div class="stat"><div class="value"><?= $stats['third_parties'] ?></div><div class="label">Tiers enregistrés</div></div>
  <div class="stat"><div class="value"><?= count(AccountingJournal::all()) ?></div><div class="label">Journaux ouverts</div></div>
  <div class="stat"><div class="value"><?= $stats['entries_total'] ?></div><div class="label">Pièces au total</div></div>
</div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;">Accès rapides</h2>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="entry_form.php">＋ Nouvelle écriture</a>
    <a class="btn secondary" href="balance.php">Balance générale</a>
    <a class="btn secondary" href="ledger.php">Grand livre</a>
    <a class="btn secondary" href="general_journal.php">Journal général</a>
    <a class="btn secondary" href="export.php?type=fec">Export FEC</a>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;">Dernières pièces saisies</h2>
  <?php if (empty($latest)): ?>
    <p class="empty">Aucune écriture pour le moment.</p>
  <?php else: ?>
  <table class="datatable" data-per-page="8">
    <thead>
      <tr><th>Pièce</th><th>Journal</th><th data-type="date">Date</th><th>Libellé</th><th>Tiers</th><th class="num">Débit</th><th class="num">Crédit</th><th>Statut</th></tr>
    </thead>
    <tbody>
      <?php foreach ($latest as $e): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars((string)$e['entry_number']) ?></td>
        <td><span class="pill jtype"><?= htmlspecialchars($e['journal_code']) ?></span></td>
        <td><?= Accounting::dateFr($e['entry_date']) ?></td>
        <td><?= htmlspecialchars(mb_substr((string)$e['label'], 0, 60)) ?></td>
        <td><?= htmlspecialchars($e['third_name'] ?? '—') ?></td>
        <td class="num amount-debit"><?= Accounting::money($e['total_debit']) ?></td>
        <td class="num amount-credit"><?= Accounting::money($e['total_credit']) ?></td>
        <td><span class="pill <?= $e['status'] ?>"><?= Accounting::statusLabel($e['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
