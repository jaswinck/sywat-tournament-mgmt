<?php
/**
 * Module: Financial Accounting & Invoice Engine
 * Path: includes/class-sywat-accounting.php
 * Description: Host vs. TFAA 50/50 fee split math, dynamic pricing/round profile fee classes, household family cap calculations (honoring paid families even when not all family members check in), First-Time/Lapsed Shooter promoter tracking, Zelle QR code rendering (Base64 embedded from plugin assets), dynamic shop header metadata, and printable invoice/roster UI.
 * Compliance: Pure library/renderer — zero direct SQL queries, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Helper: Embed Zelle QR Code from plugin assets/images/zelle-qr-code.png as Base64 for print/PDF reliability
if ( ! function_exists( 'sywat_get_zelle_qr_base64' ) ) {
    function sywat_get_zelle_qr_base64() {
        $plugin_img_path = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/images/zelle-qr-code.png';
        if ( ! file_exists( $plugin_img_path ) ) {
            $plugin_img_path = plugin_dir_path( __FILE__ ) . 'assets/images/zelle-qr-code.png';
        }

        if ( file_exists( $plugin_img_path ) ) {
            $type = pathinfo( $plugin_img_path, PATHINFO_EXTENSION );
            $data = file_get_contents( $plugin_img_path );
            return 'data:image/' . $type . ';base64,' . base64_encode( $data );
        }

        $raw_qr = get_option( 'sywat_zelle_qr_url', '' );

        if ( is_numeric( $raw_qr ) ) {
            $file_path = get_attached_file( intval( $raw_qr ) );
            if ( $file_path && file_exists( $file_path ) ) {
                $type = pathinfo( $file_path, PATHINFO_EXTENSION );
                $data = file_get_contents( $file_path );
                return 'data:image/' . $type . ';base64,' . base64_encode( $data );
            }
            $raw_qr = wp_get_attachment_url( intval( $raw_qr ) );
        }

        if ( filter_var( $raw_qr, FILTER_VALIDATE_URL ) ) {
            $response = wp_remote_get( $raw_qr, array( 'timeout' => 5 ) );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $image_data   = wp_remote_retrieve_body( $response );
                $content_type = wp_remote_retrieve_header( $response, 'content-type' );
                if ( empty( $content_type ) ) {
                    $content_type = 'image/png';
                }
                return 'data:' . $content_type . ';base64,' . base64_encode( $image_data );
            }
        }

        return '';
    }
}

/**
 * Helper: Resolve an individual archer's standard rate tier, individual entry fee,
 * and pricing profile metadata using the central Pricing & Promoter engine.
 *
 * @param array  $reg                 Registration record.
 * @param string $selected_tournament Tournament name.
 * @param array  $t_profiles          Tournament pricing profiles map.
 * @param array  $default_prof        Default pricing profile record.
 * @return array                      Normalized rate metadata ('tier', 'fee', 'profile', 'round_slug', 'round_label').
 */
if ( ! function_exists( 'sywat_accounting_resolve_archer_rate_info' ) ) {
    function sywat_accounting_resolve_archer_rate_info( $reg, $selected_tournament = '', $t_profiles = array(), $default_prof = array() ) {
        $youth_divs = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' );
        $div        = isset( $reg['division'] ) ? trim( (string) $reg['division'] ) : '';
        $style      = isset( $reg['style'] ) ? trim( (string) $reg['style'] ) : '';
        $paid_cat   = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
        $dist       = isset( $reg['distance'] ) ? trim( (string) $reg['distance'] ) : '';

        if ( $dist === '' ) {
            $dist = function_exists( 'sywat_shooting_styles_get_division_distance' )
                ? sywat_shooting_styles_get_division_distance( $div )
                : ( ( stripos( $div, 'Cub' ) !== false ) ? 'Cub' : '20yd' );
        }

        $default_slug = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';
        $round_slug   = ! empty( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : $default_slug;

        $prof = isset( $t_profiles[ $round_slug ] ) ? $t_profiles[ $round_slug ] : null;
        if ( ! is_array( $prof ) && function_exists( 'sywat_find_pricing_profile' ) ) {
            $prof = sywat_find_pricing_profile( $round_slug );
        }
        if ( ! is_array( $prof ) ) {
            $prof       = $default_prof;
            $round_slug = $default_slug;
        }

        if ( $dist === 'Cub' || strcasecmp( $paid_cat, 'Youth' ) === 0 || ( isset( $reg['rate_type'] ) && strcasecmp( (string) $reg['rate_type'], 'youth' ) === 0 ) ) {
            $tier = 'Youth';
        } elseif ( $div !== '' && function_exists( 'sywat_shooting_styles_get_division_rate_tier' ) ) {
            $tier = sywat_shooting_styles_get_division_rate_tier( $div );
        } else {
            $tier = in_array( $div, $youth_divs, true ) ? 'Youth' : 'Adult';
        }

        if ( function_exists( 'sywat_get_archer_individual_fee' ) ) {
            $fee = sywat_get_archer_individual_fee( $selected_tournament, $div, $dist, $style, $round_slug, '' );
        } else {
            $y_rate = is_array( $prof ) && isset( $prof['youth_rate'] ) ? floatval( $prof['youth_rate'] ) : 25.00;
            $a_rate = is_array( $prof ) && isset( $prof['adult_rate'] ) ? floatval( $prof['adult_rate'] ) : 30.00;
            $fee    = ( $tier === 'Youth' ) ? $y_rate : $a_rate;
        }

        return array(
            'tier'        => $tier,
            'fee'         => floatval( $fee ),
            'profile'     => is_array( $prof ) ? $prof : array(),
            'round_slug'  => $round_slug,
            'round_label' => is_array( $prof ) && ! empty( $prof['label'] ) ? $prof['label'] : '',
        );
    }
}

/**
 * Calculates the exact balance and family cap status for a group of archers sharing a family_id.
 *
 * @param string $family_id           Household family identifier.
 * @param string $line_idx            Shooting line index or 'all'.
 * @param array  $all_registrations   Registrations list.
 * @param string $selected_tournament Optional tournament name override.
 * @return array                      Family group balance summary.
 */
if ( ! function_exists( 'sywat_calculate_family_group_balance' ) ) {
    function sywat_calculate_family_group_balance( $family_id, $line_idx, $all_registrations, $selected_tournament = '' ) {
        if ( empty( $family_id ) || ! is_array( $all_registrations ) ) {
            return array(
                'is_family_capped' => false,
                'total_fee'        => 0.00,
                'member_count'     => 0,
                'raw_sum'          => 0.00,
                'members'          => array(),
            );
        }

        if ( $selected_tournament === '' ) {
            foreach ( $all_registrations as $r ) {
                if ( is_array( $r ) && ! empty( $r['tournament'] ) ) {
                    $selected_tournament = trim( (string) $r['tournament'] );
                    break;
                }
            }
        }

        $t_profiles   = ( $selected_tournament !== '' && function_exists( 'sywat_get_tournament_pricing_profiles' ) )
            ? sywat_get_tournament_pricing_profiles( $selected_tournament )
            : array();
        $default_prof = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );

        $use_tourn_wide_cap = false;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                $use_tourn_wide_cap = true;
                break;
            }
        }

        $family_members = array();
        $raw_sum        = 0.00;
        $cap_enabled    = false;
        $cap_amount     = 0.00;
        $cap_min        = 99;

        foreach ( $all_registrations as $reg ) {
            if ( ! is_array( $reg ) ) {
                continue;
            }

            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Waitlist' ) === 0 || strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            $reg_fam_id = ! empty( $reg['family_id'] ) ? $reg['family_id'] : ( ! empty( $reg['booking_id'] ) ? $reg['booking_id'] : '' );

            if ( empty( $reg_fam_id ) && isset( $reg['paid'] ) && $reg['paid'] === 'Family' ) {
                $name_parts = array_values( array_filter( explode( ' ', trim( (string) ( $reg['name'] ?? '' ) ) ) ) );
                $last_name  = ! empty( $name_parts ) ? strtolower( end( $name_parts ) ) : 'fam';
                $reg_l_idx  = isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0';
                $reg_fam_id = 'line_' . $reg_l_idx . '_last_' . $last_name;
            }

            if ( $reg_fam_id !== $family_id ) {
                continue;
            }

            if ( ! $use_tourn_wide_cap && $line_idx !== 'all' && isset( $reg['line_index'] ) && (string) $reg['line_index'] !== (string) $line_idx ) {
                continue;
            }

            $paid_cat = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
            $is_free  = ( strcasecmp( $paid_cat, 'Field Governor' ) === 0 )
                     || ! empty( $reg['is_promo_free'] )
                     || ( strcasecmp( $paid_cat, 'Promo (Free)' ) === 0 )
                     || ( stripos( $paid_cat, 'Promo' ) === 0 );

            if ( $is_free ) {
                continue;
            }

            $rate_info  = sywat_accounting_resolve_archer_rate_info( $reg, $selected_tournament, $t_profiles, $default_prof );
            $indiv_rate = $rate_info['fee'];
            $is_youth   = ( $rate_info['tier'] === 'Youth' );
            $prof       = $rate_info['profile'];

            if ( is_array( $prof ) && ! empty( $prof['family_cap_enabled'] ) ) {
                $cap_enabled = true;
                $p_cap       = isset( $prof['family_cap_amount'] ) ? floatval( $prof['family_cap_amount'] ) : 75.00;
                $p_min       = isset( $prof['family_min_archers'] ) ? max( 2, intval( $prof['family_min_archers'] ) ) : 3;
                if ( $p_cap > $cap_amount ) {
                    $cap_amount = $p_cap;
                }
                if ( $p_min < $cap_min ) {
                    $cap_min = $p_min;
                }
            }

            $raw_sum += $indiv_rate;
            $family_members[] = array(
                'reg'        => $reg,
                'indiv_rate' => $indiv_rate,
                'is_youth'   => $is_youth,
            );
        }

        if ( $cap_min === 99 ) {
            $cap_min = 3;
        }
        if ( $cap_amount <= 0 && ! $cap_enabled && is_array( $default_prof ) && ! empty( $default_prof['family_cap_enabled'] ) ) {
            $cap_enabled = true;
            $cap_amount  = isset( $default_prof['family_cap_amount'] ) ? floatval( $default_prof['family_cap_amount'] ) : 75.00;
            $cap_min     = isset( $default_prof['family_min_archers'] ) ? max( 2, intval( $default_prof['family_min_archers'] ) ) : 3;
        }

        $count            = count( $family_members );
        $is_family_capped = (
            $cap_enabled &&
            $count >= $cap_min &&
            ( $raw_sum > $cap_amount || abs( $raw_sum - $cap_amount ) < 0.01 )
        );
        $final_total      = $is_family_capped ? $cap_amount : $raw_sum;

        return array(
            'is_family_capped' => $is_family_capped,
            'total_fee'        => $final_total,
            'member_count'     => $count,
            'raw_sum'          => $raw_sum,
            'cap_amount'       => $cap_amount,
            'members'          => $family_members,
        );
    }
}

/**
 * Calculate tournament or filtered roster accounting totals using the database-driven
 * Pricing & Promoter engine. Evaluates household family groups across all registered
 * members so a paid family with at least one checked-in archer is always recorded on
 * the invoice even if another family member did not check in.
 *
 * @param array  $regs_list           Registrations list.
 * @param string $selected_tournament Optional tournament name.
 * @return array                      Accounting summary & dynamic invoice fee rows.
 */
if ( ! function_exists( 'sywat_calculate_accounting_totals' ) ) {
    function sywat_calculate_accounting_totals( $regs_list, $selected_tournament = '' ) {
        if ( ! is_array( $regs_list ) ) {
            $regs_list = array();
        }

        if ( $selected_tournament === '' ) {
            foreach ( $regs_list as $r ) {
                if ( is_array( $r ) && ! empty( $r['tournament'] ) ) {
                    $selected_tournament = trim( (string) $r['tournament'] );
                    break;
                }
            }
        }

        $t_profiles     = ( $selected_tournament !== '' && function_exists( 'sywat_get_tournament_pricing_profiles' ) )
            ? sywat_get_tournament_pricing_profiles( $selected_tournament )
            : array();
        $default_prof   = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );
        $is_multi_round = count( $t_profiles ) > 1;

        $use_tourn_wide_cap = false;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                $use_tourn_wide_cap = true;
                break;
            }
        }

        $g1_youth        = 0;
        $tot_g1          = 0.00;
        $g2_adult        = 0;
        $tot_g2          = 0.00;
        $g4_fg           = 0;
        $group_promo     = 0;
        $g5_noshow       = 0;
        $g6_waitlist     = 0;
        $family_archers  = 0;
        $family_groups   = array();
        $dynamic_buckets = array();

        $record_bucket = function( $bucket_key, $label, $unit_rate, $amount ) use ( &$dynamic_buckets ) {
            if ( ! isset( $dynamic_buckets[ $bucket_key ] ) ) {
                $dynamic_buckets[ $bucket_key ] = array(
                    'label'     => $label,
                    'count'     => 0,
                    'unit_rate' => floatval( $unit_rate ),
                    'total'     => 0.00,
                );
            }
            $dynamic_buckets[ $bucket_key ]['count']++;
            $dynamic_buckets[ $bucket_key ]['total'] = round( $dynamic_buckets[ $bucket_key ]['total'] + floatval( $amount ), 2 );
        };

        $tally_individual_archer = function( $archer_rec ) use (
            &$g1_youth,
            &$tot_g1,
            &$g2_adult,
            &$tot_g2,
            $selected_tournament,
            $t_profiles,
            $default_prof,
            $is_multi_round,
            $record_bucket
        ) {
            $rate_info   = sywat_accounting_resolve_archer_rate_info( $archer_rec, $selected_tournament, $t_profiles, $default_prof );
            $fee         = $rate_info['fee'];
            $tier        = $rate_info['tier'];
            $round_slug  = $rate_info['round_slug'];
            $round_label = $rate_info['round_label'];
            $round_tag   = ( $is_multi_round && $round_label !== '' ) ? ' — ' . $round_label : '';

            if ( $tier === 'Youth' ) {
                $g1_youth++;
                $tot_g1 = round( $tot_g1 + $fee, 2 );
                $b_key  = 'youth_' . $round_slug . '_' . number_format( $fee, 2, '', '' );
                $record_bucket( $b_key, 'Cub/Youth/Young Adult' . $round_tag, $fee, $fee );
            } else {
                $g2_adult++;
                $tot_g2 = round( $tot_g2 + $fee, 2 );
                $b_key  = 'adult_' . $round_slug . '_' . number_format( $fee, 2, '', '' );
                $record_bucket( $b_key, 'Adult/Senior/Silver Senior/Master' . $round_tag, $fee, $fee );
            }
        };

        foreach ( $regs_list as $r ) {
            if ( ! is_array( $r ) ) {
                continue;
            }

            $status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
            if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            if ( strcasecmp( $status, 'Waitlist' ) === 0 ) {
                $g6_waitlist++;
                continue;
            }

            $is_checked_in  = ! empty( $r['checked_in'] );
            $is_no_show_div = ( isset( $r['division'] ) && $r['division'] === 'No Show' );
            $is_active_show = ( $is_checked_in && ! $is_no_show_div );

            if ( ! $is_active_show ) {
                $g5_noshow++;
            }

            $paid_cat      = isset( $r['paid'] ) ? trim( (string) $r['paid'] ) : '';
            $is_promo_free = ! empty( $r['is_promo_free'] )
                || strcasecmp( $paid_cat, 'Promo (Free)' ) === 0
                || stripos( $paid_cat, 'Promo' ) === 0;

            if ( strcasecmp( $paid_cat, 'Family' ) === 0 ) {
                if ( $is_active_show ) {
                    $family_archers++;
                }

                if ( ! empty( $r['family_id'] ) ) {
                    $fam_key = trim( (string) $r['family_id'] );
                } elseif ( ! empty( $r['booking_id'] ) ) {
                    $fam_key = trim( (string) $r['booking_id'] );
                } else {
                    $name_parts = array_values( array_filter( explode( ' ', trim( (string) ( $r['name'] ?? '' ) ) ) ) );
                    $last_name  = ! empty( $name_parts ) ? strtolower( end( $name_parts ) ) : 'fam';
                    $line_idx   = isset( $r['line_index'] ) ? (string) $r['line_index'] : '0';
                    $fam_key    = 'line_' . $line_idx . '_last_' . $last_name;
                }

                if ( ! $use_tourn_wide_cap ) {
                    $line_idx = isset( $r['line_index'] ) ? (string) $r['line_index'] : '0';
                    $fam_key .= '___line_' . $line_idx;
                }

                if ( ! isset( $family_groups[ $fam_key ] ) ) {
                    $family_groups[ $fam_key ] = array();
                }
                $family_groups[ $fam_key ][] = array(
                    'reg'        => $r,
                    'checked_in' => $is_active_show,
                );
                continue;
            }

            // Non-family records must be checked-in to appear on the financial invoice
            if ( ! $is_active_show ) {
                continue;
            }

            if ( strcasecmp( $paid_cat, 'Field Governor' ) === 0 ) {
                $g4_fg++;
            } elseif ( $is_promo_free ) {
                $group_promo++;
            } else {
                $tally_individual_archer( $r );
            }
        }

        // Process Family Groups across all registered household members so paid families
        // with at least 1 checked-in archer are recorded on the invoice even if not every
        // family member checked in.
        $unique_capped_families = 0;
        $tot_g3                 = 0.00;

        foreach ( $family_groups as $fam_key => $entries ) {
            $checked_in_members = array();
            $paid_eligible_recs = array();
            $group_raw_sum      = 0.00;
            $cap_enabled        = false;
            $cap_amount         = 0.00;
            $cap_min            = 99;
            $group_round_slug   = '';
            $group_round_label  = '';

            foreach ( $entries as $entry ) {
                $m            = $entry['reg'];
                $m_checked_in = ! empty( $entry['checked_in'] );

                if ( $m_checked_in ) {
                    $checked_in_members[] = $m;
                }

                $m_paid  = isset( $m['paid'] ) ? trim( (string) $m['paid'] ) : '';
                $m_promo = ! empty( $m['is_promo_free'] )
                    || strcasecmp( $m_paid, 'Promo (Free)' ) === 0
                    || stripos( $m_paid, 'Promo' ) === 0;

                if ( strcasecmp( $m_paid, 'Field Governor' ) === 0 || $m_promo ) {
                    if ( $m_checked_in ) {
                        if ( strcasecmp( $m_paid, 'Field Governor' ) === 0 ) {
                            $g4_fg++;
                        } else {
                            $group_promo++;
                        }
                    }
                    continue;
                }

                $rate_info   = sywat_accounting_resolve_archer_rate_info( $m, $selected_tournament, $t_profiles, $default_prof );
                $prof        = $rate_info['profile'];
                $group_raw_sum += $rate_info['fee'];
                $paid_eligible_recs[] = $m;

                if ( $group_round_slug === '' ) {
                    $group_round_slug  = $rate_info['round_slug'];
                    $group_round_label = $rate_info['round_label'];
                }

                if ( is_array( $prof ) && ! empty( $prof['family_cap_enabled'] ) ) {
                    $cap_enabled = true;
                    $p_cap       = isset( $prof['family_cap_amount'] ) ? floatval( $prof['family_cap_amount'] ) : 75.00;
                    $p_min       = isset( $prof['family_min_archers'] ) ? max( 2, intval( $prof['family_min_archers'] ) ) : 3;
                    if ( $p_cap > $cap_amount ) {
                        $cap_amount = $p_cap;
                    }
                    if ( $p_min < $cap_min ) {
                        $cap_min = $p_min;
                    }
                }
            }

            // If 0 members of this family checked in, the entire household is a No-Show
            if ( empty( $checked_in_members ) ) {
                continue;
            }

            if ( $cap_min === 99 ) {
                $cap_min = 3;
            }
            if ( $cap_amount <= 0 && ! $cap_enabled && is_array( $default_prof ) && ! empty( $default_prof['family_cap_enabled'] ) ) {
                $cap_enabled = true;
                $cap_amount  = isset( $default_prof['family_cap_amount'] ) ? floatval( $default_prof['family_cap_amount'] ) : 75.00;
                $cap_min     = isset( $default_prof['family_min_archers'] ) ? max( 2, intval( $default_prof['family_min_archers'] ) ) : 3;
            }

            $qualifies_family_cap = (
                $cap_enabled &&
                count( $paid_eligible_recs ) >= $cap_min &&
                ( $group_raw_sum > $cap_amount || abs( $group_raw_sum - $cap_amount ) < 0.01 )
            );

            if ( $qualifies_family_cap ) {
                $unique_capped_families++;
                $tot_g3    = round( $tot_g3 + $cap_amount, 2 );
                $round_tag = ( $is_multi_round && $group_round_label !== '' ) ? ' — ' . $group_round_label : '';
                $b_key     = 'family_' . $group_round_slug . '_' . number_format( $cap_amount, 2, '', '' );
                $record_bucket( $b_key, 'Family' . $round_tag, $cap_amount, $cap_amount );
            } else {
                foreach ( $checked_in_members as $cm ) {
                    $cm_paid  = isset( $cm['paid'] ) ? trim( (string) $cm['paid'] ) : '';
                    $cm_promo = ! empty( $cm['is_promo_free'] )
                        || strcasecmp( $cm_paid, 'Promo (Free)' ) === 0
                        || stripos( $cm_paid, 'Promo' ) === 0;
                    if ( strcasecmp( $cm_paid, 'Field Governor' ) === 0 || $cm_promo ) {
                        continue;
                    }
                    $tally_individual_archer( $cm );
                }
            }
        }

        // Build ordered invoice fee rows (seed zero-count template rows from active profile if empty)
        $fee_rows = array();
        if ( ! empty( $dynamic_buckets ) ) {
            foreach ( $dynamic_buckets as $b ) {
                $fee_rows[] = $b;
            }
        } else {
            $seed_profiles = ! empty( $t_profiles ) ? $t_profiles : ( ! empty( $default_prof ) ? array( $default_prof ) : array() );
            foreach ( $seed_profiles as $sp ) {
                $sp_tag   = ( count( $seed_profiles ) > 1 && ! empty( $sp['label'] ) ) ? ' — ' . $sp['label'] : '';
                $sp_youth = isset( $sp['youth_rate'] ) ? floatval( $sp['youth_rate'] ) : 25.00;
                $sp_adult = isset( $sp['adult_rate'] ) ? floatval( $sp['adult_rate'] ) : 30.00;
                $sp_cap   = isset( $sp['family_cap_amount'] ) ? floatval( $sp['family_cap_amount'] ) : 75.00;

                $fee_rows[] = array(
                    'label'     => 'Cub/Youth/Young Adult' . $sp_tag,
                    'count'     => 0,
                    'unit_rate' => $sp_youth,
                    'total'     => 0.00,
                );
                $fee_rows[] = array(
                    'label'     => 'Adult/Senior/Silver Senior/Master' . $sp_tag,
                    'count'     => 0,
                    'unit_rate' => $sp_adult,
                    'total'     => 0.00,
                );
                if ( ! empty( $sp['family_cap_enabled'] ) ) {
                    $fee_rows[] = array(
                        'label'     => 'Family' . $sp_tag,
                        'count'     => 0,
                        'unit_rate' => $sp_cap,
                        'total'     => 0.00,
                    );
                }
            }
        }

        $unique_families    = $unique_capped_families;
        $total_transactions = $g1_youth + $g2_adult + $unique_families + $g4_fg + $group_promo;
        $total_archers      = count( $regs_list );
        $total_checked_in   = max( 0, $total_archers - $g5_noshow - $g6_waitlist );

        $tot_fg           = 0.00;
        $tot_promo        = 0.00;
        $total_sywat_fees = round( $tot_g1 + $tot_g2 + $tot_g3 + $tot_fg + $tot_promo, 2 );
        $host_retains     = round( $total_sywat_fees * 0.50, 2 );
        $tfaa_to_pay      = round( $total_sywat_fees * 0.50, 2 );

        return array(
            'group1_youth'       => $g1_youth,
            'tot_g1'             => $tot_g1,
            'group2_adult'       => $g2_adult,
            'tot_g2'             => $tot_g2,
            'group3_families'    => $unique_families,
            'tot_g3'             => $tot_g3,
            'family_archers'     => $family_archers,
            'group4_fg'          => $g4_fg,
            'tot_fg'             => $tot_fg,
            'group_promo'        => $group_promo,
            'tot_promo'          => $tot_promo,
            'group5_noshow'      => $g5_noshow,
            'group6_waitlist'    => $g6_waitlist,
            'total_transactions' => $total_transactions,
            'total_archers'      => $total_archers,
            'total_checked_in'   => $total_checked_in,
            'total_sywat_fees'   => $total_sywat_fees,
            'host_retains'       => $host_retains,
            'tfaa_to_pay'        => $tfaa_to_pay,
            'fee_rows'           => $fee_rows,
        );
    }
}

// Printable Invoice Modal UI Renderer
if ( ! function_exists( 'sywat_render_invoice_modal' ) ) {
    function sywat_render_invoice_modal( $selected_tournament, $registrations ) {
        $all_tournament_regs = array_filter( $registrations, function( $r ) use ( $selected_tournament ) {
            return isset( $r['tournament'] ) && strcasecmp( $r['tournament'], $selected_tournament ) === 0;
        } );

        $acct            = sywat_calculate_accounting_totals( $all_tournament_regs, $selected_tournament );
        $t_dates         = get_option( 'sywat_dates_' . sanitize_title( $selected_tournament ), '' );
        $tourn_dates_str = ! empty( $t_dates ) ? $t_dates : $selected_tournament;

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        $all_shops   = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
        $shop_id     = 'shop_ccr';

        if ( isset( $tournaments[ $selected_tournament ] ) ) {
            $t_data  = $tournaments[ $selected_tournament ];
            $shop_id = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
        }

        $shop_info  = isset( $all_shops[ $shop_id ] ) ? $all_shops[ $shop_id ] : array();
        $shop_name  = ! empty( $shop_info['name'] ) ? $shop_info['name'] : $selected_tournament;
        $shop_cont  = ! empty( $shop_info['contact'] ) ? $shop_info['contact'] : ( ! empty( $shop_info['contact_name'] ) ? $shop_info['contact_name'] : '—' );
        $shop_email = ! empty( $shop_info['email'] ) ? $shop_info['email'] : '—';
        $shop_phone = ! empty( $shop_info['phone'] ) ? $shop_info['phone'] : '—';

        $zelle_qr_src = sywat_get_zelle_qr_base64();
        $fee_rows     = isset( $acct['fee_rows'] ) && is_array( $acct['fee_rows'] ) ? $acct['fee_rows'] : array();
        ?>

        <div class="no-print" style="margin-bottom: 15px; display: flex; justify-content: flex-end;">
            <button type="button" class="sywat-btn sywat-btn-primary sywat-btn-icon-print" onclick="window.print()">Print / Save Invoice PDF</button>
        </div>

        <div id="sywat_print_area">
            <div class="invoice-box">
                <div class="invoice-title">TFAA TOURNAMENT INVOICE</div>
                <div class="invoice-subtitle">(Fill in all the Blue Shade Boxes)</div>

                <div class="sywat-table-responsive" style="margin-bottom: 15px;">
                    <table class="invoice-header-table" style="margin-bottom: 0;">
                        <tr><td class="invoice-header-label">SYWAT Tournament Date:</td><td class="invoice-header-val"><?php echo esc_html( $tourn_dates_str ); ?></td></tr>
                        <tr><td class="invoice-header-label">Archery Shop/Club Name:</td><td class="invoice-header-val"><?php echo esc_html( $shop_name ); ?></td></tr>
                        <tr><td class="invoice-header-label">Contact Name:</td><td class="invoice-header-val"><?php echo esc_html( $shop_cont ); ?></td></tr>
                        <tr><td class="invoice-header-label">Email Address:</td><td class="invoice-header-val"><?php echo esc_html( $shop_email ); ?></td></tr>
                        <tr><td class="invoice-header-label">Phone:</td><td class="invoice-header-val"><?php echo esc_html( $shop_phone ); ?></td></tr>
                    </table>
                </div>

                <div style="font-weight:bold; font-size:13px; margin-bottom:6px;">Registration Tournament Fees:</div>

                <div class="sywat-table-responsive" style="margin-bottom: 15px;">
                    <table class="invoice-fee-table" style="margin-bottom: 0;">
                        <thead>
                            <tr>
                                <th style="width: 50%;">Fee Class</th>
                                <th style="width: 15%; text-align: center;">#</th>
                                <th style="width: 15%; text-align: right;">$</th>
                                <th style="width: 20%; text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $fee_rows as $f_row ) : ?>
                                <?php
                                    $u_rate      = floatval( $f_row['unit_rate'] );
                                    $u_rate_disp = ( abs( $u_rate - round( $u_rate ) ) < 0.01 )
                                        ? '$' . number_format( $u_rate, 0 )
                                        : '$' . number_format( $u_rate, 2 );
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $f_row['label'] ); ?></td>
                                    <td class="blue-shade" style="text-align: center;"><?php echo intval( $f_row['count'] ); ?></td>
                                    <td style="text-align: right;"><?php echo esc_html( $u_rate_disp ); ?></td>
                                    <td class="blue-shade">$<?php echo number_format( floatval( $f_row['total'] ), 2 ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ( ! empty( $acct['group_promo'] ) ) : ?>
                                <tr>
                                    <td>First-Time / Lapsed Shooter Promo <span style="font-size:10px; font-style:italic;">(Promotional Free Entry)</span></td>
                                    <td class="blue-shade" style="text-align: center;"><?php echo intval( $acct['group_promo'] ); ?></td>
                                    <td style="text-align: right;">$0</td>
                                    <td class="blue-shade">$0.00</td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td>Field Governor <span style="font-size:10px; font-style:italic;">(1 free per weekend)</span></td>
                                <td class="blue-shade" style="text-align: center;"><?php echo intval( $acct['group4_fg'] ); ?></td>
                                <td style="text-align: right;">$0</td>
                                <td class="blue-shade">$0.00</td>
                            </tr>
                            <tr>
                                <td colspan="3" style="font-weight: bold; text-align: right;">TOTAL SYWAT FEES</td>
                                <td class="blue-shade">$<?php echo number_format( $acct['total_sywat_fees'], 2 ); ?></td>
                            </tr>
                            <tr>
                                <td colspan="3" style="font-weight: bold; text-align: right;">Host Retains 50%</td>
                                <td class="blue-shade">$<?php echo number_format( $acct['host_retains'], 2 ); ?></td>
                            </tr>
                            <tr>
                                <td colspan="3" style="font-weight: bold; text-align: right;">TFAA to be Paid 50%</td>
                                <td class="blue-shade">$<?php echo number_format( $acct['tfaa_to_pay'], 2 ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="invoice-footer">
                    <p>Please remit TFAA Payment via Check or Zelle and a copy of this Invoice within 7 days to:</p>
                    <p class="invoice-footer-bold">Texas Field Archery Association<br>C/O Anna Hanson<br>722 Cannon Ball Dr.<br>Spring TX 77380<br>Email: anna.hanson@resolute-tx.com</p>
                    <p style="margin-top: 8px;">Send Money with Zelle®, Tag: <strong style="color:#1e3a8a;">tfaachecking</strong></p>
                    
                    <div style="margin-top: 10px; text-align: center;">
                        <?php if ( ! empty( $zelle_qr_src ) ) : ?>
                            <img src="<?php echo $zelle_qr_src; ?>" alt="Zelle QR Code" style="width: 130px; height: auto; display: inline-block; margin: 0 auto; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
                        <?php endif; ?>
                    </div>
                    
                    <p style="font-weight: bold; font-size: 13px; margin-top: 10px;">Thank You for Hosting!</p>
                </div>
            </div>
        </div>
        <?php
    }
}

// Printable Roster Modal UI Renderer
if ( ! function_exists( 'sywat_render_print_modal' ) ) {
    function sywat_render_print_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $is_specific_line ) {
        $all_tourn_regs = array_values( array_filter( $registrations, function( $reg ) use ( $selected_tournament ) {
            return isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $selected_tournament ) === 0;
        } ) );

        $filtered_registrations = array_filter( $all_tourn_regs, function( $reg ) use ( $filter_line_idx, $available_lines ) {
            if ( $filter_line_idx !== 'all' ) {
                $match_idx  = isset( $reg['line_index'] ) && (string) $reg['line_index'] === (string) $filter_line_idx;
                $match_name = isset( $available_lines[ $filter_line_idx ]['name'] ) && isset( $reg['line_name'] ) && strcasecmp( trim( $reg['line_name'] ), trim( $available_lines[ $filter_line_idx ]['name'] ) ) === 0;
                if ( ! $match_idx && ! $match_name ) {
                    return false;
                }
            }
            return true;
        } );

        usort( $filtered_registrations, function( $a, $b ) {
            return strcasecmp( isset( $a['name'] ) ? trim( $a['name'] ) : '', isset( $b['name'] ) ? trim( $b['name'] ) : '' );
        } );

        $acct = sywat_calculate_accounting_totals( $filtered_registrations, $selected_tournament );

        $t_profiles     = ( $selected_tournament !== '' && function_exists( 'sywat_get_tournament_pricing_profiles' ) )
            ? sywat_get_tournament_pricing_profiles( $selected_tournament )
            : array();
        $is_multi_round = count( $t_profiles ) > 1;

        $active_t_slug    = sanitize_title( $selected_tournament );
        $closed_lines_key = 'sywat_closed_lines_' . $active_t_slug;
        $raw_closed       = get_option( $closed_lines_key, array() );
        $closed_lines     = is_array( $raw_closed ) ? array_map( 'trim', array_map( 'strval', $raw_closed ) ) : array();
        ?>

        <div class="no-print" style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 10px;">
            <form method="get" action="" style="display: flex; gap: 12px; align-items: center; margin: 0; flex-wrap: wrap;">
                <input type="hidden" name="tab" value="manage">
                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="modal_action" value="print">
                <div class="sywat-field-group" style="min-width: 220px; width: auto;">
                    <label for="filter_line_idx_print">Filter Roster by Line</label>
                    <select name="filter_line_idx" id="filter_line_idx_print" onchange="this.form.submit()">
                        <option value="all" <?php selected( $filter_line_idx, 'all' ); ?>>Entire Weekend (All Lines)</option>
                        <?php foreach ( $available_lines as $idx => $line ) : ?>
                            <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $filter_line_idx, (string) $idx ); ?>><?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ( (int) $idx + 1 ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <button type="button" class="sywat-btn sywat-btn-primary sywat-btn-icon-print" onclick="window.print()">Print / Save PDF</button>
        </div>

        <div id="sywat_print_area">
            <div style="margin-bottom: 12px; border-bottom: 2px solid #1d2327; padding-bottom: 8px;">
                <h2 style="margin: 0; font-size: 18px; color: #1d2327;">TFAA SYWAT Tournament Roster</h2>
                <div style="font-size: 12px; color: #475569; font-weight: 600; margin-top: 4px;">
                    Tournament: <?php echo esc_html( $selected_tournament ); ?> | Line: <?php echo ( $is_specific_line && isset( $available_lines[ $filter_line_idx ] ) ) ? esc_html( $available_lines[ $filter_line_idx ]['name'] ) : 'Entire Weekend'; ?> | Total Roster Archers: <?php echo intval( $acct['total_archers'] ); ?>
                </div>

                <div style="margin-top: 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px; font-size: 11px; color: #0f172a;">
                    <strong>📊 ACCOUNTING & FINANCIAL SUMMARY:</strong><br>
                    • <strong>Cub / Youth / YA:</strong> <?php echo intval( $acct['group1_youth'] ); ?> Paid Transactions<br>
                    • <strong>Adult / Senior / SS / MS:</strong> <?php echo intval( $acct['group2_adult'] ); ?> Paid Transactions<br>
                    • <strong>Family Category:</strong> <?php echo intval( $acct['group3_families'] ); ?> Unique Paid Families (<?php echo intval( $acct['family_archers'] ); ?> Checked-In Archers)<br>
                    <?php if ( ! empty( $acct['group_promo'] ) ) : ?>
                        • <strong>First-Time / Lapsed Promo:</strong> <?php echo intval( $acct['group_promo'] ); ?> Transactions (Free)<br>
                    <?php endif; ?>
                    • <strong>Field Governor:</strong> <?php echo intval( $acct['group4_fg'] ); ?> Transactions (Free)<br>
                    • <strong>Waitlist:</strong> <?php echo intval( $acct['group6_waitlist'] ); ?> Archers (Pending Line Slot)<br>
                    • <strong>No Show:</strong> <?php echo intval( $acct['group5_noshow'] ); ?> Archers (Unchecked Pre-Reg)<br>
                    <div style="margin-top: 3px; border-top: 1px dashed #94a3b8; padding-top: 3px; font-weight: bold; color: #1e3a8a;">
                        Total Processed Transactions: <?php echo intval( $acct['total_transactions'] ); ?> &nbsp;|&nbsp; Checked-In Archers: <?php echo intval( $acct['total_checked_in'] ); ?> / <?php echo intval( $acct['total_archers'] ); ?> Total Roster
                    </div>
                </div>
            </div>

            <?php if ( empty( $filtered_registrations ) && ! $is_specific_line ) : ?>
                <p style="color: #646970; font-style: italic;">No archer records available for this filter.</p>
            <?php else : ?>
                <div class="sywat-table-responsive">
                    <table class="sywat-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="width: 3%;">#</th>
                                <th style="width: 20%;">Name</th>
                                <th style="width: 12%;">Line</th>
                                <th style="width: 15%;">Division</th>
                                <th style="width: 6%;">Gender</th>
                                <th style="width: 18%;">Style</th>
                                <th style="width: 14%;">Rate</th>
                                <th style="width: 6%; text-align: center;">Score</th>
                                <th style="width: 6%; text-align: center;">X Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $num = 1; foreach ( $filtered_registrations as $reg ) : ?>
                                <?php
                                    $is_checked_in = ! empty( $reg['checked_in'] );
                                    $is_waitlist   = isset( $reg['status'] ) && strcasecmp( trim( $reg['status'] ), 'Waitlist' ) === 0;
                                    $raw_div       = ! empty( $reg['division'] ) ? $reg['division'] : '—';

                                    $resolved_l_idx = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $reg, $available_lines ) : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );
                                    $reg_line_name  = ( isset( $reg['line_name'] ) && ! empty( trim( $reg['line_name'] ) ) ) ? trim( $reg['line_name'] ) : ( isset( $available_lines[ $resolved_l_idx ]['name'] ) ? trim( $available_lines[ $resolved_l_idx ]['name'] ) : '' );
                                    $is_line_closed = in_array( (string) $resolved_l_idx, $closed_lines, true ) || ( $reg_line_name !== '' && in_array( $reg_line_name, $closed_lines, true ) );

                                    $is_no_show = ! $is_waitlist && ! $is_checked_in && ( $is_line_closed || $raw_div === 'No Show' );

                                    $round_slug  = ! empty( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : '';
                                    $round_label = '';
                                    if ( $is_multi_round && $round_slug !== '' ) {
                                        if ( isset( $t_profiles[ $round_slug ] ) ) {
                                            $round_label = $t_profiles[ $round_slug ]['label'];
                                        } elseif ( function_exists( 'sywat_find_pricing_profile' ) ) {
                                            $rp = sywat_find_pricing_profile( $round_slug );
                                            if ( is_array( $rp ) && ! empty( $rp['label'] ) ) {
                                                $round_label = $rp['label'];
                                            }
                                        }
                                    }

                                    if ( $is_waitlist ) {
                                        $division_disp = 'A | S | SS | MS<br>C | Y | YA | PRO';
                                        $gender_disp   = 'M | F';
                                        $style_disp    = 'FS | FSL | BB | FLS-R | BHFS | MBH | TRAD | BBR | BBC | LB | GENESIS';
                                        $paid_cat      = '<span style="color:#d97706; font-weight:bold;">🕒 Waitlist</span>';
                                        $print_score   = '—';
                                        $print_x       = '—';
                                    } elseif ( $is_no_show ) {
                                        $division_disp = 'No Show';
                                        $gender_disp   = 'No Show';
                                        $style_disp    = 'No Show';
                                        $paid_cat      = 'No Show';
                                        $print_score   = '—';
                                        $print_x       = '—';
                                    } elseif ( $is_checked_in ) {
                                        $division_disp = ! empty( $reg['division'] ) ? esc_html( $reg['division'] ) : '';
                                        $gender_disp   = ! empty( $reg['gender'] ) ? esc_html( $reg['gender'] ) : '';
                                        $style_disp    = ! empty( $reg['style'] ) ? esc_html( $reg['style'] ) : '';
                                        $print_score   = ( isset( $reg['score'] ) && $reg['score'] !== '' ) ? esc_html( $reg['score'] ) : '';
                                        $print_x       = ( isset( $reg['x_count'] ) && $reg['x_count'] !== '' ) ? esc_html( $reg['x_count'] ) : '';

                                        $due_amt       = isset( $reg['balance_due'] ) ? floatval( $reg['balance_due'] ) : 0;
                                        $raw_paid_val  = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
                                        $is_promo_free = ! empty( $reg['is_promo_free'] )
                                            || strcasecmp( $raw_paid_val, 'Promo (Free)' ) === 0
                                            || stripos( $raw_paid_val, 'Promo' ) === 0;

                                        if ( $due_amt > 0 ) {
                                            $paid_cat = '<span style="color:#dc3545;">DUE: $' . number_format( $due_amt, 2 ) . '</span>';
                                        } elseif ( $is_promo_free ) {
                                            $paid_cat = '<span style="color:#047857;">Promo ($0.00)</span>';
                                        } else {
                                            $paid_cat = ! empty( $raw_paid_val ) ? esc_html( $raw_paid_val ) : 'Youth / Adult / Fam';
                                            if ( strcasecmp( $raw_paid_val, 'Family' ) === 0 ) {
                                                $fam_id_str = ! empty( $reg['family_id'] ) ? $reg['family_id'] : ( ! empty( $reg['booking_id'] ) ? $reg['booking_id'] : '' );
                                                $fam_calc   = function_exists( 'sywat_calculate_family_group_balance' ) ? sywat_calculate_family_group_balance( $fam_id_str, 'all', $all_tourn_regs, $selected_tournament ) : null;

                                                if ( $fam_calc && empty( $fam_calc['is_family_capped'] ) ) {
                                                    $is_youth = ( stripos( $division_disp, 'Cub' ) !== false || stripos( $division_disp, 'Youth' ) !== false || stripos( $division_disp, 'Young Adult' ) !== false );
                                                    $paid_cat = $is_youth ? 'Youth' : 'Adult';
                                                } elseif ( $fam_id_str ) {
                                                    $paid_cat .= '<br><span style="font-size:7px; color:#646970; word-break:break-all; font-weight:normal;">(' . esc_html( $fam_id_str ) . ')</span>';
                                                }
                                            }
                                        }
                                    } else {
                                        $division_disp = 'A | S | SS | MS<br>C | Y | YA | PRO';
                                        $gender_disp   = 'M | F';
                                        $style_disp    = 'FS | FSL | BB | FLS-R | BHFS | MBH | TRAD | BBR | BBC | LB | GENESIS';
                                        $print_score   = '';
                                        $print_x       = '';

                                        $due_amt = isset( $reg['balance_due'] ) ? floatval( $reg['balance_due'] ) : 0;
                                        if ( $due_amt > 0 ) {
                                            $paid_cat = '<span style="color:#dc3545;">DUE: $' . number_format( $due_amt, 2 ) . '</span>';
                                        } else {
                                            $paid_cat = ! empty( $reg['paid'] ) ? esc_html( $reg['paid'] ) : 'Youth / Adult / Fam';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo $num++; ?></strong></td>
                                    <td>
                                        <strong><?php echo esc_html( $reg['name'] ); ?></strong>
                                        <?php if ( $round_label !== '' ) : ?>
                                            <br><span style="font-size:8px; color:#1e3a8a; font-weight:600;">(<?php echo esc_html( $round_label ); ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 9px;"><?php echo esc_html( $reg_line_name ); ?></td>
                                    <td style="font-size: 9px; line-height: 1.1; white-space: nowrap;"><?php echo $division_disp; ?></td>
                                    <td style="font-size: 9px;"><?php echo $gender_disp; ?></td>
                                    <td style="font-size: 9px; line-height: 1.1;"><?php echo $style_disp; ?></td>
                                    <td class="paid-col" style="font-size: 9px; line-height: 1.1;"><strong><?php echo $paid_cat; ?></strong></td>
                                    <td style="border-bottom: 1px solid #1d2327; text-align: center; font-weight: bold;"><?php echo $print_score; ?></td>
                                    <td style="border-bottom: 1px solid #1d2327; text-align: center; font-weight: bold;"><?php echo $print_x; ?></td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ( $is_specific_line && $num <= 100 ) {
                                while ( $num <= 100 ) { ?>
                                    <tr>
                                        <td><strong><?php echo $num++; ?></strong></td>
                                        <td></td>
                                        <td style="font-size: 9px;"><?php echo esc_html( $available_lines[ $filter_line_idx ]['name'] ); ?></td>
                                        <td style="font-size: 8px; line-height: 1.1;">A | S | SS | MS<br>C | Y | YA | PRO</td>
                                        <td style="font-size: 9px;">M | F</td>
                                        <td style="font-size: 8px; line-height: 1.1;">FS | FSL | BB | FLS-R | BHFS | MBH | TRAD | BBR | BBC | LB | GENESIS</td>
                                        <td class="paid-col"><strong>YOUTH | ADULT | FAMILY</strong></td>
                                        <td style="border-bottom: 1px solid #1d2327; text-align: center;"></td>
                                        <td style="border-bottom: 1px solid #1d2327; text-align: center;"></td>
                                    </tr>
                                <?php }
                            } ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
