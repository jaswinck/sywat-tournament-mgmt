<?php
/**
 * Sub-Module: Scores Engine Visual Views & Shortcode Renderer
 * File: includes/scores/scores-views.php
 * Version: 3.6.1
 */

if (!defined('ABSPATH')) exit;

function render_sywat_scores_app() {
    $user = wp_get_current_user();
    if ( ! is_user_logged_in() || ! array_intersect(array('administrator', 'management', 'non-shooting-member', 'staff_shooter', 'sywat_shop_manager', 'sywat_shop_user'), (array) $user->roles) ) {
        return '<p style="color:red; padding:20px;">Access denied. Staff access required.</p>';
    }

    $is_admin           = is_user_logged_in() && in_array('administrator', (array) $user->roles);
    $can_manage_seasons = function_exists('sywat_can_manage_seasons') && sywat_can_manage_seasons();
    $user_shop_id       = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';

    // Handle AJAX Autosave Early
    if (function_exists('sywat_scores_handle_ajax_autosave')) {
        sywat_scores_handle_ajax_autosave();
    }

    ob_start();
    global $wpdb;

    // Fetch Tournaments List with Shop Scoping
    $all_tournaments = get_option('sywat_tournaments_list', array());
    if (empty($all_tournaments) || !is_array($all_tournaments)) {
        $single_name = get_option('sywat_current_tournament', 'CCR SYWAT Qualifier');
        $all_tournaments = array($single_name => get_option('sywat_line_config', array()));
    }

    $tournaments = array();
    foreach ($all_tournaments as $t_name => $t_data) {
        $t_shop = function_exists('sywat_get_tournament_shop_id') ? sywat_get_tournament_shop_id($t_data) : 'shop_ccr';
        if ($is_admin || $t_shop === $user_shop_id) {
            $tournaments[$t_name] = function_exists('sywat_get_tournament_lines') ? sywat_get_tournament_lines($t_data) : $t_data;
        }
    }

    $selected_tournament = isset($_POST['selected_tournament']) ? sanitize_text_field($_POST['selected_tournament']) : (isset($_GET['selected_tournament']) ? sanitize_text_field($_GET['selected_tournament']) : '');
    $selected_line_idx   = isset($_POST['selected_line_idx'])   ? sanitize_text_field($_POST['selected_line_idx'])   : (isset($_GET['selected_line_idx']) ? sanitize_text_field($_GET['selected_line_idx']) : 'all');
    $search_query        = isset($_POST['search_query'])        ? sanitize_text_field($_POST['search_query'])        : '';

    if (empty($selected_tournament) && !empty($tournaments)) {
        reset($tournaments);
        $selected_tournament = key($tournaments);
    }

    $lines         = isset($tournaments[$selected_tournament]) ? $tournaments[$selected_tournament] : array();
    $active_t_slug = sanitize_title($selected_tournament);
    
    // Fetch Active Locks
    $locked_lines        = get_option('sywat_locks_' . $active_t_slug, array());
    $current_view_locked = ($selected_line_idx !== 'all' && !empty($locked_lines[$selected_line_idx]));

    // Batch Action Handlers
    $notice = '';
    if (function_exists('sywat_scores_handle_batch_actions')) {
        $notice = sywat_scores_handle_batch_actions($selected_tournament, $selected_line_idx, $is_admin, $locked_lines, $current_view_locked);
    }

    // Retrieve Archers for Active View Table
    $registrations = array();
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('sywat_t_' . $active_t_slug . '_') . '%'
    ) );

    foreach ($results as $row) {
        $reg_data = maybe_unserialize($row->option_value);
        if (is_array($reg_data) && isset($reg_data['id'])) {
            $registrations[] = $reg_data;
        }
    }

    $tourn_registrations = array();
    foreach ($registrations as $reg) {
        $match_tourn   = isset($reg['tournament']) && $reg['tournament'] === $selected_tournament;
        $match_line    = ($selected_line_idx === 'all') || (isset($reg['line_index']) && (string)$reg['line_index'] === (string)$selected_line_idx);
        $is_checked_in = !empty($reg['checked_in']);

        if ($match_tourn && $match_line && $is_checked_in) {
            $tourn_registrations[] = $reg;
        }
    }

    usort($tourn_registrations, function($a, $b) {
        $line_a = isset($a['line_index']) ? (int)$a['line_index'] : 99;
        $line_b = isset($b['line_index']) ? (int)$b['line_index'] : 99;
        if ($line_a !== $line_b) return $line_a <=> $line_b;
        $name_a = isset($a['name']) ? trim($a['name']) : '';
        $name_b = isset($b['name']) ? trim($b['name']) : '';
        return strcasecmp($name_a, $name_b);
    });

    // Check host shop & active Volunteer Hub status for dynamic return button
    $t_master  = (is_array($all_tournaments) && isset($all_tournaments[$selected_tournament])) ? $all_tournaments[$selected_tournament] : array();
    $t_shop_id = 'shop_ccr';
    if (is_array($t_master) && isset($t_master['shop_id']) && !empty($t_master['shop_id'])) {
        $t_shop_id = $t_master['shop_id'];
    } elseif (function_exists('sywat_get_tournament_shop_id')) {
        $t_shop_id = sywat_get_tournament_shop_id($t_master);
    }

    $active_hubs    = get_option('sywat_active_tournaments', array());
    if (!is_array($active_hubs)) $active_hubs = array();
    $is_hub_enabled = ($t_shop_id === 'shop_ccr') && in_array($selected_tournament, $active_hubs);
    ?>

    <div class="sywat-wrap">
        <div class="no-print"><?php echo $notice; ?></div>

        <!-- Master Navigation Tabs (Card-Docked) -->
        <div class="sywat-nav-tabs no-print">
            <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">Manage Tournaments</a>
            <a href="<?php echo esc_url( site_url( '/sywat-register/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-register">Register Shooters</a>
            <a href="<?php echo esc_url( site_url( '/sywat-desk/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-checkin">Check In Archers</a>
            <a href="<?php echo esc_url( site_url( '/sywat-enter-scores/' ) ); ?>" class="sywat-tab-item active sywat-tab-icon-edit">Enter Scores</a>
            <?php if ( $can_manage_seasons ): ?>
                <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/?tab=seasons' ) ); ?>" class="sywat-tab-item sywat-tab-icon-season">Manage Seasons</a>
            <?php endif; ?>
        </div>

        <!-- Integrated Control Panel Filter Toolbar -->
        <div class="sywat-card no-print" style="margin-bottom:20px; border-top-left-radius:0;">
            <div class="sywat-filter-toolbar">
                
                <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
                    <h3 class="sywat-filter-title">
                        🎯 Active Roster Filter
                    </h3>

                    <form method="post" id="sywat_score_filter_form" class="sywat-filter-form">
                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                        
                        <div class="sywat-filter-field">
                            <label>Tournament</label>
                            <select name="selected_tournament" id="selected_tournament_val" onchange="document.getElementById('sywat_score_filter_form').submit();">
                                <?php foreach ($tournaments as $t_name => $t_lines): ?>
                                    <option value="<?php echo esc_attr($t_name); ?>" <?php selected($selected_tournament, $t_name); ?>>
                                        <?php echo esc_html($t_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-filter-field">
                            <label>Shooting Line</label>
                            <select name="selected_line_idx" id="selected_line_val" onchange="document.getElementById('sywat_score_filter_form').submit();">
                                <option value="all" <?php selected($selected_line_idx, 'all'); ?>>All Lines (Entire Weekend)</option>
                                <?php foreach ($lines as $idx => $line): ?>
                                    <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$selected_line_idx, (string)$idx); ?>>
                                        <?php echo esc_html($line['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>

                <?php if ($is_hub_enabled): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-volunteer-hub/' ) ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back">Back to Hub</a>
                <?php endif; ?>

            </div>
        </div>

        <!-- Main Scoring Table Card -->
        <div class="sywat-card no-print">
            <form method="post" id="sywat_batch_score_form">
                <?php wp_nonce_field('sywat_scores_action', 'sywat_nonce'); ?>
                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">

                <div class="sywat-card-title">
                    <div>
                        <span style="font-size:18px;">Batch Score Entry</span>
                        <div style="font-size:13px; color:#64748b; font-weight:normal; margin-top:2px;">
                            Displaying <strong><?php echo count($tourn_registrations); ?></strong> checked-in archer(s) for <?php echo esc_html($selected_tournament); ?>
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <!-- Live Search Input -->
                        <div style="display:flex; gap:5px; align-items:center; margin-right:5px; flex-wrap:wrap;">
                            <input type="text" id="sywat_score_search_input" value="<?php echo esc_attr($search_query); ?>" placeholder="Search archer name..." autocomplete="off" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; width:180px; height:32px; box-sizing:border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height:32px;" onclick="document.getElementById('sywat_score_search_input').value=''; document.getElementById('sywat_score_search_input').dispatchEvent(new Event('input'));">Clear</button>
                        </div>

                        <!-- Export CSV Button (Admin Only & Shown Whenever Locked Lines Exist) -->
                        <?php if ($is_admin && !empty($locked_lines)): ?>
                            <button type="submit" name="sywat_action" value="export_csv" class="sywat-btn sywat-btn-primary sywat-btn-icon-export" style="height:32px;" title="Export scores for ALL locked lines to a CSV file">Export CSV</button>
                        <?php endif; ?>

                        <?php if ($is_admin): ?>
                            <?php if ($selected_line_idx !== 'all'): ?>
                                <?php if ($current_view_locked): ?>
                                    <button type="submit" name="sywat_action" value="toggle_lock" class="sywat-btn sywat-btn-secondary sywat-btn-icon-unlock" style="height:32px;" title="Unlock to edit scores">Unlock Line</button>
                                <?php else: ?>
                                    <button type="submit" name="sywat_action" value="toggle_lock" class="sywat-btn sywat-btn-secondary sywat-btn-icon-lock" style="height:32px;" title="Lock to prevent edits">Lock Line</button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <button type="submit" name="sywat_action" value="reset_scores" class="sywat-btn sywat-btn-danger sywat-btn-icon-reset" style="height:32px;" onclick="return confirm('⚠️ Are you sure you want to RESET ALL SCORES to 0 for the selected line/tournament?\n\nThis will set score and X count to 0 for all unlocked, checked-in archers in this view.');">Reset All Scores to 0</button>
                        <?php endif; ?>
                        
                        <button type="submit" name="sywat_action" value="save_scores" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px;">Save All Scores</button>
                    </div>
                </div>

                <?php if (!empty($tourn_registrations)): ?>
                    <div class="sywat-table-responsive">
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th style="width: 4%;">#</th>
                                    <th style="width: 20%; text-align: left;">Archer Name</th>
                                    <th style="width: 15%; text-align: left;">Shooting Line</th>
                                    <th style="width: 8%;">Gender</th>
                                    <th style="width: 10%; text-align: left;">Division</th>
                                    <th style="width: 12%; text-align: left;">Style</th>
                                    <th style="width: 11%; text-align: center;">Score (Max 300)</th>
                                    <th style="width: 11%; text-align: center;">X Count (Max 60)</th>
                                    <th style="width: 9%; text-align: center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $num = 1; foreach ($tourn_registrations as $reg): ?>
                                    <?php 
                                        $reg_id    = isset($reg['id']) ? $reg['id'] : '';
                                        $score     = isset($reg['score']) && $reg['score'] !== '' ? $reg['score'] : 0;
                                        $x_count   = isset($reg['x_count']) && $reg['x_count'] !== '' ? $reg['x_count'] : 0;
                                        $gender_str= !empty($reg['gender']) ? $reg['gender'] : '—';
                                        $div_str   = !empty($reg['division']) ? $reg['division'] : '—';
                                        $style_str = !empty($reg['style']) ? $reg['style'] : '—';
                                        $is_locked = !empty($locked_lines[$reg['line_index']]);
                                        $dis_attr  = $is_locked ? 'disabled' : '';

                                        $lidx      = function_exists('sywat_roster_resolve_line_idx') ? sywat_roster_resolve_line_idx($reg, $lines) : (isset($reg['line_index']) ? (string)$reg['line_index'] : '0');
                                        $line_disp = isset($lines[$lidx]['name']) ? $lines[$lidx]['name'] : (!empty($reg['line_name']) ? $reg['line_name'] : 'Line 1');
                                    ?>
                                    <tr class="sywat-score-row <?php echo $is_locked ? 'locked-row' : ''; ?>" data-id="<?php echo esc_attr($reg_id); ?>" data-name="<?php echo esc_attr(strtolower($reg['name'])); ?>">
                                        <td><strong><?php echo $num++; ?></strong></td>
                                        <td><strong><?php echo esc_html($reg['name']); ?></strong></td>
                                        <td><?php echo esc_html($line_disp); ?></td>
                                        <td style="text-align: center;"><?php echo esc_html($gender_str); ?></td>
                                        <td><?php echo esc_html($div_str); ?></td>
                                        <td><?php echo esc_html($style_str); ?></td>
                                        <td style="text-align: center;">
                                            <input type="number" name="scores[<?php echo esc_attr($reg_id); ?>]" data-id="<?php echo esc_attr($reg_id); ?>" data-original-val="<?php echo esc_attr($score); ?>" value="<?php echo esc_attr($score); ?>" min="0" max="300" placeholder="0" class="sywat-score-input score-val" <?php echo $dis_attr; ?>>
                                        </td>
                                        <td style="text-align: center;">
                                            <input type="number" name="x_counts[<?php echo esc_attr($reg_id); ?>]" data-id="<?php echo esc_attr($reg_id); ?>" data-original-val="<?php echo esc_attr($x_count); ?>" value="<?php echo esc_attr($x_count); ?>" min="0" max="60" placeholder="0" class="sywat-score-input x-val" <?php echo $dis_attr; ?>>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($is_locked): ?>
                                                <span class="sywat-save-status sywat-status-locked" title="Unlocked by Admins Only">🔒 Locked</span>
                                            <?php else: ?>
                                                <span class="sywat-save-status sywat-status-saved" id="status_<?php echo esc_attr($reg_id); ?>">✓ Saved</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;">
                        <?php if ($is_admin): ?>
                            <button type="submit" name="sywat_action" value="reset_scores" class="sywat-btn sywat-btn-danger sywat-btn-icon-reset" style="height:32px;" onclick="return confirm('⚠️ Are you sure you want to RESET ALL SCORES to 0 for the selected line/tournament?\n\nThis will set score and X count to 0 for all unlocked, checked-in archers in this view.');">Reset All Scores to 0</button>
                        <?php endif; ?>
                        <button type="submit" name="sywat_action" value="save_scores" class="sywat-btn sywat-btn-success sywat-btn-icon-save" style="height:32px;">Save All Scores</button>
                    </div>
                <?php else: ?>
                    <p style="color:#64748b; font-style:italic; padding:20px 0;">No checked-in archers found for this tournament/line selection.</p>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php
    if (function_exists('sywat_scores_render_client_scripts')) {
        sywat_scores_render_client_scripts();
    }
    return ob_get_clean();
}
