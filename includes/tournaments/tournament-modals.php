<?php
/**
 * Sub-Module: Tournament Modals View Layer
 * Path: includes/tournaments/tournament-modals.php
 * Author: SYWAT Archery
 * Version: 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render the Add / Edit Tournament Modal
 *
 * @param array $data Central data bundle from data engine.
 */
if ( ! function_exists( 'sywat_render_add_edit_tournament_modal' ) ) {
    function sywat_render_add_edit_tournament_modal( $data ) {
        $tournaments        = $data['tournaments'];
        $can_manage_seasons = $data['can_manage_seasons'];
        $user_shop_id       = $data['user_shop_id'];
        $all_shops          = $data['all_shops'];
        $seasons            = $data['seasons'];

        $open_tourn_modal = isset( $_REQUEST['open_add_tournament'] ) || isset( $_REQUEST['edit_tournament_modal'] );
        $edit_t_target    = isset( $_REQUEST['edit_tournament_modal'] ) ? sanitize_text_field( $_REQUEST['edit_tournament_modal'] ) : '';
        $is_edit_mode     = ! empty( $edit_t_target );
        
        $edit_t_data      = ( $is_edit_mode && isset( $tournaments[$edit_t_target] ) ) ? $tournaments[$edit_t_target] : null;

        $edit_t_shop   = $edit_t_data ? sywat_get_tournament_shop_id( $edit_t_data ) : ( ! empty( $user_shop_id ) ? $user_shop_id : '' );
        $edit_t_season = $edit_t_data ? sywat_get_tournament_season_slug( $edit_t_data ) : ( function_exists('sywat_get_active_season') ? sywat_get_active_season()['slug'] : '' );
        $edit_t_start  = ( is_array( $edit_t_data ) && isset( $edit_t_data['start_date'] ) ) ? $edit_t_data['start_date'] : '';
        $edit_t_end    = ( is_array( $edit_t_data ) && isset( $edit_t_data['end_date'] ) ) ? $edit_t_data['end_date'] : '';

        // Resolve target shop key for fallback initialization
        $target_shop_key = $edit_t_shop;
        if ( empty( $target_shop_key ) && ! empty( $user_shop_id ) ) {
            $target_shop_key = $user_shop_id;
        }
        if ( empty( $target_shop_key ) && ! empty( $all_shops ) && is_array( $all_shops ) ) {
            $shop_keys       = array_keys( $all_shops );
            $target_shop_key = reset( $shop_keys );
        }

        if ( function_exists( 'sywat_get_shop_capacity_defaults' ) ) {
            $shop_caps       = sywat_get_shop_capacity_defaults( $target_shop_key );
            $default_cap_20  = $shop_caps['cap_20yd'];
            $default_cap_cub = $shop_caps['cap_cub'];
        } else {
            $init_shop_data  = ( ! empty( $target_shop_key ) && isset( $all_shops[$target_shop_key] ) ) ? $all_shops[$target_shop_key] : array();
            $default_cap_20  = ( is_array( $init_shop_data ) && isset( $init_shop_data['cap_20yd'] ) ) ? $init_shop_data['cap_20yd'] : ( ( is_array( $init_shop_data ) && isset( $init_shop_data['capacity_20yd'] ) ) ? $init_shop_data['capacity_20yd'] : ( ( is_array( $init_shop_data ) && isset( $init_shop_data['cap_20'] ) ) ? $init_shop_data['cap_20'] : 20 ) );
            $default_cap_cub = ( is_array( $init_shop_data ) && isset( $init_shop_data['cap_cub'] ) ) ? $init_shop_data['cap_cub'] : ( ( is_array( $init_shop_data ) && isset( $init_shop_data['capacity_cub'] ) ) ? $init_shop_data['capacity_cub'] : ( ( is_array( $init_shop_data ) && isset( $init_shop_data['cub_cap'] ) ) ? $init_shop_data['cub_cap'] : 5 ) );
        }

        if ( function_exists( 'sywat_get_default_tournament_lines' ) ) {
            $default_lines = sywat_get_default_tournament_lines( $target_shop_key );
        } else {
            $default_lines = array(
                array( 'day' => 'Saturday', 'time' => '10:00 AM', 'name' => 'Saturday 10:00 AM', 'cap_20yd' => $default_cap_20, 'cap_cub' => $default_cap_cub ),
                array( 'day' => 'Saturday', 'time' => '2:00 PM',  'name' => 'Saturday 2:00 PM',  'cap_20yd' => $default_cap_20, 'cap_cub' => $default_cap_cub ),
                array( 'day' => 'Sunday',   'time' => '10:00 AM', 'name' => 'Sunday 10:00 AM',   'cap_20yd' => $default_cap_20, 'cap_cub' => $default_cap_cub ),
                array( 'day' => 'Sunday',   'time' => '2:00 PM',  'name' => 'Sunday 2:00 PM',    'cap_20yd' => $default_cap_20, 'cap_cub' => $default_cap_cub ),
            );
        }

        $edit_t_lines = $edit_t_data ? sywat_get_tournament_lines( $edit_t_data ) : $default_lines;
        if ( empty( $edit_t_lines ) || ! is_array( $edit_t_lines ) ) {
            $edit_t_lines = $default_lines;
        }

        // Display titles for read-only edit fields
        $shop_display_name   = isset( $all_shops[$edit_t_shop]['name'] ) ? $all_shops[$edit_t_shop]['name'] : ( isset( $edit_t_data['shop_name'] ) ? $edit_t_data['shop_name'] : 'Host Archery Shop' );
        $season_display_name = isset( $seasons[$edit_t_season]['name'] ) ? $seasons[$edit_t_season]['name'] : $edit_t_season;

        // Build shop lookup mapping to resolve shop ID by shop name variations
        $shop_lookup_map = array();
        foreach ( $all_shops as $s_id => $s_data ) {
            if ( is_array( $s_data ) ) {
                $title = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : ( ! empty( $s_data['title'] ) ? $s_data['title'] : '' ) );
                if ( ! empty( $title ) ) {
                    $shop_lookup_map[ strtolower( trim( $title ) ) ] = $s_id;
                }
            }
        }

        // Calculate existing tournament counts & collect start dates per shop for auto-naming & chronological position logic
        $shop_counts = array();
        $shop_events = array();
        foreach ( $tournaments as $t_name => $t_info ) {
            $s_id = sywat_get_tournament_shop_id( $t_info );
            if ( empty( $s_id ) || ! isset( $all_shops[$s_id] ) ) {
                $shop_name = ! empty( $t_info['shop_name'] ) ? $t_info['shop_name'] : ( ! empty( $t_info['official_shop_name'] ) ? $t_info['official_shop_name'] : '' );
                $norm_name = strtolower( trim( $shop_name ) );
                if ( ! empty( $norm_name ) && isset( $shop_lookup_map[$norm_name] ) ) {
                    $s_id = $shop_lookup_map[$norm_name];
                }
            }
            if ( ! empty( $s_id ) ) {
                $shop_counts[$s_id] = isset( $shop_counts[$s_id] ) ? $shop_counts[$s_id] + 1 : 1;
                $start = ! empty( $t_info['start_date'] ) ? $t_info['start_date'] : '';
                if ( ! empty( $start ) ) {
                    $shop_events[$s_id][] = array(
                        'name'       => $t_name,
                        'start_date' => $start,
                    );
                }
            }
        }

        // Sort events chronologically per shop
        foreach ( $shop_events as $s_id => &$evts ) {
            usort( $evts, function( $a, $b ) {
                return strcmp( $a['start_date'], $b['start_date'] );
            });
        }
        unset( $evts );
        ?>
        <div class="sywat-modal-backdrop <?php echo $open_tourn_modal ? 'active' : ''; ?>" id="sywat_add_tourn_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <!-- STANDARDIZED MODAL HEADER -->
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>🎯 <?php echo $is_edit_mode ? 'Edit Tournament Configuration' : 'Create New Tournament'; ?></h3>
                        <div class="sywat-subtitle">Set up tournament details, dates, and shooting line capacity limits.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" onclick="closeAddTournamentModal()">&times;</button>
                </div>

                <form method="post" action="?tab=manage" id="sywat_tournament_config_form" onsubmit="return validateTournamentLines();">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                        <input type="hidden" name="sywat_action" value="save_tournament">
                        <input type="hidden" name="original_tournament_name" value="<?php echo esc_attr( $edit_t_target ); ?>">

                        <!-- ROW 1: SHOP (LEFT) | SEASON (RIGHT) -->
                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label>Host Archery Shop *</label>
                                <?php if ( $is_edit_mode ): ?>
                                    <input type="hidden" name="shop_id" value="<?php echo esc_attr( $edit_t_shop ); ?>">
                                    <input type="text" value="<?php echo esc_attr( $shop_display_name ); ?>" readonly class="sywat-input-disabled-calc">
                                <?php elseif ( $can_manage_seasons ): ?>
                                    <select name="shop_id" id="sywat_modal_shop_id" onchange="sywatAutoGenerateTournamentName()" required>
                                        <option value="" <?php selected( empty( $edit_t_shop ), true ); ?>>Select Shop</option>
                                        <?php foreach ( $all_shops as $s_id => $s_name ): ?>
                                            <?php 
                                                $count       = isset( $shop_counts[$s_id] ) ? $shop_counts[$s_id] : 0; 
                                                $events_json = isset( $shop_events[$s_id] ) ? esc_attr( wp_json_encode( $shop_events[$s_id] ) ) : '[]';
                                                $s_title     = is_array( $s_name ) && isset( $s_name['name'] ) ? $s_name['name'] : ( is_string( $s_name ) ? $s_name : '' );

                                                if ( function_exists( 'sywat_get_shop_capacity_defaults' ) ) {
                                                    // Pass $s_id or $s_name to resolve capacity defaults from master shop directory
                                                    $s_caps   = sywat_get_shop_capacity_defaults( $s_id );
                                                    $s_cap20  = $s_caps['cap_20yd'];
                                                    $s_capcub = $s_caps['cap_cub'];
                                                } else {
                                                    $s_cap20  = is_array( $s_name ) && isset( $s_name['cap_20yd'] ) ? $s_name['cap_20yd'] : ( ( is_array( $s_name ) && isset( $s_name['capacity_20yd'] ) ) ? $s_name['capacity_20yd'] : ( ( is_array( $s_name ) && isset( $s_name['cap_20'] ) ) ? $s_name['cap_20'] : 20 ) );
                                                    $s_capcub = is_array( $s_name ) && isset( $s_name['cap_cub'] ) ? $s_name['cap_cub'] : ( ( is_array( $s_name ) && isset( $s_name['capacity_cub'] ) ) ? $s_name['capacity_cub'] : ( ( is_array( $s_name ) && isset( $s_name['cub_cap'] ) ) ? $s_name['cub_cap'] : 5 ) );
                                                }
                                            ?>
                                            <option value="<?php echo esc_attr( $s_id ); ?>" 
                                                    data-name="<?php echo esc_attr( $s_title ); ?>" 
                                                    data-count="<?php echo $count; ?>" 
                                                    data-events="<?php echo $events_json; ?>"
                                                    data-cap20="<?php echo esc_attr( $s_cap20 ); ?>"
                                                    data-capcub="<?php echo esc_attr( $s_capcub ); ?>"
                                                    <?php selected( $edit_t_shop, $s_id ); ?>>
                                                <?php echo esc_html( $s_title ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="hidden" name="shop_id" id="sywat_modal_shop_id" value="<?php echo esc_attr( $user_shop_id ); ?>" data-cap20="<?php echo esc_attr( $default_cap_20 ); ?>" data-capcub="<?php echo esc_attr( $default_cap_cub ); ?>">
                                    <input type="text" value="<?php echo esc_attr( isset($all_shops[$user_shop_id]['name']) ? $all_shops[$user_shop_id]['name'] : 'Current Shop' ); ?>" readonly class="sywat-input-disabled-calc">
                                <?php endif; ?>
                            </div>

                            <div class="sywat-field-group">
                                <label>Competition Season *</label>
                                <?php if ( $is_edit_mode ): ?>
                                    <input type="hidden" name="season_slug" value="<?php echo esc_attr( $edit_t_season ); ?>">
                                    <input type="text" value="<?php echo esc_attr( $season_display_name ); ?>" readonly class="sywat-input-disabled-calc">
                                <?php else: ?>
                                    <select name="season_slug" required>
                                        <?php foreach ( $seasons as $s_slug => $s_item ): ?>
                                            <option value="<?php echo esc_attr( $s_slug ); ?>" <?php selected( $edit_t_season, $s_slug ); ?>>
                                                <?php echo esc_html( $s_item['name'] ); ?> (<?php echo esc_html( ucfirst($s_item['status']) ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ROW 2: NAME (LEFT) | DATES (RIGHT) -->
                        <div class="sywat-form-grid-2 sywat-modal-row-spacing">
                            <div class="sywat-field-group">
                                <label>Tournament Name * <?php if ( ! $is_edit_mode ): ?><span class="sywat-label-subtext">(Auto-generated, editable)</span><?php endif; ?></label>
                                <input type="text" name="tournament_name" id="sywat_modal_tournament_name" value="<?php echo esc_attr( $edit_t_target ); ?>" placeholder="Select shop and date to auto-generate..." oninput="sywatMarkNameAsCustomized()" <?php echo ( $is_edit_mode && ! $can_manage_seasons ) ? 'readonly class="sywat-input-disabled-calc"' : 'required'; ?>>
                            </div>

                            <div class="sywat-form-grid-2 sywat-grid-compact">
                                <div class="sywat-field-group">
                                    <label>Start Date *</label>
                                    <input type="date" name="start_date" id="sywat_modal_start_date" value="<?php echo esc_attr( $edit_t_start ); ?>" onchange="sywatAutoGenerateTournamentName()" required>
                                </div>
                                <div class="sywat-field-group">
                                    <label>End Date (Optional)</label>
                                    <input type="date" name="end_date" id="sywat_modal_end_date" value="<?php echo esc_attr( $edit_t_end ); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- STANDARDIZED SUB-HEADER -->
                        <div class="sywat-table-header-bar sywat-modal-section-header">
                            <h2>📋 Shooting Lines & Capacity Templates</h2>
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addTournamentLineRow()">+ Add New Line</button>
                        </div>

                        <!-- COMPACT REPEATER TABLE -->
                        <div class="sywat-table-responsive">
                            <table class="sywat-table sywat-modal-repeater-table" id="sywat_tourn_lines_table">
                                <thead>
                                    <tr>
                                        <th class="sywat-col-day">Shooting Day</th>
                                        <th class="sywat-col-time">Start Time</th>
                                        <th class="sywat-col-center sywat-col-cap">20yd Cap</th>
                                        <th class="sywat-col-center sywat-col-cap">Cub Cap</th>
                                        <th class="sywat-col-center sywat-col-action-sm">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $edit_t_lines as $i => $l ): ?>
                                        <?php 
                                            $day_val  = isset( $l['day'] ) ? $l['day'] : '';
                                            $time_val = isset( $l['time'] ) ? $l['time'] : ( isset( $l['start_time'] ) ? $l['start_time'] : '' );

                                            if ( ( empty( $day_val ) || empty( $time_val ) ) && ! empty( $l['name'] ) ) {
                                                $parts = explode( ' ', trim( $l['name'] ), 2 );
                                                if ( empty( $day_val ) ) {
                                                    $day_val = $parts[0];
                                                }
                                                if ( empty( $time_val ) && isset( $parts[1] ) ) {
                                                    $time_val = $parts[1];
                                                }
                                            }

                                            if ( function_exists( 'sywat_format_line_time_token' ) && ! empty( $time_val ) ) {
                                                $time_val = sywat_format_line_time_token( $time_val );
                                            }

                                            $cap_20yd = isset( $l['cap_20yd'] ) ? $l['cap_20yd'] : $default_cap_20;
                                            $cap_cub  = isset( $l['cap_cub'] ) ? $l['cap_cub'] : $default_cap_cub;
                                        ?>
                                        <tr>
                                            <td>
                                                <select name="line_days[]" onchange="checkTournamentLineDuplicate(this);">
                                                    <option value="" disabled <?php selected( empty($day_val), true ); ?>>-- Select --</option>
                                                    <option value="Friday" <?php selected( $day_val, 'Friday' ); ?>>Friday</option>
                                                    <option value="Saturday" <?php selected( $day_val, 'Saturday' ); ?>>Saturday</option>
                                                    <option value="Sunday" <?php selected( $day_val, 'Sunday' ); ?>>Sunday</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="line_times[]" value="<?php echo esc_attr( $time_val ); ?>" placeholder="10:00 AM" required onchange="checkTournamentLineDuplicate(this);">
                                            </td>
                                            <td class="sywat-col-center">
                                                <input type="number" name="cap_20yd[]" value="<?php echo esc_attr( $cap_20yd ); ?>" min="0" class="sywat-col-num-input" required>
                                            </td>
                                            <td class="sywat-col-center">
                                                <input type="number" name="cap_cub[]" value="<?php echo esc_attr( $cap_cub ); ?>" min="0" class="sywat-col-num-input" required>
                                            </td>
                                            <td class="sywat-col-center">
                                                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-sm" onclick="removeTournamentLineRow(this)">Remove</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeAddTournamentModal()">Cancel</button>
                        <button type="submit" name="sywat_action" value="save_tournament" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Tournament Configuration</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}

/**
 * Render Bulk Schedule Upload & Dry-Run Preview Overlays
 *
 * @param array $data Central data bundle from data engine.
 */
if ( ! function_exists( 'sywat_render_import_schedule_modal' ) ) {
    function sywat_render_import_schedule_modal( $data ) {
        $seasons        = isset( $data['seasons'] ) ? $data['seasons'] : array();
        $current_season = isset( $_GET['filter_season'] ) ? sanitize_text_field( $_GET['filter_season'] ) : ( function_exists('sywat_get_active_season') ? sywat_get_active_season()['slug'] : '' );

        // Handle Cancel Request
        if ( isset( $_POST['sywat_action'] ) && $_POST['sywat_action'] === 'cancel_schedule_import' ) {
            delete_transient( 'sywat_pending_schedule_import_' . get_current_user_id() );
        }

        // Retrieve active dry-run preview transient
        $transient_key = 'sywat_pending_schedule_import_' . get_current_user_id();
        $processed     = get_transient( $transient_key );

        // Automatically trigger preview overlay whenever transient exists
        $show_preview  = ! empty( $processed ) && ( ! empty( $processed['valid_tournaments'] ) || ! empty( $processed['rejected_rows'] ) );

        $valid_list    = ( $show_preview && isset( $processed['valid_tournaments'] ) ) ? $processed['valid_tournaments'] : array();
        $rejected_list = ( $show_preview && isset( $processed['rejected_rows'] ) ) ? $processed['rejected_rows'] : array();
        $target_season = ( $show_preview && isset( $processed['target_season'] ) ) ? $processed['target_season'] : $current_season;
        ?>
        <!-- BULK SCHEDULE IMPORT UPLOAD MODAL -->
        <div class="sywat-modal-backdrop" id="sywat_import_schedule_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>📁 Bulk Import Season Schedule</h3>
                        <div class="sywat-subtitle">Upload a 7-column Excel (.xlsx) or CSV file to build the season schedule and line templates in one click.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" onclick="closeImportScheduleModal()">&times;</button>
                </div>
                <form method="post" action="?tab=manage" enctype="multipart/form-data">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field( 'sywat_import_schedule_nonce', 'sywat_import_schedule_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="import_schedule_file">

                        <div class="sywat-form-grid-1">
                            <div class="sywat-field-group">
                                <label>Target Competition Season *</label>
                                <select name="import_target_season" required>
                                    <?php foreach ( $seasons as $s_slug => $s_item ): ?>
                                        <?php $s_name = is_array( $s_item ) && isset( $s_item['name'] ) ? $s_item['name'] : $s_slug; ?>
                                        <option value="<?php echo esc_attr( $s_slug ); ?>" <?php selected( $s_slug, $target_season ); ?>>
                                            <?php echo esc_html( $s_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group sywat-modal-row-spacing">
                                <label>Select Schedule Spreadsheet File (.xlsx, .csv) *</label>
                                <input type="file" name="schedule_file" accept=".xlsx, .xls, .csv, .txt" required>
                            </div>
                        </div>
                    </div>
                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeImportScheduleModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-import">🔍 Preview & Validate Schedule</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ( $show_preview ): ?>
        <!-- DRY-RUN PREVIEW MODAL OVERLAY (AUTOMATIC DISPLAY) -->
        <div class="sywat-modal-backdrop active" id="sywat_import_preview_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>🔍 Schedule Import Dry-Run Preview</h3>
                        <div class="sywat-subtitle">Target Season: <strong><?php echo esc_html( isset( $seasons[$target_season]['name'] ) ? $seasons[$target_season]['name'] : $target_season ); ?></strong></div>
                    </div>
                    <form method="post" action="?tab=manage" class="sywat-form-inline">
                        <input type="hidden" name="sywat_action" value="cancel_schedule_import">
                        <button type="submit" class="sywat-modal-close" title="Close Preview">&times;</button>
                    </form>
                </div>
                <div class="sywat-modal-body">
                    <?php if ( ! empty( $rejected_list ) ): ?>
                        <div class="sywat-alert sywat-alert-danger sywat-modal-row-spacing">
                            <strong>⚠️ <?php echo count( $rejected_list ); ?> Row(s) Rejected / Prohibited:</strong>
                            <ul class="sywat-rejected-list">
                                <?php foreach ( $rejected_list as $rej ): ?>
                                    <li><strong>Row <?php echo intval( $rej['row_num'] ); ?>:</strong> "<?php echo esc_html( $rej['shop_name'] ); ?>" — <em><?php echo esc_html( $rej['reason'] ); ?></em></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="sywat-table-header-bar">
                        <h2>🟢 Valid Tournaments Ready to Create (<?php echo count( $valid_list ); ?>)</h2>
                    </div>

                    <div class="sywat-table-responsive">
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th>Tournament Title</th>
                                    <th>Dates</th>
                                    <th>Host / Location</th>
                                    <th>Event Type</th>
                                    <th class="sywat-col-center">Lines</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $valid_list as $vt ): ?>
                                    <?php $line_cnt = count( $vt['line_slots'] ); ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $vt['tournament_name'] ); ?></strong></td>
                                        <td><?php echo esc_html( $vt['start_date_formatted'] . ( $vt['start_date_formatted'] !== $vt['end_date_formatted'] ? ' to ' . $vt['end_date_formatted'] : '' ) ); ?></td>
                                        <td><?php echo esc_html( ! empty( $vt['official_shop_name'] ) ? $vt['official_shop_name'] : $vt['location_display'] ); ?></td>
                                        <td><span class="sywat-badge"><?php echo esc_html( $vt['event_type'] ); ?></span></td>
                                        <td class="sywat-col-center"><?php echo $line_cnt . ' ' . ( $line_cnt === 1 ? 'Line' : 'Lines' ); ?></td>
                                        <td><small><?php echo esc_html( ! empty( $vt['notes'] ) ? $vt['notes'] : '—' ); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="sywat-modal-footer">
                    <form method="post" action="?tab=manage">
                        <?php wp_nonce_field( 'sywat_confirm_schedule_nonce', 'sywat_confirm_schedule_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="confirm_schedule_import">
                        
                        <button type="submit" name="sywat_action" value="cancel_schedule_import" class="sywat-btn sywat-btn-secondary">Cancel</button>
                        <button type="submit" name="sywat_action" value="confirm_schedule_import" class="sywat-btn sywat-btn-success sywat-btn-icon-save">🚀 Confirm & Create <?php echo count( $valid_list ); ?> Tournaments</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }
}

/**
 * Render Delegated Archer / Roster / Invoice Modal Container
 *
 * @param array $data Central data bundle from data engine.
 */
if ( ! function_exists( 'sywat_render_delegated_modal_container' ) ) {
    function sywat_render_delegated_modal_container( $data ) {
        $tournaments   = $data['tournaments'];
        $registrations = $data['registrations'];

        $selected_tournament = isset( $_REQUEST['view_tournament'] ) ? sanitize_text_field( $_REQUEST['view_tournament'] ) : '';
        $modal_action        = isset( $_REQUEST['modal_action'] ) ? sanitize_text_field( $_REQUEST['modal_action'] ) : 'manage';
        $open_modal          = ! empty( $selected_tournament ) && isset( $tournaments[$selected_tournament] );

        $modal_edit_archer   = isset( $_REQUEST['edit_archer'] ) ? sanitize_text_field( $_REQUEST['edit_archer'] ) : '';

        $available_lines = $open_modal ? sywat_get_tournament_lines( $tournaments[$selected_tournament] ) : array();
        $filter_line_idx = isset( $_REQUEST['filter_line_idx'] ) ? sanitize_text_field( $_REQUEST['filter_line_idx'] ) : 'all';
        $search_query    = isset( $_REQUEST['search_shooter'] ) ? trim( sanitize_text_field( $_REQUEST['search_shooter'] ) ) : '';
        $is_specific_line = ($filter_line_idx !== 'all' && $filter_line_idx !== '' && $filter_line_idx !== null);
        ?>
        <div class="sywat-modal-backdrop <?php echo $open_modal ? 'active' : ''; ?>" id="sywat_modal">
            <div class="sywat-modal-content sywat-modal-xl">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>
                            <?php 
                                if ($modal_action === 'invoice') echo '📄 TFAA Tournament Invoice';
                                elseif ($modal_action === 'print') echo '🖨️ Print Checked In Roster';
                                elseif ($modal_action === 'prereg') echo '📝 Register Shooters & Bulk Import';
                                else echo '👥 Registered Archer Roster';
                            ?>
                        </h3>
                        <div class="sywat-subtitle"><?php echo esc_html( $selected_tournament ); ?></div>
                    </div>
                    <button type="button" class="sywat-modal-close" onclick="closeSywatModal()">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <?php if ( $open_modal ): ?>
                        <?php 
                            if ( $modal_action === 'prereg' && function_exists( 'sywat_render_registration_modal' ) ) {
                                sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations );
                            } elseif ( $modal_action === 'manage' && function_exists( 'sywat_render_roster_modal' ) ) {
                                sywat_render_roster_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $search_query, $modal_edit_archer, $tournaments );
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
        <?php
    }
}
