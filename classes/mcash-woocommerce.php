<?php
/*
  Description: Extends WooCommerce by Adding mCASH Gateway.
  Author: mCASH AS
  License: The MIT License (MIT)
*/
    
if (! defined('ABSPATH') ) {
    exit; // Exit if accessed directly
}

class Mcash_Woocommerce extends WC_Payment_Gateway
{
    
    // Setup our Gateway's id, description and other values
    function __construct() 
    {
        $this->id = "mcash";
        $this->method_title = __("mCASH", 'mcash-woocommerce-gateway');
        $this->method_description = __("mCASH Payment Gateway Plug-in for WooCommerce", 'mcash-woocommerce-gateway');
        $this->title = __("mCASH", 'mcash-woocommerce-gateway');
        $this->init();
    }
    
    function init() 
    {
        $this->has_fields = false;
        $this->supports = array(
        'products',
        'refunds'
        );
        $this->icon = "https://mca.sh/wp-content/themes/mcash/assets/images/logo_mcash.png";
        $this->init_form_fields();

        // After init_settings() is called, you can get the settings and load them into variables, e.g:
        // $this->title = $this->get_option( 'title' );
        $this->init_settings();

        // Turn these settings into variables we can use
        foreach ( $this->settings as $setting_key => $value ) {
            $this->$setting_key = $value;
        }  
        $this->testmode = 'yes' === $this->get_option('testmode', 'no');
        $this->logging = 'yes' === $this->get_option('logging', 'no');
        
        if ($this->testmode ) {
            $this->mcash_server = $this->get_option('test_server');
            $this->sslverify = false;
        } else {
            $this->mcash_server = "https://api.mca.sh";
            $this->sslverify = true;
        }
        
        // Payment listener/API hook
        add_action('woocommerce_api_mcash_woocommerce', array( $this, 'mcash_woocommerce_callback' ));
        
        // Lets check for SSL
        add_action('admin_notices', array( $this,  'do_ssl_check' ));
        
        if (! $this->is_valid_for_use() ) {
            $this->enabled = 'no';
        }
        
        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
        add_action('woocommerce_receipt_' . $this->id, array( $this, 'mcash_payment_portal' ));
        add_action('woocommerce_thankyou_' . $this->id, array( $this, 'mcash_return_handler' ));
        
        if ($this->logging ) {
            if (empty( $this->log ) ) {
                $this->log = new WC_Logger();
            }
        } else {
            $this->log = null;
        }
        
        include_once  'mcash-client.php' ;
        $this->mcash_client = new Mcash_Client(
            $this->log,
            $this->mcash_server,
            $this->mid,
            $this->uid,
            $this->priv_key,
            $this->sslverify,
            $this->testmode,
            $this->testbed_token
        );
    }
    
    function url_origin($s, $use_forwarded_host=false)
    {
        $ssl = (!empty($s['HTTPS']) && $s['HTTPS'] == 'on') ? true:false;
        $sp = strtolower($s['SERVER_PROTOCOL']);
        $protocol = substr($sp, 0, strpos($sp, '/')) . (($ssl) ? 's' : '');
        $port = $s['SERVER_PORT'];
        $port = ((!$ssl && $port=='80') || ($ssl && $port=='443')) ? '' : ':'.$port;
        $host = ($use_forwarded_host && isset($s['HTTP_X_FORWARDED_HOST'])) ? $s['HTTP_X_FORWARDED_HOST'] : (isset($s['HTTP_HOST']) ? $s['HTTP_HOST'] : null);
        $host = isset($host) ? $host : $s['SERVER_NAME'] . $port;
        return $protocol . '://' . $host;
    }
    
    function full_url($s, $use_forwarded_host=false)
    {
        return $this->url_origin($s, $use_forwarded_host) . $s['REQUEST_URI'];
    }
    
    public function mcash_woocommerce_callback() 
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $this->full_url($_SERVER);
        $this->log('mcash_woocommerce_callback() $method = ' . $method );
        $this->log('mcash_woocommerce_callback() $uri = ' . $uri );
        
        @ob_clean();
        $body = file_get_contents('php://input');
        $this->log('mcash_woocommerce_callback() $body = ' . $body);
        if ($body == '' ) {
            header('HTTP/1.1 400 Bad Request');
            exit;
        }
        
        $payload = json_decode($body);

        if (! $payload->meta->id ) {
            header('HTTP/1.1 400 Bad Request');
            exit;
        }

        if ( false ) {
            // If signature is correct, then call to outcome is not needed. Saves time.
            // Re-add later.
            $headers = $this->mcash_client->request_headers();
            if ( array_key_exists('Authorization', $headers) )
            {
                $this->log('mCASH validateSignature() Authorization header present');
                if (! $this->mcash_client->valid_signature($method, $uri, $headers, $body) ){
                    header('HTTP/1.1 401 Unauthorized');
                    exit;
                }
            } else {
                $this->log('mCASH Authorization header is not in http basic authentication format, and will be discarded by apache, if apache is not configured accordingly');
                // Makes no diffrence yet. At the moment we always make a call to mCASH anyway.
            }
        }
        
        if ($payload->meta->id ) {
            if( $this->get_payment_outcome($payload->meta->uri) ){
                header('HTTP/1.1 204 No Content');
                exit;
            } else {
                header('HTTP/1.1 503 Service Unavailable');
                exit;               
            }
        }

        header('HTTP/1.1 400 Bad Request');
        exit;
    }

    
    public function process_admin_options() 
    {
        parent::process_admin_options();
        // // Load form_field settings
        $settings = get_option($this->plugin_id . $this->id . '_settings', null);
        if ($settings['generate_new_rsa_keys'] == 'yes') {
            $keyPair = $this->generate_key_pair();
            $settings['generate_new_rsa_keys'] = 'no';
            $settings['pub_key'] = $keyPair['pubKey'];
            $settings['priv_key'] = $keyPair['privKey'];
        }
        update_option($this->plugin_id . $this->id . '_settings', $settings);
        $this->log('process_admin_options() ' . $this->plugin_id . $this->id . '_settings');
    }

    
    public function init_form_fields() 
    {
        
        $this->form_fields = array(
            'enabled' => array(
                'title'     => __('Enable / Disable', 'mcash-woocommerce-gateway'),
                'label'     => __('Enable this payment gateway', 'mcash-woocommerce-gateway'),
                'type'      => 'checkbox',
                'default'   => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'mcash-woocommerce-gateway'),
                'type'        => 'text',
                'description' => __('Payment title the customer will see during the checkout process.', 'mcash-woocommerce-gateway'),
                'default'     => $this->method_title,
            ),
            'description' => array(
                'title'       => __('Description', 'mcash-woocommerce-gateway'),
                'type'        => 'textarea',
                'description' => __('Payment description the customer will see during the checkout process.', 'mcash-woocommerce-gateway'),
                'default'     => sprintf(__('Pay with %s', 'mcash-woocommerce-gateway'), $this->method_title),
                'css'         => 'max-width:350px;'
            ),
            'mid' => array(
                'title'     => __('merchant id', 'mcash-woocommerce-gateway'),
                'type'      => 'text',
                'description'  => sprintf(__('This is the merchant id that was provided by mcash.no when you signed up for an account at %shttps://my.mca.sh/mssp/%s .', 'mcash-woocommerce-gateway'), '<a href="https://my.mca.sh/mssp/">', '</a>'),
            ),
            'uid' => array(
                'title'     => __('merchant user id', 'mcash-woocommerce-gateway'),
                'type'      => 'text',
                'description'  => sprintf(__('The merchant user created by you at %shttps://my.mca.sh/mssp/%s .', 'mcash-woocommerce-gateway'), '<a href="https://my.mca.sh/mssp/">', '</a>'),
            ),
            'generate_new_rsa_keys' => array(
                'title'     => __('Generate new RSA keys', 'mcash-woocommerce-gateway'),
                'label'     => __('Generate new RSA keys', 'mcash-woocommerce-gateway'),
                'type'      => 'checkbox',
                'description'  => sprintf(__('If set to "yes", new keys will be generated, and you need to copy the public key to %shttps://my.mca.sh/mssp/%s .', 'mcash-woocommerce-gateway'), '<a href="https://my.mca.sh/mssp/">', '</a>'),
                'default'   => 'yes',
            ),
            'priv_key' => array(
                'title'     => __('Private RSA key', 'mcash-woocommerce-gateway'),
                'type'      => 'textarea',
                'description'  => __('Your private RSA key. Keep it secret.', 'mcash-woocommerce-gateway'),
                'css'       => 'max-width:600px; height: 350px;'
            ),
            'pub_key' => array(
                'title'     => __('Public RSA key', 'mcash-woocommerce-gateway'),
                'type'      => 'textarea',
                'description'  => sprintf(__('Your public RSA key. Copy this to the corresponding field for your merchant user at %shttps://my.mca.sh/mssp/%s .', 'mcash-woocommerce-gateway'), '<a href="https://my.mca.sh/mssp/">', '</a>'),
                'css'       => 'max-width:600px; height: 120px;'
            ),
            'autocapture' => array(
                'title'     => __('autocapture', 'mcash-woocommerce-gateway'),
                'label'     => __('Capture an authorized payment automatically', 'mcash-woocommerce-gateway'),
                'type'      => 'checkbox',
                'description' => __('Capture an authorized payment automatically. If not set, capture needs to be done in the order view within 72 hours, else the auth will expire and the money will be refunded.', 'mcash-woocommerce-gateway'),
                'default'   => 'yes',
            ),
            'testmode' => array(
                'title'     => __('Test Mode', 'mcash-woocommerce-gateway'),
                'label'     => __('Enable Test Mode', 'mcash-woocommerce-gateway'),
                'type'      => 'checkbox',
                'description' => __('Place the payment gateway in test mode.', 'mcash-woocommerce-gateway'),
                'default'   => 'no',
            ),
            'logging' => array(
                'title'     => __('Log Mode', 'mcash-woocommerce-gateway'),
                'label'     => __('Enable logging', 'mcash-woocommerce-gateway'),
                'type'      => 'checkbox',
                'default'   => 'no',
            ),
            'testbed_token' => array(
                'title'     => __('testbed_token', 'mcash-woocommerce-gateway'),
                'type'      => 'text',
                'description'  => sprintf(__('When using mCASH %stest environment%s , this token needs to be set', 'mcash-woocommerce-gateway'), '<a href="https://mcashtestbed.appspot.com/testbed/">', '</a>'),
                'disabled'  =>  ( $this->get_option('testmode', 'no') == 'no' ) ? true : false,
            ),
            'test_server' => array(
                'title'     => __('test_server', 'mcash-woocommerce-gateway'),
                'type'      => 'text',
                'default'   => 'https://mcashtestbed.appspot.com',
                'disabled'  => ( $this->get_option('testmode', 'no') == 'no' ) ? true : false,
                'description'  =>  __('Only concerns developers', 'mcash-woocommerce-gateway')
            ),
            
        );      
    }

    
    function generate_key_pair() 
    {
        $config = array(
            "digest_alg" => "sha512",
            "private_key_bits" => 1024,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        );
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privKey);
        $pubKey = openssl_pkey_get_details($res);
        $keyPair = array(
            'pubKey'      => $pubKey['key'],
            'privKey'     => $privKey
        );
        return $keyPair;
    }

    
    function is_valid_for_use() 
    {
        if (! in_array(get_woocommerce_currency(), array( 'NOK' )) ) {
            return false;
        }
        return true;
    }


    public function admin_options() 
    {
        if ($this->is_valid_for_use() ) {
            parent::admin_options();
        } else {
            ?>
         <div class="inline error"><p><strong><?php _e('Gateway Disabled', 'mcash-woocommerce-gateway'); ?></strong>: <?php _e('mCASH does not support your store currency.', 'mcash-woocommerce-gateway'); ?></p></div>
            <?php
        }
    }


    function process_payment( $order_id ) 
    {
        $order = new WC_Order($order_id);
        $result = array(
            'result'    => 'success',
            'redirect'  => $order->get_checkout_payment_url(true)
        );
        return $result;
    }
    
    
    function mcash_payment_portal($order_id)
    {
        $return = $this->payment_request($order_id);
        $this->log('mcash_payment_portal() order_id = ' . $order_id . ' $return = ' . print_r($return, true));
        if( is_wp_error( $return ) ) {
            $this->log('mcash_payment_portal() order_id = ' . $order_id . ' error_message = ' . $return->get_error_message());
            echo $return->get_error_message();
        }else {
            ?>
            <script type="text/javascript">top.location.href='<?php echo $return->uri; ?>'</script>
            <?php
        }
    }

    
    public function mcash_return_handler($order_id) 
    {
        // To slow to do capture here. Leave it to the callback handler.
    }

    
    function order_description($order)
    {
        $this->log('order_description() order = ' . print_r($order, true));
        $text = "";
        if (sizeof($order->get_items()) > 0 ) {
            foreach ( $order->get_items() as $item ) {
                $this->log('order_description() item = ' . print_r($item, true));
                $text = $text . $item['qty'] . "\t" . $item['name'] . "\t" . wc_format_decimal($item['line_subtotal'] + $item['line_subtotal_tax'], 2) . "\n";
            }
        }
        $this->log('order_description() text = ' . $text);
        return $text;
    }


    function manually_capture_payment($order) 
    {
        if (get_post_meta($order->id, '_payment_method', true) != $this->id ) {
            return;
        }
        if ($order->get_transaction_id() != '') {
            // Already completed
            return;
        }
        $mcash_tid = get_post_meta($order->id, 'mcash_tid', true);
        
        if ($this->mcash_client->capture_payment($mcash_tid) ) {
            $order->add_order_note(__('mCASH manual capture completed', 'mcash-woocommerce-gateway'));
            //$order->payment_complete( $mcash_tid ); //This seems to cause an infinity loop
            add_post_meta($order->id, '_transaction_id', $mcash_tid, true);
            add_post_meta($order->id, '_paid_date', current_time('mysql'), true);
            return true;
        } 
        $order->add_order_note(__('mCASH manual capture failed', 'mcash-woocommerce-gateway'));
        $order->update_status('failed', __('mCASH manual capture failed', 'mcash-woocommerce-gateway'));
        return false;
    }

    
    // Validate fields
    public function validate_fields() 
    {
        return true;
    }


    // Check if we are forcing SSL on checkout pages
    // Custom function not required by the Gateway
    public function do_ssl_check() 
    {
        if($this->enabled == "yes" ) {
            if(get_option('woocommerce_force_ssl_checkout') == "no" ) {
                echo "<div class=\"error\"><p>". sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) ."</p></div>";   
            }
        }       
    }

    
    /*
    Process a refund if supported
    @param  int $order_id
    @param  float $amount
    @param  string $reason
    @return  bool|wp_error True or false based on success, or a WP_Error object
    */

    public function process_refund( $order_id, $amount = null, $reason = '' ) 
    {
        $order = wc_get_order($order_id);
        if (! $order ) {
            return false;
        }
        $mcash_tid = get_post_meta($order->id, 'mcash_tid', true);
        $mcash_refund_id = get_post_meta($order_id, 'mcash_refund_counter', true);
        if ($mcash_refund_id == '' ) {
            $mcash_refund_id = 0;
        }
        $mcash_refund_id++;
        update_post_meta($order_id, 'mcash_refund_counter', $mcash_refund_id);
        $result = $this->mcash_client->refund_payment($mcash_tid, $mcash_refund_id, $amount, $reason);
        if($result['status'] == 204) {
            return true;
        }

        if($result['data']->error_description) {
            return new WP_Error( 'mcash_woocommerce', $result['data']->error_description);
        }
        
        return false;
    }


    function startsWith($haystack, $needle) 
    {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }
    

    function get_payment_outcome($uri) 
    {
        $this->log('get_payment_outcome() uri = ' . $uri);
        if (!$this->testmode && ! $this->startsWith($uri, $this->mcash_server . '/')) {
            $this->log('get_payment_outcome() uri = ' . $uri . ' does not start with' . $this->mcash_server . '/');
            return false;
        }
        
        $parts = explode("/", $uri);
        $mcash_tid = $parts[count($parts)-3];
        $result = $this->mcash_client->payment_outcome($mcash_tid);
        
        
        if($result['status'] == 200 ) {
            $outcome = $result['data'];
            $order_id = $outcome->pos_tid;
            $order = wc_get_order($order_id);
            $order_payment_method = get_post_meta($order->id, '_payment_method', true);
            if (!(($order_payment_method == 'mcash') || ($order_payment_method == 'mcash_express')) ) {
                return false;
            }
            $mcash_tid = get_post_meta($order->id, 'mcash_tid', true);
            update_post_meta($order_id, 'payment_status', $outcome->status);
            if ( ($order_payment_method == 'mcash_express') && ($outcome->status == 'auth' ) ){
                if (!$outcome->permissions->user_info->shipping_address ){
                    $maxTries = 5;
                    for ($try=1; $try<=$maxTries; $try++) {
                        sleep(1);
                        $this->log('no scope in outcome, trying again ..');
                        $result = $this->mcash_client->payment_outcome($mcash_tid);
                        $outcome = $result['data'];
                        if ($outcome->permissions->user_info->shipping_address) {
                            break;
                        }
                    }
                    if (!$outcome->permissions->user_info->shipping_address ){
                        $this->log('outcome not updated with scopes yet. Respond with 5xx and let the callback retry.');
                        return false;
                    }
                }
                $this->log('get_payment_outcome() outcome = ' . print_r($outcome, true));
                $scope_data = $outcome->permissions->user_info->email;
                update_post_meta($order_id, '_billing_email',   $outcome->permissions->user_info->email);
                update_post_meta($order_id, '_billing_phone',   $outcome->permissions->user_info->phone_number);
                $shipping_full_name = $outcome->permissions->user_info->shipping_address->name;
                $pieces = explode(" ", $shipping_full_name);
                if( count($pieces) === 2 ) {
                    update_post_meta($order_id, '_shipping_first_name',  $pieces[0]);
                    update_post_meta($order_id, '_shipping_last_name',   $pieces[1]);
                }else{
                    update_post_meta($order_id, '_shipping_first_name', '');
                    update_post_meta($order_id, '_shipping_last_name',   $shipping_full_name);
                    }
                update_post_meta($order_id, '_shipping_full_name',   $shipping_full_name);
                update_post_meta($order_id, '_shipping_address_1',  $outcome->permissions->user_info->shipping_address->street_address);
                update_post_meta($order_id, '_shipping_city',       $outcome->permissions->user_info->shipping_address->locality);
                update_post_meta($order_id, '_shipping_postcode',   $outcome->permissions->user_info->shipping_address->postal_code);
                update_post_meta($order_id, '_shipping_country',    $outcome->permissions->user_info->shipping_address->country);
            }
            
            if ($outcome->status == 'auth' ) {                
                $order->update_status('processing', __('mCASH payment status : auth ', 'mcash-woocommerce-gateway'));
                if ($this->autocapture == 'yes' ) {
                    if ($this->mcash_client->capture_payment($mcash_tid) ) {
                        $order->add_order_note(__('mCASH automatic capture completed', 'mcash-woocommerce-gateway'));
                        $order->payment_complete($mcash_tid); // After we do this, we cannot do $order->add_order_note()
                        return true;
                    } 
                    $order->add_order_note(__('mCASH automatic capture failed', 'mcash-woocommerce-gateway'));
                    $order->update_status('failed', __('mCASH capture failed', 'mcash-woocommerce-gateway'));
                    return true;
                }
            }
            return true;
        }
        return false;
    }
    

    function payment_request($order_id, $required_scope=null)
    {
        $order = wc_get_order($order_id);
        $result = $this->mcash_client->payment_request(
            html_entity_decode($this->get_return_url($order)),
            html_entity_decode($order->get_cancel_order_url()),
            $order->order_total,
            get_woocommerce_currency(),
            $this->order_description($order),
            strval($order_id),
            $this->id,
            add_query_arg('wc-api', 'mcash_woocommerce', home_url('/')), //mcash_woocommerce_callback()
            $required_scope
        );
        if (!$result ) {
            return new WP_Error( 'mcash_woocommerce', __('We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.'));
        }
        update_post_meta($order->id, 'mcash_tid', $result->id);
        return $result;
    }


    public function log( $message ) 
    {
        if ($this->logging ) {
            if (empty( $this->log ) ) {
                $this->log = new WC_Logger();
            }
            $this->log->add($this->id, $message);
        }
    }

} // End of Mcash_Woocommerce
