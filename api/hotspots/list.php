<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Méthode non autorisée', 405);
}

// Visible par tout utilisateur connecté (citoyen ou autorité)
AuthMiddleware::authenticate();

$pdo = Database::getConnection();

// On ne remonte que les zones avec au moins 2 signalements (évite le bruit isolé)
$stmt = $pdo->query(
    "SELECT h.id, h.name, h.latitude, h.longitude, h.report_count, h.last_updated,
            COUNT(r.id) FILTER (WHERE r.status IN ('pending','in_progress')) AS active_reports
     FROM hotspots h
     LEFT JOIN reports r
       ON r.latitude  BETWEEN h.latitude  - 0.00045 AND h.latitude  + 0.00045
      AND r.longitude BETWEEN h.longitude - 0.00045 AND h.longitude + 0.00045
     WHERE h.report_count >= 2
     GROUP BY h.id
     ORDER BY h.report_count DESC"
);
$hotspots = $stmt->fetchAll();

Response::success(['hotspots' => $hotspots], 'Points noirs récupérés');
