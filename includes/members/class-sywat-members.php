<?php
/**
 * Sub-Module: Member Management Loader Shell
 * Path: includes/members/class-sywat-members.php
 * Description: Main loader binding public/admin shortcode [sywat_members].
 * Author: SYWAT Archery
 * Version: 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Require Sub-Module Files
require_once plugin_dir_path( __FILE__ ) . 'members-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'members-actions.php';
require_once plugin_dir_path( __FILE__ ) . 'members-views.php';
require_once plugin_dir_path( __FILE__ ) . 'members-modals.php';

// Shortcode Registration
if ( shortcode_exists( 'sywat_members' ) ) remove_shortcode( 'sywat_members' );
add_shortcode( 'sywat_members', 'sywat_render_members_portal' );
