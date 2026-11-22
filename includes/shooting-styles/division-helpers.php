<?php
/**
 * Sub-Module: Age Division Lookup, Distance & Rate Tier Helpers
 * Path: includes/shooting-styles/division-helpers.php
 * Description: Provides O(1)-cached age division lookup (sywat_find_division) by label, slug, export code, alias, or hyphen/spacing variant, plus database-driven division rate tier ('Youth' vs. 'Adult') and shooting distance ('Cub' vs. '20yd') resolvers.
 * Compliance: Pure helper layer — zero direct SQL queries, zero get_option() calls, zero HTML echo at include time, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normalize a division string, code, or alias into a consistent lowercase lookup key.
 *
 * @param string $raw_value Raw division label, code, or alias.
 * @return string           Normalized lookup token.
 */
if ( ! function_exists( 'sywat_normalize_division_lookup_token' ) ) {
    function sywat_normalize_division_lookup_token( $raw_value ) {
        $s = strtolower( trim( (string) $raw_value ) );
        $s = str_replace( array( "\xE2\x80\x93", "\xE2\x80\x94", '–', '—' ), '-', $s );
        $s = preg_replace( '/\s*-\s*/', '-', $s );
        $s = preg_replace( '/\s+/', ' ', $s );
        return trim( $s );
    }
}

/**
 * Finds a configured age division record by label, slug, export code, alias, or hyphen/spacing variant.
 * Uses a request-scoped hashtable cache for O(1) lookups across large datasets.
 *
 * @param string $raw_division Raw division text from form input, registration record, or TFAA sync.
 * @return array|null Matching division record array, or null if unmatched.
 */
if ( ! function_exists( 'sywat_find_division' ) ) {
    function sywat_find_division( $raw_division ) {
        static $lookup_cache = array();
        static $index_map    = null;

        $needle = trim( (string) $raw_division );
        if ( $needle === '' || $needle === '—' || strcasecmp( $needle, 'No Show' ) === 0 ) {
            return null;
        }

        $cache_key = strtolower( $needle );
        if ( array_key_exists( $cache_key, $lookup_cache ) ) {
            return $lookup_cache[ $cache_key ];
        }

        if ( null === $index_map ) {
            $index_map = array();
            $divisions = function_exists( 'sywat_get_division_records' ) ? sywat_get_division_records( true ) : array();

            if ( is_array( $divisions ) ) {
                foreach ( $divisions as $rec ) {
                    if ( ! is_array( $rec ) || empty( $rec['label'] ) ) {
                        continue;
                    }

                    $candidates = array( $rec['label'] );
                    if ( ! empty( $rec['slug'] ) ) {
                        $candidates[] = $rec['slug'];
                    }
                    if ( ! empty( $rec['code'] ) ) {
                        $candidates[] = $rec['code'];
                    }
                    if ( ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ) {
                        foreach ( $rec['aliases'] as $alias ) {
                            $candidates[] = $alias;
                        }
                    }

                    $base_label = trim( preg_replace( '/\s*\(.*?\)\s*/', ' ', (string) $rec['label'] ) );
                    if ( $base_label !== '' ) {
                        $candidates[] = $base_label;
                    }

                    foreach ( $candidates as $cand ) {
                        $raw_k  = strtolower( trim( (string) $cand ) );
                        $norm_k = sywat_normalize_division_lookup_token( $cand );
                        if ( $raw_k !== '' && ! isset( $index_map[ $raw_k ] ) ) {
                            $index_map[ $raw_k ] = $rec;
                        }
                        if ( $norm_k !== '' && ! isset( $index_map[ $norm_k ] ) ) {
                            $index_map[ $norm_k ] = $rec;
                        }
                    }
                }
            }
        }

        if ( isset( $index_map[ $cache_key ] ) ) {
            $lookup_cache[ $cache_key ] = $index_map[ $cache_key ];
            return $index_map[ $cache_key ];
        }

        $norm_needle = sywat_normalize_division_lookup_token( $needle );
        if ( isset( $index_map[ $norm_needle ] ) ) {
            $lookup_cache[ $cache_key ] = $index_map[ $norm_needle ];
            return $index_map[ $norm_needle ];
        }

        $base_needle = trim( preg_replace( '/\s*\(.*?\)\s*/', ' ', $norm_needle ) );
        if ( $base_needle !== '' && isset( $index_map[ $base_needle ] ) ) {
            $lookup_cache[ $cache_key ] = $index_map[ $base_needle ];
            return $index_map[ $base_needle ];
        }

        $lookup_cache[ $cache_key ] = null;
        return null;
    }
}

/**
 * Resolves the individual fee tier ('Youth' or 'Adult') for an age division
 * using database-configured division records with safe NFAA fallback.
 *
 * @param string $division Age division label, code, or alias.
 * @return string          'Youth' or 'Adult'.
 */
if ( ! function_exists( 'sywat_shooting_styles_get_division_rate_tier' ) ) {
    function sywat_shooting_styles_get_division_rate_tier( $division ) {
        $clean = trim( (string) $division );
        if ( $clean === '' || strcasecmp( $clean, 'No Show' ) === 0 ) {
            return 'Adult';
        }

        $div_rec = sywat_find_division( $clean );
        if ( is_array( $div_rec ) && isset( $div_rec['rate_tier'] ) ) {
            return ( $div_rec['rate_tier'] === 'Youth' ) ? 'Youth' : 'Adult';
        }

        if (
            strcasecmp( $clean, 'C' ) === 0 ||
            strcasecmp( $clean, 'Y' ) === 0 ||
            strcasecmp( $clean, 'YA' ) === 0 ||
            stripos( $clean, 'Cub' ) !== false ||
            stripos( $clean, 'Youth' ) !== false ||
            stripos( $clean, 'Young Adult' ) !== false
        ) {
            return 'Youth';
        }

        return 'Adult';
    }
}

/**
 * Resolves the shooting distance ('Cub' or '20yd') for an age division
 * using database-configured division records with safe NFAA fallback.
 *
 * @param string $division Age division label, code, or alias.
 * @return string          'Cub' or '20yd'.
 */
if ( ! function_exists( 'sywat_shooting_styles_get_division_distance' ) ) {
    function sywat_shooting_styles_get_division_distance( $division ) {
        $clean = trim( (string) $division );
        if ( $clean === '' || strcasecmp( $clean, 'No Show' ) === 0 ) {
            return '20yd';
        }

        $div_rec = sywat_find_division( $clean );
        if ( is_array( $div_rec ) && isset( $div_rec['distance'] ) ) {
            return ( $div_rec['distance'] === 'Cub' ) ? 'Cub' : '20yd';
        }

        if ( strcasecmp( $clean, 'C' ) === 0 || stripos( $clean, 'Cub' ) !== false ) {
            return 'Cub';
        }

        return '20yd';
    }
}
