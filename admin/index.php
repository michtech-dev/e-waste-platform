<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../helpers/Categories.php';

$pageTitle = "Vue d'ensemble";
$activeNav = 'dashboard';

// Répartition globale des signalements par statut
$byStatus = $pdo->query("SELECT status, COUNT(*) AS total FROM reports GROUP BY status")->fetchAll();
$statusCounts = ['pending' => 0, 'in_progress' => 0, 'resolved' => 0, 'rejected' => 0];
foreach ($byStatus as $row) { $statusCounts[$row['status']] = (int)$row['total']; }

$totalUsers      = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'citizen'")->fetchColumn();
$totalAgents     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'authority_agent'")->fetchColumn();
$totalAuthorities= (int)$pdo->query("SELECT COUNT(*) FROM authorities")->fetchColumn();
$suspended       = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn();

$byCategory = $pdo->query("SELECT category, COUNT(*) AS total FROM reports GROUP BY category ORDER BY total DESC")->fetchAll();

$recent = $pdo->query(
    "SELECT r.id, r.category, r.status, r.severity, r.created_at,
            COALESCE(u.full_name, 'Anonyme') AS reporter, a.name AS authority_name
     FROM reports r
     LEFT JOIN users u ON u.id = r.user_id AND r.is_anonymous = FALSE
     LEFT JOIN authorities a ON a.id = r.authority_id
     ORDER BY r.created_at DESC LIMIT 8"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div class="grid-stats">
  <div class="stat amber">
    <div class="value"><?= $statusCounts['pending'] ?></div>
    <div class="label">En attente</div>
  </div>
  <div class="stat blue">
    <div class="value"><?= $statusCounts['in_progress'] ?></div>
    <div class="label">En cours de traitement</div>
  </div>
  <div class="stat accent">
    <div class="value"><?= $statusCounts['resolved'] ?></div>
    <div class="label">Résolus</div>
  </div>
  <div class="stat brick">
    <div class="value"><?= $statusCounts['rejected'] ?></div>
    <div class="label">Rejetés</div>
  </div>
</div>

<div class="grid-stats">
  <div class="stat">
    <div class="value"><?= $totalUsers ?></div>
    <div class="label">Citoyens inscrits</div>
  </div>
  <div class="stat">
    <div class="value"><?= $totalAgents ?></div>
    <div class="label">Agents d'autorité</div>
  </div>
  <div class="stat">
    <div class="value"><?= $totalAuthorities ?></div>
    <div class="label">Autorités enregistrées</div>
  </div>
  <div class="stat">
    <div class="value"><?= $suspended ?></div>
    <div class="label">Comptes suspendus</div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;">Répartition par catégorie</h2>
  <?php if (empty($byCategory)): ?>
    <p class="empty">Aucune donnée pour le moment.</p>
  <?php else:
    $maxCat = max(array_column($byCategory, 'total'));
    foreach ($byCategory as $c):
      $pct = $maxCat > 0 ? round(($c['total'] / $maxCat) * 100) : 0;
  ?>
    <div style="margin-bottom:12px;">
      <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
        <span><?= htmlspecialchars(Categories::label($c['category'])) ?></span>
        <span class="mono"><?= $c['total'] ?></span>
      </div>
      <div style="background:var(--border); border-radius:6px; height:8px;">
        <div style="background:var(--accent); width:<?= $pct ?>%; height:8px; border-radius:6px;"></div>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;">Derniers signalements</h2>
  <?php if (empty($recent)): ?>
    <p class="empty">Aucun signalement pour le moment.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr><th>ID</th><th>Catégorie</th><th>Citoyen</th><th>Autorité</th><th>Gravité</th><th>Statut</th><th>Date</th></tr>
    </thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td class="mono">#<?= $r['id'] ?></td>
        <td><span class="pill cat"><?= htmlspecialchars(Categories::label($r['category'])) ?></span></td>
        <td><?= htmlspecialchars($r['reporter']) ?></td>
        <td><?= htmlspecialchars($r['authority_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($r['severity']) ?></td>
        <td><span class="pill <?= $r['status'] ?>"><?= $r['status'] ?></span></td>
        <td class="mono"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p style="margin-top:14px;"><a href="reports.php">Voir tous les signalements →</a></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
