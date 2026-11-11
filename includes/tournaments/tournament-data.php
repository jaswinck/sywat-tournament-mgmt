<?php
/**
 * Sub-Module: Tournament Data Access, Calculation & Scraping Engines
 * Path: includes/tournaments/tournament-data.php
 * Author: SYWAT Archery
 * Version: 7.5.0
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
 * Bulk Season Scraper: Scrapes TFAA season index ONCE and populates all direct tournament URLs into DB.
 *
 * @param string $season_name_or_slug Active season name or slug filter.
 * @return array Status array with success flag, count of updated URLs, and message.
 */
if ( ! function_exists( 'sywat_sync_season_tfaa_urls' ) ) {
    function sywat_sync_season_tfaa_urls( $season_name_or_slug = '' ) {
        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( empty( $all_tournaments ) || ! is_array( $all_tournaments ) ) {
            return array( 'success' => false, 'message' => 'No tournaments configured in database.' );
        }

        $target_season = trim( (string)$season_name_or_slug );
        if ( empty( $target_season ) ) {
            $active_s = sywat_get_active_season();
            $target_season = ! empty( $active_s['name'] ) ? $active_s['name'] : ( isset( $active_s['slug'] ) ? $active_s['slug'] : '' );
        } else {
            // Resolve slug to official name if slug was passed
            $seasons = sywat_get_seasons();
            if ( isset( $seasons[$target_season]['name'] ) ) {
                $target_season = $seasons[$target_season]['name'];
            }
        }

        $season_param = ! empty( $target_season ) ? '?season=' . urlencode( $target_season ) : '';
        $index_url    = 'https://texasfieldarchery.org/results' . $season_param;

        $http_args = array(
            'timeout'   => 20,
            'sslverify' => false,
            'headers'   => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            )
        );

        $index_resp = wp_remote_get( $index_url, $http_args );
        if ( is_wp_error( $index_resp ) ) {
            return array( 'success' => false, 'message' => 'Failed to connect to TFAA: ' . $index_resp->get_error_message() );
        }

        $html = wp_remote_retrieve_body( $index_resp );
        if ( empty( $html ) ) {
            return array( 'success' => false, 'message' => 'Empty response received from TFAA index.' );
        }

        if ( ! preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
            return array( 'success' => false, 'message' => 'No event links found on TFAA page.' );
        }

        $updated_count = 0;
        foreach ( $all_tournaments as $t_name => &$t_data ) {
            if ( ! empty( $t_data['tfaa_event_id'] ) ) continue;

            $t_season = isset( $t_data['season'] ) ? $t_data['season'] : ( isset( $t_data['season_slug'] ) ? $t_data['season_slug'] : '' );

            if ( ! empty( $target_season ) && ! empty( $t_season ) ) {
                if ( strcasecmp( sanitize_title( $t_season ), sanitize_title( $target_season ) ) !== 0 
                     && strcasecmp( trim( $t_season ), trim( $target_season ) ) !== 0 ) {
                    continue;
                }
            }

            foreach ( $matches as $m ) {
                $link_href = trim( $m[1] );
                $link_text = trim( wp_strip_all_tags( $m[2] ) );

                if ( empty( $link_href ) || strpos( $link_href, '?season=' ) !== false || $link_href === '/results' ) {
                    continue;
                }

                if ( function_exists( 'sywat_match_tfaa_tournament_name' ) && sywat_match_tfaa_tournament_name( $t_name, $link_text ) ) {
                    if ( preg_match( '/\/(?:results|view)\/(\d+)/i', $link_href, $id_m ) ) {
                        $resolved_url = 'https://texasfieldarchery.org/results/' . $id_m[1];
                    } else {
                        $resolved_url = ( strpos( $link_href, 'http' ) === 0 ) ? $link_href : 'https://texasfieldarchery.org' . ( strpos( $link_href, '/' ) === 0 ? '' : '/' ) . $link_href;
                    }

                    if ( empty( $t_data['tfaa_results_url'] ) || $t_data['tfaa_results_url'] !== $resolved_url ) {
                        $t_data['tfaa_results_url'] = $resolved_url;
                        $updated_count++;
                    }
                    break;
                }
            }
        }
        unset( $t_data );

        if ( $updated_count > 0 ) {
            update_option( 'sywat_tournaments_list', $all_tournaments, false );
        }

        return array(
            'success' => true,
            'count'   => $updated_count,
            'message' => "Successfully resolved and saved {$updated_count} TFAA direct link(s) for season '" . esc_html( $target_season ) . "'."
        );
    }
}

/**
 * Validates uploaded schedule rows against master Shop Directory & prohibits database date overlaps.
 *
 * @param array  $raw_rows       Imported raw spreadsheet rows.
 * @param string $target_season  Slug of target season.
 * @return array                 Structured array containing valid tournaments and rejected rows.
 */
if ( ! function_exists( 'sywat_process_schedule_import_rows' ) ) {
    function sywat_process_schedule_import_rows( $raw_rows, $target_season ) {
        $all_shops        = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : ( function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : get_option( 'sywat_shops', get_option( 'sywat_shops_list', array() ) ) );
        $known_shops      = array();
        $shop_lookup_data = array();

        // Multi-Key Shop Directory Extraction ('name', 'shop_name', 'title')
        foreach ( $all_shops as $s_id => $s_data ) {
            if ( is_array( $s_data ) ) {
                $title = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : ( ! empty( $s_data['title'] ) ? $s_data['title'] : '' ) );
                if ( ! empty( $title ) ) {
                    $norm_key                    = strtolower( trim( $title ) );
                    $known_shops[$norm_key]      = trim( $title );
                    $shop_lookup_data[$norm_key] = $s_data;
                }
            }
        }

        $all_existing_tournaments = get_option( 'sywat_tournaments_list', array() );
        $valid_tournaments        = array();
        $rejected_rows            = array();
        $shop_counters            = array();
        $holiday_keywords         = array( 'thanksgiving', 'thanskgiving', 'christmas', 'new years', 'no sywat\'s', 'no sywats' );

        foreach ( $raw_rows as $index => $row ) {
            $start_date     = trim( isset( $row['start_date'] ) ? $row['start_date'] : '' );
            $raw_host_city  = trim( isset( $row['host_shop_city'] ) ? $row['host_shop_city'] : '' );
            $event_type     = trim( isset( $row['event_type'] ) ? $row['event_type'] : 'SYWAT' );
            $national_title = trim( isset( $row['national_event_title'] ) ? $row['national_event_title'] : '' );
            $notes          = trim( isset( $row['notes'] ) ? $row['notes'] : '' );

            // Silent Skip: Holiday placeholders or blank rows
            if ( empty( $start_date ) || in_array( strtolower( $raw_host_city ), $holiday_keywords ) ) {
                continue;
            }

            $start_date_formatted = function_exists( 'sywat_format_imported_date' ) ? sywat_format_imported_date( $start_date ) : $start_date;
            $end_date_formatted   = ! empty( $row['end_date'] ) ? ( function_exists( 'sywat_format_imported_date' ) ? sywat_format_imported_date( $row['end_date'] ) : $row['end_date'] ) : $start_date_formatted;

            // -------------------------------------------------------------
            // OVERLAP GUARD: Prohibit overlapping event dates per host shop
            // -------------------------------------------------------------
            $is_duplicate   = false;
            $duplicate_name = '';

            foreach ( $all_existing_tournaments as $t_key => $ex ) {
                $ex_season = isset( $ex['season'] ) ? $ex['season'] : ( isset( $ex['season_slug'] ) ? $ex['season_slug'] : '' );
                if ( $ex_season !== $target_season ) {
                    continue;
                }

                $ex_shop  = ! empty( $ex['shop_name'] ) ? strtolower( trim( $ex['shop_name'] ) ) : ( ! empty( $ex['official_shop_name'] ) ? strtolower( trim( $ex['official_shop_name'] ) ) : '' );
                $ex_start = ! empty( $ex['start_date'] ) ? $ex['start_date'] : '';
                $ex_end   = ! empty( $ex['end_date'] ) ? $ex['end_date'] : $ex_start;

                // Match host shop name & date range overlap (Start1 <= End2 AND End1 >= Start2)
                if ( ! empty( $raw_host_city ) && isset( $known_shops[ strtolower( $raw_host_city ) ] ) ) {
                    if ( strtolower( $known_shops[ strtolower( $raw_host_city ) ] ) === $ex_shop ) {
                        if ( $start_date_formatted <= $ex_end && $end_date_formatted >= $ex_start ) {
                            $is_duplicate   = true;
                            $duplicate_name = isset( $ex['name'] ) ? $ex['name'] : $t_key;
                            break;
                        }
                    }
                }

                // Match National Event Title & date range overlap
                if ( strcasecmp( $event_type, 'National' ) === 0 && ! empty( $national_title ) ) {
                    $ex_title = isset( $ex['name'] ) ? $ex['name'] : $t_key;
                    if ( strtolower( trim( $ex_title ) ) === strtolower( trim( $national_title ) ) ) {
                        if ( $start_date_formatted <= $ex_end && $end_date_formatted >= $ex_start ) {
                            $is_duplicate   = true;
                            $duplicate_name = $ex_title;
                            break;
                        }
                    }
                }
            }

            if ( $is_duplicate ) {
                $rejected_rows[] = array(
                    'row_num'   => $index + 2,
                    'shop_name' => $raw_host_city,
                    'reason'    => 'DATE OVERLAP PROHIBITED: Host shop already has "' . $duplicate_name . '" scheduled during ' . $start_date_formatted . ' to ' . $end_date_formatted . ' in season ' . $target_season . '.',
                );
                continue;
            }

            // Branch 1: National / External Events
            if ( strcasecmp( $event_type, 'National' ) === 0 ) {
                $final_title = ! empty( $national_title ) ? $national_title : $raw_host_city;
                $valid_tournaments[] = array(
                    'tournament_name'      => $final_title,
                    'official_shop_name'   => '',
                    'location_display'     => $raw_host_city,
                    'start_date_formatted' => $start_date_formatted,
                    'end_date_formatted'   => $end_date_formatted,
                    'event_type'           => 'National',
                    'line_slots'           => array(),
                    'registration_enabled' => false,
                    'notes'                => $notes,
                );
                continue;
            }

            // Branch 2: TFAA State Championship
            if ( strcasecmp( $event_type, 'TFAA State' ) === 0 ) {
                $norm_shop     = strtolower( $raw_host_city );
                $official_shop = isset( $known_shops[$norm_shop] ) ? $known_shops[$norm_shop] : $raw_host_city;
                $target_shop   = isset( $shop_lookup_data[$norm_shop] ) ? $shop_lookup_data[$norm_shop] : $raw_host_city;
                
                $caps = function_exists( 'sywat_get_shop_capacity_defaults' ) 
                    ? sywat_get_shop_capacity_defaults( $target_shop ) 
                    : array( 'cap_20yd' => 20, 'cap_cub' => 5 );

                $line_slots = function_exists( 'sywat_parse_imported_line_schedule' )
                    ? sywat_parse_imported_line_schedule( isset( $row['line_times'] ) ? $row['line_times'] : '', $caps['cap_20yd'], $caps['cap_cub'] )
                    : array();

                $valid_tournaments[] = array(
                    'tournament_name'      => 'TFAA State Indoor Championship',
                    'official_shop_name'   => $official_shop,
                    'location_display'     => $official_shop,
                    'start_date_formatted' => $start_date_formatted,
                    'end_date_formatted'   => $end_date_formatted,
                    'event_type'           => 'TFAA State',
                    'line_slots'           => $line_slots,
                    'registration_enabled' => true,
                    'notes'                => $notes,
                );
                continue;
            }

            // Branch 3: Standard SYWAT Circuit Shoots
            $norm_shop = strtolower( $raw_host_city );
            if ( isset( $known_shops[$norm_shop] ) ) {
                $official_shop = $known_shops[$norm_shop];
                if ( ! isset( $shop_counters[$official_shop] ) ) {
                    $shop_counters[$official_shop] = 1;
                }

                $tournament_name = $official_shop . '-' . $shop_counters[$official_shop];
                $shop_counters[$official_shop]++;

                $target_shop = isset( $shop_lookup_data[$norm_shop] ) ? $shop_lookup_data[$norm_shop] : $official_shop;
                $caps        = function_exists( 'sywat_get_shop_capacity_defaults' ) 
                    ? sywat_get_shop_capacity_defaults( $target_shop ) 
                    : array( 'cap_20yd' => 20, 'cap_cub' => 5 );

                $line_slots = function_exists( 'sywat_parse_imported_line_schedule' )
                    ? sywat_parse_imported_line_schedule( isset( $row['line_times'] ) ? $row['line_times'] : '', $caps['cap_20yd'], $caps['cap_cub'] )
                    : array();

                $valid_tournaments[] = array(
                    'tournament_name'      => $tournament_name,
                    'official_shop_name'   => $official_shop,
                    'location_display'     => $official_shop,
                    'start_date_formatted' => $start_date_formatted,
                    'end_date_formatted'   => $end_date_formatted,
                    'event_type'           => 'SYWAT',
                    'line_slots'           => $line_slots,
                    'registration_enabled' => true,
                    'notes'                => $notes,
                );
            } else {
                $rejected_rows[] = array(
                    'row_num'   => $index + 2,
                    'shop_name' => $raw_host_city,
                    'reason'    => 'Host Shop Name not found in master Shop Directory.',
                );
            }
        }

        return array(
            'valid_tournaments' => $valid_tournaments,
            'rejected_rows'     => $rejected_rows,
        );
    }
}

/**
 * Re-sequences tournaments chronologically for a specific shop and season.
 * Updates tournament names (e.g. "Shop Name - 1", "Shop Name - 2") based on start_date order,
 * and updates associated registration option keys and active hub lists if names change.
 *
 * @param string $shop_id             Shop ID or normalized shop key.
 * @param string $season_slug         Season slug.
 * @param array  $tournaments         Reference to tournaments array.
 * @param array  $active_tournaments Reference to active tournaments array.
 * @param array  $registrations       Reference to registrations array.
 */
if ( ! function_exists( 'sywat_resequence_shop_tournaments' ) ) {
    function sywat_resequence_shop_tournaments( $shop_id, $season_slug, &$tournaments, &$active_tournaments, &$registrations ) {
        if ( empty( $shop_id ) ) return;

        $all_shops       = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : ( function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : array() );
        $shop_name       = isset( $all_shops[$shop_id]['name'] ) ? $all_shops[$shop_id]['name'] : '';
        
        $shop_lookup_map = array();
        foreach ( $all_shops as $s_id => $s_data ) {
            if ( is_array( $s_data ) ) {
                $title = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : ( ! empty( $s_data['title'] ) ? $s_data['title'] : '' ) );
                if ( ! empty( $title ) ) {
                    $shop_lookup_map[ strtolower( trim( $title ) ) ] = $s_id;
                }
            }
        }

        // Collect all standard circuit tournaments belonging to this shop & season
        $shop_tournaments = array();
        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : '';
            if ( empty( $t_shop ) || ! isset( $all_shops[$t_shop] ) ) {
                $raw_shop = ! empty( $t_data['shop_name'] ) ? $t_data['shop_name'] : ( ! empty( $t_data['official_shop_name'] ) ? $t_data['official_shop_name'] : '' );
                $norm     = strtolower( trim( $raw_shop ) );
                if ( ! empty( $norm ) && isset( $shop_lookup_map[$norm] ) ) {
                    $t_shop = $shop_lookup_map[$norm];
                }
            }

            $t_season   = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $t_data ) : '';
            $event_type = isset( $t_data['event_type'] ) ? $t_data['event_type'] : 'SYWAT';

            // Only re-sequence standard SYWAT circuit events for this shop
            if ( $t_shop === $shop_id && ( empty( $season_slug ) || $t_season === $season_slug ) && strcasecmp( $event_type, 'SYWAT' ) === 0 ) {
                $shop_tournaments[] = array(
                    'old_name'   => $t_name,
                    'start_date' => isset( $t_data['start_date'] ) ? $t_data['start_date'] : '',
                    'data'       => $t_data,
                );
            }
        }

        if ( empty( $shop_tournaments ) ) return;

        // Sort chronologically by start date
        usort( $shop_tournaments, function( $a, $b ) {
            return strcmp( $a['start_date'], $b['start_date'] );
        });

        // Determine base title
        if ( empty( $shop_name ) ) {
            $first_data = $shop_tournaments[0]['data'];
            $shop_name  = ! empty( $first_data['shop_name'] ) ? $first_data['shop_name'] : ( ! empty( $first_data['official_shop_name'] ) ? $first_data['official_shop_name'] : '' );
        }

        if ( empty( $shop_name ) ) return;

        $renamed_map = array();
        foreach ( $shop_tournaments as $idx => $item ) {
            $expected_name = trim( $shop_name ) . ' - ' . ( $idx + 1 );
            $old_name      = $item['old_name'];

            if ( $old_name !== $expected_name ) {
                $renamed_map[$old_name] = $expected_name;
            }
        }

        if ( empty( $renamed_map ) ) return;

        // Apply renames cleanly using a two-pass process to avoid key collisions
        $temp_rebuilt = array();
        foreach ( $renamed_map as $old_name => $new_name ) {
            if ( isset( $tournaments[$old_name] ) ) {
                $t_data = $tournaments[$old_name];
                unset( $tournaments[$old_name] );
                $t_data['name'] = $new_name;
                $temp_rebuilt[$new_name] = array(
                    'old_name' => $old_name,
                    'data'     => $t_data,
                );
            }
        }

        foreach ( $temp_rebuilt as $new_name => $item ) {
            $old_name = $item['old_name'];
            $t_data   = $item['data'];

            $tournaments[$new_name] = $t_data;

            $old_slug = sanitize_title( $old_name );
            $new_slug = sanitize_title( $new_name );

            // Move dates option
            $dates = get_option( 'sywat_dates_' . $old_slug );
            if ( $dates ) {
                update_option( 'sywat_dates_' . $new_slug, $dates );
                delete_option( 'sywat_dates_' . $old_slug );
            }

            // Update active tournaments array
            if ( in_array( $old_name, $active_tournaments ) ) {
                $active_tournaments   = array_diff( $active_tournaments, array( $old_name ) );
                $active_tournaments[] = $new_name;
            }

            // Migrate registrations
            foreach ( $registrations as &$reg ) {
                if ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $old_name ) === 0 ) {
                    delete_option( 'sywat_t_' . $old_slug . '_' . $reg['id'] );
                    $reg['tournament'] = $new_name;
                    update_option( 'sywat_t_' . $new_slug . '_' . $reg['id'], $reg, false );
                }
            }
            unset( $reg );
        }

        $active_tournaments = array_values( array_unique( $active_tournaments ) );
        update_option( 'sywat_tournaments_list', $tournaments );
        update_option( 'sywat_active_tournaments', $active_tournaments );
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
