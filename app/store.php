<?php
/**
 * SKIBIDI — store-wide constants.
 *
 * Mirrors `lib/constants.js` in the original React storefront so the port speaks
 * the same vocabulary (store identity, shipping rules, stock threshold).
 * Every define is guarded so this file can be included from anywhere without
 * colliding with a value a page already set.
 */

if (!defined('STORE_NAME'))    define('STORE_NAME', 'SKIBIDI');
if (!defined('STORE_TAGLINE')) define('STORE_TAGLINE', 'Everyday Essentials. Better Chosen.');
if (!defined('STORE_EMAIL'))   define('STORE_EMAIL', 'support@skibidi.store');
if (!defined('STORE_PHONE'))   define('STORE_PHONE', '+63 917 000 0000');
if (!defined('STORE_HOURS'))   define('STORE_HOURS', 'Monday to Saturday, 9:00 - 18:00 PHT');

/** Shipping rules — PRD 6.4 in the original build. */
if (!defined('FREE_SHIPPING_THRESHOLD')) define('FREE_SHIPPING_THRESHOLD', 1500);
if (!defined('STANDARD_SHIPPING_FEE'))   define('STANDARD_SHIPPING_FEE', 100);

/** Stock level at or below which the storefront shows a "low stock" hint. */
if (!defined('LOW_STOCK_THRESHOLD')) define('LOW_STOCK_THRESHOLD', 5);

/** Order lifecycle — mirrors ORDER_STATUSES in constants.js. */
if (!defined('ORDER_STATUSES')) {
    define('ORDER_STATUSES', ['Pending', 'Processing', 'Shipped', 'Completed', 'Cancelled']);
}
require_once __DIR__ . '/sample_display.php';

/**
 * Shipping fee for a subtotal — the one place the rule is expressed, matching
 * `shippingFeeFor()` from the original lib/format.js.
 */
function shipping_fee_for($subtotal): float {
    return ((float) $subtotal) >= FREE_SHIPPING_THRESHOLD ? 0.0 : (float) STANDARD_SHIPPING_FEE;
}

/** Stock label + tone — mirrors `stockStatus()` from the original lib/format.js. */
function stock_status($stock): array {
    $count = (int) $stock;
    if ($count <= 0) {
        return ['label' => 'Out of Stock', 'tone' => 'out', 'available' => false, 'count' => $count];
    }
    if ($count <= LOW_STOCK_THRESHOLD) {
        return ['label' => 'Low Stock - ' . $count . ' left', 'tone' => 'low', 'available' => true, 'count' => $count];
    }
    return ['label' => 'In Stock', 'tone' => 'in', 'available' => true, 'count' => $count];
}

/** Match the React storefront's sale-price rule without trusting a posted price. */
function is_on_sale(array $product): bool {
    $price = (float) ($product['price'] ?? 0);
    $sale = (float) sample_display($product)['sale_price'];
    return $sale > 0 && $sale < $price;
}

function effective_price(array $product): float {
    return is_on_sale($product) ? (float) sample_display($product)['sale_price'] : (float) ($product['price'] ?? 0);
}

function discount_percent(array $product): int {
    if (!is_on_sale($product)) { return 0; }
    return (int) round((1 - (float) sample_display($product)['sale_price'] / (float) $product['price']) * 100);
}

/**
 * Human-readable status text — mirrors ORDER_STATUS_MEANING in constants.js.
 * Uses a small inline map so the thank-you page stays self-contained; the
 * canonical list still lives in the ORDER_STATUSES constant above.
 */
function order_status_meaning(string $status): string {
    $map = [
        'Pending'    => 'Order has been placed and is awaiting processing.',
        'Processing' => 'Order is being prepared.',
        'Shipped'    => 'Order has been released for delivery.',
        'Completed'  => 'Order is considered finished.',
        'Cancelled'  => 'Order has been cancelled.',
    ];
    return $map[$status] ?? $map['Pending'];
}

/**
 * Human-readable order reference, e.g. "SKB-M4KQ1P8AB".
 * Mirrors buildOrderNumber() in lib/format.js so both builds produce the
 * same format (PRD 6.5): "SKB-" + base-36 millisecond timestamp + 2-char noise.
 */
function build_order_number(): string {
    $stamp = strtoupper(base_convert((string) round(microtime(true) * 1000), 10, 36));
    $noise = strtoupper(str_pad(base_convert((string) random_int(0, 1295), 10, 36), 2, '0'));
    return 'SKB-' . $stamp . $noise;
}
