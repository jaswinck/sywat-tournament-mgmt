<?php
/**
 * Sub-Module: Scores Engine Visual Views & Shortcode Renderer
 * Path: includes/scores/scores-views.php
 * Description: Pure presentation renderer for the Batch Score Entry application ([sywat_scores]), consuming pre-scoped active-season/non-historical tournament context and O(R) line score metrics from includes/scores/scores-data.php and pre-formatted surname-sorted rows from includes/scores/scores-helpers.php. Renders role-gated desk navigation tabs, canonical shooting line labels, line score tracker cards matching Check-In's sibling-form side-by-side / full-width locked button layout (hiding Enter Scores when locked), surname-sorted ("LastName, FirstName") archer score entry table with pre-indexed live search tokens followed by a single-button Unentered / Show All view toggle, per-archer "INC" incomplete-round toggle, isolated uncommitted-only Save Scores trigger, always-visible admin Export CSV trigger with locked-line validation, responsive mobile card labels, and optimizer-safe delegated control toolbars.
 * Compliance: Pure view layer — zero database queries, zero option/meta lookups, zero output at include time, zero inline styles, zero inline JS attributes (data-sywat-action delegation only), filemtime() cache-busted external JS asset, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 8.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'render_sywat_scores_app' ) ) {
    /**
     * Main Shortcode Renderer for Front-End Score Application ([sywat_scores]).
     *
     * @return string HTML rendered view buffer output.
     */
    function render_sywat_scores_app() {
        $ctx = function_exists( 'sywat_scores_get_context' ) ? sywat_scores_get_context() : array();

        if ( empty( $ctx['can_edit'] ) ) {
            return '<p class="sywat-alert sywat-alert-danger">Access denied. Staff access required.</p>';
        }

        ob_start();

        $is_admin               = ! empty( $ctx['is_admin'] );
        $can_manage_tournaments = ! empty( $ctx['can_manage_tournaments'] );
        $can_register_shooters  = ! empty( $ctx['can_register_shooters'] );
        $can_access_desk        = ! empty( $ctx['can_access_desk'] );
        $can_manage_seasons     = ! empty( $ctx['can_manage_seasons'] );
        $tournaments            = isset( $ctx['tournaments'] ) && is_array( $ctx['tournaments'] ) ? $ctx['tournaments'] : array();
        $selected_tournament    = isset( $ctx['selected_tournament'] ) ? (string) $ctx['selected_tournament'] : '';
        $selected_line_idx      = isset( $ctx['selected_line_idx'] ) ? (string) $ctx['selected_line_idx'] : 'all';
        $search_query           = isset( $ctx['search_query'] ) ? (string) $ctx['search_query'] : '';
        $lines                  = isset( $ctx['lines'] ) && is_array( $ctx['lines'] ) ? $ctx['lines'] : array();
        $locked_lines           = isset( $ctx['locked_lines'] ) && is_array( $ctx['locked_lines'] ) ? $ctx['locked_lines'] : array();
        $current_view_locked    = ! empty( $ctx['current_view_locked'] );
        $is_hub_enabled         = ! empty( $ctx['is_hub_enabled'] );

        // Execute Action Handlers (Line Lock Toggle / CSV Export Guard) prior to loading table records
        $notice = '';
        if ( function_exists( 'sywat_scores_handle_batch_actions' ) ) {
            $notice = sywat_scores_handle_batch_actions( $selected_tournament, $selected_line_idx, $is_admin, $locked_lines, $current_view_locked );
        }

        // Fetch pre-scoped archer records and O(R) line tracker counts from Data Layer
        $records_bundle = function_exists( 'sywat_scores_get_records_and_tracker' )
            ? sywat_scores_get_records_and_tracker( $selected_tournament, $selected_line_idx, $lines, $locked_lines )
            : array(
                'scores_map'          => array(),
                'tourn_registrations' => array(),
                'tracker_lines'       => array(),
            );

        $scores_map          = $records_bundle['scores_map'];
        $tourn_registrations = $records_bundle['tourn_registrations'];
        $tracker_lines       = $records_bundle['tracker_lines'];

        // Enrich & sort rows via pure presentation helper layer
        $prepared_rows = function_exists( 'sywat_scores_prepare_rows' )
            ? sywat_scores_prepare_rows( $tourn_registrations, $scores_map, $lines, $locked_lines )
            : $tourn_registrations;

        $clean_search_init       = strtolower( trim( $search_query ) );
        $initial_visible_count   = 0;
        $initial_unentered_count = 0;

        foreach ( $prepared_rows as $reg ) {
            $rd          = isset( $reg['row_display'] ) && is_array( $reg['row_display'] ) ? $reg['row_display'] : array();
            $r_line      = isset( $rd['line_idx'] ) ? (string) $rd['line_idx'] : ( isset( $reg['resolved_line_idx'] ) ? (string) $reg['resolved_line_idx'] : '0' );
            $r_tokens    = isset( $rd['search_tokens'] ) ? (string) $rd['search_tokens'] : strtolower( trim( (string) ( isset( $reg['name'] ) ? $reg['name'] : '' ) ) );
            $r_score     = isset( $rd['score'] ) ? intval( $rd['score'] ) : 0;
            $r_xs        = isset( $rd['x_count'] ) ? intval( $rd['x_count'] ) : 0;
            $r_inc       = ! empty( $rd['is_inc'] );
            $r_unentered = ( $r_score === 0 && $r_xs === 0 && ! $r_inc );
            $line_ok     = ( $selected_line_idx === 'all' || $selected_line_idx === '' || $r_line === $selected_line_idx );
            $query_ok    = ( $clean_search_init === '' || strpos( $r_tokens, $clean_search_init ) !== false );

            if ( $line_ok && $query_ok ) {
                $initial_visible_count++;
                if ( $r_unentered ) {
                    $initial_unentered_count++;
                }
            }
        }

        // Enqueue client score interactive JavaScript asset with filemtime cache-busting
        $scores_js_rel  = 'assets/js/sywat-scores.js';
        $scores_js_path = defined( 'SYWAT_PLUGIN_DIR' ) ? SYWAT_PLUGIN_DIR . $scores_js_rel : '';
        $scores_js_ver  = ( $scores_js_path !== '' && file_exists( $scores_js_path ) ) ? (string) filemtime( $scores_js_path ) : SYWAT_PLUGIN_VERSION;
        wp_enqueue_script( 'sywat-scores-js', SYWAT_PLUGIN_URL . $scores_js_rel, array(), $scores_js_ver, true );

        $tab_query_args = ! empty( $selected_tournament ) ? array( 'selected_tournament' => $selected_tournament ) : array();
        ?>

        <div class="sywat-wrap">
            <div id="sywat_scores_inline_notice"><?php echo $notice; ?></div>

            <!-- Master Navigation Tabs (Card-Docked, Role-Gated) -->
            <div class="sywat-nav-tabs no-print">
                <?php if ( $can_manage_tournaments ) : ?>
                    <a href="<?php echo esc_url( sywat_page_url( 'manage_tournaments' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">Manage Tournaments</a>
                <?php endif; ?>
                <?php if ( $can_register_shooters ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( $tab_query_args, sywat_page_url( 'register' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-register">Register Shooters</a>
                <?php endif; ?>
                <?php if ( $can_access_desk ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( $tab_query_args, sywat_page_url( 'desk' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-checkin">Check In Archers</a>
                <?php endif; ?>
                <a href="<?php echo esc_url( add_query_arg( $tab_query_args, sywat_page_url( 'enter_scores' ) ) ); ?>" class="sywat-tab-item active sywat-tab-icon-edit">Enter Scores</a>
                <?php if ( $can_manage_seasons ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', 'seasons', sywat_page_url( 'manage_tournaments' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-season">Manage Seasons</a>
                <?php endif; ?>
            </div>

            <!-- Master Single Unified Desk Card -->
            <div class="sywat-card no-print">
                <!-- Integrated Control Panel Filter Toolbar Header Bar -->
                <div class="sywat-card-header-bar">
                    <div>
                        <h2>✏️ Enter Scores</h2>
                        <div class="sywat-subtitle">Select an active tournament and line to record or unlock shooter scores.</div>
                    </div>

                    <div class="sywat-header-inline-controls">
                        <form method="post" id="sywat_score_filter_form" class="sywat-filter-bar-form">
                            <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $search_query ); ?>">
                            <input type="hidden" id="sywat_nonce" value="<?php echo esc_attr( wp_create_nonce( 'sywat_scores_action' ) ); ?>">

                            <!-- Stacked Active Tournament Dropdown -->
                            <div class="sywat-field-group sywat-field-auto">
                                <label for="selected_tournament_val">Active Tournament:</label>
                                <select name="selected_tournament" id="selected_tournament_val" class="sywat-select-tournament" data-sywat-action="submit-scores-tournament">
                                    <?php foreach ( $tournaments as $t_name => $t_lines ) : ?>
                                        <option value="<?php echo esc_attr( $t_name ); ?>" <?php selected( $selected_tournament, $t_name ); ?>>
                                            <?php echo esc_html( $t_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Stacked Shooting Line Dropdown (Instant Client-Side Line Filter) -->
                            <div class="sywat-field-group sywat-field-auto">
                                <label for="selected_line_val">Shooting Line:</label>
                                <select name="selected_line_idx" id="selected_line_val" class="sywat-select-line" data-sywat-action="filter-scores-line">
                                    <option value="all" <?php selected( $selected_line_idx, 'all' ); ?>>All Lines (Entire Weekend)</option>
                                    <?php foreach ( $lines as $idx => $line ) : ?>
                                        <?php
                                            $opt_line_label = function_exists( 'sywat_scores_resolve_line_label' )
                                                ? sywat_scores_resolve_line_label( $line, $idx )
                                                : ( ! empty( $line['name'] ) ? $line['name'] : 'Line ' . ( intval( $idx ) + 1 ) );
                                        ?>
                                        <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $selected_line_idx, (string) $idx ); ?>>
                                            <?php echo esc_html( $opt_line_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>

                        <?php if ( $is_hub_enabled ) : ?>
                            <a href="<?php echo esc_url( sywat_page_url( 'volunteer_hub' ) ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back">Back to Hub</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Nested Section: Line Score Tracker -->
                <div class="sywat-section-spacing">
                    <div class="sywat-table-header-bar">
                        <h2>Line Score Tracker</h2>
                    </div>
                    <?php if ( ! empty( $tracker_lines ) ) : ?>
                        <div class="sywat-tracker-grid">
                            <?php foreach ( $tracker_lines as $item ) : ?>
                                <?php
                                    $idx                 = (string) $item['idx'];
                                    $line_title          = function_exists( 'sywat_scores_resolve_line_label' )
                                        ? sywat_scores_resolve_line_label( $item['line'], $idx )
                                        : ( ! empty( $item['line']['name'] ) ? $item['line']['name'] : 'Line ' . ( intval( $idx ) + 1 ) );
                                    $total_line_shooters = intval( $item['total'] );
                                    $scores_entered_line = intval( $item['entered'] );
                                    $is_line_locked      = ! empty( $item['is_locked'] );
                                    $is_complete         = ! empty( $item['is_complete'] );

                                    if ( $is_line_locked ) {
                                        $status_badge = '<span class="sywat-badge sywat-badge-full">🔒 Locked</span>';
                                    } elseif ( $is_complete ) {
                                        $status_badge = '<span class="sywat-badge sywat-badge-open">✅ Complete</span>';
                                    } else {
                                        $pending      = intval( $item['pending'] );
                                        $status_badge = '<span class="sywat-badge sywat-badge-prereg">' . $pending . ' Pending</span>';
                                    }
                                ?>
                                <div class="sywat-tracker-card"
                                     data-line-idx="<?php echo esc_attr( $idx ); ?>"
                                     data-total="<?php echo intval( $total_line_shooters ); ?>"
                                     data-is-locked="<?php echo $is_line_locked ? '1' : '0'; ?>">
                                    <div>
                                        <div class="sywat-tracker-card-header">
                                            <strong class="sywat-tracker-card-title"><?php echo esc_html( $line_title ); ?></strong>
                                            <span class="sywat-tracker-status-wrap"><?php echo $status_badge; ?></span>
                                        </div>
                                        <div class="sywat-tracker-card-stats">
                                            <span><strong>Scores Saved:</strong> <span class="sywat-tracker-entered-count"><?php echo intval( $scores_entered_line ); ?></span> / <?php echo intval( $total_line_shooters ); ?></span>
                                        </div>
                                    </div>

                                    <div class="sywat-tracker-card-actions">
                                        <?php if ( ! $is_line_locked ) : ?>
                                            <form method="post" class="sywat-scores-line-filter-form">
                                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                                <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $search_query ); ?>">
                                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-edit" data-sywat-action="select-scores-line" data-line-idx="<?php echo esc_attr( $idx ); ?>">Enter Scores</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ( $is_admin ) : ?>
                                            <form method="post">
                                                <?php wp_nonce_field( 'sywat_scores_action', 'sywat_nonce' ); ?>
                                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                                <input type="hidden" name="sywat_action" value="toggle_lock">
                                                <?php if ( $is_line_locked ) : ?>
                                                    <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-unlock">Unlock</button>
                                                <?php else : ?>
                                                    <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-lock">Lock</button>
                                                <?php endif; ?>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p class="sywat-empty-text">No shooting lines configured.</p>
                    <?php endif; ?>
                </div>

                <!-- Nested Section: Main Archer Scores Table -->
                <div>
                    <form method="post"
                          id="sywat_batch_score_form"
                          data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
                          data-locked-lines="<?php echo esc_attr( wp_json_encode( $locked_lines ) ); ?>"
                          data-has-locked-lines="<?php echo ! empty( $locked_lines ) ? '1' : '0'; ?>">
                        <?php wp_nonce_field( 'sywat_scores_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="selected_line_idx" class="sywat-scores-hidden-line" value="<?php echo esc_attr( $selected_line_idx ); ?>">
                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $search_query ); ?>">

                        <div class="sywat-table-header-bar">
                            <h2>Archer Scores (<span id="sywat_scores_visible_count"><?php echo intval( $initial_visible_count ); ?></span>)</h2>

                            <div class="sywat-table-header-controls">
                                <!-- Live Search Input Group -->
                                <div class="sywat-search-input-group">
                                    <input type="text" id="sywat_score_search_input" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Search archer name..." autocomplete="off">
                                    <button type="button" id="sywat_score_search_clear" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" data-sywat-action="clear-scores-search">Clear</button>
                                </div>

                                <!-- Single-Button Unentered / Show All View Toggle -->
                                <button type="button"
                                        id="sywat_scores_unentered_btn"
                                        class="sywat-btn sywat-btn-secondary"
                                        data-sywat-action="toggle-scores-unentered"
                                        aria-pressed="false">
                                    Unentered (<?php echo intval( $initial_unentered_count ); ?>)
                                </button>

                                <!-- Isolated Save Scores Button (Saves only uncommitted rows edited in this session) -->
                                <button type="button"
                                        id="sywat_scores_save_btn"
                                        class="sywat-btn sywat-btn-success sywat-btn-icon-save"
                                        data-sywat-action="save-uncommitted-scores"
                                        title="Save any uncommitted score changes you entered">
                                    Save Scores
                                </button>

                                <!-- Export CSV Button (Always Visible for Admins; validates locked lines before exporting) -->
                                <?php if ( $is_admin ) : ?>
                                    <button type="submit" name="sywat_action" value="export_csv" class="sywat-btn sywat-btn-primary sywat-btn-icon-export" title="Export scores for locked shooting lines to a CSV file">Export CSV</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ( ! empty( $prepared_rows ) ) : ?>
                            <div class="sywat-table-responsive">
                                <table class="sywat-table sywat-table-mobile-cards" id="sywat_scores_table">
                                    <thead>
                                        <tr>
                                            <th class="sywat-col-center sywat-col-num">#</th>
                                            <th class="sywat-col-name">Archer Name</th>
                                            <th class="sywat-col-line">Shooting Line</th>
                                            <th class="sywat-col-center sywat-col-gender">Gender</th>
                                            <th class="sywat-col-div">Division</th>
                                            <th class="sywat-col-style">Style</th>
                                            <th class="sywat-col-center sywat-col-score">Score (Max 300)</th>
                                            <th class="sywat-col-center sywat-col-xs">X Count (Max 60)</th>
                                            <th class="sywat-col-center sywat-col-inc" title="Incomplete Round (excluded from Season Standings)">INC</th>
                                            <th class="sywat-col-center sywat-col-status">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $num = 1; foreach ( $prepared_rows as $reg ) : ?>
                                            <?php
                                                $rd               = isset( $reg['row_display'] ) && is_array( $reg['row_display'] ) ? $reg['row_display'] : array();
                                                $reg_id           = isset( $rd['id'] ) ? (string) $rd['id'] : ( isset( $reg['id'] ) ? (string) $reg['id'] : '' );
                                                $disp_archer_name = isset( $rd['name'] ) ? (string) $rd['name'] : ( isset( $reg['name'] ) ? (string) $reg['name'] : '' );
                                                $lidx             = isset( $rd['line_idx'] ) ? (string) $rd['line_idx'] : '0';
                                                $line_disp        = isset( $rd['line_label'] ) ? (string) $rd['line_label'] : '';
                                                $gender_str       = isset( $rd['gender'] ) ? (string) $rd['gender'] : '—';
                                                $div_str          = isset( $rd['division'] ) ? (string) $rd['division'] : '—';
                                                $style_str        = isset( $rd['style'] ) ? (string) $rd['style'] : '—';
                                                $score            = isset( $rd['score'] ) ? intval( $rd['score'] ) : 0;
                                                $x_count          = isset( $rd['x_count'] ) ? intval( $rd['x_count'] ) : 0;
                                                $is_inc           = ! empty( $rd['is_inc'] );
                                                $is_locked        = ! empty( $rd['is_locked'] );
                                                $is_unentered     = ( $score === 0 && $x_count === 0 && ! $is_inc );
                                                $search_tokens    = isset( $rd['search_tokens'] ) ? (string) $rd['search_tokens'] : strtolower( trim( $disp_archer_name ) );
                                                $dis_attr         = $is_locked ? 'disabled' : '';

                                                $is_row_line_match  = ( $selected_line_idx === 'all' || $selected_line_idx === '' || $lidx === $selected_line_idx );
                                                $is_row_query_match = ( $clean_search_init === '' || strpos( $search_tokens, $clean_search_init ) !== false );
                                                $is_row_visible     = ( $is_row_line_match && $is_row_query_match );
                                            ?>
                                            <tr class="sywat-score-row<?php echo $is_locked ? ' locked-row' : ''; ?><?php echo $is_row_visible ? '' : ' sywat-hidden'; ?>"
                                                data-id="<?php echo esc_attr( $reg_id ); ?>"
                                                data-line-idx="<?php echo esc_attr( $lidx ); ?>"
                                                data-unentered="<?php echo $is_unentered ? '1' : '0'; ?>"
                                                data-name="<?php echo esc_attr( $search_tokens ); ?>">
                                                <td class="sywat-col-center" data-label="#"><strong><?php echo $num++; ?></strong></td>
                                                <td data-label="Archer Name"><strong><?php echo esc_html( $disp_archer_name ); ?></strong></td>
                                                <td data-label="Shooting Line"><?php echo esc_html( $line_disp ); ?></td>
                                                <td class="sywat-col-center" data-label="Gender"><?php echo esc_html( $gender_str ); ?></td>
                                                <td data-label="Division"><?php echo esc_html( $div_str ); ?></td>
                                                <td data-label="Style"><?php echo esc_html( $style_str ); ?></td>
                                                <td class="sywat-col-center" data-label="Score (Max 300)">
                                                    <input type="number" name="scores[<?php echo esc_attr( $reg_id ); ?>]" data-id="<?php echo esc_attr( $reg_id ); ?>" data-original-val="<?php echo esc_attr( $score ); ?>" value="<?php echo esc_attr( $score ); ?>" min="0" max="300" placeholder="0" class="sywat-score-input score-val" <?php echo $dis_attr; ?>>
                                                </td>
                                                <td class="sywat-col-center" data-label="X Count (Max 60)">
                                                    <input type="number" name="x_counts[<?php echo esc_attr( $reg_id ); ?>]" data-id="<?php echo esc_attr( $reg_id ); ?>" data-original-val="<?php echo esc_attr( $x_count ); ?>" value="<?php echo esc_attr( $x_count ); ?>" min="0" max="60" placeholder="0" class="sywat-score-input x-val" <?php echo $dis_attr; ?>>
                                                </td>
                                                <td class="sywat-col-center" data-label="INC">
                                                    <input type="hidden" name="inc_state[<?php echo esc_attr( $reg_id ); ?>]" data-id="<?php echo esc_attr( $reg_id ); ?>" data-original-val="<?php echo $is_inc ? '1' : '0'; ?>" value="<?php echo $is_inc ? '1' : '0'; ?>" class="sywat-inc-state" <?php echo $dis_attr; ?>>
                                                    <input type="checkbox" name="is_inc[<?php echo esc_attr( $reg_id ); ?>]" data-id="<?php echo esc_attr( $reg_id ); ?>" data-original-val="<?php echo $is_inc ? '1' : '0'; ?>" value="1" class="sywat-inc-val" aria-label="<?php echo esc_attr( 'Mark ' . $disp_archer_name . ' incomplete' ); ?>" <?php checked( $is_inc ); ?> <?php echo $dis_attr; ?>>
                                                </td>
                                                <td class="sywat-col-center" data-label="Status">
                                                    <?php if ( $is_locked ) : ?>
                                                        <span class="sywat-save-status sywat-status-locked" title="Unlocked by Admins & Shop Managers Only">🔒 Locked</span>
                                                    <?php else : ?>
                                                        <span class="sywat-save-status sywat-status-saved" id="status_<?php echo esc_attr( $reg_id ); ?>">✓ Saved</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p id="sywat_scores_empty_filter_msg" class="sywat-empty-text<?php echo ( $initial_visible_count === 0 ) ? '' : ' sywat-hidden'; ?>">No checked-in archers match the selected line, status, or search filter.</p>
                        <?php else : ?>
                            <p class="sywat-empty-text">No checked-in archers found for this tournament/line selection.</p>
                        <?php endif; ?>
                    </form>
                </div>
            <!-- Close Master Single Unified Desk Card -->
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
