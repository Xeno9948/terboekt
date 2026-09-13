<?php
declare(strict_types=1);

namespace Terboekt\Http;

final class JsonResponse
{
    public static function send(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(int $status, string $code, string $message, array $extra = []): never
    {
        self::send($status, array_merge(['ok' => false, 'error' => $code, 'message' => $message], $extra));
    }
}
