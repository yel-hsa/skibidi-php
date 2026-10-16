<?php
/**
 * SKIBIDI — shared page chrome. Required by every page AFTER its logic.
 *
 * Mirrors the React storefront's Navbar + AccountMenu + MobileMenu so the port
 * is visually identical: ink announcement bar, sticky blurred header with a
 * centred wordmark, categories dropdown, search overlay, account menu and bag
 * badge with the cart count.
 *
 * Expects $user (from current_user()) and optionally $page_title in scope.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/icons.php';

if (!isset($user))       { $user = null; }
if (!isset($page_title)) { $page_title = APP_NAME; }

// A3 Stored XSS — secure path sends a Content-Security-Policy that neuters
// injected script. VULNERABLE path (no patch) sends NO CSP, so injected
// <script> runs. NOTE: script-src stays 'self' in both cases; the two extra
// font origins below only let the storefront load its own webfonts (without
// them the patched build would silently fall back to system fonts).
if (SECURE_MODE || PATCH_XSS) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:");
}

// Bag badge count — session cart is [product_id => qty], integers only.
$cart_count = 0;
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $__qty) { $cart_count += max(0, (int) $__qty); }
}

// Categories for the nav dropdown + mobile drawer. Always-prepared path:
// trusted catalogue data, never an A1 surface.
$nav_categories = function_exists('db_all_safe')
    ? db_all_safe('SELECT id, name, slug FROM categories ORDER BY id')
    : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($page_title) ?></title>
  <meta name="description" content="SKIBIDI is a premium toiletries and personal care store. Carefully selected bath, hair, oral and skin care essentials.">
  <link rel="icon" type="image/svg+xml" href="../assets/img/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/theme.css">
  <script src="../assets/js/site.js" defer></script>
</head>
<body>

<!-- Announcement bar -->
<div class="announce">
  <p class="eyebrow">Complimentary shipping on orders over &#8369;<?= number_format(FREE_SHIPPING_THRESHOLD) ?></p>
</div>

<header class="site-header" id="siteHeader">
  <div class="header-bar">

    <!-- Left: navigation -->
    <div class="header-nav">
      <button type="button" class="icon-btn -ml-2 lg:hidden" id="menuOpen" aria-label="Open menu">
        <?= icon('menu') ?>
      </button>

      <nav class="hidden lg:flex items-center gap-7">
        <a href="index.php?shop=1" class="eyebrow nav-link">Shop</a>

        <div class="relative" id="catWrap">
          <button type="button" class="eyebrow nav-link flex items-center gap-1.5" id="catToggle" aria-expanded="false" aria-haspopup="true">
            Categories
            <?= icon('chevronDown', 'h-3 w-3 transition-transform duration-300') ?>
          </button>

          <div class="menu menu-left w-64 hidden" id="catMenu">
            <?php foreach ($nav_categories as $__c): ?>
              <a class="menu-item" href="index.php?cat=<?= (int) $__c['id'] ?>"><?= e($__c['name']) ?></a>
            <?php endforeach; ?>
            <div class="menu-sep">
              <a class="eyebrow menu-item" href="index.php?shop=1">View all products</a>
            </div>
          </div>
        </div>

        <a href="index.php#about" class="eyebrow nav-link">About</a>
      </nav>
    </div>

    <!-- Centre: wordmark -->
    <a href="index.php" class="wordmark-link sm:text-2xl"><?= e(STORE_NAME) ?></a>

    <!-- Right: actions -->
    <div class="header-actions">
      <button type="button" class="icon-btn" id="searchToggle" aria-label="Search products" aria-expanded="false">
        <?= icon('search') ?>
      </button>

      <div class="relative hidden sm:block" id="acctWrap">
        <button type="button" class="icon-btn" id="acctToggle" aria-label="Account menu" aria-expanded="false" aria-haspopup="menu">
          <?= icon('user') ?>
        </button>

        <div class="menu menu-right w-60 hidden" id="acctMenu" role="menu">
          <?php if ($user): ?>
            <div class="menu-head">
              <p class="eyebrow text-ink-muted">Signed in as</p>
              <p class="mt-1.5 truncate text-sm text-ink"><?= e($user['name'] ?: $user['email']) ?></p>
            </div>
            <a class="menu-item" href="account.php" role="menuitem"><?= icon('user', 'h-4 w-4') ?> My Account</a>
            <a class="menu-item" href="account.php#orders" role="menuitem"><?= icon('receipt', 'h-4 w-4') ?> Order History</a>
            <?php if (is_admin($user)): ?>
              <a class="menu-item" href="admin.php" role="menuitem"><?= icon('grid', 'h-4 w-4') ?> Admin Dashboard</a>
            <?php endif; ?>
            <div class="menu-sep">
              <a class="menu-item" href="logout.php" role="menuitem"><?= icon('logout', 'h-4 w-4') ?> Sign Out</a>
            </div>
          <?php else: ?>
            <a class="menu-item" href="login.php" role="menuitem"><?= icon('lock', 'h-4 w-4') ?> Sign In</a>
            <a class="menu-item" href="register.php" role="menuitem"><?= icon('user', 'h-4 w-4') ?> Create Account</a>
          <?php endif; ?>
        </div>
      </div>

      <a href="cart.php" class="icon-btn relative" aria-label="Bag, <?= (int) $cart_count ?> item<?= $cart_count === 1 ? '' : 's' ?>">
        <?= icon('bag') ?>
        <?php if ($cart_count > 0): ?>
          <span class="bag-count"><?= $cart_count > 99 ? '99+' : (int) $cart_count ?></span>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <!-- Search overlay -->
  <div class="search-overlay hidden" id="searchPanel">
    <div class="shell max-w-3xl px-6 py-6">
      <form class="search-field" method="get" action="index.php" role="search">
        <?= icon('search', 'search-icon h-5 w-5') ?>
        <input type="search" name="q" id="navSearch"
               placeholder="Search shampoo, toothpaste, moisturiser&hellip;"
               aria-label="Search products"
               value="<?= e($_GET['q'] ?? '') ?>">
        <button type="button" class="search-clear" id="searchClear" aria-label="Clear search"><?= icon('close', 'h-4 w-4') ?></button>
      </form>
      <p class="mt-3 text-xs text-ink-muted">Press Enter to search the collection.</p>
    </div>
  </div>
</header>

<!-- Mobile drawer -->
<div class="fixed inset-0 z-50 flex-col bg-cream hidden lg:hidden" id="mobileMenu">
  <div class="flex items-center justify-between border-b px-6 py-4">
    <span class="wordmark text-lg text-ink"><?= e(STORE_NAME) ?></span>
    <button type="button" class="icon-btn" id="menuClose" aria-label="Close menu"><?= icon('close') ?></button>
  </div>

  <nav class="flex-1 overflow-y-auto px-6 py-8">
    <a href="index.php?shop=1" class="display block w-full text-left text-4xl text-ink">Shop All</a>

    <p class="eyebrow mt-10 text-ink-muted">Categories</p>
    <ul class="mt-4 space-y-3">
      <?php foreach ($nav_categories as $__c): ?>
        <li>
          <a href="index.php?cat=<?= (int) $__c['id'] ?>" class="display block text-2xl text-ink-soft transition-colors hover:text-ink"><?= e($__c['name']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="mt-10 border-t pt-8">
      <a href="index.php#about" class="display block text-2xl text-ink-soft transition-colors hover:text-ink">About</a>
      <a href="cart.php" class="display mt-3 block text-2xl text-ink-soft transition-colors hover:text-ink">Bag</a>
    </div>
  </nav>

  <div class="border-t px-6 py-6">
    <?php if ($user): ?>
      <div class="space-y-1">
        <p class="eyebrow text-ink-muted">Signed in as <?= e($user['name'] ?: $user['email']) ?></p>
        <a href="account.php" class="block py-2 text-sm text-ink">My Account</a>
        <?php if (is_admin($user)): ?>
          <a href="admin.php" class="block py-2 text-sm text-ink">Admin Dashboard</a>
        <?php endif; ?>
        <a href="logout.php" class="block py-2 text-sm text-rust">Sign Out</a>
      </div>
    <?php else: ?>
      <div class="flex gap-3">
        <a href="login.php" class="eyebrow flex-1 border border-ink py-3.5 text-center text-ink">Sign In</a>
        <a href="register.php" class="eyebrow flex-1 border border-ink bg-ink py-3.5 text-center text-cream">Register</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<main>
<?php
$__flash_blocks = db_errors_html();
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['flash'])):
    $__f = (string) $_SESSION['flash'];
    $__t = (($_SESSION['flash_type'] ?? 'error') === 'ok') ? 'ok' : 'error';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
    $__flash_blocks .= '<div class="flash flash-' . $__t . '">' . e($__f) . '</div>';
endif;
if ($__flash_blocks !== ''): ?>
  <div class="shell px-6 lg:px-10 pt-8"><?= $__flash_blocks ?></div>
<?php endif; ?>
