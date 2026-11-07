<?php
/**
 * Sub-Module: Historical Archers Batch Spreadsheet Importer Engine
 * Path: includes/members/import/archers-importer.php
 * Description: High-performance 2-step audit preview and batch processing engine for ingesting Archers.xlsx / CSV historical data. Uses ZipArchive + SimpleXML for native XLSX parsing and fgetcsv for CSV fallback, with persistent storage in wp_upload_dir().
 * Compliance: Zero inline styles, zero HTML layout templates. Returns structured alert notices and dedicated preview modal payloads.
 * Author: SYWAT Archery
 * Version: 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_structure_parsed_rows' ) ) {
    /**
     * Structure raw matrix into associative rows keyed by header titles.
     *
     * @param array $raw_matrix Raw 2D array of rows and columns.
     * @return array Associative array of rows.
     */
    function sywat_structure_parsed_rows( $raw_matrix ) {
        if ( empty( $raw_matrix ) ) return array();

        // Extract header from first non-empty row
        $header_row = array_shift( $raw_matrix );
        while ( empty( $header_row ) && ! empty( $raw_matrix ) ) {
            $header_row = array_shift( $raw_matrix );
        }

        if ( empty( $header_row ) ) return array();

        $header       = array_map( 'trim', $header_row );
        $header_count = count( $header );
        $structured   = array();

        foreach ( $raw_matrix as $data ) {
            $data_count = count( $data );
            if ( $data_count === 0 || ( $data_count === 1 && empty( $data[0] ) ) ) {
                continue;
            }

            if ( $data_count < $header_count ) {
                $data = array_pad( $data, $header_count, '' );
            } elseif ( $data_count > $header_count ) {
                $data = array_slice( $data, 0, $header_count );
            }

            $structured[] = array_combine( $header, $data );
        }

        return $structured;
    }
}

if ( ! function_exists( 'sywat_parse_spreadsheet_rows' ) ) {
    /**
     * DUAL XLSX & CSV PARSER ENGINE
     * Parses native Excel (.xlsx) files using ZipArchive + SimpleXML, or CSV via fgetcsv.
     *
     * @param string $file_path Absolute server path to spreadsheet file.
     * @return array Structured array of rows.
     */
    function sywat_parse_spreadsheet_rows( $file_path ) {
        $rows = array();

        // 1. NATIVE XLSX PARSER (ZipArchive + SimpleXML)
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( $zip->open( $file_path ) === true ) {
                $shared_strings = array();
                $ss_content     = $zip->getFromName( 'xl/sharedStrings.xml' );
                if ( $ss_content !== false ) {
                    $ss_xml = @simplexml_load_string( $ss_content );
                    if ( $ss_xml && isset( $ss_xml->si ) ) {
                        foreach ( $ss_xml->si as $val ) {
                            if ( isset( $val->t ) ) {
                                $shared_strings[] = (string) $val->t;
                            } elseif ( isset( $val->r ) ) {
                                $t = '';
                                foreach ( $val->r as $r ) {
                                    $t .= (string) $r->t;
                                }
                                $shared_strings[] = $t;
                            } else {
                                $shared_strings[] = '';
                            }
                        }
                    }
                }

                // Find worksheet XML
                $sheet_content = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
                if ( $sheet_content === false ) {
                    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                        $entry_name = $zip->getNameIndex( $i );
                        if ( preg_match( '#xl/worksheets/sheet\d+\.xml#i', $entry_name ) ) {
                            $sheet_content = $zip->getFromName( $entry_name );
                            break;
                        }
                    }
                }

                if ( $sheet_content !== false ) {
                    $sheet_xml = @simplexml_load_string( $sheet_content );
                    if ( $sheet_xml && isset( $sheet_xml->sheetData->row ) ) {
                        foreach ( $sheet_xml->sheetData->row as $row_node ) {
                            $row_data = array();

                            foreach ( $row_node->c as $cell ) {
                                $r_ref      = (string) $cell['r'];
                                $col_letter = preg_replace( '/[0-9]/', '', $r_ref );

                                // Convert letter to 0-based index
                                $col_idx = 0;
                                $len     = strlen( $col_letter );
                                for ( $i = 0; $i < $len; $i++ ) {
                                    $col_idx = $col_idx * 26 + ( ord( strtoupper( $col_letter[$i] ) ) - 64 );
                                }
                                $col_idx = max( 0, $col_idx - 1 );

                                while ( count( $row_data ) < $col_idx ) {
                                    $row_data[] = '';
                                }

                                $val  = (string) $cell->v;
                                $type = (string) $cell['t'];

                                if ( $type === 's' && isset( $shared_strings[ intval( $val ) ] ) ) {
                                    $val = $shared_strings[ intval( $val ) ];
                                } elseif ( $type === 'inlineStr' && isset( $cell->is->t ) ) {
                                    $val = (string) $cell->is->t;
                                }

                                $row_data[ $col_idx ] = trim( $val );
                            }
                            $rows[] = $row_data;
                        }
                        $zip->close();

                        if ( ! empty( $rows ) ) {
                            return sywat_structure_parsed_rows( $rows );
                        }
                    }
                }
                $zip->close();
            }
        }

        // 2. FALLBACK CSV PARSER (FGETCSV)
        if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
            while ( ( $data = fgetcsv( $handle, 4096, ',' ) ) !== false ) {
                $rows[] = $data;
            }
            fclose( $handle );
        }

        return sywat_structure_parsed_rows( $rows );
    }
}

if ( ! function_exists( 'sywat_import_archers_spreadsheet' ) ) {
    /**
     * Process uploaded Archers spreadsheet (.xlsx or .csv) with persistent file storage and dedicated preview modal support.
     *
     * @param array|string $file_input Uploaded $_FILES['archers_file'] array or direct persistent file path string.
     * @param string       $mode       Execution mode: 'preview' (audit only) or 'commit' (write to DB).
     * @return array|string Array payload for preview modal or alert notice markup for commit.
     */
    function sywat_import_archers_spreadsheet( $file_input, $mode = 'preview' ) {
        if ( ! current_user_can( 'administrator' ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators can execute batch data imports.</div>';
        }

        @set_time_limit( 600 );
        @ini_set( 'memory_limit', '512M' );

        $file_path = '';
        $file_name = '';

        if ( is_array( $file_input ) && ! empty( $file_input['tmp_name'] ) && is_uploaded_file( $file_input['tmp_name'] ) ) {
            $file_name = sanitize_text_field( $file_input['name'] );

            $wp_upload_dir = wp_upload_dir();
            $target_dir    = trailingslashit( $wp_upload_dir['basedir'] ) . 'sywat-temp/';

            if ( ! file_exists( $target_dir ) ) {
                wp_mkdir_p( $target_dir );
            }

            $safe_name = 'import-' . time() . '-' . sanitize_file_name( $file_name );
            $file_path = $target_dir . $safe_name;

            if ( ! move_uploaded_file( $file_input['tmp_name'], $file_path ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Failed to save uploaded file to temporary storage directory.</div>';
            }
        } elseif ( is_string( $file_input ) && file_exists( $file_input ) ) {
            $file_path = $file_input;
            $file_name = basename( $file_input );
        } else {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please upload a valid .xlsx or .csv spreadsheet file.</div>';
        }

        // Parse Rows using Dual XLSX/CSV Engine
        $rows = sywat_parse_spreadsheet_rows( $file_path );

        if ( empty( $rows ) ) {
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Failed to parse spreadsheet content or file is empty.</div>';
        }

        // Metrics Tracking
        $metric_total_rows       = count( $rows );
        $metric_purged_rows      = 0;
        $metric_deduped_rows     = 0;
        $metric_created_primary  = 0;
        $metric_created_dep      = 0;
        $metric_unassigned_email = 0;

        // -------------------------------------------------------------------------
        // STAGE 1: PURGE GARBAGE & SANITIZE INDIVIDUAL ROWS
        // -------------------------------------------------------------------------
        $cleaned_records = array();

        foreach ( $rows as $row ) {
            $raw_archer = isset( $row['Archer'] ) ? trim( $row['Archer'] ) : '';
            $raw_email  = isset( $row['Email'] ) ? trim( $row['Email'] ) : '';
            $raw_phone  = isset( $row['Phone'] ) ? trim( $row['Phone'] ) : '';
            $raw_dob    = isset( $row['DOB'] ) ? trim( $row['DOB'] ) : '';
            $raw_pro    = isset( $row['Pro'] ) ? trim( $row['Pro'] ) : 'No';
            $raw_tfaa   = isset( $row['TFAA'] ) ? trim( $row['TFAA'] ) : '';
            $raw_exp    = isset( $row['Expires'] ) ? trim( $row['Expires'] ) : '';

            // Security & Injection Purge
            if ( preg_match( '/(kEMlzpAX|select|sleep|DBMS_PIPE|XOR|waitfor|PG_SLEEP|@@)/i', $raw_archer . ' ' . $raw_email ) ) {
                $metric_purged_rows++;
                continue;
            }

            if ( empty( $raw_archer ) ) {
                $metric_purged_rows++;
                continue;
            }

            // Name Parsing
            $last_name  = '';
            $first_name = '';
            if ( strpos( $raw_archer, ',' ) !== false ) {
                $parts      = explode( ',', $raw_archer, 2 );
                $last_name  = ucfirst( strtolower( trim( $parts[0] ) ) );
                $first_name = ucfirst( strtolower( trim( $parts[1] ) ) );
            } else {
                $parts      = explode( ' ', $raw_archer, 2 );
                $first_name = ucfirst( strtolower( trim( $parts[0] ) ) );
                $last_name  = isset( $parts[1] ) ? ucfirst( strtolower( trim( $parts[1] ) ) ) : '';
            }

            // Normalization
            $dob = ( ! empty( $raw_dob ) && $raw_dob !== '0000-00-00' ) ? date( 'Y-m-d', strtotime( $raw_dob ) ) : '';
            $exp = ( ! empty( $raw_exp ) && $raw_exp !== '0000-00-00' ) ? date( 'Y-m-d', strtotime( $raw_exp ) ) : '';

            $phone = preg_replace( '/[^0-9]/', '', $raw_phone );
            if ( strlen( $phone ) === 10 ) {
                $phone = substr( $phone, 0, 3 ) . '-' . substr( $phone, 3, 3 ) . '-' . substr( $phone, 6 );
            }

            $is_pro = ( strtolower( $raw_pro ) === 'yes' ) ? 'yes' : 'no';
            $gender = 'unassigned';

            $email = sanitize_email( $raw_email );
            if ( preg_match( '/(noemail|example\.com|test)/i', $email ) ) {
                $email = '';
            }

            $tfaa_id = preg_replace( '/[^0-9]/', '', $raw_tfaa );

            $cleaned_records[] = array(
                'first_name'  => $first_name,
                'last_name'   => $last_name,
                'email'       => $email,
                'phone'       => $phone,
                'dob'         => $dob,
                'gender'      => $gender,
                'is_pro'      => $is_pro,
                'nfaa_number' => $tfaa_id,
                'nfaa_exp'    => $exp,
            );
        }

        // -------------------------------------------------------------------------
        // STAGE 2: DEDUPLICATE PROFILES (MATCH FIRST + LAST + DOB)
        // -------------------------------------------------------------------------
        $deduped_records = array();

        foreach ( $cleaned_records as $rec ) {
            $dedup_key = strtolower( $rec['first_name'] . '|' . $rec['last_name'] . '|' . $rec['dob'] );

            if ( ! isset( $deduped_records[ $dedup_key ] ) ) {
                $deduped_records[ $dedup_key ] = $rec;
            } else {
                $metric_deduped_rows++;
                $existing = $deduped_records[ $dedup_key ];

                if ( ! empty( $rec['nfaa_exp'] ) && ( empty( $existing['nfaa_exp'] ) || strtotime( $rec['nfaa_exp'] ) > strtotime( $existing['nfaa_exp'] ) ) ) {
                    $existing['nfaa_exp'] = $rec['nfaa_exp'];
                }
                if ( empty( $existing['nfaa_number'] ) && ! empty( $rec['nfaa_number'] ) ) {
                    $existing['nfaa_number'] = $rec['nfaa_number'];
                }
                if ( empty( $existing['email'] ) && ! empty( $rec['email'] ) ) {
                    $existing['email'] = $rec['email'];
                }
                if ( empty( $existing['phone'] ) && ! empty( $rec['phone'] ) ) {
                    $existing['phone'] = $rec['phone'];
                }
                if ( $rec['is_pro'] === 'yes' ) {
                    $existing['is_pro'] = 'yes';
                }

                $deduped_records[ $dedup_key ] = $existing;
            }
        }

        // -------------------------------------------------------------------------
        // STAGE 3: HOUSEHOLD HIERARCHY TREE BUILDER
        // -------------------------------------------------------------------------
        $households = array();

        foreach ( $deduped_records as $rec ) {
            $assigned_hh = false;

            if ( ! empty( $rec['email'] ) ) {
                $group_key = 'email_' . strtolower( $rec['email'] );
                $households[ $group_key ][] = $rec;
                $assigned_hh = true;
                continue;
            }

            if ( ! empty( $rec['last_name'] ) && ! empty( $rec['nfaa_number'] ) ) {
                foreach ( $households as $hk => $members ) {
                    foreach ( $members as $m ) {
                        if ( strtolower( $m['last_name'] ) === strtolower( $rec['last_name'] ) && ! empty( $m['nfaa_number'] ) ) {
                            $diff = abs( intval( $m['nfaa_number'] ) - intval( $rec['nfaa_number'] ) );
                            if ( $diff >= 1 && $diff <= 3 ) {
                                $households[ $hk ][] = $rec;
                                $assigned_hh = true;
                                break 2;
                            }
                        }
                    }
                }
            }

            if ( ! $assigned_hh && ! empty( $rec['last_name'] ) && ! empty( $rec['nfaa_exp'] ) ) {
                foreach ( $households as $hk => $members ) {
                    foreach ( $members as $m ) {
                        if ( strtolower( $m['last_name'] ) === strtolower( $rec['last_name'] ) && $m['nfaa_exp'] === $rec['nfaa_exp'] ) {
                            $households[ $hk ][] = $rec;
                            $assigned_hh = true;
                            break 2;
                        }
                    }
                }
            }

            if ( ! $assigned_hh ) {
                $group_key = 'standalone_' . md5( $rec['first_name'] . $rec['last_name'] . $rec['dob'] );
                $households[ $group_key ][] = $rec;
            }
        }

        // Calculate metrics
        foreach ( $households as $members ) {
            $metric_created_primary++;
            if ( count( $members ) > 1 ) {
                $metric_created_dep += ( count( $members ) - 1 );
            }
            $head = $members[0];
            if ( empty( $head['email'] ) ) {
                $metric_unassigned_email++;
            }
        }

        // -------------------------------------------------------------------------
        // MODE 1: RETURN AUDIT DATA PAYLOAD FOR PREVIEW MODAL
        // -------------------------------------------------------------------------
        if ( $mode === 'preview' ) {
            return array(
                'file_path'               => $file_path,
                'file_name'               => $file_name,
                'total_rows'              => $metric_total_rows,
                'purged_rows'             => $metric_purged_rows,
                'deduped_rows'            => $metric_deduped_rows,
                'clean_unique_count'      => count( $deduped_records ),
                'primary_accounts_count'  => $metric_created_primary,
                'dependents_count'        => $metric_created_dep,
                'unassigned_emails_count' => $metric_unassigned_email,
                'households'              => $households,
            );
        }

        // -------------------------------------------------------------------------
        // MODE 2: COMMIT INGESTION TO WORDPRESS DATABASE
        // -------------------------------------------------------------------------
        $real_created_primary = 0;
        $real_created_dep     = 0;
        $real_updated_user    = 0;

        foreach ( $households as $group_key => $members ) {
            usort( $members, function( $a, $b ) {
                if ( empty( $a['dob'] ) ) return 1;
                if ( empty( $b['dob'] ) ) return -1;
                return strtotime( $a['dob'] ) - strtotime( $b['dob'] );
            } );

            $head = array_shift( $members );

            $primary_email = $head['email'];
            $is_unassigned_email = false;

            if ( empty( $primary_email ) || ! is_email( $primary_email ) ) {
                $nfaa_seed            = ! empty( $head['nfaa_number'] ) ? $head['nfaa_number'] : rand( 10000, 99999 );
                $primary_email        = 'unassigned-' . $nfaa_seed . '@system.sywat.local';
                $is_unassigned_email  = true;
            }

            $existing_user_id = 0;

            if ( ! empty( $head['nfaa_number'] ) ) {
                $nfaa_users = get_users( array( 'meta_key' => 'sywat_nfaa_number', 'meta_value' => $head['nfaa_number'], 'number' => 1 ) );
                if ( ! empty( $nfaa_users ) ) {
                    $existing_user_id = $nfaa_users[0]->ID;
                }
            }

            if ( ! $existing_user_id ) {
                $user_by_email = get_user_by( 'email', $primary_email );
                if ( $user_by_email ) {
                    $existing_user_id = $user_by_email->ID;
                }
            }

            $primary_user_id = 0;

            if ( $existing_user_id ) {
                $primary_user_id = $existing_user_id;
                $real_updated_user++;

                wp_update_user( array(
                    'ID'           => $primary_user_id,
                    'first_name'   => $head['first_name'],
                    'last_name'    => $head['last_name'],
                    'display_name' => $head['first_name'] . ' ' . $head['last_name'],
                ) );
            } else {
                $base_username = sanitize_user( $head['first_name'] . '.' . $head['last_name'], true );
                $username      = $base_username;
                $counter       = 1;

                while ( username_exists( $username ) ) {
                    $username = $base_username . $counter;
                    $counter++;
                }

                $primary_user_id = wp_insert_user( array(
                    'user_login'   => $username,
                    'user_email'   => $primary_email,
                    'user_pass'    => wp_generate_password( 16, true ),
                    'first_name'   => $head['first_name'],
                    'last_name'    => $head['last_name'],
                    'display_name' => $head['first_name'] . ' ' . $head['last_name'],
                    'role'         => 'tfaa_member',
                ) );

                if ( ! is_wp_error( $primary_user_id ) ) {
                    $real_created_primary++;
                }
            }

            if ( $primary_user_id && ! is_wp_error( $primary_user_id ) ) {
                if ( function_exists( 'sywat_get_user_system_id' ) ) {
                    sywat_get_user_system_id( $primary_user_id );
                }

                update_user_meta( $primary_user_id, 'sywat_nfaa_number', $head['nfaa_number'] );
                update_user_meta( $primary_user_id, 'sywat_tfaa_number', $head['nfaa_number'] );
                update_user_meta( $primary_user_id, 'sywat_nfaa_expiration', $head['nfaa_exp'] );
                update_user_meta( $primary_user_id, 'sywat_dob', $head['dob'] );
                update_user_meta( $primary_user_id, 'sywat_gender', $head['gender'] );
                update_user_meta( $primary_user_id, 'sywat_is_pro', $head['is_pro'] );
                update_user_meta( $primary_user_id, 'sywat_phone', $head['phone'] );

                if ( $is_unassigned_email ) {
                    update_user_meta( $primary_user_id, 'sywat_email_unassigned', 'yes' );
                }

                if ( ! empty( $members ) && function_exists( 'sywat_add_household_dependent' ) ) {
                    foreach ( $members as $dep ) {
                        $dep_email = ! empty( $dep['email'] ) ? $dep['email'] : $primary_email;

                        $dep_payload = array(
                            'first_name'      => $dep['first_name'],
                            'last_name'       => $dep['last_name'],
                            'email'           => $dep_email,
                            'phone'           => $dep['phone'],
                            'gender'          => $dep['gender'],
                            'is_pro'          => $dep['is_pro'],
                            'dob'             => $dep['dob'],
                            'accommodations'  => 'none',
                            'nfaa_number'     => $dep['nfaa_number'],
                            'nfaa_expiration' => $dep['nfaa_exp'],
                        );

                        $res = sywat_add_household_dependent( $primary_user_id, $dep_payload );
                        if ( ! is_wp_error( $res ) ) {
                            $real_created_dep++;
                        }
                    }
                }
            }
        }

        if ( file_exists( $file_path ) ) {
            @unlink( $file_path );
        }

        return sprintf(
            '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🚀 <strong>Batch Archer Import Complete!</strong> Processed <strong>%d</strong> total rows | Created <strong>%d</strong> Primary Accounts | Added <strong>%d</strong> Household Dependents | Updated <strong>%d</strong> Existing Database Profiles | Purged <strong>%d</strong> Injection/Garbage Rows (%d deduplicated).</div>',
            $metric_total_rows,
            $real_created_primary,
            $real_created_dep,
            $real_updated_user,
            $metric_purged_rows,
            $metric_deduped_rows
        );
    }
}
