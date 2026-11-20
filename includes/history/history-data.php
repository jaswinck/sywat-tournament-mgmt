<?php
/**
 * Sub-Module: Historical TFAA Results Data Access & Atomic Batch Persistence
 * Path: includes/history/history-data.php
 * Description: Pure data access and remote HTTP fetching layer for historical TFAA season/tournament migration. Executes atomic MySQL transactions and chunked multi-row SQL upserts into wp_options (autoload = 'no') so 16+ seasons of independent historical scorecards import rapidly without wp_users bloat or PHP timeouts.
 * Compliance: Pure data layer — zero HTML echo, zero presentation formatting, explicit MySQL transactions, chunked batch SQL writes, object cache invalidation, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetch remote HTML from texasfieldarchery.org safely via wp_remote_get().
 *
 * @param string $url Target TFAA results URL.
 * @return array      Associative array with 'success' (bool), 'html' (string), and 'error' (string).
 */
if ( ! function_exists( 'sywat_history_fetch_remote_html' ) ) {
    function sywat_history_fetch_remote_html( $url ) {
        $clean_url = esc_url_raw( trim( (string) $url ) );
        if ( $clean_url === '' || stripos( $clean_url, 'https://texasfieldarchery.org/' ) !== 0 ) {
            return array(
                'success' => false,
                'html'    => '',
                'error'   => 'Invalid TFAA source URL.',
            );
        }

        $response = wp_remote_get(
            $clean_url,
            array(
                'timeout'   => 25,
                'sslverify' => false,
                'headers'   => array(
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'html'    => '',
                'error'   => 'HTTP request failed: ' . $response->get_error_message(),
            );
        }

        $status_code = intval( wp_remote_retrieve_response_code( $response ) );
        if ( $status_code < 200 || $status_code >= 300 ) {
            return array(
                'success' => false,
                'html'    => '',
                'error'   => 'TFAA server returned HTTP ' . $status_code . '.',
            );
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( trim( $body ) === '' ) {
            return array(
                'success' => false,
                'html'    => '',
                'error'   => 'Empty HTML body returned from TFAA.',
            );
        }

        return array(
            'success' => true,
            'html'    => $body,
            'error'   => '',
        );
    }
}

/**
 * Retrieve O(1) lookup statistics of already-imported historical seasons and tournament counts.
 *
 * @return array Associative map keyed by season slug => array( 'exists' => bool, 'status' => string, 'event_count' => int ).
 */
if ( ! function_exists( 'sywat_history_get_existing_season_stats' ) ) {
    function sywat_history_get_existing_season_stats() {
        $seasons     = get_option( 'sywat_seasons_list', array() );
        $tournaments = get_option( 'sywat_tournaments_list', array() );
        $stats       = array();

        if ( is_array( $seasons ) ) {
            foreach ( $seasons as $s_slug => $s_data ) {
                $stats[ $s_slug ] = array(
                    'exists'      => true,
                    'status'      => ( is_array( $s_data ) && ! empty( $s_data['status'] ) ) ? (string) $s_data['status'] : 'archived',
                    'event_count' => 0,
                );
            }
        }

        if ( is_array( $tournaments ) ) {
            foreach ( $tournaments as $t_name => $t_data ) {
                if ( ! is_array( $t_data ) ) {
                    continue;
                }
                $s_slug = ! empty( $t_data['season_slug'] )
                    ? (string) $t_data['season_slug']
                    : ( ! empty( $t_data['season'] ) ? (string) $t_data['season'] : '' );

                if ( $s_slug === '' ) {
                    continue;
                }

                if ( ! isset( $stats[ $s_slug ] ) ) {
                    $stats[ $s_slug ] = array(
                        'exists'      => false,
                        'status'      => 'archived',
                        'event_count' => 0,
                    );
                }

                $stats[ $s_slug ]['event_count']++;
            }
        }

        return $stats;
    }
}

/**
 * Insert or update an archived season configuration in sywat_seasons_list.
 * Preserves existing active or upcoming season statuses if the season already exists locally.
 *
 * @param array $season_payload Canonical season descriptor from sywat_history_build_season_payload().
 * @return array                Saved canonical season record.
 */
if ( ! function_exists( 'sywat_history_upsert_season' ) ) {
    function sywat_history_upsert_season( $season_payload ) {
        if ( ! is_array( $season_payload ) || empty( $season_payload['slug'] ) ) {
            return array();
        }

        $slug    = sanitize_title( (string) $season_payload['slug'] );
        $seasons = get_option( 'sywat_seasons_list', array() );
        if ( ! is_array( $seasons ) ) {
            $seasons = array();
        }

        $existing = isset( $seasons[ $slug ] ) && is_array( $seasons[ $slug ] ) ? $seasons[ $slug ] : array();
        $status   = ( ! empty( $existing['status'] ) && in_array( $existing['status'], array( 'active', 'upcoming' ), true ) )
            ? $existing['status']
            : 'archived';

        $record = array(
            'slug'         => $slug,
            'name'         => ! empty( $season_payload['name'] ) ? sanitize_text_field( (string) $season_payload['name'] ) : $slug,
            'type'         => ! empty( $season_payload['type'] ) ? sanitize_text_field( (string) $season_payload['type'] ) : 'Indoor',
            'start_date'   => ! empty( $season_payload['start_date'] ) ? sanitize_text_field( (string) $season_payload['start_date'] ) : '2010-10-01',
            'end_date'     => ! empty( $season_payload['end_date'] ) ? sanitize_text_field( (string) $season_payload['end_date'] ) : '2011-03-31',
            'year_label'   => ! empty( $season_payload['year_label'] ) ? sanitize_text_field( (string) $season_payload['year_label'] ) : '',
            'status'       => $status,
            'best_n_count' => ! empty( $season_payload['best_n_count'] ) ? intval( $season_payload['best_n_count'] ) : 3,
        );

        if ( ! empty( $existing['pricing_profiles'] ) ) {
            $record['pricing_profiles'] = $existing['pricing_profiles'];
        }
        if ( isset( $existing['promo_enabled'] ) ) {
            $record['promo_enabled'] = $existing['promo_enabled'];
        }

        $seasons[ $slug ] = $record;
        update_option( 'sywat_seasons_list', $seasons, false );
        wp_cache_delete( 'sywat_seasons_list', 'options' );

        return $record;
    }
}

/**
 * Atomically persist a historical tournament and all parsed score rows into wp_options
 * inside an explicit MySQL transaction with chunked multi-row SQL upserts (autoload = 'no').
 *
 * @param array $event_item    Normalized tournament manifest item.
 * @param array $season_record Canonical season record.
 * @param array $parsed_card   Output from sywat_history_parse_event_scorecard_html().
 * @return array               Result summary array with 'success', 'archer_count', 'title', and 'error'.
 */
if ( ! function_exists( 'sywat_history_save_event_scores_atomic' ) ) {
    function sywat_history_save_event_scores_atomic( $event_item, $season_record, $parsed_card ) {
        global $wpdb;

        $t_name   = isset( $event_item['title'] ) ? trim( (string) $event_item['title'] ) : '';
        $event_id = isset( $event_item['event_id'] ) ? intval( $event_item['event_id'] ) : 0;

        if ( $t_name === '' || $event_id <= 0 ) {
            return array(
                'success'      => false,
                'archer_count' => 0,
                'title'        => $t_name,
                'error'        => 'Missing tournament title or TFAA event ID.',
            );
        }

        $t_slug     = sanitize_title( $t_name );
        $start_date = ! empty( $parsed_card['extracted_start_date'] )
            ? $parsed_card['extracted_start_date']
            : ( ! empty( $event_item['start_date'] ) ? $event_item['start_date'] : '2010-10-01' );
        $end_date   = ! empty( $parsed_card['extracted_end_date'] )
            ? $parsed_card['extracted_end_date']
            : ( ! empty( $event_item['end_date'] ) ? $event_item['end_date'] : $start_date );

        $entries = ( isset( $parsed_card['entries'] ) && is_array( $parsed_card['entries'] ) )
            ? $parsed_card['entries']
            : array();

        $created_at = $start_date . ' 12:00:00';
        $built      = sywat_history_build_score_records( $t_name, $event_id, $entries, $created_at );
        $new_regs   = $built['registrations'];
        $new_scores = $built['scores'];

        // 1. Save or update tournament configuration in sywat_tournaments_list (published for [sywat_results])
        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) {
            $tournaments = array();
        }

        $t_record = sywat_history_build_tournament_record( $event_item, $season_record, $start_date, $end_date );
        if ( ! empty( $new_regs ) ) {
            $cnt_20  = 0;
            $cnt_cub = 0;
            foreach ( $new_regs as $r_item ) {
                if ( isset( $r_item['distance'] ) && $r_item['distance'] === 'Cub' ) {
                    $cnt_cub++;
                } else {
                    $cnt_20++;
                }
            }
            $t_record['lines'][0]['cap_20yd'] = max( 250, $cnt_20 );
            $t_record['lines'][0]['cap_cub']  = max( 50, $cnt_cub );
        }

        $tournaments[ $t_name ] = $t_record;
        update_option( 'sywat_tournaments_list', $tournaments, false );
        wp_cache_delete( 'sywat_tournaments_list', 'options' );

        // 2. Preserve any non-historical local records if this tournament also had local entries
        $existing_master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
        $merged_master_regs   = array();
        if ( is_array( $existing_master_regs ) ) {
            foreach ( $existing_master_regs as $k => $v ) {
                if ( strpos( (string) $k, 'hist_' ) !== 0 ) {
                    $merged_master_regs[ $k ] = $v;
                }
            }
        }
        foreach ( $new_regs as $k => $v ) {
            $merged_master_regs[ $k ] = $v;
        }

        $existing_scores_map = get_option( 'sywat_scores_' . $t_slug, array() );
        $merged_scores_map   = array();
        if ( is_array( $existing_scores_map ) ) {
            foreach ( $existing_scores_map as $k => $v ) {
                if ( strpos( (string) $k, 'hist_' ) !== 0 ) {
                    $merged_scores_map[ $k ] = $v;
                }
            }
        }
        foreach ( $new_scores as $k => $v ) {
            $merged_scores_map[ $k ] = $v;
        }

        // 3. Atomic MySQL Transaction for bulk wp_options writes (autoload = 'no')
        $hist_prefix = 'sywat_t_' . $t_slug . '_hist_';
        $wpdb->query( 'START TRANSACTION' );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $hist_prefix ) . '%'
            )
        );

        if ( false === $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            return array(
                'success'      => false,
                'archer_count' => 0,
                'title'        => $t_name,
                'error'        => 'Database error while clearing prior historical rows.',
            );
        }

        if ( ! empty( $new_regs ) ) {
            $chunks = array_chunk( $new_regs, 250, true );
            foreach ( $chunks as $chunk ) {
                $placeholders = array();
                $values       = array();

                foreach ( $chunk as $reg_id => $reg_payload ) {
                    $placeholders[] = "(%s, %s, 'no')";
                    $values[]       = 'sywat_t_' . $t_slug . '_' . $reg_id;
                    $values[]       = maybe_serialize( $reg_payload );
                }

                $sql = "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES "
                     . implode( ', ', $placeholders )
                     . " ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'";

                $inserted = $wpdb->query( $wpdb->prepare( $sql, $values ) );
                if ( false === $inserted ) {
                    $wpdb->query( 'ROLLBACK' );
                    return array(
                        'success'      => false,
                        'archer_count' => 0,
                        'title'        => $t_name,
                        'error'        => 'Database error while batch-inserting historical score rows.',
                    );
                }
            }
        }

        $wpdb->query( 'COMMIT' );

        // 4. Update consolidated caches and lock line 0 (autoload = 'no')
        update_option( 'sywat_registrations_' . $t_slug, $merged_master_regs, false );
        update_option( 'sywat_scores_' . $t_slug, $merged_scores_map, false );
        update_option( 'sywat_locks_' . $t_slug, array( '0' => 1 ), false );

        wp_cache_delete( 'sywat_registrations_' . $t_slug, 'options' );
        wp_cache_delete( 'sywat_scores_' . $t_slug, 'options' );
        wp_cache_delete( 'sywat_locks_' . $t_slug, 'options' );

        return array(
            'success'      => true,
            'archer_count' => count( $new_regs ),
            'title'        => $t_name,
            'error'        => '',
        );
    }
}

/**
 * Purge all imported historical tournaments and score rows for a specific season slug
 * inside an explicit MySQL transaction.
 *
 * @param string $season_slug Target archived season slug.
 * @return array              Summary array with 'success', 'events_removed', 'rows_removed', and 'message'.
 */
if ( ! function_exists( 'sywat_history_purge_season_data' ) ) {
    function sywat_history_purge_season_data( $season_slug ) {
        global $wpdb;

        $clean_slug = sanitize_title( (string) $season_slug );
        if ( $clean_slug === '' ) {
            return array(
                'success'        => false,
                'events_removed' => 0,
                'rows_removed'   => 0,
                'message'        => 'Invalid season slug.',
            );
        }

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) {
            $tournaments = array();
        }

        $target_slugs        = array();
        $has_non_hist_events = false;

        foreach ( $tournaments as $t_name => $t_data ) {
            if ( ! is_array( $t_data ) ) {
                continue;
            }
            $t_season = ! empty( $t_data['season_slug'] )
                ? (string) $t_data['season_slug']
                : ( ! empty( $t_data['season'] ) ? (string) $t_data['season'] : '' );

            if ( $t_season !== $clean_slug ) {
                continue;
            }

            if ( ! empty( $t_data['historical_import'] ) ) {
                $target_slugs[ $t_name ] = sanitize_title( $t_name );
            } else {
                $has_non_hist_events = true;
            }
        }

        $rows_removed = 0;
        $wpdb->query( 'START TRANSACTION' );

        foreach ( $target_slugs as $t_name => $t_slug ) {
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( 'sywat_t_' . $t_slug . '_hist_' ) . '%'
                )
            );
            if ( is_numeric( $deleted ) ) {
                $rows_removed += intval( $deleted );
            }

            delete_option( 'sywat_registrations_' . $t_slug );
            delete_option( 'sywat_scores_' . $t_slug );
            delete_option( 'sywat_locks_' . $t_slug );
            delete_option( 'sywat_dates_' . $t_slug );

            wp_cache_delete( 'sywat_registrations_' . $t_slug, 'options' );
            wp_cache_delete( 'sywat_scores_' . $t_slug, 'options' );
            wp_cache_delete( 'sywat_locks_' . $t_slug, 'options' );

            unset( $tournaments[ $t_name ] );
        }

        $wpdb->query( 'COMMIT' );

        update_option( 'sywat_tournaments_list', $tournaments, false );
        wp_cache_delete( 'sywat_tournaments_list', 'options' );

        if ( ! $has_non_hist_events ) {
            $seasons = get_option( 'sywat_seasons_list', array() );
            if ( is_array( $seasons ) && isset( $seasons[ $clean_slug ] ) ) {
                $s_status = isset( $seasons[ $clean_slug ]['status'] ) ? $seasons[ $clean_slug ]['status'] : 'archived';
                if ( $s_status === 'archived' ) {
                    unset( $seasons[ $clean_slug ] );
                    update_option( 'sywat_seasons_list', $seasons, false );
                    wp_cache_delete( 'sywat_seasons_list', 'options' );
                }
            }
        }

        $events_removed = count( $target_slugs );
        return array(
            'success'        => true,
            'events_removed' => $events_removed,
            'rows_removed'   => $rows_removed,
            'message'        => "Purged {$events_removed} imported event(s) and {$rows_removed} historical score row(s).",
        );
    }
}
