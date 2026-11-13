<?php
/**
 * POC Sub-Module: TFAA Gender & Pro Status Reconciliation Engine
 * Path: includes/members/reconcile/tfaa-reconcile-engine.php
 * Description: Temporary POC utility comparing synced TFAA shooter records (sywat_shooter_genders_cache and tournament registration batches) against the unified Member Directory (Primary Heads, Household Dependents, and Unassigned Pool) to backfill missing gender/pro attributes and surface unmatched TFAA names.
 * Compliance: Zero HTML layout templates. Returns standardized data arrays and alert notices. Self-contained for easy removal post-POC.
 * Author: SYWAT Archery
 * Version: 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_reconcile_normalize_name' ) ) {
    /**
     * Normalize archer name identically to sywat-tfaa-sync.php for deterministic matching.
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

if ( ! function_exists( 'sywat_get_tfaa_reconciliation_report' ) ) {
    /**
     * Build on-demand reconciliation audit comparing TFAA synced data against all flat directory archers.
     *
     * @param array|null $preloaded_archers Optional pre-fetched flat archer array to avoid duplicate user queries.
     * @return array Structured report containing 'matched_updates', 'unmatched_names', 'mappable_members', 'stats', and optional 'error'.
     */
    function sywat_get_tfaa_reconciliation_report( $preloaded_archers = null ) {
        global $wpdb;

        $empty_report = array(
            'matched_updates'  => array(),
            'unmatched_names'  => array(),
            'mappable_members' => array(),
            'stats'            => array(
                'tfaa_total'     => 0,
                'ready_updates'  => 0,
                'unmatched'      => 0,
                'already_synced' => 0,
            ),
            'error'            => '',
        );

        try {
            $gender_cache  = get_option( 'sywat_shooter_genders_cache', array() );
            $ignored_names = get_option( 'sywat_ignored_tfaa_names', array() );
            if ( ! is_array( $gender_cache ) ) $gender_cache = array();
            if ( ! is_array( $ignored_names ) ) $ignored_names = array();

            $tfaa_pool = array();

            foreach ( $gender_cache as $norm_key => $raw_gender ) {
                $clean_key = sywat_reconcile_normalize_name( $norm_key );
                if ( empty( $clean_key ) ) continue;

                $g_lower = strtolower( trim( (string) $raw_gender ) );
                $gender  = in_array( $g_lower, array( 'male', 'female' ), true ) ? $g_lower : '';

                $tfaa_pool[ $clean_key ] = array(
                    'norm_name'      => $clean_key,
                    'display_name'   => ucwords( $clean_key ),
                    'gender'         => $gender,
                    'is_pro'         => 'no',
                    'seen_non_guest' => false,
                );
            }

            // Lightweight query against tournament master registration options (sywat_registrations_*) instead of scanning all wp_options rows
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
                        if ( empty( $norm_key ) ) continue;

                        $r_type   = isset( $reg['type'] ) ? strtolower( trim( (string) $reg['type'] ) ) : '';
                        $r_style  = isset( $reg['style'] ) ? trim( (string) $reg['style'] ) : '';
                        $r_gender = isset( $reg['gender'] ) ? strtolower( trim( (string) $reg['gender'] ) ) : '';
                        $is_guest = ( $r_type === 'guest' || stripos( $r_style, 'guest' ) !== false || stripos( $raw_name, '[GUEST]' ) !== false );
                        $is_pro   = ( ! $is_guest && stripos( $r_style, 'pro' ) !== false );

                        if ( ! isset( $tfaa_pool[ $norm_key ] ) ) {
                            if ( $is_guest ) {
                                continue;
                            }
                            $tfaa_pool[ $norm_key ] = array(
                                'norm_name'      => $norm_key,
                                'display_name'   => trim( preg_replace( '/\[.*?\]/', '', $raw_name ) ),
                                'gender'         => in_array( $r_gender, array( 'male', 'female' ), true ) ? $r_gender : '',
                                'is_pro'         => $is_pro ? 'yes' : 'no',
                                'seen_non_guest' => true,
                            );
                        } else {
                            if ( ! $is_guest ) {
                                $tfaa_pool[ $norm_key ]['seen_non_guest'] = true;
                                $clean_title = trim( preg_replace( '/\[.*?\]/', '', $raw_name ) );
                                if ( ! empty( $clean_title ) ) {
                                    $tfaa_pool[ $norm_key ]['display_name'] = $clean_title;
                                }
                                if ( empty( $tfaa_pool[ $norm_key ]['gender'] ) && in_array( $r_gender, array( 'male', 'female' ), true ) ) {
                                    $tfaa_pool[ $norm_key ]['gender'] = $r_gender;
                                }
                                if ( $is_pro ) {
                                    $tfaa_pool[ $norm_key ]['is_pro'] = 'yes';
                                }
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
                $norm_key = sywat_reconcile_normalize_name( isset( $arch['display_name'] ) ? $arch['display_name'] : '' );
                if ( empty( $norm_key ) ) continue;

                $a_type   = isset( $arch['account_type'] ) ? $arch['account_type'] : 'head';
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
                    'user_id'      => $a_uid,
                    'system_id'    => $a_sys_id,
                    'head_user_id' => $a_head,
                    'head_name'    => isset( $arch['head_name'] ) ? $arch['head_name'] : '',
                    'display_name' => $arch['display_name'],
                    'gender'       => ( $a_gender === 'male' || $a_gender === 'female' ) ? $a_gender : 'unassigned',
                    'is_pro'       => ( $a_pro === 'yes' ) ? 'yes' : 'no',
                );

                $directory_by_key[ $norm_key ][] = $record;

                if ( $record['gender'] === 'unassigned' || $record['is_pro'] === 'no' ) {
                    $mappable_members[] = $record;
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
                        $needs_gender = ( $dir_rec['gender'] === 'unassigned' && ! empty( $tfaa_item['gender'] ) );
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
                } else {
                    if ( ! empty( $tfaa_item['gender'] ) || $tfaa_item['is_pro'] === 'yes' ) {
                        $unmatched_names[] = array(
                            'norm_name'    => $norm_key,
                            'display_name' => $tfaa_item['display_name'],
                            'gender'       => $tfaa_item['gender'],
                            'is_pro'       => $tfaa_item['is_pro'],
                        );
                    }
                }
            }

            usort( $matched_updates, function( $a, $b ) {
                return strcasecmp( $a['display_name'], $b['display_name'] );
            } );

            usort( $unmatched_names, function( $a, $b ) {
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
     * Apply gender and/or pro status update to a specific directory target key.
     *
     * @param string $target_key Composite key ('head:<id>', 'dep:<head_id>:<sys_id>', or 'pool:<sys_id>').
     * @param string $new_gender 'male', 'female', or ''.
     * @param string $new_pro    'yes' or ''.
     * @return bool True if updated, false otherwise.
     */
    function sywat_apply_target_reconcile_update( $target_key, $new_gender, $new_pro ) {
        $parts = explode( ':', (string) $target_key );
        $type  = isset( $parts[0] ) ? $parts[0] : '';

        $valid_gender = in_array( $new_gender, array( 'male', 'female' ), true ) ? $new_gender : '';
        $valid_pro    = ( $new_pro === 'yes' ) ? 'yes' : '';

        if ( empty( $valid_gender ) && empty( $valid_pro ) ) {
            return false;
        }

        if ( $type === 'head' && ! empty( $parts[1] ) ) {
            $uid = intval( $parts[1] );
            if ( $uid <= 0 || ! get_userdata( $uid ) ) return false;

            if ( ! empty( $valid_gender ) ) {
                $curr_g = strtolower( (string) get_user_meta( $uid, 'sywat_gender', true ) );
                if ( empty( $curr_g ) || $curr_g === 'unassigned' ) {
                    update_user_meta( $uid, 'sywat_gender', $valid_gender );
                }
            }
            if ( $valid_pro === 'yes' ) {
                update_user_meta( $uid, 'sywat_is_pro', 'yes' );
            }
            return true;
        }

        if ( $type === 'dep' && ! empty( $parts[1] ) && ! empty( $parts[2] ) ) {
            $head_id = intval( $parts[1] );
            $sys_id  = sanitize_text_field( $parts[2] );
            $deps    = get_user_meta( $head_id, 'sywat_household_dependents', true );
            if ( ! is_array( $deps ) ) return false;

            $changed = false;
            foreach ( $deps as $idx => $dep ) {
                if ( isset( $dep['system_id'] ) && (string) $dep['system_id'] === $sys_id ) {
                    if ( ! empty( $valid_gender ) ) {
                        $curr_g = isset( $dep['gender'] ) ? strtolower( (string) $dep['gender'] ) : 'unassigned';
                        if ( empty( $curr_g ) || $curr_g === 'unassigned' ) {
                            $deps[ $idx ]['gender'] = $valid_gender;
                            $changed = true;
                        }
                    }
                    if ( $valid_pro === 'yes' ) {
                        $deps[ $idx ]['is_pro'] = 'yes';
                        $changed = true;
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
            $pool   = get_option( 'sywat_unassigned_archers', array() );
            if ( ! is_array( $pool ) || ! isset( $pool[ $sys_id ] ) ) return false;

            if ( ! empty( $valid_gender ) ) {
                $curr_g = isset( $pool[ $sys_id ]['gender'] ) ? strtolower( (string) $pool[ $sys_id ]['gender'] ) : 'unassigned';
                if ( empty( $curr_g ) || $curr_g === 'unassigned' ) {
                    $pool[ $sys_id ]['gender'] = $valid_gender;
                }
            }
            if ( $valid_pro === 'yes' ) {
                $pool[ $sys_id ]['is_pro'] = 'yes';
            }

            update_option( 'sywat_unassigned_archers', $pool, false );
            return true;
        }

        return false;
    }
}

if ( ! function_exists( 'sywat_handle_tfaa_reconcile_post' ) ) {
    /**
     * Nonce-verified POST handler for applying TFAA Gender & Pro reconciliation updates.
     *
     * @return string Alert notice HTML.
     */
    function sywat_handle_tfaa_reconcile_post() {
        if ( ! current_user_can( 'administrator' ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators can reconcile TFAA member data.</div>';
        }

        $updated_count   = 0;
        $mapped_count    = 0;
        $added_pool_cnt  = 0;
        $dismissed_count = 0;

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

            if ( sywat_apply_target_reconcile_update( $t_key, $g_val, $p_val ) ) {
                $updated_count++;
            }
        }

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

        $ignored_names   = get_option( 'sywat_ignored_tfaa_names', array() );
        $unassigned_pool = get_option( 'sywat_unassigned_archers', array() );
        if ( ! is_array( $ignored_names ) ) $ignored_names = array();
        if ( ! is_array( $unassigned_pool ) ) $unassigned_pool = array();

        $ignored_changed = false;
        $pool_changed    = false;

        foreach ( $unmatched_actions as $norm_key_raw => $act_raw ) {
            $norm_key = sywat_reconcile_normalize_name( $norm_key_raw );
            $act      = sanitize_text_field( $act_raw );
            if ( empty( $norm_key ) || $act === '' || $act === 'ignore' ) {
                continue;
            }

            $disp_name = isset( $unmatched_names[ $norm_key_raw ] ) ? sanitize_text_field( $unmatched_names[ $norm_key_raw ] ) : ucwords( $norm_key );
            $g_val     = isset( $unmatched_genders[ $norm_key_raw ] ) ? sanitize_text_field( $unmatched_genders[ $norm_key_raw ] ) : '';
            $p_val     = isset( $unmatched_pros[ $norm_key_raw ] ) ? sanitize_text_field( $unmatched_pros[ $norm_key_raw ] ) : '';

            if ( $act === 'map_member' ) {
                $map_target = isset( $unmatched_map_to[ $norm_key_raw ] ) ? sanitize_text_field( $unmatched_map_to[ $norm_key_raw ] ) : '';
                if ( ! empty( $map_target ) && sywat_apply_target_reconcile_update( $map_target, $g_val, $p_val ) ) {
                    $mapped_count++;
                }
            } elseif ( $act === 'add_unassigned' ) {
                $name_parts = preg_split( '/\s+/', trim( $disp_name ), 2 );
                $first_name = isset( $name_parts[0] ) ? ucfirst( strtolower( $name_parts[0] ) ) : '';
                $last_name  = isset( $name_parts[1] ) ? ucwords( strtolower( $name_parts[1] ) ) : '';

                if ( ! empty( $first_name ) ) {
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
                        'gender'          => in_array( $g_val, array( 'male', 'female' ), true ) ? $g_val : 'unassigned',
                        'is_pro'          => ( $p_val === 'yes' ) ? 'yes' : 'no',
                        'dob'             => '',
                        'accommodations'  => 'none',
                        'nfaa_number'     => '',
                        'nfaa_expiration' => '',
                        'source'          => 'tfaa_reconcile_poc',
                        'created_at'      => current_time( 'mysql' ),
                    );
                    $pool_changed = true;
                    $added_pool_cnt++;
                }
            } elseif ( $act === 'dismiss' ) {
                $ignored_names[ $norm_key ] = current_time( 'mysql' );
                $ignored_changed = true;
                $dismissed_count++;
            }
        }

        if ( $ignored_changed ) {
            update_option( 'sywat_ignored_tfaa_names', $ignored_names, false );
        }
        if ( $pool_changed ) {
            update_option( 'sywat_unassigned_archers', $unassigned_pool, false );
        }

        return sprintf(
            '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🧬 <strong>TFAA Reconciliation Applied:</strong> Updated <strong>%d</strong> directory member(s), mapped <strong>%d</strong> nickname match(es), added <strong>%d</strong> archer(s) to Unassigned Pool, and dismissed <strong>%d</strong> name(s).</div>',
            $updated_count,
            $mapped_count,
            $added_pool_cnt,
            $dismissed_count
        );
    }
}
