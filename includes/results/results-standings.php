<?php
/**
 * Sub-Module: Public Season Standings View
 * Path: includes/results/results-standings.php
 * Author: SYWAT Archery
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

        $all_styles = array();
        foreach ( $groups as $g ) {
            foreach ( $g['styles'] as $s_name => $archers ) {
                $all_styles[$s_name] = $s_name;
            }
        }
        ksort($all_styles);
        ?>

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
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Division</label>
                    <select id="sywat_standings_filter_div" onchange="sywatApplyStandingsFilters()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; height: 36px; min-width: 140px;">
                        <option value="all">All Divisions</option>
                        <option value="cub">Cub</option>
                        <option value="youth">Youth</option>
                        <option value="young adult">Young Adult</option>
                        <option value="adult">Adult</option>
                        <option value="senior">Senior</option>
                        <option value="silver senior">Silver Senior</option>
                        <option value="master senior">Master Senior</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Gender</label>
                    <select id="sywat_standings_filter_gen" onchange="sywatApplyStandingsFilters()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; height: 36px; min-width: 120px;">
                        <option value="all">All Genders</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Style</label>
                    <select id="sywat_standings_filter_style" onchange="sywatApplyStandingsFilters()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; height: 36px; min-width: 160px;">
                        <option value="all">All Styles</option>
                        <?php foreach ( $all_styles as $st ): ?>
                            <option value="<?php echo esc_attr( strtolower( $st ) ); ?>"><?php echo esc_html( $st ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex-grow: 1; min-width: 180px;">
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Search Archer</label>
                    <input type="text" id="sywat_standings_search" oninput="sywatApplyStandingsFilters()" placeholder="Type archer name..." style="width:100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; height: 36px; box-sizing:border-box;">
                </div>
                <div>
                    <button type="button" class="sywat-btn sywat-btn-secondary" onclick="sywatResetStandingsFilters()" style="height: 36px; font-size: 12px;">Reset Filters</button>
                </div>
            </div>

            <?php if ( empty( $groups ) ): ?>
                <p style="color: #646970; font-style: italic; padding: 20px 0;">No completed scores recorded yet for this season.</p>
            <?php else: ?>
                <?php foreach ( $groups as $dg_key => $group_data ): ?>
                    <div class="sywat-div-gen-card" data-division="<?php echo esc_attr( strtolower( $group_data['division'] ) ); ?>" data-gender="<?php echo esc_attr( strtolower( $group_data['gender'] ) ); ?>">
                        <div class="sywat-div-gen-header">
                            🎯 <?php echo esc_html( $group_data['heading'] ); ?>
                        </div>

                        <div style="padding: 15px;">
                            <?php foreach ( $group_data['styles'] as $style_name => $archers ): ?>
                                <div class="sywat-style-block" data-style="<?php echo esc_attr( strtolower( $style_name ) ); ?>">
                                    <h4 class="sywat-style-header">
                                        🏹 <?php echo esc_html( $style_name ); ?>
                                    </h4>

                                    <table class="sywat-table" style="margin-top: 0; margin-bottom: 15px;">
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
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <script>
        function sywatApplyStandingsFilters() {
            const divFilter   = document.getElementById('sywat_standings_filter_div').value.toLowerCase();
            const genFilter   = document.getElementById('sywat_standings_filter_gen').value.toLowerCase();
            const styleFilter = document.getElementById('sywat_standings_filter_style').value.toLowerCase();
            const searchFilter= document.getElementById('sywat_standings_search').value.toLowerCase().trim();

            const cards = document.querySelectorAll('.sywat-div-gen-card');

            cards.forEach(card => {
                const cardDiv = (card.getAttribute('data-division') || '').toLowerCase();
                const cardGen = (card.getAttribute('data-gender') || '').toLowerCase();

                const divMatch = (divFilter === 'all' || cardDiv.includes(divFilter));
                const genMatch = (genFilter === 'all' || cardGen.includes(genFilter));

                let cardHasVisibleBlock = false;
                const styleBlocks = card.querySelectorAll('.sywat-style-block');

                styleBlocks.forEach(block => {
                    const blockStyle = (block.getAttribute('data-style') || '').toLowerCase();
                    const styleMatch = (styleFilter === 'all' || blockStyle.includes(styleFilter));

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
