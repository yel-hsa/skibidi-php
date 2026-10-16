<?php
/**
 * SKIBIDI — the signed-in buyer's own account page. Access control is HARDENED:
 * you must be logged in, and orders are strictly ownership-scoped by user_id via the
 * always-prepared db_*_safe path (never injectable, independent of PATCH_SQLI).
 *
 * Mirrors React Account.jsx: two-column layout with a profile editor (left)
 * and order history (right), "Member since" date, pre-fill from saved profile.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

auth_boot();
$user = current_user();

// Must be signed in to see an account.
if (!$user) { header('Location: login.php'); exit; }

// --- Profile update handler (POST) — HARDENED: always-prepared, no injection. ---
$profile_error = null;
$profile_ok = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $name     = trim((string) ($_POST['profile_name'] ?? ''));
    $phone    = trim((string) ($_POST['profile_phone'] ?? ''));
    $address  = trim((string) ($_POST['profile_address'] ?? ''));
    $city     = trim((string) ($_POST['profile_city'] ?? ''));
    $province = trim((string) ($_POST['profile_province'] ?? ''));
    $postal   = trim((string) ($_POST['profile_postal'] ?? ''));

    $profile_errors = [];
    if (mb_strlen($name) < 2)        { $profile_errors[] = 'Please enter your full name.'; }
    if ($phone !== '' && (mb_strlen(preg_replace('/\D/', '', $phone)) < 7 || mb_strlen(preg_replace('/\D/', '', $phone)) > 15)) {
        $profile_errors[] = 'That number looks incomplete.';
    }
    if ($address !== '' && mb_strlen($address) < 5) {
        $profile_errors[] = 'Enter your street address.';
    }

    if (!empty($profile_errors)) {
        $profile_error = implode(' ', $profile_errors);
    } else {
        db_exec_safe(
            'UPDATE users SET name = ?, phone = ?, address = ?, city = ?, province = ?, postal_code = ? WHERE id = ?',
            [$name, $phone, $address, $city, $province, $postal, (int)$user['id']]
        );
        $profile_ok = 'Profile updated';
        // Refresh the user object from DB so the form reflects the save.
        $user = db_row_safe('SELECT * FROM users WHERE id = ? LIMIT 1', [(int)$user['id']]);
    }
}

// Ownership-scoped: ONLY this user's orders. Not an injectable surface.
$orders = db_all_safe(
    'SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC',
    [(int)$user['id']]
);

$page_title = 'My Account — ' . APP_NAME;
require __DIR__ . '/header.php';

/* ---------- Presentation-only: status pill tones (lib/constants.js) -------- */
// Mirrors ORDER_STATUS_STYLE so the badge colours never drift from the React build.
$status_tone = [
    'Pending'    => 'bg-sand text-ink-soft border-line-strong',
    'Processing' => 'bg-clay/10 text-clay-deep border-clay/30',
    'Shipped'    => 'bg-sage/10 text-sage border-sage/30',
    'Completed'  => 'bg-ink text-cream border-ink',
    'Cancelled'  => 'bg-rust/10 text-rust border-rust/30',
];
?>

<div class="mx-auto max-w-[1400px] px-6 py-12 lg:px-10 lg:py-16">

  <header class="flex flex-wrap items-start justify-between gap-6 border-b border-line pb-8">
    <div>
      <p class="eyebrow text-ink-muted">My account</p>
      <h1 class="display mt-4 text-4xl text-ink sm:text-5xl">
        <?= e($user['name'] ?: 'Your account') ?>
      </h1>
      <p class="mt-3 text-sm text-ink-soft">
        <?= e($user['email']) ?>
        <?php if (!empty($user['created_at'])): ?>
          <span class="text-ink-muted"> · Member since <?= e(fmt_date((string) $user['created_at'])) ?></span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <a href="admin.php" class="btn btn-secondary btn-sm">
          <?= icon('grid', 'h-3.5 w-3.5') ?>
          Admin dashboard
        </a>
      <?php endif; ?>
      <a href="logout.php" class="btn btn-ghost btn-sm">
        <?= icon('logout', 'h-3.5 w-3.5') ?>
        Sign out
      </a>
    </div>
  </header>

  <div class="mt-12 grid gap-14 lg:grid-cols-[380px_1fr] lg:gap-20">
    <!-- Profile editor (mirrors Account.jsx profile form) -->
    <section>
      <h2 class="eyebrow border-b border-line pb-4 text-ink">Profile</h2>

      <?php if ($profile_ok): ?>
        <div class="mt-6"><div class="flash flash-ok"><?= e($profile_ok) ?></div></div>
      <?php endif; ?>
      <?php if ($profile_error): ?>
        <div class="mt-6"><div class="flash flash-error"><?= e($profile_error) ?></div></div>
      <?php endif; ?>

      <form method="post" action="account.php" class="mt-6 space-y-5">
        <input type="hidden" name="action" value="update_profile">

        <div>
          <label class="field-label" for="profile_name">Full name</label>
          <input class="field" type="text" id="profile_name" name="profile_name" autocomplete="name"
                 value="<?= e($_POST['profile_name'] ?? ($user['name'] ?? '')) ?>">
        </div>

        <div>
          <span class="field-label">Email</span>
          <p class="field bg-sand text-ink-soft"><?= e($user['email'] ?? '') ?></p>
          <p class="mt-1.5 text-xs text-ink-muted">
            Your sign-in email cannot be changed here.
          </p>
        </div>

        <div>
          <label class="field-label" for="profile_phone">Phone</label>
          <input class="field" type="tel" id="profile_phone" name="profile_phone" autocomplete="tel"
                 placeholder="09XX XXX XXXX"
                 value="<?= e($_POST['profile_phone'] ?? ($user['phone'] ?? '')) ?>">
        </div>

        <div>
          <label class="field-label" for="profile_address">Street address</label>
          <input class="field" type="text" id="profile_address" name="profile_address" autocomplete="street-address"
                 placeholder="House / unit number, street, barangay"
                 value="<?= e($_POST['profile_address'] ?? ($user['address'] ?? '')) ?>">
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
          <div>
            <label class="field-label" for="profile_city">City</label>
            <input class="field" type="text" id="profile_city" name="profile_city" autocomplete="address-level2"
                   value="<?= e($_POST['profile_city'] ?? ($user['city'] ?? '')) ?>">
          </div>
          <div>
            <label class="field-label" for="profile_province">Province</label>
            <input class="field" type="text" id="profile_province" name="profile_province" autocomplete="address-level1"
                   value="<?= e($_POST['profile_province'] ?? ($user['province'] ?? '')) ?>">
          </div>
        </div>

        <div>
          <label class="field-label" for="profile_postal">Postal code</label>
          <input class="field" type="text" id="profile_postal" name="profile_postal" autocomplete="postal-code"
                 placeholder="1000"
                 value="<?= e($_POST['profile_postal'] ?? ($user['postal_code'] ?? '')) ?>">
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block">Save changes</button>
        <p class="text-xs leading-relaxed text-ink-muted">
          These details pre-fill your next checkout.
        </p>
      </form>
    </section>

    <!-- Order history (mirrors Account.jsx OrderHistory section) -->
    <section id="orders" class="scroll-mt-28">
      <h2 class="eyebrow border-b border-line pb-4 text-ink">Order history</h2>

      <?php if (!$orders): ?>
        <div class="mt-6">
          <div class="fade flex flex-col items-center justify-center border border-line bg-shell px-8 py-20 text-center">
            <span class="mb-6 flex h-14 w-14 items-center justify-center border border-line text-ink-muted">
              <?= icon('receipt', 'h-6 w-6') ?>
            </span>
            <h3 class="display text-2xl text-ink">No orders yet</h3>
            <p class="mt-3 max-w-md text-sm leading-relaxed text-ink-soft">
              When you place your first order it will appear here with its status and total.
            </p>
            <div class="mt-8"><a href="index.php?shop=1" class="btn btn-primary">Start shopping</a></div>
          </div>
        </div>
      <?php else: ?>
        <ul class="mt-6 divide-y divide-line border-y border-line">
          <?php foreach ($orders as $o): ?>
            <?php $items = db_all_safe('SELECT * FROM order_items WHERE order_id = ?', [(int)$o['id']]); ?>
            <li class="border-b border-line last:border-b-0">
              <details>
                <summary class="list-none flex flex-wrap items-center gap-x-8 gap-y-4 py-6">
                  <div class="min-w-[9rem] flex-1">
                    <p class="eyebrow text-ink-muted">Order</p>
                    <p class="mt-1.5 text-sm text-ink"><?= e($o['order_ref']) ?></p>
                  </div>
                  <div class="min-w-[7rem]">
                    <p class="eyebrow text-ink-muted">Date</p>
                    <p class="mt-1.5 text-sm text-ink"><?= e(fmt_date($o['created_at'])) ?></p>
                  </div>
                  <div class="min-w-[6rem]">
                    <p class="eyebrow text-ink-muted">Items</p>
                    <p class="mt-1.5 text-sm tabular-nums text-ink"><?= count($items) ?></p>
                  </div>
                  <div class="min-w-[7rem]">
                    <p class="eyebrow text-ink-muted">Total</p>
                    <p class="mt-1.5 text-sm tabular-nums text-ink"><?= peso($o['total']) ?></p>
                  </div>
                  <div class="min-w-[7rem]">
                    <p class="eyebrow text-ink-muted">Status</p>
                    <div class="mt-1.5">
                      <span class="eyebrow inline-flex items-center border px-2.5 py-1 <?= $status_tone[$o['status']] ?? $status_tone['Pending'] ?>"><?= e($o['status']) ?></span>
                    </div>
                  </div>
                  <span class="eyebrow link-underline ml-auto flex items-center gap-2 text-ink">
                    View order
                    <span class="details-chevron inline-flex"><?= icon('arrowRight', 'h-4 w-4') ?></span>
                  </span>
                </summary>

                <div class="pb-8">
                  <div class="border border-line bg-shell p-7">
                    <p class="eyebrow border-b border-line pb-5 text-ink">Order <?= e($o['order_ref']) ?></p>
                    <ul class="divide-y divide-line">
                      <?php foreach ($items as $it): ?>
                        <li class="flex items-center gap-4 py-4">
                          <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-ink"><?= e($it['name']) ?></p>
                            <p class="mt-0.5 text-xs text-ink-muted"><?= (int) $it['quantity'] ?> &times; <?= peso($it['price']) ?></p>
                          </div>
                          <p class="text-sm tabular-nums text-ink"><?= peso($it['line_total']) ?></p>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                    <div class="mt-6 border-t border-line pt-6">
                      <div class="flex items-baseline justify-between">
                        <span class="eyebrow text-ink">Total</span>
                        <span class="text-lg tabular-nums text-ink"><?= peso($o['total']) ?></span>
                      </div>
                    </div>
                  </div>
                </div>
              </details>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

</div>

<?php require __DIR__ . '/footer.php'; ?>
