<?php
/**
 * Sub-Module: Historical TFAA Results Parser & Payload Helpers
 * Path: includes/history/history-helpers.php
 * Description: Pure parsing, normalization, and payload formatting helpers for migrating historical TFAA seasons, tournament manifests, and 1-day / 2-day / 3-day event scorecards (including Inside-Out "I/Os" tie-breaker counts) from texasfieldarchery.org into independent archived records.
 * Compliance: Pure helper layer — zero direct SQL queries, zero get_option()/update_option() calls, zero remote HTTP requests, zero HTML echo, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parse the TFAA Season <select> dropdown HTML into normalized archived season payloads.
 * Filters out placeholder options ("Select...") and test seasons ("TEST", "I-O TEST 1970").
 *
 * @param string $html Raw HTML from https://texasfieldarchery.org/results/season/{id}.
 * @return array       List of normalized season payload arrays sorted chronologically descending.
 */
if ( ! function_exists( 'sywat_history_parse_season_dropdown' ) ) {
    function sywat_history_parse_season_dropdown( $html ) {
        $html = trim( (string) $html );
        if ( $html === '' ) {
            return array();
        }

        $decoded = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $seasons = array();
        $seen    = array();

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $decoded );
        $xpath   = new DOMXPath( $dom );
        $options = $xpath->query( '//select//option' );

        if ( $options && $options->length > 0 ) {
            foreach ( $options as $opt ) {
                $raw_val   = trim( (string) $opt->getAttribute( 'value' ) );
                $raw_label = trim( preg_replace( '/\s+/u', ' ', (string) $opt->textContent ) );

                $season_id = 0;
                if ( is_numeric( $raw_val ) ) {
                    $season_id = intval( $raw_val );
                } elseif ( preg_match( '/\/results\/season\/(\d+)/i', $raw_val, $m ) ) {
                    $season_id = intval( $m[1] );
                }

                if ( $season_id <= 0 || $raw_label === '' ) {
                    continue;
                }

                if ( strcasecmp( $raw_label, 'Select...' ) === 0 || preg_match( '/\btest\b/i', $raw_label ) ) {
                    continue;
                }

                if ( isset( $seen[ $season_id ] ) ) {
                    continue;
                }
                $seen[ $season_id ] = true;

                $seasons[] = sywat_history_build_season_payload( $season_id, $raw_label );
            }
        }
        libxml_clear_errors();

        usort( $seasons, function( $a, $b ) {
            $a_date = isset( $a['start_date'] ) ? $a['start_date'] : '';
            $b_date = isset( $b['start_date'] ) ? $b['start_date'] : '';
            if ( $a_date !== $b_date ) {
                return strcmp( $b_date, $a_date );
            }
            return intval( $b['season_id'] ) <=> intval( $a['season_id'] );
        } );

        return $seasons;
    }
}

/**
 * Build a canonical archived season configuration payload from a TFAA season ID and label.
 *
 * @param int    $season_id Numeric TFAA season ID.
 * @param string $raw_label Raw TFAA season label (e.g. "2010-2011 Indoor", "2011 Outdoor", "Field 50 - 2026").
 * @return array            Canonical season descriptor array.
 */
if ( ! function_exists( 'sywat_history_build_season_payload' ) ) {
    function sywat_history_build_season_payload( $season_id, $raw_label ) {
        $season_id  = intval( $season_id );
        $clean_name = trim( preg_replace( '/\s+/u', ' ', (string) $raw_label ) );

        $type = 'Indoor';
        if ( stripos( $clean_name, 'field 50' ) !== false ) {
            $type = 'Field 50';
        } elseif ( stripos( $clean_name, 'outdoor' ) !== false ) {
            $type = 'Outdoor';
        }

        $years = array();
        if ( preg_match_all( '/\b(19\d{2}|20\d{2})\b/', $clean_name, $ym ) && ! empty( $ym[1] ) ) {
            $years = array_map( 'intval', $ym[1] );
        }

        $start_year = ! empty( $years ) ? $years[0] : 2010;
        $end_year   = ( count( $years ) >= 2 ) ? $years[1] : ( $type === 'Indoor' ? ( $start_year + 1 ) : $start_year );

        if ( $type === 'Indoor' ) {
            $start_date = sprintf( '%04d-10-01', $start_year );
            $end_date   = sprintf( '%04d-03-31', $end_year );
            $year_label = ( $start_year !== $end_year ) ? ( $start_year . '–' . $end_year ) : (string) $start_year;
        } else {
            $start_date = sprintf( '%04d-04-01', $start_year );
            $end_date   = sprintf( '%04d-08-31', $end_year );
            $year_label = ( $start_year !== $end_year ) ? ( $start_year . '–' . $end_year ) : (string) $start_year;
        }

        $slug_base = sanitize_title( $clean_name );
        if ( $slug_base === '' ) {
            $slug_base = 'season-' . $season_id;
        }

        // Match existing plugin season slug conventions (e.g. "indoor-2010-2011", "outdoor-2011")
        if ( preg_match( '/^(\d{4}(?:-\d{4})?)-(indoor|outdoor)$/i', $slug_base, $sm ) ) {
            $slug = strtolower( $sm[2] . '-' . $sm[1] );
        } else {
            $slug = $slug_base;
        }

        return array(
            'season_id'    => $season_id,
            'season_url'   => 'https://texasfieldarchery.org/results/season/' . $season_id,
            'slug'         => $slug,
            'name'         => $clean_name,
            'type'         => $type,
            'start_date'   => $start_date,
            'end_date'     => $end_date,
            'year_label'   => $year_label,
            'status'       => 'archived',
            'best_n_count' => 3,
        );
    }
}

/**
 * Parse a TFAA season page (/results/season/{id}) HTML table into normalized tournament descriptors.
 * Resolves fallback dates for legacy "00/00/0000" rows and disambiguates recurring event titles
 * across seasons (e.g. "Red River I (2010-2011 Indoor)") so tournament keys never collide.
 *
 * @param string $html           Raw HTML from https://texasfieldarchery.org/results/season/{id}.
 * @param array  $season_payload Canonical season descriptor from sywat_history_build_season_payload().
 * @return array                 List of normalized tournament manifest items.
 */
if ( ! function_exists( 'sywat_history_parse_season_events_html' ) ) {
    function sywat_history_parse_season_events_html( $html, $season_payload ) {
        $html = trim( (string) $html );
        if ( $html === '' || ! is_array( $season_payload ) ) {
            return array();
        }

        $decoded     = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $season_name = isset( $season_payload['name'] ) ? trim( (string) $season_payload['name'] ) : '';
        $season_slug = isset( $season_payload['slug'] ) ? trim( (string) $season_payload['slug'] ) : '';
        $base_start  = ! empty( $season_payload['start_date'] ) ? $season_payload['start_date'] : '2010-10-01';
        $base_end    = ! empty( $season_payload['end_date'] ) ? $season_payload['end_date'] : '2011-03-31';

        $season_years = array();
        if ( preg_match_all( '/\b(19\d{2}|20\d{2})\b/', $season_name, $ym ) && ! empty( $ym[1] ) ) {
            $season_years = $ym[1];
        }

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $decoded );
        $xpath = new DOMXPath( $dom );

        $raw_items = array();
        $seen_ids  = array();
        $rows      = $xpath->query( '//table//tr' );

        if ( $rows && $rows->length > 0 ) {
            foreach ( $rows as $row ) {
                $links = $xpath->query( './/a[@href]', $row );
                if ( ! $links || $links->length === 0 ) {
                    continue;
                }

                $matched_link = null;
                $event_id     = 0;

                foreach ( $links as $lnk ) {
                    $href = trim( (string) $lnk->getAttribute( 'href' ) );
                    if ( preg_match( '/\/results\/(\d+)(?:\/)?(?:\?.*)?$/i', $href, $em ) ) {
                        $event_id     = intval( $em[1] );
                        $matched_link = $lnk;
                        break;
                    }
                }

                if ( $event_id <= 0 || ! $matched_link || isset( $seen_ids[ $event_id ] ) ) {
                    continue;
                }

                $raw_title = trim( preg_replace( '/\s+/u', ' ', (string) $matched_link->textContent ) );
                if ( $raw_title === '' || preg_match( '/\btest\b/i', $raw_title ) ) {
                    continue;
                }

                $cells    = $xpath->query( './/td', $row );
                $raw_date = ( $cells && $cells->length >= 1 )
                    ? trim( preg_replace( '/\s+/u', ' ', (string) $cells->item( 0 )->textContent ) )
                    : '';

                $seen_ids[ $event_id ] = true;
                $raw_items[]           = array(
                    'event_id'  => $event_id,
                    'raw_title' => $raw_title,
                    'raw_date'  => $raw_date,
                );
            }
        }
        libxml_clear_errors();

        $events      = array();
        $seen_titles = array();
        $base_ts     = strtotime( $base_start . ' 12:00:00' );
        $end_ts      = strtotime( $base_end . ' 12:00:00' );

        foreach ( $raw_items as $idx => $item ) {
            $raw_title = $item['raw_title'];
            $raw_date  = $item['raw_date'];
            $event_id  = $item['event_id'];

            // Parse explicit date if valid, otherwise derive deterministic sequential fallback within season
            $parsed_ymd = sywat_history_parse_date_to_ymd( $raw_date );
            if ( $parsed_ymd === '' ) {
                $offset_ts  = $base_ts + ( $idx * 3 * DAY_IN_SECONDS );
                $clamped_ts = ( $end_ts > $base_ts && $offset_ts > $end_ts ) ? $end_ts : $offset_ts;
                $parsed_ymd = gmdate( 'Y-m-d', $clamped_ts );
            }

            // Append season context if title does not already include a season year
            $has_year = false;
            foreach ( $season_years as $yr ) {
                if ( strpos( $raw_title, $yr ) !== false ) {
                    $has_year = true;
                    break;
                }
            }

            $canonical_title = ( ! $has_year && $season_name !== '' )
                ? ( $raw_title . ' (' . $season_name . ')' )
                : $raw_title;

            // Ensure uniqueness if identical title appears twice in the same season
            $title_key = strtolower( $canonical_title );
            if ( isset( $seen_titles[ $title_key ] ) ) {
                $seen_titles[ $title_key ]++;
                $canonical_title .= ' #' . $seen_titles[ $title_key ];
            } else {
                $seen_titles[ $title_key ] = 1;
            }

            $is_state = ( stripos( $raw_title, 'state' ) !== false );

            $events[] = array(
                'event_id'    => $event_id,
                'event_url'   => 'https://texasfieldarchery.org/results/' . $event_id,
                'raw_title'   => $raw_title,
                'title'       => $canonical_title,
                'season_slug' => $season_slug,
                'season_name' => $season_name,
                'start_date'  => $parsed_ymd,
                'end_date'    => $parsed_ymd,
                'is_state'    => $is_state,
                'event_type'  => $is_state ? 'TFAA State' : 'SYWAT',
            );
        }

        return $events;
    }
}

/**
 * Convert a raw TFAA date string (e.g. "01/15/2023", "Jan 15, 2023", "2023-01-15") to Y-m-d.
 * Returns empty string for placeholder "00/00/0000" or unparseable values.
 *
 * @param string $raw_date Raw date string from TFAA table.
 * @return string          Normalized "YYYY-MM-DD" date or empty string.
 */
if ( ! function_exists( 'sywat_history_parse_date_to_ymd' ) ) {
    function sywat_history_parse_date_to_ymd( $raw_date ) {
        $clean = trim( (string) $raw_date );
        if ( $clean === '' || strpos( $clean, '00/00' ) !== false || strpos( $clean, '0000' ) !== false ) {
            return '';
        }

        if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(19\d{2}|20\d{2})/', $clean, $m ) ) {
            $month = intval( $m[1] );
            $day   = intval( $m[2] );
            $year  = intval( $m[3] );
            if ( checkdate( $month, $day, $year ) ) {
                return sprintf( '%04d-%02d-%02d', $year, $month, $day );
            }
        }

        if ( preg_match( '/^(19\d{2}|20\d{2})-(\d{2})-(\d{2})/', $clean, $m ) ) {
            $year  = intval( $m[1] );
            $month = intval( $m[2] );
            $day   = intval( $m[3] );
            if ( checkdate( $month, $day, $year ) ) {
                return sprintf( '%04d-%02d-%02d', $year, $month, $day );
            }
        }

        $ts = strtotime( $clean );
        if ( $ts !== false ) {
            $yr = intval( gmdate( 'Y', $ts ) );
            if ( $yr >= 1990 && $yr <= 2099 ) {
                return gmdate( 'Y-m-d', $ts );
            }
        }

        return '';
    }
}

/**
 * Strip trailing uppercase TFAA class codes (e.g. "CMFS", "CMBHFS", "AMFS", "YAMFSLR", "SSMTRAD")
 * extracted from multi-day State Championship table rows so only the archer's name remains.
 *
 * @param string $raw_name Archer name string prior to or after comma splitting.
 * @return string          Cleaned archer name without trailing TFAA class code.
 */
if ( ! function_exists( 'sywat_history_strip_tfaa_class_code' ) ) {
    function sywat_history_strip_tfaa_class_code( $raw_name ) {
        $clean = trim( preg_replace( '/\s+/u', ' ', (string) $raw_name ) );
        if ( $clean === '' ) {
            return '';
        }

        $clean = preg_replace(
            '/\s+(?:MS|SS|YA|C|Y|A|S|P)[MF](?:BHFS|FSL-R|FSLR|FSL|FSPRO|FS|MBH|BBC|BBR|BB|TRAD|LB|GEN)$/',
            '',
            $clean
        );

        return trim( (string) $clean );
    }
}

/**
 * Parse an individual TFAA event scorecard page (/results/{id}) into normalized archer score rows.
 * Supports 1-day ("Score Xs [Total TotalX] [I/Os]"), 2-day ("Score Xs Score Xs Total TotalX [I/Os]"),
 * and 3-day ("Score Xs Score Xs Score Xs Total TotalX [I/Os]") tables, storing per-day round
 * scores alongside combined totals and Inside-Out ("I/Os") tie-breaker counts while stripping
 * TFAA class codes from names.
 *
 * @param string $html Raw HTML from https://texasfieldarchery.org/results/{id}.
 * @return array       Associative array with 'extracted_start_date', 'extracted_end_date', and 'entries'.
 */
if ( ! function_exists( 'sywat_history_parse_event_scorecard_html' ) ) {
    function sywat_history_parse_event_scorecard_html( $html ) {
        $html = (string) $html;
        if ( trim( $html ) === '' ) {
            return array(
                'extracted_start_date' => '',
                'extracted_end_date'   => '',
                'entries'              => array(),
            );
        }

        $flat_body = str_replace( array( "\r", "\n" ), ' ', $html );
        if ( preg_match( '/\b0\s+Entries\b/i', $flat_body ) ) {
            return array(
                'extracted_start_date' => '',
                'extracted_end_date'   => '',
                'entries'              => array(),
            );
        }

        // Attempt to extract explicit event dates from the scorecard header (e.g., "01/14/2023 - 01/15/2023")
        $extracted_start = '';
        $extracted_end   = '';
        if ( preg_match( '/(\d{1,2}\/\d{1,2}\/(?:19|20)\d{2})\s*(?:-|–|to)\s*(\d{1,2}\/\d{1,2}\/(?:19|20)\d{2})/i', $flat_body, $dm ) ) {
            $extracted_start = sywat_history_parse_date_to_ymd( $dm[1] );
            $extracted_end   = sywat_history_parse_date_to_ymd( $dm[2] );
        } elseif ( preg_match( '/\b(\d{1,2}\/\d{1,2}\/(?:19|20)\d{2})\b/', $flat_body, $dm ) ) {
            $extracted_start = sywat_history_parse_date_to_ymd( $dm[1] );
            $extracted_end   = $extracted_start;
        }

        $spaced_body = str_replace( '>', '> ', $flat_body );
        $spaced_body = str_replace( '<', ' <', $spaced_body );
        $clean_html  = preg_replace( '/<\/?(?:tr|h[1-6]|div|p|br|li|ul|table)[^>]*>/is', "\n", $spaced_body );
        $plain_text  = wp_strip_all_tags( $clean_html );
        $raw_lines   = explode( "\n", $plain_text );

        $has_div_fn     = function_exists( 'sywat_parse_tfaa_div_gen' );
        $has_style_fn   = function_exists( 'sywat_parse_tfaa_style' );
        $has_div_dist   = function_exists( 'sywat_shooting_styles_get_division_distance' );
        $has_style_find = function_exists( 'sywat_find_shooting_style' );

        $entries      = array();
        $curr_div     = '';
        $curr_gen     = 'Male';
        $curr_style   = '';
        $curr_is_pro  = false;
        $event_has_io = false;

        foreach ( $raw_lines as $raw_line ) {
            $decoded = html_entity_decode( $raw_line, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $decoded = str_replace( "\xC2\xA0", ' ', $decoded );
            $line    = trim( preg_replace( '/\s+/u', ' ', $decoded ) );

            if ( $line === '' || stripos( $line, 'Filters:' ) !== false || stripos( $line, 'Search' ) !== false || stripos( $line, 'Entries' ) !== false ) {
                continue;
            }

            // 1. Archer Score Row (1-day: "1 Coleman, Chris 300 58 2" | 2-day: "1 Winckler, Jason 300 59 300 60 600 119 37")
            if ( preg_match( '/^(\d+)\s+([^,]+,\s*[^0-9]+?)(?=\s+\d|$)/', $line, $matches ) ) {
                if ( $curr_div === '' || $curr_style === '' ) {
                    continue;
                }

                $raw_name = trim( $matches[2] );
                $is_guest = ( stripos( $raw_name, '[GUEST]' ) !== false || stripos( $line, '[GUEST]' ) !== false || $curr_style === 'Guest' );
                $is_inc   = ( stripos( $raw_name, '[INC]' ) !== false || stripos( $line, '[INC]' ) !== false );

                $clean_raw_name = trim( preg_replace( '/\[.*?\]/', '', $raw_name ) );
                $clean_raw_name = sywat_history_strip_tfaa_class_code( $clean_raw_name );
                $parts          = explode( ',', $clean_raw_name, 2 );
                $clean_name     = ( count( $parts ) === 2 )
                    ? trim( preg_replace( '/\s+/u', ' ', trim( $parts[1] ) . ' ' . trim( $parts[0] ) ) )
                    : trim( $clean_raw_name );

                if ( $clean_name === '' ) {
                    continue;
                }

                $rest        = trim( substr( $line, strlen( $matches[0] ) ) );
                $score_parts = $rest !== '' ? array_values( array_filter( explode( ' ', $rest ), 'is_numeric' ) ) : array();
                $num_cols    = count( $score_parts );

                $round_days = 1;
                $r1_score   = isset( $score_parts[0] ) ? intval( $score_parts[0] ) : 0;
                $r1_xs      = isset( $score_parts[1] ) ? intval( $score_parts[1] ) : 0;
                $r2_score   = 0;
                $r2_xs      = 0;
                $r3_score   = 0;
                $r3_xs      = 0;
                $score      = $r1_score;
                $xs         = $r1_xs;
                $io_count   = 0;

                if ( $num_cols >= 8 ) {
                    // 3-day tournament: Score1 Xs1 Score2 Xs2 Score3 Xs3 Total TotalX [I/Os]
                    $round_days = 3;
                    $r2_score   = intval( $score_parts[2] );
                    $r2_xs      = intval( $score_parts[3] );
                    $r3_score   = intval( $score_parts[4] );
                    $r3_xs      = intval( $score_parts[5] );
                    $tot_s      = intval( $score_parts[6] );
                    $tot_x      = intval( $score_parts[7] );
                    $score      = ( $tot_s > 0 ) ? $tot_s : ( $r1_score + $r2_score + $r3_score );
                    $xs         = ( $tot_s > 0 || $tot_x > 0 ) ? $tot_x : ( $r1_xs + $r2_xs + $r3_xs );
                    if ( $num_cols >= 9 ) {
                        $io_count = max( 0, intval( $score_parts[8] ) );
                    }
                } elseif ( $num_cols >= 6 ) {
                    // 2-day tournament: Score1 Xs1 Score2 Xs2 Total TotalX [I/Os]
                    $round_days = 2;
                    $r2_score   = intval( $score_parts[2] );
                    $r2_xs      = intval( $score_parts[3] );
                    $tot_s      = intval( $score_parts[4] );
                    $tot_x      = intval( $score_parts[5] );
                    $score      = ( $tot_s > 0 ) ? $tot_s : ( $r1_score + $r2_score );
                    $xs         = ( $tot_s > 0 || $tot_x > 0 ) ? $tot_x : ( $r1_xs + $r2_xs );
                    if ( $num_cols >= 7 ) {
                        $io_count = max( 0, intval( $score_parts[6] ) );
                    }
                } else {
                    // 1-day tournament: Score1 Xs1 [I/Os] or Score1 Xs1 Total TotalX I/Os
                    if ( $num_cols === 3 ) {
                        $io_count = max( 0, intval( $score_parts[2] ) );
                    } elseif ( $num_cols === 5 ) {
                        $io_count = max( 0, intval( $score_parts[4] ) );
                    }
                }

                if ( $io_count > 0 ) {
                    $event_has_io = true;
                }

                $dist = $has_div_dist
                    ? sywat_shooting_styles_get_division_distance( $curr_div )
                    : ( ( stripos( $curr_div, 'Cub' ) !== false ) ? 'Cub' : '20yd' );

                $entries[] = array(
                    'clean_name' => $clean_name,
                    'division'   => $curr_div,
                    'gender'     => $curr_gen,
                    'style'      => $curr_style,
                    'distance'   => $dist,
                    'round_days' => $round_days,
                    'r1_score'   => $r1_score,
                    'r1_xs'      => $r1_xs,
                    'r2_score'   => $r2_score,
                    'r2_xs'      => $r2_xs,
                    'r3_score'   => $r3_score,
                    'r3_xs'      => $r3_xs,
                    'score'      => $score,
                    'xs'         => $xs,
                    'io_count'   => $io_count,
                    'has_io'     => 0,
                    'is_guest'   => $is_guest,
                    'is_inc'     => $is_inc,
                );
                continue;
            }

            // 2. Division / Gender Heading
            $div_check = $has_div_fn ? sywat_parse_tfaa_div_gen( $line ) : array( 'division' => '', 'gender' => '' );
            if ( ! empty( $div_check['division'] ) ) {
                $curr_div    = $div_check['division'];
                $curr_gen    = $div_check['gender'];
                $curr_is_pro = (bool) preg_match( '/\bpro\b/i', $line );
                continue;
            }

            // 3. Shooting Style Heading
            $style_check = $has_style_fn ? sywat_parse_tfaa_style( $line ) : '';
            if ( $style_check !== '' ) {
                $curr_style = $style_check;
                $line_pro   = $curr_is_pro || (bool) preg_match( '/\bpro\b/i', $line );

                if ( $line_pro && ( stripos( $curr_style, 'FS –' ) === 0 || strcasecmp( $curr_style, 'FS' ) === 0 ) ) {
                    if ( $has_style_find ) {
                        $pro_rec    = sywat_find_shooting_style( 'FS Pro' );
                        $curr_style = ( is_array( $pro_rec ) && ! empty( $pro_rec['label'] ) ) ? $pro_rec['label'] : 'FS Pro – Freestyle Pro';
                    } else {
                        $curr_style = 'FS Pro – Freestyle Pro';
                    }
                }
            }
        }

        if ( $event_has_io ) {
            foreach ( $entries as &$entry_ref ) {
                $entry_ref['has_io'] = 1;
            }
            unset( $entry_ref );
        }

        return array(
            'extracted_start_date' => $extracted_start,
            'extracted_end_date'   => $extracted_end,
            'entries'              => $entries,
        );
    }
}

/**
 * Build a canonical tournament configuration record for sywat_tournaments_list.
 * Marks the tournament as published for [sywat_results] while disabling online registration.
 *
 * @param array  $event_item    Normalized tournament manifest item.
 * @param array  $season_record Canonical season record.
 * @param string $start_date    Resolved YYYY-MM-DD start date.
 * @param string $end_date      Resolved YYYY-MM-DD end date.
 * @return array                Canonical tournament configuration array.
 */
if ( ! function_exists( 'sywat_history_build_tournament_record' ) ) {
    function sywat_history_build_tournament_record( $event_item, $season_record, $start_date, $end_date ) {
        $t_name      = isset( $event_item['title'] ) ? trim( (string) $event_item['title'] ) : 'Historical Event';
        $season_slug = isset( $season_record['slug'] ) ? trim( (string) $season_record['slug'] ) : '';
        $event_id    = isset( $event_item['event_id'] ) ? intval( $event_item['event_id'] ) : 0;
        $event_url   = isset( $event_item['event_url'] ) ? trim( (string) $event_item['event_url'] ) : '';
        $event_type  = isset( $event_item['event_type'] ) ? trim( (string) $event_item['event_type'] ) : 'SYWAT';

        return array(
            'name'               => $t_name,
            'season'             => $season_slug,
            'season_slug'        => $season_slug,
            'event_type'         => $event_type,
            'start_date'         => $start_date,
            'end_date'           => $end_date,
            'scores_published'   => 1,
            'public_reg_enabled' => 0,
            'tfaa_event_id'      => (string) $event_id,
            'tfaa_results_url'   => $event_url,
            'historical_import'  => 1,
            'lines'              => array(
                array(
                    'name'     => 'Line 1',
                    'cap_20yd' => 250,
                    'cap_cub'  => 50,
                ),
            ),
        );
    }
}

/**
 * Build deterministic, independent historical registration & score records for an event.
 * Uses deterministic IDs ("hist_{event_id}_{row}") so re-importing an event overwrites in place
 * without duplicating entries or linking to live wp_users / Member Directory accounts, while
 * storing per-day round breakdowns (r1/r2/r3) and Inside-Out ("io_count") tie-breakers
 * alongside combined totals.
 *
 * @param string $t_name         Canonical tournament title.
 * @param int    $event_id       Numeric TFAA event ID.
 * @param array  $parsed_entries List of parsed archer entries from sywat_history_parse_event_scorecard_html().
 * @param string $created_at     MySQL datetime timestamp string.
 * @return array                 Associative array with 'registrations' and 'scores' maps keyed by reg ID.
 */
if ( ! function_exists( 'sywat_history_build_score_records' ) ) {
    function sywat_history_build_score_records( $t_name, $event_id, $parsed_entries, $created_at ) {
        $registrations = array();
        $scores        = array();
        $primary_style = array();
        $has_div_tier  = function_exists( 'sywat_shooting_styles_get_division_rate_tier' );
        $event_id      = intval( $event_id );

        if ( ! is_array( $parsed_entries ) ) {
            return array(
                'registrations' => $registrations,
                'scores'        => $scores,
            );
        }

        foreach ( $parsed_entries as $idx => $entry ) {
            $clean_name = isset( $entry['clean_name'] ) ? trim( (string) $entry['clean_name'] ) : '';
            if ( $clean_name === '' ) {
                continue;
            }

            $norm_name = strtolower( $clean_name );
            $is_guest  = ! empty( $entry['is_guest'] ) || ( isset( $entry['style'] ) && $entry['style'] === 'Guest' );
            $is_inc    = ! empty( $entry['is_inc'] ) ? 1 : 0;

            if ( ! $is_guest && ! $is_inc && empty( $primary_style[ $norm_name ] ) ) {
                $primary_style[ $norm_name ] = $entry['style'];
            }

            $final_style = $entry['style'];
            if ( $is_guest && $final_style === 'Guest' && ! empty( $primary_style[ $norm_name ] ) ) {
                $final_style = $primary_style[ $norm_name ];
            }

            $reg_id     = 'hist_' . $event_id . '_' . ( intval( $idx ) + 1 );
            $round_days = isset( $entry['round_days'] ) ? max( 1, intval( $entry['round_days'] ) ) : 1;
            $tot_score  = intval( $entry['score'] );
            $tot_xs     = intval( $entry['xs'] );
            $io_count   = isset( $entry['io_count'] ) ? max( 0, intval( $entry['io_count'] ) ) : 0;
            $has_io     = ! empty( $entry['has_io'] ) ? 1 : 0;
            $r1_score   = isset( $entry['r1_score'] ) ? intval( $entry['r1_score'] ) : $tot_score;
            $r1_xs      = isset( $entry['r1_xs'] ) ? intval( $entry['r1_xs'] ) : $tot_xs;
            $r2_score   = isset( $entry['r2_score'] ) ? intval( $entry['r2_score'] ) : 0;
            $r2_xs      = isset( $entry['r2_xs'] ) ? intval( $entry['r2_xs'] ) : 0;
            $r3_score   = isset( $entry['r3_score'] ) ? intval( $entry['r3_score'] ) : 0;
            $r3_xs      = isset( $entry['r3_xs'] ) ? intval( $entry['r3_xs'] ) : 0;

            $paid_tier = $has_div_tier
                ? sywat_shooting_styles_get_division_rate_tier( $entry['division'] )
                : ( in_array( $entry['division'], array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' ), true ) ? 'Youth' : 'Adult' );

            $registrations[ $reg_id ] = array(
                'id'                => $reg_id,
                'tournament'        => $t_name,
                'line_index'        => '0',
                'line_name'         => 'Line 1',
                'name'              => $clean_name,
                'phone'             => '',
                'distance'          => isset( $entry['distance'] ) ? $entry['distance'] : '20yd',
                'type'              => $is_guest ? 'Guest' : 'Walk-In',
                'division'          => $entry['division'],
                'gender'            => $entry['gender'],
                'style'             => $final_style,
                'paid'              => $paid_tier,
                'balance_due'       => 0.00,
                'status'            => 'Pre-Reg',
                'checked_in'        => 1,
                'round_days'        => $round_days,
                'r1_score'          => $r1_score,
                'r1_xs'             => $r1_xs,
                'r2_score'          => $r2_score,
                'r2_xs'             => $r2_xs,
                'r3_score'          => $r3_score,
                'r3_xs'             => $r3_xs,
                'score'             => $tot_score,
                'x_count'           => $tot_xs,
                'io_count'          => $io_count,
                'has_io'            => $has_io,
                'is_inc'            => $is_inc,
                'tfaa_origin'       => 1,
                'historical_import' => 1,
                'created_at'        => $created_at,
            );

            $scores[ $reg_id ] = array(
                'round_days' => $round_days,
                'r1_score'   => $r1_score,
                'r1_xs'      => $r1_xs,
                'r2_score'   => $r2_score,
                'r2_xs'      => $r2_xs,
                'r3_score'   => $r3_score,
                'r3_xs'      => $r3_xs,
                'score'      => $tot_score,
                'xs'         => $tot_xs,
                'io_count'   => $io_count,
                'has_io'     => $has_io,
                'is_inc'     => $is_inc,
                'saved_at'   => $created_at,
            );
        }

        return array(
            'registrations' => $registrations,
            'scores'        => $scores,
        );
    }
}
