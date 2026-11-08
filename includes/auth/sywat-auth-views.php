<?php
/**
 * Sub-Module: TFAA Authentication Views & Shortcode Renderer
 * Path: includes/auth/sywat-auth-views.php
 * Description: Renders master navigation header for logged-in and public users (including Head of Household user profile dropdown navigation), custom front-end login screens, lost password forms, and sign-in shortcodes.
 * Compliance: Zero inline style attributes. Layout styling driven strictly by centralized CSS utility classes.
 * Author: SYWAT / TFAA Archery
 * Version: 5.4.0
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

        // Dynamic capability checks tied directly to sywat-permissions
        $is_admin               = $is_logged_in && current_user_can( 'administrator' );
        $can_manage_tournaments = $is_logged_in && function_exists( 'sywat_can_manage_tournaments' ) && sywat_can_manage_tournaments( $user_id );
        $can_access_desk        = $is_logged_in && function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk( $user_id );
        $can_edit_scores        = $is_logged_in && function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores( $user_id );
        $can_view_members       = $is_logged_in && function_exists( 'sywat_can_view_members' ) && sywat_can_view_members( $user_id );
        $can_manage_seasons     = $is_logged_in && function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons( $user_id );
        $can_manage_shops       = $is_logged_in && function_exists( 'sywat_can_manage_shops' ) && sywat_can_manage_shops( $user_id );
        $user_shop_id           = $is_logged_in && function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : '';

        $logo_url = defined( 'SYWAT_PLUGIN_URL' ) ? SYWAT_PLUGIN_URL . 'assets/images/logo.png' : '';
        ?>
        <div class="sywat-auth-bar">
            <div class="sywat-auth-bar-inner">
                
                <!-- Left: Brand Logo & Title -->
                <div class="sywat-auth-brand-wrapper">
                    <?php if ( ! empty( $logo_url ) ): ?>
                        <img src="<?php echo esc_url( $logo_url ); ?>" alt="TFAA Logo" class="sywat-auth-logo sywat-auth-logo-sm" onerror="this.style.display='none';">
                    <?php endif; ?>
                    
                    <span class="sywat-auth-brand-title">
                        TFAA Archery
                    </span>
                </div>

                <!-- Center: Primary App Navigation Links -->
                <nav class="sywat-auth-nav-track">
                    
                    <!-- 1. Operational Tournaments Dropdown (Staff Only) -->
                    <?php if ( $can_manage_tournaments ): ?>
                        <div class="sywat-auth-dropdown">
                            <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>" class="sywat-auth-nav-link sywat-auth-dropdown-toggle">
                                🎯 Tournaments <span class="sywat-dropdown-caret">▼</span>
                            </a>
                            <div class="sywat-auth-dropdown-menu">
                                <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>">
                                    🎯 Tournament Portal
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 2. Administrative Dropdown (Global Admins & Webmasters Only) -->
                    <?php if ( $can_manage_seasons ): ?>
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
                    <?php if ( $can_manage_shops || ! empty( $user_shop_id ) ): ?>
                        <div class="sywat-auth-dropdown">
                            <a href="<?php echo esc_url( site_url( '/shops-and-clubs/' ) ); ?>" class="sywat-auth-nav-link sywat-auth-dropdown-toggle">
                                🎯 Shops & Clubs <span class="sywat-dropdown-caret">▼</span>
                            </a>
                            <div class="sywat-auth-dropdown-menu">
                                <?php if ( ! $is_admin && ! empty( $user_shop_id ) ): ?>
                                    <a href="<?php echo esc_url( site_url( '/sywat-manage-shops/?edit_shop=' . urlencode( $user_shop_id ) ) ); ?>">
                                        🎯 My Shop / Club Profile
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ( $can_manage_seasons ): ?>
                                    <a href="<?php echo esc_url( site_url( '/sywat-manage-shops/' ) ); ?>">
                                        ⚙️ Shops & Clubs Management
                                    </a>
                                <?php endif; ?>

                                <a href="<?php echo esc_url( site_url( '/shops-and-clubs/' ) ); ?>">
                                    🌐 View Public Directory
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo esc_url( site_url( '/shops-and-clubs/' ) ); ?>" class="sywat-auth-nav-link">
                            🎯 Shops & Clubs
                        </a>
                    <?php endif; ?>

                    <!-- 4. Public Tournament Results -->
                    <a href="<?php echo esc_url( site_url( '/results/' ) ); ?>" class="sywat-auth-nav-link">
                        🏆 Results
                    </a>
                </nav>

                <!-- Right: Account Profile Dropdown or Sign In Link -->
                <div class="sywat-auth-user-controls">
                    <?php if ( $is_logged_in ): ?>
                        <div class="sywat-auth-dropdown">
                            <a href="#" class="sywat-auth-nav-link sywat-auth-dropdown-toggle" onclick="return false;">
                                👤 <strong><?php echo esc_html( $current_user->display_name ); ?></strong> <span class="sywat-dropdown-caret">▼</span>
                            </a>
                            <div class="sywat-auth-dropdown-menu">
                                <a href="<?php echo esc_url( site_url( '/member-directory/?tab=members&subtab=profile' ) ); ?>">
                                    👤 My Profile &amp; Household
                                </a>
                            </div>
                        </div>

                        <a href="<?php echo esc_url( wp_logout_url( site_url( '/login/' ) ) ); ?>" class="sywat-btn sywat-btn-danger">
                            🚪 Log Out
                        </a>
                    <?php else: ?>
                        <a href="<?php echo esc_url( site_url( '/login/' ) ); ?>" class="sywat-btn sywat-btn-primary">
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
            <div class="sywat-login-card-header">
                <?php if ( ! empty( $logo_url ) ): ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="TFAA Logo" class="sywat-login-logo" onerror="this.style.display='none';">
                <?php endif; ?>
                <h3 class="sywat-login-title">Texas Field Archery Association</h3>
            </div>

            <!-- Card Body -->
            <div class="sywat-login-card-body">
                
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
                    <p class="sywat-login-instructions">
                        Enter your username or email address below. You will receive an email with instructions to reset your password.
                    </p>

                    <form method="post" id="sywat_lost_password_form">
                        <?php wp_nonce_field( 'sywat_auth_action', 'sywat_auth_nonce' ); ?>
                        <input type="hidden" name="sywat_auth_action" value="lost_password">

                        <div class="sywat-field-group sywat-mb-3">
                            <label>Username or Email Address</label>
                            <input type="text" name="sywat_user_login" required class="sywat-w-100">
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-w-100">
                            Send Reset Link
                        </button>
                    </form>

                    <div class="sywat-login-footer">
                        <a href="<?php echo esc_url( $current_url ); ?>" class="sywat-login-footer-link">
                            ← Back to Sign In
                        </a>
                    </div>

                <?php elseif ( $action === 'rp' ): ?>
                    <!-- STATE 3: SET NEW PASSWORD -->
                    <p class="sywat-login-instructions">
                        Please enter your new password below.
                    </p>

                    <form method="post" id="sywat_reset_password_form">
                        <?php wp_nonce_field( 'sywat_auth_action', 'sywat_auth_nonce' ); ?>
                        <input type="hidden" name="sywat_auth_action" value="reset_password">
                        <input type="hidden" name="rp_key" value="<?php echo esc_attr( $rp_key ); ?>">
                        <input type="hidden" name="rp_login" value="<?php echo esc_attr( $rp_login ); ?>">

                        <div class="sywat-field-group sywat-mb-3">
                            <label>New Password</label>
                            <input type="password" name="pass1" required autocomplete="new-password" class="sywat-w-100">
                        </div>

                        <div class="sywat-field-group sywat-mb-3">
                            <label>Confirm New Password</label>
                            <input type="password" name="pass2" required autocomplete="new-password" class="sywat-w-100">
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-w-100">
                            Save New Password
                        </button>
                    </form>

                    <div class="sywat-login-footer">
                        <a href="<?php echo esc_url( $current_url ); ?>" class="sywat-login-footer-link">
                            ← Back to Sign In
                        </a>
                    </div>

                <?php else: ?>
                    <!-- STATE 1: STANDARD SIGN IN -->
                    <form method="post" id="sywat_plugin_login_form">
                        <?php wp_nonce_field( 'sywat_auth_action', 'sywat_auth_nonce' ); ?>
                        <input type="hidden" name="sywat_auth_action" value="login">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">

                        <div class="sywat-field-group sywat-mb-3">
                            <label>Username or Email Address</label>
                            <input type="text" name="sywat_user_login" required autocomplete="username" class="sywat-w-100">
                        </div>

                        <div class="sywat-field-group sywat-mb-3">
                            <label>Password</label>
                            <input type="password" name="sywat_user_pass" required autocomplete="current-password" class="sywat-w-100">
                        </div>

                        <div class="sywat-login-remember-row">
                            <label class="sywat-checkbox-label">
                                <input type="checkbox" name="sywat_rememberme" value="1" checked class="sywat-m-0"> Remember Me
                            </label>
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-w-100">
                            Sign In
                        </button>
                    </form>

                    <div class="sywat-login-footer">
                        <a href="<?php echo esc_url( $lost_pass_url ); ?>" class="sywat-login-footer-link">
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
            <div class="sywat-card sywat-card-welcome-box">
                <div class="sywat-icon-welcome">🎯</div>
                <h3 class="sywat-welcome-title">Welcome, <?php echo esc_html( $user->display_name ); ?>!</h3>
                <p class="sywat-welcome-text">You are currently signed in to the Texas Field Archery Association portal.</p>
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
