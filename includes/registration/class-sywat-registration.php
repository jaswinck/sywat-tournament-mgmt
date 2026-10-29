<?php
/**
 * Module: Archer Registration & Intake Engine
 * Description: Modular controller loader for phone group intake, per-line $75 family capping, and FareHarbor CSV parsing.
 * Author: SYWAT Archery
 * Version: 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Load Registration Sub-Modules from the current directory
require_once __DIR__ . '/registration-actions.php';
require_once __DIR__ . '/registration-import.php';
require_once __DIR__ . '/registration-views.php';
require_once __DIR__ . '/registration-scripts.php';
