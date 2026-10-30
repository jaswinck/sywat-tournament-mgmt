<?php
/**
 * Sub-Module: Public Tournament Results View
 * Path: includes/results/results-tournaments.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_public_tournament_results' ) ) {
    function sywat_render_public_tournament_results( $tournament_name ) {
        $raw_scores = sywat_get_tournament_scores( $tournament_name );

        // Group scores by Division + Gender + Style + Archer to separate re-entries into Guest
        $grouped_by_archer_dgs = array();
        foreach ( $raw_scores as $s ) {
            $dgs_key   = $s['division'] . '|||' . $s['gender'] . '|||' . $s['style'];
            $archer_key= ! empty( $s['tfaa_id'] ) ? 'id_' . strtolower( $s['tfaa_id'] ) : 'name_' . strtolower( $s['name'] );
            $grouped_by_archer_dgs[$dgs_key][$archer_key][] = $s;
        }

        $processed_scores = array();

        foreach ( $grouped_by_archer_dgs as $dgs_key => $archers_map ) {
            foreach ( $archers_map as $archer_key => $entries ) {
                // Sort entries: Score DESC, X-Count DESC
                usort( $entries, function( $p, $q ) {
                    if ( $p['score'] === $q['score'] ) {
                        return $q['x_count'] - $p['x_count'];
                    }
                    return $q['score'] - $p['score'];
                });

                // Top score stays in official style
                $processed_scores[] = $entries[0];

                // Lower scores get assigned to 'Guest' style and name gets '[Guest]' appended
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

        ksort($all_divisions);

        // Sort filter styles dropdown by style priority weight
        uksort( $all_styles, function( $a, $b ) {
            $wA = function_exists( 'sywat_get_style_sort_weight' ) ? sywat_get_style_sort_weight( $a ) : 50;
            $wB = function_exists( 'sywat_get_style_sort_weight' ) ? sywat_get_style_sort_weight( $b ) : 50;
            if ( $wA !== $wB ) return $wA - $wB;
            return strcasecmp( $a, $b );
        });

        // Sort Division + Gender groups by Pro-first weight
        uksort( $groups, function( $keyA, $keyB ) {
            list( $divA, $genA ) = explode( '|||', $keyA );
            list( $divB, $genB ) = explode( '|||', $keyB );

            $wA = function_exists('sywat_get_div_gen_sort_weight') ? sywat_get_div_gen_sort_weight( $divA, $genA ) : 50;
            $wB = function_exists('sywat_get_div_gen_sort_weight') ? sywat_get_div_gen_sort_weight( $divB, $genB ) : 50;

            if ( $wA !== $wB ) return $wA - $wB;
            return strcasecmp( $keyA, $keyB );
        });

        // Sort styles inside each group using 11-tier style weight priority
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
        ?>

        <div class="sywat-public-card">
            <div style="margin-bottom: 20px;">
                <a href="?view=tournaments" class="sywat-btn sywat-btn-secondary" style="margin-bottom: 12px; display: inline-flex; align-items: center; gap: 5px;">← Back to Tournament Directory</a>
                <h2 style="margin: 0; font-size: 22px; color: #1e3a8a;">🎯 Results: <?php echo esc_html( $tournament_name ); ?></h2>
            </div>

            <div class="sywat-filter-bar">
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Division</label>
                    <select id="sywat_tourn_filter_div" onchange="sywatApplyTournFilters()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; height: 36px; min-width: 140px;">
                        <option value="all">All Divisions</option>
                        <?php foreach ( $all_divisions as $d_slug => $d_label ): ?>
                            <option value="<?php echo esc_attr( $d_slug ); ?>"><?php echo esc_html( $d_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Gender</label>
                    <select id="sywat_tourn_filter_gen" onchange="sywatApplyTournFilters()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; height: 36px; min-width: 120px;">
                        <option value="all">All Genders</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Style</label>
                    <select id="sywat_tourn_filter_style" onchange="sywatApplyTournFilters()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; height: 36px; min-width: 160px;">
                        <option value="all">All Styles</option>
                        <?php foreach ( $all_styles as $st_slug => $st_label ): ?>
                            <option value="<?php echo esc_attr( $st_slug ); ?>"><?php echo esc_html( $st_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex-grow: 1; min-width: 180px;">
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Search Archer</label>
                    <input type="text" id="sywat_tourn_search" oninput="sywatApplyTournFilters()" placeholder="Type archer name..." style="width:100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; height: 36px; box-sizing:border-box;">
                </div>
                <div>
                    <button type="button" class="sywat-btn sywat-btn-secondary" onclick="sywatResetTournFilters()" style="height: 36px; font-size: 12px;">Reset Filters</button>
                </div>
            </div>

            <?php if ( empty( $groups ) ): ?>
                <p style="color: #646970; font-style: italic; padding: 20px 0;">No scores submitted yet for this tournament.</p>
            <?php else: ?>
                <?php foreach ( $groups as $dg_key => $group_data ): ?>
                    <?php 
                        $card_div_slug = strtolower( trim( preg_replace( '/\s*\([^)]*\)/', '', $group_data['division'] ) ) );
                        $card_gen_slug = strtolower( trim( preg_replace( '/\s*\([^)]*\)/', '', $group_data['gender'] ) ) );
                    ?>
                    <div class="sywat-div-gen-card" data-division="<?php echo esc_attr( $card_div_slug ); ?>" data-gender="<?php echo esc_attr( $card_gen_slug ); ?>">
                        <div class="sywat-div-gen-header">
                            🎯 <?php echo esc_html( $group_data['heading'] ); ?>
                        </div>

                        <div style="padding: 15px;">
                            <?php foreach ( $group_data['styles'] as $style_name => $archers ): ?>
                                <?php $style_slug = strtolower( trim( $style_name ) ); ?>
                                <div class="sywat-style-block" data-style="<?php echo esc_attr( $style_slug ); ?>">
                                    <h4 class="sywat-style-header">
                                        🏹 <?php echo esc_html( $style_name ); ?>
                                    </h4>

                                    <table class="sywat-table" style="margin-top: 0; margin-bottom: 15px;">
                                        <thead>
                                            <tr>
                                                <th style="width: 50px; text-align: center;">Place</th>
                                                <th>Archer Name</th>
                                                <th style="text-align: right; width: 140px;">Score / X</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $archers as $idx => $a ): ?>
                                                <tr class="sywat-tourn-row" data-name="<?php echo esc_attr( strtolower( $a['name'] ) ); ?>">
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
                        </div>
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
