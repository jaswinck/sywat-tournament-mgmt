<?php
/**
 * Plugin Name: SYWAT Archery Manager
 * Plugin URI:  https://ccrstaffshooters.com
 * Description: Unified portal for tournament management, check-in desk, score entry, season tracking, and TFAA sync.
 * Version:     5.0.0
 * Author:      SYWAT Archery
 * Text Domain: sywat-archery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define Plugin Constants
define( 'SYWAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYWAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SYWAT_PLUGIN_VERSION', '5.0.0' );

// Load Central Roles & Permissions Management Sub-Module First
require_once SYWAT_PLUGIN_DIR . 'includes/core/class-sywat-permissions.php';

// Trigger automatic role registration upon plugin activation
register_activation_hook( __FILE__, 'sywat_archery_activate' );
function sywat_archery_activate() {
    if ( function_exists( 'sywat_register_custom_roles' ) ) {
        sywat_register_custom_roles();
    }
}

// Enqueue standard jQuery and modular global styles
add_action( 'wp_enqueue_scripts', 'sywat_archery_enqueue_assets' );
function sywat_archery_enqueue_assets() {
    wp_enqueue_script( 'jquery' );

    $base_css_url = SYWAT_PLUGIN_URL . 'assets/css/';

    // Enqueue Modular Admin & Operations Stylesheets
    wp_enqueue_style( 'sywat-core',    $base_css_url . 'sywat-core.css',    array(), SYWAT_PLUGIN_VERSION );
    wp_enqueue_style( 'sywat-buttons', $base_css_url . 'sywat-buttons.css', array( 'sywat-core' ), SYWAT_PLUGIN_VERSION );
    wp_enqueue_style( 'sywat-tables',  $base_css_url . 'sywat-tables.css',  array( 'sywat-core' ), SYWAT_PLUGIN_VERSION );
    wp_enqueue_style( 'sywat-toolbar', $base_css_url . 'sywat-toolbar.css', array( 'sywat-core' ), SYWAT_PLUGIN_VERSION );
    wp_enqueue_style( 'sywat-modals',  $base_css_url . 'sywat-modals.css',  array( 'sywat-core' ), SYWAT_PLUGIN_VERSION );
    wp_enqueue_style( 'sywat-public',  $base_css_url . 'sywat-public.css',  array( 'sywat-core' ), SYWAT_PLUGIN_VERSION );
}

// Load TFAA Authentication Engine Sub-Modules
require_once SYWAT_PLUGIN_DIR . 'includes/auth/sywat-auth-actions.php';
require_once SYWAT_PLUGIN_DIR . 'includes/auth/sywat-auth-views.php';

// Load Core Tournament, Member & Multi-Shop Modules
require_once SYWAT_PLUGIN_DIR . 'includes/tournaments/class-sywat-tournament-config.php'; // Tournament Config, Seasons & Volunteer Hub
require_once SYWAT_PLUGIN_DIR . 'includes/members/class-sywat-members.php';               // Member Management Sub-System ([sywat_members])
require_once SYWAT_PLUGIN_DIR . 'includes/registration/class-sywat-registration.php';      // Intake & Phone Registration Engine
require_once SYWAT_PLUGIN_DIR . 'includes/roster/class-sywat-roster.php';                  // Roster Administration & Line Purging Engine
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-accounting.php';                     // Financial Split Calculations & Invoicing
require_once SYWAT_PLUGIN_DIR . 'includes/shops/class-sywat-shops.php';                     // Multi-Tenant Shop Management Engine

// Load Front-End Operations Apps
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/class-sywat-checkin.php';      // Check-In Desk App ([sywat_desk])
require_once SYWAT_PLUGIN_DIR . 'includes/scores/class-sywat-scores.php';         // Batch Score Entry App ([sywat_scores])
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-tfaa-sync.php';            // TFAA Membership Verification & Sync
require_once SYWAT_PLUGIN_DIR . 'includes/results/class-sywat-results.php';      // Public Standings & Results ([sywat_results])
