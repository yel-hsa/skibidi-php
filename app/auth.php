<?php
/**
 * SKIBIDI — session, password handling, login throttle (A2 Brute Force lives here).
 *
 * Access control is HARDENED by default (role comes only from the DB row; no
 * client-trusted role cookie; session id regenerated on login). None of that is
 * a graded vuln on this public build. The ONE deliberate hole here is A2: the
 * login throttle is a no-op unless SECURE_MODE || PATCH_BRUTE.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function auth_boot(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function current_user() {
    if (empty($_SESSION['user_id'])) return null;
    // ALWAYS-safe lookup by id (never injectable, independent of PATCH_SQLI).
    $u = db_row_safe('SELECT * FROM users WHERE id = ? LIMIT 1', [(int)$_SESSION['user_id']]);
    return $u ?: null;
}

function is_admin($user): bool { return $user && ($user['role'] ?? '') === 'admin'; }

function auth_login_session(array $user): void {
    session_regenerate_id(true);           // session-fixation safe (always)
    $_SESSION['user_id'] = (int)$user['id'];
}

function auth_logout(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function auth_client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
// ---- A2 Brute-force throttle (active only when SECURE_MODE || PATCH_BRUTE) ----
// Uses the ALWAYS-safe query path so it works regardless of the A1 flag.

function auth_failed_count(string $email, string $ip): int {
    $minutes = (int) LOGIN_WINDOW_MINUTES;   // config constant, safe to interpolate
    $row = db_row_safe(
        "SELECT COUNT(*) AS c FROM login_attempts
          WHERE success = 0
            AND created_at >= (NOW() - INTERVAL $minutes MINUTE)
            AND (email = ? OR ip = ?)",
        [$email, $ip]
    );
    return $row ? (int) $row['c'] : 0;
}

function auth_is_locked_out(string $email, string $ip): bool {
    // VULNERABLE (A2 Brute Force): with no patch there is NO lockout at all.
    if (!(SECURE_MODE || PATCH_BRUTE)) return false;
    return auth_failed_count($email, $ip) >= (int) LOGIN_MAX_ATTEMPTS;
}

function auth_record_attempt(string $email, string $ip, bool $success): void {
    // VULNERABLE (A2): with no patch, failures are not even recorded.
    if (!(SECURE_MODE || PATCH_BRUTE)) return;
    db_exec_safe(
        'INSERT INTO login_attempts (email, ip, success) VALUES (?, ?, ?)',
        [substr($email, 0, 190), substr($ip, 0, 45), $success ? 1 : 0]
    );
}

