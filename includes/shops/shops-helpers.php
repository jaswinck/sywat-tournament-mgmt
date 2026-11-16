<?php
/**
 * Sub-Module: Shop Helpers & WP User Profile Integration
 * Path: includes/shops/shops-helpers.php
 * Description: Provides formatting, normalization, registration link sanitization helpers, and WP user profile shop assignment hooks.
 * Author: SYWAT Archery / TFAA
 * Version: 2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Standardize Phone Number Formatting (XXX-XXX-XXXX)
 */
if ( ! function_exists( 'sywat_format_phone_number' ) ) {
    function sywat_format_phone_number( $phone ) {
        $digits = preg_replace( '/\D/', '', $phone );
        if ( strlen( $digits ) === 10 ) {
            return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
        }
        return sanitize_text_field( $phone );
    }
}

/**
 * Helper: Normalize District Input (e.g. "1", "6", "Dist 6", "District #7") to Standard Key ("District 1" - "District 7")
 */
if ( ! function_exists( 'sywat_normalize_district' ) ) {
    function sywat_normalize_district( $input ) {
        if ( empty( $input ) ) return '';
        $num = preg_replace( '/[^0-9]/', '', (string) $input );
        if ( ! empty( $num ) && intval( $num ) >= 1 && intval( $num ) <= 7 ) {
            return 'District ' . intval( $num );
        }
        return sanitize_text_field( $input );
    }
}

/**
 * Helper: Normalize State Input to Match Form Dropdown Values
 */
if ( ! function_exists( 'sywat_normalize_state_code' ) ) {
    function sywat_normalize_state_code( $input ) {
        $clean = strtolower( trim( $input ) );
        if ( empty( $clean ) ) return 'TX - Texas';

        $state_map = array(
            'tx'         => 'TX - Texas',
            'texas'      => 'TX - Texas',
            'la'         => 'LA - Louisiana',
            'louisiana'  => 'LA - Louisiana',
            'ok'         => 'OK - Oklahoma',
            'oklahoma'   => 'OK - Oklahoma',
            'nm'         => 'NM - New Mexico',
            'new mexico' => 'NM - New Mexico',
            'ar'         => 'AR - Arkansas',
            'arkansas'   => 'AR - Arkansas',
        );

        foreach ( $state_map as $key => $full_val ) {
            if ( strcasecmp( $clean, strtolower( $full_val ) ) === 0 ) {
                return $full_val;
            }
        }

        return isset( $state_map[$clean] ) ? $state_map[$clean] : 'TX - Texas';
    }
}

/**
 * Sanitize and normalize repeatable external online registration links.
 * Accepts either parallel ($labels, $urls) POST arrays or an array of associative ['label' => ..., 'url' => ...] items.
 * Pure array formatter with zero database queries.
 *
 * @param array      $labels_or_items Parallel labels array when $urls is passed, or array of link items.
 * @param array|null $urls            Optional parallel URLs array from form submission.
 * @return array<int, array{label: string, url: string}> Normalized list of valid registration links.
 */
if ( ! function_exists( 'sywat_sanitize_registration_links' ) ) {
    function sywat_sanitize_registration_links( $labels_or_items, $urls = null ) {
        $sanitized = array();
        $seen_urls = array();

        if ( is_array( $urls ) ) {
            $labels = is_array( $labels_or_items ) ? $labels_or_items : array();
            foreach ( $urls as $idx => $raw_url ) {
                $clean_url = esc_url_raw( trim( (string) $raw_url ), array( 'http', 'https' ) );
                if ( $clean_url === '' ) {
                    continue;
                }

                $dedup_key = strtolower( $clean_url );
                if ( isset( $seen_urls[ $dedup_key ] ) ) {
                    continue;
                }
                $seen_urls[ $dedup_key ] = true;

                $raw_label   = isset( $labels[ $idx ] ) ? (string) $labels[ $idx ] : '';
                $clean_label = trim( sanitize_text_field( $raw_label ) );

                $sanitized[] = array(
                    'label' => $clean_label,
                    'url'   => $clean_url,
                );
            }
            return $sanitized;
        }

        if ( is_array( $labels_or_items ) ) {
            foreach ( $labels_or_items as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $raw_url   = isset( $item['url'] ) ? (string) $item['url'] : '';
                $clean_url = esc_url_raw( trim( $raw_url ), array( 'http', 'https' ) );
                if ( $clean_url === '' ) {
                    continue;
                }

                $dedup_key = strtolower( $clean_url );
                if ( isset( $seen_urls[ $dedup_key ] ) ) {
                    continue;
                }
                $seen_urls[ $dedup_key ] = true;

                $raw_label   = isset( $item['label'] ) ? (string) $item['label'] : '';
                $clean_label = trim( sanitize_text_field( $raw_label ) );

                $sanitized[] = array(
                    'label' => $clean_label,
                    'url'   => $clean_url,
                );
            }
        }

        return $sanitized;
    }
}

/**
 * Extract normalized default online registration links from a shop or tournament record array.
 * Pure array accessor with zero database queries.
 *
 * @param array $record Shop or tournament configuration array.
 * @return array<int, array{label: string, url: string}> Normalized list of registration links.
 */
if ( ! function_exists( 'sywat_get_shop_registration_links' ) ) {
    function sywat_get_shop_registration_links( $record ) {
        if ( ! is_array( $record ) || empty( $record['registration_links'] ) || ! is_array( $record['registration_links'] ) ) {
            return array();
        }
        return sywat_sanitize_registration_links( $record['registration_links'] );
    }
}

/**
 * Ensure default seed shop exists upon loading
 */
add_action( 'init', 'sywat_ensure_default_shop_exists' );
if ( ! function_exists( 'sywat_ensure_default_shop_exists' ) ) {
    function sywat_ensure_default_shop_exists() {
        $shops = get_option( 'sywat_shops_list', array() );
        if ( empty( $shops ) || ! is_array( $shops ) ) {
            $default_lines = array(
                array( 'name' => 'Saturday 10AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'name' => 'Saturday 2PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'name' => 'Sunday 10AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
                array( 'name' => 'Sunday 2PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
            );
            $shops = array(
                'shop_ccr' => array(
                    'id'            => 'shop_ccr',
                    'name'          => 'Cinnamon Creek Ranch',
                    'district'      => 'District 5',
                    'facilities'    => array( 'pro_shop', 'indoor_range', '3d_range' ),
                    'contact'       => 'Hannah Lange / Beau Brennan',
                    'email'         => 'events@cinnamoncreekranch.com / beau@cinnamoncreekranch.com',
                    'phone'         => '817-439-8998',
                    'address_1'     => '13795 Old Denton Rd',
                    'address_2'     => '',
                    'city'          => 'Roanoke',
                    'state'         => 'TX - Texas',
                    'zip'           => '76262',
                    'default_lines' => $default_lines,
                    'created_at'    => current_time( 'mysql' ),
                )
            );
            update_option( 'sywat_shops_list', $shops );
        }
    }
}

/**
 * Fetch All Configured Archery Shops & Clubs (Sorted Alphabetically by Name)
 */
if ( ! function_exists( 'sywat_get_shops' ) ) {
    function sywat_get_shops() {
        $shops = get_option( 'sywat_shops_list', array() );
        if ( is_array( $shops ) && ! empty( $shops ) ) {
            uasort( $shops, function( $a, $b ) {
                $name_a = isset( $a['name'] ) ? $a['name'] : '';
                $name_b = isset( $b['name'] ) ? $b['name'] : '';
                return strcasecmp( $name_a, $name_b );
            });
        }
        return is_array( $shops ) ? $shops : array();
    }
}

/**
 * Fetch Current User's Assigned Shop ID
 * Allows Administrators, Executive Board, and Tournament Directors to switch shop views freely
 */
if ( ! function_exists( 'sywat_get_current_user_shop_id' ) ) {
    function sywat_get_current_user_shop_id() {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) return 'shop_ccr';

        $admin_roles = array( 'administrator', 'management', 'tfaa_webmaster', 'tfaa_admin', 'tfaa_president', 'tfaa_tournament_director', 'tfaa_field_governor' );
        if ( array_intersect( $admin_roles, (array) $user->roles ) ) {
            if ( isset( $_REQUEST['sywat_shop_filter'] ) ) {
                return sanitize_text_field( $_REQUEST['sywat_shop_filter'] );
            }
        }

        $shop_id = get_user_meta( $user->ID, 'sywat_shop_id', true );
        return ! empty( $shop_id ) ? $shop_id : 'shop_ccr';
    }
}

/**
 * WP Admin User Profile Integration (Users > Edit User)
 */
add_action( 'show_user_profile', 'sywat_render_user_profile_shop_field' );
add_action( 'edit_user_profile', 'sywat_render_user_profile_shop_field' );

if ( ! function_exists( 'sywat_render_user_profile_shop_field' ) ) {
    function sywat_render_user_profile_shop_field( $user ) {
        $curr_user = wp_get_current_user();
        $admin_roles = array( 'administrator', 'management', 'tfaa_webmaster', 'tfaa_admin', 'tfaa_president', 'tfaa_tournament_director' );
        if ( ! array_intersect( $admin_roles, (array) $curr_user->roles ) ) return;

        $target_roles = array( 'sywat_shop_manager', 'sywat_shop_user', 'tfaa_tournament_director' );
        if ( ! array_intersect( $target_roles, (array) $user->roles ) ) {
            return;
        }
        $current_shop = get_user_meta( $user->ID, 'sywat_shop_id', true );
        $shops = sywat_get_shops();
        ?>
        <h3>SYWAT Tournament Shop / Club Assignment</h3>
        <table class="form-table">
            <tr>
                <th><label for="sywat_shop_id">Assigned Location</label></th>
                <td>
                    <select name="sywat_shop_id" id="sywat_shop_id">
                        <option value="" <?php selected( empty( $current_shop ), true ); ?>>-- Unassigned (No Shop/Club Linked) --</option>
                        <?php foreach ( $shops as $s_id => $shop ): ?>
                            <option value="<?php echo esc_attr( $s_id ); ?>" <?php selected( $current_shop, $s_id ); ?>>
                                <?php echo esc_html( $shop['name'] ); ?> (ID: <?php echo esc_html( $s_id ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Select the shop or club this user account belongs to for tournament management and scoping.</p>
                </td>
            </tr>
        </table>
        <?php
    }
}

add_action( 'personal_options_update', 'sywat_save_user_profile_shop_field' );
add_action( 'edit_user_profile_update', 'sywat_save_user_profile_shop_field' );

if ( ! function_exists( 'sywat_save_user_profile_shop_field' ) ) {
    function sywat_save_user_profile_shop_field( $user_id ) {
        $curr_user = wp_get_current_user();
        $admin_roles = array( 'administrator', 'management', 'tfaa_webmaster', 'tfaa_admin', 'tfaa_president', 'tfaa_tournament_director' );
        if ( ! array_intersect( $admin_roles, (array) $curr_user->roles ) ) return;

        if ( isset( $_POST['sywat_shop_id'] ) ) {
            $val = sanitize_text_field( $_POST['sywat_shop_id'] );
            if ( empty( $val ) ) {
                delete_user_meta( $user_id, 'sywat_shop_id' );
            } else {
                update_user_meta( $user_id, 'sywat_shop_id', $val );
            }
        }
    }
}
