<?php
/**
 * Sub-Module: Member Self-Registration Formatting & Calculation Helpers
 * Path: includes/registration/member-registration-helpers.php
 * Description: Pure formatting and calculation helpers for the Member Online Registration portal ([sywat_member_register]). Resolves tournament-date NFAA age divisions, target distances (20yd vs. Cub), fee rate tiers, formatted household rosters, open tournament display metadata, and live per-line slot availability maps with zero database queries or HTML output.
 * Compliance: Pure helper layer — zero SQL queries, zero get_option/get_user_meta calls, zero HTML output, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolve an archer's NFAA age division label, target distance ('20yd' or 'Cub'),
 * and rate tier ('Adult' or 'Youth') from Date of Birth on a target tournament date.
 *
 * @param string $dob_ymd    Date of birth ('YYYY-MM-DD').
 * @param string $tourn_date Tournament start date ('YYYY-MM-DD').
 * @return array{division: string, distance: string, rate_tier: string}
 */
if ( ! function_exists( 'sywat_member_reg_resolve_age_profile' ) ) {
    function sywat_member_reg_resolve_age_profile( $dob_ymd, $tourn_date = '' ) {
        $raw_div = ( $dob_ymd !== '' && function_exists( 'sywat_get_eligible_age_division' ) )
            ? sywat_get_eligible_age_division( $dob_ymd, $tourn_date !== '' ? $tourn_date : null )
            : 'Adult';

        $norm_div = function_exists( 'sywat_shooting_styles_normalize_division' )
            ? sywat_shooting_styles_normalize_division( $raw_div )
            : trim( (string) $raw_div );

        if ( $norm_div === '' ) {
            $norm_div = 'Adult (18 - 49)';
        }

        $distance = function_exists( 'sywat_shooting_styles_get_division_distance' )
            ? sywat_shooting_styles_get_division_distance( $norm_div )
            : ( stripos( $norm_div, 'Cub' ) !== false ? 'Cub' : '20yd' );

        $rate_tier = ( $distance === 'Cub' )
            ? 'Youth'
            : ( function_exists( 'sywat_shooting_styles_get_division_rate_tier' )
                ? sywat_shooting_styles_get_division_rate_tier( $norm_div )
                : ( stripos( $norm_div, 'Youth' ) !== false ? 'Youth' : 'Adult' ) );

        return array(
            'division'  => $norm_div,
            'distance'  => $distance,
            'rate_tier' => $rate_tier,
        );
    }
}

/**
 * Format raw Head of Household and linked dependent records into a unified roster map
 * keyed by 'head_{user_id}' and 'dep_{system_id}' with tournament-date age profiles.
 *
 * @param array  $raw_household Payload returned by sywat_member_reg_get_household_raw_data().
 * @param string $tourn_date    Optional tournament start date ('YYYY-MM-DD').
 * @return array<string, array> Formatted household roster map.
 */
if ( ! function_exists( 'sywat_member_reg_format_household_roster' ) ) {
    function sywat_member_reg_format_household_roster( $raw_household, $tourn_date = '' ) {
        if ( ! is_array( $raw_household ) || empty( $raw_household['head'] ) || ! is_array( $raw_household['head'] ) ) {
            return array();
        }

        $head      = $raw_household['head'];
        $head_uid  = isset( $head['user_id'] ) ? intval( $head['user_id'] ) : 0;
        $head_name = isset( $head['name'] ) ? trim( (string) $head['name'] ) : '';
        if ( $head_uid <= 0 || $head_name === '' ) {
            return array();
        }

        $head_email  = isset( $head['email'] ) ? trim( (string) $head['email'] ) : '';
        $head_phone  = isset( $head['phone'] ) ? trim( (string) $head['phone'] ) : '';
        $head_dob    = isset( $head['dob'] ) ? trim( (string) $head['dob'] ) : '';
        $head_g_raw  = isset( $head['gender'] ) ? strtolower( trim( (string) $head['gender'] ) ) : '';
        $head_gender = ( $head_g_raw === 'male' || $head_g_raw === 'female' ) ? ucfirst( $head_g_raw ) : '';
        $head_nfaa   = ! empty( $head['nfaa_number'] ) ? trim( (string) $head['nfaa_number'] ) : 'Unassigned';
        $head_sys_id = ! empty( $head['system_id'] ) ? trim( (string) $head['system_id'] ) : 'WP-' . $head_uid;

        $head_age  = sywat_member_reg_resolve_age_profile( $head_dob, $tourn_date );
        $head_hist = function_exists( 'sywat_resolve_archer_intake_profile' )
            ? sywat_resolve_archer_intake_profile( $head_name, $tourn_date, array( 'email' => $head_email, 'phone' => $head_phone ) )
            : array();

        $roster   = array();
        $head_key = 'head_' . $head_uid;

        $roster[ $head_key ] = array(
            'member_key'  => $head_key,
            'role_label'  => 'Head of Household',
            'is_head'     => true,
            'user_id'     => $head_uid,
            'system_id'   => $head_sys_id,
            'nfaa_number' => $head_nfaa,
            'name'        => $head_name,
            'email'       => $head_email,
            'phone'       => $head_phone,
            'dob'         => $head_dob,
            'gender'      => $head_gender !== '' ? $head_gender : ( isset( $head_hist['gender'] ) ? $head_hist['gender'] : '' ),
            'style'       => isset( $head_hist['style'] ) ? $head_hist['style'] : '',
            'division'    => $head_age['division'],
            'distance'    => $head_age['distance'],
            'rate_tier'   => $head_age['rate_tier'],
        );

        $dependents = isset( $raw_household['dependents'] ) && is_array( $raw_household['dependents'] )
            ? $raw_household['dependents']
            : array();

        foreach ( $dependents as $idx => $dep ) {
            if ( ! is_array( $dep ) ) {
                continue;
            }
            $d_first = isset( $dep['first_name'] ) ? trim( (string) $dep['first_name'] ) : '';
            $d_last  = isset( $dep['last_name'] ) ? trim( (string) $dep['last_name'] ) : '';
            $d_name  = ! empty( $dep['display_name'] ) ? trim( (string) $dep['display_name'] ) : trim( $d_first . ' ' . $d_last );
            if ( $d_name === '' ) {
                continue;
            }

            $d_sys_id = ! empty( $dep['system_id'] ) ? sanitize_key( (string) $dep['system_id'] ) : 'idx_' . intval( $idx );
            $d_dob    = isset( $dep['dob'] ) ? trim( (string) $dep['dob'] ) : '';
            $d_g_raw  = isset( $dep['gender'] ) ? strtolower( trim( (string) $dep['gender'] ) ) : '';
            $d_gender = ( $d_g_raw === 'male' || $d_g_raw === 'female' ) ? ucfirst( $d_g_raw ) : '';
            $d_email  = ! empty( $dep['email'] ) ? trim( (string) $dep['email'] ) : $head_email;
            $d_phone  = ! empty( $dep['phone'] ) ? trim( (string) $dep['phone'] ) : $head_phone;
            $d_nfaa   = ! empty( $dep['nfaa_number'] ) ? trim( (string) $dep['nfaa_number'] ) : 'Unassigned';

            $d_age  = sywat_member_reg_resolve_age_profile( $d_dob, $tourn_date );
            $d_hist = function_exists( 'sywat_resolve_archer_intake_profile' )
                ? sywat_resolve_archer_intake_profile( $d_name, $tourn_date, array( 'email' => $d_email, 'phone' => $d_phone ) )
                : array();

            $dep_key = 'dep_' . $d_sys_id;
            $roster[ $dep_key ] = array(
                'member_key'  => $dep_key,
                'role_label'  => 'Family Member',
                'is_head'     => false,
                'user_id'     => isset( $dep['wp_user_id'] ) ? intval( $dep['wp_user_id'] ) : 0,
                'system_id'   => $d_sys_id,
                'nfaa_number' => $d_nfaa,
                'name'        => $d_name,
                'email'       => $d_email,
                'phone'       => $d_phone,
                'dob'         => $d_dob,
                'gender'      => $d_gender !== '' ? $d_gender : ( isset( $d_hist['gender'] ) ? $d_hist['gender'] : '' ),
                'style'       => isset( $d_hist['style'] ) ? $d_hist['style'] : '',
                'division'    => $d_age['division'],
                'distance'    => $d_age['distance'],
                'rate_tier'   => $d_age['rate_tier'],
            );
        }

        return $roster;
    }
}

/**
 * Format raw open tournament bundles with display dates and normalized external registration links.
 *
 * @param array<string, array> $raw_open_tournaments Bundle returned by sywat_member_reg_get_open_tournaments_data().
 * @return array<string, array> Formatted open tournament map.
 */
if ( ! function_exists( 'sywat_member_reg_format_open_tournaments' ) ) {
    function sywat_member_reg_format_open_tournaments( $raw_open_tournaments ) {
        if ( ! is_array( $raw_open_tournaments ) || empty( $raw_open_tournaments ) ) {
            return array();
        }

        $formatted = array();
        foreach ( $raw_open_tournaments as $t_name => $t_item ) {
            if ( ! is_array( $t_item ) ) {
                continue;
            }
            $s_date     = isset( $t_item['start_date'] ) ? (string) $t_item['start_date'] : '';
            $e_date     = isset( $t_item['end_date'] ) ? (string) $t_item['end_date'] : $s_date;
            $disp_dates = function_exists( 'sywat_format_tournament_dates' )
                ? sywat_format_tournament_dates( $s_date, $e_date )
                : $s_date;

            $t_raw     = isset( $t_item['raw'] ) && is_array( $t_item['raw'] ) ? $t_item['raw'] : array();
            $reg_links = function_exists( 'sywat_get_shop_registration_links' )
                ? sywat_get_shop_registration_links( $t_raw )
                : ( isset( $t_raw['registration_links'] ) && is_array( $t_raw['registration_links'] ) ? $t_raw['registration_links'] : array() );

            $t_item['display_dates'] = $disp_dates;
            $t_item['reg_links']     = $reg_links;
            $formatted[ $t_name ]    = $t_item;
        }

        return $formatted;
    }
}

/**
 * Build per-line 20yd and Cub slot availability metrics for a single tournament.
 *
 * @param string $tourn_name    Tournament title.
 * @param array  $t_lines       Shooting lines array.
 * @param array  $closed_lines  Closed line index/name locks.
 * @param array  $registrations Existing registrations for the tournament.
 * @return array<string|int, array> Per-line availability map.
 */
if ( ! function_exists( 'sywat_member_reg_build_line_availability' ) ) {
    function sywat_member_reg_build_line_availability( $tourn_name, $t_lines, $closed_lines, $registrations ) {
        if ( ! is_array( $t_lines ) || empty( $t_lines ) ) {
            return array();
        }

        $closed_list  = is_array( $closed_lines ) ? array_map( 'trim', array_map( 'strval', $closed_lines ) ) : array();
        $availability = array();

        foreach ( $t_lines as $l_idx => $l_data ) {
            $label = function_exists( 'sywat_reg_resolve_line_label' )
                ? sywat_reg_resolve_line_label( $l_data, $l_idx )
                : ( is_array( $l_data ) && ! empty( $l_data['name'] ) ? $l_data['name'] : 'Line ' . ( intval( $l_idx ) + 1 ) );

            $raw_line_name = is_array( $l_data ) && ! empty( $l_data['name'] ) ? trim( (string) $l_data['name'] ) : '';
            $is_closed     = in_array( (string) $l_idx, $closed_list, true )
                          || ( $raw_line_name !== '' && in_array( $raw_line_name, $closed_list, true ) );

            $c_20  = function_exists( 'sywat_count_registered' ) ? sywat_count_registered( $tourn_name, $l_idx, '20yd', $registrations ) : 0;
            $m_20  = function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $l_data, '20yd' ) : 20;
            $c_cub = function_exists( 'sywat_count_registered' ) ? sywat_count_registered( $tourn_name, $l_idx, 'Cub', $registrations ) : 0;
            $m_cub = function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $l_data, 'Cub' ) : 5;

            $availability[ $l_idx ] = array(
                'label'     => $label,
                'c_20'      => $c_20,
                'm_20'      => $m_20,
                'rem_20'    => $m_20 > 0 ? max( 0, $m_20 - $c_20 ) : 0,
                'c_cub'     => $c_cub,
                'm_cub'     => $m_cub,
                'rem_cub'   => $m_cub > 0 ? max( 0, $m_cub - $c_cub ) : 0,
                'is_closed' => $is_closed,
            );
        }

        return $availability;
    }
}
