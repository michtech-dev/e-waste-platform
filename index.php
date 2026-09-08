<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status'  => 'ok',
    'message' => 'API Plateforme de signalement des déchets — voir README.md pour la liste des endpoints.',
]);
