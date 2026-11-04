<?php
/**
 * Module: Tournament Configuration, Master Portal & Volunteer Hub Loader Shell
 * Path: includes/tournaments/class-sywat-tournament-config.php
 * Description: Main loader binding helpers, season CRUD, action handlers, portal views, and volunteer hub.
 * Author: SYWAT Archery
 * Version: 2.6.0
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
    wp_enqueue_script( 'sywat-tournaments-js', $js_url, array(), '1.0.0', true );
}
add_action( 'wp_enqueue_scripts', 'sywat_enqueue_tournament_scripts' );

// Include Sub-Module Files
require_once plugin_dir_path( __FILE__ ) . 'tournament-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-seasons.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-actions.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-modals.php';
require_once plugin_dir_path( __FILE__ ) . 'tournament-views.php';
require_once plugin_dir_path( __FILE__ ) . 'volunteer-hub.php';
