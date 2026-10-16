<?php
/**
 * SKIBIDI — checkout.php (create the order). HARDENED: no deliberate vuln here.
 * The server RECOMPUTES every price, line total, subtotal, shipping and grand
 * total from the DB and ENFORCES stock — no client-supplied money is ever trusted.
 * Every query is always-prepared. PRG on success -> ?placed=<order_ref> thank-you.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/store.php';
auth_boot();
$user = current_user();

// Checkout requires a logged-in buyer.
if (!$user) { header('Location: login.php'); exit; }

/**
 * Recompute the whole cart from the DB. Returns lines (with the authoritative DB
 * price), subtotal, shipping, total, and the names of any over-stock lines.
 */
function checkout_recompute(array $cart): array {
    $lines = [];
    $subtotal = 0.0;
    $overstock = [];
    foreach ($cart as $pid => $qty) {
        $pid = (int) $pid;
        $qty = max(1, (int) $qty);
        $p = db_row_safe('SELECT * FROM products WHERE id = ? LIMIT 1', [$pid]);
        if (!$p) continue;                                  // stale cart entry — drop it
        if ($qty > (int) $p['stock']) $overstock[] = $p['name'];
        $price = effective_price($p);                       // DB price — authoritative
        $lineTotal = $price * $qty;
        $subtotal += $lineTotal;
        $lines[] = ['product' => $p, 'qty' => $qty, 'price' => $price, 'line_total' => $lineTotal];
    }
    $shipping = ($subtotal >= 1500) ? 0.0 : 100.0;
    $total = $subtotal + $shipping;
    return ['lines' => $lines, 'subtotal' => $subtotal, 'shipping' => $shipping,
            'total' => $total, 'overstock' => $overstock];
}

$placed = isset($_GET['placed']) ? trim((string) $_GET['placed']) : '';

if ($placed === '') {
    $cart = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : [];

    // Buy Now: pre-populate the cart with a single unit of the requested product
    // (clamped to remaining stock), bypassing the bag page — mirrors React's
    // handleBuyNow -> navigate('/cart'). buy_now is an int product id from product.php.
    $buyNow = isset($_GET['buy_now']) ? (int) $_GET['buy_now'] : 0;
    if ($buyNow > 0 && empty($cart)) {
        $bp = db_row_safe('SELECT id, stock FROM products WHERE id = ? LIMIT 1', [$buyNow]);
        if ($bp && (int) $bp['stock'] > 0) {
            $cart = [$bp['id'] => 1];
            $_SESSION['cart'] = $cart;
            // Drop a breadcrumb so the thank-you page can note the quick-order path.
            $_SESSION['buy_now'] = true;
        } else {
            unset($_GET['buy_now']);
        }
    }

    if (empty($cart)) { header('Location: cart.php'); exit; }   // nothing to check out

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name     = trim((string) ($_POST['customer_name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? $user['email']));
        $phone    = trim((string) ($_POST['phone'] ?? ''));
        $address  = trim((string) ($_POST['ship_address'] ?? ''));
        $city     = trim((string) ($_POST['ship_city'] ?? ''));
        $province = trim((string) ($_POST['ship_province'] ?? ''));
        $postal   = trim((string) ($_POST['ship_postal'] ?? ''));
        // payment_method is restricted to a server-side allowlist.
        $allowedPayments = ['Cash on Delivery'];
        $pm = (string) ($_POST['payment_method'] ?? '');
        $payment = in_array($pm, $allowedPayments, true) ? $pm : 'Cash on Delivery';

        $calc = checkout_recompute($cart);

        if (empty($calc['lines'])) {
            $_SESSION['flash'] = 'Your cart is empty.'; $_SESSION['flash_type'] = 'error';
            header('Location: cart.php'); exit;
        }

        // Field-by-field validation mirroring React's validateShipping() (PRD 6.5).
        // Phone uses isValidPhone: 7-15 digits after stripping non-digits.
        $phoneDigits = preg_replace('/\D/', '', $phone);
        $errors = [];
        if (mb_strlen($name) < 2)        { $errors[] = 'Please enter your full name.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email address.'; }
        if (mb_strlen($phoneDigits) < 7 || mb_strlen($phoneDigits) > 15) { $errors[] = 'Enter a contact number we can reach you on.'; }
        if (mb_strlen($address) < 5)     { $errors[] = 'Enter your street address.'; }
        if ($city === '')              { $errors[] = 'City is required.'; }
        if ($province === '')          { $errors[] = 'Province is required.'; }
        if (!preg_match('/^\d{4}$/', $postal)) { $errors[] = 'Philippine postal codes are 4 digits.'; }

        if (!empty($calc['overstock'])) {
            $errors[] = 'Not enough stock for: ' . implode(', ', $calc['overstock']) . '. Please adjust your cart.';
        }

        if (!empty($errors)) {
            $_SESSION['flash'] = implode(' ', $errors);
            $_SESSION['flash_type'] = 'error';
            header('Location: checkout.php'); exit;
        }

        // Create the order as one atomic transaction: order header + line items
        // + stock decrements either all succeed or all roll back. Only the
        // server-recomputed figures are written — never a client-supplied price.
        $orderRef = build_order_number();
        try {
            db()->beginTransaction();
            db_exec_safe(
                'INSERT INTO orders
                   (order_ref, user_id, status, customer_name, customer_email, phone,
                    ship_address, ship_city, ship_province, ship_postal,
                    subtotal, shipping, total, payment_method)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$orderRef, (int) $user['id'], 'Pending', $name, $email, $phone,
                 $address, $city, $province, $postal,
                 $calc['subtotal'], $calc['shipping'], $calc['total'], $payment]
            );
            $orderId = (int) db_last_id();

            foreach ($calc['lines'] as $ln) {
                db_exec_safe(
                    'INSERT INTO order_items (order_id, product_id, name, price, quantity, line_total)
                     VALUES (?,?,?,?,?,?)',
                    [$orderId, (int) $ln['product']['id'], (string) $ln['product']['name'],
                     $ln['price'], $ln['qty'], $ln['line_total']]
                );
                db_exec_safe('UPDATE products SET stock = stock - ? WHERE id = ?',
                    [$ln['qty'], (int) $ln['product']['id']]);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('[SKIBIDI] checkout failed: ' . $e->getMessage());
            $_SESSION['flash'] = 'We could not place your order. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: checkout.php'); exit;
        }

        $_SESSION['cart'] = [];                                 // empty the cart
        header('Location: checkout.php?placed=' . urlencode($orderRef));
        exit;
    }

    // GET: recompute the summary shown before submit.
    $calc = checkout_recompute($cart);
}

$page_title = 'Checkout — ' . APP_NAME;
require __DIR__ . '/header.php';

/* ---------- Presentation-only figures (store.php is loaded by header.php) ---- */
$item_count = 0;
$to_free    = 0.0;
$progress   = 0.0;
if ($placed === '') {
    foreach ($calc['lines'] as $__ln) { $item_count += (int) $__ln['qty']; }
    $to_free  = max(0, FREE_SHIPPING_THRESHOLD - (float) $calc['subtotal']);
    $progress = min(100, ((float) $calc['subtotal'] / FREE_SHIPPING_THRESHOLD) * 100);
}

// Flash is normally rendered by header.php; this covers the case where the
// session flash arrives after the header has already been emitted.
if (!empty($_SESSION['flash'])):
    $flash = (string) $_SESSION['flash'];
    $flashType = (($_SESSION['flash_type'] ?? 'error') === 'ok') ? 'ok' : 'error';
    unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
  <div class="shell px-6 lg:px-10 pt-8"><div class="flash flash-<?= $flashType ?>"><?= e($flash) ?></div></div>
<?php endif; ?>

<?php
// Fetch the placed order so the thank-you page can show the timestamp, mirroring
// React's OrderConfirmation "Placed {formatDateTime(order.createdAt)}".
$placed_order = $placed !== ''
    ? db_row_safe('SELECT id, status, created_at, customer_name, customer_email, phone,
                      ship_address, ship_city, ship_province, ship_postal,
                      subtotal, shipping, total, payment_method
                   FROM orders WHERE order_ref = ? LIMIT 1', [$placed])
    : null;
$placed_items = [];
if ($placed_order) {
    $placed_items = db_all_safe(
        'SELECT name, price, quantity, line_total FROM order_items WHERE order_id = ? ORDER BY id',
        [$placed_order['id']]
    );
}
$is_buy_now = !empty($_SESSION['buy_now']);
unset($_SESSION['buy_now']);

/* Status-pill classes — mirrors ORDER_STATUS_STYLE in lib/constants.js. */
$status_tone = [
    'Pending'    => 'bg-sand text-ink-soft border-line-strong',
    'Processing' => 'bg-clay/10 text-clay-deep border-clay/30',
    'Shipped'    => 'bg-sage/10 text-sage border-sage/30',
    'Completed'  => 'bg-ink text-cream border-ink',
    'Cancelled'  => 'bg-rust/10 text-rust border-rust/30',
];
$status_class = $status_tone[$placed_order['status'] ?? 'Pending'] ?? $status_tone['Pending'];
?>
<?php if ($placed !== ''): ?>
  <!-- Thank-you — pages/OrderConfirmation.jsx (justPlaced state) -->
  <div class="mx-auto max-w-4xl px-6 py-12 lg:py-16">

    <div class="rise mb-12 border border-sage/30 bg-sage/5 px-7 py-8 text-center">
      <span class="mx-auto flex h-12 w-12 items-center justify-center border border-sage/40 text-sage">
        <?= icon('check', 'h-6 w-6') ?>
      </span>
      <h1 class="display mt-6 text-3xl text-ink sm:text-4xl">Thank you for your order</h1>
      <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-ink-soft">
        We have your order and will start preparing it shortly.
        Keep the reference below for your records.
      </p>
    </div>

    <header class="flex flex-wrap items-start justify-between gap-6 border-b border-line pb-8">
      <div>
        <p class="eyebrow text-ink-muted">Order reference</p>
        <h2 class="display mt-3 text-3xl text-ink"><?= e($placed) ?></h2>
        <?php if ($placed_order): ?>
          <p class="mt-2 text-sm text-ink-soft">Placed <?= e(fmt_date((string) $placed_order['created_at'])) ?></p>
        <?php endif; ?>
      </div>
      <div class="text-right">
        <?php if ($placed_order): ?>
          <span class="eyebrow inline-flex items-center border px-2.5 py-1 <?= $status_class ?>">
            <?= e((string) $placed_order['status']) ?>
          </span>
          <p class="mt-3 max-w-xs text-xs leading-relaxed text-ink-muted">
            <?= e(order_status_meaning((string) ($placed_order['status'] ?? ''))) ?>
          </p>
        <?php else: ?>
          <span class="eyebrow inline-flex items-center border px-2.5 py-1 <?= $status_tone['Pending'] ?>">
            Pending
          </span>
          <p class="mt-3 max-w-xs text-xs leading-relaxed text-ink-muted">
            <?= e(order_status_meaning('Pending')) ?>
          </p>
        <?php endif; ?>
      </div>
    </header>

    <!-- Items — mirrors OrderConfirmation.jsx Items section -->
    <?php if ($placed_order && !empty($placed_items)): ?>
      <section class="mt-10">
        <h3 class="eyebrow text-ink">Items</h3>
        <ul class="mt-5 divide-y divide-line border-y border-line">
          <?php foreach ($placed_items as $item): ?>
            <li class="flex items-center gap-5 py-5">
              <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded bg-sand text-xs text-ink-muted">
                <?= e((string) $item['name']) ?>
              </span>
              <div class="min-w-0 flex-1">
                <p class="mt-1 text-sm text-ink"><?= e((string) $item['name']) ?></p>
                <p class="mt-1 text-xs text-ink-muted">
                  <?= (int) $item['quantity'] ?> × <?= peso($item['price']) ?>
                </p>
              </div>
              <p class="text-sm tabular-nums text-ink"><?= peso($item['line_total']) ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <div class="mt-10 grid gap-10 sm:grid-cols-2">
      <section>
        <h3 class="eyebrow text-ink">Delivery details</h3>
        <?php if ($placed_order): ?>
          <div class="mt-5 space-y-1.5 text-sm text-ink-soft">
            <p class="text-ink"><?= e($placed_order['customer_name']) ?></p>
            <p><?= e($placed_order['customer_email']) ?></p>
            <?php if (!empty($placed_order['phone'])): ?><p><?= e($placed_order['phone']) ?></p><?php endif; ?>
            <p class="pt-3 leading-relaxed">
              <?= e($placed_order['ship_address']) ?><br />
              <?= e($placed_order['ship_city']) ?>, <?= e($placed_order['ship_province']) ?> <?= e($placed_order['ship_postal']) ?>
            </p>
          </div>
          <p class="mt-5 flex items-center gap-2 text-sm text-ink">
            <?= icon('truck', 'h-4 w-4 text-ink-muted') ?>
            <?= e($placed_order['payment_method'] ?? 'Cash on Delivery') ?>
          </p>
        <?php else: ?>
          <div class="mt-5 space-y-1.5 text-sm text-ink-soft">
            <p class="text-ink"><?= e($user['name'] ?? '') ?></p>
            <p><?= e($user['email'] ?? '') ?></p>
            <?php if (!empty($user['phone'])): ?><p><?= e($user['phone']) ?></p><?php endif; ?>
          </div>
          <p class="mt-5 flex items-center gap-2 text-sm text-ink">
            <?= icon('truck', 'h-4 w-4 text-ink-muted') ?>
            Cash on Delivery
          </p>
        <?php endif; ?>
      </section>

      <!-- Payment summary — mirrors OrderConfirmation.jsx Payment summary section -->
      <?php if ($placed_order): ?>
        <section>
          <h3 class="eyebrow text-ink">Payment summary</h3>
          <dl class="mt-5 space-y-3 text-sm">
            <div class="flex justify-between">
              <dt class="text-ink-soft">Subtotal</dt>
              <dd class="tabular-nums text-ink"><?= peso($placed_order['subtotal']) ?></dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-ink-soft">Shipping</dt>
              <dd class="tabular-nums text-ink">
                <?php if ((float) $placed_order['shipping'] === 0.0): ?>
                  <span class="text-sage">Free</span>
                <?php else: ?>
                  <?= peso($placed_order['shipping']) ?>
                <?php endif; ?>
              </dd>
            </div>
            <div class="flex justify-between border-t border-line pt-3">
              <dt class="eyebrow pt-1 text-ink">Total</dt>
              <dd class="text-lg tabular-nums text-ink"><?= peso($placed_order['total']) ?></dd>
            </div>
          </dl>
        </section>
      <?php else: ?>
        <section>
          <h3 class="eyebrow text-ink">What happens next</h3>
          <div class="mt-5 space-y-1.5 text-sm leading-relaxed text-ink-soft">
            <p>We'll contact you to arrange delivery.</p>
            <p>Payment is collected in cash when your order arrives.</p>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <!-- Buttons — order matches React: Continue shopping (primary) first -->
    <div class="mt-14 flex flex-col gap-3 border-t border-line pt-10 sm:flex-row">
      <a href="index.php?shop=1" class="btn btn-primary btn-lg sm:flex-1">Continue shopping</a>
      <a href="account.php#orders" class="btn btn-secondary btn-lg sm:flex-1">View order history</a>
    </div>
  </div>

<?php else: ?>

<div class="mx-auto max-w-[1400px] px-6 py-12 lg:px-10 lg:py-16">
  <header class="border-b border-line pb-8">
    <nav class="flex items-center gap-2 text-xs text-ink-muted">
      <a href="cart.php" class="hover:text-ink">Bag</a>
      <?= icon('chevronRight', 'h-3 w-3') ?>
      <span class="text-ink">Checkout</span>
    </nav>
    <h1 class="display mt-5 text-4xl text-ink sm:text-5xl">Checkout</h1>
    <p class="mt-4 max-w-xl text-sm leading-relaxed text-ink-soft">
      Tell us where to send your order. Payment is collected in cash when it arrives.
    </p>
  </header>

  <form method="post" action="checkout.php" class="mt-12 grid gap-12 lg:grid-cols-[1fr_380px] lg:gap-16">
    <div class="space-y-12">

      <section>
        <h2 class="eyebrow border-b border-line pb-4 text-ink">Contact</h2>
        <div class="mt-6 grid gap-5 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label class="field-label" for="customer_name">Full name <span class="ml-1 text-clay">*</span></label>
            <input class="field" type="text" id="customer_name" name="customer_name" required autocomplete="name"
                   value="<?= e($_POST['customer_name'] ?? ($user['name'] ?? '')) ?>">
          </div>
          <div>
            <label class="field-label" for="email">Email <span class="ml-1 text-clay">*</span></label>
            <input class="field" type="email" id="email" name="email" required autocomplete="email"
                   value="<?= e($_POST['email'] ?? ($user['email'] ?? '')) ?>">
          </div>
          <div>
            <label class="field-label" for="phone">Phone <span class="ml-1 text-clay">*</span></label>
            <input class="field" type="tel" id="phone" name="phone" required autocomplete="tel" placeholder="09XX XXX XXXX"
                   value="<?= e($_POST['phone'] ?? ($user['phone'] ?? '')) ?>">
          </div>
        </div>
      </section>

      <section>
        <h2 class="eyebrow border-b border-line pb-4 text-ink">Shipping address</h2>
        <div class="mt-6 grid gap-5 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label class="field-label" for="ship_address">Street address <span class="ml-1 text-clay">*</span></label>
            <input class="field" type="text" id="ship_address" name="ship_address" required autocomplete="street-address"
                   placeholder="House / unit number, street, barangay"
                   value="<?= e($_POST['ship_address'] ?? ($user['address'] ?? '')) ?>">
          </div>
          <div>
            <label class="field-label" for="ship_city">City</label>
            <input class="field" type="text" id="ship_city" name="ship_city" required autocomplete="address-level2"
                   value="<?= e($_POST['ship_city'] ?? ($user['city'] ?? '')) ?>">
          </div>
          <div>
            <label class="field-label" for="ship_province">Province</label>
            <input class="field" type="text" id="ship_province" name="ship_province" required autocomplete="address-level1"
                   value="<?= e($_POST['ship_province'] ?? ($user['province'] ?? '')) ?>">
          </div>
          <div>
            <label class="field-label" for="ship_postal">Postal code</label>
            <input class="field" type="text" id="ship_postal" name="ship_postal" required autocomplete="postal-code" placeholder="1000"
                   value="<?= e($_POST['ship_postal'] ?? ($user['postal_code'] ?? '')) ?>">
          </div>
        </div>
      </section>

      <section>
        <h2 class="eyebrow border-b border-line pb-4 text-ink">Payment method</h2>
        <div class="mt-6 space-y-3">
          <label class="flex cursor-pointer items-start gap-4 border border-ink bg-shell px-5 py-4 transition-colors">
            <input type="radio" name="payment_method" value="Cash on Delivery" checked class="mt-1 accent-ink">
            <span>
              <span class="block text-sm text-ink">Cash on Delivery</span>
              <span class="mt-1 block text-xs text-ink-soft">Pay the courier in cash when your order arrives.</span>
            </span>
          </label>
        </div>
      </section>

    </div>

    <!-- Order summary (components/OrderSummary.jsx) -->
    <div class="lg:sticky lg:top-28 lg:self-start">
      <div class="border border-line bg-shell">
        <h2 class="eyebrow border-b border-line px-7 py-5 text-ink">
          Your order &middot; <?= (int) $item_count ?> <?= $item_count === 1 ? 'item' : 'items' ?>
        </h2>
        <ul class="divide-y divide-line px-7">
          <?php foreach ($calc['lines'] as $ln): $p = $ln['product']; ?>
            <li class="flex items-center gap-4 py-4">
              <div class="w-14 shrink-0">
                <div class="aspect-square relative overflow-hidden bg-sand">
                  <?php if (sample_image_url($p) !== ''): ?>
                    <img src="<?= e(sample_image_url($p)) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async"
                         class="h-full w-full object-cover">
                  <?php else: ?>
                    <div class="flex h-full w-full items-center justify-center bg-sand text-ink-muted">
                      <span class="display text-lg"><?= e(strtoupper(substr((string) $p['name'], 0, 2))) ?></span>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-ink"><?= e($p['name']) ?></p>
                <p class="mt-0.5 text-xs text-ink-muted"><?= (int) $ln['qty'] ?> &times; <?= peso($ln['price']) ?></p>
              </div>
              <p class="text-sm tabular-nums text-ink"><?= peso($ln['line_total']) ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="mt-6">
        <div class="border border-line bg-shell p-7">
          <h2 class="eyebrow border-b border-line pb-5 text-ink">Total due</h2>

          <dl class="space-y-4 pt-6 text-sm">
            <div class="flex items-center justify-between">
              <dt class="text-ink-soft">Subtotal</dt>
              <dd class="tabular-nums text-ink"><?= peso($calc['subtotal']) ?></dd>
            </div>
            <div class="flex items-center justify-between">
              <dt class="text-ink-soft">Shipping</dt>
              <dd class="tabular-nums text-ink">
                <?php if ($calc['shipping'] <= 0): ?><span class="text-sage">Free</span><?php else: ?><?= peso($calc['shipping']) ?><?php endif; ?>
              </dd>
            </div>
          </dl>

          <div class="mt-6 border-t border-line pt-6">
            <div class="flex items-baseline justify-between">
              <span class="eyebrow text-ink">Total</span>
              <span class="text-xl tabular-nums text-ink"><?= peso($calc['total']) ?></span>
            </div>
          </div>

          <?php if ($to_free > 0): ?>
            <div class="mt-7 border border-line bg-cream px-5 py-4">
              <p class="flex items-start gap-2.5 text-xs leading-relaxed text-ink-soft">
                <?= icon('truck', 'mt-px h-4 w-4 shrink-0 text-ink-muted') ?>
                <span>Add <?= peso($to_free) ?> more to qualify for free shipping.</span>
              </p>
              <div class="mt-3 h-px w-full bg-line" aria-hidden="true">
                <div class="h-px bg-clay transition-all duration-500" style="width:<?= (int) round($progress) ?>%"></div>
              </div>
            </div>
          <?php else: ?>
            <p class="mt-7 flex items-center gap-2.5 border border-sage/25 bg-sage/5 px-5 py-4 text-xs text-sage">
              <?= icon('check', 'h-4 w-4 shrink-0') ?>
              Free shipping applied to this order.
            </p>
          <?php endif; ?>

          <div class="mt-7">
            <button type="submit" class="btn btn-primary btn-lg btn-block">Place Order</button>
            <p class="mt-4 text-center text-xs leading-relaxed text-ink-muted">
              By placing this order you agree to pay <?= peso($calc['total']) ?> in cash on delivery.
            </p>
            <p class="mt-3 text-center">
              <a href="cart.php" class="eyebrow link-underline text-ink-muted transition-colors hover:text-ink">Back to bag</a>
            </p>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
