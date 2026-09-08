<?php
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Autorités";
$activeNav = 'authorities';
$message = null; $error = null;

// ---- Création ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name  = trim($_POST['name'] ?? '');
    $type  = $_POST['type'] ?? 'municipal';
    $zone  = trim($_POST['zone_covered'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    $phone = trim($_POST['contact_phone'] ?? '');

    if ($name === '' || !in_array($type, ['municipal', 'police', 'ong'], true)) {
        $error = 'Nom et type valides requis.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO authorities (name, type, zone_covered, contact_email, contact_phone)
             VALUES (:name, :type, :zone, :email, :phone)'
        );
        $stmt->execute(['name' => $name, 'type' => $type, 'zone' => $zone ?: null,
                         'email' => $email ?: null, 'phone' => $phone ?: null]);
        $message = 'Autorité créée avec succès.';
    }
}

// ---- Modification ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $id    = (int)($_POST['id'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $type  = $_POST['type'] ?? 'municipal';
    $zone  = trim($_POST['zone_covered'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    $phone = trim($_POST['contact_phone'] ?? '');

    if ($id && $name !== '') {
        $stmt = $pdo->prepare(
            'UPDATE authorities SET name = :name, type = :type, zone_covered = :zone,
             contact_email = :email, contact_phone = :phone WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'type' => $type, 'zone' => $zone ?: null,
                         'email' => $email ?: null, 'phone' => $phone ?: null, 'id' => $id]);
        $message = 'Autorité mise à jour.';
    }
}

$authorities = $pdo->query(
    "SELECT a.*, COUNT(r.id) AS total_reports
     FROM authorities a
     LEFT JOIN reports r ON r.authority_id = a.id
     GROUP BY a.id ORDER BY a.id"
)->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM authorities WHERE id = :id');
    $stmt->execute(['id' => (int)$_GET['edit']]);
    $editing = $stmt->fetch();
}

require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;"><?= $editing ? 'Modifier l\'autorité' : 'Ajouter une autorité' ?></h2>
  <form method="post">
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <div class="form-inline">
      <div class="form-row">
        <label>Nom</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($editing['name'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Type</label>
        <select name="type">
          <?php foreach (['municipal' => 'Mairie / Municipalité', 'police' => 'Police', 'ong' => 'ONG'] as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($editing['type'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>Zone couverte</label>
        <input type="text" name="zone_covered" value="<?= htmlspecialchars($editing['zone_covered'] ?? '') ?>" placeholder="ex: Centre-ville">
      </div>
    </div>
    <div class="form-inline" style="margin-top:14px;">
      <div class="form-row">
        <label>Email de contact</label>
        <input type="email" name="contact_email" value="<?= htmlspecialchars($editing['contact_email'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Téléphone de contact</label>
        <input type="text" name="contact_phone" value="<?= htmlspecialchars($editing['contact_phone'] ?? '') ?>">
      </div>
    </div>
    <div style="margin-top:16px;">
      <button type="submit" class="btn"><?= $editing ? 'Enregistrer les modifications' : 'Créer l\'autorité' ?></button>
      <?php if ($editing): ?><a href="authorities.php" class="btn secondary">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;">Autorités enregistrées</h2>
  <?php if (empty($authorities)): ?>
    <p class="empty">Aucune autorité enregistrée pour le moment.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Nom</th><th>Type</th><th>Zone</th><th>Contact</th><th>Signalements</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($authorities as $a): ?>
      <tr>
        <td><?= htmlspecialchars($a['name']) ?></td>
        <td><?= htmlspecialchars($a['type']) ?></td>
        <td><?= htmlspecialchars($a['zone_covered'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['contact_email'] ?? $a['contact_phone'] ?? '—') ?></td>
        <td class="mono"><?= $a['total_reports'] ?></td>
        <td><a href="authorities.php?edit=<?= $a['id'] ?>" class="btn small secondary">Modifier</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
