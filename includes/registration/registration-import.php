<?php
/**
 * Sub-Module: FareHarbor CSV & Raw Text Import Engine
 * Path: includes/registration/registration-import.php
 * Description: Handles CSV file and raw text payload roster imports for pre-registration intake, resolving each shooter against the Member Directory (DOB age-division calculation, style, gender, household ID) and 18-month TFAA/shop history before assigning pricing profile rates.
 * Author: SYWAT Archery
 * Version: 7.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves a shooter's division, distance, rate tier, style, gender, contact details, and household ID
 * from the Member Directory (DOB age calculation) with fallback to 18-month TFAA standings & shop history.
 *
 * @param string $archer_name   Shooter full name.
 * @param array  $t_data        Target tournament configuration array.
 * @param array  $registrations Existing registrations array.
 * @param array  $contact_hints Optional contact hints ('email', 'phone').
 * @return array Resolved shooter attributes.
 */
if ( ! function_exists( 'sywat_resolve_shooter_profile_from_directory_and_history' ) ) {
    function sywat_resolve_shooter_profile_from_directory_and_history( $archer_name, $t_data = array(), $registrations = array(), $contact_hints = array() ) {
        $result = array(
            'matched'      => false,
            'source'       => '',
            'division'     => '',
            'distance'     => '',
            'rate_tier'    => '',
            'gender'       => '',
            'style'        => '',
            'phone'        => isset( $contact_hints['phone'] ) ? trim( (string) $contact_hints['phone'] ) : '',
            'email'        => isset( $contact_hints['email'] ) ? trim( (string) $contact_hints['email'] ) : '',
            'family_id'    => '',
            'member_id'    => '',
        );

        $clean_name = trim( preg_replace( '/\s+/', ' ', (string) $archer_name ) );
        if ( $clean_name === '' ) {
            return $result;
        }
        $norm_target = strtolower( $clean_name );
        $hint_email  = strtolower( $result['email'] );
        $hint_phone  = preg_replace( '/\D+/', '', $result['phone'] );

        // Reference date for TFAA age calculation (tournament start date or today)
        $ref_date_str = ( is_array( $t_data ) && ! empty( $t_data['start_date'] ) ) ? (string) $t_data['start_date'] : current_time( 'Y-m-d' );
        $ref_ts       = strtotime( $ref_date_str );
        if ( $ref_ts === false ) {
            $ref_ts = current_time( 'timestamp' );
        }

        // Load active division definitions for age-bracket & rate-tier mapping
        $active_divs = function_exists( 'sywat_get_division_records' ) ? sywat_get_division_records( false ) : array();

        $resolve_div_from_dob = function( $dob_str ) use ( $ref_ts, $active_divs ) {
            $dob_str = trim( (string) $dob_str );
            if ( $dob_str === '' ) {
                return array( 'division' => '', 'distance' => '', 'rate_tier' => '' );
            }

            // Use dedicated Member Directory DOB helper if loaded
            if ( function_exists( 'sywat_calculate_division_from_dob' ) ) {
                $calc_div = sywat_calculate_division_from_dob( $dob_str, date( 'Y-m-d', $ref_ts ) );
                if ( ! empty( $calc_div ) ) {
                    $norm_div = function_exists( 'sywat_shooting_styles_normalize_division' )
                        ? sywat_shooting_styles_normalize_division( $calc_div )
                        : $calc_div;
                    $dist = ( stripos( $norm_div, 'Cub' ) !== false ) ? 'Cub' : '20yd';
                    $tier = ( stripos( $norm_div, 'Cub' ) !== false || stripos( $norm_div, 'Youth' ) !== false ) ? 'Youth' : 'Adult';
                    return array( 'division' => $norm_div, 'distance' => $dist, 'rate_tier' => $tier );
                }
            }

            $dob_ts = strtotime( $dob_str );
            if ( $dob_ts === false || $dob_ts > $ref_ts ) {
                return array( 'division' => '', 'distance' => '', 'rate_tier' => '' );
            }

            // Exact age as of tournament reference date
            $age = intval( date( 'Y', $ref_ts ) ) - intval( date( 'Y', $dob_ts ) );
            if ( date( 'md', $ref_ts ) < date( 'md', $dob_ts ) ) {
                $age--;
            }
            if ( $age < 0 ) {
                return array( 'division' => '', 'distance' => '', 'rate_tier' => '' );
            }

            // Match against configured division records if age bounds exist
            if ( is_array( $active_divs ) && ! empty( $active_divs ) ) {
                foreach ( $active_divs as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    $min_a = isset( $rec['min_age'] ) && $rec['min_age'] !== '' ? intval( $rec['min_age'] ) : null;
                    $max_a = isset( $rec['max_age'] ) && $rec['max_age'] !== '' ? intval( $rec['max_age'] ) : null;
                    if ( $min_a !== null || $max_a !== null ) {
                        $low  = ( $min_a !== null ) ? $min_a : 0;
                        $high = ( $max_a !== null ) ? $max_a : 150;
                        if ( $age >= $low && $age <= $high ) {
                            $norm_div = function_exists( 'sywat_shooting_styles_normalize_division' )
                                ? sywat_shooting_styles_normalize_division( $rec['label'] )
                                : $rec['label'];
                            $dist     = isset( $rec['distance'] ) ? $rec['distance'] : ( stripos( $norm_div, 'Cub' ) !== false ? 'Cub' : '20yd' );
                            $tier     = isset( $rec['rate_tier'] ) ? $rec['rate_tier'] : ( in_array( $dist, array( 'Cub' ), true ) || stripos( $norm_div, 'Youth' ) !== false ? 'Youth' : 'Adult' );
                            return array( 'division' => $norm_div, 'distance' => $dist, 'rate_tier' => $tier );
                        }
                    }
                }
            }

            // Standard TFAA age brackets fallback
            if ( $age <= 11 ) {
                $raw_div = 'Cub (8 - 11)';
                $dist    = 'Cub';
                $tier    = 'Youth';
            } elseif ( $age <= 14 ) {
                $raw_div = 'Youth (12 - 14)';
                $dist    = '20yd';
                $tier    = 'Youth';
            } elseif ( $age <= 17 ) {
                $raw_div = 'Young Adult (15 - 17)';
                $dist    = '20yd';
                $tier    = 'Youth';
            } elseif ( $age <= 49 ) {
                $raw_div = 'Adult (18 - 49)';
                $dist    = '20yd';
                $tier    = 'Adult';
            } elseif ( $age <= 59 ) {
                $raw_div = 'Senior (50 - 59)';
                $dist    = '20yd';
                $tier    = 'Adult';
            } elseif ( $age <= 69 ) {
                $raw_div = 'Silver Senior (60 - 69)';
                $dist    = '20yd';
                $tier    = 'Adult';
            } else {
                $raw_div = 'Master Senior (70+)';
                $dist    = '20yd';
                $tier    = 'Adult';
            }

            $norm_div = function_exists( 'sywat_shooting_styles_normalize_division' )
                ? sywat_shooting_styles_normalize_division( $raw_div )
                : $raw_div;

            return array( 'division' => $norm_div, 'distance' => $dist, 'rate_tier' => $tier );
        };

        $infer_div_meta = function( $raw_div ) use ( $active_divs ) {
            $norm_div = function_exists( 'sywat_shooting_styles_normalize_division' )
                ? sywat_shooting_styles_normalize_division( $raw_div )
                : trim( (string) $raw_div );
            if ( $norm_div === '' ) {
                return array( 'division' => '', 'distance' => '', 'rate_tier' => '' );
            }

            if ( is_array( $active_divs ) ) {
                foreach ( $active_divs as $rec ) {
                    if ( ! empty( $rec['label'] ) && strcasecmp( trim( $rec['label'] ), $norm_div ) === 0 ) {
                        $dist = isset( $rec['distance'] ) ? $rec['distance'] : ( stripos( $norm_div, 'Cub' ) !== false ? 'Cub' : '20yd' );
                        $tier = isset( $rec['rate_tier'] ) ? $rec['rate_tier'] : ( $dist === 'Cub' || stripos( $norm_div, 'Youth' ) !== false ? 'Youth' : 'Adult' );
                        return array( 'division' => $norm_div, 'distance' => $dist, 'rate_tier' => $tier );
                    }
                }
            }

            $dist = ( stripos( $norm_div, 'Cub' ) !== false ) ? 'Cub' : '20yd';
            $tier = ( $dist === 'Cub' || stripos( $norm_div, 'Youth' ) !== false ) ? 'Youth' : 'Adult';
            return array( 'division' => $norm_div, 'distance' => $dist, 'rate_tier' => $tier );
        };

        // -----------------------------------------------------------------
        // 1. MEMBER DIRECTORY LOOKUP (Heads of Household, Dependents, Pool)
        // -----------------------------------------------------------------
        $candidate_records = array();

        $extract_person_name = function( $rec ) {
            if ( ! is_array( $rec ) ) {
                return '';
            }
            if ( ! empty( $rec['full_name'] ) ) {
                return trim( (string) $rec['full_name'] );
            }
            if ( ! empty( $rec['name'] ) ) {
                return trim( (string) $rec['name'] );
            }
            $fn = isset( $rec['first_name'] ) ? trim( (string) $rec['first_name'] ) : '';
            $ln = isset( $rec['last_name'] ) ? trim( (string) $rec['last_name'] ) : '';
            return trim( $fn . ' ' . $ln );
        };

        // Gather records from any available Member Directory store
        $raw_households = function_exists( 'sywat_get_all_households' )
            ? sywat_get_all_households()
            : get_option( 'sywat_households', get_option( 'sywat_members_list', array() ) );

        if ( is_array( $raw_households ) ) {
            foreach ( $raw_households as $hh_key => $hh ) {
                if ( ! is_array( $hh ) ) {
                    continue;
                }
                $hh_id    = ! empty( $hh['household_id'] ) ? (string) $hh['household_id'] : ( ! empty( $hh['id'] ) ? (string) $hh['id'] : (string) $hh_key );
                $hh_email = isset( $hh['email'] ) ? trim( (string) $hh['email'] ) : '';
                $hh_phone = isset( $hh['phone'] ) ? trim( (string) $hh['phone'] ) : '';

                // Head of Household record itself
                $hoh_name = $extract_person_name( $hh );
                if ( $hoh_name !== '' ) {
                    $candidate_records[] = array_merge( $hh, array(
                        '_resolved_name' => $hoh_name,
                        '_household_id'  => $hh_id,
                        '_fallback_mail' => $hh_email,
                        '_fallback_tel'  => $hh_phone,
                    ) );
                }

                // Household dependents / family members
                foreach ( array( 'members', 'dependents', 'archers', 'family_members' ) as $sub_key ) {
                    if ( ! empty( $hh[ $sub_key ] ) && is_array( $hh[ $sub_key ] ) ) {
                        foreach ( $hh[ $sub_key ] as $m_idx => $dep ) {
                            if ( ! is_array( $dep ) ) {
                                continue;
                            }
                            $dep_name = $extract_person_name( $dep );
                            if ( $dep_name !== '' ) {
                                $candidate_records[] = array_merge( $dep, array(
                                    '_resolved_name' => $dep_name,
                                    '_household_id'  => $hh_id,
                                    '_fallback_mail' => ! empty( $dep['email'] ) ? $dep['email'] : $hh_email,
                                    '_fallback_tel'  => ! empty( $dep['phone'] ) ? $dep['phone'] : $hh_phone,
                                ) );
                            }
                        }
                    }
                }
            }
        }

        // Unassigned / standalone member pool
        $unassigned_pool = function_exists( 'sywat_get_unassigned_archers' )
            ? sywat_get_unassigned_archers()
            : get_option( 'sywat_unassigned_archers', array() );

        if ( is_array( $unassigned_pool ) ) {
            foreach ( $unassigned_pool as $u_key => $u_rec ) {
                if ( ! is_array( $u_rec ) ) {
                    continue;
                }
                $u_name = $extract_person_name( $u_rec );
                if ( $u_name !== '' ) {
                    $candidate_records[] = array_merge( $u_rec, array(
                        '_resolved_name' => $u_name,
                        '_household_id'  => ! empty( $u_rec['household_id'] ) ? (string) $u_rec['household_id'] : '',
                        '_fallback_mail' => isset( $u_rec['email'] ) ? (string) $u_rec['email'] : '',
                        '_fallback_tel'  => isset( $u_rec['phone'] ) ? (string) $u_rec['phone'] : '',
                    ) );
                }
            }
        }

        $matched_member = null;
        foreach ( $candidate_records as $cand ) {
            $cand_norm = strtolower( preg_replace( '/\s+/', ' ', $cand['_resolved_name'] ) );
            if ( $cand_norm !== $norm_target ) {
                continue;
            }

            if ( $matched_member === null ) {
                $matched_member = $cand;
            }

            // Prefer exact email/phone match if multiple members share the same name
            $cand_mail = strtolower( trim( (string) $cand['_fallback_mail'] ) );
            $cand_tel  = preg_replace( '/\D+/', '', (string) $cand['_fallback_tel'] );
            if ( ( $hint_email !== '' && $cand_mail === $hint_email ) || ( $hint_phone !== '' && strlen( $hint_phone ) >= 7 && $cand_tel === $hint_phone ) ) {
                $matched_member = $cand;
                break;
            }
        }

        if ( is_array( $matched_member ) ) {
            $result['matched']   = true;
            $result['source']    = 'member_directory';
            $result['member_id'] = ! empty( $matched_member['id'] ) ? (string) $matched_member['id'] : '';
            $result['family_id'] = ! empty( $matched_member['_household_id'] ) ? 'hh_' . sanitize_key( $matched_member['_household_id'] ) : '';

            if ( $result['email'] === '' && ! empty( $matched_member['_fallback_mail'] ) ) {
                $result['email'] = trim( (string) $matched_member['_fallback_mail'] );
            }
            if ( $result['phone'] === '' && ! empty( $matched_member['_fallback_tel'] ) ) {
                $result['phone'] = trim( (string) $matched_member['_fallback_tel'] );
            }
            if ( ! empty( $matched_member['gender'] ) ) {
                $result['gender'] = trim( (string) $matched_member['gender'] );
            }
            $m_style = ! empty( $matched_member['style'] )
                ? $matched_member['style']
                : ( ! empty( $matched_member['shooting_style'] ) ? $matched_member['shooting_style'] : '' );
            if ( $m_style !== '' ) {
                $result['style'] = trim( (string) $m_style );
            }

            // Calculate TFAA division from Member Directory DOB
            $m_dob = ! empty( $matched_member['dob'] )
                ? $matched_member['dob']
                : ( ! empty( $matched_member['birth_date'] ) ? $matched_member['birth_date'] : '' );

            if ( $m_dob !== '' ) {
                $dob_meta = $resolve_div_from_dob( $m_dob );
                if ( $dob_meta['division'] !== '' ) {
                    $result['division']  = $dob_meta['division'];
                    $result['distance']  = $dob_meta['distance'];
                    $result['rate_tier'] = $dob_meta['rate_tier'];
                }
            } elseif ( ! empty( $matched_member['division'] ) ) {
                $div_meta            = $infer_div_meta( $matched_member['division'] );
                $result['division']  = $div_meta['division'];
                $result['distance']  = $div_meta['distance'];
                $result['rate_tier'] = $div_meta['rate_tier'];
            }
        }

        // -----------------------------------------------------------------
        // 2. 18-MONTH SHOP REGISTRATIONS & TFAA STANDINGS HISTORY FALLBACK
        // -----------------------------------------------------------------
        $cutoff_ts = strtotime( '-18 months', $ref_ts );

        if ( $result['division'] === '' || $result['style'] === '' || $result['gender'] === '' ) {
            $all_hist_regs = is_array( $registrations ) ? $registrations : array();
            if ( empty( $all_hist_regs ) && function_exists( 'sywat_get_all_registrations' ) ) {
                $all_hist_regs = sywat_get_all_registrations();
            }

            $latest_hist_ts  = 0;
            $latest_hist_rec = null;

            foreach ( $all_hist_regs as $h_reg ) {
                if ( ! is_array( $h_reg ) || empty( $h_reg['name'] ) ) {
                    continue;
                }
                if ( strtolower( trim( preg_replace( '/\s+/', ' ', $h_reg['name'] ) ) ) !== $norm_target ) {
                    continue;
                }
                $h_status = isset( $h_reg['status'] ) ? trim( $h_reg['status'] ) : '';
                if ( strcasecmp( $h_status, 'Cancelled' ) === 0 ) {
                    continue;
                }

                $h_date = ! empty( $h_reg['created_at'] ) ? strtotime( $h_reg['created_at'] ) : $ref_ts;
                if ( $h_date !== false && $h_date >= $cutoff_ts && $h_date >= $latest_hist_ts ) {
                    $latest_hist_ts  = $h_date;
                    $latest_hist_rec = $h_reg;
                }
            }

            if ( is_array( $latest_hist_rec ) ) {
                if ( ! $result['matched'] ) {
                    $result['matched'] = true;
                    $result['source']  = 'shop_history';
                }
                if ( $result['division'] === '' && ! empty( $latest_hist_rec['division'] ) ) {
                    $div_meta            = $infer_div_meta( $latest_hist_rec['division'] );
                    $result['division']  = $div_meta['division'];
                    $result['distance']  = $div_meta['distance'];
                    $result['rate_tier'] = $div_meta['rate_tier'];
                }
                if ( $result['style'] === '' && ! empty( $latest_hist_rec['style'] ) ) {
                    $result['style'] = trim( (string) $latest_hist_rec['style'] );
                }
                if ( $result['gender'] === '' && ! empty( $latest_hist_rec['gender'] ) ) {
                    $result['gender'] = trim( (string) $latest_hist_rec['gender'] );
                }
                if ( $result['phone'] === '' && ! empty( $latest_hist_rec['phone'] ) ) {
                    $result['phone'] = trim( (string) $latest_hist_rec['phone'] );
                }
            }
        }

        // Check cached TFAA standings if still missing division, style, or gender
        if ( $result['division'] === '' || $result['style'] === '' || $result['gender'] === '' ) {
            $tfaa_cache = get_option( 'sywat_tfaa_standings_cache', array() );
            if ( empty( $tfaa_cache ) ) {
                $tfaa_cache = get_transient( 'sywat_tfaa_standings_cache' );
            }
            if ( is_array( $tfaa_cache ) ) {
                $entries = isset( $tfaa_cache['shooters'] ) && is_array( $tfaa_cache['shooters'] ) ? $tfaa_cache['shooters'] : $tfaa_cache;
                foreach ( $entries as $t_entry ) {
                    if ( ! is_array( $t_entry ) ) {
                        continue;
                    }
                    $e_name = ! empty( $t_entry['name'] ) ? $t_entry['name'] : ( ! empty( $t_entry['archer_name'] ) ? $t_entry['archer_name'] : '' );
                    if ( strtolower( trim( preg_replace( '/\s+/', ' ', (string) $e_name ) ) ) !== $norm_target ) {
                        continue;
                    }

                    if ( ! $result['matched'] ) {
                        $result['matched'] = true;
                        $result['source']  = 'tfaa_standings';
                    }
                    if ( $result['division'] === '' && ! empty( $t_entry['division'] ) ) {
                        $div_meta            = $infer_div_meta( $t_entry['division'] );
                        $result['division']  = $div_meta['division'];
                        $result['distance']  = $div_meta['distance'];
                        $result['rate_tier'] = $div_meta['rate_tier'];
                    }
                    if ( $result['style'] === '' && ! empty( $t_entry['style'] ) ) {
                        $result['style'] = trim( (string) $t_entry['style'] );
                    }
                    if ( $result['gender'] === '' && ! empty( $t_entry['gender'] ) ) {
                        $result['gender'] = trim( (string) $t_entry['gender'] );
                    }
                    break;
                }
            }
        }

        return $result;
    }
}

/**
 * Handles CSV file and raw text payload roster imports for pre-registration intake.
 *
 * @param string $tourn_name       Selected tournament name (unslashed).
 * @param string $target_line_idx  Target shooting line index key.
 * @param array  $tournaments      Master tournament configuration array.
 * @param array  $registrations    Master registration state array reference.
 * @return string Rendered HTML notice message.
 */
if ( ! function_exists( 'sywat_handle_import_shooters' ) ) {
    function sywat_handle_import_shooters( $tourn_name, $target_line_idx, $tournaments, &$registrations ) {
        $tourn_name      = sanitize_text_field( wp_unslash( $tourn_name ) );
        $target_line_idx = sanitize_text_field( wp_unslash( $target_line_idx ) );
        $t_data          = isset( $tournaments[ $tourn_name ] ) ? $tournaments[ $tourn_name ] : array();
        $t_lines         = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : ( isset( $t_data['lines'] ) ? $t_data['lines'] : $t_data );
        $t_slug          = sanitize_title( $tourn_name );
        $allow_partial   = isset( $_POST['allow_partial'] ) && wp_unslash( $_POST['allow_partial'] ) === '1';
        $imported_count  = 0;
        $skipped_dup     = 0;
        $skipped_cap     = 0;
        $touched_lines   = array();

        // Resolve primary Pricing & Round Profile slug for imported records in this tournament
        $t_profiles       = function_exists( 'sywat_get_tournament_pricing_profiles' )
            ? sywat_get_tournament_pricing_profiles( ! empty( $t_data ) ? $t_data : $tourn_name )
            : array();
        $default_prof     = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';

        // Helper to compute standard entry rate from the active pricing profile when a booking has an unpaid balance
        $resolve_profile_fee = function( $division_label, $rate_tier ) use ( $default_prof, $tourn_name ) {
            if ( function_exists( 'sywat_calculate_shooter_entry_fee' ) ) {
                $fee_info = sywat_calculate_shooter_entry_fee( array(
                    'tournament'      => $tourn_name,
                    'division'        => $division_label,
                    'paid'            => $rate_tier,
                    'pricing_profile' => is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor',
                ) );
                if ( is_array( $fee_info ) && isset( $fee_info['fee'] ) ) {
                    return floatval( $fee_info['fee'] );
                }
            }

            if ( is_array( $default_prof ) ) {
                if ( ! empty( $default_prof['division_overrides'] ) && is_array( $default_prof['division_overrides'] ) ) {
                    $div_slug = sanitize_title( $division_label );
                    if ( isset( $default_prof['division_overrides'][ $div_slug ] ) && is_numeric( $default_prof['division_overrides'][ $div_slug ] ) ) {
                        return floatval( $default_prof['division_overrides'][ $div_slug ] );
                    }
                }
                if ( strcasecmp( $rate_tier, 'Youth' ) === 0 && isset( $default_prof['youth_rate'] ) ) {
                    return floatval( $default_prof['youth_rate'] );
                }
                if ( isset( $default_prof['adult_rate'] ) ) {
                    return floatval( $default_prof['adult_rate'] );
                }
            }

            return ( strcasecmp( $rate_tier, 'Youth' ) === 0 ) ? 25.00 : 30.00;
        };

        // Resolve default Cub, Youth (20yd), and Adult (20yd) divisions from database configuration
        $default_cub_div   = 'Cub (8 - 11)';
        $default_youth_div = 'Youth (12 - 14)';
        $default_adult_div = 'Adult (18 - 49)';

        if ( function_exists( 'sywat_get_division_records' ) ) {
            $active_divs = sywat_get_division_records( false );
            if ( is_array( $active_divs ) && ! empty( $active_divs ) ) {
                $found_cub   = '';
                $found_youth = '';
                $found_adult = '';
                $first_20yd  = '';

                foreach ( $active_divs as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    $rec_dist = isset( $rec['distance'] ) ? $rec['distance'] : '20yd';
                    $rec_tier = isset( $rec['rate_tier'] ) ? $rec['rate_tier'] : 'Adult';

                    if ( $rec_dist === 'Cub' ) {
                        if ( $found_cub === '' ) {
                            $found_cub = $rec['label'];
                        }
                    } else {
                        if ( $first_20yd === '' ) {
                            $first_20yd = $rec['label'];
                        }
                        if ( $rec_tier === 'Youth' && $found_youth === '' ) {
                            $found_youth = $rec['label'];
                        } elseif ( $rec_tier === 'Adult' && $found_adult === '' ) {
                            $found_adult = $rec['label'];
                        }
                    }
                }

                if ( $found_cub !== '' ) {
                    $default_cub_div = $found_cub;
                }
                if ( $found_youth !== '' ) {
                    $default_youth_div = $found_youth;
                } elseif ( $first_20yd !== '' ) {
                    $default_youth_div = $first_20yd;
                }
                if ( $found_adult !== '' ) {
                    $default_adult_div = $found_adult;
                } elseif ( $first_20yd !== '' ) {
                    $default_adult_div = $first_20yd;
                }
            }
        }

        $raw_text_payload = ! empty( $_POST['pasted_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pasted_text'] ) ) : '';

        if ( ! empty( $raw_text_payload ) ) {
            $lines                = explode( "\n", $raw_text_payload );
            $current_dist         = '20yd';
            $current_paid_context = '';
            $in_intro             = false;

            $blacklist_phrases = array( 'sywat', 'cinnamon creek', 'intro to archery', 'full name', 'adult categories', 'cub/youth/young adult' );
            $blacklist_words   = array( 'contact', 'total', 'due', 'bookings', 'archers', 'generated' );

            $parsed_shooters = array();

            foreach ( $lines as $raw_line ) {
                $clean_line = trim( $raw_line );
                if ( empty( $clean_line ) ) {
                    continue;
                }

                if ( stripos( $clean_line, 'Intro To Archery' ) !== false ) {
                    $in_intro = true;
                    continue;
                }
                if ( $in_intro ) {
                    continue;
                }

                $detected_hdr_paid = function_exists( 'sywat_detect_paid_category' ) ? sywat_detect_paid_category( $clean_line ) : '';
                if ( ! empty( $detected_hdr_paid ) ) {
                    $current_paid_context = $detected_hdr_paid;
                }

                if ( stripos( $clean_line, '10 YARDS' ) !== false && stripos( $clean_line, 'SYWAT' ) !== false ) {
                    $current_dist = 'Cub';
                    continue;
                }
                if ( stripos( $clean_line, '20 YARDS' ) !== false && stripos( $clean_line, 'SYWAT' ) !== false ) {
                    $current_dist = '20yd';
                    continue;
                }

                if ( strpos( $clean_line, ',' ) !== false || strpos( $clean_line, '@' ) !== false || strpos( $clean_line, '$' ) !== false || preg_match( '/[0-9]/', $clean_line ) ) {
                    continue;
                }

                $is_blacklisted = false;
                foreach ( $blacklist_phrases as $phrase ) {
                    if ( stripos( $clean_line, $phrase ) !== false ) {
                        $is_blacklisted = true;
                        break;
                    }
                }

                if ( ! $is_blacklisted ) {
                    foreach ( $blacklist_words as $kw ) {
                        if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/i', $clean_line ) ) {
                            $is_blacklisted = true;
                            break;
                        }
                    }
                }

                if ( $is_blacklisted ) {
                    continue;
                }

                $clean_line = trim( ltrim( $clean_line, '| ' ) );
                if ( strlen( $clean_line ) < 2 || strlen( $clean_line ) > 60 ) {
                    continue;
                }

                // Resolve shooter against Member Directory (DOB age) and 18-month history
                $resolved   = sywat_resolve_shooter_profile_from_directory_and_history( $clean_line, $t_data, $registrations );
                $dist_val   = function_exists( 'sywat_normalize_dist' ) ? sywat_normalize_dist( $current_dist ) : $current_dist;
                $final_paid = ! empty( $current_paid_context ) ? $current_paid_context : ( $dist_val === 'Cub' ? 'Youth' : 'Adult' );

                if ( ! empty( $resolved['division'] ) ) {
                    $final_div = $resolved['division'];
                    if ( $resolved['distance'] === 'Cub' ) {
                        $dist_val = 'Cub';
                    }
                    if ( ! empty( $resolved['rate_tier'] ) && ! in_array( $final_paid, array( 'Family', 'Pro', 'Field Governor' ), true ) ) {
                        $final_paid = $resolved['rate_tier'];
                    }
                } elseif ( $dist_val === 'Cub' ) {
                    $final_div = $default_cub_div;
                } elseif ( $final_paid === 'Youth' ) {
                    $final_div = $default_youth_div;
                } else {
                    $final_div = $default_adult_div;
                }

                if ( function_exists( 'sywat_shooting_styles_normalize_division' ) && $final_div !== '' ) {
                    $final_div = sywat_shooting_styles_normalize_division( $final_div );
                }

                $parsed_shooters[] = array(
                    'name'      => $clean_line,
                    'line_idx'  => $target_line_idx,
                    'distance'  => $dist_val,
                    'paid'      => $final_paid,
                    'division'  => $final_div,
                    'gender'    => $resolved['gender'],
                    'style'     => $resolved['style'],
                    'phone'     => $resolved['phone'],
                    'email'     => $resolved['email'],
                    'family_id' => ! empty( $resolved['family_id'] ) ? $resolved['family_id'] : ( $final_paid === 'Family' ? uniqid( 'fam_' ) : '' ),
                );
            }

            // Group Capacity Pre-Check Guard
            if ( ! empty( $parsed_shooters ) && ! $allow_partial ) {
                $line_data = isset( $t_lines[ $target_line_idx ] ) ? $t_lines[ $target_line_idx ] : array();
                $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line 1';
                $dist_val  = $parsed_shooters[0]['distance'];

                $current_count = function_exists( 'sywat_count_registered' ) ? sywat_count_registered( $tourn_name, $target_line_idx, $dist_val, $registrations ) : 0;
                $max_capacity  = function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $line_data, $dist_val ) : 0;
                if ( ! $max_capacity || $max_capacity <= 0 ) {
                    $max_capacity = ( $dist_val === 'Cub' ) ? 5 : 20;
                }

                $open_slots = max( 0, $max_capacity - $current_count );
                $req_cnt    = count( $parsed_shooters );

                if ( $req_cnt > $open_slots ) {
                    $prompt  = '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">';
                    $prompt .= '<div class="sywat-alert-title">⚠️ IMPORT CAPACITY OVERFLOW WARNING</div>';
                    $prompt .= '<div class="sywat-alert-subtext">Target line <strong>' . esc_html( $line_name ) . '</strong> (' . esc_html( $dist_val ) . ') has <strong>' . intval( $open_slots ) . ' open spot(s)</strong>, but your import contains <strong>' . intval( $req_cnt ) . ' archers</strong>. No archers were imported yet.</div>';
                    $prompt .= '<div class="sywat-field-group">';
                    $prompt .= '<form method="post" class="sywat-inline-block">';
                    $prompt .= wp_nonce_field( 'sywat_desk_action', 'sywat_nonce', true, false );
                    $prompt .= '<input type="hidden" name="sywat_action" value="import_shooters">';
                    $prompt .= '<input type="hidden" name="selected_tournament" value="' . esc_attr( $tourn_name ) . '">';
                    $prompt .= '<input type="hidden" name="target_line_idx" value="' . esc_attr( $target_line_idx ) . '">';
                    $prompt .= '<input type="hidden" name="pasted_text" value="' . esc_attr( $raw_text_payload ) . '">';
                    $prompt .= '<input type="hidden" name="allow_partial" value="1">';
                    $prompt .= '<button type="submit" class="sywat-btn sywat-btn-warning">Import Available Spots (' . intval( $open_slots ) . ')</button>';
                    $prompt .= '</form>';
                    $prompt .= '<button type="button" class="sywat-btn sywat-btn-secondary" onclick="const m=document.getElementById(\'sywat_capacity_overflow_modal\'); if(m) m.remove();">Cancel & Pick Another Line</button>';
                    $prompt .= '</div></div>';

                    $GLOBALS['sywat_modal_notice'] = $prompt;
                    return $prompt;
                }
            }

            foreach ( $parsed_shooters as $s ) {
                $line_idx   = $s['line_idx'];
                $line_data  = isset( $t_lines[ $line_idx ] ) ? $t_lines[ $line_idx ] : array();
                $line_name  = isset( $line_data['name'] ) ? $line_data['name'] : 'Line 1';
                $dist_val   = $s['distance'];
                $clean_line = $s['name'];

                $is_duplicate = false;
                foreach ( $registrations as $existing ) {
                    if ( isset( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] ) &&
                         strcasecmp( $existing['tournament'], $tourn_name ) === 0 &&
                         (string) $existing['line_index'] === (string) $line_idx &&
                         strcasecmp( trim( $existing['name'] ), $clean_line ) === 0 &&
                         strcasecmp( $existing['distance'], $dist_val ) === 0 ) {
                        $is_duplicate = true;
                        break;
                    }
                }

                if ( $is_duplicate ) {
                    $skipped_dup++;
                    continue;
                }

                $current_count = function_exists( 'sywat_count_registered' ) ? sywat_count_registered( $tourn_name, $line_idx, $dist_val, $registrations ) : 0;
                $max_capacity  = function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $line_data, $dist_val ) : 0;
                if ( ! $max_capacity || $max_capacity <= 0 ) {
                    $max_capacity = ( $dist_val === 'Cub' ) ? 5 : 20;
                }

                if ( $current_count >= $max_capacity ) {
                    $skipped_cap++;
                    continue;
                }

                $new_reg = array(
                    'id'              => uniqid( 'reg_' ),
                    'tournament'      => $tourn_name,
                    'line_index'      => $line_idx,
                    'line_name'       => $line_name,
                    'name'            => $clean_line,
                    'phone'           => $s['phone'],
                    'email'           => $s['email'],
                    'distance'        => $dist_val,
                    'division'        => $s['division'],
                    'gender'          => $s['gender'],
                    'style'           => $s['style'],
                    'pricing_profile' => $default_prof_key,
                    'is_promo_free'   => false,
                    'paid'            => $s['paid'],
                    'family_id'       => $s['family_id'],
                    'balance_due'     => 0,
                    'status'          => 'Pre-Reg',
                    'created_at'      => current_time( 'mysql' ),
                );

                if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                    sywat_waitlist_persist_record( $new_reg, $registrations );
                } else {
                    update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                    $registrations[] = $new_reg;
                }
                $touched_lines[ (string) $line_idx ] = true;
                $imported_count++;
            }
        } elseif ( isset( $_FILES['import_file'] ) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK ) {
            $handle = fopen( $_FILES['import_file']['tmp_name'], 'r' );
            if ( $handle !== false ) {
                $base_line_name = isset( $t_lines[ $target_line_idx ]['name'] ) ? $t_lines[ $target_line_idx ]['name'] : '';
                $day_context    = '';
                if ( stripos( $base_line_name, 'Sunday' ) !== false ) {
                    $day_context = 'Sunday';
                } elseif ( stripos( $base_line_name, 'Saturday' ) !== false ) {
                    $day_context = 'Saturday';
                } elseif ( stripos( $base_line_name, 'Friday' ) !== false ) {
                    $day_context = 'Friday';
                }

                $row_index       = 0;
                $is_fh_format    = false;
                $booking_col_idx = -1;
                $name_col_idx    = -1;
                $contact_col_idx = -1;
                $pax_col_idx     = -1;
                $due_col_idx     = -1;
                $current_dist    = '20yd';

                while ( ( $data = fgetcsv( $handle, 4000, ',' ) ) !== false ) {
                    $row_index++;
                    if ( empty( array_filter( $data ) ) ) {
                        continue;
                    }

                    $first_cell  = isset( $data[0] ) ? trim( $data[0] ) : '';
                    $second_cell = isset( $data[1] ) ? trim( $data[1] ) : '';

                    $is_section_header = false;
                    if ( stripos( $first_cell, '10 YARDS' ) !== false || stripos( $first_cell, '10 YD' ) !== false ) {
                        $current_dist      = 'Cub';
                        $is_section_header = true;
                    } elseif ( stripos( $first_cell, '20 YARDS' ) !== false || stripos( $first_cell, '20 YD' ) !== false ) {
                        $current_dist      = '20yd';
                        $is_section_header = true;
                    }

                    if ( $is_section_header ) {
                        if ( preg_match( '/([0-9]{1,2}):[0-9]{2}\s*(AM|PM)/i', $second_cell, $time_matches ) ) {
                            $search_str = $time_matches[1] . strtoupper( $time_matches[2] );
                            if ( ! empty( $t_lines ) ) {
                                foreach ( $t_lines as $idx => $l_data ) {
                                    $check_name = isset( $l_data['name'] ) ? $l_data['name'] : '';
                                    if ( $day_context && stripos( $check_name, $day_context ) === false ) {
                                        continue;
                                    }
                                    if ( stripos( str_replace( ' ', '', $check_name ), $search_str ) !== false ) {
                                        $target_line_idx = (string) $idx;
                                        break;
                                    }
                                }
                            }
                        }
                        continue;
                    }

                    $is_header_row = false;
                    foreach ( $data as $idx => $val ) {
                        if ( strcasecmp( trim( $val ), 'Booking ID' ) === 0 ) {
                            $booking_col_idx = $idx;
                            $is_header_row   = true;
                        }
                        if ( strcasecmp( trim( $val ), 'Full Name' ) === 0 ) {
                            $name_col_idx  = $idx;
                            $is_header_row = true;
                        }
                        if ( strcasecmp( trim( $val ), 'Contact' ) === 0 ) {
                            $contact_col_idx = $idx;
                            $is_header_row   = true;
                        }
                        if ( strcasecmp( trim( $val ), 'Pax' ) === 0 || strcasecmp( trim( $val ), 'Customers' ) === 0 ) {
                            $pax_col_idx = $idx;
                        }
                        if ( strcasecmp( trim( $val ), 'Due' ) === 0 ) {
                            $due_col_idx = $idx;
                        }
                    }

                    if ( $is_header_row ) {
                        $is_fh_format = true;
                        continue;
                    }

                    $raw_names        = '';
                    $row_email        = '';
                    $row_phone        = '';
                    $booking_id_val   = ( $booking_col_idx !== -1 && isset( $data[ $booking_col_idx ] ) ) ? trim( $data[ $booking_col_idx ] ) : '';
                    $matched_line_idx = $target_line_idx;
                    $dist_val         = function_exists( 'sywat_normalize_dist' ) ? sywat_normalize_dist( $current_dist ) : $current_dist;
                    $row_paid_cat     = function_exists( 'sywat_detect_paid_category' ) ? sywat_detect_paid_category( $data ) : '';
                    $row_due_val      = ( $due_col_idx !== -1 && isset( $data[ $due_col_idx ] ) && function_exists( 'sywat_extract_due_amount' ) ) ? sywat_extract_due_amount( $data[ $due_col_idx ] ) : 0;

                    if ( $contact_col_idx !== -1 && isset( $data[ $contact_col_idx ] ) ) {
                        $contact_lines = preg_split( '/\r\n|\r|\n/', trim( (string) $data[ $contact_col_idx ] ) );
                        foreach ( $contact_lines as $c_line ) {
                            $c_line = trim( $c_line );
                            if ( $c_line === '' ) {
                                continue;
                            }
                            if ( $row_email === '' && is_email( $c_line ) ) {
                                $row_email = sanitize_email( $c_line );
                            } elseif ( $row_phone === '' && preg_match( '/\d{3}[\s\-\.\)]*\d{3}[\s\-\.]*\d{4}/', $c_line ) ) {
                                $row_phone = sanitize_text_field( $c_line );
                            }
                        }
                    }

                    if ( $is_fh_format ) {
                        if ( $name_col_idx !== -1 && isset( $data[ $name_col_idx ] ) ) {
                            $raw_names = trim( $data[ $name_col_idx ] );
                        }
                        if ( empty( $raw_names ) && $contact_col_idx !== -1 && isset( $data[ $contact_col_idx ] ) ) {
                            $contact_parts = preg_split( '/\r\n|\r|\n/', trim( $data[ $contact_col_idx ] ) );
                            if ( ! empty( $contact_parts ) ) {
                                $raw_names = trim( $contact_parts[0] );
                            }
                        }
                        if ( empty( $raw_names ) || strtolower( trim( $raw_names ) ) === 'full name' ) {
                            continue;
                        }
                    } else {
                        $col_name     = isset( $data[0] ) ? trim( sanitize_text_field( $data[0] ) ) : '';
                        $col_line     = isset( $data[1] ) ? trim( sanitize_text_field( $data[1] ) ) : '';
                        $col_distance = isset( $data[2] ) ? trim( sanitize_text_field( $data[2] ) ) : '';

                        if ( $row_index === 1 && ( strcasecmp( $col_name, 'name' ) === 0 || strcasecmp( $col_name, 'shooter name' ) === 0 ) ) {
                            continue;
                        }
                        $raw_names = $col_name;

                        if ( ! empty( $col_line ) && ! empty( $t_lines ) ) {
                            foreach ( $t_lines as $idx => $l_data ) {
                                if ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), $col_line ) === 0 ) {
                                    $matched_line_idx = (string) $idx;
                                    break;
                                }
                            }
                        }
                        if ( ! empty( $col_distance ) ) {
                            $dist_val = function_exists( 'sywat_normalize_dist' ) ? sywat_normalize_dist( $col_distance ) : $col_distance;
                        }
                    }

                    if ( empty( $raw_names ) ) {
                        continue;
                    }

                    $split_names = array();
                    $pax         = ( $pax_col_idx !== -1 && isset( $data[ $pax_col_idx ] ) ) ? intval( $data[ $pax_col_idx ] ) : 1;

                    if ( $is_fh_format ) {
                        $clean_separators = str_ireplace( array( ' and ', ' & ' ), ',', $raw_names );
                        if ( strpos( $clean_separators, ',' ) !== false ) {
                            $parts = explode( ',', $clean_separators );
                            foreach ( $parts as $p ) {
                                if ( trim( $p ) ) {
                                    $split_names[] = trim( $p );
                                }
                            }
                        } elseif ( $pax > 1 ) {
                            $words = array_values( array_filter( explode( ' ', $raw_names ) ) );
                            if ( count( $words ) >= ( $pax * 2 ) ) {
                                $words_per_name = floor( count( $words ) / $pax );
                                $extra_words    = count( $words ) % $pax;
                                $offset         = 0;
                                for ( $i = 0; $i < $pax; $i++ ) {
                                    $length        = $words_per_name + ( $i < $extra_words ? 1 : 0 );
                                    $split_names[] = implode( ' ', array_slice( $words, $offset, $length ) );
                                    $offset       += $length;
                                }
                            } else {
                                for ( $i = 1; $i <= $pax; $i++ ) {
                                    $split_names[] = trim( $raw_names ) . " ($i)";
                                }
                            }
                        } else {
                            $split_names[] = $raw_names;
                        }
                    } else {
                        $split_names = preg_split( '/\r\n|\r|\n/', $raw_names );
                    }

                    $base_paid         = ! empty( $row_paid_cat ) ? $row_paid_cat : ( $dist_val === 'Cub' ? 'Youth' : 'Adult' );
                    $is_multi_party    = ( count( $split_names ) > 1 );
                    $booking_fam_id    = ( $base_paid === 'Family' || $is_multi_party )
                        ? ( ! empty( $booking_id_val ) ? 'fh_' . sanitize_key( $booking_id_val ) : uniqid( 'fam_' ) )
                        : '';
                    $matched_line_name = isset( $t_lines[ $matched_line_idx ]['name'] ) ? $t_lines[ $matched_line_idx ]['name'] : 'Line 1';
                    $line_data         = isset( $t_lines[ $matched_line_idx ] ) ? $t_lines[ $matched_line_idx ] : array();
                    $valid_names_count = count( array_filter( array_map( 'trim', $split_names ) ) );
                    $per_shooter_due   = ( $row_due_val > 0 && $valid_names_count > 1 )
                        ? round( $row_due_val / $valid_names_count, 2 )
                        : floatval( $row_due_val );

                    foreach ( $split_names as $single_name ) {
                        $clean_name = trim( sanitize_text_field( $single_name ) );
                        if ( empty( $clean_name ) ) {
                            continue;
                        }

                        // Resolve each shooter individually against Member Directory (DOB age) & 18-month history
                        $resolved = sywat_resolve_shooter_profile_from_directory_and_history(
                            $clean_name,
                            $t_data,
                            $registrations,
                            array( 'email' => $row_email, 'phone' => $row_phone )
                        );

                        $shooter_dist = $dist_val;
                        $shooter_paid = $base_paid;

                        if ( ! empty( $resolved['division'] ) ) {
                            $final_div = $resolved['division'];
                            if ( $resolved['distance'] === 'Cub' ) {
                                $shooter_dist = 'Cub';
                            }
                            if ( ! empty( $resolved['rate_tier'] ) && ! in_array( $shooter_paid, array( 'Family', 'Pro', 'Field Governor' ), true ) ) {
                                $shooter_paid = $resolved['rate_tier'];
                            }
                        } elseif ( $shooter_dist === 'Cub' ) {
                            $final_div = $default_cub_div;
                        } elseif ( $shooter_paid === 'Youth' ) {
                            $final_div = $default_youth_div;
                        } else {
                            $final_div = $default_adult_div;
                        }

                        if ( function_exists( 'sywat_shooting_styles_normalize_division' ) && $final_div !== '' ) {
                            $final_div = sywat_shooting_styles_normalize_division( $final_div );
                        }

                        // Align unpaid balance_due with the resolved division's pricing profile rate
                        $shooter_due = $per_shooter_due;
                        if ( $shooter_due > 0 && ! in_array( $shooter_paid, array( 'Family', 'Field Governor' ), true ) ) {
                            $shooter_due = $resolve_profile_fee( $final_div, $shooter_paid );
                        }

                        $shooter_fam_id = ! empty( $resolved['family_id'] ) ? $resolved['family_id'] : $booking_fam_id;

                        $is_duplicate = false;
                        foreach ( $registrations as $existing ) {
                            if ( isset( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] ) &&
                                 strcasecmp( $existing['tournament'], $tourn_name ) === 0 &&
                                 (string) $existing['line_index'] === (string) $matched_line_idx &&
                                 strcasecmp( trim( $existing['name'] ), $clean_name ) === 0 &&
                                 strcasecmp( $existing['distance'], $shooter_dist ) === 0 ) {
                                $is_duplicate = true;
                                break;
                            }
                        }

                        if ( $is_duplicate ) {
                            $skipped_dup++;
                            continue;
                        }

                        $current_count = function_exists( 'sywat_count_registered' ) ? sywat_count_registered( $tourn_name, $matched_line_idx, $shooter_dist, $registrations ) : 0;
                        $max_capacity  = function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $line_data, $shooter_dist ) : 0;
                        if ( ! $max_capacity || $max_capacity <= 0 ) {
                            $max_capacity = ( $shooter_dist === 'Cub' ) ? 5 : 20;
                        }

                        if ( $current_count >= $max_capacity ) {
                            $skipped_cap++;
                            continue;
                        }

                        $new_reg = array(
                            'id'              => uniqid( 'reg_' ),
                            'tournament'      => $tourn_name,
                            'line_index'      => $matched_line_idx,
                            'line_name'       => $matched_line_name,
                            'name'            => $clean_name,
                            'phone'           => $resolved['phone'],
                            'email'           => $resolved['email'],
                            'distance'        => $shooter_dist,
                            'division'        => $final_div,
                            'gender'          => $resolved['gender'],
                            'style'           => $resolved['style'],
                            'pricing_profile' => $default_prof_key,
                            'is_promo_free'   => false,
                            'paid'            => $shooter_paid,
                            'booking_id'      => $booking_id_val,
                            'family_id'       => $shooter_fam_id,
                            'balance_due'     => $shooter_due,
                            'status'          => 'Pre-Reg',
                            'created_at'      => current_time( 'mysql' ),
                        );

                        if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                            sywat_waitlist_persist_record( $new_reg, $registrations );
                        } else {
                            update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                            $registrations[] = $new_reg;
                        }
                        $touched_lines[ (string) $matched_line_idx ] = true;
                        $imported_count++;
                    }
                }
                fclose( $handle );
            }
        }

        // Recalculate household family cap balances across any lines modified during import
        if ( ! empty( $touched_lines ) && function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
            foreach ( array_keys( $touched_lines ) as $l_idx_key ) {
                sywat_waitlist_recalculate_line_family_fees( $tourn_name, $l_idx_key, $tournaments, $registrations );
            }
        }

        $msg = 'Successfully imported ' . $imported_count . ' shooter(s) as Pre-Reg.';
        if ( $skipped_dup > 0 ) {
            $msg .= ' Skipped ' . $skipped_dup . ' duplicate(s).';
        }
        if ( $skipped_cap > 0 ) {
            $msg .= ' Skipped ' . $skipped_cap . ' due to capacity limits.';
        }

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">' . esc_html( $msg ) . '</div>';
    }
}
