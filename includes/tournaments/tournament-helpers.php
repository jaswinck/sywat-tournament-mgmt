<?php
/**
 * Sub-Module: Tournament Configuration Helpers & Permission Engines
 * Path: includes/tournaments/tournament-helpers.php
 * Author: SYWAT Archery
 * Version: 2.5.0
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

if ( ! function_exists( 'sywat_count_registered' ) ) {
    function sywat_count_registered( $t_name, $l_idx, $dist, $registrations ) {
        $count = 0;
        $norm_target_dist = sywat_normalize_dist( $dist );

        foreach ( $registrations as $reg ) {
            if ( ! isset( $reg['tournament'] ) || strcasecmp( trim( $reg['tournament'] ), trim( $t_name ) ) !== 0 ) {
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

if ( ! function_exists( 'sywat_get_tournament_count' ) ) {
    function sywat_get_tournament_count( $t_name, $registrations ) {
        $count = 0;
        foreach ( $registrations as $reg ) {
            if ( isset( $reg['tournament'] ) && strcasecmp( trim( $reg['tournament'] ), trim( $t_name ) ) === 0 ) {
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
