<?php
/**
 * Module: Archer Registration & Intake Engine
 * Description: Modular controller for phone group intake, per-line $75 family capping, and FareHarbor CSV parsing.
 * Author: SYWAT Archery
 * Version: 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Load Registration Sub-Modules
require_once __DIR__ . '/registration/registration-actions.php';
require_once __DIR__ . '/registration/registration-import.php';
require_once __DIR__ . '/registration/registration-views.php';
require_once __DIR__ . '/registration/registration-scripts.php';
