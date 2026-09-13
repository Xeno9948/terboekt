<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\DateRange;
use Terboekt\Domain\ValidationException;
use Terboekt\Money;
use Terboekt\Repositories\PropertySettingsRepository;
use Terboekt\Repositories\RateRuleRepository;

final class PricingService
{
    public function __construct(
        private readonly RateRuleRepository $rates,
        private readonly PropertySettingsRepository $settings,
        private readonly PublishedRates $published,
    ) {
    }

    /**
     * Season of a single night: high (Apr–Sep), low (Nov–Mar), unspecified (October).
     * School holidays are not encoded; add seasonal date-range rules in admin later.
     */
    public function seasonForDate(string $ymd): string
    {
        DateRange::assertYmd($ymd);
        $month = (int) substr($ymd, 5, 2);
        if (in_array($month, $this->published->highMonths(), true)) {
            return 'high';
        }
        if (in_array($month, $this->published->lowMonths(), true)) {
            return 'low';
        }
        return 'unspecified';
    }

    /**
     * Authoritative nightly rate lookup. Returns null when no configured rule covers the date
     * (October without an override, or no default nightly — we do not invent a rate).
     *
     * Priority (highest first): date_override > seasonal date-range > weekend modifier+nightly > nightly.
     */
    public function getRateForDate(string $ymd): ?array
    {
        DateRange::assertYmd($ymd);
        $weekday = (int) (new \DateTimeImmutable($ymd))->format('w');

        foreach ($this->rates->findDateOverrides() as $rule) {
            if ($this->dateInRule($ymd, $rule)) {
                return $this->rateResult($rule, 'date_override', $ymd);
            }
        }
        foreach ($this->rates->findSeasonal() as $rule) {
            if ($this->dateInRule($ymd, $rule) && $this->weekdayAllowed($weekday, $rule)) {
                return $this->rateResult($rule, 'seasonal', $ymd);
            }
        }

        $nightly = $this->matchingNightly($ymd, $weekday);
        if ($nightly !== null) {
            $weekend = $this->weekendModifier($weekday);
            if ($weekend !== null && isset($nightly['amount_cents'])) {
                $nightly['amount_cents'] = (int) $nightly['amount_cents'] + (int) $weekend['amount_cents'];
                $nightly['applied'][] = 'weekend_modifier';
            }
            return $nightly;
        }

        return null;
    }

    /**
     * @return array{ok: bool, errors: list<string>, nights?: int, season?: string, package?: ?string}
     */
    public function validateStayRules(string $checkIn, string $checkOut, int $guests): array
    {
        $errors = [];
        try {
            $range = DateRange::of($checkIn, $checkOut);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'errors' => [$e->getMessage()]];
        }
        $max = $this->settings->maxGuests();
        if ($guests < 1 || $guests > $max) {
            $errors[] = "Guests must be between 1 and {$max}";
        }
        $nights = $range->nights();
        foreach ($this->enabledStayConstraints() as $rule) {
            $min = $rule['min_nights'] !== null ? (int) $rule['min_nights'] : null;
            $maxN = $rule['max_nights'] !== null ? (int) $rule['max_nights'] : null;
            if ($min !== null && $nights < $min && ($rule['type'] ?? '') !== 'package') {
                $errors[] = "Minimum stay is {$min} nights";
            }
            if ($maxN !== null && $nights > $maxN && ($rule['type'] ?? '') !== 'package') {
                $errors[] = "Maximum stay is {$maxN} nights";
            }
        }
        $season = $this->seasonForStay($range);
        $package = $this->matchPackage($range, $season);
        $canNightly = $this->canPriceNightly($range);
        if ($package === null && !$canNightly) {
            $errors[] = 'Deze data passen niet bij een gepubliceerd arrangement (weekend, verlengd weekend, midweek of week) en er is geen nachttarief ingesteld.';
        }
        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'nights' => $nights,
            'season' => $season,
            'package' => $package['code'] ?? null,
        ];
    }

    /**
     * Server-side quote. Never trusts client totals.
     *
     * @return array<string, mixed>
     */
    public function calculateBookingPrice(string $checkIn, string $checkOut, int $guests, bool $sundayEveningExtra = false): array
    {
        $validation = $this->validateStayRules($checkIn, $checkOut, $guests);
        if (!$validation['ok']) {
            throw new ValidationException($validation['errors']);
        }
        $range = DateRange::of($checkIn, $checkOut);
        $nights = $range->nights();
        $season = $this->seasonForStay($range);

        $lineItems = [];
        $package = $this->matchPackage($range, $season === 'mixed' ? null : $season);
        $accommodation = 0;
        $packageCode = null;

        if ($package !== null) {
            $accommodation = (int) $package['amount_cents'];
            $packageCode = (string) $package['code'];
            $lineItems[] = [
                'code' => 'accommodation_' . $packageCode,
                'label' => $package['name'],
                'amount_cents' => $accommodation,
            ];
        } else {
            foreach ($range->nightsList() as $day) {
                $rate = $this->getRateForDate($day);
                if ($rate === null || !isset($rate['amount_cents'])) {
                    throw new ValidationException(['No rate for ' . $day]);
                }
                $accommodation += (int) $rate['amount_cents'];
                $lineItems[] = [
                    'code' => 'night_' . $day,
                    'label' => $day,
                    'amount_cents' => (int) $rate['amount_cents'],
                ];
            }
        }

        $feesByCode = [];
        foreach ($this->rates->findFees() as $fee) {
            $feesByCode[(string) $fee['code']] = $fee;
        }

        $cleaning = (int) ($feesByCode['cleaning']['amount_cents'] ?? $this->settings->getInt('cleaning_fee_cents'));
        $lineItems[] = ['code' => 'cleaning', 'label' => 'Eindschoonmaak', 'amount_cents' => $cleaning];

        $taxEach = (int) ($feesByCode['tourist_tax']['amount_cents'] ?? $this->settings->getInt('tourist_tax_per_person_per_night_cents'));
        $touristTax = $taxEach * $guests * $nights;
        $lineItems[] = ['code' => 'tourist_tax', 'label' => 'Toeristenbelasting', 'amount_cents' => $touristTax];

        $sundayCents = 0;
        if ($sundayEveningExtra) {
            $sundayCents = (int) ($feesByCode['sunday_evening']['amount_cents'] ?? $this->settings->getInt('sunday_evening_extra_cents'));
            $lineItems[] = ['code' => 'sunday_evening', 'label' => 'Zondagavond extra', 'amount_cents' => $sundayCents];
        }

        $extraFees = 0;
        $discount = 0;
        foreach ($this->rates->allEnabled() as $rule) {
            $type = (string) $rule['type'];
            if ($type === 'fee' && (string) $rule['calculation'] === 'percent_of_accommodation' && $rule['amount_percent'] !== null) {
                $amount = Money::percent($accommodation, (int) $rule['amount_percent']);
                $extraFees += $amount;
                $lineItems[] = ['code' => (string) $rule['code'], 'label' => (string) $rule['name'], 'amount_cents' => $amount];
            }
            if ($type === 'fee' && (string) $rule['calculation'] === 'fixed_per_stay' && !in_array($rule['code'], ['cleaning', 'sunday_evening', 'security_deposit'], true)) {
                $amount = (int) $rule['amount_cents'];
                $extraFees += $amount;
                $lineItems[] = ['code' => (string) $rule['code'], 'label' => (string) $rule['name'], 'amount_cents' => $amount];
            }
            if ($type === 'discount') {
                if ($rule['amount_cents'] !== null) {
                    $discount += (int) $rule['amount_cents'];
                    $lineItems[] = ['code' => (string) $rule['code'], 'label' => (string) $rule['name'], 'amount_cents' => -(int) $rule['amount_cents']];
                } elseif ($rule['amount_percent'] !== null) {
                    $amount = Money::percent($accommodation, (int) $rule['amount_percent']);
                    $discount += $amount;
                    $lineItems[] = ['code' => (string) $rule['code'], 'label' => (string) $rule['name'], 'amount_cents' => -$amount];
                }
            }
            if (!empty($rule['extra_guest_threshold']) && $guests > (int) $rule['extra_guest_threshold'] && $rule['extra_guest_cents'] !== null) {
                $extraCount = $guests - (int) $rule['extra_guest_threshold'];
                $amount = $extraCount * (int) $rule['extra_guest_cents'] * $nights;
                $extraFees += $amount;
                $lineItems[] = ['code' => 'extra_guest', 'label' => 'Extra guests', 'amount_cents' => $amount];
            }
        }

        $subtotal = $accommodation + $cleaning + $touristTax + $sundayCents + $extraFees - $discount;
        if ($subtotal < 0) {
            $subtotal = 0;
        }
        $percent = $this->settings->depositPercentage();
        $deposit = Money::percent($subtotal, $percent);
        $remaining = $subtotal - $deposit;
        $security = (int) ($feesByCode['security_deposit']['amount_cents'] ?? $this->settings->getInt('security_deposit_cents'));

        return [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'guests' => $guests,
            'season' => $season,
            'package' => $packageCode,
            'currency' => $this->settings->currency(),
            'line_items' => $lineItems,
            'accommodation_cents' => $accommodation,
            'cleaning_fee_cents' => $cleaning,
            'tourist_tax_cents' => $touristTax,
            'sunday_evening_cents' => $sundayCents,
            'extra_fees_cents' => $extraFees,
            'discount_cents' => $discount,
            'total_cents' => $subtotal,
            'deposit_percentage' => $percent,
            'deposit_cents' => $deposit,
            'remaining_cents' => $remaining,
            'security_deposit_cents' => $security,
            'deposit_deadline_days' => $this->settings->depositDeadlineDays(),
        ];
    }

    public function seasonForStay(DateRange $range): string
    {
        $seasons = [];
        foreach ($range->nightsList() as $day) {
            $seasons[$this->seasonForDate($day)] = true;
        }
        $keys = array_keys($seasons);
        if (count($keys) === 1) {
            return $keys[0];
        }
        return 'mixed';
    }

    private function matchPackage(DateRange $range, ?string $season): ?array
    {
        $nights = $range->nights();
        $inW = $range->weekdayOfStart();
        $outW = $range->weekdayOfEnd();
        $candidates = [];
        foreach ($this->rates->findPackages() as $rule) {
            if ((int) $rule['nights'] !== $nights) {
                continue;
            }
            if ((int) $rule['checkin_weekday'] !== $inW || (int) $rule['checkout_weekday'] !== $outW) {
                continue;
            }
            $candidates[] = $rule;
        }
        if ($candidates === []) {
            return null;
        }
        $prefer = $season === 'high' ? 'high' : 'low';
        foreach ($candidates as $rule) {
            if ((string) $rule['season'] === $prefer) {
                return $rule;
            }
        }
        return $candidates[0] ?? null;
    }

    private function canPriceNightly(DateRange $range): bool
    {
        foreach ($range->nightsList() as $day) {
            if ($this->getRateForDate($day) === null) {
                return false;
            }
        }
        return true;
    }

    private function dateInRule(string $ymd, array $rule): bool
    {
        $start = $rule['start_date'] ?? null;
        $end = $rule['end_date'] ?? null;
        if (!$start || !$end) {
            return false;
        }
        return $ymd >= $start && $ymd < $end;
    }

    private function weekdayAllowed(int $weekday, array $rule): bool
    {
        if (empty($rule['days_of_week'])) {
            return true;
        }
        $days = json_decode((string) $rule['days_of_week'], true);
        if (!is_array($days)) {
            return true;
        }
        return in_array($weekday, $days, true);
    }

    private function matchingNightly(string $ymd, int $weekday): ?array
    {
        foreach ($this->rates->findNightly() as $rule) {
            $season = $rule['season'] ?? null;
            if ($season && $season !== 'any' && $season !== $this->seasonForDate($ymd)) {
                continue;
            }
            if (!$this->weekdayAllowed($weekday, $rule)) {
                continue;
            }
            if ($rule['start_date'] && $rule['end_date'] && !$this->dateInRule($ymd, $rule)) {
                continue;
            }
            return $this->rateResult($rule, 'nightly', $ymd);
        }
        $fallback = $this->settings->get('default_nightly_cents');
        if ($fallback !== null && $fallback !== '') {
            return [
                'source' => 'default_nightly',
                'date' => $ymd,
                'amount_cents' => (int) $fallback,
                'applied' => ['settings.default_nightly_cents'],
            ];
        }
        $season = $this->seasonForDate($ymd);
        $implied = $this->published->impliedNightlyCents($ymd, $season === 'unspecified' ? 'low' : $season);
        if ($implied !== null) {
            return [
                'source' => 'implied_package_nightly',
                'date' => $ymd,
                'amount_cents' => $implied,
                'applied' => ['published_package_nightly'],
            ];
        }
        return null;
    }

    private function weekendModifier(int $weekday): ?array
    {
        if (!in_array($weekday, [5, 6], true)) {
            return null;
        }
        foreach ($this->rates->findByType('weekend') as $rule) {
            if ($rule['amount_cents'] !== null) {
                return $rule;
            }
        }
        return null;
    }

    private function rateResult(array $rule, string $source, string $ymd): array
    {
        return [
            'source' => $source,
            'date' => $ymd,
            'code' => $rule['code'] ?? null,
            'amount_cents' => isset($rule['amount_cents']) ? (int) $rule['amount_cents'] : null,
            'amount_percent' => isset($rule['amount_percent']) ? (int) $rule['amount_percent'] : null,
            'applied' => [$source],
            'priority' => (int) ($rule['priority'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function enabledStayConstraints(): array
    {
        $out = [];
        foreach ($this->rates->allEnabled() as $rule) {
            if ($rule['min_nights'] !== null || $rule['max_nights'] !== null) {
                if (in_array($rule['type'], ['nightly', 'seasonal', 'min_stay'], true)) {
                    $out[] = $rule;
                }
            }
        }
        return $out;
    }
}
