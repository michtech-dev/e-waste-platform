<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Méthode non autorisée', 405);
}

$user = AuthMiddleware::authenticate();
$pdo  = Database::getConnection();

$statusFilter = $_GET['status'] ?? null;
$categoryFilter = $_GET['category'] ?? null;
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$conditions = [];
$params = [];

if (in_array($user['role'], ['authority_agent', 'admin'], true)) {
    // Une autorité voit tous les signalements qui lui sont adressés
    $conditions[] = 'r.authority_id = :authority_id';
    $params['authority_id'] = $user['authority_id'];
} else {
    // Un citoyen ne voit que ses propres signalements
    $conditions[] = 'r.user_id = :user_id';
    $params['user_id'] = $user['id'];
}

if ($statusFilter && in_array($statusFilter, ['pending', 'in_progress', 'resolved', 'rejected'], true)) {
    $conditions[] = 'r.status = :status';
    $params['status'] = $statusFilter;
}
if ($categoryFilter) {
    $conditions[] = 'r.category = :category';
    $params['category'] = $categoryFilter;
}

$where = implode(' AND ', $conditions);

$sql = "SELECT r.id, r.category, r.description, r.latitude, r.longitude, r.severity, r.status,
               r.is_anonymous, r.created_at, r.resolved_at,
               CASE WHEN r.is_anonymous THEN NULL ELSE u.full_name END AS reporter_name
        FROM reports r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE {$where}
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reports = $stmt->fetchAll();

// Attache les médias de chaque signalement
foreach ($reports as &$r) {
    $mediaStmt = $pdo->prepare('SELECT media_url, media_type FROM report_media WHERE report_id = :id');
    $mediaStmt->execute(['id' => $r['id']]);
    $r['media'] = $mediaStmt->fetchAll();
}

Response::success(['reports' => $reports, 'page' => $page], 'Liste récupérée');
