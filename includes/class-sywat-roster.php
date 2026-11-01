<?php
/**
 * Module: Archer Roster Management & Validation
 * Path: includes/class-sywat-roster.php
 * Description: Archer editing logic, division/style restrictions, strict family line-scoping, line purges, and manage modal HTML.
 * Author: SYWAT Archery
 * Version: 3.1.0
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
        $is_pro_fsl_trad = (strpos($style, 'FS Pro') !== false || strpos($style, 'FSL –') !== false || strpos($style, 'TRAD') !== false || stripos($style, 'Traditional') !== false );
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

if ( ! function_exists( 'sywat_roster_resolve_line_idx' ) ) {
    function sywat_roster_resolve_line_idx($reg, $lines) {
        if (isset($reg['line_index']) && $reg['line_index'] !== '') {
            $idx = (string)$reg['line_index'];
            if (isset($lines[$idx])) return $idx;
            foreach ($lines as $l_key => $l_data) {
                if (isset($l_data['name']) && strcasecmp(trim($l_data['name']), trim($idx)) === 0) {
                    return (string)$l_key;
                }
            }
        }
        if (isset($reg['line_name']) && !empty($reg['line_name'])) {
            foreach ($lines as $l_key => $l_data) {
                if (isset($l_data['name']) && strcasecmp(trim($l_data['name']), trim($reg['line_name'])) === 0) {
                    return (string)$l_key;
                }
            }
        }
        return isset($reg['line_index']) ? (string)$reg['line_index'] : '0';
    }
}

if ( ! function_exists( 'sywat_handle_save_edit' ) ) {
    function sywat_handle_save_edit( $tournaments, &$registrations ) {
        $edit_id       = sanitize_text_field( $_POST['registration_id'] ?? '' );
        $tourn_context = sanitize_text_field( $_POST['view_tournament'] ?? '' );
        $t_slug        = sanitize_title( $tourn_context );

        $opt_key = 'sywat_t_' . $t_slug . '_' . $edit_id;
        $reg     = get_option($opt_key);

        if ( ! $reg && ! empty( $registrations ) ) {
            foreach ( $registrations as $r ) {
                if ( isset( $r['id'] ) && (string)$r['id'] === (string)$edit_id ) {
                    $reg = $r;
                    break;
                }
            }
        }

        if ($reg) {
            $edit_name = trim(sanitize_text_field($_POST['edit_name'] ?? ''));
            $line_idx  = sanitize_text_field($_POST['edit_line_index'] ?? '0');
            $line_name = isset($tournaments[$tourn_context][$line_idx]['name']) ? $tournaments[$tourn_context][$line_idx]['name'] : 'Line ' . ((int)$line_idx + 1);
            $division  = sanitize_text_field($_POST['edit_division'] ?? '');
            $distance  = ($division === 'Cub (8 - 11)') ? 'Cub' : '20yd';
            $pay_opt   = sanitize_text_field($_POST['edit_pay_option'] ?? '');
            $style     = sanitize_text_field($_POST['edit_style'] ?? '');
            $due_val   = isset($_POST['edit_balance_due']) ? floatval($_POST['edit_balance_due']) : 0;
            
            $orig_name = isset($reg['name']) ? trim($reg['name']) : '';
            $orig_line = isset($reg['line_index']) ? (string)$reg['line_index'] : '';

            $reg['name']        = $edit_name;
            $reg['line_index']  = $line_idx;
            $reg['line_name']   = $line_name;
            $reg['distance']    = $distance;
            $reg['division']    = $division;
            $reg['gender']      = sanitize_text_field($_POST['edit_gender'] ?? '');
            $reg['style']       = sywat_sanitize_style_for_division($style, $division);
            $reg['paid']        = sywat_calculate_paid_status($pay_opt, $division);
            $reg['balance_due'] = $due_val;

            // Handle strict Family Anchor assignments
            if ($reg['paid'] === 'Family') {
                $sel_fam_id = isset($_POST['edit_family_group_id']) ? sanitize_text_field($_POST['edit_family_group_id']) : '';

                if (!empty($sel_fam_id)) {
                    $reg['family_id'] = $sel_fam_id;
                    
                    $existing_label = 'Family Group';
                    foreach ($registrations as $other) {
                        if (isset($other['family_id']) && $other['family_id'] === $sel_fam_id && !empty($other['family_name'])) {
                            $existing_label = $other['family_name'];
                            break;
                        }
                    }
                    $reg['family_name'] = $existing_label;
                } elseif (empty($reg['family_id']) || $orig_line !== $line_idx) {
                    $reg['family_id']   = 'fam_' . $t_slug . '_l' . sanitize_title($line_idx) . '_' . sanitize_title($edit_name) . '_' . substr(md5(uniqid()), 0, 4);
                    $reg['family_name'] = $edit_name . ' (Family Cap)';
                }
            } else {
                unset($reg['family_id']);
                unset($reg['family_name']);
            }

            update_option($opt_key, $reg, false);

            foreach ($registrations as &$r) {
                if (isset($r['id']) && (string)$r['id'] === (string)$edit_id) {
                    $r = $reg;
                    break;
                }
            }
            unset($r);

            $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
            if ( ! is_array( $master_regs ) ) $master_regs = array();
            $master_regs[$edit_id] = $reg;
            update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
            update_option( 'sywat_registrations', $registrations, false );

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully updated archer: <strong>' . esc_html($reg['name']) . '</strong>.</div>';
        }
        return '';
    }
}

if ( ! function_exists( 'sywat_handle_delete_shooter' ) ) {
    function sywat_handle_delete_shooter( &$registrations ) {
        $delete_id     = sanitize_text_field( $_POST['registration_id'] ?? '' );
        $tourn_context = sanitize_text_field( $_POST['view_tournament'] ?? '' );
        $t_slug        = sanitize_title( $tourn_context );

        $deleted_name = '';
        $new_registrations = array();

        foreach ( $registrations as $reg ) {
            if ( isset( $reg['id'] ) && (string)$reg['id'] === (string)$delete_id ) {
                $deleted_name = isset( $reg['name'] ) ? $reg['name'] : 'Archer';
                delete_option( 'sywat_t_' . $t_slug . '_' . $delete_id );
                continue;
            }
            $new_registrations[] = $reg;
        }

        $registrations = $new_registrations;

        $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( is_array( $master_regs ) && isset( $master_regs[$delete_id] ) ) {
            unset( $master_regs[$delete_id] );
            update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        }
        update_option( 'sywat_registrations', $registrations, false );

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully removed <strong>' . esc_html( $deleted_name ) . '</strong> from the roster.</div>';
    }
}

if ( ! function_exists( 'sywat_handle_purge_line' ) ) {
    function sywat_handle_purge_line( $tournaments, &$registrations ) {
        $tourn_name = sanitize_text_field( $_POST['selected_tournament'] ?? '' );
        $line_idx   = sanitize_text_field( $_POST['target_line_idx'] ?? '' );
        $t_slug     = sanitize_title( $tourn_name );

        $line_data = isset( $tournaments[$tourn_name][$line_idx] ) ? $tournaments[$tourn_name][$line_idx] : array();
        $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ((int)$line_idx + 1);

        $purged_count = 0;
        $master_regs  = get_option( 'sywat_registrations_' . $t_slug, array() );
        if ( ! is_array( $master_regs ) ) $master_regs = array();

        foreach ( $registrations as $k => $reg ) {
            if ( isset( $reg['tournament'], $reg['line_index'] ) && strcasecmp( $reg['tournament'], $tourn_name ) === 0 && (string)$reg['line_index'] === (string)$line_idx ) {
                delete_option( 'sywat_t_' . $t_slug . '_' . $reg['id'] );
                if ( isset( $master_regs[$reg['id']] ) ) {
                    unset( $master_regs[$reg['id']] );
                }
                $purged_count++;
                unset($registrations[$k]);
            }
        }

        $registrations = array_values($registrations);

        update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
        update_option( 'sywat_registrations', $registrations, false );

        return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully cleared <strong>' . $purged_count . '</strong> archer(s) from <strong>' . esc_html( $line_name ) . '</strong>.</div>';
    }
}

// Manage Archers Modal UI Renderer
if ( ! function_exists( 'sywat_render_roster_modal' ) ) {
    function sywat_render_roster_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $search_query, $modal_edit_archer = '' ) {
        
        $divs = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)', 'Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)');
        $genders = array('Male', 'Female');
        $sywat_styles = array('FS – Freestyle', 'FS Pro – Freestyle Pro', 'FSL – Freestyle Limited', 'FSLR – Freestyle Limited Recurve (or Olympic Recurve)', 'BHFS – Bowhunter Freestyle', 'MBH – Modern Bowhunter', 'BB Barebow', 'BBC – Barebow Compound', 'BBR – Barebow Recurve', 'TRAD – Traditional', 'LB – Longbow', 'Genesis');

        // STRICT FILTER: Build Line-Scoped & Smart-Named Family Groups array for JS dynamic dropdowns
        $existing_families_by_line = array();
        $family_members_by_id      = array();

        foreach ($registrations as $r) {
            $fid = !empty($r['family_id']) ? trim($r['family_id']) : '';
            if (empty($fid)) continue;

            $is_fam = (isset($r['paid']) && strcasecmp($r['paid'], 'Family') === 0)
                   || (!empty($r['rate_type']) && stripos($r['rate_type'], 'family') !== false);

            if (!$is_fam) continue;

            $resolved_l_idx = sywat_roster_resolve_line_idx($r, $available_lines);

            if (!isset($family_members_by_id[$fid])) {
                $family_members_by_id[$fid] = array(
                    'line_idx'    => $resolved_l_idx,
                    'family_name' => !empty($r['family_name']) ? trim($r['family_name']) : '',
                    'names'       => array(),
                );
            }
            if (!empty($r['name'])) {
                $family_members_by_id[$fid]['names'][] = trim($r['name']);
            }
        }

        foreach ($family_members_by_id as $fid => $fam_info) {
            $l_idx     = $fam_info['line_idx'];
            $raw_fname = $fam_info['family_name'];
            $names     = $fam_info['names'];

            if (!empty($raw_fname) && stripos($raw_fname, 'fam_') === false && stripos($raw_fname, 'Booking') === false && strlen($raw_fname) > 2) {
                $label = $raw_fname;
            } elseif (!empty($names)) {
                if (count($names) == 1) {
                    $label = $names[0] . ' Group';
                } elseif (count($names) <= 3) {
                    $last_names = array_map(function($n) {
                        $p = explode(' ', trim($n));
                        return end($p);
                    }, $names);
                    $label = implode(' / ', array_unique($last_names)) . ' Group';
                } else {
                    $p = explode(' ', trim($names[0]));
                    $label = end($p) . ' Family Group (' . count($names) . ' Archers)';
                }
            } else {
                $label = 'Family Group (' . substr($fid, -6) . ')';
            }

            if (!isset($existing_families_by_line[$l_idx])) {
                $existing_families_by_line[$l_idx] = array();
            }
            $existing_families_by_line[$l_idx][$fid] = $label;
        }

        // Filter registrations for this tournament
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

        // Resolve Target Archer record for the Edit Modal
        $target_archer = null;
        if ( is_array( $modal_edit_archer ) ) {
            $target_archer = $modal_edit_archer;
        } elseif ( ! empty( $modal_edit_archer ) ) {
            foreach ( $filtered_registrations as $reg ) {
                if ( isset( $reg['id'] ) && (string)$reg['id'] === (string)$modal_edit_archer ) {
                    $target_archer = $reg;
                    break;
                }
            }
        }

        $open_edit_modal = ! empty( $target_archer );
        ?>

        <div class="no-print">
            <!-- Filter & Search Controls Header -->
            <div style="margin-bottom: 15px; display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <form method="get" style="display: flex; gap: 12px; flex: 1; align-items: flex-end; margin: 0; flex-wrap: wrap;">
                    <input type="hidden" name="tab" value="manage">
                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <input type="hidden" name="modal_action" value="manage">

                    <div>
                        <label for="filter_line_idx" style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Filter by Line</label>
                        <select name="filter_line_idx" id="filter_line_idx" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="this.form.submit()">
                            <option value="all" <?php selected( $filter_line_idx, 'all' ); ?>>(All Lines)</option>
                            <?php foreach ( $available_lines as $idx => $line ): ?>
                                <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$filter_line_idx, (string)$idx ); ?>>
                                    <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <div style="flex: 2; min-width: 200px;">
                    <label for="search_shooter" style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Search Archer Name</label>
                    <div style="display:flex; gap:5px; align-items:center;">
                        <input type="text" name="search_shooter" id="search_shooter" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Type archer name..." autocomplete="off" style="width:100%; padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; height:32px; box-sizing:border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height:32px;" onclick="document.getElementById('search_shooter').value=''; document.getElementById('search_shooter').dispatchEvent(new Event('input'));">Clear</button>
                    </div>
                </div>

                <?php if ( $filter_line_idx !== 'all' && ! empty( $filtered_registrations ) ): ?>
                    <div style="flex: 0 0 auto;">
                        <form method="post" style="margin: 0;" onsubmit="return confirm('Are you sure you want to clear ALL archers from this line?');">
                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                            <input type="hidden" name="sywat_action" value="purge_line">
                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                            <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
                            <input type="hidden" name="redirect_action" value="manage">
                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Clear Line Roster</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Archer Roster Table -->
            <?php if ( empty( $filtered_registrations ) ): ?>
                <p style="color: #646970; font-style: italic; font-size: 13px; margin: 20px 0;">No archers found matching your filter criteria.</p>
            <?php else: ?>
                <div class="sywat-table-responsive">
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Shooter Name</th>
                                <th style="text-align:center;">Type / Payment</th>
                                <th style="text-align:left;">Line</th>
                                <th style="text-align:center;">Distance</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $filtered_registrations as $reg ): ?>
                                <?php 
                                    $reg_id      = isset( $reg['id'] ) ? $reg['id'] : '';
                                    $badge_type  = ! empty( $reg['is_walkin'] ) || (isset($reg['type']) && $reg['type'] === 'Walk-In') ? 'Walk-In' : 'Pre-Reg';
                                    $badge_class = ( $badge_type === 'Walk-In' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg';

                                    $paid_status = isset($reg['paid']) ? $reg['paid'] : '';
                                    $fam_label = '';
                                    if ($paid_status === 'Family' && !empty($reg['family_id'])) {
                                        $lidx = sywat_roster_resolve_line_idx($reg, $available_lines);
                                        if (isset($existing_families_by_line[$lidx][$reg['family_id']])) {
                                            $fam_label = $existing_families_by_line[$lidx][$reg['family_id']];
                                        }
                                    }
                                ?>
                                <tr class="sywat-manage-row" data-name="<?php echo esc_attr(strtolower($reg['name'])); ?>">
                                    <td>
                                        <strong><?php echo esc_html( $reg['name'] ); ?></strong>
                                        <?php if (!empty($fam_label)): ?>
                                            <div style="font-size:11px; color:#155724; background:#d4edda; display:inline-block; padding:1px 6px; border-radius:4px; margin-top:2px;">
                                                🟢 <?php echo esc_html($fam_label); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="sywat-badge <?php echo $badge_class; ?>" style="margin-bottom:3px;"><?php echo esc_html( $badge_type ); ?></span>
                                        <?php if ($paid_status === 'Family'): ?>
                                            <br><span style="font-size:10px; font-weight:bold; color:#0284c7; background:#e0f2fe; padding:2px 4px; border-radius:3px;">Family Cap</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( isset( $reg['line_name'] ) ? $reg['line_name'] : 'Line 1' ); ?></td>
                                    <td style="text-align:center;"><?php echo esc_html( isset( $reg['distance'] ) ? $reg['distance'] : '20yd' ); ?></td>
                                    <td style="text-align:right;">
                                        <div style="display:inline-flex; gap:6px; justify-content:flex-end;">
                                            <!-- Edit Button Link -->
                                            <a href="?tab=manage&view_tournament=<?php echo urlencode( $selected_tournament ); ?>&modal_action=manage&edit_archer=<?php echo esc_attr( $reg_id ); ?>&filter_line_idx=<?php echo esc_attr( $filter_line_idx ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit" style="padding: 4px 10px; font-size: 12px; height: auto;">Edit</a>

                                            <!-- Remove Form -->
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Are you sure you want to remove <?php echo esc_js( $reg['name'] ); ?>?');">
                                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                <input type="hidden" name="sywat_action" value="delete_shooter">
                                                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="registration_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="padding: 4px 10px; font-size: 12px; height: auto;">Remove</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- EDIT ARCHER POPUP MODAL OVERLAY -->
        <div class="sywat-modal-backdrop sywat-nested-modal <?php echo $open_edit_modal ? 'active' : ''; ?>" id="sywat_edit_archer_modal" style="z-index: 100050;">
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header">
                    <h3 style="margin: 0;">Edit Archer Registration</h3>
                    <button type="button" class="sywat-modal-close" onclick="closeEditArcherModal()">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <?php if ( $target_archer ): ?>
                        <?php 
                            $curr_line = isset($target_archer['line_index']) ? $target_archer['line_index'] : '0'; 
                            $curr_fam_id = isset($target_archer['family_id']) ? $target_archer['family_id'] : '';
                            $modal_line_families = isset($existing_families_by_line[$curr_line]) ? $existing_families_by_line[$curr_line] : array();
                        ?>
                        <form method="post" action="?tab=manage&view_tournament=<?php echo urlencode($selected_tournament); ?>&modal_action=manage">
                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                            <input type="hidden" name="sywat_action" value="save_edit">
                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                            <input type="hidden" name="modal_action" value="manage">
                            <input type="hidden" name="registration_id" value="<?php echo esc_attr( $target_archer['id'] ); ?>">

                            <div class="sywat-form-grid-2" style="margin-bottom:15px;">
                                <div class="sywat-full-width">
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Full Name</label>
                                    <input type="text" name="edit_name" value="<?php echo esc_attr($target_archer['name']); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Shooting Line</label>
                                    <select name="edit_line_index" id="edit_line_index" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required onchange="updateRosterEditFamilyOptions(this.value)">
                                        <?php foreach ($available_lines as $idx => $line): ?>
                                            <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$curr_line, (string)$idx); ?>><?php echo esc_html(isset($line['name']) ? $line['name'] : 'Line ' . ($idx + 1)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Division</label>
                                    <select name="edit_division" id="edit_division" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required onchange="handleDivisionChange('edit');">
                                        <?php $curr_div = isset($target_archer['division']) ? $target_archer['division'] : 'Adult (18 - 49)';
                                        foreach ($divs as $d) echo '<option value="' . esc_attr($d) . '" ' . selected($curr_div, $d, false) . '>' . esc_html($d) . '</option>'; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Gender</label>
                                    <select name="edit_gender" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required>
                                        <?php $curr_gen = isset($target_archer['gender']) ? $target_archer['gender'] : 'Male';
                                        foreach ($genders as $g) echo '<option value="' . esc_attr($g) . '" ' . selected($curr_gen, $g, false) . '>' . esc_html($g) . '</option>'; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Style</label>
                                    <select name="edit_style" id="edit_style" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required>
                                        <?php $curr_sty = isset($target_archer['style']) ? $target_archer['style'] : '';
                                        foreach ($sywat_styles as $s) echo '<option value="' . esc_attr($s) . '" ' . selected($curr_sty, $s, false) . '>' . esc_html($s) . '</option>'; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Paid Option</label>
                                    <select name="edit_pay_option" id="edit_pay_option" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required onchange="updatePaidStatusDisplay('edit');">
                                        <?php $curr_paid = isset($target_archer['paid']) ? $target_archer['paid'] : '';
                                        $curr_pay_opt = ($curr_paid === 'Family') ? 'Family' : (($curr_paid === 'Field Governor') ? 'Field Governor' : 'Individual'); ?>
                                        <option value="Individual" <?php selected($curr_pay_opt, 'Individual'); ?>>Individual</option>
                                        <option value="Family" <?php selected($curr_pay_opt, 'Family'); ?>>Family</option>
                                        <option value="Field Governor" <?php selected($curr_pay_opt, 'Field Governor'); ?>>Field Governor</option>
                                    </select>
                                </div>
                                <div id="edit_family_group_box" style="<?php echo ($curr_pay_opt === 'Family') ? '' : 'display:none;'; ?>">
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Assign to Line Family Group</label>
                                    <select name="edit_family_group_id" id="edit_family_group_id" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                                        <option value="">[ + Start New Family Group for This Line ]</option>
                                        <?php foreach ($modal_line_families as $fid => $flabel) echo '<option value="' . esc_attr($fid) . '" ' . selected($curr_fam_id, $fid, false) . '>' . esc_html($flabel) . '</option>'; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Balance Due ($)</label>
                                    <input type="number" step="0.01" min="0" name="edit_balance_due" value="<?php echo esc_attr(isset($target_archer['balance_due']) ? $target_archer['balance_due'] : 0); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Distance (Calculated)</label>
                                    <input type="text" id="edit_distance_display" value="<?php echo ($curr_div === 'Cub (8 - 11)') ? 'Cub' : '20yd'; ?>" disabled style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box; background:#f9f9f9; font-weight:bold;">
                                </div>
                            </div>

                            <div style="background:#e5f5fa; padding:10px; border-radius:4px; border:1px solid #bfe7f3; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
                                <span style="font-size:13px; color:#0073aa; font-weight:600;">Calculated Paid Status:</span>
                                <span id="edit_paid_status_display" style="font-size:15px; font-weight:bold; color:#1d2327;"><?php echo esc_html($curr_paid ?: 'Adult'); ?></span>
                            </div>

                            <div style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="sywat-btn sywat-btn-secondary" onclick="closeEditArcherModal()">Cancel</button>
                                <button type="submit" class="sywat-btn sywat-btn-success" style="padding: 8px 16px;">Save Changes</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        const existingFamiliesByLine = <?php echo json_encode($existing_families_by_line); ?>;

        function updateRosterEditFamilyOptions(lineIdx) {
            const famSelect = document.getElementById('edit_family_group_id');
            if (!famSelect) return;

            famSelect.innerHTML = '<option value="">[ + Start New Family Group for This Line ]</option>';

            const fams = (existingFamiliesByLine && existingFamiliesByLine[lineIdx]) ? existingFamiliesByLine[lineIdx] : {};

            Object.keys(fams).forEach(fid => {
                const opt = document.createElement('option');
                opt.value = fid;
                opt.textContent = fams[fid];
                famSelect.appendChild(opt);
            });
        }

        function closeEditArcherModal() {
            const modal = document.getElementById('sywat_edit_archer_modal');
            if (modal) modal.classList.remove('active');
            const url = new URL(window.location.href);
            url.searchParams.delete('edit_archer');
            url.searchParams.delete('open_edit_id');
            window.history.replaceState({}, document.title, url.toString());
        }

        function updatePaidStatusDisplay(type) {
            const payOption  = document.getElementById(type + '_pay_option');
            const division   = document.getElementById(type + '_division');
            const displayEl  = document.getElementById(type + '_paid_status_display');
            const familyBox  = document.getElementById(type + '_family_group_box');
            if(!payOption || !division || !displayEl) return;
            let calculatedStatus = 'Adult';
            if (payOption.value === 'Family') {
                calculatedStatus = 'Family';
                if (familyBox) familyBox.style.display = '';
            } else {
                if (familyBox) familyBox.style.display = 'none';
                if (payOption.value === 'Field Governor') {
                    calculatedStatus = 'Field Governor';
                } else {
                    const youthDivs = ['Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)'];
                    calculatedStatus = youthDivs.includes(division.value) ? 'Youth' : 'Adult';
                }
            }
            displayEl.textContent = calculatedStatus;
        }

        function handleStyleRestrictions(type) {
            const divisionEl = document.getElementById(type + '_division');
            const styleEl    = document.getElementById(type + '_style');
            if (!divisionEl || !styleEl) return;
            const currentDiv = divisionEl.value;
            for (let i = 0; i < styleEl.options.length; i++) {
                const opt = styleEl.options[i];
                const val = opt.value;
                let isDisabled = false;
                if (val.indexOf('Genesis') !== -1 && currentDiv !== 'Cub (8 - 11)') isDisabled = true;
                const isFSPro = val.indexOf('FS Pro') !== -1;
                const isFSL   = val.indexOf('FSL –') !== -1;
                const isTRAD  = val.indexOf('TRAD') !== -1 || val.toLowerCase().indexOf('traditional') !== -1;
                if ((isFSPro || isFSL || isTRAD) && !['Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)'].includes(currentDiv)) {
                    isDisabled = true;
                }
                const isLB = val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1;
                if (isLB && currentDiv !== 'Adult (18 - 49)') isDisabled = true;
                opt.disabled = isDisabled;
                if (isDisabled && styleEl.value === val) styleEl.value = 'FS – Freestyle';
            }
        }

        function handleDivisionChange(type) {
            const divisionEl = document.getElementById(type + '_division');
            const distDispEl = document.getElementById(type + '_distance_display');
            if (!divisionEl) return;
            const calculatedDist = (divisionEl.value === 'Cub (8 - 11)') ? 'Cub' : '20yd';
            if (distDispEl) distDispEl.value = calculatedDist;
            updatePaidStatusDisplay(type);
            handleStyleRestrictions(type);
        }

        document.addEventListener("DOMContentLoaded", function() {
            if (document.getElementById('edit_division')) handleDivisionChange('edit');
            const manageSearchInput = document.getElementById('search_shooter');
            if (manageSearchInput) {
                manageSearchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase();
                    document.querySelectorAll('.sywat-manage-row').forEach(row => {
                        const name = row.getAttribute('data-name');
                        row.style.display = (name && name.includes(filter)) ? '' : 'none';
                    });
                });
            }
        });
        </script>
        <?php
    }
}
