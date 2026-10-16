<?php
/**
 * SKIBIDI — logout. Minimal, no HTML output: tear the session down and bounce home.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

auth_boot();
auth_logout();
header('Location: index.php');
exit;
