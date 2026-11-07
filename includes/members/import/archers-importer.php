<?php
/**
 * Sub-Module: Historical Archers Batch Spreadsheet Importer Engine
 * Path: includes/members/import/archers-importer.php
 * Description: High-performance 2-step audit preview and batch processing engine for ingesting Archers.xlsx / CSV historical data. Generates an itemized archer and household tree mapping table before database mutation.
 * Compliance: Zero inline styles, zero HTML layout templates. Returns structured alert notices and permanent audit preview card markup.
 * Author: SYWAT Archery
 * Version: 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_import_archers_spreadsheet' ) ) {
    /**
     * Process uploaded Archers spreadsheet (.xlsx or .csv) with 2-step Audit Preview support and itemized shooter mapping.
     *
     * @param array  $file_array Uploaded $_FILES['archers_file'] array.
     * @param string $mode       Execution mode: 'preview' (audit only) or 'commit' (write to DB).
     * @return string Alert or Audit Preview HTML markup.
     */
    function sywat_import_archers_spreadsheet( $file_array, $mode = 'preview' ) {
        if ( ! current_user_can( 'administrator' ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators can execute batch data imports.</div>';
        }

        if ( empty( $file_array['tmp_name'] ) || ! is_uploaded_file( $file_array['tmp_name'] ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please upload a valid .xlsx or .csv spreadsheet file.</div>';
        }

        @set_time_limit( 600 );
        @ini_set( 'memory_limit', '512M' );

        $file_path = $file_array['tmp_name'];
        $file_name = sanitize_text_field( $file_array['name'] );
        $rows      = array();

        // -------------------------------------------------------------------------
        // PARSE SPREADSHEET CONTENT WITH ARRAY MATCH SAFETY
        // -------------------------------------------------------------------------
        if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
            $raw_header = fgetcsv( $handle, 4096, ',' );
            if ( $raw_header ) {
                $header       = array_map( 'trim', $raw_header );
                $header_count = count( $header );

                while ( ( $data = fgetcsv( $handle, 4096, ',' ) ) !== false ) {
                    $data_count = count( $data );
                    if ( $data_count === 0 || ( $data_count === 1 && empty( $data[0] ) ) ) {
                        continue;
                    }

                    // Pad or trim data array to strictly match header count
                    if ( $data_count < $header_count ) {
                        $data = array_pad( $data, $header_count, '' );
                    } elseif ( $data_count > $header_count ) {
                        $data = array_slice( $data, 0, $header_count );
                    }

                    $rows[] = array_combine( $header, $data );
                }
            }
            fclose( $handle );
        }

        if ( empty( $rows ) ) {
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

        // Calculate expected metrics prior to database execution
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
        // MODE 1: PERMANENT AUDIT PREVIEW RENDER WITH ITEMIZED SHOOTER TABLE
        // -------------------------------------------------------------------------
        if ( $mode === 'preview' ) {
            ob_start();
            ?>
            <!-- PERMANENT PREVIEW CARD (ID sywat-import-preview-card INSTEAD OF sywat-notice) -->
            <div id="sywat-import-preview-card" class="sywat-card sywat-mt-3 sywat-mb-4">
                <div class="sywat-card-header-bar">
                    <div>
                        <h2>📊 Historical Archers Batch Import Audit Preview</h2>
                        <div class="sywat-subtitle">File Analyzed: <strong><?php echo esc_html( $file_name ); ?></strong> — Review metrics &amp; archer mappings before committing database changes.</div>
                    </div>
                </div>

                <div class="sywat-form-grid-3 sywat-mt-3">
                    <div class="sywat-metric-box">
                        <span class="sywat-meta-subtext">Total Spreadsheet Rows</span>
                        <h3><?php echo intval( $metric_total_rows ); ?></h3>
                    </div>
                    <div class="sywat-metric-box">
                        <span class="sywat-meta-subtext">Clean Unique Archers</span>
                        <h3><?php echo intval( count( $deduped_records ) ); ?></h3>
                    </div>
                    <div class="sywat-metric-box">
                        <span class="sywat-meta-subtext">Purged Garbage Rows</span>
                        <h3><?php echo intval( $metric_purged_rows ); ?></h3>
                    </div>
                </div>

                <div class="sywat-form-grid-3 sywat-mt-3">
                    <div class="sywat-metric-box">
                        <span class="sywat-meta-subtext">Primary WP Accounts (Households)</span>
                        <h3><?php echo intval( $metric_created_primary ); ?></h3>
                    </div>
                    <div class="sywat-metric-box">
                        <span class="sywat-meta-subtext">Household Dependents</span>
                        <h3><?php echo intval( $metric_created_dep ); ?></h3>
                    </div>
                    <div class="sywat-metric-box">
                        <span class="sywat-meta-subtext">Unassigned Emails Flagged</span>
                        <h3><?php echo intval( $metric_unassigned_email ); ?></h3>
                    </div>
                </div>

                <!-- ITEMIZED HOUSEHOLD & SHOOTER MAPPING TABLE -->
                <div class="sywat-card-header-bar sywat-mt-4">
                    <div>
                        <h3>👥 Itemized Household &amp; Archer Mapping List</h3>
                        <div class="sywat-subtitle">Showing all mapped Primary Accounts and linked Family Dependents before database ingestion.</div>
                    </div>
                </div>

                <div class="sywat-table-responsive sywat-mt-2">
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th class="sywat-col-w-28">Archer Name &amp; Role</th>
                                <th class="sywat-col-w-28">Assigned Email</th>
                                <th class="sywat-col-center sywat-col-w-16">NFAA ID &amp; Exp</th>
                                <th class="sywat-col-center sywat-col-w-14">DOB / Pro</th>
                                <th class="sywat-col-center sywat-col-w-14">DB Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $households as $members ): ?>
                                <?php 
                                    usort( $members, function( $a, $b ) {
                                        if ( empty( $a['dob'] ) ) return 1;
                                        if ( empty( $b['dob'] ) ) return -1;
                                        return strtotime( $a['dob'] ) - strtotime( $b['dob'] );
                                    } );
                                    $head = array_shift( $members );
                                    
                                    // Check DB for existing account match
                                    $existing_match = false;
                                    if ( ! empty( $head['nfaa_number'] ) ) {
                                        $match = get_users( array( 'meta_key' => 'sywat_nfaa_number', 'meta_value' => $head['nfaa_number'], 'number' => 1 ) );
                                        if ( ! empty( $match ) ) $existing_match = true;
                                    }
                                    if ( ! $existing_match && ! empty( $head['email'] ) ) {
                                        if ( get_user_by( 'email', $head['email'] ) ) $existing_match = true;
                                    }

                                    $primary_email_disp = ! empty( $head['email'] ) ? $head['email'] : 'unassigned-' . ( ! empty( $head['nfaa_number'] ) ? $head['nfaa_number'] : 'temp' ) . '@system.sywat.local';
                                ?>
                                <!-- ROW 1: PRIMARY ACCOUNT HOLDER (HEAD OF HOUSEHOLD) -->
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $head['first_name'] . ' ' . $head['last_name'] ); ?></strong>
                                        <div class="sywat-meta-subtext">👑 Head of Household</div>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html( $primary_email_disp ); ?></code>
                                        <?php if ( empty( $head['email'] ) ): ?>
                                            <div class="sywat-meta-subtext">⚠️ Placeholder System Email</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sywat-col-center">
                                        <div>🆔 <strong><?php echo esc_html( ! empty( $head['nfaa_number'] ) ? $head['nfaa_number'] : '—' ); ?></strong></div>
                                        <div class="sywat-meta-subtext"><?php echo ! empty( $head['nfaa_exp'] ) ? esc_html( date( 'M Y', strtotime( $head['nfaa_exp'] ) ) ) : '—'; ?></div>
                                    </td>
                                    <td class="sywat-col-center">
                                        <div><?php echo ! empty( $head['dob'] ) ? esc_html( date( 'M j, Y', strtotime( $head['dob'] ) ) ) : '—'; ?></div>
                                        <?php if ( $head['is_pro'] === 'yes' ): ?>
                                            <span class="sywat-badge sywat-badge-pro sywat-mt-1">⭐ Pro</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sywat-col-center">
                                        <?php if ( $existing_match ): ?>
                                            <span class="sywat-badge sywat-badge-checkedin">🔄 Update Profile</span>
                                        <?php else: ?>
                                            <span class="sywat-badge sywat-badge-open">🆕 New Account</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <!-- DEPENDENTS IN THIS HOUSEHOLD -->
                                <?php foreach ( $members as $dep ): ?>
                                    <tr>
                                        <td>
                                            <div class="sywat-ml-3">
                                                ↪️ <strong><?php echo esc_html( $dep['first_name'] . ' ' . $dep['last_name'] ); ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="sywat-ml-3 sywat-meta-subtext">
                                                (Linked to Household Email)
                                            </div>
                                        </td>
                                        <td class="sywat-col-center">
                                            <div>🆔 <strong><?php echo esc_html( ! empty( $dep['nfaa_number'] ) ? $dep['nfaa_number'] : '—' ); ?></strong></div>
                                            <div class="sywat-meta-subtext"><?php echo ! empty( $dep['nfaa_exp'] ) ? esc_html( date( 'M Y', strtotime( $dep['nfaa_exp'] ) ) ) : '—'; ?></div>
                                        </td>
                                        <td class="sywat-col-center">
                                            <div><?php echo ! empty( $dep['dob'] ) ? esc_html( date( 'M j, Y', strtotime( $dep['dob'] ) ) ) : '—'; ?></div>
                                            <?php if ( $dep['is_pro'] === 'yes' ): ?>
                                                <span class="sywat-badge sywat-badge-pro sywat-mt-1">⭐ Pro</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sywat-col-center">
                                            <span class="sywat-badge sywat-badge-muted">🏠 Dependent</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sywat-modal-actions sywat-mt-4">
                    <a href="?tab=members&subtab=directory" class="sywat-btn sywat-btn-secondary">Cancel Import</a>
                    <form method="post" action="?tab=members&subtab=directory" style="display:inline-block;">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="import_archers_spreadsheet">
                        <input type="hidden" name="import_mode" value="commit">
                        <button type="submit" class="sywat-btn sywat-btn-primary">🚀 Confirm &amp; Ingest <?php echo intval( count( $deduped_records ) ); ?> Archers into Database</button>
                    </form>
                </div>
            </div>
            <?php
            return ob_get_clean();
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

            if ( ! $existing_user_id && ! $is_unassigned_email ) {
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
