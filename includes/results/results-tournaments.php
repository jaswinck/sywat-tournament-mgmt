<?php
/**
 * Sub-Module: Public Tournament Results View
 * Path: includes/results/results-tournaments.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_public_tournament_results' ) ) {
    function sywat_render_public_tournament_results( $tournament_name ) {
        $scores = sywat_get_tournament_scores( $tournament_name );

        // Group scores by Division -> Gender -> Style
        $grouped = array();
        foreach ( $scores as $s ) {
            $cat_key = $s['division'] . '|||' . $s['gender'] . '|||' . $s['style'];
            $grouped[$cat_key][] = $s;
        }

        // Sort each category by Score DESC, then X Count DESC
        foreach ( $grouped as $cat_key => &$list ) {
            usort( $list, function( $a, $b ) {
                if ( $a['score'] === $b['score'] ) {
                    return $b['x_count'] - $a['x_count'];
                }
                return $b['score'] - $a['score'];
            });
        }
        unset($list);
        ?>

        <div class="sywat-public-card">
            <div style="margin-bottom: 20px;">
                <a href="?view=tournaments" class="sywat-btn sywat-btn-secondary" style="margin-bottom: 12px; display: inline-flex; align-items: center; gap: 5px;">← Back to Tournament Directory</a>
                <h2 style="margin: 0; font-size: 22px; color: #0f172a;">🎯 Results: <?php echo esc_html( $tournament_name ); ?></h2>
            </div>

            <?php if ( empty( $grouped ) ): ?>
                <p style="color: #646970; font-style: italic; padding: 20px 0;">No scores submitted yet for this tournament.</p>
            <?php else: ?>
                <?php foreach ( $grouped as $cat_key => $archers ): ?>
                    <?php list( $div, $gender, $style ) = explode( '|||', $cat_key ); ?>
                    <div style="margin-bottom: 25px;">
                        <div style="background: #0f172a; color: #ffffff; padding: 8px 12px; border-radius: 6px 6px 0 0; font-weight: 700; font-size: 13px;">
                            🏆 <?php echo esc_html( $div . ' — ' . $gender . ' — ' . $style ); ?>
                        </div>
                        <table class="sywat-table" style="margin-top: 0;">
                            <thead>
                                <tr>
                                    <th style="width: 50px; text-align: center;">Place</th>
                                    <th>Archer Name</th>
                                    <th style="text-align: right; width: 140px;">Score / X</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $archers as $idx => $a ): ?>
                                    <tr>
                                        <td style="text-align: center; font-weight: bold;"><?php echo ($idx + 1); ?></td>
                                        <td><strong><?php echo esc_html( $a['name'] ); ?></strong></td>
                                        <td style="text-align: right; font-size: 15px; color: #0f172a;">
                                            <strong><?php echo intval( $a['score'] ) . ' / ' . intval( $a['x_count'] ) . 'X'; ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
