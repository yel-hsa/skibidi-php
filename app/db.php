<?php
/**
 * SKIBIDI — PDO connection + the SQL-injection toggle.
 *
 * TWO query paths, by design:
 *   • db_run / db_all / db_row / db_exec  -> TOGGLED. Prepared when (SECURE_MODE
 *     || PATCH_SQLI), otherwise the caller's raw concatenated SQL is executed.
 *     Use ONLY at the three intended injectable surfaces (product search,
 *     product-by-id, login auth), each with an explicit vulnerable/secure twin.
 *   • db_safe / db_all_safe / db_row_safe / db_exec_safe -> ALWAYS prepared.
 *     Use for EVERY trusted internal query (throttle, audit, orders, reviews
 *     insert, admin CRUD, user-by-id) so A1 cannot leak into them and so the
 *     app behaves identically regardless of the PATCH_SQLI flag.
 */
require_once __DIR__ . '/config.php';

$GLOBALS['db_errors'] = [];

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/** TOGGLED path — the ONLY place A1 (SQL injection) lives. */
function db_run(string $sql, array $params = []) {
    try {
        if (SECURE_MODE || PATCH_SQLI) {
            $stmt = db()->prepare($sql);   // SECURE: bound params, no interpolation
            $stmt->execute($params);
            return $stmt;
        }
        // VULNERABLE (A1): $params deliberately IGNORED; the caller's string-
        // concatenated $sql runs verbatim, so input flows straight into SQL.
        return db()->query($sql);
    } catch (PDOException $e) {
        error_log('[SKIBIDI] DB error: ' . $e->getMessage() . ' | SQL: ' . $sql);
        $GLOBALS['db_errors'][] = 'Something went wrong. Please try again later.';
        return false;
    }
}
/** ALWAYS-prepared path — for every trusted internal query (never injectable). */
function db_safe(string $sql, array $params = []) {
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log('[SKIBIDI] DB error: ' . $e->getMessage() . ' | SQL: ' . $sql);
        $GLOBALS['db_errors'][] = 'Something went wrong. Please try again later.';
        return false;
    }
}

// Toggled convenience wrappers (injectable surfaces only).
function db_all(string $sql, array $p = []): array { $s = db_run($sql, $p); return $s ? $s->fetchAll() : []; }
function db_row(string $sql, array $p = [])        { $s = db_run($sql, $p); return $s ? $s->fetch()    : null; }
function db_exec(string $sql, array $p = []): int  { $s = db_run($sql, $p); return $s ? $s->rowCount() : 0; }

// Always-safe convenience wrappers (everything else).
function db_all_safe(string $sql, array $p = []): array { $s = db_safe($sql, $p); return $s ? $s->fetchAll() : []; }
function db_row_safe(string $sql, array $p = [])        { $s = db_safe($sql, $p); return $s ? $s->fetch()    : null; }
function db_exec_safe(string $sql, array $p = []): int  { $s = db_safe($sql, $p); return $s ? $s->rowCount() : 0; }

function db_last_id() { return db()->lastInsertId(); }

function db_errors_html(): string {
    if (empty($GLOBALS['db_errors'])) return '';
    $out = '';
    foreach ($GLOBALS['db_errors'] as $m) {
        $out .= '<div class="flash flash-error">' . htmlspecialchars($m) . '</div>';
    }
    return $out;
}

function fmt_date($s) { return $s ? date('M j, Y', strtotime($s)) : ''; }
function peso($n) { return '₱' . number_format((float)$n, 2); }

/** HTML-escape helper. Output-encoding for A3 (Stored XSS) secure path uses this. */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

