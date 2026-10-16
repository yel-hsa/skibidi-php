<?php
/**
 * SKIBIDI — admin.php (admin-only product management). HARDENED: no deliberate vuln.
 * Access control is server-side (is_admin reads the role from the DB row). Every
 * mutation is a POST with action + id through an always-prepared statement; reads
 * use the always-prepared db_*_safe path.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
auth_boot();
$user = current_user();

// Admin gate — role comes only from the DB, never from the client.
if (!is_admin($user)) { header('Location: login.php'); exit; }

// --- Mutations (POST action + fields), then PRG back to admin.php ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $name        = trim((string) ($_POST['name'] ?? ''));
        $description = (string) ($_POST['description'] ?? '');
        $price       = max(0, (float) ($_POST['price'] ?? 0));
        $stock       = max(0, (int) ($_POST['stock'] ?? 0));
        $category_id = (($_POST['category_id'] ?? '') !== '') ? (int) $_POST['category_id'] : null;
        $image_url   = trim((string) ($_POST['image_url'] ?? ''));
        if ($name !== '') {
            db_exec_safe(
                'INSERT INTO products (name, description, price, stock, category_id, image_url)
                 VALUES (?,?,?,?,?,?)',
                [$name, $description, $price, $stock, $category_id, $image_url]
            );
            $_SESSION['flash'] = 'Product added.'; $_SESSION['flash_type'] = 'ok';
        } else {
            $_SESSION['flash'] = 'Product name is required.'; $_SESSION['flash_type'] = 'error';
        }
    } elseif ($action === 'edit') {
        $id    = (int) ($_POST['id'] ?? 0);
        $price = max(0, (float) ($_POST['price'] ?? 0));
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        if ($id > 0) {
            db_exec_safe('UPDATE products SET price = ?, stock = ? WHERE id = ?', [$price, $stock, $id]);
            $_SESSION['flash'] = 'Product updated.'; $_SESSION['flash_type'] = 'ok';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            db_exec_safe('DELETE FROM products WHERE id = ?', [$id]);
            $_SESSION['flash'] = 'Product deleted.'; $_SESSION['flash_type'] = 'ok';
        }
    } elseif ($action === 'update_status') {
        // Move an order through its lifecycle (parity with the Firebase
        // AdminOrders status dropdown). Status is whitelisted — the five
        // values match $status_tone below and the original ORDER_STATUSES.
        $id       = (int) ($_POST['id'] ?? 0);
        $newStat  = (string) ($_POST['status'] ?? '');
        $allowed  = ['Pending', 'Processing', 'Shipped', 'Completed', 'Cancelled'];
        if ($id > 0 && in_array($newStat, $allowed, true)) {
            db_exec_safe('UPDATE orders SET status = ? WHERE id = ?', [$newStat, $id]);
            $_SESSION['flash'] = 'Order status updated to ' . $newStat . '.';
            $_SESSION['flash_type'] = 'ok';
        } else {
            $_SESSION['flash'] = 'Invalid order status.'; $_SESSION['flash_type'] = 'error';
        }
    }
    header('Location: admin.php');
    exit;
}
// --- Reads (always-prepared) ----------------------------------------------
$products   = db_all_safe(
    'SELECT p.*, c.name AS category_name
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      ORDER BY p.id'
);
$categories = db_all_safe('SELECT * FROM categories ORDER BY name');
$orders     = db_all_safe('SELECT * FROM orders ORDER BY created_at DESC, id DESC LIMIT 50');
$subRow     = db_row_safe('SELECT COUNT(*) AS c FROM newsletter');
$subCount   = $subRow ? (int) $subRow['c'] : 0;

$page_title = 'Admin — ' . APP_NAME;
require __DIR__ . '/header.php';

/* ---------- Presentation-only: status pill tones (lib/constants.js) -------- */
$status_tone = [
    'Pending'    => 'bg-sand text-ink-soft border-line-strong',
    'Processing' => 'bg-clay/10 text-clay-deep border-clay/30',
    'Shipped'    => 'bg-sage/10 text-sage border-sage/30',
    'Completed'  => 'bg-ink text-cream border-ink',
    'Cancelled'  => 'bg-rust/10 text-rust border-rust/30',
];
// The five lifecycle states (mirrors the Firebase ORDER_STATUSES).
$order_statuses = ['Pending', 'Processing', 'Shipped', 'Completed', 'Cancelled'];

// Flash is normally rendered by header.php; this covers the case where the
// session flash arrives after the header has already been emitted.
if (!empty($_SESSION['flash'])):
    $flash = (string) $_SESSION['flash'];
    $flashType = (($_SESSION['flash_type'] ?? 'error') === 'ok') ? 'ok' : 'error';
    unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
  <div class="shell px-6 lg:px-10 pt-8"><div class="flash flash-<?= $flashType ?>"><?= e($flash) ?></div></div>
<?php endif; ?>

<div class="mx-auto max-w-[1500px] px-6 py-8 lg:px-10 lg:py-12">

  <header class="flex flex-wrap items-end justify-between gap-6 border-b border-line pb-6">
    <div>
      <p class="eyebrow text-clay-deep">Admin</p>
      <h1 class="display mt-3 text-3xl text-ink">Dashboard</h1>
      <p class="mt-2 text-sm text-ink-soft">
        <?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?> &middot;
        <?= count($orders) ?> recent <?= count($orders) === 1 ? 'order' : 'orders' ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="index.php?shop=1" class="btn btn-secondary btn-sm">
        <?= icon('bag', 'h-3.5 w-3.5') ?>
        View store
      </a>
      <a href="account.php" class="btn btn-ghost btn-sm">
        <?= icon('user', 'h-3.5 w-3.5') ?>
        My account
      </a>
    </div>
  </header>

  <div class="mt-8 grid gap-6 sm:grid-cols-3">
    <div class="border border-line bg-shell px-6 py-5">
      <p class="eyebrow text-ink-muted">Products</p>
      <p class="display mt-2 text-3xl tabular-nums text-ink"><?= count($products) ?></p>
    </div>
    <div class="border border-line bg-shell px-6 py-5">
      <p class="eyebrow text-ink-muted">Recent orders</p>
      <p class="display mt-2 text-3xl tabular-nums text-ink"><?= count($orders) ?></p>
    </div>
    <div class="border border-line bg-shell px-6 py-5">
      <p class="eyebrow text-ink-muted">Newsletter signups</p>
      <p class="display mt-2 text-3xl tabular-nums text-ink"><?= (int) $subCount ?></p>
    </div>
  </div>

  <section class="mt-12">
    <h2 class="eyebrow border-b border-line pb-4 text-ink">Products</h2>

    <?php if (empty($products)): ?>
      <p class="mt-6 text-sm text-ink-soft">No products yet.</p>
    <?php else: ?>
      <div class="mt-6 overflow-x-auto border border-line bg-shell">
        <table class="data-table">
          <thead>
            <tr class="border-b border-line bg-sand/60">
              <th>ID</th><th>Name</th><th>Category</th><th>Price &amp; stock</th><th>Actions</th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <tr>
                <td class="tabular-nums text-ink-muted"><?= (int) $p['id'] ?></td>
                <td class="text-ink"><?= e($p['name']) ?></td>
                <td class="text-ink-soft"><?= e($p['category_name'] ?? '—') ?></td>
                <td>
                  <form method="post" action="admin.php" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <input class="field field-compact w-[110px]" type="number" step="0.01" min="0" name="price"
                           value="<?= e($p['price']) ?>" aria-label="Price for <?= e($p['name']) ?>">
                    <input class="field field-compact w-[90px]" type="number" min="0" name="stock"
                           value="<?= (int) $p['stock'] ?>" aria-label="Stock for <?= e($p['name']) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">Save</button>
                  </form>
                </td>
                <td>
                  <span class="eyebrow text-ink-muted tabular-nums">SK-<?= str_pad((string) $p['id'], 4, '0', STR_PAD_LEFT) ?></span>
                </td>
                <td>
                  <form method="post" action="admin.php" data-confirm="Delete this product?">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="eyebrow link-underline text-ink-muted transition-colors hover:text-rust">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="mt-12">
    <h2 class="eyebrow border-b border-line pb-4 text-ink">Add product</h2>
    <div class="mt-6 max-w-2xl border border-line bg-shell p-8">
      <form method="post" action="admin.php" class="space-y-5">
        <input type="hidden" name="action" value="add">

        <div>
          <label class="field-label" for="name">Name <span class="ml-1 text-clay">*</span></label>
          <input class="field" type="text" id="name" name="name" required>
        </div>

        <div>
          <label class="field-label" for="description">Description</label>
          <textarea class="field" id="description" name="description" rows="3"></textarea>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
          <div>
            <label class="field-label" for="price">Price</label>
            <input class="field" type="number" step="0.01" min="0" id="price" name="price" value="0">
          </div>
          <div>
            <label class="field-label" for="stock">Stock</label>
            <input class="field" type="number" min="0" id="stock" name="stock" value="0">
          </div>
        </div>

        <div>
          <label class="field-label" for="category_id">Category</label>
          <select class="field" id="category_id" name="category_id">
            <option value="">— none —</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="field-label" for="image_url">Image URL</label>
          <input class="field" type="text" id="image_url" name="image_url" placeholder="../assets/img/example.svg">
        </div>

        <button type="submit" class="btn btn-primary btn-block">
          <?= icon('plus', 'h-3.5 w-3.5') ?>
          Add product
        </button>
      </form>
    </div>
  </section>

  <section class="mt-12">
    <h2 class="eyebrow border-b border-line pb-4 text-ink">Recent orders</h2>

    <?php if (empty($orders)): ?>
      <p class="mt-6 text-sm text-ink-soft">No orders yet.</p>
    <?php else: ?>
      <div class="mt-6 overflow-x-auto border border-line bg-shell">
        <table class="data-table">
          <thead>
            <tr class="border-b border-line bg-sand/60">
              <th>Ref</th><th>Customer</th><th>Email</th><th>Total</th><th>Status</th><th>Placed</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
              <tr>
                <td class="text-ink tabular-nums"><?= e($o['order_ref']) ?></td>
                <td class="text-ink"><?= e($o['customer_name']) ?></td>
                <td class="text-ink-soft"><?= e($o['customer_email']) ?></td>
                <td class="tabular-nums text-ink"><?= peso($o['total']) ?></td>
                <td>
                  <form method="post" action="admin.php" class="flex items-center gap-2">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                    <select class="field field-compact w-auto <?= $status_tone[$o['status']] ?? $status_tone['Pending'] ?>"
                            name="status" aria-label="Status for <?= e($o['order_ref']) ?>">
                      <?php foreach ($order_statuses as $st): ?>
                        <option value="<?= $st ?>" <?= $st === $o['status'] ? 'selected' : '' ?>><?= $st ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-ghost btn-sm">Save</button>
                  </form>
                </td>
                <td class="text-ink-soft"><?= e(fmt_date($o['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/footer.php'; ?>
