<?php
/**
 * Module: Tournament Configuration, Master Portal & Volunteer Hub Loader Shell
 * Path: includes/tournaments/class-sywat-tournament-config.php
 * Description: Main loader binding data layer, helpers, season CRUD, action handlers, portal views, and volunteer hub.
 * Author: SYWAT Archery
 * Version: 2.8.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Shortcode Bindings
if ( shortcode_exists( 'sywat_admin' ) )              remove_shortcode( 'sywat_admin' );
if ( shortcode_exists( 'sywat_manage' ) )             remove_shortcode( 'sywat_manage' );
if ( shortcode_exists( 'sywat_manage_tournaments' ) ) remove_shortcode( 'sywat_manage_tournaments' );
if ( shortcode_exists( 'sywat_volunteer_hub' ) )      remove_shortcode( 'sywat_volunteer_hub' );

add_shortcode( 'sywat_admin',              'render_sywat_master_portal' );
add_shortcode( 'sywat_manage',             'render_sywat_master_portal' );
add_shortcode( 'sywat_manage_tournaments', 'render_sywat_master_portal' );
add_shortcode( 'sywat_volunteer_hub',      'render_sywat_volunteer_hub' );

// Enqueue Module Scripts & Assets
function sywat_enqueue_tournament_scripts() {
    $js_url = plugins_url( '../../assets/js/sywat-tournaments.js', __FILE__ );
    wp_enqueue_script( 'sywat-tournaments-js', $js_url, array(), '1.0.1', true );
    
    // Localize Script to pass AJAX URL and Security Nonce to client-side JS
    wp_localize_script( 'sywat-tournaments-js', 'sywat_ajax', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'sywat_desk_action' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'sywat_enqueue_tournament_scripts' );
add_action( 'admin_enqueue_scripts', 'sywat_enqueue_tournament_scripts' );

// =====================================================================
// NEW: Early Init Interceptor for Pure JSON Responses (Bypasses Theme HTML)
// =====================================================================
add_action( 'init', 'sywat_async_tfaa_sync_interceptor' );
function sywat_async_tfaa_sync_interceptor() {
    if ( isset( $_POST['sywat_action'] ) && $_POST['sywat_action'] === 'sync_tfaa_single_json' ) {
        // Clean output buffers to ensure pure JSON response
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        if ( ! isset( $_POST['sywat_nonce'] ) || ! wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_desk_action' ) ) {
            wp_send_json_error( array( 'message' => 'Security token expired. Please refresh the page.' ) );
        }

        if ( ! function_exists( 'sywat_can_manage_tournaments' ) || ! sywat_can_manage_tournaments() ) {
            wp_send_json_error( array( 'message' => 'Access denied. Management permission required.' ) );
        }

        $target_t = isset( $_POST['tournament_name'] ) ? sanitize_text_field( $_POST['tournament_name'] ) : '';

        if ( empty( $target_t ) ) {
            wp_send_json_error( array( 'message' => 'No target tournament specified for TFAA sync.' ) );
        }

        // Dynamically locate the sync file based on the config file's location
        $tfaa_sync_file = plugin_dir_path( __FILE__ ) . '../common/sywat-tfaa-sync.php';
        if ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) && file_exists( $tfaa_sync_file ) ) {
            require_once $tfaa_sync_file;
        }

        if ( function_exists( 'sywat_sync_tfaa_event_scores' ) ) {
            $res = sywat_sync_tfaa_event_scores( $target_t );
            if ( ! empty( $res['success'] ) ) {
                wp_send_json_success( array(
                    'message' => isset( $res['message'] ) ? $res['message'] : 'Sync complete.',
                    'count'   => isset( $res['count'] ) ? intval( $res['count'] ) : 0,
                ) );
            } else {
                wp_send_json_error( array(
                    'message' => isset( $res['message'] ) ? $res['message'] : 'Sync failed for unknown reason.',
                ) );
            }
        } else {
            wp_send_json_error( array( 'message' => 'TFAA sync engine function unavailable.' ) );
        }
        // wp_send_json_success/error automatically halts WordPress, preventing HTML contamination.
    }
}
// =====================================================================

// Include Sub-Module Files
require_once plugin_dir_path( __FILE__ ) . 'tournament-data.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-seasons.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-actions.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-modals.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-views.php';
require_once plugin_dir_path( __FILE__ ) . 'volunteer-hub.php';
