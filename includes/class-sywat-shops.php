<?php
/**
 * Module: Shop Management & Multi-Tenant Permissions
 * Description: Shop CRUD, modal shop creation, optional address fields, phone auto-formatting (XXX-XXX-XXXX), line templates, explicit staff linking UI.
 * Author: SYWAT Archery
 * Version: 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Helper: Standardize Phone Number Formatting (XXX-XXX-XXXX)
function sywat_format_phone_number( $phone ) {
    $digits = preg_replace( '/\D/', '', $phone );
    if ( strlen( $digits ) === 10 ) {
        return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
    }
    return sanitize_text_field( $phone );
}

// Ensure default seed shop exists upon loading
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
                'email'         => 'events@cinnamoncreekranch.com / beau@cinnamoncreekranch.com',
                'phone'         => '817-439-8998',
                'address_1'     => '13795 Old Denton Rd',
                'address_2'     => '',
                'city'          => 'Roanoke',
                'state'         => 'TX',
                'zip'           => '76262',
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
    
    if ( ! array_intersect( array( 'sywat_shop_manager', 'sywat_shop_user' ), (array) $user->roles ) ) {
        return;
    }

    $current_shop = get_user_meta( $user->ID, 'sywat_shop_id', true );
    $shops = sywat_get_shops();
    ?>
    <h3>SYWAT Tournament Shop Assignment</h3>
    <table class="form-table">
        <tr>
            <th><label for="sywat_shop_id">Assigned Archery Shop</label></th>
            <td>
                <select name="sywat_shop_id" id="sywat_shop_id">
                    <option value="" <?php selected( empty( $current_shop ), true ); ?>>-- Unassigned (No Shop Linked) --</option>
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
        $val = sanitize_text_field( $_POST['sywat_shop_id'] );
        if ( empty( $val ) ) {
            delete_user_meta( $user_id, 'sywat_shop_id' );
        } else {
            update_user_meta( $user_id, 'sywat_shop_id', $val );
        }
    }
}

// Shop Action Handlers
function sywat_handle_shop_actions() {
    if ( ! current_user_can( 'administrator' ) ) return '';
    if ( ! isset( $_POST['sywat_shop_action'] ) || ! isset( $_POST['sywat_nonce'] ) ) return '';
    if ( ! wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_desk_action' ) ) return '';

    $action = sanitize_text_field( $_POST['sywat_shop_action'] );
    $shops  = sywat_get_shops();

    if ( $action === 'create_shop' ) {
        $shop_name = trim( sanitize_text_field( $_POST['shop_name'] ) );
        if ( empty( $shop_name ) ) {
            return '<div id="sywat-notice" style="background:#f8d7da; color:#721c24; padding:10px; margin-bottom:15px; border:1px solid #f5c6cb; border-radius:4px;">Shop Name is required.</div>';
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
            'email'         => sanitize_text_field( $_POST['shop_email'] ),
            'phone'         => sywat_format_phone_number( $_POST['shop_phone'] ),
            'address_1'     => sanitize_text_field( $_POST['shop_address_1'] ),
            'address_2'     => sanitize_text_field( $_POST['shop_address_2'] ),
            'city'          => sanitize_text_field( $_POST['shop_city'] ),
            'state'         => sanitize_text_field( $_POST['shop_state'] ),
            'zip'           => sanitize_text_field( $_POST['shop_zip'] ),
            'default_lines' => $default_lines,
            'created_at'    => current_time( 'mysql' ),
        );

        update_option( 'sywat_shops_list', $shops );
        return '<div id="sywat-notice" style="background:#d4edda; color:#155724; padding:10px; margin-bottom:15px; border:1px solid #c3e6cb; border-radius:4px; font-weight:bold;">Successfully created shop ' . esc_html( $shop_name ) . '.</div>';
    }

    if ( $action === 'update_shop' ) {
        $shop_id = sanitize_text_field( $_POST['shop_id'] );
        if ( isset( $shops[$shop_id] ) ) {
            $shops[$shop_id]['name']      = sanitize_text_field( $_POST['shop_name'] );
            $shops[$shop_id]['contact']   = sanitize_text_field( $_POST['shop_contact'] );
            $shops[$shop_id]['email']     = sanitize_text_field( $_POST['shop_email'] );
            $shops[$shop_id]['phone']     = sywat_format_phone_number( $_POST['shop_phone'] );
            $shops[$shop_id]['address_1'] = sanitize_text_field( $_POST['shop_address_1'] );
            $shops[$shop_id]['address_2'] = sanitize_text_field( $_POST['shop_address_2'] );
            $shops[$shop_id]['city']      = sanitize_text_field( $_POST['shop_city'] );
            $shops[$shop_id]['state']     = sanitize_text_field( $_POST['shop_state'] );
            $shops[$shop_id]['zip']       = sanitize_text_field( $_POST['shop_zip'] );

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
            return '<div id="sywat-notice" style="background:#d4edda; color:#155724; padding:10px; margin-bottom:15px; border:1px solid #c3e6cb; border-radius:4px; font-weight:bold;">Successfully updated shop details for ' . esc_html( $shops[$shop_id]['name'] ) . '.</div>';
        }
    }

    if ( $action === 'delete_shop' ) {
        $shop_id = sanitize_text_field( $_POST['shop_id'] );
        if ( $shop_id === 'shop_ccr' ) {
            return '<div id="sywat-notice" style="background:#f8d7da; color:#721c24; padding:10px; margin-bottom:15px; border:1px solid #f5c6cb; border-radius:4px;">The primary default shop (Cinnamon Creek Ranch) cannot be deleted.</div>';
        }

        if ( isset( $shops[$shop_id] ) ) {
            $deleted_name = $shops[$shop_id]['name'];
            unset( $shops[$shop_id] );
            update_option( 'sywat_shops_list', $shops );

            // Unlink all assigned staff completely without reassigning
            $assigned_users = get_users( array( 'meta_key' => 'sywat_shop_id', 'meta_value' => $shop_id ) );
            foreach ( $assigned_users as $u ) {
                delete_user_meta( $u->ID, 'sywat_shop_id' );
            }

            return '<div id="sywat-notice" style="background:#d4edda; color:#155724; padding:10px; margin-bottom:15px; border:1px solid #c3e6cb; border-radius:4px; font-weight:bold;">Successfully deleted shop ' . esc_html( $deleted_name ) . '.</div>';
        }
    }

    if ( $action === 'assign_staff_to_shop' ) {
        $target_user_id = intval( $_POST['staff_user_id'] );
        $target_shop_id = sanitize_text_field( $_POST['target_shop_id'] );

        if ( $target_user_id > 0 && isset( $shops[$target_shop_id] ) ) {
            update_user_meta( $target_user_id, 'sywat_shop_id', $target_shop_id );
            $u = get_userdata( $target_user_id );
            $u_name = ( $u && isset( $u->display_name ) ) ? $u->display_name : 'User';
            return '<div id="sywat-notice" style="background:#d4edda; color:#155724; padding:10px; margin-bottom:15px; border:1px solid #c3e6cb; border-radius:4px; font-weight:bold;">Assigned ' . esc_html( $u_name ) . ' to ' . esc_html( $shops[$target_shop_id]['name'] ) . '.</div>';
        }
    }

    if ( $action === 'remove_staff_from_shop' ) {
        $target_user_id = intval( $_POST['staff_user_id'] );
        if ( $target_user_id > 0 ) {
            delete_user_meta( $target_user_id, 'sywat_shop_id' );
            $u = get_userdata( $target_user_id );
            $u_name = ( $u && isset( $u->display_name ) ) ? $u->display_name : 'User';
            return '<div id="sywat-notice" style="background:#d4edda; color:#155724; padding:10px; margin-bottom:15px; border:1px solid #c3e6cb; border-radius:4px; font-weight:bold;">Unlinked ' . esc_html( $u_name ) . ' from shop assignment.</div>';
        }
    }

    return '';
}

// Render Shops UI Tab
function sywat_render_shops_tab() {
    if ( ! current_user_can( 'administrator' ) ) {
        echo '<p style="color:red; padding:20px;">Administrator access required to manage shops.</p>';
        return;
    }

    $notice     = sywat_handle_shop_actions();
    $all_shops  = sywat_get_shops();

    $edit_target_id   = isset( $_REQUEST['edit_shop'] ) ? sanitize_text_field( $_REQUEST['edit_shop'] ) : '';
    $editing_shop     = ( ! empty( $edit_target_id ) && isset( $all_shops[$edit_target_id] ) ) ? $all_shops[$edit_target_id] : null;

    $manage_staff_id  = isset( $_REQUEST['manage_staff'] ) ? sanitize_text_field( $_REQUEST['manage_staff'] ) : '';
    $managing_shop    = ( ! empty( $manage_staff_id ) && isset( $all_shops[$manage_staff_id] ) ) ? $all_shops[$manage_staff_id] : null;

    // Query all users eligible for shop assignments
    $eligible_users = get_users( array(
        'role__in' => array( 'sywat_shop_manager', 'sywat_shop_user' ),
        'orderby'  => 'display_name'
    ) );
    ?>
    <style>
        .sywat-btn { border: none; padding: 6px 10px; border-radius: 4px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; line-height: 1.2; box-sizing: border-box; white-space: nowrap; }
        .sywat-btn-green { background: #28a745; color: #fff !important; }
        .sywat-btn-green:hover { background: #218838; }
        .sywat-btn-blue { background: #0073aa; color: #fff !important; }
        .sywat-btn-blue:hover { background: #005177; }
        .sywat-btn-gray { background: #6c757d; color: #fff !important; }
        .sywat-btn-gray:hover { background: #5a6268; }
        .sywat-btn-red { background: #dc3545; color: #fff !important; }
        .sywat-btn-red:hover { background: #c82333; }
        .sywat-email-link { font-size: 12px; color: #0073aa; word-break: break-all; display: block; line-height: 1.4; text-decoration: none; }
        .sywat-email-link:hover { text-decoration: underline; }
        .sywat-shop-card { background: #fff; border: 1px solid #ccc; border-radius: 6px; padding: 20px; margin-top: 20px; }
        .sywat-shop-card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 8px; font-size: 18px; }
        .sywat-shop-field { margin-bottom: 12px; }
        .sywat-shop-field label { display: block; font-weight: bold; margin-bottom: 4px; font-size: 13px; }
        .sywat-shop-field select, .sywat-shop-field input[type="text"], .sywat-shop-field input[type="number"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .badge-type { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; white-space: nowrap; }
        .badge-prereg { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .sywat-modal-bg { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center; }
        .sywat-modal-card { background: #fff; padding: 25px; border-radius: 8px; width: 100%; max-width: 540px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto; }
    </style>

    <div class="no-print">
        <?php echo $notice; ?>
    </div>

    <?php if ( $managing_shop ): ?>
        <!-- VIEW 1: MANAGE STAFF LINKED TO SPECIFIC SHOP -->
        <?php 
            $linked_staff = array();
            $unlinked_staff = array();

            foreach ( $eligible_users as $u ) {
                $u_shop = get_user_meta( $u->ID, 'sywat_shop_id', true );

                if ( $u_shop === $managing_shop['id'] ) {
                    $linked_staff[] = $u;
                } else {
                    $unlinked_staff[] = $u;
                }
            }
        ?>
        <div class="sywat-shop-card no-print">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:8px;">
                <h3 style="margin:0; border:none; padding:0;">👤 Linked Staff for Shop: <strong><?php echo esc_html( $managing_shop['name'] ); ?></strong></h3>
                <a href="?tab=shops" class="sywat-btn sywat-btn-gray">← Back to Shops List</a>
            </div>

            <div style="margin-bottom:20px; background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:4px;">
                <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:bold;">Link Additional Staff User to This Shop</h4>
                <?php if ( empty( $unlinked_staff ) ): ?>
                    <p style="color:#646970; font-style:italic; margin:0; font-size:13px;">All registered Shop Managers and Shop Users are currently linked to a shop.</p>
                <?php else: ?>
                    <form method="post" action="?tab=shops&manage_staff=<?php echo urlencode($managing_shop['id']); ?>" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                        <input type="hidden" name="sywat_shop_action" value="assign_staff_to_shop">
                        <input type="hidden" name="target_shop_id" value="<?php echo esc_attr( $managing_shop['id'] ); ?>">

                        <div class="sywat-shop-field" style="flex:1; min-width:250px; margin-bottom:0;">
                            <label>Select Available Staff User</label>
                            <select name="staff_user_id" required>
                                <?php foreach ( $unlinked_staff as $un_user ): ?>
                                    <?php 
                                        $curr_s_id = get_user_meta( $un_user->ID, 'sywat_shop_id', true );
                                        $curr_s_name = isset( $all_shops[$curr_s_id]['name'] ) ? $all_shops[$curr_s_id]['name'] : 'Unassigned';
                                    ?>
                                    <option value="<?php echo esc_attr( $un_user->ID ); ?>">
                                        <?php echo esc_html( $un_user->display_name ); ?> (Currently: <?php echo esc_html( $curr_s_name ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="sywat-btn sywat-btn-green">Link User to <?php echo esc_html( $managing_shop['name'] ); ?></button>
                    </form>
                <?php endif; ?>
            </div>

            <h4 style="margin:15px 0 10px 0; font-size:14px; font-weight:bold;">Currently Linked Staff Accounts (<?php echo count($linked_staff); ?>)</h4>
            <?php if ( empty( $linked_staff ) ): ?>
                <p style="color:#646970; font-style:italic; margin-bottom:15px;">No Shop Manager or Shop User accounts are currently linked to this location.</p>
            <?php else: ?>
                <table style="width:100%; border-collapse:collapse; font-size:14px; text-align:left;">
                    <thead>
                        <tr style="background:#f1f1f1; border-bottom:2px solid #ccc;">
                            <th style="padding:8px;">User Display Name</th>
                            <th style="padding:8px;">Username / Email</th>
                            <th style="padding:8px;">WP Role</th>
                            <th style="padding:8px; text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $linked_staff as $ls_user ): ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:8px; font-weight:bold;"><?php echo esc_html( $ls_user->display_name ); ?></td>
                                <td style="padding:8px;"><?php echo esc_html( $ls_user->user_login ); ?><br><span style="font-size:11px; color:#646970;"><?php echo esc_html( $ls_user->user_email ); ?></span></td>
                                <td style="padding:8px;"><span class="badge-type badge-prereg"><?php echo esc_html( implode( ', ', $ls_user->roles ) ); ?></span></td>
                                <td style="padding:8px; text-align:right;">
                                    <form method="post" action="?tab=shops&manage_staff=<?php echo urlencode($managing_shop['id']); ?>" style="margin:0;" onsubmit="return confirm('Remove <?php echo esc_js( $ls_user->display_name ); ?> from this shop?');">
                                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                        <input type="hidden" name="sywat_shop_action" value="remove_staff_from_shop">
                                        <input type="hidden" name="staff_user_id" value="<?php echo esc_attr( $ls_user->ID ); ?>">
                                        <button type="submit" class="sywat-btn sywat-btn-red" style="padding:4px 8px; font-size:12px;">Unlink Staff</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php elseif ( $editing_shop ): ?>
        <!-- VIEW 2: EDIT SHOP DETAILS & LINE DEFAULT SCHEDULING -->
        <div class="sywat-shop-card no-print">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:8px;">
                <h3 style="margin:0; border:none; padding:0;">✏️ Edit Archery Shop: <?php echo esc_html( $editing_shop['name'] ); ?></h3>
                <a href="?tab=shops" class="sywat-btn sywat-btn-gray">Cancel Editing</a>
            </div>

            <form method="post" action="?tab=shops">
                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                <input type="hidden" name="sywat_shop_action" value="update_shop">
                <input type="hidden" name="shop_id" value="<?php echo esc_attr( $editing_shop['id'] ); ?>">

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="sywat-shop-field">
                        <label>Shop Name *</label>
                        <input type="text" name="shop_name" value="<?php echo esc_attr( $editing_shop['name'] ); ?>" required>
                    </div>
                    <div class="sywat-shop-field">
                        <label>Primary Contact Name(s)</label>
                        <input type="text" name="shop_contact" value="<?php echo esc_attr( isset($editing_shop['contact']) ? $editing_shop['contact'] : '' ); ?>">
                    </div>
                    <div class="sywat-shop-field">
                        <label>Contact Email(s)</label>
                        <input type="text" name="shop_email" value="<?php echo esc_attr( isset($editing_shop['email']) ? $editing_shop['email'] : '' ); ?>" placeholder="e.g. events@domain.com, beau@domain.com">
                    </div>
                    <div class="sywat-shop-field">
                        <label>Contact Phone</label>
                        <input type="text" name="shop_phone" class="sywat-phone-input" value="<?php echo esc_attr( isset($editing_shop['phone']) ? $editing_shop['phone'] : '' ); ?>" placeholder="817-439-8998">
                    </div>
                </div>

                <!-- OPTIONAL ADDRESS FIELDS -->
                <h4 style="margin:20px 0 10px 0; font-size:15px; font-weight:bold;">Location Address Details (Optional)</h4>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="sywat-shop-field">
                        <label>Address Line 1</label>
                        <input type="text" name="shop_address_1" value="<?php echo esc_attr( isset($editing_shop['address_1']) ? $editing_shop['address_1'] : '' ); ?>" placeholder="e.g. 13795 Old Denton Rd">
                    </div>
                    <div class="sywat-shop-field">
                        <label>Address Line 2</label>
                        <input type="text" name="shop_address_2" value="<?php echo esc_attr( isset($editing_shop['address_2']) ? $editing_shop['address_2'] : '' ); ?>" placeholder="Suite, Bldg, etc.">
                    </div>
                    <div class="sywat-shop-field">
                        <label>City</label>
                        <input type="text" name="shop_city" value="<?php echo esc_attr( isset($editing_shop['city']) ? $editing_shop['city'] : '' ); ?>" placeholder="e.g. Roanoke">
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <div class="sywat-shop-field">
                            <label>State</label>
                            <input type="text" name="shop_state" value="<?php echo esc_attr( isset($editing_shop['state']) ? $editing_shop['state'] : '' ); ?>" placeholder="e.g. TX">
                        </div>
                        <div class="sywat-shop-field">
                            <label>ZIP Code</label>
                            <input type="text" name="shop_zip" value="<?php echo esc_attr( isset($editing_shop['zip']) ? $editing_shop['zip'] : '' ); ?>" placeholder="e.g. 76262">
                        </div>
                    </div>
                </div>

                <h4 style="margin:20px 0 10px 0; font-size:15px; font-weight:bold;">Default Shooting Lines & Capacity Templates</h4>
                <table style="width:100%; border-collapse:collapse; font-size:14px; text-align:left; margin-bottom:20px;">
                    <thead>
                        <tr style="background:#f1f1f1; border-bottom:2px solid #ccc;">
                            <th style="padding:8px;">Line Day / Time</th>
                            <th style="padding:8px; width:180px;">Default 20yd Cap</th>
                            <th style="padding:8px; width:180px;">Default Cub Cap</th>
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
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:6px;"><input type="text" name="line_names[]" value="<?php echo esc_attr( $l['name'] ); ?>" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
                                <td style="padding:6px;"><input type="number" name="cap_20yd[]" value="<?php echo esc_attr( $l['cap_20yd'] ); ?>" min="0" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
                                <td style="padding:6px;"><input type="number" name="cap_cub[]" value="<?php echo esc_attr( $l['cap_cub'] ); ?>" min="0" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="display:flex; gap:10px;">
                    <button type="submit" class="sywat-btn sywat-btn-green" style="padding:8px 16px;">💾 Save Shop Changes</button>
                    <a href="?tab=shops" class="sywat-btn sywat-btn-gray">Cancel</a>
                </div>
            </form>
        </div>

    <?php else: ?>
        <!-- VIEW 3: MAIN SHOPS DIRECTORY & MODAL SHOP CREATION -->
        <div class="sywat-shop-card no-print">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                <h3 style="margin:0; border:none; padding:0;">Configured Archery Shops</h3>
                <div style="display:flex; gap:8px; align-items:center;">
                    <div style="display:flex; gap:5px; margin:0; align-items:center;">
                        <input type="text" id="sywat_shop_search_input" placeholder="Search shop name..." autocomplete="off" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:180px;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                        <button type="button" class="sywat-btn sywat-btn-gray" style="font-size:13px; line-height:22px;" onclick="document.getElementById('sywat_shop_search_input').value=''; document.getElementById('sywat_shop_search_input').dispatchEvent(new Event('input'));">Clear</button>
                    </div>
                    <button type="button" class="sywat-btn sywat-btn-green" onclick="openAddShopModal()">+ Add Shop</button>
                </div>
            </div>

            <?php if ( empty( $all_shops ) ): ?>
                <p style="color:#666;">No archery shops currently configured.</p>
            <?php else: ?>
                <table style="width:100%; border-collapse:collapse; font-size:14px; text-align:left;">
                    <thead>
                        <tr style="background:#f1f1f1; border-bottom:2px solid #ccc;">
                            <th style="padding:8px; width:30%;">Shop Name & ID</th>
                            <th style="padding:8px; width:30%;">Contact Info</th>
                            <th style="padding:8px; width:15%;">Phone</th>
                            <th style="padding:8px; text-align:left; width:25%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $all_shops as $s_id => $shop ): ?>
                            <?php 
                                $linked_count = 0;
                                foreach ( $eligible_users as $u ) {
                                    $u_shop = get_user_meta( $u->ID, 'sywat_shop_id', true );
                                    if ( $u_shop === $s_id ) $linked_count++;
                                }
                                $s_contact = isset($shop['contact']) ? $shop['contact'] : '';
                            ?>
                            <tr class="sywat-shop-row" data-name="<?php echo esc_attr(strtolower($shop['name'])); ?>" data-id="<?php echo esc_attr(strtolower($s_id)); ?>" data-contact="<?php echo esc_attr(strtolower($s_contact)); ?>" style="border-bottom:1px solid #eee;">
                                <td style="padding:8px;">
                                    <strong><?php echo esc_html( $shop['name'] ); ?></strong><br>
                                    <code style="font-size:11px; color:#646970;"><?php echo esc_html( $s_id ); ?></code>
                                </td>
                                <td style="padding:8px;">
                                    <strong><?php echo esc_html( $s_contact ? $s_contact : 'N/A' ); ?></strong><br>
                                    <?php 
                                        $raw_email = isset($shop['email']) ? $shop['email'] : '';
                                        if ( ! empty( $raw_email ) ) {
                                            $email_parts = preg_split( '/[\s,\/]+/', $raw_email );
                                            foreach ( $email_parts as $e_part ) {
                                                $e_clean = trim( $e_part );
                                                if ( $e_clean ) {
                                                    echo '<a href="mailto:' . esc_attr($e_clean) . '" class="sywat-email-link">' . esc_html($e_clean) . '</a>';
                                                }
                                            }
                                        } else {
                                            echo '<span style="font-size:12px; color:#94a3b8;">No email provided</span>';
                                        }
                                    ?>
                                </td>
                                <td style="padding:8px;"><?php echo esc_html( isset($shop['phone']) ? $shop['phone'] : 'N/A' ); ?></td>
                                <td style="padding:8px; text-align:left;">
                                    <div style="display:inline-flex; gap:6px; align-items:center;">
                                        <a href="?tab=shops&manage_staff=<?php echo urlencode( $s_id ); ?>" class="sywat-btn sywat-btn-gray">👥 Staff (<?php echo $linked_count; ?>)</a>
                                        <a href="?tab=shops&edit_shop=<?php echo urlencode( $s_id ); ?>" class="sywat-btn sywat-btn-gray">✏️ Edit Shop</a>
                                        
                                        <?php if ( $s_id !== 'shop_ccr' ): ?>
                                            <form method="post" action="?tab=shops" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete this shop?');">
                                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                <input type="hidden" name="sywat_shop_action" value="delete_shop">
                                                <input type="hidden" name="shop_id" value="<?php echo esc_attr( $s_id ); ?>">
                                                <button type="submit" class="sywat-btn sywat-btn-red">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge-type" style="background:#f1f5f9; color:#94a3b8; border:1px solid #cbd5e1; padding:4px 8px;" title="Primary seed shop cannot be deleted">🔒 Seed</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- CREATE SHOP MODAL -->
        <div id="addShopModal" class="sywat-modal-bg" style="display:none;">
            <div class="sywat-modal-card">
                <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">🏬 Create New Archery Shop</h3>
                <form method="post" action="?tab=shops">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_shop_action" value="create_shop">

                    <div class="sywat-shop-field">
                        <label>Shop Name *</label>
                        <input type="text" name="shop_name" placeholder="e.g. Cinnamon Creek Ranch" required>
                    </div>

                    <div class="sywat-shop-field">
                        <label>Primary Contact Name(s)</label>
                        <input type="text" name="shop_contact" placeholder="e.g. Hannah Lange / Beau Brennan">
                    </div>

                    <div class="sywat-shop-field">
                        <label>Contact Email(s)</label>
                        <input type="text" name="shop_email" placeholder="e.g. events@cinnamoncreekranch.com, beau@cinnamoncreekranch.com">
                    </div>

                    <div class="sywat-shop-field">
                        <label>Contact Phone</label>
                        <input type="text" name="shop_phone" class="sywat-phone-input" placeholder="817-439-8998">
                    </div>

                    <hr style="border:none; border-top:1px dashed #ccc; margin:15px 0;">
                    <span style="font-size:12px; font-weight:bold; color:#646970; display:block; margin-bottom:10px;">LOCATION ADDRESS DETAILS (OPTIONAL)</span>

                    <div class="sywat-shop-field">
                        <label>Address Line 1</label>
                        <input type="text" name="shop_address_1" placeholder="e.g. 13795 Old Denton Rd">
                    </div>

                    <div class="sywat-shop-field">
                        <label>Address Line 2</label>
                        <input type="text" name="shop_address_2" placeholder="Suite, Bldg, etc.">
                    </div>

                    <div style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap:10px;">
                        <div class="sywat-shop-field">
                            <label>City</label>
                            <input type="text" name="shop_city" placeholder="e.g. Roanoke">
                        </div>
                        <div class="sywat-shop-field">
                            <label>State</label>
                            <input type="text" name="shop_state" placeholder="e.g. TX">
                        </div>
                        <div class="sywat-shop-field">
                            <label>ZIP</label>
                            <input type="text" name="shop_zip" placeholder="e.g. 76262">
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                        <button type="button" class="sywat-btn sywat-btn-gray" onclick="closeAddShopModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-green">+ Create Shop Profile</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function openAddShopModal() {
            const modal = document.getElementById('addShopModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeAddShopModal() {
            const modal = document.getElementById('addShopModal');
            if (modal) modal.style.display = 'none';
        }

        document.addEventListener("DOMContentLoaded", function() {
            // Live JS Phone Input Masking (XXX-XXX-XXXX)
            const phoneInputs = document.querySelectorAll('.sywat-phone-input');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function(e) {
                    let digits = this.value.replace(/\D/g, '').substring(0, 10);
                    let formatted = '';
                    if (digits.length > 0) {
                        if (digits.length <= 3) {
                            formatted = digits;
                        } else if (digits.length <= 6) {
                            formatted = digits.substring(0, 3) + '-' + digits.substring(3);
                        } else {
                            formatted = digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6);
                        }
                    }
                    this.value = formatted;
                });
            });

            // Auto-fade notice banners
            const notice = document.getElementById('sywat-notice');
            if (notice) {
                setTimeout(function() {
                    notice.style.transition = "opacity 0.5s ease";
                    notice.style.opacity = "0";
                    setTimeout(function() { notice.remove(); }, 500);
                }, 5000);
            }

            // Client-side shop search filter
            const searchInput = document.getElementById('sywat_shop_search_input');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase().trim();
                    const rows = document.querySelectorAll('.sywat-shop-row');
                    
                    rows.forEach(row => {
                        const name = (row.getAttribute('data-name') || '').toLowerCase();
                        const contact = (row.getAttribute('data-contact') || '').toLowerCase();
                        const shopId = (row.getAttribute('data-id') || '').toLowerCase();
                        
                        if (name.includes(filter) || contact.includes(filter) || shopId.includes(filter)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
        </script>
    <?php endif; ?>
    <?php
}
