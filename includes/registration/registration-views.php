<?php
/**
 * Sub-Module: Registration Engine Views Renderer
 * Path: includes/registration/registration-views.php
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render Full Registration Desk Page (Shortcode Handler: [sywat_register])
 */
if ( ! function_exists( 'sywat_render_registration_desk' ) ) {
    function sywat_render_registration_desk() {
        if ( ! function_exists( 'sywat_can_access_desk' ) || ! sywat_can_access_desk() ) {
            return '<div class="sywat-alert sywat-alert-danger">Access denied. Desk management access required.</div>';
        }

        ob_start();
        global $wpdb;

        $can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
        $can_access_desk    = sywat_can_access_desk();
        $can_edit_scores    = function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores();

        $user_shop_id       = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';
        $all_shops          = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        // Scope Tournaments to User's Assigned Shop
        $scoped_tournaments = array();
        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop = sywat_get_tournament_shop_id( $t_data );
            if ( $can_manage_seasons || $t_shop === $user_shop_id ) {
                $scoped_tournaments[$t_name] = $t_data;
            }
        }

        // Determine Selected Tournament
        $selected_tournament = isset( $_REQUEST['selected_tournament'] ) ? sanitize_text_field( $_REQUEST['selected_tournament'] ) : '';
        if ( empty( $selected_tournament ) && ! empty( $scoped_tournaments ) ) {
            $keys = array_keys( $scoped_tournaments );
            $selected_tournament = reset( $keys );
        }

        $available_lines = ! empty( $selected_tournament ) && isset( $tournaments[$selected_tournament] ) 
            ? sywat_get_tournament_lines( $tournaments[$selected_tournament] ) 
            : array();

        // Load Registrations from DB
        $registrations = array();
        $results = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%'" );
        if ( is_array( $results ) ) {
            foreach ($results as $row) {
                $reg_data = maybe_unserialize($row->option_value);
                if (is_array($reg_data) && isset($reg_data['id'])) {
                    $registrations[] = $reg_data;
                }
            }
        }

        // POST Handling Notice
        $notice = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_action'] ) ) {
            $is_valid_nonce = isset($_POST['sywat_nonce']) && wp_verify_nonce($_POST['sywat_nonce'], 'sywat_desk_action');
            if ( $is_valid_nonce ) {
                $action = sanitize_text_field( $_POST['sywat_action'] );
                if ( $action === 'register_shooter' && function_exists( 'sywat_handle_register_shooter' ) ) {
                    $notice = sywat_handle_register_shooter( $tournaments, $registrations );
                } elseif ( $action === 'import_shooters' && function_exists( 'sywat_handle_import_shooters' ) ) {
                    $notice = sywat_handle_import_shooters( $tournaments, $registrations );
                } elseif ( $action === 'purge_line' && function_exists( 'sywat_handle_purge_line' ) ) {
                    $notice = sywat_handle_purge_line( $tournaments, $registrations );
                }
            } else {
                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
            }
        }

        // Enqueue Client Logic & Localize Data
        if ( function_exists( 'sywat_render_registration_scripts' ) ) {
            sywat_render_registration_scripts();
        }
        ?>

        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <!-- Master Operational Navigation Tabs (Card-Docked) -->
            <div class="sywat-nav-tabs no-print">
                <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">Manage Tournaments</a>
                <a href="<?php echo esc_url( site_url( '/sywat-register/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-register active">Register Shooters</a>
                <?php if ( $can_access_desk ): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-desk/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-checkin">Check In Archers</a>
                <?php endif; ?>
                <?php if ( $can_edit_scores ): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-enter-scores/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-edit">Enter Scores</a>
                <?php endif; ?>
                <?php if ( $can_manage_seasons ): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/?tab=seasons' ) ); ?>" class="sywat-tab-item sywat-tab-icon-season">Manage Seasons</a>
                <?php endif; ?>
            </div>

            <!-- Main Desk Card -->
            <div class="sywat-card no-print">
                <!-- UNIFIED TOP CARD HEADER BAR WITH MATCHING DARK BLUE TITLE -->
                <div class="sywat-card-header-bar">
                    <div>
                        <h2>📝 Register Shooters</h2>
                        <div class="sywat-subtitle">Select an active tournament to register incoming archers.</div>
                    </div>

                    <!-- Tournament Switcher Control Bar -->
                    <div class="sywat-header-inline-controls">
                        <form method="get" action="<?php echo esc_url( site_url( '/sywat-register/' ) ); ?>">
                            <div class="sywat-field-group">
                                <label for="sywat_selected_tournament">Active Tournament:</label>
                                <select id="sywat_selected_tournament" name="selected_tournament" onchange="this.form.submit()">
                                    <?php if ( empty( $scoped_tournaments ) ): ?>
                                        <option value="">No Tournaments Configured</option>
                                    <?php else: ?>
                                        <?php foreach ( $scoped_tournaments as $t_name => $t_data ): ?>
                                            <option value="<?php echo esc_attr( $t_name ); ?>" <?php selected( $selected_tournament, $t_name ); ?>>
                                                <?php echo esc_html( $t_name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ( ! empty( $selected_tournament ) && isset( $tournaments[$selected_tournament] ) ): ?>
                    <?php 
                    if ( function_exists( 'sywat_render_registration_modal' ) ) {
                        sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations ); 
                    }
                    ?>
                <?php else: ?>
                    <p class="sywat-label-subtext">Please select or create a tournament to begin registering shooters.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
if ( ! shortcode_exists( 'sywat_register' ) ) {
    add_shortcode( 'sywat_register', 'sywat_render_registration_desk' );
}
