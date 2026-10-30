<?php
/**
 * Sub-Module: Results & Standings Loader Shell
 * Path: includes/results/class-sywat-results.php
 * Description: Main loader binding public shortcode [sywat_results].
 * Author: SYWAT Archery
 * Version: 2.4.0
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
    <div class="sywat-wrap no-print" style="margin-bottom: 20px;">
        <div class="sywat-nav-tabs">
            <a href="?view=standings" class="sywat-tab-item <?php echo $view === 'standings' ? 'active' : ''; ?>">🏆 Season Standings</a>
            <a href="?view=tournaments" class="sywat-tab-item <?php echo $view === 'tournaments' ? 'active' : ''; ?>">🎯 Tournament Results</a>
        </div>
    </div>
    <?php

    if ( $view === 'tournaments' && ! empty( $selected_tourn ) ) {
        sywat_render_public_tournament_results( $selected_tourn );
    } elseif ( $view === 'tournaments' ) {
        // Directory view listing tournaments
        $tournaments = get_option( 'sywat_tournaments_list', array() );
        ?>
        <div class="sywat-public-card">
            <h2 style="margin-top: 0; font-size: 20px; color: #0f172a;">🎯 Individual Tournament Results</h2>
            <ul style="list-style: none; padding: 0; margin-top: 15px;">
                <?php foreach ( $tournaments as $t_name => $t_data ): ?>
                    <li style="padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                        <strong><?php echo esc_html( $t_name ); ?></strong>
                        <a href="?view=tournaments&tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-primary" style="font-size: 12px; padding: 4px 10px;">View Leaderboard</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    } else {
        sywat_render_public_season_standings( $selected_season );
    }

    return ob_get_clean();
}
