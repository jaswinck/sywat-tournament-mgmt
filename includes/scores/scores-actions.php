<?php
/**
 * Sub-Module: Scores Engine Action Handlers, Background Link Engine & TFAA Sync
 * Path: includes/scores/scores-actions.php
 * Description: Pure POST request processing, background WP-Cron TFAA URL discovery worker, batch score saves, dynamic line capacity expansion, line lock management, and TFAA scorecard scraper.
 * Author: SYWAT Archery
 * Version: 10.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Register WP-Cron Schedule Hook on Initialization
add_action( 'init', 'sywat_register_tfaa_url_cron' );
add_action( 'sywat_hourly_tfaa_url_sync', 'sywat_bg_sync_all_tfaa_urls' );

function sywat_register_tfaa_url_cron() {
    if ( ! wp_next_scheduled( 'sywat_hourly_tfaa_url_sync' ) ) {
        wp_schedule_event( time(), 'hourly', 'sywat_hourly_tfaa_url_sync' );
    }
}

/**
 * Background Worker: Scans TFAA Results Directory ONCE and updates all tournament URLs in DB.
 */
function sywat_bg_sync_all_tfaa_urls() {
    $all_tournaments = get_option( 'sywat_tournaments_list', array() );
    if ( empty( $all_tournaments ) || ! is_array( $all_tournaments ) ) return;

    $http_args = array(
        'timeout'   => 20,
        'sslverify' => false,
        'headers'   => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
        )
    );

    // Fetch master TFAA results page
    $index_resp = wp_remote_get( 'https://texasfieldarchery.org/results', $http_args );
    if ( is_wp_error( $index_resp ) ) return;

    $html = wp_remote_retrieve_body( $index_resp );
    if ( empty( $html ) ) return;

    if ( ! preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
        return;
    }

    $updated = false;

    foreach ( $all_tournaments as $t_name => $t_data ) {
        // Skip if a direct numerical event ID or custom deep URL is manually defined
        if ( ! empty( $t_data['tfaa_event_id'] ) ) continue;

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
                    $all_tournaments[$t_name]['tfaa_results_url'] = $resolved_url;
                    $updated = true;
                }
                break;
            }
        }
    }

    if ( $updated ) {
        update_option( 'sywat_tournaments_list', $all_tournaments, false );
    }
}

/**
 * Universal Token & Qualifier Matcher for TFAA Tournament Names
 */
if ( ! function_exists( 'sywat_match_tfaa_tournament_name' ) ) {
    function sywat_match_tfaa_tournament_name( $sywat_name, $tfaa_name ) {
        $get_qualifier = function( $str ) {
            if ( preg_match( '/(?:[\s\-\#]+)(\d+)\s*$/i', $str, $m ) ) {
                return (int)$m[1];
            }
            return null;
        };

        $sywat_q = $get_qualifier( $sywat_name );
        $tfaa_q  = $get_qualifier( $tfaa_name );

        if ( $sywat_q !== null && $tfaa_q !== null && $sywat_q !== $tfaa_q ) {
            return false;
        }

        $normalize_tokens = function( $str ) {
            $str = preg_replace( '/(?:[\s\-\#]+)(\d+)\s*$/i', '', $str );
            $str = strtolower( $str );
            $str = str_replace( array( '&', '-', '_', '/', ',', '.', '\'' ), ' ', $str );
            
            $noise  = array( 'archery', 'club', 'guns', 'outdoors', 'shop', 'shops', 'pro', 'academy', 'range', 'center', 'and' );
            $words  = explode( ' ', $str );
            $tokens = array();

            foreach ( $words as $w ) {
                $w = trim( $w );
                if ( ! empty( $w ) && ! in_array( $w, $noise ) && strlen( $w ) > 1 ) {
                    $tokens[] = $w;
                }
            }
            return $tokens;
        };

        $sywat_tokens = $normalize_tokens( $sywat_name );
        $tfaa_tokens  = $normalize_tokens( $tfaa_name );

        if ( empty( $sywat_tokens ) ) {
            $clean_s = strtolower( trim( preg_replace( '/\s+/', ' ', str_replace( '-', ' ', $sywat_name ) ) ) );
            $clean_t = strtolower( trim( preg_replace( '/\s+/', ' ', str_replace( '-', ' ', $tfaa_name ) ) ) );
            return ( stripos( $clean_t, $clean_s ) !== false || stripos( $clean_s, $clean_t ) !== false );
        }

        $raw_tfaa_lower = strtolower( $tfaa_name );
        foreach ( $sywat_tokens as $token ) {
            if ( strpos( $raw_tfaa_lower, $token ) === false && ! in_array( $token, $tfaa_tokens ) ) {
                return false;
            }
        }

        return true;
    }
}

/**
 * Auto-expands physical line capacity if imported TFAA batch exceeds limits.
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
 * Legacy Parser Helper: Division & Gender
 */
if ( ! function_exists( 'sywat_parse_tfaa_div_gen' ) ) {
    function sywat_parse_tfaa_div_gen( $text ) {
        if ( strlen( $text ) > 80 ) return array( 'division' => '', 'gender' => '' );

        $has_female = preg_match( '/\bfemale\b/i', $text );
        $has_male   = preg_match( '/\bmale\b/i', $text );

        if ( ( ! $has_female && ! $has_male ) || ( $has_female && $has_male ) ) {
            return array( 'division' => '', 'gender' => '' );
        }

        $gen = $has_female ? 'Female' : 'Male';
        $div = '';

        if ( stripos( $text, 'Master Senior' ) !== false || stripos( $text, 'Master' ) !== false ) $div = 'Master Senior (70+)';
        elseif ( stripos( $text, 'Silver Senior' ) !== false ) $div = 'Silver Senior (60 - 69)';
        elseif ( stripos( $text, 'Senior' ) !== false ) $div = 'Senior (50 - 59)';
        elseif ( stripos( $text, 'Young Adult' ) !== false ) $div = 'Young Adult (15 - 17)';
        elseif ( stripos( $text, 'Youth' ) !== false ) $div = 'Youth (12 - 14)';
        elseif ( stripos( $text, 'Cub' ) !== false ) $div = 'Cub (8 - 11)';
        elseif ( stripos( $text, 'Adult' ) !== false ) $div = 'Adult (18 - 49)';

        if ( empty( $div ) ) return array( 'division' => '', 'gender' => '' );
        return array( 'division' => $div, 'gender' => $gen );
    }
}

/**
 * Legacy Parser Helper: Style
 */
if ( ! function_exists( 'sywat_parse_tfaa_style' ) ) {
    function sywat_parse_tfaa_style( $text ) {
        if ( strlen( $text ) > 80 ) return '';
        $style = '';
        $is_pro = ( stripos( $text, 'Pro' ) !== false );

        if ( stripos( $text, 'Freestyle Limited Recurve' ) !== false || stripos( $text, 'FSLR' ) !== false || stripos( $text, 'FSL-R' ) !== false ) $style = 'FSLR – Freestyle Limited Recurve (or Olympic Recurve)';
        elseif ( stripos( $text, 'Freestyle Limited' ) !== false || stripos( $text, 'FSL' ) !== false ) $style = 'FSL – Freestyle Limited';
        elseif ( stripos( $text, 'Modern Bowhunter' ) !== false || stripos( $text, 'MBH' ) !== false ) $style = 'MBH – Modern Bowhunter';
        elseif ( stripos( $text, 'Bowhunter Freestyle' ) !== false || stripos( $text, 'BHFS' ) !== false ) $style = 'BHFS – Bowhunter Freestyle';
        elseif ( stripos( $text, 'Barebow Compound' ) !== false || stripos( $text, 'BBC' ) !== false ) $style = 'BBC – Barebow Compound';
        elseif ( stripos( $text, 'Barebow Recurve' ) !== false || stripos( $text, 'BBR' ) !== false ) $style = 'BBR – Barebow Recurve';
        elseif ( stripos( $text, 'Traditional' ) !== false || stripos( $text, 'TRAD' ) !== false ) $style = 'TRAD – Traditional';
        elseif ( stripos( $text, 'Longbow' ) !== false || stripos( $text, 'LB' ) !== false ) $style = 'LB – Longbow';
        elseif ( stripos( $text, 'Genesis' ) !== false || stripos( $text, 'GEN' ) !== false ) $style = 'Genesis';
        elseif ( stripos( $text, 'Guest' ) !== false || stripos( $text, 'GUEST' ) !== false ) $style = 'Guest';
        elseif ( stripos( $text, 'Freestyle' ) !== false || stripos( $text, 'FS' ) !== false ) $style = $is_pro ? 'FS Pro – Freestyle Pro' : 'FS – Freestyle';

        return $style;
    }
}

/**
 * Core Parser: TFAA Event Score Engine
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

        $t_data    = $all_tournaments[$t_name];
        $t_slug    = sanitize_title( $t_name );
        $event_url = '';

        // Read direct URL stored in DB by background cron or manual override
        if ( ! empty( $t_data['tfaa_event_id'] ) ) {
            $override  = trim( $t_data['tfaa_event_id'] );
            $event_url = is_numeric( $override ) ? 'https://texasfieldarchery.org/results/' . $override : $override;
        } elseif ( ! empty( $t_data['tfaa_results_url'] ) ) {
            $event_url = trim( $t_data['tfaa_results_url'] );
        }

        if ( empty( $event_url ) ) {
            // Trigger an immediate background sync run if missing
            sywat_bg_sync_all_tfaa_urls();
            $all_tournaments = get_option( 'sywat_tournaments_list', array() );
            if ( ! empty( $all_tournaments[$t_name]['tfaa_results_url'] ) ) {
                $event_url = $all_tournaments[$t_name]['tfaa_results_url'];
            }
        }

        if ( empty( $event_url ) ) {
            return array( 'success' => false, 'message' => 'Could not find a published TFAA results page matching \'' . esc_html( $t_name ) . '\'.' );
        }

        $http_args = array(
            'timeout'   => 25,
            'sslverify' => false,
            'headers'   => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'
            )
        );

        $event_resp = wp_remote_get( $event_url, $http_args );
        if ( is_wp_error( $event_resp ) ) {
            return array( 'success' => false, 'message' => 'Failed to retrieve event results: ' . $event_resp->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $event_resp );
        $body = str_replace( array( "\r", "\n" ), " ", $body );

        if ( stripos( $body, '0 Entries' ) !== false ) {
            return array( 'success' => false, 'message' => 'TFAA event page has 0 published entries yet.' );
        }

        // HTML Pre-Processing
        $spaced_body = str_replace( '>', '> ', $body );
        $spaced_body = str_replace( '<', ' <', $spaced_body );
        $clean_html  = preg_replace( '/<\/?(?:tr|h[1-6]|div|p|br|li|ul|table)[^>]*>/is', "\n", $spaced_body );
        $plain_text  = wp_strip_all_tags( $clean_html );
        $raw_lines   = explode( "\n", $plain_text );

        $parsed_entries = array();
        $curr_div   = '';
        $curr_gen   = 'Male';
        $curr_style = '';
        
        $cnt_20yd = 0;
        $cnt_cub  = 0;

        foreach ( $raw_lines as $raw_line ) {
            $decoded = html_entity_decode( $raw_line, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $decoded = str_replace( "\xC2\xA0", ' ', $decoded );
            $line    = trim( preg_replace( '/\s+/u', ' ', $decoded ) );

            if ( empty( $line ) || stripos( $line, 'Filters:' ) !== false || stripos( $line, 'Search' ) !== false || stripos( $line, 'Entries' ) !== false ) {
                continue;
            }

            // --- 1. ARCHER MATCH FIRST ---
            if ( preg_match( '/^(\d+)\s+([^,]+,\s*[^0-9]+?)(?=\s+\d|$)/', $line, $matches ) ) {
                if ( empty( $curr_div ) || empty( $curr_style ) ) continue;

                $raw_name = trim( $matches[2] );
                $is_guest = ( stripos( $raw_name, '[GUEST]' ) !== false || stripos( $line, '[GUEST]' ) !== false || $curr_style === 'Guest' );
                $is_inc   = ( stripos( $raw_name, '[INC]' ) !== false || stripos( $line, '[INC]' ) !== false );
                
                $clean_raw_name = trim( preg_replace( '/\[.*?\]/', '', $raw_name ) );

                $parts      = explode( ',', $clean_raw_name, 2 );
                $clean_name = ( count( $parts ) === 2 ) ? trim( $parts[1] ) . ' ' . trim( $parts[0] ) : trim( $clean_raw_name );

                $rest        = trim( substr( $line, strlen( $matches[0] ) ) );
                $score_parts = explode( ' ', $rest );
                
                $score = isset( $score_parts[0] ) ? intval( $score_parts[0] ) : 0;
                $xs    = isset( $score_parts[1] ) ? intval( $score_parts[1] ) : 0;

                $dist = ( stripos( $curr_div, 'Cub' ) !== false ) ? 'Cub' : '20yd';
                if ( $dist === 'Cub' ) $cnt_cub++;
                else $cnt_20yd++;

                $parsed_entries[] = array(
                    'clean_name' => $clean_name,
                    'division'   => $curr_div,
                    'gender'     => $curr_gen,
                    'style'      => $curr_style,
                    'distance'   => $dist,
                    'score'      => $score,
                    'xs'         => $xs,
                    'is_guest'   => $is_guest,
                    'is_inc'     => $is_inc
                );

                continue;
            }

            // --- 2. DIVISION CHECK ---
            $div_check = sywat_parse_tfaa_div_gen( $line );
            if ( ! empty( $div_check['division'] ) ) {
                $curr_div = $div_check['division'];
                $curr_gen = $div_check['gender'];
                continue;
            }

            // --- 3. STYLE CHECK ---
            $style_check = sywat_parse_tfaa_style( $line );
            if ( ! empty( $style_check ) ) {
                $curr_style = $style_check;
                if ( stripos( $line, 'Pro' ) !== false && $curr_style === 'FS – Freestyle' ) {
                    $curr_style = 'FS Pro – Freestyle Pro';
                }
                continue;
            }
        }

        if ( empty( $parsed_entries ) ) {
            return array( 'success' => false, 'message' => 'No valid archer score rows extracted.' );
        }

        // Auto-Expand Line Capacity
        $t_lines    = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : ( isset( $t_data['lines'] ) ? $t_data['lines'] : array() );
        $line_0_idx = ! empty( $t_lines ) ? key( $t_lines ) : '0';
        sywat_auto_expand_line_capacity( $t_data, $line_0_idx, $cnt_20yd, $cnt_cub, $t_name );

        $registrations = get_option( 'sywat_registrations', array() );
        if ( ! is_array( $registrations ) ) $registrations = array();

        $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( ! is_array( $master_regs ) ) $master_regs = array();

        $scores = get_option( 'sywat_scores_' . $t_slug, array() );
        if ( ! is_array( $scores ) ) $scores = array();

        $gender_cache = get_option( 'sywat_shooter_genders_cache', array() );
        if ( ! is_array( $gender_cache ) ) $gender_cache = array();

        $normalize_key = function( $name ) {
            $n = preg_replace( '/\[.*?\]|\(.*?\)/', '', $name );
            $n = preg_replace( '/[^a-zA-Z\s]/', '', $n );
            $n = preg_replace( '/\s+/', ' ', $n );
            return strtolower( trim( $n ) );
        };

        $reg_lookup = array();
        $occupied_lines = array();
        foreach ( $registrations as $idx => $r ) {
            if ( isset( $r['tournament'], $r['name'] ) && strcasecmp( trim( $r['tournament'] ), trim( $t_name ) ) === 0 ) {
                $norm_key = $normalize_key( $r['name'] );
                $reg_lookup[$norm_key][] = $idx;
                $occupied_lines[$norm_key][] = (string)$r['line_index'];
            }
        }

        $processed_reg_ids = array();
        $primary_styles    = array();
        $stats_updated     = 0;
        $stats_created     = 0;

        foreach ( $parsed_entries as $entry ) {
            $norm_name = $normalize_key( $entry['clean_name'] );
            
            if ( ! $entry['is_guest'] && ! $entry['is_inc'] && empty( $primary_styles[$norm_name] ) ) {
                $primary_styles[$norm_name] = $entry['style'];
            }

            $final_style = $entry['style'];
            if ( $entry['is_guest'] || $final_style === 'Guest' ) {
                $final_style = ! empty( $primary_styles[$norm_name] ) ? $primary_styles[$norm_name] : 'Guest';
            }

            $target_r_idx = -1;
            if ( isset( $reg_lookup[$norm_name] ) ) {
                foreach ( $reg_lookup[$norm_name] as $idx ) {
                    $id = $registrations[$idx]['id'];
                    if ( ! in_array( $id, $processed_reg_ids ) ) {
                        $target_r_idx = $idx;
                        break;
                    }
                }
            }

            if ( $target_r_idx !== -1 ) {
                $target_id = $registrations[$target_r_idx]['id'];
                $processed_reg_ids[] = $target_id;

                $registrations[$target_r_idx]['checked_in'] = 1;
                $registrations[$target_r_idx]['name']       = $entry['clean_name'];
                $registrations[$target_r_idx]['score']      = $entry['score'];
                $registrations[$target_r_idx]['x_count']    = $entry['xs'];

                if ( $entry['is_guest'] ) {
                    $registrations[$target_r_idx]['type']   = 'Guest';
                }
                
                if ( empty( $registrations[$target_r_idx]['division'] ) ) $registrations[$target_r_idx]['division'] = $entry['division'];
                if ( empty( $registrations[$target_r_idx]['gender'] ) )   $registrations[$target_r_idx]['gender']   = $entry['gender'];
                if ( empty( $registrations[$target_r_idx]['style'] ) )    $registrations[$target_r_idx]['style']    = $final_style;

                update_option( 'sywat_t_' . $t_slug . '_' . $target_id, $registrations[$target_r_idx], false );
                $master_regs[$target_id] = $registrations[$target_r_idx];

                $stats_updated++;
            } else {
                if ( ! isset( $occupied_lines[$norm_name] ) ) {
                    $occupied_lines[$norm_name] = array();
                }

                $assigned_line_idx = $line_0_idx;
                foreach ( $t_lines as $l_idx => $l_data ) {
                    if ( ! in_array( (string)$l_idx, $occupied_lines[$norm_name] ) ) {
                        $assigned_line_idx = (string)$l_idx;
                        break;
                    }
                }

                $line_name = isset( $t_lines[$assigned_line_idx]['name'] ) ? $t_lines[$assigned_line_idx]['name'] : 'Line ' . ( $assigned_line_idx + 1 );
                $target_id = uniqid( 'reg_seq_' );
                $new_type  = $entry['is_guest'] ? 'Guest' : 'Walk-In';

                $new_reg = array(
                    'id'          => $target_id,
                    'tournament'  => $t_name,
                    'line_index'  => $assigned_line_idx,
                    'line_name'   => $line_name,
                    'name'        => $entry['clean_name'],
                    'phone'       => '',
                    'distance'    => $entry['distance'],
                    'type'        => $new_type,
                    'division'    => $entry['division'],
                    'gender'      => $entry['gender'],
                    'style'       => $final_style,
                    'paid'        => ( $entry['distance'] === 'Cub' ? 'Youth' : 'Adult' ),
                    'balance_due' => 0.00,
                    'status'      => 'Pre-Reg',
                    'checked_in'  => 1,
                    'score'       => $entry['score'],
                    'x_count'     => $entry['xs'],
                    'created_at'  => current_time( 'mysql' ),
                );

                update_option( 'sywat_t_' . $t_slug . '_' . $target_id, $new_reg, false );
                $master_regs[$target_id] = $new_reg;
                $registrations[]         = $new_reg;

                $processed_reg_ids[]          = $target_id;
                $occupied_lines[$norm_name][] = $assigned_line_idx;
                $reg_lookup[$norm_name][]     = count( $registrations ) - 1;

                $stats_created++;
            }

            if ( ! empty( $entry['gender'] ) ) {
                $gender_cache[$norm_name] = $entry['gender'];
            }

            $scores[$target_id] = array(
                'score'    => $entry['score'],
                'xs'       => $entry['xs'],
                'saved_at' => current_time( 'mysql' ),
            );
        }

        update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        update_option( 'sywat_registrations', $registrations, false );
        update_option( 'sywat_scores_' . $t_slug, $scores, false );
        update_option( 'sywat_shooter_genders_cache', $gender_cache, false );

        return array(
            'success' => true,
            'count'   => ( $stats_updated + $stats_created ),
            'message' => "Successfully synced <strong>" . ( $stats_updated + $stats_created ) . "</strong> archers from TFAA! (Updated {$stats_updated} existing records and created {$stats_created} new sequential records)."
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

            // Action: Sync TFAA Event Scores
            if ( $action === 'sync_tfaa_scores' ) {
                $res = sywat_sync_tfaa_event_scores( $selected_tournament );
                if ( ! empty( $res['success'] ) ) {
                    return '<div class="sywat-alert sywat-alert-success">🟢 ' . $res['message'] . '</div>';
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
