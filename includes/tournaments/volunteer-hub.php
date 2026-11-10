<?php
/**
 * Sub-Module: Volunteer Hub Portal View Renderer
 * Path: includes/tournaments/volunteer-hub.php
 * Author: SYWAT Archery
 * Version: 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'render_sywat_volunteer_hub' ) ) {
    function render_sywat_volunteer_hub() {
        $user = wp_get_current_user();
        $allowed_roles = array( 'administrator', 'management', 'staff_shooter', 'non-shooting-member', 'tfaa_webmaster', 'tfaa_admin', 'sywat_shop_manager', 'sywat_shop_user' );
        if ( is_user_logged_in() && ! array_intersect( $allowed_roles, (array) $user->roles ) ) {
            return '<div class="sywat-alert sywat-alert-danger">Access denied. Staff access required.</div>';
        }

        ob_start();

        // Fetch central data bundle from Data Access Engine (No direct DB queries in View layer)
        $data = function_exists( 'sywat_get_master_portal_data' ) ? sywat_get_master_portal_data() : array();

        $tournaments        = isset( $data['tournaments'] ) ? $data['tournaments'] : array();
        $active_tournaments = isset( $data['active_tournaments'] ) ? $data['active_tournaments'] : array();
        $user_shop_id       = isset( $data['user_shop_id'] ) ? $data['user_shop_id'] : 'shop_ccr';
        $can_manage_seasons = ! empty( $data['can_manage_seasons'] );
        $registrations      = isset( $data['registrations'] ) ? $data['registrations'] : array();

        $display_tournaments = array();
        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : 'shop_ccr';
            if ( in_array( $t_name, $active_tournaments ) && ( $can_manage_seasons || $t_shop === $user_shop_id ) ) {
                $display_tournaments[$t_name] = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : array();
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
        <div class="sywat-wrap">
            <?php if ( empty( $display_tournaments ) ): ?>
                <div class="sywat-card">
                    <p class="sywat-empty-text">No active tournaments currently enabled for the Volunteer Hub.</p>
                </div>
            <?php else: ?>
                <?php foreach ( $display_tournaments as $t_name => $t_lines ): ?>
                    <div class="sywat-card">
                        <div class="sywat-card-header-bar">
                            <div>
                                <h2>🎯 <?php echo esc_html( $t_name ); ?></h2>
                            </div>
                            <div>
                                <span class="sywat-badge sywat-badge-prereg"><?php echo is_array($t_lines) ? count($t_lines) : 0; ?> Lines</span>
                            </div>
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
                                <div class="sywat-day-group-header">
                                    📅 <?php echo esc_html( $day_label ); ?> Lines
                                </div>
                                <div class="sywat-tracker-grid">
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
                                        <div class="sywat-tracker-card">
                                            <div>
                                                <div class="sywat-tracker-card-header">
                                                    <strong class="sywat-tracker-card-title"><?php echo esc_html( $line_title ); ?></strong>
                                                    <span class="sywat-badge sywat-badge-open"><?php echo $c_in . ' / ' . $tot_reg . ' Checked In'; ?></span>
                                                </div>
                                                <div class="sywat-tracker-card-stats">
                                                    <div><strong>20yd:</strong> <strong><?php echo $c_20 . '/' . $m_20; ?></strong> <span class="<?php echo ($rem_20 > 0) ? 'sywat-text-open' : 'sywat-text-closed'; ?>">(<?php echo $rem_20; ?> open)</span></div>
                                                    <div><strong>Cub:</strong> <strong><?php echo $c_cub . '/' . $m_cub; ?></strong> <span class="<?php echo ($rem_cub > 0) ? 'sywat-text-open' : 'sywat-text-closed'; ?>">(<?php echo $rem_cub; ?> open)</span></div>
                                                </div>
                                            </div>
                                            <div class="sywat-tracker-card-actions">
                                                <a href="<?php echo esc_url($checkin_url); ?>" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin sywat-btn-flex-1">Check-In Desk</a>
                                                <a href="<?php echo esc_url($scores_url); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit sywat-btn-flex-1">Enter Scores</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="sywat-empty-text">No shooting lines configured for this tournament.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
