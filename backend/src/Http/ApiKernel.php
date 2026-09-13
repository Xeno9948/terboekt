<?php
declare(strict_types=1);

namespace Terboekt\Http;

use Terboekt\App;
use Terboekt\Domain\AuthRequiredException;
use Terboekt\Domain\BookingStatus;
use Terboekt\Domain\ConfirmationRequiresManagerException;
use Terboekt\Domain\ConflictException;
use Terboekt\Domain\IllegalTransitionException;
use Terboekt\Domain\NotFoundException;
use Terboekt\Domain\UnavailableException;
use Terboekt\Domain\ValidationException;
use Terboekt\Security\Csrf;
use Terboekt\Services\BookingRequestService;

final class ApiKernel
{
    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;

    public function __construct(private readonly App $app)
    {
    }

    public function handle(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        $path = $this->path();

        try {
            if ($method === 'GET' && $path === '/health') {
                JsonResponse::send(200, ['ok' => true, 'service' => 'terboekt-booking']);
            }
            if ($method === 'GET' && $path === '/rates') {
                JsonResponse::send(200, ['ok' => true, 'rates' => $this->app->publishedRates()->publicPayload()]);
            }
            if ($method === 'GET' && $path === '/availability') {
                $this->availability();
            }
            if ($method === 'GET' && $path === '/quote') {
                $this->quote();
            }
            if ($method === 'POST' && $path === '/bookings') {
                $this->createBooking();
            }
            if ($method === 'POST' && preg_match('#^/bookings/([^/]+)/confirm-transfer-intent$#', $path, $m)) {
                $this->confirmTransferIntent($m[1]);
            }
            if ($method === 'GET' && ($path === '/ical' || $path === '/calendar/unavailable.ics')) {
                $this->ical();
            }
            if (($method === 'GET' || $method === 'POST') && $path === '/cron/tick') {
                $this->cron();
            }

            if (str_starts_with($path, '/admin')) {
                $this->admin($method, $path);
            }

            JsonResponse::error(404, 'not_found', 'Unknown API route');
        } catch (AuthRequiredException) {
            JsonResponse::error(401, 'unauthorized', 'Admin authentication required');
        } catch (ValidationException $e) {
            JsonResponse::error(422, 'validation', $e->getMessage(), ['errors' => $e->errors]);
        } catch (IllegalTransitionException | ConfirmationRequiresManagerException $e) {
            JsonResponse::error(409, 'illegal_transition', $e->getMessage());
        } catch (UnavailableException | ConflictException $e) {
            JsonResponse::error(409, 'conflict', $e->getMessage());
        } catch (NotFoundException $e) {
            JsonResponse::error(404, 'not_found', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            JsonResponse::error(400, 'bad_request', $e->getMessage());
        } catch (\Throwable $e) {
            $message = $this->app->config->isProduction() ? 'Server error' : $e->getMessage();
            JsonResponse::error(500, 'server_error', $message);
        }
    }

    private function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = '/' . trim($uri, '/');
        if (str_starts_with($uri, '/api/')) {
            $uri = substr($uri, 4);
        } elseif ($uri === '/api') {
            $uri = '/';
        }
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($script !== '' && str_starts_with($uri, $script)) {
            $uri = substr($uri, strlen($script)) ?: '/';
        }
        if ($uri === '/index.php' || str_ends_with($uri, '/index.php')) {
            $uri = '/';
        }
        if (!str_starts_with($uri, '/')) {
            $uri = '/' . $uri;
        }
        return rtrim($uri, '/') === '' ? '/' : $uri;
    }

    private function jsonBody(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            $this->parsedBody = $_POST;
            return $this->parsedBody;
        }
        $data = json_decode($raw, true);
        $this->parsedBody = is_array($data) ? $data : [];
        return $this->parsedBody;
    }

    private function availability(): void
    {
        $from = (string) ($_GET['from'] ?? $_GET['checkin'] ?? '');
        $to = (string) ($_GET['to'] ?? $_GET['checkout'] ?? '');
        if ($from === '' || $to === '') {
            JsonResponse::error(400, 'bad_request', 'from and to (YYYY-MM-DD) are required');
        }
        $this->app->calendarSync()->refreshIfStale();
        JsonResponse::send(200, ['ok' => true] + $this->app->availability()->getAvailability($from, $to));
    }

    private function quote(): void
    {
        $checkIn = (string) ($_GET['checkin'] ?? $_GET['check_in'] ?? '');
        $checkOut = (string) ($_GET['checkout'] ?? $_GET['check_out'] ?? '');
        $guests = (int) ($_GET['guests'] ?? 0);
        $sunday = !empty($_GET['sunday_evening']);
        $quote = $this->app->pricing()->calculateBookingPrice($checkIn, $checkOut, $guests, $sunday);
        JsonResponse::send(200, ['ok' => true, 'quote' => $quote]);
    }

    private function createBooking(): void
    {
        $service = new BookingRequestService($this->app);
        $result = $service->createFromPublicForm($this->jsonBody() + $_POST);
        $booking = $result['booking'];
        JsonResponse::send(201, [
            'ok' => true,
            'id' => (int) $booking['id'],
            'reference' => $booking['reference'],
            'status' => $booking['status'],
            'check_in' => $booking['check_in'],
            'check_out' => $booking['check_out'],
            'total_cents' => (int) $booking['total_cents'],
            'deposit_cents' => (int) $booking['deposit_cents'],
            'remaining_cents' => (int) $booking['remaining_cents'],
            'currency' => $booking['currency'],
            'payment_due_at' => $booking['payment_due_at'] ?? $booking['deposit_due_at'] ?? null,
            'email' => [
                'logged' => true,
                'sent' => array_values(array_filter($result['emails'], static fn($e) => $e['sent'])),
            ],
        ]);
    }

    private function confirmTransferIntent(string $idOrRef): void
    {
        $view = $this->app->workflow()->confirmTransferIntent($idOrRef, $this->jsonBody() + $_POST);
        JsonResponse::send(200, ['ok' => true] + $view);
    }

    private function ical(): void
    {
        $provided = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_ICAL_TOKEN'] ?? '');
        if (!$this->app->icalExport()->tokenMatches($provided)) {
            JsonResponse::error(401, 'unauthorized', 'Invalid iCal export token');
        }
        $ics = $this->app->icalExport()->generatePrivateIcalFeed();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="unavailable.ics"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=300');
        echo $ics;
        exit;
    }

    private function cron(): void
    {
        $this->assertCronSecret();
        $expired = $this->app->expiry()->run();
        $sync = $this->app->calendarSync()->refreshAllEnabledCalendars();
        $warnings = [];
        foreach ($sync as $row) {
            if (!empty($row['last_error']) || !empty($row['stale'])) {
                $this->app->email()->sendTemplate('manager_calendar_sync_warning', $this->app->config->managerEmail, [
                    'error' => $row['last_error'] ?? 'stale',
                    'language' => 'nl',
                ]);
                $warnings[] = $row;
            }
        }
        JsonResponse::send(200, [
            'ok' => true,
            'expired' => $expired['expired'],
            'expired_bookings' => $expired['bookings'],
            'calendars' => $sync,
            'warnings' => count($warnings),
        ]);
    }

    private function admin(string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/admin/csrf') {
            $this->app->auth()->startSession();
            JsonResponse::send(200, ['ok' => true, 'csrf' => Csrf::token()]);
        }
        if ($method === 'POST' && $path === '/admin/login') {
            $body = $this->jsonBody();
            $result = $this->app->auth()->login(
                (string) ($body['email'] ?? ''),
                (string) ($body['password'] ?? ''),
                (string) ($body['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
            );
            if (!$result['ok']) {
                $code = $result['error'] === 'rate_limited' ? 429 : 401;
                JsonResponse::error($code, $result['error'] ?? 'unauthorized', 'Login failed');
            }
            JsonResponse::send(200, ['ok' => true, 'user' => $result['user'], 'csrf' => Csrf::token()]);
        }
        if ($method === 'POST' && $path === '/admin/logout') {
            $this->app->auth()->logout();
            JsonResponse::send(200, ['ok' => true]);
        }

        $user = $this->app->auth()->requireUser();
        if ($method !== 'GET') {
            $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $this->jsonBody()['csrf'] ?? '');
            $this->app->auth()->startSession();
            if (!Csrf::verify($token)) {
                JsonResponse::error(403, 'csrf', 'Invalid CSRF token');
            }
        }

        if ($method === 'GET' && $path === '/admin/me') {
            JsonResponse::send(200, ['ok' => true, 'user' => $this->app->auth()->publicUser($user), 'csrf' => Csrf::token()]);
        }
        if ($method === 'GET' && $path === '/admin/settings') {
            JsonResponse::send(200, ['ok' => true, 'settings' => $this->publicSettings()]);
        }
        if ($method === 'POST' && $path === '/admin/settings') {
            $body = $this->jsonBody();
            $allowed = ['bank_account_holder', 'bank_iban', 'bank_bic', 'bank_name', 'deposit_percentage', 'deposit_deadline_days'];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $body)) {
                    $this->app->settings()->upsert($key, $body[$key] === null ? null : (string) $body[$key]);
                }
            }
            JsonResponse::send(200, ['ok' => true, 'settings' => $this->publicSettings()]);
        }
        if ($method === 'GET' && ($path === '/admin/calendars' || $path === '/admin/calendars/health')) {
            JsonResponse::send(200, ['ok' => true] + $this->app->calendarSync()->getCalendarHealth());
        }
        if ($method === 'POST' && $path === '/admin/calendars/refresh') {
            JsonResponse::send(200, ['ok' => true, 'connections' => $this->app->calendarSync()->refreshAllEnabledCalendars()]);
        }
        if ($method === 'POST' && preg_match('#^/admin/calendars/(\d+)/refresh$#', $path, $m)) {
            JsonResponse::send(200, ['ok' => true, 'connection' => $this->app->calendarSync()->refreshCalendarConnection((int) $m[1])]);
        }
        if ($method === 'POST' && $path === '/admin/calendars') {
            $body = $this->jsonBody();
            $row = $this->app->calendars()->insert([
                'provider' => (string) ($body['provider'] ?? 'booking_com'),
                'name' => (string) ($body['name'] ?? 'Booking.com'),
                'type' => 'ical_import',
                'url' => $body['url'] ?? null,
                'url_env_key' => $body['url_env_key'] ?? null,
                'enabled' => empty($body['enabled']) ? 0 : 1,
            ]);
            JsonResponse::send(201, ['ok' => true, 'connection' => [
                'id' => (int) $row['id'],
                'provider' => $row['provider'],
                'name' => $row['name'],
                'enabled' => (bool) $row['enabled'],
            ]]);
        }
        if ($method === 'POST' && $path === '/admin/email/test') {
            if (!$this->app->email()->smtpConfigured()) {
                JsonResponse::send(200, ['ok' => true, 'sent' => false, 'reason' => 'smtp_not_configured']);
            }
            $to = (string) ($this->jsonBody()['to'] ?? $user['email']);
            $result = $this->app->email()->sendTemplate('test_email', $to, ['language' => 'nl']);
            JsonResponse::send(200, ['ok' => true] + $result);
        }
        if ($method === 'POST' && preg_match('#^/admin/email/(\d+)/retry$#', $path, $m)) {
            JsonResponse::send(200, ['ok' => true] + $this->app->email()->retry((int) $m[1]));
        }
        if ($method === 'GET' && $path === '/admin/bookings') {
            JsonResponse::send(200, ['ok' => true, 'bookings' => $this->app->bookings()->listRecent()]);
        }
        if ($method === 'GET' && preg_match('#^/admin/bookings/([^/]+)$#', $path, $m)) {
            $booking = $this->app->bookings()->findByIdOrReference($m[1]);
            if ($booking === null) {
                throw new NotFoundException('Booking not found');
            }
            JsonResponse::send(200, [
                'ok' => true,
                'booking' => $booking,
                'history' => $this->app->history()->forBooking((int) $booking['id']),
                'audit' => $this->app->audit()->forBooking((int) $booking['id']),
                'emails' => $this->app->emailLogs()->forBooking((int) $booking['id']),
            ]);
        }
        if ($method === 'POST' && preg_match('#^/admin/bookings/([^/]+)/confirm-deposit$#', $path, $m)) {
            $updated = $this->app->workflow()->confirmDeposit($m[1], $user, $this->jsonBody());
            JsonResponse::send(200, ['ok' => true, 'booking' => $updated]);
        }
        if ($method === 'POST' && preg_match('#^/admin/bookings/([^/]+)/reject$#', $path, $m)) {
            $updated = $this->app->workflow()->reject($m[1], $user, $this->jsonBody());
            JsonResponse::send(200, ['ok' => true, 'booking' => $updated]);
        }
        if ($method === 'POST' && preg_match('#^/admin/bookings/([^/]+)/cancel$#', $path, $m)) {
            $updated = $this->app->workflow()->cancel($m[1], $user, $this->jsonBody());
            JsonResponse::send(200, ['ok' => true, 'booking' => $updated]);
        }
        if ($method === 'POST' && preg_match('#^/admin/bookings/([^/]+)/extend-deadline$#', $path, $m)) {
            $updated = $this->app->workflow()->extendDeadline($m[1], $user, $this->jsonBody());
            JsonResponse::send(200, ['ok' => true, 'booking' => $updated]);
        }
        if ($method === 'POST' && preg_match('#^/admin/bookings/([^/]+)/release-hold$#', $path, $m)) {
            $updated = $this->app->workflow()->releaseHold($m[1], $user, $this->jsonBody());
            JsonResponse::send(200, ['ok' => true, 'booking' => $updated]);
        }
        if ($method === 'POST' && preg_match('#^/admin/bookings/([^/]+)/transition$#', $path, $m)) {
            $body = $this->jsonBody();
            $to = strtoupper((string) ($body['status'] ?? $body['to'] ?? ''));
            $reason = isset($body['reason']) ? (string) $body['reason'] : null;
            $updated = $this->app->status()->transition(
                $m[1],
                $to,
                'admin',
                (string) $user['email'],
                $reason,
                $to === BookingStatus::REJECTED ? ['rejection_reason' => $reason] : ($to === BookingStatus::CANCELLED ? ['cancellation_reason' => $reason] : [])
            );
            (new BookingRequestService($this->app))->notifyTransition($updated, $to, $reason);
            JsonResponse::send(200, ['ok' => true, 'booking' => $updated]);
        }
        if ($method === 'PATCH' && preg_match('#^/admin/bookings/([^/]+)$#', $path, $m)) {
            $updated = $this->app->workflow()->patch($m[1], $user, $this->jsonBody());
            JsonResponse::send(200, ['ok' => true, 'booking' => $updated]);
        }
        if ($method === 'POST' && $path === '/admin/availability-blocks') {
            $body = $this->jsonBody();
            $start = (string) ($body['start'] ?? $body['start_date'] ?? '');
            $end = (string) ($body['end'] ?? $body['end_date'] ?? '');
            $row = $this->app->db->transaction(function () use ($start, $end, $body) {
                $this->app->db->lockScheduling();
                $this->app->availability()->assertRangeAvailable($start, $end, null, false);
                return $this->app->blocks()->insert([
                    'start_date' => $start,
                    'end_date' => $end,
                    'source' => 'manual',
                    'notes' => $body['notes'] ?? null,
                ]);
            });
            JsonResponse::send(201, ['ok' => true, 'block' => $row]);
        }

        JsonResponse::error(404, 'not_found', 'Unknown admin route');
    }

    private function publicSettings(): array
    {
        $all = $this->app->settings()->all();
        return $all;
    }

    private function assertCronSecret(): void
    {
        $secret = $this->app->config->cronSecret;
        $provided = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
        if ($secret === null || $secret === '' || !hash_equals($secret, $provided)) {
            JsonResponse::error(401, 'unauthorized', 'Invalid cron token');
        }
    }
}
