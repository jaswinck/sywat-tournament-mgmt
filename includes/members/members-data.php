<?php
/**
 * Sub-Module: Member & Board Data Retrieval & Calculation Engines
 * Path: includes/members/members-data.php
 * Description: Centralized data layer for the Member Management sub-module. Handles WP_User queries, bulk database pre-fetching, O(1) in-memory hashtable index generation, and pure statistical calculation engines for TFAA membership counts (Total Members, Active Membership, and Expired Memberships).
 * Compliance: Zero HTML layout output. Pure data arrays and scalar return values. Adheres to Dual Data & Helper File Separation Mandate.
 * Author: SYWAT Archery
 * Version: 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_get_all_tfaa_members' ) ) {
    /**
     * Retrieve registered TFAA members matching custom system roles, shop managers, and administrators.
     *
     * @return array List of WP_User objects.
     */
    function sywat_get_all_tfaa_members() {
        $allowed_roles = function_exists( 'sywat_get_custom_roles_slugs' ) 
            ? sywat_get_custom_roles_slugs() 
            : array(
                'tfaa_member', 'tfaa_president', 'tfaa_vice_president', 'tfaa_treasurer', 
                'tfaa_secretary', 'tfaa_tournament_director', 'tfaa_social_media_director', 
                'tfaa_webmaster', 'tfaa_field_governor', 'sywat_shop_manager', 
                'sywat_shop_user', 'administrator'
            );

        if ( is_array( $allowed_roles ) ) {
            $extra_roles = array( 'sywat_shop_manager', 'sywat_shop_user', 'administrator' );
            foreach ( $extra_roles as $er ) {
                if ( ! in_array( $er, $allowed_roles, true ) ) {
                    $allowed_roles[] = $er;
                }
            }
        }

        return get_users( array(
            'role__in' => $allowed_roles,
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ) );
    }
}

if ( ! function_exists( 'sywat_get_all_board_members' ) ) {
    /**
     * Retrieve all assigned TFAA Board Members.
     *
     * @return array List of WP_User objects with board assignments.
     */
    function sywat_get_all_board_members() {
        return get_users( array(
            'meta_key'     => 'sywat_board_positions',
            'meta_compare' => 'EXISTS',
            'orderby'      => 'display_name',
            'order'        => 'ASC',
        ) );
    }
}

if ( ! function_exists( 'sywat_get_tfaa_member_statistics' ) ) {
    /**
     * Statistical calculation engine for Member Directory metrics.
     * Evaluates total, active, and expired tallies strictly for users holding active TFAA member 
     * or officer roles, enforcing O(1) hashtable deduplication so users with multiple roles 
     * are never double-counted. Shop managers and administrators without active membership 
     * roles are correctly excluded from membership tallies.
     *
     * @param array|null $all_members Optional pre-fetched list of WP_User objects to process.
     * @return array Associative array containing 'total', 'active', and 'expired' integer counts.
     */
    function sywat_get_tfaa_member_statistics( $all_members = null ) {
        if ( null === $all_members ) {
            $all_members = sywat_get_all_tfaa_members();
        }

        $stat_total   = 0;
        $stat_active  = 0;
        $stat_expired = 0;
        $counted_uids = array(); // Dedup guard hashtable

        // Define exact qualifying active membership roles
        $qualifying_membership_roles = function_exists( 'sywat_get_custom_roles_slugs' ) 
            ? sywat_get_custom_roles_slugs() 
            : array(
                'tfaa_member', 'tfaa_president', 'tfaa_vice_president', 'tfaa_treasurer', 
                'tfaa_secretary', 'tfaa_tournament_director', 'tfaa_social_media_director', 
                'tfaa_webmaster', 'tfaa_field_governor'
            );

        if ( ! empty( $all_members ) && is_array( $all_members ) ) {
            foreach ( $all_members as $sm ) {
                if ( ! $sm instanceof WP_User ) continue;

                $uid = intval( $sm->ID );
                if ( isset( $counted_uids[ $uid ] ) ) {
                    continue; // Skip if already counted
                }

                $u_roles          = (array) $sm->roles;
                $has_active_member_role = false;

                foreach ( $u_roles as $r_slug ) {
                    if ( in_array( strtolower( $r_slug ), $qualifying_membership_roles, true ) ) {
                        $has_active_member_role = true;
                        break;
                    }
                }

                // If user does not hold a qualifying membership role (e.g., pure shop manager like Korbins), skip stats
                if ( ! $has_active_member_role ) {
                    continue;
                }

                $counted_uids[ $uid ] = true;
                $stat_total++;

                $sm_nfaa = function_exists( 'sywat_get_member_nfaa_status' ) 
                    ? sywat_get_member_nfaa_status( $uid ) 
                    : array( 'is_active' => false );

                if ( isset( $sm_nfaa['is_active'] ) && $sm_nfaa['is_active'] ) {
                    $stat_active++;
                } else {
                    $stat_expired++;
                }
            }
        }

        return array(
            'total'   => $stat_total,
            'active'  => $stat_active,
            'expired' => $stat_expired,
        );
    }
}

if ( ! function_exists( 'sywat_get_import_preview_db_maps' ) ) {
    /**
     * Bulk in-memory database lookup map generator for historical import audit previews.
     * Pre-fetches registered NFAA numbers and email addresses into O(1) hashtable sets.
     *
     * @return array Associative array with 'existing_nfaa_set' and 'existing_email_set'.
     */
    function sywat_get_import_preview_db_maps() {
        global $wpdb;

        $existing_nfaa_set = array();
        $nfaa_db_rows      = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'sywat_nfaa_number' AND meta_value != ''" );
        if ( ! empty( $nfaa_db_rows ) ) {
            $existing_nfaa_set = array_flip( array_map( 'trim', $nfaa_db_rows ) );
        }

        $existing_email_set = array();
        $email_db_rows      = $wpdb->get_col( "SELECT user_email FROM {$wpdb->users} WHERE user_email != ''" );
        if ( ! empty( $email_db_rows ) ) {
            $existing_email_set = array_flip( array_map( 'strtolower', array_map( 'trim', $email_db_rows ) ) );
        }

        return array(
            'existing_nfaa_set'  => $existing_nfaa_set,
            'existing_email_set' => $existing_email_set,
        );
    }
}
