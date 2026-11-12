<?php
/**
 * Sub-Module: Scores Engine Action Handlers
 * Path: includes/scores/scores-actions.php
 * Description: Pure POST request processing, AJAX autosave endpoints, batch score saves, and line lock management.
 * Author: SYWAT Archery
 * Version: 11.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles real-time single score AJAX auto-save request from client.
 */
if ( ! function_exists( 'sywat_scores_handle_ajax_autosave' ) ) {
    function sywat_scores_handle_ajax_autosave() {
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_action'] ) && $_POST['sywat_action'] === 'autosave_single_score' ) {
            if ( ! isset( $_POST['sywat_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ), 'sywat_scores_action' ) ) {
                wp_send_json_error( array( 'message' => 'Security token expired' ) );
            }

            $clean_id   = sanitize_text_field( wp_unslash( $_POST['reg_id'] ) );
            $score_str  = isset( $_POST['score'] ) ? trim( wp_unslash( $_POST['score'] ) ) : '';
            $x_str      = isset( $_POST['x_count'] ) ? trim( wp_unslash( $_POST['x_count'] ) ) : '';
            $tourn_name = isset( $_POST['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) ) : '';

            if ( $score_str !== '' && ( intval( $score_str ) < 0 || intval( $score_str ) > 300 ) ) {
                wp_send_json_error( array( 'message' => 'Score out of range (0-300)' ) );
            }
            if ( $x_str !== '' && ( intval( $x_str ) < 0 || intval( $x_str ) > 60 ) ) {
                wp_send_json_error( array( 'message' => 'X count out of range (0-60)' ) );
            }

            $active_t_slug = sanitize_title( $tourn_name );
            $opt_key       = 'sywat_t_' . $active_t_slug . '_' . $clean_id;
            $reg           = get_option( $opt_key );

            wp_cache_delete( 'sywat_locks_' . $active_t_slug, 'options' );
            $locked_lines = get_option( 'sywat_locks_' . $active_t_slug, array() );

            if ( $reg ) {
                $line_idx = isset( $reg['line_index'] ) ? (string)$reg['line_index'] : '';
                if ( ! empty( $locked_lines[$line_idx] ) ) {
                    wp_send_json_error( array( 'message' => 'Line is locked' ) );
                }

                $reg['score']   = ( $score_str !== '' ) ? intval( $score_str ) : 0;
                $reg['x_count'] = ( $x_str !== '' )     ? intval( $x_str )     : 0;

                update_option( $opt_key, $reg, false );

                $scores_map = get_option( 'sywat_scores_' . $active_t_slug, array() );
                if ( ! is_array( $scores_map ) ) $scores_map = array();
                $scores_map[$clean_id] = array(
                    'score'    => $reg['score'],
                    'xs'       => $reg['x_count'],
                    'saved_at' => current_time( 'mysql' ),
                );
                update_option( 'sywat_scores_' . $active_t_slug, $scores_map, false );

                wp_send_json_success( array( 'reg_id' => $clean_id, 'message' => 'Saved' ) );
            }

            wp_send_json_error( array( 'message' => 'Archer record not found' ) );
        }
    }
}

/**
 * Processes batch score save, line locks, and score reset POST requests.
 */
if ( ! function_exists( 'sywat_scores_handle_batch_actions' ) ) {
    function sywat_scores_handle_batch_actions( $selected_tournament, $selected_line_idx, $is_admin, &$locked_lines, &$current_view_locked ) {
        global $wpdb;
        $notice = '';

        $is_valid_nonce = ( isset( $_POST['sywat_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ), 'sywat_scores_action' ) );
        $action         = isset( $_POST['sywat_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) ) : '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $action && ! $is_valid_nonce && $action !== 'autosave_single_score' ) {
            return '<div class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce ) {
            $active_t_slug = sanitize_title( $selected_tournament );

            // Action: Toggle Line Lock
            if ( $action === 'toggle_lock' ) {
                if ( ! $is_admin ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">Access denied. Administrator privileges required to lock/unlock lines.</div>';
                } elseif ( $selected_line_idx === 'all' ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">Please filter to a specific shooting line first to lock or unlock it.</div>';
                } else {
                    if ( ! empty( $locked_lines[$selected_line_idx] ) ) {
                        unset( $locked_lines[$selected_line_idx] );
                        $notice = '<div class="sywat-alert sywat-alert-success">Line unlocked. Scores can now be edited.</div>';
                    } else {
                        $locked_lines[$selected_line_idx] = 1;
                        $notice = '<div class="sywat-alert sywat-alert-success">Line locked. Scores on this line are frozen and cannot be edited until unlocked.</div>';
                    }

                    delete_option( 'sywat_locks_' . $active_t_slug );
                    add_option( 'sywat_locks_' . $active_t_slug, $locked_lines, '', 'no' );
                    
                    update_option( 'sywat_current_tournament', $selected_tournament, false );
                    $current_view_locked = ! empty( $locked_lines[$selected_line_idx] );
                }
            }

            // Action: Reset All Scores to 0
            if ( $action === 'reset_scores' ) {
                if ( ! $is_admin ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">Access denied. Administrator privileges required to reset scores.</div>';
                } else {
                    $reset_count = 0;
                    $all_rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $wpdb->esc_like( 'sywat_t_' . $active_t_slug . '_' ) . '%'
                    ) );

                    $scores_map = get_option( 'sywat_scores_' . $active_t_slug, array() );
                    if ( ! is_array( $scores_map ) ) $scores_map = array();

                    foreach ( $all_rows as $row ) {
                        $reg = maybe_unserialize( $row->option_value );
                        if ( is_array( $reg ) && isset( $reg['id'] ) ) {
                            $match_tourn   = isset( $reg['tournament'] ) && $reg['tournament'] === $selected_tournament;
                            $match_line    = ( $selected_line_idx === 'all' ) || ( isset( $reg['line_index'] ) && (string)$reg['line_index'] === (string)$selected_line_idx );
                            $is_checked_in = ! empty( $reg['checked_in'] );
                            $is_locked     = ! empty( $locked_lines[$reg['line_index']] );

                            if ( $match_tourn && $match_line && $is_checked_in && ! $is_locked ) {
                                $opt_key = 'sywat_t_' . $active_t_slug . '_' . $reg['id'];
                                $reg['score']   = 0;
                                $reg['x_count'] = 0;
                                update_option( $opt_key, $reg, false );

                                $scores_map[$reg['id']] = array(
                                    'score'    => 0,
                                    'xs'       => 0,
                                    'saved_at' => current_time( 'mysql' ),
                                );

                                $reset_count++;
                            }
                        }
                    }

                    update_option( 'sywat_scores_' . $active_t_slug, $scores_map, false );
                    $notice = '<div class="sywat-alert sywat-alert-success">Successfully reset scores and X counts to 0 for <strong>' . $reset_count . '</strong> unlocked, checked-in archer(s).</div>';
                }
            }

            // Action: Save Batch Scores
            if ( $action === 'save_scores' ) {
                $has_invalid_data = false;
                
                $submitted_ids = array_unique( array_merge(
                    isset( $_POST['scores'] ) && is_array( $_POST['scores'] ) ? array_keys( $_POST['scores'] ) : array(),
                    isset( $_POST['x_counts'] ) && is_array( $_POST['x_counts'] ) ? array_keys( $_POST['x_counts'] ) : array()
                ) );

                foreach ( $submitted_ids as $id ) {
                    $clean_id = sanitize_text_field( wp_unslash( $id ) );
                    if ( isset( $_POST['scores'][$id] ) ) {
                        $score_str = trim( wp_unslash( $_POST['scores'][$id] ) );
                        if ( $score_str !== '' ) {
                            $s_val = intval( $score_str );
                            if ( $s_val < 0 || $s_val > 300 ) { $has_invalid_data = true; break; }
                        }
                    }
                    if ( isset( $_POST['x_counts'][$id] ) ) {
                        $x_str = trim( wp_unslash( $_POST['x_counts'][$id] ) );
                        if ( $x_str !== '' ) {
                            $x_val = intval( $x_str );
                            if ( $x_val < 0 || $x_val > 60 ) { $has_invalid_data = true; break; }
                        }
                    }
                }

                if ( $has_invalid_data ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">⚠️ <strong>Save Rejected!</strong> One or more values were invalid. Scores must be 0–300 and X Counts 0–60. Please correct highlighted fields and save again.</div>';
                } elseif ( empty( $submitted_ids ) ) {
                    $notice = '<div class="sywat-alert sywat-alert-success">No changes were made.</div>';
                } else {
                    $saved_count = 0;
                    $scores_map  = get_option( 'sywat_scores_' . $active_t_slug, array() );
                    if ( ! is_array( $scores_map ) ) $scores_map = array();

                    foreach ( $submitted_ids as $id ) {
                        $clean_id = sanitize_text_field( wp_unslash( $id ) );
                        $opt_key  = 'sywat_t_' . $active_t_slug . '_' . $clean_id;
                        $reg      = get_option( $opt_key );

                        if ( $reg ) {
                            if ( ! empty( $locked_lines[$reg['line_index']] ) ) {
                                continue;
                            }

                            if ( isset( $_POST['scores'][$id] ) ) {
                                $raw_score = trim( wp_unslash( $_POST['scores'][$id] ) );
                                $reg['score'] = ( $raw_score !== '' ) ? intval( $raw_score ) : 0;
                            }
                            if ( isset( $_POST['x_counts'][$id] ) ) {
                                $raw_x = trim( wp_unslash( $_POST['x_counts'][$id] ) );
                                $reg['x_count'] = ( $raw_x !== '' ) ? intval( $raw_x ) : 0;
                            }

                            update_option( $opt_key, $reg, false );

                            $scores_map[$clean_id] = array(
                                'score'    => isset( $reg['score'] ) ? intval( $reg['score'] ) : 0,
                                'xs'       => isset( $reg['x_count'] ) ? intval( $reg['x_count'] ) : 0,
                                'saved_at' => current_time( 'mysql' ),
                            );

                            $saved_count++;
                        }
                    }

                    update_option( 'sywat_scores_' . $active_t_slug, $scores_map, false );
                    
                    if ( $saved_count > 0 ) {
                        $notice = '<div class="sywat-alert sywat-alert-success">Successfully updated scores for <strong>' . $saved_count . '</strong> unlocked archer(s)!</div>';
                    }
                }
            }
        }

        return $notice;
    }
}
