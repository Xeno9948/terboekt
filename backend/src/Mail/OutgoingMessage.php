<?php
declare(strict_types=1);

namespace Terboekt\Mail;

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
