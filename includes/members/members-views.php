<?php
/**
 * Sub-Module: Member & Board Management Portal Views
 * Path: includes/members/members-views.php
 * Description: Renders UI structures for Member Directory, Board Management, and Full-Page Shop-Style Member Profile Editor.
 * Compliance: Zero inline style attributes. Styling driven by centralized SYWAT CSS classes.
 * Author: SYWAT Archery
 * Version: 3.2.1
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
        $all_shops           = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();

        $edit_target_id  = isset( $_REQUEST['edit_member_id'] ) ? intval( $_REQUEST['edit_member_id'] ) : 0;
        $edit_user_obj   = ( $edit_target_id > 0 ) ? get_userdata( $edit_target_id ) : null;

        $edit_board_id       = isset( $_REQUEST['edit_board_id'] ) ? intval( $_REQUEST['edit_board_id'] ) : 0;
        $edit_board_user_obj = ( $edit_board_id > 0 ) ? get_userdata( $edit_board_id ) : null;

        global $wp_roles;
        $wp_role_names  = $wp_roles ? $wp_roles->get_names() : array();
        $editable_roles = $wp_roles ? $wp_roles->roles : array();
        $excluded_roles = array(
            'editor', 'author', 'contributor', 'subscriber',
            'management', 'staff_shooter', 'staff-shooter', 'non-shooting-member', 'non_shooting_member',
        );

        ob_start();
        ?>
        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <?php if ( $view === 'edit_member' && $edit_user_obj ): ?>
                <!-- DEDICATED SHOP-STYLE MEMBER PROFILE & HOUSEHOLD EDITOR -->
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

                    $nfaa_info = function_exists( 'sywat_get_member_nfaa_status' ) 
                        ? sywat_get_member_nfaa_status( $edit_user_obj->ID ) 
                        : array( 'status' => 'Expired', 'expiration' => '—', 'badge_class' => 'sywat-badge-muted' );

                    $age_div = ! empty( $e_dob ) && function_exists( 'sywat_get_eligible_age_division' ) 
                        ? sywat_get_eligible_age_division( $e_dob ) 
                        : 'Adult';

                    $linked_dependents = function_exists( 'sywat_get_linked_household_members' ) 
                        ? sywat_get_linked_household_members( $edit_user_obj->ID ) 
                        : array();

                    $show_add_dependent_form = ( isset( $_GET['action_mode'] ) && $_GET['action_mode'] === 'add_dependent' );
                ?>

                <form method="post" action="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>" id="sywat_edit_member_full_form">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_action" value="update_member">
                    <input type="hidden" name="target_user_id" value="<?php echo $edit_user_obj->ID; ?>">

                    <!-- CARD 1: PRIMARY ACCOUNT & CONTACT PROFILE -->
                    <div class="sywat-card">
                        <div class="sywat-card-header-bar">
                            <div>
                                <h2>👤 Member Profile: <?php echo esc_html( $edit_user_obj->display_name ); ?></h2>
                                <div class="sywat-subtitle">TFAA Member ID: <strong><?php echo esc_html( ! empty( $e_tfaa ) ? $e_tfaa : 'Unassigned' ); ?></strong> | Username: <code><?php echo esc_html( $edit_user_obj->user_login ); ?></code></div>
                            </div>
                            <!-- TOP RIGHT: BACK TO DIRECTORY BUTTON -->
                            <div class="sywat-header-inline-controls no-print">
                                <a href="?tab=members&subtab=directory" class="sywat-btn sywat-btn-secondary">← Back to Member Directory</a>
                            </div>
                        </div>

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

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Date of Birth (Eligible Division: <strong><?php echo esc_html( $age_div ); ?></strong>)</label>
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
                    </div>

                    <!-- CARD 2: HOUSEHOLD & FAMILY DEPENDENTS -->
                    <div class="sywat-card">
                        <div class="sywat-card-header-bar">
                            <div>
                                <h2>🏠 Household &amp; Family Relationships</h2>
                                <div class="sywat-subtitle">Manage primary account linkages and linked household family members.</div>
                            </div>
                            <div class="sywat-header-inline-controls">
                                <?php if ( empty( $e_primary_id ) ): ?>
                                    <a href="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>&action_mode=add_dependent#sywat_add_dependent_section" class="sywat-btn sywat-btn-success sywat-btn-icon-add">+ Add Family Member</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="sywat-field-group sywat-mb-4">
                            <label>Household Status / Primary Link</label>
                            <select name="primary_user_id">
                                <option value="0" <?php selected( empty( $e_primary_id ), true ); ?>>-- Standalone / Primary Head of Household --</option>
                                <?php foreach ( $all_members as $m_opt ): ?>
                                    <?php if ( $m_opt->ID === $edit_user_obj->ID ) continue; ?>
                                    <option value="<?php echo intval( $m_opt->ID ); ?>" <?php selected( intval( $e_primary_id ), intval( $m_opt->ID ) ); ?>>
                                        🏠 Dependent of: <?php echo esc_html( $m_opt->display_name ); ?> (<?php echo esc_html( $m_opt->user_email ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ( ! empty( $linked_dependents ) ): ?>
                            <div class="sywat-table-responsive">
                                <table class="sywat-table">
                                    <thead>
                                        <tr>
                                            <th class="sywat-col-w-32">Family Member Name</th>
                                            <th class="sywat-col-center sywat-col-w-22">DOB / Age Division</th>
                                            <th class="sywat-col-center sywat-col-w-22">NFAA Status</th>
                                            <th class="sywat-col-center sywat-col-w-24">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $linked_dependents as $dep ): ?>
                                            <?php 
                                                $dep_dob   = get_user_meta( $dep->ID, 'sywat_dob', true );
                                                $dep_nfaa  = function_exists( 'sywat_get_member_nfaa_status' ) ? sywat_get_member_nfaa_status( $dep->ID ) : array( 'status' => 'Expired', 'badge_class' => 'sywat-badge-muted' );
                                                $dep_div   = ! empty( $dep_dob ) && function_exists( 'sywat_get_eligible_age_division' ) ? sywat_get_eligible_age_division( $dep_dob ) : 'Adult';
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo esc_html( $dep->display_name ); ?></strong>
                                                    <div class="sywat-meta-subtext">👤 <?php echo esc_html( $dep->user_login ); ?></div>
                                                </td>
                                                <td class="sywat-col-center">
                                                    <?php echo ! empty( $dep_dob ) ? esc_html( date( 'M j, Y', strtotime( $dep_dob ) ) ) : '—'; ?>
                                                    <div class="sywat-meta-subtext">(<?php echo esc_html( $dep_div ); ?>)</div>
                                                </td>
                                                <td class="sywat-col-center">
                                                    <span class="sywat-badge <?php echo esc_attr( $dep_nfaa['badge_class'] ); ?>">
                                                        <?php echo $dep_nfaa['is_active'] ? '🟢 Active' : '⚪ Expired'; ?>
                                                    </span>
                                                </td>
                                                <td class="sywat-col-center">
                                                    <a href="?tab=members&view=edit_member&edit_member_id=<?php echo intval( $dep->ID ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit Profile</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="sywat-empty-state-notice">No linked household dependents registered under this primary account.</p>
                        <?php endif; ?>
                    </div>

                    <!-- CARD 3: SYSTEM ROLES & HOST SHOP ACCESS -->
                    <div class="sywat-card">
                        <div class="sywat-card-header-bar">
                            <div>
                                <h2>🔑 Roles, Permissions &amp; Shop Scoping</h2>
                                <div class="sywat-subtitle">Configure system capability roles and host archery shop access limits.</div>
                            </div>
                        </div>

                        <div class="sywat-field-group">
                            <label>Assigned System Role(s) *</label>
                            <div class="sywat-form-grid-2 sywat-role-grid">
                                <?php foreach ( $editable_roles as $r_key => $r_details ): ?>
                                    <?php if ( in_array( strtolower( $r_key ), $excluded_roles, true ) ) continue; ?>
                                    <label class="sywat-checkbox-label">
                                        <input type="checkbox" name="sywat_roles[]" value="<?php echo esc_attr( $r_key ); ?>" <?php checked( in_array( $r_key, $current_user_roles, true ), true ); ?> onchange="sywatToggleShopVisibility('sywat_edit_member_full_form', 'sywat_edit_full_shop_container')">
                                        <?php echo esc_html( $r_details['name'] ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div id="sywat_edit_full_shop_container" class="sywat-field-group sywat-mt-3 sywat-hidden">
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

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Reset Password (Optional)</label>
                                <input type="password" id="sywat_edit_full_pw" name="new_password" placeholder="Leave blank to keep current..." oninput="sywatValidatePasswordRules('sywat_edit_full_pw', 'sywat_edit_full_pw_confirm', 'edit_full_rule')">
                            </div>
                            <div class="sywat-field-group">
                                <label>Confirm New Password</label>
                                <input type="password" id="sywat_edit_full_pw_confirm" name="confirm_new_password" placeholder="Confirm new password..." oninput="sywatValidatePasswordRules('sywat_edit_full_pw', 'sywat_edit_full_pw_confirm', 'edit_full_rule')">
                            </div>
                        </div>

                        <!-- COMPACT INLINE PASSWORD REQUIREMENTS BAR -->
                        <div class="sywat-password-rules-box">
                            <div class="sywat-password-rules-bar">
                                <span class="sywat-password-rules-label">Rules:</span>
                                <span id="edit_full_rule_len" class="sywat-rule-badge">⚪ 8+ Chars</span>
                                <span id="edit_full_rule_upper" class="sywat-rule-badge">⚪ 1 Upper</span>
                                <span id="edit_full_rule_lower" class="sywat-rule-badge">⚪ 1 Lower</span>
                                <span id="edit_full_rule_num" class="sywat-rule-badge">⚪ 1 Num</span>
                                <span id="edit_full_rule_match" class="sywat-rule-badge">⚪ Passwords Match</span>
                            </div>
                        </div>
                    </div>

                    <!-- BOTTOM ACTION TOOLBAR: CANCEL + SAVE BUTTONS -->
                    <div class="sywat-modal-actions no-print sywat-mt-4 sywat-mb-4">
                        <a href="?tab=members&subtab=directory" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear">Cancel</a>
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Member Profile</button>
                    </div>
                </form>

                <!-- INLINE CARD: QUICK ADD FAMILY DEPENDENT TO HOUSEHOLD -->
                <?php if ( $show_add_dependent_form ): ?>
                    <div class="sywat-card sywat-mt-3" id="sywat_add_dependent_section">
                        <div class="sywat-card-header-bar">
                            <div>
                                <h2>➕ Add Family Member to Household: <?php echo esc_html( $edit_user_obj->last_name ); ?></h2>
                                <div class="sywat-subtitle">Creates a new member account linked directly to <strong><?php echo esc_html( $edit_user_obj->display_name ); ?></strong>. Pre-populates family address and phone.</div>
                            </div>
                            <div class="sywat-header-inline-controls">
                                <a href="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear">Cancel</a>
                            </div>
                        </div>

                        <form method="post" action="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>">
                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                            <input type="hidden" name="sywat_action" value="create_member">
                            <input type="hidden" name="primary_user_id" value="<?php echo $edit_user_obj->ID; ?>">
                            <input type="hidden" name="last_name" value="<?php echo esc_attr( $edit_user_obj->last_name ); ?>">
                            <input type="hidden" name="address_1" value="<?php echo esc_attr( $e_address_1 ); ?>">
                            <input type="hidden" name="address_2" value="<?php echo esc_attr( $e_address_2 ); ?>">
                            <input type="hidden" name="city" value="<?php echo esc_attr( $e_city ); ?>">
                            <input type="hidden" name="state" value="<?php echo esc_attr( $e_state ); ?>">
                            <input type="hidden" name="zip" value="<?php echo esc_attr( $e_zip ); ?>">
                            <input type="hidden" name="phone" value="<?php echo esc_attr( $e_phone ); ?>">

                            <div class="sywat-form-grid-2">
                                <div class="sywat-field-group">
                                    <label>First Name *</label>
                                    <input type="text" name="first_name" required>
                                </div>
                                <div class="sywat-field-group">
                                    <label>Last Name *</label>
                                    <input type="text" value="<?php echo esc_attr( $edit_user_obj->last_name ); ?>" disabled>
                                </div>
                            </div>

                            <div class="sywat-form-grid-2 sywat-mt-3">
                                <div class="sywat-field-group">
                                    <label>Email Address *</label>
                                    <input type="email" name="email" required>
                                </div>
                                <div class="sywat-field-group">
                                    <label>Date of Birth * (For NFAA Age Divisions)</label>
                                    <input type="date" name="dob" required>
                                </div>
                            </div>

                            <div class="sywat-form-grid-2 sywat-mt-3">
                                <div class="sywat-field-group">
                                    <label>Initial Password *</label>
                                    <input type="password" id="sywat_dep_pw" name="password" required oninput="sywatValidatePasswordRules('sywat_dep_pw', 'sywat_dep_pw_confirm', 'dep_rule')">
                                </div>
                                <div class="sywat-field-group">
                                    <label>Confirm Password *</label>
                                    <input type="password" id="sywat_dep_pw_confirm" name="confirm_password" required oninput="sywatValidatePasswordRules('sywat_dep_pw', 'sywat_dep_pw_confirm', 'dep_rule')">
                                </div>
                            </div>

                            <!-- COMPACT INLINE PASSWORD REQUIREMENTS BAR -->
                            <div class="sywat-password-rules-box">
                                <div class="sywat-password-rules-bar">
                                    <span class="sywat-password-rules-label">Rules:</span>
                                    <span id="dep_rule_len" class="sywat-rule-badge">🔴 8+ Chars</span>
                                    <span id="dep_rule_upper" class="sywat-rule-badge">🔴 1 Upper</span>
                                    <span id="dep_rule_lower" class="sywat-rule-badge">🔴 1 Lower</span>
                                    <span id="dep_rule_num" class="sywat-rule-badge">🔴 1 Num</span>
                                    <span id="dep_rule_match" class="sywat-rule-badge">🔴 Passwords Match</span>
                                </div>
                            </div>

                            <div class="sywat-modal-actions">
                                <a href="?tab=members&view=edit_member&edit_member_id=<?php echo $edit_user_obj->ID; ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear">Cancel</a>
                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save &amp; Link Household Member</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- DIRECTORY LIST & BOARD MANAGEMENT VIEWS -->
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
                                            <th class="sywat-col-center sywat-col-w-10">Actions</th>
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
                    <!-- SUB-TAB 1: MEMBER DIRECTORY -->
                    <div class="sywat-card no-print">
                        <div class="sywat-card-header-bar">
                            <div>
                                <h2>👥 Member Directory</h2>
                                <div class="sywat-subtitle">Overview of registered TFAA members, contact details, DOB age divisions, household links, and member status.</div>
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
                                            <th class="sywat-col-w-22">Member Name &amp; DOB</th>
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
                                                $m_tfaa   = get_user_meta( $m->ID, 'sywat_tfaa_number', true );
                                                $m_phone  = get_user_meta( $m->ID, 'sywat_phone', true );
                                                $m_dob    = get_user_meta( $m->ID, 'sywat_dob', true );
                                                $disp_id  = ! empty( $m_tfaa ) ? $m_tfaa : '—';

                                                // Evaluated Member NFAA Status & Expiration
                                                $nfaa_info = function_exists( 'sywat_get_member_nfaa_status' ) 
                                                    ? sywat_get_member_nfaa_status( $m->ID ) 
                                                    : array( 'status' => 'Expired', 'expiration' => '—', 'badge_class' => 'sywat-badge-muted' );

                                                // DOB & Age Division Calculation
                                                $age_div = ! empty( $m_dob ) && function_exists( 'sywat_get_eligible_age_division' ) 
                                                    ? sywat_get_eligible_age_division( $m_dob ) 
                                                    : '';

                                                // Household Primary Link Check
                                                $primary_id   = get_user_meta( $m->ID, 'sywat_primary_user_id', true );
                                                $primary_name = '';
                                                if ( $primary_id > 0 ) {
                                                    $p_obj = get_userdata( $primary_id );
                                                    if ( $p_obj ) {
                                                        $primary_name = $p_obj->display_name;
                                                    }
                                                }

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
                                            <tr class="sywat-member-row" data-search="<?php echo esc_attr( strtolower( $m->display_name . ' ' . $m->user_login . ' ' . $m->user_email . ' ' . $disp_id . ' ' . $m_phone . ' ' . $roles_search . ' ' . $nfaa_info['status'] . ' ' . $age_div . ' ' . $primary_name ) ); ?>">
                                                <td>
                                                    <strong><?php echo esc_html( $m->display_name ); ?></strong>
                                                    <div class="sywat-meta-subtext">👤 <?php echo esc_html( $m->user_login ); ?></div>
                                                    <?php if ( ! empty( $m_dob ) ): ?>
                                                        <div class="sywat-meta-subtext">🎂 <?php echo esc_html( date( 'M j, Y', strtotime( $m_dob ) ) ); ?><?php echo ! empty( $age_div ) ? ' (' . esc_html( $age_div ) . ')' : ''; ?></div>
                                                    <?php endif; ?>
                                                    <?php if ( ! empty( $primary_name ) ): ?>
                                                        <div class="sywat-meta-subtext">🏠 Linked to <?php echo esc_html( $primary_name ); ?></div>
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
                                                    <span class="sywat-badge <?php echo esc_attr( $nfaa_info['badge_class'] ); ?>">
                                                        <?php echo $nfaa_info['is_active'] ? '🟢 Active' : '⚪ Expired'; ?>
                                                    </span>
                                                    <?php if ( ! empty( $nfaa_info['expiration'] ) && $nfaa_info['expiration'] !== '—' ): ?>
                                                        <div class="sywat-meta-subtext">Exp: <?php echo esc_html( $nfaa_info['expiration'] ); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="sywat-col-center">
                                                    <div class="sywat-table-actions-vertical">
                                                        <a href="?tab=members&view=edit_member&edit_member_id=<?php echo intval( $m->ID ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit Profile</a>
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
