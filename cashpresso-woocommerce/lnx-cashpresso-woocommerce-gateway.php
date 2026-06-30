<?php
/**
 * Plugin Name: WooCommerce cashpresso Payment Gateway
 * Plugin URI: https://www.cashpresso.com/de/i/business
 * Description: A payment gateway for cashpresso instalment payments.
 * Version: 1.3.0
 * Author: Credi2 GmbH | cashpresso
 * Author URI: https://www.cashpresso.com/de/i/business
 * Copyright: © 2025 Credi2 GmbH.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: lnx-cashpresso-woocommerce
 * Domain Path: /languages
 */

defined('ABSPATH') or exit;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Cashpresso\Cashpresso;
use Cashpresso\CashpressoBlocksSupport;

/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + cashpresso gateway
 * @since 1.0.0
 */
function cashpresso_register_gateway($gateways) {
  $gateways[] = Cashpresso::class;

  return $gateways;
}

/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 * @since 1.0.0
 */
function cashpresso_gateway_plugin_links($links) {
  $plugin_links = array(
    '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=cashpresso') . '">' . __('Einstellungen', 'lnx-cashpresso-woocommerce') . '</a>',
  );
  return array_merge($plugin_links, $links);
}

function cashpresso_gateway_init() {
  if (class_exists('WC_Payment_Gateway')) {
    require_once 'includes/Cashpresso.php';
  }
}

/**
 * Price (incl. tax) for the financing label; cheapest variation for variable products.
 *
 * @param WC_Product $product
 * @return float
 */
function cashpresso_get_product_price_value($product) {
  if ($product->is_type('variable')) {
    $prices = $product->get_variation_prices(true);

    if (!empty($prices['price'])) {
      $variation_ids = array_keys($prices['price']);
      $variation = wc_get_product(reset($variation_ids));

      if ($variation) {
        return wc_get_price_including_tax($variation);
      }
    }
  }

  return wc_get_price_including_tax($product);
}

/**
 * Build the financing label markup and enqueue the matching remote script.
 * Shared by the price-html filter and the product-label block render.
 *
 * @param WC_Product $product
 * @param array $settings woocommerce_cashpresso_settings
 * @return string label HTML
 */
function cashpresso_build_label_html($product, array $settings) {
  $size = '0.8em;';
  $class = 'cashpresso_smaller';

  if ($settings['boost'] == '1') {
    $size = '1em;';
    $class = 'cashpresso_normal';
  } elseif ($settings['boost'] == '2') {
    $size = '1.2em;';
    $class = 'cashpresso_bigger';
  }

  $priceValue = cashpresso_get_product_price_value($product);

  $label = "";

  $isDynamicIntegration = $settings["productLevel"] == "1";
  $isStaticIntegration = $settings["productLevel"] == "2";

  if ($isDynamicIntegration) {
    $args = apply_filters('cashpresso-product-integration-label-args-dynamic', [
      'class' => $class,
      'priceValue' => $priceValue,
    ], $product);

    $label = sprintf (
      '<div id="%s" class="c2-financing-label %s" data-c2-financing-amount="%.2f" style="font-size:%s"></div>',
      esc_attr(wp_unique_id('cashpresso-dynamic-')),
      $args['class'] ?? '',
      $args['priceValue'] ?? '',
      $size
    );

    wp_enqueue_script('cashpresso-dynamic');
  } elseif ($isStaticIntegration) {
    $limitTotal = (float)$settings["limitTotal"];
    $minPaybackAmount = (float)$settings["minPaybackAmount"];

    if ($priceValue <= $limitTotal && $priceValue >= $minPaybackAmount) {
      $paybackRate = $settings['paybackRate'];

      $args = apply_filters('cashpresso-product-integration-label-args-static', [
        'class' => $class,
        'priceValue' => $priceValue,
      ], $product);

      $label = sprintf('<div class="%s"><a href="#" style="font-size:%s" onclick="C2EcomWizard.startOverlayWizard(%.2f)" data-price="%.2f">%s %s € / %s</a></div>',
        $args['class'] ?? '',
        $size,
        $args['priceValue'] ?? '',
        $args['priceValue'] ?? '',
        __("ab", "lnx-cashpresso-woocommerce"),
        number_format(cashpresso_get_static_rate($args['priceValue'] ?? '', $paybackRate, $minPaybackAmount), 2, ',', '.'),
        __("Monat", "lnx-cashpresso-woocommerce")
      );

      wp_enqueue_script('cashpresso-static');
    }
  }

  return apply_filters('cashpresso-product-integration-label', $label, $product, $isDynamicIntegration);
}

function cashpresso_product_level_integration($price, $product = null) {
  $settings = get_option('woocommerce_cashpresso_settings');

  if (empty($settings) || !is_array($settings)) {
    return $price;
  }

  if ($settings['enabled'] !== 'yes') {
    return $price;
  }

  if ($settings['productLabelLocation'] == 0) {
    return $price;
  }

  if ($settings['productLabelLocation'] == 1 && !is_product()) {
    return $price;
  }

  if (empty($settings['productLevel'])) {
    return $price;
  }

  if (empty($product)) {
    $product = wc_get_product();
  }

  if (apply_filters('cashpresso-add-product-level-integration', true, $product, $price) === false) {
    return $price;
  }

  $label = cashpresso_build_label_html($product, $settings);

  $isDynamicIntegration = $settings["productLevel"] == "1";

  if (apply_filters('cashpresso-product-integration-label-after-price', true, $product, $isDynamicIntegration)) {
    return $price . $label;
  }

  return $label . $price;
}

function cashpresso_get_static_rate($price, $paybackRate, $minPaybackAmount) {
  return min(floatval($price), max(floatval($minPaybackAmount), $price * 0.01 * $paybackRate));
}

function cashpresso_label_js() {

  $settings = get_option('woocommerce_cashpresso_settings');

  if (
    empty($settings)
    || !is_array($settings)
    || empty($settings['enabled'])
    || $settings['enabled'] !== 'yes'
    || is_cart()
    || is_checkout()
    || is_view_order_page()
  ) {
    return;
  }

  // The placement/level settings only apply to classic themes. Block themes hide them, so gating
  // on them there would skip the script_loader_tag filter and drop the block label's data-c2-* attrs.
  if (
    !(function_exists('wp_is_block_theme') && wp_is_block_theme())
    && (empty($settings['productLabelLocation']) || empty($settings['productLevel']))
  ) {
    return;
  }

  $locale = 'en';
  if (stripos(get_bloginfo('language'), 'de') === 0) {
    $locale = 'de';
  }

  $interestFreeDaysMerchant = $settings["interestFreeDaysMerchant"];

  $apiKey = $settings["apikey"];
  if ($settings["modus"] == 0) {
    $modus = "live";
  } else {
    $modus = "test";
  }

  add_filter('script_loader_tag', static function($tag, $handle, $src) use ($apiKey, $interestFreeDaysMerchant, $modus, $locale) {
    if ($handle === 'cashpresso-dynamic') {
      $tag = /** @lang HTML*/ <<<SCRIPT
<script id="c2LabelScript"
        type="text/javascript"
        src="$src"
        defer
        async
        data-c2-partnerApiKey="$apiKey"
        data-c2-interestFreeDaysMerchant="$interestFreeDaysMerchant"
        data-c2-mode="$modus"
        data-c2-locale="$locale"
></script>
SCRIPT;
    } elseif ($handle === 'cashpresso-static') {
      $tag = /** @lang HTML */ <<<SCRIPT
<script id="c2StaticLabelScript"
        type="text/javascript"
        src="$src"
        defer
        async
        data-c2-partnerApiKey="$apiKey"
        data-c2-interestFreeDaysMerchant="$interestFreeDaysMerchant"
        data-c2-mode="$modus"
        data-c2-locale="$locale"
></script>
SCRIPT;
    }
    
    return $tag;
  }, 10, 3);
}

function cashpresso_add_block_support() {
  if (class_exists(AbstractPaymentMethodType::class)) {
    require_once 'includes/CashpressoBlocksSupport.php';

    add_action(
      'woocommerce_blocks_payment_method_type_registration',
      static function(PaymentMethodRegistry $payment_method_registry) {
        $payment_method_registry->register(new CashpressoBlocksSupport());
      }
    );
  }
}

function cashpresso_plugin_init() {
  // Make sure WooCommerce is active
  if (!class_exists('WooCommerce')) {
    return;
  }

  add_action('wp_enqueue_scripts', function () {
    wp_register_script('cashpresso-dynamic', 'https://my.cashpresso.com/ecommerce/v2/label/c2_ecom_wizard.all.min.js', ['cashpresso-dynamic-variable'], null, true);
    wp_register_script('cashpresso-dynamic-variable', plugins_url('assets/variable.js', __FILE__), ['jquery'], null, true);

    wp_register_script('cashpresso-static', 'https://my.cashpresso.com/ecommerce/v2/label/c2_ecom_wizard_static.all.min.js', [], null, true);
  });

  add_filter('woocommerce_payment_gateways', 'cashpresso_register_gateway');
  add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cashpresso_gateway_plugin_links');

  // Hook-based placement is classic-theme only; block themes use the product-label block.
  add_action('wp', function () {
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
      return;
    }

    add_filter('woocommerce_get_price_html', 'cashpresso_product_level_integration', 10, 2);
  });

  // Runs for both classic and block themes; the block enqueues the same scripts on render.
  add_action('wp_enqueue_scripts', 'cashpresso_label_js');

  load_plugin_textdomain('lnx-cashpresso-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

function cashpresso_register_blocks() {
  if (!class_exists('WooCommerce')) {
    return;
  }

  register_block_type(plugin_dir_path(__FILE__) . 'assets/blocks/product-label/');
}

function cashpresso_register_block_patterns() {
  if (!class_exists('WooCommerce') || !function_exists('register_block_pattern')) {
    return;
  }

  if (function_exists('register_block_pattern_category')) {
    register_block_pattern_category('cashpresso', [
      'label' => __('cashpresso', 'lnx-cashpresso-woocommerce'),
    ]);
  }

  register_block_pattern('cashpresso/product-label', [
    'title' => __('cashpresso Finanzierungs-Label', 'lnx-cashpresso-woocommerce'),
    'description' => __('Platziert das cashpresso Finanzierungs-Label für das aktuelle Produkt.', 'lnx-cashpresso-woocommerce'),
    'categories' => ['cashpresso'],
    'content' => '<!-- wp:cashpresso/product-label /-->',
  ]);
}

add_action('plugins_loaded', 'cashpresso_plugin_init');
add_action('plugins_loaded', 'cashpresso_gateway_init', 11);
add_action('woocommerce_blocks_loaded', 'cashpresso_add_block_support');
add_action('init', 'cashpresso_register_blocks');
add_action('init', 'cashpresso_register_block_patterns');
