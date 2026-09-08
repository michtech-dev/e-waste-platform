<?php
// Attend que $pageTitle, $activeNav et éventuellement $navPrefix soient définis avant l'inclusion
$activeNav = $activeNav ?? '';
$pageTitle = $pageTitle ?? 'Administration';
$navPrefix = $navPrefix ?? ''; // '' depuis admin/, '../' depuis admin/accounting/
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — Administration</title>
<link rel="stylesheet" href="<?= $navPrefix ?>assets/style.css">
<?php if (!empty($loadAccountingAssets)): ?>
<link rel="stylesheet" href="assets/accounting.css">
<?php endif; ?>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">Déchets<span>Ville</span> · Admin</div>
    <nav>
      <a href="<?= $navPrefix ?>index.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>">Vue d'ensemble</a>
      <a href="<?= $navPrefix ?>reports.php" class="<?= $activeNav === 'reports' ? 'active' : '' ?>">Signalements</a>
      <a href="<?= $navPrefix ?>authorities.php" class="<?= $activeNav === 'authorities' ? 'active' : '' ?>">Autorités</a>
      <a href="<?= $navPrefix ?>divisions.php" class="<?= $activeNav === 'divisions' ? 'active' : '' ?>">Divisions admin.</a>
      <a href="<?= $navPrefix ?>agents.php" class="<?= $activeNav === 'agents' ? 'active' : '' ?>">Agents</a>
      <a href="<?= $navPrefix ?>users.php" class="<?= $activeNav === 'users' ? 'active' : '' ?>">Citoyens</a>
      <a href="<?= $navPrefix ?>permissions.php" class="<?= $activeNav === 'permissions' ? 'active' : '' ?>">Permissions</a>
      <a href="<?= $navPrefix ?>notifications.php" class="<?= $activeNav === 'notifications' ? 'active' : '' ?>">Notifications</a>
      <?php
      $isAccSection = str_starts_with($activeNav, 'acc_');
      ?>
      <div class="nav-group-title">Comptabilité</div>
      <a href="<?= $navPrefix ?>accounting/index.php" class="sub <?= $activeNav === 'acc_dashboard' ? 'active' : '' ?>">Tableau de bord</a>
      <a href="<?= $navPrefix ?>accounting/entries.php" class="sub <?= in_array($activeNav, ['acc_entries', 'acc_entry_form'], true) ? 'active' : '' ?>">Écritures &amp; saisie</a>
      <a href="<?= $navPrefix ?>accounting/ledger.php" class="sub <?= in_array($activeNav, ['acc_ledger', 'acc_balance', 'acc_gjournal'], true) ? 'active' : '' ?>">États comptables</a>
      <a href="<?= $navPrefix ?>accounting/accounts.php" class="sub <?= in_array($activeNav, ['acc_accounts', 'acc_journals', 'acc_third_parties', 'acc_periods'], true) ? 'active' : '' ?>">Paramètres</a>
    </nav>
    <div class="logout">
      <a href="<?= $navPrefix ?>logout.php">Se déconnecter</a>
    </div>
  </aside>
  <div class="main">
    <div class="topbar">
      <h1><?= htmlspecialchars($pageTitle) ?></h1>
      <div class="who">Connecté : <?= htmlspecialchars($currentAdmin['full_name'] ?? $currentAdmin['phone']) ?></div>
    </div>
    <div class="content">
