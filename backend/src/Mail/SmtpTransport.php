<?php
declare(strict_types=1);

namespace Terboekt\Mail;

use Terboekt\Config;

final class OutgoingMessage
{
    /** @param list<string> $to */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly ?string $replyTo = null,
    ) {
    }
}

final class SmtpTransport
{
    public function __construct(private readonly Config $config)
    {
    }

    public function configured(): bool
    {
        return $this->config->smtpConfigured();
    }

    public function send(OutgoingMessage $message): void
    {
        if (!$this->configured()) {
            throw new \RuntimeException('SMTP is not configured');
        }

        $host = (string) $this->config->smtpHost;
        $port = $this->config->smtpPort;
        $secure = strtolower($this->config->smtpSecure);
        $implicitSsl = $port === 465 || ($secure === 'ssl' && $port !== 587);
        $startTls = !$implicitSsl && ($port === 587 || $secure === 'tls' || $secure === 'starttls');

        $remote = ($implicitSsl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($fp)) {
            throw new \RuntimeException(
                "SMTP-verbinding mislukt naar {$host}:{$port}"
                . ($errstr !== '' ? " ({$errstr}" . ($errno ? ", code {$errno}" : '') . ')' : ($errno ? " (code {$errno})" : ''))
                . '. Controleer host/poort of dat de mailserver Railway toelaat.'
            );
        }
        stream_set_timeout($fp, 20);

        try {
            $this->expect($fp, [220]);
            $ehlo = $this->ehloHost();
            $this->command($fp, 'EHLO ' . $ehlo, [250]);
            if ($startTls) {
                $this->command($fp, 'STARTTLS', [220]);
                $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if ($crypto !== true) {
                    throw new \RuntimeException('STARTTLS negotiation failed');
                }
                $this->command($fp, 'EHLO ' . $ehlo, [250]);
            }

            $user = (string) $this->config->smtpUsername;
            $pass = (string) $this->config->smtpPassword;
            $this->command($fp, 'AUTH LOGIN', [334]);
            $this->command($fp, base64_encode($user), [334]);
            $this->command($fp, base64_encode($pass), [235]);

            $from = $this->config->smtpFromEmail;
            $this->command($fp, 'MAIL FROM:<' . $from . '>', [250]);
            foreach ($message->to as $rcpt) {
                $this->command($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            $this->command($fp, 'DATA', [354]);
            $payload = $this->buildMime($message);
            foreach (explode("\n", $payload) as $line) {
                $line = rtrim($line, "\r");
                if (str_starts_with($line, '.')) {
                    $line = '.' . $line;
                }
                fwrite($fp, $line . "\r\n");
            }
            $this->command($fp, '.', [250]);
            $this->command($fp, 'QUIT', [221, 250]);
        } finally {
            fclose($fp);
        }
    }

    private function ehloHost(): string
    {
        $host = parse_url($this->config->appBaseUrl, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'hometerboekt.be';
    }

    /** @param list<int> $ok */
    private function command($fp, string $cmd, array $ok): string
    {
        fwrite($fp, $cmd . "\r\n");
        return $this->expect($fp, $ok);
    }

    /** @param resource $fp @param list<int> $ok */
    private function expect($fp, array $ok): string
    {
        $data = '';
        while (($line = fgets($fp, 8192)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($data, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new \RuntimeException('SMTP unexpected response: ' . trim($data));
        }
        return $data;
    }

    private function buildMime(OutgoingMessage $message): string
    {
        $fromName = $this->encodeHeader($this->config->smtpFromName);
        $from = $this->config->smtpFromEmail;
        $reply = $message->replyTo ?: $this->config->smtpReplyTo;
        $boundary = 'b' . bin2hex(random_bytes(12));
        $to = implode(', ', $message->to);
        $headers = [
            'From: ' . $fromName . ' <' . $from . '>',
            'To: ' . $to,
            'Reply-To: ' . $reply,
            'Subject: ' . $this->encodeHeader($message->subject),
            'MIME-Version: 1.0',
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@hometerboekt.be>',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $text = $this->b64($message->text);
        $html = $this->b64($message->html);
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . $text . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . $html . "\r\n"
            . '--' . $boundary . "--\r\n";
        return $body;
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function b64(string $value): string
    {
        return trim(chunk_split(base64_encode($value), 76, "\r\n"));
    }
}
