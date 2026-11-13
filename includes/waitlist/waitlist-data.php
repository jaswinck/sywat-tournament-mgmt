<?php
/**
 * Sub-Module: Waitlist Data Access, Capacity & FIFO Family Queue Engine
 * Path: includes/waitlist/waitlist-data.php
 * Description: Pure read-only data layer computing per-line/distance open capacities and building FIFO-ordered, family-clustered waitlist queues with split-family reconciliation metadata.
 * Compliance: Pure data engine — zero HTML echo, zero POST mutations, zero script/style output, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normalize target distance token to canonical 'Cub' or '20yd'.
 *
 * @param string $dist_str Raw distance label.
 * @return string          Canonical distance ('Cub' or '20yd').
 */
if ( ! function_exists( 'sywat_waitlist_normalize_distance' ) ) {
    function sywat_waitlist_normalize_distance( $dist_str ) {
        $clean = strtolower( trim( (string) $dist_str ) );
        if ( $clean === 'cub' || $clean === '10yd' || $clean === '10 yards' || $clean === '10 yd' ) {
            return 'Cub';
        }
        return '20yd';
    }
}

/**
 * Resolve human-readable shooting line display label consistently across modules.
 *
 * @param array|string $line Line configuration array or string.
 * @param string|int   $idx  Line array index.
 * @return string            Normalized shooting line label.
 */
if ( ! function_exists( 'sywat_waitlist_format_line_label' ) ) {
    function sywat_waitlist_format_line_label( $line, $idx ) {
        $label = '';
        if ( is_array( $line ) ) {
            if ( ! empty( $line['name'] ) ) {
                $label = trim( (string) $line['name'] );
            } else {
                $day_str  = isset( $line['day'] ) ? trim( (string) $line['day'] ) : '';
                $time_str = isset( $line['time'] ) ? trim( (string) $line['time'] ) : ( isset( $line['start_time'] ) ? trim( (string) $line['start_time'] ) : '' );
                if ( $day_str !== '' && $time_str !== '' ) {
                    $label = trim( $day_str . ' ' . $time_str );
                } elseif ( $day_str !== '' ) {
                    $label = $day_str;
                } elseif ( $time_str !== '' ) {
                    $label = $time_str;
                }
            }
        } elseif ( is_string( $line ) && trim( $line ) !== '' ) {
            $label = trim( $line );
        }

        if ( $label === '' ) {
            return 'Line ' . ( (int) $idx + 1 );
        }

        $label = preg_replace( '/\b6(?::00)?\s*AM\b/i', '6PM', $label );
        $label = preg_replace( '/\b6:30\s*AM\b/i', '6:30PM', $label );
        $label = preg_replace( '/(\d{1,2}):00\s*(AM|PM)/i', '$1$2', $label );

        return $label;
    }
}

/**
 * Resolve canonical shooting line index key against configured tournament lines.
 *
 * @param array $reg     Archer registration payload.
 * @param array $t_lines Configured tournament shooting lines array.
 * @return string        Resolved string line index key.
 */
if ( ! function_exists( 'sywat_waitlist_resolve_line_idx' ) ) {
    function sywat_waitlist_resolve_line_idx( $reg, $t_lines ) {
        if ( ! is_array( $t_lines ) ) {
            $t_lines = array();
        }

        if ( isset( $reg['line_index'] ) && $reg['line_index'] !== '' ) {
            $idx = (string) $reg['line_index'];
            if ( isset( $t_lines[ $idx ] ) ) {
                return $idx;
            }
            foreach ( $t_lines as $l_key => $l_data ) {
                $l_label = sywat_waitlist_format_line_label( $l_data, $l_key );
                if ( strcasecmp( $l_label, trim( $idx ) ) === 0 || ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), trim( $idx ) ) === 0 ) ) {
                    return (string) $l_key;
                }
            }
        }

        if ( ! empty( $reg['line_name'] ) ) {
            $target_name = trim( (string) $reg['line_name'] );
            foreach ( $t_lines as $l_key => $l_data ) {
                $l_label = sywat_waitlist_format_line_label( $l_data, $l_key );
                if ( strcasecmp( $l_label, $target_name ) === 0 || ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), $target_name ) === 0 ) ) {
                    return (string) $l_key;
                }
            }
        }

        return isset( $reg['line_index'] ) && $reg['line_index'] !== '' ? (string) $reg['line_index'] : '0';
    }
}

/**
 * Compute live 20yd and Cub capacities, active occupancy, open slots, closed flags,
 * and waitlisted counts across all shooting lines in a single O(N) pass.
 *
 * @param string $tourn_name    Active tournament name.
 * @param array  $t_lines       Tournament shooting line configuration array.
 * @param array  $registrations Master registration records for the tournament.
 * @param array  $closed_lines  Optional array of closed line indices/names.
 * @return array                Map keyed by string line_index.
 */
if ( ! function_exists( 'sywat_waitlist_get_line_capacities' ) ) {
    function sywat_waitlist_get_line_capacities( $tourn_name, $t_lines, $registrations, $closed_lines = array() ) {
        $capacities = array();
        if ( ! is_array( $t_lines ) ) {
            $t_lines = array();
        }
        if ( ! is_array( $closed_lines ) ) {
            $closed_lines = array();
        }

        $normalized_closed = array_map( 'trim', array_map( 'strval', $closed_lines ) );

        foreach ( $t_lines as $idx => $line ) {
            $idx_str    = (string) $idx;
            $line_label = sywat_waitlist_format_line_label( $line, $idx );
            $raw_name   = is_array( $line ) && ! empty( $line['name'] ) ? trim( (string) $line['name'] ) : $line_label;

            $max_20  = function_exists( 'sywat_get_line_max_cap' )
                ? sywat_get_line_max_cap( $line, '20yd' )
                : ( is_array( $line ) && isset( $line['cap_20yd'] ) ? intval( $line['cap_20yd'] ) : 20 );
            $max_cub = function_exists( 'sywat_get_line_max_cap' )
                ? sywat_get_line_max_cap( $line, 'Cub' )
                : ( is_array( $line ) && isset( $line['cap_cub'] ) ? intval( $line['cap_cub'] ) : 5 );

            if ( $max_20 < 0 ) {
                $max_20 = 20;
            }
            if ( $max_cub < 0 ) {
                $max_cub = 5;
            }

            $is_closed = in_array( $idx_str, $normalized_closed, true )
                      || ( $raw_name !== '' && in_array( $raw_name, $normalized_closed, true ) )
                      || ( $line_label !== '' && in_array( $line_label, $normalized_closed, true ) );

            $capacities[ $idx_str ] = array(
                'line_index'     => $idx_str,
                'line_name'      => $line_label,
                'is_closed'      => $is_closed,
                'cap_20yd'       => $max_20,
                'booked_20yd'    => 0,
                'open_20yd'      => $is_closed ? 0 : $max_20,
                'raw_open_20yd'  => $max_20,
                'waitlist_20yd'  => 0,
                'cap_cub'        => $max_cub,
                'booked_cub'     => 0,
                'open_cub'       => $is_closed ? 0 : $max_cub,
                'raw_open_cub'   => $max_cub,
                'waitlist_cub'   => 0,
                'total_booked'   => 0,
                'total_open'     => $is_closed ? 0 : ( $max_20 + $max_cub ),
                'total_waitlist' => 0,
            );
        }

        if ( is_array( $registrations ) ) {
            foreach ( $registrations as $reg ) {
                if ( ! is_array( $reg ) || ! isset( $reg['tournament'] ) ) {
                    continue;
                }
                if ( strcasecmp( trim( (string) $reg['tournament'] ), trim( (string) $tourn_name ) ) !== 0 ) {
                    continue;
                }

                $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
                if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                    continue;
                }

                $l_idx = sywat_waitlist_resolve_line_idx( $reg, $t_lines );
                if ( ! isset( $capacities[ $l_idx ] ) ) {
                    $fallback_name = ! empty( $reg['line_name'] ) ? trim( (string) $reg['line_name'] ) : ( 'Line ' . ( (int) $l_idx + 1 ) );
                    $capacities[ $l_idx ] = array(
                        'line_index'     => $l_idx,
                        'line_name'      => $fallback_name,
                        'is_closed'      => false,
                        'cap_20yd'       => 20,
                        'booked_20yd'    => 0,
                        'open_20yd'      => 20,
                        'raw_open_20yd'  => 20,
                        'waitlist_20yd'  => 0,
                        'cap_cub'        => 5,
                        'booked_cub'     => 0,
                        'open_cub'       => 5,
                        'raw_open_cub'   => 5,
                        'waitlist_cub'   => 0,
                        'total_booked'   => 0,
                        'total_open'     => 25,
                        'total_waitlist' => 0,
                    );
                }

                $dist        = sywat_waitlist_normalize_distance( isset( $reg['distance'] ) ? $reg['distance'] : '20yd' );
                $is_waitlist = ( strcasecmp( $status, 'Waitlist' ) === 0 );

                if ( $is_waitlist ) {
                    if ( $dist === 'Cub' ) {
                        $capacities[ $l_idx ]['waitlist_cub']++;
                    } else {
                        $capacities[ $l_idx ]['waitlist_20yd']++;
                    }
                    $capacities[ $l_idx ]['total_waitlist']++;
                } else {
                    if ( $dist === 'Cub' ) {
                        $capacities[ $l_idx ]['booked_cub']++;
                    } else {
                        $capacities[ $l_idx ]['booked_20yd']++;
                    }
                    $capacities[ $l_idx ]['total_booked']++;
                }
            }
        }

        foreach ( $capacities as $l_idx => &$cap ) {
            $raw_open_20  = max( 0, $cap['cap_20yd'] - $cap['booked_20yd'] );
            $raw_open_cub = max( 0, $cap['cap_cub'] - $cap['booked_cub'] );

            $cap['raw_open_20yd'] = $raw_open_20;
            $cap['raw_open_cub']  = $raw_open_cub;
            $cap['open_20yd']     = $cap['is_closed'] ? 0 : $raw_open_20;
            $cap['open_cub']      = $cap['is_closed'] ? 0 : $raw_open_cub;
            $cap['total_open']    = $cap['open_20yd'] + $cap['open_cub'];
        }
        unset( $cap );

        return $capacities;
    }
}

/**
 * Extract waitlisted archers for a tournament (and optional line filter), cluster them by
 * family_id (or solo registration ID), order groups in strict FIFO order by earliest
 * created_at timestamp, and attach split-family and open-capacity metadata.
 *
 * @param string $tourn_name      Active tournament name.
 * @param array  $t_lines         Tournament shooting line configuration array.
 * @param array  $registrations   Master registration records.
 * @param string $filter_line_idx Optional line index filter or 'all'.
 * @param array  $closed_lines    Optional closed line indices/names.
 * @return array                  Structured dataset with 'groups', 'total_waitlisted', and 'line_capacities'.
 */
if ( ! function_exists( 'sywat_waitlist_get_grouped_queue' ) ) {
    function sywat_waitlist_get_grouped_queue( $tourn_name, $t_lines, $registrations, $filter_line_idx = 'all', $closed_lines = array() ) {
        $line_capacities      = sywat_waitlist_get_line_capacities( $tourn_name, $t_lines, $registrations, $closed_lines );
        $active_family_counts = array();
        $groups_map           = array();
        $seq_counter          = 0;
        $total_waitlisted     = 0;

        $filter_str = (string) $filter_line_idx;
        $apply_line_filter = ( $filter_str !== 'all' && $filter_str !== '' );

        if ( is_array( $registrations ) ) {
            foreach ( $registrations as $reg ) {
                if ( ! is_array( $reg ) || ! isset( $reg['tournament'], $reg['id'] ) ) {
                    continue;
                }
                if ( strcasecmp( trim( (string) $reg['tournament'] ), trim( (string) $tourn_name ) ) !== 0 ) {
                    continue;
                }

                $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
                if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                    continue;
                }

                $l_idx  = sywat_waitlist_resolve_line_idx( $reg, $t_lines );
                $fam_id = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';

                // Track active (non-waitlisted) family members per line for split-family detection
                if ( strcasecmp( $status, 'Waitlist' ) !== 0 ) {
                    if ( $fam_id !== '' ) {
                        if ( ! isset( $active_family_counts[ $l_idx ] ) ) {
                            $active_family_counts[ $l_idx ] = array();
                        }
                        if ( ! isset( $active_family_counts[ $l_idx ][ $fam_id ] ) ) {
                            $active_family_counts[ $l_idx ][ $fam_id ] = 0;
                        }
                        $active_family_counts[ $l_idx ][ $fam_id ]++;
                    }
                    continue;
                }

                if ( $apply_line_filter && $l_idx !== $filter_str ) {
                    continue;
                }

                $total_waitlisted++;
                $seq_counter++;

                $is_family_group = ( $fam_id !== '' );
                $group_key       = $is_family_group
                    ? ( 'fam__' . $l_idx . '__' . $fam_id )
                    : ( 'solo__' . (string) $reg['id'] );

                $created_raw = ! empty( $reg['created_at'] ) ? trim( (string) $reg['created_at'] ) : '';
                $created_ts  = ( $created_raw !== '' && strtotime( $created_raw ) !== false )
                    ? (int) strtotime( $created_raw )
                    : PHP_INT_MAX;

                $line_label = isset( $line_capacities[ $l_idx ]['line_name'] )
                    ? $line_capacities[ $l_idx ]['line_name']
                    : sywat_waitlist_format_line_label( isset( $t_lines[ $l_idx ] ) ? $t_lines[ $l_idx ] : array(), $l_idx );

                $dist = sywat_waitlist_normalize_distance( isset( $reg['distance'] ) ? $reg['distance'] : '20yd' );

                if ( ! isset( $groups_map[ $group_key ] ) ) {
                    $raw_fam_name = ! empty( $reg['family_name'] ) ? trim( (string) $reg['family_name'] ) : '';
                    $clean_label  = '';
                    if ( $raw_fam_name !== '' && stripos( $raw_fam_name, 'fam_' ) === false && stripos( $raw_fam_name, 'Booking' ) === false ) {
                        $clean_label = $raw_fam_name;
                    }

                    $groups_map[ $group_key ] = array(
                        'group_key'           => $group_key,
                        'is_family_group'     => $is_family_group,
                        'family_id'           => $fam_id,
                        'group_label'         => $clean_label,
                        'line_index'          => $l_idx,
                        'line_name'           => $line_label,
                        'earliest_created_at' => $created_raw,
                        'earliest_timestamp'  => $created_ts,
                        'seq_idx'             => $seq_counter,
                        'active_family_count' => 0,
                        'needed_20yd'         => 0,
                        'needed_cub'          => 0,
                        'members'             => array(),
                    );
                } else {
                    if ( $created_ts < $groups_map[ $group_key ]['earliest_timestamp'] ) {
                        $groups_map[ $group_key ]['earliest_timestamp']  = $created_ts;
                        $groups_map[ $group_key ]['earliest_created_at'] = $created_raw;
                    }
                }

                if ( $dist === 'Cub' ) {
                    $groups_map[ $group_key ]['needed_cub']++;
                } else {
                    $groups_map[ $group_key ]['needed_20yd']++;
                }

                $reg_copy                   = $reg;
                $reg_copy['resolved_line']  = $l_idx;
                $reg_copy['resolved_dist']  = $dist;
                $reg_copy['created_ts']     = $created_ts;
                $reg_copy['seq_idx']        = $seq_counter;
                $groups_map[ $group_key ]['members'][] = $reg_copy;
            }
        }

        // Finalize labels, split-family counts, member ordering, and slot availability
        $groups = array_values( $groups_map );
        foreach ( $groups as &$grp ) {
            // Sort members inside each family group chronologically by created_at
            usort( $grp['members'], function( $a, $b ) {
                if ( $a['created_ts'] !== $b['created_ts'] ) {
                    return $a['created_ts'] <=> $b['created_ts'];
                }
                return $a['seq_idx'] <=> $b['seq_idx'];
            } );

            if ( $grp['group_label'] === '' ) {
                $first_name = ! empty( $grp['members'][0]['name'] ) ? trim( (string) $grp['members'][0]['name'] ) : 'Archer';
                if ( $grp['is_family_group'] && count( $grp['members'] ) > 1 ) {
                    $parts     = array_values( array_filter( explode( ' ', $first_name ) ) );
                    $last_name = ! empty( $parts ) ? end( $parts ) : $first_name;
                    $grp['group_label'] = $last_name . ' Group';
                } else {
                    $grp['group_label'] = $first_name;
                }
            }

            $l_idx  = $grp['line_index'];
            $fam_id = $grp['family_id'];
            if ( $fam_id !== '' && isset( $active_family_counts[ $l_idx ][ $fam_id ] ) ) {
                $grp['active_family_count'] = (int) $active_family_counts[ $l_idx ][ $fam_id ];
            }

            $cap_info      = isset( $line_capacities[ $l_idx ] ) ? $line_capacities[ $l_idx ] : array();
            $open_20       = isset( $cap_info['open_20yd'] ) ? (int) $cap_info['open_20yd'] : 0;
            $open_cub      = isset( $cap_info['open_cub'] ) ? (int) $cap_info['open_cub'] : 0;
            $is_line_closed = ! empty( $cap_info['is_closed'] );

            $grp['open_20yd']      = $open_20;
            $grp['open_cub']       = $open_cub;
            $grp['is_line_closed'] = $is_line_closed;
            $grp['can_fit_all']    = ( ! $is_line_closed && $open_20 >= $grp['needed_20yd'] && $open_cub >= $grp['needed_cub'] );

            foreach ( $grp['members'] as &$m ) {
                $m_dist = $m['resolved_dist'];
                $m['can_fit_member'] = ( ! $is_line_closed && ( $m_dist === 'Cub' ? $open_cub >= 1 : $open_20 >= 1 ) );
            }
            unset( $m );
        }
        unset( $grp );

        // Sort groups in strict FIFO order (earliest timestamp first, deterministic insertion tie-breaker)
        usort( $groups, function( $a, $b ) {
            if ( $a['earliest_timestamp'] !== $b['earliest_timestamp'] ) {
                return $a['earliest_timestamp'] <=> $b['earliest_timestamp'];
            }
            return $a['seq_idx'] <=> $b['seq_idx'];
        } );

        // Assign 1-based FIFO queue rank
        foreach ( $groups as $idx => &$grp ) {
            $grp['queue_position'] = $idx + 1;
        }
        unset( $grp );

        return array(
            'groups'           => $groups,
            'total_waitlisted' => $total_waitlisted,
            'line_capacities'  => $line_capacities,
        );
    }
}
