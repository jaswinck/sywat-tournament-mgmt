<?php
/**
 * Sub-Module: Shooting Styles & Divisions Database Layer
 * Path: includes/shooting-styles/shooting-styles-data.php
 * Description: Manages persistent WordPress option storage (sywat_shooting_styles & sywat_divisions) with one-time NFAA seed initialization so all styles, divisions, and age rules can be added, edited, retired, or deleted from the GUI without code changes.
 * Author: SYWAT Archery
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One-time seed dataset for canonical SYWAT / TFAA age divisions.
 *
 * @return array Associative array keyed by division slug.
 */
if ( ! function_exists( 'sywat_shooting_styles_get_default_divisions_seed' ) ) {
    function sywat_shooting_styles_get_default_divisions_seed() {
        return array(
            'cub' => array(
                'slug'       => 'cub',
                'label'      => 'Cub (8 - 11)',
                'code'       => 'C',
                'aliases'    => array( 'Cub' ),
                'distance'   => 'Cub',
                'rate_tier'  => 'Youth',
                'sort_order' => 1,
                'status'     => 'active',
            ),
            'youth' => array(
                'slug'       => 'youth',
                'label'      => 'Youth (12 - 14)',
                'code'       => 'Y',
                'aliases'    => array( 'Youth' ),
                'distance'   => '20yd',
                'rate_tier'  => 'Youth',
                'sort_order' => 2,
                'status'     => 'active',
            ),
            'young-adult' => array(
                'slug'       => 'young-adult',
                'label'      => 'Young Adult (15 - 17)',
                'code'       => 'YA',
                'aliases'    => array( 'Young Adult' ),
                'distance'   => '20yd',
                'rate_tier'  => 'Youth',
                'sort_order' => 3,
                'status'     => 'active',
            ),
            'adult' => array(
                'slug'       => 'adult',
                'label'      => 'Adult (18 - 49)',
                'code'       => 'A',
                'aliases'    => array( 'Adult' ),
                'distance'   => '20yd',
                'rate_tier'  => 'Adult',
                'sort_order' => 4,
                'status'     => 'active',
            ),
            'senior' => array(
                'slug'       => 'senior',
                'label'      => 'Senior (50 - 59)',
                'code'       => 'S',
                'aliases'    => array( 'Senior' ),
                'distance'   => '20yd',
                'rate_tier'  => 'Adult',
                'sort_order' => 5,
                'status'     => 'active',
            ),
            'silver-senior' => array(
                'slug'       => 'silver-senior',
                'label'      => 'Silver Senior (60 - 69)',
                'code'       => 'SS',
                'aliases'    => array( 'Silver Senior' ),
                'distance'   => '20yd',
                'rate_tier'  => 'Adult',
                'sort_order' => 6,
                'status'     => 'active',
            ),
            'master-senior' => array(
                'slug'       => 'master-senior',
                'label'      => 'Master Senior (70+)',
                'code'       => 'MS',
                'aliases'    => array( 'Master Senior' ),
                'distance'   => '20yd',
                'rate_tier'  => 'Adult',
                'sort_order' => 7,
                'status'     => 'active',
            ),
        );
    }
}

/**
 * Normalizes a single division record from the database.
 *
 * @param string $slug   Division slug key.
 * @param array  $record Raw division array.
 * @return array Normalized division record.
 */
if ( ! function_exists( 'sywat_shooting_styles_normalize_division_record' ) ) {
    function sywat_shooting_styles_normalize_division_record( $slug, $record ) {
        $clean_slug = sanitize_title( ! empty( $record['slug'] ) ? $record['slug'] : $slug );
        if ( $clean_slug === '' ) {
            $clean_slug = 'div-' . substr( md5( uniqid( '', true ) ), 0, 6 );
        }

        $label = isset( $record['label'] ) ? trim( (string) $record['label'] ) : '';
        $code  = isset( $record['code'] ) ? strtoupper( trim( (string) $record['code'] ) ) : '';

        $aliases = array();
        if ( isset( $record['aliases'] ) ) {
            $raw_aliases = is_array( $record['aliases'] ) ? $record['aliases'] : explode( ',', (string) $record['aliases'] );
            foreach ( $raw_aliases as $al ) {
                $trimmed = trim( (string) $al );
                if ( $trimmed !== '' && ! in_array( $trimmed, $aliases, true ) ) {
                    $aliases[] = $trimmed;
                }
            }
        }

        $distance  = ( isset( $record['distance'] ) && $record['distance'] === 'Cub' ) ? 'Cub' : '20yd';
        $rate_tier = ( isset( $record['rate_tier'] ) && $record['rate_tier'] === 'Youth' ) ? 'Youth' : 'Adult';
        $status    = ( isset( $record['status'] ) && $record['status'] === 'retired' ) ? 'retired' : 'active';

        return array(
            'slug'       => $clean_slug,
            'label'      => $label,
            'code'       => $code,
            'aliases'    => $aliases,
            'distance'   => $distance,
            'rate_tier'  => $rate_tier,
            'sort_order' => isset( $record['sort_order'] ) ? max( 1, intval( $record['sort_order'] ) ) : 50,
            'status'     => $status,
        );
    }
}

/**
 * Retrieves configured division records from the database, seeding once if the option does not exist yet.
 *
 * @param bool $include_retired Whether to include retired divisions.
 * @return array Ordered associative array of division records keyed by slug.
 */
if ( ! function_exists( 'sywat_get_division_records' ) ) {
    function sywat_get_division_records( $include_retired = false ) {
        $raw = get_option( 'sywat_divisions', false );

        if ( false === $raw ) {
            $raw = sywat_shooting_styles_get_default_divisions_seed();
            update_option( 'sywat_divisions', $raw, false );
        }

        if ( ! is_array( $raw ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $raw as $slug => $record ) {
            if ( ! is_array( $record ) || empty( $record['label'] ) ) {
                continue;
            }
            $item = sywat_shooting_styles_normalize_division_record( $slug, $record );
            if ( ! $include_retired && $item['status'] === 'retired' ) {
                continue;
            }
            $normalized[ $item['slug'] ] = $item;
        }

        uasort( $normalized, function( $a, $b ) {
            if ( $a['sort_order'] !== $b['sort_order'] ) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            return strnatcasecmp( $a['label'], $b['label'] );
        } );

        return $normalized;
    }
}

/**
 * Returns an ordered list of division display labels from the database.
 *
 * @param bool $include_retired Whether to include retired divisions.
 * @return array Ordered list of division label strings.
 */
if ( ! function_exists( 'sywat_shooting_styles_get_divisions' ) ) {
    function sywat_shooting_styles_get_divisions( $include_retired = false ) {
        $records = sywat_get_division_records( $include_retired );
        $labels  = array();
        foreach ( $records as $rec ) {
            $labels[] = $rec['label'];
        }
        return $labels;
    }
}

/**
 * Persists division records to WordPress options.
 *
 * @param array $divisions Associative array of division records.
 * @return bool True on successful option update.
 */
if ( ! function_exists( 'sywat_save_divisions' ) ) {
    function sywat_save_divisions( $divisions ) {
        if ( ! is_array( $divisions ) ) {
            return false;
        }

        $clean_map = array();
        foreach ( $divisions as $slug => $record ) {
            if ( ! is_array( $record ) || empty( $record['label'] ) ) {
                continue;
            }
            $item = sywat_shooting_styles_normalize_division_record( $slug, $record );
            $clean_map[ $item['slug'] ] = $item;
        }

        uasort( $clean_map, function( $a, $b ) {
            if ( $a['sort_order'] !== $b['sort_order'] ) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            return strnatcasecmp( $a['label'], $b['label'] );
        } );

        return update_option( 'sywat_divisions', $clean_map, false );
    }
}

/**
 * Normalizes legacy or shorthand division labels using configured database division records.
 *
 * @param string $division Raw division string.
 * @return string Canonical division string or trimmed original.
 */
if ( ! function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
    function sywat_shooting_styles_normalize_division( $division ) {
        $clean = trim( (string) $division );
        if ( $clean === '' ) {
            return '';
        }

        foreach ( sywat_get_division_records( true ) as $rec ) {
            if ( strcasecmp( $clean, $rec['label'] ) === 0 ) {
                return $rec['label'];
            }
            if ( ! empty( $rec['aliases'] ) && is_array( $rec['aliases'] ) ) {
                foreach ( $rec['aliases'] as $alias ) {
                    if ( strcasecmp( $clean, $alias ) === 0 ) {
                        return $rec['label'];
                    }
                }
            }
        }

        return $clean;
    }
}

/**
 * One-time seed dataset for NFAA / SYWAT shooting styles.
 *
 * Rule Modes:
 * - 'all'      : Selectable for any division.
 * - 'restrict' : Selectable only for divisions listed in 'allowed_divisions'.
 * - 'force'    : Selectable for any archer, automatically pushing their division to 'forced_division'.
 *
 * @return array Associative array keyed by style slug.
 */
if ( ! function_exists( 'sywat_shooting_styles_get_default_seed' ) ) {
    function sywat_shooting_styles_get_default_seed() {
        $adult_and_senior_divisions = array(
            'Adult (18 - 49)',
            'Senior (50 - 59)',
            'Silver Senior (60 - 69)',
            'Master Senior (70+)',
        );

        return array(
            'fs' => array(
                'slug'              => 'fs',
                'code'              => 'FS',
                'label'             => 'FS – Freestyle',
                'aliases'           => array( 'Freestyle', 'FS' ),
                'sort_order'        => 1,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'all',
                'allowed_divisions' => array(),
                'forced_division'   => '',
            ),
            'fs-pro' => array(
                'slug'              => 'fs-pro',
                'code'              => 'FS',
                'label'             => 'FS Pro – Freestyle Pro',
                'aliases'           => array( 'Freestyle Pro', 'FS Pro' ),
                'sort_order'        => 2,
                'is_pro'            => true,
                'status'            => 'active',
                'rule_mode'         => 'restrict',
                'allowed_divisions' => $adult_and_senior_divisions,
                'forced_division'   => '',
            ),
            'fsl' => array(
                'slug'              => 'fsl',
                'code'              => 'FSL',
                'label'             => 'FSL – Freestyle Limited',
                'aliases'           => array( 'Freestyle Limited', 'FSL' ),
                'sort_order'        => 3,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'restrict',
                'allowed_divisions' => $adult_and_senior_divisions,
                'forced_division'   => '',
            ),
            'bhfs' => array(
                'slug'              => 'bhfs',
                'code'              => 'BHFS',
                'label'             => 'BHFS – Bowhunter Freestyle',
                'aliases'           => array( 'Bowhunter Freestyle', 'BHFS' ),
                'sort_order'        => 4,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'all',
                'allowed_divisions' => array(),
                'forced_division'   => '',
            ),
            'mbh' => array(
                'slug'              => 'mbh',
                'code'              => 'MBH',
                'label'             => 'MBH – Modern Bowhunter',
                'aliases'           => array( 'Modern Bowhunter', 'MBH' ),
                'sort_order'        => 5,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'all',
                'allowed_divisions' => array(),
                'forced_division'   => '',
            ),
            'bbc' => array(
                'slug'              => 'bbc',
                'code'              => 'BBC',
                'label'             => 'BBC – Barebow Compound',
                'aliases'           => array( 'Barebow Compound', 'BBC' ),
                'sort_order'        => 6,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'all',
                'allowed_divisions' => array(),
                'forced_division'   => '',
            ),
            'bbr' => array(
                'slug'              => 'bbr',
                'code'              => 'BBR',
                'label'             => 'BBR – Barebow Recurve',
                'aliases'           => array( 'Barebow Recurve', 'BBR', 'BB Barebow', 'BB' ),
                'sort_order'        => 7,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'all',
                'allowed_divisions' => array(),
                'forced_division'   => '',
            ),
            'trad' => array(
                'slug'              => 'trad',
                'code'              => 'TRAD',
                'label'             => 'TRAD – Traditional',
                'aliases'           => array( 'Traditional', 'TRAD' ),
                'sort_order'        => 8,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'restrict',
                'allowed_divisions' => $adult_and_senior_divisions,
                'forced_division'   => '',
            ),
            'lb' => array(
                'slug'              => 'lb',
                'code'              => 'LB',
                'label'             => 'LB – Longbow',
                'aliases'           => array( 'Longbow', 'LB' ),
                'sort_order'        => 9,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'force',
                'allowed_divisions' => array(),
                'forced_division'   => 'Adult (18 - 49)',
            ),
            'fslr' => array(
                'slug'              => 'fslr',
                'code'              => 'FSLR',
                'label'             => 'FSL-R – Freestyle Limited Recurve (or Olympic Recurve)',
                'aliases'           => array(
                    'FSLR – Freestyle Limited Recurve (or Olympic Recurve)',
                    'Freestyle Limited Recurve',
                    'Olympic Recurve',
                    'FSL-R',
                    'FSLR',
                ),
                'sort_order'        => 10,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'all',
                'allowed_divisions' => array(),
                'forced_division'   => '',
            ),
            'genesis' => array(
                'slug'              => 'genesis',
                'code'              => 'GEN',
                'label'             => 'Genesis',
                'aliases'           => array( 'Genesis', 'GEN' ),
                'sort_order'        => 11,
                'is_pro'            => false,
                'status'            => 'active',
                'rule_mode'         => 'restrict',
                'allowed_divisions' => array( 'Cub (8 - 11)' ),
                'forced_division'   => '',
            ),
        );
    }
}

/**
 * Normalizes a single shooting style record from the database.
 *
 * @param string $slug   Style slug key.
 * @param array  $record Raw style array.
 * @return array Normalized style record.
 */
if ( ! function_exists( 'sywat_shooting_styles_normalize_record' ) ) {
    function sywat_shooting_styles_normalize_record( $slug, $record ) {
        $clean_slug = sanitize_title( ! empty( $record['slug'] ) ? $record['slug'] : $slug );
        if ( $clean_slug === '' ) {
            $clean_slug = 'style-' . substr( md5( uniqid( '', true ) ), 0, 6 );
        }

        $label = isset( $record['label'] ) ? trim( (string) $record['label'] ) : '';
        $code  = isset( $record['code'] ) ? strtoupper( trim( (string) $record['code'] ) ) : '';
        if ( $code === '' && $label !== '' ) {
            $parts = preg_split( '/\s*[–-]\s*/u', $label );
            $code  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $parts[0] ) );
        }

        $aliases = array();
        if ( isset( $record['aliases'] ) ) {
            $raw_aliases = is_array( $record['aliases'] ) ? $record['aliases'] : explode( ',', (string) $record['aliases'] );
            foreach ( $raw_aliases as $al ) {
                $trimmed = trim( (string) $al );
                if ( $trimmed !== '' && ! in_array( $trimmed, $aliases, true ) ) {
                    $aliases[] = $trimmed;
                }
            }
        }

        $rule_mode = isset( $record['rule_mode'] ) ? trim( (string) $record['rule_mode'] ) : 'all';
        if ( ! in_array( $rule_mode, array( 'all', 'restrict', 'force' ), true ) ) {
            $rule_mode = 'all';
        }

        $valid_divisions   = sywat_shooting_styles_get_divisions( true );
        $allowed_divisions = array();
        if ( $rule_mode === 'restrict' && ! empty( $record['allowed_divisions'] ) && is_array( $record['allowed_divisions'] ) ) {
            foreach ( $record['allowed_divisions'] as $div_item ) {
                $norm_div = sywat_shooting_styles_normalize_division( $div_item );
                if ( in_array( $norm_div, $valid_divisions, true ) && ! in_array( $norm_div, $allowed_divisions, true ) ) {
                    $allowed_divisions[] = $norm_div;
                }
            }
        }

        $forced_division = '';
        if ( $rule_mode === 'force' && ! empty( $record['forced_division'] ) ) {
            $norm_forced = sywat_shooting_styles_normalize_division( $record['forced_division'] );
            if ( in_array( $norm_forced, $valid_divisions, true ) ) {
                $forced_division = $norm_forced;
            }
        }

        $status = ( isset( $record['status'] ) && $record['status'] === 'retired' ) ? 'retired' : 'active';

        return array(
            'slug'              => $clean_slug,
            'code'              => $code,
            'label'             => $label,
            'aliases'           => $aliases,
            'sort_order'        => isset( $record['sort_order'] ) ? max( 1, intval( $record['sort_order'] ) ) : 50,
            'is_pro'            => ! empty( $record['is_pro'] ),
            'status'            => $status,
            'rule_mode'         => $rule_mode,
            'allowed_divisions' => $allowed_divisions,
            'forced_division'   => $forced_division,
        );
    }
}

/**
 * Retrieves all configured shooting styles from the database, seeding once only if the option does not exist yet.
 *
 * @param bool $include_retired Whether to include retired styles in the returned map.
 * @return array Ordered associative array of shooting style records keyed by slug.
 */
if ( ! function_exists( 'sywat_get_shooting_styles' ) ) {
    function sywat_get_shooting_styles( $include_retired = true ) {
        $raw = get_option( 'sywat_shooting_styles', false );

        if ( false === $raw ) {
            $raw = sywat_shooting_styles_get_default_seed();
            update_option( 'sywat_shooting_styles', $raw, false );
        }

        if ( ! is_array( $raw ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $raw as $slug => $record ) {
            if ( ! is_array( $record ) || empty( $record['label'] ) ) {
                continue;
            }
            $item = sywat_shooting_styles_normalize_record( $slug, $record );
            if ( ! $include_retired && $item['status'] === 'retired' ) {
                continue;
            }
            $normalized[ $item['slug'] ] = $item;
        }

        uasort( $normalized, function( $a, $b ) {
            if ( $a['sort_order'] !== $b['sort_order'] ) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            return strnatcasecmp( $a['label'], $b['label'] );
        } );

        return $normalized;
    }
}

/**
 * Persists the shooting styles configuration array to WordPress options.
 *
 * @param array $styles Associative array of style records.
 * @return bool True on successful option update.
 */
if ( ! function_exists( 'sywat_save_shooting_styles' ) ) {
    function sywat_save_shooting_styles( $styles ) {
        if ( ! is_array( $styles ) ) {
            return false;
        }

        $clean_map = array();
        foreach ( $styles as $slug => $record ) {
            if ( ! is_array( $record ) || empty( $record['label'] ) ) {
                continue;
            }
            $item = sywat_shooting_styles_normalize_record( $slug, $record );
            $clean_map[ $item['slug'] ] = $item;
        }

        uasort( $clean_map, function( $a, $b ) {
            if ( $a['sort_order'] !== $b['sort_order'] ) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            return strnatcasecmp( $a['label'], $b['label'] );
        } );

        return update_option( 'sywat_shooting_styles', $clean_map, false );
    }
}

/**
 * Restores default NFAA shooting styles and divisions in the database when explicitly triggered via the GUI.
 *
 * @return bool True on successful reset.
 */
if ( ! function_exists( 'sywat_shooting_styles_reset_defaults' ) ) {
    function sywat_shooting_styles_reset_defaults() {
        $div_ok   = update_option( 'sywat_divisions', sywat_shooting_styles_get_default_divisions_seed(), false );
        $style_ok = update_option( 'sywat_shooting_styles', sywat_shooting_styles_get_default_seed(), false );
        return (bool) ( $div_ok || $style_ok );
    }
}
