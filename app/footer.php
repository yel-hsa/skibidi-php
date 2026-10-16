<?php
/**
 * SKIBIDI — shared page footer. Mirrors the React storefront's Footer.jsx:
 * four columns (brand, Shop, Your Account, Support) over the cream surface,
 * closing with the copyright / tagline bar.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/icons.php';

$__nav_categories = (isset($nav_categories) && is_array($nav_categories))
    ? $nav_categories
    : (function_exists('db_all_safe') ? db_all_safe('SELECT id, name FROM categories ORDER BY id') : []);
?>
</main>

<footer class="site-footer">
  <div class="shell px-6 lg:px-10 py-16">
    <div class="footer-cols">

      <div>
        <a href="index.php" class="wordmark text-xl text-ink"><?= e(STORE_NAME) ?></a>
        <p class="mt-5 max-w-xs text-sm leading-relaxed text-ink-soft">
          Toiletries and personal care chosen for everyday use. Simple formulas, honest prices,
          nothing you do not need.
        </p>
      </div>

      <div>
        <h3 class="eyebrow text-ink">Shop</h3>
        <ul class="footer-list">
          <li><a href="index.php?shop=1" class="link-underline">All Products</a></li>
          <?php foreach ($__nav_categories as $__c): ?>
            <li><a href="index.php?cat=<?= (int) $__c['id'] ?>" class="link-underline"><?= e($__c['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div>
        <h3 class="eyebrow text-ink">Your Account</h3>
        <ul class="footer-list">
          <li><a href="account.php" class="link-underline">Profile</a></li>
          <li><a href="account.php#orders" class="link-underline">Order History</a></li>
          <li><a href="cart.php" class="link-underline">Shopping Bag</a></li>
          <li><a href="register.php" class="link-underline">Create Account</a></li>
        </ul>
      </div>

      <div>
        <h3 class="eyebrow text-ink">Support</h3>
        <ul class="footer-list space-y-3.5 text-ink-soft">
          <li class="flex items-start gap-2.5">
            <?= icon('mail', 'mt-0.5 h-4 w-4 shrink-0 text-ink-muted') ?>
            <a href="mailto:<?= e(STORE_EMAIL) ?>" class="link-underline"><?= e(STORE_EMAIL) ?></a>
          </li>
          <li class="flex items-start gap-2.5">
            <?= icon('phone', 'mt-0.5 h-4 w-4 shrink-0 text-ink-muted') ?>
            <a href="tel:<?= e(str_replace(' ', '', STORE_PHONE)) ?>" class="link-underline"><?= e(STORE_PHONE) ?></a>
          </li>
          <li class="flex items-start gap-2.5">
            <?= icon('clock', 'mt-0.5 h-4 w-4 shrink-0 text-ink-muted') ?>
            <span><?= e(STORE_HOURS) ?></span>
          </li>
          <li class="flex items-start gap-2.5">
            <?= icon('truck', 'mt-0.5 h-4 w-4 shrink-0 text-ink-muted') ?>
            <span><?= peso(STANDARD_SHIPPING_FEE) ?> shipping, free over <?= peso(FREE_SHIPPING_THRESHOLD) ?>. Cash on Delivery.</span>
          </li>
        </ul>
      </div>
    </div>

    <div class="footer-bottom sm:flex-row sm:items-center sm:justify-between">
      <p>&copy; <?= date('Y') ?> <?= e(STORE_NAME) ?>. A university project store &mdash; orders are not real purchases.</p>
      <p><?= e(STORE_TAGLINE) ?></p>
    </div>
  </div>
</footer>

</body>
</html>
