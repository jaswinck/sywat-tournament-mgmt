<?php
/**
 * Module: Waitlist Queue Management & Family Cap Reconciliation Engine
 * Path: includes/waitlist/class-sywat-waitlist.php
 * Description: Central controller loader binding waitlist data access, per-line $75.00 Family Cap helpers, manual promote/move/remove action handlers, dedicated Waitlist Management Modal rendering, and conditional client script enqueuing.
 * Compliance: Zero HTML echo at include time, optimizer-safe filemtime() asset versioning, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 2.0.0
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
            : ( defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '2.0.0' );

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
 * Supports promote_waitlist, move_waitlist_line, and delete_shooter submitted from #sywat_waitlist_modal.
 *
 * @param string $action Sanitized sywat_action string.
 * @return bool          True if handled by the Waitlist dispatcher.
 */
if ( ! function_exists( 'sywat_waitlist_is_supported_action' ) ) {
    function sywat_waitlist_is_supported_action( $action ) {
        $act = (string) $action;
        if ( in_array( $act, array( 'promote_waitlist', 'move_waitlist_line' ), true ) ) {
            return true;
        }

        if ( $act === 'delete_shooter' && isset( $_POST['waitlist_modal'] ) && sanitize_text_field( wp_unslash( $_POST['waitlist_modal'] ) ) === '1' ) {
            return true;
        }

        return false;
    }
}

/**
 * Central POST Action Dispatcher for Waitlist mutations (Promote, Move Selected to Line, Remove).
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

        if ( $action === 'delete_shooter' && function_exists( 'sywat_waitlist_handle_remove' ) ) {
            return sywat_waitlist_handle_remove( $tournaments, $registrations );
        }

        return '';
    }
}

/**
 * Conditionally render the Dedicated Waitlist Management Modal (#sywat_waitlist_modal)
 * whenever waitlist_modal=1 is present in GET or POST request parameters.
 *
 * @param array  $tournaments        Tournaments master list (or line map).
 * @param array  $registrations      Registrations array.
 * @param string $default_tournament Fallback active tournament name.
 * @param string $post_action_url    Optional form action target URL.
 * @param string $context            Host screen context ('register', 'manage', 'roster', 'checkin').
 * @param array  $extra_context      Optional extra context values ('return_to', 'search_query', 'close_url').
 * @return void
 */
if ( ! function_exists( 'sywat_maybe_render_waitlist_modal' ) ) {
    function sywat_maybe_render_waitlist_modal( $tournaments, $registrations, $default_tournament = '', $post_action_url = '', $context = 'register', $extra_context = array() ) {
        $is_modal_requested = isset( $_REQUEST['waitlist_modal'] ) && sanitize_text_field( wp_unslash( $_REQUEST['waitlist_modal'] ) ) === '1';
        if ( ! $is_modal_requested || ! function_exists( 'sywat_render_waitlist_modal' ) ) {
            return;
        }

        $target_tournament = '';
        if ( ! empty( $_REQUEST['waitlist_tournament'] ) ) {
            $target_tournament = trim( sanitize_text_field( wp_unslash( $_REQUEST['waitlist_tournament'] ) ) );
        } elseif ( ! empty( $default_tournament ) ) {
            $target_tournament = trim( (string) $default_tournament );
        } elseif ( ! empty( $_REQUEST['selected_tournament'] ) ) {
            $target_tournament = trim( sanitize_text_field( wp_unslash( $_REQUEST['selected_tournament'] ) ) );
        } elseif ( ! empty( $_REQUEST['view_tournament'] ) ) {
            $target_tournament = trim( sanitize_text_field( wp_unslash( $_REQUEST['view_tournament'] ) ) );
        }

        if ( $target_tournament === '' ) {
            return;
        }

        $target_line = isset( $_REQUEST['waitlist_line'] ) && $_REQUEST['waitlist_line'] !== ''
            ? trim( sanitize_text_field( wp_unslash( $_REQUEST['waitlist_line'] ) ) )
            : 'all';

        $available_lines = function_exists( 'sywat_waitlist_extract_lines' )
            ? sywat_waitlist_extract_lines( $tournaments, $target_tournament )
            : array();

        sywat_render_waitlist_modal(
            $target_tournament,
            $available_lines,
            $registrations,
            $target_line,
            $post_action_url,
            $context,
            $extra_context
        );
    }
}
