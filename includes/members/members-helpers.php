<?php
/**
 * Sub-Module: Member & Board Helper Functions
 * Path: includes/members/members-helpers.php
 * Description: Pure formatting utilities, internal System ID generation, NFAA ID resolution, DOB age division calculators, NFAA expiration status matchers (with Shop / Club dual-role detection and role-based validation guardrails), gender options dictionary, US state resolution, accommodations maps, and household sub-profile management utilities.
 * Compliance: Zero HTML output. Returns standardized data arrays and clean scalar values. Data retrieval queries isolated to members-data.php.
 * Author: SYWAT Archery
 * Version: 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Retrieve standardized Gender options map.
 *
 * @return array Associative array of gender keys to formatted titles.
 */
if ( ! function_exists( 'sywat_get_gender_options' ) ) {
    function sywat_get_gender_options() {
        return array(
            'unassigned' => '-- Select Gender --',
            'male'       => 'Male',
            'female'     => 'Female',
        );
    }
}

/**
 * Retrieve standardized 50 US States array formatted as 'ST' => 'ST - State Name'.
 *
 * @return array Associative array of 2-letter state codes to formatted labels.
 */
if ( ! function_exists( 'sywat_get_us_states_list' ) ) {
    function sywat_get_us_states_list() {
        return array(
            'AL' => 'AL - Alabama',
            'AK' => 'AK - Alaska',
            'AZ' => 'AZ - Arizona',
            'AR' => 'AR - Arkansas',
            'CA' => 'CA - California',
            'CO' => 'CO - Colorado',
            'CT' => 'CT - Connecticut',
            'DE' => 'DE - Delaware',
            'FL' => 'FL - Florida',
            'GA' => 'GA - Georgia',
            'HI' => 'HI - Hawaii',
            'ID' => 'ID - Idaho',
            'IL' => 'IL - Illinois',
            'IN' => 'IN - Indiana',
            'IA' => 'IA - Iowa',
            'KS' => 'KS - Kansas',
            'KY' => 'KY - Kentucky',
            'LA' => 'LA - Louisiana',
            'ME' => 'ME - Maine',
            'MD' => 'MD - Maryland',
            'MA' => 'MA - Massachusetts',
            'MI' => 'MI - Michigan',
            'MN' => 'MN - Minnesota',
            'MS' => 'MS - Mississippi',
            'MO' => 'MO - Missouri',
            'MT' => 'MT - Montana',
            'NE' => 'NE - Nebraska',
            'NV' => 'NV - Nevada',
            'NH' => 'NH - New Hampshire',
            'NJ' => 'NJ - New Jersey',
            'NM' => 'NM - New Mexico',
            'NY' => 'NY - New York',
            'NC' => 'NC - North Carolina',
            'ND' => 'ND - North Dakota',
            'OH' => 'OH - Ohio',
            'OK' => 'OK - Oklahoma',
            'OR' => 'OR - Oregon',
            'PA' => 'PA - Pennsylvania',
            'RI' => 'RI - Rhode Island',
            'SC' => 'SC - South Carolina',
            'SD' => 'SD - South Dakota',
            'TN' => 'TN - Tennessee',
            'TX' => 'TX - Texas',
            'UT' => 'UT - Utah',
            'VT' => 'VT - Vermont',
            'VA' => 'VA - Virginia',
            'WA' => 'WA - Washington',
            'WV' => 'WV - West Virginia',
            'WI' => 'WI - Wisconsin',
            'WY' => 'WY - Wyoming',
        );
    }
}

/**
 * Retrieve standardized Archer Accommodation options map.
 *
 * @return array Associative array of accommodation keys to human-readable titles.
 */
if ( ! function_exists( 'sywat_get_accommodations_list' ) ) {
    function sywat_get_accommodations_list() {
        return array(
            'none'           => 'None',
            'wheelchair'     => 'Wheelchair',
            'chair_needed'   => 'Chair Needed',
            'sight_impaired' => 'Sight Impaired',
            'other'          => 'Other',
        );
    }
}

/**
 * Generate a unique, permanent internal System ID for an archer.
 *
 * @return string Unique internal System ID.
 */
if ( ! function_exists( 'sywat_generate_unique_system_id' ) ) {
    function sywat_generate_unique_system_id() {
        $random_id = (string) mt_rand( 100000, 999999 );
        while ( ! empty( get_users( array( 'meta_key' => 'sywat_system_id', 'meta_value' => $random_id, 'number' => 1 ) ) ) ) {
            $random_id = (string) mt_rand( 100000, 999999 );
        }
        return $random_id;
    }
}

/**
 * Retrieve or generate an archer's permanent internal System ID.
 *
 * @param int $user_id WordPress User ID.
 * @return string Internal System ID.
 */
if ( ! function_exists( 'sywat_get_user_system_id' ) ) {
    function sywat_get_user_system_id( $user_id ) {
        $user_id   = intval( $user_id );
        $system_id = get_user_meta( $user_id, 'sywat_system_id', true );
        
        if ( empty( $system_id ) && $user_id > 0 ) {
            $legacy_id = get_user_meta( $user_id, 'sywat_tfaa_number', true );
            $system_id = ! empty( $legacy_id ) ? $legacy_id : sywat_generate_unique_system_id();
            update_user_meta( $user_id, 'sywat_system_id', $system_id );
        }
        
        return $system_id;
    }
}

/**
 * Retrieve an archer's official NFAA ID number for display on rosters and tables.
 *
 * @param int $user_id WordPress User ID.
 * @return string NFAA ID number or 'Unassigned' if not set.
 */
if ( ! function_exists( 'sywat_get_user_nfaa_id' ) ) {
    function sywat_get_user_nfaa_id( $user_id ) {
        $user_id = intval( $user_id );
        $nfaa_id = get_user_meta( $user_id, 'sywat_nfaa_number', true );
        
        if ( empty( $nfaa_id ) ) {
            $nfaa_id = get_user_meta( $user_id, 'sywat_tfaa_number', true );
        }
        
        return ! empty( $nfaa_id ) ? $nfaa_id : 'Unassigned';
    }
}

/**
 * Format user board positions into readable labels.
 *
 * @param int $user_id WordPress User ID.
 * @return array Associative array of position keys to human-readable titles.
 */
if ( ! function_exists( 'sywat_get_user_board_position_labels' ) ) {
    function sywat_get_user_board_position_labels( $user_id ) {
        $positions = get_user_meta( $user_id, 'sywat_board_positions', true );
        if ( ! is_array( $positions ) || empty( $positions ) ) {
            return array();
        }

        $all_positions = function_exists( 'sywat_get_tfaa_board_positions_list' ) ? sywat_get_tfaa_board_positions_list() : array();
        $labels = array();

        foreach ( $positions as $pos_key ) {
            if ( isset( $all_positions[$pos_key] ) ) {
                $labels[$pos_key] = $all_positions[$pos_key];
            }
        }

        return $labels;
    }
}

/**
 * Calculate NFAA Age Division based on exact Date of Birth and Tournament Date.
 *
 * @param string      $dob_string      Date of birth in YYYY-MM-DD format.
 * @param string|null $tournament_date Optional comparison date (defaults to today).
 * @return string Eligible NFAA Age Division name.
 */
if ( ! function_exists( 'sywat_get_eligible_age_division' ) ) {
    function sywat_get_eligible_age_division( $dob_string, $tournament_date = null ) {
        if ( empty( $dob_string ) ) {
            return 'Adult';
        }

        try {
            $dob  = new DateTime( $dob_string );
            $comp = $tournament_date ? new DateTime( $tournament_date ) : new DateTime();
            $age  = $dob->diff( $comp )->y;

            if ( $age < 12 )  return 'Cub';          // 0 - 11
            if ( $age < 15 )  return 'Youth';        // 12 - 14
            if ( $age < 18 )  return 'Young Adult';  // 15 - 17
            if ( $age < 50 )  return 'Adult';        // 18 - 49
            if ( $age < 60 )  return 'Senior';       // 50 - 59
            if ( $age < 70 )  return 'Silver Senior';// 60 - 69
            return 'Master Senior';                  // 70+
        } catch ( Exception $e ) {
            return 'Adult';
        }
    }
}

/**
 * Evaluate NFAA Membership Expiration Status for a given user or expiration date string.
 * Supports dual-role detection for Shop / Club staff who also maintain an NFAA ID.
 * Returns neutral non-member status for management accounts lacking membership roles.
 *
 * @param int|string $user_id_or_date WordPress User ID or YYYY-MM-DD date string.
 * @return array Status payload with 'status', 'expiration', 'raw_date', 'badge_class', 'is_active', 'system_account_label', and 'has_membership_role'.
 */
if ( ! function_exists( 'sywat_get_member_nfaa_status' ) ) {
    function sywat_get_member_nfaa_status( $user_id_or_date ) {
        $expiration           = '';
        $is_active            = false;
        $system_account_label = '';
        $has_membership_role  = true; // Default true for direct date strings or unlinked contexts

        if ( is_numeric( $user_id_or_date ) && intval( $user_id_or_date ) > 0 ) {
            $user_id    = intval( $user_id_or_date );
            $user_obj   = get_userdata( $user_id );
            $user_roles = $user_obj ? (array) $user_obj->roles : array();
            $expiration = get_user_meta( $user_id, 'sywat_nfaa_expiration', true );
            $nfaa_id    = get_user_meta( $user_id, 'sywat_nfaa_number', true );

            // Check if user holds a qualifying active shooting membership or officer role
            $qualifying_roles = function_exists( 'sywat_get_custom_roles_slugs' ) 
                ? sywat_get_custom_roles_slugs() 
                : array(
                    'tfaa_member', 'tfaa_president', 'tfaa_vice_president', 'tfaa_treasurer', 
                    'tfaa_secretary', 'tfaa_tournament_director', 'tfaa_social_media_director', 
                    'tfaa_webmaster', 'tfaa_field_governor'
                );

            $has_membership_role = false;
            foreach ( $user_roles as $r ) {
                $r_clean = strtolower( $r );
                if ( in_array( $r_clean, $qualifying_roles, true ) ) {
                    $has_membership_role = true;
                }
                if ( strpos( $r_clean, 'shop' ) !== false || strpos( $r_clean, 'club' ) !== false ) {
                    $system_account_label = 'Shop / Club';
                }
            }

            // If account has NO active membership role (e.g., pure Shop Manager like Korbins or pure Administrator)
            if ( ! $has_membership_role ) {
                if ( ! empty( $system_account_label ) ) {
                    return array(
                        'status'               => $system_account_label,
                        'expiration'           => 'System Account',
                        'raw_date'             => $expiration,
                        'badge_class'          => 'sywat-badge-checkedin',
                        'is_active'            => false, // Guarantees member status badges are suppressed
                        'system_account_label' => $system_account_label,
                        'has_membership_role'  => false,
                    );
                }

                return array(
                    'status'               => 'Non-Member',
                    'expiration'           => '—',
                    'raw_date'             => '',
                    'badge_class'          => 'sywat-badge-muted',
                    'is_active'            => false,
                    'system_account_label' => '',
                    'has_membership_role'  => false,
                );
            }

            // Dual-Role or Member Account without an explicit NFAA ID or expiration date set
            if ( empty( $expiration ) && empty( $nfaa_id ) ) {
                $legacy_flag = get_user_meta( $user_id, 'sywat_is_nfaa_member', true );
                $is_active   = ( $legacy_flag === 'yes' );
                return array(
                    'status'               => $is_active ? 'Active' : 'Expired',
                    'expiration'           => '—',
                    'raw_date'             => '',
                    'badge_class'          => $is_active ? 'sywat-badge-open' : 'sywat-badge-muted',
                    'is_active'            => $is_active,
                    'system_account_label' => $system_account_label,
                    'has_membership_role'  => $has_membership_role,
                );
            }
        } elseif ( is_string( $user_id_or_date ) ) {
            $expiration = sanitize_text_field( $user_id_or_date );
        }

        if ( empty( $expiration ) ) {
            return array(
                'status'               => 'Expired',
                'expiration'           => '—',
                'raw_date'             => '',
                'badge_class'          => 'sywat-badge-muted',
                'is_active'            => false,
                'system_account_label' => $system_account_label,
                'has_membership_role'  => $has_membership_role,
            );
        }

        $exp_timestamp   = strtotime( $expiration );
        $today_timestamp = strtotime( date( 'Y-m-d' ) );
        $is_active       = ( $exp_timestamp >= $today_timestamp );

        return array(
            'status'               => $is_active ? 'Active' : 'Expired',
            'expiration'           => ( $exp_timestamp > 0 ) ? date( 'M j, Y', $exp_timestamp ) : '—',
            'raw_date'             => $expiration,
            'badge_class'          => $is_active ? 'sywat-badge-open' : 'sywat-badge-muted',
            'is_active'            => $is_active,
            'system_account_label' => $system_account_label,
            'has_membership_role'  => $has_membership_role,
        );
    }
}

/**
 * Retrieve all linked household dependent sub-profiles under a Primary Account Holder.
 *
 * @param int $primary_user_id WordPress User ID of Primary Account Holder.
 * @return array Sorted array of associative arrays representing dependent sub-profiles.
 */
if ( ! function_exists( 'sywat_get_linked_household_members' ) ) {
    function sywat_get_linked_household_members( $primary_user_id ) {
        $primary_user_id = intval( $primary_user_id );
        if ( $primary_user_id <= 0 ) {
            return array();
        }

        $dependents = get_user_meta( $primary_user_id, 'sywat_household_dependents', true );
        if ( ! is_array( $dependents ) ) {
            $dependents = array();
        }

        // Backwards Compatibility: Include legacy WP_User linked accounts if any exist
        $legacy_users = get_users( array(
            'meta_key'   => 'sywat_primary_user_id',
            'meta_value' => $primary_user_id,
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ) );

        if ( ! empty( $legacy_users ) ) {
            $existing_system_ids = array_column( $dependents, 'system_id' );
            foreach ( $legacy_users as $lu ) {
                $lu_system_id = sywat_get_user_system_id( $lu->ID );
                if ( ! in_array( $lu_system_id, $existing_system_ids, true ) ) {
                    $legacy_gender = get_user_meta( $lu->ID, 'sywat_gender', true );
                    $legacy_pro    = get_user_meta( $lu->ID, 'sywat_is_pro', true );

                    $dependents[] = array(
                        'system_id'       => $lu_system_id,
                        'wp_user_id'      => $lu->ID,
                        'first_name'      => $lu->first_name,
                        'last_name'       => $lu->last_name,
                        'display_name'    => $lu->display_name,
                        'email'           => $lu->user_email,
                        'phone'           => get_user_meta( $lu->ID, 'sywat_phone', true ),
                        'gender'          => ! empty( $legacy_gender ) ? strtolower( $legacy_gender ) : 'unassigned',
                        'is_pro'          => ( $legacy_pro === 'yes' ) ? 'yes' : 'no',
                        'dob'             => get_user_meta( $lu->ID, 'sywat_dob', true ),
                        'accommodations'  => get_user_meta( $lu->ID, 'sywat_accommodations', true ),
                        'nfaa_number'     => get_user_meta( $lu->ID, 'sywat_nfaa_number', true ),
                        'nfaa_expiration' => get_user_meta( $lu->ID, 'sywat_nfaa_expiration', true ),
                        'address_1'       => get_user_meta( $lu->ID, 'sywat_address_1', true ),
                        'address_2'       => get_user_meta( $lu->ID, 'sywat_address_2', true ),
                        'city'            => get_user_meta( $lu->ID, 'sywat_city', true ),
                        'state'           => get_user_meta( $lu->ID, 'sywat_state', true ),
                        'zip'             => get_user_meta( $lu->ID, 'sywat_zip', true ),
                        'is_legacy'       => true,
                    );
                }
            }
        }

        // Standardize fallback defaults for dependent records
        foreach ( $dependents as $idx => $d ) {
            if ( ! isset( $d['gender'] ) || empty( $d['gender'] ) ) {
                $dependents[$idx]['gender'] = 'unassigned';
            }
            if ( ! isset( $d['is_pro'] ) || empty( $d['is_pro'] ) ) {
                $dependents[$idx]['is_pro'] = 'no';
            }
        }

        // Sort Dependents chronologically by Date of Birth (oldest archers first)
        usort( $dependents, function( $a, $b ) {
            $dob_a = isset( $a['dob'] ) && ! empty( $a['dob'] ) ? strtotime( $a['dob'] ) : 0;
            $dob_b = isset( $b['dob'] ) && ! empty( $b['dob'] ) ? strtotime( $b['dob'] ) : 0;
            if ( $dob_a === $dob_b ) {
                return 0;
            }
            return ( $dob_a < $dob_b ) ? -1 : 1;
        } );

        return $dependents;
    }
}

/**
 * Retrieve a specific household dependent sub-profile by Primary User ID and System ID.
 *
 * @param int    $primary_user_id WordPress User ID of Primary Account Holder.
 * @param string $system_id       Unique internal System ID of the dependent.
 * @return array|null Associative array of sub-profile data or null if not found.
 */
if ( ! function_exists( 'sywat_get_household_dependent_by_system_id' ) ) {
    function sywat_get_household_dependent_by_system_id( $primary_user_id, $system_id ) {
        $dependents = sywat_get_linked_household_members( $primary_user_id );
        foreach ( $dependents as $dep ) {
            if ( isset( $dep['system_id'] ) && (string) $dep['system_id'] === (string) $system_id ) {
                return $dep;
            }
        }
        return null;
    }
}

/**
 * Add a new household dependent sub-profile under a Primary Account.
 *
 * @param int   $primary_user_id WordPress User ID of Primary Account Holder.
 * @param array $dep_data        Sanitized input details.
 * @return string|WP_Error System ID on success, or WP_Error on failure.
 */
if ( ! function_exists( 'sywat_add_household_dependent' ) ) {
    function sywat_add_household_dependent( $primary_user_id, $dep_data ) {
        $primary_user_id = intval( $primary_user_id );
        $primary_user    = get_userdata( $primary_user_id );

        if ( ! $primary_user ) {
            return new WP_Error( 'invalid_primary', 'Primary Account Holder not found.' );
        }

        $first_name = ucfirst( strtolower( sanitize_text_field( isset( $dep_data['first_name'] ) ? $dep_data['first_name'] : '' ) ) );
        $last_name  = ucfirst( strtolower( sanitize_text_field( isset( $dep_data['last_name'] ) ? $dep_data['last_name'] : $primary_user->last_name ) ) );
        $dob        = sanitize_text_field( isset( $dep_data['dob'] ) ? $dep_data['dob'] : '' );

        if ( empty( $first_name ) || empty( $last_name ) || empty( $dob ) ) {
            return new WP_Error( 'missing_fields', 'First Name, Last Name, and Date of Birth are required.' );
        }

        $gender = sanitize_text_field( isset( $dep_data['gender'] ) ? strtolower( $dep_data['gender'] ) : 'unassigned' );
        $is_pro = isset( $dep_data['is_pro'] ) && $dep_data['is_pro'] === 'yes' ? 'yes' : 'no';

        $system_id  = sywat_generate_unique_system_id();
        $dependents = get_user_meta( $primary_user_id, 'sywat_household_dependents', true );
        if ( ! is_array( $dependents ) ) {
            $dependents = array();
        }

        $p_address = sywat_get_user_address( $primary_user_id );

        $new_dependent = array(
            'system_id'       => $system_id,
            'wp_user_id'      => 0,
            'first_name'      => $first_name,
            'last_name'       => $last_name,
            'display_name'    => $first_name . ' ' . $last_name,
            'email'           => sanitize_email( isset( $dep_data['email'] ) ? $dep_data['email'] : $primary_user->user_email ),
            'phone'           => sanitize_text_field( isset( $dep_data['phone'] ) ? $dep_data['phone'] : get_user_meta( $primary_user_id, 'sywat_phone', true ) ),
            'gender'          => in_array( $gender, array( 'male', 'female', 'unassigned' ), true ) ? $gender : 'unassigned',
            'is_pro'          => $is_pro,
            'dob'             => $dob,
            'accommodations'  => sanitize_text_field( isset( $dep_data['accommodations'] ) ? $dep_data['accommodations'] : 'none' ),
            'nfaa_number'     => sanitize_text_field( isset( $dep_data['nfaa_number'] ) ? $dep_data['nfaa_number'] : '' ),
            'nfaa_expiration' => sanitize_text_field( isset( $dep_data['nfaa_expiration'] ) ? $dep_data['nfaa_expiration'] : '' ),
            'address_1'       => sanitize_text_field( isset( $dep_data['address_1'] ) ? $dep_data['address_1'] : $p_address['address_1'] ),
            'address_2'       => sanitize_text_field( isset( $dep_data['address_2'] ) ? $dep_data['address_2'] : $p_address['address_2'] ),
            'city'            => sanitize_text_field( isset( $dep_data['city'] ) ? $dep_data['city'] : $p_address['city'] ),
            'state'           => sanitize_text_field( isset( $dep_data['state'] ) ? $dep_data['state'] : $p_address['state'] ),
            'zip'             => sanitize_text_field( isset( $dep_data['zip'] ) ? $dep_data['zip'] : $p_address['zip'] ),
            'is_legacy'       => false,
        );

        $dependents[] = $new_dependent;
        update_user_meta( $primary_user_id, 'sywat_household_dependents', $dependents );

        return $system_id;
    }
}

/**
 * Update an existing household dependent sub-profile.
 *
 * @param int    $primary_user_id WordPress User ID of Primary Account Holder.
 * @param string $system_id       Unique internal System ID of the dependent.
 * @param array  $dep_data        Updated field array.
 * @return bool|WP_Error True on success, or WP_Error on failure.
 */
if ( ! function_exists( 'sywat_update_household_dependent' ) ) {
    function sywat_update_household_dependent( $primary_user_id, $system_id, $dep_data ) {
        $primary_user_id = intval( $primary_user_id );
        $dependents      = get_user_meta( $primary_user_id, 'sywat_household_dependents', true );

        if ( ! is_array( $dependents ) ) {
            return new WP_Error( 'not_found', 'No household dependents registered.' );
        }

        $found_index = -1;
        foreach ( $dependents as $idx => $dep ) {
            if ( isset( $dep['system_id'] ) && (string) $dep['system_id'] === (string) $system_id ) {
                $found_index = $idx;
                break;
            }
        }

        if ( $found_index === -1 ) {
            return new WP_Error( 'dependent_not_found', 'Dependent sub-profile not found.' );
        }

        $current = $dependents[$found_index];

        $first_name = isset( $dep_data['first_name'] ) ? ucfirst( strtolower( sanitize_text_field( $dep_data['first_name'] ) ) ) : $current['first_name'];
        $last_name  = isset( $dep_data['last_name'] ) ? ucfirst( strtolower( sanitize_text_field( $dep_data['last_name'] ) ) ) : $current['last_name'];
        $gender     = isset( $dep_data['gender'] ) ? strtolower( sanitize_text_field( $dep_data['gender'] ) ) : ( isset( $current['gender'] ) ? $current['gender'] : 'unassigned' );
        $is_pro     = isset( $dep_data['is_pro'] ) ? ( $dep_data['is_pro'] === 'yes' ? 'yes' : 'no' ) : ( isset( $current['is_pro'] ) ? $current['is_pro'] : 'no' );

        $dependents[$found_index]['first_name']      = $first_name;
        $dependents[$found_index]['last_name']       = $last_name;
        $dependents[$found_index]['display_name']    = $first_name . ' ' . $last_name;
        $dependents[$found_index]['email']           = isset( $dep_data['email'] ) ? sanitize_email( $dep_data['email'] ) : $current['email'];
        $dependents[$found_index]['phone']           = isset( $dep_data['phone'] ) ? sanitize_text_field( $dep_data['phone'] ) : $current['phone'];
        $dependents[$found_index]['gender']          = in_array( $gender, array( 'male', 'female', 'unassigned' ), true ) ? $gender : 'unassigned';
        $dependents[$found_index]['is_pro']          = $is_pro;
        $dependents[$found_index]['dob']             = isset( $dep_data['dob'] ) ? sanitize_text_field( $dep_data['dob'] ) : $current['dob'];
        $dependents[$found_index]['accommodations']  = isset( $dep_data['accommodations'] ) ? sanitize_text_field( $dep_data['accommodations'] ) : ( isset( $current['accommodations'] ) ? $current['accommodations'] : 'none' );
        $dependents[$found_index]['nfaa_number']     = isset( $dep_data['nfaa_number'] ) ? sanitize_text_field( $dep_data['nfaa_number'] ) : $current['nfaa_number'];
        $dependents[$found_index]['nfaa_expiration'] = isset( $dep_data['nfaa_expiration'] ) ? sanitize_text_field( $dep_data['nfaa_expiration'] ) : $current['nfaa_expiration'];
        $dependents[$found_index]['address_1']       = isset( $dep_data['address_1'] ) ? sanitize_text_field( $dep_data['address_1'] ) : $current['address_1'];
        $dependents[$found_index]['address_2']       = isset( $dep_data['address_2'] ) ? sanitize_text_field( $dep_data['address_2'] ) : $current['address_2'];
        $dependents[$found_index]['city']            = isset( $dep_data['city'] ) ? sanitize_text_field( $dep_data['city'] ) : $current['city'];
        $dependents[$found_index]['state']           = isset( $dep_data['state'] ) ? sanitize_text_field( $dep_data['state'] ) : $current['state'];
        $dependents[$found_index]['zip']             = isset( $dep_data['zip'] ) ? sanitize_text_field( $dep_data['zip'] ) : $current['zip'];

        update_user_meta( $primary_user_id, 'sywat_household_dependents', $dependents );
        return true;
    }
}

/**
 * Delete a household dependent sub-profile from a Primary Account.
 *
 * @param int    $primary_user_id WordPress User ID of Primary Account Holder.
 * @param string $system_id       Unique internal System ID of the dependent.
 * @return bool|WP_Error True on success, or WP_Error on failure.
 */
if ( ! function_exists( 'sywat_delete_household_dependent' ) ) {
    function sywat_delete_household_dependent( $primary_user_id, $system_id ) {
        $primary_user_id = intval( $primary_user_id );
        $dependents      = get_user_meta( $primary_user_id, 'sywat_household_dependents', true );

        if ( ! is_array( $dependents ) ) {
            return new WP_Error( 'not_found', 'No household dependents found.' );
        }

        $filtered = array();
        $deleted  = false;

        foreach ( $dependents as $dep ) {
            if ( isset( $dep['system_id'] ) && (string) $dep['system_id'] === (string) $system_id ) {
                $deleted = true;
                if ( ! empty( $dep['wp_user_id'] ) && intval( $dep['wp_user_id'] ) > 0 ) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    wp_delete_user( intval( $dep['wp_user_id'] ) );
                }
            } else {
                $filtered[] = $dep;
            }
        }

        if ( ! $deleted ) {
            return new WP_Error( 'not_found', 'Dependent sub-profile not found.' );
        }

        update_user_meta( $primary_user_id, 'sywat_household_dependents', array_values( $filtered ) );
        return true;
    }
}

/**
 * Format structured address fields for a user account.
 *
 * @param int $user_id WordPress User ID.
 * @return array Sanitized address components.
 */
if ( ! function_exists( 'sywat_get_user_address' ) ) {
    function sywat_get_user_address( $user_id ) {
        return array(
            'address_1' => get_user_meta( $user_id, 'sywat_address_1', true ),
            'address_2' => get_user_meta( $user_id, 'sywat_address_2', true ),
            'city'      => get_user_meta( $user_id, 'sywat_city', true ),
            'state'     => get_user_meta( $user_id, 'sywat_state', true ),
            'zip'       => get_user_meta( $user_id, 'sywat_zip', true ),
        );
    }
}

/**
 * Promote a dependent sub-profile to a standalone Primary Account Holder at age 18.
 *
 * @param int    $primary_user_id WordPress User ID of current Primary Account Holder.
 * @param string $system_id       Unique internal System ID of the dependent to promote.
 * @param string $new_email         Unique standalone email address.
 * @param string $new_password      Initial password for standalone login.
 * @return int|WP_Error New WP User ID on success, or WP_Error on failure.
 */
if ( ! function_exists( 'sywat_promote_dependent_to_primary' ) ) {
    function sywat_promote_dependent_to_primary( $primary_user_id, $system_id, $new_email, $new_password ) {
        $dep = sywat_get_household_dependent_by_system_id( $primary_user_id, $system_id );

        if ( ! $dep ) {
            return new WP_Error( 'invalid_dependent', 'Dependent sub-profile not found.' );
        }

        $new_email = sanitize_email( $new_email );
        if ( empty( $new_email ) || ! is_email( $new_email ) ) {
            return new WP_Error( 'invalid_email', 'Please provide a valid email address.' );
        }

        if ( email_exists( $new_email ) ) {
            return new WP_Error( 'email_exists', 'An account with email address "' . esc_html( $new_email ) . '" already exists.' );
        }

        if ( empty( $new_password ) ) {
            $new_password = wp_generate_password( 12, true );
        }

        $first_name    = $dep['first_name'];
        $last_name     = $dep['last_name'];
        $base_username = sanitize_user( $first_name . '.' . $last_name, true );
        $username      = $base_username;
        $counter       = 1;

        while ( username_exists( $username ) ) {
            $username = $base_username . $counter;
            $counter++;
        }

        $new_user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $new_email,
            'user_pass'    => $new_password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $first_name . ' ' . $last_name,
            'role'         => 'tfaa_member',
        ) );

        if ( is_wp_error( $new_user_id ) ) {
            return $new_user_id;
        }

        $dep_gender = isset( $dep['gender'] ) ? strtolower( $dep['gender'] ) : 'unassigned';
        $dep_pro    = isset( $dep['is_pro'] ) && $dep['is_pro'] === 'yes' ? 'yes' : 'no';

        update_user_meta( $new_user_id, 'sywat_system_id', $system_id );
        update_user_meta( $new_user_id, 'sywat_gender', $dep_gender );
        update_user_meta( $new_user_id, 'sywat_is_pro', $dep_pro );
        update_user_meta( $new_user_id, 'sywat_dob', $dep['dob'] );
        update_user_meta( $new_user_id, 'sywat_accommodations', isset( $dep['accommodations'] ) ? $dep['accommodations'] : 'none' );
        update_user_meta( $new_user_id, 'sywat_nfaa_number', $dep['nfaa_number'] );
        update_user_meta( $new_user_id, 'sywat_tfaa_number', $dep['nfaa_number'] );
        update_user_meta( $new_user_id, 'sywat_nfaa_expiration', $dep['nfaa_expiration'] );
        update_user_meta( $new_user_id, 'sywat_phone', $dep['phone'] );
        update_user_meta( $new_user_id, 'sywat_address_1', $dep['address_1'] );
        update_user_meta( $new_user_id, 'sywat_address_2', $dep['address_2'] );
        update_user_meta( $new_user_id, 'sywat_city', $dep['city'] );
        update_user_meta( $new_user_id, 'sywat_state', $dep['state'] );
        update_user_meta( $new_user_id, 'sywat_zip', $dep['zip'] );

        sywat_delete_household_dependent( $primary_user_id, $system_id );

        return $new_user_id;
    }
}
