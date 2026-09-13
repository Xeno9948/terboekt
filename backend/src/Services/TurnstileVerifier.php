<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\Config;
use Terboekt\Domain\ValidationException;
use Terboekt\Http\HttpClient;

final class TurnstileVerifier
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
    ) {
    }

    public function siteKey(): ?string
    {
        $key = $this->config->turnstileSiteKey;
        return $key !== null && $key !== '' ? $key : null;
    }

    public function required(): bool
    {
        return $this->config->turnstileSecret !== null && $this->config->turnstileSecret !== '';
    }

    public function assertValid(?string $token, ?string $ip): void
    {
        if (!$this->required()) {
            return;
        }
        $token = trim((string) $token);
        if ($token === '') {
            throw new ValidationException(['Bevestig dat u geen robot bent.']);
        }
        $fields = [
            'secret' => (string) $this->config->turnstileSecret,
            'response' => $token,
        ];
        if ($ip) {
            $fields['remoteip'] = $ip;
        }
        $response = $this->http->postForm('https://challenges.cloudflare.com/turnstile/v0/siteverify', $fields);
        $data = json_decode($response->body, true);
        if (!$response->ok() || !is_array($data) || empty($data['success'])) {
            throw new ValidationException(['Beveiligingscontrole mislukt. Probeer opnieuw.']);
        }
    }
}
