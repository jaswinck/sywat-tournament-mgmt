<?php
/**
 * Sub-Module: Action Handlers & POST Request Router for Tournaments and Seasons
 * Path: includes/tournaments/tournament-actions.php
 * Description: Handles tournament/season POST actions, preserves tournament keys/metadata during capacity edits, and safely migrates registrations, scores, and line locks if a tournament is explicitly renamed or re-sequenced.
 * Author: SYWAT Archery
 * Version: 7.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Early Init Interceptor for Asynchronous JSON TFAA Score Sync Requests.
 * Executes prior to theme HTML rendering to guarantee a pure JSON payload.
 */
add_action( 'init', 'sywat_async_tfaa_sync_interceptor' );
if ( ! function_exists( 'sywat_async_tfaa_sync_interceptor' ) ) {
    function sywat_async_tfaa_sync_interceptor() {
        if ( isset( $_POST['sywat_action'] ) && $_POST['sywat_action'] === 'sync_tfaa_single_json' ) {
            // Clean output buffers to ensure pure JSON response
            while ( ob_get_level() ) {
                ob_end_clean();
            }

            if ( ! isset( $_POST['sywat_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ), 'sywat_desk_action' ) ) {
                wp_send_json_error( array( 'message' => 'Security token expired. Please refresh the page.' ) );
            }

            if ( ! function_exists( 'sywat_can_manage_tournaments' ) || ! sywat_can_manage_tournaments() ) {
                wp_send_json_error( array( 'message' => 'Access denied. Management permission required.' ) );
            }

            $target_t = isset( $_POST['tournament_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tournament_name'] ) ) : '';

            if ( empty( $target_t ) ) {
                wp_send_json_error( array( 'message' => 'No target tournament specified for TFAA sync.' ) );
            }

            $tfaa_sync_file = dirname( __DIR__ ) . '/common/sywat-tfaa-sync.php';
            if ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) && file_exists( $tfaa_sync_file ) ) {
                require_once $tfaa_sync_file;
            } elseif ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) && defined( 'SYWAT_PLUGIN_DIR' ) && file_exists( SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php' ) ) {
                require_once SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php';
            }

            if ( function_exists( 'sywat_sync_tfaa_event_scores' ) ) {
                $res = sywat_sync_tfaa_event_scores( $target_t );
                if ( ! empty( $res['success'] ) ) {
                    wp_send_json_success( array(
                        'message' => isset( $res['message'] ) ? $res['message'] : 'Sync complete.',
                        'count'   => isset( $res['count'] ) ? intval( $res['count'] ) : 0,
                    ) );
                } else {
                    wp_send_json_error( array(
                        'message' => isset( $res['message'] ) ? $res['message'] : 'Sync failed for unknown reason.',
                    ) );
                }
            } else {
                wp_send_json_error( array( 'message' => 'TFAA sync engine function unavailable.' ) );
            }
        }
    }
}

/**
 * Master Controller Function for Tournament & Season POST Actions.
 *
 * @param array  $tournaments         Reference to tournaments array.
 * @param array  $active_tournaments  Reference to active tournaments array.
 * @param array  $registrations       Reference to registrations array.
 * @param bool   $can_manage_seasons  Capability flag for season configuration.
 * @param string $user_shop_id        Shop ID of current logged in user.
 * @return string                     HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_handle_tournament_post_actions' ) ) {
    function sywat_handle_tournament_post_actions( &$tournaments, &$active_tournaments, &$registrations, $can_manage_seasons, $user_shop_id ) {
        $notice = '';
        $action = isset( $_POST['sywat_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) ) : '';

        // Action: Register Shooter Intake Form
        if ( $action === 'register_shooter' && function_exists( 'sywat_handle_register_shooter' ) ) {
            return sywat_handle_register_shooter( $tournaments, $registrations );
        }

        // Action: Import Shooters (FareHarbor CSV / Raw Text)
        if ( $action === 'import_shooters' && function_exists( 'sywat_handle_import_shooters' ) ) {
            return sywat_handle_import_shooters( $tournaments, $registrations );
        }

        // Action: Save Edited Archer
        if ( $action === 'save_edit' && function_exists( 'sywat_handle_save_edit' ) ) {
            return sywat_handle_save_edit( $tournaments, $registrations );
        }

        // Action: Delete Single Shooter
        if ( $action === 'delete_shooter' && function_exists( 'sywat_handle_delete_shooter' ) ) {
            return sywat_handle_delete_shooter( $registrations );
        }

        // Action: Purge Entire Line
        if ( $action === 'purge_line' && function_exists( 'sywat_handle_purge_line' ) ) {
            return sywat_handle_purge_line( $tournaments, $registrations );
        }

        // Action: Waitlist Promotion & Open-Line Move Handlers
        if ( function_exists( 'sywat_waitlist_is_supported_action' ) && sywat_waitlist_is_supported_action( $action ) && function_exists( 'sywat_waitlist_dispatch_post_action' ) ) {
            return sywat_waitlist_dispatch_post_action( $tournaments, $registrations );
        }

        // Action: Deactivate All Volunteer Hubs
        if ( $action === 'deactivate_all_hubs' ) {
            update_option( 'sywat_active_tournaments', array() );
            $active_tournaments = array();
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">⚪ <strong>All Volunteer Hubs Deactivated!</strong> Reset active status across all tournaments.</div>';
        }

        // Action: Bulk Sync Season TFAA Direct URLs (Dynamic Debug Toggle Support)
        if ( $action === 'sync_season_tfaa_urls' ) {
            $target_s = isset( $_POST['season_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['season_slug'] ) ) : '';
            $res      = function_exists( 'sywat_sync_season_tfaa_urls' ) ? sywat_sync_season_tfaa_urls( $target_s ) : array( 'success' => false, 'message' => 'Sync function missing.' );

            $is_debug = ! empty( $res['debug'] );
            if ( ! empty( $res['success'] ) ) {
                $tournaments = get_option( 'sywat_tournaments_list', array() );
                if ( $is_debug ) {
                    return '<div id="sywat-debug-notice" class="sywat-alert sywat-alert-success">🟢 ' . $res['message'] . '</div>';
                }
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 ' . $res['message'] . '</div>';
            } else {
                if ( $is_debug ) {
                    return '<div id="sywat-debug-notice" class="sywat-alert sywat-alert-danger">⚠️ ' . $res['message'] . '</div>';
                }
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ ' . $res['message'] . '</div>';
            }
        }

        // Action: Save Season Configuration
        if ( $action === 'save_season' ) {
            if ( ! $can_manage_seasons ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators and TFAA webmasters can configure seasons.</div>';
            }

            $seasons    = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
            $orig_slug  = isset( $_POST['original_season_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['original_season_slug'] ) ) : '';
            $s_type     = sanitize_text_field( wp_unslash( $_POST['season_type'] ) );
            $start_date = sanitize_text_field( wp_unslash( $_POST['start_date'] ) );
            $end_date   = sanitize_text_field( wp_unslash( $_POST['end_date'] ) );
            $s_status   = sanitize_text_field( wp_unslash( $_POST['season_status'] ) );
            $s_best_n   = intval( $_POST['season_best_n'] );

            // Validate Season Type Selection
            if ( empty( $s_type ) || ! in_array( $s_type, array( 'Indoor', 'Outdoor', 'Field 50' ), true ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a valid Season Type (Indoor, Outdoor, or Field 50).</div>';
            }

            if ( empty( $start_date ) || empty( $end_date ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select both Start and End dates for the season.</div>';
            }

            // Automatically Calculate Year Label from Start and End Dates
            $s_start_year = date( 'Y', strtotime( $start_date ) );
            $s_end_year   = date( 'Y', strtotime( $end_date ) );

            if ( $s_start_year === $s_end_year ) {
                $year_label = $s_start_year;
            } else {
                $year_label = $s_start_year . '–' . $s_end_year;
            }

            // Automatically Generate Season Name & Slug
            $s_name = $year_label . ' ' . $s_type;
            $s_slug = ! empty( $orig_slug ) ? $orig_slug : sanitize_title( $s_name );

            if ( $s_status === 'active' ) {
                foreach ( $seasons as $k => &$s ) {
                    $s['status'] = 'archived';
                }
                unset( $s );
            }

            $seasons[ $s_slug ] = array(
                'slug'         => $s_slug,
                'name'         => $s_name,
                'type'         => $s_type,
                'start_date'   => $start_date,
                'end_date'     => $end_date,
                'year_label'   => $year_label,
                'status'       => $s_status,
                'best_n_count' => $s_best_n > 0 ? $s_best_n : 3,
            );

            update_option( 'sywat_seasons_list', $seasons );

            // Auto-trigger season TFAA link sync on save
            if ( function_exists( 'sywat_sync_season_tfaa_urls' ) ) {
                sywat_sync_season_tfaa_urls( $s_name );
                $tournaments = get_option( 'sywat_tournaments_list', array() );
            }

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Season <strong>' . esc_html( $s_name ) . '</strong> saved successfully! Automatically synced TFAA tournament links.</div>';
        }

        // Action: Upload & Stage Bulk Schedule File (.xlsx or .csv)
        if ( $action === 'import_schedule_file' ) {
            check_admin_referer( 'sywat_import_schedule_nonce', 'sywat_import_schedule_nonce' );

            if ( empty( $_FILES['schedule_file']['tmp_name'] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a valid schedule file (.csv or .xlsx) to upload.</div>';
            }

            $target_season              = isset( $_POST['import_target_season'] ) ? sanitize_text_field( wp_unslash( $_POST['import_target_season'] ) ) : '2026-2027 Indoor';
            $file_path                  = $_FILES['schedule_file']['tmp_name'];
            $file_name                  = $_FILES['schedule_file']['name'];
            $raw_rows                   = function_exists( 'sywat_parse_schedule_file_rows' ) ? sywat_parse_schedule_file_rows( $file_path, $file_name ) : array();
            $processed                  = function_exists( 'sywat_process_schedule_import_rows' ) ? sywat_process_schedule_import_rows( $raw_rows, $target_season ) : array( 'valid_tournaments' => array(), 'rejected_rows' => array() );
            $processed['target_season'] = $target_season;

            set_transient( 'sywat_pending_schedule_import_' . get_current_user_id(), $processed, 3600 );

            $valid_cnt    = count( $processed['valid_tournaments'] );
            $rejected_cnt = count( $processed['rejected_rows'] );

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-info">🔍 <strong>Schedule Preview Generated:</strong> Found ' . $valid_cnt . ' valid tournament(s) and ' . $rejected_cnt . ' rejected/prohibited row(s). Please review the preview modal below to confirm creation.</div>';
        }

        // Action: Confirm & Execute Bulk Schedule Creation (Hub OFF by Default)
        if ( $action === 'confirm_schedule_import' ) {
            check_admin_referer( 'sywat_confirm_schedule_nonce', 'sywat_confirm_schedule_nonce' );

            $transient_key = 'sywat_pending_schedule_import_' . get_current_user_id();
            $processed     = get_transient( $transient_key );

            if ( ! $processed || empty( $processed['valid_tournaments'] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Import session expired or no valid tournaments found. Please try uploading the file again.</div>';
            }

            $target_season = isset( $processed['target_season'] ) ? $processed['target_season'] : '2026-2027 Indoor';
            $created_count = 0;

            // Extract shop lookup map for assigning shop_id on import
            $all_shops       = function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : get_option( 'sywat_shops_list', array() );
            $shop_name_to_id = array();
            foreach ( $all_shops as $s_id => $s_data ) {
                if ( is_array( $s_data ) ) {
                    $s_name = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : ( ! empty( $s_data['title'] ) ? $s_data['title'] : '' ) );
                    if ( ! empty( $s_name ) ) {
                        $shop_name_to_id[ strtolower( trim( $s_name ) ) ] = $s_id;
                    }
                }
            }

            $affected_shops = array();
            foreach ( $processed['valid_tournaments'] as $t ) {
                $t_name   = $t['tournament_name'];
                $official = $t['official_shop_name'];
                $norm_off = strtolower( trim( $official ) );
                $assoc_id = isset( $shop_name_to_id[ $norm_off ] ) ? $shop_name_to_id[ $norm_off ] : '';

                $tournaments[ $t_name ] = array(
                    'name'                 => $t_name,
                    'season'               => $target_season,
                    'season_slug'          => $target_season,
                    'shop_id'              => $assoc_id,
                    'shop_name'            => $official,
                    'official_shop_name'   => $official,
                    'location_display'     => $t['location_display'],
                    'start_date'           => $t['start_date_formatted'],
                    'end_date'             => $t['end_date_formatted'],
                    'event_type'           => $t['event_type'],
                    'notes'                => sanitize_textarea_field( $t['notes'] ),
                    'lines'                => $t['line_slots'],
                    'registration_enabled' => $t['registration_enabled'],
                    'scores_published'     => false,
                    'created_at'           => current_time( 'mysql' ),
                );

                if ( ! empty( $assoc_id ) && ! in_array( $assoc_id, $affected_shops, true ) ) {
                    $affected_shops[] = $assoc_id;
                }

                $created_count++;
            }

            update_option( 'sywat_tournaments_list', $tournaments );
            delete_transient( $transient_key );

            // Re-sequence all host shops affected by bulk import
            if ( function_exists( 'sywat_resequence_shop_tournaments' ) ) {
                if ( empty( $registrations ) && function_exists( 'sywat_get_all_registrations' ) ) {
                    $registrations = sywat_get_all_registrations();
                }
                foreach ( $affected_shops as $s_id ) {
                    sywat_resequence_shop_tournaments( $s_id, $target_season, $tournaments, $active_tournaments, $registrations );
                }
            }

            // Auto-trigger bulk season link sync on import confirmation
            if ( function_exists( 'sywat_sync_season_tfaa_urls' ) ) {
                sywat_sync_season_tfaa_urls( $target_season );
                $tournaments = get_option( 'sywat_tournaments_list', array() );
            }

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🚀 <strong>Schedule Imported Successfully!</strong> Created ' . intval( $created_count ) . ' new tournament(s) for season <strong>' . esc_html( $target_season ) . '</strong> and resolved TFAA links.</div>';
        }

        // Action: Sync TFAA Cache
        if ( $action === 'sync_tfaa_now' && function_exists( 'sywat_sync_tfaa_standings' ) ) {
            $res    = sywat_sync_tfaa_standings();
            $notice = ! empty( $res['success'] )
                ? '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 <strong>TFAA Standings Synced!</strong> Successfully cached ' . intval( $res['count'] ) . ' active shooters from TFAA.</div>'
                : '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
        }

        // Action: Sync Single TFAA Tournament Scorecard (Fallback UI handler)
        if ( $action === 'sync_tfaa_single' ) {
            $target_t = isset( $_POST['target_tournament'] ) ? sanitize_text_field( wp_unslash( $_POST['target_tournament'] ) ) : '';

            if ( empty( $target_t ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ No target tournament specified for TFAA sync.</div>';
            }

            $tfaa_sync_file = dirname( __DIR__ ) . '/common/sywat-tfaa-sync.php';
            if ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) && file_exists( $tfaa_sync_file ) ) {
                require_once $tfaa_sync_file;
            } elseif ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) && defined( 'SYWAT_PLUGIN_DIR' ) && file_exists( SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php' ) ) {
                require_once SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php';
            }

            if ( function_exists( 'sywat_sync_tfaa_event_scores' ) ) {
                $res = sywat_sync_tfaa_event_scores( $target_t );
                if ( ! empty( $res['success'] ) ) {
                    $tournaments   = get_option( 'sywat_tournaments_list', array() );
                    $registrations = function_exists( 'sywat_get_all_registrations' ) ? sywat_get_all_registrations() : get_option( 'sywat_registrations', array() );
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 ' . $res['message'] . '</div>';
                } else {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
                }
            }
        }

        // Action: Bulk Sync All Tournament Scores with Populated TFAA Results Links (Fallback)
        if ( $action === 'sync_all_tfaa_weekend' ) {
            $target_tournaments = array();

            if ( is_array( $tournaments ) ) {
                foreach ( $tournaments as $t_name => $t_data ) {
                    $has_url = ! empty( $t_data['tfaa_results_url'] );
                    $has_id  = ! empty( $t_data['tfaa_event_id'] );
                    if ( $has_url || $has_id ) {
                        $target_tournaments[] = $t_name;
                    }
                }
            }

            if ( empty( $target_tournaments ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ No tournaments with configured TFAA result links were found. Please run <strong>"Sync TFAA Links"</strong> first or set valid TFAA Event IDs.</div>';
            }

            $tfaa_sync_file = dirname( __DIR__ ) . '/common/sywat-tfaa-sync.php';
            if ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) && file_exists( $tfaa_sync_file ) ) {
                require_once $tfaa_sync_file;
            } elseif ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) && defined( 'SYWAT_PLUGIN_DIR' ) && file_exists( SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php' ) ) {
                require_once SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php';
            }

            $total_updated = 0;
            $synced_names  = array();
            $errors        = array();

            foreach ( $target_tournaments as $t_name ) {
                if ( function_exists( 'sywat_sync_tfaa_event_scores' ) ) {
                    $res = sywat_sync_tfaa_event_scores( $t_name );
                    if ( ! empty( $res['success'] ) ) {
                        $total_updated += intval( isset( $res['count'] ) ? $res['count'] : 0 );
                        $synced_names[] = esc_html( $t_name );
                    } else {
                        $errors[] = esc_html( $t_name ) . ': ' . esc_html( $res['message'] );
                    }
                }
            }

            $tournaments   = get_option( 'sywat_tournaments_list', array() );
            $registrations = function_exists( 'sywat_get_all_registrations' ) ? sywat_get_all_registrations() : get_option( 'sywat_registrations', array() );

            $msg = '';
            if ( ! empty( $synced_names ) ) {
                $msg .= '<div id="sywat-notice" class="sywat-alert sywat-alert-success">✅ <strong>Bulk Sync Complete!</strong> Updated ' . $total_updated . ' total archer score record(s) across ' . count( $synced_names ) . ' linked tournament(s): ' . implode( ', ', $synced_names ) . '</div>';
            }
            if ( ! empty( $errors ) ) {
                $msg .= '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ Some locations failed to sync:<br>' . implode( '<br>', $errors ) . '</div>';
            }

            return $msg;
        }

        // Action: Toggle Volunteer Hub Display
        if ( $action === 'toggle_active_tournament' ) {
            $target_t = sanitize_text_field( wp_unslash( $_POST['tournament_name'] ) );
            if ( in_array( $target_t, $active_tournaments, true ) ) {
                $active_tournaments = array_diff( $active_tournaments, array( $target_t ) );
            } else {
                $active_tournaments[] = $target_t;
            }
            $active_tournaments = array_values( array_unique( $active_tournaments ) );
            update_option( 'sywat_active_tournaments', $active_tournaments );
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Updated Volunteer Hub status for <strong>' . esc_html( $target_t ) . '</strong>.</div>';
        }

        // Action: Global Push All Weekend Scores to Season Standings
        if ( $action === 'global_push_standings' ) {
            if ( ! $can_manage_seasons ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators and TFAA webmasters can push global standings.</div>';
            }

            $pushed_count = 0;
            $now_time     = current_time( 'mysql' );

            foreach ( $tournaments as $t_name => &$t_data ) {
                $t_data['scores_published'] = true;
                $t_data['published_at']     = $now_time;
                $pushed_count++;
            }
            unset( $t_data );

            update_option( 'sywat_tournaments_list', $tournaments );

            if ( function_exists( 'sywat_recalculate_season_standings' ) ) {
                sywat_recalculate_season_standings();
            }

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🚀 <strong>Weekend Standings Pushed!</strong> Successfully published scores across ' . $pushed_count . ' tournament(s) to Season Standings.</div>';
        }

        // Action: Toggle Push / Publish Scores to Season Standings (Single Event Fallback)
        if ( $action === 'toggle_publish_standings' ) {
            $t_name = sanitize_text_field( wp_unslash( $_POST['tournament_name'] ) );
            if ( isset( $tournaments[ $t_name ] ) ) {
                $is_published                               = ! empty( $tournaments[ $t_name ]['scores_published'] );
                $tournaments[ $t_name ]['scores_published'] = ! $is_published;

                if ( ! $is_published ) {
                    $tournaments[ $t_name ]['published_at'] = current_time( 'mysql' );
                    $notice                                 = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Scores for <strong>' . esc_html( $t_name ) . '</strong> have been 🚀 <strong>Pushed to Season Standings</strong>!</div>';
                } else {
                    unset( $tournaments[ $t_name ]['published_at'] );
                    $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Scores for <strong>' . esc_html( $t_name ) . '</strong> have been 🔴 <strong>Hidden from Season Standings</strong>.</div>';
                }

                update_option( 'sywat_tournaments_list', $tournaments );
            }
        }

        // Action: Save / Edit Tournament Config
        if ( $action === 'save_tournament' ) {
            $old_name    = isset( $_POST['original_tournament_name'] ) ? sanitize_text_field( wp_unslash( $_POST['original_tournament_name'] ) ) : '';
            $t_name      = isset( $_POST['tournament_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tournament_name'] ) ) : '';
            if ( empty( $t_name ) && ! empty( $old_name ) ) {
                $t_name = $old_name;
            }
            $season_slug = isset( $_POST['season_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['season_slug'] ) ) : '';
            $new_slug    = sanitize_title( $t_name );

            $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
            $end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
            if ( empty( $end_date ) ) {
                $end_date = $start_date;
            }

            $tfaa_event_id = isset( $_POST['tfaa_event_id'] ) ? sanitize_text_field( trim( wp_unslash( $_POST['tfaa_event_id'] ) ) ) : '';

            $assigned_shop_id = $can_manage_seasons && isset( $_POST['shop_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_id'] ) ) : $user_shop_id;
            if ( empty( $assigned_shop_id ) ) {
                $assigned_shop_id = $user_shop_id;
            }

            // Capture existing record attributes before save so capacity/URL edits never wipe metadata or trigger unwanted re-sequencing
            $lookup_handle   = ( ! empty( $old_name ) && isset( $tournaments[ $old_name ] ) ) ? $old_name : $t_name;
            $existing_record = ( isset( $tournaments[ $lookup_handle ] ) && is_array( $tournaments[ $lookup_handle ] ) ) ? $tournaments[ $lookup_handle ] : array();
            $orig_start_date = isset( $existing_record['start_date'] ) ? $existing_record['start_date'] : '';
            $orig_shop_id    = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $existing_record ) : ( isset( $existing_record['shop_id'] ) ? $existing_record['shop_id'] : '' );
            $orig_season     = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $existing_record ) : ( isset( $existing_record['season_slug'] ) ? $existing_record['season_slug'] : '' );

            // Resolve shop name metadata from master shop list
            $all_shops          = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : ( function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : get_option( 'sywat_shops', get_option( 'sywat_shops_list', array() ) ) );
            $resolved_shop_name = '';
            if ( ! empty( $assigned_shop_id ) && isset( $all_shops[ $assigned_shop_id ] ) && is_array( $all_shops[ $assigned_shop_id ] ) ) {
                $s_info             = $all_shops[ $assigned_shop_id ];
                $resolved_shop_name = ! empty( $s_info['name'] ) ? $s_info['name'] : ( ! empty( $s_info['shop_name'] ) ? $s_info['shop_name'] : ( ! empty( $s_info['title'] ) ? $s_info['title'] : '' ) );
            }
            if ( empty( $resolved_shop_name ) && function_exists( 'sywat_get_tournament_shop_name' ) ) {
                $resolved_shop_name = sywat_get_tournament_shop_name( array( 'shop_id' => $assigned_shop_id ), $t_name, $all_shops );
            }

            // -------------------------------------------------------------
            // MANUAL SAVE OVERLAP GUARD: Prohibit overlapping shop event dates
            // -------------------------------------------------------------
            $target_shop_norm = strtolower( trim( $resolved_shop_name ) );
            foreach ( $tournaments as $ex_key => $ex_data ) {
                if ( ! empty( $old_name ) && $ex_key === $old_name ) {
                    continue; // Skip self when editing
                }

                $ex_season = isset( $ex_data['season_slug'] ) ? $ex_data['season_slug'] : ( isset( $ex_data['season'] ) ? $ex_data['season'] : '' );
                if ( ! empty( $season_slug ) && ! empty( $ex_season ) && $ex_season !== $season_slug ) {
                    continue;
                }

                $ex_shop_id   = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $ex_data ) : ( isset( $ex_data['shop_id'] ) ? $ex_data['shop_id'] : '' );
                $ex_shop_name = isset( $ex_data['shop_name'] ) ? strtolower( trim( $ex_data['shop_name'] ) ) : ( isset( $ex_data['official_shop_name'] ) ? strtolower( trim( $ex_data['official_shop_name'] ) ) : '' );

                $is_same_shop = ( ! empty( $assigned_shop_id ) && $ex_shop_id === $assigned_shop_id )
                             || ( ! empty( $target_shop_norm ) && $ex_shop_name === $target_shop_norm );

                if ( $is_same_shop ) {
                    $ex_start = isset( $ex_data['start_date'] ) ? $ex_data['start_date'] : '';
                    $ex_end   = ! empty( $ex_data['end_date'] ) ? $ex_data['end_date'] : $ex_start;

                    if ( ! empty( $ex_start ) && $start_date <= $ex_end && $end_date >= $ex_start ) {
                        $ex_dates_formatted = function_exists( 'sywat_format_tournament_dates' ) ? sywat_format_tournament_dates( $ex_start, $ex_end ) : $ex_start;
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Date Overlap Prohibited:</strong> Host shop already has <strong>"' . esc_html( $ex_key ) . '"</strong> scheduled from ' . esc_html( $ex_dates_formatted ) . '. Overlapping event dates are not allowed.</div>';
                    }
                }
            }

            $formatted_dates = function_exists( 'sywat_format_tournament_dates' ) ? sywat_format_tournament_dates( $start_date, $end_date ) : $start_date;
            update_option( 'sywat_dates_' . $new_slug, $formatted_dates );

            // Process Dynamic Shooting Lines Repeater (Split Day & Time + Deduplication)
            $new_lines   = array();
            $seen_combos = array();

            if ( ! empty( $_POST['line_days'] ) && is_array( $_POST['line_days'] ) ) {
                foreach ( $_POST['line_days'] as $index => $day ) {
                    $clean_day  = trim( sanitize_text_field( wp_unslash( $day ) ) );
                    $clean_time = isset( $_POST['line_times'][ $index ] ) ? trim( sanitize_text_field( wp_unslash( $_POST['line_times'][ $index ] ) ) ) : '';
                    if ( ! empty( $clean_day ) && ! empty( $clean_time ) ) {
                        $formatted_time = function_exists( 'sywat_format_line_time_token' ) ? sywat_format_line_time_token( $clean_time ) : $clean_time;
                        $combo_key      = strtolower( $clean_day . '_' . $formatted_time );
                        if ( ! in_array( $combo_key, $seen_combos, true ) ) {
                            $seen_combos[] = $combo_key;
                            $new_lines[]   = array(
                                'day'      => $clean_day,
                                'time'     => $formatted_time,
                                'name'     => trim( $clean_day . ' ' . $formatted_time ),
                                'cap_20yd' => isset( $_POST['cap_20yd'][ $index ] ) ? intval( $_POST['cap_20yd'][ $index ] ) : 20,
                                'cap_cub'  => isset( $_POST['cap_cub'][ $index ] ) ? intval( $_POST['cap_cub'][ $index ] ) : 5,
                            );
                        }
                    }
                }
            } elseif ( ! empty( $_POST['line_names'] ) && is_array( $_POST['line_names'] ) ) {
                // Fallback for legacy single line string POSTs
                foreach ( $_POST['line_names'] as $index => $name ) {
                    $clean_name = trim( sanitize_text_field( wp_unslash( $name ) ) );
                    if ( ! empty( $clean_name ) ) {
                        $parts          = explode( ' ', $clean_name, 2 );
                        $formatted_time = isset( $parts[1] ) ? ( function_exists( 'sywat_format_line_time_token' ) ? sywat_format_line_time_token( $parts[1] ) : $parts[1] ) : '';
                        $formatted_name = trim( $parts[0] . ' ' . $formatted_time );
                        $combo_key      = strtolower( $formatted_name );

                        if ( ! in_array( $combo_key, $seen_combos, true ) ) {
                            $seen_combos[] = $combo_key;
                            $new_lines[]   = array(
                                'day'      => $parts[0],
                                'time'     => $formatted_time,
                                'name'     => $formatted_name,
                                'cap_20yd' => isset( $_POST['cap_20yd'][ $index ] ) ? intval( $_POST['cap_20yd'][ $index ] ) : 20,
                                'cap_cub'  => isset( $_POST['cap_cub'][ $index ] ) ? intval( $_POST['cap_cub'][ $index ] ) : 5,
                            );
                        }
                    }
                }
            }

            // Carry forward existing publishing, classification & resolved TFAA metadata if present
            $is_scores_pub     = isset( $existing_record['scores_published'] ) ? $existing_record['scores_published'] : false;
            $published_at      = isset( $existing_record['published_at'] ) ? $existing_record['published_at'] : '';
            $existing_tfaa_url = isset( $existing_record['tfaa_results_url'] ) ? $existing_record['tfaa_results_url'] : '';
            $existing_ev_type  = isset( $existing_record['event_type'] ) ? $existing_record['event_type'] : 'SYWAT';
            $existing_notes    = isset( $existing_record['notes'] ) ? $existing_record['notes'] : '';
            $existing_reg_en   = isset( $existing_record['registration_enabled'] ) ? $existing_record['registration_enabled'] : true;
            $existing_created  = isset( $existing_record['created_at'] ) ? $existing_record['created_at'] : current_time( 'mysql' );

            if ( ! empty( $old_name ) && $old_name !== $t_name && isset( $tournaments[ $old_name ] ) ) {
                if ( empty( $registrations ) && function_exists( 'sywat_get_all_registrations' ) ) {
                    $registrations = sywat_get_all_registrations();
                }

                unset( $tournaments[ $old_name ] );
                $old_slug = sanitize_title( $old_name );

                foreach ( $registrations as &$reg ) {
                    if ( isset( $reg['tournament'] ) && ( strcasecmp( $reg['tournament'], $old_name ) === 0 || sanitize_title( $reg['tournament'] ) === $old_slug ) ) {
                        if ( $old_slug !== $new_slug ) {
                            delete_option( 'sywat_t_' . $old_slug . '_' . $reg['id'] );
                        }
                        $reg['tournament'] = $t_name;
                        update_option( 'sywat_t_' . $new_slug . '_' . $reg['id'], $reg, false );
                    }
                }
                unset( $reg );

                if ( $old_slug !== $new_slug ) {
                    foreach ( array( 'sywat_dates_', 'sywat_scores_', 'sywat_closed_lines_', 'sywat_locks_', 'sywat_registrations_' ) as $opt_prefix ) {
                        $old_opt_val = get_option( $opt_prefix . $old_slug, null );
                        if ( $old_opt_val !== null ) {
                            update_option( $opt_prefix . $new_slug, $old_opt_val, false );
                            delete_option( $opt_prefix . $old_slug );
                        }
                    }
                }

                if ( in_array( $old_name, $active_tournaments, true ) ) {
                    $active_tournaments   = array_diff( $active_tournaments, array( $old_name ) );
                    $active_tournaments[] = $t_name;
                }
            }

            // Avoid key collision when adding a new tournament whose generated name already exists in $tournaments
            $storage_key = $t_name;
            if ( empty( $old_name ) && isset( $tournaments[ $t_name ] ) ) {
                $storage_key = $t_name . '-temp-' . uniqid();
            }

            $tournaments[ $storage_key ] = array(
                'name'                 => $t_name,
                'shop_id'              => $assigned_shop_id,
                'shop_name'            => $resolved_shop_name,
                'official_shop_name'   => $resolved_shop_name,
                'location_display'     => $resolved_shop_name,
                'season_slug'          => $season_slug,
                'season'               => $season_slug,
                'start_date'           => $start_date,
                'end_date'             => $end_date,
                'event_type'           => $existing_ev_type,
                'notes'                => $existing_notes,
                'registration_enabled' => $existing_reg_en,
                'tfaa_event_id'        => $tfaa_event_id,
                'lines'                => $new_lines,
                'scores_published'     => $is_scores_pub,
                'created_at'           => $existing_created,
            );

            if ( ! empty( $published_at ) ) {
                $tournaments[ $storage_key ]['published_at'] = $published_at;
            }

            if ( ! empty( $existing_tfaa_url ) ) {
                $tournaments[ $storage_key ]['tfaa_results_url'] = $existing_tfaa_url;
            }

            update_option( 'sywat_tournaments_list', $tournaments );
            update_option( 'sywat_active_tournaments', array_values( array_unique( $active_tournaments ) ) );
            update_option( 'sywat_current_tournament', $t_name );
            update_option( 'sywat_line_config', $new_lines );

            // Re-sequence shop tournaments ONLY when creating a new event or changing its start date/shop/season
            $should_resequence = empty( $old_name )
                || ( $orig_start_date !== $start_date )
                || ( $orig_shop_id !== $assigned_shop_id )
                || ( $orig_season !== $season_slug );

            if ( $should_resequence && function_exists( 'sywat_resequence_shop_tournaments' ) ) {
                if ( empty( $registrations ) && function_exists( 'sywat_get_all_registrations' ) ) {
                    $registrations = sywat_get_all_registrations();
                }
                sywat_resequence_shop_tournaments( $assigned_shop_id, $season_slug, $tournaments, $active_tournaments, $registrations );
            }

            // Auto-trigger immediate season TFAA link sync post-save
            if ( function_exists( 'sywat_sync_season_tfaa_urls' ) ) {
                sywat_sync_season_tfaa_urls( $season_slug );
                $tournaments = get_option( 'sywat_tournaments_list', array() );
            }

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Tournament <strong>' . esc_html( $t_name ) . '</strong> saved successfully! Automatically synced TFAA links.</div>';
        }

        // Action: Delete Tournament
        if ( $action === 'delete_tournament' ) {
            $del_tournament = sanitize_text_field( wp_unslash( $_POST['tournament_name'] ) );
            if ( isset( $tournaments[ $del_tournament ] ) ) {
                $del_shop_id     = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $tournaments[ $del_tournament ] ) : '';
                $del_season_slug = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $tournaments[ $del_tournament ] ) : '';

                if ( empty( $registrations ) && function_exists( 'sywat_get_all_registrations' ) ) {
                    $registrations = sywat_get_all_registrations();
                }

                unset( $tournaments[ $del_tournament ] );
                update_option( 'sywat_tournaments_list', $tournaments );

                $active_tournaments = array_diff( $active_tournaments, array( $del_tournament ) );
                update_option( 'sywat_active_tournaments', array_values( $active_tournaments ) );

                $del_slug = sanitize_title( $del_tournament );
                delete_option( 'sywat_dates_' . $del_slug );

                foreach ( $registrations as $reg ) {
                    if ( isset( $reg['tournament'] ) && ( strcasecmp( $reg['tournament'], $del_tournament ) === 0 || sanitize_title( $reg['tournament'] ) === $del_slug ) ) {
                        delete_option( 'sywat_t_' . $del_slug . '_' . $reg['id'] );
                    }
                }

                $registrations = array_values( array_filter( $registrations, function( $reg ) use ( $del_tournament, $del_slug ) {
                    return ! ( isset( $reg['tournament'] ) && ( strcasecmp( $reg['tournament'], $del_tournament ) === 0 || sanitize_title( $reg['tournament'] ) === $del_slug ) );
                } ) );

                // Re-sequence remaining shop tournaments to collapse sequence gaps
                if ( ! empty( $del_shop_id ) && function_exists( 'sywat_resequence_shop_tournaments' ) ) {
                    sywat_resequence_shop_tournaments( $del_shop_id, $del_season_slug, $tournaments, $active_tournaments, $registrations );
                }

                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully deleted tournament <strong>' . esc_html( $del_tournament ) . '</strong> and purged all archers.</div>';
            }
        }

        return $notice;
    }
}
