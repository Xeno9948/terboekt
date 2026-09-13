<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\Domain\BookingStatus;
use Terboekt\Repositories\AvailabilityBlockRepository;
use Terboekt\Repositories\BookingRepository;
use Terboekt\Repositories\PropertySettingsRepository;

final class IcalExportService
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly AvailabilityBlockRepository $blocks,
        private readonly PropertySettingsRepository $settings,
        private readonly ?string $envExportSecret,
    ) {
    }

    /**
     * Private feed: confirmed + active pending + admin blocks.
     * SUMMARY is always "Unavailable". No guest PII, prices, notes, bank, or SMTP.
     * Imported Airbnb/Booking.com events are excluded so the feed can be imported back into Airbnb.
     */
    public function generatePrivateIcalFeed(): string
    {
        $now = gmdate('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Home Terboekt//Private Availability//NL',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Home Terboekt Unavailable',
            'X-WR-TIMEZONE:Europe/Brussels',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];

        $horizonStart = '2000-01-01';
        $horizonEnd = '2100-01-01';

        foreach ($this->bookings->findOverlapping($horizonStart, $horizonEnd, BookingStatus::ACTIVE) as $booking) {
            $updated = (string) ($booking['updated_at'] ?? $booking['created_at'] ?? '');
            $uid = 'booking-' . $booking['reference'] . '@hometerboekt.be';
            $lines = array_merge($lines, $this->vevent(
                $uid,
                (string) $booking['check_in'],
                (string) $booking['check_out'],
                $now,
                $updated
            ));
        }
        foreach ($this->blocks->findForOutboundExport() as $block) {
            $updated = (string) ($block['updated_at'] ?? $block['created_at'] ?? '');
            $uid = 'block-' . $block['source'] . '-' . $block['id'] . '@hometerboekt.be';
            $lines = array_merge($lines, $this->vevent(
                $uid,
                (string) $block['start_date'],
                (string) $block['end_date'],
                $now,
                $updated
            ));
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    public function eventCount(): int
    {
        $feed = $this->generatePrivateIcalFeed();
        return substr_count($feed, 'BEGIN:VEVENT');
    }

    public function resolveSecret(): string
    {
        $fromSettings = $this->settings->get('ical_export_secret');
        if ($fromSettings !== null && $fromSettings !== '') {
            return $fromSettings;
        }
        if ($this->envExportSecret !== null && $this->envExportSecret !== '') {
            return $this->envExportSecret;
        }
        $generated = bin2hex(random_bytes(24));
        $this->settings->upsert('ical_export_secret', $generated);
        return $generated;
    }

    public function rotateSecret(): string
    {
        $generated = bin2hex(random_bytes(24));
        $this->settings->upsert('ical_export_secret', $generated);
        return $generated;
    }

    public function tokenMatches(string $provided): bool
    {
        $secret = $this->resolveSecret();
        return $secret !== '' && hash_equals($secret, $provided);
    }

    /** @return list<string> */
    private function vevent(string $uid, string $start, string $end, string $stamp, string $updatedAt): array
    {
        $modified = $this->icalStamp($updatedAt) ?? $stamp;
        return [
            'BEGIN:VEVENT',
            'UID:' . $this->escape($uid),
            'DTSTAMP:' . $stamp,
            'LAST-MODIFIED:' . $modified,
            'DTSTART;VALUE=DATE:' . str_replace('-', '', $start),
            'DTEND;VALUE=DATE:' . str_replace('-', '', $end),
            'SUMMARY:Unavailable',
            'TRANSP:OPAQUE',
            'STATUS:CONFIRMED',
            'END:VEVENT',
        ];
    }

    private function icalStamp(string $datetime): ?string
    {
        if ($datetime === '') {
            return null;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return null;
        }
        return gmdate('Ymd\THis\Z', $ts);
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $value);
    }
}
