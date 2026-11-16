<?php
/**
 * Sub-Module: Shop & Club Action Processing & CRUD Logic
 * Path: includes/shops/shops-actions.php
 * Description: Processes Shop & Club CRUD actions, CSV/XLSX dry-run imports, and staff linking with scoped Shop Manager self-service permissions.
 * Author: SYWAT Archery / TFAA
 * Version: 5.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_handle_shop_actions' ) ) {
    function sywat_handle_shop_actions() {
        if ( ! isset( $_POST['sywat_shop_action'] ) || ! isset( $_POST['sywat_nonce'] ) ) {
            return '';
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sywat_nonce'] ) ), 'sywat_desk_action' ) ) {
            return '';
        }

        $can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) ? sywat_can_manage_seasons() : current_user_can( 'administrator' );
        $can_manage_shops   = function_exists( 'sywat_can_manage_shops' ) ? sywat_can_manage_shops() : $can_manage_seasons;
        $user_id            = get_current_user_id();
        $assigned_shop_id   = $user_id > 0 ? trim( (string) get_user_meta( $user_id, 'sywat_shop_id', true ) ) : '';

        $action = sanitize_text_field( wp_unslash( $_POST['sywat_shop_action'] ) );
        $shops  = sywat_get_shops();

        // Helper: Sort shops array alphabetically by location name
        $sort_shops = function( &$shops_arr ) {
            if ( is_array( $shops_arr ) && ! empty( $shops_arr ) ) {
                uasort( $shops_arr, function( $a, $b ) {
                    $name_a = isset( $a['name'] ) ? $a['name'] : '';
                    $name_b = isset( $b['name'] ) ? $b['name'] : '';
                    return strcasecmp( $name_a, $name_b );
                });
            }
        };

        // UPDATE EXISTING SHOP / CLUB (Global Admins/Board OR Assigned Shop Manager for own location)
        if ( $action === 'update_shop' ) {
            $shop_id = isset( $_POST['shop_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_id'] ) ) : '';
            $can_edit_target = $can_manage_seasons || ( $can_manage_shops && $assigned_shop_id !== '' && $assigned_shop_id === $shop_id );

            if ( ! $can_edit_target ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. You do not have permission to edit this location.</div>';
            }

            if ( isset( $shops[$shop_id] ) ) {
                $facilities = isset( $_POST['facilities'] ) && is_array( $_POST['facilities'] )
                    ? array_map( 'sanitize_text_field', wp_unslash( $_POST['facilities'] ) )
                    : array();

                $shops[$shop_id]['name']          = isset( $_POST['shop_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_name'] ) ) : $shops[$shop_id]['name'];
                $shops[$shop_id]['location_type'] = isset( $_POST['location_type'] ) ? sanitize_text_field( wp_unslash( $_POST['location_type'] ) ) : 'pro_shop';
                $shops[$shop_id]['district']      = isset( $_POST['shop_district'] ) ? sywat_normalize_district( wp_unslash( $_POST['shop_district'] ) ) : '';
                $shops[$shop_id]['facilities']    = $facilities;
                $shops[$shop_id]['contact']       = isset( $_POST['shop_contact'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_contact'] ) ) : '';
                $shops[$shop_id]['contact_title'] = isset( $_POST['shop_contact_title'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_contact_title'] ) ) : '';
                $shops[$shop_id]['email']         = isset( $_POST['shop_email'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_email'] ) ) : '';
                $shops[$shop_id]['phone']         = isset( $_POST['shop_phone'] ) ? sywat_format_phone_number( wp_unslash( $_POST['shop_phone'] ) ) : '';
                $shops[$shop_id]['address_1']     = isset( $_POST['shop_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_address_1'] ) ) : '';
                $shops[$shop_id]['address_2']     = isset( $_POST['shop_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_address_2'] ) ) : '';
                $shops[$shop_id]['city']          = isset( $_POST['shop_city'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_city'] ) ) : '';
                $shops[$shop_id]['state']         = isset( $_POST['shop_state'] ) ? sywat_normalize_state_code( wp_unslash( $_POST['shop_state'] ) ) : 'TX - Texas';
                $shops[$shop_id]['zip']           = isset( $_POST['shop_zip'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_zip'] ) ) : '';

                // Process Additional Contacts Repeater
                $add_contacts = array();
                if ( ! empty( $_POST['add_contact_names'] ) && is_array( $_POST['add_contact_names'] ) ) {
                    $raw_c_names  = wp_unslash( $_POST['add_contact_names'] );
                    $raw_c_titles = isset( $_POST['add_contact_titles'] ) && is_array( $_POST['add_contact_titles'] ) ? wp_unslash( $_POST['add_contact_titles'] ) : array();
                    $raw_c_emails = isset( $_POST['add_contact_emails'] ) && is_array( $_POST['add_contact_emails'] ) ? wp_unslash( $_POST['add_contact_emails'] ) : array();
                    $raw_c_phones = isset( $_POST['add_contact_phones'] ) && is_array( $_POST['add_contact_phones'] ) ? wp_unslash( $_POST['add_contact_phones'] ) : array();

                    foreach ( $raw_c_names as $idx => $c_name ) {
                        $clean_name = trim( sanitize_text_field( $c_name ) );
                        if ( ! empty( $clean_name ) ) {
                            $add_contacts[] = array(
                                'name'  => $clean_name,
                                'title' => isset( $raw_c_titles[$idx] ) ? sanitize_text_field( $raw_c_titles[$idx] ) : '',
                                'email' => isset( $raw_c_emails[$idx] ) ? sanitize_text_field( $raw_c_emails[$idx] ) : '',
                                'phone' => isset( $raw_c_phones[$idx] ) ? sywat_format_phone_number( $raw_c_phones[$idx] ) : '',
                            );
                        }
                    }
                }
                $shops[$shop_id]['additional_contacts'] = $add_contacts;

                // Process SYWAT Indoor Lines
                if ( ! empty( $_POST['indoor_line_days'] ) && is_array( $_POST['indoor_line_days'] ) ) {
                    $raw_in_days  = wp_unslash( $_POST['indoor_line_days'] );
                    $raw_in_times = isset( $_POST['indoor_line_times'] ) && is_array( $_POST['indoor_line_times'] ) ? wp_unslash( $_POST['indoor_line_times'] ) : array();
                    $raw_cap_20   = isset( $_POST['cap_20yd'] ) && is_array( $_POST['cap_20yd'] ) ? wp_unslash( $_POST['cap_20yd'] ) : array();
                    $raw_cap_cub  = isset( $_POST['cap_cub'] ) && is_array( $_POST['cap_cub'] ) ? wp_unslash( $_POST['cap_cub'] ) : array();

                    $indoor_lines = array();
                    foreach ( $raw_in_days as $index => $day ) {
                        $clean_day  = trim( sanitize_text_field( $day ) );
                        $clean_time = isset( $raw_in_times[$index] ) ? trim( sanitize_text_field( $raw_in_times[$index] ) ) : '';
                        if ( ! empty( $clean_day ) && ! empty( $clean_time ) ) {
                            $indoor_lines[] = array(
                                'day'      => $clean_day,
                                'time'     => $clean_time,
                                'name'     => trim( $clean_day . ' ' . $clean_time ),
                                'cap_20yd' => isset( $raw_cap_20[$index] ) ? intval( $raw_cap_20[$index] ) : 20,
                                'cap_cub'  => isset( $raw_cap_cub[$index] ) ? intval( $raw_cap_cub[$index] ) : 5,
                            );
                        }
                    }
                    if ( ! empty( $indoor_lines ) ) {
                        $shops[$shop_id]['default_lines'] = $indoor_lines;
                    }
                }

                // Process SYWAT Outdoor Lines
                if ( ! empty( $_POST['outdoor_line_days'] ) && is_array( $_POST['outdoor_line_days'] ) ) {
                    $raw_out_days  = wp_unslash( $_POST['outdoor_line_days'] );
                    $raw_out_times = isset( $_POST['outdoor_line_times'] ) && is_array( $_POST['outdoor_line_times'] ) ? wp_unslash( $_POST['outdoor_line_times'] ) : array();

                    $outdoor_lines = array();
                    foreach ( $raw_out_days as $index => $day ) {
                        $clean_day  = trim( sanitize_text_field( $day ) );
                        $clean_time = isset( $raw_out_times[$index] ) ? trim( sanitize_text_field( $raw_out_times[$index] ) ) : '';
                        if ( ! empty( $clean_day ) && ! empty( $clean_time ) ) {
                            $outdoor_lines[] = array(
                                'day'  => $clean_day,
                                'time' => $clean_time,
                                'name' => trim( $clean_day . ' ' . $clean_time ),
                            );
                        }
                    }
                    $shops[$shop_id]['outdoor_lines'] = $outdoor_lines;
                }

                $sort_shops( $shops );
                update_option( 'sywat_shops_list', $shops );
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully updated details for <strong>' . esc_html( $shops[$shop_id]['name'] ) . '</strong>.</div>';
            }
        }

        // All remaining directory, import, deletion, and staff-linking actions require global season/directory authority
        if ( ! $can_manage_seasons ) {
            return '';
        }

        if ( $action === 'create_shop' ) {
            $shop_name = isset( $_POST['shop_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['shop_name'] ) ) ) : '';
            if ( empty( $shop_name ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Location Name is required.</div>';
            }
            $shop_slug = 'shop_' . sanitize_title( $shop_name );
            if ( isset( $shops[$shop_slug] ) ) {
                $shop_slug .= '_' . substr( md5( time() ), 0, 4 );
            }

            $default_indoor_lines = array(
                array( 'day' => 'Saturday', 'time' => '10:00 AM', 'name' => 'Saturday 10:00 AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'day' => 'Saturday', 'time' => '2:00 PM',  'name' => 'Saturday 2:00 PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'day' => 'Sunday',   'time' => '10:00 AM', 'name' => 'Sunday 10:00 AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'day' => 'Sunday',   'time' => '2:00 PM',  'name' => 'Sunday 2:00 PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
            );

            $default_outdoor_lines = array(
                array( 'day' => 'Saturday', 'time' => '9:00 AM', 'name' => 'Saturday 9:00 AM' ),
                array( 'day' => 'Sunday',   'time' => '9:00 AM', 'name' => 'Sunday 9:00 AM' ),
            );

            $facilities = isset( $_POST['facilities'] ) && is_array( $_POST['facilities'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['facilities'] ) )
                : array();
            $loc_type   = isset( $_POST['location_type'] ) ? sanitize_text_field( wp_unslash( $_POST['location_type'] ) ) : 'pro_shop';

            if ( empty( $facilities ) ) {
                $facilities = array( 'indoor_range' );
            }

            $shops[$shop_slug] = array(
                'id'                  => $shop_slug,
                'name'                => $shop_name,
                'location_type'       => $loc_type,
                'district'            => isset( $_POST['shop_district'] ) ? sywat_normalize_district( wp_unslash( $_POST['shop_district'] ) ) : '',
                'facilities'          => $facilities,
                'contact'             => isset( $_POST['shop_contact'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_contact'] ) ) : '',
                'contact_title'       => isset( $_POST['shop_contact_title'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_contact_title'] ) ) : '',
                'email'               => isset( $_POST['shop_email'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_email'] ) ) : '',
                'phone'               => isset( $_POST['shop_phone'] ) ? sywat_format_phone_number( wp_unslash( $_POST['shop_phone'] ) ) : '',
                'additional_contacts' => array(),
                'address_1'           => isset( $_POST['shop_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_address_1'] ) ) : '',
                'address_2'           => isset( $_POST['shop_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_address_2'] ) ) : '',
                'city'                => isset( $_POST['shop_city'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_city'] ) ) : '',
                'state'               => isset( $_POST['shop_state'] ) ? sywat_normalize_state_code( wp_unslash( $_POST['shop_state'] ) ) : 'TX - Texas',
                'zip'                 => isset( $_POST['shop_zip'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_zip'] ) ) : '',
                'default_lines'       => $default_indoor_lines,
                'outdoor_lines'       => $default_outdoor_lines,
                'created_at'          => current_time( 'mysql' ),
            );

            $sort_shops( $shops );
            update_option( 'sywat_shops_list', $shops );
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully created location <strong>' . esc_html( $shop_name ) . '</strong>.</div>';
        }

        // STEP 1: PARSE FILE & GENERATE DRY-RUN PREVIEW
        if ( $action === 'import_shops' || $action === 'preview_import_shops' ) {
            if ( empty( $_FILES['shop_import_file']['tmp_name'] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select an Excel or CSV file to import.</div>';
            }

            $file_tmp  = $_FILES['shop_import_file']['tmp_name'];
            $file_name = sanitize_file_name( wp_unslash( $_FILES['shop_import_file']['name'] ) );
            $ext       = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
            $mode      = isset( $_POST['import_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['import_mode'] ) ) : 'upsert';

            $rows = array();

            if ( in_array( $ext, array( 'xlsx', 'xls' ), true ) ) {
                $lib_path  = dirname( __DIR__ ) . '/lib/SimpleXLSX.php';
                $shop_path = __DIR__ . '/SimpleXLSX.php';

                if ( file_exists( $lib_path ) ) {
                    require_once $lib_path;
                } elseif ( file_exists( $shop_path ) ) {
                    require_once $shop_path;
                }

                if ( class_exists( 'SimpleXLSX' ) && $xlsx = SimpleXLSX::parse( $file_tmp ) ) {
                    $rows = $xlsx->rows();
                } else {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Unable to parse Excel file. Please ensure it is a valid .xlsx file or upload as CSV.</div>';
                }
            } else {
                // CSV / TSV Text Parser with Delimiter Auto-Detection & UTF-8 BOM Stripping
                $file_content = file_get_contents( $file_tmp );
                if ( substr( $file_content, 0, 3 ) === "\xEF\xBB\xBF" ) {
                    $file_content = substr( $file_content, 3 );
                }

                $lines = array_filter( explode( "\n", str_replace( "\r", "", $file_content ) ) );
                if ( ! empty( $lines ) ) {
                    $first_line = reset( $lines );
                    $tab_cnt   = substr_count( $first_line, "\t" );
                    $comma_cnt = substr_count( $first_line, "," );
                    $semi_cnt  = substr_count( $first_line, ";" );

                    if ( $tab_cnt >= $comma_cnt && $tab_cnt >= $semi_cnt && $tab_cnt > 0 ) {
                        $delim = "\t";
                    } elseif ( $semi_cnt > $comma_cnt && $semi_cnt > 0 ) {
                        $delim = ";";
                    } else {
                        $delim = ",";
                    }

                    if ( ( $handle = fopen( $file_tmp, 'r' ) ) !== false ) {
                        while ( ( $data = fgetcsv( $handle, 4096, $delim ) ) !== false ) {
                            if ( ! empty( array_filter( $data ) ) ) {
                                $rows[] = $data;
                            }
                        }
                        fclose( $handle );
                    }
                }
            }

            if ( empty( $rows ) || count( $rows ) < 2 ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">No valid data rows found in the uploaded file.</div>';
            }

            // Extract Header Row
            $raw_headers = array_shift( $rows );
            $headers     = array();
            foreach ( $raw_headers as $idx => $h_val ) {
                $clean_h = strtolower( preg_replace( '/[^a-z0-9]/', '', (string) $h_val ) );
                if ( ! empty( $clean_h ) ) {
                    $headers[$clean_h] = $idx;
                }
            }

            $get_cell = function( $row, $patterns, $fallback_col = null ) use ( $headers ) {
                if ( ! is_array( $patterns ) ) $patterns = array( $patterns );
                foreach ( $patterns as $p ) {
                    $clean_p = strtolower( preg_replace( '/[^a-z0-9]/', '', $p ) );
                    if ( isset( $headers[$clean_p] ) && isset( $row[$headers[$clean_p]] ) ) {
                        $val = trim( (string) $row[$headers[$clean_p]] );
                        if ( $val !== '' ) return $val;
                    }
                }
                if ( $fallback_col !== null && isset( $row[$fallback_col] ) ) {
                    $val = trim( (string) $row[$fallback_col] );
                    if ( $val !== '' ) return $val;
                }
                return '';
            };

            $preview_items = array();
            $new_count     = 0;
            $update_count  = 0;
            $skip_count    = 0;

            foreach ( $rows as $row ) {
                $shop_name = $get_cell( $row, array( 'Shop/Club', 'Shop Name', 'Location Name', 'Shop', 'Club' ), 0 );
                if ( empty( $shop_name ) || in_array( strtolower($shop_name), array( 'shop/club', 'shop name', 'location name', 'shop', 'club' ), true ) ) {
                    continue;
                }

                $raw_type = $get_cell( $row, array( 'Type', 'Location Type' ), 1 );
                $raw_dist = $get_cell( $row, array( 'District', 'TFAA District' ), 2 );
                $district = function_exists('sywat_normalize_district') ? sywat_normalize_district( $raw_dist ) : sanitize_text_field( $raw_dist );

                $addr1 = $get_cell( $row, array( 'Address Line 1', 'Address 1', 'Address' ), 3 );
                $addr2 = $get_cell( $row, array( 'Address Line 2', 'Address 2', 'Suite' ), 4 );
                $city  = $get_cell( $row, array( 'City' ), 5 );
                $state = $get_cell( $row, array( 'State' ), 6 );
                $state = function_exists('sywat_normalize_state_code') ? sywat_normalize_state_code( $state ) : 'TX - Texas';
                $zip   = $get_cell( $row, array( 'Zip', 'Zip Code', 'Postal Code' ), 7 );
                if ( ! empty( $zip ) ) {
                    $zip = preg_replace( '/\.0$/', '', (string) $zip );
                }

                $contact       = $get_cell( $row, array( 'Primary Contact', 'Contact Name', 'Contact' ), 8 );
                $contact_title = $get_cell( $row, array( 'Primary Title', 'Contact Title', 'Title' ), 9 );
                $email         = $get_cell( $row, array( 'Primary Email', 'Contact Email', 'Email' ), 10 );
                $phone         = $get_cell( $row, array( 'Primary Phone', 'Contact Phone', 'Phone' ), 11 );
                if ( ! empty( $phone ) ) {
                    $phone = sywat_format_phone_number( $phone );
                }

                $add_name  = $get_cell( $row, array( 'Additional Contact', 'Secondary Contact', 'Contact 2' ), 12 );
                $add_title = $get_cell( $row, array( 'Additional Title', 'Secondary Title', 'Title 2' ), 13 );
                $add_email = $get_cell( $row, array( 'Additional Email', 'Secondary Email', 'Email 2' ), 14 );
                $add_phone = $get_cell( $row, array( 'Additional Phone', 'Secondary Phone', 'Phone 2' ), 15 );

                $additional_contacts = array();
                if ( ! empty( $add_name ) || ! empty( $add_email ) || ! empty( $add_phone ) ) {
                    $additional_contacts[] = array(
                        'name'  => $add_name,
                        'title' => $add_title,
                        'email' => $add_email,
                        'phone' => ! empty( $add_phone ) ? sywat_format_phone_number( $add_phone ) : '',
                    );
                }

                // Auto-detect Location Type
                if ( strtolower($raw_type) === 'club' || strpos( strtolower($shop_name), '4h' ) !== false || strpos( strtolower($shop_name), 'club' ) !== false ) {
                    $loc_type = 'club';
                } else {
                    $loc_type = 'pro_shop';
                }

                // All uploaded/imported locations default to Indoor Range only (no Pro Shop tag by default)
                $facilities = array( 'indoor_range' );

                $shop_slug = 'shop_' . sanitize_title( $shop_name );

                // Determine Status
                if ( isset( $shops[$shop_slug] ) ) {
                    if ( $mode === 'skip_existing' ) {
                        $status       = 'skip';
                        $status_label = 'Skipped (Exists)';
                        $skip_count++;
                    } else {
                        $status       = 'update';
                        $status_label = 'Update Existing';
                        $update_count++;
                    }
                } else {
                    $status       = 'new';
                    $status_label = 'Create New';
                    $new_count++;
                }

                $preview_items[] = array(
                    'slug'                => $shop_slug,
                    'name'                => $shop_name,
                    'location_type'       => $loc_type,
                    'facilities'          => $facilities,
                    'district'            => $district,
                    'contact'             => $contact,
                    'contact_title'       => $contact_title,
                    'email'               => $email,
                    'phone'               => $phone,
                    'additional_contacts' => $additional_contacts,
                    'address_1'           => $addr1,
                    'address_2'           => $addr2,
                    'city'                => $city,
                    'state'               => $state,
                    'zip'                 => $zip,
                    'status'              => $status,
                    'status_label'        => $status_label,
                );
            }

            if ( empty( $preview_items ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">No valid locations could be parsed from the file.</div>';
            }

            // Alphabetical sort preview items by name
            usort( $preview_items, function( $a, $b ) {
                return strcasecmp( $a['name'], $b['name'] );
            });

            // Store Preview Transient (valid for 15 minutes)
            $transient_payload = array(
                'file_name' => $file_name,
                'mode'      => $mode,
                'summary'   => array(
                    'total'  => count( $preview_items ),
                    'new'    => $new_count,
                    'update' => $update_count,
                    'skip'   => $skip_count,
                ),
                'items'     => $preview_items,
            );

            set_transient( 'sywat_shop_import_preview_' . $user_id, $transient_payload, 900 );

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-info">📥 File <strong>' . esc_html($file_name) . '</strong> parsed successfully. Please review the <strong>' . count($preview_items) . ' location(s)</strong> below and click <strong>Confirm Import</strong> to save changes.</div>';
        }

        // STEP 2: COMMIT PREVIEW TO DATABASE
        if ( $action === 'confirm_import_shops' ) {
            $transient_data = get_transient( 'sywat_shop_import_preview_' . $user_id );

            if ( empty( $transient_data ) || empty( $transient_data['items'] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Import preview session expired or invalid. Please re-upload your file to try again.</div>';
            }

            $items         = $transient_data['items'];
            $created_count = 0;
            $updated_count = 0;
            $skipped_count = 0;

            $default_indoor_lines = array(
                array( 'day' => 'Saturday', 'time' => '10:00 AM', 'name' => 'Saturday 10:00 AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'day' => 'Saturday', 'time' => '2:00 PM',  'name' => 'Saturday 2:00 PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'day' => 'Sunday',   'time' => '10:00 AM', 'name' => 'Sunday 10:00 AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'day' => 'Sunday',   'time' => '2:00 PM',  'name' => 'Sunday 2:00 PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
            );

            $default_outdoor_lines = array(
                array( 'day' => 'Saturday', 'time' => '9:00 AM', 'name' => 'Saturday 9:00 AM' ),
                array( 'day' => 'Sunday',   'time' => '9:00 AM', 'name' => 'Sunday 9:00 AM' ),
            );

            foreach ( $items as $item ) {
                if ( $item['status'] === 'skip' ) {
                    $skipped_count++;
                    continue;
                }

                $shop_slug = $item['slug'];

                if ( isset( $shops[$shop_slug] ) ) {
                    // Update existing location (Safe Merge)
                    $shops[$shop_slug]['name']          = $item['name'];
                    $shops[$shop_slug]['location_type'] = $item['location_type'];
                    if ( ! empty( $item['district'] ) )      $shops[$shop_slug]['district']      = $item['district'];
                    if ( ! empty( $item['contact'] ) )       $shops[$shop_slug]['contact']       = $item['contact'];
                    if ( ! empty( $item['contact_title'] ) ) $shops[$shop_slug]['contact_title'] = $item['contact_title'];
                    if ( ! empty( $item['email'] ) )         $shops[$shop_slug]['email']         = $item['email'];
                    if ( ! empty( $item['phone'] ) )         $shops[$shop_slug]['phone']         = $item['phone'];
                    if ( ! empty( $item['address_1'] ) )     $shops[$shop_slug]['address_1']     = $item['address_1'];
                    if ( ! empty( $item['address_2'] ) )     $shops[$shop_slug]['address_2']     = $item['address_2'];
                    if ( ! empty( $item['city'] ) )          $shops[$shop_slug]['city']          = $item['city'];
                    if ( ! empty( $item['state'] ) )         $shops[$shop_slug]['state']         = $item['state'];
                    if ( ! empty( $item['zip'] ) )           $shops[$shop_slug]['zip']           = $item['zip'];

                    if ( ! empty( $item['additional_contacts'] ) ) {
                        $shops[$shop_slug]['additional_contacts'] = $item['additional_contacts'];
                    }

                    // Strip auto-assigned 'pro_shop' facility tag from all imported locations, ensure 'indoor_range' is set
                    $existing_fac = isset( $shops[$shop_slug]['facilities'] ) && is_array( $shops[$shop_slug]['facilities'] ) ? $shops[$shop_slug]['facilities'] : array();
                    $existing_fac = array_diff( $existing_fac, array( 'pro_shop' ) );
                    if ( ! in_array( 'indoor_range', $existing_fac, true ) ) {
                        $existing_fac[] = 'indoor_range';
                    }
                    $shops[$shop_slug]['facilities'] = array_values( $existing_fac );

                    $updated_count++;
                } else {
                    // Create new location (Only Indoor Target Range by default)
                    $shops[$shop_slug] = array(
                        'id'                  => $shop_slug,
                        'name'                => $item['name'],
                        'location_type'       => $item['location_type'],
                        'district'            => $item['district'],
                        'facilities'          => array( 'indoor_range' ),
                        'contact'             => $item['contact'],
                        'contact_title'       => $item['contact_title'],
                        'email'               => $item['email'],
                        'phone'               => $item['phone'],
                        'additional_contacts' => $item['additional_contacts'],
                        'address_1'           => $item['address_1'],
                        'address_2'           => $item['address_2'],
                        'city'                => $item['city'],
                        'state'               => $item['state'],
                        'zip'                 => $item['zip'],
                        'default_lines'       => $default_indoor_lines,
                        'outdoor_lines'       => $default_outdoor_lines,
                        'created_at'          => current_time( 'mysql' ),
                    );
                    $created_count++;
                }
            }

            $sort_shops( $shops );
            update_option( 'sywat_shops_list', $shops );
            delete_transient( 'sywat_shop_import_preview_' . $user_id );

            $msg = "Import complete! Successfully created {$created_count} new location(s), updated {$updated_count} location(s)";
            if ( $skipped_count > 0 ) {
                $msg .= ", skipped {$skipped_count} existing location(s)";
            }
            $msg .= ".";

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">' . esc_html( $msg ) . '</div>';
        }

        // CANCEL IMPORT PREVIEW
        if ( $action === 'cancel_import_shops' ) {
            delete_transient( 'sywat_shop_import_preview_' . $user_id );
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-info">Import preview cancelled. No database changes were made.</div>';
        }

        if ( $action === 'delete_shop' ) {
            $shop_id = isset( $_POST['shop_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_id'] ) ) : '';
            if ( isset( $shops[$shop_id] ) ) {
                $deleted_name = $shops[$shop_id]['name'];
                unset( $shops[$shop_id] );
                
                $sort_shops( $shops );
                update_option( 'sywat_shops_list', $shops );

                $assigned_users = get_users( array( 'meta_key' => 'sywat_shop_id', 'meta_value' => $shop_id ) );
                foreach ( $assigned_users as $u ) {
                    delete_user_meta( $u->ID, 'sywat_shop_id' );
                }

                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully deleted location <strong>' . esc_html( $deleted_name ) . '</strong>.</div>';
            }
        }

        if ( $action === 'assign_staff_to_shop' ) {
            $target_user_id = isset( $_POST['staff_user_id'] ) ? intval( wp_unslash( $_POST['staff_user_id'] ) ) : 0;
            $target_shop_id = isset( $_POST['target_shop_id'] ) ? sanitize_text_field( wp_unslash( $_POST['target_shop_id'] ) ) : '';
            if ( $target_user_id > 0 && isset( $shops[$target_shop_id] ) ) {
                update_user_meta( $target_user_id, 'sywat_shop_id', $target_shop_id );
                $u = get_userdata( $target_user_id );
                $u_name = ( $u && isset( $u->display_name ) ) ? $u->display_name : 'User';
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Assigned <strong>' . esc_html( $u_name ) . '</strong> to <strong>' . esc_html( $shops[$target_shop_id]['name'] ) . '</strong>.</div>';
            }
        }

        if ( $action === 'remove_staff_from_shop' ) {
            $target_user_id = isset( $_POST['staff_user_id'] ) ? intval( wp_unslash( $_POST['staff_user_id'] ) ) : 0;
            if ( $target_user_id > 0 ) {
                delete_user_meta( $target_user_id, 'sywat_shop_id' );
                $u = get_userdata( $target_user_id );
                $u_name = ( $u && isset( $u->display_name ) ) ? $u->display_name : 'User';
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Unlinked <strong>' . esc_html( $u_name ) . '</strong> from location assignment.</div>';
            }
        }

        return '';
    }
}
