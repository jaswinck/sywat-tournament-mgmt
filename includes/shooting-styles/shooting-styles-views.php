<?php
/**
 * Sub-Module: Shooting Styles & Divisions Administration Views Renderer
 * Path: includes/shooting-styles/shooting-styles-views.php
 * Description: Renders the [sywat_shooting_styles] Administration GUI for managing NFAA shooting styles, division/age restriction & force-override rules, and age divisions without code edits.
 * Compliance: Zero inline style attributes, zero embedded scripts, native modal close controls, delegated data-sywat-action attributes.
 * Author: SYWAT Archery
 * Version: 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main Shortcode Renderer for Shooting Styles & Age Divisions Administration ([sywat_shooting_styles]).
 *
 * @return string HTML rendered view buffer output.
 */
if ( ! function_exists( 'render_sywat_shooting_styles_app' ) ) {
    function render_sywat_shooting_styles_app() {
        if ( ! is_user_logged_in() || ! function_exists( 'sywat_can_manage_seasons' ) || ! sywat_can_manage_seasons() ) {
            return '<div class="sywat-alert sywat-alert-danger">Access denied. TFAA Leadership privileges are required to manage shooting styles and divisions.</div>';
        }

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();

        if ( function_exists( 'sywat_enqueue_shooting_styles_scripts' ) ) {
            sywat_enqueue_shooting_styles_scripts();
        }

        $notice = function_exists( 'sywat_shooting_styles_handle_post_actions' )
            ? sywat_shooting_styles_handle_post_actions()
            : '';

        $styles    = function_exists( 'sywat_get_shooting_styles' ) ? sywat_get_shooting_styles( true ) : array();
        $divisions = function_exists( 'sywat_get_division_records' ) ? sywat_get_division_records( true ) : array();
        $div_names = function_exists( 'sywat_shooting_styles_get_divisions' ) ? sywat_shooting_styles_get_divisions( true ) : array();

        $page_url = function_exists( 'sywat_page_url' ) ? sywat_page_url( 'shooting_styles' ) : '';
        ?>
        <div class="sywat-wrap">
            <?php echo $notice; ?>

            <!-- Master Unified Card: Shooting Styles -->
            <div class="sywat-card no-print">
                <div class="sywat-card-header-bar">
                    <div>
                        <h2>🏹 Shooting Styles &amp; Division Rules</h2>
                        <div class="sywat-subtitle">Configure NFAA shooting styles, export codes, leaderboard order, and automatic age/division enforcement rules.</div>
                    </div>

                    <div class="sywat-header-inline-controls">
                        <form method="post" action="<?php echo esc_url( $page_url ); ?>" data-sywat-confirm="Restore all shooting styles and age divisions to default NFAA settings? Custom styles or rule edits will be overwritten.">
                            <?php wp_nonce_field( 'sywat_shooting_styles_action', 'sywat_styles_nonce' ); ?>
                            <input type="hidden" name="sywat_styles_action" value="reset_nfaa_defaults">
                            <button type="submit" class="sywat-btn sywat-btn-secondary">Reset NFAA Defaults</button>
                        </form>
                        <button type="button" class="sywat-btn sywat-btn-success" data-sywat-action="open-add-style-modal">+ Add Shooting Style</button>
                    </div>
                </div>

                <!-- Sub-Header Toolbar -->
                <div class="sywat-table-header-bar">
                    <h2>Configured Shooting Styles (<?php echo intval( count( $styles ) ); ?>)</h2>

                    <div class="sywat-table-header-controls">
                        <div class="sywat-search-input-group">
                            <input type="text" id="sywat_styles_search_input" placeholder="Search styles..." autocomplete="off">
                            <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="clear-styles-search">Clear</button>
                        </div>
                    </div>
                </div>

                <!-- Shooting Styles Table -->
                <?php if ( ! empty( $styles ) ) : ?>
                    <div class="sywat-table-responsive">
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th class="sywat-col-center">Order</th>
                                    <th class="sywat-col-center">Code</th>
                                    <th>Display Name</th>
                                    <th class="sywat-col-center">Pro</th>
                                    <th>Division / Age Rule</th>
                                    <th class="sywat-col-center">Status</th>
                                    <th class="sywat-col-center sywat-col-action">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $styles as $slug => $st ) : ?>
                                    <?php
                                        $rule_mode = isset( $st['rule_mode'] ) ? $st['rule_mode'] : 'all';
                                        $is_active = ( isset( $st['status'] ) && $st['status'] === 'active' );
                                        $aliases_str = ! empty( $st['aliases'] ) && is_array( $st['aliases'] ) ? implode( ', ', $st['aliases'] ) : '';
                                        $allowed_json = wp_json_encode( ! empty( $st['allowed_divisions'] ) ? array_values( $st['allowed_divisions'] ) : array() );
                                        $search_haystack = strtolower( $st['label'] . ' ' . $st['code'] . ' ' . $aliases_str );
                                    ?>
                                    <tr class="sywat-style-config-row" data-style-search="<?php echo esc_attr( $search_haystack ); ?>">
                                        <td class="sywat-col-center"><strong><?php echo intval( $st['sort_order'] ); ?></strong></td>
                                        <td class="sywat-col-center"><code><?php echo esc_html( $st['code'] ); ?></code></td>
                                        <td>
                                            <strong><?php echo esc_html( $st['label'] ); ?></strong>
                                            <?php if ( $aliases_str !== '' ) : ?>
                                                <div class="sywat-subtitle">Aliases: <?php echo esc_html( $aliases_str ); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sywat-col-center">
                                            <?php if ( ! empty( $st['is_pro'] ) ) : ?>
                                                <span class="sywat-badge sywat-badge-walkin">PRO</span>
                                            <?php else : ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( $rule_mode === 'force' && ! empty( $st['forced_division'] ) ) : ?>
                                                <span class="sywat-badge sywat-badge-walkin">Force → <?php echo esc_html( $st['forced_division'] ); ?></span>
                                            <?php elseif ( $rule_mode === 'restrict' ) : ?>
                                                <span class="sywat-badge sywat-badge-prereg">Allowed Only:</span>
                                                <div class="sywat-subtitle"><?php echo esc_html( ! empty( $st['allowed_divisions'] ) ? implode( ', ', $st['allowed_divisions'] ) : 'None' ); ?></div>
                                            <?php else : ?>
                                                <span class="sywat-badge sywat-badge-open">All Divisions</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sywat-col-center">
                                            <?php if ( $is_active ) : ?>
                                                <span class="sywat-badge sywat-badge-open">Active</span>
                                            <?php else : ?>
                                                <span class="sywat-badge sywat-badge-full">Retired</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sywat-col-center">
                                            <div class="sywat-table-actions-vertical">
                                                <button
                                                    type="button"
                                                    class="sywat-btn sywat-btn-primary sywat-btn-full"
                                                    data-sywat-action="open-edit-style-modal"
                                                    data-slug="<?php echo esc_attr( $st['slug'] ); ?>"
                                                    data-code="<?php echo esc_attr( $st['code'] ); ?>"
                                                    data-label="<?php echo esc_attr( $st['label'] ); ?>"
                                                    data-aliases="<?php echo esc_attr( $aliases_str ); ?>"
                                                    data-sort-order="<?php echo intval( $st['sort_order'] ); ?>"
                                                    data-is-pro="<?php echo ! empty( $st['is_pro'] ) ? '1' : '0'; ?>"
                                                    data-status="<?php echo esc_attr( $st['status'] ); ?>"
                                                    data-rule-mode="<?php echo esc_attr( $rule_mode ); ?>"
                                                    data-allowed-divisions="<?php echo esc_attr( $allowed_json ); ?>"
                                                    data-forced-division="<?php echo esc_attr( isset( $st['forced_division'] ) ? $st['forced_division'] : '' ); ?>"
                                                >Edit</button>

                                                <form method="post" action="<?php echo esc_url( $page_url ); ?>" class="sywat-table-action-form">
                                                    <?php wp_nonce_field( 'sywat_shooting_styles_action', 'sywat_styles_nonce' ); ?>
                                                    <input type="hidden" name="sywat_styles_action" value="toggle_style_status">
                                                    <input type="hidden" name="style_slug" value="<?php echo esc_attr( $st['slug'] ); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-full">
                                                        <?php echo $is_active ? 'Retire' : 'Activate'; ?>
                                                    </button>
                                                </form>

                                                <form method="post" action="<?php echo esc_url( $page_url ); ?>" class="sywat-table-action-form" data-sywat-confirm="Permanently delete shooting style '<?php echo esc_attr( $st['label'] ); ?>'? Tip: Use 'Retire' if historical tournaments still reference this style.">
                                                    <?php wp_nonce_field( 'sywat_shooting_styles_action', 'sywat_styles_nonce' ); ?>
                                                    <input type="hidden" name="sywat_styles_action" value="delete_style">
                                                    <input type="hidden" name="style_slug" value="<?php echo esc_attr( $st['slug'] ); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-full">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p class="sywat-empty-text">No shooting styles configured. Click <strong>+ Add Shooting Style</strong> or <strong>Reset NFAA Defaults</strong>.</p>
                <?php endif; ?>

                <!-- Secondary Section: Age Divisions -->
                <div class="sywat-table-header-bar">
                    <div>
                        <h2>🎯 Age Divisions (<?php echo intval( count( $divisions ) ); ?>)</h2>
                        <div class="sywat-subtitle">Configure age brackets, shooting distances (20yd vs. Cub 10yd), and Youth/Adult fee tiers.</div>
                    </div>

                    <div class="sywat-table-header-controls">
                        <button type="button" class="sywat-btn sywat-btn-success" data-sywat-action="open-add-division-modal">+ Add Division</button>
                    </div>
                </div>

                <?php if ( ! empty( $divisions ) ) : ?>
                    <div class="sywat-table-responsive">
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th class="sywat-col-center">Order</th>
                                    <th class="sywat-col-center">Code</th>
                                    <th>Division Name</th>
                                    <th class="sywat-col-center">Distance</th>
                                    <th class="sywat-col-center">Fee Tier</th>
                                    <th class="sywat-col-center">Status</th>
                                    <th class="sywat-col-center sywat-col-action">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $divisions as $d_slug => $div_rec ) : ?>
                                    <?php
                                        $div_active  = ( isset( $div_rec['status'] ) && $div_rec['status'] === 'active' );
                                        $div_aliases = ! empty( $div_rec['aliases'] ) && is_array( $div_rec['aliases'] ) ? implode( ', ', $div_rec['aliases'] ) : '';
                                    ?>
                                    <tr>
                                        <td class="sywat-col-center"><strong><?php echo intval( $div_rec['sort_order'] ); ?></strong></td>
                                        <td class="sywat-col-center"><code><?php echo esc_html( $div_rec['code'] ); ?></code></td>
                                        <td>
                                            <strong><?php echo esc_html( $div_rec['label'] ); ?></strong>
                                            <?php if ( $div_aliases !== '' ) : ?>
                                                <div class="sywat-subtitle">Aliases: <?php echo esc_html( $div_aliases ); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sywat-col-center">
                                            <span class="sywat-badge <?php echo ( $div_rec['distance'] === 'Cub' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg'; ?>">
                                                <?php echo esc_html( $div_rec['distance'] === 'Cub' ? 'Cub (10yd)' : '20yd' ); ?>
                                            </span>
                                        </td>
                                        <td class="sywat-col-center"><?php echo esc_html( $div_rec['rate_tier'] ); ?></td>
                                        <td class="sywat-col-center">
                                            <?php if ( $div_active ) : ?>
                                                <span class="sywat-badge sywat-badge-open">Active</span>
                                            <?php else : ?>
                                                <span class="sywat-badge sywat-badge-full">Retired</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sywat-col-center">
                                            <div class="sywat-table-actions-vertical">
                                                <button
                                                    type="button"
                                                    class="sywat-btn sywat-btn-primary sywat-btn-full"
                                                    data-sywat-action="open-edit-division-modal"
                                                    data-slug="<?php echo esc_attr( $div_rec['slug'] ); ?>"
                                                    data-code="<?php echo esc_attr( $div_rec['code'] ); ?>"
                                                    data-label="<?php echo esc_attr( $div_rec['label'] ); ?>"
                                                    data-aliases="<?php echo esc_attr( $div_aliases ); ?>"
                                                    data-distance="<?php echo esc_attr( $div_rec['distance'] ); ?>"
                                                    data-rate-tier="<?php echo esc_attr( $div_rec['rate_tier'] ); ?>"
                                                    data-sort-order="<?php echo intval( $div_rec['sort_order'] ); ?>"
                                                    data-status="<?php echo esc_attr( $div_rec['status'] ); ?>"
                                                >Edit</button>

                                                <form method="post" action="<?php echo esc_url( $page_url ); ?>" class="sywat-table-action-form">
                                                    <?php wp_nonce_field( 'sywat_shooting_styles_action', 'sywat_styles_nonce' ); ?>
                                                    <input type="hidden" name="sywat_styles_action" value="toggle_division_status">
                                                    <input type="hidden" name="div_slug" value="<?php echo esc_attr( $div_rec['slug'] ); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-full">
                                                        <?php echo $div_active ? 'Retire' : 'Activate'; ?>
                                                    </button>
                                                </form>

                                                <form method="post" action="<?php echo esc_url( $page_url ); ?>" class="sywat-table-action-form" data-sywat-confirm="Permanently delete division '<?php echo esc_attr( $div_rec['label'] ); ?>'?">
                                                    <?php wp_nonce_field( 'sywat_shooting_styles_action', 'sywat_styles_nonce' ); ?>
                                                    <input type="hidden" name="sywat_styles_action" value="delete_division">
                                                    <input type="hidden" name="div_slug" value="<?php echo esc_attr( $div_rec['slug'] ); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-full">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p class="sywat-empty-text">No divisions configured.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- MODAL 1: ADD / EDIT SHOOTING STYLE -->
        <div class="sywat-modal-backdrop" id="sywat_style_editor_modal">
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header">
                    <div>
                        <h3 id="sywat_style_modal_title">🏹 Add Shooting Style</h3>
                        <div class="sywat-subtitle">Configure style display label, export code, sort order, and division eligibility rules.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" aria-label="Close Shooting Style Modal" data-sywat-action="close-style-modal">&times;</button>
                </div>

                <form method="post" action="<?php echo esc_url( $page_url ); ?>">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field( 'sywat_shooting_styles_action', 'sywat_styles_nonce' ); ?>
                        <input type="hidden" name="sywat_styles_action" value="save_style">
                        <input type="hidden" name="orig_style_slug" id="sywat_style_orig_slug" value="">

                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label for="sywat_style_label">Display Name *</label>
                                <input type="text" id="sywat_style_label" name="style_label" placeholder="e.g., LB – Longbow" required>
                            </div>
                            <div class="sywat-field-group">
                                <label for="sywat_style_code">Short Export Code *</label>
                                <input type="text" id="sywat_style_code" name="style_code" placeholder="e.g., LB" maxlength="12" required>
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label for="sywat_style_sort_order">Sort Order</label>
                                <input type="number" id="sywat_style_sort_order" name="style_sort_order" min="1" max="999" value="12">
                            </div>
                            <div class="sywat-field-group">
                                <label for="sywat_style_status">Status</label>
                                <select id="sywat_style_status" name="style_status">
                                    <option value="active">Active (Selectable)</option>
                                    <option value="retired">Retired (Hidden from New Intake)</option>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label for="sywat_style_is_pro">Pro Category</label>
                                <label class="sywat-checkbox-label sywat-mt-3">
                                    <input type="checkbox" id="sywat_style_is_pro" name="style_is_pro" value="1">
                                    Pro Division Style
                                </label>
                            </div>
                        </div>

                        <div class="sywat-form-grid-1 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label for="sywat_style_aliases">TFAA Sync &amp; Legacy Aliases (comma-separated)</label>
                                <input type="text" id="sywat_style_aliases" name="style_aliases" placeholder="e.g., Longbow, LB">
                            </div>
                        </div>

                        <div class="sywat-form-grid-1 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label for="sywat_style_rule_mode">Division / Age Eligibility Rule</label>
                                <select id="sywat_style_rule_mode" name="style_rule_mode" data-sywat-action="change-style-rule-mode">
                                    <option value="all">Available to All Divisions</option>
                                    <option value="restrict">Restrict to Selected Divisions Only</option>
                                    <option value="force">Force Specific Division (Overrides Archer Age)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Conditional Box A: Allowed Divisions Checkboxes -->
                        <div class="sywat-field-group sywat-mt-3 sywat-hidden" id="sywat_style_restrict_box">
                            <label>Allowed Divisions *</label>
                            <div class="sywat-role-grid sywat-form-grid-2">
                                <?php foreach ( $div_names as $d_label ) : ?>
                                    <label class="sywat-checkbox-label">
                                        <input type="checkbox" class="sywat-style-allowed-div-cb" name="style_allowed_divisions[]" value="<?php echo esc_attr( $d_label ); ?>">
                                        <?php echo esc_html( $d_label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Conditional Box B: Forced Division Dropdown -->
                        <div class="sywat-field-group sywat-mt-3 sywat-hidden" id="sywat_style_force_box">
                            <label for="sywat_style_forced_division">Force Archer Into Division *</label>
                            <select id="sywat_style_forced_division" name="style_forced_division">
                                <option value="">-- Select Target Division --</option>
                                <?php foreach ( $div_names as $d_label ) : ?>
                                    <option value="<?php echo esc_attr( $d_label ); ?>"><?php echo esc_html( $d_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-style-modal">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-success">Save Shooting Style</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL 2: ADD / EDIT AGE DIVISION -->
        <div class="sywat-modal-backdrop" id="sywat_division_editor_modal">
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header">
                    <div>
                        <h3 id="sywat_div_modal_title">🎯 Add Age Division</h3>
                        <div class="sywat-subtitle">Configure division display name, export code, distance, and Youth/Adult fee tier.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" aria-label="Close Division Modal" data-sywat-action="close-division-modal">&times;</button>
                </div>

                <form method="post" action="<?php echo esc_url( $page_url ); ?>">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field( 'sywat_shooting_styles_action', 'sywat_styles_nonce' ); ?>
                        <input type="hidden" name="sywat_styles_action" value="save_division">
                        <input type="hidden" name="orig_div_slug" id="sywat_div_orig_slug" value="">

                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label for="sywat_div_label">Division Name *</label>
                                <input type="text" id="sywat_div_label" name="div_label" placeholder="e.g., Adult (18 - 49)" required>
                            </div>
                            <div class="sywat-field-group">
                                <label for="sywat_div_code">Export Code *</label>
                                <input type="text" id="sywat_div_code" name="div_code" placeholder="e.g., A" maxlength="8" required>
                            </div>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label for="sywat_div_distance">Shooting Distance</label>
                                <select id="sywat_div_distance" name="div_distance">
                                    <option value="20yd">20yd (Standard Line)</option>
                                    <option value="Cub">Cub (10yd Line)</option>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label for="sywat_div_rate_tier">Individual Fee Tier</label>
                                <select id="sywat_div_rate_tier" name="div_rate_tier">
                                    <option value="Adult">Adult Rate ($30)</option>
                                    <option value="Youth">Youth Rate ($25)</option>
                                </select>
                            </div>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label for="sywat_div_sort_order">Sort Order</label>
                                <input type="number" id="sywat_div_sort_order" name="div_sort_order" min="1" max="999" value="8">
                            </div>
                            <div class="sywat-field-group">
                                <label for="sywat_div_status">Status</label>
                                <select id="sywat_div_status" name="div_status">
                                    <option value="active">Active</option>
                                    <option value="retired">Retired</option>
                                </select>
                            </div>
                        </div>

                        <div class="sywat-form-grid-1 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label for="sywat_div_aliases">Legacy &amp; Sync Aliases (comma-separated)</label>
                                <input type="text" id="sywat_div_aliases" name="div_aliases" placeholder="e.g., Adult">
                            </div>
                        </div>
                    </div>

                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-division-modal">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-success">Save Division</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
