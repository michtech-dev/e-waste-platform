<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Peut être surchargé avant l'inclusion (ex. sous-dossier accounting : '../login.php')
$loginRedirect = $loginRedirect ?? 'login.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ' . $loginRedirect);
    exit;
}

// Revérifie le statut/rôle en base à chaque chargement (le rôle a pu changer)
$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT id, full_name, phone, role, status FROM users WHERE id = :id');
$stmt->execute(['id' => $_SESSION['admin_id']]);
$currentAdmin = $stmt->fetch();

if (!$currentAdmin || $currentAdmin['role'] !== 'admin' || $currentAdmin['status'] !== 'active') {
    session_destroy();
    header('Location: ' . $loginRedirect . '?expired=1');
    exit;
}
