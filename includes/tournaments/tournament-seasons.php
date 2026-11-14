<?php
/**
 * Sub-Module: Season Management & Taxonomies View Renderer
 * Path: includes/tournaments/tournament-seasons.php
 * Description: Renders the Manage Seasons tab and Add/Edit Season modal with configurable Pricing/Round Formats and Season-level Promotional Free Entry toggles.
 * Compliance: Pure view layer — zero direct SQL queries, zero inline style attributes, zero embedded script blocks, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Note: sywat_get_seasons() and sywat_get_active_season() are housed in includes/tournaments/tournament-data.php (Data Layer).

/**
 * Render Season Management Tab UI
 */
if ( ! function_exists( 'sywat_render_seasons_tab' ) ) {
    function sywat_render_seasons_tab() {
        if ( ! function_exists( 'sywat_can_manage_seasons' ) || ! sywat_can_manage_seasons() ) {
            echo '<div class="sywat-alert sywat-alert-danger">Access denied. TFAA Administrator privileges required to manage seasons.</div>';
            return;
        }

        $seasons           = function_exists( 'sywat_get_seasons' ) ? sywat_get_seasons() : array();
        $pricing_profiles  = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( false ) : array();
        $edit_slug         = isset( $_REQUEST['edit_season'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['edit_season'] ) ) : '';
        $edit_season       = ( ! empty( $edit_slug ) && isset( $seasons[ $edit_slug ] ) ) ? $seasons[ $edit_slug ] : null;
        $open_season_modal = ! empty( $edit_season ) || isset( $_REQUEST['open_add_season'] );

        // Resolve checked pricing format slugs for the Add/Edit Season modal
        $selected_profile_slugs = array();
        if ( is_array( $edit_season ) && function_exists( 'sywat_get_season_pricing_profiles' ) ) {
            $resolved_profs         = sywat_get_season_pricing_profiles( $edit_season );
            $selected_profile_slugs = array_keys( $resolved_profs );
        } elseif ( function_exists( 'sywat_get_default_pricing_profile' ) ) {
            $def_prof = sywat_get_default_pricing_profile();
            if ( ! empty( $def_prof['slug'] ) ) {
                $selected_profile_slugs = array( $def_prof['slug'] );
            }
        }

        $edit_season_type = is_array( $edit_season ) && isset( $edit_season['type'] ) ? $edit_season['type'] : '';
        $standard_types   = array( 'Indoor', 'Outdoor', 'Field 50' );
        $has_custom_type  = ( $edit_season_type !== '' && ! in_array( $edit_season_type, $standard_types, true ) );
        $promo_checked    = is_array( $edit_season ) && ! empty( $edit_season['promo_enabled'] );
        ?>

        <!-- Master Single Unified Desk Card -->
        <div class="sywat-card no-print">
            <!-- Integrated Top Header Bar -->
            <div class="sywat-card-header-bar">
                <div>
                    <h2>🏆 Manage Seasons</h2>
                    <div class="sywat-subtitle">Configure SYWAT competition seasons, enabled round pricing formats, promotional entry rules, and active aggregate score rules.</div>
                </div>

                <div class="sywat-header-inline-controls">
                    <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="openAddSeasonModal()">Add New Season</button>
                </div>
            </div>

            <!-- Table Sub-Header Row (Title Left, Search Right) -->
            <div class="sywat-table-header-bar">
                <h2>All Seasons</h2>

                <div class="sywat-table-header-controls">
                    <div class="sywat-field-group">
                        <input type="text" id="sywat_season_search_input" placeholder="Search seasons..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                    </div>
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="const input = document.getElementById('sywat_season_search_input'); if (input) { input.value=''; input.dispatchEvent(new Event('input')); }">Clear</button>
                </div>
            </div>

            <!-- Seasons Data Table -->
            <div class="sywat-table-responsive">
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th>Season Name</th>
                            <th class="sywat-col-center">Type</th>
                            <th>Enabled Pricing Formats</th>
                            <th class="sywat-col-center">Promoter Free Entries</th>
                            <th class="sywat-col-center">Season Duration</th>
                            <th class="sywat-col-center">Best-N Rule</th>
                            <th class="sywat-col-center">Status</th>
                            <th class="sywat-col-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $seasons as $slug => $s ) : ?>
                            <?php
                                $s_start     = ! empty( $s['start_date'] ) ? date( 'M j, Y', strtotime( $s['start_date'] ) ) : '—';
                                $s_end       = ! empty( $s['end_date'] ) ? date( 'M j, Y', strtotime( $s['end_date'] ) ) : '—';
                                $s_profs     = function_exists( 'sywat_get_season_pricing_profiles' ) ? sywat_get_season_pricing_profiles( $s ) : array();
                                $prof_labels = array();
                                foreach ( $s_profs as $sp ) {
                                    $prof_labels[] = $sp['label'];
                                }
                                $s_promo_on  = ! empty( $s['promo_enabled'] );
                                $search_data = strtolower( $s['name'] . ' ' . $s['type'] . ' ' . implode( ' ', $prof_labels ) . ' ' . ( isset( $s['year_label'] ) ? $s['year_label'] : '' ) );
                            ?>
                            <tr class="sywat-season-row" data-search="<?php echo esc_attr( $search_data ); ?>">
                                <td>
                                    <strong><?php echo esc_html( $s['name'] ); ?></strong>
                                    <?php if ( ! empty( $s['year_label'] ) ) : ?>
                                        <div class="sywat-label-subtext"><?php echo esc_html( $s['year_label'] ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-center"><span class="sywat-badge"><?php echo esc_html( $s['type'] ); ?></span></td>
                                <td>
                                    <?php if ( ! empty( $prof_labels ) ) : ?>
                                        <?php foreach ( $prof_labels as $p_lbl ) : ?>
                                            <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $p_lbl ); ?></span>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <span class="sywat-label-subtext">Default Pricing</span>
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-center">
                                    <?php if ( $s_promo_on ) : ?>
                                        <span class="sywat-badge sywat-badge-open">Enabled</span>
                                    <?php else : ?>
                                        <span class="sywat-label-subtext">Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-center sywat-text-muted-sm"><?php echo esc_html( $s_start . ' – ' . $s_end ); ?></td>
                                <td class="sywat-col-center">Best <strong><?php echo intval( $s['best_n_count'] ); ?></strong> Scores</td>
                                <td class="sywat-col-center">
                                    <?php if ( $s['status'] === 'active' ) : ?>
                                        <span class="sywat-text-active">🟢 Active</span>
                                    <?php elseif ( $s['status'] === 'upcoming' ) : ?>
                                        <span class="sywat-text-upcoming">🔵 Upcoming</span>
                                    <?php else : ?>
                                        <span class="sywat-text-archived">⚪ Archived</span>
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
                    <h3><?php echo $edit_season ? 'Edit Season: ' . esc_html( $edit_season['name'] ) : 'Create New Season'; ?></h3>
                    <button type="button" class="sywat-modal-close" onclick="closeAddSeasonModal()">&times;</button>
                </div>
                <form method="post" action="?tab=seasons">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="save_season">
                        <input type="hidden" name="original_season_slug" value="<?php echo esc_attr( $edit_slug ); ?>">

                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label>Season Type *</label>
                                <select name="season_type" required>
                                    <option value="" <?php selected( empty( $edit_season_type ), true ); ?>>Select Season Type</option>
                                    <option value="Indoor" <?php selected( $edit_season_type, 'Indoor' ); ?>>Indoor</option>
                                    <option value="Outdoor" <?php selected( $edit_season_type, 'Outdoor' ); ?>>Outdoor</option>
                                    <option value="Field 50" <?php selected( $edit_season_type, 'Field 50' ); ?>>Field 50</option>
                                    <?php if ( $has_custom_type ) : ?>
                                        <option value="<?php echo esc_attr( $edit_season_type ); ?>" selected><?php echo esc_html( $edit_season_type ); ?></option>
                                    <?php endif; ?>
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

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label>Start Date *</label>
                                <input type="date" name="start_date" value="<?php echo esc_attr( $edit_season ? ( isset( $edit_season['start_date'] ) ? $edit_season['start_date'] : '' ) : '' ); ?>" required>
                            </div>
                            <div class="sywat-field-group">
                                <label>End Date *</label>
                                <input type="date" name="end_date" value="<?php echo esc_attr( $edit_season ? ( isset( $edit_season['end_date'] ) ? $edit_season['end_date'] : '' ) : '' ); ?>" required>
                            </div>
                            <div class="sywat-field-group">
                                <label>Top Qualifier Scores (Best N)</label>
                                <input type="number" name="season_best_n" value="<?php echo esc_attr( $edit_season ? $edit_season['best_n_count'] : 3 ); ?>" min="1" max="10" required>
                            </div>
                        </div>

                        <?php if ( ! empty( $pricing_profiles ) ) : ?>
                            <div class="sywat-field-group sywat-mt-3">
                                <label>Enabled Pricing &amp; Round Formats * <span class="sywat-label-subtext">(Select all round formats active during this season)</span></label>
                                <div class="sywat-form-grid-2">
                                    <?php foreach ( $pricing_profiles as $p_slug => $p_rec ) : ?>
                                        <?php
                                            $is_checked   = in_array( $p_slug, $selected_profile_slugs, true );
                                            $rate_summary = function_exists( 'sywat_format_pricing_profile_summary' )
                                                ? sywat_format_pricing_profile_summary( $p_rec )
                                                : '';
                                        ?>
                                        <label>
                                            <input type="checkbox"
                                                   name="season_pricing_profiles[]"
                                                   value="<?php echo esc_attr( $p_slug ); ?>"
                                                   <?php checked( $is_checked ); ?>>
                                            <strong><?php echo esc_html( $p_rec['label'] ); ?></strong>
                                            <?php if ( ! empty( $p_rec['round_subtitle'] ) ) : ?>
                                                <span class="sywat-label-subtext">(<?php echo esc_html( $p_rec['round_subtitle'] ); ?>)</span>
                                            <?php endif; ?>
                                            <?php if ( $rate_summary !== '' ) : ?>
                                                <div class="sywat-label-subtext"><?php echo esc_html( $rate_summary ); ?></div>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="sywat-field-group sywat-mt-3">
                            <label>
                                <input type="checkbox" name="season_promo_enabled" value="1" <?php checked( $promo_checked ); ?>>
                                <strong>Enable Promotional Free Entries for this Season</strong>
                            </label>
                            <span class="sywat-meta-subtext">When enabled, formats configured with a First-Time / Lapsed Shooter Promoter rule (such as Field 50) will automatically grant $0.00 promotional entry to qualifying shooters up to that format's configured usage limit.</span>
                        </div>

                        <div class="sywat-notice-box sywat-mt-3 sywat-mb-4">
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
        <?php
    }
}
