<?php
/**
 * Sub-Module: Archer Roster Client Scripts Enqueuer
 * Path: includes/roster/roster-scripts.php
 * Description: Enqueues the Roster Management client controller and shared Shooting Styles rule engine, localizing line-scoped family groups, database-configured age divisions, and active tournament pricing/round profiles.
 * Compliance: Pure script enqueuer — zero HTML echo, zero direct SQL queries, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'sywat_render_roster_scripts' ) ) {
    /**
     * Enqueue Roster Management client scripts and localize line-scoped family groups,
     * age divisions, and active tournament pricing/round profiles.
     *
     * @param array  $existing_families_by_line Line-scoped family group lookup map.
     * @param string $selected_tournament       Optional active tournament name.
     * @return void
     */
    function sywat_render_roster_scripts( $existing_families_by_line = array(), $selected_tournament = '' ) {
        // Ensure shared Shooting Styles & Division Rule controller is enqueued first
        if ( function_exists( 'sywat_enqueue_shooting_styles_scripts' ) ) {
            sywat_enqueue_shooting_styles_scripts();
        }

        $tournament_name = trim( (string) $selected_tournament );
        if ( $tournament_name === '' ) {
            if ( ! empty( $_REQUEST['view_tournament'] ) ) {
                $tournament_name = trim( sanitize_text_field( wp_unslash( $_REQUEST['view_tournament'] ) ) );
            } elseif ( ! empty( $_REQUEST['selected_tournament'] ) ) {
                $tournament_name = trim( sanitize_text_field( wp_unslash( $_REQUEST['selected_tournament'] ) ) );
            }
        }

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

        // Build age division metadata payload (include retired records so existing assignments resolve accurately)
        $divisions_payload = array();
        if ( function_exists( 'sywat_get_division_records' ) ) {
            $div_records = sywat_get_division_records( true );
            if ( is_array( $div_records ) ) {
                foreach ( $div_records as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    $rate_tier = ( isset( $rec['rate_tier'] ) && $rec['rate_tier'] === 'Youth' ) ? 'Youth' : 'Adult';
                    $distance  = ( isset( $rec['distance'] ) && $rec['distance'] === 'Cub' ) ? 'Cub' : '20yd';

                    $div_fee = function_exists( 'sywat_get_archer_individual_fee' )
                        ? sywat_get_archer_individual_fee( $tournament_name, $rec['label'], $distance, '', $default_prof_key, '' )
                        : ( ( $rate_tier === 'Youth' ) ? $init_youth_rate : $init_adult_rate );

                    $divisions_payload[] = array(
                        'slug'      => isset( $rec['slug'] ) ? $rec['slug'] : sanitize_title( $rec['label'] ),
                        'code'      => isset( $rec['code'] ) ? $rec['code'] : '',
                        'label'     => $rec['label'],
                        'distance'  => $distance,
                        'rate_tier' => $rate_tier,
                        'fee'       => $div_fee,
                        'status'    => isset( $rec['status'] ) ? $rec['status'] : 'active',
                        'aliases'   => ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ? array_values( $rec['aliases'] ) : array(),
                    );
                }
            }
        }

        // Determine script URL relative to plugin root
        $js_url = defined( 'SYWAT_PLUGIN_URL' )
            ? SYWAT_PLUGIN_URL . 'assets/js/sywat-roster.js'
            : plugins_url( '../../assets/js/sywat-roster.js', __FILE__ );

        $version = defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '7.1.0';
        $deps    = wp_script_is( 'sywat-shooting-styles-js', 'registered' ) ? array( 'sywat-shooting-styles-js' ) : array();

        // Enqueue external JavaScript file
        wp_enqueue_script( 'sywat-roster-js', $js_url, $deps, $version, true );

        // Localize line-scoped family groups, age divisions, and pricing profiles to window.sywatRosterData
        wp_localize_script( 'sywat-roster-js', 'sywatRosterData', array(
            'tournamentName'         => $tournament_name,
            'defaultProfile'         => $default_prof_key,
            'profiles'               => $t_profiles,
            'existingFamiliesByLine' => is_array( $existing_families_by_line ) ? $existing_families_by_line : array(),
            'divisions'              => $divisions_payload,
        ) );
    }
}
