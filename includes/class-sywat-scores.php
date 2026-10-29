<?php
/**
 * Module: SYWAT Score Management & Entry App
 * Description: Front-end scoring interface featuring real-time AJAX auto-save, score validation (0-300),
 *              X-count tie-breakers, division/style sorting, CSV score export generation, and multi-tenant shop scoping.
 * Shortcode: [sywat_scores]
 * Author: SYWAT Archery
 * Version: 2.1.0
 */

if (!defined('ABSPATH')) exit;

// -----------------------------------------------------------------------------
// 1. EARLY CSV EXPORT PROCESSOR (Triggers before page headers are sent)
// -----------------------------------------------------------------------------
add_action('template_redirect', 'sywat_process_csv_export');

function sywat_process_csv_export() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sywat_action']) && $_POST['sywat_action'] === 'export_csv') {
        if (!isset($_POST['sywat_nonce']) || !wp_verify_nonce($_POST['sywat_nonce'], 'sywat_scores_action')) {
            wp_die('Security token expired. Please refresh and try again.');
        }

        $user = wp_get_current_user();
        if (!is_user_logged_in() || !in_array('administrator', (array)$user->roles)) {
            wp_die('Access Denied: Administrator access required.');
        }

        global $wpdb;

        $selected_tournament = isset($_POST['selected_tournament']) ? sanitize_text_field($_POST['selected_tournament']) : '';
        if (empty($selected_tournament)) {
            $tournaments = get_option('sywat_tournaments_list', array());
            if (!empty($tournaments) && is_array($tournaments)) {
                reset($tournaments);
                $selected_tournament = key($tournaments);
            } else {
                $selected_tournament = get_option('sywat_current_tournament', 'CCR SYWAT Qualifier');
            }
        }

        $active_t_slug = sanitize_title($selected_tournament);
        $locked_lines  = get_option('sywat_locks_' . $active_t_slug, array());

        if (empty($locked_lines)) {
            wp_die('Access Denied: At least one shooting line must be locked to export CSV.');
        }

        // Fetch ALL checked-in archers for the entire tournament across ALL locked lines
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('sywat_t_' . $active_t_slug . '_') . '%'
        ));

        $export_regs = array();
        foreach ($results as $row) {
            $reg_data = maybe_unserialize($row->option_value);
            if (is_array($reg_data) && isset($reg_data['id'])) {
                $match_tourn   = isset($reg_data['tournament']) && $reg_data['tournament'] === $selected_tournament;
                $is_checked_in = !empty($reg_data['checked_in']);
                $line_idx      = isset($reg_data['line_index']) ? (string)$reg_data['line_index'] : '';
                $is_locked     = !empty($locked_lines[$line_idx]);

                // Include archer if checked in and their line is locked
                if ($match_tourn && $is_checked_in && $is_locked) {
                    $export_regs[] = $reg_data;
                }
            }
        }

        $processed_items = array();
        foreach ($export_regs as $reg) {
            // 1. Archer Name (Lastname, Firstname)
            $full_name  = trim(isset($reg['name']) ? $reg['name'] : '');
            $name_parts = preg_split('/\s+/', $full_name);
            if (count($name_parts) > 1) {
                $last_name      = array_pop($name_parts);
                $first_name     = implode(' ', $name_parts);
                $formatted_name = $last_name . ', ' . $first_name;
            } else {
                $formatted_name = $full_name;
            }

            // 2. Score & 3. Xs
            $score_val   = isset($reg['score']) ? intval($reg['score']) : 0;
            $x_count_val = isset($reg['x_count']) ? intval($reg['x_count']) : 0;

            // 4. Division/Style Mapping
            $div_raw   = isset($reg['division']) ? $reg['division'] : '';
            $style_raw = isset($reg['style']) ? $reg['style'] : '';
            $gender_raw= isset($reg['gender']) ? $reg['gender'] : '';

            $is_pro = (stripos($style_raw, 'Pro') !== false);

            // Base Division Code
            $div_code = '';
            if (stripos($div_raw, 'Cub') !== false) {
                $div_code = 'C';
            } elseif (stripos($div_raw, 'Young Adult') !== false) {
                $div_code = 'YA';
            } elseif (stripos($div_raw, 'Youth') !== false) {
                $div_code = 'Y';
            } elseif (stripos($div_raw, 'Master Senior') !== false) {
                $div_code = 'MS';
            } elseif (stripos($div_raw, 'Silver Senior') !== false) {
                $div_code = 'SS';
            } elseif (stripos($div_raw, 'Senior') !== false) {
                $div_code = 'S'; 
            } elseif (stripos($div_raw, 'Adult') !== false) {
                $div_code = 'A'; 
            }

            // Gender Code
            $gender_code = ($gender_raw === 'Female') ? 'F' : 'M';

            // Style Code
            $style_code = '';
            if (stripos($style_raw, 'Genesis') !== false) {
                $style_code = 'GEN';
            } elseif (stripos($style_raw, 'FSL-R') !== false || stripos($style_raw, 'FSLR') !== false || stripos($style_raw, 'Olympic Recurve') !== false) {
                $style_code = 'FSLR';
            } elseif (strpos($style_raw, 'FSL') !== false) {
                $style_code = 'FSL';
            } elseif (strpos($style_raw, 'BHFS') !== false) {
                $style_code = 'BHFS';
            } elseif (strpos($style_raw, 'MBH') !== false) {
                $style_code = 'MBH';
            } elseif (strpos($style_raw, 'BBC') !== false) {
                $style_code = 'BBC';
            } elseif (strpos($style_raw, 'BBR') !== false) {
                $style_code = 'BBR';
            } elseif (strpos($style_raw, 'TRAD') !== false || stripos($style_raw, 'Traditional') !== false) {
                $style_code = 'TRAD';
            } elseif (strpos($style_raw, 'LB') !== false || stripos($style_raw, 'Longbow') !== false) {
                $style_code = 'LB';
            } elseif (strpos($style_raw, 'FS') !== false || stripos($style_raw, 'Freestyle') !== false) {
                $style_code = 'FS';
            }

            $div_style_str = $div_code . $gender_code . $style_code;

            // If it's a Pro style, prepend 'P' to the entire classification (e.g. PAMFS)
            if ($is_pro) {
                $div_style_str = 'P' . $div_style_str;
            }

            $processed_items[] = array(
                'formatted_name' => $formatted_name,
                'score'          => $score_val,
                'x_count'        => $x_count_val,
                'div_style'      => $div_style_str,
                'guest'          => '',
                'incomplete'     => ($score_val === 0 && $x_count_val === 0) ? 'Y' : '',
                'base_prefix'    => ($is_pro ? 'P' : '') . $div_code . $gender_code
            );
        }

        // Group by exact Archer Name and Division/Style to flag duplicate "Guest" scores
        $groups = array();
        foreach ($processed_items as $idx => $item) {
            $key = strtolower($item['formatted_name']) . '|||' . strtolower($item['div_style']);
            $groups[$key][] = $idx;
        }

        foreach ($groups as $key => $indices) {
            if (count($indices) > 1) {
                // Sort duplicate entries by score DESC, then x_count DESC
                usort($indices, function($i1, $i2) use ($processed_items) {
                    if ($processed_items[$i1]['score'] !== $processed_items[$i2]['score']) {
                        return $processed_items[$i2]['score'] <=> $processed_items[$i1]['score'];
                    }
                    return $processed_items[$i2]['x_count'] <=> $processed_items[$i1]['x_count'];
                });

                // Top score remains official (guest = ''); lower duplicate scores get guest = 'Y' UNLESS score & x_count are both 0
                for ($k = 1; $k < count($indices); $k++) {
                    $target_idx = $indices[$k];
                    if ($processed_items[$target_idx]['score'] > 0 || $processed_items[$target_idx]['x_count'] > 0) {
                        $processed_items[$target_idx]['guest'] = 'Y';
                        // Replace the style suffix with GUEST (e.g., AMFS -> AMGUEST)
                        $processed_items[$target_idx]['div_style'] = $processed_items[$target_idx]['base_prefix'] . 'GUEST';
                    }
                }
            }
        }

        // Sort final CSV output by Archer Name (Lastname, Firstname), then Division/Style, then Score DESC
        usort($processed_items, function($a, $b) {
            $cmp_name = strcasecmp($a['formatted_name'], $b['formatted_name']);
            if ($cmp_name !== 0) {
                return $cmp_name;
            }
            $cmp_div = strcasecmp($a['div_style'], $b['div_style']);
            if ($cmp_div !== 0) {
                return $cmp_div;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return $b['x_count'] <=> $a['x_count'];
        });

        // Clear output buffers to ensure clean CSV binary stream
        while (ob_get_level()) {
            ob_end_clean();
        }

        $filename = 'SYWAT_Scores_' . sanitize_file_name($selected_tournament) . '_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fputcsv($output, array('Archer', 'Score1', 'Xs', 'Division/Style', 'Guest (Y)', 'Incomplete (Y)'));

        foreach ($processed_items as $item) {
            fputcsv($output, array(
                $item['formatted_name'],
                $item['score'],
                $item['x_count'],
                $item['div_style'],
                $item['guest'],
                $item['incomplete']
            ));
        }

        fclose($output);
        exit;
    }
}

// -----------------------------------------------------------------------------
// 2. MAIN SHORTCODE RRENDERER
// -----------------------------------------------------------------------------
add_shortcode('sywat_scores', 'render_sywat_scores_app');

function render_sywat_scores_app() {
    // Permissions Check
    $user = wp_get_current_user();
    if ( ! is_user_logged_in() || ! array_intersect(array('administrator', 'management', 'non-shooting-member', 'staff_shooter', 'sywat_shop_manager', 'sywat_shop_user'), (array) $user->roles) ) {
        return '<p style="color:red; padding:20px;">Access denied. Staff access required.</p>';
    }

    $is_admin     = is_user_logged_in() && in_array('administrator', (array) $user->roles);
    $user_shop_id = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';

    // ==========================================
    // AJAX AUTOSAVE HANDLER
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sywat_action']) && $_POST['sywat_action'] === 'autosave_single_score') {
        if (!isset($_POST['sywat_nonce']) || !wp_verify_nonce($_POST['sywat_nonce'], 'sywat_scores_action')) {
            wp_send_json_error(array('message' => 'Security token expired'));
        }

        $clean_id    = sanitize_text_field($_POST['reg_id']);
        $score_str   = isset($_POST['score']) ? trim($_POST['score']) : '';
        $x_str       = isset($_POST['x_count']) ? trim($_POST['x_count']) : '';
        $tourn_name  = isset($_POST['selected_tournament']) ? sanitize_text_field($_POST['selected_tournament']) : '';

        if ($score_str !== '' && (intval($score_str) < 0 || intval($score_str) > 300)) {
            wp_send_json_error(array('message' => 'Score out of range (0-300)'));
        }
        if ($x_str !== '' && (intval($x_str) < 0 || intval($x_str) > 60)) {
            wp_send_json_error(array('message' => 'X count out of range (0-60)'));
        }

        $active_t_slug = sanitize_title($tourn_name);
        $opt_key       = 'sywat_t_' . $active_t_slug . '_' . $clean_id;
        $reg           = get_option($opt_key);

        $locked_lines = get_option('sywat_locks_' . $active_t_slug, array());

        if ($reg) {
            $line_idx = isset($reg['line_index']) ? (string)$reg['line_index'] : '';
            if (!empty($locked_lines[$line_idx])) {
                wp_send_json_error(array('message' => 'Line is locked'));
                exit;
            }

            $reg['score']   = ($score_str !== '') ? intval($score_str) : 0;
            $reg['x_count'] = ($x_str !== '') ? intval($x_str) : 0;

            update_option($opt_key, $reg, false);
            wp_send_json_success(array('reg_id' => $clean_id, 'message' => 'Saved'));
        }

        wp_send_json_error(array('message' => 'Archer record not found'));
        exit;
    }

    ob_start();
    global $wpdb;

    // 1. Fetch Tournaments List with Shop Scoping
    $all_tournaments = get_option('sywat_tournaments_list', array());
    if (empty($all_tournaments) || !is_array($all_tournaments)) {
        $single_name = get_option('sywat_current_tournament', 'CCR SYWAT Qualifier');
        $all_tournaments = array($single_name => get_option('sywat_line_config', array()));
    }

    $tournaments = array();
    foreach ($all_tournaments as $t_name => $t_data) {
        $t_shop = function_exists('sywat_get_tournament_shop_id') ? sywat_get_tournament_shop_id($t_data) : 'shop_ccr';
        if ($is_admin || $t_shop === $user_shop_id) {
            $tournaments[$t_name] = function_exists('sywat_get_tournament_lines') ? sywat_get_tournament_lines($t_data) : $t_data;
        }
    }

    $selected_tournament = isset($_POST['selected_tournament']) ? sanitize_text_field($_POST['selected_tournament']) : (isset($_GET['selected_tournament']) ? sanitize_text_field($_GET['selected_tournament']) : '');
    $selected_line_idx   = isset($_POST['selected_line_idx'])   ? sanitize_text_field($_POST['selected_line_idx'])   : (isset($_GET['selected_line_idx']) ? sanitize_text_field($_GET['selected_line_idx']) : 'all');
    $search_query        = isset($_POST['search_query'])        ? sanitize_text_field($_POST['search_query'])        : '';

    if (empty($selected_tournament) && !empty($tournaments)) {
        reset($tournaments);
        $selected_tournament = key($tournaments);
    }

    $lines = isset($tournaments[$selected_tournament]) ? $tournaments[$selected_tournament] : array();
    $active_t_slug = sanitize_title($selected_tournament);
    
    // Fetch Active Locks
    $locked_lines = get_option('sywat_locks_' . $active_t_slug, array());
    $current_view_locked = ($selected_line_idx !== 'all' && !empty($locked_lines[$selected_line_idx]));

    $notice = '';

    // 2. Action Processors (Batch Save / Reset / Lock)
    $is_valid_nonce = (isset($_POST['sywat_nonce']) && wp_verify_nonce($_POST['sywat_nonce'], 'sywat_scores_action'));
    $action = isset($_POST['sywat_action']) ? $_POST['sywat_action'] : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action && !$is_valid_nonce && $action !== 'autosave_single_score') {
        $notice = '<div class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce) {
        
        // --- Action: Toggle Line Lock (Administrator Only) ---
        if ($action === 'toggle_lock') {
            if (!$is_admin) {
                $notice = '<div class="sywat-alert sywat-alert-danger">Access denied. Administrator privileges required to lock/unlock lines.</div>';
            } elseif ($selected_line_idx === 'all') {
                $notice = '<div class="sywat-alert sywat-alert-danger">Please filter to a specific shooting line first to lock or unlock it.</div>';
            } else {
                if (!empty($locked_lines[$selected_line_idx])) {
                    unset($locked_lines[$selected_line_idx]);
                    $notice = '<div class="sywat-alert sywat-alert-success">🔓 Line unlocked. Scores can now be edited.</div>';
                } else {
                    $locked_lines[$selected_line_idx] = 1;
                    $notice = '<div class="sywat-alert sywat-alert-success">🔒 Line locked. Scores on this line are frozen and cannot be edited until unlocked.</div>';
                }
                update_option('sywat_locks_' . $active_t_slug, $locked_lines);
                $current_view_locked = !empty($locked_lines[$selected_line_idx]);
            }
        }

        // --- Action A: Reset All Scores to 0 (Administrator Only) ---
        if ($action === 'reset_scores') {
            if (!$is_admin) {
                $notice = '<div class="sywat-alert sywat-alert-danger">Access denied. Administrator privileges required to reset scores.</div>';
            } else {
                $reset_count = 0;
                
                $all_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like('sywat_t_' . $active_t_slug . '_') . '%'
                ) );

                foreach ($all_rows as $row) {
                    $reg = maybe_unserialize($row->option_value);
                    if (is_array($reg) && isset($reg['id'])) {
                        $match_tourn   = isset($reg['tournament']) && $reg['tournament'] === $selected_tournament;
                        $match_line    = ($selected_line_idx === 'all') || (isset($reg['line_index']) && (string)$reg['line_index'] === (string)$selected_line_idx);
                        $is_checked_in = !empty($reg['checked_in']);
                        $is_locked     = !empty($locked_lines[$reg['line_index']]);

                        if ($match_tourn && $match_line && $is_checked_in && !$is_locked) {
                            $opt_key = 'sywat_t_' . $active_t_slug . '_' . $reg['id'];
                            $reg['score']   = 0;
                            $reg['x_count'] = 0;
                            update_option($opt_key, $reg, false);
                            $reset_count++;
                        }
                    }
                }
                $notice = '<div class="sywat-alert sywat-alert-success">Successfully reset scores and X counts to 0 for <strong>' . $reset_count . '</strong> unlocked, checked-in archer(s).</div>';
            }
        }

        // --- Action B: Save Batch Scores ---
        if ($action === 'save_scores') {
            $has_invalid_data = false;
            
            $submitted_ids = array_unique(array_merge(
                isset($_POST['scores']) && is_array($_POST['scores']) ? array_keys($_POST['scores']) : array(),
                isset($_POST['x_counts']) && is_array($_POST['x_counts']) ? array_keys($_POST['x_counts']) : array()
            ));

            foreach ($submitted_ids as $id) {
                $clean_id = sanitize_text_field($id);
                if (isset($_POST['scores'][$clean_id])) {
                    $score_str = trim($_POST['scores'][$clean_id]);
                    if ($score_str !== '') {
                        $s_val = intval($score_str);
                        if ($s_val < 0 || $s_val > 300) { $has_invalid_data = true; break; }
                    }
                }
                if (isset($_POST['x_counts'][$clean_id])) {
                    $x_str = trim($_POST['x_counts'][$clean_id]);
                    if ($x_str !== '') {
                        $x_val = intval($x_str);
                        if ($x_val < 0 || $x_val > 60) { $has_invalid_data = true; break; }
                    }
                }
            }

            if ($has_invalid_data) {
                $notice = '<div class="sywat-alert sywat-alert-danger">⚠️ <strong>Save Rejected!</strong> One or more values were invalid. Scores must be 0–300 and X Counts 0–60. Please correct highlighted fields and save again.</div>';
            } elseif (empty($submitted_ids)) {
                $notice = '<div class="sywat-alert sywat-alert-success">No changes were made.</div>';
            } else {
                $saved_count = 0;
                foreach ($submitted_ids as $id) {
                    $clean_id = sanitize_text_field($id);
                    $opt_key  = 'sywat_t_' . $active_t_slug . '_' . $clean_id;
                    $reg      = get_option($opt_key);

                    if ($reg) {
                        if (!empty($locked_lines[$reg['line_index']])) {
                            continue;
                        }

                        if (isset($_POST['scores'][$clean_id])) {
                            $raw_score = trim($_POST['scores'][$clean_id]);
                            $reg['score'] = ($raw_score !== '') ? intval($raw_score) : 0;
                        }
                        if (isset($_POST['x_counts'][$clean_id])) {
                            $raw_x = trim($_POST['x_counts'][$clean_id]);
                            $reg['x_count'] = ($raw_x !== '') ? intval($raw_x) : 0;
                        }

                        update_option($opt_key, $reg, false);
                        $saved_count++;
                    }
                }
                
                if ($saved_count > 0) {
                    $notice = '<div class="sywat-alert sywat-alert-success">Successfully updated scores for <strong>' . $saved_count . '</strong> unlocked archer(s)!</div>';
                }
            }
        }
    }

    // 3. Retrieve Archers for Active View Table
    $registrations = array();
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('sywat_t_' . $active_t_slug . '_') . '%'
    ) );

    foreach ($results as $row) {
        $reg_data = maybe_unserialize($row->option_value);
        if (is_array($reg_data) && isset($reg_data['id'])) {
            $registrations[] = $reg_data;
        }
    }

    $tourn_registrations = array();
    foreach ($registrations as $reg) {
        $match_tourn   = isset($reg['tournament']) && $reg['tournament'] === $selected_tournament;
        $match_line    = ($selected_line_idx === 'all') || (isset($reg['line_index']) && (string)$reg['line_index'] === (string)$selected_line_idx);
        $is_checked_in = !empty($reg['checked_in']);

        if ($match_tourn && $match_line && $is_checked_in) {
            $tourn_registrations[] = $reg;
        }
    }

    usort($tourn_registrations, function($a, $b) {
        $line_a = isset($a['line_index']) ? (int)$a['line_index'] : 99;
        $line_b = isset($b['line_index']) ? (int)$b['line_index'] : 99;
        if ($line_a !== $line_b) {
            return $line_a <=> $line_b;
        }
        $name_a = isset($a['name']) ? trim($a['name']) : '';
        $name_b = isset($b['name']) ? trim($b['name']) : '';
        return strcasecmp($name_a, $name_b);
    });
    ?>

    <style>
        .sywat-score-input { width: 80px; padding: 6px 8px; border: 1px solid #94a3b8; border-radius: 4px; font-size: 14px; text-align: center; font-weight: bold; transition: all 0.15s ease-in-out; }
        .sywat-score-input:focus { border-color: #2271b1; outline: 2px solid #2271b1; }
        .sywat-score-input:disabled { background: #f1f5f9; border-color: #cbd5e1; color: #64748b; cursor: not-allowed; }
        .sywat-score-input.invalid-input { border: 2px solid #dc3545 !important; background-color: #f8d7da !important; color: #721c24 !important; }

        .sywat-save-status { font-size: 11px; font-weight: 700; padding: 2px 6px; border-radius: 3px; display: inline-block; white-space: nowrap; }
        .sywat-status-saved { color: #008a20; background: #d4edda; border: 1px solid #c3e6cb; }
        .sywat-status-draft { color: #b45309; background: #fef3c7; border: 1px solid #fde68a; }
        .sywat-status-saving { color: #0369a1; background: #e0f2fe; border: 1px solid #bae6fd; }
        .sywat-status-error { color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; }
        .sywat-status-locked { color: #475569; background: #e2e8f0; border: 1px solid #cbd5e1; }
    </style>

    <div class="sywat-wrap">
        <div class="no-print"><?php echo $notice; ?></div>

        <!-- FILTER BAR -->
        <form method="post" id="sywat_score_filter_form" class="sywat-card no-print" style="display:flex; gap:15px; align-items:center; flex-wrap:wrap; padding:15px; margin-bottom:20px;">
            <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
            <div>
                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Tournament:</label>
                <select name="selected_tournament" id="selected_tournament_val" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; height:32px;" onchange="document.getElementById('sywat_score_filter_form').submit();">
                    <?php foreach ($tournaments as $t_name => $t_lines): ?>
                        <option value="<?php echo esc_attr($t_name); ?>" <?php selected($selected_tournament, $t_name); ?>>
                            <?php echo esc_html($t_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Shooting Line:</label>
                <select name="selected_line_idx" id="selected_line_val" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; height:32px;" onchange="document.getElementById('sywat_score_filter_form').submit();">
                    <option value="all" <?php selected($selected_line_idx, 'all'); ?>>All Lines (Entire Weekend)</option>
                    <?php foreach ($lines as $idx => $line): ?>
                        <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$selected_line_idx, (string)$idx); ?>>
                            <?php echo esc_html($line['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-left:auto;">
                <a href="/sywat-volunteer-hub/" class="sywat-btn sywat-btn-secondary">← Back to Volunteer Hub</a>
            </div>
        </form>

        <div class="sywat-card no-print">
            <form method="post" id="sywat_batch_score_form">
                <?php wp_nonce_field('sywat_scores_action', 'sywat_nonce'); ?>
                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">

                <div class="sywat-card-title">
                    <div>
                        <span style="font-size:18px;">Batch Score Entry</span>
                        <div style="font-size:13px; color:#64748b; font-weight:normal; margin-top:2px;">
                            Displaying <strong><?php echo count($tourn_registrations); ?></strong> checked-in archer(s) for <?php echo esc_html($selected_tournament); ?>
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <!-- Live Search Input -->
                        <div style="display:flex; gap:5px; align-items:center; margin-right:5px;">
                            <input type="text" id="sywat_score_search_input" value="<?php echo esc_attr($search_query); ?>" placeholder="Search archer name..." autocomplete="off" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; width:180px; height:32px; box-sizing:border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                            <button type="button" class="sywat-btn sywat-btn-secondary" style="height:32px;" onclick="document.getElementById('sywat_score_search_input').value=''; document.getElementById('sywat_score_search_input').dispatchEvent(new Event('input'));">Clear</button>
                        </div>

                        <!-- Export CSV Button (Admin Only & Shown Whenever Locked Lines Exist) -->
                        <?php if ($is_admin && !empty($locked_lines)): ?>
                            <button type="submit" name="sywat_action" value="export_csv" class="sywat-btn sywat-btn-primary" style="height:32px;" title="Export scores for ALL locked lines to a CSV file">📥 Export CSV</button>
                        <?php endif; ?>

                        <?php if ($is_admin): ?>
                            <?php if ($selected_line_idx !== 'all'): ?>
                                <?php if ($current_view_locked): ?>
                                    <button type="submit" name="sywat_action" value="toggle_lock" class="sywat-btn sywat-btn-secondary" style="height:32px;" title="Unlock to edit scores">🔓 Unlock Line</button>
                                <?php else: ?>
                                    <button type="submit" name="sywat_action" value="toggle_lock" class="sywat-btn sywat-btn-secondary" style="height:32px;" title="Lock to prevent edits">🔒 Lock Line</button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <button type="submit" name="sywat_action" value="reset_scores" class="sywat-btn sywat-btn-danger" style="height:32px;" onclick="return confirm('⚠️ Are you sure you want to RESET ALL SCORES to 0 for the selected line/tournament?\n\nThis will set score and X count to 0 for all unlocked, checked-in archers in this view.');">↺ Reset All Scores to 0</button>
                        <?php endif; ?>
                        
                        <button type="submit" name="sywat_action" value="save_scores" class="sywat-btn sywat-btn-success" style="height:32px;">💾 Save All Scores</button>
                    </div>
                </div>

                <?php if (!empty($tourn_registrations)): ?>
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th style="width: 4%;">#</th>
                                <th style="width: 20%; text-align: left;">Archer Name</th>
                                <th style="width: 15%; text-align: left;">Shooting Line</th>
                                <th style="width: 8%;">Gender</th>
                                <th style="width: 10%; text-align: left;">Division</th>
                                <th style="width: 12%; text-align: left;">Style</th>
                                <th style="width: 11%; text-align: center;">Score (Max 300)</th>
                                <th style="width: 11%; text-align: center;">X Count (Max 60)</th>
                                <th style="width: 9%; text-align: center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $num = 1; foreach ($tourn_registrations as $reg): ?>
                                <?php 
                                    $reg_id    = isset($reg['id']) ? $reg['id'] : '';
                                    $score     = isset($reg['score']) && $reg['score'] !== '' ? $reg['score'] : 0;
                                    $x_count   = isset($reg['x_count']) && $reg['x_count'] !== '' ? $reg['x_count'] : 0;
                                    $gender_str= !empty($reg['gender']) ? $reg['gender'] : '—';
                                    $div_str   = !empty($reg['division']) ? $reg['division'] : '—';
                                    $style_str = !empty($reg['style']) ? $reg['style'] : '—';
                                    $is_locked = !empty($locked_lines[$reg['line_index']]);
                                    $dis_attr  = $is_locked ? 'disabled' : '';
                                ?>
                                <tr class="sywat-score-row <?php echo $is_locked ? 'locked-row' : ''; ?>" data-id="<?php echo esc_attr($reg_id); ?>" data-name="<?php echo esc_attr(strtolower($reg['name'])); ?>">
                                    <td><strong><?php echo $num++; ?></strong></td>
                                    <td><strong><?php echo esc_html($reg['name']); ?></strong></td>
                                    <td><?php echo esc_html(isset($reg['line_name']) ? $reg['line_name'] : 'Line 1'); ?></td>
                                    <td style="text-align: center;"><?php echo esc_html($gender_str); ?></td>
                                    <td><?php echo esc_html($div_str); ?></td>
                                    <td><?php echo esc_html($style_str); ?></td>
                                    <td style="text-align: center;">
                                        <input type="number" name="scores[<?php echo esc_attr($reg_id); ?>]" data-id="<?php echo esc_attr($reg_id); ?>" data-original-val="<?php echo esc_attr($score); ?>" value="<?php echo esc_attr($score); ?>" min="0" max="300" placeholder="0" class="sywat-score-input score-val" <?php echo $dis_attr; ?>>
                                    </td>
                                    <td style="text-align: center;">
                                        <input type="number" name="x_counts[<?php echo esc_attr($reg_id); ?>]" data-id="<?php echo esc_attr($reg_id); ?>" data-original-val="<?php echo esc_attr($x_count); ?>" value="<?php echo esc_attr($x_count); ?>" min="0" max="60" placeholder="0" class="sywat-score-input x-val" <?php echo $dis_attr; ?>>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($is_locked): ?>
                                            <span class="sywat-save-status sywat-status-locked" title="Unlocked by Admins Only">🔒 Locked</span>
                                        <?php else: ?>
                                            <span class="sywat-save-status sywat-status-saved" id="status_<?php echo esc_attr($reg_id); ?>">✓ Saved</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                        <?php if ($is_admin): ?>
                            <button type="submit" name="sywat_action" value="reset_scores" class="sywat-btn sywat-btn-danger" style="height:32px;" onclick="return confirm('⚠️ Are you sure you want to RESET ALL SCORES to 0 for the selected line/tournament?\n\nThis will set score and X count to 0 for all unlocked, checked-in archers in this view.');">↺ Reset All Scores to 0</button>
                        <?php endif; ?>
                        <button type="submit" name="sywat_action" value="save_scores" class="sywat-btn sywat-btn-success" style="height:32px;">💾 Save All Scores</button>
                    </div>
                <?php else: ?>
                    <p style="color:#64748b; font-style:italic; padding:20px 0;">No checked-in archers found for this tournament/line selection.</p>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Hybrid Local Draft + Background Auto-Save Script -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const currentTourn = document.getElementById('selected_tournament_val') ? document.getElementById('selected_tournament_val').value : '';
        const nonceVal     = document.getElementById('sywat_nonce') ? document.getElementById('sywat_nonce').value : '';

        // Real-Time Search Filter
        const searchInput = document.getElementById('sywat_score_search_input');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('.sywat-score-row');
                
                rows.forEach(row => {
                    const name = row.getAttribute('data-name');
                    if (name && name.includes(filter)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });

                const hiddenSearches = document.querySelectorAll('.sywat-hidden-search');
                hiddenSearches.forEach(hidden => {
                    hidden.value = this.value;
                });
            });
            searchInput.dispatchEvent(new Event('input'));
        }

        // Helper: Status Badge Updater
        function setRowStatus(regId, state, customMsg) {
            const badge = document.getElementById('status_' + regId);
            if (!badge) return;

            badge.className = 'sywat-save-status';
            if (state === 'saved') {
                badge.classList.add('sywat-status-saved');
                badge.textContent = customMsg || '✓ Saved';
            } else if (state === 'draft') {
                badge.classList.add('sywat-status-draft');
                badge.textContent = customMsg || '⚡ Local Draft';
            } else if (state === 'saving') {
                badge.classList.add('sywat-status-saving');
                badge.textContent = customMsg || '⏳ Saving...';
            } else if (state === 'error') {
                badge.classList.add('sywat-status-error');
                badge.textContent = customMsg || '⚠️ Invalid Range';
            }
        }

        // Helper: Range Validator
        function validateInput(input) {
            const min = parseInt(input.getAttribute('min'), 10) || 0;
            const max = parseInt(input.getAttribute('max'), 10) || 300;
            const valStr = input.value.trim();

            if (valStr === '') {
                input.classList.remove('invalid-input');
                return true;
            }

            const val = parseInt(valStr, 10);
            if (isNaN(val) || val < min || val > max) {
                input.classList.add('invalid-input');
                return false;
            } else {
                input.classList.remove('invalid-input');
                return true;
            }
        }

        // Local Storage Key Generator
        function getStorageKey(regId) {
            return 'sywat_draft_' + currentTourn.replace(/[^a-z0-9]/gi, '_') + '_' + regId;
        }

        // 1. Restore Unsaved Drafts on Page Load
        document.querySelectorAll('.sywat-score-row').forEach(row => {
            const regId = row.getAttribute('data-id');
            const savedDraft = localStorage.getItem(getStorageKey(regId));
            const isLocked = row.classList.contains('locked-row');

            if (savedDraft && !isLocked) {
                try {
                    const data = JSON.parse(savedDraft);
                    const scoreInput = row.querySelector('.score-val');
                    const xInput     = row.querySelector('.x-val');

                    if (scoreInput && data.score !== undefined) scoreInput.value = data.score;
                    if (xInput && data.x_count !== undefined) xInput.value = data.x_count;

                    setRowStatus(regId, 'draft', '⚡ Local Draft');
                } catch(e) {}
            } else if (isLocked) {
                localStorage.removeItem(getStorageKey(regId));
            }
        });

        // 2. Background AJAX Saver
        function performBackgroundSave(regId) {
            const row = document.querySelector('.sywat-score-row[data-id="' + regId + '"]');
            if (!row || row.classList.contains('locked-row')) return;

            const scoreInput = row.querySelector('.score-val');
            const xInput     = row.querySelector('.x-val');

            const isScoreValid = validateInput(scoreInput);
            const isXValid     = validateInput(xInput);

            if (!isScoreValid || !isXValid) {
                setRowStatus(regId, 'error', '⚠️ Range Error');
                return;
            }

            const scoreVal = scoreInput ? scoreInput.value : '0';
            const xVal     = xInput ? xInput.value : '0';

            localStorage.setItem(getStorageKey(regId), JSON.stringify({ score: scoreVal, x_count: xVal }));

            if (!navigator.onLine) {
                setRowStatus(regId, 'draft', '⚠️ Offline Draft');
                return;
            }

            setRowStatus(regId, 'saving', '⏳ Saving...');

            const formData = new FormData();
            formData.append('sywat_action', 'autosave_single_score');
            formData.append('sywat_nonce', nonceVal);
            formData.append('selected_tournament', currentTourn);
            formData.append('reg_id', regId);
            formData.append('score', scoreVal);
            formData.append('x_count', xVal);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (scoreInput) scoreInput.setAttribute('data-original-val', scoreVal);
                    if (xInput) xInput.setAttribute('data-original-val', xVal);
                    
                    localStorage.removeItem(getStorageKey(regId));
                    setRowStatus(regId, 'saved', '✓ Saved');
                } else {
                    setRowStatus(regId, 'draft', '⚡ Local Draft');
                }
            })
            .catch(() => {
                setRowStatus(regId, 'draft', '⚠️ Offline Draft');
            });
        }

        // 3. Event Listeners for Input & Blur
        document.addEventListener('input', function(e) {
            if (e.target && e.target.classList.contains('sywat-score-input') && !e.target.disabled) {
                const regId = e.target.getAttribute('data-id');
                const row   = document.querySelector('.sywat-score-row[data-id="' + regId + '"]');
                if (!row) return;

                const isValid = validateInput(e.target);
                if (isValid) {
                    const scoreInput = row.querySelector('.score-val');
                    const xInput     = row.querySelector('.x-val');
                    localStorage.setItem(getStorageKey(regId), JSON.stringify({ score: scoreInput.value, x_count: xInput.value }));
                    setRowStatus(regId, 'draft', '⚡ Local Draft');
                } else {
                    setRowStatus(regId, 'error', '⚠️ Range Error');
                }
            }
        });

        document.addEventListener('focusout', function(e) {
            if (e.target && e.target.classList.contains('sywat-score-input') && !e.target.disabled) {
                const regId = e.target.getAttribute('data-id');
                performBackgroundSave(regId);
            }
        });

        // 4. Sync Drafts Automatically When Internet Connection Restores
        window.addEventListener('online', function() {
            document.querySelectorAll('.sywat-score-row:not(.locked-row)').forEach(row => {
                const regId = row.getAttribute('data-id');
                if (localStorage.getItem(getStorageKey(regId))) {
                    performBackgroundSave(regId);
                }
            });
        });

        // 5. Concurrency Protection & Local Storage Clear on Full Batch Submit
        const batchForm = document.getElementById('sywat_batch_score_form');
        if (batchForm) {
            batchForm.addEventListener('submit', function(e) {
                if (e.submitter && e.submitter.value === 'reset_scores') {
                    return true;
                }
                if (e.submitter && e.submitter.value === 'toggle_lock') {
                    return true;
                }
                if (e.submitter && e.submitter.value === 'export_csv') {
                    return true;
                }

                const inputs = batchForm.querySelectorAll('.sywat-score-input');
                let hasError = false;
                let firstInvalid = null;
                let actuallyChangedCount = 0;

                inputs.forEach(input => {
                    if (input.disabled) return;

                    if (!validateInput(input)) {
                        hasError = true;
                        if (!firstInvalid) firstInvalid = input;
                    }
                });

                if (hasError) {
                    e.preventDefault();
                    alert('⚠️ Cannot save scores!\n\nOne or more values are out of range:\n- Scores must be between 0 and 300.\n- X Counts must be between 0 and 60.\n\nPlease fix the highlighted red field(s) before saving.');
                    if (firstInvalid) firstInvalid.focus();
                } else {
                    inputs.forEach(input => {
                        if (input.disabled) return;

                        if (input.value.trim() === input.getAttribute('data-original-val').trim()) {
                            input.disabled = true;
                            input.setAttribute('data-concurrency-disabled', 'true');
                        } else {
                            actuallyChangedCount++;
                        }
                    });

                    if (actuallyChangedCount === 0) {
                        e.preventDefault();
                        inputs.forEach(input => {
                            if (input.hasAttribute('data-concurrency-disabled')) {
                                input.disabled = false;
                                input.removeAttribute('data-concurrency-disabled');
                            }
                        });
                        return false; 
                    }

                    document.querySelectorAll('.sywat-score-row').forEach(row => {
                        const regId = row.getAttribute('data-id');
                        localStorage.removeItem(getStorageKey(regId));
                    });
                }
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
