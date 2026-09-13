<?php
declare(strict_types=1);

namespace Terboekt\Domain;

final class BookingStatus
{
    public const REQUESTED = 'REQUESTED';
    public const AWAITING_DEPOSIT = 'AWAITING_DEPOSIT';
    public const CONFIRMED = 'CONFIRMED';
    public const REJECTED = 'REJECTED';
    public const EXPIRED = 'EXPIRED';
    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [
        self::REQUESTED,
        self::AWAITING_DEPOSIT,
        self::CONFIRMED,
        self::REJECTED,
        self::EXPIRED,
        self::CANCELLED,
    ];

    /** Statuses that occupy the calendar. */
    public const ACTIVE = [
        self::REQUESTED,
        self::AWAITING_DEPOSIT,
        self::CONFIRMED,
    ];

    public const TERMINAL = [
        self::REJECTED,
        self::EXPIRED,
        self::CANCELLED,
    ];

    /**
     * Legal transitions. Terminal statuses have none.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::REQUESTED => [self::AWAITING_DEPOSIT, self::CONFIRMED, self::REJECTED, self::EXPIRED, self::CANCELLED],
        self::AWAITING_DEPOSIT => [self::CONFIRMED, self::REJECTED, self::EXPIRED, self::CANCELLED],
        self::CONFIRMED => [self::CANCELLED],
        self::REJECTED => [],
        self::EXPIRED => [],
        self::CANCELLED => [],
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }
}
