<?php
/**
 * Sub-Module: Member Self-Registration Formatting & Calculation Helpers
 * Path: includes/registration/member-registration-helpers.php
 * Description: Pure formatting and calculation helpers for the Member Online Registration portal ([sywat_member_register]). Resolves tournament-date NFAA age divisions, target distances (20yd vs. Cub), fee rate tiers, formatted household rosters with hoisted "LastName, FirstName" display_name labels while preserving canonical "FirstName LastName" name values, open tournament display metadata, live per-line slot availability maps, per-archer/per-line existing registration status maps, and complete view payloads with zero database queries or HTML output.
 * Compliance: Pure helper layer — zero SQL queries, zero get_option/get_user_meta calls, zero HTML output, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.3.0
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
 * keyed by 'head_{user_id}' and 'dep_{system_id}' with tournament-date age profiles,
 * attaching 'display_name' ("LastName, FirstName") while preserving canonical 'name' ("FirstName LastName").
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

        $has_lf_formatter = function_exists( 'sywat_format_name_last_first' );
        $head_disp_name   = $has_lf_formatter ? sywat_format_name_last_first( $head_name ) : $head_name;

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
            'member_key'   => $head_key,
            'role_label'   => 'Head of Household',
            'is_head'      => true,
            'user_id'      => $head_uid,
            'system_id'    => $head_sys_id,
            'nfaa_number'  => $head_nfaa,
            'name'         => $head_name,
            'display_name' => $head_disp_name,
            'email'        => $head_email,
            'phone'        => $head_phone,
            'dob'          => $head_dob,
            'gender'       => $head_gender !== '' ? $head_gender : ( isset( $head_hist['gender'] ) ? $head_hist['gender'] : '' ),
            'style'        => isset( $head_hist['style'] ) ? $head_hist['style'] : '',
            'division'     => $head_age['division'],
            'distance'     => $head_age['distance'],
            'rate_tier'    => $head_age['rate_tier'],
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

            $d_disp_name = $has_lf_formatter ? sywat_format_name_last_first( $d_name ) : $d_name;
            $d_sys_id    = ! empty( $dep['system_id'] ) ? sanitize_key( (string) $dep['system_id'] ) : 'idx_' . intval( $idx );
            $d_dob       = isset( $dep['dob'] ) ? trim( (string) $dep['dob'] ) : '';
            $d_g_raw     = isset( $dep['gender'] ) ? strtolower( trim( (string) $dep['gender'] ) ) : '';
            $d_gender    = ( $d_g_raw === 'male' || $d_g_raw === 'female' ) ? ucfirst( $d_g_raw ) : '';
            $d_email     = ! empty( $dep['email'] ) ? trim( (string) $dep['email'] ) : $head_email;
            $d_phone     = ! empty( $dep['phone'] ) ? trim( (string) $dep['phone'] ) : $head_phone;
            $d_nfaa      = ! empty( $dep['nfaa_number'] ) ? trim( (string) $dep['nfaa_number'] ) : 'Unassigned';

            $d_age  = sywat_member_reg_resolve_age_profile( $d_dob, $tourn_date );
            $d_hist = function_exists( 'sywat_resolve_archer_intake_profile' )
                ? sywat_resolve_archer_intake_profile( $d_name, $tourn_date, array( 'email' => $d_email, 'phone' => $d_phone ) )
                : array();

            $dep_key = 'dep_' . $d_sys_id;
            $roster[ $dep_key ] = array(
                'member_key'   => $dep_key,
                'role_label'   => 'Family Member',
                'is_head'      => false,
                'user_id'      => isset( $dep['wp_user_id'] ) ? intval( $dep['wp_user_id'] ) : 0,
                'system_id'    => $d_sys_id,
                'nfaa_number'  => $d_nfaa,
                'name'         => $d_name,
                'display_name' => $d_disp_name,
                'email'        => $d_email,
                'phone'        => $d_phone,
                'dob'          => $d_dob,
                'gender'       => $d_gender !== '' ? $d_gender : ( isset( $d_hist['gender'] ) ? $d_hist['gender'] : '' ),
                'style'        => isset( $d_hist['style'] ) ? $d_hist['style'] : '',
                'division'     => $d_age['division'],
                'distance'     => $d_age['distance'],
                'rate_tier'    => $d_age['rate_tier'],
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

/**
 * Resolve the active tournament key from a map of open tournaments using exact or
 * case-insensitive matching, falling back to the nearest open tournament.
 *
 * @param array<string, array> $open_tournaments Formatted open tournaments map.
 * @param string               $requested_tourn  Optional requested tournament name.
 * @return string                                Resolved tournament key or empty string.
 */
if ( ! function_exists( 'sywat_member_reg_resolve_selected_tournament' ) ) {
    function sywat_member_reg_resolve_selected_tournament( $open_tournaments, $requested_tourn = '' ) {
        if ( ! is_array( $open_tournaments ) || empty( $open_tournaments ) ) {
            return '';
        }

        $requested = trim( (string) $requested_tourn );
        if ( $requested !== '' ) {
            if ( isset( $open_tournaments[ $requested ] ) ) {
                return $requested;
            }
            foreach ( array_keys( $open_tournaments ) as $open_key ) {
                if ( strcasecmp( (string) $open_key, $requested ) === 0 ) {
                    return (string) $open_key;
                }
            }
        }

        reset( $open_tournaments );
        return (string) key( $open_tournaments );
    }
}

/**
 * Build an O(1) per-archer, per-shooting-line lookup map of existing non-cancelled registrations
 * keyed by normalized shooter name, supporting both line_index and normalized line_name lookups.
 *
 * @param array<int, array> $registrations Scoped tournament registration records.
 * @return array<string, array{
 *     by_line_idx: array<string, array{status: string, line_index: string, line_name: string}>,
 *     by_line_name: array<string, array{status: string, line_index: string, line_name: string}>,
 *     entries: array<int, array{status: string, line_index: string, line_name: string}>
 * }>
 */
if ( ! function_exists( 'sywat_member_reg_build_existing_status_map' ) ) {
    function sywat_member_reg_build_existing_status_map( $registrations ) {
        if ( ! is_array( $registrations ) || empty( $registrations ) ) {
            return array();
        }

        $status_map = array();
        foreach ( $registrations as $reg ) {
            if ( ! is_array( $reg ) || empty( $reg['name'] ) ) {
                continue;
            }
            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            $norm_name = strtolower( trim( (string) $reg['name'] ) );
            if ( $norm_name === '' ) {
                continue;
            }

            if ( ! isset( $status_map[ $norm_name ] ) ) {
                $status_map[ $norm_name ] = array(
                    'by_line_idx'  => array(),
                    'by_line_name' => array(),
                    'entries'      => array(),
                );
            }

            $line_idx       = isset( $reg['line_index'] ) ? trim( (string) $reg['line_index'] ) : '';
            $line_name      = isset( $reg['line_name'] ) ? trim( (string) $reg['line_name'] ) : '';
            $norm_line_name = strtolower( $line_name );

            $entry = array(
                'status'     => $status !== '' ? $status : 'Pre-Reg',
                'line_index' => $line_idx,
                'line_name'  => $line_name,
            );

            if ( $line_idx !== '' && ! isset( $status_map[ $norm_name ]['by_line_idx'][ $line_idx ] ) ) {
                $status_map[ $norm_name ]['by_line_idx'][ $line_idx ] = $entry;
            }
            if ( $norm_line_name !== '' && ! isset( $status_map[ $norm_name ]['by_line_name'][ $norm_line_name ] ) ) {
                $status_map[ $norm_name ]['by_line_name'][ $norm_line_name ] = $entry;
            }
            $status_map[ $norm_name ]['entries'][] = $entry;
        }

        return $status_map;
    }
}

/**
 * Format raw active division records into dropdown option items.
 *
 * @param array<int, array> $div_records Raw active division records.
 * @return array<int, array{label: string, distance: string}>
 */
if ( ! function_exists( 'sywat_member_reg_format_division_options' ) ) {
    function sywat_member_reg_format_division_options( $div_records ) {
        if ( ! is_array( $div_records ) || empty( $div_records ) ) {
            return array();
        }

        $options = array();
        foreach ( $div_records as $rec ) {
            if ( is_array( $rec ) && ! empty( $rec['label'] ) ) {
                $options[] = array(
                    'label'    => (string) $rec['label'],
                    'distance' => isset( $rec['distance'] ) ? (string) $rec['distance'] : '20yd',
                );
            }
        }

        return $options;
    }
}

/**
 * Assemble the complete presentation payload ($view_data) for view-member-registration.php
 * from pre-fetched raw data arrays without executing any database or option queries.
 *
 * @param array $raw_context {
 *     @type bool        $is_logged_in
 *     @type int         $user_id
 *     @type string      $login_url
 *     @type string      $schedule_url
 *     @type string      $profile_url
 *     @type string      $member_reg_url
 *     @type string      $requested_tourn
 *     @type array       $raw_open_tournaments
 *     @type array       $registrations
 *     @type array       $raw_household
 *     @type array       $raw_divisions
 *     @type array|null  $submission_result
 * }
 * @return array Formatted $view_data array for view-member-registration.php.
 */
if ( ! function_exists( 'sywat_member_reg_build_view_data' ) ) {
    function sywat_member_reg_build_view_data( $raw_context ) {
        $is_logged_in    = ! empty( $raw_context['is_logged_in'] );
        $user_id         = isset( $raw_context['user_id'] ) ? intval( $raw_context['user_id'] ) : 0;
        $login_url       = isset( $raw_context['login_url'] ) ? (string) $raw_context['login_url'] : '';
        $schedule_url    = isset( $raw_context['schedule_url'] ) ? (string) $raw_context['schedule_url'] : '';
        $profile_url     = isset( $raw_context['profile_url'] ) ? (string) $raw_context['profile_url'] : '';
        $member_reg_url  = isset( $raw_context['member_reg_url'] ) ? (string) $raw_context['member_reg_url'] : '';
        $requested_tourn = isset( $raw_context['requested_tourn'] ) ? (string) $raw_context['requested_tourn'] : '';
        $raw_open        = isset( $raw_context['raw_open_tournaments'] ) && is_array( $raw_context['raw_open_tournaments'] ) ? $raw_context['raw_open_tournaments'] : array();
        $registrations   = isset( $raw_context['registrations'] ) && is_array( $raw_context['registrations'] ) ? $raw_context['registrations'] : array();
        $raw_household   = isset( $raw_context['raw_household'] ) && is_array( $raw_context['raw_household'] ) ? $raw_context['raw_household'] : array();
        $raw_divisions   = isset( $raw_context['raw_divisions'] ) && is_array( $raw_context['raw_divisions'] ) ? $raw_context['raw_divisions'] : array();
        $submission_res  = isset( $raw_context['submission_result'] ) && is_array( $raw_context['submission_result'] ) ? $raw_context['submission_result'] : null;

        $open_tournaments    = sywat_member_reg_format_open_tournaments( $raw_open );
        $selected_tournament = sywat_member_reg_resolve_selected_tournament( $open_tournaments, $requested_tourn );

        $form_action_url = ( $selected_tournament !== '' && $member_reg_url !== '' )
            ? add_query_arg( 'tournament', rawurlencode( $selected_tournament ), $member_reg_url )
            : $member_reg_url;

        $line_availability    = array();
        $household            = array();
        $existing_member_regs = array();

        if ( $is_logged_in && $selected_tournament !== '' && isset( $open_tournaments[ $selected_tournament ] ) ) {
            $active_event         = $open_tournaments[ $selected_tournament ];
            $line_availability    = sywat_member_reg_build_line_availability(
                $selected_tournament,
                isset( $active_event['lines'] ) ? $active_event['lines'] : array(),
                isset( $active_event['closed_lines'] ) ? $active_event['closed_lines'] : array(),
                $registrations
            );
            $household            = sywat_member_reg_format_household_roster(
                $raw_household,
                isset( $active_event['start_date'] ) ? $active_event['start_date'] : ''
            );
            $existing_member_regs = sywat_member_reg_build_existing_status_map( $registrations );
        }

        return array(
            'is_logged_in'         => $is_logged_in,
            'user_id'              => $user_id,
            'login_url'            => $login_url,
            'schedule_url'         => $schedule_url,
            'profile_url'          => $profile_url,
            'form_action_url'      => $form_action_url,
            'open_tournaments'     => $open_tournaments,
            'selected_tournament'  => $selected_tournament,
            'line_availability'    => $line_availability,
            'household'            => $household,
            'existing_member_regs' => $existing_member_regs,
            'division_options'     => sywat_member_reg_format_division_options( $raw_divisions ),
            'submission_result'    => $submission_res,
        );
    }
}
