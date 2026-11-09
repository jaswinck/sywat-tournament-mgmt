<?php
/**
 * Sub-Module: Member & Board Action Handling & AJAX Batch Engines
 * Path: includes/members/members-actions.php
 * Description: Nonce-verified action processor for member account creation, household sub-profile management, Head of Household swapping, unlinking dependents into unassigned data pools, transferring standalone primary accounts or linking unassigned pool archers into household rosters, profile updates, DOB/NFAA meta saving, Gender/Pro selection processing, automated bi-directional board position role-syncing and board role preservation during profile edits (including complete Field Governor district mappings in fallback role map), high-performance protected test data purging with cache/hook suspension, 2-step batch spreadsheet importer persistent file staging, output-buffered AJAX progress chunking endpoints, dynamic archer autocomplete search APIs, live Head of Household autocomplete search APIs, self-service Head of Household profile updates, and DRY protected account hashtable maps.
 * Compliance: Zero visual HTML layout templates. Returns standardized alert notices and sets preview data globals.
 * Author: SYWAT Archery
 * Version: 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_validate_password_rules' ) ) {
    /**
     * Validate password rules for new primary accounts or password resets.
     *
     * @param string $password         Password string.
     * @param string $confirm_password Confirm password string.
     * @return bool|string True if valid, or error message string.
     */
    function sywat_validate_password_rules( $password, $confirm_password ) {
        if ( $password !== $confirm_password ) {
            return 'Passwords do not match. Please ensure both password fields are identical.';
        }
        if ( strlen( $password ) < 8 ) {
            return 'Password must be at least 8 characters long.';
        }
        if ( ! preg_match( '/[A-Z]/', $password ) ) {
            return 'Password must contain at least one uppercase letter (A-Z).';
        }
        if ( ! preg_match( '/[a-z]/', $password ) ) {
            return 'Password must contain at least one lowercase letter (a-z).';
        }
        if ( ! preg_match( '/[0-9]/', $password ) ) {
            return 'Password must contain at least one number (0-9).';
        }
        return true;
    }
}

if ( ! function_exists( 'sywat_get_protected_user_map' ) ) {
    /**
     * Build O(1) hashtable map of protected account IDs (Administrators, Officers, Shop Managers, Board Members, active user).
     *
     * @return array Map array with user IDs as keys.
     */
    function sywat_get_protected_user_map() {
        global $wpdb;

        $protected_map   = array();
        $current_user_id = get_current_user_id();

        // Always protect active logged-in account
        if ( $current_user_id > 0 ) {
            $protected_map[ $current_user_id ] = true;
        }

        // Bulk pre-fetch user capabilities to protect Admins, Shop Managers, and Officers ($O(1) lookup)
        $cap_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
                $wpdb->prefix . 'capabilities'
            )
        );

        if ( ! empty( $cap_rows ) ) {
            foreach ( $cap_rows as $cr ) {
                $uid  = intval( $cr->user_id );
                $caps = @maybe_unserialize( $cr->meta_value );
                if ( is_array( $caps ) ) {
                    foreach ( array_keys( $caps ) as $role_slug ) {
                        $r_clean = strtolower( $role_slug );
                        if ( preg_match( '/(administrator|sywat_shop_manager|sywat_shop_user|president|treasurer|secretary|governor|director|officer|manager|webmaster)/i', $r_clean ) ) {
                            $protected_map[ $uid ] = true;
                            break;
                        }
                    }
                }
            }
        }

        // Bulk pre-fetch active Board Member assignments
        $board_rows = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'sywat_board_positions' AND meta_value != '' AND meta_value != 'a:0:{}'"
        );
        if ( ! empty( $board_rows ) ) {
            foreach ( $board_rows as $b_uid ) {
                $protected_map[ intval( $b_uid ) ] = true;
            }
        }

        return $protected_map;
    }
}

if ( ! function_exists( 'sywat_sync_user_board_roles' ) ) {
    /**
     * Synchronizes WordPress user roles based on assigned TFAA Board Positions.
     * Acts as the single source of truth for officer role binding across all form submissions.
     *
     * @param int   $user_id   WordPress User ID.
     * @param array $positions Array of board position keys.
     */
    function sywat_sync_user_board_roles( $user_id, $positions ) {
        $user_id = intval( $user_id );
        if ( $user_id <= 0 ) return;

        $user_obj = new WP_User( $user_id );
        if ( ! $user_obj->exists() ) return;

        $officer_roles = array(
            'tfaa_president', 'tfaa_vice_president', 'tfaa_secretary', 
            'tfaa_treasurer', 'tfaa_social_media_director', 'tfaa_webmaster', 
            'tfaa_tournament_director', 'tfaa_field_governor'
        );

        if ( empty( $positions ) ) {
            delete_user_meta( $user_id, 'sywat_board_positions' );
            foreach ( $officer_roles as $o_role ) {
                if ( in_array( $o_role, (array) $user_obj->roles, true ) ) {
                    $user_obj->remove_role( $o_role );
                }
            }
            return;
        }

        update_user_meta( $user_id, 'sywat_board_positions', $positions );

        $pos_to_role_map = function_exists( 'sywat_get_board_position_role_map' ) ? sywat_get_board_position_role_map() : array(
            'tfaa_president'             => 'tfaa_president',
            'president'                  => 'tfaa_president',
            'tfaa_vice_president'        => 'tfaa_vice_president',
            'vice_president'             => 'tfaa_vice_president',
            'tfaa_secretary'             => 'tfaa_secretary',
            'secretary'                  => 'tfaa_secretary',
            'tfaa_treasurer'             => 'tfaa_treasurer',
            'treasurer'                  => 'tfaa_treasurer',
            'tfaa_social_media_director' => 'tfaa_social_media_director',
            'tfaa_social_media'          => 'tfaa_social_media_director',
            'social_media'               => 'tfaa_social_media_director',
            'tfaa_webmaster'             => 'tfaa_webmaster',
            'webmaster'                  => 'tfaa_webmaster',
            'tfaa_tournament_director'   => 'tfaa_tournament_director',
            'tournament_director'        => 'tfaa_tournament_director',
            'tfaa_field_governor'        => 'tfaa_field_governor',
            'field_governor'             => 'tfaa_field_governor',
            'field_governor_1'           => 'tfaa_field_governor',
            'field_governor_2'           => 'tfaa_field_governor',
            'field_governor_3'           => 'tfaa_field_governor',
            'field_governor_4'           => 'tfaa_field_governor',
            'field_governor_5'           => 'tfaa_field_governor',
            'field_governor_6'           => 'tfaa_field_governor',
            'field_governor_7'           => 'tfaa_field_governor',
        );

        $target_board_roles = array();
        foreach ( $positions as $p_key ) {
            $p_clean = strtolower( trim( $p_key ) );
            if ( isset( $pos_to_role_map[ $p_clean ] ) ) {
                $target_board_roles[] = $pos_to_role_map[ $p_clean ];
            } elseif ( strpos( $p_clean, 'governor' ) !== false || strpos( $p_clean, 'district' ) !== false ) {
                $target_board_roles[] = 'tfaa_field_governor';
            }
        }

        foreach ( array_unique( $target_board_roles ) as $r_slug ) {
            if ( ! in_array( $r_slug, (array) $user_obj->roles, true ) ) {
                $user_obj->add_role( $r_slug );
            }
        }

        foreach ( $officer_roles as $o_role ) {
            if ( in_array( $o_role, (array) $user_obj->roles, true ) && ! in_array( $o_role, $target_board_roles, true ) ) {
                $user_obj->remove_role( $o_role );
            }
        }
    }
}

if ( ! function_exists( 'sywat_handle_member_post_actions' ) ) {
    /**
     * Central POST handler for member actions.
     *
     * @return string Alert notice markup.
     */
    function sywat_handle_member_post_actions() {
        // Nonce Verification
        if ( ! isset( $_POST['sywat_nonce'] ) || ! wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_desk_action' ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        }

        $action = isset( $_POST['sywat_action'] ) ? sanitize_text_field( $_POST['sywat_action'] ) : '';

        // 0. 2-STEP BATCH HISTORICAL ARCHERS SPREADSHEET IMPORT HANDLER
        if ( $action === 'import_archers_spreadsheet' ) {
            if ( ! current_user_can( 'administrator' ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators can execute batch data imports.</div>';
            }

            $import_mode = isset( $_POST['import_mode'] ) ? sanitize_text_field( $_POST['import_mode'] ) : 'preview';

            $importer_path = plugin_dir_path( __FILE__ ) . 'import/archers-importer.php';
            if ( file_exists( $importer_path ) ) {
                require_once $importer_path;
            }

            if ( ! function_exists( 'sywat_import_archers_spreadsheet' ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Archer Importer Module Engine is missing or unavailable.</div>';
            }

            if ( $import_mode === 'preview' ) {
                if ( empty( $_FILES['archers_file'] ) || empty( $_FILES['archers_file']['tmp_name'] ) ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a spreadsheet file (.xlsx or .csv) to import.</div>';
                }

                $preview_result = sywat_import_archers_spreadsheet( $_FILES['archers_file'], 'preview' );
                if ( is_string( $preview_result ) ) {
                    return $preview_result;
                }

                if ( is_array( $preview_result ) ) {
                    $GLOBALS['sywat_import_preview_data'] = $preview_result;
                    return '<div class="sywat-alert sywat-alert-info">🔍 <strong>File Audited:</strong> Review the batch archer mapping details in the pop-up modal below.</div>';
                }
            } elseif ( $import_mode === 'commit' ) {
                $persistent_file = isset( $_POST['persistent_file_path'] ) ? sanitize_text_field( $_POST['persistent_file_path'] ) : '';

                if ( empty( $persistent_file ) || ! file_exists( $persistent_file ) ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Staged upload file missing or expired. Please upload the spreadsheet again.</div>';
                }

                return sywat_import_archers_spreadsheet( $persistent_file, 'commit' );
            }
        }

        // 1. SWAP HEAD OF HOUSEHOLD WITH A DEPENDENT SUB-PROFILE
        if ( $action === 'swap_head_of_household' ) {
            $primary_user_id  = isset( $_POST['primary_user_id'] ) ? intval( $_POST['primary_user_id'] ) : 0;
            $target_system_id = isset( $_POST['target_system_id'] ) ? sanitize_text_field( $_POST['target_system_id'] ) : '';

            if ( $primary_user_id > 0 && ! empty( $target_system_id ) ) {
                $primary_user = get_userdata( $primary_user_id );
                $dep          = function_exists( 'sywat_get_household_dependent_by_system_id' ) 
                    ? sywat_get_household_dependent_by_system_id( $primary_user_id, $target_system_id ) 
                    : null;

                if ( $primary_user && $dep ) {
                    $old_head_dep = array(
                        'first_name'      => get_user_meta( $primary_user_id, 'first_name', true ) ? get_user_meta( $primary_user_id, 'first_name', true ) : $primary_user->first_name,
                        'last_name'       => get_user_meta( $primary_user_id, 'last_name', true ) ? get_user_meta( $primary_user_id, 'last_name', true ) : $primary_user->last_name,
                        'email'           => $primary_user->user_email,
                        'phone'           => get_user_meta( $primary_user_id, 'sywat_phone', true ),
                        'gender'          => get_user_meta( $primary_user_id, 'sywat_gender', true ),
                        'is_pro'          => get_user_meta( $primary_user_id, 'sywat_is_pro', true ),
                        'dob'             => get_user_meta( $primary_user_id, 'sywat_dob', true ),
                        'accommodations'  => get_user_meta( $primary_user_id, 'sywat_accommodations', true ),
                        'nfaa_number'     => get_user_meta( $primary_user_id, 'sywat_nfaa_number', true ),
                        'nfaa_expiration' => get_user_meta( $primary_user_id, 'sywat_nfaa_expiration', true ),
                        'address_1'       => get_user_meta( $primary_user_id, 'sywat_address_1', true ),
                        'address_2'       => get_user_meta( $primary_user_id, 'sywat_address_2', true ),
                        'city'            => get_user_meta( $primary_user_id, 'sywat_city', true ),
                        'state'           => get_user_meta( $primary_user_id, 'sywat_state', true ),
                        'zip'             => get_user_meta( $primary_user_id, 'sywat_zip', true ),
                    );

                    $new_first = isset( $dep['first_name'] ) ? $dep['first_name'] : '';
                    $new_last  = isset( $dep['last_name'] ) ? $dep['last_name'] : '';
                    $new_email = ! empty( $dep['email'] ) ? $dep['email'] : $primary_user->user_email;

                    wp_update_user( array(
                        'ID'           => $primary_user_id,
                        'first_name'   => $new_first,
                        'last_name'    => $new_last,
                        'display_name' => trim( $new_first . ' ' . $new_last ),
                        'user_email'   => $new_email,
                    ) );

                    update_user_meta( $primary_user_id, 'sywat_nfaa_number', isset( $dep['nfaa_number'] ) ? $dep['nfaa_number'] : '' );
                    update_user_meta( $primary_user_id, 'sywat_tfaa_number', isset( $dep['nfaa_number'] ) ? $dep['nfaa_number'] : '' );
                    update_user_meta( $primary_user_id, 'sywat_nfaa_expiration', isset( $dep['nfaa_expiration'] ) ? $dep['nfaa_expiration'] : '' );
                    update_user_meta( $primary_user_id, 'sywat_dob', isset( $dep['dob'] ) ? $dep['dob'] : '' );
                    update_user_meta( $primary_user_id, 'sywat_gender', isset( $dep['gender'] ) ? $dep['gender'] : 'unassigned' );
                    update_user_meta( $primary_user_id, 'sywat_is_pro', isset( $dep['is_pro'] ) ? $dep['is_pro'] : 'no' );
                    if ( ! empty( $dep['phone'] ) ) {
                        update_user_meta( $primary_user_id, 'sywat_phone', $dep['phone'] );
                    }

                    if ( function_exists( 'sywat_update_household_dependent' ) ) {
                        sywat_update_household_dependent( $primary_user_id, $target_system_id, $old_head_dep );
                    }

                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">👑 <strong>' . esc_html( $new_first . ' ' . $new_last ) . '</strong> is now designated as the Head of Household!</div>';
                }
            }
        }

        // 2. PURGE TEST MEMBERS (HIGH-PERFORMANCE PROTECTED BULK RESET WITH CACHE & HOOK SUSPENSION)
        if ( $action === 'purge_test_members' ) {
            if ( ! current_user_can( 'administrator' ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators can execute batch data purges.</div>';
            }

            require_once ABSPATH . 'wp-admin/includes/user.php';
            global $wpdb;

            $protected_map   = sywat_get_protected_user_map();
            $all_user_ids    = $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" );
            $deleted_count   = 0;
            $protected_count = 0;

            if ( ! empty( $all_user_ids ) ) {
                wp_suspend_cache_invalidation( true );
                remove_all_actions( 'delete_user' );
                remove_all_actions( 'deleted_user' );

                $wpdb->query( 'START TRANSACTION' );

                foreach ( $all_user_ids as $uid_raw ) {
                    $uid = intval( $uid_raw );

                    if ( isset( $protected_map[ $uid ] ) ) {
                        $protected_count++;
                        continue;
                    }

                    if ( wp_delete_user( $uid ) ) {
                        $deleted_count++;
                    }
                }

                $wpdb->query( 'COMMIT' );
                wp_suspend_cache_invalidation( false );
                wp_cache_flush();
            }

            return sprintf(
                '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🗑️ <strong>Batch Test Data Purge Complete!</strong> Permanently deleted <strong>%d</strong> test member accounts. Retained and protected <strong>%d</strong> Officer, Shop Manager, and Administrator accounts.</div>',
                $deleted_count,
                $protected_count
            );
        }

        // 3. CREATE NEW MEMBER ACCOUNT OR HOUSEHOLD DEPENDENT
        if ( $action === 'create_member' ) {
            $primary_user_id = isset( $_POST['primary_user_id'] ) ? intval( $_POST['primary_user_id'] ) : 0;
            $first_name      = ucfirst( strtolower( sanitize_text_field( $_POST['first_name'] ) ) );
            $last_name       = ucfirst( strtolower( sanitize_text_field( $_POST['last_name'] ) ) );
            $email           = sanitize_email( $_POST['email'] );
            $dob             = isset( $_POST['dob'] ) ? sanitize_text_field( $_POST['dob'] ) : '';
            $gender          = isset( $_POST['gender'] ) ? strtolower( sanitize_text_field( $_POST['gender'] ) ) : 'unassigned';
            $is_pro          = ( isset( $_POST['is_pro'] ) && $_POST['is_pro'] === 'yes' ) ? 'yes' : 'no';
            $accommodations  = isset( $_POST['accommodations'] ) ? sanitize_text_field( $_POST['accommodations'] ) : 'none';
            $nfaa_number     = isset( $_POST['nfaa_number'] ) ? sanitize_text_field( $_POST['nfaa_number'] ) : '';
            $nfaa_expiration = isset( $_POST['nfaa_expiration'] ) ? sanitize_text_field( $_POST['nfaa_expiration'] ) : '';

            // HOUSEHOLD DEPENDENT SUB-PROFILE CREATION
            if ( $primary_user_id > 0 ) {
                if ( empty( $first_name ) || empty( $last_name ) || empty( $dob ) ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please provide a valid First Name, Last Name, and Date of Birth for the family member.</div>';
                }

                $dep_payload = array(
                    'first_name'      => $first_name,
                    'last_name'       => $last_name,
                    'email'           => $email,
                    'phone'           => isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '',
                    'gender'          => in_array( $gender, array( 'male', 'female', 'unassigned' ), true ) ? $gender : 'unassigned',
                    'is_pro'          => $is_pro,
                    'dob'             => $dob,
                    'accommodations'  => $accommodations,
                    'nfaa_number'     => $nfaa_number,
                    'nfaa_expiration' => $nfaa_expiration,
                    'address_1'       => isset( $_POST['address_1'] ) ? sanitize_text_field( $_POST['address_1'] ) : '',
                    'address_2'       => isset( $_POST['address_2'] ) ? sanitize_text_field( $_POST['address_2'] ) : '',
                    'city'            => isset( $_POST['city'] ) ? sanitize_text_field( $_POST['city'] ) : '',
                    'state'           => isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( $_POST['state'] ) ) : '',
                    'zip'             => isset( $_POST['zip'] ) ? sanitize_text_field( $_POST['zip'] ) : '',
                );

                if ( function_exists( 'sywat_add_household_dependent' ) ) {
                    $system_id = sywat_add_household_dependent( $primary_user_id, $dep_payload );
                    if ( is_wp_error( $system_id ) ) {
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $system_id->get_error_message() ) . '</div>';
                    }
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Family member <strong>' . esc_html( $first_name . ' ' . $last_name ) . '</strong> added to household successfully!</div>';
                }
            }

            // STANDALONE PRIMARY ACCOUNT CREATION
            $password         = isset( $_POST['password'] ) ? $_POST['password'] : '';
            $confirm_password = isset( $_POST['confirm_password'] ) ? $_POST['confirm_password'] : '';
            $phone            = sanitize_text_field( $_POST['phone'] );
            $is_nfaa          = ( isset( $_POST['is_nfaa_member'] ) && $_POST['is_nfaa_member'] === 'yes' ) ? 'yes' : 'no';
            $submitted_roles  = isset( $_POST['sywat_roles'] ) && is_array( $_POST['sywat_roles'] ) ? array_map( 'sanitize_text_field', $_POST['sywat_roles'] ) : array( 'tfaa_member' );
            $board_positions  = isset( $_POST['board_positions'] ) && is_array( $_POST['board_positions'] ) ? array_map( 'sanitize_text_field', $_POST['board_positions'] ) : array();
            $shop_id          = isset( $_POST['sywat_shop_id'] ) ? sanitize_text_field( $_POST['sywat_shop_id'] ) : '';

            $address_1 = isset( $_POST['address_1'] ) ? sanitize_text_field( $_POST['address_1'] ) : '';
            $address_2 = isset( $_POST['address_2'] ) ? sanitize_text_field( $_POST['address_2'] ) : '';
            $city      = isset( $_POST['city'] ) ? sanitize_text_field( $_POST['city'] ) : '';
            $state     = isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( $_POST['state'] ) ) : '';
            $zip       = isset( $_POST['zip'] ) ? sanitize_text_field( $_POST['zip'] ) : '';

            if ( empty( $first_name ) || empty( $last_name ) || ! is_email( $email ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please provide a valid First Name, Last Name, and Email Address.</div>';
            }

            if ( email_exists( $email ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">An account with email address "' . esc_html( $email ) . '" already exists.</div>';
            }

            $pw_val = sywat_validate_password_rules( $password, $confirm_password );
            if ( $pw_val !== true ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $pw_val ) . '</div>';
            }

            $base_username = sanitize_user( $first_name . '.' . $last_name, true );
            $username      = $base_username;
            $counter       = 1;

            while ( username_exists( $username ) ) {
                $username = $base_username . $counter;
                $counter++;
            }

            $user_id = wp_insert_user( array(
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => $password,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'display_name' => $first_name . ' ' . $last_name,
            ) );

            if ( is_wp_error( $user_id ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $user_id->get_error_message() ) . '</div>';
            }

            $new_user_obj = new WP_User( $user_id );
            if ( $new_user_obj->exists() ) {
                $new_user_obj->set_role( reset( $submitted_roles ) );
                foreach ( $submitted_roles as $r_slug ) {
                    $new_user_obj->add_role( $r_slug );
                }
            }

            if ( function_exists( 'sywat_sync_user_board_roles' ) ) {
                sywat_sync_user_board_roles( $user_id, $board_positions );
            }

            $system_id = function_exists( 'sywat_get_user_system_id' ) ? sywat_get_user_system_id( $user_id ) : '';

            update_user_meta( $user_id, 'sywat_nfaa_number', $nfaa_number );
            update_user_meta( $user_id, 'sywat_tfaa_number', $nfaa_number );
            update_user_meta( $user_id, 'sywat_is_nfaa_member', $is_nfaa );
            update_user_meta( $user_id, 'sywat_phone', $phone );
            update_user_meta( $user_id, 'sywat_gender', in_array( $gender, array( 'male', 'female', 'unassigned' ), true ) ? $gender : 'unassigned' );
            update_user_meta( $user_id, 'sywat_is_pro', $is_pro );
            update_user_meta( $user_id, 'sywat_dob', $dob );
            update_user_meta( $user_id, 'sywat_accommodations', $accommodations );
            update_user_meta( $user_id, 'sywat_nfaa_expiration', $nfaa_expiration );
            update_user_meta( $user_id, 'sywat_address_1', $address_1 );
            update_user_meta( $user_id, 'sywat_address_2', $address_2 );
            update_user_meta( $user_id, 'sywat_city', $city );
            update_user_meta( $user_id, 'sywat_state', $state );
            update_user_meta( $user_id, 'sywat_zip', $zip );

            if ( ! empty( $shop_id ) ) {
                update_user_meta( $user_id, 'sywat_shop_id', $shop_id );
            }

            $disp_nfaa = ! empty( $nfaa_number ) ? $nfaa_number : 'Unassigned';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Primary Member account created successfully! Username: <strong>' . esc_html( $username ) . '</strong> | NFAA ID: <strong>' . esc_html( $disp_nfaa ) . '</strong></div>';
        }

        // 4. UPDATE EXISTING MEMBER PROFILE (OR DEPENDENT SUB-PROFILE)
        if ( $action === 'update_member' ) {
            $user_id         = isset( $_POST['target_user_id'] ) ? intval( $_POST['target_user_id'] ) : 0;
            $primary_user_id = isset( $_POST['primary_user_id'] ) ? intval( $_POST['primary_user_id'] ) : 0;
            $system_id       = isset( $_POST['target_system_id'] ) ? sanitize_text_field( $_POST['target_system_id'] ) : '';

            $gender          = isset( $_POST['gender'] ) ? strtolower( sanitize_text_field( $_POST['gender'] ) ) : 'unassigned';
            $is_pro          = ( isset( $_POST['is_pro'] ) && $_POST['is_pro'] === 'yes' ) ? 'yes' : 'no';

            // Update Dependent Sub-Profile
            if ( $primary_user_id > 0 && ! empty( $system_id ) ) {
                $dep_update = array(
                    'first_name'      => sanitize_text_field( $_POST['first_name'] ),
                    'last_name'       => sanitize_text_field( $_POST['last_name'] ),
                    'email'           => sanitize_email( $_POST['email'] ),
                    'phone'           => sanitize_text_field( $_POST['phone'] ),
                    'gender'          => in_array( $gender, array( 'male', 'female', 'unassigned' ), true ) ? $gender : 'unassigned',
                    'is_pro'          => $is_pro,
                    'dob'             => sanitize_text_field( $_POST['dob'] ),
                    'accommodations'  => isset( $_POST['accommodations'] ) ? sanitize_text_field( $_POST['accommodations'] ) : 'none',
                    'nfaa_number'     => sanitize_text_field( $_POST['nfaa_number'] ),
                    'nfaa_expiration' => sanitize_text_field( $_POST['nfaa_expiration'] ),
                    'address_1'       => sanitize_text_field( $_POST['address_1'] ),
                    'address_2'       => sanitize_text_field( $_POST['address_2'] ),
                    'city'            => sanitize_text_field( $_POST['city'] ),
                    'state'           => strtoupper( sanitize_text_field( $_POST['state'] ) ),
                    'zip'             => sanitize_text_field( $_POST['zip'] ),
                );

                if ( function_exists( 'sywat_update_household_dependent' ) ) {
                    $res = sywat_update_household_dependent( $primary_user_id, $system_id, $dep_update );
                    if ( is_wp_error( $res ) ) {
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $res->get_error_message() ) . '</div>';
                    }
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Household member sub-profile updated successfully.</div>';
                }
            }

            // Update Primary Member Profile
            $first_name      = sanitize_text_field( $_POST['first_name'] );
            $last_name       = sanitize_text_field( $_POST['last_name'] );
            $email           = sanitize_email( $_POST['email'] );
            $phone           = sanitize_text_field( $_POST['phone'] );
            $is_nfaa         = ( isset( $_POST['is_nfaa_member'] ) && $_POST['is_nfaa_member'] === 'yes' ) ? 'yes' : 'no';
            $submitted_roles = isset( $_POST['sywat_roles'] ) && is_array( $_POST['sywat_roles'] ) ? array_map( 'sanitize_text_field', $_POST['sywat_roles'] ) : array();
            $board_positions = isset( $_POST['board_positions'] ) && is_array( $_POST['board_positions'] ) ? array_map( 'sanitize_text_field', $_POST['board_positions'] ) : array();
            $shop_id         = isset( $_POST['sywat_shop_id'] ) ? sanitize_text_field( $_POST['sywat_shop_id'] ) : '';
            $new_pw          = isset( $_POST['new_password'] ) ? $_POST['new_password'] : '';
            $confirm_new_pw  = isset( $_POST['confirm_new_password'] ) ? $_POST['confirm_new_password'] : '';

            $dob             = isset( $_POST['dob'] ) ? sanitize_text_field( $_POST['dob'] ) : '';
            $accommodations  = isset( $_POST['accommodations'] ) ? sanitize_text_field( $_POST['accommodations'] ) : 'none';
            $nfaa_number     = isset( $_POST['nfaa_number'] ) ? sanitize_text_field( $_POST['nfaa_number'] ) : '';
            $nfaa_expiration = isset( $_POST['nfaa_expiration'] ) ? sanitize_text_field( $_POST['nfaa_expiration'] ) : '';
            $address_1       = isset( $_POST['address_1'] ) ? sanitize_text_field( $_POST['address_1'] ) : '';
            $address_2       = isset( $_POST['address_2'] ) ? sanitize_text_field( $_POST['address_2'] ) : '';
            $city            = isset( $_POST['city'] ) ? sanitize_text_field( $_POST['city'] ) : '';
            $state           = isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( $_POST['state'] ) ) : '';
            $zip             = isset( $_POST['zip'] ) ? sanitize_text_field( $_POST['zip'] ) : '';

            if ( $user_id > 0 && get_userdata( $user_id ) ) {
                if ( ! empty( $new_pw ) || ! empty( $confirm_new_pw ) ) {
                    $pw_val = sywat_validate_password_rules( $new_pw, $confirm_new_pw );
                    if ( $pw_val !== true ) {
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $pw_val ) . '</div>';
                    }
                    wp_set_password( $new_pw, $user_id );
                }

                wp_update_user( array(
                    'ID'           => $user_id,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'display_name' => $first_name . ' ' . $last_name,
                    'user_email'   => $email,
                ) );

                if ( function_exists( 'sywat_get_user_system_id' ) ) {
                    sywat_get_user_system_id( $user_id );
                }

                update_user_meta( $user_id, 'sywat_nfaa_number', $nfaa_number );
                update_user_meta( $user_id, 'sywat_tfaa_number', $nfaa_number );
                update_user_meta( $user_id, 'sywat_is_nfaa_member', $is_nfaa );
                update_user_meta( $user_id, 'sywat_phone', $phone );
                update_user_meta( $user_id, 'sywat_gender', in_array( $gender, array( 'male', 'female', 'unassigned' ), true ) ? $gender : 'unassigned' );
                update_user_meta( $user_id, 'sywat_is_pro', $is_pro );
                update_user_meta( $user_id, 'sywat_dob', $dob );
                update_user_meta( $user_id, 'sywat_accommodations', $accommodations );
                update_user_meta( $user_id, 'sywat_nfaa_expiration', $nfaa_expiration );
                update_user_meta( $user_id, 'sywat_address_1', $address_1 );
                update_user_meta( $user_id, 'sywat_address_2', $address_2 );
                update_user_meta( $user_id, 'sywat_city', $city );
                update_user_meta( $user_id, 'sywat_state', $state );
                update_user_meta( $user_id, 'sywat_zip', $zip );

                if ( ! empty( $shop_id ) ) {
                    update_user_meta( $user_id, 'sywat_shop_id', $shop_id );
                } else {
                    delete_user_meta( $user_id, 'sywat_shop_id' );
                }

                $user_obj = new WP_User( $user_id );
                if ( $user_obj->exists() ) {
                    $officer_roles = array(
                        'tfaa_president', 'tfaa_vice_president', 'tfaa_secretary', 
                        'tfaa_treasurer', 'tfaa_social_media_director', 'tfaa_webmaster', 
                        'tfaa_tournament_director', 'tfaa_field_governor'
                    );

                    foreach ( (array) $user_obj->roles as $old_role ) {
                        if ( ! in_array( $old_role, $submitted_roles, true ) && ! in_array( $old_role, $officer_roles, true ) ) {
                            $user_obj->remove_role( $old_role );
                        }
                    }
                    foreach ( $submitted_roles as $new_role ) {
                        if ( ! in_array( $new_role, (array) $user_obj->roles, true ) && ! in_array( $new_role, $officer_roles, true ) ) {
                            $user_obj->add_role( $new_role );
                        }
                    }

                    if ( function_exists( 'sywat_sync_user_board_roles' ) ) {
                        sywat_sync_user_board_roles( $user_id, $board_positions );
                    }
                }

                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Member profile updated successfully.</div>';
            }
        }

        // 4B. HEAD OF HOUSEHOLD SELF-SERVICE PROFILE UPDATE
        if ( $action === 'update_self_profile' ) {
            $user_id = get_current_user_id();
            if ( $user_id <= 0 ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">You must be logged in to update your profile.</div>';
            }

            $first_name      = sanitize_text_field( $_POST['first_name'] );
            $last_name       = sanitize_text_field( $_POST['last_name'] );
            $email           = sanitize_email( $_POST['email'] );
            $phone           = sanitize_text_field( $_POST['phone'] );
            $gender          = isset( $_POST['gender'] ) ? strtolower( sanitize_text_field( $_POST['gender'] ) ) : 'unassigned';
            $is_pro          = ( isset( $_POST['is_pro'] ) && $_POST['is_pro'] === 'yes' ) ? 'yes' : 'no';
            $dob             = isset( $_POST['dob'] ) ? sanitize_text_field( $_POST['dob'] ) : '';
            $accommodations  = isset( $_POST['accommodations'] ) ? sanitize_text_field( $_POST['accommodations'] ) : 'none';
            $nfaa_number     = isset( $_POST['nfaa_number'] ) ? sanitize_text_field( $_POST['nfaa_number'] ) : '';
            $nfaa_expiration = isset( $_POST['nfaa_expiration'] ) ? sanitize_text_field( $_POST['nfaa_expiration'] ) : '';
            $address_1       = isset( $_POST['address_1'] ) ? sanitize_text_field( $_POST['address_1'] ) : '';
            $address_2       = isset( $_POST['address_2'] ) ? sanitize_text_field( $_POST['address_2'] ) : '';
            $city            = isset( $_POST['city'] ) ? sanitize_text_field( $_POST['city'] ) : '';
            $state           = isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( $_POST['state'] ) ) : '';
            $zip             = isset( $_POST['zip'] ) ? sanitize_text_field( $_POST['zip'] ) : '';

            $new_pw         = isset( $_POST['new_password'] ) ? $_POST['new_password'] : '';
            $confirm_new_pw = isset( $_POST['confirm_new_password'] ) ? $_POST['confirm_new_password'] : '';

            if ( empty( $first_name ) || empty( $last_name ) || ! is_email( $email ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please provide a valid First Name, Last Name, and Email Address.</div>';
            }

            if ( ! empty( $new_pw ) || ! empty( $confirm_new_pw ) ) {
                $pw_val = sywat_validate_password_rules( $new_pw, $confirm_new_pw );
                if ( $pw_val !== true ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $pw_val ) . '</div>';
                }
                wp_set_password( $new_pw, $user_id );
            }

            wp_update_user( array(
                'ID'           => $user_id,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'display_name' => $first_name . ' ' . $last_name,
                'user_email'   => $email,
            ) );

            update_user_meta( $user_id, 'sywat_nfaa_number', $nfaa_number );
            update_user_meta( $user_id, 'sywat_tfaa_number', $nfaa_number );
            update_user_meta( $user_id, 'sywat_phone', $phone );
            update_user_meta( $user_id, 'sywat_gender', in_array( $gender, array( 'male', 'female', 'unassigned' ), true ) ? $gender : 'unassigned' );
            update_user_meta( $user_id, 'sywat_is_pro', $is_pro );
            update_user_meta( $user_id, 'sywat_dob', $dob );
            update_user_meta( $user_id, 'sywat_accommodations', $accommodations );
            update_user_meta( $user_id, 'sywat_nfaa_expiration', $nfaa_expiration );
            update_user_meta( $user_id, 'sywat_address_1', $address_1 );
            update_user_meta( $user_id, 'sywat_address_2', $address_2 );
            update_user_meta( $user_id, 'sywat_city', $city );
            update_user_meta( $user_id, 'sywat_state', $state );
            update_user_meta( $user_id, 'sywat_zip', $zip );

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Your household master profile has been updated successfully!</div>';
        }

        // 5. DELETE MEMBER ACCOUNT OR PERMANENTLY PURGE DEPENDENT SUB-PROFILE
        if ( $action === 'delete_member' ) {
            $user_id         = isset( $_POST['target_user_id'] ) ? intval( $_POST['target_user_id'] ) : 0;
            $primary_user_id = isset( $_POST['primary_user_id'] ) ? intval( $_POST['primary_user_id'] ) : 0;
            $system_id       = isset( $_POST['target_system_id'] ) ? sanitize_text_field( $_POST['target_system_id'] ) : '';

            // Delete Dependent Sub-Profile Permanently
            if ( $primary_user_id > 0 && ! empty( $system_id ) ) {
                $dep = function_exists( 'sywat_get_household_dependent_by_system_id' ) 
                    ? sywat_get_household_dependent_by_system_id( $primary_user_id, $system_id ) 
                    : null;
                $dep_name = ( $dep && isset( $dep['first_name'] ) ) ? $dep['first_name'] . ' ' . $dep['last_name'] : 'Family member';

                if ( function_exists( 'sywat_delete_household_dependent' ) ) {
                    $res = sywat_delete_household_dependent( $primary_user_id, $system_id );
                    if ( is_wp_error( $res ) ) {
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $res->get_error_message() ) . '</div>';
                    }
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🗑️ Sub-profile "<strong>' . esc_html( $dep_name ) . '</strong>" permanently deleted from household.</div>';
                }
            }

            // Delete Standalone Primary Account
            $user = get_userdata( $user_id );
            if ( ! $user ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">User not found.</div>';
            }

            if ( get_current_user_id() === $user_id ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">You cannot delete your own active administrator account.</div>';
            }

            require_once ABSPATH . 'wp-admin/includes/user.php';
            $deleted_name = $user->display_name;

            if ( wp_delete_user( $user_id ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Member account "<strong>' . esc_html( $deleted_name ) . '</strong>" has been permanently deleted.</div>';
            } else {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Failed to delete user account. Please try again.</div>';
            }
        }

        // 6. UNLINK HOUSEHOLD DEPENDENT (PRESERVE IN UNASSIGNED POOL IF NO STANDALONE ACCOUNT)
        if ( $action === 'unlink_dependent' || $action === 'unlink_member' ) {
            $primary_user_id = isset( $_POST['primary_user_id'] ) ? intval( $_POST['primary_user_id'] ) : 0;
            $system_id       = isset( $_POST['target_system_id'] ) ? sanitize_text_field( $_POST['target_system_id'] ) : '';

            if ( $primary_user_id > 0 && ! empty( $system_id ) ) {
                $dep = function_exists( 'sywat_get_household_dependent_by_system_id' ) 
                    ? sywat_get_household_dependent_by_system_id( $primary_user_id, $system_id ) 
                    : null;
                $dep_name = ( $dep && isset( $dep['first_name'] ) ) ? $dep['first_name'] . ' ' . $dep['last_name'] : 'Family member';

                if ( $dep ) {
                    // Store in unassigned pool option to prevent data loss
                    $unassigned_pool = get_option( 'sywat_unassigned_archers', array() );
                    if ( ! is_array( $unassigned_pool ) ) {
                        $unassigned_pool = array();
                    }
                    $dep['unlinked_from_head_id'] = $primary_user_id;
                    $dep['unlinked_date']         = current_time( 'mysql' );
                    $unassigned_pool[ $system_id ] = $dep;
                    update_option( 'sywat_unassigned_archers', $unassigned_pool );
                }

                if ( function_exists( 'sywat_unlink_household_dependent' ) ) {
                    $res = sywat_unlink_household_dependent( $primary_user_id, $system_id );
                    if ( is_wp_error( $res ) ) {
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $res->get_error_message() ) . '</div>';
                    }
                } elseif ( function_exists( 'sywat_delete_household_dependent' ) ) {
                    $res = sywat_delete_household_dependent( $primary_user_id, $system_id );
                    if ( is_wp_error( $res ) ) {
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $res->get_error_message() ) . '</div>';
                    }
                }
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🔗 <strong>' . esc_html( $dep_name ) . '</strong> has been unlinked from this household and moved to the unassigned archer pool.</div>';
            }
        }

        // 7. PROMOTE DEPENDENT TO STANDALONE ACCOUNT (AGE 18)
        if ( $action === 'promote_dependent' ) {
            $primary_user_id = intval( $_POST['primary_user_id'] );
            $system_id       = sanitize_text_field( $_POST['target_system_id'] );
            $new_email       = sanitize_email( $_POST['new_email'] );
            $new_pw          = isset( $_POST['new_password'] ) ? $_POST['new_password'] : '';
            $conf_pw         = isset( $_POST['confirm_new_password'] ) ? $_POST['confirm_new_password'] : '';

            if ( empty( $new_email ) || ! is_email( $new_email ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please provide a valid standalone email address for the member.</div>';
            }

            if ( ! empty( $new_pw ) ) {
                $pw_val = sywat_validate_password_rules( $new_pw, $conf_pw );
                if ( $pw_val !== true ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $pw_val ) . '</div>';
                }
            }

            if ( function_exists( 'sywat_promote_dependent_to_primary' ) ) {
                $promoted_id = sywat_promote_dependent_to_primary( $primary_user_id, $system_id, $new_email, $new_pw );
                if ( is_wp_error( $promoted_id ) ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $promoted_id->get_error_message() ) . '</div>';
                }
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Member promoted to standalone Primary Account Holder successfully!</div>';
            }
        }

        // 8. ASSIGN OR UPDATE BOARD MEMBER POSITIONS (WITH AUTOMATED SINGLE-ACTION ROLE BINDING)
        if ( $action === 'save_board_member' ) {
            $user_id   = intval( $_POST['board_user_id'] );
            $positions = isset( $_POST['board_positions'] ) && is_array( $_POST['board_positions'] ) ? array_map( 'sanitize_text_field', $_POST['board_positions'] ) : array();

            if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a valid member to assign to the Board.</div>';
            }

            if ( function_exists( 'sywat_sync_user_board_roles' ) ) {
                sywat_sync_user_board_roles( $user_id, $positions );
            }
            
            if ( empty( $positions ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Board positions and officer roles removed for member.</div>';
            }

            $user_obj = get_userdata( $user_id );
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Board positions updated and user roles automatically synchronized for "<strong>' . esc_html( $user_obj->display_name ) . '</strong>"!</div>';
        }

        // 9. REMOVE BOARD MEMBER ASSIGNMENT
        if ( $action === 'remove_board_member' ) {
            $user_id = isset( $_POST['target_user_id'] ) ? intval( $_POST['target_user_id'] ) : 0;
            $user    = get_userdata( $user_id );

            if ( $user ) {
                delete_user_meta( $user_id, 'sywat_board_positions' );
                $user_obj = new WP_User( $user_id );
                if ( $user_obj->exists() ) {
                    $officer_roles = array(
                        'tfaa_president', 'tfaa_vice_president', 'tfaa_secretary', 
                        'tfaa_treasurer', 'tfaa_social_media_director', 'tfaa_webmaster', 
                        'tfaa_tournament_director', 'tfaa_field_governor'
                    );
                    foreach ( $officer_roles as $o_role ) {
                        if ( in_array( $o_role, (array) $user_obj->roles, true ) ) {
                            $user_obj->remove_role( $o_role );
                        }
                    }
                }
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">"<strong>' . esc_html( $user->display_name ) . '</strong>" has been removed from the Board of Directors and board roles revoked.</div>';
            }
        }

        // 10. TRANSFER / MERGE STANDALONE PRIMARY ACCOUNT OR UNASSIGNED POOL ARCHER INTO TARGET HOUSEHOLD
        if ( $action === 'transfer_household_member' ) {
            if ( ! current_user_can( 'administrator' ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators can merge or transfer member accounts.</div>';
            }

            $source_user_id   = isset( $_POST['source_user_id'] ) ? intval( $_POST['source_user_id'] ) : 0;
            $target_head_id   = isset( $_POST['target_head_id'] ) ? intval( $_POST['target_head_id'] ) : 0;
            $target_system_id = isset( $_POST['target_system_id'] ) ? sanitize_text_field( $_POST['target_system_id'] ) : '';

            if ( $target_head_id <= 0 ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a valid target Head of Household.</div>';
            }

            $tgt_user = get_userdata( $target_head_id );
            if ( ! $tgt_user ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Specified target Head of Household account does not exist.</div>';
            }

            // OPTION A: Standalone WP_User Account Migration
            if ( $source_user_id > 0 ) {
                if ( $source_user_id === $target_head_id ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Source member cannot be transferred to their own household.</div>';
                }

                $src_user = get_userdata( $source_user_id );
                if ( ! $src_user ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Specified source user account does not exist.</div>';
                }

                $src_fname = get_user_meta( $source_user_id, 'first_name', true ) ? get_user_meta( $source_user_id, 'first_name', true ) : $src_user->first_name;
                $src_lname = get_user_meta( $source_user_id, 'last_name', true ) ? get_user_meta( $source_user_id, 'last_name', true ) : $src_user->last_name;

                $dep_payload = array(
                    'first_name'      => $src_fname,
                    'last_name'       => $src_lname,
                    'email'           => $src_user->user_email,
                    'phone'           => get_user_meta( $source_user_id, 'sywat_phone', true ),
                    'gender'          => get_user_meta( $source_user_id, 'sywat_gender', true ),
                    'is_pro'          => get_user_meta( $source_user_id, 'sywat_is_pro', true ),
                    'dob'             => get_user_meta( $source_user_id, 'sywat_dob', true ),
                    'accommodations'  => get_user_meta( $source_user_id, 'sywat_accommodations', true ),
                    'nfaa_number'     => get_user_meta( $source_user_id, 'sywat_nfaa_number', true ),
                    'nfaa_expiration' => get_user_meta( $source_user_id, 'sywat_nfaa_expiration', true ),
                    'address_1'       => get_user_meta( $source_user_id, 'sywat_address_1', true ),
                    'address_2'       => get_user_meta( $source_user_id, 'sywat_address_2', true ),
                    'city'            => get_user_meta( $source_user_id, 'sywat_city', true ),
                    'state'           => get_user_meta( $source_user_id, 'sywat_state', true ),
                    'zip'             => get_user_meta( $source_user_id, 'sywat_zip', true ),
                );

                if ( function_exists( 'sywat_add_household_dependent' ) ) {
                    $res = sywat_add_household_dependent( $target_head_id, $dep_payload );
                    if ( is_wp_error( $res ) ) {
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $res->get_error_message() ) . '</div>';
                    }

                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    wp_delete_user( $source_user_id );
                    wp_cache_flush();

                    return sprintf(
                        '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🔗 <strong>%s %s</strong> successfully transferred to <strong>%s</strong>\'s household roster! Redundant standalone account merged.</div>',
                        esc_html( $src_fname ),
                        esc_html( $src_lname ),
                        esc_html( $tgt_user->display_name )
                    );
                }
            }

            // OPTION B: Unassigned Pool Archer Migration
            if ( ! empty( $target_system_id ) ) {
                $unassigned_pool = get_option( 'sywat_unassigned_archers', array() );
                if ( is_array( $unassigned_pool ) && isset( $unassigned_pool[ $target_system_id ] ) ) {
                    $dep_payload = $unassigned_pool[ $target_system_id ];
                    $fn          = isset( $dep_payload['first_name'] ) ? $dep_payload['first_name'] : '';
                    $ln          = isset( $dep_payload['last_name'] ) ? $dep_payload['last_name'] : '';

                    if ( function_exists( 'sywat_add_household_dependent' ) ) {
                        $res = sywat_add_household_dependent( $target_head_id, $dep_payload );
                        if ( is_wp_error( $res ) ) {
                            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $res->get_error_message() ) . '</div>';
                        }

                        // Clean up unassigned pool record
                        unset( $unassigned_pool[ $target_system_id ] );
                        update_option( 'sywat_unassigned_archers', $unassigned_pool );

                        return sprintf(
                            '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🔗 Unassigned archer <strong>%s %s</strong> linked to <strong>%s</strong>\'s household roster successfully!</div>',
                            esc_html( $fn ),
                            esc_html( $ln ),
                            esc_html( $tgt_user->display_name )
                        );
                    }
                }
            }
        }

        return '';
    }
}

if ( ! function_exists( 'sywat_ajax_search_head_of_households' ) ) {
    /**
     * AJAX Endpoint: Autocomplete Head of Household Search Engine
     * Queries primary WP_User accounts matching term for dynamic target Head selection.
     */
    function sywat_ajax_search_head_of_households() {
        if ( ! isset( $_GET['sywat_nonce'] ) || ! wp_verify_nonce( $_GET['sywat_nonce'], 'sywat_desk_action' ) ) {
            if ( ob_get_length() ) ob_clean();
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $term = isset( $_GET['term'] ) ? sanitize_text_field( trim( $_GET['term'] ) ) : '';
        if ( strlen( $term ) < 2 ) {
            if ( ob_get_length() ) ob_clean();
            wp_send_json_success( array() );
        }

        $results = array();
        $members = function_exists( 'sywat_get_all_tfaa_members' ) ? sywat_get_all_tfaa_members() : array();

        if ( is_array( $members ) ) {
            foreach ( $members as $m ) {
                if ( ! $m instanceof WP_User ) continue;
                $nfaa_id = get_user_meta( $m->ID, 'sywat_nfaa_number', true );
                $search_blob = strtolower( $m->display_name . ' ' . $m->user_email . ' ' . $m->first_name . ' ' . $m->last_name . ' ' . $nfaa_id );

                if ( stripos( $search_blob, strtolower( $term ) ) !== false ) {
                    $results[] = array(
                        'user_id'      => intval( $m->ID ),
                        'display_name' => $m->display_name,
                        'email'        => $m->user_email,
                        'nfaa_number'  => ! empty( $nfaa_id ) ? $nfaa_id : 'No NFAA ID',
                    );
                }
            }
        }

        if ( ob_get_length() ) ob_clean();
        wp_send_json_success( array_slice( $results, 0, 10 ) );
    }
}

add_action( 'wp_ajax_sywat_search_head_of_households', 'sywat_ajax_search_head_of_households' );

if ( ! function_exists( 'sywat_ajax_search_unassigned_archers' ) ) {
    /**
     * AJAX Endpoint: Autocomplete Archer Search Engine
     * Queries standalone archers and unassigned pool items matching term for dynamic household link autocomplete.
     */
    function sywat_ajax_search_unassigned_archers() {
        if ( ! isset( $_GET['sywat_nonce'] ) || ! wp_verify_nonce( $_GET['sywat_nonce'], 'sywat_desk_action' ) ) {
            if ( ob_get_length() ) ob_clean();
            wp_send_json_error( array( 'message' => 'Security check failed.' ) );
        }

        $term = isset( $_GET['term'] ) ? sanitize_text_field( trim( $_GET['term'] ) ) : '';
        if ( strlen( $term ) < 2 ) {
            if ( ob_get_length() ) ob_clean();
            wp_send_json_success( array() );
        }

        $results = array();
        
        // Search unassigned pool
        $pool = get_option( 'sywat_unassigned_archers', array() );
        if ( is_array( $pool ) ) {
            foreach ( $pool as $sys_id => $item ) {
                $fn = isset( $item['first_name'] ) ? $item['first_name'] : '';
                $ln = isset( $item['last_name'] ) ? $item['last_name'] : '';
                $name = trim( $fn . ' ' . $ln );
                if ( stripos( $name, $term ) !== false || stripos( isset( $item['nfaa_number'] ) ? $item['nfaa_number'] : '', $term ) !== false ) {
                    $results[] = array(
                        'system_id'       => $sys_id,
                        'first_name'      => $fn,
                        'last_name'       => $ln,
                        'dob'             => isset( $item['dob'] ) ? $item['dob'] : '',
                        'gender'          => isset( $item['gender'] ) ? $item['gender'] : 'unassigned',
                        'nfaa_number'     => isset( $item['nfaa_number'] ) ? $item['nfaa_number'] : '',
                        'nfaa_expiration' => isset( $item['nfaa_expiration'] ) ? $item['nfaa_expiration'] : '',
                        'source'          => 'unassigned_pool',
                    );
                }
            }
        }

        if ( ob_get_length() ) ob_clean();
        wp_send_json_success( array_slice( $results, 0, 10 ) );
    }
}

add_action( 'wp_ajax_sywat_search_unassigned_archers', 'sywat_ajax_search_unassigned_archers' );

if ( ! function_exists( 'sywat_ajax_purge_test_members_chunk' ) ) {
    /**
     * AJAX Endpoint: Chunked Protected Test Data Purge Processor
     * Deletes test accounts in batches of 100 while returning percentage updates to the client progress bar.
     * Enforces output buffer clearing to prevent stray HTML/script output from polluting the JSON stream.
     */
    function sywat_ajax_purge_test_members_chunk() {
        if ( ! isset( $_POST['sywat_nonce'] ) || ! wp_verify_nonce( $_POST['sywat_nonce'], 'sywat_desk_action' ) ) {
            if ( ob_get_length() ) {
                ob_clean();
            }
            wp_send_json_error( array( 'message' => 'Security token expired. Please refresh the page and try again.' ) );
        }

        if ( ! current_user_can( 'administrator' ) ) {
            if ( ob_get_length() ) {
                ob_clean();
            }
            wp_send_json_error( array( 'message' => 'Access denied. Only administrators can execute batch data purges.' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        global $wpdb;

        $user_id     = get_current_user_id();
        $queue_key   = 'sywat_purge_queue_' . $user_id;
        $meta_key    = 'sywat_purge_meta_' . $user_id;
        $chunk       = isset( $_POST['chunk'] ) ? intval( $_POST['chunk'] ) : 1;
        $reset_queue = isset( $_POST['reset'] ) && $_POST['reset'] === 'yes';

        if ( $chunk === 1 || $reset_queue || get_transient( $queue_key ) === false ) {
            $protected_map = function_exists( 'sywat_get_protected_user_map' ) ? sywat_get_protected_user_map() : array();
            $all_user_ids  = $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" );
            $purge_queue   = array();
            $protected_cnt = 0;

            if ( ! empty( $all_user_ids ) ) {
                foreach ( $all_user_ids as $uid_raw ) {
                    $uid = intval( $uid_raw );
                    if ( isset( $protected_map[ $uid ] ) ) {
                        $protected_cnt++;
                    } else {
                        $purge_queue[] = $uid;
                    }
                }
            }

            set_transient( $queue_key, $purge_queue, HOUR_IN_SECONDS );
            set_transient( $meta_key, array(
                'total_purgeable' => count( $purge_queue ),
                'protected_count' => $protected_cnt,
                'total_deleted'   => 0,
            ), HOUR_IN_SECONDS );
        }

        $purge_queue = get_transient( $queue_key );
        $meta        = get_transient( $meta_key );

        if ( ! is_array( $purge_queue ) || ! is_array( $meta ) ) {
            if ( ob_get_length() ) {
                ob_clean();
            }
            wp_send_json_error( array( 'message' => 'Purge state lost or expired. Please reload and try again.' ) );
        }

        $total_purgeable = intval( $meta['total_purgeable'] );
        $protected_count = intval( $meta['protected_count'] );
        $total_deleted   = intval( $meta['total_deleted'] );

        if ( empty( $purge_queue ) ) {
            delete_transient( $queue_key );
            delete_transient( $meta_key );
            wp_cache_flush();

            if ( ob_get_length() ) {
                ob_clean();
            }
            wp_send_json_success( array(
                'finished'        => true,
                'deleted_chunk'   => 0,
                'total_deleted'   => $total_deleted,
                'total_records'   => $total_purgeable,
                'protected_count' => $protected_count,
                'remaining'       => 0,
                'percent'         => 100,
                'message'         => sprintf( '🗑️ Batch Purge Complete! Deleted %d test member accounts. Protected %d accounts.', $total_deleted, $protected_count ),
            ) );
        }

        $limit     = 100;
        $batch_ids = array_splice( $purge_queue, 0, $limit );

        wp_suspend_cache_invalidation( true );
        remove_all_actions( 'delete_user' );
        remove_all_actions( 'deleted_user' );

        $wpdb->query( 'START TRANSACTION' );
        $chunk_deleted = 0;

        foreach ( $batch_ids as $uid ) {
            if ( wp_delete_user( $uid ) ) {
                $chunk_deleted++;
            }
        }

        $wpdb->query( 'COMMIT' );
        wp_suspend_cache_invalidation( false );

        $new_total_deleted = $total_deleted + $chunk_deleted;
        $remaining         = count( $purge_queue );
        $finished          = ( $remaining === 0 );

        set_transient( $queue_key, $purge_queue, HOUR_IN_SECONDS );
        $meta['total_deleted'] = $new_total_deleted;
        set_transient( $meta_key, $meta, HOUR_IN_SECONDS );

        if ( $finished ) {
            delete_transient( $queue_key );
            delete_transient( $meta_key );
            wp_cache_flush();
        }

        $percent = $total_purgeable > 0 ? min( 100, round( ( $new_total_deleted / $total_purgeable ) * 100 ) ) : 100;

        if ( ob_get_length() ) {
            ob_clean();
        }
        wp_send_json_success( array(
            'finished'        => $finished,
            'deleted_chunk'   => $chunk_deleted,
            'total_deleted'   => $new_total_deleted,
            'total_records'   => $total_purgeable,
            'protected_count' => $protected_count,
            'remaining'       => $remaining,
            'percent'         => $percent,
            'message'         => $finished 
                ? sprintf( '🗑️ Batch Purge Complete! Deleted %d test member accounts. Protected %d accounts.', $new_total_deleted, $protected_count )
                : sprintf( 'Purged %d of %d accounts (%d%%)', $new_total_deleted, $total_purgeable, $percent ),
        ) );
    }
}

add_action( 'wp_ajax_sywat_purge_test_members_chunk', 'sywat_ajax_purge_test_members_chunk' );
