<?php
/**
 * Sub-Module: Check-In Desk Actions Handler
 * Path: includes/checkin/checkin-actions.php
 * Author: SYWAT Archery
 * Version: 4.0.0 (Nuclear Cache Bypass)
 */

if (!defined('ABSPATH')) exit;

function sywat_desk_handle_actions(&$data) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';

    $is_valid_nonce = (isset($_POST['sywat_nonce']) && wp_verify_nonce($_POST['sywat_nonce'], 'sywat_desk_action'));
    $action = isset($_POST['sywat_action']) ? $_POST['sywat_action'] : '';

    if ($action && !$is_valid_nonce && $action !== 'cancel') {
        return '<div class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
    }

    if (!$is_valid_nonce) return '';

    global $wpdb;
    $active_t_slug       = $data['active_t_slug'];
    $selected_tournament = $data['selected_tournament'];
    $closed_lines_key    = $data['closed_lines_key'];
    $registrations       = &$data['registrations'];
    $lines               = $data['lines'];
    
    // Use line-scoped family groups if available, otherwise fallback to generic existing families
    $existing_families_by_line = isset($data['existing_families_by_line']) ? $data['existing_families_by_line'] : array();
    $existing_families         = $data['existing_families'];

    // ---------------------------------------------------------
    // NUCLEAR DATABASE HELPERS: BYPASS ALL WP OBJECT CACHING
    // ---------------------------------------------------------
    $force_db_read = function($key) use ($wpdb) {
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
        $arr = $raw ? maybe_unserialize($raw) : array();
        return is_array($arr) ? array_map('strval', $arr) : array();
    };

    $force_db_update = function($key, $array) use ($wpdb) {
        $serialized = maybe_serialize($array);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $key));
        if ($exists) {
            $wpdb->update($wpdb->options, array('option_value' => $serialized), array('option_name' => $key));
        } else {
            $wpdb->insert($wpdb->options, array('option_name' => $key, 'option_value' => $serialized, 'autoload' => 'no'));
        }
        wp_cache_delete($key, 'options');
    };

    $calculate_paid_status = function($pay_option, $division) {
        if ($pay_option === 'Family') return 'Family';
        if ($pay_option === 'Field Governor') return 'Field Governor';
        $youth_divs = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)');
        return in_array($division, $youth_divs) ? 'Youth' : 'Adult';
    };

    $sanitize_style_for_division = function($style, $division) {
        if ($division === 'No Show' || $style === 'No Show') return 'No Show';
        if (strpos($style, 'Genesis') !== false && $division !== 'Cub (8 - 11)') return 'FS – Freestyle';

        $is_pro_fsl_trad = (strpos($style, 'FS Pro') !== false || strpos($style, 'FSL –') !== false || strpos($style, 'TRAD') !== false || stripos($style, 'Traditional') !== false);
        $allowed = array('Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)');

        if ($is_pro_fsl_trad && !in_array($division, $allowed)) return 'FS – Freestyle';
        $is_lb = (strpos($style, 'LB') !== false || stripos($style, 'Longbow') !== false);
        if ($is_lb && $division !== 'Adult (18 - 49)') return 'FS – Freestyle';

        return $style;
    };

    // Close Line (Admin, Tournament Director & Shop Managers)
    if ($action === 'close_line' && $data['is_admin']) {
        $target_line = trim((string) sanitize_text_field($_POST['target_line_idx']));
        $live_closed = $force_db_read($closed_lines_key);

        if (!in_array($target_line, $live_closed, true)) {
            $live_closed[] = $target_line;
            $live_closed   = array_values(array_unique($live_closed));
            
            $force_db_update($closed_lines_key, $live_closed);
            $data['closed_lines'] = $live_closed; // Update in-memory array for current view render

            $update_count = 0;
            foreach ($registrations as &$r) {
                // Safely resolve the line index to handle legacy string names
                $r_resolved_lidx = function_exists('sywat_desk_resolve_line_idx') ? sywat_desk_resolve_line_idx($r, $lines) : (isset($r['line_index']) ? (string)$r['line_index'] : '0');
                if (isset($r['tournament']) && $r['tournament'] === $selected_tournament && (string)$r_resolved_lidx === $target_line) {
                    if (empty($r['checked_in'])) {
                        $r['division'] = 'No Show'; $r['gender'] = 'No Show'; $r['style'] = 'No Show';
                        update_option('sywat_t_' . $active_t_slug . '_' . $r['id'], $r, false);
                        $update_count++;
                    }
                }
            }
            unset($r);
            return '<div class="sywat-alert sywat-alert-success"><strong>Line closed successfully.</strong> ' . $update_count . ' absent archer(s) marked as No Show.</div>';
        }
    }

    // Reopen Line (Admin, Tournament Director & Shop Managers)
    if ($action === 'reopen_line' && $data['is_admin']) {
        $target_line = trim((string) sanitize_text_field($_POST['target_line_idx']));
        $live_closed = $force_db_read($closed_lines_key);
        
        $new_closed = array();
        foreach ($live_closed as $val) {
            if (trim((string)$val) !== $target_line) {
                $new_closed[] = (string)$val;
            }
        }

        $force_db_update($closed_lines_key, $new_closed);
        $data['closed_lines'] = $new_closed; // Update in-memory array for current view render
        
        return '<div class="sywat-alert sywat-alert-success"><strong>Line reopened successfully.</strong> Walk-ins and check-ins are active again.</div>';
    }

    // Add Walk-In
    if ($action === 'add_walkin') {
        $name_val  = trim(sanitize_text_field($_POST['walkin_name']));
        $line_idx  = sanitize_text_field($_POST['walkin_line_index']);

        if (empty($name_val)) {
            return '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Walk-In Failed!</strong> Please enter an archer name.</div>';
        }

        // Duplicate check: prevent registering the same archer on the same line twice
        $is_duplicate = false;
        if (function_exists('sywat_is_archer_on_line')) {
            $is_duplicate = sywat_is_archer_on_line($selected_tournament, $line_idx, $name_val, $registrations);
        } else {
            foreach ($registrations as $r) {
                if (isset($r['tournament'], $r['line_index'], $r['name']) &&
                    strcasecmp(trim($r['tournament']), trim($selected_tournament)) === 0 &&
                    (string)$r['line_index'] === (string)$line_idx &&
                    strcasecmp(trim($r['name']), trim($name_val)) === 0) {
                    $is_duplicate = true;
                    break;
                }
            }
        }

        if ($is_duplicate) {
            $alert_text = '⚠️ WALK-IN BLOCKED:\n\n' . esc_js($name_val) . ' is already registered on this shooting line.\n(Signing up for a different line is allowed).';
            return '<div class="sywat-alert sywat-alert-danger" style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:10px 14px; border-radius:4px; font-weight:bold; margin-bottom:12px;">⚠️ Walk-In Blocked! <strong>' . esc_html($name_val) . '</strong> is already registered on this shooting line. (Signing up for a different line is allowed).</div>' .
                   '<script>document.addEventListener("DOMContentLoaded", function() { alert("' . $alert_text . '"); });</script>';
        }

        $new_id    = uniqid('reg_');
        $line_name = isset($lines[$line_idx]['name']) ? $lines[$line_idx]['name'] : 'N/A';
        $division  = sanitize_text_field($_POST['walkin_division']);
        $distance  = ($division === 'Cub (8 - 11)') ? 'Cub' : '20yd';
        $pay_opt   = sanitize_text_field($_POST['walkin_pay_option']);
        $style     = sanitize_text_field($_POST['walkin_style']);

        $family_id = ''; $family_name = '';

        if ($pay_opt === 'Family') {
            $sel_fam_id = isset($_POST['walkin_family_group_id']) ? sanitize_text_field($_POST['walkin_family_group_id']) : '';
            $checkin_families = isset($existing_families_by_line[$line_idx]) ? $existing_families_by_line[$line_idx] : $existing_families;

            if (!empty($sel_fam_id)) {
                $family_id   = $sel_fam_id;
                $family_name = isset($checkin_families[$sel_fam_id]) ? $checkin_families[$sel_fam_id] : 'Family Group';
            } else {
                // Generate Anchor Family ID
                $family_id   = 'fam_' . $active_t_slug . '_l' . sanitize_title($line_idx) . '_' . sanitize_title($name_val) . '_' . substr(md5(uniqid()), 0, 4);
                $family_name = $name_val . ' (Family Cap)';
            }

            $GLOBALS['sywat_added_family_prompt'] = array(
                'archer_name' => $name_val,
                'family_id'   => $family_id,
                'family_name' => $family_name,
                'line_idx'    => $line_idx
            );
        }

        $new_reg = array(
            'id'          => $new_id,
            'tournament'  => $selected_tournament,
            'name'        => $name_val,
            'line_index'  => $line_idx,
            'line_name'   => $line_name,
            'distance'    => $distance,
            'type'        => 'Walk-In',
            'division'    => $division,
            'gender'      => sanitize_text_field($_POST['walkin_gender']),
            'style'       => $sanitize_style_for_division($style, $division),
            'paid'        => $calculate_paid_status($pay_opt, $division),
            'family_id'   => $family_id,
            'family_name' => $family_name,
            'balance_due' => 0,
            'checked_in'  => 1
        );

        update_option('sywat_t_' . $active_t_slug . '_' . $new_id, $new_reg, false);
        $registrations[] = $new_reg;

        // Synchronize into master tournament roster
        $t_slug      = sanitize_title($selected_tournament);
        $master_regs = get_option('sywat_registrations_' . $t_slug, array());
        if (!is_array($master_regs)) $master_regs = array();
        $master_regs[$new_id] = $new_reg;
        update_option('sywat_registrations_' . $t_slug, $master_regs, false);
    }

    // Check-In
    if ($action === 'save_checkin') {
        $target_id = sanitize_text_field($_POST['reg_id']);
        $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
        $reg       = get_option($opt_key);
        
        if ($reg) {
            $due_amt  = isset($reg['balance_due']) ? floatval($reg['balance_due']) : 0;
            $verified = !empty($_POST['checkin_payment_verified']);

            if ($due_amt > 0 && !$verified) {
                return '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Check-In Blocked!</strong> Archer has an unpaid balance. Please see event staff for payment and check the verification box.</div>';
            } else {
                $division = sanitize_text_field($_POST['checkin_division']);
                $distance = ($division === 'Cub (8 - 11)' || $division === 'No Show') ? 'Cub' : '20yd';
                $pay_opt  = sanitize_text_field($_POST['checkin_pay_option']);
                $style    = sanitize_text_field($_POST['checkin_style']);
                
                if (!empty($_POST['checkin_name'])) $reg['name'] = sanitize_text_field($_POST['checkin_name']);

                $reg['distance']    = $distance;
                $reg['division']    = $division;
                $reg['gender']      = sanitize_text_field($_POST['checkin_gender']);
                $reg['style']       = $sanitize_style_for_division($style, $division);
                $reg['paid']        = $calculate_paid_status($pay_opt, $division);
                $reg['checked_in']  = 1;
                $reg['balance_due'] = 0;
                
                // Process Family Option changes on check-in
                if ($reg['paid'] === 'Family') {
                    $sel_fam_id = isset($_POST['checkin_family_group_id']) ? sanitize_text_field($_POST['checkin_family_group_id']) : '';
                    $line_idx   = isset($reg['line_index']) ? $reg['line_index'] : '0';
                    $checkin_families = isset($existing_families_by_line[$line_idx]) ? $existing_families_by_line[$line_idx] : $existing_families;

                    if (!empty($sel_fam_id)) {
                        $reg['family_id']   = $sel_fam_id;
                        $reg['family_name'] = isset($checkin_families[$sel_fam_id]) ? $checkin_families[$sel_fam_id] : 'Family Group';
                    } elseif (empty($reg['family_id'])) {
                        // Generate Anchor Family ID if changing to family at check-in
                        $reg['family_id']   = 'fam_' . $active_t_slug . '_l' . sanitize_title($line_idx) . '_' . sanitize_title($reg['name']) . '_' . substr(md5(uniqid()), 0, 4);
                        $reg['family_name'] = $reg['name'] . ' (Family Cap)';
                    }
                } else {
                    unset($reg['family_id']); unset($reg['family_name']);
                }
                
                update_option($opt_key, $reg, false);

                // Multi-Archer Family Balance Sync: Clear balance_due ONLY for archers on the SAME shooting line
                $fam_id      = !empty($reg['family_id']) ? $reg['family_id'] : '';
                $book_id     = !empty($reg['booking_id']) ? $reg['booking_id'] : '';
                $target_line = isset($reg['line_index']) ? (string)$reg['line_index'] : '';

                foreach ($registrations as &$r) {
                    if (isset($r['id']) && $r['id'] === $target_id) {
                        $r = $reg;
                    }
                    if (!empty($fam_id) || !empty($book_id)) {
                        if (isset($r['tournament'], $r['line_index']) 
                            && strcasecmp($r['tournament'], $selected_tournament) === 0
                            && (string)$r['line_index'] === $target_line) {
                            
                            $is_fam_match = (!empty($fam_id) && isset($r['family_id']) && $r['family_id'] === $fam_id) ||
                                            (!empty($book_id) && isset($r['booking_id']) && $r['booking_id'] === $book_id);
                            if ($is_fam_match) {
                                $r['balance_due'] = 0;
                                update_option('sywat_t_' . $active_t_slug . '_' . $r['id'], $r, false);
                            }
                        }
                    }
                }
                unset($r);
            }
        }
    }

    // Undo Check-In
    if ($action === 'undo_checkin') {
        $target_id = sanitize_text_field($_POST['reg_id']);
        $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
        $reg       = get_option($opt_key);
        if ($reg) {
            $reg['checked_in'] = 0;
            update_option($opt_key, $reg, false);

            foreach ($registrations as &$r) {
                if (isset($r['id']) && $r['id'] === $target_id) {
                    $r['checked_in'] = 0;
                }
            }
            unset($r);
        }
    }

    // Save Edit
    if ($action === 'save_edit') {
        $target_id = sanitize_text_field($_POST['reg_id']);
        $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
        $reg       = get_option($opt_key);
        
        if ($reg) {
            $edit_name = trim(sanitize_text_field($_POST['edit_name']));
            $line_idx  = sanitize_text_field($_POST['edit_line_index']);
            $orig_name = isset($reg['name']) ? trim($reg['name']) : '';
            $orig_line = isset($reg['line_index']) ? (string)$reg['line_index'] : '';

            // Duplicate check if archer name or line is edited to match an existing registrant on that line
            if ( (strcasecmp($orig_name, $edit_name) !== 0 || (string)$orig_line !== (string)$line_idx) ) {
                $dupe_found = false;
                foreach ($registrations as $other) {
                    if (isset($other['id']) && $other['id'] === $target_id) continue;
                    if (isset($other['tournament'], $other['line_index'], $other['name']) &&
                        strcasecmp(trim($other['tournament']), trim($selected_tournament)) === 0 &&
                        (string)$other['line_index'] === (string)$line_idx &&
                        strcasecmp(trim($other['name']), trim($edit_name)) === 0) {
                        $dupe_found = true;
                        break;
                    }
                }

                if ($dupe_found) {
                    $alert_text = '⚠️ EDIT BLOCKED:\n\n' . esc_js($edit_name) . ' is already registered on this shooting line.';
                    return '<div class="sywat-alert sywat-alert-danger" style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:10px 14px; border-radius:4px; font-weight:bold; margin-bottom:12px;">⚠️ Edit Blocked! <strong>' . esc_html($edit_name) . '</strong> is already registered on this shooting line.</div>' .
                           '<script>document.addEventListener("DOMContentLoaded", function() { alert("' . $alert_text . '"); });</script>';
                }
            }

            $line_name = isset($lines[$line_idx]['name']) ? $lines[$line_idx]['name'] : 'N/A';
            $division  = sanitize_text_field($_POST['edit_division']);
            $distance  = ($division === 'Cub (8 - 11)' || $division === 'No Show') ? 'Cub' : '20yd';
            $pay_opt   = sanitize_text_field($_POST['edit_pay_option']);
            $style     = sanitize_text_field($_POST['edit_style']);

            $reg['name']        = $edit_name;
            $reg['line_index']  = $line_idx;
            $reg['line_name']   = $line_name;
            $reg['distance']    = $distance;
            $reg['division']    = $division;
            $reg['gender']      = sanitize_text_field($_POST['edit_gender']);
            $reg['style']       = $sanitize_style_for_division($style, $division);
            $reg['paid']        = $calculate_paid_status($pay_opt, $division);
            $reg['balance_due'] = isset($_POST['edit_balance_due']) ? floatval($_POST['edit_balance_due']) : 0;
            
            if ($reg['paid'] === 'Family') {
                $sel_fam_id = isset($_POST['edit_family_group_id']) ? sanitize_text_field($_POST['edit_family_group_id']) : '';
                $edit_families = isset($existing_families_by_line[$line_idx]) ? $existing_families_by_line[$line_idx] : $existing_families;

                if (!empty($sel_fam_id)) {
                    $reg['family_id']   = $sel_fam_id;
                    $reg['family_name'] = isset($edit_families[$sel_fam_id]) ? $edit_families[$sel_fam_id] : 'Family Group';
                } elseif (empty($reg['family_id']) || $orig_line !== $line_idx) {
                    // Generate new Anchor Family ID if moved to a new line or previously unassigned
                    $reg['family_id']   = 'fam_' . $active_t_slug . '_l' . sanitize_title($line_idx) . '_' . sanitize_title($edit_name) . '_' . substr(md5(uniqid()), 0, 4);
                    $reg['family_name'] = $edit_name . ' (Family Cap)';
                }
            } else {
                unset($reg['family_id']); unset($reg['family_name']);
            }

            update_option($opt_key, $reg, false);

            foreach ($registrations as &$r) {
                if (isset($r['id']) && $r['id'] === $target_id) {
                    $r = $reg;
                }
            }
            unset($r);
        }
    }

    // Delete Archer
    if ($action === 'delete_archer') {
        $target_id = sanitize_text_field($_POST['reg_id']);
        delete_option('sywat_t_' . $active_t_slug . '_' . $target_id);

        $registrations = array_values(array_filter($registrations, function($r) use ($target_id) {
            return !(isset($r['id']) && $r['id'] === $target_id);
        }));
    }

    return '';
}
