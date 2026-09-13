<?php
declare(strict_types=1);

namespace Terboekt\Services;

use RuntimeException;
use Terboekt\Repositories\PropertySettingsRepository;
use Terboekt\Repositories\RateRuleRepository;

final class PublishedRates
{
    /** @param array<string, mixed> $fileData */
    private function __construct(
        private readonly array $fileData,
        private readonly ?RateRuleRepository $rates = null,
        private readonly ?PropertySettingsRepository $settings = null,
    ) {
    }

    public static function load(
        string $path,
        ?RateRuleRepository $rates = null,
        ?PropertySettingsRepository $settings = null,
    ): self {
        if (!is_file($path)) {
            throw new RuntimeException('Published rates file missing: ' . $path);
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid published rates JSON');
        }
        return new self($data, $rates, $settings);
    }

    public function currency(): string
    {
        return (string) $this->publicPayload()['currency'];
    }

    public function maxGuests(): int
    {
        return (int) $this->publicPayload()['maxGuests'];
    }

    public function depositPercentage(): int
    {
        return (int) $this->publicPayload()['depositPercentage'];
    }

    /** @return list<int> */
    public function highMonths(): array
    {
        return array_map('intval', $this->publicPayload()['seasons']['highMonths'] ?? []);
    }

    /** @return list<int> */
    public function lowMonths(): array
    {
        return array_map('intval', $this->publicPayload()['seasons']['lowMonths'] ?? []);
    }

    /** @return list<int> */
    public function unspecifiedMonths(): array
    {
        return array_map('intval', $this->publicPayload()['seasons']['unspecifiedMonths'] ?? [10]);
    }

    /** @return list<array<string, mixed>> */
    public function packages(): array
    {
        return $this->publicPayload()['packages'] ?? [];
    }

    /** @return array<string, int> */
    public function fees(): array
    {
        $fees = $this->publicPayload()['fees'] ?? [];
        return [
            'sundayEveningExtraCents' => (int) ($fees['sundayEveningExtraCents'] ?? 0),
            'cleaningCents' => (int) ($fees['cleaningCents'] ?? 0),
            'touristTaxPerPersonPerNightCents' => (int) ($fees['touristTaxPerPersonPerNightCents'] ?? 0),
            'securityDepositCents' => (int) ($fees['securityDepositCents'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function publicPayload(): array
    {
        if ($this->rates === null || $this->settings === null) {
            return $this->fileData;
        }
        return $this->mergeLive($this->fileData);
    }

    /**
     * Nightly amount derived from published packages (not invented).
     * Fri/Sat use weekend / 2 nights. Other nights use midweek / 4, else week / 7.
     */
    public function impliedNightlyCents(string $ymd, string $season): ?int
    {
        $key = $season === 'high' ? 'highCents' : 'lowCents';
        $weekday = (int) (new \DateTimeImmutable($ymd))->format('w');
        $weekend = $this->packageById('weekend');
        $midweek = $this->packageById('midweek');
        $week = $this->packageById('week');
        if (in_array($weekday, [5, 6], true) && $weekend && !empty($weekend[$key])) {
            return intdiv((int) $weekend[$key], max(1, (int) $weekend['nights']));
        }
        if ($midweek && !empty($midweek[$key])) {
            return intdiv((int) $midweek[$key], max(1, (int) $midweek['nights']));
        }
        if ($week && !empty($week[$key])) {
            return intdiv((int) $week[$key], max(1, (int) $week['nights']));
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public function packageById(string $id): ?array
    {
        foreach ($this->packages() as $package) {
            if (($package['id'] ?? '') === $id) {
                return $package;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mergeLive(array $data): array
    {
        $settings = $this->settings;
        $rates = $this->rates;
        if ($settings === null || $rates === null) {
            return $data;
        }

        $data['currency'] = $settings->currency();
        $data['maxGuests'] = $settings->maxGuests();
        $data['bedrooms'] = $settings->getInt('bedrooms', (int) ($data['bedrooms'] ?? 4));
        $data['propertyName'] = $settings->get('property_name', (string) ($data['propertyName'] ?? 'Home Terboekt')) ?? (string) ($data['propertyName'] ?? 'Home Terboekt');
        $data['address'] = $settings->get('property_address', (string) ($data['address'] ?? '')) ?? (string) ($data['address'] ?? '');
        $data['email'] = $settings->get('contact_email', (string) ($data['email'] ?? '')) ?? (string) ($data['email'] ?? '');
        $data['checkinFrom'] = $settings->get('checkin_from', (string) ($data['checkinFrom'] ?? '16:00')) ?? '16:00';
        $data['checkoutBefore'] = $settings->get('checkout_before', (string) ($data['checkoutBefore'] ?? '10:00')) ?? '10:00';
        $data['houseRulesUrl'] = $settings->get('house_rules_url', (string) ($data['houseRulesUrl'] ?? 'voorwaarden.html')) ?? 'voorwaarden.html';
        $data['timezone'] = $settings->get('timezone', (string) ($data['timezone'] ?? 'Europe/Brussels')) ?? 'Europe/Brussels';
        $data['depositPercentage'] = $settings->depositPercentage();
        $data['depositDeadlineDays'] = $settings->depositDeadlineDays();

        $byCode = [];
        foreach ($rates->findAllByType('package') as $row) {
            $code = (string) $row['code'];
            $season = (string) ($row['season'] ?? '');
            if (!isset($byCode[$code])) {
                $byCode[$code] = [
                    'id' => $code,
                    'nights' => $row['nights'] !== null ? (int) $row['nights'] : null,
                    'checkinWeekday' => $row['checkin_weekday'] !== null ? (int) $row['checkin_weekday'] : null,
                    'checkoutWeekday' => $row['checkout_weekday'] !== null ? (int) $row['checkout_weekday'] : null,
                    'lowCents' => null,
                    'highCents' => null,
                    'enabled' => false,
                ];
            }
            if ($row['nights'] !== null) {
                $byCode[$code]['nights'] = (int) $row['nights'];
            }
            if ($season === 'low' && $row['amount_cents'] !== null) {
                $byCode[$code]['lowCents'] = (int) $row['amount_cents'];
            } elseif ($season === 'high' && $row['amount_cents'] !== null) {
                $byCode[$code]['highCents'] = (int) $row['amount_cents'];
            }
            if ((int) $row['enabled'] === 1) {
                $byCode[$code]['enabled'] = true;
            }
        }

        $packages = [];
        foreach ($data['packages'] ?? [] as $pkg) {
            $id = (string) ($pkg['id'] ?? '');
            if ($id !== '' && isset($byCode[$id])) {
                $live = $byCode[$id];
                $packages[] = [
                    'id' => $id,
                    'nights' => $live['nights'] ?? $pkg['nights'],
                    'checkinWeekday' => $live['checkinWeekday'] ?? $pkg['checkinWeekday'] ?? null,
                    'checkoutWeekday' => $live['checkoutWeekday'] ?? $pkg['checkoutWeekday'] ?? null,
                    'lowCents' => $live['lowCents'] ?? $pkg['lowCents'] ?? 0,
                    'highCents' => $live['highCents'] ?? $pkg['highCents'] ?? 0,
                    'enabled' => $live['enabled'],
                ];
                unset($byCode[$id]);
            } else {
                $packages[] = $pkg + ['enabled' => true];
            }
        }
        foreach ($byCode as $extra) {
            $packages[] = $extra;
        }
        $data['packages'] = $packages;

        $feeMap = [];
        $extraGuest = ['enabled' => false, 'threshold' => null, 'cents' => null];
        foreach ($rates->findAllByType('fee') as $row) {
            $code = (string) $row['code'];
            if ($code === 'extra_guest') {
                $extraGuest = [
                    'enabled' => (int) $row['enabled'] === 1
                        && $row['extra_guest_threshold'] !== null
                        && $row['extra_guest_cents'] !== null,
                    'threshold' => $row['extra_guest_threshold'] !== null ? (int) $row['extra_guest_threshold'] : null,
                    'cents' => $row['extra_guest_cents'] !== null ? (int) $row['extra_guest_cents'] : null,
                ];
                continue;
            }
            if ($row['amount_cents'] !== null) {
                $feeMap[$code] = (int) $row['amount_cents'];
            }
        }

        $fileFees = is_array($data['fees'] ?? null) ? $data['fees'] : [];
        $data['fees'] = [
            'sundayEveningExtraCents' => $feeMap['sunday_evening']
                ?? $settings->getInt('sunday_evening_extra_cents', (int) ($fileFees['sundayEveningExtraCents'] ?? 0)),
            'cleaningCents' => $feeMap['cleaning']
                ?? $settings->getInt('cleaning_fee_cents', (int) ($fileFees['cleaningCents'] ?? 0)),
            'touristTaxPerPersonPerNightCents' => $feeMap['tourist_tax']
                ?? $settings->getInt('tourist_tax_per_person_per_night_cents', (int) ($fileFees['touristTaxPerPersonPerNightCents'] ?? 0)),
            'securityDepositCents' => $feeMap['security_deposit']
                ?? $settings->getInt('security_deposit_cents', (int) ($fileFees['securityDepositCents'] ?? 0)),
        ];
        $data['extraGuest'] = $extraGuest;

        return $data;
    }
}
