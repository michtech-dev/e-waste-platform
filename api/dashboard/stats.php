<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Méthode non autorisée', 405);
}

$agent = AuthMiddleware::requireAuthority();
$pdo   = Database::getConnection();
$authorityId = $agent['authority_id'];

// 1. Répartition par statut
$statusStmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS total FROM reports
     WHERE authority_id = :aid GROUP BY status'
);
$statusStmt->execute(['aid' => $authorityId]);
$byStatus = $statusStmt->fetchAll();

// 2. Temps moyen de résolution (en heures)
$avgStmt = $pdo->prepare(
    "SELECT AVG(EXTRACT(EPOCH FROM (resolved_at - created_at)) / 3600) AS avg_hours
     FROM reports
     WHERE authority_id = :aid AND status = 'resolved' AND resolved_at IS NOT NULL"
);
$avgStmt->execute(['aid' => $authorityId]);
$avgResolutionHours = round((float)($avgStmt->fetch()['avg_hours'] ?? 0), 1);

// 2bis. Répartition par catégorie
$categoryStmt = $pdo->prepare(
    'SELECT category, COUNT(*) AS total FROM reports
     WHERE authority_id = :aid GROUP BY category ORDER BY total DESC'
);
$categoryStmt->execute(['aid' => $authorityId]);
$byCategory = $categoryStmt->fetchAll();

// 2ter. Répartition géographique par commune
$byCommuneStmt = $pdo->prepare(
    'SELECT c.name AS commune, COUNT(*) AS total
     FROM reports r JOIN communes c ON c.id = r.commune_id
     WHERE r.authority_id = :aid GROUP BY c.name ORDER BY total DESC LIMIT 10'
);
$byCommuneStmt->execute(['aid' => $authorityId]);
$byCommune = $byCommuneStmt->fetchAll();

// 3. Signalements des 30 derniers jours (tendance)
$trendStmt = $pdo->prepare(
    "SELECT DATE(created_at) AS day, COUNT(*) AS total
     FROM reports
     WHERE authority_id = :aid AND created_at > NOW() - INTERVAL '30 days'
     GROUP BY DATE(created_at) ORDER BY day ASC"
);
$trendStmt->execute(['aid' => $authorityId]);
$trend = $trendStmt->fetchAll();

// 4. Top 5 des zones à risque
$hotspotStmt = $pdo->query(
    'SELECT name, latitude, longitude, report_count
     FROM hotspots ORDER BY report_count DESC LIMIT 5'
);
$topHotspots = $hotspotStmt->fetchAll();

// 5. Total citoyens actifs (ayant signalé au moins une fois pour cette autorité)
$citizensStmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT user_id) AS total FROM reports WHERE authority_id = :aid'
);
$citizensStmt->execute(['aid' => $authorityId]);
$activeCitizens = (int)$citizensStmt->fetch()['total'];

Response::success([
    'by_status'             => $byStatus,
    'by_category'           => $byCategory,
    'by_commune'            => $byCommune,
    'avg_resolution_hours'  => $avgResolutionHours,
    'trend_last_30_days'    => $trend,
    'top_hotspots'          => $topHotspots,
    'active_citizens'       => $activeCitizens,
], 'Statistiques récupérées');
