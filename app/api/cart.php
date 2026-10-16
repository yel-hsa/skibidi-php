<?php
/**
 * SKIBIDI — JSON cart API for the JS-enhanced storefront.
 *
 * Mirrors the React storefront's client-side cart mutations by exposing a
 * tiny JSON surface: POST to add an item, GET to read the current count.
 * Every mutation reuses the exact same session-cart logic as cart.php so the
 * hardening (stock clamping, product validation) stays in one place.
 *
 * The A3 patch's `script-src 'self'` policy does NOT block this endpoint — it
 * serves JSON, not executable markup.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
auth_boot();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

/**
 * Recompute the total item count from the session cart.
 * (Matches the badge math in header.php so the two never drift.)
 */
function cart_item_count(): int {
    $count = 0;
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $__qty) {
            $count += max(0, (int) $__qty);
        }
    }
    return $count;
}

// --- POST: add to bag (AJAX path) -------------------------------------------
// Same validation as cart.php's POST handler, but returns JSON instead of
// redirecting. This lets the product card form be hijacked via fetch().
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int) ($_POST['product_id'] ?? 0);
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));

    if ($pid > 0) {
        // Validate the product exists before trusting the posted id.
        $product = db_row_safe('SELECT id, name, stock FROM products WHERE id = ? LIMIT 1', [$pid]);
        if ($product) {
            $stock  = (int) $product['stock'];
            $have   = (int) ($_SESSION['cart'][$pid] ?? 0);
            $newQty = ($stock > 0) ? min($have + $qty, $stock) : 0;

            if ($newQty > 0) {
                $_SESSION['cart'][$pid] = $newQty;
                $_SESSION['flash']      = 'Added "' . $product['name'] . '" to your cart.';
                $_SESSION['flash_type'] = 'ok';
            } else {
                unset($_SESSION['cart'][$pid]);
                $_SESSION['flash']      = '"' . $product['name'] . '" is no longer available.';
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            $_SESSION['flash']      = 'That product is no longer available.';
            $_SESSION['flash_type'] = 'error';
        }
    }

    // Echo back the new count so the badge can be updated without a round-trip.
    echo json_encode([
        'ok'          => true,
        'count'       => cart_item_count(),
        'flash'       => (string) ($_SESSION['flash'] ?? ''),
        'flash_type'  => (($_SESSION['flash_type'] ?? 'error') === 'ok') ? 'ok' : 'error',
    ]);
    unset($_SESSION['flash'], $_SESSION['flash_type']);
    exit;
}

// --- GET: read the current bag count (for badge sync) -----------------------
echo json_encode([
    'ok'    => true,
    'count' => cart_item_count(),
]);
