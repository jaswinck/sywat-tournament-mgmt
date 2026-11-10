<?php
/**
 * Sub-Module: Tournament Configuration Helpers & Presentation Engines
 * Path: includes/tournaments/tournament-helpers.php
 * Author: SYWAT Archery
 * Version: 7.4.0
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

/**
 * Resolves standard line capacity defaults (20yd and Cub) for a given shop dataset array, shop ID, or shop name string.
 *
 * @param array|string $shop_data_or_id Shop dataset array, shop ID, or shop name string.
 * @return array Array containing 'cap_20yd' and 'cap_cub'.
 */
if ( ! function_exists( 'sywat_get_shop_capacity_defaults' ) ) {
    function sywat_get_shop_capacity_defaults( $shop_data_or_id ) {
        $cap_20yd  = 20;
        $cap_cub   = 5;
        $shop_data = array();

        if ( is_string( $shop_data_or_id ) && ! empty( $shop_data_or_id ) ) {
            $master_shops = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : get_option( 'sywat_shops', array() );
            if ( ! is_array( $master_shops ) ) {
                $master_shops = array();
            }

            if ( isset( $master_shops[$shop_data_or_id] ) && is_array( $master_shops[$shop_data_or_id] ) ) {
                $shop_data = $master_shops[$shop_data_or_id];
            } else {
                $search_str = strtolower( trim( $shop_data_or_id ) );
                foreach ( $master_shops as $s_id => $s_info ) {
                    if ( is_array( $s_info ) ) {
                        $s_name  = isset( $s_info['name'] ) ? strtolower( trim( $s_info['name'] ) ) : '';
                        $s_title = isset( $s_info['title'] ) ? strtolower( trim( $s_info['title'] ) ) : '';
                        $s_key   = strtolower( trim( (string) $s_id ) );

                        if ( $s_key === $search_str || $s_name === $search_str || $s_title === $search_str ) {
                            $shop_data = $s_info;
                            break;
                        }
                    }
                }
            }
        } elseif ( is_array( $shop_data_or_id ) ) {
            $shop_data = $shop_data_or_id;
        }

        if ( is_array( $shop_data ) && ! empty( $shop_data ) ) {
            // 1. Check top-level legacy keys first
            if ( isset( $shop_data['cap_20yd'] ) && ! empty( $shop_data['cap_20yd'] ) ) {
                $cap_20yd = intval( $shop_data['cap_20yd'] );
            } elseif ( isset( $shop_data['capacity_20yd'] ) && ! empty( $shop_data['capacity_20yd'] ) ) {
                $cap_20yd = intval( $shop_data['capacity_20yd'] );
            } elseif ( isset( $shop_data['lanes_20yd'] ) && ! empty( $shop_data['lanes_20yd'] ) ) {
                $cap_20yd = intval( $shop_data['lanes_20yd'] );
            } elseif ( isset( $shop_data['max_20yd'] ) && ! empty( $shop_data['max_20yd'] ) ) {
                $cap_20yd = intval( $shop_data['max_20yd'] );
            } elseif ( isset( $shop_data['cap_20'] ) && ! empty( $shop_data['cap_20'] ) ) {
                $cap_20yd = intval( $shop_data['cap_20'] );
            }

            if ( isset( $shop_data['cap_cub'] ) && ! empty( $shop_data['cap_cub'] ) ) {
                $cap_cub = intval( $shop_data['cap_cub'] );
            } elseif ( isset( $shop_data['capacity_cub'] ) && ! empty( $shop_data['capacity_cub'] ) ) {
                $cap_cub = intval( $shop_data['capacity_cub'] );
            } elseif ( isset( $shop_data['cub_cap'] ) && ! empty( $shop_data['cub_cap'] ) ) {
                $cap_cub = intval( $shop_data['cub_cap'] );
            } elseif ( isset( $shop_data['max_cub'] ) && ! empty( $shop_data['max_cub'] ) ) {
                $cap_cub = intval( $shop_data['max_cub'] );
            }

            // 2. Check nested 'default_lines' configuration (Takes precedence)
            if ( isset( $shop_data['default_lines'] ) && is_array( $shop_data['default_lines'] ) && isset( $shop_data['default_lines'][0] ) ) {
                $first_line = $shop_data['default_lines'][0];
                if ( isset( $first_line['cap_20yd'] ) && $first_line['cap_20yd'] !== '' ) {
                    $cap_20yd = intval( $first_line['cap_20yd'] );
                }
                if ( isset( $first_line['cap_cub'] ) && $first_line['cap_cub'] !== '' ) {
                    $cap_cub = intval( $first_line['cap_cub'] );
                }
            }
        }

        return array(
            'cap_20yd' => $cap_20yd,
            'cap_cub'  => $cap_cub,
        );
    }
}

/**
 * Builds the default 4-line template using host shop capacity limits.
 *
 * @param array|string $shop_data_or_id Optional shop dataset array, shop ID, or shop name string.
 * @return array Default lines template.
 */
if ( ! function_exists( 'sywat_get_default_tournament_lines' ) ) {
    function sywat_get_default_tournament_lines( $shop_data_or_id = array() ) {
        $caps = sywat_get_shop_capacity_defaults( $shop_data_or_id );
        return array(
            array( 'day' => 'Saturday', 'time' => '10:00 AM', 'name' => 'Saturday 10:00 AM', 'cap_20yd' => $caps['cap_20yd'], 'cap_cub' => $caps['cap_cub'] ),
            array( 'day' => 'Saturday', 'time' => '2:00 PM',  'name' => 'Saturday 2:00 PM',  'cap_20yd' => $caps['cap_20yd'], 'cap_cub' => $caps['cap_cub'] ),
            array( 'day' => 'Sunday',   'time' => '10:00 AM', 'name' => 'Sunday 10:00 AM',   'cap_20yd' => $caps['cap_20yd'], 'cap_cub' => $caps['cap_cub'] ),
            array( 'day' => 'Sunday',   'time' => '2:00 PM',  'name' => 'Sunday 2:00 PM',    'cap_20yd' => $caps['cap_20yd'], 'cap_cub' => $caps['cap_cub'] ),
        );
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
        return '';
    }
}

/**
 * Resolves host archery shop display name with smart waterfall resolution.
 *
 * @param array  $t_data    Tournament configuration array.
 * @param string $t_name    Optional tournament title.
 * @param array  $all_shops Optional master shops array.
 * @return string Resolved shop display name.
 */
if ( ! function_exists( 'sywat_get_tournament_shop_name' ) ) {
    function sywat_get_tournament_shop_name( $t_data, $t_name = '', $all_shops = array() ) {
        if ( empty( $all_shops ) && function_exists( 'sywat_get_all_shops' ) ) {
            $all_shops = sywat_get_all_shops();
        }
        if ( empty( $all_shops ) ) {
            $all_shops = get_option( 'sywat_shops', get_option( 'sywat_shops_list', array() ) );
        }
        if ( ! is_array( $all_shops ) ) {
            $all_shops = array();
        }

        // 1. Direct shop_id lookup in $all_shops
        $s_id = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : ( is_array( $t_data ) && isset( $t_data['shop_id'] ) ? $t_data['shop_id'] : '' );
        if ( ! empty( $s_id ) && isset( $all_shops[$s_id] ) && is_array( $all_shops[$s_id] ) ) {
            $s_info = $all_shops[$s_id];
            $name   = ! empty( $s_info['name'] ) ? $s_info['name'] : ( ! empty( $s_info['shop_name'] ) ? $s_info['shop_name'] : ( ! empty( $s_info['title'] ) ? $s_info['title'] : '' ) );
            if ( ! empty( $name ) ) return $name;
        }

        // 2. Stored explicit shop name metadata in tournament data
        if ( is_array( $t_data ) ) {
            if ( ! empty( $t_data['official_shop_name'] ) ) return $t_data['official_shop_name'];
            if ( ! empty( $t_data['shop_name'] ) ) return $t_data['shop_name'];
            if ( ! empty( $t_data['location_display'] ) ) return $t_data['location_display'];
        }

        // Build shop lookup map by title
        $shop_map = array();
        foreach ( $all_shops as $id => $info ) {
            if ( is_array( $info ) ) {
                $title = ! empty( $info['name'] ) ? $info['name'] : ( ! empty( $info['shop_name'] ) ? $info['shop_name'] : ( ! empty( $info['title'] ) ? $info['title'] : '' ) );
                if ( ! empty( $title ) ) {
                    $shop_map[ strtolower( trim( $title ) ) ] = $title;
                }
            }
        }

        // 3. Fallback: Parse shop name from tournament title (e.g. "Archery HQ-1" -> "Archery HQ")
        $title_to_parse = ! empty( $t_name ) ? $t_name : ( is_array( $t_data ) && isset( $t_data['name'] ) ? $t_data['name'] : '' );
        if ( ! empty( $title_to_parse ) ) {
            $base_title = preg_replace( '/\s*[\-\#]\s*\d+$/i', '', trim( $title_to_parse ) );
            $norm_base  = strtolower( trim( $base_title ) );

            if ( isset( $shop_map[$norm_base] ) ) {
                return $shop_map[$norm_base];
            }

            foreach ( $shop_map as $norm_shop => $official_title ) {
                if ( ! empty( $norm_shop ) && strpos( $norm_base, $norm_shop ) !== false ) {
                    return $official_title;
                }
            }

            if ( ! empty( $base_title ) && strcasecmp( $base_title, 'TFAA State Indoor Championship' ) !== 0 ) {
                return $base_title;
            }
        }

        return 'Host Archery Shop';
    }
}

if ( ! function_exists( 'sywat_get_tournament_season_slug' ) ) {
    function sywat_get_tournament_season_slug( $t_data ) {
        if ( is_array( $t_data ) && isset( $t_data['season_slug'] ) && ! empty( $t_data['season_slug'] ) ) {
            return $t_data['season_slug'];
        }
        if ( is_array( $t_data ) && isset( $t_data['season'] ) && ! empty( $t_data['season'] ) ) {
            return $t_data['season'];
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
