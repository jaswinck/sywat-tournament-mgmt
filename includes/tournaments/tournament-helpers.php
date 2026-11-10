<?php
/**
 * Sub-Module: Tournament Configuration Helpers & Permission Engines
 * Path: includes/tournaments/tournament-helpers.php
 * Author: SYWAT Archery
 * Version: 6.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Central Permission Checker for Season/Shop Administration
if ( ! function_exists( 'sywat_can_manage_seasons' ) ) {
    function sywat_can_manage_seasons() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        $allowed_roles = array( 'administrator', 'tfaa_webmaster', 'tfaa_admin' );
        return (bool) array_intersect( $allowed_roles, (array) $user->roles );
    }
}

if ( ! function_exists( 'sywat_normalize_dist' ) ) {
    function sywat_normalize_dist( $dist_str ) {
        return ( strcasecmp( trim( $dist_str ), 'cub' ) === 0 || strcasecmp( trim( $dist_str ), '10yd' ) === 0 || strcasecmp( trim( $dist_str ), '10 yards' ) === 0 ) ? 'Cub' : '20yd';
    }
}

if ( ! function_exists( 'sywat_get_line_max_cap' ) ) {
    function sywat_get_line_max_cap( $line_data, $dist ) {
        if ( $dist === 'Cub' ) {
            return isset( $line_data['cap_cub'] ) ? intval( $line_data['cap_cub'] ) : 5;
        }
        return isset( $line_data['cap_20yd'] ) ? intval( $line_data['cap_20yd'] ) : 20;
    }
}

/**
 * Counts registered archers for a specific line and target distance.
 * Explicitly excludes Waitlisted and Cancelled archers from physical line occupancy counts.
 */
if ( ! function_exists( 'sywat_count_registered' ) ) {
    function sywat_count_registered( $t_name, $l_idx, $dist, $registrations ) {
        $count = 0;
        $norm_target_dist = sywat_normalize_dist( $dist );

        foreach ( $registrations as $reg ) {
            if ( ! isset( $reg['tournament'] ) || strcasecmp( trim( $reg['tournament'] ), trim( $t_name ) ) !== 0 ) {
                continue;
            }

            // Exclude Waitlisted and Cancelled archers from active target butt occupancy
            $status = isset( $reg['status'] ) ? trim( $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Waitlist' ) === 0 || strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            $reg_dist = isset( $reg['distance'] ) ? sywat_normalize_dist( $reg['distance'] ) : '20yd';
            if ( $reg_dist !== $norm_target_dist ) {
                continue;
            }

            // Match by numerical array index (e.g. 0 === 0)
            $match_idx = isset( $reg['line_index'] ) && (string)$reg['line_index'] === (string)$l_idx;

            // Fallback match: check if line_name or line_index contains the line text name
            $match_name = false;
            if ( isset( $reg['line_name'] ) && is_string( $l_idx ) && strcasecmp( trim( $reg['line_name'] ), trim( $l_idx ) ) === 0 ) {
                $match_name = true;
            }
            if ( isset( $reg['line_index'] ) && is_string( $reg['line_index'] ) && ! is_numeric( $reg['line_index'] ) ) {
                if ( (string)$reg['line_index'] === (string)$l_idx ) {
                    $match_name = true;
                }
            }

            if ( $match_idx || $match_name ) {
                $count++;
            }
        }
        return $count;
    }
}

if ( ! function_exists( 'sywat_get_registrations' ) ) {
    function sywat_get_registrations( $t_name = '' ) {
        if ( ! empty( $t_name ) ) {
            $t_slug = sanitize_title( $t_name );
            $regs   = get_option( 'sywat_registrations_' . $t_slug, array() );
            if ( is_array( $regs ) && ! empty( $regs ) ) {
                return array_values( $regs );
            }
        }

        $global_regs = get_option( 'sywat_registrations', array() );
        if ( is_array( $global_regs ) && ! empty( $global_regs ) ) {
            if ( empty( $t_name ) ) {
                return array_values( $global_regs );
            }
            return array_values( array_filter( $global_regs, function( $r ) use ( $t_name ) {
                return isset( $r['tournament'] ) && strcasecmp( trim( $r['tournament'] ), trim( $t_name ) ) === 0;
            } ) );
        }

        return array();
    }
}

/**
 * Gets total active registered archers count for a tournament.
 * Excludes Waitlisted and Cancelled archers.
 */
if ( ! function_exists( 'sywat_get_tournament_count' ) ) {
    function sywat_get_tournament_count( $t_name, $registrations ) {
        $count = 0;
        foreach ( $registrations as $reg ) {
            if ( isset( $reg['tournament'] ) && strcasecmp( trim( $reg['tournament'] ), trim( $t_name ) ) === 0 ) {
                $status = isset( $reg['status'] ) ? trim( $reg['status'] ) : '';
                if ( strcasecmp( $status, 'Waitlist' ) === 0 || strcasecmp( $status, 'Cancelled' ) === 0 ) {
                    continue;
                }
                $count++;
            }
        }
        return $count;
    }
}

if ( ! function_exists( 'sywat_format_tournament_dates' ) ) {
    function sywat_format_tournament_dates( $start_date, $end_date ) {
        if ( empty( $start_date ) && empty( $end_date ) ) return '';
        if ( empty( $end_date ) || $start_date === $end_date ) {
            return date( 'F j, Y', strtotime( $start_date ) );
        }

        $s_time = strtotime( $start_date );
        $e_time = strtotime( $end_date );

        if ( date( 'Y-m', $s_time ) === date( 'Y-m', $e_time ) ) {
            return date( 'F j', $s_time ) . ' – ' . date( 'j, Y', $e_time );
        } elseif ( date( 'Y', $s_time ) === date( 'Y', $e_time ) ) {
            return date( 'M j', $s_time ) . ' – ' . date( 'M j, Y', $e_time );
        }
        return date( 'M j, Y', $s_time ) . ' – ' . date( 'M j, Y', $e_time );
    }
}

if ( ! function_exists( 'sywat_get_tournament_lines' ) ) {
    function sywat_get_tournament_lines( $t_data ) {
        if ( ! is_array( $t_data ) ) return array();
        if ( isset( $t_data['lines'] ) && is_array( $t_data['lines'] ) ) {
            return $t_data['lines'];
        }
        return $t_data;
    }
}

if ( ! function_exists( 'sywat_get_tournament_shop_id' ) ) {
    function sywat_get_tournament_shop_id( $t_data ) {
        if ( is_array( $t_data ) && isset( $t_data['shop_id'] ) && ! empty( $t_data['shop_id'] ) ) {
            return $t_data['shop_id'];
        }
        return 'shop_ccr';
    }
}

if ( ! function_exists( 'sywat_get_tournament_season_slug' ) ) {
    function sywat_get_tournament_season_slug( $t_data ) {
        if ( is_array( $t_data ) && isset( $t_data['season_slug'] ) && ! empty( $t_data['season_slug'] ) ) {
            return $t_data['season_slug'];
        }
        $active_season = function_exists('sywat_get_active_season') ? sywat_get_active_season() : array();
        return isset( $active_season['slug'] ) ? $active_season['slug'] : 'indoor-2026-2027';
    }
}

// Check if an archer is already registered on a specific line
if ( ! function_exists( 'sywat_is_archer_on_line' ) ) {
    function sywat_is_archer_on_line( $t_name, $l_idx, $archer_name, $registrations ) {
        $norm_target_name = strtolower( trim( $archer_name ) );
        if ( empty( $norm_target_name ) ) return false;

        foreach ( $registrations as $reg ) {
            if ( ! isset( $reg['tournament'] ) || strcasecmp( trim( $reg['tournament'] ), trim( $t_name ) ) !== 0 ) {
                continue;
            }

            $reg_name = '';
            if ( ! empty( $reg['name'] ) ) {
                $reg_name = strtolower( trim( $reg['name'] ) );
            } elseif ( ! empty( $reg['archer_name'] ) ) {
                $reg_name = strtolower( trim( $reg['archer_name'] ) );
            }

            if ( $reg_name === $norm_target_name ) {
                $match_idx = isset( $reg['line_index'] ) && (string)$reg['line_index'] === (string)$l_idx;
                $match_name = false;
                if ( isset( $reg['line_name'] ) && is_string( $l_idx ) && strcasecmp( trim( $reg['line_name'] ), trim( $l_idx ) ) === 0 ) {
                    $match_name = true;
                }
                if ( isset( $reg['line_index'] ) && is_string( $reg['line_index'] ) && ! is_numeric( $reg['line_index'] ) ) {
                    if ( (string)$reg['line_index'] === (string)$l_idx ) {
                        $match_name = true;
                    }
                }

                if ( $match_idx || $match_name ) {
                    return true;
                }
            }
        }
        return false;
    }
}

// Partial search lookup against cached TFAA roster
if ( ! function_exists( 'sywat_search_tfaa_shooters' ) ) {
    function sywat_search_tfaa_shooters( $query, $limit = 10 ) {
        $clean_query = strtolower( trim( $query ) );
        if ( strlen( $clean_query ) < 2 ) return array();

        $cache    = get_option( 'sywat_tfaa_cache', array() );
        $shooters = isset( $cache['shooters'] ) && is_array( $cache['shooters'] ) 
            ? $cache['shooters'] 
            : ( is_array( $cache ) ? $cache : array() );

        $matches = array();

        foreach ( $shooters as $s ) {
            $name = isset( $s['name'] ) ? $s['name'] : ( isset( $s['full_name'] ) ? $s['full_name'] : '' );
            if ( empty( $name ) ) continue;

            if ( strpos( strtolower( $name ), $clean_query ) !== false ) {
                $matches[] = array(
                    'name'     => $name,
                    'division' => isset( $s['division'] ) ? $s['division'] : '',
                    'gender'   => isset( $s['gender'] ) ? $s['gender'] : '',
                    'style'    => isset( $s['style'] ) ? $s['style'] : '',
                );

                if ( count( $matches ) >= $limit ) break;
            }
        }

        return $matches;
    }
}

/**
 * Master Data Retrieval Engine for Admin Portal & Views
 */
if ( ! function_exists( 'sywat_get_master_portal_data' ) ) {
    function sywat_get_master_portal_data() {
        global $wpdb;

        $can_manage_seasons = sywat_can_manage_seasons();
        $can_access_desk    = function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk();
        $can_edit_scores    = function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores();

        $user_shop_id       = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';
        $all_shops          = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
        $seasons            = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        $active_tournaments = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_tournaments ) ) $active_tournaments = array();

        // Load Registrations from DB with Master Roster Fallback
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

        // Fallback: Query master registration options if single option keys return empty
        if ( empty( $registrations ) && function_exists( 'sywat_get_registrations' ) ) {
            $registrations = sywat_get_registrations();
        }

        // Filter Tournaments by Shop Scoping, Season Filtering & Collect Unique Shops
        $scoped_tournaments = array();
        $filter_shops       = array();

        $selected_season = isset( $_REQUEST['filter_season'] ) ? sanitize_text_field( $_REQUEST['filter_season'] ) : '';

        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop   = sywat_get_tournament_shop_id( $t_data );
            $t_season = sywat_get_tournament_season_slug( $t_data );

            // Filter out tournaments that do not match the selected season parameter
            if ( ! empty( $selected_season ) && $t_season !== $selected_season ) {
                continue;
            }

            if ( $can_manage_seasons || $t_shop === $user_shop_id ) {
                $scoped_tournaments[$t_name] = $t_data;
                if ( isset( $all_shops[$t_shop]['name'] ) ) {
                    $filter_shops[$t_shop] = $all_shops[$t_shop]['name'];
                }
            }
        }
        asort( $filter_shops );

        // Sort tournaments chronologically by Start Date (Soonest first)
        uasort( $scoped_tournaments, function( $a, $b ) {
            $a_date = ( is_array( $a ) && ! empty( $a['start_date'] ) ) ? $a['start_date'] : '';
            $b_date = ( is_array( $b ) && ! empty( $b['start_date'] ) ) ? $b['start_date'] : '';
            if ( $a_date === $b_date ) return 0;
            return ( $a_date < $b_date ) ? -1 : 1;
        });

        $tfaa_cache = get_option( 'sywat_tfaa_cache', array() );
        $tfaa_count = isset( $tfaa_cache['count'] ) ? $tfaa_cache['count'] : 0;

        return array(
            'can_manage_seasons' => $can_manage_seasons,
            'can_access_desk'    => $can_access_desk,
            'can_edit_scores'    => $can_edit_scores,
            'user_shop_id'       => $user_shop_id,
            'all_shops'          => $all_shops,
            'seasons'            => $seasons,
            'tournaments'        => $tournaments,
            'active_tournaments' => $active_tournaments,
            'registrations'      => $registrations,
            'scoped_tournaments' => $scoped_tournaments,
            'filter_shops'       => $filter_shops,
            'tfaa_count'         => $tfaa_count,
        );
    }
}
