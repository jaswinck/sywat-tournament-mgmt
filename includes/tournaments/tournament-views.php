<?php
/**
 * Sub-Module: Master Admin Portal Views & Navigation Tabs
 * Path: includes/tournaments/tournament-views.php
 * Author: SYWAT Archery
 * Version: 7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'render_sywat_master_portal' ) ) {
    function render_sywat_master_portal() {
        // Enforce Central Permission Check
        if ( ! sywat_can_manage_tournaments() ) {
            return '<div class="sywat-alert sywat-alert-danger">Access denied. Management access required.</div>';
        }

        ob_start();

        // 1. Initial Permission & Role Data for POST Handling
        $can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) ? sywat_can_manage_seasons() : current_user_can( 'manage_options' );
        $user_shop_id       = function_exists( 'sywat_get_user_shop_id' ) ? sywat_get_user_shop_id() : '';

        // Mutable Data Collections for Action Handling
        $tournaments        = get_option( 'sywat_tournaments_list', array() );
        $active_tournaments = get_option( 'sywat_active_tournaments', array() );
        $registrations      = function_exists( 'sywat_get_all_registrations' ) ? sywat_get_all_registrations() : array();

        $notice     = '';
        $active_tab = isset( $_REQUEST['tab'] ) ? sanitize_text_field( $_REQUEST['tab'] ) : 'manage';

        // 2. Execute POST Actions FIRST (Before rendering data load)
        $is_valid_nonce = ( isset( $_POST['sywat_nonce'] ) && wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_desk_action' ) )
                       || ( isset( $_POST['sywat_import_schedule_nonce'] ) && wp_verify_nonce( $_POST['sywat_import_schedule_nonce'], 'sywat_import_schedule_nonce' ) )
                       || ( isset( $_POST['sywat_confirm_schedule_nonce'] ) && wp_verify_nonce( $_POST['sywat_confirm_schedule_nonce'], 'sywat_confirm_schedule_nonce' ) );

        $action = isset( $_POST['sywat_action'] ) ? sanitize_text_field( $_POST['sywat_action'] ) : '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $action && ! $is_valid_nonce && $action !== 'cancel' ) {
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        } elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce ) {
            if ( function_exists( 'sywat_handle_tournament_post_actions' ) ) {
                $notice = sywat_handle_tournament_post_actions( $tournaments, $active_tournaments, $registrations, $can_manage_seasons, $user_shop_id );
            }
        }

        // 3. Fetch fresh master portal data bundle AFTER actions mutate database
        $data = sywat_get_master_portal_data();

        $can_manage_seasons = $data['can_manage_seasons'];
        $can_access_desk    = $data['can_access_desk'];
        $can_edit_scores    = $data['can_edit_scores'];
        $user_shop_id       = $data['user_shop_id'];
        $all_shops          = $data['all_shops'];
        $seasons            = $data['seasons'];
        $tournaments        = $data['tournaments'];
        $active_tournaments = $data['active_tournaments'];
        $registrations      = $data['registrations'];
        $scoped_tournaments = $data['scoped_tournaments'];
        $filter_shops       = $data['filter_shops'];
        $tfaa_count         = $data['tfaa_count'];

        // Build lookup map from normalized shop name to Shop ID & District
        $shop_lookup_map = array();
        foreach ( $all_shops as $s_id => $s_data ) {
            if ( is_array( $s_data ) ) {
                $s_name = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : ( ! empty( $s_data['title'] ) ? $s_data['title'] : '' ) );
                $s_dist = ! empty( $s_data['district'] ) ? $s_data['district'] : '';
                if ( ! empty( $s_name ) ) {
                    $shop_lookup_map[ strtolower( trim( $s_name ) ) ] = array(
                        'id'       => $s_id,
                        'district' => $s_dist,
                    );
                }
            }
        }

        // Build list of unique TFAA districts across scoped tournaments
        $filter_districts = array();
        foreach ( $scoped_tournaments as $t_info ) {
            $s_id   = sywat_get_tournament_shop_id( $t_info );
            $d_name = '';

            if ( ! empty( $s_id ) && isset( $all_shops[$s_id]['district'] ) && ! empty( $all_shops[$s_id]['district'] ) ) {
                $d_name = $all_shops[$s_id]['district'];
            } else {
                $target_name = ! empty( $t_info['shop_name'] ) ? $t_info['shop_name'] : ( ! empty( $t_info['official_shop_name'] ) ? $t_info['official_shop_name'] : ( ! empty( $t_info['location_display'] ) ? $t_info['location_display'] : '' ) );
                $norm_target = strtolower( trim( $target_name ) );
                if ( ! empty( $norm_target ) && isset( $shop_lookup_map[$norm_target] ) ) {
                    $d_name = $shop_lookup_map[$norm_target]['district'];
                }
            }

            if ( ! empty( $d_name ) && ! in_array( $d_name, $filter_districts ) ) {
                $filter_districts[] = $d_name;
            }
        }
        sort( $filter_districts );
        ?>

        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <!-- Operational Navigation Tabs (Card-Docked) -->
            <div class="sywat-nav-tabs no-print">
                <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament <?php echo ($active_tab === 'manage') ? 'active' : ''; ?>">Manage Tournaments</a>
                <?php if ( $can_access_desk ): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-register/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-register">Register Shooters</a>
                    <a href="<?php echo esc_url( site_url( '/sywat-desk/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-checkin">Check In Archers</a>
                <?php endif; ?>
                <?php if ( $can_edit_scores ): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-enter-scores/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-edit">Enter Scores</a>
                <?php endif; ?>
                <?php if ( $can_manage_seasons ): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/?tab=seasons' ) ); ?>" class="sywat-tab-item sywat-tab-icon-season <?php echo ($active_tab === 'seasons') ? 'active' : ''; ?>">Manage Seasons</a>
                <?php endif; ?>
            </div>

            <?php if ( $active_tab === 'seasons' && $can_manage_seasons ): ?>
                <!-- TAB 2: SEASONS MANAGEMENT -->
                <?php if ( function_exists( 'sywat_render_seasons_tab' ) ) sywat_render_seasons_tab(); ?>

            <?php else: ?>
                <!-- TAB 1: TOURNAMENTS TABLE -->
                <div class="sywat-card no-print">
                    <!-- UNIFIED TOP CARD HEADER BAR -->
                    <div class="sywat-card-header-bar">
                        <div>
                            <h2>🎯 Manage Tournaments</h2>
                            <div class="sywat-subtitle">Set up upcoming events, configure shooting lines, and print desk rosters.</div>
                        </div>

                        <!-- TOP PAGE ACTION CONTROLS & SEASON SWITCHER -->
                        <div class="sywat-header-inline-controls">
                            <?php if ( $can_manage_seasons && ! empty( $seasons ) ): ?>
                                <?php
                                $selected_season = isset( $_GET['filter_season'] ) ? sanitize_text_field( $_GET['filter_season'] ) : '';
                                ?>
                                <form method="get" class="sywat-filter-inline-form">
                                    <input type="hidden" name="page" value="sywat-manage-tournaments">
                                    <select name="filter_season" class="sywat-select-season" onchange="this.form.submit();">
                                        <option value="">All Seasons</option>
                                        <?php foreach ( $seasons as $s_slug => $s_data ): ?>
                                            <?php 
                                            $s_name = is_array( $s_data ) && isset( $s_data['name'] ) ? $s_data['name'] : $s_slug; 
                                            ?>
                                            <option value="<?php echo esc_attr( $s_slug ); ?>" <?php selected( $s_slug, $selected_season ); ?>>
                                                <?php echo esc_html( $s_name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php endif; ?>

                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-import" onclick="openImportScheduleModal()">Bulk Import Schedule</button>
                            <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="openAddTournamentModal()">Add Tournament</button>
                        </div>
                    </div>

                    <!-- TABLE SUB-HEADER ROW (Blueprint Pure View Layout) -->
                    <div class="sywat-table-header-bar">
                        <h2>Configured Events</h2>

                        <div class="sywat-table-header-controls">
                            <!-- District Dropdown Filter -->
                            <?php if ( count( $filter_districts ) > 0 ): ?>
                                <select id="sywat_tournament_district_filter" onchange="sywatApplyTournamentFilters()">
                                    <option value="all">All Districts</option>
                                    <?php foreach ( $filter_districts as $dist ): ?>
                                        <option value="<?php echo esc_attr( strtolower( $dist ) ); ?>"><?php echo esc_html( $dist ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <!-- Shop Dropdown Filter -->
                            <?php if ( $can_manage_seasons && count( $filter_shops ) > 1 ): ?>
                                <select id="sywat_tournament_shop_filter" onchange="sywatApplyTournamentFilters()">
                                    <option value="all">All Shops</option>
                                    <?php foreach ( $filter_shops as $s_id => $s_name ): ?>
                                        <option value="<?php echo esc_attr( strtolower( $s_name ) ); ?>"><?php echo esc_html( $s_name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <input type="text" id="sywat_tournament_search_input" oninput="sywatApplyTournamentFilters()" placeholder="Search..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">

                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetTournamentFilters()">Clear</button>

                            <!-- Option 1: Deactivate All Hubs Form -->
                            <?php if ( $can_manage_seasons ): ?>
                                <form method="post" onsubmit="return confirm('Are you sure you want to deactivate Volunteer Hub visibility across ALL tournaments?');">
                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                    <input type="hidden" name="sywat_action" value="deactivate_all_hubs">
                                    <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-reset">⚪ Deactivate All Hubs</button>
                                </form>
                            <?php endif; ?>

                            <!-- Push Standings Form -->
                            <?php if ( $can_manage_seasons ): ?>
                                <form method="post" onsubmit="return confirm('Are you sure you want to push all weekend scores to Season Standings? This will aggregate recorded scores across all active tournaments into the public leaderboard.');">
                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                    <input type="hidden" name="sywat_action" value="global_push_standings">
                                    <button type="submit" class="sywat-btn sywat-btn-global-push sywat-btn-icon-global-push">Push Weekend Standings</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ( empty( $scoped_tournaments ) ): ?>
                        <p class="sywat-score-null">No tournaments created yet for this location. Click on <strong>"Add Tournament"</strong> to configure your first tournament.</p>
                    <?php else: ?>
                        <div class="sywat-table-responsive">
                            <table class="sywat-table">
                                <thead>
                                    <tr>
                                        <th class="sywat-col-tourn">Tournament, Season & Location</th>
                                        <th class="sywat-col-center">Line Slot Availability (20yd / Cub)</th>
                                        <th class="sywat-col-center sywat-col-action">Tournament Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $scoped_tournaments as $t_name => $t_data ): ?>
                                        <?php 
                                            $t_lines       = sywat_get_tournament_lines( $t_data );
                                            $t_shop_id     = sywat_get_tournament_shop_id( $t_data );
                                            $t_season_slug = sywat_get_tournament_season_slug( $t_data );
                                            $t_season_name = isset( $seasons[$t_season_slug]['name'] ) ? $seasons[$t_season_slug]['name'] : 'Active Season';
                                            
                                            // Dynamic Host Shop Name & District Resolution
                                            $t_shop_name = '';
                                            if ( is_array( $t_data ) && ! empty( $t_data['shop_name'] ) ) {
                                                $t_shop_name = $t_data['shop_name'];
                                            } elseif ( is_array( $t_data ) && ! empty( $t_data['official_shop_name'] ) ) {
                                                $t_shop_name = $t_data['official_shop_name'];
                                            } elseif ( is_array( $t_data ) && ! empty( $t_data['location_display'] ) ) {
                                                $t_shop_name = $t_data['location_display'];
                                            } elseif ( ! empty( $t_shop_id ) && isset( $all_shops[$t_shop_id]['name'] ) ) {
                                                $t_shop_name = $all_shops[$t_shop_id]['name'];
                                            } else {
                                                $t_shop_name = 'Host Archery Shop';
                                            }

                                            // Resolve District via Direct Shop ID or Name Lookup Fallback
                                            $t_district = '';
                                            if ( ! empty( $t_shop_id ) && isset( $all_shops[$t_shop_id]['district'] ) && ! empty( $all_shops[$t_shop_id]['district'] ) ) {
                                                $t_district = $all_shops[$t_shop_id]['district'];
                                            } else {
                                                $norm_target = strtolower( trim( $t_shop_name ) );
                                                if ( ! empty( $norm_target ) && isset( $shop_lookup_map[$norm_target] ) ) {
                                                    $t_district = $shop_lookup_map[$norm_target]['district'];
                                                }
                                            }

                                            $is_ccr = ( strcasecmp( trim( $t_shop_name ), 'Cinnamon Creek Ranch' ) === 0 || $t_shop_id === 'shop_ccr' );

                                            $s_date        = ( is_array( $t_data ) && isset( $t_data['start_date'] ) ) ? $t_data['start_date'] : '';
                                            $e_date        = ( is_array( $t_data ) && isset( $t_data['end_date'] ) ) ? $t_data['end_date'] : '';
                                            $disp_dates    = sywat_format_tournament_dates( $s_date, $e_date );
                                            if ( empty( $disp_dates ) ) {
                                                $disp_dates = get_option( 'sywat_dates_' . sanitize_title( $t_name ), 'Dates Not Set' );
                                            }

                                            $line_count    = is_array( $t_lines ) ? count( $t_lines ) : 0;
                                            $reg_count     = sywat_get_tournament_count( $t_name, $registrations );
                                            $is_active_hub = in_array( $t_name, $active_tournaments );
                                        ?>
                                        <tr class="sywat-tournament-row" data-name="<?php echo esc_attr(strtolower($t_name)); ?>" data-shop="<?php echo esc_attr(strtolower($t_shop_name)); ?>" data-district="<?php echo esc_attr(strtolower($t_district)); ?>">
                                            <!-- Column 1: Metadata & Volunteer Hub Toggle -->
                                            <td>
                                                <strong class="sywat-tourn-title"><?php echo esc_html( $t_name ); ?></strong>
                                                <div class="sywat-tourn-season">🏆 <?php echo esc_html( $t_season_name ); ?></div>
                                                <div class="sywat-tourn-shop">📍 <?php echo esc_html( $t_shop_name ); ?><?php if ( ! empty( $t_district ) ): ?> <span class="sywat-label-subtext">(🗺️ <?php echo esc_html( $t_district ); ?>)</span><?php endif; ?></div>
                                                <div class="sywat-tourn-dates">📅 <?php echo esc_html( $disp_dates ); ?> (<?php echo $line_count . ' ' . ( $line_count === 1 ? 'line' : 'lines' ); ?>)</div>
                                                
                                                <!-- Volunteer Hub Toggle Chip -->
                                                <div class="sywat-table-action-form">
                                                    <?php if ( $is_ccr ): ?>
                                                        <form method="post" class="sywat-table-action-form">
                                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                            <input type="hidden" name="sywat_action" value="toggle_active_tournament">
                                                            <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                            <?php if ( $is_active_hub ): ?>
                                                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-sm" title="Visible in Volunteer Hub">🟢 Hub Active</button>
                                                            <?php else: ?>
                                                                <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm" title="Click to activate in Volunteer Hub">⚪ Hub Hidden</button>
                                                            <?php endif; ?>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="sywat-hub-chip-na">Hub: N/A</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Column 2: 2-Column Line Slot Capacity Grid -->
                                            <td class="sywat-col-center">
                                                <?php if ( ! empty( $t_lines ) && is_array( $t_lines ) ): ?>
                                                    <div class="sywat-lines-grid">
                                                        <?php foreach ( $t_lines as $idx => $line ): ?>
                                                            <?php 
                                                                $day_str   = isset( $line['day'] ) ? $line['day'] : '';
                                                                $time_str  = isset( $line['time'] ) ? $line['time'] : ( isset( $line['start_time'] ) ? $line['start_time'] : '' );
                                                                $line_title = ! empty( $line['name'] ) ? $line['name'] : ( ( ! empty( $day_str ) && ! empty( $time_str ) ) ? trim( $day_str . ' ' . $time_str ) : 'Line ' . ($idx + 1) );

                                                                // View Normalization for Line Times (6AM/6:00AM -> 6PM, 6:00PM -> 6PM)
                                                                $line_title = preg_replace( '/\b6(?::00)?\s*AM\b/i', '6PM', $line_title );
                                                                $line_title = preg_replace( '/\b6:30\s*AM\b/i', '6:30PM', $line_title );
                                                                $line_title = preg_replace( '/(\d{1,2}):00\s*(AM|PM)/i', '$1$2', $line_title );

                                                                $c_20 = sywat_count_registered( $t_name, $idx, '20yd', $registrations );
                                                                $m_20 = sywat_get_line_max_cap( $line, '20yd' );
                                                                $rem_20 = max( 0, $m_20 - $c_20 );
                                                                $c_cub = sywat_count_registered( $t_name, $idx, 'Cub', $registrations );
                                                                $m_cub = sywat_get_line_max_cap( $line, 'Cub' );
                                                                $rem_cub = max( 0, $m_cub - $c_cub );

                                                                // Count waitlisted archers for this specific line
                                                                $line_waitlist = 0;
                                                                foreach ( $registrations as $r ) {
                                                                    if ( ! isset( $r['tournament'] ) || strcasecmp( trim( $r['tournament'] ), trim( $t_name ) ) !== 0 ) continue;
                                                                    $match_idx  = isset( $r['line_index'] ) && (string)$r['line_index'] === (string)$idx;
                                                                    $match_name = isset( $r['line_name'] ) && is_string( $idx ) && strcasecmp( trim( $r['line_name'] ), trim( $line_title ) ) === 0;
                                                                    if ( $match_idx || $match_name ) {
                                                                        if ( isset( $r['status'] ) && strcasecmp( trim( $r['status'] ), 'Waitlist' ) === 0 ) {
                                                                            $line_waitlist++;
                                                                        }
                                                                    }
                                                                }
                                                            ?>
                                                            <div class="sywat-line-slot-compact">
                                                                <span class="sywat-line-slot-title"><?php echo esc_html( $line_title ); ?></span>
                                                                <div class="sywat-line-slot-caps">
                                                                    <span>20yd: <strong><?php echo $c_20; ?>/<?php echo $m_20; ?></strong> <span class="<?php echo ($rem_20 > 0) ? 'sywat-text-open' : 'sywat-text-closed'; ?>">(<?php echo $rem_20; ?> open)</span></span>
                                                                    <span>•</span>
                                                                    <span>Cub: <strong><?php echo $c_cub; ?>/<?php echo $m_cub; ?></strong> <span class="<?php echo ($rem_cub > 0) ? 'sywat-text-open' : 'sywat-text-closed'; ?>">(<?php echo $rem_cub; ?> open)</span></span>
                                                                    <?php if ( $line_waitlist > 0 ): ?>
                                                                        <span>•</span>
                                                                        <span class="sywat-waitlist-pill">🕒 <?php echo $line_waitlist; ?> Waitlisted</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="sywat-lines-total">
                                                    <strong>Total Registered:</strong> <?php echo $reg_count; ?> <?php echo ( $reg_count === 1 ? 'archer' : 'archers' ); ?>
                                                </div>
                                            </td>

                                            <!-- Column 3: Stacked Tournament Action Buttons -->
                                            <td class="sywat-col-center">
                                                <div class="sywat-table-actions-vertical">
                                                    <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=manage" class="sywat-btn sywat-btn-primary sywat-btn-icon-manage sywat-btn-full">Manage Shooters</a>
                                                    <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=print" class="sywat-btn sywat-btn-primary sywat-btn-icon-print sywat-btn-full">Print Roster</a>
                                                    <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=invoice" class="sywat-btn sywat-btn-primary sywat-btn-icon-invoice sywat-btn-full">Generate Invoice</a>
                                                    <a href="?edit_tournament_modal=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit sywat-btn-full">Edit Tournament</a>
                                                    
                                                    <form method="post" class="sywat-table-action-form" onsubmit="return confirm('Are you sure you want to delete \'<?php echo esc_js( $t_name ); ?>\'? This permanently purges all registered archers.');">
                                                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                        <input type="hidden" name="sywat_action" value="delete_tournament">
                                                        <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete sywat-btn-full">Delete Tournament</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php 
            // Render Modals via Modal Component Helper
            if ( function_exists( 'sywat_render_add_edit_tournament_modal' ) ) {
                sywat_render_add_edit_tournament_modal( $data );
            }
            if ( function_exists( 'sywat_render_import_schedule_modal' ) ) {
                sywat_render_import_schedule_modal( $data );
            }
            if ( function_exists( 'sywat_render_delegated_modal_container' ) ) {
                sywat_render_delegated_modal_container( $data );
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
