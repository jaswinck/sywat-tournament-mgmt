<?php
/**
 * Sub-Module: Shop & Club Administration Management Views Controller
 * Path: includes/shops/shops-views.php
 * Author: SYWAT Archery / TFAA
 * Version: 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_shops_tab' ) ) {
    function sywat_render_shops_tab() {
        $can_manage = function_exists( 'sywat_can_manage_seasons' ) ? sywat_can_manage_seasons() : current_user_can( 'administrator' );
        if ( ! $can_manage ) {
            echo '<p style="color:red; padding:20px;">Management access required to manage shops and clubs.</p>';
            return;
        }

        // Enqueue Module JavaScript
        wp_enqueue_script( 'sywat-shops-js', plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/sywat-shops.js', array(), '1.0.0', true );

        $page_url        = site_url( '/sywat-manage-shops/' );
        $notice          = sywat_handle_shop_actions();
        $all_shops       = sywat_get_shops();
        $edit_target_id  = isset( $_REQUEST['edit_shop'] ) ? sanitize_text_field( $_REQUEST['edit_shop'] ) : '';
        $editing_shop    = ( ! empty( $edit_target_id ) && isset( $all_shops[$edit_target_id] ) ) ? $all_shops[$edit_target_id] : null;
        $manage_staff_id = isset( $_REQUEST['manage_staff'] ) ? sanitize_text_field( $_REQUEST['manage_staff'] ) : '';
        $managing_shop   = ( ! empty( $manage_staff_id ) && isset( $all_shops[$manage_staff_id] ) ) ? $all_shops[$manage_staff_id] : null;

        $type_filter     = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'all';
        $eligible_users  = get_users( array( 'role__in' => array( 'sywat_shop_manager', 'sywat_shop_user' ), 'orderby' => 'display_name' ) );

        $districts = array(
            'District 1' => 'District 1 (Panhandle / West Texas)',
            'District 2' => 'District 2 (North Texas / DFW)',
            'District 3' => 'District 3 (East Texas)',
            'District 4' => 'District 4 (Central / Hill Country)',
            'District 5' => 'District 5 (South Texas / Gulf)',
        );
        ?>

        <!-- Sub-Tab Navigation Bar -->
        <div class="sywat-nav-tabs no-print">
            <a href="<?php echo esc_url( add_query_arg( 'type', 'all', $page_url ) ); ?>" class="sywat-tab-item <?php echo ($type_filter === 'all') ? 'active' : ''; ?>">
                🌐 All Locations
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'type', 'pro_shop', $page_url ) ); ?>" class="sywat-tab-item <?php echo ($type_filter === 'pro_shop') ? 'active' : ''; ?>">
                🏪 Shops
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'type', 'club', $page_url ) ); ?>" class="sywat-tab-item <?php echo ($type_filter === 'club') ? 'active' : ''; ?>">
                🌲 Clubs
            </a>
        </div>

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
