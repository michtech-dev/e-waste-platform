<?php
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Citoyens";
$activeNav = 'users';
$message = null;

// ---- Suspendre / réactiver ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare(
        "UPDATE users SET status = CASE WHEN status = 'active' THEN 'suspended' ELSE 'active' END
         WHERE id = :id AND role = 'citizen'"
    );
    $stmt->execute(['id' => $id]);
    $message = 'Statut du compte mis à jour.';
}

$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25; $offset = ($page - 1) * $limit;

$where = "role = 'citizen'";
$params = [];
if ($search !== '') {
    $where .= " AND (phone ILIKE :q OR full_name ILIKE :q)";
    $params['q'] = "%{$search}%";
}

$sql = "SELECT u.id, u.full_name, u.phone, u.status, u.points, u.created_at,
               (SELECT COUNT(*) FROM reports r WHERE r.user_id = u.id) AS report_count
        FROM users u WHERE {$where}
        ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="card">
  <form method="get" class="form-inline">
    <div class="form-row">
      <label>Rechercher</label>
      <input type="text" name="q" placeholder="Nom ou téléphone" value="<?= htmlspecialchars($search) ?>">
    </div>
    <div><button type="submit" class="btn secondary">Rechercher</button></div>
  </form>
</div>

<div class="card">
  <?php if (empty($users)): ?>
    <p class="empty">Aucun citoyen trouvé.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Nom</th><th>Téléphone</th><th>Signalements</th><th>Points</th><th>Inscrit le</th><th>Statut</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['full_name'] ?: '—') ?></td>
        <td class="mono"><?= htmlspecialchars($u['phone']) ?></td>
        <td class="mono"><?= $u['report_count'] ?></td>
        <td class="mono"><?= $u['points'] ?></td>
        <td class="mono"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
        <td><span class="pill <?= $u['status'] ?>"><?= $u['status'] ?></span></td>
        <td>
          <form method="post" onsubmit="return confirm('Confirmer le changement de statut ?');">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button type="submit" class="btn small <?= $u['status'] === 'active' ? 'danger' : 'secondary' ?>">
              <?= $u['status'] === 'active' ? 'Suspendre' : 'Réactiver' ?>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div style="margin-top:14px; display:flex; gap:10px;">
    <?php if ($page > 1): ?><a class="btn small secondary" href="?q=<?= urlencode($search) ?>&page=<?= $page-1 ?>">← Précédent</a><?php endif; ?>
    <?php if (count($users) === $limit): ?><a class="btn small secondary" href="?q=<?= urlencode($search) ?>&page=<?= $page+1 ?>">Suivant →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
