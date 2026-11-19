<?php
/**
 * Sub-Module: Results & Standings Formatting & Sort Helpers
 * Path: includes/results/results-helpers.php
 * Description: Pure helper layer providing O(1) static per-request lookup maps for Age Divisions and Shooting Styles, category heading formatters, and database-driven sort-weight calculators.
 * Compliance: Pure helper layer — zero direct SQL queries, zero get_option() calls, zero HTML echo, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.3.0
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
 * Cleanly formats Division, Gender, and Style into a single unified category header.
 * Example: Pro Adult (18 - 49) Male Freestyle
 * Example: Cub (8 - 11) Male Bowhunter Freestyle
 *
 * @param string $division Division string.
 * @param string $gender   Gender string.
 * @param string $style    Shooting style string.
 * @return string          Formatted category header.
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
 *
 * @param string $div    Division string.
 * @param string $gender Gender string.
 * @return int           Numeric sort weight.
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
 *
 * @param string $style_name Shooting style string.
 * @return int               Numeric sort weight.
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
 *
 * @param string $division Division string.
 * @param string $gender   Gender string.
 * @return string          Formatted Division + Gender header.
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
