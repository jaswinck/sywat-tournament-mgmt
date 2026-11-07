<?php
/**
 * Sub-Module: Member & Board Helper Functions
 * Path: includes/members/members-helpers.php
 * Description: Data getters, DOB age division calculators, NFAA expiration status matchers, and household linkage utilities.
 * Author: SYWAT Archery
 * Version: 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Retrieve registered TFAA members matching custom system roles and administrators.
 */
if ( ! function_exists( 'sywat_get_all_tfaa_members' ) ) {
    function sywat_get_all_tfaa_members() {
        $allowed_roles = function_exists( 'sywat_get_custom_roles_slugs' ) 
            ? sywat_get_custom_roles_slugs() 
            : array(
                'tfaa_member', 'tfaa_president', 'tfaa_vice_president', 'tfaa_treasurer', 
                'tfaa_secretary', 'tfaa_tournament_director', 'tfaa_social_media_director', 
                'tfaa_webmaster', 'tfaa_field_governor', 'sywat_shop_manager', 
                'sywat_shop_user', 'administrator'
            );

        if ( is_array( $allowed_roles ) && ! in_array( 'administrator', $allowed_roles, true ) ) {
            $allowed_roles[] = 'administrator';
        }

        return get_users( array(
            'role__in' => $allowed_roles,
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ) );
    }
}

/**
 * Retrieve all assigned TFAA Board Members.
 */
if ( ! function_exists( 'sywat_get_all_board_members' ) ) {
    function sywat_get_all_board_members() {
        return get_users( array(
            'meta_key'     => 'sywat_board_positions',
            'meta_compare' => 'EXISTS',
            'orderby'      => 'display_name',
            'order'        => 'ASC',
        ) );
    }
}

/**
 * Format user board positions into readable labels.
 */
if ( ! function_exists( 'sywat_get_user_board_position_labels' ) ) {
    function sywat_get_user_board_position_labels( $user_id ) {
        $positions = get_user_meta( $user_id, 'sywat_board_positions', true );
        if ( ! is_array( $positions ) || empty( $positions ) ) {
            return array();
        }

        $all_positions = function_exists( 'sywat_get_tfaa_board_positions_list' ) ? sywat_get_tfaa_board_positions_list() : array();
        $labels = array();

        foreach ( $positions as $pos_key ) {
            if ( isset( $all_positions[$pos_key] ) ) {
                $labels[$pos_key] = $all_positions[$pos_key];
            }
        }

        return $labels;
    }
}

/**
 * Calculate NFAA Age Division based on exact Date of Birth and Tournament Date.
 * Rule: Archer ages up on their exact birthday.
 *
 * @param string      $dob_string      Date of birth in YYYY-MM-DD or readable format.
 * @param string|null $tournament_date Optional comparison date (defaults to today).
 * @return string Eligible NFAA Age Division name.
 */
if ( ! function_exists( 'sywat_get_eligible_age_division' ) ) {
    function sywat_get_eligible_age_division( $dob_string, $tournament_date = null ) {
        if ( empty( $dob_string ) ) {
            return 'Adult';
        }

        try {
            $dob  = new DateTime( $dob_string );
            $comp = $tournament_date ? new DateTime( $tournament_date ) : new DateTime();
            $age  = $dob->diff( $comp )->y;

            if ( $age < 12 )  return 'Cub';          // 0 - 11
            if ( $age < 15 )  return 'Youth';        // 12 - 14 (Ages up on 15th birthday)
            if ( $age < 18 )  return 'Young Adult';  // 15 - 17
            if ( $age < 50 )  return 'Adult';        // 18 - 49
            if ( $age < 60 )  return 'Senior';       // 50 - 59
            if ( $age < 70 )  return 'Silver Senior';// 60 - 69
            return 'Master Senior';                  // 70+
        } catch ( Exception $e ) {
            return 'Adult';
        }
    }
}

/**
 * Evaluate NFAA Membership Expiration Status for a given user.
 *
 * @param int $user_id WordPress User ID.
 * @return array Status payload with 'status', 'expiration', and CSS 'badge_class'.
 */
if ( ! function_exists( 'sywat_get_member_nfaa_status' ) ) {
    function sywat_get_member_nfaa_status( $user_id ) {
        $expiration = get_user_meta( $user_id, 'sywat_nfaa_expiration', true );
        
        if ( empty( $expiration ) ) {
            // Fallback for legacy 'sywat_is_nfaa_member' meta flag
            $legacy_flag = get_user_meta( $user_id, 'sywat_is_nfaa_member', true );
            $is_active   = ( $legacy_flag === 'yes' );
            return array(
                'status'      => $is_active ? 'Active' : 'Expired',
                'expiration'  => '—',
                'badge_class' => $is_active ? 'sywat-badge-open' : 'sywat-badge-muted',
                'is_active'   => $is_active,
            );
        }

        $exp_timestamp   = strtotime( $expiration );
        $today_timestamp = strtotime( date( 'Y-m-d' ) );
        $is_active       = ( $exp_timestamp >= $today_timestamp );

        return array(
            'status'      => $is_active ? 'Active' : 'Expired',
            'expiration'  => date( 'M j, Y', $exp_timestamp ),
            'raw_date'    => $expiration,
            'badge_class' => $is_active ? 'sywat-badge-open' : 'sywat-badge-muted',
            'is_active'   => $is_active,
        );
    }
}

/**
 * Retrieve all linked dependent/family members under a Primary Account.
 *
 * @param int $primary_user_id WordPress User ID of Primary Account Holder.
 * @return array Array of WP_User objects linked to this Primary ID.
 */
if ( ! function_exists( 'sywat_get_linked_household_members' ) ) {
    function sywat_get_linked_household_members( $primary_user_id ) {
        $primary_user_id = intval( $primary_user_id );
        if ( $primary_user_id <= 0 ) {
            return array();
        }

        return get_users( array(
            'meta_key'   => 'sywat_primary_user_id',
            'meta_value' => $primary_user_id,
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ) );
    }
}

/**
 * Format structured address fields for a user account.
 *
 * @param int $user_id WordPress User ID.
 * @return array Sanitized address components.
 */
if ( ! function_exists( 'sywat_get_user_address' ) ) {
    function sywat_get_user_address( $user_id ) {
        return array(
            'address_1' => get_user_meta( $user_id, 'sywat_address_1', true ),
            'address_2' => get_user_meta( $user_id, 'sywat_address_2', true ),
            'city'      => get_user_meta( $user_id, 'sywat_city', true ),
            'state'     => get_user_meta( $user_id, 'sywat_state', true ),
            'zip'       => get_user_meta( $user_id, 'sywat_zip', true ),
        );
    }
}
