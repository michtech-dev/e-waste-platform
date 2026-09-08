<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../helpers/Categories.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Méthode non autorisée', 405);
}

// Endpoint volontairement PUBLIC (pas d'AuthMiddleware) : un citoyen partage
// son code de suivi (ex: reçu par SMS) sans avoir besoin de se connecter.
// Le code est un identifiant opaque, pas l'ID interne du signalement,
// pour empêcher de parcourir tous les signalements en incrémentant un id.

$code = trim($_GET['code'] ?? '');
if ($code === '') {
    Response::error('Code de suivi requis', 422);
}

$pdo = Database::getConnection();

$stmt = $pdo->prepare(
    'SELECT r.tracking_code, r.category, r.status, r.severity, r.created_at, r.resolved_at,
            p.name AS province_name, c.name AS commune_name, z.name AS zone_name, col.name AS colline_name,
            a.name AS authority_name
     FROM reports r
     LEFT JOIN provinces p ON p.id = r.province_id
     LEFT JOIN communes c ON c.id = r.commune_id
     LEFT JOIN zones z ON z.id = r.zone_id
     LEFT JOIN collines col ON col.id = r.colline_id
     LEFT JOIN authorities a ON a.id = r.authority_id
     WHERE r.tracking_code = :code'
);
$stmt->execute(['code' => strtoupper($code)]);
$report = $stmt->fetch();

if (!$report) {
    Response::error('Aucun signalement trouvé pour ce code', 404);
}

$historyStmt = $pdo->prepare(
    "SELECT new_status, changed_at FROM report_status_history
     WHERE report_id = (SELECT id FROM reports WHERE tracking_code = :code)
     ORDER BY changed_at ASC"
);
$historyStmt->execute(['code' => strtoupper($code)]);

Response::success([
    'tracking_code'  => $report['tracking_code'],
    'category'       => $report['category'],
    'category_label' => Categories::label($report['category']),
    'status'         => $report['status'],
    'severity'       => $report['severity'],
    'location'       => [
        'province' => $report['province_name'],
        'commune'  => $report['commune_name'],
        'zone'     => $report['zone_name'],
        'colline'  => $report['colline_name'],
    ],
    'authority'      => $report['authority_name'],
    'created_at'     => $report['created_at'],
    'resolved_at'    => $report['resolved_at'],
    'history'        => $historyStmt->fetchAll(),
], 'Suivi du signalement');
