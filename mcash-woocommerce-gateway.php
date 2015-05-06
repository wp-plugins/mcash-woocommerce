<?php
/*
  Plugin Name: mCASH - WooCommerce Gateway
  Plugin URI: http://www.mcash.no
  Description: Extends WooCommerce by Adding mCASH Gateway.
  Version: 0.2
  Author: mCASH AS
  License: The MIT License (MIT)
*/

add_action('init', 'mcash_woocommerce_real_init', 0);
function mcash_woocommerce_real_init() {
    $domain = 'mcash-woocommerce-gateway';
    load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

// Include our Gateway Class and register Payment Gateway with WooCommerce
add_action('plugins_loaded', 'mcash_woocommerce_init', 0);
function mcash_woocommerce_init() 
{
    // If the parent WC_Payment_Gateway class doesn't exist
    // it means WooCommerce is not installed on the site
    // so do nothing
    if (! class_exists('WC_Payment_Gateway') ) { return; 
    }
    
    // If we made it this far, then include our Gateway Class
    include_once  'classes/mcash-woocommerce.php' ;
    
    // Now that we have successfully included our class,
    // Lets add it too WooCommerce
    add_filter('woocommerce_payment_gateways', 'add_mcash_woocommerce_gateway');
    function add_mcash_woocommerce_gateway( $methods ) 
    {
        $methods[] = 'Mcash_Woocommerce';
        return $methods;
    }
        
    add_action('woocommerce_order_actions', 'mcash_woocommerce_order_actions');
    function mcash_woocommerce_order_actions($actions) 
    {
        $actions['mcash_capture'] = "Manually capture mCASH payment";
        return $actions;
    }
    
    add_action('woocommerce_order_action_mcash_capture', 'mcash_manually_capture_payment');
    function mcash_manually_capture_payment($order) 
    {
        $payment_gateway = new Mcash_Woocommerce();
        $payment_gateway->manually_capture_payment($order);
    }


}
 
// Add custom action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'mcash_woocommerce_action_links');
function mcash_woocommerce_action_links( $links ) 
{
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">' . __('Settings', 'mcash-woocommerce-gateway') . '</a>',
    );
 
    // Merge our new link with the default ones
    return array_merge($plugin_links, $links);
}

