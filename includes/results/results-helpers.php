<?php
/**
 * Sub-Module: Results & Standings Helpers
 * Path: includes/results/results-helpers.php
 * Description: Provides O(1) cached lookup maps, historical shooter queries, category formatting, database-driven sort weights, hoisted "LastName, FirstName" display name formatting, and multi-tournament season standings aggregation with surname tie-breaker sorting.
 * Compliance: Pure helper layer — zero HTML echo, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Build and cache an O(1) static per-request lookup map for database-configured Age Divisions,
 * indexed by lowercase label, slug, export code, base name, and aliases.
 *
 * @return array Associative lookup map of lowercase key => division record.
 */
if ( ! function_exists( 'sywat_results_get_division_lookup' ) ) {
    function sywat_results_get_division_lookup() {
        static $lookup = null;
        if ( null !== $lookup ) {
            return $lookup;
        }

        $lookup = array();
        if ( ! function_exists( 'sywat_get_division_records' ) ) {
            return $lookup;
        }

        $records = sywat_get_division_records( true );
        if ( ! is_array( $records ) ) {
            return $lookup;
        }

        foreach ( $records as $rec ) {
            if ( empty( $rec['label'] ) ) {
                continue;
            }

            $label_key = strtolower( trim( (string) $rec['label'] ) );
            $lookup[ $label_key ] = $rec;

            if ( ! empty( $rec['slug'] ) ) {
                $lookup[ strtolower( trim( (string) $rec['slug'] ) ) ] = $rec;
            }
            if ( ! empty( $rec['code'] ) ) {
                $code_key = strtolower( trim( (string) $rec['code'] ) );
                if ( ! isset( $lookup[ $code_key ] ) ) {
                    $lookup[ $code_key ] = $rec;
                }
            }

            $base_name = strtolower( trim( preg_replace( '/\s*\([^)]*\)/', '', (string) $rec['label'] ) ) );
            if ( $base_name !== '' && ! isset( $lookup[ $base_name ] ) ) {
                $lookup[ $base_name ] = $rec;
            }

            if ( ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ) {
                foreach ( $rec['aliases'] as $alias ) {
                    $alias_key = strtolower( trim( (string) $alias ) );
                    if ( $alias_key !== '' && ! isset( $lookup[ $alias_key ] ) ) {
                        $lookup[ $alias_key ] = $rec;
                    }
                }
            }
        }

        return $lookup;
    }
}

/**
 * Build and cache an O(1) static per-request lookup map for database-configured Shooting Styles,
 * indexed by lowercase label, slug, export code, descriptive title, and aliases.
 *
 * @return array Associative lookup map of lowercase key => shooting style record.
 */
if ( ! function_exists( 'sywat_results_get_style_lookup' ) ) {
    function sywat_results_get_style_lookup() {
        static $lookup = null;
        if ( null !== $lookup ) {
            return $lookup;
        }

        $lookup = array();
        if ( ! function_exists( 'sywat_get_shooting_styles' ) ) {
            return $lookup;
        }

        $records = sywat_get_shooting_styles( true );
        if ( ! is_array( $records ) ) {
            return $lookup;
        }

        foreach ( $records as $rec ) {
            if ( empty( $rec['label'] ) ) {
                continue;
            }

            $label_str = trim( (string) $rec['label'] );
            $label_key = strtolower( $label_str );
            $lookup[ $label_key ] = $rec;

            if ( ! empty( $rec['slug'] ) ) {
                $lookup[ strtolower( trim( (string) $rec['slug'] ) ) ] = $rec;
            }
            if ( ! empty( $rec['code'] ) ) {
                $code_key = strtolower( trim( (string) $rec['code'] ) );
                if ( ! isset( $lookup[ $code_key ] ) ) {
                    $lookup[ $code_key ] = $rec;
                }
            }

            // Index split parts around en-dash / hyphen ("FS – Freestyle" -> "fs" & "freestyle")
            $parts = preg_split( '/\s+(?:–|-)\s+/', $label_str, 2 );
            if ( is_array( $parts ) && count( $parts ) === 2 ) {
                $p0 = strtolower( trim( $parts[0] ) );
                $p1 = strtolower( trim( $parts[1] ) );
                if ( $p0 !== '' && ! isset( $lookup[ $p0 ] ) ) {
                    $lookup[ $p0 ] = $rec;
                }
                if ( $p1 !== '' && ! isset( $lookup[ $p1 ] ) ) {
                    $lookup[ $p1 ] = $rec;
                }
            }

            if ( ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ) {
                foreach ( $rec['aliases'] as $alias ) {
                    $alias_key = strtolower( trim( (string) $alias ) );
                    if ( $alias_key !== '' && ! isset( $lookup[ $alias_key ] ) ) {
                        $lookup[ $alias_key ] = $rec;
                    }
                }
            }
        }

        return $lookup;
    }
}

/**
 * Resolve a raw division string to its canonical database record in O(1) time.
 *
 * @param string $raw_div Raw division string.
 * @return array|null     Division config record or null.
 */
if ( ! function_exists( 'sywat_results_resolve_division_record' ) ) {
    function sywat_results_resolve_division_record( $raw_div ) {
        $clean = strtolower( trim( (string) $raw_div ) );
        if ( $clean === '' || $clean === 'no show' ) {
            return null;
        }

        $lookup = sywat_results_get_division_lookup();
        if ( isset( $lookup[ $clean ] ) ) {
            return $lookup[ $clean ];
        }

        $stripped = strtolower( trim( preg_replace( '/\s*\([^)]*\)/', '', $clean ) ) );
        if ( $stripped !== '' && isset( $lookup[ $stripped ] ) ) {
            return $lookup[ $stripped ];
        }

        return null;
    }
}

/**
 * Resolve a raw shooting style string to its canonical database record in O(1) time.
 *
 * @param string $raw_style Raw shooting style string.
 * @return array|null       Shooting style config record or null.
 */
if ( ! function_exists( 'sywat_results_resolve_style_record' ) ) {
    function sywat_results_resolve_style_record( $raw_style ) {
        $clean = strtolower( trim( (string) $raw_style ) );
        if ( $clean === '' || $clean === 'no show' || $clean === 'guest' ) {
            return null;
        }

        $lookup = sywat_results_get_style_lookup();
        if ( isset( $lookup[ $clean ] ) ) {
            return $lookup[ $clean ];
        }

        $parts = preg_split( '/\s+(?:–|-)\s+/', $clean, 2 );
        if ( is_array( $parts ) && ! empty( $parts[0] ) ) {
            $prefix = strtolower( trim( $parts[0] ) );
            if ( isset( $lookup[ $prefix ] ) ) {
                return $lookup[ $prefix ];
            }
        }

        return null;
    }
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

        $shooters  = array();
        $seen_keys = array();

        // 1. Primary Query: Scores table (wp_sywat_scores) with 18-month rolling window
        $scores_table = $wpdb->prefix . 'sywat_scores';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$scores_table}'" ) === $scores_table ) {
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
                    $div_rec   = sywat_results_resolve_division_record( $r['division'] );
                    $style_rec = sywat_results_resolve_style_record( $r['style'] );
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

                    $div_rec   = sywat_results_resolve_division_record( $division );
                    $style_rec = sywat_results_resolve_style_record( $style );
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
 * Cleanly formats Division, Gender, and Style into a single unified category header.
 * Example: Pro Adult (18 - 49) Male Freestyle
 * Example: Cub (8 - 11) Male Bowhunter Freestyle
 */
if ( ! function_exists( 'sywat_format_combined_category_header' ) ) {
    function sywat_format_combined_category_header( $division, $gender, $style ) {
        $div_rec   = sywat_results_resolve_division_record( $division );
        $style_rec = sywat_results_resolve_style_record( $style );

        $is_pro = ( $style_rec && ! empty( $style_rec['is_pro'] ) )
            || ( stripos( (string) $style, 'Pro' ) !== false )
            || ( stripos( (string) $division, 'Pro' ) !== false );

        $clean_div = ( $div_rec && ! empty( $div_rec['label'] ) ) ? $div_rec['label'] : trim( (string) $division );
        if ( $is_pro && stripos( $clean_div, 'Pro' ) === false ) {
            $clean_div = 'Pro ' . $clean_div;
        }

        $clean_style = ( $style_rec && ! empty( $style_rec['label'] ) ) ? $style_rec['label'] : trim( (string) $style );
        if ( strpos( $clean_style, ' – ' ) !== false ) {
            $parts       = explode( ' – ', $clean_style );
            $clean_style = end( $parts );
        } elseif ( strpos( $clean_style, ' - ' ) !== false ) {
            $parts       = explode( ' - ', $clean_style );
            $clean_style = end( $parts );
        }

        if ( $is_pro && preg_match( '/\s+Pro$/i', $clean_style ) ) {
            $clean_style = preg_replace( '/\s+Pro$/i', '', $clean_style );
        }

        $clean_gender = trim( (string) $gender );
        return trim( "{$clean_div} {$clean_gender} {$clean_style}" );
    }
}

/**
 * Calculates numeric sort weight for Division + Gender groupings using database sort_order.
 * Order: Configured Division sort_order * 10 + Gender Modifier (1=Pro Male, 2=Male, 3=Pro Female, 4=Female).
 */
if ( ! function_exists( 'sywat_get_div_gen_sort_weight' ) ) {
    function sywat_get_div_gen_sort_weight( $div, $gender ) {
        $d = strtolower( trim( (string) $div ) );
        $g = strtolower( trim( (string) $gender ) );

        $base    = 900;
        $div_rec = sywat_results_resolve_division_record( $div );
        if ( $div_rec && isset( $div_rec['sort_order'] ) ) {
            $base = intval( $div_rec['sort_order'] ) * 10;
        } else {
            if ( strpos( $d, 'cub' ) !== false ) {
                $base = 10;
            } elseif ( strpos( $d, 'young adult' ) !== false ) {
                $base = 30;
            } elseif ( strpos( $d, 'youth' ) !== false ) {
                $base = 20;
            } elseif ( strpos( $d, 'master senior' ) !== false ) {
                $base = 70;
            } elseif ( strpos( $d, 'silver senior' ) !== false ) {
                $base = 60;
            } elseif ( strpos( $d, 'senior' ) !== false ) {
                $base = 50;
            } elseif ( strpos( $d, 'adult' ) !== false ) {
                $base = 40;
            }
        }

        $mod       = 2; // Default Male
        $is_pro    = ( strpos( $d, 'pro' ) !== false || strpos( $g, 'pro' ) !== false );
        $is_female = ( strpos( $g, 'female' ) !== false );

        if ( $is_pro && ! $is_female ) {
            $mod = 1; // Pro Male
        } elseif ( ! $is_pro && ! $is_female ) {
            $mod = 2; // Male
        } elseif ( $is_pro && $is_female ) {
            $mod = 3; // Pro Female
        } elseif ( ! $is_pro && $is_female ) {
            $mod = 4; // Female
        }

        return $base + $mod;
    }
}

/**
 * Calculates numeric sort weight for Shooting Styles from database sort_order (with Guest & legacy fallbacks).
 */
if ( ! function_exists( 'sywat_get_style_sort_weight' ) ) {
    function sywat_get_style_sort_weight( $style_name ) {
        $st = strtolower( trim( (string) $style_name ) );

        if ( $st === 'guest' || strpos( $st, 'guest' ) !== false ) {
            return 900;
        }

        $style_rec = sywat_results_resolve_style_record( $style_name );
        if ( $style_rec && isset( $style_rec['sort_order'] ) ) {
            return intval( $style_rec['sort_order'] );
        }

        // Legacy fallback order
        if ( strpos( $st, 'genesis' ) !== false ) {
            return 120;
        }
        if ( strpos( $st, 'fslr' ) !== false || strpos( $st, 'fsl-r' ) !== false || ( strpos( $st, 'recurve' ) !== false && strpos( $st, 'fsl' ) !== false ) ) {
            return 40;
        }
        if ( strpos( $st, 'lb' ) !== false || strpos( $st, 'longbow' ) !== false ) {
            return 110;
        }
        if ( strpos( $st, 'trad' ) !== false || strpos( $st, 'traditional' ) !== false ) {
            return 100;
        }
        if ( strpos( $st, 'bbr' ) !== false || strpos( $st, 'barebow recurve' ) !== false ) {
            return 90;
        }
        if ( strpos( $st, 'bbc' ) !== false || strpos( $st, 'barebow compound' ) !== false ) {
            return 80;
        }
        if ( strpos( $st, 'bb' ) !== false && strpos( $st, 'bhfs' ) === false ) {
            return 70;
        }
        if ( strpos( $st, 'mbh' ) !== false || strpos( $st, 'modern bowhunter' ) !== false ) {
            return 60;
        }
        if ( strpos( $st, 'bhfs' ) !== false || strpos( $st, 'bowhunter freestyle' ) !== false ) {
            return 50;
        }
        if ( strpos( $st, 'fsl' ) !== false ) {
            return 30;
        }
        if ( strpos( $st, 'fs pro' ) !== false ) {
            return 20;
        }
        if ( strpos( $st, 'fs' ) !== false || strpos( $st, 'freestyle' ) !== false ) {
            return 10;
        }

        return 999;
    }
}

/**
 * Cleanly formats Division and Gender into a unified header title.
 */
if ( ! function_exists( 'sywat_format_div_gen_heading' ) ) {
    function sywat_format_div_gen_heading( $division, $gender ) {
        $div_rec   = sywat_results_resolve_division_record( $division );
        $canon_div = ( $div_rec && ! empty( $div_rec['label'] ) ) ? $div_rec['label'] : (string) $division;

        $clean_div = trim( preg_replace( '/\s*\([^)]*\)/', '', $canon_div ) );
        $clean_gen = trim( preg_replace( '/\s*\([^)]*\)/', '', (string) $gender ) );

        if ( $clean_gen !== '' && stripos( $clean_div, $clean_gen ) !== false ) {
            return $clean_div;
        }
        return trim( $clean_div . ' ' . $clean_gen );
    }
}

/**
 * Fetch all registered score records for a given tournament, normalizing division and style
 * labels via O(1) lookup maps and computing hoisted "LastName, FirstName" display labels
 * while preserving canonical 'name' keys for cross-tournament deduplication.
 * Defaults blank/unset score fields to 0 so every roster entry is included.
 */
if ( ! function_exists( 'sywat_get_tournament_scores' ) ) {
    function sywat_get_tournament_scores( $t_name ) {
        global $wpdb;
        $t_slug           = sanitize_title( $t_name );
        $scores           = array();
        $has_lf_formatter = function_exists( 'sywat_format_name_last_first' );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'sywat_t_' . $t_slug . '_' ) . '%'
        ) );

        foreach ( $results as $row ) {
            $reg = maybe_unserialize( $row->option_value );
            if ( is_array( $reg ) ) {
                $score_val = ( isset( $reg['score'] ) && $reg['score'] !== '' && $reg['score'] !== null ) ? intval( $reg['score'] ) : 0;
                $x_val     = ( isset( $reg['x_count'] ) && $reg['x_count'] !== '' && $reg['x_count'] !== null ) ? intval( $reg['x_count'] ) : 0;

                $raw_div  = isset( $reg['division'] ) && trim( (string) $reg['division'] ) !== '' ? trim( (string) $reg['division'] ) : 'Adult (18 - 49)';
                $div_rec  = sywat_results_resolve_division_record( $raw_div );
                $norm_div = ( $div_rec && ! empty( $div_rec['label'] ) ) ? $div_rec['label'] : $raw_div;

                $raw_style  = isset( $reg['style'] ) && trim( (string) $reg['style'] ) !== '' ? trim( (string) $reg['style'] ) : 'FS – Freestyle';
                $style_rec  = sywat_results_resolve_style_record( $raw_style );
                $norm_style = ( $style_rec && ! empty( $style_rec['label'] ) ) ? $style_rec['label'] : $raw_style;

                $raw_name  = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : 'Archer';
                $disp_name = $has_lf_formatter ? sywat_format_name_last_first( $raw_name ) : $raw_name;

                $scores[] = array(
                    'id'           => isset( $reg['id'] ) ? $reg['id'] : '',
                    'name'         => $raw_name,
                    'display_name' => $disp_name,
                    'tfaa_id'      => isset( $reg['tfaa_id'] ) ? trim( (string) $reg['tfaa_id'] ) : '',
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
 * Aggregates season standings grouped by Division + Gender -> Style
 * EXCLUDES all Guest scores, score-0 cards, and secondary re-entries entirely.
 * ENFORCES 'scores_published' gate so unpublished scores remain hidden.
 * Orders tied total scores & X-counts alphabetically by "LastName, FirstName".
 */
if ( ! function_exists( 'sywat_aggregate_season_standings' ) ) {
    function sywat_aggregate_season_standings( $season_slug ) {
        $seasons          = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
        $season           = isset( $seasons[ $season_slug ] ) ? $seasons[ $season_slug ] : null;
        $best_n           = ( $season && isset( $season['best_n_count'] ) ) ? intval( $season['best_n_count'] ) : 3;
        $has_lf_formatter = function_exists( 'sywat_format_name_last_first' );

        $all_tournaments      = get_option( 'sywat_tournaments_list', array() );
        $matching_tournaments = array();

        foreach ( $all_tournaments as $t_name => $t_data ) {
            $t_season     = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $t_data ) : '';
            $is_published = ! empty( $t_data['scores_published'] );

            if ( $t_season === $season_slug && $is_published ) {
                $matching_tournaments[] = $t_name;
            }
        }

        $groups = array();

        foreach ( $matching_tournaments as $t_name ) {
            $raw_scores     = sywat_get_tournament_scores( $t_name );
            $is_state_tourn = ( stripos( $t_name, 'state' ) !== false );

            // Filter out Guests AND turned-in 0 scores
            $official_scores = array_filter( $raw_scores, function( $s ) {
                $st = strtolower( trim( $s['style'] ) );
                $nm = strtolower( trim( $s['name'] ) );
                $sc = intval( $s['score'] );
                return ( $st !== 'guest' && strpos( $nm, '[guest]' ) === false && $sc > 0 );
            } );

            $grouped_per_tournament = array();
            foreach ( $official_scores as $s ) {
                $dgs_key    = $s['division'] . '|||' . $s['gender'] . '|||' . $s['style'];
                $archer_key = ! empty( $s['tfaa_id'] ) ? 'id_' . strtolower( $s['tfaa_id'] ) : 'name_' . strtolower( $s['name'] );
                $grouped_per_tournament[ $dgs_key ][ $archer_key ][] = $s;
            }

            $deduped_scores = array();
            foreach ( $grouped_per_tournament as $dgs_key => $archers_map ) {
                foreach ( $archers_map as $archer_key => $entries ) {
                    usort( $entries, function( $p, $q ) {
                        if ( $p['score'] === $q['score'] ) {
                            return $q['x_count'] - $p['x_count'];
                        }
                        return $q['score'] - $p['score'];
                    } );
                    $deduped_scores[] = $entries[0];
                }
            }

            foreach ( $deduped_scores as $s ) {
                $div_gen_key = $s['division'] . '|||' . $s['gender'];
                $style_key   = $s['style'];
                $archer_key  = ! empty( $s['tfaa_id'] ) ? 'id_' . strtolower( $s['tfaa_id'] ) : 'name_' . strtolower( $s['name'] );

                if ( ! isset( $groups[ $div_gen_key ] ) ) {
                    $groups[ $div_gen_key ] = array(
                        'division' => $s['division'],
                        'gender'   => $s['gender'],
                        'heading'  => sywat_format_div_gen_heading( $s['division'], $s['gender'] ),
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

        uksort( $groups, function( $keyA, $keyB ) {
            list( $divA, $genA ) = explode( '|||', $keyA );
            list( $divB, $genB ) = explode( '|||', $keyB );

            $wA = sywat_get_div_gen_sort_weight( $divA, $genA );
            $wB = sywat_get_div_gen_sort_weight( $divB, $genB );

            if ( $wA !== $wB ) {
                return $wA - $wB;
            }
            return strcasecmp( $keyA, $keyB );
        } );

        foreach ( $groups as $dg_key => &$group ) {
            uksort( $group['styles'], function( $stA, $stB ) {
                $wA = sywat_get_style_sort_weight( $stA );
                $wB = sywat_get_style_sort_weight( $stB );
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
