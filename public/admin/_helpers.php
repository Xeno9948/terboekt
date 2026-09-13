<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/_boot.php';

use Terboekt\App;
use Terboekt\Domain\BookingStatus;
use Terboekt\Money;
use Terboekt\Security\Csrf;

/**
 * @return array{0: App, 1: array<string, mixed>}
 */
function admin_require(): array
{
    $app = terboekt_boot();
    $user = $app->auth()->currentUser();
    if ($user === null) {
        header('Location: /admin/', true, 302);
        exit;
    }
    return [$app, $user];
}

function h(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(Csrf::token()) . '">';
}

function admin_verify_csrf(): bool
{
    return Csrf::verify(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null);
}

function admin_is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function admin_set_flash(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

/** @return array{type: string, message: string}|null */
function admin_take_flash(): ?array
{
    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($flash) && isset($flash['type'], $flash['message']) ? $flash : null;
}

function admin_redirect(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

function admin_money(int $cents): string
{
    return Money::formatEuro($cents);
}

function admin_euro_value(?int $cents): string
{
    if ($cents === null) {
        return '';
    }
    return Money::toEuroString($cents);
}

function admin_parse_euros(?string $raw): ?int
{
    if ($raw === null) {
        return null;
    }
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    return Money::fromEuroString($raw);
}

function admin_today(App $app): string
{
    $tz = $app->settings()->get('timezone', 'Europe/Brussels') ?: 'Europe/Brussels';
    return \Terboekt\DateRange::today($tz);
}

function admin_status_label(string $status): string
{
    return match ($status) {
        BookingStatus::REQUESTED => 'Aanvraag',
        BookingStatus::AWAITING_DEPOSIT => 'Wacht op voorschot',
        BookingStatus::CONFIRMED => 'Bevestigd',
        BookingStatus::REJECTED => 'Geweigerd',
        BookingStatus::EXPIRED => 'Verlopen',
        BookingStatus::CANCELLED => 'Geannuleerd',
        default => $status,
    };
}

function admin_status_class(string $status): string
{
    return match ($status) {
        BookingStatus::REQUESTED => 'is-pending',
        BookingStatus::AWAITING_DEPOSIT => 'is-awaiting',
        BookingStatus::CONFIRMED => 'is-confirmed',
        BookingStatus::REJECTED, BookingStatus::CANCELLED => 'is-negative',
        BookingStatus::EXPIRED => 'is-muted',
        default => 'is-muted',
    };
}

function admin_package_label(string $code): string
{
    return match ($code) {
        'weekend' => 'Weekend (vr–zo)',
        'extended' => 'Verlengd weekend (vr–ma)',
        'midweek' => 'Midweek (ma–vr)',
        'week' => 'Week (vr–vr)',
        default => $code,
    };
}

function admin_season_label(?string $season): string
{
    return match ($season) {
        'high' => 'Hoogseizoen',
        'low' => 'Laagseizoen',
        'unspecified' => 'Niet bepaald (oktober)',
        'mixed' => 'Gemengd',
        default => $season ?: '—',
    };
}

function admin_block_label(string $source, ?string $provider = null): string
{
    if ($source === 'ical' || $source === 'airbnb' || $source === 'booking_com') {
        $provider = $provider ?: $source;
        return match ($provider) {
            'airbnb' => 'Airbnb',
            'booking_com' => 'Booking.com',
            default => 'Externe kalender',
        };
    }
    return match ($source) {
        'booking' => 'Directe boeking',
        'manual' => 'Handmatig geblokkeerd',
        'owner' => 'Eigenaar',
        'maintenance' => 'Onderhoud',
        default => $source,
    };
}

function admin_format_dt(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('d-m-Y H:i', $ts);
}

function admin_format_date(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('d-m-Y', $ts);
}

function admin_handle_action_error(\Throwable $e): void
{
    if ($e instanceof \Terboekt\Domain\ValidationException) {
        admin_set_flash('error', implode(' ', $e->errors) !== '' ? implode(' ', $e->errors) : $e->getMessage());
        return;
    }
    if ($e instanceof \Terboekt\Domain\IllegalTransitionException) {
        admin_set_flash('error', 'Deze statuswijziging is niet mogelijk voor deze boeking.');
        return;
    }
    if ($e instanceof \Terboekt\Domain\ConfirmationRequiresManagerException) {
        admin_set_flash('error', 'Een boeking kan alleen bevestigd worden door de beheerder, na een duidelijke actie.');
        return;
    }
    if ($e instanceof \Terboekt\Domain\UnavailableException) {
        admin_set_flash('error', 'Die data zijn niet vrij.');
        return;
    }
    if ($e instanceof \InvalidArgumentException) {
        admin_set_flash('error', $e->getMessage());
        return;
    }
    admin_set_flash('error', 'Er ging iets mis. Probeer opnieuw.');
}

/** @param list<string> $columns */
function admin_update_row(App $app, string $table, int $id, array $fields, array $columns): void
{
    $sets = [];
    $params = ['id' => $id];
    foreach ($fields as $key => $value) {
        if (!in_array($key, $columns, true)) {
            continue;
        }
        $sets[] = "{$key} = :{$key}";
        $params[$key] = $value;
    }
    if ($sets === []) {
        return;
    }
    $app->db->query('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
}

function admin_booking_vars(array $booking): array
{
    return [
        'language' => $booking['language'] ?? 'nl',
        'reference' => $booking['reference'],
        'guest_name' => $booking['guest_name'],
        'guest_email' => $booking['guest_email'],
        'check_in' => $booking['check_in'],
        'check_out' => $booking['check_out'],
        'guests' => $booking['guests'],
        'deposit_cents' => $booking['deposit_cents'],
        'total_cents' => $booking['total_cents'],
        'remaining_cents' => $booking['remaining_cents'],
        'deposit_due_at' => $booking['deposit_due_at'] ?? '',
        'reason' => $booking['rejection_reason'] ?? $booking['cancellation_reason'] ?? '',
    ];
}
