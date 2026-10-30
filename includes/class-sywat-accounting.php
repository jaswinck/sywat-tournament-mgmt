<?php
/**
 * Module: Financial Accounting & Invoice Engine
 * Description: Host vs. TFAA 50/50 fee split math, family cap calculations, Zelle QR code rendering, dynamic shop header metadata, and printable invoice/roster UI.
 * Author: SYWAT Archery
 * Version: 2.3.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_calculate_accounting_totals' ) ) {
    function sywat_calculate_accounting_totals($regs_list) {
        $g1_youth = 0; $g2_adult = 0; $g4_fg = 0; $g5_noshow = 0;
        $family_archers = 0; $family_groups = array();
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

// Printable Invoice Modal UI Renderer
if ( ! function_exists( 'sywat_render_invoice_modal' ) ) {
    function sywat_render_invoice_modal( $selected_tournament, $registrations ) {
        $all_tournament_regs = array_filter( $registrations, function($r) use ($selected_tournament) {
            return isset($r['tournament']) && strcasecmp($r['tournament'], $selected_tournament) === 0;
        });

        $acct = sywat_calculate_accounting_totals($all_tournament_regs);
        $t_dates = get_option('sywat_dates_' . sanitize_title($selected_tournament), '');
        $tourn_dates_str = !empty($t_dates) ? $t_dates : $selected_tournament;

        // Fetch Shop Info dynamically based on Tournament Assignment
        $tournaments = get_option( 'sywat_tournaments_list', array() );
        $all_shops   = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
        $shop_id     = 'shop_ccr';

        if ( isset( $tournaments[$selected_tournament] ) ) {
            $t_data  = $tournaments[$selected_tournament];
            $shop_id = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
        }

        $shop_info   = isset( $all_shops[$shop_id] ) ? $all_shops[$shop_id] : array();
        $shop_name   = ! empty( $shop_info['name'] ) ? $shop_info['name'] : 'Cinnamon Creek Ranch';
        $shop_cont   = ! empty( $shop_info['contact'] ) ? $shop_info['contact'] : 'Hannah Lange / Beau Brennan';
        $shop_email  = ! empty( $shop_info['email'] ) ? $shop_info['email'] : 'events@cinnamoncreekranch.com / beau@cinnamoncreekranch.com';
        $shop_phone  = ! empty( $shop_info['phone'] ) ? $shop_info['phone'] : '817-439-8998';

        // Retrieve uploaded Zelle QR code image path directly
        $zelle_qr_url = get_option( 'sywat_zelle_qr_url', '/wp-content/uploads/zelle-qr.png' );
        if ( empty( $zelle_qr_url ) ) {
            $zelle_qr_url = '/wp-content/uploads/zelle-qr.png';
        }

        if ( is_numeric( $zelle_qr_url ) ) {
            $att_url = wp_get_attachment_url( intval( $zelle_qr_url ) );
            if ( $att_url ) $zelle_qr_url = $att_url;
        }

        if ( function_exists( 'set_url_scheme' ) && ! empty( $zelle_qr_url ) && strpos( $zelle_qr_url, 'http' ) === 0 ) {
            $zelle_qr_url = set_url_scheme( $zelle_qr_url, 'https' );
        }
        ?>

        <div class="no-print" style="margin-bottom: 15px; display: flex; justify-content: flex-end;">
            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-print" onclick="window.print()">Print / Save Invoice PDF</button>
        </div>

        <div id="sywat_print_area">
            <style>
                .invoice-box { font-family: Arial, sans-serif; color: #000; width: 100%; max-width: 800px; margin: 0 auto; padding: 10px; box-sizing: border-box; }
                .invoice-title { text-align: center; font-size: 20px; font-weight: bold; margin-bottom: 2px; }
                .invoice-subtitle { text-align: center; font-size: 11px; margin-bottom: 15px; font-style: italic; }
                .invoice-header-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                .invoice-header-table td { padding: 4px 6px; font-size: 12px; }
                .invoice-header-label { font-weight: bold; width: 28%; white-space: nowrap; }
                .invoice-header-val { background-color: #DAE6F5 !important; border: 1px solid #7092BE !important; font-weight: bold; padding: 4px 8px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .invoice-fee-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                .invoice-fee-table th { background-color: #f1f5f9 !important; border: 1px solid #000 !important; padding: 6px; font-size: 12px; text-align: left; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .invoice-fee-table td { border: 1px solid #000 !important; padding: 6px; font-size: 12px; }
                .blue-shade { background-color: #DAE6F5 !important; font-weight: bold; text-align: right; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .invoice-footer { text-align: center; font-size: 11px; margin-top: 15px; line-height: 1.5; }
                .invoice-footer-bold { font-weight: bold; color: #1e3a8a; }

                @media print {
                    body * { visibility: hidden !important; }
                    #sywat_print_area, #sywat_print_area * { visibility: visible !important; }
                    #sywat_print_area { position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important; margin: 0 !important; padding: 10px !important; }
                    .no-print { display: none !important; }
                    .invoice-qr-code { display: inline-block !important; visibility: visible !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; width: 120px !important; height: auto !important; }
                }
            </style>

            <div class="invoice-box">
                <div class="invoice-title">TFAA TOURNAMENT INVOICE</div>
                <div class="invoice-subtitle">(Fill in all the Blue Shade Boxes)</div>

                <table class="invoice-header-table">
                    <tr><td class="invoice-header-label">SYWAT Tournament Date:</td><td class="invoice-header-val"><?php echo esc_html($tourn_dates_str); ?></td></tr>
                    <tr><td class="invoice-header-label">Archery Shop/Club Name:</td><td class="invoice-header-val"><?php echo esc_html($shop_name); ?></td></tr>
                    <tr><td class="invoice-header-label">Contact Name:</td><td class="invoice-header-val"><?php echo esc_html($shop_cont); ?></td></tr>
                    <tr><td class="invoice-header-label">Email Address:</td><td class="invoice-header-val"><?php echo esc_html($shop_email); ?></td></tr>
                    <tr><td class="invoice-header-label">Phone:</td><td class="invoice-header-val"><?php echo esc_html($shop_phone); ?></td></tr>
                </table>

                <div style="font-weight:bold; font-size:13px; margin-bottom:6px;">Registration Tournament Fees:</div>

                <table class="invoice-fee-table">
                    <thead>
                        <tr>
                            <th style="width: 50%;">Fee Class</th>
                            <th style="width: 15%; text-align: center;">#</th>
                            <th style="width: 15%; text-align: right;">$</th>
                            <th style="width: 20%; text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Cub/Youth/Young Adult</td>
                            <td class="blue-shade" style="text-align: center;"><?php echo $acct['group1_youth']; ?></td>
                            <td style="text-align: right;">$25</td>
                            <td class="blue-shade">$<?php echo number_format($acct['tot_g1'], 2); ?></td>
                        </tr>
                        <tr>
                            <td>Adult/Senior/Silver Senior/Master</td>
                            <td class="blue-shade" style="text-align: center;"><?php echo $acct['group2_adult']; ?></td>
                            <td style="text-align: right;">$30</td>
                            <td class="blue-shade">$<?php echo number_format($acct['tot_g2'], 2); ?></td>
                        </tr>
                        <tr>
                            <td>Family</td>
                            <td class="blue-shade" style="text-align: center;"><?php echo $acct['group3_families']; ?></td>
                            <td style="text-align: right;">$75</td>
                            <td class="blue-shade">$<?php echo number_format($acct['tot_g3'], 2); ?></td>
                        </tr>
                        <tr>
                            <td>Field Governor <span style="font-size:10px; font-style:italic;">(1 free per weekend)</span></td>
                            <td class="blue-shade" style="text-align: center;"><?php echo $acct['group4_fg']; ?></td>
                            <td style="text-align: right;">$0</td>
                            <td class="blue-shade">$0.00</td>
                        </tr>
                        <tr>
                            <td colspan="3" style="font-weight: bold; text-align: right;">TOTAL SYWAT FEES</td>
                            <td class="blue-shade">$<?php echo number_format($acct['total_sywat_fees'], 2); ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" style="font-weight: bold; text-align: right;">Host Retains 50%</td>
                            <td class="blue-shade">$<?php echo number_format($acct['host_retains'], 2); ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" style="font-weight: bold; text-align: right;">TFAA to be Paid 50%</td>
                            <td class="blue-shade">$<?php echo number_format($acct['tfaa_to_pay'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="invoice-footer">
                    <p>Please remit TFAA Payment via Check or Zelle and a copy of this Invoice within 7 days to:</p>
                    <p class="invoice-footer-bold">Texas Field Archery Association<br>C/O Anna Hanson<br>722 Cannon Ball Dr.<br>Spring TX 77380<br>Email: anna.hanson@resolute-tx.com</p>
                    <p style="margin-top: 8px;">Send Money with Zelle®, Tag: <strong style="color:#1e3a8a;">tfaachecking</strong></p>
                    
                    <div style="margin-top: 10px; text-align: center;">
                        <img src="<?php echo esc_url($zelle_qr_url); ?>" class="invoice-qr-code" alt="Zelle QR Code" style="width: 120px; height: auto; display: inline-block;" loading="eager">
                    </div>
                    
                    <p style="font-weight: bold; font-size: 13px; margin-top: 10px;">Thank You for Hosting!</p>
                </div>
            </div>
        </div>
        <?php
    }
}

// Printable Roster Modal UI Renderer
if ( ! function_exists( 'sywat_render_print_modal' ) ) {
    function sywat_render_print_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $is_specific_line ) {
        $filtered_registrations = array_filter( $registrations, function( $reg ) use ( $selected_tournament, $filter_line_idx, $available_lines ) {
            if ( ! isset( $reg['tournament'] ) || strcasecmp( $reg['tournament'], $selected_tournament ) !== 0 ) return false;
            if ( $filter_line_idx !== 'all' ) {
                $match_idx  = isset( $reg['line_index'] ) && (string)$reg['line_index'] === (string)$filter_line_idx;
                $match_name = isset( $available_lines[$filter_line_idx]['name'] ) && isset( $reg['line_name'] ) && strcasecmp( trim( $reg['line_name'] ), trim( $available_lines[$filter_line_idx]['name'] ) ) === 0;
                if ( ! $match_idx && ! $match_name ) return false;
            }
            return true;
        });

        usort( $filtered_registrations, function( $a, $b ) {
            return strcasecmp( isset( $a['name'] ) ? trim( $a['name'] ) : '', isset( $b['name'] ) ? trim( $b['name'] ) : '' );
        });

        $acct = sywat_calculate_accounting_totals($filtered_registrations);
        ?>

        <div class="no-print" style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 10px;">
            <form method="get" action="" style="display: flex; gap: 12px; align-items: center; margin: 0;">
                <input type="hidden" name="tab" value="manage">
                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="modal_action" value="print">
                <div style="min-width: 220px;">
                    <label for="filter_line_idx_print" style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Filter Roster by Line</label>
                    <select name="filter_line_idx" id="filter_line_idx_print" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px;" onchange="this.form.submit()">
                        <option value="all" <?php selected( $filter_line_idx, 'all' ); ?>>Entire Weekend (All Lines)</option>
                        <?php foreach ( $available_lines as $idx => $line ): ?>
                            <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$filter_line_idx, (string)$idx ); ?>><?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-print" onclick="window.print()">Print / Save PDF</button>
        </div>

        <div id="sywat_print_area">
            <style>
                @media print {
                    body * { visibility: hidden !important; }
                    #sywat_print_area, #sywat_print_area * { visibility: visible !important; }
                    #sywat_print_area { position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important; margin: 0 !important; padding: 10px !important; }
                    .no-print { display: none !important; }
                }
            </style>

            <div style="margin-bottom: 12px; border-bottom: 2px solid #1d2327; padding-bottom: 8px;">
                <h2 style="margin: 0; font-size: 18px; color: #1d2327;">TFAA SYWAT Tournament Roster</h2>
                <div style="font-size: 12px; color: #475569; font-weight: 600; margin-top: 4px;">
                    Tournament: <?php echo esc_html( $selected_tournament ); ?> | Line: <?php echo ($is_specific_line && isset($available_lines[$filter_line_idx])) ? esc_html($available_lines[$filter_line_idx]['name']) : 'Entire Weekend'; ?> | Total Roster Archers: <?php echo $acct['total_archers']; ?>
                </div>

                <div style="margin-top: 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px; font-size: 11px; color: #0f172a;">
                    <strong>📊 ACCOUNTING & FINANCIAL SUMMARY:</strong><br>
                    • <strong>Cub / Youth / YA:</strong> <?php echo $acct['group1_youth']; ?> Paid Transactions<br>
                    • <strong>Adult / Senior / SS / MS:</strong> <?php echo $acct['group2_adult']; ?> Paid Transactions<br>
                    • <strong>Family Category:</strong> <?php echo $acct['group3_families']; ?> Unique Paid Families (<?php echo $acct['family_archers']; ?> Checked-In Archers)<br>
                    • <strong>Field Governor:</strong> <?php echo $acct['group4_fg']; ?> Transactions (Free)<br>
                    • <strong>No Show:</strong> <?php echo $acct['group5_noshow']; ?> Archers (Not Checked In)<br>
                    <div style="margin-top: 3px; border-top: 1px dashed #94a3b8; padding-top: 3px; font-weight: bold; color: #1e3a8a;">
                        Total Processed Transactions: <?php echo $acct['total_transactions']; ?> &nbsp;|&nbsp; Checked-In Archers: <?php echo $acct['total_checked_in']; ?> / <?php echo $acct['total_archers']; ?> Total Roster
                    </div>
                </div>
            </div>

            <?php if ( empty( $filtered_registrations ) && ! $is_specific_line ): ?>
                <p style="color: #646970; font-style: italic;">No archer records available for this filter.</p>
            <?php else: ?>
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th style="width: 4%;">#</th>
                            <th style="width: 22%;">Name</th>
                            <th style="width: 14%;">Line</th>
                            <th style="width: 11%;">Division</th>
                            <th style="width: 7%;">Gender</th>
                            <th style="width: 16%;">Style</th>
                            <th style="width: 12%;">Paid</th>
                            <th style="width: 7%; text-align: center;">Score</th>
                            <th style="width: 7%; text-align: center;">X Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $num = 1; foreach ( $filtered_registrations as $reg ): ?>
                            <?php 
                                $is_checked_in = ! empty( $reg['checked_in'] );
                                $is_no_show    = ( isset( $reg['division'] ) && $reg['division'] === 'No Show' );

                                if ( $is_no_show ) {
                                    $division_disp = 'No Show';
                                    $gender_disp   = 'No Show';
                                    $style_disp    = 'No Show';
                                    $print_score   = '';
                                    $print_x       = '';
                                } elseif ( $is_checked_in ) {
                                    $division_disp = ! empty( $reg['division'] ) ? esc_html( $reg['division'] ) : '';
                                    $gender_disp   = ! empty( $reg['gender'] ) ? esc_html( $reg['gender'] ) : '';
                                    $style_disp    = ! empty( $reg['style'] ) ? esc_html( $reg['style'] ) : '';
                                    $print_score   = ( isset( $reg['score'] ) && $reg['score'] !== '' ) ? esc_html( $reg['score'] ) : '';
                                    $print_x       = ( isset( $reg['x_count'] ) && $reg['x_count'] !== '' ) ? esc_html( $reg['x_count'] ) : '';
                                } else {
                                    $division_disp = 'A | S | SS | MS<br>C | Y | YA | PRO';
                                    $gender_disp   = 'M | F';
                                    $style_disp    = 'FS | FSL | BB | FLS-R | BHFS | MBH | TRAD | BBR | BBC | LB | GENESIS';
                                    $print_score   = '';
                                    $print_x       = '';
                                }

                                $due_amt = isset($reg['balance_due']) ? floatval($reg['balance_due']) : 0;
                                if ($due_amt > 0) {
                                    $paid_cat = 'DUE: $' . number_format($due_amt, 2);
                                } else {
                                    $paid_cat = ! empty( $reg['paid'] ) ? $reg['paid'] : 'Youth / Adult / Fam';
                                    if ( $paid_cat === 'Family' ) {
                                        $fam_id_str = ! empty( $reg['family_id'] ) ? $reg['family_id'] : ( ! empty( $reg['booking_id'] ) ? $reg['booking_id'] : '' );
                                        if ( $fam_id_str ) $paid_cat .= ' <span style="font-size:8px;">(' . esc_html( $fam_id_str ) . ')</span>';
                                    }
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo $num++; ?></strong></td>
                                <td><strong><?php echo esc_html( $reg['name'] ); ?></strong></td>
                                <td style="font-size: 9px;"><?php echo esc_html( isset( $reg['line_name'] ) ? $reg['line_name'] : '' ); ?></td>
                                <td style="font-size: 8px; line-height: 1.1;"><?php echo $division_disp; ?></td>
                                <td style="font-size: 9px;"><?php echo $gender_disp; ?></td>
                                <td style="font-size: 8px; line-height: 1.1;"><?php echo $style_disp; ?></td>
                                <td class="paid-col" style="<?php echo ($due_amt > 0) ? 'color:#dc3545;' : ''; ?>"><strong><?php echo $paid_cat; ?></strong></td>
                                <td style="border-bottom: 1px solid #1d2327; text-align: center; font-weight: bold;"><?php echo $print_score; ?></td>
                                <td style="border-bottom: 1px solid #1d2327; text-align: center; font-weight: bold;"><?php echo $print_x; ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ( $is_specific_line && $num <= 100 ) {
                            while ( $num <= 100 ) { ?>
                                <tr>
                                    <td><strong><?php echo $num++; ?></strong></td>
                                    <td></td>
                                    <td style="font-size: 9px;"><?php echo esc_html($available_lines[$filter_line_idx]['name']); ?></td>
                                    <td style="font-size: 8px; line-height: 1.1;">A | S | SS | MS<br>C | Y | YA | PRO</td>
                                    <td style="font-size: 9px;">M | F</td>
                                    <td style="font-size: 8px; line-height: 1.1;">FS | FSL | BB | FLS-R | BHFS | MBH | TRAD | BBR | BBC | LB | GENESIS</td>
                                    <td class="paid-col"><strong>YOUTH | ADULT | FAMILY</strong></td>
                                    <td style="border-bottom: 1px solid #1d2327; text-align: center;"></td>
                                    <td style="border-bottom: 1px solid #1d2327; text-align: center;"></td>
                                </tr>
                            <?php }
                        } ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
