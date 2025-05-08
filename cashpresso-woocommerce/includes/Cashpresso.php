<?php

namespace Cashpresso;

use Exception;
use WC_Admin_Settings;
use WC_Blocks_Utils;
use WC_Order;
use WC_Payment_Gateway;

class Cashpresso extends WC_Payment_Gateway {
  private $secretkey;
  private $apikey;
  private $modus;
  private $validUntil;
  private $boost;
  private $interestFreeMaxDuration;
  private $minPaybackAmount;
  private $limitTotal;

  public function __construct() {
    $this->id = "cashpresso";
    $this->has_fields = false;
    $this->method_title = __("cashpresso Ratenkauf", "lnx-cashpresso-woocommerce");
    $this->method_description = __("cashpresso ermöglicht es Ihren Kunden den Einkauf in Raten zu bezahlen.", "lnx-cashpresso-woocommerce");

    $this->title = $this->get_option('title');
    $this->description = __($this->get_option('description'), 'lnx-cashpresso-woocommerce');

    $this->secretkey = $this->get_option('secretkey');
    $this->apikey = $this->get_option('apikey');
    $this->modus = $this->get_option('modus');

    $this->validUntil = $this->get_option('validUntil');

    $this->boost = $this->get_option('boost');

    $this->interestFreeMaxDuration = $this->get_option('interestFreeMaxDuration');

    $this->minPaybackAmount = $this->get_option('minPaybackAmount');
    $this->limitTotal = $this->get_option('limitTotal');

    $this->init_settings();

    if (is_admin() || $this->isTimeForUpdate()) {
      $this->init_form_fields();
    }

    add_action('woocommerce_api_wc_gateway_cashpresso', array($this, 'processCallback'));

    add_action('wp_head', array($this, 'wc_cashpresso_checkout_js'));
    add_action('wp_footer', array($this, 'wc_cashpresso_refresh_js'));

    add_action('woocommerce_before_thankyou', array($this, 'wc_cashpresso_postcheckout_js'));

    add_filter('woocommerce_gateway_title', array($this, 'wc_cashpresso_add_banner'));

    add_action('admin_notices', array($this, 'do_ssl_check'));
    add_action('admin_notices', array($this, 'do_eur_check'));

    add_filter('woocommerce_my_account_my_orders_actions', function($actions, $order) {
      if ($order->get_payment_method() === $this->id) {
        unset($actions['pay']);
      }

      return $actions;
    }, 10, 2);

    // Save settings
    if (is_admin()) {
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }
  }

  public function payment_fields() {
    parent::payment_fields();

    if (is_checkout()) { ?>
      <p>&nbsp;</p>
      <input type="hidden" id="cashpressoToken" name="cashpressoToken">
      <input type="hidden" id="cashpressoAmount" name="cashpressoAmount" value="<?php esc_attr_e(WC()->cart->get_total('edit')); ?>">
      <div id="cashpresso-checkout"></div>
    <?php }
  }

  private function validateField($key, $value, $check, $message) {
    if ($check) {
      $value = $this->get_option($key);
      WC_Admin_Settings::add_error(
        $message
      );
    }

    return $value;
  }

  public function validate_apikey_field($key, $value) {
    return $this->validateField(
      $key,
      $value,
      empty($value),
      __('Error Api Key invalid. Not updated.', 'lnx-cashpresso-woocommerce')
    );
  }

  public function validate_secretkey_field($key, $value) {
    return $this->validateField(
      $key,
      $value,
      empty($value),
      __('Error: Secret Key invalid. Not updated.', 'lnx-cashpresso-woocommerce')
    );
  }

  public function validate_validUntil_field($key, $value) {
    return $this->validateField(
      $key,
      $value,
      empty($value),
      __('Error: Period of Validity invalid. Not updated.', 'lnx-cashpresso-woocommerce')
    );
  }

  public function validate_interestFreeDaysMerchant_field($key, $value) {
    return $this->validateField(
      $key,
      $value,
      empty($this->settings['interestFreeMaxDuration']) === false && (int)$value > (int)$this->settings['interestFreeMaxDuration'],
      sprintf(
        __(
          'Error: Interest-free Days invalid. Max Duration is set to: %d. Not updated.',
          'lnx-cashpresso-woocommerce'
        ),
        $this->settings['interestFreeMaxDuration']
      )
    );
  }

  public function getAmount(): float {
    if (is_admin()) {
      return 0.0;
    } else {
      $cart = WC()->cart;

      if ($cart === null) {
        return 0.0;
      }

      return (float)$cart->total;
    }
  }

  public function do_eur_check() {
    if ($this->enabled == "yes") {
      if (get_woocommerce_currency() !== "EUR") {

        echo __("<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> wurde deaktiviert, da WooCommerce nicht EUR als Währung eingestellt hat. Bitte stellen Sie EUR als Währung ein und aktivieren Sie die Zahlungsmethode anschlie&szlig;end <a href=\"%s\">hier wieder.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>", "lnx-cashpresso-woocommerce");
        $this->settings["enabled"] = "no";
        update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings));
      }
    }
  }

  // Check if we are forcing SSL on checkout pages
  // Custom function not required by the Gateway
  public function do_ssl_check() {
    if ($this->enabled == "yes") {
      if (get_option('woocommerce_force_ssl_checkout') == "no" && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'off')) {
        echo __("<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> wurde deaktiviert, da WooCommerce kein SSL Zertifikat auf der Bezahlseite verlangt. Bitte erwerben und installieren Sie ein gültiges SSL Zertifikat  und richten Sie es <a href=\"%s\">es hier ein.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>", "lnx-cashpresso-woocommerce");
        $this->settings["enabled"] = "no";
        update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings));
      }
    }
  }

  public function getCurrentLanguage() {
    if (preg_match('/^de($|-.+)/i', get_bloginfo("language"))) {
      return "de";
    }

    return "en";
  }

  public function processCallback() {
    $json = file_get_contents('php://input');
    $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

    if ($this->generateReceivingVerificationHash($this->getSecretKey(), $data->status, $data->referenceId, $data->usage) == $data->verificationHash) {
      $order_id = intval(substr($data->usage, 6));
      $order = wc_get_order($order_id);

      switch ($data->status) {
        case "SUCCESS":
          $order->update_status('processing', $data->referenceId);
          break;
        case "CANCELLED":
          $order->update_status("failed", "cancelled");
          break;
        case "TIMEOUT":
          $order->update_status("failed", "expired");
          break;
        default:
          throw new Exception("Status not valid!");
      }
      echo "OK";
    } else {
      throw new Exception("Verification not valid!");
    }

    die();
  }

  public function admin_options() {
    echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';

    echo wp_kses_post(wpautop($this->get_method_description()));

    if (isset($this->settings["partnerInfo"]) && $this->settings["partnerInfo"] !== "") {

      $obj = json_decode($this->settings["partnerInfo"], true);

      $content = "<table>";
      foreach ($obj as $key => $value) {
        if (is_array($value)) {
          $content .= "<tr><td>$key</td><td colspan='3'></td></tr>";
          foreach ($value as $k => $v) {
            if (is_array($v)) {
              $content .= "<tr><td></td><td>$k</td><td colspan='2'></td></tr>";
              foreach ($v as $x => $y) {
                $content .= "<tr><td></td><td></td><td>$x</td><td>$y</td></tr>";
              }
            } else {
              $content .= "<tr><td></td><td>$k</td><td colspan='2'>$v</td></tr>";
            }
          }
        } else {
          $content .= "<tr><td>$key</td><td colspan='2'>$value</td></tr>";
        }
      }

      $content .= "</table>";

      echo '<table width="100%"><tr><td style="background:#e0e0e0;border:1px solid #666;padding:20px;vertical-align:top;"><strong>Partner Info (' . $this->settings["partnerInfoTimestamp"] . ')</strong><br/> ' . $content . '</td></tr></table>';
    }

    echo '<table class="form-table">' . $this->generate_settings_html($this->get_form_fields(), false) . '</table>';

  }

  /**
   * Initialise settings form fields.
   *
   * Add an array of fields to be displayed
   * on the gateway's settings screen.
   *
   * @since  1.0.0
   */
  public function init_form_fields() {

    if (isset($_POST) && $this->getUrl() !== "") {

      $parameters = [];

      $parameters["partnerApiKey"] = $this->getApiKey();

      $url = $this->getUrl() . "/backend/ecommerce/v2/partnerInfo";

      $data = wp_remote_post($url, array(
        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
        'body' => json_encode($parameters),
        'method' => 'POST',
      ));

      if ($this->wasRequestSuccess($data)) {

        $obj = json_decode($data["body"], true);

        $this->settings["minPaybackAmount"] = $obj["minPaybackAmount"];
        $this->settings["interestFreeEnabled"] = $obj["interestFreeEnabled"];
        $this->settings["limitTotal"] = $obj["limit"]["total"];
        $this->settings["paybackRate"] = $obj["paybackRate"];
        $this->settings["interestFreeMaxDuration"] = $obj["interestFreeMaxDuration"];

        $this->settings["partnerInfo"] = $data["body"];
        $this->settings["partnerInfoTimestamp"] = date('Y-m-d H:i:s');

        if (empty($obj["interestFreeEnabled"]) === false && empty($this->settings["interestFreeMaxDuration"]) === false &&
          $this->getInterestFreeDaysMerchant() > (int)$this->settings["interestFreeMaxDuration"]) {
          $this->settings["interestFreeDaysMerchant"] = $obj["interestFreeMaxDuration"];
        } elseif (!$obj["interestFreeEnabled"]) {
          $this->settings["interestFreeDaysMerchant"] = 0;
        }

        update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings));
      }
    }

    $fields = array(
      'enabled' => array(
        'title' => __('Aktiviert/Deaktiviert', 'lnx-cashpresso-woocommerce'),
        'type' => 'checkbox',
        'label' => __('Aktiviere cashpresso Zahlung', 'lnx-cashpresso-woocommerce'),
        'default' => 'yes',
      ),
      'title' => array(
        'title' => __('Titel', 'lnx-cashpresso-woocommerce'),
        'type' => 'text',
        'description' => __('Namen der im Shop angezeigt wird', 'lnx-cashpresso-woocommerce'),
        'default' => __('Ratenkauf', 'lnx-cashpresso-woocommerce'),
        'desc_tip' => true,
      ),
      'description' => array(
        'title' => __('Beschreibung', 'lnx-cashpresso-woocommerce'),
        'type' => 'textarea',
        'description' => __('Beschreibung der Zahlungsart', 'lnx-cashpresso-woocommerce'),
        'default' => __('cashpresso ermöglicht dir Einkäufe in Raten zu bezahlen. Deine Ratenhöhe kannst du dir beim Kauf aussuchen und später jederzeit ändern.', 'lnx-cashpresso-woocommerce'),
        'desc_tip' => true,
      ),
      'secretkey' => array(
        'title' => __('Secret Key', 'lnx-cashpresso-woocommerce'),
        'type' => 'text',
        'description' => __('Secret Key', 'lnx-cashpresso-woocommerce'),
        'default' => __('', 'lnx-cashpresso-woocommerce'),
        'desc_tip' => true,
        'sanitize_callback' => [$this, 'sanitizeKey']
      ),
      'apikey' => array(
        'title' => __('Api Key', 'lnx-cashpresso-woocommerce'),
        'type' => 'text',
        'description' => __('Api Key', 'lnx-cashpresso-woocommerce'),
        'default' => __('', 'lnx-cashpresso-woocommerce'),
        'desc_tip' => true,
        'sanitize_callback' => [$this, 'sanitizeKey']
      ),
      'modus' => array(
        'title' => __(__('Modus'), 'lnx-cashpresso-woocommerce'),
        'type' => 'select',
        'options' => [__("live", 'lnx-cashpresso-woocommerce'), __("test", 'lnx-cashpresso-woocommerce')],
        'description' => __('Die beiden Modi können nur mit den entsprechenden Zugangsdaten verwendet werden. Haben Sie z.B. Live-Zugangsdaten, so können Sie nur den Live-Modus verwenden. Um Sandboxing im Live-Modus zu de/aktivieren, wenden Sie sich bitte an cashpresso.', 'lnx-cashpresso-woocommerce'),
        'default' => '0',
        'desc_tip' => true,
      ),
      'validUntil' => array(
        'title' => __('Gültigkeitsdauer', 'lnx-cashpresso-woocommerce'),
        'type' => 'number',
        'description' => __('Wie lange kann der Käufer den Prozess bei cashpresso abschließen. Sie müssen solange die Ware vorhalten. (Angabe in Stunden).', 'lnx-cashpresso-woocommerce'),
        'default' => '336',
        'desc_tip' => true,
      ),
      'productLevel' => array(
        'title' => __('cashpresso auf Produktebene', 'lnx-cashpresso-woocommerce'),
        'type' => 'select',
        'options' => [__("deaktivieren", 'lnx-cashpresso-woocommerce'), __("dynamisch", 'lnx-cashpresso-woocommerce'), __("statisch", 'lnx-cashpresso-woocommerce')],
        'description' => __('Soll die Option der Ratenzahlung auf Produktebene angezeigt werden?', 'lnx-cashpresso-woocommerce'),
        'default' => '0',
        'desc_tip' => true,
      ),
      'productLabelLocation' => array(
        'title' => __('Platzierung auf Produktebene', 'lnx-cashpresso-woocommerce'),
        'type' => 'select',
        'options' => [__("keine", 'lnx-cashpresso-woocommerce'), __("Produktseite", 'lnx-cashpresso-woocommerce'), __("Produktseite & Katalog", 'lnx-cashpresso-woocommerce')],
        'description' => __('Wo soll es angezeigt werden?', 'lnx-cashpresso-woocommerce'),
        'default' => '0',
        'desc_tip' => true,
      ),
      'boost' => array(
        'title' => __('Hervorheben', 'lnx-cashpresso-woocommerce'),
        'type' => 'select',
        'description' => __('Schrift vergrößern', 'lnx-cashpresso-woocommerce'),
        'options' => ["80%", "100%", "120%"],
        'default' => '1',
        'desc_tip' => true,
      ));

    if (empty($this->settings["interestFreeEnabled"]) === false) {
      $fields['interestFreeDaysMerchant'] = array(
        'title' => __('Zinsfreie Tage', 'lnx-cashpresso-woocommerce'),
        'type' => 'number',
        'description' => __('Zinsfreie Tage. Nur möglich wenn das Feature für diesen Account von cashpresso freigegeben wurde.', 'lnx-cashpresso-woocommerce'),
        'default' => '0',
        'desc_tip' => true,
      );
    }

    $this->form_fields = $fields;
  }

  public function sanitizeKey(string $value): string {
    return trim(wc_clean($value));
  }

  public function isTimeForUpdate() {
    if (empty($this->settings['partnerInfoTimestamp'])) {
      return false;
    }

    $last_update = $this->settings["partnerInfoTimestamp"];
    return isset($last_update) && (time() - strtotime($last_update) > DAY_IN_SECONDS);
  }

  public function isLive() {
    return ($this->modus == 0);
  }

  public function getMode() {
    if ($this->isLive()) {
      return "live";
    }
    return "test";
  }

  public function getSecretKey() {
    return $this->secretkey;
  }

  public function getApiKey() {
    return $this->apikey;
  }

  public function getUrl() {
    if ($this->isLive()) {
      return "https://rest.cashpresso.com";
    }
    return "https://backend.test-cashpresso.com";
  }

  public function getInterestFreeDaysMerchant() {
    return (int)$this->settings["interestFreeDaysMerchant"];
  }

  public function validate_fields() {
    $keys = ['cashpressotoken', 'cashpressoToken'];

    foreach ($keys as $key) {
      if (!empty($_POST[$key])) {
        return true;
      }
    }

    wc_add_notice(__('Bitte wähle deine Rate aus.', 'lnx-cashpresso-woocommerce'), 'error');
    return false;
  }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);

    $purchaseId = $this->sendBuyRequest($order);
    if (!$purchaseId) {
      return array(
        'result' => 'failure'
      );
    }

    $order->update_status('pending', __('Kunde muss sich noch verifizieren.', 'lnx-cashpresso-woocommerce'));

    // Reduce stock levels
    wc_reduce_stock_levels($order_id);

    // Remove cart
    WC()->cart->empty_cart();

    return array(
      'result' => 'success',
      'redirect' => $this->get_return_url($order),
    );
  }

  public function sendBuyRequest($order) {
    $c2EcomId = $_POST["cashpressoToken"] ?? $_POST['cashpressotoken'];

    $parameters = [];
    $parameters["partnerApiKey"] = $this->getApiKey();
    $parameters["c2EcomId"] = $c2EcomId;
    $parameters["amount"] = floatval($order->calculate_totals());
    $parameters["verificationHash"] = $this->generateSendingVerificationHash($this->getSecretKey(), floatval($order->calculate_totals()), $this->getInterestFreeDaysMerchant(), "Order-" . $order->get_id(), null);
    $parameters["validUntil"] = date('c', time() + $this->validUntil * 3600);
    $parameters["bankUsage"] = "Order-" . $order->get_id();
    $parameters["interestFreeDaysMerchant"] = $this->getInterestFreeDaysMerchant();
    $parameters["callbackUrl"] = trailingslashit(get_site_url()) . "?wc-api=wc_gateway_cashpresso";

    $parameters["language"] = $this->getCurrentLanguage();

    $url = $this->getUrl() . "/backend/ecommerce/v2/buy";

    $data = wp_remote_post($url, array(
      'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
      'body' => json_encode($parameters),
      'method' => 'POST',
    ));

    if (is_wp_error($data)) {
      $this->logError($data);
      return false;
    }

    if ($this->wasRequestSuccess($data)) {
      $obj = json_decode($data["body"]);
      $purchaseId = $obj->purchaseId;
      $order->add_meta_data("purchaseId", $purchaseId);
      $order->save_meta_data();
      return $purchaseId;
    } else {
      $obj = json_decode($data["body"]);
      $message = __('cashpresso Ratenkauf: Ein Fehler ist aufgetreten, bitte wende dich an <a href="mailto:support@cashpresso.com">support@cashpresso.com</a>', 'lnx-cashpresso-woocommerce');
      $errorType = $obj->error->type;

      if ($errorType === 'DUPLICATE_CUSTOMER' || $errorType === 'DUPLICATE_EMAIL') {
        $message = __('cashpresso Ratenkauf: Du hast bereits einen cashpresso Account. Bitte klicke auf Raten ändern und log dich erneut mit deiner E-Mail Adresse an.', 'lnx-cashpresso-woocommerce');
      }

      if (defined('WP_DEBUG') && WP_DEBUG === true) {
        $message .= ". Error: $errorType";
      }

      wc_add_notice($message , 'error');
      return false;
    }
  }

  public function wasRequestSuccess($data) {
    if (is_wp_error($data)) {
      $this->logError($data);
      return false;
    }
    if (isset($data["body"])) {
      $obj = json_decode($data["body"]);
      if (is_object($obj)) {
        if (property_exists($obj, "success") && $obj->success === true) {
          return true;
        }
      }
    }
    return false;
  }

  private function logError($data) {
    try {
      if (is_array($data) || is_object($data)) {
        error_log(print_r($data, true));
      } else {
        error_log($data);
      }
    } catch (Exception $e) {
      return;
    }
  }

  public function generateSendingVerificationHash($secretKey, $amount, $interestFreeDaysMerchant, $bankUsage, $targetAccountId) {
    if (is_null($secretKey)) {
      $secretKey = "";
    }

    if (is_null($amount)) {
      $amount = "";
    }

    if (is_null($interestFreeDaysMerchant)) {
      $interestFreeDaysMerchant = 0;
    }

    if (is_null($bankUsage)) {
      $bankUsage = "";
    }

    if (is_null($targetAccountId)) {
      $targetAccountId = "";
    }

    $key = $secretKey . ";" . intval(round($amount * 100, 0), 10) . ";" . $interestFreeDaysMerchant . ";" . $bankUsage . ";" . $targetAccountId;

    return hash("sha512", $key);
  }

  public function generateReceivingVerificationHash($secretKey, $status, $referenceId, $usage) {
    if (is_null($secretKey)) {
      $secretKey = "";
    }

    if (is_null($status)) {
      $status = "";
    }

    if (is_null($referenceId)) {
      $referenceId = "";
    }

    if (is_null($usage)) {
      $usage = "";
    }

    $key = $secretKey . ";" . $status . ";" . $referenceId . ";" . $usage;

    return hash("sha512", $key);
  }

  function hasCurrentPageCheckoutBlock():bool {
    if (class_exists('WC_Blocks_Utils')) {
      return WC_Blocks_Utils::has_block_in_page(get_queried_object_id(), 'woocommerce/checkout');
    }

    return false;
  }

  public static function getCheckoutScriptTag($apiKey, $interestFreeDaysMerchant, $mode, $locale, $amount) {
    return /** @lang HTML */ <<<TAG
<script id="c2CheckoutScript"
        src="https://my.cashpresso.com/ecommerce/v2/checkout/c2_ecom_checkout.all.min.js"
        data-c2-partnerApiKey="{$apiKey}"
        data-c2-interestFreeDaysMerchant="{$interestFreeDaysMerchant}"
        data-c2-mode="{$mode}"
        data-c2-locale="{$locale}"
        data-c2-amount="{$amount}">
</script>
TAG;
  }

  public function wc_cashpresso_checkout_js() {
    if ($this->enabled !== 'yes' || !is_checkout() || $this->hasCurrentPageCheckoutBlock()) {
      return;
    }

    if (is_wc_endpoint_url('order-pay') && isset($_GET['key'])) {
      $order_key = $_GET['key'];
      $order_id = wc_get_order_id_by_order_key($order_key);
      $order = new WC_Order($order_id);
      $amount = $order->get_total();

    } else {
      $amount = $this->getAmount();
    }

    if (!is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('view-order')) {
      echo self::getCheckoutScriptTag(
        $this->getApiKey(),
        $this->getInterestFreeDaysMerchant(),
        $this->getMode(),
        $this->getCurrentLanguage(),
        $amount
      );
    }
  }

  public function wc_cashpresso_refresh_js() {
    if ($this->enabled !== 'yes' || !is_checkout() || $this->hasCurrentPageCheckoutBlock()) {
      return;
    }

    if (!is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('view-order')) { ?>
      <script>
        function syncData() {
          C2EcomCheckout.refreshOptionalData({
            'email': document.getElementById('billing_email').value,
            'given': document.getElementById('billing_first_name').value,
            'family': document.getElementById('billing_last_name').value,
            'country': document.getElementById('billing_country').value,
            'city': document.getElementById('billing_city').value,
            'zip': document.getElementById('billing_postcode').value,
            'addressline': document.getElementById('billing_address_1').value,
            'phone': document.getElementById('billing_phone').value
          });
        }

        document.getElementById('billing_email').addEventListener('change', syncData);
        document.getElementById('billing_first_name').addEventListener('change', syncData);
        document.getElementById('billing_last_name').addEventListener('change', syncData);
        document.getElementById('billing_country').addEventListener('change', syncData);
        document.getElementById('billing_city').addEventListener('change', syncData);
        document.getElementById('billing_postcode').addEventListener('change', syncData);
        document.getElementById('billing_address_1').addEventListener('change', syncData);
        document.getElementById('billing_phone').addEventListener('change', syncData);
      </script>

      <script>
        jQuery(document.body)
          .on('updated_checkout', function() {
            const checkoutForm = document.querySelector('form.woocommerce-checkout'),
              paymentMethod = checkoutForm ? new FormData(checkoutForm).get('payment_method') : null;

            if (paymentMethod === 'cashpresso' && C2EcomCheckout) {
              C2EcomCheckout.refreshOptionalData({
                "email": jQuery("#billing_email").val(),
                "given": jQuery("#billing_first_name").val(),
                "family": jQuery("#billing_last_name").val(),
                "country": jQuery("#billing_country").val(),
                "city": jQuery("#billing_city").val(),
                "zip": jQuery("#billing_postcode").val(),
                "addressline": jQuery("#billing_address_1").val() + " " + jQuery("#billing_address_2").val(),
                "phone": jQuery("#billing_phone").val()
              });

              let refreshAmount = jQuery("#cashpressoAmount").val();

              if (!isNaN(refreshAmount)) {
                refreshAmount = parseFloat(refreshAmount);
              }

              C2EcomCheckout.refresh(refreshAmount);
            }
          });
      </script><?php
    }
  }

  public function wc_cashpresso_postcheckout_js($orderID) {
    if ($this->enabled !== 'yes' || empty($orderID)) {
      return;
    }

    $order = wc_get_order($orderID);

    if (empty($order)) {
      return;
    }

    if ($order->get_payment_method() === "cashpresso" && $order->get_status() === "pending") {
      $purchaseId = $order->get_meta("purchaseId"); ?>

      <div id="instructions"><?php _e('post-checkout-instructions', 'lnx-cashpresso-woocommerce') ?>
        <br><br>
        <script>function c2SuccessCallback(){ jQuery("#instructions").html("<?php _e("<p>Herzlichen Dank! Ihre Bezahlung wurde soeben freigegeben.</p>", "lnx-cashpresso-woocommerce")  ?>"); }</script>
        <script id="c2PostCheckoutScript" type="text/javascript"
                src="https://my.cashpresso.com/ecommerce/v2/checkout/c2_ecom_post_checkout.all.min.js"
                defer
                data-c2-partnerApiKey="<?php echo $this->getApiKey() ?>"
                data-c2-purchaseId="<?php echo $purchaseId ?>"
                data-c2-mode="<?php echo $this->getMode() ?>"
                data-c2-successCallback="true"
                data-c2-locale="<?php echo $this->getCurrentLanguage() ?>">
        </script>
      </div>
      <br>

    <?php }
  }

  public function wc_cashpresso_add_banner($str) {
    if (is_admin()) {
      return str_replace('<div id="cashpresso-availability-banner"></div>', '', $str);
    } else {
      $amount = $this->getAmount();
      return preg_replace('/value="\d+"/i', 'value="' . $amount . '"', $str);
    }
  }

}
