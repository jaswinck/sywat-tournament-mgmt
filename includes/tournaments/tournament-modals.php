<?php
/**
 * Sub-Module: Tournament Modals View Layer
 * Path: includes/tournaments/tournament-modals.php
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render the Add / Edit Tournament Modal
 */
if ( ! function_exists( 'sywat_render_add_edit_tournament_modal' ) ) {
    function sywat_render_add_edit_tournament_modal( $data ) {
        $tournaments        = $data['tournaments'];
        $can_manage_seasons = $data['can_manage_seasons'];
        $user_shop_id       = $data['user_shop_id'];
        $all_shops          = $data['all_shops'];
        $seasons            = $data['seasons'];

        $default_lines = array(
            array( 'day' => 'Saturday', 'time' => '10:00 AM', 'name' => 'Saturday 10:00 AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'day' => 'Saturday', 'time' => '2:00 PM',  'name' => 'Saturday 2:00 PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'day' => 'Sunday',   'time' => '10:00 AM', 'name' => 'Sunday 10:00 AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'day' => 'Sunday',   'time' => '2:00 PM',  'name' => 'Sunday 2:00 PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
        );

        $open_tourn_modal = isset( $_REQUEST['open_add_tournament'] ) || isset( $_REQUEST['edit_tournament_modal'] );
        $edit_t_target    = isset( $_REQUEST['edit_tournament_modal'] ) ? sanitize_text_field( $_REQUEST['edit_tournament_modal'] ) : '';
        
        $edit_t_data      = ( ! empty( $edit_t_target ) && isset( $tournaments[$edit_t_target] ) ) ? $tournaments[$edit_t_target] : null;
        $edit_t_lines     = $edit_t_data ? sywat_get_tournament_lines( $edit_t_data ) : $default_lines;
        if ( empty( $edit_t_lines ) || ! is_array( $edit_t_lines ) ) {
            $edit_t_lines = $default_lines;
        }

        $edit_t_shop   = $edit_t_data ? sywat_get_tournament_shop_id( $edit_t_data ) : ( ! empty( $user_shop_id ) ? $user_shop_id : '' );
        $edit_t_season = $edit_t_data ? sywat_get_tournament_season_slug( $edit_t_data ) : ( function_exists('sywat_get_active_season') ? sywat_get_active_season()['slug'] : '' );
        $edit_t_start  = ( is_array( $edit_t_data ) && isset( $edit_t_data['start_date'] ) ) ? $edit_t_data['start_date'] : '';
        $edit_t_end    = ( is_array( $edit_t_data ) && isset( $edit_t_data['end_date'] ) ) ? $edit_t_data['end_date'] : '';

        // Calculate existing tournament counts per shop for auto-naming logic
        $shop_counts = array();
        foreach ( $tournaments as $t_name => $t_info ) {
            $s_id = sywat_get_tournament_shop_id( $t_info );
            $shop_counts[$s_id] = isset( $shop_counts[$s_id] ) ? $shop_counts[$s_id] + 1 : 1;
        }
        ?>
        <div class="sywat-modal-backdrop <?php echo $open_tourn_modal ? 'active' : ''; ?>" id="sywat_add_tourn_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <!-- STANDARDIZED MODAL HEADER -->
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>🎯 <?php echo ! empty( $edit_t_target ) ? 'Edit Tournament Configuration' : 'Create New Tournament'; ?></h3>
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
                                <?php if ( $can_manage_seasons ): ?>
                                    <select name="shop_id" id="sywat_modal_shop_id" onchange="sywatAutoGenerateTournamentName()" required>
                                        <option value="" <?php selected( empty( $edit_t_shop ), true ); ?>>Select Shop</option>
                                        <?php foreach ( $all_shops as $s_id => $s_name ): ?>
                                            <?php $count = isset( $shop_counts[$s_id] ) ? $shop_counts[$s_id] : 0; ?>
                                            <option value="<?php echo esc_attr( $s_id ); ?>" 
                                                    data-name="<?php echo esc_attr( $s_name['name'] ); ?>" 
                                                    data-count="<?php echo $count; ?>" 
                                                    <?php selected( $edit_t_shop, $s_id ); ?>>
                                                <?php echo esc_html( $s_name['name'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="hidden" name="shop_id" id="sywat_modal_shop_id" value="<?php echo esc_attr( $user_shop_id ); ?>">
                                    <input type="text" value="<?php echo esc_attr( isset($all_shops[$user_shop_id]['name']) ? $all_shops[$user_shop_id]['name'] : 'Current Shop' ); ?>" readonly>
                                <?php endif; ?>
                            </div>

                            <div class="sywat-field-group">
                                <label>Competition Season *</label>
                                <select name="season_slug" required>
                                    <?php foreach ( $seasons as $s_slug => $s_item ): ?>
                                        <option value="<?php echo esc_attr( $s_slug ); ?>" <?php selected( $edit_t_season, $s_slug ); ?>>
                                            <?php echo esc_html( $s_item['name'] ); ?> (<?php echo esc_html( ucfirst($s_item['status']) ); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- ROW 2: NAME (LEFT) | DATES (RIGHT) -->
                        <div class="sywat-form-grid-2 sywat-modal-row-spacing">
                            <div class="sywat-field-group">
                                <label>Tournament Name * <span class="sywat-label-subtext">(Auto-generated, editable)</span></label>
                                <input type="text" name="tournament_name" id="sywat_modal_tournament_name" value="<?php echo esc_attr( $edit_t_target ); ?>" placeholder="Select shop to auto-generate..." oninput="sywatMarkNameAsCustomized()" required>
                            </div>

                            <div class="sywat-form-grid-2 sywat-grid-compact">
                                <div class="sywat-field-group">
                                    <label>Start Date *</label>
                                    <input type="date" name="start_date" value="<?php echo esc_attr( $edit_t_start ); ?>" required>
                                </div>
                                <div class="sywat-field-group">
                                    <label>End Date (Optional)</label>
                                    <input type="date" name="end_date" value="<?php echo esc_attr( $edit_t_end ); ?>">
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
                                            $day_val  = isset($l['day']) ? $l['day'] : '';
                                            $time_val = isset($l['time']) ? $l['time'] : '';
                                            if ( empty($day_val) && !empty($l['name']) ) {
                                                $parts    = explode(' ', trim($l['name']), 2);
                                                $day_val  = $parts[0];
                                                $time_val = isset($parts[1]) ? $parts[1] : '';
                                            }

                                            $cap_20yd  = isset($l['cap_20yd']) ? $l['cap_20yd'] : 20;
                                            $cap_cub   = isset($l['cap_cub']) ? $l['cap_cub'] : 5;
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
 * Render Delegated Archer / Roster / Invoice Modal Container
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
                                if ($modal_action === 'invoice') echo '📄 TFAA Tournament Invoice — ' . esc_html( $selected_tournament );
                                elseif ($modal_action === 'print') echo '🖨️ Print Checked In Roster — ' . esc_html( $selected_tournament );
                                elseif ($modal_action === 'prereg') echo '📝 Register Shooters & Bulk Import — ' . esc_html( $selected_tournament );
                                else echo '👥 Registered Archer Roster — ' . esc_html( $selected_tournament );
                            ?>
                        </h3>
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
