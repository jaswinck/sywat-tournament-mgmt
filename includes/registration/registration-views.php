<?php
/**
 * Sub-Module: Registration Engine Views Renderer
 * Path: includes/registration/registration-views.php
 * Description: Renders the [sywat_register] Registration Desk portal with role-gated capability checks and desk tabs, persists sticky tournament selection across desk portals, computes single-pass O(R) per-line capacity and waitlist metrics ($line_stats) after POST action execution so Current Line Slot Usage and the multi-line intake matrix stay strictly synchronized without view-layer option queries, renders the header Bulk Upload trigger and top-level Import Modal (#sywat_import_modal), dispatches waitlist modal POST actions, and renders the dedicated Waitlist Management Modal (#sywat_waitlist_modal) on demand.
 * Compliance: Pure view/orchestration layer — zero output at include time, zero inline styles, zero inline JS event handlers (delegated data-sywat-action attributes), single-pass O(R) line stats computation, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper: Filter scoped tournaments to active season(s) and build an ordered option map
 * with the currently selected tournament at the top and all remaining tournaments sorted A–Z.
 *
 * Priority order when no explicit tournament is requested:
 * 1. User's last-selected tournament (sywat_desk_selected_tournament user meta)
 * 2. Active Volunteer Hub tournament (user's shop first, then any shop) or sywat_current_tournament
 * 3. Nearest upcoming tournament belonging to the user's assigned shop
 * 4. Nearest upcoming tournament overall
 * 5. First tournament alphabetically (A–Z)
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

        $uid          = get_current_user_id();
        $saved_user_t = $uid ? trim( (string) get_user_meta( $uid, 'sywat_desk_selected_tournament', true ) ) : '';

        // Filter pool to active season(s), always preserving an explicitly requested or saved tournament if valid
        $pool = array();
        foreach ( $scoped_tournaments as $t_name => $t_data ) {
            if ( ! empty( $requested_tourn ) && strcasecmp( $t_name, $requested_tourn ) === 0 ) {
                $pool[ $t_name ] = $t_data;
                continue;
            }
            if ( $saved_user_t !== '' && strcasecmp( $t_name, $saved_user_t ) === 0 ) {
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

        // 1. Resolve explicitly requested tournament using exact or case-insensitive key match
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

        // 2. Sticky User Preference: Restore last tournament selected across Register / Check-In / Scores
        if ( empty( $selected_tournament ) && $saved_user_t !== '' ) {
            if ( isset( $pool[ $saved_user_t ] ) ) {
                $selected_tournament = $saved_user_t;
            } else {
                foreach ( $sorted_keys as $k ) {
                    if ( strcasecmp( $k, $saved_user_t ) === 0 ) {
                        $selected_tournament = $k;
                        break;
                    }
                }
            }
        }

        // 3. Active Volunteer Hub tournament or global active tournament
        if ( empty( $selected_tournament ) ) {
            $active_hubs = get_option( 'sywat_active_tournaments', array() );
            if ( is_array( $active_hubs ) && ! empty( $active_hubs ) ) {
                $hub_any = '';
                foreach ( $sorted_keys as $k ) {
                    if ( in_array( $k, $active_hubs, true ) ) {
                        $t_shop = function_exists( 'sywat_get_tournament_shop_id' )
                            ? (string) sywat_get_tournament_shop_id( $pool[ $k ] )
                            : ( isset( $pool[ $k ]['shop_id'] ) ? (string) $pool[ $k ]['shop_id'] : '' );
                        if ( ! empty( $user_shop_id ) && $t_shop === (string) $user_shop_id ) {
                            $selected_tournament = $k;
                            break;
                        }
                        if ( $hub_any === '' ) {
                            $hub_any = $k;
                        }
                    }
                }
                if ( empty( $selected_tournament ) && $hub_any !== '' ) {
                    $selected_tournament = $hub_any;
                }
            }
        }

        if ( empty( $selected_tournament ) ) {
            $global_curr = trim( (string) get_option( 'sywat_current_tournament', '' ) );
            if ( $global_curr !== '' ) {
                if ( isset( $pool[ $global_curr ] ) ) {
                    $selected_tournament = $global_curr;
                } else {
                    foreach ( $sorted_keys as $k ) {
                        if ( strcasecmp( $k, $global_curr ) === 0 ) {
                            $selected_tournament = $k;
                            break;
                        }
                    }
                }
            }
        }

        // 4. Nearest upcoming tournament (user's shop first, then overall), else first A–Z
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
 * Render Full Registration Desk Page (Shortcode Handler: [sywat_register]).
 *
 * @return string Rendered HTML markup output for the registration portal.
 */
if ( ! function_exists( 'sywat_render_registration_desk' ) ) {
    function sywat_render_registration_desk() {
        $can_register = function_exists( 'sywat_can_register_shooters' )
            ? sywat_can_register_shooters()
            : ( function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk() );

        if ( ! $can_register ) {
            return '<div class="sywat-alert sywat-alert-danger">Access denied. Registration intake access required.</div>';
        }

        ob_start();
        global $wpdb;

        $can_manage_tournaments = function_exists( 'sywat_can_manage_tournaments' ) && sywat_can_manage_tournaments();
        $can_manage_seasons     = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
        $can_access_desk        = function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk();
        $can_edit_scores        = function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores();

        $user_shop_id           = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) {
            $tournaments = array();
        }

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
        } elseif ( isset( $_REQUEST['waitlist_tournament'] ) ) {
            $requested_tourn = sanitize_text_field( wp_unslash( $_REQUEST['waitlist_tournament'] ) );
        } elseif ( isset( $_GET['view_tournament'] ) ) {
            $requested_tourn = sanitize_text_field( wp_unslash( $_GET['view_tournament'] ) );
        }

        $dropdown_data       = sywat_registration_build_tournament_dropdown( $scoped_tournaments, $requested_tourn, $user_shop_id );
        $ordered_tournaments = $dropdown_data['ordered'];
        $selected_tournament = $dropdown_data['selected'];

        // Persist active selection to user meta so Check-In and Enter Scores stay synchronized
        $uid = get_current_user_id();
        if ( $uid && ! empty( $selected_tournament ) ) {
            update_user_meta( $uid, 'sywat_desk_selected_tournament', $selected_tournament );
        }

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
        $notice                 = '';
        $import_modal_auto_open = false;

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_action'] ) ) {
            $nonce_val      = isset( $_POST['sywat_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ) : '';
            $is_valid_nonce = ! empty( $nonce_val ) && wp_verify_nonce( $nonce_val, 'sywat_desk_action' );
            if ( $is_valid_nonce ) {
                $action = sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) );
                if ( function_exists( 'sywat_waitlist_is_supported_action' ) && sywat_waitlist_is_supported_action( $action ) && function_exists( 'sywat_waitlist_dispatch_post_action' ) ) {
                    $notice = sywat_waitlist_dispatch_post_action( $tournaments, $registrations );
                } elseif ( $action === 'register_shooter' && function_exists( 'sywat_handle_register_shooter' ) ) {
                    $notice = sywat_handle_register_shooter( $tournaments, $registrations );
                } elseif ( $action === 'import_shooters' && function_exists( 'sywat_handle_import_shooters' ) ) {
                    $import_tourn    = isset( $_POST['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) ) : $selected_tournament;
                    $target_line_idx = isset( $_POST['target_line_idx'] ) ? sanitize_text_field( wp_unslash( $_POST['target_line_idx'] ) ) : '0';
                    $notice          = sywat_handle_import_shooters( $import_tourn, $target_line_idx, $tournaments, $registrations );
                    if ( strpos( $notice, 'sywat-alert-danger' ) !== false ) {
                        $import_modal_auto_open = true;
                    }
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

        // Single-Pass O(R) Line Capacity & Waitlist Aggregation (post-action so counts reflect fresh mutations)
        $line_stats = array();
        if ( ! empty( $available_lines ) && is_array( $available_lines ) ) {
            $closed_lines_raw = ( $active_t_slug !== '' ) ? get_option( 'sywat_closed_lines_' . $active_t_slug, array() ) : array();
            $closed_map       = is_array( $closed_lines_raw )
                ? array_flip( array_map( 'trim', array_map( 'strval', $closed_lines_raw ) ) )
                : array();

            $has_line_resolver = function_exists( 'sywat_resolve_tournament_line_label' );
            $has_cap_fn        = function_exists( 'sywat_get_line_max_cap' );
            $has_norm_dist     = function_exists( 'sywat_normalize_dist' );
            $line_name_to_idx  = array();

            foreach ( $available_lines as $idx => $line ) {
                $idx_str       = (string) $idx;
                $label         = $has_line_resolver
                    ? sywat_resolve_tournament_line_label( $line, $idx )
                    : ( is_array( $line ) && ! empty( $line['name'] ) ? trim( (string) $line['name'] ) : 'Line ' . ( (int) $idx + 1 ) );
                $raw_line_name = ( is_array( $line ) && ! empty( $line['name'] ) ) ? trim( (string) $line['name'] ) : '';
                $m_20          = $has_cap_fn ? sywat_get_line_max_cap( $line, '20yd' ) : 20;
                $m_cub         = $has_cap_fn ? sywat_get_line_max_cap( $line, 'Cub' ) : 5;
                $is_closed     = isset( $closed_map[ $idx_str ] )
                              || ( $raw_line_name !== '' && isset( $closed_map[ $raw_line_name ] ) )
                              || isset( $closed_map[ $label ] );

                $line_stats[ $idx ] = array(
                    'idx'                   => $idx_str,
                    'label'                 => $label,
                    'raw_name'              => $raw_line_name,
                    'c_20'                  => 0,
                    'm_20'                  => $m_20,
                    'rem_20'                => $m_20,
                    'c_cub'                 => 0,
                    'm_cub'                 => $m_cub,
                    'rem_cub'               => $m_cub,
                    'waitlisted_20'         => 0,
                    'waitlisted_cub'        => 0,
                    'total_line_shooters'   => 0,
                    'total_line_waitlisted' => 0,
                    'is_closed'             => $is_closed,
                );

                $line_name_to_idx[ strtolower( $label ) ] = $idx;
                if ( $raw_line_name !== '' ) {
                    $line_name_to_idx[ strtolower( $raw_line_name ) ] = $idx;
                }
            }

            foreach ( $registrations as $r ) {
                if ( ! is_array( $r ) || ! isset( $r['tournament'] ) ) {
                    continue;
                }
                if ( strcasecmp( trim( (string) $r['tournament'] ), trim( $selected_tournament ) ) !== 0 ) {
                    continue;
                }

                $status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
                if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                    continue;
                }

                $matched_key = null;
                if ( isset( $r['line_index'] ) ) {
                    $r_idx_str = trim( (string) $r['line_index'] );
                    if ( isset( $line_stats[ $r_idx_str ] ) ) {
                        $matched_key = $r_idx_str;
                    } elseif ( is_numeric( $r_idx_str ) && isset( $line_stats[ (int) $r_idx_str ] ) ) {
                        $matched_key = (int) $r_idx_str;
                    } elseif ( $r_idx_str !== '' && isset( $line_name_to_idx[ strtolower( $r_idx_str ) ] ) ) {
                        $matched_key = $line_name_to_idx[ strtolower( $r_idx_str ) ];
                    }
                }

                if ( $matched_key === null && ! empty( $r['line_name'] ) ) {
                    $norm_r_name = strtolower( trim( (string) $r['line_name'] ) );
                    if ( isset( $line_name_to_idx[ $norm_r_name ] ) ) {
                        $matched_key = $line_name_to_idx[ $norm_r_name ];
                    }
                }

                if ( $matched_key === null ) {
                    continue;
                }

                $raw_dist = isset( $r['distance'] ) ? (string) $r['distance'] : '20yd';
                $dist     = $has_norm_dist
                    ? sywat_normalize_dist( $raw_dist )
                    : ( ( strcasecmp( trim( $raw_dist ), 'Cub' ) === 0 || strcasecmp( trim( $raw_dist ), '10yd' ) === 0 ) ? 'Cub' : '20yd' );

                if ( strcasecmp( $status, 'Waitlist' ) === 0 ) {
                    if ( $dist === 'Cub' ) {
                        $line_stats[ $matched_key ]['waitlisted_cub']++;
                    } else {
                        $line_stats[ $matched_key ]['waitlisted_20']++;
                    }
                } else {
                    if ( $dist === 'Cub' ) {
                        $line_stats[ $matched_key ]['c_cub']++;
                    } else {
                        $line_stats[ $matched_key ]['c_20']++;
                    }
                }
            }

            foreach ( $line_stats as $idx => &$st ) {
                $st['rem_20']                = $st['is_closed'] ? 0 : max( 0, $st['m_20'] - $st['c_20'] );
                $st['rem_cub']               = $st['is_closed'] ? 0 : max( 0, $st['m_cub'] - $st['c_cub'] );
                $st['total_line_shooters']   = $st['c_20'] + $st['c_cub'];
                $st['total_line_waitlisted'] = $st['waitlisted_20'] + $st['waitlisted_cub'];
            }
            unset( $st );
        }

        // Enqueue Client Logic & Localize Data
        if ( function_exists( 'sywat_render_registration_scripts' ) ) {
            sywat_render_registration_scripts();
        }

        $tab_query_args   = ! empty( $selected_tournament ) ? array( 'selected_tournament' => $selected_tournament ) : array();
        $has_active_tourn = ( ! empty( $selected_tournament ) && isset( $tournaments[ $selected_tournament ] ) );
        ?>

        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <!-- Master Operational Navigation Tabs (Card-Docked, Role-Gated) -->
            <div class="sywat-nav-tabs no-print">
                <?php if ( $can_manage_tournaments ) : ?>
                    <a href="<?php echo esc_url( sywat_page_url( 'manage_tournaments' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">Manage Tournaments</a>
                <?php endif; ?>
                <a href="<?php echo esc_url( add_query_arg( $tab_query_args, sywat_page_url( 'register' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-register active">Register Shooters</a>
                <?php if ( $can_access_desk ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( $tab_query_args, sywat_page_url( 'desk' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-checkin">Check In Archers</a>
                <?php endif; ?>
                <?php if ( $can_edit_scores ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( $tab_query_args, sywat_page_url( 'enter_scores' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-edit">Enter Scores</a>
                <?php endif; ?>
                <?php if ( $can_manage_seasons ) : ?>
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

                    <!-- Tournament Switcher & Bulk Upload Control Bar -->
                    <div class="sywat-header-inline-controls">
                        <form method="get" action="<?php echo esc_url( sywat_page_url( 'register' ) ); ?>">
                            <div class="sywat-field-group">
                                <label for="sywat_selected_tournament">Active Tournament:</label>
                                <select id="sywat_selected_tournament" name="selected_tournament" data-sywat-action="switch-tournament">
                                    <?php if ( empty( $ordered_tournaments ) ) : ?>
                                        <option value="">No Tournaments Configured</option>
                                    <?php else : ?>
                                        <?php foreach ( $ordered_tournaments as $t_name => $t_label ) : ?>
                                            <option value="<?php echo esc_attr( $t_name ); ?>" <?php selected( $selected_tournament, $t_name ); ?>>
                                                <?php echo esc_html( $t_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </form>

                        <?php if ( $has_active_tourn ) : ?>
                            <div class="sywat-field-group">
                                <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="open-import-modal">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <polyline points="17 8 12 3 7 8"></polyline>
                                        <line x1="12" y1="3" x2="12" y2="15"></line>
                                    </svg>
                                    Bulk Upload
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $has_active_tourn ) : ?>
                    <?php
                    if ( function_exists( 'sywat_render_registration_modal' ) ) {
                        sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations, $line_stats );
                    }
                    ?>
                <?php else : ?>
                    <p class="sywat-label-subtext">Please select or create a tournament to begin registering shooters.</p>
                <?php endif; ?>
            </div>

            <?php
            // Dedicated Bulk Spreadsheet Import Modal (#sywat_import_modal)
            if ( $has_active_tourn && function_exists( 'sywat_render_import_modal' ) ) {
                sywat_render_import_modal(
                    $selected_tournament,
                    $available_lines,
                    $tournaments[ $selected_tournament ],
                    $import_modal_auto_open
                );
            }

            // Dedicated Waitlist Management Modal (#sywat_waitlist_modal)
            if ( function_exists( 'sywat_maybe_render_waitlist_modal' ) ) {
                $register_clean_url = add_query_arg(
                    array( 'selected_tournament' => $selected_tournament ),
                    sywat_page_url( 'register' )
                );
                sywat_maybe_render_waitlist_modal(
                    $tournaments,
                    $registrations,
                    $selected_tournament,
                    $register_clean_url,
                    'register',
                    array(
                        'close_url' => $register_clean_url,
                    )
                );
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
if ( ! shortcode_exists( 'sywat_register' ) ) {
    add_shortcode( 'sywat_register', 'sywat_render_registration_desk' );
}
