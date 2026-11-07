<?php
/**
 * Sub-Module: Member & Board Management Portal Modals
 * Path: includes/members/members-modals.php
 * Description: Renders modal overlay templates for creating/editing members, address details, DOB, NFAA expiration date, household links, and board positions.
 * Compliance: Zero inline styles, zero embedded scripts. JS logic isolated to assets/js/sywat-members.js.
 * Author: SYWAT Archery
 * Version: 3.1.0
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
    $edit_board_user_obj = isset( $data['edit_board_user_obj'] ) ? $data['edit_board_user_obj'] : null;
    $excluded_roles      = isset( $data['excluded_roles'] ) ? $data['excluded_roles'] : array();

    $open_edit_modal       = ( $edit_user_obj !== null );
    $open_edit_board_modal = ( $edit_board_user_obj !== null );

    $all_shops = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();

    global $wp_roles;
    $editable_roles = $wp_roles ? $wp_roles->roles : array();
    ?>

    <!-- MODAL 1: ADD NEW MEMBER MODAL -->
    <div class="sywat-modal-backdrop" id="sywat_add_member_modal">
        <div class="sywat-modal-content sywat-modal-lg">
            <div class="sywat-modal-header no-print">
                <h3>Create New Member Account</h3>
                <button type="button" class="sywat-modal-close" onclick="sywatCloseAddMemberModal()">&times;</button>
            </div>
            <div class="sywat-modal-body">
                <form method="post" action="?tab=members&subtab=directory" id="sywat_add_member_form">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_action" value="create_member">

                    <div class="sywat-alert sywat-alert-success sywat-notice-banner">
                        🆔 <strong>TFAA Member ID &amp; Username:</strong> Auto-formatted as <code>Firstname.Lastname</code>; ID generated sequentially.
                    </div>

                    <!-- Personal Information -->
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

                    <!-- DOB & NFAA Expiration -->
                    <div class="sywat-form-grid-2 sywat-mt-3">
                        <div class="sywat-field-group">
                            <label>Date of Birth * (For NFAA Age Divisions)</label>
                            <input type="date" name="dob" required>
                        </div>
                        <div class="sywat-field-group">
                            <label>NFAA Membership Expiration Date</label>
                            <input type="date" name="nfaa_expiration">
                        </div>
                    </div>

                    <!-- Address Information -->
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
                            <input type="text" name="state" placeholder="TX" maxlength="2">
                        </div>
                        <div class="sywat-field-group">
                            <label>Zip Code</label>
                            <input type="text" name="zip" maxlength="10">
                        </div>
                    </div>

                    <!-- Household Primary Linkage -->
                    <div class="sywat-field-group sywat-mt-3">
                        <label>Primary Account Holder (Household Link)</label>
                        <select name="primary_user_id">
                            <option value="0" selected>-- Standalone / Primary Account Holder --</option>
                            <?php foreach ( $all_members as $m_opt ): ?>
                                <option value="<?php echo intval( $m_opt->ID ); ?>">
                                    🏠 Link to: <?php echo esc_html( $m_opt->display_name ); ?> (<?php echo esc_html( $m_opt->user_email ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- MULTI-ROLE CHECKBOX GRID -->
                    <div class="sywat-field-group sywat-mt-3">
                        <label>Assigned System Role(s) *</label>
                        <div class="sywat-form-grid-2 sywat-role-grid">
                            <?php foreach ( $editable_roles as $r_key => $r_details ): ?>
                                <?php if ( in_array( strtolower( $r_key ), $excluded_roles, true ) ) continue; ?>
                                <label class="sywat-checkbox-label">
                                    <input type="checkbox" name="sywat_roles[]" value="<?php echo esc_attr( $r_key ); ?>" <?php checked( $r_key, 'tfaa_member' ); ?> onchange="sywatToggleShopVisibility('sywat_add_member_form', 'sywat_add_shop_container')">
                                    <?php echo esc_html( $r_details['name'] ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- CONDITIONAL SHOP DROPDOWN -->
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

                    <!-- COMPACT INLINE PASSWORD REQUIREMENTS BAR -->
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

    <!-- MODAL 2: EDIT MEMBER MODAL -->
    <div class="sywat-modal-backdrop <?php echo $open_edit_modal ? 'active' : ''; ?>" id="sywat_edit_member_modal">
        <?php if ( $edit_user_obj ): ?>
            <?php 
                $e_tfaa             = get_user_meta( $edit_user_obj->ID, 'sywat_tfaa_number', true );
                $e_phone            = get_user_meta( $edit_user_obj->ID, 'sywat_phone', true );
                $e_dob              = get_user_meta( $edit_user_obj->ID, 'sywat_dob', true );
                $e_expiration       = get_user_meta( $edit_user_obj->ID, 'sywat_nfaa_expiration', true );
                $e_address_1        = get_user_meta( $edit_user_obj->ID, 'sywat_address_1', true );
                $e_address_2        = get_user_meta( $edit_user_obj->ID, 'sywat_address_2', true );
                $e_city             = get_user_meta( $edit_user_obj->ID, 'sywat_city', true );
                $e_state            = get_user_meta( $edit_user_obj->ID, 'sywat_state', true );
                $e_zip              = get_user_meta( $edit_user_obj->ID, 'sywat_zip', true );
                $e_primary_id       = get_user_meta( $edit_user_obj->ID, 'sywat_primary_user_id', true );
                $e_shop_id          = get_user_meta( $edit_user_obj->ID, 'sywat_shop_id', true );
                $current_user_roles = (array) $edit_user_obj->roles;

                // Retrieve all household members linked to this user (if this user is Primary)
                $linked_household = function_exists( 'sywat_get_linked_household_members' ) 
                    ? sywat_get_linked_household_members( $edit_user_obj->ID ) 
                    : array();
            ?>
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <h3>Edit Member: <?php echo esc_html( $edit_user_obj->display_name ); ?></h3>
                    <button type="button" class="sywat-modal-close" onclick="sywatCloseEditMemberModal()">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <form method="post" action="?tab=members&subtab=directory" id="sywat_edit_member_form">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="update_member">
                        <input type="hidden" name="target_user_id" value="<?php echo $edit_user_obj->ID; ?>">

                        <div class="sywat-member-info-banner">
                            <span>🆔 <strong>TFAA Member #:</strong> <span class="sywat-meta-code-primary"><?php echo esc_html( ! empty( $e_tfaa ) ? $e_tfaa : 'Unassigned' ); ?></span></span>
                            <span>👤 <strong>Username:</strong> <span class="sywat-meta-code"><?php echo esc_html( $edit_user_obj->user_login ); ?></span></span>
                        </div>

                        <!-- Personal Details -->
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

                        <!-- DOB & Expiration Date -->
                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Date of Birth (For NFAA Age Divisions)</label>
                                <input type="date" name="dob" value="<?php echo esc_attr( $e_dob ); ?>">
                            </div>
                            <div class="sywat-field-group">
                                <label>NFAA Membership Expiration Date</label>
                                <input type="date" name="nfaa_expiration" value="<?php echo esc_attr( $e_expiration ); ?>">
                            </div>
                        </div>

                        <!-- Address Information -->
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
                                <input type="text" name="state" value="<?php echo esc_attr( $e_state ); ?>" placeholder="TX" maxlength="2">
                            </div>
                            <div class="sywat-field-group">
                                <label>Zip Code</label>
                                <input type="text" name="zip" value="<?php echo esc_attr( $e_zip ); ?>" maxlength="10">
                            </div>
                        </div>

                        <!-- Household Primary Linkage -->
                        <div class="sywat-field-group sywat-mt-3">
                            <label>Primary Account Holder (Household Link)</label>
                            <select name="primary_user_id">
                                <option value="0" <?php selected( empty( $e_primary_id ), true ); ?>>-- Standalone / Primary Account Holder --</option>
                                <?php foreach ( $all_members as $m_opt ): ?>
                                    <?php if ( $m_opt->ID === $edit_user_obj->ID ) continue; ?>
                                    <option value="<?php echo intval( $m_opt->ID ); ?>" <?php selected( intval( $e_primary_id ), intval( $m_opt->ID ) ); ?>>
                                        🏠 Link to: <?php echo esc_html( $m_opt->display_name ); ?> (<?php echo esc_html( $m_opt->user_email ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- LINKED HOUSEHOLD DEPENDENTS DISPLAY -->
                        <?php if ( ! empty( $linked_household ) ): ?>
                            <div class="sywat-field-group sywat-mt-3">
                                <label>🏠 Linked Household Members (Dependents)</label>
                                <div class="sywat-role-grid">
                                    <?php foreach ( $linked_household as $dep ): ?>
                                        <div class="sywat-checkbox-label">
                                            👤 <strong><?php echo esc_html( $dep->display_name ); ?></strong> 
                                            <span class="sywat-meta-subtext">(ID: <?php echo esc_html( get_user_meta( $dep->ID, 'sywat_tfaa_number', true ) ); ?>)</span>
                                            <a href="?tab=members&subtab=directory&edit_member_id=<?php echo intval( $dep->ID ); ?>" class="sywat-table-link">Edit Profile</a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- MULTI-ROLE CHECKBOX GRID -->
                        <div class="sywat-field-group sywat-mt-3">
                            <label>Assigned System Role(s) *</label>
                            <div class="sywat-form-grid-2 sywat-role-grid">
                                <?php foreach ( $editable_roles as $r_key => $r_details ): ?>
                                    <?php if ( in_array( strtolower( $r_key ), $excluded_roles, true ) ) continue; ?>
                                    <label class="sywat-checkbox-label">
                                        <input type="checkbox" name="sywat_roles[]" value="<?php echo esc_attr( $r_key ); ?>" <?php checked( in_array( $r_key, $current_user_roles, true ), true ); ?> onchange="sywatToggleShopVisibility('sywat_edit_member_form', 'sywat_edit_shop_container')">
                                        <?php echo esc_html( $r_details['name'] ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- CONDITIONAL SHOP DROPDOWN -->
                        <div id="sywat_edit_shop_container" class="sywat-field-group sywat-mt-3 sywat-hidden">
                            <label>Assigned Host Shop / Club</label>
                            <select name="sywat_shop_id">
                                <option value="" <?php selected( empty( $e_shop_id ), true ); ?>>-- None / Unassigned --</option>
                                <?php foreach ( $all_shops as $s_id => $s_item ): ?>
                                    <option value="<?php echo esc_attr( $s_id ); ?>" <?php selected( $e_shop_id, $s_id ); ?>>
                                        <?php echo esc_html( isset( $s_item['name'] ) ? $s_item['name'] : $s_id ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Reset Password (Optional)</label>
                                <input type="password" id="sywat_edit_pw" name="new_password" placeholder="Leave blank to keep..." oninput="sywatValidatePasswordRules('sywat_edit_pw', 'sywat_edit_pw_confirm', 'edit_rule')">
                            </div>
                            <div class="sywat-field-group">
                                <label>Confirm New Password</label>
                                <input type="password" id="sywat_edit_pw_confirm" name="confirm_new_password" placeholder="Confirm password..." oninput="sywatValidatePasswordRules('sywat_edit_pw', 'sywat_edit_pw_confirm', 'edit_rule')">
                            </div>
                        </div>

                        <!-- COMPACT INLINE PASSWORD REQUIREMENTS BAR -->
                        <div class="sywat-password-rules-box">
                            <div class="sywat-password-rules-bar">
                                <span class="sywat-password-rules-label">Rules:</span>
                                <span id="edit_rule_len" class="sywat-rule-badge">⚪ 8+ Chars</span>
                                <span id="edit_rule_upper" class="sywat-rule-badge">⚪ 1 Upper</span>
                                <span id="edit_rule_lower" class="sywat-rule-badge">⚪ 1 Lower</span>
                                <span id="edit_rule_num" class="sywat-rule-badge">⚪ 1 Num</span>
                                <span id="edit_rule_match" class="sywat-rule-badge">⚪ Passwords Match</span>
                            </div>
                        </div>

                        <div class="sywat-modal-actions">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseEditMemberModal()">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Update Member Profile</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODAL 3: ADD / ASSIGN BOARD MEMBER MODAL -->
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
                            <?php foreach ( $all_members as $m_item ): ?>
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

    <!-- MODAL 4: EDIT BOARD MEMBER POSITIONS MODAL -->
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
    <?php
}
