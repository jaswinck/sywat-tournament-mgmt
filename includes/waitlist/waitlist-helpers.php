<?php
/**
 * Sub-Module: Waitlist Fee Calculation, Per-Line Family Cap Reconciler & Persistence Helpers
 * Path: includes/waitlist/waitlist-helpers.php
 * Description: Pure business-rule helpers for database-driven Youth/Adult rate evaluation, strict per-line $75.00 Family Cap reconciliation, household-wide balance resolution, and atomic multi-store registration persistence.
 * Compliance: Pure helper layer — zero HTML echo, zero POST handling, zero script/style output, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Determine whether a division/distance qualifies for the Youth ($25.00) tier
 * using database-configured age division rate tiers (with safe NFAA fallback).
 *
 * @param string $division Archer age division label.
 * @param string $distance Optional target distance label.
 * @return bool            True if Youth/Cub tier, false otherwise.
 */
if ( ! function_exists( 'sywat_waitlist_is_youth_division' ) ) {
    function sywat_waitlist_is_youth_division( $division, $distance = '' ) {
        $div_clean  = trim( (string) $division );
        $dist_clean = function_exists( 'sywat_waitlist_normalize_distance' )
            ? sywat_waitlist_normalize_distance( $distance )
            : ( strcasecmp( trim( (string) $distance ), 'Cub' ) === 0 ? 'Cub' : '20yd' );

        if ( $div_clean !== '' && strcasecmp( $div_clean, 'No Show' ) !== 0 && function_exists( 'sywat_find_division' ) ) {
            $div_rec = sywat_find_division( $div_clean );
            if ( is_array( $div_rec ) && isset( $div_rec['rate_tier'] ) ) {
                return ( $div_rec['rate_tier'] === 'Youth' );
            }
        }

        if ( $dist_clean === 'Cub' ) {
            return true;
        }

        $youth_divisions = array(
            'Cub (8 - 11)',
            'Youth (12 - 14)',
            'Young Adult (15 - 17)',
            'Cub',
            'Youth',
            'Young Adult',
        );

        foreach ( $youth_divisions as $yd ) {
            if ( strcasecmp( $div_clean, $yd ) === 0 ) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Return standard individual entry fee ($25.00 Youth/Cub, $30.00 Adult/Senior, $0.00 No Show).
 *
 * @param string $division Archer age division label.
 * @param string $distance Optional target distance label.
 * @return float           Standard individual entry fee.
 */
if ( ! function_exists( 'sywat_waitlist_get_individual_rate' ) ) {
    function sywat_waitlist_get_individual_rate( $division, $distance = '' ) {
        if ( strcasecmp( trim( (string) $division ), 'No Show' ) === 0 ) {
            return 0.00;
        }
        return sywat_waitlist_is_youth_division( $division, $distance ) ? 25.00 : 30.00;
    }
}

/**
 * Resolve canonical non-family paid tier ('Field Governor', 'Youth', or 'Adult').
 *
 * @param string $division      Archer age division label.
 * @param string $distance      Optional target distance label.
 * @param string $existing_paid Optional existing paid status.
 * @return string               Canonical paid status label.
 */
if ( ! function_exists( 'sywat_waitlist_get_paid_tier' ) ) {
    function sywat_waitlist_get_paid_tier( $division, $distance = '', $existing_paid = '' ) {
        if ( strcasecmp( trim( (string) $existing_paid ), 'Field Governor' ) === 0 ) {
            return 'Field Governor';
        }
        return sywat_waitlist_is_youth_division( $division, $distance ) ? 'Youth' : 'Adult';
    }
}

/**
 * Evaluate household-wide unpaid balance across all active members sharing the same
 * family_id or booking_id on the same shooting line so no family member can check in
 * while a family balance remains unpaid.
 *
 * @param array $reg           Target archer registration record.
 * @param array $registrations All registrations for the active tournament.
 * @param array $t_lines       Optional tournament lines array for index resolution.
 * @return array               Household balance summary ('has_family', 'family_id', 'group_due', 'individual_due', 'effective_due', 'is_family_capped').
 */
if ( ! function_exists( 'sywat_get_household_balance_info' ) ) {
    function sywat_get_household_balance_info( $reg, $registrations, $t_lines = array() ) {
        $ind_due = ( is_array( $reg ) && isset( $reg['balance_due'] ) ) ? max( 0.00, floatval( $reg['balance_due'] ) ) : 0.00;
        $paid    = ( is_array( $reg ) && isset( $reg['paid'] ) ) ? trim( (string) $reg['paid'] ) : '';
        $fam_id  = ( is_array( $reg ) && ! empty( $reg['family_id'] ) ) ? trim( (string) $reg['family_id'] ) : '';
        $book_id = ( is_array( $reg ) && ! empty( $reg['booking_id'] ) ) ? trim( (string) $reg['booking_id'] ) : '';

        $is_family_paid = ( strcasecmp( $paid, 'Family' ) === 0 )
            || ( is_array( $reg ) && ! empty( $reg['rate_type'] ) && stripos( (string) $reg['rate_type'], 'family' ) !== false );

        if ( ! is_array( $reg ) || ( $fam_id === '' && $book_id === '' && ! $is_family_paid ) || ! is_array( $registrations ) ) {
            return array(
                'has_family'       => false,
                'family_id'        => '',
                'group_due'        => $ind_due,
                'individual_due'   => $ind_due,
                'effective_due'    => $ind_due,
                'is_family_capped' => false,
            );
        }

        $target_tourn = isset( $reg['tournament'] ) ? trim( (string) $reg['tournament'] ) : '';
        $target_line  = function_exists( 'sywat_waitlist_resolve_line_idx' )
            ? sywat_waitlist_resolve_line_idx( $reg, $t_lines )
            : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

        $group_due_sum    = 0.00;
        $active_member_ct = 0;
        $raw_rate_sum     = 0.00;
        $any_family_tag   = $is_family_paid;

        foreach ( $registrations as $other ) {
            if ( ! is_array( $other ) ) {
                continue;
            }
            if ( $target_tourn !== '' && isset( $other['tournament'] ) && strcasecmp( trim( (string) $other['tournament'] ), $target_tourn ) !== 0 ) {
                continue;
            }

            $status = isset( $other['status'] ) ? trim( (string) $other['status'] ) : '';
            if ( strcasecmp( $status, 'Waitlist' ) === 0 || strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            $other_line = function_exists( 'sywat_waitlist_resolve_line_idx' )
                ? sywat_waitlist_resolve_line_idx( $other, $t_lines )
                : ( isset( $other['line_index'] ) ? (string) $other['line_index'] : '0' );

            if ( $other_line !== $target_line ) {
                continue;
            }

            $other_fam  = ! empty( $other['family_id'] ) ? trim( (string) $other['family_id'] ) : '';
            $other_book = ! empty( $other['booking_id'] ) ? trim( (string) $other['booking_id'] ) : '';

            $matched = false;
            if ( $fam_id !== '' && $other_fam === $fam_id ) {
                $matched = true;
            } elseif ( $book_id !== '' && $other_book === $book_id ) {
                $matched = true;
            }

            if ( ! $matched ) {
                continue;
            }

            $active_member_ct++;
            $other_due      = isset( $other['balance_due'] ) ? max( 0.00, floatval( $other['balance_due'] ) ) : 0.00;
            $group_due_sum += $other_due;

            $o_div  = isset( $other['division'] ) ? $other['division'] : '';
            $o_dist = isset( $other['distance'] ) ? $other['distance'] : '20yd';
            $o_paid = isset( $other['paid'] ) ? trim( (string) $other['paid'] ) : '';

            if ( strcasecmp( $o_paid, 'Family' ) === 0 ) {
                $any_family_tag = true;
            }
            if ( strcasecmp( $o_paid, 'Field Governor' ) !== 0 ) {
                $raw_rate_sum += sywat_waitlist_get_individual_rate( $o_div, $o_dist );
            }
        }

        $effective_due    = round( max( $ind_due, $group_due_sum ), 2 );
        $is_family_capped = $any_family_tag && ( $active_member_ct >= 3 || $raw_rate_sum >= 75.00 || $effective_due >= 75.00 );

        return array(
            'has_family'       => ( $active_member_ct > 1 || $any_family_tag ),
            'family_id'        => $fam_id !== '' ? $fam_id : $book_id,
            'group_due'        => round( $group_due_sum, 2 ),
            'individual_due'   => round( $ind_due, 2 ),
            'effective_due'    => $effective_due,
            'is_family_capped' => $is_family_capped,
        );
    }
}

/**
 * Atomically persist a registration record across individual option storage,
 * tournament-scoped registration cache, legacy global cache (if present),
 * and the in-memory $registrations array reference.
 *
 * @param array $reg           Registration record array (must contain 'id' and 'tournament').
 * @param array $registrations In-memory registrations list passed by reference.
 * @return bool                True on successful persistence, false if missing required keys.
 */
if ( ! function_exists( 'sywat_waitlist_persist_record' ) ) {
    function sywat_waitlist_persist_record( $reg, &$registrations ) {
        if ( ! is_array( $reg ) || empty( $reg['id'] ) || empty( $reg['tournament'] ) ) {
            return false;
        }

        $reg_id = (string) $reg['id'];
        $t_slug = sanitize_title( (string) $reg['tournament'] );

        if ( empty( $t_slug ) ) {
            return false;
        }

        // 1. Primary per-record option store
        update_option( 'sywat_t_' . $t_slug . '_' . $reg_id, $reg, false );

        // 2. Tournament-scoped master map
        $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( ! is_array( $master_regs ) ) {
            $master_regs = array();
        }
        $master_regs[ $reg_id ] = $reg;
        update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );

        // 3. In-memory reference sync
        if ( is_array( $registrations ) ) {
            $found = false;
            foreach ( $registrations as &$existing ) {
                if ( is_array( $existing ) && isset( $existing['id'] ) && (string) $existing['id'] === $reg_id ) {
                    $existing = $reg;
                    $found    = true;
                    break;
                }
            }
            unset( $existing );

            if ( ! $found ) {
                $registrations[] = $reg;
            }
        }

        // 4. Legacy global cache sync (only if option exists, without overwriting other tournaments)
        $legacy_global = get_option( 'sywat_registrations', false );
        if ( is_array( $legacy_global ) ) {
            $global_updated = false;
            foreach ( $legacy_global as &$g_reg ) {
                if ( is_array( $g_reg ) && isset( $g_reg['id'] ) && (string) $g_reg['id'] === $reg_id ) {
                    $g_reg          = $reg;
                    $global_updated = true;
                    break;
                }
            }
            unset( $g_reg );

            if ( ! $global_updated ) {
                $legacy_global[] = $reg;
            }
            update_option( 'sywat_registrations', $legacy_global, false );
        }

        return true;
    }
}

/**
 * Reconcile paid tiers and balances for all active (non-waitlisted, non-cancelled)
 * members of a family group on a single shooting line.
 *
 * @param string $tourn_name    Active tournament name.
 * @param string $line_idx      Target shooting line index.
 * @param string $family_id     Family group identifier.
 * @param array  $registrations In-memory registrations list passed by reference.
 * @param array  $t_lines       Optional tournament lines config for index resolution.
 * @return array                Summary array ('is_family_capped', 'active_count', 'line_total_due').
 */
if ( ! function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
    function sywat_waitlist_recalculate_line_family_fees( $tourn_name, $line_idx, $family_id, &$registrations, $t_lines = array() ) {
        $target_tourn = trim( (string) $tourn_name );
        $target_line  = (string) $line_idx;
        $target_fam   = trim( (string) $family_id );

        if ( $target_tourn === '' || $target_fam === '' || ! is_array( $registrations ) ) {
            return array(
                'is_family_capped' => false,
                'active_count'     => 0,
                'line_total_due'   => 0.00,
            );
        }

        $active_indices              = array();
        $prior_due_sum               = 0.00;
        $prior_paid_sum              = 0.00;
        $had_explicit_paid_tracking  = false;
        $was_previously_capped       = false;
        $prior_active_individual_sum = 0.00;

        foreach ( $registrations as $idx => $reg ) {
            if ( ! is_array( $reg ) || ! isset( $reg['tournament'], $reg['id'] ) ) {
                continue;
            }
            if ( strcasecmp( trim( (string) $reg['tournament'] ), $target_tourn ) !== 0 ) {
                continue;
            }

            $reg_fam = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
            if ( $reg_fam !== $target_fam ) {
                continue;
            }

            $resolved_l = function_exists( 'sywat_waitlist_resolve_line_idx' )
                ? sywat_waitlist_resolve_line_idx( $reg, $t_lines )
                : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

            if ( $resolved_l !== $target_line ) {
                continue;
            }

            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            // Waitlisted members always carry $0.00 balance due until promoted
            if ( strcasecmp( $status, 'Waitlist' ) === 0 ) {
                if ( ( isset( $reg['balance_due'] ) && floatval( $reg['balance_due'] ) !== 0.0 ) || ( isset( $reg['paid'] ) && $reg['paid'] !== 'Waitlist' ) ) {
                    $reg['paid']        = 'Waitlist';
                    $reg['balance_due'] = 0.00;
                    sywat_waitlist_persist_record( $reg, $registrations );
                }
                continue;
            }

            $paid_val = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
            if ( strcasecmp( $paid_val, 'Field Governor' ) === 0 ) {
                continue;
            }

            $div_val  = isset( $reg['division'] ) ? $reg['division'] : '';
            $dist_val = isset( $reg['distance'] ) ? $reg['distance'] : '20yd';
            $ind_rate = sywat_waitlist_get_individual_rate( $div_val, $dist_val );

            if ( strcasecmp( $paid_val, 'Family' ) === 0 ) {
                $was_previously_capped = true;
            }

            // Track prior balances/payments from members who were already active before this change
            $is_newly_promoted = ! empty( $reg['_just_promoted'] );
            if ( ! $is_newly_promoted ) {
                $prior_due_sum               += isset( $reg['balance_due'] ) ? max( 0.00, floatval( $reg['balance_due'] ) ) : 0.00;
                $prior_active_individual_sum += $ind_rate;

                if ( isset( $reg['amount_paid'] ) ) {
                    $had_explicit_paid_tracking = true;
                    $prior_paid_sum            += max( 0.00, floatval( $reg['amount_paid'] ) );
                }
            }

            $active_indices[] = array(
                'index'         => $idx,
                'ind_rate'      => $ind_rate,
                'checked_in'    => ! empty( $reg['checked_in'] ),
                'just_promoted' => $is_newly_promoted,
            );
        }

        $active_count = count( $active_indices );
        if ( $active_count === 0 ) {
            return array(
                'is_family_capped' => false,
                'active_count'     => 0,
                'line_total_due'   => 0.00,
            );
        }

        $raw_sum = 0.00;
        foreach ( $active_indices as $item ) {
            $raw_sum += $item['ind_rate'];
        }

        $is_family_capped  = ( $active_count >= 3 && $raw_sum >= 75.00 );
        $target_line_total = $is_family_capped ? 75.00 : $raw_sum;

        // Calculate payments already collected on this line so nobody is double-billed
        if ( $had_explicit_paid_tracking ) {
            $amount_already_paid = $prior_paid_sum;
        } else {
            $prior_billed_total  = $was_previously_capped ? 75.00 : $prior_active_individual_sum;
            $amount_already_paid = max( 0.00, round( $prior_billed_total - $prior_due_sum, 2 ) );
        }

        $remaining_line_due = max( 0.00, round( $target_line_total - $amount_already_paid, 2 ) );

        if ( $is_family_capped ) {
            $due_holder_pos = 0;
            foreach ( $active_indices as $pos => $item ) {
                if ( $item['just_promoted'] ) {
                    $due_holder_pos = $pos;
                    break;
                }
                if ( ! $item['checked_in'] ) {
                    $due_holder_pos = $pos;
                }
            }

            foreach ( $active_indices as $pos => $item ) {
                $reg_copy = $registrations[ $item['index'] ];
                unset( $reg_copy['_just_promoted'] );

                $reg_copy['paid']        = 'Family';
                $reg_copy['balance_due'] = ( $pos === $due_holder_pos ) ? $remaining_line_due : 0.00;

                sywat_waitlist_persist_record( $reg_copy, $registrations );
            }
        } else {
            $running_paid_credit = $amount_already_paid;

            foreach ( $active_indices as $item ) {
                $reg_copy = $registrations[ $item['index'] ];
                unset( $reg_copy['_just_promoted'] );

                $div_val  = isset( $reg_copy['division'] ) ? $reg_copy['division'] : '';
                $dist_val = isset( $reg_copy['distance'] ) ? $reg_copy['distance'] : '20yd';
                $ind_rate = $item['ind_rate'];

                $reg_copy['paid'] = sywat_waitlist_get_paid_tier( $div_val, $dist_val );

                if ( $item['checked_in'] ) {
                    $reg_copy['balance_due'] = 0.00;
                    $running_paid_credit     = max( 0.00, round( $running_paid_credit - $ind_rate, 2 ) );
                } elseif ( $running_paid_credit >= $ind_rate ) {
                    $reg_copy['balance_due'] = 0.00;
                    $running_paid_credit     = round( $running_paid_credit - $ind_rate, 2 );
                } else {
                    $reg_copy['balance_due'] = round( $ind_rate - $running_paid_credit, 2 );
                    $running_paid_credit     = 0.00;
                }

                sywat_waitlist_persist_record( $reg_copy, $registrations );
            }
        }

        return array(
            'is_family_capped' => $is_family_capped,
            'active_count'     => $active_count,
            'line_total_due'   => $remaining_line_due,
        );
    }
}

/**
 * Build line-scoped human-readable family group labels (line_index => [ family_id => label ]).
 *
 * @param string $tourn_name    Active tournament name.
 * @param array  $t_lines       Tournament shooting lines array.
 * @param array  $registrations Master registration records.
 * @return array                Line-scoped family label lookup map.
 */
if ( ! function_exists( 'sywat_waitlist_build_family_labels_by_line' ) ) {
    function sywat_waitlist_build_family_labels_by_line( $tourn_name, $t_lines, $registrations ) {
        $family_members_by_id = array();
        $labels_by_line       = array();

        if ( ! is_array( $registrations ) ) {
            return $labels_by_line;
        }

        foreach ( $registrations as $reg ) {
            if ( ! is_array( $reg ) || ! isset( $reg['tournament'] ) ) {
                continue;
            }
            if ( strcasecmp( trim( (string) $reg['tournament'] ), trim( (string) $tourn_name ) ) !== 0 ) {
                continue;
            }

            $fid = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
            if ( $fid === '' ) {
                continue;
            }

            $l_idx = function_exists( 'sywat_waitlist_resolve_line_idx' )
                ? sywat_waitlist_resolve_line_idx( $reg, $t_lines )
                : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

            if ( ! isset( $family_members_by_id[ $fid ] ) ) {
                $family_members_by_id[ $fid ] = array(
                    'line_idx'    => $l_idx,
                    'family_name' => ! empty( $reg['family_name'] ) ? trim( (string) $reg['family_name'] ) : '',
                    'names'       => array(),
                );
            }

            if ( ! empty( $reg['name'] ) ) {
                $family_members_by_id[ $fid ]['names'][] = trim( (string) $reg['name'] );
            }
        }

        foreach ( $family_members_by_id as $fid => $info ) {
            $l_idx     = $info['line_idx'];
            $raw_fname = $info['family_name'];
            $names     = $info['names'];

            if ( $raw_fname !== '' && stripos( $raw_fname, 'fam_' ) === false && stripos( $raw_fname, 'Booking' ) === false && strlen( $raw_fname ) > 2 ) {
                $label = $raw_fname;
            } elseif ( ! empty( $names ) ) {
                if ( count( $names ) === 1 ) {
                    $label = $names[0] . ' Group';
                } elseif ( count( $names ) <= 3 ) {
                    $last_names = array_map( function( $n ) {
                        $p = array_values( array_filter( explode( ' ', trim( $n ) ) ) );
                        return ! empty( $p ) ? end( $p ) : $n;
                    }, $names );
                    $label = implode( ' / ', array_unique( $last_names ) ) . ' Group';
                } else {
                    $p     = array_values( array_filter( explode( ' ', trim( $names[0] ) ) ) );
                    $last  = ! empty( $p ) ? end( $p ) : $names[0];
                    $label = $last . ' Family Group (' . count( $names ) . ' Archers)';
                }
            } else {
                $label = 'Family Group (' . substr( $fid, -6 ) . ')';
            }

            if ( ! isset( $labels_by_line[ $l_idx ] ) ) {
                $labels_by_line[ $l_idx ] = array();
            }
            $labels_by_line[ $l_idx ][ $fid ] = $label;
        }

        return $labels_by_line;
    }
}
