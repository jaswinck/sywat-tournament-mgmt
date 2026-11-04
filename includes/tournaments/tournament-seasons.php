<?php
/**
 * Sub-Module: Season Management & Taxonomies
 * Path: includes/tournaments/tournament-seasons.php
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_get_seasons' ) ) {
    function sywat_get_seasons() {
        $default_seasons = array(
            'indoor-2026-2027' => array(
                'slug'         => 'indoor-2026-2027',
                'name'         => '2026–2027 Indoor',
                'type'         => 'Indoor',
                'start_date'   => '2026-10-01',
                'end_date'     => '2027-03-31',
                'year_label'   => '2026–2027',
                'status'       => 'active',
                'best_n_count' => 3,
            ),
            'outdoor-2026' => array(
                'slug'         => 'outdoor-2026',
                'name'         => '2026 Outdoor',
                'type'         => 'Outdoor',
                'start_date'   => '2026-04-01',
                'end_date'     => '2026-07-31',
                'year_label'   => '2026',
                'status'       => 'archived',
                'best_n_count' => 3,
            ),
        );

        $seasons = get_option( 'sywat_seasons_list', $default_seasons );
        if ( ! is_array( $seasons ) ) $seasons = $default_seasons;

        // Sort seasons from newest to oldest by start date (descending)
        uasort( $seasons, function( $a, $b ) {
            $a_date = ! empty( $a['start_date'] ) ? $a['start_date'] : ( ! empty( $a['end_date'] ) ? $a['end_date'] : '' );
            $b_date = ! empty( $b['start_date'] ) ? $b['start_date'] : ( ! empty( $b['end_date'] ) ? $b['end_date'] : '' );
            if ( $a_date === $b_date ) {
                return strcasecmp( isset( $b['name'] ) ? $b['name'] : '', isset( $a['name'] ) ? $a['name'] : '' );
            }
            return ( $a_date < $b_date ) ? 1 : -1;
        });

        return $seasons;
    }
}

if ( ! function_exists( 'sywat_get_active_season' ) ) {
    function sywat_get_active_season() {
        $seasons = sywat_get_seasons();
        foreach ( $seasons as $slug => $s ) {
            if ( isset( $s['status'] ) && $s['status'] === 'active' ) {
                return $s;
            }
        }
        return reset( $seasons );
    }
}

// Render Season Management Tab UI
if ( ! function_exists( 'sywat_render_seasons_tab' ) ) {
    function sywat_render_seasons_tab() {
        if ( ! function_exists( 'sywat_can_manage_seasons' ) || ! sywat_can_manage_seasons() ) {
            echo '<div class="sywat-alert sywat-alert-danger">Access denied. TFAA Administrator privileges required to manage seasons.</div>';
            return;
        }

        $seasons = sywat_get_seasons();
        $edit_slug = isset( $_REQUEST['edit_season'] ) ? sanitize_text_field( $_REQUEST['edit_season'] ) : '';
        $edit_season = ( ! empty( $edit_slug ) && isset( $seasons[$edit_slug] ) ) ? $seasons[$edit_slug] : null;
        $open_season_modal = ! empty( $edit_season ) || isset( $_REQUEST['open_add_season'] );
        ?>

        <!-- Master Single Unified Desk Card -->
        <div class="sywat-card no-print">
            <!-- Integrated Top Header Bar -->
            <div class="sywat-card-header-bar">
                <div>
                    <h2>🏆 Manage Seasons</h2>
                    <div class="sywat-subtitle">Configure SYWAT competition seasons and set active aggregate score rules.</div>
                </div>

                <div class="sywat-header-inline-controls">
                    <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="openAddSeasonModal()">Add New Season</button>
                </div>
            </div>

            <!-- Table Sub-Header Row (Title Left, Search Right) -->
            <div class="sywat-table-header-bar">
                <h2>All Seasons</h2>

                <div style="display:flex; gap:6px; margin:0; align-items:center; flex-wrap:wrap;">
                    <div class="sywat-field-group" style="width:auto;">
                        <input type="text" id="sywat_season_search_input" placeholder="Search seasons..." autocomplete="off" style="width:180px;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                    </div>
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="document.getElementById('sywat_season_search_input').value=''; document.getElementById('sywat_season_search_input').dispatchEvent(new Event('input'));">Clear</button>
                </div>
            </div>

            <!-- Seasons Data Table -->
            <div class="sywat-table-responsive">
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th>Season Name</th>
                            <th class="sywat-col-center">Type</th>
                            <th class="sywat-col-center">Season Duration</th>
                            <th class="sywat-col-center">Calculated Year</th>
                            <th class="sywat-col-center">Best-N Qualifier Rule</th>
                            <th class="sywat-col-center">Status</th>
                            <th class="sywat-col-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $seasons as $slug => $s ): ?>
                            <?php 
                                $s_start = ! empty( $s['start_date'] ) ? date( 'M j, Y', strtotime( $s['start_date'] ) ) : '—';
                                $s_end   = ! empty( $s['end_date'] ) ? date( 'M j, Y', strtotime( $s['end_date'] ) ) : '—';
                                $search_data = strtolower( $s['name'] . ' ' . $s['type'] . ' ' . ( isset( $s['year_label'] ) ? $s['year_label'] : '' ) );
                            ?>
                            <tr class="sywat-season-row" data-search="<?php echo esc_attr( $search_data ); ?>">
                                <td><strong><?php echo esc_html( $s['name'] ); ?></strong></td>
                                <td class="sywat-col-center"><span class="sywat-badge"><?php echo esc_html( $s['type'] ); ?></span></td>
                                <td class="sywat-col-center" style="font-size: 12px; color: #475569;"><?php echo esc_html( $s_start . ' – ' . $s_end ); ?></td>
                                <td class="sywat-col-center" style="font-weight: bold; color: #1e3a8a;"><?php echo esc_html( isset( $s['year_label'] ) ? $s['year_label'] : '—' ); ?></td>
                                <td class="sywat-col-center">Best <strong><?php echo intval( $s['best_n_count'] ); ?></strong> Scores</td>
                                <td class="sywat-col-center">
                                    <?php if ( $s['status'] === 'active' ): ?>
                                        <span style="color: #008a20; font-weight: bold;">🟢 Active</span>
                                    <?php elseif ( $s['status'] === 'upcoming' ): ?>
                                        <span style="color: #2271b1; font-weight: bold;">🔵 Upcoming</span>
                                    <?php else: ?>
                                        <span style="color: #646970; font-weight: bold;">⚪ Archived</span>
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-right">
                                    <div class="sywat-table-actions">
                                        <a href="?tab=seasons&edit_season=<?php echo urlencode( $slug ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add / Edit Season Pop-Up Modal -->
        <div class="sywat-modal-backdrop <?php echo $open_season_modal ? 'active' : ''; ?>" id="sywat_add_season_modal">
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header no-print">
                    <h3><?php echo $edit_season ? 'Edit Season: ' . esc_html($edit_season['name']) : 'Create New Season'; ?></h3>
                    <button type="button" class="sywat-modal-close" onclick="closeAddSeasonModal()">&times;</button>
                </div>
                <form method="post" action="?tab=seasons">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                        <input type="hidden" name="sywat_action" value="save_season">
                        <input type="hidden" name="original_season_slug" value="<?php echo esc_attr( $edit_slug ); ?>">

                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label>Season Type *</label>
                                <select name="season_type" required>
                                    <option value="" <?php selected( empty($edit_season['type']), true ); ?>>Select Season Type</option>
                                    <option value="Indoor" <?php selected( $edit_season ? $edit_season['type'] : '', 'Indoor' ); ?>>Indoor</option>
                                    <option value="Outdoor" <?php selected( $edit_season ? $edit_season['type'] : '', 'Outdoor' ); ?>>Outdoor</option>
                                    <option value="Field 50" <?php selected( $edit_season ? $edit_season['type'] : '', 'Field 50' ); ?>>Field 50</option>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label>Season Status *</label>
                                <select name="season_status" required>
                                    <option value="active" <?php selected( $edit_season ? $edit_season['status'] : 'active', 'active' ); ?>>Active (Current Active Season)</option>
                                    <option value="upcoming" <?php selected( $edit_season ? $edit_season['status'] : '', 'upcoming' ); ?>>Upcoming</option>
                                    <option value="archived" <?php selected( $edit_season ? $edit_season['status'] : '', 'archived' ); ?>>Archived</option>
                                </select>
                            </div>
                        </div>

                        <div class="sywat-form-grid-3" style="margin-top:12px;">
                            <div class="sywat-field-group">
                                <label>Start Date *</label>
                                <input type="date" name="start_date" value="<?php echo esc_attr( $edit_season ? (isset($edit_season['start_date']) ? $edit_season['start_date'] : '') : '' ); ?>" required>
                            </div>
                            <div class="sywat-field-group">
                                <label>End Date *</label>
                                <input type="date" name="end_date" value="<?php echo esc_attr( $edit_season ? (isset($edit_season['end_date']) ? $edit_season['end_date'] : '') : '' ); ?>" required>
                            </div>
                            <div class="sywat-field-group">
                                <label>Top Qualifier Scores (Best N)</label>
                                <input type="number" name="season_best_n" value="<?php echo esc_attr( $edit_season ? $edit_season['best_n_count'] : 3 ); ?>" min="1" max="10" required>
                            </div>
                        </div>

                        <div style="background: #edf2f7; border-left: 4px solid #3182ce; padding: 10px 12px; margin-top:15px; margin-bottom: 20px; font-size: 12px; color: #2d3748; border-radius: 0 4px 4px 0;">
                            ℹ️ <strong>Auto-Generated Name Notice:</strong> Season name and year label are created automatically based on your dates and type (e.g., <em>"2026–2027 Indoor"</em> or <em>"2026 Outdoor"</em>).
                        </div>
                    </div>

                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeAddSeasonModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Season Configuration</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function openAddSeasonModal() {
            const modal = document.getElementById('sywat_add_season_modal');
            if (modal) modal.classList.add('active');
        }

        function closeAddSeasonModal() {
            const modal = document.getElementById('sywat_add_season_modal');
            if (modal) modal.classList.remove('active');
            const url = new URL(window.location.href);
            url.searchParams.delete('edit_season');
            url.searchParams.delete('open_add_season');
            window.history.replaceState({}, document.title, url.toString());
        }

        document.addEventListener("DOMContentLoaded", function() {
            const seasonSearchInput = document.getElementById('sywat_season_search_input');
            if (seasonSearchInput) {
                seasonSearchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase().trim();
                    const rows = document.querySelectorAll('.sywat-season-row');
                    rows.forEach(row => {
                        const searchStr = (row.getAttribute('data-search') || '').toLowerCase();
                        row.style.display = searchStr.includes(filter) ? '' : 'none';
                    });
                });
            }
        });
        </script>
        <?php
    }
}
