<?php
/**
 * Sub-Module: Member & Board Management Portal Views
 * Path: includes/members/members-views.php
 * Author: SYWAT Archery
 * Version: 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_members_portal' ) ) {
    function sywat_render_members_portal() {
        // Enforce Central Permission Check
        if ( ! function_exists( 'sywat_can_create_members' ) || ! sywat_can_create_members() ) {
            return '<div class="sywat-wrap" style="max-width: 600px; margin: 20px auto;"><div class="sywat-alert sywat-alert-danger">Access denied. Member management is restricted to authorized TFAA Officers and Administrators.</div></div>';
        }

        $notice = '';
        $subtab = isset( $_REQUEST['subtab'] ) ? sanitize_text_field( $_REQUEST['subtab'] ) : 'directory';

        // POST Action Handling
        $is_valid_nonce = ( isset( $_POST['sywat_nonce'] ) && wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_desk_action' ) );
        $action         = isset( $_POST['sywat_action'] ) ? sanitize_text_field( $_POST['sywat_action'] ) : '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $action && ! $is_valid_nonce ) {
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        } elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce ) {
            if ( function_exists( 'sywat_handle_member_post_actions' ) ) {
                $notice = sywat_handle_member_post_actions();
            }
        }

        $raw_members     = function_exists( 'sywat_get_all_tfaa_members' ) ? sywat_get_all_tfaa_members() : array();
        $tfaa_role_slugs = function_exists( 'sywat_get_custom_roles_slugs' ) ? sywat_get_custom_roles_slugs() : array();

        // Filter directory list to only include accounts with active TFAA roles (excludes standalone admins)
        $all_members = array();
        foreach ( $raw_members as $m_user ) {
            $u_roles = (array) $m_user->roles;
            if ( ! empty( array_intersect( $tfaa_role_slugs, $u_roles ) ) ) {
                $all_members[] = $m_user;
            }
        }

        $board_members       = function_exists( 'sywat_get_all_board_members' ) ? sywat_get_all_board_members() : array();
        $all_board_positions = function_exists( 'sywat_get_tfaa_board_positions_list' ) ? sywat_get_tfaa_board_positions_list() : array();

        $edit_target_id  = isset( $_REQUEST['edit_member_id'] ) ? intval( $_REQUEST['edit_member_id'] ) : 0;
        $edit_user_obj   = ( $edit_target_id > 0 ) ? get_userdata( $edit_target_id ) : null;

        $edit_board_id       = isset( $_REQUEST['edit_board_id'] ) ? intval( $_REQUEST['edit_board_id'] ) : 0;
        $edit_board_user_obj = ( $edit_board_id > 0 ) ? get_userdata( $edit_board_id ) : null;

        global $wp_roles;
        $wp_role_names  = $wp_roles ? $wp_roles->get_names() : array();
        $excluded_roles = array(
            'administrator', 'editor', 'author', 'contributor', 'subscriber',
            'management', 'staff_shooter', 'staff-shooter', 'non-shooting-member', 'non_shooting_member',
        );

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
                            <div style="display: flex; gap: 5px; margin: 0; align-items: center; flex-wrap: wrap;">
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
                        <div class="sywat-table-responsive">
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
                                            $bm_phone   = get_user_meta( $bm->ID, 'sywat_phone', true );
                                            $pos_labels = sywat_get_user_board_position_labels( $bm->ID );
                                            $pos_search = implode( ' ', $pos_labels );
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
                                                        <form method="post" action="?tab=members&subtab=board" style="margin: 0;" onsubmit="return confirm('Are you sure you want to remove \'<?php echo esc_js( $bm->display_name ); ?>\' from the Board of Directors?');">
                                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                            <input type="hidden" name="sywat_action" value="remove_board_member">
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
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- SUB-TAB 1: MEMBER DIRECTORY -->
                <div class="sywat-card no-print">
                    <div class="sywat-card-title">
                        <span>Member Directory</span>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; gap: 5px; margin: 0; align-items: center; flex-wrap: wrap;">
                                <input type="text" id="sywat_member_search_input" oninput="sywatApplyMemberSearch()" placeholder="Search members..." autocomplete="off" style="padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 180px; height: 32px; box-sizing: border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height: 32px; padding: 4px 8px;" onclick="sywatResetMemberSearch()">Clear</button>
                            </div>
                            <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="sywatOpenAddMemberModal()">Add Member</button>
                        </div>
                    </div>

                    <div class="sywat-subtitle">Overview of registered TFAA members, contact details, assigned roles, and NFAA membership status.</div>

                    <?php if ( empty( $all_members ) ): ?>
                        <p style="color: #646970; font-style: italic; padding: 20px 0;">No TFAA members found in system database.</p>
                    <?php else: ?>
                        <div class="sywat-table-responsive">
                            <table class="sywat-table">
                                <thead>
                                    <tr>
                                        <th style="width: 12%; text-align: center;">TFAA Member #</th>
                                        <th style="width: 18%;">Member Name</th>
                                        <th style="width: 20%;">Assigned Role(s)</th>
                                        <th style="width: 20%;">Email Address</th>
                                        <th style="width: 11%; text-align: center;">Phone</th>
                                        <th style="width: 9%; text-align: center;">NFAA Status</th>
                                        <th style="width: 10%; text-align: right;">Actions</th>
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

                                            // Role Badges Formatting
                                            $m_roles     = (array) $m->roles;
                                            $role_badges = array();
                                            $role_names  = array();
                                            foreach ( $m_roles as $r_slug ) {
                                                if ( in_array( strtolower( $r_slug ), $excluded_roles, true ) ) continue;
                                                
                                                $r_name = isset( $wp_role_names[$r_slug] ) ? $wp_role_names[$r_slug] : ucfirst( str_replace( '_', ' ', $r_slug ) );
                                                
                                                // Fail-safe cleanup: Strip any legacy "TFAA " or "SYWAT " prefixes
                                                $r_name = trim( str_replace( array( 'TFAA ', 'SYWAT ' ), '', $r_name ) );

                                                $role_names[]  = $r_name;
                                                $role_badges[] = '<span class="sywat-badge sywat-badge-prereg" style="font-size: 10px; padding: 2px 6px;">' . esc_html( $r_name ) . '</span>';
                                            }
                                            $roles_html   = ! empty( $role_badges ) ? implode( ' ', $role_badges ) : '<span style="color: #94a3b8; font-size: 11px; font-style: italic;">No Role</span>';
                                            $roles_search = implode( ' ', $role_names );
                                        ?>
                                        <tr class="sywat-member-row" data-search="<?php echo esc_attr( strtolower( $m->display_name . ' ' . $m->user_login . ' ' . $m->user_email . ' ' . $disp_id . ' ' . $m_phone . ' ' . $roles_search ) ); ?>">
                                            <td style="text-align: center;">
                                                <strong style="color: #1e3a8a; font-family: monospace; font-size: 13px;"><?php echo esc_html( $disp_id ); ?></strong>
                                            </td>
                                            <td>
                                                <strong><?php echo esc_html( $m->display_name ); ?></strong>
                                                <div style="font-size: 11px; color: #64748b; font-family: monospace; margin-top: 1px;">👤 <?php echo esc_html( $m->user_login ); ?></div>
                                            </td>
                                            <td>
                                                <div style="display: flex; flex-wrap: wrap; gap: 3px;">
                                                    <?php echo $roles_html; ?>
                                                </div>
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
                                                        <form method="post" action="?tab=members&subtab=directory" style="margin: 0;" onsubmit="return confirm('Are you sure you want to permanently delete member \'<?php echo esc_js( $m->display_name ); ?>\'? This action cannot be undone.');">
                                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                            <input type="hidden" name="sywat_action" value="delete_member">
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
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php 
            // Render Modals & Client JavaScript via Sub-Module
            if ( function_exists( 'sywat_members_render_modals' ) ) {
                sywat_members_render_modals( array(
                    'all_members'         => $all_members,
                    'all_board_positions' => $all_board_positions,
                    'edit_user_obj'       => $edit_user_obj,
                    'edit_board_user_obj' => $edit_board_user_obj,
                    'excluded_roles'      => $excluded_roles,
                ) );
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
