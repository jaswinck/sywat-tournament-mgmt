<?php
/**
 * Sub-Module: Check-In Desk Modals
 * Path: includes/checkin/checkin-modals.php
 * Author: SYWAT Archery
 * Version: 6.3.2
 */

if (!defined('ABSPATH')) exit;

/**
 * Render modal popups for Check-In, Edit Archer, and Add Walk-In.
 *
 * @param array $data Aggregated Check-In Desk dataset.
 * @return void
 */
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
                <form method="post">
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

                    <div class="sywat-field-group sywat-field-relative">
                        <label>Name:</label>
                        <input type="text" name="checkin_name" id="checkin_archer_name" value="<?php echo esc_attr($modal_archer['name']); ?>" autocomplete="off" class="sywat-input-bold">
                        <div id="checkin_tfaa_badge"></div>
                        <div id="checkin_tfaa_pills" class="sywat-pill-box"></div>
                    </div>

                    <?php if ($modal_due > 0): ?>
                        <div id="sywat_unpaid_warning_banner" class="sywat-alert sywat-alert-danger sywat-alert-center">
                            <div class="sywat-alert-title">
                                ⚠️ <?php echo $is_family ? 'UNPAID FAMILY BALANCE' : 'UNPAID BALANCE'; ?>: $<?php echo number_format($display_due, 2); ?>
                            </div>
                            <div class="sywat-alert-subtext">
                                <?php if ($is_family): ?>
                                    Family Cap Group Outstanding Balance. Collect Payment Onsite.
                                <?php else: ?>
                                    Please See Event Staff for Payment
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="sywat-field-group">
                            <label class="sywat-checkbox-label-danger">
                                <input type="checkbox" name="checkin_payment_verified" id="sywat_payment_verified_check" value="1" required class="sywat-checkbox-auto" onchange="toggleUnpaidBanner(this.checked)">
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

                    <div class="sywat-field-group <?php echo ($curr_pay_opt === 'Family') ? '' : 'sywat-hidden'; ?>" id="checkin_family_box">
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

                    <div class="sywat-modal-form-actions">
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
                <form method="post">
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

                    <div class="sywat-field-group sywat-field-relative">
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

                    <div class="sywat-field-group <?php echo ($curr_pay_opt === 'Family') ? '' : 'sywat-hidden'; ?>" id="edit_family_box">
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

                    <div class="sywat-modal-form-actions-split">
                        <button type="submit" name="sywat_action" value="delete_archer" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" onclick="return confirm('⚠️ Are you sure you want to PERMANENTLY delete this archer?');" formnovalidate>Delete</button>
                        
                        <div class="sywat-modal-form-actions-right">
                            <button type="submit" name="sywat_action" value="cancel" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" formnovalidate>Cancel</button>
                            <button type="submit" name="sywat_action" value="save_edit" class="sywat-btn sywat-btn-primary sywat-btn-icon-save">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ADD WALK-IN MODAL (STANDARDIZED SYWAT TABLE & HEADER LAYOUT) -->
    <div id="walkinModal" class="sywat-modal-backdrop">
        <div class="sywat-modal-content sywat-modal-xl">
            <div class="sywat-modal-header no-print">
                <div>
                    <h3>📋 Add Walk-In Registration</h3>
                    <div class="sywat-subtitle">Register walk-in archers, assign shooting lines, and calculate fees on-site.</div>
                </div>
                <button type="button" class="sywat-modal-close" onclick="closeWalkinModal()">&times;</button>
            </div>
            <div class="sywat-modal-body">
                <form method="post" id="sywat_walkin_form">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">

                    <!-- Shared Group Header Controls -->
                    <div class="sywat-intake-group-wrapper">
                        <div class="sywat-form-grid-4">
                            <div class="sywat-field-group">
                                <label for="walkin_group_name">Primary Contact:</label>
                                <input type="text" name="walkin_group_name" id="walkin_group_name" placeholder="e.g. John Smith" autocomplete="off" oninput="if(window.handleWalkinGroupInput) window.handleWalkinGroupInput(this);">
                            </div>

                            <div class="sywat-field-group">
                                <label for="walkin_group_phone">Phone Number:</label>
                                <input type="text" name="walkin_group_phone" id="walkin_group_phone" placeholder="XXX-XXX-XXXX" autocomplete="off" onkeyup="if(window.formatPhoneNumber) window.formatPhoneNumber(this)">
                            </div>

                            <div class="sywat-field-group">
                                <label for="walkin_line_index">Default Shooting Line:</label>
                                <select name="walkin_line_index" id="walkin_line_index" required onchange="if(window.updateAllWalkinArcherLines) window.updateAllWalkinArcherLines(this.value)">
                                    <?php foreach ($lines as $idx => $line): ?>
                                        <?php $is_c = in_array((string)$idx, $closed_lines); ?>
                                        <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$selected_line_idx, (string)$idx); ?><?php echo $is_c ? ' disabled' : ''; ?>>
                                            <?php echo esc_html($line['name']) . ($is_c ? ' (Closed)' : ''); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="walkin_pay_option">Paid Option:</label>
                                <select name="walkin_pay_option" id="walkin_pay_option" required onchange="if(window.recalculateWalkinTotal) window.recalculateWalkinTotal()">
                                    <option value="Individual">Individual Rate ($25 / $30)</option>
                                    <option value="Family">Family Group ($75 Cap)</option>
                                    <option value="Field Governor">Field Governor (Free)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Dynamic Multi-Archer Intake Table Section -->
                    <div class="sywat-intake-card">
                        <div class="sywat-table-header-bar">
                            <h2>👥 Walk-In Archer Roster</h2>
                            <div class="sywat-table-header-controls">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="if(window.addWalkinArcherRow) window.addWalkinArcherRow();">Add Archer</button>
                            </div>
                        </div>

                        <div class="sywat-table-responsive">
                            <table class="sywat-table" id="sywat_walkin_table">
                                <thead>
                                    <tr>
                                        <th style="width: 4%;">#</th>
                                        <th style="width: 26%;">Archer Full Name *</th>
                                        <th style="width: 20%;">Division *</th>
                                        <th style="width: 14%;">Gender *</th>
                                        <th style="width: 18%;">Style *</th>
                                        <th style="width: 14%;">Shooting Line</th>
                                        <th style="width: 4%; text-align: center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="sywat_walkin_archer_rows_container">
                                    <!-- Initial Archer Row 0 -->
                                    <tr class="sywat-walkin-archer-row" data-row-idx="0">
                                        <td><strong>1</strong></td>
                                        <td>
                                            <div class="sywat-field-group sywat-field-relative">
                                                <input type="text" name="archers[0][name]" class="sywat-archer-name-input walkin-archer-name" placeholder="Full Name *" autocomplete="off" required oninput="if(window.handleTfaaAutocomplete) window.handleTfaaAutocomplete(this); if(window.recalculateWalkinTotal) window.recalculateWalkinTotal();">
                                                <div class="sywat-tfaa-autocomplete-dropdown sywat-hidden"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <select name="archers[0][division]" class="sywat-division-select walkin-archer-div" required onchange="if(window.handleDivisionChange) window.handleDivisionChange(this); if(window.recalculateWalkinTotal) window.recalculateWalkinTotal();">
                                                <option value="" disabled selected hidden>Select Division</option>
                                                <?php foreach ($divs as $d): ?>
                                                    <?php if ($d === 'No Show') continue; ?>
                                                    <option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="archers[0][gender]" class="sywat-gender-select walkin-archer-gender" required onchange="if(window.recalculateWalkinTotal) window.recalculateWalkinTotal();">
                                                <option value="" disabled selected hidden>Gender</option>
                                                <?php foreach ($genders as $g): ?>
                                                    <?php if ($g === 'No Show') continue; ?>
                                                    <option value="<?php echo esc_attr($g); ?>"><?php echo esc_html($g); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="archers[0][style]" class="sywat-style-select walkin-archer-style" required onchange="if(window.recalculateWalkinTotal) window.recalculateWalkinTotal();">
                                                <option value="" disabled selected hidden>Select Style</option>
                                                <?php foreach ($sywat_styles as $s): ?>
                                                    <?php if ($s === 'No Show') continue; ?>
                                                    <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="archers[0][line_idx]" class="sywat-line-override-select walkin-line-select" onchange="if(window.recalculateWalkinTotal) window.recalculateWalkinTotal();">
                                                <?php foreach ($lines as $idx => $line): ?>
                                                    <?php $is_c = in_array((string)$idx, $closed_lines); ?>
                                                    <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$selected_line_idx, (string)$idx); ?><?php echo $is_c ? ' disabled' : ''; ?>>
                                                        <?php echo esc_html($line['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td style="text-align: center;">
                                            <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" onclick="if(window.removeWalkinArcherRow) window.removeWalkinArcherRow(this);" title="Remove Archer"></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Intake Footer & Totals Summary Bar -->
                        <div class="sywat-intake-footer-bar">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="if(window.addWalkinArcherRow) window.addWalkinArcherRow();">Add Archer</button>
                            <div class="sywat-intake-summary">
                                <span class="sywat-intake-total-text">Total Walk-In Due: <span id="sywat_walkin_total_disp" class="sywat-intake-total-val">$0.00</span></span>
                                <span id="sywat_walkin_cap_badge" class="sywat-family-badge sywat-hidden">🟢 Family Cap Applied</span>
                            </div>
                        </div>
                    </div>

                    <div class="sywat-modal-form-actions">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeWalkinModal()">Cancel</button>
                        <button type="submit" name="sywat_action" value="add_walkin" class="sywat-btn sywat-btn-primary sywat-btn-icon-checkin">Register & Check In</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}
