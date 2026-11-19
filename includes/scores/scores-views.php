<?php
/**
 * Sub-Module: Scores Engine Visual Views & Shortcode Renderer
 * Path: includes/scores/scores-views.php
 * Description: Visual HTML renderers for role-gated desk navigation tabs, active tournament selectors, line score tracker, archer score entry table formatted and surname-sorted as "LastName, FirstName" with dual-format live search indexing, responsive mobile card labels and database-normalized division/style labels, and control toolbars.
 * Compliance: Pure view layer — zero output at include time, zero inline styles, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.7.0
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
        $user = wp_get_current_user();

        // Centralized capability check (includes tfaa_tournament_director & Executive Board)
        $can_edit = function_exists( 'sywat_can_edit_scores' ) ? sywat_can_edit_scores() : ( is_user_logged_in() && (bool) array_intersect( array( 'administrator', 'management', 'non-shooting-member', 'staff_shooter', 'sywat_shop_manager', 'sywat_shop_user', 'tfaa_tournament_director', 'tfaa_president', 'tfaa_webmaster', 'tfaa_admin', 'tfaa_field_governor' ), (array) $user->roles ) );
        if ( ! $can_edit ) {
            return '<p class="sywat-alert sywat-alert-danger">Access denied. Staff access required.</p>';
        }

        // Administrative & Score Control roles (Includes sywat_shop_manager for Line Lock/Unlock)
        $admin_roles            = array( 'administrator', 'management', 'tfaa_webmaster', 'tfaa_admin', 'tfaa_president', 'tfaa_tournament_director', 'tfaa_field_governor', 'sywat_shop_manager' );
        $is_admin               = is_user_logged_in() && (bool) array_intersect( $admin_roles, (array) $user->roles );
        $can_manage_tournaments = function_exists( 'sywat_can_manage_tournaments' ) && sywat_can_manage_tournaments();
        $can_register_shooters  = function_exists( 'sywat_can_register_shooters' ) ? sywat_can_register_shooters() : ( function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk() );
        $can_access_desk        = function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk();
        $can_manage_seasons     = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
        $user_shop_id           = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';

        // Handle AJAX Autosave Early
        if ( function_exists( 'sywat_scores_handle_ajax_autosave' ) ) {
            sywat_scores_handle_ajax_autosave();
        }

        ob_start();
        global $wpdb;

        // Fetch Tournaments List with Shop Scoping
        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( empty( $all_tournaments ) || ! is_array( $all_tournaments ) ) {
            $single_name     = get_option( 'sywat_current_tournament', 'CCR SYWAT Qualifier' );
            $all_tournaments = array( $single_name => get_option( 'sywat_line_config', array() ) );
        }

        $raw_tournaments = array();
        foreach ( $all_tournaments as $t_name => $t_data ) {
            $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
            if ( $is_admin || $t_shop === $user_shop_id ) {
                $raw_tournaments[ $t_name ] = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : $t_data;
            }
        }

        $selected_tournament = isset( $_POST['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) ) : ( isset( $_GET['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_GET['selected_tournament'] ) ) : '' );
        $selected_line_idx   = isset( $_POST['selected_line_idx'] )   ? sanitize_text_field( wp_unslash( $_POST['selected_line_idx'] ) )   : ( isset( $_GET['selected_line_idx'] )   ? sanitize_text_field( wp_unslash( $_GET['selected_line_idx'] ) )   : 'all' );
        $search_query        = isset( $_POST['search_query'] )        ? sanitize_text_field( wp_unslash( $_POST['search_query'] ) )        : '';

        // Primary Fallback: Sticky User Meta Preference across Register / Check-In / Scores
        if ( empty( $selected_tournament ) && $user->ID ) {
            $saved_t = get_user_meta( $user->ID, 'sywat_desk_selected_tournament', true );
            if ( ! empty( $saved_t ) && isset( $raw_tournaments[ $saved_t ] ) ) {
                $selected_tournament = $saved_t;
            }
        }

        // Secondary Fallback: Global active tournament
        if ( empty( $selected_tournament ) ) {
            $global_curr = get_option( 'sywat_current_tournament', '' );
            if ( ! empty( $global_curr ) && isset( $raw_tournaments[ $global_curr ] ) ) {
                $selected_tournament = $global_curr;
            }
        }

        if ( empty( $selected_tournament ) && ! empty( $raw_tournaments ) ) {
            reset( $raw_tournaments );
            $selected_tournament = key( $raw_tournaments );
        }

        if ( $user->ID && ! empty( $selected_tournament ) ) {
            update_user_meta( $user->ID, 'sywat_desk_selected_tournament', $selected_tournament );
        }

        // Hybrid Tournament Sorting: Sort A-Z, Pin Active Tournament to Top Position (#0)
        uksort( $raw_tournaments, 'strcasecmp' );

        $tournaments = array();
        if ( ! empty( $selected_tournament ) && isset( $raw_tournaments[ $selected_tournament ] ) ) {
            $tournaments[ $selected_tournament ] = $raw_tournaments[ $selected_tournament ];
        }
        foreach ( $raw_tournaments as $t_name => $t_lines ) {
            if ( $t_name !== $selected_tournament ) {
                $tournaments[ $t_name ] = $t_lines;
            }
        }

        $lines         = isset( $tournaments[ $selected_tournament ] ) ? $tournaments[ $selected_tournament ] : array();
        $active_t_slug = sanitize_title( $selected_tournament );

        // Fetch Active Locks with object cache flush
        $locks_key = 'sywat_locks_' . $active_t_slug;
        wp_cache_delete( $locks_key, 'options' );
        $locked_lines        = get_option( $locks_key, array() );
        $current_view_locked = ( $selected_line_idx !== 'all' && ! empty( $locked_lines[ $selected_line_idx ] ) );

        // Batch Action Handlers
        $notice = '';
        if ( function_exists( 'sywat_scores_handle_batch_actions' ) ) {
            $notice = sywat_scores_handle_batch_actions( $selected_tournament, $selected_line_idx, $is_admin, $locked_lines, $current_view_locked );
        }

        // Retrieve Archers and Master Score Map for Active View
        $registrations = array();
        $results       = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'sywat_t_' . $active_t_slug . '_' ) . '%'
        ) );

        foreach ( $results as $row ) {
            $reg_data = maybe_unserialize( $row->option_value );
            if ( is_array( $reg_data ) && isset( $reg_data['id'] ) ) {
                $registrations[] = $reg_data;
            }
        }

        $scores_map = get_option( 'sywat_scores_' . $active_t_slug, array() );
        if ( ! is_array( $scores_map ) ) {
            $scores_map = array();
        }

        $has_lf_formatter    = function_exists( 'sywat_format_name_last_first' );
        $tourn_registrations = array();
        foreach ( $registrations as $reg ) {
            $match_tourn   = isset( $reg['tournament'] ) && $reg['tournament'] === $selected_tournament;
            $match_line    = ( $selected_line_idx === 'all' ) || ( isset( $reg['line_index'] ) && (string) $reg['line_index'] === (string) $selected_line_idx );
            $is_checked_in = ! empty( $reg['checked_in'] );

            if ( $match_tourn && $match_line && $is_checked_in ) {
                $raw_name              = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : '';
                $reg['display_name']   = $has_lf_formatter ? sywat_format_name_last_first( $raw_name ) : $raw_name;
                $tourn_registrations[] = $reg;
            }
        }

        usort( $tourn_registrations, function( $a, $b ) {
            $line_a = isset( $a['line_index'] ) ? (int) $a['line_index'] : 99;
            $line_b = isset( $b['line_index'] ) ? (int) $b['line_index'] : 99;
            if ( $line_a !== $line_b ) {
                return $line_a <=> $line_b;
            }
            $disp_a = isset( $a['display_name'] ) ? trim( (string) $a['display_name'] ) : ( isset( $a['name'] ) ? trim( (string) $a['name'] ) : '' );
            $disp_b = isset( $b['display_name'] ) ? trim( (string) $b['display_name'] ) : ( isset( $b['name'] ) ? trim( (string) $b['name'] ) : '' );
            return strcasecmp( $disp_a, $disp_b );
        } );

        // Check active Volunteer Hub status across all host shops for dynamic return button
        $active_hubs = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_hubs ) ) {
            $active_hubs = array();
        }
        $is_hub_enabled = in_array( $selected_tournament, $active_hubs, true );

        // Enqueue client score interactive JavaScript asset
        wp_enqueue_script( 'sywat-scores-js', SYWAT_PLUGIN_URL . 'assets/js/sywat-scores.js', array(), SYWAT_PLUGIN_VERSION, true );

        $tab_query_args      = ! empty( $selected_tournament ) ? array( 'selected_tournament' => $selected_tournament ) : array();
        $has_norm_division   = function_exists( 'sywat_shooting_styles_normalize_division' );
        $has_norm_style      = function_exists( 'sywat_shooting_styles_normalize_style' );
        $script_optout_attrs = defined( 'SYWAT_SCRIPT_OPTOUT_ATTRS' ) ? SYWAT_SCRIPT_OPTOUT_ATTRS : '';
        ?>

        <div class="sywat-wrap">
            <?php echo $notice; ?>

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
                                <select name="selected_tournament" id="selected_tournament_val" class="sywat-select-tournament" onchange="document.getElementById('sywat_score_filter_form').submit();">
                                    <?php foreach ( $tournaments as $t_name => $t_lines ) : ?>
                                        <option value="<?php echo esc_attr( $t_name ); ?>" <?php selected( $selected_tournament, $t_name ); ?>>
                                            <?php echo esc_html( $t_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Stacked Shooting Line Dropdown -->
                            <div class="sywat-field-group sywat-field-auto">
                                <label for="selected_line_val">Shooting Line:</label>
                                <select name="selected_line_idx" id="selected_line_val" class="sywat-select-line" onchange="document.getElementById('sywat_score_filter_form').submit();">
                                    <option value="all" <?php selected( $selected_line_idx, 'all' ); ?>>All Lines (Entire Weekend)</option>
                                    <?php foreach ( $lines as $idx => $line ) : ?>
                                        <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $selected_line_idx, (string) $idx ); ?>>
                                            <?php echo esc_html( $line['name'] ); ?>
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
                    <?php if ( ! empty( $lines ) ) : ?>
                        <?php
                            $ordered_lines = array();
                            foreach ( $lines as $idx => $line ) {
                                $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ( $idx + 1 );
                                $weight     = 50;

                                if ( stripos( $line_title, 'Sat' ) !== false ) {
                                    if ( stripos( $line_title, '10' ) !== false ) {
                                        $weight = 10;
                                    } elseif ( stripos( $line_title, '2' ) !== false ) {
                                        $weight = 20;
                                    } else {
                                        $weight = 25;
                                    }
                                } elseif ( stripos( $line_title, 'Sun' ) !== false ) {
                                    if ( stripos( $line_title, '10' ) !== false ) {
                                        $weight = 30;
                                    } elseif ( stripos( $line_title, '2' ) !== false ) {
                                        $weight = 40;
                                    } else {
                                        $weight = 45;
                                    }
                                }

                                $ordered_lines[] = array( 'idx' => $idx, 'line' => $line, 'weight' => $weight );
                            }

                            usort( $ordered_lines, function( $a, $b ) {
                                if ( $a['weight'] !== $b['weight'] ) {
                                    return $a['weight'] <=> $b['weight'];
                                }
                                return $a['idx'] <=> $b['idx'];
                            } );
                        ?>

                        <div class="sywat-tracker-grid">
                            <?php foreach ( $ordered_lines as $item ) : ?>
                                <?php
                                    $idx  = $item['idx'];
                                    $line = $item['line'];

                                    $total_line_shooters = 0;
                                    $scores_entered_line = 0;

                                    foreach ( $registrations as $reg ) {
                                        if ( isset( $reg['tournament'] ) && $reg['tournament'] === $selected_tournament ) {
                                            $match_index = isset( $reg['line_index'] ) && (string) $reg['line_index'] === (string) $idx;
                                            $match_name  = isset( $reg['line_name'] ) && isset( $line['name'] ) && trim( $reg['line_name'] ) === trim( $line['name'] );

                                            if ( ( $match_index || $match_name ) && ! empty( $reg['checked_in'] ) ) {
                                                $total_line_shooters++;
                                                $rid   = isset( $reg['id'] ) ? $reg['id'] : '';
                                                $map_s = isset( $scores_map[ $rid ]['score'] ) ? intval( $scores_map[ $rid ]['score'] ) : ( isset( $reg['score'] ) ? intval( $reg['score'] ) : 0 );
                                                $map_x = isset( $scores_map[ $rid ]['xs'] )    ? intval( $scores_map[ $rid ]['xs'] )    : ( isset( $reg['x_count'] ) ? intval( $reg['x_count'] ) : 0 );

                                                if ( $map_s > 0 || $map_x > 0 ) {
                                                    $scores_entered_line++;
                                                }
                                            }
                                        }
                                    }

                                    $is_line_locked = ! empty( $locked_lines[ $idx ] );
                                    $is_complete    = ( $total_line_shooters > 0 && $scores_entered_line === $total_line_shooters );

                                    if ( $is_line_locked ) {
                                        $status_badge = '<span class="sywat-badge sywat-badge-full">🔒 Locked</span>';
                                    } elseif ( $is_complete ) {
                                        $status_badge = '<span class="sywat-badge sywat-badge-open">✅ Complete</span>';
                                    } else {
                                        $pending      = max( 0, $total_line_shooters - $scores_entered_line );
                                        $status_badge = '<span class="sywat-badge sywat-badge-prereg">' . $pending . ' Pending</span>';
                                    }
                                ?>
                                <div class="sywat-tracker-card">
                                    <div>
                                        <div class="sywat-tracker-card-header">
                                            <strong class="sywat-tracker-card-title"><?php echo esc_html( $line['name'] ); ?></strong>
                                            <?php echo $status_badge; ?>
                                        </div>
                                        <div class="sywat-tracker-card-stats">
                                            <span><strong>Scores Saved:</strong> <?php echo $scores_entered_line . ' / ' . $total_line_shooters; ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="sywat-tracker-card-actions">
                                        <form method="post" class="sywat-form-inline">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-edit">Enter Scores</button>
                                        </form>

                                        <?php if ( $is_admin ) : ?>
                                            <form method="post" class="sywat-form-inline">
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
                    <form method="post" id="sywat_batch_score_form">
                        <?php wp_nonce_field( 'sywat_scores_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $selected_line_idx ); ?>">

                        <div class="sywat-table-header-bar">
                            <h2>Archer Scores</h2>

                            <div class="sywat-table-header-controls">
                                <!-- Live Search Input Group (Aligned with Check-In Inline Enter Guard) -->
                                <div class="sywat-search-input-group">
                                    <input type="text" id="sywat_score_search_input" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Search archer name..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                                    <button type="button" id="sywat_score_search_clear" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="document.getElementById('sywat_score_search_input').value=''; document.getElementById('sywat_score_search_input').dispatchEvent(new Event('input'));">Clear</button>
                                </div>

                                <!-- Export CSV Button (Admin & Shop Managers) -->
                                <?php if ( $is_admin && ! empty( $locked_lines ) ) : ?>
                                    <button type="submit" name="sywat_action" value="export_csv" class="sywat-btn sywat-btn-primary sywat-btn-icon-export" title="Export scores for ALL locked lines to a CSV file">Export CSV</button>
                                <?php endif; ?>

                                <?php if ( $is_admin ) : ?>
                                    <?php if ( $selected_line_idx !== 'all' ) : ?>
                                        <?php if ( $current_view_locked ) : ?>
                                            <button type="submit" name="sywat_action" value="toggle_lock" class="sywat-btn sywat-btn-secondary sywat-btn-icon-unlock" title="Unlock to edit scores">Unlock Line</button>
                                        <?php else : ?>
                                            <button type="submit" name="sywat_action" value="toggle_lock" class="sywat-btn sywat-btn-secondary sywat-btn-icon-lock" title="Lock to prevent edits">Lock Line</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <button type="submit" name="sywat_action" value="reset_scores" class="sywat-btn sywat-btn-danger sywat-btn-icon-reset" onclick="return confirm('⚠️ Are you sure you want to RESET ALL SCORES to 0 for the selected line/tournament?\n\nThis will set score and X count to 0 for all unlocked, checked-in archers in this view.');">Reset All Scores to 0</button>
                                <?php endif; ?>

                                <button type="submit" name="sywat_action" value="save_scores" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save All Scores</button>
                            </div>
                        </div>

                        <?php if ( ! empty( $tourn_registrations ) ) : ?>
                            <div class="sywat-table-responsive">
                                <table class="sywat-table sywat-table-mobile-cards">
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
                                            <th class="sywat-col-center sywat-col-status">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $num = 1; foreach ( $tourn_registrations as $reg ) : ?>
                                            <?php
                                                $reg_id     = isset( $reg['id'] ) ? $reg['id'] : '';
                                                $score      = isset( $scores_map[ $reg_id ]['score'] ) ? intval( $scores_map[ $reg_id ]['score'] ) : ( isset( $reg['score'] ) ? intval( $reg['score'] ) : 0 );
                                                $x_count    = isset( $scores_map[ $reg_id ]['xs'] )    ? intval( $scores_map[ $reg_id ]['xs'] )    : ( isset( $reg['x_count'] ) ? intval( $reg['x_count'] ) : 0 );
                                                $gender_str = ! empty( $reg['gender'] ) ? $reg['gender'] : '—';

                                                $disp_archer_name = ! empty( $reg['display_name'] )
                                                    ? $reg['display_name']
                                                    : ( $has_lf_formatter ? sywat_format_name_last_first( $reg['name'] ) : $reg['name'] );
                                                $search_tokens    = strtolower( trim( $disp_archer_name . ' ' . $reg['name'] ) );

                                                $raw_div = ! empty( $reg['division'] ) ? trim( (string) $reg['division'] ) : '';
                                                $div_str = ( $raw_div !== '' && $raw_div !== '—' && strcasecmp( $raw_div, 'No Show' ) !== 0 && $has_norm_division )
                                                    ? sywat_shooting_styles_normalize_division( $raw_div )
                                                    : ( $raw_div !== '' ? $raw_div : '—' );

                                                $raw_style = ! empty( $reg['style'] ) ? trim( (string) $reg['style'] ) : '';
                                                $style_str = ( $raw_style !== '' && $raw_style !== '—' && strcasecmp( $raw_style, 'No Show' ) !== 0 && $has_norm_style )
                                                    ? sywat_shooting_styles_normalize_style( $raw_style )
                                                    : ( $raw_style !== '' ? $raw_style : '—' );

                                                $is_locked = ! empty( $locked_lines[ $reg['line_index'] ] );
                                                $dis_attr  = $is_locked ? 'disabled' : '';

                                                $lidx      = function_exists( 'sywat_roster_resolve_line_idx' ) ? sywat_roster_resolve_line_idx( $reg, $lines ) : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );
                                                $line_disp = isset( $lines[ $lidx ]['name'] ) ? $lines[ $lidx ]['name'] : ( ! empty( $reg['line_name'] ) ? $reg['line_name'] : 'Line 1' );
                                            ?>
                                            <tr class="sywat-score-row <?php echo $is_locked ? 'locked-row' : ''; ?>" data-id="<?php echo esc_attr( $reg_id ); ?>" data-name="<?php echo esc_attr( $search_tokens ); ?>">
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

                            <div class="sywat-table-footer-controls sywat-modal-form-actions-right sywat-mt-3">
                                <?php if ( $is_admin ) : ?>
                                    <button type="submit" name="sywat_action" value="reset_scores" class="sywat-btn sywat-btn-danger sywat-btn-icon-reset" onclick="return confirm('⚠️ Are you sure you want to RESET ALL SCORES to 0 for the selected line/tournament?\n\nThis will set score and X count to 0 for all unlocked, checked-in archers in this view.');">Reset All Scores to 0</button>
                                <?php endif; ?>
                                <button type="submit" name="sywat_action" value="save_scores" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save All Scores</button>
                            </div>
                        <?php else : ?>
                            <p class="sywat-empty-text">No checked-in archers found for this tournament/line selection.</p>
                        <?php endif; ?>
                    </form>
                </div>
            <!-- Close Master Single Unified Desk Card -->
            </div>
        </div>

        <script <?php echo $script_optout_attrs; ?>>
        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.getElementById('sywat_score_search_input');

            function applyScoreSearchFilter(query) {
                const cleanQuery = query.trim().toLowerCase();
                const rows = document.querySelectorAll('.sywat-score-row');

                rows.forEach(function(row) {
                    const dataName = (row.getAttribute('data-name') || '').toLowerCase();
                    const textContent = (row.textContent || row.innerText || '').toLowerCase();

                    if (!cleanQuery || dataName.includes(cleanQuery) || textContent.includes(cleanQuery)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });

                document.querySelectorAll('.sywat-hidden-search').forEach(function(hiddenInput) {
                    hiddenInput.value = query;
                });

                if (query) {
                    sessionStorage.setItem('sywat_score_search_query', query);
                } else {
                    sessionStorage.removeItem('sywat_score_search_query');
                }
            }

            if (searchInput) {
                let initialQuery = searchInput.value;
                if (!initialQuery) {
                    const savedQuery = sessionStorage.getItem('sywat_score_search_query');
                    if (savedQuery) {
                        initialQuery = savedQuery;
                        searchInput.value = savedQuery;
                    }
                }

                if (initialQuery) {
                    applyScoreSearchFilter(initialQuery);
                }

                searchInput.addEventListener('input', function() {
                    applyScoreSearchFilter(this.value);
                });
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
}
