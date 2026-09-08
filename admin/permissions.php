<?php
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Permissions";
$activeNav = 'permissions';
$message = null; $error = null;

$authorities = $pdo->query('SELECT id, name FROM authorities ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_role') {
    $id          = (int)($_POST['id'] ?? 0);
    $newRole     = $_POST['role'] ?? '';
    $authorityId = $_POST['authority_id'] !== '' ? (int)$_POST['authority_id'] : null;

    if (!in_array($newRole, ['citizen', 'authority_agent', 'admin'], true)) {
        $error = 'Rôle invalide.';
    } elseif ($id === (int)$currentAdmin['id'] && $newRole !== 'admin') {
        $error = 'Tu ne peux pas retirer tes propres droits administrateur.';
    } elseif ($newRole === 'authority_agent' && !$authorityId) {
        $error = 'Une autorité doit être choisie pour le rôle "agent d\'autorité".';
    } else {
        $stmt = $pdo->prepare(
            'UPDATE users SET role = :role, authority_id = :aid WHERE id = :id'
        );
        $stmt->execute([
            'role' => $newRole,
            'aid'  => $newRole === 'authority_agent' ? $authorityId : null,
            'id'   => $id,
        ]);
        $message = 'Permissions mises à jour.';
    }
}

$search = trim($_GET['q'] ?? '');
$where = '1=1'; $params = [];
if ($search !== '') {
    $where = '(phone ILIKE :q OR full_name ILIKE :q)';
    $params['q'] = "%{$search}%";
}
$sql = "SELECT id, full_name, phone, role, authority_id, status FROM users WHERE {$where} ORDER BY role, full_name LIMIT 50";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->execute();
$allUsers = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <p style="margin-top:0; color:var(--ink-soft);">
    Change le niveau d'accès de n'importe quel compte. Trois rôles existent :
    <strong>citoyen</strong> (usage standard), <strong>agent d'autorité</strong> (traite les signalements
    d'une autorité précise) et <strong>administrateur</strong> (accès complet au back-office).
  </p>
  <form method="get">
    <div class="form-inline">
      <div class="form-row">
        <label>Rechercher un compte</label>
        <input type="text" name="q" placeholder="Nom ou téléphone" value="<?= htmlspecialchars($search) ?>">
      </div>
      <div><button type="submit" class="btn secondary">Rechercher</button></div>
    </div>
  </form>
</div>

<div class="card">
  <table>
    <thead><tr><th>Nom</th><th>Téléphone</th><th>Rôle actuel</th><th colspan="3">Modifier le rôle</th></tr></thead>
    <tbody>
      <?php foreach ($allUsers as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['full_name'] ?: '—') ?></td>
        <td class="mono"><?= htmlspecialchars($u['phone']) ?></td>
        <td><span class="pill <?= $u['role'] ?>"><?= $u['role'] ?></span></td>
        <td colspan="3">
          <form method="post" style="display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="action" value="change_role">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <select name="role" style="flex:1;">
              <?php foreach (['citizen' => 'Citoyen', 'authority_agent' => 'Agent d\'autorité', 'admin' => 'Administrateur'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= $u['role'] === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <select name="authority_id" style="flex:1;">
              <option value="">Autorité (si agent) —</option>
              <?php foreach ($authorities as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $u['authority_id'] == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn small">Appliquer</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
