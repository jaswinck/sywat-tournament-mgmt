<?php
/**
 * Sub-Module: Check-In Desk Visual Views
 * File: includes/checkin/checkin-views.php
 * Author: SYWAT Archery
 * Version: 6.1.2
 */

if (!defined('ABSPATH')) exit;

function sywat_desk_render_family_banner($added_family_prompt) {
    if (!$added_family_prompt) return;
    ?>
    <div class="sywat-alert sywat-alert-success no-print sywat-family-banner">
        <div class="sywat-family-banner-title">
            ✅ <?php echo esc_html($added_family_prompt['archer_name']); ?> added under <?php echo esc_html($added_family_prompt['family_name']); ?>!
        </div>
        <div class="sywat-family-banner-subtext">
            Is there another family member to register under this same payment group?
        </div>
        <div class="sywat-family-banner-actions">
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
    $can_manage_seasons = function_exists('sywat_can_manage_seasons') && sywat_can_manage_seasons();
    $selected_t = isset($data['selected_tournament']) ? $data['selected_tournament'] : '';
    
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

    $is_hub_enabled = ($t_shop_id === 'shop_ccr') && in_array($selected_t, $active_hubs);
    ?>
    <!-- Master Navigation Tabs (Card-Docked) -->
    <div class="sywat-nav-tabs no-print">
        <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">Manage Tournaments</a>
        <a href="<?php echo esc_url( site_url( '/sywat-register/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-register">Register Shooters</a>
        <a href="<?php echo esc_url( site_url( '/sywat-desk/' ) ); ?>" class="sywat-tab-item active sywat-tab-icon-checkin">Check In Archers</a>
        <a href="<?php echo esc_url( site_url( '/sywat-enter-scores/' ) ); ?>" class="sywat-tab-item sywat-tab-icon-edit">Enter Scores</a>
        <?php if ( $can_manage_seasons ): ?>
            <a href="<?php echo esc_url( site_url( '/sywat-manage-tournaments/?tab=seasons' ) ); ?>" class="sywat-tab-item sywat-tab-icon-season">Manage Seasons</a>
        <?php endif; ?>
    </div>

    <!-- Master Single Unified Desk Card -->
    <div class="sywat-card no-print">
        <!-- Integrated Control Panel Filter Toolbar Header Bar -->
        <div class="sywat-card-header-bar">
            <div>
                <h2>📋 Check In Archers</h2>
                <div class="sywat-subtitle">Select an active tournament and line to begin checking in archers.</div>
            </div>

            <div class="sywat-header-inline-controls">
                <form method="post" id="sywat_filter_form" class="sywat-filter-bar-form">
                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($data['search_query']); ?>">
                    
                    <!-- Stacked Active Tournament Select -->
                    <div class="sywat-field-group sywat-field-auto">
                        <label for="sywat_selected_tournament">Active Tournament:</label>
                        <select id="sywat_selected_tournament" name="selected_tournament" class="sywat-select-tournament" onchange="document.getElementById('sywat_filter_form').submit();">
                            <?php foreach ($data['tournaments'] as $t_name => $t_lines): ?>
                                <option value="<?php echo esc_attr($t_name); ?>" <?php selected($data['selected_tournament'], $t_name); ?>>
                                    <?php echo esc_html($t_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Stacked Shooting Line Select -->
                    <div class="sywat-field-group sywat-field-auto">
                        <label for="sywat_selected_line_idx">Shooting Line:</label>
                        <select id="sywat_selected_line_idx" name="selected_line_idx" class="sywat-select-line" onchange="document.getElementById('sywat_filter_form').submit();">
                            <option value="all" <?php selected($data['selected_line_idx'], 'all'); ?>>All Lines</option>
                            <?php foreach ($data['lines'] as $idx => $line): ?>
                                <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$data['selected_line_idx'], (string)$idx); ?>>
                                    <?php echo esc_html($line['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <?php if ($is_hub_enabled): ?>
                    <a href="<?php echo esc_url( site_url( '/sywat-volunteer-hub/' ) ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back">Back to Hub</a>
                <?php endif; ?>
            </div>
        </div>
    <?php
}

function sywat_desk_render_capacity_tracker($data) {
    $lines               = $data['lines'];
    $registrations       = $data['registrations'];
    $selected_tournament = $data['selected_tournament'];
    $is_admin            = $data['is_admin'];

    // Direct Live Render Fetch to eliminate the "Success but opposite display" glitch
    $active_t_slug    = isset($data['active_t_slug']) ? $data['active_t_slug'] : sanitize_title($selected_tournament);
    $closed_lines_key = 'sywat_closed_lines_' . $active_t_slug;
    
    $raw_closed   = get_option($closed_lines_key, array());
    $closed_lines = is_array($raw_closed) ? array_map('trim', array_map('strval', $raw_closed)) : array();

    ?>
    <!-- Nested Section: Line Capacity Tracker -->
    <div class="sywat-tracker-container">
        <div class="sywat-table-header-bar">
            <h2>Line Capacity Tracker</h2>
            <div class="sywat-table-header-controls">
                <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="openWalkinModal()">Add Walk-In</button>
            </div>
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

                    $ordered_lines[] = array('idx' => (string)$idx, 'line' => $line, 'weight' => $weight);
                }

                usort($ordered_lines, function($a, $b) {
                    if ($a['weight'] !== $b['weight']) return $a['weight'] <=> $b['weight'];
                    return $a['idx'] <=> $b['idx'];
                });
            ?>

            <div class="sywat-tracker-grid">
                <?php foreach ($ordered_lines as $item): ?>
                    <?php
                        $idx       = $item['idx'];
                        $line      = $item['line'];
                        $line_name = isset($line['name']) ? trim($line['name']) : '';
                        
                        $booked_20yd = 0; $booked_cub = 0; $total_reg_line = 0; $checked_in_line = 0;

                        foreach ($registrations as $reg) {
                            if (isset($reg['tournament']) && $reg['tournament'] === $selected_tournament) {
                                $match_index = isset($reg['line_index']) && (string)$reg['line_index'] === $idx;
                                $match_name  = isset($reg['line_name']) && $line_name !== '' && trim($reg['line_name']) === $line_name;

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
                        
                        $is_closed_line = in_array($idx, $closed_lines, true) || ($line_name !== '' && in_array($line_name, $closed_lines, true));
                    ?>
                    <div class="sywat-tracker-card">
                        <div>
                            <div class="sywat-tracker-card-header">
                                <strong class="sywat-tracker-card-title"><?php echo esc_html($line['name']); ?></strong>
                                <div class="sywat-tracker-header-badges">
                                    <?php if ($is_closed_line): ?>
                                        <span class="sywat-badge sywat-badge-full">CLOSED</span>
                                    <?php endif; ?>
                                    <span class="sywat-badge sywat-badge-prereg">
                                        <?php echo $checked_in_line . ' / ' . $total_reg_line . ' Checked In'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="sywat-tracker-card-stats">
                                <span><strong>20yd:</strong> <?php echo $disp_20yd; ?></span>
                                <span><strong>Cub:</strong> <?php echo $disp_cub; ?></span>
                            </div>
                        </div>
                        
                        <div class="sywat-tracker-card-actions">
                            <form method="post">
                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($idx); ?>">
                                <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($data['search_query']); ?>">
                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin">Check In Line</button>
                            </form>

                            <?php if ($is_admin): ?>
                                <?php if ($is_closed_line): ?>
                                    <form method="post">
                                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                        <input type="hidden" name="sywat_action" value="reopen_line">
                                        <input type="hidden" name="target_line_idx" value="<?php echo esc_attr($idx); ?>">
                                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($data['search_query']); ?>">
                                        <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-unlock">Reopen</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" onsubmit="return confirm('Are you sure you want to close this line? All un-checked-in archers will be permanently marked as No Show.');">
                                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                        <input type="hidden" name="sywat_action" value="close_line">
                                        <input type="hidden" name="target_line_idx" value="<?php echo esc_attr($idx); ?>">
                                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($data['search_query']); ?>">
                                        <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-lock">Close</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="sywat-empty-text">No shooting lines configured.</p>
        <?php endif; ?>
    </div>
    <?php
}

function sywat_desk_render_archers_table($data) {
    $tourn_registrations = $data['tourn_registrations'];
    $search_query        = $data['search_query'];
    $selected_tournament = $data['selected_tournament'];
    $selected_line_idx   = $data['selected_line_idx'];
    $lines               = $data['lines'];

    // Direct Live Render Fetch
    $active_t_slug    = isset($data['active_t_slug']) ? $data['active_t_slug'] : sanitize_title($selected_tournament);
    $closed_lines_key = 'sywat_closed_lines_' . $active_t_slug;
    
    $raw_closed   = get_option($closed_lines_key, array());
    $closed_lines = is_array($raw_closed) ? array_map('trim', array_map('strval', $raw_closed)) : array();

    ?>
    <!-- Nested Section: Registered Archers Table -->
    <div>
        <div class="sywat-table-header-bar">
            <h2>Registered Archers</h2>
            <div class="sywat-table-header-controls">
                <div class="sywat-search-input-group">
                    <input type="text" id="sywat_search_input" value="<?php echo esc_attr($search_query); ?>" placeholder="Search name..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="document.getElementById('sywat_search_input').value=''; document.getElementById('sywat_search_input').dispatchEvent(new Event('input'));">Clear</button>
                </div>
            </div>
        </div>

        <?php if (!empty($tourn_registrations)): ?>
            <div class="sywat-table-responsive">
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="sywat-col-center">Type</th>
                            <th>Line</th>
                            <th>Division</th>
                            <th class="sywat-col-center">Gender</th>
                            <th>Style</th>
                            <th>Paid Status</th>
                            <th class="sywat-col-center">Distance</th>
                            <th class="sywat-col-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tourn_registrations as $reg): ?>
                            <?php 
                                $reg_type       = isset($reg['type']) && $reg['type'] === 'Walk-In' ? 'Walk-In' : 'Pre-Reg';
                                $badge_class    = ($reg_type === 'Walk-In') ? 'sywat-badge-walkin' : 'sywat-badge-prereg';
                                $balance_due    = isset($reg['balance_due']) ? floatval($reg['balance_due']) : 0;
                                $paid_disp      = !empty($reg['paid']) ? $reg['paid'] : '—';
                                $div_disp       = !empty($reg['division']) ? $reg['division'] : '—';
                                $style_disp     = !empty($reg['style']) ? $reg['style'] : '—';
                                $is_family_due  = (isset($reg['paid']) && $reg['paid'] === 'Family') || (isset($reg['rate_type']) && strtolower($reg['rate_type']) === 'family');
                                
                                $resolved_l_idx = function_exists('sywat_desk_resolve_line_idx') ? sywat_desk_resolve_line_idx($reg, $lines) : (isset($reg['line_index']) ? (string)$reg['line_index'] : '0');
                                $reg_line_name  = isset($lines[$resolved_l_idx]['name']) ? trim($lines[$resolved_l_idx]['name']) : (isset($reg['line_name']) ? trim($reg['line_name']) : '');

                                $is_line_closed = in_array((string)$resolved_l_idx, $closed_lines, true) || ($reg_line_name !== '' && in_array($reg_line_name, $closed_lines, true));
                            ?>
                            <tr class="sywat-archer-row" data-name="<?php echo esc_attr(strtolower($reg['name'])); ?>">
                                <td>
                                    <div class="sywat-archer-name"><?php echo esc_html($reg['name']); ?></div>
                                    <?php if ($balance_due > 0 || empty($reg['paid']) || $paid_disp === '—'): ?>
                                        <?php if (!empty($reg['paid']) && strtolower($reg['paid']) === 'yes') { /* Paid, show no owe badge */ } else { ?>
                                            <div class="sywat-owe-badge-wrap">
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
                                <td class="sywat-col-center">
                                    <span class="sywat-badge <?php echo $badge_class; ?>"><?php echo esc_html($reg_type); ?></span>
                                </td>
                                <td><?php echo esc_html(isset($reg['line_name']) ? $reg['line_name'] : 'N/A'); ?></td>
                                <td><?php echo esc_html($div_disp); ?></td>
                                <td class="sywat-col-center"><?php echo esc_html(isset($reg['gender']) ? $reg['gender'] : 'N/A'); ?></td>
                                <td><?php echo esc_html($style_disp); ?></td>
                                <td class="sywat-col-paid"><?php echo esc_html($paid_disp); ?></td>
                                <td class="sywat-col-center"><?php echo esc_html(isset($reg['distance']) ? $reg['distance'] : 'N/A'); ?></td>
                                <td class="sywat-col-center">
                                    <div class="sywat-table-actions-vertical">
                                        <?php if ($is_line_closed): ?>
                                            <?php if (!empty($reg['checked_in'])): ?>
                                                <!-- Static Badge on Closed Lines (Removes Undo Form/Link) -->
                                                <span class="sywat-badge sywat-badge-open">✓ Checked In</span>
                                            <?php else: ?>
                                                <!-- Static Badge for Unchecked/Waitlist on Closed Lines -->
                                                <span class="sywat-badge sywat-badge-full">Line Closed</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if (!empty($reg['checked_in'])): ?>
                                                <!-- Interactive Undo Form Button on Open Lines -->
                                                <form method="post" class="sywat-table-action-form">
                                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                                                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                                                    <input type="hidden" name="sywat_action" value="undo_checkin">
                                                    <input type="hidden" name="reg_id" value="<?php echo esc_attr($reg['id']); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-checkedin sywat-btn-icon-checkedin">Checked In</button>
                                                </form>
                                            <?php else: ?>
                                                <!-- Interactive Check In Form Button on Open Lines -->
                                                <form method="post" class="sywat-table-action-form">
                                                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                                    <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                                                    <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                                                    <input type="hidden" name="open_checkin_id" value="<?php echo esc_attr($reg['id']); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-success-solid sywat-btn-icon-checkin">Check In Archer</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <!-- Always Accessible Edit Button -->
                                        <form method="post" class="sywat-table-action-form">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr($selected_tournament); ?>">
                                            <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr($selected_line_idx); ?>">
                                            <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr($search_query); ?>">
                                            <input type="hidden" name="open_edit_id" value="<?php echo esc_attr($reg['id']); ?>">
                                            <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="sywat-empty-text">No archers registered for this selection.</p>
        <?php endif; ?>
    </div>
    <!-- Close Master Single Unified Desk Card -->
    </div>
    <?php
}
?>

<script>
if ( window.history.replaceState ) {
    window.history.replaceState( null, null, window.location.href );
}

document.addEventListener("DOMContentLoaded", function() {
    // Persistent Archer Search Query Retention & Dynamic Table Filter
    const searchInput = document.getElementById('sywat_search_input');
    
    function applySearchFilter(query) {
        const cleanQuery = query.trim().toLowerCase();
        const rows = document.querySelectorAll('.sywat-archer-row');
        
        rows.forEach(function(row) {
            const archerName = row.getAttribute('data-name') || '';
            if (!cleanQuery || archerName.includes(cleanQuery)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });

        // Sync hidden search fields across all forms on the page
        document.querySelectorAll('.sywat-hidden-search').forEach(function(hiddenInput) {
            hiddenInput.value = query;
        });

        // Store query in sessionStorage for retention across action posts
        if (query) {
            sessionStorage.setItem('sywat_checkin_search_query', query);
        } else {
            sessionStorage.removeItem('sywat_checkin_search_query');
        }
    }

    if (searchInput) {
        let initialQuery = searchInput.value;
        if (!initialQuery) {
            const savedQuery = sessionStorage.getItem('sywat_checkin_search_query');
            if (savedQuery) {
                initialQuery = savedQuery;
                searchInput.value = savedQuery;
            }
        }

        if (initialQuery) {
            applySearchFilter(initialQuery);
        }

        searchInput.addEventListener('input', function() {
            applySearchFilter(this.value);
        });
    }

    // Auto-dismiss standard notices after 4 seconds
    setTimeout(function() {
        const notices = document.querySelectorAll('.sywat-wrap .sywat-alert, .sywat-wrap .sywat-notice, .sywat-wrap .notice');
        notices.forEach(function(notice) {
            notice.style.transition = 'opacity 0.5s ease, margin-bottom 0.5s ease';
            notice.style.opacity = '0';
            setTimeout(function() {
                notice.style.display = 'none';
            }, 500);
        });
    }, 4000);
});
</script>
