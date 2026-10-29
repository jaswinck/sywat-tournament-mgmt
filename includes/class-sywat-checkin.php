<?php
/**
 * Module: Check-In Desk Portal
 * Description: Front-end desk interface for verifying archer line times, updating payment 
 *              statuses, assigning target lanes onsite, and managing event line check-ins with multi-tenant shop scoping.
 * Shortcode: [sywat_desk]
 * Author: SYWAT Archery
 * Version: 2.3.1
 */

if (!defined('ABSPATH')) exit;

add_shortcode('sywat_desk', 'render_sywat_desk_app');

function render_sywat_desk_app() {
    // 0. Permissions Check
    $user = wp_get_current_user();
    if ( ! is_user_logged_in() || ! array_intersect(array('administrator', 'management', 'non-shooting-member', 'staff_shooter', 'sywat_shop_manager', 'sywat_shop_user'), (array) $user->roles) ) {
        return '<p style="color:red; padding:20px;">Access denied. Management access required.</p>';
    }

    $is_admin     = in_array('administrator', (array) $user->roles);
    $user_shop_id = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';

    ob_start();
    global $wpdb;

    // 1. Migration
    $legacy_data = get_option('sywat_registrations');
    if (is_array($legacy_data) && !empty($legacy_data)) {
        foreach ($legacy_data as $reg) {
            if (isset($reg['id']) && isset($reg['tournament'])) {
                $t_slug = sanitize_title($reg['tournament']);
                update_option('sywat_t_' . $t_slug . '_' . $reg['id'], $reg, false);
            }
        }
        delete_option('sywat_registrations');
        delete_option('sywat_reg_index');
    }

    $old_regs = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_reg_%'");
    if (!empty($old_regs)) {
        foreach ($old_regs as $row) {
            $reg_data = maybe_unserialize($row->option_value);
            if (is_array($reg_data) && isset($reg_data['tournament']) && isset($reg_data['id'])) {
                $t_slug = sanitize_title($reg_data['tournament']);
                update_option('sywat_t_' . $t_slug . '_' . $reg_data['id'], $reg_data, false);
            }
            delete_option($row->option_name);
        }
    }

    // 2. Data Retrieval
    $all_tournaments = get_option('sywat_tournaments_list', array());
    if (empty($all_tournaments) || !is_array($all_tournaments)) {
        $single_name = get_option('sywat_current_tournament', 'CCR SYWAT Qualifier');
        $all_tournaments = array($single_name => get_option('sywat_line_config', array()));
    }

    // Filter Tournaments by Shop Scoping
    $tournaments = array();
    foreach ($all_tournaments as $t_name => $t_data) {
        $t_shop = function_exists('sywat_get_tournament_shop_id') ? sywat_get_tournament_shop_id($t_data) : 'shop_ccr';
        if ($is_admin || $t_shop === $user_shop_id) {
            $tournaments[$t_name] = function_exists('sywat_get_tournament_lines') ? sywat_get_tournament_lines($t_data) : $t_data;
        }
    }

    $selected_tournament = isset($_REQUEST['selected_tournament']) ? sanitize_text_field($_REQUEST['selected_tournament']) : '';
    $selected_line_idx   = isset($_REQUEST['selected_line_idx'])   ? sanitize_text_field($_REQUEST['selected_line_idx'])   : 'all';
    $search_query        = isset($_REQUEST['search_query'])        ? sanitize_text_field($_REQUEST['search_query'])        : '';

    if (empty($selected_tournament) && !empty($tournaments)) {
        reset($tournaments);
        $selected_tournament = key($tournaments);
    }

    $lines = isset($tournaments[$selected_tournament]) ? $tournaments[$selected_tournament] : array();
    $active_t_slug = sanitize_title($selected_tournament);

    // Fetch Closed Lines Array
    $closed_lines_key = 'sywat_closed_lines_' . $active_t_slug;
    $closed_lines = get_option($closed_lines_key, array());
    if (!is_array($closed_lines)) $closed_lines = array();

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

    // Fetch ALL Historical Registrations for Smart Auto-Fill Lookups
    $all_db_results = $wpdb->get_results("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%'");
    $history_profiles = array();

    foreach ($all_db_results as $row) {
        $r = maybe_unserialize($row->option_value);
        if (is_array($r) && !empty($r['name'])) {
            $clean_name = trim($r['name']);
            if (empty($clean_name)) continue;

            $div_val    = isset($r['division']) ? $r['division'] : '';
            $gen_val    = isset($r['gender']) ? $r['gender'] : '';
            $style_val  = isset($r['style']) ? $r['style'] : '';
            $paid_val   = isset($r['paid']) ? $r['paid'] : '';

            if ($div_val === 'No Show' || $style_val === 'No Show' || empty($div_val) || empty($style_val)) {
                continue;
            }

            $norm_key = strtolower(preg_replace('/\s+/', ' ', $clean_name));
            $parts    = explode(' ', $norm_key);
            $fl_key   = (count($parts) > 1) ? reset($parts) . '___' . end($parts) : $norm_key;

            $profile_data = array(
                'full_name' => $clean_name,
                'division'  => $div_val,
                'gender'    => $gen_val,
                'style'     => $style_val,
                'paid'      => $paid_val
            );

            $history_profiles['exact'][$norm_key] = $profile_data;
            if (!isset($history_profiles['fl'][$fl_key])) {
                $history_profiles['fl'][$fl_key] = $profile_data;
            }
        }
    }

    // Fetch Active Season TFAA Standings Cache
    $tfaa_cache_option = get_option('sywat_tfaa_cache', array());
    $tfaa_data_bundle  = array(
        'exact'      => isset($tfaa_cache_option['exact']) ? $tfaa_cache_option['exact'] : array(),
        'fl'         => isset($tfaa_cache_option['fl']) ? $tfaa_cache_option['fl'] : array(),
        'updated_at' => isset($tfaa_cache_option['updated_at']) ? $tfaa_cache_option['updated_at'] : 'Never'
    );

    $existing_families = array();
    foreach ($registrations as $r) {
        if (isset($r['paid']) && $r['paid'] === 'Family' && !empty($r['family_id'])) {
            $fid = $r['family_id'];
            $fname = !empty($r['family_name']) ? $r['family_name'] : (!empty($r['booking_id']) ? 'Booking ' . $r['booking_id'] : 'Family Group (' . substr($fid, -6) . ')');
            $existing_families[$fid] = $fname;
        }
    }

    $divs = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)', 'Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)', 'No Show');
    $genders = array('Male', 'Female', 'No Show');
    $sywat_styles = array(
        'FS – Freestyle',
        'FS Pro – Freestyle Pro',
        'FSL – Freestyle Limited',
        'FSL-R – Freestyle Limited Recurve (or Olympic Recurve)',
        'BHFS – Bowhunter Freestyle',
        'MBH – Modern Bowhunter',
        'BBC – Barebow Compound',
        'BBR – Barebow Recurve',
        'TRAD – Traditional',
        'LB – Longbow',
        'Genesis',
        'No Show'
    );
    
    $legacy_div_map = array(
        'Cub'           => 'Cub (8 - 11)',
        'Youth'         => 'Youth (12 - 14)',
        'Young Adult'   => 'Young Adult (15 - 17)',
        'Adult'         => 'Adult (18 - 49)',
        'Senior'        => 'Senior (50 - 59)',
        'Silver Senior' => 'Silver Senior (60 - 69)',
        'Master Senior' => 'Master Senior (70+)'
    );

    $calculate_paid_status = function($pay_option, $division) {
        if ($pay_option === 'Family') return 'Family';
        if ($pay_option === 'Field Governor') return 'Field Governor';
        $youth_divs = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)');
        return in_array($division, $youth_divs) ? 'Youth' : 'Adult';
    };

    $sanitize_style_for_division = function($style, $division) {
        if ($division === 'No Show' || $style === 'No Show') return 'No Show';

        if (strpos($style, 'Genesis') !== false && $division !== 'Cub (8 - 11)') {
            return 'FS – Freestyle';
        }

        $is_pro_fsl_trad = (
            strpos($style, 'FS Pro') !== false ||
            strpos($style, 'FSL –') !== false ||
            strpos($style, 'TRAD') !== false ||
            stripos($style, 'Traditional') !== false
        );
        $allowed_adult_senior = array('Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)');

        if ($is_pro_fsl_trad && !in_array($division, $allowed_adult_senior)) {
            return 'FS – Freestyle';
        }

        $is_lb = (strpos($style, 'LB') !== false || stripos($style, 'Longbow') !== false);
        if ($is_lb && $division !== 'Adult (18 - 49)') {
            return 'FS – Freestyle';
        }

        return $style;
    };

    $added_family_prompt = null;

    // 3. Action Processors
    $is_valid_nonce = (isset($_POST['sywat_nonce']) && wp_verify_nonce($_POST['sywat_nonce'], 'sywat_desk_action'));
    $action = isset($_POST['sywat_action']) ? $_POST['sywat_action'] : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action && !$is_valid_nonce && $action !== 'cancel') {
        echo '<div class="sywat-alert sywat-alert-danger">Security token expired. Please refresh and try again.</div>';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce) {

        // --- Action: Admin Close Line ---
        if ($action === 'close_line' && $is_admin) {
            $target_line = sanitize_text_field($_POST['target_line_idx']);
            if (!in_array($target_line, $closed_lines)) {
                $closed_lines[] = $target_line;
                update_option($closed_lines_key, $closed_lines);

                $update_count = 0;
                foreach ($registrations as &$r) {
                    if (isset($r['tournament']) && $r['tournament'] === $selected_tournament && (string)$r['line_index'] === (string)$target_line) {
                        if (empty($r['checked_in'])) {
                            $r['division'] = 'No Show';
                            $r['gender']   = 'No Show';
                            $r['style']    = 'No Show';
                            
                            $opt_key = 'sywat_t_' . $active_t_slug . '_' . $r['id'];
                            update_option($opt_key, $r, false);
                            $update_count++;
                        }
                    }
                }
                unset($r);
                echo '<div class="sywat-alert sywat-alert-success"><strong>Line closed successfully.</strong> ' . $update_count . ' absent archer(s) marked as No Show.</div>';
            }
        }

        // --- Action: Admin Reopen Line ---
        if ($action === 'reopen_line' && $is_admin) {
            $target_line = sanitize_text_field($_POST['target_line_idx']);
            $closed_lines = array_diff($closed_lines, array($target_line));
            update_option($closed_lines_key, array_values($closed_lines));
            echo '<div class="sywat-alert sywat-alert-success"><strong>Line reopened successfully.</strong> Walk-ins and check-ins are active again.</div>';
        }

        // --- Action 1: Add Walk-In ---
        if ($action === 'add_walkin') {
            $new_id    = uniqid('reg_');
            $line_idx  = sanitize_text_field($_POST['walkin_line_index']);
            $line_name = isset($lines[$line_idx]['name']) ? $lines[$line_idx]['name'] : 'N/A';
            $division  = sanitize_text_field($_POST['walkin_division']);
            $distance  = ($division === 'Cub (8 - 11)') ? 'Cub' : '20yd';
            $pay_opt   = sanitize_text_field($_POST['walkin_pay_option']);
            $style     = sanitize_text_field($_POST['walkin_style']);
            $name_val  = sanitize_text_field($_POST['walkin_name']);

            $family_id   = '';
            $family_name = '';

            if ($pay_opt === 'Family') {
                $sel_fam_id  = isset($_POST['walkin_family_group_id']) ? sanitize_text_field($_POST['walkin_family_group_id']) : '';
                $typed_fam   = isset($_POST['walkin_new_family_name']) ? trim(sanitize_text_field($_POST['walkin_new_family_name'])) : '';

                if (!empty($sel_fam_id)) {
                    $family_id   = $sel_fam_id;
                    $family_name = isset($existing_families[$sel_fam_id]) ? $existing_families[$sel_fam_id] : 'Family Group';
                } elseif (!empty($typed_fam)) {
                    $family_id   = 'fam_' . sanitize_title($typed_fam) . '_' . substr(md5($typed_fam), 0, 4);
                    $family_name = $typed_fam . ' Family';
                } else {
                    $name_parts  = array_values(array_filter(explode(' ', $name_val)));
                    $last_part   = !empty($name_parts) ? end($name_parts) : 'Family';
                    $family_id   = 'fam_' . sanitize_title($last_part) . '_' . uniqid();
                    $family_name = $last_part . ' Family';
                }

                $added_family_prompt = array(
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
        }

        // --- Action 2: Process Pre-Reg Check-In ---
        if ($action === 'save_checkin') {
            $target_id = sanitize_text_field($_POST['reg_id']);
            $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
            $reg       = get_option($opt_key);
            
            if ($reg) {
                $due_amt  = isset($reg['balance_due']) ? floatval($reg['balance_due']) : 0;
                $verified = !empty($_POST['checkin_payment_verified']);

                if ($due_amt > 0 && !$verified) {
                    echo '<div class="sywat-alert sywat-alert-danger"><strong>⚠️ Check-In Blocked!</strong> Archer has an unpaid balance of $' . number_format($due_amt, 2) . '. Please see event staff for payment and check the verification box.</div>';
                } else {
                    $division = sanitize_text_field($_POST['checkin_division']);
                    $distance = ($division === 'Cub (8 - 11)' || $division === 'No Show') ? 'Cub' : '20yd';
                    $pay_opt  = sanitize_text_field($_POST['checkin_pay_option']);
                    $style    = sanitize_text_field($_POST['checkin_style']);
                    
                    if (!empty($_POST['checkin_name'])) {
                        $reg['name'] = sanitize_text_field($_POST['checkin_name']);
                    }

                    $reg['distance']    = $distance;
                    $reg['division']    = $division;
                    $reg['gender']      = sanitize_text_field($_POST['checkin_gender']);
                    $reg['style']       = $sanitize_style_for_division($style, $division);
                    $reg['paid']        = $calculate_paid_status($pay_opt, $division);
                    $reg['checked_in']  = 1;
                    $reg['balance_due'] = 0;
                    
                    update_option($opt_key, $reg, false);

                    foreach ($registrations as &$r) {
                        if ($r['id'] === $target_id) { $r = $reg; break; }
                    }
                    unset($r);
                }
            }
        }

        // --- Action 3: Undo Check-In ---
        if ($action === 'undo_checkin') {
            $target_id = sanitize_text_field($_POST['reg_id']);
            $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
            $reg       = get_option($opt_key);
            
            if ($reg) {
                $reg['checked_in'] = 0;
                update_option($opt_key, $reg, false);

                foreach ($registrations as &$r) {
                    if ($r['id'] === $target_id) { $r = $reg; break; }
                }
                unset($r);
            }
        }

        // --- Action 4: Save Edit (Full Record) ---
        if ($action === 'save_edit') {
            $target_id = sanitize_text_field($_POST['reg_id']);
            $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
            $reg       = get_option($opt_key);
            
            if ($reg) {
                $line_idx  = sanitize_text_field($_POST['edit_line_index']);
                $line_name = isset($lines[$line_idx]['name']) ? $lines[$line_idx]['name'] : 'N/A';
                $division  = sanitize_text_field($_POST['edit_division']);
                $distance  = ($division === 'Cub (8 - 11)' || $division === 'No Show') ? 'Cub' : '20yd';
                $pay_opt   = sanitize_text_field($_POST['edit_pay_option']);
                $style     = sanitize_text_field($_POST['edit_style']);

                $reg['name']        = sanitize_text_field($_POST['edit_name']);
                $reg['line_index']  = $line_idx;
                $reg['line_name']   = $line_name;
                $reg['distance']    = $distance;
                $reg['division']    = $division;
                $reg['gender']      = sanitize_text_field($_POST['edit_gender']);
                $reg['style']       = $sanitize_style_for_division($style, $division);
                $reg['paid']        = $calculate_paid_status($pay_opt, $division);
                $reg['balance_due'] = isset($_POST['edit_balance_due']) ? floatval($_POST['edit_balance_due']) : 0;
                
                if ($reg['paid'] === 'Family') {
                    if (!empty($_POST['edit_family_group_id'])) {
                        $reg['family_id']   = sanitize_text_field($_POST['edit_family_group_id']);
                        $reg['family_name'] = isset($existing_families[$reg['family_id']]) ? $existing_families[$reg['family_id']] : 'Family Group';
                    } elseif (!empty($_POST['edit_new_family_name'])) {
                        $typed_f = trim(sanitize_text_field($_POST['edit_new_family_name']));
                        $reg['family_id']   = 'fam_' . sanitize_title($typed_f) . '_' . substr(md5($typed_f), 0, 4);
                        $reg['family_name'] = $typed_f . ' Family';
                    } elseif (empty($reg['family_id'])) {
                        $reg['family_id']   = uniqid('fam_');
                    }
                } else {
                    unset($reg['family_id']);
                    unset($reg['family_name']);
                }

                update_option($opt_key, $reg, false);

                foreach ($registrations as &$r) {
                    if ($r['id'] === $target_id) { $r = $reg; break; }
                }
                unset($r);
            }
        }

        // --- Action 5: Delete Archer ---
        if ($action === 'delete_archer') {
            $target_id = sanitize_text_field($_POST['reg_id']);
            $opt_key   = 'sywat_t_' . $active_t_slug . '_' . $target_id;
            
            delete_option($opt_key);
            
            $registrations = array_filter($registrations, function($r) use ($target_id) {
                return $r['id'] !== $target_id;
            });
        }
    }

    $tourn_registrations = array();
    foreach ($registrations as $reg) {
        $match_tourn = isset($reg['tournament']) && $reg['tournament'] === $selected_tournament;
        $match_line  = ($selected_line_idx === 'all') || (isset($reg['line_index']) && (string)$reg['line_index'] === (string)$selected_line_idx);
        
        if ($match_tourn && $match_line) {
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

    $modal_target_id = isset($_POST['open_checkin_id']) ? sanitize_text_field($_POST['open_checkin_id']) : '';
    $modal_edit_id   = isset($_POST['open_edit_id']) ? sanitize_text_field($_POST['open_edit_id']) : '';
    
    $modal_archer    = null;
    $modal_edit_archer = null;
    
    if ($modal_target_id || $modal_edit_id) {
        $search_id = $modal_target_id ? $modal_target_id : $modal_edit_id;
        foreach ($registrations as $reg) {
            if (isset($reg['id']) && $reg['id'] === $search_id) {
                if ($modal_target_id) $modal_archer = $reg;
                if ($modal_edit_id) $modal_edit_archer = $reg;
                break;
            }
        }
    }
    ?>

    <style>
        .sywat-badge-verified { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 6px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; display: block; margin-top: 4px; }
        .sywat-badge-known { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 6px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; display: block; margin-top: 4px; }
        .sywat-badge-none { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 6px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; display: block; margin-top: 4px; }
        .sywat-pill-box { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .sywat-btn-pill { background: #0284c7; color: #ffffff; border: none; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; cursor: pointer; }
        .sywat-btn-pill:hover { background: #0369a1; }
        .sywat-btn-use-official { background: #0d9488; color: #ffffff; border: none; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; cursor: pointer; margin-left: 6px; }
        .sywat-btn-use-official:hover { background: #0f766e; }
        .sywat-multi-style-alert { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 10px; border-radius: 6px; font-size: 11px; margin-bottom: 6px; width: 100%; box-sizing: border-box; }
        .sywat-multi-style-title { font-weight: bold; font-size: 12px; margin-bottom: 6px; display: flex; align-items: center; gap: 4px; color: #856404; }
    </style>

    <div class="sywat-wrap">
        <!-- SEQUENTIAL FAMILY WALK-IN PROMPT BANNER -->
        <?php if ($added_family_prompt): ?>
            <div class="no-print" style="background: #e0f2fe; border: 2px solid #0284c7; border-radius: 8px; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <div style="font-size: 15px; font-weight: bold; color: #0369a1; margin-bottom: 6px;">
                    ✅ <?php echo esc_html($added_family_prompt['archer_name']); ?> added under <?php echo esc_html($added_family_prompt['family_name']); ?>!
                </div>
                <div style="font-size: 13px; color: #334155; margin-bottom: 12px;">
                    Is there another family member to register under this same payment group?
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="sywat-btn-success" onclick="continueFamilyLoop('<?php echo esc_js($added_family_prompt['family_id']); ?>', '<?php echo esc_js($added_family_prompt['line_idx']); ?>')">
                        + Yes, Add Next Family Member
                    </button>
                    <button type="button" class="sywat-btn-secondary" onclick="this.parentElement.parentElement.remove();">
                        Done / Finish Family
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" id="sywat_filter_form" class="no-print sywat-card" style="display:flex; gap:15px; align-items:center; flex-wrap:wrap; padding:15px; margin-bottom:20px;">
            <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
            <div>
                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Tournament:</label>
                <select name="selected_tournament" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; height:32px;" onchange="document.getElementById('sywat_filter_form').submit();">
                    <?php foreach ($tournaments as $t_name => $t_lines): ?>
                        <option value="<?php echo esc_attr($t_name); ?>" <?php selected($selected_tournament, $t_name); ?>>
                            <?php echo esc_html($t_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Shooting Line:</label>
                <select name="selected_line_idx" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; height:32px;" onchange="document.getElementById('sywat_filter_form').submit();">
                    <option value="all" <?php selected($selected_line_idx, 'all'); ?>>All Lines</option>
                    <?php foreach ($lines as $idx => $line): ?>
                        <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$selected_line_idx, (string)$idx); ?>>
                            <?php echo esc_html($line['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-left:auto;">
                <a href="/sywat-volunteer-hub/" class="sywat-btn-secondary">← Back to Volunteer Hub</a>
            </div>
        </form>

        <!-- LINE CAPACITY TRACKER -->
        <div class="sywat-card no-print">
            <div class="sywat-card-title">
                <span>Line Capacity Tracker</span>
            </div>
            <?php if (!empty($lines)): ?>
                <?php
                    $ordered_lines = array();
                    foreach ($lines as $idx => $line) {
                        $line_title = isset($line['name']) ? $line['name'] : 'Line ' . ($idx + 1);
                        $weight = 50;

                        if (stripos($line_title, 'Sat') !== false) {
                            if (stripos($line_title, '10') !== false) $weight = 10;
                            elseif (stripos($line_title, '2') !== false) $weight = 20;
                            else $weight = 25;
                        } elseif (stripos($line_title, 'Sun') !== false) {
                            if (stripos($line_title, '10') !== false) $weight = 30;
                            elseif (stripos($line_title, '2') !== false) $weight = 40;
                            else $weight = 45;
                        }

                        $ordered_lines[] = array('idx' => $idx, 'line' => $line, 'weight' => $weight);
                    }

                    usort($ordered_lines, function($a, $b) {
                        if ($a['weight'] !== $b['weight']) {
                            return $a['weight'] <=> $b['weight'];
                        }
                        return $a['idx'] <=> $b['idx'];
                    });
                ?>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                    <?php foreach ($ordered_lines as $item): ?>
                        <?php
                            $idx  = $item['idx'];
                            $line = $item['line'];
                            
                            $booked_20yd = 0;
                            $booked_cub  = 0;
                            $total_reg_line = 0;
                            $checked_in_line = 0;

                            foreach ($registrations as $reg) {
                                if (isset($reg['tournament']) && $reg['tournament'] === $selected_tournament) {
                                    $match_index = isset($reg['line_index']) && (string)$reg['line_index'] === (string)$idx;
                                    $match_name  = isset($reg['line_name']) && isset($line['name']) && trim($reg['line_name']) === trim($line['name']);

                                    if ($match_index || $match_name) {
                                        $total_reg_line++;
                                        if (!empty($reg['checked_in'])) $checked_in_line++;
                                        if (isset($reg['distance']) && $reg['distance'] === '20yd') $booked_20yd++;
                                        if (isset($reg['distance']) && $reg['distance'] === 'Cub') $booked_cub++;
                                    }
                                }
                            }

                            $cap_20yd_num = max(0, intval(isset($line['cap_20yd']) ? $line['cap_20yd'] : 0) - $booked_20yd);
                            $cap_cub_num  = max(0, intval(isset($line['cap_cub']) ? $line['cap_cub'] : 0) - $booked_cub);

                            $disp_20yd = ($cap_20yd_num === 0) ? '<span style="color:#dc2626; font-weight:bold;">FULL</span>' : $cap_20yd_num . ' Open';
                            $disp_cub  = ($cap_cub_num === 0) ? '<span style="color:#dc2626; font-weight:bold;">FULL</span>' : $cap_cub_num . ' Open';
                            
                            $is_closed_line = in_array((string)$idx, $closed_lines);
                            $closed_badge = $is_closed_line ? '<span style="color:#dc2626; font-size:11px; font-weight:bold; margin-left:8px;">[CLOSED]</span>' : '';
                        ?>
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                                <strong style="color:#0f172a; font-size:14px;"><?php echo esc_html($line['name']) . $closed_badge; ?></strong>
                                <span style="font-size:11px; background:#0073aa; color:#fff; padding:2px 6px; border-radius:12px; font-weight:bold; white-space:nowrap;">
                                    <?php echo $checked_in_line . ' / ' . $total_reg_line . ' Checked In'; ?>
                                </span>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:13px; color:#475569;">
                                <span><strong>20yd:</strong> <?php echo $disp_20yd; ?></span>
                                <span><strong>Cub:</strong> <?php echo $disp_cub; ?></span>
                            </div>
                            
                            <?php if ($is_admin): ?>
                                <div style="margin-top:10px; border-top:1px dashed #cbd5e1; padding-top:8px; display:flex; justify-content:flex-end;">
                                    <?php if ($is_closed_line): ?>
                                        <form method="post" style="margin:0;">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_action" value="reopen_line">
                                            <input type="hidden" name="target_line_idx" value="<?php echo esc_attr($idx); ?>">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                            <button type="submit" class="sywat-btn-secondary" style="font-size:11px; height:26px; padding:2px 8px;">Reopen Line</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="margin:0;" onsubmit="return confirm('Are you sure you want to close this line? All un-checked-in archers will be permanently marked as No Show.');">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_action" value="close_line">
                                            <input type="hidden" name="target_line_idx" value="<?php echo esc_attr($idx); ?>">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                            <button type="submit" class="sywat-btn-danger" style="font-size:11px; height:26px; padding:2px 8px;">Close Line</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color:#64748b; font-style:italic;">No shooting lines configured.</p>
            <?php endif; ?>
        </div>

        <!-- REGISTERED ARCHERS TABLE -->
        <div class="sywat-card no-print">
            <div class="sywat-card-title">
                <span>Registered Archers</span>
                <div style="display:flex; gap:8px; align-items:center;">
                    <div style="display:flex; gap:5px; margin:0; align-items:center;">
                        <input type="text" id="sywat_search_input" value="<?php echo esc_attr($search_query); ?>" placeholder="Search name..." autocomplete="off" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:180px; height:32px; box-sizing:border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                        <button type="button" class="sywat-btn-secondary" style="height:32px;" onclick="document.getElementById('sywat_search_input').value=''; document.getElementById('sywat_search_input').dispatchEvent(new Event('input'));">Clear</button>
                    </div>
                    <button type="button" class="sywat-btn-success" style="height:32px;" onclick="openWalkinModal()">+ Add Walk-In</button>
                </div>
            </div>

            <?php if (!empty($tourn_registrations)): ?>
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Name</th>
                            <th style="text-align:center;">Type</th>
                            <th style="text-align:left;">Line</th>
                            <th style="text-align:left;">Division</th>
                            <th style="text-align:center;">Gender</th>
                            <th style="text-align:left;">Style</th>
                            <th style="text-align:left;">Paid Status</th>
                            <th style="text-align:center;">Distance</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tourn_registrations as $reg): ?>
                            <?php 
                                $reg_type   = isset($reg['type']) && $reg['type'] === 'Walk-In' ? 'Walk-In' : 'Pre-Reg';
                                $badge_class= ($reg_type === 'Walk-In') ? 'sywat-badge-walkin' : 'sywat-badge-prereg';
                                $balance_due= isset($reg['balance_due']) ? floatval($reg['balance_due']) : 0;
                                $paid_disp  = (!empty($reg['checked_in']) && !empty($reg['paid'])) ? $reg['paid'] : '—';
                                $div_disp   = !empty($reg['division']) ? $reg['division'] : '—';
                                $style_disp = !empty($reg['style']) ? $reg['style'] : '—';
                            ?>
                            <tr class="sywat-archer-row" data-name="<?php echo esc_attr(strtolower($reg['name'])); ?>">
                                <td>
                                    <strong><?php echo esc_html($reg['name']); ?></strong>
                                    <?php if ($balance_due > 0): ?>
                                        <span style="font-size:11px; background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; padding:2px 6px; border-radius:10px; font-weight:bold; margin-left:4px;">
                                            ⚠️ OWE $<?php echo number_format($balance_due, 2); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <span class="sywat-badge <?php echo $badge_class; ?>"><?php echo esc_html($reg_type); ?></span>
                                </td>
                                <td><?php echo esc_html(isset($reg['line_name']) ? $reg['line_name'] : 'N/A'); ?></td>
                                <td><?php echo esc_html($div_disp); ?></td>
                                <td style="text-align:center;"><?php echo esc_html(isset($reg['gender']) ? $reg['gender'] : 'N/A'); ?></td>
                                <td><?php echo esc_html($style_disp); ?></td>
                                <td style="font-weight:bold; color:#0073aa;"><?php echo esc_html($paid_disp); ?></td>
                                <td style="text-align:center;"><?php echo esc_html(isset($reg['distance']) ? $reg['distance'] : 'N/A'); ?></td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; gap:4px; align-items:center;">
                                        <form method="post" style="margin:0;">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                            <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                                            <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                                            <input type="hidden" name="open_edit_id" value="<?php echo esc_attr($reg['id']); ?>">
                                            <button type="submit" class="sywat-btn-primary" style="height:28px; padding:2px 8px; font-size:12px;">✏️ Edit</button>
                                        </form>

                                        <?php if (!empty($reg['checked_in'])): ?>
                                            <form method="post" style="margin:0;">
                                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                                <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                                                <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                                                <input type="hidden" name="sywat_action" value="undo_checkin">
                                                <input type="hidden" name="reg_id" value="<?php echo esc_attr($reg['id']); ?>">
                                                <button type="submit" class="sywat-btn-checkedin" style="height:28px; padding:2px 8px; font-size:12px;">Checked In ✓</button>
                                            </form>
                                        <?php else: ?>
                                            <?php if (in_array((string)$reg['line_index'], $closed_lines)): ?>
                                                <span style="font-size:11px; color:#dc2626; font-weight:bold; padding:4px 8px;">Line Closed</span>
                                            <?php else: ?>
                                                <form method="post" style="margin:0;">
                                                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                                                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                                                    <input type="hidden" name="open_checkin_id" value="<?php echo esc_attr($reg['id']); ?>">
                                                    <button type="submit" class="sywat-btn-success" style="height:28px; padding:2px 8px; font-size:12px;">Check In</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color:#666;">No archers registered for this selection.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- CHECK-IN MODAL (PRE-REG) -->
    <?php if ($modal_archer): ?>
    <?php $modal_due = isset($modal_archer['balance_due']) ? floatval($modal_archer['balance_due']) : 0; ?>
    <div class="sywat-modal-backdrop active">
        <div class="sywat-modal-content" style="max-width:480px;">
            <div class="sywat-modal-header no-print">
                <h3>Check In Archer</h3>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <button type="submit" name="sywat_action" value="cancel" class="sywat-modal-close" formnovalidate>&times;</button>
                </form>
            </div>
            <div class="sywat-modal-body">
                <form method="post">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                    <input type="hidden" name="reg_id" value="<?php echo esc_attr($modal_archer['id']); ?>">

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Name:</label>
                        <input type="text" name="checkin_name" id="checkin_archer_name" value="<?php echo esc_attr($modal_archer['name']); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-weight:bold;">
                        <div id="checkin_tfaa_badge"></div>
                        <div id="checkin_tfaa_pills" class="sywat-pill-box"></div>
                    </div>

                    <?php if ($modal_due > 0): ?>
                        <div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:6px; border:1px solid #f5c6cb; margin-bottom:15px; text-align:center;">
                            <div style="font-size:16px; font-weight:bold;">⚠️ UNPAID BALANCE: $<?php echo number_format($modal_due, 2); ?></div>
                            <div style="font-size:13px; margin-top:4px; font-weight:600;">Please See Event Staff for Payment</div>
                        </div>

                        <div style="margin-bottom:15px;">
                            <label style="color:#721c24; font-weight:bold; cursor:pointer; display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" name="checkin_payment_verified" value="1" required style="width:auto;">
                                Payment / Resolution Verified with Event Staff
                            </label>
                        </div>
                    <?php endif; ?>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Division:</label>
                        <select name="checkin_division" id="checkin_division" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required onchange="handleStyleRestrictions('checkin'); evaluateTfaaStatus('checkin');">
                            <?php
                            $curr_div = isset($modal_archer['division']) ? $modal_archer['division'] : '';
                            if (isset($legacy_div_map[$curr_div])) $curr_div = $legacy_div_map[$curr_div];

                            echo '<option value="" disabled ' . (empty($curr_div) ? 'selected' : '') . ' hidden>Select Division</option>';
                            foreach ($divs as $d) {
                                if ($d === 'No Show' && $curr_div !== 'No Show') continue;
                                echo '<option value="' . esc_attr($d) . '" ' . selected($curr_div, $d, false) . '>' . esc_html($d) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Gender:</label>
                        <select name="checkin_gender" id="checkin_gender" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required onchange="evaluateTfaaStatus('checkin')">
                            <?php
                            $curr_gen = isset($modal_archer['gender']) && in_array($modal_archer['gender'], $genders) ? $modal_archer['gender'] : '';
                            echo '<option value="" disabled ' . (empty($curr_gen) ? 'selected' : '') . ' hidden>Select Gender</option>';
                            foreach ($genders as $g) {
                                if ($g === 'No Show' && $curr_gen !== 'No Show') continue;
                                echo '<option value="' . esc_attr($g) . '" ' . selected($curr_gen, $g, false) . '>' . esc_html($g) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Style:</label>
                        <select name="checkin_style" id="checkin_style" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required onchange="evaluateTfaaStatus('checkin')">
                            <?php
                            $curr_sty = isset($modal_archer['style']) && in_array($modal_archer['style'], $sywat_styles) ? $modal_archer['style'] : '';
                            echo '<option value="" disabled ' . (empty($curr_sty) ? 'selected' : '') . ' hidden>Select Style</option>';
                            foreach ($sywat_styles as $s) {
                                if ($s === 'No Show' && $curr_sty !== 'No Show') continue;
                                echo '<option value="' . esc_attr($s) . '" ' . selected($curr_sty, $s, false) . '>' . esc_html($s) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Paid Option:</label>
                        <select name="checkin_pay_option" id="checkin_pay_option" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                            <?php
                            $curr_paid = isset($modal_archer['paid']) ? $modal_archer['paid'] : '';
                            $curr_pay_opt = ($curr_paid === 'Family') ? 'Family' : (($curr_paid === 'Field Governor') ? 'Field Governor' : 'Individual');
                            ?>
                            <option value="Individual" <?php selected($curr_pay_opt, 'Individual'); ?>>Individual</option>
                            <option value="Family" <?php selected($curr_pay_opt, 'Family'); ?>>Family</option>
                            <option value="Field Governor" <?php selected($curr_pay_opt, 'Field Governor'); ?>>Field Governor</option>
                        </select>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                        <button type="submit" name="sywat_action" value="cancel" class="sywat-btn-secondary" formnovalidate>Cancel</button>
                        <button type="submit" name="sywat_action" value="save_checkin" class="sywat-btn-success">Complete Check-In</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- EDIT ARCHER MODAL -->
    <?php if ($modal_edit_archer): ?>
    <div class="sywat-modal-backdrop active">
        <div class="sywat-modal-content" style="max-width:480px;">
            <div class="sywat-modal-header no-print">
                <h3>Edit Archer Registration</h3>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <button type="submit" name="sywat_action" value="cancel" class="sywat-modal-close" formnovalidate>&times;</button>
                </form>
            </div>
            <div class="sywat-modal-body">
                <form method="post">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                    <input type="hidden" name="reg_id" value="<?php echo esc_attr($modal_edit_archer['id']); ?>">

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Full Name:</label>
                        <input type="text" name="edit_name" value="<?php echo esc_attr($modal_edit_archer['name']); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Shooting Line:</label>
                        <select name="edit_line_index" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                            <?php $curr_line = isset($modal_edit_archer['line_index']) ? $modal_edit_archer['line_index'] : ''; ?>
                            <?php foreach ($lines as $idx => $line): ?>
                                <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$curr_line, (string)$idx); ?>>
                                    <?php echo esc_html($line['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Division:</label>
                        <select name="edit_division" id="edit_division" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required onchange="handleStyleRestrictions('edit');">
                            <?php
                            $raw_div = isset($modal_edit_archer['division']) ? $modal_edit_archer['division'] : '';
                            if (isset($legacy_div_map[$raw_div])) $raw_div = $legacy_div_map[$raw_div];
                            
                            $curr_div = in_array($raw_div, $divs) ? $raw_div : '';
                            
                            echo '<option value="" disabled ' . (empty($curr_div) ? 'selected' : '') . ' hidden>Select Division</option>';
                            foreach ($divs as $d) {
                                if ($d === 'No Show' && $curr_div !== 'No Show') continue;
                                echo '<option value="' . esc_attr($d) . '" ' . selected($curr_div, $d, false) . '>' . esc_html($d) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Gender:</label>
                        <select name="edit_gender" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                            <?php
                            $curr_gen = isset($modal_edit_archer['gender']) && in_array($modal_edit_archer['gender'], $genders) ? $modal_edit_archer['gender'] : '';
                            echo '<option value="" disabled ' . (empty($curr_gen) ? 'selected' : '') . ' hidden>Select Gender</option>';
                            foreach ($genders as $g) {
                                if ($g === 'No Show' && $curr_gen !== 'No Show') continue;
                                echo '<option value="' . esc_attr($g) . '" ' . selected($curr_gen, $g, false) . '>' . esc_html($g) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Style:</label>
                        <select name="edit_style" id="edit_style" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                            <?php
                            $curr_sty = isset($modal_edit_archer['style']) && in_array($modal_edit_archer['style'], $sywat_styles) ? $modal_edit_archer['style'] : '';
                            echo '<option value="" disabled ' . (empty($curr_sty) ? 'selected' : '') . ' hidden>Select Style</option>';
                            foreach ($sywat_styles as $s) {
                                if ($s === 'No Show' && $curr_sty !== 'No Show') continue;
                                echo '<option value="' . esc_attr($s) . '" ' . selected($curr_sty, $s, false) . '>' . esc_html($s) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Paid Option:</label>
                        <select name="edit_pay_option" id="edit_pay_option" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required onchange="toggleFamilyBox('edit')">
                            <?php
                            $curr_paid = isset($modal_edit_archer['paid']) ? $modal_edit_archer['paid'] : '';
                            $curr_pay_opt = ($curr_paid === 'Family') ? 'Family' : (($curr_paid === 'Field Governor') ? 'Field Governor' : 'Individual');
                            ?>
                            <option value="Individual" <?php selected($curr_pay_opt, 'Individual'); ?>>Individual</option>
                            <option value="Family" <?php selected($curr_pay_opt, 'Family'); ?>>Family</option>
                            <option value="Field Governor" <?php selected($curr_pay_opt, 'Field Governor'); ?>>Field Governor</option>
                        </select>
                    </div>

                    <div style="margin-bottom:12px; <?php echo ($curr_pay_opt === 'Family') ? '' : 'display:none;'; ?>" id="edit_family_box">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Family Group Reference:</label>
                        <select name="edit_family_group_id" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                            <option value="">[ + Create New Family Group ]</option>
                            <?php
                            $curr_fam_id = isset($modal_edit_archer['family_id']) ? $modal_edit_archer['family_id'] : '';
                            foreach ($existing_families as $fid => $flabel) {
                                echo '<option value="' . esc_attr($fid) . '" ' . selected($curr_fam_id, $fid, false) . '>' . esc_html($flabel) . '</option>';
                            }
                            ?>
                        </select>
                        <input type="text" name="edit_new_family_name" placeholder="Or type new family name..." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; margin-top:5px;">
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Balance Due ($):</label>
                        <input type="number" step="0.01" min="0" name="edit_balance_due" value="<?php echo esc_attr(isset($modal_edit_archer['balance_due']) ? $modal_edit_archer['balance_due'] : 0); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px;">
                        <button type="submit" name="sywat_action" value="delete_archer" class="sywat-btn-danger" onclick="return confirm('⚠️ Are you sure you want to PERMANENTLY delete this archer?');" formnovalidate>🗑️ Delete</button>
                        
                        <div style="display:flex; gap:10px;">
                            <button type="submit" name="sywat_action" value="cancel" class="sywat-btn-secondary" formnovalidate>Cancel</button>
                            <button type="submit" name="sywat_action" value="save_edit" class="sywat-btn-primary">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ADD WALK-IN MODAL -->
    <div id="walkinModal" class="sywat-modal-backdrop">
        <div class="sywat-modal-content" style="max-width:480px;">
            <div class="sywat-modal-header no-print">
                <h3>Add Walk-In Registration</h3>
                <button type="button" class="sywat-modal-close" onclick="closeWalkinModal()">&times;</button>
            </div>
            <div class="sywat-modal-body">
                <form method="post">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Full Name:</label>
                        <input type="text" name="walkin_name" id="walkin_name_input" required placeholder="Type archer name..." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        <div id="walkin_tfaa_badge"></div>
                        <div id="walkin_tfaa_pills" class="sywat-pill-box"></div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Shooting Line:</label>
                        <select name="walkin_line_index" id="walkin_line_index" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                            <?php foreach ($lines as $idx => $line): ?>
                                <?php $is_c = in_array((string)$idx, $closed_lines); ?>
                                <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$selected_line_idx, (string)$idx); ?><?php echo $is_c ? ' disabled' : ''; ?>>
                                    <?php echo esc_html($line['name']) . ($is_c ? ' (Closed)' : ''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Division:</label>
                        <select name="walkin_division" id="walkin_division" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required onchange="handleStyleRestrictions('walkin'); evaluateTfaaStatus('walkin');">
                            <option value="" disabled selected hidden>Select Division</option>
                            <?php foreach ($divs as $d): ?>
                                <?php if ($d === 'No Show') continue; ?>
                                <option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Gender:</label>
                        <select name="walkin_gender" id="walkin_gender" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required onchange="evaluateTfaaStatus('walkin')">
                            <option value="" disabled selected hidden>Select Gender</option>
                            <?php foreach ($genders as $g): ?>
                                <?php if ($g === 'No Show') continue; ?>
                                <option value="<?php echo esc_attr($g); ?>"><?php echo esc_html($g); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Style:</label>
                        <select name="walkin_style" id="walkin_style" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required onchange="evaluateTfaaStatus('walkin')">
                            <option value="" disabled selected hidden>Select Style</option>
                            <?php foreach ($sywat_styles as $s): ?>
                                <?php if ($s === 'No Show') continue; ?>
                                <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Paid Option:</label>
                        <select name="walkin_pay_option" id="walkin_pay_option" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required onchange="toggleFamilyBox('walkin')">
                            <option value="Individual">Individual</option>
                            <option value="Family">Family</option>
                            <option value="Field Governor">Field Governor</option>
                        </select>
                    </div>

                    <div style="margin-bottom:12px; display:none;" id="walkin_family_box">
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Family Group Reference:</label>
                        <select name="walkin_family_group_id" id="walkin_family_group_id" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                            <option value="">[ + Start New Family Group ]</option>
                            <?php foreach ($existing_families as $fid => $flabel): ?>
                                <option value="<?php echo esc_attr($fid); ?>"><?php echo esc_html($flabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="walkin_new_family_name" id="walkin_new_family_name" placeholder="Or type new family name (e.g. Smith Family)..." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; margin-top:5px;">
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                        <button type="button" class="sywat-btn-secondary" onclick="closeWalkinModal()">Cancel</button>
                        <button type="submit" name="sywat_action" value="add_walkin" class="sywat-btn-primary">Register & Check In</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- CLIENT SIDE TFAA VERIFICATION & AUTO-FILL -->
    <script>
    const sywatHistory = <?php echo json_encode($history_profiles); ?>;
    const tfaaData     = <?php echo json_encode($tfaa_data_bundle); ?>;

    function findTfaaMatch(typedName) {
        if (!typedName || !tfaaData || !tfaaData.exact) return null;
        const clean = typedName.trim().toLowerCase().replace(/\s+/g, ' ');
        if (!clean) return null;

        if (tfaaData.exact[clean]) return tfaaData.exact[clean];

        const parts = clean.split(' ');
        if (parts.length > 1) {
            const flKey = parts[0] + '___' + parts[parts.length - 1];
            if (tfaaData.fl && tfaaData.fl[flKey]) return tfaaData.fl[flKey];
        }
        return null;
    }

    function findShopHistoryMatch(typedName) {
        if (!typedName || !sywatHistory || !sywatHistory.exact) return null;
        const clean = typedName.trim().toLowerCase().replace(/\s+/g, ' ');
        if (!clean) return null;

        if (sywatHistory.exact[clean]) return sywatHistory.exact[clean];

        const parts = clean.split(' ');
        if (parts.length > 1) {
            const flKey = parts[0] + '___' + parts[parts.length - 1];
            if (sywatHistory.fl && sywatHistory.fl[flKey]) return sywatHistory.fl[flKey];
        }
        return null;
    }

    function loadTfaaCategory(prefix, divVal, genVal, styleVal) {
        const divEl   = document.getElementById(prefix + '_division');
        const genEl   = document.getElementById(prefix + '_gender');
        const styleEl = document.getElementById(prefix + '_style');

        if (divEl && divVal) divEl.value = divVal;
        if (genEl && genVal) genEl.value = genVal;
        if (styleEl && styleVal) styleEl.value = styleVal;

        handleStyleRestrictions(prefix);
        evaluateTfaaStatus(prefix);
    }

    function updateNameToOfficial(prefix, officialName) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        if (nameInput && officialName) {
            nameInput.value = officialName;
            evaluateTfaaStatus(prefix);
        }
    }

    function evaluateTfaaStatus(prefix) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        const badgeEl   = document.getElementById(prefix + '_tfaa_badge');
        const pillsEl   = document.getElementById(prefix + '_tfaa_pills');

        if (!nameInput || !badgeEl || !pillsEl) return;

        const typedName = nameInput.value;
        const tfaaMatches = findTfaaMatch(typedName);

        pillsEl.innerHTML = '';

        if (!tfaaMatches || !Array.isArray(tfaaMatches) || tfaaMatches.length === 0) {
            badgeEl.className = 'sywat-badge-none';
            badgeEl.innerHTML = '⚪ No TFAA History (Current Season)';
            return;
        }

        const currDiv   = document.getElementById(prefix + '_division')?.value || '';
        const currGen   = document.getElementById(prefix + '_gender')?.value || '';
        const currStyle = document.getElementById(prefix + '_style')?.value || '';

        let isExactMatch = false;
        let knownStyles = [];

        // Build the buttons array first
        let buttonContainer = document.createElement('div');
        buttonContainer.style.cssText = 'display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;';

        tfaaMatches.forEach(cat => {
            if (cat.style) {
                let shortSty = cat.style.split(' – ')[0];
                if (!knownStyles.includes(shortSty)) knownStyles.push(shortSty);
            }
            if (cat.division === currDiv && cat.gender === currGen && cat.style === currStyle) {
                isExactMatch = true;
            }

            const divShort = cat.division ? cat.division.split(' ')[0] : '';
            const genShort = cat.gender ? cat.gender.charAt(0) : '';
            const styShort = cat.style ? cat.style.split(' – ')[0] : '';

            const pillBtn = document.createElement('button');
            pillBtn.type = 'button';
            pillBtn.className = 'sywat-btn-pill';
            pillBtn.innerHTML = '⚡ Load: <strong>' + styShort + '</strong> (' + divShort + ' ' + genShort + ')';
            pillBtn.onclick = function() {
                loadTfaaCategory(prefix, cat.division, cat.gender, cat.style);
            };
            buttonContainer.appendChild(pillBtn);
        });

        // Group the title AND buttons inside the yellow banner if multiple styles exist
        if (tfaaMatches.length > 1) {
            const alertBox = document.createElement('div');
            alertBox.className = 'sywat-multi-style-alert';
            
            const alertTitle = document.createElement('div');
            alertTitle.className = 'sywat-multi-style-title';
            alertTitle.innerHTML = '⚠️ <strong>Archer has multiple TFAA styles on file. Click a style to load:</strong>';
            
            alertBox.appendChild(alertTitle);
            alertBox.appendChild(buttonContainer);
            pillsEl.appendChild(alertBox);
        } else {
            // Single style - render buttons directly
            pillsEl.appendChild(buttonContainer);
        }

        let officialName = tfaaMatches[0].full_name || '';
        let cleanTyped = typedName.trim().toLowerCase().replace(/\s+/g, ' ');
        let cleanOfficial = officialName.trim().toLowerCase().replace(/\s+/g, ' ');

        let badgeText = '';
        if (isExactMatch) {
            badgeEl.className = 'sywat-badge-verified';
            badgeText = '🟢 TFAA Validated: ' + knownStyles.join(', ');
        } else {
            badgeEl.className = 'sywat-badge-known';
            badgeText = '⚠️ TFAA Style Mismatch (TFAA Lists: ' + knownStyles.join(', ') + ')';
        }

        if (cleanTyped !== cleanOfficial && officialName !== '') {
            badgeText += '<br><span style="font-size:11px; font-weight:normal; display:inline-block; margin-top:4px;">↳ Official TFAA Name: <strong>' + officialName + '</strong></span>';
            badgeText += '<button type="button" class="sywat-btn-use-official" onclick="updateNameToOfficial(\'' + prefix + '\', \'' + officialName.replace(/'/g, "\\'") + '\')">✏️ Use Official Name</button>';
        }

        badgeEl.innerHTML = badgeText;
    }

    function autoFillOnNameChange(prefix) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        if (!nameInput) return;

        const typedName = nameInput.value;
        const tfaaMatches = findTfaaMatch(typedName);

        if (prefix === 'walkin') {
            if (tfaaMatches && tfaaMatches.length > 0) {
                const firstCat = tfaaMatches[0];
                loadTfaaCategory(prefix, firstCat.division, firstCat.gender, firstCat.style);
            } else {
                const shopMatch = findShopHistoryMatch(typedName);
                if (shopMatch) {
                    const divEl   = document.getElementById(prefix + '_division');
                    const genEl   = document.getElementById(prefix + '_gender');
                    const styleEl = document.getElementById(prefix + '_style');
                    const payEl   = document.getElementById(prefix + '_pay_option');

                    if (divEl && shopMatch.division) divEl.value = shopMatch.division;
                    if (genEl && shopMatch.gender) genEl.value = shopMatch.gender;
                    if (styleEl && shopMatch.style) styleEl.value = shopMatch.style;
                    if (payEl && shopMatch.paid) payEl.value = shopMatch.paid;

                    handleStyleRestrictions(prefix);
                } else {
                    const divEl   = document.getElementById('walkin_division');
                    const genEl   = document.getElementById('walkin_gender');
                    const styleEl = document.getElementById('walkin_style');
                    if (divEl) divEl.value = '';
                    if (genEl) genEl.value = '';
                    if (styleEl) styleEl.value = '';
                    handleStyleRestrictions('walkin');
                }
            }
        }
        
        evaluateTfaaStatus(prefix);
    }

    function closeWalkinModal() {
        const modal = document.getElementById('walkinModal');
        if (modal) modal.classList.remove('active');

        const nameInput = document.getElementById('walkin_name_input');
        if (nameInput) nameInput.value = '';

        const divEl = document.getElementById('walkin_division');
        if (divEl) divEl.value = '';
        const genEl = document.getElementById('walkin_gender');
        if (genEl) genEl.value = '';
        const styleEl = document.getElementById('walkin_style');
        if (styleEl) styleEl.value = '';

        const payEl = document.getElementById('walkin_pay_option');
        if (payEl) payEl.value = 'Individual';

        const famSel = document.getElementById('walkin_family_group_id');
        if (famSel) famSel.value = '';

        const famTxt = document.getElementById('walkin_new_family_name');
        if (famTxt) famTxt.value = '';

        toggleFamilyBox('walkin');
        handleStyleRestrictions('walkin');
        evaluateTfaaStatus('walkin');
    }

    function openWalkinModal() {
        closeWalkinModal();
        const modal = document.getElementById('walkinModal');
        if (modal) modal.classList.add('active');
        const walkinNameInput = document.getElementById('walkin_name_input');
        if (walkinNameInput) walkinNameInput.focus();
    }

    function toggleFamilyBox(type) {
        const payOpt = document.getElementById(type + '_pay_option');
        const box    = document.getElementById(type + '_family_box');
        if (payOpt && box) {
            box.style.display = (payOpt.value === 'Family') ? 'block' : 'none';
        }
    }

    function continueFamilyLoop(familyId, lineIdx) {
        openWalkinModal();
        
        const payOptSelect = document.getElementById('walkin_pay_option');
        if (payOptSelect) {
            payOptSelect.value = 'Family';
            toggleFamilyBox('walkin');
        }

        const familySelect = document.getElementById('walkin_family_group_id');
        if (familySelect) {
            familySelect.value = familyId;
        }

        const lineSelect = document.getElementById('walkin_line_index');
        if (lineSelect && lineIdx !== undefined) {
            lineSelect.value = lineIdx;
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        const walkinInput = document.getElementById('walkin_name_input');
        if (walkinInput) {
            walkinInput.addEventListener('input', function() {
                autoFillOnNameChange('walkin');
            });
        }

        const checkinArcherNameEl = document.getElementById('checkin_archer_name');
        if (checkinArcherNameEl) {
            checkinArcherNameEl.addEventListener('input', function() {
                autoFillOnNameChange('checkin');
            });
            autoFillOnNameChange('checkin');
        }

        const searchInput = document.getElementById('sywat_search_input');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('.sywat-archer-row');
                
                rows.forEach(row => {
                    const name = row.getAttribute('data-name');
                    if (name.includes(filter)) {
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
    });

    function handleStyleRestrictions(type) {
        const divisionEl = document.getElementById(type + '_division');
        const styleEl    = document.getElementById(type + '_style');
        if (!divisionEl || !styleEl) return;

        const currentDiv = divisionEl.value;

        if (!currentDiv || currentDiv === 'No Show') {
            for (let i = 0; i < styleEl.options.length; i++) styleEl.options[i].disabled = false;
            return;
        }

        for (let i = 0; i < styleEl.options.length; i++) {
            const opt = styleEl.options[i];
            const val = opt.value;
            let isDisabled = false;

            if (val.indexOf('Genesis') !== -1 && currentDiv !== 'Cub (8 - 11)') isDisabled = true;

            const isFSPro = val.indexOf('FS Pro') !== -1;
            const isFSL   = val.indexOf('FSL –') !== -1;
            const isTRAD  = val.indexOf('TRAD') !== -1 || val.toLowerCase().indexOf('traditional') !== -1;

            if (isFSPro || isFSL || isTRAD) {
                if (!['Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)'].includes(currentDiv)) isDisabled = true;
            }

            const isLB = val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1;
            if (isLB && currentDiv !== 'Adult (18 - 49)') isDisabled = true;

            opt.disabled = isDisabled;
            if (isDisabled && styleEl.value === val) styleEl.value = 'FS – Freestyle';
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        if(document.getElementById('walkin_division')) handleStyleRestrictions('walkin');
        if(document.getElementById('checkin_division')) handleStyleRestrictions('checkin');
        if(document.getElementById('edit_division')) handleStyleRestrictions('edit');
    });
    </script>
    <?php
    return ob_get_clean();
}
