<?php
/**
 * Sub-Module: TFAA Authentication Views Renderer
 * Path: includes/auth/sywat-auth-views.php
 * Author: SYWAT / TFAA Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render Header Bar for Logged-In Operators
 */
if ( ! function_exists( 'sywat_render_auth_header' ) ) {
    function sywat_render_auth_header() {
        if ( ! is_user_logged_in() ) return;

        $current_user = wp_get_current_user();
        $roles        = (array) $current_user->roles;
        $role_label   = in_array( 'administrator', $roles ) ? 'Administrator' : 'Operator';
        ?>
        <div class="sywat-auth-bar" style="display:flex; justify-content:space-between; align-items:center; background:#0f172a; color:#fff; padding:10px 16px; border-radius:6px; margin-bottom:18px; box-shadow:0 2px 6px rgba(0,0,0,0.1);">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:15px; font-weight:700; color:#38bdf8;">🎯 Texas Field Archery Association</span>
                <span style="background:#1e293b; color:#94a3b8; font-size:11px; padding:2px 8px; border-radius:12px; border:1px solid #334155;">
                    <?php echo esc_html( $role_label ); ?>
                </span>
            </div>

            <div style="display:flex; align-items:center; gap:14px;">
                <span style="font-size:13px; color:#cbd5e1;">
                    Logged in as: <strong style="color:#fff;"><?php echo esc_html( $current_user->display_name ); ?></strong>
                </span>

                <form method="post" style="margin:0; display:inline;">
                    <?php wp_nonce_field( 'sywat_auth_action', 'sywat_auth_nonce' ); ?>
                    <input type="hidden" name="sywat_auth_action" value="logout">
                    <button type="submit" class="sywat-btn sywat-btn-danger" style="background:#ef4444; color:#fff; border:none; padding:5px 12px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer;">
                        🚪 Log Out
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
}

/**
 * Render Custom TFAA Front-End Auth Screen (Sign In, Lost Password, & Set New Password)
 */
if ( ! function_exists( 'sywat_render_login_screen' ) ) {
    function sywat_render_login_screen() {
        $auth_error   = ! empty( $GLOBALS['sywat_auth_error'] ) ? $GLOBALS['sywat_auth_error'] : '';
        $auth_message = ! empty( $GLOBALS['sywat_auth_message'] ) ? $GLOBALS['sywat_auth_message'] : '';
        
        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'login';
        $rp_key = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : '';
        $rp_login = isset( $_GET['login'] ) ? sanitize_text_field( $_GET['login'] ) : '';

        if ( ! empty( $rp_key ) && ! empty( $rp_login ) ) {
            $action = 'rp';
        }

        $current_url = remove_query_arg( array( 'action', 'key', 'login' ) );
        $lost_pass_url = add_query_arg( 'action', 'lost_password', $current_url );
        ?>
        <div class="sywat-login-card" style="max-width:400px; margin:40px auto; background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,0.08); overflow:hidden;">
            
            <!-- Card Header -->
            <div style="background:#0f172a; padding:24px 20px; text-align:center; color:#ffffff;">
                <div style="font-size:28px; margin-bottom:6px;">🎯</div>
                <h3 style="margin:0; font-size:18px; font-weight:700; color:#ffffff;">Texas Field Archery Association</h3>
            </div>

            <!-- Card Body -->
            <div style="padding:24px; background:#f8fafc;">
                
                <?php if ( ! empty( $auth_error ) ): ?>
                    <div class="sywat-alert sywat-alert-danger" style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:10px 12px; border-radius:4px; font-size:13px; font-weight:600; margin-bottom:16px;">
                        ⚠️ <?php echo esc_html( $auth_error ); ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $auth_message ) ): ?>
                    <div class="sywat-alert sywat-alert-success" style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:10px 12px; border-radius:4px; font-size:13px; font-weight:600; margin-bottom:16px;">
                        ✅ <?php echo esc_html( $auth_message ); ?>
                    </div>
                <?php endif; ?>

                <?php if ( $action === 'lost_password' ): ?>
                    <!-- STATE 2: REQUEST PASSWORD RESET -->
                    <p style="font-size:13px; color:#475569; margin-top:0; margin-bottom:16px;">
                        Enter your username or email address below. You will receive an email with instructions to reset your password.
                    </p>

                    <form method="post" id="sywat_lost_password_form">
                        <?php wp_nonce_field( 'sywat_auth_action', 'sywat_auth_nonce' ); ?>
                        <input type="hidden" name="sywat_auth_action" value="lost_password">

                        <div style="margin-bottom:18px;">
                            <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Username or Email Address</label>
                            <input type="text" name="sywat_user_login" required style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:14px; box-sizing:border-box; height:38px;">
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary" style="width:100%; padding:10px; background:#0284c7; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer;">
                            Send Reset Link
                        </button>
                    </form>

                    <div style="text-align:center; margin-top:16px; padding-top:12px; border-top:1px solid #e2e8f0;">
                        <a href="<?php echo esc_url( $current_url ); ?>" style="font-size:12px; color:#0284c7; text-decoration:none; font-weight:600;">
                            ← Back to Sign In
                        </a>
                    </div>

                <?php elseif ( $action === 'rp' ): ?>
                    <!-- STATE 3: SET NEW PASSWORD -->
                    <p style="font-size:13px; color:#475569; margin-top:0; margin-bottom:16px;">
                        Please enter your new password below.
                    </p>

                    <form method="post" id="sywat_reset_password_form">
                        <?php wp_nonce_field( 'sywat_auth_action', 'sywat_auth_nonce' ); ?>
                        <input type="hidden" name="sywat_auth_action" value="reset_password">
                        <input type="hidden" name="rp_key" value="<?php echo esc_attr( $rp_key ); ?>">
                        <input type="hidden" name="rp_login" value="<?php echo esc_attr( $rp_login ); ?>">

                        <div style="margin-bottom:14px;">
                            <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">New Password</label>
                            <input type="password" name="pass1" required autocomplete="new-password" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:14px; box-sizing:border-box; height:38px;">
                        </div>

                        <div style="margin-bottom:18px;">
                            <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Confirm New Password</label>
                            <input type="password" name="pass2" required autocomplete="new-password" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:14px; box-sizing:border-box; height:38px;">
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary" style="width:100%; padding:10px; background:#0284c7; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer;">
                            Save New Password
                        </button>
                    </form>

                    <div style="text-align:center; margin-top:16px; padding-top:12px; border-top:1px solid #e2e8f0;">
                        <a href="<?php echo esc_url( $current_url ); ?>" style="font-size:12px; color:#0284c7; text-decoration:none; font-weight:600;">
                            ← Back to Sign In
                        </a>
                    </div>

                <?php else: ?>
                    <!-- STATE 1: STANDARD SIGN IN -->
                    <form method="post" id="sywat_plugin_login_form">
                        <?php wp_nonce_field( 'sywat_auth_action', 'sywat_auth_nonce' ); ?>
                        <input type="hidden" name="sywat_auth_action" value="login">

                        <div style="margin-bottom:14px;">
                            <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Username or Email Address</label>
                            <input type="text" name="sywat_user_login" required autocomplete="username" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:14px; box-sizing:border-box; height:38px;">
                        </div>

                        <div style="margin-bottom:14px;">
                            <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Password</label>
                            <input type="password" name="sywat_user_pass" required autocomplete="current-password" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:14px; box-sizing:border-box; height:38px;">
                        </div>

                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
                            <label style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; cursor:pointer;">
                                <input type="checkbox" name="sywat_rememberme" value="1" checked style="margin:0;"> Remember Me
                            </label>
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary" style="width:100%; padding:10px; background:#0284c7; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer; transition:background 0.2s;">
                            Sign In
                        </button>
                    </form>

                    <div style="text-align:center; margin-top:16px; padding-top:12px; border-top:1px solid #e2e8f0;">
                        <a href="<?php echo esc_url( $lost_pass_url ); ?>" style="font-size:12px; color:#0284c7; text-decoration:none; font-weight:600;">
                            Forgot Password?
                        </a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
        <?php
    }
}
