<?php
declare(strict_types=1);

use Terboekt\App;
use Terboekt\Database;
use Terboekt\Migrator;
use Terboekt\Money;
use Terboekt\Domain\BookingStatus;
use Terboekt\Domain\IllegalTransitionException;
use Terboekt\Domain\ConfirmationRequiresManagerException;
use Terboekt\Domain\ValidationException;
use Terboekt\Http\HttpClient;
use Terboekt\Http\HttpResponse;
use Terboekt\Services\CalendarSyncService;
use Terboekt\Services\IcalParser;
use Terboekt\Services\BookingRequestService;

putenv('DATABASE_URL=sqlite::memory:');
putenv('APP_ENV=test');
putenv('APP_BASE_URL=http://localhost:8080');
putenv('ICAL_EXPORT_SECRET=test-ical-secret');
putenv('AIRBNB_ICAL_URL=https://example.test/airbnb.ics');
putenv('SMTP_HOST=');
putenv('SMTP_USERNAME=');
putenv('SMTP_PASSWORD=');
$_ENV['DATABASE_URL'] = 'sqlite::memory:';
$_ENV['APP_ENV'] = 'test';
$_ENV['APP_BASE_URL'] = 'http://localhost:8080';
$_ENV['ICAL_EXPORT_SECRET'] = 'test-ical-secret';
$_ENV['AIRBNB_ICAL_URL'] = 'https://example.test/airbnb.ics';
$_ENV['SMTP_HOST'] = '';
$_ENV['SMTP_USERNAME'] = '';
$_ENV['SMTP_PASSWORD'] = '';

$config = require dirname(__DIR__) . '/bootstrap.php';

final class TestFailure extends RuntimeException
{
}

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new TestFailure($msg);
    }
}

function assert_same(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected !== $actual) {
        throw new TestFailure($msg . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function bootApp(): App
{
    global $config;
    $app = new App($config, new Database($config));
    (new Migrator($app->db))->migrate();
    return $app;
}

function fridayIn(string $monthStart): DateTimeImmutable
{
    $d = new DateTimeImmutable($monthStart);
    while ((int) $d->format('w') !== 5) {
        $d = $d->modify('+1 day');
    }
    return $d;
}

$passed = 0;
$failed = 0;
$tests = [];

$tests['migrations run on fresh sqlite'] = function (): void {
    $app = bootApp();
    $tables = $app->db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    $names = array_column($tables, 'name');
    foreach (['bookings', 'rate_rules', 'availability_blocks', 'calendar_connections', 'booking_status_history', 'email_logs', 'property_settings', 'admin_users', 'occupancy_nights', 'booking_audit_log', 'availability_locks'] as $table) {
        assert_true(in_array($table, $names, true), 'missing table ' . $table);
    }
    assert_same('30', $app->settings()->get('deposit_percentage'), 'deposit percentage');
    assert_same('7', $app->settings()->get('deposit_deadline_days'), 'deposit deadline');
    assert_same('EUR', $app->settings()->currency(), 'currency');
    $airbnb = $app->calendars()->findByProvider('airbnb');
    assert_true($airbnb !== null && (int) $airbnb['enabled'] === 1, 'Airbnb connection seeded');
    assert_true($airbnb['url'] === null || $airbnb['url'] === '', 'Airbnb URL must not be stored');
    assert_same('AIRBNB_ICAL_URL', $airbnb['url_env_key'], 'Airbnb url from env key');
};

$tests['all statuses persist and illegal transitions rejected'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-04-01');
    $checkIn = $fri->format('Y-m-d');
    $checkOut = $fri->modify('+2 days')->format('Y-m-d');
    $booking = $app->availability()->createBookingHoldWithinTransaction([
        'guest_name' => 'Test Guest',
        'guest_email' => 'guest@example.com',
        'guest_phone' => null,
        'guest_message' => null,
        'language' => 'nl',
        'guests' => 4,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => 2,
        'sunday_evening_extra' => 0,
        'source' => 'test',
        'package_code' => 'weekend',
        'season' => 'high',
        'accommodation_cents' => 90000,
        'cleaning_fee_cents' => 10000,
        'tourist_tax_cents' => 1200,
        'sunday_evening_cents' => 0,
        'extra_fees_cents' => 0,
        'discount_cents' => 0,
        'total_cents' => 101200,
        'deposit_cents' => 30360,
        'remaining_cents' => 70840,
        'security_deposit_cents' => 50000,
        'currency' => 'EUR',
        'pricing_snapshot' => '{}',
    ], function (array $created) use ($app): void {
        $app->status()->recordCreated($created, 'system');
    });
    assert_same(BookingStatus::REQUESTED, $booking['status'], 'initial status');
    assert_true(preg_match('/^BOOK-[A-Z2-9]{6}$/', (string) $booking['reference']) === 1, 'human-readable reference');

    $awaiting = $app->status()->transition((int) $booking['id'], BookingStatus::AWAITING_DEPOSIT, 'admin', 'owner@test');
    assert_same(BookingStatus::AWAITING_DEPOSIT, $awaiting['status'], 'awaiting');
    assert_true(!empty($awaiting['deposit_due_at']), 'deposit due set');

    try {
        $app->status()->transition((int) $booking['id'], BookingStatus::CONFIRMED, 'system');
        throw new TestFailure('system must not confirm');
    } catch (ConfirmationRequiresManagerException) {
    }

    $confirmed = $app->status()->transition((int) $booking['id'], BookingStatus::CONFIRMED, 'admin', 'owner@test');
    assert_same(BookingStatus::CONFIRMED, $confirmed['status'], 'confirmed by admin');

    try {
        $app->status()->transition((int) $booking['id'], BookingStatus::REQUESTED, 'admin', 'owner@test');
        throw new TestFailure('CONFIRMED → REQUESTED must fail');
    } catch (IllegalTransitionException) {
    }

    $cancelled = $app->status()->transition((int) $booking['id'], BookingStatus::CANCELLED, 'admin', 'owner@test', 'guest cancelled');
    assert_same(BookingStatus::CANCELLED, $cancelled['status'], 'cancelled');
    try {
        $app->status()->transition((int) $booking['id'], BookingStatus::CONFIRMED, 'admin', 'owner@test');
        throw new TestFailure('CANCELLED is terminal');
    } catch (IllegalTransitionException) {
    }

    $history = $app->history()->forBooking((int) $booking['id']);
    assert_true(count($history) >= 4, 'audit history recorded');
};

$tests['booking references unique'] = function (): void {
    $app = bootApp();
    $seen = [];
    for ($i = 0; $i < 20; $i++) {
        $ref = $app->bookings()->generateReference();
        assert_true(!isset($seen[$ref]), 'duplicate reference ' . $ref);
        $seen[$ref] = true;
    }
};

$tests['money uses integer cents'] = function (): void {
    assert_same(30720, Money::percent(102400, 30), '30% of 102400');
    assert_same(80000, Money::fromEuroString('800'), '800 euros');
    assert_same(150, Money::fromEuroString('1.50'), '1.50 euros');
    assert_same(150, Money::fromEuroString('1,50'), '1,50 euros');
};

$tests['pricing engine published packages and 30% deposit'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-04-01');
    $checkIn = $fri->format('Y-m-d');
    $checkOut = $fri->modify('+2 days')->format('Y-m-d');
    $quote = $app->pricing()->calculateBookingPrice($checkIn, $checkOut, 8, false);
    assert_same('weekend', $quote['package'], 'package match');
    assert_same('high', $quote['season'], 'april is high');
    assert_same(90000, $quote['accommodation_cents'], 'high weekend');
    assert_same(10000, $quote['cleaning_fee_cents'], 'cleaning');
    assert_same(150 * 8 * 2, $quote['tourist_tax_cents'], 'tourist tax');
    $total = 90000 + 10000 + 2400;
    assert_same($total, $quote['total_cents'], 'total');
    assert_same(Money::percent($total, 30), $quote['deposit_cents'], '30% deposit');
    assert_same($total - $quote['deposit_cents'], $quote['remaining_cents'], 'remaining');
    assert_same(50000, $quote['security_deposit_cents'], 'waarborg separate');
};

$tests['october without override is unspecified'] = function (): void {
    $app = bootApp();
    $d = new DateTimeImmutable('2026-10-02');
    while ((int) $d->format('w') !== 5) {
        $d = $d->modify('+1 day');
    }
    $checkIn = $d->format('Y-m-d');
    $checkOut = $d->modify('+2 days')->format('Y-m-d');
    $quote = $app->pricing()->calculateBookingPrice($checkIn, $checkOut, 4);
    assert_same('weekend', $quote['package'], 'october weekend still matches package');
    assert_same(80000, $quote['accommodation_cents'], 'october uses low-season package amount');
};

$tests['ical parser and conservative failed sync'] = function (): void {
    $app = bootApp();
    $ics = (string) file_get_contents(TERBOEKT_BACKEND . '/tests/fixtures/airbnb-sample.ics');
    $events = (new IcalParser())->parse($ics);
    assert_same(2, count($events), 'two events');
    assert_same('2026-07-01', $events[0]['start'], 'start date');
    assert_same('2026-07-08', $events[0]['end'], 'exclusive end');

    $fakeOk = new class ($ics) implements HttpClient {
        public function __construct(private string $ics)
        {
        }
        public function get(string $url, int $timeoutSeconds = 20): HttpResponse
        {
            return new HttpResponse(200, $this->ics);
        }
    };
    $sync = new CalendarSyncService($app->db, $app->calendars(), $app->blocks(), new IcalParser(), $fakeOk, $app->config);
    $airbnb = $app->calendars()->findByProvider('airbnb');
    $result = $sync->refreshCalendarConnection((int) $airbnb['id']);
    assert_true($result['imported_event_count'] >= 1, 'imported');
    $count = $app->blocks()->countImported((int) $airbnb['id']);
    assert_true($count >= 1, 'blocks stored');

    $fakeFail = new class implements HttpClient {
        public function get(string $url, int $timeoutSeconds = 20): HttpResponse
        {
            return new HttpResponse(500, '', 'upstream down');
        }
    };
    $syncFail = new CalendarSyncService($app->db, $app->calendars(), $app->blocks(), new IcalParser(), $fakeFail, $app->config);
    $after = $syncFail->refreshCalendarConnection((int) $airbnb['id']);
    assert_true($after['stale'] === true || $after['last_error'] !== null, 'failure recorded');
    assert_same($count, $app->blocks()->countImported((int) $airbnb['id']), 'stale blocks kept');
};

$tests['private ical export has no PII'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-05-01');
    $booking = $app->availability()->createBookingHoldWithinTransaction([
        'guest_name' => 'Secret Guest',
        'guest_email' => 'secret.guest@example.com',
        'guest_phone' => '+3200000000',
        'guest_message' => 'Do not leak',
        'language' => 'nl',
        'guests' => 2,
        'check_in' => $fri->format('Y-m-d'),
        'check_out' => $fri->modify('+2 days')->format('Y-m-d'),
        'nights' => 2,
        'sunday_evening_extra' => 0,
        'source' => 'test',
        'package_code' => 'weekend',
        'season' => 'high',
        'accommodation_cents' => 90000,
        'cleaning_fee_cents' => 10000,
        'tourist_tax_cents' => 600,
        'sunday_evening_cents' => 0,
        'extra_fees_cents' => 0,
        'discount_cents' => 0,
        'total_cents' => 100600,
        'deposit_cents' => 30180,
        'remaining_cents' => 70420,
        'security_deposit_cents' => 50000,
        'currency' => 'EUR',
        'pricing_snapshot' => '{}',
    ], function (array $created) use ($app): void {
        $app->status()->recordCreated($created);
    });
    $feed = $app->icalExport()->generatePrivateIcalFeed();
    assert_true(str_contains($feed, 'SUMMARY:Unavailable'), 'summary');
    assert_true(!str_contains($feed, 'Secret Guest'), 'no name');
    assert_true(!str_contains($feed, 'secret.guest@example.com'), 'no email');
    assert_true(!str_contains($feed, '+3200000000'), 'no phone');
    assert_true(!str_contains($feed, 'Do not leak'), 'no notes');
    assert_true(!str_contains(strtolower($feed), 'smtp'), 'no smtp');
    assert_true(str_contains($feed, 'BOOK-'), 'booking uid');
    $loopStart = (clone $fri)->modify('+10 days')->format('Y-m-d');
    $loopEnd = (clone $fri)->modify('+12 days')->format('Y-m-d');
    $ownerStart = (clone $fri)->modify('+20 days')->format('Y-m-d');
    $ownerEnd = (clone $fri)->modify('+22 days')->format('Y-m-d');
    $app->blocks()->insert([
        'start_date' => $loopStart,
        'end_date' => $loopEnd,
        'source' => 'ical',
        'external_uid' => 'airbnb-loop-test',
        'notes' => 'Airbnb imported — must not re-export',
    ]);
    $app->blocks()->insert([
        'start_date' => $ownerStart,
        'end_date' => $ownerEnd,
        'source' => 'owner',
    ]);
    $feed2 = $app->icalExport()->generatePrivateIcalFeed();
    assert_true(!str_contains($feed2, 'Airbnb imported'), 'no imported notes');
    assert_true(!str_contains($feed2, 'airbnb-loop-test'), 'no imported uid');
    assert_true(str_contains($feed2, 'block-owner-'), 'owner block exported');
    assert_true(str_contains($feed2, 'LAST-MODIFIED:'), 'airbnb-friendly last-modified');
};

$tests['email logs without sending when SMTP missing'] = function (): void {
    $app = bootApp();
    $result = $app->email()->sendTemplate('test_email', 'owner@example.com', ['language' => 'nl']);
    assert_same(false, $result['sent'], 'not sent');
    assert_same('smtp_not_configured', $result['error'], 'reason');
    $row = $app->emailLogs()->findById($result['id']);
    assert_same('failed', $row['status'], 'logged failure');
};

$tests['admin password hashing'] = function (): void {
    $app = bootApp();
    $user = $app->auth()->createAdmin('owner@hometerboekt.be', 'super-secret-pass', 'owner');
    assert_true(password_verify('super-secret-pass', (string) $user['password_hash']), 'hash verifies');
    assert_true($user['password_hash'] !== 'super-secret-pass', 'not stored plaintext');
};

$tests['booking persist independent of email'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-06-01');
    $service = new BookingRequestService($app);
    $result = $service->createFromPublicForm([
        'name' => 'Ann Guest',
        'email' => 'ann@example.com',
        'phone' => '',
        'checkin' => $fri->format('Y-m-d'),
        'checkout' => $fri->modify('+2 days')->format('Y-m-d'),
        'guests' => 8,
        'message' => '',
        'rules' => true,
        'language' => 'nl',
    ]);
    assert_same(BookingStatus::REQUESTED, $result['booking']['status'], 'requested only');
    assert_true($result['booking']['reference'] !== '', 'has reference');
    assert_true(!$result['emails'][0]['sent'], 'email not required for persist');
};

$tests['overlap hold rejects second booking'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-09-01');
    $fields = [
        'guest_name' => 'A',
        'guest_email' => 'a@example.com',
        'language' => 'nl',
        'guests' => 2,
        'check_in' => $fri->format('Y-m-d'),
        'check_out' => $fri->modify('+2 days')->format('Y-m-d'),
        'nights' => 2,
        'sunday_evening_extra' => 0,
        'source' => 'test',
        'accommodation_cents' => 90000,
        'cleaning_fee_cents' => 10000,
        'tourist_tax_cents' => 600,
        'sunday_evening_cents' => 0,
        'extra_fees_cents' => 0,
        'discount_cents' => 0,
        'total_cents' => 100600,
        'deposit_cents' => 30180,
        'remaining_cents' => 70420,
        'security_deposit_cents' => 50000,
        'currency' => 'EUR',
    ];
    $app->availability()->createBookingHoldWithinTransaction($fields, function (array $created) use ($app): void {
        $app->status()->recordCreated($created);
    });
    try {
        $fields['guest_email'] = 'b@example.com';
        $app->availability()->createBookingHoldWithinTransaction($fields, function (): void {
        });
        throw new TestFailure('overlap must fail');
    } catch (\Terboekt\Domain\UnavailableException) {
    }
};

$tests['adjacent stays share checkout morning'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-09-01');
    $sun = $fri->modify('+2 days');
    $tue = $fri->modify('+4 days');
    $fields = testHoldFields($fri->format('Y-m-d'), $sun->format('Y-m-d'), 'adj-a@example.com');
    $app->availability()->createBookingHoldWithinTransaction($fields, function (array $created) use ($app): void {
        $app->status()->recordCreated($created);
    });
    $fields['guest_email'] = 'adj-b@example.com';
    $fields['check_in'] = $sun->format('Y-m-d');
    $fields['check_out'] = $tue->format('Y-m-d');
    $second = $app->availability()->createBookingHoldWithinTransaction($fields, function (array $created) use ($app): void {
        $app->status()->recordCreated($created);
    });
    assert_same(\Terboekt\Domain\BookingStatus::REQUESTED, $second['status'], 'adjacent hold allowed');
};

$tests['stale ical sync does not treat dates as free'] = function (): void {
    $app = bootApp();
    $airbnb = $app->calendars()->findByProvider('airbnb');
    assert_true($airbnb !== null, 'airbnb seeded');
    $app->calendars()->update((int) $airbnb['id'], [
        'last_sync_attempt_at' => '2026-09-13 12:00:00',
        'last_successful_sync_at' => '2026-09-01 12:00:00',
        'last_error' => 'HTTP 500',
    ]);
    $fri = fridayIn('2026-08-01');
    assert_true(!$app->availability()->isRangeAvailable($fri->format('Y-m-d'), $fri->modify('+2 days')->format('Y-m-d')), 'stale is unavailable');
    $payload = $app->availability()->getAvailability($fri->format('Y-m-d'), $fri->modify('+2 days')->format('Y-m-d'));
    assert_same(false, $payload['calendar_reliable'], 'calendar_reliable flag');
    try {
        $app->availability()->createBookingHoldWithinTransaction(
            testHoldFields($fri->format('Y-m-d'), $fri->modify('+2 days')->format('Y-m-d'), 'stale@example.com'),
            function (): void {
            }
        );
        throw new TestFailure('stale calendar must reject new holds');
    } catch (\Terboekt\Domain\UnavailableException) {
    }
};

$tests['guest supplied prices are ignored'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-06-01');
    $service = new BookingRequestService($app);
    $result = $service->createFromPublicForm([
        'name' => 'Price Guest',
        'email' => 'price@example.com',
        'checkin' => $fri->format('Y-m-d'),
        'checkout' => $fri->modify('+2 days')->format('Y-m-d'),
        'guests' => 8,
        'rules' => true,
        'language' => 'nl',
        'total_cents' => 1,
        'deposit_cents' => 1,
        'status' => 'CONFIRMED',
    ]);
    assert_same(\Terboekt\Domain\BookingStatus::REQUESTED, $result['booking']['status'], 'never auto-confirmed');
    assert_true((int) $result['booking']['total_cents'] > 1, 'server price used');
    assert_true(!empty($result['booking']['payment_due_at']), 'payment_due_at set at create');
    $created = new DateTimeImmutable((string) $result['booking']['created_at']);
    $due = new DateTimeImmutable((string) $result['booking']['payment_due_at']);
    assert_same(7, (int) $created->diff($due)->format('%a'), '7 day payment deadline');
};

$tests['illegal transitions include requested to confirmed'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-04-01');
    $booking = $app->availability()->createBookingHoldWithinTransaction(
        testHoldFields($fri->format('Y-m-d'), $fri->modify('+2 days')->format('Y-m-d'), 'illegal@example.com'),
        function (array $created) use ($app): void {
            $app->status()->recordCreated($created);
        }
    );
    try {
        $app->status()->transition((int) $booking['id'], BookingStatus::CONFIRMED, 'admin', 'owner@test');
        throw new TestFailure('REQUESTED → CONFIRMED must fail');
    } catch (IllegalTransitionException) {
    }
    try {
        $app->status()->transition((int) $booking['id'], BookingStatus::CONFIRMED, 'guest', (string) $booking['guest_email']);
        throw new TestFailure('guest must not confirm');
    } catch (ConfirmationRequiresManagerException | IllegalTransitionException) {
    }
    $app->status()->transition((int) $booking['id'], BookingStatus::AWAITING_DEPOSIT, 'guest', (string) $booking['guest_email']);
    try {
        $app->status()->transition((int) $booking['id'], BookingStatus::CONFIRMED, 'system');
        throw new TestFailure('system must not confirm');
    } catch (ConfirmationRequiresManagerException) {
    }
};

$tests['confirm transfer intent hides other bookings'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-07-01');
    $service = new BookingRequestService($app);
    $a = $service->createFromPublicForm([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'checkin' => $fri->format('Y-m-d'),
        'checkout' => $fri->modify('+2 days')->format('Y-m-d'),
        'guests' => 2,
        'rules' => true,
    ]);
    $later = $fri->modify('+7 days');
    $b = $service->createFromPublicForm([
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'checkin' => $later->format('Y-m-d'),
        'checkout' => $later->modify('+2 days')->format('Y-m-d'),
        'guests' => 2,
        'rules' => true,
    ]);
    try {
        $app->workflow()->confirmTransferIntent($a['booking']['reference'], ['email' => 'bob@example.com']);
        throw new TestFailure('email mismatch must 404');
    } catch (\Terboekt\Domain\NotFoundException) {
    }
    $view = $app->workflow()->confirmTransferIntent($a['booking']['reference'], ['email' => 'ada@example.com']);
    assert_same(BookingStatus::AWAITING_DEPOSIT, $view['status'], 'guest intent');
    assert_same($a['booking']['reference'], $view['reference'], 'same booking');
    assert_true(!isset($view['guest_email']), 'no extra PII in public view');
    assert_true(!isset($view['manager_notes']), 'no manager notes');
    assert_true(!str_contains(json_encode($view), 'bob@example.com'), 'no other guest email');
    $other = $app->bookings()->findById((int) $b['booking']['id']);
    assert_same(BookingStatus::REQUESTED, $other['status'], 'other booking untouched');
};

$tests['expiry is idempotent and releases occupancy'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-05-01');
    $booking = $app->availability()->createBookingHoldWithinTransaction(
        testHoldFields($fri->format('Y-m-d'), $fri->modify('+2 days')->format('Y-m-d'), 'expire@example.com'),
        function (array $created) use ($app): void {
            $app->status()->recordCreated($created);
        }
    );
    assert_true($app->occupancy()->nightsForBooking((int) $booking['id']) !== [], 'occupancy reserved');
    $app->bookings()->update((int) $booking['id'], [
        'payment_due_at' => '2020-01-01 00:00:00',
        'deposit_due_at' => '2020-01-01 00:00:00',
    ]);
    $first = $app->expiry()->run();
    assert_same(1, $first['expired'], 'first run expires one');
    $row = $app->bookings()->findById((int) $booking['id']);
    assert_same(BookingStatus::EXPIRED, $row['status'], 'expired');
    assert_same([], $app->occupancy()->nightsForBooking((int) $booking['id']), 'occupancy released');
    assert_true($app->availability()->isRangeAvailable($fri->format('Y-m-d'), $fri->modify('+2 days')->format('Y-m-d')), 'dates free after expiry');
    $second = $app->expiry()->run();
    assert_same(0, $second['expired'], 'second run is a no-op');
    $history = $app->history()->forBooking((int) $booking['id']);
    $toExpired = array_values(array_filter($history, static fn(array $h) => $h['to_status'] === BookingStatus::EXPIRED && $h['from_status'] === BookingStatus::REQUESTED));
    assert_same(1, count($toExpired), 'single expire audit');
    $emails = $app->emailLogs()->forBooking((int) $booking['id']);
    $expiredMails = array_values(array_filter($emails, static fn(array $e) => $e['template_key'] === 'booking_expired'));
    assert_true(count($expiredMails) >= 1, 'guest expiry email logged');
};

$tests['admin confirm-deposit reject cancel extend and patch'] = function (): void {
    $app = bootApp();
    $fri = fridayIn('2026-04-01');
    $booking = $app->availability()->createBookingHoldWithinTransaction(
        testHoldFields($fri->format('Y-m-d'), $fri->modify('+2 days')->format('Y-m-d'), 'flow@example.com'),
        function (array $created) use ($app): void {
            $app->status()->recordCreated($created);
        }
    );
    $admin = ['email' => 'owner@hometerboekt.be'];
    $app->workflow()->confirmTransferIntent($booking['reference'], ['email' => 'flow@example.com']);
    $extended = $app->workflow()->extendDeadline($booking['reference'], $admin, ['days' => 3]);
    assert_true($extended['payment_due_at'] > $booking['payment_due_at'], 'deadline moved');
    $confirmed = $app->workflow()->confirmDeposit($booking['reference'], $admin, ['reason' => 'IBAN match']);
    assert_same(BookingStatus::CONFIRMED, $confirmed['status'], 'admin confirmed');
    $nextFri = $fri->modify('+14 days');
    $patched = $app->workflow()->patch($booking['reference'], $admin, [
        'checkin' => $nextFri->format('Y-m-d'),
        'checkout' => $nextFri->modify('+2 days')->format('Y-m-d'),
        'guests' => 3,
        'manager_notes' => 'Moved after phone call',
        'discount_cents' => 1000,
    ]);
    assert_same(3, (int) $patched['guests'], 'guest count');
    assert_same($nextFri->format('Y-m-d'), $patched['check_in'], 'dates moved');
    assert_same('Moved after phone call', $patched['manager_notes'], 'notes');
    $cancelled = $app->workflow()->cancel($booking['reference'], $admin, ['reason' => 'guest cancelled']);
    assert_same(BookingStatus::CANCELLED, $cancelled['status'], 'cancelled');
    assert_same([], $app->occupancy()->nightsForBooking((int) $booking['id']), 'hold released');
};

$tests['concurrent overlapping holds only one succeeds'] = function (): void {
    $path = sys_get_temp_dir() . '/terboekt-occ-' . bin2hex(random_bytes(4)) . '.sqlite';
    putenv('DATABASE_URL=sqlite:' . $path);
    $_ENV['DATABASE_URL'] = 'sqlite:' . $path;
    $config = Terboekt\Config::fromEnv();
    $app = new App($config, new Database($config));
    (new Migrator($app->db))->migrate();

    $fri = fridayIn('2026-08-01');
    $checkIn = $fri->format('Y-m-d');
    $checkOut = $fri->modify('+2 days')->format('Y-m-d');
    $worker = dirname(__DIR__) . '/tests/concurrent_hold_worker.php';
    $cmd = static function (string $email) use ($path, $checkIn, $checkOut, $worker): string {
        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' '
            . escapeshellarg($path) . ' ' . escapeshellarg($checkIn) . ' '
            . escapeshellarg($checkOut) . ' ' . escapeshellarg($email);
    };

    $specs = [
        ['cmd' => $cmd('race-a@example.com'), 'pipes' => []],
        ['cmd' => $cmd('race-b@example.com'), 'pipes' => []],
    ];
    $procs = [];
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    foreach ($specs as $i => $spec) {
        $procs[$i] = proc_open($spec['cmd'], $descriptors, $specs[$i]['pipes']);
    }
    $codes = [];
    $outputs = [];
    foreach ($procs as $i => $proc) {
        $outputs[$i] = stream_get_contents($specs[$i]['pipes'][1]);
        fclose($specs[$i]['pipes'][1]);
        fclose($specs[$i]['pipes'][2]);
        $codes[$i] = proc_close($proc);
    }

    putenv('DATABASE_URL=sqlite::memory:');
    $_ENV['DATABASE_URL'] = 'sqlite::memory:';
    @unlink($path);

    $ok = count(array_filter($codes, static fn($c) => $c === 0));
    $blocked = count(array_filter($codes, static fn($c) => $c === 2));
    assert_same(1, $ok, 'exactly one hold succeeded, outputs=' . json_encode($outputs) . ' codes=' . json_encode($codes));
    assert_same(1, $blocked, 'exactly one hold was rejected as unavailable');
};

function testHoldFields(string $checkIn, string $checkOut, string $email): array
{
    return [
        'guest_name' => 'Test Guest',
        'guest_email' => $email,
        'guest_phone' => null,
        'guest_message' => null,
        'language' => 'nl',
        'guests' => 2,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => 2,
        'sunday_evening_extra' => 0,
        'source' => 'test',
        'package_code' => 'weekend',
        'season' => 'high',
        'accommodation_cents' => 90000,
        'cleaning_fee_cents' => 10000,
        'tourist_tax_cents' => 600,
        'sunday_evening_cents' => 0,
        'extra_fees_cents' => 0,
        'discount_cents' => 0,
        'total_cents' => 100600,
        'deposit_cents' => 30180,
        'remaining_cents' => 70420,
        'security_deposit_cents' => 50000,
        'currency' => 'EUR',
        'pricing_snapshot' => '{}',
    ];
}

foreach ($tests as $name => $fn) {
    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDOUT, "FAIL  {$name}\n  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n");
    }
}

fwrite(STDOUT, "\n{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
