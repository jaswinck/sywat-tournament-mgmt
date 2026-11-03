<?php
/**
 * Sub-Module: Public Season Standings View
 * Path: includes/results/results-standings.php
 * Author: SYWAT Archery
 * Version: 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_format_score_cell' ) ) {
    function sywat_format_score_cell( $score_item ) {
        if ( ! is_array( $score_item ) || ! isset( $score_item['score'] ) ) {
            return '<span class="sywat-score-null">—</span>';
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

        $all_divisions         = array();
        $all_styles            = array();
        $total_season_shooters = 0;

        foreach ( $groups as $g ) {
            $clean_d = trim( preg_replace( '/\s*\([^)]*\)/', '', $g['division'] ) );
            if ( ! empty( $clean_d ) ) {
                $all_divisions[strtolower( $clean_d )] = $clean_d;
            }
            foreach ( $g['styles'] as $s_name => $archers ) {
                $all_styles[strtolower( trim( $s_name ) )] = trim( $s_name );
                $total_season_shooters += count( $archers );
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

        <div class="sywat-public-card">
            <!-- TOP CARD HEADER BAR: TITLE + SUBTITLE (LEFT) & CLEAN INLINE SEASON SELECTOR (RIGHT) -->
            <div class="sywat-card-header-bar">
                <div>
                    <h2>🎯 Season Standings</h2>
                    <div class="sywat-subtitle">
                        Leaderboards aggregated across Top <strong><?php echo $best_n; ?></strong> Qualifiers + State Championship.
                    </div>
                </div>

                <form method="get" id="sywat_season_form" class="sywat-header-inline-select">
                    <input type="hidden" name="view" value="standings">
                    <label for="sywat_season_select"><strong>Season:</strong></label>
                    <select name="season" id="sywat_season_select" onchange="this.form.submit()">
                        <?php foreach ( $seasons as $slug => $s ): ?>
                            <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected_season_slug, $slug ); ?>>
                                <?php echo esc_html( $s['name'] ); ?> (<?php echo esc_html( ucfirst( $s['status'] ) ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <!-- UNIFIED ALIGNED FILTER BAR (LIVE TABLE FILTERS ONLY) -->
            <div class="sywat-filter-bar">
                <!-- 1. Division Filter -->
                <div class="sywat-field-group">
                    <label>Division</label>
                    <select id="sywat_standings_filter_div" onchange="sywatApplyStandingsFilters()">
                        <option value="all">All Divisions</option>
                        <?php foreach ( $all_divisions as $d_slug => $d_label ): ?>
                            <option value="<?php echo esc_attr( $d_slug ); ?>"><?php echo esc_html( $d_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. Gender Filter -->
                <div class="sywat-field-group">
                    <label>Gender</label>
                    <select id="sywat_standings_filter_gen" onchange="sywatApplyStandingsFilters()">
                        <option value="all">All Genders</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>

                <!-- 3. Style Filter -->
                <div class="sywat-field-group">
                    <label>Style</label>
                    <select id="sywat_standings_filter_style" onchange="sywatApplyStandingsFilters()">
                        <option value="all">All Styles</option>
                        <?php foreach ( $all_styles as $st_slug => $st_label ): ?>
                            <option value="<?php echo esc_attr( $st_slug ); ?>"><?php echo esc_html( $st_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 4. Search Archer Field & Reset Button -->
                <div class="sywat-field-group">
                    <label>Search Archer</label>
                    <div class="sywat-search-input-wrapper">
                        <input type="text" id="sywat_standings_search" oninput="sywatApplyStandingsFilters()" placeholder="Type archer name...">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetStandingsFilters()">Reset Filters</button>
                    </div>
                </div>
            </div>

            <!-- DYNAMIC PARTICIPATION STAT BANNER -->
            <div id="sywat_standings_counter" class="sywat-counter-banner">
                🎯 Total Season Shooters: <strong><?php echo $total_season_shooters; ?></strong>
            </div>

            <?php if ( empty( $groups ) ): ?>
                <p class="sywat-empty-notice">No completed scores recorded yet for this season.</p>
            <?php else: ?>
                <?php foreach ( $groups as $dg_key => $group_data ): ?>
                    <?php 
                        $card_div_slug = strtolower( trim( preg_replace( '/\s*\([^)]*\)/', '', $group_data['division'] ) ) );
                        $card_gen_slug = strtolower( trim( preg_replace( '/\s*\([^)]*\)/', '', $group_data['gender'] ) ) );
                    ?>
                    <div class="sywat-div-gen-card" data-division="<?php echo esc_attr( $card_div_slug ); ?>" data-gender="<?php echo esc_attr( $card_gen_slug ); ?>">

                        <?php foreach ( $group_data['styles'] as $style_name => $archers ): ?>
                            <?php 
                                $style_slug = strtolower( trim( $style_name ) );
                                $combined_title = function_exists('sywat_format_combined_category_header') 
                                    ? sywat_format_combined_category_header( $group_data['division'], $group_data['gender'], $style_name )
                                    : trim( $group_data['division'] . ' ' . $group_data['gender'] . ' ' . $style_name );
                            ?>
                            <div class="sywat-style-block" data-style="<?php echo esc_attr( $style_slug ); ?>">
                                <div class="sywat-single-category-header">
                                    🎯 <?php echo esc_html( $combined_title ); ?>
                                </div>

                                <div class="sywat-table-responsive">
                                    <table class="sywat-table">
                                        <thead>
                                            <tr>
                                                <th class="sywat-col-center">Place</th>
                                                <th>Archer Name</th>
                                                <th class="sywat-col-center">Score 1</th>
                                                <th class="sywat-col-center">Score 2</th>
                                                <th class="sywat-col-center">Score 3</th>
                                                <th class="sywat-col-center">State</th>
                                                <th class="sywat-col-right">Total Score</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $current_rank   = 1;
                                            $prev_tot_score = null;
                                            $prev_tot_x     = null;

                                            foreach ( $archers as $idx => $a ): 
                                                $tot_s = intval( $a['total_score'] );
                                                $tot_x = intval( $a['total_x_count'] );

                                                if ( $prev_tot_score !== null && $tot_s === $prev_tot_score && $tot_x === $prev_tot_x ) {
                                                    // Tied with previous archer - keep current rank
                                                } else {
                                                    $current_rank = $idx + 1;
                                                }
                                                $prev_tot_score = $tot_s;
                                                $prev_tot_x     = $tot_x;
                                            ?>
                                                <tr class="sywat-standings-row" data-name="<?php echo esc_attr( strtolower( $a['name'] ) ); ?>">
                                                    <td class="sywat-col-center">
                                                        <strong><?php echo $current_rank; ?></strong>
                                                    </td>
                                                    <td><strong><?php echo esc_html( $a['name'] ); ?></strong></td>
                                                    <td class="sywat-col-center"><?php echo sywat_format_score_cell( $a['score_1'] ); ?></td>
                                                    <td class="sywat-col-center"><?php echo sywat_format_score_cell( $a['score_2'] ); ?></td>
                                                    <td class="sywat-col-center"><?php echo sywat_format_score_cell( $a['score_3'] ); ?></td>
                                                    <td class="sywat-col-center"><?php echo sywat_format_score_cell( $a['state_score'] ); ?></td>
                                                    <td class="sywat-col-right sywat-score-cell">
                                                        <strong><?php echo $tot_s . ' / ' . $tot_x . 'X'; ?></strong>
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

            let totalCount = 0;
            let visibleCount = 0;

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

                        totalCount++;

                        if (divMatch && genMatch && styleMatch && searchMatch) {
                            row.style.display = '';
                            blockHasVisibleRow = true;
                            visibleCount++;
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

            const counterEl = document.getElementById('sywat_standings_counter');
            if (counterEl) {
                const isFiltered = (divFilter !== 'all' || genFilter !== 'all' || styleFilter !== 'all' || searchFilter !== '');

                if (!isFiltered || visibleCount === totalCount) {
                    counterEl.classList.remove('is-filtered');
                    counterEl.innerHTML = '🎯 Total Season Shooters: <strong>' + totalCount + '</strong>';
                } else {
                    counterEl.classList.add('is-filtered');
                    counterEl.innerHTML = '🎯 Displaying <strong>' + visibleCount + '</strong> of <strong>' + totalCount + '</strong> Season Shooters';
                }
            }
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
