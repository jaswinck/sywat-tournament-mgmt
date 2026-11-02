<?php
/**
 * Sub-Module: Shop & Club Action Processing & CRUD Logic
 * Path: includes/shops/shops-actions.php
 * Author: SYWAT Archery / TFAA
 * Version: 3.3.0
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
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Location Name is required.</div>';
            }
            $shop_slug = 'shop_' . sanitize_title( $shop_name );
            if ( isset( $shops[$shop_slug] ) ) {
                $shop_slug .= '_' . substr( md5( time() ), 0, 4 );
            }

            $default_indoor_lines = array(
                array( 'day' => 'Saturday', 'time' => '10:00 AM', 'name' => 'Saturday 10:00 AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'day' => 'Saturday', 'time' => '2:00 PM',  'name' => 'Saturday 2:00 PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'day' => 'Sunday',   'time' => '10:00 AM', 'name' => 'Sunday 10:00 AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'day' => 'Sunday',   'time' => '2:00 PM',  'name' => 'Sunday 2:00 PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
            );

            $default_outdoor_lines = array(
                array( 'day' => 'Saturday', 'time' => '9:00 AM', 'name' => 'Saturday 9:00 AM' ),
                array( 'day' => 'Sunday',   'time' => '9:00 AM', 'name' => 'Sunday 9:00 AM' ),
            );

            $facilities = isset( $_POST['facilities'] ) && is_array( $_POST['facilities'] ) ? array_map( 'sanitize_text_field', $_POST['facilities'] ) : array();
            $loc_type   = isset( $_POST['location_type'] ) ? sanitize_text_field( $_POST['location_type'] ) : 'pro_shop';

            $shops[$shop_slug] = array(
                'id'                  => $shop_slug,
                'name'                => $shop_name,
                'location_type'       => $loc_type,
                'district'            => isset( $_POST['shop_district'] ) ? sanitize_text_field( $_POST['shop_district'] ) : '',
                'facilities'          => $facilities,
                'contact'             => isset( $_POST['shop_contact'] ) ? sanitize_text_field( $_POST['shop_contact'] ) : '',
                'contact_title'       => isset( $_POST['shop_contact_title'] ) ? sanitize_text_field( $_POST['shop_contact_title'] ) : '',
                'email'               => isset( $_POST['shop_email'] ) ? sanitize_text_field( $_POST['shop_email'] ) : '',
                'phone'               => isset( $_POST['shop_phone'] ) ? sywat_format_phone_number( $_POST['shop_phone'] ) : '',
                'additional_contacts' => array(),
                'address_1'           => isset( $_POST['shop_address_1'] ) ? sanitize_text_field( $_POST['shop_address_1'] ) : '',
                'address_2'           => isset( $_POST['shop_address_2'] ) ? sanitize_text_field( $_POST['shop_address_2'] ) : '',
                'city'                => isset( $_POST['shop_city'] ) ? sanitize_text_field( $_POST['shop_city'] ) : '',
                'state'               => isset( $_POST['shop_state'] ) ? sanitize_text_field( $_POST['shop_state'] ) : '',
                'zip'                 => isset( $_POST['shop_zip'] ) ? sanitize_text_field( $_POST['shop_zip'] ) : '',
                'default_lines'       => $default_indoor_lines,
                'outdoor_lines'       => $default_outdoor_lines,
                'created_at'          => current_time( 'mysql' ),
            );

            update_option( 'sywat_shops_list', $shops );
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully created location <strong>' . esc_html( $shop_name ) . '</strong>.</div>';
        }

        if ( $action === 'update_shop' ) {
            $shop_id = sanitize_text_field( $_POST['shop_id'] );
            if ( isset( $shops[$shop_id] ) ) {
                $facilities = isset( $_POST['facilities'] ) && is_array( $_POST['facilities'] ) ? array_map( 'sanitize_text_field', $_POST['facilities'] ) : array();

                $shops[$shop_id]['name']          = sanitize_text_field( $_POST['shop_name'] );
                $shops[$shop_id]['location_type'] = isset( $_POST['location_type'] ) ? sanitize_text_field( $_POST['location_type'] ) : 'pro_shop';
                $shops[$shop_id]['district']      = isset( $_POST['shop_district'] ) ? sanitize_text_field( $_POST['shop_district'] ) : '';
                $shops[$shop_id]['facilities']    = $facilities;
                $shops[$shop_id]['contact']       = sanitize_text_field( $_POST['shop_contact'] );
                $shops[$shop_id]['contact_title'] = isset( $_POST['shop_contact_title'] ) ? sanitize_text_field( $_POST['shop_contact_title'] ) : '';
                $shops[$shop_id]['email']         = sanitize_text_field( $_POST['shop_email'] );
                $shops[$shop_id]['phone']         = sywat_format_phone_number( $_POST['shop_phone'] );
                $shops[$shop_id]['address_1']     = sanitize_text_field( $_POST['shop_address_1'] );
                $shops[$shop_id]['address_2']     = sanitize_text_field( $_POST['shop_address_2'] );
                $shops[$shop_id]['city']          = sanitize_text_field( $_POST['shop_city'] );
                $shops[$shop_id]['state']         = sanitize_text_field( $_POST['shop_state'] );
                $shops[$shop_id]['zip']           = sanitize_text_field( $_POST['shop_zip'] );

                // Process Additional Contacts Repeater
                $add_contacts = array();
                if ( ! empty( $_POST['add_contact_names'] ) && is_array( $_POST['add_contact_names'] ) ) {
                    foreach ( $_POST['add_contact_names'] as $idx => $c_name ) {
                        $clean_name = trim( sanitize_text_field( $c_name ) );
                        if ( ! empty( $clean_name ) ) {
                            $add_contacts[] = array(
                                'name'  => $clean_name,
                                'title' => isset( $_POST['add_contact_titles'][$idx] ) ? sanitize_text_field( $_POST['add_contact_titles'][$idx] ) : '',
                                'email' => isset( $_POST['add_contact_emails'][$idx] ) ? sanitize_text_field( $_POST['add_contact_emails'][$idx] ) : '',
                                'phone' => isset( $_POST['add_contact_phones'][$idx] ) ? sywat_format_phone_number( $_POST['add_contact_phones'][$idx] ) : '',
                            );
                        }
                    }
                }
                $shops[$shop_id]['additional_contacts'] = $add_contacts;

                // Process SYWAT Indoor Lines (Split Day & Time)
                if ( ! empty( $_POST['indoor_line_days'] ) && is_array( $_POST['indoor_line_days'] ) ) {
                    $indoor_lines = array();
                    foreach ( $_POST['indoor_line_days'] as $index => $day ) {
                        $clean_day  = trim( sanitize_text_field( $day ) );
                        $clean_time = isset( $_POST['indoor_line_times'][$index] ) ? trim( sanitize_text_field( $_POST['indoor_line_times'][$index] ) ) : '';
                        if ( ! empty( $clean_day ) && ! empty( $clean_time ) ) {
                            $indoor_lines[] = array(
                                'day'      => $clean_day,
                                'time'     => $clean_time,
                                'name'     => trim( $clean_day . ' ' . $clean_time ),
                                'cap_20yd' => isset( $_POST['cap_20yd'][$index] ) ? intval( $_POST['cap_20yd'][$index] ) : 20,
                                'cap_cub'  => isset( $_POST['cap_cub'][$index] ) ? intval( $_POST['cap_cub'][$index] ) : 5,
                            );
                        }
                    }
                    if ( ! empty( $indoor_lines ) ) {
                        $shops[$shop_id]['default_lines'] = $indoor_lines;
                    }
                } elseif ( ! empty( $_POST['line_names'] ) && is_array( $_POST['line_names'] ) ) {
                    // Fallback for single line name string inputs
                    $indoor_lines = array();
                    foreach ( $_POST['line_names'] as $index => $name ) {
                        $clean_name = trim( sanitize_text_field( $name ) );
                        if ( ! empty( $clean_name ) ) {
                            $parts = explode( ' ', $clean_name, 2 );
                            $indoor_lines[] = array(
                                'day'      => $parts[0],
                                'time'     => isset( $parts[1] ) ? $parts[1] : '',
                                'name'     => $clean_name,
                                'cap_20yd' => isset( $_POST['cap_20yd'][$index] ) ? intval( $_POST['cap_20yd'][$index] ) : 20,
                                'cap_cub'  => isset( $_POST['cap_cub'][$index] ) ? intval( $_POST['cap_cub'][$index] ) : 5,
                            );
                        }
                    }
                    if ( ! empty( $indoor_lines ) ) {
                        $shops[$shop_id]['default_lines'] = $indoor_lines;
                    }
                }

                // Process SYWAT Outdoor Lines (Split Day & Time)
                if ( ! empty( $_POST['outdoor_line_days'] ) && is_array( $_POST['outdoor_line_days'] ) ) {
                    $outdoor_lines = array();
                    foreach ( $_POST['outdoor_line_days'] as $index => $day ) {
                        $clean_day  = trim( sanitize_text_field( $day ) );
                        $clean_time = isset( $_POST['outdoor_line_times'][$index] ) ? trim( sanitize_text_field( $_POST['outdoor_line_times'][$index] ) ) : '';
                        if ( ! empty( $clean_day ) && ! empty( $clean_time ) ) {
                            $outdoor_lines[] = array(
                                'day'  => $clean_day,
                                'time' => $clean_time,
                                'name' => trim( $clean_day . ' ' . $clean_time ),
                            );
                        }
                    }
                    $shops[$shop_id]['outdoor_lines'] = $outdoor_lines;
                }

                update_option( 'sywat_shops_list', $shops );
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully updated details for <strong>' . esc_html( $shops[$shop_id]['name'] ) . '</strong>.</div>';
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

                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully deleted location <strong>' . esc_html( $deleted_name ) . '</strong>.</div>';
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
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Unlinked <strong>' . esc_html( $u_name ) . '</strong> from location assignment.</div>';
            }
        }

        return '';
    }
}
