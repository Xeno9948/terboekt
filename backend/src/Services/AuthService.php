<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\Config;
use Terboekt\Database;
use Terboekt\Domain\ValidationException;
use Terboekt\Repositories\AdminUserRepository;
use Terboekt\Security\AppKey;
use Terboekt\Security\Csrf;
use Terboekt\Security\RateLimiter;
use Terboekt\Security\SessionStore;

final class AuthService
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';

    public function __construct(
        private readonly Database $db,
        private readonly AdminUserRepository $admins,
        private readonly Config $config,
    ) {
    }

    public function startSession(): void
    {
        SessionStore::start($this->config);
    }

    /** @return array{ok: bool, user?: array, error?: string, retry_after?: int} */
    public function login(string $email, string $password, string $csrfToken): array
    {
        $this->startSession();
        if (!Csrf::verify($csrfToken)) {
            return ['ok' => false, 'error' => 'csrf'];
        }
        $limiter = new RateLimiter($this->db);
        $ip = SessionStore::clientIp();
        if (!$limiter->allow('admin_login', $ip, 5, 15 * 60)) {
            return ['ok' => false, 'error' => 'rate_limited', 'retry_after' => 900];
        }
        $user = $this->admins->findByEmail($email);
        $hash = $user['password_hash'] ?? '';
        $ok = is_string($hash) && $hash !== '' && password_verify($password, $hash);
        if (!$ok || !$user || !(int) $user['is_active']) {
            $limiter->hit('admin_login', $ip);
            return ['ok' => false, 'error' => 'invalid_credentials'];
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->query(
                'UPDATE admin_users SET password_hash = :h, updated_at = :u WHERE id = :id',
                ['h' => password_hash($password, PASSWORD_DEFAULT), 'u' => $this->db->now(), 'id' => (int) $user['id']]
            );
        }
        $this->admins->touchLogin((int) $user['id']);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['admin_role'] = (string) $user['role'];
        $_SESSION['admin_email'] = (string) $user['email'];
        Csrf::rotate();
        $limiter->clear('admin_login', $ip);
        return ['ok' => true, 'user' => $this->publicUser($user)];
    }

    public function logout(): void
    {
        $this->startSession();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            session_destroy();
        }
    }

    public function currentUser(): ?array
    {
        $this->startSession();
        $id = $_SESSION['admin_id'] ?? null;
        if (!is_int($id) && !is_numeric($id)) {
            return null;
        }
        $user = $this->admins->findById((int) $id);
        if ($user === null || !(int) $user['is_active']) {
            return null;
        }
        return $user;
    }

    public function requireUser(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            throw new \Terboekt\Domain\AuthRequiredException();
        }
        return $user;
    }

    public function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'display_name' => $user['display_name'],
        ];
    }

    public function createAdmin(string $email, string $password, string $role = self::ROLE_OWNER, ?string $name = null): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['Invalid email']);
        }
        if (strlen($password) < 8) {
            throw new ValidationException(['Password must be at least 8 characters']);
        }
        if (!in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN], true)) {
            throw new ValidationException(['Unknown role']);
        }
        $existing = $this->admins->findByEmail($email);
        if ($existing) {
            $this->admins->updatePassword((int) $existing['id'], password_hash($password, PASSWORD_DEFAULT));
            return $this->admins->findById((int) $existing['id']) ?? $existing;
        }
        return $this->admins->create($email, password_hash($password, PASSWORD_DEFAULT), $role, $name);
    }
}
