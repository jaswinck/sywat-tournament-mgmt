<?php
/**
 * Module: Tournament Configuration, Master Portal & Volunteer Hub Loader Shell
 * Path: includes/tournaments/class-sywat-tournament-config.php
 * Description: Main loader binding data layer, helpers, season CRUD, action handlers, portal views, and volunteer hub.
 * Author: SYWAT Archery
 * Version: 2.8.2
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
    if ( ! function_exists( 'sywat_should_load_module_assets' ) || ! sywat_should_load_module_assets() ) {
        return;
    }
    $js_url = SYWAT_PLUGIN_URL . 'assets/js/sywat-tournaments.js';
    wp_enqueue_script( 'sywat-tournaments-js', $js_url, array(), '1.0.1', true );
    
    // Localize Script to pass AJAX URL and Security Nonce to client-side JS
    wp_localize_script( 'sywat-tournaments-js', 'sywat_ajax', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'sywat_desk_action' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'sywat_enqueue_tournament_scripts' );

// Include Sub-Module Files
require_once plugin_dir_path( __FILE__ ) . 'tournament-data.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-seasons.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-actions.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-modals.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-views.php';
require_once plugin_dir_path( __FILE__ ) . 'volunteer-hub.php';
