<?php
/**
 * Sub-Module: Central Roles & Permissions Management
 * Path: includes/core/class-sywat-permissions.php
 * Description: Registers clean TFAA & Shop roles automatically and provides central capability checks.
 * Author: SYWAT Archery
 * Version: 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register clean, essential organizational roles safely in WordPress.
 * Grants read and private page access without elevating roles to WP site administrators.
 */
if ( ! function_exists( 'sywat_register_custom_roles' ) ) {
    function sywat_register_custom_roles() {
        $roles_definition = array(
            // Multi-Shop Roles
            'sywat_shop_manager'         => 'Shop Manager',
            'sywat_shop_user'            => 'Shop User',

            // Executive Board Roles
            'tfaa_president'             => 'President',
            'tfaa_vice_president'        => 'Vice-President',
            'tfaa_treasurer'             => 'Treasurer',
            'tfaa_secretary'             => 'Secretary',
            'tfaa_tournament_director'   => 'Tournament Director',
            'tfaa_social_media_director' => 'Social Media Director',
            'tfaa_webmaster'             => 'Webmaster',
            'tfaa_field_governor'        => 'Field Governor',

            // General Membership
            'tfaa_member'                => 'Member',
        );

        global $wp_roles;
        if ( ! isset( $wp_roles ) ) {
            $wp_roles = new WP_Roles();
        }

        $needs_db_update = false;

        foreach ( $roles_definition as $slug => $display_name ) {
            $caps = array(
                'read'               => true,
                'read_private_pages' => true,
            );

            if ( null === get_role( $slug ) ) {
                add_role( $slug, $display_name, $caps );
            } else {
                if ( isset( $wp_roles->roles[ $slug ] ) ) {
                    if ( $wp_roles->roles[ $slug ]['name'] !== $display_name ) {
                        $wp_roles->roles[ $slug ]['name'] = $display_name;
                        $wp_roles->role_names[ $slug ]     = $display_name;
                        $needs_db_update = true;
                    }
                    if ( empty( $wp_roles->roles[ $slug ]['capabilities']['read_private_pages'] ) ) {
                        $wp_roles->roles[ $slug ]['capabilities']['read_private_pages'] = true;
                        $needs_db_update = true;
                    }
                }
            }
        }

        if ( $needs_db_update ) {
            update_option( $wp_roles->role_key, $wp_roles->roles );
        }
    }
}
add_action( 'init', 'sywat_register_custom_roles' );

/**
 * Utility: Returns array of official organizational role slugs.
 */
if ( ! function_exists( 'sywat_get_custom_roles_slugs' ) ) {
    function sywat_get_custom_roles_slugs() {
        return array(
            'tfaa_member',
            'tfaa_president',
            'tfaa_vice_president',
            'tfaa_treasurer',
            'tfaa_secretary',
            'tfaa_tournament_director',
            'tfaa_social_media_director',
            'tfaa_webmaster',
            'tfaa_field_governor',
            'sywat_shop_manager',
            'sywat_shop_user',
        );
    }
}

/**
 * Utility: Master list of official TFAA Board Positions (for checkboxes / dropdowns).
 */
if ( ! function_exists( 'sywat_get_tfaa_board_positions_list' ) ) {
    function sywat_get_tfaa_board_positions_list() {
        return array(
            'president'             => 'President',
            'vice_president'        => 'Vice-President',
            'treasurer'             => 'Treasurer',
            'secretary'             => 'Secretary',
            'tournament_director'   => 'Tournament Director',
            'social_media_director' => 'Social Media Director',
            'governor_d1'           => 'Field Governor - District 1',
            'governor_d2'           => 'Field Governor - District 2',
            'governor_d3'           => 'Field Governor - District 3',
            'governor_d4'           => 'Field Governor - District 4',
            'governor_d5'           => 'Field Governor - District 5',
            'governor_d6'           => 'Field Governor - District 6',
            'governor_d7'           => 'Field Governor - District 7',
            'webmaster'             => 'Webmaster',
        );
    }
}

/**
 * Utility: Fetch current or target WP_User object safely.
 */
if ( ! function_exists( 'sywat_get_user_object' ) ) {
    function sywat_get_user_object( $user_id = 0 ) {
        if ( $user_id > 0 ) {
            return get_userdata( $user_id );
        }
        return wp_get_current_user();
    }
}

/**
 * Utility: Normalize role and board position slugs for uniform comparison.
 * Strips 'tfaa_' and 'sywat_' prefixes and maps district governor variants to 'field_governor'.
 */
if ( ! function_exists( 'sywat_normalize_role_slug' ) ) {
    function sywat_normalize_role_slug( $slug ) {
        $s = strtolower( trim( (string) $slug ) );
        $s = str_replace( array( 'tfaa_', 'sywat_' ), '', $s );
        if ( strpos( $s, 'governor' ) === 0 ) {
            return 'field_governor';
        }
        return $s;
    }
}

/**
 * Utility: Internal role & board position match checker.
 * Checks WP Primary Roles AND User Meta board designations (sywat_board_position / sywat_tfaa_role).
 */
if ( ! function_exists( 'sywat_user_has_any_role' ) ) {
    function sywat_user_has_any_role( $allowed_roles, $user_id = 0 ) {
        $user = sywat_get_user_object( $user_id );
        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        $user_roles = (array) $user->roles;
        $board_pos  = get_user_meta( $user->ID, 'sywat_board_position', true );
        $tfaa_role  = get_user_meta( $user->ID, 'sywat_tfaa_role', true );

        $user_all_inputs = array_filter( array_merge( $user_roles, array( $board_pos, $tfaa_role ) ) );
        $user_normalized = array_map( 'sywat_normalize_role_slug', $user_all_inputs );

        // WordPress Site Administrators always pass permission checks
        if ( in_array( 'administrator', $user_normalized, true ) || in_array( 'admin', $user_normalized, true ) ) {
            return true;
        }

        // Compare normalized allowed roles against normalized user roles & user meta board positions
        $allowed_normalized = array_map( 'sywat_normalize_role_slug', (array) $allowed_roles );

        return (bool) array_intersect( $allowed_normalized, $user_normalized );
    }
}

/**
 * Permission 1: Can view the Members Directory portal.
 */
if ( ! function_exists( 'sywat_can_view_members' ) ) {
    function sywat_can_view_members( $user_id = 0 ) {
        $allowed = array(
            'administrator',
            'tfaa_president',
            'tfaa_vice_president',
            'tfaa_treasurer',
            'tfaa_secretary',
            'tfaa_webmaster',
            'tfaa_field_governor',
            'tfaa_tournament_director',
            'tfaa_social_media_director',
            'sywat_shop_manager',
            'sywat_shop_user',
            'tfaa_member',
        );
        return sywat_user_has_any_role( $allowed, $user_id );
    }
}

/**
 * Permission 2: Can access Master Tournament Management portal & shooter administration.
 */
if ( ! function_exists( 'sywat_can_manage_tournaments' ) ) {
    function sywat_can_manage_tournaments( $user_id = 0 ) {
        $allowed = array(
            'administrator',
            'tfaa_president',
            'tfaa_vice_president',
            'tfaa_treasurer',
            'tfaa_secretary',
            'tfaa_webmaster',
            'tfaa_field_governor',
            'tfaa_tournament_director',
            'tfaa_social_media_director',
            'sywat_shop_manager',
        );
        return sywat_user_has_any_role( $allowed, $user_id );
    }
}

/**
 * Permission 3: Can manage Season CRUD configurations & global settings.
 */
if ( ! function_exists( 'sywat_can_manage_seasons' ) ) {
    function sywat_can_manage_seasons( $user_id = 0 ) {
        $allowed = array(
            'administrator',
            'tfaa_president',
            'tfaa_vice_president',
            'tfaa_webmaster',
            'tfaa_tournament_director',
        );
        return sywat_user_has_any_role( $allowed, $user_id );
    }
}

/**
 * Permission 4: Can add, edit, or delete archery shop profiles.
 */
if ( ! function_exists( 'sywat_can_manage_shops' ) ) {
    function sywat_can_manage_shops( $user_id = 0 ) {
        $allowed = array(
            'administrator',
            'tfaa_president',
            'tfaa_vice_president',
            'tfaa_webmaster',
            'tfaa_field_governor',
            'tfaa_tournament_director',
        );
        return sywat_user_has_any_role( $allowed, $user_id );
    }
}

/**
 * Permission 5: Can enter or edit scores (Desk, Score Entry page, Public Results edit shortcuts).
 */
if ( ! function_exists( 'sywat_can_edit_scores' ) ) {
    function sywat_can_edit_scores( $user_id = 0 ) {
        $allowed = array(
            'administrator',
            'tfaa_president',
            'tfaa_vice_president',
            'tfaa_treasurer',
            'tfaa_secretary',
            'tfaa_webmaster',
            'tfaa_field_governor',
            'tfaa_tournament_director',
            'tfaa_social_media_director',
            'sywat_shop_manager',
            'sywat_shop_user',
            'tfaa_member',
        );
        return sywat_user_has_any_role( $allowed, $user_id );
    }
}

/**
 * Permission 6: Can access the Check-In Desk portal.
 */
if ( ! function_exists( 'sywat_can_access_desk' ) ) {
    function sywat_can_access_desk( $user_id = 0 ) {
        $allowed = array(
            'administrator',
            'tfaa_president',
            'tfaa_vice_president',
            'tfaa_treasurer',
            'tfaa_secretary',
            'tfaa_webmaster',
            'tfaa_field_governor',
            'tfaa_tournament_director',
            'tfaa_social_media_director',
            'sywat_shop_manager',
            'sywat_shop_user',
            'tfaa_member',
        );
        return sywat_user_has_any_role( $allowed, $user_id );
    }
}

/**
 * Permission 7: Can create or manage member user accounts.
 */
if ( ! function_exists( 'sywat_can_create_members' ) ) {
    function sywat_can_create_members( $user_id = 0 ) {
        $allowed = array(
            'administrator',
            'tfaa_president',
            'tfaa_vice_president',
            'tfaa_secretary',
            'tfaa_webmaster',
            'tfaa_tournament_director',
        );
        return sywat_user_has_any_role( $allowed, $user_id );
    }
}

/**
 * Permission 8: Can assign, edit, or remove Board Members.
 */
if ( ! function_exists( 'sywat_can_manage_board' ) ) {
    function sywat_can_manage_board( $user_id = 0 ) {
        $allowed = array(
            'administrator',
            'tfaa_president',
            'tfaa_vice_president',
            'tfaa_webmaster',
        );
        return sywat_user_has_any_role( $allowed, $user_id );
    }
}

/**
 * Helper: Retrieve assigned District slug for Field Governor user scoping.
 */
if ( ! function_exists( 'sywat_get_user_district' ) ) {
    function sywat_get_user_district( $user_id = 0 ) {
        $user = sywat_get_user_object( $user_id );
        if ( ! $user ) return '';
        $district = get_user_meta( $user->ID, 'sywat_district', true );
        return ! empty( $district ) ? $district : 'district_1';
    }
}
