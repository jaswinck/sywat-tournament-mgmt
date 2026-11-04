<?php
/**
 * Module: Archer Roster Management & Validation Engine
 * Path: includes/roster/class-sywat-roster.php
 * Description: Archer profile editing logic, division/style restriction enforcement, strict family line-scoping, and roster table renderers.
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Load Roster Sub-Modules
require_once __DIR__ . '/roster-actions.php';
require_once __DIR__ . '/roster-views.php';
require_once __DIR__ . '/roster-modals.php';
require_once __DIR__ . '/roster-scripts.php';
