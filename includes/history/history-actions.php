<?php
/**
 * Sub-Module: Historical TFAA Results AJAX Action Handlers
 * Path: includes/history/history-actions.php
 * Description: Nonce-verified and capability-gated wp_ajax_* endpoints for discovering and permanently persisting historical TFAA seasons, scanning and caching per-season tournament manifests with live import statuses, importing individual event scorecards in chunked AJAX ticks, and purging imported historical seasons while retaining discovered manifests.
 * Compliance: Pure AJAX action layer — executes on wp_ajax_* before theme output, pure JSON responses via wp_send_json_*(), zero HTML echo, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared security and capability guard for all historical migration AJAX endpoints.
 * Terminates with a pure JSON error response if feature flag, nonce, or permissions fail.
 *
 * @return void
 */
if ( ! function_exists( 'sywat_history_verify_ajax_request' ) ) {
    function sywat_history_verify_ajax_request() {
        if ( ! defined( 'SYWAT_ENABLE_HISTORY_IMPORTER' ) || ! SYWAT_ENABLE_HISTORY_IMPORTER ) {
            wp_send_json_error(
                array( 'message' => 'The TFAA Historical Importer is currently disabled.' ),
                403
            );
        }

        if ( ! check_ajax_referer( 'sywat_history_import_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                array( 'message' => 'Security token expired. Please refresh the page and try again.' ),
                403
            );
        }

        if ( ! function_exists( 'sywat_can_manage_seasons' ) || ! sywat_can_manage_seasons() ) {
            wp_send_json_error(
                array( 'message' => 'Access denied. Season management privileges are required.' ),
                403
            );
        }
    }
}

/**
 * AJAX Handler 1: Discover all available historical seasons from TFAA's season dropdown
 * and persist them permanently in wp_options (sywat_history_discovered_seasons).
 * Action: wp_ajax_sywat_history_discover_seasons
 *
 * @return void
 */
if ( ! function_exists( 'sywat_ajax_history_discover_seasons' ) ) {
    function sywat_ajax_history_discover_seasons() {
        sywat_history_verify_ajax_request();

        $seed_url = 'https://texasfieldarchery.org/results/season/7';
        $fetched  = sywat_history_fetch_remote_html( $seed_url );

        if ( empty( $fetched['success'] ) ) {
            wp_send_json_error(
                array(
                    'message' => ! empty( $fetched['error'] )
                        ? $fetched['error']
                        : 'Unable to connect to texasfieldarchery.org.',
                )
            );
        }

        $parsed_seasons = sywat_history_parse_season_dropdown( $fetched['html'] );
        if ( empty( $parsed_seasons ) ) {
            wp_send_json_error(
                array( 'message' => 'No historical seasons could be parsed from the TFAA season selector.' )
            );
        }

        $hydrated_seasons = function_exists( 'sywat_history_save_discovered_seasons' )
            ? sywat_history_save_discovered_seasons( $parsed_seasons )
            : $parsed_seasons;

        wp_send_json_success(
            array(
                'seasons' => $hydrated_seasons,
                'count'   => count( $hydrated_seasons ),
            )
        );
    }
}
add_action( 'wp_ajax_sywat_history_discover_seasons', 'sywat_ajax_history_discover_seasons' );

/**
 * AJAX Handler 2: Scan a single TFAA season page (/results/season/{id}) for its tournament manifest,
 * persist the discovered events permanently, and ensure the archived season exists in sywat_seasons_list.
 * Action: wp_ajax_sywat_history_scan_season
 *
 * @return void
 */
if ( ! function_exists( 'sywat_ajax_history_scan_season' ) ) {
    function sywat_ajax_history_scan_season() {
        sywat_history_verify_ajax_request();

        $season_id    = isset( $_POST['season_id'] ) ? intval( wp_unslash( $_POST['season_id'] ) ) : 0;
        $season_label = isset( $_POST['season_label'] ) ? sanitize_text_field( wp_unslash( $_POST['season_label'] ) ) : '';

        if ( $season_id <= 0 || $season_label === '' ) {
            wp_send_json_error(
                array( 'message' => 'Invalid season ID or season label supplied.' )
            );
        }

        $season_payload = sywat_history_build_season_payload( $season_id, $season_label );
        $fetched        = sywat_history_fetch_remote_html( $season_payload['season_url'] );

        if ( empty( $fetched['success'] ) ) {
            wp_send_json_error(
                array(
                    'message' => ! empty( $fetched['error'] )
                        ? $fetched['error']
                        : 'Failed to retrieve season schedule from TFAA.',
                )
            );
        }

        if ( function_exists( 'sywat_history_save_discovered_seasons' ) ) {
            sywat_history_save_discovered_seasons( array( $season_payload ) );
        }

        $saved_season    = sywat_history_upsert_season( $season_payload );
        $parsed_events   = sywat_history_parse_season_events_html( $fetched['html'], $saved_season );
        $hydrated_events = function_exists( 'sywat_history_save_discovered_events' )
            ? sywat_history_save_discovered_events( $saved_season['slug'], $parsed_events )
            : $parsed_events;

        wp_send_json_success(
            array(
                'season' => $saved_season,
                'events' => $hydrated_events,
                'count'  => count( $hydrated_events ),
            )
        );
    }
}
add_action( 'wp_ajax_sywat_history_scan_season', 'sywat_ajax_history_scan_season' );

/**
 * AJAX Handler 3: Retrieve permanently stored tournaments for a season (or refresh live from TFAA)
 * with real-time imported/not-imported status and scorecard counts.
 * Action: wp_ajax_sywat_history_get_season_events
 *
 * @return void
 */
if ( ! function_exists( 'sywat_ajax_history_get_season_events' ) ) {
    function sywat_ajax_history_get_season_events() {
        sywat_history_verify_ajax_request();

        $season_id     = isset( $_POST['season_id'] ) ? intval( wp_unslash( $_POST['season_id'] ) ) : 0;
        $season_slug   = isset( $_POST['season_slug'] ) ? sanitize_title( wp_unslash( $_POST['season_slug'] ) ) : '';
        $season_label  = isset( $_POST['season_label'] ) ? sanitize_text_field( wp_unslash( $_POST['season_label'] ) ) : '';
        $force_refresh = isset( $_POST['force_refresh'] ) && sanitize_text_field( wp_unslash( $_POST['force_refresh'] ) ) === '1';

        if ( $season_slug === '' && $season_id > 0 && $season_label !== '' ) {
            $tmp_payload = sywat_history_build_season_payload( $season_id, $season_label );
            $season_slug = isset( $tmp_payload['slug'] ) ? $tmp_payload['slug'] : '';
        }

        if ( $season_slug === '' ) {
            wp_send_json_error(
                array( 'message' => 'Missing season identifier.' )
            );
        }

        if ( ! $force_refresh && function_exists( 'sywat_history_get_discovered_events' ) ) {
            $cached_events = sywat_history_get_discovered_events( $season_slug );
            if ( ! empty( $cached_events ) ) {
                wp_send_json_success(
                    array(
                        'season_slug' => $season_slug,
                        'events'      => $cached_events,
                        'count'       => count( $cached_events ),
                        'source'      => 'cache',
                    )
                );
            }
        }

        if ( $season_id <= 0 || $season_label === '' ) {
            $fallback_events = function_exists( 'sywat_history_get_discovered_events' )
                ? sywat_history_get_discovered_events( $season_slug )
                : array();

            wp_send_json_success(
                array(
                    'season_slug' => $season_slug,
                    'events'      => $fallback_events,
                    'count'       => count( $fallback_events ),
                    'source'      => 'cache',
                )
            );
        }

        $season_payload = sywat_history_build_season_payload( $season_id, $season_label );
        $fetched        = sywat_history_fetch_remote_html( $season_payload['season_url'] );

        if ( empty( $fetched['success'] ) ) {
            wp_send_json_error(
                array(
                    'message' => ! empty( $fetched['error'] )
                        ? $fetched['error']
                        : 'Failed to retrieve tournament list from TFAA.',
                )
            );
        }

        if ( function_exists( 'sywat_history_save_discovered_seasons' ) ) {
            sywat_history_save_discovered_seasons( array( $season_payload ) );
        }

        $parsed_events   = sywat_history_parse_season_events_html( $fetched['html'], $season_payload );
        $hydrated_events = function_exists( 'sywat_history_save_discovered_events' )
            ? sywat_history_save_discovered_events( $season_slug, $parsed_events )
            : $parsed_events;

        wp_send_json_success(
            array(
                'season_slug' => $season_slug,
                'events'      => $hydrated_events,
                'count'       => count( $hydrated_events ),
                'source'      => 'remote',
            )
        );
    }
}
add_action( 'wp_ajax_sywat_history_get_season_events', 'sywat_ajax_history_get_season_events' );

/**
 * AJAX Handler 4: Fetch and import a single historical TFAA tournament scorecard (/results/{id}).
 * Executed 1 event per AJAX request to prevent shared-hosting PHP execution timeouts.
 * Action: wp_ajax_sywat_history_import_tournament
 *
 * @return void
 */
if ( ! function_exists( 'sywat_ajax_history_import_tournament' ) ) {
    function sywat_ajax_history_import_tournament() {
        sywat_history_verify_ajax_request();

        $event_id    = isset( $_POST['event_id'] ) ? intval( wp_unslash( $_POST['event_id'] ) ) : 0;
        $raw_title   = isset( $_POST['raw_title'] ) ? sanitize_text_field( wp_unslash( $_POST['raw_title'] ) ) : '';
        $title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $season_id   = isset( $_POST['season_id'] ) ? intval( wp_unslash( $_POST['season_id'] ) ) : 0;
        $season_name = isset( $_POST['season_name'] ) ? sanitize_text_field( wp_unslash( $_POST['season_name'] ) ) : '';
        $season_slug = isset( $_POST['season_slug'] ) ? sanitize_title( wp_unslash( $_POST['season_slug'] ) ) : '';
        $start_date  = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end_date    = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
        $event_type  = isset( $_POST['event_type'] ) ? sanitize_text_field( wp_unslash( $_POST['event_type'] ) ) : 'SYWAT';

        if ( $event_id <= 0 || $title === '' ) {
            wp_send_json_error(
                array( 'message' => 'Missing event ID or canonical tournament title.' )
            );
        }

        $event_url = 'https://texasfieldarchery.org/results/' . $event_id;
        $fetched   = sywat_history_fetch_remote_html( $event_url );

        if ( empty( $fetched['success'] ) ) {
            wp_send_json_error(
                array(
                    'title'   => $title,
                    'message' => ! empty( $fetched['error'] )
                        ? $fetched['error']
                        : 'Failed to retrieve event scorecard from TFAA.',
                )
            );
        }

        $parsed_card = sywat_history_parse_event_scorecard_html( $fetched['html'] );

        $season_record = array(
            'slug' => $season_slug,
            'name' => $season_name,
        );
        if ( $season_id > 0 && $season_name !== '' ) {
            $season_payload = sywat_history_build_season_payload( $season_id, $season_name );
            $season_record  = sywat_history_upsert_season( $season_payload );
        }

        $event_item = array(
            'event_id'    => $event_id,
            'event_url'   => $event_url,
            'raw_title'   => $raw_title !== '' ? $raw_title : $title,
            'title'       => $title,
            'season_slug' => isset( $season_record['slug'] ) ? $season_record['slug'] : $season_slug,
            'season_name' => isset( $season_record['name'] ) ? $season_record['name'] : $season_name,
            'start_date'  => $start_date,
            'end_date'    => $end_date !== '' ? $end_date : $start_date,
            'event_type'  => $event_type,
        );

        $saved = sywat_history_save_event_scores_atomic( $event_item, $season_record, $parsed_card );
        if ( empty( $saved['success'] ) ) {
            wp_send_json_error(
                array(
                    'title'   => $title,
                    'message' => ! empty( $saved['error'] ) ? $saved['error'] : 'Failed to save event scores.',
                )
            );
        }

        $resolved_slug   = $event_item['season_slug'];
        $hydrated_events = function_exists( 'sywat_history_get_discovered_events' )
            ? sywat_history_get_discovered_events( $resolved_slug )
            : array();

        wp_send_json_success(
            array(
                'event_id'     => $event_id,
                'title'        => $saved['title'],
                'season_slug'  => $resolved_slug,
                'archer_count' => intval( $saved['archer_count'] ),
                'events'       => $hydrated_events,
            )
        );
    }
}
add_action( 'wp_ajax_sywat_history_import_tournament', 'sywat_ajax_history_import_tournament' );

/**
 * AJAX Handler 5: Purge all imported historical tournaments and score rows for an archived season
 * while keeping permanent discovered manifests intact.
 * Action: wp_ajax_sywat_history_purge_season
 *
 * @return void
 */
if ( ! function_exists( 'sywat_ajax_history_purge_season' ) ) {
    function sywat_ajax_history_purge_season() {
        sywat_history_verify_ajax_request();

        $season_slug = isset( $_POST['season_slug'] ) ? sanitize_title( wp_unslash( $_POST['season_slug'] ) ) : '';
        if ( $season_slug === '' ) {
            wp_send_json_error(
                array( 'message' => 'Missing season slug for purge operation.' )
            );
        }

        $result = sywat_history_purge_season_data( $season_slug );
        if ( empty( $result['success'] ) ) {
            wp_send_json_error(
                array( 'message' => ! empty( $result['message'] ) ? $result['message'] : 'Purge operation failed.' )
            );
        }

        wp_send_json_success( $result );
    }
}
add_action( 'wp_ajax_sywat_history_purge_season', 'sywat_ajax_history_purge_season' );
