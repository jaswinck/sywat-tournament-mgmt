<?php
/**
 * Sub-Module: Member & Board Data Retrieval & Calculation Engines
 * Path: includes/members/members-data.php
 * Description: Centralized data layer for the Member Management sub-module. Handles WP_User queries, bulk scalar SQL pre-fetching with transient caching for 10,000+ record scalability, O(1) in-memory hashtable index generation, unified flat archer dataset generation (combining primary WP_User accounts, household dependent sub-profiles, and unassigned pool archers), O(1) member record lookup by exact and First+Last name/contact, hierarchy-sorted board member retrieval (merging position meta and officer role accounts), and pure statistical calculation engines for TFAA membership counts.
 * Compliance: Zero HTML layout output. Pure data arrays and scalar return values. Adheres to Dual Data & Helper File Separation Mandate.
 * Author: SYWAT Archery
 * Version: 6.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

if ( ! function_exists( 'sywat_get_bulk_member_directory_records' ) ) {
    /**
     * Bulk scalar SQL retrieval of all Member Directory archers (Primary Heads of Household,
     * Household Dependents, and Unassigned Pool Archers) cached in a 15-minute transient.
     * Executes only 2 scalar SQL queries with zero WP_User ORM hydration for 10,000+ record scale.
     *
     * @return array List of lightweight scalar archer associative arrays.
     */
    function sywat_get_bulk_member_directory_records() {
        $cached = get_transient( 'sywat_member_directory_index_cache' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;

        $allowed_roles = array(
            'tfaa_member', 'tfaa_president', 'tfaa_vice_president', 'tfaa_treasurer',
            'tfaa_secretary', 'tfaa_tournament_director', 'tfaa_social_media_director',
            'tfaa_webmaster', 'tfaa_field_governor', 'sywat_shop_manager',
            'sywat_shop_user', 'administrator',
        );

        $user_rows = $wpdb->get_results( "SELECT ID, display_name, user_email FROM {$wpdb->users}" );
        $users_map = array();
        if ( ! empty( $user_rows ) ) {
            foreach ( $user_rows as $u_row ) {
                $uid = intval( $u_row->ID );
                if ( $uid > 0 ) {
                    $users_map[ $uid ] = array(
                        'display_name' => trim( (string) $u_row->display_name ),
                        'email'        => trim( (string) $u_row->user_email ),
                    );
                }
            }
        }

        $cap_key   = $wpdb->prefix . 'capabilities';
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN (%s, 'sywat_phone', 'sywat_gender', 'sywat_dob', 'sywat_is_pro', 'sywat_household_dependents')",
                $cap_key
            )
        );

        $meta_by_user = array();
        $dep_blobs    = array();
        if ( ! empty( $meta_rows ) ) {
            foreach ( $meta_rows as $m_row ) {
                $uid  = intval( $m_row->user_id );
                $mkey = (string) $m_row->meta_key;
                if ( $mkey === 'sywat_household_dependents' ) {
                    if ( $m_row->meta_value !== '' && $m_row->meta_value !== 'a:0:{}' ) {
                        $dep_blobs[ $uid ] = $m_row->meta_value;
                    }
                } else {
                    $meta_by_user[ $uid ][ $mkey ] = $m_row->meta_value;
                }
            }
        }

        $records = array();

        foreach ( $users_map as $uid => $u_info ) {
            $raw_caps = isset( $meta_by_user[ $uid ][ $cap_key ] ) ? maybe_unserialize( $meta_by_user[ $uid ][ $cap_key ] ) : array();
            if ( ! is_array( $raw_caps ) || empty( $raw_caps ) ) {
                continue;
            }
            if ( empty( array_intersect( $allowed_roles, array_keys( array_filter( $raw_caps ) ) ) ) || $u_info['display_name'] === '' ) {
                continue;
            }

            $records[] = array(
                'account_type' => 'head',
                'user_id'      => $uid,
                'head_user_id' => $uid,
                'display_name' => $u_info['display_name'],
                'email'        => $u_info['email'],
                'phone'        => isset( $meta_by_user[ $uid ]['sywat_phone'] ) ? trim( (string) $meta_by_user[ $uid ]['sywat_phone'] ) : '',
                'gender'       => isset( $meta_by_user[ $uid ]['sywat_gender'] ) ? trim( (string) $meta_by_user[ $uid ]['sywat_gender'] ) : '',
                'dob'          => isset( $meta_by_user[ $uid ]['sywat_dob'] ) ? trim( (string) $meta_by_user[ $uid ]['sywat_dob'] ) : '',
                'is_pro'       => isset( $meta_by_user[ $uid ]['sywat_is_pro'] ) ? trim( (string) $meta_by_user[ $uid ]['sywat_is_pro'] ) : 'no',
            );
        }

        foreach ( $dep_blobs as $head_id => $blob ) {
            $deps = maybe_unserialize( $blob );
            if ( ! is_array( $deps ) || empty( $deps ) ) {
                continue;
            }
            $head_email = isset( $users_map[ $head_id ]['email'] ) ? $users_map[ $head_id ]['email'] : '';
            $head_phone = isset( $meta_by_user[ $head_id ]['sywat_phone'] ) ? trim( (string) $meta_by_user[ $head_id ]['sywat_phone'] ) : '';

            foreach ( $deps as $d ) {
                if ( ! is_array( $d ) ) {
                    continue;
                }
                $fn   = isset( $d['first_name'] ) ? trim( (string) $d['first_name'] ) : '';
                $ln   = isset( $d['last_name'] ) ? trim( (string) $d['last_name'] ) : '';
                $name = ! empty( $d['display_name'] ) ? trim( (string) $d['display_name'] ) : trim( $fn . ' ' . $ln );
                if ( $name === '' ) {
                    continue;
                }

                $records[] = array(
                    'account_type' => 'dependent',
                    'user_id'      => 0,
                    'head_user_id' => $head_id,
                    'display_name' => $name,
                    'email'        => ! empty( $d['email'] ) ? trim( (string) $d['email'] ) : $head_email,
                    'phone'        => ! empty( $d['phone'] ) ? trim( (string) $d['phone'] ) : $head_phone,
                    'gender'       => isset( $d['gender'] ) ? trim( (string) $d['gender'] ) : '',
                    'dob'          => isset( $d['dob'] ) ? trim( (string) $d['dob'] ) : '',
                    'is_pro'       => isset( $d['is_pro'] ) ? trim( (string) $d['is_pro'] ) : 'no',
                );
            }
        }

        $unassigned_pool = get_option( 'sywat_unassigned_archers', array() );
        if ( is_array( $unassigned_pool ) && ! empty( $unassigned_pool ) ) {
            foreach ( $unassigned_pool as $u_item ) {
                if ( ! is_array( $u_item ) ) {
                    continue;
                }
                $fn   = isset( $u_item['first_name'] ) ? trim( (string) $u_item['first_name'] ) : '';
                $ln   = isset( $u_item['last_name'] ) ? trim( (string) $u_item['last_name'] ) : '';
                $name = ! empty( $u_item['display_name'] ) ? trim( (string) $u_item['display_name'] ) : trim( $fn . ' ' . $ln );
                if ( $name === '' ) {
                    continue;
                }

                $records[] = array(
                    'account_type' => 'unassigned',
                    'user_id'      => 0,
                    'head_user_id' => 0,
                    'display_name' => $name,
                    'email'        => isset( $u_item['email'] ) ? trim( (string) $u_item['email'] ) : '',
                    'phone'        => isset( $u_item['phone'] ) ? trim( (string) $u_item['phone'] ) : '',
                    'gender'       => isset( $u_item['gender'] ) ? trim( (string) $u_item['gender'] ) : '',
                    'dob'          => isset( $u_item['dob'] ) ? trim( (string) $u_item['dob'] ) : '',
                    'is_pro'       => isset( $u_item['is_pro'] ) ? trim( (string) $u_item['is_pro'] ) : 'no',
                );
            }
        }

        set_transient( 'sywat_member_directory_index_cache', $records, 15 * MINUTE_IN_SECONDS );
        return $records;
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
        $all_archers   = array();
        $head_name_map = array();

        // 1. Primary WP_User Accounts (Heads of Household)
        $primary_users = sywat_get_all_tfaa_members();
        if ( ! empty( $primary_users ) && is_array( $primary_users ) ) {
            foreach ( $primary_users as $pu ) {
                if ( ! $pu instanceof WP_User ) continue;
                $uid = intval( $pu->ID );
                
                // Populate O(1) lookup map for dependent loop
                $head_name_map[ $uid ] = $pu->display_name;

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
                $head_id = intval( $row->user_id );
                
                // O(1) Hashtable lookup to prevent N+1 query loop
                if ( isset( $head_name_map[ $head_id ] ) ) {
                    $head_name = $head_name_map[ $head_id ];
                } else {
                    // Fallback only if Head was stripped of their role but retained dependents
                    $head_user = get_userdata( $head_id );
                    $head_name = $head_user ? $head_user->display_name : 'Primary Account';
                }
                
                $deps = maybe_unserialize( $row->meta_value );

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

if ( ! function_exists( 'sywat_find_member_record_by_name' ) ) {
    /**
     * Retrieve a single Member Directory archer record by full name (with First+Last middle-initial folding
     * and optional email/phone tie-breaker) using O(1) static hashtable indexes built once per request
     * from sywat_get_bulk_member_directory_records().
     *
     * @param string $full_name Archer full name.
     * @param string $email     Optional email hint for duplicate-name disambiguation.
     * @param string $phone     Optional phone hint for duplicate-name disambiguation.
     * @return array|null       Standardized archer record array or null.
     */
    function sywat_find_member_record_by_name( $full_name, $email = '', $phone = '' ) {
        static $exact_index = null;
        static $fl_index    = null;

        $clean_name = trim( preg_replace( '/[[:space:]]+/', ' ', (string) $full_name ) );
        if ( $clean_name === '' ) {
            return null;
        }

        $build_keys = function( $raw ) {
            if ( function_exists( 'sywat_normalize_archer_match_keys' ) ) {
                return sywat_normalize_archer_match_keys( $raw );
            }
            $norm  = strtolower( trim( preg_replace( '/[[:space:]]+/', ' ', str_replace( '.', ' ', (string) $raw ) ) ) );
            $parts = $norm !== '' ? explode( ' ', $norm ) : array();
            return array(
                'exact' => $norm,
                'fl'    => ( count( $parts ) >= 2 ) ? ( reset( $parts ) . '___' . end( $parts ) ) : $norm,
            );
        };

        if ( $exact_index === null ) {
            $exact_index = array();
            $fl_index    = array();
            $records     = sywat_get_bulk_member_directory_records();

            foreach ( $records as $arch ) {
                if ( ! is_array( $arch ) || empty( $arch['display_name'] ) ) {
                    continue;
                }
                $keys = $build_keys( $arch['display_name'] );
                if ( $keys['exact'] !== '' ) {
                    $exact_index[ $keys['exact'] ][] = $arch;
                }
                if ( $keys['fl'] !== '' ) {
                    $fl_index[ $keys['fl'] ][] = $arch;
                }
            }
        }

        $query_keys = $build_keys( $clean_name );
        if ( $query_keys['exact'] === '' ) {
            return null;
        }

        $hint_email = strtolower( trim( (string) $email ) );
        $hint_phone = preg_replace( '/\D+/', '', (string) $phone );

        $disambiguate = function( $candidates ) use ( $hint_email, $hint_phone ) {
            if ( empty( $candidates ) ) {
                return null;
            }
            if ( count( $candidates ) === 1 ) {
                return $candidates[0];
            }
            if ( $hint_email !== '' || strlen( $hint_phone ) >= 7 ) {
                foreach ( $candidates as $cand ) {
                    $c_mail = isset( $cand['email'] ) ? trim( (string) $cand['email'] ) : '';
                    $c_tel  = isset( $cand['phone'] ) ? trim( (string) $cand['phone'] ) : '';
                    if ( ( $hint_email !== '' && strtolower( $c_mail ) === $hint_email )
                      || ( strlen( $hint_phone ) >= 7 && preg_replace( '/\D+/', '', $c_tel ) === $hint_phone ) ) {
                        return $cand;
                    }
                }
            }
            return null;
        };

        if ( ! empty( $exact_index[ $query_keys['exact'] ] ) ) {
            $matched = $disambiguate( $exact_index[ $query_keys['exact'] ] );
            return $matched ? $matched : $exact_index[ $query_keys['exact'] ][0];
        }

        if ( $query_keys['fl'] !== '' && ! empty( $fl_index[ $query_keys['fl'] ] ) ) {
            return $disambiguate( $fl_index[ $query_keys['fl'] ] );
        }

        return null;
    }
}

        return $matched;
    }
}
