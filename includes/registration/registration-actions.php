<?php
/**
 * Sub-Module: Registration Engine Actions Handler
 * Path: includes/registration/registration-actions.php
 * Description: Handles multi-archer phone intake, Member Directory DOB & history profile resolution via sywat_resolve_archer_intake_profile(), round/pricing profile resolution, First-Time/Lapsed Shooter promoter free-entry evaluation, database-driven division/fee-tier resolution, dynamic Household Family Cap evaluation (line or tournament scope), waitlist triage ('all' vs. 'remainder'), and atomic multi-store persistence.
 * Compliance: Pure action layer — zero output at include time, zero inline styles, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'sywat_detect_paid_category' ) ) {
    function sywat_detect_paid_category( $text ) {
        $str = is_array( $text ) ? implode( ' ', $text ) : (string) $text;
        if ( preg_match( '/max\.?\s*per\s*family|family/i', $str ) ) {
            return 'Family';
        }
        if ( preg_match( '/cub\/youth\/young\s*adult|cub|youth|young\s*adult/i', $str ) ) {
            return 'Youth';
        }
        if ( preg_match( '/adult\s*categories|adult/i', $str ) ) {
            return 'Adult';
        }
        return '';
    }
}

if ( ! function_exists( 'sywat_extract_due_amount' ) ) {
    function sywat_extract_due_amount( $str ) {
        $clean = preg_replace( '/[^\d\.]/', '', (string) $str );
        return floatval( $clean );
    }
}

/**
 * Resolve a raw line index or line title string to its canonical array index key in $t_lines.
 *
 * @param string|int $raw_line_idx Raw submitted line index or title.
 * @param array      $t_lines      Tournament shooting lines array.
 * @return string                  Canonical string line index key.
 */
if ( ! function_exists( 'sywat_registration_resolve_line_idx' ) ) {
    function sywat_registration_resolve_line_idx( $raw_line_idx, $t_lines ) {
        $idx_str = trim( (string) $raw_line_idx );
        if ( ! is_array( $t_lines ) || empty( $t_lines ) ) {
            return $idx_str !== '' ? $idx_str : '0';
        }

        if ( isset( $t_lines[ $idx_str ] ) ) {
            return $idx_str;
        }

        foreach ( $t_lines as $l_key => $l_data ) {
            if ( is_array( $l_data ) && ! empty( $l_data['name'] ) && strcasecmp( trim( (string) $l_data['name'] ), $idx_str ) === 0 ) {
                return (string) $l_key;
            }
        }

        return $idx_str !== '' ? $idx_str : '0';
    }
}

/**
 * Handles multi-archer phone intake, Member Directory & history profile lookup,
 * round/pricing profile fee calculation, First-Time/Lapsed Shooter promoter evaluation,
 * active-only household family capping, waitlists, and persistence.
 *
 * @param array $tournaments   Master tournament configuration array.
 * @param array $registrations Master registration state array reference.
 * @return string              Rendered HTML notice message.
 */
if ( ! function_exists( 'sywat_handle_register_shooter' ) ) {
    function sywat_handle_register_shooter( $tournaments, &$registrations ) {
        $group_name       = trim( sanitize_text_field( wp_unslash( $_POST['group_name'] ?? '' ) ) );
        $group_phone      = trim( sanitize_text_field( wp_unslash( $_POST['group_phone'] ?? '' ) ) );
        $default_line_idx = sanitize_text_field( wp_unslash( $_POST['default_line_idx'] ?? '0' ) );
        $tourn_name       = sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ?? '' ) );
        $amount_paid      = isset( $_POST['amount_paid_today'] ) ? max( 0.00, floatval( wp_unslash( $_POST['amount_paid_today'] ) ) ) : 0.00;
        $allow_partial    = isset( $_POST['allow_partial'] ) && wp_unslash( $_POST['allow_partial'] ) === '1';
        $waitlist_mode    = sanitize_text_field( wp_unslash( $_POST['waitlist_mode'] ?? '' ) );
        $t_slug           = sanitize_title( $tourn_name );

        $t_data     = isset( $tournaments[ $tourn_name ] ) ? $tournaments[ $tourn_name ] : array();
        $tourn_date = ( is_array( $t_data ) && ! empty( $t_data['start_date'] ) ) ? (string) $t_data['start_date'] : '';
        $t_lines    = function_exists( 'sywat_get_tournament_lines' )
            ? sywat_get_tournament_lines( $t_data )
            : ( isset( $t_data['lines'] ) ? $t_data['lines'] : array() );

        // Resolve offered Pricing & Round Profiles for this tournament
        $t_profiles       = function_exists( 'sywat_get_tournament_pricing_profiles' )
            ? sywat_get_tournament_pricing_profiles( ! empty( $t_data ) ? $t_data : $tourn_name )
            : array();
        $default_prof     = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';

        $closed_lines_raw = get_option( 'sywat_closed_lines_' . $t_slug, array() );
        $closed_lines     = is_array( $closed_lines_raw ) ? array_map( 'trim', array_map( 'strval', $closed_lines_raw ) ) : array();

        $archers_raw = isset( $_POST['archers'] ) && is_array( $_POST['archers'] ) ? wp_unslash( $_POST['archers'] ) : array();

        if ( empty( $archers_raw ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please add at least one archer name.</div>';
        }

        // Resolve default Cub and 20yd Adult fallback divisions from database configuration
        $default_cub_div  = 'Cub (8 - 11)';
        $default_20yd_div = 'Adult (18 - 49)';
        if ( function_exists( 'sywat_get_division_records' ) ) {
            $active_divs = sywat_get_division_records( false );
            if ( is_array( $active_divs ) && ! empty( $active_divs ) ) {
                $found_cub  = '';
                $found_20yd = '';
                $first_20yd = '';
                foreach ( $active_divs as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    $rec_dist = isset( $rec['distance'] ) ? $rec['distance'] : '20yd';
                    $rec_tier = isset( $rec['rate_tier'] ) ? $rec['rate_tier'] : 'Adult';
                    if ( $rec_dist === 'Cub' && $found_cub === '' ) {
                        $found_cub = $rec['label'];
                    } elseif ( $rec_dist !== 'Cub' ) {
                        if ( $first_20yd === '' ) {
                            $first_20yd = $rec['label'];
                        }
                        if ( $rec_tier === 'Adult' && $found_20yd === '' ) {
                            $found_20yd = $rec['label'];
                        }
                    }
                }
                if ( $found_cub !== '' ) {
                    $default_cub_div = $found_cub;
                }
                if ( $found_20yd !== '' ) {
                    $default_20yd_div = $found_20yd;
                } elseif ( $first_20yd !== '' ) {
                    $default_20yd_div = $first_20yd;
                }
            }
        }

        $legacy_youth_divisions = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' );
        $valid_archers          = array();

        foreach ( $archers_raw as $a ) {
            $name = trim( sanitize_text_field( $a['name'] ?? '' ) );
            if ( empty( $name ) ) {
                continue;
            }

            $distance = function_exists( 'sywat_normalize_dist' )
                ? sywat_normalize_dist( sanitize_text_field( $a['distance'] ?? '20yd' ) )
                : sanitize_text_field( $a['distance'] ?? '20yd' );
            $division = trim( sanitize_text_field( $a['division'] ?? '' ) );

            // Resolve Member Directory (DOB age division, gender, contact) & cached shop/TFAA history
            $resolved_profile = function_exists( 'sywat_resolve_archer_intake_profile' )
                ? sywat_resolve_archer_intake_profile( $name, $tourn_date, array( 'phone' => $group_phone ) )
                : array();

            if ( $division !== '' && function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
                $division = sywat_shooting_styles_normalize_division( $division );
            }

            if ( empty( $division ) ) {
                if ( ! empty( $resolved_profile['division'] ) ) {
                    $division = $resolved_profile['division'];
                    if ( isset( $resolved_profile['distance'] ) && $resolved_profile['distance'] === 'Cub' ) {
                        $distance = 'Cub';
                    }
                } else {
                    $division = ( $distance === 'Cub' ) ? $default_cub_div : $default_20yd_div;
                }
            } elseif ( function_exists( 'sywat_shooting_styles_get_division_distance' ) ) {
                $div_dist = sywat_shooting_styles_get_division_distance( $division );
                if ( $div_dist === 'Cub' ) {
                    $distance = 'Cub';
                }
            }

            if ( $distance === 'Cub' ) {
                $paid_tier = 'Youth';
            } elseif ( function_exists( 'sywat_shooting_styles_get_division_rate_tier' ) ) {
                $paid_tier = sywat_shooting_styles_get_division_rate_tier( $division );
            } else {
                $paid_tier = in_array( $division, $legacy_youth_divisions, true ) ? 'Youth' : 'Adult';
            }

            $raw_round = ! empty( $a['pricing_profile'] ) ? sanitize_title( wp_unslash( $a['pricing_profile'] ) ) : $default_prof_key;
            if ( ! empty( $t_profiles ) && ! isset( $t_profiles[ $raw_round ] ) ) {
                $raw_round = $default_prof_key;
            }

            $raw_l_idx    = ! empty( $a['line_idx'] ) || ( isset( $a['line_idx'] ) && (string) $a['line_idx'] === '0' )
                ? sanitize_text_field( $a['line_idx'] )
                : $default_line_idx;
            $resolved_idx = sywat_registration_resolve_line_idx( $raw_l_idx, $t_lines );

            $valid_archers[] = array(
                'name'            => $name,
                'division'        => $division,
                'paid_tier'       => $paid_tier,
                'distance'        => $distance,
                'pricing_profile' => $raw_round,
                'line_idx'        => $resolved_idx,
                'gender'          => isset( $resolved_profile['gender'] ) ? $resolved_profile['gender'] : '',
                'style'           => isset( $resolved_profile['style'] ) ? $resolved_profile['style'] : '',
                'phone'           => ! empty( $group_phone ) ? $group_phone : ( isset( $resolved_profile['phone'] ) ? $resolved_profile['phone'] : '' ),
                'email'           => isset( $resolved_profile['email'] ) ? $resolved_profile['email'] : '',
            );
        }

        if ( empty( $valid_archers ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please enter at least one valid archer name.</div>';
        }

        // Tally requested slots per resolved line/distance combo
        $line_dist_tallies = array();
        foreach ( $valid_archers as $archer ) {
            $l_idx = $archer['line_idx'];
            $dist  = $archer['distance'];
            $key   = $l_idx . '___' . $dist;

            if ( ! isset( $line_dist_tallies[ $key ] ) ) {
                $line_dist_tallies[ $key ] = array(
                    'line_idx' => $l_idx,
                    'distance' => $dist,
                    'count'    => 0,
                );
            }
            $line_dist_tallies[ $key ]['count']++;
        }

        // Compute open slots per resolved line/distance (respecting closed lines)
        $remaining_open_slots = array();
        $capacity_overflows   = array();

        foreach ( $line_dist_tallies as $key => $tally ) {
            $l_idx     = $tally['line_idx'];
            $dist      = $tally['distance'];
            $req_cnt   = $tally['count'];
            $line_data = isset( $t_lines[ $l_idx ] ) ? $t_lines[ $l_idx ] : array();
            $line_name = isset( $line_data['name'] ) ? $line_data['name'] : ( is_numeric( $l_idx ) ? 'Line ' . ( (int) $l_idx + 1 ) : $l_idx );

            $is_line_closed = in_array( (string) $l_idx, $closed_lines, true )
                           || ( $line_name !== '' && in_array( trim( (string) $line_name ), $closed_lines, true ) );

            $current_count = function_exists( 'sywat_count_registered' )
                ? sywat_count_registered( $tourn_name, $l_idx, $dist, $registrations )
                : 0;
            $max_capacity  = function_exists( 'sywat_get_line_max_cap' )
                ? sywat_get_line_max_cap( $line_data, $dist )
                : 0;

            if ( ! $max_capacity || $max_capacity <= 0 ) {
                $max_capacity = ( $dist === 'Cub' ) ? 5 : 20;
            }

            $open_slots                   = $is_line_closed ? 0 : max( 0, $max_capacity - $current_count );
            $remaining_open_slots[ $key ] = $open_slots;

            if ( $req_cnt > $open_slots ) {
                $capacity_overflows[] = array(
                    'line_idx'   => $l_idx,
                    'line_name'  => $line_name,
                    'distance'   => $dist,
                    'requested'  => $req_cnt,
                    'open_slots' => $open_slots,
                );
            }
        }

        if ( ! empty( $capacity_overflows ) && empty( $waitlist_mode ) && ! $allow_partial ) {
            $overflow_details   = array();
            $max_open_available = 0;

            foreach ( $capacity_overflows as $ov ) {
                $overflow_details[] = 'Line <strong>' . esc_html( $ov['line_name'] ) . '</strong> (' . esc_html( $ov['distance'] ) . ') has <strong>' . intval( $ov['open_slots'] ) . ' open spot(s)</strong>, but you requested <strong>' . intval( $ov['requested'] ) . ' archer(s)</strong>.';
                if ( $ov['open_slots'] > $max_open_available ) {
                    $max_open_available = $ov['open_slots'];
                }
            }

            $hidden_archers_html = '';
            foreach ( $archers_raw as $i => $a ) {
                $hidden_archers_html .= '<input type="hidden" name="archers[' . intval( $i ) . '][name]" value="' . esc_attr( $a['name'] ?? '' ) . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . intval( $i ) . '][distance]" value="' . esc_attr( $a['distance'] ?? '' ) . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . intval( $i ) . '][division]" value="' . esc_attr( $a['division'] ?? '' ) . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . intval( $i ) . '][pricing_profile]" value="' . esc_attr( $a['pricing_profile'] ?? $default_prof_key ) . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . intval( $i ) . '][line_idx]" value="' . esc_attr( $a['line_idx'] ?? '' ) . '">';
            }

            $base_hidden_fields  = wp_nonce_field( 'sywat_desk_action', 'sywat_nonce', true, false );
            $base_hidden_fields .= '<input type="hidden" name="sywat_action" value="register_shooter">';
            $base_hidden_fields .= '<input type="hidden" name="selected_tournament" value="' . esc_attr( $tourn_name ) . '">';
            $base_hidden_fields .= '<input type="hidden" name="group_name" value="' . esc_attr( $group_name ) . '">';
            $base_hidden_fields .= '<input type="hidden" name="group_phone" value="' . esc_attr( $group_phone ) . '">';
            $base_hidden_fields .= '<input type="hidden" name="default_line_idx" value="' . esc_attr( $default_line_idx ) . '">';
            $base_hidden_fields .= '<input type="hidden" name="amount_paid_today" value="' . esc_attr( $amount_paid ) . '">';
            $base_hidden_fields .= $hidden_archers_html;

            $prompt_html  = '<div class="sywat-overflow-dialog">';
            $prompt_html .= '<div class="sywat-overflow-title">⚠️ GROUP CAPACITY OVERFLOW WARNING</div>';
            $prompt_html .= '<div class="sywat-overflow-body">' . implode( '<br>', $overflow_details ) . '</div>';
            $prompt_html .= '<p class="sywat-overflow-subtext">No archers were registered yet. Please select how you would like to handle this group:</p>';
            $prompt_html .= '<div class="sywat-overflow-actions">';

            $prompt_html .= '<form method="post" class="sywat-overflow-form">' . $base_hidden_fields;
            $prompt_html .= '<input type="hidden" name="waitlist_mode" value="all">';
            $prompt_html .= '<button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-block">🕒 Waitlist ENTIRE Group (Keep Group Together - $0 Due)</button>';
            $prompt_html .= '</form>';

            if ( $max_open_available > 0 ) {
                $prompt_html .= '<form method="post" class="sywat-overflow-form">' . $base_hidden_fields;
                $prompt_html .= '<input type="hidden" name="waitlist_mode" value="remainder">';
                $prompt_html .= '<button type="submit" class="sywat-btn sywat-btn-warning-solid sywat-btn-block">⚠️ Book Available Spots (' . intval( $max_open_available ) . ') & Waitlist Remainder</button>';
                $prompt_html .= '</form>';
            }

            $prompt_html .= '<button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-block" onclick="const m=document.getElementById(\'sywat_capacity_overflow_modal\'); if(m) m.remove();">Cancel & Change Line</button>';
            $prompt_html .= '</div></div>';

            $GLOBALS['sywat_modal_notice'] = $prompt_html;
            return '';
        }

        $family_base_name = ! empty( $group_name ) ? $group_name : $valid_archers[0]['name'];

        // Determine if any offered profile uses tournament-wide vs line-scoped family caps
        $use_tourn_wide_cap = false;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                $use_tourn_wide_cap = true;
                break;
            }
        }

        // Load full registration history once if promo checks are active on any offered profile
        $all_regs_for_promo = null;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['promo_enabled'] ) && function_exists( 'sywat_get_all_registrations' ) ) {
                $all_regs_for_promo = sywat_get_all_registrations();
                break;
            }
        }

        // Pass 1: Duplicate/capacity triage, Promo eligibility check, and active-only group stats
        $triaged_archers     = array();
        $active_group_stats  = array();
        $skipped_duplicates  = array();
        $skipped_full        = array();
        $claimed_promo_names = array();

        foreach ( $valid_archers as $archer ) {
            $l_idx      = $archer['line_idx'];
            $dist       = $archer['distance'];
            $name       = $archer['name'];
            $round_slug = $archer['pricing_profile'];
            $key        = $l_idx . '___' . $dist;
            $line_data  = isset( $t_lines[ $l_idx ] ) ? $t_lines[ $l_idx ] : array();
            $line_name  = isset( $line_data['name'] ) ? $line_data['name'] : ( is_numeric( $l_idx ) ? 'Line ' . ( (int) $l_idx + 1 ) : $l_idx );

            $is_duplicate = false;
            if ( function_exists( 'sywat_is_archer_on_line' ) ) {
                $is_duplicate = sywat_is_archer_on_line( $tourn_name, $l_idx, $name, $registrations );
            } else {
                foreach ( $registrations as $existing ) {
                    if ( isset( $existing['tournament'], $existing['line_index'], $existing['name'] ) &&
                         strcasecmp( trim( $existing['tournament'] ), trim( $tourn_name ) ) === 0 &&
                         (string) $existing['line_index'] === (string) $l_idx &&
                         strcasecmp( trim( $existing['name'] ), trim( $name ) ) === 0 ) {
                        $is_duplicate = true;
                        break;
                    }
                }
            }

            if ( $is_duplicate ) {
                $skipped_duplicates[] = $name . ' (' . $line_name . ')';
                continue;
            }

            $is_waitlist = false;
            if ( $waitlist_mode === 'all' ) {
                $is_waitlist = true;
            } elseif ( isset( $remaining_open_slots[ $key ] ) && $remaining_open_slots[ $key ] <= 0 ) {
                if ( $waitlist_mode === 'remainder' ) {
                    $is_waitlist = true;
                } else {
                    $skipped_full[] = $name . ' (' . $line_name . ' - ' . $dist . ')';
                    continue;
                }
            }

            if ( ! $is_waitlist && isset( $remaining_open_slots[ $key ] ) ) {
                $remaining_open_slots[ $key ]--;
            }

            // Evaluate First-Time / Lapsed Shooter Promotional Free Entry eligibility
            $is_promo_free = false;
            $norm_shooter  = strtolower( trim( $name ) );
            if ( ! $is_waitlist && ! isset( $claimed_promo_names[ $norm_shooter ] ) && function_exists( 'sywat_check_archer_promo_eligibility' ) ) {
                $promo_check = sywat_check_archer_promo_eligibility(
                    $name,
                    ! empty( $t_data ) ? $t_data : $tourn_name,
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
                    ? sywat_get_archer_individual_fee( ! empty( $t_data ) ? $t_data : $tourn_name, $archer['division'], $dist, '', $round_slug, '' )
                    : ( $archer['paid_tier'] === 'Youth' ? 25.00 : 30.00 ) );

            $prof_rec = function_exists( 'sywat_resolve_active_pricing_profile' )
                ? sywat_resolve_active_pricing_profile( ! empty( $t_data ) ? $t_data : $tourn_name, $round_slug )
                : $default_prof;

            $cap_bucket = $use_tourn_wide_cap ? 'tournament' : (string) $l_idx;

            if ( ! $is_waitlist && ! $is_promo_free ) {
                if ( ! isset( $active_group_stats[ $cap_bucket ] ) ) {
                    $active_group_stats[ $cap_bucket ] = array(
                        'count'       => 0,
                        'raw_sum'     => 0.00,
                        'cap_enabled' => false,
                        'cap_amount'  => 0.00,
                        'min_archers' => 99,
                    );
                }
                $active_group_stats[ $cap_bucket ]['count']++;
                $active_group_stats[ $cap_bucket ]['raw_sum'] += $ind_rate;

                if ( is_array( $prof_rec ) && ! empty( $prof_rec['family_cap_enabled'] ) ) {
                    $active_group_stats[ $cap_bucket ]['cap_enabled'] = true;
                    $p_cap = isset( $prof_rec['family_cap_amount'] ) ? floatval( $prof_rec['family_cap_amount'] ) : 75.00;
                    $p_min = isset( $prof_rec['family_min_archers'] ) ? max( 2, intval( $prof_rec['family_min_archers'] ) ) : 3;
                    if ( $p_cap > $active_group_stats[ $cap_bucket ]['cap_amount'] ) {
                        $active_group_stats[ $cap_bucket ]['cap_amount'] = $p_cap;
                    }
                    if ( $p_min < $active_group_stats[ $cap_bucket ]['min_archers'] ) {
                        $active_group_stats[ $cap_bucket ]['min_archers'] = $p_min;
                    }
                }
            }

            $archer['line_name']     = $line_name;
            $archer['is_waitlist']   = $is_waitlist;
            $archer['is_promo_free'] = $is_promo_free;
            $archer['ind_rate']      = $ind_rate;
            $archer['cap_bucket']    = $cap_bucket;
            $triaged_archers[]       = $archer;
        }

        // Pass 2: Assign fees, apply dynamic Household Family Cap, and persist
        $added_count             = 0;
        $waitlisted_count        = 0;
        $promo_applied_names     = array();
        $family_primary_assigned = array();
        $remaining_payment       = $amount_paid;
        $created_timestamp       = current_time( 'mysql' );
        $shared_tourn_fam_id     = 'fam_' . $t_slug . '_' . sanitize_title( $family_base_name );

        foreach ( $triaged_archers as $archer ) {
            $l_idx         = $archer['line_idx'];
            $dist          = $archer['distance'];
            $name          = $archer['name'];
            $line_name     = $archer['line_name'];
            $round_slug    = $archer['pricing_profile'];
            $is_waitlist   = $archer['is_waitlist'];
            $is_promo_free = $archer['is_promo_free'];
            $ind_rate      = $archer['ind_rate'];
            $cap_bucket    = $archer['cap_bucket'];

            $bucket_stat = isset( $active_group_stats[ $cap_bucket ] ) ? $active_group_stats[ $cap_bucket ] : null;
            $min_needed  = ( is_array( $bucket_stat ) && $bucket_stat['min_archers'] !== 99 ) ? $bucket_stat['min_archers'] : 3;

            $is_family_eligible = (
                ! $is_waitlist &&
                ! $is_promo_free &&
                is_array( $bucket_stat ) &&
                ! empty( $bucket_stat['cap_enabled'] ) &&
                $bucket_stat['count'] >= $min_needed &&
                $bucket_stat['raw_sum'] > $bucket_stat['cap_amount']
            );

            if ( $is_waitlist ) {
                $reg_status      = 'Waitlist';
                $paid_status     = 'Waitlist';
                $initial_fee     = 0.00;
                $due_amount      = 0.00;
                $paid_for_archer = 0.00;
            } elseif ( $is_promo_free ) {
                $reg_status            = 'Pre-Reg';
                $paid_status           = 'Promo (Free)';
                $initial_fee           = 0.00;
                $due_amount            = 0.00;
                $paid_for_archer       = 0.00;
                $promo_applied_names[] = $name;
            } else {
                $reg_status = 'Pre-Reg';
                if ( $is_family_eligible ) {
                    $paid_status = 'Family';
                    if ( empty( $family_primary_assigned[ $cap_bucket ] ) ) {
                        $initial_fee                            = floatval( $bucket_stat['cap_amount'] );
                        $family_primary_assigned[ $cap_bucket ] = true;
                    } else {
                        $initial_fee = 0.00;
                    }
                } else {
                    $paid_status = $archer['paid_tier'];
                    $initial_fee = $ind_rate;
                }

                if ( $remaining_payment >= $initial_fee ) {
                    $paid_for_archer   = $initial_fee;
                    $due_amount        = 0.00;
                    $remaining_payment = round( $remaining_payment - $initial_fee, 2 );
                } else {
                    $paid_for_archer   = $remaining_payment;
                    $due_amount        = round( $initial_fee - $remaining_payment, 2 );
                    $remaining_payment = 0.00;
                }
            }

            $fam_id_val = $use_tourn_wide_cap
                ? $shared_tourn_fam_id
                : ( 'fam_' . $t_slug . '_l' . sanitize_title( $l_idx ) . '_' . sanitize_title( $family_base_name ) );

            $new_reg = array(
                'id'              => uniqid( 'reg_' ),
                'tournament'      => $tourn_name,
                'line_index'      => $l_idx,
                'line_name'       => $line_name,
                'name'            => $name,
                'phone'           => $archer['phone'],
                'email'           => $archer['email'],
                'distance'        => $dist,
                'division'        => $archer['division'],
                'pricing_profile' => $round_slug,
                'is_promo_free'   => $is_promo_free,
                'gender'          => $archer['gender'],
                'style'           => $archer['style'],
                'paid'            => $paid_status,
                'family_id'       => $fam_id_val,
                'family_name'     => $family_base_name,
                'amount_paid'     => $paid_for_archer,
                'balance_due'     => $due_amount,
                'status'          => $reg_status,
                'created_at'      => $created_timestamp,
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
                $waitlisted_count++;
            } else {
                $added_count++;
            }
        }

        if ( ! empty( $group_phone ) && ( $added_count + $waitlisted_count ) > 0 ) {
            $phone_cache = get_option( 'sywat_shooter_phones_cache', array() );
            if ( ! is_array( $phone_cache ) ) {
                $phone_cache = array();
            }
            foreach ( $valid_archers as $archer ) {
                if ( ! empty( $archer['name'] ) ) {
                    $norm_name                 = strtolower( trim( $archer['name'] ) );
                    $phone_cache[ $norm_name ] = $group_phone;
                }
            }
            update_option( 'sywat_shooter_phones_cache', $phone_cache, false );
        }

        $GLOBALS['sywat_last_added_count']   = $added_count;
        $GLOBALS['sywat_skipped_duplicates'] = $skipped_duplicates;
        $GLOBALS['sywat_skipped_full']       = $skipped_full;

        $msg = '';
        if ( $added_count > 0 ) {
            $promo_suffix = ! empty( $promo_applied_names )
                ? ' (First-Time/Lapsed Promo $0.00 applied to: ' . esc_html( implode( ', ', $promo_applied_names ) ) . ')'
                : '';
            $msg .= '<div class="sywat-alert sywat-alert-success">Successfully registered ' . intval( $added_count ) . ' archer(s) under \'' . esc_html( $family_base_name ) . '\'' . $promo_suffix . '.</div>';
        }
        if ( $waitlisted_count > 0 ) {
            $msg .= '<div class="sywat-alert sywat-alert-warning">🕒 Added ' . intval( $waitlisted_count ) . ' archer(s) to the WAITLIST under \'' . esc_html( $family_base_name ) . '\' ($0 Balance Due).</div>';
        }
        if ( ! empty( $skipped_duplicates ) ) {
            $msg .= '<div class="sywat-alert sywat-alert-danger">⚠️ DUPLICATE BLOCKED: ' . implode( ', ', array_map( 'esc_html', $skipped_duplicates ) ) . ' — already registered on this shooting line!</div>';
        }
        if ( ! empty( $skipped_full ) ) {
            $msg .= '<div class="sywat-alert sywat-alert-danger">⚠️ LINE FULL BLOCKED: ' . implode( ', ', array_map( 'esc_html', $skipped_full ) ) . ' — target shooting line is at maximum capacity!</div>';
        }

        return $msg;
    }
}
