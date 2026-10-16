<?php
/**
 * SKIBIDI — login. Carries the A1 (SQL injection) twin and the A2 (brute force) wiring.
 *
 * A1 lives ONLY in the deliberate toggled login lookup below: when unpatched it runs
 * the caller's string-concatenated SQL via db_row (plaintext compare); when
 * SECURE_MODE || PATCH_SQLI it switches to a parameterized db_row_safe + bcrypt verify.
 * Every other query in the app uses the always-prepared db_*_safe path.
 * A2: auth_is_locked_out() / auth_record_attempt() are no-ops unless SECURE_MODE || PATCH_BRUTE.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

auth_boot();
$user = current_user();

// Already signed in → nothing to do here.
if ($user) { header('Location: index.php'); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = auth_client_ip();
    if (auth_is_locked_out($email, $ip)) {               // A2: no-op unless patched
        $error = 'Invalid email or password.';            // SAME string as failure (keeps hydra F= marker valid)
    } else {
        if (SECURE_MODE || PATCH_SQLI) {
            // SECURE: parameterized lookup + bcrypt verify
            $u = db_row_safe('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
            $ok = $u && password_verify($password, (string)$u['password_hash']);
        } else {
            // VULNERABLE (A1 SQL injection): plaintext compare built by string concat
            $u = db_row("SELECT * FROM users WHERE email = '$email' AND password = '$password' LIMIT 1");
            $ok = (bool)$u;
        }
        auth_record_attempt($email, $ip, (bool)$ok);      // A2: no-op unless patched
        if ($ok) { auth_login_session($u); header('Location: index.php'); exit; }
        $error = 'Invalid email or password.';
    }
}

$page_title = 'Login — ' . APP_NAME;
require __DIR__ . '/header.php';
?>

<div class="grid min-h-[calc(100vh-8rem)] lg:grid-cols-2">

  <div class="flex items-center justify-center px-6 py-16 lg:px-16">
    <div class="rise w-full max-w-md">
      <p class="eyebrow text-ink-muted">Account</p>
      <h1 class="display mt-5 text-4xl text-ink">Sign in</h1>
      <p class="mt-4 text-sm leading-relaxed text-ink-soft">Sign in to check out faster and follow your orders.</p>

      <div class="mt-10">
        <?php if ($error): ?>
          <div class="fade mb-6 flex flex-col items-center border border-rust/25 bg-rust/5 px-6 py-10 text-center" role="alert">
            <?= icon('alert', 'h-6 w-6 text-rust') ?>
            <p class="mt-5 text-base font-medium text-ink">Could not sign in</p>
            <p class="mt-2 max-w-lg text-sm leading-relaxed text-ink-soft"><?= e($error) ?></p>
          </div>
        <?php endif; ?>

        <form method="post" action="login.php" class="space-y-5">
          <div>
            <label class="field-label" for="email">Email <span class="ml-1 text-clay">*</span></label>
            <?php if (SECURE_MODE || PATCH_SQLI): ?>
              <input class="field" type="email" id="email" name="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>">
            <?php else: ?>
              <input class="field" type="text" id="email" name="email" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>">
            <?php endif; ?>
          </div>

          <div>
            <label class="field-label" for="password">Password <span class="ml-1 text-clay">*</span></label>
            <input class="field" type="password" id="password" name="password" required autocomplete="current-password">
          </div>

          <button type="submit" class="btn btn-primary btn-lg btn-block">Sign In</button>
        </form>
      </div>

      <div class="mt-8 text-sm text-ink-soft">
        New to <?= e(STORE_NAME) ?>?
        <a href="register.php" class="link-underline text-ink">Create an account</a>
      </div>
    </div>
  </div>

  <aside class="relative hidden border-l border-line bg-sand lg:block">
    <img src="../assets/img/hero.svg" alt="" aria-hidden="true" class="h-full w-full object-cover opacity-95">
    <div class="absolute bottom-12 left-12 right-12 border border-line bg-shell/95 px-8 py-7 backdrop-blur">
      <a href="index.php" class="wordmark text-lg text-ink"><?= e(STORE_NAME) ?></a>
      <p class="mt-3 text-sm leading-relaxed text-ink-soft"><?= e(STORE_TAGLINE) ?></p>
    </div>
  </aside>
</div>

<?php require __DIR__ . '/footer.php'; ?>
