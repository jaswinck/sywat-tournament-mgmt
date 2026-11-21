<?php
/**
 * Sub-Module: Results & Standings Data Access & Aggregation Engine
 * Path: includes/results/results-data.php
 * Description: Pure database retrieval and batch aggregation layer for historical shooter lookups (excluding archived "_hist_" migration records), single-tournament score queries (including 1-day, 2-day, and 3-day round breakdowns, Inside-Out "I/Os" tie-breaker counts, and legacy TFAA class-code stripping), and multi-tournament Season Standings (single-query season batching, incomplete "[INC]" round and Guest-entry exclusion, single-pass O(1) canonical identity resolution via sywat_normalize_archer_match_keys() and sywat_get_given_name_alias_map(), sequential Score 1 -> Score 2 -> Score 3 -> State X-count tie-breaking, and surname tie-breaker sorting).
 * Compliance: Pure data layer — zero HTML echo, zero presentation formatting, zero duplicated alias maps, single-pass O(1) computational hoisting, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.3.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetches distinct historical archer profiles across Indoor and Outdoor cycles (18-Month Rolling Window).
 * Queries custom scores tables and active tournament registration options (excluding archived "_hist_" records),
 * returning ONLY complete (Name, Division, Gender, Style) profiles.
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

        // 2. Secondary Query: Active tournament options store (sywat_t_%, excluding archived "_hist_" migration rows)
        $opt_results = $wpdb->get_results(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%' AND option_name NOT LIKE '%\\_hist\\_%'"
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
 * labels via O(1) lookup maps, stripping legacy TFAA class codes from imported names,
 * and preserving registration 'type' (e.g., 'Guest'), 'is_inc', Inside-Out ('io_count' / 'has_io'),
 * and multi-day round score breakdowns (r1/r2/r3). Defaults blank/unset score fields to 0
 * so every roster entry is included.
 *
 * @param string $t_name Tournament title.
 * @return array         Normalized list of score entries for the tournament.
 */
if ( ! function_exists( 'sywat_get_tournament_scores' ) ) {
    function sywat_get_tournament_scores( $t_name ) {
        global $wpdb;

        $t_slug         = sanitize_title( $t_name );
        $scores         = array();
        $has_div_res    = function_exists( 'sywat_results_resolve_division_record' );
        $has_style_res  = function_exists( 'sywat_results_resolve_style_record' );
        $has_clean_name = function_exists( 'sywat_results_clean_archer_name' );

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
                $score_val  = ( isset( $reg['score'] ) && $reg['score'] !== '' && $reg['score'] !== null ) ? intval( $reg['score'] ) : 0;
                $x_val      = ( isset( $reg['x_count'] ) && $reg['x_count'] !== '' && $reg['x_count'] !== null ) ? intval( $reg['x_count'] ) : 0;
                $io_val     = ( isset( $reg['io_count'] ) && $reg['io_count'] !== '' && $reg['io_count'] !== null ) ? max( 0, intval( $reg['io_count'] ) ) : 0;
                $has_io     = ( ! empty( $reg['has_io'] ) || $io_val > 0 ) ? 1 : 0;
                $round_days = isset( $reg['round_days'] ) ? max( 1, intval( $reg['round_days'] ) ) : 1;
                $r1_score   = isset( $reg['r1_score'] ) ? intval( $reg['r1_score'] ) : $score_val;
                $r1_xs      = isset( $reg['r1_xs'] ) ? intval( $reg['r1_xs'] ) : $x_val;
                $r2_score   = isset( $reg['r2_score'] ) ? intval( $reg['r2_score'] ) : 0;
                $r2_xs      = isset( $reg['r2_xs'] ) ? intval( $reg['r2_xs'] ) : 0;
                $r3_score   = isset( $reg['r3_score'] ) ? intval( $reg['r3_score'] ) : 0;
                $r3_xs      = isset( $reg['r3_xs'] ) ? intval( $reg['r3_xs'] ) : 0;

                $raw_div  = isset( $reg['division'] ) && trim( (string) $reg['division'] ) !== '' ? trim( (string) $reg['division'] ) : 'Adult (18 - 49)';
                $div_rec  = $has_div_res ? sywat_results_resolve_division_record( $raw_div ) : null;
                $norm_div = ( $div_rec && ! empty( $div_rec['label'] ) ) ? $div_rec['label'] : $raw_div;

                $raw_style  = isset( $reg['style'] ) && trim( (string) $reg['style'] ) !== '' ? trim( (string) $reg['style'] ) : 'FS – Freestyle';
                $style_rec  = $has_style_res ? sywat_results_resolve_style_record( $raw_style ) : null;
                $norm_style = ( $style_rec && ! empty( $style_rec['label'] ) ) ? $style_rec['label'] : $raw_style;

                $raw_name = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : 'Archer';
                if ( $has_clean_name && $raw_name !== '' ) {
                    $raw_name = sywat_results_clean_archer_name( $raw_name );
                }

                $scores[] = array(
                    'id'         => isset( $reg['id'] ) ? $reg['id'] : '',
                    'name'       => $raw_name,
                    'tfaa_id'    => isset( $reg['tfaa_id'] ) ? trim( (string) $reg['tfaa_id'] ) : '',
                    'type'       => isset( $reg['type'] ) ? trim( (string) $reg['type'] ) : '',
                    'division'   => $norm_div,
                    'gender'     => isset( $reg['gender'] ) ? $reg['gender'] : 'Male',
                    'style'      => $norm_style,
                    'round_days' => $round_days,
                    'r1_score'   => $r1_score,
                    'r1_xs'      => $r1_xs,
                    'r2_score'   => $r2_score,
                    'r2_xs'      => $r2_xs,
                    'r3_score'   => $r3_score,
                    'r3_xs'      => $r3_xs,
                    'score'      => $score_val,
                    'x_count'    => $x_val,
                    'io_count'   => $io_val,
                    'has_io'     => $has_io,
                    'is_inc'     => ! empty( $reg['is_inc'] ) ? 1 : 0,
                    'tournament' => $t_name,
                );
            }
        }

        return $scores;
    }
}

/**
 * Aggregates season standings grouped by Division + Gender -> Style using a single
 * batched SQL query across all published tournaments in the season.
 * EXCLUDES all incomplete ("[INC]") rounds, Guest entries (type === 'Guest', style === 'Guest',
 * or '[Guest]' tags), score-0 cards, and secondary re-entries entirely.
 * Resolves each archer's canonical O(1) identity key in a single pass via
 * sywat_normalize_archer_match_keys() and sywat_get_given_name_alias_map() so
 * identical archers with differing/missing tfaa_ids and unambiguous given-name variants
 * ("Chris Clark" <-> "Christopher Clark") merge cleanly, enforces 'scores_published',
 * and breaks ties on Total Score + Total X-Count sequentially across Score 1 -> Score 2 ->
 * Score 3 -> State (score then X-count descending) before falling back to surname order.
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
        $has_match_keys   = function_exists( 'sywat_normalize_archer_match_keys' );
        $alias_map        = function_exists( 'sywat_get_given_name_alias_map' ) ? sywat_get_given_name_alias_map() : array();
        $has_div_res      = function_exists( 'sywat_results_resolve_division_record' );
        $has_style_res    = function_exists( 'sywat_results_resolve_style_record' );
        $has_clean_name   = function_exists( 'sywat_results_clean_archer_name' );
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
                if ( $has_clean_name && $raw_name !== '' ) {
                    $raw_name = sywat_results_clean_archer_name( $raw_name );
                }
                $raw_style = isset( $reg['style'] ) && trim( (string) $reg['style'] ) !== '' ? trim( (string) $reg['style'] ) : 'FS – Freestyle';
                $raw_type  = isset( $reg['type'] ) ? trim( (string) $reg['type'] ) : '';

                // Exclude all incomplete ([INC]) rounds from Season Standings
                if ( ! empty( $reg['is_inc'] ) || stripos( $raw_name, '[inc]' ) !== false || strcasecmp( $raw_type, 'INC' ) === 0 ) {
                    continue;
                }

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

                // Single-pass O(1) canonical identity key (folds middle initials, suffix periods, and unambiguous given-name aliases)
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

                $first_tok = isset( $name_keys['first'] ) ? $name_keys['first'] : '';
                $last_tok  = isset( $name_keys['last'] ) ? $name_keys['last'] : '';
                $sfx_tok   = isset( $name_keys['suffix'] ) ? $name_keys['suffix'] : '';

                if ( $first_tok !== '' && $last_tok !== '' && $name_keys['token_count'] >= 2 ) {
                    $canon_first = ( isset( $alias_map[ $first_tok ] ) && count( $alias_map[ $first_tok ] ) === 1 )
                        ? $alias_map[ $first_tok ][0]
                        : $first_tok;
                    $archer_key  = 'fl_' . $canon_first . '___' . $last_tok . ( $sfx_tok !== '' ? '___' . $sfx_tok : '' );
                } elseif ( ! empty( $name_keys['fl'] ) ) {
                    $archer_key = 'fl_' . $name_keys['fl'];
                } else {
                    $archer_key = 'ex_' . $name_keys['exact'];
                }

                $scores_by_tourn[ $resolved_tourn ][] = array(
                    'id'         => isset( $reg['id'] ) ? $reg['id'] : '',
                    'name'       => $raw_name,
                    'tfaa_id'    => isset( $reg['tfaa_id'] ) ? trim( (string) $reg['tfaa_id'] ) : '',
                    'archer_key' => $archer_key,
                    'name_keys'  => $name_keys,
                    'type'       => $raw_type,
                    'division'   => $norm_div,
                    'gender'     => isset( $reg['gender'] ) ? $reg['gender'] : 'Male',
                    'style'      => $norm_sty,
                    'score'      => $score_val,
                    'x_count'    => $x_val,
                    'tournament' => $resolved_tourn,
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
                        'tfaa_id'          => $s['tfaa_id'],
                        'name_keys'        => $s['name_keys'],
                        'division'         => $s['division'],
                        'gender'           => $s['gender'],
                        'style'            => $s['style'],
                        'qualifier_scores' => array(),
                        'state_score'      => null,
                    );
                } else {
                    // Prefer fuller given name (e.g., "Christopher Clark" over "Chris Clark") and retain non-empty tfaa_id
                    $existing_ref =& $groups[ $div_gen_key ]['styles'][ $style_key ][ $archer_key ];
                    $curr_first   = isset( $existing_ref['name_keys']['first'] ) ? $existing_ref['name_keys']['first'] : '';
                    $new_first    = isset( $s['name_keys']['first'] ) ? $s['name_keys']['first'] : '';
                    if ( strlen( $new_first ) > strlen( $curr_first ) ) {
                        $existing_ref['name']      = $s['name'];
                        $existing_ref['name_keys'] = $s['name_keys'];
                    }
                    if ( empty( $existing_ref['tfaa_id'] ) && ! empty( $s['tfaa_id'] ) ) {
                        $existing_ref['tfaa_id'] = $s['tfaa_id'];
                    }
                    unset( $existing_ref );
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

                    $nk          = $a['name_keys'];
                    $surname_key = trim(
                        ( isset( $nk['last'] ) ? $nk['last'] : '' ) . ' ' .
                        ( isset( $nk['suffix'] ) ? $nk['suffix'] : '' ) . ', ' .
                        ( isset( $nk['first'] ) ? $nk['first'] : $a['name'] )
                    );

                    $processed[] = array(
                        'name'          => $a['name'],
                        'surname_key'   => $surname_key,
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
                    if ( $x['total_score'] !== $y['total_score'] ) {
                        return $y['total_score'] - $x['total_score'];
                    }
                    if ( $x['total_x_count'] !== $y['total_x_count'] ) {
                        return $y['total_x_count'] - $x['total_x_count'];
                    }

                    // Sequential slot tie-breaker: Score 1 -> Score 2 -> Score 3 -> State (score, then X-count descending)
                    $slots = array( 'score_1', 'score_2', 'score_3', 'state_score' );
                    foreach ( $slots as $slot ) {
                        $s_x = isset( $x[ $slot ]['score'] ) ? intval( $x[ $slot ]['score'] ) : 0;
                        $s_y = isset( $y[ $slot ]['score'] ) ? intval( $y[ $slot ]['score'] ) : 0;
                        if ( $s_x !== $s_y ) {
                            return $s_y - $s_x;
                        }

                        $x_x = isset( $x[ $slot ]['x_count'] ) ? intval( $x[ $slot ]['x_count'] ) : 0;
                        $x_y = isset( $y[ $slot ]['x_count'] ) ? intval( $y[ $slot ]['x_count'] ) : 0;
                        if ( $x_x !== $x_y ) {
                            return $x_y - $x_x;
                        }
                    }

                    return strcasecmp( $x['surname_key'], $y['surname_key'] );
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
