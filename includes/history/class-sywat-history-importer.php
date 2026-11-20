<?php
/**
 * Sub-Module: Historical TFAA Results Importer Controller Shell
 * Path: includes/history/class-sywat-history-importer.php
 * Description: Master loader shell for the toggleable TFAA Historical Results Importer sub-module. Loads helper, data, AJAX action, and view files, registers the [sywat_history_import] shortcode, and enqueues assets/js/sywat-history-import.js in the footer with localized nonce and AJAX configuration.
 * Compliance: Pure controller shell — zero HTML output at include time, zero jQuery dependencies, optimizer-safe sywat-* script handle, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include Sub-Module Files (Helpers -> Data -> Actions -> Views)
require_once plugin_dir_path( __FILE__ ) . 'history-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'history-data.php';
require_once plugin_dir_path( __FILE__ ) . 'history-actions.php';
require_once plugin_dir_path( __FILE__ ) . 'history-views.php';

/**
 * Ensure [sywat_history_import] is registered in the central shortcode registry
 * so class-sywat-frontend.php applies .sywat-scope, assets, builder placeholders, and no-cache headers.
 *
 * @param array $tags Registered SYWAT shortcode tags.
 * @return array      Updated shortcode tags list.
 */
if ( ! function_exists( 'sywat_history_register_shortcode_tag' ) ) {
    function sywat_history_register_shortcode_tag( $tags ) {
        if ( is_array( $tags ) && ! in_array( 'sywat_history_import', $tags, true ) ) {
            $tags[] = 'sywat_history_import';
        }
        return $tags;
    }
    add_filter( 'sywat_shortcode_tags', 'sywat_history_register_shortcode_tag' );
}

/**
 * Register and enqueue the standalone vanilla JS client controller for [sywat_history_import].
 * Safe to invoke multiple times; enqueues only once in the footer with no jQuery dependency.
 *
 * @return void
 */
if ( ! function_exists( 'sywat_history_enqueue_assets' ) ) {
    function sywat_history_enqueue_assets() {
        if ( is_admin() ) {
            return;
        }
        if ( function_exists( 'sywat_is_builder_request' ) && sywat_is_builder_request() ) {
            return;
        }

        static $enqueued = false;
        if ( $enqueued ) {
            return;
        }
        $enqueued = true;

        $ver = defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '1.0.0';
        $url = defined( 'SYWAT_PLUGIN_URL' ) ? SYWAT_PLUGIN_URL . 'assets/js/sywat-history-import.js' : '';

        if ( $url === '' ) {
            return;
        }

        wp_register_script( 'sywat-history-import', $url, array(), $ver, true );
        wp_localize_script(
            'sywat-history-import',
            'sywatHistoryData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'sywat_history_import_nonce' ),
            )
        );
        wp_enqueue_script( 'sywat-history-import' );
    }
}

/**
 * Early wp_enqueue_scripts hook so sywat-history-import.js loads cleanly in the footer
 * whenever the current page renders [sywat_history_import].
 *
 * @return void
 */
if ( ! function_exists( 'sywat_history_maybe_enqueue_page_assets' ) ) {
    function sywat_history_maybe_enqueue_page_assets() {
        if ( function_exists( 'sywat_should_load_module_assets' ) && ! sywat_should_load_module_assets() ) {
            return;
        }

        $post = get_queried_object();
        if ( $post instanceof WP_Post && ! empty( $post->post_content ) && has_shortcode( $post->post_content, 'sywat_history_import' ) ) {
            sywat_history_enqueue_assets();
        }
    }
    add_action( 'wp_enqueue_scripts', 'sywat_history_maybe_enqueue_page_assets', 100 );
}

// Shortcode Registration: [sywat_history_import]
if ( shortcode_exists( 'sywat_history_import' ) ) {
    remove_shortcode( 'sywat_history_import' );
}
add_shortcode( 'sywat_history_import', 'sywat_render_history_importer_view' );
