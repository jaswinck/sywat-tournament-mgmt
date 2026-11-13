<?php
/**
 * POC Sub-Module: TFAA Gender & Pro Status Reconciliation Engine
 * Path: includes/members/reconcile/tfaa-reconcile-engine.php
 * Description: Enterprise-scalable, transaction-batched POC utility comparing synced TFAA shooter records (sywat_shooter_genders_cache and tournament master registration batches) against the unified Member Directory (Primary Heads, Household Dependents, and Unassigned Pool) to backfill missing gender/pro attributes and surface unmatched TFAA names with server-side pagination.
 * Compliance: Zero HTML layout templates, zero O(N^2) loops, scalar ID verification in batch writes, explicit MySQL transaction batching with cache suspension, omitted closing PHP tag. Self-contained for clean removal post-POC.
 * Author: SYWAT Archery
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_reconcile_normalize_name' ) ) {
    /**
     * Normalize an archer name identically to sywat-tfaa-sync.php for O(1) hashtable matching.
     *
     * @param string $name Raw archer name.
     * @return string Normalized lowercase alphanumeric string.
     */
    function sywat_reconcile_normalize_name( $name ) {
        $n = preg_replace( '/\[.*?\]|\(.*?\)/', '', (string) $name );
        $n = preg_replace( '/[^a-zA-Z0-9\s]/', '', $n );
        $n = preg_replace( '/\s+/', ' ', $n );
        return strtolower( trim( $n ) );
    }
}

if ( ! function_exists( 'sywat_reconcile_build_datasets' ) ) {
    /**
     * Shared DRY dataset builder indexing TFAA synced records and flat directory records into O(1) hashtables.
     *
     * @param array|null $preloaded_archers Optional pre-fetched flat archer array to avoid duplicate user queries.
     * @return array Associative array containing 'matched_updates', 'unmatched_names', 'mappable_members', and 'stats'.
     */
    function sywat_reconcile_build_datasets( $preloaded_archers = null ) {
        global $wpdb;

        $gender_cache  = get_option( 'sywat_shooter_genders_cache', array() );
        $ignored_names = get_option( 'sywat_ignored_tfaa_names', array() );
        if ( ! is_array( $gender_cache ) ) $gender_cache = array();
        if ( ! is_array( $ignored_names ) ) $ignored_names = array();

        $tfaa_pool = array();

        foreach ( $gender_cache as $norm_key => $raw_gender ) {
            $clean_key = sywat_reconcile_normalize_name( $norm_key );
            if ( $clean_key === '' ) continue;

            $g_lower = strtolower( trim( (string) $raw_gender ) );
            $gender  = ( $g_lower === 'male' || $g_lower === 'female' ) ? $g_lower : '';

            $tfaa_pool[ $clean_key ] = array(
                'norm_name'    => $clean_key,
                'display_name' => ucwords( $clean_key ),
                'gender'       => $gender,
                'is_pro'       => 'no',
            );
        }
        unset( $gender_cache );

        // Single indexed query against tournament master registration batches (sywat_registrations_*)
        $master_reg_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'sywat_registrations_' ) . '%'
            )
        );

        if ( ! empty( $master_reg_rows ) && is_array( $master_reg_rows ) ) {
            foreach ( $master_reg_rows as $raw_batch ) {
                $batch = maybe_unserialize( $raw_batch );
                if ( ! is_array( $batch ) ) continue;

                foreach ( $batch as $reg ) {
                    if ( ! is_array( $reg ) || empty( $reg['name'] ) ) continue;

                    $raw_name = trim( (string) $reg['name'] );
                    $norm_key = sywat_reconcile_normalize_name( $raw_name );
                    if ( $norm_key === '' ) continue;

                    $r_type   = isset( $reg['type'] ) ? strtolower( trim( (string) $reg['type'] ) ) : '';
                    $r_style  = isset( $reg['style'] ) ? trim( (string) $reg['style'] ) : '';
                    $r_gender = isset( $reg['gender'] ) ? strtolower( trim( (string) $reg['gender'] ) ) : '';
                    $is_guest = ( $r_type === 'guest' || stripos( $r_style, 'guest' ) !== false || stripos( $raw_name, '[GUEST]' ) !== false );
                    $is_pro   = ( ! $is_guest && stripos( $r_style, 'pro' ) !== false );

                    if ( ! isset( $tfaa_pool[ $norm_key ] ) ) {
                        if ( $is_guest ) continue;

                        $clean_title = trim( preg_replace( '/\[.*?\]/', '', $raw_name ) );
                        $tfaa_pool[ $norm_key ] = array(
                            'norm_name'    => $norm_key,
                            'display_name' => $clean_title !== '' ? $clean_title : ucwords( $norm_key ),
                            'gender'       => ( $r_gender === 'male' || $r_gender === 'female' ) ? $r_gender : '',
                            'is_pro'       => $is_pro ? 'yes' : 'no',
                        );
                    } elseif ( ! $is_guest ) {
                        $clean_title = trim( preg_replace( '/\[.*?\]/', '', $raw_name ) );
                        if ( $clean_title !== '' ) {
                            $tfaa_pool[ $norm_key ]['display_name'] = $clean_title;
                        }
                        if ( $tfaa_pool[ $norm_key ]['gender'] === '' && ( $r_gender === 'male' || $r_gender === 'female' ) ) {
                            $tfaa_pool[ $norm_key ]['gender'] = $r_gender;
                        }
                        if ( $is_pro ) {
                            $tfaa_pool[ $norm_key ]['is_pro'] = 'yes';
                        }
                    }
                }
                unset( $batch );
            }
            unset( $master_reg_rows );
        }

        // Reuse preloaded flat directory archers when supplied
        $flat_archers = is_array( $preloaded_archers )
            ? $preloaded_archers
            : ( function_exists( 'sywat_get_all_flat_archers' ) ? sywat_get_all_flat_archers() : array() );

        $directory_by_key = array();
        $mappable_members = array();

        foreach ( $flat_archers as $arch ) {
            if ( ! is_array( $arch ) ) continue;
            $disp_name = isset( $arch['display_name'] ) ? (string) $arch['display_name'] : '';
            $norm_key  = sywat_reconcile_normalize_name( $disp_name );
            if ( $norm_key === '' ) continue;

            $a_type   = isset( $arch['account_type'] ) ? (string) $arch['account_type'] : 'head';
            $a_uid    = isset( $arch['user_id'] ) ? intval( $arch['user_id'] ) : 0;
            $a_sys_id = isset( $arch['system_id'] ) ? (string) $arch['system_id'] : '';
            $a_head   = isset( $arch['head_user_id'] ) ? intval( $arch['head_user_id'] ) : 0;
            $a_gender = isset( $arch['gender'] ) ? strtolower( trim( (string) $arch['gender'] ) ) : 'unassigned';
            $a_pro    = isset( $arch['is_pro'] ) ? strtolower( trim( (string) $arch['is_pro'] ) ) : 'no';

            if ( $a_type === 'head' && $a_uid > 0 ) {
                $target_key = 'head:' . $a_uid;
            } elseif ( $a_type === 'dependent' && $a_head > 0 && $a_sys_id !== '' ) {
                $target_key = 'dep:' . $a_head . ':' . $a_sys_id;
            } elseif ( $a_type === 'unassigned' && $a_sys_id !== '' ) {
                $target_key = 'pool:' . $a_sys_id;
            } else {
                continue;
            }

            $record = array(
                'target_key'   => $target_key,
                'account_type' => $a_type,
                'head_name'    => isset( $arch['head_name'] ) ? (string) $arch['head_name'] : '',
                'display_name' => $disp_name,
                'gender'       => ( $a_gender === 'male' || $a_gender === 'female' ) ? $a_gender : 'unassigned',
                'is_pro'       => ( $a_pro === 'yes' ) ? 'yes' : 'no',
            );

            $directory_by_key[ $norm_key ][] = $record;

            if ( $record['gender'] === 'unassigned' || $record['is_pro'] === 'no' ) {
                $mappable_members[] = array(
                    'target_key'   => $target_key,
                    'account_type' => $a_type,
                    'display_name' => $disp_name,
                );
            }
        }

        $matched_updates = array();
        $unmatched_names = array();
        $already_synced  = 0;

        foreach ( $tfaa_pool as $norm_key => $tfaa_item ) {
            if ( isset( $ignored_names[ $norm_key ] ) ) {
                continue;
            }

            if ( isset( $directory_by_key[ $norm_key ] ) ) {
                foreach ( $directory_by_key[ $norm_key ] as $dir_rec ) {
                    $needs_gender = ( $dir_rec['gender'] === 'unassigned' && $tfaa_item['gender'] !== '' );
                    $needs_pro    = ( $dir_rec['is_pro'] === 'no' && $tfaa_item['is_pro'] === 'yes' );

                    if ( $needs_gender || $needs_pro ) {
                        $matched_updates[] = array(
                            'target_key'     => $dir_rec['target_key'],
                            'account_type'   => $dir_rec['account_type'],
                            'display_name'   => $dir_rec['display_name'],
                            'head_name'      => $dir_rec['head_name'],
                            'current_gender' => $dir_rec['gender'],
                            'new_gender'     => $needs_gender ? $tfaa_item['gender'] : '',
                            'current_is_pro' => $dir_rec['is_pro'],
                            'new_is_pro'     => $needs_pro ? 'yes' : '',
                        );
                    } else {
                        $already_synced++;
                    }
                }
            } elseif ( $tfaa_item['gender'] !== '' || $tfaa_item['is_pro'] === 'yes' ) {
                $unmatched_names[] = array(
                    'norm_name'    => $norm_key,
                    'display_name' => $tfaa_item['display_name'],
                    'gender'       => $tfaa_item['gender'],
                    'is_pro'       => $tfaa_item['is_pro'],
                );
            }
        }

        usort( $matched_updates, function( $a, $b ) {
            return strcasecmp( $a['display_name'], $b['display_name'] );
        } );

        usort( $unmatched_names, function( $a, $b ) {
            return strcasecmp( $a['display_name'], $b['display_name'] );
        } );

        usort( $mappable_members, function( $a, $b ) {
            return strcasecmp( $a['display_name'], $b['display_name'] );
        } );

        return array(
            'matched_updates'  => $matched_updates,
            'unmatched_names'  => $unmatched_names,
            'mappable_members' => $mappable_members,
            'stats'            => array(
                'tfaa_total'     => count( $tfaa_pool ),
                'ready_updates'  => count( $matched_updates ),
                'unmatched'      => count( $unmatched_names ),
                'already_synced' => $already_synced,
            ),
        );
    }
}

if ( ! function_exists( 'sywat_get_tfaa_reconciliation_report' ) ) {
    /**
     * Build paginated, search-filtered reconciliation report for safe UI rendering at enterprise scale.
     *
     * @param array|null $preloaded_archers Optional pre-fetched flat archer array.
     * @param array      $args              Pagination and filter arguments ('matched_page', 'unmatched_page', 'per_page', 'search').
     * @return array Paginated report payload with 'matched_updates', 'unmatched_names', 'mappable_members', 'pagination', 'stats', and 'error'.
     */
    function sywat_get_tfaa_reconciliation_report( $preloaded_archers = null, $args = array() ) {
        $per_page       = isset( $args['per_page'] ) ? max( 10, min( 100, intval( $args['per_page'] ) ) ) : 50;
        $matched_page   = isset( $args['matched_page'] ) ? max( 1, intval( $args['matched_page'] ) ) : 1;
        $unmatched_page = isset( $args['unmatched_page'] ) ? max( 1, intval( $args['unmatched_page'] ) ) : 1;
        $search_term    = isset( $args['search'] ) ? strtolower( trim( (string) $args['search'] ) ) : '';

        $empty_report = array(
            'matched_updates'  => array(),
            'unmatched_names'  => array(),
            'mappable_members' => array(),
            'pagination'       => array(
                'per_page'              => $per_page,
                'matched_page'          => 1,
                'matched_total_pages'   => 1,
                'matched_filtered'      => 0,
                'unmatched_page'        => 1,
                'unmatched_total_pages' => 1,
                'unmatched_filtered'    => 0,
                'search'                => $search_term,
            ),
            'stats'            => array(
                'tfaa_total'     => 0,
                'ready_updates'  => 0,
                'unmatched'      => 0,
                'already_synced' => 0,
            ),
            'error'            => '',
        );

        try {
            $datasets = sywat_reconcile_build_datasets( $preloaded_archers );

            $matched_all   = $datasets['matched_updates'];
            $unmatched_all = $datasets['unmatched_names'];

            if ( $search_term !== '' ) {
                $matched_all = array_values( array_filter( $matched_all, function( $row ) use ( $search_term ) {
                    return stripos( $row['display_name'] . ' ' . $row['head_name'], $search_term ) !== false;
                } ) );
                $unmatched_all = array_values( array_filter( $unmatched_all, function( $row ) use ( $search_term ) {
                    return stripos( $row['display_name'], $search_term ) !== false;
                } ) );
            }

            $matched_filtered   = count( $matched_all );
            $matched_pages      = max( 1, (int) ceil( $matched_filtered / $per_page ) );
            $matched_page       = min( $matched_page, $matched_pages );
            $matched_slice      = array_slice( $matched_all, ( $matched_page - 1 ) * $per_page, $per_page );

            $unmatched_filtered = count( $unmatched_all );
            $unmatched_pages    = max( 1, (int) ceil( $unmatched_filtered / $per_page ) );
            $unmatched_page     = min( $unmatched_page, $unmatched_pages );
            $unmatched_slice    = array_slice( $unmatched_all, ( $unmatched_page - 1 ) * $per_page, $per_page );

            return array(
                'matched_updates'  => $matched_slice,
                'unmatched_names'  => $unmatched_slice,
                'mappable_members' => $datasets['mappable_members'],
                'pagination'       => array(
                    'per_page'              => $per_page,
                    'matched_page'          => $matched_page,
                    'matched_total_pages'   => $matched_pages,
                    'matched_filtered'      => $matched_filtered,
                    'unmatched_page'        => $unmatched_page,
                    'unmatched_total_pages' => $unmatched_pages,
                    'unmatched_filtered'    => $unmatched_filtered,
                    'search'                => $search_term,
                ),
                'stats'            => $datasets['stats'],
                'error'            => '',
            );
        } catch ( Throwable $e ) {
            $empty_report['error'] = 'Reconciliation scan error: ' . $e->getMessage();
            return $empty_report;
        }
    }
}

if ( ! function_exists( 'sywat_apply_target_reconcile_update' ) ) {
    /**
     * Apply gender and/or pro status update to a specific directory target key using scalar ID verification.
     *
     * @param string $target_key      Composite key ('head:<id>', 'dep:<head_id>:<sys_id>', or 'pool:<sys_id>').
     * @param string $new_gender      'male', 'female', or ''.
     * @param string $new_pro         'yes' or ''.
     * @param array  $unassigned_pool Reference to preloaded unassigned archers option array (batched write).
     * @param bool   $pool_dirty      Reference flag indicating whether unassigned pool was modified.
     * @return bool True if updated, false otherwise.
     */
    function sywat_apply_target_reconcile_update( $target_key, $new_gender, $new_pro, &$unassigned_pool, &$pool_dirty ) {
        global $wpdb;

        $parts = explode( ':', (string) $target_key );
        $type  = isset( $parts[0] ) ? $parts[0] : '';

        $valid_gender = ( $new_gender === 'male' || $new_gender === 'female' ) ? $new_gender : '';
        $valid_pro    = ( $new_pro === 'yes' ) ? 'yes' : '';

        if ( $valid_gender === '' && $valid_pro === '' ) {
            return false;
        }

        if ( $type === 'head' && ! empty( $parts[1] ) ) {
            $uid = intval( $parts[1] );
            if ( $uid <= 0 ) return false;

            // Scalar ID verification without hydrating full WP_User ORM objects
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $uid ) );
            if ( ! $exists ) return false;

            $changed = false;
            if ( $valid_gender !== '' ) {
                $curr_g = strtolower( (string) get_user_meta( $uid, 'sywat_gender', true ) );
                if ( $curr_g === '' || $curr_g === 'unassigned' ) {
                    update_user_meta( $uid, 'sywat_gender', $valid_gender );
                    $changed = true;
                }
            }
            if ( $valid_pro === 'yes' ) {
                $curr_p = strtolower( (string) get_user_meta( $uid, 'sywat_is_pro', true ) );
                if ( $curr_p !== 'yes' ) {
                    update_user_meta( $uid, 'sywat_is_pro', 'yes' );
                    $changed = true;
                }
            }
            return $changed;
        }

        if ( $type === 'dep' && ! empty( $parts[1] ) && ! empty( $parts[2] ) ) {
            $head_id = intval( $parts[1] );
            $sys_id  = sanitize_text_field( $parts[2] );
            $deps    = get_user_meta( $head_id, 'sywat_household_dependents', true );
            if ( ! is_array( $deps ) ) return false;

            $changed = false;
            foreach ( $deps as $idx => $dep ) {
                if ( isset( $dep['system_id'] ) && (string) $dep['system_id'] === $sys_id ) {
                    if ( $valid_gender !== '' ) {
                        $curr_g = isset( $dep['gender'] ) ? strtolower( (string) $dep['gender'] ) : 'unassigned';
                        if ( $curr_g === '' || $curr_g === 'unassigned' ) {
                            $deps[ $idx ]['gender'] = $valid_gender;
                            $changed = true;
                        }
                    }
                    if ( $valid_pro === 'yes' ) {
                        $curr_p = isset( $dep['is_pro'] ) ? strtolower( (string) $dep['is_pro'] ) : 'no';
                        if ( $curr_p !== 'yes' ) {
                            $deps[ $idx ]['is_pro'] = 'yes';
                            $changed = true;
                        }
                    }
                    break;
                }
            }

            if ( $changed ) {
                update_user_meta( $head_id, 'sywat_household_dependents', $deps );
            }
            return $changed;
        }

        if ( $type === 'pool' && ! empty( $parts[1] ) ) {
            $sys_id = sanitize_text_field( $parts[1] );
            if ( ! isset( $unassigned_pool[ $sys_id ] ) ) return false;

            $changed = false;
            if ( $valid_gender !== '' ) {
                $curr_g = isset( $unassigned_pool[ $sys_id ]['gender'] ) ? strtolower( (string) $unassigned_pool[ $sys_id ]['gender'] ) : 'unassigned';
                if ( $curr_g === '' || $curr_g === 'unassigned' ) {
                    $unassigned_pool[ $sys_id ]['gender'] = $valid_gender;
                    $changed = true;
                }
            }
            if ( $valid_pro === 'yes' ) {
                $curr_p = isset( $unassigned_pool[ $sys_id ]['is_pro'] ) ? strtolower( (string) $unassigned_pool[ $sys_id ]['is_pro'] ) : 'no';
                if ( $curr_p !== 'yes' ) {
                    $unassigned_pool[ $sys_id ]['is_pro'] = 'yes';
                    $changed = true;
                }
            }

            if ( $changed ) {
                $pool_dirty = true;
            }
            return $changed;
        }

        return false;
    }
}

if ( ! function_exists( 'sywat_reconcile_parse_target_key' ) ) {
    /**
     * Extract deterministic target_key from a datalist input value ("Display Name [head:12]" or "head:12").
     *
     * @param string $raw_input Raw text submitted from the datalist input field.
     * @return string Validated target_key or empty string.
     */
    function sywat_reconcile_parse_target_key( $raw_input ) {
        $val = trim( (string) $raw_input );
        if ( $val === '' ) return '';

        if ( preg_match( '/\[((?:head|dep|pool):[a-zA-Z0-9:_-]+)\]\s*$/', $val, $matches ) ) {
            return $matches[1];
        }
        if ( preg_match( '/^(?:head|dep|pool):[a-zA-Z0-9:_-]+$/', $val ) ) {
            return $val;
        }
        return '';
    }
}

if ( ! function_exists( 'sywat_handle_tfaa_reconcile_post' ) ) {
    /**
     * Nonce-verified, MySQL-transactional POST handler for applying TFAA Gender & Pro reconciliation updates.
     *
     * @return string Alert notice HTML.
     */
    function sywat_handle_tfaa_reconcile_post() {
        global $wpdb;

        if ( ! current_user_can( 'administrator' ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators can reconcile TFAA member data.</div>';
        }

        $reconcile_mode  = isset( $_POST['reconcile_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['reconcile_mode'] ) ) : 'selected';
        $updated_count   = 0;
        $mapped_count    = 0;
        $added_pool_cnt  = 0;
        $dismissed_count = 0;

        $ignored_names   = get_option( 'sywat_ignored_tfaa_names', array() );
        $unassigned_pool = get_option( 'sywat_unassigned_archers', array() );
        if ( ! is_array( $ignored_names ) ) $ignored_names = array();
        if ( ! is_array( $unassigned_pool ) ) $unassigned_pool = array();

        $ignored_changed = false;
        $pool_dirty      = false;

        wp_suspend_cache_invalidation( true );
        $wpdb->query( 'START TRANSACTION' );

        try {
            // 1A. Bulk apply ALL exact matches across the entire dataset in a single transaction
            if ( $reconcile_mode === 'apply_all_matched' ) {
                $datasets = sywat_reconcile_build_datasets();
                foreach ( $datasets['matched_updates'] as $row ) {
                    if ( sywat_apply_target_reconcile_update( $row['target_key'], $row['new_gender'], $row['new_is_pro'], $unassigned_pool, $pool_dirty ) ) {
                        $updated_count++;
                    }
                }
            } else {
                // 1B. Apply checked exact-match directory updates on current page
                $selected_targets = isset( $_POST['reconcile_targets'] ) && is_array( $_POST['reconcile_targets'] )
                    ? array_map( 'sanitize_text_field', wp_unslash( $_POST['reconcile_targets'] ) )
                    : array();
                $target_genders   = isset( $_POST['reconcile_target_gender'] ) && is_array( $_POST['reconcile_target_gender'] )
                    ? wp_unslash( $_POST['reconcile_target_gender'] )
                    : array();
                $target_pros      = isset( $_POST['reconcile_target_pro'] ) && is_array( $_POST['reconcile_target_pro'] )
                    ? wp_unslash( $_POST['reconcile_target_pro'] )
                    : array();

                foreach ( $selected_targets as $t_key ) {
                    $g_val = isset( $target_genders[ $t_key ] ) ? sanitize_text_field( $target_genders[ $t_key ] ) : '';
                    $p_val = isset( $target_pros[ $t_key ] ) ? sanitize_text_field( $target_pros[ $t_key ] ) : '';

                    if ( sywat_apply_target_reconcile_update( $t_key, $g_val, $p_val, $unassigned_pool, $pool_dirty ) ) {
                        $updated_count++;
                    }
                }

                // 2. Process unmatched TFAA archer actions on current page
                $unmatched_actions = isset( $_POST['unmatched_action'] ) && is_array( $_POST['unmatched_action'] )
                    ? wp_unslash( $_POST['unmatched_action'] )
                    : array();
                $unmatched_map_to  = isset( $_POST['unmatched_map_target'] ) && is_array( $_POST['unmatched_map_target'] )
                    ? wp_unslash( $_POST['unmatched_map_target'] )
                    : array();
                $unmatched_names   = isset( $_POST['unmatched_display_name'] ) && is_array( $_POST['unmatched_display_name'] )
                    ? wp_unslash( $_POST['unmatched_display_name'] )
                    : array();
                $unmatched_genders = isset( $_POST['unmatched_gender'] ) && is_array( $_POST['unmatched_gender'] )
                    ? wp_unslash( $_POST['unmatched_gender'] )
                    : array();
                $unmatched_pros    = isset( $_POST['unmatched_pro'] ) && is_array( $_POST['unmatched_pro'] )
                    ? wp_unslash( $_POST['unmatched_pro'] )
                    : array();

                foreach ( $unmatched_actions as $norm_key_raw => $act_raw ) {
                    $norm_key = sywat_reconcile_normalize_name( $norm_key_raw );
                    $act      = sanitize_text_field( $act_raw );
                    if ( $norm_key === '' || $act === '' || $act === 'ignore' ) {
                        continue;
                    }

                    $disp_name = isset( $unmatched_names[ $norm_key_raw ] ) ? sanitize_text_field( $unmatched_names[ $norm_key_raw ] ) : ucwords( $norm_key );
                    $g_val     = isset( $unmatched_genders[ $norm_key_raw ] ) ? sanitize_text_field( $unmatched_genders[ $norm_key_raw ] ) : '';
                    $p_val     = isset( $unmatched_pros[ $norm_key_raw ] ) ? sanitize_text_field( $unmatched_pros[ $norm_key_raw ] ) : '';

                    if ( $act === 'map_member' ) {
                        $raw_map_val = isset( $unmatched_map_to[ $norm_key_raw ] ) ? sanitize_text_field( $unmatched_map_to[ $norm_key_raw ] ) : '';
                        $map_target  = sywat_reconcile_parse_target_key( $raw_map_val );
                        if ( $map_target !== '' && sywat_apply_target_reconcile_update( $map_target, $g_val, $p_val, $unassigned_pool, $pool_dirty ) ) {
                            $mapped_count++;
                        }
                    } elseif ( $act === 'add_unassigned' ) {
                        $name_parts = preg_split( '/\s+/', trim( $disp_name ), 2 );
                        $first_name = isset( $name_parts[0] ) ? ucfirst( strtolower( $name_parts[0] ) ) : '';
                        $last_name  = isset( $name_parts[1] ) ? ucwords( strtolower( $name_parts[1] ) ) : '';

                        if ( $first_name !== '' ) {
                            $sys_id = function_exists( 'sywat_generate_unique_system_id' )
                                ? sywat_generate_unique_system_id()
                                : (string) wp_rand( 100000, 999999 );

                            $unassigned_pool[ $sys_id ] = array(
                                'system_id'       => $sys_id,
                                'first_name'      => $first_name,
                                'last_name'       => $last_name,
                                'display_name'    => trim( $first_name . ' ' . $last_name ),
                                'email'           => '',
                                'phone'           => '',
                                'gender'          => ( $g_val === 'male' || $g_val === 'female' ) ? $g_val : 'unassigned',
                                'is_pro'          => ( $p_val === 'yes' ) ? 'yes' : 'no',
                                'dob'             => '',
                                'accommodations'  => 'none',
                                'nfaa_number'     => '',
                                'nfaa_expiration' => '',
                                'source'          => 'tfaa_reconcile_poc',
                                'created_at'      => current_time( 'mysql' ),
                            );
                            $pool_dirty = true;
                            $added_pool_cnt++;
                        }
                    } elseif ( $act === 'dismiss' ) {
                        $ignored_names[ $norm_key ] = current_time( 'mysql' );
                        $ignored_changed = true;
                        $dismissed_count++;
                    }
                }
            }

            if ( $ignored_changed ) {
                update_option( 'sywat_ignored_tfaa_names', $ignored_names, false );
            }
            if ( $pool_dirty ) {
                update_option( 'sywat_unassigned_archers', $unassigned_pool, false );
            }

            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            wp_suspend_cache_invalidation( false );
            wp_cache_flush();
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Reconciliation transaction rolled back due to an error: ' . esc_html( $e->getMessage() ) . '</div>';
        }

        wp_suspend_cache_invalidation( false );
        wp_cache_flush();

        return sprintf(
            '<div id="sywat-notice" class="sywat-alert sywat-alert-success"><strong>TFAA Reconciliation Applied:</strong> Updated <strong>%d</strong> directory member(s), mapped <strong>%d</strong> nickname match(es), added <strong>%d</strong> archer(s) to Unassigned Pool, and dismissed <strong>%d</strong> name(s).</div>',
            $updated_count,
            $mapped_count,
            $added_pool_cnt,
            $dismissed_count
        );
    }
}
