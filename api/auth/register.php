<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Méthode non autorisée', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$phone       = trim($input['phone'] ?? '');
$password    = $input['password'] ?? '';
$fullName    = trim($input['full_name'] ?? '');
$isAnonymous = (bool)($input['is_anonymous'] ?? false);

if ($phone === '' || strlen($password) < 6) {
    Response::error('Téléphone requis et mot de passe d\'au moins 6 caractères', 422);
}

$pdo = Database::getConnection();

$check = $pdo->prepare('SELECT id FROM users WHERE phone = :phone');
$check->execute(['phone' => $phone]);
if ($check->fetch()) {
    Response::error('Ce numéro de téléphone est déjà enregistré', 409);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    'INSERT INTO users (full_name, phone, password_hash, role, is_anonymous)
     VALUES (:full_name, :phone, :hash, :role, :is_anonymous)
     RETURNING id, full_name, phone, role, points, created_at'
);
$stmt->execute([
    'full_name'    => $fullName ?: null,
    'phone'        => $phone,
    'hash'         => $hash,
    'role'         => 'citizen',
    'is_anonymous' => $isAnonymous,
]);

$user = $stmt->fetch();

Response::success($user, 'Compte créé avec succès', 201);
