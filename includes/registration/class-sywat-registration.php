<?php
/**
 * Module: Archer Registration & Intake Engine
 * Path: includes/registration/class-sywat-registration.php
 * Description: Modular controller shell for staff phone/group intake, FareHarbor CSV parsing, and the public Member Online Registration portal ([sywat_member_register]).
 * Compliance: Lightweight controller entry point — zero HTML output at include time, buffered shortcode rendering, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 6.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load Registration Sub-Modules
require_once __DIR__ . '/registration-actions.php';
require_once __DIR__ . '/registration-import.php';
require_once __DIR__ . '/registration-views.php';
require_once __DIR__ . '/registration-modals.php';
require_once __DIR__ . '/registration-scripts.php';
require_once __DIR__ . '/member-registration-data.php';
require_once __DIR__ . '/member-registration-helpers.php';
require_once __DIR__ . '/member-registration-actions.php';

/**
 * Shortcode Handler: [sywat_member_register]
 * Orchestrates the public Member Online Registration portal by delegating POST handling
 * to member-registration-actions.php, data fetching to member-registration-data.php,
 * payload assembly to member-registration-helpers.php, and markup to view-member-registration.php.
 *
 * @return string Buffered HTML output.
 */
if ( ! function_exists( 'sywat_render_member_registration_portal' ) ) {
    function sywat_render_member_registration_portal() {
        $is_logged_in = is_user_logged_in();
        $user_id      = $is_logged_in ? get_current_user_id() : 0;

        $member_reg_url = function_exists( 'sywat_page_url' ) ? sywat_page_url( 'member_register' ) : '';
        $schedule_url   = function_exists( 'sywat_page_url' ) ? sywat_page_url( 'schedule' ) : '';
        $profile_url    = function_exists( 'sywat_page_url' )
            ? add_query_arg( array( 'tab' => 'members', 'subtab' => 'profile' ), sywat_page_url( 'member_directory' ) )
            : '';

        $requested_tourn = '';
        if ( isset( $_POST['tournament_name'] ) && $_POST['tournament_name'] !== '' ) {
            $requested_tourn = sanitize_text_field( wp_unslash( $_POST['tournament_name'] ) );
        } elseif ( isset( $_GET['tournament'] ) && $_GET['tournament'] !== '' ) {
            $requested_tourn = sanitize_text_field( wp_unslash( $_GET['tournament'] ) );
        }

        $return_target = ( $requested_tourn !== '' && $member_reg_url !== '' )
            ? add_query_arg( 'tournament', rawurlencode( $requested_tourn ), $member_reg_url )
            : $member_reg_url;

        $login_url = function_exists( 'sywat_page_url' )
            ? add_query_arg( 'redirect_to', rawurlencode( $return_target ), sywat_page_url( 'login' ) )
            : wp_login_url( $return_target );

        // Process POST submission prior to fetching updated line occupancy & household statuses
        $submission_result = null;
        if ( $is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_member_reg_submit'] ) ) {
            if ( function_exists( 'sywat_member_reg_handle_submission' ) ) {
                $submission_result = sywat_member_reg_handle_submission( $user_id );
            }
        }

        // Fetch raw datasets from Data Layer
        $raw_open_tournaments = function_exists( 'sywat_member_reg_get_open_tournaments_data' )
            ? sywat_member_reg_get_open_tournaments_data()
            : array();

        $formatted_open      = function_exists( 'sywat_member_reg_format_open_tournaments' )
            ? sywat_member_reg_format_open_tournaments( $raw_open_tournaments )
            : $raw_open_tournaments;
        $selected_tournament = function_exists( 'sywat_member_reg_resolve_selected_tournament' )
            ? sywat_member_reg_resolve_selected_tournament( $formatted_open, $requested_tourn )
            : '';

        $registrations = ( $is_logged_in && $selected_tournament !== '' && function_exists( 'sywat_member_reg_get_tournament_registrations' ) )
            ? sywat_member_reg_get_tournament_registrations( $selected_tournament )
            : array();

        $raw_household = ( $is_logged_in && function_exists( 'sywat_member_reg_get_household_raw_data' ) )
            ? sywat_member_reg_get_household_raw_data( $user_id )
            : array();

        $raw_divisions = function_exists( 'sywat_member_reg_get_division_records_raw' )
            ? sywat_member_reg_get_division_records_raw()
            : array();

        // Assemble presentation view payload via Helper Layer
        $view_data = function_exists( 'sywat_member_reg_build_view_data' )
            ? sywat_member_reg_build_view_data( array(
                'is_logged_in'         => $is_logged_in,
                'user_id'              => $user_id,
                'login_url'            => $login_url,
                'schedule_url'         => $schedule_url,
                'profile_url'          => $profile_url,
                'member_reg_url'       => $member_reg_url,
                'requested_tourn'      => $requested_tourn,
                'raw_open_tournaments' => $raw_open_tournaments,
                'registrations'        => $registrations,
                'raw_household'        => $raw_household,
                'raw_divisions'        => $raw_divisions,
                'submission_result'    => $submission_result,
            ) )
            : array();

        ob_start();
        $view_path = __DIR__ . '/views/view-member-registration.php';
        if ( file_exists( $view_path ) ) {
            include $view_path;
        }
        return ob_get_clean();
    }
}

if ( ! shortcode_exists( 'sywat_member_register' ) ) {
    add_shortcode( 'sywat_member_register', 'sywat_render_member_registration_portal' );
}
