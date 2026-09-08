<?php
echo "1. PHP démarre correctement" . PHP_EOL;

echo "2. Tentative de connexion PDO PostgreSQL..." . PHP_EOL;

try {
    $pdo = new PDO('pgsql:host=localhost;port=5432;dbname=waste_platform', 'waste_app', 'TON_MOT_DE_PASSE_ICI');
    echo "3. Connexion réussie !" . PHP_EOL;
} catch (\Throwable $e) {
    echo "3. Erreur (mais au moins ça n'a pas crashé) : " . $e->getMessage() . PHP_EOL;
}

echo "4. Script terminé normalement" . PHP_EOL;
