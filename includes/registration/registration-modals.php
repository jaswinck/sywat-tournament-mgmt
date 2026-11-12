<?php
/**
 * Sub-Module: Registration Engine Component & Modal Views
 * Path: includes/registration/registration-modals.php
 * Author: SYWAT Archery
 * Version: 6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Helper: Resolve a human-readable shooting line label from line config array.
 * Prevents "Line 1, Line 2" fallback when lines are stored with day/time keys.
 */
if ( ! function_exists( 'sywat_reg_resolve_line_label' ) ) {
    function sywat_reg_resolve_line_label( $line, $idx ) {
        $label = '';
        if ( is_array( $line ) ) {
            if ( ! empty( $line['name'] ) ) {
                $label = trim( $line['name'] );
            } else {
                $day_str  = isset( $line['day'] ) ? trim( $line['day'] ) : '';
                $time_str = isset( $line['time'] ) ? trim( $line['time'] ) : ( isset( $line['start_time'] ) ? trim( $line['start_time'] ) : '' );
                if ( ! empty( $day_str ) && ! empty( $time_str ) ) {
                    $label = trim( $day_str . ' ' . $time_str );
                } elseif ( ! empty( $day_str ) ) {
                    $label = $day_str;
                } elseif ( ! empty( $time_str ) ) {
                    $label = $time_str;
                }
            }
        } elseif ( is_string( $line ) && trim( $line ) !== '' ) {
            $label = trim( $line );
        }

        if ( $label === '' ) {
            return 'Line ' . ( (int)$idx + 1 );
        }

        // Normalize line times consistently with Manage Tournaments view
        $label = preg_replace( '/\b6(?::00)?\s*AM\b/i', '6PM', $label );
        $label = preg_replace( '/\b6:30\s*AM\b/i', '6:30PM', $label );
        $label = preg_replace( '/(\d{1,2}):00\s*(AM|PM)/i', '$1$2', $label );

        return $label;
    }
}

/**
 * Main Dispatcher: Registration Forms & Line Usage Component Container
 */
if ( ! function_exists( 'sywat_render_registration_modal' ) ) {
    function sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations ) {
        $skipped_duplicates = ! empty( $GLOBALS['sywat_skipped_duplicates'] ) ? $GLOBALS['sywat_skipped_duplicates'] : array();
        $skipped_full       = ! empty( $GLOBALS['sywat_skipped_full'] ) ? $GLOBALS['sywat_skipped_full'] : array();
        $modal_notice       = ! empty( $GLOBALS['sywat_modal_notice'] ) ? $GLOBALS['sywat_modal_notice'] : '';
        ?>
        <div class="no-print sywat-intake-wrapper">
            
            <?php if ( ! empty( $modal_notice ) ): ?>
                <?php if ( stripos( $modal_notice, 'OVERFLOW' ) !== false ): ?>
                    <div id="sywat_capacity_overflow_modal" class="sywat-modal-backdrop sywat-modal-active sywat-modal-overflow-backdrop">
                        <div class="sywat-modal-content sywat-modal-overflow-content">
                            <?php echo $modal_notice; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="sywat-modal-notice"><?php echo $modal_notice; ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( ! empty( $skipped_duplicates ) ): ?>
                <div class="sywat-alert sywat-alert-warning">
                    <strong>⚠️ DUPLICATE REGISTRATION BLOCKED:</strong> The following archer(s) were NOT registered because they are already signed up on this line:
                    <ul>
                        <?php foreach ( $skipped_duplicates as $dup ): ?>
                            <li><?php echo esc_html( $dup ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $skipped_full ) ): ?>
                <div class="sywat-alert sywat-alert-warning">
                    <strong>⚠️ LINE CAPACITY BLOCKED:</strong> The following archer(s) were NOT registered because the target line is at maximum capacity:
                    <ul>
                        <?php foreach ( $skipped_full as $full ): ?>
                            <li><?php echo esc_html( $full ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Component 1: Phone & Group Intake Form -->
            <?php sywat_render_phone_intake_form( $selected_tournament, $available_lines ); ?>

            <!-- Component 2: FareHarbor CSV Bulk Import Form -->
            <?php sywat_render_csv_import_form( $selected_tournament, $available_lines ); ?>

            <!-- Component 3: Line Slot Usage Table -->
            <?php sywat_render_line_slot_usage_table( $selected_tournament, $available_lines, $registrations ); ?>

        </div>
        <?php
    }
}

/**
 * Component 1: Phone & Multi-Archer Group Registration Intake Form
 */
if ( ! function_exists( 'sywat_render_phone_intake_form' ) ) {
    function sywat_render_phone_intake_form( $selected_tournament, $available_lines ) {
        $current_default_line = isset( $_REQUEST['default_line_idx'] ) 
            ? sanitize_text_field( wp_unslash( $_REQUEST['default_line_idx'] ) ) 
            : ( isset( $_REQUEST['target_line_idx'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['target_line_idx'] ) ) : 0 );

        $is_overflow_notice = ! empty( $GLOBALS['sywat_modal_notice'] ) && stripos( $GLOBALS['sywat_modal_notice'], 'OVERFLOW' ) !== false;
        $retain_form_data   = $is_overflow_notice || ! empty( $GLOBALS['sywat_skipped_duplicates'] ) || ! empty( $GLOBALS['sywat_skipped_full'] );

        $group_name_val  = $retain_form_data && isset($_POST['group_name']) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '';
        $group_phone_val = $retain_form_data && isset($_POST['group_phone']) ? sanitize_text_field( wp_unslash( $_POST['group_phone'] ) ) : '';
        $amount_paid_val = $retain_form_data && isset($_POST['amount_paid_today']) ? sanitize_text_field( wp_unslash( $_POST['amount_paid_today'] ) ) : '0.00';

        $archers_data = array();
        if ( $retain_form_data && isset($_POST['archers']) && is_array($_POST['archers']) ) {
            $archers_data = wp_unslash( $_POST['archers'] );
        }

        if ( empty( $archers_data ) ) {
            $archers_data = array(
                array(
                    'name'     => '',
                    'distance' => '20yd',
                    'division' => '',
                    'line_idx' => $current_default_line,
                )
            );
        }
        ?>
        <div class="sywat-intake-card">
            <div class="sywat-table-header-bar">
                <h2>📞 Phone & Group Registration Intake</h2>
            </div>
            
            <form method="post" id="sywat_phone_intake_form">
                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                <input type="hidden" name="sywat_action" value="register_shooter">
                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="modal_action" value="prereg">

                <div class="sywat-form-grid-4 sywat-intake-group-wrapper">
                    <div class="sywat-field-group">
                        <label for="sywat_group_name">Caller / Primary Archer Name</label>
                        <input type="text" name="group_name" id="sywat_group_name" value="<?php echo esc_attr( $group_name_val ); ?>" placeholder="e.g. John Smith" oninput="handleCallerNameInput(this)">
                    </div>

                    <div class="sywat-field-group">
                        <label for="sywat_group_phone">Contact Phone Number</label>
                        <input type="tel" name="group_phone" id="sywat_group_phone" value="<?php echo esc_attr( $group_phone_val ); ?>" placeholder="e.g. 817-555-1234" oninput="formatPhoneNumber(this)">
                    </div>

                    <div class="sywat-field-group">
                        <label for="sywat_default_line_idx">Default Shooting Line *</label>
                        <select name="default_line_idx" id="sywat_default_line_idx" required onchange="updateAllArcherLines(this.value)">
                            <?php foreach ( $available_lines as $idx => $line ): ?>
                                <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$current_default_line, (string)$idx ); ?>>
                                    <?php echo esc_html( sywat_reg_resolve_line_label( $line, $idx ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label for="sywat_amount_paid_today">Amount Paid Today ($)</label>
                        <input type="number" step="0.01" min="0" name="amount_paid_today" id="sywat_amount_paid_today" value="<?php echo esc_attr( $amount_paid_val ); ?>" oninput="recalculateGroupTotal()">
                    </div>
                </div>

                <div class="sywat-archer-header-row">
                    <div class="sywat-col-archer-name">Archer Full Name *</div>
                    <div class="sywat-col-archer-dist">Target Distance *</div>
                    <div class="sywat-col-archer-div">Division *</div>
                    <div class="sywat-col-archer-line">Line Override</div>
                    <div class="sywat-col-archer-action"></div>
                </div>

                <div id="sywat_archer_rows_container">
                    <?php foreach ( $archers_data as $i => $a ): ?>
                        <?php 
                            $a_name = isset($a['name']) ? sanitize_text_field($a['name']) : '';
                            $a_dist = isset($a['distance']) ? sanitize_text_field($a['distance']) : '20yd';
                            $a_div  = isset($a['division']) ? sanitize_text_field($a['division']) : '';
                            $a_line = isset($a['line_idx']) ? sanitize_text_field($a['line_idx']) : $current_default_line;
                        ?>
                        <div class="sywat-archer-input-row">
                            <div class="sywat-field-group sywat-col-archer-name">
                                <input type="text" name="archers[<?php echo $i; ?>][name]" <?php if ($i === 0) echo 'id="sywat_first_archer_name"'; ?> class="sywat-archer-name-input" placeholder="Archer Full Name *" autocomplete="off" required value="<?php echo esc_attr($a_name); ?>" oninput="handleArcherNameInput(this); recalculateGroupTotal();">
                            </div>

                            <div class="sywat-field-group sywat-col-archer-dist">
                                <select name="archers[<?php echo $i; ?>][distance]" class="sywat-distance-select" onchange="handleDistanceChange(this)">
                                    <option value="20yd" <?php selected($a_dist, '20yd'); ?>>20yd</option>
                                    <option value="Cub" <?php selected($a_dist, 'Cub'); ?>>Cub (10yd)</option>
                                </select>
                            </div>

                            <div class="sywat-field-group sywat-col-archer-div">
                                <select name="archers[<?php echo $i; ?>][division]" class="sywat-division-select" onchange="handleDivisionChange(this);">
                                    <option value="" disabled <?php selected($a_div, ''); ?> hidden>Select Division</option>
                                    <option value="Cub (8 - 11)" <?php selected($a_div, 'Cub (8 - 11)'); ?>>Cub (8 - 11) [$25]</option>
                                    <option value="Youth (12 - 14)" <?php selected($a_div, 'Youth (12 - 14)'); ?>>Youth (12 - 14) [$25]</option>
                                    <option value="Young Adult (15 - 17)" <?php selected($a_div, 'Young Adult (15 - 17)'); ?>>Young Adult (15 - 17) [$25]</option>
                                    <option value="Adult (18 - 49)" <?php selected($a_div, 'Adult (18 - 49)'); ?>>Adult (18 - 49) [$30]</option>
                                    <option value="Senior (50 - 59)" <?php selected($a_div, 'Senior (50 - 59)'); ?>>Senior (50 - 59) [$30]</option>
                                    <option value="Silver Senior (60 - 69)" <?php selected($a_div, 'Silver Senior (60 - 69)'); ?>>Silver Senior (60 - 69) [$30]</option>
                                    <option value="Master Senior (70+) " <?php selected(trim($a_div), 'Master Senior (70+)'); ?>>Master Senior (70+) [$30]</option>
                                </select>
                            </div>

                            <div class="sywat-field-group sywat-col-archer-line">
                                <select name="archers[<?php echo $i; ?>][line_idx]" class="sywat-line-override-select" onchange="recalculateGroupTotal()">
                                    <?php foreach ( $available_lines as $idx => $line ): ?>
                                        <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$a_line, (string)$idx ); ?>>
                                            <?php echo esc_html( sywat_reg_resolve_line_label( $line, $idx ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-col-archer-action">
                                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" onclick="removeArcherRow(this)" title="Remove Archer"></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="sywat-intake-footer-bar">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addArcherRow()">Add Another Archer</button>

                    <div class="sywat-intake-summary">
                        <div class="sywat-intake-total-text">
                            Group Total: <span id="sywat_group_total_disp" class="sywat-intake-total-val">$30.00</span>
                            <span id="sywat_family_cap_badge" class="sywat-family-badge">🟢 Family Cap Applied</span>
                        </div>
                        <div class="sywat-intake-due-text">
                            Balance Due at Desk: <strong id="sywat_balance_due_disp" class="sywat-intake-due-val">$30.00</strong>
                        </div>
                    </div>

                    <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-checkin">Save & Register Shooters</button>
                </div>
            </form>
        </div>
        <?php
    }
}

/**
 * Component 2: FareHarbor CSV Bulk Upload Form
 */
if ( ! function_exists( 'sywat_render_csv_import_form' ) ) {
    function sywat_render_csv_import_form( $selected_tournament, $available_lines ) {
        $user = wp_get_current_user();
        $is_admin = in_array('administrator', (array) $user->roles);

        $tournaments_list = get_option('sywat_tournaments_list', array());
        $tourn_data       = isset($tournaments_list[$selected_tournament]) ? $tournaments_list[$selected_tournament] : array();
        $tourn_shop_id    = function_exists('sywat_get_tournament_shop_id') ? sywat_get_tournament_shop_id($tourn_data) : 'shop_ccr';

        if ( ! ($is_admin && $tourn_shop_id === 'shop_ccr') ) {
            return;
        }

        $current_default_line = isset( $_REQUEST['default_line_idx'] ) 
            ? sanitize_text_field( wp_unslash( $_REQUEST['default_line_idx'] ) ) 
            : ( isset( $_REQUEST['target_line_idx'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['target_line_idx'] ) ) : 0 );
        ?>
        <div class="sywat-intake-card">
            <div class="sywat-table-header-bar">
                <h2>📁 Bulk Upload Roster File (FareHarbor CSV)</h2>
            </div>
            <form method="post" enctype="multipart/form-data" id="sywat_import_form">
                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                <input type="hidden" name="sywat_action" value="import_shooters">
                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="modal_action" value="prereg">

                <div class="sywat-import-file-row">
                    <div class="sywat-field-group">
                        <label for="sywat_target_line_idx">Select Target Shooting Line</label>
                        <select name="target_line_idx" id="sywat_target_line_idx" required>
                            <?php foreach ( $available_lines as $idx => $line ): ?>
                                <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$current_default_line, (string)$idx ); ?>>
                                    <?php echo esc_html( sywat_reg_resolve_line_label( $line, $idx ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label for="import_file_input">Select File (.csv, .txt)</label>
                        <input type="file" id="import_file_input" name="import_file" accept=".csv,.txt">
                    </div>

                    <button type="submit" id="sywat_upload_btn" class="sywat-btn sywat-btn-primary sywat-btn-icon-export">Upload & Parse File</button>
                </div>

                <details class="sywat-import-details">
                    <summary class="sywat-import-summary">Or paste shop report text directly...</summary>
                    <div class="sywat-field-group sywat-import-textarea-wrap">
                        <textarea name="pasted_text" rows="5" class="sywat-import-textarea" placeholder="Paste report text directly here if preferred..."></textarea>
                    </div>
                </details>
            </form>
        </div>
        <?php
    }
}

/**
 * Component 3: Line Slot Usage Table with Direct Manage Roster Links
 */
if ( ! function_exists( 'sywat_render_line_slot_usage_table' ) ) {
    function sywat_render_line_slot_usage_table( $selected_tournament, $available_lines, $registrations ) {
        ?>
        <div class="sywat-intake-card">
            <div class="sywat-table-header-bar">
                <h2>📊 Current Line Slot Usage</h2>
            </div>
            <div class="sywat-table-responsive">
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th class="sywat-col-line-name">Line Name</th>
                            <th class="sywat-col-cap-20yd sywat-col-center">20yd Capacity</th>
                            <th class="sywat-col-cap-cub sywat-col-center">Cub Capacity</th>
                            <th class="sywat-col-line-action sywat-col-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $available_lines as $idx => $line ): ?>
                            <?php
                                $line_title = sywat_reg_resolve_line_label( $line, $idx );
                                $c_20 = sywat_count_registered( $selected_tournament, $idx, '20yd', $registrations );
                                $m_20 = sywat_get_line_max_cap( $line, '20yd' );
                                $c_cub = sywat_count_registered( $selected_tournament, $idx, 'Cub', $registrations );
                                $m_cub = sywat_get_line_max_cap( $line, 'Cub' );
                                $total_line_shooters = $c_20 + $c_cub;

                                $waitlisted_20  = 0;
                                $waitlisted_cub = 0;

                                foreach ($registrations as $r) {
                                    if ( ! isset( $r['tournament'] ) || strcasecmp( trim( $r['tournament'] ), trim( $selected_tournament ) ) !== 0 ) {
                                        continue;
                                    }

                                    $match_idx = isset( $r['line_index'] ) && (string)$r['line_index'] === (string)$idx;
                                    $match_name = false;
                                    if ( isset( $r['line_name'] ) && is_string( $idx ) && strcasecmp( trim( $r['line_name'] ), trim( $idx ) ) === 0 ) {
                                        $match_name = true;
                                    }
                                    if ( isset( $r['line_index'] ) && is_string( $r['line_index'] ) && ! is_numeric( $r['line_index'] ) ) {
                                        if ( (string)$r['line_index'] === (string)$idx ) {
                                            $match_name = true;
                                        }
                                    }

                                    if ( $match_idx || $match_name ) {
                                        if ( isset( $r['status'] ) && strcasecmp( trim( $r['status'] ), 'Waitlist' ) === 0 ) {
                                            if ( isset( $r['distance'] ) && strcasecmp( trim( $r['distance'] ), 'Cub' ) === 0 ) {
                                                $waitlisted_cub++;
                                            } else {
                                                $waitlisted_20++;
                                            }
                                        }
                                    }
                                }

                                $manage_url = add_query_arg(
                                    array(
                                        'tab'             => 'manage',
                                        'view_tournament' => $selected_tournament,
                                        'modal_action'    => 'manage',
                                        'filter_line_idx' => $idx,
                                    ),
                                    sywat_page_url( 'manage_tournaments' )
                                );
                                $confirm_msg = sprintf( 'Are you sure you want to clear ALL archers from %s?', $line_title );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $line_title ); ?></strong></td>
                                <td class="sywat-col-center">
                                    <strong><?php echo $c_20; ?></strong> / <?php echo $m_20; ?>
                                    <span class="sywat-badge <?php echo ($c_20 >= $m_20) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ($c_20 >= $m_20) ? 'Full' : 'Open'; ?></span>
                                    <?php if ($waitlisted_20 > 0): ?>
                                        <div class="sywat-waitlist-pill">🕒 <?php echo $waitlisted_20; ?> Waitlisted</div>
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-center">
                                    <strong><?php echo $c_cub; ?></strong> / <?php echo $m_cub; ?>
                                    <span class="sywat-badge <?php echo ($c_cub >= $m_cub) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ($c_cub >= $m_cub) ? 'Full' : 'Open'; ?></span>
                                    <?php if ($waitlisted_cub > 0): ?>
                                        <div class="sywat-waitlist-pill">🕒 <?php echo $waitlisted_cub; ?> Waitlisted</div>
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-center">
                                    <div class="sywat-table-actions-vertical">
                                        <a href="<?php echo esc_url( $manage_url ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-manage sywat-btn-full">
                                            Manage Roster
                                        </a>
                                        <?php if ( $total_line_shooters > 0 || ($waitlisted_20 + $waitlisted_cub) > 0 ): ?>
                                            <form method="post" class="sywat-table-action-form" data-confirm-msg="<?php echo esc_attr( $confirm_msg ); ?>" onsubmit="return confirm(this.dataset.confirmMsg);">
                                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                <input type="hidden" name="sywat_action" value="purge_line">
                                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                                <input type="hidden" name="redirect_action" value="prereg">
                                                <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete sywat-btn-full">Clear Line</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
