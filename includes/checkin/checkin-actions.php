<?php
/**
 * Sub-Module: Check-In Desk Actions Handler
 * Path: includes/checkin/checkin-actions.php
 * Description: Handles Check-In Desk POST actions including dedicated Waitlist Modal delegation (promote, multi-select move, remove) with live tourn_registrations sync, line closure/reopening (preserving waitlisted records), walk-in registration with database-driven style/division rule enforcement and active-only family cap evaluation, household-wide check-in balance verification, and archer edits/deletions with per-line Family Cap reconciliation.
 * Author: SYWAT Archery
 * Version: 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles desk POST actions including waitlist promotion/moves/removal, walk-in registration,
 * check-in updates, line closure, and edits.
 *
 * @param array $data Aggregated Check-In Desk dataset passed by reference.
 * @return string     Alert HTML notice markup.
 */
if ( ! function_exists( 'sywat_desk_handle_actions' ) ) {
    function sywat_desk_handle_actions( &$data ) {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            return '';
        }

        $is_valid_nonce = ( isset( $_POST['sywat_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ), 'sywat_desk_action' ) );
        $action         = isset( $_POST['sywat_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_action'] ) ) : '';

        if ( $action && ! $is_valid_nonce && $action !== 'cancel' ) {
            return '<div class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
        }

        if ( ! $is_valid_nonce ) {
            return '';
        }

        $active_t_slug       = $data['active_t_slug'];
        $selected_tournament = $data['selected_tournament'];
        $closed_lines_key    = $data['closed_lines_key'];
        $registrations       = &$data['registrations'];
        $lines               = $data['lines'];
        $tournaments_map     = isset( $data['tournaments'] ) ? $data['tournaments'] : array();
        $script_optout_attrs = defined( 'SYWAT_SCRIPT_OPTOUT_ATTRS' ) ? SYWAT_SCRIPT_OPTOUT_ATTRS : '';

        // Delegate Waitlist Modal actions (promote_waitlist, move_waitlist_line, modal delete_shooter) to central Waitlist engine
        if ( function_exists( 'sywat_waitlist_is_supported_action' ) && sywat_waitlist_is_supported_action( $action ) && function_exists( 'sywat_waitlist_dispatch_post_action' ) ) {
            $waitlist_notice = sywat_waitlist_dispatch_post_action( $tournaments_map, $registrations );

            if ( isset( $data['tourn_registrations'] ) && is_array( $data['tourn_registrations'] ) ) {
                $sel_line = isset( $data['selected_line_idx'] ) ? (string) $data['selected_line_idx'] : 'all';
                $data['tourn_registrations'] = array_values( array_filter( $registrations, function( $reg ) use ( $selected_tournament, $sel_line, $lines ) {
                    if ( ! isset( $reg['tournament'] ) || strcasecmp( trim( (string) $reg['tournament'] ), trim( (string) $selected_tournament ) ) !== 0 ) {
                        return false;
                    }
                    if ( $sel_line !== 'all' && $sel_line !== '' ) {
                        $r_lidx = function_exists( 'sywat_desk_resolve_line_idx' )
                            ? sywat_desk_resolve_line_idx( $reg, $lines )
                            : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );
                        if ( (string) $r_lidx !== $sel_line ) {
                            return false;
                        }
                    }
                    return true;
                } ) );
            }

            return $waitlist_notice;
        }

        $existing_families_by_line = isset( $data['existing_families_by_line'] ) ? $data['existing_families_by_line'] : array();
        $existing_families         = $data['existing_families'];

        $legacy_youth_divs = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' );

        $resolve_paid_tier = function( $division ) use ( $legacy_youth_divs ) {
            $clean_div = trim( (string) $division );
            if ( strcasecmp( $clean_div, 'No Show' ) === 0 ) {
                return 'No Show';
            }
            if ( function_exists( 'sywat_shooting_styles_get_division_rate_tier' ) ) {
                return sywat_shooting_styles_get_division_rate_tier( $clean_div );
            }
            return in_array( $clean_div, $legacy_youth_divs, true ) ? 'Youth' : 'Adult';
        };

        $calculate_individual_fee = function( $division ) use ( $resolve_paid_tier ) {
            $clean_div = trim( (string) $division );
            if ( $clean_div === '' || strcasecmp( $clean_div, 'No Show' ) === 0 ) {
                return 0.00;
            }
            $tier = $resolve_paid_tier( $clean_div );
            return ( $tier === 'Youth' ) ? 25.00 : 30.00;
        };

        $resolve_division_distance = function( $division ) {
            $clean_div = trim( (string) $division );
            if ( strcasecmp( $clean_div, 'No Show' ) === 0 ) {
                return 'Cub';
            }
            if ( function_exists( 'sywat_shooting_styles_get_division_distance' ) ) {
                return sywat_shooting_styles_get_division_distance( $clean_div );
            }
            return ( $clean_div === 'Cub (8 - 11)' ) ? 'Cub' : '20yd';
        };

        $resolve_style_and_div = function( $raw_style, $raw_division ) {
            $clean_div   = trim( (string) $raw_division );
            $clean_style = trim( (string) $raw_style );

            if ( $clean_div !== '' && strcasecmp( $clean_div, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
                $clean_div = sywat_shooting_styles_normalize_division( $clean_div );
            }
            if ( $clean_style !== '' && strcasecmp( $clean_style, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_style' ) ) {
                $clean_style = sywat_shooting_styles_normalize_style( $clean_style );
            }

            if ( strcasecmp( $clean_div, 'No Show' ) === 0 || strcasecmp( $clean_style, 'No Show' ) === 0 ) {
                return array(
                    'style'    => 'No Show',
                    'division' => $clean_div,
                );
            }

            if ( function_exists( 'sywat_resolve_style_and_division' ) ) {
                $resolved = sywat_resolve_style_and_division( $clean_style, $clean_div );
                if ( is_array( $resolved ) ) {
                    return array(
                        'style'    => ! empty( $resolved['style'] ) ? $resolved['style'] : $clean_style,
                        'division' => ! empty( $resolved['division'] ) ? $resolved['division'] : $clean_div,
                    );
                }
            }

            // Legacy fallback if shooting styles engine is unavailable
            if ( stripos( $clean_style, 'Genesis' ) !== false && $clean_div !== 'Cub (8 - 11)' ) {
                $clean_style = 'FS – Freestyle';
            }

            $is_pro_fsl_trad = (
                stripos( $clean_style, 'FS Pro' ) !== false ||
                stripos( $clean_style, 'FSL –' ) !== false ||
                stripos( $clean_style, 'TRAD' ) !== false ||
                stripos( $clean_style, 'Traditional' ) !== false
            );
            $allowed_adult = array( 'Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)' );

            if ( $is_pro_fsl_trad && ! in_array( $clean_div, $allowed_adult, true ) ) {
                $clean_style = 'FS – Freestyle';
            }

            $is_lb = ( stripos( $clean_style, 'LB' ) !== false || stripos( $clean_style, 'Longbow' ) !== false );
            if ( $is_lb && $clean_div !== 'Adult (18 - 49)' ) {
                $clean_style = 'FS – Freestyle';
            }

            return array(
                'style'    => $clean_style,
                'division' => $clean_div,
            );
        };

        // Close Line (Preserves Waitlist & Cancelled records; marks un-checked-in active archers as No Show)
        if ( $action === 'close_line' && $data['is_admin'] ) {
            $target_idx  = trim( sanitize_text_field( wp_unslash( $_POST['target_line_idx'] ) ) );
            $target_name = isset( $lines[ $target_idx ]['name'] ) ? trim( $lines[ $target_idx ]['name'] ) : '';

            $live_closed = get_option( $closed_lines_key, array() );
            if ( ! is_array( $live_closed ) ) {
                $live_closed = array();
            }

            if ( ! in_array( $target_idx, $live_closed, true ) ) {
                $live_closed[] = $target_idx;
            }
            if ( $target_name !== '' && ! in_array( $target_name, $live_closed, true ) ) {
                $live_closed[] = $target_name;
            }

            $live_closed = array_values( array_unique( $live_closed ) );

            update_option( $closed_lines_key, $live_closed, false );
            update_option( 'sywat_current_tournament', $selected_tournament, false );

            $data['closed_lines'] = $live_closed;

            $update_count = 0;
            foreach ( $registrations as &$r ) {
                $r_resolved_lidx = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $r, $lines ) : ( isset( $r['line_index'] ) ? (string) $r['line_index'] : '0' );
                if ( isset( $r['tournament'] ) && $r['tournament'] === $selected_tournament && (string) $r_resolved_lidx === $target_idx ) {
                    $r_status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
                    if ( strcasecmp( $r_status, 'Waitlist' ) === 0 || strcasecmp( $r_status, 'Cancelled' ) === 0 ) {
                        continue;
                    }
                    if ( empty( $r['checked_in'] ) ) {
                        $r['division'] = 'No Show';
                        $r['gender']   = 'No Show';
                        $r['style']    = 'No Show';
                        if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                            sywat_waitlist_persist_record( $r, $registrations );
                        } else {
                            update_option( 'sywat_t_' . $active_t_slug . '_' . $r['id'], $r, false );
                        }
                        $update_count++;
                    }
                }
            }
            unset( $r );
            return '<div class="sywat-alert sywat-alert-success"><strong>Line closed successfully.</strong> ' . $update_count . ' absent archer(s) marked as No Show.</div>';
        }

        // Reopen Line
        if ( $action === 'reopen_line' && $data['is_admin'] ) {
            $target_idx  = trim( sanitize_text_field( wp_unslash( $_POST['target_line_idx'] ) ) );
            $target_name = isset( $lines[ $target_idx ]['name'] ) ? trim( $lines[ $target_idx ]['name'] ) : '';

            $live_closed = get_option( $closed_lines_key, array() );
            if ( ! is_array( $live_closed ) ) {
                $live_closed = array();
            }

            $new_closed = array();
            foreach ( $live_closed as $val ) {
                $v_clean = trim( (string) $val );
                if ( $v_clean !== $target_idx && ( $target_name === '' || $v_clean !== $target_name ) ) {
                    $new_closed[] = $v_clean;
                }
            }

            update_option( $closed_lines_key, $new_closed, false );
            update_option( 'sywat_current_tournament', $selected_tournament, false );

            $data['closed_lines'] = $new_closed;

            return '<div class="sywat-alert sywat-alert-success"><strong>Line reopened successfully.</strong> Walk-ins and check-ins are active again.</div>';
        }

        // Add Walk-In (Fully Automated Fee & Family Cap Engine)
        if ( $action === 'add_walkin' || $action === 'add_walkin_next' ) {
            $name_val     = trim( sanitize_text_field( wp_unslash( $_POST['walkin_name'] ) ) );
            $group_name   = isset( $_POST['walkin_group_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['walkin_group_name'] ) ) ) : '';
            $group_phone  = isset( $_POST['walkin_group_phone'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['walkin_group_phone'] ) ) ) : '';
            $line_idx     = sanitize_text_field( wp_unslash( $_POST['walkin_line_index'] ) );
            $raw_division = sanitize_text_field( wp_unslash( $_POST['walkin_division'] ) );
            $gender       = sanitize_text_field( wp_unslash( $_POST['walkin_gender'] ) );
            $raw_style    = sanitize_text_field( wp_unslash( $_POST['walkin_style'] ) );
            $is_courtesy  = ! empty( $_POST['walkin_is_courtesy'] );

            if ( empty( $name_val ) ) {
                return '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Walk-In Failed!</strong> Please enter an archer name.</div>';
            }

            $is_duplicate = false;
            if ( function_exists( 'sywat_is_archer_on_line' ) ) {
                $is_duplicate = sywat_is_archer_on_line( $selected_tournament, $line_idx, $name_val, $registrations );
            } else {
                foreach ( $registrations as $r ) {
                    if ( isset( $r['tournament'], $r['line_index'], $r['name'] ) &&
                        strcasecmp( trim( $r['tournament'] ), trim( $selected_tournament ) ) === 0 &&
                        (string) $r['line_index'] === (string) $line_idx &&
                        strcasecmp( trim( $r['name'] ), trim( $name_val ) ) === 0 ) {
                        $is_duplicate = true;
                        break;
                    }
                }
            }

            if ( $is_duplicate ) {
                $alert_text = '⚠️ WALK-IN BLOCKED:\n\n' . esc_js( $name_val ) . ' is already registered on this shooting line.\n(Signing up for a different line is allowed).';
                return '<div class="sywat-alert sywat-alert-danger">⚠️ Walk-In Blocked! <strong>' . esc_html( $name_val ) . '</strong> is already registered on this shooting line. (Signing up for a different line is allowed).</div>' .
                       '<script ' . $script_optout_attrs . '>document.addEventListener("DOMContentLoaded", function() { alert("' . $alert_text . '"); });</script>';
            }

            // Enforce database-driven style & age division rules (including force-division overrides)
            $resolved_sd = $resolve_style_and_div( $raw_style, $raw_division );
            $division    = $resolved_sd['division'];
            $style       = $resolved_sd['style'];
            $distance    = $resolve_division_distance( $division );

            $new_id    = uniqid( 'reg_' );
            $line_name = isset( $lines[ $line_idx ]['name'] ) ? $lines[ $line_idx ]['name'] : 'N/A';

            // Family Group Resolution
            $sel_fam_id  = isset( $_POST['walkin_family_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['walkin_family_group_id'] ) ) : '';
            $family_id   = '';
            $family_name = '';

            if ( ! empty( $sel_fam_id ) ) {
                $family_id        = $sel_fam_id;
                $checkin_families = isset( $existing_families_by_line[ $line_idx ] ) ? $existing_families_by_line[ $line_idx ] : $existing_families;
                $family_name      = isset( $checkin_families[ $sel_fam_id ] ) ? $checkin_families[ $sel_fam_id ] : ( ! empty( $group_name ) ? $group_name . ' (Family Cap)' : 'Family Group' );
            } elseif ( ! empty( $group_name ) ) {
                $family_id   = 'fam_' . $active_t_slug . '_l' . sanitize_title( $line_idx ) . '_' . sanitize_title( $group_name ) . '_' . substr( md5( uniqid( '', true ) ), 0, 4 );
                $family_name = $group_name . ' (Family Cap)';
            }

            // Gather existing ACTIVE (non-waitlisted, non-cancelled) archers in this family group on this line
            $family_archers = array();
            if ( ! empty( $family_id ) ) {
                foreach ( $registrations as $r ) {
                    if ( isset( $r['tournament'], $r['line_index'] ) &&
                        strcasecmp( $r['tournament'], $selected_tournament ) === 0 &&
                        (string) $r['line_index'] === (string) $line_idx &&
                        ! empty( $r['family_id'] ) && $r['family_id'] === $family_id ) {
                        $r_status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
                        if ( strcasecmp( $r_status, 'Waitlist' ) === 0 || strcasecmp( $r_status, 'Cancelled' ) === 0 ) {
                            continue;
                        }
                        $family_archers[] = $r;
                    }
                }
            }

            // Calculate total active group archer count and standard cost including the new walk-in archer
            $total_group_count = count( $family_archers ) + 1;
            $total_group_cost  = $calculate_individual_fee( $division );

            foreach ( $family_archers as $fa ) {
                $total_group_cost += $calculate_individual_fee( isset( $fa['division'] ) ? $fa['division'] : '' );
            }

            // Auto-Cap Logic: If active group total >= $75 or archer count >= 3, apply Family status
            $is_family_capped = ( ! empty( $family_id ) && ( $total_group_cost >= 75.00 || $total_group_count >= 3 ) );

            // Determine paid status for new archer
            if ( $is_courtesy ) {
                $paid_status = 'Field Governor';
            } elseif ( $is_family_capped ) {
                $paid_status = 'Family';
            } else {
                $paid_status = $resolve_paid_tier( $division );
            }

            $new_reg = array(
                'id'          => $new_id,
                'tournament'  => $selected_tournament,
                'name'        => $name_val,
                'phone'       => $group_phone,
                'line_index'  => $line_idx,
                'line_name'   => $line_name,
                'distance'    => $distance,
                'type'        => 'Walk-In',
                'status'      => 'Pre-Reg',
                'division'    => $division,
                'gender'      => $gender,
                'style'       => $style,
                'paid'        => $paid_status,
                'family_id'   => $family_id,
                'family_name' => $family_name,
                'balance_due' => 0,
                'checked_in'  => 1,
                'created_at'  => current_time( 'mysql' ),
            );

            if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                sywat_waitlist_persist_record( $new_reg, $registrations );
            } else {
                update_option( 'sywat_t_' . $active_t_slug . '_' . $new_id, $new_reg, false );
                $registrations[] = $new_reg;

                $t_slug      = sanitize_title( $selected_tournament );
                $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
                if ( ! is_array( $master_regs ) ) {
                    $master_regs = array();
                }
                $master_regs[ $new_id ] = $new_reg;
                update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
            }

            // Retroactively update existing active family archers if cap threshold was reached on this registration
            if ( $is_family_capped && ! empty( $family_archers ) ) {
                foreach ( $registrations as &$r ) {
                    if ( isset( $r['tournament'], $r['line_index'] ) &&
                        strcasecmp( $r['tournament'], $selected_tournament ) === 0 &&
                        (string) $r['line_index'] === (string) $line_idx &&
                        ! empty( $r['family_id'] ) && $r['family_id'] === $family_id ) {
                        $r_status = isset( $r['status'] ) ? trim( (string) $r['status'] ) : '';
                        if ( strcasecmp( $r_status, 'Waitlist' ) === 0 || strcasecmp( $r_status, 'Cancelled' ) === 0 ) {
                            continue;
                        }
                        if ( $r['paid'] !== 'Field Governor' ) {
                            $r['paid'] = 'Family';
                            if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                                sywat_waitlist_persist_record( $r, $registrations );
                            } else {
                                update_option( 'sywat_t_' . $active_t_slug . '_' . $r['id'], $r, false );
                            }
                        }
                    }
                }
                unset( $r );
            }

            // Fast-Loop Context Persistence: Accumulate session archers and total balance
            $prev_session_archers = array();
            if ( isset( $_POST['walkin_session_archers_json'] ) && ! empty( $_POST['walkin_session_archers_json'] ) ) {
                $decoded = json_decode( stripslashes( $_POST['walkin_session_archers_json'] ), true );
                if ( is_array( $decoded ) ) {
                    $prev_session_archers = $decoded;
                }
            }

            $calc_fee               = $is_courtesy ? 0.00 : $calculate_individual_fee( $division );
            $prev_session_archers[] = array(
                'name'     => $name_val,
                'division' => $division,
                'fee'      => $calc_fee,
                'capped'   => $is_family_capped,
            );

            // Recalculate session total
            $raw_sum = 0;
            foreach ( $prev_session_archers as $sa ) {
                $raw_sum += floatval( $sa['fee'] );
            }
            $session_total = ( $is_family_capped || $raw_sum >= 75.00 ) ? 75.00 : $raw_sum;

            if ( $action === 'add_walkin_next' ) {
                $loop_context = array(
                    'primary_contact' => ! empty( $group_name ) ? $group_name : $name_val,
                    'phone'           => $group_phone,
                    'line_idx'        => $line_idx,
                    'family_id'       => $family_id,
                    'last_added_name' => $name_val,
                    'session_archers' => $prev_session_archers,
                    'session_total'   => $session_total,
                    'is_capped'       => $is_family_capped,
                );
                $data['walkin_loop_context']          = $loop_context;
                $GLOBALS['sywat_walkin_loop_context'] = $loop_context;

                $cap_msg = $is_family_capped ? ' 🟢 (Family Cap Applied - $75.00 Max)' : '';
                return '<div class="sywat-alert sywat-alert-success">✅ <strong>' . esc_html( $name_val ) . '</strong> registered and checked in successfully! Running Total: $' . number_format( $session_total, 2 ) . $cap_msg . '. Enter next archer below.</div>';
            }

            return '<div class="sywat-alert sywat-alert-success">✅ Walk-in archer <strong>' . esc_html( $name_val ) . '</strong> registered and checked in successfully. Total Due: $' . number_format( $session_total, 2 ) . '</div>';
        }

        // Check-In (Enforces household-wide unpaid family balance block)
        if ( $action === 'save_checkin' ) {
            $target_id = sanitize_text_field( wp_unslash( $_POST['reg_id'] ) );
            $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
            $reg       = get_option( $opt_key );

            if ( $reg ) {
                $due_amt  = isset( $reg['balance_due'] ) ? floatval( $reg['balance_due'] ) : 0;
                $verified = ! empty( $_POST['checkin_payment_verified'] );

                $household_info = function_exists( 'sywat_get_household_balance_info' )
                    ? sywat_get_household_balance_info( $reg, $registrations, $lines )
                    : null;

                $effective_due  = $household_info ? floatval( $household_info['effective_due'] ) : $due_amt;
                $is_family_paid = ( isset( $reg['paid'] ) && strcasecmp( $reg['paid'], 'Family' ) === 0 )
                    || ( ! empty( $reg['rate_type'] ) && stripos( (string) $reg['rate_type'], 'family' ) !== false );

                if ( ! $household_info && $is_family_paid ) {
                    $fam_id   = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
                    $book_id  = ! empty( $reg['booking_id'] ) ? trim( (string) $reg['booking_id'] ) : '';
                    $reg_line = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $reg, $lines ) : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

                    if ( $fam_id !== '' || $book_id !== '' ) {
                        foreach ( $registrations as $other_r ) {
                            $o_status = isset( $other_r['status'] ) ? trim( (string) $other_r['status'] ) : '';
                            if ( strcasecmp( $o_status, 'Waitlist' ) === 0 || strcasecmp( $o_status, 'Cancelled' ) === 0 ) {
                                continue;
                            }
                            $o_line = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $other_r, $lines ) : ( isset( $other_r['line_index'] ) ? (string) $other_r['line_index'] : '0' );
                            if ( $o_line !== $reg_line ) {
                                continue;
                            }
                            $o_fam  = ! empty( $other_r['family_id'] ) ? trim( (string) $other_r['family_id'] ) : '';
                            $o_book = ! empty( $other_r['booking_id'] ) ? trim( (string) $other_r['booking_id'] ) : '';
                            if ( ( $fam_id !== '' && $o_fam === $fam_id ) || ( $book_id !== '' && $o_book === $book_id ) ) {
                                $effective_due = max( $effective_due, isset( $other_r['balance_due'] ) ? floatval( $other_r['balance_due'] ) : 0 );
                            }
                        }
                    }
                }

                $is_family_balance = $is_family_paid || ( $household_info && ! empty( $household_info['has_family'] ) );

                if ( $effective_due > 0 && ! $verified ) {
                    if ( $is_family_balance ) {
                        return '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Check-In Blocked!</strong> Household has an unpaid Family Cap balance ($' . number_format( $effective_due, 2 ) . '). Please collect payment onsite and check the verification box.</div>';
                    }
                    return '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Check-In Blocked!</strong> Archer has an unpaid balance ($' . number_format( $effective_due, 2 ) . '). Please see event staff for payment and check the verification box.</div>';
                } else {
                    $raw_division = sanitize_text_field( wp_unslash( $_POST['checkin_division'] ) );
                    $raw_style    = sanitize_text_field( wp_unslash( $_POST['checkin_style'] ) );
                    $pay_opt      = sanitize_text_field( wp_unslash( $_POST['checkin_pay_option'] ) );

                    // Enforce database-driven style & age division rules
                    $resolved_sd = $resolve_style_and_div( $raw_style, $raw_division );
                    $division    = $resolved_sd['division'];
                    $style       = $resolved_sd['style'];
                    $distance    = $resolve_division_distance( $division );

                    if ( ! empty( $_POST['checkin_name'] ) ) {
                        $reg['name'] = sanitize_text_field( wp_unslash( $_POST['checkin_name'] ) );
                    }

                    if ( $verified && $effective_due > 0 ) {
                        $prior_paid         = isset( $reg['amount_paid'] ) ? floatval( $reg['amount_paid'] ) : 0.00;
                        $reg['amount_paid'] = round( $prior_paid + $effective_due, 2 );
                    }

                    $reg['distance']    = $distance;
                    $reg['division']    = $division;
                    $reg['gender']      = sanitize_text_field( wp_unslash( $_POST['checkin_gender'] ) );
                    $reg['style']       = $style;
                    $reg['paid']        = ( $pay_opt === 'Family' ) ? 'Family' : ( ( $pay_opt === 'Field Governor' ) ? 'Field Governor' : $resolve_paid_tier( $division ) );
                    $reg['checked_in']  = 1;
                    $reg['balance_due'] = 0;

                    if ( $reg['paid'] === 'Family' ) {
                        $sel_fam_id       = isset( $_POST['checkin_family_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['checkin_family_group_id'] ) ) : '';
                        $line_idx         = isset( $reg['line_index'] ) ? $reg['line_index'] : '0';
                        $checkin_families = isset( $existing_families_by_line[ $line_idx ] ) ? $existing_families_by_line[ $line_idx ] : $existing_families;

                        if ( ! empty( $sel_fam_id ) ) {
                            $reg['family_id']   = $sel_fam_id;
                            $reg['family_name'] = isset( $checkin_families[ $sel_fam_id ] ) ? $checkin_families[ $sel_fam_id ] : 'Family Group';
                        } elseif ( empty( $reg['family_id'] ) ) {
                            $reg['family_id']   = 'fam_' . $active_t_slug . '_l' . sanitize_title( $line_idx ) . '_' . sanitize_title( $reg['name'] ) . '_' . substr( md5( uniqid( '', true ) ), 0, 4 );
                            $reg['family_name'] = $reg['name'] . ' (Family Cap)';
                        }
                    } else {
                        unset( $reg['family_id'], $reg['family_name'] );
                    }

                    if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                        sywat_waitlist_persist_record( $reg, $registrations );
                    } else {
                        update_option( $opt_key, $reg, false );
                    }

                    $fam_id      = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
                    $book_id     = ! empty( $reg['booking_id'] ) ? trim( (string) $reg['booking_id'] ) : '';
                    $target_line = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $reg, $lines ) : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

                    foreach ( $registrations as &$r ) {
                        if ( isset( $r['id'] ) && $r['id'] === $target_id ) {
                            $r = $reg;
                        }
                        if ( ! empty( $fam_id ) || ! empty( $book_id ) ) {
                            $r_line = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $r, $lines ) : ( isset( $r['line_index'] ) ? (string) $r['line_index'] : '0' );
                            if ( isset( $r['tournament'] )
                                && strcasecmp( $r['tournament'], $selected_tournament ) === 0
                                && $r_line === $target_line ) {

                                $is_fam_match = ( ! empty( $fam_id ) && isset( $r['family_id'] ) && trim( (string) $r['family_id'] ) === $fam_id ) ||
                                                ( ! empty( $book_id ) && isset( $r['booking_id'] ) && trim( (string) $r['booking_id'] ) === $book_id );
                                if ( $is_fam_match ) {
                                    $r['balance_due'] = 0;
                                    if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                                        sywat_waitlist_persist_record( $r, $registrations );
                                    } else {
                                        update_option( 'sywat_t_' . $active_t_slug . '_' . $r['id'], $r, false );
                                    }
                                }
                            }
                        }
                    }
                    unset( $r );
                }
            }
        }

        // Undo Check-In
        if ( $action === 'undo_checkin' ) {
            $target_id = sanitize_text_field( wp_unslash( $_POST['reg_id'] ) );
            $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
            $reg       = get_option( $opt_key );
            if ( $reg ) {
                $reg['checked_in'] = 0;
                if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                    sywat_waitlist_persist_record( $reg, $registrations );
                } else {
                    update_option( $opt_key, $reg, false );
                    foreach ( $registrations as &$r ) {
                        if ( isset( $r['id'] ) && $r['id'] === $target_id ) {
                            $r['checked_in'] = 0;
                        }
                    }
                    unset( $r );
                }
            }
        }

        // Save Edit (With database-driven rule enforcement & per-line Family Cap reconciliation)
        if ( $action === 'save_edit' ) {
            $target_id = sanitize_text_field( wp_unslash( $_POST['reg_id'] ) );
            $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
            $reg       = get_option( $opt_key );

            if ( $reg ) {
                $edit_name   = trim( sanitize_text_field( wp_unslash( $_POST['edit_name'] ) ) );
                $line_idx    = sanitize_text_field( wp_unslash( $_POST['edit_line_index'] ) );
                $orig_name   = isset( $reg['name'] ) ? trim( $reg['name'] ) : '';
                $orig_line   = isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '';
                $orig_fam_id = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';

                if ( strcasecmp( $orig_name, $edit_name ) !== 0 || (string) $orig_line !== (string) $line_idx ) {
                    $dupe_found = false;
                    foreach ( $registrations as $other ) {
                        if ( isset( $other['id'] ) && $other['id'] === $target_id ) {
                            continue;
                        }
                        if ( isset( $other['tournament'], $other['line_index'], $other['name'] ) &&
                            strcasecmp( trim( $other['tournament'] ), trim( $selected_tournament ) ) === 0 &&
                            (string) $other['line_index'] === (string) $line_idx &&
                            strcasecmp( trim( $other['name'] ), trim( $edit_name ) ) === 0 ) {
                            $dupe_found = true;
                            break;
                        }
                    }

                    if ( $dupe_found ) {
                        $alert_text = '⚠️ EDIT BLOCKED:\n\n' . esc_js( $edit_name ) . ' is already registered on this shooting line.';
                        return '<div class="sywat-alert sywat-alert-danger">⚠️ Edit Blocked! <strong>' . esc_html( $edit_name ) . '</strong> is already registered on this shooting line.</div>' .
                               '<script ' . $script_optout_attrs . '>document.addEventListener("DOMContentLoaded", function() { alert("' . $alert_text . '"); });</script>';
                    }
                }

                $line_name    = isset( $lines[ $line_idx ]['name'] ) ? $lines[ $line_idx ]['name'] : 'N/A';
                $raw_division = sanitize_text_field( wp_unslash( $_POST['edit_division'] ) );
                $raw_style    = sanitize_text_field( wp_unslash( $_POST['edit_style'] ) );
                $pay_opt      = sanitize_text_field( wp_unslash( $_POST['edit_pay_option'] ) );

                // Enforce database-driven style & age division rules
                $resolved_sd = $resolve_style_and_div( $raw_style, $raw_division );
                $division    = $resolved_sd['division'];
                $style       = $resolved_sd['style'];
                $distance    = $resolve_division_distance( $division );

                $reg['name']        = $edit_name;
                $reg['line_index']  = $line_idx;
                $reg['line_name']   = $line_name;
                $reg['distance']    = $distance;
                $reg['division']    = $division;
                $reg['gender']      = sanitize_text_field( wp_unslash( $_POST['edit_gender'] ) );
                $reg['style']       = $style;
                $reg['paid']        = ( $pay_opt === 'Family' ) ? 'Family' : ( ( $pay_opt === 'Field Governor' ) ? 'Field Governor' : $resolve_paid_tier( $division ) );
                $reg['balance_due'] = isset( $_POST['edit_balance_due'] ) ? floatval( wp_unslash( $_POST['edit_balance_due'] ) ) : 0;

                if ( $reg['paid'] === 'Family' ) {
                    $sel_fam_id    = isset( $_POST['edit_family_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edit_family_group_id'] ) ) : '';
                    $edit_families = isset( $existing_families_by_line[ $line_idx ] ) ? $existing_families_by_line[ $line_idx ] : $existing_families;

                    if ( ! empty( $sel_fam_id ) ) {
                        $reg['family_id']   = $sel_fam_id;
                        $reg['family_name'] = isset( $edit_families[ $sel_fam_id ] ) ? $edit_families[ $sel_fam_id ] : 'Family Group';
                    } elseif ( empty( $reg['family_id'] ) || $orig_line !== $line_idx ) {
                        $reg['family_id']   = 'fam_' . $active_t_slug . '_l' . sanitize_title( $line_idx ) . '_' . sanitize_title( $edit_name ) . '_' . substr( md5( uniqid( '', true ) ), 0, 4 );
                        $reg['family_name'] = $edit_name . ' (Family Cap)';
                    }
                } else {
                    unset( $reg['family_id'], $reg['family_name'] );
                }

                if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                    sywat_waitlist_persist_record( $reg, $registrations );
                } else {
                    update_option( $opt_key, $reg, false );
                    foreach ( $registrations as &$r ) {
                        if ( isset( $r['id'] ) && $r['id'] === $target_id ) {
                            $r = $reg;
                        }
                    }
                    unset( $r );
                }

                // Reconcile per-line $75.00 Family Cap fees if family membership or line changed
                $new_fam_id = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '';
                if ( function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
                    if ( $orig_fam_id !== '' ) {
                        sywat_waitlist_recalculate_line_family_fees( $selected_tournament, $orig_line, $orig_fam_id, $registrations, $lines );
                    }
                    if ( $new_fam_id !== '' && ( $new_fam_id !== $orig_fam_id || $line_idx !== $orig_line ) ) {
                        sywat_waitlist_recalculate_line_family_fees( $selected_tournament, $line_idx, $new_fam_id, $registrations, $lines );
                    }
                }
            }
        }

        // Delete Archer (With per-line Family Cap reconciliation)
        if ( $action === 'delete_archer' ) {
            $target_id  = sanitize_text_field( wp_unslash( $_POST['reg_id'] ) );
            $target_reg = get_option( 'sywat_t_' . $active_t_slug . '_' . $target_id, null );

            if ( ! is_array( $target_reg ) && is_array( $registrations ) ) {
                foreach ( $registrations as $candidate ) {
                    if ( is_array( $candidate ) && isset( $candidate['id'] ) && (string) $candidate['id'] === $target_id ) {
                        $target_reg = $candidate;
                        break;
                    }
                }
            }

            $del_line_idx = is_array( $target_reg ) && isset( $target_reg['line_index'] ) ? (string) $target_reg['line_index'] : '';
            $del_fam_id   = is_array( $target_reg ) && ! empty( $target_reg['family_id'] ) ? trim( (string) $target_reg['family_id'] ) : '';

            delete_option( 'sywat_t_' . $active_t_slug . '_' . $target_id );

            $master_regs = get_option( 'sywat_registrations_' . $active_t_slug, array() );
            if ( is_array( $master_regs ) && isset( $master_regs[ $target_id ] ) ) {
                unset( $master_regs[ $target_id ] );
                update_option( 'sywat_registrations_' . $active_t_slug, $master_regs, false );
            }

            $registrations = array_values( array_filter( $registrations, function( $r ) use ( $target_id ) {
                return ! ( isset( $r['id'] ) && $r['id'] === $target_id );
            } ) );

            if ( $del_fam_id !== '' && $del_line_idx !== '' && function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
                sywat_waitlist_recalculate_line_family_fees( $selected_tournament, $del_line_idx, $del_fam_id, $registrations, $lines );
            }
        }

        return '';
    }
}
