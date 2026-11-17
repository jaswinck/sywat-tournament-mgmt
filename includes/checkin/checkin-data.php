<?php
/**
 * Sub-Module: Check-In Desk Data Layer
 * Path: includes/checkin/checkin-data.php
 * Description: Fetches and prepares Check-In Desk state datasets, integrating database-configured NFAA shooting styles, age divisions, active tournament pricing/round profiles, and O(1) First-Time/Lapsed Shooter promoter eligibility rules with zero extra database queries per loop.
 * Compliance: Pure data layer — zero HTML echo, zero POST mutations, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves the array line index for a registration entry.
 *
 * @param array $reg   Archer registration dataset.
 * @param array $lines Shooting line configuration array.
 * @return string Resolved array line key index.
 */
if ( ! function_exists( 'sywat_desk_resolve_line_idx' ) ) {
    function sywat_desk_resolve_line_idx( $reg, $lines ) {
        if ( isset( $reg['line_index'] ) && $reg['line_index'] !== '' ) {
            $idx = (string) $reg['line_index'];
            if ( isset( $lines[ $idx ] ) ) {
                return $idx;
            }
            foreach ( $lines as $l_key => $l_data ) {
                if ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), trim( $idx ) ) === 0 ) {
                    return (string) $l_key;
                }
            }
        }
        if ( isset( $reg['line_name'] ) && ! empty( $reg['line_name'] ) ) {
            foreach ( $lines as $l_key => $l_data ) {
                if ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), trim( $reg['line_name'] ) ) === 0 ) {
                    return (string) $l_key;
                }
            }
        }
        return isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0';
    }
}

/**
 * Runs legacy database migrations for registration option keys.
 *
 * @return void
 */
if ( ! function_exists( 'sywat_desk_run_migrations' ) ) {
    function sywat_desk_run_migrations() {
        global $wpdb;

        $legacy_data = get_option( 'sywat_registrations' );
        if ( is_array( $legacy_data ) && ! empty( $legacy_data ) ) {
            foreach ( $legacy_data as $reg ) {
                if ( isset( $reg['id'] ) && isset( $reg['tournament'] ) ) {
                    $t_slug = sanitize_title( $reg['tournament'] );
                    update_option( 'sywat_t_' . $t_slug . '_' . $reg['id'], $reg, false );
                }
            }
            delete_option( 'sywat_registrations' );
            delete_option( 'sywat_reg_index' );
        }

        $old_regs = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_reg_%'" );
        if ( ! empty( $old_regs ) ) {
            foreach ( $old_regs as $row ) {
                $reg_data = maybe_unserialize( $row->option_value );
                if ( is_array( $reg_data ) && isset( $reg_data['tournament'] ) && isset( $reg_data['id'] ) ) {
                    $t_slug = sanitize_title( $reg_data['tournament'] );
                    update_option( 'sywat_t_' . $t_slug . '_' . $reg_data['id'], $reg_data, false );
                }
                delete_option( $row->option_name );
            }
        }
    }
}

/**
 * Build an O(1) client-side Promoter eligibility lookup map per offered round profile
 * for the active Check-In Desk tournament so walk-in intake surfaces $0.00 promotional
 * eligibility live.
 *
 * @param string     $selected_tournament Active tournament name.
 * @param array|null $t_data              Full tournament config array (or null).
 * @param array      $t_profiles          Ordered map of pricing profiles offered by the tournament.
 * @param array      $seasons             Seasons master list.
 * @param array      $current_tourn_regs  Current tournament registrations.
 * @return array                          Map keyed by profile slug ('active', 'label', 'ineligible').
 */
if ( ! function_exists( 'sywat_desk_build_promo_rules_payload' ) ) {
    function sywat_desk_build_promo_rules_payload( $selected_tournament, $t_data, $t_profiles, $seasons, $current_tourn_regs ) {
        $payload = array();

        if ( empty( $selected_tournament ) || ! is_array( $t_profiles ) || empty( $t_profiles ) ) {
            return $payload;
        }

        $season_slug = '';
        if ( is_array( $t_data ) ) {
            $season_slug = ! empty( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( ! empty( $t_data['season'] ) ? $t_data['season'] : '' );
        }
        $season_rec      = ( $season_slug !== '' && is_array( $seasons ) && isset( $seasons[ $season_slug ] ) ) ? $seasons[ $season_slug ] : null;
        $season_promo_on = is_array( $season_rec ) && ! empty( $season_rec['promo_enabled'] );

        // Only load full cross-tournament registration history if promo is active on season + at least one profile
        $any_prof_promo = false;
        if ( $season_promo_on ) {
            foreach ( $t_profiles as $prof ) {
                if ( ! empty( $prof['promo_enabled'] ) ) {
                    $any_prof_promo = true;
                    break;
                }
            }
        }

        $all_regs        = array();
        $candidate_names = array();
        if ( $any_prof_promo ) {
            $all_regs = function_exists( 'sywat_get_all_registrations' )
                ? sywat_get_all_registrations()
                : ( is_array( $current_tourn_regs ) ? $current_tourn_regs : array() );

            if ( is_array( $all_regs ) ) {
                foreach ( $all_regs as $reg ) {
                    if ( is_array( $reg ) && ! empty( $reg['name'] ) ) {
                        $norm = strtolower( trim( (string) $reg['name'] ) );
                        if ( $norm !== '' ) {
                            $candidate_names[ $norm ] = trim( (string) $reg['name'] );
                        }
                    }
                }
            }
        }

        $t_context = is_array( $t_data ) ? $t_data : $selected_tournament;

        foreach ( $t_profiles as $p_slug => $prof ) {
            $is_active      = $season_promo_on && ! empty( $prof['promo_enabled'] );
            $ineligible_map = array();

            if ( $is_active && function_exists( 'sywat_check_archer_promo_eligibility' ) ) {
                foreach ( $candidate_names as $norm_name => $orig_name ) {
                    $check = sywat_check_archer_promo_eligibility(
                        $orig_name,
                        $t_context,
                        $p_slug,
                        $all_regs
                    );
                    if ( empty( $check['eligible'] ) ) {
                        $ineligible_map[ $norm_name ] = isset( $check['reason'] ) ? $check['reason'] : 'ineligible';
                    }
                }
            }

            $payload[ $p_slug ] = array(
                'active'     => $is_active,
                'label'      => ! empty( $prof['promo_label'] ) ? $prof['promo_label'] : 'Promo (Free)',
                'ineligible' => $ineligible_map,
            );
        }

        return $payload;
    }
}

/**
 * Fetches and prepares all Check-In Desk portal state datasets.
 *
 * @return array Aggregated state array containing tournaments, lines, archers, pricing profiles, and TFAA bundles.
 */
if ( ! function_exists( 'sywat_desk_get_data' ) ) {
    function sywat_desk_get_data() {
        global $wpdb;

        $user = wp_get_current_user();

        // Administrative & Desk Control roles
        $admin_roles  = array( 'administrator', 'management', 'tfaa_webmaster', 'tfaa_admin', 'tfaa_president', 'tfaa_tournament_director', 'tfaa_field_governor', 'sywat_shop_manager' );
        $is_admin     = (bool) array_intersect( $admin_roles, (array) $user->roles );
        $user_shop_id = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';

        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( empty( $all_tournaments ) || ! is_array( $all_tournaments ) ) {
            $single_name     = get_option( 'sywat_current_tournament', 'CCR SYWAT Qualifier' );
            $all_tournaments = array( $single_name => get_option( 'sywat_line_config', array() ) );
        }

        $requested_tourn   = isset( $_REQUEST['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['selected_tournament'] ) ) : '';
        $selected_line_idx = isset( $_REQUEST['selected_line_idx'] )   ? sanitize_text_field( wp_unslash( $_REQUEST['selected_line_idx'] ) )   : 'all';
        $search_query      = isset( $_REQUEST['search_query'] )        ? sanitize_text_field( wp_unslash( $_REQUEST['search_query'] ) )        : '';
        $saved_user_t      = $user->ID ? trim( (string) get_user_meta( $user->ID, 'sywat_desk_selected_tournament', true ) ) : '';

        // Identify active season slugs/names so archived season events do not clutter the dropdown
        $seasons            = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : get_option( 'sywat_seasons_list', array() );
        $active_season_keys = array();
        if ( is_array( $seasons ) ) {
            foreach ( $seasons as $s_slug => $s_data ) {
                if ( is_array( $s_data ) && isset( $s_data['status'] ) && $s_data['status'] === 'active' ) {
                    $active_season_keys[] = strtolower( trim( (string) $s_slug ) );
                    if ( ! empty( $s_data['name'] ) ) {
                        $active_season_keys[] = strtolower( trim( (string) $s_data['name'] ) );
                    }
                }
            }
        }

        // Scope Tournaments to User's Assigned Shop & Active Season(s)
        $scoped_raw = array();
        foreach ( $all_tournaments as $t_name => $t_data ) {
            $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
            if ( ! $is_admin && $t_shop !== $user_shop_id ) {
                continue;
            }

            $is_explicitly_requested = ( ! empty( $requested_tourn ) && strcasecmp( $t_name, $requested_tourn ) === 0 );
            $is_saved_preference     = ( $saved_user_t !== '' && strcasecmp( $t_name, $saved_user_t ) === 0 );

            if ( ! $is_explicitly_requested && ! $is_saved_preference && ! empty( $active_season_keys ) ) {
                $t_season = '';
                if ( is_array( $t_data ) ) {
                    $t_season = ! empty( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( ! empty( $t_data['season'] ) ? $t_data['season'] : '' );
                }
                if ( ! empty( $t_season ) && ! in_array( strtolower( trim( (string) $t_season ) ), $active_season_keys, true ) ) {
                    continue;
                }
            }

            $scoped_raw[ $t_name ] = $t_data;
        }

        if ( empty( $scoped_raw ) ) {
            foreach ( $all_tournaments as $t_name => $t_data ) {
                $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
                if ( $is_admin || $t_shop === $user_shop_id ) {
                    $scoped_raw[ $t_name ] = $t_data;
                }
            }
        }

        // Sort scoped keys alphabetically (A–Z, natural case-insensitive)
        $sorted_keys = array_keys( $scoped_raw );
        usort( $sorted_keys, 'strnatcasecmp' );

        // 1. Resolve explicitly requested tournament with exact or case-insensitive match
        $selected_tournament = '';
        if ( ! empty( $requested_tourn ) ) {
            if ( isset( $scoped_raw[ $requested_tourn ] ) ) {
                $selected_tournament = $requested_tourn;
            } else {
                foreach ( $sorted_keys as $k ) {
                    if ( strcasecmp( $k, $requested_tourn ) === 0 ) {
                        $selected_tournament = $k;
                        break;
                    }
                }
            }
        }

        // 2. Sticky User Preference: Restore last tournament selected across Register / Check-In / Scores
        if ( empty( $selected_tournament ) && $saved_user_t !== '' ) {
            if ( isset( $scoped_raw[ $saved_user_t ] ) ) {
                $selected_tournament = $saved_user_t;
            } else {
                foreach ( $sorted_keys as $k ) {
                    if ( strcasecmp( $k, $saved_user_t ) === 0 ) {
                        $selected_tournament = $k;
                        break;
                    }
                }
            }
        }

        // 3. Active Volunteer Hub tournament or global active tournament
        if ( empty( $selected_tournament ) ) {
            $active_hubs = get_option( 'sywat_active_tournaments', array() );
            if ( is_array( $active_hubs ) && ! empty( $active_hubs ) ) {
                $hub_any = '';
                foreach ( $sorted_keys as $k ) {
                    if ( in_array( $k, $active_hubs, true ) ) {
                        $t_shop = function_exists( 'sywat_get_tournament_shop_id' )
                            ? (string) sywat_get_tournament_shop_id( $scoped_raw[ $k ] )
                            : ( isset( $scoped_raw[ $k ]['shop_id'] ) ? (string) $scoped_raw[ $k ]['shop_id'] : '' );
                        if ( ! empty( $user_shop_id ) && $t_shop === (string) $user_shop_id ) {
                            $selected_tournament = $k;
                            break;
                        }
                        if ( $hub_any === '' ) {
                            $hub_any = $k;
                        }
                    }
                }
                if ( empty( $selected_tournament ) && $hub_any !== '' ) {
                    $selected_tournament = $hub_any;
                }
            }
        }

        if ( empty( $selected_tournament ) ) {
            $global_curr = trim( (string) get_option( 'sywat_current_tournament', '' ) );
            if ( $global_curr !== '' ) {
                if ( isset( $scoped_raw[ $global_curr ] ) ) {
                    $selected_tournament = $global_curr;
                } else {
                    foreach ( $sorted_keys as $k ) {
                        if ( strcasecmp( $k, $global_curr ) === 0 ) {
                            $selected_tournament = $k;
                            break;
                        }
                    }
                }
            }
        }

        // 4. Fallback to nearest upcoming event (user shop first, then overall), else first A–Z
        if ( empty( $selected_tournament ) && ! empty( $sorted_keys ) ) {
            $today_ymd     = current_time( 'Y-m-d' );
            $shop_upcoming = array();
            $any_upcoming  = array();

            foreach ( $sorted_keys as $k ) {
                $s_date = isset( $scoped_raw[ $k ]['start_date'] ) ? $scoped_raw[ $k ]['start_date'] : '';
                if ( ! empty( $s_date ) && $s_date >= $today_ymd ) {
                    $any_upcoming[ $k ] = $s_date;
                    $t_shop             = function_exists( 'sywat_get_tournament_shop_id' )
                        ? (string) sywat_get_tournament_shop_id( $scoped_raw[ $k ] )
                        : ( isset( $scoped_raw[ $k ]['shop_id'] ) ? (string) $scoped_raw[ $k ]['shop_id'] : '' );
                    if ( ! empty( $user_shop_id ) && $t_shop === (string) $user_shop_id ) {
                        $shop_upcoming[ $k ] = $s_date;
                    }
                }
            }

            if ( ! empty( $shop_upcoming ) ) {
                asort( $shop_upcoming );
                reset( $shop_upcoming );
                $selected_tournament = key( $shop_upcoming );
            } elseif ( ! empty( $any_upcoming ) ) {
                asort( $any_upcoming );
                reset( $any_upcoming );
                $selected_tournament = key( $any_upcoming );
            } else {
                $selected_tournament = $sorted_keys[0];
            }
        }

        if ( $user->ID && ! empty( $selected_tournament ) ) {
            update_user_meta( $user->ID, 'sywat_desk_selected_tournament', $selected_tournament );
        }

        // Build ordered $tournaments map: selected tournament pinned at the top, remaining sorted A–Z
        $tournaments = array();
        if ( ! empty( $selected_tournament ) && isset( $scoped_raw[ $selected_tournament ] ) ) {
            $tournaments[ $selected_tournament ] = function_exists( 'sywat_get_tournament_lines' )
                ? sywat_get_tournament_lines( $scoped_raw[ $selected_tournament ] )
                : $scoped_raw[ $selected_tournament ];
        }
        foreach ( $sorted_keys as $t_name ) {
            if ( $t_name === $selected_tournament ) {
                continue;
            }
            $tournaments[ $t_name ] = function_exists( 'sywat_get_tournament_lines' )
                ? sywat_get_tournament_lines( $scoped_raw[ $t_name ] )
                : $scoped_raw[ $t_name ];
        }

        $selected_t_data = ( ! empty( $selected_tournament ) && isset( $scoped_raw[ $selected_tournament ] ) )
            ? $scoped_raw[ $selected_tournament ]
            : ( isset( $all_tournaments[ $selected_tournament ] ) ? $all_tournaments[ $selected_tournament ] : array() );

        $lines         = isset( $tournaments[ $selected_tournament ] ) ? $tournaments[ $selected_tournament ] : array();
        $active_t_slug = sanitize_title( $selected_tournament );

        // Resolve active Pricing & Round Profiles for the selected tournament
        $t_profiles       = ( ! empty( $selected_tournament ) && function_exists( 'sywat_get_tournament_pricing_profiles' ) )
            ? sywat_get_tournament_pricing_profiles( ! empty( $selected_t_data ) ? $selected_t_data : $selected_tournament )
            : array();
        $default_prof     = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';
        $init_adult_rate  = is_array( $default_prof ) && isset( $default_prof['adult_rate'] ) ? floatval( $default_prof['adult_rate'] ) : 30.00;
        $init_youth_rate  = is_array( $default_prof ) && isset( $default_prof['youth_rate'] ) ? floatval( $default_prof['youth_rate'] ) : 25.00;
        $is_multi_round   = count( $t_profiles ) > 1;

        $closed_lines_key = 'sywat_closed_lines_' . $active_t_slug;

        $raw_closed   = get_option( $closed_lines_key, array() );
        $closed_lines = is_array( $raw_closed ) ? array_map( 'trim', array_map( 'strval', $raw_closed ) ) : array();

        $registrations = array();
        $results       = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'sywat_t_' . $active_t_slug . '_' ) . '%'
        ) );

        foreach ( $results as $row ) {
            $reg_data = maybe_unserialize( $row->option_value );
            if ( is_array( $reg_data ) && isset( $reg_data['id'] ) ) {
                $registrations[] = $reg_data;
            }
        }

        // High Concurrency Caching: Wrap DB history lookup in Transient to optimize performance
        $history_profiles = get_transient( 'sywat_history_profiles_cache' );
        if ( false === $history_profiles || ! is_array( $history_profiles ) ) {
            $all_db_results   = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%'" );
            $history_profiles = array( 'exact' => array(), 'fl' => array() );

            foreach ( $all_db_results as $row ) {
                $r = maybe_unserialize( $row->option_value );
                if ( is_array( $r ) && ! empty( $r['name'] ) ) {
                    $clean_name = trim( $r['name'] );
                    if ( empty( $clean_name ) ) {
                        continue;
                    }

                    $div_val   = isset( $r['division'] ) ? $r['division'] : '';
                    $gen_val   = isset( $r['gender'] ) ? $r['gender'] : '';
                    $style_val = isset( $r['style'] ) ? $r['style'] : '';
                    $paid_val  = isset( $r['paid'] ) ? $r['paid'] : '';

                    if ( $div_val === 'No Show' || $style_val === 'No Show' || empty( $div_val ) || empty( $style_val ) ) {
                        continue;
                    }

                    $norm_key = strtolower( preg_replace( '/[[:space:]]+/', ' ', $clean_name ) );
                    $parts    = explode( ' ', $norm_key );
                    $fl_key   = ( count( $parts ) > 1 ) ? reset( $parts ) . '___' . end( $parts ) : $norm_key;

                    $profile_data = array(
                        'full_name' => $clean_name,
                        'division'  => $div_val,
                        'gender'    => $gen_val,
                        'style'     => $style_val,
                        'paid'      => $paid_val,
                    );

                    $history_profiles['exact'][ $norm_key ] = $profile_data;
                    if ( ! isset( $history_profiles['fl'][ $fl_key ] ) ) {
                        $history_profiles['fl'][ $fl_key ] = $profile_data;
                    }
                }
            }
            set_transient( 'sywat_history_profiles_cache', $history_profiles, 15 * MINUTE_IN_SECONDS );
        }

        $tfaa_cache_option = get_option( 'sywat_tfaa_cache', array() );
        $tfaa_data_bundle  = array(
            'exact'      => isset( $tfaa_cache_option['exact'] ) ? $tfaa_cache_option['exact'] : array(),
            'fl'         => isset( $tfaa_cache_option['fl'] ) ? $tfaa_cache_option['fl'] : array(),
            'updated_at' => isset( $tfaa_cache_option['updated_at'] ) ? $tfaa_cache_option['updated_at'] : 'Never',
        );

        $existing_families_by_line = array();
        $family_members_by_id      = array();

        foreach ( $registrations as $r ) {
            $fid = ! empty( $r['family_id'] ) ? trim( $r['family_id'] ) : '';
            if ( empty( $fid ) ) {
                continue;
            }

            $is_fam = ( isset( $r['paid'] ) && strcasecmp( $r['paid'], 'Family' ) === 0 )
                   || ( ! empty( $r['rate_type'] ) && stripos( $r['rate_type'], 'family' ) !== false );

            if ( ! $is_fam ) {
                continue;
            }

            $resolved_l_idx = sywat_desk_resolve_line_idx( $r, $lines );

            if ( ! isset( $family_members_by_id[ $fid ] ) ) {
                $family_members_by_id[ $fid ] = array(
                    'line_idx'    => $resolved_l_idx,
                    'family_name' => ! empty( $r['family_name'] ) ? trim( $r['family_name'] ) : '',
                    'names'       => array(),
                );
            }
            if ( ! empty( $r['name'] ) ) {
                $family_members_by_id[ $fid ]['names'][] = trim( $r['name'] );
            }
        }

        foreach ( $family_members_by_id as $fid => $fam_info ) {
            $l_idx     = $fam_info['line_idx'];
            $raw_fname = $fam_info['family_name'];
            $names     = $fam_info['names'];

            if ( ! empty( $raw_fname ) && stripos( $raw_fname, 'fam_' ) === false && stripos( $raw_fname, 'Booking' ) === false && strlen( $raw_fname ) > 2 ) {
                $label = $raw_fname;
            } elseif ( ! empty( $names ) ) {
                if ( count( $names ) == 1 ) {
                    $label = $names[0] . ' Group';
                } elseif ( count( $names ) <= 3 ) {
                    $last_names = array_map( function( $n ) {
                        $p = explode( ' ', trim( $n ) );
                        return end( $p );
                    }, $names );
                    $label = implode( ' / ', array_unique( $last_names ) ) . ' Group';
                } else {
                    $p     = explode( ' ', trim( $names[0] ) );
                    $label = end( $p ) . ' Family Group (' . count( $names ) . ' Archers)';
                }
            } else {
                $label = 'Family Group (' . substr( $fid, -6 ) . ')';
            }

            if ( ! isset( $existing_families_by_line[ $l_idx ] ) ) {
                $existing_families_by_line[ $l_idx ] = array();
            }
            $existing_families_by_line[ $l_idx ][ $fid ] = $label;
        }

        $existing_families = isset( $existing_families_by_line[ $selected_line_idx ] ) ? $existing_families_by_line[ $selected_line_idx ] : array();
        if ( empty( $existing_families ) && $selected_line_idx === 'all' ) {
            foreach ( $existing_families_by_line as $l_fams ) {
                foreach ( $l_fams as $fid => $flabel ) {
                    $existing_families[ $fid ] = $flabel;
                }
            }
        }

        // Database-Driven Age Divisions, Shooting Styles, Legacy Alias Map & Client Metadata
        $divs           = array();
        $active_divs    = array();
        $divisions_meta = array();
        $legacy_div_map = array(
            'Cub'           => 'Cub (8 - 11)',
            'Youth'         => 'Youth (12 - 14)',
            'Young Adult'   => 'Young Adult (15 - 17)',
            'Adult'         => 'Adult (18 - 49)',
            'Senior'        => 'Senior (50 - 59)',
            'Silver Senior' => 'Silver Senior (60 - 69)',
            'Master Senior' => 'Master Senior (70+)',
        );

        if ( function_exists( 'sywat_get_division_records' ) ) {
            $div_records = sywat_get_division_records( true );
            if ( is_array( $div_records ) && ! empty( $div_records ) ) {
                foreach ( $div_records as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    $label     = $rec['label'];
                    $status    = isset( $rec['status'] ) ? $rec['status'] : 'active';
                    $rate_tier = ( isset( $rec['rate_tier'] ) && $rec['rate_tier'] === 'Youth' ) ? 'Youth' : 'Adult';
                    $distance  = ( isset( $rec['distance'] ) && $rec['distance'] === 'Cub' ) ? 'Cub' : '20yd';

                    $div_fee = function_exists( 'sywat_get_archer_individual_fee' )
                        ? sywat_get_archer_individual_fee( ! empty( $selected_t_data ) ? $selected_t_data : $selected_tournament, $label, $distance, '', $default_prof_key, '' )
                        : ( ( $rate_tier === 'Youth' ) ? $init_youth_rate : $init_adult_rate );

                    $divs[] = $label;
                    if ( $status === 'active' ) {
                        $active_divs[] = $label;
                    }

                    $divisions_meta[] = array(
                        'slug'      => isset( $rec['slug'] ) ? $rec['slug'] : sanitize_title( $label ),
                        'code'      => isset( $rec['code'] ) ? $rec['code'] : '',
                        'label'     => $label,
                        'distance'  => $distance,
                        'rate_tier' => $rate_tier,
                        'fee'       => $div_fee,
                        'status'    => $status,
                        'aliases'   => ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ? array_values( $rec['aliases'] ) : array(),
                    );

                    // Populate legacy alias & base-name lookup map from configured division record
                    $base_name = trim( preg_replace( '/\s*\(.*?\)\s*/', '', $label ) );
                    if ( $base_name !== '' && $base_name !== $label ) {
                        $legacy_div_map[ $base_name ] = $label;
                    }
                    if ( ! empty( $rec['code'] ) ) {
                        $legacy_div_map[ trim( $rec['code'] ) ] = $label;
                    }
                    if ( ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ) {
                        foreach ( $rec['aliases'] as $alias ) {
                            if ( trim( $alias ) !== '' ) {
                                $legacy_div_map[ trim( $alias ) ] = $label;
                            }
                        }
                    }
                }
            }
        }

        if ( empty( $divs ) ) {
            $divs        = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)', 'Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)' );
            $active_divs = $divs;
        }
        $divs[] = 'No Show';

        $genders = array( 'Male', 'Female', 'No Show' );

        $sywat_styles  = array();
        $active_styles = array();
        if ( function_exists( 'sywat_get_shooting_styles' ) ) {
            $style_records = sywat_get_shooting_styles( true );
            if ( is_array( $style_records ) && ! empty( $style_records ) ) {
                foreach ( $style_records as $s_rec ) {
                    if ( empty( $s_rec['label'] ) ) {
                        continue;
                    }
                    $s_label        = $s_rec['label'];
                    $s_status       = isset( $s_rec['status'] ) ? $s_rec['status'] : 'active';
                    $sywat_styles[] = $s_label;
                    if ( $s_status === 'active' ) {
                        $active_styles[] = $s_label;
                    }
                }
            }
        }

        if ( empty( $sywat_styles ) ) {
            $sywat_styles  = array(
                'FS – Freestyle',
                'FS Pro – Freestyle Pro',
                'FSL – Freestyle Limited',
                'FSLR – Freestyle Limited Recurve (or Olympic Recurve)',
                'BHFS – Bowhunter Freestyle',
                'MBH – Modern Bowhunter',
                'BB – Barebow',
                'BBC – Barebow Compound',
                'BBR – Barebow Recurve',
                'TRAD – Traditional',
                'LB – Longbow',
                'Genesis',
            );
            $active_styles = $sywat_styles;
        }
        $sywat_styles[] = 'No Show';

        $tourn_registrations = array();
        foreach ( $registrations as $reg ) {
            $match_tourn = isset( $reg['tournament'] ) && $reg['tournament'] === $selected_tournament;
            $match_line  = ( $selected_line_idx === 'all' ) || ( isset( $reg['line_index'] ) && (string) $reg['line_index'] === (string) $selected_line_idx );
            if ( $match_tourn && $match_line ) {
                $tourn_registrations[] = $reg;
            }
        }

        usort( $tourn_registrations, function( $a, $b ) {
            $line_a = isset( $a['line_index'] ) ? (int) $a['line_index'] : 99;
            $line_b = isset( $b['line_index'] ) ? (int) $b['line_index'] : 99;
            if ( $line_a !== $line_b ) {
                return $line_a <=> $line_b;
            }
            return strcasecmp( isset( $a['name'] ) ? trim( $a['name'] ) : '', isset( $b['name'] ) ? trim( $b['name'] ) : '' );
        } );

        $modal_target_id   = isset( $_POST['open_checkin_id'] ) ? sanitize_text_field( wp_unslash( $_POST['open_checkin_id'] ) ) : '';
        $modal_edit_id     = isset( $_POST['open_edit_id'] )    ? sanitize_text_field( wp_unslash( $_POST['open_edit_id'] ) )    : '';
        $modal_archer      = null;
        $modal_edit_archer = null;

        if ( $modal_target_id || $modal_edit_id ) {
            $search_id = $modal_target_id ? $modal_target_id : $modal_edit_id;
            foreach ( $registrations as $reg ) {
                if ( isset( $reg['id'] ) && $reg['id'] === $search_id ) {
                    if ( $modal_target_id ) {
                        $modal_archer = $reg;
                    }
                    if ( $modal_edit_id ) {
                        $modal_edit_archer = $reg;
                    }
                    break;
                }
            }
        }

        $promo_rules = sywat_desk_build_promo_rules_payload(
            $selected_tournament,
            $selected_t_data,
            $t_profiles,
            $seasons,
            $registrations
        );

        return array(
            'is_admin'                  => $is_admin,
            'user_shop_id'              => $user_shop_id,
            'tournaments'               => $tournaments,
            'tournaments_full'          => $scoped_raw,
            'selected_tournament'       => $selected_tournament,
            'selected_tournament_data'  => $selected_t_data,
            'selected_line_idx'         => $selected_line_idx,
            'search_query'              => $search_query,
            'lines'                     => $lines,
            'active_t_slug'             => $active_t_slug,
            'closed_lines'              => $closed_lines,
            'closed_lines_key'          => $closed_lines_key,
            'pricing_profiles'          => $t_profiles,
            'default_pricing_profile'   => $default_prof_key,
            'is_multi_round'            => $is_multi_round,
            'promo_rules'               => $promo_rules,
            'registrations'             => $registrations,
            'tourn_registrations'       => $tourn_registrations,
            'history_profiles'          => $history_profiles,
            'tfaa_data_bundle'          => $tfaa_data_bundle,
            'existing_families'         => $existing_families,
            'existing_families_by_line' => $existing_families_by_line,
            'divs'                      => $divs,
            'active_divs'               => $active_divs,
            'divisions_meta'            => $divisions_meta,
            'genders'                   => $genders,
            'sywat_styles'              => $sywat_styles,
            'active_styles'             => $active_styles,
            'legacy_div_map'            => $legacy_div_map,
            'modal_archer'              => $modal_archer,
            'modal_edit_archer'         => $modal_edit_archer,
            'added_family_prompt'       => isset( $GLOBALS['sywat_added_family_prompt'] ) ? $GLOBALS['sywat_added_family_prompt'] : null,
        );
    }
}
