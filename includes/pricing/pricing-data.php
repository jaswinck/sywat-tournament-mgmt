<?php
/**
 * Sub-Module: Pricing Profiles, Round Formats & Promoter Database Layer
 * Path: includes/pricing/pricing-data.php
 * Description: Manages persistent WordPress option storage (sywat_pricing_profiles) with one-time seed initialization for Indoor, Field, Field 50, Known 3D, and 900 formats, per-division price overrides, household family cap rules, and configurable first-time/lapsed shooter promotional rules.
 * Compliance: Pure data layer — zero HTML echo, zero presentation formatting, zero POST handling, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One-time seed dataset for canonical SYWAT / TFAA pricing & round formats.
 *
 * @return array Associative array keyed by profile slug.
 */
if ( ! function_exists( 'sywat_pricing_get_default_seed' ) ) {
    function sywat_pricing_get_default_seed() {
        return array(
            'indoor' => array(
                'slug'                   => 'indoor',
                'label'                  => 'Indoor',
                'round_subtitle'         => '',
                'code'                   => 'INDOOR',
                'aliases'                => array( 'Indoor SYWAT', 'Indoor' ),
                'sort_order'             => 1,
                'status'                 => 'active',
                'is_default'             => true,
                'youth_rate'             => 25.00,
                'adult_rate'             => 30.00,
                'pro_rate'               => '',
                'field_governor_rate'    => 0.00,
                'division_overrides'     => array(),
                'family_cap_enabled'     => true,
                'family_cap_amount'      => 75.00,
                'family_min_archers'     => 3,
                'family_cap_scope'       => 'line',
                'promo_enabled'          => false,
                'promo_label'            => 'First-Time / Lapsed Shooter Promo',
                'promo_lookback_value'   => 24,
                'promo_lookback_unit'    => 'months',
                'promo_history_profiles' => array( 'indoor' ),
                'promo_max_uses'         => 1,
                'promo_usage_scope'      => 'season',
            ),
            'field' => array(
                'slug'                   => 'field',
                'label'                  => 'Field',
                'round_subtitle'         => '28 Targets',
                'code'                   => 'FIELD',
                'aliases'                => array( 'Field Round', '28 Target Field', 'Outdoor Field' ),
                'sort_order'             => 2,
                'status'                 => 'active',
                'is_default'             => false,
                'youth_rate'             => 30.00,
                'adult_rate'             => 40.00,
                'pro_rate'               => '',
                'field_governor_rate'    => 0.00,
                'division_overrides'     => array(),
                'family_cap_enabled'     => true,
                'family_cap_amount'      => 100.00,
                'family_min_archers'     => 3,
                'family_cap_scope'       => 'tournament',
                'promo_enabled'          => false,
                'promo_label'            => 'First-Time / Lapsed Shooter Promo',
                'promo_lookback_value'   => 24,
                'promo_lookback_unit'    => 'months',
                'promo_history_profiles' => array( 'field', 'field-50' ),
                'promo_max_uses'         => 1,
                'promo_usage_scope'      => 'season',
            ),
            'field-50' => array(
                'slug'                   => 'field-50',
                'label'                  => 'Field 50',
                'round_subtitle'         => '14 Targets',
                'code'                   => 'F50',
                'aliases'                => array( 'Field50', '14 Target Field' ),
                'sort_order'             => 3,
                'status'                 => 'active',
                'is_default'             => false,
                'youth_rate'             => 20.00,
                'adult_rate'             => 25.00,
                'pro_rate'               => '',
                'field_governor_rate'    => 0.00,
                'division_overrides'     => array(),
                'family_cap_enabled'     => true,
                'family_cap_amount'      => 65.00,
                'family_min_archers'     => 3,
                'family_cap_scope'       => 'tournament',
                'promo_enabled'          => true,
                'promo_label'            => 'First-Time / Lapsed Field 50 Promo',
                'promo_lookback_value'   => 24,
                'promo_lookback_unit'    => 'months',
                'promo_history_profiles' => array( 'field', 'field-50' ),
                'promo_max_uses'         => 1,
                'promo_usage_scope'      => 'season',
            ),
            'known-3d' => array(
                'slug'                   => 'known-3d',
                'label'                  => 'Known 3D',
                'round_subtitle'         => '',
                'code'                   => 'K3D',
                'aliases'                => array( '3D', 'Known 3-D' ),
                'sort_order'             => 4,
                'status'                 => 'active',
                'is_default'             => false,
                'youth_rate'             => 25.00,
                'adult_rate'             => 35.00,
                'pro_rate'               => '',
                'field_governor_rate'    => 0.00,
                'division_overrides'     => array(),
                'family_cap_enabled'     => true,
                'family_cap_amount'      => 90.00,
                'family_min_archers'     => 3,
                'family_cap_scope'       => 'tournament',
                'promo_enabled'          => false,
                'promo_label'            => 'First-Time / Lapsed Shooter Promo',
                'promo_lookback_value'   => 24,
                'promo_lookback_unit'    => 'months',
                'promo_history_profiles' => array( 'known-3d' ),
                'promo_max_uses'         => 1,
                'promo_usage_scope'      => 'season',
            ),
            '900' => array(
                'slug'                   => '900',
                'label'                  => '900',
                'round_subtitle'         => '900 Round',
                'code'                   => '900',
                'aliases'                => array( '900 Round', 'Outdoor 900' ),
                'sort_order'             => 5,
                'status'                 => 'active',
                'is_default'             => false,
                'youth_rate'             => 25.00,
                'adult_rate'             => 35.00,
                'pro_rate'               => '',
                'field_governor_rate'    => 0.00,
                'division_overrides'     => array(),
                'family_cap_enabled'     => true,
                'family_cap_amount'      => 90.00,
                'family_min_archers'     => 3,
                'family_cap_scope'       => 'tournament',
                'promo_enabled'          => false,
                'promo_label'            => 'First-Time / Lapsed Shooter Promo',
                'promo_lookback_value'   => 24,
                'promo_lookback_unit'    => 'months',
                'promo_history_profiles' => array( '900' ),
                'promo_max_uses'         => 1,
                'promo_usage_scope'      => 'season',
            ),
        );
    }
}

/**
 * Normalizes a single pricing profile record from database storage or form input.
 *
 * @param string $slug   Profile slug key.
 * @param array  $record Raw profile array.
 * @return array Normalized pricing profile record.
 */
if ( ! function_exists( 'sywat_pricing_normalize_profile_record' ) ) {
    function sywat_pricing_normalize_profile_record( $slug, $record ) {
        $clean_slug = sanitize_title( ! empty( $record['slug'] ) ? $record['slug'] : $slug );
        if ( $clean_slug === '' ) {
            $clean_slug = 'format-' . substr( md5( uniqid( '', true ) ), 0, 6 );
        }

        $label          = isset( $record['label'] ) ? trim( (string) $record['label'] ) : '';
        $round_subtitle = isset( $record['round_subtitle'] ) ? trim( (string) $record['round_subtitle'] ) : '';
        $code           = isset( $record['code'] ) ? strtoupper( trim( (string) $record['code'] ) ) : '';
        if ( $code === '' && $label !== '' ) {
            $code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $label ) );
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

        $status     = ( isset( $record['status'] ) && $record['status'] === 'retired' ) ? 'retired' : 'active';
        $is_default = ! empty( $record['is_default'] );

        $youth_rate          = isset( $record['youth_rate'] ) && is_numeric( $record['youth_rate'] ) ? max( 0.00, round( floatval( $record['youth_rate'] ), 2 ) ) : 25.00;
        $adult_rate          = isset( $record['adult_rate'] ) && is_numeric( $record['adult_rate'] ) ? max( 0.00, round( floatval( $record['adult_rate'] ), 2 ) ) : 30.00;
        $pro_rate            = ( isset( $record['pro_rate'] ) && $record['pro_rate'] !== '' && is_numeric( $record['pro_rate'] ) )
            ? max( 0.00, round( floatval( $record['pro_rate'] ), 2 ) )
            : '';
        $field_governor_rate = isset( $record['field_governor_rate'] ) && is_numeric( $record['field_governor_rate'] ) ? max( 0.00, round( floatval( $record['field_governor_rate'] ), 2 ) ) : 0.00;

        // Optional per-division dollar price overrides (division_slug => float rate)
        $division_overrides = array();
        if ( ! empty( $record['division_overrides'] ) && is_array( $record['division_overrides'] ) ) {
            foreach ( $record['division_overrides'] as $div_key => $ov_val ) {
                if ( $ov_val !== '' && $ov_val !== null && is_numeric( $ov_val ) ) {
                    $d_slug = sanitize_title( (string) $div_key );
                    if ( $d_slug !== '' ) {
                        $division_overrides[ $d_slug ] = max( 0.00, round( floatval( $ov_val ), 2 ) );
                    }
                }
            }
        }

        // Family Cap configuration
        $family_cap_enabled = ! empty( $record['family_cap_enabled'] );
        $family_cap_amount  = isset( $record['family_cap_amount'] ) && is_numeric( $record['family_cap_amount'] ) ? max( 0.00, round( floatval( $record['family_cap_amount'] ), 2 ) ) : 75.00;
        $family_min_archers = isset( $record['family_min_archers'] ) ? max( 2, intval( $record['family_min_archers'] ) ) : 3;
        $family_cap_scope   = ( isset( $record['family_cap_scope'] ) && $record['family_cap_scope'] === 'tournament' ) ? 'tournament' : 'line';

        // First-Time / Lapsed Shooter Promotional Rule configuration
        $promo_enabled        = ! empty( $record['promo_enabled'] );
        $promo_label          = ! empty( $record['promo_label'] ) ? trim( (string) $record['promo_label'] ) : 'First-Time / Lapsed Shooter Promo';
        $promo_lookback_value = isset( $record['promo_lookback_value'] ) ? max( 1, intval( $record['promo_lookback_value'] ) ) : 24;
        $promo_lookback_unit  = ( isset( $record['promo_lookback_unit'] ) && $record['promo_lookback_unit'] === 'years' ) ? 'years' : 'months';

        $promo_history_profiles = array();
        if ( ! empty( $record['promo_history_profiles'] ) && is_array( $record['promo_history_profiles'] ) ) {
            foreach ( $record['promo_history_profiles'] as $hp_slug ) {
                $clean_hp = sanitize_title( (string) $hp_slug );
                if ( $clean_hp !== '' && ! in_array( $clean_hp, $promo_history_profiles, true ) ) {
                    $promo_history_profiles[] = $clean_hp;
                }
            }
        }
        if ( empty( $promo_history_profiles ) ) {
            $promo_history_profiles = array( $clean_slug );
        }

        // 0 = unlimited while eligible; >= 1 = strict cap per scope
        $promo_max_uses    = isset( $record['promo_max_uses'] ) ? max( 0, intval( $record['promo_max_uses'] ) ) : 1;
        $promo_usage_scope = isset( $record['promo_usage_scope'] ) ? trim( (string) $record['promo_usage_scope'] ) : 'season';
        if ( ! in_array( $promo_usage_scope, array( 'season', 'lookback', 'lifetime' ), true ) ) {
            $promo_usage_scope = 'season';
        }

        return array(
            'slug'                   => $clean_slug,
            'label'                  => $label,
            'round_subtitle'         => $round_subtitle,
            'code'                   => $code,
            'aliases'                => $aliases,
            'sort_order'             => isset( $record['sort_order'] ) ? max( 1, intval( $record['sort_order'] ) ) : 50,
            'status'                 => $status,
            'is_default'             => $is_default,
            'youth_rate'             => $youth_rate,
            'adult_rate'             => $adult_rate,
            'pro_rate'               => $pro_rate,
            'field_governor_rate'    => $field_governor_rate,
            'division_overrides'     => $division_overrides,
            'family_cap_enabled'     => $family_cap_enabled,
            'family_cap_amount'      => $family_cap_amount,
            'family_min_archers'     => $family_min_archers,
            'family_cap_scope'       => $family_cap_scope,
            'promo_enabled'          => $promo_enabled,
            'promo_label'            => $promo_label,
            'promo_lookback_value'   => $promo_lookback_value,
            'promo_lookback_unit'    => $promo_lookback_unit,
            'promo_history_profiles' => $promo_history_profiles,
            'promo_max_uses'         => $promo_max_uses,
            'promo_usage_scope'      => $promo_usage_scope,
        );
    }
}

/**
 * Retrieves all configured pricing & round profiles from the database,
 * seeding once only if the option does not exist yet.
 *
 * @param bool $include_retired Whether to include retired profiles.
 * @return array Ordered associative array of pricing profile records keyed by slug.
 */
if ( ! function_exists( 'sywat_get_pricing_profiles' ) ) {
    function sywat_get_pricing_profiles( $include_retired = false ) {
        $raw = get_option( 'sywat_pricing_profiles', false );

        if ( false === $raw ) {
            $raw = sywat_pricing_get_default_seed();
            update_option( 'sywat_pricing_profiles', $raw, false );
        }

        if ( ! is_array( $raw ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $raw as $slug => $record ) {
            if ( ! is_array( $record ) || empty( $record['label'] ) ) {
                continue;
            }
            $item = sywat_pricing_normalize_profile_record( $slug, $record );
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
 * Persists pricing profiles configuration to WordPress options.
 * Guarantees exactly one active profile is marked as default.
 *
 * @param array $profiles Associative array of pricing profile records.
 * @return bool True on successful option update.
 */
if ( ! function_exists( 'sywat_save_pricing_profiles' ) ) {
    function sywat_save_pricing_profiles( $profiles ) {
        if ( ! is_array( $profiles ) ) {
            return false;
        }

        $clean_map    = array();
        $default_slug = '';

        foreach ( $profiles as $slug => $record ) {
            if ( ! is_array( $record ) || empty( $record['label'] ) ) {
                continue;
            }
            $item = sywat_pricing_normalize_profile_record( $slug, $record );
            if ( $item['is_default'] && $item['status'] === 'active' && $default_slug === '' ) {
                $default_slug = $item['slug'];
            }
            $clean_map[ $item['slug'] ] = $item;
        }

        uasort( $clean_map, function( $a, $b ) {
            if ( $a['sort_order'] !== $b['sort_order'] ) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            return strnatcasecmp( $a['label'], $b['label'] );
        } );

        if ( $default_slug === '' && ! empty( $clean_map ) ) {
            foreach ( $clean_map as $slug => $item ) {
                if ( $item['status'] === 'active' ) {
                    $default_slug = $slug;
                    break;
                }
            }
            if ( $default_slug === '' ) {
                reset( $clean_map );
                $default_slug = key( $clean_map );
            }
        }

        foreach ( $clean_map as $slug => &$item ) {
            $item['is_default'] = ( $slug === $default_slug );
        }
        unset( $item );

        return update_option( 'sywat_pricing_profiles', $clean_map, false );
    }
}

/**
 * Restores default SYWAT / TFAA pricing profiles when explicitly triggered via the GUI.
 *
 * @return bool True on successful reset.
 */
if ( ! function_exists( 'sywat_pricing_reset_defaults' ) ) {
    function sywat_pricing_reset_defaults() {
        return (bool) update_option( 'sywat_pricing_profiles', sywat_pricing_get_default_seed(), false );
    }
}
