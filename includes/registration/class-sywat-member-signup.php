<?php
/**
 * Sub-Module: TFAA Member Creation Portal
 * Path: includes/registration/class-sywat-member-signup.php
 * Description: Renders the [sywat_member_signup] form for authorized admins to register new members.
 * Author: SYWAT Archery
 * Version: 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Register Shortcode
if ( shortcode_exists( 'sywat_member_signup' ) ) remove_shortcode( 'sywat_member_signup' );
add_shortcode( 'sywat_member_signup', 'sywat_render_member_signup_form' );

function sywat_render_member_signup_form() {
    // Enforce Restricted Access: Only Administrator, TFAA Webmaster, and TFAA Admin can create members
    if ( ! function_exists( 'sywat_can_create_members' ) || ! sywat_can_create_members() ) {
        return '<div class="sywat-wrap" style="max-width: 600px; margin: 20px auto;"><div class="sywat-alert sywat-alert-danger">Access denied. Member account creation is restricted to TFAA Administrators, Webmasters, and System Administrators.</div></div>';
    }

    $errors  = array();
    $success = '';

    // Handle Form Submission
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_signup_action'] ) ) {
        if ( ! isset( $_POST['sywat_signup_nonce'] ) || ! wp_verify_nonce( $_POST['sywat_signup_nonce'], 'sywat_signup_submit' ) ) {
            $errors[] = 'Security token expired. Please refresh the page and try again.';
        } else {
            $first_name = sanitize_text_field( $_POST['first_name'] );
            $last_name  = sanitize_text_field( $_POST['last_name'] );
            $email      = sanitize_email( $_POST['email'] );
            $password   = $_POST['password'];
            $tfaa_num   = sanitize_text_field( $_POST['tfaa_number'] );
            $phone      = sanitize_text_field( $_POST['phone'] );

            // Validation
            if ( empty( $first_name ) || empty( $last_name ) ) {
                $errors[] = 'First and Last Name are required.';
            }

            if ( ! is_email( $email ) ) {
                $errors[] = 'Please enter a valid email address.';
            } elseif ( email_exists( $email ) ) {
                $errors[] = 'An account with that email address already exists.';
            }

            if ( strlen( $password ) < 8 ) {
                $errors[] = 'Password must be at least 8 characters long.';
            }

            // Create User Account if no validation errors
            if ( empty( $errors ) ) {
                $user_id = wp_insert_user( array(
                    'user_login'   => $email,
                    'user_email'   => $email,
                    'user_pass'    => $password,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'display_name' => $first_name . ' ' . $last_name,
                    'role'         => 'tfaa_member',
                ) );

                if ( is_wp_error( $user_id ) ) {
                    $errors[] = $user_id->get_error_message();
                } else {
                    if ( ! empty( $tfaa_num ) ) {
                        update_user_meta( $user_id, 'sywat_tfaa_number', $tfaa_num );
                    }
                    if ( ! empty( $phone ) ) {
                        update_user_meta( $user_id, 'sywat_phone', $phone );
                    }

                    $success = sprintf( 'Member account for "%s %s" created successfully!', esc_html( $first_name ), esc_html( $last_name ) );
                }
            }
        }
    }

    ob_start();
    ?>
    <div class="sywat-wrap" style="max-width: 550px; margin: 20px auto;">
        <div class="sywat-card">
            <h2 style="margin-top: 0; color: #0f172a; font-size: 22px; text-align: center;">👤 Administrative Member Account Creation</h2>
            <p style="text-align: center; color: #64748b; font-size: 13px; margin-bottom: 20px;">
                Register a new official TFAA Member account in the system database.
            </p>

            <?php if ( ! empty( $errors ) ): ?>
                <div class="sywat-alert sywat-alert-danger" style="margin-bottom: 15px;">
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ( $errors as $err ): ?>
                            <li><?php echo $err; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $success ) ): ?>
                <div class="sywat-alert sywat-alert-success" style="margin-bottom: 15px; text-align: center;">
                    <strong><?php echo $success; ?></strong>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'sywat_signup_submit', 'sywat_signup_nonce' ); ?>
                <input type="hidden" name="sywat_signup_action" value="1">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">First Name *</label>
                        <input type="text" name="first_name" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Last Name *</label>
                        <input type="text" name="last_name" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Email Address *</label>
                    <input type="email" name="email" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">TFAA / NFAA Member #</label>
                        <input type="text" name="tfaa_number" placeholder="e.g. 123456" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Phone Number</label>
                        <input type="text" name="phone" placeholder="(555) 000-0000" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Initial Temporary Password *</label>
                    <input type="password" name="password" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; box-sizing:border-box;">
                </div>

                <button type="submit" class="sywat-btn sywat-btn-success" style="width: 100%; padding: 10px; font-size: 14px; font-weight: 600;">Create Member Account</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
