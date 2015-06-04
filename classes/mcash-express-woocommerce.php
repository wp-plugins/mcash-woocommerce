<?php
/*
  Description: Extends WooCommerce by Adding mCASH Gateway.
  Author: mCASH AS
  License: The MIT License (MIT)
*/
    
if (! defined('ABSPATH') ) {
    exit; // Exit if accessed directly
}

global $mcash_express_settings;
$mcash_express_settings = get_option( 'woocommerce_mcash_express_settings' );

require_once  'mcash-woocommerce.php' ;
class Mcash_Express_Woocommerce extends Mcash_Woocommerce
{
    
    // Setup our Gateway's id, description and other values
    function __construct() 
    {
        $this->id = "mcash_express";
        $this->method_title = __("mCASH Express", 'mcash-woocommerce');
        $this->method_description = __("mCASH Express Payment Gateway Plug-in for WooCommerce. Please notice that this needs free shipping.", 'mcash-woocommerce');
        $this->title = __("mCASH Express", 'mcash-woocommerce');
        $this->init();
        
        add_action('wp_enqueue_scripts', array($this, 'mcash_express_woocommerce_init_styles'));
        add_action('woocommerce_api_' . strtolower(get_class()), array( $this, 'mcash_express_checkout' ));
        
        if ( $this->enabled && ($this->get_option('show_on_cart', 'bottom' ) === 'bottom')) { 
            add_action('woocommerce_after_cart', array( $this, 'mcash_express_button'), 20 );
        }
        
        if ( $this->enabled && ( $this->get_option('show_on_checkout', 'no') === 'yes')) { 
            add_action('woocommerce_before_checkout_form', array( $this, 'mcash_express_button'), 6 );
        }

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
        add_action('woocommerce_review_order_before_submit', array( $this, 'hide_mcash_express_on_checkout_page' ));
        }
    
    public function init_form_fields() 
    {
        global $mcash_settings;
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
            'copy_values_from_mcash_gateway' => array(
                'title'     => __('Copy values from mCASH plugin settings', 'mcash-woocommerce-gateway'),
                'label'     => __('Copy values from mCASH plugin settings', 'mcash-woocommerce-gateway'),
                'type'      => 'checkbox',
                'description'  => __('If set to "yes", merchant id, merchant user id, key pair will be copied from mCASH plugin settings.', 'mcash-woocommerce-gateway'),
                'default'   => 'yes',
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
                'default'   => 'no',
            ),
            'priv_key' => array(
                'title'     => __('Private RSA key', 'mcash-woocommerce-gateway'),
                'type'      => 'textarea',
                'description'  => __('Your private RSA key. Keep it secret.', 'mcash-woocommerce-gateway'),
                'css'       => 'max-width:600px; height: 350px;',
            ),
            'pub_key' => array(
                'title'     => __('Public RSA key', 'mcash-woocommerce-gateway'),
                'type'      => 'textarea',
                'description'  => sprintf(__('Your public RSA key. Copy this to the corresponding field for your merchant user at %shttps://my.mca.sh/mssp/%s .', 'mcash-woocommerce-gateway'), '<a href="https://my.mca.sh/mssp/">', '</a>'),
                'css'       => 'max-width:600px; height: 120px;',
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
            'show_on_cart' => array(
                'title' => __( 'Cart Page', 'mcash-woocommerce-gateway'),
                'type' => 'select',
                'options' => array(
                    'top' => __( 'Display at the top of page.' , 'mcash-woocommerce-gateway'),
                    'bottom' => __( 'Display at the bottom of page.' , 'mcash-woocommerce-gateway') ),
                'default' => 'top',
				'description' => __( 'Display the express button on page' )
            ),
            'show_on_checkout' => array(
                'title' => __( 'Checkout Page', 'mcash-woocommerce-gateway'),
                'type'      => 'checkbox',
                'default' => 'yes',
				'description' => __( 'Display the express button on page' )
            ),
            'min_amount' => array(
                'title'     => __('Minimum amount', 'mcash-woocommerce-gateway'),
                'type'      => 'text',
                'default'   => '0.00',
                'description'  =>  __('Only show mCASH Express button, when cart amount is bigger then this value', 'mcash-woocommerce-gateway')
            ),
            'btn_picture' => array(
                'title' => __( 'Select picture for mCASH Express button', 'mcash-woocommerce-gateway'),
                'type' => 'select',
                'options' => array(
                    '/assets/images/btn_mcash_express_blue_3x.png'       => __( 'blue' , 'mcash-woocommerce-gateway'),
                    '/assets/images/btn_mcash_express_dark_3x.png'       => __( 'dark' , 'mcash-woocommerce-gateway'),
                    '/assets/images/btn_mcash_express_dark_green_3x.png' => __( 'dark_green' , 'mcash-woocommerce-gateway'),
                    '/assets/images/btn_mcash_express_gray_3x.png'       => __( 'grey' , 'mcash-woocommerce-gateway'),
                    '/assets/images/btn_mcash_express_green_3x.png'      => __( 'green' , 'mcash-woocommerce-gateway') ),
                'default' => '/assets/images/btn_mcash_express_blue_3x.png'
            ),
        );      
    }
    
    public function process_admin_options() 
    {
        parent::process_admin_options();
        // Load form_field settings
        $mcash_settings = get_option( 'woocommerce_mcash_settings' );
        $settings = get_option($this->plugin_id . $this->id . '_settings', null);
        if ($settings['copy_values_from_mcash_gateway'] == 'yes') {
            $settings['copy_values_from_mcash_gateway'] = 'no';
            $settings['generate_new_rsa_keys'] = 'no';
            $settings['pub_key'] = $mcash_settings['pub_key'];
            $settings['priv_key'] = $mcash_settings['priv_key'];
            $settings['mid'] = $mcash_settings['mid'];
            $settings['uid'] = $mcash_settings['uid'];
            $settings['autocapture'] = $mcash_settings['autocapture'];
            $settings['testmode'] = $mcash_settings['testmode'];
            $settings['logging'] = $mcash_settings['logging'];
            $settings['testbed_token'] = $mcash_settings['testbed_token'];
            $settings['test_server'] = $mcash_settings['test_server'];
        } elseif ($settings['generate_new_rsa_keys'] == 'yes') {
            $keyPair = $this->generate_key_pair();
            $settings['generate_new_rsa_keys'] = 'no';
            $settings['pub_key'] = $keyPair['pubKey'];
            $settings['priv_key'] = $keyPair['privKey'];
        }
        update_option($this->plugin_id . $this->id . '_settings', $settings);
        $this->log('process_admin_options() ' . $this->plugin_id . $this->id . '_settings');
    }
    
    // We have an mCASH Express button on the previus page. We dont support mCASH Expresss option checkout page
    function hide_mcash_express_on_checkout_page()
    {
        ?>
        <style>.payment_method_mcash_express {display:none !important;}</style>
        <?php
    }
    

    function mcash_express_checkout($posted = null) 
    {
        $this->log('mcash_express_checkout()');        
        if (!empty($posted) || ( isset( $_GET['action'] ) && $_GET['action'] == 'expresscheckout' && sizeof(WC()->cart->get_cart()) > 0) ) {
            if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) )
                define( 'WOOCOMMERCE_CHECKOUT', true );
            WC()->cart->calculate_totals();
            $order_id = WC()->checkout()->create_order();
            $order = wc_get_order($order_id);
            $rate = new WC_Shipping_Rate('free_shipping', __( 'Free Shipping', 'woocommerce' ), 0, array(), 'free_shipping');
            $shipping_id = $order->add_shipping( $rate );
            update_post_meta($order_id, '_payment_method',   $this->id);
            update_post_meta($order_id, '_payment_method_title',  $this->title);
            $required_scope = 'openid phone email shipping_address';
            $return = $this->payment_request($order_id, $required_scope);
            if( is_wp_error( $return ) ) {
                $this->log('mcash_express_checkout()' . $return->get_error_message());
                wp_redirect(home_url() . '/error?m=' . urlencode($return->get_error_message()));
                exit();
            }else {
                wp_redirect($return->uri);
                exit();
            }
        }
        wp_redirect(get_permalink(wc_get_page_id('cart')));
        exit();
    }


    static function mcash_express_button()
    {
        global $mcash_express_settings;
        $payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (isset( $payment_gateways['mcash_express'] ) && WC()->cart->total > (float) @$mcash_express_settings['min_amount']) {
            echo '<div id="mcash_express_button">';
            echo '<a class="mcash_express_button" href="' . add_query_arg('action', 'expresscheckout', add_query_arg('wc-api', get_class(), home_url('/'))) . '">';
            echo '<img width="194" height="44" alt="mCASH Express" src="' . WP_PLUGIN_URL . "/" . plugin_basename(dirname(dirname(__FILE__))) . @$mcash_express_settings['btn_picture'] . '">';
            echo "</a>";
            echo '</div>';
        }
    }

    
    static function mcash_express_cart_button_top()
    {
        wp_enqueue_style('mcash_express', plugins_url('/assets/css/mcash_express.css',  dirname(__FILE__)));
        global $mcash_express_settings;
        $payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (isset( $payment_gateways['mcash_express'] ) && (WC()->cart->total > (float) @$mcash_express_settings['min_amount']) && @$mcash_express_settings['show_on_cart']=='top') { 
            echo '<div id="mcash_express_button">';
            echo '<a class="mcash_express_button" href="' . add_query_arg('action', 'expresscheckout', add_query_arg('wc-api', get_class(), home_url('/'))) . '">';
            echo '<img width="194" height="44" alt="mCASH Express" src="' . WP_PLUGIN_URL . "/" . plugin_basename(dirname(dirname(__FILE__))) . @$mcash_express_settings['btn_picture'] . '">';
            echo "</a>";
            echo '</div>';
        }
    }

    
    function mcash_express_woocommerce_init_styles() 
    {
        $this->log('mcash_express_woocommerce_init_styles()');
        wp_register_script('mcash_express', plugins_url('/assets/js/mcash_express.js',  dirname(__FILE__)), array( 'jquery' ), WC_VERSION, true);
        wp_enqueue_style('mcash_express', plugins_url('/assets/css/mcash_express.css',  dirname(__FILE__)));
        wp_enqueue_script('mcash_express');
    }
    
    
} // End of Mcash_Express_Woocommerce
