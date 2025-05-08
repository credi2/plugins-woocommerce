<?php

namespace Cashpresso;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Exception;
use WC_Order;

class CashpressoBlocksSupport extends AbstractPaymentMethodType
{
  /**
   * Payment method name/id/slug.
   *
   * @var string
   */
  protected $name = 'cashpresso';

  /**
   * The gateway instance.
   *
   * @var Cashpresso;
   */
  private $gateway;

  /**
   * Initializes the payment method type.
   */
  public function initialize()
  {
    $this->settings = get_option('woocommerce_cashpresso_settings', []);

    $gateways = WC()->payment_gateways->payment_gateways();
    $this->gateway = $gateways[$this->name];

    add_filter('script_loader_tag', function ($tag, $handle) {
      if ($handle === 'cashpresso-checkout') {
        $partnerApiKey= $this->gateway->getApiKey();
        $interestFreeDaysMerchant= $this->gateway->getInterestFreeDaysMerchant();
        $mode= $this->gateway->getMode();
        $locale = $this->gateway->getCurrentLanguage();
        $amount = $this->gateway->getAmount();

        if (is_wc_endpoint_url('order-pay') && isset($_GET['key'])) {
          $order_key = $_GET['key'];
          $order_id = wc_get_order_id_by_order_key($order_key);
          $order = new WC_Order($order_id);
          $amount = $order->get_total();
        }

        return $this->gateway::getCheckoutScriptTag(
          $partnerApiKey,
          $interestFreeDaysMerchant,
          $mode,
          $locale,
          $amount,
        );
      }

      return $tag;
    }, 10, 2);


  }

  /**
   * Returns if this payment method should be active. If false, the scripts will not be enqueued.
   *
   * @return bool
   */
  public function is_active()
  {
    return $this->gateway->is_available();
  }

  /**
   * Returns an array of scripts/handles to be registered for this payment method.
   *
   * @return array
   */
  public function get_payment_method_script_handles()
  {
    $script_path = 'assets/js/checkout.js';
    $script_asset_path = trailingslashit(plugin_dir_path(__FILE__)) . '../assets/js/checkout.asset.php';
    $script_asset = file_exists($script_asset_path)
      ? require($script_asset_path)
      : array(
        'dependencies' => array(),
        'version' => '1.0.0'
      );
    $script_url = trailingslashit(plugins_url('/', __FILE__))  . '../' . $script_path;

    wp_register_script(
      'wc-cashpresso-payments-blocks',
      $script_url,
      $script_asset['dependencies'],
      $script_asset['version'],
      true
    );

    wp_register_script(
      'cashpresso-checkout',
      'https://my.cashpresso.com/ecommerce/v2/checkout/c2_ecom_checkout.all.min.js',
      ['wc-cashpresso-payments-blocks'],
      null,
      true
    );

    return [
      'wc-cashpresso-payments-blocks',
      'cashpresso-checkout'
    ];
  }

  /**
   * Returns an array of key=>value pairs of data made available to the payment methods script.
   *
   * @return array
   */
  public function get_payment_method_data()
  {
    $amount = $this->gateway->getAmount();

    if (is_checkout() && is_wc_endpoint_url('order-pay') && isset($_GET['key'])) {
      $order_key = $_GET['key'];
      $order_id = wc_get_order_id_by_order_key($order_key);
      $order = new WC_Order($order_id);
      $amount = $order->get_total();
    }

    try {
      $minPurchase = json_decode($this->settings['partnerInfo'], true, 512, JSON_THROW_ON_ERROR)['minPurchaseAmount'];
    } catch (Exception $e) {
      $minPurchase = 0;
    }

    try {
      $maxPurchase = json_decode($this->settings['partnerInfo'], true, 512, JSON_THROW_ON_ERROR)['limit']['total'];
    } catch (Exception $e) {
      $maxPurchase = 0;
    }

    return [
      'title' => $this->get_setting( 'title' ),
      'description' => $this->get_setting( 'description' ),
      'supports' => array_filter($this->gateway->supports, [$this->gateway, 'supports']),
      'partnerApiKey' => $this->gateway->getApiKey(),
      'interestFreeDaysMerchant' => $this->gateway->getInterestFreeDaysMerchant(),
      'minPurchaseAmount' => $minPurchase,
      'maxPurchaseAmount' => $maxPurchase,
      'mode' => $this->gateway->getMode(),
      'locale' => $this->gateway->getCurrentLanguage(),
      'amount' => $amount,
    ];
  }
}
