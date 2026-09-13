<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use Terboekt\Database;

final class AdminUserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM admin_users WHERE id = :id', ['id' => $id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM admin_users WHERE email = :email',
            ['email' => strtolower(trim($email))]
        );
    }

    public function create(string $email, string $passwordHash, string $role = 'owner', ?string $displayName = null): array
    {
        $now = $this->db->now();
        $this->db->query(
            'INSERT INTO admin_users (email, password_hash, role, display_name, is_active, created_at, updated_at)
             VALUES (:email, :password_hash, :role, :display_name, 1, :created_at, :updated_at)',
            [
                'email' => strtolower(trim($email)),
                'password_hash' => $passwordHash,
                'role' => $role,
                'display_name' => $displayName,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $row = $this->findById($this->db->lastInsertId());
        if ($row === null) {
            throw new \RuntimeException('Admin user insert failed');
        }
        return $row;
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->db->query(
            'UPDATE admin_users SET password_hash = :h, updated_at = :u WHERE id = :id',
            ['h' => $passwordHash, 'u' => $this->db->now(), 'id' => $id]
        );
    }

    public function touchLogin(int $id): void
    {
        $this->db->query(
            'UPDATE admin_users SET last_login_at = :t, updated_at = :t2 WHERE id = :id',
            ['t' => $this->db->now(), 't2' => $this->db->now(), 'id' => $id]
        );
    }

    public function setOtpEnabled(int $id, bool $enabled): void
    {
        $this->db->query(
            'UPDATE admin_users SET otp_enabled = :e, otp_code_hash = NULL, otp_expires_at = NULL, otp_attempts = 0, updated_at = :u WHERE id = :id',
            ['e' => $enabled ? 1 : 0, 'u' => $this->db->now(), 'id' => $id]
        );
    }

    public function storeOtp(int $id, string $hash, string $expiresAt): void
    {
        $this->db->query(
            'UPDATE admin_users SET otp_code_hash = :h, otp_expires_at = :e, otp_attempts = 0, updated_at = :u WHERE id = :id',
            ['h' => $hash, 'e' => $expiresAt, 'u' => $this->db->now(), 'id' => $id]
        );
    }

    public function incrementOtpAttempts(int $id): int
    {
        $this->db->query(
            'UPDATE admin_users SET otp_attempts = otp_attempts + 1, updated_at = :u WHERE id = :id',
            ['u' => $this->db->now(), 'id' => $id]
        );
        $row = $this->findById($id);
        return (int) ($row['otp_attempts'] ?? 0);
    }

    public function clearOtp(int $id): void
    {
        $this->db->query(
            'UPDATE admin_users SET otp_code_hash = NULL, otp_expires_at = NULL, otp_attempts = 0, updated_at = :u WHERE id = :id',
            ['u' => $this->db->now(), 'id' => $id]
        );
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT id, email, role, display_name, is_active, last_login_at, created_at FROM admin_users ORDER BY id');
    }
}
