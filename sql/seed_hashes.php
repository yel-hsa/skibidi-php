<?php
/**
 * SKIBIDI — fill users.password_hash with bcrypt from the seeded plaintext passwords.
 * Run ONCE after importing sql/schema.sql:   php sql/seed_hashes.php
 *
 * Uses prepared PDO directly (never the toggled db_* helpers) so it behaves
 * identically regardless of SECURE_MODE / PATCH_SQLI. One seeded DB serves both
 * modes: vulnerable login reads `password`, secure login reads `password_hash`.
 */
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/db.php';

$pdo = db();
$users = $pdo->query('SELECT id, email, password FROM users')->fetchAll();

$updated = 0;
foreach ($users as $u) {
    if ($u['password'] === null || $u['password'] === '') {
        echo "SKIP {$u['email']} (no plaintext password)\n";
        continue;
    }
    $hash = password_hash($u['password'], PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$hash, $u['id']]);

    // Verify the round-trip now, so a broken seed fails here, not during a demo.
    $check = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $check->execute([$u['id']]);
    $stored = (string) $check->fetchColumn();
    if (!password_verify($u['password'], $stored)) {
        fwrite(STDERR, "FAIL: hash round-trip failed for {$u['email']}\n");
        exit(1);
    }
    echo "OK   {$u['email']}\n";
    $updated++;
}

echo "Done. password_hash filled for {$updated} user(s).\n";
