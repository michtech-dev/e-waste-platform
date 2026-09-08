<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Méthode non autorisée', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$phone    = trim($input['phone'] ?? '');
$password = $input['password'] ?? '';
$device   = trim($input['device_info'] ?? 'unknown device');

if ($phone === '' || $password === '') {
    Response::error('Téléphone et mot de passe requis', 422);
}

$pdo = Database::getConnection();

$stmt = $pdo->prepare(
    'SELECT id, full_name, phone, password_hash, role, authority_id, points, status
     FROM users WHERE phone = :phone'
);
$stmt->execute(['phone' => $phone]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    Response::error('Identifiants incorrects', 401);
}
if ($user['status'] !== 'active') {
    Response::error('Compte suspendu, contactez le support', 403);
}

// Génère un token opaque et l'enregistre (valable 30 jours)
$token     = bin2hex(random_bytes(32));
$expiresAt = (new DateTime('+30 days'))->format('Y-m-d H:i:s');

$insert = $pdo->prepare(
    'INSERT INTO auth_tokens (user_id, token, device_info, expires_at)
     VALUES (:user_id, :token, :device_info, :expires_at)'
);
$insert->execute([
    'user_id'     => $user['id'],
    'token'       => $token,
    'device_info' => $device,
    'expires_at'  => $expiresAt,
]);

unset($user['password_hash']);
$user['token'] = $token;

Response::success($user, 'Connexion réussie');
