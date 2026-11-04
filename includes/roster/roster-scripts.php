<?php
/**
 * Sub-Module: Archer Roster Client Scripts Enqueuer
 * Path: includes/roster/roster-scripts.php
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_roster_scripts' ) ) {
    function sywat_render_roster_scripts( $existing_families_by_line = array() ) {
        // Determine script URL relative to plugin root
        $js_url = defined( 'SYWAT_PLUGIN_URL' ) 
            ? SYWAT_PLUGIN_URL . 'assets/js/sywat-roster.js' 
            : plugins_url( '../../assets/js/sywat-roster.js', __FILE__ );

        // Enqueue external JavaScript file
        wp_enqueue_script( 'sywat-roster-js', $js_url, array(), '5.0.0', true );

        // Localize line-scoped family group assignments to window.sywatRosterData
        wp_localize_script( 'sywat-roster-js', 'sywatRosterData', array(
            'existingFamiliesByLine' => is_array( $existing_families_by_line ) ? $existing_families_by_line : array(),
        ) );
    }
}
