<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Méthode non autorisée', 405);
}

AuthMiddleware::authenticate(); // vérifie que le token est valide

$headers = apache_request_headers();
preg_match('/Bearer\s+(\S+)/', $headers['Authorization'] ?? '', $matches);
$token = $matches[1] ?? '';

$pdo = Database::getConnection();
$stmt = $pdo->prepare('DELETE FROM auth_tokens WHERE token = :token');
$stmt->execute(['token' => $token]);

Response::success(null, 'Déconnexion réussie');
