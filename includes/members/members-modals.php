<?php
/**
 * Sub-Module: Member & Board Management Portal Modals & Scripts
 * Path: includes/members/members-modals.php
 * Author: SYWAT Archery
 * Version: 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function sywat_members_render_modals( $data ) {
    $all_members         = $data['all_members'];
    $all_board_positions = $data['all_board_positions'];
    $edit_user_obj       = $data['edit_user_obj'];
    $edit_board_user_obj = $data['edit_board_user_obj'];
    $excluded_roles      = $data['excluded_roles'];

    $open_edit_modal       = ( $edit_user_obj !== null );
    $open_edit_board_modal = ( $edit_board_user_obj !== null );

    global $wp_roles;
    $editable_roles = $wp_roles ? $wp_roles->roles : array();
    ?>

    <!-- MODAL 1: ADD NEW MEMBER MODAL -->
    <div class="sywat-modal-backdrop" id="sywat_add_member_modal">
        <div class="sywat-modal-content sywat-modal-md">
            <div class="sywat-modal-header no-print" style="padding: 12px 20px;">
                <h3 style="font-size: 1.05em; margin: 0;">Create New Member Account</h3>
                <button type="button" class="sywat-modal-close" onclick="sywatCloseAddMemberModal()">&times;</button>
            </div>
            <div class="sywat-modal-body" style="padding: 16px 20px;">
                <form method="post" action="?tab=members&subtab=directory">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_action" value="create_member">

                    <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; font-size: 12px; color: #1e3a8a;">
                        🆔 <strong>TFAA Member ID & Username:</strong> Auto-formatted as <code>Firstname.Lastname</code>; ID generated sequentially.
                    </div>

                    <div class="sywat-form-grid-2" style="margin-bottom: 10px;">
                        <div>
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">First Name *</label>
                            <input type="text" name="first_name" required style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Last Name *</label>
                            <input type="text" name="last_name" required style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                        </div>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Email Address *</label>
                        <input type="email" name="email" required style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                    </div>

                    <div class="sywat-form-grid-2" style="margin-bottom: 10px;">
                        <div>
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Phone Number</label>
                            <input type="text" name="phone" oninput="sywatFormatPhoneInput(this)" placeholder="XXX-XXX-XXXX" maxlength="12" style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">NFAA Member Status</label>
                            <select name="is_nfaa_member" style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; height:32px; box-sizing:border-box;">
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>
                    </div>

                    <!-- MULTI-ROLE CHECKBOX GRID -->
                    <div style="margin-bottom: 10px;">
                        <label style="display:block; font-weight:600; font-size:12px; margin-bottom:4px;">Assigned System Role(s) *</label>
                        <div class="sywat-form-grid-2" style="gap: 5px 12px; background: #f8fafc; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            <?php foreach ( $editable_roles as $r_key => $r_details ): ?>
                                <?php if ( in_array( strtolower( $r_key ), $excluded_roles, true ) ) continue; ?>
                                <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                    <input type="checkbox" name="sywat_roles[]" value="<?php echo esc_attr( $r_key ); ?>" <?php checked( $r_key, 'tfaa_member' ); ?>>
                                    <?php echo esc_html( $r_details['name'] ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="sywat-form-grid-2" style="margin-bottom: 8px;">
                        <div>
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Initial Password *</label>
                            <input type="password" id="sywat_add_pw" name="password" required oninput="sywatValidatePasswordRules('sywat_add_pw', 'sywat_add_pw_confirm', 'add_rule')" style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Confirm Password *</label>
                            <input type="password" id="sywat_add_pw_confirm" name="confirm_password" required oninput="sywatValidatePasswordRules('sywat_add_pw', 'sywat_add_pw_confirm', 'add_rule')" style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                        </div>
                    </div>

                    <!-- COMPACT INLINE PASSWORD REQUIREMENTS BAR -->
                    <div style="margin-bottom: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px;">
                        <div style="display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 11px; color: #64748b; align-items: center;">
                            <span style="font-weight: 700; color: #475569; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 2px;">Rules:</span>
                            <span id="add_rule_len">🔴 8+ Chars</span>
                            <span id="add_rule_upper">🔴 1 Upper</span>
                            <span id="add_rule_lower">🔴 1 Lower</span>
                            <span id="add_rule_num">🔴 1 Num</span>
                            <span id="add_rule_match">🔴 Passwords Match</span>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseAddMemberModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px; padding:6px 14px;">Save Member Profile</button>
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
                $e_nfaa             = get_user_meta( $edit_user_obj->ID, 'sywat_is_nfaa_member', true );
                $current_user_roles = (array) $edit_user_obj->roles;
            ?>
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header no-print" style="padding: 12px 20px;">
                    <h3 style="font-size: 1.05em; margin: 0;">Edit Member: <?php echo esc_html( $edit_user_obj->display_name ); ?></h3>
                    <button type="button" class="sywat-modal-close" onclick="sywatCloseEditMemberModal()">&times;</button>
                </div>
                <div class="sywat-modal-body" style="padding: 16px 20px;">
                    <form method="post" action="?tab=members&subtab=directory">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="update_member">
                        <input type="hidden" name="target_user_id" value="<?php echo $edit_user_obj->ID; ?>">

                        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; margin-bottom: 10px; font-size: 12px; color: #334155; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px;">
                            <span>🆔 <strong>TFAA Member #:</strong> <span style="font-family: monospace; font-weight: bold; color: #1e3a8a;"><?php echo esc_html( ! empty( $e_tfaa ) ? $e_tfaa : 'Unassigned' ); ?></span></span>
                            <span>👤 <strong>Username:</strong> <span style="font-family: monospace; font-weight: bold; color: #334155;"><?php echo esc_html( $edit_user_obj->user_login ); ?></span></span>
                        </div>

                        <div class="sywat-form-grid-2" style="margin-bottom: 10px;">
                            <div>
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">First Name *</label>
                                <input type="text" name="first_name" value="<?php echo esc_attr( $edit_user_obj->first_name ); ?>" required style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Last Name *</label>
                                <input type="text" name="last_name" value="<?php echo esc_attr( $edit_user_obj->last_name ); ?>" required style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                            </div>
                        </div>

                        <div style="margin-bottom: 10px;">
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Email Address *</label>
                            <input type="email" name="email" value="<?php echo esc_attr( $edit_user_obj->user_email ); ?>" required style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                        </div>

                        <div class="sywat-form-grid-2" style="margin-bottom: 10px;">
                            <div>
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Phone Number</label>
                                <input type="text" name="phone" value="<?php echo esc_attr( $e_phone ); ?>" oninput="sywatFormatPhoneInput(this)" placeholder="XXX-XXX-XXXX" maxlength="12" style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">NFAA Member Status</label>
                                <select name="is_nfaa_member" style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; height:32px; box-sizing:border-box;">
                                    <option value="no" <?php selected( $e_nfaa, 'no' ); ?>>No</option>
                                    <option value="yes" <?php selected( $e_nfaa, 'yes' ); ?>>Yes</option>
                                </select>
                            </div>
                        </div>

                        <!-- MULTI-ROLE CHECKBOX GRID -->
                        <div style="margin-bottom: 10px;">
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:4px;">Assigned System Role(s) *</label>
                            <div class="sywat-form-grid-2" style="gap: 5px 12px; background: #f8fafc; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                <?php foreach ( $editable_roles as $r_key => $r_details ): ?>
                                    <?php if ( in_array( strtolower( $r_key ), $excluded_roles, true ) ) continue; ?>
                                    <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="checkbox" name="sywat_roles[]" value="<?php echo esc_attr( $r_key ); ?>" <?php checked( in_array( $r_key, $current_user_roles ), true ); ?>>
                                        <?php echo esc_html( $r_details['name'] ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="sywat-form-grid-2" style="margin-bottom: 8px;">
                            <div>
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Reset Password (Optional)</label>
                                <input type="password" id="sywat_edit_pw" name="new_password" placeholder="Leave blank to keep..." oninput="sywatValidatePasswordRules('sywat_edit_pw', 'sywat_edit_pw_confirm', 'edit_rule')" style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; font-size:12px; margin-bottom:3px;">Confirm New Password</label>
                                <input type="password" id="sywat_edit_pw_confirm" name="confirm_new_password" placeholder="Confirm password..." oninput="sywatValidatePasswordRules('sywat_edit_pw', 'sywat_edit_pw_confirm', 'edit_rule')" style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                            </div>
                        </div>

                        <!-- COMPACT INLINE PASSWORD REQUIREMENTS BAR -->
                        <div style="margin-bottom: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px;">
                            <div style="display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 11px; color: #64748b; align-items: center;">
                                <span style="font-weight: 700; color: #475569; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 2px;">Rules:</span>
                                <span id="edit_rule_len">⚪ 8+ Chars</span>
                                <span id="edit_rule_upper">⚪ 1 Upper</span>
                                <span id="edit_rule_lower">⚪ 1 Lower</span>
                                <span id="edit_rule_num">⚪ 1 Num</span>
                                <span id="edit_rule_match">⚪ Passwords Match</span>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 10px;">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseEditMemberModal()">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px; padding:6px 14px;">Update Member Profile</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODAL 3: ADD / ASSIGN BOARD MEMBER MODAL -->
    <div class="sywat-modal-backdrop" id="sywat_add_board_modal">
        <div class="sywat-modal-content sywat-modal-md">
            <div class="sywat-modal-header no-print" style="padding: 12px 20px;">
                <h3 style="font-size: 1.05em; margin: 0;">Assign Board Member Positions</h3>
                <button type="button" class="sywat-modal-close" onclick="sywatCloseAddBoardModal()">&times;</button>
            </div>
            <div class="sywat-modal-body" style="padding: 16px 20px;">
                <form method="post" action="?tab=members&subtab=board">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_action" value="save_board_member">

                    <div style="margin-bottom: 12px;">
                        <label style="display:block; font-weight:600; font-size:12px; margin-bottom:4px;">Select Member *</label>
                        <select name="board_user_id" required style="width:100%; padding:6px 8px; border:1px solid #8c8f94; border-radius:4px; height:34px; box-sizing:border-box;">
                            <option value="">-- Choose Member from Directory --</option>
                            <?php foreach ( $all_members as $m_item ): ?>
                                <option value="<?php echo $m_item->ID; ?>">
                                    <?php echo esc_html( $m_item->display_name ); ?> (<?php echo esc_html( $m_item->user_email ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <label style="display:block; font-weight:600; font-size:12px; margin-bottom:4px;">Assign Board Position(s) *</label>
                    <div class="sywat-form-grid-2" style="gap: 6px; margin-bottom: 16px; background: #f8fafc; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        <?php foreach ( $all_board_positions as $pos_key => $pos_title ): ?>
                            <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" name="board_positions[]" value="<?php echo esc_attr( $pos_key ); ?>">
                                <?php echo esc_html( $pos_title ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseAddBoardModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px; padding:6px 14px;">Save Board Assignment</button>
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
                <div class="sywat-modal-header no-print" style="padding: 12px 20px;">
                    <h3 style="font-size: 1.05em; margin: 0;">Edit Board Member: <?php echo esc_html( $edit_board_user_obj->display_name ); ?></h3>
                    <button type="button" class="sywat-modal-close" onclick="sywatCloseEditBoardModal()">&times;</button>
                </div>
                <div class="sywat-modal-body" style="padding: 16px 20px;">
                    <form method="post" action="?tab=members&subtab=board">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="save_board_member">
                        <input type="hidden" name="board_user_id" value="<?php echo $edit_board_user_obj->ID; ?>">

                        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; margin-bottom: 12px; font-size: 12px; color: #334155; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px;">
                            <span>👤 <strong>Name:</strong> <?php echo esc_html( $edit_board_user_obj->display_name ); ?></span>
                            <span>✉️ <strong>Email:</strong> <?php echo esc_html( $edit_board_user_obj->user_email ); ?></span>
                        </div>

                        <label style="display:block; font-weight:600; font-size:12px; margin-bottom:4px;">Assigned Position(s)</label>
                        <div class="sywat-form-grid-2" style="gap: 6px; margin-bottom: 16px; background: #f8fafc; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            <?php foreach ( $all_board_positions as $pos_key => $pos_title ): ?>
                                <label style="font-size: 12px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                    <input type="checkbox" name="board_positions[]" value="<?php echo esc_attr( $pos_key ); ?>" <?php checked( in_array( $pos_key, $cur_positions ), true ); ?>>
                                    <?php echo esc_html( $pos_title ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 10px;">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseEditBoardModal()">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px; padding:6px 14px;">Update Positions</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
    function sywatFormatPhoneInput(input) {
        let val = input.value.replace(/\D/g, '');
        if (val.length > 10) val = val.substring(0, 10);
        if (val.length > 6) {
            input.value = val.substring(0, 3) + '-' + val.substring(3, 6) + '-' + val.substring(6);
        } else if (val.length > 3) {
            input.value = val.substring(0, 3) + '-' + val.substring(3);
        } else {
            input.value = val;
        }
    }

    function sywatValidatePasswordRules(pwId, confirmId, prefix) {
        const pwVal      = document.getElementById(pwId).value;
        const confirmVal = document.getElementById(confirmId).value;

        const isLen   = pwVal.length >= 8;
        const isUpper = /[A-Z]/.test(pwVal);
        const isLower = /[a-z]/.test(pwVal);
        const isNum   = /[0-9]/.test(pwVal);
        const isMatch = (pwVal.length > 0 && pwVal === confirmVal);

        sywatUpdateRuleStatus(prefix + '_len', isLen, '8+ Chars');
        sywatUpdateRuleStatus(prefix + '_upper', isUpper, '1 Upper');
        sywatUpdateRuleStatus(prefix + '_lower', isLower, '1 Lower');
        sywatUpdateRuleStatus(prefix + '_num', isNum, '1 Num');
        sywatUpdateRuleStatus(prefix + '_match', isMatch, 'Passwords Match');
    }

    function sywatUpdateRuleStatus(elementId, isValid, text) {
        const el = document.getElementById(elementId);
        if (!el) return;

        if (isValid) {
            el.style.color = '#166534';
            el.style.fontWeight = '700';
            el.innerHTML = '🟢 ' + text;
        } else {
            el.style.color = '#dc2626';
            el.style.fontWeight = '400';
            el.innerHTML = '🔴 ' + text;
        }
    }

    function sywatOpenAddMemberModal() {
        const modal = document.getElementById('sywat_add_member_modal');
        if (modal) modal.classList.add('active');
    }

    function sywatCloseAddMemberModal() {
        const modal = document.getElementById('sywat_add_member_modal');
        if (modal) modal.classList.remove('active');
    }

    function sywatCloseEditMemberModal() {
        const modal = document.getElementById('sywat_edit_member_modal');
        if (modal) modal.classList.remove('active');
        const url = new URL(window.location.href);
        url.searchParams.delete('edit_member_id');
        window.history.replaceState({}, document.title, url.toString());
    }

    function sywatOpenAddBoardModal() {
        const modal = document.getElementById('sywat_add_board_modal');
        if (modal) modal.classList.add('active');
    }

    function sywatCloseAddBoardModal() {
        const modal = document.getElementById('sywat_add_board_modal');
        if (modal) modal.classList.remove('active');
    }

    function sywatCloseEditBoardModal() {
        const modal = document.getElementById('sywat_edit_board_modal');
        if (modal) modal.classList.remove('active');
        const url = new URL(window.location.href);
        url.searchParams.delete('edit_board_id');
        window.history.replaceState({}, document.title, url.toString());
    }

    function sywatApplyMemberSearch() {
        const input = document.getElementById('sywat_member_search_input');
        const query = input ? input.value.toLowerCase().trim() : '';
        const rows  = document.querySelectorAll('.sywat-member-row');

        rows.forEach(row => {
            const searchData = (row.getAttribute('data-search') || '').toLowerCase();
            if (!query || searchData.includes(query)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function sywatResetMemberSearch() {
        const input = document.getElementById('sywat_member_search_input');
        if (input) input.value = '';
        sywatApplyMemberSearch();
    }

    function sywatApplyBoardSearch() {
        const input = document.getElementById('sywat_board_search_input');
        const query = input ? input.value.toLowerCase().trim() : '';
        const rows  = document.querySelectorAll('.sywat-board-row');

        rows.forEach(row => {
            const searchData = (row.getAttribute('data-search') || '').toLowerCase();
            if (!query || searchData.includes(query)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function sywatResetBoardSearch() {
        const input = document.getElementById('sywat_board_search_input');
        if (input) input.value = '';
        sywatApplyBoardSearch();
    }

    document.addEventListener("DOMContentLoaded", function() {
        const notice = document.getElementById('sywat-notice');
        if (notice) {
            setTimeout(function() {
                notice.style.transition = "opacity 0.5s ease";
                notice.style.opacity = "0";
                setTimeout(function() { notice.remove(); }, 500);
            }, 5000);
        }
    });
    </script>
    <?php
}
