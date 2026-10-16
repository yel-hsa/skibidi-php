<?php
/**
 * SKIBIDI — product detail + reviews (list + submit).
 * Product-by-id (?id=) is an A1 SQL-injection twin; the review body OUTPUT is the A3 Stored-XSS twin.
 */
require __DIR__.'/config.php';
require __DIR__.'/db.php';
require __DIR__.'/auth.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/product_card.php';
auth_boot();
$user = current_user();

$id = $_GET['id'] ?? '';
if (SECURE_MODE || PATCH_SQLI) {
    $product = db_row_safe('SELECT * FROM products WHERE id = ? LIMIT 1', [(int)$id]);
} else {
    // VULNERABLE (A1 SQL injection)
    $product = db_row("SELECT * FROM products WHERE id = $id LIMIT 1");
}
$product_id = $product ? (int)$product['id'] : 0;

// Review submission — any logged-in user may post. Insert uses the ALWAYS-safe path
// (the insert is NOT the vuln); body is stored RAW so the A3 payload survives to OUTPUT.
$review_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $product && $user) {
    $rating = (int)($_POST['rating'] ?? 0);
    $body   = $_POST['body'] ?? '';                 // RAW on purpose (no input sanitization)
    if ($rating < 1 || $rating > 5) {
        $review_error = 'Please choose a rating between 1 and 5.';
    } elseif (trim($body) === '') {
        $review_error = 'Please write something in your review.';
    } else {
        db_exec_safe(
            'INSERT INTO reviews (product_id,user_id,author_name,rating,body) VALUES (?,?,?,?,?)',
            [(int)$product_id, (int)$user['id'], $user['name'], (int)$rating, $body]
        );
        // Post/Redirect/Get so a refresh doesn't resubmit the review.
        header('Location: product.php?id='.$product_id.'&submitted=1');
        exit;
    }
}

// Reviews list is NOT a vuln surface — always use the prepared path.
$reviews = $product
    ? db_all_safe('SELECT * FROM reviews WHERE product_id = ? ORDER BY created_at DESC', [(int)$product_id])
    : [];

/* ==========================================================================
   Presentation-only queries. None of these touch a toggled helper, so they add
   no new injection surface — the A1 twin above stays the only one on this page.
   ========================================================================== */

$category_name = '';
$related       = [];
if ($product) {
    $cat = db_row_safe('SELECT name FROM categories WHERE id = ? LIMIT 1', [(int)$product['category_id']]);
    $category_name = $cat ? (string) $cat['name'] : '';

    // relatedProducts(): same category, never the product you are looking at.
    $related = db_all_safe(
        'SELECT * FROM products WHERE category_id = ? AND id <> ? ORDER BY created_at DESC, id DESC LIMIT 4',
        [(int)$product['category_id'], (int)$product_id]
    );
    if (count($related) < 4) {
        $fallback = db_all_safe(
            'SELECT * FROM products WHERE (category_id <> ? OR category_id IS NULL) AND id <> ? ORDER BY created_at DESC, id DESC',
            [(int)$product['category_id'], (int)$product_id]
        );
        usort($fallback, function ($a, $b) {
            $by_time = strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']);
            if ($by_time !== 0) { return $by_time; }
            $a_order = sample_order($a); $b_order = sample_order($b);
            if ($a_order && $b_order) { return $a_order <=> $b_order; }
            return (int) $b['id'] <=> (int) $a['id'];
        });
        $related = array_slice(array_merge($related, $fallback), 0, 4);
    }
}

$status  = $product ? stock_status($product['stock'] ?? 0) : null;
$in_bag  = $product ? (int) ($_SESSION['cart'][$product_id] ?? 0) : 0;
// React parity: "remaining" is in-stock minus what is already bagged, floored at 0.
$remaining = $product ? max(0, (int) $product['stock'] - $in_bag) : 0;
$can_add   = $product ? ($status['available'] && $remaining > 0) : false;
$rating_avg = 0.0;
if (!empty($reviews)) {
    $sum = 0;
    foreach ($reviews as $r) { $sum += (int) $r['rating']; }
    $rating_avg = $sum / count($reviews);
}

$page_title = ($product ? $product['name'] : 'Product').' — '.APP_NAME;
require __DIR__.'/header.php';
?>

<div class="mx-auto max-w-[1400px] px-6 pt-8 lg:px-10">
  <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-2 text-xs text-ink-muted">
    <a href="index.php" class="hover:text-ink">Home</a>
    <?= icon('chevronRight', 'h-3 w-3') ?>
    <a href="index.php?shop=1" class="hover:text-ink">Shop</a>
    <?php if ($category_name !== ''): ?>
      <?= icon('chevronRight', 'h-3 w-3') ?>
      <a href="index.php?cat=<?= (int) $product['category_id'] ?>" class="hover:text-ink"><?= e($category_name) ?></a>
    <?php endif; ?>
    <?= icon('chevronRight', 'h-3 w-3') ?>
    <span class="text-ink"><?= e($product ? $product['name'] : 'Not found') ?></span>
  </nav>
</div>

<?php if (!$product): ?>
  <div class="mx-auto max-w-2xl px-6 py-24">
    <div class="fade flex flex-col items-center justify-center border border-line bg-shell px-8 py-20 text-center">
      <span class="mb-6 flex h-14 w-14 items-center justify-center border border-line text-ink-muted">
        <?= icon('search', 'h-6 w-6') ?>
      </span>
      <h3 class="display text-2xl text-ink">We could not find that product</h3>
      <p class="mt-3 max-w-md text-sm leading-relaxed text-ink-soft">
        It may have been removed from the catalog, or the link is out of date.
      </p>
      <div class="mt-8"><a href="index.php?shop=1" class="btn btn-primary">Back to shop</a></div>
    </div>
  </div>
<?php else: ?>

<section class="mx-auto max-w-[1400px] px-6 py-10 lg:px-10 lg:py-16">
  <div class="grid gap-12 lg:grid-cols-2 lg:gap-20">

    <div class="rise relative">
      <div class="aspect-[4/5] relative overflow-hidden border border-line bg-sand">
        <?php if (sample_image_url($product) !== ''): ?>
          <img src="<?= e(sample_image_url($product)) ?>" alt="<?= e($product['name']) ?>" loading="lazy" decoding="async" data-image-fallback
               class="h-full w-full object-cover">
          <div class="hidden h-full w-full flex-col items-center justify-center gap-2 bg-sand text-ink-muted" data-image-placeholder>
            <span class="display text-3xl"><?= e(strtoupper(substr((string) $product['name'], 0, 2))) ?></span>
            <span class="eyebrow">No image</span>
          </div>
        <?php else: ?>
          <div class="flex h-full w-full flex-col items-center justify-center gap-2 bg-sand text-ink-muted">
            <span class="display text-3xl"><?= e(strtoupper(substr((string) $product['name'], 0, 2))) ?></span>
            <span class="eyebrow">No image</span>
          </div>
        <?php endif; ?>
      </div>
      <?php if (is_on_sale($product)): ?>
        <div class="absolute left-5 top-5">
          <span class="eyebrow inline-flex items-center border border-ink bg-ink px-2.5 py-1 text-cream"><?= discount_percent($product) ?>% off</span>
        </div>
      <?php endif; ?>
    </div>

    <div class="rise delay-1 lg:py-4">
      <?php if ($category_name !== ''): ?>
        <p class="eyebrow text-ink-muted"><?= e($category_name) ?></p>
      <?php endif; ?>
      <h1 class="display mt-4 text-4xl text-ink sm:text-5xl"><?= e($product['name']) ?></h1>

      <div class="mt-6 flex flex-wrap items-center gap-4">
        <span class="inline-flex items-baseline gap-2.5">
          <span class="text-2xl tabular-nums <?= is_on_sale($product) ? 'text-clay-deep' : 'text-ink' ?>"><?= peso(effective_price($product)) ?></span>
          <?php if (is_on_sale($product)): ?>
            <span class="text-base tabular-nums text-ink-muted line-through"><?= peso($product['price']) ?></span>
          <?php endif; ?>
        </span>
        <?php if ($status['tone'] === 'in'): ?>
          <span class="eyebrow inline-flex items-center border px-2.5 py-1 bg-sage/10 text-sage border-sage/30"><?= e($status['label']) ?></span>
        <?php elseif ($status['tone'] === 'low'): ?>
          <span class="eyebrow inline-flex items-center border px-2.5 py-1 bg-clay/10 text-clay-deep border-clay/40"><?= e($status['label']) ?></span>
        <?php else: ?>
          <span class="eyebrow inline-flex items-center border px-2.5 py-1 bg-rust/10 text-rust border-rust/30"><?= e($status['label']) ?></span>
        <?php endif; ?>
      </div>

      <p class="mt-8 text-[15px] leading-relaxed text-ink-soft"><?= e($product['description']) ?></p>

      <div class="mt-10 border-t border-line pt-8">
        <?php if ($status['available']): ?>
          <form method="post" action="cart.php">
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
            <div class="flex flex-wrap items-end gap-6">
              <div>
                <label class="field-label" for="quantity">Quantity</label>
                <div class="inline-flex items-stretch border border-line bg-shell">
                  <button type="button" class="h-11 w-11 flex items-center justify-center text-ink-soft transition-colors hover:text-ink"
                          data-step="-1" data-target="quantity" aria-label="Decrease quantity">
                    <?= icon('minus', 'h-3.5 w-3.5') ?>
                  </button>
                  <input id="quantity" type="number" name="quantity" value="1" min="1" max="<?= (int) $remaining ?>"
                         class="h-11 w-14 border-x border-line bg-transparent text-center text-sm tabular-nums text-ink focus:outline-none"
                         aria-label="Quantity">
                  <button type="button" class="h-11 w-11 flex items-center justify-center text-ink-soft transition-colors hover:text-ink"
                          data-step="1" data-target="quantity" aria-label="Increase quantity">
                    <?= icon('plus', 'h-3.5 w-3.5') ?>
                  </button>
                </div>
              </div>
              <p class="pb-3 text-xs text-ink-muted">
                <?= $in_bag > 0 ? (int) $in_bag . ' already in your bag · ' : '' ?><?= (int) $remaining ?> available
              </p>
            </div>

            <div class="mt-7 flex flex-col gap-3 sm:flex-row">
              <button type="submit" class="btn btn-primary btn-lg sm:flex-1">
                <?= icon('bag', 'h-4 w-4') ?>
                Add to Bag
              </button>
              <a href="checkout.php?buy_now=<?= (int) $product_id ?>" class="btn btn-secondary btn-lg sm:flex-1">
                Buy Now
              </a>
            </div>
            <?php if (!$can_add): ?>
              <p class="mt-4 text-xs text-clay-deep">
                Your bag already holds every unit we have in stock.
              </p>
            <?php endif; ?>
          </form>
        <?php else: ?>
          <div class="border border-line bg-sand px-6 py-5">
            <p class="text-sm text-ink">This product is currently out of stock.</p>
            <p class="mt-1.5 text-xs text-ink-soft">
              Check back soon, or browse the rest of <?= e($category_name !== '' ? $category_name : 'the catalog') ?>.
            </p>
            <?php if ($category_name !== ''): ?>
              <div class="mt-5">
                <a href="index.php?cat=<?= (int) $product['category_id'] ?>" class="btn btn-secondary btn-sm">Browse <?= e($category_name) ?></a>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <dl class="mt-10 divide-y divide-line border-t border-line text-sm">
        <div class="flex justify-between gap-6 py-4">
          <dt class="text-ink-muted">Category</dt>
          <dd class="text-ink"><?= e($category_name !== '' ? $category_name : '—') ?></dd>
        </div>
        <div class="flex justify-between gap-6 py-4">
          <dt class="text-ink-muted">Availability</dt>
          <dd class="text-ink"><?= e($status['label']) ?></dd>
        </div>
        <div class="flex justify-between gap-6 py-4">
          <dt class="text-ink-muted">Item code</dt>
          <dd class="text-ink tabular-nums">SK-<?= str_pad((string) $product_id, 4, '0', STR_PAD_LEFT) ?></dd>
        </div>
        <div class="flex justify-between gap-6 py-4">
          <dt class="text-ink-muted">Shipping</dt>
          <dd class="max-w-[60%] text-right text-ink">
            <?= peso(STANDARD_SHIPPING_FEE) ?>, free over <?= peso(FREE_SHIPPING_THRESHOLD) ?>
          </dd>
        </div>
        <div class="flex justify-between gap-6 py-4">
          <dt class="text-ink-muted">Payment</dt>
          <dd class="text-ink">Cash on Delivery</dd>
        </div>
      </dl>
    </div>
  </div>
</section>

<!-- Reviews — the A3 Stored-XSS surface. The list is the OUTPUT side of the bug. -->
<section id="reviews" class="scroll-mt-28 border-t border-line bg-shell">
  <div class="mx-auto max-w-[1400px] px-6 py-20 lg:px-10">
    <div class="grid gap-12 lg:grid-cols-[1fr_420px] lg:gap-20">
      <div>
        <p class="eyebrow text-ink-muted">Customer notes</p>
        <h2 class="display mt-4 text-3xl text-ink">Reviews</h2>

        <?php if (!empty($reviews)): ?>
          <p class="mt-3 text-xs text-ink-muted">
            <?= count($reviews) ?> <?= count($reviews) === 1 ? 'review' : 'reviews' ?>
            &middot; <?= number_format($rating_avg, 1) ?> average
          </p>
        <?php endif; ?>

        <?php if (isset($_GET['submitted'])): ?>
          <div class="flash flash-ok mt-6">Thanks! Your review has been posted.</div>
        <?php endif; ?>
        <?php if ($review_error !== ''): ?>
          <div class="flash flash-error mt-6"><?= e($review_error) ?></div>
        <?php endif; ?>

        <?php if (empty($reviews)): ?>
          <p class="mt-8 text-sm leading-relaxed text-ink-soft">No reviews yet. Be the first to review this product.</p>
        <?php else: ?>
          <div class="mt-8 divide-y divide-line border-t border-line">
            <?php foreach ($reviews as $r): ?>
              <article class="py-8">
                <div class="flex flex-wrap items-center gap-3">
                  <span class="eyebrow text-ink"><?= e($r['author_name']) ?></span>
                  <span class="stars" aria-label="<?= (int) $r['rating'] ?> out of 5">
                    <?php for ($n = 1; $n <= 5; $n++): ?>
                      <?= icon('star', 'h-3.5 w-3.5' . ($n <= (int) $r['rating'] ? '' : ' opacity-50')) ?>
                    <?php endfor; ?>
                  </span>
                  <span class="text-xs text-ink-muted"><?= e(fmt_date($r['created_at'])) ?></span>
                </div>
                <div class="review-body mt-4 max-w-2xl text-sm leading-relaxed text-ink-soft">
        <?php if (SECURE_MODE || PATCH_XSS): ?>
            <?= e($r['body']) ?>                     <!-- SECURE: output-encoded -->
        <?php else: ?>
            <?= $r['body'] ?>                          <!-- VULNERABLE (A3 Stored XSS): raw -->
        <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Write a review -->
      <div class="lg:pt-10">
        <div class="panel p-6">
          <h3 class="display text-xl text-ink">Write a review</h3>
          <?php if ($user): ?>
            <form method="post" action="product.php?id=<?= (int) $product['id'] ?>" class="mt-6 space-y-5">
              <div>
                <label class="field-label" for="rating">Rating</label>
                <select name="rating" id="rating" class="field">
                  <?php for ($n = 5; $n >= 1; $n--): ?>
                    <option value="<?= $n ?>"><?= $n ?> / 5</option>
                  <?php endfor; ?>
                </select>
              </div>
              <div>
                <label class="field-label" for="body">Your review</label>
                <textarea name="body" id="body" rows="4" class="field" placeholder="What did you think?"></textarea>
              </div>
              <button type="submit" class="btn btn-primary btn-block">Submit review</button>
            </form>
          <?php else: ?>
            <p class="mt-4 text-sm leading-relaxed text-ink-soft">
              <a href="login.php" class="link-underline text-ink">Log in</a> to write a review.
            </p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if (!empty($related)): ?>
  <section class="border-t border-line bg-cream">
    <div class="mx-auto max-w-[1400px] px-6 py-20 lg:px-10">
      <p class="eyebrow text-ink-muted">You may also like</p>
      <h2 class="display mt-4 text-3xl text-ink">Related products</h2>
      <div class="mt-12 grid grid-cols-2 gap-x-6 gap-y-12 md:grid-cols-3 xl:grid-cols-4">
        <?php foreach ($related as $index => $rp): ?>
          <div class="rise<?= $index < 4 ? ' delay-' . ($index + 1) : '' ?>">
            <?php render_product_card($rp, $category_name); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php endif; ?>
<?php require __DIR__.'/footer.php'; ?>
