<?php
/**
 * Sub-Module: Registration Engine Views Renderer
 * Path: includes/registration/registration-views.php
 * Author: SYWAT Archery
 * Version: 6.8.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Helper: Filter scoped tournaments to active season(s) and build an ordered option map
 * with the currently selected tournament at the top and all remaining tournaments sorted A–Z.
 *
 * @param array  $scoped_tournaments  Shop-scoped tournaments array (name => config).
 * @param string $requested_tourn     Unslashed requested tournament name from GET/POST.
 * @param string $user_shop_id        Current user's assigned shop ID.
 * @return array                      Associative array with 'ordered' (name => label) and 'selected' (string).
 */
if ( ! function_exists( 'sywat_registration_build_tournament_dropdown' ) ) {
    function sywat_registration_build_tournament_dropdown( $scoped_tournaments, $requested_tourn, $user_shop_id ) {
        if ( empty( $scoped_tournaments ) || ! is_array( $scoped_tournaments ) ) {
            return array(
                'ordered'  => array(),
                'selected' => '',
            );
        }

        // Identify active season slugs/names so archived season events do not clutter the dropdown
        $seasons            = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : get_option( 'sywat_seasons_list', array() );
        $active_season_keys = array();
        if ( is_array( $seasons ) ) {
            foreach ( $seasons as $s_slug => $s_data ) {
                if ( is_array( $s_data ) && isset( $s_data['status'] ) && $s_data['status'] === 'active' ) {
                    $active_season_keys[] = strtolower( trim( (string) $s_slug ) );
                    if ( ! empty( $s_data['name'] ) ) {
                        $active_season_keys[] = strtolower( trim( (string) $s_data['name'] ) );
                    }
                }
            }
        }

        // Filter pool to active season(s), always preserving an explicitly requested tournament if valid
        $pool = array();
        foreach ( $scoped_tournaments as $t_name => $t_data ) {
            if ( ! empty( $requested_tourn ) && strcasecmp( $t_name, $requested_tourn ) === 0 ) {
                $pool[ $t_name ] = $t_data;
                continue;
            }
            if ( empty( $active_season_keys ) ) {
                $pool[ $t_name ] = $t_data;
                continue;
            }
            $t_season = '';
            if ( is_array( $t_data ) ) {
                $t_season = ! empty( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( ! empty( $t_data['season'] ) ? $t_data['season'] : '' );
            }
            if ( empty( $t_season ) || in_array( strtolower( trim( (string) $t_season ) ), $active_season_keys, true ) ) {
                $pool[ $t_name ] = $t_data;
            }
        }

        if ( empty( $pool ) ) {
            $pool = $scoped_tournaments;
        }

        // Sort all pool keys alphabetically (A–Z, natural case-insensitive)
        $sorted_keys = array_keys( $pool );
        usort( $sorted_keys, 'strnatcasecmp' );

        // Resolve actual selected tournament using unslashed exact or case-insensitive key match
        $selected_tournament = '';
        if ( ! empty( $requested_tourn ) ) {
            if ( isset( $pool[ $requested_tourn ] ) ) {
                $selected_tournament = $requested_tourn;
            } else {
                foreach ( $sorted_keys as $k ) {
                    if ( strcasecmp( $k, $requested_tourn ) === 0 ) {
                        $selected_tournament = $k;
                        break;
                    }
                }
            }
        }

        // If no tournament was explicitly requested, pick a clean default:
        // 1. Nearest upcoming tournament belonging to the user's assigned shop
        // 2. Nearest upcoming tournament overall
        // 3. First tournament alphabetically (A–Z)
        if ( empty( $selected_tournament ) ) {
            $today_ymd     = current_time( 'Y-m-d' );
            $shop_upcoming = array();
            $any_upcoming  = array();

            foreach ( $sorted_keys as $k ) {
                $s_date = isset( $pool[ $k ]['start_date'] ) ? $pool[ $k ]['start_date'] : '';
                if ( ! empty( $s_date ) && $s_date >= $today_ymd ) {
                    $any_upcoming[ $k ] = $s_date;
                    $t_shop = function_exists( 'sywat_get_tournament_shop_id' )
                        ? (string) sywat_get_tournament_shop_id( $pool[ $k ] )
                        : ( isset( $pool[ $k ]['shop_id'] ) ? (string) $pool[ $k ]['shop_id'] : '' );
                    if ( ! empty( $user_shop_id ) && $t_shop === (string) $user_shop_id ) {
                        $shop_upcoming[ $k ] = $s_date;
                    }
                }
            }

            if ( ! empty( $shop_upcoming ) ) {
                asort( $shop_upcoming );
                reset( $shop_upcoming );
                $selected_tournament = key( $shop_upcoming );
            } elseif ( ! empty( $any_upcoming ) ) {
                asort( $any_upcoming );
                reset( $any_upcoming );
                $selected_tournament = key( $any_upcoming );
            } elseif ( ! empty( $sorted_keys ) ) {
                $selected_tournament = $sorted_keys[0];
            }
        }

        // Build ordered dropdown options:
        // 1. Currently selected tournament pinned at the top
        // 2. All remaining tournaments sorted A–Z
        $ordered = array();
        if ( ! empty( $selected_tournament ) && isset( $pool[ $selected_tournament ] ) ) {
            $ordered[ $selected_tournament ] = $selected_tournament;
        }
        foreach ( $sorted_keys as $t_name ) {
            if ( $t_name === $selected_tournament ) {
                continue;
            }
            $ordered[ $t_name ] = $t_name;
        }

        return array(
            'ordered'  => $ordered,
            'selected' => $selected_tournament,
        );
    }
}

/**
 * Render Full Registration Desk Page (Shortcode Handler: [sywat_register])
 *
 * @return string Rendered HTML markup output for the registration portal.
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

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        // Scope Tournaments to User's Assigned Shop
        $scoped_tournaments = array();
        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
            if ( $can_manage_seasons || $t_shop === $user_shop_id ) {
                $scoped_tournaments[ $t_name ] = $t_data;
            }
        }

        // Unslash GET/POST tournament names so apostrophes (e.g., "Buck & Doe's - 1") match exact keys
        $requested_tourn = '';
        if ( isset( $_REQUEST['selected_tournament'] ) ) {
            $requested_tourn = sanitize_text_field( wp_unslash( $_REQUEST['selected_tournament'] ) );
        } elseif ( isset( $_GET['view_tournament'] ) ) {
            $requested_tourn = sanitize_text_field( wp_unslash( $_GET['view_tournament'] ) );
        }

        $dropdown_data       = sywat_registration_build_tournament_dropdown( $scoped_tournaments, $requested_tourn, $user_shop_id );
        $ordered_tournaments = $dropdown_data['ordered'];
        $selected_tournament = $dropdown_data['selected'];

        $available_lines = ( ! empty( $selected_tournament ) && isset( $tournaments[ $selected_tournament ] ) && function_exists( 'sywat_get_tournament_lines' ) )
            ? sywat_get_tournament_lines( $tournaments[ $selected_tournament ] )
            : array();

        $active_t_slug = sanitize_title( $selected_tournament );

        // High Concurrency Scoped Query: Fetch registrations for active tournament only
        $registrations = array();
        if ( ! empty( $active_t_slug ) ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'sywat_t_' . $active_t_slug . '_' ) . '%'
            ) );

            if ( is_array( $results ) ) {
                foreach ( $results as $row ) {
                    $reg_data = maybe_unserialize( $row->option_value );
                    if ( is_array( $reg_data ) && isset( $reg_data['id'] ) ) {
                        $registrations[] = $reg_data;
                    }
                }
            }
        }

        // POST Handling Notice
        $notice = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_action'] ) ) {
            $nonce_val      = isset( $_POST['sywat_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ) : '';
            $is_valid_nonce = ! empty( $nonce_val ) && wp_verify_nonce( $nonce_val, 'sywat_desk_action' );
            if ( $is_valid_nonce ) {
                $action = sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) );
                if ( $action === 'register_shooter' && function_exists( 'sywat_handle_register_shooter' ) ) {
                    $notice = sywat_handle_register_shooter( $tournaments, $registrations );
                } elseif ( $action === 'import_shooters' && function_exists( 'sywat_handle_import_shooters' ) ) {
                    $import_tourn    = isset( $_POST['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) ) : $selected_tournament;
                    $target_line_idx = isset( $_POST['target_line_idx'] ) ? sanitize_text_field( wp_unslash( $_POST['target_line_idx'] ) ) : '0';
                    $notice          = sywat_handle_import_shooters( $import_tourn, $target_line_idx, $tournaments, $registrations );
                } elseif ( $action === 'purge_line' ) {
                    if ( ! function_exists( 'sywat_handle_purge_line' ) && defined( 'SYWAT_PLUGIN_DIR' ) && file_exists( SYWAT_PLUGIN_DIR . 'includes/roster/class-sywat-roster.php' ) ) {
                        require_once SYWAT_PLUGIN_DIR . 'includes/roster/class-sywat-roster.php';
                    }
                    if ( function_exists( 'sywat_handle_purge_line' ) ) {
                        $notice = sywat_handle_purge_line( $tournaments, $registrations );
                    }
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
                <a href="<?php echo esc_url( sywat_page_url( 'manage_tournaments' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">Manage Tournaments</a>
                <a href="<?php echo esc_url( sywat_page_url( 'register' ) ); ?>" class="sywat-tab-item sywat-tab-icon-register active">Register Shooters</a>
                <?php if ( $can_access_desk ): ?>
                    <a href="<?php echo esc_url( sywat_page_url( 'desk' ) ); ?>" class="sywat-tab-item sywat-tab-icon-checkin">Check In Archers</a>
                <?php endif; ?>
                <?php if ( $can_edit_scores ): ?>
                    <a href="<?php echo esc_url( sywat_page_url( 'enter_scores' ) ); ?>" class="sywat-tab-item sywat-tab-icon-edit">Enter Scores</a>
                <?php endif; ?>
                <?php if ( $can_manage_seasons ): ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', 'seasons', sywat_page_url( 'manage_tournaments' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-season">Manage Seasons</a>
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
                        <form method="get" action="<?php echo esc_url( sywat_page_url( 'register' ) ); ?>">
                            <div class="sywat-field-group">
                                <label for="sywat_selected_tournament">Active Tournament:</label>
                                <select id="sywat_selected_tournament" name="selected_tournament" onchange="this.form.submit()">
                                    <?php if ( empty( $ordered_tournaments ) ): ?>
                                        <option value="">No Tournaments Configured</option>
                                    <?php else: ?>
                                        <?php foreach ( $ordered_tournaments as $t_name => $t_label ): ?>
                                            <option value="<?php echo esc_attr( $t_name ); ?>" <?php selected( $selected_tournament, $t_name ); ?>>
                                                <?php echo esc_html( $t_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ( ! empty( $selected_tournament ) && isset( $tournaments[ $selected_tournament ] ) ): ?>
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
