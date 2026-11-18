<?php
/**
 * Sub-Module: Bulk Spreadsheet Import & Template Download Handler (.xlsx, .csv)
 * Path: includes/registration/registration-import.php
 * Description: Handles tournament-aware 8-column .xlsx template downloads on template_redirect and processes strict 8-column roster imports (with 3-tier Category / Rate selection: Cub 10yd, Youth 20yd, Adult 20yd) using hoisted O(1) lookups, canonical shorthand/full line-time matching, canonical official archer name & verified division resolution (Member DB + 18-month TFAA history) without guessing divisions for unmatched shooters, First+Last duplicate prevention, deterministic household grouping, Paid/Unpaid Check-In mapping, Waitlist overflow, and atomic MySQL transaction batching.
 * Compliance: Pure action/import layer — delegates OpenXML/ZIP operations to sywat-spreadsheet-helpers.php, zero output at include time, zero inline styles, O(1) hashtable lookups, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Defensive fallback include in case registration-import.php is loaded before sywat-archery-manager.php is updated
if ( ! function_exists( 'sywat_build_import_template_xlsx' ) && defined( 'SYWAT_PLUGIN_DIR' ) ) {
    $sywat_sheet_helper = SYWAT_PLUGIN_DIR . 'includes/common/sywat-spreadsheet-helpers.php';
    if ( file_exists( $sywat_sheet_helper ) ) {
        require_once $sywat_sheet_helper;
    }
}

/**
 * Intercept and stream the tournament-aware Excel (.xlsx) import template (with .csv fallback).
 * Runs on template_redirect prior to theme output, guarded by nonce and desk capability.
 */
add_action( 'template_redirect', 'sywat_handle_download_import_template' );
if ( ! function_exists( 'sywat_handle_download_import_template' ) ) {
    function sywat_handle_download_import_template() {
        if ( ! isset( $_GET['sywat_download_import_template'] ) || wp_unslash( $_GET['sywat_download_import_template'] ) !== '1' ) {
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'sywat_download_import_template' ) ) {
            wp_die( 'Security token expired. Please refresh the page and try again.', 'Security Check', array( 'response' => 403 ) );
        }

        if ( ! function_exists( 'sywat_can_access_desk' ) || ! sywat_can_access_desk() ) {
            wp_die( 'Access denied. Desk management access required.', 'Access Denied', array( 'response' => 403 ) );
        }

        $tourn_name  = isset( $_GET['selected_tournament'] ) ? sanitize_text_field( wp_unslash( $_GET['selected_tournament'] ) ) : '';
        $tournaments = get_option( 'sywat_tournaments_list', array() );
        $tourn_data  = ( is_array( $tournaments ) && isset( $tournaments[ $tourn_name ] ) ) ? $tournaments[ $tourn_name ] : array();
        $t_lines     = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $tourn_data ) : array();
        $base_slug   = $tourn_name !== '' ? sanitize_title( $tourn_name ) : 'General';

        // Resolve active tournament pricing summary for the Tournament Info reference tab
        $t_profiles   = function_exists( 'sywat_get_tournament_pricing_profiles' ) ? sywat_get_tournament_pricing_profiles( $tourn_data ) : array();
        $default_prof = ! empty( $t_profiles ) ? reset( $t_profiles ) : array();
        $pricing_info = array(
            'adult_rate' => isset( $default_prof['adult_rate'] ) ? number_format( (float) $default_prof['adult_rate'], 2 ) : '30.00',
            'youth_rate' => isset( $default_prof['youth_rate'] ) ? number_format( (float) $default_prof['youth_rate'], 2 ) : '25.00',
            'family_cap' => ( ! empty( $default_prof['family_cap_enabled'] ) && isset( $default_prof['family_cap_amount'] ) )
                ? '$' . number_format( (float) $default_prof['family_cap_amount'], 2 ) . ' (per shooting line)'
                : 'Disabled',
        );

        // Primary Output: Native 8-column .xlsx workbook with strict Excel Data Validation dropdowns
        if ( class_exists( 'ZipArchive' ) && function_exists( 'sywat_build_import_template_xlsx' ) ) {
            $tmp_file = wp_tempnam( 'sywat_template_' . $base_slug . '.xlsx' );
            if ( $tmp_file && sywat_build_import_template_xlsx( $tmp_file, $tourn_name, $tourn_data, $t_lines, $pricing_info ) ) {
                while ( ob_get_level() > 0 ) {
                    ob_end_clean();
                }
                $filename = 'SYWAT_Import_Template_' . $base_slug . '.xlsx';
                header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
                header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
                header( 'Content-Length: ' . filesize( $tmp_file ) );
                header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
                header( 'Pragma: no-cache' );
                header( 'Expires: 0' );
                readfile( $tmp_file );
                if ( file_exists( $tmp_file ) ) {
                    wp_delete_file( $tmp_file );
                }
                exit;
            }
            if ( $tmp_file && file_exists( $tmp_file ) ) {
                wp_delete_file( $tmp_file );
            }
        }

        // Fallback Output: Standardized 8-column .csv if PHP ZipArchive is unavailable
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        $filename = 'SYWAT_Import_Template_' . $base_slug . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $headers = function_exists( 'sywat_get_import_template_headers' )
            ? sywat_get_import_template_headers()
            : array(
                'Contact Name',
                'Phone',
                'Email',
                'Archer Name',
                'Line Day (Friday / Saturday / Sunday)',
                'Line Time (HH:MM AM/PM)',
                'Category / Rate (Cub (10yd) / Youth (20yd) / Adult (20yd))',
                'Paid Status (Paid / Unpaid)',
            );

        $output = fopen( 'php://output', 'w' );
        if ( $output !== false ) {
            fputcsv( $output, $headers );
            fputcsv( $output, array(
                'John Smith',
                '817-555-1234',
                'john@example.com',
                'John Smith',
                'Saturday',
                '10:00 AM',
                'Adult (20yd)',
                'Paid',
            ) );
            fclose( $output );
        }
        exit;
    }
}

/**
 * Validate, group, price, and persist shooters uploaded via the 8-column spreadsheet modal (.xlsx or .csv).
 *
 * @param string $tourn_name       Selected tournament name (unslashed).
 * @param string $target_line_idx  Fallback line index (unused when spreadsheet specifies Line Day & Line Time).
 * @param array  $tournaments      Master tournament configuration array.
 * @param array  $registrations    Master registration state array reference.
 * @return string                  Standardized alert HTML notice.
 */
if ( ! function_exists( 'sywat_handle_import_shooters' ) ) {
    function sywat_handle_import_shooters( $tourn_name, $target_line_idx, $tournaments, &$registrations ) {
        global $wpdb;

        $tourn_name = sanitize_text_field( wp_unslash( $tourn_name ) );
        $t_data     = isset( $tournaments[ $tourn_name ] ) ? $tournaments[ $tourn_name ] : array();
        $tourn_date = ( is_array( $t_data ) && ! empty( $t_data['start_date'] ) ) ? (string) $t_data['start_date'] : '';
        $t_lines    = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : ( isset( $t_data['lines'] ) ? $t_data['lines'] : $t_data );
        $t_slug     = sanitize_title( $tourn_name );

        // 1. Validate Uploaded File
        if ( ! isset( $_FILES['import_file'] ) || ! is_array( $_FILES['import_file'] ) ) {
            $GLOBALS['sywat_import_modal_error'] = 'No spreadsheet file was received. Please select a .xlsx or .csv file and try again.';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Import aborted: No file uploaded.</div>';
        }

        $upload_err = isset( $_FILES['import_file']['error'] ) ? (int) $_FILES['import_file']['error'] : UPLOAD_ERR_NO_FILE;
        if ( $upload_err !== UPLOAD_ERR_OK ) {
            $err_msg = 'File upload failed (error code ' . $upload_err . '). Please try again.';
            if ( $upload_err === UPLOAD_ERR_NO_FILE ) {
                $err_msg = 'Please choose a .xlsx or .csv file before submitting.';
            } elseif ( $upload_err === UPLOAD_ERR_INI_SIZE || $upload_err === UPLOAD_ERR_FORM_SIZE ) {
                $err_msg = 'The selected file exceeds the server upload limit.';
            }
            $GLOBALS['sywat_import_modal_error'] = esc_html( $err_msg );
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $err_msg ) . '</div>';
        }

        $tmp_path = isset( $_FILES['import_file']['tmp_name'] ) ? (string) $_FILES['import_file']['tmp_name'] : '';
        if ( $tmp_path === '' || ! is_uploaded_file( $tmp_path ) ) {
            $GLOBALS['sywat_import_modal_error'] = 'Invalid file upload source. Please choose the file again and retry.';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Invalid file upload source.</div>';
        }

        $orig_name = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['import_file']['name'] ) ) : '';
        $ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'xlsx', 'csv' ), true ) ) {
            $GLOBALS['sywat_import_modal_error'] = 'Unsupported file type. Please upload an Excel (.xlsx) or CSV (.csv) file using the downloadable template.';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Unsupported spreadsheet format.</div>';
        }

        // 2. Parse Raw Rows
        $raw_rows = array();
        if ( $ext === 'xlsx' ) {
            if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'sywat_parse_simple_xlsx' ) ) {
                $GLOBALS['sywat_import_modal_error'] = 'Your server is missing the PHP ZipArchive extension required to read .xlsx files. Please save the template as .csv and upload it instead.';
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">PHP ZipArchive extension unavailable.</div>';
            }
            $raw_rows = sywat_parse_simple_xlsx( $tmp_path );
        } else {
            $handle = fopen( $tmp_path, 'r' );
            if ( $handle !== false ) {
                while ( ( $data = fgetcsv( $handle, 4000, ',' ) ) !== false ) {
                    if ( isset( $data[0] ) ) {
                        $data[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $data[0] );
                    }
                    $non_empty = array_filter( $data, function( $cell ) {
                        return trim( (string) $cell ) !== '';
                    } );
                    if ( ! empty( $non_empty ) ) {
                        $raw_rows[] = $data;
                    }
                }
                fclose( $handle );
            }
        }

        if ( count( $raw_rows ) < 2 ) {
            $GLOBALS['sywat_import_modal_error'] = 'The uploaded spreadsheet has no archer rows. Please enter at least one archer below the header row.';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Spreadsheet contains no data rows.</div>';
        }

        // 3. Resolve Strict 8-Column Header Map (Columns A–H / index 0–7)
        $headers = array_map( 'trim', array_map( 'strtolower', $raw_rows[0] ) );
        $col_map = array(
            'contact'   => -1,
            'phone'     => -1,
            'email'     => -1,
            'archer'    => -1,
            'line_day'  => -1,
            'line_time' => -1,
            'category'  => -1,
            'paid'      => -1,
        );

        foreach ( $headers as $idx => $hdr ) {
            if ( strpos( $hdr, 'contact' ) !== false ) {
                $col_map['contact'] = $idx;
            } elseif ( strpos( $hdr, 'phone' ) !== false ) {
                $col_map['phone'] = $idx;
            } elseif ( strpos( $hdr, 'email' ) !== false ) {
                $col_map['email'] = $idx;
            } elseif ( strpos( $hdr, 'archer' ) !== false || strpos( $hdr, 'full name' ) !== false ) {
                $col_map['archer'] = $idx;
            } elseif ( strpos( $hdr, 'line day' ) !== false ) {
                $col_map['line_day'] = $idx;
            } elseif ( strpos( $hdr, 'line time' ) !== false ) {
                $col_map['line_time'] = $idx;
            } elseif ( strpos( $hdr, 'category' ) !== false || strpos( $hdr, 'rate' ) !== false ) {
                $col_map['category'] = $idx;
            } elseif ( strpos( $hdr, 'paid' ) !== false ) {
                $col_map['paid'] = $idx;
            }
        }

        if ( $col_map['archer'] === -1 || $col_map['line_day'] === -1 || $col_map['line_time'] === -1 || $col_map['category'] === -1 ) {
            $col_map = array(
                'contact'   => 0,
                'phone'     => 1,
                'email'     => 2,
                'archer'    => 3,
                'line_day'  => 4,
                'line_time' => 5,
                'category'  => 6,
                'paid'      => 7,
            );
        }

        // 4. Hoist All Lookups & O(1) Hashtables Outside Iteration Loops
        $t_profiles       = function_exists( 'sywat_get_tournament_pricing_profiles' ) ? sywat_get_tournament_pricing_profiles( $t_data ) : array();
        $default_prof     = ! empty( $t_profiles ) ? reset( $t_profiles ) : array();
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';

        $closed_lines_raw = get_option( 'sywat_closed_lines_' . $t_slug, array() );
        $closed_lines_map = is_array( $closed_lines_raw ) ? array_flip( array_map( 'trim', array_map( 'strval', $closed_lines_raw ) ) ) : array();

        $has_match_keys_fn = function_exists( 'sywat_normalize_archer_match_keys' );
        $build_match_keys  = function( $raw_name ) use ( $has_match_keys_fn ) {
            if ( $has_match_keys_fn ) {
                return sywat_normalize_archer_match_keys( $raw_name );
            }
            $norm  = strtolower( trim( preg_replace( '/[[:space:]]+/', ' ', str_replace( '.', ' ', (string) $raw_name ) ) ) );
            $parts = $norm !== '' ? explode( ' ', $norm ) : array();
            return array(
                'exact' => $norm,
                'fl'    => ( count( $parts ) >= 2 ) ? ( reset( $parts ) . '___' . end( $parts ) ) : $norm,
            );
        };

        // O(1) Duplicate & Active Line Occupancy Maps (indexed by both Exact and First+Last keys)
        $dup_lookup       = array();
        $line_dist_counts = array();
        if ( is_array( $registrations ) ) {
            foreach ( $registrations as $ex ) {
                if ( ! is_array( $ex ) || ! isset( $ex['tournament'], $ex['line_index'], $ex['name'], $ex['status'] ) ) {
                    continue;
                }
                if ( strcasecmp( trim( (string) $ex['tournament'] ), trim( $tourn_name ) ) !== 0 ) {
                    continue;
                }
                $ex_status = trim( (string) $ex['status'] );
                if ( strcasecmp( $ex_status, 'Cancelled' ) === 0 ) {
                    continue;
                }
                $ex_line = trim( (string) $ex['line_index'] );
                $ex_dist = isset( $ex['distance'] ) ? trim( (string) $ex['distance'] ) : '20yd';
                $ex_keys = $build_match_keys( $ex['name'] );

                if ( $ex_keys['exact'] !== '' ) {
                    $dup_lookup[ $ex_line . '|' . strtolower( $ex_dist ) . '|ex|' . $ex_keys['exact'] ] = true;
                }
                if ( $ex_keys['fl'] !== '' && strpos( $ex_keys['fl'], '___' ) !== false ) {
                    $dup_lookup[ $ex_line . '|' . strtolower( $ex_dist ) . '|fl|' . $ex_keys['fl'] ] = true;
                }

                if ( strcasecmp( $ex_status, 'Waitlist' ) !== 0 ) {
                    if ( ! isset( $line_dist_counts[ $ex_line ][ $ex_dist ] ) ) {
                        $line_dist_counts[ $ex_line ][ $ex_dist ] = 0;
                    }
                    $line_dist_counts[ $ex_line ][ $ex_dist ]++;
                }
            }
        }

        // Canonical time normalizer: unifies '10AM', '10:00 AM', '06:30 PM', '6:30PM' -> '1000am', '630pm'
        $has_norm_time_fn     = function_exists( 'sywat_normalize_import_line_time' );
        $canonical_time_token = function( $raw_time ) use ( $has_norm_time_fn ) {
            $base = $has_norm_time_fn
                ? (string) sywat_normalize_import_line_time( $raw_time )
                : strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $raw_time ) );
            if ( preg_match( '/^0*(\d{1,2})(\d{2})?(am|pm)$/', $base, $m ) ) {
                $hr   = (string) intval( $m[1] );
                $mins = ( isset( $m[2] ) && $m[2] !== '' ) ? $m[2] : '00';
                return $hr . $mins . $m[3];
            }
            return $base;
        };

        // O(1) Allowed Line Day Map (Friday, Saturday, Sunday)
        $allowed_days_map = array(
            'friday'   => 'Friday',
            'saturday' => 'Saturday',
            'sunday'   => 'Sunday',
        );

        // O(1) Allowed 30-Minute Time Slots Map (7:00 AM – 10:00 PM)
        $valid_time_tokens = array();
        $standard_slots    = function_exists( 'sywat_get_standard_import_time_slots' ) ? sywat_get_standard_import_time_slots() : array();
        foreach ( $standard_slots as $slot_label ) {
            $tok = $canonical_time_token( $slot_label );
            if ( $tok !== '' ) {
                $valid_time_tokens[ $tok ] = $slot_label;
            }
        }

        // O(1) Configured Tournament Line Token Lookup & Line Capacity Cache
        $line_token_to_idx = array();
        $line_meta_by_idx  = array();
        if ( is_array( $t_lines ) ) {
            foreach ( $t_lines as $idx => $ld ) {
                $idx_str = (string) $idx;
                $l_name  = is_array( $ld ) && isset( $ld['name'] ) ? trim( (string) $ld['name'] ) : '';
                $day     = is_array( $ld ) && isset( $ld['day'] ) ? trim( (string) $ld['day'] ) : '';
                $time    = is_array( $ld ) && isset( $ld['time'] )
                    ? trim( (string) $ld['time'] )
                    : ( is_array( $ld ) && isset( $ld['start_time'] ) ? trim( (string) $ld['start_time'] ) : '' );

                $cap_20  = function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $ld, '20yd' ) : 20;
                $cap_cub = function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $ld, 'Cub' ) : 5;

                $line_meta_by_idx[ $idx_str ] = array(
                    'name'      => $l_name,
                    'is_closed' => isset( $closed_lines_map[ $idx_str ] ) || ( $l_name !== '' && isset( $closed_lines_map[ $l_name ] ) ),
                    'cap_20yd'  => ( $cap_20 && $cap_20 > 0 ) ? (int) $cap_20 : 20,
                    'cap_Cub'   => ( $cap_cub && $cap_cub > 0 ) ? (int) $cap_cub : 5,
                );

                if ( $day !== '' && $time !== '' ) {
                    $t_tok = $canonical_time_token( $time );
                    $key1  = strtolower( preg_replace( '/[^a-z0-9]/i', '', $day ) ) . $t_tok;
                    $line_token_to_idx[ $key1 ] = $idx_str;
                }
                if ( $l_name !== '' ) {
                    if ( preg_match( '/^(friday|saturday|sunday)\s+(.+)$/i', $l_name, $lm ) ) {
                        $d_tok  = strtolower( $lm[1] );
                        $t_tok2 = $canonical_time_token( $lm[2] );
                        $line_token_to_idx[ $d_tok . $t_tok2 ] = $idx_str;
                    }
                    $raw_lbl_tok = strtolower( preg_replace( '/[^a-z0-9]/i', '', $l_name ) );
                    if ( $raw_lbl_tok !== '' ) {
                        $line_token_to_idx[ $raw_lbl_tok ] = $idx_str;
                    }
                }
            }
        }

        // O(1) Allowed Category / Rate Map
        $category_map = array(
            'cub10yd'    => array( 'distance' => 'Cub',  'rate_tier' => 'Youth' ),
            'cub10yds'   => array( 'distance' => 'Cub',  'rate_tier' => 'Youth' ),
            'cub'        => array( 'distance' => 'Cub',  'rate_tier' => 'Youth' ),
            'youth20yd'  => array( 'distance' => '20yd', 'rate_tier' => 'Youth' ),
            'youth20yds' => array( 'distance' => '20yd', 'rate_tier' => 'Youth' ),
            'youth'      => array( 'distance' => '20yd', 'rate_tier' => 'Youth' ),
            'adult20yd'  => array( 'distance' => '20yd', 'rate_tier' => 'Adult' ),
            'adult20yds' => array( 'distance' => '20yd', 'rate_tier' => 'Adult' ),
            'adult'      => array( 'distance' => '20yd', 'rate_tier' => 'Adult' ),
        );

        // Pass 1: Pre-Flight Validation & Normalization
        $parsed_shooters = array();
        $errors          = array();
        $total_rows      = count( $raw_rows );

        for ( $i = 1; $i < $total_rows; $i++ ) {
            $row      = $raw_rows[ $i ];
            $row_num  = $i + 1;
            $safe_val = function( $key ) use ( $row, $col_map ) {
                return isset( $row[ $col_map[ $key ] ] ) ? trim( sanitize_text_field( (string) $row[ $col_map[ $key ] ] ) ) : '';
            };

            $contact_name = $safe_val( 'contact' );
            $phone_raw    = $safe_val( 'phone' );
            $email        = $safe_val( 'email' );
            $archer_name  = $safe_val( 'archer' );
            $line_day     = $safe_val( 'line_day' );
            $line_time    = $safe_val( 'line_time' );
            $category_raw = $safe_val( 'category' );
            $paid_status  = $safe_val( 'paid' );

            // Skip blank pre-formatted template rows
            if ( $archer_name === '' && $contact_name === '' && $phone_raw === '' ) {
                continue;
            }

            if ( $contact_name === '' || $phone_raw === '' || $archer_name === '' || $line_day === '' || $line_time === '' || $category_raw === '' || $paid_status === '' ) {
                $errors[] = 'Row ' . $row_num . ': Missing mandatory column data. All columns except Email are required.';
                continue;
            }

            // Phone validation (10 digits minimum)
            $phone_digits = preg_replace( '/\D/', '', $phone_raw );
            if ( strlen( $phone_digits ) > 10 && substr( $phone_digits, 0, 1 ) === '1' ) {
                $phone_digits = substr( $phone_digits, 1 );
            }
            if ( strlen( $phone_digits ) < 10 ) {
                $errors[] = 'Row ' . $row_num . ': Phone number must contain at least 10 digits.';
            }

            // Optional Email validation
            if ( $email !== '' && ! is_email( $email ) ) {
                $errors[] = 'Row ' . $row_num . ": Invalid Email address ('{$email}'). Leave blank or enter a valid email.";
            }

            // Line Day validation (Friday, Saturday, Sunday)
            $day_lower = strtolower( $line_day );
            if ( ! isset( $allowed_days_map[ $day_lower ] ) ) {
                $errors[] = 'Row ' . $row_num . ": Invalid Line Day ('{$line_day}'). Must be Friday, Saturday, or Sunday.";
            }

            // Line Time validation (30-minute increments 7:00 AM–10:00 PM + configured on active tournament)
            $time_token = $canonical_time_token( $line_time );
            $disp_time  = function_exists( 'sywat_format_template_line_time' ) ? sywat_format_template_line_time( $line_time ) : $line_time;

            if ( ! empty( $valid_time_tokens ) && ! isset( $valid_time_tokens[ $time_token ] ) ) {
                $errors[] = 'Row ' . $row_num . ": Invalid Line Time ('{$disp_time}'). Must be on a 30-minute increment between 7:00 AM and 10:00 PM.";
            }

            // Category / Rate validation: Cub (10yd), Youth (20yd), or Adult (20yd)
            $cat_token = strtolower( preg_replace( '/[^a-z0-9]/', '', $category_raw ) );
            if ( ! isset( $category_map[ $cat_token ] ) ) {
                $errors[] = 'Row ' . $row_num . ": Invalid Category / Rate ('{$category_raw}'). Must be 'Cub (10yd)', 'Youth (20yd)', or 'Adult (20yd)'.";
                $dist_val = '20yd';
                $tier_val = 'Adult';
            } else {
                $dist_val = $category_map[ $cat_token ]['distance'];
                $tier_val = $category_map[ $cat_token ]['rate_tier'];
            }

            // Paid Status validation (Paid or Unpaid)
            $paid_lower = strtolower( $paid_status );
            if ( $paid_lower !== 'paid' && $paid_lower !== 'unpaid' ) {
                $errors[] = 'Row ' . $row_num . ": Invalid Paid Status ('{$paid_status}'). Must be 'Paid' or 'Unpaid'.";
            }
            $is_paid = ( $paid_lower === 'paid' );

            // Match Line Day + Line Time to Configured Tournament Line in O(1)
            $day_token     = strtolower( preg_replace( '/[^a-z0-9]/i', '', $line_day ) );
            $search_target = $day_token . $time_token;
            $matched_idx   = isset( $line_token_to_idx[ $search_target ] ) ? $line_token_to_idx[ $search_target ] : '';
            $matched_name  = '';

            if ( $matched_idx === '' ) {
                $errors[] = 'Row ' . $row_num . ": Invalid Line Day/Time combination ('{$line_day} {$disp_time}'). Does not match any line configured on this tournament.";
            } else {
                $canonical_day = isset( $allowed_days_map[ $day_lower ] ) ? $allowed_days_map[ $day_lower ] : ucfirst( $day_lower );
                $matched_name  = $line_meta_by_idx[ $matched_idx ]['name'] !== ''
                    ? $line_meta_by_idx[ $matched_idx ]['name']
                    : trim( $canonical_day . ' ' . $disp_time );
            }

            // Deterministic Family Grouping Key (Phone -> Email -> Contact Name)
            $family_key = $phone_digits !== '' ? $phone_digits : ( $email !== '' ? strtolower( $email ) : strtolower( $contact_name ) );

            $parsed_shooters[] = array(
                'contact_name' => $contact_name,
                'phone'        => $phone_raw,
                'email'        => $email,
                'archer_name'  => $archer_name,
                'line_idx'     => $matched_idx,
                'line_name'    => $matched_name,
                'distance'     => $dist_val,
                'rate_tier'    => $tier_val,
                'is_paid'      => $is_paid,
                'family_key'   => $family_key,
            );
        }

        // Halt immediately on pre-flight validation errors (zero inline styles)
        if ( ! empty( $errors ) ) {
            $err_list = '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', array_slice( $errors, 0, 10 ) ) ) . '</li></ul>';
            if ( count( $errors ) > 10 ) {
                $err_list .= '<p>...and ' . ( count( $errors ) - 10 ) . ' more error(s).</p>';
            }
            $GLOBALS['sywat_import_modal_error'] = '<strong>Pre-Flight Validation Failed:</strong>' . $err_list;
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Import aborted due to formatting errors.</div>';
        }

        // Pass 2: Memoized Canonical Profile/Fee Resolution & Atomic Transactional Persistence
        $imported_count   = 0;
        $waitlisted_count = 0;
        $skipped_dup      = 0;
        $touched_fams     = array();
        $profile_cache    = array();
        $fee_cache        = array();
        $now_mysql        = current_time( 'mysql' );
        $has_persist_fn   = function_exists( 'sywat_waitlist_persist_record' );
        $has_resolve_fn   = function_exists( 'sywat_resolve_archer_intake_profile' );
        $has_norm_div_fn  = function_exists( 'sywat_shooting_styles_normalize_division' );
        $has_fee_fn       = function_exists( 'sywat_get_archer_individual_fee' );

        $wpdb->query( 'START TRANSACTION' );

        try {
            foreach ( $parsed_shooters as $s ) {
                $line_idx    = $s['line_idx'];
                $dist_val    = $s['distance'];
                $tier_val    = $s['rate_tier'];
                $profile_key = strtolower( trim( $s['archer_name'] ) ) . '|' . strtolower( trim( $s['email'] ) ) . '|' . preg_replace( '/\D+/', '', $s['phone'] );

                // Memoized Member Directory + 18-Month TFAA canonical resolution
                if ( ! isset( $profile_cache[ $profile_key ] ) ) {
                    $profile_cache[ $profile_key ] = $has_resolve_fn
                        ? sywat_resolve_archer_intake_profile(
                            $s['archer_name'],
                            $tourn_date,
                            array(
                                'email' => $s['email'],
                                'phone' => $s['phone'],
                            )
                        )
                        : array();
                }
                $resolved       = $profile_cache[ $profile_key ];
                $canonical_name = ! empty( $resolved['canonical_name'] ) ? trim( (string) $resolved['canonical_name'] ) : $s['archer_name'];
                $cand_keys      = $build_match_keys( $canonical_name );

                $ex_dup_key = $line_idx . '|' . strtolower( $dist_val ) . '|ex|' . $cand_keys['exact'];
                $fl_dup_key = ( $cand_keys['fl'] !== '' && strpos( $cand_keys['fl'], '___' ) !== false )
                    ? ( $line_idx . '|' . strtolower( $dist_val ) . '|fl|' . $cand_keys['fl'] )
                    : '';

                if ( isset( $dup_lookup[ $ex_dup_key ] ) || ( $fl_dup_key !== '' && isset( $dup_lookup[ $fl_dup_key ] ) ) ) {
                    $skipped_dup++;
                    continue;
                }

                // Use verified division from Member Directory / 18-Month TFAA history when it matches
                // the imported distance & rate tier; leave blank ('') for unmatched shooters so Check-In
                // resolves or prompts cleanly instead of guessing a default division.
                $final_div = '';
                if ( ! empty( $resolved['division'] ) ) {
                    $res_dist_ok = empty( $resolved['distance'] ) || $resolved['distance'] === $dist_val;
                    $res_tier_ok = empty( $resolved['rate_tier'] ) || $resolved['rate_tier'] === $tier_val;
                    if ( $res_dist_ok && $res_tier_ok ) {
                        $final_div = $has_norm_div_fn
                            ? sywat_shooting_styles_normalize_division( $resolved['division'] )
                            : trim( (string) $resolved['division'] );
                    }
                }

                // Memoized Fee Calculation (runs at most once per unique division + distance + tier)
                $shooter_due = 0.00;
                if ( ! $s['is_paid'] && $has_fee_fn ) {
                    $fee_key = $final_div . '|' . $dist_val . '|' . $tier_val;
                    if ( ! isset( $fee_cache[ $fee_key ] ) ) {
                        $fee_cache[ $fee_key ] = (float) sywat_get_archer_individual_fee( $t_data, $final_div, $dist_val, '', $default_prof_key, $tier_val );
                    }
                    $shooter_due = $fee_cache[ $fee_key ];
                }

                // O(1) Line Capacity & Closed-Line Check
                $l_meta        = $line_meta_by_idx[ $line_idx ];
                $current_count = isset( $line_dist_counts[ $line_idx ][ $dist_val ] ) ? $line_dist_counts[ $line_idx ][ $dist_val ] : 0;
                $max_capacity  = ( $dist_val === 'Cub' ) ? $l_meta['cap_Cub'] : $l_meta['cap_20yd'];
                $is_waitlist   = ( $l_meta['is_closed'] || $current_count >= $max_capacity );

                $new_reg = array(
                    'id'              => uniqid( 'reg_' ),
                    'tournament'      => $tourn_name,
                    'line_index'      => $line_idx,
                    'line_name'       => $s['line_name'],
                    'name'            => $canonical_name,
                    'phone'           => $s['phone'],
                    'email'           => $s['email'],
                    'distance'        => $dist_val,
                    'division'        => $final_div,
                    'gender'          => isset( $resolved['gender'] ) ? $resolved['gender'] : '',
                    'style'           => isset( $resolved['style'] ) ? $resolved['style'] : '',
                    'pricing_profile' => $default_prof_key,
                    'is_promo_free'   => false,
                    'paid'            => $is_waitlist ? 'Waitlist' : $tier_val,
                    'rate_type'       => $tier_val,
                    'booking_id'      => '',
                    'family_id'       => $s['family_key'],
                    'balance_due'     => $is_waitlist ? 0.00 : $shooter_due,
                    'status'          => $is_waitlist ? 'Waitlist' : 'Pre-Reg',
                    'created_at'      => $now_mysql,
                );

                if ( $has_persist_fn ) {
                    sywat_waitlist_persist_record( $new_reg, $registrations );
                } else {
                    update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                    $registrations[] = $new_reg;
                }

                $dup_lookup[ $ex_dup_key ] = true;
                if ( $fl_dup_key !== '' ) {
                    $dup_lookup[ $fl_dup_key ] = true;
                }

                if ( $is_waitlist ) {
                    $waitlisted_count++;
                } else {
                    if ( ! isset( $line_dist_counts[ $line_idx ][ $dist_val ] ) ) {
                        $line_dist_counts[ $line_idx ][ $dist_val ] = 0;
                    }
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

            $wpdb->query( 'COMMIT' );
        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            $GLOBALS['sywat_import_modal_error'] = 'Database error during batch import: ' . esc_html( $e->getMessage() );
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Import failed and was rolled back safely.</div>';
        }

        if ( $imported_count === 0 && $waitlisted_count === 0 ) {
            $warn  = 'No new shooters were imported. ';
            $warn .= ( $skipped_dup > 0 ) ? "Skipped {$skipped_dup} duplicate(s)." : 'Please verify your template data.';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-warning">' . esc_html( $warn ) . '</div>';
        }

        $msg_parts = array();
        if ( $imported_count > 0 ) {
            $msg_parts[] = "Successfully imported {$imported_count} shooter(s) as Pre-Reg.";
        }
        if ( $waitlisted_count > 0 ) {
            $msg_parts[] = "Added {$waitlisted_count} over-capacity shooter(s) to the Waitlist ($0.00 due).";
        }
        if ( $skipped_dup > 0 ) {
            $msg_parts[] = "Skipped {$skipped_dup} duplicate(s).";
        }

        $alert_class = ( $imported_count > 0 ) ? 'sywat-alert-success' : 'sywat-alert-warning';
        return '<div id="sywat-notice" class="sywat-alert ' . $alert_class . '">' . esc_html( implode( ' ', $msg_parts ) ) . '</div>';
    }
}
