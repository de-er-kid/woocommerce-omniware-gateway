<?php
/*
Plugin Name: WooCommerce OmniWare Gateway
Plugin URI: https://github.com/de-er-kid/woocommerce-omniware-gateway
Description: OmniWare Payment Gateway for WooCommerce
Version: 1.0.0
Author: Sinan
Author URI: https://github.com/de-er-kid
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'init_omniware_gateway');

function init_omniware_gateway()
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-omniware-gateway.php';

    add_filter('woocommerce_payment_gateways', 'add_omniware_gateway');
}

function add_omniware_gateway($gateways)
{
    $gateways[] = 'WC_Omniware_Gateway';
    return $gateways;
}
