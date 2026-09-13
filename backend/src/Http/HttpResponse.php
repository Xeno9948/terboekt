<?php
declare(strict_types=1);

namespace Terboekt\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $error = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300 && $this->body !== '';
    }
}
