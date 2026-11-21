<?php
/**
 * Module: SYWAT Score Management & Entry App
 * Path: includes/scores/class-sywat-scores.php
 * Description: Modular controller shell binding the Scores data engine, CSV export processor, batch/AJAX action handlers, and pure view renderer for the [sywat_scores] front-end scoring application.
 * Compliance: Controller shell — zero HTML output at include time, omitted closing PHP tag.
 * Shortcode: [sywat_scores]
 * Author: SYWAT Archery
 * Version: 6.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load Scores Sub-Modules
require_once __DIR__ . '/scores-data.php';
require_once __DIR__ . '/scores-export.php';
require_once __DIR__ . '/scores-actions.php';
require_once __DIR__ . '/scores-views.php';

// Shortcode Binding
add_shortcode( 'sywat_scores', 'render_sywat_scores_app' );
