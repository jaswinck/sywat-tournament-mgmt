<?php
/**
 * Sub-Module: Volunteer Hub Portal View Renderer
 * Path: includes/tournaments/volunteer-hub.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'render_sywat_volunteer_hub' ) ) {
    function render_sywat_volunteer_hub() {
        $user = wp_get_current_user();
        $allowed_roles = array( 'administrator', 'management', 'staff_shooter', 'non-shooting-member', 'tfaa_webmaster', 'tfaa_admin', 'sywat_shop_manager', 'sywat_shop_user' );
        if ( is_user_logged_in() && ! array_intersect( $allowed_roles, (array) $user->roles ) ) {
            return '<p style="color:red; padding:20px;">Access denied. Staff access required.</p>';
        }

        ob_start();
        global $wpdb;

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        $active_tournaments = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_tournaments ) ) $active_tournaments = array();

        $user_shop_id       = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';
        $can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();

        $display_tournaments = array();
        foreach ($tournaments as $t_name => $t_data) {
            $t_shop = sywat_get_tournament_shop_id( $t_data );
            if ( in_array( $t_name, $active_tournaments ) && ( $can_manage_seasons || $t_shop === $user_shop_id ) ) {
                $display_tournaments[$t_name] = sywat_get_tournament_lines( $t_data );
            }
        }

        $registrations = array();
        $results = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%'" );
        foreach ($results as $row) {
            $reg_data = maybe_unserialize($row->option_value);
            if (is_array($reg_data) && isset($reg_data['id'])) {
                $registrations[] = $reg_data;
            }
        }

        $count_checked_in = function( $t_name, $l_idx ) use ( &$registrations ) {
            $count = 0;
            foreach ( $registrations as $reg ) {
                if ( isset( $reg['tournament'], $reg['line_index'] )
                    && strcasecmp( $reg['tournament'], $t_name ) === 0
                    && (string)$reg['line_index'] === (string)$l_idx
                    && ! empty( $reg['checked_in'] ) ) {
                    $count++;
                }
            }
            return $count;
        };
        ?>
        <style>
            .sywat-vhub-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 1000px; margin: 0 auto; color: #2c3338; }
            .sywat-vhub-card { background: #ffffff; border: 1px solid #c3c4c7; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 20px; }
            .sywat-vhub-title { font-size: 1.3em; font-weight: 700; color: #1d2327; margin: 0 0 15px 0; border-bottom: 2px solid #2271b1; padding-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
            .sywat-vhub-day-title { font-size: 1.05em; font-weight: 700; color: #1e293b; margin: 15px 0 8px 0; border-bottom: 1px dashed #cbd5e1; padding-bottom: 4px; display: flex; align-items: center; gap: 6px; }
            .sywat-vhub-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 10px; margin-bottom: 15px; }
            @media (max-width: 650px) { .sywat-vhub-grid { grid-template-columns: 1fr; } }
            .sywat-vhub-line-box { background: #f8f9fa; border: 1px solid #dcdcde; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 12px; }
            .sywat-vhub-line-name { font-size: 1.1em; font-weight: 700; color: #1d2327; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
            .sywat-vhub-stat { font-size: 15px; color: #334155; line-height: 1.6; }
            .sywat-vhub-stat-num { font-size: 16px; font-weight: 700; color: #0f172a; }
            .sywat-vhub-actions { display: flex; gap: 10px; margin-top: 10px; }
            .sywat-vhub-btn-checkin { flex: 1; background: #008a20; color: #ffffff !important; border: 1px solid #008a20; text-align: center; padding: 11px 10px; border-radius: 5px; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; }
            .sywat-vhub-btn-scores { flex: 1; background: #2271b1; color: #ffffff !important; border: 1px solid #2271b1; text-align: center; padding: 11px 10px; border-radius: 5px; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; }
            .sywat-vhub-badge { font-size: 13px; padding: 4px 8px; border-radius: 4px; font-weight: 700; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        </style>

        <div class="sywat-vhub-wrap">
            <?php if ( empty( $display_tournaments ) ): ?>
                <div class="sywat-vhub-card"><p style="color:#646970; font-style:italic; margin:0;">No active tournaments currently enabled for the Volunteer Hub.</p></div>
            <?php else: ?>
                <?php foreach ( $display_tournaments as $t_name => $t_lines ): ?>
                    <div class="sywat-vhub-card">
                        <div class="sywat-vhub-title">
                            <span>🎯 <?php echo esc_html( $t_name ); ?></span>
                            <span style="font-size: 12px; padding: 3px 8px; border-radius: 4px; font-weight: 700; background: #e2e8f0; color: #334155;"><?php echo is_array($t_lines) ? count($t_lines) : 0; ?> Lines</span>
                        </div>

                        <?php if ( ! empty( $t_lines ) && is_array( $t_lines ) ): ?>
                            <?php
                                $day_groups = array();
                                foreach ( $t_lines as $idx => $line ) {
                                    $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ($idx + 1);
                                    $day_key = 'Other Lines';
                                    if ( stripos( $line_title, 'Saturday' ) !== false ) $day_key = 'Saturday';
                                    elseif ( stripos( $line_title, 'Sunday' ) !== false ) $day_key = 'Sunday';
                                    elseif ( stripos( $line_title, 'Friday' ) !== false ) $day_key = 'Friday';

                                    $day_groups[$day_key][] = array( 'idx' => $idx, 'line' => $line );
                                }
                            ?>

                            <?php foreach ( $day_groups as $day_label => $group_item_list ): ?>
                                <div class="sywat-vhub-day-title">📅 <?php echo esc_html( $day_label ); ?> Lines</div>
                                <div class="sywat-vhub-grid">
                                    <?php foreach ( $group_item_list as $item ): ?>
                                        <?php 
                                            $idx        = $item['idx'];
                                            $line       = $item['line'];
                                            $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ($idx + 1);

                                            $c_20       = sywat_count_registered( $t_name, $idx, '20yd', $registrations );
                                            $m_20       = sywat_get_line_max_cap( $line, '20yd' );
                                            $rem_20     = max( 0, $m_20 - $c_20 );

                                            $c_cub      = sywat_count_registered( $t_name, $idx, 'Cub', $registrations );
                                            $m_cub      = sywat_get_line_max_cap( $line, 'Cub' );
                                            $rem_cub    = max( 0, $m_cub - $c_cub );

                                            $tot_reg    = $c_20 + $c_cub;
                                            $c_in       = $count_checked_in( $t_name, $idx );

                                            $checkin_url = '/sywat-desk/?selected_tournament=' . urlencode($t_name) . '&selected_line_idx=' . urlencode($idx);
                                            $scores_url  = '/sywat-enter-scores/?selected_tournament=' . urlencode($t_name) . '&selected_line_idx=' . urlencode($idx);
                                        ?>
                                        <div class="sywat-vhub-line-box">
                                            <div>
                                                <div class="sywat-vhub-line-name">
                                                    <span><?php echo esc_html( $line_title ); ?></span>
                                                    <span class="sywat-vhub-badge"><?php echo $c_in . ' / ' . $tot_reg . ' Checked In'; ?></span>
                                                </div>
                                                <div class="sywat-vhub-stat">
                                                    <strong>20yd:</strong> <span class="sywat-vhub-stat-num"><?php echo $c_20 . '/' . $m_20; ?></span> <span style="color:<?php echo ($rem_20 > 0) ? '#008a20' : '#d63638'; ?>; font-weight:600;">(<?php echo $rem_20; ?> open)</span><br>
                                                    <strong>Cub:</strong> <span class="sywat-vhub-stat-num"><?php echo $c_cub . '/' . $m_cub; ?></span> <span style="color:<?php echo ($rem_cub > 0) ? '#008a20' : '#d63638'; ?>; font-weight:600;">(<?php echo $rem_cub; ?> open)</span>
                                                </div>
                                            </div>
                                            <div class="sywat-vhub-actions">
                                                <a href="<?php echo esc_url($checkin_url); ?>" class="sywat-vhub-btn-checkin sywat-btn-icon-checkin">Check-In Desk</a>
                                                <a href="<?php echo esc_url($scores_url); ?>" class="sywat-vhub-btn-scores sywat-btn-icon-edit">Enter Scores</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color:#646970; font-style:italic; margin:0;">No shooting lines configured for this tournament.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
