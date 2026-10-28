<?php
/**
 * Module: Archer Registration & Intake Engine
 * Description: Quick-add phone registration form, FareHarbor CSV parser, text report handling, and intake modal UI.
 * Author: SYWAT Archery
 * Version: 1.0.0
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

if ( ! function_exists( 'sywat_handle_register_shooter' ) ) {
    function sywat_handle_register_shooter( $tournaments, &$registrations ) {
        $shooter_name = trim( sanitize_text_field( $_POST['shooter_name'] ) );
        $distance     = sywat_normalize_dist( sanitize_text_field( $_POST['shooter_distance'] ) );
        $type         = sanitize_text_field( $_POST['shooter_type'] );
        $line_idx     = sanitize_text_field( $_POST['target_line_idx'] );
        $tourn_name   = sanitize_text_field( $_POST['selected_tournament'] );
        $t_slug       = sanitize_title( $tourn_name );

        $line_data = isset( $tournaments[$tourn_name][$line_idx] ) ? $tournaments[$tourn_name][$line_idx] : array();
        $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ((int)$line_idx + 1);

        if ( empty( $shooter_name ) ) return '';

        $is_duplicate = false;
        foreach ( $registrations as $existing ) {
            if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] )
                && strcasecmp ( $existing['tournament'], $tourn_name ) === 0
                && (string)$existing['line_index'] === (string)$line_idx
                && strcasecmp ( trim ( $existing['name'] ), $shooter_name ) === 0 
                && strcasecmp ( $existing['distance'], $distance ) === 0 ) {
                $is_duplicate = true; break;
            }
        }

        $current_count = sywat_count_registered( $tourn_name, $line_idx, $distance, $registrations );
        $max_capacity  = sywat_get_line_max_cap( $line_data, $distance );

        if ( $is_duplicate ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger"><strong>' . esc_html( $shooter_name ) . '</strong> is already registered for ' . esc_html( $line_name ) . ' (' . esc_html( $distance ) . ').</div>';
        } elseif ( $current_count >= $max_capacity ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Cannot add archer. <strong>' . esc_html( $line_name ) . '</strong> has reached maximum capacity (' . $current_count . '/' . $max_capacity . ').</div>';
        }

        $default_paid = ($distance === 'Cub') ? 'Youth' : 'Adult';
        $default_div  = ($distance === 'Cub') ? 'Cub (8 - 11)' : 'Adult (18 - 49)';

        $new_reg = array(
            'id'          => uniqid( 'reg_' ),
            'tournament'  => $tourn_name,
            'line_index'  => $line_idx,
            'line_name'   => $line_name,
            'name'        => $shooter_name,
            'distance'    => $distance,
            'division'    => $default_div,
            'paid'        => $default_paid,
            'balance_due' => 0,
            'status'      => $type,
            'created_at'  => current_time( 'mysql' ),
        );
        
        update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
        $registrations[] = $new_reg;

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully added ' . esc_html( $shooter_name ) . ' to ' . esc_html( $line_name ) . ' (' . esc_html( $distance ) . ')!</div>';
    }
}

if ( ! function_exists( 'sywat_handle_import_shooters' ) ) {
    function sywat_handle_import_shooters( $tourn_name, $target_line_idx, $tournaments, &$registrations ) {
        $t_lines     = isset( $tournaments[$tourn_name] ) ? $tournaments[$tourn_name] : array();
        $t_slug      = sanitize_title( $tourn_name );
        $imported_count = 0; $skipped_dup = 0; $skipped_cap = 0;

        $raw_text_payload = ! empty( $_POST['pasted_text'] ) ? $_POST['pasted_text'] : '';

        if ( ! empty( $raw_text_payload ) ) {
            $lines = explode( "\n", $raw_text_payload );
            $current_dist = '20yd'; $current_paid_context = ''; $in_intro = false;

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
                foreach ( $blacklist_phrases as $phrase ) { if ( stripos( $clean_line, $phrase ) !== false ) { $is_blacklisted = true; break; } }
                if ( ! $is_blacklisted ) {
                    foreach ( $blacklist_words as $kw ) { if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/i', $clean_line ) ) { $is_blacklisted = true; break; } }
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
                    if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] )
                        && strcasecmp ( $existing['tournament'], $tourn_name ) === 0
                        && (string)$existing['line_index'] === (string)$line_idx
                        && strcasecmp ( trim ( $existing['name'] ), $clean_line ) === 0 
                        && strcasecmp ( $existing['distance'], $dist_val ) === 0 ) {
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
                                    if ( stripos( str_replace(' ', '', $check_name), $search_str ) !== false ) { $target_line_idx = (string)$idx; break; }
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
                    $matched_line_idx  = $target_line_idx; 
                    $dist_val  = sywat_normalize_dist( $current_dist ); 
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
                        $col_name     = isset( $data[0] ) ? trim( sanitize_text_field( $data[0] ) ) : '';
                        $col_line     = isset( $data[1] ) ? trim( sanitize_text_field( $data[1] ) ) : '';
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
                        } else {
                            $split_names[] = $raw_names;
                        }
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
                            if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] )
                                && strcasecmp ( $existing['tournament'], $tourn_name ) === 0
                                && (string)$existing['line_index'] === (string)$matched_line_idx
                                && strcasecmp ( trim ( $existing['name'] ), $clean_name ) === 0 
                                && strcasecmp ( $existing['distance'], $dist_val ) === 0 ) {
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
        ?>
        <div class="no-print" style="display:flex; flex-direction:column; gap:20px;">
            <div style="background:#f6f7f7; padding:15px; border:1px solid #c3c4c7; border-radius:4px;">
                <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:600;">1. Quick Add Single Archer</h4>
                <form method="post">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_action" value="register_shooter">
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="modal_action" value="prereg">

                    <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                        <div class="sywat-form-group" style="flex:2; min-width:140px;">
                            <label>Archer Name *</label>
                            <input type="text" name="shooter_name" placeholder="Full Name" required>
                        </div>
                        <div class="sywat-form-group" style="min-width:120px;">
                            <label>Line</label>
                            <select name="target_line_idx" required>
                                <?php foreach ( $available_lines as $idx => $line ): ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>"><?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sywat-form-group" style="min-width:90px; flex:0.8;">
                            <label>Distance</label>
                            <select name="shooter_distance" required>
                                <option value="20yd">20yd</option>
                                <option value="Cub">Cub</option>
                            </select>
                        </div>
                        <div class="sywat-form-group" style="min-width:95px; flex:1;">
                            <label>Status</label>
                            <select name="shooter_type" required>
                                <option value="Pre-Reg">Pre-Reg</option>
                                <option value="Walk-In">Walk-In</option>
                            </select>
                        </div>
                        <div class="sywat-form-group" style="flex:0 0 auto;">
                            <button type="submit" class="sywat-btn-primary">Add Archer</button>
                        </div>
                    </div>
                </form>
            </div>

            <div style="background:#f6f7f7; padding:15px; border:1px solid #c3c4c7; border-radius:4px;">
                <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:600;">2. Bulk Upload Roster File (FareHarbor CSV)</h4>
                <form method="post" enctype="multipart/form-data" id="sywat_import_form">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_action" value="import_shooters">
                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="modal_action" value="prereg">

                    <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom: 10px;">
                        <div class="sywat-form-group" style="flex:1; min-width:200px;">
                            <label>Select Target Shooting Line</label>
                            <select name="target_line_idx" required>
                                <?php foreach ( $available_lines as $idx => $line ): ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>"><?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sywat-form-group" style="flex:2; min-width:250px;">
                            <label>Select File (.csv, .txt)</label>
                            <input type="file" id="import_file_input" name="import_file" accept=".csv,.txt" style="padding:4px;">
                        </div>
                        <button type="submit" id="sywat_upload_btn" class="sywat-btn-primary">Upload & Parse File</button>
                    </div>

                    <details style="margin-top: 10px;">
                        <summary style="font-size: 12px; font-weight: 600; color: #2271b1; cursor: pointer;">Or paste shop report text directly...</summary>
                        <div style="margin-top: 8px;">
                            <textarea name="pasted_text" rows="5" placeholder="Paste report text directly here if preferred..." style="width: 100%; font-family: monospace; font-size: 12px;"></textarea>
                        </div>
                    </details>
                </form>
            </div>

            <div>
                <h4 style="margin:0 0 8px 0; font-size:14px; font-weight:600;">Current Line Slot Usage</h4>
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th>Line Name</th>
                            <th>20yd Capacity</th>
                            <th>Cub Capacity</th>
                            <th style="text-align: right;">Action</th>
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
                                <td>
                                    <?php echo $c_20 . ' / ' . $m_20; ?>
                                    <span class="sywat-badge <?php echo ($c_20 >= $m_20) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ($c_20 >= $m_20) ? 'Full' : 'Open'; ?></span>
                                </td>
                                <td>
                                    <?php echo $c_cub . ' / ' . $m_cub; ?>
                                    <span class="sywat-badge <?php echo ($c_cub >= $m_cub) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ($c_cub >= $m_cub) ? 'Full' : 'Open'; ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ( $total_line_shooters > 0 ): ?>
                                        <form method="post" style="display: inline-block; margin: 0;" onsubmit="return confirm('Are you sure you want to clear ALL <?php echo $total_line_shooters; ?> archers from <?php echo esc_js( $line_title ); ?>?');">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_action" value="purge_line">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                            <input type="hidden" name="redirect_action" value="prereg">
                                            <button type="submit" class="sywat-btn-danger" style="padding: 3px 8px; font-size: 11px; height: auto;">Clear Line</button>
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
        <?php
    }
}
