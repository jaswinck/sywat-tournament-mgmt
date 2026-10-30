<?php
/**
 * Sub-Module: Action Handlers for Tournaments and Seasons
 * Path: includes/tournaments/tournament-actions.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_handle_tournament_post_actions' ) ) {
    function sywat_handle_tournament_post_actions( &$tournaments, &$active_tournaments, &$registrations, $can_manage_seasons, $user_shop_id ) {
        $notice = '';
        $action = isset($_POST['sywat_action']) ? sanitize_text_field($_POST['sywat_action']) : '';

        // Save Season Action
        if ( $action === 'save_season' ) {
            if ( ! $can_manage_seasons ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators and TFAA webmasters can configure seasons.</div>';
            }

            $seasons     = sywat_get_seasons();
            $s_name      = sanitize_text_field( $_POST['season_name'] );
            $s_slug      = sanitize_title( $s_name );
            $s_type      = sanitize_text_field( $_POST['season_type'] );
            $s_years     = sanitize_text_field( $_POST['season_years'] );
            $s_status    = sanitize_text_field( $_POST['season_status'] );
            $s_best_n    = intval( $_POST['season_best_n'] );

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
                'year_label'   => $s_years,
                'status'       => $s_status,
                'best_n_count' => $s_best_n > 0 ? $s_best_n : 3,
            );

            update_option( 'sywat_seasons_list', $seasons );
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Season <strong>' . esc_html( $s_name ) . '</strong> saved successfully!</div>';
        }

        if ( $action === 'sync_tfaa_now' && function_exists( 'sywat_sync_tfaa_standings' ) ) {
            $res = sywat_sync_tfaa_standings();
            $notice = ! empty( $res['success'] ) 
                ? '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 <strong>TFAA Standings Synced!</strong> Successfully cached ' . intval($res['count']) . ' active shooters from TFAA.</div>'
                : '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
        }

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

            $form_line_count = isset( $_POST['line_count'] ) ? intval( $_POST['line_count'] ) : 4;
            $new_lines = array();
            if ( ! empty( $_POST['line_names'] ) ) {
                foreach ( $_POST['line_names'] as $index => $name ) {
                    if ( $index < $form_line_count ) {
                        $new_lines[] = array(
                            'name'     => sanitize_text_field( $name ),
                            'cap_20yd' => intval( $_POST['cap_20yd'][$index] ),
                            'cap_cub'  => intval( $_POST['cap_cub'][$index] ),
                        );
                    }
                }
            }

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
                'shop_id'     => $assigned_shop_id,
                'season_slug' => $season_slug,
                'start_date'  => $start_date,
                'end_date'    => $end_date,
                'lines'       => $new_lines,
            );

            update_option( 'sywat_tournaments_list', $tournaments );
            update_option( 'sywat_active_tournaments', array_values( array_unique( $active_tournaments ) ) );
            update_option( 'sywat_current_tournament', $t_name );
            update_option( 'sywat_line_config', $new_lines );

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Tournament <strong>' . esc_html( $t_name ) . '</strong> saved successfully!</div>';
        }

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
