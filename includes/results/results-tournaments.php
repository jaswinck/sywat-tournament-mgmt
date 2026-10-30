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

        $groups = array();
        $all_styles = array();

        foreach ( $scores as $s ) {
            $div_gen_key = $s['division'] . '|||' . $s['gender'];
            $style_key   = $s['style'];
            $all_styles[$style_key] = $style_key;

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

        ksort($all_styles);

        uksort( $groups, function( $keyA, $keyB ) {
            list( $divA, $genA ) = explode( '|||', $keyA );
            list( $divB, $genB ) = explode( '|||', $keyB );

            $wA = function_exists('sywat_get_div_gen_sort_weight') ? sywat_get_div_gen_sort_weight( $divA, $genA ) : 50;
            $wB = function_exists('sywat_get_div_gen_sort_weight') ? sywat_get_div_gen_sort_weight( $divB, $genB ) : 50;

            if ( $wA !== $wB ) return $wA - $wB;
            return strcasecmp( $keyA, $keyB );
        });

        foreach ( $groups as $dg_key => &$group ) {
            ksort( $group['styles'] );
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
                <h2 style="margin: 0; font-size: 22px; color: #0f172a;">🎯 Results: <?php echo esc_html( $tournament_name ); ?></h2>
            </div>

            <div class="sywat-filter-bar">
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px;">Division</label>
                    <select id="sywat_tourn_filter_div" onchange="sywatApplyTournFilters()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; height: 36px; min-width: 140px;">
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
                        <?php foreach ( $all_styles as $st ): ?>
                            <option value="<?php echo esc_attr( strtolower( $st ) ); ?>"><?php echo esc_html( $st ); ?></option>
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
                    <div class="sywat-tourn-div-card" data-division="<?php echo esc_attr( strtolower( $group_data['division'] ) ); ?>" data-gender="<?php echo esc_attr( strtolower( $group_data['gender'] ) ); ?>">
                        <div class="sywat-div-gen-header" style="background: #0f172a;">
                            🎯 <?php echo esc_html( $group_data['heading'] ); ?>
                        </div>

                        <div style="padding: 15px;">
                            <?php foreach ( $group_data['styles'] as $style_name => $archers ): ?>
                                <div class="sywat-tourn-style-block" data-style="<?php echo esc_attr( strtolower( $style_name ) ); ?>">
                                    <h4 class="sywat-style-header" style="border-left-color: #0f172a;">
                                        <span>🏆 <?php echo esc_html( $style_name ); ?></span>
                                        <span class="sywat-style-header-tag" style="background: #0f172a;">Style Category</span>
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
            const divFilter   = document.getElementById('sywat_tourn_filter_div').value.toLowerCase();
            const genFilter   = document.getElementById('sywat_tourn_filter_gen').value.toLowerCase();
            const styleFilter = document.getElementById('sywat_tourn_filter_style').value.toLowerCase();
            const searchFilter= document.getElementById('sywat_tourn_search').value.toLowerCase().trim();

            const cards = document.querySelectorAll('.sywat-tourn-div-card');

            cards.forEach(card => {
                const cardDiv = (card.getAttribute('data-division') || '').toLowerCase();
                const cardGen = (card.getAttribute('data-gender') || '').toLowerCase();

                const divMatch = (divFilter === 'all' || cardDiv.includes(divFilter));
                const genMatch = (genFilter === 'all' || cardGen.includes(genFilter));

                let cardHasVisibleBlock = false;
                const styleBlocks = card.querySelectorAll('.sywat-tourn-style-block');

                styleBlocks.forEach(block => {
                    const blockStyle = (block.getAttribute('data-style') || '').toLowerCase();
                    const styleMatch = (styleFilter === 'all' || blockStyle.includes(styleFilter));

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
