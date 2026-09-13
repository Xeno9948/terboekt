<?php
declare(strict_types=1);

namespace Terboekt\Domain;

use RuntimeException;

class DomainException extends RuntimeException
{
}

final class IllegalTransitionException extends DomainException
{
    public static function from(string $from, string $to): self
    {
        return new self("Illegal booking status transition: {$from} → {$to}");
    }
}

final class ConfirmationRequiresManagerException extends DomainException
{
    public function __construct()
    {
        parent::__construct('A booking cannot be CONFIRMED without manager approval');
    }
}

final class NotFoundException extends DomainException
{
}

final class ConflictException extends DomainException
{
}

final class ValidationException extends DomainException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message);
    }
}

final class UnavailableException extends DomainException
{
}

final class AuthRequiredException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Authentication required');
    }
}
