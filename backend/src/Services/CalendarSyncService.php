<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\Config;
use Terboekt\Database;
use Terboekt\Http\HttpClient;
use Terboekt\Repositories\AvailabilityBlockRepository;
use Terboekt\Repositories\CalendarConnectionRepository;

final class CalendarSyncService
{
    public function __construct(
        private readonly Database $db,
        private readonly CalendarConnectionRepository $calendars,
        private readonly AvailabilityBlockRepository $blocks,
        private readonly IcalParser $parser,
        private readonly HttpClient $http,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getCalendarHealth(): array
    {
        $items = [];
        foreach ($this->calendars->all() as $c) {
            $items[] = $this->healthRow($c);
        }
        return ['connections' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshCalendarConnection(int $id): array
    {
        $connection = $this->calendars->findById($id);
        if ($connection === null) {
            throw new \Terboekt\Domain\NotFoundException('Calendar connection not found');
        }
        return $this->refresh($connection);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function refreshAllEnabledCalendars(): array
    {
        $out = [];
        foreach ($this->calendars->findEnabled() as $connection) {
            $out[] = $this->refresh($connection);
        }
        return $out;
    }

    /**
     * Failed fetch does not delete existing imported blocks (conservative / stale cache).
     *
     * @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    public function refresh(array $connection): array
    {
        $id = (int) $connection['id'];
        $now = $this->db->now();
        $this->calendars->update($id, ['last_sync_attempt_at' => $now]);

        $url = $this->resolveUrl($connection);
        if ($url === null || $url === '') {
            $this->calendars->update($id, [
                'last_error' => 'No iCal URL configured (set AIRBNB_ICAL_URL or connection.url)',
            ]);
            return $this->healthRow($this->calendars->findById($id) ?? $connection);
        }

        $response = $this->http->get($url);
        if (!$response->ok() || !str_contains($response->body, 'BEGIN:VCALENDAR')) {
            $message = $response->error !== ''
                ? $response->error
                : ('HTTP ' . $response->status . ' or invalid iCalendar body');
            $this->calendars->update($id, ['last_error' => $message]);
            return $this->healthRow($this->calendars->findById($id) ?? $connection);
        }

        try {
            $events = $this->parser->parse($response->body, $this->config->timezone);
        } catch (\Throwable $e) {
            $this->calendars->update($id, ['last_error' => 'Parse error: ' . $e->getMessage()]);
            return $this->healthRow($this->calendars->findById($id) ?? $connection);
        }

        $this->db->transaction(function () use ($id, $events, $now): void {
            $this->db->lockScheduling();
            $this->blocks->deleteImportedForConnection($id);
            foreach ($events as $event) {
                if ($event['end'] <= $event['start']) {
                    continue;
                }
                $this->blocks->insert([
                    'start_date' => $event['start'],
                    'end_date' => $event['end'],
                    'source' => 'ical',
                    'booking_id' => null,
                    'calendar_connection_id' => $id,
                    'external_uid' => $event['uid'],
                    'notes' => null,
                    'created_at' => $now,
                ]);
            }
            $this->calendars->update($id, [
                'last_successful_sync_at' => $now,
                'last_error' => null,
                'imported_event_count' => count($events),
            ]);
        });

        return $this->healthRow($this->calendars->findById($id) ?? $connection);
    }

    /**
     * Resolve feed URL. Env-backed connections never persist the secret URL.
     *
     * @param array<string, mixed> $connection
     */
    public function resolveUrl(array $connection): ?string
    {
        $envKey = $connection['url_env_key'] ?? null;
        if (is_string($envKey) && $envKey !== '') {
            $fromEnv = \Terboekt\Env::get($envKey);
            if ($fromEnv) {
                return $fromEnv;
            }
        }
        if ($connection['provider'] === 'airbnb') {
            return $this->config->airbnbIcalUrl;
        }
        $url = $connection['url'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @param array<string, mixed> $c */
    private function healthRow(array $c): array
    {
        $attempt = $c['last_sync_attempt_at'] ?? null;
        $success = $c['last_successful_sync_at'] ?? null;
        return [
            'id' => (int) $c['id'],
            'provider' => $c['provider'],
            'name' => $c['name'],
            'enabled' => (bool) $c['enabled'],
            'has_url' => $this->resolveUrl($c) !== null,
            'last_sync_attempt_at' => $attempt,
            'last_successful_sync_at' => $success,
            'last_error' => $c['last_error'],
            'imported_event_count' => (int) $c['imported_event_count'],
            'stale' => $attempt !== null && ($success === null || $success < $attempt),
        ];
    }
}
