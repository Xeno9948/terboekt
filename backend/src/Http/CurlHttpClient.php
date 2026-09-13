<?php
declare(strict_types=1);

namespace Terboekt\Http;

final class CurlHttpClient implements HttpClient
{
    public function get(string $url, int $timeoutSeconds = 20): HttpResponse
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HomeTerboekt/1.0; +https://hometerboekt.be)',
                CURLOPT_HTTPHEADER => ['Accept: text/calendar, text/plain, */*'],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                return new HttpResponse(0, '', $error ?: 'curl failed');
            }
            return new HttpResponse($status, (string) $body, $error);
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'header' => "User-Agent: HomeTerboekt-CalendarSync/1.0\r\nAccept: text/calendar\r\n",
                'follow_location' => 1,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return new HttpResponse(0, '', 'fetch failed');
        }
        return new HttpResponse(200, $body);
    }
}
