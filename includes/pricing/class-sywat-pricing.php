<?php
/**
 * Module: Pricing Profiles, Round Formats & Promoter Controller Shell
 * Path: includes/pricing/class-sywat-pricing.php
 * Description: Entry point binding pricing data, helpers, actions, and views, registering the [sywat_pricing] shortcode with the central frontend integration layer, and enqueueing localized pricing assets.
 * Compliance: Controller shell — zero HTML output at include time, no jQuery dependency, SYWAT_PLUGIN_URL & SYWAT_PLUGIN_VERSION asset loading, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include Sub-Module Triad Files (Data -> Helpers -> Actions -> Views)
$sywat_pricing_dir = plugin_dir_path( __FILE__ );

require_once $sywat_pricing_dir . 'pricing-data.php';
require_once $sywat_pricing_dir . 'pricing-helpers.php';
require_once $sywat_pricing_dir . 'pricing-actions.php';
require_once $sywat_pricing_dir . 'pricing-views.php';

/**
 * Register [sywat_pricing] with the central frontend integration layer so pages using
 * this shortcode receive plugin CSS/JS, .sywat-scope wrapping, Divi Visual Builder
 * placeholders, and Cache-Control: no-cache headers.
 *
 * @param array $tags Existing registered SYWAT shortcode tags.
 * @return array      Updated shortcode tags array.
 */
if ( ! function_exists( 'sywat_register_pricing_shortcode_tag' ) ) {
    function sywat_register_pricing_shortcode_tag( $tags ) {
        if ( ! is_array( $tags ) ) {
            $tags = array();
        }
        if ( ! in_array( 'sywat_pricing', $tags, true ) ) {
            $tags[] = 'sywat_pricing';
        }
        return $tags;
    }
}
add_filter( 'sywat_shortcode_tags', 'sywat_register_pricing_shortcode_tag' );

// Register [sywat_pricing] Shortcode
if ( shortcode_exists( 'sywat_pricing' ) ) {
    remove_shortcode( 'sywat_pricing' );
}
add_shortcode( 'sywat_pricing', 'render_sywat_pricing_portal' );

/**
 * Enqueue the Pricing, Round Formats & Promoter JavaScript engine on SYWAT front-end pages
 * and localize configured pricing profiles, tournament round mappings, and promo rules.
 */
if ( ! function_exists( 'sywat_enqueue_pricing_scripts' ) ) {
    function sywat_enqueue_pricing_scripts() {
        static $enqueued = false;

        if ( $enqueued ) {
            return;
        }

        if ( ! function_exists( 'sywat_should_load_module_assets' ) || ! sywat_should_load_module_assets() ) {
            return;
        }

        $ver = defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '1.0.0';
        $url = defined( 'SYWAT_PLUGIN_URL' ) ? SYWAT_PLUGIN_URL . 'assets/js/sywat-pricing.js' : '';

        if ( $url === '' ) {
            return;
        }

        wp_enqueue_script(
            'sywat-pricing-js',
            $url,
            array(),
            $ver,
            true
        );

        $payload = function_exists( 'sywat_get_pricing_js_payload' )
            ? sywat_get_pricing_js_payload()
            : array();

        wp_localize_script( 'sywat-pricing-js', 'sywatPricingConfig', $payload );

        $enqueued = true;
    }
}
add_action( 'wp_enqueue_scripts', 'sywat_enqueue_pricing_scripts' );
