<?php
/**
 * Sub-Module: Archer Roster Actions & Sanitization Engine
 * Path: includes/roster/roster-actions.php
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Calculate Paid Category Status based on Division & Payment Option
 */
if ( ! function_exists( 'sywat_calculate_paid_status' ) ) {
    function sywat_calculate_paid_status( $pay_option, $division ) {
        if ( $pay_option === 'Family' ) return 'Family';
        if ( $pay_option === 'Field Governor' ) return 'Field Governor';

        $youth_divs = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' );
        return in_array( $division, $youth_divs ) ? 'Youth' : 'Adult';
    }
}

/**
 * Enforce Division vs. Shooting Style Restrictions
 */
if ( ! function_exists( 'sywat_sanitize_style_for_division' ) ) {
    function sywat_sanitize_style_for_division( $style, $division ) {
        // Genesis style restricted to Cub division
        if ( strpos( $style, 'Genesis' ) !== false && $division !== 'Cub (8 - 11)' ) {
            return 'FS – Freestyle';
        }

        // Pro, FSL, & Traditional restricted to Adult & Senior divisions
        $is_pro_fsl_trad = ( strpos( $style, 'FS Pro' ) !== false || strpos( $style, 'FSL –' ) !== false || strpos( $style, 'TRAD' ) !== false || stripos( $style, 'Traditional' ) !== false );
        $allowed_adult_senior = array( 'Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)' );
        if ( $is_pro_fsl_trad && ! in_array( $division, $allowed_adult_senior ) ) {
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
 * Resolve Shooting Line Numeric Index against Line Data
 */
if ( ! function_exists( 'sywat_roster_resolve_line_idx' ) ) {
    function sywat_roster_resolve_line_idx( $reg, $lines ) {
        if ( isset( $reg['line_index'] ) && $reg['line_index'] !== '' ) {
            $idx = (string)$reg['line_index'];
            if ( isset( $lines[$idx] ) ) return $idx;

            foreach ( $lines as $l_key => $l_data ) {
                if ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), trim( $idx ) ) === 0 ) {
                    return (string)$l_key;
                }
            }
        }

        if ( isset( $reg['line_name'] ) && ! empty( $reg['line_name'] ) ) {
            foreach ( $lines as $l_key => $l_data ) {
                if ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), trim( $reg['line_name'] ) ) === 0 ) {
                    return (string)$l_key;
                }
            }
        }

        return isset( $reg['line_index'] ) ? (string)$reg['line_index'] : '0';
    }
}

/**
 * Action Handler: Save Edited Archer Profile
 */
if ( ! function_exists( 'sywat_handle_save_edit' ) ) {
    function sywat_handle_save_edit( $tournaments, &$registrations ) {
        $edit_id       = sanitize_text_field( $_POST['registration_id'] ?? '' );
        $tourn_context = sanitize_text_field( $_POST['view_tournament'] ?? '' );
        $t_slug        = sanitize_title( $tourn_context );

        $opt_key = 'sywat_t_' . $t_slug . '_' . $edit_id;
        $reg     = get_option( $opt_key );

        if ( ! $reg && ! empty( $registrations ) ) {
            foreach ( $registrations as $r ) {
                if ( isset( $r['id'] ) && (string)$r['id'] === (string)$edit_id ) {
                    $reg = $r;
                    break;
                }
            }
        }

        if ( $reg ) {
            $edit_name = trim( sanitize_text_field( $_POST['edit_name'] ?? '' ) );
            $line_idx  = sanitize_text_field( $_POST['edit_line_index'] ?? '0' );
            $line_name = isset( $tournaments[$tourn_context][$line_idx]['name'] ) ? $tournaments[$tourn_context][$line_idx]['name'] : 'Line ' . ((int)$line_idx + 1);
            $division  = sanitize_text_field( $_POST['edit_division'] ?? '' );
            $distance  = ( $division === 'Cub (8 - 11)' ) ? 'Cub' : '20yd';
            $pay_opt   = sanitize_text_field( $_POST['edit_pay_option'] ?? '' );
            $style     = sanitize_text_field( $_POST['edit_style'] ?? '' );
            $due_val   = isset( $_POST['edit_balance_due'] ) ? floatval( $_POST['edit_balance_due'] ) : 0;
            
            $orig_name = isset( $reg['name'] ) ? trim( $reg['name'] ) : '';
            $orig_line = isset( $reg['line_index'] ) ? (string)$reg['line_index'] : '';

            $reg['name']        = $edit_name;
            $reg['line_index']  = $line_idx;
            $reg['line_name']   = $line_name;
            $reg['distance']    = $distance;
            $reg['division']    = $division;
            $reg['gender']      = sanitize_text_field( $_POST['edit_gender'] ?? '' );
            $reg['style']       = sywat_sanitize_style_for_division( $style, $division );
            $reg['paid']        = sywat_calculate_paid_status( $pay_opt, $division );
            $reg['balance_due'] = $due_val;

            // Handle strict Family Anchor assignments
            if ( $reg['paid'] === 'Family' ) {
                $sel_fam_id = isset( $_POST['edit_family_group_id'] ) ? sanitize_text_field( $_POST['edit_family_group_id'] ) : '';

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
                    $reg['family_id']   = 'fam_' . $t_slug . '_l' . sanitize_title( $line_idx ) . '_' . sanitize_title( $edit_name ) . '_' . substr( md5( uniqid() ), 0, 4 );
                    $reg['family_name'] = $edit_name . ' (Family Cap)';
                }
            } else {
                unset( $reg['family_id'] );
                unset( $reg['family_name'] );
            }

            update_option( $opt_key, $reg, false );

            foreach ( $registrations as &$r ) {
                if ( isset( $r['id'] ) && (string)$r['id'] === (string)$edit_id ) {
                    $r = $reg;
                    break;
                }
            }
            unset( $r );

            $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
            if ( ! is_array( $master_regs ) ) $master_regs = array();
            $master_regs[$edit_id] = $reg;
            update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
            update_option( 'sywat_registrations', $registrations, false );

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully updated archer: <strong>' . esc_html( $reg['name'] ) . '</strong>.</div>';
        }

        return '';
    }
}

/**
 * Action Handler: Delete Archer Registration
 */
if ( ! function_exists( 'sywat_handle_delete_shooter' ) ) {
    function sywat_handle_delete_shooter( &$registrations ) {
        $delete_id     = sanitize_text_field( $_POST['registration_id'] ?? '' );
        $tourn_context = sanitize_text_field( $_POST['view_tournament'] ?? '' );
        $t_slug        = sanitize_title( $tourn_context );

        $deleted_name = '';
        $new_registrations = array();

        foreach ( $registrations as $reg ) {
            if ( isset( $reg['id'] ) && (string)$reg['id'] === (string)$delete_id ) {
                $deleted_name = isset( $reg['name'] ) ? $reg['name'] : 'Archer';
                delete_option( 'sywat_t_' . $t_slug . '_' . $delete_id );
                continue;
            }
            $new_registrations[] = $reg;
        }

        $registrations = $new_registrations;

        $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( is_array( $master_regs ) && isset( $master_regs[$delete_id] ) ) {
            unset( $master_regs[$delete_id] );
            update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        }
        update_option( 'sywat_registrations', $registrations, false );

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully removed <strong>' . esc_html( $deleted_name ) . '</strong> from the roster.</div>';
    }
}

/**
 * Action Handler: Purge All Archers on a Shooting Line
 */
if ( ! function_exists( 'sywat_handle_purge_line' ) ) {
    function sywat_handle_purge_line( $tournaments, &$registrations ) {
        $tourn_name = sanitize_text_field( $_POST['selected_tournament'] ?? '' );
        $line_idx   = sanitize_text_field( $_POST['target_line_idx'] ?? '' );
        $t_slug     = sanitize_title( $tourn_name );

        $line_data = isset( $tournaments[$tourn_name][$line_idx] ) ? $tournaments[$tourn_name][$line_idx] : array();
        $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ((int)$line_idx + 1);

        $purged_count = 0;
        $master_regs  = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( ! is_array( $master_regs ) ) $master_regs = array();

        foreach ( $registrations as $k => $reg ) {
            if ( isset( $reg['tournament'], $reg['line_index'] ) && strcasecmp( $reg['tournament'], $tourn_name ) === 0 && (string)$reg['line_index'] === (string)$line_idx ) {
                delete_option( 'sywat_t_' . $t_slug . '_' . $reg['id'] );
                if ( isset( $master_regs[$reg['id']] ) ) {
                    unset( $master_regs[$reg['id']] );
                }
                $purged_count++;
                unset( $registrations[$k] );
            }
        }

        $registrations = array_values( $registrations );

        update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        update_option( 'sywat_registrations', $registrations, false );

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully cleared <strong>' . $purged_count . '</strong> archer(s) from <strong>' . esc_html( $line_name ) . '</strong>.</div>';
    }
}
