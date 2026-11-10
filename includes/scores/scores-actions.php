<?php
/**
 * Sub-Module: Scores Engine Action Handlers & TFAA Event Sync Engine
 * Path: includes/scores/scores-actions.php
 * Description: Pure POST request processing, AJAX autosave endpoints, batch score saves, dynamic line capacity expansion, line lock management, and legacy-proven 3-layer TFAA event score scraper.
 * Author: SYWAT Archery
 * Version: 6.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auto-expands physical line capacity on Line 0 if imported TFAA batch exceeds limits.
 *
 * @param array  $t_data   Tournament configuration reference array.
 * @param string $line_idx Shooting line index key.
 * @param int    $req_20yd Count of 20-yard shooters in incoming import.
 * @param int    $req_cub  Count of Cub distance shooters in incoming import.
 * @param string $t_name   Tournament title string.
 */
if ( ! function_exists( 'sywat_auto_expand_line_capacity' ) ) {
    function sywat_auto_expand_line_capacity( &$t_data, $line_idx, $req_20yd, $req_cub, $t_name ) {
        if ( ! isset( $t_data['lines'][$line_idx] ) ) return;

        $updated      = false;
        $current_20yd = isset( $t_data['lines'][$line_idx]['cap_20yd'] ) ? intval( $t_data['lines'][$line_idx]['cap_20yd'] ) : 20;
        $current_cub  = isset( $t_data['lines'][$line_idx]['cap_cub'] )  ? intval( $t_data['lines'][$line_idx]['cap_cub'] )  : 5;

        if ( $req_20yd > $current_20yd ) {
            $t_data['lines'][$line_idx]['cap_20yd'] = $req_20yd;
            $updated = true;
        }

        if ( $req_cub > $current_cub ) {
            $t_data['lines'][$line_idx]['cap_cub'] = $req_cub;
            $updated = true;
        }

        if ( $updated ) {
            $all_tournaments = get_option( 'sywat_tournaments_list', array() );
            if ( is_array( $all_tournaments ) ) {
                $all_tournaments[$t_name] = $t_data;
                update_option( 'sywat_tournaments_list', $all_tournaments, false );
            }
        }
    }
}

/**
 * Core Parser: Scrapes TFAA event results page using 3-layer precision matching and legacy-proven regex engine
 * (Layer 1: Manual Override ID/URL, Layer 2: Season Parameter Filter, Layer 3: Date Range & Title Matching),
 * registers missing archers, auto-expands line capacities, enriches gender cache, and injects scores.
 *
 * @param string $t_name Target tournament title.
 * @return array Execution response array with success status, count, and message.
 */
if ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) ) {
    function sywat_sync_tfaa_event_scores( $t_name ) {
        if ( empty( $t_name ) ) {
            return array( 'success' => false, 'message' => 'No active tournament selected.' );
        }

        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $all_tournaments ) || ! isset( $all_tournaments[$t_name] ) ) {
            return array( 'success' => false, 'message' => 'Selected tournament configuration not found.' );
        }

        $t_data = $all_tournaments[$t_name];
        $t_slug = sanitize_title( $t_name );
        $event_url = '';

        $http_args = array(
            'timeout'   => 25,
            'sslverify' => false,
            'headers'   => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'
            )
        );

        // LAYER 1: Check for Manual TFAA Event ID / Direct URL Override
        $tfaa_override = '';
        if ( ! empty( $t_data['tfaa_event_id'] ) ) {
            $tfaa_override = trim( $t_data['tfaa_event_id'] );
        } elseif ( ! empty( $t_data['tfaa_results_url'] ) ) {
            $tfaa_override = trim( $t_data['tfaa_results_url'] );
        }

        if ( ! empty( $tfaa_override ) ) {
            if ( is_numeric( $tfaa_override ) ) {
                $event_url = 'https://texasfieldarchery.org/results/' . $tfaa_override;
            } else {
                $event_url = ( strpos( $tfaa_override, 'http' ) === 0 ) ? $tfaa_override : 'https://texasfieldarchery.org' . ( strpos( $tfaa_override, '/' ) === 0 ? '' : '/' ) . $tfaa_override;
            }
        }

        // LAYERS 2 & 3: Scrape Index with Season Parameter + Date & Title Verification
        if ( empty( $event_url ) ) {
            $season_param = '';
            if ( ! empty( $t_data['season'] ) ) {
                $season_param = '?season=' . urlencode( trim( $t_data['season'] ) );
            }

            $results_main_url = 'https://texasfieldarchery.org/results' . $season_param;
            $main_resp        = wp_remote_get( $results_main_url, $http_args );

            if ( is_wp_error( $main_resp ) ) {
                return array( 'success' => false, 'message' => 'Failed to reach TFAA site: ' . $main_resp->get_error_message() );
            }

            $main_html    = wp_remote_retrieve_body( $main_resp );
            $clean_t_name = preg_replace( '/\s+/', ' ', str_replace( '-', ' ', $t_name ) );

            // Parse HTML table rows matching dates + links
            if ( preg_match_all( '/<tr[^>]*>\s*<td[^>]*>(.*?)<\/td>\s*<td[^>]*>\s*<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $main_html, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $m ) {
                    $row_date  = trim( wp_strip_all_tags( $m[1] ) );
                    $link_href = trim( $m[2] );
                    $link_text = preg_replace( '/\s+/', ' ', str_replace( '-', ' ', wp_strip_all_tags( $m[3] ) ) );

                    $name_match = ( stripos( $link_text, trim( $clean_t_name ) ) !== false || stripos( trim( $clean_t_name ), $link_text ) !== false );
                    
                    // Cross-verify year/month if tournament start_date is set
                    $date_match = true;
                    if ( ! empty( $t_data['start_date'] ) ) {
                        $t_year = date( 'Y', strtotime( $t_data['start_date'] ) );
                        if ( ! empty( $t_year ) && strpos( $row_date, $t_year ) === false ) {
                            $date_match = false;
                        }
                    }

                    if ( $name_match && $date_match ) {
                        $event_url = ( strpos( $link_href, 'http' ) === 0 ) ? $link_href : 'https://texasfieldarchery.org' . ( strpos( $link_href, '/' ) === 0 ? '' : '/' ) . $link_href;
                        break;
                    }
                }
            }

            // Fallback general link matching if row-based match was omitted
            if ( empty( $event_url ) && preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $main_html, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $m ) {
                    $link_href = trim( $m[1] );
                    $link_text = preg_replace( '/\s+/', ' ', str_replace( '-', ' ', wp_strip_all_tags( $m[2] ) ) );
                    if ( stripos( $link_text, trim( $clean_t_name ) ) !== false || stripos( trim( $clean_t_name ), $link_text ) !== false ) {
                        $event_url = ( strpos( $link_href, 'http' ) === 0 ) ? $link_href : 'https://texasfieldarchery.org' . ( strpos( $link_href, '/' ) === 0 ? '' : '/' ) . $link_href;
                        break;
                    }
                }
            }
        }

        if ( empty( $event_url ) ) {
            return array( 'success' => false, 'message' => 'Could not find a published TFAA results page matching \'' . esc_html( $t_name ) . '\'.' );
        }

        // Fetch target event detail results page
        $event_resp = wp_remote_get( $event_url, $http_args );
        if ( is_wp_error( $event_resp ) ) {
            return array( 'success' => false, 'message' => 'Failed to retrieve event results: ' . $event_resp->get_error_message() );
        }

        $event_html = wp_remote_retrieve_body( $event_resp );
        $event_html = str_replace( array( "\r", "\n" ), ' ', $event_html );

        // Legacy auto-season query fallback if page returned 0 entries
        if ( stripos( $event_html, '0 Entries' ) !== false || ( stripos( $event_html, 'Cub Male' ) === false && stripos( $event_html, 'Adult Male' ) === false ) ) {
            if ( preg_match_all( '/<option[^>]*value=["\']([^"\']+)["\'][^>]*>([^<]+)<\/option>/i', $event_html, $opt_matches, PREG_SET_ORDER ) ) {
                foreach ( $opt_matches as $om ) {
                    $val = trim( $om[1] );
                    if ( ! empty( $val ) && stripos( $val, 'Select' ) === false && preg_match( '/\d{4}/', $val ) ) {
                        $season_url = $event_url . ( strpos( $event_url, '?' ) === false ? '?' : '&' ) . 'Season=' . urlencode( $val );
                        $resp2 = wp_remote_get( $season_url, $http_args );
                        if ( ! is_wp_error( $resp2 ) ) {
                            $event_html = wp_remote_retrieve_body( $resp2 );
                            $event_html = str_replace( array( "\r", "\n" ), ' ', $event_html );
                        }
                        break;
                    }
                }
            }
        }

        if ( stripos( $event_html, '0 Entries' ) !== false ) {
            return array( 'success' => false, 'message' => 'TFAA event page has 0 published entries yet.' );
        }

        // Standardized Legacy Spacing & Tag Stripping Protocol
        $spaced_html = str_replace( '>', '> ', $event_html );
        $spaced_html = str_replace( '<', ' <', $spaced_html );
        $clean_html  = preg_replace( '/<\/?(?:tr|h[1-6]|div|p|br|li|ul|table)[^>]*>/is', "\n", $spaced_html );
        $plain_text  = wp_strip_all_tags( $clean_html );
        $raw_lines   = explode( "\n", $plain_text );

        $parsed_entries = array();
        $curr_div       = '';
        $curr_gen       = 'Male';
        $curr_style     = '';
        $curr_is_pro    = false;

        $cnt_20yd = 0;
        $cnt_cub  = 0;

        foreach ( $raw_lines as $raw_line ) {
            $line = trim( preg_replace( '/\s+/', ' ', html_entity_decode( $raw_line, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
            if ( empty( $line ) ) continue;

            if ( stripos( $line, 'Filters:' ) !== false || stripos( $line, 'Search' ) !== false || stripos( $line, 'Entries' ) !== false ) {
                continue;
            }

            if ( function_exists( 'sywat_parse_tfaa_div_gen' ) ) {
                $div_check = sywat_parse_tfaa_div_gen( $line );
                if ( ! empty( $div_check['division'] ) ) {
                    $curr_div    = $div_check['division'];
                    $curr_gen    = $div_check['gender'];
                    $curr_is_pro = ! empty( $div_check['is_pro'] );
                    continue;
                }
            }

            if ( function_exists( 'sywat_parse_tfaa_style' ) ) {
                $style_check = sywat_parse_tfaa_style( $line, $curr_is_pro );
                if ( ! empty( $style_check ) ) {
                    $curr_style = $style_check;
                    continue;
                }
            }

            // Legacy-proven name lookahead engine: Number + Space + (Last Name, First Name) + Space + Score + (Optional X count)
            if ( preg_match( '/^\d+\s+([A-Za-z\-\.\'\s]+,\s*[A-Za-z\-\.\'\s]+?)(?=\s+\d|$)\s+(\d{1,3})(?:\s+(\d{1,2})X?)?/i', $line, $matches ) ) {
                if ( empty( $curr_div ) || empty( $curr_style ) ) continue;

                $raw_name = trim( $matches[1] );
                $parts    = explode( ',', $raw_name, 2 );
                $name     = trim( $parts[1] ) . ' ' . trim( $parts[0] );
                $score    = isset( $matches[2] ) ? intval( $matches[2] ) : 0;
                $xs       = isset( $matches[3] ) ? intval( $matches[3] ) : 0;
                $dist     = ( stripos( $curr_div, 'Cub' ) !== false ) ? 'Cub' : '20yd';

                if ( $dist === 'Cub' ) $cnt_cub++;
                else $cnt_20yd++;

                $parsed_entries[] = array(
                    'name'     => $name,
                    'division' => $curr_div,
                    'gender'   => $curr_gen,
                    'style'    => $curr_style,
                    'distance' => $dist,
                    'score'    => $score,
                    'xs'       => $xs,
                );
            }
        }

        if ( empty( $parsed_entries ) ) {
            return array( 'success' => false, 'message' => 'No valid archer score rows could be extracted from the TFAA page.' );
        }

        // Auto-Expand Line 0 Capacity if incoming counts exceed limits
        $t_lines    = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : ( isset( $t_data['lines'] ) ? $t_data['lines'] : array() );
        $line_0_idx = ! empty( $t_lines ) ? key( $t_lines ) : '0';
        sywat_auto_expand_line_capacity( $t_data, $line_0_idx, $cnt_20yd, $cnt_cub, $t_name );

        // Pre-Fetch and Index Roster Data into O(1) Single-Key Hashtable
        $registrations = get_option( 'sywat_registrations', array() );
        if ( ! is_array( $registrations ) ) $registrations = array();

        $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( ! is_array( $master_regs ) ) $master_regs = array();

        $scores = get_option( 'sywat_scores_' . $t_slug, array() );
        if ( ! is_array( $scores ) ) $scores = array();

        $gender_cache = get_option( 'sywat_shooter_genders_cache', array() );
        if ( ! is_array( $gender_cache ) ) $gender_cache = array();

        $reg_lookup = array();
        foreach ( $registrations as $idx => $r ) {
            if ( isset( $r['tournament'], $r['name'] ) && strcasecmp( trim( $r['tournament'] ), trim( $t_name ) ) === 0 ) {
                $norm_key = strtolower( trim( $r['name'] ) );
                $reg_lookup[$norm_key] = $idx;
            }
        }

        $synced_count = 0;
        $line_name    = isset( $t_lines[$line_0_idx]['name'] ) ? $t_lines[$line_0_idx]['name'] : 'Line 1';

        foreach ( $parsed_entries as $entry ) {
            $name      = $entry['name'];
            $norm_name = strtolower( trim( $name ) );
            $target_id = '';

            if ( isset( $reg_lookup[$norm_name] ) ) {
                $r_idx = $reg_lookup[$norm_name];
                $target_id = $registrations[$r_idx]['id'];

                $registrations[$r_idx]['checked_in'] = 1;
                if ( empty( $registrations[$r_idx]['division'] ) ) $registrations[$r_idx]['division'] = $entry['division'];
                if ( empty( $registrations[$r_idx]['gender'] ) )   $registrations[$r_idx]['gender']   = $entry['gender'];
                if ( empty( $registrations[$r_idx]['style'] ) )    $registrations[$r_idx]['style']    = $entry['style'];

                update_option( 'sywat_t_' . $t_slug . '_' . $target_id, $registrations[$r_idx], false );
                $master_regs[$target_id] = $registrations[$r_idx];
            } else {
                $target_id = uniqid( 'reg_' );
                $new_reg = array(
                    'id'          => $target_id,
                    'tournament'  => $t_name,
                    'line_index'  => (string)$line_0_idx,
                    'line_name'   => $line_name,
                    'name'        => $name,
                    'phone'       => '',
                    'distance'    => $entry['distance'],
                    'type'        => 'Walk-In',
                    'division'    => $entry['division'],
                    'gender'      => $entry['gender'],
                    'style'       => $entry['style'],
                    'paid'        => ( $entry['distance'] === 'Cub' ? 'Youth' : 'Adult' ),
                    'balance_due' => 0.00,
                    'status'      => 'Pre-Reg',
                    'checked_in'  => 1,
                    'created_at'  => current_time( 'mysql' ),
                );

                update_option( 'sywat_t_' . $t_slug . '_' . $target_id, $new_reg, false );
                $master_regs[$target_id] = $new_reg;
                $registrations[]         = $new_reg;

                $reg_lookup[$norm_name] = count( $registrations ) - 1;
            }

            if ( ! empty( $entry['gender'] ) ) {
                $gender_cache[$norm_name] = $entry['gender'];
            }

            $scores[$target_id] = array(
                'score'    => $entry['score'],
                'xs'       => $entry['xs'],
                'saved_at' => current_time( 'mysql' ),
            );

            $synced_count++;
        }

        update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        update_option( 'sywat_registrations', $registrations, false );
        update_option( 'sywat_scores_' . $t_slug, $scores, false );
        update_option( 'sywat_shooter_genders_cache', $gender_cache, false );

        return array(
            'success' => true,
            'count'   => $synced_count,
            'message' => 'Successfully synced and checked in ' . $synced_count . ' archer scores from TFAA for \'' . esc_html( $t_name ) . '\'.'
        );
    }
}

/**
 * Handles real-time single score AJAX auto-save request from client.
 */
if ( ! function_exists( 'sywat_scores_handle_ajax_autosave' ) ) {
    function sywat_scores_handle_ajax_autosave() {
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sywat_action'] ) && $_POST['sywat_action'] === 'autosave_single_score' ) {
            if ( ! isset( $_POST['sywat_nonce'] ) || ! wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_scores_action' ) ) {
                wp_send_json_error( array( 'message' => 'Security token expired' ) );
            }

            $clean_id   = sanitize_text_field( $_POST['reg_id'] );
            $score_str  = isset( $_POST['score'] ) ? trim( $_POST['score'] ) : '';
            $x_str      = isset( $_POST['x_count'] ) ? trim( $_POST['x_count'] ) : '';
            $tourn_name = isset( $_POST['selected_tournament'] ) ? sanitize_text_field( $_POST['selected_tournament'] ) : '';

            if ( $score_str !== '' && ( intval( $score_str ) < 0 || intval( $score_str ) > 300 ) ) {
                wp_send_json_error( array( 'message' => 'Score out of range (0-300)' ) );
            }
            if ( $x_str !== '' && ( intval( $x_str ) < 0 || intval( $x_str ) > 60 ) ) {
                wp_send_json_error( array( 'message' => 'X count out of range (0-60)' ) );
            }

            $active_t_slug = sanitize_title( $tourn_name );
            $opt_key       = 'sywat_t_' . $active_t_slug . '_' . $clean_id;
            $reg           = get_option( $opt_key );

            wp_cache_delete( 'sywat_locks_' . $active_t_slug, 'options' );
            $locked_lines = get_option( 'sywat_locks_' . $active_t_slug, array() );

            if ( $reg ) {
                $line_idx = isset( $reg['line_index'] ) ? (string)$reg['line_index'] : '';
                if ( ! empty( $locked_lines[$line_idx] ) ) {
                    wp_send_json_error( array( 'message' => 'Line is locked' ) );
                }

                $reg['score']   = ( $score_str !== '' ) ? intval( $score_str ) : 0;
                $reg['x_count'] = ( $x_str !== '' )     ? intval( $x_str )     : 0;

                update_option( $opt_key, $reg, false );

                $scores_map = get_option( 'sywat_scores_' . $active_t_slug, array() );
                if ( ! is_array( $scores_map ) ) $scores_map = array();
                $scores_map[$clean_id] = array(
                    'score'    => $reg['score'],
                    'xs'       => $reg['x_count'],
                    'saved_at' => current_time( 'mysql' ),
                );
                update_option( 'sywat_scores_' . $active_t_slug, $scores_map, false );

                wp_send_json_success( array( 'reg_id' => $clean_id, 'message' => 'Saved' ) );
            }

            wp_send_json_error( array( 'message' => 'Archer record not found' ) );
        }
    }
}

/**
 * Processes batch score save, line locks, score reset, and TFAA event score sync POST requests.
 *
 * @param string $selected_tournament Active tournament title.
 * @param string $selected_line_idx   Filtered line index ('all' or line number).
 * @param bool   $is_admin           Whether current user has administrative score privileges.
 * @param array  $locked_lines       Reference to active locked lines array.
 * @param bool   $current_view_locked Reference to current view lock boolean flag.
 * @return string HTML alert status markup string.
 */
if ( ! function_exists( 'sywat_scores_handle_batch_actions' ) ) {
    function sywat_scores_handle_batch_actions( $selected_tournament, $selected_line_idx, $is_admin, &$locked_lines, &$current_view_locked ) {
        global $wpdb;
        $notice = '';

        $is_valid_nonce = ( isset( $_POST['sywat_nonce'] ) && wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_scores_action' ) );
        $action         = isset( $_POST['sywat_action'] ) ? sanitize_text_field( $_POST['sywat_action'] ) : '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $action && ! $is_valid_nonce && $action !== 'autosave_single_score' ) {
            return '<div class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce ) {
            $active_t_slug = sanitize_title( $selected_tournament );

            // Action: 🔄 Sync TFAA Event Scores
            if ( $action === 'sync_tfaa_scores' ) {
                $res = sywat_sync_tfaa_event_scores( $selected_tournament );
                if ( ! empty( $res['success'] ) ) {
                    return '<div class="sywat-alert sywat-alert-success">🟢 ' . esc_html( $res['message'] ) . '</div>';
                } else {
                    return '<div class="sywat-alert sywat-alert-danger">⚠️ Sync Failed: ' . esc_html( $res['message'] ) . '</div>';
                }
            }

            // Action: Toggle Line Lock
            if ( $action === 'toggle_lock' ) {
                if ( ! $is_admin ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">Access denied. Administrator privileges required to lock/unlock lines.</div>';
                } elseif ( $selected_line_idx === 'all' ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">Please filter to a specific shooting line first to lock or unlock it.</div>';
                } else {
                    if ( ! empty( $locked_lines[$selected_line_idx] ) ) {
                        unset( $locked_lines[$selected_line_idx] );
                        $notice = '<div class="sywat-alert sywat-alert-success">Line unlocked. Scores can now be edited.</div>';
                    } else {
                        $locked_lines[$selected_line_idx] = 1;
                        $notice = '<div class="sywat-alert sywat-alert-success">Line locked. Scores on this line are frozen and cannot be edited until unlocked.</div>';
                    }

                    delete_option( 'sywat_locks_' . $active_t_slug );
                    add_option( 'sywat_locks_' . $active_t_slug, $locked_lines, '', 'no' );
                    
                    update_option( 'sywat_current_tournament', $selected_tournament, false );
                    $current_view_locked = ! empty( $locked_lines[$selected_line_idx] );
                }
            }

            // Action: Reset All Scores to 0
            if ( $action === 'reset_scores' ) {
                if ( ! $is_admin ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">Access denied. Administrator privileges required to reset scores.</div>';
                } else {
                    $reset_count = 0;
                    $all_rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $wpdb->esc_like( 'sywat_t_' . $active_t_slug . '_' ) . '%'
                    ) );

                    $scores_map = get_option( 'sywat_scores_' . $active_t_slug, array() );
                    if ( ! is_array( $scores_map ) ) $scores_map = array();

                    foreach ( $all_rows as $row ) {
                        $reg = maybe_unserialize( $row->option_value );
                        if ( is_array( $reg ) && isset( $reg['id'] ) ) {
                            $match_tourn   = isset( $reg['tournament'] ) && $reg['tournament'] === $selected_tournament;
                            $match_line    = ( $selected_line_idx === 'all' ) || ( isset( $reg['line_index'] ) && (string)$reg['line_index'] === (string)$selected_line_idx );
                            $is_checked_in = ! empty( $reg['checked_in'] );
                            $is_locked     = ! empty( $locked_lines[$reg['line_index']] );

                            if ( $match_tourn && $match_line && $is_checked_in && ! $is_locked ) {
                                $opt_key = 'sywat_t_' . $active_t_slug . '_' . $reg['id'];
                                $reg['score']   = 0;
                                $reg['x_count'] = 0;
                                update_option( $opt_key, $reg, false );

                                $scores_map[$reg['id']] = array(
                                    'score'    => 0,
                                    'xs'       => 0,
                                    'saved_at' => current_time( 'mysql' ),
                                );

                                $reset_count++;
                            }
                        }
                    }

                    update_option( 'sywat_scores_' . $active_t_slug, $scores_map, false );
                    $notice = '<div class="sywat-alert sywat-alert-success">Successfully reset scores and X counts to 0 for <strong>' . $reset_count . '</strong> unlocked, checked-in archer(s).</div>';
                }
            }

            // Action: Save Batch Scores
            if ( $action === 'save_scores' ) {
                $has_invalid_data = false;
                
                $submitted_ids = array_unique( array_merge(
                    isset( $_POST['scores'] ) && is_array( $_POST['scores'] ) ? array_keys( $_POST['scores'] ) : array(),
                    isset( $_POST['x_counts'] ) && is_array( $_POST['x_counts'] ) ? array_keys( $_POST['x_counts'] ) : array()
                ) );

                foreach ( $submitted_ids as $id ) {
                    $clean_id = sanitize_text_field( $id );
                    if ( isset( $_POST['scores'][$clean_id] ) ) {
                        $score_str = trim( $_POST['scores'][$clean_id] );
                        if ( $score_str !== '' ) {
                            $s_val = intval( $score_str );
                            if ( $s_val < 0 || $s_val > 300 ) { $has_invalid_data = true; break; }
                        }
                    }
                    if ( isset( $_POST['x_counts'][$clean_id] ) ) {
                        $x_str = trim( $_POST['x_counts'][$clean_id] );
                        if ( $x_str !== '' ) {
                            $x_val = intval( $x_str );
                            if ( $x_val < 0 || $x_val > 60 ) { $has_invalid_data = true; break; }
                        }
                    }
                }

                if ( $has_invalid_data ) {
                    $notice = '<div class="sywat-alert sywat-alert-danger">⚠️ <strong>Save Rejected!</strong> One or more values were invalid. Scores must be 0–300 and X Counts 0–60. Please correct highlighted fields and save again.</div>';
                } elseif ( empty( $submitted_ids ) ) {
                    $notice = '<div class="sywat-alert sywat-alert-success">No changes were made.</div>';
                } else {
                    $saved_count = 0;
                    $scores_map  = get_option( 'sywat_scores_' . $active_t_slug, array() );
                    if ( ! is_array( $scores_map ) ) $scores_map = array();

                    foreach ( $submitted_ids as $id ) {
                        $clean_id = sanitize_text_field( $id );
                        $opt_key  = 'sywat_t_' . $active_t_slug . '_' . $clean_id;
                        $reg      = get_option( $opt_key );

                        if ( $reg ) {
                            if ( ! empty( $locked_lines[$reg['line_index']] ) ) {
                                continue;
                            }

                            if ( isset( $_POST['scores'][$clean_id] ) ) {
                                $raw_score = trim( $_POST['scores'][$clean_id] );
                                $reg['score'] = ( $raw_score !== '' ) ? intval( $raw_score ) : 0;
                            }
                            if ( isset( $_POST['x_counts'][$clean_id] ) ) {
                                $raw_x = trim( $_POST['x_counts'][$clean_id] );
                                $reg['x_count'] = ( $raw_x !== '' ) ? intval( $raw_x ) : 0;
                            }

                            update_option( $opt_key, $reg, false );

                            $scores_map[$clean_id] = array(
                                'score'    => isset( $reg['score'] ) ? intval( $reg['score'] ) : 0,
                                'xs'       => isset( $reg['x_count'] ) ? intval( $reg['x_count'] ) : 0,
                                'saved_at' => current_time( 'mysql' ),
                            );

                            $saved_count++;
                        }
                    }

                    update_option( 'sywat_scores_' . $active_t_slug, $scores_map, false );
                    
                    if ( $saved_count > 0 ) {
                        $notice = '<div class="sywat-alert sywat-alert-success">Successfully updated scores for <strong>' . $saved_count . '</strong> unlocked archer(s)!</div>';
                    }
                }
            }
        }

        return $notice;
    }
}
