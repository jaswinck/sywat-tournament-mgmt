<?php
/**
 * Sub-Module: TFAA Authentication Views & Shortcode Renderer
 * Path: includes/auth/sywat-auth-views.php
 * Author: SYWAT / TFAA Archery
 * Version: 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render Master App Navigation Bar for Logged-In & Public Users
 */
if ( ! function_exists( 'sywat_render_auth_header' ) ) {
    function sywat_render_auth_header() {
        if ( is_admin() ) return;

        $is_logged_in = is_user_logged_in();
        $current_user = $is_logged_in ? wp_get_current_user() : null;
        $user_id      = $is_logged_in ? $current_user->ID : 0;

        // Dynamic capability checks
        $is_admin               = $is_logged_in && current_user_can( 'administrator' );
        $can_manage_tournaments = $is_logged_in && function_exists( 'sywat_can_manage_tournaments' ) && sywat_can_manage_tournaments( $user_id );
        $can_access_desk        = $is_logged_in && function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk( $user_id );
        $can_edit_scores        = $is_logged_in && function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores( $user_id );
        $can_manage_members     = $is_logged_in && ( function_exists( 'sywat_can_manage_members' ) ? sywat_can_manage_members( $user_id ) : current_user_can( 'manage_options' ) );
        $user_shop_id           = $is_logged_in && function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : '';

        $logo_url = defined( 'SYWAT_PLUGIN_URL' ) ? SYWAT_PLUGIN_URL . 'assets/images/logo.png' : '';
        ?>
        <div class="sywat-auth-bar">
            <div class="sywat-auth-bar-inner">
                
                <!-- Left: Brand Logo & Title -->
                <div style="display:flex; align-items:center; gap:12px; flex-shrink:0;">
                    <?php if ( ! empty( $logo_url ) ): ?>
                        <img src="<?php echo esc_url( $logo_url ); ?>" alt="TFAA Logo" style="height:40px; width:auto; display:block; filter:drop-shadow(0 2px 4px rgba(0,0,0,0.3));" onerror="this.style.display='none';">
                    <?php endif; ?>
                    
                    <span style="font-size:16px; font-weight:800; color:#ffffff; letter-spacing:0.3px; white-space:nowrap;">
                        TFAA Archery
                    </span>
                </div>

                <!-- Center: Primary App Navigation Links -->
                <nav style="display:flex; align-items:center; gap:6px;">
                    
                    <!-- 1. Operational Tournaments Dropdown (Staff Only) -->
                    <?php if ( $can_manage_tournaments ): ?>
                        <div class="sywat-auth-dropdown">
                            <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>" class="sywat-auth-nav-link sywat-auth-dropdown-toggle">
                                🎯 Tournaments <span class="sywat-dropdown-caret">▼</span>
                            </a>
                            <div class="sywat-auth-dropdown-menu">
                                <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>">
                                    📊 Tournament Dashboard
                                </a>
                                <?php if ( $can_access_desk ): ?>
                                    <a href="<?php echo esc_url( site_url( '/sywat-desk/' ) ); ?>">
                                        📋 Check-In Desk
                                    </a>
                                <?php endif; ?>
                                <?php if ( $can_edit_scores ): ?>
                                    <a href="<?php echo esc_url( site_url( '/sywat-enter-scores/' ) ); ?>">
                                        ✏️ Score Entry
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 2. Administrative Dropdown (Staff Only) -->
                    <?php if ( $can_manage_members ): ?>
                        <div class="sywat-auth-dropdown">
                            <a href="#" class="sywat-auth-nav-link sywat-auth-dropdown-toggle" onclick="return false;">
                                ⚙️ Administration <span class="sywat-dropdown-caret">▼</span>
                            </a>
                            <div class="sywat-auth-dropdown-menu">
                                <a href="<?php echo esc_url( site_url( '/member-directory/' ) ); ?>">
                                    👥 Member Directory
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 3. Shops & Clubs Navigation Item (Dropdown for Staff, Direct Link for Public) -->
                    <?php if ( $is_admin || ! empty( $user_shop_id ) ): ?>
                        <div class="sywat-auth-dropdown">
                            <a href="<?php echo esc_url( site_url( '/shops-and-clubs/' ) ); ?>" class="sywat-auth-nav-link sywat-auth-dropdown-toggle">
                                🏪 Shops & Clubs <span class="sywat-dropdown-caret">▼</span>
                            </a>
                            <div class="sywat-auth-dropdown-menu">
                                <a href="<?php echo esc_url( site_url( '/shops-and-clubs/' ) ); ?>">
                                    🌐 View Public Directory
                                </a>
                                <?php if ( $is_admin ): ?>
                                    <a href="<?php echo esc_url( site_url( '/sywat-manage-shops/' ) ); ?>">
                                        ⚙️ Shops & Clubs Management
                                    </a>
                                <?php elseif ( ! empty( $user_shop_id ) ): ?>
                                    <a href="<?php echo esc_url( site_url( '/sywat-manage-shops/?edit_shop=' . urlencode( $user_shop_id ) ) ); ?>">
                                        🏪 My Shop / Club Profile
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo esc_url( site_url( '/shops-and-clubs/' ) ); ?>" class="sywat-auth-nav-link">
                            🏪 Shops & Clubs
                        </a>
                    <?php endif; ?>

                    <!-- 4. Public Tournament Results -->
                    <a href="<?php echo esc_url( site_url( '/results/' ) ); ?>" class="sywat-auth-nav-link">
                        🏆 Results
                    </a>
                </nav>

                <!-- Right: Account Profile or Sign In Link -->
                <div style="display:flex; align-items:center; gap:12px; flex-shrink:0;">
                    <?php if ( $is_logged_in ): ?>
                        <span style="font-size:13px; font-weight:600; color:#e2e8f0; white-space:nowrap;">
                            👤 <?php echo esc_html( $current_user->display_name ); ?>
                        </span>

                        <a href="<?php echo esc_url( wp_logout_url( site_url( '/login/' ) ) ); ?>" class="sywat-btn sywat-btn-danger" style="font-size:12px; padding:4px 12px; height:32px; white-space:nowrap;">
                            🚪 Log Out
                        </a>
                    <?php else: ?>
                        <a href="<?php echo esc_url( site_url( '/login/' ) ); ?>" class="sywat-btn sywat-btn-primary" style="font-size:12px; padding:4px 12px; height:32px; white-space:nowrap;">
                            🔑 Login
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php
    }
}
add_action( 'wp_body_open', 'sywat_render_auth_header', 5 );

/**
 * Render Custom TFAA Front-End Auth Screen (Sign In, Lost Password, & Set New Password)
 */
if ( ! function_exists( 'sywat_render_login_screen' ) ) {
    function sywat_render_login_screen() {
        $auth_error   = ! empty( $GLOBALS['sywat_auth_error'] ) ? $GLOBALS['sywat_auth_error'] : '';
        $auth_message = ! empty( $GLOBALS['sywat_auth_message'] ) ? $GLOBALS['sywat_auth_message'] : '';
        
        $action      = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'login';
        $rp_key      = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : '';
        $rp_login    = isset( $_GET['login'] ) ? sanitize_text_field( $_GET['login'] ) : '';
        
        $redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : site_url( '/sywat-manage-tournaments/' );

        if ( ! empty( $rp_key ) && ! empty( $rp_login ) ) {
            $action = 'rp';
        }

        $current_url   = remove_query_arg( array( 'action', 'key', 'login' ) );
        $lost_pass_url = add_query_arg( 'action', 'lost_password', $current_url );
        $logo_url      = defined( 'SYWAT_PLUGIN_URL' ) ? SYWAT_PLUGIN_URL . 'assets/images/logo.png' : '';
        ?>
        <div class="sywat-login-card">
            
            <!-- Card Header -->
            <div style="background:#0f172a; padding:28px 20px; text-align:center; color:#ffffff;">
                <?php if ( ! empty( $logo_url ) ): ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="TFAA Logo" style="height:64px; width:auto; margin:0 auto 10px auto; display:block; filter:drop-shadow(0 2px 6px rgba(0,0,0,0.4));" onerror="this.style.display='none';">
                <?php endif; ?>
                <h3 style="margin:0; font-size:19px; font-weight:700; color:#ffffff;">Texas Field Archery Association</h3>
            </div>

            <!-- Card Body -->
            <div style="padding:24px; background:#f8fafc;">
                
                <?php if ( ! empty( $auth_error ) ): ?>
                    <div class="sywat-alert sywat-alert-danger">
                        ⚠️ <?php echo esc_html( $auth_error ); ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $auth_message ) ): ?>
                    <div class="sywat-alert sywat-alert-success">
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
                            <input type="text" name="sywat_user_login" required style="width:100%;">
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary" style="width:100%;">
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
                            <input type="password" name="pass1" required autocomplete="new-password" style="width:100%;">
                        </div>

                        <div style="margin-bottom:18px;">
                            <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Confirm New Password</label>
                            <input type="password" name="pass2" required autocomplete="new-password" style="width:100%;">
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary" style="width:100%;">
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
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">

                        <div style="margin-bottom:14px;">
                            <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Username or Email Address</label>
                            <input type="text" name="sywat_user_login" required autocomplete="username" style="width:100%;">
                        </div>

                        <div style="margin-bottom:14px;">
                            <label style="display:block; font-weight:600; font-size:12px; color:#334155; margin-bottom:4px;">Password</label>
                            <input type="password" name="sywat_user_pass" required autocomplete="current-password" style="width:100%;">
                        </div>

                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
                            <label style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; cursor:pointer;">
                                <input type="checkbox" name="sywat_rememberme" value="1" checked style="margin:0;"> Remember Me
                            </label>
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary" style="width:100%;">
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

/**
 * Shortcode Handler: [sywat_login]
 */
if ( ! function_exists( 'sywat_render_login_shortcode' ) ) {
    function sywat_render_login_shortcode() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();

        ob_start();
        echo '<div class="sywat-app">';

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            ?>
            <div class="sywat-card" style="text-align:center; padding:30px; max-width:500px; margin:20px auto;">
                <div style="font-size:32px; margin-bottom:8px;">🎯</div>
                <h3 style="margin-top:0; color:#0f172a;">Welcome, <?php echo esc_html( $user->display_name ); ?>!</h3>
                <p style="color:#64748b; font-size:14px;">You are currently signed in to the Texas Field Archery Association portal.</p>
            </div>
            <?php
        } else {
            sywat_render_login_screen();
        }

        echo '</div>';
        return ob_get_clean();
    }
    add_shortcode( 'sywat_login', 'sywat_render_login_shortcode' );
}
