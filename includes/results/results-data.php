<?php
/**
 * Sub-Module: Results & Standings Data Access & Aggregation Engine
 * Path: includes/results/results-data.php
 * Description: Pure database retrieval and batch aggregation layer for historical shooter lookups, single-tournament score queries, and multi-tournament Season Standings (with single-query season batching, Guest-entry exclusion, unified tfaa_id <-> normalized First+Last (+ suffix) deduplication, given-name alias merging via sywat_are_given_names_compatible(), and surname tie-breaker sorting).
 * Compliance: Pure data layer — zero HTML echo, zero presentation formatting, single-pass computational hoisting, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.1.0
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
 * Unifies tfaa_id and normalized First+Last (+ suffix) keys so identical archers with
 * or without tfaa_id metadata merge into a single row, merges given-name alias variants
 * (e.g., "Chris Clark" <-> "Christopher Clark") across separate tournaments within the
 * same category via sywat_are_given_names_compatible(), enforces 'scores_published',
 * and orders tied total scores & X-counts alphabetically by "LastName, FirstName".
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
        $has_alias_fn     = function_exists( 'sywat_are_given_names_compatible' );
        $has_div_res      = function_exists( 'sywat_results_resolve_division_record' );
        $has_style_res    = function_exists( 'sywat_results_resolve_style_record' );
        $has_heading_fn   = function_exists( 'sywat_format_div_gen_heading' );
        $has_dg_weight_fn = function_exists( 'sywat_get_div_gen_sort_weight' );
        $has_st_weight_fn = function_exists( 'sywat_get_style_sort_weight' );

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
                $t_slug                                             = sanitize_title( $t_name );
                $prefix                                             = 'sywat_t_' . $t_slug . '_';
                $matching_tournaments[ $t_name ]                    = ( stripos( $t_name, 'state' ) !== false );
                $tourn_lookup_lower[ strtolower( trim( $t_name ) ) ] = $t_name;
                $prefix_to_tourn[ $prefix ]                         = $t_name;
                $like_clauses[]                                     = 'option_name LIKE %s';
                $like_params[]                                      = $wpdb->esc_like( $prefix ) . '%';
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

        $raw_parsed_rows = array();
        $fl_to_ids       = array();
        $id_to_best_fl   = array();
        $id_to_best_len  = array();

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

                $name_keys = $has_match_keys
                    ? sywat_normalize_archer_match_keys( $raw_name )
                    : array(
                        'exact'       => strtolower( trim( preg_replace( '/[[:space:]]+/', ' ', str_replace( '.', ' ', $raw_name ) ) ) ),
                        'fl'          => strtolower( trim( preg_replace( '/[[:space:]]+/', ' ', str_replace( '.', ' ', $raw_name ) ) ) ),
                        'first'       => '',
                        'last'        => '',
                        'suffix'      => '',
                        'token_count' => 1,
                    );

                if ( ! empty( $name_keys['fl'] ) ) {
                    $base_fl = 'fl_' . $name_keys['fl'];
                } elseif ( ! empty( $name_keys['exact'] ) ) {
                    $base_fl = 'ex_' . $name_keys['exact'];
                } else {
                    $base_fl = 'name_' . strtolower( $raw_name );
                }

                $tfaa_id  = isset( $reg['tfaa_id'] ) ? trim( (string) $reg['tfaa_id'] ) : '';
                $id_lower = strtolower( $tfaa_id );

                if ( $id_lower !== '' ) {
                    $fl_to_ids[ $base_fl ][ $id_lower ] = true;
                    $first_len                          = isset( $name_keys['first'] ) ? strlen( $name_keys['first'] ) : strlen( $raw_name );
                    if ( ! isset( $id_to_best_fl[ $id_lower ] ) || $first_len > $id_to_best_len[ $id_lower ] ) {
                        $id_to_best_fl[ $id_lower ]  = $base_fl;
                        $id_to_best_len[ $id_lower ] = $first_len;
                    }
                }

                $disp_name = $has_lf_formatter ? sywat_format_name_last_first( $raw_name ) : $raw_name;

                $raw_parsed_rows[] = array(
                    'id'           => isset( $reg['id'] ) ? $reg['id'] : '',
                    'name'         => $raw_name,
                    'display_name' => $disp_name,
                    'tfaa_id'      => $tfaa_id,
                    'id_lower'     => $id_lower,
                    'base_fl'      => $base_fl,
                    'name_keys'    => $name_keys,
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

        // Assign unified archer_key linking tfaa_id and normalized First+Last (+ suffix) keys
        $scores_by_tourn = array();
        foreach ( $raw_parsed_rows as $item ) {
            $canon_fl = ( $item['id_lower'] !== '' && isset( $id_to_best_fl[ $item['id_lower'] ] ) )
                ? $id_to_best_fl[ $item['id_lower'] ]
                : $item['base_fl'];

            // Only split identical First+Last names when two genuinely distinct non-empty tfaa_ids exist
            if ( $item['id_lower'] !== '' && isset( $fl_to_ids[ $canon_fl ] ) && count( $fl_to_ids[ $canon_fl ] ) > 1 ) {
                $item['archer_key'] = $canon_fl . '___id_' . $item['id_lower'];
            } else {
                $item['archer_key'] = $canon_fl;
            }

            $scores_by_tourn[ $item['tournament'] ][] = $item;
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
                    $disp_name = ! empty( $s['display_name'] )
                        ? $s['display_name']
                        : ( $has_lf_formatter ? sywat_format_name_last_first( $s['name'] ) : $s['name'] );

                    $groups[ $div_gen_key ]['styles'][ $style_key ][ $archer_key ] = array(
                        'name'             => $s['name'],
                        'display_name'     => $disp_name,
                        'tfaa_id'          => $s['tfaa_id'],
                        'name_keys'        => $s['name_keys'],
                        'division'         => $s['division'],
                        'gender'           => $s['gender'],
                        'style'            => $s['style'],
                        'tournaments'      => array(),
                        'qualifier_scores' => array(),
                        'state_score'      => null,
                    );
                } else {
                    // Prefer fuller canonical name (e.g. "Christopher Clark" over "Chris Clark") and populate tfaa_id if missing
                    $existing_ref =& $groups[ $div_gen_key ]['styles'][ $style_key ][ $archer_key ];
                    $curr_first   = isset( $existing_ref['name_keys']['first'] ) ? $existing_ref['name_keys']['first'] : '';
                    $new_first    = isset( $s['name_keys']['first'] ) ? $s['name_keys']['first'] : '';
                    if ( strlen( $new_first ) > strlen( $curr_first ) ) {
                        $existing_ref['name']         = $s['name'];
                        $existing_ref['display_name'] = $s['display_name'];
                        $existing_ref['name_keys']    = $s['name_keys'];
                    }
                    if ( empty( $existing_ref['tfaa_id'] ) && ! empty( $s['tfaa_id'] ) ) {
                        $existing_ref['tfaa_id'] = $s['tfaa_id'];
                    }
                    unset( $existing_ref );
                }

                $groups[ $div_gen_key ]['styles'][ $style_key ][ $archer_key ]['tournaments'][ $t_name ] = true;

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
                // Merge given-name alias variants (e.g. "Chris Clark" <-> "Christopher Clark") across disjoint tournaments
                if ( $has_alias_fn && count( $archers ) > 1 ) {
                    $by_surname = array();
                    foreach ( $archers as $a_key => $a_rec ) {
                        $nk     = isset( $a_rec['name_keys'] ) ? $a_rec['name_keys'] : array();
                        $f_tok  = isset( $nk['first'] ) ? $nk['first'] : '';
                        $l_tok  = isset( $nk['last'] ) ? $nk['last'] : '';
                        $sx_tok = isset( $nk['suffix'] ) ? $nk['suffix'] : '';
                        if ( $f_tok !== '' && $l_tok !== '' ) {
                            $by_surname[ $l_tok . '___' . $sx_tok ][] = $a_key;
                        }
                    }

                    foreach ( $by_surname as $surname_sig => $cand_keys ) {
                        if ( count( $cand_keys ) < 2 ) {
                            continue;
                        }

                        // Prefer fuller canonical first name (e.g. "Christopher" before "Chris") as primary target
                        usort( $cand_keys, function( $kA, $kB ) use ( $archers ) {
                            $fA = isset( $archers[ $kA ]['name_keys']['first'] ) ? strlen( $archers[ $kA ]['name_keys']['first'] ) : 0;
                            $fB = isset( $archers[ $kB ]['name_keys']['first'] ) ? strlen( $archers[ $kB ]['name_keys']['first'] ) : 0;
                            if ( $fA !== $fB ) {
                                return $fB - $fA;
                            }
                            $idA = ! empty( $archers[ $kA ]['tfaa_id'] ) ? 1 : 0;
                            $idB = ! empty( $archers[ $kB ]['tfaa_id'] ) ? 1 : 0;
                            if ( $idA !== $idB ) {
                                return $idB - $idA;
                            }
                            return strcmp( $kA, $kB );
                        } );

                        $total_cands = count( $cand_keys );
                        for ( $i = 0; $i < $total_cands; $i++ ) {
                            $primary_key = $cand_keys[ $i ];
                            if ( ! isset( $archers[ $primary_key ] ) ) {
                                continue;
                            }

                            for ( $j = $i + 1; $j < $total_cands; $j++ ) {
                                $alias_key = $cand_keys[ $j ];
                                if ( ! isset( $archers[ $alias_key ] ) ) {
                                    continue;
                                }

                                $p_first = $archers[ $primary_key ]['name_keys']['first'];
                                $a_first = $archers[ $alias_key ]['name_keys']['first'];

                                if ( $p_first === $a_first || ! sywat_are_given_names_compatible( $p_first, $a_first ) ) {
                                    continue;
                                }

                                // Do not merge if both records carry distinct non-empty tfaa_ids
                                $p_id = strtolower( trim( (string) $archers[ $primary_key ]['tfaa_id'] ) );
                                $a_id = strtolower( trim( (string) $archers[ $alias_key ]['tfaa_id'] ) );
                                if ( $p_id !== '' && $a_id !== '' && $p_id !== $a_id ) {
                                    continue;
                                }

                                // Do not merge if both names competed in the exact same tournament
                                $overlap = array_intersect_key(
                                    $archers[ $primary_key ]['tournaments'],
                                    $archers[ $alias_key ]['tournaments']
                                );
                                if ( ! empty( $overlap ) ) {
                                    continue;
                                }

                                // Merge alias bucket into primary bucket
                                foreach ( $archers[ $alias_key ]['qualifier_scores'] as $qs ) {
                                    $archers[ $primary_key ]['qualifier_scores'][] = $qs;
                                }
                                if ( null === $archers[ $primary_key ]['state_score'] && null !== $archers[ $alias_key ]['state_score'] ) {
                                    $archers[ $primary_key ]['state_score'] = $archers[ $alias_key ]['state_score'];
                                }
                                if ( empty( $archers[ $primary_key ]['tfaa_id'] ) && ! empty( $archers[ $alias_key ]['tfaa_id'] ) ) {
                                    $archers[ $primary_key ]['tfaa_id'] = $archers[ $alias_key ]['tfaa_id'];
                                }
                                foreach ( $archers[ $alias_key ]['tournaments'] as $t_seen => $flag ) {
                                    $archers[ $primary_key ]['tournaments'][ $t_seen ] = true;
                                }

                                unset( $archers[ $alias_key ] );
                            }
                        }
                    }
                }

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
