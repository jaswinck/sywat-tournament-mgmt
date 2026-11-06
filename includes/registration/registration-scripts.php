<?php
/**
 * Sub-Module: Registration Engine Client Scripts Enqueuer
 * Path: includes/registration/registration-scripts.php
 * Author: SYWAT Archery
 * Version: 6.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enqueues registration portal client scripts and localizes cached historical shooter data and phone numbers.
 *
 * @return void
 */
if ( ! function_exists( 'sywat_render_registration_scripts' ) ) {
    function sywat_render_registration_scripts() {
        $phone_cache = get_option( 'sywat_shooter_phones_cache', array() );

        // Fetch 18-month rolling window historical shooters
        $js_tfaa_data = function_exists( 'sywat_get_historical_shooters' )
            ? sywat_get_historical_shooters()
            : array();

        // Determine script URL relative to plugin root
        $js_url = defined( 'SYWAT_PLUGIN_URL' ) 
            ? SYWAT_PLUGIN_URL . 'assets/js/sywat-registration.js' 
            : plugins_url( '../../assets/js/sywat-registration.js', __FILE__ );

        $version = defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '6.1.0';

        // Enqueue external JavaScript file
        wp_enqueue_script( 'sywat-registration-js', $js_url, array(), $version, true );

        // Localize database caches to JS window object safely
        wp_localize_script( 'sywat-registration-js', 'sywatRegData', array(
            'tfaaShooters' => array_values( $js_tfaa_data ),
            'phoneCache'   => is_array( $phone_cache ) ? $phone_cache : array(),
        ) );
    }
}
