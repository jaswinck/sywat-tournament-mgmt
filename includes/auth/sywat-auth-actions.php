<?php
/**
 * Sub-Module: TFAA Authentication Actions & Routing Handler
 * Path: includes/auth/sywat-auth-actions.php
 * Author: SYWAT / TFAA Archery
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolve post-login destination URL based on user role or explicit redirect target.
 */
if ( ! function_exists( 'sywat_get_post_login_redirect_url' ) ) {
    function sywat_get_post_login_redirect_url( $user ) {
        // 1. If user was attempting to access a specific protected page, respect that destination
        if ( ! empty( $_REQUEST['redirect_to'] ) ) {
            return esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
        }

        // 2. Administrators, TFAA Board Members, Directors & Shop Managers -> Tournaments Admin Page
        if ( function_exists( 'sywat_can_manage_tournaments' ) && sywat_can_manage_tournaments( $user->ID ) ) {
            return site_url( '/tournaments' );
        }

        // 3. Shop Operators -> Check-In Desk Page
        if ( function_exists( 'sywat_user_has_any_role' ) && sywat_user_has_any_role( array( 'sywat_shop_user' ), $user->ID ) ) {
            return site_url( '/desk' );
        }

        // 4. Default: Standard TFAA Members & All Other Roles -> Main Site Homepage
        return home_url();
    }
}

/**
 * Handle Authentication Post Requests (Login, Logout, Lost Password, Reset Password)
 */
if ( ! function_exists( 'sywat_handle_auth_actions' ) ) {
    function sywat_handle_auth_actions() {
        if ( ! isset( $_REQUEST['sywat_auth_action'] ) ) {
            return;
        }

        $action = sanitize_text_field( $_REQUEST['sywat_auth_action'] );

        // 1. Handle Logout Action
        if ( $action === 'logout' ) {
            if ( check_admin_referer( 'sywat_auth_action', 'sywat_auth_nonce' ) ) {
                wp_logout();
                $redirect = remove_query_arg( array( 'sywat_auth_action', 'sywat_auth_nonce', 'action', 'key', 'login', 'redirect_to' ) );
                wp_safe_redirect( $redirect );
                exit;
            }
        }

        // 2. Handle Login Action
        if ( $action === 'login' && isset( $_POST['sywat_auth_action'] ) ) {
            if ( ! check_admin_referer( 'sywat_auth_action', 'sywat_auth_nonce' ) ) {
                $GLOBALS['sywat_auth_error'] = 'Security verification failed. Please try again.';
                return;
            }

            $username = isset( $_POST['sywat_user_login'] ) ? trim( sanitize_text_field( $_POST['sywat_user_login'] ) ) : '';
            $password = isset( $_POST['sywat_user_pass'] ) ? $_POST['sywat_user_pass'] : '';
            $remember = ! empty( $_POST['sywat_rememberme'] );

            if ( empty( $username ) || empty( $password ) ) {
                $GLOBALS['sywat_auth_error'] = 'Please enter both username/email and password.';
                return;
            }

            $creds = array(
                'user_login'    => $username,
                'user_password' => $password,
                'remember'      => $remember,
            );

            $user = wp_signon( $creds, is_ssl() );

            if ( is_wp_error( $user ) ) {
                $GLOBALS['sywat_auth_error'] = 'Invalid username, email, or password.';
            } else {
                wp_set_current_user( $user->ID );
                wp_set_auth_cookie( $user->ID, $remember );
                
                $redirect_url = sywat_get_post_login_redirect_url( $user );
                wp_safe_redirect( $redirect_url );
                exit;
            }
        }

        // 3. Handle Lost Password Request
        if ( $action === 'lost_password' && isset( $_POST['sywat_auth_action'] ) ) {
            if ( ! check_admin_referer( 'sywat_auth_action', 'sywat_auth_nonce' ) ) {
                $GLOBALS['sywat_auth_error'] = 'Security verification failed. Please try again.';
                return;
            }

            $user_input = isset( $_POST['sywat_user_login'] ) ? trim( sanitize_text_field( $_POST['sywat_user_login'] ) ) : '';
            if ( empty( $user_input ) ) {
                $GLOBALS['sywat_auth_error'] = 'Please enter your username or email address.';
                return;
            }

            $_POST['user_login'] = $user_input;
            $errors = retrieve_password();

            if ( is_wp_error( $errors ) ) {
                $GLOBALS['sywat_auth_error'] = $errors->get_error_message();
            } else {
                $GLOBALS['sywat_auth_message'] = 'Password reset instructions have been sent to your email address.';
            }
        }

        // 4. Handle Reset Password Submission
        if ( $action === 'reset_password' && isset( $_POST['sywat_auth_action'] ) ) {
            if ( ! check_admin_referer( 'sywat_auth_action', 'sywat_auth_nonce' ) ) {
                $GLOBALS['sywat_auth_error'] = 'Security verification failed. Please try again.';
                return;
            }

            $key   = isset( $_POST['rp_key'] ) ? sanitize_text_field( $_POST['rp_key'] ) : '';
            $login = isset( $_POST['rp_login'] ) ? sanitize_text_field( $_POST['rp_login'] ) : '';
            $pass1 = isset( $_POST['pass1'] ) ? $_POST['pass1'] : '';
            $pass2 = isset( $_POST['pass2'] ) ? $_POST['pass2'] : '';

            if ( empty( $pass1 ) || empty( $pass2 ) ) {
                $GLOBALS['sywat_auth_error'] = 'Please enter and confirm your new password.';
                return;
            }

            if ( $pass1 !== $pass2 ) {
                $GLOBALS['sywat_auth_error'] = 'Passwords do not match.';
                return;
            }

            $user = check_password_reset_key( $key, $login );

            if ( is_wp_error( $user ) ) {
                $GLOBALS['sywat_auth_error'] = 'This password reset link is invalid or has expired.';
            } else {
                reset_password( $user, $pass1 );
                $GLOBALS['sywat_auth_message'] = 'Your password has been reset. You may now sign in.';
                $_GET['action'] = ''; // Clear action view state
            }
        }
    }
    add_action( 'init', 'sywat_handle_auth_actions' );
}
