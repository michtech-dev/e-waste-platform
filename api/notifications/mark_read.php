<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Méthode non autorisée', 405);
}

$user = AuthMiddleware::authenticate();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$notifId = (int)($input['notification_id'] ?? 0);

if (!$notifId) {
    Response::error('Identifiant de notification requis', 422);
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare(
    'UPDATE notifications SET is_read = TRUE
     WHERE id = :id AND user_id = :uid'
);
$stmt->execute(['id' => $notifId, 'uid' => $user['id']]);

Response::success(null, 'Notification marquée comme lue');
