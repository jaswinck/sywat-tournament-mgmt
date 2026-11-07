<?php
/**
 * Sub-Module: Member & Board POST Action Handling
 * Path: includes/members/members-actions.php
 * Description: Nonce-verified POST action processor for member account creation, household sub-profile management, profile updates, DOB/NFAA meta saving, address management, and board position assignment.
 * Compliance: Zero visual HTML layout templates. Returns standardized alert notices.
 * Author: SYWAT Archery
 * Version: 3.3.0
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

        // 1. CREATE NEW MEMBER ACCOUNT OR HOUSEHOLD DEPENDENT
        if ( $action === 'create_member' ) {
            $primary_user_id = isset( $_POST['primary_user_id'] ) ? intval( $_POST['primary_user_id'] ) : 0;
            $first_name      = ucfirst( strtolower( sanitize_text_field( $_POST['first_name'] ) ) );
            $last_name       = ucfirst( strtolower( sanitize_text_field( $_POST['last_name'] ) ) );
            $email           = sanitize_email( $_POST['email'] );
            $dob             = isset( $_POST['dob'] ) ? sanitize_text_field( $_POST['dob'] ) : '';
            $nfaa_number     = isset( $_POST['nfaa_number'] ) ? sanitize_text_field( $_POST['nfaa_number'] ) : '';
            $nfaa_expiration = isset( $_POST['nfaa_expiration'] ) ? sanitize_text_field( $_POST['nfaa_expiration'] ) : '';

            // HOUSEHOLD DEPENDENT SUB-PROFILE CREATION (Bypasses WP_User creation completely)
            if ( $primary_user_id > 0 ) {
                if ( empty( $first_name ) || empty( $last_name ) || empty( $dob ) ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please provide a valid First Name, Last Name, and Date of Birth for the family member.</div>';
                }

                $dep_payload = array(
                    'first_name'      => $first_name,
                    'last_name'       => $last_name,
                    'email'           => $email,
                    'phone'           => isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '',
                    'dob'             => $dob,
                    'nfaa_number'     => $nfaa_number,
                    'nfaa_expiration' => $nfaa_expiration,
                    'address_1'       => isset( $_POST['address_1'] ) ? sanitize_text_field( $_POST['address_1'] ) : '',
                    'address_2'       => isset( $_POST['address_2'] ) ? sanitize_text_field( $_POST['address_2'] ) : '',
                    'city'            => isset( $_POST['city'] ) ? sanitize_text_field( $_POST['city'] ) : '',
                    'state'           => isset( $_POST['state'] ) ? sanitize_text_field( $_POST['state'] ) : '',
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
            $shop_id          = isset( $_POST['sywat_shop_id'] ) ? sanitize_text_field( $_POST['sywat_shop_id'] ) : '';

            $address_1 = isset( $_POST['address_1'] ) ? sanitize_text_field( $_POST['address_1'] ) : '';
            $address_2 = isset( $_POST['address_2'] ) ? sanitize_text_field( $_POST['address_2'] ) : '';
            $city      = isset( $_POST['city'] ) ? sanitize_text_field( $_POST['city'] ) : '';
            $state     = isset( $_POST['state'] ) ? sanitize_text_field( $_POST['state'] ) : '';
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

            // Generate permanent hidden internal System ID via helper
            $system_id = function_exists( 'sywat_get_user_system_id' ) ? sywat_get_user_system_id( $user_id ) : '';

            update_user_meta( $user_id, 'sywat_nfaa_number', $nfaa_number );
            update_user_meta( $user_id, 'sywat_tfaa_number', $nfaa_number );
            update_user_meta( $user_id, 'sywat_is_nfaa_member', $is_nfaa );
            update_user_meta( $user_id, 'sywat_phone', $phone );
            update_user_meta( $user_id, 'sywat_dob', $dob );
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

        // 2. UPDATE EXISTING MEMBER PROFILE (OR DEPENDENT SUB-PROFILE)
        if ( $action === 'update_member' ) {
            $user_id         = intval( $_POST['target_user_id'] );
            $primary_user_id = isset( $_POST['primary_user_id'] ) ? intval( $_POST['primary_user_id'] ) : 0;
            $system_id       = isset( $_POST['target_system_id'] ) ? sanitize_text_field( $_POST['target_system_id'] ) : '';

            // Update Dependent Sub-Profile
            if ( $primary_user_id > 0 && ! empty( $system_id ) ) {
                $dep_update = array(
                    'first_name'      => sanitize_text_field( $_POST['first_name'] ),
                    'last_name'       => sanitize_text_field( $_POST['last_name'] ),
                    'email'           => sanitize_email( $_POST['email'] ),
                    'phone'           => sanitize_text_field( $_POST['phone'] ),
                    'dob'             => sanitize_text_field( $_POST['dob'] ),
                    'nfaa_number'     => sanitize_text_field( $_POST['nfaa_number'] ),
                    'nfaa_expiration' => sanitize_text_field( $_POST['nfaa_expiration'] ),
                    'address_1'       => sanitize_text_field( $_POST['address_1'] ),
                    'address_2'       => sanitize_text_field( $_POST['address_2'] ),
                    'city'            => sanitize_text_field( $_POST['city'] ),
                    'state'           => sanitize_text_field( $_POST['state'] ),
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
            $shop_id         = isset( $_POST['sywat_shop_id'] ) ? sanitize_text_field( $_POST['sywat_shop_id'] ) : '';
            $new_pw          = isset( $_POST['new_password'] ) ? $_POST['new_password'] : '';
            $confirm_new_pw  = isset( $_POST['confirm_new_password'] ) ? $_POST['confirm_new_password'] : '';

            $dob             = isset( $_POST['dob'] ) ? sanitize_text_field( $_POST['dob'] ) : '';
            $nfaa_number     = isset( $_POST['nfaa_number'] ) ? sanitize_text_field( $_POST['nfaa_number'] ) : '';
            $nfaa_expiration = isset( $_POST['nfaa_expiration'] ) ? sanitize_text_field( $_POST['nfaa_expiration'] ) : '';
            $address_1       = isset( $_POST['address_1'] ) ? sanitize_text_field( $_POST['address_1'] ) : '';
            $address_2       = isset( $_POST['address_2'] ) ? sanitize_text_field( $_POST['address_2'] ) : '';
            $city            = isset( $_POST['city'] ) ? sanitize_text_field( $_POST['city'] ) : '';
            $state           = isset( $_POST['state'] ) ? sanitize_text_field( $_POST['state'] ) : '';
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
                update_user_meta( $user_id, 'sywat_dob', $dob );
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
                if ( $user_obj->exists() && ! empty( $submitted_roles ) ) {
                    foreach ( (array) $user_obj->roles as $old_role ) {
                        if ( ! in_array( $old_role, $submitted_roles, true ) ) {
                            $user_obj->remove_role( $old_role );
                        }
                    }
                    foreach ( $submitted_roles as $new_role ) {
                        if ( ! in_array( $new_role, (array) $user_obj->roles, true ) ) {
                            $user_obj->add_role( $new_role );
                        }
                    }
                }

                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Member profile updated successfully.</div>';
            }
        }

        // 3. DELETE MEMBER ACCOUNT OR HOUSEHOLD DEPENDENT
        if ( $action === 'delete_member' ) {
            $user_id         = intval( $_POST['target_user_id'] );
            $primary_user_id = isset( $_POST['primary_user_id'] ) ? intval( $_POST['primary_user_id'] ) : 0;
            $system_id       = isset( $_POST['target_system_id'] ) ? sanitize_text_field( $_POST['target_system_id'] ) : '';

            // Delete Dependent Sub-Profile
            if ( $primary_user_id > 0 && ! empty( $system_id ) ) {
                if ( function_exists( 'sywat_delete_household_dependent' ) ) {
                    $res = sywat_delete_household_dependent( $primary_user_id, $system_id );
                    if ( is_wp_error( $res ) ) {
                        return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">' . esc_html( $res->get_error_message() ) . '</div>';
                    }
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Family member sub-profile removed from household.</div>';
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

        // 4. PROMOTE DEPENDENT TO STANDALONE ACCOUNT (AGE 18)
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

        // 5. ASSIGN OR UPDATE BOARD MEMBER POSITIONS
        if ( $action === 'save_board_member' ) {
            $user_id   = intval( $_POST['board_user_id'] );
            $positions = isset( $_POST['board_positions'] ) && is_array( $_POST['board_positions'] ) ? array_map( 'sanitize_text_field', $_POST['board_positions'] ) : array();

            if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a valid member to assign to the Board.</div>';
            }

            if ( empty( $positions ) ) {
                delete_user_meta( $user_id, 'sywat_board_positions' );
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Board positions removed for member.</div>';
            }

            update_user_meta( $user_id, 'sywat_board_positions', $positions );
            $user = get_userdata( $user_id );

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Board positions updated for "<strong>' . esc_html( $user->display_name ) . '</strong>".</div>';
        }

        // 6. REMOVE BOARD MEMBER ASSIGNMENT
        if ( $action === 'remove_board_member' ) {
            $user_id = intval( $_POST['target_user_id'] );
            $user    = get_userdata( $user_id );

            if ( $user ) {
                delete_user_meta( $user_id, 'sywat_board_positions' );
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">"<strong>' . esc_html( $user->display_name ) . '</strong>" has been removed from the Board of Directors.</div>';
            }
        }

        return '';
    }
}
