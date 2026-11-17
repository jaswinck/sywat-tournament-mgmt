<?php
/**
 * Sub-Module: Public Season Schedule Data Access Layer
 * Path: includes/schedule/schedule-data.php
 * Description: Pure data retrieval engine for the public season schedule. Fetches active seasons, filters tournaments, maps host shop data in O(N) time, and sorts events deterministically by start date, event tier/end date, and host shop name without formatting or HTML generation.
 * Compliance: Zero HTML output, zero inline styles, pure scalar data shaping, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retrieves and structures the raw tournament and shop data for a specific season.
 *
 * @param string $requested_season_slug The target season slug (defaults to active season).
 * @return array{target_season_slug: string, seasons: array, tournaments: array, all_shops: array, referenced_shops: array}
 */
if ( ! function_exists( 'sywat_schedule_get_raw_data' ) ) {
    function sywat_schedule_get_raw_data( $requested_season_slug = '' ) {
        // 1. Fetch Seasons
        $seasons = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : get_option( 'sywat_seasons_list', array() );
        if ( ! is_array( $seasons ) ) {
            $seasons = array();
        }

        // 2. Resolve Target Season
        if ( empty( $requested_season_slug ) ) {
            $active_season = function_exists( 'sywat_get_active_season' ) ? sywat_get_active_season() : array();
            $requested_season_slug = isset( $active_season['slug'] ) ? $active_season['slug'] : ( ! empty( $seasons ) ? key( $seasons ) : '' );
        }

        // 3. Fetch Tournaments and Shops
        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $all_tournaments ) ) {
            $all_tournaments = array();
        }

        $all_shops = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : ( function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : get_option( 'sywat_shops_list', array() ) );
        if ( ! is_array( $all_shops ) ) {
            $all_shops = array();
        }

        $season_tournaments = array();
        $referenced_shops   = array();

        // 4. O(N) Filter Pass: Collect tournaments for the target season and map their host shops
        foreach ( $all_tournaments as $t_name => $t_data ) {
            $t_season = function_exists( 'sywat_get_tournament_season_slug' ) 
                ? sywat_get_tournament_season_slug( $t_data ) 
                : ( isset( $t_data['season_slug'] ) ? $t_data['season_slug'] : ( isset( $t_data['season'] ) ? $t_data['season'] : '' ) );

            if ( $t_season === $requested_season_slug ) {
                $season_tournaments[ $t_name ] = $t_data;

                // Track the host shop for the front-end filter dropdown
                $t_shop_id = function_exists( 'sywat_get_tournament_shop_id' ) 
                    ? sywat_get_tournament_shop_id( $t_data ) 
                    : ( isset( $t_data['shop_id'] ) ? $t_data['shop_id'] : '' );
                
                if ( ! empty( $t_shop_id ) && isset( $all_shops[ $t_shop_id ] ) ) {
                    $referenced_shops[ $t_shop_id ] = $all_shops[ $t_shop_id ];
                }
            }
        }

        // 5. Sort season tournaments deterministically (Start Date -> Event Tier/End Date -> Host Shop A-Z -> Title A-Z)
        if ( count( $season_tournaments ) > 1 ) {
            if ( function_exists( 'sywat_sort_tournaments_chronologically' ) ) {
                sywat_sort_tournaments_chronologically( $season_tournaments, $all_shops );
            } else {
                uasort( $season_tournaments, function( $a, $b ) {
                    $a_start = ! empty( $a['start_date'] ) ? $a['start_date'] : '9999-12-31';
                    $b_start = ! empty( $b['start_date'] ) ? $b['start_date'] : '9999-12-31';
                    if ( $a_start !== $b_start ) {
                        return ( $a_start < $b_start ) ? -1 : 1;
                    }
                    $a_end = ! empty( $a['end_date'] ) ? $a['end_date'] : $a_start;
                    $b_end = ! empty( $b['end_date'] ) ? $b['end_date'] : $b_start;
                    if ( $a_end !== $b_end ) {
                        return ( $a_end < $b_end ) ? -1 : 1;
                    }
                    $a_shop = ! empty( $a['shop_name'] ) ? $a['shop_name'] : ( ! empty( $a['name'] ) ? $a['name'] : '' );
                    $b_shop = ! empty( $b['shop_name'] ) ? $b['shop_name'] : ( ! empty( $b['name'] ) ? $b['name'] : '' );
                    return strnatcasecmp( $a_shop, $b_shop );
                } );
            }
        }

        return array(
            'target_season_slug' => $requested_season_slug,
            'seasons'            => $seasons,
            'tournaments'        => $season_tournaments,
            'all_shops'          => $all_shops,
            'referenced_shops'   => $referenced_shops,
        );
    }
}
