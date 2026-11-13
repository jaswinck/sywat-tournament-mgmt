<?php
/**
 * Module: Waitlist Queue Management & Family Cap Reconciliation Engine
 * Path: includes/waitlist/class-sywat-waitlist.php
 * Description: Central controller loader binding waitlist data access, per-line $75.00 Family Cap helpers, manual promote/move action handlers, reusable FIFO family-grouped queue views, and conditional client script enqueuing.
 * Compliance: Zero HTML echo at include time, optimizer-safe filemtime() asset versioning, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load Waitlist Sub-Modules
require_once __DIR__ . '/waitlist-data.php';
require_once __DIR__ . '/waitlist-helpers.php';
require_once __DIR__ . '/waitlist-actions.php';
require_once __DIR__ . '/waitlist-views.php';

/**
 * Enqueue the namespaced Waitlist client script with cache-busting filemtime() versioning.
 * Can be invoked automatically via wp_enqueue_scripts or directly by view renderers.
 *
 * @return void
 */
if ( ! function_exists( 'sywat_enqueue_waitlist_scripts' ) ) {
    function sywat_enqueue_waitlist_scripts() {
        if ( wp_script_is( 'sywat-waitlist-js', 'enqueued' ) ) {
            return;
        }

        $rel_path = 'assets/js/sywat-waitlist.js';
        $js_url   = defined( 'SYWAT_PLUGIN_URL' )
            ? SYWAT_PLUGIN_URL . $rel_path
            : plugins_url( '../../' . $rel_path, __FILE__ );

        $js_file = defined( 'SYWAT_PLUGIN_DIR' )
            ? SYWAT_PLUGIN_DIR . $rel_path
            : dirname( dirname( __DIR__ ) ) . '/' . $rel_path;

        $version = file_exists( $js_file )
            ? (string) filemtime( $js_file )
            : ( defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '1.0.0' );

        wp_enqueue_script( 'sywat-waitlist-js', $js_url, array(), $version, true );
    }
}

/**
 * Conditionally enqueue Waitlist client assets on front-end SYWAT portal pages.
 *
 * @return void
 */
if ( ! function_exists( 'sywat_maybe_enqueue_waitlist_assets' ) ) {
    function sywat_maybe_enqueue_waitlist_assets() {
        if ( ! function_exists( 'sywat_should_load_module_assets' ) || ! sywat_should_load_module_assets() ) {
            return;
        }
        sywat_enqueue_waitlist_scripts();
    }
}
add_action( 'wp_enqueue_scripts', 'sywat_maybe_enqueue_waitlist_assets' );

/**
 * Check whether a POST sywat_action key belongs to the Waitlist module.
 *
 * @param string $action Sanitized sywat_action string.
 * @return bool          True if handled by the Waitlist dispatcher.
 */
if ( ! function_exists( 'sywat_waitlist_is_supported_action' ) ) {
    function sywat_waitlist_is_supported_action( $action ) {
        return in_array( (string) $action, array( 'promote_waitlist', 'move_waitlist_line' ), true );
    }
}

/**
 * Central POST Action Dispatcher for Waitlist mutations.
 *
 * @param array $tournaments   Tournaments master list (or line map).
 * @param array $registrations Reference to active registrations array.
 * @return string              HTML alert notice markup, or empty string if action is unrelated.
 */
if ( ! function_exists( 'sywat_waitlist_dispatch_post_action' ) ) {
    function sywat_waitlist_dispatch_post_action( $tournaments, &$registrations ) {
        $action = isset( $_POST['sywat_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) ) : '';

        if ( $action === 'promote_waitlist' && function_exists( 'sywat_waitlist_handle_promote' ) ) {
            return sywat_waitlist_handle_promote( $tournaments, $registrations );
        }

        if ( $action === 'move_waitlist_line' && function_exists( 'sywat_waitlist_handle_move_line' ) ) {
            return sywat_waitlist_handle_move_line( $tournaments, $registrations );
        }

        return '';
    }
}
