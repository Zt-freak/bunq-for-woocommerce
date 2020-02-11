<?php
/**
 * Plugin Name: bunq for WooCommerce
 * Description: Accept payments in your WooCommerce shop with just your bunq account.
 * Version: 0.0.1
 * Author: Patrick Kivits
 * Author URI: https://www.patrickkivits.nl
 * Requires at least: 3.8
 * Tested up to: 5.3
 * Text Domain: bunq-for-woocommerce
 * License: GPLv2 or later
 * WC requires at least: 2.2.0
 * WC tested up to: 3.8
 */

require_once (__DIR__.'/vendor/autoload.php');
require_once (__DIR__.'/includes/bunq.php');

// Check for active WooCommerce install
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'bunq_add_gateway_class' );
function bunq_add_gateway_class( $gateways ) {
    $gateways[] = 'WC_Bunq_Gateway'; // your class name is here
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'bunq_init_gateway_class' );
function bunq_init_gateway_class() {

    class WC_Bunq_Gateway extends WC_Payment_Gateway {

        var $api_key;
        var $testmode;
        var $monetary_account_bank_id;
        var $api_context;

        public function __construct() {
            $this->id = 'bunq';
            $this->icon = '';
            $this->has_fields = false;
            $this->method_title = 'bunq';
            $this->method_description = 'bunq payment gateway for WooCommerce';

            $this->supports = array(
                'products'
            );

            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled = $this->get_option( 'enabled' );
            $this->testmode = 'yes' === $this->get_option( 'testmode' );
            $this->api_key = $this->testmode ? $this->get_option( 'test_api_key' ) : $this->get_option( 'api_key' );
            $this->api_context = $this->testmode ? $this->get_option( 'test_api_context' ) : $this->get_option( 'api_context' );
            $this->monetary_account_bank_id = $this->get_option( 'monetary_account_bank_id' );

            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            // You can also register a webhook here
            add_action( 'woocommerce_api_wc_bunq_gateway', array( $this, 'bunq_callback' ) );
        }

        public function process_admin_options() {
            parent::process_admin_options();

            // Get saved testmode and api key
            $testmode = 'yes' === $this->settings['testmode'];
            $api_key = $testmode ? $this->settings['test_api_key'] : $this->settings['api_key'];
            $monetary_account_bank_id = $this->settings['monetary_account_bank_id'] > 0 ? intval($this->settings['monetary_account_bank_id']) : null;

            // Recreate API context after settings are saved
            if($api_key)
            {
                $api_context = bunq_create_api_context($api_key, $testmode);
                $this->update_option(($testmode ? 'test_api_context' : 'api_context'), $api_context->toJson());

                // Setup callback URL for bunq (only on production)
                if(!in_array($_SERVER['REMOTE_ADDR'], array('127.0.0.1', '::1')))
                {
                    bunq_create_notification_filters($monetary_account_bank_id);
                }
            }
        }

        public function get_setting($key)
        {
            $this->init_settings();

            $testmode = 'yes' === $this->settings['testmode'];

            if($key === 'api_context')
            {
                $key = $testmode ? 'test_api_context' : 'api_context';
            }

            return $this->settings[$key];
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields(){

            $api_context = $this->get_setting('api_context');

            // Load Bunq API context
            bunq_load_api_context($api_context);

            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable bunq Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'iDEAL, Credit Card or Sofort',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with iDEAL, Credit Card or Sofort',
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'test_api_key' => array(
                    'title'       => 'Test API Key',
                    'type'        => 'text',
                ),
                'api_key' => array(
                    'title'       => 'Live API Key',
                    'type'        => 'text'
                ),
                'monetary_account_bank_id' => array(
                    'title'       => 'Bank account',
                    'type'        => 'select',
                    'options'     => bunq_get_bank_accounts($api_context)
                ),
                'api_context' => array(
                    'title'       => 'Live API Context',
                    'type'        => 'textarea',
                    'disabled'    => true,
                    'css'         => 'height: 150px;'
                ),
                'test_api_context' => array(
                    'title'       => 'Test API Context',
                    'type'        => 'textarea',
                    'disabled'    => true,
                    'css'         => 'height: 150px;'
                ),
            );
        }

        public function process_payment( $order_id ) {

            $api_context = $this->get_setting('api_context');
            bunq_load_api_context($api_context);

            $order = wc_get_order( $order_id );
            $monetary_account_bank_id = $this->get_setting('monetary_account_bank_id') > 0 ? intval($this->get_setting('monetary_account_bank_id')) : null;

            $payment_request = bunq_create_payment_request(
                $order->get_total(),
                $order->get_currency(),
                '#'.$order->get_order_number(),
                $this->get_return_url($order),
                $monetary_account_bank_id
            );

            if($payment_request && isset($payment_request['id'])) {
                $order->add_order_note('bunq payment_request created '.$payment_request['id']);
                $order->update_meta_data( 'bunq_payment_request_id', $payment_request['id']);
                $order->save();

                return array(
                    'result'   => 'success',
                    'redirect' => $payment_request['url'],
                );
            }

            return array('result' => 'failure');
        }

        public function bunq_callback() {

            // Get input and save raw as text file
            $input = file_get_contents('php://input');

            if(!$input)
            {
                $input = $_REQUEST;
            }

            $obj = json_decode($input);

            $category = $obj->NotificationUrl->category;
            $eventType = $obj->NotificationUrl->event_type;

            if($category !== 'BUNQME_TAB' || $eventType !== 'BUNQME_TAB_RESULT_INQUIRY_CREATED')
            {
                exit;
            }

            // Retrieve payment request id (bunqmetab)
            $payment_request_id = $obj->NotificationUrl->object->BunqMeTabResultInquiry->bunq_me_tab_id;

            // Retrieve order by bunq payment request id
            $orders = wc_get_orders( array(
                'limit'        => 1, // Query all orders
                'orderby'      => 'date',
                'order'        => 'DESC',
                'meta_key'     => 'bunq_payment_request_id',
                'meta_compare' => $payment_request_id
            ));

            // Only continue is we have exactly 1 order
            if(count($orders) !== 1)
            {
                exit;
            }

            // Set order
            $order = $orders[0];

            // Check payment
            $monetary_account_bank_id = $this->get_setting('monetary_account_bank_id') > 0 ? intval($this->get_setting('monetary_account_bank_id')) : null;
            $payment_request = bunq_get_payment_request($payment_request_id, $monetary_account_bank_id);

            foreach($payment_request->getResultInquiries() as $resultInquiry)
            {
                $payment = $resultInquiry->getPayment();

                // Process payment
                if($payment && $payment->getAmount()->getCurrency() === $order->get_currency() && $payment->getAmount()->getValue() === $order->get_total())
                {
                    // Update order with payment id
                    $order->add_order_note('bunq payment received '.$payment->getId());
                    $order->update_meta_data( 'bunq_payment_id', $payment->getId());
                    $order->save();

                    // Complete order
                    global $woocommerce;
                    $woocommerce->cart->empty_cart();
                    $order->payment_complete();

                    break;
                }
            }

            die();
        }
    }
}