<?php
/**
 * Sub-Module: Check-In Desk Visual Views
 * Path: includes/checkin/checkin-views.php
 * Description: Pure view renderer for the Check-In Desk portal. Renders role-gated desk navigation tabs with sticky tournament carryover, chronologically ordered Line Capacity Tracker with single-pass O(R) pre-indexed slot & waitlist counters and instant client-side line filter triggers, Active Registered Archers table formatted as "LastName, FirstName" consuming pre-formatted display & modal JSON payloads from includes/checkin/checkin-helpers.php for instantaneous zero-reload Check-In & Edit modals, one-click Needs Check-In filter toggle button and Show All reset trigger, responsive mobile card labels, and the dedicated Waitlist Management Modal (#sywat_waitlist_modal) on demand.
 * Compliance: Pure view layer — zero database queries, zero business/fee calculations, zero output at include time, zero inline styles, optimizer-safe data-sywat-action triggers, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.8.1
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
        <input type="hidden" name="selected_line_idx" class="sywat-desk-hidden-line" value="<?php echo esc_attr( $selected_line_idx ); ?>">
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
                <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" data-sywat-action="continue-family-loop" data-family-id="<?php echo esc_attr( $added_family_prompt['family_id'] ); ?>" data-line-idx="<?php echo esc_attr( $added_family_prompt['line_idx'] ); ?>">
                    Yes, Add Next Family Member
                </button>
                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" data-sywat-action="dismiss-family-banner">
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

        $t_master = ( isset( $data['selected_tournament_data'] ) && is_array( $data['selected_tournament_data'] ) )
            ? $data['selected_tournament_data']
            : ( ( isset( $data['tournaments_full'][ $selected_t ] ) && is_array( $data['tournaments_full'][ $selected_t ] ) ) ? $data['tournaments_full'][ $selected_t ] : array() );

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
                            <select id="sywat_selected_tournament" name="selected_tournament" class="sywat-select-tournament" data-sywat-action="submit-desk-tournament">
                                <?php foreach ( $data['tournaments'] as $t_name => $t_lines ) : ?>
                                    <option value="<?php echo esc_attr( $t_name ); ?>" <?php selected( $data['selected_tournament'], $t_name ); ?>>
                                        <?php echo esc_html( $t_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Stacked Shooting Line Select (Instant Client-Side Line Filter) -->
                        <div class="sywat-field-group sywat-field-auto">
                            <label for="sywat_selected_line_idx">Shooting Line:</label>
                            <select id="sywat_selected_line_idx" name="selected_line_idx" class="sywat-select-line" data-sywat-action="filter-desk-line">
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
 * Render chronologically ordered Line Capacity Tracker cards with single-pass O(R)
 * pre-indexed line counters and Walk-In & Waitlist triggers.
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

        $closed_lines = ( isset( $data['closed_lines'] ) && is_array( $data['closed_lines'] ) )
            ? array_map( 'trim', array_map( 'strval', $data['closed_lines'] ) )
            : array();

        $desk_base_url = function_exists( 'sywat_page_url' )
            ? add_query_arg(
                array(
                    'selected_tournament' => $selected_tournament,
                    'selected_line_idx'   => $selected_line_idx,
                ),
                sywat_page_url( 'desk' )
            )
            : '';

        if ( ! empty( $lines ) && is_array( $lines ) && function_exists( 'sywat_compare_tournament_lines' ) ) {
            uasort( $lines, 'sywat_compare_tournament_lines' );
        }

        // Pre-index line lookup keys and counters in a single O(R) pass
        $line_stats           = array();
        $line_name_to_idx     = array();
        $total_tourn_waitlist = 0;

        if ( ! empty( $lines ) && is_array( $lines ) ) {
            foreach ( $lines as $raw_idx => $line ) {
                $idx_str                = (string) $raw_idx;
                $l_name                 = isset( $line['name'] ) ? trim( (string) $line['name'] ) : '';
                $line_stats[ $idx_str ] = array(
                    'booked_20yd' => 0,
                    'booked_cub'  => 0,
                    'total_reg'   => 0,
                    'checked_in'  => 0,
                    'waitlist'    => 0,
                );
                if ( $l_name !== '' && ! isset( $line_name_to_idx[ $l_name ] ) ) {
                    $line_name_to_idx[ $l_name ] = $idx_str;
                }
            }
        }

        if ( is_array( $registrations ) ) {
            foreach ( $registrations as $reg ) {
                if ( ! is_array( $reg ) || ! isset( $reg['tournament'] ) ) {
                    continue;
                }
                if ( strcasecmp( trim( (string) $reg['tournament'] ), trim( (string) $selected_tournament ) ) !== 0 ) {
                    continue;
                }

                $status_str = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
                if ( strcasecmp( $status_str, 'Cancelled' ) === 0 ) {
                    continue;
                }

                $is_wait = ( strcasecmp( $status_str, 'Waitlist' ) === 0 );
                if ( $is_wait ) {
                    $total_tourn_waitlist++;
                }

                $resolved_idx = '';
                if ( isset( $reg['line_index'] ) ) {
                    $raw_l_idx = (string) $reg['line_index'];
                    if ( isset( $line_stats[ $raw_l_idx ] ) ) {
                        $resolved_idx = $raw_l_idx;
                    }
                }
                if ( $resolved_idx === '' && isset( $reg['line_name'] ) ) {
                    $raw_l_name = trim( (string) $reg['line_name'] );
                    if ( $raw_l_name !== '' && isset( $line_name_to_idx[ $raw_l_name ] ) ) {
                        $resolved_idx = $line_name_to_idx[ $raw_l_name ];
                    }
                }

                if ( $resolved_idx !== '' && isset( $line_stats[ $resolved_idx ] ) ) {
                    if ( $is_wait ) {
                        $line_stats[ $resolved_idx ]['waitlist']++;
                    } else {
                        $line_stats[ $resolved_idx ]['total_reg']++;
                        if ( ! empty( $reg['checked_in'] ) ) {
                            $line_stats[ $resolved_idx ]['checked_in']++;
                        }
                        if ( isset( $reg['distance'] ) && $reg['distance'] === '20yd' ) {
                            $line_stats[ $resolved_idx ]['booked_20yd']++;
                        } elseif ( isset( $reg['distance'] ) && $reg['distance'] === 'Cub' ) {
                            $line_stats[ $resolved_idx ]['booked_cub']++;
                        }
                    }
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
                    <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" data-sywat-action="open-desk-walkin-modal">Add Walk-In</button>
                </div>
            </div>
            <?php if ( ! empty( $lines ) && is_array( $lines ) ) : ?>
                <div class="sywat-tracker-grid">
                    <?php foreach ( $lines as $raw_idx => $line ) : ?>
                        <?php
                            $idx       = (string) $raw_idx;
                            $line_name = isset( $line['name'] ) ? trim( (string) $line['name'] ) : '';
                            $stats     = isset( $line_stats[ $idx ] )
                                ? $line_stats[ $idx ]
                                : array( 'booked_20yd' => 0, 'booked_cub' => 0, 'total_reg' => 0, 'checked_in' => 0, 'waitlist' => 0 );

                            $booked_20yd     = $stats['booked_20yd'];
                            $booked_cub      = $stats['booked_cub'];
                            $total_reg_line  = $stats['total_reg'];
                            $checked_in_line = $stats['checked_in'];
                            $waitlist_line   = $stats['waitlist'];

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
                                    <div class="sywat-tracker-header-badges">
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
                                    <form method="post" class="sywat-desk-line-filter-form">
                                        <?php sywat_desk_render_hidden_context( $selected_tournament, $idx, $data['search_query'] ); ?>
                                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin" data-sywat-action="select-desk-line" data-line-idx="<?php echo esc_attr( $idx ); ?>">Check In Line</button>
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
 * Render Active Registered Archers table consuming pre-computed presentation & modal payloads
 * from includes/checkin/checkin-helpers.php for instantaneous zero-reload Check-In & Edit modals,
 * one-click Needs Check-In filter toggle button, and Show All reset trigger.
 *
 * @param array $data Aggregated Check-In Desk dataset.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_render_archers_table' ) ) {
    function sywat_desk_render_archers_table( $data ) {
        $active_checkins     = isset( $data['active_checkins'] ) && is_array( $data['active_checkins'] ) ? $data['active_checkins'] : array();
        $all_tourn_regs      = isset( $data['registrations'] ) ? $data['registrations'] : $active_checkins;
        $search_query        = isset( $data['search_query'] ) ? (string) $data['search_query'] : '';
        $selected_tournament = isset( $data['selected_tournament'] ) ? (string) $data['selected_tournament'] : '';
        $selected_line_idx   = isset( $data['selected_line_idx'] ) ? (string) $data['selected_line_idx'] : 'all';
        $tournaments_map     = ! empty( $data['tournaments_full'] ) ? $data['tournaments_full'] : ( isset( $data['tournaments'] ) ? $data['tournaments'] : array() );

        $clean_search_init         = strtolower( trim( $search_query ) );
        $initial_visible_count     = 0;
        $initial_needs_checkin_cnt = 0;

        foreach ( $active_checkins as $reg ) {
            $r_line        = isset( $reg['resolved_line_idx'] ) ? (string) $reg['resolved_line_idx'] : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );
            $r_tokens      = isset( $reg['search_tokens'] ) ? (string) $reg['search_tokens'] : strtolower( trim( (string) ( isset( $reg['name'] ) ? $reg['name'] : '' ) ) );
            $r_checked_in  = ! empty( $reg['is_checked_in'] );
            $r_line_closed = ! empty( $reg['is_line_closed'] );
            $line_ok       = ( $selected_line_idx === 'all' || $selected_line_idx === '' || $r_line === $selected_line_idx );
            $query_ok      = ( $clean_search_init === '' || strpos( $r_tokens, $clean_search_init ) !== false );

            if ( $line_ok && $query_ok ) {
                $initial_visible_count++;
                if ( ! $r_checked_in && ! $r_line_closed ) {
                    $initial_needs_checkin_cnt++;
                }
            }
        }

        $is_initial_filtered = ( ( $selected_line_idx !== 'all' && $selected_line_idx !== '' ) || $clean_search_init !== '' );

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
                <h2>Registered Archers (<span id="sywat_desk_visible_count"><?php echo intval( $initial_visible_count ); ?></span>)</h2>

                <div class="sywat-table-header-controls">
                    <button type="button"
                            id="sywat_desk_needs_checkin_btn"
                            class="sywat-btn sywat-btn-secondary"
                            data-sywat-action="toggle-desk-needs-checkin"
                            aria-pressed="false">
                        Needs Check-In (<span id="sywat_desk_needs_checkin_count"><?php echo intval( $initial_needs_checkin_cnt ); ?></span>)
                    </button>

                    <button type="button"
                            id="sywat_desk_reset_view_btn"
                            class="sywat-btn sywat-btn-secondary<?php echo $is_initial_filtered ? '' : ' sywat-hidden'; ?>"
                            data-sywat-action="reset-desk-view">
                        Show All
                    </button>

                    <div class="sywat-search-input-group">
                        <input type="text" id="sywat_search_input" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Search name..." autocomplete="off">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" data-sywat-action="clear-desk-search">Clear</button>
                    </div>
                </div>
            </div>

            <?php if ( ! empty( $active_checkins ) ) : ?>
                <div class="sywat-table-responsive">
                    <table class="sywat-table sywat-table-mobile-cards" id="sywat_desk_archers_table">
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
                                    $disp_archer_name = isset( $reg['display_name'] ) ? $reg['display_name'] : ( isset( $reg['name'] ) ? $reg['name'] : '' );
                                    $search_tokens    = isset( $reg['search_tokens'] ) ? $reg['search_tokens'] : strtolower( trim( $disp_archer_name ) );
                                    $resolved_l_idx   = isset( $reg['resolved_line_idx'] ) ? (string) $reg['resolved_line_idx'] : '0';
                                    $is_line_closed   = ! empty( $reg['is_line_closed'] );
                                    $is_checked_in    = ! empty( $reg['is_checked_in'] );
                                    $needs_checkin    = ( ! $is_checked_in && ! $is_line_closed );
                                    $is_promo_free    = ! empty( $reg['is_promo_free'] );
                                    $round_label      = isset( $reg['round_label'] ) ? (string) $reg['round_label'] : '';
                                    $owe_badge_text   = isset( $reg['owe_badge_text'] ) ? (string) $reg['owe_badge_text'] : '';
                                    $tbl              = isset( $reg['table_display'] ) && is_array( $reg['table_display'] ) ? $reg['table_display'] : array();
                                    $modal_payload    = isset( $reg['modal_payload'] ) && is_array( $reg['modal_payload'] ) ? $reg['modal_payload'] : array();

                                    $reg_type    = isset( $tbl['type'] ) ? $tbl['type'] : 'Pre-Reg';
                                    $badge_class = isset( $tbl['badge_class'] ) ? $tbl['badge_class'] : 'sywat-badge-prereg';
                                    $line_disp   = isset( $tbl['line'] ) ? $tbl['line'] : '';
                                    $div_disp    = isset( $tbl['division'] ) ? $tbl['division'] : '—';
                                    $gender_disp = isset( $tbl['gender'] ) ? $tbl['gender'] : 'N/A';
                                    $style_disp  = isset( $tbl['style'] ) ? $tbl['style'] : '—';
                                    $paid_disp   = isset( $tbl['paid'] ) ? $tbl['paid'] : '—';
                                    $dist_disp   = isset( $tbl['distance'] ) ? $tbl['distance'] : 'N/A';

                                    $is_row_line_match  = ( $selected_line_idx === 'all' || $selected_line_idx === '' || $resolved_l_idx === $selected_line_idx );
                                    $is_row_query_match = ( $clean_search_init === '' || strpos( $search_tokens, $clean_search_init ) !== false );
                                    $is_row_visible     = ( $is_row_line_match && $is_row_query_match );
                                ?>
                                <tr class="sywat-archer-row<?php echo $is_row_visible ? '' : ' sywat-hidden'; ?>"
                                    data-line-idx="<?php echo esc_attr( $resolved_l_idx ); ?>"
                                    data-needs-checkin="<?php echo $needs_checkin ? '1' : '0'; ?>"
                                    data-name="<?php echo esc_attr( $search_tokens ); ?>"
                                    data-archer="<?php echo esc_attr( wp_json_encode( $modal_payload ) ); ?>">
                                    <td>
                                        <div class="sywat-archer-name">
                                            <?php echo esc_html( $disp_archer_name ); ?>
                                            <?php if ( $round_label !== '' ) : ?>
                                                <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $round_label ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <!-- OWE Badge Rule: Show across all members of an unpaid household; suppress for No Shows and Zero Balances -->
                                        <?php if ( $owe_badge_text !== '' ) : ?>
                                            <div class="sywat-owe-badge-wrap">
                                                <span class="sywat-badge sywat-badge-full"><?php echo esc_html( $owe_badge_text ); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sywat-col-center" data-label="Type">
                                        <span class="sywat-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $reg_type ); ?></span>
                                    </td>
                                    <td data-label="Line"><?php echo esc_html( $line_disp ); ?></td>
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
                                                <button type="button" class="sywat-btn sywat-btn-success-solid sywat-btn-icon-checkin" data-sywat-action="open-desk-checkin-modal">Check In Archer</button>
                                            <?php endif; ?>

                                            <!-- Instant Client-Side Edit Trigger -->
                                            <button type="button" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit" data-sywat-action="open-desk-edit-modal">Edit</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p id="sywat_desk_empty_filter_msg" class="sywat-empty-text<?php echo ( $initial_visible_count === 0 ) ? '' : ' sywat-hidden'; ?>">No active archers match the selected line, status, or search filter.</p>
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
