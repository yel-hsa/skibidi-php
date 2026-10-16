<?php
/**
 * SKIBIDI — inline SVG icon set.
 *
 * Ported 1:1 from the React storefront's `components/Icon.jsx`: same glyph
 * geometry, same 24x24 viewBox, same 1.5px stroke weight and round caps, so
 * every icon in the PHP build is pixel-identical to the original.
 *
 *   <?= icon('bag') ?>                     // default h-5 w-5
 *   <?= icon('chevronDown', 'h-3 w-3') ?>  // custom size
 */

function icon_paths(): array {
    static $paths = null;
    if ($paths !== null) return $paths;

    $paths = [
        'search'       => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>',
        'bag'          => '<path d="M6 8h12l1 12H5L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'user'         => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.6 3.1-5.5 7-5.5s7 1.9 7 5.5"/>',
        'menu'         => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'        => '<path d="M6 6l12 12M18 6 6 18"/>',
        'chevronDown'  => '<path d="m6 9 6 6 6-6"/>',
        'chevronRight' => '<path d="m9 6 6 6-6 6"/>',
        'chevronLeft'  => '<path d="m15 6-6 6 6 6"/>',
        'minus'        => '<path d="M5 12h14"/>',
        'plus'         => '<path d="M12 5v14M5 12h14"/>',
        'trash'        => '<path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/>',
        'check'        => '<path d="m5 13 4 4L19 7"/>',
        'arrowRight'   => '<path d="M4 12h15"/><path d="m13 6 6 6-6 6"/>',
        'truck'        => '<path d="M2 7h11v9H2z"/><path d="M13 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
        'leaf'         => '<path d="M5 19c0-8 5-13 14-13 0 9-5 13-14 13Z"/><path d="M9 15c2-3 4.5-5 8-6"/>',
        'sparkle'      => '<path d="M12 3l1.7 4.8L18.5 9.5 13.7 11.2 12 16l-1.7-4.8L5.5 9.5l4.8-1.7z"/><path d="M18 16.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>',
        'clock'        => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'grid'         => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
        'box'          => '<path d="M12 3l8 4v10l-8 4-8-4V7z"/><path d="M4 7l8 4 8-4M12 11v10"/>',
        'receipt'      => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'users'        => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5"/><path d="M16 5.5a3.2 3.2 0 0 1 0 6.2M17 15.4c2.4.5 4 2.1 4 4.6"/>',
        'alert'        => '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/>',
        'logout'       => '<path d="M10 5H5v14h5"/><path d="M14 8l4 4-4 4M18 12H9"/>',
        'edit'         => '<path d="M4 20h4l11-11-4-4L4 16z"/><path d="m14 5 4 4"/>',
        'star'         => '<path d="M12 4l2.4 5.2 5.6.6-4.2 3.8 1.2 5.6L12 16.5 6.9 19.2l1.2-5.6L4 9.8l5.6-.6z"/>',
        'filter'       => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'lock'         => '<path d="M6 11h12v9H6z"/><path d="M9 11V8a3 3 0 0 1 6 0v3"/>',
        'mail'         => '<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/>',
        'phone'        => '<path d="M6 3h3l2 5-2 1.5a11 11 0 0 0 5 5L15.5 12l5 2v3.5A2.5 2.5 0 0 1 18 20 15 15 0 0 1 4 6a2.5 2.5 0 0 1 2-3Z"/>',
        'store'        => '<path d="M4 9h16v11H4z"/><path d="M3 9l2-5h14l2 5"/><path d="M10 20v-6h4v6"/>',
    ];
    return $paths;
}

/**
 * Render one icon as an inline SVG.
 *
 * @param string $name  key from the set above
 * @param string $class Tailwind-style sizing/colour classes
 */
function icon(string $name, string $class = 'h-5 w-5'): string {
    $paths = icon_paths();
    if (!isset($paths[$name])) return '';

    return '<svg viewBox="0 0 24 24" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"'
         . ' fill="none" stroke="currentColor" stroke-width="1.5"'
         . ' stroke-linecap="round" stroke-linejoin="round"'
         . ' aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

/** Names available, for parity with `ICON_NAMES` in the React build. */
function icon_names(): array { return array_keys(icon_paths()); }
