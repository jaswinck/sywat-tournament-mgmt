<?php
/**
 * Sub-Module: Results & Standings Data Access & Aggregation Engine
 * Path: includes/results/results-data.php
 * Description: Pure database retrieval and batch aggregation layer for historical shooter lookups, single-tournament score queries, and multi-tournament Season Standings (with single-query season batching, Guest-entry exclusion, and once-per-row normalized First+Last (+ suffix) archer deduplication).
 * Compliance: Pure data layer — zero HTML echo, zero presentation formatting, single-pass computational hoisting, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetches distinct historical archer profiles across Indoor and Outdoor cycles (18-Month Rolling Window).
 * Queries custom scores tables and tournament registration options, returning ONLY complete (Name, Division, Gender, Style) profiles.
 *
 * @return array List of shooter objects for JS localization.
 */
if ( ! function_exists( 'sywat_get_historical_shooters' ) ) {
    function sywat_get_historical_shooters() {
        global $wpdb;

        $shooters      = array();
        $seen_keys     = array();
        $has_div_res   = function_exists( 'sywat_results_resolve_division_record' );
        $has_style_res = function_exists( 'sywat_results_resolve_style_record' );

        // 1. Primary Query: Scores table (wp_sywat_scores) with 18-month rolling window
        $scores_table = $wpdb->prefix . 'sywat_scores';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $scores_table ) ) === $scores_table ) {
            $query = "
                SELECT 
                    TRIM(name) AS name,
                    TRIM(name) AS full_name,
                    TRIM(division) AS division,
                    TRIM(gender) AS gender,
                    TRIM(style) AS style,
                    MAX(phone) AS phone,
                    MAX(created_at) AS last_shot
                FROM {$scores_table}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 18 MONTH)
                  AND name IS NOT NULL AND name != ''
                  AND division IS NOT NULL AND division != '' AND division != 'No Show'
                  AND style IS NOT NULL AND style != '' AND style != 'No Show'
                GROUP BY LOWER(TRIM(name)), LOWER(TRIM(division)), LOWER(TRIM(gender)), LOWER(TRIM(style))
                ORDER BY last_shot DESC
            ";
            $db_results = $wpdb->get_results( $query, ARRAY_A );
            if ( is_array( $db_results ) ) {
                foreach ( $db_results as $r ) {
                    $div_rec   = $has_div_res ? sywat_results_resolve_division_record( $r['division'] ) : null;
                    $style_rec = $has_style_res ? sywat_results_resolve_style_record( $r['style'] ) : null;
                    $norm_div  = ( $div_rec && ! empty( $div_rec['label'] ) ) ? $div_rec['label'] : trim( $r['division'] );
                    $norm_sty  = ( $style_rec && ! empty( $style_rec['label'] ) ) ? $style_rec['label'] : trim( $r['style'] );
                    $norm_gen  = trim( $r['gender'] );

                    $key = strtolower( trim( $r['name'] ) . '___' . $norm_div . '___' . $norm_gen . '___' . $norm_sty );
                    if ( ! isset( $seen_keys[ $key ] ) ) {
                        $seen_keys[ $key ] = true;
                        $shooters[]        = array(
                            'name'      => trim( $r['name'] ),
                            'full_name' => trim( $r['full_name'] ),
                            'division'  => $norm_div,
                            'gender'    => $norm_gen,
                            'style'     => $norm_sty,
                            'phone'     => isset( $r['phone'] ) ? trim( $r['phone'] ) : '',
                        );
                    }
                }
            }
        }

        // 2. Secondary Query: Tournament options store (sywat_t_%)
        $opt_results = $wpdb->get_results(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%'"
        );

        if ( is_array( $opt_results ) ) {
            foreach ( $opt_results as $row ) {
                $reg = maybe_unserialize( $row->option_value );
                if ( is_array( $reg ) && ! empty( $reg['name'] ) ) {
                    $name     = trim( $reg['name'] );
                    $division = isset( $reg['division'] ) ? trim( $reg['division'] ) : '';
                    $gender   = isset( $reg['gender'] ) ? trim( $reg['gender'] ) : '';
                    $style    = isset( $reg['style'] ) ? trim( $reg['style'] ) : '';
                    $phone    = isset( $reg['phone'] ) ? trim( $reg['phone'] ) : '';

                    // Require complete profiles only (must have name, division, and style)
                    if ( empty( $name ) || empty( $style ) || empty( $division ) || strcasecmp( $division, 'No Show' ) === 0 || strcasecmp( $style, 'No Show' ) === 0 ) {
                        continue;
                    }

                    $div_rec   = $has_div_res ? sywat_results_resolve_division_record( $division ) : null;
                    $style_rec = $has_style_res ? sywat_results_resolve_style_record( $style ) : null;
                    $norm_div  = ( $div_rec && ! empty( $div_rec['label'] ) ) ? $div_rec['label'] : $division;
                    $norm_sty  = ( $style_rec && ! empty( $style_rec['label'] ) ) ? $style_rec['label'] : $style;

                    $key = strtolower( $name . '___' . $norm_div . '___' . $gender . '___' . $norm_sty );
                    if ( ! isset( $seen_keys[ $key ] ) ) {
                        $seen_keys[ $key ] = true;
                        $shooters[]        = array(
                            'name'      => $name,
                            'full_name' => $name,
                            'division'  => $norm_div,
                            'gender'    => $gender,
                            'style'     => $norm_sty,
                            'phone'     => $phone,
                        );
                    }
                }
            }
        }

        return $shooters;
    }
}

/**
 * Fetch all registered score records for a given tournament, normalizing division and style
 * labels via O(1) lookup maps, preserving registration 'type' (e.g., 'Guest'), and computing
 * hoisted "LastName, FirstName" display labels while preserving canonical 'name' keys.
 * Defaults blank/unset score fields to 0 so every roster entry is included.
 *
 * @param string $t_name Tournament title.
 * @return array         Normalized list of score entries for the tournament.
 */
if ( ! function_exists( 'sywat_get_tournament_scores' ) ) {
    function sywat_get_tournament_scores( $t_name ) {
        global $wpdb;

        $t_slug           = sanitize_title( $t_name );
        $scores           = array();
        $has_lf_formatter = function_exists( 'sywat_format_name_last_first' );
        $has_div_res      = function_exists( 'sywat_results_resolve_division_record' );
        $has_style_res    = function_exists( 'sywat_results_resolve_style_record' );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'sywat_t_' . $t_slug . '_' ) . '%'
        ) );

        if ( ! is_array( $results ) ) {
            return $scores;
        }

        foreach ( $results as $row ) {
            $reg = maybe_unserialize( $row->option_value );
            if ( is_array( $reg ) ) {
                $score_val = ( isset( $reg['score'] ) && $reg['score'] !== '' && $reg['score'] !== null ) ? intval( $reg['score'] ) : 0;
                $x_val     = ( isset( $reg['x_count'] ) && $reg['x_count'] !== '' && $reg['x_count'] !== null ) ? intval( $reg['x_count'] ) : 0;

                $raw_div  = isset( $reg['division'] ) && trim( (string) $reg['division'] ) !== '' ? trim( (string) $reg['division'] ) : 'Adult (18 - 49)';
                $div_rec  = $has_div_res ? sywat_results_resolve_division_record( $raw_div ) : null;
                $norm_div = ( $div_rec && ! empty( $div_rec['label'] ) ) ? $div_rec['label'] : $raw_div;

                $raw_style  = isset( $reg['style'] ) && trim( (string) $reg['style'] ) !== '' ? trim( (string) $reg['style'] ) : 'FS – Freestyle';
                $style_rec  = $has_style_res ? sywat_results_resolve_style_record( $raw_style ) : null;
                $norm_style = ( $style_rec && ! empty( $style_rec['label'] ) ) ? $style_rec['label'] : $raw_style;

                $raw_name  = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : 'Archer';
                $disp_name = $has_lf_formatter ? sywat_format_name_last_first( $raw_name ) : $raw_name;

                $scores[] = array(
                    'id'           => isset( $reg['id'] ) ? $reg['id'] : '',
                    'name'         => $raw_name,
                    'display_name' => $disp_name,
                    'tfaa_id'      => isset( $reg['tfaa_id'] ) ? trim( (string) $reg['tfaa_id'] ) : '',
                    'type'         => isset( $reg['type'] ) ? trim( (string) $reg['type'] ) : '',
                    'division'     => $norm_div,
                    'gender'       => isset( $reg['gender'] ) ? $reg['gender'] : 'Male',
                    'style'        => $norm_style,
                    'score'        => $score_val,
                    'x_count'      => $x_val,
                    'tournament'   => $t_name,
                );
            }
        }

        return $scores;
    }
}

/**
 * Aggregates season standings grouped by Division + Gender -> Style using a single
 * batched SQL query across all published tournaments in the season.
 * EXCLUDES all Guest entries (type === 'Guest', style === 'Guest', or '[Guest]' tags),
 * score-0 cards, and secondary re-entries entirely.
 * Computes normalized First+Last (+ suffix) deduplication keys once per score row,
 * enforces the 'scores_published' gate, and orders tied total scores & X-counts
 * alphabetically by "LastName, FirstName".
 *
 * @param string $season_slug Target season slug.
 * @return array              Associative array with 'season', 'best_n', and 'groups'.
 */
if ( ! function_exists( 'sywat_aggregate_season_standings' ) ) {
    function sywat_aggregate_season_standings( $season_slug ) {
        global $wpdb;

        $seasons          = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
        $season           = isset( $seasons[ $season_slug ] ) ? $seasons[ $season_slug ] : null;
        $best_n           = ( $season && isset( $season['best_n_count'] ) ) ? intval( $season['best_n_count'] ) : 3;
        $has_lf_formatter = function_exists( 'sywat_format_name_last_first' );
        $has_match_keys   = function_exists( 'sywat_normalize_archer_match_keys' );
        $has_div_res      = function_exists( 'sywat_results_resolve_division_record' );
        $has_style_res    = function_exists( 'sywat_results_resolve_style_record' );
        $has_heading_fn   = function_exists( 'sywat_format_div_gen_heading' );
        $has_dg_weight_fn = function_exists( 'sywat_get_div_gen_sort_weight' );
        $has_st_weight_fn = function_exists( 'sywat_get_style_sort_weight' );

        $build_archer_key = function( $tfaa_id, $raw_name ) use ( $has_match_keys ) {
            if ( $tfaa_id !== '' ) {
                return 'id_' . strtolower( $tfaa_id );
            }
            if ( $has_match_keys ) {
                $keys = sywat_normalize_archer_match_keys( $raw_name );
                if ( ! empty( $keys['fl'] ) ) {
                    return 'fl_' . $keys['fl'];
                }
                if ( ! empty( $keys['exact'] ) ) {
                    return 'ex_' . $keys['exact'];
                }
            }
            $fallback = strtolower( trim( preg_replace( '/[[:space:]]+/', ' ', str_replace( '.', ' ', $raw_name ) ) ) );
            return 'name_' . $fallback;
        };

        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $all_tournaments ) ) {
            $all_tournaments = array();
        }

        $matching_tournaments = array();
        $tourn_lookup_lower   = array();
        $prefix_to_tourn      = array();
        $like_clauses         = array();
        $like_params          = array();

        foreach ( $all_tournaments as $t_name => $t_data ) {
            $t_season     = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $t_data ) : '';
            $is_published = ! empty( $t_data['scores_published'] );

            if ( $t_season === $season_slug && $is_published ) {
                $t_slug                                        = sanitize_title( $t_name );
                $prefix                                        = 'sywat_t_' . $t_slug . '_';
                $matching_tournaments[ $t_name ]               = ( stripos( $t_name, 'state' ) !== false );
                $tourn_lookup_lower[ strtolower( trim( $t_name ) ) ] = $t_name;
                $prefix_to_tourn[ $prefix ]                    = $t_name;
                $like_clauses[]                                = 'option_name LIKE %s';
                $like_params[]                                 = $wpdb->esc_like( $prefix ) . '%';
            }
        }

        if ( empty( $matching_tournaments ) ) {
            return array(
                'season' => $season,
                'best_n' => $best_n,
                'groups' => array(),
            );
        }

        // Sort prefix map by length descending so longer tournament slugs match first
        uksort( $prefix_to_tourn, function( $a, $b ) {
            return strlen( $b ) <=> strlen( $a );
        } );

        // Single batched SQL query across all published season tournaments
        $sql     = "SELECT option_name, option_value FROM {$wpdb->options} WHERE " . implode( ' OR ', $like_clauses );
        $results = $wpdb->get_results( $wpdb->prepare( $sql, $like_params ) );

        $scores_by_tourn = array();
        if ( is_array( $results ) ) {
            foreach ( $results as $row ) {
                $reg = maybe_unserialize( $row->option_value );
                if ( ! is_array( $reg ) ) {
                    continue;
                }

                // Resolve owning tournament name
                $resolved_tourn = '';
                if ( ! empty( $reg['tournament'] ) ) {
                    $t_lower = strtolower( trim( (string) $reg['tournament'] ) );
                    if ( isset( $tourn_lookup_lower[ $t_lower ] ) ) {
                        $resolved_tourn = $tourn_lookup_lower[ $t_lower ];
                    }
                }
                if ( $resolved_tourn === '' && isset( $row->option_name ) ) {
                    $opt_name = (string) $row->option_name;
                    foreach ( $prefix_to_tourn as $pfx => $cand_tourn ) {
                        if ( strpos( $opt_name, $pfx ) === 0 ) {
                            $resolved_tourn = $cand_tourn;
                            break;
                        }
                    }
                }
                if ( $resolved_tourn === '' || ! isset( $matching_tournaments[ $resolved_tourn ] ) ) {
                    continue;
                }

                $score_val = ( isset( $reg['score'] ) && $reg['score'] !== '' && $reg['score'] !== null ) ? intval( $reg['score'] ) : 0;
                if ( $score_val <= 0 ) {
                    continue;
                }

                $raw_name  = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : 'Archer';
                $raw_style = isset( $reg['style'] ) && trim( (string) $reg['style'] ) !== '' ? trim( (string) $reg['style'] ) : 'FS – Freestyle';
                $raw_type  = isset( $reg['type'] ) ? trim( (string) $reg['type'] ) : '';

                // Exclude all Guest entries (including synced type === 'Guest' records)
                if ( strcasecmp( $raw_type, 'Guest' ) === 0 || strcasecmp( $raw_style, 'Guest' ) === 0 || stripos( $raw_name, '[guest]' ) !== false ) {
                    continue;
                }

                $x_val     = ( isset( $reg['x_count'] ) && $reg['x_count'] !== '' && $reg['x_count'] !== null ) ? intval( $reg['x_count'] ) : 0;
                $raw_div   = isset( $reg['division'] ) && trim( (string) $reg['division'] ) !== '' ? trim( (string) $reg['division'] ) : 'Adult (18 - 49)';
                $div_rec   = $has_div_res ? sywat_results_resolve_division_record( $raw_div ) : null;
                $norm_div  = ( $div_rec && ! empty( $div_rec['label'] ) ) ? $div_rec['label'] : $raw_div;
                $style_rec = $has_style_res ? sywat_results_resolve_style_record( $raw_style ) : null;
                $norm_sty  = ( $style_rec && ! empty( $style_rec['label'] ) ) ? $style_rec['label'] : $raw_style;

                if ( strcasecmp( $norm_sty, 'Guest' ) === 0 ) {
                    continue;
                }

                $tfaa_id    = isset( $reg['tfaa_id'] ) ? trim( (string) $reg['tfaa_id'] ) : '';
                $archer_key = $build_archer_key( $tfaa_id, $raw_name );
                $disp_name  = $has_lf_formatter ? sywat_format_name_last_first( $raw_name ) : $raw_name;

                $scores_by_tourn[ $resolved_tourn ][] = array(
                    'id'           => isset( $reg['id'] ) ? $reg['id'] : '',
                    'name'         => $raw_name,
                    'display_name' => $disp_name,
                    'tfaa_id'      => $tfaa_id,
                    'archer_key'   => $archer_key,
                    'type'         => $raw_type,
                    'division'     => $norm_div,
                    'gender'       => isset( $reg['gender'] ) ? $reg['gender'] : 'Male',
                    'style'        => $norm_sty,
                    'score'        => $score_val,
                    'x_count'      => $x_val,
                    'tournament'   => $resolved_tourn,
                );
            }
        }

        $groups = array();

        foreach ( $matching_tournaments as $t_name => $is_state_tourn ) {
            if ( empty( $scores_by_tourn[ $t_name ] ) ) {
                continue;
            }

            $grouped_per_tournament = array();
            foreach ( $scores_by_tourn[ $t_name ] as $s ) {
                $dgs_key = $s['division'] . '|||' . $s['gender'] . '|||' . $s['style'];
                $grouped_per_tournament[ $dgs_key ][ $s['archer_key'] ][] = $s;
            }

            $deduped_scores = array();
            foreach ( $grouped_per_tournament as $dgs_key => $archers_map ) {
                foreach ( $archers_map as $archer_key => $entries ) {
                    if ( count( $entries ) > 1 ) {
                        usort( $entries, function( $p, $q ) {
                            if ( $p['score'] === $q['score'] ) {
                                return $q['x_count'] - $p['x_count'];
                            }
                            return $q['score'] - $p['score'];
                        } );
                    }
                    $deduped_scores[] = $entries[0];
                }
            }

            foreach ( $deduped_scores as $s ) {
                $div_gen_key = $s['division'] . '|||' . $s['gender'];
                $style_key   = $s['style'];
                $archer_key  = $s['archer_key'];

                if ( ! isset( $groups[ $div_gen_key ] ) ) {
                    $groups[ $div_gen_key ] = array(
                        'division' => $s['division'],
                        'gender'   => $s['gender'],
                        'heading'  => $has_heading_fn ? sywat_format_div_gen_heading( $s['division'], $s['gender'] ) : trim( $s['division'] . ' ' . $s['gender'] ),
                        'styles'   => array(),
                    );
                }

                if ( ! isset( $groups[ $div_gen_key ]['styles'][ $style_key ] ) ) {
                    $groups[ $div_gen_key ]['styles'][ $style_key ] = array();
                }

                if ( ! isset( $groups[ $div_gen_key ]['styles'][ $style_key ][ $archer_key ] ) ) {
                    $groups[ $div_gen_key ]['styles'][ $style_key ][ $archer_key ] = array(
                        'name'             => $s['name'],
                        'display_name'     => $s['display_name'],
                        'tfaa_id'          => $s['tfaa_id'],
                        'division'         => $s['division'],
                        'gender'           => $s['gender'],
                        'style'            => $s['style'],
                        'qualifier_scores' => array(),
                        'state_score'      => null,
                    );
                }

                if ( $is_state_tourn ) {
                    $groups[ $div_gen_key ]['styles'][ $style_key ][ $archer_key ]['state_score'] = $s;
                } else {
                    $groups[ $div_gen_key ]['styles'][ $style_key ][ $archer_key ]['qualifier_scores'][] = $s;
                }
            }
        }

        uksort( $groups, function( $keyA, $keyB ) use ( $has_dg_weight_fn ) {
            list( $divA, $genA ) = explode( '|||', $keyA );
            list( $divB, $genB ) = explode( '|||', $keyB );

            $wA = $has_dg_weight_fn ? sywat_get_div_gen_sort_weight( $divA, $genA ) : 50;
            $wB = $has_dg_weight_fn ? sywat_get_div_gen_sort_weight( $divB, $genB ) : 50;

            if ( $wA !== $wB ) {
                return $wA - $wB;
            }
            return strcasecmp( $keyA, $keyB );
        } );

        foreach ( $groups as $dg_key => &$group ) {
            uksort( $group['styles'], function( $stA, $stB ) use ( $has_st_weight_fn ) {
                $wA = $has_st_weight_fn ? sywat_get_style_sort_weight( $stA ) : 50;
                $wB = $has_st_weight_fn ? sywat_get_style_sort_weight( $stB ) : 50;
                if ( $wA !== $wB ) {
                    return $wA - $wB;
                }
                return strcasecmp( $stA, $stB );
            } );

            foreach ( $group['styles'] as $st_key => &$archers ) {
                $processed = array();

                foreach ( $archers as $a_key => $a ) {
                    usort( $a['qualifier_scores'], function( $p, $q ) {
                        if ( $p['score'] === $q['score'] ) {
                            return $q['x_count'] - $p['x_count'];
                        }
                        return $q['score'] - $p['score'];
                    } );

                    $score_1 = isset( $a['qualifier_scores'][0] ) ? $a['qualifier_scores'][0] : null;
                    $score_2 = isset( $a['qualifier_scores'][1] ) ? $a['qualifier_scores'][1] : null;
                    $score_3 = isset( $a['qualifier_scores'][2] ) ? $a['qualifier_scores'][2] : null;
                    $state_s = $a['state_score'];

                    $total_score   = 0;
                    $total_x_count = 0;

                    $kept_list = array_filter( array( $score_1, $score_2, $score_3, $state_s ) );
                    foreach ( $kept_list as $ks ) {
                        $total_score   += $ks['score'];
                        $total_x_count += $ks['x_count'];
                    }

                    $processed[] = array(
                        'name'          => $a['name'],
                        'display_name'  => $a['display_name'],
                        'tfaa_id'       => $a['tfaa_id'],
                        'division'      => $a['division'],
                        'gender'        => $a['gender'],
                        'style'         => $a['style'],
                        'score_1'       => $score_1,
                        'score_2'       => $score_2,
                        'score_3'       => $score_3,
                        'state_score'   => $state_s,
                        'total_score'   => $total_score,
                        'total_x_count' => $total_x_count,
                    );
                }

                usort( $processed, function( $x, $y ) {
                    if ( $x['total_score'] === $y['total_score'] ) {
                        if ( $x['total_x_count'] === $y['total_x_count'] ) {
                            return strcasecmp( $x['display_name'], $y['display_name'] );
                        }
                        return $y['total_x_count'] - $x['total_x_count'];
                    }
                    return $y['total_score'] - $x['total_score'];
                } );

                $archers = $processed;
            }
            unset( $archers );
        }
        unset( $group );

        return array(
            'season' => $season,
            'best_n' => $best_n,
            'groups' => $groups,
        );
    }
}
