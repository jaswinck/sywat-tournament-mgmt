<?php
/**
 * Sub-Module: Member & Board Management Portal Views & Modals
 * Path: includes/members/members-views.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_members_portal' ) ) {
    function sywat_render_members_portal() {
        // Enforce Central Permission Check
        if ( ! function_exists( 'sywat_can_create_members' ) || ! sywat_can_create_members() ) {
            return '<div class="sywat-wrap" style="max-width: 600px; margin: 20px auto;"><div class="sywat-alert sywat-alert-danger">Access denied. Member management is restricted to authorized TFAA Officers and Administrators.</div></div>';
        }

        $notice = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_member_action'] ) ) {
            $notice = sywat_handle_member_post_actions();
        }

        $subtab              = isset( $_REQUEST['subtab'] ) ? sanitize_text_field( $_REQUEST['subtab'] ) : 'directory';
        $all_members         = function_exists( 'sywat_get_all_tfaa_members' ) ? sywat_get_all_tfaa_members() : array();
        $board_members       = function_exists( 'sywat_get_all_board_members' ) ? sywat_get_all_board_members() : array();
        $all_board_positions = function_exists( 'sywat_get_tfaa_board_positions_list' ) ? sywat_get_tfaa_board_positions_list() : array();

        $edit_target_id  = isset( $_REQUEST['edit_member_id'] ) ? intval( $_REQUEST['edit_member_id'] ) : 0;
        $edit_user_obj   = ( $edit_target_id > 0 ) ? get_userdata( $edit_target_id ) : null;
        $open_edit_modal = ( $edit_user_obj !== null );

        $edit_board_id       = isset( $_REQUEST['edit_board_id'] ) ? intval( $_REQUEST['edit_board_id'] ) : 0;
        $edit_board_user_obj = ( $edit_board_id > 0 ) ? get_userdata( $edit_board_id ) : null;
        $open_edit_board_modal = ( $edit_board_user_obj !== null );

        ob_start();
        ?>
        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <!-- Member Sub-System Navigation Tabs -->
            <div class="sywat-nav-tabs no-print">
                <a href="?tab=members&subtab=directory" class="sywat-tab-item sywat-tab-icon-member <?php echo $subtab === 'directory' ? 'active' : ''; ?>">Member Directory</a>
                <a href="?tab=members&subtab=board" class="sywat-tab-item sywat-tab-icon-board <?php echo $subtab === 'board' ? 'active' : ''; ?>">Board Management</a>
            </div>

            <?php if ( $subtab === 'board' ): ?>
                <!-- SUB-TAB 2: BOARD MANAGEMENT -->
                <div class="sywat-card no-print">
                    <div class="sywat-card-title">
                        <span>TFAA Board of Directors</span>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; gap: 5px; margin: 0; align-items: center; flex-wrap: nowrap;">
                                <input type="text" id="sywat_board_search_input" oninput="sywatApplyBoardSearch()" placeholder="Search board members..." autocomplete="off" style="padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 180px; height: 32px; box-sizing: border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height: 32px; padding: 4px 8px;" onclick="sywatResetBoardSearch()">Clear</button>
                            </div>
                            <?php if ( function_exists( 'sywat_can_manage_board' ) && sywat_can_manage_board() ): ?>
                                <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="sywatOpenAddBoardModal()">Add Board Member</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sywat-subtitle">Overview of official TFAA Board Officers, regional Field Governors, and position assignments.</div>

                    <?php if ( empty( $board_members ) ): ?>
                        <p style="color: #646970; font-style: italic; padding: 20px 0;">No board members currently assigned. Click <strong>"+ Add Board Member"</strong> to assign positions.</p>
                    <?php else: ?>
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th style="width: 22%;">Board Member Name</th>
                                    <th style="width: 32%;">Assigned Position(s)</th>
                                    <th style="width: 22%;">Email Address</th>
                                    <th style="width: 14%; text-align: center;">Phone</th>
                                    <th style="width: 10%; text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $board_members as $bm ): ?>
                                    <?php 
                                        $bm_phone    = get_user_meta( $bm->ID, 'sywat_phone', true );
                                        $pos_labels  = sywat_get_user_board_position_labels( $bm->ID );
                                        $pos_search  = implode( ' ', $pos_labels );
                                    ?>
                                    <tr class="sywat-board-row" data-search="<?php echo esc_attr( strtolower( $bm->display_name . ' ' . $bm->user_login . ' ' . $bm->user_email . ' ' . $pos_search . ' ' . $bm_phone ) ); ?>">
                                        <td>
                                            <strong><?php echo esc_html( $bm->display_name ); ?></strong>
                                            <div style="font-size: 11px; color: #64748b; font-family: monospace; margin-top: 1px;">👤 <?php echo esc_html( $bm->user_login ); ?></div>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                                <?php foreach ( $pos_labels as $p_key => $p_name ): ?>
                                                    <span style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 12px; display: inline-block;">
                                                        🏛️ <?php echo esc_html( $p_name ); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td><a href="mailto:<?php echo esc_attr( $bm->user_email ); ?>" style="color: #2271b1; text-decoration: none;"><?php echo esc_html( $bm->user_email ); ?></a></td>
                                        <td style="text-align: center;"><?php echo esc_html( ! empty( $bm_phone ) ? $bm_phone : '—' ); ?></td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 4px; justify-content: flex-end; align-items: center;">
                                                <a href="?tab=members&subtab=board&edit_board_id=<?php echo $bm->ID; ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit" style="font-size: 11px; padding: 3px 8px; height: 26px;">Edit</a>
                                                <?php if ( function_exists( 'sywat_can_manage_board' ) && sywat_can_manage_board() ): ?>
                                                    <form method="post" action="" style="margin: 0;" onsubmit="return confirm('Are you sure you want to remove \'<?php echo esc_js( $bm->display_name ); ?>\' from the Board of Directors?');">
                                                        <?php wp_nonce_field( 'sywat_member_action_nonce', 'sywat_member_nonce' ); ?>
                                                        <input type="hidden" name="sywat_member_action" value="remove_board_member">
                                                        <input type="hidden" name="target_user_id" value="<?php echo $bm->ID; ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="font-size: 11px; padding: 3px 8px; height: 26px;">Remove</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- SUB-TAB 1: MEMBER DIRECTORY -->
                <div class="sywat-card no-print">
                    <div class="sywat-card-title">
                        <span>Member Directory</span>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; gap: 5px; margin: 0; align-items: center; flex-wrap: nowrap;">
                                <input type="text" id="sywat_member_search_input" oninput="sywatApplyMemberSearch()" placeholder="Search members..." autocomplete="off" style="padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 180px; height: 32px; box-sizing: border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height: 32px; padding: 4px 8px;" onclick="sywatResetMemberSearch()">Clear</button>
                            </div>
                            <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="sywatOpenAddMemberModal()">Add Member</button>
                        </div>
                    </div>

                    <div class="sywat-subtitle">Overview of registered TFAA members, contact details, and NFAA membership status.</div>

                    <?php if ( empty( $all_members ) ): ?>
                        <p style="color: #646970; font-style: italic; padding: 20px 0;">No members found in system database.</p>
                    <?php else: ?>
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th style="width: 14%; text-align: center;">TFAA Member #</th>
                                    <th style="width: 22%;">Member Name</th>
                                    <th style="width: 24%;">Email Address</th>
                                    <th style="width: 14%; text-align: center;">Phone</th>
                                    <th style="width: 10%; text-align: center;">NFAA Status</th>
                                    <th style="width: 16%; text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $all_members as $m ): ?>
                                    <?php 
                                        $m_tfaa   = get_user_meta( $m->ID, 'sywat_tfaa_number', true );
                                        $m_phone  = get_user_meta( $m->ID, 'sywat_phone', true );
                                        $m_nfaa   = get_user_meta( $m->ID, 'sywat_is_nfaa_member', true );
                                        $disp_id  = ! empty( $m_tfaa ) ? $m_tfaa : '—';
                                        $is_nfaa_active = ( $m_nfaa === 'yes' );
                                    ?>
                                    <tr class="sywat-member-row" data-search="<?php echo esc_attr( strtolower( $m->display_name . ' ' . $m->user_login . ' ' . $m->user_email . ' ' . $disp_id . ' ' . $m_phone ) ); ?>">
                                        <td style="text-align: center;">
                                            <strong style="color: #1e3a8a; font-family: monospace; font-size: 13px;"><?php echo esc_html( $disp_id ); ?></strong>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html( $m->display_name ); ?></strong>
                                            <div style="font-size: 11px; color: #64748b; font-family: monospace; margin-top: 1px;">👤 <?php echo esc_html( $m->user_login ); ?></div>
                                        </td>
                                        <td><a href="mailto:<?php echo esc_attr( $m->user_email ); ?>" style="color: #2271b1; text-decoration: none;"><?php echo esc_html( $m->user_email ); ?></a></td>
                                        <td style="text-align: center;"><?php echo esc_html( ! empty( $m_phone ) ? $m_phone : '—' ); ?></td>
                                        <td style="text-align: center;">
                                            <?php if ( $is_nfaa_active ): ?>
                                                <span style="background: #dcfce7; color: #166534; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 12px; display: inline-block;">🟢 NFAA Yes</span>
                                            <?php else: ?>
                                                <span style="background: #f1f5f9; color: #646970; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 12px; display: inline-block;">⚪ NFAA No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 4px; justify-content: flex-end; align-items: center;">
                                                <a href="?tab=members&subtab=directory&edit_member_id=<?php echo $m->ID; ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit" style="font-size: 11px; padding: 3px 8px; height: 26px;">Edit</a>
                                                <?php if ( get_current_user_id() !== $m->ID ): ?>
                                                    <form method="post" action="" style="margin: 0;" onsubmit="return confirm('Are you sure you want to permanently delete member \'<?php echo esc_js( $m->display_name ); ?>\'? This action cannot be undone.');">
                                                        <?php wp_nonce_field( 'sywat_member_action_nonce', 'sywat_member_nonce' ); ?>
                                                        <input type="hidden" name="sywat_member_action" value="delete_member">
                                                        <input type="hidden" name="target_user_id" value="<?php echo $m->ID; ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="font-size: 11px; padding: 3px 8px; height: 26px;">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- MODAL 1: ADD NEW MEMBER MODAL -->
            <div class="sywat-modal-backdrop" id="sywat_add_member_modal">
                <div class="sywat-modal-content" style="max-width: 600px;">
                    <div class="sywat-modal-header no-print">
                        <h3>Create New Member Account</h3>
                        <button type="button" class="sywat-modal-close" onclick="sywatCloseAddMemberModal()">&times;</button>
                    </div>
                    <div class="sywat-modal-body">
                        <form method="post" action="">
                            <?php wp_nonce_field( 'sywat_member_action_nonce', 'sywat_member_nonce' ); ?>
                            <input type="hidden" name="sywat_member_action" value="create_member">

                            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; margin-bottom: 15px; font-size: 12px; color: #1e3a8a;">
                                🆔 <strong>TFAA Member ID & Username:</strong> Username is auto-formatted as <code>Firstname.Lastname</code> and Member ID is auto-generated sequentially.
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                <div>
                                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">First Name *</label>
                                    <input type="text" name="first_name" required style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Last Name *</label>
                                    <input type="text" name="last_name" required style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                </div>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Email Address *</label>
                                <input type="email" name="email" required style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                <div>
                                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Phone Number</label>
                                    <input type="text" name="phone" oninput="sywatFormatPhoneInput(this)" placeholder="XXX-XXX-XXXX" maxlength="12" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">NFAA Member Status</label>
                                    <select name="is_nfaa_member" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; height:37px; box-sizing:border-box;">
                                        <option value="no">No</option>
                                        <option value="yes">Yes</option>
                                    </select>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 8px;">
                                <div>
                                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Initial Password *</label>
                                    <input type="password" id="sywat_add_pw" name="password" required oninput="sywatValidatePasswordRules('sywat_add_pw', 'sywat_add_pw_confirm', 'add_rule')" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Confirm Password *</label>
                                    <input type="password" id="sywat_add_pw_confirm" name="confirm_password" required oninput="sywatValidatePasswordRules('sywat_add_pw', 'sywat_add_pw_confirm', 'add_rule')" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                </div>
                            </div>

                            <div style="margin-bottom: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px;">
                                <div style="font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Password Requirements</div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 12px; color: #64748b;">
                                    <div id="add_rule_len">🔴 8+ Characters</div>
                                    <div id="add_rule_upper">🔴 1 Uppercase (A-Z)</div>
                                    <div id="add_rule_lower">🔴 1 Lowercase (a-z)</div>
                                    <div id="add_rule_num">🔴 1 Number (0-9)</div>
                                    <div id="add_rule_match" style="grid-column: span 2;">🔴 Passwords Match</div>
                                </div>
                            </div>

                            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseAddMemberModal()">Cancel</button>
                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px; padding:8px 16px;">Save Member Profile</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- MODAL 2: EDIT MEMBER MODAL -->
            <div class="sywat-modal-backdrop <?php echo $open_edit_modal ? 'active' : ''; ?>" id="sywat_edit_member_modal">
                <?php if ( $edit_user_obj ): ?>
                    <?php 
                        $e_tfaa  = get_user_meta( $edit_user_obj->ID, 'sywat_tfaa_number', true );
                        $e_phone = get_user_meta( $edit_user_obj->ID, 'sywat_phone', true );
                        $e_nfaa  = get_user_meta( $edit_user_obj->ID, 'sywat_is_nfaa_member', true );
                    ?>
                    <div class="sywat-modal-content" style="max-width: 600px;">
                        <div class="sywat-modal-header no-print">
                            <h3>Edit Member: <?php echo esc_html( $edit_user_obj->display_name ); ?></h3>
                            <button type="button" class="sywat-modal-close" onclick="sywatCloseEditMemberModal()">&times;</button>
                        </div>
                        <div class="sywat-modal-body">
                            <form method="post" action="">
                                <?php wp_nonce_field( 'sywat_member_action_nonce', 'sywat_member_nonce' ); ?>
                                <input type="hidden" name="sywat_member_action" value="update_member">
                                <input type="hidden" name="target_user_id" value="<?php echo $edit_user_obj->ID; ?>">

                                <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; margin-bottom: 15px; font-size: 13px; color: #334155; display: flex; justify-content: space-between;">
                                    <span>🆔 <strong>TFAA Member #:</strong> <span style="font-family: monospace; font-weight: bold; color: #1e3a8a;"><?php echo esc_html( ! empty( $e_tfaa ) ? $e_tfaa : 'Unassigned' ); ?></span></span>
                                    <span>👤 <strong>Username:</strong> <span style="font-family: monospace; font-weight: bold; color: #334155;"><?php echo esc_html( $edit_user_obj->user_login ); ?></span></span>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                    <div>
                                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">First Name *</label>
                                        <input type="text" name="first_name" value="<?php echo esc_attr( $edit_user_obj->first_name ); ?>" required style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Last Name *</label>
                                        <input type="text" name="last_name" value="<?php echo esc_attr( $edit_user_obj->last_name ); ?>" required style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                    </div>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Email Address *</label>
                                    <input type="email" name="email" value="<?php echo esc_attr( $edit_user_obj->user_email ); ?>" required style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                    <div>
                                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Phone Number</label>
                                        <input type="text" name="phone" value="<?php echo esc_attr( $e_phone ); ?>" oninput="sywatFormatPhoneInput(this)" placeholder="XXX-XXX-XXXX" maxlength="12" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">NFAA Member Status</label>
                                        <select name="is_nfaa_member" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; height:37px; box-sizing:border-box;">
                                            <option value="no" <?php selected( $e_nfaa, 'no' ); ?>>No</option>
                                            <option value="yes" <?php selected( $e_nfaa, 'yes' ); ?>>Yes</option>
                                        </select>
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 8px;">
                                    <div>
                                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Reset Password (Optional)</label>
                                        <input type="password" id="sywat_edit_pw" name="new_password" placeholder="Leave blank to keep..." oninput="sywatValidatePasswordRules('sywat_edit_pw', 'sywat_edit_pw_confirm', 'edit_rule')" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Confirm New Password</label>
                                        <input type="password" id="sywat_edit_pw_confirm" name="confirm_new_password" placeholder="Confirm password..." oninput="sywatValidatePasswordRules('sywat_edit_pw', 'sywat_edit_pw_confirm', 'edit_rule')" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
                                    </div>
                                </div>

                                <div style="margin-bottom: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px;">
                                    <div style="font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">New Password Requirements</div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 12px; color: #64748b;">
                                        <div id="edit_rule_len">⚪ 8+ Characters</div>
                                        <div id="edit_rule_upper">⚪ 1 Uppercase (A-Z)</div>
                                        <div id="edit_rule_lower">⚪ 1 Lowercase (a-z)</div>
                                        <div id="edit_rule_num">⚪ 1 Number (0-9)</div>
                                        <div id="edit_rule_match" style="grid-column: span 2;">⚪ Passwords Match</div>
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseEditMemberModal()">Cancel</button>
                                    <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px; padding:8px 16px;">Update Member Profile</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- MODAL 3: ADD / ASSIGN BOARD MEMBER MODAL -->
            <div class="sywat-modal-backdrop" id="sywat_add_board_modal">
                <div class="sywat-modal-content" style="max-width: 600px;">
                    <div class="sywat-modal-header no-print">
                        <h3>Assign Board Member Positions</h3>
                        <button type="button" class="sywat-modal-close" onclick="sywatCloseAddBoardModal()">&times;</button>
                    </div>
                    <div class="sywat-modal-body">
                        <form method="post" action="">
                            <?php wp_nonce_field( 'sywat_member_action_nonce', 'sywat_member_nonce' ); ?>
                            <input type="hidden" name="sywat_member_action" value="save_board_member">

                            <div style="margin-bottom: 15px;">
                                <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Select Member *</label>
                                <select name="board_user_id" required style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; height:38px; box-sizing:border-box;">
                                    <option value="">-- Choose Member from Directory --</option>
                                    <?php foreach ( $all_members as $m_item ): ?>
                                        <option value="<?php echo $m_item->ID; ?>">
                                            <?php echo esc_html( $m_item->display_name ); ?> (<?php echo esc_html( $m_item->user_email ); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:8px;">Assign Board Position(s) *</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 20px; background: #f8fafc; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                <?php foreach ( $all_board_positions as $pos_key => $pos_title ): ?>
                                    <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="checkbox" name="board_positions[]" value="<?php echo esc_attr( $pos_key ); ?>">
                                        <?php echo esc_html( $pos_title ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseAddBoardModal()">Cancel</button>
                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px; padding:8px 16px;">Save Board Assignment</button>
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
                    <div class="sywat-modal-content" style="max-width: 600px;">
                        <div class="sywat-modal-header no-print">
                            <h3>Edit Board Member: <?php echo esc_html( $edit_board_user_obj->display_name ); ?></h3>
                            <button type="button" class="sywat-modal-close" onclick="sywatCloseEditBoardModal()">&times;</button>
                        </div>
                        <div class="sywat-modal-body">
                            <form method="post" action="">
                                <?php wp_nonce_field( 'sywat_member_action_nonce', 'sywat_member_nonce' ); ?>
                                <input type="hidden" name="sywat_member_action" value="save_board_member">
                                <input type="hidden" name="board_user_id" value="<?php echo $edit_board_user_obj->ID; ?>">

                                <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; margin-bottom: 15px; font-size: 13px; color: #334155; display: flex; justify-content: space-between;">
                                    <span>👤 <strong>Name:</strong> <?php echo esc_html( $edit_board_user_obj->display_name ); ?></span>
                                    <span>✉️ <strong>Email:</strong> <?php echo esc_html( $edit_board_user_obj->user_email ); ?></span>
                                </div>

                                <label style="display:block; font-weight:600; font-size:13px; margin-bottom:8px;">Assigned Position(s)</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 20px; background: #f8fafc; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                    <?php foreach ( $all_board_positions as $pos_key => $pos_title ): ?>
                                        <label style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                            <input type="checkbox" name="board_positions[]" value="<?php echo esc_attr( $pos_key ); ?>" <?php checked( in_array( $pos_key, $cur_positions ), true ); ?>>
                                            <?php echo esc_html( $pos_title ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatCloseEditBoardModal()">Cancel</button>
                                    <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px; padding:8px 16px;">Update Positions</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
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

            sywatUpdateRuleStatus(prefix + '_len', isLen, '8+ Characters');
            sywatUpdateRuleStatus(prefix + '_upper', isUpper, '1 Uppercase (A-Z)');
            sywatUpdateRuleStatus(prefix + '_lower', isLower, '1 Lowercase (a-z)');
            sywatUpdateRuleStatus(prefix + '_num', isNum, '1 Number (0-9)');
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
        return ob_get_clean();
    }
}
