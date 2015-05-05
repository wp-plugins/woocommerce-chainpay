<?php
/**
 * Plugin Name: WooCommerce ChainPay
 * Plugin URI: https://chainpay.com/
 * Description: ChainPay provides Bitcoin Payment Gateway functionality for WooCommerce.
 * Author: AltXE Limited
 * Author URI: https://chainpay.com/
 * Version: 1.3
 * License: MIT
 * Text Domain: wcchainpay
 * Domain Path: /languages/
 */

// Inform users that WooCommerce needs to be installed..
function wcchainpay_woocommerce_fallback_notice() {
    $html = '<div class="error">';
        $html .= '<p>' . __( 'ChainPay depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!', 'wcchainpay' ) . '</p>';
    $html .= '</div>';

    echo $html;
}

// Load the ChainPay plugin (specifies which function to run to initialise ChainPay).
add_action( 'plugins_loaded', 'wcchainpay_init', 0 );
// Function to initialise the ChainPay plugin.
function wcchainpay_init() {

    // If we cannot find the WC_Payment_Gateway class we inherit from.
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        // Add the WooCommerce required notice to the admin console.
        add_action( 'admin_notices', 'wcchainpay_woocommerce_fallback_notice' );
        return;
    }

    // Notify Wordpress about our languages.
    load_plugin_textdomain( 'wcchainpay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    
    // Add ChainPay to the list of supported Payment Gateways.
    add_filter( 'woocommerce_payment_gateways', 'wcchainpay_add' );

    function wcchainpay_add( $methods ) {
        $methods[] = 'WC_ChainPay';
        return $methods;
    }

    // ChainPay Class
    class WC_ChainPay extends WC_Payment_Gateway {

        public function __construct() {
            global $woocommerce;
            
            $this->id = 'chainpay';
            $this->icon = plugins_url( 'images/BC_Logotype.png', __FILE__ );
            $this->has_fields = false;
            $this->method_title = __( 'ChainPay', 'wcchainpay' );
            $this->method_description = __( 'Bitcoin Payment Gateway', 'wcchainpay' );
            
            $this->init_form_fields();
            $this->init_settings();
            
            $this->title = $this->get_option('title');
		    $this->description = $this->get_option('description');
            $this->apiKey = $this->get_option('apiKey');
            $this->privateKey = $this->get_option('privateKey');
            $this->test = $this->get_option('test');
            
            if($this->test === 'yes')
            {
                $this->apiAbsoluteUri = 'https://testapi.chainpay.com';
                $this->paymentUri = 'https://testpay.chainpay.com/invoice?id=';
            }
            else
            {
                $this->apiAbsoluteUri = 'https://api.chainpay.com/';
                $this->paymentUri = 'https://pay.chainpay.com/invoice?id=';
            }
            $this->createInvoiceUri = 'invoice';
            
            $this->log = new WC_Logger();
            $this->debug = true;
            
            add_action( 'woocommerce_api_wc_chainpay', array( &$this, 'ChainPay_Callback' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			
			if (empty($this->apiKey)) {
                add_action( 'admin_notices', array( &$this, 'ChainPay_MissingApiKey' ) );
            }
            if (empty( $this->privateKey)) {
                add_action( 'admin_notices', array( &$this, 'ChainPay_MissingPrivateKey' ) );
            }
        }
        
        public function init_form_fields() {
    	    $this->form_fields = array(
			    'enabled' => array(
				    'title'   => __( 'Enable/Disable', 'wcchainpay' ),
				    'type'    => 'checkbox',
				    'label'   => __( 'Enable payment with ChainPay', 'wcchainpay' ),
				    'default' => 'yes'
			    ),
			    'title' => array(
				    'title'       => __( 'Title', 'woocommerce' ),
				    'type'        => 'text',
				    'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				    'default'     => __( 'Bitcoin', 'wcchainpay' ),
				    'desc_tip'    => true,
			    ),
			    'description' => array(
				    'title'       => __( 'Description', 'woocommerce' ),
				    'type'        => 'textarea',
				    'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
				    'default'     => __( 'Make payment quickly and easily with Bitcoin.', 'wcchainpay' ),
				    'desc_tip'    => true,
			    ),
			    'apiKey' => array(
				    'title'       => __( 'API Key', 'wcchainpay' ),
				    'type'        => 'text',
				    'description' => __( 'Your API Key from the ChainPay Portal (Settings Tab).', 'wcchainpay' ),
				    'desc_tip'    => true,
			    ),
			    'privateKey' => array(
				    'title'       => __( 'Private Key', 'wcchainpay' ),
				    'type'        => 'text',
				    'description' => __( 'Your Private Key from the ChainPay Portal.', 'wcchainpay' ),
				    'desc_tip'    => true,
			    ),
                'test' => array(
				    'title'   => __( 'Sandbox', 'wcchainpay' ),
				    'type'    => 'checkbox',
				    'label'   => __( 'Connect to the Sandbox ChainPay API for testing.', 'wcchainpay' ),
				    'default' => 'no'
			    ),
                'debug' => array(
				    'title'   => __( 'Debug Mode', 'wcchainpay' ),
				    'type'    => 'checkbox',
				    'label'   => __( 'Log communication with the ChainPay API to the WooCommerce logs.', 'wcchainpay' ),
				    'default' => 'no'
			    ));
        }
        
        public function process_payment( $order_id ) {

            $order = new WC_Order( $order_id );

            // Set the order as pending as we are awaiting payment.
            $order->update_status( 'pending', __( 'Awaiting Bitcoin payment', 'wcchainpay' ) );
            
            // Call the ChainPay API to create a Invoice.
            $invoice = $this->ChainPay_CreateInvoice($order);
            if($invoice)
            {
                // Store the ChainPay invoice id on the order.
                update_post_meta($order->id, 'chainpay_invoice_id', $invoice->Id);
                
                // Empty shopping cart.
                WC()->cart->empty_cart();
                
                // Forward to chainpay for payment.
                return array(
                    'result' 	=> 'success',
                    'redirect'	=> $this->paymentUri . $invoice->Id
                );
            }
            else
            {
            
            }
            
        }
        
        private function ChainPay_CreateInvoice($order)
        {
            $params = array(
               'Reference' => $order->id,
               'RequestCurrency' => get_woocommerce_currency(),
               'RequestAmount' => (float) $order->order_total,
               'ForwardOnPaidUri' => $this->get_return_url( $order ),
               'ForwardOnCancelUri' => htmlspecialchars_decode($order->get_cancel_order_url()),
               'CallbackUri' => str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_ChainPay', home_url( '/' ) ) )
            );
            
            $invoice = $this->ChainPay_Post($params, $this->createInvoiceUri);
            
            if(!$invoice && $this->debug)
            {
                $this->log->add('ChainPay', 'Could not create invoice.');
            }
            
            return $invoice;
        }
        
        private function ChainPay_Post($params, $relativeUri)
        {
            $postdata = http_build_query( $params, '', '&' );
            $httpArgs = array(
                'body'       => $postdata,
                'sslverify'  => true,
                'timeout'    => 30,
                'method'     => 'POST',
                'headers' => array (
                    'Authorization' => $this->apiKey
                )
            );
            
            $absoluteUri = $this->apiAbsoluteUri . $relativeUri . '.json';
            $response = wp_remote_post($absoluteUri, $httpArgs);
            
            if ( ! is_wp_error( $response ) && 200 == $response['response']['code'] ) {
                $data = $response['body'];
                $result = $this->ChainPay_DecodeResponse($data);
                if($result)
                {
                    if($this->debug)
                    {
                        $this->log->add('ChainPay', 'Successful call to ChainPay API: ' . $absoluteUri);
                    }
                    return $result;
                }
                
                $this->log->add('ChainPay', 'Could not deserialize response: ' . $data);
            }
			else if( is_wp_error ($response) )
			{
				$this->log->add('ChainPay', 'WP Error on call: ' . json_encode($response));
			}
            else {
                if(401 == $response['response']['code'])                
                {
                    $this->log->add('ChainPay', 'Unauthorized: Called ChainPay with invalid API Key. Please check your API Key in the WooCommerce checkout settings.');
                }
                else
                {
                    $this->log->add('ChainPay', 'Error returned from ChainPay API. Called URI: ' . $absoluteUri
                    . '"\r\nWith data: ' . json_encode($postdata) 
                    . '\r\nResponse:' . json_encode($response));
                }
            }
            
            return false;
        }
        
        private function ChainPay_DecodeResponse($data)
        {
            $response = json_decode( $data );

            // Verify response is a JSON encoded object.
            if( ! is_object( $response ) ) {
                // Could not decode response.
                return false;
            }
            
            return $response;
        }
        
        private function ChainPay_ValidateSignature($message, $signature, $key)
        {
            $hmac = hash_hmac('sha256', $message, $key );
            $hmacBytes = pack('H*', $hmac); 
            
            if($hmacBytes != $signature)
            {
                $this->log->add('ChainPay', 'Invalid message signature: ' . $message);
                return false;
            }
            return true;
        }
        
        public function ChainPay_Callback()
        {
            @ob_clean();
            global $wpdb;
            
            $data = file_get_contents('php://input');
            
            $callbackId = $_SERVER['HTTP_X_ALTXE_CALLBACKID'];
            $callbackType = $_SERVER['HTTP_X_ALTXE_CALLBACKTYPE'];
            $callbackCreated = $_SERVER['HTTP_X_ALTXE_CALLBACKCREATED'];
            $callbackAttempt = $_SERVER['HTTP_X_ALTXE_CALLBACKATTEMPT'];
            $callbackSignature = $_SERVER['HTTP_X_ALTXE_SIGNATURE'];
            $callbackSalt = $_SERVER['HTTP_X_ALTXE_SALT'];
            
            $signature = base64_decode($callbackSignature);
            $salt = base64_decode($callbackSalt);
            $key = base64_decode($this->privateKey) . $salt;
            
            $isValid = $this->ChainPay_ValidateSignature($data, $signature, $key);
            if($isValid != false)
            {
                $event = $this->ChainPay_DecodeResponse($data);
                if($this->debug)
                    $this->log->add('ChainPay', 'WebHook object received: ' . json_encode($event));
            
                if($event != false)
                {
                    $invoiceId = $event->InvoiceId;
                    $reference = $event->Reference;
                
                    // Find our OrderId based on Reference.
                    $metadata = $wpdb->get_row("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'chainpay_invoice_id' AND meta_value = '$invoiceId'");
                    $orderId = $metadata->post_id;
                    $order = new WC_Order($orderId);
                    if($order->id == $reference)
                    {
                        switch($callbackType)
                        {
                            case 'InvoicePaid':
                                if($this->debug)
                                {
                                    $this->log->add('ChainPay', 'WebHook - OrderId: ' . $orderId . ' is now Paid.');
                                }
                        
                                // Order is Paid but not yet confirmed, put it On-Hold (Awaiting Payment).
                                $order->update_status('on-hold', __('Bitcoin payment received, awaiting confirmation.', 'wcchainpay'));
                                                
                                // Reduce stock levels
                                $order->reduce_order_stock();
                        
                                break;
                            case 'InvoiceCompleted':
                        
                                if($this->debug)
                                {
                                    $this->log->add('ChainPay', 'WebHook - OrderId: ' . $orderId . ' is now Completed.');
                                }
                                // Order is now confirmed and can be completed.
                        
                                $order->add_order_note(__('Bitcoin payment confirmed.', 'wcchainpay'));
                                $order->payment_complete();
                        
                                break;
                            case 'InvoiceExpired':
                        
                                if($this->debug)
                                {
                                    $this->log->add('ChainPay', 'WebHook - OrderId: ' . $orderId . ' is now Expired (Cancelled).');
                                }
                        
                                $order->cancel_order('Bitcoin payment expired.');
                        
                                break;
                        }
                        return true;
                    }
                    else
                    {
                        if($this->debug)
                        {
                            $this->log->add('ChainPay', 'Could not locate Order relating to WebHook event: ' . json_encode($data));
                        }
                    }
                }
            }
            
            $this->log->add('ChainPay', 'WebHook event failure: ' . json_encode($data));
            
            wp_die( __( 'ChainPay Webhook Failure', 'wc-chainpay' ) );
        }
		
		public function ChainPay_MissingApiKey() {
            $html = '<div class="error">';
                $html .= '<p>' . sprintf( __( '<strong>ChainPay Disabled</strong> Please enter your API Key from the <a href="https://portal.chainpay.com">ChainPay Portal</a>. %sClick here to configure!%s', 'wcchainpay' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&amp;tab=checkout&amp;section=wc_chainpay">', '</a>' ) . '</p>';
            $html .= '</div>';

            echo $html;
        }
		
		public function ChainPay_MissingPrivateKey() {
            $html = '<div class="error">';
                $html .= '<p>' . sprintf( __( '<strong>ChainPay Disabled</strong> Please enter your Private Key from the <a href="https://portal.chainpay.com">ChainPay Portal</a>. %sClick here to configure!%s', 'wcchainpay' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&amp;tab=checkout&amp;section=wc_chainpay">', '</a>' ) . '</p>';
            $html .= '</div>';

            echo $html;
        }
    }
}
