<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$pdo = Database::getConnection();
$adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$error = '';
$bootstrapMode = ($adminCount === 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($bootstrapMode && isset($_POST['bootstrap'])) {
        // ---- Création du tout premier compte admin (une seule fois) ----
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullName === '' || $phone === '' || strlen($password) < 8) {
            $error = 'Nom, téléphone et mot de passe (8 caractères min.) requis.';
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE phone = :phone');
            $check->execute(['phone' => $phone]);
            if ($check->fetch()) {
                $error = 'Ce numéro est déjà utilisé par un compte existant.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $ins = $pdo->prepare(
                    "INSERT INTO users (full_name, phone, password_hash, role, status)
                     VALUES (:name, :phone, :hash, 'admin', 'active') RETURNING id"
                );
                $ins->execute(['name' => $fullName, 'phone' => $phone, 'hash' => $hash]);
                $_SESSION['admin_id'] = $ins->fetch()['id'];
                header('Location: index.php');
                exit;
            }
        }
    } else {
        // ---- Connexion classique ----
        $phone    = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare('SELECT id, password_hash, role, status FROM users WHERE phone = :phone');
        $stmt->execute(['phone' => $phone]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Identifiants incorrects.';
        } elseif ($user['role'] !== 'admin') {
            $error = 'Ce compte n\'a pas les droits administrateur.';
        } elseif ($user['status'] !== 'active') {
            $error = 'Ce compte est suspendu.';
        } else {
            $_SESSION['admin_id'] = $user['id'];
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administration — Plateforme déchets</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <?php if ($bootstrapMode): ?>
      <h1>Créer le compte administrateur</h1>
      <p>Aucun administrateur n'existe encore. Crée le tout premier compte pour démarrer.</p>
      <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="bootstrap" value="1">
        <div class="form-row">
          <label>Nom complet</label>
          <input type="text" name="full_name" required>
        </div>
        <div class="form-row">
          <label>Téléphone</label>
          <input type="text" name="phone" required>
        </div>
        <div class="form-row">
          <label>Mot de passe (8 caractères min.)</label>
          <input type="password" name="password" required minlength="8">
        </div>
        <button type="submit" class="btn" style="width:100%">Créer le compte</button>
      </form>
    <?php else: ?>
      <h1>Administration</h1>
      <p>Connecte-toi avec ton compte administrateur.</p>
      <?php if (isset($_GET['expired'])): ?>
        <div class="alert error">Session expirée ou droits révoqués.</div>
      <?php endif; ?>
      <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <div class="form-row">
          <label>Téléphone</label>
          <input type="text" name="phone" required autofocus>
        </div>
        <div class="form-row">
          <label>Mot de passe</label>
          <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn" style="width:100%">Se connecter</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
