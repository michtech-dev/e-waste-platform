<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Méthode non autorisée', 405);
}

// Toute personne connectée (citoyen ou autorité) peut consulter les divisions
AuthMiddleware::authenticate();

$level = $_GET['level'] ?? 'provinces';
$parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : null;

$pdo = Database::getConnection();

switch ($level) {
    case 'provinces':
        $stmt = $pdo->query('SELECT id, name, chef_lieu FROM provinces ORDER BY name');
        break;

    case 'communes':
        if (!$parentId) Response::error('parent_id (province_id) requis', 422);
        $stmt = $pdo->prepare('SELECT id, name, chef_lieu FROM communes WHERE province_id = :pid ORDER BY name');
        $stmt->execute(['pid' => $parentId]);
        break;

    case 'zones':
        if (!$parentId) Response::error('parent_id (commune_id) requis', 422);
        $stmt = $pdo->prepare('SELECT id, name FROM zones WHERE commune_id = :cid ORDER BY name');
        $stmt->execute(['cid' => $parentId]);
        break;

    case 'collines':
        if (!$parentId) Response::error('parent_id (zone_id) requis', 422);
        $stmt = $pdo->prepare('SELECT id, name, type FROM collines WHERE zone_id = :zid ORDER BY name');
        $stmt->execute(['zid' => $parentId]);
        break;

    default:
        Response::error('level invalide (provinces|communes|zones|collines)', 422);
}

Response::success(['level' => $level, 'items' => $stmt->fetchAll()], 'Divisions récupérées');
