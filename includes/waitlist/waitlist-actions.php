<?php
/**
 * Sub-Module: Waitlist Action Handlers (Manual Promotion & Open-Line Moves)
 * Path: includes/waitlist/waitlist-actions.php
 * Description: Processes manual FIFO/group promotions from Waitlist to Pre-Reg and moves single waitlisted archers or entire family groups to open shooting lines with capacity guards and per-line $75.00 Family Cap reconciliation.
 * Compliance: Pure action layer — zero HTML echo at include time, zero script/style tags, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Safely extract the shooting lines array for a tournament regardless of whether
 * $tournaments holds full tournament config arrays or pre-extracted line maps.
 *
 * @param array  $tournaments Tournaments map.
 * @param string $tourn_name  Active tournament name.
 * @return array              Configured shooting lines array.
 */
if ( ! function_exists( 'sywat_waitlist_extract_lines' ) ) {
    function sywat_waitlist_extract_lines( $tournaments, $tourn_name ) {
        if ( ! is_array( $tournaments ) || empty( $tourn_name ) ) {
            return array();
        }

        $t_entry = null;
        if ( isset( $tournaments[ $tourn_name ] ) ) {
            $t_entry = $tournaments[ $tourn_name ];
        } else {
            foreach ( $tournaments as $k => $v ) {
                if ( strcasecmp( trim( (string) $k ), trim( (string) $tourn_name ) ) === 0 ) {
                    $t_entry = $v;
                    break;
                }
            }
        }

        if ( ! is_array( $t_entry ) ) {
            return array();
        }

        if ( function_exists( 'sywat_get_tournament_lines' ) ) {
            return sywat_get_tournament_lines( $t_entry );
        }

        if ( isset( $t_entry['lines'] ) && is_array( $t_entry['lines'] ) ) {
            return $t_entry['lines'];
        }

        return $t_entry;
    }
}

/**
 * Resolve tournament name from POST payload (supports both selected_tournament and view_tournament).
 *
 * @return string Unslashed, sanitized tournament name.
 */
if ( ! function_exists( 'sywat_waitlist_get_posted_tournament' ) ) {
    function sywat_waitlist_get_posted_tournament() {
        if ( ! empty( $_POST['selected_tournament'] ) ) {
            return trim( sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ) ) );
        }
        if ( ! empty( $_POST['view_tournament'] ) ) {
            return trim( sanitize_text_field( wp_unslash( $_POST['view_tournament'] ) ) );
        }
        return '';
    }
}

/**
 * Collect matching waitlisted candidate records for a single archer or an entire
 * line-scoped family group in deterministic FIFO order.
 *
 * @param array  $registrations   Registrations array.
 * @param string $tourn_name      Active tournament name.
 * @param string $scope           'single' or 'group'.
 * @param string $registration_id Target single registration ID.
 * @param string $family_id       Target family ID (for group scope).
 * @param string $source_line_idx Optional source line index filter.
 * @param array  $t_lines         Configured tournament lines array.
 * @return array                  Ordered list of matching Waitlist registration records.
 */
if ( ! function_exists( 'sywat_waitlist_collect_candidates' ) ) {
    function sywat_waitlist_collect_candidates( $registrations, $tourn_name, $scope, $registration_id, $family_id, $source_line_idx, $t_lines ) {
        $candidates = array();
        if ( ! is_array( $registrations ) || empty( $tourn_name ) ) {
            return $candidates;
        }

        $target_reg_id   = trim( (string) $registration_id );
        $target_fam_id   = trim( (string) $family_id );
        $target_line_str = trim( (string) $source_line_idx );

        // If group scope was requested without an explicit family_id, resolve it from registration_id
        if ( $scope === 'group' && $target_fam_id === '' && $target_reg_id !== '' ) {
            foreach ( $registrations as $reg ) {
                if ( is_array( $reg ) && isset( $reg['id'] ) && (string) $reg['id'] === $target_reg_id ) {
                    $target_fam_id = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
                    if ( $target_line_str === '' ) {
                        $target_line_str = function_exists( 'sywat_waitlist_resolve_line_idx' )
                            ? sywat_waitlist_resolve_line_idx( $reg, $t_lines )
                            : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '' );
                    }
                    break;
                }
            }
        }

        // Fallback to single scope if group has no valid family_id
        if ( $scope === 'group' && $target_fam_id === '' ) {
            $scope = 'single';
        }

        $seq = 0;
        foreach ( $registrations as $reg ) {
            if ( ! is_array( $reg ) || ! isset( $reg['tournament'], $reg['id'] ) ) {
                continue;
            }
            if ( strcasecmp( trim( (string) $reg['tournament'] ), trim( (string) $tourn_name ) ) !== 0 ) {
                continue;
            }

            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Waitlist' ) !== 0 ) {
                continue;
            }

            $resolved_l = function_exists( 'sywat_waitlist_resolve_line_idx' )
                ? sywat_waitlist_resolve_line_idx( $reg, $t_lines )
                : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

            if ( $scope === 'group' ) {
                $reg_fam = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
                if ( $reg_fam !== $target_fam_id ) {
                    continue;
                }
                if ( $target_line_str !== '' && $resolved_l !== $target_line_str ) {
                    continue;
                }
            } else {
                if ( (string) $reg['id'] !== $target_reg_id ) {
                    continue;
                }
            }

            $seq++;
            $created_raw = ! empty( $reg['created_at'] ) ? trim( (string) $reg['created_at'] ) : '';
            $created_ts  = ( $created_raw !== '' && strtotime( $created_raw ) !== false )
                ? (int) strtotime( $created_raw )
                : PHP_INT_MAX;

            $reg['_resolved_line'] = $resolved_l;
            $reg['_created_ts']    = $created_ts;
            $reg['_seq']           = $seq;
            $candidates[]          = $reg;
        }

        usort( $candidates, function( $a, $b ) {
            if ( $a['_created_ts'] !== $b['_created_ts'] ) {
                return $a['_created_ts'] <=> $b['_created_ts'];
            }
            return $a['_seq'] <=> $b['_seq'];
        } );

        return $candidates;
    }
}

/**
 * Check whether an archer name is already registered (active or waitlisted) on a specific line.
 *
 * @param array  $registrations   Registrations list.
 * @param string $tourn_name      Active tournament name.
 * @param string $target_line_idx Target line index string.
 * @param string $archer_name     Archer name to check.
 * @param string $exclude_id      Registration ID to exclude from self-check.
 * @param array  $t_lines         Tournament shooting lines array.
 * @return bool                   True if duplicate exists on target line.
 */
if ( ! function_exists( 'sywat_waitlist_has_line_duplicate' ) ) {
    function sywat_waitlist_has_line_duplicate( $registrations, $tourn_name, $target_line_idx, $archer_name, $exclude_id, $t_lines ) {
        $norm_name = strtolower( trim( (string) $archer_name ) );
        if ( $norm_name === '' || ! is_array( $registrations ) ) {
            return false;
        }

        foreach ( $registrations as $reg ) {
            if ( ! is_array( $reg ) || ! isset( $reg['tournament'], $reg['id'] ) ) {
                continue;
            }
            if ( (string) $reg['id'] === (string) $exclude_id ) {
                continue;
            }
            if ( strcasecmp( trim( (string) $reg['tournament'] ), trim( (string) $tourn_name ) ) !== 0 ) {
                continue;
            }

            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            $reg_name = ! empty( $reg['name'] ) ? strtolower( trim( (string) $reg['name'] ) ) : '';
            if ( $reg_name !== $norm_name ) {
                continue;
            }

            $reg_line = function_exists( 'sywat_waitlist_resolve_line_idx' )
                ? sywat_waitlist_resolve_line_idx( $reg, $t_lines )
                : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

            if ( $reg_line === (string) $target_line_idx ) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Action Handler: Promote a Single Waitlisted Archer or Entire Waitlisted Family Group
 * from 'Waitlist' to 'Pre-Reg' on their current shooting line.
 *
 * @param array $tournaments   Tournaments master list.
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_waitlist_handle_promote' ) ) {
    function sywat_waitlist_handle_promote( $tournaments, &$registrations ) {
        $tourn_name      = sywat_waitlist_get_posted_tournament();
        $promote_scope   = isset( $_POST['promote_scope'] ) ? sanitize_key( wp_unslash( $_POST['promote_scope'] ) ) : 'single';
        $registration_id = isset( $_POST['registration_id'] ) ? sanitize_text_field( wp_unslash( $_POST['registration_id'] ) ) : '';
        $family_id       = isset( $_POST['family_id'] ) ? sanitize_text_field( wp_unslash( $_POST['family_id'] ) ) : '';
        $source_line_idx = isset( $_POST['source_line_idx'] ) ? sanitize_text_field( wp_unslash( $_POST['source_line_idx'] ) ) : '';
        $force_override  = isset( $_POST['force_override'] ) && wp_unslash( $_POST['force_override'] ) === '1';

        if ( $promote_scope !== 'group' ) {
            $promote_scope = 'single';
        }

        if ( $tourn_name === '' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Tournament context missing. Unable to promote waitlisted archer.</div>';
        }

        $t_lines    = sywat_waitlist_extract_lines( $tournaments, $tourn_name );
        $candidates = sywat_waitlist_collect_candidates( $registrations, $tourn_name, $promote_scope, $registration_id, $family_id, $source_line_idx, $t_lines );

        if ( empty( $candidates ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Waitlisted archer record was not found or has already been promoted.</div>';
        }

        $target_line_idx = $candidates[0]['_resolved_line'];
        $t_slug          = sanitize_title( $tourn_name );
        $closed_lines    = get_option( 'sywat_closed_lines_' . $t_slug, array() );
        $line_caps       = sywat_waitlist_get_line_capacities( $tourn_name, $t_lines, $registrations, $closed_lines );
        $cap_info        = isset( $line_caps[ $target_line_idx ] ) ? $line_caps[ $target_line_idx ] : array();
        $line_label      = isset( $cap_info['line_name'] )
            ? $cap_info['line_name']
            : sywat_waitlist_format_line_label( isset( $t_lines[ $target_line_idx ] ) ? $t_lines[ $target_line_idx ] : array(), $target_line_idx );

        $needed_20  = 0;
        $needed_cub = 0;
        foreach ( $candidates as $cand ) {
            $dist = sywat_waitlist_normalize_distance( isset( $cand['distance'] ) ? $cand['distance'] : '20yd' );
            if ( $dist === 'Cub' ) {
                $needed_cub++;
            } else {
                $needed_20++;
            }
        }

        $open_20   = isset( $cap_info['open_20yd'] ) ? (int) $cap_info['open_20yd'] : 0;
        $open_cub  = isset( $cap_info['open_cub'] ) ? (int) $cap_info['open_cub'] : 0;
        $is_closed = ! empty( $cap_info['is_closed'] );

        if ( ! $force_override ) {
            if ( $is_closed ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Promotion Blocked: <strong>' . esc_html( $line_label ) . '</strong> is currently closed. Reopen the line or use an explicit capacity override to promote.</div>';
            }

            $shortages = array();
            if ( $needed_20 > $open_20 ) {
                $shortages[] = '20yd requires ' . $needed_20 . ' spot(s), but only ' . $open_20 . ' open';
            }
            if ( $needed_cub > $open_cub ) {
                $shortages[] = 'Cub requires ' . $needed_cub . ' spot(s), but only ' . $open_cub . ' open';
            }

            if ( ! empty( $shortages ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Promotion Blocked on <strong>' . esc_html( $line_label ) . '</strong>: ' . esc_html( implode( '; ', $shortages ) ) . '. Move to an open line or confirm a capacity override.</div>';
            }
        }

        $promoted_names    = array();
        $affected_families = array();

        foreach ( $candidates as $cand ) {
            unset( $cand['_resolved_line'], $cand['_created_ts'], $cand['_seq'] );

            $div_val  = isset( $cand['division'] ) ? $cand['division'] : '';
            $dist_val = sywat_waitlist_normalize_distance( isset( $cand['distance'] ) ? $cand['distance'] : '20yd' );
            $existing_paid = isset( $cand['paid'] ) ? $cand['paid'] : '';

            $cand['status']         = 'Pre-Reg';
            $cand['line_index']     = $target_line_idx;
            $cand['line_name']      = $line_label;
            $cand['distance']       = $dist_val;
            $cand['paid']           = sywat_waitlist_get_paid_tier( $div_val, $dist_val, $existing_paid );
            $cand['balance_due']    = ( $cand['paid'] === 'Field Governor' ) ? 0.00 : sywat_waitlist_get_individual_rate( $div_val, $dist_val );
            $cand['_just_promoted'] = true;

            if ( sywat_waitlist_persist_record( $cand, $registrations ) ) {
                $promoted_names[] = $cand['name'];
                if ( ! empty( $cand['family_id'] ) ) {
                    $affected_families[ trim( (string) $cand['family_id'] ) ] = true;
                }
            }
        }

        // Clean up temporary flag on any solo records that had no family_id
        foreach ( $registrations as &$r ) {
            if ( isset( $r['_just_promoted'] ) ) {
                unset( $r['_just_promoted'] );
                sywat_waitlist_persist_record( $r, $registrations );
            }
        }
        unset( $r );

        $cap_applied = false;
        foreach ( array_keys( $affected_families ) as $fam_key ) {
            $rec_res = sywat_waitlist_recalculate_line_family_fees( $tourn_name, $target_line_idx, $fam_key, $registrations, $t_lines );
            if ( ! empty( $rec_res['is_family_capped'] ) ) {
                $cap_applied = true;
            }
        }

        $count_promoted = count( $promoted_names );
        $cap_note       = $cap_applied ? ' ($75.00 Family Cap applied for this line).' : '.';

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Promoted <strong>' . intval( $count_promoted ) . '</strong> archer(s) (<strong>' . esc_html( implode( ', ', $promoted_names ) ) . '</strong>) to Pre-Reg on <strong>' . esc_html( $line_label ) . '</strong>' . esc_html( $cap_note ) . '</div>';
    }
}

/**
 * Action Handler: Move a Single Waitlisted Archer or Entire Waitlisted Family Group
 * from their current waitlisted line to a selected open shooting line as 'Pre-Reg'.
 *
 * @param array $tournaments   Tournaments master list.
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_waitlist_handle_move_line' ) ) {
    function sywat_waitlist_handle_move_line( $tournaments, &$registrations ) {
        $tourn_name       = sywat_waitlist_get_posted_tournament();
        $move_scope       = isset( $_POST['move_scope'] ) ? sanitize_key( wp_unslash( $_POST['move_scope'] ) ) : 'single';
        $registration_id  = isset( $_POST['registration_id'] ) ? sanitize_text_field( wp_unslash( $_POST['registration_id'] ) ) : '';
        $family_id        = isset( $_POST['family_id'] ) ? sanitize_text_field( wp_unslash( $_POST['family_id'] ) ) : '';
        $source_line_idx  = isset( $_POST['source_line_idx'] ) ? sanitize_text_field( wp_unslash( $_POST['source_line_idx'] ) ) : '';
        $target_line_raw  = isset( $_POST['target_line_index'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['target_line_index'] ) ) ) : '';
        $force_override   = isset( $_POST['force_override'] ) && wp_unslash( $_POST['force_override'] ) === '1';

        if ( $move_scope !== 'group' ) {
            $move_scope = 'single';
        }

        if ( $tourn_name === '' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Tournament context missing. Unable to move waitlisted archer.</div>';
        }

        if ( $target_line_raw === '' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Please select a destination shooting line before moving.</div>';
        }

        $t_lines = sywat_waitlist_extract_lines( $tournaments, $tourn_name );
        if ( ! isset( $t_lines[ $target_line_raw ] ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Selected destination shooting line could not be found.</div>';
        }

        $candidates = sywat_waitlist_collect_candidates( $registrations, $tourn_name, $move_scope, $registration_id, $family_id, $source_line_idx, $t_lines );
        if ( empty( $candidates ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Waitlisted archer record was not found or has already been moved.</div>';
        }

        $origin_line_idx = $candidates[0]['_resolved_line'];
        if ( (string) $origin_line_idx === (string) $target_line_raw ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Selected line is already their current line. Use Promote to move them onto the active roster.</div>';
        }

        $t_slug       = sanitize_title( $tourn_name );
        $closed_lines = get_option( 'sywat_closed_lines_' . $t_slug, array() );
        $line_caps    = sywat_waitlist_get_line_capacities( $tourn_name, $t_lines, $registrations, $closed_lines );
        $dest_cap     = isset( $line_caps[ $target_line_raw ] ) ? $line_caps[ $target_line_raw ] : array();
        $dest_label   = isset( $dest_cap['line_name'] )
            ? $dest_cap['line_name']
            : sywat_waitlist_format_line_label( $t_lines[ $target_line_raw ], $target_line_raw );

        // Duplicate archer check on destination line
        $duplicate_names = array();
        $needed_20       = 0;
        $needed_cub      = 0;

        foreach ( $candidates as $cand ) {
            if ( sywat_waitlist_has_line_duplicate( $registrations, $tourn_name, $target_line_raw, $cand['name'], $cand['id'], $t_lines ) ) {
                $duplicate_names[] = $cand['name'];
            }
            $dist = sywat_waitlist_normalize_distance( isset( $cand['distance'] ) ? $cand['distance'] : '20yd' );
            if ( $dist === 'Cub' ) {
                $needed_cub++;
            } else {
                $needed_20++;
            }
        }

        if ( ! empty( $duplicate_names ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Move Blocked: <strong>' . esc_html( implode( ', ', $duplicate_names ) ) . '</strong> is already registered on <strong>' . esc_html( $dest_label ) . '</strong>.</div>';
        }

        $open_20   = isset( $dest_cap['open_20yd'] ) ? (int) $dest_cap['open_20yd'] : 0;
        $open_cub  = isset( $dest_cap['open_cub'] ) ? (int) $dest_cap['open_cub'] : 0;
        $is_closed = ! empty( $dest_cap['is_closed'] );

        if ( ! $force_override ) {
            if ( $is_closed ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Move Blocked: Destination line <strong>' . esc_html( $dest_label ) . '</strong> is currently closed.</div>';
            }

            $shortages = array();
            if ( $needed_20 > $open_20 ) {
                $shortages[] = '20yd requires ' . $needed_20 . ' spot(s), but only ' . $open_20 . ' open';
            }
            if ( $needed_cub > $open_cub ) {
                $shortages[] = 'Cub requires ' . $needed_cub . ' spot(s), but only ' . $open_cub . ' open';
            }

            if ( ! empty( $shortages ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Move Blocked to <strong>' . esc_html( $dest_label ) . '</strong>: ' . esc_html( implode( '; ', $shortages ) ) . '. Please choose a line with enough open spots.</div>';
            }
        }

        // Re-scope family_id strictly to destination line so per-line $75 Family Cap remains line-isolated
        $old_fam_id    = ! empty( $candidates[0]['family_id'] ) ? trim( (string) $candidates[0]['family_id'] ) : '';
        $old_fam_name  = ! empty( $candidates[0]['family_name'] ) ? trim( (string) $candidates[0]['family_name'] ) : '';
        $new_fam_id    = '';
        $new_fam_label = '';

        if ( $move_scope === 'group' && count( $candidates ) > 1 ) {
            $base_label    = $old_fam_name !== '' ? $old_fam_name : $candidates[0]['name'];
            $new_fam_id    = 'fam_' . $t_slug . '_l' . sanitize_title( $target_line_raw ) . '_' . sanitize_title( $base_label ) . '_' . substr( md5( $old_fam_id . '_' . $target_line_raw ), 0, 4 );
            $new_fam_label = $base_label;
        }

        $moved_names = array();
        foreach ( $candidates as $cand ) {
            unset( $cand['_resolved_line'], $cand['_created_ts'], $cand['_seq'] );

            $div_val       = isset( $cand['division'] ) ? $cand['division'] : '';
            $dist_val      = sywat_waitlist_normalize_distance( isset( $cand['distance'] ) ? $cand['distance'] : '20yd' );
            $existing_paid = isset( $cand['paid'] ) ? $cand['paid'] : '';

            $cand['line_index']     = (string) $target_line_raw;
            $cand['line_name']      = $dest_label;
            $cand['distance']       = $dist_val;
            $cand['status']         = 'Pre-Reg';
            $cand['paid']           = sywat_waitlist_get_paid_tier( $div_val, $dist_val, $existing_paid );
            $cand['balance_due']    = ( $cand['paid'] === 'Field Governor' ) ? 0.00 : sywat_waitlist_get_individual_rate( $div_val, $dist_val );
            $cand['_just_promoted'] = true;

            if ( $new_fam_id !== '' ) {
                $cand['family_id']   = $new_fam_id;
                $cand['family_name'] = $new_fam_label;
            } else {
                unset( $cand['family_id'], $cand['family_name'] );
            }

            if ( sywat_waitlist_persist_record( $cand, $registrations ) ) {
                $moved_names[] = $cand['name'];
            }
        }

        // Clean up temporary flag on solo moved records
        foreach ( $registrations as &$r ) {
            if ( isset( $r['_just_promoted'] ) ) {
                unset( $r['_just_promoted'] );
                sywat_waitlist_persist_record( $r, $registrations );
            }
        }
        unset( $r );

        $cap_applied = false;
        if ( $new_fam_id !== '' ) {
            $rec_res = sywat_waitlist_recalculate_line_family_fees( $tourn_name, $target_line_raw, $new_fam_id, $registrations, $t_lines );
            if ( ! empty( $rec_res['is_family_capped'] ) ) {
                $cap_applied = true;
            }
        }

        // Reconcile origin line family if active members remained behind
        if ( $old_fam_id !== '' ) {
            sywat_waitlist_recalculate_line_family_fees( $tourn_name, $origin_line_idx, $old_fam_id, $registrations, $t_lines );
        }

        $count_moved = count( $moved_names );
        $cap_note    = $cap_applied ? ' ($75.00 Family Cap applied on destination line).' : '.';

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Moved <strong>' . intval( $count_moved ) . '</strong> archer(s) (<strong>' . esc_html( implode( ', ', $moved_names ) ) . '</strong>) to <strong>' . esc_html( $dest_label ) . '</strong> as Pre-Reg' . esc_html( $cap_note ) . '</div>';
    }
}
