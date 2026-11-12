<?php
/**
 * Sub-Module: Archer Roster Actions & Sanitization Engine
 * Path: includes/roster/roster-actions.php
 * Author: SYWAT Archery
 * Version: 6.8.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Calculate Paid Category Status based on Division & Payment Option.
 *
 * @param string $pay_option Selected payment option ('Individual', 'Family', 'Field Governor').
 * @param string $division   Archer age division label.
 * @return string            Normalized paid status label.
 */
if ( ! function_exists( 'sywat_calculate_paid_status' ) ) {
    function sywat_calculate_paid_status( $pay_option, $division ) {
        if ( $pay_option === 'Family' ) return 'Family';
        if ( $pay_option === 'Field Governor' ) return 'Field Governor';

        $youth_divs = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' );
        return in_array( $division, $youth_divs, true ) ? 'Youth' : 'Adult';
    }
}

/**
 * Enforce Division vs. Shooting Style Restrictions.
 *
 * @param string $style    Submitted shooting style string.
 * @param string $division Archer age division label.
 * @return string          Validated shooting style string.
 */
if ( ! function_exists( 'sywat_sanitize_style_for_division' ) ) {
    function sywat_sanitize_style_for_division( $style, $division ) {
        // Genesis style restricted to Cub division
        if ( strpos( $style, 'Genesis' ) !== false && $division !== 'Cub (8 - 11)' ) {
            return 'FS – Freestyle';
        }

        // Pro, FSL, & Traditional restricted to Adult & Senior divisions
        $is_pro_fsl_trad      = ( strpos( $style, 'FS Pro' ) !== false || strpos( $style, 'FSL –' ) !== false || strpos( $style, 'TRAD' ) !== false || stripos( $style, 'Traditional' ) !== false );
        $allowed_adult_senior = array( 'Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)' );
        if ( $is_pro_fsl_trad && ! in_array( $division, $allowed_adult_senior, true ) ) {
            return 'FS – Freestyle';
        }

        // Longbow restricted to Adult (18 - 49)
        $is_lb = ( strpos( $style, 'LB' ) !== false || stripos( $style, 'Longbow' ) !== false );
        if ( $is_lb && $division !== 'Adult (18 - 49)' ) {
            return 'FS – Freestyle';
        }

        return $style;
    }
}

/**
 * Resolve Shooting Line Numeric Index against Line Data.
 *
 * @param array $reg   Archer registration payload.
 * @param array $lines Tournament shooting line configuration array.
 * @return string      Resolved line index key.
 */
if ( ! function_exists( 'sywat_roster_resolve_line_idx' ) ) {
    function sywat_roster_resolve_line_idx( $reg, $lines ) {
        if ( isset( $reg['line_index'] ) && $reg['line_index'] !== '' ) {
            $idx = (string) $reg['line_index'];
            if ( isset( $lines[ $idx ] ) ) return $idx;

            foreach ( $lines as $l_key => $l_data ) {
                if ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), trim( $idx ) ) === 0 ) {
                    return (string) $l_key;
                }
            }
        }

        if ( isset( $reg['line_name'] ) && ! empty( $reg['line_name'] ) ) {
            foreach ( $lines as $l_key => $l_data ) {
                if ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), trim( $reg['line_name'] ) ) === 0 ) {
                    return (string) $l_key;
                }
            }
        }

        return isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0';
    }
}

/**
 * Resolve human-readable shooting line label from tournament line config.
 *
 * @param array      $tournaments   Tournaments map.
 * @param string     $tourn_context Tournament name key.
 * @param string|int $line_idx      Line index.
 * @return string                   Resolved line label.
 */
if ( ! function_exists( 'sywat_roster_resolve_line_label' ) ) {
    function sywat_roster_resolve_line_label( $tournaments, $tourn_context, $line_idx ) {
        $t_lines   = ( isset( $tournaments[ $tourn_context ]['lines'] ) && is_array( $tournaments[ $tourn_context ]['lines'] ) )
            ? $tournaments[ $tourn_context ]['lines']
            : ( isset( $tournaments[ $tourn_context ] ) && is_array( $tournaments[ $tourn_context ] ) ? $tournaments[ $tourn_context ] : array() );
        $line_data = isset( $t_lines[ $line_idx ] ) && is_array( $t_lines[ $line_idx ] ) ? $t_lines[ $line_idx ] : array();

        if ( ! empty( $line_data['name'] ) ) {
            return trim( $line_data['name'] );
        }

        $day_str  = isset( $line_data['day'] ) ? trim( $line_data['day'] ) : '';
        $time_str = isset( $line_data['time'] ) ? trim( $line_data['time'] ) : ( isset( $line_data['start_time'] ) ? trim( $line_data['start_time'] ) : '' );
        if ( ! empty( $day_str ) || ! empty( $time_str ) ) {
            return trim( $day_str . ' ' . $time_str );
        }

        return 'Line ' . ( (int) $line_idx + 1 );
    }
}

/**
 * Action Handler: Save Edited Archer Profile.
 *
 * @param array $tournaments   Tournaments master list.
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_handle_save_edit' ) ) {
    function sywat_handle_save_edit( $tournaments, &$registrations ) {
        $edit_id       = sanitize_text_field( wp_unslash( $_POST['registration_id'] ?? '' ) );
        $tourn_context = sanitize_text_field( wp_unslash( $_POST['view_tournament'] ?? '' ) );
        $t_slug        = sanitize_title( $tourn_context );

        $opt_key = 'sywat_t_' . $t_slug . '_' . $edit_id;
        $reg     = get_option( $opt_key );

        if ( ! $reg && ! empty( $registrations ) ) {
            foreach ( $registrations as $r ) {
                if ( isset( $r['id'] ) && (string) $r['id'] === (string) $edit_id ) {
                    $reg = $r;
                    if ( ! empty( $r['tournament'] ) ) {
                        $tourn_context = $r['tournament'];
                        $t_slug        = sanitize_title( $tourn_context );
                        $opt_key       = 'sywat_t_' . $t_slug . '_' . $edit_id;
                    }
                    break;
                }
            }
        }

        if ( $reg ) {
            $edit_name = trim( sanitize_text_field( wp_unslash( $_POST['edit_name'] ?? '' ) ) );
            $line_idx  = sanitize_text_field( wp_unslash( $_POST['edit_line_index'] ?? '0' ) );
            $line_name = sywat_roster_resolve_line_label( $tournaments, $tourn_context, $line_idx );
            $division  = sanitize_text_field( wp_unslash( $_POST['edit_division'] ?? '' ) );
            $distance  = ( $division === 'Cub (8 - 11)' ) ? 'Cub' : '20yd';
            $pay_opt   = sanitize_text_field( wp_unslash( $_POST['edit_pay_option'] ?? '' ) );
            $style     = sanitize_text_field( wp_unslash( $_POST['edit_style'] ?? '' ) );
            $due_val   = isset( $_POST['edit_balance_due'] ) ? floatval( wp_unslash( $_POST['edit_balance_due'] ) ) : 0;

            $orig_line = isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '';

            $reg['name']        = $edit_name;
            $reg['line_index']  = $line_idx;
            $reg['line_name']   = $line_name;
            $reg['distance']    = $distance;
            $reg['division']    = $division;
            $reg['gender']      = sanitize_text_field( wp_unslash( $_POST['edit_gender'] ?? '' ) );
            $reg['style']       = sywat_sanitize_style_for_division( $style, $division );
            $reg['paid']        = sywat_calculate_paid_status( $pay_opt, $division );
            $reg['balance_due'] = $due_val;

            // Handle strict Family Anchor assignments
            if ( $reg['paid'] === 'Family' ) {
                $sel_fam_id = isset( $_POST['edit_family_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edit_family_group_id'] ) ) : '';

                if ( ! empty( $sel_fam_id ) ) {
                    $reg['family_id'] = $sel_fam_id;

                    $existing_label = 'Family Group';
                    foreach ( $registrations as $other ) {
                        if ( isset( $other['family_id'] ) && $other['family_id'] === $sel_fam_id && ! empty( $other['family_name'] ) ) {
                            $existing_label = $other['family_name'];
                            break;
                        }
                    }
                    $reg['family_name'] = $existing_label;
                } elseif ( empty( $reg['family_id'] ) || $orig_line !== $line_idx ) {
                    $reg['family_id']   = 'fam_' . $t_slug . '_l' . sanitize_title( $line_idx ) . '_' . sanitize_title( $edit_name ) . '_' . substr( md5( uniqid( '', true ) ), 0, 4 );
                    $reg['family_name'] = $edit_name . ' (Family Cap)';
                }
            } else {
                unset( $reg['family_id'] );
                unset( $reg['family_name'] );
            }

            update_option( $opt_key, $reg, false );

            foreach ( $registrations as &$r ) {
                if ( isset( $r['id'] ) && (string) $r['id'] === (string) $edit_id ) {
                    $r = $reg;
                    break;
                }
            }
            unset( $r );

            $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
            if ( ! is_array( $master_regs ) ) $master_regs = array();
            $master_regs[ $edit_id ] = $reg;
            update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
            update_option( 'sywat_registrations', $registrations, false );

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully updated archer: <strong>' . esc_html( $reg['name'] ) . '</strong>.</div>';
        }

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Unable to locate archer registration record for editing.</div>';
    }
}

/**
 * Action Handler: Delete Archer Registration.
 * Safely unslashes apostrophe tournament names, resolves exact wp_options keys
 * from both the matched record and POST tournament slug, and cleans legacy caches.
 *
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_handle_delete_shooter' ) ) {
    function sywat_handle_delete_shooter( &$registrations ) {
        global $wpdb;

        $delete_id     = sanitize_text_field( wp_unslash( $_POST['registration_id'] ?? '' ) );
        $tourn_context = sanitize_text_field( wp_unslash( $_POST['view_tournament'] ?? '' ) );
        $t_slug        = sanitize_title( $tourn_context );

        if ( empty( $delete_id ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Invalid registration ID. No archer was removed.</div>';
        }

        $deleted_name      = '';
        $matched_slugs     = array();
        if ( ! empty( $t_slug ) ) {
            $matched_slugs[ $t_slug ] = true;
        }

        $new_registrations = array();
        foreach ( $registrations as $reg ) {
            if ( isset( $reg['id'] ) && (string) $reg['id'] === (string) $delete_id ) {
                $deleted_name = ! empty( $reg['name'] ) ? $reg['name'] : 'Archer';
                if ( ! empty( $reg['tournament'] ) ) {
                    $rec_slug = sanitize_title( $reg['tournament'] );
                    if ( ! empty( $rec_slug ) ) {
                        $matched_slugs[ $rec_slug ] = true;
                    }
                }
                continue;
            }
            $new_registrations[] = $reg;
        }

        $registrations = $new_registrations;

        // Delete individual wp_options rows across all resolved tournament slugs
        $deleted_option = false;
        foreach ( array_keys( $matched_slugs ) as $slug_key ) {
            $opt_key = 'sywat_t_' . $slug_key . '_' . $delete_id;
            if ( get_option( $opt_key ) !== false ) {
                if ( empty( $deleted_name ) ) {
                    $stored_reg = get_option( $opt_key );
                    if ( is_array( $stored_reg ) && ! empty( $stored_reg['name'] ) ) {
                        $deleted_name = $stored_reg['name'];
                    }
                }
                if ( delete_option( $opt_key ) ) {
                    $deleted_option = true;
                }
            }

            $master_regs = get_option( 'sywat_registrations_' . $slug_key, array() );
            if ( is_array( $master_regs ) && isset( $master_regs[ $delete_id ] ) ) {
                unset( $master_regs[ $delete_id ] );
                update_option( 'sywat_registrations_' . $slug_key, $master_regs, false );
                $deleted_option = true;
            }
        }

        // Fallback lookup by registration ID suffix if tournament slug was renamed/mismatched
        if ( ! $deleted_option && isset( $wpdb ) ) {
            $like_pattern = 'sywat\_t\_%\_' . $wpdb->esc_like( $delete_id );
            $orphan_keys  = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like_pattern
                )
            );
            if ( is_array( $orphan_keys ) ) {
                foreach ( $orphan_keys as $orphan_opt ) {
                    $stored_reg = get_option( $orphan_opt );
                    if ( is_array( $stored_reg ) && isset( $stored_reg['id'] ) && (string) $stored_reg['id'] === (string) $delete_id ) {
                        if ( empty( $deleted_name ) && ! empty( $stored_reg['name'] ) ) {
                            $deleted_name = $stored_reg['name'];
                        }
                        if ( delete_option( $orphan_opt ) ) {
                            $deleted_option = true;
                        }
                    }
                }
            }
        }

        update_option( 'sywat_registrations', $registrations, false );

        if ( $deleted_option || ! empty( $deleted_name ) ) {
            $label = ! empty( $deleted_name ) ? $deleted_name : 'Archer';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully removed <strong>' . esc_html( $label ) . '</strong> from the roster.</div>';
        }

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Archer record could not be found or was already removed.</div>';
    }
}

/**
 * Action Handler: Purge All Archers on a Shooting Line.
 *
 * @param array $tournaments   Tournaments master list.
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_handle_purge_line' ) ) {
    function sywat_handle_purge_line( $tournaments, &$registrations ) {
        $tourn_name = sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ?? '' ) );
        $line_idx   = sanitize_text_field( wp_unslash( $_POST['target_line_idx'] ?? '' ) );
        $t_slug     = sanitize_title( $tourn_name );

        $line_name = sywat_roster_resolve_line_label( $tournaments, $tourn_name, $line_idx );

        $purged_count = 0;
        $master_regs  = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( ! is_array( $master_regs ) ) $master_regs = array();

        foreach ( $registrations as $k => $reg ) {
            if ( isset( $reg['tournament'], $reg['line_index'] ) && strcasecmp( $reg['tournament'], $tourn_name ) === 0 && (string) $reg['line_index'] === (string) $line_idx ) {
                delete_option( 'sywat_t_' . $t_slug . '_' . $reg['id'] );
                if ( isset( $master_regs[ $reg['id'] ] ) ) {
                    unset( $master_regs[ $reg['id'] ] );
                }
                $purged_count++;
                unset( $registrations[ $k ] );
            }
        }

        $registrations = array_values( $registrations );

        update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        update_option( 'sywat_registrations', $registrations, false );

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully cleared <strong>' . intval( $purged_count ) . '</strong> archer(s) from <strong>' . esc_html( $line_name ) . '</strong>.</div>';
    }
}
