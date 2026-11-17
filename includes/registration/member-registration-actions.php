<?php
/**
 * Sub-Module: Member Self-Registration POST Action Processor
 * Path: includes/registration/member-registration-actions.php
 * Description: Nonce-verified POST submission handler for the Member Online Registration portal ([sywat_member_register]). Validates open-tournament status, line locks, and household ownership; prevents duplicate line entries; books open slots as Pre-Reg (balance due at check-in) or automatically places overflow entries on Waitlist ($0.00 due); evaluates First-Time/Lapsed Shooter promos and Household Family Caps; and persists records atomically.
 * Compliance: Pure action layer — zero HTML output at include time, zero inline styles, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Process a member self-registration form submission for the logged-in user's household.
 *
 * @param int $user_id Logged-in WordPress User ID.
 * @return array{
 *     submitted: bool,
 *     success: bool,
 *     tournament: string,
 *     line_name: string,
 *     registered: array,
 *     waitlisted: array,
 *     duplicates: array,
 *     promo_applied: array,
 *     family_cap_applied: bool,
 *     total_due: float,
 *     errors: array
 * }
 */
if ( ! function_exists( 'sywat_member_reg_handle_submission' ) ) {
    function sywat_member_reg_handle_submission( $user_id ) {
        $result = array(
            'submitted'          => true,
            'success'            => false,
            'tournament'         => '',
            'line_name'          => '',
            'registered'         => array(),
            'waitlisted'         => array(),
            'duplicates'         => array(),
            'promo_applied'      => array(),
            'family_cap_applied' => false,
            'total_due'          => 0.00,
            'errors'             => array(),
        );

        $user_id = intval( $user_id );
        if ( $user_id <= 0 ) {
            $result['errors'][] = 'You must be signed in to register online.';
            return $result;
        }

        $nonce = isset( $_POST['sywat_member_reg_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_member_reg_nonce'] ) ) : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'sywat_member_self_register' ) ) {
            $result['errors'][] = 'Your security session expired. Please refresh the page and try again.';
            return $result;
        }

        $tourn_name    = isset( $_POST['tournament_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tournament_name'] ) ) : '';
        $raw_line_idx  = isset( $_POST['line_idx'] ) ? sanitize_text_field( wp_unslash( $_POST['line_idx'] ) ) : '';
        $contact_phone = isset( $_POST['contact_phone'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['contact_phone'] ) ) ) : '';
        $raw_selected  = isset( $_POST['selected_members'] ) && is_array( $_POST['selected_members'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_members'] ) )
            : array();
        $posted_divs   = isset( $_POST['member_divisions'] ) && is_array( $_POST['member_divisions'] )
            ? wp_unslash( $_POST['member_divisions'] )
            : array();
        $posted_rounds = isset( $_POST['member_rounds'] ) && is_array( $_POST['member_rounds'] )
            ? wp_unslash( $_POST['member_rounds'] )
            : array();

        $result['tournament'] = $tourn_name;

        $open_tournaments = function_exists( 'sywat_member_reg_get_open_tournaments_data' )
            ? sywat_member_reg_get_open_tournaments_data()
            : array();

        if ( $tourn_name === '' || ! isset( $open_tournaments[ $tourn_name ] ) ) {
            $result['errors'][] = 'Online registration is not currently open for the selected tournament.';
            return $result;
        }

        if ( empty( $raw_selected ) ) {
            $result['errors'][] = 'Please select at least one archer from your household to register.';
            return $result;
        }

        $t_bundle     = $open_tournaments[ $tourn_name ];
        $t_raw        = $t_bundle['raw'];
        $t_slug       = $t_bundle['slug'];
        $t_lines      = $t_bundle['lines'];
        $closed_lines = $t_bundle['closed_lines'];
        $t_profiles   = $t_bundle['pricing_profiles'];

        $resolved_idx = function_exists( 'sywat_registration_resolve_line_idx' )
            ? sywat_registration_resolve_line_idx( $raw_line_idx, $t_lines )
            : (string) $raw_line_idx;

        if ( $resolved_idx === '' || ! isset( $t_lines[ $resolved_idx ] ) ) {
            $result['errors'][] = 'Please select a valid shooting line.';
            return $result;
        }

        $line_data = $t_lines[ $resolved_idx ];
        $line_name = function_exists( 'sywat_reg_resolve_line_label' )
            ? sywat_reg_resolve_line_label( $line_data, $resolved_idx )
            : ( ! empty( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ( intval( $resolved_idx ) + 1 ) );
        $result['line_name'] = $line_name;

        $raw_line_name  = ! empty( $line_data['name'] ) ? trim( (string) $line_data['name'] ) : '';
        $is_line_closed = in_array( (string) $resolved_idx, $closed_lines, true )
                       || ( $raw_line_name !== '' && in_array( $raw_line_name, $closed_lines, true ) );

        if ( $is_line_closed ) {
            $result['errors'][] = 'The selected shooting line (' . $line_name . ') has been closed by the host shop. Please choose another shooting line.';
            return $result;
        }

        $raw_household = function_exists( 'sywat_member_reg_get_household_raw_data' )
            ? sywat_member_reg_get_household_raw_data( $user_id )
            : array();
        $household     = function_exists( 'sywat_member_reg_format_household_roster' )
            ? sywat_member_reg_format_household_roster( $raw_household, $t_bundle['start_date'] )
            : array();

        if ( empty( $household ) ) {
            $result['errors'][] = 'Unable to load your household profile. Please update your Member Profile first.';
            return $result;
        }

        $head_key         = 'head_' . $user_id;
        $family_base_name = isset( $household[ $head_key ]['name'] ) ? $household[ $head_key ]['name'] : 'Household';
        if ( $contact_phone === '' && ! empty( $household[ $head_key ]['phone'] ) ) {
            $contact_phone = $household[ $head_key ]['phone'];
        }

        $default_prof     = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';

        $registrations = function_exists( 'sywat_member_reg_get_tournament_registrations' )
            ? sywat_member_reg_get_tournament_registrations( $tourn_name )
            : array();

        // Track remaining open slots on this line by distance ('20yd' and 'Cub')
        $open_slots_by_dist = array();
        foreach ( array( '20yd', 'Cub' ) as $d_key ) {
            $curr = function_exists( 'sywat_count_registered' )
                ? sywat_count_registered( $tourn_name, $resolved_idx, $d_key, $registrations )
                : 0;
            $max  = function_exists( 'sywat_get_line_max_cap' )
                ? sywat_get_line_max_cap( $line_data, $d_key )
                : ( $d_key === 'Cub' ? 5 : 20 );
            if ( $max <= 0 ) {
                $open_slots_by_dist[ $d_key ] = 0;
            } else {
                $open_slots_by_dist[ $d_key ] = max( 0, $max - $curr );
            }
        }

        // Load full registration history once if First-Time/Lapsed Shooter promos are active
        $all_regs_for_promo = null;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['promo_enabled'] ) && function_exists( 'sywat_get_all_registrations' ) ) {
                $all_regs_for_promo = sywat_get_all_registrations();
                break;
            }
        }

        $use_tourn_wide_cap = false;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                $use_tourn_wide_cap = true;
                break;
            }
        }

        // Pass 1: Validate selected household members, check duplicates, assign open slot vs. Waitlist, and compute fees
        $triaged_archers     = array();
        $claimed_promo_names = array();
        $active_group_stat   = array(
            'count'       => 0,
            'raw_sum'     => 0.00,
            'cap_enabled' => false,
            'cap_amount'  => 0.00,
            'min_archers' => 99,
        );

        foreach ( array_unique( $raw_selected ) as $m_key ) {
            if ( ! isset( $household[ $m_key ] ) ) {
                continue;
            }

            $member = $household[ $m_key ];
            $name   = $member['name'];

            $division = $member['division'];
            if ( isset( $posted_divs[ $m_key ] ) && trim( (string) $posted_divs[ $m_key ] ) !== '' ) {
                $candidate_div = trim( sanitize_text_field( (string) $posted_divs[ $m_key ] ) );
                if ( function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
                    $candidate_div = sywat_shooting_styles_normalize_division( $candidate_div );
                }
                if ( $candidate_div !== '' ) {
                    $division = $candidate_div;
                }
            }

            $distance = function_exists( 'sywat_shooting_styles_get_division_distance' )
                ? sywat_shooting_styles_get_division_distance( $division )
                : ( stripos( $division, 'Cub' ) !== false ? 'Cub' : '20yd' );
            $distance = function_exists( 'sywat_normalize_dist' ) ? sywat_normalize_dist( $distance ) : $distance;

            $rate_tier = ( $distance === 'Cub' )
                ? 'Youth'
                : ( function_exists( 'sywat_shooting_styles_get_division_rate_tier' )
                    ? sywat_shooting_styles_get_division_rate_tier( $division )
                    : $member['rate_tier'] );

            $round_slug = $default_prof_key;
            if ( isset( $posted_rounds[ $m_key ] ) && trim( (string) $posted_rounds[ $m_key ] ) !== '' ) {
                $cand_round = sanitize_title( (string) $posted_rounds[ $m_key ] );
                if ( isset( $t_profiles[ $cand_round ] ) ) {
                    $round_slug = $cand_round;
                }
            }

            $is_duplicate = function_exists( 'sywat_is_archer_on_line' )
                ? sywat_is_archer_on_line( $tourn_name, $resolved_idx, $name, $registrations )
                : false;

            if ( $is_duplicate ) {
                $result['duplicates'][] = $name . ' (' . $line_name . ')';
                continue;
            }

            $is_waitlist = ( ! isset( $open_slots_by_dist[ $distance ] ) || $open_slots_by_dist[ $distance ] <= 0 );
            if ( ! $is_waitlist ) {
                $open_slots_by_dist[ $distance ]--;
            }

            $is_promo_free = false;
            $norm_shooter  = strtolower( trim( $name ) );
            if ( ! $is_waitlist && ! isset( $claimed_promo_names[ $norm_shooter ] ) && function_exists( 'sywat_check_archer_promo_eligibility' ) ) {
                $promo_check = sywat_check_archer_promo_eligibility(
                    $name,
                    $t_raw,
                    $round_slug,
                    is_array( $all_regs_for_promo ) ? $all_regs_for_promo : $registrations
                );
                if ( ! empty( $promo_check['eligible'] ) ) {
                    $is_promo_free                        = true;
                    $claimed_promo_names[ $norm_shooter ] = true;
                }
            }

            $ind_rate = $is_promo_free
                ? 0.00
                : ( function_exists( 'sywat_get_archer_individual_fee' )
                    ? sywat_get_archer_individual_fee( $t_raw, $division, $distance, '', $round_slug, '' )
                    : ( $rate_tier === 'Youth' ? 25.00 : 30.00 ) );

            $prof_rec = function_exists( 'sywat_resolve_active_pricing_profile' )
                ? sywat_resolve_active_pricing_profile( $t_raw, $round_slug )
                : $default_prof;

            if ( ! $is_waitlist && ! $is_promo_free ) {
                $active_group_stat['count']++;
                $active_group_stat['raw_sum'] += $ind_rate;

                if ( is_array( $prof_rec ) && ! empty( $prof_rec['family_cap_enabled'] ) ) {
                    $active_group_stat['cap_enabled'] = true;
                    $p_cap = isset( $prof_rec['family_cap_amount'] ) ? floatval( $prof_rec['family_cap_amount'] ) : 75.00;
                    $p_min = isset( $prof_rec['family_min_archers'] ) ? max( 2, intval( $prof_rec['family_min_archers'] ) ) : 3;
                    if ( $p_cap > $active_group_stat['cap_amount'] ) {
                        $active_group_stat['cap_amount'] = $p_cap;
                    }
                    if ( $p_min < $active_group_stat['min_archers'] ) {
                        $active_group_stat['min_archers'] = $p_min;
                    }
                }
            }

            $triaged_archers[] = array(
                'member'        => $member,
                'name'          => $name,
                'division'      => $division,
                'distance'      => $distance,
                'rate_tier'     => $rate_tier,
                'round_slug'    => $round_slug,
                'is_waitlist'   => $is_waitlist,
                'is_promo_free' => $is_promo_free,
                'ind_rate'      => $ind_rate,
            );
        }

        if ( empty( $triaged_archers ) ) {
            if ( ! empty( $result['duplicates'] ) ) {
                $result['errors'][] = 'Selected archer(s) are already registered on ' . $line_name . ': ' . implode( ', ', $result['duplicates'] ) . '.';
            } else {
                $result['errors'][] = 'No eligible household archers were selected.';
            }
            return $result;
        }

        // Pass 2: Apply Household Family Cap (if eligible) and persist records
        $min_needed         = ( $active_group_stat['min_archers'] !== 99 ) ? $active_group_stat['min_archers'] : 3;
        $is_family_eligible = (
            ! empty( $active_group_stat['cap_enabled'] ) &&
            $active_group_stat['count'] >= $min_needed &&
            $active_group_stat['raw_sum'] > $active_group_stat['cap_amount']
        );

        $result['family_cap_applied'] = $is_family_eligible;
        $family_primary_assigned      = false;
        $created_timestamp            = current_time( 'mysql' );
        $fam_id_val                   = $use_tourn_wide_cap
            ? ( 'fam_' . $t_slug . '_u' . $user_id )
            : ( 'fam_' . $t_slug . '_l' . sanitize_title( $resolved_idx ) . '_u' . $user_id );

        foreach ( $triaged_archers as $item ) {
            $member        = $item['member'];
            $name          = $item['name'];
            $is_waitlist   = $item['is_waitlist'];
            $is_promo_free = $item['is_promo_free'];

            if ( $is_waitlist ) {
                $reg_status  = 'Waitlist';
                $paid_status = 'Waitlist';
                $due_amount  = 0.00;
            } elseif ( $is_promo_free ) {
                $reg_status                = 'Pre-Reg';
                $paid_status               = 'Promo (Free)';
                $due_amount                = 0.00;
                $result['promo_applied'][] = $name;
            } else {
                $reg_status = 'Pre-Reg';
                if ( $is_family_eligible ) {
                    $paid_status = 'Family';
                    if ( ! $family_primary_assigned ) {
                        $due_amount              = floatval( $active_group_stat['cap_amount'] );
                        $family_primary_assigned = true;
                    } else {
                        $due_amount = 0.00;
                    }
                } else {
                    $paid_status = $item['rate_tier'];
                    $due_amount  = floatval( $item['ind_rate'] );
                }
            }

            $new_reg = array(
                'id'                    => uniqid( 'reg_' ),
                'tournament'            => $tourn_name,
                'line_index'            => $resolved_idx,
                'line_name'             => $line_name,
                'name'                  => $name,
                'phone'                 => $contact_phone !== '' ? $contact_phone : $member['phone'],
                'email'                 => $member['email'],
                'distance'              => $item['distance'],
                'division'              => $item['division'],
                'pricing_profile'       => $item['round_slug'],
                'is_promo_free'         => $is_promo_free,
                'gender'                => $member['gender'],
                'style'                 => $member['style'],
                'paid'                  => $paid_status,
                'family_id'             => $fam_id_val,
                'family_name'           => $family_base_name,
                'amount_paid'           => 0.00,
                'balance_due'           => $due_amount,
                'status'                => $reg_status,
                'source'                => 'member_portal',
                'registered_by_user_id' => $user_id,
                'created_at'            => $created_timestamp,
            );

            if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                sywat_waitlist_persist_record( $new_reg, $registrations );
            } else {
                update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
                if ( ! is_array( $master_regs ) ) {
                    $master_regs = array();
                }
                $master_regs[ $new_reg['id'] ] = $new_reg;
                update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
                $registrations[] = $new_reg;
            }

            if ( $is_waitlist ) {
                $result['waitlisted'][] = $new_reg;
            } else {
                $result['registered'][] = $new_reg;
                $result['total_due']    = round( $result['total_due'] + $due_amount, 2 );
            }
        }

        if ( $contact_phone !== '' && ( ! empty( $result['registered'] ) || ! empty( $result['waitlisted'] ) ) ) {
            $phone_cache = get_option( 'sywat_shooter_phones_cache', array() );
            if ( ! is_array( $phone_cache ) ) {
                $phone_cache = array();
            }
            foreach ( $triaged_archers as $item ) {
                $norm_name                 = strtolower( trim( $item['name'] ) );
                $phone_cache[ $norm_name ] = $contact_phone;
            }
            update_option( 'sywat_shooter_phones_cache', $phone_cache, false );
        }

        $result['success'] = ( ! empty( $result['registered'] ) || ! empty( $result['waitlisted'] ) );
        return $result;
    }
}
