<?php
/**
 * Sub-Module: Historical Archers Batch Spreadsheet Importer Engine & AJAX Batch API
 * Path: includes/members/import/archers-importer.php
 * Description: High-performance, DRY-refactored 2-step audit preview, batch ingestion, and AJAX progress chunking engine for ingesting Archers.xlsx / CSV historical data. Features email-first Head of Household sorting, ZipArchive + SimpleXML XLSX parsing, O(1) last-name hashtable indexing, global $wp_hasher class interception, in-memory username map indexing, atomic MySQL transactions with cache and hook suspension, persistent upload staging, step-by-step AJAX progress endpoints, and household dependent deduplication guards.
 * Compliance: Zero inline styles, zero HTML layout templates. Returns structured alert notices, dedicated preview modal payloads, and JSON AJAX responses.
 * Author: SYWAT Archery
 * Version: 2.1.0
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

if ( ! function_exists( 'sywat_build_archer_households_tree' ) ) {
    /**
     * Build cleaned, deduplicated household family tree from raw spreadsheet rows.
     *
     * @param array $rows Structured raw matrix rows.
     * @return array Tree payload with structured households and audit metrics.
     */
    function sywat_build_archer_households_tree( $rows ) {
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

            if ( preg_match( '/(kEMlzpAX|select|sleep|DBMS_PIPE|XOR|waitfor|PG_SLEEP|@@)/i', $raw_archer . ' ' . $raw_email ) ) {
                $metric_purged_rows++;
                continue;
            }

            if ( empty( $raw_archer ) ) {
                $metric_purged_rows++;
                continue;
            }

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
        // STAGE 3: HOUSEHOLD HIERARCHY TREE BUILDER (O(1) HASHTABLE INDEXED)
        // -------------------------------------------------------------------------
        $households    = array();
        $last_name_map = array();

        foreach ( $deduped_records as $rec ) {
            $assigned_hh = false;
            $ln_clean    = ! empty( $rec['last_name'] ) ? strtolower( $rec['last_name'] ) : '';

            // Priority 1: Match or assign by Email Address
            if ( ! empty( $rec['email'] ) ) {
                $group_key = 'email_' . strtolower( $rec['email'] );
                $households[ $group_key ][] = $rec;
                $assigned_hh = true;

                if ( $ln_clean ) {
                    if ( ! isset( $last_name_map[ $ln_clean ] ) || ! in_array( $group_key, $last_name_map[ $ln_clean ], true ) ) {
                        $last_name_map[ $ln_clean ][] = $group_key;
                    }
                }
                continue;
            }

            // Priority 2: Match by Last Name + NFAA ID (Difference 1-3)
            if ( $ln_clean && ! empty( $rec['nfaa_number'] ) && isset( $last_name_map[ $ln_clean ] ) ) {
                foreach ( $last_name_map[ $ln_clean ] as $hk ) {
                    if ( ! isset( $households[ $hk ] ) ) continue;
                    foreach ( $households[ $hk ] as $m ) {
                        if ( strtolower( $m['last_name'] ) === $ln_clean && ! empty( $m['nfaa_number'] ) ) {
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

            // Priority 3: Match by Last Name + NFAA Expiration Date
            if ( ! $assigned_hh && $ln_clean && ! empty( $rec['nfaa_exp'] ) && isset( $last_name_map[ $ln_clean ] ) ) {
                foreach ( $last_name_map[ $ln_clean ] as $hk ) {
                    if ( ! isset( $households[ $hk ] ) ) continue;
                    foreach ( $households[ $hk ] as $m ) {
                        if ( strtolower( $m['last_name'] ) === $ln_clean && $m['nfaa_exp'] === $rec['nfaa_exp'] ) {
                            $households[ $hk ][] = $rec;
                            $assigned_hh = true;
                            break 2;
                        }
                    }
                }
            }

            // Priority 4: Create Standalone Household
            if ( ! $assigned_hh ) {
                $group_key = 'standalone_' . md5( $rec['first_name'] . $rec['last_name'] . $rec['dob'] );
                $households[ $group_key ][] = $rec;

                if ( $ln_clean ) {
                    if ( ! isset( $last_name_map[ $ln_clean ] ) || ! in_array( $group_key, $last_name_map[ $ln_clean ], true ) ) {
                        $last_name_map[ $ln_clean ][] = $group_key;
                    }
                }
            }
        }

        // Re-sort household members so real email owners become Head of Household ($head)
        foreach ( $households as $hk => $members ) {
            usort( $members, function( $a, $b ) {
                $a_has = ! empty( $a['email'] ) && is_email( $a['email'] );
                $b_has = ! empty( $b['email'] ) && is_email( $b['email'] );

                if ( $a_has && ! $b_has ) return -1;
                if ( ! $a_has && $b_has ) return 1;

                if ( empty( $a['dob'] ) ) return 1;
                if ( empty( $b['dob'] ) ) return -1;
                return strtotime( $a['dob'] ) - strtotime( $b['dob'] );
            } );
            $households[ $hk ] = $members;
        }

        // Metrics Calculation
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

        return array(
            'households'              => $households,
            'total_rows'              => $metric_total_rows,
            'purged_rows'             => $metric_purged_rows,
            'deduped_rows'            => $metric_deduped_rows,
            'clean_unique_count'      => count( $deduped_records ),
            'primary_accounts_count'  => $metric_created_primary,
            'dependents_count'        => $metric_created_dep,
            'unassigned_emails_count' => $metric_unassigned_email,
        );
    }
}

if ( ! function_exists( 'sywat_ingest_archer_households_batch' ) ) {
    /**
     * Ingest a batch array of household families into WordPress database with cache/hook suspension and fast hasher interception.
     *
     * @param array $batch_households Array of household member arrays.
     * @return array Metric counts of created primary accounts, added dependents, and updated user profiles.
     */
    function sywat_ingest_archer_households_batch( $batch_households ) {
        global $wpdb, $wp_hasher, $sywat_precomputed_hash;

        // Suspend object cache invalidations and unhook registration callbacks for maximum ingestion speed
        wp_suspend_cache_invalidation( true );
        remove_all_actions( 'user_register' );
        remove_all_actions( 'profile_update' );
        remove_all_actions( 'added_user_meta' );
        remove_all_actions( 'updated_user_meta' );

        // Start atomic MySQL Transaction for ultra-fast batch disk writes
        $wpdb->query( 'START TRANSACTION' );

        // Pre-compute Bcrypt hash for default password ("P@55w0rd") ONCE before loop
        $default_pass_hash = wp_hash_password( 'P@55w0rd' );

        // Intercept global $wp_hasher class to eliminate CPU Blowfish hashing inside wp_insert_user()
        if ( ! class_exists( 'SYWAT_Fast_Hasher' ) ) {
            class SYWAT_Fast_Hasher {
                public function HashPassword( $password ) {
                    global $sywat_precomputed_hash;
                    return $sywat_precomputed_hash;
                }
                public function CheckPassword( $password, $hash ) {
                    return true;
                }
            }
        }
        $sywat_precomputed_hash = $default_pass_hash;
        $old_hasher             = $wp_hasher;
        $wp_hasher              = new SYWAT_Fast_Hasher();

        // Bulk Pre-fetch Username Map ($O(1) RAM lookup)
        $username_map = array();
        $user_logins  = $wpdb->get_col( "SELECT user_login FROM {$wpdb->users}" );
        if ( ! empty( $user_logins ) ) {
            foreach ( $user_logins as $ul ) {
                $username_map[ strtolower( trim( $ul ) ) ] = true;
            }
        }

        // Bulk Pre-fetch NFAA ID Map ($O(1) RAM lookup)
        $nfaa_map = array();
        $nfaa_rows = $wpdb->get_results(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'sywat_nfaa_number' AND meta_value != ''"
        );
        if ( ! empty( $nfaa_rows ) ) {
            foreach ( $nfaa_rows as $nr ) {
                $nfaa_map[ trim( $nr->meta_value ) ] = intval( $nr->user_id );
            }
        }

        // Bulk Pre-fetch Email Map ($O(1) RAM lookup)
        $email_map = array();
        $email_rows = $wpdb->get_results(
            "SELECT ID, user_email FROM {$wpdb->users} WHERE user_email != ''"
        );
        if ( ! empty( $email_rows ) ) {
            foreach ( $email_rows as $er ) {
                $email_map[ strtolower( trim( $er->user_email ) ) ] = intval( $er->ID );
            }
        }

        $created_primary = 0;
        $created_dep     = 0;
        $updated_user    = 0;

        foreach ( $batch_households as $members ) {
            $head = array_shift( $members );

            $primary_email       = $head['email'];
            $is_unassigned_email = false;

            if ( empty( $primary_email ) || ! is_email( $primary_email ) ) {
                $nfaa_seed           = ! empty( $head['nfaa_number'] ) ? $head['nfaa_number'] : rand( 10000, 99999 );
                $primary_email       = 'unassigned-' . $nfaa_seed . '@system.sywat.local';
                $is_unassigned_email = true;
            }

            $existing_user_id = 0;

            if ( ! empty( $head['nfaa_number'] ) && isset( $nfaa_map[ $head['nfaa_number'] ] ) ) {
                $existing_user_id = $nfaa_map[ $head['nfaa_number'] ];
            }

            if ( ! $existing_user_id && ! $is_unassigned_email && isset( $email_map[ strtolower( $primary_email ) ] ) ) {
                $existing_user_id = $email_map[ strtolower( $primary_email ) ];
            }

            $primary_user_id = 0;

            if ( $existing_user_id ) {
                $primary_user_id = $existing_user_id;
                $updated_user++;

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

                while ( isset( $username_map[ strtolower( $username ) ] ) ) {
                    $username = $base_username . $counter;
                    $counter++;
                }
                $username_map[ strtolower( $username ) ] = true;

                $primary_user_id = wp_insert_user( array(
                    'user_login'   => $username,
                    'user_email'   => $primary_email,
                    'user_pass'    => 'P@55w0rd',
                    'first_name'   => $head['first_name'],
                    'last_name'    => $head['last_name'],
                    'display_name' => $head['first_name'] . ' ' . $head['last_name'],
                    'role'         => 'tfaa_member',
                ) );

                if ( ! is_wp_error( $primary_user_id ) ) {
                    $created_primary++;

                    if ( ! empty( $head['nfaa_number'] ) ) {
                        $nfaa_map[ $head['nfaa_number'] ] = $primary_user_id;
                    }
                    if ( ! $is_unassigned_email ) {
                        $email_map[ strtolower( $primary_email ) ] = $primary_user_id;
                    }
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

                // ---------------------------------------------------------------------
                // STAGE 4: DEPENDENT INGESTION & DEDUPLICATION
                // ---------------------------------------------------------------------
                if ( ! empty( $members ) ) {
                    $existing_deps = function_exists( 'sywat_get_linked_household_members' ) 
                        ? sywat_get_linked_household_members( $primary_user_id ) 
                        : array();

                    $existing_dep_map = array();
                    if ( is_array( $existing_deps ) ) {
                        foreach ( $existing_deps as $ex_d ) {
                            $ex_first = isset( $ex_d['first_name'] ) ? strtolower( trim( $ex_d['first_name'] ) ) : '';
                            $ex_last  = isset( $ex_d['last_name'] ) ? strtolower( trim( $ex_d['last_name'] ) ) : '';
                            $ex_dob   = isset( $ex_d['dob'] ) ? trim( $ex_d['dob'] ) : '';
                            $ex_nfaa  = isset( $ex_d['nfaa_number'] ) ? trim( $ex_d['nfaa_number'] ) : '';
                            $sys_id   = isset( $ex_d['system_id'] ) ? $ex_d['system_id'] : true;

                            if ( $ex_first && $ex_last ) {
                                $key = $ex_first . '|' . $ex_last . '|' . $ex_dob;
                                $existing_dep_map[ $key ] = $sys_id;
                            }
                            if ( ! empty( $ex_nfaa ) ) {
                                $existing_dep_map[ 'nfaa_' . $ex_nfaa ] = $sys_id;
                            }
                        }
                    }

                    foreach ( $members as $dep ) {
                        $dep_first = strtolower( trim( $dep['first_name'] ) );
                        $dep_last  = strtolower( trim( $dep['last_name'] ) );
                        $dep_dob   = trim( $dep['dob'] );
                        $dep_nfaa  = trim( $dep['nfaa_number'] );

                        $match_key1 = $dep_first . '|' . $dep_last . '|' . $dep_dob;
                        $match_key2 = ! empty( $dep_nfaa ) ? 'nfaa_' . $dep_nfaa : '';

                        $existing_sys_id = null;
                        if ( isset( $existing_dep_map[ $match_key1 ] ) ) {
                            $existing_sys_id = $existing_dep_map[ $match_key1 ];
                        } elseif ( $match_key2 && isset( $existing_dep_map[ $match_key2 ] ) ) {
                            $existing_sys_id = $existing_dep_map[ $match_key2 ];
                        }

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

                        if ( $existing_sys_id && is_string( $existing_sys_id ) && function_exists( 'sywat_update_household_dependent' ) ) {
                            sywat_update_household_dependent( $primary_user_id, $existing_sys_id, $dep_payload );
                        } elseif ( ! $existing_sys_id && function_exists( 'sywat_add_household_dependent' ) ) {
                            $res = sywat_add_household_dependent( $primary_user_id, $dep_payload );
                            if ( ! is_wp_error( $res ) ) {
                                $created_dep++;
                            }
                        }
                    }
                }
            }
        }

        // Restore standard WordPress password hasher class
        $wp_hasher = $old_hasher;

        // Commit atomic MySQL transaction
        $wpdb->query( 'COMMIT' );

        // Re-enable object cache invalidation
        wp_suspend_cache_invalidation( false );

        return array(
            'created_primary' => $created_primary,
            'created_dep'     => $created_dep,
            'updated_user'    => $updated_user,
        );
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

        $rows = sywat_parse_spreadsheet_rows( $file_path );
        if ( empty( $rows ) ) {
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Failed to parse spreadsheet content or file is empty.</div>';
        }

        $tree = sywat_build_archer_households_tree( $rows );

        if ( $mode === 'preview' ) {
            return array(
                'file_path'               => $file_path,
                'file_name'               => $file_name,
                'total_rows'              => $tree['total_rows'],
                'purged_rows'             => $tree['purged_rows'],
                'deduped_rows'            => $tree['deduped_rows'],
                'clean_unique_count'      => $tree['clean_unique_count'],
                'primary_accounts_count'  => $tree['primary_accounts_count'],
                'dependents_count'        => $tree['dependents_count'],
                'unassigned_emails_count' => $tree['unassigned_emails_count'],
                'households'              => $tree['households'],
            );
        }

        // Mode: Commit
        $res = sywat_ingest_archer_households_batch( $tree['households'] );

        wp_cache_flush();

        if ( file_exists( $file_path ) ) {
            @unlink( $file_path );
        }

        return sprintf(
            '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🚀 <strong>Batch Archer Import Complete!</strong> Processed <strong>%d</strong> total rows | Created <strong>%d</strong> Primary Accounts | Added <strong>%d</strong> Household Dependents | Updated <strong>%d</strong> Existing Database Profiles | Purged <strong>%d</strong> Injection/Garbage Rows (%d deduplicated). Default login password initialized to: <code>P@55w0rd</code></div>',
            $tree['total_rows'],
            $res['created_primary'],
            $res['created_dep'],
            $res['updated_user'],
            $tree['purged_rows'],
            $tree['deduped_rows']
        );
    }
}

if ( ! function_exists( 'sywat_ajax_import_archers_chunk' ) ) {
    /**
     * AJAX Endpoint: Chunked Archer Spreadsheet Ingestion Processor
     * Processes staged spreadsheet households in batches of 100 while returning percentage updates to the client progress bar.
     */
    function sywat_ajax_import_archers_chunk() {
        if ( ! isset( $_POST['sywat_nonce'] ) || ! wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_desk_action' ) ) {
            wp_send_json_error( array( 'message' => 'Security token expired. Please refresh the page and try again.' ) );
        }

        if ( ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( array( 'message' => 'Access denied. Only administrators can execute batch data imports.' ) );
        }

        @set_time_limit( 300 );
        @ini_set( 'memory_limit', '512M' );

        $user_id         = get_current_user_id();
        $persistent_file = isset( $_POST['persistent_file_path'] ) ? sanitize_text_field( $_POST['persistent_file_path'] ) : '';
        $offset          = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $limit           = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 100;
        $reset           = isset( $_POST['reset'] ) && $_POST['reset'] === 'yes';

        $hh_transient   = 'sywat_imp_hh_' . $user_id;
        $meta_transient = 'sywat_imp_meta_' . $user_id;

        // INITIALIZATION: Build household tree queue on offset 0 or reset
        if ( $offset === 0 || $reset || get_transient( $hh_transient ) === false ) {
            if ( empty( $persistent_file ) || ! file_exists( $persistent_file ) ) {
                wp_send_json_error( array( 'message' => 'Staged spreadsheet upload file missing or expired. Please upload again.' ) );
            }

            $rows = sywat_parse_spreadsheet_rows( $persistent_file );
            if ( empty( $rows ) ) {
                wp_send_json_error( array( 'message' => 'Spreadsheet file is empty or unparseable.' ) );
            }

            $tree               = sywat_build_archer_households_tree( $rows );
            $indexed_households = array_values( $tree['households'] );

            set_transient( $hh_transient, $indexed_households, HOUR_IN_SECONDS );
            set_transient( $meta_transient, array(
                'total_rows'       => $tree['total_rows'],
                'purged_rows'      => $tree['purged_rows'],
                'deduped_rows'     => $tree['deduped_rows'],
                'total_households' => count( $indexed_households ),
                'created_primary'  => 0,
                'created_dep'      => 0,
                'updated_user'     => 0,
            ), HOUR_IN_SECONDS );
        }

        $all_households = get_transient( $hh_transient );
        $meta           = get_transient( $meta_transient );

        if ( ! is_array( $all_households ) || ! is_array( $meta ) ) {
            wp_send_json_error( array( 'message' => 'Import state session expired. Please re-upload the spreadsheet file.' ) );
        }

        $total_households = count( $all_households );
        if ( $total_households === 0 || $offset >= $total_households ) {
            delete_transient( $hh_transient );
            delete_transient( $meta_transient );
            if ( file_exists( $persistent_file ) ) {
                @unlink( $persistent_file );
            }
            wp_cache_flush();

            wp_send_json_success( array(
                'finished'         => true,
                'next_offset'      => $total_households,
                'total_households' => $total_households,
                'created_primary'  => intval( $meta['created_primary'] ),
                'created_dep'      => intval( $meta['created_dep'] ),
                'updated_user'     => intval( $meta['updated_user'] ),
                'percent'          => 100,
                'message'          => sprintf( '🚀 Archer Import Complete! Created %d primary accounts, added %d dependents, and updated %d existing profiles.', $meta['created_primary'], $meta['created_dep'], $meta['updated_user'] ),
            ) );
        }

        $batch_households = array_slice( $all_households, $offset, $limit );
        $res              = sywat_ingest_archer_households_batch( $batch_households );

        $meta['created_primary'] = intval( $meta['created_primary'] ) + $res['created_primary'];
        $meta['created_dep']     = intval( $meta['created_dep'] ) + $res['created_dep'];
        $meta['updated_user']    = intval( $meta['updated_user'] ) + $res['updated_user'];
        set_transient( $meta_transient, $meta, HOUR_IN_SECONDS );

        $next_offset = $offset + count( $batch_households );
        $finished    = ( $next_offset >= $total_households );

        if ( $finished ) {
            delete_transient( $hh_transient );
            delete_transient( $meta_transient );
            if ( file_exists( $persistent_file ) ) {
                @unlink( $persistent_file );
            }
            wp_cache_flush();
        }

        $percent = min( 100, round( ( $next_offset / $total_households ) * 100 ) );

        wp_send_json_success( array(
            'finished'         => $finished,
            'next_offset'      => $next_offset,
            'total_households' => $total_households,
            'created_primary'  => intval( $meta['created_primary'] ),
            'created_dep'      => intval( $meta['created_dep'] ),
            'updated_user'     => intval( $meta['updated_user'] ),
            'percent'          => $percent,
            'message'          => $finished
                ? sprintf( '🚀 Archer Import Complete! Processed %d households (%d primary, %d dependents).', $total_households, $meta['created_primary'], $meta['created_dep'] )
                : sprintf( 'Imported %d of %d households (%d%%)', $next_offset, $total_households, $percent ),
        ) );
    }
}

add_action( 'wp_ajax_sywat_import_archers_chunk', 'sywat_ajax_import_archers_chunk' );
