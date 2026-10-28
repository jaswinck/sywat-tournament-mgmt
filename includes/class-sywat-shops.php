<?php
/**
 * Module: Shop Management & Multi-Tenant Permissions
 * Description: Shop CRUD, user shop mapping, shop defaults, and admin staff assignment UI.
 * Author: SYWAT Archery
 * Version: 1.1.0
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

// WP Admin User Profile Hooks (Users > Edit User)
add_action( 'show_user_profile', 'sywat_render_user_profile_shop_field' );
add_action( 'edit_user_profile', 'sywat_render_user_profile_shop_field' );
function sywat_render_user_profile_shop_field( $user ) {
    if ( ! current_user_can( 'administrator' ) ) return;
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

// Shop Management Action Handler
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

        $staff_users = get_users( array(
            'role__in' => array( 'administrator', 'management', 'sywat_shop_manager', 'sywat_shop_user' ),
            'orderby'  => 'display_name'
        ) );
        ?>
        <div class="no-print">
            <?php echo $notice; ?>
        </div>

        <div class="sywat-card no-print">
            <div class="sywat-card-title">
                <span>🏬 Create New Archery Shop</span>
            </div>
            <div class="sywat-subtitle">Define shop profile details and auto-generate unique Shop ID for multi-shop scoping.</div>

            <form method="post">
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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="sywat-card no-print">
            <div class="sywat-card-title">
                <span>👤 Staff Shop Assignments</span>
            </div>
            <div class="sywat-subtitle">Assign managers and staff users to their designated archery shop context.</div>

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
        </div>
        <?php
    }
}
