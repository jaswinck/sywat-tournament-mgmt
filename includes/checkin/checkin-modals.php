<?php
/**
 * Sub-Module: Check-In Desk Modals & Client Scripts
 * Path: includes/checkin/checkin-modals.php
 * Author: SYWAT Archery
 * Version: 3.8.0
 */

if (!defined('ABSPATH')) exit;

function sywat_desk_render_modals($data) {
    $modal_archer              = $data['modal_archer'];
    $modal_edit_archer         = $data['modal_edit_archer'];
    $selected_tournament       = $data['selected_tournament'];
    $selected_line_idx         = $data['selected_line_idx'];
    $search_query              = $data['search_query'];
    $lines                     = $data['lines'];
    $closed_lines              = $data['closed_lines'];
    $divs                      = $data['divs'];
    $genders                   = $data['genders'];
    $sywat_styles              = $data['sywat_styles'];
    $legacy_div_map            = $data['legacy_div_map'];
    $existing_families         = $data['existing_families'];
    $existing_families_by_line = isset($data['existing_families_by_line']) ? $data['existing_families_by_line'] : array();

    ?>
    <style>
        .sywat-badge-verified { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 6px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; display: block; margin-top: 4px; }
        .sywat-badge-known { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 6px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; display: block; margin-top: 4px; }
        .sywat-badge-none { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 6px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; display: block; margin-top: 4px; }
        .sywat-pill-box { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .sywat-btn-pill { background: #0284c7; color: #ffffff; border: none; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; cursor: pointer; }
        .sywat-btn-pill:hover { background: #0369a1; }
        .sywat-btn-use-official { background: #0d9488; color: #ffffff; border: none; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; cursor: pointer; margin-left: 6px; }
        .sywat-btn-use-official:hover { background: #0f766e; }
        .sywat-multi-style-alert { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 10px; border-radius: 6px; font-size: 11px; margin-bottom: 6px; width: 100%; box-sizing: border-box; }
        .sywat-multi-style-title { font-weight: bold; font-size: 12px; margin-bottom: 6px; display: flex; align-items: center; gap: 4px; color: #856404; }
    </style>

    <!-- CHECK-IN MODAL (PRE-REG) -->
    <?php if ($modal_archer): ?>
    <?php 
        $modal_due = isset($modal_archer['balance_due']) ? floatval($modal_archer['balance_due']) : 0; 
        $is_family = isset($modal_archer['paid']) && $modal_archer['paid'] === 'Family';
        $curr_div_check = isset($modal_archer['division']) ? $modal_archer['division'] : '';
        $is_youth_check = (stripos($curr_div_check, 'Cub') !== false || stripos($curr_div_check, 'Youth') !== false || stripos($curr_div_check, 'Young Adult') !== false);
        $display_due    = $is_family ? $modal_due : ($is_youth_check ? 25.00 : 30.00);

        $checkin_line_idx = function_exists('sywat_desk_resolve_line_idx') ? sywat_desk_resolve_line_idx($modal_archer, $lines) : (isset($modal_archer['line_index']) ? $modal_archer['line_index'] : '0');
        $checkin_families = isset($existing_families_by_line[$checkin_line_idx]) ? $existing_families_by_line[$checkin_line_idx] : $existing_families;
    ?>
    <div class="sywat-modal-backdrop active">
        <div class="sywat-modal-content sywat-modal-sm">
            <div class="sywat-modal-header no-print">
                <h3>Check In Archer</h3>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <button type="submit" name="sywat_action" value="cancel" class="sywat-modal-close" formnovalidate>&times;</button>
                </form>
            </div>
            <div class="sywat-modal-body">
                <form method="post">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                    <input type="hidden" name="reg_id" value="<?php echo esc_attr($modal_archer['id']); ?>">

                    <div class="sywat-field-group" style="position:relative;">
                        <label>Name:</label>
                        <input type="text" name="checkin_name" id="checkin_archer_name" value="<?php echo esc_attr($modal_archer['name']); ?>" autocomplete="off" style="font-weight:bold;">
                        <div id="checkin_tfaa_badge"></div>
                        <div id="checkin_tfaa_pills" class="sywat-pill-box"></div>
                    </div>

                    <?php if ($modal_due > 0): ?>
                        <div id="sywat_unpaid_warning_banner" class="sywat-alert sywat-alert-danger" style="text-align:center; margin-top:12px;">
                            <div style="font-size:16px; font-weight:bold;">
                                ⚠️ <?php echo $is_family ? 'UNPAID FAMILY BALANCE' : 'UNPAID BALANCE'; ?>: $<?php echo number_format($display_due, 2); ?>
                            </div>
                            <div style="font-size:13px; margin-top:4px; font-weight:600;">
                                <?php if ($is_family): ?>
                                    Family Cap Group Outstanding Balance. Collect Payment Onsite.
                                <?php else: ?>
                                    Please See Event Staff for Payment
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="sywat-field-group">
                            <label style="color:#721c24; cursor:pointer; display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" name="checkin_payment_verified" id="sywat_payment_verified_check" value="1" required style="width:auto;" onchange="toggleUnpaidBanner(this.checked)">
                                Payment / Resolution Verified with Event Staff
                            </label>
                        </div>
                    <?php endif; ?>

                    <div class="sywat-field-group">
                        <label>Division:</label>
                        <select name="checkin_division" id="checkin_division" required onchange="handleStyleRestrictions('checkin'); evaluateTfaaStatus('checkin');">
                            <?php
                            $curr_div = isset($modal_archer['division']) ? $modal_archer['division'] : '';
                            if (isset($legacy_div_map[$curr_div])) $curr_div = $legacy_div_map[$curr_div];

                            echo '<option value="" disabled ' . (empty($curr_div) ? 'selected' : '') . ' hidden>Select Division</option>';
                            foreach ($divs as $d) {
                                if ($d === 'No Show' && $curr_div !== 'No Show') continue;
                                echo '<option value="' . esc_attr($d) . '" ' . selected($curr_div, $d, false) . '>' . esc_html($d) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Gender:</label>
                        <select name="checkin_gender" id="checkin_gender" required onchange="evaluateTfaaStatus('checkin')">
                            <?php
                            $curr_gen = isset($modal_archer['gender']) && in_array($modal_archer['gender'], $genders) ? $modal_archer['gender'] : '';
                            echo '<option value="" disabled ' . (empty($curr_gen) ? 'selected' : '') . ' hidden>Select Gender</option>';
                            foreach ($genders as $g) {
                                if ($g === 'No Show' && $curr_gen !== 'No Show') continue;
                                echo '<option value="' . esc_attr($g) . '" ' . selected($curr_gen, $g, false) . '>' . esc_html($g) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Style:</label>
                        <select name="checkin_style" id="checkin_style" required onchange="evaluateTfaaStatus('checkin')">
                            <?php
                            $curr_sty = isset($modal_archer['style']) && in_array($modal_archer['style'], $sywat_styles) ? $modal_archer['style'] : '';
                            echo '<option value="" disabled ' . (empty($curr_sty) ? 'selected' : '') . ' hidden>Select Style</option>';
                            foreach ($sywat_styles as $s) {
                                if ($s === 'No Show' && $curr_sty !== 'No Show') continue;
                                echo '<option value="' . esc_attr($s) . '" ' . selected($curr_sty, $s, false) . '>' . esc_html($s) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Paid Option:</label>
                        <select name="checkin_pay_option" id="checkin_pay_option" required onchange="toggleFamilyBox('checkin')">
                            <?php
                            $curr_paid = isset($modal_archer['paid']) ? $modal_archer['paid'] : '';
                            $curr_pay_opt = ($curr_paid === 'Family') ? 'Family' : (($curr_paid === 'Field Governor') ? 'Field Governor' : 'Individual');
                            ?>
                            <option value="Individual" <?php selected($curr_pay_opt, 'Individual'); ?>>Individual</option>
                            <option value="Family" <?php selected($curr_pay_opt, 'Family'); ?>>Family</option>
                            <option value="Field Governor" <?php selected($curr_pay_opt, 'Field Governor'); ?>>Field Governor</option>
                        </select>
                    </div>

                    <div class="sywat-field-group" style="<?php echo ($curr_pay_opt === 'Family') ? '' : 'display:none;'; ?>" id="checkin_family_box">
                        <label>Line Family Group:</label>
                        <select name="checkin_family_group_id" id="checkin_family_group_id">
                            <option value="">[ + Start New Family Group for This Line ]</option>
                            <?php
                            $curr_fam_id = isset($modal_archer['family_id']) ? $modal_archer['family_id'] : '';
                            foreach ($checkin_families as $fid => $flabel) {
                                echo '<option value="' . esc_attr($fid) . '" ' . selected($curr_fam_id, $fid, false) . '>' . esc_html($flabel) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                        <button type="submit" name="sywat_action" value="cancel" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" formnovalidate>Cancel</button>
                        <button type="submit" name="sywat_action" value="save_checkin" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin">Complete Check-In</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- EDIT ARCHER MODAL -->
    <?php if ($modal_edit_archer): ?>
    <?php
        $edit_line_idx = function_exists('sywat_desk_resolve_line_idx') ? sywat_desk_resolve_line_idx($modal_edit_archer, $lines) : (isset($modal_edit_archer['line_index']) ? $modal_edit_archer['line_index'] : '0');
        $edit_families = isset($existing_families_by_line[$edit_line_idx]) ? $existing_families_by_line[$edit_line_idx] : $existing_families;
    ?>
    <div class="sywat-modal-backdrop active">
        <div class="sywat-modal-content sywat-modal-sm">
            <div class="sywat-modal-header no-print">
                <h3>Edit Archer Registration</h3>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <button type="submit" name="sywat_action" value="cancel" class="sywat-modal-close" formnovalidate>&times;</button>
                </form>
            </div>
            <div class="sywat-modal-body">
                <form method="post">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                    <input type="hidden" name="reg_id" value="<?php echo esc_attr($modal_edit_archer['id']); ?>">

                    <div class="sywat-field-group" style="position:relative;">
                        <label>Full Name:</label>
                        <input type="text" name="edit_name" id="edit_archer_name" value="<?php echo esc_attr($modal_edit_archer['name']); ?>" autocomplete="off" required>
                    </div>

                    <div class="sywat-field-group">
                        <label>Shooting Line:</label>
                        <select name="edit_line_index" id="edit_line_index" required onchange="updateEditFamilyOptions(this.value)">
                            <?php $curr_line = isset($modal_edit_archer['line_index']) ? $modal_edit_archer['line_index'] : ''; ?>
                            <?php foreach ($lines as $idx => $line): ?>
                                <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$curr_line, (string)$idx); ?>>
                                    <?php echo esc_html($line['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Division:</label>
                        <select name="edit_division" id="edit_division" required onchange="handleStyleRestrictions('edit');">
                            <?php
                            $raw_div = isset($modal_edit_archer['division']) ? $modal_edit_archer['division'] : '';
                            if (isset($legacy_div_map[$raw_div])) $raw_div = $legacy_div_map[$raw_div];
                            
                            $curr_div = in_array($raw_div, $divs) ? $raw_div : '';
                            
                            echo '<option value="" disabled ' . (empty($curr_div) ? 'selected' : '') . ' hidden>Select Division</option>';
                            foreach ($divs as $d) {
                                if ($d === 'No Show' && $curr_div !== 'No Show') continue;
                                echo '<option value="' . esc_attr($d) . '" ' . selected($curr_div, $d, false) . '>' . esc_html($d) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Gender:</label>
                        <select name="edit_gender" id="edit_gender" required>
                            <?php
                            $curr_gen = isset($modal_edit_archer['gender']) && in_array($modal_edit_archer['gender'], $genders) ? $modal_edit_archer['gender'] : '';
                            echo '<option value="" disabled ' . (empty($curr_gen) ? 'selected' : '') . ' hidden>Select Gender</option>';
                            foreach ($genders as $g) {
                                if ($g === 'No Show' && $curr_gen !== 'No Show') continue;
                                echo '<option value="' . esc_attr($g) . '" ' . selected($curr_gen, $g, false) . '>' . esc_html($g) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Style:</label>
                        <select name="edit_style" id="edit_style" required>
                            <?php
                            $curr_sty = isset($modal_edit_archer['style']) && in_array($modal_edit_archer['style'], $sywat_styles) ? $modal_edit_archer['style'] : '';
                            echo '<option value="" disabled ' . (empty($curr_sty) ? 'selected' : '') . ' hidden>Select Style</option>';
                            foreach ($sywat_styles as $s) {
                                if ($s === 'No Show' && $curr_sty !== 'No Show') continue;
                                echo '<option value="' . esc_attr($s) . '" ' . selected($curr_sty, $s, false) . '>' . esc_html($s) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Paid Option:</label>
                        <select name="edit_pay_option" id="edit_pay_option" required onchange="toggleFamilyBox('edit')">
                            <?php
                            $curr_paid = isset($modal_edit_archer['paid']) ? $modal_edit_archer['paid'] : '';
                            $curr_pay_opt = ($curr_paid === 'Family') ? 'Family' : (($curr_paid === 'Field Governor') ? 'Field Governor' : 'Individual');
                            ?>
                            <option value="Individual" <?php selected($curr_pay_opt, 'Individual'); ?>>Individual</option>
                            <option value="Family" <?php selected($curr_pay_opt, 'Family'); ?>>Family</option>
                            <option value="Field Governor" <?php selected($curr_pay_opt, 'Field Governor'); ?>>Field Governor</option>
                        </select>
                    </div>

                    <div class="sywat-field-group" style="<?php echo ($curr_pay_opt === 'Family') ? '' : 'display:none;'; ?>" id="edit_family_box">
                        <label>Line Family Group:</label>
                        <select name="edit_family_group_id" id="edit_family_group_id">
                            <option value="">[ + Start New Family Group for This Line ]</option>
                            <?php
                            $curr_fam_id = isset($modal_edit_archer['family_id']) ? $modal_edit_archer['family_id'] : '';
                            foreach ($edit_families as $fid => $flabel) {
                                echo '<option value="' . esc_attr($fid) . '" ' . selected($curr_fam_id, $fid, false) . '>' . esc_html($flabel) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Balance Due ($):</label>
                        <input type="number" step="0.01" min="0" name="edit_balance_due" value="<?php echo esc_attr(isset($modal_edit_archer['balance_due']) ? $modal_edit_archer['balance_due'] : 0); ?>">
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px;">
                        <button type="submit" name="sywat_action" value="delete_archer" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" onclick="return confirm('⚠️ Are you sure you want to PERMANENTLY delete this archer?');" formnovalidate>Delete</button>
                        
                        <div style="display:flex; gap:10px;">
                            <button type="submit" name="sywat_action" value="cancel" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" formnovalidate>Cancel</button>
                            <button type="submit" name="sywat_action" value="save_edit" class="sywat-btn sywat-btn-primary sywat-btn-icon-save">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ADD WALK-IN MODAL -->
    <div id="walkinModal" class="sywat-modal-backdrop">
        <div class="sywat-modal-content sywat-modal-sm">
            <div class="sywat-modal-header no-print">
                <h3>Add Walk-In Registration</h3>
                <button type="button" class="sywat-modal-close" onclick="closeWalkinModal()">&times;</button>
            </div>
            <div class="sywat-modal-body">
                <form method="post">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">

                    <div class="sywat-field-group" style="position:relative;">
                        <label>Full Name:</label>
                        <input type="text" name="walkin_name" id="walkin_name_input" required placeholder="Type archer name..." autocomplete="off">
                        <div id="walkin_tfaa_badge"></div>
                        <div id="walkin_tfaa_pills" class="sywat-pill-box"></div>
                    </div>

                    <div class="sywat-field-group">
                        <label>Shooting Line:</label>
                        <select name="walkin_line_index" id="walkin_line_index" required onchange="updateWalkinFamilyOptions(this.value)">
                            <?php foreach ($lines as $idx => $line): ?>
                                <?php $is_c = in_array((string)$idx, $closed_lines); ?>
                                <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$selected_line_idx, (string)$idx); ?><?php echo $is_c ? ' disabled' : ''; ?>>
                                    <?php echo esc_html($line['name']) . ($is_c ? ' (Closed)' : ''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Division:</label>
                        <select name="walkin_division" id="walkin_division" required onchange="handleStyleRestrictions('walkin'); evaluateTfaaStatus('walkin');">
                            <option value="" disabled selected hidden>Select Division</option>
                            <?php foreach ($divs as $d): ?>
                                <?php if ($d === 'No Show') continue; ?>
                                <option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Gender:</label>
                        <select name="walkin_gender" id="walkin_gender" required onchange="evaluateTfaaStatus('walkin')">
                            <option value="" disabled selected hidden>Select Gender</option>
                            <?php foreach ($genders as $g): ?>
                                <?php if ($g === 'No Show') continue; ?>
                                <option value="<?php echo esc_attr($g); ?>"><?php echo esc_html($g); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Style:</label>
                        <select name="walkin_style" id="walkin_style" required onchange="evaluateTfaaStatus('walkin')">
                            <option value="" disabled selected hidden>Select Style</option>
                            <?php foreach ($sywat_styles as $s): ?>
                                <?php if ($s === 'No Show') continue; ?>
                                <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label>Paid Option:</label>
                        <select name="walkin_pay_option" id="walkin_pay_option" required onchange="toggleFamilyBox('walkin')">
                            <option value="Individual">Individual</option>
                            <option value="Family">Family</option>
                            <option value="Field Governor">Field Governor</option>
                        </select>
                    </div>

                    <div class="sywat-field-group" style="display:none;" id="walkin_family_box">
                        <label>Line Family Group:</label>
                        <select name="walkin_family_group_id" id="walkin_family_group_id">
                            <option value="">[ + Start New Family Group for This Line ]</option>
                            <?php foreach ($existing_families as $fid => $flabel): ?>
                                <option value="<?php echo esc_attr($fid); ?>"><?php echo esc_html($flabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeWalkinModal()">Cancel</button>
                        <button type="submit" name="sywat_action" value="add_walkin" class="sywat-btn sywat-btn-primary sywat-btn-icon-checkin">Register & Check In</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function sywat_desk_render_client_scripts($data) {
    $tfaa_cache = get_option( 'sywat_tfaa_cache', array() );
    $exact_entries = isset( $tfaa_cache['exact'] ) && is_array( $tfaa_cache['exact'] ) ? $tfaa_cache['exact'] : array();

    $js_tfaa_desk_data = array();
    foreach ( $exact_entries as $norm_key => $records ) {
        if ( ! is_array( $records ) || empty( $records ) ) continue;

        // Prioritize Pro style if multiple records exist for this archer
        $best_rec = $records[0];
        foreach ( $records as $rec ) {
            if ( isset( $rec['style'] ) && stripos( $rec['style'], 'FS Pro' ) !== false ) {
                $best_rec = $rec;
                break;
            }
        }

        $name = isset( $best_rec['full_name'] ) ? $best_rec['full_name'] : ( isset( $best_rec['name'] ) ? $best_rec['name'] : '' );
        if ( ! empty( $name ) ) {
            $js_tfaa_desk_data[] = array(
                'name'     => $name,
                'division' => isset( $best_rec['division'] ) ? $best_rec['division'] : '',
                'gender'   => isset( $best_rec['gender'] ) ? $best_rec['gender'] : '',
                'style'    => isset( $best_rec['style'] ) ? $best_rec['style'] : '',
            );
        }
    }

    $existing_families_by_line = isset($data['existing_families_by_line']) ? $data['existing_families_by_line'] : array();
    ?>
    <script>
    const sywatHistory           = <?php echo json_encode($data['history_profiles']); ?>;
    const tfaaData               = <?php echo json_encode($data['tfaa_data_bundle']); ?>;
    const sywatDeskTfaaShooters  = <?php echo json_encode( array_values( $js_tfaa_desk_data ) ); ?>;
    const existingFamiliesByLine = <?php echo json_encode($existing_families_by_line); ?>;

    function escapeHtmlDesk(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function updateWalkinFamilyOptions(lineIdx) {
        const famSelect = document.getElementById('walkin_family_group_id');
        if (!famSelect) return;

        const currentVal = famSelect.value;
        famSelect.innerHTML = '<option value="">[ + Start New Family Group for This Line ]</option>';

        const fams = (existingFamiliesByLine && existingFamiliesByLine[lineIdx]) ? existingFamiliesByLine[lineIdx] : {};

        Object.keys(fams).forEach(fid => {
            const opt = document.createElement('option');
            opt.value = fid;
            opt.textContent = fams[fid];
            if (fid === currentVal) opt.selected = true;
            famSelect.appendChild(opt);
        });
    }

    function updateEditFamilyOptions(lineIdx) {
        const famSelect = document.getElementById('edit_family_group_id');
        if (!famSelect) return;

        famSelect.innerHTML = '<option value="">[ + Start New Family Group for This Line ]</option>';

        const fams = (existingFamiliesByLine && existingFamiliesByLine[lineIdx]) ? existingFamiliesByLine[lineIdx] : {};

        Object.keys(fams).forEach(fid => {
            const opt = document.createElement('option');
            opt.value = fid;
            opt.textContent = fams[fid];
            famSelect.appendChild(opt);
        });
    }

    function toggleUnpaidBanner(isChecked) {
        const banner = document.getElementById('sywat_unpaid_warning_banner');
        if (banner) {
            banner.style.display = isChecked ? 'none' : 'block';
        }
    }

    function findTfaaMatch(typedName) {
        if (!typedName || !tfaaData) return null;
        const clean = typedName.trim().toLowerCase().replace(/\s+/g, ' ');
        if (!clean || clean.length < 2) return null;

        let matches = null;

        if (tfaaData.exact && tfaaData.exact[clean]) {
            matches = tfaaData.exact[clean];
        } else {
            const parts = clean.split(' ');
            if (parts.length > 1) {
                const flKey = parts[0] + '___' + parts[parts.length - 1];
                if (tfaaData.fl && tfaaData.fl[flKey]) matches = tfaaData.fl[flKey];
            }
        }

        if (!matches && tfaaData.exact) {
            let partialMatches = [];
            const keys = Object.keys(tfaaData.exact);
            for (let i = 0; i < keys.length; i++) {
                if (keys[i].includes(clean)) {
                    const arr = tfaaData.exact[keys[i]];
                    if (Array.isArray(arr)) {
                        partialMatches = partialMatches.concat(arr);
                    }
                }
            }
            if (partialMatches.length > 0) matches = partialMatches;
        }

        if (Array.isArray(matches) && matches.length > 1) {
            matches.sort((a, b) => {
                const aPro = (a.style && a.style.indexOf('FS Pro') !== -1) ? 1 : 0;
                const bPro = (b.style && b.style.indexOf('FS Pro') !== -1) ? 1 : 0;
                return bPro - aPro;
            });
        }

        return matches;
    }

    function findShopHistoryMatch(typedName) {
        if (!typedName || !sywatHistory || !sywatHistory.exact) return null;
        const clean = typedName.trim().toLowerCase().replace(/\s+/g, ' ');
        if (!clean) return null;

        if (sywatHistory.exact[clean]) return sywatHistory.exact[clean];

        const parts = clean.split(' ');
        if (parts.length > 1) {
            const flKey = parts[0] + '___' + parts[parts.length - 1];
            if (sywatHistory.fl && sywatHistory.fl[flKey]) return sywatHistory.fl[flKey];
        }
        return null;
    }

    function matchDeskTfaaSelect(value, selectEl) {
        if (!value || !selectEl) return;
        const clean = value.trim().toLowerCase();

        for (let opt of selectEl.options) {
            if (!opt.value) continue;
            const optLower = opt.value.trim().toLowerCase();
            
            if (optLower === clean) {
                selectEl.value = opt.value;
                return;
            }
            
            const cleanPrefix = clean.split(' –')[0].split(' (')[0].trim();
            const optPrefix   = optLower.split(' –')[0].split(' (')[0].trim();
            
            if (cleanPrefix === optPrefix) {
                selectEl.value = opt.value;
                return;
            }
        }
    }

    function handleDeskTfaaAutocomplete(input) {
        const query = input.value.trim().toLowerCase();
        closeAllDeskTfaaAutocompletes();

        if (query.length < 2) return;

        let matches = [];
        if (Array.isArray(sywatDeskTfaaShooters) && sywatDeskTfaaShooters.length > 0) {
            matches = sywatDeskTfaaShooters.filter(s => s.name && s.name.toLowerCase().includes(query)).slice(0, 8);
        } else if (tfaaData && tfaaData.exact) {
            const keys = Object.keys(tfaaData.exact);
            for (let i = 0; i < keys.length; i++) {
                if (keys[i].includes(query)) {
                    const arr = tfaaData.exact[keys[i]];
                    if (Array.isArray(arr) && arr.length > 0) {
                        let best = arr[0];
                        for (let k = 0; k < arr.length; k++) {
                            if (arr[k].style && arr[k].style.indexOf('FS Pro') !== -1) {
                                best = arr[k];
                                break;
                            }
                        }
                        matches.push({
                            name: best.full_name || keys[i],
                            division: best.division || '',
                            gender: best.gender || '',
                            style: best.style || ''
                        });
                    }
                    if (matches.length >= 8) break;
                }
            }
        }

        if (matches.length === 0) return;

        const parent = input.parentElement;
        if (getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }

        const dropdown = document.createElement('div');
        dropdown.className = 'sywat-desk-tfaa-dropdown';
        dropdown.style.cssText = 'position:absolute; top:100%; left:0; width:100%; min-width:240px; z-index:99999; background:#fff; border:1px solid #94a3b8; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.15); max-height:180px; overflow-y:auto; margin-top:2px;';

        matches.forEach(m => {
            const item = document.createElement('div');
            item.style.cssText = 'padding:7px 10px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:12px; display:flex; justify-content:space-between; align-items:center; color:#1e293b;';
            item.innerHTML = `<strong>${escapeHtmlDesk(m.name)}</strong> <span style="font-size:11px; color:#0284c7; background:#e0f2fe; padding:1px 6px; border-radius:3px;">${escapeHtmlDesk(m.division || '')}</span>`;

            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                input.value = m.name;
                closeAllDeskTfaaAutocompletes();

                const form = input.closest('form');
                let prefix = 'walkin';
                if (input.id === 'checkin_archer_name' || input.name === 'checkin_name') {
                    prefix = 'checkin';
                } else if (input.id === 'edit_archer_name' || input.name === 'edit_name') {
                    prefix = 'edit';
                }

                if (form) {
                    const divEl   = form.querySelector('select[name="walkin_division"], select[name="checkin_division"], select[name="edit_division"]');
                    const genEl   = form.querySelector('select[name="walkin_gender"], select[name="checkin_gender"], select[name="edit_gender"]');
                    const styleEl = form.querySelector('select[name="walkin_style"], select[name="checkin_style"], select[name="edit_style"]');

                    if (divEl && m.division) matchDeskTfaaSelect(m.division, divEl);
                    if (genEl && m.gender) matchDeskTfaaSelect(m.gender, genEl);
                    if (styleEl && m.style) matchDeskTfaaSelect(m.style, styleEl);
                }

                if (typeof autoFillOnNameChange === 'function' && (prefix === 'walkin' || prefix === 'checkin')) {
                    autoFillOnNameChange(prefix);
                }
            });

            dropdown.appendChild(item);
        });

        parent.appendChild(dropdown);
    }

    function closeAllDeskTfaaAutocompletes() {
        document.querySelectorAll('.sywat-desk-tfaa-dropdown').forEach(el => el.remove());
    }

    function loadTfaaCategory(prefix, divVal, genVal, styleVal) {
        const divEl   = document.getElementById(prefix + '_division');
        const genEl   = document.getElementById(prefix + '_gender');
        const styleEl = document.getElementById(prefix + '_style');

        if (divEl && divVal) matchDeskTfaaSelect(divVal, divEl);
        if (genEl && genVal) matchDeskTfaaSelect(genVal, genEl);
        if (styleEl && styleVal) matchDeskTfaaSelect(styleVal, styleEl);

        handleStyleRestrictions(prefix);
        evaluateTfaaStatus(prefix);
    }

    function updateNameToOfficial(prefix, officialName) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        if (nameInput && officialName) {
            nameInput.value = officialName;
            autoFillOnNameChange(prefix);
        }
    }

    function evaluateTfaaStatus(prefix) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        const badgeEl   = document.getElementById(prefix + '_tfaa_badge');
        const pillsEl   = document.getElementById(prefix + '_tfaa_pills');

        if (!nameInput || !badgeEl || !pillsEl) return;

        const typedName = nameInput.value;
        const tfaaMatches = findTfaaMatch(typedName);

        pillsEl.innerHTML = '';

        if (!tfaaMatches || !Array.isArray(tfaaMatches) || tfaaMatches.length === 0) {
            badgeEl.className = 'sywat-badge-none';
            badgeEl.innerHTML = '⚪ No TFAA History (Current Season)';
            return;
        }

        const currDiv   = document.getElementById(prefix + '_division')?.value || '';
        const currGen   = document.getElementById(prefix + '_gender')?.value || '';
        const currStyle = document.getElementById(prefix + '_style')?.value || '';

        let isExactMatch = false;
        let knownStyles = [];

        let buttonContainer = document.createElement('div');
        buttonContainer.style.cssText = 'display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;';

        tfaaMatches.forEach(cat => {
            if (cat.style) {
                let shortSty = cat.style.split(' – ')[0];
                if (!knownStyles.includes(shortSty)) knownStyles.push(shortSty);
            }

            const cDiv = (cat.division || '').toLowerCase();
            const cGen = (cat.gender || '').toLowerCase();
            const cSty = (cat.style || '').toLowerCase();

            const uDiv = currDiv.toLowerCase();
            const uGen = currGen.toLowerCase();
            const uSty = currStyle.toLowerCase();

            const divMatch   = (cDiv === uDiv) || (cDiv && uDiv && cDiv.split(' (')[0].trim() === uDiv.split(' (')[0].trim());
            const genMatch   = (cGen === uGen) || (cGen && uGen && cGen.charAt(0) === uGen.charAt(0));
            const styleMatch = (cSty === uSty) || (cSty && uSty && cSty.split(' –')[0].trim() === uSty.split(' –')[0].trim());

            if (divMatch && genMatch && styleMatch) {
                isExactMatch = true;
            }

            const divShort = cat.division ? cat.division.split(' ')[0] : '';
            const genShort = cat.gender ? cat.gender.charAt(0) : '';
            const styShort = cat.style ? cat.style.split(' – ')[0] : '';

            const pillBtn = document.createElement('button');
            pillBtn.type = 'button';
            pillBtn.className = 'sywat-btn-pill';
            pillBtn.innerHTML = '⚡ Load: <strong>' + styShort + '</strong> (' + divShort + ' ' + genShort + ')';
            pillBtn.onclick = function() {
                loadTfaaCategory(prefix, cat.division, cat.gender, cat.style);
            };
            buttonContainer.appendChild(pillBtn);
        });

        if (tfaaMatches.length > 1) {
            const alertBox = document.createElement('div');
            alertBox.className = 'sywat-multi-style-alert';
            
            const alertTitle = document.createElement('div');
            alertTitle.className = 'sywat-multi-style-title';
            alertTitle.innerHTML = '⚠️ <strong>Archer has multiple TFAA styles on file. Click a style to load:</strong>';
            
            alertBox.appendChild(alertTitle);
            alertBox.appendChild(buttonContainer);
            pillsEl.appendChild(alertBox);
        }

        let officialName = tfaaMatches[0].full_name || tfaaMatches[0].name || '';
        let cleanTyped = typedName.trim().toLowerCase().replace(/\s+/g, ' ');
        let cleanOfficial = officialName.trim().toLowerCase().replace(/\s+/g, ' ');

        let badgeText = '';
        if (isExactMatch) {
            badgeEl.className = 'sywat-badge-verified';
            badgeText = '🟢 TFAA Validated: ' + knownStyles.join(', ');
        } else {
            badgeEl.className = 'sywat-badge-known';
            badgeText = '⚠️ TFAA Style Mismatch (TFAA Lists: ' + knownStyles.join(', ') + ')';
        }

        if (cleanTyped !== cleanOfficial && officialName !== '') {
            badgeText += '<br><span style="font-size:11px; font-weight:normal; display:inline-block; margin-top:4px;">↳ Official TFAA Name: <strong>' + officialName + '</strong></span>';
            badgeText += '<button type="button" class="sywat-btn-use-official" onclick="updateNameToOfficial(\'' + prefix + '\', \'' + officialName.replace(/'/g, "\\'") + '\')">✏️ Use Official Name</button>';
        }

        badgeEl.innerHTML = badgeText;
    }

    function autoFillOnNameChange(prefix) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        if (!nameInput) return;

        const typedName = nameInput.value;
        const tfaaMatches = findTfaaMatch(typedName);

        if (tfaaMatches && tfaaMatches.length > 0) {
            const targetMatch = tfaaMatches[0];
            const divEl   = document.getElementById(prefix + '_division');
            const genEl   = document.getElementById(prefix + '_gender');
            const styleEl = document.getElementById(prefix + '_style');

            if (divEl && targetMatch.division) matchDeskTfaaSelect(targetMatch.division, divEl);
            if (genEl && targetMatch.gender) matchDeskTfaaSelect(targetMatch.gender, genEl);
            if (styleEl && targetMatch.style) matchDeskTfaaSelect(targetMatch.style, styleEl);

            handleStyleRestrictions(prefix);
        } else if (prefix === 'walkin') {
            const shopMatch = findShopHistoryMatch(typedName);
            if (shopMatch) {
                const divEl   = document.getElementById(prefix + '_division');
                const genEl   = document.getElementById(prefix + '_gender');
                const styleEl = document.getElementById(prefix + '_style');
                const payEl   = document.getElementById(prefix + '_pay_option');

                if (divEl && shopMatch.division) matchDeskTfaaSelect(shopMatch.division, divEl);
                if (genEl && shopMatch.gender) matchDeskTfaaSelect(shopMatch.gender, genEl);
                if (styleEl && shopMatch.style) matchDeskTfaaSelect(shopMatch.style, styleEl);
                if (payEl && shopMatch.paid) payEl.value = shopMatch.paid;

                handleStyleRestrictions(prefix);
            } else {
                const divEl   = document.getElementById('walkin_division');
                const genEl   = document.getElementById('walkin_gender');
                const styleEl = document.getElementById('walkin_style');
                if (divEl) divEl.value = '';
                if (genEl) genEl.value = '';
                if (styleEl) styleEl.value = '';
                handleStyleRestrictions('walkin');
            }
        }

        evaluateTfaaStatus(prefix);
    }

    function closeWalkinModal() {
        const modal = document.getElementById('walkinModal');
        if (modal) modal.classList.remove('active');

        const nameInput = document.getElementById('walkin_name_input');
        if (nameInput) nameInput.value = '';

        const divEl = document.getElementById('walkin_division');
        if (divEl) divEl.value = '';
        const genEl = document.getElementById('walkin_gender');
        if (genEl) genEl.value = '';
        const styleEl = document.getElementById('walkin_style');
        if (styleEl) styleEl.value = '';

        const payEl = document.getElementById('walkin_pay_option');
        if (payEl) payEl.value = 'Individual';

        const famSel = document.getElementById('walkin_family_group_id');
        if (famSel) famSel.value = '';

        toggleFamilyBox('walkin');
        handleStyleRestrictions('walkin');
        evaluateTfaaStatus('walkin');
        closeAllDeskTfaaAutocompletes();
    }

    function openWalkinModal() {
        closeWalkinModal();
        const modal = document.getElementById('walkinModal');
        if (modal) modal.classList.add('active');

        const lineSel = document.getElementById('walkin_line_index');
        if (lineSel) {
            updateWalkinFamilyOptions(lineSel.value);
        }

        const walkinNameInput = document.getElementById('walkin_name_input');
        if (walkinNameInput) walkinNameInput.focus();
    }

    function toggleFamilyBox(type) {
        const payOpt = document.getElementById(type + '_pay_option');
        const box    = document.getElementById(type + '_family_box');
        if (payOpt && box) {
            box.style.display = (payOpt.value === 'Family') ? 'block' : 'none';
        }
    }

    function continueFamilyLoop(familyId, lineIdx) {
        openWalkinModal();
        
        const lineSelect = document.getElementById('walkin_line_index');
        if (lineSelect && lineIdx !== undefined) {
            lineSelect.value = lineIdx;
            updateWalkinFamilyOptions(lineIdx);
        }

        const payOptSelect = document.getElementById('walkin_pay_option');
        if (payOptSelect) {
            payOptSelect.value = 'Family';
            toggleFamilyBox('walkin');
        }

        const familySelect = document.getElementById('walkin_family_group_id');
        if (familySelect) {
            familySelect.value = familyId;
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        const walkinInput = document.getElementById('walkin_name_input');
        if (walkinInput) {
            walkinInput.addEventListener('input', function() {
                autoFillOnNameChange('walkin');
            });
        }

        const checkinArcherNameEl = document.getElementById('checkin_archer_name');
        if (checkinArcherNameEl) {
            checkinArcherNameEl.addEventListener('input', function() {
                autoFillOnNameChange('checkin');
            });
            autoFillOnNameChange('checkin');
            toggleFamilyBox('checkin');
        }

        const searchInput = document.getElementById('sywat_search_input');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('.sywat-archer-row');
                
                rows.forEach(row => {
                    const name = row.getAttribute('data-name');
                    if (name.includes(filter)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });

                const hiddenSearches = document.querySelectorAll('.sywat-hidden-search');
                hiddenSearches.forEach(hidden => {
                    hidden.value = this.value;
                });
            });
            
            searchInput.dispatchEvent(new Event('input'));
        }
    });

    document.addEventListener('input', function(e) {
        if (e.target && (e.target.id === 'walkin_name_input' || e.target.id === 'checkin_archer_name' || e.target.id === 'edit_archer_name' || e.target.name === 'edit_name' || e.target.name === 'walkin_name' || e.target.name === 'checkin_name')) {
            handleDeskTfaaAutocomplete(e.target);
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.sywat-desk-tfaa-dropdown')) {
            closeAllDeskTfaaAutocompletes();
        }
    });

    function handleStyleRestrictions(type) {
        const divisionEl = document.getElementById(type + '_division');
        const styleEl    = document.getElementById(type + '_style');
        if (!divisionEl || !styleEl) return;

        const currentDiv = divisionEl.value;

        if (!currentDiv || currentDiv === 'No Show') {
            for (let i = 0; i < styleEl.options.length; i++) styleEl.options[i].disabled = false;
            return;
        }

        for (let i = 0; i < styleEl.options.length; i++) {
            const opt = styleEl.options[i];
            const val = opt.value;
            let isDisabled = false;

            if (val.indexOf('Genesis') !== -1 && currentDiv !== 'Cub (8 - 11)') isDisabled = true;

            const isFSPro = val.indexOf('FS Pro') !== -1;
            const isFSL   = val.indexOf('FSL –') !== -1;
            const isTRAD  = val.indexOf('TRAD') !== -1 || val.toLowerCase().indexOf('traditional') !== -1;

            if (isFSPro || isFSL || isTRAD) {
                if (!['Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)'].includes(currentDiv)) isDisabled = true;
            }

            const isLB = val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1;
            if (isLB && currentDiv !== 'Adult (18 - 49)') isDisabled = true;

            opt.disabled = isDisabled;
            if (isDisabled && styleEl.value === val) styleEl.value = 'FS – Freestyle';
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        if(document.getElementById('walkin_division')) handleStyleRestrictions('walkin');
        if(document.getElementById('checkin_division')) handleStyleRestrictions('checkin');
        if(document.getElementById('edit_division')) handleStyleRestrictions('edit');
    });
    </script>
    <?php
}
