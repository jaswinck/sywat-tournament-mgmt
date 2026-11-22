<?php
/**
 * Module: Shooting Styles & Divisions Configuration Engine
 * Path: includes/shooting-styles/class-sywat-shooting-styles.php
 * Description: Central controller loader binding database storage, O(1) age division lookup & rate-tier helpers, global style helpers, POST action handlers, Administration GUI shortcode ([sywat_shooting_styles]), and conditional client script localization.
 * Compliance: Zero HTML echo at include time, SYWAT_PLUGIN_URL & filemtime() asset versioning, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load Shooting Styles & Divisions Sub-Modules
require_once __DIR__ . '/shooting-styles-data.php';
require_once __DIR__ . '/division-helpers.php';
require_once __DIR__ . '/shooting-styles-helpers.php';
require_once __DIR__ . '/shooting-styles-actions.php';
require_once __DIR__ . '/shooting-styles-views.php';

/**
 * Enqueue and localize the namespaced Shooting Styles client controller with filemtime() versioning.
 * Exposes window.SYWAT.shootingStyles and window.sywatShootingStylesConfig across SYWAT portal pages.
 *
 * @return void
 */
if ( ! function_exists( 'sywat_enqueue_shooting_styles_scripts' ) ) {
    function sywat_enqueue_shooting_styles_scripts() {
        if ( wp_script_is( 'sywat-shooting-styles-js', 'enqueued' ) ) {
            return;
        }

        if ( ! defined( 'SYWAT_PLUGIN_URL' ) || ! defined( 'SYWAT_PLUGIN_DIR' ) ) {
            return;
        }

        $rel_path = 'assets/js/sywat-shooting-styles.js';
        $js_url   = SYWAT_PLUGIN_URL . $rel_path;
        $js_file  = SYWAT_PLUGIN_DIR . $rel_path;
        $version  = file_exists( $js_file )
            ? (string) filemtime( $js_file )
            : ( defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : false );

        wp_enqueue_script( 'sywat-shooting-styles-js', $js_url, array(), $version, true );

        $payload = function_exists( 'sywat_get_shooting_styles_js_payload' )
            ? sywat_get_shooting_styles_js_payload()
            : array(
                'defaultStyle' => 'FS – Freestyle',
                'styles'       => array(),
                'divisions'    => array(),
            );

        wp_localize_script( 'sywat-shooting-styles-js', 'sywatShootingStylesConfig', $payload );
    }
}

/**
 * Conditionally enqueue Shooting Styles client assets on front-end SYWAT portal pages.
 *
 * @return void
 */
if ( ! function_exists( 'sywat_maybe_enqueue_shooting_styles_assets' ) ) {
    function sywat_maybe_enqueue_shooting_styles_assets() {
        if ( ! function_exists( 'sywat_should_load_module_assets' ) || ! sywat_should_load_module_assets() ) {
            return;
        }
        sywat_enqueue_shooting_styles_scripts();
    }
}
add_action( 'wp_enqueue_scripts', 'sywat_maybe_enqueue_shooting_styles_assets' );

// Register Front-End Administration Shortcode
if ( shortcode_exists( 'sywat_shooting_styles' ) ) {
    remove_shortcode( 'sywat_shooting_styles' );
}
add_shortcode( 'sywat_shooting_styles', 'render_sywat_shooting_styles_app' );
