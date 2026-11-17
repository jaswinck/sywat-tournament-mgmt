<?php
/**
 * Sub-Module: Member Self-Registration Data Access Layer
 * Path: includes/registration/member-registration-data.php
 * Description: Pure database, option, and user-meta retrieval engine for the Member Online Registration portal ([sywat_member_register]). Fetches open tournaments, scoped tournament registrations, raw Head of Household & linked dependent records, and active division records with zero HTML rendering.
 * Compliance: Pure data layer — zero HTML output, zero presentation formatting, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retrieve raw data for all tournaments whose Public Online Registration window is currently open,
 * sorted chronologically and paired with raw lines, closed-line locks, shop metadata, and pricing profiles.
 *
 * @param string $today_ymd Optional 'YYYY-MM-DD' override for deterministic date evaluation.
 * @return array<string, array> Associative map keyed by tournament name.
 */
if ( ! function_exists( 'sywat_member_reg_get_open_tournaments_data' ) ) {
    function sywat_member_reg_get_open_tournaments_data( $today_ymd = '' ) {
        $today = ( $today_ymd !== '' )
            ? trim( (string) $today_ymd )
            : ( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' ) );

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) || empty( $tournaments ) ) {
            return array();
        }

        $all_shops = function_exists( 'sywat_get_shops' )
            ? sywat_get_shops()
            : ( function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : array() );
        if ( ! is_array( $all_shops ) ) {
            $all_shops = array();
        }

        $open_raw = array();
        foreach ( $tournaments as $t_name => $t_data ) {
            if ( ! is_array( $t_data ) ) {
                continue;
            }

            $pub_status = function_exists( 'sywat_get_public_registration_status' )
                ? sywat_get_public_registration_status( $t_data, $today )
                : array( 'status' => 'closed', 'is_open' => false );

            if ( empty( $pub_status['is_open'] ) || $pub_status['status'] !== 'open' ) {
                continue;
            }

            $open_raw[ $t_name ] = $t_data;
        }

        if ( empty( $open_raw ) ) {
            return array();
        }

        if ( function_exists( 'sywat_sort_tournaments_chronologically' ) ) {
            sywat_sort_tournaments_chronologically( $open_raw, $all_shops );
        }

        $seasons = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : get_option( 'sywat_seasons_list', array() );
        if ( ! is_array( $seasons ) ) {
            $seasons = array();
        }

        $bundle = array();
        foreach ( $open_raw as $t_name => $t_data ) {
            $t_slug      = sanitize_title( $t_name );
            $s_date      = ! empty( $t_data['start_date'] ) ? trim( (string) $t_data['start_date'] ) : '';
            $e_date      = ! empty( $t_data['end_date'] ) ? trim( (string) $t_data['end_date'] ) : $s_date;
            $shop_id     = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : '';
            $shop_name   = function_exists( 'sywat_get_tournament_shop_name' )
                ? sywat_get_tournament_shop_name( $t_data, $t_name, $all_shops )
                : ( ! empty( $t_data['shop_name'] ) ? (string) $t_data['shop_name'] : '' );
            $season_slug = function_exists( 'sywat_get_tournament_season_slug' )
                ? sywat_get_tournament_season_slug( $t_data )
                : '';

            $lines = function_exists( 'sywat_get_tournament_lines' )
                ? sywat_get_tournament_lines( $t_data )
                : ( isset( $t_data['lines'] ) && is_array( $t_data['lines'] ) ? $t_data['lines'] : array() );

            $closed_raw   = get_option( 'sywat_closed_lines_' . $t_slug, array() );
            $closed_lines = is_array( $closed_raw ) ? array_map( 'trim', array_map( 'strval', $closed_raw ) ) : array();

            $profiles = function_exists( 'sywat_get_tournament_pricing_profiles' )
                ? sywat_get_tournament_pricing_profiles( $t_data, $tournaments, $seasons )
                : array();

            $bundle[ $t_name ] = array(
                'name'             => $t_name,
                'slug'             => $t_slug,
                'raw'              => $t_data,
                'start_date'       => $s_date,
                'end_date'         => $e_date,
                'shop_id'          => (string) $shop_id,
                'shop_name'        => (string) $shop_name,
                'season_slug'      => (string) $season_slug,
                'event_type'       => ! empty( $t_data['event_type'] ) ? (string) $t_data['event_type'] : 'SYWAT',
                'lines'            => $lines,
                'closed_lines'     => $closed_lines,
                'pricing_profiles' => $profiles,
            );
        }

        return $bundle;
    }
}

/**
 * Retrieve all persisted registration records for a single tournament via a scoped options query.
 *
 * @param string $tourn_name Tournament title.
 * @return array<int, array> List of registration records for the tournament.
 */
if ( ! function_exists( 'sywat_member_reg_get_tournament_registrations' ) ) {
    function sywat_member_reg_get_tournament_registrations( $tourn_name ) {
        global $wpdb;

        $t_slug = sanitize_title( (string) $tourn_name );
        if ( $t_slug === '' ) {
            return array();
        }

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'sywat_t_' . $t_slug . '_' ) . '%'
        ) );

        $registrations = array();
        if ( is_array( $results ) ) {
            foreach ( $results as $row ) {
                $reg_data = maybe_unserialize( $row->option_value );
                if ( is_array( $reg_data ) && isset( $reg_data['id'] ) ) {
                    $registrations[] = $reg_data;
                }
            }
        }

        return $registrations;
    }
}

/**
 * Fetch raw Head of Household and linked dependent records for a logged-in WordPress user.
 *
 * @param int $user_id WordPress User ID.
 * @return array{head: array|null, dependents: array<int, array>}
 */
if ( ! function_exists( 'sywat_member_reg_get_household_raw_data' ) ) {
    function sywat_member_reg_get_household_raw_data( $user_id ) {
        $user_id = intval( $user_id );
        if ( $user_id <= 0 ) {
            return array(
                'head'       => null,
                'dependents' => array(),
            );
        }

        $user = get_userdata( $user_id );
        if ( ! $user instanceof WP_User ) {
            return array(
                'head'       => null,
                'dependents' => array(),
            );
        }

        $head_first = trim( (string) ( get_user_meta( $user_id, 'first_name', true ) ?: $user->first_name ) );
        $head_last  = trim( (string) ( get_user_meta( $user_id, 'last_name', true ) ?: $user->last_name ) );
        $head_name  = trim( (string) $user->display_name );
        if ( $head_name === '' ) {
            $head_name = trim( $head_first . ' ' . $head_last );
        }
        if ( $head_name === '' ) {
            $head_name = $user->user_login;
        }

        $head_data = array(
            'user_id'     => $user_id,
            'system_id'   => function_exists( 'sywat_get_user_system_id' ) ? (string) sywat_get_user_system_id( $user_id ) : 'WP-' . $user_id,
            'nfaa_number' => function_exists( 'sywat_get_user_nfaa_id' ) ? (string) sywat_get_user_nfaa_id( $user_id ) : '',
            'first_name'  => $head_first,
            'last_name'   => $head_last,
            'name'        => $head_name,
            'email'       => trim( (string) $user->user_email ),
            'phone'       => trim( (string) get_user_meta( $user_id, 'sywat_phone', true ) ),
            'dob'         => trim( (string) get_user_meta( $user_id, 'sywat_dob', true ) ),
            'gender'      => trim( (string) get_user_meta( $user_id, 'sywat_gender', true ) ),
        );

        $dependents = function_exists( 'sywat_get_linked_household_members' )
            ? sywat_get_linked_household_members( $user_id )
            : array();

        return array(
            'head'       => $head_data,
            'dependents' => is_array( $dependents ) ? $dependents : array(),
        );
    }
}

/**
 * Retrieve raw active NFAA age division records from database configuration.
 *
 * @return array<int, array> List of active division records.
 */
if ( ! function_exists( 'sywat_member_reg_get_division_records_raw' ) ) {
    function sywat_member_reg_get_division_records_raw() {
        if ( ! function_exists( 'sywat_get_division_records' ) ) {
            return array();
        }
        $records = sywat_get_division_records( false );
        return is_array( $records ) ? $records : array();
    }
}
