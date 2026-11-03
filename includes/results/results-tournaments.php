<?php
/**
 * Sub-Module: Public Tournament Results View
 * Path: includes/results/results-tournaments.php
 * Author: SYWAT Archery
 * Version: 4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Action Handler: In-Page Quick Edit Archer Record
 */
function sywat_handle_results_quick_edit() {
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_action'] ) && $_POST['sywat_action'] === 'results_quick_edit_archer' ) {
        if ( ! isset( $_POST['sywat_nonce'] ) || ! wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_results_edit_action' ) ) {
            return;
        }

        if ( ! function_exists( 'sywat_can_edit_scores' ) || ! sywat_can_edit_scores() ) {
            return;
        }

        $tourn_name  = sanitize_text_field( $_POST['tournament_name'] );
        $reg_id      = sanitize_text_field( $_POST['reg_id'] );
        $active_slug = sanitize_title( $tourn_name );
        $opt_key     = 'sywat_t_' . $active_slug . '_' . $reg_id;

        $reg = get_option( $opt_key );
        if ( $reg && is_array( $reg ) ) {
            if ( isset( $_POST['archer_name'] ) ) $reg['name']     = sanitize_text_field( $_POST['archer_name'] );
            if ( isset( $_POST['division'] ) )    $reg['division'] = sanitize_text_field( $_POST['division'] );
            if ( isset( $_POST['gender'] ) )      $reg['gender']   = sanitize_text_field( $_POST['gender'] );
            if ( isset( $_POST['style'] ) )       $reg['style']    = sanitize_text_field( $_POST['style'] );
            if ( isset( $_POST['score'] ) )       $reg['score']    = intval( $_POST['score'] );
            if ( isset( $_POST['x_count'] ) )     $reg['x_count']  = intval( $_POST['x_count'] );

            update_option( $opt_key, $reg, false );
            wp_cache_delete( $opt_key, 'options' );
        }
    }
}
add_action( 'init', 'sywat_handle_results_quick_edit' );

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
        <div class="sywat-public-card">
            <div class="sywat-card-header-bar">
                <div>
                    <h2>🎯 Individual Tournament Directory</h2>
                    <div class="sywat-subtitle">
                        Select a completed tournament below to view official scores and category leaderboards.
                    </div>
                </div>
            </div>

            <!-- Directory Filter Bar -->
            <div class="sywat-filter-bar">
                <?php if ( count( $filter_shops ) > 1 ): ?>
                    <div class="sywat-field-group">
                        <label>Host Location / Shop</label>
                        <select id="sywat_dir_shop_filter" onchange="sywatApplyDirectoryFilters()">
                            <option value="all">All Locations</option>
                            <?php foreach ( $filter_shops as $s_id => $s_name ): ?>
                                <option value="<?php echo esc_attr( strtolower( $s_name ) ); ?>"><?php echo esc_html( $s_name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="sywat-field-group">
                    <label>Search Tournament</label>
                    <div class="sywat-search-input-wrapper">
                        <input type="text" id="sywat_dir_search_input" oninput="sywatApplyDirectoryFilters()" placeholder="Type tournament name or range...">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetDirectoryFilters()">Clear</button>
                    </div>
                </div>
            </div>

            <?php if ( empty( $tournaments ) ): ?>
                <p class="sywat-empty-notice">No tournaments configured yet.</p>
            <?php else: ?>
                <div class="sywat-table-responsive">
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th>Tournament Date</th>
                                <th>Tournament Name & Location</th>
                                <th class="sywat-col-center">Shooters</th>
                                <th class="sywat-col-right">Action</th>
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

                                    $raw_scores    = function_exists( 'sywat_get_tournament_scores' ) ? sywat_get_tournament_scores( $t_name ) : array();
                                    $scores_count  = is_array( $raw_scores ) ? count( $raw_scores ) : 0;
                                ?>
                                <tr class="sywat-dir-row" data-name="<?php echo esc_attr( strtolower( $t_name ) ); ?>" data-shop="<?php echo esc_attr( strtolower( $t_shop_name ) ); ?>">
                                    <td>
                                        <strong>📅 <?php echo esc_html( $disp_dates ); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html( $t_name ); ?></strong>
                                        <div class="sywat-shop-location">📍 <?php echo esc_html( $t_shop_name ); ?></div>
                                    </td>
                                    <td class="sywat-col-center">
                                        <span class="sywat-badge <?php echo ($scores_count > 0) ? 'sywat-badge-open' : 'sywat-badge-prereg'; ?>">
                                            👥 <strong><?php echo $scores_count; ?></strong> <?php echo ($scores_count === 1 ? 'Archer' : 'Archers'); ?>
                                        </span>
                                    </td>
                                    <td class="sywat-col-right">
                                        <div class="sywat-table-actions">
                                            <?php if ( $can_edit_scores ): ?>
                                                <a href="/sywat-enter-scores/?selected_tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-edit" title="Jump to Score Entry Desk">Enter Scores</a>
                                            <?php endif; ?>

                                            <?php if ( $scores_count > 0 ): ?>
                                                <a href="?view=tournaments&tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-results">View Results</a>
                                            <?php else: ?>
                                                <span class="sywat-badge sywat-badge-pending" title="No scores entered yet">Pending</span>
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

        <div class="sywat-public-card">
            <div class="sywat-card-header-bar">
                <h2>🎯 Results: <?php echo esc_html( $tournament_name ); ?></h2>
                <a href="?view=tournaments" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back">Back to Directory</a>
            </div>

            <div class="sywat-filter-bar">
                <div class="sywat-field-group">
                    <label>Division</label>
                    <select id="sywat_tourn_filter_div" onchange="sywatApplyTournFilters()">
                        <option value="all">All Divisions</option>
                        <?php foreach ( $all_divisions as $d_slug => $d_label ): ?>
                            <option value="<?php echo esc_attr( $d_slug ); ?>"><?php echo esc_html( $d_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sywat-field-group">
                    <label>Gender</label>
                    <select id="sywat_tourn_filter_gen" onchange="sywatApplyTournFilters()">
                        <option value="all">All Genders</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>

                <div class="sywat-field-group">
                    <label>Style</label>
                    <select id="sywat_tourn_filter_style" onchange="sywatApplyTournFilters()">
                        <option value="all">All Styles</option>
                        <?php foreach ( $all_styles as $st_slug => $st_label ): ?>
                            <option value="<?php echo esc_attr( $st_slug ); ?>"><?php echo esc_html( $st_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sywat-field-group">
                    <label>Search Archer</label>
                    <div class="sywat-search-input-wrapper">
                        <input type="text" id="sywat_tourn_search" oninput="sywatApplyTournFilters()" placeholder="Type archer name...">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetTournFilters()">Reset Filters</button>
                    </div>
                </div>
            </div>

            <div id="sywat_tourn_counter" class="sywat-counter-banner">
                🎯 Total Tournament Shooters: <strong><?php echo $total_tourn_shooters; ?></strong>
            </div>

            <?php if ( empty( $groups ) ): ?>
                <p class="sywat-empty-notice">No scores submitted yet for this tournament.</p>
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
                                                <th class="sywat-col-right">Score / X</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $current_rank = 1;
                                            $prev_score   = null;
                                            $prev_x       = null;

                                            foreach ( $archers as $idx => $a ): 
                                                $s_val = intval( $a['score'] );
                                                $x_val = intval( $a['x_count'] );

                                                if ( $prev_score !== null && $s_val === $prev_score && $x_val === $prev_x ) {
                                                    // Tied with previous archer - keep current rank
                                                } else {
                                                    $current_rank = $idx + 1;
                                                }
                                                $prev_score = $s_val;
                                                $prev_x     = $x_val;

                                                $reg_id     = ! empty( $a['id'] ) ? $a['id'] : '';
                                                $gender_val = ! empty( $a['gender'] ) ? $a['gender'] : ( ! empty( $group_data['gender'] ) ? $group_data['gender'] : 'Male' );
                                            ?>
                                                <tr class="sywat-tourn-row" data-name="<?php echo esc_attr( strtolower( $a['name'] ) ); ?>">
                                                    <td class="sywat-col-center"><strong><?php echo $current_rank; ?></strong></td>
                                                    <td>
                                                        <div class="sywat-archer-cell-content">
                                                            <strong><?php echo esc_html( $a['name'] ); ?></strong>
                                                            <?php if ( $can_edit_scores && ! empty( $reg_id ) ): ?>
                                                                <button type="button" 
                                                                        class="sywat-btn sywat-btn-secondary sywat-btn-icon-edit sywat-btn-sm" 
                                                                        onclick="openResultsEditModal('<?php echo esc_js( $reg_id ); ?>', '<?php echo esc_js( $a['name'] ); ?>', '<?php echo esc_js( $a['division'] ); ?>', '<?php echo esc_js( $gender_val ); ?>', '<?php echo esc_js( $a['style'] ); ?>', <?php echo $s_val; ?>, <?php echo $x_val; ?>)">
                                                                    Edit
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="sywat-col-right sywat-score-cell">
                                                        <strong><?php echo $s_val . ' / ' . $x_val . 'X'; ?></strong>
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

        <!-- In-Page Quick Edit Archer Modal Overlay -->
        <?php if ( $can_edit_scores ): ?>
            <div id="sywat_results_edit_modal" class="sywat-modal-backdrop">
                <div class="sywat-modal-content sywat-modal-sm">
                    <div class="sywat-modal-header">
                        <h3>✏️ Quick Edit Archer Record</h3>
                        <button type="button" class="sywat-modal-close" onclick="closeResultsEditModal()">&times;</button>
                    </div>
                    <form method="post">
                        <?php wp_nonce_field( 'sywat_results_edit_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="results_quick_edit_archer">
                        <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $tournament_name ); ?>">
                        <input type="hidden" name="reg_id" id="edit_modal_reg_id">

                        <div class="sywat-modal-body">
                            <div class="sywat-field-group">
                                <label>Archer Name</label>
                                <input type="text" name="archer_name" id="edit_modal_name" required>
                            </div>

                            <div class="sywat-form-grid-2">
                                <div class="sywat-field-group">
                                    <label>Division</label>
                                    <input type="text" name="division" id="edit_modal_division" required>
                                </div>
                                <div class="sywat-field-group">
                                    <label>Gender</label>
                                    <select name="gender" id="edit_modal_gender" required>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                            </div>

                            <div class="sywat-field-group">
                                <label>Style</label>
                                <input type="text" name="style" id="edit_modal_style" required>
                            </div>

                            <div class="sywat-form-grid-2">
                                <div class="sywat-field-group">
                                    <label>Score (0–300)</label>
                                    <input type="number" name="score" id="edit_modal_score" min="0" max="300" required>
                                </div>
                                <div class="sywat-field-group">
                                    <label>X Count (0–60)</label>
                                    <input type="number" name="x_count" id="edit_modal_x_count" min="0" max="60" required>
                                </div>
                            </div>
                        </div>

                        <div class="sywat-modal-footer">
                            <button type="button" class="sywat-btn sywat-btn-secondary" onclick="closeResultsEditModal()">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            function openResultsEditModal(regId, name, division, gender, style, score, xCount) {
                document.getElementById('edit_modal_reg_id').value   = regId;
                document.getElementById('edit_modal_name').value     = name;
                document.getElementById('edit_modal_division').value = division;
                
                const genderSelect = document.getElementById('edit_modal_gender');
                if (genderSelect) {
                    genderSelect.value = (gender && gender.toLowerCase().includes('female')) ? 'Female' : 'Male';
                }

                document.getElementById('edit_modal_style').value    = style;
                document.getElementById('edit_modal_score').value    = score;
                document.getElementById('edit_modal_x_count').value  = xCount;

                const modal = document.getElementById('sywat_results_edit_modal');
                if (modal) {
                    modal.classList.add('active');
                }
            }

            function closeResultsEditModal() {
                const modal = document.getElementById('sywat_results_edit_modal');
                if (modal) {
                    modal.classList.remove('active');
                }
            }
            </script>
        <?php endif; ?>

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

            const counterEl = document.getElementById('sywat_tourn_counter');
            if (counterEl) {
                const isFiltered = (divFilter !== 'all' || genFilter !== 'all' || styleFilter !== 'all' || searchFilter !== '');

                if (!isFiltered || visibleCount === totalCount) {
                    counterEl.classList.remove('is-filtered');
                    counterEl.innerHTML = '🎯 Total Tournament Shooters: <strong>' + totalCount + '</strong>';
                } else {
                    counterEl.classList.add('is-filtered');
                    counterEl.innerHTML = '🎯 Displaying <strong>' + visibleCount + '</strong> of <strong>' + totalCount + '</strong> Archers';
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
