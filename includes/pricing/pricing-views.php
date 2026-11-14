<?php
/**
 * Sub-Module: Pricing Profiles, Round Formats & Promoter Admin View Renderer
 * Path: includes/pricing/pricing-views.php
 * Description: Renders the [sywat_pricing] administration interface for managing round pricing profiles (Indoor, Field, Field 50, Known 3D, 900, and custom formats), per-division rate overrides, household family cap rules, and configurable first-time/lapsed shooter promotional rules.
 * Compliance: Pure view layer — zero direct SQL queries, zero inline style attributes, zero inline event handlers, zero embedded script blocks, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the Pricing, Round Formats & Promoter Administration Portal ([sywat_pricing]).
 *
 * @return string Rendered HTML markup.
 */
if ( ! function_exists( 'render_sywat_pricing_portal' ) ) {
    function render_sywat_pricing_portal() {
        if ( ! function_exists( 'sywat_can_manage_seasons' ) || ! sywat_can_manage_seasons() ) {
            return '<div class="sywat-alert sywat-alert-danger">Access denied. TFAA Leadership privileges are required to manage pricing formats.</div>';
        }

        // Execute POST mutations prior to loading fresh profile list
        $notice = function_exists( 'sywat_pricing_handle_post_actions' )
            ? sywat_pricing_handle_post_actions()
            : '';

        $all_profiles    = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( true ) : array();
        $active_profiles = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( false ) : array();
        $divisions       = function_exists( 'sywat_get_division_records' ) ? sywat_get_division_records( false ) : array();
        $ordered_slugs   = array_keys( $all_profiles );
        $total_profiles  = count( $ordered_slugs );
        $active_count    = count( $active_profiles );

        $manage_tournaments_url = function_exists( 'sywat_page_url' ) ? sywat_page_url( 'manage_tournaments' ) : '';
        $shooting_styles_url    = function_exists( 'sywat_page_url' ) ? sywat_page_url( 'shooting_styles' ) : '';
        $pricing_url            = function_exists( 'sywat_page_url' ) ? sywat_page_url( 'pricing' ) : '';

        ob_start();
        ?>
        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <?php if ( ! empty( $manage_tournaments_url ) || ! empty( $shooting_styles_url ) ) : ?>
                <div class="sywat-nav-tabs no-print">
                    <?php if ( ! empty( $manage_tournaments_url ) ) : ?>
                        <a href="<?php echo esc_url( $manage_tournaments_url ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">Manage Tournaments</a>
                        <a href="<?php echo esc_url( add_query_arg( 'tab', 'seasons', $manage_tournaments_url ) ); ?>" class="sywat-tab-item sywat-tab-icon-season">Manage Seasons</a>
                    <?php endif; ?>
                    <?php if ( ! empty( $shooting_styles_url ) ) : ?>
                        <a href="<?php echo esc_url( $shooting_styles_url ); ?>" class="sywat-tab-item">Styles &amp; Divisions</a>
                    <?php endif; ?>
                    <?php if ( ! empty( $pricing_url ) ) : ?>
                        <a href="<?php echo esc_url( $pricing_url ); ?>" class="sywat-tab-item active">Pricing &amp; Formats</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="sywat-card no-print">
                <!-- TOP CARD HEADER BAR -->
                <div class="sywat-card-header-bar">
                    <div>
                        <h2>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <line x1="12" y1="1" x2="12" y2="23"></line>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                            Pricing, Round Formats &amp; Promoter Rules
                        </h2>
                        <div class="sywat-subtitle">Configure entry fees, per-division overrides, household family caps, and first-time/lapsed shooter promotional rules across Indoor, Field, Field 50, Known 3D, 900, and custom formats.</div>
                    </div>

                    <div class="sywat-table-header-actions">
                        <form method="post" class="sywat-table-action-form" data-sywat-confirm="Restore all pricing formats, family cap limits, and promoter presets to SYWAT / TFAA defaults?">
                            <?php wp_nonce_field( 'sywat_pricing_action', 'sywat_pricing_nonce' ); ?>
                            <input type="hidden" name="sywat_pricing_action" value="reset_pricing_defaults">
                            <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-reset">Reset Defaults</button>
                        </form>

                        <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" data-sywat-action="open-add-pricing-modal">Add Pricing Format</button>
                    </div>
                </div>

                <!-- TABLE SUB-HEADER BAR -->
                <div class="sywat-table-header-bar">
                    <h2>Configured Round &amp; Pricing Formats (<?php echo intval( $active_count ); ?> Active)</h2>

                    <div class="sywat-table-header-controls">
                        <div class="sywat-table-header-filters">
                            <div class="sywat-field-group">
                                <label for="sywat_pricing_status_filter">Status:</label>
                                <select id="sywat_pricing_status_filter" data-sywat-action="filter-pricing-table">
                                    <option value="all">All Formats</option>
                                    <option value="active" selected>Active Only</option>
                                    <option value="retired">Retired Only</option>
                                </select>
                            </div>

                            <input type="text" id="sywat_pricing_search_input" data-sywat-action="filter-pricing-table" placeholder="Search formats or codes..." autocomplete="off">

                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" data-sywat-action="clear-pricing-filters">Clear</button>
                        </div>
                    </div>
                </div>

                <!-- PRICING FORMATS DATA TABLE -->
                <?php if ( empty( $all_profiles ) ) : ?>
                    <p class="sywat-empty-text">No pricing formats configured yet. Click <strong>Add Pricing Format</strong> to create your first rate profile.</p>
                <?php else : ?>
                    <div class="sywat-table-responsive">
                        <table class="sywat-table" id="sywat_pricing_profiles_table">
                            <thead>
                                <tr>
                                    <th class="sywat-col-center">Order</th>
                                    <th>Format &amp; Round</th>
                                    <th>Entry Fee Tiers</th>
                                    <th>Household Family Cap</th>
                                    <th>Promoter Free Entry Rule</th>
                                    <th class="sywat-col-center">Status</th>
                                    <th class="sywat-col-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $all_profiles as $slug => $prof ) : ?>
                                    <?php
                                        $pos_index    = array_search( $slug, $ordered_slugs, true );
                                        $is_first     = ( $pos_index === 0 );
                                        $is_last      = ( $pos_index === ( $total_profiles - 1 ) );
                                        $is_active    = ( $prof['status'] === 'active' );
                                        $is_default   = ! empty( $prof['is_default'] );
                                        $ov_count     = ! empty( $prof['division_overrides'] ) && is_array( $prof['division_overrides'] ) ? count( $prof['division_overrides'] ) : 0;
                                        $search_index = strtolower( $prof['label'] . ' ' . $prof['round_subtitle'] . ' ' . $prof['code'] . ' ' . implode( ' ', (array) $prof['aliases'] ) );

                                        // Build readable list of qualifying prior-experience formats for Promo display
                                        $history_labels = array();
                                        if ( ! empty( $prof['promo_history_profiles'] ) && is_array( $prof['promo_history_profiles'] ) ) {
                                            foreach ( $prof['promo_history_profiles'] as $hp_slug ) {
                                                if ( isset( $all_profiles[ $hp_slug ]['label'] ) ) {
                                                    $history_labels[] = $all_profiles[ $hp_slug ]['label'];
                                                } else {
                                                    $history_labels[] = $hp_slug;
                                                }
                                            }
                                        }

                                        $scope_labels = array(
                                            'season'   => 'Per Season',
                                            'lookback' => 'Per Lookback Window',
                                            'lifetime' => 'Lifetime (All-Time)',
                                        );
                                        $usage_scope_text = isset( $scope_labels[ $prof['promo_usage_scope'] ] ) ? $scope_labels[ $prof['promo_usage_scope'] ] : 'Per Season';
                                        $usage_limit_text = ( intval( $prof['promo_max_uses'] ) === 0 )
                                            ? 'Unlimited while eligible'
                                            : intval( $prof['promo_max_uses'] ) . ' free ' . ( intval( $prof['promo_max_uses'] ) === 1 ? 'shoot' : 'shoots' ) . ' (' . $usage_scope_text . ')';

                                        $profile_json = wp_json_encode( $prof );
                                    ?>
                                    <tr class="sywat-pricing-row<?php echo $is_active ? '' : ' sywat-hidden'; ?>"
                                        data-status="<?php echo esc_attr( $prof['status'] ); ?>"
                                        data-search="<?php echo esc_attr( $search_index ); ?>">

                                        <!-- Order Controls -->
                                        <td class="sywat-col-center">
                                            <div class="sywat-table-actions">
                                                <form method="post" class="sywat-table-action-form">
                                                    <?php wp_nonce_field( 'sywat_pricing_action', 'sywat_pricing_nonce' ); ?>
                                                    <input type="hidden" name="sywat_pricing_action" value="move_pricing_profile">
                                                    <input type="hidden" name="profile_slug" value="<?php echo esc_attr( $slug ); ?>">
                                                    <input type="hidden" name="direction" value="up">
                                                    <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm" <?php disabled( $is_first ); ?> aria-label="Move <?php echo esc_attr( $prof['label'] ); ?> up">&uarr;</button>
                                                </form>

                                                <form method="post" class="sywat-table-action-form">
                                                    <?php wp_nonce_field( 'sywat_pricing_action', 'sywat_pricing_nonce' ); ?>
                                                    <input type="hidden" name="sywat_pricing_action" value="move_pricing_profile">
                                                    <input type="hidden" name="profile_slug" value="<?php echo esc_attr( $slug ); ?>">
                                                    <input type="hidden" name="direction" value="down">
                                                    <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm" <?php disabled( $is_last ); ?> aria-label="Move <?php echo esc_attr( $prof['label'] ); ?> down">&darr;</button>
                                                </form>
                                            </div>
                                        </td>

                                        <!-- Format & Round -->
                                        <td>
                                            <strong><?php echo esc_html( $prof['label'] ); ?></strong>
                                            <?php if ( ! empty( $prof['round_subtitle'] ) ) : ?>
                                                <span class="sywat-label-subtext">(<?php echo esc_html( $prof['round_subtitle'] ); ?>)</span>
                                            <?php endif; ?>
                                            <div>
                                                <span class="sywat-badge"><?php echo esc_html( $prof['code'] ); ?></span>
                                                <?php if ( $is_default ) : ?>
                                                    <span class="sywat-badge sywat-badge-open">Global Default</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Entry Fee Tiers -->
                                        <td>
                                            <div><strong>Youth Tier:</strong> $<?php echo esc_html( number_format( floatval( $prof['youth_rate'] ), 2 ) ); ?></div>
                                            <div><strong>Adult+ Tier:</strong> $<?php echo esc_html( number_format( floatval( $prof['adult_rate'] ), 2 ) ); ?></div>
                                            <?php if ( $prof['pro_rate'] !== '' && is_numeric( $prof['pro_rate'] ) ) : ?>
                                                <div><strong>Pro Category:</strong> $<?php echo esc_html( number_format( floatval( $prof['pro_rate'] ), 2 ) ); ?></div>
                                            <?php endif; ?>
                                            <div class="sywat-label-subtext">
                                                Field Gov: $<?php echo esc_html( number_format( floatval( $prof['field_governor_rate'] ), 2 ) ); ?>
                                                <?php if ( $ov_count > 0 ) : ?>
                                                    &bull; <?php echo intval( $ov_count ); ?> division <?php echo ( $ov_count === 1 ? 'override' : 'overrides' ); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Household Family Cap -->
                                        <td>
                                            <?php if ( ! empty( $prof['family_cap_enabled'] ) ) : ?>
                                                <div>
                                                    <span class="sywat-badge sywat-badge-prereg">Cap: $<?php echo esc_html( number_format( floatval( $prof['family_cap_amount'] ), 2 ) ); ?></span>
                                                </div>
                                                <div class="sywat-label-subtext">
                                                    <?php echo intval( $prof['family_min_archers'] ); ?>+ Archers &bull;
                                                    <?php echo ( $prof['family_cap_scope'] === 'tournament' ) ? 'Per Tournament' : 'Per Shooting Line'; ?>
                                                </div>
                                            <?php else : ?>
                                                <span class="sywat-label-subtext">Disabled (Individual Rates Only)</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Promoter Free Entry Rule -->
                                        <td>
                                            <?php if ( ! empty( $prof['promo_enabled'] ) ) : ?>
                                                <div>
                                                    <span class="sywat-badge sywat-badge-open">Active ($0.00 Promo)</span>
                                                </div>
                                                <div class="sywat-label-subtext">
                                                    Lookback: <strong><?php echo intval( $prof['promo_lookback_value'] ) . ' ' . esc_html( $prof['promo_lookback_unit'] ); ?></strong>
                                                    (<?php echo esc_html( implode( ', ', $history_labels ) ); ?>)
                                                </div>
                                                <div class="sywat-label-subtext">
                                                    Limit: <?php echo esc_html( $usage_limit_text ); ?>
                                                </div>
                                            <?php else : ?>
                                                <span class="sywat-label-subtext">Not Enabled</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status -->
                                        <td class="sywat-col-center">
                                            <?php if ( $is_active ) : ?>
                                                <span class="sywat-text-active">Active</span>
                                            <?php else : ?>
                                                <span class="sywat-text-archived">Retired</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Row Actions -->
                                        <td class="sywat-col-right">
                                            <div class="sywat-table-actions">
                                                <button type="button"
                                                        class="sywat-btn sywat-btn-primary sywat-btn-sm sywat-btn-icon-edit"
                                                        data-sywat-action="open-edit-pricing-modal"
                                                        data-profile="<?php echo esc_attr( $profile_json ); ?>">Edit</button>

                                                <?php if ( $is_active && ! $is_default ) : ?>
                                                    <form method="post" class="sywat-table-action-form">
                                                        <?php wp_nonce_field( 'sywat_pricing_action', 'sywat_pricing_nonce' ); ?>
                                                        <input type="hidden" name="sywat_pricing_action" value="set_default_profile">
                                                        <input type="hidden" name="profile_slug" value="<?php echo esc_attr( $slug ); ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm">Set Default</button>
                                                    </form>
                                                <?php endif; ?>

                                                <form method="post" class="sywat-table-action-form">
                                                    <?php wp_nonce_field( 'sywat_pricing_action', 'sywat_pricing_nonce' ); ?>
                                                    <input type="hidden" name="sywat_pricing_action" value="toggle_profile_status">
                                                    <input type="hidden" name="profile_slug" value="<?php echo esc_attr( $slug ); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm">
                                                        <?php echo $is_active ? 'Retire' : 'Activate'; ?>
                                                    </button>
                                                </form>

                                                <?php if ( $total_profiles > 1 && ! $is_default ) : ?>
                                                    <form method="post" class="sywat-table-action-form" data-sywat-confirm="Delete pricing format '<?php echo esc_attr( $prof['label'] ); ?>'? Existing historical registrations will remain intact.">
                                                        <?php wp_nonce_field( 'sywat_pricing_action', 'sywat_pricing_nonce' ); ?>
                                                        <input type="hidden" name="sywat_pricing_action" value="delete_pricing_profile">
                                                        <input type="hidden" name="profile_slug" value="<?php echo esc_attr( $slug ); ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-sm sywat-btn-icon-delete">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ADD / EDIT PRICING & PROMOTER PROFILE MODAL -->
        <div class="sywat-modal-backdrop" id="sywat_pricing_profile_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3 id="sywat_pricing_modal_title">Configure Pricing &amp; Round Format</h3>
                        <div class="sywat-subtitle">Define tier rates, optional per-division overrides, household cap rules, and first-time shooter promotional limits.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" data-sywat-action="close-pricing-modal" aria-label="Close pricing format modal">&times;</button>
                </div>

                <form method="post" id="sywat_pricing_profile_form">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field( 'sywat_pricing_action', 'sywat_pricing_nonce' ); ?>
                        <input type="hidden" name="sywat_pricing_action" value="save_pricing_profile">
                        <input type="hidden" name="orig_profile_slug" id="sywat_pf_orig_slug" value="">

                        <!-- SECTION 1: FORMAT IDENTITY -->
                        <div class="sywat-form-grid-3">
                            <div class="sywat-field-group">
                                <label for="sywat_pf_label">Format Display Name *</label>
                                <input type="text" name="profile_label" id="sywat_pf_label" placeholder="e.g., Field 50" required>
                            </div>

                            <div class="sywat-field-group">
                                <label for="sywat_pf_subtitle">Round Subtitle <span class="sywat-label-subtext">(Optional)</span></label>
                                <input type="text" name="profile_round_subtitle" id="sywat_pf_subtitle" placeholder="e.g., 14 Targets or 28 Targets">
                            </div>

                            <div class="sywat-field-group">
                                <label for="sywat_pf_code">Short Code *</label>
                                <input type="text" name="profile_code" id="sywat_pf_code" placeholder="e.g., F50" required>
                            </div>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label for="sywat_pf_sort_order">Display Order</label>
                                <input type="number" name="profile_sort_order" id="sywat_pf_sort_order" value="<?php echo intval( $total_profiles + 1 ); ?>" min="1" required>
                            </div>

                            <div class="sywat-field-group">
                                <label for="sywat_pf_status">Status</label>
                                <select name="profile_status" id="sywat_pf_status">
                                    <option value="active">Active</option>
                                    <option value="retired">Retired</option>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="sywat_pf_aliases">Import / Alias Keywords</label>
                                <input type="text" name="profile_aliases" id="sywat_pf_aliases" placeholder="e.g., Field50, 14 Target Field">
                            </div>
                        </div>

                        <div class="sywat-field-group sywat-mt-3">
                            <label>
                                <input type="checkbox" name="profile_is_default" id="sywat_pf_is_default" value="1">
                                Set as Global Default Pricing Format (fallback when a tournament has no season or format selected)
                            </label>
                        </div>

                        <!-- SECTION 2: ENTRY FEE RATES -->
                        <div class="sywat-table-header-bar sywat-modal-section-header">
                            <h2>Entry Fee Rates ($)</h2>
                        </div>

                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label for="sywat_pf_youth_rate">Youth Tier Rate ($) * <span class="sywat-label-subtext">(Cub, Youth, Young Adult)</span></label>
                                <input type="number" name="youth_rate" id="sywat_pf_youth_rate" value="25.00" min="0" step="0.01" required>
                            </div>

                            <div class="sywat-field-group">
                                <label for="sywat_pf_adult_rate">Adult+ Tier Rate ($) * <span class="sywat-label-subtext">(Adult, Senior, Silver, Master)</span></label>
                                <input type="number" name="adult_rate" id="sywat_pf_adult_rate" value="30.00" min="0" step="0.01" required>
                            </div>
                        </div>

                        <div class="sywat-form-grid-2 sywat-mt-3">
                            <div class="sywat-field-group">
                                <label for="sywat_pf_pro_rate">Pro Category Rate ($) <span class="sywat-label-subtext">(Leave blank to use Adult+ rate)</span></label>
                                <input type="number" name="pro_rate" id="sywat_pf_pro_rate" value="" min="0" step="0.01" placeholder="Optional Pro entry rate">
                            </div>

                            <div class="sywat-field-group">
                                <label for="sywat_pf_fg_rate">Field Governor Rate ($) *</label>
                                <input type="number" name="field_governor_rate" id="sywat_pf_fg_rate" value="0.00" min="0" step="0.01" required>
                            </div>
                        </div>

                        <?php if ( ! empty( $divisions ) ) : ?>
                            <details class="sywat-mt-3" id="sywat_pf_overrides_disclosure">
                                <summary><strong>Optional Per-Division Price Overrides</strong> (Leave blank to use standard Youth / Adult+ tier rates above)</summary>
                                <div class="sywat-form-grid-3 sywat-mt-3">
                                    <?php foreach ( $divisions as $div_slug => $div_rec ) : ?>
                                        <div class="sywat-field-group">
                                            <label for="sywat_pf_ov_<?php echo esc_attr( $div_slug ); ?>">
                                                <?php echo esc_html( $div_rec['label'] ); ?>
                                                <span class="sywat-label-subtext">(<?php echo esc_html( $div_rec['rate_tier'] ); ?>)</span>
                                            </label>
                                            <input type="number"
                                                   name="division_overrides[<?php echo esc_attr( $div_slug ); ?>]"
                                                   id="sywat_pf_ov_<?php echo esc_attr( $div_slug ); ?>"
                                                   class="sywat-pf-div-override-input"
                                                   data-div-slug="<?php echo esc_attr( $div_slug ); ?>"
                                                   value=""
                                                   min="0"
                                                   step="0.01"
                                                   placeholder="Default Tier Rate">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>

                        <!-- SECTION 3: HOUSEHOLD FAMILY CAP RULES -->
                        <div class="sywat-table-header-bar sywat-modal-section-header">
                            <h2>Household Family Cap Limits</h2>
                        </div>

                        <div class="sywat-field-group">
                            <label>
                                <input type="checkbox" name="family_cap_enabled" id="sywat_pf_family_cap_enabled" value="1" data-sywat-action="toggle-family-cap-fields" checked>
                                Enable Household Family Cap for this format
                            </label>
                        </div>

                        <div class="sywat-form-grid-3 sywat-mt-3" id="sywat_family_cap_fields">
                            <div class="sywat-field-group">
                                <label for="sywat_pf_family_cap_amount">Max Household Cap ($)</label>
                                <input type="number" name="family_cap_amount" id="sywat_pf_family_cap_amount" value="75.00" min="0" step="0.01">
                            </div>

                            <div class="sywat-field-group">
                                <label for="sywat_pf_family_min_archers">Minimum Active Archers</label>
                                <input type="number" name="family_min_archers" id="sywat_pf_family_min_archers" value="3" min="2" max="20">
                            </div>

                            <div class="sywat-field-group">
                                <label for="sywat_pf_family_cap_scope">Family Cap Scope</label>
                                <select name="family_cap_scope" id="sywat_pf_family_cap_scope">
                                    <option value="line">Per Shooting Line (e.g., Indoor)</option>
                                    <option value="tournament">Per Tournament / Weekend (e.g., Outdoor)</option>
                                </select>
                            </div>
                        </div>

                        <!-- SECTION 4: PROMOTIONAL FREE ENTRY RULES -->
                        <div class="sywat-table-header-bar sywat-modal-section-header">
                            <h2>First-Time / Lapsed Shooter Promotional Rules</h2>
                        </div>

                        <div class="sywat-field-group">
                            <label>
                                <input type="checkbox" name="promo_enabled" id="sywat_pf_promo_enabled" value="1" data-sywat-action="toggle-promo-fields">
                                Allow $0.00 Promotional Free Entries on this format (when enabled on the active Season)
                            </label>
                        </div>

                        <div id="sywat_promo_fields" class="sywat-mt-3 sywat-hidden">
                            <div class="sywat-form-grid-3">
                                <div class="sywat-field-group">
                                    <label for="sywat_pf_promo_label">Promo Display Label</label>
                                    <input type="text" name="promo_label" id="sywat_pf_promo_label" value="First-Time / Lapsed Shooter Promo" placeholder="e.g., First-Time Field 50 Promo">
                                </div>

                                <div class="sywat-field-group">
                                    <label for="sywat_pf_promo_lookback_value">Prior Experience Lookback</label>
                                    <input type="number" name="promo_lookback_value" id="sywat_pf_promo_lookback_value" value="24" min="1" max="120">
                                </div>

                                <div class="sywat-field-group">
                                    <label for="sywat_pf_promo_lookback_unit">Lookback Time Unit</label>
                                    <select name="promo_lookback_unit" id="sywat_pf_promo_lookback_unit">
                                        <option value="months" selected>Months</option>
                                        <option value="years">Years</option>
                                    </select>
                                </div>
                            </div>

                            <div class="sywat-form-grid-2 sywat-mt-3">
                                <div class="sywat-field-group">
                                    <label for="sywat_pf_promo_max_uses">Max Free Promotional Shoots <span class="sywat-label-subtext">(0 = Unlimited while eligible)</span></label>
                                    <input type="number" name="promo_max_uses" id="sywat_pf_promo_max_uses" value="1" min="0" max="50">
                                </div>

                                <div class="sywat-field-group">
                                    <label for="sywat_pf_promo_usage_scope">Usage Limit Scope</label>
                                    <select name="promo_usage_scope" id="sywat_pf_promo_usage_scope">
                                        <option value="season" selected>Per Season</option>
                                        <option value="lookback">Per Lookback Window</option>
                                        <option value="lifetime">Lifetime (All-Time)</option>
                                    </select>
                                </div>
                            </div>

                            <?php if ( ! empty( $all_profiles ) ) : ?>
                                <div class="sywat-field-group sywat-mt-3">
                                    <label>Qualifying Prior-Experience Formats <span class="sywat-label-subtext">(Archers who shot any checked format within the lookback window pay standard rates)</span></label>
                                    <div class="sywat-form-grid-3">
                                        <?php foreach ( $all_profiles as $hp_slug => $hp_rec ) : ?>
                                            <label>
                                                <input type="checkbox"
                                                       name="promo_history_profiles[]"
                                                       class="sywat-pf-promo-history-cb"
                                                       value="<?php echo esc_attr( $hp_slug ); ?>">
                                                <?php echo esc_html( $hp_rec['label'] ); ?>
                                                <?php if ( ! empty( $hp_rec['round_subtitle'] ) ) : ?>
                                                    <span class="sywat-label-subtext">(<?php echo esc_html( $hp_rec['round_subtitle'] ); ?>)</span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" data-sywat-action="close-pricing-modal">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Pricing Format</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
