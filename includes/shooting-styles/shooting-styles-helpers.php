<?php
/**
 * Sub-Module: Shooting Styles & Divisions Global Helpers
 * Path: includes/shooting-styles/shooting-styles-helpers.php
 * Description: Provides centralized helper functions for active dropdown options, O(1)-cached style/alias matching, division/age rule enforcement (all, restrict, force), CSV export codes, leaderboard sort weights, and JS localization payloads.
 * Author: SYWAT Archery
 * Version: 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Returns an ordered list of active shooting style labels for front-end dropdowns.
 *
 * @param bool $include_no_show Whether to append 'No Show' (used on Check-In Desk modals).
 * @return array Ordered list of style label strings.
 */
if ( ! function_exists( 'sywat_get_shooting_style_options' ) ) {
    function sywat_get_shooting_style_options( $include_no_show = false ) {
        $styles  = function_exists( 'sywat_get_shooting_styles' ) ? sywat_get_shooting_styles( false ) : array();
        $options = array();

        foreach ( $styles as $item ) {
            if ( ! empty( $item['label'] ) ) {
                $options[] = $item['label'];
            }
        }

        if ( $include_no_show && ! in_array( 'No Show', $options, true ) ) {
            $options[] = 'No Show';
        }

        return $options;
    }
}

/**
 * Finds a configured shooting style record by label, code, slug, or alias.
 * Uses a request-scoped hashtable cache for O(1) lookups across large datasets.
 *
 * @param string $raw_style Raw style text from form input, registration record, or TFAA sync.
 * @return array|null Matching style record array, or null if unmatched.
 */
if ( ! function_exists( 'sywat_find_shooting_style' ) ) {
    function sywat_find_shooting_style( $raw_style ) {
        static $lookup_cache = array();
        static $sorted_aliases = null;

        $needle = trim( (string) $raw_style );
        if ( $needle === '' ) {
            return null;
        }

        $cache_key = strtolower( $needle );
        if ( array_key_exists( $cache_key, $lookup_cache ) ) {
            return $lookup_cache[ $cache_key ];
        }

        $styles = function_exists( 'sywat_get_shooting_styles' ) ? sywat_get_shooting_styles( true ) : array();
        if ( empty( $styles ) ) {
            $lookup_cache[ $cache_key ] = null;
            return null;
        }

        // 1. Exact case-insensitive match on label, slug, or code
        foreach ( $styles as $item ) {
            if ( strcasecmp( $needle, $item['label'] ) === 0 ) {
                $lookup_cache[ $cache_key ] = $item;
                return $item;
            }
            if ( strcasecmp( $needle, $item['slug'] ) === 0 ) {
                $lookup_cache[ $cache_key ] = $item;
                return $item;
            }
            if ( ! empty( $item['code'] ) && strcasecmp( $needle, $item['code'] ) === 0 ) {
                $lookup_cache[ $cache_key ] = $item;
                return $item;
            }
        }

        // 2. Exact case-insensitive match on configured aliases
        foreach ( $styles as $item ) {
            if ( ! empty( $item['aliases'] ) && is_array( $item['aliases'] ) ) {
                foreach ( $item['aliases'] as $alias ) {
                    if ( strcasecmp( $needle, $alias ) === 0 ) {
                        $lookup_cache[ $cache_key ] = $item;
                        return $item;
                    }
                }
            }
        }

        // 3. Prefix match before dash (e.g., "FSL-R – ..." vs "FSLR")
        $needle_prefix = trim( preg_split( '/\s*[–-]\s*/u', $needle )[0] );
        if ( $needle_prefix !== '' ) {
            foreach ( $styles as $item ) {
                $item_prefix = trim( preg_split( '/\s*[–-]\s*/u', $item['label'] )[0] );
                if ( strcasecmp( $needle_prefix, $item_prefix ) === 0 ) {
                    $lookup_cache[ $cache_key ] = $item;
                    return $item;
                }
            }
        }

        // 4. Hoisted longest-first alias candidates for substring matching
        if ( null === $sorted_aliases ) {
            $sorted_aliases = array();
            foreach ( $styles as $item ) {
                $sorted_aliases[] = array( 'term' => $item['label'], 'record' => $item );
                if ( ! empty( $item['aliases'] ) && is_array( $item['aliases'] ) ) {
                    foreach ( $item['aliases'] as $alias ) {
                        if ( strlen( $alias ) >= 2 ) {
                            $sorted_aliases[] = array( 'term' => $alias, 'record' => $item );
                        }
                    }
                }
            }

            usort( $sorted_aliases, function( $a, $b ) {
                return strlen( $b['term'] ) <=> strlen( $a['term'] );
            } );
        }

        foreach ( $sorted_aliases as $cand ) {
            if ( stripos( $needle, $cand['term'] ) !== false ) {
                $lookup_cache[ $cache_key ] = $cand['record'];
                return $cand['record'];
            }
        }

        $lookup_cache[ $cache_key ] = null;
        return null;
    }
}

/**
 * Returns the default fallback shooting style label (first active 'all'-division style, or first active style).
 *
 * @return string Canonical fallback style label.
 */
if ( ! function_exists( 'sywat_get_default_shooting_style_label' ) ) {
    function sywat_get_default_shooting_style_label() {
        $styles = function_exists( 'sywat_get_shooting_styles' ) ? sywat_get_shooting_styles( false ) : array();
        foreach ( $styles as $item ) {
            if ( $item['rule_mode'] === 'all' && ! empty( $item['label'] ) ) {
                return $item['label'];
            }
        }
        if ( ! empty( $styles ) ) {
            $first = reset( $styles );
            return ! empty( $first['label'] ) ? $first['label'] : 'FS – Freestyle';
        }
        return 'FS – Freestyle';
    }
}

/**
 * Enforces configured division/age rules for a style + division pair.
 *
 * - 'all'      : Keeps both style and division as-is.
 * - 'restrict' : If division is not in 'allowed_divisions', resets style to the default fallback style.
 * - 'force'    : Automatically overrides division to 'forced_division' (e.g., LB – Longbow -> Adult (18 - 49)).
 *
 * @param string $style    Submitted or existing shooting style.
 * @param string $division Submitted or existing age division.
 * @return array Associative array with resolved 'style', 'division', 'distance', and 'rate_tier'.
 */
if ( ! function_exists( 'sywat_resolve_style_and_division' ) ) {
    function sywat_resolve_style_and_division( $style, $division ) {
        $clean_style = trim( (string) $style );
        $clean_div   = function_exists( 'sywat_shooting_styles_normalize_division' )
            ? sywat_shooting_styles_normalize_division( $division )
            : trim( (string) $division );

        if ( strcasecmp( $clean_div, 'No Show' ) === 0 || strcasecmp( $clean_style, 'No Show' ) === 0 ) {
            return array(
                'style'     => 'No Show',
                'division'  => 'No Show',
                'distance'  => '20yd',
                'rate_tier' => 'Adult',
            );
        }

        $matched_style = sywat_find_shooting_style( $clean_style );
        if ( $matched_style ) {
            $clean_style = $matched_style['label'];
            $rule_mode   = $matched_style['rule_mode'];

            if ( $rule_mode === 'force' && ! empty( $matched_style['forced_division'] ) ) {
                $clean_div = $matched_style['forced_division'];
            } elseif ( $rule_mode === 'restrict' ) {
                $allowed = ! empty( $matched_style['allowed_divisions'] ) ? $matched_style['allowed_divisions'] : array();
                if ( $clean_div !== '' && ! in_array( $clean_div, $allowed, true ) ) {
                    $clean_style = sywat_get_default_shooting_style_label();
                }
            }
        }

        // Resolve calculated distance & Youth/Adult rate tier from database division record
        $distance  = ( $clean_div === 'Cub (8 - 11)' ) ? 'Cub' : '20yd';
        $rate_tier = in_array( $clean_div, array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' ), true ) ? 'Youth' : 'Adult';

        if ( function_exists( 'sywat_get_division_records' ) ) {
            foreach ( sywat_get_division_records( true ) as $d_rec ) {
                if ( strcasecmp( $clean_div, $d_rec['label'] ) === 0 ) {
                    $distance  = $d_rec['distance'];
                    $rate_tier = $d_rec['rate_tier'];
                    break;
                }
            }
        }

        return array(
            'style'     => $clean_style,
            'division'  => $clean_div,
            'distance'  => $distance,
            'rate_tier' => $rate_tier,
        );
    }
}

/**
 * Backward-compatible wrapper returning only the validated shooting style string.
 *
 * @param string $style    Submitted shooting style.
 * @param string $division Submitted division.
 * @return string Validated shooting style label.
 */
if ( ! function_exists( 'sywat_sanitize_style_for_division' ) ) {
    function sywat_sanitize_style_for_division( $style, $division ) {
        $resolved = sywat_resolve_style_and_division( $style, $division );
        return $resolved['style'];
    }
}

/**
 * Resolves the short NFAA CSV export code (e.g., FS, BHFS, LB, GEN) for a shooting style.
 *
 * @param string $raw_style Style label or text.
 * @return string Uppercase export code.
 */
if ( ! function_exists( 'sywat_get_style_export_code' ) ) {
    function sywat_get_style_export_code( $raw_style ) {
        $matched = sywat_find_shooting_style( $raw_style );
        if ( $matched && ! empty( $matched['code'] ) ) {
            return strtoupper( $matched['code'] );
        }

        $parts = preg_split( '/\s*[–-]\s*/u', trim( (string) $raw_style ) );
        return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $parts[0] ) );
    }
}

/**
 * Resolves the 1-to-2 letter CSV export code for an age division (e.g., C, Y, YA, A, S, SS, MS).
 *
 * @param string $raw_division Division label or text.
 * @return string Uppercase division export code.
 */
if ( ! function_exists( 'sywat_get_division_export_code' ) ) {
    function sywat_get_division_export_code( $raw_division ) {
        static $div_code_cache = array();

        $clean = trim( (string) $raw_division );
        if ( $clean === '' ) {
            return '';
        }

        $key = strtolower( $clean );
        if ( array_key_exists( $key, $div_code_cache ) ) {
            return $div_code_cache[ $key ];
        }

        $norm = function_exists( 'sywat_shooting_styles_normalize_division' )
            ? sywat_shooting_styles_normalize_division( $clean )
            : $clean;

        if ( function_exists( 'sywat_get_division_records' ) ) {
            foreach ( sywat_get_division_records( true ) as $d_rec ) {
                if ( strcasecmp( $norm, $d_rec['label'] ) === 0 && ! empty( $d_rec['code'] ) ) {
                    $div_code_cache[ $key ] = strtoupper( $d_rec['code'] );
                    return $div_code_cache[ $key ];
                }
            }
        }

        $div_code_cache[ $key ] = '';
        return '';
    }
}

/**
 * Determines whether a style or division represents a Pro category.
 *
 * @param string $raw_style    Style label.
 * @param string $raw_division Optional division label.
 * @return bool True if Pro category.
 */
if ( ! function_exists( 'sywat_is_pro_style' ) ) {
    function sywat_is_pro_style( $raw_style, $raw_division = '' ) {
        if ( stripos( (string) $raw_style, 'Pro' ) !== false || stripos( (string) $raw_division, 'Pro' ) !== false ) {
            return true;
        }
        $matched = sywat_find_shooting_style( $raw_style );
        return ( $matched && ! empty( $matched['is_pro'] ) );
    }
}

/**
 * Returns numeric sort weight for a shooting style based on database sort_order (Guest sorts last).
 *
 * @param string $style_name Style label.
 * @return int Numeric sort weight.
 */
if ( ! function_exists( 'sywat_get_dynamic_style_sort_weight' ) ) {
    function sywat_get_dynamic_style_sort_weight( $style_name ) {
        $st = strtolower( trim( (string) $style_name ) );
        if ( $st === 'guest' || strpos( $st, 'guest' ) !== false ) {
            return 999;
        }

        $matched = sywat_find_shooting_style( $style_name );
        if ( $matched && isset( $matched['sort_order'] ) ) {
            return intval( $matched['sort_order'] );
        }

        return 500;
    }
}

/**
 * Builds the localized JavaScript configuration payload for front-end dropdown enforcement.
 *
 * @return array Associative payload for wp_localize_script().
 */
if ( ! function_exists( 'sywat_get_shooting_styles_js_payload' ) ) {
    function sywat_get_shooting_styles_js_payload() {
        $styles_map = function_exists( 'sywat_get_shooting_styles' ) ? sywat_get_shooting_styles( false ) : array();
        $div_recs   = function_exists( 'sywat_get_division_records' ) ? sywat_get_division_records( false ) : array();

        $styles_list = array();
        foreach ( $styles_map as $item ) {
            $styles_list[] = array(
                'slug'              => $item['slug'],
                'code'              => $item['code'],
                'label'             => $item['label'],
                'aliases'           => $item['aliases'],
                'is_pro'            => (bool) $item['is_pro'],
                'rule_mode'         => $item['rule_mode'],
                'allowed_divisions' => $item['allowed_divisions'],
                'forced_division'   => $item['forced_division'],
            );
        }

        $divisions_list = array();
        foreach ( $div_recs as $d_item ) {
            $divisions_list[] = array(
                'slug'      => $d_item['slug'],
                'label'     => $d_item['label'],
                'code'      => $d_item['code'],
                'distance'  => $d_item['distance'],
                'rate_tier' => $d_item['rate_tier'],
            );
        }

        return array(
            'defaultStyle' => sywat_get_default_shooting_style_label(),
            'styles'       => $styles_list,
            'divisions'    => $divisions_list,
        );
    }
}
