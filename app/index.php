<?php
/**
 * SKIBIDI — storefront: home page and shop (catalog, search, category, price, sort).
 * Search (?q=) and category filter (?cat=) are the A1 SQL-injection twins.
 *
 * The original React build had two routes, `/` (Home.jsx) and `/shop` (Shop.jsx).
 * This page renders the Home layout at index.php and the Shop layout at
 * index.php?shop=1 or whenever a filter is active.
 */
require __DIR__.'/config.php';
require __DIR__.'/db.php';
require __DIR__.'/auth.php';
require __DIR__.'/product_card.php';
auth_boot();
$user = current_user();

$q   = $_GET['q']   ?? '';
$cat = $_GET['cat'] ?? '';

if (SECURE_MODE || PATCH_SQLI) {
    // SECURE (A1 patched): parameterized
    if ($cat !== '') {
        $products = db_all_safe('SELECT * FROM products WHERE category_id = ? ORDER BY id', [(int)$cat]);
    } elseif ($q !== '') {
        $products = db_all_safe('SELECT * FROM products WHERE name LIKE ? ORDER BY id', ['%'.$q.'%']);
    } else {
        $products = db_all_safe('SELECT * FROM products ORDER BY id');
    }
} else {
    // VULNERABLE (A1 SQL injection): raw concatenation, input flows into SQL
    if ($cat !== '') {
        $products = db_all("SELECT * FROM products WHERE category_id = $cat ORDER BY id");
    } elseif ($q !== '') {
        $products = db_all("SELECT * FROM products WHERE name LIKE '%$q%' ORDER BY id");
    } else {
        $products = db_all("SELECT * FROM products ORDER BY id");
    }
}

// Category list is NOT a vuln surface — always use the prepared path.
$categories = db_all_safe('SELECT * FROM categories ORDER BY id');

// Resolve the active category name (from the already-loaded list; no extra query).
$active_cat = '';
if ($cat !== '') {
    foreach ($categories as $c) {
        if ((string)$c['id'] === (string)$cat) { $active_cat = $c['name']; break; }
    }
}

/* ==========================================================================
   Presentation state. Everything below shapes only the view: it is applied in
   PHP, exactly as lib/catalog.js filtered client-side in the React build, so
   none of it can widen the A1 surface above.
   ========================================================================== */

// $products is false when the toggled query errored — normalise for the view.
$product_rows = is_array($products) ? $products : [];

$category_names = [];
foreach ($categories as $c) { $category_names[(int) $c['id']] = $c['name']; }

$price = isset($_GET['price']) ? (string) $_GET['price'] : 'all';
$sort  = isset($_GET['sort'])  ? (string) $_GET['sort']  : 'featured';
$shop  = isset($_GET['shop']);

// Price brackets and sort order — mirrors PRICE_RANGES / SORT_OPTIONS.
$PRICE_RANGES = [
    'all'       => ['label' => 'All prices',   'min' => 0,   'max' => null],
    'under-200' => ['label' => 'Under &#8369;200', 'min' => 0,   'max' => 200],
    '200-400'   => ['label' => '&#8369;200 &#8211; &#8369;400', 'min' => 200, 'max' => 400],
    '400-700'   => ['label' => '&#8369;400 &#8211; &#8369;700', 'min' => 400, 'max' => 700],
    'over-700'  => ['label' => 'Over &#8369;700',  'min' => 700, 'max' => null],
];
$SORT_OPTIONS = [
    'featured'   => 'Featured',
    'newest'     => 'Newest',
    'price-asc'  => 'Price: Low to High',
    'price-desc' => 'Price: High to Low',
];
if (!isset($PRICE_RANGES[$price])) { $price = 'all'; }
if (!isset($SORT_OPTIONS[$sort]))  { $sort  = 'featured'; }

$has_filters = ($q !== '') || ($cat !== '') || ($price !== 'all');
$is_home     = !$shop && $q === '' && $cat === '' && $price === 'all' && $sort === 'featured';

// Featured products for the home page: mirror the original sample shelf, but
// leave admin-created products as the fallback if sample rows are removed.
$featured_pool = db_all_safe('SELECT * FROM products ORDER BY created_at DESC, id DESC');
$featured = array_values(array_filter($featured_pool, function ($p) {
    return sample_display($p)['featured'];
}));
if (!$featured) { $featured = $featured_pool; }
usort($featured, function ($a, $b) {
    $by_time = strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']);
    if ($by_time !== 0) { return $by_time; }
    $a_order = sample_order($a); $b_order = sample_order($b);
    if ($a_order && $b_order) { return $a_order <=> $b_order; }
    return (int) $b['id'] <=> (int) $a['id'];
});
$featured = array_slice($featured, 0, 4);

// The four categories the home page showcases (FEATURED_CATEGORY_NAMES).
$showcase = array_values(array_filter($categories, function ($c) {
    return in_array($c['name'], ['Bath & Body', 'Hair Care', 'Oral Care', 'Skin Care'], true);
}));

// Sidebar counts — a safe aggregate over the whole catalogue, not the filter.
$cat_counts = [];
$total_products = 0;
foreach (db_all_safe('SELECT category_id, COUNT(*) AS n FROM products GROUP BY category_id') as $row) {
    $cat_counts[(int) $row['category_id']] = (int) $row['n'];
    $total_products += (int) $row['n'];
}

// queryCatalog(): price bracket, then sort. Mirrors lib/catalog.js.
$results = [];
$bracket = $PRICE_RANGES[$price];
foreach ($product_rows as $p) {
    $amount = effective_price($p);
    if ($amount < $bracket['min']) { continue; }
    if ($bracket['max'] !== null && $amount > $bracket['max']) { continue; }
    $results[] = $p;
}
usort($results, function ($a, $b) use ($sort) {
    $a_time = strtotime((string) $a['created_at']);
    $b_time = strtotime((string) $b['created_at']);
    switch ($sort) {
        case 'price-asc':
            return (effective_price($a) <=> effective_price($b)) ?: ((int) $a['id'] <=> (int) $b['id']);
        case 'price-desc':
            return (effective_price($b) <=> effective_price($a)) ?: ((int) $a['id'] <=> (int) $b['id']);
        case 'featured':
            $by_feature = (int) sample_display($b)['featured'] <=> (int) sample_display($a)['featured'];
            if ($by_feature !== 0) { return $by_feature; }
            if ($b_time !== $a_time) { return $b_time <=> $a_time; }
            $a_order = sample_order($a); $b_order = sample_order($b);
            if ($a_order && $b_order) { return $a_order <=> $b_order; }
            return (int) $b['id'] <=> (int) $a['id'];
        default: // newest
            return ($b_time <=> $a_time) ?: ((int) $b['id'] <=> (int) $a['id']);
    }
});

/**
 * Build a sibling URL carrying the current filters, with overrides applied.
 * A null override removes the key, so the links stay shareable and the A1
 * parameters (?q / ?cat) survive a price or sort change.
 */
$url = function (array $overrides = []) {
    $params = $_GET;
    $params['shop'] = '1';
    unset($params['subscribed']);
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') { unset($params[$key]); }
        else { $params[$key] = $value; }
    }
    $qs = http_build_query($params);
    return 'index.php' . ($qs !== '' ? '?' . $qs : '');
};

$page_title = $is_home ? APP_NAME.' — '.STORE_TAGLINE
    : ($active_cat !== '' ? $active_cat : 'Shop').' — '.APP_NAME;
require __DIR__.'/header.php';
?>

<?php if ($is_home): ?>
<!-- ============================ HOME (Home.jsx) ============================ -->

<!-- Hero (PRD 6.1) -->
<section class="border-b border-line bg-cream">
  <div class="mx-auto grid max-w-[1400px] items-center gap-12 px-6 py-16 lg:grid-cols-[1fr_1fr] lg:gap-20 lg:px-10 lg:py-24">
    <div class="rise order-2 lg:order-1">
      <p class="eyebrow text-ink-muted">Toiletries &amp; Personal Care</p>

      <h1 class="display mt-7 text-[2.75rem] leading-[1.05] text-ink sm:text-6xl lg:text-[4.25rem]">
        Everyday Essentials.<br>
        <span class="italic text-clay-deep">Better Chosen.</span>
      </h1>

      <p class="mt-7 max-w-md text-[15px] leading-relaxed text-ink-soft">
        A short, considered range of bath, hair, oral and skin care. Formulas that do the job
        quietly, in packaging you will not want to hide.
      </p>

      <div class="mt-10 flex flex-wrap items-center gap-4">
        <a href="index.php?shop=1" class="btn btn-primary btn-lg">Shop Collection</a>
        <a href="index.php#about" class="btn btn-secondary btn-lg">Why <?= e(STORE_NAME) ?></a>
      </div>

      <dl class="mt-14 grid max-w-lg grid-cols-3 gap-6 border-t border-line pt-8">
        <div>
          <dt class="eyebrow text-ink-muted">Free shipping</dt>
          <dd class="mt-2 text-sm text-ink">Over <?= peso(FREE_SHIPPING_THRESHOLD) ?></dd>
        </div>
        <div>
          <dt class="eyebrow text-ink-muted">Payment</dt>
          <dd class="mt-2 text-sm text-ink">Cash on Delivery</dd>
        </div>
        <div>
          <dt class="eyebrow text-ink-muted">Categories</dt>
          <dd class="mt-2 text-sm text-ink">Five, no more</dd>
        </div>
      </dl>
    </div>

    <div class="rise delay-2 order-1 lg:order-2">
      <div class="relative">
        <img src="../assets/img/hero.svg" alt="A group of <?= e(STORE_NAME) ?> daily ritual bottles and jars"
             class="w-full border border-line object-cover">
        <div class="absolute -bottom-5 -left-5 hidden items-center gap-3 border border-line bg-shell px-5 py-4 sm:flex lg:-left-8">
          <?= icon('leaf', 'h-5 w-5 text-sage') ?>
          <div>
            <p class="eyebrow text-ink-muted"><?= e(STORE_NAME) ?> promise</p>
            <p class="mt-1 text-sm text-ink">Carefully selected, never overdone</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Featured categories -->
<section class="mx-auto max-w-[1400px] px-6 py-20 lg:px-10 lg:py-28">
  <div class="flex flex-wrap items-end justify-between gap-6">
    <div>
      <p class="eyebrow text-ink-muted">Browse by category</p>
      <h2 class="display mt-4 text-3xl text-ink sm:text-4xl">Find your routine</h2>
    </div>
    <a href="index.php?shop=1" class="eyebrow link-underline flex items-center gap-2 text-ink">
      All products
      <?= icon('arrowRight', 'h-4 w-4') ?>
    </a>
  </div>

  <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
    <?php foreach ($showcase as $index => $c): ?>
      <a href="index.php?cat=<?= (int) $c['id'] ?>" class="group rise delay-<?= min($index + 1, 4) ?> block">
        <div class="relative overflow-hidden border border-line">
          <img src="<?= e($c['image_url']) ?>" alt="<?= e($c['name']) ?>" loading="lazy"
               class="aspect-[4/3] w-full object-cover transition-transform duration-[900ms] ease-out group-hover:scale-[1.05]">
        </div>
        <div class="mt-5 flex items-start justify-between gap-3">
          <div>
            <h3 class="text-[15px] text-ink"><?= e($c['name']) ?></h3>
            <p class="mt-1.5 text-xs leading-relaxed text-ink-muted"><?= e($c['description']) ?></p>
          </div>
          <?= icon('arrowRight', 'mt-1 h-4 w-4 shrink-0 text-ink-muted transition-all duration-300 group-hover:translate-x-1 group-hover:text-ink') ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- Featured products -->
<section class="border-t border-line bg-shell">
  <div class="mx-auto max-w-[1400px] px-6 py-20 lg:px-10 lg:py-28">
    <div class="flex flex-wrap items-end justify-between gap-6">
      <div>
        <p class="eyebrow text-ink-muted">Chosen for you</p>
        <h2 class="display mt-4 text-3xl text-ink sm:text-4xl">Featured this month</h2>
      </div>
      <a href="index.php?shop=1" class="btn btn-secondary">Shop all</a>
    </div>

    <div class="mt-12">
      <?php if (empty($featured)): ?>
        <div class="fade flex flex-col items-center justify-center border border-line bg-shell px-8 py-20 text-center">
          <span class="mb-6 flex h-14 w-14 items-center justify-center border border-line text-ink-muted">
            <?= icon('search', 'h-6 w-6') ?>
          </span>
          <h3 class="display text-2xl text-ink">No products yet</h3>
          <p class="mt-3 max-w-md text-sm leading-relaxed text-ink-soft">
            Sign in as an administrator and add your first products, or load the sample catalog from Admin &rsaquo; Products.
          </p>
          <div class="mt-8"><a href="index.php?shop=1" class="btn btn-primary">Go to shop</a></div>
        </div>
      <?php else: ?>
        <div class="grid grid-cols-2 gap-x-6 gap-y-12 md:grid-cols-3 xl:grid-cols-4">
          <?php foreach ($featured as $index => $p): ?>
            <div class="rise<?= $index < 4 ? ' delay-' . ($index + 1) : '' ?>">
              <?php render_product_card($p, $category_names[(int) $p['category_id']] ?? ''); ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Why SKIBIDI -->
<section id="about" class="scroll-mt-28 border-t border-line bg-cream">
  <div class="mx-auto max-w-[1400px] px-6 py-20 lg:px-10 lg:py-28">
    <div class="max-w-2xl">
      <p class="eyebrow text-ink-muted">Why <?= e(STORE_NAME) ?></p>
      <h2 class="display mt-4 text-3xl text-ink sm:text-4xl">A smaller shelf, chosen properly</h2>
      <p class="mt-5 text-[15px] leading-relaxed text-ink-soft">
        We stock the things people actually reach for every day, and we stop there. It keeps
        choosing simple and quality consistent.
      </p>
    </div>

    <div class="mt-14 grid gap-10 border-t border-line pt-12 sm:grid-cols-3 sm:gap-8">
      <div>
        <?= icon('leaf', 'h-6 w-6 text-clay') ?>
        <h3 class="mt-5 text-base text-ink">Carefully Selected</h3>
        <p class="mt-3 text-sm leading-relaxed text-ink-soft">
          A short list instead of a long one. Every product earns its place on the shelf before it reaches yours.
        </p>
      </div>
      <div>
        <?= icon('sparkle', 'h-6 w-6 text-clay') ?>
        <h3 class="mt-5 text-base text-ink">Quality Essentials</h3>
        <p class="mt-3 text-sm leading-relaxed text-ink-soft">
          Straightforward formulas for daily use &mdash; gentle enough to repeat, effective enough to notice.
        </p>
      </div>
      <div>
        <?= icon('clock', 'h-6 w-6 text-clay') ?>
        <h3 class="mt-5 text-base text-ink">Convenient Shopping</h3>
        <p class="mt-3 text-sm leading-relaxed text-ink-soft">
          Browse, add to your bag and pay on delivery. No accounts to chase, no hidden steps at checkout.
        </p>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__.'/newsletter_band.php'; ?>

<?php else: ?>
<!-- ============================ SHOP (Shop.jsx) ============================ -->

<header class="border-b border-line bg-cream">
  <div class="mx-auto max-w-[1400px] px-6 py-14 lg:px-10 lg:py-20">
    <p class="eyebrow text-ink-muted">The collection</p>
    <h1 class="display mt-5 text-4xl text-ink sm:text-5xl">
      <?= $active_cat !== '' ? e($active_cat) : 'All Products' ?>
    </h1>
    <p class="mt-4 max-w-xl text-sm leading-relaxed text-ink-soft">
      <?= count($results) ?> <?= count($results) === 1 ? 'product' : 'products' ?><?= $has_filters ? ' matching your filters' : ' available' ?>.
    </p>
  </div>
</header>

<div class="mx-auto max-w-[1400px] px-6 py-12 lg:px-10 lg:py-16">
  <div class="grid gap-10 lg:grid-cols-[220px_1fr] lg:gap-14">

    <!-- Sidebar: category + price -->
    <aside class="hidden lg:block">
      <div class="sticky top-28 space-y-10">
        <div>
          <h2 class="eyebrow border-b border-line pb-4 text-ink">Category</h2>
          <ul class="mt-4 space-y-1">
            <li>
              <a href="<?= e($url(['cat' => null])) ?>" aria-pressed="<?= $cat === '' ? 'true' : 'false' ?>"
                 class="flex w-full items-center justify-between py-2 text-left text-sm transition-colors <?= $cat === '' ? 'text-ink' : 'text-ink-soft hover:text-ink' ?>">
                <span class="flex items-center gap-2.5">
                  <span class="<?= $cat === '' ? 'w-6 bg-ink' : 'w-4 bg-line-strong' ?> h-px transition-all duration-300" aria-hidden="true"></span>
                  All Products
                </span>
                <span class="text-xs tabular-nums text-ink-muted"><?= (int) $total_products ?></span>
              </a>
            </li>
            <?php foreach ($categories as $c): $__id = (int) $c['id']; $__active = ((string) $c['id'] === (string) $cat); ?>
              <li>
                <a href="<?= e($url(['cat' => $__id])) ?>" aria-pressed="<?= $__active ? 'true' : 'false' ?>"
                   class="flex w-full items-center justify-between py-2 text-left text-sm transition-colors <?= $__active ? 'text-ink' : 'text-ink-soft hover:text-ink' ?>">
                  <span class="flex items-center gap-2.5">
                    <span class="<?= $__active ? 'w-6 bg-ink' : 'w-4 bg-line-strong' ?> h-px transition-all duration-300" aria-hidden="true"></span>
                    <?= e($c['name']) ?>
                  </span>
                  <span class="text-xs tabular-nums text-ink-muted"><?= (int) ($cat_counts[$__id] ?? 0) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div>
          <h2 class="eyebrow border-b border-line pb-4 text-ink">Price</h2>
          <ul class="mt-4 space-y-1">
            <?php foreach ($PRICE_RANGES as $__key => $__range): $__active = ($__key === $price); ?>
              <li>
                <a href="<?= e($url(['price' => $__key === 'all' ? null : $__key])) ?>" aria-pressed="<?= $__active ? 'true' : 'false' ?>"
                   class="flex w-full items-center gap-2.5 py-2 text-left text-sm transition-colors <?= $__active ? 'text-ink' : 'text-ink-soft hover:text-ink' ?>">
                  <span class="<?= $__active ? 'w-6 bg-ink' : 'w-4 bg-line-strong' ?> h-px transition-all duration-300" aria-hidden="true"></span>
                  <?= $__range['label'] ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <?php if ($has_filters): ?>
          <a href="index.php?shop=1" class="eyebrow link-underline text-clay-deep">Clear all filters</a>
        <?php endif; ?>
      </div>
    </aside>

    <div>
      <!-- Toolbar: search + sort + mobile filters -->
      <div class="flex flex-col gap-5 border-b border-line pb-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
          <div class="flex-1">
            <form role="search" method="get" action="index.php" class="relative w-full">
              <input type="hidden" name="shop" value="1">
              <?php foreach (['cat' => $cat, 'price' => $price, 'sort' => $sort] as $__k => $__v): ?>
                <?php if ($__v !== '' && !($__k === 'price' && $__v === 'all') && !($__k === 'sort' && $__v === 'featured')): ?>
                  <input type="hidden" name="<?= e($__k) ?>" value="<?= e($__v) ?>">
                <?php endif; ?>
              <?php endforeach; ?>
              <?= icon('search', 'pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-ink-muted') ?>
              <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search the collection"
                     aria-label="Search the collection"
                     class="w-full border border-line bg-shell py-3 pl-11 pr-10 text-sm text-ink placeholder:text-ink-muted transition-colors hover:border-line-strong focus:border-ink focus:outline-none">
            </form>
          </div>

          <form method="get" action="index.php" class="flex items-center gap-3">
            <input type="hidden" name="shop" value="1">
            <?php foreach (['q' => $q, 'cat' => $cat, 'price' => $price] as $__k => $__v): ?>
              <?php if ($__v !== '' && !($__k === 'price' && $__v === 'all')): ?>
                <input type="hidden" name="<?= e($__k) ?>" value="<?= e($__v) ?>">
              <?php endif; ?>
            <?php endforeach; ?>
            <label class="eyebrow shrink-0 text-ink-muted" for="sort">Sort</label>
            <select id="sort" name="sort" class="field py-3 sm:w-52" data-auto-submit>
              <?php foreach ($SORT_OPTIONS as $__key => $__label): ?>
                <option value="<?= e($__key) ?>"<?= $__key === $sort ? ' selected' : '' ?>><?= e($__label) ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-quiet btn-sm">Apply</button></noscript>
          </form>
        </div>

        <!-- Mobile: category pills + price select -->
        <div class="space-y-4 lg:hidden">
          <div class="no-scrollbar -mx-6 flex gap-2 overflow-x-auto px-6 pb-1">
            <a href="<?= e($url(['cat' => null])) ?>" aria-pressed="<?= $cat === '' ? 'true' : 'false' ?>"
               class="eyebrow shrink-0 border px-4 py-2.5 transition-colors <?= $cat === '' ? 'border-ink bg-ink text-cream' : 'border-line bg-shell text-ink-soft hover:border-ink hover:text-ink' ?>">All Products</a>
            <?php foreach ($categories as $c): $__active = ((string) $c['id'] === (string) $cat); ?>
              <a href="<?= e($url(['cat' => (int) $c['id']])) ?>" aria-pressed="<?= $__active ? 'true' : 'false' ?>"
                 class="eyebrow shrink-0 border px-4 py-2.5 transition-colors <?= $__active ? 'border-ink bg-ink text-cream' : 'border-line bg-shell text-ink-soft hover:border-ink hover:text-ink' ?>"><?= e($c['name']) ?></a>
            <?php endforeach; ?>
          </div>
          <form method="get" action="index.php" class="flex items-center gap-3">
            <input type="hidden" name="shop" value="1">
            <?php foreach (['q' => $q, 'cat' => $cat, 'sort' => $sort] as $__k => $__v): ?>
              <?php if ($__v !== '' && !($__k === 'sort' && $__v === 'featured')): ?>
                <input type="hidden" name="<?= e($__k) ?>" value="<?= e($__v) ?>">
              <?php endif; ?>
            <?php endforeach; ?>
            <label class="eyebrow shrink-0 text-ink-muted" for="price-compact">Price</label>
            <select id="price-compact" name="price" class="field py-2.5" data-auto-submit>
              <?php foreach ($PRICE_RANGES as $__key => $__range): ?>
                <option value="<?= e($__key) ?>"<?= $__key === $price ? ' selected' : '' ?>><?= $__range['label'] ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      </div>

      <div class="pt-12 shop-results">
        <?php if (empty($results)): ?>
          <div class="fade flex flex-col items-center justify-center border border-line bg-shell px-8 py-20 text-center">
            <span class="mb-6 flex h-14 w-14 items-center justify-center border border-line text-ink-muted">
              <?= icon('search', 'h-6 w-6') ?>
            </span>
            <h3 class="display text-2xl text-ink">
              <?= $has_filters ? 'No products match those filters' : 'The shelf is empty' ?>
            </h3>
            <p class="mt-3 max-w-md text-sm leading-relaxed text-ink-soft">
              <?= $has_filters
                    ? 'Try a different search term, category or price range.'
                    : 'No products have been added yet. An administrator can add them from Admin &rsaquo; Products.' ?>
            </p>
            <?php if ($has_filters): ?>
              <div class="mt-8"><a href="index.php?shop=1" class="btn btn-primary">Clear filters</a></div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="grid grid-cols-2 gap-x-6 gap-y-12 md:grid-cols-3 xl:grid-cols-4">
            <?php foreach ($results as $index => $p): ?>
              <div class="rise<?= $index < 4 ? ' delay-' . ($index + 1) : '' ?>">
                <?php render_product_card($p, $category_names[(int) $p['category_id']] ?? ''); ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
<?php require __DIR__.'/footer.php'; ?>
