<?php
/**
 * Plugin Name: SYWAT Archery Manager
 * Plugin URI:  https://ccrstaffshooters.com
 * Description: Unified portal for tournament management, check-in desk, score entry, and TFAA sync.
 * Version:     2.1.0
 * Author:      SYWAT Archery
 * Text Domain: sywat-archery
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define Plugin Constants
define('SYWAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SYWAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SYWAT_PLUGIN_VERSION', '2.1.0');

// Automatically register custom roles upon plugin activation
register_activation_hook(__FILE__, 'sywat_archery_activate');
function sywat_archery_activate() {
    add_role('staff_shooter', 'Staff Shooter', array('read' => true));
    add_role('management', 'Management', array('read' => true));
    
    // Phase 2 Custom Multi-Shop Roles
    add_role('sywat_shop_manager', 'SYWAT Shop Manager', array('read' => true));
    add_role('sywat_shop_user', 'SYWAT Shop User', array('read' => true));
}

// Enqueue standard jQuery and global styles
add_action('wp_enqueue_scripts', 'sywat_archery_enqueue_assets');
function sywat_archery_enqueue_assets() {
    wp_enqueue_script('jquery');

    // Enqueue Master Admin Stylesheet
    wp_enqueue_style(
        'sywat-admin-css',
        SYWAT_PLUGIN_URL . 'assets/css/sywat-admin.css',
        array(),
        SYWAT_PLUGIN_VERSION
    );
}

// Load Core Portal & Multi-Shop Sub-Modules
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-tournament-config.php';
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-registration.php';
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-roster.php';
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-accounting.php';
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-shops.php';       // Phase 2 Multi-Shop Engine

// Load Supporting App Modules
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-checkin.php';     // Snippet 22
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-scores.php';      // Snippet 37
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-tfaa-sync.php';   // Snippet 38
