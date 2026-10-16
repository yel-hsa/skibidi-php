<?php
/**
 * SKIBIDI — newsletter.php (subscribe handler). HARDENED: no deliberate vuln here.
 * POST-only endpoint used by the footer form. Validates the email, inserts via an
 * always-prepared statement, then redirects back to index.php (PRG). Never renders.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
auth_boot();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    if (strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        db_exec_safe('INSERT INTO newsletter (email) VALUES (?)', [$email]);
        // Success is announced by the newsletter band's own inline panel on
        // index.php?subscribed=1 (components/Newsletter.jsx), so no flash here —
        // a flash would render a second, duplicate success message at the top.
        header('Location: index.php?subscribed=1');
        exit;
    }
    $_SESSION['flash'] = 'Please enter a valid email address.';
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php?subscribed=0');
    exit;
}

// Not a POST — nothing to do here.
header('Location: index.php');
exit;
