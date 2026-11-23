<?php
/**
 * Sub-Module: Action Handlers & POST Request Router for Tournaments and Seasons
 * Path: includes/tournaments/tournament-actions.php
 * Description: Handles tournament/season POST actions, persists season and tournament pricing/round profiles, public online registration On/Off & lead-time overrides (inheriting host shop defaults), external registration links, and promoter flags, excludes historical_import archives from weekend bulk sync/push operations, ensures global_push_standings publishes only tournaments with recorded scores while keeping upcoming/unscored tournaments unpublished, avoids redundant remote TFAA scrapes during capacity/metadata-only edits, sorts shooting lines chronologically on save with automatic registration/lock index remapping, preserves tournament keys/metadata during capacity edits, and safely migrates registrations, scores, and line locks if a tournament is explicitly renamed or re-sequenced.
 * Compliance: Pure action layer — zero HTML echo at include time, indexed active-tournament registration lookups, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.19.0
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
            $s_type     = isset( $_POST['season_type'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['season_type'] ) ) ) : '';
            $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
            $end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
            $s_status   = isset( $_POST['season_status'] ) ? sanitize_text_field( wp_unslash( $_POST['season_status'] ) ) : 'active';
            $s_best_n   = isset( $_POST['season_best_n'] ) ? intval( wp_unslash( $_POST['season_best_n'] ) ) : 3;

            // Validate Season Type Selection
            if ( empty( $s_type ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a valid Season Type.</div>';
            }

            if ( empty( $start_date ) || empty( $end_date ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select both Start and End dates for the season.</div>';
            }

            // Sanitize Enabled Pricing & Round Profiles for this Season
            $raw_s_profiles     = isset( $_POST['season_pricing_profiles'] ) && is_array( $_POST['season_pricing_profiles'] )
                ? wp_unslash( $_POST['season_pricing_profiles'] )
                : array();
            $s_pricing_profiles = array();
            foreach ( $raw_s_profiles as $sp_slug ) {
                $clean_sp = sanitize_title( (string) $sp_slug );
                if ( $clean_sp !== '' && ! in_array( $clean_sp, $s_pricing_profiles, true ) ) {
                    $s_pricing_profiles[] = $clean_sp;
                }
            }
            if ( empty( $s_pricing_profiles ) && function_exists( 'sywat_get_season_pricing_profiles' ) ) {
                $fallback_profs     = sywat_get_season_pricing_profiles( array( 'type' => $s_type ) );
                $s_pricing_profiles = array_keys( $fallback_profs );
            }

            $s_promo_enabled = ! empty( $_POST['season_promo_enabled'] );

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
                'slug'             => $s_slug,
                'name'             => $s_name,
                'type'             => $s_type,
                'start_date'       => $start_date,
                'end_date'         => $end_date,
                'year_label'       => $year_label,
                'status'           => $s_status,
                'best_n_count'     => $s_best_n > 0 ? $s_best_n : 3,
                'pricing_profiles' => $s_pricing_profiles,
                'promo_enabled'    => $s_promo_enabled,
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

            // Resolve inherited Pricing Profiles for imported tournaments in this season
            $imported_t_profiles = array();
            if ( function_exists( 'sywat_get_tournament_pricing_profiles' ) ) {
                $resolved_imp_profs  = sywat_get_tournament_pricing_profiles( array( 'season_slug' => $target_season ) );
                $imported_t_profiles = array_keys( $resolved_imp_profs );
            }

            // Extract shop lookup map for assigning shop_id and default public registration settings on import
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
                $t_name      = $t['tournament_name'];
                $official    = $t['official_shop_name'];
                $norm_off    = strtolower( trim( $official ) );
                $assoc_id    = isset( $shop_name_to_id[ $norm_off ] ) ? $shop_name_to_id[ $norm_off ] : '';
                $shop_record = ( ! empty( $assoc_id ) && isset( $all_shops[ $assoc_id ] ) && is_array( $all_shops[ $assoc_id ] ) )
                    ? $all_shops[ $assoc_id ]
                    : array();

                $shop_reg_defaults = function_exists( 'sywat_get_shop_public_reg_defaults' )
                    ? sywat_get_shop_public_reg_defaults( $shop_record, $all_shops )
                    : array(
                        'public_reg_default'     => ! empty( $shop_record['public_reg_default'] ) ? '1' : '0',
                        'public_reg_days_before' => ( isset( $shop_record['public_reg_days_before'] ) && $shop_record['public_reg_days_before'] !== '' )
                            ? max( 0, min( 365, intval( $shop_record['public_reg_days_before'] ) ) )
                            : 15,
                    );

                $tournaments[ $t_name ] = array(
                    'name'                   => $t_name,
                    'season'                 => $target_season,
                    'season_slug'            => $target_season,
                    'shop_id'                => $assoc_id,
                    'shop_name'              => $official,
                    'official_shop_name'     => $official,
                    'location_display'       => $t['location_display'],
                    'start_date'             => $t['start_date_formatted'],
                    'end_date'               => $t['end_date_formatted'],
                    'event_type'             => $t['event_type'],
                    'pricing_profiles'       => $imported_t_profiles,
                    'public_reg_enabled'     => ( $shop_reg_defaults['public_reg_default'] === '1' ),
                    'public_reg_days_before' => intval( $shop_reg_defaults['public_reg_days_before'] ),
                    'registration_links'     => array(),
                    'notes'                  => sanitize_textarea_field( $t['notes'] ),
                    'lines'                  => $t['line_slots'],
                    'registration_enabled'   => $t['registration_enabled'],
                    'scores_published'       => false,
                    'created_at'             => current_time( 'mysql' ),
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
                    $registrations = sywat_get_all_registrations( $tournaments );
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
                    $registrations = function_exists( 'sywat_get_all_registrations' ) ? sywat_get_all_registrations( $tournaments ) : get_option( 'sywat_registrations', array() );
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 ' . $res['message'] . '</div>';
                } else {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
                }
            }
        }

        // Action: Bulk Sync All Active Tournament Scores with Populated TFAA Results Links (Excludes historical_import)
        if ( $action === 'sync_all_tfaa_weekend' ) {
            $target_tournaments = array();

            if ( is_array( $tournaments ) ) {
                foreach ( $tournaments as $t_name => $t_data ) {
                    if ( is_array( $t_data ) && ! empty( $t_data['historical_import'] ) ) {
                        continue;
                    }
                    $has_url = ! empty( $t_data['tfaa_results_url'] );
                    $has_id  = ! empty( $t_data['tfaa_event_id'] );
                    if ( $has_url || $has_id ) {
                        $target_tournaments[] = $t_name;
                    }
                }
            }

            if ( empty( $target_tournaments ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ No active tournaments with configured TFAA result links were found. Please run <strong>"Sync TFAA Links"</strong> first or set valid TFAA Event IDs.</div>';
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
            $registrations = function_exists( 'sywat_get_all_registrations' ) ? sywat_get_all_registrations( $tournaments ) : get_option( 'sywat_registrations', array() );

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

        // Action: Global Push Scored Weekend Tournaments to Season Standings (Keeps unscored/upcoming events unpublished)
        if ( $action === 'global_push_standings' ) {
            if ( ! $can_manage_seasons ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators and TFAA webmasters can push global standings.</div>';
            }

            $target_season      = isset( $_POST['season_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['season_slug'] ) ) : '';
            $target_season_slug = sanitize_title( $target_season );

            // Single O(R) pass over active registrations to identify tournaments with recorded scores (> 0)
            $scored_tourn_slugs = array();
            if ( is_array( $registrations ) ) {
                foreach ( $registrations as $reg ) {
                    if ( ! is_array( $reg ) || empty( $reg['tournament'] ) ) {
                        continue;
                    }
                    $reg_score = isset( $reg['score'] ) ? intval( $reg['score'] ) : 0;
                    if ( $reg_score > 0 ) {
                        $scored_tourn_slugs[ sanitize_title( (string) $reg['tournament'] ) ] = true;
                    }
                }
            }

            $pushed_count     = 0;
            $skipped_unscored = 0;
            $now_time         = current_time( 'mysql' );

            foreach ( $tournaments as $t_name => &$t_data ) {
                if ( ! is_array( $t_data ) || ! empty( $t_data['historical_import'] ) ) {
                    continue;
                }

                if ( $target_season_slug !== '' ) {
                    $t_season = function_exists( 'sywat_get_tournament_season_slug' )
                        ? sywat_get_tournament_season_slug( $t_data )
                        : ( isset( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( isset( $t_data['season'] ) ? $t_data['season'] : '' ) );
                    if ( sanitize_title( (string) $t_season ) !== $target_season_slug ) {
                        continue;
                    }
                }

                $t_slug     = sanitize_title( (string) $t_name );
                $has_scores = ! empty( $scored_tourn_slugs[ $t_slug ] );

                if ( ! $has_scores && $t_slug !== '' ) {
                    $scores_map = get_option( 'sywat_scores_' . $t_slug, array() );
                    if ( is_array( $scores_map ) ) {
                        foreach ( $scores_map as $s_item ) {
                            if ( is_array( $s_item ) && isset( $s_item['score'] ) && intval( $s_item['score'] ) > 0 ) {
                                $has_scores = true;
                                break;
                            }
                        }
                    }
                }

                if ( $has_scores ) {
                    $t_data['scores_published'] = true;
                    $t_data['published_at']     = $now_time;
                    $pushed_count++;
                } else {
                    $t_data['scores_published'] = false;
                    unset( $t_data['published_at'] );
                    $skipped_unscored++;
                }
            }
            unset( $t_data );

            update_option( 'sywat_tournaments_list', $tournaments );

            if ( function_exists( 'sywat_recalculate_season_standings' ) ) {
                sywat_recalculate_season_standings();
            }

            if ( $pushed_count > 0 ) {
                $unscored_suffix = ( $skipped_unscored > 0 )
                    ? ' (' . $skipped_unscored . ' upcoming/unscored tournament(s) kept unpublished.)'
                    : '';
                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🚀 <strong>Weekend Standings Pushed!</strong> Published scores for <strong>' . $pushed_count . '</strong> tournament(s) with recorded scores to Season Standings.' . $unscored_suffix . '</div>';
            } else {
                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ No tournaments with recorded scores were found to publish. Upcoming/unscored tournaments remain unpublished.</div>';
            }
        }

        // Action: Toggle Push / Publish Scores to Season Standings (Single Event)
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
            $existing_lines  = ( isset( $existing_record['lines'] ) && is_array( $existing_record['lines'] ) ) ? $existing_record['lines'] : array();
            $orig_start_date = isset( $existing_record['start_date'] ) ? $existing_record['start_date'] : '';
            $orig_shop_id    = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $existing_record ) : ( isset( $existing_record['shop_id'] ) ? $existing_record['shop_id'] : '' );
            $orig_season     = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $existing_record ) : ( isset( $existing_record['season_slug'] ) ? $existing_record['season_slug'] : '' );

            // Resolve Offered Round / Pricing Format(s) for this Tournament
            $raw_t_profiles     = isset( $_POST['tournament_pricing_profiles'] ) && is_array( $_POST['tournament_pricing_profiles'] )
                ? wp_unslash( $_POST['tournament_pricing_profiles'] )
                : array();
            $t_pricing_profiles = array();
            foreach ( $raw_t_profiles as $tp_slug ) {
                $clean_tp = sanitize_title( (string) $tp_slug );
                if ( $clean_tp !== '' && ! in_array( $clean_tp, $t_pricing_profiles, true ) ) {
                    $t_pricing_profiles[] = $clean_tp;
                }
            }
            if ( empty( $t_pricing_profiles ) ) {
                if ( ! empty( $existing_record['pricing_profiles'] ) && is_array( $existing_record['pricing_profiles'] ) ) {
                    $t_pricing_profiles = $existing_record['pricing_profiles'];
                } elseif ( function_exists( 'sywat_get_tournament_pricing_profiles' ) ) {
                    $inherited_profs    = sywat_get_tournament_pricing_profiles( array( 'season_slug' => $season_slug ) );
                    $t_pricing_profiles = array_keys( $inherited_profs );
                }
            }

            // Sanitize repeatable External Online Registration Links for this Tournament
            $raw_t_link_labels = isset( $_POST['tourn_reg_link_labels'] ) && is_array( $_POST['tourn_reg_link_labels'] )
                ? wp_unslash( $_POST['tourn_reg_link_labels'] )
                : array();
            $raw_t_link_urls   = isset( $_POST['tourn_reg_link_urls'] ) && is_array( $_POST['tourn_reg_link_urls'] )
                ? wp_unslash( $_POST['tourn_reg_link_urls'] )
                : array();
            $t_reg_links       = function_exists( 'sywat_sanitize_registration_links' )
                ? sywat_sanitize_registration_links( $raw_t_link_labels, $raw_t_link_urls )
                : array();

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

            // Resolve Public Online Registration On/Off & lead-time days (explicit POST override -> existing record -> host shop default)
            $shop_reg_defaults = function_exists( 'sywat_get_shop_public_reg_defaults' )
                ? sywat_get_shop_public_reg_defaults( $assigned_shop_id, $all_shops )
                : array(
                    'public_reg_default'     => '0',
                    'public_reg_days_before' => 15,
                );

            if ( isset( $_POST['public_reg_enabled'] ) ) {
                $public_reg_enabled = ( sanitize_text_field( wp_unslash( $_POST['public_reg_enabled'] ) ) === '1' );
            } elseif ( isset( $existing_record['public_reg_enabled'] ) ) {
                $public_reg_enabled = ! empty( $existing_record['public_reg_enabled'] );
            } else {
                $public_reg_enabled = ( $shop_reg_defaults['public_reg_default'] === '1' );
            }

            if ( isset( $_POST['public_reg_days_before'] ) && $_POST['public_reg_days_before'] !== '' ) {
                $public_reg_days = max( 0, min( 365, intval( wp_unslash( $_POST['public_reg_days_before'] ) ) ) );
            } elseif ( isset( $existing_record['public_reg_days_before'] ) && $existing_record['public_reg_days_before'] !== '' ) {
                $public_reg_days = max( 0, min( 365, intval( $existing_record['public_reg_days_before'] ) ) );
            } else {
                $public_reg_days = intval( $shop_reg_defaults['public_reg_days_before'] );
            }

            // -------------------------------------------------------------
            // MANUAL SAVE OVERLAP GUARD: Prohibit overlapping shop event dates
            // -------------------------------------------------------------
            $target_shop_norm = strtolower( trim( $resolved_shop_name ) );
            foreach ( $tournaments as $ex_key => $ex_data ) {
                if ( is_array( $ex_data ) && ! empty( $ex_data['historical_import'] ) ) {
                    continue;
                }
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

            // Sort lines chronologically (Fri -> Sat -> Sun, then by start time)
            if ( count( $new_lines ) > 1 && function_exists( 'sywat_compare_tournament_lines' ) ) {
                usort( $new_lines, 'sywat_compare_tournament_lines' );
            }

            // Carry forward existing publishing, classification & resolved TFAA metadata if present
            $is_scores_pub     = isset( $existing_record['scores_published'] ) ? $existing_record['scores_published'] : false;
            $published_at      = isset( $existing_record['published_at'] ) ? $existing_record['published_at'] : '';
            $existing_tfaa_url = isset( $existing_record['tfaa_results_url'] ) ? $existing_record['tfaa_results_url'] : '';
            $existing_ev_type  = isset( $existing_record['event_type'] ) ? $existing_record['event_type'] : 'SYWAT';
            $existing_notes    = isset( $existing_record['notes'] ) ? $existing_record['notes'] : '';
            $existing_reg_en   = isset( $existing_record['registration_enabled'] ) ? $existing_record['registration_enabled'] : true;
            $existing_created  = isset( $existing_record['created_at'] ) ? $existing_record['created_at'] : current_time( 'mysql' );
            $is_historical     = ! empty( $existing_record['historical_import'] );

            if ( ! empty( $old_name ) && $old_name !== $t_name && isset( $tournaments[ $old_name ] ) ) {
                if ( empty( $registrations ) && function_exists( 'sywat_get_all_registrations' ) ) {
                    $registrations = sywat_get_all_registrations( $tournaments, $old_name );
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

            // Remap existing registrations and closed-line locks if line order changed on save
            if ( ! empty( $existing_lines ) && ! empty( $new_lines ) ) {
                $normalize_line_key = function( $raw_line_str ) {
                    $s = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $raw_line_str ) ) );
                    $s = preg_replace( '/:00\s*(am|pm)/i', '$1', $s );
                    $s = preg_replace( '/(\d)\s+(am|pm)/i', '$1$2', $s );
                    return $s;
                };

                $old_idx_to_key = array();
                foreach ( $existing_lines as $old_i => $old_l ) {
                    if ( is_array( $old_l ) ) {
                        $o_label = ! empty( $old_l['name'] )
                            ? (string) $old_l['name']
                            : trim( ( isset( $old_l['day'] ) ? $old_l['day'] : '' ) . ' ' . ( isset( $old_l['time'] ) ? $old_l['time'] : '' ) );
                        if ( $o_label !== '' ) {
                            $old_idx_to_key[ (string) $old_i ] = $normalize_line_key( $o_label );
                        }
                    }
                }

                $new_key_to_idx = array();
                foreach ( $new_lines as $new_i => $new_l ) {
                    if ( is_array( $new_l ) ) {
                        $n_label = ! empty( $new_l['name'] )
                            ? (string) $new_l['name']
                            : trim( ( isset( $new_l['day'] ) ? $new_l['day'] : '' ) . ' ' . ( isset( $new_l['time'] ) ? $new_l['time'] : '' ) );
                        if ( $n_label !== '' ) {
                            $new_key_to_idx[ $normalize_line_key( $n_label ) ] = $new_i;
                        }
                    }
                }

                $idx_remap   = array();
                $needs_remap = false;
                foreach ( $old_idx_to_key as $old_i_str => $norm_lbl ) {
                    if ( isset( $new_key_to_idx[ $norm_lbl ] ) ) {
                        $mapped_i                = $new_key_to_idx[ $norm_lbl ];
                        $idx_remap[ $old_i_str ] = $mapped_i;
                        if ( (string) $mapped_i !== $old_i_str ) {
                            $needs_remap = true;
                        }
                    }
                }

                if ( $needs_remap ) {
                    if ( empty( $registrations ) && function_exists( 'sywat_get_all_registrations' ) ) {
                        $registrations = sywat_get_all_registrations( $tournaments, $t_name );
                    }

                    foreach ( $registrations as &$reg ) {
                        if ( ! isset( $reg['tournament'] ) ) {
                            continue;
                        }
                        $matches_event = ( strcasecmp( $reg['tournament'], $t_name ) === 0 || sanitize_title( $reg['tournament'] ) === $new_slug )
                                      || ( ! empty( $old_name ) && strcasecmp( $reg['tournament'], $old_name ) === 0 );
                        if ( ! $matches_event ) {
                            continue;
                        }

                        $curr_idx_str = isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '';
                        $target_idx   = null;

                        if ( $curr_idx_str !== '' && isset( $idx_remap[ $curr_idx_str ] ) ) {
                            $target_idx = $idx_remap[ $curr_idx_str ];
                        } elseif ( ! empty( $reg['line_name'] ) ) {
                            $norm_reg_line = $normalize_line_key( $reg['line_name'] );
                            if ( isset( $new_key_to_idx[ $norm_reg_line ] ) ) {
                                $target_idx = $new_key_to_idx[ $norm_reg_line ];
                            }
                        }

                        if ( $target_idx !== null && isset( $new_lines[ $target_idx ] ) ) {
                            $reg['line_index'] = $target_idx;
                            $reg['line_name']  = $new_lines[ $target_idx ]['name'];
                            if ( ! empty( $reg['id'] ) ) {
                                update_option( 'sywat_t_' . $new_slug . '_' . $reg['id'], $reg, false );
                            }
                        }
                    }
                    unset( $reg );

                    $closed_lines = get_option( 'sywat_closed_lines_' . $new_slug, null );
                    if ( is_array( $closed_lines ) && ! empty( $closed_lines ) ) {
                        $remapped_closed = array();
                        foreach ( $closed_lines as $c_val ) {
                            $c_str = (string) $c_val;
                            if ( isset( $idx_remap[ $c_str ] ) ) {
                                $remapped_closed[] = $idx_remap[ $c_str ];
                            } else {
                                $remapped_closed[] = $c_val;
                            }
                        }
                        update_option( 'sywat_closed_lines_' . $new_slug, array_values( array_unique( $remapped_closed ) ), false );
                    }

                    $line_locks = get_option( 'sywat_locks_' . $new_slug, null );
                    if ( is_array( $line_locks ) && ! empty( $line_locks ) ) {
                        $remapped_locks = array();
                        foreach ( $line_locks as $l_key => $l_val ) {
                            $l_str = (string) $l_key;
                            if ( isset( $idx_remap[ $l_str ] ) ) {
                                $remapped_locks[ $idx_remap[ $l_str ] ] = $l_val;
                            } else {
                                $remapped_locks[ $l_key ] = $l_val;
                            }
                        }
                        update_option( 'sywat_locks_' . $new_slug, $remapped_locks, false );
                    }
                }
            }

            // Avoid key collision when adding a new tournament whose generated name already exists in $tournaments
            $storage_key = $t_name;
            if ( empty( $old_name ) && isset( $tournaments[ $t_name ] ) ) {
                $storage_key = $t_name . '-temp-' . uniqid();
            }

            $tournaments[ $storage_key ] = array(
                'name'                   => $t_name,
                'shop_id'                => $assigned_shop_id,
                'shop_name'              => $resolved_shop_name,
                'official_shop_name'     => $resolved_shop_name,
                'location_display'       => $resolved_shop_name,
                'season_slug'            => $season_slug,
                'season'                 => $season_slug,
                'start_date'             => $start_date,
                'end_date'               => $end_date,
                'event_type'             => $existing_ev_type,
                'pricing_profiles'       => $t_pricing_profiles,
                'public_reg_enabled'     => $public_reg_enabled,
                'public_reg_days_before' => $public_reg_days,
                'registration_links'     => $t_reg_links,
                'notes'                  => $existing_notes,
                'registration_enabled'   => $existing_reg_en,
                'tfaa_event_id'          => $tfaa_event_id,
                'lines'                  => $new_lines,
                'scores_published'       => $is_scores_pub,
                'created_at'             => $existing_created,
            );

            if ( $is_historical ) {
                $tournaments[ $storage_key ]['historical_import'] = 1;
            }

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

            if ( $should_resequence && ! $is_historical && function_exists( 'sywat_resequence_shop_tournaments' ) ) {
                if ( empty( $registrations ) && function_exists( 'sywat_get_all_registrations' ) ) {
                    $registrations = sywat_get_all_registrations( $tournaments );
                }
                sywat_resequence_shop_tournaments( $assigned_shop_id, $season_slug, $tournaments, $active_tournaments, $registrations );
            }

            // Trigger season TFAA link sync only on new event creation or date/season change when no link is configured yet
            $needs_tfaa_link_sync = ! $is_historical
                && $should_resequence
                && empty( $existing_tfaa_url )
                && empty( $tfaa_event_id );

            if ( $needs_tfaa_link_sync && function_exists( 'sywat_sync_season_tfaa_urls' ) ) {
                sywat_sync_season_tfaa_urls( $season_slug );
                $tournaments = get_option( 'sywat_tournaments_list', array() );
            }

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Tournament <strong>' . esc_html( $t_name ) . '</strong> saved successfully!</div>';
        }

        // Action: Delete Tournament
        if ( $action === 'delete_tournament' ) {
            $del_tournament = sanitize_text_field( wp_unslash( $_POST['tournament_name'] ) );
            if ( isset( $tournaments[ $del_tournament ] ) ) {
                $del_shop_id     = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $tournaments[ $del_tournament ] ) : '';
                $del_season_slug = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $tournaments[ $del_tournament ] ) : '';
                $del_is_hist     = ! empty( $tournaments[ $del_tournament ]['historical_import'] );

                if ( empty( $registrations ) && function_exists( 'sywat_get_all_registrations' ) ) {
                    $registrations = sywat_get_all_registrations( $tournaments, $del_tournament );
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

                // Re-sequence remaining active shop tournaments to collapse sequence gaps
                if ( ! $del_is_hist && ! empty( $del_shop_id ) && function_exists( 'sywat_resequence_shop_tournaments' ) ) {
                    $active_regs = sywat_get_all_registrations( $tournaments );
                    sywat_resequence_shop_tournaments( $del_shop_id, $del_season_slug, $tournaments, $active_tournaments, $active_regs );
                }

                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully deleted tournament <strong>' . esc_html( $del_tournament ) . '</strong> and purged all archers.</div>';
            }
        }

        return $notice;
    }
}
