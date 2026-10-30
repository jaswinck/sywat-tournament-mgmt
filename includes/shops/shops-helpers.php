<?php
/**
 * Sub-Module: Shop Helpers & WP User Profile Integration
 * Path: includes/shops/shops-helpers.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Standardize Phone Number Formatting (XXX-XXX-XXXX)
 */
if ( ! function_exists( 'sywat_format_phone_number' ) ) {
    function sywat_format_phone_number( $phone ) {
        $digits = preg_replace( '/\D/', '', $phone );
        if ( strlen( $digits ) === 10 ) {
            return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
        }
        return sanitize_text_field( $phone );
    }
}

/**
 * Ensure default seed shop exists upon loading
 */
add_action( 'init', 'sywat_ensure_default_shop_exists' );
if ( ! function_exists( 'sywat_ensure_default_shop_exists' ) ) {
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
}

/**
 * Fetch All Configured Archery Shops
 */
if ( ! function_exists( 'sywat_get_shops' ) ) {
    function sywat_get_shops() {
        $shops = get_option( 'sywat_shops_list', array() );
        return is_array( $shops ) ? $shops : array();
    }
}

/**
 * Fetch Current User's Assigned Shop ID
 * Allows Administrators, Management, and TFAA Admin roles to switch shop views
 */
if ( ! function_exists( 'sywat_get_current_user_shop_id' ) ) {
    function sywat_get_current_user_shop_id() {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) return 'shop_ccr';

        $admin_roles = array( 'administrator', 'management', 'tfaa_webmaster', 'tfaa_admin' );
        if ( array_intersect( $admin_roles, (array) $user->roles ) ) {
            if ( isset( $_REQUEST['sywat_shop_filter'] ) ) {
                return sanitize_text_field( $_REQUEST['sywat_shop_filter'] );
            }
        }

        $shop_id = get_user_meta( $user->ID, 'sywat_shop_id', true );
        return ! empty( $shop_id ) ? $shop_id : 'shop_ccr';
    }
}

/**
 * WP Admin User Profile Integration (Users > Edit User)
 */
add_action( 'show_user_profile', 'sywat_render_user_profile_shop_field' );
add_action( 'edit_user_profile', 'sywat_render_user_profile_shop_field' );

if ( ! function_exists( 'sywat_render_user_profile_shop_field' ) ) {
    function sywat_render_user_profile_shop_field( $user ) {
        $curr_user = wp_get_current_user();
        $admin_roles = array( 'administrator', 'management', 'tfaa_webmaster', 'tfaa_admin' );
        if ( ! array_intersect( $admin_roles, (array) $curr_user->roles ) ) return;

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
}

add_action( 'personal_options_update', 'sywat_save_user_profile_shop_field' );
add_action( 'edit_user_profile_update', 'sywat_save_user_profile_shop_field' );

if ( ! function_exists( 'sywat_save_user_profile_shop_field' ) ) {
    function sywat_save_user_profile_shop_field( $user_id ) {
        $curr_user = wp_get_current_user();
        $admin_roles = array( 'administrator', 'management', 'tfaa_webmaster', 'tfaa_admin' );
        if ( ! array_intersect( $admin_roles, (array) $curr_user->roles ) ) return;

        if ( isset( $_POST['sywat_shop_id'] ) ) {
            $val = sanitize_text_field( $_POST['sywat_shop_id'] );
            if ( empty( $val ) ) {
                delete_user_meta( $user_id, 'sywat_shop_id' );
            } else {
                update_user_meta( $user_id, 'sywat_shop_id', $val );
            }
        }
    }
}
