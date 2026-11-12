<?php
/**
 * Sub-Module: Shop & Club Administration Management Views Controller
 * Path: includes/shops/shops-views.php
 * Author: SYWAT Archery / TFAA
 * Version: 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_shops_tab' ) ) {
    function sywat_render_shops_tab() {
        $can_manage_shops   = function_exists( 'sywat_can_manage_shops' ) && sywat_can_manage_shops();
        $can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
        $user_shop_id       = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : '';

        if ( ! $can_manage_shops && empty( $user_shop_id ) ) {
            echo '<div class="sywat-alert sywat-alert-danger">Management access required to manage shops and clubs.</div>';
            return;
        }

        // Enqueue Module JavaScript
        wp_enqueue_script( 'sywat-shops-js', plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/sywat-shops.js', array(), '1.0.0', true );

        $page_url        = sywat_page_url( 'manage_shops' );
        $notice          = sywat_handle_shop_actions();
        $all_shops       = sywat_get_shops();

        $edit_target_id  = isset( $_REQUEST['edit_shop'] ) ? sanitize_text_field( $_REQUEST['edit_shop'] ) : '';
        $manage_staff_id = isset( $_REQUEST['manage_staff'] ) ? sanitize_text_field( $_REQUEST['manage_staff'] ) : '';

        // Lock Shop Managers (non-board/admins) directly to their assigned shop profile or staff view
        if ( ! $can_manage_seasons && ! empty( $user_shop_id ) ) {
            if ( ! empty( $manage_staff_id ) && $manage_staff_id === $user_shop_id ) {
                // Allow managing staff for their own shop
            } else {
                // Default to editing their own shop profile
                $edit_target_id  = $user_shop_id;
                $manage_staff_id = '';
            }
        }

        $editing_shop    = ( ! empty( $edit_target_id ) && isset( $all_shops[$edit_target_id] ) ) ? $all_shops[$edit_target_id] : null;
        $managing_shop   = ( ! empty( $manage_staff_id ) && isset( $all_shops[$manage_staff_id] ) ) ? $all_shops[$manage_staff_id] : null;

        $type_filter     = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'all';
        $eligible_users  = get_users( array( 'role__in' => array( 'sywat_shop_manager', 'sywat_shop_user' ), 'orderby' => 'display_name' ) );

        $districts = array(
            'District 1' => 'District 1 (West TX / Panhandle)',
            'District 2' => 'District 2 (Vacant)',
            'District 3' => 'District 3 (Southeast TX)',
            'District 4' => 'District 4 (South TX)',
            'District 5' => 'District 5 (DFW Area)',
            'District 6' => 'District 6 (Central TX)',
            'District 7' => 'District 7 (East TX / LA)',
        );
        ?>

        <!-- Sub-Tab Navigation Bar (Only for Global Admins/Board Members) -->
        <?php if ( $can_manage_seasons ): ?>
            <div class="sywat-nav-tabs no-print">
                <a href="<?php echo esc_url( add_query_arg( 'type', 'all', $page_url ) ); ?>" class="sywat-tab-item <?php echo ($type_filter === 'all') ? 'active' : ''; ?>">
                    🌐 All Locations
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'type', 'pro_shop', $page_url ) ); ?>" class="sywat-tab-item <?php echo ($type_filter === 'pro_shop') ? 'active' : ''; ?>">
                    🎯 Shops
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'type', 'club', $page_url ) ); ?>" class="sywat-tab-item <?php echo ($type_filter === 'club') ? 'active' : ''; ?>">
                    🏹 Clubs
                </a>
            </div>
        <?php endif; ?>

        <div class="no-print"><?php echo $notice; ?></div>

        <?php 
        // Route to View Template Partial
        if ( $managing_shop ) {
            include __DIR__ . '/views/view-shop-staff.php';
        } elseif ( $editing_shop ) {
            include __DIR__ . '/views/view-shop-edit.php';
        } else {
            include __DIR__ . '/views/view-shop-directory.php';
        }
    }
}

/**
 * Shortcode Handler: [sywat_manage_shops]
 */
if ( ! function_exists( 'sywat_manage_shops_shortcode' ) ) {
    function sywat_manage_shops_shortcode() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();

        ob_start();
        echo '<div class="sywat-wrap">';
        sywat_render_shops_tab();
        echo '</div>';
        return ob_get_clean();
    }
    add_shortcode( 'sywat_manage_shops', 'sywat_manage_shops_shortcode' );
}
