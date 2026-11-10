<?php
/**
 * Sub-Module: Registration Engine Actions Handler
 * Path: includes/registration/registration-actions.php
 * Author: SYWAT Archery
 * Version: 6.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_detect_paid_category' ) ) {
    function sywat_detect_paid_category($text) {
        $str = is_array($text) ? implode(' ', $text) : (string)$text;
        if (preg_match('/max\.?\s*per\s*family|family/i', $str)) return 'Family';
        if (preg_match('/cub\/youth\/young\s*adult|cub|youth|young\s*adult/i', $str)) return 'Youth';
        if (preg_match('/adult\s*categories|adult/i', $str)) return 'Adult';
        return '';
    }
}

if ( ! function_exists( 'sywat_extract_due_amount' ) ) {
    function sywat_extract_due_amount($str) {
        $clean = preg_replace('/[^\d\.]/', '', (string)$str);
        return floatval($clean);
    }
}

/**
 * Handles multi-archer phone intake, line group fee calculation, family capping, waitlists, and persistence.
 *
 * @param array $tournaments   Master tournament configuration array.
 * @param array $registrations Master registration state array reference.
 * @return string Rendered HTML notice message.
 */
if ( ! function_exists( 'sywat_handle_register_shooter' ) ) {
    function sywat_handle_register_shooter( $tournaments, &$registrations ) {
        $group_name       = trim( sanitize_text_field( $_POST['group_name'] ?? '' ) );
        $group_phone      = trim( sanitize_text_field( $_POST['group_phone'] ?? '' ) );
        $default_line_idx = sanitize_text_field( $_POST['default_line_idx'] ?? '0' );
        $tourn_name       = sanitize_text_field( $_POST['selected_tournament'] ?? '' );
        $amount_paid      = isset($_POST['amount_paid_today']) ? floatval($_POST['amount_paid_today']) : 0;
        $allow_partial    = isset($_POST['allow_partial']) && $_POST['allow_partial'] === '1';
        $waitlist_mode    = sanitize_text_field($_POST['waitlist_mode'] ?? '');
        $t_slug           = sanitize_title( $tourn_name );

        $t_data  = isset( $tournaments[$tourn_name] ) ? $tournaments[$tourn_name] : array();
        $t_lines = function_exists('sywat_get_tournament_lines') ? sywat_get_tournament_lines($t_data) : (isset($t_data['lines']) ? $t_data['lines'] : array());

        $archers_raw = isset($_POST['archers']) && is_array($_POST['archers']) ? $_POST['archers'] : array();

        if ( empty( $archers_raw ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please add at least one archer name.</div>';
        }

        $youth_divisions = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)');
        $valid_archers   = array();

        foreach ($archers_raw as $a) {
            $name = trim(sanitize_text_field($a['name'] ?? ''));
            if (empty($name)) continue;

            $distance = function_exists('sywat_normalize_dist') ? sywat_normalize_dist(sanitize_text_field($a['distance'] ?? '20yd')) : sanitize_text_field($a['distance'] ?? '20yd');
            $division = sanitize_text_field($a['division'] ?? '');

            if (empty($division)) {
                $division = ($distance === 'Cub') ? 'Cub (8 - 11)' : 'Adult (18 - 49)';
            }

            $is_youth_tier = in_array($division, $youth_divisions) || ($distance === 'Cub');
            $paid_tier     = $is_youth_tier ? 'Youth' : 'Adult';
            $line_idx      = !empty($a['line_idx']) ? sanitize_text_field($a['line_idx']) : $default_line_idx;

            $valid_archers[] = array(
                'name'      => $name,
                'division'  => $division,
                'paid_tier' => $paid_tier,
                'distance'  => $distance,
                'line_idx'  => $line_idx,
            );
        }

        if (empty($valid_archers)) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please enter at least one valid archer name.</div>';
        }

        // Tally requested slots per line/distance combo
        $line_dist_tallies = array();
        // Tally per-line group stats for Family Cap evaluation (Requires 3+ archers AND raw total >= $75)
        $line_group_stats  = array();

        foreach ($valid_archers as $archer) {
            $l_idx = $archer['line_idx'];
            $dist  = $archer['distance'];
            $key   = $l_idx . '___' . $dist;

            if (!isset($line_dist_tallies[$key])) {
                $line_dist_tallies[$key] = array(
                    'line_idx' => $l_idx,
                    'distance' => $dist,
                    'count'    => 0,
                );
            }
            $line_dist_tallies[$key]['count']++;

            if (!isset($line_group_stats[$l_idx])) {
                $line_group_stats[$l_idx] = array(
                    'count'   => 0,
                    'raw_sum' => 0.00
                );
            }
            $line_group_stats[$l_idx]['count']++;
            $line_group_stats[$l_idx]['raw_sum'] += ($archer['paid_tier'] === 'Youth' ? 25.00 : 30.00);
        }

        $capacity_overflows = array();
        foreach ($line_dist_tallies as $key => $tally) {
            $l_idx    = $tally['line_idx'];
            $dist     = $tally['distance'];
            $req_cnt  = $tally['count'];

            $resolved_idx = $l_idx;
            if ( ! is_numeric( $resolved_idx ) && ! empty( $t_lines ) ) {
                foreach ( $t_lines as $idx => $line ) {
                    if ( isset( $line['name'] ) && strcasecmp( trim( $line['name'] ), trim( $resolved_idx ) ) === 0 ) {
                        $resolved_idx = $idx;
                        break;
                    }
                }
            }

            $line_data = isset( $t_lines[$resolved_idx] ) ? $t_lines[$resolved_idx] : array();
            $line_name = isset( $line_data['name'] ) ? $line_data['name'] : ( is_numeric( $l_idx ) ? 'Line ' . ((int)$l_idx + 1) : $l_idx );

            $current_count = function_exists('sywat_count_registered') ? sywat_count_registered( $tourn_name, $l_idx, $dist, $registrations ) : 0;
            $max_capacity  = function_exists('sywat_get_line_max_cap') ? sywat_get_line_max_cap( $line_data, $dist ) : 0;

            if ( ! $max_capacity || $max_capacity <= 0 ) {
                $max_capacity = ($dist === 'Cub') ? 5 : 20;
            }

            $open_slots = max(0, $max_capacity - $current_count);

            if ($req_cnt > $open_slots) {
                $capacity_overflows[] = array(
                    'line_idx'   => $l_idx,
                    'line_name'  => $line_name,
                    'distance'   => $dist,
                    'requested'  => $req_cnt,
                    'open_slots' => $open_slots,
                );
            }
        }

        if ( ! empty( $capacity_overflows ) && empty( $waitlist_mode ) && ! $allow_partial ) {
            $overflow_details = array();
            $max_open_available = 0;

            foreach ($capacity_overflows as $ov) {
                $overflow_details[] = 'Line <strong>' . esc_html($ov['line_name']) . '</strong> (' . esc_html($ov['distance']) . ') has <strong>' . intval($ov['open_slots']) . ' open spot(s)</strong>, but you requested <strong>' . intval($ov['requested']) . ' archer(s)</strong>.';
                if ($ov['open_slots'] > $max_open_available) {
                    $max_open_available = $ov['open_slots'];
                }
            }

            $hidden_archers_html = '';
            foreach ($archers_raw as $i => $a) {
                $hidden_archers_html .= '<input type="hidden" name="archers[' . $i . '][name]" value="' . esc_attr($a['name'] ?? '') . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . $i . '][distance]" value="' . esc_attr($a['distance'] ?? '') . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . $i . '][division]" value="' . esc_attr($a['division'] ?? '') . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . $i . '][line_idx]" value="' . esc_attr($a['line_idx'] ?? '') . '">';
            }

            $base_hidden_fields  = wp_nonce_field('sywat_desk_action', 'sywat_nonce', true, false);
            $base_hidden_fields .= '<input type="hidden" name="sywat_action" value="register_shooter">';
            $base_hidden_fields .= '<input type="hidden" name="selected_tournament" value="' . esc_attr($tourn_name) . '">';
            $base_hidden_fields .= '<input type="hidden" name="group_name" value="' . esc_attr($group_name) . '">';
            $base_hidden_fields .= '<input type="hidden" name="group_phone" value="' . esc_attr($group_phone) . '">';
            $base_hidden_fields .= '<input type="hidden" name="default_line_idx" value="' . esc_attr($default_line_idx) . '">';
            $base_hidden_fields .= '<input type="hidden" name="amount_paid_today" value="' . esc_attr($amount_paid) . '">';
            $base_hidden_fields .= $hidden_archers_html;

            $prompt_html  = '<div class="sywat-overflow-dialog">';
            $prompt_html .= '<div class="sywat-overflow-title">⚠️ GROUP CAPACITY OVERFLOW WARNING</div>';
            $prompt_html .= '<div class="sywat-overflow-body">' . implode('<br>', $overflow_details) . '</div>';
            $prompt_html .= '<p class="sywat-overflow-subtext">No archers were registered yet. Please select how you would like to handle this group:</p>';
            $prompt_html .= '<div class="sywat-overflow-actions">';
            
            $prompt_html .= '<form method="post" class="sywat-overflow-form">' . $base_hidden_fields;
            $prompt_html .= '<input type="hidden" name="waitlist_mode" value="all">';
            $prompt_html .= '<button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-block">🕒 Waitlist ENTIRE Group (Keep Group Together - $0 Due)</button>';
            $prompt_html .= '</form>';

            if ($max_open_available > 0) {
                $prompt_html .= '<form method="post" class="sywat-overflow-form">' . $base_hidden_fields;
                $prompt_html .= '<input type="hidden" name="waitlist_mode" value="remainder">';
                $prompt_html .= '<button type="submit" class="sywat-btn sywat-btn-warning-solid sywat-btn-block">⚠️ Book Available Spots (' . intval($max_open_available) . ') & Waitlist Remainder</button>';
                $prompt_html .= '</form>';
            }

            $prompt_html .= '<button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-block" onclick="const m=document.getElementById(\'sywat_capacity_overflow_modal\'); if(m) m.remove();">Cancel & Change Line</button>';
            $prompt_html .= '</div></div>';

            $GLOBALS['sywat_modal_notice'] = $prompt_html;
            return '';
        }

        $family_base_name = !empty($group_name) ? $group_name : $valid_archers[0]['name'];

        $remaining_open_slots = array();
        foreach ($line_dist_tallies as $key => $tally) {
            $l_idx     = $tally['line_idx'];
            $dist      = $tally['distance'];
            $line_data = isset( $t_lines[$l_idx] ) ? $t_lines[$l_idx] : array();
            $curr_cnt  = function_exists('sywat_count_registered') ? sywat_count_registered( $tourn_name, $l_idx, $dist, $registrations ) : 0;
            $max_cap   = function_exists('sywat_get_line_max_cap') ? sywat_get_line_max_cap( $line_data, $dist ) : 0;
            if (!$max_cap || $max_cap <= 0) $max_cap = ($dist === 'Cub') ? 5 : 20;

            $remaining_open_slots[$key] = max(0, $max_cap - $curr_cnt);
        }

        $added_count             = 0;
        $waitlisted_count        = 0;
        $skipped_duplicates      = array();
        $skipped_full            = array();
        $family_primary_assigned = array();
        $remaining_payment       = $amount_paid;

        $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( ! is_array( $master_regs ) ) {
            $master_regs = array();
        }

        foreach ($valid_archers as $archer) {
            $l_idx = $archer['line_idx'];
            $dist  = $archer['distance'];
            $name  = $archer['name'];
            $key   = $l_idx . '___' . $dist;

            $resolved_idx = $l_idx;
            if ( ! is_numeric( $resolved_idx ) && ! empty( $t_lines ) ) {
                foreach ( $t_lines as $idx => $line ) {
                    if ( isset( $line['name'] ) && strcasecmp( trim( $line['name'] ), trim( $resolved_idx ) ) === 0 ) {
                        $resolved_idx = $idx;
                        break;
                    }
                }
            }

            $line_data = isset( $t_lines[$resolved_idx] ) ? $t_lines[$resolved_idx] : array();
            $line_name = isset( $line_data['name'] ) ? $line_data['name'] : ( is_numeric( $l_idx ) ? 'Line ' . ((int)$l_idx + 1) : $l_idx );

            $is_duplicate = false;
            if ( function_exists( 'sywat_is_archer_on_line' ) ) {
                $is_duplicate = sywat_is_archer_on_line( $tourn_name, $l_idx, $name, $registrations );
            } else {
                foreach ( $registrations as $existing ) {
                    if ( isset( $existing['tournament'], $existing['line_index'], $existing['name'] ) &&
                         strcasecmp( trim( $existing['tournament'] ), trim( $tourn_name ) ) === 0 &&
                         (string)$existing['line_index'] === (string)$l_idx &&
                         strcasecmp( trim( $existing['name'] ), trim( $name ) ) === 0 ) {
                        $is_duplicate = true;
                        break;
                    }
                }
            }

            if ($is_duplicate) { 
                $skipped_duplicates[] = $name . ' (' . $line_name . ')'; 
                continue; 
            }

            $is_waitlist = false;
            if ($waitlist_mode === 'all') {
                $is_waitlist = true;
            } elseif (isset($remaining_open_slots[$key]) && $remaining_open_slots[$key] <= 0) {
                if ($waitlist_mode === 'remainder') {
                    $is_waitlist = true;
                } else {
                    $skipped_full[] = $name . ' (' . $line_name . ' - ' . $dist . ')';
                    continue;
                }
            }

            if (!$is_waitlist && isset($remaining_open_slots[$key])) {
                $remaining_open_slots[$key]--;
            }

            // Enforce Family Cap threshold: Only assign 'Family' rate if group on this line has >= 3 archers AND raw total >= $75.00
            $is_family_eligible = (isset($line_group_stats[$l_idx]) && $line_group_stats[$l_idx]['count'] >= 3 && $line_group_stats[$l_idx]['raw_sum'] >= 75.00);

            if ($is_waitlist) {
                $reg_status  = 'Waitlist';
                $paid_status = 'Waitlist';
                $initial_fee = 0.00;
                $due_amount  = 0.00;
            } else {
                $reg_status = 'Pre-Reg';
                if ($is_family_eligible) {
                    $paid_status = 'Family';
                    if (empty($family_primary_assigned[$l_idx])) {
                        $initial_fee = 75.00;
                        $family_primary_assigned[$l_idx] = true;
                    } else {
                        $initial_fee = 0.00;
                    }
                } else {
                    $paid_status = $archer['paid_tier'];
                    $initial_fee = ($archer['paid_tier'] === 'Youth' ? 25.00 : 30.00);
                }

                if ($remaining_payment >= $initial_fee) {
                    $due_amount = 0.00;
                    $remaining_payment -= $initial_fee;
                } else {
                    $due_amount = $initial_fee - $remaining_payment;
                    $remaining_payment = 0.00;
                }
            }

            $new_reg = array(
                'id'          => uniqid( 'reg_' ),
                'tournament'  => $tourn_name,
                'line_index'  => $l_idx,
                'line_name'   => $line_name,
                'name'        => $name,
                'phone'       => $group_phone,
                'distance'    => $dist,
                'division'    => $archer['division'],
                'gender'      => '',
                'style'       => '',
                'paid'        => $paid_status,
                'family_id'   => 'fam_' . $t_slug . '_l' . sanitize_title($l_idx) . '_' . sanitize_title($family_base_name),
                'family_name' => $family_base_name,
                'balance_due' => $due_amount,
                'status'      => $reg_status,
                'created_at'  => current_time( 'mysql' ),
            );

            update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
            $master_regs[$new_reg['id']] = $new_reg;
            $registrations[] = $new_reg;

            if ($is_waitlist) {
                $waitlisted_count++;
            } else {
                $added_count++;
            }
        }

        update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        update_option( 'sywat_registrations', $registrations, false );

        if ( ! empty( $group_phone ) && ($added_count + $waitlisted_count) > 0 ) {
            $phone_cache = get_option( 'sywat_shooter_phones_cache', array() );
            if ( ! is_array( $phone_cache ) ) {
                $phone_cache = array();
            }
            foreach ( $valid_archers as $archer ) {
                if ( ! empty( $archer['name'] ) ) {
                    $norm_name = strtolower( trim( $archer['name'] ) );
                    $phone_cache[$norm_name] = $group_phone;
                }
            }
            update_option( 'sywat_shooter_phones_cache', $phone_cache, false );
        }

        $GLOBALS['sywat_last_added_count']    = $added_count;
        $GLOBALS['sywat_skipped_duplicates']  = $skipped_duplicates;
        $GLOBALS['sywat_skipped_full']        = $skipped_full;

        $msg = '';
        if ($added_count > 0) {
            $msg .= '<div class="sywat-alert sywat-alert-success">Successfully registered ' . $added_count . ' archer(s) under \'' . esc_html($family_base_name) . '\'.</div>';
        }
        if ($waitlisted_count > 0) {
            $msg .= '<div class="sywat-alert sywat-alert-warning">🕒 Added ' . $waitlisted_count . ' archer(s) to the WAITLIST under \'' . esc_html($family_base_name) . '\' ($0 Balance Due).</div>';
        }
        if (!empty($skipped_duplicates)) {
            $msg .= '<div class="sywat-alert sywat-alert-danger">⚠️ DUPLICATE BLOCKED: ' . implode(', ', array_map('esc_html', $skipped_duplicates)) . ' — already registered on this shooting line!</div>';
        }
        if (!empty($skipped_full)) {
            $msg .= '<div class="sywat-alert sywat-alert-danger">⚠️ LINE FULL BLOCKED: ' . implode(', ', array_map('esc_html', $skipped_full)) . ' — target shooting line is at maximum capacity!</div>';
        }

        return $msg;
    }
}
