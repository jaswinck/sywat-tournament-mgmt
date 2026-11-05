<?php
/**
 * Sub-Module: FareHarbor CSV & Raw Text Import Engine
 * Path: includes/registration/registration-import.php
 * Author: SYWAT Archery
 * Version: 6.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles CSV file and raw text payload roster imports for pre-registration intake.
 *
 * @param string $tourn_name       Selected tournament name.
 * @param string $target_line_idx  Target shooting line index key.
 * @param array  $tournaments      Master tournament configuration array.
 * @param array  $registrations    Master registration state array reference.
 * @return string Rendered HTML notice message.
 */
if ( ! function_exists( 'sywat_handle_import_shooters' ) ) {
    function sywat_handle_import_shooters( $tourn_name, $target_line_idx, $tournaments, &$registrations ) {
        $t_data         = isset( $tournaments[$tourn_name] ) ? $tournaments[$tourn_name] : array();
        $t_lines        = function_exists('sywat_get_tournament_lines') ? sywat_get_tournament_lines($t_data) : (isset($t_data['lines']) ? $t_data['lines'] : $t_data);
        $t_slug         = sanitize_title( $tourn_name );
        $allow_partial  = isset( $_POST['allow_partial'] ) && $_POST['allow_partial'] === '1';
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

            $parsed_shooters = array();

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

                $dist_val  = function_exists('sywat_normalize_dist') ? sywat_normalize_dist( $current_dist ) : $current_dist;
                $final_paid = !empty($current_paid_context) ? $current_paid_context : ($dist_val === 'Cub' ? 'Youth' : 'Adult');
                $final_div  = ($final_paid === 'Youth') ? ($dist_val === 'Cub' ? 'Cub (8 - 11)' : 'Youth (12 - 14)') : 'Adult (18 - 49)';

                $parsed_shooters[] = array(
                    'name'     => $clean_line,
                    'line_idx' => $target_line_idx,
                    'distance' => $dist_val,
                    'paid'     => $final_paid,
                    'division' => $final_div,
                );
            }

            // Group Capacity Pre-Check Guard
            if ( ! empty( $parsed_shooters ) && ! $allow_partial ) {
                $line_data = isset( $t_lines[$target_line_idx] ) ? $t_lines[$target_line_idx] : array();
                $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line 1';
                $dist_val  = $parsed_shooters[0]['distance'];

                $current_count = function_exists('sywat_count_registered') ? sywat_count_registered( $tourn_name, $target_line_idx, $dist_val, $registrations ) : 0;
                $max_capacity  = function_exists('sywat_get_line_max_cap') ? sywat_get_line_max_cap( $line_data, $dist_val ) : 0;
                if ( ! $max_capacity || $max_capacity <= 0 ) $max_capacity = ($dist_val === 'Cub') ? 5 : 20;

                $open_slots = max(0, $max_capacity - $current_count);
                $req_cnt    = count($parsed_shooters);

                if ( $req_cnt > $open_slots ) {
                    $prompt  = '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">';
                    $prompt .= '<div class="sywat-alert-title">⚠️ IMPORT CAPACITY OVERFLOW WARNING</div>';
                    $prompt .= '<div class="sywat-alert-subtext">Target line <strong>' . esc_html($line_name) . '</strong> (' . esc_html($dist_val) . ') has <strong>' . intval($open_slots) . ' open spot(s)</strong>, but your import contains <strong>' . intval($req_cnt) . ' archers</strong>. No archers were imported yet.</div>';
                    $prompt .= '<div class="sywat-field-group">';
                    $prompt .= '<form method="post" class="sywat-inline-block">';
                    $prompt .= wp_nonce_field('sywat_desk_action', 'sywat_nonce', true, false);
                    $prompt .= '<input type="hidden" name="sywat_action" value="import_shooters">';
                    $prompt .= '<input type="hidden" name="selected_tournament" value="' . esc_attr($tourn_name) . '">';
                    $prompt .= '<input type="hidden" name="target_line_idx" value="' . esc_attr($target_line_idx) . '">';
                    $prompt .= '<input type="hidden" name="pasted_text" value="' . esc_attr($raw_text_payload) . '">';
                    $prompt .= '<input type="hidden" name="allow_partial" value="1">';
                    $prompt .= '<button type="submit" class="sywat-btn sywat-btn-warning">Import Available Spots (' . intval($open_slots) . ')</button>';
                    $prompt .= '</form>';
                    $prompt .= '<button type="button" class="sywat-btn sywat-btn-secondary" onclick="const m=document.getElementById(\'sywat_capacity_overflow_modal\'); if(m) m.remove();">Cancel & Pick Another Line</button>';
                    $prompt .= '</div></div>';

                    $GLOBALS['sywat_modal_notice'] = $prompt;
                    return $prompt;
                }
            }

            foreach ( $parsed_shooters as $s ) {
                $line_idx  = $s['line_idx'];
                $line_data = isset( $t_lines[$line_idx] ) ? $t_lines[$line_idx] : array();
                $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line 1';
                $dist_val  = $s['distance'];
                $clean_line = $s['name'];

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

                $current_count = function_exists('sywat_count_registered') ? sywat_count_registered( $tourn_name, $line_idx, $dist_val, $registrations ) : 0;
                $max_capacity  = function_exists('sywat_get_line_max_cap') ? sywat_get_line_max_cap( $line_data, $dist_val ) : 0;
                if ( ! $max_capacity || $max_capacity <= 0 ) $max_capacity = ($dist_val === 'Cub') ? 5 : 20;

                if ( $current_count >= $max_capacity ) { $skipped_cap++; continue; }

                $new_reg = array(
                    'id'          => uniqid( 'reg_' ),
                    'tournament'  => $tourn_name,
                    'line_index'  => $line_idx,
                    'line_name'   => $line_name,
                    'name'        => $clean_line,
                    'phone'       => '',
                    'distance'    => $dist_val,
                    'division'    => $s['division'],
                    'paid'        => $s['paid'],
                    'family_id'   => ($s['paid'] === 'Family') ? uniqid('fam_') : '',
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
                    $dist_val = function_exists('sywat_normalize_dist') ? sywat_normalize_dist( $current_dist ) : $current_dist;
                    $row_paid_cat = function_exists('sywat_detect_paid_category') ? sywat_detect_paid_category($data) : '';
                    $row_due_val  = ($due_col_idx !== -1 && isset($data[$due_col_idx]) && function_exists('sywat_extract_due_amount')) ? sywat_extract_due_amount($data[$due_col_idx]) : 0;

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
                        if (!empty($col_distance)) $dist_val = function_exists('sywat_normalize_dist') ? sywat_normalize_dist( $col_distance ) : $col_distance;
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

                        $current_count = function_exists('sywat_count_registered') ? sywat_count_registered( $tourn_name, $matched_line_idx, $dist_val, $registrations ) : 0;
                        $max_capacity  = function_exists('sywat_get_line_max_cap') ? sywat_get_line_max_cap( $line_data, $dist_val ) : 0;
                        if ( ! $max_capacity || $max_capacity <= 0 ) $max_capacity = ($dist_val === 'Cub') ? 5 : 20;

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
