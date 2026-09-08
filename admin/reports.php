<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../helpers/Categories.php';

$pageTitle = "Signalements";
$activeNav = 'reports';
$message = null;

// ---- Forcer un changement de statut (contournement admin) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_status') {
    $id = (int)($_POST['id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    if ($id && in_array($newStatus, ['pending', 'in_progress', 'resolved', 'rejected'], true)) {
        $stmt = $pdo->prepare('SELECT status, user_id FROM reports WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $old = $stmt->fetch();

        $pdo->beginTransaction();
        $upd = $pdo->prepare(
            "UPDATE reports SET status = :s,
             resolved_at = CASE WHEN :s2 = 'resolved' THEN NOW() ELSE resolved_at END WHERE id = :id"
        );
        $upd->execute(['s' => $newStatus, 's2' => $newStatus, 'id' => $id]);

        $hist = $pdo->prepare(
            'INSERT INTO report_status_history (report_id, old_status, new_status, changed_by, note)
             VALUES (:rid, :old, :new, :by, :note)'
        );
        $hist->execute(['rid' => $id, 'old' => $old['status'], 'new' => $newStatus,
                         'by' => $currentAdmin['id'], 'note' => 'Modifié manuellement par un administrateur']);
        $pdo->commit();
        $message = "Signalement #{$id} mis à jour.";
    }
}

$statusFilter = $_GET['status'] ?? '';
$authorityFilter = $_GET['authority_id'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20; $offset = ($page - 1) * $limit;

$conditions = ['1=1']; $params = [];
if (in_array($statusFilter, ['pending', 'in_progress', 'resolved', 'rejected'], true)) {
    $conditions[] = 'r.status = :status';
    $params['status'] = $statusFilter;
}
if ($authorityFilter !== '') {
    $conditions[] = 'r.authority_id = :aid';
    $params['aid'] = (int)$authorityFilter;
}
if ($categoryFilter !== '' && Categories::isValid($categoryFilter)) {
    $conditions[] = 'r.category = :category';
    $params['category'] = $categoryFilter;
}
$where = implode(' AND ', $conditions);

$sql = "SELECT r.id, r.category, r.description, r.severity, r.status, r.is_anonymous, r.created_at,
               COALESCE(u.full_name, 'Anonyme') AS reporter, a.name AS authority_name
        FROM reports r
        LEFT JOIN users u ON u.id = r.user_id AND r.is_anonymous = FALSE
        LEFT JOIN authorities a ON a.id = r.authority_id
        WHERE {$where}
        ORDER BY r.created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reports = $stmt->fetchAll();

$authorities = $pdo->query('SELECT id, name FROM authorities ORDER BY name')->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="filters">
  <?php
  $statuses = ['' => 'Tous', 'pending' => 'En attente', 'in_progress' => 'En cours', 'resolved' => 'Résolus', 'rejected' => 'Rejetés'];
  foreach ($statuses as $val => $label):
    $qs = http_build_query(array_filter(['status' => $val, 'authority_id' => $authorityFilter, 'category' => $categoryFilter]));
  ?>
    <a href="?<?= $qs ?>" class="<?= $statusFilter === $val ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="filters">
  <?php
  $catOptions = ['' => 'Toutes les catégories'] + array_combine(Categories::codes(), array_map(['Categories','label'], Categories::codes()));
  foreach ($catOptions as $val => $label):
    $qs = http_build_query(array_filter(['status' => $statusFilter, 'authority_id' => $authorityFilter, 'category' => $val]));
  ?>
    <a href="?<?= $qs ?>" class="<?= $categoryFilter === $val ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <form method="get" class="form-inline" style="margin-bottom:0;">
    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
    <div class="form-row">
      <label>Filtrer par autorité</label>
      <select name="authority_id" onchange="this.form.submit()">
        <option value="">Toutes les autorités</option>
        <?php foreach ($authorities as $a): ?>
          <option value="<?= $a['id'] ?>" <?= $authorityFilter == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<div class="card">
  <?php if (empty($reports)): ?>
    <p class="empty">Aucun signalement ne correspond à ces filtres.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>ID</th><th>Catégorie</th><th>Description</th><th>Citoyen</th><th>Autorité</th><th>Gravité</th><th>Date</th><th>Statut</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach ($reports as $r): ?>
      <tr>
        <td class="mono">#<?= $r['id'] ?></td>
        <td><span class="pill cat"><?= htmlspecialchars(Categories::label($r['category'])) ?></span></td>
        <td style="max-width:220px;"><?= htmlspecialchars(mb_strimwidth($r['description'] ?? '—', 0, 60, '…')) ?></td>
        <td><?= htmlspecialchars($r['reporter']) ?></td>
        <td><?= htmlspecialchars($r['authority_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($r['severity']) ?></td>
        <td class="mono"><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
        <td><span class="pill <?= $r['status'] ?>"><?= $r['status'] ?></span></td>
        <td>
          <form method="post" style="display:flex; gap:6px;">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <select name="status" onchange="this.form.submit()">
              <?php foreach (['pending','in_progress','resolved','rejected'] as $s): ?>
                <option value="<?= $s ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div style="margin-top:14px; display:flex; gap:10px;">
    <?php
    $baseQs = ['status' => $statusFilter, 'authority_id' => $authorityFilter, 'category' => $categoryFilter];
    ?>
    <?php if ($page > 1): ?><a class="btn small secondary" href="?<?= http_build_query(array_merge($baseQs, ['page' => $page-1])) ?>">← Précédent</a><?php endif; ?>
    <?php if (count($reports) === $limit): ?><a class="btn small secondary" href="?<?= http_build_query(array_merge($baseQs, ['page' => $page+1])) ?>">Suivant →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
