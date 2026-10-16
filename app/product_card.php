<?php
/**
 * SKIBIDI — storefront product card, a 1:1 port of components/ProductCard.jsx.
 *
 * One <article class="group"> in a 4/5 image ratio, with the out-of-stock veil
 * and the Add to Bag bar that slides up on hover (pointer devices) and stays
 * visible on touch. In React the bar was a <button onClick>; here it is a small
 * POST form to cart.php carrying the same product_id/quantity pair.
 *
 * The original card's sale and Featured badges use the same product fields here.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/icons.php';

/**
 * @param array  $p            product row
 * @param string $category     category name to print above the title
 * @param string $delay_class  one of delay-1 … delay-4, or ''
 */
function render_product_card(array $p, string $category = '', string $delay_class = ''): void {
    $status   = stock_status($p['stock'] ?? 0);
    $display  = sample_display($p);
    $on_sale  = is_on_sale($p);
    $name     = (string) $p['name'];
    $img      = sample_image_url($p);
    $initials = '';
    foreach (array_slice(array_values(array_filter(explode(' ', $name))), 0, 2) as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    if ($initials === '') { $initials = 'SK'; }
    ?>
    <article class="group flex flex-col">
      <div class="relative overflow-hidden">
        <a href="product.php?id=<?= (int) $p['id'] ?>" class="block" aria-label="<?= e($name) ?>">
          <div class="aspect-[4/5] relative overflow-hidden bg-sand">
            <?php if ($img !== ''): ?>
              <img src="<?= e($img) ?>" alt="<?= e($name) ?>" loading="lazy" decoding="async" data-image-fallback
                   class="h-full w-full object-cover transition-transform duration-[900ms] ease-out group-hover:scale-[1.04]">
              <div class="hidden h-full w-full flex-col items-center justify-center gap-2 bg-sand text-ink-muted" data-image-placeholder>
                <span class="display text-3xl"><?= e($initials) ?></span>
                <span class="eyebrow">No image</span>
              </div>
            <?php else: ?>
              <div class="flex h-full w-full flex-col items-center justify-center gap-2 bg-sand text-ink-muted">
                <span class="display text-3xl"><?= e($initials) ?></span>
                <span class="eyebrow">No image</span>
              </div>
            <?php endif; ?>
          </div>
        </a>

        <?php if ($on_sale || $display['featured']): ?>
          <div class="pointer-events-none absolute left-4 top-4 flex flex-col items-start gap-2">
            <?php if ($on_sale): ?>
              <span class="eyebrow inline-flex items-center border border-ink bg-ink px-2.5 py-1 text-cream"><?= discount_percent($p) ?>% off</span>
            <?php endif; ?>
            <?php if ($display['featured']): ?>
              <span class="eyebrow inline-flex items-center border border-line-strong bg-sand px-2.5 py-1 text-ink-soft">Featured</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (!$status['available']): ?>
          <div class="absolute inset-0 flex items-center justify-center bg-cream/70">
            <span class="eyebrow border border-ink/20 bg-shell px-3 py-1.5 text-ink">Out of Stock</span>
          </div>
        <?php endif; ?>

        <div class="add-bar">
          <form method="post" action="cart.php">
            <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="quantity" value="1">
            <button type="submit" <?= $status['available'] ? '' : 'disabled' ?>
                    class="eyebrow flex w-full items-center justify-center gap-2 border border-ink bg-ink/95 py-3 text-cream backdrop-blur transition-colors hover:bg-ink disabled:cursor-not-allowed disabled:border-line disabled:bg-shell/90 disabled:text-ink-muted">
              <?= icon('bag', 'h-3.5 w-3.5') ?>
              <?= $status['available'] ? 'Add to Bag' : 'Out of Stock' ?>
            </button>
          </form>
        </div>
      </div>

      <div class="flex flex-1 flex-col pt-5">
        <?php if ($category !== ''): ?>
          <p class="eyebrow text-ink-muted"><?= e($category) ?></p>
        <?php endif; ?>
        <h3 class="mt-2 text-[15px] leading-snug text-ink">
          <a href="product.php?id=<?= (int) $p['id'] ?>" class="link-underline"><?= e($name) ?></a>
        </h3>
        <div class="mt-3 flex items-center justify-between gap-3">
          <span class="inline-flex items-baseline gap-2.5">
            <span class="text-base tabular-nums <?= $on_sale ? 'text-clay-deep' : 'text-ink' ?>"><?= peso(effective_price($p)) ?></span>
            <?php if ($on_sale): ?>
              <span class="text-sm tabular-nums text-ink-muted line-through"><?= peso($p['price']) ?></span>
            <?php endif; ?>
          </span>
          <?php if ($status['tone'] === 'low'): ?>
            <span class="text-[11px] text-clay-deep"><?= (int) $status['count'] ?> left</span>
          <?php elseif ($status['tone'] === 'in'): ?>
            <span class="text-[11px] text-ink-muted">In Stock</span>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php
}
