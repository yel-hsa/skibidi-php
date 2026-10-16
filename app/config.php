<?php
/**
 * SKIBIDI — configuration + the master vulnerability switch.
 *
 * Intentionally-vulnerable IAS2 lab target (PHP/MySQL port of the Firebase build).
 * READ AGENTS.md / WORKLOG.md before editing. Never touch SECURE_MODE / PATCH_*
 * or vulnerability logic during unrelated UX/design work.
 *
 * RULE everywhere in the code:
 *   a threat is mitigated when  SECURE_MODE === true  OR  its own PATCH_* === true.
 */

// ---- Master switch -------------------------------------------------------
define('SECURE_MODE', false);   // false = vulnerable "before", true = fully hardened "after"

// ---- The THREE graded red-team vulns (OPEN = false in the committed lab) --
define('PATCH_SQLI',  false);   // A1 SQL Injection  — true => prepared statements everywhere
define('PATCH_BRUTE', false);   // A2 Brute Force    — true => login throttle + lockout
define('PATCH_XSS',   false);   // A3 Stored XSS     — true => output-encode reviews + send CSP

// ---- Database -----------------------------------------------------------
// Local dev defaults below. On InfinityFree, set these to the panel's
// if0_XXXXXXXX values (via env or by editing the fallbacks). Keep real creds
// OUT of source control — see .gitignore.
define('DB_HOST', getenv('SKB_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('SKB_DB_NAME') ?: 'skibidi');
define('DB_USER', getenv('SKB_DB_USER') ?: 'skibidi');
define('DB_PASS', getenv('SKB_DB_PASS') ?: 'skibidi123');

define('APP_NAME', 'SKIBIDI');

// ---- Brute-force throttle tunables (used only when A2 is patched) --------
define('LOGIN_MAX_ATTEMPTS',  5);
define('LOGIN_WINDOW_MINUTES', 15);   // rolling window, counted per email AND per IP

// ---- Session hardening (ALWAYS ON — the site is internet-facing) ---------
// None of the three graded vulns is session-related, so we never weaken this.
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');   // only when actually over HTTPS (InfinityFree SSL)
}
