<?php
/**
 * Plugin Name: SYWAT Archery Manager
 * Plugin URI:  https://ccrstaffshooters.com
 * Description: Unified portal for tournament management, check-in desk, score entry, season tracking, and historical results lookup.
 * Version:     6.8.1
 * Author:      SYWAT Archery
 * Text Domain: sywat-archery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define Plugin Constants
define( 'SYWAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYWAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SYWAT_PLUGIN_VERSION', '6.8.1' );

// Load Central Roles & Permissions Management Sub-Module First
require_once SYWAT_PLUGIN_DIR . 'includes/core/class-sywat-permissions.php';

// Trigger automatic role registration upon plugin activation
register_activation_hook( __FILE__, 'sywat_archery_activate' );
function sywat_archery_activate() {
    if ( function_exists( 'sywat_register_custom_roles' ) ) {
        sywat_register_custom_roles();
    }
}

// Front-end integration layer: page detection, conditional asset loading,
// cache exclusion, optimizer opt-outs, builder placeholders, display settings.
// Assets now load only on pages that contain a SYWAT shortcode (never in wp-admin
// or page-builder editors). jQuery is no longer required by any plugin script.
require_once SYWAT_PLUGIN_DIR . 'includes/core/class-sywat-frontend.php';

// Load Shared Core Engines & Utilities
require_once SYWAT_PLUGIN_DIR . 'includes/shooting-styles/class-sywat-shooting-styles.php'; // Shooting Styles & Divisions Configuration Engine ([sywat_shooting_styles])
require_once SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php';

// Load TFAA Authentication Engine Sub-Modules
require_once SYWAT_PLUGIN_DIR . 'includes/auth/sywat-auth-actions.php';
require_once SYWAT_PLUGIN_DIR . 'includes/auth/sywat-auth-views.php';

// Load Core Tournament, Member & Multi-Shop Modules
require_once SYWAT_PLUGIN_DIR . 'includes/tournaments/class-sywat-tournament-config.php'; // Tournament Config, Seasons & Volunteer Hub
require_once SYWAT_PLUGIN_DIR . 'includes/members/class-sywat-members.php';               // Member Management Sub-System ([sywat_members])
require_once SYWAT_PLUGIN_DIR . 'includes/registration/class-sywat-registration.php';      // Intake & Phone Registration Engine
require_once SYWAT_PLUGIN_DIR . 'includes/roster/class-sywat-roster.php';                  // Roster Administration & Line Purging Engine
require_once SYWAT_PLUGIN_DIR . 'includes/waitlist/class-sywat-waitlist.php';              // Waitlist Queue Management & Family Cap Reconciliation Engine
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-accounting.php';                     // Financial Split Calculations & Invoicing
require_once SYWAT_PLUGIN_DIR . 'includes/shops/class-sywat-shops.php';                     // Multi-Tenant Shop Management Engine

// Load Front-End Operations Apps
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/class-sywat-checkin.php';      // Check-In Desk App ([sywat_desk])
require_once SYWAT_PLUGIN_DIR . 'includes/scores/class-sywat-scores.php';         // Batch Score Entry App ([sywat_scores])
require_once SYWAT_PLUGIN_DIR . 'includes/results/class-sywat-results.php';      // Public Standings & Results ([sywat_results])
