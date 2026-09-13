<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use Terboekt\Database;

final class BookingStatusHistoryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function record(
        int $bookingId,
        ?string $from,
        string $to,
        string $actorType,
        ?string $actorId = null,
        ?string $reason = null,
    ): void {
        $this->db->query(
            'INSERT INTO booking_status_history (
                booking_id, from_status, to_status, actor_type, actor_id, reason, created_at
            ) VALUES (
                :booking_id, :from_status, :to_status, :actor_type, :actor_id, :reason, :created_at
            )',
            [
                'booking_id' => $bookingId,
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
                'created_at' => $this->db->now(),
            ]
        );
    }

    /** @return list<array<string, mixed>> */
    public function forBooking(int $bookingId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM booking_status_history WHERE booking_id = :id ORDER BY created_at ASC, id ASC',
            ['id' => $bookingId]
        );
    }
}
