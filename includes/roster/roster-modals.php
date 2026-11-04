<?php
/**
 * Sub-Module: Archer Roster Modal Views
 * Path: includes/roster/roster-modals.php
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render Edit Archer Registration Popup Modal Component
 */
if ( ! function_exists( 'sywat_render_edit_archer_modal' ) ) {
    function sywat_render_edit_archer_modal( $target_archer, $selected_tournament, $available_lines, $existing_families_by_line ) {
        $open_edit_modal = ! empty( $target_archer );

        $divs = array(
            'Cub (8 - 11)',
            'Youth (12 - 14)',
            'Young Adult (15 - 17)',
            'Adult (18 - 49)',
            'Senior (50 - 59)',
            'Silver Senior (60 - 69)',
            'Master Senior (70+)'
        );

        $genders = array( 'Male', 'Female' );

        $sywat_styles = array(
            'FS – Freestyle',
            'FS Pro – Freestyle Pro',
            'FSL – Freestyle Limited',
            'FSLR – Freestyle Limited Recurve (or Olympic Recurve)',
            'BHFS – Bowhunter Freestyle',
            'MBH – Modern Bowhunter',
            'BB Barebow',
            'BBC – Barebow Compound',
            'BBR – Barebow Recurve',
            'TRAD – Traditional',
            'LB – Longbow',
            'Genesis'
        );
        ?>
        <div class="sywat-modal-backdrop sywat-nested-modal <?php echo $open_edit_modal ? 'active' : ''; ?>" id="sywat_edit_archer_modal">
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header">
                    <div>
                        <h3>✏️ Edit Archer Registration</h3>
                        <div class="sywat-subtitle">Update division, shooting line, or payment category details.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" onclick="closeEditArcherModal()">&times;</button>
                </div>
                <?php if ( $target_archer ): ?>
                    <?php 
                        $curr_line = isset( $target_archer['line_index'] ) ? $target_archer['line_index'] : '0'; 
                        $curr_fam_id = isset( $target_archer['family_id'] ) ? $target_archer['family_id'] : '';
                        $modal_line_families = isset( $existing_families_by_line[$curr_line] ) ? $existing_families_by_line[$curr_line] : array();
                        $curr_paid = isset( $target_archer['paid'] ) ? $target_archer['paid'] : '';
                        $curr_pay_opt = ( $curr_paid === 'Family' ) ? 'Family' : (( $curr_paid === 'Field Governor' ) ? 'Field Governor' : 'Individual');
                        $curr_div = isset( $target_archer['division'] ) ? $target_archer['division'] : 'Adult (18 - 49)';
                        $curr_gen = isset( $target_archer['gender'] ) ? $target_archer['gender'] : 'Male';
                        $curr_sty = isset( $target_archer['style'] ) ? $target_archer['style'] : '';
                    ?>
                    <form method="post" action="?tab=manage&view_tournament=<?php echo urlencode( $selected_tournament ); ?>&modal_action=manage">
                        <div class="sywat-modal-body">
                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                            <input type="hidden" name="sywat_action" value="save_edit">
                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                            <input type="hidden" name="modal_action" value="manage">
                            <input type="hidden" name="registration_id" value="<?php echo esc_attr( $target_archer['id'] ); ?>">

                            <div class="sywat-form-grid-2">
                                <div class="sywat-field-group sywat-full-width">
                                    <label for="edit_name">Full Name</label>
                                    <input type="text" id="edit_name" name="edit_name" value="<?php echo esc_attr( $target_archer['name'] ); ?>" required>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="edit_line_index">Shooting Line</label>
                                    <select name="edit_line_index" id="edit_line_index" required onchange="updateRosterEditFamilyOptions(this.value)">
                                        <?php foreach ( $available_lines as $idx => $line ): ?>
                                            <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$curr_line, (string)$idx ); ?>>
                                                <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ($idx + 1) ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="edit_division">Division</label>
                                    <select name="edit_division" id="edit_division" required onchange="handleDivisionChange('edit');">
                                        <?php foreach ( $divs as $d ): ?>
                                            <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $curr_div, $d ); ?>>
                                                <?php echo esc_html( $d ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="edit_gender">Gender</label>
                                    <select name="edit_gender" id="edit_gender" required>
                                        <?php foreach ( $genders as $g ): ?>
                                            <option value="<?php echo esc_attr( $g ); ?>" <?php selected( $curr_gen, $g ); ?>>
                                                <?php echo esc_html( $g ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="edit_style">Style</label>
                                    <select name="edit_style" id="edit_style" required>
                                        <?php foreach ( $sywat_styles as $s ): ?>
                                            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $curr_sty, $s ); ?>>
                                                <?php echo esc_html( $s ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="edit_pay_option">Paid Option</label>
                                    <select name="edit_pay_option" id="edit_pay_option" required onchange="updatePaidStatusDisplay('edit');">
                                        <option value="Individual" <?php selected( $curr_pay_opt, 'Individual' ); ?>>Individual</option>
                                        <option value="Family" <?php selected( $curr_pay_opt, 'Family' ); ?>>Family</option>
                                        <option value="Field Governor" <?php selected( $curr_pay_opt, 'Field Governor' ); ?>>Field Governor</option>
                                    </select>
                                </div>

                                <div class="sywat-field-group" id="edit_family_group_box" <?php if ( $curr_pay_opt !== 'Family' ) echo 'style="display:none;"'; ?>>
                                    <label for="edit_family_group_id">Assign to Line Family Group</label>
                                    <select name="edit_family_group_id" id="edit_family_group_id">
                                        <option value="">[ + Start New Family Group for This Line ]</option>
                                        <?php foreach ( $modal_line_families as $fid => $flabel ): ?>
                                            <option value="<?php echo esc_attr( $fid ); ?>" <?php selected( $curr_fam_id, $fid ); ?>>
                                                <?php echo esc_html( $flabel ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="edit_balance_due">Balance Due ($)</label>
                                    <input type="number" step="0.01" min="0" id="edit_balance_due" name="edit_balance_due" value="<?php echo esc_attr( isset( $target_archer['balance_due'] ) ? $target_archer['balance_due'] : 0 ); ?>">
                                </div>

                                <div class="sywat-field-group">
                                    <label for="edit_distance_display">Distance (Calculated)</label>
                                    <input type="text" id="edit_distance_display" class="sywat-input-disabled-calc" value="<?php echo ($curr_div === 'Cub (8 - 11)') ? 'Cub' : '20yd'; ?>" disabled>
                                </div>
                            </div>

                            <div class="sywat-calculated-status-box">
                                <span class="sywat-calculated-status-label">Calculated Paid Status:</span>
                                <span id="edit_paid_status_display" class="sywat-calculated-status-value"><?php echo esc_html( $curr_paid ?: 'Adult' ); ?></span>
                            </div>
                        </div>

                        <div class="sywat-modal-footer">
                            <button type="button" class="sywat-btn sywat-btn-secondary" onclick="closeEditArcherModal()">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success">Save Changes</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
