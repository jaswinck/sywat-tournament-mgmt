<?php
/**
 * Sub-Module: Member & Board Helper Functions
 * Path: includes/members/members-helpers.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Retrieve registered TFAA members matching custom system roles.
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
