<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';

/**
 * Vérifie le header "Authorization: Bearer <token>".
 * Retourne les infos de l'utilisateur connecté ou coupe la requête (401).
 */
class AuthMiddleware
{
    public static function authenticate(): array
    {
        $headers = self::getAuthHeader();

        if (!$headers || !preg_match('/Bearer\s+(\S+)/', $headers, $matches)) {
            Response::error('Token d\'authentification manquant', 401);
        }

        $token = $matches[1];
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT u.id, u.full_name, u.phone, u.role, u.authority_id, u.points, u.status
             FROM auth_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = :token AND t.expires_at > NOW()'
        );
        $stmt->execute(['token' => $token]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('Session invalide ou expirée', 401);
        }
        if ($user['status'] !== 'active') {
            Response::error('Compte suspendu', 403);
        }

        return $user;
    }

    /** Vérifie en plus que l'utilisateur est un agent d'autorité (ou admin). */
    public static function requireAuthority(): array
    {
        $user = self::authenticate();
        if (!in_array($user['role'], ['authority_agent', 'admin'], true)) {
            Response::error('Accès réservé aux autorités compétentes', 403);
        }
        return $user;
    }

    /** @return string|null */
    private static function getAuthHeader()
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                return $headers['Authorization'];
            }
        }
        return null;
    }
}
