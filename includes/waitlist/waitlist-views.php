<?php
/**
 * Sub-Module: Waitlist Reusable Queue View Renderer
 * Path: includes/waitlist/waitlist-views.php
 * Description: Renders the FIFO-ordered, family-grouped Waitlist Queue panel for both the Roster Management modal and the Check-In Desk portal, including split-family badges, individual/group promotion controls, and open-line move selectors.
 * Compliance: Pure view layer — zero DB writes, zero inline scripts/styles, zero inline event attributes, accessible SVG + text controls, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render standard hidden context fields so POST submissions preserve active tournament,
 * line filter, modal state, and search query across Roster and Check-In screens.
 *
 * @param string $selected_tournament Active tournament name.
 * @param string $filter_line_idx     Current line filter ('all' or numeric index).
 * @param string $context             Active UI context ('roster' or 'checkin').
 * @param array  $extra_context       Optional extra context values ('return_to', 'search_query').
 * @return void
 */
if ( ! function_exists( 'sywat_waitlist_render_context_inputs' ) ) {
    function sywat_waitlist_render_context_inputs( $selected_tournament, $filter_line_idx, $context = 'roster', $extra_context = array() ) {
        ?>
        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
        <?php if ( $context === 'roster' ) : ?>
            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
            <input type="hidden" name="modal_action" value="manage">
            <input type="hidden" name="filter_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
            <?php if ( ! empty( $extra_context['return_to'] ) ) : ?>
                <input type="hidden" name="return_to" value="<?php echo esc_attr( $extra_context['return_to'] ); ?>">
            <?php endif; ?>
        <?php else : ?>
            <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
            <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( isset( $extra_context['search_query'] ) ? $extra_context['search_query'] : '' ); ?>">
        <?php endif;
    }
}

/**
 * Render accessible inline clock SVG icon for waitlist badges.
 *
 * @return string SVG markup.
 */
if ( ! function_exists( 'sywat_waitlist_clock_svg' ) ) {
    function sywat_waitlist_clock_svg() {
        return '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
    }
}

/**
 * Build destination line <option> elements showing live open capacity per line.
 *
 * @param array  $line_capacities Capacity map from sywat_waitlist_get_line_capacities().
 * @param string $exclude_line    Current source line index to exclude from options.
 * @param int    $req_20yd        Required 20yd spots (1 for single 20yd archer, or group total).
 * @param int    $req_cub         Required Cub spots (1 for single Cub archer, or group total).
 * @param bool   $is_single_dist  True if rendering for a single archer's specific distance.
 * @param string $single_dist     '20yd' or 'Cub' when $is_single_dist is true.
 * @return array                  Associative array with 'options_html' (string) and 'has_open_line' (bool).
 */
if ( ! function_exists( 'sywat_waitlist_build_open_line_options' ) ) {
    function sywat_waitlist_build_open_line_options( $line_capacities, $exclude_line, $req_20yd, $req_cub, $is_single_dist = false, $single_dist = '20yd' ) {
        $options_html  = '';
        $has_open_line = false;

        if ( ! is_array( $line_capacities ) ) {
            return array(
                'options_html'  => '',
                'has_open_line' => false,
            );
        }

        foreach ( $line_capacities as $l_idx => $cap ) {
            if ( (string) $l_idx === (string) $exclude_line ) {
                continue;
            }

            $l_name    = isset( $cap['line_name'] ) ? $cap['line_name'] : ( 'Line ' . ( (int) $l_idx + 1 ) );
            $is_closed = ! empty( $cap['is_closed'] );
            $open_20   = isset( $cap['open_20yd'] ) ? (int) $cap['open_20yd'] : 0;
            $open_cub  = isset( $cap['open_cub'] ) ? (int) $cap['open_cub'] : 0;

            if ( $is_closed ) {
                $label    = sprintf( '%s (Closed)', $l_name );
                $can_fit  = false;
            } elseif ( $is_single_dist ) {
                $avail   = ( $single_dist === 'Cub' ) ? $open_cub : $open_20;
                $can_fit = ( $avail >= 1 );
                $label   = $can_fit
                    ? sprintf( '%s (%d %s open)', $l_name, $avail, $single_dist )
                    : sprintf( '%s (Full - 0 %s open)', $l_name, $single_dist );
            } else {
                $can_fit = ( $open_20 >= $req_20yd && $open_cub >= $req_cub );
                $label   = sprintf( '%s (%d 20yd / %d Cub open)', $l_name, $open_20, $open_cub );
            }

            if ( $can_fit ) {
                $has_open_line = true;
            }

            $options_html .= '<option value="' . esc_attr( (string) $l_idx ) . '"' . ( $can_fit ? '' : ' disabled' ) . '>' . esc_html( $label ) . '</option>';
        }

        return array(
            'options_html'  => $options_html,
            'has_open_line' => $has_open_line,
        );
    }
}

/**
 * Render Reusable FIFO Family-Grouped Waitlist Queue Panel.
 *
 * @param string $selected_tournament Active tournament name.
 * @param array  $available_lines     Configured shooting lines for the tournament.
 * @param array  $registrations       Tournament registration records.
 * @param string $filter_line_idx     Current line filter ('all' or numeric line index).
 * @param string $post_action_url     Form submission target URL.
 * @param string $context             Active UI context ('roster' or 'checkin').
 * @param array  $extra_context       Optional context values ('return_to', 'search_query', 'edit_base_url').
 * @return void
 */
if ( ! function_exists( 'sywat_render_waitlist_panel' ) ) {
    function sywat_render_waitlist_panel( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $post_action_url, $context = 'roster', $extra_context = array() ) {
        if ( ! function_exists( 'sywat_waitlist_get_grouped_queue' ) ) {
            return;
        }

        $t_slug       = sanitize_title( $selected_tournament );
        $closed_lines = get_option( 'sywat_closed_lines_' . $t_slug, array() );
        $queue_data   = sywat_waitlist_get_grouped_queue( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $closed_lines );

        $groups           = isset( $queue_data['groups'] ) ? $queue_data['groups'] : array();
        $total_waitlisted = isset( $queue_data['total_waitlisted'] ) ? (int) $queue_data['total_waitlisted'] : 0;
        $line_capacities  = isset( $queue_data['line_capacities'] ) ? $queue_data['line_capacities'] : array();
        $edit_base_url    = ! empty( $extra_context['edit_base_url'] ) ? $extra_context['edit_base_url'] : $post_action_url;
        ?>
        <section class="sywat-waitlist-section" aria-label="Tournament Waitlist Queue">
            <div class="sywat-table-header-bar sywat-modal-section-header">
                <div>
                    <h2>
                        <?php echo sywat_waitlist_clock_svg(); ?>
                        Waitlist Queue (FIFO Order, Grouped by Family)
                        <span class="sywat-waitlist-pill"><?php echo intval( $total_waitlisted ); ?> Waitlisted</span>
                    </h2>
                    <div class="sywat-subtitle">Ordered chronologically by arrival time and clustered by household. Promote into open spots or move to an open shooting line.</div>
                </div>
            </div>

            <?php if ( empty( $groups ) ) : ?>
                <p class="sywat-label-subtext">No archers currently on the waitlist for this selection.</p>
            <?php else : ?>
                <div class="sywat-table-responsive">
                    <table class="sywat-table sywat-waitlist-table">
                        <thead>
                            <tr>
                                <th class="sywat-col-center">Queue #</th>
                                <th>Archer / Family Group</th>
                                <th>Waitlisted Line &amp; Open Spots</th>
                                <th class="sywat-col-center">Distance / Division</th>
                                <th class="sywat-col-center">Waitlist Actions</th>
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

                                    $group_names_list = array();
                                    foreach ( $members as $gm ) {
                                        if ( ! empty( $gm['name'] ) ) {
                                            $group_names_list[] = strtolower( trim( (string) $gm['name'] ) );
                                        }
                                    }
                                    $group_search_attr = trim( strtolower( $grp['group_label'] ) . ' ' . implode( ' ', $group_names_list ) );

                                    $group_move_opts = $is_multi_group
                                        ? sywat_waitlist_build_open_line_options( $line_capacities, $src_line_idx, (int) $grp['needed_20yd'], (int) $grp['needed_cub'], false )
                                        : array( 'options_html' => '', 'has_open_line' => false );
                                ?>

                                <?php if ( $is_multi_group ) : ?>
                                    <tr class="sywat-waitlist-group-header-row" data-waitlist-search="<?php echo esc_attr( $group_search_attr ); ?>">
                                        <td class="sywat-col-center">
                                            <span class="sywat-waitlist-pill">#<?php echo intval( $q_pos ); ?></span>
                                        </td>
                                        <td>
                                            <span class="sywat-chip-family">Family Group: <?php echo esc_html( $grp['group_label'] ); ?> (<?php echo intval( $member_count ); ?> Waitlisted)</span>
                                            <?php if ( $active_fam_cnt > 0 ) : ?>
                                                <div class="sywat-label-subtext">
                                                    <strong>Split Family:</strong> <?php echo intval( $active_fam_cnt ); ?> family member(s) already active on <?php echo esc_html( $src_line_name ); ?> ($75.00 Family Cap applies at 3+ active archers).
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
                                            <span class="sywat-badge sywat-badge-prereg">Group (<?php echo intval( $member_count ); ?>)</span>
                                        </td>
                                        <td class="sywat-col-center">
                                            <div class="sywat-modal-roster-actions">
                                                <!-- Group Promote Form -->
                                                <?php
                                                    $group_promote_confirm = $can_fit_all
                                                        ? sprintf( 'Promote all %d waitlisted members of %s to Pre-Reg on %s?', $member_count, $grp['group_label'], $src_line_name )
                                                        : sprintf( 'WARNING: %s does not have enough open spots for all %d members of %s. Override line capacity and promote the entire group anyway?', $src_line_name, $member_count, $grp['group_label'] );
                                                ?>
                                                <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form" data-sywat-confirm="<?php echo esc_attr( $group_promote_confirm ); ?>">
                                                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                    <?php sywat_waitlist_render_context_inputs( $selected_tournament, $filter_line_idx, $context, $extra_context ); ?>
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

                                                <!-- Group Move to Open Line Form -->
                                                <?php if ( ! empty( $group_move_opts['options_html'] ) ) : ?>
                                                    <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form sywat-waitlist-move-form">
                                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                        <?php sywat_waitlist_render_context_inputs( $selected_tournament, $filter_line_idx, $context, $extra_context ); ?>
                                                        <input type="hidden" name="sywat_action" value="move_waitlist_line">
                                                        <input type="hidden" name="move_scope" value="group">
                                                        <input type="hidden" name="family_id" value="<?php echo esc_attr( $grp['family_id'] ); ?>">
                                                        <input type="hidden" name="source_line_idx" value="<?php echo esc_attr( $src_line_idx ); ?>">
                                                        <div class="sywat-search-input-group">
                                                            <select name="target_line_index" aria-label="Select destination open line for <?php echo esc_attr( $grp['group_label'] ); ?>" required>
                                                                <option value="" disabled selected><?php echo $group_move_opts['has_open_line'] ? 'Move Group to Line...' : 'No Line Fits Group'; ?></option>
                                                                <?php echo $group_move_opts['options_html']; ?>
                                                            </select>
                                                            <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-sm" <?php echo $group_move_opts['has_open_line'] ? '' : 'disabled'; ?>>Move Group</button>
                                                        </div>
                                                    </form>
                                                <?php endif; ?>
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
                                        $m_div          = ! empty( $m['division'] ) ? $m['division'] : '—';
                                        $m_created      = ! empty( $m['created_at'] ) ? $m['created_at'] : '';
                                        $can_fit_member = ! empty( $m['can_fit_member'] );

                                        $avail_for_dist = ( $m_dist === 'Cub' ) ? (int) $grp['open_cub'] : (int) $grp['open_20yd'];

                                        $member_move_opts = sywat_waitlist_build_open_line_options( $line_capacities, $src_line_idx, ( $m_dist === '20yd' ? 1 : 0 ), ( $m_dist === 'Cub' ? 1 : 0 ), true, $m_dist );

                                        $single_promote_confirm = $can_fit_member
                                            ? sprintf( 'Promote %s (%s) from Waitlist to Pre-Reg on %s?', $archer_name, $m_dist, $src_line_name )
                                            : sprintf( 'WARNING: %s currently has 0 open %s spots. Override line capacity and promote %s anyway?', $src_line_name, $m_dist, $archer_name );

                                        $row_search_attr = trim( strtolower( $archer_name ) . ' ' . $group_search_attr );
                                    ?>
                                    <tr class="sywat-waitlist-row <?php echo $is_multi_group ? 'sywat-waitlist-member-subrow' : ''; ?>" data-name="<?php echo esc_attr( strtolower( $archer_name ) ); ?>" data-waitlist-search="<?php echo esc_attr( $row_search_attr ); ?>">
                                        <td class="sywat-col-center">
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
                                            <div><?php echo esc_html( $src_line_name ); ?></div>
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
                                            <div><strong><?php echo esc_html( $m_dist ); ?></strong></div>
                                            <div class="sywat-label-subtext"><?php echo esc_html( $m_div ); ?></div>
                                        </td>
                                        <td class="sywat-col-center">
                                            <div class="sywat-modal-roster-actions">
                                                <!-- Single Archer Promote Form -->
                                                <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form" data-sywat-confirm="<?php echo esc_attr( $single_promote_confirm ); ?>">
                                                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                    <?php sywat_waitlist_render_context_inputs( $selected_tournament, $filter_line_idx, $context, $extra_context ); ?>
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

                                                <!-- Single Archer Move to Open Line Form -->
                                                <?php if ( ! empty( $member_move_opts['options_html'] ) ) : ?>
                                                    <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form sywat-waitlist-move-form">
                                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                        <?php sywat_waitlist_render_context_inputs( $selected_tournament, $filter_line_idx, $context, $extra_context ); ?>
                                                        <input type="hidden" name="sywat_action" value="move_waitlist_line">
                                                        <input type="hidden" name="move_scope" value="single">
                                                        <input type="hidden" name="registration_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                        <input type="hidden" name="source_line_idx" value="<?php echo esc_attr( $src_line_idx ); ?>">
                                                        <div class="sywat-search-input-group">
                                                            <select name="target_line_index" aria-label="Move <?php echo esc_attr( $archer_name ); ?> to open line" required>
                                                                <option value="" disabled selected><?php echo $member_move_opts['has_open_line'] ? 'Move to Line...' : 'No Open Line'; ?></option>
                                                                <?php echo $member_move_opts['options_html']; ?>
                                                            </select>
                                                            <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-sm" <?php echo $member_move_opts['has_open_line'] ? '' : 'disabled'; ?>>Move</button>
                                                        </div>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- Context-Aware Edit & Remove Controls -->
                                                <?php if ( $context === 'roster' ) : ?>
                                                    <?php
                                                        $edit_link_args = array(
                                                            'tab'             => 'manage',
                                                            'view_tournament' => $selected_tournament,
                                                            'modal_action'    => 'manage',
                                                            'edit_archer'     => $reg_id,
                                                            'filter_line_idx' => $filter_line_idx,
                                                        );
                                                        if ( ! empty( $extra_context['return_to'] ) ) {
                                                            $edit_link_args['return_to'] = $extra_context['return_to'];
                                                        }
                                                        $edit_url = add_query_arg( $edit_link_args, $edit_base_url );
                                                    ?>
                                                    <a href="<?php echo esc_url( $edit_url ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-sm">Edit</a>

                                                    <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form" data-sywat-confirm="<?php echo esc_attr( sprintf( 'Remove %s from the waitlist?', $archer_name ) ); ?>">
                                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                        <?php sywat_waitlist_render_context_inputs( $selected_tournament, $filter_line_idx, $context, $extra_context ); ?>
                                                        <input type="hidden" name="sywat_action" value="delete_shooter">
                                                        <input type="hidden" name="registration_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-sm">Remove</button>
                                                    </form>
                                                <?php else : ?>
                                                    <form method="post" action="<?php echo esc_url( $post_action_url ); ?>" class="sywat-table-action-form">
                                                        <?php sywat_waitlist_render_context_inputs( $selected_tournament, $filter_line_idx, $context, $extra_context ); ?>
                                                        <input type="hidden" name="open_edit_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                        <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-sm">Edit</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }
}
