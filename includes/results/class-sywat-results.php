<?php
/**
 * Sub-Module: Results & Standings Loader Shell
 * Path: includes/results/class-sywat-results.php
 * Description: Main loader binding public shortcode [sywat_results].
 * Author: SYWAT Archery
 * Version: 3.6.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Include Sub-Module Files
require_once plugin_dir_path( __FILE__ ) . 'results-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'results-standings.php';
require_once plugin_dir_path( __FILE__ ) . 'results-tournaments.php';

// Shortcode Registration
if ( shortcode_exists( 'sywat_results' ) ) remove_shortcode( 'sywat_results' );

add_shortcode( 'sywat_results', 'render_sywat_public_results_portal' );

function render_sywat_public_results_portal() {
    ob_start();

    $view            = isset( $_REQUEST['view'] ) ? sanitize_text_field( $_REQUEST['view'] ) : 'standings';
    $selected_season = isset( $_REQUEST['season'] ) ? sanitize_text_field( $_REQUEST['season'] ) : '';
    $selected_tourn  = isset( $_REQUEST['tournament'] ) ? sanitize_text_field( $_REQUEST['tournament'] ) : '';

    ?>
    <div class="sywat-wrap">
        <!-- Sub-Module Navigation Tabs (Card-Docked) -->
        <div class="sywat-nav-tabs no-print">
            <a href="?view=standings" class="sywat-tab-item sywat-tab-icon-standings <?php echo $view === 'standings' ? 'active' : ''; ?>">Season Standings</a>
            <a href="?view=tournaments" class="sywat-tab-item sywat-tab-icon-results <?php echo $view === 'tournaments' ? 'active' : ''; ?>">Tournament Results</a>
        </div>

        <?php
        if ( $view === 'tournaments' && ! empty( $selected_tourn ) ) {
            sywat_render_public_tournament_results( $selected_tourn );
        } elseif ( $view === 'tournaments' ) {
            if ( function_exists( 'sywat_render_public_tournament_directory' ) ) {
                sywat_render_public_tournament_directory();
            }
        } else {
            sywat_render_public_season_standings( $selected_season );
        }
        ?>
    </div>
    <?php

    return ob_get_clean();
}
