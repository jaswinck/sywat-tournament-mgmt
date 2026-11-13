<?php
/**
 * Sub-Module: Archer Roster Client Scripts Enqueuer
 * Path: includes/roster/roster-scripts.php
 * Description: Enqueues the Roster Management client controller and shared Shooting Styles rule engine, localizing line-scoped family groups and database-configured age divisions.
 * Author: SYWAT Archery
 * Version: 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'sywat_render_roster_scripts' ) ) {
    function sywat_render_roster_scripts( $existing_families_by_line = array() ) {
        // Ensure shared Shooting Styles & Division Rule controller is enqueued first
        if ( function_exists( 'sywat_enqueue_shooting_styles_scripts' ) ) {
            sywat_enqueue_shooting_styles_scripts();
        }

        // Build age division metadata payload (include retired records so existing assignments resolve accurately)
        $divisions_payload = array();
        if ( function_exists( 'sywat_get_division_records' ) ) {
            $div_records = sywat_get_division_records( true );
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
                        'status'    => isset( $rec['status'] ) ? $rec['status'] : 'active',
                        'aliases'   => ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ? array_values( $rec['aliases'] ) : array(),
                    );
                }
            }
        }

        // Determine script URL relative to plugin root
        $js_url = defined( 'SYWAT_PLUGIN_URL' )
            ? SYWAT_PLUGIN_URL . 'assets/js/sywat-roster.js'
            : plugins_url( '../../assets/js/sywat-roster.js', __FILE__ );

        $version = defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '7.0.0';
        $deps    = wp_script_is( 'sywat-shooting-styles-js', 'registered' ) ? array( 'sywat-shooting-styles-js' ) : array();

        // Enqueue external JavaScript file
        wp_enqueue_script( 'sywat-roster-js', $js_url, $deps, $version, true );

        // Localize line-scoped family group assignments & age divisions to window.sywatRosterData
        wp_localize_script( 'sywat-roster-js', 'sywatRosterData', array(
            'existingFamiliesByLine' => is_array( $existing_families_by_line ) ? $existing_families_by_line : array(),
            'divisions'              => $divisions_payload,
        ) );
    }
}
