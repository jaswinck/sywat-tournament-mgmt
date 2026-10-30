<?php
/**
 * Plugin Name: SYWAT Archery Manager
 * Plugin URI:  https://ccrstaffshooters.com
 * Description: Unified portal for tournament management, check-in desk, score entry, season tracking, and TFAA sync.
 * Version:     2.4.0
 * Author:      SYWAT Archery
 * Text Domain: sywat-archery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define Plugin Constants
define( 'SYWAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYWAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SYWAT_PLUGIN_VERSION', '2.4.0' );

// Automatically register custom roles upon plugin activation
register_activation_hook( __FILE__, 'sywat_archery_activate' );
function sywat_archery_activate() {
    add_role( 'staff_shooter', 'Staff Shooter', array( 'read' => true ) );
    add_role( 'management', 'Management', array( 'read' => true ) );

    // Custom Multi-Shop Roles
    add_role( 'sywat_shop_manager', 'SYWAT Shop Manager', array( 'read' => true ) );
    add_role( 'sywat_shop_user', 'SYWAT Shop User', array( 'read' => true ) );

    // Custom TFAA Administrative Roles
    add_role( 'tfaa_webmaster', 'TFAA Webmaster', array( 'read' => true, 'upload_files' => true, 'edit_posts' => true ) );
    add_role( 'tfaa_admin', 'TFAA Admin', array( 'read' => true, 'upload_files' => true, 'edit_posts' => true ) );
}

// Enqueue standard jQuery and global styles
add_action( 'wp_enqueue_scripts', 'sywat_archery_enqueue_assets' );
function sywat_archery_enqueue_assets() {
    wp_enqueue_script( 'jquery' );

    // Enqueue Master Admin Stylesheet
    wp_enqueue_style(
        'sywat-admin-css',
        SYWAT_PLUGIN_URL . 'assets/css/sywat-admin.css',
        array(),
        SYWAT_PLUGIN_VERSION
    );
}

// Load Core Tournament & Multi-Shop Modules
require_once SYWAT_PLUGIN_DIR . 'includes/tournaments/class-sywat-tournament-config.php'; // Tournament Config, Seasons & Volunteer Hub
require_once SYWAT_PLUGIN_DIR . 'includes/registration/class-sywat-registration.php';  // Intake & Phone Registration Engine
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-roster.php';               // Roster Administration & Line Purging
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-accounting.php';           // Financial Split Calculations & Invoicing
require_once SYWAT_PLUGIN_DIR . 'includes/shops/class-sywat-shops.php';           // Multi-Tenant Shop Management Engine

// Load Front-End Operations Apps
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/class-sywat-checkin.php';      // Check-In Desk App ([sywat_desk])
require_once SYWAT_PLUGIN_DIR . 'includes/scores/class-sywat-scores.php';         // Batch Score Entry App ([sywat_scores])
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-tfaa-sync.php';            // TFAA Membership Verification & Sync
require_once SYWAT_PLUGIN_DIR . 'includes/results/class-sywat-results.php';      // Public Standings & Results ([sywat_results])
