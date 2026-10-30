<?php
/**
 * Sub-Module: Public Season Standings View (Co-Mingled)
 * Path: includes/results/results-standings.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_public_season_standings' ) ) {
    function sywat_render_public_season_standings( $selected_season_slug ) {
        $seasons = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
        if ( empty( $selected_season_slug ) ) {
            $active_s = function_exists( 'sywat_get_active_season' ) ? sywat_get_active_season() : array();
            $selected_season_slug = isset( $active_s['slug'] ) ? $active_s['slug'] : key( $seasons );
        }

        $season_data = sywat_aggregate_season_standings( $selected_season_slug );
        $best_n      = $season_data['best_n'];
        $categories  = $season_data['standings'];
        ?>

        <div class="sywat-public-card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                <div>
                    <h2 style="margin: 0; font-size: 22px; color: #1e3a8a;">🏆 SYWAT Season Standings</h2>
                    <div style="font-size: 13px; color: #646970; margin-top: 4px;">
                        Cumulative leaderboards based on Top <strong><?php echo $best_n; ?></strong> Qualifier Scores.
                    </div>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <!-- Season Dropdown Selector -->
                    <form method="get" style="margin: 0;">
                        <input type="hidden" name="view" value="standings">
                        <select name="season" onchange="this.form.submit()" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 600; font-size: 13px; height: 38px;">
                            <?php foreach ( $seasons as $slug => $s ): ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected_season_slug, $slug ); ?>>
                                    <?php echo esc_html( $s['name'] ); ?> (<?php echo esc_html( ucfirst( $s['status'] ) ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <!-- Live Archer Search -->
                    <input type="text" id="sywat_standings_search" placeholder="Search archer name..." style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; height: 38px; width: 180px;">
                </div>
            </div>

            <?php if ( empty( $categories ) ): ?>
                <p style="color: #646970; font-style: italic; padding: 20px 0;">No completed scores recorded yet for this season.</p>
            <?php else: ?>
                <?php foreach ( $categories as $cat_key => $archers ): ?>
                    <?php 
                        list( $div, $gender, $style ) = explode( '|||', $cat_key );
                    ?>
                    <div class="sywat-category-block" style="margin-bottom: 30px;">
                        <div style="background: #1e3a8a; color: #ffffff; padding: 10px 15px; border-radius: 6px 6px 0 0; font-weight: 700; font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
                            <span>🎯 <?php echo esc_html( $div . ' — ' . $gender . ' — ' . $style ); ?></span>
                            <span style="font-size: 12px; font-weight: normal; opacity: 0.85;"><?php echo count($archers); ?> Shooter<?php echo count($archers) > 1 ? 's' : ''; ?></span>
                        </div>

                        <table class="sywat-table" style="margin-top: 0; border-top: none;">
                            <thead>
                                <tr>
                                    <th style="width: 60px; text-align: center;">Rank</th>
                                    <th>Archer Name</th>
                                    <th style="text-align: center;">Tournaments</th>
                                    <th>Best <?php echo $best_n; ?> Scores Breakdown</th>
                                    <th style="text-align: right; width: 110px;">Total Score</th>
                                    <th style="text-align: right; width: 90px;">Total X's</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $archers as $idx => $a ): ?>
                                    <?php 
                                        $rank = $idx + 1;
                                        $rank_badge = '';
                                        if ( $rank === 1 ) $rank_badge = '🥇';
                                        elseif ( $rank === 2 ) $rank_badge = '🥈';
                                        elseif ( $rank === 3 ) $rank_badge = '🥉';
                                    ?>
                                    <tr class="sywat-standings-row" data-name="<?php echo esc_attr( strtolower( $a['name'] ) ); ?>">
                                        <td style="text-align: center; font-weight: bold; font-size: 14px;">
                                            <?php echo $rank_badge ? $rank_badge : $rank; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html( $a['name'] ); ?></strong>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="font-weight: 600; color: <?php echo $a['is_qualified'] ? '#008a20' : '#475569'; ?>;">
                                                <?php echo $a['tournaments_shot']; ?> Shot
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-size: 12px; color: #334155;">
                                                <?php 
                                                    $kept_strings = array();
                                                    foreach ( $a['scores_kept'] as $sk ) {
                                                        $kept_strings[] = '<strong>' . $sk['score'] . '</strong> (' . $sk['x_count'] . 'X)';
                                                    }
                                                    echo implode( ' + ', $kept_strings );
                                                ?>
                                            </div>
                                        </td>
                                        <td style="text-align: right; font-weight: 700; font-size: 15px; color: #0f172a;">
                                            <?php echo $a['total_score']; ?>
                                        </td>
                                        <td style="text-align: right; font-weight: 700; font-size: 14px; color: #2563eb;">
                                            <?php echo $a['total_x_count']; ?>X
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.getElementById('sywat_standings_search');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase().trim();
                    const rows = document.querySelectorAll('.sywat-standings-row');
                    rows.forEach(row => {
                        const name = (row.getAttribute('data-name') || '').toLowerCase();
                        row.style.display = name.includes(filter) ? '' : 'none';
                    });
                });
            }
        });
        </script>
        <?php
    }
}
