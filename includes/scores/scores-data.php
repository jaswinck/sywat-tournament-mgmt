<?php
/**
 * Sub-Module: Scores Engine Data Access & Metric Engine
 * Path: includes/scores/scores-data.php
 * Description: Pure data access and metric aggregation engine for the Batch Score Entry application ([sywat_scores]). Resolves staff capabilities, filters tournaments to active-season and non-historical events (excluding archived historical_import records), executes indexed single-tournament wp_options queries, returns all checked-in tournament registrations with resolved line indices for zero-reload client-side line filtering, and computes O(R) line score completion counters.
 * Compliance: Pure data layer — zero HTML rendering, zero presentation formatting strings, zero output at include time, indexed single-tournament option queries, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves user capabilities, active-season/non-historical tournament scope, selected
 * tournament/line state, and active line lock flags for the Scores Engine.
 *
 * @return array{
 *     can_edit: bool,
 *     is_admin: bool,
 *     can_manage_tournaments: bool,
 *     can_register_shooters: bool,
 *     can_access_desk: bool,
 *     can_manage_seasons: bool,
 *     user_shop_id: string,
 *     tournaments: array,
 *     selected_tournament: string,
 *     selected_line_idx: string,
 *     search_query: string,
 *     lines: array,
 *     active_t_slug: string,
 *     locks_key: string,
 *     locked_lines: array,
 *     current_view_locked: bool,
 *     is_hub_enabled: bool
 * }
 */
if ( ! function_exists( 'sywat_scores_get_context' ) ) {
    function sywat_scores_get_context() {
        $user       = wp_get_current_user();
        $user_roles = is_object( $user ) && isset( $user->roles ) ? (array) $user->roles : array();

        $can_edit = function_exists( 'sywat_can_edit_scores' )
            ? sywat_can_edit_scores()
            : ( is_user_logged_in() && (bool) array_intersect(
                array(
                    'administrator',
                    'management',
                    'non-shooting-member',
                    'staff_shooter',
                    'sywat_shop_manager',
                    'sywat_shop_user',
                    'tfaa_tournament_director',
                    'tfaa_president',
                    'tfaa_webmaster',
                    'tfaa_admin',
                    'tfaa_field_governor',
                ),
                $user_roles
            ) );

        $admin_roles            = array( 'administrator', 'management', 'tfaa_webmaster', 'tfaa_admin', 'tfaa_president', 'tfaa_tournament_director', 'tfaa_field_governor', 'sywat_shop_manager' );
        $is_admin               = is_user_logged_in() && (bool) array_intersect( $admin_roles, $user_roles );
        $can_manage_tournaments = function_exists( 'sywat_can_manage_tournaments' ) && sywat_can_manage_tournaments();
        $can_register_shooters  = function_exists( 'sywat_can_register_shooters' )
            ? sywat_can_register_shooters()
            : ( function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk() );
        $can_access_desk        = function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk();
        $can_manage_seasons     = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
        $user_shop_id           = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';

        $selected_tournament = isset( $_POST['selected_tournament'] )
            ? sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) )
            : ( isset( $_GET['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_GET['selected_tournament'] ) ) : '' );

        $selected_line_idx = isset( $_POST['selected_line_idx'] )
            ? sanitize_text_field( wp_unslash( $_POST['selected_line_idx'] ) )
            : ( isset( $_GET['selected_line_idx'] ) ? sanitize_text_field( wp_unslash( $_GET['selected_line_idx'] ) ) : 'all' );

        $search_query = isset( $_POST['search_query'] )
            ? sanitize_text_field( wp_unslash( $_POST['search_query'] ) )
            : ( isset( $_GET['search_query'] ) ? sanitize_text_field( wp_unslash( $_GET['search_query'] ) ) : '' );

        $saved_user_t = ( is_object( $user ) && ! empty( $user->ID ) )
            ? trim( (string) get_user_meta( $user->ID, 'sywat_desk_selected_tournament', true ) )
            : '';

        // Identify active season keys so archived seasons & historical_import records are excluded from the dropdown
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

        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( empty( $all_tournaments ) || ! is_array( $all_tournaments ) ) {
            $single_name     = get_option( 'sywat_current_tournament', 'CCR SYWAT Qualifier' );
            $all_tournaments = array( $single_name => get_option( 'sywat_line_config', array() ) );
        }

        $raw_tournaments = array();
        foreach ( $all_tournaments as $t_name => $t_data ) {
            $is_explicitly_requested = ( $selected_tournament !== '' && strcasecmp( $t_name, $selected_tournament ) === 0 );
            if ( is_array( $t_data ) && ! empty( $t_data['historical_import'] ) && ! $is_explicitly_requested ) {
                continue;
            }

            $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
            if ( ! $is_admin && $t_shop !== $user_shop_id ) {
                continue;
            }

            $is_saved_preference = ( $saved_user_t !== '' && strcasecmp( $t_name, $saved_user_t ) === 0 );
            if ( ! $is_explicitly_requested && ! $is_saved_preference && ! empty( $active_season_keys ) ) {
                $t_season = '';
                if ( is_array( $t_data ) ) {
                    $t_season = ! empty( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( ! empty( $t_data['season'] ) ? $t_data['season'] : '' );
                }
                if ( ! empty( $t_season ) && ! in_array( strtolower( trim( (string) $t_season ) ), $active_season_keys, true ) ) {
                    continue;
                }
            }

            $raw_tournaments[ $t_name ] = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : $t_data;
        }

        // Fallback to non-historical shop-scoped events if the active season has no events configured yet
        if ( empty( $raw_tournaments ) ) {
            foreach ( $all_tournaments as $t_name => $t_data ) {
                if ( is_array( $t_data ) && ! empty( $t_data['historical_import'] ) ) {
                    continue;
                }
                $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
                if ( $is_admin || $t_shop === $user_shop_id ) {
                    $raw_tournaments[ $t_name ] = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : $t_data;
                }
            }
        }

        // Resolve sticky user meta preference across Register / Check-In / Scores
        if ( empty( $selected_tournament ) && $saved_user_t !== '' ) {
            if ( isset( $raw_tournaments[ $saved_user_t ] ) ) {
                $selected_tournament = $saved_user_t;
            } else {
                foreach ( array_keys( $raw_tournaments ) as $cand_t ) {
                    if ( strcasecmp( $cand_t, $saved_user_t ) === 0 ) {
                        $selected_tournament = $cand_t;
                        break;
                    }
                }
            }
        }

        // Secondary fallback to global active tournament
        if ( empty( $selected_tournament ) ) {
            $global_curr = trim( (string) get_option( 'sywat_current_tournament', '' ) );
            if ( $global_curr !== '' ) {
                if ( isset( $raw_tournaments[ $global_curr ] ) ) {
                    $selected_tournament = $global_curr;
                } else {
                    foreach ( array_keys( $raw_tournaments ) as $cand_t ) {
                        if ( strcasecmp( $cand_t, $global_curr ) === 0 ) {
                            $selected_tournament = $cand_t;
                            break;
                        }
                    }
                }
            }
        }

        uksort( $raw_tournaments, 'strcasecmp' );

        if ( empty( $selected_tournament ) && ! empty( $raw_tournaments ) ) {
            reset( $raw_tournaments );
            $selected_tournament = key( $raw_tournaments );
        }

        if ( is_object( $user ) && ! empty( $user->ID ) && ! empty( $selected_tournament ) && $selected_tournament !== $saved_user_t ) {
            update_user_meta( $user->ID, 'sywat_desk_selected_tournament', $selected_tournament );
        }

        // Pin selected tournament to index #0, followed by remaining events sorted A–Z
        $tournaments = array();
        if ( ! empty( $selected_tournament ) && isset( $raw_tournaments[ $selected_tournament ] ) ) {
            $tournaments[ $selected_tournament ] = $raw_tournaments[ $selected_tournament ];
        }
        foreach ( $raw_tournaments as $t_name => $t_lines ) {
            if ( $t_name !== $selected_tournament ) {
                $tournaments[ $t_name ] = $t_lines;
            }
        }

        $lines         = isset( $tournaments[ $selected_tournament ] ) ? $tournaments[ $selected_tournament ] : array();
        $active_t_slug = sanitize_title( $selected_tournament );

        $locks_key = 'sywat_locks_' . $active_t_slug;
        wp_cache_delete( $locks_key, 'options' );
        $locked_lines = get_option( $locks_key, array() );
        if ( ! is_array( $locked_lines ) ) {
            $locked_lines = array();
        }
        $current_view_locked = ( $selected_line_idx !== 'all' && ! empty( $locked_lines[ $selected_line_idx ] ) );

        $active_hubs = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_hubs ) ) {
            $active_hubs = array();
        }
        $is_hub_enabled = in_array( $selected_tournament, $active_hubs, true );

        return array(
            'can_edit'               => $can_edit,
            'is_admin'               => $is_admin,
            'can_manage_tournaments' => $can_manage_tournaments,
            'can_register_shooters'  => $can_register_shooters,
            'can_access_desk'        => $can_access_desk,
            'can_manage_seasons'     => $can_manage_seasons,
            'user_shop_id'           => $user_shop_id,
            'tournaments'            => $tournaments,
            'selected_tournament'    => $selected_tournament,
            'selected_line_idx'      => $selected_line_idx,
            'search_query'           => $search_query,
            'lines'                  => $lines,
            'active_t_slug'          => $active_t_slug,
            'locks_key'              => $locks_key,
            'locked_lines'           => $locked_lines,
            'current_view_locked'    => $current_view_locked,
            'is_hub_enabled'         => $is_hub_enabled,
        );
    }
}

/**
 * Queries checked-in archer records and master score map for the active tournament
 * using an indexed single-tournament option prefix, resolves each archer's canonical
 * line index across all lines for zero-reload client-side line filtering, and computes
 * O(R) line score completion metrics across all configured shooting lines.
 *
 * @param string $selected_tournament Active tournament name.
 * @param string $selected_line_idx   Active shooting line index or 'all'.
 * @param array  $lines               Configured shooting lines for the active tournament.
 * @param array  $locked_lines        Map of locked line indices.
 * @return array{
 *     scores_map: array,
 *     tourn_registrations: array,
 *     tracker_lines: array
 * }
 */
if ( ! function_exists( 'sywat_scores_get_records_and_tracker' ) ) {
    function sywat_scores_get_records_and_tracker( $selected_tournament, $selected_line_idx, $lines, $locked_lines ) {
        global $wpdb;

        $active_t_slug = sanitize_title( $selected_tournament );
        $registrations = array();

        if ( $active_t_slug !== '' ) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( 'sywat_t_' . $active_t_slug . '_' ) . '%'
                )
            );

            if ( is_array( $results ) ) {
                foreach ( $results as $row ) {
                    $reg_data = maybe_unserialize( $row->option_value );
                    if ( is_array( $reg_data ) && isset( $reg_data['id'] ) ) {
                        $registrations[] = $reg_data;
                    }
                }
            }
        }

        $scores_map = get_option( 'sywat_scores_' . $active_t_slug, array() );
        if ( ! is_array( $scores_map ) ) {
            $scores_map = array();
        }

        // Pre-index line metadata and counters in a single O(R) pass
        $ordered_lines     = array();
        $line_stats        = array();
        $line_title_to_idx = array();
        $has_line_resolver = function_exists( 'sywat_resolve_tournament_line_label' );

        if ( ! empty( $lines ) && is_array( $lines ) ) {
            foreach ( $lines as $idx => $line ) {
                $idx_str    = (string) $idx;
                $raw_title  = ( is_array( $line ) && ! empty( $line['name'] ) )
                    ? (string) $line['name']
                    : trim( ( is_array( $line ) && isset( $line['day'] ) ? $line['day'] : '' ) . ' ' . ( is_array( $line ) && isset( $line['time'] ) ? $line['time'] : '' ) );
                $weight     = 50;

                if ( stripos( $raw_title, 'Sat' ) !== false ) {
                    if ( stripos( $raw_title, '10' ) !== false ) {
                        $weight = 10;
                    } elseif ( stripos( $raw_title, '2' ) !== false ) {
                        $weight = 20;
                    } else {
                        $weight = 25;
                    }
                } elseif ( stripos( $raw_title, 'Sun' ) !== false ) {
                    if ( stripos( $raw_title, '10' ) !== false ) {
                        $weight = 30;
                    } elseif ( stripos( $raw_title, '2' ) !== false ) {
                        $weight = 40;
                    } else {
                        $weight = 45;
                    }
                }

                $ordered_lines[] = array(
                    'idx'    => $idx,
                    'line'   => $line,
                    'weight' => $weight,
                );

                $line_stats[ $idx_str ] = array(
                    'total'   => 0,
                    'entered' => 0,
                );

                $trim_title = trim( $raw_title );
                if ( $trim_title !== '' && ! isset( $line_title_to_idx[ $trim_title ] ) ) {
                    $line_title_to_idx[ $trim_title ] = $idx_str;
                }
                if ( $has_line_resolver ) {
                    $resolved_label = trim( (string) sywat_resolve_tournament_line_label( $line, $idx ) );
                    if ( $resolved_label !== '' && ! isset( $line_title_to_idx[ $resolved_label ] ) ) {
                        $line_title_to_idx[ $resolved_label ] = $idx_str;
                    }
                }
            }

            usort( $ordered_lines, function( $a, $b ) {
                if ( $a['weight'] !== $b['weight'] ) {
                    return $a['weight'] <=> $b['weight'];
                }
                return $a['idx'] <=> $b['idx'];
            } );
        }

        $tourn_registrations = array();
        foreach ( $registrations as $reg ) {
            if ( ! is_array( $reg ) || ! isset( $reg['tournament'] ) ) {
                continue;
            }
            if ( strcasecmp( trim( (string) $reg['tournament'] ), trim( (string) $selected_tournament ) ) !== 0 ) {
                continue;
            }
            if ( empty( $reg['checked_in'] ) ) {
                continue;
            }

            // Resolve canonical line index for both O(R) tracker counts and client-side line filtering
            $resolved_idx = '';
            if ( isset( $reg['line_index'] ) ) {
                $raw_l_idx = (string) $reg['line_index'];
                if ( isset( $line_stats[ $raw_l_idx ] ) ) {
                    $resolved_idx = $raw_l_idx;
                }
            }
            if ( $resolved_idx === '' && isset( $reg['line_name'] ) ) {
                $raw_l_name = trim( (string) $reg['line_name'] );
                if ( $raw_l_name !== '' && isset( $line_title_to_idx[ $raw_l_name ] ) ) {
                    $resolved_idx = $line_title_to_idx[ $raw_l_name ];
                }
            }
            if ( $resolved_idx === '' ) {
                $resolved_idx = isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0';
            }

            $reg['resolved_line_idx'] = $resolved_idx;
            $tourn_registrations[]    = $reg;

            if ( isset( $line_stats[ $resolved_idx ] ) ) {
                $line_stats[ $resolved_idx ]['total']++;

                $rid     = isset( $reg['id'] ) ? $reg['id'] : '';
                $map_s   = isset( $scores_map[ $rid ]['score'] ) ? intval( $scores_map[ $rid ]['score'] ) : ( isset( $reg['score'] ) ? intval( $reg['score'] ) : 0 );
                $map_x   = isset( $scores_map[ $rid ]['xs'] ) ? intval( $scores_map[ $rid ]['xs'] ) : ( isset( $reg['x_count'] ) ? intval( $reg['x_count'] ) : 0 );
                $map_inc = isset( $scores_map[ $rid ]['is_inc'] ) ? ! empty( $scores_map[ $rid ]['is_inc'] ) : ! empty( $reg['is_inc'] );

                if ( $map_s > 0 || $map_x > 0 || $map_inc ) {
                    $line_stats[ $resolved_idx ]['entered']++;
                }
            }
        }

        $tracker_lines = array();
        foreach ( $ordered_lines as $item ) {
            $idx       = $item['idx'];
            $idx_str   = (string) $idx;
            $total     = isset( $line_stats[ $idx_str ]['total'] ) ? $line_stats[ $idx_str ]['total'] : 0;
            $entered   = isset( $line_stats[ $idx_str ]['entered'] ) ? $line_stats[ $idx_str ]['entered'] : 0;
            $is_locked = ! empty( $locked_lines[ $idx ] );

            $tracker_lines[] = array(
                'idx'         => $idx,
                'line'        => $item['line'],
                'total'       => $total,
                'entered'     => $entered,
                'pending'     => max( 0, $total - $entered ),
                'is_locked'   => $is_locked,
                'is_complete' => ( $total > 0 && $entered === $total ),
            );
        }

        return array(
            'scores_map'          => $scores_map,
            'tourn_registrations' => $tourn_registrations,
            'tracker_lines'       => $tracker_lines,
        );
    }
}
