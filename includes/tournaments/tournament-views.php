<?php
/**
 * Sub-Module: Master Admin Portal Views & Navigation Tabs
 * Path: includes/tournaments/tournament-views.php
 * Author: SYWAT Archery
 * Version: 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'render_sywat_master_portal' ) ) {
    function render_sywat_master_portal() {
        // Enforce Central Permission Check
        if ( ! sywat_can_manage_tournaments() ) {
            return '<p style="color:red; padding:20px; font-weight:bold;">Access denied. Management access required.</p>';
        }

        ob_start();
        global $wpdb;

        $can_manage_seasons = sywat_can_manage_seasons();
        $user_shop_id       = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';
        $all_shops          = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
        $seasons            = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        $active_tournaments = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_tournaments ) ) $active_tournaments = array();

        // Load Registrations from DB with Master Roster Fallback
        $registrations = array();
        $results = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%'" );
        if ( is_array( $results ) ) {
            foreach ($results as $row) {
                $reg_data = maybe_unserialize($row->option_value);
                if (is_array($reg_data) && isset($reg_data['id'])) {
                    $registrations[] = $reg_data;
                }
            }
        }

        // Fallback: Query master registration options if single option keys return empty
        if ( empty( $registrations ) && function_exists( 'sywat_get_registrations' ) ) {
            $registrations = sywat_get_registrations();
        }

        $notice = '';
        $active_tab = isset( $_REQUEST['tab'] ) ? sanitize_text_field( $_REQUEST['tab'] ) : 'manage';

        // POST Action Handling
        $is_valid_nonce = (isset($_POST['sywat_nonce']) && wp_verify_nonce($_POST['sywat_nonce'], 'sywat_desk_action'));
        $action = isset($_POST['sywat_action']) ? $_POST['sywat_action'] : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action && !$is_valid_nonce && $action !== 'cancel') {
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce) {
            if ( function_exists( 'sywat_handle_tournament_post_actions' ) ) {
                $notice = sywat_handle_tournament_post_actions( $tournaments, $active_tournaments, $registrations, $can_manage_seasons, $user_shop_id );
            }
        }

        // Modal Variables
        $default_lines = array(
            array( 'name' => 'Saturday 10AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Saturday 2PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Sunday 10AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Sunday 2PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
        );

        $selected_tournament = isset( $_REQUEST['view_tournament'] ) ? sanitize_text_field( $_REQUEST['view_tournament'] ) : '';
        $modal_action        = isset( $_REQUEST['modal_action'] ) ? sanitize_text_field( $_REQUEST['modal_action'] ) : 'manage';
        $open_modal          = ! empty( $selected_tournament ) && isset( $tournaments[$selected_tournament] );

        $modal_edit_archer   = isset( $_REQUEST['edit_archer'] ) ? sanitize_text_field( $_REQUEST['edit_archer'] ) : '';
        $existing_families   = function_exists( 'sywat_get_existing_families' ) ? sywat_get_existing_families( $registrations ) : array();

        $open_tourn_modal    = isset( $_REQUEST['open_add_tournament'] ) || isset( $_REQUEST['edit_tournament_modal'] );
        $edit_t_target       = isset( $_REQUEST['edit_tournament_modal'] ) ? sanitize_text_field( $_REQUEST['edit_tournament_modal'] ) : '';
        
        $edit_t_data         = ( ! empty( $edit_t_target ) && isset( $tournaments[$edit_t_target] ) ) ? $tournaments[$edit_t_target] : null;
        $edit_t_lines        = $edit_t_data ? sywat_get_tournament_lines( $edit_t_data ) : $default_lines;
        $edit_t_shop         = $edit_t_data ? sywat_get_tournament_shop_id( $edit_t_data ) : ( $can_manage_seasons ? '' : $user_shop_id );
        $edit_t_season       = $edit_t_data ? sywat_get_tournament_season_slug( $edit_t_data ) : ( function_exists('sywat_get_active_season') ? sywat_get_active_season()['slug'] : '' );
        $edit_t_start        = ( is_array( $edit_t_data ) && isset( $edit_t_data['start_date'] ) ) ? $edit_t_data['start_date'] : '';
        $edit_t_end          = ( is_array( $edit_t_data ) && isset( $edit_t_data['end_date'] ) ) ? $edit_t_data['end_date'] : '';

        $form_line_count     = isset( $_POST['line_count'] ) ? intval( $_POST['line_count'] ) : count( $edit_t_lines );
        if ( $form_line_count < 1 ) $form_line_count = 1;
        if ( $form_line_count > 6 ) $form_line_count = 6;

        $available_lines = $open_modal ? sywat_get_tournament_lines( $tournaments[$selected_tournament] ) : array();
        $filter_line_idx = isset( $_REQUEST['filter_line_idx'] ) ? sanitize_text_field( $_REQUEST['filter_line_idx'] ) : 'all';
        $search_query    = isset( $_REQUEST['search_shooter'] ) ? trim( sanitize_text_field( $_REQUEST['search_shooter'] ) ) : '';
        $is_specific_line = ($filter_line_idx !== 'all' && $filter_line_idx !== '' && $filter_line_idx !== null);

        // Filter Tournaments by Shop Scoping & Collect Unique Shops for Filter Dropdown
        $scoped_tournaments = array();
        $filter_shops       = array();

        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop = sywat_get_tournament_shop_id( $t_data );
            if ( $can_manage_seasons || $t_shop === $user_shop_id ) {
                $scoped_tournaments[$t_name] = $t_data;
                if ( isset( $all_shops[$t_shop]['name'] ) ) {
                    $filter_shops[$t_shop] = $all_shops[$t_shop]['name'];
                }
            }
        }
        asort( $filter_shops );

        // Sort tournaments chronologically by Start Date (Soonest first)
        uasort( $scoped_tournaments, function( $a, $b ) {
            $a_date = ( is_array( $a ) && ! empty( $a['start_date'] ) ) ? $a['start_date'] : '';
            $b_date = ( is_array( $b ) && ! empty( $b['start_date'] ) ) ? $b['start_date'] : '';
            if ( $a_date === $b_date ) return 0;
            return ( $a_date < $b_date ) ? -1 : 1;
        });

        $tfaa_cache = get_option( 'sywat_tfaa_cache', array() );
        $tfaa_count = isset( $tfaa_cache['count'] ) ? $tfaa_cache['count'] : 0;
        ?>

        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <!-- Navigation Tabs -->
            <div class="sywat-nav-tabs no-print">
                <a href="?tab=manage" class="sywat-tab-item sywat-tab-icon-tournament <?php echo $active_tab === 'manage' ? 'active' : ''; ?>">Tournament Management</a>
                <?php if ( $can_manage_seasons ): ?>
                    <a href="?tab=seasons" class="sywat-tab-item sywat-tab-icon-season <?php echo $active_tab === 'seasons' ? 'active' : ''; ?>">Season Management</a>
                <?php endif; ?>
            </div>

            <?php if ( $active_tab === 'seasons' && $can_manage_seasons ): ?>
                <!-- TAB 2: SEASONS MANAGEMENT -->
                <?php if ( function_exists( 'sywat_render_seasons_tab' ) ) sywat_render_seasons_tab(); ?>

            <?php else: ?>
                <!-- TAB 1: TOURNAMENTS TABLE -->
                <div class="sywat-card no-print">
                    <div class="sywat-card-title">
                        <span>Configured Tournaments</span>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            
                            <div style="display: flex; gap: 5px; margin: 0; align-items: center; flex-wrap: nowrap;">
                                <select id="sywat_tournament_shop_filter" onchange="sywatApplyTournamentFilters()" style="padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; height: 32px; box-sizing: border-box; width: 140px;">
                                    <option value="all">All Shops</option>
                                    <?php foreach ( $filter_shops as $s_id => $s_name ): ?>
                                        <option value="<?php echo esc_attr( strtolower( $s_name ) ); ?>"><?php echo esc_html( $s_name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" id="sywat_tournament_search_input" oninput="sywatApplyTournamentFilters()" placeholder="Search..." autocomplete="off" style="padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 130px; height: 32px; box-sizing: border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height: 32px; padding: 4px 8px;" onclick="sywatResetTournamentFilters()">Clear</button>
                            </div>

                            <form method="post" style="margin: 0; display: inline-flex; align-items: center; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 20px; padding: 2px 4px 2px 10px; gap: 6px;">
                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                <input type="hidden" name="sywat_action" value="sync_tfaa_now">
                                <span style="font-size: 12px; color: #475569; font-weight: 600; white-space: nowrap;">
                                    👤 <strong><?php echo $tfaa_count; ?></strong> TFAA
                                </span>
                                <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-sync" style="height: 26px; padding: 2px 8px; font-size: 11px; border-radius: 14px; background: #ffffff;">Sync</button>
                            </form>

                            <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="openAddTournamentModal()">Add Tournament</button>
                        </div>
                    </div>

                    <div class="sywat-subtitle">Overview of configured tournaments, shooting lines, and archer administration.</div>

                    <?php if ( empty( $scoped_tournaments ) ): ?>
                        <p style="color:#646970; font-style:italic;">No tournaments created yet for this location. Click on <strong>"+ Add Tournament"</strong> to configure your first tournament.</p>
                    <?php else: ?>
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th style="width: 22%; text-align: center;">Tournament, Season & Location</th>
                                    <th style="width: 12%; text-align: center;">Volunteer Hub</th>
                                    <th style="width: 35%; text-align: center;">Line Slot Availability (20yd / Cub)</th>
                                    <th style="width: 16%; text-align: center;">Shooter Management</th>
                                    <th style="width: 15%; text-align: center;">Tournament Management</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $scoped_tournaments as $t_name => $t_data ): ?>
                                    <?php 
                                        $t_lines       = sywat_get_tournament_lines( $t_data );
                                        $t_shop_id     = sywat_get_tournament_shop_id( $t_data );
                                        $t_season_slug = sywat_get_tournament_season_slug( $t_data );
                                        $t_season_name = isset( $seasons[$t_season_slug]['name'] ) ? $seasons[$t_season_slug]['name'] : 'Active Season';
                                        $t_shop_name   = isset( $all_shops[$t_shop_id]['name'] ) ? $all_shops[$t_shop_id]['name'] : 'Cinnamon Creek Ranch';

                                        $s_date        = ( is_array( $t_data ) && isset( $t_data['start_date'] ) ) ? $t_data['start_date'] : '';
                                        $e_date        = ( is_array( $t_data ) && isset( $t_data['end_date'] ) ) ? $t_data['end_date'] : '';
                                        $disp_dates    = sywat_format_tournament_dates( $s_date, $e_date );
                                        if ( empty( $disp_dates ) ) {
                                            $disp_dates = get_option( 'sywat_dates_' . sanitize_title( $t_name ), 'Dates Not Set' );
                                        }

                                        $line_count    = is_array( $t_lines ) ? count( $t_lines ) : 0;
                                        $reg_count     = sywat_get_tournament_count( $t_name, $registrations );
                                        $is_active_hub = in_array( $t_name, $active_tournaments );
                                        $is_scores_pub = ! empty( $t_data['scores_published'] );

                                        $confirm_msg_raw = $is_scores_pub
                                            ? sprintf( 'Are you sure you want to recall scores for "%s" and hide them from Season Standings?', $t_name )
                                            : sprintf( 'Are you sure you want to push scores for "%s" to Season Standings?', $t_name );
                                    ?>
                                    <tr class="sywat-tournament-row" data-name="<?php echo esc_attr(strtolower($t_name)); ?>" data-shop="<?php echo esc_attr(strtolower($t_shop_name)); ?>">
                                        <td>
                                            <strong><?php echo esc_html( $t_name ); ?></strong>
                                            <div style="font-size: 11px; color: #1e3a8a; font-weight: 700; margin-top: 2px;">🏆 <?php echo esc_html( $t_season_name ); ?></div>
                                            <div style="font-size: 12px; color: #2271b1; font-weight: 600; margin-top: 2px;">📍 <?php echo esc_html( $t_shop_name ); ?></div>
                                            <div style="font-size: 11px; color: #646970; margin-top: 2px;">📅 <?php echo esc_html( $disp_dates ); ?></div>
                                            <div style="font-size: 11px; color: #646970; margin-top: 1px;"><?php echo $line_count . ' ' . ( $line_count === 1 ? 'line' : 'lines' ); ?></div>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ( $t_shop_id === 'shop_ccr' ): ?>
                                                <form method="post" style="margin: 0;">
                                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                    <input type="hidden" name="sywat_action" value="toggle_active_tournament">
                                                    <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                    <?php if ( $is_active_hub ): ?>
                                                        <button type="submit" class="sywat-btn sywat-btn-success" style="padding: 4px 8px; font-size: 11px; height: auto;">🟢 Active (Hide)</button>
                                                    <?php else: ?>
                                                        <button type="submit" class="sywat-btn sywat-btn-secondary" style="padding: 4px 8px; font-size: 11px; height: auto;">⚪ Hidden (Activate)</button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #8c8f94; font-size: 12px; font-weight: 600;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( ! empty( $t_lines ) && is_array( $t_lines ) ): ?>
                                                <div style="display: flex; flex-direction: column; gap: 5px;">
                                                    <?php foreach ( $t_lines as $idx => $line ): ?>
                                                        <?php 
                                                            $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ($idx + 1);
                                                            $c_20 = sywat_count_registered( $t_name, $idx, '20yd', $registrations );
                                                            $m_20 = sywat_get_line_max_cap( $line, '20yd' );
                                                            $rem_20 = max( 0, $m_20 - $c_20 );
                                                            $c_cub = sywat_count_registered( $t_name, $idx, 'Cub', $registrations );
                                                            $m_cub = sywat_get_line_max_cap( $line, 'Cub' );
                                                            $rem_cub = max( 0, $m_cub - $c_cub );
                                                        ?>
                                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f8f9fa; padding: 5px 10px; border-radius: 4px; border: 1px solid #e2e8f0; font-size: 12px;">
                                                            <strong style="color: #1d2327;"><?php echo esc_html( $line_title ); ?></strong>
                                                            <div style="display: flex; gap: 10px; color: #50575e; align-items: center;">
                                                                <span>20yd: <strong><?php echo $c_20; ?>/<?php echo $m_20; ?></strong> <span style="color: <?php echo ($rem_20 > 0) ? '#008a20' : '#d63638'; ?>; font-weight: 600;">(<?php echo $rem_20; ?> open)</span></span>
                                                                <span>Cub: <strong><?php echo $c_cub; ?>/<?php echo $m_cub; ?></strong> <span style="color: <?php echo ($rem_cub > 0) ? '#008a20' : '#d63638'; ?>; font-weight: 600;">(<?php echo $rem_cub; ?> open)</span></span>
                                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=print&filter_line_idx=<?php echo $idx; ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-print" style="margin-left:5px; height:24px; padding:2px 6px; font-size:11px;">Print Line</a>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="margin-top: 8px; padding-top: 6px; border-top: 1px dashed #c3c4c7; font-size: 12px; color: #1d2327;">
                                                <strong>Total Registered:</strong> <?php echo $reg_count; ?> <?php echo ( $reg_count === 1 ? 'archer' : 'archers' ); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=manage" class="sywat-btn sywat-btn-primary sywat-btn-icon-manage" style="width: 100%;">Manage Shooters</a>
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=prereg" class="sywat-btn sywat-btn-primary sywat-btn-icon-add" style="width: 100%;">Register Shooters</a>
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=print" class="sywat-btn sywat-btn-primary sywat-btn-icon-print" style="width: 100%;">Print Roster</a>
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=invoice" class="sywat-btn sywat-btn-primary sywat-btn-icon-invoice" style="width: 100%;">Generate Invoice</a>
                                            </div>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-end;">
                                                <a href="/sywat-desk/?selected_tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin sywat-btn-full">Check-In Desk</a>
                                                <a href="/sywat-enter-scores/?selected_tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit sywat-btn-full">Enter Scores</a>

                                                <form method="post" class="sywat-table-action-form" onsubmit="return confirm('<?php echo esc_js( $confirm_msg_raw ); ?>');">
                                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                    <input type="hidden" name="sywat_action" value="toggle_publish_standings">
                                                    <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                    <?php if ( $is_scores_pub ): ?>
                                                        <button type="submit" class="sywat-btn sywat-btn-live sywat-btn-full" title="Scores are live in Season Standings. Click to recall/hide.">
                                                            🟢 Standings Live
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="submit" class="sywat-btn sywat-btn-push sywat-btn-full" title="Push tournament scores to Season Standings">
                                                            🚀 Push Standings
                                                        </button>
                                                    <?php endif; ?>
                                                </form>

                                                <a href="?edit_tournament_modal=<?php echo urlencode( $t_name ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit sywat-btn-full">Edit Config</a>
                                                
                                                <form method="post" class="sywat-table-action-form" onsubmit="return confirm('Are you sure you want to delete \'<?php echo esc_js( $t_name ); ?>\'? This permanently purges all registered archers.');">
                                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                    <input type="hidden" name="sywat_action" value="delete_tournament">
                                                    <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete sywat-btn-full">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- MODAL 1: ADD / EDIT TOURNAMENT MODAL -->
            <div class="sywat-modal-backdrop <?php echo $open_tourn_modal ? 'active' : ''; ?>" id="sywat_add_tourn_modal">
                <div class="sywat-modal-content" style="max-width: 700px;">
                    <div class="sywat-modal-header no-print">
                        <h3><?php echo ! empty( $edit_t_target ) ? 'Edit Tournament Configuration' : 'Create New Tournament'; ?></h3>
                        <button type="button" class="sywat-modal-close" onclick="closeAddTournamentModal()">&times;</button>
                    </div>
                    <div class="sywat-modal-body">
                        <form method="post" action="?tab=manage">
                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                            <input type="hidden" name="sywat_action" value="save_tournament">
                            <input type="hidden" name="original_tournament_name" value="<?php echo esc_attr( $edit_t_target ); ?>">

                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom: 15px;">
                                <div>
                                    <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Tournament Name *</label>
                                    <input type="text" name="tournament_name" value="<?php echo esc_attr( $edit_t_target ); ?>" placeholder="e.g. CCR October 2026" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;" required>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Competition Season *</label>
                                    <select name="season_slug" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; height:37px;" required>
                                        <?php foreach ( $seasons as $s_slug => $s_item ): ?>
                                            <option value="<?php echo esc_attr( $s_slug ); ?>" <?php selected( $edit_t_season, $s_slug ); ?>>
                                                <?php echo esc_html( $s_item['name'] ); ?> (<?php echo esc_html( ucfirst($s_item['status']) ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom: 15px;">
                                <div>
                                    <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Host Archery Shop *</label>
                                    <?php if ( $can_manage_seasons ): ?>
                                        <select name="shop_id" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; height:37px;" required>
                                            <option value="" <?php selected( empty( $edit_t_shop ), true ); ?>>Select Shop</option>
                                            <?php foreach ( $all_shops as $s_id => $s_name ): ?>
                                                <option value="<?php echo esc_attr( $s_id ); ?>" <?php selected( $edit_t_shop, $s_id ); ?>>
                                                    <?php echo esc_html( $s_name['name'] ); ?> (<?php echo esc_html( $s_id ); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="shop_id" value="<?php echo esc_attr( $user_shop_id ); ?>">
                                        <input type="text" value="<?php echo esc_attr( isset($all_shops[$user_shop_id]['name']) ? $all_shops[$user_shop_id]['name'] : 'Current Shop' ); ?>" readonly style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; background:#f0f0f1; font-weight:bold;">
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Start Date *</label>
                                    <input type="date" name="start_date" value="<?php echo esc_attr( $edit_t_start ); ?>" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;" required>
                                </div>
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">End Date (Optional for multi-day)</label>
                                <input type="date" name="end_date" value="<?php echo esc_attr( $edit_t_end ); ?>" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;">
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Number of Shooting Lines (1 - 6)</label>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <select name="line_count" style="padding:6px 10px; border:1px solid #8c8f94; border-radius:4px; width:130px; height:34px;">
                                        <?php for ($i = 1; $i <= 6; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php selected( $form_line_count, $i ); ?>><?php echo $i; ?> Line<?php echo $i > 1 ? 's' : ''; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <button type="submit" name="sywat_action" value="update_line_rows" class="sywat-btn sywat-btn-primary sywat-btn-icon-sync" formnovalidate>Update Line Rows</button>
                                </div>
                            </div>

                            <h4 style="font-size: 14px; font-weight: 600; margin-top: 15px; margin-bottom: 10px;">Shooting Lines & Capacity Templates</h4>
                            <table class="sywat-table" style="margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                        <th>Line Day / Time</th>
                                        <th style="width: 140px;">20yd Capacity</th>
                                        <th style="width: 140px;">Cub Capacity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($i = 0; $i < $form_line_count; $i++): ?>
                                        <?php 
                                            $fallback  = isset($default_lines[$i]) ? $default_lines[$i] : array( 'name' => 'Line ' . ($i + 1), 'cap_20yd' => 20, 'cap_cub' => 5 );
                                            $line_name = isset($edit_t_lines[$i]['name']) ? $edit_t_lines[$i]['name'] : $fallback['name'];
                                            $cap_20yd  = isset($edit_t_lines[$i]['cap_20yd']) ? $edit_t_lines[$i]['cap_20yd'] : $fallback['cap_20yd'];
                                            $cap_cub   = isset($edit_t_lines[$i]['cap_cub']) ? $edit_t_lines[$i]['cap_cub'] : $fallback['cap_cub'];
                                        ?>
                                        <tr>
                                            <td><input type="text" name="line_names[]" value="<?php echo esc_attr( $line_name ); ?>" placeholder="e.g. Saturday 10AM" style="width:100%; padding:6px; border:1px solid #8c8f94; border-radius:4px;" required></td>
                                            <td><input type="number" name="cap_20yd[]" value="<?php echo esc_attr( $cap_20yd ); ?>" min="0" style="width:100%; padding:6px; border:1px solid #8c8f94; border-radius:4px;" required></td>
                                            <td><input type="number" name="cap_cub[]" value="<?php echo esc_attr( $cap_cub ); ?>" min="0" style="width:100%; padding:6px; border:1px solid #8c8f94; border-radius:4px;" required></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>

                            <div style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeAddTournamentModal()">Cancel</button>
                                <button type="submit" name="sywat_action" value="save_tournament" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px; padding:8px 16px;">Save Tournament Configuration</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- MODAL 2: DELEGATED SHOOTER / ROSTER / INVOICE MODAL -->
            <div class="sywat-modal-backdrop <?php echo $open_modal ? 'active' : ''; ?>" id="sywat_modal">
                <div class="sywat-modal-content">
                    <div class="sywat-modal-header no-print">
                        <h3>
                            <?php 
                                if ($modal_action === 'invoice') echo 'TFAA Tournament Invoice';
                                elseif ($modal_action === 'print') echo 'Print Checked In Roster';
                                elseif ($modal_action === 'prereg') echo 'Register Shooters & Bulk Import';
                                else echo 'Manage Archers';
                            ?>: <?php echo esc_html( $selected_tournament ); ?>
                        </h3>
                        <button type="button" class="sywat-modal-close" onclick="closeSywatModal()">&times;</button>
                    </div>
                    <div class="sywat-modal-body">
                        <?php if ( $open_modal ): ?>
                            <?php 
                                if ( $modal_action === 'prereg' && function_exists( 'sywat_render_registration_modal' ) ) {
                                    sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations );
                                } elseif ( $modal_action === 'manage' && function_exists( 'sywat_render_roster_modal' ) ) {
                                    sywat_render_roster_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $search_query, $modal_edit_archer, $existing_families );
                                } elseif ( $modal_action === 'invoice' && function_exists( 'sywat_render_invoice_modal' ) ) {
                                    sywat_render_invoice_modal( $selected_tournament, $registrations );
                                } elseif ( $modal_action === 'print' && function_exists( 'sywat_render_print_modal' ) ) {
                                    sywat_render_print_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $is_specific_line );
                                }
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function openAddTournamentModal() {
            const modal = document.getElementById('sywat_add_tourn_modal');
            if (modal) modal.classList.add('active');
        }

        function closeAddTournamentModal() {
            const modal = document.getElementById('sywat_add_tourn_modal');
            if (modal) modal.classList.remove('active');
            const url = new URL(window.location.href);
            url.searchParams.delete('open_add_tournament');
            url.searchParams.delete('edit_tournament_modal');
            window.history.replaceState({}, document.title, url.toString());
        }

        function closeSywatModal() {
            const modal = document.getElementById('sywat_modal');
            if (modal) modal.classList.remove('active');
            const url = new URL(window.location.href);
            url.searchParams.delete('view_tournament');
            url.searchParams.delete('modal_action');
            url.searchParams.delete('filter_line_idx');
            url.searchParams.delete('search_shooter');
            window.history.replaceState({}, document.title, url.toString());
        }

        function sywatApplyTournamentFilters() {
            const shopSelect   = document.getElementById('sywat_tournament_shop_filter');
            const searchInput  = document.getElementById('sywat_tournament_search_input');

            const shopFilter   = shopSelect ? shopSelect.value.toLowerCase().trim() : 'all';
            const searchFilter = searchInput ? searchInput.value.toLowerCase().trim() : '';

            const rows = document.querySelectorAll('.sywat-tournament-row');

            rows.forEach(row => {
                const name = (row.getAttribute('data-name') || '').toLowerCase();
                const shop = (row.getAttribute('data-shop') || '').toLowerCase();

                const shopMatch   = (shopFilter === 'all' || shop === shopFilter);
                const searchMatch = (!searchFilter || name.includes(searchFilter) || shop.includes(searchFilter));

                if (shopMatch && searchMatch) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function sywatResetTournamentFilters() {
            const shopSelect  = document.getElementById('sywat_tournament_shop_filter');
            const searchInput = document.getElementById('sywat_tournament_search_input');
            if (shopSelect) shopSelect.value = 'all';
            if (searchInput) searchInput.value = '';
            sywatApplyTournamentFilters();
        }

        document.addEventListener("DOMContentLoaded", function() {
            const notice = document.getElementById('sywat-notice');
            if (notice) {
                setTimeout(function() {
                    notice.style.transition = "opacity 0.5s ease";
                    notice.style.opacity = "0";
                    setTimeout(function() { notice.remove(); }, 500);
                }, 5000);
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
}
