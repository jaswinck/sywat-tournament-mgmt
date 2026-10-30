<?php
/**
 * Sub-Module: Member Helper Utilities & Data Querying
 * Path: includes/members/members-helpers.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sequential TFAA ID Generator (e.g., TFAA-10001)
 */
if ( ! function_exists( 'sywat_generate_unique_tfaa_id' ) ) {
    function sywat_generate_unique_tfaa_id() {
        $last_seq = intval( get_option( 'sywat_last_tfaa_id_seq', 10000 ) );
        $new_seq  = $last_seq + 1;
        update_option( 'sywat_last_tfaa_id_seq', $new_seq );
        return 'TFAA-' . $new_seq;
    }
}

/**
 * Retrieve all registered TFAA members sorted alphabetically by display name.
 */
if ( ! function_exists( 'sywat_get_all_tfaa_members' ) ) {
    function sywat_get_all_tfaa_members() {
        return get_users( array(
            'role__in' => array( 'tfaa_member', 'tfaa_tournament_director', 'tfaa_field_governor', 'tfaa_admin', 'tfaa_webmaster' ),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ) );
    }
}
