<?php
/**
 * Sub-Module: Central Roles & Permissions Management
 * Path: includes/core/class-sywat-permissions.php
 * Description: Registers clean TFAA & Shop roles automatically and provides central capability checks.
 * Author: SYWAT Archery
 * Version: 2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register clean, essential TFAA & Shop roles safely in WordPress.
 */
if ( ! function_exists( 'sywat_register_custom_roles' ) ) {
    function sywat_register_custom_roles() {
        // 1. Multi-Shop Roles
        if ( null === get_role( 'sywat_shop_manager' ) ) {
            add_role( 'sywat_shop_manager', 'SYWAT Shop Manager', array( 'read' => true ) );
        }
        if ( null === get_role( 'sywat_shop_user' ) ) {
            add_role( 'sywat_shop_user', 'SYWAT Shop User', array( 'read' => true ) );
        }

        // 2. TFAA Executive Board Roles
        if ( null === get_role( 'tfaa_president' ) ) {
            add_role( 'tfaa_president', 'TFAA President', array( 'read' => true ) );
        }
        if ( null === get_role( 'tfaa_vice_president' ) ) {
            add_role( 'tfaa_vice_president', 'TFAA Vice-President', array( 'read' => true ) );
        }
        if ( null === get_role( 'tfaa_treasurer' ) ) {
            add_role( 'tfaa_treasurer', 'TFAA Treasurer', array( 'read' => true ) );
        }
        if ( null === get_role( 'tfaa_secretary' ) ) {
            add_role( 'tfaa_secretary', 'TFAA Secretary', array( 'read' => true ) );
        }
        if ( null === get_role( 'tfaa_tournament_director' ) ) {
            add_role( 'tfaa_tournament_director', 'TFAA Tournament Director', array( 'read' => true ) );
        }
        if ( null === get_role( 'tfaa_social_media_director' ) ) {
            add_role( 'tfaa_social_media_director', 'TFAA Social Media Director', array( 'read' => true ) );
        }
        if ( null === get_role( 'tfaa_webmaster' ) ) {
            add_role( 'tfaa_webmaster', 'TFAA Webmaster', array( 'read' => true ) );
        }
        if ( null === get_role( 'tfaa_field_governor' ) ) {
            add_role( 'tfaa_field_governor', 'TFAA Field Governor', array( 'read' => true ) );
        }

        // 3. Standard TFAA General Membership
        if ( null === get_role( 'tfaa_member' ) ) {
            add_role( 'tfaa_member', 'TFAA Member', array( 'read' => true ) );
        }
    }
}
add_action( 'init', 'sywat_register_custom_roles' );

/**
 * Utility: Returns array of official TFAA organizational role slugs.
 * Excludes generic site administrators so maintenance accounts do not appear in directories.
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
 * Utility: Internal role match checker.
 */
if ( ! function_exists( 'sywat_user_has_any_role' ) ) {
    function sywat_user_has_any_role( $allowed_roles, $user_id = 0 ) {
        $user = sywat_get_user_object( $user_id );
        if ( ! $user || ! $user->exists() ) {
            return false;
        }
        return (bool) array_intersect( (array) $allowed_roles, (array) $user->roles );
    }
}

/**
 * Permission 1: Can access Master Tournament Management portal & shooter administration.
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
 * Permission 2: Can manage Season CRUD configurations & global settings.
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
 * Permission 3: Can add, edit, or delete archery shop profiles.
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
 * Permission 4: Can enter or edit scores (Desk, Score Entry page, Public Results edit shortcuts).
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
 * Permission 5: Can access the Check-In Desk portal.
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
 * Permission 6: Can create or manage member user accounts.
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
 * Permission 7: Can assign, edit, or remove Board Members.
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
