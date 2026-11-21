<?php
/**
 * Sub-Module: Scores Engine Presentation & Payload Helpers
 * Path: includes/scores/scores-helpers.php
 * Description: Pure presentation formatting, surname alphabetical sorting ("LastName, FirstName"), division/style normalization, canonical shooting line label resolution, and pre-indexed search token generation for the Batch Score Entry application ([sywat_scores]).
 * Compliance: Pure helper layer — zero database queries, zero option/meta lookups, zero HTML echo at include time, O(R) hoisted lookups, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves a canonical shooting line display label from a line configuration entry.
 *
 * @param array|mixed $line Shooting line configuration array.
 * @param int|string  $idx  Array index key for the line.
 * @return string           Formatted shooting line label.
 */
if ( ! function_exists( 'sywat_scores_resolve_line_label' ) ) {
    function sywat_scores_resolve_line_label( $line, $idx ) {
        if ( function_exists( 'sywat_resolve_tournament_line_label' ) ) {
            return (string) sywat_resolve_tournament_line_label( $line, $idx );
        }
        if ( is_array( $line ) && ! empty( $line['name'] ) ) {
            return trim( (string) $line['name'] );
        }
        return 'Line ' . ( intval( $idx ) + 1 );
    }
}

/**
 * Enriches checked-in tournament registrations with formatted surname labels,
 * normalized division/style labels, resolved score/X/INC values, lock status,
 * and comprehensive search tokens, then sorts rows by shooting line and surname.
 *
 * @param array $tourn_registrations Raw checked-in registrations for the active tournament.
 * @param array $scores_map          Master tournament score map keyed by registration ID.
 * @param array $lines               Configured shooting lines for the active tournament.
 * @param array $locked_lines        Map of locked shooting line indices.
 * @return array                     Formatted and sorted registration rows ready for view rendering.
 */
if ( ! function_exists( 'sywat_scores_prepare_rows' ) ) {
    function sywat_scores_prepare_rows( $tourn_registrations, $scores_map, $lines, $locked_lines ) {
        if ( ! is_array( $tourn_registrations ) || empty( $tourn_registrations ) ) {
            return array();
        }

        if ( ! is_array( $scores_map ) ) {
            $scores_map = array();
        }
        if ( ! is_array( $lines ) ) {
            $lines = array();
        }
        if ( ! is_array( $locked_lines ) ) {
            $locked_lines = array();
        }

        // Hoist helper existence checks & pre-index line labels outside the row loop
        $has_lf_formatter  = function_exists( 'sywat_format_name_last_first' );
        $has_norm_division = function_exists( 'sywat_shooting_styles_normalize_division' );
        $has_norm_style    = function_exists( 'sywat_shooting_styles_normalize_style' );
        $has_line_resolver = function_exists( 'sywat_resolve_tournament_line_label' );
        $has_roster_idx_fn = function_exists( 'sywat_roster_resolve_line_idx' );

        $line_labels_by_idx = array();
        foreach ( $lines as $idx => $line ) {
            $idx_str                        = (string) $idx;
            $line_labels_by_idx[ $idx_str ] = $has_line_resolver
                ? (string) sywat_resolve_tournament_line_label( $line, $idx )
                : ( ( is_array( $line ) && ! empty( $line['name'] ) ) ? trim( (string) $line['name'] ) : 'Line ' . ( intval( $idx ) + 1 ) );
        }

        $prepared = array();

        foreach ( $tourn_registrations as $reg ) {
            if ( ! is_array( $reg ) ) {
                continue;
            }

            $reg_id   = isset( $reg['id'] ) ? (string) $reg['id'] : '';
            $raw_name = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : '';

            $disp_archer_name    = $has_lf_formatter ? sywat_format_name_last_first( $raw_name ) : $raw_name;
            $reg['display_name'] = $disp_archer_name;

            $score   = isset( $scores_map[ $reg_id ]['score'] ) ? intval( $scores_map[ $reg_id ]['score'] ) : ( isset( $reg['score'] ) ? intval( $reg['score'] ) : 0 );
            $x_count = isset( $scores_map[ $reg_id ]['xs'] ) ? intval( $scores_map[ $reg_id ]['xs'] ) : ( isset( $reg['x_count'] ) ? intval( $reg['x_count'] ) : 0 );
            $is_inc  = isset( $scores_map[ $reg_id ]['is_inc'] ) ? ! empty( $scores_map[ $reg_id ]['is_inc'] ) : ! empty( $reg['is_inc'] );

            $gender_str = ! empty( $reg['gender'] ) ? trim( (string) $reg['gender'] ) : '—';

            $raw_div = ! empty( $reg['division'] ) ? trim( (string) $reg['division'] ) : '';
            $div_str = ( $raw_div !== '' && $raw_div !== '—' && strcasecmp( $raw_div, 'No Show' ) !== 0 && $has_norm_division )
                ? sywat_shooting_styles_normalize_division( $raw_div )
                : ( $raw_div !== '' ? $raw_div : '—' );

            $raw_style = ! empty( $reg['style'] ) ? trim( (string) $reg['style'] ) : '';
            $style_str = ( $raw_style !== '' && $raw_style !== '—' && strcasecmp( $raw_style, 'No Show' ) !== 0 && $has_norm_style )
                ? sywat_shooting_styles_normalize_style( $raw_style )
                : ( $raw_style !== '' ? $raw_style : '—' );

            if ( isset( $reg['resolved_line_idx'] ) && $reg['resolved_line_idx'] !== '' ) {
                $lidx = (string) $reg['resolved_line_idx'];
            } elseif ( $has_roster_idx_fn ) {
                $lidx = (string) sywat_roster_resolve_line_idx( $reg, $lines );
            } else {
                $lidx = isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0';
            }

            $raw_line_idx = isset( $reg['line_index'] ) ? (string) $reg['line_index'] : $lidx;
            $is_locked    = ! empty( $locked_lines[ $lidx ] ) || ! empty( $locked_lines[ $raw_line_idx ] );

            $line_disp = isset( $line_labels_by_idx[ $lidx ] )
                ? $line_labels_by_idx[ $lidx ]
                : ( ! empty( $reg['line_name'] ) ? trim( (string) $reg['line_name'] ) : 'Line ' . ( intval( $lidx ) + 1 ) );

            $search_tokens = strtolower(
                trim(
                    preg_replace(
                        '/\s+/',
                        ' ',
                        $disp_archer_name . ' ' . $raw_name . ' ' . $line_disp . ' ' . $div_str . ' ' . $style_str . ' ' . $gender_str
                    )
                )
            );

            $reg['resolved_line_idx'] = $lidx;
            $reg['row_display']       = array(
                'id'            => $reg_id,
                'name'          => $disp_archer_name,
                'line_idx'      => $lidx,
                'line_label'    => $line_disp,
                'gender'        => $gender_str,
                'division'      => $div_str,
                'style'         => $style_str,
                'score'         => $score,
                'x_count'       => $x_count,
                'is_inc'        => $is_inc,
                'is_locked'     => $is_locked,
                'search_tokens' => $search_tokens,
            );

            $prepared[] = $reg;
        }

        usort( $prepared, function( $a, $b ) {
            $line_a = isset( $a['resolved_line_idx'] ) ? (int) $a['resolved_line_idx'] : ( isset( $a['line_index'] ) ? (int) $a['line_index'] : 99 );
            $line_b = isset( $b['resolved_line_idx'] ) ? (int) $b['resolved_line_idx'] : ( isset( $b['line_index'] ) ? (int) $b['line_index'] : 99 );
            if ( $line_a !== $line_b ) {
                return $line_a <=> $line_b;
            }
            $disp_a = isset( $a['display_name'] ) ? trim( (string) $a['display_name'] ) : ( isset( $a['name'] ) ? trim( (string) $a['name'] ) : '' );
            $disp_b = isset( $b['display_name'] ) ? trim( (string) $b['display_name'] ) : ( isset( $b['name'] ) ? trim( (string) $b['name'] ) : '' );
            return strcasecmp( $disp_a, $disp_b );
        } );

        return $prepared;
    }
}
