<?php
/**
 * Module: Archer Roster Management & Validation
 * Description: Roster filtering, archer editing forms, family group assignments, and style/division validation.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_calculate_paid_status' ) ) {
    function sywat_calculate_paid_status($pay_option, $division) {
        if ($pay_option === 'Family') return 'Family';
        if ($pay_option === 'Field Governor') return 'Field Governor';
        $youth_divs = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)');
        return in_array($division, $youth_divs) ? 'Youth' : 'Adult';
    }
}

if ( ! function_exists( 'sywat_sanitize_style_for_division' ) ) {
    function sywat_sanitize_style_for_division($style, $division) {
        if (strpos($style, 'Genesis') !== false && $division !== 'Cub (8 - 11)') {
            return 'FS – Freestyle';
        }
        $is_pro_fsl_trad = (
            strpos($style, 'FS Pro') !== false ||
            strpos($style, 'FSL –') !== false ||
            strpos($style, 'TRAD') !== false ||
            stripos($style, 'Traditional') !== false
        );
        $allowed_adult_senior = array('Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)');
        if ($is_pro_fsl_trad && !in_array($division, $allowed_adult_senior)) {
            return 'FS – Freestyle';
        }
        $is_lb = (strpos($style, 'LB') !== false || stripos($style, 'Longbow') !== false);
        if ($is_lb && $division !== 'Adult (18 - 49)') {
            return 'FS – Freestyle';
        }
        return $style;
    }
}
