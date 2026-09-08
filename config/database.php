<?php
/**
 * Connexion PDO à PostgreSQL (pattern singleton)
 * Modifie les constantes ci-dessous selon ton environnement,
 * ou définis-les comme variables d'environnement serveur.
 */

class Database
{
    /** @var PDO|null */
    private static $instance = null;

    // ---- Paramètres de connexion ----
    private const HOST    = 'localhost';
    private const PORT    = '5432';
    private const DBNAME  = 'waste_platform';
    private const USER    = 'waste_app';
    private const PASS    = 'change_me_in_production';

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host   = getenv('DB_HOST') ?: self::HOST;
            $port   = getenv('DB_PORT') ?: self::PORT;
            $dbname = getenv('DB_NAME') ?: self::DBNAME;
            $user   = getenv('DB_USER') ?: self::USER;
            $pass   = getenv('DB_PASS') ?: self::PASS;

            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (\Throwable $e) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Erreur de connexion à la base de données',
                    'debug'   => $e->getMessage(), // ⚠️ À RETIRER avant la mise en production
                ]);
                exit;
            }
        }
        return self::$instance;
    }
}
