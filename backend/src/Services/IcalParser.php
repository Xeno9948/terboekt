<?php
declare(strict_types=1);

namespace Terboekt\Services;

use DateTimeImmutable;
use DateTimeZone;
use Terboekt\DateRange;

final class IcalParser
{
    /**
     * @return list<array{uid: string, start: string, end: string, summary: string}>
     */
    public function parse(string $ics, string $timezone = 'Europe/Brussels'): array
    {
        $unfolded = $this->unfold($ics);
        $events = [];
        $chunks = preg_split('/BEGIN:VEVENT\s*/i', $unfolded) ?: [];
        array_shift($chunks);
        foreach ($chunks as $chunk) {
            $endPos = stripos($chunk, 'END:VEVENT');
            if ($endPos === false) {
                continue;
            }
            $body = substr($chunk, 0, $endPos);
            $props = $this->properties($body);
            $dtStart = $props['DTSTART'] ?? null;
            if ($dtStart === null) {
                continue;
            }
            $start = $this->toDate($dtStart, $timezone);
            $dtEnd = $props['DTEND'] ?? null;
            if ($dtEnd !== null) {
                $end = $this->toDate($dtEnd, $timezone);
            } else {
                $end = DateRange::addDays($start, 1);
            }
            if ($end <= $start) {
                $end = DateRange::addDays($start, 1);
            }
            $events[] = [
                'uid' => $props['UID'] ?? ('anon-' . sha1($start . $end . ($props['SUMMARY'] ?? ''))),
                'start' => $start,
                'end' => $end,
                'summary' => $props['SUMMARY'] ?? '',
            ];
        }
        return $events;
    }

    private function unfold(string $ics): string
    {
        $ics = str_replace(["\r\n", "\r"], "\n", $ics);
        return preg_replace("/\n[ \t]/", '', $ics) ?? $ics;
    }

    /** @return array<string, string> */
    private function properties(string $body): array
    {
        $out = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$namePart, $value] = explode(':', $line, 2);
            $name = strtoupper(explode(';', $namePart)[0]);
            $out[$name] = $value;
            $out[$name . '_RAW'] = $line;
        }
        return $out;
    }

    private function toDate(string $value, string $timezone): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        $tz = new DateTimeZone($timezone);
        if (str_ends_with($value, 'Z')) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
            if ($dt) {
                return $dt->setTimezone($tz)->format('Y-m-d');
            }
        }
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz);
        if ($dt) {
            return $dt->format('Y-m-d');
        }
        $dt = date_create($value);
        if ($dt) {
            return (new DateTimeImmutable($dt->format('c')))->setTimezone($tz)->format('Y-m-d');
        }
        throw new \InvalidArgumentException('Unparseable iCal date: ' . $value);
    }
}
