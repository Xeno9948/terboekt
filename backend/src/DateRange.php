<?php
declare(strict_types=1);

namespace Terboekt;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Stay ranges are half-open: [start, end). Checkout day is free for the next guest.
 */
final class DateRange
{
    public function __construct(
        public readonly string $start,
        public readonly string $end,
    ) {
        self::assertYmd($start);
        self::assertYmd($end);
        if ($end <= $start) {
            throw new InvalidArgumentException('Range end must be after start');
        }
    }

    public static function of(string $start, string $end): self
    {
        return new self($start, $end);
    }

    public static function assertYmd(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Expected YYYY-MM-DD, got ' . $date);
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid calendar date: ' . $date);
        }
    }

    public function nights(): int
    {
        $from = new DateTimeImmutable($this->start);
        $to = new DateTimeImmutable($this->end);
        return (int) $from->diff($to)->format('%a');
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }

    /** @return list<string> Inclusive occupied nights (checkout excluded). */
    public function nightsList(): array
    {
        $days = [];
        $cursor = new DateTimeImmutable($this->start);
        $end = new DateTimeImmutable($this->end);
        while ($cursor < $end) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->add(new DateInterval('P1D'));
        }
        return $days;
    }

    public function weekdayOfStart(): int
    {
        return (int) (new DateTimeImmutable($this->start))->format('w');
    }

    public function weekdayOfEnd(): int
    {
        return (int) (new DateTimeImmutable($this->end))->format('w');
    }

    public static function today(string $timezone = 'Europe/Brussels'): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
    }

    public static function addDays(string $ymd, int $days): string
    {
        self::assertYmd($ymd);
        $dt = new DateTimeImmutable($ymd);
        if ($days >= 0) {
            return $dt->add(new DateInterval('P' . $days . 'D'))->format('Y-m-d');
        }
        return $dt->sub(new DateInterval('P' . abs($days) . 'D'))->format('Y-m-d');
    }
}
