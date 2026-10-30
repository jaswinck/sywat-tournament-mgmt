<?php
/**
 * Sub-Module: Shop Action Processing & CRUD Logic
 * Path: includes/shops/shops-actions.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_handle_shop_actions' ) ) {
    function sywat_handle_shop_actions() {
        $can_manage = function_exists( 'sywat_can_manage_seasons' ) ? sywat_can_manage_seasons() : current_user_can( 'administrator' );
        if ( ! $can_manage ) return '';
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
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully created shop <strong>' . esc_html( $shop_name ) . '</strong>.</div>';
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
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully updated shop details for <strong>' . esc_html( $shops[$shop_id]['name'] ) . '</strong>.</div>';
            }
        }

        if ( $action === 'delete_shop' ) {
            $shop_id = sanitize_text_field( $_POST['shop_id'] );
            if ( isset( $shops[$shop_id] ) ) {
                $deleted_name = $shops[$shop_id]['name'];
                unset( $shops[$shop_id] );
                update_option( 'sywat_shops_list', $shops );

                $assigned_users = get_users( array( 'meta_key' => 'sywat_shop_id', 'meta_value' => $shop_id ) );
                foreach ( $assigned_users as $u ) {
                    delete_user_meta( $u->ID, 'sywat_shop_id' );
                }

                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully deleted shop <strong>' . esc_html( $deleted_name ) . '</strong>.</div>';
            }
        }

        if ( $action === 'assign_staff_to_shop' ) {
            $target_user_id = intval( $_POST['staff_user_id'] );
            $target_shop_id = sanitize_text_field( $_POST['target_shop_id'] );
            if ( $target_user_id > 0 && isset( $shops[$target_shop_id] ) ) {
                update_user_meta( $target_user_id, 'sywat_shop_id', $target_shop_id );
                $u = get_userdata( $target_user_id );
                $u_name = ( $u && isset( $u->display_name ) ) ? $u->display_name : 'User';
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Assigned <strong>' . esc_html( $u_name ) . '</strong> to <strong>' . esc_html( $shops[$target_shop_id]['name'] ) . '</strong>.</div>';
            }
        }

        if ( $action === 'remove_staff_from_shop' ) {
            $target_user_id = intval( $_POST['staff_user_id'] );
            if ( $target_user_id > 0 ) {
                delete_user_meta( $target_user_id, 'sywat_shop_id' );
                $u = get_userdata( $target_user_id );
                $u_name = ( $u && isset( $u->display_name ) ) ? $u->display_name : 'User';
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Unlinked <strong>' . esc_html( $u_name ) . '</strong> from shop assignment.</div>';
            }
        }

        return '';
    }
}
