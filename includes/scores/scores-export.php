<?php
/**
 * Sub-Module: Scores Engine CSV Export Processor
 */

if (!defined('ABSPATH')) exit;

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

            $gender_code = ($gender_raw === 'Female') ? 'F' : 'M';

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
                usort($indices, function($i1, $i2) use ($processed_items) {
                    if ($processed_items[$i1]['score'] !== $processed_items[$i2]['score']) {
                        return $processed_items[$i2]['score'] <=> $processed_items[$i1]['score'];
                    }
                    return $processed_items[$i2]['x_count'] <=> $processed_items[$i1]['x_count'];
                });

                for ($k = 1; $k < count($indices); $k++) {
                    $target_idx = $indices[$k];
                    if ($processed_items[$target_idx]['score'] > 0 || $processed_items[$target_idx]['x_count'] > 0) {
                        $processed_items[$target_idx]['guest'] = 'Y';
                        $processed_items[$target_idx]['div_style'] = $processed_items[$target_idx]['base_prefix'] . 'GUEST';
                    }
                }
            }
        }

        usort($processed_items, function($a, $b) {
            $cmp_name = strcasecmp($a['formatted_name'], $b['formatted_name']);
            if ($cmp_name !== 0) return $cmp_name;
            $cmp_div = strcasecmp($a['div_style'], $b['div_style']);
            if ($cmp_div !== 0) return $cmp_div;
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            return $b['x_count'] <=> $a['x_count'];
        });

        while (ob_get_level()) ob_end_clean();

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
