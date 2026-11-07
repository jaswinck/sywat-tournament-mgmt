<?php
/**
 * Sub-Module: Member & Board Management Portal Views
 * Path: includes/members/members-views.php
 * Description: Renders UI structures for Member Directory and Board Management tables.
 * Compliance: Zero inline style attributes. Styling driven by centralized SYWAT CSS classes.
 * Author: SYWAT Archery
 * Version: 3.0.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_members_portal' ) ) {
    /**
     * Master render function for the Member Management Sub-Module.
     *
     * @return string Buffered HTML output for the member portal view.
     */
    function sywat_render_members_portal() {
        // Enforce Central Permission Check
        if ( ! function_exists( 'sywat_can_create_members' ) || ! sywat_can_create_members() ) {
            return '<div class="sywat-wrap sywat-wrap-sm sywat-mt-4"><div class="sywat-alert sywat-alert-danger">Access denied. Member management is restricted to authorized TFAA Officers and Administrators.</div></div>';
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

        // Include accounts with active TFAA roles or Administrator role
        $allowed_directory_roles = array_merge( (array) $tfaa_role_slugs, array( 'administrator' ) );
        $all_members = array();
        foreach ( $raw_members as $m_user ) {
            $u_roles = (array) $m_user->roles;
            if ( ! empty( array_intersect( $allowed_directory_roles, $u_roles ) ) ) {
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
            'editor', 'author', 'contributor', 'subscriber',
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
                    <div class="sywat-card-header-bar">
                        <div>
                            <h2>🏛️ TFAA Board of Directors</h2>
                            <div class="sywat-subtitle">Overview of official TFAA Board Officers, regional Field Governors, and position assignments.</div>
                        </div>
                        <div class="sywat-header-inline-controls">
                            <?php if ( function_exists( 'sywat_can_manage_board' ) && sywat_can_manage_board() ): ?>
                                <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="sywatOpenAddBoardModal()">Add Board Member</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sywat-table-header-bar">
                        <div class="sywat-table-header-controls sywat-ml-0">
                            <div class="sywat-search-input-group">
                                <input type="text" id="sywat_board_search_input" oninput="sywatApplyBoardSearch()" placeholder="Search board members..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetBoardSearch()">Clear</button>
                            </div>
                        </div>
                    </div>

                    <?php if ( empty( $board_members ) ): ?>
                        <p class="sywat-empty-state-notice">No board members currently assigned. Click <strong>"+ Add Board Member"</strong> to assign positions.</p>
                    <?php else: ?>
                        <div class="sywat-table-responsive">
                            <table class="sywat-table">
                                <thead>
                                    <tr>
                                        <th class="sywat-col-w-22">Board Member Name</th>
                                        <th class="sywat-col-w-32">Assigned Position(s)</th>
                                        <th class="sywat-col-w-22">Email Address</th>
                                        <th class="sywat-col-center sywat-col-w-14">Phone</th>
                                        <th class="sywat-col-right sywat-col-w-10">Actions</th>
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
                                                <div class="sywat-meta-subtext">👤 <?php echo esc_html( $bm->user_login ); ?></div>
                                            </td>
                                            <td>
                                                <div class="sywat-badge-group">
                                                    <?php foreach ( $pos_labels as $p_key => $p_name ): ?>
                                                        <span class="sywat-badge sywat-badge-prereg">
                                                            🏛️ <?php echo esc_html( $p_name ); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td><a href="mailto:<?php echo esc_attr( $bm->user_email ); ?>" class="sywat-table-link"><?php echo esc_html( $bm->user_email ); ?></a></td>
                                            <td class="sywat-col-center"><?php echo esc_html( ! empty( $bm_phone ) ? $bm_phone : '—' ); ?></td>
                                            <td class="sywat-col-right">
                                                <div class="sywat-table-actions">
                                                    <a href="?tab=members&subtab=board&edit_board_id=<?php echo intval( $bm->ID ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit</a>
                                                    <?php if ( function_exists( 'sywat_can_manage_board' ) && sywat_can_manage_board() ): ?>
                                                        <form method="post" action="?tab=members&subtab=board" class="sywat-table-action-form" onsubmit="return confirm('Are you sure you want to remove &quot;<?php echo esc_js( $bm->display_name ); ?>&quot; from the Board of Directors?');">
                                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                            <input type="hidden" name="sywat_action" value="remove_board_member">
                                                            <input type="hidden" name="target_user_id" value="<?php echo intval( $bm->ID ); ?>">
                                                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Remove</button>
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
                    <div class="sywat-card-header-bar">
                        <div>
                            <h2>👥 Member Directory</h2>
                            <div class="sywat-subtitle">Overview of registered TFAA members, contact details, assigned roles, and NFAA membership status.</div>
                        </div>
                        <div class="sywat-header-inline-controls">
                            <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="sywatOpenAddMemberModal()">Add Member</button>
                        </div>
                    </div>

                    <div class="sywat-table-header-bar">
                        <div class="sywat-table-header-controls sywat-ml-0">
                            <div class="sywat-search-input-group">
                                <input type="text" id="sywat_member_search_input" oninput="sywatApplyMemberSearch()" placeholder="Search members..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetMemberSearch()">Clear</button>
                            </div>
                        </div>
                    </div>

                    <?php if ( empty( $all_members ) ): ?>
                        <p class="sywat-empty-state-notice">No TFAA members found in system database.</p>
                    <?php else: ?>
                        <div class="sywat-table-responsive">
                            <table class="sywat-table">
                                <thead>
                                    <tr>
                                        <th class="sywat-col-w-22">Member Name</th>
                                        <th class="sywat-col-w-24">Assigned Role(s)</th>
                                        <th class="sywat-col-w-22">Email Address</th>
                                        <th class="sywat-col-center sywat-col-w-12">Phone</th>
                                        <th class="sywat-col-center sywat-col-w-10">NFAA Status</th>
                                        <th class="sywat-col-right sywat-col-w-10">Actions</th>
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
                                                $role_badges[] = '<span class="sywat-badge sywat-badge-prereg">' . esc_html( $r_name ) . '</span>';
                                            }
                                            $roles_html   = ! empty( $role_badges ) ? implode( ' ', $role_badges ) : '<span class="sywat-no-data-tag">No Role</span>';
                                            $roles_search = implode( ' ', $role_names );
                                        ?>
                                        <tr class="sywat-member-row" data-search="<?php echo esc_attr( strtolower( $m->display_name . ' ' . $m->user_login . ' ' . $m->user_email . ' ' . $disp_id . ' ' . $m_phone . ' ' . $roles_search ) ); ?>">
                                            <td>
                                                <strong><?php echo esc_html( $m->display_name ); ?></strong>
                                                <div class="sywat-meta-subtext">👤 <?php echo esc_html( $m->user_login ); ?></div>
                                            </td>
                                            <td>
                                                <div class="sywat-badge-group">
                                                    <?php echo $roles_html; ?>
                                                </div>
                                            </td>
                                            <td><a href="mailto:<?php echo esc_attr( $m->user_email ); ?>" class="sywat-table-link"><?php echo esc_html( $m->user_email ); ?></a></td>
                                            <td class="sywat-col-center"><?php echo esc_html( ! empty( $m_phone ) ? $m_phone : '—' ); ?></td>
                                            <td class="sywat-col-center">
                                                <?php if ( $is_nfaa_active ): ?>
                                                    <span class="sywat-badge sywat-badge-open">🟢 NFAA Yes</span>
                                                <?php else: ?>
                                                    <span class="sywat-badge sywat-badge-muted">⚪ NFAA No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="sywat-col-right">
                                                <div class="sywat-table-actions">
                                                    <a href="?tab=members&subtab=directory&edit_member_id=<?php echo intval( $m->ID ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit</a>
                                                    <?php if ( get_current_user_id() !== $m->ID ): ?>
                                                        <form method="post" action="?tab=members&subtab=directory" class="sywat-table-action-form" onsubmit="return confirm('Are you sure you want to permanently delete member &quot;<?php echo esc_js( $m->display_name ); ?>&quot;? This action cannot be undone.');">
                                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                            <input type="hidden" name="sywat_action" value="delete_member">
                                                            <input type="hidden" name="target_user_id" value="<?php echo intval( $m->ID ); ?>">
                                                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Delete</button>
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
