<?php
/**
 * Module: Public Season Schedule Loader Shell
 * Path: includes/schedule/class-sywat-schedule.php
 * Description: Main controller binding the data layer, formatting helpers, public shortcode [sywat_schedule], and client-side JS engine.
 * Compliance: Zero inline HTML output at include time, strict asset scoping, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Include Sub-Module Architecture Files
require_once plugin_dir_path( __FILE__ ) . 'schedule-data.php';
require_once plugin_dir_path( __FILE__ ) . 'schedule-helpers.php';

// 2. Register & Scoped Enqueue of Client JS Engine
if ( ! function_exists( 'sywat_enqueue_schedule_scripts' ) ) {
    function sywat_enqueue_schedule_scripts() {
        if ( ! function_exists( 'sywat_should_load_module_assets' ) || ! sywat_should_load_module_assets() ) {
            return;
        }
        
        $js_url = defined( 'SYWAT_PLUGIN_URL' ) ? SYWAT_PLUGIN_URL . 'assets/js/sywat-schedule.js' : '';
        $v      = defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '1.0.0';
        
        if ( ! empty( $js_url ) ) {
            wp_enqueue_script( 'sywat-schedule-js', $js_url, array(), $v, true );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'sywat_enqueue_schedule_scripts' );

// 3. Shortcode Registration & Handler
if ( shortcode_exists( 'sywat_schedule' ) ) {
    remove_shortcode( 'sywat_schedule' );
}
add_shortcode( 'sywat_schedule', 'render_sywat_public_schedule' );

if ( ! function_exists( 'render_sywat_public_schedule' ) ) {
    function render_sywat_public_schedule() {
        // Enforce strict no-cache policy for the live schedule board
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();

        // Check for specific season request (defaults to active season in data layer if empty)
        $requested_season = isset( $_REQUEST['season'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['season'] ) ) : '';

        // Execute Data Pipeline
        $raw_data  = sywat_schedule_get_raw_data( $requested_season );
        $view_data = sywat_schedule_format_view_payload( $raw_data );

        // Render View
        ob_start();
        ?>
        <div class="sywat-wrap">
            <?php 
                $view_path = plugin_dir_path( __FILE__ ) . 'views/view-schedule-public.php';
                if ( file_exists( $view_path ) ) {
                    include $view_path;
                } else {
                    echo '<div class="sywat-alert sywat-alert-danger">Schedule view template not found.</div>';
                }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
