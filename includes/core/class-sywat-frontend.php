<?php
/**
 * SYWAT Archery - Front-End Integration Layer
 * Path: includes/core/class-sywat-frontend.php
 *
 * Keeps the plugin theme-neutral so it behaves the same under Astra, Divi or
 * any other theme:
 *  - Detects pages that contain a SYWAT shortcode and loads CSS/JS only there.
 *  - Never loads plugin assets in wp-admin or inside page-builder editors.
 *  - Marks plugin pages as non-cacheable (nonces, staff data, live rosters).
 *  - Adds body classes used to scope the optional "hide theme header" rule.
 *  - Wraps every shortcode in a .sywat-scope container for CSS scoping.
 *  - Shields plugin scripts from JS "delay/defer/combine" optimizers.
 *  - Provides configurable page URLs (Settings > SYWAT Display).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* --------------------------------------------------------------------------
 * Shortcode registry
 * ----------------------------------------------------------------------- */

if ( ! function_exists( 'sywat_shortcode_tags' ) ) {
    function sywat_shortcode_tags() {
        $tags = array(
            'sywat_admin', 'sywat_manage', 'sywat_manage_tournaments', 'sywat_volunteer_hub',
            'sywat_members', 'sywat_register', 'sywat_member_register', 'sywat_desk',
            'sywat_scores', 'sywat_results', 'sywat_schedule', 'sywat_login',
            'sywat_public_shops', 'sywat_manage_shops', 'sywat_shooting_styles', 'sywat_pricing',
        );
        return apply_filters( 'sywat_shortcode_tags', $tags );
    }
}

/**
 * True when the current front-end request renders SYWAT content.
 * Checks the queried post's raw content (works for classic, block and
 * Divi 4/5 stored layouts). Override with the 'sywat_is_plugin_page' filter,
 * e.g. when a shortcode lives in a Divi Theme Builder template.
 */
if ( ! function_exists( 'sywat_is_plugin_page' ) ) {
    function sywat_is_plugin_page() {
        static $cached = null;
        if ( null !== $cached ) {
            return $cached;
        }
        $is = false;
        if ( ! is_admin() ) {
            $post = get_queried_object();
            if ( $post instanceof WP_Post && ! empty( $post->post_content ) ) {
                $pattern = '/' . implode( '|', array_map( 'preg_quote', sywat_shortcode_tags() ) ) . '/';
                $is      = (bool) preg_match( $pattern, $post->post_content );
            }
        }
        $result = (bool) apply_filters( 'sywat_is_plugin_page', $is );
        if ( did_action( 'wp' ) ) {
            $cached = $result;
        }
        return $result;
    }
}

/**
 * True inside a visual page-builder editor (Divi Visual Builder, Divi 5,
 * Elementor, Beaver Builder, block-editor previews of shortcodes).
 */
if ( ! function_exists( 'sywat_is_builder_request' ) ) {
    function sywat_is_builder_request() {
        $is = false;
        if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_tb'] ) || isset( $_GET['et_bfb'] ) ) {
            $is = true;
        }
        if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
            $is = true;
        }
        if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 0 === strpos( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), 'et_fb' ) ) {
            $is = true;
        }
        if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['fl_builder'] ) ) {
            $is = true;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['context'] ) && 'edit' === $_GET['context'] ) {
            $is = true;
        }
        return (bool) apply_filters( 'sywat_is_builder_request', $is );
    }
}

/* --------------------------------------------------------------------------
 * Display settings (Settings > SYWAT Display)
 * ----------------------------------------------------------------------- */

if ( ! function_exists( 'sywat_page_defaults' ) ) {
    function sywat_page_defaults() {
        return array(
            'manage_tournaments'  => array( '/sywat-manage-tournaments/', 'Manage Tournaments page' ),
            'register'            => array( '/sywat-register/', 'Register Shooters page' ),
            'member_register'     => array( '/register-online/', 'Member Online Registration page' ),
            'enter_scores'        => array( '/sywat-enter-scores/', 'Enter Scores page' ),
            'desk'                => array( '/sywat-desk/', 'Check-In Desk page' ),
            'shops_public'        => array( '/shops-and-clubs/', 'Public Shops & Clubs page' ),
            'volunteer_hub'       => array( '/sywat-volunteer-hub/', 'Volunteer Hub page' ),
            'manage_shops'        => array( '/sywat-manage-shops/', 'Manage Shops page' ),
            'login'               => array( '/login/', 'Login page' ),
            'schedule'            => array( '/schedule/', 'Season Schedule page' ),
            'results'             => array( '/results/', 'Results & Standings page' ),
            'member_directory'    => array( '/member-directory/', 'Member Directory page' ),
            'shooting_styles'     => array( '/shooting-styles/', 'Shooting Styles & Rules page' ),
            'pricing'             => array( '/pricing/', 'Pricing & Round Formats page' ),
            'after_login_manager' => array( '/tournaments', 'After login: tournament managers go to' ),
            'after_login_shop'    => array( '/desk', 'After login: shop operators go to' ),
        );
    }
}

/**
 * Full URL of a plugin page. Defaults match the original hard-coded paths,
 * so existing sites behave exactly as before until a path is changed.
 */
if ( ! function_exists( 'sywat_page_url' ) ) {
    function sywat_page_url( $key ) {
        $defaults = sywat_page_defaults();
        $saved    = get_option( 'sywat_page_paths', array() );
        $path     = ( is_array( $saved ) && ! empty( $saved[ $key ] ) ) ? $saved[ $key ] : ( isset( $defaults[ $key ] ) ? $defaults[ $key ][0] : '/' );
        return apply_filters( 'sywat_page_url', site_url( $path ), $key, $path );
    }
}

/**
 * Layout mode.
 *  'app'   (default) - original behaviour: the plugin top bar is the site
 *                      navigation on every page and the theme header/title
 *                      are hidden. Plugin CSS loads on every front-end page.
 *  'theme'           - for a designed site (e.g. Divi): the theme header and
 *                      menu stay, plugin CSS loads only on plugin pages, and
 *                      the plugin top bar is shown at the top of plugin pages.
 */
if ( ! function_exists( 'sywat_layout_mode' ) ) {
    function sywat_layout_mode() {
        $mode = get_option( 'sywat_layout_mode', 'app' );
        $mode = apply_filters( 'sywat_layout_mode', $mode );
        return 'theme' === $mode ? 'theme' : 'app';
    }
}

if ( ! function_exists( 'sywat_is_app_mode' ) ) {
    function sywat_is_app_mode() {
        return 'app' === sywat_layout_mode();
    }
}

if ( ! function_exists( 'sywat_hide_theme_header_enabled' ) ) {
    function sywat_hide_theme_header_enabled() {
        return (bool) apply_filters( 'sywat_hide_theme_header', sywat_is_app_mode() );
    }
}

add_action( 'admin_menu', function () {
    add_options_page( 'SYWAT Display', 'SYWAT Display', 'manage_options', 'sywat-display', 'sywat_render_display_settings' );
} );

add_action( 'admin_init', function () {
    register_setting( 'sywat_display', 'sywat_layout_mode', array(
        'type'              => 'string',
        'sanitize_callback' => function ( $v ) { return 'theme' === $v ? 'theme' : 'app'; },
        'default'           => 'app',
    ) );
    register_setting( 'sywat_display', 'sywat_page_paths', array(
        'type'              => 'array',
        'sanitize_callback' => function ( $v ) {
            $out = array();
            if ( is_array( $v ) ) {
                foreach ( sywat_page_defaults() as $key => $def ) {
                    if ( ! empty( $v[ $key ] ) ) {
                        $p = '/' . ltrim( sanitize_text_field( wp_unslash( $v[ $key ] ) ), '/' );
                        if ( $p !== $def[0] ) {
                            $out[ $key ] = $p;
                        }
                    }
                }
            }
            return $out;
        },
        'default'           => array(),
    ) );
} );

if ( ! function_exists( 'sywat_render_display_settings' ) ) {
    function sywat_render_display_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $saved = get_option( 'sywat_page_paths', array() );
        ?>
        <div class="wrap">
            <h1>SYWAT Display</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'sywat_display' ); ?>
                <h2>Layout</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Layout mode</th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="sywat_layout_mode" value="app" <?php checked( sywat_layout_mode(), 'app' ); ?>>
                                <strong>App mode</strong> (original): the SYWAT top bar is the navigation on every page; the theme header and page titles are hidden.</label><br>
                                <label><input type="radio" name="sywat_layout_mode" value="theme" <?php checked( sywat_layout_mode(), 'theme' ); ?>>
                                <strong>Theme mode</strong>: keep the theme's header and menu (e.g. Divi). The SYWAT top bar appears at the top of plugin pages only, and plugin styles load only there.</label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <h2>Page addresses</h2>
                <p>Paths used for links between plugin screens. Leave blank to use the default shown.</p>
                <table class="form-table" role="presentation">
                    <?php foreach ( sywat_page_defaults() as $key => $def ) : ?>
                        <tr>
                            <th scope="row"><label for="sywat_path_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $def[1] ); ?></label></th>
                            <td><input type="text" class="regular-text code" id="sywat_path_<?php echo esc_attr( $key ); ?>" name="sywat_page_paths[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $saved[ $key ] ) ? $saved[ $key ] : '' ); ?>" placeholder="<?php echo esc_attr( $def[0] ); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

/* --------------------------------------------------------------------------
 * Assets: register everywhere, enqueue only where needed
 * ----------------------------------------------------------------------- */

if ( ! function_exists( 'sywat_register_assets' ) ) {
    function sywat_register_assets() {
        $css = SYWAT_PLUGIN_URL . 'assets/css/';
        $js  = SYWAT_PLUGIN_URL . 'assets/js/';
        $v   = SYWAT_PLUGIN_VERSION;

        wp_register_style( 'sywat-core',    $css . 'sywat-core.css',    array(), $v );
        wp_register_style( 'sywat-buttons', $css . 'sywat-buttons.css', array( 'sywat-core' ), $v );
        wp_register_style( 'sywat-tables',  $css . 'sywat-tables.css',  array( 'sywat-core' ), $v );
        wp_register_style( 'sywat-toolbar', $css . 'sywat-toolbar.css', array( 'sywat-core' ), $v );
        wp_register_style( 'sywat-modals',  $css . 'sywat-modals.css',  array( 'sywat-core' ), $v );
        wp_register_style( 'sywat-public',  $css . 'sywat-public.css',  array( 'sywat-core' ), $v );

        wp_register_script( 'sywat-modal-manager', $js . 'sywat-modal-manager.js', array(), $v, true );
        wp_add_inline_script( 'sywat-modal-manager', 'window.ajaxurl = window.ajaxurl || ' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ';', 'before' );
    }
}
add_action( 'wp_enqueue_scripts', 'sywat_register_assets', 5 );

/**
 * Enqueue the shared stylesheets and modal manager. Safe to call more than
 * once; also called from shortcode output as a fallback when the shortcode
 * sits somewhere the page scan cannot see (Theme Builder, widgets).
 */
if ( ! function_exists( 'sywat_enqueue_frontend_assets' ) ) {
    function sywat_enqueue_frontend_assets() {
        if ( is_admin() || sywat_is_builder_request() ) {
            return;
        }
        if ( ! wp_style_is( 'sywat-core', 'registered' ) ) {
            sywat_register_assets();
        }
        foreach ( array( 'sywat-core', 'sywat-buttons', 'sywat-tables', 'sywat-toolbar', 'sywat-modals', 'sywat-public' ) as $h ) {
            wp_enqueue_style( $h );
        }
        wp_enqueue_script( 'sywat-modal-manager' );
    }
}

add_action( 'wp_enqueue_scripts', function () {
    if ( sywat_is_plugin_page() ) {
        sywat_enqueue_frontend_assets();
    } elseif ( sywat_is_app_mode() && ! sywat_is_builder_request() ) {
        // App mode: the SYWAT top bar is on every page, so its styles are too.
        if ( ! wp_style_is( 'sywat-core', 'registered' ) ) {
            sywat_register_assets();
        }
        foreach ( array( 'sywat-core', 'sywat-buttons', 'sywat-tables', 'sywat-toolbar', 'sywat-modals', 'sywat-public' ) as $h ) {
            wp_enqueue_style( $h );
        }
    }
}, 99 );

/**
 * Module scripts (tournaments, members) used to load on every page. They now
 * load only on plugin pages; this helper is what their enqueue hooks call.
 */
if ( ! function_exists( 'sywat_should_load_module_assets' ) ) {
    function sywat_should_load_module_assets() {
        return ! is_admin() && ! sywat_is_builder_request() && sywat_is_plugin_page();
    }
}

/* --------------------------------------------------------------------------
 * Shortcode wrapper, builder placeholder, body classes, caching
 * ----------------------------------------------------------------------- */

// In page-builder editors show a placeholder instead of the live app.
add_filter( 'pre_do_shortcode_tag', function ( $return, $tag ) {
    if ( false === $return && in_array( $tag, sywat_shortcode_tags(), true ) && sywat_is_builder_request() ) {
        return '<div class="sywat-builder-placeholder" style="padding:24px;border:2px dashed #94a3b8;border-radius:6px;text-align:center;font-family:sans-serif;color:#334155">SYWAT: <code>[' . esc_html( $tag ) . ']</code> appears here on the live page.</div>';
    }
    return $return;
}, 10, 2 );

// Wrap every SYWAT shortcode in a scoping container and make sure assets load.
add_filter( 'do_shortcode_tag', function ( $output, $tag ) {
    if ( ! in_array( $tag, sywat_shortcode_tags(), true ) || sywat_is_builder_request() ) {
        return $output;
    }
    sywat_enqueue_frontend_assets();
    return '<div class="sywat-scope" data-sywat-shortcode="' . esc_attr( $tag ) . '">' . sywat_get_inline_auth_bar() . $output . '</div>';
}, 10, 2 );

add_filter( 'body_class', function ( $classes ) {
    if ( sywat_is_plugin_page() ) {
        $classes[] = 'sywat-page';
    }
    // App mode hides the theme header on every page (original behaviour).
    if ( sywat_hide_theme_header_enabled() ) {
        $classes[] = 'sywat-hide-theme-chrome';
    }
    $classes[] = 'sywat-layout-' . sywat_layout_mode();
    return $classes;
} );

/**
 * Theme mode: move the SYWAT top bar from the top of <body> (where it would sit
 * underneath a fixed theme header) to the top of the first plugin shortcode.
 */
add_action( 'init', function () {
    if ( ! sywat_is_app_mode() && function_exists( 'sywat_render_auth_header' ) ) {
        remove_action( 'wp_body_open', 'sywat_render_auth_header', 5 );
    }
}, 20 );

if ( ! function_exists( 'sywat_get_inline_auth_bar' ) ) {
    function sywat_get_inline_auth_bar() {
        static $done = false;
        if ( $done || sywat_is_app_mode() || ! function_exists( 'sywat_render_auth_header' ) ) {
            return '';
        }
        $done = true;
        ob_start();
        sywat_render_auth_header();
        return '<div class="sywat-auth-bar-inline">' . ob_get_clean() . '</div>';
    }
}

// Plugin pages carry nonces, staff-only data and live rosters: never cache them.
add_action( 'template_redirect', function () {
    if ( ! sywat_is_plugin_page() || ! apply_filters( 'sywat_disable_page_cache', true ) ) {
        return;
    }
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true ); // WP Rocket, W3TC, WP Super Cache, LiteSpeed.
    }
    if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
        define( 'DONOTCACHEOBJECT', true );
    }
    nocache_headers();
}, 1 );

/* --------------------------------------------------------------------------
 * Shield plugin scripts from JS optimizers
 *
 * The plugin's markup calls its JavaScript from onclick/onchange attributes.
 * "Delay JavaScript execution" style optimizations hold scripts back until the
 * visitor interacts, which makes the first click call a function that does not
 * exist yet. These markers opt SYWAT scripts out of delay/defer/combine.
 * ----------------------------------------------------------------------- */

if ( ! defined( 'SYWAT_SCRIPT_OPTOUT_ATTRS' ) ) {
    // nowprocket = WP Rocket, data-cfasync = Cloudflare Rocket Loader,
    // data-no-optimize / data-no-defer = LiteSpeed Cache, data-pagespeed-no-defer = PageSpeed.
    define( 'SYWAT_SCRIPT_OPTOUT_ATTRS', 'nowprocket data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-pagespeed-no-defer' );
}

if ( ! function_exists( 'sywat_is_plugin_script_handle' ) ) {
    function sywat_is_plugin_script_handle( $handle ) {
        return 0 === strpos( (string) $handle, 'sywat-' );
    }
}

add_filter( 'script_loader_tag', function ( $tag, $handle ) {
    if ( sywat_is_plugin_script_handle( $handle ) && false === strpos( $tag, 'nowprocket' ) ) {
        $tag = str_replace( '<script ', '<script ' . SYWAT_SCRIPT_OPTOUT_ATTRS . ' ', $tag );
    }
    return $tag;
}, 10, 2 );

// Inline data/script blocks attached to SYWAT handles (wp_localize_script, wp_add_inline_script).
add_filter( 'wp_inline_script_attributes', function ( $attributes ) {
    if ( isset( $attributes['id'] ) && 0 === strpos( $attributes['id'], 'sywat-' ) ) {
        $attributes['nowprocket']              = true;
        $attributes['data-cfasync']            = 'false';
        $attributes['data-no-optimize']        = '1';
        $attributes['data-no-defer']           = '1';
        $attributes['data-pagespeed-no-defer'] = true;
    }
    return $attributes;
} );
add_filter( 'wp_script_attributes', function ( $attributes ) {
    if ( isset( $attributes['id'] ) && 0 === strpos( $attributes['id'], 'sywat-' ) ) {
        $attributes['data-cfasync']     = 'false';
        $attributes['data-no-optimize'] = '1';
    }
    return $attributes;
} );

// WP Rocket: exclude from Delay JS, Defer and Combine/Minify.
$sywat_rocket_patterns = function ( $patterns ) {
    $patterns   = is_array( $patterns ) ? $patterns : array();
    $patterns[] = '/sywat-archery-manager/assets/js/(.*)';
    $patterns[] = basename( dirname( dirname( __DIR__ ) ) ) . '/assets/js/(.*)';
    $patterns[] = 'sywat';
    return array_values( array_unique( $patterns ) );
};
add_filter( 'rocket_delay_js_exclusions', $sywat_rocket_patterns );
add_filter( 'rocket_exclude_defer_js', $sywat_rocket_patterns );
add_filter( 'rocket_exclude_js', $sywat_rocket_patterns );
add_filter( 'rocket_excluded_inline_js_content', function ( $patterns ) {
    $patterns   = is_array( $patterns ) ? $patterns : array();
    $patterns[] = 'sywat';
    return $patterns;
} );
// LiteSpeed Cache.
add_filter( 'litespeed_optimize_js_excludes', function ( $list ) {
    $list   = is_array( $list ) ? $list : array();
    $list[] = 'sywat';
    return $list;
} );
add_filter( 'litespeed_optm_js_defer_exc', function ( $list ) {
    $list   = is_array( $list ) ? $list : array();
    $list[] = 'sywat';
    return $list;
} );
