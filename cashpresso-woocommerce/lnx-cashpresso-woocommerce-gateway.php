<?php
/**
 * Plugin Name: WooCommerce cashpresso Payment Gateway
 * Plugin URI: https://www.cashpresso.com/de/i/business
 * Description: A payment gateway for cashpresso instalment payments.
 * Version: 1.1.2
 * Author: Credi2 GmbH | cashpresso
 * Author URI: https://www.cashpresso.com/de/i/business
 * Copyright: © 2020 Credi2 GmbH.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: lnx-cashpresso-woocommerce
 * Domain Path: /languages
 */
defined('ABSPATH') or exit;

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
  return;
}

/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + cashpresso gateway
 * @since 1.0.0
 */
function wc_cashpresso_add_to_gateways($gateways) {
  $gateways[] = 'WC_Gateway_Cashpresso';
  return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_cashpresso_add_to_gateways');

/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 * @since 1.0.0
 */
function wc_cashpresso_gateway_plugin_links($links) {
  $plugin_links = array(
    '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=cashpresso') . '">' . __('Einstellungen', 'lnx-cashpresso-woocommerce') . '</a>',
  );
  return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_cashpresso_gateway_plugin_links');

function wc_cashpresso_gateway_init() {

  if (!class_exists('WC_Payment_Gateway')) {
    return;
  }

  class WC_Gateway_Cashpresso extends WC_Payment_Gateway {

    protected $amount;

    public function __construct() {
      $this->id = "cashpresso";
      $this->has_fields = false;
      $this->method_title = __("cashpresso Ratenkauf", "lnx-cashpresso-woocommerce");
      $this->method_description = __("cashpresso ermöglicht es Ihren Kunden den Einkauf in Raten zu bezahlen.", "lnx-cashpresso-woocommerce");

      if (is_admin()) {
        $this->amount = 0.0;
      } else {
        $cart = WC()->cart;

        if ($cart === null) {
          $this->amount = 0.0;
        } else {
          $this->amount = (float)$cart->total;
        }
      }

      $this->title = $this->get_option('title');
      $this->description = __($this->get_option('description'), 'lnx-cashpresso-woocommerce') . '<p>&nbsp;</p><input type="hidden" id="cashpressoToken" name="cashpressoToken"><div id="cashpresso-checkout"></div><script type="text/javascript"> //document.addEventListener("DOMContentLoaded", function(event) { if (window.C2EcomCheckout) { window.C2EcomCheckout.refresh( ); } //});</script>';

      $this->secretkey = $this->get_option('secretkey');
      $this->apikey = $this->get_option('apikey');
      $this->modus = $this->get_option('modus');

      $this->validUntil = $this->get_option('validUntil');

      $this->boost = $this->get_option('boost');

      $this->interestFreeMaxDuration = $this->get_option('interestFreeMaxDuration');

      $this->instructions = __('post-checkout-instructions', 'lnx-cashpresso-woocommerce');


      $this->minPaybackAmount = $this->get_option('minPaybackAmount');
      $this->limitTotal = $this->get_option('limitTotal');

      if (is_admin() || $this->isTimeForUpdate()) {
        $this->init_form_fields();
      }
      $this->init_settings();

      add_action('woocommerce_api_wc_gateway_cashpresso', array($this, 'processCallback'));

      add_action('wp_head', array($this, 'wc_cashpresso_checkout_js'));
      add_action('wp_footer', array($this, 'wc_cashpresso_refresh_js'));

      add_filter('woocommerce_thankyou_order_received_text', array($this, 'wc_cashpresso_postcheckout_js'), 10, 2);

      add_filter('woocommerce_gateway_title', array($this, 'wc_cashpresso_add_banner'));

      add_action('admin_notices', array($this, 'do_ssl_check'));
      add_action('admin_notices', array($this, 'do_eur_check'));

      // Save settings
      if (is_admin()) {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
      }
    }

    public function validate_apikey_field($key) {
      $value = $_POST['woocommerce_cashpresso_' . $key];
      if (empty($value)) {
        $value = $this->get_option("apikey");
        echo __("<div class=\"error\"><p>" . __("<strong>ApiKey</strong> invalid. Not updated.") . "</p></div>", "lnx-cashpresso-woocommerce");
      }

      return $value;
    }

    public function validate_secretkey_field($key) {
      $value = $_POST['woocommerce_cashpresso_' . $key];
      if (empty($value)) {
        $value = $this->get_option("secretkey");
        echo __("<div class=\"error\"><p>" . __("<strong>SecretKey</strong> invalid. Not updated.") . "</p></div>", "lnx-cashpresso-woocommerce");
      }

      return $value;
    }

    public function validate_validUntil_field($key) {
      $value = $_POST['woocommerce_cashpresso_' . $key];
      if (empty($value)) {
        $value = $this->get_option("validUntil");
        echo __("<div class=\"error\"><p>" . __("<strong>Period of Validity</strong> invalid. Not updated.") . "</p></div>", "lnx-cashpresso-woocommerce");

      }
      return $value;
    }

    public function validate_interestFreeDaysMerchant_field($key) {
      $value = $_POST['woocommerce_cashpresso_' . $key];
      if (isset($this->settings["interestFreeMaxDuration"]) && intval($value) > intval($this->settings["interestFreeMaxDuration"])) {
        $value = $this->get_option("interestFreeDaysMerchant");
        echo __("<div class=\"error\"><p>" . __("<strong>interest-free days</strong> invalid. Max Duration is set to: " . $this->settings["interestFreeMaxDuration"] . ". Not updated.") . "</p></div>", "lnx-cashpresso-woocommerce");
      }
      return $value;
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
      if (get_bloginfo("language") == "de-DE") {
        return "de";
      }
      return "en";
    }

    public function processCallback() {
      $json = file_get_contents('php://input');
      $data = json_decode($json);

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
            break;
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
     * @return string
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
          $this->settings["partnerInfoTimestamp"] = strftime("%Y-%m-%d %H:%M:%S");

          if ($obj["interestFreeEnabled"] && isset($this->settings["interestFreeMaxDuration"]) &&
            $this->getInterestFreeDaysMerchant() > intval($this->settings["interestFreeMaxDuration"])) {
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
        ),
        'apikey' => array(
          'title' => __('Api Key', 'lnx-cashpresso-woocommerce'),
          'type' => 'text',
          'description' => __('Api Key', 'lnx-cashpresso-woocommerce'),
          'default' => __('', 'lnx-cashpresso-woocommerce'),
          'desc_tip' => true,
        ),
        'modus' => array(
          'title' => __(__('Modus'), 'lnx-cashpresso-woocommerce'),
          'type' => 'select',
          'options' => [__("live"), __("test")],
          'description' => __('k', 'lnx-cashpresso-woocommerce'),
          'description' => __('Die beiden Modi können nur mit den entsprechenden Zugangsdaten verwendet werden. Haben Sie z.B. Live-Zugangsdaten, so können Sie nur den Live-Modus verwenden. Um Sandboxing im Live-Modus zu de/aktivieren, wenden Sie sich bitte an cashpresso.', 'lnx-cashpresso-woocommerce'),
          'default' => __('live', 'lnx-cashpresso-woocommerce'),
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
          'options' => [__("deaktivieren"), __("dynamisch"), __("statisch")],
          'description' => __('Soll die Option der Ratenzahlung auf Produktebene angezeigt werden?', 'lnx-cashpresso-woocommerce'),
          'default' => __('', 'lnx-cashpresso-woocommerce'),
          'desc_tip' => true,
        ),
        'productLabelLocation' => array(
          'title' => __('Platzierung auf Produktebene', 'lnx-cashpresso-woocommerce'),
          'type' => 'select',
          'options' => [__("keine"), __("Produktseite"), __("Produktseite & Katalog")],
          'description' => __('Wo soll es angezeigt werden?', 'lnx-cashpresso-woocommerce'),
          'default' => __('', 'lnx-cashpresso-woocommerce'),
          'desc_tip' => true,
        ),
        'boost' => array(
          'title' => __('Hervorheben', 'lnx-cashpresso-woocommerce'),
          'type' => 'select',
          'description' => __('Schrift vergrößern', 'lnx-cashpresso-woocommerce'),
          'options' => ["80%", "100%", "120%"],
          'default' => '100% Schriftgröße',
          'desc_tip' => true,
        ));

      if ($this->settings["interestFreeEnabled"]) {
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

    public function isTimeForUpdate() {
      $last_update = $this->settings["partnerInfoTimestamp"];
      $day_in_seconds = 60 * 60 * 24;
      return isset($last_update) && (time() - strtotime($last_update) > $day_in_seconds);
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
        return "https://backend.cashpresso.com/rest";
      }
      return "https://test.cashpresso.com/rest";
    }

    public function getInterestFreeDaysMerchant() {
      if (!is_numeric($this->settings["interestFreeDaysMerchant"])) {
        return intval($this->settings["interestFreeDaysMerchant"]);
      }
      return $this->settings["interestFreeDaysMerchant"];
    }

    public function validate_fields() {
      if (empty($_POST['cashpressoToken'])) {
        wc_add_notice(__('Bitte wähle deine Rate aus.', 'lnx-cashpresso-woocommerce'), 'error');
        return false;
      }

      return true;
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
      $order->reduce_order_stock();

      // Remove cart
      WC()->cart->empty_cart();

      return array(
        'result' => 'success',
        'redirect' => $this->get_return_url($order),
      );
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page() {
      if ($this->instructions) {
        echo wpautop(wptexturize($this->instructions));
      }
    }

    public function sendBuyRequest($order) {

      $parameters = [];
      $parameters["partnerApiKey"] = $this->getApiKey();
      $parameters["c2EcomId"] = $_POST["cashpressoToken"];
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

        wc_add_notice($message, 'error');
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

    public function wc_cashpresso_checkout_js() {
      global $woocommerce;

      $amount = $this->amount;

      if (is_checkout() && is_wc_endpoint_url('order-pay')) {
        if (isset($_GET['key'])) {
          $order_key = $_GET['key'];
          $order_id = wc_get_order_id_by_order_key($order_key);
          $order = new WC_Order($order_id);
          $amount = $order->get_total();
        }
      }


      if (is_checkout() && !is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('view-order')) {

        echo '
        <script id="c2CheckoutScript" type="text/javascript"
		      src="https://my.cashpresso.com/ecommerce/v2/checkout/c2_ecom_checkout.all.min.js"
		        data-c2-partnerApiKey="' . $this->getApiKey() . '"
		        data-c2-interestFreeDaysMerchant="' . $this->getInterestFreeDaysMerchant() . '"
		        data-c2-mode="' . $this->getMode() . '"
		        data-c2-locale="' . $this->getCurrentLanguage() . '"
		        data-c2-amount="' . $amount . '">
		    </script>';
      }
    }

    public function wc_cashpresso_refresh_js() {
      global $woocommerce;

      if (is_checkout() && !is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('view-order')) {

        echo "<script>

			function syncData(){
				var foo = C2EcomCheckout.refreshOptionalData({
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
		    ";
        echo '<script>jQuery( document.body ).on( "updated_checkout", function( e ){

				if(C2EcomCheckout &&  window.location.href  == "' . wc_get_checkout_url() . '" ){
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
	let refreshAmount = jQuery("#wc_cashpresso_refresh_amount").val();
	if (!isNaN(refreshAmount)) {
	  refreshAmount = parseFloat(refreshAmount);
	}
	 C2EcomCheckout.refresh(refreshAmount);
}
});</script>';
      }
    }

    public function wc_cashpresso_postcheckout_js($str, $order) {
      global $woocommerce;
      if ($order->get_payment_method() == "cashpresso" && $order->get_status() == "pending") {

        $purchaseId = $order->get_meta("purchaseId");
        $newString = "<div id='instructions'>" . __('post-checkout-instructions', 'lnx-cashpresso-woocommerce');
        $newString .= "<br/><br/>";
        $newString .= '<script>function c2SuccessCallback(){ jQuery("#instructions").html("' . __("<p>Herzlichen Dank! Ihre Bezahlung wurde soeben freigegeben.</p>", "lnx-cashpresso-woocommerce") . '"); }</script><script id="c2PostCheckoutScript" type="text/javascript"
		    src="https://my.cashpresso.com/ecommerce/v2/checkout/c2_ecom_post_checkout.all.min.js"
		    defer
		    data-c2-partnerApiKey="' . $this->getApiKey() . '"
		    data-c2-purchaseId="' . $purchaseId . '"
		    data-c2-mode="' . $this->getMode() . '"
		    data-c2-successCallback="true"
		    data-c2-locale="' . $this->getCurrentLanguage() . '">
		  </script></div><br/>';
        return $newString;
      }
      return $str;
    }

    public function wc_cashpresso_add_banner($str) {
      if (is_admin()) {
        return str_replace('<div id="cashpresso-availability-banner"></div>', '', $str);
      } else {
        $this->amount = (float)WC()->cart->total;
        return preg_replace('/value="\d+"/i', 'value="' . $this->amount . '"', $str);
      }
    }

  }

  // end \WC_Gateway_Offline class
}

add_action('plugins_loaded', 'wc_cashpresso_gateway_init', 11);

function product_level_integration($price, $product = null) {

  if (empty($product)) {
    $product = wc_get_product();
  }

  $pricevalue = wc_get_price_including_tax($product);

  $settings = get_option('woocommerce_cashpresso_settings');

  if ($settings["productLabelLocation"] == 0) {
    return $price;
  }

  if ($settings["productLabelLocation"] == 1 && !is_product()) {
    return $price;
  }

  if ($settings['enabled'] !== "yes") {
    return $price;
  }

  $vat = "";

  if ($settings["productLevel"] == "1") {

    $limitTotal = floatval($settings["limitTotal"]);
    $minPaybackAmount = floatval($settings["minPaybackAmount"]);

    if ($pricevalue <= $limitTotal && $pricevalue >= $minPaybackAmount) {

      $size = "0.8em;";
      $class = "cashpresso_smaller";
      if ($settings["boost"] == "1") {
        $size = "1em;";
        $class = "cashpresso_normal";
      }
      if ($settings["boost"] == "2") {
        $size = "1.2em;";
        $class = "cashpresso_bigger";
      }
      $vat = ' <div id="dynamic' . rand() . '" class="c2-financing-label ' . $class . '" data-c2-financing-amount="' . number_format($pricevalue, 2, ".", "") . '" style="font-size:' . $size . '"><a href"#" ></a></div>';
    }
  }

  if ($settings["productLevel"] == "2") {

    $limitTotal = floatval($settings["limitTotal"]);
    $minPaybackAmount = floatval($settings["minPaybackAmount"]);

    if ($pricevalue <= $limitTotal && $pricevalue >= $minPaybackAmount) {

      $size = "0.8em;";
      $class = "cashpresso_smaller";
      if ($settings["boost"] == "1") {
        $size = "1em;";
        $class = "cashpresso_normal";
      }
      if ($settings["boost"] == "2") {
        $size = "1.2em;";
        $class = "cashpresso_bigger";
      }

      $paybackRate = $settings['paybackRate'];
      $minPaybackAmount = $settings['minPaybackAmount'];

      $vat = ' <div class="' . $class . '"><a href="#" style="font-size:' . $size . '" onclick="C2EcomWizard.startOverlayWizard(' . number_format($pricevalue, 2, ".", "") . ')"> ' . __("ab", "lnx-cashpresso-woocommerce") . ' ' . number_format(getStaticRate($pricevalue, $paybackRate, $minPaybackAmount), 2) . ' € / ' . __("Monat", "lnx-cashpresso-woocommerce") . '</a></div>';
    }
  }

  return $price . $vat;
}

function getStaticRate($price, $paybackRate, $minPaybackAmount) {
  return min(floatval($price), max(floatval($minPaybackAmount), floatval($price * 0.01 * $paybackRate)));
}

add_filter('woocommerce_get_price_html', 'product_level_integration', 10, 2);

function wc_cashpresso_label_js() {

  $settings = get_option('woocommerce_cashpresso_settings');

  if (is_cart() || is_checkout() || is_view_order_page() || $settings["productLabelLocation"] == "0" || $settings["productLevel"] == "0") {
    return;
  }

  $product = wc_get_product();

  $locale = "en";
  if (get_bloginfo("language") == "de-DE") {
    $locale = "de";
  }
  $interestFreeDaysMerchant = $settings["interestFreeDaysMerchant"];

  $apiKey = $settings["apikey"];
  if ($settings["modus"] == 0) {
    $modus = "live";
  } else {
    $modus = "test";
  }

  if ($settings["productLevel"] == "1") {
    echo '<script id="c2LabelScript" type="text/javascript"
src="https://my.cashpresso.com/ecommerce/v2/label/c2_ecom_wizard.all.min.js"
defer
data-c2-partnerApiKey="' . $apiKey . '"
data-c2-interestFreeDaysMerchant="' . $interestFreeDaysMerchant . '"
data-c2-mode="' . $modus . '"
data-c2-locale="' . $locale . '" ></script>';
  }
  if ($settings["productLevel"] == "2") {
    echo '<script id="c2StaticLabelScript" type="text/javascript"
src="https://my.cashpresso.com/ecommerce/v2/label/c2_ecom_wizard_static.all.min.js"
defer
data-c2-partnerApiKey="' . $apiKey . '"
data-c2-interestFreeDaysMerchant="' . $interestFreeDaysMerchant . '"
data-c2-mode="' . $modus . '"
data-c2-locale="' . $locale . '" ></script>';
  }

  echo '<script>jQuery(document).ready(function() {
		jQuery( ".single_variation_wrap" ).on( "show_variation", function ( event, variation ) {
		    if (window.C2EcomWizard) {
		      window.C2EcomWizard.refreshAmount("dynamic", variation.display_price.toFixed(2) );
		    }
		} )});</script>';
}

add_action('wp_head', 'wc_cashpresso_label_js');

function plugin_init() {
  load_plugin_textdomain('lnx-cashpresso-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

add_action('plugins_loaded', 'plugin_init');
