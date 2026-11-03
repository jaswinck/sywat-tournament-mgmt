<?php
/**
 * Sub-Module: Results & Standings Loader Shell
 * Path: includes/results/class-sywat-results.php
 * Description: Main loader binding public shortcode [sywat_results].
 * Author: SYWAT Archery
 * Version: 3.6.2
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
            // Directory view listing tournaments sorted oldest first
            $tournaments = get_option( 'sywat_tournaments_list', array() );
            if ( ! is_array( $tournaments ) ) $tournaments = array();

            // Sort tournaments by start_date ASC (Oldest to Newest)
            uasort( $tournaments, function( $a, $b ) {
                $a_date = ( is_array( $a ) && ! empty( $a['start_date'] ) ) ? $a['start_date'] : '';
                $b_date = ( is_array( $b ) && ! empty( $b['start_date'] ) ) ? $b['start_date'] : '';
                if ( $a_date === $b_date ) return 0;
                return ( $a_date < $b_date ) ? -1 : 1;
            });
            ?>
            <div class="sywat-public-card" style="border-top-left-radius:0;">
                <h2 style="margin-top: 0; font-size: 20px; color: #0f172a;">🎯 Individual Tournament Results</h2>
                
                <?php if ( empty( $tournaments ) ): ?>
                    <p style="color: #646970; font-style: italic; padding: 20px 0;">No tournaments configured yet.</p>
                <?php else: ?>
                    <div class="sywat-table-responsive" style="margin-top: 15px;">
                        <table class="sywat-table" style="margin-top: 0;">
                            <thead>
                                <tr>
                                    <th style="width: 25%;">Tournament Date</th>
                                    <th>Tournament Name</th>
                                    <th style="width: 220px; text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $tournaments as $t_name => $t_data ): ?>
                                    <?php 
                                        $s_date     = ( is_array( $t_data ) && isset( $t_data['start_date'] ) ) ? $t_data['start_date'] : '';
                                        $e_date     = ( is_array( $t_data ) && isset( $t_data['end_date'] ) ) ? $t_data['end_date'] : '';
                                        $disp_dates = function_exists( 'sywat_format_tournament_dates' ) ? sywat_format_tournament_dates( $s_date, $e_date ) : '';
                                        if ( empty( $disp_dates ) ) {
                                            $disp_dates = get_option( 'sywat_dates_' . sanitize_title( $t_name ), '—' );
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong style="font-size: 15px; color: #0f172a; font-weight: 700;">📅 <?php echo esc_html( $disp_dates ); ?></strong>
                                        </td>
                                        <td>
                                            <strong style="font-size: 15px; color: #0f172a; font-weight: 700;"><?php echo esc_html( $t_name ); ?></strong>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">
                                                <?php if ( function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores() ): ?>
                                                    <a href="/sywat-enter-scores/?selected_tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-secondary" style="font-size: 12px; padding: 6px 10px;" title="Jump to Score Entry Desk">✏️ Enter Scores</a>
                                                <?php endif; ?>
                                                <a href="?view=tournaments&tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-primary" style="font-size: 12px; padding: 6px 12px;">View Results</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        } else {
            sywat_render_public_season_standings( $selected_season );
        }
        ?>
    </div>
    <?php

    return ob_get_clean();
}
