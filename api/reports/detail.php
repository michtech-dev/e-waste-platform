<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Méthode non autorisée', 405);
}

$user = AuthMiddleware::authenticate();
$reportId = (int)($_GET['id'] ?? 0);

if (!$reportId) {
    Response::error('Identifiant du signalement requis', 422);
}

$pdo = Database::getConnection();

$stmt = $pdo->prepare(
    'SELECT r.*, CASE WHEN r.is_anonymous THEN NULL ELSE u.full_name END AS reporter_name,
            a.name AS authority_name
     FROM reports r
     LEFT JOIN users u ON u.id = r.user_id
     LEFT JOIN authorities a ON a.id = r.authority_id
     WHERE r.id = :id'
);
$stmt->execute(['id' => $reportId]);
$report = $stmt->fetch();

if (!$report) {
    Response::error('Signalement introuvable', 404);
}

// Contrôle d'accès : le citoyen ne voit que son propre signalement
$isOwner    = $report['user_id'] == $user['id'];
$isAuthority = in_array($user['role'], ['authority_agent', 'admin'], true)
               && $report['authority_id'] == $user['authority_id'];

if (!$isOwner && !$isAuthority) {
    Response::error('Accès refusé', 403);
}

$mediaStmt = $pdo->prepare('SELECT media_url, media_type, captured_at FROM report_media WHERE report_id = :id');
$mediaStmt->execute(['id' => $reportId]);
$report['media'] = $mediaStmt->fetchAll();

$historyStmt = $pdo->prepare(
    'SELECT h.old_status, h.new_status, h.note, h.changed_at, u.full_name AS changed_by_name
     FROM report_status_history h
     LEFT JOIN users u ON u.id = h.changed_by
     WHERE h.report_id = :id ORDER BY h.changed_at ASC'
);
$historyStmt->execute(['id' => $reportId]);
$report['status_history'] = $historyStmt->fetchAll();

Response::success($report, 'Détail du signalement');
