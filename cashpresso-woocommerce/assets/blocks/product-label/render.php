<?php
/**
 * Server-side render for the cashpresso/product-label block.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined('ABSPATH') or exit;

// wc_get_product() guards against a saved block rendering while WooCommerce is inactive.
if (!function_exists('wc_get_product') || !function_exists('cashpresso_get_product_price_value')) {
    return;
}

$post_id = $block->context['postId'] ?? get_the_ID();

if (!$post_id) {
    return;
}

$product = wc_get_product($post_id);

if (!$product) {
    return;
}

$settings = get_option('woocommerce_cashpresso_settings');

if (empty($settings) || !is_array($settings)) {
    return;
}

if (empty($settings['enabled']) || $settings['enabled'] !== 'yes') {
    return;
}

// Placing the block is the opt-in; always render the dynamic label.
$priceValue = cashpresso_get_product_price_value($product);

wp_enqueue_script('cashpresso-dynamic');

// Styling comes from the block supports, not the legacy setting. The id must be unique per
// instance, otherwise the wizard only initialises the first matching label.
$label = sprintf(
    '<div id="%s" class="c2-financing-label" data-c2-financing-amount="%.2f"></div>',
    esc_attr(wp_unique_id('cashpresso-dynamic-')),
    $priceValue
);

$label = apply_filters('cashpresso-product-integration-label', $label, $product, true);

$wrapper_attributes = get_block_wrapper_attributes([
    'data-c2-block'      => '1',
    'data-c2-product-id' => (string) $product->get_id(),
]);

echo sprintf('<div %s>%s</div>', $wrapper_attributes, $label);
