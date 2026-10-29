<?php
/**
 * Module: Check-In Desk Portal Controller
 * Description: Main entry point and shortcode definition for the Check-In Desk portal.
 * Shortcode: [sywat_desk]
 * Author: SYWAT Archery
 * Version: 2.3.2
 */

if (!defined('ABSPATH')) exit;

// Load Check-In Desk Sub-Modules
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-data.php';
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-actions.php';
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-views.php';
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-modals.php';

add_shortcode('sywat_desk', 'render_sywat_desk_app');

function render_sywat_desk_app() {
    // 0. Permissions Check
    $user = wp_get_current_user();
    if (!is_user_logged_in() || !array_intersect(array('administrator', 'management', 'non-shooting-member', 'staff_shooter', 'sywat_shop_manager', 'sywat_shop_user'), (array) $user->roles)) {
        return '<p style="color:red; padding:20px;">Access denied. Management access required.</p>';
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
