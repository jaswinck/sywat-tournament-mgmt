<?php
/**
 * Plugin Name: SYWAT Archery Manager
 * Plugin URI:  https://ccrstaffshooters.com
 * Description: Unified portal for tournament management, check-in desk, score entry, and TFAA sync.
 * Version:     1.0.0
 * Author:      SYWAT Archery
 * Text Domain: sywat-archery
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define Plugin Constants
define('SYWAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SYWAT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Automatically register custom roles upon plugin activation
register_activation_hook(__FILE__, 'sywat_archery_activate');
function sywat_archery_activate() {
    add_role('staff_shooter', 'Staff Shooter', array('read' => true));
    add_role('management', 'Management', array('read' => true));
}

// Enqueue standard jQuery and global styles
add_action('wp_enqueue_scripts', 'sywat_archery_enqueue_assets');
function sywat_archery_enqueue_assets() {
    wp_enqueue_script('jquery');
}

// Load Core Portal & Management Sub-Modules (Refactored Snippet 25)
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-tournament-config.php';
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-registration.php';
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-roster.php';
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-accounting.php';

// Load Supporting App Modules
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-checkin.php';     // Snippet 22
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-scores.php';      // Snippet 37
require_once SYWAT_PLUGIN_DIR . 'includes/class-sywat-tfaa-sync.php';   // Snippet 38
