<?php
/**
 * Sub-Module: Results & Standings Loader Shell
 * Path: includes/results/class-sywat-results.php
 * Description: Main loader binding public shortcode [sywat_results] with Tournament Results as the primary/default tab and Season Standings as the secondary tab, loading separated helper, data, and view sub-modules.
 * Compliance: Output-buffered shortcode handler, zero HTML emitted at include time, zero inline styles, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 3.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include Sub-Module Files (Helpers -> Data -> Views)
require_once plugin_dir_path( __FILE__ ) . 'results-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'results-data.php';
require_once plugin_dir_path( __FILE__ ) . 'results-standings.php';
require_once plugin_dir_path( __FILE__ ) . 'results-tournaments.php';

// Shortcode Registration
if ( shortcode_exists( 'sywat_results' ) ) {
    remove_shortcode( 'sywat_results' );
}

add_shortcode( 'sywat_results', 'render_sywat_public_results_portal' );

/**
 * Renders the public [sywat_results] portal with Tournament Results and Season Standings tabs.
 *
 * @return string Buffered HTML output.
 */
function render_sywat_public_results_portal() {
    ob_start();

    $raw_view        = isset( $_REQUEST['view'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['view'] ) ) : 'tournaments';
    $view            = ( $raw_view === 'standings' ) ? 'standings' : 'tournaments';
    $selected_season = isset( $_REQUEST['season'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['season'] ) ) : '';
    $selected_tourn  = isset( $_REQUEST['tournament'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tournament'] ) ) : '';
    ?>
    <div class="sywat-wrap">
        <!-- Sub-Module Navigation Tabs (Card-Docked) -->
        <div class="sywat-nav-tabs no-print">
            <a href="?view=tournaments" class="sywat-tab-item sywat-tab-icon-results <?php echo ( $view === 'tournaments' ) ? 'active' : ''; ?>">Tournament Results</a>
            <a href="?view=standings" class="sywat-tab-item sywat-tab-icon-standings <?php echo ( $view === 'standings' ) ? 'active' : ''; ?>">Season Standings</a>
        </div>

        <?php
        if ( $view === 'standings' ) {
            if ( function_exists( 'sywat_render_public_season_standings' ) ) {
                sywat_render_public_season_standings( $selected_season );
            }
        } elseif ( ! empty( $selected_tourn ) ) {
            if ( function_exists( 'sywat_render_public_tournament_results' ) ) {
                sywat_render_public_tournament_results( $selected_tourn );
            }
        } else {
            if ( function_exists( 'sywat_render_public_tournament_directory' ) ) {
                sywat_render_public_tournament_directory();
            }
        }
        ?>
    </div>
    <?php

    return ob_get_clean();
}
