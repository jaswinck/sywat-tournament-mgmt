<?php
/**
 * Sub-Module: Pricing Profiles, Promoter Eligibility & Household Cap Calculation Helpers
 * Path: includes/pricing/pricing-helpers.php
 * Description: Centralized helpers for Season/Tournament pricing inheritance, individual entry rate calculation, O(1)-indexed First-Time/Lapsed Shooter Promoter eligibility checks, mixed-round Household Family Cap reconciliation, and client JS localization payloads.
 * Compliance: Pure helper layer — zero direct database queries, zero get_option() calls, zero HTML echo at include time, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retrieve cached tournament and season maps through Data Layer functions only.
 *
 * @return array Associative array with 'tournaments' and 'seasons' maps.
 */
if ( ! function_exists( 'sywat_pricing_get_context_maps' ) ) {
    function sywat_pricing_get_context_maps() {
        static $context_cache = null;

        if ( null !== $context_cache ) {
            return $context_cache;
        }

        $seasons     = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
        $tournaments = array();

        if ( function_exists( 'sywat_get_master_portal_data' ) ) {
            $portal_data = sywat_get_master_portal_data();
            if ( isset( $portal_data['tournaments'] ) && is_array( $portal_data['tournaments'] ) ) {
                $tournaments = $portal_data['tournaments'];
            }
            if ( empty( $seasons ) && isset( $portal_data['seasons'] ) && is_array( $portal_data['seasons'] ) ) {
                $seasons = $portal_data['seasons'];
            }
        }

        $context_cache = array(
            'tournaments' => is_array( $tournaments ) ? $tournaments : array(),
            'seasons'     => is_array( $seasons ) ? $seasons : array(),
        );

        return $context_cache;
    }
}

/**
 * Find a configured pricing profile by slug, label, code, or alias (O(1) cached).
 *
 * @param string $query Profile slug, label, code, or alias.
 * @return array|null   Matching profile record or null.
 */
if ( ! function_exists( 'sywat_find_pricing_profile' ) ) {
    function sywat_find_pricing_profile( $query ) {
        static $cache = array();

        $needle = trim( (string) $query );
        if ( $needle === '' ) {
            return null;
        }

        $key = strtolower( $needle );
        if ( array_key_exists( $key, $cache ) ) {
            return $cache[ $key ];
        }

        $profiles = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( true ) : array();
        if ( isset( $profiles[ $key ] ) ) {
            $cache[ $key ] = $profiles[ $key ];
            return $profiles[ $key ];
        }

        $slug_needle = sanitize_title( $needle );
        if ( $slug_needle !== '' && isset( $profiles[ $slug_needle ] ) ) {
            $cache[ $key ] = $profiles[ $slug_needle ];
            return $profiles[ $slug_needle ];
        }

        foreach ( $profiles as $prof ) {
            if ( strcasecmp( $needle, $prof['label'] ) === 0 || ( ! empty( $prof['code'] ) && strcasecmp( $needle, $prof['code'] ) === 0 ) ) {
                $cache[ $key ] = $prof;
                return $prof;
            }
            if ( ! empty( $prof['aliases'] ) && is_array( $prof['aliases'] ) ) {
                foreach ( $prof['aliases'] as $alias ) {
                    if ( strcasecmp( $needle, $alias ) === 0 ) {
                        $cache[ $key ] = $prof;
                        return $prof;
                    }
                }
            }
        }

        $cache[ $key ] = null;
        return null;
    }
}

/**
 * Return the global default active pricing profile.
 *
 * @return array Default pricing profile record.
 */
if ( ! function_exists( 'sywat_get_default_pricing_profile' ) ) {
    function sywat_get_default_pricing_profile() {
        $profiles = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( false ) : array();
        foreach ( $profiles as $prof ) {
            if ( ! empty( $prof['is_default'] ) ) {
                return $prof;
            }
        }
        if ( ! empty( $profiles ) ) {
            $first = reset( $profiles );
            return $first;
        }
        $seed = function_exists( 'sywat_pricing_get_default_seed' ) ? sywat_pricing_get_default_seed() : array();
        return isset( $seed['indoor'] ) ? $seed['indoor'] : array();
    }
}

/**
 * Resolve the list of enabled Pricing Profile records for a given Season.
 * Falls back gracefully to matching Season Type ('Indoor', 'Outdoor', 'Field 50')
 * when legacy seasons have not saved an explicit 'pricing_profiles' array yet.
 *
 * @param string|array $season_slug_or_data Season slug or season configuration array.
 * @param array|null   $seasons_map         Optional pre-loaded seasons array.
 * @return array                            Associative array of active pricing profile records keyed by slug.
 */
if ( ! function_exists( 'sywat_get_season_pricing_profiles' ) ) {
    function sywat_get_season_pricing_profiles( $season_slug_or_data, $seasons_map = null ) {
        $all_profiles = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( false ) : array();
        if ( empty( $all_profiles ) ) {
            return array();
        }

        $season_rec = null;
        if ( is_array( $season_slug_or_data ) ) {
            $season_rec = $season_slug_or_data;
        } elseif ( is_string( $season_slug_or_data ) && $season_slug_or_data !== '' ) {
            $seasons = is_array( $seasons_map ) ? $seasons_map : sywat_pricing_get_context_maps()['seasons'];
            if ( isset( $seasons[ $season_slug_or_data ] ) ) {
                $season_rec = $seasons[ $season_slug_or_data ];
            } else {
                foreach ( $seasons as $s_item ) {
                    if ( is_array( $s_item ) && isset( $s_item['name'] ) && strcasecmp( $s_item['name'], $season_slug_or_data ) === 0 ) {
                        $season_rec = $s_item;
                        break;
                    }
                }
            }
        }

        // 1. Explicit pricing_profiles array configured on the Season
        if ( is_array( $season_rec ) && ! empty( $season_rec['pricing_profiles'] ) && is_array( $season_rec['pricing_profiles'] ) ) {
            $matched = array();
            foreach ( $season_rec['pricing_profiles'] as $p_slug ) {
                $prof = sywat_find_pricing_profile( $p_slug );
                if ( $prof && $prof['status'] === 'active' ) {
                    $matched[ $prof['slug'] ] = $prof;
                }
            }
            if ( ! empty( $matched ) ) {
                return $matched;
            }
        }

        // 2. Smart fallback based on legacy Season Type ('Indoor', 'Outdoor', 'Field 50')
        $s_type = is_array( $season_rec ) && ! empty( $season_rec['type'] ) ? strtolower( trim( (string) $season_rec['type'] ) ) : '';
        if ( $s_type === 'outdoor' ) {
            $outdoor_slugs = array( 'field', 'field-50', 'known-3d', '900' );
            $matched       = array();
            foreach ( $outdoor_slugs as $os ) {
                if ( isset( $all_profiles[ $os ] ) ) {
                    $matched[ $os ] = $all_profiles[ $os ];
                }
            }
            if ( ! empty( $matched ) ) {
                return $matched;
            }
        } elseif ( $s_type !== '' ) {
            $by_type = sywat_find_pricing_profile( $s_type );
            if ( $by_type && $by_type['status'] === 'active' ) {
                return array( $by_type['slug'] => $by_type );
            }
        }

        $def = sywat_get_default_pricing_profile();
        return ! empty( $def['slug'] ) ? array( $def['slug'] => $def ) : array();
    }
}

/**
 * Resolve the active Pricing / Round Profile(s) offered by a specific Tournament.
 * - Single-format shoots (Indoor, Known 3D, 900) return 1 profile.
 * - Dual-round shoots (Field + Field 50 on the same course/start time) return both profiles.
 *
 * @param string|array $tournament_name_or_data Tournament title or tournament config array.
 * @param array|null   $tournaments_map         Optional pre-loaded tournaments array.
 * @param array|null   $seasons_map             Optional pre-loaded seasons array.
 * @return array                                Ordered associative array of pricing profiles keyed by slug.
 */
if ( ! function_exists( 'sywat_get_tournament_pricing_profiles' ) ) {
    function sywat_get_tournament_pricing_profiles( $tournament_name_or_data, $tournaments_map = null, $seasons_map = null ) {
        $ctx    = sywat_pricing_get_context_maps();
        $all_t  = is_array( $tournaments_map ) ? $tournaments_map : $ctx['tournaments'];
        $all_s  = is_array( $seasons_map ) ? $seasons_map : $ctx['seasons'];
        $t_data = null;

        if ( is_array( $tournament_name_or_data ) ) {
            $t_data = $tournament_name_or_data;
        } elseif ( is_string( $tournament_name_or_data ) && $tournament_name_or_data !== '' ) {
            if ( isset( $all_t[ $tournament_name_or_data ] ) ) {
                $t_data = $all_t[ $tournament_name_or_data ];
            } else {
                foreach ( $all_t as $k => $v ) {
                    if ( strcasecmp( trim( (string) $k ), trim( $tournament_name_or_data ) ) === 0 ) {
                        $t_data = $v;
                        break;
                    }
                }
            }
        }

        // 1. Explicit pricing_profiles configured on the Tournament
        if ( is_array( $t_data ) && ! empty( $t_data['pricing_profiles'] ) && is_array( $t_data['pricing_profiles'] ) ) {
            $matched = array();
            foreach ( $t_data['pricing_profiles'] as $p_slug ) {
                $prof = sywat_find_pricing_profile( $p_slug );
                if ( $prof ) {
                    $matched[ $prof['slug'] ] = $prof;
                }
            }
            if ( ! empty( $matched ) ) {
                return $matched;
            }
        }

        // 2. Inherit from parent Season
        $season_slug = '';
        if ( is_array( $t_data ) ) {
            $season_slug = ! empty( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( ! empty( $t_data['season'] ) ? $t_data['season'] : '' );
        }

        $season_profiles = sywat_get_season_pricing_profiles( $season_slug, $all_s );
        if ( ! empty( $season_profiles ) ) {
            if ( isset( $season_profiles['field'], $season_profiles['field-50'] ) && count( $season_profiles ) > 2 ) {
                return array(
                    'field'    => $season_profiles['field'],
                    'field-50' => $season_profiles['field-50'],
                );
            }
            return $season_profiles;
        }

        $def = sywat_get_default_pricing_profile();
        return ! empty( $def['slug'] ) ? array( $def['slug'] => $def ) : array();
    }
}

/**
 * Resolve the single effective Pricing Profile for a tournament + optional archer round selection.
 *
 * @param string|array $tournament_name_or_data Tournament title or config array.
 * @param string       $round_slug              Optional archer-selected round/format slug.
 * @param array|null   $tournaments_map         Optional pre-loaded tournaments array.
 * @param array|null   $seasons_map             Optional pre-loaded seasons array.
 * @return array                                Effective pricing profile array.
 */
if ( ! function_exists( 'sywat_resolve_active_pricing_profile' ) ) {
    function sywat_resolve_active_pricing_profile( $tournament_name_or_data, $round_slug = '', $tournaments_map = null, $seasons_map = null ) {
        $t_profiles = sywat_get_tournament_pricing_profiles( $tournament_name_or_data, $tournaments_map, $seasons_map );

        if ( $round_slug !== '' ) {
            $clean_slug = sanitize_title( $round_slug );
            if ( isset( $t_profiles[ $clean_slug ] ) ) {
                return $t_profiles[ $clean_slug ];
            }
            $direct = sywat_find_pricing_profile( $round_slug );
            if ( $direct ) {
                return $direct;
            }
        }

        if ( ! empty( $t_profiles ) ) {
            $first = reset( $t_profiles );
            return $first;
        }

        return sywat_get_default_pricing_profile();
    }
}

/**
 * Resolve the standard individual entry fee for an archer from database-configured
 * Pricing Profiles and Age Division rate tiers (with zero hardcoded prices).
 *
 * @param string|array $tournament_name_or_data Tournament title or config array.
 * @param string       $division                Archer age division label.
 * @param string       $distance                Optional target distance ('Cub' or '20yd').
 * @param string       $style                   Optional shooting style label (for Pro rate check).
 * @param string       $round_slug              Optional archer-selected round slug (e.g., 'field' vs 'field-50').
 * @param string       $paid_status             Optional existing paid tier ('Field Governor', 'Promo (Free)', etc.).
 * @return float                                Calculated individual entry fee.
 */
if ( ! function_exists( 'sywat_get_archer_individual_fee' ) ) {
    function sywat_get_archer_individual_fee( $tournament_name_or_data, $division, $distance = '', $style = '', $round_slug = '', $paid_status = '' ) {
        $clean_div  = trim( (string) $division );
        $clean_paid = trim( (string) $paid_status );

        if ( strcasecmp( $clean_div, 'No Show' ) === 0 ) {
            return 0.00;
        }

        if ( strcasecmp( $clean_paid, 'Promo (Free)' ) === 0 || stripos( $clean_paid, 'Promo' ) === 0 ) {
            return 0.00;
        }

        $profile = sywat_resolve_active_pricing_profile( $tournament_name_or_data, $round_slug );
        if ( empty( $profile ) ) {
            return 30.00;
        }

        if ( strcasecmp( $clean_paid, 'Field Governor' ) === 0 ) {
            return isset( $profile['field_governor_rate'] ) ? floatval( $profile['field_governor_rate'] ) : 0.00;
        }

        // 1. Check per-division override on the active pricing profile
        $div_rec = ( $clean_div !== '' && function_exists( 'sywat_find_division' ) ) ? sywat_find_division( $clean_div ) : null;
        if ( is_array( $div_rec ) && ! empty( $div_rec['slug'] ) && isset( $profile['division_overrides'][ $div_rec['slug'] ] ) ) {
            return floatval( $profile['division_overrides'][ $div_rec['slug'] ] );
        }

        // 2. Check optional Pro rate if archer is shooting a Pro style
        if ( $style !== '' && isset( $profile['pro_rate'] ) && $profile['pro_rate'] !== '' && is_numeric( $profile['pro_rate'] ) ) {
            if ( function_exists( 'sywat_is_pro_style' ) && sywat_is_pro_style( $style, $clean_div ) ) {
                return floatval( $profile['pro_rate'] );
            }
        }

        // 3. Resolve Youth vs. Adult tier from database-configured Age Division
        $is_youth = false;
        if ( is_array( $div_rec ) && isset( $div_rec['rate_tier'] ) ) {
            $is_youth = ( $div_rec['rate_tier'] === 'Youth' );
        } elseif ( function_exists( 'sywat_waitlist_is_youth_division' ) ) {
            $is_youth = sywat_waitlist_is_youth_division( $clean_div, $distance );
        }

        return $is_youth
            ? ( isset( $profile['youth_rate'] ) ? floatval( $profile['youth_rate'] ) : 25.00 )
            : ( isset( $profile['adult_rate'] ) ? floatval( $profile['adult_rate'] ) : 30.00 );
    }
}

/**
 * Evaluate whether an archer qualifies for a First-Time / Lapsed Shooter Promotional Free Entry
 * based on Season toggle, Pricing Profile promo settings, prior participation lookback window,
 * and configurable usage limits (Per Season, Per Lookback Window, or Lifetime).
 *
 * @param string       $archer_name             Full archer name.
 * @param string|array $tournament_name_or_data Target tournament title or config array.
 * @param string       $round_slug              Optional selected round slug (e.g., 'field-50').
 * @param array|null   $registrations           Optional pre-loaded registrations list (avoids extra DB reads).
 * @param string       $exclude_reg_id          Optional registration ID to exclude (when editing an existing record).
 * @return array                                Result array ('eligible', 'reason', 'rate', 'label', 'profile_label', 'used_count', 'max_uses').
 */
if ( ! function_exists( 'sywat_check_archer_promo_eligibility' ) ) {
    function sywat_check_archer_promo_eligibility( $archer_name, $tournament_name_or_data, $round_slug = '', $registrations = null, $exclude_reg_id = '' ) {
        $clean_name = strtolower( trim( (string) $archer_name ) );
        if ( $clean_name === '' ) {
            return array(
                'eligible'      => false,
                'reason'        => 'empty_name',
                'rate'          => 0.00,
                'label'         => '',
                'profile_label' => '',
                'used_count'    => 0,
                'max_uses'      => 0,
            );
        }

        $ctx    = sywat_pricing_get_context_maps();
        $all_t  = $ctx['tournaments'];
        $all_s  = $ctx['seasons'];
        $t_data = null;
        $t_name = '';

        if ( is_array( $tournament_name_or_data ) ) {
            $t_data = $tournament_name_or_data;
            $t_name = isset( $t_data['name'] ) ? $t_data['name'] : '';
        } elseif ( is_string( $tournament_name_or_data ) && $tournament_name_or_data !== '' ) {
            $t_name = $tournament_name_or_data;
            if ( isset( $all_t[ $t_name ] ) ) {
                $t_data = $all_t[ $t_name ];
            } else {
                foreach ( $all_t as $k => $v ) {
                    if ( strcasecmp( trim( (string) $k ), trim( $t_name ) ) === 0 ) {
                        $t_data = $v;
                        $t_name = $k;
                        break;
                    }
                }
            }
        }

        // Resolve parent Season & check Season-level Promo toggle
        $season_slug = '';
        if ( is_array( $t_data ) ) {
            $season_slug = ! empty( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( ! empty( $t_data['season'] ) ? $t_data['season'] : '' );
        }
        $season_rec = ( $season_slug !== '' && isset( $all_s[ $season_slug ] ) ) ? $all_s[ $season_slug ] : null;

        if ( ! is_array( $season_rec ) || empty( $season_rec['promo_enabled'] ) ) {
            return array(
                'eligible'      => false,
                'reason'        => 'season_disabled',
                'rate'          => 0.00,
                'label'         => '',
                'profile_label' => '',
                'used_count'    => 0,
                'max_uses'      => 0,
            );
        }

        // Resolve Pricing Profile & check Profile-level Promo toggle
        $profile = sywat_resolve_active_pricing_profile( is_array( $t_data ) ? $t_data : $t_name, $round_slug, $all_t, $all_s );
        if ( empty( $profile ) || empty( $profile['promo_enabled'] ) ) {
            return array(
                'eligible'      => false,
                'reason'        => 'profile_disabled',
                'rate'          => 0.00,
                'label'         => '',
                'profile_label' => isset( $profile['label'] ) ? $profile['label'] : '',
                'used_count'    => 0,
                'max_uses'      => 0,
            );
        }

        $ref_date_str = ( is_array( $t_data ) && ! empty( $t_data['start_date'] ) ) ? $t_data['start_date'] : current_time( 'Y-m-d' );
        $ref_ts       = strtotime( $ref_date_str );
        if ( false === $ref_ts ) {
            $ref_ts = current_time( 'timestamp' );
        }

        $lookback_val  = isset( $profile['promo_lookback_value'] ) ? max( 1, intval( $profile['promo_lookback_value'] ) ) : 24;
        $lookback_unit = ( isset( $profile['promo_lookback_unit'] ) && $profile['promo_lookback_unit'] === 'years' ) ? 'years' : 'months';
        $cutoff_ts     = strtotime( "-{$lookback_val} {$lookback_unit}", $ref_ts );

        $qualifying_history_slugs = ! empty( $profile['promo_history_profiles'] ) && is_array( $profile['promo_history_profiles'] )
            ? $profile['promo_history_profiles']
            : array( $profile['slug'] );

        $max_uses    = isset( $profile['promo_max_uses'] ) ? max( 0, intval( $profile['promo_max_uses'] ) ) : 1;
        $usage_scope = isset( $profile['promo_usage_scope'] ) ? $profile['promo_usage_scope'] : 'season';

        if ( ! is_array( $registrations ) ) {
            $registrations = function_exists( 'sywat_get_all_registrations' ) ? sywat_get_all_registrations() : array();
        }

        // Pre-map tournament names to their primary format slug & start_date in RAM
        $tourn_meta_cache = array();
        foreach ( $all_t as $tk => $tv ) {
            $norm_tk = strtolower( trim( (string) $tk ) );
            $t_profs = sywat_get_tournament_pricing_profiles( $tv, $all_t, $all_s );
            $first_p = ! empty( $t_profs ) ? reset( $t_profs ) : null;
            $tourn_meta_cache[ $norm_tk ] = array(
                'start_date'   => ( is_array( $tv ) && ! empty( $tv['start_date'] ) ) ? $tv['start_date'] : '',
                'season_slug'  => ( is_array( $tv ) && ! empty( $tv['season_slug'] ) ) ? $tv['season_slug'] : ( ( is_array( $tv ) && ! empty( $tv['season'] ) ) ? $tv['season'] : '' ),
                'default_prof' => is_array( $first_p ) && ! empty( $first_p['slug'] ) ? $first_p['slug'] : '',
            );
        }

        $used_count = 0;

        foreach ( $registrations as $reg ) {
            if ( ! is_array( $reg ) ) {
                continue;
            }
            if ( $exclude_reg_id !== '' && isset( $reg['id'] ) && (string) $reg['id'] === (string) $exclude_reg_id ) {
                continue;
            }

            $reg_name = ! empty( $reg['name'] ) ? strtolower( trim( (string) $reg['name'] ) ) : '';
            if ( $reg_name !== $clean_name ) {
                continue;
            }

            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Cancelled' ) === 0 || strcasecmp( $status, 'Waitlist' ) === 0 ) {
                continue;
            }
            $div = isset( $reg['division'] ) ? trim( (string) $reg['division'] ) : '';
            if ( strcasecmp( $div, 'No Show' ) === 0 ) {
                continue;
            }

            $reg_t_name = isset( $reg['tournament'] ) ? strtolower( trim( (string) $reg['tournament'] ) ) : '';
            $t_meta     = isset( $tourn_meta_cache[ $reg_t_name ] ) ? $tourn_meta_cache[ $reg_t_name ] : array( 'start_date' => '', 'season_slug' => '', 'default_prof' => '' );

            $ev_date_str = ! empty( $t_meta['start_date'] ) ? $t_meta['start_date'] : ( ! empty( $reg['created_at'] ) ? substr( (string) $reg['created_at'], 0, 10 ) : '' );
            $ev_ts       = ( $ev_date_str !== '' ) ? strtotime( $ev_date_str ) : false;

            // Resolve format slug for this historical entry
            $entry_prof_slug = ! empty( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : '';
            if ( $entry_prof_slug === '' ) {
                $entry_prof_slug = $t_meta['default_prof'];
            }

            // 1. Check if this entry already used a Promotional Free Entry
            $paid_str = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
            $is_promo = ! empty( $reg['is_promo_free'] ) || ( strcasecmp( $paid_str, 'Promo (Free)' ) === 0 ) || ( stripos( $paid_str, 'Promo' ) === 0 );

            if ( $is_promo && ( $entry_prof_slug === '' || $entry_prof_slug === $profile['slug'] ) ) {
                if ( $usage_scope === 'lifetime' ) {
                    $used_count++;
                } elseif ( $usage_scope === 'season' ) {
                    if ( $t_meta['season_slug'] === $season_slug ) {
                        $used_count++;
                    }
                } elseif ( $usage_scope === 'lookback' ) {
                    if ( false !== $ev_ts && $ev_ts >= $cutoff_ts ) {
                        $used_count++;
                    }
                }
            }

            // 2. Check if this entry represents actual participation in a qualifying prior-experience format
            $has_participated = ! empty( $reg['checked_in'] )
                || ( isset( $reg['score'] ) && is_numeric( $reg['score'] ) && floatval( $reg['score'] ) > 0 )
                || ( false !== $ev_ts && $ev_ts < $ref_ts );

            if ( $has_participated && $entry_prof_slug !== '' && in_array( $entry_prof_slug, $qualifying_history_slugs, true ) ) {
                if ( false !== $ev_ts && $ev_ts >= $cutoff_ts && $ev_ts <= $ref_ts ) {
                    return array(
                        'eligible'      => false,
                        'reason'        => 'recent_experience',
                        'rate'          => 0.00,
                        'label'         => '',
                        'profile_label' => $profile['label'],
                        'used_count'    => $used_count,
                        'max_uses'      => $max_uses,
                    );
                }
            }
        }

        if ( $max_uses > 0 && $used_count >= $max_uses ) {
            return array(
                'eligible'      => false,
                'reason'        => 'usage_limit_reached',
                'rate'          => 0.00,
                'label'         => '',
                'profile_label' => $profile['label'],
                'used_count'    => $used_count,
                'max_uses'      => $max_uses,
            );
        }

        return array(
            'eligible'      => true,
            'reason'        => 'eligible',
            'rate'          => 0.00,
            'label'         => ! empty( $profile['promo_label'] ) ? $profile['promo_label'] : 'Promo (Free)',
            'profile_label' => $profile['label'],
            'used_count'    => $used_count,
            'max_uses'      => $max_uses,
        );
    }
}

/**
 * Reconcile paid tiers and balances for all active members of a household group
 * using the active tournament/round Pricing Profile(s) and Family Cap settings.
 * Supports both 'line' and 'tournament' cap scopes and mixed rounds (e.g., Field + Field 50).
 *
 * @param string $tourn_name    Active tournament name.
 * @param string $line_idx      Target shooting line index.
 * @param string $family_id     Family group identifier.
 * @param array  $registrations In-memory registrations list passed by reference.
 * @param array  $t_lines       Optional tournament lines config for index resolution.
 * @return array                Summary array ('is_family_capped', 'active_count', 'line_total_due', 'cap_amount').
 */
if ( ! function_exists( 'sywat_recalculate_household_fees' ) ) {
    function sywat_recalculate_household_fees( $tourn_name, $line_idx, $family_id, &$registrations, $t_lines = array() ) {
        $target_tourn = trim( (string) $tourn_name );
        $target_line  = (string) $line_idx;
        $target_fam   = trim( (string) $family_id );

        if ( $target_tourn === '' || $target_fam === '' || ! is_array( $registrations ) ) {
            return array(
                'is_family_capped' => false,
                'active_count'     => 0,
                'line_total_due'   => 0.00,
                'cap_amount'       => 0.00,
            );
        }

        $t_profiles     = sywat_get_tournament_pricing_profiles( $target_tourn );
        $use_tourn_wide = false;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                $use_tourn_wide = true;
                break;
            }
        }

        $active_indices              = array();
        $prior_due_sum               = 0.00;
        $prior_paid_sum              = 0.00;
        $had_explicit_paid_tracking  = false;
        $was_previously_capped       = false;
        $prior_active_individual_sum = 0.00;
        $prior_cap_amount            = 75.00;

        $any_cap_enabled = false;
        $effective_cap   = 0.00;
        $effective_min   = 99;

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

            if ( ! $use_tourn_wide ) {
                $resolved_l = function_exists( 'sywat_waitlist_resolve_line_idx' )
                    ? sywat_waitlist_resolve_line_idx( $reg, $t_lines )
                    : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

                if ( $resolved_l !== $target_line ) {
                    continue;
                }
            }

            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            if ( strcasecmp( $status, 'Waitlist' ) === 0 ) {
                if ( ( isset( $reg['balance_due'] ) && floatval( $reg['balance_due'] ) !== 0.0 ) || ( isset( $reg['paid'] ) && $reg['paid'] !== 'Waitlist' ) ) {
                    $reg['paid']        = 'Waitlist';
                    $reg['balance_due'] = 0.00;
                    if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                        sywat_waitlist_persist_record( $reg, $registrations );
                    }
                }
                continue;
            }

            $paid_val = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
            if ( strcasecmp( $paid_val, 'Field Governor' ) === 0 || strcasecmp( $paid_val, 'Promo (Free)' ) === 0 || stripos( $paid_val, 'Promo' ) === 0 || ! empty( $reg['is_promo_free'] ) ) {
                continue;
            }

            $div_val    = isset( $reg['division'] ) ? $reg['division'] : '';
            $dist_val   = isset( $reg['distance'] ) ? $reg['distance'] : '20yd';
            $style_val  = isset( $reg['style'] ) ? $reg['style'] : '';
            $round_slug = isset( $reg['pricing_profile'] ) ? $reg['pricing_profile'] : '';

            $prof     = sywat_resolve_active_pricing_profile( $target_tourn, $round_slug );
            $ind_rate = sywat_get_archer_individual_fee( $target_tourn, $div_val, $dist_val, $style_val, $round_slug, '' );

            if ( ! empty( $prof['family_cap_enabled'] ) ) {
                $any_cap_enabled = true;
                $p_cap           = isset( $prof['family_cap_amount'] ) ? floatval( $prof['family_cap_amount'] ) : 75.00;
                $p_min           = isset( $prof['family_min_archers'] ) ? max( 2, intval( $prof['family_min_archers'] ) ) : 3;
                if ( $p_cap > $effective_cap ) {
                    $effective_cap = $p_cap;
                }
                if ( $p_min < $effective_min ) {
                    $effective_min = $p_min;
                }
            }

            if ( strcasecmp( $paid_val, 'Family' ) === 0 ) {
                $was_previously_capped = true;
                if ( $effective_cap > 0 ) {
                    $prior_cap_amount = $effective_cap;
                }
            }

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
                'cap_amount'       => $effective_cap,
            );
        }

        $raw_sum = 0.00;
        foreach ( $active_indices as $item ) {
            $raw_sum += $item['ind_rate'];
        }

        if ( $effective_min === 99 ) {
            $effective_min = 3;
        }

        $is_family_capped  = $any_cap_enabled && ( $active_count >= $effective_min ) && ( $raw_sum > $effective_cap );
        $target_line_total = $is_family_capped ? $effective_cap : $raw_sum;

        if ( $had_explicit_paid_tracking ) {
            $amount_already_paid = $prior_paid_sum;
        } else {
            $prior_billed_total  = $was_previously_capped ? $prior_cap_amount : $prior_active_individual_sum;
            $amount_already_paid = max( 0.00, round( $prior_billed_total - $prior_due_sum, 2 ) );
        }

        $remaining_line_due = max( 0.00, round( $target_line_total - $amount_already_paid, 2 ) );

        if ( $is_family_capped ) {
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

                if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                    sywat_waitlist_persist_record( $reg_copy, $registrations );
                }
            }
        } else {
            $running_paid_credit = $amount_already_paid;

            foreach ( $active_indices as $item ) {
                $reg_copy = $registrations[ $item['index'] ];
                unset( $reg_copy['_just_promoted'] );

                $div_val  = isset( $reg_copy['division'] ) ? $reg_copy['division'] : '';
                $dist_val = isset( $reg_copy['distance'] ) ? $reg_copy['distance'] : '20yd';
                $ind_rate = $item['ind_rate'];

                if ( function_exists( 'sywat_waitlist_get_paid_tier' ) ) {
                    $reg_copy['paid'] = sywat_waitlist_get_paid_tier( $div_val, $dist_val );
                }

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

                if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                    sywat_waitlist_persist_record( $reg_copy, $registrations );
                }
            }
        }

        return array(
            'is_family_capped' => $is_family_capped,
            'active_count'     => $active_count,
            'line_total_due'   => $remaining_line_due,
            'cap_amount'       => $effective_cap,
        );
    }
}

/**
 * Format a compact plain-text summary of a pricing profile's rates and cap rules.
 *
 * @param array $profile Pricing profile record.
 * @return string        Formatted summary string.
 */
if ( ! function_exists( 'sywat_format_pricing_profile_summary' ) ) {
    function sywat_format_pricing_profile_summary( $profile ) {
        if ( ! is_array( $profile ) ) {
            return '';
        }
        $youth = isset( $profile['youth_rate'] ) ? number_format( floatval( $profile['youth_rate'] ), 2 ) : '25.00';
        $adult = isset( $profile['adult_rate'] ) ? number_format( floatval( $profile['adult_rate'] ), 2 ) : '30.00';
        $parts = array( '$' . $youth . ' Youth', '$' . $adult . ' Adult' );

        if ( ! empty( $profile['family_cap_enabled'] ) ) {
            $cap   = isset( $profile['family_cap_amount'] ) ? number_format( floatval( $profile['family_cap_amount'] ), 2 ) : '75.00';
            $min   = isset( $profile['family_min_archers'] ) ? intval( $profile['family_min_archers'] ) : 3;
            $scope = ( isset( $profile['family_cap_scope'] ) && $profile['family_cap_scope'] === 'tournament' ) ? 'Event' : 'Line';
            $parts[] = 'Cap $' . $cap . ' (' . $min . '+ / ' . $scope . ')';
        }

        return implode( ' • ', $parts );
    }
}

/**
 * Builds the localized JavaScript configuration payload for front-end pricing,
 * tournament round formats, family cap calculators, and promo rules.
 *
 * @return array Associative payload for wp_localize_script().
 */
if ( ! function_exists( 'sywat_get_pricing_js_payload' ) ) {
    function sywat_get_pricing_js_payload() {
        $profiles    = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( false ) : array();
        $default_p   = sywat_get_default_pricing_profile();
        $ctx         = sywat_pricing_get_context_maps();
        $seasons     = $ctx['seasons'];
        $tournaments = $ctx['tournaments'];

        $tournament_map = array();
        foreach ( $tournaments as $t_name => $t_data ) {
            $t_profs    = sywat_get_tournament_pricing_profiles( $t_data, $tournaments, $seasons );
            $s_slug     = is_array( $t_data ) && ! empty( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( is_array( $t_data ) && ! empty( $t_data['season'] ) ? $t_data['season'] : '' );
            $s_promo_on = ( $s_slug !== '' && isset( $seasons[ $s_slug ]['promo_enabled'] ) ) ? (bool) $seasons[ $s_slug ]['promo_enabled'] : false;

            $tournament_map[ $t_name ] = array(
                'profiles'      => array_keys( $t_profs ),
                'season_slug'   => $s_slug,
                'promo_enabled' => $s_promo_on,
                'start_date'    => is_array( $t_data ) && ! empty( $t_data['start_date'] ) ? $t_data['start_date'] : '',
            );
        }

        return array(
            'defaultProfile' => isset( $default_p['slug'] ) ? $default_p['slug'] : 'indoor',
            'profiles'       => $profiles,
            'tournaments'    => $tournament_map,
        );
    }
}
