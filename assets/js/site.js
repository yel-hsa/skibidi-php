/* ==========================================================================
   SKIBIDI — storefront interactions (vanilla, no dependencies).

   The original React storefront drove these with component state: the sticky
   header's scrolled border, the categories dropdown, the account menu, the
   mobile drawer and the search overlay. This is the same behaviour, re-expressed
   with event listeners so it works against server-rendered PHP.

   Extra enhancements over the plain server fallback:
   • Shop filters (category links, sort <select>, price <select>) use
     history.pushState + fetch so only the product grid swaps — no full reload.
   • "Add to Bag" forms POST via fetch to api/cart.php, update the bag badge,
     and surface a toast — matching the React storefront's instant feedback.

   It lives in an external file on purpose: the A3 patch sends
   `script-src 'self'`, which blocks inline handlers. Everything here therefore
   attaches with addEventListener and no inline `onclick` attributes exist.
   ========================================================================== */
(function () {
  'use strict';

  /** Toggle a `hidden` class on an element. */
  function setOpen(el, open) {
    if (!el) return;
    if (open) {
      el.classList.remove('hidden');
      el.classList.remove('fade');
      // Re-trigger the fade-in animation on each open.
      void el.offsetWidth;
      el.classList.add('fade');
    } else {
      el.classList.add('hidden');
    }
  }

  /* ==========================================================================
     TOAST — lightweight JS-driven notification (mirrors React toast).
     ========================================================================== */
  function showToast(message, type) {
    type = type || 'ok';
    var container = document.getElementById('toastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toastContainer';
      container.className = 'skibidi-toasts';
      document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', type === 'ok' ? 'polite' : 'assertive');
    toast.textContent = message;
    container.appendChild(toast);
    // Auto-dismiss after 3s
    setTimeout(function () {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 3000);
  }

  /** Update the bag badge count from a numeric value. */
  function updateBagBadge(count) {
    var badge = document.querySelector('.bag-count');
    if (count > 0) {
      if (!badge) {
        var bagLink = document.querySelector('a[aria-label*="Bag"]');
        if (!bagLink) bagLink = document.querySelector('a[href="cart.php"]');
        if (bagLink) {
          badge = document.createElement('span');
          badge.className = 'bag-count';
          bagLink.appendChild(badge);
        }
      }
      if (badge) badge.textContent = count > 99 ? '99+' : String(count);
    } else if (badge) {
      badge.remove();
    }
  }

  /* ==========================================================================
     SHOP GRID INTERCEPTION — pushState + fetch for category/sort/price.
     ========================================================================== */
  var isShopPage = typeof window.__SKIBIDI_SHOP !== 'undefined' && window.__SKIBIDI_SHOP;
  // Fallback: detect the shop by the presence of a data-shop attribute on <main>
  // or by the URL containing shop=1.
  if (!isShopPage) {
    isShopPage = document.body && document.body.getAttribute('data-page') === 'shop';
  }
  if (!isShopPage) {
    var m = window.location.search.match(/[?&]shop=1/);
    isShopPage = !!m;
  }

  /**
   * Fetch a new URL and replace only the shop grid section.
   * The grid lives in a <div class="pt-12"> inside the shop layout; we target
   * it by its surrounding structure and swap its innerHTML.
   */
  function fetchAndSwapGrid(url, label) {
    label = label || 'shop';
    var gridWrapper = document.querySelector('.shop-results');
    if (!gridWrapper) {
      // Not on the shop page — fall back to a full navigation.
      window.location.href = url;
      return;
    }
    // Show a brief loading state
    var originalHTML = gridWrapper.innerHTML;
    gridWrapper.classList.add('is-loading');
    fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (resp) { return resp.text(); })
      .then(function (html) {
        // Extract just the .shop-results inner content from the response.
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var newContent = doc.querySelector('.shop-results');
        if (newContent) {
          gridWrapper.innerHTML = newContent.innerHTML;
        } else {
          gridWrapper.innerHTML = originalHTML;
        }
        // Re-bind add-to-bag forms on newly swapped cards
        bindAddToBagForms();
      })
      .catch(function () {
        gridWrapper.innerHTML = originalHTML;
        showToast('Something went wrong while updating the products.', 'error');
      })
      .finally(function () {
        gridWrapper.classList.remove('is-loading');
      });
  }

  /** Bind all shop filter interactions: category links + data-auto-submit selects. */
  function bindShopFilters() {
    if (!isShopPage) return;

    // Category / price / "clear filters" anchor links
    document.querySelectorAll('aside a[href*="index.php"], .lg\\:hidden a[href*="index.php"]').forEach(function (link) {
      // Only intercept shop-related links (cat=, price=, shop=1, clear)
      var href = link.getAttribute('href') || '';
      if (!isShopLink(href)) return;
      link.addEventListener('click', function (event) {
        // Don't hijack search form links or non-shop pages
        if (event.metaKey || event.ctrlKey || event.button === 1) return; // let browser handle
        event.preventDefault();
        var url = link.href;
        // Build the URL with proper shop=1
        var u = new URL(url, window.location.origin);
        if (!u.searchParams.has('shop')) {
          u.searchParams.set('shop', '1');
        }
        history.pushState({ url: u.href }, '', u.href);
        fetchAndSwapGrid(u.href, 'shop');
      });
    });

    // Mobile category pills
    var mobilePills = document.querySelector('.no-scrollbar');
    if (mobilePills) {
      mobilePills.querySelectorAll('a[href*="index.php"]').forEach(function (link) {
        var href = link.getAttribute('href') || '';
        if (!isShopLink(href)) return;
        link.addEventListener('click', function (event) {
          if (event.metaKey || event.ctrlKey || event.button === 1) return;
          event.preventDefault();
          var url = link.href;
          var u = new URL(url, window.location.origin);
          if (!u.searchParams.has('shop')) u.searchParams.set('shop', '1');
          history.pushState({ url: u.href }, '', u.href);
          fetchAndSwapGrid(u.href, 'shop');
        });
      });
    }

    // Sort / price selects with data-auto-submit
    document.querySelectorAll('select[data-auto-submit]').forEach(function (select) {
      select.addEventListener('change', function () {
        if (!select.form) return;
        var formData = new FormData(select.form);
        // Ensure shop=1 is present so the server renders the shop layout
        if (!formData.has('shop')) formData.set('shop', '1');
        var params = new URLSearchParams();
        for (var pair of formData.entries()) {
          params.set(pair[0], pair[1]);
        }
        var url = 'index.php?' + params.toString();
        history.pushState({ url: url }, '', url);
        fetchAndSwapGrid(url, 'shop');
      });
    });

    // Search form: hijack submit to fetch results
    var searchForm = document.querySelector('form[role="search"]');
    if (searchForm) {
      searchForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var formData = new FormData(searchForm);
        if (!formData.has('shop')) formData.set('shop', '1');
        var params = new URLSearchParams();
        for (var pair of formData.entries()) {
          params.set(pair[0], pair[1]);
        }
        var url = 'index.php?' + params.toString();
        history.pushState({ url: url }, '', url);
        fetchAndSwapGrid(url, 'search');
      });
    }

    // Handle back/forward — if the target URL doesn't have shop-results in it,
    // we're leaving the shop, so fall back to a full navigation.
    window.addEventListener('popstate', function () {
      var href = window.location.href;
      // If the URL still has shop=1 (or any shop filter param), stay SPA.
      if (isShopLink(href)) {
        fetchAndSwapGrid(href, 'nav');
      } else {
        // No shop params — full page load for the new route.
        window.location.href = href;
      }
    });
  }

  /** Check if a link href is a shop filter link (cat=, price=, shop=, clear). */
  function isShopLink(href) {
    return /index\.php.*[?&](cat|price|shop|q)=/.test(href) ||
           href.indexOf('index.php?shop=1') !== -1 ||
           href === 'index.php?shop=1';
  }

  /* ==========================================================================
     ADD-TO-BAG VIA FETCH — intercept product card forms.
     ========================================================================== */
  function bindAddToBagForms() {
    document.querySelectorAll('form[action="cart.php"]').forEach(function (form) {
      // Only intercept the product-card add-to-bag forms (which have product_id
      // but NOT quantity_mode=set — the cart page's +/- forms do that).
      var productIdInput = form.querySelector('input[name="product_id"]');
      if (!productIdInput) return; // not a product-card form
      var modeInput = form.querySelector('input[name="quantity_mode"]');
      if (modeInput) return; // cart page stepper form — leave native

      // Avoid double-binding
      if (form.hasAttribute('data-js-bound')) return;
      form.setAttribute('data-js-bound', 'true');

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn && submitBtn.disabled) return;

        // Disable the button during the request
        var originalLabel = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<span class="flex items-center justify-center gap-2"><span class="animate-spin h-3.5 w-3.5 border border-current border-t-transparent rounded-full"></span>Adding…</span>';
        }

        var formData = new FormData(form);

        fetch('api/cart.php', {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
          .then(function (resp) { return resp.json(); })
          .then(function (data) {
            if (data.ok) {
              updateBagBadge(data.count);
              if (data.flash) showToast(data.flash, data.flash_type);
            } else {
              showToast(data.flash || 'Could not add that product.', 'error');
            }
          })
          .catch(function () {
            showToast('Network error — please try again.', 'error');
          })
          .finally(function () {
            // Restore button
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.innerHTML = originalLabel;
            }
          });
      });
    });
  }

  /* ==========================================================================
     MAIN — wire everything on DOMContentLoaded.
     ========================================================================== */
  document.addEventListener('DOMContentLoaded', function () {
    /* ---- Sticky header ------------------------------------------------------ */
    var header = document.getElementById('siteHeader');
    if (header) {
      var onScroll = function () {
        header.classList.toggle('is-scrolled', window.scrollY > 8);
      };
      onScroll();
      window.addEventListener('scroll', onScroll, { passive: true });
    }

    /* ---- Dropdowns (categories, account) ----------------------------------- */
    var dropdowns = [
      { toggle: 'catToggle',  panel: 'catMenu',  wrap: 'catWrap'  },
      { toggle: 'acctToggle', panel: 'acctMenu', wrap: 'acctWrap' }
    ];

    function closeAllDropdowns(except) {
      dropdowns.forEach(function (d) {
        if (d === except) return;
        var t = document.getElementById(d.toggle);
        setOpen(document.getElementById(d.panel), false);
        if (t) t.setAttribute('aria-expanded', 'false');
      });
    }

    dropdowns.forEach(function (d) {
      var toggle = document.getElementById(d.toggle);
      var panel = document.getElementById(d.panel);
      var wrap = document.getElementById(d.wrap);
      if (!toggle || !panel) return;

      toggle.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var isOpen = !panel.classList.contains('hidden');
        closeAllDropdowns(d);
        setOpen(panel, !isOpen);
        toggle.setAttribute('aria-expanded', String(!isOpen));
      });

      // Clicks inside a panel shouldn't fall through to the outside-close handler.
      panel.addEventListener('click', function (event) {
        if (event.target.closest('a')) return; // let navigation happen
        event.stopPropagation();
      });

      if (wrap) {
        wrap.addEventListener('click', function (event) { event.stopPropagation(); });
      }
    });

    document.addEventListener('click', function () { closeAllDropdowns(null); });

    /* ---- Search overlay ---------------------------------------------------- */
    var searchToggle = document.getElementById('searchToggle');
    var searchPanel = document.getElementById('searchPanel');
    var navSearch = document.getElementById('navSearch');
    var searchClear = document.getElementById('searchClear');

    if (searchToggle && searchPanel) {
      searchToggle.addEventListener('click', function (event) {
        event.stopPropagation();
        var isOpen = !searchPanel.classList.contains('hidden');
        closeAllDropdowns(null);
        setOpen(searchPanel, !isOpen);
        searchToggle.setAttribute('aria-expanded', String(!isOpen));
        if (!isOpen && navSearch) navSearch.focus();
      });
    }

    if (searchClear && navSearch) {
      searchClear.addEventListener('click', function () {
        navSearch.value = '';
        navSearch.focus();
      });
    }

    /* ---- Mobile drawer ----------------------------------------------------- */
    var menuOpen = document.getElementById('menuOpen');
    var mobileMenu = document.getElementById('mobileMenu');
    var menuClose = document.getElementById('menuClose');

    function openDrawer() {
      if (!mobileMenu) return;
      mobileMenu.classList.remove('hidden');
      mobileMenu.classList.add('flex');
      document.body.style.overflow = 'hidden';
    }
    function closeDrawer() {
      if (!mobileMenu) return;
      mobileMenu.classList.add('hidden');
      mobileMenu.classList.remove('flex');
      document.body.style.overflow = '';
    }

    if (menuOpen) menuOpen.addEventListener('click', openDrawer);
    if (menuClose) menuClose.addEventListener('click', closeDrawer);
    if (mobileMenu) {
      mobileMenu.addEventListener('click', function (event) {
        if (event.target.closest('a')) closeDrawer();
      });
    }

    /* ---- Escape closes every open surface ---------------------------------- */
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      closeAllDropdowns(null);
      setOpen(searchPanel, false);
      if (searchToggle) searchToggle.setAttribute('aria-expanded', 'false');
      closeDrawer();
    });

    /* ---- Product-card quantity steppers ------------------------------------ */
    document.querySelectorAll('[data-step]').forEach(function (button) {
      button.addEventListener('click', function () {
        var input = document.getElementById(button.getAttribute('data-target'));
        if (!input) return;
        var step = parseInt(button.getAttribute('data-step'), 10) || 0;
        var min = input.min === '' ? 1 : parseInt(input.min, 10);
        var max = input.max === '' ? Infinity : parseInt(input.max, 10);
        var next = (parseInt(input.value, 10) || min) + step;
        if (next < min) next = min;
        if (next > max) next = max;
        input.value = next;
      });
    });

    /* ---- Image fallbacks --------------------------------------------------- */
    document.querySelectorAll('img[data-image-fallback]').forEach(function (image) {
      var showFallback = function () {
        var placeholder = image.nextElementSibling;
        if (!placeholder || !placeholder.hasAttribute('data-image-placeholder')) return;
        image.classList.add('hidden');
        placeholder.classList.remove('hidden');
        placeholder.classList.add('flex');
      };
      image.addEventListener('error', showFallback);
      if (image.complete && image.naturalWidth === 0) showFallback();
    });

    /* ---- JS-enhanced shop filters + add-to-bag ----------------------------- */
    bindShopFilters();
    bindAddToBagForms();

    /* ---- Destructive-form confirmation ------------------------------------- */
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
      });
    });
  });

  /* Expose for testing / debugging */
  window.SKIBIDI = {
    showToast: showToast,
    updateBagBadge: updateBagBadge,
    isShopPage: function () { return isShopPage; }
  };
})();
