<?php
/**
 * Sub-Module: Member Management Loader Shell
 * Path: includes/members/class-sywat-members.php
 * Description: Main loader binding public/admin shortcode [sywat_members] and enqueuing sub-module assets.
 * Compliance: Registers script hooks for external JS asset loading.
 * Author: SYWAT Archery
 * Version: 3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Require Sub-Module Files
require_once plugin_dir_path( __FILE__ ) . 'members-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'members-actions.php';
require_once plugin_dir_path( __FILE__ ) . 'members-views.php';
require_once plugin_dir_path( __FILE__ ) . 'members-modals.php';

/**
 * Enqueue JavaScript assets for the Member Management sub-module.
 */
if ( ! function_exists( 'sywat_enqueue_members_assets' ) ) {
    function sywat_enqueue_members_assets() {
        $plugin_root_url  = plugin_dir_url( dirname( dirname( __FILE__ ) ) );
        $plugin_root_path = plugin_dir_path( dirname( dirname( __FILE__ ) ) );

        $js_url  = $plugin_root_url . 'assets/js/sywat-members.js';
        $js_path = $plugin_root_path . 'assets/js/sywat-members.js';
        $version = file_exists( $js_path ) ? filemtime( $js_path ) : '3.2.0';

        wp_enqueue_script(
            'sywat-members-js',
            $js_url,
            array(),
            $version,
            true
        );
    }
}
add_action( 'wp_enqueue_scripts', 'sywat_enqueue_members_assets' );
add_action( 'admin_enqueue_scripts', 'sywat_enqueue_members_assets' );

// Shortcode Registration
if ( shortcode_exists( 'sywat_members' ) ) {
    remove_shortcode( 'sywat_members' );
}
add_shortcode( 'sywat_members', 'sywat_render_members_portal' );
