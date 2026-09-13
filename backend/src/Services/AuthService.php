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

    public const OTP_TTL_SECONDS = 600;
    public const OTP_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $db,
        private readonly AdminUserRepository $admins,
        private readonly Config $config,
        private readonly EmailService $email,
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
        $limiter->clear('admin_login', $ip);
        if ($this->otpEnabled($user)) {
            $issued = $this->issueOtp($user);
            if (!$issued['ok']) {
                return ['ok' => false, 'error' => $issued['error'] ?? 'otp_send_failed'];
            }
            session_regenerate_id(true);
            $_SESSION['admin_otp_pending_id'] = (int) $user['id'];
            unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_email']);
            Csrf::rotate();
            return ['ok' => true, 'needs_otp' => true, 'email' => $this->maskEmail((string) $user['email'])];
        }
        $this->completeLogin($user);
        return ['ok' => true, 'user' => $this->publicUser($user)];
    }

    /** @return array{ok: bool, user?: array, error?: string, retry_after?: int} */
    public function verifyOtp(string $code, string $csrfToken): array
    {
        $this->startSession();
        if (!Csrf::verify($csrfToken)) {
            return ['ok' => false, 'error' => 'csrf'];
        }
        $pendingId = $this->pendingOtpUserId();
        if ($pendingId === null) {
            return ['ok' => false, 'error' => 'otp_not_pending'];
        }
        $limiter = new RateLimiter($this->db);
        $ip = SessionStore::clientIp();
        if (!$limiter->allow('admin_otp', $ip, 10, 15 * 60)) {
            return ['ok' => false, 'error' => 'rate_limited', 'retry_after' => 900];
        }
        $user = $this->admins->findById($pendingId);
        if ($user === null || !(int) $user['is_active'] || !$this->otpEnabled($user)) {
            unset($_SESSION['admin_otp_pending_id']);
            return ['ok' => false, 'error' => 'otp_not_pending'];
        }
        $expires = (string) ($user['otp_expires_at'] ?? '');
        $hash = (string) ($user['otp_code_hash'] ?? '');
        $normalized = preg_replace('/\s+/', '', trim($code)) ?? '';
        $expiresAt = $expires !== '' ? strtotime($expires . ' UTC') : false;
        $valid = $hash !== ''
            && $expiresAt !== false
            && $expiresAt >= time()
            && hash_equals($hash, $this->otpHash($normalized, (int) $user['id']));
        if (!$valid) {
            $limiter->hit('admin_otp', $ip);
            $attempts = $this->admins->incrementOtpAttempts((int) $user['id']);
            if ($attempts >= self::OTP_MAX_ATTEMPTS) {
                $this->admins->clearOtp((int) $user['id']);
                unset($_SESSION['admin_otp_pending_id']);
                return ['ok' => false, 'error' => 'otp_locked'];
            }
            return ['ok' => false, 'error' => 'invalid_otp'];
        }
        $this->admins->clearOtp((int) $user['id']);
        unset($_SESSION['admin_otp_pending_id']);
        $this->completeLogin($user);
        $limiter->clear('admin_otp', $ip);
        return ['ok' => true, 'user' => $this->publicUser($user)];
    }

    /** @return array{ok: bool, error?: string, email?: string} */
    public function resendOtp(string $csrfToken): array
    {
        $this->startSession();
        if (!Csrf::verify($csrfToken)) {
            return ['ok' => false, 'error' => 'csrf'];
        }
        $pendingId = $this->pendingOtpUserId();
        if ($pendingId === null) {
            return ['ok' => false, 'error' => 'otp_not_pending'];
        }
        $limiter = new RateLimiter($this->db);
        $ip = SessionStore::clientIp();
        if (!$limiter->allow('admin_otp_resend', $ip, 3, 15 * 60)) {
            return ['ok' => false, 'error' => 'rate_limited'];
        }
        $user = $this->admins->findById($pendingId);
        if ($user === null || !$this->otpEnabled($user)) {
            return ['ok' => false, 'error' => 'otp_not_pending'];
        }
        $limiter->hit('admin_otp_resend', $ip);
        $issued = $this->issueOtp($user);
        if (!$issued['ok']) {
            return ['ok' => false, 'error' => $issued['error'] ?? 'otp_send_failed'];
        }
        return ['ok' => true, 'email' => $this->maskEmail((string) $user['email'])];
    }

    public function pendingOtp(): bool
    {
        $this->startSession();
        return $this->pendingOtpUserId() !== null;
    }

    /**
     * @param array<string, mixed> $actor
     * @return array{ok: bool, error?: string}
     */
    public function setOtpEnabledFor(array $actor, bool $enabled, string $password): array
    {
        $hash = (string) ($actor['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return ['ok' => false, 'error' => 'invalid_password'];
        }
        if ($enabled && !$this->email->mailConfigured() && $this->config->appEnv !== 'test') {
            return ['ok' => false, 'error' => 'mail_not_configured'];
        }
        $this->admins->setOtpEnabled((int) $actor['id'], $enabled);
        return ['ok' => true];
    }

    /** @param array<string, mixed> $user */
    public function otpEnabled(array $user): bool
    {
        return (int) ($user['otp_enabled'] ?? 0) === 1;
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

    /** @param array<string, mixed> $user */
    private function completeLogin(array $user): void
    {
        $this->admins->touchLogin((int) $user['id']);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['admin_role'] = (string) $user['role'];
        $_SESSION['admin_email'] = (string) $user['email'];
        unset($_SESSION['admin_otp_pending_id']);
        Csrf::rotate();
    }

    /**
     * @param array<string, mixed> $user
     * @return array{ok: bool, error?: string}
     */
    private function issueOtp(array $user): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = gmdate('Y-m-d H:i:s', time() + self::OTP_TTL_SECONDS);
        $this->admins->storeOtp((int) $user['id'], $this->otpHash($code, (int) $user['id']), $expires);
        $result = $this->email->sendTemplate('admin_otp', (string) $user['email'], [
            'language' => 'nl',
            'otp_code' => $code,
            'guest_name' => (string) ($user['display_name'] ?: 'beheerder'),
        ]);
        if (!$result['sent'] && $this->config->appEnv !== 'test') {
            return ['ok' => false, 'error' => 'otp_send_failed'];
        }
        return ['ok' => true];
    }

    private function otpHash(string $code, int $userId): string
    {
        $secret = AppKey::get() ?: hash('sha256', $this->config->databaseUrl . '|admin-otp');
        return hash_hmac('sha256', $userId . ':' . $code, $secret);
    }

    private function pendingOtpUserId(): ?int
    {
        $id = $_SESSION['admin_otp_pending_id'] ?? null;
        if (!is_int($id) && !is_numeric($id)) {
            return null;
        }
        return (int) $id;
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return $email;
        }
        $name = substr($email, 0, $at);
        $domain = substr($email, $at);
        $keep = min(2, max(1, strlen($name) - 1));
        return substr($name, 0, $keep) . '•••' . $domain;
    }
}
