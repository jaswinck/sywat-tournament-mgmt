<?php
/**
 * Sub-Module: Archer Roster Action Handlers (Edit, Remove & Line Purge)
 * Path: includes/roster/roster-actions.php
 * Description: Handles editing archer registrations (including Pre-Reg / Waitlist status sync and database-driven shooting style & division rule enforcement), removing individual shooters, and clearing active line rosters while reconciling per-line $75.00 Family Cap fees and multi-store persistence.
 * Compliance: Pure action layer — zero HTML echo at include time, zero script/style tags, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolve canonical shooting line index key for a registration record.
 *
 * @param array $reg             Archer registration record.
 * @param array $available_lines Tournament shooting lines array.
 * @return string                Resolved string line index key.
 */
if ( ! function_exists( 'sywat_roster_resolve_line_idx' ) ) {
    function sywat_roster_resolve_line_idx( $reg, $available_lines ) {
        if ( function_exists( 'sywat_waitlist_resolve_line_idx' ) ) {
            return sywat_waitlist_resolve_line_idx( $reg, $available_lines );
        }

        if ( ! is_array( $available_lines ) ) {
            $available_lines = array();
        }

        if ( isset( $reg['line_index'] ) && $reg['line_index'] !== '' ) {
            $idx = (string) $reg['line_index'];
            if ( isset( $available_lines[ $idx ] ) ) {
                return $idx;
            }
            foreach ( $available_lines as $l_key => $l_data ) {
                if ( is_array( $l_data ) && ! empty( $l_data['name'] ) && strcasecmp( trim( (string) $l_data['name'] ), trim( $idx ) ) === 0 ) {
                    return (string) $l_key;
                }
            }
        }

        if ( ! empty( $reg['line_name'] ) ) {
            $target_name = trim( (string) $reg['line_name'] );
            foreach ( $available_lines as $l_key => $l_data ) {
                if ( is_array( $l_data ) && ! empty( $l_data['name'] ) && strcasecmp( trim( (string) $l_data['name'] ), $target_name ) === 0 ) {
                    return (string) $l_key;
                }
            }
        }

        return isset( $reg['line_index'] ) && $reg['line_index'] !== '' ? (string) $reg['line_index'] : '0';
    }
}

/**
 * Enforce division-based shooting style rules using database configuration so invalid combinations are normalized.
 *
 * @param string $style    Submitted shooting style.
 * @param string $division Submitted age division.
 * @return string          Validated shooting style.
 */
if ( ! function_exists( 'sywat_roster_sanitize_style_for_division' ) ) {
    function sywat_roster_sanitize_style_for_division( $style, $division ) {
        $clean_style = trim( (string) $style );
        $clean_div   = trim( (string) $division );

        if ( strcasecmp( $clean_div, 'No Show' ) === 0 || strcasecmp( $clean_style, 'No Show' ) === 0 ) {
            return 'No Show';
        }

        if ( function_exists( 'sywat_resolve_style_and_division' ) ) {
            $resolved = sywat_resolve_style_and_division( $clean_style, $clean_div );
            if ( is_array( $resolved ) && ! empty( $resolved['style'] ) ) {
                return $resolved['style'];
            }
        }

        // Legacy fallback if shooting styles engine is unavailable
        if ( stripos( $clean_style, 'Genesis' ) !== false && $clean_div !== 'Cub (8 - 11)' ) {
            return 'FS – Freestyle';
        }

        $is_adult_only_style = (
            stripos( $clean_style, 'FS Pro' ) !== false ||
            stripos( $clean_style, 'FSL –' ) !== false ||
            stripos( $clean_style, 'TRAD' ) !== false ||
            stripos( $clean_style, 'Traditional' ) !== false
        );

        $adult_divisions = array(
            'Adult (18 - 49)',
            'Senior (50 - 59)',
            'Silver Senior (60 - 69)',
            'Master Senior (70+)',
        );

        if ( $is_adult_only_style && ! in_array( $clean_div, $adult_divisions, true ) ) {
            return 'FS – Freestyle';
        }

        $is_longbow = ( stripos( $clean_style, 'LB' ) !== false || stripos( $clean_style, 'Longbow' ) !== false );
        if ( $is_longbow && $clean_div !== 'Adult (18 - 49)' ) {
            return 'FS – Freestyle';
        }

        return $clean_style;
    }
}

/**
 * Remove a registration record cleanly from per-record option storage, tournament-scoped
 * cache, legacy global cache (only if present), and the in-memory $registrations array.
 *
 * @param string $tourn_name    Tournament name.
 * @param string $reg_id        Registration ID to remove.
 * @param array  $registrations In-memory registrations list passed by reference.
 * @return void
 */
if ( ! function_exists( 'sywat_roster_delete_record_storage' ) ) {
    function sywat_roster_delete_record_storage( $tourn_name, $reg_id, &$registrations ) {
        $t_slug     = sanitize_title( (string) $tourn_name );
        $target_id  = (string) $reg_id;

        if ( $t_slug !== '' && $target_id !== '' ) {
            delete_option( 'sywat_t_' . $t_slug . '_' . $target_id );

            $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
            if ( is_array( $master_regs ) && isset( $master_regs[ $target_id ] ) ) {
                unset( $master_regs[ $target_id ] );
                update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
            }
        }

        $legacy_global = get_option( 'sywat_registrations', false );
        if ( is_array( $legacy_global ) ) {
            $filtered_global = array_values( array_filter( $legacy_global, function( $item ) use ( $target_id ) {
                return ! ( is_array( $item ) && isset( $item['id'] ) && (string) $item['id'] === $target_id );
            } ) );
            update_option( 'sywat_registrations', $filtered_global, false );
        }

        if ( is_array( $registrations ) ) {
            $registrations = array_values( array_filter( $registrations, function( $item ) use ( $target_id ) {
                return ! ( is_array( $item ) && isset( $item['id'] ) && (string) $item['id'] === $target_id );
            } ) );
        }
    }
}

/**
 * Action Handler: Save Edited Archer Registration from the Roster Modal.
 * Synchronizes Pre-Reg / Waitlist status, enforces database-driven style & division rules,
 * enforces $0.00 balance due on Waitlist, validates duplicate names on target lines,
 * and reconciles per-line $75.00 Family Cap fees.
 *
 * @param array $tournaments   Master tournaments array.
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_handle_save_edit' ) ) {
    function sywat_handle_save_edit( $tournaments, &$registrations ) {
        $reg_id     = isset( $_POST['registration_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['registration_id'] ) ) ) : '';
        $tourn_name = ! empty( $_POST['view_tournament'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['view_tournament'] ) ) )
            : ( ! empty( $_POST['selected_tournament'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) ) ) : '' );

        if ( $reg_id === '' || $tourn_name === '' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Missing archer or tournament identifier. Unable to save changes.</div>';
        }

        $t_slug  = sanitize_title( $tourn_name );
        $opt_key = 'sywat_t_' . $t_slug . '_' . $reg_id;
        $reg     = get_option( $opt_key, null );

        if ( ! is_array( $reg ) && is_array( $registrations ) ) {
            foreach ( $registrations as $candidate ) {
                if ( is_array( $candidate ) && isset( $candidate['id'] ) && (string) $candidate['id'] === $reg_id ) {
                    $reg = $candidate;
                    break;
                }
            }
        }

        if ( ! is_array( $reg ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Archer registration record could not be found.</div>';
        }

        $t_lines = array();
        if ( is_array( $tournaments ) && isset( $tournaments[ $tourn_name ] ) ) {
            $t_lines = function_exists( 'sywat_get_tournament_lines' )
                ? sywat_get_tournament_lines( $tournaments[ $tourn_name ] )
                : ( isset( $tournaments[ $tourn_name ]['lines'] ) ? $tournaments[ $tourn_name ]['lines'] : array() );
        }

        $orig_name     = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : '';
        $orig_line_idx = sywat_roster_resolve_line_idx( $reg, $t_lines );
        $orig_fam_id   = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';

        $edit_name     = isset( $_POST['edit_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['edit_name'] ) ) ) : $orig_name;
        $new_line_idx  = isset( $_POST['edit_line_index'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['edit_line_index'] ) ) ) : $orig_line_idx;
        $raw_status    = isset( $_POST['edit_status'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['edit_status'] ) ) ) : ( isset( $reg['status'] ) ? $reg['status'] : 'Pre-Reg' );
        $raw_division  = isset( $_POST['edit_division'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['edit_division'] ) ) ) : ( isset( $reg['division'] ) ? $reg['division'] : 'Adult (18 - 49)' );
        $gender        = isset( $_POST['edit_gender'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['edit_gender'] ) ) ) : ( isset( $reg['gender'] ) ? $reg['gender'] : 'Male' );
        $raw_style     = isset( $_POST['edit_style'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['edit_style'] ) ) ) : ( isset( $reg['style'] ) ? $reg['style'] : 'FS – Freestyle' );
        $pay_opt       = isset( $_POST['edit_pay_option'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['edit_pay_option'] ) ) ) : 'Individual';
        $sel_fam_id    = isset( $_POST['edit_family_group_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['edit_family_group_id'] ) ) ) : '';
        $posted_due    = isset( $_POST['edit_balance_due'] ) ? max( 0.00, floatval( wp_unslash( $_POST['edit_balance_due'] ) ) ) : 0.00;

        if ( $edit_name === '' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Archer full name is required.</div>';
        }

        // Normalize status selection
        if ( strcasecmp( $raw_status, 'Waitlist' ) === 0 ) {
            $new_status = 'Waitlist';
        } elseif ( strcasecmp( $raw_status, 'Cancelled' ) === 0 ) {
            $new_status = 'Cancelled';
        } else {
            $new_status = 'Pre-Reg';
        }

        // Duplicate name-on-line guard for non-cancelled records
        if ( $new_status !== 'Cancelled' && is_array( $registrations ) ) {
            foreach ( $registrations as $other ) {
                if ( ! is_array( $other ) || ! isset( $other['id'], $other['tournament'] ) ) {
                    continue;
                }
                if ( (string) $other['id'] === $reg_id ) {
                    continue;
                }
                if ( strcasecmp( trim( (string) $other['tournament'] ), $tourn_name ) !== 0 ) {
                    continue;
                }

                $other_status = isset( $other['status'] ) ? trim( (string) $other['status'] ) : '';
                if ( strcasecmp( $other_status, 'Cancelled' ) === 0 ) {
                    continue;
                }

                $other_line = sywat_roster_resolve_line_idx( $other, $t_lines );
                $other_name = isset( $other['name'] ) ? trim( (string) $other['name'] ) : '';

                if ( $other_line === $new_line_idx && strcasecmp( $other_name, $edit_name ) === 0 ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Edit Blocked: <strong>' . esc_html( $edit_name ) . '</strong> is already registered on that shooting line.</div>';
                }
            }
        }

        // Normalize & enforce database-driven shooting style and age division rules
        $division = ( $raw_division !== '' && strcasecmp( $raw_division, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_division' ) )
            ? sywat_shooting_styles_normalize_division( $raw_division )
            : $raw_division;

        $style = ( $raw_style !== '' && strcasecmp( $raw_style, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_style' ) )
            ? sywat_shooting_styles_normalize_style( $raw_style )
            : $raw_style;

        if ( strcasecmp( $division, 'No Show' ) !== 0 && strcasecmp( $style, 'No Show' ) !== 0 && function_exists( 'sywat_resolve_style_and_division' ) ) {
            $resolved = sywat_resolve_style_and_division( $style, $division );
            if ( is_array( $resolved ) ) {
                if ( ! empty( $resolved['style'] ) ) {
                    $style = $resolved['style'];
                }
                if ( ! empty( $resolved['division'] ) ) {
                    $division = $resolved['division'];
                }
            }
        } else {
            $style = sywat_roster_sanitize_style_for_division( $style, $division );
        }

        $distance = function_exists( 'sywat_shooting_styles_get_division_distance' )
            ? sywat_shooting_styles_get_division_distance( $division )
            : ( ( $division === 'Cub (8 - 11)' ) ? 'Cub' : '20yd' );

        $line_name = '';
        if ( isset( $t_lines[ $new_line_idx ] ) ) {
            $line_name = function_exists( 'sywat_roster_format_line_display' )
                ? sywat_roster_format_line_display( $t_lines[ $new_line_idx ], $new_line_idx )
                : ( isset( $t_lines[ $new_line_idx ]['name'] ) ? $t_lines[ $new_line_idx ]['name'] : '' );
        }
        if ( $line_name === '' ) {
            $line_name = ! empty( $reg['line_name'] ) ? $reg['line_name'] : ( 'Line ' . ( (int) $new_line_idx + 1 ) );
        }

        $reg['tournament'] = $tourn_name;
        $reg['name']       = $edit_name;
        $reg['line_index'] = $new_line_idx;
        $reg['line_name']  = $line_name;
        $reg['distance']   = $distance;
        $reg['division']   = $division;
        $reg['gender']     = $gender;
        $reg['style']      = $style;
        $reg['status']     = $new_status;

        // Resolve line-scoped family group assignment
        if ( $pay_opt === 'Family' ) {
            $families_by_line = function_exists( 'sywat_waitlist_build_family_labels_by_line' )
                ? sywat_waitlist_build_family_labels_by_line( $tourn_name, $t_lines, $registrations )
                : array();
            $line_families = isset( $families_by_line[ $new_line_idx ] ) ? $families_by_line[ $new_line_idx ] : array();

            if ( $sel_fam_id !== '' ) {
                $reg['family_id']   = $sel_fam_id;
                $reg['family_name'] = isset( $line_families[ $sel_fam_id ] )
                    ? $line_families[ $sel_fam_id ]
                    : ( ! empty( $reg['family_name'] ) ? $reg['family_name'] : $edit_name . ' Group' );
            } elseif ( empty( $reg['family_id'] ) || $orig_line_idx !== $new_line_idx ) {
                $reg['family_id']   = 'fam_' . $t_slug . '_l' . sanitize_title( $new_line_idx ) . '_' . sanitize_title( $edit_name ) . '_' . substr( md5( uniqid( '', true ) ), 0, 4 );
                $reg['family_name'] = $edit_name . ' Group';
            }
        } else {
            unset( $reg['family_id'], $reg['family_name'] );
        }

        // Synchronize paid tier and balance_due with registration status
        if ( $new_status === 'Waitlist' ) {
            $reg['paid']        = 'Waitlist';
            $reg['balance_due'] = 0.00;
            $reg['checked_in']  = 0;
        } elseif ( $new_status === 'Cancelled' ) {
            $reg['paid']        = 'Cancelled';
            $reg['balance_due'] = 0.00;
            $reg['checked_in']  = 0;
        } else {
            if ( $pay_opt === 'Family' ) {
                $reg['paid'] = 'Family';
            } elseif ( $pay_opt === 'Field Governor' ) {
                $reg['paid'] = 'Field Governor';
            } elseif ( function_exists( 'sywat_shooting_styles_get_division_rate_tier' ) ) {
                $reg['paid'] = sywat_shooting_styles_get_division_rate_tier( $division );
            } elseif ( function_exists( 'sywat_waitlist_get_paid_tier' ) ) {
                $reg['paid'] = sywat_waitlist_get_paid_tier( $division, $distance );
            } else {
                $reg['paid'] = in_array( $division, array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' ), true ) ? 'Youth' : 'Adult';
            }
            $reg['balance_due'] = ( $reg['paid'] === 'Field Governor' ) ? 0.00 : $posted_due;
        }

        if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
            sywat_waitlist_persist_record( $reg, $registrations );
        } else {
            update_option( $opt_key, $reg, false );
            $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
            if ( ! is_array( $master_regs ) ) {
                $master_regs = array();
            }
            $master_regs[ $reg_id ] = $reg;
            update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );

            if ( is_array( $registrations ) ) {
                foreach ( $registrations as &$r ) {
                    if ( is_array( $r ) && isset( $r['id'] ) && (string) $r['id'] === $reg_id ) {
                        $r = $reg;
                        break;
                    }
                }
                unset( $r );
            }
        }

        // Reconcile per-line $75.00 Family Cap fees for affected family groups
        $new_fam_id = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
        if ( function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
            if ( $orig_fam_id !== '' ) {
                sywat_waitlist_recalculate_line_family_fees( $tourn_name, $orig_line_idx, $orig_fam_id, $registrations, $t_lines );
            }
            if ( $new_fam_id !== '' && ( $new_fam_id !== $orig_fam_id || $new_line_idx !== $orig_line_idx ) ) {
                sywat_waitlist_recalculate_line_family_fees( $tourn_name, $new_line_idx, $new_fam_id, $registrations, $t_lines );
            }
        }

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Updated registration details for <strong>' . esc_html( $edit_name ) . '</strong> (' . esc_html( $new_status ) . ').</div>';
    }
}

/**
 * Action Handler: Remove a Single Shooter (Active or Waitlisted) from the Roster
 * and reconcile any remaining family group fees on that shooting line.
 *
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_handle_delete_shooter' ) ) {
    function sywat_handle_delete_shooter( &$registrations ) {
        $reg_id     = isset( $_POST['registration_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['registration_id'] ) ) ) : '';
        $tourn_name = ! empty( $_POST['view_tournament'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['view_tournament'] ) ) )
            : ( ! empty( $_POST['selected_tournament'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) ) ) : '' );

        if ( $reg_id === '' || $tourn_name === '' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Missing archer identifier. Unable to remove archer.</div>';
        }

        $t_slug      = sanitize_title( $tourn_name );
        $target_reg  = get_option( 'sywat_t_' . $t_slug . '_' . $reg_id, null );

        if ( ! is_array( $target_reg ) && is_array( $registrations ) ) {
            foreach ( $registrations as $candidate ) {
                if ( is_array( $candidate ) && isset( $candidate['id'] ) && (string) $candidate['id'] === $reg_id ) {
                    $target_reg = $candidate;
                    break;
                }
            }
        }

        $archer_name = is_array( $target_reg ) && ! empty( $target_reg['name'] ) ? $target_reg['name'] : 'Archer';
        $line_idx    = is_array( $target_reg ) && isset( $target_reg['line_index'] ) ? (string) $target_reg['line_index'] : '';
        $family_id   = is_array( $target_reg ) && ! empty( $target_reg['family_id'] ) ? trim( (string) $target_reg['family_id'] ) : '';

        sywat_roster_delete_record_storage( $tourn_name, $reg_id, $registrations );

        if ( $family_id !== '' && $line_idx !== '' && function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
            $all_tournaments = get_option( 'sywat_tournaments_list', array() );
            $t_lines         = ( is_array( $all_tournaments ) && isset( $all_tournaments[ $tourn_name ] ) && function_exists( 'sywat_get_tournament_lines' ) )
                ? sywat_get_tournament_lines( $all_tournaments[ $tourn_name ] )
                : array();
            sywat_waitlist_recalculate_line_family_fees( $tourn_name, $line_idx, $family_id, $registrations, $t_lines );
        }

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Removed <strong>' . esc_html( $archer_name ) . '</strong> from <strong>' . esc_html( $tourn_name ) . '</strong>.</div>';
    }
}

/**
 * Action Handler: Clear All Active Shooters from a Shooting Line while preserving
 * waitlisted records so desk operators can promote from the waitlist queue.
 *
 * @param array $tournaments   Master tournaments array.
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_handle_purge_line' ) ) {
    function sywat_handle_purge_line( $tournaments, &$registrations ) {
        $tourn_name      = ! empty( $_POST['selected_tournament'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) ) )
            : ( ! empty( $_POST['view_tournament'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['view_tournament'] ) ) ) : '' );
        $target_line_idx = isset( $_POST['target_line_idx'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['target_line_idx'] ) ) ) : '';

        if ( $tourn_name === '' || $target_line_idx === '' || $target_line_idx === 'all' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a specific shooting line before clearing the roster.</div>';
        }

        $t_lines = array();
        if ( is_array( $tournaments ) && isset( $tournaments[ $tourn_name ] ) ) {
            $t_lines = function_exists( 'sywat_get_tournament_lines' )
                ? sywat_get_tournament_lines( $tournaments[ $tourn_name ] )
                : ( isset( $tournaments[ $tourn_name ]['lines'] ) ? $tournaments[ $tourn_name ]['lines'] : array() );
        }

        $line_label = isset( $t_lines[ $target_line_idx ] )
            ? ( function_exists( 'sywat_roster_format_line_display' ) ? sywat_roster_format_line_display( $t_lines[ $target_line_idx ], $target_line_idx ) : 'Selected Line' )
            : 'Selected Line';

        $ids_to_purge = array();
        if ( is_array( $registrations ) ) {
            foreach ( $registrations as $reg ) {
                if ( ! is_array( $reg ) || ! isset( $reg['id'], $reg['tournament'] ) ) {
                    continue;
                }
                if ( strcasecmp( trim( (string) $reg['tournament'] ), $tourn_name ) !== 0 ) {
                    continue;
                }

                $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
                if ( strcasecmp( $status, 'Waitlist' ) === 0 ) {
                    continue;
                }

                $resolved_l = sywat_roster_resolve_line_idx( $reg, $t_lines );
                if ( $resolved_l === $target_line_idx ) {
                    $ids_to_purge[] = (string) $reg['id'];
                }
            }
        }

        $purged_count = 0;
        foreach ( $ids_to_purge as $purge_id ) {
            sywat_roster_delete_record_storage( $tourn_name, $purge_id, $registrations );
            $purged_count++;
        }

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Cleared <strong>' . intval( $purged_count ) . '</strong> active archer(s) from <strong>' . esc_html( $line_label ) . '</strong>.</div>';
    }
}
