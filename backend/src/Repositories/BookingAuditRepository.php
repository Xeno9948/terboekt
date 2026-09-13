<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use Terboekt\Database;

final class BookingAuditRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public function record(
        int $bookingId,
        string $actorType,
        ?string $actorId,
        string $action,
        ?array $details = null,
    ): void {
        $this->db->query(
            'INSERT INTO booking_audit_log (
                booking_id, actor_type, actor_id, action, details, created_at
            ) VALUES (
                :booking_id, :actor_type, :actor_id, :action, :details, :created_at
            )',
            [
                'booking_id' => $bookingId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'details' => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at' => $this->db->now(),
            ]
        );
    }

    /** @return list<array<string, mixed>> */
    public function forBooking(int $bookingId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM booking_audit_log WHERE booking_id = :id ORDER BY created_at ASC, id ASC',
            ['id' => $bookingId]
        );
    }
}
