<?php
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Notifications";
$activeNav = 'notifications';

$totalSent = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$totalUnread = (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = FALSE')->fetchColumn();
$readRate = $totalSent > 0 ? round((($totalSent - $totalUnread) / $totalSent) * 100, 1) : 0;

$filter = $_GET['filter'] ?? '';
$where = '1=1';
if ($filter === 'unread') $where = 'n.is_read = FALSE';
if ($filter === 'read') $where = 'n.is_read = TRUE';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 30; $offset = ($page - 1) * $limit;

$stmt = $pdo->prepare(
    "SELECT n.id, n.message, n.is_read, n.created_at, n.report_id,
            u.full_name, u.phone
     FROM notifications n
     JOIN users u ON u.id = n.user_id
     WHERE {$where}
     ORDER BY n.created_at DESC LIMIT :limit OFFSET :offset"
);
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div class="grid-stats">
  <div class="stat">
    <div class="value"><?= $totalSent ?></div>
    <div class="label">Notifications envoyées</div>
  </div>
  <div class="stat amber">
    <div class="value"><?= $totalUnread ?></div>
    <div class="label">Non lues</div>
  </div>
  <div class="stat accent">
    <div class="value"><?= $readRate ?>%</div>
    <div class="label">Taux de lecture</div>
  </div>
</div>

<div class="filters">
  <a href="?filter=" class="<?= $filter === '' ? 'active' : '' ?>">Toutes</a>
  <a href="?filter=unread" class="<?= $filter === 'unread' ? 'active' : '' ?>">Non lues</a>
  <a href="?filter=read" class="<?= $filter === 'read' ? 'active' : '' ?>">Lues</a>
</div>

<div class="card">
  <?php if (empty($notifications)): ?>
    <p class="empty">Aucune notification à afficher.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Destinataire</th><th>Message</th><th>Signalement</th><th>Statut</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach ($notifications as $n): ?>
      <tr>
        <td><?= htmlspecialchars($n['full_name'] ?: $n['phone']) ?></td>
        <td><?= htmlspecialchars($n['message']) ?></td>
        <td class="mono">
          <?php if ($n['report_id']): ?>
            <a href="reports.php?q=<?= $n['report_id'] ?>">#<?= $n['report_id'] ?></a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td><span class="pill <?= $n['is_read'] ? 'resolved' : 'pending' ?>"><?= $n['is_read'] ? 'lue' : 'non lue' ?></span></td>
        <td class="mono"><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div style="margin-top:14px; display:flex; gap:10px;">
    <?php if ($page > 1): ?><a class="btn small secondary" href="?filter=<?= $filter ?>&page=<?= $page-1 ?>">← Précédent</a><?php endif; ?>
    <?php if (count($notifications) === $limit): ?><a class="btn small secondary" href="?filter=<?= $filter ?>&page=<?= $page+1 ?>">Suivant →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
