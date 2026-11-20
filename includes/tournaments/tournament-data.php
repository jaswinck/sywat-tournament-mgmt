<?php
/**
 * Sub-Module: Tournament Data Access, Calculation & Scraping Engines
 * Path: includes/tournaments/tournament-data.php
 * Description: Provides season/tournament retrieval (with automatic default active-season recovery if sywat_seasons_list was saved without an active season), live registration loading with automatic slug-to-title reconciliation (excluding archived "_hist_" migration records), TFAA schedule scraping, deterministic chronological/shop event sorting, and safe chronological re-sequencing.
 * Compliance: Zero HTML output at include time, O(N) hoisted sorting via shared helper, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 8.1.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// DEBUG TOGGLE: Set to true to inspect scraping logs; set to false for production.
if ( ! defined( 'SYWAT_DEBUG_SYNC' ) ) {
    define( 'SYWAT_DEBUG_SYNC', false );
}

/**
 * Retrieves all competition seasons sorted chronologically descending by start date.
 * Automatically restores default seasons (including the active season) if sywat_seasons_list
 * was persisted without any active season entry.
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
        if ( ! is_array( $seasons ) || empty( $seasons ) ) {
            $seasons = $default_seasons;
        }

        $has_active = false;
        foreach ( $seasons as $s_item ) {
            if ( is_array( $s_item ) && isset( $s_item['status'] ) && $s_item['status'] === 'active' ) {
                $has_active = true;
                break;
            }
        }

        if ( ! $has_active ) {
            $repaired = false;
            foreach ( $default_seasons as $def_slug => $def_season ) {
                if ( ! isset( $seasons[ $def_slug ] ) ) {
                    $seasons[ $def_slug ] = $def_season;
                    $repaired             = true;
                }
            }
            if ( $repaired ) {
                update_option( 'sywat_seasons_list', $seasons, false );
                wp_cache_delete( 'sywat_seasons_list', 'options' );
            }
        }

        uasort( $seasons, function( $a, $b ) {
            $a_date = ! empty( $a['start_date'] ) ? $a['start_date'] : ( ! empty( $a['end_date'] ) ? $a['end_date'] : '' );
            $b_date = ! empty( $b['start_date'] ) ? $b['start_date'] : ( ! empty( $b['end_date'] ) ? $b['end_date'] : '' );
            if ( $a_date === $b_date ) {
                return strcasecmp( isset( $b['name'] ) ? $b['name'] : '', isset( $a['name'] ) ? $a['name'] : '' );
            }
            return ( $a_date < $b_date ) ? 1 : -1;
        } );

        return $seasons;
    }
}

/**
 * Retrieves the current active season configuration array.
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
 * Retrieves all individual archer registration records across active tournaments directly from wp_options,
 * excluding archived "_hist_" historical migration rows.
 */
if ( ! function_exists( 'sywat_get_all_registrations' ) ) {
    function sywat_get_all_registrations() {
        global $wpdb;

        $registrations = array();
        $results       = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%' AND option_name NOT LIKE '%\\_hist\\_%'" );

        if ( is_array( $results ) ) {
            foreach ( $results as $row ) {
                $reg_data = maybe_unserialize( $row->option_value );
                if ( is_array( $reg_data ) && isset( $reg_data['id'] ) ) {
                    $registrations[] = $reg_data;
                }
            }
        }

        if ( empty( $registrations ) ) {
            $registrations = sywat_get_registrations();
        }

        return $registrations;
    }
}

/**
 * Performs a partial search query against the cached TFAA member roster database option.
 */
if ( ! function_exists( 'sywat_search_tfaa_shooters' ) ) {
    function sywat_search_tfaa_shooters( $query, $limit = 10 ) {
        $clean_query = strtolower( trim( $query ) );
        if ( strlen( $clean_query ) < 2 ) {
            return array();
        }

        $cache    = get_option( 'sywat_tfaa_cache', array() );
        $shooters = isset( $cache['shooters'] ) && is_array( $cache['shooters'] )
            ? $cache['shooters']
            : ( is_array( $cache ) ? $cache : array() );

        $matches = array();

        foreach ( $shooters as $s ) {
            $name = isset( $s['name'] ) ? $s['name'] : ( isset( $s['full_name'] ) ? $s['full_name'] : '' );
            if ( empty( $name ) ) {
                continue;
            }

            if ( strpos( strtolower( $name ), $clean_query ) !== false ) {
                $matches[] = array(
                    'name'     => $name,
                    'division' => isset( $s['division'] ) ? $s['division'] : '',
                    'gender'   => isset( $s['gender'] ) ? $s['gender'] : '',
                    'style'    => isset( $s['style'] ) ? $s['style'] : '',
                );

                if ( count( $matches ) >= $limit ) {
                    break;
                }
            }
        }

        return $matches;
    }
}

/**
 * Bulk Season Scraper: Scrapes TFAA season index ONCE and populates all direct tournament URLs into DB.
 */
if ( ! function_exists( 'sywat_sync_season_tfaa_urls' ) ) {
    function sywat_sync_season_tfaa_urls( $season_name_or_slug = '' ) {
        $debug_log = array();
        $is_debug  = defined( 'SYWAT_DEBUG_SYNC' ) && SYWAT_DEBUG_SYNC;

        if ( $is_debug ) {
            $debug_log[] = '--- SYWAT TFAA SYNC DIAGNOSTIC PROFILER (DOMDocument) ---';
        }

        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( empty( $all_tournaments ) || ! is_array( $all_tournaments ) ) {
            return array( 'success' => false, 'message' => 'No tournaments configured in database.' );
        }

        $target_input = trim( (string) $season_name_or_slug );
        $target_slug  = '';
        $target_name  = '';

        $seasons = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : get_option( 'sywat_seasons_list', array() );

        if ( empty( $target_input ) ) {
            $active_s    = sywat_get_active_season();
            $target_slug = isset( $active_s['slug'] ) ? $active_s['slug'] : '';
            $target_name = ! empty( $active_s['name'] ) ? $active_s['name'] : $target_slug;
        } else {
            if ( is_array( $seasons ) ) {
                foreach ( $seasons as $s_slug => $s_data ) {
                    if ( strcasecmp( $s_slug, $target_input ) === 0 || ( isset( $s_data['name'] ) && strcasecmp( $s_data['name'], $target_input ) === 0 ) ) {
                        $target_slug = $s_slug;
                        $target_name = isset( $s_data['name'] ) ? $s_data['name'] : $s_slug;
                        break;
                    }
                }
            }
            if ( empty( $target_slug ) ) {
                $target_slug = sanitize_title( $target_input );
                $target_name = $target_input;
            }
        }

        if ( $is_debug ) {
            $debug_log[] = "Resolved Target Input: '{$target_input}' => Name: '{$target_name}', Slug: '{$target_slug}'";
        }

        $request_season = str_replace( array( '–', '—' ), '-', $target_name );
        $season_param   = ! empty( $request_season ) ? '?season=' . urlencode( $request_season ) : '';
        $index_url      = 'https://texasfieldarchery.org/results' . $season_param;

        if ( $is_debug ) {
            $debug_log[] = "Requesting Remote URL: {$index_url}";
        }

        $http_args = array(
            'timeout'   => 20,
            'sslverify' => false,
            'headers'   => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ),
        );

        $index_resp = wp_remote_get( $index_url, $http_args );
        if ( is_wp_error( $index_resp ) ) {
            return array( 'success' => false, 'message' => 'Failed to connect to TFAA: ' . $index_resp->get_error_message() );
        }

        $http_code = wp_remote_retrieve_response_code( $index_resp );
        if ( $is_debug ) {
            $debug_log[] = "HTTP Response Code: {$http_code}";
        }

        $html = wp_remote_retrieve_body( $index_resp );
        if ( empty( $html ) ) {
            return array( 'success' => false, 'message' => 'Empty response received from TFAA index.' );
        }

        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        @$dom->loadHTML( $html );
        $xpath = new DOMXPath( $dom );

        $rows          = $xpath->query( '//table//tr' );
        $remote_events = array();

        if ( $rows->length > 0 ) {
            foreach ( $rows as $row ) {
                $cells = $xpath->query( './/td', $row );
                if ( $cells->length >= 2 ) {
                    $name_cell = $cells->item( 1 );
                    $link      = $xpath->query( './/a', $name_cell );

                    if ( $link->length > 0 ) {
                        $remote_events[] = array(
                            'has_link' => true,
                            'href'     => trim( $link->item( 0 )->getAttribute( 'href' ) ),
                            'text'     => trim( $link->item( 0 )->textContent ),
                        );
                    } else {
                        $remote_events[] = array(
                            'has_link' => false,
                            'href'     => '',
                            'text'     => trim( $name_cell->textContent ),
                        );
                    }
                }
            }
        } else {
            $links = $dom->getElementsByTagName( 'a' );
            foreach ( $links as $link ) {
                $href = trim( $link->getAttribute( 'href' ) );
                if ( strpos( $href, '/results/' ) !== false ) {
                    $remote_events[] = array(
                        'has_link' => true,
                        'href'     => $href,
                        'text'     => trim( $link->textContent ),
                    );
                }
            }
        }
        libxml_clear_errors();

        if ( $is_debug ) {
            $debug_log[] = 'DOM Parser found ' . count( $remote_events ) . ' tournament rows on the page.';
        }

        $target_year = preg_replace( '/[^0-9]/', '', $target_name );
        $target_type = stripos( $target_name, 'indoor' ) !== false ? 'indoor' : 'outdoor';

        $updated_count = 0;
        foreach ( $all_tournaments as $t_name => &$t_data ) {
            if ( $is_debug ) {
                $debug_log[] = '--------------------------------------------------';
                $debug_log[] = "Evaluating Local DB Tournament: '{$t_name}'";
            }

            if ( ! empty( $t_data['tfaa_event_id'] ) && strpos( $t_data['tfaa_event_id'], 'http' ) === 0 ) {
                if ( $is_debug ) {
                    $debug_log[] = ' -> SKIP: Manual URL override already exists.';
                }
                continue;
            }

            $t_season = isset( $t_data['season'] ) ? $t_data['season'] : ( isset( $t_data['season_slug'] ) ? $t_data['season_slug'] : '' );
            $t_year   = preg_replace( '/[^0-9]/', '', $t_season );
            $t_type   = stripos( $t_season, 'indoor' ) !== false ? 'indoor' : 'outdoor';

            if ( $target_year !== $t_year || $target_type !== $t_type ) {
                if ( $is_debug ) {
                    $debug_log[] = ' -> SKIP: Season mismatch.';
                }
                continue;
            }

            $simple_local_name = strtolower( preg_replace( '/[^a-z0-9]/i', '', $t_name ) );
            $matched           = false;

            foreach ( $remote_events as $remote ) {
                $simple_remote_text = strtolower( preg_replace( '/[^a-z0-9]/i', '', $remote['text'] ) );

                if ( ! empty( $simple_local_name ) && strpos( $simple_remote_text, $simple_local_name ) !== false ) {
                    if ( $remote['has_link'] && ! empty( $remote['href'] ) ) {
                        $link_href = $remote['href'];

                        if ( preg_match( '/\/(?:results|view)\/(\d+)/i', $link_href, $id_m ) ) {
                            $resolved_url = 'https://texasfieldarchery.org/results/' . $id_m[1];
                        } else {
                            $resolved_url = ( strpos( $link_href, 'http' ) === 0 ) ? $link_href : 'https://texasfieldarchery.org' . ( strpos( $link_href, '/' ) === 0 ? '' : '/' ) . $link_href;
                        }

                        if ( empty( $t_data['tfaa_results_url'] ) || $t_data['tfaa_results_url'] !== $resolved_url ) {
                            $t_data['tfaa_results_url'] = $resolved_url;
                            $updated_count++;
                            if ( $is_debug ) {
                                $debug_log[] = "    -> [MATCH SUCCESS]: Saved URL: {$resolved_url}";
                            }
                        } else {
                            if ( $is_debug ) {
                                $debug_log[] = '    -> [MATCH SUCCESS]: URL already up to date.';
                            }
                        }
                    } else {
                        if ( $is_debug ) {
                            $debug_log[] = '    -> [MATCH FOUND BUT NO LINK YET]: Results not posted yet.';
                        }
                    }
                    $matched = true;
                    break;
                }
            }

            if ( ! $matched && $is_debug ) {
                $debug_log[] = ' -> FAILED TO MATCH against any of the remote rows.';
            }
        }
        unset( $t_data );

        if ( $updated_count > 0 ) {
            update_option( 'sywat_tournaments_list', $all_tournaments, false );
        }

        $debug_html = '';
        if ( $is_debug ) {
            $debug_html = '<details class="sywat-debug-log-details"><summary class="sywat-debug-log-summary">View Diagnostic Log</summary><pre class="sywat-debug-log-pre">' . esc_html( implode( "\n", $debug_log ) ) . '</pre></details>';
        }

        return array(
            'success' => true,
            'count'   => $updated_count,
            'debug'   => $is_debug,
            'message' => "Successfully resolved and saved {$updated_count} TFAA direct link(s) for season '" . esc_html( $target_name ) . "'." . $debug_html,
        );
    }
}

/**
 * Validates uploaded schedule rows against master Shop Directory.
 */
if ( ! function_exists( 'sywat_process_schedule_import_rows' ) ) {
    function sywat_process_schedule_import_rows( $raw_rows, $target_season ) {
        $all_shops        = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : ( function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : get_option( 'sywat_shops', get_option( 'sywat_shops_list', array() ) ) );
        $known_shops      = array();
        $shop_lookup_data = array();

        foreach ( $all_shops as $s_id => $s_data ) {
            if ( is_array( $s_data ) ) {
                $title = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : ( ! empty( $s_data['title'] ) ? $s_data['title'] : '' ) );
                if ( ! empty( $title ) ) {
                    $norm_key                      = strtolower( trim( $title ) );
                    $known_shops[ $norm_key ]      = trim( $title );
                    $shop_lookup_data[ $norm_key ] = $s_data;
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

            if ( empty( $start_date ) || in_array( strtolower( $raw_host_city ), $holiday_keywords, true ) ) {
                continue;
            }

            $start_date_formatted = function_exists( 'sywat_format_imported_date' ) ? sywat_format_imported_date( $start_date ) : $start_date;
            $end_date_formatted   = ! empty( $row['end_date'] ) ? ( function_exists( 'sywat_format_imported_date' ) ? sywat_format_imported_date( $row['end_date'] ) : $row['end_date'] ) : $start_date_formatted;

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

                if ( ! empty( $raw_host_city ) && isset( $known_shops[ strtolower( $raw_host_city ) ] ) ) {
                    if ( strtolower( $known_shops[ strtolower( $raw_host_city ) ] ) === $ex_shop ) {
                        if ( $start_date_formatted <= $ex_end && $end_date_formatted >= $ex_start ) {
                            $is_duplicate   = true;
                            $duplicate_name = isset( $ex['name'] ) ? $ex['name'] : $t_key;
                            break;
                        }
                    }
                }

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

            if ( strcasecmp( $event_type, 'National' ) === 0 ) {
                $final_title         = ! empty( $national_title ) ? $national_title : $raw_host_city;
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

            if ( strcasecmp( $event_type, 'TFAA State' ) === 0 ) {
                $norm_shop     = strtolower( $raw_host_city );
                $official_shop = isset( $known_shops[ $norm_shop ] ) ? $known_shops[ $norm_shop ] : $raw_host_city;
                $target_shop   = isset( $shop_lookup_data[ $norm_shop ] ) ? $shop_lookup_data[ $norm_shop ] : $raw_host_city;

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

            $norm_shop = strtolower( $raw_host_city );
            if ( isset( $known_shops[ $norm_shop ] ) ) {
                $official_shop = $known_shops[ $norm_shop ];
                if ( ! isset( $shop_counters[ $official_shop ] ) ) {
                    $shop_counters[ $official_shop ] = 1;
                }

                $tournament_name = $official_shop . '-' . $shop_counters[ $official_shop ];
                $shop_counters[ $official_shop ]++;

                $target_shop = isset( $shop_lookup_data[ $norm_shop ] ) ? $shop_lookup_data[ $norm_shop ] : $official_shop;
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
 * Re-sequences tournaments chronologically for a specific shop and season,
 * migrating all associated archer registrations directly in wp_options.
 */
if ( ! function_exists( 'sywat_resequence_shop_tournaments' ) ) {
    function sywat_resequence_shop_tournaments( $shop_id, $season_slug, &$tournaments, &$active_tournaments, &$registrations ) {
        if ( empty( $shop_id ) ) {
            return;
        }

        if ( empty( $registrations ) || ! is_array( $registrations ) ) {
            $registrations = sywat_get_all_registrations();
        }

        $all_shops = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : ( function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : array() );
        $shop_name = isset( $all_shops[ $shop_id ]['name'] ) ? $all_shops[ $shop_id ]['name'] : '';

        $shop_lookup_map = array();
        foreach ( $all_shops as $s_id => $s_data ) {
            if ( is_array( $s_data ) ) {
                $title = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : ( ! empty( $s_data['title'] ) ? $s_data['title'] : '' ) );
                if ( ! empty( $title ) ) {
                    $shop_lookup_map[ strtolower( trim( $title ) ) ] = $s_id;
                }
            }
        }

        $shop_tournaments = array();
        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : '';
            if ( empty( $t_shop ) || ! isset( $all_shops[ $t_shop ] ) ) {
                $raw_shop = ! empty( $t_data['shop_name'] ) ? $t_data['shop_name'] : ( ! empty( $t_data['official_shop_name'] ) ? $t_data['official_shop_name'] : '' );
                $norm     = strtolower( trim( $raw_shop ) );
                if ( ! empty( $norm ) && isset( $shop_lookup_map[ $norm ] ) ) {
                    $t_shop = $shop_lookup_map[ $norm ];
                }
            }

            $t_season   = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $t_data ) : '';
            $event_type = isset( $t_data['event_type'] ) ? $t_data['event_type'] : 'SYWAT';

            if ( $t_shop === $shop_id && ( empty( $season_slug ) || $t_season === $season_slug ) && strcasecmp( $event_type, 'SYWAT' ) === 0 ) {
                $shop_tournaments[] = array(
                    'old_name'   => $t_name,
                    'start_date' => isset( $t_data['start_date'] ) ? $t_data['start_date'] : '',
                    'data'       => $t_data,
                );
            }
        }

        if ( empty( $shop_tournaments ) ) {
            return;
        }

        usort( $shop_tournaments, function( $a, $b ) {
            return strcmp( $a['start_date'], $b['start_date'] );
        } );

        if ( empty( $shop_name ) ) {
            $first_data = $shop_tournaments[0]['data'];
            $shop_name  = ! empty( $first_data['shop_name'] ) ? $first_data['shop_name'] : ( ! empty( $first_data['official_shop_name'] ) ? $first_data['official_shop_name'] : '' );
        }

        if ( empty( $shop_name ) ) {
            return;
        }

        $renamed_map = array();
        foreach ( $shop_tournaments as $idx => $item ) {
            $expected_name = trim( $shop_name ) . ' - ' . ( $idx + 1 );
            $old_name      = $item['old_name'];

            if ( $old_name !== $expected_name ) {
                $renamed_map[ $old_name ] = $expected_name;
            }
        }

        if ( empty( $renamed_map ) ) {
            return;
        }

        $temp_rebuilt = array();
        foreach ( $renamed_map as $old_name => $new_name ) {
            if ( isset( $tournaments[ $old_name ] ) ) {
                $t_data = $tournaments[ $old_name ];
                unset( $tournaments[ $old_name ] );
                $t_data['name']            = $new_name;
                $temp_rebuilt[ $new_name ] = array(
                    'old_name' => $old_name,
                    'data'     => $t_data,
                );
            }
        }

        foreach ( $temp_rebuilt as $new_name => $item ) {
            $old_name = $item['old_name'];
            $t_data   = $item['data'];

            $tournaments[ $new_name ] = $t_data;

            $old_slug = sanitize_title( $old_name );
            $new_slug = sanitize_title( $new_name );

            $dates = get_option( 'sywat_dates_' . $old_slug );
            if ( $dates ) {
                update_option( 'sywat_dates_' . $new_slug, $dates );
                if ( $old_slug !== $new_slug ) {
                    delete_option( 'sywat_dates_' . $old_slug );
                }
            }

            if ( in_array( $old_name, $active_tournaments, true ) ) {
                $active_tournaments   = array_diff( $active_tournaments, array( $old_name ) );
                $active_tournaments[] = $new_name;
            }

            foreach ( $registrations as &$reg ) {
                if ( isset( $reg['tournament'] ) && ( strcasecmp( $reg['tournament'], $old_name ) === 0 || sanitize_title( $reg['tournament'] ) === $old_slug ) ) {
                    if ( $old_slug !== $new_slug ) {
                        delete_option( 'sywat_t_' . $old_slug . '_' . $reg['id'] );
                    }
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
 * Master Data Retrieval Engine for Admin Portal & Views.
 * Automatically reconciles any active registration records whose tournament slug matches a configured
 * tournament whose display punctuation/spacing was normalized (e.g., space vs. hyphen),
 * while excluding archived "_hist_" historical migration rows from full-table scans.
 */
if ( ! function_exists( 'sywat_get_master_portal_data' ) ) {
    function sywat_get_master_portal_data() {
        global $wpdb;

        $can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
        $can_access_desk    = function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk();
        $can_edit_scores    = function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores();

        $user_shop_id = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';
        $all_shops    = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
        $seasons      = sywat_get_seasons();

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) {
            $tournaments = array();
        }

        $active_tournaments = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_tournaments ) ) {
            $active_tournaments = array();
        }

        // Map normalized tournament slugs to their canonical key in $tournaments
        $slug_to_canonical = array();
        foreach ( array_keys( $tournaments ) as $canonical_name ) {
            $t_slug = sanitize_title( $canonical_name );
            if ( ! empty( $t_slug ) && ! isset( $slug_to_canonical[ $t_slug ] ) ) {
                $slug_to_canonical[ $t_slug ] = $canonical_name;
            }
        }

        $registrations = array();
        $results       = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%' AND option_name NOT LIKE '%\\_hist\\_%'" );

        if ( is_array( $results ) ) {
            foreach ( $results as $row ) {
                $reg_data = maybe_unserialize( $row->option_value );
                if ( is_array( $reg_data ) && isset( $reg_data['id'] ) ) {
                    if ( ! empty( $reg_data['tournament'] ) ) {
                        $reg_t_slug = sanitize_title( $reg_data['tournament'] );
                        if ( isset( $slug_to_canonical[ $reg_t_slug ] ) && $reg_data['tournament'] !== $slug_to_canonical[ $reg_t_slug ] ) {
                            $canonical_title        = $slug_to_canonical[ $reg_t_slug ];
                            $reg_data['tournament'] = $canonical_title;
                            update_option( $row->option_name, $reg_data, false );
                        }
                    }
                    $registrations[] = $reg_data;
                }
            }
        }

        if ( empty( $registrations ) ) {
            $registrations = sywat_get_registrations();
        }

        $scoped_tournaments = array();
        $filter_shops       = array();
        $selected_season    = isset( $_REQUEST['filter_season'] ) ? sanitize_text_field( $_REQUEST['filter_season'] ) : '';

        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop   = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
            $t_season = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $t_data ) : 'indoor-2026-2027';

            if ( ! empty( $selected_season ) && $t_season !== $selected_season ) {
                continue;
            }

            if ( $can_manage_seasons || $t_shop === $user_shop_id ) {
                $scoped_tournaments[ $t_name ] = $t_data;
                if ( isset( $all_shops[ $t_shop ]['name'] ) ) {
                    $filter_shops[ $t_shop ] = $all_shops[ $t_shop ]['name'];
                }
            }
        }
        asort( $filter_shops );

        if ( count( $scoped_tournaments ) > 1 ) {
            if ( function_exists( 'sywat_sort_tournaments_chronologically' ) ) {
                sywat_sort_tournaments_chronologically( $scoped_tournaments, $all_shops );
            } else {
                uasort( $scoped_tournaments, function( $a, $b ) {
                    $a_start = ( is_array( $a ) && ! empty( $a['start_date'] ) ) ? $a['start_date'] : '9999-12-31';
                    $b_start = ( is_array( $b ) && ! empty( $b['start_date'] ) ) ? $b['start_date'] : '9999-12-31';
                    if ( $a_start !== $b_start ) {
                        return ( $a_start < $b_start ) ? -1 : 1;
                    }
                    $a_end = ( is_array( $a ) && ! empty( $a['end_date'] ) ) ? $a['end_date'] : $a_start;
                    $b_end = ( is_array( $b ) && ! empty( $b['end_date'] ) ) ? $b['end_date'] : $b_start;
                    if ( $a_end !== $b_end ) {
                        return ( $a_end < $b_end ) ? -1 : 1;
                    }
                    $a_shop = ( is_array( $a ) && ! empty( $a['shop_name'] ) ) ? $a['shop_name'] : ( ( is_array( $a ) && ! empty( $a['name'] ) ) ? $a['name'] : '' );
                    $b_shop = ( is_array( $b ) && ! empty( $b['shop_name'] ) ) ? $b['shop_name'] : ( ( is_array( $b ) && ! empty( $b['name'] ) ) ? $b['name'] : '' );
                    return strnatcasecmp( $a_shop, $b_shop );
                } );
            }
        }

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
