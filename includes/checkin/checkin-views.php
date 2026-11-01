<?php
/**
 * Sub-Module: Check-In Desk Visual Views
 * File: checkin-views.php
 * Author: SYWAT Archery
 * Version: 2.4.0
 */

if (!defined('ABSPATH')) exit;

function sywat_desk_render_family_banner($added_family_prompt) {
    if (!$added_family_prompt) return;
    ?>
    <div class="no-print" style="background: #e0f2fe; border: 2px solid #0284c7; border-radius: 8px; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <div style="font-size: 15px; font-weight: bold; color: #0369a1; margin-bottom: 6px;">
            ✅ <?php echo esc_html($added_family_prompt['archer_name']); ?> added under <?php echo esc_html($added_family_prompt['family_name']); ?>!
        </div>
        <div style="font-size: 13px; color: #334155; margin-bottom: 12px;">
            Is there another family member to register under this same payment group?
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="continueFamilyLoop('<?php echo esc_js($added_family_prompt['family_id']); ?>', '<?php echo esc_js($added_family_prompt['line_idx']); ?>')">
                Yes, Add Next Family Member
            </button>
            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="this.parentElement.parentElement.remove();">
                Done / Finish Family
            </button>
        </div>
    </div>
    <?php
}

function sywat_desk_render_filter_bar($data) {
    $selected_t = isset($data['selected_tournament']) ? $data['selected_tournament'] : '';
    
    // Fetch master tournament configuration directly from database option
    $all_tournaments = get_option('sywat_tournaments_list', array());
    $t_master        = (is_array($all_tournaments) && isset($all_tournaments[$selected_t])) ? $all_tournaments[$selected_t] : array();

    $t_shop_id = 'shop_ccr';
    if (is_array($t_master) && isset($t_master['shop_id']) && !empty($t_master['shop_id'])) {
        $t_shop_id = $t_master['shop_id'];
    } elseif (function_exists('sywat_get_tournament_shop_id')) {
        $t_shop_id = sywat_get_tournament_shop_id($t_master);
    }

    $active_hubs = get_option('sywat_active_tournaments', array());
    if (!is_array($active_hubs)) $active_hubs = array();

    // Volunteer Hub is strictly enabled ONLY for active tournaments hosted by Cinnamon Creek Ranch (shop_ccr)
    $is_hub_enabled = ($t_shop_id === 'shop_ccr') && in_array($selected_t, $active_hubs);
    ?>
    <!-- Master Navigation Tabs -->
    <div class="sywat-nav-tabs no-print">
        <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">
            Manage Tournaments
        </a>
        <a href="<?php echo esc_url( site_url( '/sywat-desk/' ) ); ?>" class="sywat-tab-item active sywat-tab-icon-results">
            Check In Archers
        </a>
        <a href="<?php echo esc_url( site_url( '/sywat-enter-scores/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-season">
            Enter Scores
        </a>
    </div>

    <!-- Integrated Top Card Header & Filters -->
    <div class="sywat-card no-print" style="margin-bottom:20px; border-top-left-radius:0;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
            
            <h3 style="margin:0; font-size:17px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">
                Check In Archers
            </h3>

            <form method="post" id="sywat_filter_form" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin:0;">
                <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($data['search_query']); ?>">
                
                <div style="display:flex; align-items:center; gap:6px;">
                    <label style="font-weight:700; font-size:13px; color:#334155; white-space:nowrap;">Tournament:</label>
                    <select name="selected_tournament" onchange="document.getElementById('sywat_filter_form').submit();">
                        <?php foreach ($data['tournaments'] as $t_name => $t_lines): ?>
                            <option value="<?php echo esc_attr($t_name); ?>" <?php selected($data['selected_tournament'], $t_name); ?>>
                                <?php echo esc_html($t_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:flex; align-items:center; gap:6px;">
                    <label style="font-weight:700; font-size:13px; color:#334155; white-space:nowrap;">Shooting Line:</label>
                    <select name="selected_line_idx" onchange="document.getElementById('sywat_filter_form').submit();">
                        <option value="all" <?php selected($data['selected_line_idx'], 'all'); ?>>All Lines</option>
                        <?php foreach ($data['lines'] as $idx => $line): ?>
                            <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$data['selected_line_idx'], (string)$idx); ?>>
                                <?php echo esc_html($line['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($is_hub_enabled): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-volunteer-hub/' ) ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back">Back to Hub</a>
                <?php endif; ?>
            </form>

        </div>
    </div>
    <?php
}

function sywat_desk_render_capacity_tracker($data) {
    $lines = $data['lines'];
    $registrations = $data['registrations'];
    $selected_tournament = $data['selected_tournament'];
    $closed_lines = $data['closed_lines'];
    $is_admin = $data['is_admin'];

    ?>
    <div class="sywat-card no-print">
        <div class="sywat-card-title">
            <span>Line Capacity Tracker</span>
        </div>
        <?php if (!empty($lines)): ?>
            <?php
                $ordered_lines = array();
                foreach ($lines as $idx => $line) {
                    $line_title = isset($line['name']) ? $line['name'] : 'Line ' . ($idx + 1);
                    $weight = 50;

                    if (stripos($line_title, 'Sat') !== false) {
                        if (stripos($line_title, '10') !== false) $weight = 10;
                        elseif (stripos($line_title, '2') !== false) $weight = 20;
                        else $weight = 25;
                    } elseif (stripos($line_title, 'Sun') !== false) {
                        if (stripos($line_title, '10') !== false) $weight = 30;
                        elseif (stripos($line_title, '2') !== false) $weight = 40;
                        else $weight = 45;
                    }

                    $ordered_lines[] = array('idx' => $idx, 'line' => $line, 'weight' => $weight);
                }

                usort($ordered_lines, function($a, $b) {
                    if ($a['weight'] !== $b['weight']) return $a['weight'] <=> $b['weight'];
                    return $a['idx'] <=> $b['idx'];
                });
            ?>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                <?php foreach ($ordered_lines as $item): ?>
                    <?php
                        $idx  = $item['idx'];
                        $line = $item['line'];
                        
                        $booked_20yd = 0; $booked_cub = 0; $total_reg_line = 0; $checked_in_line = 0;

                        foreach ($registrations as $reg) {
                            if (isset($reg['tournament']) && $reg['tournament'] === $selected_tournament) {
                                $match_index = isset($reg['line_index']) && (string)$reg['line_index'] === (string)$idx;
                                $match_name  = isset($reg['line_name']) && isset($line['name']) && trim($reg['line_name']) === trim($line['name']);

                                if ($match_index || $match_name) {
                                    $total_reg_line++;
                                    if (!empty($reg['checked_in'])) $checked_in_line++;
                                    if (isset($reg['distance']) && $reg['distance'] === '20yd') $booked_20yd++;
                                    if (isset($reg['distance']) && $reg['distance'] === 'Cub') $booked_cub++;
                                }
                            }
                        }

                        $cap_20yd_num = max(0, intval(isset($line['cap_20yd']) ? $line['cap_20yd'] : 0) - $booked_20yd);
                        $cap_cub_num  = max(0, intval(isset($line['cap_cub']) ? $line['cap_cub'] : 0) - $booked_cub);

                        $disp_20yd = ($cap_20yd_num === 0) ? '<span class="sywat-badge sywat-badge-full">FULL</span>' : $cap_20yd_num . ' Open';
                        $disp_cub  = ($cap_cub_num === 0) ? '<span class="sywat-badge sywat-badge-full">FULL</span>' : $cap_cub_num . ' Open';
                        
                        $is_closed_line = in_array((string)$idx, $closed_lines);
                        $closed_badge = $is_closed_line ? ' <span class="sywat-badge sywat-badge-full">[CLOSED]</span>' : '';
                    ?>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; display:flex; flex-direction:column; justify-content:space-between; gap:10px;">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                                <strong style="color:#0f172a; font-size:14px;"><?php echo esc_html($line['name']) . $closed_badge; ?></strong>
                                <span class="sywat-badge sywat-badge-prereg">
                                    <?php echo $checked_in_line . ' / ' . $total_reg_line . ' Checked In'; ?>
                                </span>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:13px; color:#475569;">
                                <span><strong>20yd:</strong> <?php echo $disp_20yd; ?></span>
                                <span><strong>Cub:</strong> <?php echo $disp_cub; ?></span>
                            </div>
                        </div>
                        
                        <!-- Line Check-In Shortcut Button & Admin Toggle -->
                        <div style="margin-top:4px; border-top:1px dashed #cbd5e1; padding-top:8px; display:flex; gap:6px; align-items:center; justify-content:space-between;">
                            <form method="post" style="margin:0; flex:1;">
                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($idx); ?>">
                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin" style="width:100%;">Check In Line</button>
                            </form>

                            <?php if ($is_admin): ?>
                                <?php if ($is_closed_line): ?>
                                    <form method="post" style="margin:0;">
                                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                        <input type="hidden" name="sywat_action" value="reopen_line">
                                        <input type="hidden" name="target_line_idx" value="<?php echo esc_attr($idx); ?>">
                                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                        <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-unlock">Reopen</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="margin:0;" onsubmit="return confirm('Are you sure you want to close this line? All un-checked-in archers will be permanently marked as No Show.');">
                                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                        <input type="hidden" name="sywat_action" value="close_line">
                                        <input type="hidden" name="target_line_idx" value="<?php echo esc_attr($idx); ?>">
                                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                        <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-lock">Close</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color:#64748b; font-style:italic;">No shooting lines configured.</p>
        <?php endif; ?>
    </div>
    <?php
}

function sywat_desk_render_archers_table($data) {
    $tourn_registrations = $data['tourn_registrations'];
    $search_query        = $data['search_query'];
    $selected_tournament = $data['selected_tournament'];
    $selected_line_idx   = $data['selected_line_idx'];
    $closed_lines        = $data['closed_lines'];

    ?>
    <div class="sywat-card no-print">
        <div class="sywat-card-title">
            <span>Registered Archers</span>
            <div style="display:flex; gap:8px; align-items:center;">
                <div style="display:flex; gap:5px; margin:0; align-items:center;">
                    <input type="text" id="sywat_search_input" value="<?php echo esc_attr($search_query); ?>" placeholder="Search name..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="document.getElementById('sywat_search_input').value=''; document.getElementById('sywat_search_input').dispatchEvent(new Event('input'));">Clear</button>
                </div>
                <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="openWalkinModal()">Add Walk-In</button>
            </div>
        </div>

        <?php if (!empty($tourn_registrations)): ?>
            <table class="sywat-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">Name</th>
                        <th style="text-align:center;">Type</th>
                        <th style="text-align:left;">Line</th>
                        <th style="text-align:left;">Division</th>
                        <th style="text-align:center;">Gender</th>
                        <th style="text-align:left;">Style</th>
                        <th style="text-align:left;">Paid Status</th>
                        <th style="text-align:center;">Distance</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tourn_registrations as $reg): ?>
                        <?php 
                            $reg_type   = isset($reg['type']) && $reg['type'] === 'Walk-In' ? 'Walk-In' : 'Pre-Reg';
                            $badge_class= ($reg_type === 'Walk-In') ? 'sywat-badge-walkin' : 'sywat-badge-prereg';
                            $balance_due= isset($reg['balance_due']) ? floatval($reg['balance_due']) : 0;
                            $paid_disp  = !empty($reg['paid']) ? $reg['paid'] : '—';
                            $div_disp   = !empty($reg['division']) ? $reg['division'] : '—';
                            $style_disp = !empty($reg['style']) ? $reg['style'] : '—';
                            
                            $is_family_due = (isset($reg['paid']) && $reg['paid'] === 'Family') || (isset($reg['rate_type']) && strtolower($reg['rate_type']) === 'family');
                        ?>
                        <tr class="sywat-archer-row" data-name="<?php echo esc_attr(strtolower($reg['name'])); ?>">
                            <td>
                                <div style="font-weight:bold; color:#0f172a;"><?php echo esc_html($reg['name']); ?></div>
                                <!-- Clean CSS OWE Badge using .sywat-badge-full -->
                                <?php if ($balance_due > 0 || empty($reg['paid']) || $paid_disp === '—'): ?>
                                    <?php if (!empty($reg['paid']) && strtolower($reg['paid']) === 'yes') { /* Paid, show no owe badge */ } else { ?>
                                        <div style="margin-top:4px;">
                                            <span class="sywat-badge sywat-badge-full">
                                                <?php if ($is_family_due): ?>
                                                    ⚠️ OWE: FAMILY
                                                <?php else: ?>
                                                    <?php 
                                                        $is_youth_div = (stripos($div_disp, 'Cub') !== false || stripos($div_disp, 'Youth') !== false || stripos($div_disp, 'Young Adult') !== false);
                                                        $fixed_due = $is_youth_div ? 25.00 : 30.00;
                                                    ?>
                                                    ⚠️ OWE $<?php echo number_format($fixed_due, 2); ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php } ?>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <span class="sywat-badge <?php echo $badge_class; ?>"><?php echo esc_html($reg_type); ?></span>
                            </td>
                            <td><?php echo esc_html(isset($reg['line_name']) ? $reg['line_name'] : 'N/A'); ?></td>
                            <td><?php echo esc_html($div_disp); ?></td>
                            <td style="text-align:center;"><?php echo esc_html(isset($reg['gender']) ? $reg['gender'] : 'N/A'); ?></td>
                            <td><?php echo esc_html($style_disp); ?></td>
                            <td style="font-weight:bold; color:#0073aa;"><?php echo esc_html($paid_disp); ?></td>
                            <td style="text-align:center;"><?php echo esc_html(isset($reg['distance']) ? $reg['distance'] : 'N/A'); ?></td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex; gap:4px; align-items:center;">
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                        <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                                        <input type="hidden" name="open_edit_id" value="<?php echo esc_attr($reg['id']); ?>">
                                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit</button>
                                    </form>

                                    <?php if (!empty($reg['checked_in'])): ?>
                                        <form method="post" style="margin:0;">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                            <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                                            <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                                            <input type="hidden" name="sywat_action" value="undo_checkin">
                                            <input type="hidden" name="reg_id" value="<?php echo esc_attr($reg['id']); ?>">
                                            <button type="submit" class="sywat-btn sywat-btn-checkedin sywat-btn-icon-checkedin">Checked In</button>
                                        </form>
                                    <?php else: ?>
                                        <?php if (in_array((string)$reg['line_index'], $closed_lines)): ?>
                                            <span class="sywat-badge sywat-badge-full">Line Closed</span>
                                        <?php else: ?>
                                            <form method="post" style="margin:0;">
                                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                                <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                                                <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                                                <input type="hidden" name="open_checkin_id" value="<?php echo esc_attr($reg['id']); ?>">
                                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin">Check In Archer</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#666;">No archers registered for this selection.</p>
        <?php endif; ?>
    </div>
    <?php
}
