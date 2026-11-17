<?php
/**
 * Sub-Module: Bulk Spreadsheet Import Engine (.xlsx, .xls, .csv)
 * Path: includes/registration/registration-import.php
 * Description: Handles strict 9-column roster imports, native .xlsx/.csv parsing, automatic template generation, deterministic household grouping (Phone/Contact), O(1) duplicate checks, 10yd Youth pricing locks, Check-In payment mapping (Paid/Unpaid), and Waitlist overflow.
 * Compliance: Pure action/import layer — zero output at include time, zero inline styles, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hook to intercept and generate the downloadable Excel (.csv/.xlsx format) template.
 * Triggered via $_GET['sywat_download_import_template']
 */
add_action( 'init', 'sywat_handle_download_import_template' );
if ( ! function_exists( 'sywat_handle_download_import_template' ) ) {
    function sywat_handle_download_import_template() {
        if ( isset( $_GET['sywat_download_import_template'] ) && $_GET['sywat_download_import_template'] === '1' ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sywat_download_import_template' ) ) {
                wp_die( 'Security token expired. Please refresh the page and try again.' );
            }
            if ( ! function_exists( 'sywat_can_access_desk' ) || ! sywat_can_access_desk() ) {
                wp_die( 'Access denied.' );
            }

            $tourn_name = isset( $_GET['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_GET['selected_tournament'] ) ) : '';
            $filename   = 'SYWAT_Import_Template_' . ( ! empty( $tourn_name ) ? sanitize_title( $tourn_name ) : 'General' ) . '.csv';

            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );

            $output = fopen( 'php://output', 'w' );
            
            // Standardized 9-Column Header
            fputcsv( $output, array(
                'Contact Name',
                'Phone',
                'Email',
                'Archer Name',
                'Line Day (Friday / Saturday / Sunday)',
                'Line Time (HH:MM AM/PM)',
                'Distance (10 yds / 20 yds)',
                'Rate Tier (Youth / Adult)',
                'Paid Status (Paid / Unpaid)'
            ) );

            // Example Row
            fputcsv( $output, array(
                'John Smith',
                '817-555-1234',
                'john@example.com',
                'John Smith',
                'Saturday',
                '10:00 AM',
                '20 yds',
                'Adult',
                'Paid'
            ) );

            fclose( $output );
            exit;
        }
    }
}

/**
 * Native lightweight .xlsx parser using PHP ZipArchive.
 * Reads sharedStrings and sheet1 to extract cell values without external libraries.
 */
if ( ! function_exists( 'sywat_parse_simple_xlsx' ) ) {
    function sywat_parse_simple_xlsx( $filepath ) {
        $rows = array();
        if ( ! class_exists( 'ZipArchive' ) ) {
            return $rows; // Fallback handled in main flow
        }
        $zip = new ZipArchive();
        if ( $zip->open( $filepath ) === true ) {
            $shared_strings = array();
            if ( ( $ss_idx = $zip->locateName( 'xl/sharedStrings.xml' ) ) !== false ) {
                $xml = simplexml_load_string( $zip->getFromIndex( $ss_idx ) );
                if ( $xml ) {
                    foreach ( $xml->si as $si ) {
                        $shared_strings[] = (string) $si->t;
                    }
                }
            }

            if ( ( $sheet_idx = $zip->locateName( 'xl/worksheets/sheet1.xml' ) ) !== false ) {
                $xml = simplexml_load_string( $zip->getFromIndex( $sheet_idx ) );
                if ( $xml ) {
                    foreach ( $xml->sheetData->row as $row ) {
                        $current_row = array();
                        $col_idx = 0;
                        foreach ( $row->c as $c ) {
                            // Account for skipped empty cells via 'r' attribute (e.g., A1, C1)
                            $cell_ref = (string) $c['r'];
                            $col_letter = preg_replace( '/[0-9]/', '', $cell_ref );
                            $expected_idx = ord( strtoupper( $col_letter ) ) - 65; // A=0, B=1
                            
                            while ( $col_idx < $expected_idx ) {
                                $current_row[] = '';
                                $col_idx++;
                            }

                            $val = (string) $c->v;
                            if ( isset( $c['t'] ) && (string) $c['t'] === 's' ) {
                                $val = isset( $shared_strings[ (int) $val ] ) ? $shared_strings[ (int) $val ] : '';
                            } else {
                                // Convert Excel numeric time fraction (e.g., 0.416666 = 10:00 AM)
                                if ( is_numeric( $val ) && $val > 0 && $val < 1 ) {
                                    $total_seconds = round( $val * 86400 );
                                    $val = gmdate( 'g:i A', $total_seconds );
                                }
                            }
                            $current_row[] = trim( $val );
                            $col_idx++;
                        }
                        $rows[] = $current_row;
                    }
                }
            }
            $zip->close();
        }
        return $rows;
    }
}

/**
 * Main Importer: Validates, groups, prices, and registers shooters from .csv/.xlsx uploads.
 */
if ( ! function_exists( 'sywat_handle_import_shooters' ) ) {
    function sywat_handle_import_shooters( $tourn_name, $target_line_idx, $tournaments, &$registrations ) {
        $tourn_name      = sanitize_text_field( wp_unslash( $tourn_name ) );
        $t_data          = isset( $tournaments[ $tourn_name ] ) ? $tournaments[ $tourn_name ] : array();
        $tourn_date      = ( is_array( $t_data ) && ! empty( $t_data['start_date'] ) ) ? (string) $t_data['start_date'] : '';
        $t_lines         = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : ( isset( $t_data['lines'] ) ? $t_data['lines'] : $t_data );
        $t_slug          = sanitize_title( $tourn_name );
        
        $imported_count   = 0;
        $waitlisted_count = 0;
        $skipped_dup      = 0;
        $touched_fams     = array();
        $errors           = array();

        // Check file upload
        if ( ! isset( $_FILES['import_file'] ) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK ) {
            $GLOBALS['sywat_import_modal_error'] = 'No valid file was received. Please select a .xlsx or .csv file and try again.';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">File upload failed.</div>';
        }

        $tmp_path = (string) $_FILES['import_file']['tmp_name'];
        $filename = strtolower( (string) $_FILES['import_file']['name'] );
        $raw_rows = array();

        // Parse File
        if ( strpos( $filename, '.xlsx' ) !== false ) {
            if ( ! class_exists( 'ZipArchive' ) ) {
                $GLOBALS['sywat_import_modal_error'] = 'Your server is missing the PHP ZipArchive extension required for .xlsx files. Please upload a .csv file instead.';
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">ZipArchive extension missing.</div>';
            }
            $raw_rows = sywat_parse_simple_xlsx( $tmp_path );
        } else {
            // CSV Parse
            $handle = fopen( $tmp_path, 'r' );
            if ( $handle !== false ) {
                while ( ( $data = fgetcsv( $handle, 4000, ',' ) ) !== false ) {
                    if ( ! empty( array_filter( $data ) ) ) {
                        $raw_rows[] = $data;
                    }
                }
                fclose( $handle );
            }
        }

        if ( empty( $raw_rows ) || count( $raw_rows ) < 2 ) {
            $GLOBALS['sywat_import_modal_error'] = 'The uploaded file appears to be empty or unreadable.';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">File is empty.</div>';
        }

        // Header mapping (Expected 9 columns)
        $headers = array_map( 'trim', array_map( 'strtolower', $raw_rows[0] ) );
        $col_map = array(
            'contact'  => -1, 'phone'    => -1, 'email' => -1, 'archer'   => -1,
            'line_day' => -1, 'line_time'=> -1, 'distance' => -1, 'tier'  => -1, 'paid' => -1
        );

        foreach ( $headers as $idx => $hdr ) {
            if ( strpos( $hdr, 'contact' ) !== false ) $col_map['contact'] = $idx;
            elseif ( strpos( $hdr, 'phone' ) !== false ) $col_map['phone'] = $idx;
            elseif ( strpos( $hdr, 'email' ) !== false ) $col_map['email'] = $idx;
            elseif ( strpos( $hdr, 'archer' ) !== false || strpos( $hdr, 'full name' ) !== false ) $col_map['archer'] = $idx;
            elseif ( strpos( $hdr, 'line day' ) !== false ) $col_map['line_day'] = $idx;
            elseif ( strpos( $hdr, 'line time' ) !== false ) $col_map['line_time'] = $idx;
            elseif ( strpos( $hdr, 'distance' ) !== false ) $col_map['distance'] = $idx;
            elseif ( strpos( $hdr, 'tier' ) !== false ) $col_map['tier'] = $idx;
            elseif ( strpos( $hdr, 'paid' ) !== false ) $col_map['paid'] = $idx;
        }

        // If strict headers fail, assume strict column index order 0-8
        if ( $col_map['archer'] === -1 || $col_map['line_day'] === -1 ) {
            $col_map = array(
                'contact' => 0, 'phone' => 1, 'email' => 2, 'archer' => 3,
                'line_day' => 4, 'line_time' => 5, 'distance' => 6, 'tier' => 7, 'paid' => 8
            );
        }

        // Prepare context
        $t_profiles       = function_exists( 'sywat_get_tournament_pricing_profiles' ) ? sywat_get_tournament_pricing_profiles( $t_data ) : array();
        $default_prof     = ! empty( $t_profiles ) ? reset( $t_profiles ) : array();
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';
        
        $closed_lines_raw = get_option( 'sywat_closed_lines_' . $t_slug, array() );
        $closed_lines     = is_array( $closed_lines_raw ) ? array_map( 'trim', array_map( 'strval', $closed_lines_raw ) ) : array();

        $dup_lookup       = array();
        $line_dist_counts = array();
        foreach ( $registrations as $ex ) {
            if ( isset( $ex['tournament'], $ex['line_index'], $ex['distance'], $ex['name'], $ex['status'] ) && strcasecmp( $ex['tournament'], $tourn_name ) === 0 ) {
                $ex_line = (string) $ex['line_index'];
                $ex_dist = (string) $ex['distance'];
                $dup_key = $ex_line . '|' . strtolower( $ex_dist ) . '|' . strtolower( trim( $ex['name'] ) );
                $dup_lookup[ $dup_key ] = true;

                if ( strcasecmp( $ex['status'], 'Waitlist' ) !== 0 ) {
                    if ( ! isset( $line_dist_counts[ $ex_line ][ $ex_dist ] ) ) $line_dist_counts[ $ex_line ][ $ex_dist ] = 0;
                    $line_dist_counts[ $ex_line ][ $ex_dist ]++;
                }
            }
        }

        // Map configured tournament lines to easily match Day + Time
        $valid_lines_map = array();
        foreach ( $t_lines as $idx => $ld ) {
            $l_name = isset( $ld['name'] ) ? trim( $ld['name'] ) : '';
            $day    = isset( $ld['day'] ) ? trim( $ld['day'] ) : '';
            $time   = isset( $ld['time'] ) ? trim( $ld['time'] ) : ( isset( $ld['start_time'] ) ? trim( $ld['start_time'] ) : '' );
            
            $match_str = strtolower( preg_replace( '/\s+/', '', $l_name . $day . $time ) );
            $valid_lines_map[ (string) $idx ] = array(
                'raw_name'  => $l_name,
                'match_str' => $match_str,
                'data'      => $ld
            );
        }

        $parsed_shooters = array();
        
        // Pass 1: Strict Validation & Parsing
        for ( $i = 1; $i < count( $raw_rows ); $i++ ) {
            $row = $raw_rows[$i];
            $safe_val = function($key) use ($row, $col_map) {
                return isset( $row[ $col_map[$key] ] ) ? trim( sanitize_text_field( (string) $row[ $col_map[$key] ] ) ) : '';
            };

            $contact_name = $safe_val('contact');
            $phone_raw    = $safe_val('phone');
            $email        = $safe_val('email');
            $archer_name  = $safe_val('archer');
            $line_day     = $safe_val('line_day');
            $line_time    = $safe_val('line_time');
            $distance     = $safe_val('distance');
            $tier         = $safe_val('tier');
            $paid_status  = $safe_val('paid');

            if ( empty( $archer_name ) ) continue; // Skip truly empty trailing rows

            // Required Fields
            if ( empty( $contact_name ) || empty( $phone_raw ) || empty( $line_day ) || empty( $line_time ) || empty( $distance ) || empty( $paid_status ) ) {
                $errors[] = "Row " . ($i + 1) . ": Missing mandatory column data. All columns except Email are required.";
                continue;
            }

            // Normalize Phone (10 digits)
            $phone_digits = preg_replace( '/\D/', '', $phone_raw );
            if ( strlen( $phone_digits ) > 10 && substr( $phone_digits, 0, 1 ) === '1' ) {
                $phone_digits = substr( $phone_digits, 1 ); // Strip leading 1
            }
            if ( strlen( $phone_digits ) < 10 ) {
                $errors[] = "Row " . ($i + 1) . ": Phone number must contain at least 10 digits.";
            }

            // Normalize Distance & Tier (10yd strictly locks Youth)
            $is_cub = ( stripos( $distance, '10' ) !== false || stripos( $distance, 'cub' ) !== false );
            $dist_val = $is_cub ? 'Cub' : '20yd';
            $tier_val = $is_cub ? 'Youth' : ( stripos( $tier, 'youth' ) !== false ? 'Youth' : 'Adult' );
            
            // Normalize Payment
            $is_paid = ( stripos( $paid_status, 'unpaid' ) === false && stripos( $paid_status, 'paid' ) !== false );

            // Match Line Day + Time to Tournament Line
            $search_target = strtolower( preg_replace( '/\s+/', '', $line_day . $line_time ) );
            $matched_idx = '';
            $matched_name = '';
            foreach ( $valid_lines_map as $idx => $l_meta ) {
                if ( strpos( $l_meta['match_str'], $search_target ) !== false || strpos( $search_target, $l_meta['match_str'] ) !== false ) {
                    $matched_idx = $idx;
                    $matched_name = $l_meta['raw_name'] !== '' ? $l_meta['raw_name'] : $line_day . ' ' . $line_time;
                    break;
                }
            }

            if ( $matched_idx === '' ) {
                $errors[] = "Row " . ($i + 1) . ": Invalid Line Day/Time combination ('{$line_day} {$line_time}'). Does not match any line configured on this tournament.";
            }

            // Automatic Family Grouping Key (Phone -> Email -> Contact Name)
            $family_key = $phone_digits !== '' ? $phone_digits : ( $email !== '' ? strtolower( $email ) : strtolower( $contact_name ) );

            $parsed_shooters[] = array(
                'row_num'      => $i + 1,
                'contact_name' => $contact_name,
                'phone'        => $phone_raw,
                'email'        => $email,
                'archer_name'  => $archer_name,
                'line_idx'     => $matched_idx,
                'line_name'    => $matched_name,
                'distance'     => $dist_val,
                'rate_tier'    => $tier_val,
                'is_paid'      => $is_paid,
                'family_key'   => $family_key
            );
        }

        // Halt on Validation Errors
        if ( ! empty( $errors ) ) {
            $GLOBALS['sywat_import_modal_error'] = '<strong>Pre-Flight Validation Failed:</strong><br><ul style="margin-top:8px; padding-left:20px;"><li>' . implode( '</li><li>', array_map( 'esc_html', array_slice( $errors, 0, 10 ) ) ) . '</li></ul>' . ( count( $errors ) > 10 ? '<br>...and more.' : '' );
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Import aborted due to formatting errors.</div>';
        }

        // Pass 2: Price Calculation, Waitlist Logic, and Save
        foreach ( $parsed_shooters as $s ) {
            $line_idx  = $s['line_idx'];
            $dist_val  = $s['distance'];
            $tier_val  = $s['rate_tier'];
            $dup_key   = $line_idx . '|' . strtolower( $dist_val ) . '|' . strtolower( $s['archer_name'] );

            if ( isset( $dup_lookup[ $dup_key ] ) ) {
                $skipped_dup++;
                continue;
            }

            // Directory lookup for exact age division / gender (Optional enrichment)
            $resolved = function_exists( 'sywat_resolve_archer_intake_profile' )
                ? sywat_resolve_archer_intake_profile( $s['archer_name'], $tourn_date )
                : array();
            
            $final_div = ! empty( $resolved['division'] ) ? $resolved['division'] : ( $dist_val === 'Cub' ? 'Cub (8 - 11)' : ( $tier_val === 'Youth' ? 'Youth (12 - 14)' : 'Adult (18 - 49)' ) );
            if ( function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
                $final_div = sywat_shooting_styles_normalize_division( $final_div );
            }

            // Fee Math
            $shooter_due = 0.00;
            if ( ! $s['is_paid'] && function_exists( 'sywat_get_archer_individual_fee' ) ) {
                $shooter_due = sywat_get_archer_individual_fee( $t_data, $final_div, $dist_val, '', $default_prof_key, $tier_val );
            }

            // Capacity Check
            $line_data = isset( $t_lines[ $line_idx ] ) ? $t_lines[ $line_idx ] : array();
            $is_line_closed = in_array( $line_idx, $closed_lines, true );
            $current_count  = isset( $line_dist_counts[ $line_idx ][ $dist_val ] ) ? $line_dist_counts[ $line_idx ][ $dist_val ] : 0;
            $max_capacity   = function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $line_data, $dist_val ) : ( $dist_val === 'Cub' ? 5 : 20 );
            
            $is_waitlist = ( $is_line_closed || $current_count >= $max_capacity );

            $new_reg = array(
                'id'              => uniqid( 'reg_' ),
                'tournament'      => $tourn_name,
                'line_index'      => $line_idx,
                'line_name'       => $s['line_name'],
                'name'            => $s['archer_name'],
                'phone'           => $s['phone'],
                'email'           => $s['email'],
                'distance'        => $dist_val,
                'division'        => $final_div,
                'gender'          => isset( $resolved['gender'] ) ? $resolved['gender'] : '',
                'style'           => isset( $resolved['style'] ) ? $resolved['style'] : '',
                'pricing_profile' => $default_prof_key,
                'is_promo_free'   => false,
                'paid'            => $is_waitlist ? 'Waitlist' : $tier_val,
                'booking_id'      => '',
                'family_id'       => $s['family_key'],
                'balance_due'     => $is_waitlist ? 0.00 : $shooter_due,
                'status'          => $is_waitlist ? 'Waitlist' : 'Pre-Reg',
                'created_at'      => current_time( 'mysql' ),
            );

            if ( function_exists( 'sywat_waitlist_persist_record' ) ) {
                sywat_waitlist_persist_record( $new_reg, $registrations );
            } else {
                update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                $registrations[] = $new_reg;
            }

            $dup_lookup[ $dup_key ] = true;

            if ( $is_waitlist ) {
                $waitlisted_count++;
            } else {
                if ( ! isset( $line_dist_counts[ $line_idx ][ $dist_val ] ) ) $line_dist_counts[ $line_idx ][ $dist_val ] = 0;
                $line_dist_counts[ $line_idx ][ $dist_val ]++;

                $touched_fams[ $line_idx . '___' . $s['family_key'] ] = array(
                    'line_idx'  => $line_idx,
                    'family_id' => $s['family_key'],
                );
                $imported_count++;
            }
        }

        // Pass 3: Reconcile per-line Family Caps automatically for any touched households
        if ( ! empty( $touched_fams ) && function_exists( 'sywat_waitlist_recalculate_line_family_fees' ) ) {
            foreach ( $touched_fams as $tf ) {
                sywat_waitlist_recalculate_line_family_fees( $tourn_name, $tf['line_idx'], $tf['family_id'], $registrations, $t_lines );
            }
        }

        if ( $imported_count === 0 && $waitlisted_count === 0 ) {
            $warn = 'No new shooters were imported. ';
            $warn .= ( $skipped_dup > 0 ) ? "Skipped {$skipped_dup} duplicate(s)." : "Please verify your template data.";
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">' . esc_html( $warn ) . '</div>';
        }

        $msg_parts = array();
        if ( $imported_count > 0 )   $msg_parts[] = "Successfully imported {$imported_count} shooter(s) as Pre-Reg.";
        if ( $waitlisted_count > 0 ) $msg_parts[] = "Added {$waitlisted_count} over-capacity shooter(s) to the Waitlist ($0.00 due).";
        if ( $skipped_dup > 0 )      $msg_parts[] = "Skipped {$skipped_dup} duplicate(s).";

        $alert_class = ( $imported_count > 0 ) ? 'sywat-alert-success' : 'sywat-alert-warning';
        return '<div id="sywat-notice" class="sywat-alert ' . $alert_class . '">' . esc_html( implode( ' ', $msg_parts ) ) . '</div>';
    }
}
