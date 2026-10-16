<?php
/**
 * SKIBIDI — self-registration (buyer only). Not an injectable surface: every query
 * uses the always-prepared db_*_safe path. The role is NEVER taken from input — it is
 * hard-coded 'buyer'. Both password columns are written so the account logs in under
 * either mode (plaintext for the A1 login twin, bcrypt for the secure path).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

auth_boot();
$user = current_user();

// Already signed in → no need to register.
if ($user) { header('Location: index.php'); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm'] ?? '';
    $name      = trim($_POST['name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');

    // Field-by-field validation mirroring React's Register.jsx (PRD 6.6).
    if (mb_strlen($name) < 2) {
        $error = 'Please enter your name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($phone !== '' && (mb_strlen(preg_replace('/\D/', '', $phone)) < 7 || mb_strlen(preg_replace('/\D/', '', $phone)) > 15)) {
        $error = 'That number looks incomplete.';
    } elseif ($password === '') {
        $error = 'Password is required.';
    } elseif (mb_strlen($password) < 6) {
        $error = 'Use at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (db_row_safe('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
        $error = 'That email is already registered.';
    } else {
        $ok = db_exec_safe(
            'INSERT INTO users (email,password,password_hash,role,name,phone) VALUES (?,?,?,?,?,?)',
            [$email, $password, password_hash($password, PASSWORD_BCRYPT), 'buyer', $name, $phone]
        );
        if ($ok) { header('Location: login.php'); exit; }
        $error = 'Registration failed. Please try again.';
    }
}

$page_title = 'Register — ' . APP_NAME;
require __DIR__ . '/header.php';
?>

<div class="grid min-h-[calc(100vh-8rem)] lg:grid-cols-2">

  <div class="flex items-center justify-center px-6 py-16 lg:px-16">
    <div class="rise w-full max-w-md">
      <p class="eyebrow text-ink-muted">Account</p>
      <h1 class="display mt-5 text-4xl text-ink">Create an account</h1>
      <p class="mt-4 text-sm leading-relaxed text-ink-soft">Save your details for faster checkout and keep every order in one place.</p>

      <div class="mt-10">
        <?php if ($error): ?>
          <div class="fade mb-6 flex flex-col items-center border border-rust/25 bg-rust/5 px-6 py-10 text-center" role="alert">
            <?= icon('alert', 'h-6 w-6 text-rust') ?>
            <p class="mt-5 text-base font-medium text-ink">Could not create account</p>
            <p class="mt-2 max-w-lg text-sm leading-relaxed text-ink-soft"><?= e($error) ?></p>
          </div>
        <?php endif; ?>

        <form method="post" action="register.php" class="space-y-5">
          <div>
            <label class="field-label" for="name">Full name <span class="ml-1 text-clay">*</span></label>
            <input class="field" type="text" id="name" name="name" required autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>">
          </div>

          <div>
            <label class="field-label" for="email">Email <span class="ml-1 text-clay">*</span></label>
            <input class="field" type="email" id="email" name="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>">
          </div>

          <div>
            <label class="field-label" for="phone">Phone (optional)</label>
            <input class="field" type="tel" id="phone" name="phone" autocomplete="tel" placeholder="09XX XXX XXXX" value="<?= e($_POST['phone'] ?? '') ?>">
          </div>

          <div>
            <label class="field-label" for="password">Password <span class="ml-1 text-clay">*</span></label>
            <input class="field" type="password" id="password" name="password" required autocomplete="new-password">
            <p class="mt-1.5 text-xs text-ink-muted">At least 6 characters.</p>
          </div>

          <div>
            <label class="field-label" for="confirm">Confirm password <span class="ml-1 text-clay">*</span></label>
            <input class="field" type="password" id="confirm" name="confirm" required autocomplete="new-password">
          </div>

          <button type="submit" class="btn btn-primary btn-lg btn-block">Create Account</button>
        </form>
      </div>

      <div class="mt-8 text-sm text-ink-soft">
        Already have an account?
        <a href="login.php" class="link-underline text-ink">Sign in</a>
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
