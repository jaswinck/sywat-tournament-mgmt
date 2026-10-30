<?php
/**
 * Sub-Module: Central Roles & Permissions Management
 * Path: includes/core/class-sywat-permissions.php
 * Description: Registers custom TFAA roles automatically and provides central capability checks.
 * Author: SYWAT Archery
 * Version: 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register custom TFAA plugin roles safely in WordPress.
 */
if ( ! function_exists( 'sywat_register_custom_roles' ) ) {
    function sywat_register_custom_roles() {
        // 1. TFAA Member (Read-only archer for online registration & personal score history)
        if ( null === get_role( 'tfaa_member' ) ) {
            add_role( 'tfaa_member', 'TFAA Member', array(
                'read' => true,
            ) );
        }

        // 2. TFAA Tournament Director (Local Desk, Check-In, Walk-Ins, Roster, Score Entry)
        if ( null === get_role( 'tfaa_tournament_director' ) ) {
            add_role( 'tfaa_tournament_director', 'TFAA Tournament Director', array(
                'read' => true,
            ) );
        }

        // 3. TFAA Field Governor (District Oversight - District Shops, Tournaments, Scores)
        if ( null === get_role( 'tfaa_field_governor' ) ) {
            add_role( 'tfaa_field_governor', 'TFAA Field Governor', array(
                'read' => true,
            ) );
        }

        // 4. TFAA Admin (Statewide Plugin Admin across all districts)
        if ( null === get_role( 'tfaa_admin' ) ) {
            add_role( 'tfaa_admin', 'TFAA Admin', array(
                'read' => true,
            ) );
        }

        // 5. TFAA Webmaster (Full Statewide Plugin Admin + WordPress Core Admin)
        if ( null === get_role( 'tfaa_webmaster' ) ) {
            add_role( 'tfaa_webmaster', 'TFAA Webmaster', array(
                'read' => true,
            ) );
        }
    }
}
add_action( 'init', 'sywat_register_custom_roles' );

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
            'tfaa_webmaster',
            'tfaa_admin',
            'tfaa_field_governor',
            'tfaa_tournament_director',
            'sywat_shop_manager',
            'management', // Maintained during prototype migration
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
            'tfaa_webmaster',
            'tfaa_admin',
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
            'tfaa_webmaster',
            'tfaa_admin',
            'tfaa_field_governor',
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
            'tfaa_webmaster',
            'tfaa_admin',
            'tfaa_field_governor',
            'tfaa_tournament_director',
            'sywat_shop_manager',
            'management',
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
            'tfaa_webmaster',
            'tfaa_admin',
            'tfaa_field_governor',
            'tfaa_tournament_director',
            'sywat_shop_manager',
            'sywat_shop_user',
            'management',
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
