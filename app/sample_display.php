<?php
/**
 * Display data for the original sample catalog. Keep the eight-column products
 * table unchanged; admin-created products still use their database values.
 */
const SAMPLE_DISPLAY = [
    'Daily Balance Shampoo' => ['id' => 4, 'price' => 380, 'featured' => true, 'sale_price' => 0, 'legacy' => 'shampoo.svg', 'order' => 4],
    'Hydrating Body Wash' => ['id' => 5, 'price' => 340, 'featured' => true, 'sale_price' => 289, 'legacy' => 'bodywash.svg', 'order' => 3],
    'Gentle Facial Cleanser' => ['id' => 2, 'price' => 480, 'featured' => true, 'sale_price' => 0, 'legacy' => 'cleanser.svg', 'order' => 2],
    'Fresh Mint Toothpaste' => ['id' => 8, 'price' => 165, 'featured' => true, 'sale_price' => 0, 'legacy' => 'toothpaste.svg', 'order' => 1],
    'Nourishing Conditioner' => ['id' => 3, 'price' => 395, 'featured' => false, 'sale_price' => 335, 'legacy' => 'conditioner.svg', 'order' => 6],
    'Soft Touch Hand Lotion' => ['id' => 6, 'price' => 260, 'featured' => false, 'sale_price' => 0, 'legacy' => 'lotion.svg', 'order' => 8],
    'Daily Defense Deodorant' => ['id' => 7, 'price' => 220, 'featured' => false, 'sale_price' => 0, 'legacy' => 'deodorant.svg', 'order' => 7],
    'SPF Daily Moisturizer' => ['id' => 1, 'price' => 720, 'featured' => false, 'sale_price' => 0, 'legacy' => 'spf.svg', 'order' => 5],
];

function sample_image_url(array $product): string {
    $name = (string) ($product['name'] ?? '');
    $url = (string) ($product['image_url'] ?? '');
    if (!isset(SAMPLE_DISPLAY[$name])) { return $url; }
    $sample = SAMPLE_DISPLAY[$name];
    if ((int) ($product['id'] ?? 0) !== $sample['id'] ||
        (float) ($product['price'] ?? 0) !== (float) $sample['price']) { return $url; }
    if ($url === '../assets/img/' . $sample['legacy']) {
        return '../assets/img/products/' . strtolower(str_replace(' ', '-', $name)) . '.svg';
    }
    return $url;
}

function sample_order(array $product): int {
    $display = sample_display($product);
    return (int) ($display['order'] ?? 0);
}

function sample_display(array $product): array {
    $name = (string) ($product['name'] ?? '');
    if (!isset(SAMPLE_DISPLAY[$name])) {
        return ['featured' => false, 'sale_price' => 0];
    }
    $sample = SAMPLE_DISPLAY[$name];
    $artwork = sample_image_url($product);
    $expected = '../assets/img/products/' . strtolower(str_replace(' ', '-', $name)) . '.svg';
    // Don't apply the original discount to a differently priced or imaged row.
    if ($artwork !== $expected || (int) ($product['id'] ?? 0) !== $sample['id'] ||
        (float) ($product['price'] ?? 0) !== (float) $sample['price']) {
        return ['featured' => false, 'sale_price' => 0];
    }
    return $sample;
}
