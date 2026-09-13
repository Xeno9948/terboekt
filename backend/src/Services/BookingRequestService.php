<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\App;
use Terboekt\Domain\BookingStatus;
use Terboekt\Domain\ValidationException;

final class BookingRequestService
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Public booking request. Field names match #booking-form.
     * Status is always REQUESTED. Email failure does not roll back the booking.
     *
     * @param array<string, mixed> $input
     * @return array{booking: array<string, mixed>, emails: list<array<string, mixed>>}
     */
    public function createFromPublicForm(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $checkIn = trim((string) ($input['checkin'] ?? $input['check_in'] ?? ''));
        $checkOut = trim((string) ($input['checkout'] ?? $input['check_out'] ?? ''));
        $guests = (int) ($input['guests'] ?? 0);
        $message = trim((string) ($input['message'] ?? ''));
        $rules = $input['rules'] ?? false;
        $language = (string) ($input['language'] ?? 'nl');
        $sunday = !empty($input['sunday_evening']);

        unset(
            $input['total_cents'],
            $input['deposit_cents'],
            $input['remaining_cents'],
            $input['accommodation_cents'],
            $input['cleaning_fee_cents'],
            $input['tourist_tax_cents'],
            $input['sunday_evening_cents'],
            $input['extra_fees_cents'],
            $input['discount_cents'],
            $input['security_deposit_cents'],
            $input['total'],
            $input['deposit'],
            $input['price'],
            $input['status']
        );

        $errors = [];
        if ($name === '') {
            $errors[] = 'name is required';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'valid email is required';
        }
        if (!$rules) {
            $errors[] = 'rules must be accepted';
        }
        if (!in_array($language, ['nl', 'en', 'fr', 'de'], true)) {
            $language = 'nl';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $quote = $this->app->pricing()->calculateBookingPrice($checkIn, $checkOut, $guests, $sunday);

        $booking = $this->app->availability()->createBookingHoldWithinTransaction(
            [
                'guest_name' => $name,
                'guest_email' => $email,
                'guest_phone' => $phone !== '' ? $phone : null,
                'guest_message' => $message !== '' ? $message : null,
                'language' => $language,
                'guests' => $guests,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nights' => $quote['nights'],
                'sunday_evening_extra' => $sunday ? 1 : 0,
                'source' => 'website',
                'package_code' => $quote['package'],
                'season' => $quote['season'],
                'accommodation_cents' => $quote['accommodation_cents'],
                'cleaning_fee_cents' => $quote['cleaning_fee_cents'],
                'tourist_tax_cents' => $quote['tourist_tax_cents'],
                'sunday_evening_cents' => $quote['sunday_evening_cents'],
                'extra_fees_cents' => $quote['extra_fees_cents'],
                'discount_cents' => $quote['discount_cents'],
                'total_cents' => $quote['total_cents'],
                'deposit_cents' => $quote['deposit_cents'],
                'remaining_cents' => $quote['remaining_cents'],
                'security_deposit_cents' => $quote['security_deposit_cents'],
                'currency' => $quote['currency'],
                'pricing_snapshot' => json_encode($quote, JSON_UNESCAPED_UNICODE),
            ],
            function (array $created): void {
                $this->app->status()->recordCreated($created, 'guest', (string) $created['guest_email']);
            }
        );

        $vars = [
            'language' => $language,
            'reference' => $booking['reference'],
            'guest_name' => $name,
            'guest_email' => $email,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => $guests,
            'deposit_cents' => $quote['deposit_cents'],
            'total_cents' => $quote['total_cents'],
            'remaining_cents' => $quote['remaining_cents'],
        ];
        $emails = [];
        $emails[] = $this->app->email()->sendTemplate('booking_request_received', $email, $vars, (int) $booking['id']);
        $emails[] = $this->app->email()->sendTemplate(
            'manager_new_booking_request',
            $this->app->email()->managerEmail(),
            $vars,
            (int) $booking['id']
        );

        return ['booking' => $booking, 'emails' => $emails];
    }

    public function notifyTransition(array $booking, string $to, ?string $reason = null): void
    {
        $vars = [
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
            'reason' => $reason ?? '',
        ];
        $guestTo = (string) $booking['guest_email'];
        $id = (int) $booking['id'];
        match ($to) {
            BookingStatus::AWAITING_DEPOSIT => $this->app->email()->sendTemplate('bank_transfer_instructions', $guestTo, $vars, $id),
            BookingStatus::CONFIRMED => $this->app->email()->sendTemplate('booking_confirmed', $guestTo, $vars, $id),
            BookingStatus::REJECTED => $this->app->email()->sendTemplate('booking_rejected', $guestTo, $vars, $id),
            BookingStatus::EXPIRED => $this->app->email()->sendTemplate('booking_expired', $guestTo, $vars, $id),
            BookingStatus::CANCELLED => $this->app->email()->sendTemplate('booking_cancelled', $guestTo, $vars, $id),
            'changed' => $this->app->email()->sendTemplate('booking_changed', $guestTo, $vars, $id),
            default => null,
        };
    }
}
