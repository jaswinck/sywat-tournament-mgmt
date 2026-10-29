<?php
/**
 * Module: Archer Registration & Intake Engine
 * Description: Simplified phone group intake with full 7 TFAA age divisions, contact phone auto-formatting, real-time $75 family capping, CCR-only FareHarbor CSV parsing, and line slot tracker.
 * Author: SYWAT Archery
 * Version: 2.3.2
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
        $youth_count = 0;
        $adult_count = 0;
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

            if ($is_youth_tier) {
                $youth_count++;
                $paid_tier = 'Youth';
            } else {
                $adult_count++;
                $paid_tier = 'Adult';
            }

            $valid_archers[] = array(
                'name'      => $name,
                'division'  => $division,
                'paid_tier' => $paid_tier,
                'distance'  => $distance,
                'line_idx'  => !empty($a['line_idx']) ? sanitize_text_field($a['line_idx']) : $default_line_idx,
            );
        }

        if (empty($valid_archers)) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please enter at least one valid archer name.</div>';
        }

        $raw_total = ($youth_count * 25) + ($adult_count * 30);
        $is_family_capped = ($raw_total >= 75 || count($valid_archers) >= 3);
        $final_group_fee  = $is_family_capped ? 75.00 : $raw_total;
        $total_balance_due = max(0, $final_group_fee - $amount_paid);

        // Split balance due proportionally across group members
        $per_archer_due = count($valid_archers) > 0 ? round($total_balance_due / count($valid_archers), 2) : 0;

        $family_id   = 'fam_' . sanitize_title(!empty($group_name) ? $group_name : $valid_archers[0]['name']) . '_' . uniqid();
        $family_name = !empty($group_name) ? $group_name : $valid_archers[0]['name'] . ' Group';

        $added_count = 0;
        $skipped_dup = 0;
        $skipped_cap = 0;

        foreach ($valid_archers as $archer) {
            $line_idx  = $archer['line_idx'];
            $line_data = isset( $t_lines[$line_idx] ) ? $t_lines[$line_idx] : array();
            $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ((int)$line_idx + 1);
            $distance  = $archer['distance'];
            $name      = $archer['name'];

            // Duplicate check
            $is_duplicate = false;
            foreach ( $registrations as $existing ) {
                if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] ) &&
                     strcasecmp ( $existing['tournament'], $tourn_name ) === 0 &&
                     (string)$existing['line_index'] === (string)$line_idx &&
                     strcasecmp ( trim ( $existing['name'] ), $name ) === 0 &&
                     strcasecmp ( $existing['distance'], $distance ) === 0 ) {
                    $is_duplicate = true;
                    break;
                }
            }

            if ($is_duplicate) { $skipped_dup++; continue; }

            // Capacity check
            $current_count = sywat_count_registered( $tourn_name, $line_idx, $distance, $registrations );
            $max_capacity  = sywat_get_line_max_cap( $line_data, $distance );

            if ($current_count >= $max_capacity) { $skipped_cap++; continue; }

            $paid_status = $is_family_capped ? 'Family' : $archer['paid_tier'];

            $new_reg = array(
                'id'          => uniqid( 'reg_' ),
                'tournament'  => $tourn_name,
                'line_index'  => $line_idx,
                'line_name'   => $line_name,
                'name'        => $name,
                'phone'       => $group_phone,
                'distance'    => $distance,
                'division'    => $archer['division'],
                'gender'      => '', // Captured at Desk Check-In
                'style'       => '', // Captured at Desk Check-In
                'paid'        => $paid_status,
                'family_id'   => $family_id,
                'family_name' => $family_name,
                'balance_due' => $per_archer_due,
                'status'      => 'Pre-Reg',
                'created_at'  => current_time( 'mysql' ),
            );

            update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
            $registrations[] = $new_reg;
            $added_count++;
        }

        $msg = "Successfully registered {$added_count} archer(s) under '{$family_name}'. Group Total: $" . number_format($final_group_fee, 2) . " (Balance Due: $" . number_format($total_balance_due, 2) . ").";
        if ($skipped_dup > 0) $msg .= " Skipped {$skipped_dup} duplicate(s).";
        if ($skipped_cap > 0) $msg .= " Skipped {$skipped_cap} over-capacity spot(s).";

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">' . esc_html($msg) . '</div>';
    }
}

// Action: Handle CSV / Text File Import
if ( ! function_exists( 'sywat_handle_import_shooters' ) ) {
    function sywat_handle_import_shooters( $tourn_name, $target_line_idx, $tournaments, &$registrations ) {
        $t_data         = isset( $tournaments[$tourn_name] ) ? $tournaments[$tourn_name] : array();
        $t_lines        = function_exists('sywat_get_tournament_lines') ? sywat_get_tournament_lines($t_data) : (isset($t_data['lines']) ? $t_data['lines'] : $t_data);
        $t_slug         = sanitize_title( $tourn_name );
        $imported_count = 0;
        $skipped_dup    = 0;
        $skipped_cap    = 0;

        $raw_text_payload = ! empty( $_POST['pasted_text'] ) ? $_POST['pasted_text'] : '';

        if ( ! empty( $raw_text_payload ) ) {
            $lines = explode( "\n", $raw_text_payload );
            $current_dist = '20yd';
            $current_paid_context = '';
            $in_intro = false;

            $blacklist_phrases = array( 'sywat', 'cinnamon creek', 'intro to archery', 'full name', 'adult categories', 'cub/youth/young adult' );
            $blacklist_words   = array( 'contact', 'total', 'due', 'bookings', 'archers', 'generated' );

            foreach ( $lines as $raw_line ) {
                $clean_line = trim( $raw_line );
                if ( empty( $clean_line ) ) continue;

                if ( stripos( $clean_line, 'Intro To Archery' ) !== false ) { $in_intro = true; continue; }
                if ( $in_intro ) continue;

                $detected_hdr_paid = sywat_detect_paid_category($clean_line);
                if (!empty($detected_hdr_paid)) $current_paid_context = $detected_hdr_paid;

                if ( stripos( $clean_line, '10 YARDS' ) !== false && stripos( $clean_line, 'SYWAT' ) !== false ) { $current_dist = 'Cub'; continue; }
                if ( stripos( $clean_line, '20 YARDS' ) !== false && stripos( $clean_line, 'SYWAT' ) !== false ) { $current_dist = '20yd'; continue; }

                if ( strpos( $clean_line, ',' ) !== false || strpos( $clean_line, '@' ) !== false || strpos( $clean_line, '$' ) !== false || preg_match( '/[0-9]/', $clean_line ) ) continue;

                $is_blacklisted = false;
                foreach ( $blacklist_phrases as $phrase ) {
                    if ( stripos( $clean_line, $phrase ) !== false ) { $is_blacklisted = true; break; }
                }

                if ( ! $is_blacklisted ) {
                    foreach ( $blacklist_words as $kw ) {
                        if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/i', $clean_line ) ) { $is_blacklisted = true; break; }
                    }
                }

                if ( $is_blacklisted ) continue;

                $clean_line = trim( ltrim( $clean_line, '| ' ) );
                if ( strlen( $clean_line ) < 2 || strlen( $clean_line ) > 60 ) continue;

                $line_idx  = $target_line_idx;
                $line_data = isset( $t_lines[$line_idx] ) ? $t_lines[$line_idx] : array();
                $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line 1';
                $dist_val  = sywat_normalize_dist( $current_dist );

                $is_duplicate = false;
                foreach ( $registrations as $existing ) {
                    if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] ) &&
                         strcasecmp ( $existing['tournament'], $tourn_name ) === 0 &&
                         (string)$existing['line_index'] === (string)$line_idx &&
                         strcasecmp ( trim ( $existing['name'] ), $clean_line ) === 0 &&
                         strcasecmp ( $existing['distance'], $dist_val ) === 0 ) {
                        $is_duplicate = true; break;
                    }
                }

                if ( $is_duplicate ) { $skipped_dup++; continue; }

                $current_count = sywat_count_registered( $tourn_name, $line_idx, $dist_val, $registrations );
                $max_capacity  = sywat_get_line_max_cap( $line_data, $dist_val );

                if ( $current_count >= $max_capacity ) { $skipped_cap++; continue; }

                $final_paid = !empty($current_paid_context) ? $current_paid_context : ($dist_val === 'Cub' ? 'Youth' : 'Adult');
                $final_div  = ($final_paid === 'Youth') ? ($dist_val === 'Cub' ? 'Cub (8 - 11)' : 'Youth (12 - 14)') : 'Adult (18 - 49)';

                $new_reg = array(
                    'id'          => uniqid( 'reg_' ),
                    'tournament'  => $tourn_name,
                    'line_index'  => $line_idx,
                    'line_name'   => $line_name,
                    'name'        => $clean_line,
                    'phone'       => '',
                    'distance'    => $dist_val,
                    'division'    => $final_div,
                    'paid'        => $final_paid,
                    'family_id'   => ($final_paid === 'Family') ? uniqid('fam_') : '',
                    'balance_due' => 0,
                    'status'      => 'Pre-Reg',
                    'created_at'  => current_time( 'mysql' ),
                );

                update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                $registrations[] = $new_reg;
                $imported_count++;
            }
        } elseif ( isset( $_FILES['import_file'] ) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK ) {
            $handle = fopen( $_FILES['import_file']['tmp_name'], 'r' );
            if ( $handle !== false ) {
                $base_line_name = isset($t_lines[$target_line_idx]['name']) ? $t_lines[$target_line_idx]['name'] : '';
                $day_context = '';
                if (stripos($base_line_name, 'Sunday') !== false) $day_context = 'Sunday';
                elseif (stripos($base_line_name, 'Saturday') !== false) $day_context = 'Saturday';
                elseif (stripos($base_line_name, 'Friday') !== false) $day_context = 'Friday';

                $row_index = 0; $is_fh_format = false;
                $booking_col_idx = -1; $name_col_idx = -1; $contact_col_idx = -1; $pax_col_idx = -1; $due_col_idx = -1;
                $current_dist = '20yd';

                while ( ( $data = fgetcsv( $handle, 4000, ',' ) ) !== false ) {
                    $row_index++;
                    if ( empty( array_filter( $data ) ) ) continue;

                    $first_cell  = isset($data[0]) ? trim($data[0]) : '';
                    $second_cell = isset($data[1]) ? trim($data[1]) : '';

                    $is_section_header = false;
                    if ( stripos($first_cell, '10 YARDS') !== false || stripos($first_cell, '10 YD') !== false ) { $current_dist = 'Cub'; $is_section_header = true; }
                    elseif ( stripos($first_cell, '20 YARDS') !== false || stripos($first_cell, '20 YD') !== false ) { $current_dist = '20yd'; $is_section_header = true; }

                    if ( $is_section_header ) {
                        if ( preg_match('/([0-9]{1,2}):[0-9]{2}\s*(AM|PM)/i', $second_cell, $time_matches) ) {
                            $search_str = $time_matches[1] . strtoupper($time_matches[2]);
                            if ( ! empty( $t_lines ) ) {
                                foreach ( $t_lines as $idx => $l_data ) {
                                    $check_name = isset($l_data['name']) ? $l_data['name'] : '';
                                    if ( $day_context && stripos($check_name, $day_context) === false ) continue;
                                    if ( stripos( str_replace(' ', '', $check_name), $search_str ) !== false ) {
                                        $target_line_idx = (string)$idx; break;
                                    }
                                }
                            }
                        }
                        continue;
                    }

                    $is_header_row = false;
                    foreach ($data as $idx => $val) {
                        if (strcasecmp(trim($val), 'Booking ID') === 0) { $booking_col_idx = $idx; $is_header_row = true; }
                        if (strcasecmp(trim($val), 'Full Name') === 0) { $name_col_idx = $idx; $is_header_row = true; }
                        if (strcasecmp(trim($val), 'Contact') === 0) { $contact_col_idx = $idx; $is_header_row = true; }
                        if (strcasecmp(trim($val), 'Pax') === 0 || strcasecmp(trim($val), 'Customers') === 0) { $pax_col_idx = $idx; }
                        if (strcasecmp(trim($val), 'Due') === 0) { $due_col_idx = $idx; }
                    }

                    if ($is_header_row) { $is_fh_format = true; continue; }

                    $raw_names = '';
                    $booking_id_val = ($booking_col_idx !== -1 && isset($data[$booking_col_idx])) ? trim($data[$booking_col_idx]) : '';
                    $matched_line_idx = $target_line_idx;
                    $dist_val = sywat_normalize_dist( $current_dist );
                    $row_paid_cat = sywat_detect_paid_category($data);
                    $row_due_val  = ($due_col_idx !== -1 && isset($data[$due_col_idx])) ? sywat_extract_due_amount($data[$due_col_idx]) : 0;

                    if ($is_fh_format) {
                        if ($name_col_idx !== -1 && isset($data[$name_col_idx])) $raw_names = trim($data[$name_col_idx]);
                        if (empty($raw_names) && $contact_col_idx !== -1 && isset($data[$contact_col_idx])) {
                            $contact_parts = preg_split('/\r\n|\r|\n/', trim($data[$contact_col_idx]));
                            if (!empty($contact_parts)) $raw_names = trim($contact_parts[0]);
                        }
                        if (empty($raw_names) || strtolower(trim($raw_names)) === 'full name') continue;
                    } else {
                        $col_name = isset( $data[0] ) ? trim( sanitize_text_field( $data[0] ) ) : '';
                        $col_line = isset( $data[1] ) ? trim( sanitize_text_field( $data[1] ) ) : '';
                        $col_distance = isset( $data[2] ) ? trim( sanitize_text_field( $data[2] ) ) : '';

                        if ( $row_index === 1 && ( strcasecmp( $col_name, 'name' ) === 0 || strcasecmp( $col_name, 'shooter name' ) === 0 ) ) continue;
                        $raw_names = $col_name;

                        if ( ! empty( $col_line ) && ! empty( $t_lines ) ) {
                            foreach ( $t_lines as $idx => $l_data ) {
                                if ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), $col_line ) === 0 ) {
                                    $matched_line_idx = (string)$idx; break;
                                }
                            }
                        }
                        if (!empty($col_distance)) $dist_val = sywat_normalize_dist( $col_distance );
                    }

                    if (empty($raw_names)) continue;

                    $split_names = array();
                    $pax = ($pax_col_idx !== -1 && isset($data[$pax_col_idx])) ? intval($data[$pax_col_idx]) : 1;

                    if ($is_fh_format) {
                        $clean_separators = str_ireplace(array(' and ', ' & '), ',', $raw_names);
                        if (strpos($clean_separators, ',') !== false) {
                            $parts = explode(',', $clean_separators);
                            foreach ($parts as $p) { if (trim($p)) $split_names[] = trim($p); }
                        } elseif ($pax > 1) {
                            $words = array_values(array_filter(explode(' ', $raw_names)));
                            if (count($words) >= ($pax * 2)) {
                                $words_per_name = floor(count($words) / $pax);
                                $extra_words = count($words) % $pax;
                                $offset = 0;
                                for ($i = 0; $i < $pax; $i++) {
                                    $length = $words_per_name + ($i < $extra_words ? 1 : 0);
                                    $split_names[] = implode(' ', array_slice($words, $offset, $length));
                                    $offset += $length;
                                }
                            } else {
                                for ($i = 1; $i <= $pax; $i++) { $split_names[] = trim($raw_names) . " ($i)"; }
                            }
                        } else { $split_names[] = $raw_names; }
                    } else {
                        $split_names = preg_split('/\r\n|\r|\n/', $raw_names);
                    }

                    $final_paid = !empty($row_paid_cat) ? $row_paid_cat : ($dist_val === 'Cub' ? 'Youth' : 'Adult');
                    $shared_fam_id = ($final_paid === 'Family') ? (!empty($booking_id_val) ? $booking_id_val : uniqid('fam_')) : '';
                    $matched_line_name = isset( $t_lines[$matched_line_idx]['name'] ) ? $t_lines[$matched_line_idx]['name'] : 'Line 1';
                    $line_data = isset( $t_lines[$matched_line_idx] ) ? $t_lines[$matched_line_idx] : array();

                    foreach ($split_names as $single_name) {
                        $clean_name = trim(sanitize_text_field($single_name));
                        if (empty($clean_name)) continue;

                        $is_duplicate = false;
                        foreach ( $registrations as $existing ) {
                            if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] ) &&
                                 strcasecmp ( $existing['tournament'], $tourn_name ) === 0 &&
                                 (string)$existing['line_index'] === (string)$matched_line_idx &&
                                 strcasecmp ( trim ( $existing['name'] ), $clean_name ) === 0 &&
                                 strcasecmp ( $existing['distance'], $dist_val ) === 0 ) {
                                $is_duplicate = true; break;
                            }
                        }

                        if ( $is_duplicate ) { $skipped_dup++; continue; }

                        $current_count = sywat_count_registered( $tourn_name, $matched_line_idx, $dist_val, $registrations );
                        $max_capacity  = sywat_get_line_max_cap( $line_data, $dist_val );

                        if ( $current_count >= $max_capacity ) { $skipped_cap++; continue; }

                        $final_div = ($final_paid === 'Youth') ? ($dist_val === 'Cub' ? 'Cub (8 - 11)' : 'Youth (12 - 14)') : 'Adult (18 - 49)';

                        $new_reg   = array(
                            'id'          => uniqid( 'reg_' ),
                            'tournament'  => $tourn_name,
                            'line_index'  => $matched_line_idx,
                            'line_name'   => $matched_line_name,
                            'name'        => $clean_name,
                            'phone'       => '',
                            'distance'    => $dist_val,
                            'division'    => $final_div,
                            'paid'        => $final_paid,
                            'booking_id'  => $booking_id_val,
                            'family_id'   => $shared_fam_id,
                            'balance_due' => $row_due_val,
                            'status'      => 'Pre-Reg',
                            'created_at'  => current_time( 'mysql' ),
                        );

                        update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                        $registrations[] = $new_reg;
                        $imported_count++;
                    }
                }
                fclose( $handle );
            }
        }

        $msg = 'Successfully imported ' . $imported_count . ' shooter(s) as Pre-Reg.';
        if ( $skipped_dup > 0 ) $msg .= ' Skipped ' . $skipped_dup . ' duplicate(s).';
        if ( $skipped_cap > 0 ) $msg .= ' Skipped ' . $skipped_cap . ' due to capacity limits.';

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">' . esc_html( $msg ) . '</div>';
    }
}

// Intake Modal UI Renderer
if ( ! function_exists( 'sywat_render_registration_modal' ) ) {
    function sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations ) {
        $user = wp_get_current_user();
        $is_admin = in_array('administrator', (array) $user->roles);

        $tournaments_list = get_option('sywat_tournaments_list', array());
        $tourn_data       = isset($tournaments_list[$selected_tournament]) ? $tournaments_list[$selected_tournament] : array();
        $tourn_shop_id    = function_exists('sywat_get_tournament_shop_id') ? sywat_get_tournament_shop_id($tourn_data) : 'shop_ccr';

        $can_show_csv_import = ($is_admin && $tourn_shop_id === 'shop_ccr');
        ?>
        <div class="no-print" style="display:flex; flex-direction:column; gap:20px;">
            
            <!-- PHONE & GROUP INTAKE FORM -->
            <div style="background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:4px;">
                <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:600; color:#1e293b;">1. Phone & Group Registration Intake</h4>
                
                <form method="post" id="sywat_phone_intake_form">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_action" value="register_shooter">
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="modal_action" value="prereg">

                    <!-- Group Header Information -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; background:#fff; padding:12px; border:1px solid #e2e8f0; border-radius:4px; margin-bottom:15px;">
                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Caller / Group Name</label>
                            <input type="text" name="group_name" placeholder="e.g. Smith Family or John Smith" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;">
                        </div>

                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Contact Phone Number</label>
                            <input type="tel" name="group_phone" id="sywat_group_phone" placeholder="e.g. 817-555-1234" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" oninput="formatPhoneNumber(this)">
                        </div>

                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Default Shooting Line *</label>
                            <select name="default_line_idx" id="sywat_default_line_idx" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" required onchange="updateAllArcherLines(this.value)">
                                <?php foreach ( $available_lines as $idx => $line ): ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>"><?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Amount Paid Today ($)</label>
                            <input type="number" step="0.01" min="0" name="amount_paid_today" id="sywat_amount_paid_today" value="0.00" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" oninput="recalculateGroupTotal()">
                        </div>
                    </div>

                    <!-- Archer Rows Container -->
                    <div id="sywat_archer_rows_container">
                        <!-- Archer Row 1 -->
                        <div class="sywat-archer-input-row" style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">
                            <input type="text" name="archers[0][name]" placeholder="Archer Full Name *" style="flex:2; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" required oninput="recalculateGroupTotal()">
                            
                            <!-- Target Distance Selection -->
                            <select name="archers[0][distance]" class="sywat-distance-select" style="flex:1; min-width:100px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="handleDistanceChange(this)">
                                <option value="20yd" selected>20yd</option>
                                <option value="Cub">Cub (10yd)</option>
                            </select>

                            <!-- Full 7 TFAA Age Division Selection -->
                            <select name="archers[0][division]" class="sywat-division-select" style="flex:1.5; min-width:160px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="this.dataset.userChosen='true'; recalculateGroupTotal();" required>
                                <option value="" disabled selected hidden>Select Division</option>
                                <option value="Cub (8 - 11)">Cub (8 - 11) [$25]</option>
                                <option value="Youth (12 - 14)">Youth (12 - 14) [$25]</option>
                                <option value="Young Adult (15 - 17)">Young Adult (15 - 17) [$25]</option>
                                <option value="Adult (18 - 49)">Adult (18 - 49) [$30]</option>
                                <option value="Senior (50 - 59)">Senior (50 - 59) [$30]</option>
                                <option value="Silver Senior (60 - 69)">Silver Senior (60 - 69) [$30]</option>
                                <option value="Master Senior (70+) ">Master Senior (70+) [$30]</option>
                            </select>

                            <!-- Line Override Selection -->
                            <select name="archers[0][line_idx]" class="sywat-line-override-select" style="flex:1.2; min-width:130px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;">
                                <?php foreach ( $available_lines as $idx => $line ): ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>"><?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?></option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" style="height:32px; padding:2px 8px; font-size:12px;" onclick="removeArcherRow(this)" title="Remove Archer"></button>
                        </div>
                    </div>

                    <!-- Add Row & Live Payment Total Display -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; padding-top:12px; border-top:1px dashed #cbd5e1; flex-wrap:wrap; gap:10px;">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addArcherRow()">Add Another Archer</button>

                        <div style="text-align:right;">
                            <div style="font-size:13px; font-weight:bold; color:#1e293b;">
                                Group Total: <span id="sywat_group_total_disp" style="color:#0284c7;">$30.00</span>
                                <span id="sywat_family_cap_badge" style="display:none; background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:2px 6px; border-radius:12px; font-size:11px; margin-left:6px;">🟢 Family Cap Applied</span>
                            </div>
                            <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                Balance Due at Desk: <strong id="sywat_balance_due_disp" style="color:#dc2626;">$30.00</strong>
                            </div>
                        </div>

                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-checkin" style="height:34px;">Save & Register Shooters</button>
                    </div>
                </form>
            </div>

            <!-- FAREHARBOR CSV BULK UPLOAD (CCR ADMIN ONLY) -->
            <?php if ( $can_show_csv_import ): ?>
                <div style="background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:4px;">
                    <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:600; color:#1e293b;">2. Bulk Upload Roster File (FareHarbor CSV)</h4>
                    <form method="post" enctype="multipart/form-data" id="sywat_import_form">
                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                        <input type="hidden" name="sywat_action" value="import_shooters">
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="modal_action" value="prereg">

                        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom: 10px;">
                            <div style="flex:1; min-width:200px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Select Target Shooting Line</label>
                                <select name="target_line_idx" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" required>
                                    <?php foreach ( $available_lines as $idx => $line ): ?>
                                        <option value="<?php echo esc_attr( $idx ); ?>"><?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="flex:2; min-width:250px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Select File (.csv, .txt)</label>
                                <input type="file" id="import_file_input" name="import_file" accept=".csv,.txt" style="width:100%; padding:4px; font-size:13px;">
                            </div>

                            <button type="submit" id="sywat_upload_btn" class="sywat-btn sywat-btn-primary sywat-btn-icon-export">Upload & Parse File</button>
                        </div>

                        <details style="margin-top: 10px;">
                            <summary style="font-size: 12px; font-weight: 600; color: #2271b1; cursor: pointer;">Or paste shop report text directly...</summary>
                            <div style="margin-top: 8px;">
                                <textarea name="pasted_text" rows="5" placeholder="Paste report text directly here if preferred..." style="width: 100%; font-family: monospace; font-size: 12px; border:1px solid #ccc; border-radius:4px; padding:6px;"></textarea>
                            </div>
                        </details>
                    </form>
                </div>
            <?php endif; ?>

            <!-- LINE SLOT USAGE TABLE -->
            <div>
                <h4 style="margin:0 0 8px 0; font-size:14px; font-weight:600; color:#1e293b;">Current Line Slot Usage</h4>
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Line Name</th>
                            <th style="text-align:center;">20yd Capacity</th>
                            <th style="text-align:center;">Cub Capacity</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $available_lines as $idx => $line ): ?>
                            <?php
                                $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1);
                                $c_20 = sywat_count_registered( $selected_tournament, $idx, '20yd', $registrations );
                                $m_20 = sywat_get_line_max_cap( $line, '20yd' );
                                $c_cub = sywat_count_registered( $selected_tournament, $idx, 'Cub', $registrations );
                                $m_cub = sywat_get_line_max_cap( $line, 'Cub' );
                                $total_line_shooters = $c_20 + $c_cub;
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $line_title ); ?></strong></td>
                                <td style="text-align:center;">
                                    <?php echo $c_20 . ' / ' . $m_20; ?>
                                    <span class="sywat-badge <?php echo ($c_20 >= $m_20) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ($c_20 >= $m_20) ? 'Full' : 'Open'; ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <?php echo $c_cub . ' / ' . $m_cub; ?>
                                    <span class="sywat-badge <?php echo ($c_cub >= $m_cub) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ($c_cub >= $m_cub) ? 'Full' : 'Open'; ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ( $total_line_shooters > 0 ): ?>
                                        <form method="post" style="display: inline-block; margin: 0;" onsubmit="return confirm('Are you sure you want to clear ALL <?php echo $total_line_shooters; ?> archers from <?php echo esc_js( $line_title ); ?>?');">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_action" value="purge_line">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                            <input type="hidden" name="redirect_action" value="prereg">
                                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="padding: 3px 8px; font-size: 11px; height: auto;">Clear Line</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: #94a3b8;">(Empty)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        let archerRowCount = 1;

        function formatPhoneNumber(input) {
            let digits = input.value.replace(/\D/g, '');
            if (digits.length > 10) digits = digits.substring(0, 10);

            if (digits.length > 6) {
                input.value = digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6);
            } else if (digits.length > 3) {
                input.value = digits.substring(0, 3) + '-' + digits.substring(3);
            } else {
                input.value = digits;
            }
        }

        function updateAllArcherLines(selectedLineIdx) {
            const lineSelects = document.querySelectorAll('.sywat-line-override-select');
            lineSelects.forEach(select => {
                select.value = selectedLineIdx;
            });
        }

        function handleDistanceChange(distSelect) {
            const row = distSelect.closest('.sywat-archer-input-row');
            if (!row) return;
            const divSelect = row.querySelector('.sywat-division-select');
            if (!divSelect) return;

            if (distSelect.value === 'Cub') {
                divSelect.value = 'Cub (8 - 11)';
            } else if (distSelect.value === '20yd') {
                if (divSelect.value === 'Cub (8 - 11)' && divSelect.dataset.userChosen !== 'true') {
                    divSelect.value = '';
                }
            }
            recalculateGroupTotal();
        }

        function addArcherRow() {
            const container = document.getElementById('sywat_archer_rows_container');
            if (!container) return;

            const defaultLineIdx = document.getElementById('sywat_default_line_idx')?.value || '0';
            const rowDiv = document.createElement('div');
            rowDiv.className = 'sywat-archer-input-row';
            rowDiv.style.cssText = 'display:flex; gap:10px; align-items:center; margin-bottom:8px;';

            let lineOptionsHtml = '';
            const defaultLineSelect = document.getElementById('sywat_default_line_idx');
            if (defaultLineSelect) {
                Array.from(defaultLineSelect.options).forEach(opt => {
                    const isSel = (opt.value === defaultLineIdx) ? 'selected' : '';
                    lineOptionsHtml += `<option value="${opt.value}" ${isSel}>${opt.text}</option>`;
                });
            }

            rowDiv.innerHTML = `
                <input type="text" name="archers[${archerRowCount}][name]" placeholder="Archer Full Name *" style="flex:2; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" required oninput="recalculateGroupTotal()">
                
                <select name="archers[${archerRowCount}][distance]" class="sywat-distance-select" style="flex:1; min-width:100px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="handleDistanceChange(this)">
                    <option value="20yd" selected>20yd</option>
                    <option value="Cub">Cub (10yd)</option>
                </select>

                <select name="archers[${archerRowCount}][division]" class="sywat-division-select" style="flex:1.5; min-width:160px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="this.dataset.userChosen='true'; recalculateGroupTotal();" required>
                    <option value="" disabled selected hidden>Select Division</option>
                    <option value="Cub (8 - 11)">Cub (8 - 11) [$25]</option>
                    <option value="Youth (12 - 14)">Youth (12 - 14) [$25]</option>
                    <option value="Young Adult (15 - 17)">Young Adult (15 - 17) [$25]</option>
                    <option value="Adult (18 - 49)">Adult (18 - 49) [$30]</option>
                    <option value="Senior (50 - 59)">Senior (50 - 59) [$30]</option>
                    <option value="Silver Senior (60 - 69)">Silver Senior (60 - 69) [$30]</option>
                    <option value="Master Senior (70+) ">Master Senior (70+) [$30]</option>
                </select>

                <select name="archers[${archerRowCount}][line_idx]" class="sywat-line-override-select" style="flex:1.2; min-width:130px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;">
                    ${lineOptionsHtml}
                </select>

                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" style="height:32px; padding:2px 8px; font-size:12px;" onclick="removeArcherRow(this)" title="Remove Archer"></button>
            `;

            container.appendChild(rowDiv);
            archerRowCount++;
            recalculateGroupTotal();
        }

        function removeArcherRow(btn) {
            const rows = document.querySelectorAll('.sywat-archer-input-row');
            if (rows.length <= 1) {
                alert('At least one archer row is required.');
                return;
            }
            btn.closest('.sywat-archer-input-row').remove();
            recalculateGroupTotal();
        }

        function recalculateGroupTotal() {
            const rows = document.querySelectorAll('.sywat-archer-input-row');
            let rawTotal = 0;
            let archerCount = 0;

            const youthDivisions = ['Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)'];

            rows.forEach(row => {
                const nameInput  = row.querySelector('input[type="text"]');
                const distSelect = row.querySelector('.sywat-distance-select');
                const divSelect  = row.querySelector('.sywat-division-select');

                if (nameInput && nameInput.value.trim() !== '') {
                    archerCount++;
                    const dist = distSelect ? distSelect.value : '20yd';
                    const div  = divSelect ? divSelect.value : '';

                    if (dist === 'Cub' || youthDivisions.includes(div)) {
                        rawTotal += 25;
                    } else {
                        rawTotal += 30;
                    }
                }
            });

            if (archerCount === 0) archerCount = rows.length;

            const isCapped = (rawTotal >= 75 || archerCount >= 3);
            const finalFee = isCapped ? 75.00 : rawTotal;

            const totalDisp = document.getElementById('sywat_group_total_disp');
            const capBadge  = document.getElementById('sywat_family_cap_badge');
            const paidInput = document.getElementById('sywat_amount_paid_today');
            const dueDisp   = document.getElementById('sywat_balance_due_disp');

            if (totalDisp) totalDisp.textContent = '$' + finalFee.toFixed(2);
            if (capBadge) capBadge.style.display = isCapped ? 'inline-block' : 'none';

            const paidVal = paidInput ? parseFloat(paidInput.value) || 0 : 0;
            const balanceDue = Math.max(0, finalFee - paidVal);

            if (dueDisp) dueDisp.textContent = '$' + balanceDue.toFixed(2);
        }

        document.addEventListener('DOMContentLoaded', function() {
            recalculateGroupTotal();
        });
        </script>
        <?php
    }
}
