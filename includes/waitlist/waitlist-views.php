<?php
/**
 * Sub-Module: Waitlist Dedicated Management Modal & Trigger Views
 * Path: includes/waitlist/waitlist-views.php
 * Description: Renders the dedicated Waitlist Management Modal (#sywat_waitlist_modal) with a top-of-modal "Move Selected to Open Line" bar, FIFO family-grouped queue table, clean horizontal actions, and reusable clickable waitlist badges/buttons for Register, Manage Tournaments, Roster, and Check-In screens.
 * Compliance: Pure view layer — zero DB writes, zero inline scripts/styles, zero inline event attributes, accessible SVG + text controls, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render accessible inline clock SVG icon for waitlist badges and buttons.
 *
 * @return string SVG markup.
 */
if ( ! function_exists( 'sywat_waitlist_clock_svg' ) ) {
    function sywat_waitlist_clock_svg() {
        return '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
    }
}

/**
 * Resolve current page URL without transient waitlist_modal query parameters.
 *
 * @param string $fallback_url Optional explicit base URL.
 * @return string              Clean base URL.
 */
if ( ! function_exists( 'sywat_waitlist_clean_base_url' ) ) {
    function sywat_waitlist_clean_base_url( $fallback_url = '' ) {
        if ( ! empty( $fallback_url ) ) {
            return remove_query_arg( array( 'waitlist_modal', 'waitlist_tournament', 'waitlist_line' ), $fallback_url );
        }

        $req_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( ! empty( $req_uri ) ) {
            return remove_query_arg( array( 'waitlist_modal', 'waitlist_tournament', 'waitlist_line' ), $req_uri );
        }

        return get_permalink();
    }
}

/**
 * Build a URL that opens the dedicated Waitlist Management Modal for a given tournament and line.
 *
 * @param string     $tourn_name Active tournament name.
 * @param string|int $line_idx   Line index ('all' or specific line index).
 * @param string     $base_url   Optional base page URL.
 * @return string                URL with waitlist modal trigger query args.
 */
if ( ! function_exists( 'sywat_waitlist_get_modal_url' ) ) {
    function sywat_waitlist_get_modal_url( $tourn_name, $line_idx = 'all', $base_url = '' ) {
        $clean_base = sywat_waitlist_clean_base_url( $base_url );
        return add_query_arg(
            array(
                'waitlist_modal'      => '1',
                'waitlist_tournament' => (string) $tourn_name,
                'waitlist_line'       => (string) $line_idx,
            ),
            $clean_base
        );
    }
}

/**
 * Render a clickable "X Waitlisted" pill badge that opens the Waitlist Management Modal.
 *
 * @param string     $tourn_name     Active tournament name.
 * @param int        $waitlist_count Number of waitlisted archers.
 * @param string|int $line_idx       Target line index ('all' or specific line index).
 * @param string     $base_url       Optional base page URL.
 * @return void
 */
if ( ! function_exists( 'sywat_waitlist_render_badge_link' ) ) {
    function sywat_waitlist_render_badge_link( $tourn_name, $waitlist_count, $line_idx = 'all', $base_url = '' ) {
        $count = (int) $waitlist_count;
        if ( $count <= 0 ) {
            return;
        }
        $modal_url = sywat_waitlist_get_modal_url( $tourn_name, $line_idx, $base_url );
        ?>
        <a href="<?php echo esc_url( $modal_url ); ?>" class="sywat-waitlist-pill sywat-waitlist-trigger-link" title="Open Waitlist Management Window">
            <?php echo sywat_waitlist_clock_svg(); ?>
            <span><?php echo intval( $count ); ?> Waitlisted</span>
        </a>
        <?php
    }
}

/**
 * Render a compact action button that opens the Waitlist Management Modal when waitlisted archers exist.
 *
 * @param string     $tourn_name     Active tournament name.
 * @param int        $waitlist_count Number of waitlisted archers.
 * @param string|int $line_idx       Target line index ('all' or specific line index).
 * @param string     $base_url       Optional base page URL.
 * @param string     $label_prefix   Button label prefix (default 'Waitlist').
 * @return void
 */
if ( ! function_exists( 'sywat_waitlist_render_button_link' ) ) {
    function sywat_waitlist_render_button_link( $tourn_name, $waitlist_count, $line_idx = 'all', $base_url = '', $label_prefix = 'Waitlist' ) {
        $count = (int) $waitlist_count;
        if ( $count <= 0 ) {
            return;
        }
        $modal_url = sywat_waitlist_get_modal_url( $tourn_name, $line_idx, $base_url );
        ?>
        <a href="<?php echo esc_url( $modal_url ); ?>" class="sywat-btn sywat-btn-warning sywat-btn-sm sywat-waitlist-trigger-btn" title="Manage Waitlisted Archers">
            <?php echo sywat_waitlist_clock_svg(); ?>
            <span><?php echo esc_html( $label_prefix ); ?> (<?php echo intval( $count ); ?>)</span>
        </a>
        <?php
    }
}

/**
 * Render hidden context inputs so POST submissions inside #sywat_waitlist_modal
 * preserve the active screen context and keep the Waitlist Modal open after reload.
 *
 * @param string $selected_tournament Active tournament name.
 * @param string $filter_line_idx     Current line filter ('all' or numeric index).
 * @param string $context             Host UI context ('register', 'manage', 'roster', 'checkin').
 * @param array  $extra_context       Optional extra context values ('return_to', 'search_query').
 * @return void
 */
if ( ! function_exists( 'sywat_waitlist_render_context_inputs' ) ) {
    function sywat_waitlist_render_context_inputs( $selected_tournament, $filter_line_idx, $context = 'register', $extra_context = array() ) {
        ?>
        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
        <input type="hidden" name="waitlist_modal" value="1">
        <input type="hidden" name="waitlist_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
        <input type="hidden" name="waitlist_line" value="<?php echo esc_attr( $filter_line_idx ); ?>">
        <?php if ( $context === 'roster' || $context === 'manage' ) : ?>
            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
            <?php if ( $context === 'roster' ) : ?>
                <input type="hidden" name="modal_action" value="manage">
            <?php endif; ?>
            <input type="hidden" name="filter_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
            <?php if ( ! empty( $extra_context['return_to'] ) ) : ?>
                <input type="hidden" name="return_to" value="<?php echo esc_attr( $extra_context['return_to'] ); ?>">
            <?php endif; ?>
        <?php elseif ( $context === 'checkin' ) : ?>
            <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
            <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( isset( $extra_context['search_query'] ) ? $extra_context['search_query'] : '' ); ?>">
        <?php endif;
    }
}

/**
 * Render the Dedicated Waitlist Management Popup Modal (#sywat_waitlist_modal).
 * Features a top-of-modal "Move Selected to Open Line" bar, line filter, search box,
 * and a clean 4-column FIFO family-grouped table with horizontal row actions.
 *
 * @param string $selected_tournament Active tournament name.
 * @param array  $available_lines     Configured shooting lines for the tournament.
 * @param array  $registrations       Master or tournament registration records.
 * @param string $filter_line_idx     Current line filter ('all' or numeric line index).
 * @param string $post_action_url     Form submission target URL.
 * @param string $context             Host UI context ('register', 'manage', 'roster', 'checkin').
 * @param array  $extra_context       Optional context values ('return_to', 'search_query', 'close_url').
 * @return void
 */
if ( ! function_exists( 'sywat_render_waitlist_modal' ) ) {
    function sywat_render_waitlist_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx = 'all', $post_action_url = '', $context = 'register', $extra_context = array() ) {
        if ( ! function_exists( 'sywat_waitlist_get_grouped_queue' ) || empty( $selected_tournament ) ) {
            return;
        }

        if ( function_exists( 'sywat_enqueue_waitlist_scripts' ) ) {
            sywat_enqueue_waitlist_scripts();
        }

        $t_slug       = sanitize_title( $selected_tournament );
        $closed_lines = get_option( 'sywat_closed_lines_' . $t_slug, array() );

        $active_line_filter = ( $filter_line_idx === '' ) ? 'all' : (string) $filter_line_idx;
        $queue_data         = sywat_waitlist_get_grouped_queue( $selected_tournament, $available_lines, $registrations, $active_line_filter, $closed_lines );
        $all_queue_data     = ( $active_line_filter !== 'all' )
            ? sywat_waitlist_get_grouped_queue( $selected_tournament, $available_lines, $registrations, 'all', $closed_lines )
            : $queue_data;

        $groups           = isset( $queue_data['groups'] ) ? $queue_data['groups'] : array();
        $total_waitlisted = isset( $queue_data['total_waitlisted'] ) ? (int) $queue_data['total_waitlisted'] : 0;
        $all_waitlisted   = isset( $all_queue_data['total_waitlisted'] ) ? (int) $all_queue_data['total_waitlisted'] : $total_waitlisted;
        $line_capacities  = isset( $queue_data['line_capacities'] ) ? $queue_data['line_capacities'] : array();

        // Count waitlisted archers per line for the top line-filter selector
        $waitlist_counts_by_line = array();
        if ( ! empty( $all_queue_data['groups'] ) ) {
            foreach ( $all_queue_data['groups'] as $ag ) {
                $al_idx = (string) $ag['line_index'];
                if ( ! isset( $waitlist_counts_by_line[ $al_idx ] ) ) {
                    $waitlist_counts_by_line[ $al_idx ] = 0;
                }
                $waitlist_counts_by_line[ $al_idx ] += count( $ag['members'] );
            }
        }

        $close_target_url = ! empty( $extra_context['close_url'] )
            ? $extra_context['close_url']
            : sywat_waitlist_clean_base_url();

        $manage_tourn_url = function_exists( 'sywat_page_url' )
            ? sywat_page_url( 'manage_tournaments' )
            : $close_target_url;
        ?>
        <div class="sywat-modal-backdrop active no-print" id="sywat_waitlist_modal" data-sywat-close-url="<?php echo esc_url( $close_target_url ); ?>">
            <div class="sywat-modal-content sywat-modal-xl">

                <!-- Pinned Modal Header -->
                <div class="sywat-modal-header">
                    <div>
                        <h3>
                            <?php echo sywat_waitlist_clock_svg(); ?>
                            Waitlist Queue Management
                            <span class="sywat-waitlist-pill"><?php echo intval( $total_waitlisted ); ?> Waitlisted</span>
                        </h3>
                        <div class="sywat-subtitle"><?php echo esc_html( $selected_tournament ); ?> &mdash; FIFO arrival order grouped by household</div>
                    </div>
                    <button type="button" class="sywat-modal-close" aria-label="Close Waitlist Modal" data-sywat-action="close-waitlist-modal" data-close-url="<?php echo esc_url( $close_target_url ); ?>">&times;</button>
                </div>

                <!-- Scrollable Modal Body -->
                <div class="sywat-modal-body">

                    <!-- Top Bar 1: Shooting Line Filter & Live Search -->
                    <div class="sywat-roster-filter-bar">
                        <div class="sywat-roster-filter-form">
                            <div class="sywat-filter-field">
                                <label for="sywat_waitlist_line_filter">Filter by Shooting Line</label>
                                <select id="sywat_waitlist_line_filter" data-sywat-action="waitlist-line-filter">
                                    <option value="<?php echo esc_attr( sywat_waitlist_get_modal_url( $selected_tournament, 'all', $close_target_url ) ); ?>" <?php selected( $active_line_filter, 'all' ); ?>>
                                        All Lines (<?php echo intval( $all_waitlisted ); ?> Waitlisted)
                                    </option>
                                    <?php foreach ( $available_lines as $idx => $line ) : ?>
                                        <?php
                                            $idx_str  = (string) $idx;
                                            $l_label  = function_exists( 'sywat_waitlist_format_line_label' )
                                                ? sywat_waitlist_format_line_label( $line, $idx_str )
                                                : ( isset( $line['name'] ) ? $line['name'] : 'Line ' . ( (int) $idx + 1 ) );
                                            $l_wcount = isset( $waitlist_counts_by_line[ $idx_str ] ) ? (int) $waitlist_counts_by_line[ $idx_str ] : 0;
                                            $l_url    = sywat_waitlist_get_modal_url( $selected_tournament, $idx_str, $close_target_url );
                                        ?>
                                        <option value="<?php echo esc_url( $l_url ); ?>" <?php selected( $active_line_filter, $idx_str ); ?>>
                                            <?php echo esc_html( sprintf( '%s (%d Waitlisted)', $l_label, $l_wcount ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-filter-field">
                                <label for="sywat_waitlist_search_input">Search Waitlist (Name / Family)</label>
                                <div class="sywat-search-input-group">
                                    <input type="text" id="sywat_waitlist_search_input" value="" placeholder="Type archer or family name..." autocomplete="off">
                                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-sm" data-sywat-action="clear-waitlist-search">Clear</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ( empty( $groups ) ) : ?>
                        <p class="sywat-label-subtext">No archers currently on the waitlist for this selection.</p>
                    <?php else : ?>

                        <!-- Top Bar 2: Move Selected Archers / Family to Open Line -->
                        <div class="sywat-roster-filter-bar sywat-waitlist-move-toolbar">
                            <div class="sywat-filter-field">
                                <label>Selected Archers</label>
                                <div class="sywat-search-input-group">
                                    <span class="sywat-waitlist-pill"><strong id="sywat_waitlist_selected_count">0</strong>&nbsp;selected</span>
                                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-sm" data-sywat-action="waitlist-select-all">Select All</button>
                                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-sm" data-sywat-action="waitlist-clear-selection">Clear</button>
                                </div>
                            </div>

                            <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" id="sywat_waitlist_move_selected_form" class="sywat-waitlist-move-form sywat-filter-field">
                                <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                <?php sywat_waitlist_render_context_inputs( $selected_tournament, $active_line_filter, $context, $extra_context ); ?>
                                <input type="hidden" name="sywat_action" value="move_waitlist_line">
                                <input type="hidden" name="move_scope" value="selected">

                                <label for="sywat_waitlist_target_line">Move Selected to Open Shooting Line (as Pre-Reg)</label>
                                <div class="sywat-search-input-group">
                                    <select name="target_line_index" id="sywat_waitlist_target_line" required>
                                        <option value="" disabled selected>Choose Destination Open Line...</option>
                                        <?php foreach ( $line_capacities as $l_idx => $cap ) : ?>
                                            <?php
                                                $l_name    = isset( $cap['line_name'] ) ? $cap['line_name'] : ( 'Line ' . ( (int) $l_idx + 1 ) );
                                                $is_closed = ! empty( $cap['is_closed'] );
                                                $open_20   = isset( $cap['open_20yd'] ) ? (int) $cap['open_20yd'] : 0;
                                                $open_cub  = isset( $cap['open_cub'] ) ? (int) $cap['open_cub'] : 0;

                                                if ( $is_closed ) {
                                                    $opt_label = sprintf( '%s (Line Closed)', $l_name );
                                                } else {
                                                    $opt_label = sprintf( '%s — %d 20yd / %d Cub Open', $l_name, $open_20, $open_cub );
                                                }
                                            ?>
                                            <option value="<?php echo esc_attr( (string) $l_idx ); ?>" data-open-20yd="<?php echo intval( $open_20 ); ?>" data-open-cub="<?php echo intval( $open_cub ); ?>" <?php echo $is_closed ? 'disabled' : ''; ?>>
                                                <?php echo esc_html( $opt_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" id="sywat_waitlist_move_btn" class="sywat-btn sywat-btn-primary" disabled>Move Selected to Line</button>
                                </div>
                            </form>
                        </div>

                        <!-- Clean 4-Column Waitlist Queue Table -->
                        <div class="sywat-table-responsive">
                            <table class="sywat-table sywat-waitlist-table">
                                <thead>
                                    <tr>
                                        <th class="sywat-col-center">Select / #</th>
                                        <th>Archer / Family Group</th>
                                        <th>Current Line &amp; Category</th>
                                        <th class="sywat-col-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $groups as $grp ) : ?>
                                        <?php
                                            $q_pos          = (int) $grp['queue_position'];
                                            $members        = $grp['members'];
                                            $member_count   = count( $members );
                                            $is_multi_group = ( $grp['is_family_group'] && $member_count > 1 );
                                            $src_line_idx   = (string) $grp['line_index'];
                                            $src_line_name  = $grp['line_name'];
                                            $active_fam_cnt = (int) $grp['active_family_count'];
                                            $can_fit_all    = ! empty( $grp['can_fit_all'] );
                                            $is_line_closed = ! empty( $grp['is_line_closed'] );
                                            $group_uid      = 'wlgrp_' . $q_pos . '_' . sanitize_title( $grp['group_key'] );

                                            $group_names_list = array();
                                            foreach ( $members as $gm ) {
                                                if ( ! empty( $gm['name'] ) ) {
                                                    $group_names_list[] = strtolower( trim( (string) $gm['name'] ) );
                                                }
                                            }
                                            $group_search_attr = trim( strtolower( $grp['group_label'] ) . ' ' . implode( ' ', $group_names_list ) );
                                        ?>

                                        <?php if ( $is_multi_group ) : ?>
                                            <!-- Family Group Header Banner Row -->
                                            <tr class="sywat-waitlist-group-header-row" data-waitlist-search="<?php echo esc_attr( $group_search_attr ); ?>">
                                                <td class="sywat-col-center">
                                                    <input type="checkbox" aria-label="Select entire <?php echo esc_attr( $grp['group_label'] ); ?> group" data-waitlist-group-checkbox="<?php echo esc_attr( $group_uid ); ?>">
                                                    <span class="sywat-waitlist-pill">#<?php echo intval( $q_pos ); ?></span>
                                                </td>
                                                <td>
                                                    <span class="sywat-chip-family">Family Group: <?php echo esc_html( $grp['group_label'] ); ?> (<?php echo intval( $member_count ); ?> Waitlisted)</span>
                                                    <?php if ( $active_fam_cnt > 0 ) : ?>
                                                        <div class="sywat-label-subtext">
                                                            <strong>Split Family:</strong> <?php echo intval( $active_fam_cnt ); ?> member(s) already active on <?php echo esc_html( $src_line_name ); ?> ($75.00 Family Cap applies at 3+ active archers).
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ( ! empty( $grp['earliest_created_at'] ) ) : ?>
                                                        <div class="sywat-meta-subtext">Added: <?php echo esc_html( $grp['earliest_created_at'] ); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo esc_html( $src_line_name ); ?></strong>
                                                    <div class="sywat-meta-subtext">
                                                        <?php if ( $is_line_closed ) : ?>
                                                            <span class="sywat-badge sywat-badge-full">Line Closed</span>
                                                        <?php else : ?>
                                                            Needs: <?php echo intval( $grp['needed_20yd'] ); ?> 20yd, <?php echo intval( $grp['needed_cub'] ); ?> Cub &bull;
                                                            Open: <?php echo intval( $grp['open_20yd'] ); ?> 20yd, <?php echo intval( $grp['open_cub'] ); ?> Cub
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="sywat-col-center">
                                                    <div class="sywat-modal-roster-actions">
                                                        <?php
                                                            $group_promote_confirm = $can_fit_all
                                                                ? sprintf( 'Promote all %d waitlisted members of %s to Pre-Reg on %s?', $member_count, $grp['group_label'], $src_line_name )
                                                                : sprintf( 'WARNING: %s does not have enough open spots for all %d members of %s. Override line capacity and promote the entire group anyway?', $src_line_name, $member_count, $grp['group_label'] );
                                                        ?>
                                                        <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form" data-sywat-confirm="<?php echo esc_attr( $group_promote_confirm ); ?>">
                                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                            <?php sywat_waitlist_render_context_inputs( $selected_tournament, $active_line_filter, $context, $extra_context ); ?>
                                                            <input type="hidden" name="sywat_action" value="promote_waitlist">
                                                            <input type="hidden" name="promote_scope" value="group">
                                                            <input type="hidden" name="family_id" value="<?php echo esc_attr( $grp['family_id'] ); ?>">
                                                            <input type="hidden" name="source_line_idx" value="<?php echo esc_attr( $src_line_idx ); ?>">
                                                            <?php if ( ! $can_fit_all ) : ?>
                                                                <input type="hidden" name="force_override" value="1">
                                                            <?php endif; ?>
                                                            <button type="submit" class="sywat-btn <?php echo $can_fit_all ? 'sywat-btn-success' : 'sywat-btn-warning'; ?> sywat-btn-sm">
                                                                <?php echo $can_fit_all ? 'Promote All (' . intval( $member_count ) . ')' : 'Override & Promote All (' . intval( $member_count ) . ')'; ?>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ( $members as $m ) : ?>
                                            <?php
                                                $reg_id         = (string) $m['id'];
                                                $archer_name    = isset( $m['name'] ) ? $m['name'] : 'Archer';
                                                $archer_phone   = isset( $m['phone'] ) ? trim( (string) $m['phone'] ) : '';
                                                $m_dist         = isset( $m['resolved_dist'] ) ? $m['resolved_dist'] : '20yd';
                                                $raw_div        = isset( $m['division'] ) ? trim( (string) $m['division'] ) : '';
                                                $m_div          = ( $raw_div !== '' && strcasecmp( $raw_div, 'No Show' ) !== 0 ) ? $raw_div : '—';
                                                $m_created      = ! empty( $m['created_at'] ) ? $m['created_at'] : '';
                                                $can_fit_member = ! empty( $m['can_fit_member'] );
                                                $avail_for_dist = ( $m_dist === 'Cub' ) ? (int) $grp['open_cub'] : (int) $grp['open_20yd'];

                                                $single_promote_confirm = $can_fit_member
                                                    ? sprintf( 'Promote %s (%s) from Waitlist to Pre-Reg on %s?', $archer_name, $m_dist, $src_line_name )
                                                    : sprintf( 'WARNING: %s currently has 0 open %s spots. Override line capacity and promote %s anyway?', $src_line_name, $m_dist, $archer_name );

                                                $row_search_attr = trim( strtolower( $archer_name ) . ' ' . $group_search_attr );

                                                $edit_url = add_query_arg(
                                                    array(
                                                        'tab'             => 'manage',
                                                        'view_tournament' => $selected_tournament,
                                                        'modal_action'    => 'manage',
                                                        'edit_archer'     => $reg_id,
                                                        'filter_line_idx' => $src_line_idx,
                                                    ),
                                                    $manage_tourn_url
                                                );
                                            ?>
                                            <tr class="sywat-waitlist-row <?php echo $is_multi_group ? 'sywat-waitlist-member-subrow' : ''; ?>" data-name="<?php echo esc_attr( strtolower( $archer_name ) ); ?>" data-waitlist-search="<?php echo esc_attr( $row_search_attr ); ?>">
                                                <td class="sywat-col-center">
                                                    <input type="checkbox"
                                                           form="sywat_waitlist_move_selected_form"
                                                           name="selected_reg_ids[]"
                                                           value="<?php echo esc_attr( $reg_id ); ?>"
                                                           aria-label="Select <?php echo esc_attr( $archer_name ); ?>"
                                                           data-waitlist-member-checkbox="<?php echo esc_attr( $group_uid ); ?>"
                                                           data-distance="<?php echo esc_attr( $m_dist ); ?>"
                                                           data-source-line="<?php echo esc_attr( $src_line_idx ); ?>">
                                                    <?php if ( ! $is_multi_group ) : ?>
                                                        <span class="sywat-waitlist-pill">#<?php echo intval( $q_pos ); ?></span>
                                                    <?php else : ?>
                                                        <span class="sywat-label-subtext">#<?php echo intval( $q_pos ); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo esc_html( $archer_name ); ?></strong>
                                                    <?php if ( $is_multi_group ) : ?>
                                                        <span class="sywat-label-subtext">(<?php echo esc_html( $grp['group_label'] ); ?>)</span>
                                                    <?php elseif ( $active_fam_cnt > 0 ) : ?>
                                                        <div>
                                                            <span class="sywat-chip-family">Split Family: <?php echo intval( $active_fam_cnt ); ?> active on this line</span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ( $archer_phone !== '' || ( ! $is_multi_group && $m_created !== '' ) ) : ?>
                                                        <div class="sywat-meta-subtext">
                                                            <?php if ( $archer_phone !== '' ) : ?>
                                                                Phone: <?php echo esc_html( $archer_phone ); ?>
                                                            <?php endif; ?>
                                                            <?php if ( $archer_phone !== '' && ! $is_multi_group && $m_created !== '' ) : ?>
                                                                &bull;
                                                            <?php endif; ?>
                                                            <?php if ( ! $is_multi_group && $m_created !== '' ) : ?>
                                                                Added: <?php echo esc_html( $m_created ); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo esc_html( $src_line_name ); ?></strong> &bull;
                                                        <span><?php echo esc_html( $m_dist ); ?></span>
                                                        <?php if ( $m_div !== '—' ) : ?>
                                                            <span class="sywat-label-subtext">(<?php echo esc_html( $m_div ); ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <?php if ( $is_line_closed ) : ?>
                                                            <span class="sywat-badge sywat-badge-full">Line Closed</span>
                                                        <?php elseif ( $can_fit_member ) : ?>
                                                            <span class="sywat-badge sywat-badge-open"><?php echo intval( $avail_for_dist ); ?> Open (<?php echo esc_html( $m_dist ); ?>)</span>
                                                        <?php else : ?>
                                                            <span class="sywat-badge sywat-badge-full">Full (0 <?php echo esc_html( $m_dist ); ?> Open)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="sywat-col-center">
                                                    <div class="sywat-modal-roster-actions">
                                                        <!-- Single Archer Promote Form -->
                                                        <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form" data-sywat-confirm="<?php echo esc_attr( $single_promote_confirm ); ?>">
                                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                            <?php sywat_waitlist_render_context_inputs( $selected_tournament, $active_line_filter, $context, $extra_context ); ?>
                                                            <input type="hidden" name="sywat_action" value="promote_waitlist">
                                                            <input type="hidden" name="promote_scope" value="single">
                                                            <input type="hidden" name="registration_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                            <input type="hidden" name="source_line_idx" value="<?php echo esc_attr( $src_line_idx ); ?>">
                                                            <?php if ( ! $can_fit_member ) : ?>
                                                                <input type="hidden" name="force_override" value="1">
                                                            <?php endif; ?>
                                                            <button type="submit" class="sywat-btn <?php echo $can_fit_member ? 'sywat-btn-success' : 'sywat-btn-warning'; ?> sywat-btn-sm">
                                                                <?php echo $can_fit_member ? 'Promote' : 'Override & Promote'; ?>
                                                            </button>
                                                        </form>

                                                        <!-- Edit Archer Link -->
                                                        <?php if ( $context === 'checkin' ) : ?>
                                                            <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form">
                                                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                                <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $active_line_filter ); ?>">
                                                                <input type="hidden" name="open_edit_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                                <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm">Edit</button>
                                                            </form>
                                                        <?php else : ?>
                                                            <a href="<?php echo esc_url( $edit_url ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-sm">Edit</a>
                                                        <?php endif; ?>

                                                        <!-- Remove Waitlist Entry Form -->
                                                        <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form" data-sywat-confirm="<?php echo esc_attr( sprintf( 'Remove %s from the waitlist?', $archer_name ) ); ?>">
                                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                            <?php sywat_waitlist_render_context_inputs( $selected_tournament, $active_line_filter, $context, $extra_context ); ?>
                                                            <input type="hidden" name="sywat_action" value="delete_shooter">
                                                            <input type="hidden" name="registration_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-sm">Remove</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pinned Modal Footer -->
                <div class="sywat-modal-footer">
                    <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-waitlist-modal" data-close-url="<?php echo esc_url( $close_target_url ); ?>">Close Waitlist</button>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * Backwards-compatible wrapper for legacy inline panel callers.
 *
 * @param string $selected_tournament Active tournament name.
 * @param array  $available_lines     Configured shooting lines for the tournament.
 * @param array  $registrations       Master or tournament registration records.
 * @param string $filter_line_idx     Current line filter ('all' or numeric line index).
 * @param string $post_action_url     Form submission target URL.
 * @param string $context             Host UI context.
 * @param array  $extra_context       Optional extra context values.
 * @return void
 */
if ( ! function_exists( 'sywat_render_waitlist_panel' ) ) {
    function sywat_render_waitlist_panel( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $post_action_url, $context = 'roster', $extra_context = array() ) {
        sywat_render_waitlist_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $post_action_url, $context, $extra_context );
    }
}
