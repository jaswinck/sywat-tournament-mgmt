<?php
/**
 * Sub-Module: Check-In Desk AJAX Endpoints
 * Path: includes/checkin/checkin-ajax.php
 * Description: Registers authenticated admin-ajax.php JSON endpoints (wp_ajax_sywat_desk_checkin and wp_ajax_sywat_desk_undo_checkin) so Check-In and Undo Check-In actions execute without full-page reloads. Delegates validation, canonical name normalization, style/division rules, and atomic household balance clearance to shared processors in checkin-actions.php, and returns updated row payloads, household group updates, family group maps, and Line Capacity Tracker counts as pure JSON.
 * Compliance: Pure AJAX endpoint layer — zero HTML echo at include time, capability & nonce verified prior to any mutation, DRY reuse of checkin-actions.php and checkin-helpers.php, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Build O(R) Line Capacity Tracker metrics and updated row payloads from an enriched
 * Check-In Desk dataset so the client UI can update affected rows and tracker cards in place.
 *
 * @param array              $data        Enriched Check-In Desk dataset (post sywat_desk_prepare_presentation_data()).
 * @param array<int, string> $updated_ids List of registration IDs modified during the action.
 * @return array{
 *     updated_rows: array<string, array>,
 *     line_stats: array<string, array>,
 *     existing_families_by_line: array<string, array<string, string>>
 * }
 */
if ( ! function_exists( 'sywat_desk_build_ajax_sync_payload' ) ) {
    function sywat_desk_build_ajax_sync_payload( $data, $updated_ids ) {
        $target_lookup   = array_flip( array_map( 'strval', is_array( $updated_ids ) ? $updated_ids : array() ) );
        $updated_rows    = array();
        $active_checkins = isset( $data['active_checkins'] ) && is_array( $data['active_checkins'] ) ? $data['active_checkins'] : array();

        foreach ( $active_checkins as $reg ) {
            if ( ! is_array( $reg ) || empty( $reg['id'] ) ) {
                continue;
            }
            $r_id = (string) $reg['id'];
            if ( ! isset( $target_lookup[ $r_id ] ) ) {
                continue;
            }

            $is_checked_in  = ! empty( $reg['is_checked_in'] );
            $is_line_closed = ! empty( $reg['is_line_closed'] );

            $updated_rows[ $r_id ] = array(
                'id'                => $r_id,
                'display_name'      => isset( $reg['display_name'] ) ? (string) $reg['display_name'] : ( isset( $reg['name'] ) ? (string) $reg['name'] : '' ),
                'search_tokens'     => isset( $reg['search_tokens'] ) ? (string) $reg['search_tokens'] : '',
                'resolved_line_idx' => isset( $reg['resolved_line_idx'] ) ? (string) $reg['resolved_line_idx'] : '0',
                'is_checked_in'     => $is_checked_in,
                'is_line_closed'    => $is_line_closed,
                'needs_checkin'     => ( ! $is_checked_in && ! $is_line_closed ),
                'is_promo_free'     => ! empty( $reg['is_promo_free'] ),
                'round_label'       => isset( $reg['round_label'] ) ? (string) $reg['round_label'] : '',
                'owe_badge_text'    => isset( $reg['owe_badge_text'] ) ? (string) $reg['owe_badge_text'] : '',
                'table_display'     => isset( $reg['table_display'] ) && is_array( $reg['table_display'] ) ? $reg['table_display'] : array(),
                'modal_payload'     => isset( $reg['modal_payload'] ) && is_array( $reg['modal_payload'] ) ? $reg['modal_payload'] : array(),
            );
        }

        // Compute per-line occupancy & check-in counters in a single O(R) pass
        $lines               = isset( $data['lines'] ) && is_array( $data['lines'] ) ? $data['lines'] : array();
        $registrations       = isset( $data['registrations'] ) && is_array( $data['registrations'] ) ? $data['registrations'] : array();
        $selected_tournament = isset( $data['selected_tournament'] ) ? trim( (string) $data['selected_tournament'] ) : '';

        $line_stats       = array();
        $line_name_to_idx = array();

        foreach ( $lines as $raw_idx => $line ) {
            $idx_str = (string) $raw_idx;
            $l_name  = isset( $line['name'] ) ? trim( (string) $line['name'] ) : '';
            $cap_20  = isset( $line['cap_20yd'] ) ? max( 0, intval( $line['cap_20yd'] ) ) : 0;
            $cap_cub = isset( $line['cap_cub'] ) ? max( 0, intval( $line['cap_cub'] ) ) : 0;

            $line_stats[ $idx_str ] = array(
                'cap_20yd'    => $cap_20,
                'cap_cub'     => $cap_cub,
                'booked_20yd' => 0,
                'booked_cub'  => 0,
                'open_20yd'   => $cap_20,
                'open_cub'    => $cap_cub,
                'total_reg'   => 0,
                'checked_in'  => 0,
                'waitlist'    => 0,
            );

            if ( $l_name !== '' && ! isset( $line_name_to_idx[ $l_name ] ) ) {
                $line_name_to_idx[ $l_name ] = $idx_str;
            }
        }

        foreach ( $registrations as $reg ) {
            if ( ! is_array( $reg ) || ! isset( $reg['tournament'] ) ) {
                continue;
            }
            if ( strcasecmp( trim( (string) $reg['tournament'] ), $selected_tournament ) !== 0 ) {
                continue;
            }

            $status_str = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status_str, 'Cancelled' ) === 0 ) {
                continue;
            }

            $resolved_idx = '';
            if ( isset( $reg['line_index'] ) ) {
                $raw_l_idx = (string) $reg['line_index'];
                if ( isset( $line_stats[ $raw_l_idx ] ) ) {
                    $resolved_idx = $raw_l_idx;
                }
            }
            if ( $resolved_idx === '' && isset( $reg['line_name'] ) ) {
                $raw_l_name = trim( (string) $reg['line_name'] );
                if ( $raw_l_name !== '' && isset( $line_name_to_idx[ $raw_l_name ] ) ) {
                    $resolved_idx = $line_name_to_idx[ $raw_l_name ];
                }
            }

            if ( $resolved_idx === '' || ! isset( $line_stats[ $resolved_idx ] ) ) {
                continue;
            }

            if ( strcasecmp( $status_str, 'Waitlist' ) === 0 ) {
                $line_stats[ $resolved_idx ]['waitlist']++;
            } else {
                $line_stats[ $resolved_idx ]['total_reg']++;
                if ( ! empty( $reg['checked_in'] ) ) {
                    $line_stats[ $resolved_idx ]['checked_in']++;
                }
                if ( isset( $reg['distance'] ) && $reg['distance'] === '20yd' ) {
                    $line_stats[ $resolved_idx ]['booked_20yd']++;
                } elseif ( isset( $reg['distance'] ) && $reg['distance'] === 'Cub' ) {
                    $line_stats[ $resolved_idx ]['booked_cub']++;
                }
            }
        }

        foreach ( $line_stats as $idx_str => &$st ) {
            $st['open_20yd'] = max( 0, $st['cap_20yd'] - $st['booked_20yd'] );
            $st['open_cub']  = max( 0, $st['cap_cub'] - $st['booked_cub'] );
        }
        unset( $st );

        return array(
            'updated_rows'              => $updated_rows,
            'line_stats'                => $line_stats,
            'existing_families_by_line' => isset( $data['existing_families_by_line'] ) && is_array( $data['existing_families_by_line'] )
                ? $data['existing_families_by_line']
                : array(),
        );
    }
}

/**
 * AJAX Handler: Complete Archer Check-In (wp_ajax_sywat_desk_checkin).
 *
 * @return void Sends pure JSON response and terminates.
 */
if ( ! function_exists( 'sywat_ajax_desk_save_checkin' ) ) {
    function sywat_ajax_desk_save_checkin() {
        if ( function_exists( 'sywat_can_access_desk' ) && ! sywat_can_access_desk() ) {
            wp_send_json_error(
                array( 'message' => 'Access denied. Management or staff access required.' ),
                403
            );
        }

        $nonce = isset( $_POST['sywat_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ) : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'sywat_desk_action' ) ) {
            wp_send_json_error(
                array( 'message' => 'Security token expired. Please refresh the page and try again.' ),
                403
            );
        }

        if ( ! function_exists( 'sywat_desk_get_data' ) || ! function_exists( 'sywat_desk_process_checkin_submission' ) ) {
            wp_send_json_error(
                array( 'message' => 'Check-In Desk processors are unavailable.' ),
                500
            );
        }

        try {
            $data = sywat_desk_get_data();
            if ( function_exists( 'sywat_desk_prepare_presentation_data' ) ) {
                sywat_desk_prepare_presentation_data( $data );
            }

            $result = sywat_desk_process_checkin_submission( $data, $_POST );
            if ( empty( $result['success'] ) ) {
                wp_send_json_error(
                    array(
                        'message' => ! empty( $result['message'] ) ? $result['message'] : 'Unable to complete check-in.',
                    )
                );
            }

            if ( function_exists( 'sywat_desk_prepare_presentation_data' ) ) {
                sywat_desk_prepare_presentation_data( $data );
            }

            $sync_payload = sywat_desk_build_ajax_sync_payload( $data, $result['updated_ids'] );

            wp_send_json_success(
                array(
                    'reg_id'                    => $result['reg_id'],
                    'archer_name'               => $result['archer_name'],
                    'message'                   => $result['message'],
                    'updated_rows'              => $sync_payload['updated_rows'],
                    'line_stats'                => $sync_payload['line_stats'],
                    'existing_families_by_line' => $sync_payload['existing_families_by_line'],
                )
            );
        } catch ( \Throwable $e ) {
            wp_send_json_error(
                array( 'message' => 'Unexpected error while saving check-in. Please try again.' ),
                500
            );
        }
    }
}
add_action( 'wp_ajax_sywat_desk_checkin', 'sywat_ajax_desk_save_checkin' );

/**
 * AJAX Handler: Undo Archer Check-In (wp_ajax_sywat_desk_undo_checkin).
 *
 * @return void Sends pure JSON response and terminates.
 */
if ( ! function_exists( 'sywat_ajax_desk_undo_checkin' ) ) {
    function sywat_ajax_desk_undo_checkin() {
        if ( function_exists( 'sywat_can_access_desk' ) && ! sywat_can_access_desk() ) {
            wp_send_json_error(
                array( 'message' => 'Access denied. Management or staff access required.' ),
                403
            );
        }

        $nonce = isset( $_POST['sywat_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ) : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'sywat_desk_action' ) ) {
            wp_send_json_error(
                array( 'message' => 'Security token expired. Please refresh the page and try again.' ),
                403
            );
        }

        if ( ! function_exists( 'sywat_desk_get_data' ) || ! function_exists( 'sywat_desk_process_undo_checkin_submission' ) ) {
            wp_send_json_error(
                array( 'message' => 'Check-In Desk processors are unavailable.' ),
                500
            );
        }

        try {
            $data = sywat_desk_get_data();
            if ( function_exists( 'sywat_desk_prepare_presentation_data' ) ) {
                sywat_desk_prepare_presentation_data( $data );
            }

            $result = sywat_desk_process_undo_checkin_submission( $data, $_POST );
            if ( empty( $result['success'] ) ) {
                wp_send_json_error(
                    array(
                        'message' => ! empty( $result['message'] ) ? $result['message'] : 'Unable to revert check-in.',
                    )
                );
            }

            if ( function_exists( 'sywat_desk_prepare_presentation_data' ) ) {
                sywat_desk_prepare_presentation_data( $data );
            }

            $sync_payload = sywat_desk_build_ajax_sync_payload( $data, $result['updated_ids'] );

            wp_send_json_success(
                array(
                    'reg_id'                    => $result['reg_id'],
                    'archer_name'               => $result['archer_name'],
                    'message'                   => $result['message'],
                    'updated_rows'              => $sync_payload['updated_rows'],
                    'line_stats'                => $sync_payload['line_stats'],
                    'existing_families_by_line' => $sync_payload['existing_families_by_line'],
                )
            );
        } catch ( \Throwable $e ) {
            wp_send_json_error(
                array( 'message' => 'Unexpected error while reverting check-in. Please try again.' ),
                500
            );
        }
    }
}
add_action( 'wp_ajax_sywat_desk_undo_checkin', 'sywat_ajax_desk_undo_checkin' );
