<?php
/**
 * Sub-Module: Archer Roster Views Renderer
 * Path: includes/roster/roster-views.php
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Component Renderer: Archer Roster Management Table & Controls
 */
if ( ! function_exists( 'sywat_render_roster_modal' ) ) {
    function sywat_render_roster_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $search_query, $modal_edit_archer = '', $tournaments = array() ) {
        
        // Build list of tournaments for switcher dropdown
        $tourn_options = array();
        if ( is_array( $tournaments ) && ! empty( $tournaments ) ) {
            foreach ( $tournaments as $t_key => $t_val ) {
                $t_name = is_string( $t_key ) ? $t_key : ( is_string( $t_val ) ? $t_val : '' );
                if ( ! empty( $t_name ) ) {
                    $tourn_options[$t_name] = $t_name;
                }
            }
        }
        if ( empty( $tourn_options ) && ! empty( $selected_tournament ) ) {
            $tourn_options[$selected_tournament] = $selected_tournament;
        }

        // STRICT FILTER: Build Line-Scoped & Smart-Named Family Groups array for JS dynamic dropdowns & labels
        $existing_families_by_line = array();
        $family_members_by_id      = array();

        foreach ( $registrations as $r ) {
            $fid = ! empty( $r['family_id'] ) ? trim( $r['family_id'] ) : '';
            if ( empty( $fid ) ) continue;

            $is_fam = ( isset( $r['paid'] ) && strcasecmp( $r['paid'], 'Family' ) === 0 )
                   || ( ! empty( $r['rate_type'] ) && stripos( $r['rate_type'], 'family' ) !== false );

            if ( ! $is_fam ) continue;

            $resolved_l_idx = function_exists( 'sywat_roster_resolve_line_idx' ) 
                ? sywat_roster_resolve_line_idx( $r, $available_lines ) 
                : ( isset( $r['line_index'] ) ? (string)$r['line_index'] : '0' );

            if ( ! isset( $family_members_by_id[$fid] ) ) {
                $family_members_by_id[$fid] = array(
                    'line_idx'    => $resolved_l_idx,
                    'family_name' => ! empty( $r['family_name'] ) ? trim( $r['family_name'] ) : '',
                    'names'       => array(),
                );
            }
            if ( ! empty( $r['name'] ) ) {
                $family_members_by_id[$fid]['names'][] = trim( $r['name'] );
            }
        }

        foreach ( $family_members_by_id as $fid => $fam_info ) {
            $l_idx     = $fam_info['line_idx'];
            $raw_fname = $fam_info['family_name'];
            $names     = $fam_info['names'];

            if ( ! empty( $raw_fname ) && stripos( $raw_fname, 'fam_' ) === false && stripos( $raw_fname, 'Booking' ) === false && strlen( $raw_fname ) > 2 ) {
                $label = $raw_fname;
            } elseif ( ! empty( $names ) ) {
                if ( count( $names ) == 1 ) {
                    $label = $names[0] . ' Group';
                } elseif ( count( $names ) <= 3 ) {
                    $last_names = array_map( function( $n ) {
                        $p = explode( ' ', trim( $n ) );
                        return end( $p );
                    }, $names );
                    $label = implode( ' / ', array_unique( $last_names ) ) . ' Group';
                } else {
                    $p = explode( ' ', trim( $names[0] ) );
                    $label = end( $p ) . ' Family Group (' . count( $names ) . ' Archers)';
                }
            } else {
                $label = 'Family Group (' . substr( $fid, -6 ) . ')';
            }

            if ( ! isset( $existing_families_by_line[$l_idx] ) ) {
                $existing_families_by_line[$l_idx] = array();
            }
            $existing_families_by_line[$l_idx][$fid] = $label;
        }

        // Filter registrations for this tournament & line filter
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

        // Enqueue Client Logic & Localize Family Groups
        if ( function_exists( 'sywat_render_roster_scripts' ) ) {
            sywat_render_roster_scripts( $existing_families_by_line );
        }
        ?>

        <div class="no-print">
            <!-- Inline Filter & Search Controls Header -->
            <div class="sywat-roster-filter-bar">
                <form method="get" class="sywat-roster-filter-form">
                    <input type="hidden" name="tab" value="manage">
                    <input type="hidden" name="modal_action" value="manage">

                    <div class="sywat-field-group">
                        <label for="view_tournament">Active Tournament</label>
                        <select name="view_tournament" id="view_tournament" onchange="this.form.submit()">
                            <?php foreach ( $tourn_options as $t_name ): ?>
                                <option value="<?php echo esc_attr( $t_name ); ?>" <?php selected( $selected_tournament, $t_name ); ?>>
                                    <?php echo esc_html( $t_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label for="filter_line_idx">Filter by Line</label>
                        <select name="filter_line_idx" id="filter_line_idx" onchange="this.form.submit()">
                            <option value="all" <?php selected( $filter_line_idx, 'all' ); ?>>(All Lines)</option>
                            <?php foreach ( $available_lines as $idx => $line ): ?>
                                <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$filter_line_idx, (string)$idx ); ?>>
                                    <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group sywat-col-roster-line">
                        <label for="search_shooter">Search Archer Name</label>
                        <div class="sywat-search-input-group">
                            <input type="text" name="search_shooter" id="search_shooter" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Type archer name..." autocomplete="off">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="document.getElementById('search_shooter').value=''; document.getElementById('search_shooter').dispatchEvent(new Event('input'));">Clear</button>
                        </div>
                    </div>
                </form>

                <?php if ( $filter_line_idx !== 'all' && ! empty( $filtered_registrations ) ): ?>
                    <div class="sywat-field-group">
                        <form method="post" onsubmit="return confirm('Are you sure you want to clear ALL archers from this line?');">
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
                <p class="sywat-label-subtext">No archers found matching your filter criteria.</p>
            <?php else: ?>
                <div class="sywat-table-responsive">
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th class="sywat-col-roster-name">Shooter Name</th>
                                <th class="sywat-col-roster-type">Type / Payment</th>
                                <th class="sywat-col-roster-line">Line</th>
                                <th class="sywat-col-roster-dist">Distance</th>
                                <th class="sywat-col-roster-action">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $filtered_registrations as $reg ): ?>
                                <?php 
                                    $reg_id      = isset( $reg['id'] ) ? $reg['id'] : '';
                                    $badge_type  = ! empty( $reg['is_walkin'] ) || (isset($reg['type']) && $reg['type'] === 'Walk-In') ? 'Walk-In' : 'Pre-Reg';
                                    $badge_class = ( $badge_type === 'Walk-In' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg';

                                    $paid_status = isset($reg['paid']) ? $reg['paid'] : '';
                                    $lidx        = function_exists( 'sywat_roster_resolve_line_idx' ) ? sywat_roster_resolve_line_idx($reg, $available_lines) : (isset($reg['line_index']) ? (string)$reg['line_index'] : '0');

                                    $fam_label = '';
                                    if ($paid_status === 'Family' && !empty($reg['family_id'])) {
                                        if (isset($existing_families_by_line[$lidx][$reg['family_id']])) {
                                            $fam_label = $existing_families_by_line[$lidx][$reg['family_id']];
                                        }
                                    }

                                    $line_disp = isset( $available_lines[$lidx]['name'] ) ? $available_lines[$lidx]['name'] : ( ! empty( $reg['line_name'] ) ? $reg['line_name'] : 'Line 1' );
                                ?>
                                <tr class="sywat-manage-row" data-name="<?php echo esc_attr(strtolower($reg['name'])); ?>">
                                    <td>
                                        <strong><?php echo esc_html( $reg['name'] ); ?></strong>
                                        <?php if (!empty($fam_label)): ?>
                                            <div class="sywat-chip-family">
                                                🟢 <?php echo esc_html($fam_label); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sywat-col-center">
                                        <span class="sywat-badge <?php echo $badge_class; ?>"><?php echo esc_html( $badge_type ); ?></span>
                                        <?php if ($paid_status === 'Family'): ?>
                                            <br><span class="sywat-badge-family-cap">Family Cap</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $line_disp ); ?></td>
                                    <td class="sywat-col-center"><?php echo esc_html( isset( $reg['distance'] ) ? $reg['distance'] : '20yd' ); ?></td>
                                    <td class="sywat-col-roster-action">
                                        <div class="sywat-modal-roster-actions">
                                            <!-- Edit Button Link -->
                                            <a href="?tab=manage&view_tournament=<?php echo urlencode( $selected_tournament ); ?>&modal_action=manage&edit_archer=<?php echo esc_attr( $reg_id ); ?>&filter_line_idx=<?php echo esc_attr( $filter_line_idx ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit</a>

                                            <!-- Remove Form -->
                                            <form method="post" class="sywat-table-action-form" onsubmit="return confirm('Are you sure you want to remove <?php echo esc_js( $reg['name'] ); ?>?');">
                                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                <input type="hidden" name="sywat_action" value="delete_shooter">
                                                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="registration_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Remove</button>
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

        <!-- Render Edit Archer Modal Component -->
        <?php 
        if ( function_exists( 'sywat_render_edit_archer_modal' ) ) {
            sywat_render_edit_archer_modal( $target_archer, $selected_tournament, $available_lines, $existing_families_by_line );
        }
        ?>
        <?php
    }
}
