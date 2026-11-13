<?php
/**
 * Sub-Module: Registration Engine Client Scripts Enqueuer
 * Path: includes/registration/registration-scripts.php
 * Description: Enqueues registration portal client scripts and localizes cached historical shooter data, phone numbers, and database-configured age divisions.
 * Author: SYWAT Archery
 * Version: 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enqueues registration portal client scripts and localizes cached historical shooter data, phone numbers, and active age divisions.
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

        // Build active age division metadata payload from database configuration
        $divisions_payload = array();
        if ( function_exists( 'sywat_get_division_records' ) ) {
            $div_records = sywat_get_division_records( false );
            if ( is_array( $div_records ) ) {
                foreach ( $div_records as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    $rate_tier = ( isset( $rec['rate_tier'] ) && $rec['rate_tier'] === 'Youth' ) ? 'Youth' : 'Adult';
                    $distance  = ( isset( $rec['distance'] ) && $rec['distance'] === 'Cub' ) ? 'Cub' : '20yd';
                    $divisions_payload[] = array(
                        'slug'      => isset( $rec['slug'] ) ? $rec['slug'] : sanitize_title( $rec['label'] ),
                        'code'      => isset( $rec['code'] ) ? $rec['code'] : '',
                        'label'     => $rec['label'],
                        'distance'  => $distance,
                        'rate_tier' => $rate_tier,
                        'fee'       => ( $rate_tier === 'Youth' ) ? 25 : 30,
                        'aliases'   => ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ? array_values( $rec['aliases'] ) : array(),
                    );
                }
            }
        }

        // Determine script URL relative to plugin root
        $js_url = defined( 'SYWAT_PLUGIN_URL' )
            ? SYWAT_PLUGIN_URL . 'assets/js/sywat-registration.js'
            : plugins_url( '../../assets/js/sywat-registration.js', __FILE__ );

        $version = defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '7.0.0';

        // Enqueue external JavaScript file
        wp_enqueue_script( 'sywat-registration-js', $js_url, array(), $version, true );

        // Localize database caches and division rules to JS window object safely
        wp_localize_script( 'sywat-registration-js', 'sywatRegData', array(
            'tfaaShooters' => array_values( $js_tfaa_data ),
            'phoneCache'   => is_array( $phone_cache ) ? $phone_cache : array(),
            'divisions'    => $divisions_payload,
        ) );
    }
}
