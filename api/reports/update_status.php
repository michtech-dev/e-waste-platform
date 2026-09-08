<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/Response.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Méthode non autorisée', 405);
}

$agent = AuthMiddleware::requireAuthority();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$reportId  = (int)($input['report_id'] ?? 0);
$newStatus = $input['status'] ?? '';
$note      = trim($input['note'] ?? '');

$validStatuses = ['pending', 'in_progress', 'resolved', 'rejected'];
if (!$reportId || !in_array($newStatus, $validStatuses, true)) {
    Response::error('Identifiant et statut valide requis', 422);
}

// Points attribués au citoyen selon le statut final
const POINTS_ON_RESOLVED = 20;

$pdo = Database::getConnection();

$stmt = $pdo->prepare('SELECT id, user_id, status, authority_id FROM reports WHERE id = :id');
$stmt->execute(['id' => $reportId]);
$report = $stmt->fetch();

if (!$report) {
    Response::error('Signalement introuvable', 404);
}
if ($report['authority_id'] != $agent['authority_id'] && $agent['role'] !== 'admin') {
    Response::error('Ce signalement ne relève pas de votre autorité', 403);
}

try {
    $pdo->beginTransaction();

    $oldStatus = $report['status'];
    $resolvedAt = $newStatus === 'resolved' ? 'NOW()' : 'resolved_at';

    $update = $pdo->prepare(
        "UPDATE reports SET status = :new_status,
             resolved_at = CASE WHEN :new_status2 = 'resolved' THEN NOW() ELSE resolved_at END
         WHERE id = :id"
    );
    $update->execute([
        'new_status'  => $newStatus,
        'new_status2' => $newStatus,
        'id'          => $reportId,
    ]);

    $history = $pdo->prepare(
        'INSERT INTO report_status_history (report_id, old_status, new_status, changed_by, note)
         VALUES (:report_id, :old_status, :new_status, :changed_by, :note)'
    );
    $history->execute([
        'report_id'  => $reportId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'changed_by' => $agent['id'],
        'note'       => $note ?: null,
    ]);

    // Notification au citoyen
    $messages = [
        'in_progress' => 'Votre signalement est en cours de traitement.',
        'resolved'    => 'Votre signalement a été résolu, merci pour votre civisme !',
        'rejected'    => 'Votre signalement a été examiné et rejeté.',
    ];
    if (isset($messages[$newStatus]) && $report['user_id']) {
        $notif = $pdo->prepare(
            'INSERT INTO notifications (user_id, report_id, message)
             VALUES (:user_id, :report_id, :message)'
        );
        $notif->execute([
            'user_id'   => $report['user_id'],
            'report_id' => $reportId,
            'message'   => $messages[$newStatus],
        ]);
    }

    // Attribution de points si résolu
    if ($newStatus === 'resolved' && $report['user_id']) {
        $points = $pdo->prepare('UPDATE users SET points = points + :pts WHERE id = :uid');
        $points->execute(['pts' => POINTS_ON_RESOLVED, 'uid' => $report['user_id']]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    Response::error('Échec de la mise à jour du statut', 500, $e->getMessage());
}

Response::success(['report_id' => $reportId, 'status' => $newStatus], 'Statut mis à jour');
