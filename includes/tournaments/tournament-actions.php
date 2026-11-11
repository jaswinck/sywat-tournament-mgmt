<?php
/**
 * Sub-Module: Action Handlers & POST Request Router for Tournaments and Seasons
 * Path: includes/tournaments/tournament-actions.php
 * Author: SYWAT Archery
 * Version: 7.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
        $action = isset( $_POST['sywat_action'] ) ? sanitize_text_field( $_POST['sywat_action'] ) : '';

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

        // Action: Deactivate All Volunteer Hubs
        if ( $action === 'deactivate_all_hubs' ) {
            update_option( 'sywat_active_tournaments', array() );
            $active_tournaments = array();
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">⚪ <strong>All Volunteer Hubs Deactivated!</strong> Reset active status across all tournaments.</div>';
        }

        // Action: Bulk Sync Season TFAA Direct URLs (Dynamic Debug Toggle Support)
        if ( $action === 'sync_season_tfaa_urls' ) {
            $target_s = isset( $_POST['season_slug'] ) ? sanitize_text_field( $_POST['season_slug'] ) : '';
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

            $seasons     = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
            $orig_slug   = isset( $_POST['original_season_slug'] ) ? sanitize_text_field( $_POST['original_season_slug'] ) : '';
            $s_type      = sanitize_text_field( $_POST['season_type'] );
            $start_date  = sanitize_text_field( $_POST['start_date'] );
            $end_date    = sanitize_text_field( $_POST['end_date'] );
            $s_status    = sanitize_text_field( $_POST['season_status'] );
            $s_best_n    = intval( $_POST['season_best_n'] );

            // Validate Season Type Selection
            if ( empty( $s_type ) || ! in_array( $s_type, array( 'Indoor', 'Outdoor', 'Field 50' ) ) ) {
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

            $seasons[$s_slug] = array(
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

            $target_season = isset( $_POST['import_target_season'] ) ? sanitize_text_field( $_POST['import_target_season'] ) : '2026-2027 Indoor';
            $file_path     = $_FILES['schedule_file']['tmp_name'];
            $file_name     = $_FILES['schedule_file']['name'];

            $raw_rows      = function_exists( 'sywat_parse_schedule_file_rows' ) ? sywat_parse_schedule_file_rows( $file_path, $file_name ) : array();
            $processed     = function_exists( 'sywat_process_schedule_import_rows' ) ? sywat_process_schedule_import_rows( $raw_rows, $target_season ) : array( 'valid_tournaments' => array(), 'rejected_rows' => array() );
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
                $assoc_id = isset( $shop_name_to_id[$norm_off] ) ? $shop_name_to_id[$norm_off] : '';

                $tournaments[$t_name] = array(
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

                if ( ! empty( $assoc_id ) && ! in_array( $assoc_id, $affected_shops ) ) {
                    $affected_shops[] = $assoc_id;
                }

                $created_count++;
            }

            update_option( 'sywat_tournaments_list', $tournaments );
            delete_transient( $transient_key );

            // Re-sequence all host shops affected by bulk import
            if ( function_exists( 'sywat_resequence_shop_tournaments' ) ) {
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
            $res = sywat_sync_tfaa_standings();
            $notice = ! empty( $res['success'] ) 
                ? '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 <strong>TFAA Standings Synced!</strong> Successfully cached ' . intval( $res['count'] ) . ' active shooters from TFAA.</div>'
                : '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
        }

        // Action: Sync Single TFAA Tournament Scorecard
        if ( $action === 'sync_tfaa_single' ) {
            $target_t = isset( $_POST['target_tournament'] ) ? sanitize_text_field( $_POST['target_tournament'] ) : '';

            if ( empty( $target_t ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ No target tournament specified for TFAA sync.</div>';
            }

            if ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) && file_exists( SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php' ) ) {
                require_once SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php';
            }

            if ( function_exists( 'sywat_sync_tfaa_event_scores' ) ) {
                $res = sywat_sync_tfaa_event_scores( $target_t );
                if ( ! empty( $res['success'] ) ) {
                    $tournaments   = get_option( 'sywat_tournaments_list', array() );
                    $registrations = get_option( 'sywat_registrations', array() );
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 ' . $res['message'] . '</div>';
                } else {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
                }
            }
        }

        // Action: Bulk Sync All Active Weekend Host Locations
        if ( $action === 'sync_all_tfaa_weekend' ) {
            $active_hubs = ! empty( $active_tournaments ) ? $active_tournaments : get_option( 'sywat_active_tournaments', array() );

            if ( empty( $active_hubs ) || ! is_array( $active_hubs ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ No active weekend tournaments found to sync.</div>';
            }

            if ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) && file_exists( SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php' ) ) {
                require_once SYWAT_PLUGIN_DIR . 'includes/common/sywat-tfaa-sync.php';
            }

            $total_updated = 0;
            $synced_names  = array();
            $errors        = array();

            foreach ( $active_hubs as $t_name ) {
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
            $registrations = get_option( 'sywat_registrations', array() );

            $msg = '';
            if ( ! empty( $synced_names ) ) {
                $msg .= '<div id="sywat-notice" class="sywat-alert sywat-alert-success">✅ <strong>Bulk Sync Complete!</strong> Updated ' . $total_updated . ' total archer score record(s) across: ' . implode( ', ', $synced_names ) . '</div>';
            }
            if ( ! empty( $errors ) ) {
                $msg .= '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ Some locations failed to sync:<br>' . implode( '<br>', $errors ) . '</div>';
            }

            return $msg;
        }

        // Action: Toggle Volunteer Hub Display
        if ( $action === 'toggle_active_tournament' ) {
            $target_t = sanitize_text_field( $_POST['tournament_name'] );
            if ( in_array( $target_t, $active_tournaments ) ) {
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
            $t_name = sanitize_text_field( $_POST['tournament_name'] );
            if ( isset( $tournaments[$t_name] ) ) {
                $is_published = ! empty( $tournaments[$t_name]['scores_published'] );
                $tournaments[$t_name]['scores_published'] = ! $is_published;

                if ( ! $is_published ) {
                    $tournaments[$t_name]['published_at'] = current_time( 'mysql' );
                    $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Scores for <strong>' . esc_html( $t_name ) . '</strong> have been 🚀 <strong>Pushed to Season Standings</strong>!</div>';
                } else {
                    unset( $tournaments[$t_name]['published_at'] );
                    $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Scores for <strong>' . esc_html( $t_name ) . '</strong> have been 🔴 <strong>Hidden from Season Standings</strong>.</div>';
                }

                update_option( 'sywat_tournaments_list', $tournaments );
            }
        }

        // Action: Save / Edit Tournament Config
        if ( $action === 'save_tournament' ) {
            $t_name        = sanitize_text_field( $_POST['tournament_name'] );
            $old_name      = sanitize_text_field( $_POST['original_tournament_name'] );
            $season_slug   = sanitize_text_field( $_POST['season_slug'] );
            $new_slug      = sanitize_title( $t_name );

            $start_date    = sanitize_text_field( $_POST['start_date'] );
            $end_date      = sanitize_text_field( $_POST['end_date'] );
            if ( empty( $end_date ) ) $end_date = $start_date;

            $tfaa_event_id = isset( $_POST['tfaa_event_id'] ) ? sanitize_text_field( trim( $_POST['tfaa_event_id'] ) ) : '';

            $assigned_shop_id = $can_manage_seasons ? sanitize_text_field( $_POST['shop_id'] ) : $user_shop_id;
            if ( empty( $assigned_shop_id ) ) $assigned_shop_id = $user_shop_id;
            
            // Resolve shop name metadata from master shop list
            $all_shops          = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : ( function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : get_option( 'sywat_shops', get_option( 'sywat_shops_list', array() ) ) );
            $resolved_shop_name = '';
            if ( ! empty( $assigned_shop_id ) && isset( $all_shops[$assigned_shop_id] ) && is_array( $all_shops[$assigned_shop_id] ) ) {
                $s_info             = $all_shops[$assigned_shop_id];
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
                    $clean_day  = trim( sanitize_text_field( $day ) );
                    $clean_time = isset( $_POST['line_times'][$index] ) ? trim( sanitize_text_field( $_POST['line_times'][$index] ) ) : '';
                    if ( ! empty( $clean_day ) && ! empty( $clean_time ) ) {
                        $formatted_time = function_exists( 'sywat_format_line_time_token' ) ? sywat_format_line_time_token( $clean_time ) : $clean_time;
                        $combo_key      = strtolower( $clean_day . '_' . $formatted_time );
                        if ( ! in_array( $combo_key, $seen_combos ) ) {
                            $seen_combos[] = $combo_key;
                            $new_lines[]   = array(
                                'day'      => $clean_day,
                                'time'     => $formatted_time,
                                'name'     => trim( $clean_day . ' ' . $formatted_time ),
                                'cap_20yd' => isset( $_POST['cap_20yd'][$index] ) ? intval( $_POST['cap_20yd'][$index] ) : 20,
                                'cap_cub'  => isset( $_POST['cap_cub'][$index] ) ? intval( $_POST['cap_cub'][$index] ) : 5,
                            );
                        }
                    }
                }
            } elseif ( ! empty( $_POST['line_names'] ) && is_array( $_POST['line_names'] ) ) {
                // Fallback for legacy single line string POSTs
                foreach ( $_POST['line_names'] as $index => $name ) {
                    $clean_name = trim( sanitize_text_field( $name ) );
                    if ( ! empty( $clean_name ) ) {
                        $parts          = explode( ' ', $clean_name, 2 );
                        $formatted_time = isset( $parts[1] ) ? ( function_exists( 'sywat_format_line_time_token' ) ? sywat_format_line_time_token( $parts[1] ) : $parts[1] ) : '';
                        $formatted_name = trim( $parts[0] . ' ' . $formatted_time );
                        $combo_key      = strtolower( $formatted_name );

                        if ( ! in_array( $combo_key, $seen_combos ) ) {
                            $seen_combos[] = $combo_key;
                            $new_lines[]   = array(
                                'day'      => $parts[0],
                                'time'     => $formatted_time,
                                'name'     => $formatted_name,
                                'cap_20yd' => isset( $_POST['cap_20yd'][$index] ) ? intval( $_POST['cap_20yd'][$index] ) : 20,
                                'cap_cub'  => isset( $_POST['cap_cub'][$index] ) ? intval( $_POST['cap_cub'][$index] ) : 5,
                            );
                        }
                    }
                }
            }

            // Carry forward existing publishing metadata & resolved TFAA direct links if present
            $lookup_handle     = ( ! empty( $old_name ) && isset( $tournaments[$old_name] ) ) ? $old_name : $t_name;
            $is_scores_pub     = isset( $tournaments[$lookup_handle]['scores_published'] ) ? $tournaments[$lookup_handle]['scores_published'] : false;
            $published_at      = isset( $tournaments[$lookup_handle]['published_at'] ) ? $tournaments[$lookup_handle]['published_at'] : '';
            $existing_tfaa_url = isset( $tournaments[$lookup_handle]['tfaa_results_url'] ) ? $tournaments[$lookup_handle]['tfaa_results_url'] : '';

            if ( ! empty( $old_name ) && $old_name !== $t_name && isset( $tournaments[$old_name] ) ) {
                unset( $tournaments[$old_name] );
                $old_slug = sanitize_title( $old_name );

                foreach ( $registrations as &$reg ) {
                    if ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $old_name ) === 0 ) {
                        delete_option( 'sywat_t_' . $old_slug . '_' . $reg['id'] );
                        $reg['tournament'] = $t_name;
                        update_option( 'sywat_t_' . $new_slug . '_' . $reg['id'], $reg, false );
                    }
                }
                unset( $reg );

                if ( in_array( $old_name, $active_tournaments ) ) {
                    $active_tournaments = array_diff( $active_tournaments, array( $old_name ) );
                }
            }

            // Avoid key collision when adding a new tournament whose generated name already exists in $tournaments
            $storage_key = $t_name;
            if ( empty( $old_name ) && isset( $tournaments[$t_name] ) ) {
                $storage_key = $t_name . '-temp-' . uniqid();
            }

            $tournaments[$storage_key] = array(
                'name'                 => $t_name,
                'shop_id'              => $assigned_shop_id,
                'shop_name'            => $resolved_shop_name,
                'official_shop_name'   => $resolved_shop_name,
                'location_display'     => $resolved_shop_name,
                'season_slug'          => $season_slug,
                'season'               => $season_slug,
                'start_date'           => $start_date,
                'end_date'             => $end_date,
                'tfaa_event_id'        => $tfaa_event_id,
                'lines'                => $new_lines,
                'scores_published'     => $is_scores_pub,
            );

            if ( ! empty( $published_at ) ) {
                $tournaments[$storage_key]['published_at'] = $published_at;
            }

            if ( ! empty( $existing_tfaa_url ) ) {
                $tournaments[$storage_key]['tfaa_results_url'] = $existing_tfaa_url;
            }

            update_option( 'sywat_tournaments_list', $tournaments );
            update_option( 'sywat_active_tournaments', array_values( array_unique( $active_tournaments ) ) );
            update_option( 'sywat_current_tournament', $t_name );
            update_option( 'sywat_line_config', $new_lines );

            // Re-sequence shop tournaments chronologically by start date
            if ( function_exists( 'sywat_resequence_shop_tournaments' ) ) {
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
            $del_tournament = sanitize_text_field( $_POST['tournament_name'] );
            if ( isset( $tournaments[$del_tournament] ) ) {
                $del_shop_id     = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $tournaments[$del_tournament] ) : '';
                $del_season_slug = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $tournaments[$del_tournament] ) : '';

                unset( $tournaments[$del_tournament] );
                update_option( 'sywat_tournaments_list', $tournaments );

                $active_tournaments = array_diff( $active_tournaments, array( $del_tournament ) );
                update_option( 'sywat_active_tournaments', array_values( $active_tournaments ) );

                $del_slug = sanitize_title( $del_tournament );
                delete_option( 'sywat_dates_' . $del_slug );

                foreach ( $registrations as $reg ) {
                    if ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $del_tournament ) === 0 ) {
                        delete_option( 'sywat_t_' . $del_slug . '_' . $reg['id'] );
                    }
                }

                $registrations = array_values( array_filter( $registrations, function( $reg ) use ( $del_tournament ) {
                    return ! ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $del_tournament ) === 0 );
                }) );

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
