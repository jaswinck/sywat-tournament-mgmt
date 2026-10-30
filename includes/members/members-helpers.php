<?php
/**
 * Sub-Module: Member & Board Helper Functions
 * Path: includes/members/members-helpers.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Retrieve all registered TFAA members.
 */
if ( ! function_exists( 'sywat_get_all_tfaa_members' ) ) {
    function sywat_get_all_tfaa_members() {
        return get_users( array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
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
