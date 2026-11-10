<?php
/**
 * Sub-Module: Tournament Data Access & Metric Engines
 * Path: includes/tournaments/tournament-data.php
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Retrieves all competition seasons sorted chronologically descending by start date.
 *
 * @return array Multi-dimensional array of configured seasons.
 */
if ( ! function_exists( 'sywat_get_seasons' ) ) {
    function sywat_get_seasons() {
        $default_seasons = array(
            'indoor-2026-2027' => array(
                'slug'         => 'indoor-2026-2027',
                'name'         => '2026–2027 Indoor',
                'type'         => 'Indoor',
                'start_date'   => '2026-10-01',
                'end_date'     => '2027-03-31',
                'year_label'   => '2026–2027',
                'status'       => 'active',
                'best_n_count' => 3,
            ),
            'outdoor-2026' => array(
                'slug'         => 'outdoor-2026',
                'name'         => '2026 Outdoor',
                'type'         => 'Outdoor',
                'start_date'   => '2026-04-01',
                'end_date'     => '2026-07-31',
                'year_label'   => '2026',
                'status'       => 'archived',
                'best_n_count' => 3,
            ),
        );

        $seasons = get_option( 'sywat_seasons_list', $default_seasons );
        if ( ! is_array( $seasons ) ) $seasons = $default_seasons;

        // Sort seasons from newest to oldest by start date (descending)
        uasort( $seasons, function( $a, $b ) {
            $a_date = ! empty( $a['start_date'] ) ? $a['start_date'] : ( ! empty( $a['end_date'] ) ? $a['end_date'] : '' );
            $b_date = ! empty( $b['start_date'] ) ? $b['start_date'] : ( ! empty( $b['end_date'] ) ? $b['end_date'] : '' );
            if ( $a_date === $b_date ) {
                return strcasecmp( isset( $b['name'] ) ? $b['name'] : '', isset( $a['name'] ) ? $a['name'] : '' );
            }
            return ( $a_date < $b_date ) ? 1 : -1;
        });

        return $seasons;
    }
}

/**
 * Retrieves the current active season configuration array.
 *
 * @return array Active season payload array.
 */
if ( ! function_exists( 'sywat_get_active_season' ) ) {
    function sywat_get_active_season() {
        $seasons = sywat_get_seasons();
        foreach ( $seasons as $slug => $s ) {
            if ( isset( $s['status'] ) && $s['status'] === 'active' ) {
                return $s;
            }
        }
        return reset( $seasons );
    }
}

/**
 * Retrieves archer registrations from database storage.
 *
 * @param string $t_name Optional tournament name filter.
 * @return array List of registration data records.
 */
if ( ! function_exists( 'sywat_get_registrations' ) ) {
    function sywat_get_registrations( $t_name = '' ) {
        if ( ! empty( $t_name ) ) {
            $t_slug = sanitize_title( $t_name );
            $regs   = get_option( 'sywat_registrations_' . $t_slug, array() );
            if ( is_array( $regs ) && ! empty( $regs ) ) {
                return array_values( $regs );
            }
        }

        $global_regs = get_option( 'sywat_registrations', array() );
        if ( is_array( $global_regs ) && ! empty( $global_regs ) ) {
            if ( empty( $t_name ) ) {
                return array_values( $global_regs );
            }
            return array_values( array_filter( $global_regs, function( $r ) use ( $t_name ) {
                return isset( $r['tournament'] ) && strcasecmp( trim( $r['tournament'] ), trim( $t_name ) ) === 0;
            } ) );
        }

        return array();
    }
}

/**
 * Performs a partial search query against the cached TFAA member roster database option.
 *
 * @param string $query Archer search query term.
 * @param int    $limit Maximum result count limit.
 * @return array Matching TFAA member records.
 */
if ( ! function_exists( 'sywat_search_tfaa_shooters' ) ) {
    function sywat_search_tfaa_shooters( $query, $limit = 10 ) {
        $clean_query = strtolower( trim( $query ) );
        if ( strlen( $clean_query ) < 2 ) return array();

        $cache    = get_option( 'sywat_tfaa_cache', array() );
        $shooters = isset( $cache['shooters'] ) && is_array( $cache['shooters'] ) 
            ? $cache['shooters'] 
            : ( is_array( $cache ) ? $cache : array() );

        $matches = array();

        foreach ( $shooters as $s ) {
            $name = isset( $s['name'] ) ? $s['name'] : ( isset( $s['full_name'] ) ? $s['full_name'] : '' );
            if ( empty( $name ) ) continue;

            if ( strpos( strtolower( $name ), $clean_query ) !== false ) {
                $matches[] = array(
                    'name'     => $name,
                    'division' => isset( $s['division'] ) ? $s['division'] : '',
                    'gender'   => isset( $s['gender'] ) ? $s['gender'] : '',
                    'style'    => isset( $s['style'] ) ? $s['style'] : '',
                );

                if ( count( $matches ) >= $limit ) break;
            }
        }

        return $matches;
    }
}

/**
 * Master Data Retrieval Engine for Admin Portal & Views
 *
 * @return array Aggregated portal data payload array.
 */
if ( ! function_exists( 'sywat_get_master_portal_data' ) ) {
    function sywat_get_master_portal_data() {
        global $wpdb;

        $can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
        $can_access_desk    = function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk();
        $can_edit_scores    = function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores();

        $user_shop_id       = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';
        $all_shops          = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
        $seasons            = sywat_get_seasons();

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        $active_tournaments = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_tournaments ) ) $active_tournaments = array();

        // Load Registrations from DB with Master Roster Fallback
        $registrations = array();
        $results = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%'" );
        if ( is_array( $results ) ) {
            foreach ($results as $row) {
                $reg_data = maybe_unserialize($row->option_value);
                if (is_array($reg_data) && isset($reg_data['id'])) {
                    $registrations[] = $reg_data;
                }
            }
        }

        // Fallback: Query master registration options if single option keys return empty
        if ( empty( $registrations ) ) {
            $registrations = sywat_get_registrations();
        }

        // Filter Tournaments by Shop Scoping, Season Filtering & Collect Unique Shops
        $scoped_tournaments = array();
        $filter_shops       = array();

        $selected_season = isset( $_REQUEST['filter_season'] ) ? sanitize_text_field( $_REQUEST['filter_season'] ) : '';

        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop   = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
            $t_season = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $t_data ) : 'indoor-2026-2027';

            // Filter out tournaments that do not match the selected season parameter
            if ( ! empty( $selected_season ) && $t_season !== $selected_season ) {
                continue;
            }

            if ( $can_manage_seasons || $t_shop === $user_shop_id ) {
                $scoped_tournaments[$t_name] = $t_data;
                if ( isset( $all_shops[$t_shop]['name'] ) ) {
                    $filter_shops[$t_shop] = $all_shops[$t_shop]['name'];
                }
            }
        }
        asort( $filter_shops );

        // Sort tournaments chronologically by Start Date (Soonest first)
        uasort( $scoped_tournaments, function( $a, $b ) {
            $a_date = ( is_array( $a ) && ! empty( $a['start_date'] ) ) ? $a['start_date'] : '';
            $b_date = ( is_array( $b ) && ! empty( $b['start_date'] ) ) ? $b['start_date'] : '';
            if ( $a_date === $b_date ) return 0;
            return ( $a_date < $b_date ) ? -1 : 1;
        });

        $tfaa_cache = get_option( 'sywat_tfaa_cache', array() );
        $tfaa_count = isset( $tfaa_cache['count'] ) ? $tfaa_cache['count'] : 0;

        return array(
            'can_manage_seasons' => $can_manage_seasons,
            'can_access_desk'    => $can_access_desk,
            'can_edit_scores'    => $can_edit_scores,
            'user_shop_id'       => $user_shop_id,
            'all_shops'          => $all_shops,
            'seasons'            => $seasons,
            'tournaments'        => $tournaments,
            'active_tournaments' => $active_tournaments,
            'registrations'      => $registrations,
            'scoped_tournaments' => $scoped_tournaments,
            'filter_shops'       => $filter_shops,
            'tfaa_count'         => $tfaa_count,
        );
    }
}
