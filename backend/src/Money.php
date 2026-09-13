<?php
declare(strict_types=1);

namespace Terboekt;

use InvalidArgumentException;

/**
 * Integer minor units (cents). Never uses floating-point arithmetic.
 */
final class Money
{
    public static function percent(int $cents, int $percent): int
    {
        if ($cents < 0 || $percent < 0) {
            throw new InvalidArgumentException('Money percent requires non-negative values');
        }
        // Round half up: (cents * percent + 50) / 100
        return intdiv($cents * $percent + 50, 100);
    }

    public static function fromEuroString(string $euros): int
    {
        $normalized = str_replace([' ', '€'], '', trim($euros));
        $normalized = str_replace(',', '.', $normalized);
        if ($normalized === '' || !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException('Invalid euro amount: ' . $euros);
        }
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        $parts = explode('.', $normalized, 2);
        $major = (int) $parts[0];
        $minor = isset($parts[1]) ? str_pad(substr($parts[1], 0, 2), 2, '0') : '00';
        $cents = $major * 100 + (int) $minor;
        return $negative ? -$cents : $cents;
    }

    public static function formatEuro(int $cents, string $locale = 'nl'): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);
        $major = intdiv($cents, 100);
        $minor = $cents % 100;
        $formatted = number_format($major, 0, ',', '.') . ',' . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
        $sign = $negative ? '-' : '';
        return $sign . '€ ' . $formatted;
    }

    public static function toEuroString(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);
        $major = intdiv($cents, 100);
        $minor = $cents % 100;
        return ($negative ? '-' : '') . $major . '.' . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
    }
}
