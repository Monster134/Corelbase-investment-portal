<?php
/**
 * CORELBASE PRIVATE LIMITED — AUTHENTICATION & AUTHORIZATION MANAGER
 * -----------------------------------------------------------------------------
 * Handles:
 * - Admin login with password_verify(), account lockout & IP throttling
 * - Admin session validation & role checks
 * - Investor portal authentication (Application ID / Email + Password)
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

class Auth
{
    /**
     * Authenticate Administrator account.
     *
     * @return array{success:bool, message:string, admin?:array}
     */
    public static function adminLogin(PDO $pdo, string $identifier, string $password): array
    {
        Security::initSecureSession();
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            return ['success' => false, 'message' => 'Username/Email and password are required.'];
        }

        $maxAttempts = (int)getSetting('max_login_attempts', '5', $pdo);
        $lockoutMinutes = (int)getSetting('lockout_duration_minutes', '15', $pdo);

        if (Security::isRateLimited($pdo, $identifier, 'admin_login', $maxAttempts, $lockoutMinutes)) {
            self::logAdminEvent($pdo, null, $identifier, 'LOGIN_THROTTLED', 'Rate limit exceeded');
            return [
                'success' => false,
                'message' => "Too many failed login attempts. Please wait {$lockoutMinutes} minutes before retrying."
            ];
        }

        $stmt = $pdo->prepare("
            SELECT * FROM `admin_users`
            WHERE (`username` = :ident OR LOWER(`email`) = LOWER(:ident2))
            LIMIT 1
        ");
        $stmt->execute([':ident' => $identifier, ':ident2' => $identifier]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            Security::recordFailedAttempt($pdo, $identifier, 'admin_login');
            self::logAdminEvent($pdo, null, $identifier, 'LOGIN_FAILED', 'Account not found');
            return ['success' => false, 'message' => 'Invalid credentials.'];
        }

        if ((int)$admin['is_active'] !== 1) {
            return ['success' => false, 'message' => 'This administrator account has been disabled.'];
        }

        if (!empty($admin['locked_until']) && strtotime($admin['locked_until'] . ' UTC') > time()) {
            return ['success' => false, 'message' => 'Account is temporarily locked due to failed login attempts.'];
        }

        if (!password_verify($password, (string)$admin['password_hash'])) {
            Security::recordFailedAttempt($pdo, $identifier, 'admin_login');
            $failedCount = (int)$admin['failed_login_attempts'] + 1;
            $lockedUntil = ($failedCount >= $maxAttempts)
                ? gmdate('Y-m-d H:i:s', time() + ($lockoutMinutes * 60))
                : null;

            $upd = $pdo->prepare("
                UPDATE `admin_users`
                SET `failed_login_attempts` = :fc, `locked_until` = :lu
                WHERE `id` = :id
            ");
            $upd->execute([':fc' => $failedCount, ':lu' => $lockedUntil, ':id' => $admin['id']]);

            self::logAdminEvent($pdo, (int)$admin['id'], $admin['email'], 'LOGIN_FAILED', "Invalid password (attempt #{$failedCount})");
            return ['success' => false, 'message' => 'Invalid credentials.'];
        }

        // Rehash if needed
        if (password_needs_rehash((string)$admin['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE `admin_users` SET `password_hash` = :h WHERE `id` = :id")
                ->execute([':h' => $newHash, ':id' => $admin['id']]);
        }

        // Clear failed attempts and update login metadata
        Security::clearFailedAttempts($pdo, $identifier, 'admin_login');
        $ip = Security::getClientIp();
        $pdo->prepare("
            UPDATE `admin_users`
            SET `failed_login_attempts` = 0, `locked_until` = NULL,
                `last_login_at` = UTC_TIMESTAMP(), `last_login_ip` = :ip
            WHERE `id` = :id
        ")->execute([':ip' => $ip, ':id' => $admin['id']]);

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_name'] = $admin['full_name'];
        $_SESSION['admin_role'] = $admin['role'];
        $_SESSION['_last_activity'] = time();

        self::logAdminEvent($pdo, (int)$admin['id'], $admin['email'], 'LOGIN_SUCCESS', 'Authenticated into Admin Portal');
        logAuditAction($pdo, 'Admin Login', 'ADMIN', (string)$admin['id'], (int)$admin['id'], $admin['email']);

        return [
            'success' => true,
            'message' => 'Login successful.',
            'admin'   => [
                'id'        => (int)$admin['id'],
                'username'  => $admin['username'],
                'email'     => $admin['email'],
                'full_name' => $admin['full_name'],
                'role'      => $admin['role'],
            ],
        ];
    }

    /**
     * Check if an Administrator is currently logged in.
     */
    public static function isAdminLoggedIn(): bool
    {
        Security::initSecureSession();
        return !empty($_SESSION['admin_id']);
    }

    /**
     * Get current authenticated admin details or null.
     */
    public static function getCurrentAdmin(): ?array
    {
        if (!self::isAdminLoggedIn()) {
            return null;
        }
        return [
            'id'        => (int)$_SESSION['admin_id'],
            'username'  => (string)($_SESSION['admin_username'] ?? ''),
            'email'     => (string)($_SESSION['admin_email'] ?? ''),
            'full_name' => (string)($_SESSION['admin_name'] ?? ''),
            'role'      => (string)($_SESSION['admin_role'] ?? 'SUPER_ADMIN'),
        ];
    }

    /**
     * Require Admin authentication for API or page access.
     */
    public static function requireAdmin(bool $isApi = true): array
    {
        $admin = self::getCurrentAdmin();
        if (!$admin) {
            if ($isApi) {
                jsonResponse(['success' => false, 'message' => 'Authentication required. Please log in as Administrator.', 'auth_required' => true], 401);
            }
            header('Location: login.php');
            exit;
        }
        return $admin;
    }

    /**
     * Log out current Administrator.
     */
    public static function adminLogout(?PDO $pdo = null): void
    {
        Security::initSecureSession();
        if (!empty($_SESSION['admin_id']) && $pdo) {
            self::logAdminEvent($pdo, (int)$_SESSION['admin_id'], (string)$_SESSION['admin_email'], 'LOGOUT', 'Admin logged out');
            logAuditAction($pdo, 'Admin Logout', 'ADMIN', (string)$_SESSION['admin_id'], (int)$_SESSION['admin_id'], (string)$_SESSION['admin_email']);
        }
        unset(
            $_SESSION['admin_id'],
            $_SESSION['admin_username'],
            $_SESSION['admin_email'],
            $_SESSION['admin_name'],
            $_SESSION['admin_role']
        );
        session_regenerate_id(true);
    }

    /**
     * Authenticate Investor into the Investor Portal (`/investor/`).
     * Supports login with (Email or Investor/Application ID) + Password.
     */
    public static function investorLogin(PDO $pdo, string $identifier, string $password): array
    {
        Security::initSecureSession();
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return ['success' => false, 'message' => 'Email/Investor ID and password are required.'];
        }

        if (Security::isRateLimited($pdo, $identifier, 'investor_login', 5, 15)) {
            return ['success' => false, 'message' => 'Too many login attempts. Please wait 15 minutes.'];
        }

        $stmt = $pdo->prepare("
            SELECT u.*, i.id AS investor_pk, i.full_name, i.investor_id
            FROM `users` u
            LEFT JOIN `investors` i ON i.user_id = u.id OR i.investor_id = u.investor_code
            WHERE LOWER(u.email) = LOWER(:ident) OR UPPER(u.investor_code) = UPPER(:ident2)
            LIMIT 1
        ");
        $stmt->execute([':ident' => $identifier, ':ident2' => $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            Security::recordFailedAttempt($pdo, $identifier, 'investor_login');
            return ['success' => false, 'message' => 'Invalid email/ID or password.'];
        }

        if ((int)$user['is_active'] !== 1) {
            return ['success' => false, 'message' => 'Your investor portal access is currently suspended.'];
        }

        Security::clearFailedAttempts($pdo, $identifier, 'investor_login');
        $pdo->prepare("UPDATE `users` SET `last_login_at` = UTC_TIMESTAMP(), `last_login_ip` = :ip WHERE `id` = :id")
            ->execute([':ip' => Security::getClientIp(), ':id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['investor_user_id'] = (int)$user['id'];
        $_SESSION['investor_code'] = (string)$user['investor_code'];
        $_SESSION['investor_email'] = (string)$user['email'];
        $_SESSION['investor_name'] = (string)($user['full_name'] ?? 'Investor');

        return [
            'success'  => true,
            'message'  => 'Welcome back to Corelbase Investor Portal.',
            'investor' => [
                'investor_id' => $user['investor_code'],
                'email'       => $user['email'],
                'full_name'   => $user['full_name'] ?? 'Investor',
            ],
        ];
    }

    /**
     * Log event into `admin_logs`.
     */
    public static function logAdminEvent(PDO $pdo, ?int $adminId, string $identifier, string $eventType, string $details = ''): void
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO `admin_logs` (`admin_id`, `admin_identifier`, `event_type`, `ip_address`, `user_agent`, `details`, `created_at`)
                VALUES (:aid, :ident, :etype, :ip, :ua, :det, UTC_TIMESTAMP())
            ");
            $stmt->execute([
                ':aid'   => $adminId,
                ':ident' => mb_substr($identifier, 0, 190),
                ':etype' => $eventType,
                ':ip'    => Security::getClientIp(),
                ':ua'    => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250),
                ':det'   => $details,
            ]);
        } catch (Throwable $e) {
            Database::logError('Admin log error: ' . $e->getMessage());
        }
    }
}
