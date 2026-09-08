<?php
/**
 * Uniformise toutes les réponses JSON de l'API.
 * Toujours utilisé en dernière instruction (contient exit).
 */

class Response
{
    public static function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *'); // apps Cordova = origine "file://"
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success($data = null, string $message = 'OK', int $code = 200): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    public static function error(string $message, int $code = 400, $details = null): void
    {
        self::json([
            'success' => false,
            'message' => $message,
            'details' => $details,
        ], $code);
    }
}
