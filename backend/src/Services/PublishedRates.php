<?php
declare(strict_types=1);

namespace Terboekt\Services;

use RuntimeException;

final class PublishedRates
{
    /** @param array<string, mixed> $data */
    private function __construct(public readonly array $data)
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException('Published rates file missing: ' . $path);
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid published rates JSON');
        }
        return new self($data);
    }

    public function currency(): string
    {
        return (string) $this->data['currency'];
    }

    public function maxGuests(): int
    {
        return (int) $this->data['maxGuests'];
    }

    public function depositPercentage(): int
    {
        return (int) $this->data['depositPercentage'];
    }

    /** @return list<int> */
    public function highMonths(): array
    {
        return array_map('intval', $this->data['seasons']['highMonths'] ?? []);
    }

    /** @return list<int> */
    public function lowMonths(): array
    {
        return array_map('intval', $this->data['seasons']['lowMonths'] ?? []);
    }

    /** @return list<int> */
    public function unspecifiedMonths(): array
    {
        return array_map('intval', $this->data['seasons']['unspecifiedMonths'] ?? [10]);
    }

    /** @return list<array<string, mixed>> */
    public function packages(): array
    {
        return $this->data['packages'] ?? [];
    }

    /** @return array<string, int> */
    public function fees(): array
    {
        $fees = $this->data['fees'] ?? [];
        return [
            'sundayEveningExtraCents' => (int) ($fees['sundayEveningExtraCents'] ?? 0),
            'cleaningCents' => (int) ($fees['cleaningCents'] ?? 0),
            'touristTaxPerPersonPerNightCents' => (int) ($fees['touristTaxPerPersonPerNightCents'] ?? 0),
            'securityDepositCents' => (int) ($fees['securityDepositCents'] ?? 0),
        ];
    }

    public function publicPayload(): array
    {
        return $this->data;
    }
}
