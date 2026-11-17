<?php
/**
 * Sub-Module: Member Self-Registration POST Action Processor
 * Path: includes/registration/member-registration-actions.php
 * Description: Nonce-verified POST submission handler for the Member Online Registration portal ([sywat_member_register]). Supports per-archer multi-line selections (mixed single-line and multi-line registrations across household members), validates open-tournament status, line locks, and household ownership; prevents per-line duplicate entries; books open slots as Pre-Reg (balance due at check-in) or automatically places overflow entries on Waitlist ($0.00 due); evaluates First-Time/Lapsed Shooter promos and per-line Household Family Caps; and persists records atomically.
 * Compliance: Pure action layer — zero HTML output at include time, zero inline styles, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Process a member self-registration form submission for the logged-in user's household.
 * Supports per-archer multi-line checkbox matrices (member_lines[member_key][]) so individual
 * household members can register for 1 or more shooting lines in a single submission.
 *
 * @param int $user_id Logged-in WordPress User ID.
 * @return array{
 *     submitted: bool,
 *     success: bool,
 *     tournament: string,
 *     line_name: string,
 *     line_summaries: array,
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
            'line_summaries'     => array(),
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

        $tourn_name          = isset( $_POST['tournament_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tournament_name'] ) ) : '';
        $contact_phone       = isset( $_POST['contact_phone'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['contact_phone'] ) ) ) : '';
        $posted_member_lines = isset( $_POST['member_lines'] ) && is_array( $_POST['member_lines'] )
            ? wp_unslash( $_POST['member_lines'] )
            : array();
        $posted_divs         = isset( $_POST['member_divisions'] ) && is_array( $_POST['member_divisions'] )
            ? wp_unslash( $_POST['member_divisions'] )
            : array();
        $posted_rounds       = isset( $_POST['member_rounds'] ) && is_array( $_POST['member_rounds'] )
            ? wp_unslash( $_POST['member_rounds'] )
            : array();

        // Backward-compatible fallback if single-line radio + selected_members[] payload is submitted
        if ( empty( $posted_member_lines ) && isset( $_POST['selected_members'] ) && is_array( $_POST['selected_members'] ) && isset( $_POST['line_idx'] ) ) {
            $fallback_line = sanitize_text_field( wp_unslash( $_POST['line_idx'] ) );
            if ( $fallback_line !== '' ) {
                foreach ( wp_unslash( $_POST['selected_members'] ) as $fb_member_key ) {
                    $clean_mk = sanitize_text_field( (string) $fb_member_key );
                    if ( $clean_mk !== '' ) {
                        $posted_member_lines[ $clean_mk ] = array( $fallback_line );
                    }
                }
            }
        }

        $result['tournament'] = $tourn_name;

        $open_tournaments = function_exists( 'sywat_member_reg_get_open_tournaments_data' )
            ? sywat_member_reg_get_open_tournaments_data()
            : array();

        if ( $tourn_name === '' || ! isset( $open_tournaments[ $tourn_name ] ) ) {
            $result['errors'][] = 'Online registration is not currently open for the selected tournament.';
            return $result;
        }

        $t_bundle     = $open_tournaments[ $tourn_name ];
        $t_raw        = $t_bundle['raw'];
        $t_slug       = $t_bundle['slug'];
        $t_lines      = $t_bundle['lines'];
        $closed_lines = $t_bundle['closed_lines'];
        $t_profiles   = $t_bundle['pricing_profiles'];

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

        // Group validated (member_key, line_idx) selections by shooting line index
        $raw_selections_by_line = array();
        $has_any_selection      = false;

        foreach ( $posted_member_lines as $raw_m_key => $line_list ) {
            $m_key = sanitize_text_field( (string) $raw_m_key );
            if ( ! isset( $household[ $m_key ] ) || ! is_array( $line_list ) ) {
                continue;
            }

            foreach ( $line_list as $raw_l_idx ) {
                $clean_l_idx = sanitize_text_field( (string) $raw_l_idx );
                if ( $clean_l_idx === '' ) {
                    continue;
                }

                $resolved_idx = function_exists( 'sywat_registration_resolve_line_idx' )
                    ? sywat_registration_resolve_line_idx( $clean_l_idx, $t_lines )
                    : $clean_l_idx;

                if ( $resolved_idx === '' || ! isset( $t_lines[ $resolved_idx ] ) ) {
                    $result['errors'][] = 'One or more selected shooting lines are invalid.';
                    return $result;
                }

                $line_data     = $t_lines[ $resolved_idx ];
                $line_name     = function_exists( 'sywat_reg_resolve_line_label' )
                    ? sywat_reg_resolve_line_label( $line_data, $resolved_idx )
                    : ( ! empty( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ( intval( $resolved_idx ) + 1 ) );
                $raw_line_name = ! empty( $line_data['name'] ) ? trim( (string) $line_data['name'] ) : '';

                $is_line_closed = in_array( (string) $resolved_idx, $closed_lines, true )
                               || ( $raw_line_name !== '' && in_array( $raw_line_name, $closed_lines, true ) );

                if ( $is_line_closed ) {
                    $result['errors'][] = 'The selected shooting line (' . $line_name . ') has been closed by the host shop. Please choose another shooting line.';
                    return $result;
                }

                if ( ! isset( $raw_selections_by_line[ $resolved_idx ] ) ) {
                    $raw_selections_by_line[ $resolved_idx ] = array();
                }
                if ( ! in_array( $m_key, $raw_selections_by_line[ $resolved_idx ], true ) ) {
                    $raw_selections_by_line[ $resolved_idx ][] = $m_key;
                    $has_any_selection                         = true;
                }
            }
        }

        if ( ! $has_any_selection || empty( $raw_selections_by_line ) ) {
            $result['errors'][] = 'Please check at least one shooting line box for at least one household archer.';
            return $result;
        }

        // Preserve chronological tournament line order
        $selections_by_line = array();
        foreach ( array_keys( $t_lines ) as $ordered_l_idx ) {
            $key_str = (string) $ordered_l_idx;
            if ( isset( $raw_selections_by_line[ $key_str ] ) ) {
                $selections_by_line[ $key_str ] = $raw_selections_by_line[ $key_str ];
            } elseif ( isset( $raw_selections_by_line[ $ordered_l_idx ] ) ) {
                $selections_by_line[ $ordered_l_idx ] = $raw_selections_by_line[ $ordered_l_idx ];
            }
        }
        foreach ( $raw_selections_by_line as $rem_idx => $rem_members ) {
            if ( ! isset( $selections_by_line[ $rem_idx ] ) ) {
                $selections_by_line[ $rem_idx ] = $rem_members;
            }
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

        $claimed_promo_names  = array();
        $selected_line_labels = array();
        $created_timestamp    = current_time( 'mysql' );
        $touched_archers      = array();

        // Process each selected shooting line independently for capacity and per-line Family Caps
        foreach ( $selections_by_line as $resolved_idx => $member_keys_for_line ) {
            $line_data = $t_lines[ $resolved_idx ];
            $line_name = function_exists( 'sywat_reg_resolve_line_label' )
                ? sywat_reg_resolve_line_label( $line_data, $resolved_idx )
                : ( ! empty( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ( intval( $resolved_idx ) + 1 ) );

            $open_slots_by_dist = array();
            foreach ( array( '20yd', 'Cub' ) as $d_key ) {
                $curr = function_exists( 'sywat_count_registered' )
                    ? sywat_count_registered( $tourn_name, $resolved_idx, $d_key, $registrations )
                    : 0;
                $max  = function_exists( 'sywat_get_line_max_cap' )
                    ? sywat_get_line_max_cap( $line_data, $d_key )
                    : ( $d_key === 'Cub' ? 5 : 20 );
                $open_slots_by_dist[ $d_key ] = ( $max <= 0 ) ? 0 : max( 0, $max - $curr );
            }

            $triaged_archers   = array();
            $active_group_stat = array(
                'count'       => 0,
                'raw_sum'     => 0.00,
                'cap_enabled' => false,
                'cap_amount'  => 0.00,
                'min_archers' => 99,
            );

            foreach ( $member_keys_for_line as $m_key ) {
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
                continue;
            }

            $selected_line_labels[] = $line_name;

            $fam_id_val = $use_tourn_wide_cap
                ? ( 'fam_' . $t_slug . '_u' . $user_id )
                : ( 'fam_' . $t_slug . '_l' . sanitize_title( $resolved_idx ) . '_u' . $user_id );

            // Account for existing active non-promo household registrations sharing this family_id on this line
            $existing_fam_count  = 0;
            $existing_fam_billed = 0.00;
            foreach ( $registrations as $ex_reg ) {
                if ( ! is_array( $ex_reg ) ) {
                    continue;
                }
                $ex_status = isset( $ex_reg['status'] ) ? trim( (string) $ex_reg['status'] ) : '';
                if ( strcasecmp( $ex_status, 'Waitlist' ) === 0 || strcasecmp( $ex_status, 'Cancelled' ) === 0 ) {
                    continue;
                }
                if ( ! empty( $ex_reg['is_promo_free'] ) || ( isset( $ex_reg['paid'] ) && strcasecmp( (string) $ex_reg['paid'], 'Promo (Free)' ) === 0 ) ) {
                    continue;
                }
                $ex_fam_id = isset( $ex_reg['family_id'] ) ? trim( (string) $ex_reg['family_id'] ) : '';
                if ( $ex_fam_id === $fam_id_val ) {
                    $existing_fam_count++;
                    $existing_fam_billed += floatval( isset( $ex_reg['amount_paid'] ) ? $ex_reg['amount_paid'] : 0 )
                                          + floatval( isset( $ex_reg['balance_due'] ) ? $ex_reg['balance_due'] : 0 );
                }
            }

            $min_needed         = ( $active_group_stat['min_archers'] !== 99 ) ? $active_group_stat['min_archers'] : 3;
            $combined_fam_count = $existing_fam_count + $active_group_stat['count'];
            $combined_fam_raw   = $existing_fam_billed + $active_group_stat['raw_sum'];
            $is_family_eligible = (
                ! empty( $active_group_stat['cap_enabled'] ) &&
                $active_group_stat['count'] > 0 &&
                $combined_fam_count >= $min_needed &&
                $combined_fam_raw > $active_group_stat['cap_amount']
            );

            if ( $is_family_eligible ) {
                $result['family_cap_applied'] = true;
            }

            $remaining_cap_due       = $is_family_eligible
                ? max( 0.00, round( floatval( $active_group_stat['cap_amount'] ) - $existing_fam_billed, 2 ) )
                : 0.00;
            $family_primary_assigned = false;

            $line_summary = array(
                'line_idx'           => $resolved_idx,
                'line_name'          => $line_name,
                'registered'         => array(),
                'waitlisted'         => array(),
                'total_due'          => 0.00,
                'family_cap_applied' => $is_family_eligible,
            );

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
                            $due_amount              = $remaining_cap_due;
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

                $touched_archers[ strtolower( trim( $name ) ) ] = true;

                if ( $is_waitlist ) {
                    $result['waitlisted'][]       = $new_reg;
                    $line_summary['waitlisted'][] = $new_reg;
                } else {
                    $result['registered'][]       = $new_reg;
                    $line_summary['registered'][] = $new_reg;
                    $line_summary['total_due']    = round( $line_summary['total_due'] + $due_amount, 2 );
                    $result['total_due']          = round( $result['total_due'] + $due_amount, 2 );
                }
            }

            if ( ! empty( $line_summary['registered'] ) || ! empty( $line_summary['waitlisted'] ) ) {
                $result['line_summaries'][] = $line_summary;
            }
        }

        if ( empty( $result['registered'] ) && empty( $result['waitlisted'] ) ) {
            if ( ! empty( $result['duplicates'] ) ) {
                $result['errors'][] = 'Selected archer(s) are already registered on the chosen line(s): ' . implode( ', ', $result['duplicates'] ) . '.';
            } else {
                $result['errors'][] = 'No eligible household archers or shooting lines were selected.';
            }
            return $result;
        }

        $result['line_name'] = implode( ', ', $selected_line_labels );

        if ( $contact_phone !== '' && ! empty( $touched_archers ) ) {
            $phone_cache = get_option( 'sywat_shooter_phones_cache', array() );
            if ( ! is_array( $phone_cache ) ) {
                $phone_cache = array();
            }
            foreach ( array_keys( $touched_archers ) as $norm_name ) {
                $phone_cache[ $norm_name ] = $contact_phone;
            }
            update_option( 'sywat_shooter_phones_cache', $phone_cache, false );
        }

        $result['success'] = true;
        return $result;
    }
}
