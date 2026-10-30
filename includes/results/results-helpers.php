<?php
/**
 * Sub-Module: Results & Standings Helpers
 * Path: includes/results/results-helpers.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_get_division_weight' ) ) {
    function sywat_get_division_weight( $div ) {
        $d = strtolower( trim( $div ) );
        if ( strpos( $d, 'cub' ) !== false ) return 1;
        if ( strpos( $d, 'young adult' ) !== false ) return 3;
        if ( strpos( $d, 'youth' ) !== false ) return 2;
        if ( strpos( $d, 'master senior' ) !== false ) return 7;
        if ( strpos( $d, 'silver senior' ) !== false ) return 6;
        if ( strpos( $d, 'senior' ) !== false ) return 5;
        if ( strpos( $d, 'adult' ) !== false ) return 4;
        return 99;
    }
}

if ( ! function_exists( 'sywat_get_gender_weight' ) ) {
    function sywat_get_gender_weight( $gender ) {
        $g = strtolower( trim( $gender ) );
        if ( strpos( $g, 'female' ) !== false ) return 2;
        if ( strpos( $g, 'male' ) !== false ) return 1;
        return 3;
    }
}

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

        $categories = array();

        foreach ( $matching_tournaments as $t_name ) {
            $scores = sywat_get_tournament_scores( $t_name );
            $is_state_tourn = ( stripos( $t_name, 'state' ) !== false );

            foreach ( $scores as $s ) {
                $cat_key   = $s['division'] . '|||' . $s['gender'] . '|||' . $s['style'];
                $archer_key = ! empty( $s['tfaa_id'] ) ? 'id_' . strtolower( $s['tfaa_id'] ) : 'name_' . strtolower( $s['name'] );

                if ( ! isset( $categories[$cat_key] ) ) {
                    $categories[$cat_key] = array();
                }

                if ( ! isset( $categories[$cat_key][$archer_key] ) ) {
                    $categories[$cat_key][$archer_key] = array(
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
                    $categories[$cat_key][$archer_key]['state_score'] = $s;
                } else {
                    $categories[$cat_key][$archer_key]['qualifier_scores'][] = $s;
                }
            }
        }

        // Sort categories logically: Cubs -> Youth -> Young Adult -> Adult -> Senior (Male first, then Female)
        uksort( $categories, function( $keyA, $keyB ) {
            list( $divA, $genA, $styleA ) = explode( '|||', $keyA );
            list( $divB, $genB, $styleB ) = explode( '|||', $keyB );

            $w_divA = sywat_get_division_weight( $divA );
            $w_divB = sywat_get_division_weight( $divB );

            if ( $w_divA !== $w_divB ) {
                return $w_divA - $w_divB;
            }

            $w_genA = sywat_get_gender_weight( $genA );
            $w_genB = sywat_get_gender_weight( $genB );

            if ( $w_genA !== $w_genB ) {
                return $w_genA - $w_genB;
            }

            return strcasecmp( $styleA, $styleB );
        });

        // Compute top N scores, state score, and totals
        $final_standings = array();

        foreach ( $categories as $cat_key => $archers ) {
            $final_standings[$cat_key] = array();

            foreach ( $archers as $archer_key => $a ) {
                // Sort qualifiers: Score DESC, X-Count DESC
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

                $final_standings[$cat_key][] = array(
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
            usort( $final_standings[$cat_key], function( $x, $y ) {
                if ( $x['total_score'] === $y['total_score'] ) {
                    return $y['total_x_count'] - $x['total_x_count'];
                }
                return $y['total_score'] - $x['total_score'];
            });
        }

        return array(
            'season'    => $season,
            'best_n'    => $best_n,
            'standings' => $final_standings,
        );
    }
}
