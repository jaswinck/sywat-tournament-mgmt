<?php
/**
 * Module: Financial Accounting & Invoice Engine
 * Description: Host vs. TFAA 50/50 fee split math, family cap calculations, Zelle QR generation, and invoice printing.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_calculate_accounting_totals' ) ) {
    function sywat_calculate_accounting_totals($regs_list) {
        $g1_youth  = 0;
        $g2_adult  = 0;
        $g4_fg     = 0;
        $g5_noshow = 0;
        $family_archers = 0;
        $family_groups  = array();

        $youth_divs = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)');

        foreach ($regs_list as $r) {
            $is_checked_in = ! empty( $r['checked_in'] );
            $is_no_show_div = (isset($r['division']) && $r['division'] === 'No Show');

            if ( ! $is_checked_in || $is_no_show_div ) {
                $g5_noshow++;
                continue;
            }

            $paid_cat = isset($r['paid']) ? $r['paid'] : '';
            $div      = isset($r['division']) ? $r['division'] : '';

            if ($paid_cat === 'Field Governor') {
                $g4_fg++;
            } elseif ($paid_cat === 'Family') {
                $family_archers++;
                
                if (!empty($r['family_id'])) {
                    $fam_key = $r['family_id'];
                } elseif (!empty($r['booking_id'])) {
                    $fam_key = $r['booking_id'];
                } else {
                    $name_parts = array_values(array_filter(explode(' ', trim($r['name']))));
                    $last_name  = !empty($name_parts) ? strtolower(end($name_parts)) : 'fam';
                    $line_idx   = isset($r['line_index']) ? $r['line_index'] : '0';
                    $fam_key    = 'line_' . $line_idx . '_last_' . $last_name;
                }
                $family_groups[$fam_key] = true;

            } else {
                if (in_array($div, $youth_divs) || $paid_cat === 'Youth') {
                    $g1_youth++;
                } else {
                    $g2_adult++;
                }
            }
        }

        $unique_families    = count($family_groups);
        $total_transactions = $g1_youth + $g2_adult + $unique_families + $g4_fg;
        $total_archers      = count($regs_list);
        $total_checked_in   = $total_archers - $g5_noshow;

        $tot_g1 = $g1_youth * 25;
        $tot_g2 = $g2_adult * 30;
        $tot_g3 = $unique_families * 75;
        $tot_fg = $g4_fg * 0;

        $total_sywat_fees = $tot_g1 + $tot_g2 + $tot_g3 + $tot_fg;
        $host_retains     = $total_sywat_fees * 0.50;
        $tfaa_to_pay      = $total_sywat_fees * 0.50;

        return array(
            'group1_youth'       => $g1_youth,
            'tot_g1'             => $tot_g1,
            'group2_adult'       => $g2_adult,
            'tot_g2'             => $tot_g2,
            'group3_families'    => $unique_families,
            'tot_g3'             => $tot_g3,
            'family_archers'     => $family_archers,
            'group4_fg'          => $g4_fg,
            'tot_fg'             => $tot_fg,
            'group5_noshow'      => $g5_noshow,
            'total_transactions' => $total_transactions,
            'total_archers'      => $total_archers,
            'total_checked_in'   => $total_checked_in,
            'total_sywat_fees'   => $total_sywat_fees,
            'host_retains'       => $host_retains,
            'tfaa_to_pay'        => $tfaa_to_pay
        );
    }
}
