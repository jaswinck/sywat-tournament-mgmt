<?php
/**
 * Module: Shop Management & Multi-Tenant Permissions
 * Description: Shop CRUD (Create, Read, Update, Delete), default line templates, user shop mapping, and staff assignment UI.
 * Author: SYWAT Archery
 * Version: 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Ensure default shop exists upon loading
add_action( 'init', 'sywat_ensure_default_shop_exists' );
function sywat_ensure_default_shop_exists() {
    $shops = get_option( 'sywat_shops_list', array() );
    if ( empty( $shops ) || ! is_array( $shops ) ) {
        $default_lines = array(
            array( 'name' => 'Saturday 10AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Saturday 2PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Sunday 10AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Sunday 2PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
        );
        $shops = array(
            'shop_ccr' => array(
                'id'            => 'shop_ccr',
                'name'          => 'Cinnamon Creek Ranch',
                'contact'       => 'Hannah Lange / Beau Brennan',
                'email'         => 'events@cinnamoncreekranch.com',
                'phone'         => '817-439-8998',
                'default_lines' => $default_lines,
                'created_at'    => current_time( 'mysql' ),
            )
        );
        update_option( 'sywat_shops_list', $shops );
    }
}

// Helper: Get All Shops
function sywat_get_shops() {
    $shops = get_option( 'sywat_shops_list', array() );
    return is_array( $shops ) ? $shops : array();
}

// Helper: Get Current User's Assigned Shop ID
function sywat_get_current_user_shop_id() {
    $user = wp_get_current_user();
    if ( ! $user || ! $user->ID ) return 'shop_ccr';

    if ( array_intersect( array( 'administrator' ), (array) $user->roles ) ) {
        if ( isset( $_REQUEST['sywat_shop_filter'] ) ) {
            return sanitize_text_field( $_REQUEST['sywat_shop_filter'] );
        }
    }

    $shop_id = get_user_meta( $user->ID, 'sywat_shop_id', true );
    return ! empty( $shop_id ) ? $shop_id : 'shop_ccr';
}

// WP Admin User Profile Hooks (Users > Edit User) - Restricted to Shop Roles Only
add_action( 'show_user_profile', 'sywat_render_user_profile_shop_field' );
add_action( 'edit_user_profile', 'sywat_render_user_profile_shop_field' );
function sywat_render_user_profile_shop_field( $user ) {
    if ( ! current_user_can( 'administrator' ) ) return;
    
    // Only display on profiles for Shop Manager and Shop User roles
    if ( ! array_intersect( array( 'sywat_shop_manager', 'sywat_shop_user' ), (array) $user->roles ) ) {
        return;
    }

    $current_shop = get_user_meta( $user->ID, 'sywat_shop_id', true );
    if ( empty( $current_shop ) ) $current_shop = 'shop_ccr';
    $shops = sywat_get_shops();
    ?>
    <h3>SYWAT Tournament Shop Assignment</h3>
    <table class="form-table">
        <tr>
            <th><label for="sywat_shop_id">Assigned Archery Shop</label></th>
            <td>
                <select name="sywat_shop_id" id="sywat_shop_id">
                    <?php foreach ( $shops as $s_id => $shop ): ?>
                        <option value="<?php echo esc_attr( $s_id ); ?>" <?php selected( $current_shop, $s_id ); ?>>
                            <?php echo esc_html( $shop['name'] ); ?> (ID: <?php echo esc_html( $s_id ); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Select the shop this user account belongs to for tournament management and scoping.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'sywat_save_user_profile_shop_field' );
add_action( 'edit_user_profile_update', 'sywat_save_user_profile_shop_field' );
function sywat_save_user_profile_shop_field( $user_id ) {
    if ( ! current_user_can( 'administrator' ) ) return;
    if ( isset( $_POST['sywat_shop_id'] ) ) {
        update_user_meta( $user_id, 'sywat_shop_id', sanitize_text_field( $_POST['sywat_shop_id'] ) );
    }
}

// Shop Management Action Handler (Create, Update, Delete, Staff Assign)
function sywat_handle_shop_actions() {
    if ( ! current_user_can( 'administrator' ) ) return '';
    if ( ! isset( $_POST['sywat_shop_action'] ) || ! isset( $_POST['sywat_nonce'] ) ) return '';
    if ( ! wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_desk_action' ) ) return '';

    $action = sanitize_text_field( $_POST['sywat_shop_action'] );
    $shops  = sywat_get_shops();

    if ( $action === 'create_shop' ) {
        $shop_name = trim( sanitize_text_field( $_POST['shop_name'] ) );
        if ( empty( $shop_name ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Shop Name is required.</div>';
        }

        $shop_slug = 'shop_' . sanitize_title( $shop_name );
        if ( isset( $shops[$shop_slug] ) ) {
            $shop_slug .= '_' . substr( md5( time() ), 0, 4 );
        }

        $default_lines = array(
            array( 'name' => 'Saturday 10AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Saturday 2PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Sunday 10AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Sunday 2PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
        );

        $shops[$shop_slug] = array(
            'id'            => $shop_slug,
            'name'          => $shop_name,
            'contact'       => sanitize_text_field( $_POST['shop_contact'] ),
            'email'         => sanitize_email( $_POST['shop_email'] ),
            'phone'         => sanitize_text_field( $_POST['shop_phone'] ),
            'default_lines' => $default_lines,
            'created_at'    => current_time( 'mysql' ),
        );

        update_option( 'sywat_shops_list', $shops );
        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully created shop <strong>' . esc_html( $shop_name ) . '</strong> (ID: <code>' . esc_html($shop_slug) . '</code>).</div>';
    }

    if ( $action === 'update_shop' ) {
        $shop_id = sanitize_text_field( $_POST['shop_id'] );
        if ( isset( $shops[$shop_id] ) ) {
            $shops[$shop_id]['name']    = sanitize_text_field( $_POST['shop_name'] );
            $shops[$shop_id]['contact'] = sanitize_text_field( $_POST['shop_contact'] );
            $shops[$shop_id]['email']   = sanitize_email( $_POST['shop_email'] );
            $shops[$shop_id]['phone']   = sanitize_text_field( $_POST['shop_phone'] );

            if ( ! empty( $_POST['line_names'] ) && is_array( $_POST['line_names'] ) ) {
                $new_lines = array();
                foreach ( $_POST['line_names'] as $index => $name ) {
                    if ( trim( $name ) !== '' ) {
                        $new_lines[] = array(
                            'name'     => sanitize_text_field( $name ),
                            'cap_20yd' => isset( $_POST['cap_20yd'][$index] ) ? intval( $_POST['cap_20yd'][$index] ) : 20,
                            'cap_cub'  => isset( $_POST['cap_cub'][$index] ) ? intval( $_POST['cap_cub'][$index] ) : 5,
                        );
                    }
                }
                if ( ! empty( $new_lines ) ) {
                    $shops[$shop_id]['default_lines'] = $new_lines;
                }
            }

            update_option( 'sywat_shops_list', $shops );
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully updated shop details for <strong>' . esc_html( $shops[$shop_id]['name'] ) . '</strong>.</div>';
        }
    }

    if ( $action === 'delete_shop' ) {
        $shop_id = sanitize_text_field( $_POST['shop_id'] );
        if ( $shop_id === 'shop_ccr' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">The primary default shop (Cinnamon Creek Ranch) cannot be deleted.</div>';
        }

        if ( isset( $shops[$shop_id] ) ) {
            $deleted_name = $shops[$shop_id]['name'];
            unset( $shops[$shop_id] );
            update_option( 'sywat_shops_list', $shops );

            $assigned_users = get_users( array( 'meta_key' => 'sywat_shop_id', 'meta_value' => $shop_id ) );
            foreach ( $assigned_users as $u ) {
                update_user_meta( $u->ID, 'sywat_shop_id', 'shop_ccr' );
            }

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully deleted shop <strong>' . esc_html( $deleted_name ) . '</strong>. Reassigned associated staff to Cinnamon Creek Ranch.</div>';
        }
    }

    if ( $action === 'assign_staff_shop' ) {
        $target_user_id = intval( $_POST['staff_user_id'] );
        $target_shop_id = sanitize_text_field( $_POST['staff_shop_id'] );

        if ( $target_user_id > 0 ) {
            update_user_meta( $target_user_id, 'sywat_shop_id', $target_shop_id );
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Updated shop assignment for user.</div>';
        }
    }

    return '';
}

// Render Shops & Staff Tab UI
if ( ! function_exists( 'sywat_render_shops_tab' ) ) {
    function sywat_render_shops_tab() {
        if ( ! current_user_can( 'administrator' ) ) {
            echo '<p style="color:red; padding:20px;">Administrator access required to manage shops.</p>';
            return;
        }

        $notice = sywat_handle_shop_actions();
        $shops  = sywat_get_shops();

        $edit_target_id = isset( $_REQUEST['edit_shop'] ) ? sanitize_text_field( $_REQUEST['edit_shop'] ) : '';
        $editing_shop   = ( ! empty( $edit_target_id ) && isset( $shops[$edit_target_id] ) ) ? $shops[$edit_target_id] : null;

        // Strictly query ONLY users with SYWAT Shop Manager or SYWAT Shop User roles
        $staff_users = get_users( array(
            'role__in' => array( 'sywat_shop_manager', 'sywat_shop_user' ),
            'orderby'  => 'display_name'
        ) );
        ?>
        <div class="no-print">
            <?php echo $notice; ?>
        </div>

        <?php if ( $editing_shop ): ?>
            <!-- EDIT EXISTING SHOP CARD -->
            <div class="sywat-card no-print">
                <div class="sywat-card-title">
                    <span>✏️ Edit Archery Shop: <?php echo esc_html( $editing_shop['name'] ); ?></span>
                    <a href="?tab=shops" class="sywat-btn-secondary" style="font-size:12px; height:28px;">✖ Cancel Editing</a>
                </div>
                <div class="sywat-subtitle">Update contact details and default tournament line schedule templates for this location.</div>

                <form method="post" action="?tab=shops">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_shop_action" value="update_shop">
                    <input type="hidden" name="shop_id" value="<?php echo esc_attr( $editing_shop['id'] ); ?>">

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div class="sywat-form-group">
                            <label>Shop Name *</label>
                            <input type="text" name="shop_name" value="<?php echo esc_attr( $editing_shop['name'] ); ?>" required>
                        </div>
                        <div class="sywat-form-group">
                            <label>Primary Contact Name(s)</label>
                            <input type="text" name="shop_contact" value="<?php echo esc_attr( isset($editing_shop['contact']) ? $editing_shop['contact'] : '' ); ?>">
                        </div>
                        <div class="sywat-form-group">
                            <label>Contact Email</label>
                            <input type="email" name="shop_email" value="<?php echo esc_attr( isset($editing_shop['email']) ? $editing_shop['email'] : '' ); ?>">
                        </div>
                        <div class="sywat-form-group">
                            <label>Contact Phone</label>
                            <input type="text" name="shop_phone" value="<?php echo esc_attr( isset($editing_shop['phone']) ? $editing_shop['phone'] : '' ); ?>">
                        </div>
                    </div>

                    <h4 style="margin:20px 0 10px 0; font-size:14px; font-weight:600;">Default Shooting Lines & Capacity Templates</h4>
                    <table class="sywat-table" style="margin-bottom:15px;">
                        <thead>
                            <tr>
                                <th>Line Day / Time</th>
                                <th style="width:150px;">Default 20yd Cap</th>
                                <th style="width:150px;">Default Cub Cap</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                                $lines = isset( $editing_shop['default_lines'] ) && is_array( $editing_shop['default_lines'] ) 
                                    ? $editing_shop['default_lines'] 
                                    : array(
                                        array( 'name' => 'Saturday 10AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
                                        array( 'name' => 'Saturday 2PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
                                        array( 'name' => 'Sunday 10AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
                                        array( 'name' => 'Sunday 2PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
                                    );
                                foreach ( $lines as $i => $l ): 
                            ?>
                                <tr>
                                    <td><input type="text" name="line_names[]" value="<?php echo esc_attr( $l['name'] ); ?>" required></td>
                                    <td><input type="number" name="cap_20yd[]" value="<?php echo esc_attr( $l['cap_20yd'] ); ?>" min="0" required></td>
                                    <td><input type="number" name="cap_cub[]" value="<?php echo esc_attr( $l['cap_cub'] ); ?>" min="0" required></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="display:flex; gap:10px;">
                        <button type="submit" class="sywat-btn-primary" style="height:auto; padding:8px 16px;">💾 Save Shop Changes</button>
                        <a href="?tab=shops" class="sywat-btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- CREATE NEW SHOP CARD -->
            <div class="sywat-card no-print">
                <div class="sywat-card-title">
                    <span>🏬 Create New Archery Shop</span>
                </div>
                <div class="sywat-subtitle">Define shop profile details and auto-generate unique Shop ID for multi-shop scoping.</div>

                <form method="post" action="?tab=shops">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_shop_action" value="create_shop">

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div class="sywat-form-group">
                            <label>Shop Name *</label>
                            <input type="text" name="shop_name" placeholder="e.g. Cinnamon Creek Ranch" required>
                        </div>
                        <div class="sywat-form-group">
                            <label>Primary Contact Name(s)</label>
                            <input type="text" name="shop_contact" placeholder="e.g. Hannah Lange / Beau Brennan">
                        </div>
                        <div class="sywat-form-group">
                            <label>Contact Email</label>
                            <input type="email" name="shop_email" placeholder="e.g. events@cinnamoncreekranch.com">
                        </div>
                        <div class="sywat-form-group">
                            <label>Contact Phone</label>
                            <input type="text" name="shop_phone" placeholder="e.g. 817-439-8998">
                        </div>
                    </div>

                    <button type="submit" class="sywat-btn-primary" style="height:auto; padding:8px 16px;">➕ Create Shop Profile</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- SHOP DIRECTORY TABLE -->
        <div class="sywat-card no-print">
            <div class="sywat-card-title">
                <span>📋 Configured Archery Shops</span>
            </div>
            <table class="sywat-table">
                <thead>
                    <tr>
                        <th>Shop Name & ID</th>
                        <th>Contact Info</th>
                        <th>Phone</th>
                        <th>Created Date</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $shops as $s_id => $shop ): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $shop['name'] ); ?></strong><br>
                                <code style="font-size:11px; color:#646970;"><?php echo esc_html( $s_id ); ?></code>
                            </td>
                            <td>
                                <?php echo esc_html( isset($shop['contact']) ? $shop['contact'] : 'N/A' ); ?><br>
                                <span style="font-size:12px; color:#646970;"><?php echo esc_html( isset($shop['email']) ? $shop['email'] : '' ); ?></span>
                            </td>
                            <td><?php echo esc_html( isset($shop['phone']) ? $shop['phone'] : 'N/A' ); ?></td>
                            <td style="font-size:12px; color:#646970;"><?php echo esc_html( isset($shop['created_at']) ? $shop['created_at'] : 'Default' ); ?></td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex; gap:6px; justify-content:flex-end;">
                                    <a href="?tab=shops&edit_shop=<?php echo urlencode( $s_id ); ?>" class="sywat-btn-primary" style="background:#6c757d; border-color:#6c757d; padding:3px 8px; font-size:11px; height:auto;">✏️ Edit</a>
                                    
                                    <?php if ( $s_id !== 'shop_ccr' ): ?>
                                        <form method="post" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete \'<?php echo esc_js( $shop['name'] ); ?>\'? Any assigned staff will be reassigned to Cinnamon Creek Ranch.');">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_shop_action" value="delete_shop">
                                            <input type="hidden" name="shop_id" value="<?php echo esc_attr( $s_id ); ?>">
                                            <button type="submit" class="sywat-btn-danger" style="padding:3px 8px; font-size:11px; height:auto;">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- STAFF ASSIGNMENTS TABLE -->
        <div class="sywat-card no-print">
            <div class="sywat-card-title">
                <span>👤 Shop Staff Assignments</span>
            </div>
            <div class="sywat-subtitle">Map users with the SYWAT Shop Manager or SYWAT Shop User roles to their assigned archery shop.</div>

            <?php if ( empty( $staff_users ) ): ?>
                <p style="color: #646970; font-style: italic; margin-top: 10px;">No user accounts with <strong>SYWAT Shop Manager</strong> or <strong>SYWAT Shop User</strong> roles currently exist. Assign those roles to user accounts under <strong>WP Admin > Users</strong> to manage their shop context here.</p>
            <?php else: ?>
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th>User Display Name</th>
                            <th>Username / Email</th>
                            <th>WP Role</th>
                            <th>Assigned Shop</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $staff_users as $s_user ): ?>
                            <?php 
                                $assigned_s = get_user_meta( $s_user->ID, 'sywat_shop_id', true );
                                if ( empty( $assigned_s ) ) $assigned_s = 'shop_ccr';
                                $user_roles = implode( ', ', $s_user->roles );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $s_user->display_name ); ?></strong></td>
                                <td><?php echo esc_html( $s_user->user_login ); ?><br><span style="font-size:11px; color:#646970;"><?php echo esc_html( $s_user->user_email ); ?></span></td>
                                <td><span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $user_roles ); ?></span></td>
                                <td>
                                    <form method="post" style="margin:0; display:flex; gap:8px; align-items:center;">
                                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                        <input type="hidden" name="sywat_shop_action" value="assign_staff_shop">
                                        <input type="hidden" name="staff_user_id" value="<?php echo esc_attr( $s_user->ID ); ?>">
                                        <select name="staff_shop_id" style="padding:4px 8px; font-size:12px; border-radius:4px; height:30px;">
                                            <?php foreach ( $shops as $s_id => $shop ): ?>
                                                <option value="<?php echo esc_attr( $s_id ); ?>" <?php selected( $assigned_s, $s_id ); ?>>
                                                    <?php echo esc_html( $shop['name'] ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                </td>
                                <td style="text-align:right;">
                                        <button type="submit" class="sywat-btn-primary" style="height:30px; font-size:12px; padding:0 10px;">Update</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
