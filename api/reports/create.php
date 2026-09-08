<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../helpers/Categories.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Méthode non autorisée', 405);
}

$user = AuthMiddleware::authenticate();

// --- Anti-abus : max 10 signalements / 24h par utilisateur ---
const MAX_REPORTS_PER_DAY = 10;

$pdo = Database::getConnection();

$limitCheck = $pdo->prepare(
    "SELECT COUNT(*) AS cnt FROM reports
     WHERE user_id = :uid AND created_at > NOW() - INTERVAL '24 hours'"
);
$limitCheck->execute(['uid' => $user['id']]);
if ((int)$limitCheck->fetch()['cnt'] >= MAX_REPORTS_PER_DAY) {
    Response::error('Limite quotidienne de signalements atteinte', 429);
}

// --- Champs attendus : multipart/form-data (photo/vidéo) ou JSON pur ---
$category    = $_POST['category'] ?? '';
$description = trim($_POST['description'] ?? '');
$latitude    = $_POST['latitude']  ?? null;
$longitude   = $_POST['longitude'] ?? null;
$severity    = $_POST['severity']  ?? 'medium';
$isAnonymous = filter_var($_POST['is_anonymous'] ?? false, FILTER_VALIDATE_BOOLEAN);
$authorityId = $_POST['authority_id'] ?? null;
$capturedAt  = $_POST['captured_at'] ?? date('Y-m-d H:i:s');

// Localisation administrative (facultative mais recommandée pour l'agrégation
// par colline/zone/commune/province) — en complément du GPS, pas en remplacement.
$provinceId = $_POST['province_id'] ?: null;
$communeId  = $_POST['commune_id'] ?: null;
$zoneId     = $_POST['zone_id'] ?: null;
$collineId  = $_POST['colline_id'] ?: null;

if (!Categories::isValid($category)) {
    Response::error('Catégorie invalide. Valeurs acceptées : ' . implode(', ', Categories::codes()), 422);
}
if ($latitude === null || $longitude === null) {
    Response::error('La position GPS (latitude/longitude) est requise', 422);
}
if (!in_array($severity, ['low', 'medium', 'high'], true)) {
    $severity = 'medium';
}

// Si aucune autorité précisée : on route automatiquement vers le type le plus
// adapté à la catégorie (ex: pollution -> ONG, nid-de-poule -> municipal),
// avec repli sur la première autorité disponible si aucune ne correspond.
if (!$authorityId) {
    $preferredType = Categories::preferredAuthorityType($category);
    $routeStmt = $pdo->prepare('SELECT id FROM authorities WHERE type = :type ORDER BY id LIMIT 1');
    $routeStmt->execute(['type' => $preferredType]);
    $authorityId = $routeStmt->fetchColumn();

    if (!$authorityId) {
        $authorityId = $pdo->query('SELECT id FROM authorities ORDER BY id LIMIT 1')->fetchColumn();
    }
}

try {
    $pdo->beginTransaction();

    // 1. Création du signalement
    $trackingCode = strtoupper(bin2hex(random_bytes(4))); // ex: A1B2C3D4

    $stmt = $pdo->prepare(
        'INSERT INTO reports (user_id, authority_id, category, description, latitude, longitude, severity,
                               is_anonymous, province_id, commune_id, zone_id, colline_id, tracking_code)
         VALUES (:user_id, :authority_id, :category, :description, :lat, :lng, :severity,
                 :is_anon, :province_id, :commune_id, :zone_id, :colline_id, :tracking_code)
         RETURNING id, status, created_at'
    );
    $stmt->execute([
        'user_id'       => $user['id'],
        'authority_id'  => $authorityId,
        'category'      => $category,
        'description'   => $description ?: null,
        'lat'           => $latitude,
        'lng'           => $longitude,
        'severity'      => $severity,
        'is_anon'       => $isAnonymous,
        'province_id'   => $provinceId,
        'commune_id'    => $communeId,
        'zone_id'       => $zoneId,
        'colline_id'    => $collineId,
        'tracking_code' => $trackingCode,
    ]);
    $report = $stmt->fetch();
    $reportId = $report['id'];

    // 2. Upload des fichiers preuve (photo/vidéo)
    $mediaSaved = [];
    if (!empty($_FILES)) {
        $mediaSaved = handleUploads($pdo, $reportId, $capturedAt);
    }

    // 3. Mise à jour / création du "point noir" le plus proche (rayon ~50m)
    updateHotspot($pdo, (float)$latitude, (float)$longitude);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    Response::error('Échec de la création du signalement', 500, $e->getMessage());
}

Response::success([
    'report_id'     => $reportId,
    'category'      => $category,
    'status'        => $report['status'],
    'tracking_code' => $trackingCode,
    'media'         => $mediaSaved,
], 'Signalement enregistré', 201);

// ============================================
// Fonctions internes
// ============================================
function handleUploads(PDO $pdo, int $reportId, string $capturedAt): array
{
    $allowedTypes = [
        'image/jpeg' => 'photo', 'image/png' => 'photo', 'image/webp' => 'photo',
        'video/mp4'  => 'video', 'video/3gpp' => 'video', 'video/quicktime' => 'video',
    ];
    $maxSize = 25 * 1024 * 1024; // 25 Mo
    $saved = [];

    foreach ($_FILES as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) continue;
        if ($file['size'] > $maxSize) continue;

        $mime = mime_content_type($file['tmp_name']);
        if (!isset($allowedTypes[$mime])) continue;

        $type = $allowedTypes[$mime];
        $ext  = $type === 'photo' ? 'jpg' : 'mp4';
        $filename = uniqid('rpt_' . $reportId . '_', true) . '.' . $ext;
        $subdir = $type === 'photo' ? 'photos' : 'videos';
        $destination = __DIR__ . '/../../uploads/' . $subdir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $publicUrl = '/uploads/' . $subdir . '/' . $filename;

            $stmt = $pdo->prepare(
                'INSERT INTO report_media (report_id, media_url, media_type, captured_at)
                 VALUES (:report_id, :url, :type, :captured_at)'
            );
            $stmt->execute([
                'report_id'   => $reportId,
                'url'         => $publicUrl,
                'type'        => $type,
                'captured_at' => $capturedAt,
            ]);
            $saved[] = ['url' => $publicUrl, 'type' => $type];
        }
    }
    return $saved;
}

function updateHotspot(PDO $pdo, float $lat, float $lng): void
{
    // Rayon approximatif de 50m en degrés (~0.00045°)
    $radius = 0.00045;

    $existing = $pdo->prepare(
        'SELECT id FROM hotspots
         WHERE latitude BETWEEN :lat_min AND :lat_max
           AND longitude BETWEEN :lng_min AND :lng_max
         LIMIT 1'
    );
    $existing->execute([
        'lat_min' => $lat - $radius, 'lat_max' => $lat + $radius,
        'lng_min' => $lng - $radius, 'lng_max' => $lng + $radius,
    ]);
    $hotspot = $existing->fetch();

    if ($hotspot) {
        $update = $pdo->prepare(
            'UPDATE hotspots SET report_count = report_count + 1, last_updated = NOW()
             WHERE id = :id'
        );
        $update->execute(['id' => $hotspot['id']]);
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO hotspots (name, latitude, longitude, report_count)
             VALUES (:name, :lat, :lng, 1)'
        );
        $insert->execute(['name' => 'Zone non nommée', 'lat' => $lat, 'lng' => $lng]);
    }
}
