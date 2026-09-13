<?php
declare(strict_types=1);

namespace Terboekt\Http;

interface HttpClient
{
    public function get(string $url, int $timeoutSeconds = 20): HttpResponse;

    /** @param array<string, string> $fields */
    public function postForm(string $url, array $fields, int $timeoutSeconds = 20): HttpResponse;

    /**
     * @param array<string, mixed> $json
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $json, array $headers = [], int $timeoutSeconds = 20): HttpResponse;
}
