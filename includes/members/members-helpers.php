<?php
/**
 * Sub-Module: Member & Board Helper Functions
 * Path: includes/members/members-helpers.php
 * Description: Pure data retrieval, internal System ID generation, NFAA ID resolution, DOB age division calculators, NFAA expiration status matchers, and household sub-profile linkage utilities.
 * Compliance: Zero HTML output. Returns standardized data arrays and clean scalar values.
 * Author: SYWAT Archery
 * Version: 3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generate a unique, permanent internal System ID for an archer.
 * This ID is used for internal database indexing and score tracking across the system.
 *
 * @return string Unique internal System ID.
 */
if ( ! function_exists( 'sywat_generate_unique_system_id' ) ) {
    function sywat_generate_unique_system_id() {
        $random_id = (string) mt_rand( 100000, 999999 );
        while ( ! empty( get_users( array( 'meta_key' => 'sywat_system_id', 'meta_value' => $random_id, 'number' => 1 ) ) ) ) {
            $random_id = (string) mt_rand( 100000, 999999 );
        }
        return $random_id;
    }
}

/**
 * Retrieve or generate an archer's permanent internal System ID.
 *
 * @param int $user_id WordPress User ID.
 * @return string Internal System ID.
 */
if ( ! function_exists( 'sywat_get_user_system_id' ) ) {
    function sywat_get_user_system_id( $user_id ) {
        $user_id   = intval( $user_id );
        $system_id = get_user_meta( $user_id, 'sywat_system_id', true );
        
        if ( empty( $system_id ) && $user_id > 0 ) {
            // Check legacy 'sywat_tfaa_number' as fallback or generate new
            $legacy_id = get_user_meta( $user_id, 'sywat_tfaa_number', true );
            $system_id = ! empty( $legacy_id ) ? $legacy_id : sywat_generate_unique_system_id();
            update_user_meta( $user_id, 'sywat_system_id', $system_id );
        }
        
        return $system_id;
    }
}

/**
 * Retrieve an archer's official NFAA ID number for display on rosters and tables.
 *
 * @param int $user_id WordPress User ID.
 * @return string NFAA ID number or 'Unassigned' if not set.
 */
if ( ! function_exists( 'sywat_get_user_nfaa_id' ) ) {
    function sywat_get_user_nfaa_id( $user_id ) {
        $user_id = intval( $user_id );
        $nfaa_id = get_user_meta( $user_id, 'sywat_nfaa_number', true );
        
        if ( empty( $nfaa_id ) ) {
            // Legacy fallback check
            $nfaa_id = get_user_meta( $user_id, 'sywat_tfaa_number', true );
        }
        
        return ! empty( $nfaa_id ) ? $nfaa_id : 'Unassigned';
    }
}

/**
 * Retrieve registered TFAA members matching custom system roles and administrators.
 *
 * @return array List of WP_User objects.
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
 *
 * @return array List of WP_User objects with board assignments.
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
 *
 * @param int $user_id WordPress User ID.
 * @return array Associative array of position keys to human-readable titles.
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
 * @param string      $dob_string      Date of birth in YYYY-MM-DD format.
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
 * @return array Status payload with 'status', 'expiration', 'raw_date', 'badge_class', and 'is_active'.
 */
if ( ! function_exists( 'sywat_get_member_nfaa_status' ) ) {
    function sywat_get_member_nfaa_status( $user_id ) {
        $expiration = get_user_meta( $user_id, 'sywat_nfaa_expiration', true );
        
        if ( empty( $expiration ) ) {
            // Fallback for legacy 'sywat_is_nfaa_member' meta flag
            $legacy_flag = get_user_meta( $user_id, 'sywat_is_nfaa_member', true );
            $is_active   = ( $legacy_flag === 'yes' );
            return array(
                'status'      => 'Expired',
                'expiration'  => '—',
                'raw_date'    => '',
                'badge_class' => 'sywat-badge-muted',
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

/**
 * Promote a dependent family member to a standalone Primary Account Holder.
 * Preserves their permanent internal System ID and all historical tournament score records.
 *
 * @param int    $dependent_user_id WordPress User ID of the dependent to promote.
 * @param string $new_email         Unique email address for their new standalone account.
 * @param string $new_password      Initial password for their new standalone account.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
if ( ! function_exists( 'sywat_promote_dependent_to_primary' ) ) {
    function sywat_promote_dependent_to_primary( $dependent_user_id, $new_email, $new_password ) {
        $dependent_user_id = intval( $dependent_user_id );
        $user              = get_userdata( $dependent_user_id );

        if ( ! $user ) {
            return new WP_Error( 'invalid_user', 'Member account not found.' );
        }

        if ( email_exists( $new_email ) && strtolower( $user->user_email ) !== strtolower( $new_email ) ) {
            return new WP_Error( 'email_exists', 'This email address is already registered to another account.' );
        }

        // 1. Update core WP User details
        $update_data = array(
            'ID'         => $dependent_user_id,
            'user_email' => sanitize_email( $new_email ),
        );

        if ( ! empty( $new_password ) ) {
            $update_data['user_pass'] = $new_password;
        }

        $res = wp_update_user( $update_data );
        if ( is_wp_error( $res ) ) {
            return $res;
        }

        // 2. Remove household primary linkage (making them standalone primary)
        delete_user_meta( $dependent_user_id, 'sywat_primary_user_id' );

        // 3. Ensure internal System ID is preserved (fail-safe check)
        sywat_get_user_system_id( $dependent_user_id );

        return true;
    }
}
