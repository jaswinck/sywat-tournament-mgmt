<?php
/**
 * Sub-Module: Check-In Desk Actions Handler
 * Path: includes/checkin/checkin-actions.php
 * Description: Handles Check-In Desk synchronous POST actions and exposes shared, transaction-safe check-in / undo-check-in processors (sywat_desk_process_checkin_submission and sywat_desk_process_undo_checkin_submission) for both traditional POST fallback and the admin-ajax.php JSON endpoint. Preserves explicit manual Balance Due ($) overrides in save_edit without being overwritten by household cap recalculation while propagating $0.00 balance clearances across linked household/booking members. Includes dedicated Waitlist Modal delegation (promote, multi-select move, remove) with live tourn_registrations sync, line closure/reopening (preserving waitlisted records), walk-in registration with round/pricing profile selection, indexed First-Time/Lapsed Shooter promoter evaluation, server-side canonical name enforcement (Member DB + 18-month TFAA history) while preserving intentional capitalization edits (e.g., "McBride"), First+Last same-line duplicate lockout, database-driven style/division rule enforcement and dynamic Household Family Cap evaluation (line or tournament scope), household-wide check-in and edit balance verification/clearance inside atomic MySQL transactions, and archer edits/deletions with Family Cap reconciliation.
 * Compliance: Pure action layer — zero HTML echo at include time, zero style tags, indexed non-historical registration lookups, atomic MySQL transaction batching, DRY shared POST/AJAX processors, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Synchronize $data['tourn_registrations'] from $data['registrations'] for the active
 * tournament and selected shooting line filter.
 *
 * @param array $data Aggregated Check-In Desk dataset passed by reference.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_sync_tourn_registrations' ) ) {
    function sywat_desk_sync_tourn_registrations( &$data ) {
        if ( ! isset( $data['tourn_registrations'] ) || ! is_array( $data['tourn_registrations'] ) ) {
            return;
        }

        $registrations       = isset( $data['registrations'] ) && is_array( $data['registrations'] ) ? $data['registrations'] : array();
        $selected_tournament = isset( $data['selected_tournament'] ) ? (string) $data['selected_tournament'] : '';
        $lines               = isset( $data['lines'] ) && is_array( $data['lines'] ) ? $data['lines'] : array();
        $sel_line            = isset( $data['selected_line_idx'] ) ? (string) $data['selected_line_idx'] : 'all';

        $data['tourn_registrations'] = array_values( array_filter( $registrations, function( $reg ) use ( $selected_tournament, $sel_line, $lines ) {
            if ( ! is_array( $reg ) || ! isset( $reg['tournament'] ) || strcasecmp( trim( (string) $reg['tournament'] ), trim( $selected_tournament ) ) !== 0 ) {
                return false;
            }
            if ( $sel_line !== 'all' && $sel_line !== '' ) {
                $r_lidx = function_exists( 'sywat_desk_resolve_line_idx' )
                    ? sywat_desk_resolve_line_idx( $reg, $lines )
                    : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );
                if ( (string) $r_lidx !== $sel_line ) {
                    return false;
                }
            }
            return true;
        } ) );
    }
}

/**
 * Normalize "Last, First" comma-formatted names into "First Last" order.
 *
 * @param string $raw_name Raw archer name input.
 * @return string          Normalized "First Last" string.
 */
if ( ! function_exists( 'sywat_desk_normalize_comma_name' ) ) {
    function sywat_desk_normalize_comma_name( $raw_name ) {
        $clean_name = trim( preg_replace( '/[[:space:]]+/', ' ', (string) $raw_name ) );
        if ( $clean_name !== '' && strpos( $clean_name, ',' ) !== false ) {
            $comma_parts = array_map( 'trim', explode( ',', $clean_name, 2 ) );
            if ( count( $comma_parts ) === 2 && $comma_parts[0] !== '' && $comma_parts[1] !== '' ) {
                $clean_name = trim( preg_replace( '/[[:space:]]+/', ' ', $comma_parts[1] . ' ' . $comma_parts[0] ) );
            }
        }
        return $clean_name;
    }
}

/**
 * Enforce official Member DB / TFAA canonical archer names while preserving
 * intentional mixed-case capitalization edits (e.g., "McBride").
 *
 * @param string $raw_name         Raw archer name input.
 * @param string $tourn_start_date Optional YYYY-MM-DD tournament start date.
 * @param string $email            Optional email hint.
 * @param string $phone            Optional phone hint.
 * @return string                  Canonicalized archer name.
 */
if ( ! function_exists( 'sywat_desk_canonicalize_archer_name' ) ) {
    function sywat_desk_canonicalize_archer_name( $raw_name, $tourn_start_date = '', $email = '', $phone = '' ) {
        $clean_name = sywat_desk_normalize_comma_name( $raw_name );
        if ( $clean_name === '' ) {
            return '';
        }

        if ( function_exists( 'sywat_resolve_archer_intake_profile' ) ) {
            $resolved = sywat_resolve_archer_intake_profile(
                $clean_name,
                $tourn_start_date,
                array(
                    'email' => $email,
                    'phone' => $phone,
                )
            );
            if ( ! empty( $resolved['canonical_name'] ) ) {
                $canonical = trim( (string) $resolved['canonical_name'] );
                if ( strcasecmp( $canonical, $clean_name ) === 0 ) {
                    return $clean_name;
                }
                return $canonical;
            }
        }

        return $clean_name;
    }
}

/**
 * Check whether an archer already has an active/waitlisted registration on the target line.
 *
 * @param array  $registrations       Tournament registrations list.
 * @param string $selected_tournament Active tournament name.
 * @param array  $lines               Configured shooting lines map.
 * @param string $target_line_idx     Target line index.
 * @param string $candidate_name      Candidate archer name.
 * @param string $exclude_reg_id      Optional registration ID to exclude (for self-edits).
 * @return string                     Matching duplicate archer name, or empty string if none.
 */
if ( ! function_exists( 'sywat_desk_find_same_line_duplicate' ) ) {
    function sywat_desk_find_same_line_duplicate( $registrations, $selected_tournament, $lines, $target_line_idx, $candidate_name, $exclude_reg_id = '' ) {
        $build_keys = function( $name ) {
            if ( function_exists( 'sywat_normalize_archer_match_keys' ) ) {
                return sywat_normalize_archer_match_keys( $name );
            }
            $norm  = strtolower( trim( preg_replace( '/[[:space:]]+/', ' ', str_replace( '.', ' ', (string) $name ) ) ) );
            $parts = $norm !== '' ? explode( ' ', $norm ) : array();
            return array(
                'exact' => $norm,
                'fl'    => ( count( $parts ) >= 2 ) ? ( reset( $parts ) . '___' . end( $parts ) ) : $norm,
            );
        };

        $cand_keys = $build_keys( $candidate_name );
        if ( $cand_keys['exact'] === '' || ! is_array( $registrations ) ) {
            return '';
        }

        $target_line_str = (string) $target_line_idx;
        $has_fl_pair     = ( $cand_keys['fl'] !== '' && strpos( $cand_keys['fl'], '___' ) !== false );

        foreach ( $registrations as $other ) {
            if ( ! is_array( $other ) || empty( $other['name'] ) ) {
                continue;
            }
            if ( $exclude_reg_id !== '' && isset( $other['id'] ) && (string) $other['id'] === (string) $exclude_reg_id ) {
                continue;
            }
            if ( ! isset( $other['tournament'] ) || strcasecmp( trim( (string) $other['tournament'] ), trim( (string) $selected_tournament ) ) !== 0 ) {
                continue;
            }
            $o_status = isset( $other['status'] ) ? trim( (string) $other['status'] ) : '';
            if ( strcasecmp( $o_status, 'Cancelled' ) === 0 ) {
                continue;
            }
            $o_line = function_exists( 'sywat_desk_resolve_line_idx' )
                ? sywat_desk_resolve_line_idx( $other, $lines )
                : ( isset( $other['line_index'] ) ? (string) $other['line_index'] : '0' );
            if ( (string) $o_line !== $target_line_str ) {
                continue;
            }

            $other_keys = $build_keys( $other['name'] );
            if ( $other_keys['exact'] === $cand_keys['exact'] || ( $has_fl_pair && $other_keys['fl'] === $cand_keys['fl'] ) ) {
                return trim( (string) $other['name'] );
            }
        }

        return '';
    }
}

/**
 * Resolve and enforce database-driven shooting style and age division rules.
 *
 * @param string $raw_style    Submitted shooting style.
 * @param string $raw_division Submitted age division.
 * @return array{style: string, division: string}
 */
if ( ! function_exists( 'sywat_desk_resolve_style_and_division_for_action' ) ) {
    function sywat_desk_resolve_style_and_division_for_action( $raw_style, $raw_division ) {
        $clean_div   = trim( (string) $raw_division );
        $clean_style = trim( (string) $raw_style );

        if ( $clean_div !== '' && strcasecmp( $clean_div, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
            $clean_div = sywat_shooting_styles_normalize_division( $clean_div );
        }
        if ( $clean_style !== '' && strcasecmp( $clean_style, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_style' ) ) {
            $clean_style = sywat_shooting_styles_normalize_style( $clean_style );
        }

        if ( strcasecmp( $clean_div, 'No Show' ) === 0 || strcasecmp( $clean_style, 'No Show' ) === 0 ) {
            return array(
                'style'    => 'No Show',
                'division' => $clean_div,
            );
        }

        if ( function_exists( 'sywat_resolve_style_and_division' ) ) {
            $resolved = sywat_resolve_style_and_division( $clean_style, $clean_div );
            if ( is_array( $resolved ) ) {
                return array(
                    'style'    => ! empty( $resolved['style'] ) ? $resolved['style'] : $clean_style,
                    'division' => ! empty( $resolved['division'] ) ? $resolved['division'] : $clean_div,
                );
            }
        }

        // Legacy fallback if shooting styles engine is unavailable
        if ( stripos( $clean_style, 'Genesis' ) !== false && $clean_div !== 'Cub (8 - 11)' ) {
            $clean_style = 'FS – Freestyle';
        }

        $is_pro_fsl_trad = (
            stripos( $clean_style, 'FS Pro' ) !== false ||
            stripos( $clean_style, 'FSL –' ) !== false ||
            stripos( $clean_style, 'TRAD' ) !== false ||
            stripos( $clean_style, 'Traditional' ) !== false
        );
        $allowed_adult = array( 'Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)' );

        if ( $is_pro_fsl_trad && ! in_array( $clean_div, $allowed_adult, true ) ) {
            $clean_style = 'FS – Freestyle';
        }

        $is_lb = ( stripos( $clean_style, 'LB' ) !== false || stripos( $clean_style, 'Longbow' ) !== false );
        if ( $is_lb && $clean_div !== 'Adult (18 - 49)' ) {
            $clean_style = 'FS – Freestyle';
        }

        return array(
            'style'    => $clean_style,
            'division' => $clean_div,
        );
    }
}

/**
 * Clear unpaid balance_due across all active members sharing the same family_id or booking_id
 * when a household/group payment is collected at check-in or marked Paid in edit.
 *
 * @param array  $source_reg          Primary registration record.
 * @param array  $registrations       In-memory registrations list passed by reference.
 * @param string $selected_tournament Active tournament name.
 * @param string $active_t_slug       Sanitized tournament slug.
 * @param array  $lines               Tournament shooting lines array.
 * @param bool   $use_tourn_wide_cap  Whether tournament-wide family cap scope applies.
 * @return array<int, string>         List of linked registration IDs whose balances were updated.
 */
if ( ! function_exists( 'sywat_desk_clear_linked_group_balances' ) ) {
    function sywat_desk_clear_linked_group_balances( $source_reg, &$registrations, $selected_tournament, $active_t_slug, $lines, $use_tourn_wide_cap ) {
        $cleared_ids = array();
        if ( ! is_array( $source_reg ) || ! is_array( $registrations ) ) {
            return $cleared_ids;
        }

        $fam_id      = ! empty( $source_reg['family_id'] ) ? trim( (string) $source_reg['family_id'] ) : '';
        $book_id     = ! empty( $source_reg['booking_id'] ) ? trim( (string) $source_reg['booking_id'] ) : '';
        $source_id   = isset( $source_reg['id'] ) ? (string) $source_reg['id'] : '';
        $target_line = function_exists( 'sywat_desk_resolve_line_idx' )
            ? sywat_desk_resolve_line_idx( $source_reg, $lines )
            : ( isset( $source_reg['line_index'] ) ? (string) $source_reg['line_index'] : '0' );

        if ( $fam_id === '' && $book_id === '' ) {
            return $cleared_ids;
        }

        foreach ( $registrations as &$r ) {
            if ( ! is_array( $r ) || ( isset( $r['id'] ) && (string) $r['id'] === $source_id ) ) {
                continue;
            }
            if ( ! isset( $r['tournament'] ) || strcasecmp( trim( (string) $r['tournament'] ), trim( (string) $selected_tournament ) ) !== 0 ) {
                continue;
            }
            $r_status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
            if ( strcasecmp( $r_status, 'Waitlist' ) === 0 || strcasecmp( $r_status, 'Cancelled' ) === 0 ) {
                continue;
            }

            $r_fam  = ! empty( $r['family_id'] ) ? trim( (string) $r['family_id'] ) : '';
            $r_book = ! empty( $r['booking_id'] ) ? trim( (string) $r['booking_id'] ) : '';
            $r_line = function_exists( 'sywat_desk_resolve_line_idx' )
                ? sywat_desk_resolve_line_idx( $r, $lines )
                : ( isset( $r['line_index'] ) ? (string) $r['line_index'] : '0' );

            $is_book_match = ( $book_id !== '' && $r_book === $book_id );
            $is_fam_match  = ( $fam_id !== '' && $r_fam === $fam_id ) && ( $use_tourn_wide_cap || $r_line === $target_line );

            if ( $is_book_match || $is_fam_match ) {
                $r['balance_due'] = 0;
                if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                    sywat_waitlist_persist_record( $r, $registrations );
                } else {
                    update_option( 'sywat_t_' . $active_t_slug . '_' . $r['id'], $r, false );
                }
                if ( ! empty( $r['id'] ) ) {
                    $cleared_ids[] = (string) $r['id'];
                }
            }
        }
        unset( $r );

        return $cleared_ids;
    }
}

/**
 * Shared single-source-of-truth processor for completing an archer check-in.
 * Enforces household-wide unpaid family balance verification, canonical name standardization
 * (preserving intentional casing edits), same-line duplicate prevention, database-driven
 * style/division rules, and atomic MySQL transaction persistence for primary + linked family records.
 *
 * @param array      $data      Aggregated Check-In Desk dataset passed by reference.
 * @param array|null $post_data Optional unslashed/raw POST array (defaults to $_POST).
 * @return array{
 *     success: bool,
 *     reg_id: string,
 *     updated_ids: array<int, string>,
 *     archer_name: string,
 *     message: string,
 *     notice_html: string
 * }
 */
if ( ! function_exists( 'sywat_desk_process_checkin_submission' ) ) {
    function sywat_desk_process_checkin_submission( &$data, $post_data = null ) {
        global $wpdb;

        $src = is_array( $post_data ) ? $post_data : $_POST;

        $active_t_slug       = isset( $data['active_t_slug'] ) ? (string) $data['active_t_slug'] : '';
        $selected_tournament = isset( $data['selected_tournament'] ) ? (string) $data['selected_tournament'] : '';
        $selected_t_data     = ! empty( $data['selected_tournament_data'] ) ? $data['selected_tournament_data'] : $selected_tournament;
        $tourn_start_date    = ( is_array( $selected_t_data ) && ! empty( $selected_t_data['start_date'] ) ) ? trim( (string) $selected_t_data['start_date'] ) : '';
        $registrations       = &$data['registrations'];
        $lines               = isset( $data['lines'] ) && is_array( $data['lines'] ) ? $data['lines'] : array();

        $t_profiles       = isset( $data['pricing_profiles'] ) && is_array( $data['pricing_profiles'] )
            ? $data['pricing_profiles']
            : ( function_exists( 'sywat_get_tournament_pricing_profiles' ) ? sywat_get_tournament_pricing_profiles( $selected_t_data ) : array() );
        $default_prof_key = ! empty( $data['default_pricing_profile'] )
            ? (string) $data['default_pricing_profile']
            : ( ! empty( $t_profiles ) ? (string) key( $t_profiles ) : 'indoor' );

        $use_tourn_wide_cap = false;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                $use_tourn_wide_cap = true;
                break;
            }
        }

        $target_id = isset( $src['reg_id'] ) ? sanitize_text_field( wp_unslash( $src['reg_id'] ) ) : '';
        if ( $target_id === '' || $active_t_slug === '' ) {
            return array(
                'success'     => false,
                'reg_id'      => $target_id,
                'updated_ids' => array(),
                'archer_name' => '',
                'message'     => 'Missing registration identifier. Please refresh and try again.',
                'notice_html' => '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Check-In Failed!</strong> Missing registration identifier. Please refresh and try again.</div>',
            );
        }

        $opt_key = 'sywat_t_' . $active_t_slug . '_' . $target_id;
        $reg     = get_option( $opt_key );
        if ( ! is_array( $reg ) ) {
            return array(
                'success'     => false,
                'reg_id'      => $target_id,
                'updated_ids' => array(),
                'archer_name' => '',
                'message'     => 'Registration record not found. Please refresh the roster.',
                'notice_html' => '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Check-In Failed!</strong> Registration record not found. Please refresh the roster.</div>',
            );
        }

        $due_amt  = isset( $reg['balance_due'] ) ? floatval( $reg['balance_due'] ) : 0.00;
        $verified = ! empty( $src['checkin_payment_verified'] );

        $household_info = function_exists( 'sywat_get_household_balance_info' )
            ? sywat_get_household_balance_info( $reg, $registrations, $lines )
            : null;

        $effective_due  = $household_info ? floatval( $household_info['effective_due'] ) : $due_amt;
        $is_family_paid = ( isset( $reg['paid'] ) && strcasecmp( $reg['paid'], 'Family' ) === 0 )
            || ( ! empty( $reg['rate_type'] ) && stripos( (string) $reg['rate_type'], 'family' ) !== false );

        if ( ! $household_info && $is_family_paid ) {
            $fam_id   = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
            $book_id  = ! empty( $reg['booking_id'] ) ? trim( (string) $reg['booking_id'] ) : '';
            $reg_line = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $reg, $lines ) : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

            if ( $fam_id !== '' || $book_id !== '' ) {
                foreach ( $registrations as $other_r ) {
                    $o_status = isset( $other_r['status'] ) ? trim( (string) $other_r['status'] ) : '';
                    if ( strcasecmp( $o_status, 'Waitlist' ) === 0 || strcasecmp( $o_status, 'Cancelled' ) === 0 ) {
                        continue;
                    }
                    $o_line = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $other_r, $lines ) : ( isset( $other_r['line_index'] ) ? (string) $other_r['line_index'] : '0' );
                    if ( ! $use_tourn_wide_cap && $o_line !== $reg_line ) {
                        continue;
                    }
                    $o_fam  = ! empty( $other_r['family_id'] ) ? trim( (string) $other_r['family_id'] ) : '';
                    $o_book = ! empty( $other_r['booking_id'] ) ? trim( (string) $other_r['booking_id'] ) : '';
                    if ( ( $fam_id !== '' && $o_fam === $fam_id ) || ( $book_id !== '' && $o_book === $book_id ) ) {
                        $effective_due = max( $effective_due, isset( $other_r['balance_due'] ) ? floatval( $other_r['balance_due'] ) : 0 );
                    }
                }
            }
        }

        $is_family_balance = $is_family_paid || ( $household_info && ! empty( $household_info['has_family'] ) );

        if ( $effective_due > 0 && ! $verified ) {
            if ( $is_family_balance ) {
                $msg = 'Household has an unpaid Family Cap balance ($' . number_format( $effective_due, 2 ) . '). Please collect payment onsite and check the verification box.';
                return array(
                    'success'     => false,
                    'reg_id'      => $target_id,
                    'updated_ids' => array(),
                    'archer_name' => isset( $reg['name'] ) ? (string) $reg['name'] : '',
                    'message'     => $msg,
                    'notice_html' => '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Check-In Blocked!</strong> ' . esc_html( $msg ) . '</div>',
                );
            }
            $msg = 'Archer has an unpaid balance ($' . number_format( $effective_due, 2 ) . '). Please see event staff for payment and check the verification box.';
            return array(
                'success'     => false,
                'reg_id'      => $target_id,
                'updated_ids' => array(),
                'archer_name' => isset( $reg['name'] ) ? (string) $reg['name'] : '',
                'message'     => $msg,
                'notice_html' => '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Check-In Blocked!</strong> ' . esc_html( $msg ) . '</div>',
            );
        }

        $orig_checkin_name  = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : '';
        $raw_checkin_name   = ! empty( $src['checkin_name'] )
            ? trim( preg_replace( '/[[:space:]]+/', ' ', sanitize_text_field( wp_unslash( $src['checkin_name'] ) ) ) )
            : $orig_checkin_name;
        $clean_checkin_name = sywat_desk_normalize_comma_name( $raw_checkin_name );

        $resolved_name = ( $clean_checkin_name !== '' && strcasecmp( $clean_checkin_name, $orig_checkin_name ) !== 0 )
            ? sywat_desk_canonicalize_archer_name(
                $clean_checkin_name,
                $tourn_start_date,
                isset( $reg['email'] ) ? (string) $reg['email'] : '',
                isset( $reg['phone'] ) ? (string) $reg['phone'] : ''
            )
            : ( $clean_checkin_name !== '' ? $clean_checkin_name : $orig_checkin_name );

        $reg_line_idx = function_exists( 'sywat_desk_resolve_line_idx' )
            ? sywat_desk_resolve_line_idx( $reg, $lines )
            : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

        $existing_dup_name = sywat_desk_find_same_line_duplicate( $registrations, $selected_tournament, $lines, $reg_line_idx, $resolved_name, $target_id );
        if ( $existing_dup_name !== '' ) {
            $plain_msg = 'Official shooter ' . $resolved_name . ' already has a registration on this shooting line'
                . ( strcasecmp( $existing_dup_name, $resolved_name ) !== 0 ? ' (listed as "' . $existing_dup_name . '")' : '' )
                . '. Please remove the duplicate row before completing check-in.';
            $html_msg = '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Check-In Blocked!</strong> Official shooter <strong>' . esc_html( $resolved_name ) . '</strong> already has a registration on this shooting line'
                . ( strcasecmp( $existing_dup_name, $resolved_name ) !== 0 ? ' (listed as <strong>' . esc_html( $existing_dup_name ) . '</strong>)' : '' )
                . '. Please remove the duplicate row before completing check-in.</div>';
            return array(
                'success'     => false,
                'reg_id'      => $target_id,
                'updated_ids' => array(),
                'archer_name' => $resolved_name,
                'message'     => $plain_msg,
                'notice_html' => $html_msg,
            );
        }

        $raw_division = isset( $src['checkin_division'] ) ? sanitize_text_field( wp_unslash( $src['checkin_division'] ) ) : '';
        $raw_style    = isset( $src['checkin_style'] ) ? sanitize_text_field( wp_unslash( $src['checkin_style'] ) ) : '';
        $raw_gender   = isset( $src['checkin_gender'] ) ? sanitize_text_field( wp_unslash( $src['checkin_gender'] ) ) : '';
        $pay_opt      = isset( $src['checkin_pay_option'] ) ? sanitize_text_field( wp_unslash( $src['checkin_pay_option'] ) ) : 'Individual';
        $round_slug   = isset( $src['checkin_pricing_profile'] )
            ? sanitize_title( wp_unslash( $src['checkin_pricing_profile'] ) )
            : ( ! empty( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : $default_prof_key );

        if ( $round_slug === '' || ( ! empty( $t_profiles ) && ! isset( $t_profiles[ $round_slug ] ) ) ) {
            $legacy_rp = ( $round_slug !== '' && function_exists( 'sywat_find_pricing_profile' ) ) ? sywat_find_pricing_profile( $round_slug ) : null;
            if ( ! is_array( $legacy_rp ) ) {
                $round_slug = $default_prof_key;
            }
        }

        $resolved_sd = sywat_desk_resolve_style_and_division_for_action( $raw_style, $raw_division );
        $division    = $resolved_sd['division'];
        $style       = $resolved_sd['style'];
        $distance    = ( strcasecmp( $division, 'No Show' ) === 0 )
            ? 'Cub'
            : ( function_exists( 'sywat_shooting_styles_get_division_distance' )
                ? sywat_shooting_styles_get_division_distance( $division )
                : ( ( $division === 'Cub (8 - 11)' ) ? 'Cub' : '20yd' ) );

        $legacy_youth_divs = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' );
        $paid_tier         = ( strcasecmp( $division, 'No Show' ) === 0 )
            ? 'No Show'
            : ( function_exists( 'sywat_shooting_styles_get_division_rate_tier' )
                ? sywat_shooting_styles_get_division_rate_tier( $division )
                : ( in_array( $division, $legacy_youth_divs, true ) ? 'Youth' : 'Adult' ) );

        if ( $resolved_name !== '' ) {
            $reg['name'] = $resolved_name;
        }

        if ( $verified && $effective_due > 0 ) {
            $prior_paid         = isset( $reg['amount_paid'] ) ? floatval( $reg['amount_paid'] ) : 0.00;
            $reg['amount_paid'] = round( $prior_paid + $effective_due, 2 );
        }

        $reg['distance']        = $distance;
        $reg['division']        = $division;
        $reg['pricing_profile'] = $round_slug;
        $reg['gender']          = $raw_gender;
        $reg['style']           = $style;

        if ( strcasecmp( $pay_opt, 'Promo (Free)' ) === 0 || stripos( $pay_opt, 'Promo' ) === 0 ) {
            $reg['paid']          = 'Promo (Free)';
            $reg['is_promo_free'] = true;
        } elseif ( $pay_opt === 'Family' ) {
            $reg['paid']          = 'Family';
            $reg['is_promo_free'] = false;
        } elseif ( $pay_opt === 'Field Governor' ) {
            $reg['paid']          = 'Field Governor';
            $reg['is_promo_free'] = false;
        } else {
            $reg['paid']          = $paid_tier;
            $reg['is_promo_free'] = false;
        }

        $reg['checked_in']  = 1;
        $reg['balance_due'] = 0;

        $existing_families_by_line = isset( $data['existing_families_by_line'] ) ? $data['existing_families_by_line'] : array();
        $existing_families         = isset( $data['existing_families'] ) ? $data['existing_families'] : array();

        if ( $reg['paid'] === 'Family' ) {
            $sel_fam_id       = isset( $src['checkin_family_group_id'] ) ? sanitize_text_field( wp_unslash( $src['checkin_family_group_id'] ) ) : '';
            $line_idx         = isset( $reg['line_index'] ) ? $reg['line_index'] : '0';
            $checkin_families = isset( $existing_families_by_line[ $line_idx ] ) ? $existing_families_by_line[ $line_idx ] : $existing_families;

            if ( ! empty( $sel_fam_id ) ) {
                $reg['family_id']   = $sel_fam_id;
                $reg['family_name'] = isset( $checkin_families[ $sel_fam_id ] ) ? $checkin_families[ $sel_fam_id ] : 'Family Group';
            } elseif ( empty( $reg['family_id'] ) ) {
                $reg['family_id']   = 'fam_' . $active_t_slug . '_l' . sanitize_title( $line_idx ) . '_' . sanitize_title( $reg['name'] ) . '_' . substr( md5( uniqid( '', true ) ), 0, 4 );
                $reg['family_name'] = $reg['name'] . ' (Family Cap)';
            }
        }

        $updated_ids = array( $target_id );

        $wpdb->query( 'START TRANSACTION' );
        try {
            if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                sywat_waitlist_persist_record( $reg, $registrations );
            } else {
                update_option( $opt_key, $reg, false );
                foreach ( $registrations as &$r ) {
                    if ( isset( $r['id'] ) && $r['id'] === $target_id ) {
                        $r = $reg;
                        break;
                    }
                }
                unset( $r );
            }

            $cleared_linked_ids = sywat_desk_clear_linked_group_balances(
                $reg,
                $registrations,
                $selected_tournament,
                $active_t_slug,
                $lines,
                $use_tourn_wide_cap
            );
            foreach ( $cleared_linked_ids as $cid ) {
                if ( ! in_array( $cid, $updated_ids, true ) ) {
                    $updated_ids[] = $cid;
                }
            }

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            throw $e;
        }

        sywat_desk_sync_tourn_registrations( $data );

        $disp_name = function_exists( 'sywat_format_name_last_first' )
            ? sywat_format_name_last_first( $reg['name'] )
            : $reg['name'];

        return array(
            'success'     => true,
            'reg_id'      => $target_id,
            'updated_ids' => $updated_ids,
            'archer_name' => $disp_name,
            'message'     => 'Checked in ' . $disp_name . '.',
            'notice_html' => '',
        );
    }
}

/**
 * Shared single-source-of-truth processor for undoing an archer check-in.
 *
 * @param array      $data      Aggregated Check-In Desk dataset passed by reference.
 * @param array|null $post_data Optional unslashed/raw POST array (defaults to $_POST).
 * @return array{
 *     success: bool,
 *     reg_id: string,
 *     updated_ids: array<int, string>,
 *     archer_name: string,
 *     message: string,
 *     notice_html: string
 * }
 */
if ( ! function_exists( 'sywat_desk_process_undo_checkin_submission' ) ) {
    function sywat_desk_process_undo_checkin_submission( &$data, $post_data = null ) {
        $src           = is_array( $post_data ) ? $post_data : $_POST;
        $active_t_slug = isset( $data['active_t_slug'] ) ? (string) $data['active_t_slug'] : '';
        $registrations = &$data['registrations'];

        $target_id = isset( $src['reg_id'] ) ? sanitize_text_field( wp_unslash( $src['reg_id'] ) ) : '';
        if ( $target_id === '' || $active_t_slug === '' ) {
            return array(
                'success'     => false,
                'reg_id'      => $target_id,
                'updated_ids' => array(),
                'archer_name' => '',
                'message'     => 'Missing registration identifier.',
                'notice_html' => '',
            );
        }

        $opt_key = 'sywat_t_' . $active_t_slug . '_' . $target_id;
        $reg     = get_option( $opt_key );
        if ( ! is_array( $reg ) ) {
            return array(
                'success'     => false,
                'reg_id'      => $target_id,
                'updated_ids' => array(),
                'archer_name' => '',
                'message'     => 'Registration record not found.',
                'notice_html' => '',
            );
        }

        $reg['checked_in'] = 0;

        if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
            sywat_waitlist_persist_record( $reg, $registrations );
        } else {
            update_option( $opt_key, $reg, false );
            foreach ( $registrations as &$r ) {
                if ( isset( $r['id'] ) && $r['id'] === $target_id ) {
                    $r['checked_in'] = 0;
                    break;
                }
            }
            unset( $r );
        }

        sywat_desk_sync_tourn_registrations( $data );

        $disp_name = function_exists( 'sywat_format_name_last_first' )
            ? sywat_format_name_last_first( isset( $reg['name'] ) ? $reg['name'] : '' )
            : ( isset( $reg['name'] ) ? (string) $reg['name'] : '' );

        return array(
            'success'     => true,
            'reg_id'      => $target_id,
            'updated_ids' => array( $target_id ),
            'archer_name' => $disp_name,
            'message'     => 'Reverted check-in for ' . $disp_name . '.',
            'notice_html' => '',
        );
    }
}

/**
 * Handles desk POST actions including waitlist promotion/moves/removal, walk-in registration,
 * check-in updates, line closure, and edits.
 *
 * @param array $data Aggregated Check-In Desk dataset passed by reference.
 * @return string     Alert HTML notice markup.
 */
if ( ! function_exists( 'sywat_desk_handle_actions' ) ) {
    function sywat_desk_handle_actions( &$data ) {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            return '';
        }

        $is_valid_nonce = ( isset( $_POST['sywat_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ), 'sywat_desk_action' ) );
        $action         = isset( $_POST['sywat_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) ) : '';

        if ( $action && ! $is_valid_nonce && $action !== 'cancel' ) {
            return '<div class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
        }

        if ( ! $is_valid_nonce ) {
            return '';
        }

        $active_t_slug       = $data['active_t_slug'];
        $selected_tournament = $data['selected_tournament'];
        $selected_t_data     = ! empty( $data['selected_tournament_data'] ) ? $data['selected_tournament_data'] : $selected_tournament;
        $tourn_start_date    = ( is_array( $selected_t_data ) && ! empty( $selected_t_data['start_date'] ) ) ? trim( (string) $selected_t_data['start_date'] ) : '';
        $closed_lines_key    = $data['closed_lines_key'];
        $registrations       = &$data['registrations'];
        $lines               = $data['lines'];
        $tournaments_map     = ! empty( $data['tournaments_full'] ) ? $data['tournaments_full'] : ( isset( $data['tournaments'] ) ? $data['tournaments'] : array() );
        $script_optout_attrs = defined( 'SYWAT_SCRIPT_OPTOUT_ATTRS' ) ? SYWAT_SCRIPT_OPTOUT_ATTRS : '';

        // Resolve offered Pricing & Round Profiles for this tournament
        $t_profiles       = isset( $data['pricing_profiles'] ) && is_array( $data['pricing_profiles'] )
            ? $data['pricing_profiles']
            : ( function_exists( 'sywat_get_tournament_pricing_profiles' ) ? sywat_get_tournament_pricing_profiles( $selected_t_data ) : array() );
        $default_prof_key = ! empty( $data['default_pricing_profile'] )
            ? (string) $data['default_pricing_profile']
            : ( ! empty( $t_profiles ) ? (string) key( $t_profiles ) : 'indoor' );
        $default_prof     = isset( $t_profiles[ $default_prof_key ] )
            ? $t_profiles[ $default_prof_key ]
            : ( ! empty( $t_profiles ) ? reset( $t_profiles ) : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() ) );

        $use_tourn_wide_cap = false;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                $use_tourn_wide_cap = true;
                break;
            }
        }

        // Delegate Waitlist Modal actions (promote_waitlist, move_waitlist_line, modal delete_shooter) to central Waitlist engine
        if ( function_exists( 'sywat_waitlist_is_supported_action' ) && sywat_waitlist_is_supported_action( $action ) && function_exists( 'sywat_waitlist_dispatch_post_action' ) ) {
            $waitlist_notice = sywat_waitlist_dispatch_post_action( $tournaments_map, $registrations );
            sywat_desk_sync_tourn_registrations( $data );
            return $waitlist_notice;
        }

        $existing_families_by_line = isset( $data['existing_families_by_line'] ) ? $data['existing_families_by_line'] : array();
        $existing_families         = $data['existing_families'];
        $legacy_youth_divs         = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' );

        $resolve_paid_tier = function( $division ) use ( $legacy_youth_divs ) {
            $clean_div = trim( (string) $division );
            if ( strcasecmp( $clean_div, 'No Show' ) === 0 ) {
                return 'No Show';
            }
            if ( function_exists( 'sywat_shooting_styles_get_division_rate_tier' ) ) {
                return sywat_shooting_styles_get_division_rate_tier( $clean_div );
            }
            return in_array( $clean_div, $legacy_youth_divs, true ) ? 'Youth' : 'Adult';
        };

        $resolve_division_distance = function( $division ) {
            $clean_div = trim( (string) $division );
            if ( strcasecmp( $clean_div, 'No Show' ) === 0 ) {
                return 'Cub';
            }
            if ( function_exists( 'sywat_shooting_styles_get_division_distance' ) ) {
                return sywat_shooting_styles_get_division_distance( $clean_div );
            }
            return ( $clean_div === 'Cub (8 - 11)' ) ? 'Cub' : '20yd';
        };

        $calculate_individual_fee = function( $division, $style = '', $round_slug = '' ) use ( $resolve_paid_tier, $resolve_division_distance, $selected_t_data, $default_prof_key ) {
            $clean_div = trim( (string) $division );
            if ( $clean_div === '' || strcasecmp( $clean_div, 'No Show' ) === 0 ) {
                return 0.00;
            }
            $dist   = $resolve_division_distance( $clean_div );
            $r_slug = $round_slug !== '' ? $round_slug : $default_prof_key;

            if ( function_exists( 'sywat_get_archer_individual_fee' ) ) {
                return sywat_get_archer_individual_fee( $selected_t_data, $clean_div, $dist, $style, $r_slug, '' );
            }
            $tier = $resolve_paid_tier( $clean_div );
            return ( $tier === 'Youth' ) ? 25.00 : 30.00;
        };

        // Close Line (Preserves Waitlist & Cancelled records; marks un-checked-in active archers as No Show)
        if ( $action === 'close_line' && $data['is_admin'] ) {
            $target_idx  = trim( sanitize_text_field( wp_unslash( $_POST['target_line_idx'] ) ) );
            $target_name = isset( $lines[ $target_idx ]['name'] ) ? trim( $lines[ $target_idx ]['name'] ) : '';

            $live_closed = get_option( $closed_lines_key, array() );
            if ( ! is_array( $live_closed ) ) {
                $live_closed = array();
            }

            if ( ! in_array( $target_idx, $live_closed, true ) ) {
                $live_closed[] = $target_idx;
            }
            if ( $target_name !== '' && ! in_array( $target_name, $live_closed, true ) ) {
                $live_closed[] = $target_name;
            }

            $live_closed = array_values( array_unique( $live_closed ) );

            update_option( $closed_lines_key, $live_closed, false );
            update_option( 'sywat_current_tournament', $selected_tournament, false );

            $data['closed_lines'] = $live_closed;

            $update_count = 0;
            foreach ( $registrations as &$r ) {
                $r_resolved_lidx = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $r, $lines ) : ( isset( $r['line_index'] ) ? (string) $r['line_index'] : '0' );
                if ( isset( $r['tournament'] ) && $r['tournament'] === $selected_tournament && (string) $r_resolved_lidx === $target_idx ) {
                    $r_status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
                    if ( strcasecmp( $r_status, 'Waitlist' ) === 0 || strcasecmp( $r_status, 'Cancelled' ) === 0 ) {
                        continue;
                    }
                    if ( empty( $r['checked_in'] ) ) {
                        $r['division'] = 'No Show';
                        $r['gender']   = 'No Show';
                        $r['style']    = 'No Show';
                        if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                            sywat_waitlist_persist_record( $r, $registrations );
                        } else {
                            update_option( 'sywat_t_' . $active_t_slug . '_' . $r['id'], $r, false );
                        }
                        $update_count++;
                    }
                }
            }
            unset( $r );
            sywat_desk_sync_tourn_registrations( $data );
            return '<div class="sywat-alert sywat-alert-success"><strong>Line closed successfully.</strong> ' . $update_count . ' absent archer(s) marked as No Show.</div>';
        }

        // Reopen Line
        if ( $action === 'reopen_line' && $data['is_admin'] ) {
            $target_idx  = trim( sanitize_text_field( wp_unslash( $_POST['target_line_idx'] ) ) );
            $target_name = isset( $lines[ $target_idx ]['name'] ) ? trim( $lines[ $target_idx ]['name'] ) : '';

            $live_closed = get_option( $closed_lines_key, array() );
            if ( ! is_array( $live_closed ) ) {
                $live_closed = array();
            }

            $new_closed = array();
            foreach ( $live_closed as $val ) {
                $v_clean = trim( (string) $val );
                if ( $v_clean !== $target_idx && ( $target_name === '' || $v_clean !== $target_name ) ) {
                    $new_closed[] = $v_clean;
                }
            }

            update_option( $closed_lines_key, $new_closed, false );
            update_option( 'sywat_current_tournament', $selected_tournament, false );

            $data['closed_lines'] = $new_closed;

            return '<div class="sywat-alert sywat-alert-success"><strong>Line reopened successfully.</strong> Walk-ins and check-ins are active again.</div>';
        }

        // Add Walk-In (Fully Automated Fee, Promo, Canonical Name Enforcement & Household Family Cap Engine)
        if ( $action === 'add_walkin' || $action === 'add_walkin_next' ) {
            $raw_name_val = trim( sanitize_text_field( wp_unslash( $_POST['walkin_name'] ) ) );
            $group_name   = isset( $_POST['walkin_group_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['walkin_group_name'] ) ) ) : '';
            $group_phone  = isset( $_POST['walkin_group_phone'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['walkin_group_phone'] ) ) ) : '';
            $line_idx     = sanitize_text_field( wp_unslash( $_POST['walkin_line_index'] ) );
            $raw_division = sanitize_text_field( wp_unslash( $_POST['walkin_division'] ) );
            $gender       = sanitize_text_field( wp_unslash( $_POST['walkin_gender'] ) );
            $raw_style    = sanitize_text_field( wp_unslash( $_POST['walkin_style'] ) );
            $is_courtesy  = ! empty( $_POST['walkin_is_courtesy'] );

            $round_slug = ! empty( $_POST['walkin_pricing_profile'] )
                ? sanitize_title( wp_unslash( $_POST['walkin_pricing_profile'] ) )
                : $default_prof_key;
            if ( ! empty( $t_profiles ) && ! isset( $t_profiles[ $round_slug ] ) ) {
                $round_slug = $default_prof_key;
            }

            if ( empty( $raw_name_val ) ) {
                return '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Walk-In Failed!</strong> Please enter an archer name.</div>';
            }

            // Enforce official Member DB / TFAA canonical name (e.g., "Mark E. Tanner" -> "Mark Tanner")
            $name_val = sywat_desk_canonicalize_archer_name( $raw_name_val, $tourn_start_date, '', $group_phone );
            if ( $group_name !== '' ) {
                $group_name = ( strcasecmp( $group_name, $raw_name_val ) === 0 )
                    ? $name_val
                    : sywat_desk_canonicalize_archer_name( $group_name, $tourn_start_date, '', $group_phone );
            }

            $existing_dup_name = sywat_desk_find_same_line_duplicate( $registrations, $selected_tournament, $lines, $line_idx, $name_val );
            if ( $existing_dup_name !== '' ) {
                $alert_text = '⚠️ WALK-IN BLOCKED:\n\n' . esc_js( $name_val ) . ' is already registered on this shooting line' . ( strcasecmp( $existing_dup_name, $name_val ) !== 0 ? ' (as "' . esc_js( $existing_dup_name ) . '")' : '' ) . '.\n(Signing up for a different line is allowed).';
                return '<div class="sywat-alert sywat-alert-danger">⚠️ Walk-In Blocked! <strong>' . esc_html( $name_val ) . '</strong> is already registered on this shooting line' . ( strcasecmp( $existing_dup_name, $name_val ) !== 0 ? ' (as <strong>' . esc_html( $existing_dup_name ) . '</strong>)' : '' ) . '. (Signing up for a different line is allowed).</div>' .
                       '<script ' . $script_optout_attrs . '>document.addEventListener("DOMContentLoaded", function() { alert("' . $alert_text . '"); });</script>';
            }

            // Enforce database-driven style & age division rules (including force-division overrides)
            $resolved_sd = sywat_desk_resolve_style_and_division_for_action( $raw_style, $raw_division );
            $division    = $resolved_sd['division'];
            $style       = $resolved_sd['style'];
            $distance    = $resolve_division_distance( $division );

            // Evaluate First-Time / Lapsed Shooter Promotional Free Entry eligibility using indexed active tournaments
            $is_promo_free = false;
            if ( ! $is_courtesy && function_exists( 'sywat_check_archer_promo_eligibility' ) ) {
                $all_regs_for_promo = function_exists( 'sywat_get_all_registrations' )
                    ? sywat_get_all_registrations( $tournaments_map )
                    : $registrations;
                $promo_check = sywat_check_archer_promo_eligibility(
                    $name_val,
                    $selected_t_data,
                    $round_slug,
                    $all_regs_for_promo
                );
                $is_promo_free = ! empty( $promo_check['eligible'] );
            }

            $new_id    = uniqid( 'reg_' );
            $line_name = isset( $lines[ $line_idx ]['name'] ) ? $lines[ $line_idx ]['name'] : 'N/A';

            // Resolve active Pricing Profile for Family Cap rules
            $active_prof = isset( $t_profiles[ $round_slug ] ) ? $t_profiles[ $round_slug ] : $default_prof;
            $cap_enabled = is_array( $active_prof ) && ! empty( $active_prof['family_cap_enabled'] );
            $cap_amount  = is_array( $active_prof ) && isset( $active_prof['family_cap_amount'] ) ? floatval( $active_prof['family_cap_amount'] ) : 75.00;
            $cap_min     = is_array( $active_prof ) && isset( $active_prof['family_min_archers'] ) ? max( 2, intval( $active_prof['family_min_archers'] ) ) : 3;
            $cap_scope   = is_array( $active_prof ) && isset( $active_prof['family_cap_scope'] ) ? $active_prof['family_cap_scope'] : 'line';

            // Family Group Resolution
            $sel_fam_id  = isset( $_POST['walkin_family_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['walkin_family_group_id'] ) ) : '';
            $family_id   = '';
            $family_name = '';

            if ( ! empty( $sel_fam_id ) ) {
                $family_id        = $sel_fam_id;
                $checkin_families = isset( $existing_families_by_line[ $line_idx ] ) ? $existing_families_by_line[ $line_idx ] : $existing_families;
                $family_name      = isset( $checkin_families[ $sel_fam_id ] ) ? $checkin_families[ $sel_fam_id ] : ( ! empty( $group_name ) ? $group_name . ' (Family Cap)' : 'Family Group' );
            } elseif ( ! empty( $group_name ) ) {
                $fam_prefix  = ( $cap_scope === 'tournament' )
                    ? ( 'fam_' . $active_t_slug . '_' . sanitize_title( $group_name ) )
                    : ( 'fam_' . $active_t_slug . '_l' . sanitize_title( $line_idx ) . '_' . sanitize_title( $group_name ) . '_' . substr( md5( uniqid( '', true ) ), 0, 4 ) );
                $family_id   = $fam_prefix;
                $family_name = $group_name . ' (Family Cap)';
            }

            // Gather existing ACTIVE, paid (non-waitlisted, non-cancelled, non-governor, non-promo) archers in this family group
            $family_archers = array();
            if ( ! empty( $family_id ) ) {
                foreach ( $registrations as $r ) {
                    if ( ! isset( $r['tournament'] ) || strcasecmp( $r['tournament'], $selected_tournament ) !== 0 ) {
                        continue;
                    }
                    if ( $cap_scope !== 'tournament' && ( ! isset( $r['line_index'] ) || (string) $r['line_index'] !== (string) $line_idx ) ) {
                        continue;
                    }
                    if ( empty( $r['family_id'] ) || $r['family_id'] !== $family_id ) {
                        continue;
                    }
                    $r_status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
                    if ( strcasecmp( $r_status, 'Waitlist' ) === 0 || strcasecmp( $r_status, 'Cancelled' ) === 0 ) {
                        continue;
                    }
                    $r_paid = isset( $r['paid'] ) ? trim( (string) $r['paid'] ) : '';
                    if ( strcasecmp( $r_paid, 'Field Governor' ) === 0 || ! empty( $r['is_promo_free'] ) || strcasecmp( $r_paid, 'Promo (Free)' ) === 0 ) {
                        continue;
                    }
                    $family_archers[] = $r;
                }
            }

            $new_archer_fee = ( $is_courtesy || $is_promo_free )
                ? 0.00
                : $calculate_individual_fee( $division, $style, $round_slug );

            // Calculate total active paid group archer count and standard cost including the new walk-in archer
            $total_group_count = count( $family_archers ) + ( ( $is_courtesy || $is_promo_free ) ? 0 : 1 );
            $total_group_cost  = $new_archer_fee;

            foreach ( $family_archers as $fa ) {
                $fa_round = ! empty( $fa['pricing_profile'] ) ? (string) $fa['pricing_profile'] : $round_slug;
                $total_group_cost += $calculate_individual_fee(
                    isset( $fa['division'] ) ? $fa['division'] : '',
                    isset( $fa['style'] ) ? $fa['style'] : '',
                    $fa_round
                );
            }

            // Dynamic Family Cap evaluation
            $is_family_capped = (
                ! empty( $family_id ) &&
                ! $is_courtesy &&
                ! $is_promo_free &&
                $cap_enabled &&
                $total_group_count >= $cap_min &&
                $total_group_cost > $cap_amount
            );

            // Determine paid status for new archer
            if ( $is_courtesy ) {
                $paid_status = 'Field Governor';
            } elseif ( $is_promo_free ) {
                $paid_status = 'Promo (Free)';
            } elseif ( $is_family_capped ) {
                $paid_status = 'Family';
            } else {
                $paid_status = $resolve_paid_tier( $division );
            }

            $new_reg = array(
                'id'              => $new_id,
                'tournament'      => $selected_tournament,
                'name'            => $name_val,
                'phone'           => $group_phone,
                'line_index'      => $line_idx,
                'line_name'       => $line_name,
                'distance'        => $distance,
                'type'            => 'Walk-In',
                'status'          => 'Pre-Reg',
                'division'        => $division,
                'pricing_profile' => $round_slug,
                'is_promo_free'   => $is_promo_free,
                'gender'          => $gender,
                'style'           => $style,
                'paid'            => $paid_status,
                'family_id'       => $family_id,
                'family_name'     => $family_name,
                'balance_due'     => 0,
                'checked_in'      => 1,
                'created_at'      => current_time( 'mysql' ),
            );

            if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                sywat_waitlist_persist_record( $new_reg, $registrations );
            } else {
                update_option( 'sywat_t_' . $active_t_slug . '_' . $new_id, $new_reg, false );
                $registrations[] = $new_reg;

                $t_slug      = sanitize_title( $selected_tournament );
                $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
                if ( ! is_array( $master_regs ) ) {
                    $master_regs = array();
                }
                $master_regs[ $new_id ] = $new_reg;
                update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
            }

            // Retroactively update existing active paid family archers if cap threshold was reached on this registration
            if ( $is_family_capped && ! empty( $family_archers ) ) {
                foreach ( $registrations as &$r ) {
                    if ( ! isset( $r['tournament'] ) || strcasecmp( $r['tournament'], $selected_tournament ) !== 0 ) {
                        continue;
                    }
                    if ( $cap_scope !== 'tournament' && ( ! isset( $r['line_index'] ) || (string) $r['line_index'] !== (string) $line_idx ) ) {
                        continue;
                    }
                    if ( ! empty( $r['family_id'] ) && $r['family_id'] === $family_id ) {
                        $r_status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
                        if ( strcasecmp( $r_status, 'Waitlist' ) === 0 || strcasecmp( $r_status, 'Cancelled' ) === 0 ) {
                            continue;
                        }
                        if ( $r['paid'] !== 'Field Governor' && empty( $r['is_promo_free'] ) && strcasecmp( $r['paid'], 'Promo (Free)' ) === 0 ) {
                            $r['paid'] = 'Family';
                            if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                                sywat_waitlist_persist_record( $r, $registrations );
                            } else {
                                update_option( 'sywat_t_' . $active_t_slug . '_' . $r['id'], $r, false );
                            }
                        }
                    }
                }
                unset( $r );
            }

            sywat_desk_sync_tourn_registrations( $data );

            // Fast-Loop Context Persistence: Accumulate session archers and total balance
            $prev_session_archers = array();
            if ( isset( $_POST['walkin_session_archers_json'] ) && ! empty( $_POST['walkin_session_archers_json'] ) ) {
                $decoded = json_decode( stripslashes( $_POST['walkin_session_archers_json'] ), true );
                if ( is_array( $decoded ) ) {
                    $prev_session_archers = $decoded;
                }
            }

            $prev_session_archers[] = array(
                'name'     => $name_val,
                'division' => $division,
                'fee'      => $new_archer_fee,
                'is_promo' => $is_promo_free,
                'capped'   => $is_family_capped,
            );

            // Recalculate session total using active profile cap rules
            $raw_sum = 0.00;
            foreach ( $prev_session_archers as $sa ) {
                $raw_sum += floatval( $sa['fee'] );
            }
            $session_total = ( $is_family_capped && $cap_enabled && $raw_sum > $cap_amount ) ? $cap_amount : $raw_sum;

            $promo_note = $is_promo_free ? ' 🎟️ (First-Time/Lapsed Promo — $0.00)' : '';

            if ( $action === 'add_walkin_next' ) {
                $loop_context = array(
                    'primary_contact' => ! empty( $group_name ) ? $group_name : $name_val,
                    'phone'           => $group_phone,
                    'line_idx'        => $line_idx,
                    'pricing_profile' => $round_slug,
                    'family_id'       => $family_id,
                    'last_added_name' => $name_val,
                    'session_archers' => $prev_session_archers,
                    'session_total'   => $session_total,
                    'is_capped'       => $is_family_capped,
                    'cap_amount'      => $cap_amount,
                );
                $data['walkin_loop_context']          = $loop_context;
                $GLOBALS['sywat_walkin_loop_context'] = $loop_context;

                $cap_msg = $is_family_capped ? ' 🟢 (Family Cap Applied - $' . number_format( $cap_amount, 2 ) . ' Max)' : '';
                return '<div class="sywat-alert sywat-alert-success">✅ <strong>' . esc_html( $name_val ) . '</strong> registered and checked in successfully!' . $promo_note . ' Running Total: $' . number_format( $session_total, 2 ) . $cap_msg . '. Enter next archer below.</div>';
            }

            return '<div class="sywat-alert sywat-alert-success">✅ Walk-in archer <strong>' . esc_html( $name_val ) . '</strong> registered and checked in successfully.' . $promo_note . ' Total Due: $' . number_format( $session_total, 2 ) . '</div>';
        }

        // Check-In (Delegates to shared POST/AJAX transaction-safe processor)
        if ( $action === 'save_checkin' ) {
            $res = sywat_desk_process_checkin_submission( $data, $_POST );
            return ! empty( $res['notice_html'] ) ? $res['notice_html'] : '';
        }

        // Undo Check-In (Delegates to shared POST/AJAX processor)
        if ( $action === 'undo_checkin' ) {
            $res = sywat_desk_process_undo_checkin_submission( $data, $_POST );
            return ! empty( $res['notice_html'] ) ? $res['notice_html'] : '';
        }

        // Save Edit (Preserves explicit manual Balance Due overrides while propagating $0.00 group clearances and Family Cap changes)
        if ( $action === 'save_edit' ) {
            $target_id = sanitize_text_field( wp_unslash( $_POST['reg_id'] ) );
            $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
            $reg       = get_option( $opt_key );

            if ( $reg ) {
                $orig_name       = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : '';
                $raw_edit_name   = trim( preg_replace( '/[[:space:]]+/', ' ', sanitize_text_field( wp_unslash( $_POST['edit_name'] ) ) ) );
                $clean_edit_name = sywat_desk_normalize_comma_name( $raw_edit_name );
                $edit_name       = ( $clean_edit_name !== '' && strcasecmp( $clean_edit_name, $orig_name ) !== 0 )
                    ? sywat_desk_canonicalize_archer_name(
                        $clean_edit_name,
                        $tourn_start_date,
                        isset( $reg['email'] ) ? (string) $reg['email'] : '',
                        isset( $reg['phone'] ) ? (string) $reg['phone'] : ''
                    )
                    : ( $clean_edit_name !== '' ? $clean_edit_name : $orig_name );

                $line_idx    = sanitize_text_field( wp_unslash( $_POST['edit_line_index'] ) );
                $orig_line   = isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '';
                $orig_div    = isset( $reg['division'] ) ? trim( (string) $reg['division'] ) : '';
                $orig_round  = isset( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : $default_prof_key;
                $orig_paid   = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
                $orig_due    = isset( $reg['balance_due'] ) ? floatval( $reg['balance_due'] ) : 0.00;
                $orig_fam_id = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';

                if ( strcasecmp( $orig_name, $edit_name ) !== 0 || (string) $orig_line !== (string) $line_idx ) {
                    $existing_dup_name = sywat_desk_find_same_line_duplicate( $registrations, $selected_tournament, $lines, $line_idx, $edit_name, $target_id );
                    if ( $existing_dup_name !== '' ) {
                        $alert_text = '⚠️ EDIT BLOCKED:\n\n' . esc_js( $edit_name ) . ' is already registered on this shooting line' . ( strcasecmp( $existing_dup_name, $edit_name ) !== 0 ? ' (as "' . esc_js( $existing_dup_name ) . '")' : '' ) . '.';
                        return '<div class="sywat-alert sywat-alert-danger">⚠️ Edit Blocked! <strong>' . esc_html( $edit_name ) . '</strong> is already registered on this shooting line' . ( strcasecmp( $existing_dup_name, $edit_name ) !== 0 ? ' (as <strong>' . esc_html( $existing_dup_name ) . '</strong>)' : '' ) . '.</div>' .
                               '<script ' . $script_optout_attrs . '>document.addEventListener("DOMContentLoaded", function() { alert("' . $alert_text . '"); });</script>';
                    }
                }

                $line_name    = isset( $lines[ $line_idx ]['name'] ) ? $lines[ $line_idx ]['name'] : 'N/A';
                $raw_division = sanitize_text_field( wp_unslash( $_POST['edit_division'] ) );
                $raw_style    = sanitize_text_field( wp_unslash( $_POST['edit_style'] ) );
                $pay_opt      = sanitize_text_field( wp_unslash( $_POST['edit_pay_option'] ) );
                $round_slug   = isset( $_POST['edit_pricing_profile'] )
                    ? sanitize_title( wp_unslash( $_POST['edit_pricing_profile'] ) )
                    : ( $orig_round !== '' ? $orig_round : $default_prof_key );

                if ( $round_slug === '' || ( ! empty( $t_profiles ) && ! isset( $t_profiles[ $round_slug ] ) ) ) {
                    $legacy_rp = ( $round_slug !== '' && function_exists( 'sywat_find_pricing_profile' ) ) ? sywat_find_pricing_profile( $round_slug ) : null;
                    if ( ! is_array( $legacy_rp ) ) {
                        $round_slug = $default_prof_key;
                    }
                }

                // Enforce database-driven style & age division rules
                $resolved_sd = sywat_desk_resolve_style_and_division_for_action( $raw_style, $raw_division );
                $division    = $resolved_sd['division'];
                $style       = $resolved_sd['style'];
                $distance    = $resolve_division_distance( $division );

                $submitted_due       = isset( $_POST['edit_balance_due'] ) ? max( 0.00, round( floatval( wp_unslash( $_POST['edit_balance_due'] ) ), 2 ) ) : 0.00;
                $manual_due_adjusted = ( abs( $submitted_due - $orig_due ) >= 0.01 );
                $div_changed         = ( strcasecmp( $orig_div, $division ) !== 0 || $orig_round !== $round_slug );

                $reg['name']            = $edit_name;
                $reg['line_index']      = $line_idx;
                $reg['line_name']       = $line_name;
                $reg['distance']        = $distance;
                $reg['division']        = $division;
                $reg['pricing_profile'] = $round_slug;
                $reg['gender']          = sanitize_text_field( wp_unslash( $_POST['edit_gender'] ) );
                $reg['style']           = $style;

                if ( strcasecmp( $pay_opt, 'Promo (Free)' ) === 0 || stripos( $pay_opt, 'Promo' ) === 0 ) {
                    $reg['paid']          = 'Promo (Free)';
                    $reg['is_promo_free'] = true;
                    $reg['balance_due']   = 0.00;
                } elseif ( $pay_opt === 'Family' ) {
                    $reg['paid']          = 'Family';
                    $reg['is_promo_free'] = false;
                    $reg['balance_due']   = $submitted_due;
                } elseif ( $pay_opt === 'Field Governor' ) {
                    $reg['paid']          = 'Field Governor';
                    $reg['is_promo_free'] = false;
                    $reg['balance_due']   = 0.00;
                } else {
                    $reg['paid']          = $resolve_paid_tier( $division );
                    $reg['is_promo_free'] = false;

                    // Automatically recalculate unpaid fee if division or round changed and balance wasn't manually adjusted
                    if ( $orig_due > 0 && ! $manual_due_adjusted && $div_changed ) {
                        $reg['balance_due'] = round( $calculate_individual_fee( $division, $style, $round_slug ), 2 );
                    } else {
                        $reg['balance_due'] = $submitted_due;
                    }
                }

                if ( $reg['paid'] === 'Family' ) {
                    $sel_fam_id    = isset( $_POST['edit_family_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edit_family_group_id'] ) ) : '';
                    $edit_families = isset( $existing_families_by_line[ $line_idx ] ) ? $existing_families_by_line[ $line_idx ] : $existing_families;

                    if ( ! empty( $sel_fam_id ) ) {
                        $reg['family_id']   = $sel_fam_id;
                        $reg['family_name'] = isset( $edit_families[ $sel_fam_id ] ) ? $edit_families[ $sel_fam_id ] : 'Family Group';
                    } elseif ( empty( $reg['family_id'] ) || $orig_line !== $line_idx ) {
                        $reg['family_id']   = 'fam_' . $active_t_slug . '_l' . sanitize_title( $line_idx ) . '_' . sanitize_title( $edit_name ) . '_' . substr( md5( uniqid( '', true ) ), 0, 4 );
                        $reg['family_name'] = $edit_name . ' (Family Cap)';
                    }
                }

                $cleared_to_zero = ( $reg['balance_due'] <= 0.00 ) && ( $orig_due > 0.00 || $manual_due_adjusted );
                if ( $cleared_to_zero && $orig_due > 0.00 ) {
                    $prior_paid         = isset( $reg['amount_paid'] ) ? floatval( $reg['amount_paid'] ) : 0.00;
                    $reg['amount_paid'] = round( $prior_paid + $orig_due, 2 );
                } elseif ( $reg['balance_due'] > $orig_due && isset( $reg['amount_paid'] ) ) {
                    $added_due          = round( $reg['balance_due'] - $orig_due, 2 );
                    $reg['amount_paid'] = max( 0.00, round( floatval( $reg['amount_paid'] ) - $added_due, 2 ) );
                }

                if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                    sywat_waitlist_persist_record( $reg, $registrations );
                } else {
                    update_option( $opt_key, $reg, false );
                    foreach ( $registrations as &$r ) {
                        if ( isset( $r['id'] ) && $r['id'] === $target_id ) {
                            $r = $reg;
                        }
                    }
                    unset( $r );
                }

                // Propagate $0.00 Paid clearance across linked household/booking members, or reconcile Family Cap on group/rate changes
                $new_fam_id         = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
                $group_rule_changed = ( $new_fam_id !== $orig_fam_id || (string) $line_idx !== (string) $orig_line || strcasecmp( $orig_paid, $reg['paid'] ) !== 0 );

                if ( $cleared_to_zero ) {
                    sywat_desk_clear_linked_group_balances(
                        $reg,
                        $registrations,
                        $selected_tournament,
                        $active_t_slug,
                        $lines,
                        $use_tourn_wide_cap
                    );
                } elseif ( ! $manual_due_adjusted && $group_rule_changed && function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
                    if ( $orig_fam_id !== '' ) {
                        sywat_waitlist_recalculate_line_family_fees( $selected_tournament, $orig_line, $orig_fam_id, $registrations, $lines );
                    }
                    if ( $new_fam_id !== '' && ( $new_fam_id !== $orig_fam_id || $line_idx !== $orig_line ) ) {
                        sywat_waitlist_recalculate_line_family_fees( $selected_tournament, $line_idx, $new_fam_id, $registrations, $lines );
                    }
                }

                sywat_desk_sync_tourn_registrations( $data );
                return '<div class="sywat-alert sywat-alert-success">Updated registration details for <strong>' . esc_html( $edit_name ) . '</strong>.</div>';
            }
        }

        // Delete Archer (With dynamic Household Family Cap reconciliation)
        if ( $action === 'delete_archer' ) {
            $target_id  = sanitize_text_field( wp_unslash( $_POST['reg_id'] ) );
            $target_reg = get_option( 'sywat_t_' . $active_t_slug . '_' . $target_id, null );

            if ( ! is_array( $target_reg ) && is_array( $registrations ) ) {
                foreach ( $registrations as $candidate ) {
                    if ( is_array( $candidate ) && isset( $candidate['id'] ) && (string) $candidate['id'] === $target_id ) {
                        $target_reg = $candidate;
                        break;
                    }
                }
            }

            $del_line_idx = is_array( $target_reg ) && isset( $target_reg['line_index'] ) ? (string) $target_reg['line_index'] : '';
            $del_fam_id   = is_array( $target_reg ) && ! empty( $target_reg['family_id'] ) ? trim( (string) $target_reg['family_id'] ) : '';

            delete_option( 'sywat_t_' . $active_t_slug . '_' . $target_id );

            $master_regs = get_option( 'sywat_registrations_' . $active_t_slug, array() );
            if ( is_array( $master_regs ) && isset( $master_regs[ $target_id ] ) ) {
                unset( $master_regs[ $target_id ] );
                update_option( 'sywat_registrations_' . $active_t_slug, $master_regs, false );
            }

            $registrations = array_values( array_filter( $registrations, function( $r ) use ( $target_id ) {
                return ! ( isset( $r['id'] ) && $r['id'] === $target_id );
            } ) );

            if ( $del_fam_id !== '' && $del_line_idx !== '' && function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
                sywat_waitlist_recalculate_line_family_fees( $selected_tournament, $del_line_idx, $del_fam_id, $registrations, $lines );
            }

            sywat_desk_sync_tourn_registrations( $data );
        }

        return '';
    }
}
