<?php
/**
 * Sub-Module: Public Season Standings View
 * Path: includes/results/results-standings.php
 * Author: SYWAT Archery
 * Version: 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_format_score_cell' ) ) {
    function sywat_format_score_cell( $score_item ) {
        if ( ! is_array( $score_item ) || ! isset( $score_item['score'] ) ) {
            return '<span style="color: #94a3b8;">—</span>';
        }
        return '<strong>' . intval( $score_item['score'] ) . ' / ' . intval( $score_item['x_count'] ) . 'X</strong>';
    }
}

if ( ! function_exists( 'sywat_render_public_season_standings' ) ) {
    function sywat_render_public_season_standings( $selected_season_slug ) {
        $seasons = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
        if ( empty( $selected_season_slug ) ) {
            $active_s = function_exists( 'sywat_get_active_season' ) ? sywat_get_active_season() : array();
            $selected_season_slug = isset( $active_s['slug'] ) ? $active_s['slug'] : key( $seasons );
        }

        $season_data = sywat_aggregate_season_standings( $selected_season_slug );
        $best_n      = $season_data['best_n'];
        $groups      = $season_data['groups'];

        $all_divisions = array();
        $all_styles    = array();

        foreach ( $groups as $g ) {
            $clean_d = trim( preg_replace( '/\s*\([^)]*\)/', '', $g['division'] ) );
            if ( ! empty( $clean_d ) ) {
                $all_divisions[strtolower( $clean_d )] = $clean_d;
            }
            foreach ( $g['styles'] as $s_name => $archers ) {
                $all_styles[strtolower( trim( $s_name ) )] = trim( $s_name );
            }
        }
        ksort($all_divisions);

        uksort( $all_styles, function( $a, $b ) {
            $wA = function_exists( 'sywat_get_style_sort_weight' ) ? sywat_get_style_sort_weight( $a ) : 50;
            $wB = function_exists( 'sywat_get_style_sort_weight' ) ? sywat_get_style_sort_weight( $b ) : 50;
            if ( $wA !== $wB ) return $wA - $wB;
            return strcasecmp( $a, $b );
        });
        ?>

        <style>
            .sywat-single-category-header {
                background: #1e3a8a;
                color: #ffffff;
                padding: 10px 14px;
                font-size: 14px;
                font-weight: 700;
                border-radius: 6px 6px 0 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }
        </style>

        <div class="sywat-public-card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                <div>
                    <h2 style="margin: 0; font-size: 22px; color: #1e3a8a;">🏆 SYWAT Season Standings</h2>
                    <div style="font-size: 13px; color: #646970; margin-top: 4px;">
                        Leaderboards aggregated across Top <strong><?php echo $best_n; ?></strong> Qualifiers + State Championship.
                    </div>
                </div>

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
            </div>

            <div class="sywat-filter-bar">
                <div class="sywat-field-group" style="flex: 1 1 140px; min-width: 130px;">
                    <label>Division</label>
                    <select id="sywat_standings_filter_div" onchange="sywatApplyStandingsFilters()" style="width: 100%; height: 36px;">
                        <option value="all">All Divisions</option>
                        <?php foreach ( $all_divisions as $d_slug => $d_label ): ?>
                            <option value="<?php echo esc_attr( $d_slug ); ?>"><?php echo esc_html( $d_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sywat-field-group" style="flex: 1 1 120px; min-width: 110px;">
                    <label>Gender</label>
                    <select id="sywat_standings_filter_gen" onchange="sywatApplyStandingsFilters()" style="width: 100%; height: 36px;">
                        <option value="all">All Genders</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>

                <div class="sywat-field-group" style="flex: 1 1 160px; min-width: 150px;">
                    <label>Style</label>
                    <select id="sywat_standings_filter_style" onchange="sywatApplyStandingsFilters()" style="width: 100%; height: 36px;">
                        <option value="all">All Styles</option>
                        <?php foreach ( $all_styles as $st_slug => $st_label ): ?>
                            <option value="<?php echo esc_attr( $st_slug ); ?>"><?php echo esc_html( $st_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sywat-field-group" style="flex: 2 1 200px; min-width: 180px;">
                    <label>Search Archer</label>
                    <input type="text" id="sywat_standings_search" oninput="sywatApplyStandingsFilters()" placeholder="Type archer name..." style="width: 100%; height: 36px; box-sizing: border-box;">
                </div>

                <div style="flex: 0 0 auto;">
                    <button type="button" class="sywat-btn sywat-btn-secondary" onclick="sywatResetStandingsFilters()" style="height: 36px; font-size: 12px;">Reset Filters</button>
                </div>
            </div>

            <?php if ( empty( $groups ) ): ?>
                <p style="color: #646970; font-style: italic; padding: 20px 0;">No completed scores recorded yet for this season.</p>
            <?php else: ?>
                <?php foreach ( $groups as $dg_key => $group_data ): ?>
                    <?php 
                        $card_div_slug = strtolower( trim( preg_replace( '/\s*\([^)]*\)/', '', $group_data['division'] ) ) );
                        $card_gen_slug = strtolower( trim( preg_replace( '/\s*\([^)]*\)/', '', $group_data['gender'] ) ) );
                    ?>
                    <div class="sywat-div-gen-card" data-division="<?php echo esc_attr( $card_div_slug ); ?>" data-gender="<?php echo esc_attr( $card_gen_slug ); ?>" style="margin-bottom: 20px; background: transparent; border: none; box-shadow: none; padding: 0;">

                        <?php foreach ( $group_data['styles'] as $style_name => $archers ): ?>
                            <?php 
                                $style_slug = strtolower( trim( $style_name ) );
                                $combined_title = function_exists('sywat_format_combined_category_header') 
                                    ? sywat_format_combined_category_header( $group_data['division'], $group_data['gender'], $style_name )
                                    : trim( $group_data['division'] . ' ' . $group_data['gender'] . ' ' . $style_name );
                            ?>
                            <div class="sywat-style-block" data-style="<?php echo esc_attr( $style_slug ); ?>" style="margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; background: #ffffff;">
                                <div class="sywat-single-category-header">
                                    🎯 <?php echo esc_html( $combined_title ); ?>
                                </div>

                                <div class="sywat-table-responsive" style="margin-bottom: 0;">
                                    <table class="sywat-table" style="margin-top: 0;">
                                        <thead>
                                            <tr>
                                                <th style="width: 50px; text-align: center;">Rank</th>
                                                <th>Archer Name</th>
                                                <th style="text-align: center; width: 110px;">Score 1</th>
                                                <th style="text-align: center; width: 110px;">Score 2</th>
                                                <th style="text-align: center; width: 110px;">Score 3</th>
                                                <th style="text-align: center; width: 110px;">State</th>
                                                <th style="text-align: right; width: 130px;">Total Score</th>
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
                                                    <td><strong><?php echo esc_html( $a['name'] ); ?></strong></td>
                                                    <td style="text-align: center;"><?php echo sywat_format_score_cell( $a['score_1'] ); ?></td>
                                                    <td style="text-align: center;"><?php echo sywat_format_score_cell( $a['score_2'] ); ?></td>
                                                    <td style="text-align: center;"><?php echo sywat_format_score_cell( $a['score_3'] ); ?></td>
                                                    <td style="text-align: center;"><?php echo sywat_format_score_cell( $a['state_score'] ); ?></td>
                                                    <td style="text-align: right; font-size: 15px; color: #0f172a;">
                                                        <strong><?php echo intval( $a['total_score'] ) . ' / ' . intval( $a['total_x_count'] ) . 'X'; ?></strong>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <script>
        function sywatApplyStandingsFilters() {
            const divFilter   = document.getElementById('sywat_standings_filter_div').value.toLowerCase().trim();
            const genFilter   = document.getElementById('sywat_standings_filter_gen').value.toLowerCase().trim();
            const styleFilter = document.getElementById('sywat_standings_filter_style').value.toLowerCase().trim();
            const searchFilter= document.getElementById('sywat_standings_search').value.toLowerCase().trim();

            const cards = document.querySelectorAll('.sywat-div-gen-card');

            cards.forEach(card => {
                const cardDiv = (card.getAttribute('data-division') || '').toLowerCase().trim();
                const cardGen = (card.getAttribute('data-gender') || '').toLowerCase().trim();

                const divMatch = (divFilter === 'all' || cardDiv === divFilter);
                const genMatch = (genFilter === 'all' || cardGen === genFilter);

                let cardHasVisibleBlock = false;
                const styleBlocks = card.querySelectorAll('.sywat-style-block');

                styleBlocks.forEach(block => {
                    const blockStyle = (block.getAttribute('data-style') || '').toLowerCase().trim();
                    const styleMatch = (styleFilter === 'all' || blockStyle === styleFilter);

                    let blockHasVisibleRow = false;
                    const rows = block.querySelectorAll('.sywat-standings-row');

                    rows.forEach(row => {
                        const name = (row.getAttribute('data-name') || '').toLowerCase();
                        const searchMatch = (!searchFilter || name.includes(searchFilter));

                        if (divMatch && genMatch && styleMatch && searchMatch) {
                            row.style.display = '';
                            blockHasVisibleRow = true;
                        } else {
                            row.style.display = 'none';
                        }
                    });

                    if (blockHasVisibleRow) {
                        block.style.display = '';
                        cardHasVisibleBlock = true;
                    } else {
                        block.style.display = 'none';
                    }
                });

                card.style.display = cardHasVisibleBlock ? '' : 'none';
            });
        }

        function sywatResetStandingsFilters() {
            document.getElementById('sywat_standings_filter_div').value = 'all';
            document.getElementById('sywat_standings_filter_gen').value = 'all';
            document.getElementById('sywat_standings_filter_style').value = 'all';
            document.getElementById('sywat_standings_search').value = '';
            sywatApplyStandingsFilters();
        }
        </script>
        <?php
    }
}
