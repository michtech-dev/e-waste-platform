<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Méthode non autorisée', 405);
}

$user = AuthMiddleware::authenticate();
$pdo  = Database::getConnection();

$stmt = $pdo->prepare(
    'SELECT id, report_id, message, is_read, created_at
     FROM notifications WHERE user_id = :uid
     ORDER BY created_at DESC LIMIT 50'
);
$stmt->execute(['uid' => $user['id']]);

Response::success(['notifications' => $stmt->fetchAll()], 'Notifications récupérées');
