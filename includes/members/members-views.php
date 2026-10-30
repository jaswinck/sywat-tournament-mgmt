<?php
/**
 * Sub-Module: Member Management Portal View & Modals
 * Path: includes/members/members-views.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_members_portal' ) ) {
    function sywat_render_members_portal() {
        // Enforce Permission Check
        if ( ! function_exists( 'sywat_can_create_members' ) || ! sywat_can_create_members() ) {
            return '<div class="sywat-wrap" style="max-width: 600px; margin: 20px auto;"><div class="sywat-alert sywat-alert-danger">Access denied. Member management is restricted to TFAA Administrators and Webmasters.</div></div>';
        }

        $notice = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_member_action'] ) ) {
            $notice = sywat_handle_member_post_actions();
        }

        $members = function_exists( 'sywat_get_all_tfaa_members' ) ? sywat_get_all_tfaa_members() : array();

        $edit_target_id = isset( $_REQUEST['edit_member_id'] ) ? intval( $_REQUEST['edit_member_id'] ) : 0;
        $edit_user_obj  = ( $edit_target_id > 0 ) ? get_userdata( $edit_target_id ) : null;
        $open_edit_modal = ( $edit_user_obj !== null );

        ob_start();
        ?>
        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <div class="sywat-card no-print">
                <div class="sywat-card-title">
                    <span>TFAA Member Directory</span>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <div style="display: flex; gap: 5px; margin: 0; align-items: center; flex-wrap: nowrap;">
                            <input type="text" id="sywat_member_search_input" oninput="sywatApplyMemberSearch()" placeholder="Search members..." autocomplete="off" style="padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 180px; height: 32px; box-sizing: border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height: 32px; padding: 4px 8px;" onclick="sywatResetMemberSearch()">Clear</button>
                        </div>
                        <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="sywatOpenAddMemberModal()">Add Member</button>
                    </div>
                </div>

                <div class="sywat-subtitle">Overview of registered TFAA members, contact details, and NFAA membership status.</div>

                <?php if ( empty( $members ) ): ?>
                    <p style="color: #646970; font-style: italic; padding: 20px 0;">No members found in system database.</p>
                <?php else: ?>
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th style="width: 15%; text-align: center;">TFAA Member #</th>
                                <th style="width: 25%;">Member Name</th>
                                <th style="width: 25%;">Email Address</th>
                                <th style="width: 15%; text-align: center;">Phone</th>
                                <th style="width: 10%; text-align: center;">NFAA Status</th>
                                <th style="width: 10%; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $members as $m ): ?>
                                <?php 
                                    $m_tfaa   = get_user_meta( $m->ID, 'sywat_tfaa_number', true );
                                    $m_phone  = get_user_meta( $m->ID, 'sywat_phone', true );
                                    $m_nfaa   = get_user_meta( $m->ID, 'sywat_is_nfaa_member', true );
                                    $disp_id  = ! empty( $m_tfaa ) ? $m_tfaa : '—';
                                    $is_nfaa_active = ( $m_nfaa === 'yes' );
                                ?>
                                <tr class="sywat-member-row" data-search="<?php echo esc_attr( strtolower( $m->display_name . ' ' . $m->user_email . ' ' . $disp_id . ' ' . $m_phone ) ); ?>">
                                    <td style="text-align: center;">
                                        <strong style="color: #1e3a8a; font-family: monospace; font-size: 13px;"><?php echo esc_html( $disp_id ); ?></strong>
                                    </td>
                                    <td><strong><?php echo esc_html( $m->display_name ); ?></strong></td>
                                    <td><a href="mailto:<?php echo esc_attr( $m->user_email ); ?>" style="color: #2271b1; text-decoration: none;"><?php echo esc_html( $m->user_email ); ?></a></td>
                                    <td style="text-align: center;"><?php echo esc_html( ! empty( $m_phone ) ? $m_phone : '—' ); ?></td>
                                    <td style="text-align: center;">
                                        <?php if ( $is_nfaa_active ): ?>
                                            <span style="background: #dcfce7; color: #166534; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 12px; display: inline-block;">🟢 NFAA Yes</span>
                                        <?php else: ?>
                                            <span style="background: #f1f5f9; color: #64748b; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 12px; display: inline-block;">⚪ NFAA No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="?tab=members&edit_member_id=<?php echo $m->ID; ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit" style="font-size: 11px; padding: 3px 8px;">Edit</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- MODAL 1: ADD NEW MEMBER MODAL -->
            <div class="sywat-modal-backdrop" id="sywat_add_member_modal">
                <div class="sywat-modal-content" style="max-width: 600px;">
                    <div class="sywat-modal-header no-print">
                        <h3>Create New Member Account</h3>
                        <button type="button" class="sywat-modal-close" onclick="sywatCloseAddMemberModal()">&times;</button>
                    </div>
                    <div class="sywat-modal-body">
                        <form method="post" action="?tab=members">
                            <?php wp_nonce_field( 'sywat_member_action_nonce', 'sywat_member_nonce' ); ?>
                            <input type="hidden" name="sywat_member_action" value="create_member">

                            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; margin-bottom: 15px; font-size: 12px; color: #1e3a8a;">
                                🆔 <strong>TFAA Member ID:</strong> System auto-generates sequentially upon creation (e.g., <code>TFAA-10001</code>).
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

                            <div style="margin-bottom: 20px;">
                                <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Initial Password * (Minimum 8 characters)</label>
                                <input type="password" name="password" required style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
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
                            <form method="post" action="?tab=members">
                                <?php wp_nonce_field( 'sywat_member_action_nonce', 'sywat_member_nonce' ); ?>
                                <input type="hidden" name="sywat_member_action" value="update_member">
                                <input type="hidden" name="target_user_id" value="<?php echo $edit_user_obj->ID; ?>">

                                <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; margin-bottom: 15px; font-size: 13px; color: #334155;">
                                    🆔 <strong>TFAA Member #:</strong> <span style="font-family: monospace; font-weight: bold; color: #1e3a8a;"><?php echo esc_html( ! empty( $e_tfaa ) ? $e_tfaa : 'Unassigned' ); ?></span>
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

                                <div style="margin-bottom: 20px;">
                                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px;">Reset Password (Leave blank to keep current password)</label>
                                    <input type="password" name="new_password" placeholder="New password..." style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; box-sizing:border-box;">
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
