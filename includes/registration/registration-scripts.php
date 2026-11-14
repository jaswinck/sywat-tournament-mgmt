<?php
/**
 * Sub-Module: Registration Engine Client Scripts Enqueuer
 * Path: includes/registration/registration-scripts.php
 * Description: Enqueues registration portal client scripts and localizes cached historical shooter data, phone numbers, database-configured age divisions, active tournament pricing/round profiles, and O(1) First-Time/Lapsed Shooter promoter eligibility maps.
 * Compliance: Pure script enqueuer — zero HTML echo, zero direct SQL queries, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolve the currently selected tournament name for script localization
 * when not explicitly passed by the caller.
 *
 * @param string $explicit_tournament Optional explicit tournament name.
 * @return string                     Resolved tournament name.
 */
if ( ! function_exists( 'sywat_registration_resolve_script_tournament' ) ) {
    function sywat_registration_resolve_script_tournament( $explicit_tournament = '' ) {
        if ( ! empty( $explicit_tournament ) ) {
            return trim( (string) $explicit_tournament );
        }

        if ( isset( $_REQUEST['selected_tournament'] ) && $_REQUEST['selected_tournament'] !== '' ) {
            return trim( sanitize_text_field( wp_unslash( $_REQUEST['selected_tournament'] ) ) );
        }
        if ( isset( $_REQUEST['waitlist_tournament'] ) && $_REQUEST['waitlist_tournament'] !== '' ) {
            return trim( sanitize_text_field( wp_unslash( $_REQUEST['waitlist_tournament'] ) ) );
        }
        if ( isset( $_GET['view_tournament'] ) && $_GET['view_tournament'] !== '' ) {
            return trim( sanitize_text_field( wp_unslash( $_GET['view_tournament'] ) ) );
        }

        $uid = get_current_user_id();
        if ( $uid ) {
            $saved_t = trim( (string) get_user_meta( $uid, 'sywat_desk_selected_tournament', true ) );
            if ( $saved_t !== '' ) {
                return $saved_t;
            }
        }

        return trim( (string) get_option( 'sywat_current_tournament', '' ) );
    }
}

/**
 * Build an O(1) client-side Promoter eligibility lookup map per offered round profile
 * for the active tournament so intake rows reflect $0.00 First-Time/Lapsed Shooter promos live.
 *
 * @param string $tournament_name Active tournament name.
 * @param array  $t_profiles      Ordered map of pricing profiles offered by the tournament.
 * @return array                  Map keyed by profile slug ('active', 'label', 'ineligible').
 */
if ( ! function_exists( 'sywat_registration_build_promo_rules_payload' ) ) {
    function sywat_registration_build_promo_rules_payload( $tournament_name, $t_profiles ) {
        $payload = array();

        if ( empty( $tournament_name ) || ! is_array( $t_profiles ) || empty( $t_profiles ) ) {
            return $payload;
        }

        $ctx         = function_exists( 'sywat_pricing_get_context_maps' ) ? sywat_pricing_get_context_maps() : array( 'tournaments' => array(), 'seasons' => array() );
        $all_t       = isset( $ctx['tournaments'] ) ? $ctx['tournaments'] : array();
        $all_s       = isset( $ctx['seasons'] ) ? $ctx['seasons'] : array();
        $t_data      = isset( $all_t[ $tournament_name ] ) ? $all_t[ $tournament_name ] : null;

        if ( ! is_array( $t_data ) ) {
            foreach ( $all_t as $k => $v ) {
                if ( strcasecmp( trim( (string) $k ), trim( (string) $tournament_name ) ) === 0 ) {
                    $t_data = $v;
                    break;
                }
            }
        }

        $season_slug = '';
        if ( is_array( $t_data ) ) {
            $season_slug = ! empty( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( ! empty( $t_data['season'] ) ? $t_data['season'] : '' );
        }
        $season_rec      = ( $season_slug !== '' && isset( $all_s[ $season_slug ] ) ) ? $all_s[ $season_slug ] : null;
        $season_promo_on = is_array( $season_rec ) && ! empty( $season_rec['promo_enabled'] );

        $registrations = function_exists( 'sywat_get_all_registrations' ) ? sywat_get_all_registrations() : array();

        // Collect unique shooter names across registrations and historical TFAA cache
        $candidate_names = array();
        if ( is_array( $registrations ) ) {
            foreach ( $registrations as $reg ) {
                if ( is_array( $reg ) && ! empty( $reg['name'] ) ) {
                    $norm = strtolower( trim( (string) $reg['name'] ) );
                    if ( $norm !== '' ) {
                        $candidate_names[ $norm ] = trim( (string) $reg['name'] );
                    }
                }
            }
        }

        foreach ( $t_profiles as $p_slug => $prof ) {
            $is_active = $season_promo_on && ! empty( $prof['promo_enabled'] );
            $ineligible_map = array();

            if ( $is_active && function_exists( 'sywat_check_archer_promo_eligibility' ) ) {
                foreach ( $candidate_names as $norm_name => $orig_name ) {
                    $check = sywat_check_archer_promo_eligibility(
                        $orig_name,
                        is_array( $t_data ) ? $t_data : $tournament_name,
                        $p_slug,
                        $registrations
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
 * Enqueues registration portal client scripts and localizes cached historical shooter data,
 * phone numbers, active age divisions, tournament pricing profiles, and promo eligibility maps.
 *
 * @param string $selected_tournament Optional active tournament name.
 * @return void
 */
if ( ! function_exists( 'sywat_render_registration_scripts' ) ) {
    function sywat_render_registration_scripts( $selected_tournament = '' ) {
        $tournament_name = sywat_registration_resolve_script_tournament( $selected_tournament );
        $phone_cache     = get_option( 'sywat_shooter_phones_cache', array() );

        // Fetch 18-month rolling window historical shooters
        $js_tfaa_data = function_exists( 'sywat_get_historical_shooters' )
            ? sywat_get_historical_shooters()
            : array();

        // Resolve active Pricing & Round Profiles for this Tournament
        $t_profiles       = ( $tournament_name !== '' && function_exists( 'sywat_get_tournament_pricing_profiles' ) )
            ? sywat_get_tournament_pricing_profiles( $tournament_name )
            : array();
        $default_prof     = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';
        $init_adult_rate  = is_array( $default_prof ) && isset( $default_prof['adult_rate'] ) ? floatval( $default_prof['adult_rate'] ) : 30.00;
        $init_youth_rate  = is_array( $default_prof ) && isset( $default_prof['youth_rate'] ) ? floatval( $default_prof['youth_rate'] ) : 25.00;

        // Build active age division metadata payload from database configuration
        $divisions_payload = array();
        if ( function_exists( 'sywat_get_division_records' ) ) {
            $div_records = sywat_get_division_records( false );
            if ( is_array( $div_records ) ) {
                foreach ( $div_records as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    $div_slug  = isset( $rec['slug'] ) ? $rec['slug'] : sanitize_title( $rec['label'] );
                    $rate_tier = ( isset( $rec['rate_tier'] ) && $rec['rate_tier'] === 'Youth' ) ? 'Youth' : 'Adult';
                    $distance  = ( isset( $rec['distance'] ) && $rec['distance'] === 'Cub' ) ? 'Cub' : '20yd';

                    $div_fee = function_exists( 'sywat_get_archer_individual_fee' )
                        ? sywat_get_archer_individual_fee( $tournament_name, $rec['label'], $distance, '', $default_prof_key, '' )
                        : ( ( $rate_tier === 'Youth' ) ? $init_youth_rate : $init_adult_rate );

                    $divisions_payload[] = array(
                        'slug'      => $div_slug,
                        'code'      => isset( $rec['code'] ) ? $rec['code'] : '',
                        'label'     => $rec['label'],
                        'distance'  => $distance,
                        'rate_tier' => $rate_tier,
                        'fee'       => $div_fee,
                        'aliases'   => ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ? array_values( $rec['aliases'] ) : array(),
                    );
                }
            }
        }

        $promo_rules = sywat_registration_build_promo_rules_payload( $tournament_name, $t_profiles );

        // Determine script URL relative to plugin root
        $js_url = defined( 'SYWAT_PLUGIN_URL' )
            ? SYWAT_PLUGIN_URL . 'assets/js/sywat-registration.js'
            : plugins_url( '../../assets/js/sywat-registration.js', __FILE__ );

        $version = defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '7.1.0';

        // Enqueue external JavaScript file
        wp_enqueue_script( 'sywat-registration-js', $js_url, array(), $version, true );

        // Localize database caches, pricing profiles, promo rules, and division rules to JS window object safely
        wp_localize_script( 'sywat-registration-js', 'sywatRegData', array(
            'tournamentName' => $tournament_name,
            'defaultProfile' => $default_prof_key,
            'profiles'       => $t_profiles,
            'promoRules'     => $promo_rules,
            'tfaaShooters'   => array_values( $js_tfaa_data ),
            'phoneCache'     => is_array( $phone_cache ) ? $phone_cache : array(),
            'divisions'      => $divisions_payload,
        ) );
    }
}
