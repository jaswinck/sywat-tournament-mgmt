<?php
/**
 * Sub-Module: Season Management & Taxonomies
 * Path: includes/tournaments/tournament-seasons.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_get_seasons' ) ) {
    function sywat_get_seasons() {
        $default_seasons = array(
            'indoor-2026-2027' => array(
                'slug'         => 'indoor-2026-2027',
                'name'         => '2026-2027 Indoor',
                'type'         => 'Indoor',
                'year_label'   => '2026–2027',
                'status'       => 'active',
                'best_n_count' => 3,
            ),
            'outdoor-2026' => array(
                'slug'         => 'outdoor-2026',
                'name'         => '2026 Outdoor',
                'type'         => 'Outdoor',
                'year_label'   => '2026',
                'status'       => 'archived',
                'best_n_count' => 3,
            ),
        );

        $seasons = get_option( 'sywat_seasons_list', $default_seasons );
        return is_array( $seasons ) ? $seasons : $default_seasons;
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
            echo '<p style="color:red; padding:20px;">Access denied. TFAA Administrator privileges required to manage seasons.</p>';
            return;
        }

        $seasons = sywat_get_seasons();
        $edit_slug = isset( $_REQUEST['edit_season'] ) ? sanitize_text_field( $_REQUEST['edit_season'] ) : '';
        $edit_season = ( ! empty( $edit_slug ) && isset( $seasons[$edit_slug] ) ) ? $seasons[$edit_slug] : null;
        ?>

        <div class="sywat-card no-print">
            <div class="sywat-card-title">
                <span>Season Management</span>
                <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="document.getElementById('sywat_season_form_card').scrollIntoView({behavior: 'smooth'});">Add New Season</button>
            </div>
            <div class="sywat-subtitle">Configure SYWAT Indoor/Outdoor competition seasons and set active aggregate score rules.</div>

            <table class="sywat-table" style="margin-bottom: 30px;">
                <thead>
                    <tr>
                        <th>Season Name</th>
                        <th style="text-align: center;">Type</th>
                        <th style="text-align: center;">Years</th>
                        <th style="text-align: center;">Best-N Qualifier Rule</th>
                        <th style="text-align: center;">Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $seasons as $slug => $s ): ?>
                        <tr>
                            <td><strong><?php echo esc_html( $s['name'] ); ?></strong></td>
                            <td style="text-align: center;"><span class="sywat-badge"><?php echo esc_html( $s['type'] ); ?></span></td>
                            <td style="text-align: center;"><?php echo esc_html( $s['year_label'] ); ?></td>
                            <td style="text-align: center;">Best <strong><?php echo intval( $s['best_n_count'] ); ?></strong> Scores</td>
                            <td style="text-align: center;">
                                <?php if ( $s['status'] === 'active' ): ?>
                                    <span style="color: #008a20; font-weight: bold;">🟢 Active</span>
                                <?php elseif ( $s['status'] === 'upcoming' ): ?>
                                    <span style="color: #2271b1; font-weight: bold;">🔵 Upcoming</span>
                                <?php else: ?>
                                    <span style="color: #646970; font-weight: bold;">⚪ Archived</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <a href="?tab=seasons&edit_season=<?php echo urlencode( $slug ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit" style="padding: 4px 8px; font-size: 11px;">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Add / Edit Season Form -->
            <div id="sywat_season_form_card" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 20px;">
                <h3 style="margin-top: 0; font-size: 15px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                    <?php echo $edit_season ? 'Edit Season: ' . esc_html($edit_season['name']) : 'Add New Season'; ?>
                </h3>
                <form method="post" action="?tab=seasons">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_action" value="save_season">
                    <input type="hidden" name="original_season_slug" value="<?php echo esc_attr( $edit_slug ); ?>">

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Season Name *</label>
                            <input type="text" name="season_name" value="<?php echo esc_attr( $edit_season ? $edit_season['name'] : '' ); ?>" placeholder="e.g. 2026-2027 Indoor" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;" required>
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Season Type *</label>
                            <select name="season_type" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; height:37px;" required>
                                <option value="Indoor" <?php selected( $edit_season ? $edit_season['type'] : 'Indoor', 'Indoor' ); ?>>Indoor (Oct – Mar)</option>
                                <option value="Outdoor" <?php selected( $edit_season ? $edit_season['type'] : '', 'Outdoor' ); ?>>Outdoor (Apr – Jul)</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Year Label *</label>
                            <input type="text" name="season_years" value="<?php echo esc_attr( $edit_season ? $edit_season['year_label'] : '2026–2027' ); ?>" placeholder="e.g. 2026-2027" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Season Status *</label>
                            <select name="season_status" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; height:37px;" required>
                                <option value="active" <?php selected( $edit_season ? $edit_season['status'] : 'active', 'active' ); ?>>Active (Current Active Season)</option>
                                <option value="upcoming" <?php selected( $edit_season ? $edit_season['status'] : '', 'upcoming' ); ?>>Upcoming</option>
                                <option value="archived" <?php selected( $edit_season ? $edit_season['status'] : '', 'archived' ); ?>>Archived</option>
                            </select>
                            <span style="font-size: 11px; color: #646970;">Setting a season to Active automatically archives previous active seasons.</span>
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Top Qualifier Scores Count (Best N)</label>
                            <input type="number" name="season_best_n" value="<?php echo esc_attr( $edit_season ? $edit_season['best_n_count'] : 3 ); ?>" min="1" max="10" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;" required>
                            <span style="font-size: 11px; color: #646970;">Number of highest qualifier scores kept in season aggregate (Default: 3).</span>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px;">
                        <?php if ( $edit_season ): ?>
                            <a href="?tab=seasons" class="sywat-btn sywat-btn-secondary">Cancel Edit</a>
                        <?php endif; ?>
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Season Configuration</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
