<?php
/**
 * Sub-Module: Public Tournament Results View
 * Path: includes/results/results-tournaments.php
 * Author: SYWAT Archery
 * Version: 3.6.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render Public Tournament Directory Listing with Shop Search & Turnout Stats
 */
if ( ! function_exists( 'sywat_render_public_tournament_directory' ) ) {
    function sywat_render_public_tournament_directory() {
        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        $all_shops = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
        $filter_shops = array();

        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
            if ( isset( $all_shops[$t_shop]['name'] ) ) {
                $filter_shops[$t_shop] = $all_shops[$t_shop]['name'];
            }
        }
        asort( $filter_shops );

        // Sort tournaments chronologically by start_date ASC (Oldest to Newest)
        uasort( $tournaments, function( $a, $b ) {
            $a_date = ( is_array( $a ) && ! empty( $a['start_date'] ) ) ? $a['start_date'] : '';
            $b_date = ( is_array( $b ) && ! empty( $b['start_date'] ) ) ? $b['start_date'] : '';
            if ( $a_date === $b_date ) return 0;
            return ( $a_date < $b_date ) ? -1 : 1;
        });

        $can_edit_scores = function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores();
        ?>
        <div class="sywat-public-card" style="border-top-left-radius:0;">
            <div style="margin-bottom: 16px;">
                <h2 style="margin: 0; font-size: 20px; color: #0f172a;">🎯 Individual Tournament Directory</h2>
                <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                    Select a completed tournament below to view official scores and category leaderboards.
                </div>
            </div>

            <!-- Directory Filter Bar -->
            <div class="sywat-filter-bar" style="margin-bottom: 20px; display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                <?php if ( count( $filter_shops ) > 1 ): ?>
                    <div class="sywat-field-group" style="flex: 1 1 180px; min-width: 160px; margin: 0;">
                        <label style="display: block; font-weight: 700; font-size: 12px; color: #1e293b; margin-bottom: 4px;">Host Location / Shop</label>
                        <select id="sywat_dir_shop_filter" onchange="sywatApplyDirectoryFilters()" style="width: 100%; height: 38px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; margin: 0;">
                            <option value="all">All Locations</option>
                            <?php foreach ( $filter_shops as $s_id => $s_name ): ?>
                                <option value="<?php echo esc_attr( strtolower( $s_name ) ); ?>"><?php echo esc_html( $s_name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="sywat-field-group" style="flex: 2 1 260px; min-width: 220px; margin: 0;">
                    <label style="display: block; font-weight: 700; font-size: 12px; color: #1e293b; margin-bottom: 4px;">Search Tournament</label>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <input type="text" id="sywat_dir_search_input" oninput="sywatApplyDirectoryFilters()" placeholder="Type tournament name or range..." style="flex: 1; height: 38px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; margin: 0;">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetDirectoryFilters()" style="height: 38px; font-size: 13px; padding: 4px 12px; margin: 0; white-space: nowrap;">Clear</button>
                    </div>
                </div>
            </div>

            <?php if ( empty( $tournaments ) ): ?>
                <p style="color: #646970; font-style: italic; padding: 20px 0;">No tournaments configured yet.</p>
            <?php else: ?>
                <div class="sywat-table-responsive" style="margin-top: 10px;">
                    <table class="sywat-table" style="margin-top: 0;">
                        <thead>
                            <tr>
                                <th style="width: 22%;">Tournament Date</th>
                                <th style="width: 38%;">Tournament Name & Location</th>
                                <th style="width: 15%; text-align: center;">Shooters</th>
                                <th style="width: 25%; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $tournaments as $t_name => $t_data ): ?>
                                <?php 
                                    $s_date        = ( is_array( $t_data ) && isset( $t_data['start_date'] ) ) ? $t_data['start_date'] : '';
                                    $e_date        = ( is_array( $t_data ) && isset( $t_data['end_date'] ) ) ? $t_data['end_date'] : '';
                                    $disp_dates    = function_exists( 'sywat_format_tournament_dates' ) ? sywat_format_tournament_dates( $s_date, $e_date ) : '';
                                    if ( empty( $disp_dates ) ) {
                                        $disp_dates = get_option( 'sywat_dates_' . sanitize_title( $t_name ), '—' );
                                    }

                                    $t_shop_id     = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
                                    $t_shop_name   = isset( $all_shops[$t_shop_id]['name'] ) ? $all_shops[$t_shop_id]['name'] : 'Cinnamon Creek Ranch';

                                    // Calculate recorded shooter count for conditional View Results button
                                    $raw_scores    = function_exists( 'sywat_get_tournament_scores' ) ? sywat_get_tournament_scores( $t_name ) : array();
                                    $scores_count  = is_array( $raw_scores ) ? count( $raw_scores ) : 0;
                                ?>
                                <tr class="sywat-dir-row" data-name="<?php echo esc_attr( strtolower( $t_name ) ); ?>" data-shop="<?php echo esc_attr( strtolower( $t_shop_name ) ); ?>">
                                    <td>
                                        <strong style="font-size: 14px; color: #0f172a; font-weight: 700;">📅 <?php echo esc_html( $disp_dates ); ?></strong>
                                    </td>
                                    <td>
                                        <strong style="font-size: 15px; color: #0f172a; font-weight: 700;"><?php echo esc_html( $t_name ); ?></strong>
                                        <div style="font-size: 12px; color: #0284c7; font-weight: 600; margin-top: 2px;">📍 <?php echo esc_html( $t_shop_name ); ?></div>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="sywat-badge <?php echo ($scores_count > 0) ? 'sywat-badge-open' : 'sywat-badge-prereg'; ?>" style="font-size: 12px; padding: 4px 10px;">
                                            👥 <strong><?php echo $scores_count; ?></strong> <?php echo ($scores_count === 1 ? 'Archer' : 'Archers'); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">
                                            <?php if ( $can_edit_scores ): ?>
                                                <a href="/sywat-enter-scores/?selected_tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-edit" style="font-size: 12px; height: 32px;" title="Jump to Score Entry Desk">Enter Scores</a>
                                            <?php endif; ?>

                                            <?php if ( $scores_count > 0 ): ?>
                                                <a href="?view=tournaments&tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-results" style="font-size: 12px; height: 32px;">View Results</a>
                                            <?php else: ?>
                                                <span class="sywat-badge" style="background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 6px 12px; font-size: 12px;" title="No scores entered yet">Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <script>
        function sywatApplyDirectoryFilters() {
            const shopSelect   = document.getElementById('sywat_dir_shop_filter');
            const searchInput  = document.getElementById('sywat_dir_search_input');

            const shopFilter   = shopSelect ? shopSelect.value.toLowerCase().trim() : 'all';
            const searchFilter = searchInput ? searchInput.value.toLowerCase().trim() : '';

            const rows = document.querySelectorAll('.sywat-dir-row');

            rows.forEach(row => {
                const name = (row.getAttribute('data-name') || '').toLowerCase();
                const shop = (row.getAttribute('data-shop') || '').toLowerCase();

                const shopMatch   = (shopFilter === 'all' || shop === shopFilter);
                const searchMatch = (!searchFilter || name.includes(searchFilter) || shop.includes(searchFilter));

                row.style.display = (shopMatch && searchMatch) ? '' : 'none';
            });
        }

        function sywatResetDirectoryFilters() {
            const shopSelect  = document.getElementById('sywat_dir_shop_filter');
            const searchInput = document.getElementById('sywat_dir_search_input');
            if (shopSelect) shopSelect.value = 'all';
            if (searchInput) searchInput.value = '';
            sywatApplyDirectoryFilters();
        }
        </script>
        <?php
    }
}

/**
 * Render Single Tournament Results Breakdown
 */
if ( ! function_exists( 'sywat_render_public_tournament_results' ) ) {
    function sywat_render_public_tournament_results( $tournament_name ) {
        $raw_scores = sywat_get_tournament_scores( $tournament_name );

        $grouped_by_archer_dgs = array();
        foreach ( $raw_scores as $s ) {
            $dgs_key   = $s['division'] . '|||' . $s['gender'] . '|||' . $s['style'];
            $archer_key= ! empty( $s['tfaa_id'] ) ? 'id_' . strtolower( $s['tfaa_id'] ) : 'name_' . strtolower( $s['name'] );
            $grouped_by_archer_dgs[$dgs_key][$archer_key][] = $s;
        }

        $processed_scores = array();

        foreach ( $grouped_by_archer_dgs as $dgs_key => $archers_map ) {
            foreach ( $archers_map as $archer_key => $entries ) {
                usort( $entries, function( $p, $q ) {
                    if ( $p['score'] === $q['score'] ) {
                        return $q['x_count'] - $p['x_count'];
                    }
                    return $q['score'] - $p['score'];
                });

                // If top score is 0, archer turned in a card but didn't record a score -> convert all entries to Guest
                if ( intval( $entries[0]['score'] ) === 0 ) {
                    foreach ( $entries as $zero_entry ) {
                        $guest_entry = $zero_entry;
                        $guest_entry['style'] = 'Guest';
                        if ( strpos( $guest_entry['name'], '[Guest]' ) === false ) {
                            $guest_entry['name'] .= ' [Guest]';
                        }
                        $processed_scores[] = $guest_entry;
                    }
                } else {
                    $processed_scores[] = $entries[0];

                    for ( $i = 1; $i < count( $entries ); $i++ ) {
                        $guest_entry = $entries[$i];
                        $guest_entry['style'] = 'Guest';
                        if ( strpos( $guest_entry['name'], '[Guest]' ) === false ) {
                            $guest_entry['name'] .= ' [Guest]';
                        }
                        $processed_scores[] = $guest_entry;
                    }
                }
            }
        }

        $groups        = array();
        $all_divisions = array();
        $all_styles    = array();

        foreach ( $processed_scores as $s ) {
            $div_gen_key = $s['division'] . '|||' . $s['gender'];
            $style_key   = $s['style'];

            $clean_d = trim( preg_replace( '/\s*\([^)]*\)/', '', $s['division'] ) );
            if ( ! empty( $clean_d ) ) {
                $all_divisions[strtolower( $clean_d )] = $clean_d;
            }
            $all_styles[strtolower( trim( $style_key ) )] = trim( $style_key );

            if ( ! isset( $groups[$div_gen_key] ) ) {
                $groups[$div_gen_key] = array(
                    'division' => $s['division'],
                    'gender'   => $s['gender'],
                    'heading'  => function_exists('sywat_format_div_gen_heading') ? sywat_format_div_gen_heading( $s['division'], $s['gender'] ) : $s['division'] . ' ' . $s['gender'],
                    'styles'   => array(),
                );
            }

            $groups[$div_gen_key]['styles'][$style_key][] = $s;
        }

        $total_tourn_shooters = count( $processed_scores );

        ksort($all_divisions);

        uksort( $all_styles, function( $a, $b ) {
            $wA = function_exists( 'sywat_get_style_sort_weight' ) ? sywat_get_style_sort_weight( $a ) : 50;
            $wB = function_exists( 'sywat_get_style_sort_weight' ) ? sywat_get_style_sort_weight( $b ) : 50;
            if ( $wA !== $wB ) return $wA - $wB;
            return strcasecmp( $a, $b );
        });

        uksort( $groups, function( $keyA, $keyB ) {
            list( $divA, $genA ) = explode( '|||', $keyA );
            list( $divB, $genB ) = explode( '|||', $keyB );

            $wA = function_exists('sywat_get_div_gen_sort_weight') ? sywat_get_div_gen_sort_weight( $divA, $genA ) : 50;
            $wB = function_exists('sywat_get_div_gen_sort_weight') ? sywat_get_div_gen_sort_weight( $divB, $genB ) : 50;

            if ( $wA !== $wB ) return $wA - $wB;
            return strcasecmp( $keyA, $keyB );
        });

        foreach ( $groups as $dg_key => &$group ) {
            uksort( $group['styles'], function( $stA, $stB ) {
                $wA = function_exists( 'sywat_get_style_sort_weight' ) ? sywat_get_style_sort_weight( $stA ) : 50;
                $wB = function_exists( 'sywat_get_style_sort_weight' ) ? sywat_get_style_sort_weight( $stB ) : 50;
                if ( $wA !== $wB ) return $wA - $wB;
                return strcasecmp( $stA, $stB );
            });

            foreach ( $group['styles'] as $st_key => &$archers ) {
                usort( $archers, function( $a, $b ) {
                    if ( $a['score'] === $b['score'] ) {
                        return $b['x_count'] - $a['x_count'];
                    }
                    return $b['score'] - $a['score'];
                });
            }
            unset($archers);
        }
        unset($group);

        $can_edit_scores = function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores();
        ?>

        <div class="sywat-public-card" style="border-top-left-radius:0;">
            <!-- Clean Header: Results Title Left, Back Button Right -->
            <div style="display:flex; justify-content:space-between; align-items:center; gap:15px; margin-bottom:20px; flex-wrap:wrap;">
                <h2 style="margin: 0; font-size: 22px; color: #1e3a8a;">🎯 Results: <?php echo esc_html( $tournament_name ); ?></h2>
                <a href="?view=tournaments" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back" style="height: 36px; padding: 0 14px; display: inline-flex; align-items: center; gap: 6px; line-height: 1; font-weight: 600; text-decoration: none;">Back to Directory</a>
            </div>

            <div class="sywat-filter-bar" style="margin-bottom: 20px; display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                <div class="sywat-field-group" style="flex: 1 1 140px; min-width: 130px; margin: 0;">
                    <label style="display: block; font-weight: 700; font-size: 12px; color: #1e293b; margin-bottom: 4px;">Division</label>
                    <select id="sywat_tourn_filter_div" onchange="sywatApplyTournFilters()" style="width: 100%; height: 38px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; margin: 0;">
                        <option value="all">All Divisions</option>
                        <?php foreach ( $all_divisions as $d_slug => $d_label ): ?>
                            <option value="<?php echo esc_attr( $d_slug ); ?>"><?php echo esc_html( $d_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sywat-field-group" style="flex: 1 1 120px; min-width: 110px; margin: 0;">
                    <label style="display: block; font-weight: 700; font-size: 12px; color: #1e293b; margin-bottom: 4px;">Gender</label>
                    <select id="sywat_tourn_filter_gen" onchange="sywatApplyTournFilters()" style="width: 100%; height: 38px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; margin: 0;">
                        <option value="all">All Genders</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>

                <div class="sywat-field-group" style="flex: 1 1 160px; min-width: 150px; margin: 0;">
                    <label style="display: block; font-weight: 700; font-size: 12px; color: #1e293b; margin-bottom: 4px;">Style</label>
                    <select id="sywat_tourn_filter_style" onchange="sywatApplyTournFilters()" style="width: 100%; height: 38px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; margin: 0;">
                        <option value="all">All Styles</option>
                        <?php foreach ( $all_styles as $st_slug => $st_label ): ?>
                            <option value="<?php echo esc_attr( $st_slug ); ?>"><?php echo esc_html( $st_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sywat-field-group" style="flex: 2 1 240px; min-width: 200px; margin: 0;">
                    <label style="display: block; font-weight: 700; font-size: 12px; color: #1e293b; margin-bottom: 4px;">Search Archer</label>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <input type="text" id="sywat_tourn_search" oninput="sywatApplyTournFilters()" placeholder="Type archer name..." style="flex: 1; height: 38px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; margin: 0;">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetTournFilters()" style="height: 38px; font-size: 13px; padding: 4px 12px; margin: 0; white-space: nowrap;">Reset Filters</button>
                    </div>
                </div>
            </div>

            <!-- DYNAMIC PARTICIPATION STAT BANNER -->
            <div id="sywat_tourn_counter" class="sywat-counter-banner">
                🎯 Total Tournament Shooters: <strong><?php echo $total_tourn_shooters; ?></strong>
            </div>

            <?php if ( empty( $groups ) ): ?>
                <p style="color: #646970; font-style: italic; padding: 20px 0;">No scores submitted yet for this tournament.</p>
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
                                                <th style="width: 50px; text-align: center;">Place</th>
                                                <th>Archer Name</th>
                                                <th style="text-align: right; width: 140px;">Score / X</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $archers as $idx => $a ): ?>
                                                <?php $archer_identifier = ! empty( $a['id'] ) ? $a['id'] : $a['name']; ?>
                                                <tr class="sywat-tourn-row" data-name="<?php echo esc_attr( strtolower( $a['name'] ) ); ?>">
                                                    <td style="text-align: center; font-weight: bold;"><?php echo ($idx + 1); ?></td>
                                                    <td>
                                                        <strong style="font-size: 14px; color: #0f172a;"><?php echo esc_html( $a['name'] ); ?></strong>
                                                        <?php if ( $can_edit_scores ): ?>
                                                            <a href="/sywat-enter-scores/?selected_tournament=<?php echo urlencode( $tournament_name ); ?>&edit_archer=<?php echo urlencode( $archer_identifier ); ?>" 
                                                               class="sywat-btn-edit-shortcut" 
                                                               style="font-size: 11px; padding: 2px 6px; margin-left: 8px; text-decoration: none; background: #2271b1; color: #ffffff; border-radius: 3px; display: inline-block; font-weight: 600;" 
                                                               title="Edit Archer Score & Details">
                                                                ✏️ Edit
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: right; font-size: 15px; color: #0f172a;">
                                                        <strong><?php echo intval( $a['score'] ) . ' / ' . intval( $a['x_count'] ) . 'X'; ?></strong>
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
        function sywatApplyTournFilters() {
            const divFilter   = document.getElementById('sywat_tourn_filter_div').value.toLowerCase().trim();
            const genFilter   = document.getElementById('sywat_tourn_filter_gen').value.toLowerCase().trim();
            const styleFilter = document.getElementById('sywat_tourn_filter_style').value.toLowerCase().trim();
            const searchFilter= document.getElementById('sywat_tourn_search').value.toLowerCase().trim();

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
                    const rows = block.querySelectorAll('.sywat-tourn-row');

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

            // Update Counter Banner
            const counterEl = document.getElementById('sywat_tourn_counter');
            if (counterEl) {
                const isFiltered = (divFilter !== 'all' || genFilter !== 'all' || styleFilter !== 'all' || searchFilter !== '');

                if (!isFiltered || visibleCount === totalCount) {
                    counterEl.style.background  = '#eff6ff';
                    counterEl.style.borderColor = '#bfdbfe';
                    counterEl.style.color       = '#1e40af';
                    counterEl.innerHTML         = '🎯 Total Tournament Shooters: <strong>' + totalCount + '</strong>';
                } else {
                    counterEl.style.background  = '#dcfce7';
                    counterEl.style.borderColor = '#86efac';
                    counterEl.style.color       = '#166534';
                    counterEl.innerHTML         = '🎯 Displaying <strong>' + visibleCount + '</strong> of <strong>' + totalCount + '</strong> Archers';
                }
            }
        }

        function sywatResetTournFilters() {
            document.getElementById('sywat_tourn_filter_div').value = 'all';
            document.getElementById('sywat_tourn_filter_gen').value = 'all';
            document.getElementById('sywat_tourn_filter_style').value = 'all';
            document.getElementById('sywat_tourn_search').value = '';
            sywatApplyTournFilters();
        }
        </script>
        <?php
    }
}
