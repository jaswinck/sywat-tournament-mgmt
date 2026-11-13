<?php
/**
 * Sub-Module: Waitlist Fee Calculation, Per-Line Family Cap Reconciler & Persistence Helpers
 * Path: includes/waitlist/waitlist-helpers.php
 * Description: Pure business-rule helpers for Youth/Adult rate evaluation, strict per-line $75.00 Family Cap reconciliation when archers are promoted, moved, edited, or removed, and atomic multi-store registration persistence.
 * Compliance: Pure helper layer — zero HTML echo, zero POST handling, zero script/style output, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Determine whether a division/distance qualifies for the Youth ($25.00) tier.
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
 * Enforces SYWAT rules:
 * - Family Cap ($75.00 max) is strictly scoped to a single shooting line.
 * - Requires 3 or more active billable archers in the same family_id on that line
 *   whose combined individual rates equal or exceed $75.00.
 * - Preserves any payments already collected on that line so promoted or moved
 *   archers are only billed the remaining net difference.
 * - Ensures any remaining Waitlist members of that family_id stay at $0.00 due.
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

        $active_indices = array();
        $prior_due_sum  = 0.00;
        $prior_paid_sum = 0.00;
        $had_explicit_paid_tracking = false;
        $was_previously_capped      = false;
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
            // Assign 'Family' paid status to all active billable members; assign remaining net due to
            // the newly promoted / unpaid primary holder and $0.00 to sibling records.
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
            // Below 3 active archers or < $75 raw total: apply individual Youth ($25) / Adult ($30) tiers
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
