<?php
/**
 * Sub-Module: Member & Board Management Portal Modals
 * Path: includes/members/members-modals.php
 * Description: Renders modal overlay templates for quick member account creation, household profile updates, dependent management, Gender and Pro selection, board position assignments with single-action automated role binding compatibility, batch spreadsheet upload, 2-step audit preview overlays, protected test data purge overlays, live unassigned archer autocomplete search fields, dynamic target Head of Household autocomplete search fields, in-form governance card standalone account conversion drawer with standardized button weight, bold compact metadata subtext typography, administrator-gated system role grids and 4-column district-specific board position assignment grids, and native button controls for client-side modal state management.
 * Compliance: Zero inline styles, zero embedded scripts. JS logic isolated to assets/js/sywat-members.js. Delegates bulk database pre-fetching to members-data.php.
 * Author: SYWAT Archery
 * Version: 6.1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render all Member and Board management modal overlays.
 *
 * @param array $data Data payload passed from controller containing member lists, board roles, and edit objects.
 */
function sywat_members_render_modals( $data ) {
    $all_members         = isset( $data['all_members'] ) ? $data['all_members'] : array();
    $all_board_positions = isset( $data['all_board_positions'] ) ? $data['all_board_positions'] : array();
    $edit_user_obj       = isset( $data['edit_user_obj'] ) ? $data['edit_user_obj'] : null;
    $edit_dep_subprofile = isset( $data['edit_dep_subprofile'] ) ? $data['edit_dep_subprofile'] : null;
    $edit_board_user_obj = isset( $data['edit_board_user_obj'] ) ? $data['edit_board_user_obj'] : null;
    $excluded_roles      = isset( $data['excluded_roles'] ) ? $data['excluded_roles'] : array();

    $open_edit_board_modal     = ( $edit_board_user_obj !== null );
    $open_edit_dependent_modal = ( $edit_dep_subprofile !== null );
    $all_shops                 = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
    $us_states                 = function_exists( 'sywat_get_us_states_list' ) ? sywat_get_us_states_list() : array();
    $accom_options             = function_exists( 'sywat_get_accommodations_list' ) ? sywat_get_accommodations_list() : array();
    $gender_options            = function_exists( 'sywat_get_gender_options' ) ? sywat_get_gender_options() : array( 'unassigned' => '-- Select Gender --', 'male' => 'Male', 'female' => 'Female' );

    global $wp_roles;
    $editable_roles = $wp_roles ? $wp_roles->roles : array();

    $board_officer_roles = array(
        'tfaa_president', 'tfaa_vice_president', 'tfaa_secretary', 
        'tfaa_treasurer', 'tfaa_social_media_director', 'tfaa_webmaster', 
        'tfaa_tournament_director', 'tfaa_field_governor'
    );

    // Primary User Data Unpacking (if active)
    $e_phone      = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_phone', true ) : '';
    $e_gender     = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_gender', true ) : 'unassigned';
    $e_is_pro     = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_is_pro', true ) : 'no';
    $e_dob        = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_dob', true ) : '';
    $e_accom      = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_accommodations', true ) : 'none';
    $e_nfaa_id    = $edit_user_obj && function_exists( 'sywat_get_user_nfaa_id' ) ? sywat_get_user_nfaa_id( $edit_user_obj->ID ) : '';
    $e_expiration = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_nfaa_expiration', true ) : '';
    $e_address_1  = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_address_1', true ) : '';
    $e_address_2  = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_address_2', true ) : '';
    $e_city       = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_city', true ) : '';
    $e_state      = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_state', true ) : '';
    $e_zip        = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_zip', true ) : '';
    $e_shop_id    = $edit_user_obj ? get_user_meta( $edit_user_obj->ID, 'sywat_shop_id', true ) : '';
    $c_roles      = $edit_user_obj ? (array) $edit_user_obj->roles : array();
    $e_age_div    = ! empty( $e_dob ) && function_exists( 'sywat_get_eligible_age_division' ) ? sywat_get_eligible_age_division( $e_dob ) : 'Adult';
    ?>

    <!-- MODAL 1: ADD NEW MEMBER MODAL -->
    <div class="sywat-modal-backdrop" id="sywat_add_member_modal">
        <div class="sywat-modal-content sywat-modal-lg">
            <div class="sywat-modal-header no-print">
                <h3>Create New Primary Member Account</h3>
                <button type="button" class="sywat-modal-close" onclick="sywatCloseAddMemberModal()">&times;</button>
            </div>
            <div class="sywat-modal-body">
                <form method="post" action="?tab=members&subtab=directory" id="sywat_add_member_form">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_action" value="create_member">

                    <div class="sywat-alert sywat-alert-success sywat-notice-banner">
                        🆔 <strong>TFAA Member ID &amp; Username:</strong> Auto-formatted as <code>Firstname.Lastname</code>; ID generated sequentially.
                    </div>

                    <div class="sywat-form-grid-2">
                        <div class="sywat-field-group">
                            <label>First Name *</label>
                            <input type="text" name="first_name" required>
                        </div>
                        <div class="sywat-field-group">
                            <label>Last Name *</label>
                            <input type="text" name="last_name" required>
                        </div>
                    </div>

                    <div class="sywat-form-grid-2 sywat-mt-3">
                        <div class="sywat-field-group">
                            <label>Email Address *</label>
                            <input type="email" name="email" required>
                        </div>
                        <div class="sywat-field-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" oninput="sywatFormatPhoneInput(this)" placeholder="XXX-XXX-XXXX" maxlength="12">
                        </div>
                    </div>

                    <div class="sywat-form-grid-3 sywat-mt-3">
                        <div class="sywat-field-group">
                            <label>Gender *</label>
                            <select name="gender" required>
                                <?php foreach ( $gender_options as $g_key => $g_label ): ?>
                                    <option value="<?php echo esc_attr( $g_key ); ?>" <?php selected( $g_key, 'unassigned' ); ?>><?php echo esc_html( $g_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sywat-field-group">
                            <label>Pro Archer Status</label>
                            <select name="is_pro">
                                <option value="no" selected>No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>
                        <div class="sywat-field-group">
                            <label>Date of Birth * (For Age Divisions)</label>
                            <input type="date" name="dob" required>
                        </div>
                    </div>

                    <div class="sywat-form-grid-2 sywat-mt-3">
                        <div class="sywat-field-group">
                            <label>Accommodations Needed</label>
                            <select name="accommodations">
                                <?php foreach ( $accom_options as $a_key => $a_label ): ?>
                                    <option value="<?php echo esc_attr( $a_key ); ?>"><?php echo esc_html( $a_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sywat-field-group">
                            <label>NFAA Membership Expiration Date</label>
                            <input type="date" name="nfaa_expiration">
                        </div>
                    </div>

                    <div class="sywat-form-grid-2 sywat-mt-3">
                        <div class="sywat-field-group">
                            <label>Street Address Line 1</label>
                            <input type="text" name="address_1" placeholder="123 Main St">
                        </div>
                        <div class="sywat-field-group">
                            <label>Street Address Line 2</label>
                            <input type="text" name="address_2" placeholder="Apt / Suite #">
                        </div>
                    </div>

                    <div class="sywat-form-grid-3 sywat-mt-3">
                        <div class="sywat-field-group">
                            <label>City</label>
                            <input type="text" name="city">
                        </div>
                        <div class="sywat-field-group">
                            <label>State</label>
                            <select name="state">
                                <option value="">-- Select State --</option>
                                <?php foreach ( $us_states as $s_code => $s_label ): ?>
                                    <option value="<?php echo esc_attr( $s_code ); ?>"><?php echo esc_html( $s_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sywat-field-group">
                            <label>Zip Code</label>
                            <input type="text" name="zip" maxlength="10">
                        </div>
                    </div>

                    <!-- ASSIGNED SYSTEM ROLES GRID -->
                    <div class="sywat-field-group sywat-mt-4">
                        <label>Assigned System Access Role(s) *</label>
                        <div class="sywat-meta-subtext sywat-mb-2">Note: Select base system login capabilities for this account.</div>
                        <div class="sywat-form-grid-4 sywat-role-grid">
                            <?php foreach ( $editable_roles as $r_key => $r_details ): ?>
                                <?php 
                                    $r_clean = strtolower( $r_key );
                                    if ( in_array( $r_clean, $excluded_roles, true ) || in_array( $r_clean, $board_officer_roles, true ) ) continue; 
                                ?>
                                <label class="sywat-checkbox-label">
                                    <input type="checkbox" name="sywat_roles[]" value="<?php echo esc_attr( $r_key ); ?>" <?php checked( $r_key, 'tfaa_member' ); ?> onchange="sywatToggleShopVisibility('sywat_add_member_form', 'sywat_add_shop_container')">
                                    <?php echo esc_html( $r_details['name'] ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ASSIGNED BOARD POSITIONS GRID -->
                    <div class="sywat-field-group sywat-mt-3">
                        <label>Assigned Board Position(s)</label>
                        <div class="sywat-meta-subtext sywat-mb-2">Note: Select official TFAA Board of Directors position(s) if applicable.</div>
                        <div class="sywat-form-grid-4 sywat-role-grid">
                            <?php foreach ( $all_board_positions as $pos_key => $pos_title ): ?>
                                <label class="sywat-checkbox-label">
                                    <input type="checkbox" name="board_positions[]" value="<?php echo esc_attr( $pos_key ); ?>">
                                    <?php echo esc_html( $pos_title ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="sywat_add_shop_container" class="sywat-field-group sywat-mt-3 sywat-hidden">
                        <label>Assigned Host Shop / Club</label>
                        <select name="sywat_shop_id">
                            <option value="" selected>-- None / Unassigned --</option>
                            <?php foreach ( $all_shops as $s_id => $s_item ): ?>
                                <option value="<?php echo esc_attr( $s_id ); ?>">
                                    <?php echo esc_html( isset( $s_item['name'] ) ? $s_item['name'] : $s_id ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-form-grid-2 sywat-mt-3">
                        <div class="sywat-field-group">
                            <label>Initial Password *</label>
                            <input type="password" id="sywat_add_pw" name="password" required oninput="sywatValidatePasswordRules('sywat_add_pw', 'sywat_add_pw_confirm', 'add_rule')">
                        </div>
                        <div class="sywat-field-group">
                            <label>Confirm Password *</label>
                            <input type="password" id="sywat_add_pw_confirm" name="confirm_password" required oninput="sywatValidatePasswordRules('sywat_add_pw', 'sywat_add_pw_confirm', 'add_rule')">
                        </div>
                    </div>

                    <div class="sywat-password-rules-box">
                        <div class="sywat-password-rules-bar">
                            <span class="sywat-password-rules-label">Rules:</span>
                            <span id="add_rule_len" class="sywat-rule-badge">🔴 8+ Chars</span>
                            <span id="add_rule_upper" class="sywat-rule-badge">🔴 1 Upper</span>
                            <span id="add_rule_lower" class="sywat-rule-badge">🔴 1 Lower</span>
                            <span id="add_rule_num" class="sywat-rule-badge">🔴 1 Num</span>
                            <span id="add_rule_match" class="sywat-rule-badge">🔴 Passwords Match</span>
                        </div>
                    </div>

                    <div class="sywat-modal-actions">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseAddMemberModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Member Profile</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 2: EDIT HEAD OF HOUSEHOLD PRIMARY PROFILE -->
    <?php if ( $edit_user_obj ): ?>
        <?php 
            $cur_positions = get_user_meta( $edit_user_obj->ID, 'sywat_board_positions', true );
            if ( ! is_array( $cur_positions ) ) $cur_positions = array();
        ?>
        <div class="sywat-modal-backdrop" id="sywat_edit_primary_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <h3>Edit Head of Household: <?php echo esc_html( $edit_user_obj->display_name ); ?></h3>
                    <button type="button" class="sywat-modal-close" onclick="sywatCloseEditPrimaryModal()">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <form method="post" action="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>" id="sywat_edit_primary_form">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="update_member">
                        <input type="hidden" name="target_user_id" value="<?php echo $edit_user_obj->ID; ?>">

                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label>First Name *</label>
                                <input type="text" name="first_name" value="<?php echo esc_attr( $edit_user_obj->first_name ); ?>" required>
                            </div>
                            <div class="sywat-field-group">
                                <label>Last Name *</label>
                                <input type="text" name="last_name" value="<?php echo esc_attr( $edit_user_obj->last_name ); ?>" required>
                            </div>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Email Address *</label>
                                <input type="email" name="email" value="<?php echo esc_attr( $edit_user_obj->user_email ); ?>" required>
                            </div>
                            <div class="sywat-field-group">
                                <label>Phone Number</label>
                                <input type="text" name="phone" value="<?php echo esc_attr( $e_phone ); ?>" oninput="sywatFormatPhoneInput(this)" placeholder="XXX-XXX-XXXX" maxlength="12">
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Gender *</label>
                                <select name="gender" required>
                                    <?php foreach ( $gender_options as $g_key => $g_label ): ?>
                                        <option value="<?php echo esc_attr( $g_key ); ?>" <?php selected( strtolower( $e_gender ), $g_key ); ?>><?php echo esc_html( $g_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Pro Archer Status</label>
                                <select name="is_pro">
                                    <option value="no" <?php selected( $e_is_pro, 'no' ); ?>>No</option>
                                    <option value="yes" <?php selected( $e_is_pro, 'yes' ); ?>>Yes</option>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Date of Birth (Division: <strong><?php echo esc_html( $e_age_div ); ?></strong>)</label>
                                <input type="date" name="dob" value="<?php echo esc_attr( $e_dob ); ?>">
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Accommodations Needed</label>
                                <select name="accommodations">
                                    <?php foreach ( $accom_options as $a_key => $a_label ): ?>
                                        <option value="<?php echo esc_attr( $a_key ); ?>" <?php selected( $e_accom, $a_key ); ?>><?php echo esc_html( $a_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>NFAA Membership ID</label>
                                <input type="text" name="nfaa_number" value="<?php echo esc_attr( $e_nfaa_id !== 'Unassigned' ? $e_nfaa_id : '' ); ?>" placeholder="e.g. 123456">
                            </div>
                            <div class="sywat-field-group">
                                <label>NFAA Membership Expiration Date</label>
                                <input type="date" name="nfaa_expiration" value="<?php echo esc_attr( $e_expiration ); ?>">
                            </div>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Street Address Line 1</label>
                                <input type="text" name="address_1" value="<?php echo esc_attr( $e_address_1 ); ?>" placeholder="123 Main St">
                            </div>
                            <div class="sywat-field-group">
                                <label>Street Address Line 2</label>
                                <input type="text" name="address_2" value="<?php echo esc_attr( $e_address_2 ); ?>" placeholder="Apt / Suite #">
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>City</label>
                                <input type="text" name="city" value="<?php echo esc_attr( $e_city ); ?>">
                            </div>
                            <div class="sywat-field-group">
                                <label>State</label>
                                <select name="state">
                                    <option value="">-- Select State --</option>
                                    <?php foreach ( $us_states as $s_code => $s_label ): ?>
                                        <option value="<?php echo esc_attr( $s_code ); ?>" <?php selected( strtoupper( $e_state ), $s_code ); ?>><?php echo esc_html( $s_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Zip Code</label>
                                <input type="text" name="zip" value="<?php echo esc_attr( $e_zip ); ?>" maxlength="10">
                            </div>
                        </div>

                        <?php if ( current_user_can( 'administrator' ) ): ?>
                            <!-- ASSIGNED SYSTEM ROLES GRID -->
                            <div class="sywat-field-group sywat-mt-4">
                                <label>Assigned System Access Role(s) *</label>
                                <div class="sywat-meta-subtext sywat-mb-2">Note: Select base system login capabilities for this account.</div>
                                <div class="sywat-form-grid-4 sywat-role-grid">
                                    <?php foreach ( $editable_roles as $r_key => $r_details ): ?>
                                        <?php 
                                            $r_clean = strtolower( $r_key );
                                            if ( in_array( $r_clean, $excluded_roles, true ) || in_array( $r_clean, $board_officer_roles, true ) ) continue; 
                                        ?>
                                        <label class="sywat-checkbox-label">
                                            <input type="checkbox" name="sywat_roles[]" value="<?php echo esc_attr( $r_key ); ?>" <?php checked( in_array( $r_key, $c_roles, true ), true ); ?> onchange="sywatToggleShopVisibility('sywat_edit_primary_form', 'sywat_edit_primary_shop_container')">
                                            <?php echo esc_html( $r_details['name'] ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- ASSIGNED BOARD POSITIONS GRID -->
                            <div class="sywat-field-group sywat-mt-3">
                                <label>Assigned Board Position(s)</label>
                                <div class="sywat-meta-subtext sywat-mb-2">Note: Select official TFAA Board of Directors position(s) assigned to this member.</div>
                                <div class="sywat-form-grid-4 sywat-role-grid">
                                    <?php foreach ( $all_board_positions as $pos_key => $pos_title ): ?>
                                        <label class="sywat-checkbox-label">
                                            <input type="checkbox" name="board_positions[]" value="<?php echo esc_attr( $pos_key ); ?>" <?php checked( in_array( $pos_key, $cur_positions, true ), true ); ?>>
                                            <?php echo esc_html( $pos_title ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div id="sywat_edit_primary_shop_container" class="sywat-field-group sywat-mt-3 sywat-hidden">
                                <label>Assigned Host Shop / Range</label>
                                <select name="sywat_shop_id">
                                    <option value="" <?php selected( empty( $e_shop_id ), true ); ?>>-- None / Unassigned --</option>
                                    <?php foreach ( $all_shops as $s_id => $s_item ): ?>
                                        <option value="<?php echo esc_attr( $s_id ); ?>" <?php selected( $e_shop_id, $s_id ); ?>>
                                            <?php echo esc_html( isset( $s_item['name'] ) ? $s_item['name'] : $s_id ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Reset Password (Optional)</label>
                                <input type="password" id="sywat_edit_prim_pw" name="new_password" placeholder="Leave blank to keep current..." oninput="sywatValidatePasswordRules('sywat_edit_prim_pw', 'sywat_edit_prim_pw_confirm', 'edit_prim_rule')">
                            </div>
                            <div class="sywat-field-group">
                                <label>Confirm New Password</label>
                                <input type="password" id="sywat_edit_prim_pw_confirm" name="confirm_new_password" placeholder="Confirm new password..." oninput="sywatValidatePasswordRules('sywat_edit_prim_pw', 'sywat_edit_prim_pw_confirm', 'edit_prim_rule')">
                            </div>
                        </div>

                        <div class="sywat-password-rules-box">
                            <div class="sywat-password-rules-bar">
                                <span class="sywat-password-rules-label">Rules:</span>
                                <span id="edit_prim_rule_len" class="sywat-rule-badge">⚪ 8+ Chars</span>
                                <span id="edit_prim_rule_upper" class="sywat-rule-badge">⚪ 1 Upper</span>
                                <span id="edit_prim_rule_lower" class="sywat-rule-badge">⚪ 1 Lower</span>
                                <span id="edit_prim_rule_num" class="sywat-rule-badge">⚪ 1 Num</span>
                                <span id="edit_prim_rule_match" class="sywat-rule-badge">⚪ Passwords Match</span>
                            </div>
                        </div>

                        <div class="sywat-modal-actions sywat-mt-4">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseEditPrimaryModal()">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Master Profile</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODAL 3: ADD FAMILY MEMBER SUB-PROFILE TO HOUSEHOLD -->
        <div class="sywat-modal-backdrop" id="sywat_add_dependent_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <h3>➕ Add Family Member to Household</h3>
                    <button type="button" class="sywat-modal-close" onclick="sywatCloseAddDependentModal()">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <form method="post" action="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>" id="sywat_add_dependent_form">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="create_member">
                        <input type="hidden" name="primary_user_id" value="<?php echo $edit_user_obj->ID; ?>">

                        <div class="sywat-alert sywat-alert-success sywat-notice-banner">
                            🏠 Sub-profile linked directly under Head of Household <strong><?php echo esc_html( $edit_user_obj->display_name ); ?></strong>. Zero password rules or WP login accounts required.
                        </div>

                        <!-- LIVE ARCHER AUTOCOMPLETE SEARCH BOX -->
                        <div class="sywat-field-group sywat-mt-2 sywat-mb-3 sywat-autocomplete-wrapper">
                            <label>🔍 Search Existing Unassigned Archer (Type Name or NFAA ID)</label>
                            <input type="text" id="sywat_add_dep_autocomplete" placeholder="Start typing name to search unassigned pool..." autocomplete="off" oninput="sywatHandleArcherSearch(this)">
                            <div id="sywat_add_dep_autocomplete_results" class="sywat-autocomplete-results sywat-hidden"></div>
                        </div>

                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label>First Name *</label>
                                <input type="text" name="first_name" id="add_dep_first_name" required placeholder="e.g. Timmy">
                            </div>
                            <div class="sywat-field-group">
                                <label>Last Name *</label>
                                <input type="text" name="last_name" id="add_dep_last_name" value="<?php echo esc_attr( $edit_user_obj->last_name ); ?>" required>
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Gender *</label>
                                <select name="gender" id="add_dep_gender" required>
                                    <?php foreach ( $gender_options as $g_key => $g_label ): ?>
                                        <option value="<?php echo esc_attr( $g_key ); ?>" <?php selected( $g_key, 'unassigned' ); ?>><?php echo esc_html( $g_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Pro Archer Status</label>
                                <select name="is_pro" id="add_dep_is_pro">
                                    <option value="no" selected>No</option>
                                    <option value="yes">Yes</option>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Date of Birth * (For Age Divisions)</label>
                                <input type="date" name="dob" id="add_dep_dob" required>
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Accommodations Needed</label>
                                <select name="accommodations" id="add_dep_accommodations">
                                    <?php foreach ( $accom_options as $a_key => $a_label ): ?>
                                        <option value="<?php echo esc_attr( $a_key ); ?>"><?php echo esc_html( $a_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>NFAA Membership ID (Optional)</label>
                                <input type="text" name="nfaa_number" id="add_dep_nfaa_number" placeholder="e.g. 123456">
                            </div>
                            <div class="sywat-field-group">
                                <label>NFAA Expiration Date (Optional)</label>
                                <input type="date" name="nfaa_expiration" id="add_dep_nfaa_expiration" value="<?php echo esc_attr( $e_expiration ); ?>">
                            </div>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Contact Email Address (Defaults to Primary Email)</label>
                                <input type="email" name="email" value="<?php echo esc_attr( $edit_user_obj->user_email ); ?>" required>
                            </div>
                            <div class="sywat-field-group">
                                <label>Contact Phone Number (Defaults to Primary Phone)</label>
                                <input type="text" name="phone" value="<?php echo esc_attr( $e_phone ); ?>" oninput="sywatFormatPhoneInput(this)" placeholder="XXX-XXX-XXXX" maxlength="12">
                            </div>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Street Address Line 1</label>
                                <input type="text" name="address_1" value="<?php echo esc_attr( $e_address_1 ); ?>">
                            </div>
                            <div class="sywat-field-group">
                                <label>Street Address Line 2</label>
                                <input type="text" name="address_2" value="<?php echo esc_attr( $e_address_2 ); ?>">
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>City</label>
                                <input type="text" name="city" value="<?php echo esc_attr( $e_city ); ?>">
                            </div>
                            <div class="sywat-field-group">
                                <label>State</label>
                                <select name="state">
                                    <option value="">-- Select State --</option>
                                    <?php foreach ( $us_states as $s_code => $s_label ): ?>
                                        <option value="<?php echo esc_attr( $s_code ); ?>" <?php selected( strtoupper( $e_state ), $s_code ); ?>><?php echo esc_html( $s_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Zip Code</label>
                                <input type="text" name="zip" value="<?php echo esc_attr( $e_zip ); ?>" maxlength="10">
                            </div>
                        </div>

                        <div class="sywat-modal-actions sywat-mt-4">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseAddDependentModal()">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save &amp; Link Family Member</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- MODAL 4: EDIT FAMILY SUB-PROFILE & STANDALONE ACCOUNT CONVERSION -->
    <div class="sywat-modal-backdrop <?php echo $open_edit_dependent_modal ? 'active' : ''; ?>" id="sywat_edit_dependent_modal">
        <?php if ( $edit_user_obj && $edit_dep_subprofile ): ?>
            <?php 
                $d_fname   = isset( $edit_dep_subprofile['first_name'] ) ? $edit_dep_subprofile['first_name'] : '';
                $d_lname   = isset( $edit_dep_subprofile['last_name'] ) ? $edit_dep_subprofile['last_name'] : '';
                $d_email   = isset( $edit_dep_subprofile['email'] ) ? $edit_dep_subprofile['email'] : '';
                $d_phone   = isset( $edit_dep_subprofile['phone'] ) ? $edit_dep_subprofile['phone'] : '';
                $d_gender  = isset( $edit_dep_subprofile['gender'] ) ? $edit_dep_subprofile['gender'] : 'unassigned';
                $d_is_pro  = isset( $edit_dep_subprofile['is_pro'] ) ? $edit_dep_subprofile['is_pro'] : 'no';
                $d_dob     = isset( $edit_dep_subprofile['dob'] ) ? $edit_dep_subprofile['dob'] : '';
                $d_accom   = isset( $edit_dep_subprofile['accommodations'] ) ? $edit_dep_subprofile['accommodations'] : 'none';
                $d_nfaa_n  = isset( $edit_dep_subprofile['nfaa_number'] ) ? $edit_dep_subprofile['nfaa_number'] : '';
                $d_nfaa_e  = isset( $edit_dep_subprofile['nfaa_expiration'] ) ? $edit_dep_subprofile['nfaa_expiration'] : '';
                $d_sys_id  = isset( $edit_dep_subprofile['system_id'] ) ? $edit_dep_subprofile['system_id'] : '';
                $d_addr_1  = isset( $edit_dep_subprofile['address_1'] ) ? $edit_dep_subprofile['address_1'] : '';
                $d_addr_2  = isset( $edit_dep_subprofile['address_2'] ) ? $edit_dep_subprofile['address_2'] : '';
                $d_city    = isset( $edit_dep_subprofile['city'] ) ? $edit_dep_subprofile['city'] : '';
                $d_state   = isset( $edit_dep_subprofile['state'] ) ? $edit_dep_subprofile['state'] : '';
                $d_zip     = isset( $edit_dep_subprofile['zip'] ) ? $edit_dep_subprofile['zip'] : '';
                $d_age_div = ! empty( $d_dob ) && function_exists( 'sywat_get_eligible_age_division' ) ? sywat_get_eligible_age_division( $d_dob ) : 'Adult';
                $d_name    = trim( $d_fname . ' ' . $d_lname );
                $parent_edit_url = '?tab=members&view=edit_member&edit_member_id=' . intval( $edit_user_obj->ID );
            ?>
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <h3>✏️ Edit Family Sub-Profile: <?php echo esc_html( $d_name ); ?></h3>
                    <button type="button" class="sywat-modal-close" onclick="sywatCloseEditDependentModal()">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <form method="post" action="<?php echo esc_url( $parent_edit_url ); ?>">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="update_member">
                        <input type="hidden" name="primary_user_id" value="<?php echo $edit_user_obj->ID; ?>">
                        <input type="hidden" name="target_system_id" value="<?php echo esc_attr( $d_sys_id ); ?>">

                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label>First Name *</label>
                                <input type="text" name="first_name" value="<?php echo esc_attr( $d_fname ); ?>" required>
                            </div>
                            <div class="sywat-field-group">
                                <label>Last Name *</label>
                                <input type="text" name="last_name" value="<?php echo esc_attr( $d_lname ); ?>" required>
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Gender *</label>
                                <select name="gender" required>
                                    <?php foreach ( $gender_options as $g_key => $g_label ): ?>
                                        <option value="<?php echo esc_attr( $g_key ); ?>" <?php selected( strtolower( $d_gender ), $g_key ); ?>><?php echo esc_html( $g_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Pro Archer Status</label>
                                <select name="is_pro">
                                    <option value="no" <?php selected( $d_is_pro, 'no' ); ?>>No</option>
                                    <option value="yes" <?php selected( $d_is_pro, 'yes' ); ?>>Yes</option>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Date of Birth * (Age Division: <strong><?php echo esc_html( $d_age_div ); ?></strong>)</label>
                                <input type="date" name="dob" value="<?php echo esc_attr( $d_dob ); ?>" required>
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Accommodations Needed</label>
                                <select name="accommodations">
                                    <?php foreach ( $accom_options as $a_key => $a_label ): ?>
                                        <option value="<?php echo esc_attr( $a_key ); ?>" <?php selected( $d_accom, $a_key ); ?>><?php echo esc_html( $a_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>NFAA Membership ID</label>
                                <input type="text" name="nfaa_number" value="<?php echo esc_attr( $d_nfaa_n ); ?>" placeholder="e.g. 123456">
                            </div>
                            <div class="sywat-field-group">
                                <label>NFAA Membership Expiration Date</label>
                                <input type="date" name="nfaa_expiration" value="<?php echo esc_attr( $d_nfaa_e ); ?>">
                            </div>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Contact Email Address</label>
                                <input type="email" name="email" value="<?php echo esc_attr( $d_email ); ?>" required>
                            </div>
                            <div class="sywat-field-group">
                                <label>Contact Phone Number</label>
                                <input type="text" name="phone" value="<?php echo esc_attr( $d_phone ); ?>" oninput="sywatFormatPhoneInput(this)" placeholder="XXX-XXX-XXXX" maxlength="12">
                            </div>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Street Address Line 1</label>
                                <input type="text" name="address_1" value="<?php echo esc_attr( $d_addr_1 ); ?>">
                            </div>
                            <div class="sywat-field-group">
                                <label>Street Address Line 2</label>
                                <input type="text" name="address_2" value="<?php echo esc_attr( $d_addr_2 ); ?>">
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>City</label>
                                <input type="text" name="city" value="<?php echo esc_attr( $d_city ); ?>">
                            </div>
                            <div class="sywat-field-group">
                                <label>State</label>
                                <select name="state">
                                    <option value="">-- Select State --</option>
                                    <?php foreach ( $us_states as $s_code => $s_label ): ?>
                                        <option value="<?php echo esc_attr( $s_code ); ?>" <?php selected( strtoupper( $d_state ), $s_code ); ?>><?php echo esc_html( $s_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Zip Code</label>
                                <input type="text" name="zip" value="<?php echo esc_attr( $d_zip ); ?>" maxlength="10">
                            </div>
                        </div>

                        <!-- IN-FORM GOVERNANCE CARD FOR STANDALONE CONVERSION -->
                        <div class="sywat-card sywat-mt-4">
                            <div class="sywat-card-header-bar">
                                <div>
                                    <h5 class="sywat-m-0">🔄 Convert to Standalone Account</h5>
                                    <div class="sywat-meta-subtext sywat-m-0 sywat-mt-1">
                                        <strong>Promote this family member to an independent primary account with dedicated login credentials.</strong>
                                    </div>
                                </div>
                                <button type="button" class="sywat-btn sywat-btn-secondary" onclick="sywatTogglePromoteSection()">
                                    Expand Form
                                </button>
                            </div>

                            <!-- COLLAPSIBLE CONVERSION CREDENTIAL DRAWER -->
                            <div id="sywat_promote_collapsible" class="sywat-mt-3 sywat-hidden">
                                <div class="sywat-alert sywat-alert-info">
                                    🔄 <strong>Convert to Standalone Account:</strong> Creates an independent WordPress login account for this member while preserving 100% of their historical score records.
                                </div>

                                <div class="sywat-form-grid-2 sywat-mt-3">
                                    <div class="sywat-field-group">
                                        <label>New Standalone Email Address *</label>
                                        <input type="email" name="new_email" value="<?php echo esc_attr( $d_email ); ?>" placeholder="unique_email@domain.com">
                                    </div>
                                    <div class="sywat-field-group">
                                        <label>Initial Login Password (Optional)</label>
                                        <input type="password" id="sywat_promote_pw" name="new_password" placeholder="Leave blank to auto-generate..." oninput="sywatValidatePasswordRules('sywat_promote_pw', 'sywat_promote_pw_confirm', 'promote_rule')">
                                    </div>
                                </div>

                                <div class="sywat-form-grid-2 sywat-mt-3">
                                    <div class="sywat-field-group">
                                        <label>Confirm Login Password</label>
                                        <input type="password" id="sywat_promote_pw_confirm" name="confirm_new_password" placeholder="Confirm new password..." oninput="sywatValidatePasswordRules('sywat_promote_pw', 'sywat_promote_pw_confirm', 'promote_rule')">
                                    </div>
                                </div>

                                <div class="sywat-mt-3 sywat-text-right">
                                    <button type="submit" name="sywat_action" value="promote_dependent" class="sywat-btn sywat-btn-primary" onclick="return confirm('Convert <?php echo esc_js( $d_name ); ?> to a standalone Primary Account Holder? Their System ID and tournament score history will be preserved.');">
                                        🚀 Confirm Account Conversion
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- CLEAN MODAL FOOTER ACTIONS -->
                        <div class="sywat-modal-actions sywat-mt-4">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseEditDependentModal()">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Update Family Sub-Profile</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODAL 5: ADD / ASSIGN BOARD MEMBER MODAL -->
    <div class="sywat-modal-backdrop" id="sywat_add_board_modal">
        <div class="sywat-modal-content sywat-modal-md">
            <div class="sywat-modal-header no-print">
                <h3>Assign Board Member Positions</h3>
                <button type="button" class="sywat-modal-close" onclick="sywatCloseAddBoardModal()">&times;</button>
            </div>
            <div class="sywat-modal-body">
                <form method="post" action="?tab=members&subtab=board">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_action" value="save_board_member">

                    <div class="sywat-field-group">
                        <label>Select Member *</label>
                        <select name="board_user_id" required>
                            <option value="">-- Choose Member from Directory --</option>
                            <?php 
                                foreach ( $all_members as $m_item ): 
                                    if ( ! $m_item instanceof WP_User ) continue;
                            ?>
                                <option value="<?php echo intval( $m_item->ID ); ?>">
                                    <?php echo esc_html( $m_item->display_name ); ?> (<?php echo esc_html( $m_item->user_email ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group sywat-mt-3 sywat-mb-4">
                        <label>Assign Board Position(s) *</label>
                        <div class="sywat-form-grid-2 sywat-role-grid">
                            <?php foreach ( $all_board_positions as $pos_key => $pos_title ): ?>
                                <label class="sywat-checkbox-label">
                                    <input type="checkbox" name="board_positions[]" value="<?php echo esc_attr( $pos_key ); ?>">
                                    <?php echo esc_html( $pos_title ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="sywat-modal-actions">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseAddBoardModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Board Assignment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 6: EDIT BOARD MEMBER POSITIONS MODAL -->
    <div class="sywat-modal-backdrop <?php echo $open_edit_board_modal ? 'active' : ''; ?>" id="sywat_edit_board_modal">
        <?php if ( $edit_board_user_obj ): ?>
            <?php 
                $cur_positions = get_user_meta( $edit_board_user_obj->ID, 'sywat_board_positions', true );
                if ( ! is_array( $cur_positions ) ) $cur_positions = array();
            ?>
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header no-print">
                    <h3>Edit Board Member: <?php echo esc_html( $edit_board_user_obj->display_name ); ?></h3>
                    <button type="button" class="sywat-modal-close" onclick="sywatCloseEditBoardModal()">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <form method="post" action="?tab=members&subtab=board">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="save_board_member">
                        <input type="hidden" name="board_user_id" value="<?php echo intval( $edit_board_user_obj->ID ); ?>">

                        <div class="sywat-member-info-banner">
                            <span>👤 <strong>Name:</strong> <?php echo esc_html( $edit_board_user_obj->display_name ); ?></span>
                            <span>✉️ <strong>Email:</strong> <?php echo esc_html( $edit_board_user_obj->user_email ); ?></span>
                        </div>

                        <div class="sywat-field-group sywat-mb-4">
                            <label>Assigned Position(s)</label>
                            <div class="sywat-form-grid-2 sywat-role-grid">
                                <?php foreach ( $all_board_positions as $pos_key => $pos_title ): ?>
                                    <label class="sywat-checkbox-label">
                                        <input type="checkbox" name="board_positions[]" value="<?php echo esc_attr( $pos_key ); ?>" <?php checked( in_array( $pos_key, $cur_positions, true ), true ); ?>>
                                        <?php echo esc_html( $pos_title ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="sywat-modal-actions">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseEditBoardModal()">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Update Positions</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODAL 7: HISTORICAL ARCHERS BATCH SPREADSHEET IMPORTER MODAL -->
    <div class="sywat-modal-backdrop" id="sywat_import_archers_modal">
        <div class="sywat-modal-content sywat-modal-md">
            <div class="sywat-modal-header no-print">
                <h3>📥 Import Historical Archers Spreadsheet</h3>
                <button type="button" class="sywat-modal-close" onclick="sywatCloseImportModal()">&times;</button>
            </div>
            <div class="sywat-modal-body">
                <form method="post" action="?tab=members&subtab=directory" enctype="multipart/form-data" id="sywat_import_archers_form">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_action" value="import_archers_spreadsheet">
                    <input type="hidden" name="import_mode" value="preview">

                    <div class="sywat-alert sywat-alert-info">
                        📊 <strong>Batch Archer Import:</strong> Upload an Archers spreadsheet (.xlsx / .csv). Records will be cleaned, deduplicated, and grouped into household units automatically before database ingestion.
                    </div>

                    <div class="sywat-field-group sywat-mt-3">
                        <label>Select Archers Spreadsheet File (.xlsx / .csv) *</label>
                        <input type="file" name="archers_file" accept=".xlsx, .xls, .csv" required>
                    </div>

                    <div class="sywat-modal-actions sywat-mt-4">
                        <button type="button" class="sywat-btn sywat-btn-secondary" onclick="sywatCloseImportModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-primary" id="sywat_import_submit_btn">🔍 Audit &amp; Preview File</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 8: HISTORICAL ARCHERS BATCH IMPORT AUDIT PREVIEW OVERLAY -->
    <?php
    $preview_data       = isset( $GLOBALS['sywat_import_preview_data'] ) ? $GLOBALS['sywat_import_preview_data'] : null;
    $open_preview_modal = ( $preview_data !== null );
    ?>
    <div class="sywat-modal-backdrop <?php echo $open_preview_modal ? 'active' : ''; ?>" id="sywat_import_preview_modal">
        <?php if ( $preview_data ): ?>
            <?php
                $p_file_path = isset( $preview_data['file_path'] ) ? $preview_data['file_path'] : '';
                $p_file_name = isset( $preview_data['file_name'] ) ? $preview_data['file_name'] : '';
                $p_total     = isset( $preview_data['total_rows'] ) ? $preview_data['total_rows'] : 0;
                $p_purged    = isset( $preview_data['purged_rows'] ) ? $preview_data['purged_rows'] : 0;
                $p_clean     = isset( $preview_data['clean_unique_count'] ) ? $preview_data['clean_unique_count'] : 0;
                $p_primary   = isset( $preview_data['primary_accounts_count'] ) ? $preview_data['primary_accounts_count'] : 0;
                $p_dep       = isset( $preview_data['dependents_count'] ) ? $preview_data['dependents_count'] : 0;
                $p_noemail   = isset( $preview_data['unassigned_emails_count'] ) ? $preview_data['unassigned_emails_count'] : 0;
                $households  = isset( $preview_data['households'] ) && is_array( $preview_data['households'] ) ? $preview_data['households'] : array();

                // BULK IN-MEMORY DATABASE PRE-FETCH FOR FAST PREVIEW MATCHING (DELEGATED TO members-data.php)
                $db_maps            = function_exists( 'sywat_get_import_preview_db_maps' ) ? sywat_get_import_preview_db_maps() : array();
                $existing_nfaa_set  = isset( $db_maps['existing_nfaa_set'] ) ? $db_maps['existing_nfaa_set'] : array();
                $existing_email_set = isset( $db_maps['existing_email_set'] ) ? $db_maps['existing_email_set'] : array();
            ?>
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <h3>📊 Archer Batch Import Audit Preview</h3>
                    <button type="button" class="sywat-modal-close" onclick="sywatCloseImportPreviewModal()">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <div class="sywat-alert sywat-alert-info">
                        File Analyzed: <strong><?php echo esc_html( $p_file_name ); ?></strong> — Review summary metrics and itemized archer mappings before committing database changes.
                    </div>

                    <div class="sywat-form-grid-3 sywat-mt-3">
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">Total Spreadsheet Rows</span>
                            <h3><?php echo intval( $p_total ); ?></h3>
                        </div>
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">Clean Unique Archers</span>
                            <h3><?php echo intval( $p_clean ); ?></h3>
                        </div>
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">Purged Garbage Rows</span>
                            <h3><?php echo intval( $p_purged ); ?></h3>
                        </div>
                    </div>

                    <div class="sywat-form-grid-3 sywat-mt-3">
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">Primary WP Accounts (Households)</span>
                            <h3><?php echo intval( $p_primary ); ?></h3>
                        </div>
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">Household Dependents</span>
                            <h3><?php echo intval( $p_dep ); ?></h3>
                        </div>
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">Unassigned Emails Flagged</span>
                            <h3><?php echo intval( $p_noemail ); ?></h3>
                        </div>
                    </div>

                    <div class="sywat-card-header-bar sywat-mt-4">
                        <div>
                            <h3>👥 Itemized Household &amp; Archer Mapping List</h3>
                            <div class="sywat-subtitle">Showing all mapped Primary Accounts and linked Family Dependents before database ingestion.</div>
                        </div>
                    </div>

                    <div class="sywat-table-responsive sywat-mt-2">
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th class="sywat-col-w-28">Archer Name &amp; Role</th>
                                    <th class="sywat-col-w-28">Assigned Email</th>
                                    <th class="sywat-col-center sywat-col-w-16">NFAA ID &amp; Exp</th>
                                    <th class="sywat-col-center sywat-col-w-14">DOB / Pro</th>
                                    <th class="sywat-col-center sywat-col-w-14">DB Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $households as $members ): ?>
                                    <?php 
                                        $head = array_shift( $members );
                                        
                                        $existing_match = false;
                                        if ( ! empty( $head['nfaa_number'] ) && isset( $existing_nfaa_set[ $head['nfaa_number'] ] ) ) {
                                            $existing_match = true;
                                        }
                                        if ( ! $existing_match && ! empty( $head['email'] ) && isset( $existing_email_set[ strtolower( $head['email'] ) ] ) ) {
                                            $existing_match = true;
                                        }

                                        $primary_email_disp = ! empty( $head['email'] ) ? $head['email'] : 'unassigned-' . ( ! empty( $head['nfaa_number'] ) ? $head['nfaa_number'] : 'temp' ) . '@system.sywat.local';
                                    ?>
                                    <!-- ROW 1: PRIMARY ACCOUNT HOLDER (HEAD OF HOUSEHOLD) -->
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html( $head['first_name'] . ' ' . $head['last_name'] ); ?></strong>
                                            <div class="sywat-meta-subtext">👑 Head of Household</div>
                                        </td>
                                        <td>
                                            <code><?php echo esc_html( $primary_email_disp ); ?></code>
                                            <?php if ( empty( $head['email'] ) ): ?>
                                                <div class="sywat-meta-subtext">⚠️ Placeholder System Email</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sywat-col-center">
                                            <div>🆔 <strong><?php echo esc_html( ! empty( $head['nfaa_number'] ) ? $head['nfaa_number'] : '—' ); ?></strong></div>
                                            <div class="sywat-meta-subtext"><?php echo ! empty( $head['nfaa_exp'] ) ? esc_html( date( 'M Y', strtotime( $head['nfaa_exp'] ) ) ) : '—'; ?></div>
                                        </td>
                                        <td class="sywat-col-center">
                                            <div><?php echo ! empty( $head['dob'] ) ? esc_html( date( 'M j, Y', strtotime( $head['dob'] ) ) ) : '—'; ?></div>
                                            <?php if ( $head['is_pro'] === 'yes' ): ?>
                                                <span class="sywat-badge sywat-badge-pro sywat-mt-1">⭐ Pro</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sywat-col-center">
                                            <?php if ( $existing_match ): ?>
                                                <span class="sywat-badge sywat-badge-checkedin">🔄 Update Profile</span>
                                            <?php else: ?>
                                                <span class="sywat-badge sywat-badge-open">🆕 New Account</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                    <!-- DEPENDENTS IN THIS HOUSEHOLD -->
                                    <?php foreach ( $members as $dep ): ?>
                                        <tr>
                                            <td>
                                                <div class="sywat-ml-3">
                                                    ↪️ <strong><?php echo esc_html( $dep['first_name'] . ' ' . $dep['last_name'] ); ?></strong>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="sywat-ml-3 sywat-meta-subtext">
                                                    (Linked to Household Email)
                                                </div>
                                            </td>
                                            <td class="sywat-col-center">
                                                <div>🆔 <strong><?php echo esc_html( ! empty( $dep['nfaa_number'] ) ? $dep['nfaa_number'] : '—' ); ?></strong></div>
                                                <div class="sywat-meta-subtext"><?php echo ! empty( $dep['nfaa_exp'] ) ? esc_html( date( 'M Y', strtotime( $dep['nfaa_exp'] ) ) ) : '—'; ?></div>
                                            </td>
                                            <td class="sywat-col-center">
                                                <div><?php echo ! empty( $dep['dob'] ) ? esc_html( date( 'M j, Y', strtotime( $dep['dob'] ) ) ) : '—'; ?></div>
                                                <?php if ( $dep['is_pro'] === 'yes' ): ?>
                                                    <span class="sywat-badge sywat-badge-pro sywat-mt-1">⭐ Pro</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="sywat-col-center">
                                                <span class="sywat-badge sywat-badge-muted">🏠 Dependent</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form method="post" action="?tab=members&subtab=directory" id="sywat_commit_import_form">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="import_archers_spreadsheet">
                        <input type="hidden" name="import_mode" value="commit">
                        <input type="hidden" name="persistent_file_path" value="<?php echo esc_attr( $p_file_path ); ?>">

                        <div class="sywat-modal-actions sywat-mt-4">
                            <button type="button" class="sywat-btn sywat-btn-secondary" onclick="sywatCloseImportPreviewModal()">Cancel Import</button>
                            <button type="submit" class="sywat-btn sywat-btn-primary" id="sywat_commit_submit_btn">🚀 Confirm &amp; Ingest <?php echo intval( $p_clean ); ?> Archers into Database</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODAL 9: PROTECTED BATCH TEST DATA PURGE MODAL -->
    <div class="sywat-modal-backdrop" id="sywat_purge_test_modal">
        <div class="sywat-modal-content sywat-modal-md">
            <div class="sywat-modal-header no-print">
                <h3>🗑️ Purge Batch Test Data</h3>
                <button type="button" class="sywat-modal-close" onclick="sywatClosePurgeModal()">&times;</button>
            </div>
            <div class="sywat-modal-body">
                <form method="post" action="?tab=members&subtab=directory" id="sywat_purge_test_form">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_action" value="purge_test_members">

                    <div class="sywat-alert sywat-alert-danger">
                        ⚠️ <strong>Protected Data Reset Tool:</strong> This action will permanently remove standard imported test members from the directory database.
                    </div>

                    <div class="sywat-member-info-banner sywat-mt-3">
                        <div>🛡️ <strong>Automatically Protected Accounts (Will NOT Be Deleted):</strong></div>
                        <ul class="sywat-mt-1 sywat-mb-0 sywat-pl-3">
                            <li>System Administrators &amp; Webmasters</li>
                            <li>Your Active Logged-In Account</li>
                            <li>Shop &amp; Club Managers / Desk Staff</li>
                            <li>Official Board Directors &amp; Field Governors</li>
                        </ul>
                    </div>

                    <div class="sywat-field-group sywat-mt-4">
                        <label class="sywat-checkbox-label">
                            <input type="checkbox" id="sywat_purge_confirm_check" onchange="document.getElementById('sywat_purge_submit_btn').disabled = !this.checked;">
                            I understand this will delete standard test members while preserving all protected officers and shop managers.
                        </label>
                    </div>

                    <div class="sywat-modal-actions sywat-mt-4">
                        <button type="button" class="sywat-btn sywat-btn-secondary" onclick="sywatClosePurgeModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-danger" id="sywat_purge_submit_btn" disabled>🗑️ Execute Data Purge</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 10: LINK ARCHER TO HOUSEHOLD OVERLAY -->
    <div class="sywat-modal-backdrop" id="sywat_transfer_member_modal">
        <div class="sywat-modal-content sywat-modal-md">
            <div class="sywat-modal-header no-print">
                <h3>🔗 Link Archer to Household</h3>
                <button type="button" class="sywat-modal-close" onclick="sywatCloseTransferMemberModal()">&times;</button>
            </div>
            <div class="sywat-modal-body">
                <form method="post" action="?tab=members&subtab=directory" id="sywat_transfer_member_form">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_action" value="transfer_household_member">
                    <input type="hidden" name="source_user_id" id="sywat_transfer_source_user_id" value="0">
                    <input type="hidden" name="target_system_id" id="sywat_transfer_target_system_id" value="">
                    <input type="hidden" name="target_head_id" id="sywat_transfer_target_head_id" value="" required>

                    <div class="sywat-alert sywat-alert-info">
                        🔗 <strong>Link Archer:</strong> Assigns this archer under a target Head of Household as a family dependent sub-profile.
                    </div>

                    <div class="sywat-member-info-banner sywat-mt-3">
                        <span>👤 <strong>Archer Name:</strong> <span id="sywat_transfer_source_name">-- Select Member --</span></span>
                    </div>

                    <!-- DYNAMIC AS-YOU-TYPE HEAD OF HOUSEHOLD AUTOCOMPLETE SEARCH -->
                    <div class="sywat-field-group sywat-mt-4 sywat-autocomplete-wrapper">
                        <label>🔍 Search Target Head of Household (Type Name or NFAA ID) *</label>
                        <input type="text" id="sywat_transfer_head_search" placeholder="Start typing name or NFAA ID..." autocomplete="off" oninput="sywatHandleHeadSearch(this)">
                        <div id="sywat_transfer_head_search_results" class="sywat-autocomplete-results sywat-hidden"></div>
                    </div>

                    <div id="sywat_transfer_selected_head_display" class="sywat-mt-2 sywat-hidden"></div>

                    <div class="sywat-modal-actions sywat-mt-4">
                        <button type="button" class="sywat-btn sywat-btn-secondary" onclick="sywatCloseTransferMemberModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-primary" id="sywat_transfer_submit_btn" disabled>🚀 Confirm &amp; Link to Household</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    // TEMPORARY POC MODAL HOOK: TFAA Gender & Pro Status Reconciliation (Remove Post-POC)
    if ( function_exists( 'sywat_render_tfaa_reconcile_modal' ) ) {
        sywat_render_tfaa_reconcile_modal();
    }
}
