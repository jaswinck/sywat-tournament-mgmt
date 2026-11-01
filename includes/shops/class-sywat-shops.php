<?php
/**
 * Module: Shop Management & Multi-Tenant Permissions
 * Path: includes/shops/class-sywat-shops.php
 * Description: Main loader for shop management helpers, action handlers, administrative UI, and public directory.
 * Author: SYWAT Archery
 * Version: 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'shops-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'shops-actions.php';
require_once plugin_dir_path( __FILE__ ) . 'shops-views.php';
require_once plugin_dir_path( __FILE__ ) . 'shops-public-views.php';
