<?php
/**
 * Module: Archer Registration & Intake Engine
 * Description: Modular controller loader for phone group intake, per-line $75 family capping, and FareHarbor CSV parsing.
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Load Registration Sub-Modules
require_once __DIR__ . '/registration-actions.php';
require_once __DIR__ . '/registration-import.php';
require_once __DIR__ . '/registration-views.php';
require_once __DIR__ . '/registration-modals.php';
require_once __DIR__ . '/registration-scripts.php';
