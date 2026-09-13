<?php
declare(strict_types=1);

namespace Terboekt\Mail;

use Terboekt\Config;
use Terboekt\Http\HttpClient;

final class ResendTransport
{
    public const API_URL = 'https://api.resend.com/emails';

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
    ) {
    }

    public function configured(): bool
    {
        return $this->config->resendConfigured();
    }

    public function send(OutgoingMessage $message): void
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Resend is not configured');
        }

        $fromEmail = $this->config->smtpFromEmail;
        $fromName = trim($this->config->smtpFromName);
        $from = $fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail;
        $payload = [
            'from' => $from,
            'to' => array_values($message->to),
            'subject' => $message->subject,
            'html' => $message->html,
            'text' => $message->text,
        ];
        $reply = $message->replyTo ?: $this->config->smtpReplyTo;
        if ($reply !== '') {
            $payload['reply_to'] = $reply;
        }

        $response = $this->http->postJson(
            self::API_URL,
            $payload,
            [
                'Authorization' => 'Bearer ' . (string) $this->config->resendApiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            15,
        );

        if ($response->status < 200 || $response->status >= 300) {
            throw new \RuntimeException($this->describeFailure($response->status, $response->body, $response->error));
        }
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded) || empty($decoded['id'])) {
            throw new \RuntimeException('Resend: onverwacht antwoord (geen id).');
        }
    }

    private function describeFailure(int $status, string $body, string $transportError): string
    {
        $decoded = json_decode($body, true);
        $message = '';
        if (is_array($decoded)) {
            if (isset($decoded['message']) && is_string($decoded['message'])) {
                $message = $decoded['message'];
            } elseif (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message = $decoded['error']['message'];
            }
        }
        if ($message === '' && $transportError !== '') {
            $message = $transportError;
        }
        if ($message === '') {
            $message = $status > 0 ? 'HTTP ' . $status : 'geen antwoord';
        }
        return 'Resend: ' . $message;
    }
}
