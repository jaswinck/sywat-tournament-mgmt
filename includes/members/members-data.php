<?php
/**
 * Sub-Module: Member & Board Data Retrieval & Calculation Engines
 * Path: includes/members/members-data.php
 * Description: Centralized data layer for the Member Management sub-module. Handles WP_User queries, bulk database pre-fetching, O(1) in-memory hashtable index generation, unified flat archer dataset generation (combining primary WP_User accounts, household dependent sub-profiles, and unassigned pool archers), hierarchy-sorted board member retrieval (merging position meta and officer role accounts), and pure statistical calculation engines for TFAA membership counts.
 * Compliance: Zero HTML layout output. Pure data arrays and scalar return values. Adheres to Dual Data & Helper File Separation Mandate.
 * Author: SYWAT Archery
 * Version: 5.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_get_all_tfaa_members' ) ) {
    /**
     * Retrieve registered TFAA primary members matching custom system roles, shop managers, and administrators.
     *
     * @return array List of WP_User objects.
     */
    function sywat_get_all_tfaa_members() {
        $allowed_roles = array(
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

if ( ! function_exists( 'sywat_get_all_flat_archers' ) ) {
    /**
     * Build unified flat array of ALL archers (Heads of Household, Dependents, and Unassigned Pool Archers).
     *
     * @return array Standardized list of archer associative arrays.
     */
    function sywat_get_all_flat_archers() {
        global $wpdb;
        $all_archers = array();

        // 1. Primary WP_User Accounts (Heads of Household)
        $primary_users = sywat_get_all_tfaa_members();
        if ( ! empty( $primary_users ) && is_array( $primary_users ) ) {
            foreach ( $primary_users as $pu ) {
                if ( ! $pu instanceof WP_User ) continue;
                $uid = intval( $pu->ID );

                $all_archers[] = array(
                    'account_type'    => 'head',
                    'user_id'         => $uid,
                    'system_id'       => function_exists( 'sywat_get_user_system_id' ) ? sywat_get_user_system_id( $uid ) : 'WP-' . $uid,
                    'first_name'      => get_user_meta( $uid, 'first_name', true ) ? get_user_meta( $uid, 'first_name', true ) : $pu->first_name,
                    'last_name'       => get_user_meta( $uid, 'last_name', true ) ? get_user_meta( $uid, 'last_name', true ) : $pu->last_name,
                    'display_name'    => $pu->display_name,
                    'email'           => $pu->user_email,
                    'phone'           => get_user_meta( $uid, 'sywat_phone', true ),
                    'gender'          => get_user_meta( $uid, 'sywat_gender', true ),
                    'is_pro'          => get_user_meta( $uid, 'sywat_is_pro', true ),
                    'dob'             => get_user_meta( $uid, 'sywat_dob', true ),
                    'accommodations'  => get_user_meta( $uid, 'sywat_accommodations', true ),
                    'nfaa_number'     => get_user_meta( $uid, 'sywat_nfaa_number', true ),
                    'nfaa_expiration' => get_user_meta( $uid, 'sywat_nfaa_expiration', true ),
                    'head_user_id'    => $uid,
                    'head_name'       => $pu->display_name,
                    'wp_user_obj'     => $pu,
                );
            }
        }

        // 2. Household Dependents (Stored in User Meta)
        $dep_meta_rows = $wpdb->get_results(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'sywat_household_dependents' AND meta_value != '' AND meta_value != 'a:0:{}'"
        );

        if ( ! empty( $dep_meta_rows ) ) {
            foreach ( $dep_meta_rows as $row ) {
                $head_id   = intval( $row->user_id );
                $head_user = get_userdata( $head_id );
                $head_name = $head_user ? $head_user->display_name : 'Primary Account';
                $deps      = maybe_unserialize( $row->meta_value );

                if ( is_array( $deps ) && ! empty( $deps ) ) {
                    foreach ( $deps as $d ) {
                        $fn   = isset( $d['first_name'] ) ? $d['first_name'] : '';
                        $ln   = isset( $d['last_name'] ) ? $d['last_name'] : '';
                        $name = isset( $d['display_name'] ) ? $d['display_name'] : trim( $fn . ' ' . $ln );

                        if ( empty( $fn ) && empty( $ln ) ) continue;

                        $all_archers[] = array(
                            'account_type'    => 'dependent',
                            'user_id'         => 0,
                            'system_id'       => isset( $d['system_id'] ) ? $d['system_id'] : '',
                            'first_name'      => $fn,
                            'last_name'       => $ln,
                            'display_name'    => $name,
                            'email'           => isset( $d['email'] ) ? $d['email'] : '',
                            'phone'           => isset( $d['phone'] ) ? $d['phone'] : '',
                            'gender'          => isset( $d['gender'] ) ? $d['gender'] : 'unassigned',
                            'is_pro'          => isset( $d['is_pro'] ) ? $d['is_pro'] : 'no',
                            'dob'             => isset( $d['dob'] ) ? $d['dob'] : '',
                            'accommodations'  => isset( $d['accommodations'] ) ? $d['accommodations'] : 'none',
                            'nfaa_number'     => isset( $d['nfaa_number'] ) ? $d['nfaa_number'] : '',
                            'nfaa_expiration' => isset( $d['nfaa_expiration'] ) ? $d['nfaa_expiration'] : '',
                            'head_user_id'    => $head_id,
                            'head_name'       => $head_name,
                            'wp_user_obj'     => null,
                        );
                    }
                }
            }
        }

        // 3. Unassigned Pool Archers (Stored in Option Array)
        $unassigned_pool = get_option( 'sywat_unassigned_archers', array() );
        if ( is_array( $unassigned_pool ) && ! empty( $unassigned_pool ) ) {
            foreach ( $unassigned_pool as $sys_id => $u_item ) {
                $fn   = isset( $u_item['first_name'] ) ? $u_item['first_name'] : '';
                $ln   = isset( $u_item['last_name'] ) ? $u_item['last_name'] : '';
                $name = isset( $u_item['display_name'] ) ? $u_item['display_name'] : trim( $fn . ' ' . $ln );

                if ( empty( $fn ) && empty( $ln ) ) continue;

                $all_archers[] = array(
                    'account_type'    => 'unassigned',
                    'user_id'         => 0,
                    'system_id'       => $sys_id,
                    'first_name'      => $fn,
                    'last_name'       => $ln,
                    'display_name'    => $name,
                    'email'           => isset( $u_item['email'] ) ? $u_item['email'] : '',
                    'phone'           => isset( $u_item['phone'] ) ? $u_item['phone'] : '',
                    'gender'          => isset( $u_item['gender'] ) ? $u_item['gender'] : 'unassigned',
                    'is_pro'          => isset( $u_item['is_pro'] ) ? $u_item['is_pro'] : 'no',
                    'dob'             => isset( $u_item['dob'] ) ? $u_item['dob'] : '',
                    'accommodations'  => isset( $u_item['accommodations'] ) ? $u_item['accommodations'] : 'none',
                    'nfaa_number'     => isset( $u_item['nfaa_number'] ) ? $u_item['nfaa_number'] : '',
                    'nfaa_expiration' => isset( $u_item['nfaa_expiration'] ) ? $u_item['nfaa_expiration'] : '',
                    'head_user_id'    => 0,
                    'head_name'       => 'Unassigned Pool',
                    'wp_user_obj'     => null,
                );
            }
        }

        // Sort alphabetically by Display Name
        usort( $all_archers, function( $a, $b ) {
            return strcasecmp( $a['display_name'], $b['display_name'] );
        } );

        return $all_archers;
    }
}

if ( ! function_exists( 'sywat_get_all_board_members' ) ) {
    /**
     * Retrieve all assigned TFAA Board Members pre-sorted by official organizational hierarchy rank.
     * Combines users holding sywat_board_positions meta and users assigned official officer/governor roles.
     *
     * @return array List of WP_User objects with board assignments sorted by rank.
     */
    function sywat_get_all_board_members() {
        $board_roles = array(
            'tfaa_president', 'tfaa_vice_president', 'tfaa_treasurer', 
            'tfaa_secretary', 'tfaa_tournament_director', 'tfaa_social_media_director', 
            'tfaa_webmaster', 'tfaa_field_governor'
        );

        $users_meta  = get_users( array( 'meta_key' => 'sywat_board_positions', 'meta_compare' => 'EXISTS' ) );
        $users_roles = get_users( array( 'role__in' => $board_roles ) );

        $combined_map = array();
        foreach ( array_merge( (array) $users_meta, (array) $users_roles ) as $u ) {
            if ( $u instanceof WP_User ) {
                $combined_map[ $u->ID ] = $u;
            }
        }

        $board_members = array_values( $combined_map );

        if ( function_exists( 'sywat_sort_board_members_by_hierarchy' ) && ! empty( $board_members ) ) {
            return sywat_sort_board_members_by_hierarchy( $board_members );
        }

        return $board_members;
    }
}

if ( ! function_exists( 'sywat_get_tfaa_member_statistics' ) ) {
    /**
     * Statistical calculation engine for Member Directory metrics.
     * Evaluates total, active, expired tallies, primary accounts, and unassigned count across all flat archer records.
     *
     * @param array|null $all_members Optional pre-fetched list of WP_User objects or flat archer arrays.
     * @return array Associative array containing 'total', 'active', 'expired', 'primary_count', and 'unassigned_count'.
     */
    function sywat_get_tfaa_member_statistics( $all_members = null ) {
        $flat_archers = sywat_get_all_flat_archers();

        $stat_total      = count( $flat_archers );
        $stat_active     = 0;
        $stat_expired    = 0;
        $primary_count   = 0;
        $unassigned_cnt  = 0;
        $today           = date( 'Y-m-d' );

        foreach ( $flat_archers as $arch ) {
            if ( $arch['account_type'] === 'head' ) {
                $primary_count++;
            } elseif ( $arch['account_type'] === 'unassigned' ) {
                $unassigned_cnt++;
            }

            $exp_date  = isset( $arch['nfaa_expiration'] ) ? trim( $arch['nfaa_expiration'] ) : '';
            $is_active = false;

            if ( ! empty( $exp_date ) && $exp_date !== '0000-00-00' ) {
                if ( $exp_date === '2099-12-31' || strtotime( $exp_date ) >= strtotime( $today ) ) {
                    $is_active = true;
                }
            }

            if ( $is_active ) {
                $stat_active++;
            } else {
                $stat_expired++;
            }
        }

        return array(
            'total'            => $stat_total,
            'active'           => $stat_active,
            'expired'          => $stat_expired,
            'primary_count'    => $primary_count,
            'unassigned_count' => $unassigned_cnt,
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
