<?php
/**
 * Sub-Module: Registration Engine Actions Handler
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

// Action: Handle Multi-Archer Phone / Group Intake
if ( ! function_exists( 'sywat_handle_register_shooter' ) ) {
    function sywat_handle_register_shooter( $tournaments, &$registrations ) {
        $group_name      = trim( sanitize_text_field( $_POST['group_name'] ) );
        $group_phone     = trim( sanitize_text_field( $_POST['group_phone'] ) );
        $default_line_idx= sanitize_text_field( $_POST['default_line_idx'] );
        $tourn_name      = sanitize_text_field( $_POST['selected_tournament'] );
        $amount_paid     = isset($_POST['amount_paid_today']) ? floatval($_POST['amount_paid_today']) : 0;
        $t_slug          = sanitize_title( $tourn_name );

        $t_data  = isset( $tournaments[$tourn_name] ) ? $tournaments[$tourn_name] : array();
        $t_lines = function_exists('sywat_get_tournament_lines') ? sywat_get_tournament_lines($t_data) : (isset($t_data['lines']) ? $t_data['lines'] : $t_data);

        $archers_raw = isset($_POST['archers']) && is_array($_POST['archers']) ? $_POST['archers'] : array();

        if ( empty( $archers_raw ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please add at least one archer name.</div>';
        }

        $youth_divisions = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)');
        $valid_archers = array();

        foreach ($archers_raw as $a) {
            $name = trim(sanitize_text_field($a['name']));
            if (empty($name)) continue;

            $distance = sywat_normalize_dist(sanitize_text_field($a['distance']));
            $division = sanitize_text_field($a['division']);

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

        // Group archers by shooting line for per-line family capping
        $family_base_name = !empty($group_name) ? $group_name : $valid_archers[0]['name'] . ' Group';
        $line_groups = array();

        foreach ($valid_archers as $archer) {
            $l_idx = $archer['line_idx'];
            if (!isset($line_groups[$l_idx])) {
                $line_groups[$l_idx] = array(
                    'archers'     => array(),
                    'youth_count' => 0,
                    'adult_count' => 0,
                    'raw_total'   => 0,
                    'is_capped'   => false,
                    'line_fee'    => 0,
                    'paid_amount' => 0,
                    'balance_due' => 0,
                    'family_id'   => 'fam_' . sanitize_title($family_base_name) . '_l' . $l_idx . '_' . uniqid(),
                );
            }

            $line_groups[$l_idx]['archers'][] = $archer;
            if ($archer['paid_tier'] === 'Youth') {
                $line_groups[$l_idx]['youth_count']++;
            } else {
                $line_groups[$l_idx]['adult_count']++;
            }
        }

        // Calculate per-line fees and family caps
        $overall_fee = 0;
        foreach ($line_groups as $l_idx => &$grp) {
            $grp['raw_total'] = ($grp['youth_count'] * 25) + ($grp['adult_count'] * 30);
            $grp['is_capped'] = ($grp['raw_total'] >= 75 || count($grp['archers']) >= 3);
            $grp['line_fee']  = $grp['is_capped'] ? 75.00 : $grp['raw_total'];
            $overall_fee      += $grp['line_fee'];
        }
        unset($grp);

        // Allocate amount paid today sequentially across line groups
        $rem_paid = $amount_paid;
        $total_balance_due = 0;
        foreach ($line_groups as $l_idx => &$grp) {
            $grp_paid = min($rem_paid, $grp['line_fee']);
            $grp['paid_amount'] = $grp_paid;
            $rem_paid -= $grp_paid;

            $grp['balance_due'] = max(0, $grp['line_fee'] - $grp_paid);
            $total_balance_due += $grp['balance_due'];

            $archer_cnt = count($grp['archers']);
            $grp['per_archer_due'] = $archer_cnt > 0 ? round($grp['balance_due'] / $archer_cnt, 2) : 0;
        }
        unset($grp);

        $added_count = 0;
        $skipped_dup = 0;
        $skipped_cap = 0;

        foreach ($line_groups as $l_idx => $grp) {
            $line_data = isset( $t_lines[$l_idx] ) ? $t_lines[$l_idx] : array();
            $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ((int)$l_idx + 1);

            foreach ($grp['archers'] as $archer) {
                $distance = $archer['distance'];
                $name     = $archer['name'];

                // Duplicate check
                $is_duplicate = false;
                foreach ( $registrations as $existing ) {
                    if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] ) &&
                         strcasecmp ( $existing['tournament'], $tourn_name ) === 0 &&
                         (string)$existing['line_index'] === (string)$l_idx &&
                         strcasecmp ( trim ( $existing['name'] ), $name ) === 0 &&
                         strcasecmp ( $existing['distance'], $distance ) === 0 ) {
                        $is_duplicate = true;
                        break;
                    }
                }

                if ($is_duplicate) { $skipped_dup++; continue; }

                // Capacity check
                $current_count = sywat_count_registered( $tourn_name, $l_idx, $distance, $registrations );
                $max_capacity  = sywat_get_line_max_cap( $line_data, $distance );

                if ($current_count >= $max_capacity) { $skipped_cap++; continue; }

                $paid_status = $grp['is_capped'] ? 'Family' : $archer['paid_tier'];

                $new_reg = array(
                    'id'          => uniqid( 'reg_' ),
                    'tournament'  => $tourn_name,
                    'line_index'  => $l_idx,
                    'line_name'   => $line_name,
                    'name'        => $name,
                    'phone'       => $group_phone,
                    'distance'    => $distance,
                    'division'    => $archer['division'],
                    'gender'      => '', // Captured at Desk Check-In
                    'style'       => '', // Captured at Desk Check-In
                    'paid'        => $paid_status,
                    'family_id'   => $grp['family_id'],
                    'family_name' => $family_base_name,
                    'balance_due' => $grp['per_archer_due'],
                    'status'      => 'Pre-Reg',
                    'created_at'  => current_time( 'mysql' ),
                );

                update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                $registrations[] = $new_reg;
                $added_count++;
            }
        }

        $msg = "Successfully registered {$added_count} archer(s) under '{$family_base_name}'. Total Fee: $" . number_format($overall_fee, 2) . " (Balance Due: $" . number_format($total_balance_due, 2) . ").";
        if ($skipped_dup > 0) $msg .= " Skipped {$skipped_dup} duplicate(s).";
        if ($skipped_cap > 0) $msg .= " Skipped {$skipped_cap} over-capacity spot(s).";

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">' . esc_html($msg) . '</div>';
    }
}
