<?php
/**
 * Sub-Module: Master Admin Portal Views & Navigation Tabs
 * Path: includes/tournaments/tournament-views.php
 * Description: Renders the Manage Tournaments & Seasons portal with deferred POST-only pre-action registration loading (eliminating duplicate full-table scans on GET requests), canonical active/selected season resolution from sywat_get_master_portal_data(), explicit front-end Season filter form routing with filter_season preservation across row action links, single-pass O(R) pre-indexed tournament, line occupancy, and recorded-score counters, live Public Online Registration status indicators, flush-left side-by-side Volunteer Hub and score-gated Season Standings status pills (remaining Standings Pending until published via global Push Weekend Standings), clickable Waitlist pills, tournament-level Waitlist action buttons, and the dedicated Waitlist Management Modal (#sywat_waitlist_modal).
 * Compliance: Pure view layer — zero output at include time, zero inline style attributes, O(R) pre-indexed counters, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.22.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

        $notice     = '';
        $active_tab = isset( $_REQUEST['tab'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) ) : 'manage';

        // 2. Execute POST Actions FIRST (load mutable collections only on valid POST requests)
        $is_valid_nonce = ( isset( $_POST['sywat_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ), 'sywat_desk_action' ) )
                       || ( isset( $_POST['sywat_import_schedule_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_import_schedule_nonce'] ) ), 'sywat_import_schedule_nonce' ) )
                       || ( isset( $_POST['sywat_confirm_schedule_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_confirm_schedule_nonce'] ) ), 'sywat_confirm_schedule_nonce' ) );

        $action = isset( $_POST['sywat_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) ) : '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $action && ! $is_valid_nonce && $action !== 'cancel' ) {
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        } elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce ) {
            $tournaments        = get_option( 'sywat_tournaments_list', array() );
            $active_tournaments = get_option( 'sywat_active_tournaments', array() );
            $registrations      = function_exists( 'sywat_get_all_registrations' ) ? sywat_get_all_registrations( $tournaments ) : array();

            if ( function_exists( 'sywat_handle_tournament_post_actions' ) ) {
                $notice = sywat_handle_tournament_post_actions( $tournaments, $active_tournaments, $registrations, $can_manage_seasons, $user_shop_id );
            }
        }

        // 3. Fetch fresh master portal data bundle AFTER actions mutate database (100% Pure Read-Only)
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

        // Use canonical selected season resolved by sywat_get_master_portal_data()
        $selected_season = ! empty( $data['selected_season'] )
            ? (string) $data['selected_season']
            : ( isset( $_GET['filter_season'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_season'] ) ) : '' );
        if ( empty( $selected_season ) && ! empty( $seasons ) ) {
            foreach ( $seasons as $s_slug => $s_data ) {
                if ( is_array( $s_data ) && isset( $s_data['status'] ) && $s_data['status'] === 'active' ) {
                    $selected_season = $s_slug;
                    break;
                }
            }
            if ( empty( $selected_season ) ) {
                reset( $seasons );
                $selected_season = key( $seasons );
            }
        }

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

        // Build list of unique TFAA districts & pre-resolve shooting lines across scoped tournaments
        $filter_districts        = array();
        $scoped_lines_by_tourn   = array();
        $scoped_line_titles      = array();
        $tourn_valid_line_idxs   = array();
        $tourn_line_name_to_idx  = array();
        $tourn_counts            = array();

        $normalize_line_title = function( $line, $idx ) {
            $day_str    = isset( $line['day'] ) ? $line['day'] : '';
            $time_str   = isset( $line['time'] ) ? $line['time'] : ( isset( $line['start_time'] ) ? $line['start_time'] : '' );
            $line_title = ! empty( $line['name'] ) ? $line['name'] : ( ( ! empty( $day_str ) && ! empty( $time_str ) ) ? trim( $day_str . ' ' . $time_str ) : 'Line ' . ( $idx + 1 ) );

            $line_title = preg_replace( '/\b6(?::00)?\s*AM\b/i', '6PM', $line_title );
            $line_title = preg_replace( '/\b6:30\s*AM\b/i', '6:30PM', $line_title );
            $line_title = preg_replace( '/(\d{1,2}):00\s*(AM|PM)/i', '$1$2', $line_title );

            return $line_title;
        };

        foreach ( $scoped_tournaments as $t_name => $t_info ) {
            $s_id   = sywat_get_tournament_shop_id( $t_info );
            $d_name = '';

            if ( ! empty( $s_id ) && isset( $all_shops[ $s_id ]['district'] ) && ! empty( $all_shops[ $s_id ]['district'] ) ) {
                $d_name = $all_shops[ $s_id ]['district'];
            } else {
                $resolved_name = function_exists( 'sywat_get_tournament_shop_name' )
                    ? sywat_get_tournament_shop_name( $t_info, $t_name, $all_shops )
                    : ( ! empty( $t_info['shop_name'] ) ? $t_info['shop_name'] : '' );
                $norm_target = strtolower( trim( $resolved_name ) );
                if ( ! empty( $norm_target ) && isset( $shop_lookup_map[ $norm_target ] ) ) {
                    $d_name = $shop_lookup_map[ $norm_target ]['district'];
                }
            }

            if ( ! empty( $d_name ) && ! in_array( $d_name, $filter_districts, true ) ) {
                $filter_districts[] = $d_name;
            }

            $t_key                        = strtolower( trim( (string) $t_name ) );
            $t_lines                      = sywat_get_tournament_lines( $t_info );
            $scoped_lines_by_tourn[ $t_name ] = $t_lines;
            $scoped_line_titles[ $t_name ]    = array();
            $tourn_valid_line_idxs[ $t_key ]  = array();
            $tourn_line_name_to_idx[ $t_key ] = array();
            $tourn_counts[ $t_key ]           = array(
                'active'     => 0,
                'waitlist'   => 0,
                'has_scores' => false,
                'lines'      => array(),
            );

            if ( ! empty( $t_lines ) && is_array( $t_lines ) ) {
                foreach ( $t_lines as $idx => $line ) {
                    $idx_str    = (string) $idx;
                    $line_title = $normalize_line_title( $line, $idx );

                    $scoped_line_titles[ $t_name ][ $idx_str ]                    = $line_title;
                    $tourn_valid_line_idxs[ $t_key ][ $idx_str ]                  = true;
                    $tourn_line_name_to_idx[ $t_key ][ strtolower( trim( $line_title ) ) ] = $idx_str;
                    $tourn_counts[ $t_key ]['lines'][ $idx_str ]                  = array(
                        '20yd'     => 0,
                        'Cub'      => 0,
                        'waitlist' => 0,
                    );
                }
            }
        }
        sort( $filter_districts );

        // Single-pass O(R) aggregation of active, waitlist, recorded-score, 20yd, and Cub counts across scoped tournaments
        if ( is_array( $registrations ) && ! empty( $tourn_counts ) ) {
            foreach ( $registrations as $r ) {
                if ( ! is_array( $r ) || ! isset( $r['tournament'] ) ) {
                    continue;
                }
                $t_key = strtolower( trim( (string) $r['tournament'] ) );
                if ( ! isset( $tourn_counts[ $t_key ] ) ) {
                    continue;
                }

                $status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
                if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                    continue;
                }

                if ( isset( $r['score'] ) && intval( $r['score'] ) > 0 ) {
                    $tourn_counts[ $t_key ]['has_scores'] = true;
                }

                $resolved_idx = '';
                if ( isset( $r['line_index'] ) && $r['line_index'] !== '' ) {
                    $raw_l_idx = (string) $r['line_index'];
                    if ( isset( $tourn_valid_line_idxs[ $t_key ][ $raw_l_idx ] ) ) {
                        $resolved_idx = $raw_l_idx;
                    } else {
                        $norm_l_idx = strtolower( trim( $raw_l_idx ) );
                        if ( isset( $tourn_line_name_to_idx[ $t_key ][ $norm_l_idx ] ) ) {
                            $resolved_idx = $tourn_line_name_to_idx[ $t_key ][ $norm_l_idx ];
                        }
                    }
                }
                if ( $resolved_idx === '' && ! empty( $r['line_name'] ) ) {
                    $norm_l_name = strtolower( trim( (string) $r['line_name'] ) );
                    if ( isset( $tourn_line_name_to_idx[ $t_key ][ $norm_l_name ] ) ) {
                        $resolved_idx = $tourn_line_name_to_idx[ $t_key ][ $norm_l_name ];
                    }
                }

                if ( strcasecmp( $status, 'Waitlist' ) === 0 ) {
                    $tourn_counts[ $t_key ]['waitlist']++;
                    if ( $resolved_idx !== '' && isset( $tourn_counts[ $t_key ]['lines'][ $resolved_idx ] ) ) {
                        $tourn_counts[ $t_key ]['lines'][ $resolved_idx ]['waitlist']++;
                    }
                    continue;
                }

                $tourn_counts[ $t_key ]['active']++;
                if ( $resolved_idx !== '' && isset( $tourn_counts[ $t_key ]['lines'][ $resolved_idx ] ) ) {
                    $reg_dist = isset( $r['distance'] ) ? sywat_normalize_dist( $r['distance'] ) : '20yd';
                    $tourn_counts[ $t_key ]['lines'][ $resolved_idx ][ $reg_dist ]++;
                }
            }
        }

        $manage_page_url = function_exists( 'sywat_page_url' ) ? sywat_page_url( 'manage_tournaments' ) : '';
        $manage_base_url = $manage_page_url;
        if ( ! empty( $selected_season ) && ! empty( $manage_base_url ) ) {
            $manage_base_url = add_query_arg( 'filter_season', $selected_season, $manage_base_url );
        }
        $season_query_suffix = ! empty( $selected_season ) ? '&filter_season=' . urlencode( $selected_season ) : '';
        ?>

        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <!-- Operational Navigation Tabs (Card-Docked) -->
            <div class="sywat-nav-tabs no-print">
                <a href="<?php echo esc_url( $manage_page_url ); ?>" class="sywat-tab-item sywat-tab-icon-tournament <?php echo ( $active_tab === 'manage' ) ? 'active' : ''; ?>">Manage Tournaments</a>
                <?php if ( $can_access_desk ) : ?>
                    <a href="<?php echo esc_url( sywat_page_url( 'register' ) ); ?>" class="sywat-tab-item sywat-tab-icon-register">Register Shooters</a>
                    <a href="<?php echo esc_url( sywat_page_url( 'desk' ) ); ?>" class="sywat-tab-item sywat-tab-icon-checkin">Check In Archers</a>
                <?php endif; ?>
                <?php if ( $can_edit_scores ) : ?>
                    <a href="<?php echo esc_url( sywat_page_url( 'enter_scores' ) ); ?>" class="sywat-tab-item sywat-tab-icon-edit">Enter Scores</a>
                <?php endif; ?>
                <?php if ( $can_manage_seasons ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', 'seasons', $manage_page_url ) ); ?>" class="sywat-tab-item sywat-tab-icon-season <?php echo ( $active_tab === 'seasons' ) ? 'active' : ''; ?>">Manage Seasons</a>
                <?php endif; ?>
            </div>

            <?php if ( $active_tab === 'seasons' && $can_manage_seasons ) : ?>
                <!-- TAB 2: SEASONS MANAGEMENT -->
                <?php if ( function_exists( 'sywat_render_seasons_tab' ) ) sywat_render_seasons_tab(); ?>

            <?php else : ?>
                <!-- TAB 1: TOURNAMENTS TABLE -->
                <div class="sywat-card no-print">
                    <!-- UNIFIED TOP CARD HEADER BAR -->
                    <div class="sywat-card-header-bar">
                        <div>
                            <h2>🎯 Manage Tournaments</h2>
                            <div class="sywat-subtitle">Set up upcoming events, configure shooting lines, and print desk rosters.</div>
                        </div>

                        <div class="sywat-table-header-controls">
                            <!-- Sub-wrapper 1: Filters (Season Dropdown) -->
                            <div class="sywat-table-header-filters">
                                <?php if ( $can_manage_seasons && ! empty( $seasons ) ) : ?>
                                    <form method="get" action="<?php echo esc_url( $manage_page_url ); ?>">
                                        <div class="sywat-field-group">
                                            <label for="sywat_select_season">Season:</label>
                                            <select id="sywat_select_season" name="filter_season" class="sywat-select-season" onchange="this.form.submit();">
                                                <?php foreach ( $seasons as $s_slug => $s_data ) : ?>
                                                    <?php
                                                    $s_name = is_array( $s_data ) && isset( $s_data['name'] ) ? $s_data['name'] : $s_slug;
                                                    ?>
                                                    <option value="<?php echo esc_attr( $s_slug ); ?>" <?php selected( $s_slug, $selected_season ); ?>>
                                                        <?php echo esc_html( $s_name ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <!-- Sub-wrapper 2: Action Buttons -->
                            <div class="sywat-table-header-actions">
                                <!-- Global Master Desk Security Nonce Field for JS AJAX Engines -->
                                <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>

                                <?php if ( $can_manage_seasons && ! empty( $seasons ) ) : ?>
                                    <form method="post" action="<?php echo esc_url( $manage_base_url ); ?>">
                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                        <input type="hidden" name="sywat_action" value="sync_season_tfaa_urls">
                                        <input type="hidden" name="season_slug" value="<?php echo esc_attr( $selected_season ); ?>">
                                        <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-sync" title="Scrape TFAA season index page and populate direct links for all scheduled events">Sync TFAA Links</button>
                                    </form>
                                <?php endif; ?>

                                <!-- Global Bulk Sync Tournaments with Configured Results Links (Asynchronous Modal Launch) -->
                                <?php if ( $can_manage_seasons ) : ?>
                                    <button type="button" class="sywat-btn sywat-btn-primary sywat-btn-icon-sync" onclick="sywatOpenTfaaSyncModal()" title="Pull latest scores from TFAA for all tournaments with configured results links">Sync TFAA Scores</button>
                                <?php endif; ?>

                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-import" onclick="openImportScheduleModal()">Bulk Import Schedule</button>
                                <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="openAddTournamentModal()">Add Tournament</button>
                            </div>

                        </div>
                    </div>

                    <!-- TABLE SUB-HEADER ROW (Blueprint Pure View Layout) -->
                    <div class="sywat-table-header-bar">
                        <h2>Configured Events</h2>

                        <div class="sywat-table-header-controls">
                            <div class="sywat-table-header-filters">
                                <!-- District Dropdown Filter -->
                                <?php if ( count( $filter_districts ) > 0 ) : ?>
                                    <div class="sywat-field-group">
                                        <label for="sywat_tournament_district_filter">District:</label>
                                        <select id="sywat_tournament_district_filter" onchange="sywatApplyTournamentFilters()">
                                            <option value="all">All Districts</option>
                                            <?php foreach ( $filter_districts as $dist ) : ?>
                                                <option value="<?php echo esc_attr( strtolower( $dist ) ); ?>"><?php echo esc_html( $dist ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <input type="text" id="sywat_tournament_search_input" oninput="sywatApplyTournamentFilters()" placeholder="Search events..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">

                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetTournamentFilters()">Clear</button>
                            </div>

                            <div class="sywat-table-header-actions">
                                <!-- Option 1: Deactivate All Hubs Form -->
                                <?php if ( $can_manage_seasons ) : ?>
                                    <form method="post" action="<?php echo esc_url( $manage_base_url ); ?>" onsubmit="return confirm('Are you sure you want to deactivate Volunteer Hub visibility across ALL tournaments?');">
                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                        <input type="hidden" name="sywat_action" value="deactivate_all_hubs">
                                        <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-reset">Deactivate All Hubs</button>
                                    </form>
                                <?php endif; ?>

                                <!-- Push Standings Form -->
                                <?php if ( $can_manage_seasons ) : ?>
                                    <form method="post" action="<?php echo esc_url( $manage_base_url ); ?>" onsubmit="return confirm('Are you sure you want to push recorded weekend scores for this season to Season Standings? Tournaments with recorded scores will be published to the public leaderboard.');">
                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                        <input type="hidden" name="sywat_action" value="global_push_standings">
                                        <input type="hidden" name="season_slug" value="<?php echo esc_attr( $selected_season ); ?>">
                                        <button type="submit" class="sywat-btn sywat-btn-global-push sywat-btn-icon-global-push">Push Weekend Standings</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ( empty( $scoped_tournaments ) ) : ?>
                        <p class="sywat-score-null">No tournaments found for the selected season. Click on <strong>"Add Tournament"</strong> to configure an event.</p>
                    <?php else : ?>
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
                                    <?php foreach ( $scoped_tournaments as $t_name => $t_data ) : ?>
                                        <?php
                                            $t_key         = strtolower( trim( (string) $t_name ) );
                                            $t_lines       = isset( $scoped_lines_by_tourn[ $t_name ] ) ? $scoped_lines_by_tourn[ $t_name ] : sywat_get_tournament_lines( $t_data );
                                            $t_shop_id     = sywat_get_tournament_shop_id( $t_data );
                                            $t_season_slug = sywat_get_tournament_season_slug( $t_data );
                                            $t_season_name = isset( $seasons[ $t_season_slug ]['name'] ) ? $seasons[ $t_season_slug ]['name'] : $t_season_slug;

                                            // Dynamic Host Shop Name Resolution via 4-Tier Waterfall
                                            $t_shop_name = function_exists( 'sywat_get_tournament_shop_name' )
                                                ? sywat_get_tournament_shop_name( $t_data, $t_name, $all_shops )
                                                : ( is_array( $t_data ) && ! empty( $t_data['shop_name'] ) ? $t_data['shop_name'] : 'Host Archery Shop' );

                                            // Resolve District via Direct Shop ID or Name Lookup Fallback
                                            $t_district = '';
                                            if ( ! empty( $t_shop_id ) && isset( $all_shops[ $t_shop_id ]['district'] ) && ! empty( $all_shops[ $t_shop_id ]['district'] ) ) {
                                                $t_district = $all_shops[ $t_shop_id ]['district'];
                                            } else {
                                                $norm_target = strtolower( trim( $t_shop_name ) );
                                                if ( ! empty( $norm_target ) && isset( $shop_lookup_map[ $norm_target ] ) ) {
                                                    $t_district = $shop_lookup_map[ $norm_target ]['district'];
                                                }
                                            }

                                            $s_date     = ( is_array( $t_data ) && isset( $t_data['start_date'] ) ) ? $t_data['start_date'] : '';
                                            $e_date     = ( is_array( $t_data ) && isset( $t_data['end_date'] ) ) ? $t_data['end_date'] : '';
                                            $disp_dates = sywat_format_tournament_dates( $s_date, $e_date );
                                            if ( empty( $disp_dates ) ) {
                                                $disp_dates = get_option( 'sywat_dates_' . sanitize_title( $t_name ), 'Dates Not Set' );
                                            }

                                            $line_count           = is_array( $t_lines ) ? count( $t_lines ) : 0;
                                            $reg_count            = isset( $tourn_counts[ $t_key ]['active'] ) ? $tourn_counts[ $t_key ]['active'] : 0;
                                            $total_tourn_waitlist = isset( $tourn_counts[ $t_key ]['waitlist'] ) ? $tourn_counts[ $t_key ]['waitlist'] : 0;
                                            $has_recorded_scores  = ! empty( $tourn_counts[ $t_key ]['has_scores'] );
                                            $is_active_hub        = in_array( $t_name, $active_tournaments, true );
                                            $is_scores_published  = ( $has_recorded_scores && is_array( $t_data ) && ! empty( $t_data['scores_published'] ) );

                                            // Resolve TFAA Link Status Indicator
                                            $tfaa_link = ! empty( $t_data['tfaa_results_url'] ) ? $t_data['tfaa_results_url'] : '';
                                            if ( empty( $tfaa_link ) && ! empty( $t_data['tfaa_event_id'] ) ) {
                                                $eid       = trim( $t_data['tfaa_event_id'] );
                                                $tfaa_link = is_numeric( $eid ) ? 'https://texasfieldarchery.org/results/' . $eid : $eid;
                                            }

                                            // Evaluate live Public Online Registration gatekeeper status
                                            $public_reg_status = function_exists( 'sywat_get_public_registration_status' )
                                                ? sywat_get_public_registration_status( $t_data )
                                                : array(
                                                    'status'      => 'closed',
                                                    'reason'      => 'disabled',
                                                    'opens_date'  => '',
                                                    'opens_label' => '',
                                                );

                                            $tourn_waitlist_url = ( $total_tourn_waitlist > 0 && function_exists( 'sywat_waitlist_get_modal_url' ) )
                                                ? sywat_waitlist_get_modal_url( $t_name, 'all', $manage_base_url )
                                                : '';
                                        ?>
                                        <tr class="sywat-tournament-row" data-name="<?php echo esc_attr( strtolower( $t_name ) ); ?>" data-shop="<?php echo esc_attr( strtolower( $t_shop_name ) ); ?>" data-district="<?php echo esc_attr( strtolower( $t_district ) ); ?>">
                                            <!-- Column 1: Metadata, Volunteer Hub Toggle & Season Standings Status -->
                                            <td>
                                                <strong class="sywat-tourn-title"><?php echo esc_html( $t_name ); ?></strong>
                                                <div class="sywat-tourn-season">🏆 <?php echo esc_html( $t_season_name ); ?></div>
                                                <div class="sywat-tourn-shop">📍 <?php echo esc_html( $t_shop_name ); ?><?php if ( ! empty( $t_district ) ) : ?> <span class="sywat-label-subtext">(<?php echo esc_html( $t_district ); ?>)</span><?php endif; ?></div>
                                                <div class="sywat-tourn-dates">📅 <?php echo esc_html( $disp_dates ); ?> (<?php echo $line_count . ' ' . ( $line_count === 1 ? 'line' : 'lines' ); ?>)</div>

                                                <?php if ( ! empty( $tfaa_link ) ) : ?>
                                                    <div class="sywat-tourn-tfaa-link">🔗 <a href="<?php echo esc_url( $tfaa_link ); ?>" target="_blank" rel="noopener">TFAA Direct Link ↗</a></div>
                                                <?php else : ?>
                                                    <div class="sywat-tourn-tfaa-link sywat-label-subtext">🔗 TFAA Link: Pending</div>
                                                <?php endif; ?>

                                                <?php if ( $public_reg_status['status'] === 'open' ) : ?>
                                                    <div class="sywat-tourn-tfaa-link"><span class="sywat-badge sywat-badge-success">Public Reg: Open</span></div>
                                                <?php elseif ( $public_reg_status['status'] === 'opens_soon' ) : ?>
                                                    <?php $opens_lbl = ! empty( $public_reg_status['opens_label'] ) ? $public_reg_status['opens_label'] : $public_reg_status['opens_date']; ?>
                                                    <div class="sywat-tourn-tfaa-link"><span class="sywat-badge sywat-badge-warning">Public Reg: Opens <?php echo esc_html( $opens_lbl ); ?></span></div>
                                                <?php elseif ( isset( $public_reg_status['reason'] ) && $public_reg_status['reason'] === 'past' ) : ?>
                                                    <div class="sywat-tourn-tfaa-link sywat-label-subtext">Public Reg: Closed (Event Ended)</div>
                                                <?php else : ?>
                                                    <div class="sywat-tourn-tfaa-link sywat-label-subtext">Public Reg: Off</div>
                                                <?php endif; ?>

                                                <!-- Volunteer Hub Toggle & Season Standings Status Pills -->
                                                <div class="sywat-table-header-actions sywat-mt-3">
                                                    <form method="post" action="<?php echo esc_url( $manage_base_url ); ?>">
                                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                        <input type="hidden" name="sywat_action" value="toggle_active_tournament">
                                                        <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                        <?php if ( $is_active_hub ) : ?>
                                                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-sm" title="Visible in Volunteer Hub">🟢 Hub Active</button>
                                                        <?php else : ?>
                                                            <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm" title="Click to activate in Volunteer Hub">⚪ Hub Hidden</button>
                                                        <?php endif; ?>
                                                    </form>

                                                    <?php if ( $can_manage_seasons ) : ?>
                                                        <?php if ( $is_scores_published ) : ?>
                                                            <form method="post" action="<?php echo esc_url( $manage_base_url ); ?>">
                                                                <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                                <input type="hidden" name="sywat_action" value="toggle_publish_standings">
                                                                <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-sm" title="Published in Season Standings — click to hide from Season Standings">🟢 In Standings</button>
                                                            </form>
                                                        <?php else : ?>
                                                            <span class="sywat-btn sywat-btn-secondary sywat-btn-sm" title="<?php echo esc_attr( $has_recorded_scores ? 'Scores entered — click Push Weekend Standings to publish to Season Standings' : 'No scores entered yet — unpublished' ); ?>">⚪ Standings Pending</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Column 2: 2-Column Line Slot Capacity Grid -->
                                            <td class="sywat-col-center">
                                                <?php if ( ! empty( $t_lines ) && is_array( $t_lines ) ) : ?>
                                                    <div class="sywat-lines-grid">
                                                        <?php foreach ( $t_lines as $idx => $line ) : ?>
                                                            <?php
                                                                $idx_str       = (string) $idx;
                                                                $line_title    = isset( $scoped_line_titles[ $t_name ][ $idx_str ] ) ? $scoped_line_titles[ $t_name ][ $idx_str ] : $normalize_line_title( $line, $idx );
                                                                $line_counts   = isset( $tourn_counts[ $t_key ]['lines'][ $idx_str ] ) ? $tourn_counts[ $t_key ]['lines'][ $idx_str ] : array( '20yd' => 0, 'Cub' => 0, 'waitlist' => 0 );

                                                                $c_20          = $line_counts['20yd'];
                                                                $m_20          = sywat_get_line_max_cap( $line, '20yd' );
                                                                $rem_20        = max( 0, $m_20 - $c_20 );
                                                                $c_cub         = $line_counts['Cub'];
                                                                $m_cub         = sywat_get_line_max_cap( $line, 'Cub' );
                                                                $rem_cub       = max( 0, $m_cub - $c_cub );
                                                                $line_waitlist = $line_counts['waitlist'];
                                                            ?>
                                                            <div class="sywat-line-slot-compact">
                                                                <span class="sywat-line-slot-title"><?php echo esc_html( $line_title ); ?></span>
                                                                <div class="sywat-line-slot-caps">
                                                                    <span>20yd: <strong><?php echo $c_20; ?>/<?php echo $m_20; ?></strong> <span class="<?php echo ( $rem_20 > 0 ) ? 'sywat-text-open' : 'sywat-text-closed'; ?>">(<?php echo $rem_20; ?> open)</span></span>
                                                                    <span>•</span>
                                                                    <span>Cub: <strong><?php echo $c_cub; ?>/<?php echo $m_cub; ?></strong> <span class="<?php echo ( $rem_cub > 0 ) ? 'sywat-text-open' : 'sywat-text-closed'; ?>">(<?php echo $rem_cub; ?> open)</span></span>
                                                                    <?php if ( $line_waitlist > 0 ) : ?>
                                                                        <span>•</span>
                                                                        <?php if ( function_exists( 'sywat_waitlist_render_badge_link' ) ) : ?>
                                                                            <?php sywat_waitlist_render_badge_link( $t_name, $line_waitlist, $idx, $manage_base_url ); ?>
                                                                        <?php else : ?>
                                                                            <span class="sywat-waitlist-pill">🕒 <?php echo $line_waitlist; ?> Waitlisted</span>
                                                                        <?php endif; ?>
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
                                                    <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=manage<?php echo esc_attr( $season_query_suffix ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-manage sywat-btn-full">Manage Shooters</a>
                                                    <?php if ( $total_tourn_waitlist > 0 && ! empty( $tourn_waitlist_url ) ) : ?>
                                                        <a href="<?php echo esc_url( $tourn_waitlist_url ); ?>" class="sywat-btn sywat-btn-warning sywat-btn-full sywat-waitlist-trigger-btn" title="Open Waitlist Management Window">
                                                            <?php echo function_exists( 'sywat_waitlist_clock_svg' ) ? sywat_waitlist_clock_svg() : ''; ?>
                                                            <span>Waitlist (<?php echo intval( $total_tourn_waitlist ); ?>)</span>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=print<?php echo esc_attr( $season_query_suffix ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-print sywat-btn-full">Print Roster</a>
                                                    <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=invoice<?php echo esc_attr( $season_query_suffix ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-invoice sywat-btn-full">Generate Invoice</a>

                                                    <?php if ( ! empty( $tfaa_link ) && $can_manage_seasons ) : ?>
                                                        <form method="post" action="<?php echo esc_url( $manage_base_url ); ?>" class="sywat-table-action-form">
                                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                            <input type="hidden" name="sywat_action" value="sync_tfaa_single">
                                                            <input type="hidden" name="target_tournament" value="<?php echo esc_attr( $t_name ); ?>">
                                                            <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-sync sywat-btn-full" title="Sync scores for this tournament directly from TFAA">Sync TFAA Scores</button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <a href="?edit_tournament_modal=<?php echo urlencode( $t_name ); ?><?php echo esc_attr( $season_query_suffix ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit sywat-btn-full">Edit Tournament</a>

                                                    <form method="post" action="<?php echo esc_url( $manage_base_url ); ?>" class="sywat-table-action-form" onsubmit="return confirm('Are you sure you want to delete \'<?php echo esc_js( $t_name ); ?>\'? This permanently purges all registered archers.');">
                                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
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
            if ( function_exists( 'sywat_render_tfaa_sync_progress_modal' ) ) {
                sywat_render_tfaa_sync_progress_modal( $data );
            }
            if ( function_exists( 'sywat_maybe_render_waitlist_modal' ) ) {
                sywat_maybe_render_waitlist_modal(
                    $tournaments,
                    $registrations,
                    '',
                    $manage_base_url,
                    'manage',
                    array(
                        'close_url' => $manage_base_url,
                    )
                );
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
