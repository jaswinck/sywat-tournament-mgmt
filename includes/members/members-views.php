<?php
/**
 * Sub-Module: Member & Board Management Portal Views
 * Path: includes/members/members-views.php
 * Description: High-density visual renderers for Member Directory, Board Management, and Single-Dashboard Household Roster. Includes administrative batch import triggers, active/expired status filters and statistics counters, protected test data reset triggers, Head of Household swapping, and explicit archer-specific Unlink & Delete actions. Decoupled status badge presentation delegates payload evaluations strictly to members-helpers.php.
 * Compliance: Zero inline style attributes. Zero embedded form clutter. Styling driven by centralized SYWAT CSS classes. Delegates statistical metrics calculation engines to members-data.php and status evaluations to members-helpers.php.
 * Author: SYWAT Archery
 * Version: 5.2.0
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
        $view   = isset( $_REQUEST['view'] ) ? sanitize_text_field( $_REQUEST['view'] ) : 'directory';
        $subtab = isset( $_REQUEST['subtab'] ) ? sanitize_text_field( $_REQUEST['subtab'] ) : 'directory';

        // Nonce Verification & Central POST Processing
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

        // Filter registered directory accounts matching TFAA officer/member roles, shop managers, or Administrator
        $allowed_directory_roles = array_unique( array_merge( (array) $tfaa_role_slugs, array( 'administrator', 'sywat_shop_manager', 'sywat_shop_user' ) ) );
        $all_members = array();
        if ( is_array( $raw_members ) ) {
            foreach ( $raw_members as $m_user ) {
                if ( ! $m_user instanceof WP_User ) continue;
                $u_roles = (array) $m_user->roles;
                if ( ! empty( array_intersect( $allowed_directory_roles, $u_roles ) ) ) {
                    $all_members[] = $m_user;
                }
            }
        }

        $board_members       = function_exists( 'sywat_get_all_board_members' ) ? sywat_get_all_board_members() : array();
        $all_board_positions = function_exists( 'sywat_get_tfaa_board_positions_list' ) ? sywat_get_tfaa_board_positions_list() : array();

        $edit_target_id  = isset( $_REQUEST['edit_member_id'] ) ? intval( $_REQUEST['edit_member_id'] ) : 0;
        $edit_user_obj   = ( $edit_target_id > 0 ) ? get_userdata( $edit_target_id ) : null;

        $edit_system_id      = isset( $_REQUEST['edit_system_id'] ) ? sanitize_text_field( $_REQUEST['edit_system_id'] ) : '';
        $edit_dep_subprofile = ( $edit_target_id > 0 && ! empty( $edit_system_id ) && function_exists( 'sywat_get_household_dependent_by_system_id' ) ) 
            ? sywat_get_household_dependent_by_system_id( $edit_target_id, $edit_system_id ) 
            : null;

        $edit_board_id       = isset( $_REQUEST['edit_board_id'] ) ? intval( $_REQUEST['edit_board_id'] ) : 0;
        $edit_board_user_obj = ( $edit_board_id > 0 ) ? get_userdata( $edit_board_id ) : null;

        global $wp_roles;
        $wp_role_names  = $wp_roles ? $wp_roles->get_names() : array();
        $excluded_roles = array(
            'editor', 'author', 'contributor', 'subscriber',
            'management', 'staff_shooter', 'staff-shooter', 'non-shooting-member', 'non_shooting_member',
        );

        $accom_labels  = function_exists( 'sywat_get_accommodations_list' ) ? sywat_get_accommodations_list() : array();
        $gender_labels = function_exists( 'sywat_get_gender_options' ) ? sywat_get_gender_options() : array( 'unassigned' => '-- Select Gender --', 'male' => 'Male', 'female' => 'Female' );

        ob_start();
        ?>
        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <?php if ( $view === 'edit_member' && $edit_user_obj ): ?>
                <!-- VIEW 1: SINGLE-DASHBOARD HOUSEHOLD MEMBER PROFILE VIEW -->
                <?php 
                    $e_nfaa_id   = function_exists( 'sywat_get_user_nfaa_id' ) ? sywat_get_user_nfaa_id( $edit_user_obj->ID ) : 'Unassigned';
                    $e_phone     = get_user_meta( $edit_user_obj->ID, 'sywat_phone', true );
                    $e_gender    = get_user_meta( $edit_user_obj->ID, 'sywat_gender', true );
                    $e_is_pro    = get_user_meta( $edit_user_obj->ID, 'sywat_is_pro', true );
                    $e_dob       = get_user_meta( $edit_user_obj->ID, 'sywat_dob', true );
                    $e_accom     = get_user_meta( $edit_user_obj->ID, 'sywat_accommodations', true );
                    $e_address_1 = get_user_meta( $edit_user_obj->ID, 'sywat_address_1', true );
                    $e_address_2 = get_user_meta( $edit_user_obj->ID, 'sywat_address_2', true );
                    $e_city      = get_user_meta( $edit_user_obj->ID, 'sywat_city', true );
                    $e_state     = get_user_meta( $edit_user_obj->ID, 'sywat_state', true );
                    $e_zip       = get_user_meta( $edit_user_obj->ID, 'sywat_zip', true );

                    $nfaa_info = function_exists( 'sywat_get_member_nfaa_status' ) 
                        ? sywat_get_member_nfaa_status( $edit_user_obj->ID ) 
                        : array( 'status' => 'Expired', 'expiration' => '—', 'badge_class' => 'sywat-badge-muted', 'system_account_label' => '', 'is_active' => false, 'has_membership_role' => false );

                    // STRICT BOOLEAN CHECK: User MUST explicitly hold a shooting member role (has_membership_role === true)
                    $is_hoh_member = isset( $nfaa_info['has_membership_role'] ) && ( $nfaa_info['has_membership_role'] === true );

                    $age_div = ! empty( $e_dob ) && function_exists( 'sywat_get_eligible_age_division' ) 
                        ? sywat_get_eligible_age_division( $e_dob ) 
                        : 'Adult';

                    $e_gender_clean = ! empty( $e_gender ) && isset( $gender_labels[strtolower( $e_gender )] ) 
                        ? $gender_labels[strtolower( $e_gender )] 
                        : '⚠️ Unassigned';

                    $linked_dependents = function_exists( 'sywat_get_linked_household_members' ) 
                        ? sywat_get_linked_household_members( $edit_user_obj->ID ) 
                        : array();

                    $full_address = implode( ', ', array_filter( array(
                        $e_address_1 . ( ! empty( $e_address_2 ) ? ' ' . $e_address_2 : '' ),
                        $e_city,
                        ( ! empty( $e_state ) ? strtoupper( $e_state ) : '' ) . ( ! empty( $e_zip ) ? ' ' . $e_zip : '' ),
                    ) ) );
                ?>

                <!-- UNIFIED HOUSEHOLD DASHBOARD CARD -->
                <div class="sywat-card">
                    <div class="sywat-card-header-bar">
                        <div>
                            <h2>🏠 Household Dashboard: <?php echo esc_html( $edit_user_obj->display_name ); ?></h2>
                            <div class="sywat-subtitle">Managing family roster &amp; archer profiles stored under this household.</div>
                        </div>
                        <div class="sywat-header-inline-controls no-print">
                            <a href="?tab=members&subtab=directory" class="sywat-btn sywat-btn-secondary">← Back to Directory</a>
                            <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="sywatOpenAddDependentModal()">Add Family Member</button>
                        </div>
                    </div>

                    <!-- UNIFIED HOUSEHOLD ROSTER TABLE -->
                    <div class="sywat-table-responsive sywat-mt-2">
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th class="sywat-col-w-28">Archer Name &amp; ID</th>
                                    <th class="sywat-col-center sywat-col-w-18">Household Role</th>
                                    <th class="sywat-col-center sywat-col-w-20">Gender / DOB / Division</th>
                                    <th class="sywat-col-center sywat-col-w-18">Member Status</th>
                                    <th class="sywat-col-center sywat-col-w-16">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- ROW 1: HEAD OF HOUSEHOLD (PRIMARY ACCOUNT HOLDER) -->
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $edit_user_obj->display_name ); ?></strong>
                                        <?php if ( $e_is_pro === 'yes' ): ?>
                                            <span class="sywat-badge sywat-badge-pro sywat-ml-1">⭐ Pro</span>
                                        <?php endif; ?>
                                        <div class="sywat-meta-subtext">🆔 NFAA ID: <strong><?php echo esc_html( $e_nfaa_id ); ?></strong></div>
                                        <div class="sywat-meta-subtext">👤 Login: <code><?php echo esc_html( $edit_user_obj->user_login ); ?></code></div>
                                        <?php if ( ! empty( $e_accom ) && $e_accom !== 'none' ): ?>
                                            <div class="sywat-mt-1">
                                                <span class="sywat-badge sywat-badge-prereg">♿ <?php echo esc_html( isset( $accom_labels[$e_accom] ) ? $accom_labels[$e_accom] : $e_accom ); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sywat-col-center">
                                        <span class="sywat-badge sywat-badge-open">👑 Head of Household</span>
                                    </td>
                                    <td class="sywat-col-center">
                                        <div><strong><?php echo esc_html( $e_gender_clean ); ?></strong></div>
                                        <?php echo ! empty( $e_dob ) ? esc_html( date( 'M j, Y', strtotime( $e_dob ) ) ) : '—'; ?>
                                        <div class="sywat-meta-subtext">(<?php echo esc_html( $age_div ); ?>)</div>
                                    </td>
                                    <td class="sywat-col-center">
                                        <div class="sywat-badge-group-vertical">
                                            <?php if ( ! empty( $nfaa_info['system_account_label'] ) ): ?>
                                                <span class="sywat-badge sywat-badge-checkedin">🏢 <?php echo esc_html( $nfaa_info['system_account_label'] ); ?></span>
                                            <?php endif; ?>

                                            <?php if ( $is_hoh_member ): ?>
                                                <span class="sywat-badge <?php echo esc_attr( isset( $nfaa_info['badge_class'] ) ? $nfaa_info['badge_class'] : 'sywat-badge-muted' ); ?>">
                                                    <?php echo ( ! empty( $nfaa_info['is_active'] ) ) ? '🟢 Active' : '⚪ Expired'; ?>
                                                </span>
                                            <?php elseif ( empty( $nfaa_info['system_account_label'] ) ): ?>
                                                <span class="sywat-badge sywat-badge-muted">Non-Member</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ( $is_hoh_member && ! empty( $nfaa_info['expiration'] ) && $nfaa_info['expiration'] !== '—' && $nfaa_info['expiration'] !== 'System Account' ): ?>
                                            <div class="sywat-meta-subtext">Exp: <?php echo esc_html( $nfaa_info['expiration'] ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sywat-col-center">
                                        <button type="button" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit" onclick="sywatOpenEditPrimaryModal()">Edit Profile</button>
                                    </td>
                                </tr>

                                <!-- ROWS 2+: HOUSEHOLD DEPENDENTS & LINKED ARCHERS -->
                                <?php if ( is_array( $linked_dependents ) ): ?>
                                    <?php foreach ( $linked_dependents as $dep ): ?>
                                        <?php 
                                            $dep_fname  = isset( $dep['first_name'] ) ? $dep['first_name'] : '';
                                            $dep_lname  = isset( $dep['last_name'] ) ? $dep['last_name'] : '';
                                            $dep_dob    = isset( $dep['dob'] ) ? $dep['dob'] : '';
                                            $dep_exp    = isset( $dep['nfaa_expiration'] ) ? $dep['nfaa_expiration'] : '';
                                            $dep_accom  = isset( $dep['accommodations'] ) ? $dep['accommodations'] : 'none';
                                            $dep_gender = isset( $dep['gender'] ) && isset( $gender_labels[strtolower( $dep['gender'] )] ) ? $gender_labels[strtolower( $dep['gender'] )] : '⚠️ Unassigned';
                                            $dep_is_pro = isset( $dep['is_pro'] ) && $dep['is_pro'] === 'yes';
                                            $dep_nfaa   = function_exists( 'sywat_get_member_nfaa_status' ) ? sywat_get_member_nfaa_status( $dep_exp ) : array( 'status' => 'Expired', 'badge_class' => 'sywat-badge-muted', 'system_account_label' => '', 'is_active' => false, 'has_membership_role' => true );
                                            $dep_div    = ! empty( $dep_dob ) && function_exists( 'sywat_get_eligible_age_division' ) ? sywat_get_eligible_age_division( $dep_dob ) : 'Adult';
                                            $dep_sys_id = isset( $dep['system_id'] ) ? $dep['system_id'] : '';
                                            $dep_n_id   = ! empty( $dep['nfaa_number'] ) ? $dep['nfaa_number'] : 'Unassigned';
                                            $dep_name   = isset( $dep['display_name'] ) ? $dep['display_name'] : ( ! empty( $dep_fname ) ? trim( $dep_fname . ' ' . $dep_lname ) : 'Unnamed' );
                                            $dep_first  = ! empty( $dep_fname ) ? $dep_fname : $dep_name;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html( $dep_name ); ?></strong>
                                                <?php if ( $dep_is_pro ): ?>
                                                    <span class="sywat-badge sywat-badge-pro sywat-ml-1">⭐ Pro</span>
                                                <?php endif; ?>
                                                <div class="sywat-meta-subtext">🆔 NFAA ID: <strong><?php echo esc_html( $dep_n_id ); ?></strong></div>
                                                <?php if ( ! empty( $dep_accom ) && $dep_accom !== 'none' ): ?>
                                                    <div class="sywat-mt-1">
                                                        <span class="sywat-badge sywat-badge-prereg">♿ <?php echo esc_html( isset( $accom_labels[$dep_accom] ) ? $accom_labels[$dep_accom] : $dep_accom ); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="sywat-col-center">
                                                <span class="sywat-badge sywat-badge-muted">🏠 Dependent</span>
                                            </td>
                                            <td class="sywat-col-center">
                                                <div><strong><?php echo esc_html( $dep_gender ); ?></strong></div>
                                                <?php echo ! empty( $dep_dob ) ? esc_html( date( 'M j, Y', strtotime( $dep_dob ) ) ) : '—'; ?>
                                                <div class="sywat-meta-subtext">(<?php echo esc_html( $dep_div ); ?>)</div>
                                            </td>
                                            <td class="sywat-col-center">
                                                <div class="sywat-badge-group-vertical">
                                                    <span class="sywat-badge <?php echo esc_attr( isset( $dep_nfaa['badge_class'] ) ? $dep_nfaa['badge_class'] : 'sywat-badge-muted' ); ?>">
                                                        <?php echo ( isset( $dep_nfaa['is_active'] ) && $dep_nfaa['is_active'] ) ? '🟢 Active' : '⚪ Expired'; ?>
                                                    </span>
                                                </div>
                                                <?php if ( ! empty( $dep_nfaa['expiration'] ) && $dep_nfaa['expiration'] !== '—' ): ?>
                                                    <div class="sywat-meta-subtext">Exp: <?php echo esc_html( $dep_nfaa['expiration'] ); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="sywat-col-center">
                                                <div class="sywat-table-actions-vertical">
                                                    <a href="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>&edit_system_id=<?php echo esc_attr( $dep_sys_id ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit Sub-Profile</a>
                                                    
                                                    <form method="post" action="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>" class="sywat-table-action-form" onsubmit="return confirm('Promote &quot;<?php echo esc_js( $dep_name ); ?>&quot; to Head of Household?');">
                                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                        <input type="hidden" name="sywat_action" value="swap_head_of_household">
                                                        <input type="hidden" name="primary_user_id" value="<?php echo $edit_user_obj->ID; ?>">
                                                        <input type="hidden" name="target_system_id" value="<?php echo esc_attr( $dep_sys_id ); ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm sywat-mt-1">👑 Make Head</button>
                                                    </form>

                                                    <form method="post" action="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>" class="sywat-table-action-form" onsubmit="return confirm('Unlink <?php echo esc_js( $dep_name ); ?> from this household? Their record will be detached and preserved.');">
                                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                        <input type="hidden" name="sywat_action" value="unlink_dependent">
                                                        <input type="hidden" name="primary_user_id" value="<?php echo $edit_user_obj->ID; ?>">
                                                        <input type="hidden" name="target_system_id" value="<?php echo esc_attr( $dep_sys_id ); ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm sywat-mt-1">🔗 Unlink <?php echo esc_html( $dep_first ); ?></button>
                                                    </form>

                                                    <form method="post" action="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>" class="sywat-table-action-form" onsubmit="return confirm('Are you sure you want to PERMANENTLY DELETE <?php echo esc_js( $dep_name ); ?>? This action cannot be undone.');">
                                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                        <input type="hidden" name="sywat_action" value="delete_member">
                                                        <input type="hidden" name="primary_user_id" value="<?php echo $edit_user_obj->ID; ?>">
                                                        <input type="hidden" name="target_system_id" value="<?php echo esc_attr( $dep_sys_id ); ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-sm sywat-mt-1">🗑️ Delete <?php echo esc_html( $dep_first ); ?></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- MASTER HOUSEHOLD ADDRESS & CONTACT SUMMARY FOOTER -->
                    <hr class="sywat-hr-divider sywat-mt-4 sywat-mb-3">
                    <div class="sywat-form-grid-2">
                        <div>
                            <span class="sywat-meta-subtext">📍 Master Household Address:</span>
                            <div><strong><?php echo esc_html( ! empty( $full_address ) ? $full_address : 'Unassigned / Not on File' ); ?></strong></div>
                        </div>
                        <div class="sywat-text-right">
                            <span class="sywat-meta-subtext">📞 Master Phone / Email Contact:</span>
                            <div><strong><?php echo esc_html( ! empty( $e_phone ) ? $e_phone : '—' ); ?></strong> (<code><?php echo esc_html( $edit_user_obj->user_email ); ?></code>)</div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- VIEW 2: MEMBER DIRECTORY LIST & BOARD MANAGEMENT VIEWS -->
                <div class="sywat-nav-tabs no-print">
                    <a href="?tab=members&subtab=directory" class="sywat-tab-item sywat-tab-icon-member <?php echo $subtab === 'directory' ? 'active' : ''; ?>">Member Directory</a>
                    <a href="?tab=members&subtab=board" class="sywat-tab-item sywat-tab-icon-board <?php echo $subtab === 'board' ? 'active' : ''; ?>">Board Management</a>
                </div>

                <?php if ( $subtab === 'board' ): ?>
                    <!-- SUB-TAB 2: BOARD MANAGEMENT TABLE -->
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

                        <?php if ( empty( $board_members ) || ! is_array( $board_members ) ): ?>
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
                                            <th class="sywat-col-center sywat-col-w-10">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $board_members as $bm ): ?>
                                            <?php 
                                                if ( ! $bm instanceof WP_User ) continue;
                                                $bm_phone   = get_user_meta( $bm->ID, 'sywat_phone', true );
                                                $pos_labels = function_exists( 'sywat_get_user_board_position_labels' ) ? sywat_get_user_board_position_labels( $bm->ID ) : array();
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
                                                            <span class="sywat-badge sywat-badge-open">
                                                                🏛️ <?php echo esc_html( $p_name ); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </td>
                                                <td><a href="mailto:<?php echo esc_attr( $bm->user_email ); ?>" class="sywat-table-link"><?php echo esc_html( $bm->user_email ); ?></a></td>
                                                <td class="sywat-col-center"><?php echo esc_html( ! empty( $bm_phone ) ? $bm_phone : '—' ); ?></td>
                                                <td class="sywat-col-center">
                                                    <div class="sywat-table-actions-vertical">
                                                        <a href="?tab=members&view=edit_member&edit_member_id=<?php echo intval( $bm->ID ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit Profile</a>
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
                    <!-- SUB-TAB 1: MEMBER DIRECTORY TABLE -->
                    <div class="sywat-card no-print">
                        <div class="sywat-card-header-bar">
                            <div>
                                <h2>👥 Member Directory</h2>
                                <div class="sywat-subtitle">Overview of registered TFAA members, contact details, household links, and member status.</div>
                            </div>
                            <div class="sywat-header-inline-controls">
                                <?php if ( current_user_can( 'administrator' ) ): ?>
                                    <button type="button" class="sywat-btn sywat-btn-secondary" onclick="sywatOpenImportModal()">📥 Import Members</button>
                                    <button type="button" class="sywat-btn sywat-btn-danger" onclick="sywatOpenPurgeModal()">🗑️ Reset Members</button>
                                <?php endif; ?>
                                <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="sywatOpenAddMemberModal()">Add Member</button>
                            </div>
                        </div>

                        <?php
                            // Fetch pre-calculated directory statistics engine payload from members-data.php
                            $member_stats = function_exists( 'sywat_get_tfaa_member_statistics' ) 
                                ? sywat_get_tfaa_member_statistics( $all_members ) 
                                : array( 'total' => 0, 'active' => 0, 'expired' => 0 );

                            $stat_total   = isset( $member_stats['total'] ) ? $member_stats['total'] : 0;
                            $stat_active  = isset( $member_stats['active'] ) ? $member_stats['active'] : 0;
                            $stat_expired = isset( $member_stats['expired'] ) ? $member_stats['expired'] : 0;
                        ?>

                        <!-- UNIFIED BASELINE CONTROL & STATS TOOLBAR -->
                        <div class="sywat-table-header-bar">
                            <div class="sywat-table-header-left">
                                <div class="sywat-filter-field">
                                    <label for="sywat_member_status_filter">STATUS</label>
                                    <select id="sywat_member_status_filter" onchange="sywatApplyMemberSearch()" class="sywat-select-filter">
                                        <option value="">-- All Statuses --</option>
                                        <option value="active">🟢 Active Only</option>
                                        <option value="expired">⚪ Expired Only</option>
                                    </select>
                                </div>
                                <div class="sywat-stats-inline-pills">
                                    <span class="sywat-inline-pill" title="Total Members">
                                        👤 <strong><?php echo number_format( $stat_total ); ?></strong> Total Members
                                    </span>
                                    <span class="sywat-inline-pill sywat-inline-pill-active" title="Active Membership">
                                        🟢 <strong><?php echo number_format( $stat_active ); ?></strong> Active Memberships
                                    </span>
                                    <span class="sywat-inline-pill sywat-inline-pill-expired" title="Expired Memberships">
                                        ⚪ <strong><?php echo number_format( $stat_expired ); ?></strong> Expired Memberships
                                    </span>
                                </div>
                            </div>
                            <div class="sywat-table-header-controls">
                                <div class="sywat-search-input-group">
                                    <input type="text" id="sywat_member_search_input" oninput="sywatApplyMemberSearch()" placeholder="Search members..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="sywatResetMemberSearch()">Clear</button>
                                </div>
                            </div>
                        </div>

                        <?php if ( empty( $all_members ) || ! is_array( $all_members ) ): ?>
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
                                            <th class="sywat-col-center sywat-col-w-10">Member Status</th>
                                            <th class="sywat-col-center sywat-col-w-10">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $all_members as $m ): ?>
                                            <?php 
                                                if ( ! $m instanceof WP_User ) continue;
                                                $m_nfaa_id = function_exists( 'sywat_get_user_nfaa_id' ) ? sywat_get_user_nfaa_id( $m->ID ) : 'Unassigned';
                                                $m_phone   = get_user_meta( $m->ID, 'sywat_phone', true );
                                                $m_gender  = get_user_meta( $m->ID, 'sywat_gender', true );
                                                $m_dob     = get_user_meta( $m->ID, 'sywat_dob', true );

                                                // Evaluated Member NFAA Status & Expiration Payload from members-helpers.php
                                                $nfaa_info = function_exists( 'sywat_get_member_nfaa_status' ) 
                                                    ? sywat_get_member_nfaa_status( $m->ID ) 
                                                    : array( 'status' => 'Expired', 'expiration' => '—', 'badge_class' => 'sywat-badge-muted', 'system_account_label' => '', 'is_active' => false, 'has_membership_role' => false );

                                                // STRICT BOOLEAN CHECK: User MUST explicitly hold a shooting member role (has_membership_role === true)
                                                $is_member  = isset( $nfaa_info['has_membership_role'] ) && ( $nfaa_info['has_membership_role'] === true );
                                                $status_key = $is_member ? ( ( isset( $nfaa_info['is_active'] ) && $nfaa_info['is_active'] ) ? 'active' : 'expired' ) : 'non_member';

                                                // DOB & Age Division Calculation (Retained for search data attribute)
                                                $age_div = ! empty( $m_dob ) && function_exists( 'sywat_get_eligible_age_division' ) 
                                                    ? sywat_get_eligible_age_division( $m_dob ) 
                                                    : '';

                                                // Role Badges Formatting with Color Hierarchy
                                                $m_roles     = (array) $m->roles;
                                                $role_badges = array();
                                                $role_names  = array();
                                                foreach ( $m_roles as $r_slug ) {
                                                    if ( in_array( strtolower( $r_slug ), $excluded_roles, true ) ) continue;
                                                    
                                                    $r_name = isset( $wp_role_names[$r_slug] ) ? $wp_role_names[$r_slug] : ucfirst( str_replace( '_', ' ', $r_slug ) );
                                                    $r_name = trim( str_replace( array( 'TFAA ', 'SYWAT ' ), '', $r_name ) );
                                                    $r_clean = strtolower( $r_slug );

                                                    // Robust String Matching for Color Hierarchy Classes
                                                    $badge_cls = 'sywat-badge-muted'; // Default TFAA Member
                                                    if ( strpos( $r_clean, 'admin' ) !== false || strpos( $r_clean, 'webmaster' ) !== false ) {
                                                        $badge_cls = 'sywat-badge-closed'; // Dark Gold / Crimson
                                                    } elseif ( strpos( $r_clean, 'president' ) !== false || strpos( $r_clean, 'treasurer' ) !== false || strpos( $r_clean, 'secretary' ) !== false || strpos( $r_clean, 'director' ) !== false ) {
                                                        $badge_cls = 'sywat-badge-open'; // Navy Blue
                                                    } elseif ( strpos( $r_clean, 'governor' ) !== false ) {
                                                        $badge_cls = 'sywat-badge-registered'; // Purple
                                                    } elseif ( strpos( $r_clean, 'shop' ) !== false || strpos( $r_clean, 'club' ) !== false ) {
                                                        $badge_cls = 'sywat-badge-checkedin'; // Teal Emerald
                                                    }

                                                    $role_names[]  = $r_name;
                                                    $role_badges[] = '<span class="sywat-badge ' . esc_attr( $badge_cls ) . '">' . esc_html( $r_name ) . '</span>';
                                                }
                                                $roles_html   = ! empty( $role_badges ) ? implode( ' ', $role_badges ) : '<span class="sywat-no-data-tag">No Role</span>';
                                                $roles_search = implode( ' ', $role_names );

                                                // Dependents count tag
                                                $m_deps       = function_exists( 'sywat_get_linked_household_members' ) ? sywat_get_linked_household_members( $m->ID ) : array();
                                                $m_deps_count = is_array( $m_deps ) ? count( $m_deps ) : 0;
                                            ?>
                                            <tr class="sywat-member-row" data-status="<?php echo esc_attr( $status_key ); ?>" data-search="<?php echo esc_attr( strtolower( $m->display_name . ' ' . $m->user_login . ' ' . $m->user_email . ' ' . $m_nfaa_id . ' ' . $m_phone . ' ' . $m_gender . ' ' . $roles_search . ' ' . ( isset( $nfaa_info['status'] ) ? $nfaa_info['status'] : '' ) . ' ' . $age_div ) ); ?>">
                                                <td>
                                                    <strong><?php echo esc_html( $m->display_name ); ?></strong>
                                                    <?php if ( $m_deps_count > 0 ): ?>
                                                        <div class="sywat-meta-subtext">🏠 Household: <?php echo intval( $m_deps_count ); ?> Linked Archer(s)</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="sywat-badge-group">
                                                        <?php echo $roles_html; ?>
                                                    </div>
                                                </td>
                                                <td><a href="mailto:<?php echo esc_attr( $m->user_email ); ?>" class="sywat-table-link"><?php echo esc_html( $m->user_email ); ?></a></td>
                                                <td class="sywat-col-center"><?php echo esc_html( ! empty( $m_phone ) ? $m_phone : '—' ); ?></td>
                                                <td class="sywat-col-center">
                                                    <div class="sywat-badge-group-vertical">
                                                        <?php if ( ! empty( $nfaa_info['system_account_label'] ) ): ?>
                                                            <span class="sywat-badge sywat-badge-checkedin">🏢 <?php echo esc_html( $nfaa_info['system_account_label'] ); ?></span>
                                                        <?php endif; ?>

                                                        <?php if ( $is_member ): ?>
                                                            <span class="sywat-badge <?php echo esc_attr( isset( $nfaa_info['badge_class'] ) ? $nfaa_info['badge_class'] : 'sywat-badge-muted' ); ?>">
                                                                <?php echo ( ! empty( $nfaa_info['is_active'] ) ) ? '🟢 Active' : '⚪ Expired'; ?>
                                                            </span>
                                                        <?php elseif ( empty( $nfaa_info['system_account_label'] ) ): ?>
                                                            <span class="sywat-badge sywat-badge-muted">Non-Member</span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if ( $is_member && ! empty( $nfaa_info['expiration'] ) && $nfaa_info['expiration'] !== '—' && $nfaa_info['expiration'] !== 'System Account' ): ?>
                                                        <div class="sywat-meta-subtext">Exp: <?php echo esc_html( $nfaa_info['expiration'] ); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="sywat-col-center">
                                                    <div class="sywat-table-actions-vertical">
                                                        <a href="?tab=members&view=edit_member&edit_member_id=<?php echo intval( $m->ID ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">View Household</a>
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
            <?php endif; ?>

            <?php 
            // Render Modals & Client JavaScript Overlays
            if ( function_exists( 'sywat_members_render_modals' ) ) {
                sywat_members_render_modals( array(
                    'all_members'         => $all_members,
                    'all_board_positions' => $all_board_positions,
                    'edit_user_obj'       => $edit_user_obj,
                    'edit_dep_subprofile' => $edit_dep_subprofile,
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
