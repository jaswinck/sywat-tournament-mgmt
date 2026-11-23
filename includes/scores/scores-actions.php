<?php
/**
 * Sub-Module: Scores Engine Action Handlers
 * Path: includes/scores/scores-actions.php
 * Description: Isolated per-archer JSON AJAX autosave endpoints (admin-ajax.php primary + template_redirect fallback) serialized with per-tournament MySQL advisory locks and explicit object-cache invalidation to eliminate multi-scorekeeper and rapid-tabbing overwrites, line lock toggles for the Line Score Tracker, and inline validation feedback when CSV export is requested with no locked lines.
 * Compliance: Pure action layer — zero output at include time, atomic per-tournament advisory locking + object cache invalidation on option read-modify-write, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 11.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles real-time single-score (and incomplete round "INC") AJAX auto-save request from client.
 * Uses a per-tournament MySQL advisory lock and explicit WordPress object cache invalidation
 * before reading and updating options so concurrent scorekeepers or rapid field transitions
 * never overwrite another archer's saved score.
 *
 * @return void
 */
if ( ! function_exists( 'sywat_scores_handle_ajax_autosave' ) ) {
    function sywat_scores_handle_ajax_autosave() {
        $req_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
        $post_act   = isset( $_POST['sywat_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) ) : '';
        $ajax_act   = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

        if ( 'POST' !== $req_method || ( 'autosave_single_score' !== $post_act && 'sywat_autosave_single_score' !== $ajax_act ) ) {
            return;
        }

        if ( ! isset( $_POST['sywat_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ), 'sywat_scores_action' ) ) {
            wp_send_json_error( array( 'message' => 'Security token expired' ), 403 );
        }

        if ( ! function_exists( 'sywat_can_edit_scores' ) || ! sywat_can_edit_scores() ) {
            wp_send_json_error( array( 'message' => 'Access denied' ), 403 );
        }

        $clean_id   = isset( $_POST['reg_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reg_id'] ) ) : '';
        $score_str  = isset( $_POST['score'] ) ? trim( wp_unslash( $_POST['score'] ) ) : '';
        $x_str      = isset( $_POST['x_count'] ) ? trim( wp_unslash( $_POST['x_count'] ) ) : '';
        $tourn_name = isset( $_POST['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) ) : '';

        if ( '' === $clean_id || '' === $tourn_name ) {
            wp_send_json_error( array( 'message' => 'Missing archer or tournament identifier' ), 400 );
        }

        if ( '' !== $score_str && ( intval( $score_str ) < 0 || intval( $score_str ) > 300 ) ) {
            wp_send_json_error( array( 'message' => 'Score out of range (0-300)' ), 400 );
        }
        if ( '' !== $x_str && ( intval( $x_str ) < 0 || intval( $x_str ) > 60 ) ) {
            wp_send_json_error( array( 'message' => 'X count out of range (0-60)' ), 400 );
        }

        global $wpdb;

        $active_t_slug = sanitize_title( $tourn_name );
        $opt_key       = 'sywat_t_' . $active_t_slug . '_' . $clean_id;
        $scores_key    = 'sywat_scores_' . $active_t_slug;
        $locks_key     = 'sywat_locks_' . $active_t_slug;
        $master_key    = 'sywat_registrations_' . $active_t_slug;
        $lock_name     = 'sywat_score_lock_' . substr( md5( $active_t_slug ), 0, 24 );

        $lock_acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock_name ) );
        if ( null !== $lock_acquired && '1' !== (string) $lock_acquired ) {
            wp_send_json_error( array( 'message' => 'Server busy saving another score, please retry' ), 409 );
        }

        try {
            wp_cache_delete( $opt_key, 'options' );
            wp_cache_delete( $locks_key, 'options' );
            wp_cache_delete( $scores_key, 'options' );
            wp_cache_delete( $master_key, 'options' );

            $reg          = get_option( $opt_key );
            $locked_lines = get_option( $locks_key, array() );
            if ( ! is_array( $locked_lines ) ) {
                $locked_lines = array();
            }

            if ( ! $reg || ! is_array( $reg ) ) {
                wp_send_json_error( array( 'message' => 'Archer record not found' ), 404 );
            }

            $line_idx = isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '';
            if ( ! empty( $locked_lines[ $line_idx ] ) ) {
                wp_send_json_error( array( 'message' => 'Line is locked' ), 423 );
            }

            $reg['score']   = ( '' !== $score_str ) ? intval( $score_str ) : 0;
            $reg['x_count'] = ( '' !== $x_str ) ? intval( $x_str ) : 0;

            if ( isset( $_POST['is_inc'] ) ) {
                $raw_inc       = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['is_inc'] ) ) ) );
                $reg['is_inc'] = ( '1' === $raw_inc || 'true' === $raw_inc ) ? 1 : 0;
            } else {
                $reg['is_inc'] = ! empty( $reg['is_inc'] ) ? 1 : 0;
            }

            update_option( $opt_key, $reg, false );

            $scores_map = get_option( $scores_key, array() );
            if ( ! is_array( $scores_map ) ) {
                $scores_map = array();
            }
            $scores_map[ $clean_id ] = array(
                'score'    => $reg['score'],
                'xs'       => $reg['x_count'],
                'is_inc'   => $reg['is_inc'],
                'saved_at' => current_time( 'mysql' ),
            );
            update_option( $scores_key, $scores_map, false );

            $master_regs = get_option( $master_key, array() );
            if ( is_array( $master_regs ) && isset( $master_regs[ $clean_id ] ) ) {
                $master_regs[ $clean_id ] = $reg;
                update_option( $master_key, $master_regs, false );
            }

            $response_payload = array(
                'reg_id'  => $clean_id,
                'score'   => $reg['score'],
                'x_count' => $reg['x_count'],
                'is_inc'  => $reg['is_inc'],
                'message' => 'Saved',
            );
        } finally {
            $wpdb->query( $wpdb->prepare( 'DO RELEASE_LOCK( %s )', $lock_name ) );
        }

        wp_send_json_success( $response_payload );
    }
}

/**
 * Processes shooting line lock toggles from the Line Score Tracker and returns an inline
 * error banner when CSV export is requested with no locked shooting lines.
 *
 * @param string $selected_tournament Active tournament name.
 * @param string $selected_line_idx   Active shooting line index or 'all'.
 * @param bool   $is_admin            Whether current user has score administrative privileges.
 * @param array  $locked_lines        Map of locked line indices (passed by reference).
 * @param bool   $current_view_locked Whether active single-line view is locked (passed by reference).
 * @return string                     Formatted alert banner HTML or empty string.
 */
if ( ! function_exists( 'sywat_scores_handle_batch_actions' ) ) {
    function sywat_scores_handle_batch_actions( $selected_tournament, $selected_line_idx, $is_admin, &$locked_lines, &$current_view_locked ) {
        $notice = '';

        $is_valid_nonce = ( isset( $_POST['sywat_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ), 'sywat_scores_action' ) );
        $action         = isset( $_POST['sywat_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) ) : '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $action && ! $is_valid_nonce && 'autosave_single_score' !== $action ) {
            return '<div class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce ) {
            $active_t_slug = sanitize_title( $selected_tournament );

            // Action: Export CSV requested with no locked lines (early exporter returned without streaming)
            if ( 'export_csv' === $action ) {
                if ( ! $is_admin ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">Access denied. Administrator or Shop Manager privileges required to export CSV.</div>';
                } elseif ( empty( $locked_lines ) ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">⚠️ At least one shooting line must be locked before exporting CSV.</div>';
                }
            }

            // Action: Toggle Line Lock (from Line Score Tracker cards)
            if ( 'toggle_lock' === $action ) {
                if ( ! $is_admin ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">Access denied. Administrator privileges required to lock/unlock lines.</div>';
                } elseif ( 'all' === $selected_line_idx || '' === $selected_line_idx ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">Please select a specific shooting line to lock or unlock.</div>';
                } else {
                    if ( ! empty( $locked_lines[ $selected_line_idx ] ) ) {
                        unset( $locked_lines[ $selected_line_idx ] );
                        $notice = '<div class="sywat-alert sywat-alert-success">Line unlocked. Scores can now be edited.</div>';
                    } else {
                        $locked_lines[ $selected_line_idx ] = 1;
                        $notice = '<div class="sywat-alert sywat-alert-success">Line locked. Scores on this line are frozen and cannot be edited until unlocked.</div>';
                    }

                    delete_option( 'sywat_locks_' . $active_t_slug );
                    add_option( 'sywat_locks_' . $active_t_slug, $locked_lines, '', 'no' );
                    wp_cache_delete( 'sywat_locks_' . $active_t_slug, 'options' );

                    update_option( 'sywat_current_tournament', $selected_tournament, false );
                    $current_view_locked = ! empty( $locked_lines[ $selected_line_idx ] );
                }
            }
        }

        return $notice;
    }
}

// Primary WordPress AJAX endpoint for isolated per-archer score saves
add_action( 'wp_ajax_sywat_autosave_single_score', 'sywat_scores_handle_ajax_autosave' );

// Front-end template_redirect fallback endpoint for backward compatibility
add_action(
    'template_redirect',
    function () {
        if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' )
            || ! isset( $_POST['sywat_action'] ) || 'autosave_single_score' !== $_POST['sywat_action'] ) {
            return;
        }
        sywat_scores_handle_ajax_autosave();
    },
    0
);
