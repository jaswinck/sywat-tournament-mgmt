<?php
/**
 * Sub-Module: Registration Engine Views Renderer
 * Path: includes/registration/registration-views.php
 * Author: SYWAT Archery
 * Version: 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_registration_modal' ) ) {
    function sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations ) {
        $user = wp_get_current_user();
        $is_admin = in_array('administrator', (array) $user->roles);

        $tournaments_list = get_option('sywat_tournaments_list', array());
        $tourn_data       = isset($tournaments_list[$selected_tournament]) ? $tournaments_list[$selected_tournament] : array();
        $tourn_shop_id    = function_exists('sywat_get_tournament_shop_id') ? sywat_get_tournament_shop_id($tourn_data) : 'shop_ccr';

        $can_show_csv_import = ($is_admin && $tourn_shop_id === 'shop_ccr');

        // Persist previously selected line index across form submissions
        $current_default_line = isset( $_REQUEST['default_line_idx'] ) 
            ? sanitize_text_field( $_REQUEST['default_line_idx'] ) 
            : ( isset( $_REQUEST['target_line_idx'] ) ? sanitize_text_field( $_REQUEST['target_line_idx'] ) : 0 );
        ?>
        <div class="no-print" style="display:flex; flex-direction:column; gap:20px;">
            
            <!-- PHONE & GROUP INTAKE FORM -->
            <div style="background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:4px;">
                <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:600; color:#1e293b;">1. Phone & Group Registration Intake</h4>
                
                <form method="post" id="sywat_phone_intake_form">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_action" value="register_shooter">
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="modal_action" value="prereg">

                    <!-- Group Header Information -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; background:#fff; padding:12px; border:1px solid #e2e8f0; border-radius:4px; margin-bottom:15px;">
                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Caller / Group Name</label>
                            <input type="text" name="group_name" placeholder="e.g. Smith Family or John Smith" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;">
                        </div>

                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Contact Phone Number</label>
                            <input type="tel" name="group_phone" id="sywat_group_phone" placeholder="e.g. 817-555-1234" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" oninput="formatPhoneNumber(this)">
                        </div>

                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Default Shooting Line *</label>
                            <select name="default_line_idx" id="sywat_default_line_idx" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" required onchange="sessionStorage.setItem('sywat_last_line_idx', this.value); updateAllArcherLines(this.value)">
                                <?php foreach ( $available_lines as $idx => $line ): ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$current_default_line, (string)$idx ); ?>>
                                        <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Amount Paid Today ($)</label>
                            <input type="number" step="0.01" min="0" name="amount_paid_today" id="sywat_amount_paid_today" value="0.00" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" oninput="recalculateGroupTotal()">
                        </div>
                    </div>

                    <!-- Archer Rows Container -->
                    <div id="sywat_archer_rows_container">
                        <!-- Archer Row 1 -->
                        <div class="sywat-archer-input-row" style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">
                            <input type="text" name="archers[0][name]" placeholder="Archer Full Name *" style="flex:2; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" required oninput="recalculateGroupTotal()">
                            
                            <!-- Target Distance Selection -->
                            <select name="archers[0][distance]" class="sywat-distance-select" style="flex:1; min-width:100px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="handleDistanceChange(this)">
                                <option value="20yd" selected>20yd</option>
                                <option value="Cub">Cub (10yd)</option>
                            </select>

                            <!-- Full 7 TFAA Age Division Selection (Age Order) -->
                            <select name="archers[0][division]" class="sywat-division-select" style="flex:1.5; min-width:160px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="this.dataset.userChosen='true'; recalculateGroupTotal();">
                                <option value="" disabled selected hidden>Select Division</option>
                                <option value="Cub (8 - 11)">Cub (8 - 11) [$25]</option>
                                <option value="Youth (12 - 14)">Youth (12 - 14) [$25]</option>
                                <option value="Young Adult (15 - 17)">Young Adult (15 - 17) [$25]</option>
                                <option value="Adult (18 - 49)">Adult (18 - 49) [$30]</option>
                                <option value="Senior (50 - 59)">Senior (50 - 59) [$30]</option>
                                <option value="Silver Senior (60 - 69)">Silver Senior (60 - 69) [$30]</option>
                                <option value="Master Senior (70+) ">Master Senior (70+) [$30]</option>
                            </select>

                            <!-- Line Override Selection -->
                            <select name="archers[0][line_idx]" class="sywat-line-override-select" style="flex:1.2; min-width:130px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="recalculateGroupTotal()">
                                <?php foreach ( $available_lines as $idx => $line ): ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$current_default_line, (string)$idx ); ?>>
                                        <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" style="height:32px; padding:2px 8px; font-size:12px;" onclick="removeArcherRow(this)" title="Remove Archer"></button>
                        </div>
                    </div>

                    <!-- Add Row & Live Payment Total Display -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; padding-top:12px; border-top:1px dashed #cbd5e1; flex-wrap:wrap; gap:10px;">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addArcherRow()">Add Another Archer</button>

                        <div style="text-align:right;">
                            <div style="font-size:13px; font-weight:bold; color:#1e293b;">
                                Group Total: <span id="sywat_group_total_disp" style="color:#0284c7;">$30.00</span>
                                <span id="sywat_family_cap_badge" style="display:none; background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:2px 6px; border-radius:12px; font-size:11px; margin-left:6px;">🟢 Family Cap Applied</span>
                            </div>
                            <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                Balance Due at Desk: <strong id="sywat_balance_due_disp" style="color:#dc2626;">$30.00</strong>
                            </div>
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-checkin" style="height:34px;">Save & Register Shooters</button>
                    </div>
                </form>
            </div>

            <!-- FAREHARBOR CSV BULK UPLOAD (CCR ADMIN ONLY) -->
            <?php if ( $can_show_csv_import ): ?>
                <div style="background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:4px;">
                    <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:600; color:#1e293b;">2. Bulk Upload Roster File (FareHarbor CSV)</h4>
                    <form method="post" enctype="multipart/form-data" id="sywat_import_form">
                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                        <input type="hidden" name="sywat_action" value="import_shooters">
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="modal_action" value="prereg">

                        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom: 10px;">
                            <div style="flex:1; min-width:200px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Select Target Shooting Line</label>
                                <select name="target_line_idx" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" required>
                                    <?php foreach ( $available_lines as $idx => $line ): ?>
                                        <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$current_default_line, (string)$idx ); ?>>
                                            <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="flex:2; min-width:250px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Select File (.csv, .txt)</label>
                                <input type="file" id="import_file_input" name="import_file" accept=".csv,.txt" style="width:100%; padding:4px; font-size:13px;">
                            </div>

                            <button type="submit" id="sywat_upload_btn" class="sywat-btn sywat-btn-primary sywat-btn-icon-export">Upload & Parse File</button>
                        </div>

                        <details style="margin-top: 10px;">
                            <summary style="font-size: 12px; font-weight: 600; color: #2271b1; cursor: pointer;">Or paste shop report text directly...</summary>
                            <div style="margin-top: 8px;">
                                <textarea name="pasted_text" rows="5" placeholder="Paste report text directly here if preferred..." style="width: 100%; font-family: monospace; font-size: 12px; border:1px solid #ccc; border-radius:4px; padding:6px;"></textarea>
                            </div>
                        </details>
                    </form>
                </div>
            <?php endif; ?>

            <!-- LINE SLOT USAGE TABLE -->
            <div>
                <h4 style="margin:0 0 8px 0; font-size:14px; font-weight:600; color:#1e293b;">Current Line Slot Usage</h4>
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Line Name</th>
                            <th style="text-align:center;">20yd Capacity</th>
                            <th style="text-align:center;">Cub Capacity</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $available_lines as $idx => $line ): ?>
                            <?php
                                $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1);
                                $c_20 = sywat_count_registered( $selected_tournament, $idx, '20yd', $registrations );
                                $m_20 = sywat_get_line_max_cap( $line, '20yd' );
                                $c_cub = sywat_count_registered( $selected_tournament, $idx, 'Cub', $registrations );
                                $m_cub = sywat_get_line_max_cap( $line, 'Cub' );
                                $total_line_shooters = $c_20 + $c_cub;
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $line_title ); ?></strong></td>
                                <td style="text-align:center;">
                                    <?php echo $c_20 . ' / ' . $m_20; ?>
                                    <span class="sywat-badge <?php echo ($c_20 >= $m_20) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ($c_20 >= $m_20) ? 'Full' : 'Open'; ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <?php echo $c_cub . ' / ' . $m_cub; ?>
                                    <span class="sywat-badge <?php echo ($c_cub >= $m_cub) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ($c_cub >= $m_cub) ? 'Full' : 'Open'; ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ( $total_line_shooters > 0 ): ?>
                                        <form method="post" style="display: inline-block; margin: 0;" onsubmit="return confirm('Are you sure you want to clear ALL <?php echo $total_line_shooters; ?> archers from <?php echo esc_js( $line_title ); ?>?');">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_action" value="purge_line">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                            <input type="hidden" name="redirect_action" value="prereg">
                                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="padding: 3px 8px; font-size: 11px; height: auto;">Clear Line</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: #94a3b8;">(Empty)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const defaultLineSelect = document.getElementById('sywat_default_line_idx');
            if (defaultLineSelect) {
                const savedLine = sessionStorage.getItem('sywat_last_line_idx');
                const hasPhpSelection = <?php echo ( isset($_REQUEST['default_line_idx']) || isset($_REQUEST['target_line_idx']) ) ? 'true' : 'false'; ?>;
                if (savedLine !== null && !hasPhpSelection && defaultLineSelect.querySelector('option[value="' + savedLine + '"]')) {
                    defaultLineSelect.value = savedLine;
                    if (typeof updateAllArcherLines === 'function') {
                        updateAllArcherLines(savedLine);
                    }
                }
            }
        });
        </script>
        <?php
        // Render Client Scripts
        if ( function_exists( 'sywat_render_registration_scripts' ) ) {
            sywat_render_registration_scripts();
        }
    }
}
