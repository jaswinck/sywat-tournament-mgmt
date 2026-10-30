<?php
/**
 * Sub-Module: Results & Standings Helpers
 * Path: includes/results/results-helpers.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Calculates numeric sort weight for Division + Gender groupings.
 * Order: Cub -> Youth -> Young Adult -> Adult (Pro Male -> Male -> Pro Female -> Female) -> Senior -> Silver Senior -> Master Senior
 */
if ( ! function_exists( 'sywat_get_div_gen_sort_weight' ) ) {
    function sywat_get_div_gen_sort_weight( $div, $gender ) {
        $d = strtolower( trim( $div ) );
        $g = strtolower( trim( $gender ) );

        $base = 90;
        if ( strpos( $d, 'cub' ) !== false ) $base = 10;
        elseif ( strpos( $d, 'young adult' ) !== false ) $base = 30;
        elseif ( strpos( $d, 'youth' ) !== false ) $base = 20;
        elseif ( strpos( $d, 'master senior' ) !== false ) $base = 70;
        elseif ( strpos( $d, 'silver senior' ) !== false ) $base = 60;
        elseif ( strpos( $d, 'senior' ) !== false ) $base = 50;
        elseif ( strpos( $d, 'adult' ) !== false ) $base = 40;

        $mod = 2; // Default Male
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
 * Cleanly formats Division and Gender into a unified header title
 */
if ( ! function_exists( 'sywat_format_div_gen_heading' ) ) {
    function sywat_format_div_gen_heading( $division, $gender ) {
        $clean_div = trim( preg_replace( '/\s*\([^)]*\)/', '', $division ) );
        $clean_gen = trim( preg_replace( '/\s*\([^)]*\)/', '', $gender ) );
        
        if ( stripos( $clean_div, $clean_gen ) !== false ) {
            return $clean_div;
        }
        return $clean_div . ' ' . $clean_gen;
    }
}

/**
 * Fetch all completed score records for a given tournament
 */
if ( ! function_exists( 'sywat_get_tournament_scores' ) ) {
    function sywat_get_tournament_scores( $t_name ) {
        global $wpdb;
        $t_slug = sanitize_title( $t_name );
        $scores = array();

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            'sywat_t_' . $t_slug . '_%'
        ) );

        foreach ( $results as $row ) {
            $reg = maybe_unserialize( $row->option_value );
            if ( is_array( $reg ) && isset( $reg['score'] ) && $reg['score'] !== '' && $reg['score'] !== null ) {
                $scores[] = array(
                    'id'          => isset( $reg['id'] ) ? $reg['id'] : '',
                    'name'        => isset( $reg['name'] ) ? trim( $reg['name'] ) : 'Archer',
                    'tfaa_id'     => isset( $reg['tfaa_id'] ) ? trim( $reg['tfaa_id'] ) : '',
                    'division'    => isset( $reg['division'] ) ? $reg['division'] : 'Adult (18 - 49)',
                    'gender'      => isset( $reg['gender'] ) ? $reg['gender'] : 'Male',
                    'style'       => isset( $reg['style'] ) ? $reg['style'] : 'FS – Freestyle',
                    'score'       => intval( $reg['score'] ),
                    'x_count'     => isset( $reg['x_count'] ) ? intval( $reg['x_count'] ) : 0,
                    'tournament'  => $t_name,
                );
            }
        }

        return $scores;
    }
}

/**
 * Aggregates season standings grouped by Division + Gender -> Style
 */
if ( ! function_exists( 'sywat_aggregate_season_standings' ) ) {
    function sywat_aggregate_season_standings( $season_slug ) {
        $seasons = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
        $season  = isset( $seasons[$season_slug] ) ? $seasons[$season_slug] : null;
        $best_n  = ( $season && isset( $season['best_n_count'] ) ) ? intval( $season['best_n_count'] ) : 3;

        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        $matching_tournaments = array();

        foreach ( $all_tournaments as $t_name => $t_data ) {
            $t_season = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $t_data ) : '';
            if ( $t_season === $season_slug ) {
                $matching_tournaments[] = $t_name;
            }
        }

        $groups = array();

        foreach ( $matching_tournaments as $t_name ) {
            $scores = sywat_get_tournament_scores( $t_name );
            $is_state_tourn = ( stripos( $t_name, 'state' ) !== false );

            foreach ( $scores as $s ) {
                $div_gen_key = $s['division'] . '|||' . $s['gender'];
                $style_key   = $s['style'];
                $archer_key  = ! empty( $s['tfaa_id'] ) ? 'id_' . strtolower( $s['tfaa_id'] ) : 'name_' . strtolower( $s['name'] );

                if ( ! isset( $groups[$div_gen_key] ) ) {
                    $groups[$div_gen_key] = array(
                        'division' => $s['division'],
                        'gender'   => $s['gender'],
                        'heading'  => sywat_format_div_gen_heading( $s['division'], $s['gender'] ),
                        'styles'   => array(),
                    );
                }

                if ( ! isset( $groups[$div_gen_key]['styles'][$style_key] ) ) {
                    $groups[$div_gen_key]['styles'][$style_key] = array();
                }

                if ( ! isset( $groups[$div_gen_key]['styles'][$style_key][$archer_key] ) ) {
                    $groups[$div_gen_key]['styles'][$style_key][$archer_key] = array(
                        'name'             => $s['name'],
                        'tfaa_id'          => $s['tfaa_id'],
                        'division'         => $s['division'],
                        'gender'           => $s['gender'],
                        'style'            => $s['style'],
                        'qualifier_scores' => array(),
                        'state_score'      => null,
                    );
                }

                if ( $is_state_tourn ) {
                    $groups[$div_gen_key]['styles'][$style_key][$archer_key]['state_score'] = $s;
                } else {
                    $groups[$div_gen_key]['styles'][$style_key][$archer_key]['qualifier_scores'][] = $s;
                }
            }
        }

        // Sort Division + Gender groups (Cub -> Youth -> Young Adult -> Adult Pro Male -> Adult Male -> etc.)
        uksort( $groups, function( $keyA, $keyB ) {
            list( $divA, $genA ) = explode( '|||', $keyA );
            list( $divB, $genB ) = explode( '|||', $keyB );

            $wA = sywat_get_div_gen_sort_weight( $divA, $genA );
            $wB = sywat_get_div_gen_sort_weight( $divB, $genB );

            if ( $wA !== $wB ) {
                return $wA - $wB;
            }
            return strcasecmp( $keyA, $keyB );
        });

        // Compute scores and sort archers inside each style
        foreach ( $groups as $dg_key => &$group ) {
            ksort( $group['styles'] );

            foreach ( $group['styles'] as $st_key => &$archers ) {
                $processed = array();

                foreach ( $archers as $a_key => $a ) {
                    usort( $a['qualifier_scores'], function( $p, $q ) {
                        if ( $p['score'] === $q['score'] ) {
                            return $q['x_count'] - $p['x_count'];
                        }
                        return $q['score'] - $p['score'];
                    });

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

                // Rank category: Total Score DESC, Total X DESC
                usort( $processed, function( $x, $y ) {
                    if ( $x['total_score'] === $y['total_score'] ) {
                        return $y['total_x_count'] - $x['total_x_count'];
                    }
                    return $y['total_score'] - $x['total_score'];
                });

                $archers = $processed;
            }
            unset($archers);
        }
        unset($group);

        return array(
            'season'    => $season,
            'best_n'    => $best_n,
            'groups'    => $groups,
        );
    }
}
