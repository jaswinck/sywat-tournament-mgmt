<?php
/**
 * Module: Check-In Desk Portal Controller
 * Path: includes/checkin/class-sywat-checkin.php
 * Description: Main entry point and shortcode definition for the Check-In Desk portal, enqueueing the shared Shooting Styles & Division Rule engine, Member Directory shooter profiles, 18-month TFAA historical shooter datasets, and active tournament pricing/promoter data.
 * Shortcode: [sywat_desk]
 * Author: SYWAT Archery
 * Version: 7.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load Check-In Desk Sub-Modules
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-data.php';
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-actions.php';
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-views.php';
require_once SYWAT_PLUGIN_DIR . 'includes/checkin/checkin-modals.php';

if ( ! shortcode_exists( 'sywat_desk' ) ) {
    add_shortcode( 'sywat_desk', 'render_sywat_desk_app' );
}

/**
 * Shortcode handler rendering the Check-In Desk Portal.
 *
 * @return string Rendered HTML markup output.
 */
if ( ! function_exists( 'render_sywat_desk_app' ) ) {
    function render_sywat_desk_app() {
        // Central Permissions Check
        if ( function_exists( 'sywat_can_access_desk' ) && ! sywat_can_access_desk() ) {
            return '<div class="sywat-wrap sywat-card-sm"><div class="sywat-alert sywat-alert-danger">Access denied. Management or staff access required.</div></div>';
        }

        ob_start();

        // 1. Run Data Migrations
        sywat_desk_run_migrations();

        // 2. Fetch Data State
        $data = sywat_desk_get_data();

        // 3. Process Form Actions
        $action_notice = sywat_desk_handle_actions( $data );

        // Re-fetch dataset if actions mutated state
        if ( ! empty( $_POST['sywat_action'] ) ) {
            $data = sywat_desk_get_data();
        }

        // 4. Enqueue External JS & Localize Data State
        sywat_desk_enqueue_scripts( $data );

        // 5. Render Interface
        echo '<div class="sywat-wrap">';
        echo $action_notice;
        sywat_desk_render_family_banner( $data['added_family_prompt'] );
        sywat_desk_render_filter_bar( $data );
        sywat_desk_render_capacity_tracker( $data );
        sywat_desk_render_archers_table( $data );
        echo '</div>';

        // 6. Render Modals
        sywat_desk_render_modals( $data );

        return ob_get_clean();
    }
}

/**
 * Enqueues external JS asset and injects localized JSON datasets using Member Directory records,
 * 18-month historical TFAA results, database-configured shooting styles & age divisions,
 * and active tournament pricing/promoter rules.
 *
 * @param array $data Aggregated state dataset.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_enqueue_scripts' ) ) {
    function sywat_desk_enqueue_scripts( $data ) {
        // Ensure shared Shooting Styles & Division Rule controller is enqueued first
        if ( function_exists( 'sywat_enqueue_shooting_styles_scripts' ) ) {
            sywat_enqueue_shooting_styles_scripts();
        }

        // Fetch 18-month rolling window historical shooters
        $js_tfaa_desk_data = function_exists( 'sywat_get_historical_shooters' )
            ? sywat_get_historical_shooters()
            : array();

        // Fetch bulk scalar Member Directory records (cached transient, zero WP_User hydration)
        $bulk_members        = function_exists( 'sywat_get_bulk_member_directory_records' )
            ? sywat_get_bulk_member_directory_records()
            : array();
        $js_member_desk_data = array();

        if ( is_array( $bulk_members ) && ! empty( $bulk_members ) ) {
            $event_date   = ( isset( $data['selected_tournament_data'] ) && is_array( $data['selected_tournament_data'] ) && ! empty( $data['selected_tournament_data']['start_date'] ) )
                ? trim( (string) $data['selected_tournament_data']['start_date'] )
                : '';
            $comp_date    = $event_date !== '' ? $event_date : null;
            $has_dob_div  = function_exists( 'sywat_get_eligible_age_division' );
            $has_norm_div = function_exists( 'sywat_shooting_styles_normalize_division' );

            foreach ( $bulk_members as $m ) {
                if ( ! is_array( $m ) || empty( $m['display_name'] ) ) {
                    continue;
                }
                $m_name = trim( preg_replace( '/[[:space:]]+/', ' ', (string) $m['display_name'] ) );
                if ( $m_name === '' ) {
                    continue;
                }

                $m_gender = '';
                if ( ! empty( $m['gender'] ) && strtolower( (string) $m['gender'] ) !== 'unassigned' ) {
                    $g_raw    = strtolower( trim( (string) $m['gender'] ) );
                    $m_gender = ( strpos( $g_raw, 'f' ) === 0 ) ? 'Female' : ( ( strpos( $g_raw, 'm' ) === 0 ) ? 'Male' : '' );
                }

                $m_div = '';
                if ( ! empty( $m['dob'] ) && $has_dob_div ) {
                    $raw_div = sywat_get_eligible_age_division( $m['dob'], $comp_date );
                    $m_div   = $has_norm_div ? sywat_shooting_styles_normalize_division( $raw_div ) : $raw_div;
                }

                $js_member_desk_data[] = array(
                    'name'      => $m_name,
                    'full_name' => $m_name,
                    'division'  => $m_div,
                    'gender'    => $m_gender,
                    'style'     => '',
                    'phone'     => isset( $m['phone'] ) ? trim( (string) $m['phone'] ) : '',
                    'source'    => 'member_db',
                );
            }
        }

        $handle  = 'sywat-checkin-js';
        $js_file = SYWAT_PLUGIN_DIR . 'assets/js/sywat-checkin.js';
        $version = file_exists( $js_file ) ? (string) filemtime( $js_file ) : ( defined( 'SYWAT_PLUGIN_VERSION' ) ? SYWAT_PLUGIN_VERSION : '7.2.0' );
        $deps    = ( wp_script_is( 'sywat-shooting-styles-js', 'registered' ) || wp_script_is( 'sywat-shooting-styles-js', 'enqueued' ) )
            ? array( 'sywat-shooting-styles-js' )
            : array();

        wp_enqueue_script( $handle, SYWAT_PLUGIN_URL . 'assets/js/sywat-checkin.js', $deps, $version, true );

        $divisions_meta = isset( $data['divisions_meta'] ) && is_array( $data['divisions_meta'] ) ? $data['divisions_meta'] : array();
        $pricing_bundle = array(
            'tournamentName' => isset( $data['selected_tournament'] ) ? $data['selected_tournament'] : '',
            'defaultProfile' => isset( $data['default_pricing_profile'] ) ? $data['default_pricing_profile'] : 'indoor',
            'isMultiRound'   => ! empty( $data['is_multi_round'] ),
            'profiles'       => isset( $data['pricing_profiles'] ) && is_array( $data['pricing_profiles'] ) ? $data['pricing_profiles'] : array(),
            'promoRules'     => isset( $data['promo_rules'] ) && is_array( $data['promo_rules'] ) ? $data['promo_rules'] : array(),
        );

        $inline_js = 'window.sywatHistory = ' . wp_json_encode( $data['history_profiles'] ) . '; ' .
                     'window.tfaaData = ' . wp_json_encode( $data['tfaa_data_bundle'] ) . '; ' .
                     'window.sywatDeskMembers = ' . wp_json_encode( $js_member_desk_data ) . '; ' .
                     'window.sywatDeskTfaaShooters = ' . wp_json_encode( array_values( $js_tfaa_desk_data ) ) . '; ' .
                     'window.existingFamiliesByLine = ' . wp_json_encode( $data['existing_families_by_line'] ) . '; ' .
                     'window.sywatDeskDivisions = ' . wp_json_encode( $divisions_meta ) . '; ' .
                     'window.sywatDeskPricing = ' . wp_json_encode( $pricing_bundle ) . ';';

        wp_add_inline_script( $handle, $inline_js, 'before' );
    }
}
