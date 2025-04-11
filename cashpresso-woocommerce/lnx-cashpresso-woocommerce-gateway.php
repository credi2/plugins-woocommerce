<?php
/**
 * Plugin Name: WooCommerce cashpresso Payment Gateway
 * Plugin URI: https://www.cashpresso.com/de/i/business
 * Description: A payment gateway for cashpresso instalment payments.
 * Version: 1.1.7
 * Author: Credi2 GmbH | cashpresso
 * Author URI: https://www.cashpresso.com/de/i/business
 * Copyright: © 2021 Credi2 GmbH.
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

  $size = '0.8em;';
  $class = 'cashpresso_smaller';

  if ($settings['boost'] == '1') {
    $size = '1em;';
    $class = 'cashpresso_normal';
  } elseif ($settings['boost'] == '2') {
    $size = '1.2em;';
    $class = 'cashpresso_bigger';
  }

  $priceValue = wc_get_price_including_tax($product);

  $vat = "";

  if ($settings["productLevel"] == "1") {
    $vat = sprintf (
      '<div id="dynamic%d" class="c2-financing-label %s" data-c2-financing-amount="%.2f" style="font-size:%s"></div>',
      mt_rand(),
      $class,
      $priceValue,
      $size
    );

    wp_enqueue_script('cashpresso-dynamic');
  } elseif ($settings["productLevel"] == "2") {
    $limitTotal = (float)$settings["limitTotal"];
    $minPaybackAmount = (float)$settings["minPaybackAmount"];

    if ($priceValue <= $limitTotal && $priceValue >= $minPaybackAmount) {
      $paybackRate = $settings['paybackRate'];

      $vat = sprintf('<div class="%s"><a href="#" style="font-size:%s" onclick="C2EcomWizard.startOverlayWizard(%.2f)">%s %s € / %s</a></div>',
      $class,
        $size,
        $priceValue,
        __("ab", "lnx-cashpresso-woocommerce"),
        number_format(cashpresso_get_static_rate($priceValue, $paybackRate, $minPaybackAmount), 2, ',', '.'),
        __("Monat", "lnx-cashpresso-woocommerce")
      );

      wp_enqueue_script('cashpresso-static');
    }
  }

  return $price . $vat;
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
    || empty($settings['productLabelLocation'])
    || empty($settings['productLevel'])
    || is_cart()
    || is_checkout()
    || is_view_order_page()
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
  add_filter('woocommerce_get_price_html', 'cashpresso_product_level_integration', 10, 2);
  add_action('wp_head', 'cashpresso_label_js');

  load_plugin_textdomain('lnx-cashpresso-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

add_action('plugins_loaded', 'cashpresso_plugin_init');
add_action('plugins_loaded', 'cashpresso_gateway_init', 11);
add_action('woocommerce_blocks_loaded', 'cashpresso_add_block_support');
