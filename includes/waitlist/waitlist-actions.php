<?php
/**
 * Sub-Module: Waitlist Action Handlers (Manual Promotion, Top-Bar Open-Line Moves & Removal)
 * Path: includes/waitlist/waitlist-actions.php
 * Description: Processes single/group promotions from Waitlist to Pre-Reg, multi-select moves from the top-of-modal Open Line toolbar, waitlist entry removal, database-driven division/style/distance normalization, capacity guards, and per-line $75.00 Family Cap reconciliation.
 * Compliance: Pure action layer — zero HTML echo at include time, zero script/style tags, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.0.0
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
 * Resolve tournament name from POST payload (supports waitlist_tournament, selected_tournament, and view_tournament).
 *
 * @return string Unslashed, sanitized tournament name.
 */
if ( ! function_exists( 'sywat_waitlist_get_posted_tournament' ) ) {
    function sywat_waitlist_get_posted_tournament() {
        if ( ! empty( $_POST['waitlist_tournament'] ) ) {
            return trim( sanitize_text_field( wp_unslash( $_POST['waitlist_tournament'] ) ) );
        }
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
 * Resolve canonical distance ('Cub' or '20yd') for a registration record using
 * database-configured division settings first, with safe fallback to stored distance.
 *
 * @param array $record Registration record.
 * @return string       Canonical distance ('Cub' or '20yd').
 */
if ( ! function_exists( 'sywat_waitlist_resolve_record_distance' ) ) {
    function sywat_waitlist_resolve_record_distance( $record ) {
        $raw_div = isset( $record['division'] ) ? trim( (string) $record['division'] ) : '';
        if ( $raw_div !== '' && strcasecmp( $raw_div, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_get_division_distance' ) ) {
            return sywat_shooting_styles_get_division_distance( $raw_div );
        }
        return sywat_waitlist_normalize_distance( isset( $record['distance'] ) ? $record['distance'] : '20yd' );
    }
}

/**
 * Resolve default active division label from database configuration for a target distance ('Cub' vs '20yd').
 *
 * @param string $dist_val Target distance ('Cub' or '20yd').
 * @return string          Canonical active division label.
 */
if ( ! function_exists( 'sywat_waitlist_get_default_division_for_distance' ) ) {
    function sywat_waitlist_get_default_division_for_distance( $dist_val ) {
        $want_cub = ( strcasecmp( trim( (string) $dist_val ), 'Cub' ) === 0 );

        if ( function_exists( 'sywat_get_division_records' ) ) {
            $records = sywat_get_division_records( false );
            if ( is_array( $records ) ) {
                foreach ( $records as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    $rec_dist = ( isset( $rec['distance'] ) && $rec['distance'] === 'Cub' ) ? 'Cub' : '20yd';
                    if ( $want_cub && $rec_dist === 'Cub' ) {
                        return $rec['label'];
                    }
                    if ( ! $want_cub && $rec_dist === '20yd' && isset( $rec['rate_tier'] ) && $rec['rate_tier'] === 'Adult' ) {
                        return $rec['label'];
                    }
                }
                if ( ! $want_cub ) {
                    foreach ( $records as $rec ) {
                        if ( ! empty( $rec['label'] ) && ( ! isset( $rec['distance'] ) || $rec['distance'] !== 'Cub' ) ) {
                            return $rec['label'];
                        }
                    }
                }
            }
        }

        return $want_cub ? 'Cub (8 - 11)' : 'Adult (18 - 49)';
    }
}

/**
 * Clean up any legacy 'No Show' division/gender/style flags and normalize division,
 * shooting style, and distance against database configuration before promoting or moving
 * a waitlisted record onto an active Pre-Reg shooting line.
 *
 * @param array  $record   Registration record passed by reference.
 * @param string $dist_val Normalized distance ('20yd' or 'Cub') passed by reference.
 * @return void
 */
if ( ! function_exists( 'sywat_waitlist_sanitize_promoted_record' ) ) {
    function sywat_waitlist_sanitize_promoted_record( &$record, &$dist_val ) {
        $raw_div = isset( $record['division'] ) ? trim( (string) $record['division'] ) : '';
        if ( $raw_div === '' || $raw_div === '—' || strcasecmp( $raw_div, 'No Show' ) === 0 ) {
            $record['division'] = sywat_waitlist_get_default_division_for_distance( $dist_val );
        } elseif ( function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
            $record['division'] = sywat_shooting_styles_normalize_division( $raw_div );
        }

        if ( isset( $record['gender'] ) && strcasecmp( trim( (string) $record['gender'] ), 'No Show' ) === 0 ) {
            $record['gender'] = '';
        }

        $raw_style = isset( $record['style'] ) ? trim( (string) $record['style'] ) : '';
        if ( strcasecmp( $raw_style, 'No Show' ) === 0 ) {
            $record['style'] = '';
        } elseif ( $raw_style !== '' && $raw_style !== '—' && function_exists( 'sywat_shooting_styles_normalize_style' ) ) {
            $record['style'] = sywat_shooting_styles_normalize_style( $raw_style );
        }

        if ( ! empty( $record['style'] ) && ! empty( $record['division'] ) && function_exists( 'sywat_resolve_style_and_division' ) ) {
            $resolved = sywat_resolve_style_and_division( $record['style'], $record['division'] );
            if ( is_array( $resolved ) ) {
                if ( ! empty( $resolved['style'] ) ) {
                    $record['style'] = $resolved['style'];
                }
                if ( ! empty( $resolved['division'] ) ) {
                    $record['division'] = $resolved['division'];
                }
            }
        }

        if ( function_exists( 'sywat_shooting_styles_get_division_distance' ) && ! empty( $record['division'] ) ) {
            $dist_val = sywat_shooting_styles_get_division_distance( $record['division'] );
        } else {
            $dist_val = sywat_waitlist_normalize_distance( $dist_val );
        }

        $record['checked_in'] = 0;
    }
}

/**
 * Collect matching waitlisted candidate records for a single archer, a line-scoped
 * family group, or an explicit list of checkbox-selected registration IDs in FIFO order.
 *
 * @param array  $registrations   Registrations array.
 * @param string $tourn_name      Active tournament name.
 * @param string $scope           'single', 'group', or 'selected'.
 * @param string $registration_id Target single registration ID.
 * @param string $family_id       Target family ID (for group scope).
 * @param string $source_line_idx Optional source line index filter.
 * @param array  $t_lines         Configured tournament lines array.
 * @param array  $selected_ids    Explicit list of registration IDs (for 'selected' scope).
 * @return array                  Ordered list of matching Waitlist registration records.
 */
if ( ! function_exists( 'sywat_waitlist_collect_candidates' ) ) {
    function sywat_waitlist_collect_candidates( $registrations, $tourn_name, $scope, $registration_id, $family_id, $source_line_idx, $t_lines, $selected_ids = array() ) {
        $candidates = array();
        if ( ! is_array( $registrations ) || empty( $tourn_name ) ) {
            return $candidates;
        }

        $target_reg_id   = trim( (string) $registration_id );
        $target_fam_id   = trim( (string) $family_id );
        $target_line_str = trim( (string) $source_line_idx );

        $clean_selected_ids = array();
        if ( is_array( $selected_ids ) ) {
            foreach ( $selected_ids as $sid ) {
                $s_trim = trim( (string) $sid );
                if ( $s_trim !== '' ) {
                    $clean_selected_ids[ $s_trim ] = true;
                }
            }
        }

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

            if ( $scope === 'selected' ) {
                if ( ! isset( $clean_selected_ids[ (string) $reg['id'] ] ) ) {
                    continue;
                }
            } elseif ( $scope === 'group' ) {
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
            $dist = sywat_waitlist_resolve_record_distance( $cand );
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

            $dist_val = sywat_waitlist_resolve_record_distance( $cand );
            sywat_waitlist_sanitize_promoted_record( $cand, $dist_val );

            $div_val       = isset( $cand['division'] ) ? $cand['division'] : '';
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

        // Clean up temporary flag on solo records that had no family_id
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
 * Action Handler: Move Checkbox-Selected Waitlisted Archers (or Single/Group)
 * from their current waitlisted line(s) to a selected open shooting line as 'Pre-Reg'.
 * Keeps moved members of the same household grouped together on the destination line
 * and reconciles per-line $75.00 Family Cap fees on both origin and destination lines.
 *
 * @param array $tournaments   Tournaments master list.
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_waitlist_handle_move_line' ) ) {
    function sywat_waitlist_handle_move_line( $tournaments, &$registrations ) {
        $tourn_name       = sywat_waitlist_get_posted_tournament();
        $move_scope       = isset( $_POST['move_scope'] ) ? sanitize_key( wp_unslash( $_POST['move_scope'] ) ) : 'selected';
        $registration_id  = isset( $_POST['registration_id'] ) ? sanitize_text_field( wp_unslash( $_POST['registration_id'] ) ) : '';
        $family_id        = isset( $_POST['family_id'] ) ? sanitize_text_field( wp_unslash( $_POST['family_id'] ) ) : '';
        $source_line_idx  = isset( $_POST['source_line_idx'] ) ? sanitize_text_field( wp_unslash( $_POST['source_line_idx'] ) ) : '';
        $target_line_raw  = isset( $_POST['target_line_index'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['target_line_index'] ) ) ) : '';
        $force_override   = isset( $_POST['force_override'] ) && wp_unslash( $_POST['force_override'] ) === '1';

        $selected_ids_raw = ( isset( $_POST['selected_reg_ids'] ) && is_array( $_POST['selected_reg_ids'] ) )
            ? wp_unslash( $_POST['selected_reg_ids'] )
            : array();
        $selected_ids = array();
        foreach ( $selected_ids_raw as $raw_id ) {
            $clean_id = sanitize_text_field( (string) $raw_id );
            if ( $clean_id !== '' ) {
                $selected_ids[] = $clean_id;
            }
        }

        if ( ! in_array( $move_scope, array( 'selected', 'group', 'single' ), true ) ) {
            $move_scope = ! empty( $selected_ids ) ? 'selected' : 'single';
        }

        if ( $tourn_name === '' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Tournament context missing. Unable to move waitlisted archer(s).</div>';
        }

        if ( $target_line_raw === '' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Please choose a destination open shooting line first.</div>';
        }

        if ( $move_scope === 'selected' && empty( $selected_ids ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Please check at least one waitlisted archer or family group to move.</div>';
        }

        $t_lines = sywat_waitlist_extract_lines( $tournaments, $tourn_name );
        if ( ! isset( $t_lines[ $target_line_raw ] ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Selected destination shooting line could not be found.</div>';
        }

        $candidates = sywat_waitlist_collect_candidates(
            $registrations,
            $tourn_name,
            $move_scope,
            $registration_id,
            $family_id,
            $source_line_idx,
            $t_lines,
            $selected_ids
        );

        if ( empty( $candidates ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">No eligible waitlisted archers were found for the selection.</div>';
        }

        $t_slug       = sanitize_title( $tourn_name );
        $closed_lines = get_option( 'sywat_closed_lines_' . $t_slug, array() );
        $line_caps    = sywat_waitlist_get_line_capacities( $tourn_name, $t_lines, $registrations, $closed_lines );
        $dest_cap     = isset( $line_caps[ $target_line_raw ] ) ? $line_caps[ $target_line_raw ] : array();
        $dest_label   = isset( $dest_cap['line_name'] )
            ? $dest_cap['line_name']
            : sywat_waitlist_format_line_label( $t_lines[ $target_line_raw ], $target_line_raw );

        // Block if any selected archer is already on the destination line (should use Promote instead)
        $already_on_dest = array();
        $duplicate_names = array();
        $needed_20       = 0;
        $needed_cub      = 0;

        foreach ( $candidates as $cand ) {
            if ( (string) $cand['_resolved_line'] === (string) $target_line_raw ) {
                $already_on_dest[] = $cand['name'];
                continue;
            }
            if ( sywat_waitlist_has_line_duplicate( $registrations, $tourn_name, $target_line_raw, $cand['name'], $cand['id'], $t_lines ) ) {
                $duplicate_names[] = $cand['name'];
            }
            $dist = sywat_waitlist_resolve_record_distance( $cand );
            if ( $dist === 'Cub' ) {
                $needed_cub++;
            } else {
                $needed_20++;
            }
        }

        if ( ! empty( $already_on_dest ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">Move Blocked: <strong>' . esc_html( implode( ', ', $already_on_dest ) ) . '</strong> is already on <strong>' . esc_html( $dest_label ) . '</strong>. Use <strong>Promote</strong> to move them onto the active roster.</div>';
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

        // Count how many candidates share each original family_id so moved family members stay clustered
        $moving_family_counts = array();
        foreach ( $candidates as $cand ) {
            $fid = ! empty( $cand['family_id'] ) ? trim( (string) $cand['family_id'] ) : '';
            if ( $fid !== '' ) {
                if ( ! isset( $moving_family_counts[ $fid ] ) ) {
                    $moving_family_counts[ $fid ] = 0;
                }
                $moving_family_counts[ $fid ]++;
            }
        }

        $moved_names          = array();
        $dest_family_ids      = array();
        $origin_family_scopes = array();

        foreach ( $candidates as $cand ) {
            $origin_line_idx = $cand['_resolved_line'];
            $old_fam_id      = ! empty( $cand['family_id'] ) ? trim( (string) $cand['family_id'] ) : '';
            $old_fam_name    = ! empty( $cand['family_name'] ) ? trim( (string) $cand['family_name'] ) : '';

            unset( $cand['_resolved_line'], $cand['_created_ts'], $cand['_seq'] );

            $dist_val = sywat_waitlist_resolve_record_distance( $cand );
            sywat_waitlist_sanitize_promoted_record( $cand, $dist_val );

            $div_val       = isset( $cand['division'] ) ? $cand['division'] : '';
            $existing_paid = isset( $cand['paid'] ) ? $cand['paid'] : '';

            $cand['line_index']     = (string) $target_line_raw;
            $cand['line_name']      = $dest_label;
            $cand['distance']       = $dist_val;
            $cand['status']         = 'Pre-Reg';
            $cand['paid']           = sywat_waitlist_get_paid_tier( $div_val, $dist_val, $existing_paid );
            $cand['balance_due']    = ( $cand['paid'] === 'Field Governor' ) ? 0.00 : sywat_waitlist_get_individual_rate( $div_val, $dist_val );
            $cand['_just_promoted'] = true;

            if ( $old_fam_id !== '' ) {
                $origin_family_scopes[ $origin_line_idx . '___' . $old_fam_id ] = array(
                    'line_idx'  => $origin_line_idx,
                    'family_id' => $old_fam_id,
                );
            }

            if ( $old_fam_id !== '' && isset( $moving_family_counts[ $old_fam_id ] ) && $moving_family_counts[ $old_fam_id ] > 1 ) {
                $base_label          = $old_fam_name !== '' ? $old_fam_name : $cand['name'];
                $new_fam_id          = 'fam_' . $t_slug . '_l' . sanitize_title( $target_line_raw ) . '_' . sanitize_title( $base_label ) . '_' . substr( md5( $old_fam_id . '_' . $target_line_raw ), 0, 4 );
                $cand['family_id']   = $new_fam_id;
                $cand['family_name'] = $base_label;
                $dest_family_ids[ $new_fam_id ] = true;
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
        foreach ( array_keys( $dest_family_ids ) as $df_id ) {
            $rec_res = sywat_waitlist_recalculate_line_family_fees( $tourn_name, $target_line_raw, $df_id, $registrations, $t_lines );
            if ( ! empty( $rec_res['is_family_capped'] ) ) {
                $cap_applied = true;
            }
        }

        // Reconcile any origin line family groups where active members remained behind
        foreach ( $origin_family_scopes as $scope_info ) {
            sywat_waitlist_recalculate_line_family_fees( $tourn_name, $scope_info['line_idx'], $scope_info['family_id'], $registrations, $t_lines );
        }

        $count_moved = count( $moved_names );
        $cap_note    = $cap_applied ? ' ($75.00 Family Cap applied on destination line).' : '.';

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Moved <strong>' . intval( $count_moved ) . '</strong> archer(s) (<strong>' . esc_html( implode( ', ', $moved_names ) ) . '</strong>) to <strong>' . esc_html( $dest_label ) . '</strong> as Pre-Reg' . esc_html( $cap_note ) . '</div>';
    }
}

/**
 * Action Handler: Remove a Single Waitlisted Archer Entry across all host portals
 * (Register, Manage Tournaments, Roster, Check-In) and reconcile line family fees.
 *
 * @param array $tournaments   Tournaments master list.
 * @param array $registrations Reference to registrations array.
 * @return string              HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_waitlist_handle_remove' ) ) {
    function sywat_waitlist_handle_remove( $tournaments, &$registrations ) {
        $tourn_name = sywat_waitlist_get_posted_tournament();
        $reg_id     = isset( $_POST['registration_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['registration_id'] ) ) ) : '';

        if ( $tourn_name === '' || $reg_id === '' ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Missing archer or tournament identifier. Unable to remove waitlist entry.</div>';
        }

        $t_slug     = sanitize_title( $tourn_name );
        $target_reg = get_option( 'sywat_t_' . $t_slug . '_' . $reg_id, null );

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

        delete_option( 'sywat_t_' . $t_slug . '_' . $reg_id );

        $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( is_array( $master_regs ) && isset( $master_regs[ $reg_id ] ) ) {
            unset( $master_regs[ $reg_id ] );
            update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        }

        $legacy_global = get_option( 'sywat_registrations', false );
        if ( is_array( $legacy_global ) ) {
            $filtered_global = array_values( array_filter( $legacy_global, function( $item ) use ( $reg_id ) {
                return ! ( is_array( $item ) && isset( $item['id'] ) && (string) $item['id'] === $reg_id );
            } ) );
            update_option( 'sywat_registrations', $filtered_global, false );
        }

        if ( is_array( $registrations ) ) {
            $registrations = array_values( array_filter( $registrations, function( $item ) use ( $reg_id ) {
                return ! ( is_array( $item ) && isset( $item['id'] ) && (string) $item['id'] === $reg_id );
            } ) );
        }

        if ( $family_id !== '' && $line_idx !== '' ) {
            $t_lines = sywat_waitlist_extract_lines( $tournaments, $tourn_name );
            sywat_waitlist_recalculate_line_family_fees( $tourn_name, $line_idx, $family_id, $registrations, $t_lines );
        }

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Removed <strong>' . esc_html( $archer_name ) . '</strong> from the waitlist for <strong>' . esc_html( $tourn_name ) . '</strong>.</div>';
    }
}
