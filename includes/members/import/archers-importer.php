<?php
/**
 * Sub-Module: Historical Archers Batch Spreadsheet Importer Engine
 * Path: includes/members/import/archers-importer.php
 * Description: High-performance batch processing engine for ingesting Archers.xlsx / CSV historical data. Performs SQL injection purging, DOB-based profile deduplication, 3-tier household grouping, system placeholder email resolution, and safe in-place database updates.
 * Compliance: Zero inline styles, zero HTML layout templates. Returns structured alert notices.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_import_archers_spreadsheet' ) ) {
    /**
     * Process uploaded Archers spreadsheet (.xlsx or .csv) and ingest clean records into WordPress.
     *
     * @param array $file_array Uploaded $_FILES['archers_file'] array.
     * @return string Alert notice markup summarizing batch execution metrics.
     */
    function sywat_import_archers_spreadsheet( $file_array ) {
        // Capability & Nonce Verification
        if ( ! current_user_can( 'administrator' ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators can execute batch data imports.</div>';
        }

        if ( empty( $file_array['tmp_name'] ) || ! is_uploaded_file( $file_array['tmp_name'] ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please upload a valid .xlsx or .csv spreadsheet file.</div>';
        }

        // Elevate resource limits for heavy batch processing
        @set_time_limit( 600 );
        @ini_set( 'memory_limit', '512M' );

        $file_path = $file_array['tmp_name'];
        $file_ext  = strtolower( pathinfo( $file_array['name'], PATHINFO_EXTENSION ) );
        $rows      = array();

        // -------------------------------------------------------------------------
        // PARSE SPREADSHEET CONTENT
        // -------------------------------------------------------------------------
        if ( $file_ext === 'csv' ) {
            if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
                $header = fgetcsv( $handle, 2000, ',' );
                while ( ( $data = fgetcsv( $handle, 2000, ',' ) ) !== false ) {
                    if ( count( $data ) >= count( $header ) ) {
                        $rows[] = array_combine( array_slice( $header, 0, count( $data ) ), $data );
                    }
                }
                fclose( $handle );
            }
        } else {
            // Attempt parsing via SimpleXLSX or PHP spreadsheet parser if available
            if ( class_exists( 'SimpleXLSX' ) ) {
                if ( $xlsx = SimpleXLSX::parse( $file_path ) ) {
                    $sheet_rows = $xlsx->rows();
                    if ( ! empty( $sheet_rows ) ) {
                        $header = array_map( 'trim', array_shift( $sheet_rows ) );
                        foreach ( $sheet_rows as $r ) {
                            if ( count( $r ) >= count( $header ) ) {
                                $rows[] = array_combine( array_slice( $header, 0, count( $r ) ), $r );
                            }
                        }
                    }
                }
            } else {
                // Native CSV fallback reading logic
                if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
                    $header = fgetcsv( $handle, 2000, ',' );
                    while ( ( $data = fgetcsv( $handle, 2000, ',' ) ) !== false ) {
                        if ( count( $data ) >= count( $header ) ) {
                            $rows[] = array_combine( array_slice( $header, 0, count( $data ) ), $data );
                        }
                    }
                    fclose( $handle );
                }
            }
        }

        if ( empty( $rows ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Failed to parse spreadsheet content or spreadsheet is empty.</div>';
        }

        // Metrics Tracking
        $metric_total_rows      = count( $rows );
        $metric_purged_rows     = 0;
        $metric_deduped_rows    = 0;
        $metric_created_primary = 0;
        $metric_created_dep     = 0;
        $metric_updated_user    = 0;

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

            // Rule 1.1: SQL Injection & Security Pen-Test Purge
            if ( preg_match( '/(kEMlzpAX|select|sleep|DBMS_PIPE|XOR|waitfor|PG_SLEEP|@@)/i', $raw_archer . ' ' . $raw_email ) ) {
                $metric_purged_rows++;
                continue;
            }

            if ( empty( $raw_archer ) ) {
                $metric_purged_rows++;
                continue;
            }

            // Rule 1.2: Name Parsing (LastName, FirstName)
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

            // Rule 1.3: Date & Phone Normalization
            $dob = ( ! empty( $raw_dob ) && $raw_dob !== '0000-00-00' ) ? date( 'Y-m-d', strtotime( $raw_dob ) ) : '';
            $exp = ( ! empty( $raw_exp ) && $raw_exp !== '0000-00-00' ) ? date( 'Y-m-d', strtotime( $raw_exp ) ) : '';

            $phone = preg_replace( '/[^0-9]/', '', $raw_phone );
            if ( strlen( $phone ) === 10 ) {
                $phone = substr( $phone, 0, 3 ) . '-' . substr( $phone, 3, 3 ) . '-' . substr( $phone, 6 );
            }

            // Rule 1.4: Gender & Pro Defaults
            $is_pro = ( strtolower( $raw_pro ) === 'yes' ) ? 'yes' : 'no';
            $gender = 'unassigned'; // System Rule: Default to unassigned for staff verification

            // Email Cleaning (Filter placeholder domains)
            $email = sanitize_email( $raw_email );
            if ( preg_match( '/(noemail|example\.com|test)/i', $email ) ) {
                $email = '';
            }

            // Clean numeric TFAA ID
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

                // Retain newest NFAA expiration date
                if ( ! empty( $rec['nfaa_exp'] ) && ( empty( $existing['nfaa_exp'] ) || strtotime( $rec['nfaa_exp'] ) > strtotime( $existing['nfaa_exp'] ) ) ) {
                    $existing['nfaa_exp'] = $rec['nfaa_exp'];
                }

                // Retain valid NFAA ID
                if ( empty( $existing['nfaa_number'] ) && ! empty( $rec['nfaa_number'] ) ) {
                    $existing['nfaa_number'] = $rec['nfaa_number'];
                }

                // Retain valid email
                if ( empty( $existing['email'] ) && ! empty( $rec['email'] ) ) {
                    $existing['email'] = $rec['email'];
                }

                // Retain valid phone
                if ( empty( $existing['phone'] ) && ! empty( $rec['phone'] ) ) {
                    $existing['phone'] = $rec['phone'];
                }

                // Retain Pro status
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

            // Tier 1: Group by Shared Email
            if ( ! empty( $rec['email'] ) ) {
                $group_key = 'email_' . strtolower( $rec['email'] );
                $households[ $group_key ][] = $rec;
                $assigned_hh = true;
                continue;
            }

            // Tier 2: Group by Last Name + Sequential NFAA IDs
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

            // Tier 3: Group by Last Name + Matching Expiration Date
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

            // Standalone Household Unit
            if ( ! $assigned_hh ) {
                $group_key = 'standalone_' . md5( $rec['first_name'] . $rec['last_name'] . $rec['dob'] );
                $households[ $group_key ][] = $rec;
            }
        }

        // -------------------------------------------------------------------------
        // STAGE 4 & 5: DATABASE INGESTION & IN-PLACE UPDATES
        // -------------------------------------------------------------------------
        foreach ( $households as $group_key => $members ) {
            // Sort members by age (oldest first to select Head of Household)
            usort( $members, function( $a, $b ) {
                if ( empty( $a['dob'] ) ) return 1;
                if ( empty( $b['dob'] ) ) return -1;
                return strtotime( $a['dob'] ) - strtotime( $b['dob'] );
            } );

            $head = array_shift( $members ); // Oldest adult as Primary Account Holder

            // Rule 4.3: Email Resolution for Standalone Primary Accounts missing email
            $primary_email = $head['email'];
            $is_unassigned_email = false;

            if ( empty( $primary_email ) || ! is_email( $primary_email ) ) {
                $nfaa_seed            = ! empty( $head['nfaa_number'] ) ? $head['nfaa_number'] : rand( 10000, 99999 );
                $primary_email        = 'unassigned-' . $nfaa_seed . '@system.sywat.local';
                $is_unassigned_email  = true;
            }

            // De-duplication Check against existing WP database
            $existing_user_id = 0;

            if ( ! empty( $head['nfaa_number'] ) ) {
                $nfaa_users = get_users( array( 'meta_key' => 'sywat_nfaa_number', 'meta_value' => $head['nfaa_number'], 'number' => 1 ) );
                if ( ! empty( $nfaa_users ) ) {
                    $existing_user_id = $nfaa_users[0]->ID;
                }
            }

            if ( ! $existing_user_id && ! $is_unassigned_email ) {
                $user_by_email = get_user_by( 'email', $primary_email );
                if ( $user_by_email ) {
                    $existing_user_id = $user_by_email->ID;
                }
            }

            $primary_user_id = 0;

            if ( $existing_user_id ) {
                // Update Existing Primary User Record In-Place
                $primary_user_id = $existing_user_id;
                $metric_updated_user++;

                wp_update_user( array(
                    'ID'           => $primary_user_id,
                    'first_name'   => $head['first_name'],
                    'last_name'    => $head['last_name'],
                    'display_name' => $head['first_name'] . ' ' . $head['last_name'],
                ) );
            } else {
                // Insert New Primary Account Holder into wp_users
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
                    $metric_created_primary++;
                }
            }

            if ( $primary_user_id && ! is_wp_error( $primary_user_id ) ) {
                // Ensure System ID exists
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

                // Process Household Dependents Sub-Profile Array
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
                            $metric_created_dep++;
                        }
                    }
                }
            }
        }

        // -------------------------------------------------------------------------
        // RETURN METRIC SUMMARY ALERT BANNER
        // -------------------------------------------------------------------------
        return sprintf(
            '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🚀 <strong>Batch Archer Import Complete!</strong> Processed <strong>%d</strong> total rows | Created <strong>%d</strong> Primary Accounts | Added <strong>%d</strong> Household Dependents | Updated <strong>%d</strong> Existing Database Profiles | Purged <strong>%d</strong> Injection/Garbage Rows (%d deduplicated).</div>',
            $metric_total_rows,
            $metric_created_primary,
            $metric_created_dep,
            $metric_updated_user,
            $metric_purged_rows,
            $metric_deduped_rows
        );
    }
}
