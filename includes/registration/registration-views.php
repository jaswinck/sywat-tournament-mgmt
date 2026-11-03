<?php
/**
 * Sub-Module: Registration Engine Views Renderer
 * Path: includes/registration/registration-views.php
 * Author: SYWAT Archery
 * Version: 3.6.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render Full Registration Desk Page (Shortcode Handler: [sywat_register])
 */
if ( ! function_exists( 'sywat_render_registration_desk' ) ) {
    function sywat_render_registration_desk() {
        if ( ! function_exists( 'sywat_can_access_desk' ) || ! sywat_can_access_desk() ) {
            return '<p style="color:red; padding:20px; font-weight:bold;">Access denied. Desk management access required.</p>';
        }

        ob_start();
        global $wpdb;

        $can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
        $can_access_desk    = sywat_can_access_desk();
        $can_edit_scores    = function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores();

        $user_shop_id       = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';
        $all_shops          = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        // Scope Tournaments to User's Assigned Shop
        $scoped_tournaments = array();
        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop = sywat_get_tournament_shop_id( $t_data );
            if ( $can_manage_seasons || $t_shop === $user_shop_id ) {
                $scoped_tournaments[$t_name] = $t_data;
            }
        }

        // Determine Selected Tournament
        $selected_tournament = isset( $_REQUEST['selected_tournament'] ) ? sanitize_text_field( $_REQUEST['selected_tournament'] ) : '';
        if ( empty( $selected_tournament ) && ! empty( $scoped_tournaments ) ) {
            $keys = array_keys( $scoped_tournaments );
            $selected_tournament = reset( $keys );
        }

        $available_lines = ! empty( $selected_tournament ) && isset( $tournaments[$selected_tournament] ) 
            ? sywat_get_tournament_lines( $tournaments[$selected_tournament] ) 
            : array();

        // Load Registrations from DB
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

        // POST Handling Notice
        $notice = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_action'] ) ) {
            $is_valid_nonce = isset($_POST['sywat_nonce']) && wp_verify_nonce($_POST['sywat_nonce'], 'sywat_desk_action');
            if ( $is_valid_nonce ) {
                $action = sanitize_text_field( $_POST['sywat_action'] );
                if ( $action === 'register_shooter' && function_exists( 'sywat_handle_register_shooter' ) ) {
                    $notice = sywat_handle_register_shooter( $tournaments, $registrations );
                } elseif ( $action === 'import_shooters' && function_exists( 'sywat_handle_import_shooters' ) ) {
                    $notice = sywat_handle_import_shooters( $tournaments, $registrations );
                } elseif ( $action === 'purge_line' && function_exists( 'sywat_handle_purge_line' ) ) {
                    $notice = sywat_handle_purge_line( $tournaments, $registrations );
                }
            } else {
                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
            }
        }
        ?>

        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <!-- Master Operational Navigation Tabs (Card-Docked) -->
            <div class="sywat-nav-tabs no-print">
                <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">Manage Tournaments</a>
                <a href="<?php echo esc_url( site_url( '/sywat-register/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-register active">Register Shooters</a>
                <?php if ( $can_access_desk ): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-desk/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-checkin">Check In Archers</a>
                <?php endif; ?>
                <?php if ( $can_edit_scores ): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-enter-scores/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-edit">Enter Scores</a>
                <?php endif; ?>
                <?php if ( $can_manage_seasons ): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/?tab=seasons' ) ); ?>" class="sywat-tab-item sywat-tab-icon-season">Manage Seasons</a>
                <?php endif; ?>
            </div>

            <!-- Main Desk Card (Docked to Tabs with Balanced Header Controls) -->
            <div class="sywat-card no-print" style="border-top-left-radius:0;">
                <div class="sywat-card-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <span>Register Shooters Desk</span>

                    <!-- Tournament Switcher Control Bar (Right-Aligned) -->
                    <form method="get" action="<?php echo esc_url( site_url( '/sywat-register/' ) ); ?>" style="margin:0; display:flex; gap:8px; align-items:center;">
                        <label style="font-size:12px; font-weight:700; color:#475569; margin:0;">Active Tournament:</label>
                        <select name="selected_tournament" onchange="this.form.submit()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; height:38px;">
                            <?php if ( empty( $scoped_tournaments ) ): ?>
                                <option value="">No Tournaments Configured</option>
                            <?php else: ?>
                                <?php foreach ( $scoped_tournaments as $t_name => $t_data ): ?>
                                    <option value="<?php echo esc_attr( $t_name ); ?>" <?php selected( $selected_tournament, $t_name ); ?>>
                                        <?php echo esc_html( $t_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </form>
                </div>

                <div class="sywat-subtitle">Phone intake, group pre-registrations, and FareHarbor roster file imports.</div>

                <?php if ( ! empty( $selected_tournament ) && isset( $tournaments[$selected_tournament] ) ): ?>
                    <?php sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations ); ?>
                <?php else: ?>
                    <p style="color:#646970; font-style:italic;">Please select or create a tournament to begin registering shooters.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
if ( ! shortcode_exists( 'sywat_register' ) ) {
    add_shortcode( 'sywat_register', 'sywat_render_registration_desk' );
}

/**
 * Component Renderer: Registration Intake Forms & Line Usage Table
 */
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

        $modal_notice       = ! empty( $GLOBALS['sywat_modal_notice'] ) ? $GLOBALS['sywat_modal_notice'] : '';
        $skipped_duplicates = ! empty( $GLOBALS['sywat_skipped_duplicates'] ) ? $GLOBALS['sywat_skipped_duplicates'] : array();
        $skipped_full       = ! empty( $GLOBALS['sywat_skipped_full'] ) ? $GLOBALS['sywat_skipped_full'] : array();
        ?>
        <div class="no-print" style="display:flex; flex-direction:column; gap:24px;">
            
            <?php if ( ! empty( $modal_notice ) ): ?>
                <div><?php echo $modal_notice; ?></div>
            <?php endif; ?>

            <?php if ( ! empty( $skipped_duplicates ) || ! empty( $skipped_full ) ): ?>
                <script>
                document.addEventListener("DOMContentLoaded", function() {
                    <?php if ( ! empty( $skipped_duplicates ) ): ?>
                    const dupes = <?php echo json_encode( array_values( $skipped_duplicates ) ); ?>;
                    alert("⚠️ REGISTRATION NOTICE:\n\nThe following archer(s) were NOT registered because they are already signed up on this line:\n\n• " + dupes.join("\n• "));
                    <?php endif; ?>

                    <?php if ( ! empty( $skipped_full ) ): ?>
                    const fulls = <?php echo json_encode( array_values( $skipped_full ) ); ?>;
                    alert("⚠️ REGISTRATION NOTICE:\n\nThe following archer(s) were NOT registered because the target shooting line is FULL:\n\n• " + fulls.join("\n• "));
                    <?php endif; ?>
                });
                </script>
            <?php endif; ?>

            <!-- PHONE & GROUP INTAKE FORM -->
            <div style="background:#ffffff; padding:18px; border:1px solid #cbd5e1; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                <div class="sywat-style-header" style="margin-top: -6px; margin-bottom: 16px;">
                    1. Phone & Group Registration Intake
                </div>
                
                <form method="post" id="sywat_phone_intake_form">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_action" value="register_shooter">
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="modal_action" value="prereg">

                    <!-- Group Header Information -->
                    <div class="sywat-form-grid-4" style="background:#f8fafc; padding:14px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-weight:700; margin-bottom:4px; font-size:12px; color:#1e293b;">Caller / Primary Archer Name</label>
                            <input type="text" name="group_name" id="sywat_group_name" placeholder="e.g. John Smith" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; height:38px; box-sizing:border-box;" oninput="handleCallerNameInput(this)">
                        </div>

                        <div>
                            <label style="display:block; font-weight:700; margin-bottom:4px; font-size:12px; color:#1e293b;">Contact Phone Number</label>
                            <input type="tel" name="group_phone" id="sywat_group_phone" placeholder="e.g. 817-555-1234" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; height:38px; box-sizing:border-box;" oninput="formatPhoneNumber(this)">
                        </div>

                        <div>
                            <label style="display:block; font-weight:700; margin-bottom:4px; font-size:12px; color:#1e293b;">Default Shooting Line *</label>
                            <select name="default_line_idx" id="sywat_default_line_idx" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; height:38px; box-sizing:border-box;" required onchange="sessionStorage.setItem('sywat_last_line_idx', this.value); updateAllArcherLines(this.value)">
                                <?php foreach ( $available_lines as $idx => $line ): ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$current_default_line, (string)$idx ); ?>>
                                        <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label style="display:block; font-weight:700; margin-bottom:4px; font-size:12px; color:#1e293b;">Amount Paid Today ($)</label>
                            <input type="number" step="0.01" min="0" name="amount_paid_today" id="sywat_amount_paid_today" value="0.00" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; height:38px; box-sizing:border-box;" oninput="recalculateGroupTotal()">
                        </div>
                    </div>

                    <!-- Column Headings Bar -->
                    <div class="sywat-archer-header-row" style="display:flex; gap:10px; align-items:center; margin-bottom:6px; padding:0 4px; font-weight:700; font-size:12px; color:#475569;">
                        <div style="flex:2; min-width:180px;">Archer Full Name *</div>
                        <div style="flex:1; min-width:100px;">Target Distance *</div>
                        <div style="flex:1.5; min-width:160px;">Division *</div>
                        <div style="flex:1.2; min-width:130px;">Line Override</div>
                        <div style="width:38px;"></div>
                    </div>

                    <!-- Archer Rows Container -->
                    <div id="sywat_archer_rows_container">
                        <!-- Archer Row 1 -->
                        <div class="sywat-archer-input-row" style="display:flex; gap:10px; align-items:center; margin-bottom:8px; flex-wrap:wrap;">
                            <div style="flex:2; min-width:180px; position:relative;">
                                <input type="text" name="archers[0][name]" id="sywat_first_archer_name" class="sywat-archer-name-input" placeholder="Archer Full Name *" autocomplete="off" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; height:38px; box-sizing:border-box;" required oninput="handleArcherNameInput(this); recalculateGroupTotal();">
                            </div>

                            <!-- Target Distance Selection -->
                            <select name="archers[0][distance]" class="sywat-distance-select" style="flex:1; min-width:100px; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; height:38px; box-sizing:border-box;" onchange="handleDistanceChange(this)">
                                <option value="20yd" selected>20yd</option>
                                <option value="Cub">Cub (10yd)</option>
                            </select>

                            <!-- Full 7 TFAA Age Division Selection (Age Order) -->
                            <select name="archers[0][division]" class="sywat-division-select" style="flex:1.5; min-width:160px; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; height:38px; box-sizing:border-box;" onchange="handleDivisionChange(this);">
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
                            <select name="archers[0][line_idx]" class="sywat-line-override-select" style="flex:1.2; min-width:130px; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; height:38px; box-sizing:border-box;" onchange="recalculateGroupTotal()">
                                <?php foreach ( $available_lines as $idx => $line ): ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$current_default_line, (string)$idx ); ?>>
                                        <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" style="height:38px; width:38px; padding:2px; font-size:12px;" onclick="removeArcherRow(this)" title="Remove Archer"></button>
                        </div>
                    </div>

                    <!-- Add Row & Live Payment Total Display -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding-top:14px; border-top:1px dashed #cbd5e1; flex-wrap:wrap; gap:10px;">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" style="height:38px;" onclick="addArcherRow()">Add Another Archer</button>

                        <div style="text-align:right;">
                            <div style="font-size:14px; font-weight:700; color:#0f172a;">
                                Group Total: <span id="sywat_group_total_disp" style="color:#0284c7;">$30.00</span>
                                <span id="sywat_family_cap_badge" style="display:none; background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:2px 8px; border-radius:12px; font-size:11px; margin-left:6px;">🟢 Family Cap Applied</span>
                            </div>
                            <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                Balance Due at Desk: <strong id="sywat_balance_due_disp" style="color:#dc2626;">$30.00</strong>
                            </div>
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-checkin" style="height:38px;">Save & Register Shooters</button>
                    </div>
                </form>
            </div>

            <!-- FAREHARBOR CSV BULK UPLOAD (CCR ADMIN ONLY) -->
            <?php if ( $can_show_csv_import ): ?>
                <div style="background:#ffffff; padding:18px; border:1px solid #cbd5e1; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                    <div class="sywat-style-header" style="margin-top: -6px; margin-bottom: 16px;">
                        2. Bulk Upload Roster File (FareHarbor CSV)
                    </div>
                    <form method="post" enctype="multipart/form-data" id="sywat_import_form">
                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                        <input type="hidden" name="sywat_action" value="import_shooters">
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="modal_action" value="prereg">

                        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom: 10px;">
                            <div style="flex:1; min-width:200px;">
                                <label style="display:block; font-weight:700; margin-bottom:4px; font-size:12px; color:#1e293b;">Select Target Shooting Line</label>
                                <select name="target_line_idx" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; height:38px; box-sizing:border-box;" required>
                                    <?php foreach ( $available_lines as $idx => $line ): ?>
                                        <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$current_default_line, (string)$idx ); ?>>
                                            <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="flex:2; min-width:250px;">
                                <label style="display:block; font-weight:700; margin-bottom:4px; font-size:12px; color:#1e293b;">Select File (.csv, .txt)</label>
                                <input type="file" id="import_file_input" name="import_file" accept=".csv,.txt" style="width:100%; padding:4px; font-size:13px; height:38px;">
                            </div>

                            <button type="submit" id="sywat_upload_btn" class="sywat-btn sywat-btn-primary sywat-btn-icon-export" style="height:38px;">Upload & Parse File</button>
                        </div>

                        <details style="margin-top: 10px;">
                            <summary style="font-size: 12px; font-weight: 600; color: #2271b1; cursor: pointer;">Or paste shop report text directly...</summary>
                            <div style="margin-top: 8px;">
                                <textarea name="pasted_text" rows="5" placeholder="Paste report text directly here if preferred..." style="width: 100%; font-family: monospace; font-size: 12px; border:1px solid #cbd5e1; border-radius:4px; padding:8px;"></textarea>
                            </div>
                        </details>
                    </form>
                </div>
            <?php endif; ?>

            <!-- LINE SLOT USAGE TABLE -->
            <div style="margin-top: 6px;">
                <h4 style="margin:0 0 12px 0; font-size:15px; font-weight:700; color:#0f172a;">Current Line Slot Usage</h4>
                <div class="sywat-table-responsive">
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th style="text-align:left; width: 30%;">Line Name</th>
                                <th style="text-align:center; width: 25%;">20yd Capacity</th>
                                <th style="text-align:center; width: 25%;">Cub Capacity</th>
                                <th style="text-align:center; width: 20%;">Action</th>
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
                                    <td><strong style="color: #0f172a;"><?php echo esc_html( $line_title ); ?></strong></td>
                                    <td style="text-align:center;">
                                        <strong><?php echo $c_20; ?></strong> / <?php echo $m_20; ?>
                                        <span class="sywat-badge <?php echo ($c_20 >= $m_20) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>" style="margin-left: 6px;"><?php echo ($c_20 >= $m_20) ? 'Full' : 'Open'; ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <strong><?php echo $c_cub; ?></strong> / <?php echo $m_cub; ?>
                                        <span class="sywat-badge <?php echo ($c_cub >= $m_cub) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>" style="margin-left: 6px;"><?php echo ($c_cub >= $m_cub) ? 'Full' : 'Open'; ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ( $total_line_shooters > 0 ): ?>
                                            <form method="post" style="display: inline-block; margin: 0;" onsubmit="return confirm('Are you sure you want to clear ALL <?php echo $total_line_shooters; ?> archers from <?php echo esc_js( $line_title ); ?>?');">
                                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                <input type="hidden" name="sywat_action" value="purge_line">
                                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                                <input type="hidden" name="redirect_action" value="prereg">
                                                <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Clear Line</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: #94a3b8; font-weight: 600;">(Empty)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
        if ( function_exists( 'sywat_render_registration_scripts' ) ) {
            sywat_render_registration_scripts();
        }
    }
}
