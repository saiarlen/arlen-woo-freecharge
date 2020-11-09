<?php
   /*
   Plugin Name: Gateway for Freecharge on WooCommerce
   Plugin URI: https://www.arlencode.com
   description: FreeCharge woocommerce payments gateway which enables merchants to accept payments from their customers who use FreeCharge account for online/offline transaction. you can also accept online payments from across all major channels.
   Version: 1.0.1
   Author: Saiarlen
   Author URI: http://www.saiarlen.com
   License: GPL v2 or later
   License URI:       https://www.gnu.org/licenses/gpl-2.0.html
   Text Domain:       arlen-woo-freecharge
   Domain Path:       languages
   */
   /*
   Gateway for Freecharge on WooCommerce is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 2 of the License, or
   any later version.

   Gateway for Freecharge on WooCommerce is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with Gateway for Freecharge on WooCommerce. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
   */

defined('ABSPATH') || exit;

class arConstants
{
    const ENCR = "sha256";
    const ENCODING = "UTF-8";
}
//Checksum Class for Json Request
class arChecksum
{
    public static function arJsonChecksum($json_decode_output, $merchantKey)
    {
        // JSON must be alphabetically serialised and must not contain null or empty values.
        $sanitizedInput = arChecksum::arSanitizeInput($json_decode_output, $merchantKey);

        // adding merchant Key
        $serializedObj = $sanitizedInput . $merchantKey;

        // Calculate Checksum for the serialized string
        return arChecksum::arCalculateChecksum($serializedObj);
    }
    
    private static function arCalculateChecksum($serializedObj)
    {
        // Use 'sha-265' for hashing
        $checksum = hash(arConstants::ENCR, $serializedObj, false);
        return $checksum;
    }
    /*   public String generateChecksum(String jsonString, String merchantKey)
    throws Exception {
    MessageDigest md;
    String plainText = jsonString.concat(merchantKey);
    try {
    md = MessageDigest.getInstance("SHA-256");
    } catch (NoSuchAlgorithmException e)
    {
    throw new Exception(); //
    }
    md.update(plainText.getBytes(Charset.defaultCharset()));
    byte[] mdbytes = md.digest();
    // convert the byte to hex format method 1
    StringBuffer checksum = new StringBuffer();
    for (int i = 0; i < mdbytes.length; i++)
    {
    checksum.append(Integer.toString((mdbytes[i] & 0xff) +
    0x100, 16).substring(1));
    }
    return checksum.toString();
    }*/

    private static function arRecursiveSort(&$array)
    {
        //json object keys alphabetically recursively sortning
        foreach ($array as &$value) {
            if (is_array($value)) {
                arChecksum::arRecursiveSort($value);
            }
        }
        return ksort($array);
    }
    private static function arSanitizeInput(array $json_decode_output, $merchantKey)
    {
        $dataWithoutNull = array_filter($json_decode_output, function ($Jout) {
            if (is_null($Jout)) {
                return false;
            }
            if (is_array($Jout)) {
                return true;
            }

            return !(trim($Jout) == "");
        });

        arChecksum::arRecursiveSort($dataWithoutNull);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        return json_encode($dataWithoutNull, $flags);
    }
}
//End of Checksum


//Plugin Init
add_action('plugins_loaded', 'arFreechargeInit', 0);

function arFreechargeInit()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    //Woo Gateway Calss
    class WC_Gateway_Free_Charge extends WC_Payment_Gateway
    {

      /* * @var bool Whether or not logging is enabled */
        public static $log_enabled = false;

        /* * @var WC_Logger Logger instance */
        public static $log = false;

        // Go wild in here
        public function __construct()
        {
            $this->id = 'freecharge';
            $this->method_title = 'Free Charge';
            $this->has_fields = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->merchant_id = $this->settings['merchant_id'];
            $this->secret_key = $this->settings['secret_key'];
            $this->sandbox = $this->settings['sandbox'];
            $this->debug = $this->settings['debug'];

            $this->supports = array(
            'products',
            'refunds',
         );

            self::$log_enabled = $this->debug;

            if ($this->sandbox == 'yes') {
                $this->liveurl = "https://checkout-sandbox.freecharge.in/api/v1/co/pay/init";
            } else {
                $this->liveurl = "https://checkout.freecharge.in/api/v1/co/pay/init";
            }

            $this->notify_url = home_url('?wc-api=wc_gateway_free_charge');
            $this->msg['message'] = "";
            $this->msg['class'] = "";

            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'arFreechargeResponseCheck'));

            add_action('valid-freecharge-request', array($this, 'successful_request'));

            if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
            add_action('woocommerce_receipt_freecharge', array($this, 'receipt_page'));
        }

        /**
         * Logging method.
         * @param string $message
         */
        public static function log($message)
        {
            if (self::$log_enabled) {
                if (empty(self::$log)) {
                    self::$log = new WC_Logger();
                }
                self::$log->add('freeCharge', '');
                if (is_array($message)) {
                    foreach ($message as $key => $value) {
                        $data = "Field " . htmlspecialchars($key) . " is " . htmlspecialchars($value);
                        self::$log->add('freeCharge', $data);
                    }
                } else {
                    self::$log->add('freeCharge', $message);
                }
                self::$log->add('freeCharge', '');
            }
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
            'enabled' => array(
               'title' => __('Enable/Disable', 'arlen-woo-freecharge'),
               'type' => 'checkbox',
               'label' => __('Enable this module?', 'arlen-woo-freecharge'),
               'default' => 'no'),

            'sandbox' => array(
               'title' => __('Enable Sandbox?', 'arlen-woo-freecharge'),
               'type' => 'checkbox',
               'label' => __('Enable Sandbox for Free Charge Payment.', 'arlen-woo-freecharge'),
               'default' => 'no'),

            'merchant_id' => array(
               'title' => __('Merchant ID', 'arlen-woo-freecharge'),
               'type' => 'text',
               'description' => __('Merchant ID is provided with your freecharge merchant account if you did not the {ID} contact your freecharge support')),

            'secret_key' => array(
               'title' => __('Merchant Key', 'arlen-woo-freecharge'),
               'type' => 'text',
               'description' => __('The secret key can be given by freecharge account Dashboard. Use test or live for test or live mode.', 'arlen-woo-freecharge'),
            ),
            'debug' => array(
               'title' => __('Debug log', 'arlen-woo-freecharge'),
               'type' => 'checkbox',
               'discription' => __('Note: this may log personal information. We recommend using this for debugging purposes only and deleting the logs when finished.', 'arlen-woo-freecharge'),
               'label' => __('Enable Debug for Free Charge Payment.', 'arlen-woo-freecharge'),
               'default' => 'no'),

            'title' => array(
               'title' => __('Title:', 'arlen-woo-freecharge'),
               'type' => 'text',
               'description' => __('This controls the title which the user sees during checkout.', 'arlen-woo-freecharge'),
               'default' => __('Freecharge | Accepts all payment modes', 'arlen-woo-freecharge')),

            'description' => array(
               'title' => __('Description:', 'arlen-woo-freecharge'),
               'type' => 'textarea',
               'description' => __('This controls the description which the user sees during checkout.', 'arlen-woo-freecharge'),
               'default' => __('Pay securely by Credit or Debit card or Internet Banking through Free Charge Secure Servers.', 'arlen-woo-freecharge')),

         );
        }
        //Admin Panel Options
        public function admin_options()
        {
            echo '<h3>' . __('Woocommerce Freecharge Payment Gateway', 'arlen-woo-freecharge') . '</h3>';
            echo '<p>' . __('Allows payments by Credit/Debit Cards, NetBanking, UPI through Freecharge') . '</p>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }
        //There are no payment fields for Free Charge
        public function payment_fields()
        {
            if ($this->description) {
                echo(wptexturize($this->description));
            }
        }

        //Receipt Page Message
        public function receipt_page($order)
        {
            echo '<p>' . __('Thank you for your order! please procced to pay.', 'arlen-woo-freecharge') . '</p>';
            echo $this->arOutputForm($order);
        }

        //Process the payment and return the result
        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
        }

        //Generate Free Charge button link
        public function arOutputForm($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $order_id = $order_id;

            $the_order_total = $order->order_total;
            $freecharge_args = array(
            'merchantTxnId' => strval($order_id),
            'amount' => $the_order_total,
            'furl' => $this->notify_url,
            'surl' => $this->notify_url,
            'merchantId' => $this->merchant_id,
            'channel' => "WEB",
         );

            $checksum = arChecksum::arJsonChecksum(json_decode(json_encode($freecharge_args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->secret_key);

            $freecharge_args_array = array();
            foreach ($freecharge_args as $key => $value) {
                $freecharge_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">' . "\n";
            }

            //add checksum
            $freecharge_args_array[] = '<input type="hidden" name="checksum" value="' . esc_attr($checksum) . '">' . "\n";

            $form = '';

            wc_enqueue_js('
               $.blockUI({
                  message: "' . esc_js(__('Thank you for your order! We are now redirecting you to Free Charge to make payment.', 'woocommerce')) . '",
                  baseZ: 99999,
                  overlayCSS:
                  {
                     background: "#ffffff",
                     opacity: 0.7
                  },
                  css: {
                     padding:        "20px",
                     zindex:         "9999999",
                     width:         "65%",
                     textAlign:      "center",
                     color:          "#555",
                     border:         "1px solid #aaa",
                     backgroundColor:"#fff",
                     cursor:         "wait",
                     lineHeight:     "24px",
                     left:           "18%",
                     right:          "18%",
                  }
               });
            jQuery("#submit_freecharge_payment_form").click();
            ');

            // Get the right URL in case the test mode is enabled
            $posturl = $this->liveurl;

            $form .= '<form action="' . esc_url($posturl) . '" method="post" id="freecharge_payment_form">
            ' . implode('', $freecharge_args_array) . '
            <!-- Button Fallback -->
            <div class="payment_buttons">
            <input type="submit" class="button alt" id="submit_freecharge_payment_form" value="' . __('Pay Now', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woocommerce') . '</a>
            </div>
            </form>';
            return $form;
        }

        /**
         * Create Checksum for Server Response
         **/

        public function arChecksumResponse($args)
        {
            $hashargs = array(
            'txnId' => $args['txnId'],
            'status' => $args['status'],
            'metadata' => $args['metadata'],
            'merchantTxnId' => $args['merchantTxnId'],
            'authCode' => $args['authCode'],
            'amount' => $args['amount'],
         );

            $hash = arChecksum::arJsonChecksum(json_decode(json_encode($hashargs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->secret_key);

            return $hash;
        }

        public function arFreechargeResponseCheck()
        {
            global $woocommerce;

            $msg['class'] = 'error';
            $msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";

            if (isset($_REQUEST['status'])) {

            //Log Request

                $this->log($_REQUEST);

                $order_id = (int) wc_clean($_REQUEST['merchantTxnId']);

                if ($order_id != '') {
                    try {
                        $order = new WC_Order($order_id);
                        $order_status = strtolower(wc_clean($_REQUEST['status']));
                        $transauthorised = false;
                        if ($order->status !== 'completed') {

                     // Check if User Click back to merchant link ( There is no Checksum to verfiy)
                            if ($order_status === "failed" && wc_clean($_REQUEST['errorCode']) == "E704") {
                                $admin_email = get_option('admin_email');
                                $msg['message'] = 'Payment was cancelled. Reason: ' . wc_clean($_REQUEST['errorMessage']);
                                $msg['class'] = 'error';
                            } elseif ($this->arChecksumResponse(wc_clean($_REQUEST)) === wc_clean($_REQUEST['checksum'])) {

                        //verify response using checksum

                                if ($order_status == "completed") {
                                    $transauthorised = true;
                                    $msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.";
                                    $msg['class'] = 'success';
                                    if ($order->status != 'processing') {
                                        $transaction_id = wc_clean($_REQUEST['txnId']);
                                        $order->payment_complete($transaction_id);
                                        $order->add_order_note('Free Charge payment successful<br/>Ref Number: ' . $_REQUEST['txnId']);
                                        $woocommerce->cart->empty_cart();
                                    }
                                } elseif ($order_status === "failed" && wc_clean($_REQUEST['errorCode']) == "E005") {
                                    $admin_email = get_option('admin_email');
                                    $msg['message'] = 'Server Error , Please use other payment gateway.';
                                    $msg['class'] = 'error';
                                } else {
                                    $msg['class'] = 'error';
                                    $msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                                }
                            } else {
                                $this->msg['class'] = 'error';
                                $this->msg['message'] = "Security Error. Illegal access detected.";
                            }
                        }

                        if ($transauthorised == false) {
                            $order->update_status('failed');
                            $order->add_order_note('Failed');
                            $order->add_order_note($msg['message']);
                        }
                    } catch (Exception $e) {
                        $msg['class'] = 'error';
                        $msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                    }
                }
            }

            if (function_exists('wc_add_notice')) {
                wc_add_notice($msg['message'], $msg['class']);
            } else {
                if ($msg['class'] == 'success') {
                    $woocommerce->add_message($msg['message']);
                } else {
                    $woocommerce->add_error($msg['message']);
                }
                $woocommerce->set_messages();
            }
            //redirect_URL
            $redirect_url = $this->get_return_url($order);
            wp_redirect($redirect_url);

            exit;
        }

        /**
         * Can the order be refunded via FreeCharge?
         * @param  WC_Order $order
         * @return bool
         */
        public function can_refund_order($order)
        {
            return $order && $order->get_transaction_id();
        }

        /**
         * Process a refund if supported.
         * @param  int    $order_id
         * @param  float  $amount
         * @param  string $reason
         * @return bool True or false based on success, or a WP_Error object
         */

        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);

            if (!$this->can_refund_order($order)) {
                $this->log('Refund Failed: No transaction ID');
                return new WP_Error('error', __('Refund Failed: No transaction ID', 'woocommerce'));
            }
            include_once 'refund.php';
            WC_Gateway_Free_Charge_Refund::$merchantId = $this->merchant_id;

            WC_Gateway_Free_Charge_Refund::$secertkey = $this->secret_key;

            $result = WC_Gateway_Free_Charge_Refund::refund_order($order, $amount, $this->sandbox);

            if (is_wp_error($result)) {
                $this->log('Refund Failed: ' . $result->get_error_message());
                return new WP_Error('error', $result->get_error_message());
            }

            $this->log('Refund Result: ' . $result);

            switch (strtolower($result['status'])) {
         case 'success':
         case 'initiated':
            $order->add_order_note(sprintf(__('Refund Initiated. Amount: %s - Refund ID: %s', 'woocommerce'), $result['refundedAmount'], $result['refundTxnId']));
            return true;
            break;
         }

            return isset($result['errorCode']) ? new WP_Error('error', $result['errorMessage']) : false;
        }
    }

    //Add the Gateway to WooCommerce
    function woocommerce_add_freecharge_gateway($methods)
    {
        $methods[] = 'WC_Gateway_Free_Charge';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_freecharge_gateway');
}
