<?php
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Agents des autorités";
$activeNav = 'agents';
$message = null; $error = null;

$authorities = $pdo->query('SELECT id, name FROM authorities ORDER BY name')->fetchAll();

// ---- Créer un nouvel agent ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_agent') {
    $fullName    = trim($_POST['full_name'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $password    = $_POST['password'] ?? '';
    $authorityId = (int)($_POST['authority_id'] ?? 0);

    if ($fullName === '' || $phone === '' || strlen($password) < 8 || !$authorityId) {
        $error = 'Tous les champs sont requis (mot de passe : 8 caractères min.).';
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE phone = :phone');
        $check->execute(['phone' => $phone]);
        if ($check->fetch()) {
            $error = 'Ce numéro de téléphone est déjà utilisé.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, phone, password_hash, role, authority_id, status)
                 VALUES (:name, :phone, :hash, 'authority_agent', :aid, 'active')"
            );
            $stmt->execute(['name' => $fullName, 'phone' => $phone, 'hash' => $hash, 'aid' => $authorityId]);
            $message = 'Compte agent créé avec succès.';
        }
    }
}

// ---- Révoquer un agent (repasse en citoyen simple) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE users SET role = 'citizen', authority_id = NULL WHERE id = :id AND role = 'authority_agent'");
    $stmt->execute(['id' => $id]);
    $message = 'Accès agent révoqué. Le compte redevient un compte citoyen standard.';
}

// ---- Réassigner à une autre autorité ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign') {
    $id = (int)($_POST['id'] ?? 0);
    $aid = (int)($_POST['authority_id'] ?? 0);
    if ($id && $aid) {
        $stmt = $pdo->prepare("UPDATE users SET authority_id = :aid WHERE id = :id AND role = 'authority_agent'");
        $stmt->execute(['aid' => $aid, 'id' => $id]);
        $message = 'Agent réassigné avec succès.';
    }
}

$agents = $pdo->query(
    "SELECT u.id, u.full_name, u.phone, u.status, u.authority_id, a.name AS authority_name
     FROM users u LEFT JOIN authorities a ON a.id = u.authority_id
     WHERE u.role = 'authority_agent' ORDER BY u.full_name"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;">Créer un compte agent</h2>
  <?php if (empty($authorities)): ?>
    <p class="empty">Crée d'abord une autorité avant de pouvoir lui assigner un agent. <a href="authorities.php">Aller aux autorités →</a></p>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="action" value="create_agent">
    <div class="form-inline">
      <div class="form-row">
        <label>Nom complet</label>
        <input type="text" name="full_name" required>
      </div>
      <div class="form-row">
        <label>Téléphone</label>
        <input type="text" name="phone" required>
      </div>
      <div class="form-row">
        <label>Mot de passe</label>
        <input type="password" name="password" required minlength="8">
      </div>
      <div class="form-row">
        <label>Autorité</label>
        <select name="authority_id" required>
          <option value="">— Choisir —</option>
          <?php foreach ($authorities as $a): ?>
            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="margin-top:16px;"><button type="submit" class="btn">Créer l'agent</button></div>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;">Agents actifs</h2>
  <?php if (empty($agents)): ?>
    <p class="empty">Aucun agent créé pour le moment.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Nom</th><th>Téléphone</th><th>Autorité</th><th>Statut</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($agents as $ag): ?>
      <tr>
        <td><?= htmlspecialchars($ag['full_name']) ?></td>
        <td class="mono"><?= htmlspecialchars($ag['phone']) ?></td>
        <td>
          <form method="post" style="display:flex; gap:6px;">
            <input type="hidden" name="action" value="reassign">
            <input type="hidden" name="id" value="<?= $ag['id'] ?>">
            <select name="authority_id" onchange="this.form.submit()">
              <?php foreach ($authorities as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $a['id'] == $ag['authority_id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <td><span class="pill <?= $ag['status'] ?>"><?= $ag['status'] ?></span></td>
        <td>
          <form method="post" onsubmit="return confirm('Révoquer les droits agent de ce compte ?');">
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="id" value="<?= $ag['id'] ?>">
            <button type="submit" class="btn small danger">Révoquer</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
