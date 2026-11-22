<?php
/**
 * Sub-Module: Check-In Desk Presentation & Payload Helpers
 * Path: includes/checkin/checkin-helpers.php
 * Description: Pure presentation formatting, O(R) single-pass household balance indexing with Youth/Young Adult rate-tier overcharge reconciliation, family group label formatting, surname alphabetical sorting ("LastName, FirstName"), and normalized modal JSON payload builder for the Check-In Desk portal.
 * Compliance: Pure helper layer — zero database queries, zero option/meta lookups, zero HTML echo at include time, O(R) single-pass indexing, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Formats raw family group membership clusters into human-readable line-scoped
 * and tournament-wide dropdown option maps.
 *
 * @param array  $family_members_by_id Raw family member clusters keyed by family_id.
 * @param string $selected_line_idx    Currently selected line index or 'all'.
 * @return array{
 *     by_line: array<string, array<string, string>>,
 *     current: array<string, string>
 * }
 */
if ( ! function_exists( 'sywat_desk_format_family_groups' ) ) {
    function sywat_desk_format_family_groups( $family_members_by_id, $selected_line_idx = 'all' ) {
        $by_line = array();

        if ( is_array( $family_members_by_id ) ) {
            foreach ( $family_members_by_id as $fid => $fam_info ) {
                if ( ! is_array( $fam_info ) ) {
                    continue;
                }

                $l_idx     = isset( $fam_info['line_idx'] ) ? (string) $fam_info['line_idx'] : '0';
                $raw_fname = isset( $fam_info['family_name'] ) ? trim( (string) $fam_info['family_name'] ) : '';
                $names     = isset( $fam_info['names'] ) && is_array( $fam_info['names'] ) ? $fam_info['names'] : array();

                if ( $raw_fname !== '' && stripos( $raw_fname, 'fam_' ) === false && stripos( $raw_fname, 'Booking' ) === false && strlen( $raw_fname ) > 2 ) {
                    $label = $raw_fname;
                } elseif ( ! empty( $names ) ) {
                    $count = count( $names );
                    if ( $count === 1 ) {
                        $label = $names[0] . ' Group';
                    } elseif ( $count <= 3 ) {
                        $last_names = array_map( function( $n ) {
                            $parts = explode( ' ', trim( (string) $n ) );
                            return end( $parts );
                        }, $names );
                        $label = implode( ' / ', array_unique( $last_names ) ) . ' Group';
                    } else {
                        $parts = explode( ' ', trim( (string) $names[0] ) );
                        $label = end( $parts ) . ' Family Group (' . $count . ' Archers)';
                    }
                } else {
                    $label = 'Family Group (' . substr( (string) $fid, -6 ) . ')';
                }

                if ( ! isset( $by_line[ $l_idx ] ) ) {
                    $by_line[ $l_idx ] = array();
                }
                $by_line[ $l_idx ][ (string) $fid ] = $label;
            }
        }

        $current = isset( $by_line[ (string) $selected_line_idx ] ) ? $by_line[ (string) $selected_line_idx ] : array();
        if ( empty( $current ) && (string) $selected_line_idx === 'all' ) {
            foreach ( $by_line as $l_fams ) {
                foreach ( $l_fams as $fid => $flabel ) {
                    $current[ $fid ] = $flabel;
                }
            }
        }

        return array(
            'by_line' => $by_line,
            'current' => $current,
        );
    }
}

/**
 * Computes an archer's standard individual entry fee purely from pre-loaded
 * tournament pricing profiles and division metadata (zero database/option queries).
 *
 * @param string $division         Normalized division label.
 * @param string $style            Normalized shooting style label.
 * @param string $round_slug       Pricing profile slug.
 * @param array  $pricing_profiles Tournament pricing profiles map.
 * @param string $default_prof_key Default pricing profile slug.
 * @param array  $div_meta_lookup  Associative division lookup indexed by lowercase label/code/alias.
 * @param string $tier_hint        Optional explicit rate-tier or distance hint ('Youth', 'Cub', 'Adult').
 * @return float Calculated individual entry fee.
 */
if ( ! function_exists( 'sywat_desk_compute_individual_fee_pure' ) ) {
    function sywat_desk_compute_individual_fee_pure( $division, $style, $round_slug, $pricing_profiles, $default_prof_key, $div_meta_lookup, $tier_hint = '' ) {
        $clean_div  = trim( (string) $division );
        $clean_hint = trim( (string) $tier_hint );

        if ( strcasecmp( $clean_div, 'No Show' ) === 0 ) {
            return 0.00;
        }
        if ( $clean_div === '' && $clean_hint === '' ) {
            return 0.00;
        }

        $div_key = strtolower( $clean_div );
        $div_cfg = ( $div_key !== '' && isset( $div_meta_lookup[ $div_key ] ) ) ? $div_meta_lookup[ $div_key ] : null;

        $is_cub   = $div_cfg
            ? ( isset( $div_cfg['distance'] ) && $div_cfg['distance'] === 'Cub' )
            : ( stripos( $clean_div, 'Cub' ) !== false || strcasecmp( $clean_hint, 'Cub' ) === 0 );
        $is_youth = $is_cub
            || ( $div_cfg && isset( $div_cfg['rate_tier'] ) && $div_cfg['rate_tier'] === 'Youth' )
            || ( stripos( $clean_div, 'Youth' ) !== false || stripos( $clean_div, 'Young Adult' ) !== false )
            || ( strcasecmp( $clean_hint, 'Youth' ) === 0 );

        $prof = null;
        if ( is_array( $pricing_profiles ) && ! empty( $pricing_profiles ) ) {
            if ( $round_slug !== '' && isset( $pricing_profiles[ $round_slug ] ) ) {
                $prof = $pricing_profiles[ $round_slug ];
            } elseif ( $default_prof_key !== '' && isset( $pricing_profiles[ $default_prof_key ] ) ) {
                $prof = $pricing_profiles[ $default_prof_key ];
            } else {
                $prof = reset( $pricing_profiles );
            }
        }

        if ( is_array( $prof ) ) {
            $div_slug = ( $div_cfg && ! empty( $div_cfg['slug'] ) ) ? $div_cfg['slug'] : ( $clean_div !== '' ? sanitize_title( $clean_div ) : '' );
            if ( $div_slug !== '' && isset( $prof['division_overrides'][ $div_slug ] ) && $prof['division_overrides'][ $div_slug ] !== '' ) {
                return round( floatval( $prof['division_overrides'][ $div_slug ] ), 2 );
            }

            $clean_style = trim( (string) $style );
            if ( $clean_style !== '' && isset( $prof['pro_rate'] ) && $prof['pro_rate'] !== '' && $prof['pro_rate'] !== null ) {
                if ( stripos( $clean_style, 'pro' ) !== false || stripos( $clean_div, 'pro' ) !== false ) {
                    return round( floatval( $prof['pro_rate'] ), 2 );
                }
            }

            if ( $is_youth && isset( $prof['youth_rate'] ) && $prof['youth_rate'] !== '' ) {
                return round( floatval( $prof['youth_rate'] ), 2 );
            }
            if ( ! $is_youth && isset( $prof['adult_rate'] ) && $prof['adult_rate'] !== '' ) {
                return round( floatval( $prof['adult_rate'] ), 2 );
            }
        }

        if ( $div_cfg && isset( $div_cfg['fee'] ) ) {
            return round( floatval( $div_cfg['fee'] ), 2 );
        }

        return $is_youth ? 25.00 : 30.00;
    }
}

/**
 * Formats all Check-In Desk presentation fields, pre-indexes household balances in a single
 * O(R) pass, reconciles any Youth/Young Adult Adult-rate overcharge discrepancies, sorts
 * registrations by line and surname ("LastName, FirstName"), and builds normalized modal
 * JSON payloads for zero-reload Check-In and Edit modals.
 *
 * @param array $data Check-In Desk state dataset passed by reference.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_prepare_presentation_data' ) ) {
    function sywat_desk_prepare_presentation_data( &$data ) {
        if ( ! is_array( $data ) ) {
            return;
        }

        $selected_line_idx = isset( $data['selected_line_idx'] ) ? (string) $data['selected_line_idx'] : 'all';
        $lines             = isset( $data['lines'] ) && is_array( $data['lines'] ) ? $data['lines'] : array();
        $closed_lines      = isset( $data['closed_lines'] ) && is_array( $data['closed_lines'] ) ? $data['closed_lines'] : array();
        $t_profiles        = isset( $data['pricing_profiles'] ) && is_array( $data['pricing_profiles'] ) ? $data['pricing_profiles'] : array();
        $default_prof_key  = ! empty( $data['default_pricing_profile'] )
            ? (string) $data['default_pricing_profile']
            : ( ! empty( $t_profiles ) ? (string) key( $t_profiles ) : 'indoor' );
        $is_multi_round    = ! empty( $data['is_multi_round'] ) || count( $t_profiles ) > 1;
        $legacy_div_map    = isset( $data['legacy_div_map'] ) && is_array( $data['legacy_div_map'] ) ? $data['legacy_div_map'] : array();
        $divisions_meta    = isset( $data['divisions_meta'] ) && is_array( $data['divisions_meta'] ) ? $data['divisions_meta'] : array();

        // 1. Format human-readable Family Group labels
        $family_groups                     = sywat_desk_format_family_groups(
            isset( $data['family_members_by_id'] ) ? $data['family_members_by_id'] : array(),
            $selected_line_idx
        );
        $data['existing_families_by_line'] = $family_groups['by_line'];
        $data['existing_families']         = $family_groups['current'];

        // 2. Build O(1) division metadata lookup & resolve tournament Family Cap rules
        $div_meta_lookup = array();
        foreach ( $divisions_meta as $dm ) {
            if ( ! is_array( $dm ) || empty( $dm['label'] ) ) {
                continue;
            }
            $div_meta_lookup[ strtolower( trim( (string) $dm['label'] ) ) ] = $dm;
            if ( ! empty( $dm['code'] ) ) {
                $div_meta_lookup[ strtolower( trim( (string) $dm['code'] ) ) ] = $dm;
            }
            if ( ! empty( $dm['aliases'] ) && is_array( $dm['aliases'] ) ) {
                foreach ( $dm['aliases'] as $alias ) {
                    $clean_alias = strtolower( trim( (string) $alias ) );
                    if ( $clean_alias !== '' ) {
                        $div_meta_lookup[ $clean_alias ] = $dm;
                    }
                }
            }
        }

        $cap_enabled       = false;
        $cap_amount        = 75.00;
        $cap_min           = 3;
        $use_tourn_wide    = false;
        $active_cap_amount = 75.00;

        if ( ! empty( $t_profiles ) ) {
            $max_prof_cap = 0.00;
            foreach ( $t_profiles as $tp ) {
                if ( ! is_array( $tp ) || empty( $tp['family_cap_enabled'] ) ) {
                    continue;
                }
                $cap_enabled = true;
                if ( isset( $tp['family_cap_amount'] ) ) {
                    $prof_cap     = floatval( $tp['family_cap_amount'] );
                    $max_prof_cap = max( $max_prof_cap, $prof_cap );
                }
                if ( isset( $tp['family_min_archers'] ) ) {
                    $cap_min = max( 2, intval( $tp['family_min_archers'] ) );
                }
                if ( isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                    $use_tourn_wide = true;
                }
            }
            if ( $max_prof_cap > 0 ) {
                $cap_amount        = $max_prof_cap;
                $active_cap_amount = $max_prof_cap;
            }
        }

        $has_lf_formatter = function_exists( 'sywat_format_name_last_first' );
        $has_norm_div     = function_exists( 'sywat_shooting_styles_normalize_division' );
        $has_norm_style   = function_exists( 'sywat_shooting_styles_normalize_style' );

        $raw_tourn_regs = isset( $data['tourn_registrations'] ) && is_array( $data['tourn_registrations'] )
            ? $data['tourn_registrations']
            : array();

        // Helper: Reconcile any Youth/Young Adult record overbilled at the Adult rate (e.g., $25 paid + $5 due)
        $reconcile_record_due = function( $reg ) use ( $t_profiles, $default_prof_key, $div_meta_lookup ) {
            $raw_due = isset( $reg['balance_due'] ) ? max( 0.00, floatval( $reg['balance_due'] ) ) : 0.00;
            if ( $raw_due <= 0.00 ) {
                return 0.00;
            }

            $r_paid  = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
            $r_rate  = isset( $reg['rate_type'] ) ? trim( (string) $reg['rate_type'] ) : '';
            $r_dist  = isset( $reg['distance'] ) ? trim( (string) $reg['distance'] ) : '';
            $r_div   = isset( $reg['division'] ) ? trim( (string) $reg['division'] ) : '';
            $r_sty   = isset( $reg['style'] ) ? trim( (string) $reg['style'] ) : '';
            $r_round = ! empty( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : $default_prof_key;

            $is_fam = ( strcasecmp( $r_paid, 'Family' ) === 0 ) || ( $r_rate !== '' && stripos( $r_rate, 'family' ) !== false );
            if ( $is_fam || strcasecmp( $r_div, 'No Show' ) === 0 ) {
                return round( $raw_due, 2 );
            }

            $div_key       = strtolower( $r_div );
            $div_cfg       = ( $div_key !== '' && isset( $div_meta_lookup[ $div_key ] ) ) ? $div_meta_lookup[ $div_key ] : null;
            $is_youth_tier = (
                strcasecmp( $r_paid, 'Youth' ) === 0 ||
                strcasecmp( $r_paid, 'Cub' ) === 0 ||
                strcasecmp( $r_rate, 'Youth' ) === 0 ||
                strcasecmp( $r_rate, 'Cub' ) === 0 ||
                strcasecmp( $r_dist, 'Cub' ) === 0 ||
                ( is_array( $div_cfg ) && isset( $div_cfg['rate_tier'] ) && $div_cfg['rate_tier'] === 'Youth' ) ||
                stripos( $r_div, 'Cub' ) !== false ||
                stripos( $r_div, 'Youth' ) !== false ||
                stripos( $r_div, 'Young Adult' ) !== false
            );

            if ( ! $is_youth_tier ) {
                return round( $raw_due, 2 );
            }

            $youth_fee = sywat_desk_compute_individual_fee_pure( $r_div, $r_sty, $r_round, $t_profiles, $default_prof_key, $div_meta_lookup, 'Youth' );
            $adult_fee = sywat_desk_compute_individual_fee_pure( '', $r_sty, $r_round, $t_profiles, $default_prof_key, $div_meta_lookup, 'Adult' );

            if ( isset( $reg['amount_paid'] ) ) {
                $amt_paid = max( 0.00, floatval( $reg['amount_paid'] ) );
                if ( $amt_paid > 0.00 && ( $amt_paid + $raw_due ) > ( $youth_fee + 0.009 ) ) {
                    return max( 0.00, round( $youth_fee - $amt_paid, 2 ) );
                }
            } elseif ( $adult_fee > $youth_fee && abs( $raw_due - ( $adult_fee - $youth_fee ) ) < 0.01 ) {
                return 0.00;
            }

            return round( $raw_due, 2 );
        };

        // 3. Single-pass O(R) household & booking group balance indexing
        $family_index        = array();
        $booking_index       = array();
        $reconciled_due_list = array();

        foreach ( $raw_tourn_regs as $reg_idx => $reg ) {
            if ( ! is_array( $reg ) ) {
                continue;
            }
            $status_str = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status_str, 'Waitlist' ) === 0 || strcasecmp( $status_str, 'Cancelled' ) === 0 ) {
                continue;
            }

            $r_line    = sywat_desk_resolve_line_idx( $reg, $lines );
            $r_due     = $reconcile_record_due( $reg );
            $reconciled_due_list[ $reg_idx ] = $r_due;

            $r_paid    = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
            $r_rate    = isset( $reg['rate_type'] ) ? trim( (string) $reg['rate_type'] ) : '';
            $r_dist    = isset( $reg['distance'] ) ? trim( (string) $reg['distance'] ) : '';
            $is_fam    = ( strcasecmp( $r_paid, 'Family' ) === 0 ) || ( $r_rate !== '' && stripos( $r_rate, 'family' ) !== false );
            $is_exempt = ( strcasecmp( $r_paid, 'Field Governor' ) === 0 )
                || ! empty( $reg['is_promo_free'] )
                || ( strcasecmp( $r_paid, 'Promo (Free)' ) === 0 )
                || ( stripos( $r_paid, 'Promo' ) === 0 );

            $r_div     = isset( $reg['division'] ) ? trim( (string) $reg['division'] ) : '';
            $r_sty     = isset( $reg['style'] ) ? trim( (string) $reg['style'] ) : '';
            $r_round   = ! empty( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : $default_prof_key;
            $tier_hint = ! $is_fam
                ? ( $r_paid !== '' ? $r_paid : ( $r_rate !== '' ? $r_rate : ( strcasecmp( $r_dist, 'Cub' ) === 0 ? 'Cub' : '' ) ) )
                : ( $r_rate !== '' ? $r_rate : ( strcasecmp( $r_dist, 'Cub' ) === 0 ? 'Cub' : '' ) );
            $ind_fee   = $is_exempt ? 0.00 : sywat_desk_compute_individual_fee_pure( $r_div, $r_sty, $r_round, $t_profiles, $default_prof_key, $div_meta_lookup, $tier_hint );

            $fam_id = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
            if ( $fam_id !== '' ) {
                $fam_scope_key = $use_tourn_wide ? $fam_id : ( $fam_id . '|||' . $r_line );
                if ( ! isset( $family_index[ $fam_scope_key ] ) ) {
                    $family_index[ $fam_scope_key ] = array(
                        'max_due'      => 0.0,
                        'has_family'   => false,
                        'paid_count'   => 0,
                        'standard_sum' => 0.0,
                    );
                }
                $family_index[ $fam_scope_key ]['max_due'] = max( $family_index[ $fam_scope_key ]['max_due'], $r_due );
                if ( $is_fam ) {
                    $family_index[ $fam_scope_key ]['has_family'] = true;
                }
                if ( ! $is_exempt && strcasecmp( $r_div, 'No Show' ) !== 0 ) {
                    $family_index[ $fam_scope_key ]['paid_count']++;
                    $family_index[ $fam_scope_key ]['standard_sum'] += $ind_fee;
                }
            }

            $book_id = ! empty( $reg['booking_id'] ) ? trim( (string) $reg['booking_id'] ) : '';
            if ( $book_id !== '' ) {
                if ( ! isset( $booking_index[ $book_id ] ) ) {
                    $booking_index[ $book_id ] = array(
                        'max_due'    => 0.0,
                        'has_family' => false,
                    );
                }
                $booking_index[ $book_id ]['max_due'] = max( $booking_index[ $book_id ]['max_due'], $r_due );
                if ( $is_fam ) {
                    $booking_index[ $book_id ]['has_family'] = true;
                }
            }
        }

        // 4. Enrich every registration with formatted display fields & normalized modal JSON payload
        $formatted_tourn_regs = array();
        $active_checkins      = array();
        $modal_round_profiles = $t_profiles;

        foreach ( $raw_tourn_regs as $reg_idx => $reg ) {
            if ( ! is_array( $reg ) ) {
                continue;
            }

            $raw_name            = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : '';
            $disp_archer_name    = $has_lf_formatter ? sywat_format_name_last_first( $raw_name ) : $raw_name;
            $reg['display_name'] = $disp_archer_name;

            $status_str = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status_str, 'Waitlist' ) === 0 || strcasecmp( $status_str, 'Cancelled' ) === 0 ) {
                $formatted_tourn_regs[] = $reg;
                continue;
            }

            $reg_type       = ( isset( $reg['type'] ) && $reg['type'] === 'Walk-In' ) ? 'Walk-In' : 'Pre-Reg';
            $badge_class    = ( $reg_type === 'Walk-In' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg';
            $balance_due    = isset( $reconciled_due_list[ $reg_idx ] )
                ? $reconciled_due_list[ $reg_idx ]
                : ( isset( $reg['balance_due'] ) ? floatval( $reg['balance_due'] ) : 0.0 );
            $search_tokens  = strtolower( trim( $disp_archer_name . ' ' . $raw_name ) );
            $resolved_l_idx = sywat_desk_resolve_line_idx( $reg, $lines );
            $reg_line_name  = ( isset( $reg['line_name'] ) && trim( (string) $reg['line_name'] ) !== '' )
                ? trim( (string) $reg['line_name'] )
                : ( isset( $lines[ $resolved_l_idx ]['name'] ) ? trim( (string) $lines[ $resolved_l_idx ]['name'] ) : 'Line ' . ( intval( $resolved_l_idx ) + 1 ) );

            $is_line_closed = in_array( (string) $resolved_l_idx, $closed_lines, true ) || ( $reg_line_name !== '' && in_array( $reg_line_name, $closed_lines, true ) );
            $is_checked_in  = ! empty( $reg['checked_in'] );

            $raw_div    = ! empty( $reg['division'] ) ? trim( (string) $reg['division'] ) : '—';
            $is_no_show = ! $is_checked_in && ( $is_line_closed || $raw_div === 'No Show' );

            $div_disp    = $is_no_show ? 'No Show' : $raw_div;
            $gender_disp = $is_no_show ? 'No Show' : ( ! empty( $reg['gender'] ) ? trim( (string) $reg['gender'] ) : 'N/A' );
            $style_disp  = $is_no_show ? 'No Show' : ( ! empty( $reg['style'] ) ? trim( (string) $reg['style'] ) : '—' );
            $paid_disp   = $is_no_show ? 'No Show' : ( ! empty( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '—' );
            $dist_disp   = $is_no_show ? 'No Show' : ( ! empty( $reg['distance'] ) ? trim( (string) $reg['distance'] ) : 'N/A' );

            $raw_paid_str  = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
            $raw_rate_str  = isset( $reg['rate_type'] ) ? trim( (string) $reg['rate_type'] ) : '';
            $raw_dist_str  = isset( $reg['distance'] ) ? trim( (string) $reg['distance'] ) : '';
            $is_promo_free = ! $is_no_show && (
                ! empty( $reg['is_promo_free'] )
                || strcasecmp( $paid_disp, 'Promo (Free)' ) === 0
                || stripos( $paid_disp, 'Promo' ) === 0
            );

            $round_slug  = ! empty( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : '';
            $round_label = '';
            if ( $round_slug !== '' ) {
                if ( isset( $t_profiles[ $round_slug ] ) ) {
                    if ( $is_multi_round ) {
                        $round_label = $t_profiles[ $round_slug ]['label'];
                    }
                } else {
                    $fallback_r_label = ucwords( str_replace( array( '-', '_' ), ' ', $round_slug ) );
                    $modal_round_profiles[ $round_slug ] = array(
                        'slug'  => $round_slug,
                        'label' => $fallback_r_label,
                    );
                    if ( $is_multi_round ) {
                        $round_label = $fallback_r_label;
                    }
                }
            }

            $is_family_due = ! $is_no_show && (
                strcasecmp( $raw_paid_str, 'Family' ) === 0
                || ( $raw_rate_str !== '' && strtolower( $raw_rate_str ) === 'family' )
            );

            // O(1) household balance resolution from pre-indexed lookup maps
            $effective_due      = $balance_due;
            $has_family_group   = $is_family_due;
            $is_true_family_cap = false;

            $fam_id = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
            if ( $fam_id !== '' ) {
                $fam_scope_key = $use_tourn_wide ? $fam_id : ( $fam_id . '|||' . $resolved_l_idx );
                if ( isset( $family_index[ $fam_scope_key ] ) ) {
                    $f_entry       = $family_index[ $fam_scope_key ];
                    $effective_due = max( $effective_due, $f_entry['max_due'] );
                    if ( ! empty( $f_entry['has_family'] ) ) {
                        $has_family_group = true;
                    }
                    if ( $has_family_group && $cap_enabled && $f_entry['paid_count'] >= $cap_min && $f_entry['standard_sum'] > $cap_amount ) {
                        $is_true_family_cap = true;
                    }
                }
            }

            $book_id = ! empty( $reg['booking_id'] ) ? trim( (string) $reg['booking_id'] ) : '';
            if ( $book_id !== '' && isset( $booking_index[ $book_id ] ) ) {
                $b_entry       = $booking_index[ $book_id ];
                $effective_due = max( $effective_due, $b_entry['max_due'] );
                if ( ! empty( $b_entry['has_family'] ) ) {
                    $has_family_group = true;
                }
            }

            if ( $has_family_group && $effective_due >= $active_cap_amount ) {
                $is_true_family_cap = true;
            }

            $owe_badge_text = '';
            if ( ! $is_no_show && $effective_due > 0 ) {
                $owe_badge_text = ( $is_true_family_cap || ( $is_family_due && $has_family_group ) )
                    ? '⚠️ OWE: FAMILY'
                    : '⚠️ OWE $' . number_format( $effective_due, 2 );
            }

            // Normalize division & style for modal dropdown selection
            $norm_div = isset( $reg['division'] ) ? trim( (string) $reg['division'] ) : '';
            if ( $norm_div === '—' ) {
                $norm_div = '';
            }
            if ( isset( $legacy_div_map[ $norm_div ] ) ) {
                $norm_div = $legacy_div_map[ $norm_div ];
            }
            if ( $norm_div !== '' && strcasecmp( $norm_div, 'No Show' ) !== 0 && $has_norm_div ) {
                $norm_div = sywat_shooting_styles_normalize_division( $norm_div );
            }

            $norm_sty = isset( $reg['style'] ) ? trim( (string) $reg['style'] ) : '';
            if ( $norm_sty === '—' ) {
                $norm_sty = '';
            }
            if ( $norm_sty !== '' && strcasecmp( $norm_sty, 'No Show' ) !== 0 && $has_norm_style ) {
                $norm_sty = sywat_shooting_styles_normalize_style( $norm_sty );
            }

            $modal_round_slug = ( $round_slug !== '' && isset( $modal_round_profiles[ $round_slug ] ) )
                ? $round_slug
                : $default_prof_key;

            $fallback_ind_fee = 0.00;
            if ( ! $is_promo_free && strcasecmp( $raw_paid_str, 'Field Governor' ) !== 0 ) {
                $modal_tier_hint  = ! $is_family_due
                    ? ( $raw_paid_str !== '' ? $raw_paid_str : ( $raw_rate_str !== '' ? $raw_rate_str : ( strcasecmp( $raw_dist_str, 'Cub' ) === 0 ? 'Cub' : '' ) ) )
                    : ( $raw_rate_str !== '' ? $raw_rate_str : ( strcasecmp( $raw_dist_str, 'Cub' ) === 0 ? 'Cub' : '' ) );
                $fallback_ind_fee = sywat_desk_compute_individual_fee_pure(
                    $norm_div,
                    $norm_sty,
                    $modal_round_slug,
                    $t_profiles,
                    $default_prof_key,
                    $div_meta_lookup,
                    $modal_tier_hint
                );
            }

            $is_family_balance = ( strcasecmp( $raw_paid_str, 'Family' ) === 0 ) || $has_family_group;
            $display_due       = $is_family_balance ? $effective_due : ( $balance_due > 0 ? $balance_due : $fallback_ind_fee );

            if ( $is_promo_free ) {
                $pay_opt_val = 'Promo (Free)';
            } elseif ( strcasecmp( $raw_paid_str, 'Family' ) === 0 ) {
                $pay_opt_val = 'Family';
            } elseif ( strcasecmp( $raw_paid_str, 'Field Governor' ) === 0 ) {
                $pay_opt_val = 'Field Governor';
            } else {
                $pay_opt_val = 'Individual';
            }

            $reg['balance_due']        = round( $balance_due, 2 );
            $reg['resolved_line_idx']  = (string) $resolved_l_idx;
            $reg['resolved_line_name'] = $reg_line_name;
            $reg['search_tokens']      = $search_tokens;
            $reg['is_line_closed']     = $is_line_closed;
            $reg['is_checked_in']      = $is_checked_in;
            $reg['is_no_show']         = $is_no_show;
            $reg['is_promo_free']      = $is_promo_free;
            $reg['round_label']        = $round_label;
            $reg['owe_badge_text']     = $owe_badge_text;
            $reg['table_display']      = array(
                'type'        => $reg_type,
                'badge_class' => $badge_class,
                'line'        => $reg_line_name,
                'division'    => $div_disp,
                'gender'      => $gender_disp,
                'style'       => $style_disp,
                'paid'        => $paid_disp,
                'distance'    => $dist_disp,
            );
            $reg['modal_payload']      = array(
                'id'                => isset( $reg['id'] ) ? (string) $reg['id'] : '',
                'name'              => $raw_name,
                'display_name'      => $disp_archer_name,
                'line_index'        => (string) $resolved_l_idx,
                'line_name'         => $reg_line_name,
                'division'          => $norm_div,
                'gender'            => isset( $reg['gender'] ) ? (string) $reg['gender'] : '',
                'style'             => $norm_sty,
                'pricing_profile'   => $modal_round_slug,
                'paid'              => $raw_paid_str,
                'pay_option'        => $pay_opt_val,
                'is_promo_free'     => $is_promo_free,
                'family_id'         => $fam_id,
                'balance_due'       => round( $balance_due, 2 ),
                'effective_due'     => round( $effective_due, 2 ),
                'display_due'       => round( $display_due, 2 ),
                'is_family_balance' => $is_family_balance,
                'checked_in'        => $is_checked_in,
            );

            $formatted_tourn_regs[] = $reg;
            $active_checkins[]      = $reg;
        }

        $sort_by_line_and_surname = function( $a, $b ) {
            $line_a = isset( $a['resolved_line_idx'] ) ? (int) $a['resolved_line_idx'] : ( isset( $a['line_index'] ) ? (int) $a['line_index'] : 99 );
            $line_b = isset( $b['resolved_line_idx'] ) ? (int) $b['resolved_line_idx'] : ( isset( $b['line_index'] ) ? (int) $b['line_index'] : 99 );
            if ( $line_a !== $line_b ) {
                return $line_a <=> $line_b;
            }
            $disp_a = isset( $a['display_name'] ) ? trim( (string) $a['display_name'] ) : ( isset( $a['name'] ) ? trim( (string) $a['name'] ) : '' );
            $disp_b = isset( $b['display_name'] ) ? trim( (string) $b['display_name'] ) : ( isset( $b['name'] ) ? trim( (string) $b['name'] ) : '' );
            return strcasecmp( $disp_a, $disp_b );
        };

        usort( $formatted_tourn_regs, $sort_by_line_and_surname );
        usort( $active_checkins, $sort_by_line_and_surname );

        $data['tourn_registrations']  = $formatted_tourn_regs;
        $data['active_checkins']      = $active_checkins;
        $data['modal_round_profiles'] = $modal_round_profiles;

        // Refresh POST-fallback modal targets if present
        if ( ! empty( $data['modal_archer']['id'] ) ) {
            $m_id = (string) $data['modal_archer']['id'];
            foreach ( $active_checkins as $cand ) {
                if ( isset( $cand['id'] ) && (string) $cand['id'] === $m_id ) {
                    $data['modal_archer'] = $cand;
                    break;
                }
            }
        }
        if ( ! empty( $data['modal_edit_archer']['id'] ) ) {
            $e_id = (string) $data['modal_edit_archer']['id'];
            foreach ( $active_checkins as $cand ) {
                if ( isset( $cand['id'] ) && (string) $cand['id'] === $e_id ) {
                    $data['modal_edit_archer'] = $cand;
                    break;
                }
            }
        }
    }
}
