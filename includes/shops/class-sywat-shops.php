<?php
/**
 * Module: Shop Management & Multi-Tenant Permissions
 * Path: includes/shops/class-sywat-shops.php
 * Description: Main loader for shop management helpers, action handlers, and administrative UI.
 * Author: SYWAT Archery
 * Version: 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'shops-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'shops-actions.php';
require_once plugin_dir_path( __FILE__ ) . 'shops-views.php';
