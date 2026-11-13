<?php
/**
 * Sub-Module: Shooting Styles & Divisions POST Action Handlers
 * Path: includes/shooting-styles/shooting-styles-actions.php
 * Description: Processes nonce-verified POST mutations for adding, editing, retiring, deleting, and resetting NFAA shooting styles and age divisions in WordPress options.
 * Author: SYWAT Archery
 * Version: 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Processes POST requests submitted from the Shooting Styles & Divisions administration portal.
 *
 * @return string HTML alert notice markup, or empty string when no action was processed.
 */
if ( ! function_exists( 'sywat_shooting_styles_handle_post_actions' ) ) {
    function sywat_shooting_styles_handle_post_actions() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['sywat_styles_action'] ) ) {
            return '';
        }

        if ( ! function_exists( 'sywat_can_manage_seasons' ) || ! sywat_can_manage_seasons() ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. TFAA Leadership privileges are required to modify shooting styles or divisions.</div>';
        }

        $nonce = isset( $_POST['sywat_styles_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_styles_nonce'] ) ) : '';
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'sywat_shooting_styles_action' ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        }

        $action = sanitize_key( wp_unslash( $_POST['sywat_styles_action'] ) );

        // 1. Add or Edit Shooting Style
        if ( $action === 'save_style' ) {
            $orig_slug  = isset( $_POST['orig_style_slug'] ) ? sanitize_title( wp_unslash( $_POST['orig_style_slug'] ) ) : '';
            $label      = isset( $_POST['style_label'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['style_label'] ) ) ) : '';
            $code       = isset( $_POST['style_code'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['style_code'] ) ) ) ) : '';
            $aliases    = isset( $_POST['style_aliases'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['style_aliases'] ) ) ) : '';
            $sort_order = isset( $_POST['style_sort_order'] ) ? max( 1, intval( wp_unslash( $_POST['style_sort_order'] ) ) ) : 10;
            $is_pro     = ! empty( $_POST['style_is_pro'] );
            $status     = ( isset( $_POST['style_status'] ) && sanitize_key( wp_unslash( $_POST['style_status'] ) ) === 'retired' ) ? 'retired' : 'active';
            $rule_mode  = isset( $_POST['style_rule_mode'] ) ? sanitize_key( wp_unslash( $_POST['style_rule_mode'] ) ) : 'all';

            if ( ! in_array( $rule_mode, array( 'all', 'restrict', 'force' ), true ) ) {
                $rule_mode = 'all';
            }

            if ( $label === '' ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Style Display Name is required.</div>';
            }

            if ( $code === '' ) {
                $parts = preg_split( '/\s*[–-]\s*/u', $label );
                $code  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $parts[0] ) );
            }
            if ( $code === '' ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Short Export Code is required (e.g., FS, BHFS, LB).</div>';
            }

            $allowed_divisions = array();
            if ( $rule_mode === 'restrict' ) {
                $raw_allowed = isset( $_POST['style_allowed_divisions'] ) && is_array( $_POST['style_allowed_divisions'] )
                    ? wp_unslash( $_POST['style_allowed_divisions'] )
                    : array();

                foreach ( $raw_allowed as $div_val ) {
                    $clean_d = sanitize_text_field( $div_val );
                    if ( function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
                        $clean_d = sywat_shooting_styles_normalize_division( $clean_d );
                    }
                    if ( $clean_d !== '' && ! in_array( $clean_d, $allowed_divisions, true ) ) {
                        $allowed_divisions[] = $clean_d;
                    }
                }

                if ( empty( $allowed_divisions ) ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select at least one allowed division when using "Restrict to Selected Divisions".</div>';
                }
            }

            $forced_division = '';
            if ( $rule_mode === 'force' ) {
                $raw_forced = isset( $_POST['style_forced_division'] ) ? sanitize_text_field( wp_unslash( $_POST['style_forced_division'] ) ) : '';
                $forced_division = function_exists( 'sywat_shooting_styles_normalize_division' )
                    ? sywat_shooting_styles_normalize_division( $raw_forced )
                    : trim( $raw_forced );

                if ( $forced_division === '' ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please choose a target division when using "Force Specific Division".</div>';
                }
            }

            $styles = function_exists( 'sywat_get_shooting_styles' ) ? sywat_get_shooting_styles( true ) : array();

            // Prevent duplicate display labels across different slugs
            foreach ( $styles as $existing_slug => $existing_rec ) {
                if ( $existing_slug !== $orig_slug && strcasecmp( $existing_rec['label'], $label ) === 0 ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">A shooting style named <strong>' . esc_html( $label ) . '</strong> already exists.</div>';
                }
            }

            if ( $orig_slug !== '' && isset( $styles[ $orig_slug ] ) ) {
                $target_slug = $orig_slug;
            } else {
                $base_slug   = sanitize_title( $label );
                if ( $base_slug === '' ) {
                    $base_slug = sanitize_title( $code );
                }
                $target_slug = $base_slug;
                $counter     = 2;
                while ( isset( $styles[ $target_slug ] ) ) {
                    $target_slug = $base_slug . '-' . $counter;
                    $counter++;
                }
            }

            $styles[ $target_slug ] = array(
                'slug'              => $target_slug,
                'code'              => $code,
                'label'             => $label,
                'aliases'           => $aliases,
                'sort_order'        => $sort_order,
                'is_pro'            => $is_pro,
                'status'            => $status,
                'rule_mode'         => $rule_mode,
                'allowed_divisions' => $allowed_divisions,
                'forced_division'   => $forced_division,
            );

            if ( function_exists( 'sywat_save_shooting_styles' ) ) {
                sywat_save_shooting_styles( $styles );
            }

            $verb = ( $orig_slug !== '' ) ? 'updated' : 'added';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Shooting style <strong>' . esc_html( $label ) . '</strong> ' . esc_html( $verb ) . ' successfully.</div>';
        }

        // 2. Toggle Shooting Style Active / Retired Status
        if ( $action === 'toggle_style_status' ) {
            $slug   = isset( $_POST['style_slug'] ) ? sanitize_title( wp_unslash( $_POST['style_slug'] ) ) : '';
            $styles = function_exists( 'sywat_get_shooting_styles' ) ? sywat_get_shooting_styles( true ) : array();

            if ( $slug === '' || ! isset( $styles[ $slug ] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Shooting style not found.</div>';
            }

            $new_status = ( $styles[ $slug ]['status'] === 'retired' ) ? 'active' : 'retired';
            $styles[ $slug ]['status'] = $new_status;

            if ( function_exists( 'sywat_save_shooting_styles' ) ) {
                sywat_save_shooting_styles( $styles );
            }

            $state_label = ( $new_status === 'active' ) ? 'activated' : 'retired (hidden from new registrations)';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Shooting style <strong>' . esc_html( $styles[ $slug ]['label'] ) . '</strong> ' . esc_html( $state_label ) . '.</div>';
        }

        // 3. Delete Shooting Style
        if ( $action === 'delete_style' ) {
            $slug   = isset( $_POST['style_slug'] ) ? sanitize_title( wp_unslash( $_POST['style_slug'] ) ) : '';
            $styles = function_exists( 'sywat_get_shooting_styles' ) ? sywat_get_shooting_styles( true ) : array();

            if ( $slug === '' || ! isset( $styles[ $slug ] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Shooting style not found.</div>';
            }

            $deleted_label = $styles[ $slug ]['label'];
            unset( $styles[ $slug ] );

            if ( function_exists( 'sywat_save_shooting_styles' ) ) {
                sywat_save_shooting_styles( $styles );
            }

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Shooting style <strong>' . esc_html( $deleted_label ) . '</strong> removed.</div>';
        }

        // 4. Add or Edit Age Division
        if ( $action === 'save_division' ) {
            $orig_slug  = isset( $_POST['orig_div_slug'] ) ? sanitize_title( wp_unslash( $_POST['orig_div_slug'] ) ) : '';
            $label      = isset( $_POST['div_label'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['div_label'] ) ) ) : '';
            $code       = isset( $_POST['div_code'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['div_code'] ) ) ) ) : '';
            $aliases    = isset( $_POST['div_aliases'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['div_aliases'] ) ) ) : '';
            $distance   = ( isset( $_POST['div_distance'] ) && sanitize_text_field( wp_unslash( $_POST['div_distance'] ) ) === 'Cub' ) ? 'Cub' : '20yd';
            $rate_tier  = ( isset( $_POST['div_rate_tier'] ) && sanitize_text_field( wp_unslash( $_POST['div_rate_tier'] ) ) === 'Youth' ) ? 'Youth' : 'Adult';
            $sort_order = isset( $_POST['div_sort_order'] ) ? max( 1, intval( wp_unslash( $_POST['div_sort_order'] ) ) ) : 10;
            $status     = ( isset( $_POST['div_status'] ) && sanitize_key( wp_unslash( $_POST['div_status'] ) ) === 'retired' ) ? 'retired' : 'active';

            if ( $label === '' ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Division Name is required.</div>';
            }

            $divisions = function_exists( 'sywat_get_division_records' ) ? sywat_get_division_records( true ) : array();

            foreach ( $divisions as $existing_slug => $existing_rec ) {
                if ( $existing_slug !== $orig_slug && strcasecmp( $existing_rec['label'], $label ) === 0 ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">A division named <strong>' . esc_html( $label ) . '</strong> already exists.</div>';
                }
            }

            if ( $orig_slug !== '' && isset( $divisions[ $orig_slug ] ) ) {
                $target_slug = $orig_slug;
            } else {
                $base_slug   = sanitize_title( $label );
                if ( $base_slug === '' ) {
                    $base_slug = 'division';
                }
                $target_slug = $base_slug;
                $counter     = 2;
                while ( isset( $divisions[ $target_slug ] ) ) {
                    $target_slug = $base_slug . '-' . $counter;
                    $counter++;
                }
            }

            $divisions[ $target_slug ] = array(
                'slug'       => $target_slug,
                'label'      => $label,
                'code'       => $code,
                'aliases'    => $aliases,
                'distance'   => $distance,
                'rate_tier'  => $rate_tier,
                'sort_order' => $sort_order,
                'status'     => $status,
            );

            if ( function_exists( 'sywat_save_divisions' ) ) {
                sywat_save_divisions( $divisions );
            }

            $verb = ( $orig_slug !== '' ) ? 'updated' : 'added';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Division <strong>' . esc_html( $label ) . '</strong> ' . esc_html( $verb ) . ' successfully.</div>';
        }

        // 5. Toggle Division Active / Retired Status
        if ( $action === 'toggle_division_status' ) {
            $slug      = isset( $_POST['div_slug'] ) ? sanitize_title( wp_unslash( $_POST['div_slug'] ) ) : '';
            $divisions = function_exists( 'sywat_get_division_records' ) ? sywat_get_division_records( true ) : array();

            if ( $slug === '' || ! isset( $divisions[ $slug ] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Division not found.</div>';
            }

            $new_status = ( $divisions[ $slug ]['status'] === 'retired' ) ? 'active' : 'retired';
            $divisions[ $slug ]['status'] = $new_status;

            if ( function_exists( 'sywat_save_divisions' ) ) {
                sywat_save_divisions( $divisions );
            }

            $state_label = ( $new_status === 'active' ) ? 'activated' : 'retired';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Division <strong>' . esc_html( $divisions[ $slug ]['label'] ) . '</strong> ' . esc_html( $state_label ) . '.</div>';
        }

        // 6. Delete Division
        if ( $action === 'delete_division' ) {
            $slug      = isset( $_POST['div_slug'] ) ? sanitize_title( wp_unslash( $_POST['div_slug'] ) ) : '';
            $divisions = function_exists( 'sywat_get_division_records' ) ? sywat_get_division_records( true ) : array();

            if ( $slug === '' || ! isset( $divisions[ $slug ] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Division not found.</div>';
            }

            $deleted_label = $divisions[ $slug ]['label'];
            unset( $divisions[ $slug ] );

            if ( function_exists( 'sywat_save_divisions' ) ) {
                sywat_save_divisions( $divisions );
            }

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Division <strong>' . esc_html( $deleted_label ) . '</strong> removed.</div>';
        }

        // 7. Reset All Styles & Divisions to NFAA Defaults
        if ( $action === 'reset_nfaa_defaults' ) {
            if ( function_exists( 'sywat_shooting_styles_reset_defaults' ) ) {
                sywat_shooting_styles_reset_defaults();
            }
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Shooting styles and age divisions have been restored to NFAA defaults.</div>';
        }

        return '';
    }
}
