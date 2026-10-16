<?php
/**
 * SKIBIDI — newsletter sign-up band, a 1:1 port of components/Newsletter.jsx.
 *
 * The React component held local state for the idle/done/error transitions. Here
 * the form is a real POST to newsletter.php, which redirects back to
 * index.php?subscribed=1 on success (PRG) — so the "done" panel is driven by
 * that query flag instead of component state.
 *
 * Include it from any page with:  require __DIR__.'/newsletter_band.php';
 */
require_once __DIR__ . '/icons.php';

$__subscribed = isset($_GET['subscribed']) && (string) $_GET['subscribed'] === '1';
?>
<section class="border-y border-line bg-sand">
  <div class="mx-auto max-w-2xl px-6 py-20 text-center sm:py-24">
    <p class="eyebrow text-ink-muted">The <?= e(STORE_NAME) ?> Edit</p>
    <h2 class="display mt-5 text-3xl text-ink sm:text-4xl">
      Considered essentials, straight to your inbox
    </h2>
    <p class="mx-auto mt-4 max-w-md text-sm leading-relaxed text-ink-soft">
      New arrivals, restocks and quiet routines. One email a month, nothing more.
    </p>

    <?php if ($__subscribed): ?>
      <div class="fade mt-9 flex items-center justify-center gap-2.5 border border-sage/30 bg-sage/5 px-6 py-4 text-sm text-sage">
        <?= icon('check', 'h-4 w-4') ?>
        You are on the list. Look out for our next edit.
      </div>
    <?php else: ?>
      <form method="post" action="newsletter.php"
            class="mx-auto mt-9 flex max-w-md flex-col gap-3 sm:flex-row">
        <label class="sr-only" for="newsletter-email">Email address</label>
        <input id="newsletter-email" type="email" name="email" required
               class="field flex-1" placeholder="you@example.com">
        <button type="submit" class="btn btn-primary">Subscribe</button>
      </form>
    <?php endif; ?>
  </div>
</section>
