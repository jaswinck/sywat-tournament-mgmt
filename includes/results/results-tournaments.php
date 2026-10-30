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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <a href="?view=tournaments" style="font-size: 12px; text-decoration: none; color: #2563eb; font-weight: 600;">← Back to Tournament Directory</a>
                    <h2 style="margin: 5px 0 0 0; font-size: 22px; color: #0f172a;">🎯 Results: <?php echo esc_html( $tournament_name ); ?></h2>
                </div>
                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-print" onclick="window.print();">Print Leaderboard</button>
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
                                    <th style="text-align: right; width: 100px;">Score</th>
                                    <th style="text-align: right; width: 80px;">X Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $archers as $idx => $a ): ?>
                                    <tr>
                                        <td style="text-align: center; font-weight: bold;"><?php echo ($idx + 1); ?></td>
                                        <td><strong><?php echo esc_html( $a['name'] ); ?></strong></td>
                                        <td style="text-align: right; font-weight: 700;"><?php echo $a['score']; ?></td>
                                        <td style="text-align: right; font-weight: 700; color: #2563eb;"><?php echo $a['x_count']; ?>X</td>
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
