<?php
/**
 * Module: Check-In Desk Portal Controller
 * Path: includes/checkin/class-sywat-checkin.php
 * Description: Main entry point and shortcode definition for the Check-In Desk portal.
 * Shortcode: [sywat_desk]
 * Author: SYWAT Archery
 * Version: 3.8.0
 */

if (!defined('ABSPATH')) exit;

// Load Check-In Desk Sub-Modules
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-data.php';
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-actions.php';
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-views.php';
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-modals.php';

add_shortcode('sywat_desk', 'render_sywat_desk_app');

function render_sywat_desk_app() {
    // Central Permissions Check
    if (function_exists('sywat_can_access_desk') && !sywat_can_access_desk()) {
        return '<div class="sywat-wrap" style="max-width:600px; margin:20px auto;"><div class="sywat-alert sywat-alert-danger">Access denied. Management or staff access required.</div></div>';
    }

    ob_start();

    // 1. Run Data Migrations
    sywat_desk_run_migrations();

    // 2. Fetch Data State
    $data = sywat_desk_get_data();

    // 3. Process Form Actions
    $action_notice = sywat_desk_handle_actions($data);

    // Re-fetch dataset if actions mutated state
    if (!empty($_POST['sywat_action'])) {
        $data = sywat_desk_get_data();
    }

    // 4. Render Interface
    echo '<div class="sywat-wrap">';
    echo $action_notice;
    sywat_desk_render_family_banner($data['added_family_prompt']);
    sywat_desk_render_filter_bar($data);
    sywat_desk_render_capacity_tracker($data);
    sywat_desk_render_archers_table($data);
    echo '</div>';

    // 5. Render Modals & Client Scripts
    sywat_desk_render_modals($data);
    sywat_desk_render_client_scripts($data);

    return ob_get_clean();
}
