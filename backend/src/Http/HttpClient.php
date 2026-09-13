<?php
declare(strict_types=1);

namespace Terboekt\Http;

interface HttpClient
{
    public function get(string $url, int $timeoutSeconds = 20): HttpResponse;
}
