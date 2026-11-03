<?php
/**
 * Sub-Module: Action Handlers for Tournaments and Seasons
 * Path: includes/tournaments/tournament-actions.php
 * Author: SYWAT Archery
 * Version: 3.6.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_handle_tournament_post_actions' ) ) {
    function sywat_handle_tournament_post_actions( &$tournaments, &$active_tournaments, &$registrations, $can_manage_seasons, $user_shop_id ) {
        $notice = '';
        $action = isset($_POST['sywat_action']) ? sanitize_text_field($_POST['sywat_action']) : '';

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

        // Action: Save Season Configuration
        if ( $action === 'save_season' ) {
            if ( ! $can_manage_seasons ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators and TFAA webmasters can configure seasons.</div>';
            }

            $seasons     = sywat_get_seasons();
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
                unset($s);
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
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Season <strong>' . esc_html( $s_name ) . '</strong> saved successfully!</div>';
        }

        // Action: Sync TFAA Cache
        if ( $action === 'sync_tfaa_now' && function_exists( 'sywat_sync_tfaa_standings' ) ) {
            $res = sywat_sync_tfaa_standings();
            $notice = ! empty( $res['success'] ) 
                ? '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 <strong>TFAA Standings Synced!</strong> Successfully cached ' . intval($res['count']) . ' active shooters from TFAA.</div>'
                : '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
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
            $t_name      = sanitize_text_field( $_POST['tournament_name'] );
            $old_name    = sanitize_text_field( $_POST['original_tournament_name'] );
            $season_slug = sanitize_text_field( $_POST['season_slug'] );
            $new_slug    = sanitize_title( $t_name );

            $start_date  = sanitize_text_field( $_POST['start_date'] );
            $end_date    = sanitize_text_field( $_POST['end_date'] );
            if ( empty( $end_date ) ) $end_date = $start_date;

            $assigned_shop_id = $can_manage_seasons ? sanitize_text_field( $_POST['shop_id'] ) : $user_shop_id;
            if ( empty( $assigned_shop_id ) ) $assigned_shop_id = $user_shop_id;
            
            $formatted_dates = sywat_format_tournament_dates( $start_date, $end_date );
            update_option( 'sywat_dates_' . $new_slug, $formatted_dates );

            // Process Dynamic Shooting Lines Repeater (Split Day & Time + Deduplication)
            $new_lines = array();
            $seen_combos = array();

            if ( ! empty( $_POST['line_days'] ) && is_array( $_POST['line_days'] ) ) {
                foreach ( $_POST['line_days'] as $index => $day ) {
                    $clean_day  = trim( sanitize_text_field( $day ) );
                    $clean_time = isset( $_POST['line_times'][$index] ) ? trim( sanitize_text_field( $_POST['line_times'][$index] ) ) : '';
                    if ( ! empty( $clean_day ) && ! empty( $clean_time ) ) {
                        $combo_key = strtolower( $clean_day . '_' . $clean_time );
                        if ( ! in_array( $combo_key, $seen_combos ) ) {
                            $seen_combos[] = $combo_key;
                            $new_lines[]   = array(
                                'day'      => $clean_day,
                                'time'     => $clean_time,
                                'name'     => trim( $clean_day . ' ' . $clean_time ),
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
                        $parts     = explode( ' ', $clean_name, 2 );
                        $combo_key = strtolower( $clean_name );
                        if ( ! in_array( $combo_key, $seen_combos ) ) {
                            $seen_combos[] = $combo_key;
                            $new_lines[]   = array(
                                'day'      => $parts[0],
                                'time'     => isset( $parts[1] ) ? $parts[1] : '',
                                'name'     => $clean_name,
                                'cap_20yd' => isset( $_POST['cap_20yd'][$index] ) ? intval( $_POST['cap_20yd'][$index] ) : 20,
                                'cap_cub'  => isset( $_POST['cap_cub'][$index] ) ? intval( $_POST['cap_cub'][$index] ) : 5,
                            );
                        }
                    }
                }
            }

            // Carry forward existing publishing metadata if present
            $lookup_handle = ( ! empty( $old_name ) && isset( $tournaments[$old_name] ) ) ? $old_name : $t_name;
            $is_scores_pub = isset( $tournaments[$lookup_handle]['scores_published'] ) ? $tournaments[$lookup_handle]['scores_published'] : false;
            $published_at  = isset( $tournaments[$lookup_handle]['published_at'] ) ? $tournaments[$lookup_handle]['published_at'] : '';

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
                unset($reg);

                if ( in_array( $old_name, $active_tournaments ) ) {
                    $active_tournaments = array_diff( $active_tournaments, array( $old_name ) );
                    $active_tournaments[] = $t_name;
                }
            } else {
                if ( ! in_array( $t_name, $active_tournaments ) ) {
                    $active_tournaments[] = $t_name;
                }
            }

            $tournaments[$t_name] = array(
                'shop_id'          => $assigned_shop_id,
                'season_slug'      => $season_slug,
                'start_date'       => $start_date,
                'end_date'         => $end_date,
                'lines'            => $new_lines,
                'scores_published' => $is_scores_pub,
            );

            if ( ! empty( $published_at ) ) {
                $tournaments[$t_name]['published_at'] = $published_at;
            }

            update_option( 'sywat_tournaments_list', $tournaments );
            update_option( 'sywat_active_tournaments', array_values( array_unique( $active_tournaments ) ) );
            update_option( 'sywat_current_tournament', $t_name );
            update_option( 'sywat_line_config', $new_lines );

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Tournament <strong>' . esc_html( $t_name ) . '</strong> saved successfully!</div>';
        }

        // Action: Delete Tournament
        if ( $action === 'delete_tournament' ) {
            $del_tournament = sanitize_text_field( $_POST['tournament_name'] );
            if ( isset( $tournaments[$del_tournament] ) ) {
                unset( $tournaments[$del_tournament] );
                update_option( 'sywat_tournaments_list', $tournaments );

                $active_tournaments = array_diff( $active_tournaments, array( $del_tournament ) );
                update_option( 'sywat_active_tournaments', array_values( $active_tournaments ) );

                $del_slug = sanitize_title( $del_tournament );
                delete_option('sywat_dates_' . $del_slug);

                foreach ( $registrations as $reg ) {
                    if ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $del_tournament ) === 0 ) {
                        delete_option( 'sywat_t_' . $del_slug . '_' . $reg['id'] );
                    }
                }

                $registrations = array_values( array_filter( $registrations, function( $reg ) use ( $del_tournament ) {
                    return ! ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $del_tournament ) === 0 );
                }) );

                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully deleted tournament <strong>' . esc_html( $del_tournament ) . '</strong> and purged all archers.</div>';
            }
        }

        return $notice;
    }
}
