<?php
/**
 * SKIBIDI — cart.php (session cart). HARDENED: no deliberate vuln here.
 * Cart shape:  $_SESSION['cart'] = [ product_id (int) => quantity (int) ].
 * Add    = POST product_id + quantity (accumulates onto any existing qty).
 * Remove = GET  cart.php?remove=<id>.
 * All mutations use PRG (redirect back to cart.php); every query is always-prepared.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/store.php';
auth_boot();
$user = current_user();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// --- Remove a line (GET) ---------------------------------------------------
if (isset($_GET['remove'])) {
    $rid = (int) $_GET['remove'];
    if (isset($_SESSION['cart'][$rid])) unset($_SESSION['cart'][$rid]);
    header('Location: cart.php');
    exit;
}

// --- Empty the whole bag (GET) ---------------------------------------------
// Presentation-supporting only (the "Empty bag" control on the bag page).
// Same PRG shape as ?remove=, no SQL, no change to any vulnerability surface.
if (isset($_GET['clear'])) {
    $_SESSION['cart'] = [];
    header('Location: cart.php');
    exit;
}

// --- Adjust quantity (POST) ------------------------------------------------
// quantity_mode is: "add" | "set". "add" accumulates onto any existing qty
// (the product-card +1 button posts this). "set" replaces the line qty (the
// bag-page +/- buttons post this), and is clamped to [1, stock] so a customer
// can never book more than is on hand. Every mutation is a POST + PRG.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int) ($_POST['product_id'] ?? 0);
    $qty = (int) ($_POST['quantity'] ?? 1);
    $mode = (string) ($_POST['quantity_mode'] ?? 'add');
    if ($qty < 1) { $qty = 1; }                   // clamp quantity to >= 1
    if ($pid > 0) {
        // Validate the product exists before trusting the posted id.
        $product = db_row_safe('SELECT id, name, stock FROM products WHERE id = ? LIMIT 1', [$pid]);
        if ($product) {
            $stock = (int) $product['stock'];
            $have = (int) ($_SESSION['cart'][$pid] ?? 0);
            if ($mode === 'set') {
                // Direct quantity entry from the bag page — cap at stock.
                $newQty = ($stock > 0) ? min($qty, $stock) : 0;
            } else {
                // "add" — the card +1 button; accumulate, then clamp to stock.
                $newQty = ($stock > 0) ? min($have + $qty, $stock) : 0;
            }
            if ($newQty > 0) {
                $_SESSION['cart'][$pid] = $newQty;
                $_SESSION['flash'] = 'Added "' . $product['name'] . '" to your cart.';
                $_SESSION['flash_type'] = 'ok';
            } else {
                // Stock ran out while the customer was looking.
                unset($_SESSION['cart'][$pid]);
                $_SESSION['flash'] = '"' . $product['name'] . '" is no longer available.';
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            $_SESSION['flash'] = 'That product is no longer available.';
            $_SESSION['flash_type'] = 'error';
        }
    }
    header('Location: cart.php');
    exit;
}

// --- Build the display from the DB (prices come from the DB, never the client) ---
$cart     = $_SESSION['cart'];
$items    = [];
$subtotal = 0.0;
foreach ($cart as $pid => $qty) {
    $p = db_row_safe('SELECT * FROM products WHERE id = ? LIMIT 1', [(int) $pid]);
    if (!$p) continue;                            // stale entry — skip silently
    $qty  = max(1, (int) $qty);
    $line = effective_price($p) * $qty;
    $subtotal += $line;
    $items[] = ['p' => $p, 'qty' => $qty, 'line' => $line];
}

$page_title = 'Your Cart — ' . APP_NAME;
require __DIR__ . '/header.php';

/* ---------- Presentation-only totals (store.php is loaded by header.php) ---- */
$item_count = 0;
foreach ($items as $__it) { $item_count += (int) $__it['qty']; }
$shipping = shipping_fee_for($subtotal);
$total    = $subtotal + $shipping;
$to_free  = max(0, FREE_SHIPPING_THRESHOLD - $subtotal);
$progress = min(100, ($subtotal / FREE_SHIPPING_THRESHOLD) * 100);

// Category labels for the line items (prepared path; adds no injection surface).
$__cat_names = [];
foreach (db_all_safe('SELECT id, name FROM categories ORDER BY id') as $__c) {
    $__cat_names[(int) $__c['id']] = (string) $__c['name'];
}

// Flash is normally rendered by header.php; this covers the case where the
// session flash arrives after the header has already been emitted.
if (!empty($_SESSION['flash'])):
    $flash = (string) $_SESSION['flash'];
    $flashType = (($_SESSION['flash_type'] ?? 'error') === 'ok') ? 'ok' : 'error';
    unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
  <div class="shell px-6 lg:px-10 pt-8"><div class="flash flash-<?= $flashType ?>"><?= e($flash) ?></div></div>
<?php endif; ?>

<?php if (empty($items)): ?>
  <div class="mx-auto max-w-3xl px-6 py-20 lg:py-28">
    <div class="fade flex flex-col items-center justify-center border border-line bg-shell px-8 py-20 text-center">
      <span class="mb-6 flex h-14 w-14 items-center justify-center border border-line text-ink-muted">
        <?= icon('bag', 'h-6 w-6') ?>
      </span>
      <h3 class="display text-2xl text-ink">Your bag is empty</h3>
      <p class="mt-3 max-w-md text-sm leading-relaxed text-ink-soft">
        Once you add something it stays here, even if you close the tab.
      </p>
      <div class="mt-8"><a href="index.php?shop=1" class="btn btn-primary">Start shopping</a></div>
    </div>
  </div>
<?php else: ?>

<div class="mx-auto max-w-[1400px] px-6 py-12 lg:px-10 lg:py-16">
  <header class="border-b border-line pb-8">
    <p class="eyebrow text-ink-muted">Shopping bag</p>
    <h1 class="display mt-4 text-4xl text-ink sm:text-5xl">
      Your Bag
      <span class="ml-3 align-middle text-lg text-ink-muted"><?= (int) $item_count ?> <?= $item_count === 1 ? 'item' : 'items' ?></span>
    </h1>
  </header>

  <div class="mt-12 grid gap-12 lg:grid-cols-[1fr_380px] lg:gap-16">
    <div>
      <ul>
        <?php foreach ($items as $it): $p = $it['p']; ?>
          <li class="flex gap-5 border-b border-line py-7 first:pt-0 sm:gap-7">
            <a href="product.php?id=<?= (int) $p['id'] ?>" class="w-24 shrink-0 sm:w-28">
              <div class="aspect-[4/5] relative overflow-hidden bg-sand">
                <?php if (sample_image_url($p) !== ''): ?>
                  <img src="<?= e(sample_image_url($p)) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async"
                       class="h-full w-full object-cover">
                <?php else: ?>
                  <div class="flex h-full w-full items-center justify-center bg-sand text-ink-muted">
                    <span class="display text-3xl"><?= e(strtoupper(substr((string) $p['name'], 0, 2))) ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </a>

            <div class="flex min-w-0 flex-1 flex-col">
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <p class="eyebrow text-ink-muted"><?= e($__cat_names[(int) $p['category_id']] ?? '') ?></p>
                  <h3 class="mt-1.5 text-[15px] leading-snug text-ink">
                    <a href="product.php?id=<?= (int) $p['id'] ?>" class="link-underline"><?= e($p['name']) ?></a>
                  </h3>
                  <span class="mt-2 inline-flex items-baseline gap-2.5">
                    <span class="text-sm tabular-nums <?= is_on_sale($p) ? 'text-clay-deep' : 'text-ink' ?>"><?= peso(effective_price($p)) ?></span>
                    <?php if (is_on_sale($p)): ?>
                      <span class="text-xs tabular-nums text-ink-muted line-through"><?= peso($p['price']) ?></span>
                    <?php endif; ?>
                  </span>
                </div>

                <a href="cart.php?remove=<?= (int) $p['id'] ?>" aria-label="Remove <?= e($p['name']) ?> from your bag"
                   class="-mr-1 -mt-1 flex items-center gap-1.5 p-1.5 text-ink-muted transition-colors hover:text-rust">
                  <?= icon('trash', 'h-4 w-4') ?>
                </a>
              </div>

              <div class="mt-5 flex flex-wrap items-center justify-between gap-4">
                <?php
                  $stock = (int) $p['stock'];
                  $curQty = (int) $it['qty'];
                  $minusAttr = ($stock <= 0 || $curQty <= 1) ? 'disabled' : '';
                  $plusAttr  = ($stock <= 0 || $curQty >= $stock) ? 'disabled' : '';
                ?>
                <form method="post" action="cart.php" class="inline-flex items-stretch border border-line bg-shell">
                  <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                  <input type="hidden" name="quantity_mode" value="set">
                  <button type="submit" name="quantity" value="<?= $curQty - 1 ?>" <?= $minusAttr ?>
                          class="h-8 w-8 flex items-center justify-center text-ink-soft transition-colors hover:text-ink disabled:cursor-not-allowed disabled:text-ink-muted/40"
                          aria-label="Decrease quantity">
                    <?= icon('minus', 'h-3 w-3') ?>
                  </button>
                  <span class="h-8 w-10 border-x border-line flex items-center justify-center text-xs tabular-nums text-ink"><?= $curQty ?></span>
                  <button type="submit" name="quantity" value="<?= $curQty + 1 ?>" <?= $plusAttr ?>
                          class="h-8 w-8 flex items-center justify-center text-ink-soft transition-colors hover:text-ink disabled:cursor-not-allowed disabled:text-ink-muted/40"
                          aria-label="Increase quantity">
                    <?= icon('plus', 'h-3 w-3') ?>
                  </button>
                </form>
                <div class="text-right">
                  <p class="eyebrow text-ink-muted">Subtotal</p>
                  <p class="mt-1 text-[15px] tabular-nums text-ink"><?= peso($it['line']) ?></p>
                </div>
              </div>

              <?php if ((int) $p['stock'] > 0 && (int) $it['qty'] >= (int) $p['stock']): ?>
                <p class="mt-3 text-xs text-clay-deep">
                  Only <?= (int) $p['stock'] ?> in stock &mdash; that is the maximum we can ship right now.
                </p>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>

      <div class="mt-8 flex flex-wrap items-center justify-between gap-4">
        <a href="index.php?shop=1" class="eyebrow link-underline flex items-center gap-2 text-ink">
          <?= icon('chevronLeft', 'h-4 w-4') ?>
          Continue shopping
        </a>
        <a href="cart.php?clear=1" class="eyebrow link-underline text-ink-muted transition-colors hover:text-rust">
          Empty bag
        </a>
      </div>
    </div>

    <!-- Order summary (components/OrderSummary.jsx) -->
    <div class="lg:sticky lg:top-28 lg:self-start">
      <div class="border border-line bg-shell p-7">
        <h2 class="eyebrow border-b border-line pb-5 text-ink">Order Summary</h2>

        <dl class="space-y-4 pt-6 text-sm">
          <div class="flex items-center justify-between">
            <dt class="text-ink-soft">Subtotal</dt>
            <dd class="tabular-nums text-ink"><?= peso($subtotal) ?></dd>
          </div>
          <div class="flex items-center justify-between">
            <dt class="text-ink-soft">Shipping</dt>
            <dd class="tabular-nums text-ink">
              <?php if ($shipping <= 0): ?><span class="text-sage">Free</span><?php else: ?><?= peso($shipping) ?><?php endif; ?>
            </dd>
          </div>
        </dl>

        <div class="mt-6 border-t border-line pt-6">
          <div class="flex items-baseline justify-between">
            <span class="eyebrow text-ink">Total</span>
            <span class="text-xl tabular-nums text-ink"><?= peso($total) ?></span>
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
          <a href="checkout.php" class="btn btn-primary btn-lg btn-block">Proceed to Checkout</a>
          <p class="mt-4 text-center text-xs leading-relaxed text-ink-muted">
            <?= $user
                  ? 'Cash on Delivery. You can review everything before placing the order.'
                  : 'You will be asked to sign in before placing the order.' ?>
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
