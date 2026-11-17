<?php
/**
 * Sub-Module: Waitlist Fee Calculation, Household Family Cap Reconciler & Persistence Helpers
 * Path: includes/waitlist/waitlist-helpers.php
 * Description: Pure business-rule helpers for database-driven Youth/Adult/Pro/Promo rate evaluation, dynamic Household Family Cap reconciliation, household-wide balance resolution, and atomic multi-store registration persistence.
 * Compliance: Pure helper layer — zero HTML echo, zero POST handling, zero script/style output, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Determine whether a division/distance qualifies for the Youth tier
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
 * Return standard individual entry fee using the active Tournament / Round Pricing Profile
 * (with safe fallback to database-configured default rates).
 *
 * @param string $division        Archer age division label.
 * @param string $distance        Optional target distance label.
 * @param string $tournament_name Optional tournament title for round/format resolution.
 * @param string $round_slug      Optional archer-selected round format slug (e.g., 'field', 'field-50').
 * @param string $style           Optional shooting style label (for Pro rate evaluation).
 * @param string $paid_status     Optional existing paid tier ('Field Governor', 'Promo (Free)', etc.).
 * @return float                  Calculated individual entry fee.
 */
if ( ! function_exists( 'sywat_waitlist_get_individual_rate' ) ) {
    function sywat_waitlist_get_individual_rate( $division, $distance = '', $tournament_name = '', $round_slug = '', $style = '', $paid_status = '' ) {
        if ( strcasecmp( trim( (string) $division ), 'No Show' ) === 0 ) {
            return 0.00;
        }

        if ( function_exists( 'sywat_get_archer_individual_fee' ) ) {
            return sywat_get_archer_individual_fee( $tournament_name, $division, $distance, $style, $round_slug, $paid_status );
        }

        $clean_paid = trim( (string) $paid_status );
        if ( strcasecmp( $clean_paid, 'Field Governor' ) === 0 || strcasecmp( $clean_paid, 'Promo (Free)' ) === 0 || stripos( $clean_paid, 'Promo' ) === 0 ) {
            return 0.00;
        }

        return sywat_waitlist_is_youth_division( $division, $distance ) ? 25.00 : 30.00;
    }
}

/**
 * Resolve canonical non-family paid tier ('Field Governor', 'Promo (Free)', 'Youth', or 'Adult').
 *
 * @param string $division      Archer age division label.
 * @param string $distance      Optional target distance label.
 * @param string $existing_paid Optional existing paid status.
 * @param bool   $is_promo_free Optional flag indicating First-Time/Lapsed Shooter promo eligibility.
 * @return string               Canonical paid status label.
 */
if ( ! function_exists( 'sywat_waitlist_get_paid_tier' ) ) {
    function sywat_waitlist_get_paid_tier( $division, $distance = '', $existing_paid = '', $is_promo_free = false ) {
        $clean_paid = trim( (string) $existing_paid );

        if ( $is_promo_free || strcasecmp( $clean_paid, 'Promo (Free)' ) === 0 || stripos( $clean_paid, 'Promo' ) === 0 ) {
            return 'Promo (Free)';
        }

        if ( strcasecmp( $clean_paid, 'Field Governor' ) === 0 ) {
            return 'Field Governor';
        }

        return sywat_waitlist_is_youth_division( $division, $distance ) ? 'Youth' : 'Adult';
    }
}

/**
 * Evaluate household-wide unpaid balance across all active members sharing the same
 * family_id or booking_id (scoped per shooting line or tournament-wide per active Pricing Profile)
 * so no family member can check in while a family balance remains unpaid.
 *
 * @param array $reg           Target archer registration record.
 * @param array $registrations All registrations for the active tournament.
 * @param array $t_lines       Optional tournament lines array for index resolution.
 * @return array               Household balance summary ('has_family', 'family_id', 'group_due', 'individual_due', 'effective_due', 'is_family_capped', 'cap_amount').
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
                'cap_amount'       => 0.00,
            );
        }

        $target_tourn = isset( $reg['tournament'] ) ? trim( (string) $reg['tournament'] ) : '';
        $target_line  = function_exists( 'sywat_waitlist_resolve_line_idx' )
            ? sywat_waitlist_resolve_line_idx( $reg, $t_lines )
            : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

        // Determine cap scope & thresholds from active tournament pricing profile(s)
        $use_tourn_wide  = false;
        $any_cap_enabled = false;
        $effective_cap   = 0.00;
        $effective_min   = 99;

        if ( $target_tourn !== '' && function_exists( 'sywat_get_tournament_pricing_profiles' ) ) {
            $t_profiles = sywat_get_tournament_pricing_profiles( $target_tourn );
            foreach ( $t_profiles as $tp ) {
                if ( ! empty( $tp['family_cap_enabled'] ) ) {
                    $any_cap_enabled = true;
                    $p_cap           = isset( $tp['family_cap_amount'] ) ? floatval( $tp['family_cap_amount'] ) : 75.00;
                    $p_min           = isset( $tp['family_min_archers'] ) ? max( 2, intval( $tp['family_min_archers'] ) ) : 3;
                    if ( $p_cap > $effective_cap ) {
                        $effective_cap = $p_cap;
                    }
                    if ( $p_min < $effective_min ) {
                        $effective_min = $p_min;
                    }
                    if ( isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                        $use_tourn_wide = true;
                    }
                }
            }
        } else {
            $any_cap_enabled = true;
            $effective_cap   = 75.00;
            $effective_min   = 3;
        }

        if ( $effective_min === 99 ) {
            $effective_min = 3;
        }

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

            if ( ! $use_tourn_wide ) {
                $other_line = function_exists( 'sywat_waitlist_resolve_line_idx' )
                    ? sywat_waitlist_resolve_line_idx( $other, $t_lines )
                    : ( isset( $other['line_index'] ) ? (string) $other['line_index'] : '0' );

                if ( $other_line !== $target_line ) {
                    continue;
                }
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

            $o_paid  = isset( $other['paid'] ) ? trim( (string) $other['paid'] ) : '';
            $is_free = ( strcasecmp( $o_paid, 'Field Governor' ) === 0 )
                || ( strcasecmp( $o_paid, 'Promo (Free)' ) === 0 )
                || ( stripos( $o_paid, 'Promo' ) === 0 )
                || ! empty( $other['is_promo_free'] );

            $other_due      = isset( $other['balance_due'] ) ? max( 0.00, floatval( $other['balance_due'] ) ) : 0.00;
            $group_due_sum += $other_due;

            if ( strcasecmp( $o_paid, 'Family' ) === 0 ) {
                $any_family_tag = true;
            }

            if ( ! $is_free ) {
                $active_member_ct++;
                $o_div   = isset( $other['division'] ) ? $other['division'] : '';
                $o_dist  = isset( $other['distance'] ) ? $other['distance'] : '20yd';
                $o_style = isset( $other['style'] ) ? $other['style'] : '';
                $o_round = isset( $other['pricing_profile'] ) ? $other['pricing_profile'] : '';

                $raw_rate_sum += sywat_waitlist_get_individual_rate( $o_div, $o_dist, $target_tourn, $o_round, $o_style, '' );
            }
        }

        $effective_due    = round( max( $ind_due, $group_due_sum ), 2 );
        $is_family_capped = $any_cap_enabled && $any_family_tag && ( $active_member_ct >= $effective_min || ( $effective_cap > 0 && $raw_rate_sum > $effective_cap ) );

        return array(
            'has_family'       => ( $active_member_ct > 1 || $any_family_tag ),
            'family_id'        => $fam_id !== '' ? $fam_id : $book_id,
            'group_due'        => round( $group_due_sum, 2 ),
            'individual_due'   => round( $ind_due, 2 ),
            'effective_due'    => $effective_due,
            'is_family_capped' => $is_family_capped,
            'cap_amount'       => round( $effective_cap, 2 ),
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
 * members of a household group using the active Pricing Profile's Family Cap rules
 * (delegating to sywat_recalculate_household_fees() for single-source-of-truth math).
 *
 * @param string $tourn_name    Active tournament name.
 * @param string $line_idx      Target shooting line index.
 * @param string $family_id     Family group identifier.
 * @param array  $registrations In-memory registrations list passed by reference.
 * @param array  $t_lines       Optional tournament lines config for index resolution.
 * @return array                Summary array ('is_family_capped', 'active_count', 'line_total_due', 'cap_amount').
 */
if ( ! function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
    function sywat_waitlist_recalculate_line_family_fees( $tourn_name, $line_idx, $family_id, &$registrations, $t_lines = array() ) {
        if ( function_exists( 'sywat_recalculate_household_fees' ) ) {
            return sywat_recalculate_household_fees( $tourn_name, $line_idx, $family_id, $registrations, $t_lines );
        }

        return array(
            'is_family_capped' => false,
            'active_count'     => 0,
            'line_total_due'   => 0.00,
            'cap_amount'       => 0.00,
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
