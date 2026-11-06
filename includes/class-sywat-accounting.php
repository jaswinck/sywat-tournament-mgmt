<?php
/**
 * Module: Financial Accounting & Invoice Engine
 * Path: includes/class-sywat-accounting.php
 * Description: Host vs. TFAA 50/50 fee split math, family cap calculations, Zelle QR code rendering (Base64 embedded from plugin assets), dynamic shop header metadata, and printable invoice/roster UI.
 * Author: SYWAT Archery
 * Version: 6.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Helper: Embed Zelle QR Code from plugin assets/images/zelle-qr-code.png as Base64 for print/PDF reliability
if ( ! function_exists( 'sywat_get_zelle_qr_base64' ) ) {
    function sywat_get_zelle_qr_base64() {
        // 1. Check local plugin asset path: assets/images/zelle-qr-code.png
        $plugin_img_path = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/images/zelle-qr-code.png';
        if ( ! file_exists( $plugin_img_path ) ) {
            $plugin_img_path = plugin_dir_path( __FILE__ ) . 'assets/images/zelle-qr-code.png';
        }

        if ( file_exists( $plugin_img_path ) ) {
            $type = pathinfo( $plugin_img_path, PATHINFO_EXTENSION );
            $data = file_get_contents( $plugin_img_path );
            return 'data:image/' . $type . ';base64,' . base64_encode( $data );
        }

        // 2. Fallback: Check if custom option or Attachment ID is set in WordPress settings
        $raw_qr = get_option( 'sywat_zelle_qr_url', '' );

        if ( is_numeric( $raw_qr ) ) {
            $file_path = get_attached_file( intval( $raw_qr ) );
            if ( $file_path && file_exists( $file_path ) ) {
                $type = pathinfo( $file_path, PATHINFO_EXTENSION );
                $data = file_get_contents( $file_path );
                return 'data:image/' . $type . ';base64,' . base64_encode( $data );
            }
            $raw_qr = wp_get_attachment_url( intval( $raw_qr ) );
        }

        if ( filter_var( $raw_qr, FILTER_VALIDATE_URL ) ) {
            $response = wp_remote_get( $raw_qr, array( 'timeout' => 5 ) );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $image_data   = wp_remote_retrieve_body( $response );
                $content_type = wp_remote_retrieve_header( $response, 'content-type' );
                if ( empty( $content_type ) ) $content_type = 'image/png';
                return 'data:' . $content_type . ';base64,' . base64_encode( $image_data );
            }
        }

        return '';
    }
}

/**
 * Calculates the exact balance and family cap status for a group of archers sharing a family_id on a line.
 * 
 * Rules:
 * - Adult / Senior / SS / MS = $30.00 standard rate
 * - Cub / Youth / Young Adult = $25.00 standard rate
 * - 1 or 2 Archers: Billed at standard individual rates (Sum < $75.00). Not eligible for $75 family cap.
 * - 3+ Archers: Billed at capped $75.00 family rate (Sum >= $75.00).
 * 
 * @param string     $family_id        The unique family identifier.
 * @param string|int $line_idx         The line index or line name context ('all' for entire event).
 * @param array      $all_registrations Master list of registrations.
 * @return array Calculated billing breakdown.
 */
if ( ! function_exists( 'sywat_calculate_family_group_balance' ) ) {
    function sywat_calculate_family_group_balance( $family_id, $line_idx, $all_registrations ) {
        $youth_divs = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' );
        
        if ( empty( $family_id ) ) {
            return array(
                'is_family_capped' => false,
                'total_fee'        => 0.00,
                'member_count'     => 0,
                'raw_sum'          => 0.00,
                'members'          => array()
            );
        }

        $family_members = array();
        $raw_sum        = 0.00;

        foreach ( $all_registrations as $reg ) {
            $reg_fam_id = ! empty( $reg['family_id'] ) ? $reg['family_id'] : ( ! empty( $reg['booking_id'] ) ? $reg['booking_id'] : '' );
            
            // Fallback family identifier matching if family_id field is empty
            if ( empty( $reg_fam_id ) && isset( $reg['paid'] ) && $reg['paid'] === 'Family' ) {
                $name_parts = array_values( array_filter( explode( ' ', trim( $reg['name'] ) ) ) );
                $last_name  = ! empty( $name_parts ) ? strtolower( end( $name_parts ) ) : 'fam';
                $reg_l_idx  = isset( $reg['line_index'] ) ? (string)$reg['line_index'] : '0';
                $reg_fam_id = 'line_' . $reg_l_idx . '_last_' . $last_name;
            }

            if ( $reg_fam_id === $family_id ) {
                // Line match check when a specific line context is provided
                if ( $line_idx !== 'all' && isset( $reg['line_index'] ) && (string)$reg['line_index'] !== (string)$line_idx ) {
                    continue;
                }

                $div        = isset( $reg['division'] ) ? $reg['division'] : '';
                $is_youth   = ( in_array( $div, $youth_divs ) || ( isset( $reg['rate_type'] ) && strtolower( $reg['rate_type'] ) === 'youth' ) );
                $indiv_rate = $is_youth ? 25.00 : 30.00;

                $raw_sum += $indiv_rate;
                $family_members[] = array(
                    'reg'        => $reg,
                    'indiv_rate' => $indiv_rate,
                    'is_youth'   => $is_youth
                );
            }
        }

        $count            = count( $family_members );
        $is_family_capped = ( $count >= 3 && $raw_sum >= 75.00 );
        $final_total      = $is_family_capped ? 75.00 : $raw_sum;

        return array(
            'is_family_capped' => $is_family_capped,
            'total_fee'        => $final_total,
            'member_count'     => $count,
            'raw_sum'          => $raw_sum,
            'members'          => $family_members
        );
    }
}

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
                
                if (!isset($family_groups[$fam_key])) {
                    $family_groups[$fam_key] = array();
                }
                $family_groups[$fam_key][] = $r;
            } else {
                if (in_array($div, $youth_divs) || $paid_cat === 'Youth') {
                    $g1_youth++;
                } else {
                    $g2_adult++;
                }
            }
        }

        // Process Family Groups to enforce 3+ Archers / $75+ Cap Threshold
        $unique_capped_families = 0;
        foreach ($family_groups as $fam_key => $members) {
            $group_raw_sum = 0;
            foreach ($members as $m) {
                $m_div = isset($m['division']) ? $m['division'] : '';
                $group_raw_sum += (in_array($m_div, $youth_divs) || (isset($m['rate_type']) && strtolower($m['rate_type']) === 'youth')) ? 25 : 30;
            }

            if (count($members) >= 3 && $group_raw_sum >= 75) {
                $unique_capped_families++;
            } else {
                // Solo or 2-shooter entries fallback to individual Youth/Adult billing buckets
                foreach ($members as $m) {
                    $m_div = isset($m['division']) ? $m['division'] : '';
                    if (in_array($m_div, $youth_divs) || (isset($m['rate_type']) && strtolower($m['rate_type']) === 'youth')) {
                        $g1_youth++;
                    } else {
                        $g2_adult++;
                    }
                }
            }
        }

        $unique_families    = $unique_capped_families;
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

        // Fetch Shop Info dynamically
        $tournaments = get_option( 'sywat_tournaments_list', array() );
        $all_shops   = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
        $shop_id     = 'shop_ccr';

        if ( isset( $tournaments[$selected_tournament] ) ) {
            $t_data  = $tournaments[$selected_tournament];
            $shop_id = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
        }

        $shop_info  = isset( $all_shops[$shop_id] ) ? $all_shops[$shop_id] : array();
        $shop_name  = ! empty( $shop_info['name'] ) ? $shop_info['name'] : $selected_tournament;
        $shop_cont  = ! empty( $shop_info['contact'] ) ? $shop_info['contact'] : ( ! empty( $shop_info['contact_name'] ) ? $shop_info['contact_name'] : '—' );
        $shop_email = ! empty( $shop_info['email'] ) ? $shop_info['email'] : '—';
        $shop_phone = ! empty( $shop_info['phone'] ) ? $shop_info['phone'] : '—';

        $zelle_qr_src = sywat_get_zelle_qr_base64();
        ?>

        <div class="no-print" style="margin-bottom: 15px; display: flex; justify-content: flex-end;">
            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-print" onclick="window.print()">Print / Save Invoice PDF</button>
        </div>

        <div id="sywat_print_area">
            <div class="invoice-box">
                <div class="invoice-title">TFAA TOURNAMENT INVOICE</div>
                <div class="invoice-subtitle">(Fill in all the Blue Shade Boxes)</div>

                <div class="sywat-table-responsive" style="margin-bottom: 15px;">
                    <table class="invoice-header-table" style="margin-bottom: 0;">
                        <tr><td class="invoice-header-label">SYWAT Tournament Date:</td><td class="invoice-header-val"><?php echo esc_html($tourn_dates_str); ?></td></tr>
                        <tr><td class="invoice-header-label">Archery Shop/Club Name:</td><td class="invoice-header-val"><?php echo esc_html($shop_name); ?></td></tr>
                        <tr><td class="invoice-header-label">Contact Name:</td><td class="invoice-header-val"><?php echo esc_html($shop_cont); ?></td></tr>
                        <tr><td class="invoice-header-label">Email Address:</td><td class="invoice-header-val"><?php echo esc_html($shop_email); ?></td></tr>
                        <tr><td class="invoice-header-label">Phone:</td><td class="invoice-header-val"><?php echo esc_html($shop_phone); ?></td></tr>
                    </table>
                </div>

                <div style="font-weight:bold; font-size:13px; margin-bottom:6px;">Registration Tournament Fees:</div>

                <div class="sywat-table-responsive" style="margin-bottom: 15px;">
                    <table class="invoice-fee-table" style="margin-bottom: 0;">
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
                </div>

                <div class="invoice-footer">
                    <p>Please remit TFAA Payment via Check or Zelle and a copy of this Invoice within 7 days to:</p>
                    <p class="invoice-footer-bold">Texas Field Archery Association<br>C/O Anna Hanson<br>722 Cannon Ball Dr.<br>Spring TX 77380<br>Email: anna.hanson@resolute-tx.com</p>
                    <p style="margin-top: 8px;">Send Money with Zelle®, Tag: <strong style="color:#1e3a8a;">tfaachecking</strong></p>
                    
                    <div style="margin-top: 10px; text-align: center;">
                        <?php if ( ! empty( $zelle_qr_src ) ) : ?>
                            <img src="<?php echo $zelle_qr_src; ?>" alt="Zelle QR Code" style="width: 130px; height: auto; display: inline-block; margin: 0 auto; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
                        <?php endif; ?>
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
            <form method="get" action="" style="display: flex; gap: 12px; align-items: center; margin: 0; flex-wrap: wrap;">
                <input type="hidden" name="tab" value="manage">
                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="modal_action" value="print">
                <div class="sywat-field-group" style="min-width: 220px; width: auto;">
                    <label for="filter_line_idx_print">Filter Roster by Line</label>
                    <select name="filter_line_idx" id="filter_line_idx_print" onchange="this.form.submit()">
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
                <div class="sywat-table-responsive">
                    <table class="sywat-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="width: 3%;">#</th>
                                <th style="width: 20%;">Name</th>
                                <th style="width: 12%;">Line</th>
                                <th style="width: 15%;">Division</th>
                                <th style="width: 6%;">Gender</th>
                                <th style="width: 18%;">Style</th>
                                <th style="width: 14%;">Paid</th>
                                <th style="width: 6%; text-align: center;">Score</th>
                                <th style="width: 6%; text-align: center;">X Count</th>
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
                                        $paid_cat = '<span style="color:#dc3545;">DUE: $' . number_format($due_amt, 2) . '</span>';
                                    } else {
                                        $paid_cat = ! empty( $reg['paid'] ) ? esc_html($reg['paid']) : 'Youth / Adult / Fam';
                                        if ( $reg['paid'] === 'Family' ) {
                                            $fam_id_str = ! empty( $reg['family_id'] ) ? $reg['family_id'] : ( ! empty( $reg['booking_id'] ) ? $reg['booking_id'] : '' );
                                            if ( $fam_id_str ) {
                                                $paid_cat .= '<br><span style="font-size:7px; color:#646970; word-break:break-all; font-weight:normal;">(' . esc_html( $fam_id_str ) . ')</span>';
                                            }
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo $num++; ?></strong></td>
                                    <td><strong><?php echo esc_html( $reg['name'] ); ?></strong></td>
                                    <td style="font-size: 9px;"><?php echo esc_html( isset( $reg['line_name'] ) ? $reg['line_name'] : '' ); ?></td>
                                    <td style="font-size: 9px; line-height: 1.1; white-space: nowrap;"><?php echo $division_disp; ?></td>
                                    <td style="font-size: 9px;"><?php echo $gender_disp; ?></td>
                                    <td style="font-size: 9px; line-height: 1.1;"><?php echo $style_disp; ?></td>
                                    <td class="paid-col" style="font-size: 9px; line-height: 1.1;"><strong><?php echo $paid_cat; ?></strong></td>
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
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
