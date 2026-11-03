<?php
/**
 * Sub-Module: Public Archery Shops & Clubs Directory Controller
 * Path: includes/shops/shops-public-views.php
 * Author: SYWAT Archery / TFAA
 * Version: 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render Public Read-Only Directory Layout
 */
if ( ! function_exists( 'sywat_render_public_shops_directory' ) ) {
    function sywat_render_public_shops_directory() {
        wp_enqueue_script( 'sywat-shops-js', plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/sywat-shops.js', array(), '1.0.0', true );

        ob_start();
        include __DIR__ . '/views/view-shop-public.php';
        return ob_get_clean();
    }
}

/**
 * Shortcode Handler: [sywat_public_shops]
 */
if ( ! function_exists( 'sywat_public_shops_shortcode' ) ) {
    function sywat_public_shops_shortcode() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        return sywat_render_public_shops_directory();
    }
    add_shortcode( 'sywat_public_shops', 'sywat_public_shops_shortcode' );
}
