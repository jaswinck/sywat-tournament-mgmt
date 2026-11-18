<?php
/**
 * Sub-Module: Check-In Desk Visual Views
 * File: includes/checkin/checkin-views.php
 * Description: Renders the Check-In Desk navigation with role-gated desk tabs and sticky tournament carryover, chronologically ordered Line Capacity Tracker with clickable Waitlist badges/buttons, Active Registered Archers table with responsive mobile card labels, Rate column, multi-round & promotional badges and household-wide OWE: FAMILY badges, and the dedicated Waitlist Management Modal (#sywat_waitlist_modal) on demand.
 * Compliance: Pure view layer — zero output at include time, zero inline styles, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reusable helper to render hidden filter context inputs and eliminate form bloat.
 *
 * @param string $selected_tournament Active tournament name.
 * @param string $selected_line_idx   Active line index or 'all'.
 * @param string $search_query        Current search query string.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_render_hidden_context' ) ) {
    function sywat_desk_render_hidden_context( $selected_tournament, $selected_line_idx, $search_query ) {
        ?>
        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
        <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $selected_line_idx ); ?>">
        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $search_query ); ?>">
        <?php
    }
}

/**
 * Render post-registration family prompt banner when continuing a household group.
 *
 * @param array|false $added_family_prompt Household prompt payload or false.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_render_family_banner' ) ) {
    function sywat_desk_render_family_banner( $added_family_prompt ) {
        if ( ! $added_family_prompt ) {
            return;
        }
        ?>
        <div class="sywat-alert sywat-alert-success no-print sywat-family-banner">
            <div class="sywat-family-banner-title">
                ✅ <?php echo esc_html( $added_family_prompt['archer_name'] ); ?> added under <?php echo esc_html( $added_family_prompt['family_name'] ); ?>!
            </div>
            <div class="sywat-family-banner-subtext">
                Is there another family member to register under this same payment group?
            </div>
            <div class="sywat-family-banner-actions">
                <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="continueFamilyLoop('<?php echo esc_js( $added_family_prompt['family_id'] ); ?>', '<?php echo esc_js( $added_family_prompt['line_idx'] ); ?>')">
                    Yes, Add Next Family Member
                </button>
                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="this.parentElement.parentElement.remove();">
                    Done / Finish Family
                </button>
            </div>
        </div>
        <?php
    }
}

/**
 * Render role-gated card-docked navigation tabs and unified tournament/line filter bar.
 *
 * @param array $data Aggregated Check-In Desk dataset.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_render_filter_bar' ) ) {
    function sywat_desk_render_filter_bar( $data ) {
        $can_manage_tournaments = function_exists( 'sywat_can_manage_tournaments' ) && sywat_can_manage_tournaments();
        $can_register_shooters  = function_exists( 'sywat_can_register_shooters' ) ? sywat_can_register_shooters() : ( function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk() );
        $can_access_desk        = function_exists( 'sywat_can_access_desk' ) && sywat_can_access_desk();
        $can_edit_scores        = function_exists( 'sywat_can_edit_scores' ) && sywat_can_edit_scores();
        $can_manage_seasons     = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
        $selected_t             = isset( $data['selected_tournament'] ) ? $data['selected_tournament'] : '';

        $all_tournaments = get_option( 'sywat_tournaments_list', array() );
        $t_master        = ( is_array( $all_tournaments ) && isset( $all_tournaments[ $selected_t ] ) ) ? $all_tournaments[ $selected_t ] : array();

        $t_shop_id = 'shop_ccr';
        if ( is_array( $t_master ) && isset( $t_master['shop_id'] ) && ! empty( $t_master['shop_id'] ) ) {
            $t_shop_id = $t_master['shop_id'];
        } elseif ( function_exists( 'sywat_get_tournament_shop_id' ) ) {
            $t_shop_id = sywat_get_tournament_shop_id( $t_master );
        }

        $active_hubs = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_hubs ) ) {
            $active_hubs = array();
        }

        $is_hub_enabled = ( $t_shop_id === 'shop_ccr' ) && in_array( $selected_t, $active_hubs, true );
        $tab_query_args = ! empty( $selected_t ) ? array( 'selected_tournament' => $selected_t ) : array();
        ?>
        <!-- Master Navigation Tabs (Card-Docked, Role-Gated) -->
        <div class="sywat-nav-tabs no-print">
            <?php if ( $can_manage_tournaments ) : ?>
                <a href="<?php echo esc_url( sywat_page_url( 'manage_tournaments' ) ); ?>" class="sywat-tab-item sywat-tab-icon-tournament">Manage Tournaments</a>
            <?php endif; ?>
            <?php if ( $can_register_shooters ) : ?>
                <a href="<?php echo esc_url( add_query_arg( $tab_query_args, sywat_page_url( 'register' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-register">Register Shooters</a>
            <?php endif; ?>
            <?php if ( $can_access_desk ) : ?>
                <a href="<?php echo esc_url( add_query_arg( $tab_query_args, sywat_page_url( 'desk' ) ) ); ?>" class="sywat-tab-item active sywat-tab-icon-checkin">Check In Archers</a>
            <?php endif; ?>
            <?php if ( $can_edit_scores ) : ?>
                <a href="<?php echo esc_url( add_query_arg( $tab_query_args, sywat_page_url( 'enter_scores' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-edit">Enter Scores</a>
            <?php endif; ?>
            <?php if ( $can_manage_seasons ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'seasons', sywat_page_url( 'manage_tournaments' ) ) ); ?>" class="sywat-tab-item sywat-tab-icon-season">Manage Seasons</a>
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
                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $data['search_query'] ); ?>">
                        
                        <!-- Stacked Active Tournament Select -->
                        <div class="sywat-field-group sywat-field-auto">
                            <label for="sywat_selected_tournament">Active Tournament:</label>
                            <select id="sywat_selected_tournament" name="selected_tournament" class="sywat-select-tournament" onchange="document.getElementById('sywat_filter_form').submit();">
                                <?php foreach ( $data['tournaments'] as $t_name => $t_lines ) : ?>
                                    <option value="<?php echo esc_attr( $t_name ); ?>" <?php selected( $data['selected_tournament'], $t_name ); ?>>
                                        <?php echo esc_html( $t_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Stacked Shooting Line Select -->
                        <div class="sywat-field-group sywat-field-auto">
                            <label for="sywat_selected_line_idx">Shooting Line:</label>
                            <select id="sywat_selected_line_idx" name="selected_line_idx" class="sywat-select-line" onchange="document.getElementById('sywat_filter_form').submit();">
                                <option value="all" <?php selected( $data['selected_line_idx'], 'all' ); ?>>All Lines</option>
                                <?php foreach ( $data['lines'] as $idx => $line ) : ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $data['selected_line_idx'], (string) $idx ); ?>>
                                        <?php echo esc_html( $line['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <?php if ( $is_hub_enabled ) : ?>
                        <a href="<?php echo esc_url( sywat_page_url( 'volunteer_hub' ) ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back">Back to Hub</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php
    }
}

/**
 * Render chronologically ordered Line Capacity Tracker cards with Walk-In & Waitlist triggers.
 *
 * @param array $data Aggregated Check-In Desk dataset.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_render_capacity_tracker' ) ) {
    function sywat_desk_render_capacity_tracker( $data ) {
        $lines               = $data['lines'];
        $registrations       = $data['registrations'];
        $selected_tournament = $data['selected_tournament'];
        $selected_line_idx   = isset( $data['selected_line_idx'] ) ? (string) $data['selected_line_idx'] : 'all';
        $is_admin            = $data['is_admin'];

        $active_t_slug    = isset( $data['active_t_slug'] ) ? $data['active_t_slug'] : sanitize_title( $selected_tournament );
        $closed_lines_key = 'sywat_closed_lines_' . $active_t_slug;

        $raw_closed   = get_option( $closed_lines_key, array() );
        $closed_lines = is_array( $raw_closed ) ? array_map( 'trim', array_map( 'strval', $raw_closed ) ) : array();

        $desk_base_url = function_exists( 'sywat_page_url' )
            ? add_query_arg(
                array(
                    'selected_tournament' => $selected_tournament,
                    'selected_line_idx'   => $selected_line_idx,
                ),
                sywat_page_url( 'desk' )
            )
            : '';

        // Count total waitlisted archers across the selected tournament for header quick-action button
        $total_tourn_waitlist = 0;
        if ( is_array( $registrations ) ) {
            foreach ( $registrations as $r ) {
                if ( isset( $r['tournament'], $r['status'] )
                    && strcasecmp( trim( (string) $r['tournament'] ), trim( (string) $selected_tournament ) ) === 0
                    && strcasecmp( trim( (string) $r['status'] ), 'Waitlist' ) === 0 ) {
                    $total_tourn_waitlist++;
                }
            }
        }
        ?>
        <!-- Nested Section: Line Capacity Tracker -->
        <div class="sywat-tracker-container">
            <div class="sywat-table-header-bar">
                <h2>Line Capacity Tracker</h2>
                <div class="sywat-table-header-controls">
                    <?php if ( $total_tourn_waitlist > 0 && function_exists( 'sywat_waitlist_render_button_link' ) ) : ?>
                        <?php sywat_waitlist_render_button_link( $selected_tournament, $total_tourn_waitlist, 'all', $desk_base_url, 'Waitlist' ); ?>
                    <?php endif; ?>
                    <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="openWalkinModal()">Add Walk-In</button>
                </div>
            </div>
            <?php if ( ! empty( $lines ) && is_array( $lines ) ) : ?>
                <?php
                    if ( function_exists( 'sywat_compare_tournament_lines' ) ) {
                        uasort( $lines, 'sywat_compare_tournament_lines' );
                    }
                ?>

                <div class="sywat-tracker-grid">
                    <?php foreach ( $lines as $raw_idx => $line ) : ?>
                        <?php
                            $idx       = (string) $raw_idx;
                            $line_name = isset( $line['name'] ) ? trim( $line['name'] ) : '';

                            $booked_20yd     = 0;
                            $booked_cub      = 0;
                            $total_reg_line  = 0;
                            $checked_in_line = 0;
                            $waitlist_line   = 0;

                            foreach ( $registrations as $reg ) {
                                if ( isset( $reg['tournament'] ) && $reg['tournament'] === $selected_tournament ) {
                                    $match_index = isset( $reg['line_index'] ) && (string) $reg['line_index'] === $idx;
                                    $match_name  = isset( $reg['line_name'] ) && $line_name !== '' && trim( $reg['line_name'] ) === $line_name;

                                    if ( $match_index || $match_name ) {
                                        $status_str = isset( $reg['status'] ) ? trim( $reg['status'] ) : '';
                                        if ( strcasecmp( $status_str, 'Cancelled' ) === 0 ) {
                                            continue;
                                        }

                                        $is_wait = ( strcasecmp( $status_str, 'Waitlist' ) === 0 );
                                        if ( $is_wait ) {
                                            $waitlist_line++;
                                        } else {
                                            $total_reg_line++;
                                            if ( ! empty( $reg['checked_in'] ) ) {
                                                $checked_in_line++;
                                            }
                                            if ( isset( $reg['distance'] ) && $reg['distance'] === '20yd' ) {
                                                $booked_20yd++;
                                            }
                                            if ( isset( $reg['distance'] ) && $reg['distance'] === 'Cub' ) {
                                                $booked_cub++;
                                            }
                                        }
                                    }
                                }
                            }

                            $cap_20yd_num = max( 0, intval( isset( $line['cap_20yd'] ) ? $line['cap_20yd'] : 0 ) - $booked_20yd );
                            $cap_cub_num  = max( 0, intval( isset( $line['cap_cub'] ) ? $line['cap_cub'] : 0 ) - $booked_cub );

                            $disp_20yd = ( $cap_20yd_num === 0 ) ? '<span class="sywat-badge sywat-badge-full">FULL</span>' : $cap_20yd_num . ' Open';
                            $disp_cub  = ( $cap_cub_num === 0 ) ? '<span class="sywat-badge sywat-badge-full">FULL</span>' : $cap_cub_num . ' Open';

                            $is_closed_line = in_array( $idx, $closed_lines, true ) || ( $line_name !== '' && in_array( $line_name, $closed_lines, true ) );
                        ?>
                        <div class="sywat-tracker-card">
                            <div>
                                <div class="sywat-tracker-card-header">
                                    <strong class="sywat-tracker-card-title"><?php echo esc_html( $line['name'] ); ?></strong>
                                    <div class="sywat-tracker-header-badges sywat-table-actions-vertical">
                                        <span class="sywat-badge sywat-badge-prereg">
                                            <?php echo $checked_in_line . ' / ' . $total_reg_line . ' Checked In'; ?>
                                        </span>
                                        <?php if ( $waitlist_line > 0 ) : ?>
                                            <?php if ( function_exists( 'sywat_waitlist_render_badge_link' ) ) : ?>
                                                <?php sywat_waitlist_render_badge_link( $selected_tournament, $waitlist_line, $idx, $desk_base_url ); ?>
                                            <?php else : ?>
                                                <span class="sywat-badge sywat-waitlist-pill">🕒 <?php echo $waitlist_line; ?> Waitlisted</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="sywat-tracker-card-stats">
                                    <span><strong>20yd:</strong> <?php echo $disp_20yd; ?></span>
                                    <span><strong>Cub:</strong> <?php echo $disp_cub; ?></span>
                                </div>
                            </div>
                            
                            <div class="sywat-tracker-card-actions">
                                <?php if ( ! $is_closed_line ) : ?>
                                    <form method="post">
                                        <?php sywat_desk_render_hidden_context( $selected_tournament, $idx, $data['search_query'] ); ?>
                                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin">Check In Line</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ( $waitlist_line > 0 && function_exists( 'sywat_waitlist_render_button_link' ) ) : ?>
                                    <?php sywat_waitlist_render_button_link( $selected_tournament, $waitlist_line, $idx, $desk_base_url, 'Waitlist' ); ?>
                                <?php endif; ?>

                                <?php if ( $is_admin ) : ?>
                                    <?php if ( $is_closed_line ) : ?>
                                        <form method="post">
                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                            <input type="hidden" name="sywat_action" value="reopen_line">
                                            <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                            <?php sywat_desk_render_hidden_context( $selected_tournament, $idx, $data['search_query'] ); ?>
                                            <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-unlock">Reopen</button>
                                        </form>
                                    <?php else : ?>
                                        <form method="post" onsubmit="return confirm('Are you sure you want to close this line? All un-checked-in archers will be permanently marked as No Show.');">
                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                            <input type="hidden" name="sywat_action" value="close_line">
                                            <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                            <?php sywat_desk_render_hidden_context( $selected_tournament, $idx, $data['search_query'] ); ?>
                                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-lock">Close</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="sywat-empty-text">No shooting lines configured.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}

/**
 * Render Active Registered Archers table with Check-In, Edit, and household OWE badges.
 *
 * @param array $data Aggregated Check-In Desk dataset.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_render_archers_table' ) ) {
    function sywat_desk_render_archers_table( $data ) {
        $tourn_registrations = $data['tourn_registrations'];
        $all_tourn_regs      = isset( $data['registrations'] ) ? $data['registrations'] : $tourn_registrations;
        $search_query        = $data['search_query'];
        $selected_tournament = $data['selected_tournament'];
        $selected_line_idx   = $data['selected_line_idx'];
        $lines               = $data['lines'];
        $tournaments_map     = ! empty( $data['tournaments_full'] ) ? $data['tournaments_full'] : ( isset( $data['tournaments'] ) ? $data['tournaments'] : array() );

        $t_profiles     = isset( $data['pricing_profiles'] ) && is_array( $data['pricing_profiles'] ) ? $data['pricing_profiles'] : array();
        $is_multi_round = ! empty( $data['is_multi_round'] ) || count( $t_profiles ) > 1;

        // Resolve active tournament Family Cap threshold for fallback evaluations
        $active_cap_amount = 75.00;
        if ( ! empty( $t_profiles ) ) {
            $max_prof_cap = 0.00;
            foreach ( $t_profiles as $tp ) {
                if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_amount'] ) ) {
                    $max_prof_cap = max( $max_prof_cap, floatval( $tp['family_cap_amount'] ) );
                }
            }
            if ( $max_prof_cap > 0 ) {
                $active_cap_amount = $max_prof_cap;
            }
        }

        $active_t_slug    = isset( $data['active_t_slug'] ) ? $data['active_t_slug'] : sanitize_title( $selected_tournament );
        $closed_lines_key = 'sywat_closed_lines_' . $active_t_slug;

        $raw_closed   = get_option( $closed_lines_key, array() );
        $closed_lines = is_array( $raw_closed ) ? array_map( 'trim', array_map( 'strval', $raw_closed ) ) : array();

        // Filter out Waitlisted & Cancelled archers from the active check-in table
        // (Waitlisted archers are managed on-demand via the Dedicated Waitlist Management Modal)
        $active_checkins = array_values( array_filter( $tourn_registrations, function( $reg ) {
            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Waitlist' ) === 0 || strcasecmp( $status, 'Cancelled' ) === 0 ) {
                return false;
            }
            return true;
        } ) );

        $desk_clean_url = function_exists( 'sywat_page_url' )
            ? add_query_arg(
                array(
                    'selected_tournament' => $selected_tournament,
                    'selected_line_idx'   => $selected_line_idx,
                ),
                sywat_page_url( 'desk' )
            )
            : '';
        ?>
        <!-- Nested Section: Registered Archers Table -->
        <div>
            <div class="sywat-table-header-bar">
                <h2>Registered Archers (<?php echo intval( count( $active_checkins ) ); ?>)</h2>
                <div class="sywat-table-header-controls">
                    <div class="sywat-search-input-group">
                        <input type="text" id="sywat_search_input" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Search name..." autocomplete="off" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="document.getElementById('sywat_search_input').value=''; document.getElementById('sywat_search_input').dispatchEvent(new Event('input'));">Clear</button>
                    </div>
                </div>
            </div>

            <?php if ( ! empty( $active_checkins ) ) : ?>
                <div class="sywat-table-responsive">
                    <table class="sywat-table sywat-table-mobile-cards">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th class="sywat-col-center">Type</th>
                                <th>Line</th>
                                <th>Division</th>
                                <th class="sywat-col-center">Gender</th>
                                <th>Style</th>
                                <th>Rate</th>
                                <th class="sywat-col-center">Distance</th>
                                <th class="sywat-col-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $active_checkins as $reg ) : ?>
                                <?php
                                    $reg_type    = isset( $reg['type'] ) && $reg['type'] === 'Walk-In' ? 'Walk-In' : 'Pre-Reg';
                                    $badge_class = ( $reg_type === 'Walk-In' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg';
                                    $balance_due = isset( $reg['balance_due'] ) ? floatval( $reg['balance_due'] ) : 0;

                                    // Clean Line Title Resolution
                                    $resolved_l_idx = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $reg, $lines ) : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );
                                    $reg_line_name  = ( isset( $reg['line_name'] ) && ! empty( trim( $reg['line_name'] ) ) )
                                                        ? trim( $reg['line_name'] )
                                                        : ( isset( $lines[ $resolved_l_idx ]['name'] ) ? trim( $lines[ $resolved_l_idx ]['name'] ) : 'Line ' . ( intval( $resolved_l_idx ) + 1 ) );

                                    $is_line_closed = in_array( (string) $resolved_l_idx, $closed_lines, true ) || ( $reg_line_name !== '' && in_array( $reg_line_name, $closed_lines, true ) );
                                    $is_checked_in  = ! empty( $reg['checked_in'] );

                                    $raw_div    = ! empty( $reg['division'] ) ? $reg['division'] : '—';
                                    $is_no_show = ! $is_checked_in && ( $is_line_closed || $raw_div === 'No Show' );

                                    // Uniform No Show Metadata Overrides
                                    $div_disp    = $is_no_show ? 'No Show' : $raw_div;
                                    $gender_disp = $is_no_show ? 'No Show' : ( ! empty( $reg['gender'] ) ? $reg['gender'] : 'N/A' );
                                    $style_disp  = $is_no_show ? 'No Show' : ( ! empty( $reg['style'] ) ? $reg['style'] : '—' );
                                    $paid_disp   = $is_no_show ? 'No Show' : ( ! empty( $reg['paid'] ) ? $reg['paid'] : '—' );
                                    $dist_disp   = $is_no_show ? 'No Show' : ( ! empty( $reg['distance'] ) ? $reg['distance'] : 'N/A' );

                                    $is_promo_free = ! $is_no_show && (
                                        ! empty( $reg['is_promo_free'] )
                                        || strcasecmp( $paid_disp, 'Promo (Free)' ) === 0
                                        || stripos( $paid_disp, 'Promo' ) === 0
                                    );

                                    $round_slug  = ! empty( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : '';
                                    $round_label = '';
                                    if ( $is_multi_round && $round_slug !== '' ) {
                                        if ( isset( $t_profiles[ $round_slug ] ) ) {
                                            $round_label = $t_profiles[ $round_slug ]['label'];
                                        } elseif ( function_exists( 'sywat_find_pricing_profile' ) ) {
                                            $rp = sywat_find_pricing_profile( $round_slug );
                                            if ( is_array( $rp ) && ! empty( $rp['label'] ) ) {
                                                $round_label = $rp['label'];
                                            }
                                        }
                                    }

                                    $is_family_due = ! $is_no_show && ( ( isset( $reg['paid'] ) && strcasecmp( $reg['paid'], 'Family' ) === 0 ) || ( isset( $reg['rate_type'] ) && strtolower( $reg['rate_type'] ) === 'family' ) );

                                    // Evaluate household-wide unpaid balance so every family member reflects an open family balance
                                    $household_info = function_exists( 'sywat_get_household_balance_info' )
                                        ? sywat_get_household_balance_info( $reg, $all_tourn_regs, $lines )
                                        : null;

                                    $effective_due      = $household_info ? floatval( $household_info['effective_due'] ) : $balance_due;
                                    $is_true_family_cap = $household_info ? ! empty( $household_info['is_family_capped'] ) : false;

                                    if ( ! $household_info && $is_family_due ) {
                                        $fam_id = ! empty( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : ( ! empty( $reg['booking_id'] ) ? trim( (string) $reg['booking_id'] ) : '' );
                                        if ( $fam_id !== '' ) {
                                            foreach ( $all_tourn_regs as $other_r ) {
                                                $o_status = isset( $other_r['status'] ) ? trim( (string) $other_r['status'] ) : '';
                                                if ( strcasecmp( $o_status, 'Waitlist' ) === 0 || strcasecmp( $o_status, 'Cancelled' ) === 0 ) {
                                                    continue;
                                                }
                                                $o_fam  = ! empty( $other_r['family_id'] ) ? trim( (string) $other_r['family_id'] ) : '';
                                                $o_book = ! empty( $other_r['booking_id'] ) ? trim( (string) $other_r['booking_id'] ) : '';
                                                if ( $o_fam === $fam_id || $o_book === $fam_id ) {
                                                    $effective_due = max( $effective_due, isset( $other_r['balance_due'] ) ? floatval( $other_r['balance_due'] ) : 0 );
                                                }
                                            }
                                        }
                                        $is_true_family_cap = ( $effective_due >= $active_cap_amount );
                                    }
                                ?>
                                <tr class="sywat-archer-row" data-name="<?php echo esc_attr( strtolower( $reg['name'] ) ); ?>">
                                    <td>
                                        <div class="sywat-archer-name">
                                            <?php echo esc_html( $reg['name'] ); ?>
                                            <?php if ( $round_label !== '' ) : ?>
                                                <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $round_label ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <!-- OWE Badge Rule: Show across all members of an unpaid household; suppress for No Shows and Zero Balances -->
                                        <?php if ( ! $is_no_show && $effective_due > 0 ) : ?>
                                            <div class="sywat-owe-badge-wrap">
                                                <span class="sywat-badge sywat-badge-full">
                                                    <?php if ( $is_true_family_cap || ( $is_family_due && $household_info && ! empty( $household_info['has_family'] ) ) ) : ?>
                                                        ⚠️ OWE: FAMILY
                                                    <?php else : ?>
                                                        ⚠️ OWE $<?php echo number_format( $effective_due, 2 ); ?>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sywat-col-center" data-label="Type">
                                        <span class="sywat-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $reg_type ); ?></span>
                                    </td>
                                    <td data-label="Line"><?php echo esc_html( $reg_line_name ); ?></td>
                                    <td data-label="Division"><?php echo esc_html( $div_disp ); ?></td>
                                    <td class="sywat-col-center" data-label="Gender"><?php echo esc_html( $gender_disp ); ?></td>
                                    <td data-label="Style"><?php echo esc_html( $style_disp ); ?></td>
                                    <td class="sywat-col-paid" data-label="Rate">
                                        <?php if ( $is_promo_free ) : ?>
                                            <span class="sywat-badge sywat-badge-open">Promo ($0.00)</span>
                                        <?php else : ?>
                                            <?php echo esc_html( $paid_disp ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sywat-col-center" data-label="Distance"><?php echo esc_html( $dist_disp ); ?></td>
                                    <td class="sywat-col-center">
                                        <div class="sywat-table-actions-vertical">
                                            <?php if ( $is_line_closed ) : ?>
                                                <?php if ( $is_checked_in ) : ?>
                                                    <span class="sywat-badge sywat-badge-open">✓ Checked In</span>
                                                <?php else : ?>
                                                    <span class="sywat-badge sywat-badge-full">Line Closed</span>
                                                <?php endif; ?>
                                            <?php elseif ( $is_checked_in ) : ?>
                                                <form method="post" class="sywat-table-action-form">
                                                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                    <?php sywat_desk_render_hidden_context( $selected_tournament, $selected_line_idx, $search_query ); ?>
                                                    <input type="hidden" name="sywat_action" value="undo_checkin">
                                                    <input type="hidden" name="reg_id" value="<?php echo esc_attr( $reg['id'] ); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-checkedin sywat-btn-icon-checkedin">Checked In</button>
                                                </form>
                                            <?php else : ?>
                                                <form method="post" class="sywat-table-action-form">
                                                    <?php sywat_desk_render_hidden_context( $selected_tournament, $selected_line_idx, $search_query ); ?>
                                                    <input type="hidden" name="open_checkin_id" value="<?php echo esc_attr( $reg['id'] ); ?>">
                                                    <button type="submit" class="sywat-btn sywat-btn-success-solid sywat-btn-icon-checkin">Check In Archer</button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Edit Form -->
                                            <form method="post" class="sywat-table-action-form">
                                                <?php sywat_desk_render_hidden_context( $selected_tournament, $selected_line_idx, $search_query ); ?>
                                                <input type="hidden" name="open_edit_id" value="<?php echo esc_attr( $reg['id'] ); ?>">
                                                <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p class="sywat-empty-text">No active archers registered for this selection.</p>
            <?php endif; ?>
        </div>

        <!-- Close Master Single Unified Desk Card -->
        </div>

        <?php
        // Dedicated Waitlist Management Modal (#sywat_waitlist_modal)
        if ( function_exists( 'sywat_maybe_render_waitlist_modal' ) ) {
            sywat_maybe_render_waitlist_modal(
                $tournaments_map,
                $all_tourn_regs,
                $selected_tournament,
                $desk_clean_url,
                'checkin',
                array(
                    'search_query' => $search_query,
                    'close_url'    => $desk_clean_url,
                )
            );
        }
    }
}
