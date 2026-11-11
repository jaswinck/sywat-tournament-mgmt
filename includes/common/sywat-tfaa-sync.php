<?php
/**
 * Shared Module: TFAA Event Score Scraper & Deduplicated Sync Engine
 * Path: includes/common/sywat-tfaa-sync.php
 * Description: Standalone TFAA remote HTTP scraper, HTML parser, and registration reconciliation engine.
 * Author: SYWAT Archery
 * Version: 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auto-expands physical line capacity if imported TFAA batch exceeds current limits.
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
 * TFAA Parser Helper: Division & Gender Resolution
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
 * TFAA Parser Helper: Shooting Style Resolution
 */
if ( ! function_exists( 'sywat_parse_tfaa_style' ) ) {
    function sywat_parse_tfaa_style( $text ) {
        if ( strlen( $text ) > 80 ) return '';
        $style  = '';
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
 * Core Universal TFAA Event Score Sync Engine with Deduplication
 *
 * @param string $t_name Tournament Title/Key Name.
 * @return array Result payload array containing success boolean, count, and notice message.
 */
if ( ! function_exists( 'sywat_sync_tfaa_event_scores' ) ) {
    function sywat_sync_tfaa_event_scores( $t_name ) {
        global $wpdb;

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

        if ( ! empty( $t_data['tfaa_event_id'] ) ) {
            $override  = trim( $t_data['tfaa_event_id'] );
            $event_url = is_numeric( $override ) ? 'https://texasfieldarchery.org/results/' . $override : $override;
        } elseif ( ! empty( $t_data['tfaa_results_url'] ) ) {
            $event_url = trim( $t_data['tfaa_results_url'] );
        }

        if ( empty( $event_url ) ) {
            return array( 'success' => false, 'message' => 'No direct TFAA results link found for \'' . esc_html( $t_name ) . '\'.' );
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

        if ( preg_match( '/\b0\s+Entries\b/i', $body ) ) {
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

            // 1. Archer Match First
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

            // 2. Division Check
            $div_check = sywat_parse_tfaa_div_gen( $line );
            if ( ! empty( $div_check['division'] ) ) {
                $curr_div = $div_check['division'];
                $curr_gen = $div_check['gender'];
                continue;
            }

            // 3. Style Check
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
        $line_0_idx = ! empty( $t_lines ) ? (string)key( $t_lines ) : '0';
        sywat_auto_expand_line_capacity( $t_data, $line_0_idx, $cnt_20yd, $cnt_cub, $t_name );

        // FETCH ALL LIVE REGISTRATIONS DIRECTLY FROM WP_OPTIONS
        $registrations = array();
        $db_results    = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'sywat_t_' . $t_slug . '_' ) . '%'
        ) );

        foreach ( $db_results as $row ) {
            $reg_data = maybe_unserialize( $row->option_value );
            if ( is_array( $reg_data ) && isset( $reg_data['id'] ) ) {
                $registrations[$reg_data['id']] = $reg_data;
            }
        }

        $normalize_str = function( $str ) {
            $n = preg_replace( '/\[.*?\]|\(.*?\)/', '', $str );
            $n = preg_replace( '/[^a-zA-Z0-9\s]/', '', $n );
            $n = preg_replace( '/\s+/', ' ', $n );
            return strtolower( trim( $n ) );
        };

        $claimed_reg_ids = array();
        $primary_styles  = array();
        $stats_updated   = 0;
        $stats_created   = 0;

        $master_regs = array();
        $scores      = get_option( 'sywat_scores_' . $t_slug, array() );
        if ( ! is_array( $scores ) ) $scores = array();

        $gender_cache = get_option( 'sywat_shooter_genders_cache', array() );
        if ( ! is_array( $gender_cache ) ) $gender_cache = array();

        foreach ( $parsed_entries as $entry ) {
            $norm_name      = $normalize_str( $entry['clean_name'] );
            $norm_div       = $normalize_str( $entry['division'] );
            $norm_style     = $normalize_str( $entry['style'] );
            $entry_is_guest = ! empty( $entry['is_guest'] ) || $entry['style'] === 'Guest';

            if ( ! $entry_is_guest && ! $entry['is_inc'] && empty( $primary_styles[$norm_name] ) ) {
                $primary_styles[$norm_name] = $entry['style'];
            }

            $final_style = $entry['style'];
            if ( $entry_is_guest && $final_style === 'Guest' ) {
                $final_style = ! empty( $primary_styles[$norm_name] ) ? $primary_styles[$norm_name] : 'Guest';
            }

            // Find best matching existing registration for this entry
            $matched_reg_id = null;

            // Pass 1: Match Name + Division + Style + Guest Status
            foreach ( $registrations as $r_id => $r ) {
                if ( isset( $claimed_reg_ids[$r_id] ) ) continue;

                $r_name     = $normalize_str( isset( $r['name'] ) ? $r['name'] : '' );
                $r_div      = $normalize_str( isset( $r['division'] ) ? $r['division'] : '' );
                $r_style    = $normalize_str( isset( $r['style'] ) ? $r['style'] : '' );
                $r_is_guest = ( isset( $r['type'] ) && $r['type'] === 'Guest' ) || ( isset( $r['style'] ) && $r['style'] === 'Guest' );

                if ( $r_name === $norm_name && $r_div === $norm_div && $r_style === $norm_style && $r_is_guest === $entry_is_guest ) {
                    $matched_reg_id = $r_id;
                    break;
                }
            }

            // Pass 2: Match Name + Division + Guest Status
            if ( ! $matched_reg_id ) {
                foreach ( $registrations as $r_id => $r ) {
                    if ( isset( $claimed_reg_ids[$r_id] ) ) continue;

                    $r_name     = $normalize_str( isset( $r['name'] ) ? $r['name'] : '' );
                    $r_div      = $normalize_str( isset( $r['division'] ) ? $r['division'] : '' );
                    $r_is_guest = ( isset( $r['type'] ) && $r['type'] === 'Guest' ) || ( isset( $r['style'] ) && $r['style'] === 'Guest' );

                    if ( $r_name === $norm_name && $r_div === $norm_div && $r_is_guest === $entry_is_guest ) {
                        $matched_reg_id = $r_id;
                        break;
                    }
                }
            }

            // Pass 3: Match Name + Guest Status (Incomplete pre-regs)
            if ( ! $matched_reg_id ) {
                foreach ( $registrations as $r_id => $r ) {
                    if ( isset( $claimed_reg_ids[$r_id] ) ) continue;

                    $r_name     = $normalize_str( isset( $r['name'] ) ? $r['name'] : '' );
                    $r_is_guest = ( isset( $r['type'] ) && $r['type'] === 'Guest' ) || ( isset( $r['style'] ) && $r['style'] === 'Guest' );

                    if ( $r_name === $norm_name && $r_is_guest === $entry_is_guest ) {
                        $matched_reg_id = $r_id;
                        break;
                    }
                }
            }

            // Pass 4: Fallback Name Match
            if ( ! $matched_reg_id ) {
                foreach ( $registrations as $r_id => $r ) {
                    if ( isset( $claimed_reg_ids[$r_id] ) ) continue;

                    $r_name = $normalize_str( isset( $r['name'] ) ? $r['name'] : '' );

                    if ( $r_name === $norm_name ) {
                        $matched_reg_id = $r_id;
                        break;
                    }
                }
            }

            if ( $matched_reg_id ) {
                // UPDATE EXISTING REGISTRATION RECORD
                $claimed_reg_ids[$matched_reg_id] = true;
                $reg = $registrations[$matched_reg_id];

                $reg['checked_in'] = 1;
                $reg['name']       = $entry['clean_name'];
                $reg['score']      = $entry['score'];
                $reg['x_count']    = $entry['xs'];

                if ( $entry_is_guest ) {
                    $reg['type'] = 'Guest';
                }

                if ( empty( $reg['division'] ) || $reg['division'] === '—' ) $reg['division'] = $entry['division'];
                if ( empty( $reg['gender'] )   || $reg['gender'] === '—' )   $reg['gender']   = $entry['gender'];
                if ( empty( $reg['style'] )    || $reg['style'] === '—' )    $reg['style']    = $final_style;

                $registrations[$matched_reg_id] = $reg;
                update_option( 'sywat_t_' . $t_slug . '_' . $matched_reg_id, $reg, false );
                $master_regs[$matched_reg_id] = $reg;

                $scores[$matched_reg_id] = array(
                    'score'    => $entry['score'],
                    'xs'       => $entry['xs'],
                    'saved_at' => current_time( 'mysql' ),
                );

                $stats_updated++;
            } else {
                // CREATE NEW REGISTRATION (Only if no match exists)
                $assigned_line_idx = $line_0_idx;
                $occupied = array();
                foreach ( $registrations as $r ) {
                    if ( $normalize_str( isset( $r['name'] ) ? $r['name'] : '' ) === $norm_name ) {
                        if ( isset( $r['line_index'] ) ) $occupied[] = (string)$r['line_index'];
                    }
                }

                if ( ! empty( $t_lines ) ) {
                    foreach ( $t_lines as $l_idx => $l_data ) {
                        if ( ! in_array( (string)$l_idx, $occupied, true ) ) {
                            $assigned_line_idx = (string)$l_idx;
                            break;
                        }
                    }
                }

                $line_name = isset( $t_lines[$assigned_line_idx]['name'] ) ? $t_lines[$assigned_line_idx]['name'] : 'Line ' . ( intval( $assigned_line_idx ) + 1 );
                $target_id = uniqid( 'reg_seq_' );
                $new_type  = $entry_is_guest ? 'Guest' : 'Walk-In';

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

                $registrations[$target_id]   = $new_reg;
                $claimed_reg_ids[$target_id] = true;

                update_option( 'sywat_t_' . $t_slug . '_' . $target_id, $new_reg, false );
                $master_regs[$target_id] = $new_reg;

                $scores[$target_id] = array(
                    'score'    => $entry['score'],
                    'xs'       => $entry['xs'],
                    'saved_at' => current_time( 'mysql' ),
                );

                $stats_created++;
            }

            if ( ! empty( $entry['gender'] ) ) {
                $gender_cache[$norm_name] = $entry['gender'];
            }
        }

        update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        update_option( 'sywat_scores_' . $t_slug, $scores, false );
        update_option( 'sywat_shooter_genders_cache', $gender_cache, false );

        return array(
            'success' => true,
            'count'   => ( $stats_updated + $stats_created ),
            'message' => "Successfully synced <strong>" . ( $stats_updated + $stats_created ) . "</strong> archers from TFAA! (Updated/verified {$stats_updated} existing records and created {$stats_created} new sequential records)."
        );
    }
}
