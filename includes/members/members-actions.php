<?php
/**
 * Sub-Module: Member POST Action Handling
 * Path: includes/members/members-actions.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Utility: Password Strength & Match Validation Helper
 */
if ( ! function_exists( 'sywat_validate_password_rules' ) ) {
    function sywat_validate_password_rules( $password, $confirm_password ) {
        if ( $password !== $confirm_password ) {
            return 'Passwords do not match. Please ensure both password fields are identical.';
        }
        if ( strlen( $password ) < 8 ) {
            return 'Password must be at least 8 characters long.';
        }
        if ( ! preg_match( '/[A-Z]/', $password ) ) {
            return 'Password must contain at least one uppercase letter (A-Z).';
        }
        if ( ! preg_match( '/[a-z]/', $password ) ) {
            return 'Password must contain at least one lowercase letter (a-z).';
        }
        if ( ! preg_match( '/[0-9]/', $password ) ) {
            return 'Password must contain at least one number (0-9).';
        }
        return true;
    }
}

if ( ! function_exists( 'sywat_handle_member_post_actions' ) ) {
    function sywat_handle_member_post_actions() {
        if ( ! isset( $_POST['sywat_member_nonce'] ) || ! wp_verify_nonce( $_POST['sywat_member_nonce'], 'sywat_member_action_nonce' ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        }

        $action = isset( $_POST['sywat_member_action'] ) ? sanitize_text_field( $_POST['sywat_member_action'] ) : '';

        // 1. CREATE NEW MEMBER ACCOUNT
        if ( $action === 'create_member' ) {
            $first_name       = ucfirst( strtolower( sanitize_text_field( $_POST['first_name'] ) ) );
            $last_name        = ucfirst( strtolower( sanitize_text_field( $_POST['last_name'] ) ) );
            $email            = sanitize_email( $_POST['email'] );
            $password         = $_POST['password'];
            $confirm_password = isset( $_POST['confirm_password'] ) ? $_POST['confirm_password'] : '';
            $phone            = sanitize_text_field( $_POST['phone'] );
            $is_nfaa          = ( isset( $_POST['is_nfaa_member'] ) && $_POST['is_nfaa_member'] === 'yes' ) ? 'yes' : 'no';

            if ( empty( $first_name ) || empty( $last_name ) || ! is_email( $email ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please provide a valid First Name, Last Name, and Email Address.</div>';
            }

            if ( email_exists( $email ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">An account with email address "' . esc_html( $email ) . '" already exists.</div>';
            }

            // Password Complexity & Match Check
            $pw_val = sywat_validate_password_rules( $password, $confirm_password );
            if ( $pw_val !== true ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $pw_val ) . '</div>';
            }

            // Generate Username: Firstname.Lastname (e.g. John.Doe) with collision protection
            $base_username = sanitize_user( $first_name . '.' . $last_name, true );
            $username      = $base_username;
            $counter       = 1;

            while ( username_exists( $username ) ) {
                $username = $base_username . $counter;
                $counter++;
            }

            $tfaa_id = sywat_generate_unique_tfaa_id();
            $user_id = wp_insert_user( array(
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => $password,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'display_name' => $first_name . ' ' . $last_name,
                'role'         => 'tfaa_member',
            ) );

            if ( is_wp_error( $user_id ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $user_id->get_error_message() ) . '</div>';
            }

            update_user_meta( $user_id, 'sywat_tfaa_number', $tfaa_id );
            update_user_meta( $user_id, 'sywat_is_nfaa_member', $is_nfaa );
            update_user_meta( $user_id, 'sywat_phone', $phone );

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Member account created successfully! Username: <strong>' . esc_html( $username ) . '</strong> | Member ID: <strong>' . esc_html( $tfaa_id ) . '</strong></div>';
        }

        // 2. UPDATE EXISTING MEMBER PROFILE
        if ( $action === 'update_member' ) {
            $user_id        = intval( $_POST['target_user_id'] );
            $first_name     = sanitize_text_field( $_POST['first_name'] );
            $last_name      = sanitize_text_field( $_POST['last_name'] );
            $email          = sanitize_email( $_POST['email'] );
            $phone          = sanitize_text_field( $_POST['phone'] );
            $is_nfaa        = ( isset( $_POST['is_nfaa_member'] ) && $_POST['is_nfaa_member'] === 'yes' ) ? 'yes' : 'no';
            $new_pw         = $_POST['new_password'];
            $confirm_new_pw = isset( $_POST['confirm_new_password'] ) ? $_POST['confirm_new_password'] : '';

            if ( $user_id > 0 && get_userdata( $user_id ) ) {
                // If a new password is entering, validate complexity & match
                if ( ! empty( $new_pw ) || ! empty( $confirm_new_pw ) ) {
                    $pw_val = sywat_validate_password_rules( $new_pw, $confirm_new_pw );
                    if ( $pw_val !== true ) {
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $pw_val ) . '</div>';
                    }
                    wp_set_password( $new_pw, $user_id );
                }

                wp_update_user( array(
                    'ID'           => $user_id,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'display_name' => $first_name . ' ' . $last_name,
                    'user_email'   => $email,
                ) );

                update_user_meta( $user_id, 'sywat_is_nfaa_member', $is_nfaa );
                update_user_meta( $user_id, 'sywat_phone', $phone );

                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Member profile updated successfully.</div>';
            }
        }

        // 3. DELETE MEMBER ACCOUNT
        if ( $action === 'delete_member' ) {
            $user_id = intval( $_POST['target_user_id'] );
            $user    = get_userdata( $user_id );

            if ( ! $user ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">User not found.</div>';
            }

            // Prevent currently logged in user from deleting themselves
            if ( get_current_user_id() === $user_id ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">You cannot delete your own active administrator account.</div>';
            }

            require_once ABSPATH . 'wp-admin/includes/user.php';
            $deleted_name = $user->display_name;

            if ( wp_delete_user( $user_id ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Member account "<strong>' . esc_html( $deleted_name ) . '</strong>" has been permanently deleted.</div>';
            } else {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Failed to delete user account. Please try again.</div>';
            }
        }

        return '';
    }
}
