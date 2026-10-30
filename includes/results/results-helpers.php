<?php
/**
 * Sub-Module: Results & Standings Helpers
 * Path: includes/results/results-helpers.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fetch all completed/published score records for a given tournament
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
 * Aggregates season standings across all tournaments linked to a season_slug
 */
if ( ! function_exists( 'sywat_aggregate_season_standings' ) ) {
    function sywat_aggregate_season_standings( $season_slug ) {
        $seasons = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
        $season  = isset( $seasons[$season_slug] ) ? $seasons[$season_slug] : null;
        $best_n  = ( $season && isset( $season['best_n_count'] ) ) ? intval( $season['best_n_count'] ) : 3;

        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        $matching_tournaments = array();

        // Collect tournaments matching this season
        foreach ( $all_tournaments as $t_name => $t_data ) {
            $t_season = function_exists( 'sywat_get_tournament_season_slug' ) ? sywat_get_tournament_season_slug( $t_data ) : '';
            if ( $t_season === $season_slug ) {
                $matching_tournaments[] = $t_name;
            }
        }

        // Group 1: Buckets by Division -> Gender -> Style
        $categories = array();

        foreach ( $matching_tournaments as $t_name ) {
            $scores = sywat_get_tournament_scores( $t_name );

            foreach ( $scores as $s ) {
                $cat_key = $s['division'] . '|||' . $s['gender'] . '|||' . $s['style'];
                
                // Group 2: Archer Identity (Primary: TFAA ID, Fallback: Normalized Name)
                $archer_key = ! empty( $s['tfaa_id'] ) ? 'id_' . strtolower( $s['tfaa_id'] ) : 'name_' . strtolower( $s['name'] );

                if ( ! isset( $categories[$cat_key] ) ) {
                    $categories[$cat_key] = array();
                }

                if ( ! isset( $categories[$cat_key][$archer_key] ) ) {
                    $categories[$cat_key][$archer_key] = array(
                        'name'        => $s['name'],
                        'tfaa_id'     => $s['tfaa_id'],
                        'division'    => $s['division'],
                        'gender'      => $s['gender'],
                        'style'       => $s['style'],
                        'scores_list' => array(),
                    );
                }

                $categories[$cat_key][$archer_key]['scores_list'][] = array(
                    'score'      => $s['score'],
                    'x_count'    => $s['x_count'],
                    'tournament' => $t_name,
                );
            }
        }

        // Calculate Best-N Totals for each archer
        $final_standings = array();

        foreach ( $categories as $cat_key => $archers ) {
            $final_standings[$cat_key] = array();

            foreach ( $archers as $archer_key => $a ) {
                // Sort archer's individual tournament scores: Score DESC, X-Count DESC
                usort( $a['scores_list'], function( $p, $q ) {
                    if ( $p['score'] === $q['score'] ) {
                        return $q['x_count'] - $p['x_count'];
                    }
                    return $q['score'] - $p['score'];
                });

                $scores_kept    = array_slice( $a['scores_list'], 0, $best_n );
                $scores_dropped = array_slice( $a['scores_list'], $best_n );

                $total_score   = 0;
                $total_x_count = 0;

                foreach ( $scores_kept as $k ) {
                    $total_score   += $k['score'];
                    $total_x_count += $k['x_count'];
                }

                $final_standings[$cat_key][] = array(
                    'name'             => $a['name'],
                    'tfaa_id'          => $a['tfaa_id'],
                    'division'         => $a['division'],
                    'gender'           => $a['gender'],
                    'style'            => $a['style'],
                    'tournaments_shot' => count( $a['scores_list'] ),
                    'scores_kept'      => $scores_kept,
                    'scores_dropped'   => $scores_dropped,
                    'total_score'      => $total_score,
                    'total_x_count'    => $total_x_count,
                    'is_qualified'     => ( count( $a['scores_list'] ) >= $best_n ),
                );
            }

            // Sort Category Standings: 1st Total Score DESC, 2nd Total X Count DESC, 3rd Tournaments Shot DESC
            usort( $final_standings[$cat_key], function( $x, $y ) {
                if ( $x['total_score'] === $y['total_score'] ) {
                    if ( $x['total_x_count'] === $y['total_x_count'] ) {
                        return $y['tournaments_shot'] - $x['tournaments_shot'];
                    }
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
