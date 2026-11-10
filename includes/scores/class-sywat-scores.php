<?php
/**
 * Module: SYWAT Score Management & Entry App
 * Description: Modular controller for front-end scoring interface featuring real-time AJAX auto-save, score validation (0-300), X-count tie-breakers, division/style sorting, CSV score export generation, and TFAA event score sync engine.
 * Shortcode: [sywat_scores]
 * Author: SYWAT Archery
 * Version: 6.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Load Scores Sub-Modules
require_once __DIR__ . '/scores-export.php';
require_once __DIR__ . '/scores-actions.php';
require_once __DIR__ . '/scores-views.php';

// Shortcode Binding
add_shortcode( 'sywat_scores', 'render_sywat_scores_app' );
