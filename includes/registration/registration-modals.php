<?php
/**
 * Sub-Module: Registration Engine Component & Modal Views
 * Path: includes/registration/registration-modals.php
 * Description: Pure view renderer for the compact horizontal Line Capacity Tracker card strip at the top of [sywat_register], the Phone & Group Registration Intake form featuring TFAA division autocomplete anchoring on both Caller / Primary Archer Name (#sywat_group_name) and Archer Full Name rows, a per-archer multi-line checkbox matrix (zero pre-checked defaults, single-entry multi-line registration, live per-line capacity headers, 3-tier Category / Rate selection, dynamic Round Format selection), footer Clear Form & Save actions, and the top-level 8-column Bulk Spreadsheet Import Modal (#sywat_import_modal).
 * Compliance: Pure view layer — zero database/option queries, zero stats aggregation loops, zero output at include time, zero inline style attributes, zero embedded script blocks, zero inline JS event handlers (delegated data-sywat-action attributes only), canonical sywat_resolve_tournament_line_label() delegation, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.4.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper: Delegate shooting line label resolution to the single source of truth in tournament-helpers.php.
 *
 * @param array|string $line Line configuration array or label string.
 * @param int|string   $idx  Zero-based line array index key.
 * @return string            Normalized, non-empty line display label.
 */
if ( ! function_exists( 'sywat_reg_resolve_line_label' ) ) {
    function sywat_reg_resolve_line_label( $line, $idx ) {
        if ( function_exists( 'sywat_resolve_tournament_line_label' ) ) {
            return sywat_resolve_tournament_line_label( $line, $idx );
        }
        if ( is_array( $line ) && ! empty( $line['name'] ) ) {
            return trim( (string) $line['name'] );
        }
        return ( is_string( $line ) && trim( $line ) !== '' ) ? trim( $line ) : 'Line ' . ( (int) $idx + 1 );
    }
}

/**
 * Main Dispatcher: Registration Line Capacity Tracker & Multi-Line Intake Component Container
 *
 * @param string $selected_tournament Active tournament title.
 * @param array  $available_lines     Configured shooting lines keyed by line index.
 * @param array  $registrations       Scoped registration records for the tournament.
 * @param array  $line_stats          Pre-computed per-line capacity and waitlist metrics from registration-views.php.
 */
if ( ! function_exists( 'sywat_render_registration_modal' ) ) {
    function sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations, $line_stats = array() ) {
        $skipped_duplicates = ! empty( $GLOBALS['sywat_skipped_duplicates'] ) ? $GLOBALS['sywat_skipped_duplicates'] : array();
        $skipped_full       = ! empty( $GLOBALS['sywat_skipped_full'] ) ? $GLOBALS['sywat_skipped_full'] : array();
        $modal_notice       = ! empty( $GLOBALS['sywat_modal_notice'] ) ? $GLOBALS['sywat_modal_notice'] : '';
        ?>
        <div class="no-print sywat-intake-wrapper">
            <?php if ( ! empty( $modal_notice ) ) : ?>
                <?php if ( stripos( $modal_notice, 'OVERFLOW' ) !== false ) : ?>
                    <div id="sywat_capacity_overflow_modal" class="sywat-modal-backdrop sywat-modal-active sywat-modal-overflow-backdrop">
                        <div class="sywat-modal-content sywat-modal-overflow-content">
                            <?php echo $modal_notice; ?>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="sywat-modal-notice"><?php echo $modal_notice; ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( ! empty( $skipped_duplicates ) ) : ?>
                <div class="sywat-alert sywat-alert-warning">
                    <strong>⚠️ DUPLICATE REGISTRATION BLOCKED:</strong> The following archer(s) were NOT registered because they are already signed up on the selected line(s):
                    <ul>
                        <?php foreach ( $skipped_duplicates as $dup ) : ?>
                            <li><?php echo esc_html( $dup ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $skipped_full ) ) : ?>
                <div class="sywat-alert sywat-alert-warning">
                    <strong>⚠️ LINE CAPACITY BLOCKED:</strong> The following archer(s) were NOT registered because the target line is at maximum capacity:
                    <ul>
                        <?php foreach ( $skipped_full as $full ) : ?>
                            <li><?php echo esc_html( $full ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Component 1: Compact Horizontal Line Capacity Tracker Card Grid -->
            <?php sywat_render_line_slot_usage_table( $selected_tournament, $available_lines, $registrations, $line_stats ); ?>

            <!-- Component 2: Phone & Group Multi-Line Registration Matrix Form -->
            <?php sywat_render_phone_intake_form( $selected_tournament, $available_lines, $line_stats ); ?>
        </div>
        <?php
    }
}

/**
 * Component 1: Line Capacity Tracker Card Grid with Manage Roster, Waitlist & Clear Line Controls
 *
 * @param string $selected_tournament Active tournament title.
 * @param array  $available_lines     Configured shooting lines keyed by line index.
 * @param array  $registrations       Scoped registration records for the tournament.
 * @param array  $line_stats          Pre-computed per-line capacity and waitlist metrics.
 */
if ( ! function_exists( 'sywat_render_line_slot_usage_table' ) ) {
    function sywat_render_line_slot_usage_table( $selected_tournament, $available_lines, $registrations, $line_stats = array() ) {
        $register_base_url = function_exists( 'sywat_page_url' )
            ? add_query_arg( array( 'selected_tournament' => $selected_tournament ), sywat_page_url( 'register' ) )
            : '';
        ?>
        <div class="sywat-intake-card">
            <div class="sywat-table-header-bar">
                <h2>📊 Line Capacity Tracker</h2>
            </div>
            <div class="sywat-tracker-grid">
                <?php foreach ( $available_lines as $idx => $line ) : ?>
                    <?php
                        $st                    = isset( $line_stats[ $idx ] ) && is_array( $line_stats[ $idx ] ) ? $line_stats[ $idx ] : array();
                        $line_title            = isset( $st['label'] ) ? $st['label'] : sywat_reg_resolve_line_label( $line, $idx );
                        $c_20                  = isset( $st['c_20'] ) ? intval( $st['c_20'] ) : ( function_exists( 'sywat_count_registered' ) ? sywat_count_registered( $selected_tournament, $idx, '20yd', $registrations ) : 0 );
                        $m_20                  = isset( $st['m_20'] ) ? intval( $st['m_20'] ) : ( function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $line, '20yd' ) : 20 );
                        $c_cub                 = isset( $st['c_cub'] ) ? intval( $st['c_cub'] ) : ( function_exists( 'sywat_count_registered' ) ? sywat_count_registered( $selected_tournament, $idx, 'Cub', $registrations ) : 0 );
                        $m_cub                 = isset( $st['m_cub'] ) ? intval( $st['m_cub'] ) : ( function_exists( 'sywat_get_line_max_cap' ) ? sywat_get_line_max_cap( $line, 'Cub' ) : 5 );
                        $is_closed             = ! empty( $st['is_closed'] );
                        $rem_20                = isset( $st['rem_20'] ) ? intval( $st['rem_20'] ) : ( $is_closed ? 0 : max( 0, $m_20 - $c_20 ) );
                        $rem_cub               = isset( $st['rem_cub'] ) ? intval( $st['rem_cub'] ) : ( $is_closed ? 0 : max( 0, $m_cub - $c_cub ) );
                        $waitlisted_20         = isset( $st['waitlisted_20'] ) ? intval( $st['waitlisted_20'] ) : 0;
                        $waitlisted_cub        = isset( $st['waitlisted_cub'] ) ? intval( $st['waitlisted_cub'] ) : 0;
                        $total_line_shooters   = isset( $st['total_line_shooters'] ) ? intval( $st['total_line_shooters'] ) : ( $c_20 + $c_cub );
                        $total_line_waitlisted = isset( $st['total_line_waitlisted'] ) ? intval( $st['total_line_waitlisted'] ) : ( $waitlisted_20 + $waitlisted_cub );

                        $has_open_20  = ( $m_20 > 0 && $rem_20 > 0 );
                        $has_open_cub = ( $m_cub > 0 && $rem_cub > 0 );
                        $is_line_full = ( ! $is_closed && ! $has_open_20 && ! $has_open_cub && ( $m_20 > 0 || $m_cub > 0 ) );

                        $manage_url = add_query_arg(
                            array(
                                'tab'             => 'manage',
                                'view_tournament' => $selected_tournament,
                                'modal_action'    => 'manage',
                                'filter_line_idx' => $idx,
                                'return_to'       => 'register',
                            ),
                            sywat_page_url( 'manage_tournaments' )
                        );

                        $waitlist_modal_url = function_exists( 'sywat_waitlist_get_modal_url' )
                            ? sywat_waitlist_get_modal_url( $selected_tournament, $idx, $register_base_url )
                            : '';

                        $confirm_msg = sprintf( 'Are you sure you want to clear ALL archers from %s?', $line_title );
                    ?>
                    <div class="sywat-tracker-card">
                        <div>
                            <div class="sywat-tracker-card-header">
                                <span class="sywat-tracker-card-title"><?php echo esc_html( $line_title ); ?></span>
                                <div class="sywat-tracker-header-badges">
                                    <?php if ( $is_closed ) : ?>
                                        <span class="sywat-badge sywat-badge-muted">Closed</span>
                                    <?php elseif ( $is_line_full ) : ?>
                                        <span class="sywat-badge sywat-badge-full">Full</span>
                                    <?php else : ?>
                                        <span class="sywat-badge sywat-badge-open">Open</span>
                                    <?php endif; ?>
                                    <?php if ( $total_line_waitlisted > 0 ) : ?>
                                        <?php if ( function_exists( 'sywat_waitlist_render_badge_link' ) ) : ?>
                                            <?php sywat_waitlist_render_badge_link( $selected_tournament, $total_line_waitlisted, $idx, $register_base_url ); ?>
                                        <?php else : ?>
                                            <span class="sywat-waitlist-pill">🕒 <?php echo intval( $total_line_waitlisted ); ?> Waitlisted</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="sywat-tracker-card-stats">
                                <span>
                                    <strong>20yd:</strong>
                                    <?php if ( $m_20 <= 0 ) : ?>
                                        N/A
                                    <?php elseif ( ! $has_open_20 ) : ?>
                                        <span class="sywat-text-closed">FULL</span> (<?php echo $c_20; ?>/<?php echo $m_20; ?>)
                                    <?php else : ?>
                                        <?php echo $rem_20; ?> Open (<?php echo $c_20; ?>/<?php echo $m_20; ?>)
                                    <?php endif; ?>
                                </span>
                                <span>
                                    <strong>Cub:</strong>
                                    <?php if ( $m_cub <= 0 ) : ?>
                                        N/A
                                    <?php elseif ( ! $has_open_cub ) : ?>
                                        <span class="sywat-text-closed">FULL</span> (<?php echo $c_cub; ?>/<?php echo $m_cub; ?>)
                                    <?php else : ?>
                                        <?php echo $rem_cub; ?> Open (<?php echo $c_cub; ?>/<?php echo $m_cub; ?>)
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="sywat-tracker-card-actions">
                            <a href="<?php echo esc_url( $manage_url ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-manage">
                                Manage Roster
                            </a>
                            <?php if ( $total_line_waitlisted > 0 && ! empty( $waitlist_modal_url ) ) : ?>
                                <a href="<?php echo esc_url( $waitlist_modal_url ); ?>" class="sywat-btn sywat-btn-warning sywat-waitlist-trigger-btn" title="Open Waitlist Management Window">
                                    <?php echo function_exists( 'sywat_waitlist_clock_svg' ) ? sywat_waitlist_clock_svg() : ''; ?>
                                    <span>Waitlist (<?php echo intval( $total_line_waitlisted ); ?>)</span>
                                </a>
                            <?php endif; ?>
                            <?php if ( $total_line_shooters > 0 || $total_line_waitlisted > 0 ) : ?>
                                <form method="post" data-sywat-action="confirm-purge-line" data-confirm-msg="<?php echo esc_attr( $confirm_msg ); ?>">
                                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                    <input type="hidden" name="sywat_action" value="purge_line">
                                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                    <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                    <input type="hidden" name="redirect_action" value="prereg">
                                    <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Clear Line</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}

/**
 * Component 2: Phone & Multi-Archer Group Registration Matrix Form
 * Zero pre-checked line defaults; supports single-entry multi-line registration per shooter.
 *
 * @param string $selected_tournament Active tournament title.
 * @param array  $available_lines     Configured shooting lines keyed by line index.
 * @param array  $line_stats          Pre-computed per-line capacity and waitlist metrics.
 */
if ( ! function_exists( 'sywat_render_phone_intake_form' ) ) {
    function sywat_render_phone_intake_form( $selected_tournament, $available_lines, $line_stats = array() ) {
        // Resolve active Pricing & Round Profiles offered by this Tournament
        $t_profiles       = function_exists( 'sywat_get_tournament_pricing_profiles' )
            ? sywat_get_tournament_pricing_profiles( $selected_tournament )
            : array();
        $is_multi_round   = count( $t_profiles ) > 1;
        $default_prof     = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';
        $init_adult_rate  = is_array( $default_prof ) && isset( $default_prof['adult_rate'] ) ? floatval( $default_prof['adult_rate'] ) : 30.00;
        $init_youth_rate  = is_array( $default_prof ) && isset( $default_prof['youth_rate'] ) ? floatval( $default_prof['youth_rate'] ) : 25.00;

        $is_overflow_notice = ! empty( $GLOBALS['sywat_modal_notice'] ) && stripos( $GLOBALS['sywat_modal_notice'], 'OVERFLOW' ) !== false;
        $retain_form_data   = $is_overflow_notice || ! empty( $GLOBALS['sywat_skipped_duplicates'] ) || ! empty( $GLOBALS['sywat_skipped_full'] );

        $group_name_val  = $retain_form_data && isset( $_POST['group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '';
        $group_phone_val = $retain_form_data && isset( $_POST['group_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['group_phone'] ) ) : '';
        $amount_paid_val = $retain_form_data && isset( $_POST['amount_paid_today'] ) ? sanitize_text_field( wp_unslash( $_POST['amount_paid_today'] ) ) : '0.00';

        $archers_data = array();
        if ( $retain_form_data && isset( $_POST['archers'] ) && is_array( $_POST['archers'] ) ) {
            $archers_data = wp_unslash( $_POST['archers'] );
        }

        if ( empty( $archers_data ) ) {
            $archers_data = array(
                array(
                    'name'            => '',
                    'rate_tier'       => '',
                    'distance'        => '20yd',
                    'division'        => '',
                    'pricing_profile' => $default_prof_key,
                    'line_indices'    => array(),
                ),
            );
        }

        $format_badges = array();
        foreach ( $t_profiles as $tp ) {
            $lbl = $tp['label'];
            if ( ! empty( $tp['round_subtitle'] ) ) {
                $lbl .= ' (' . $tp['round_subtitle'] . ')';
            }
            $format_badges[] = $lbl;
        }

        $line_columns_payload = array();
        foreach ( $available_lines as $idx => $line ) {
            $st                     = isset( $line_stats[ $idx ] ) && is_array( $line_stats[ $idx ] ) ? $line_stats[ $idx ] : array();
            $line_columns_payload[] = array(
                'idx'   => (string) $idx,
                'label' => isset( $st['label'] ) ? $st['label'] : sywat_reg_resolve_line_label( $line, $idx ),
            );
        }
        ?>
        <div class="sywat-intake-card">
            <div class="sywat-table-header-bar">
                <h2>📞 Phone &amp; Group Registration Intake</h2>
                <?php if ( ! empty( $format_badges ) ) : ?>
                    <span class="sywat-badge sywat-badge-prereg">Format: <?php echo esc_html( implode( ' / ', $format_badges ) ); ?></span>
                <?php endif; ?>
            </div>

            <form method="post" id="sywat_phone_intake_form" data-sywat-action="phone-intake-form">
                <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                <input type="hidden" name="sywat_action" value="register_shooter">
                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="modal_action" value="prereg">

                <div class="sywat-form-grid-3 sywat-intake-group-wrapper">
                    <div class="sywat-field-group sywat-field-relative">
                        <label for="sywat_group_name">Caller / Primary Archer Name</label>
                        <input type="text"
                               name="group_name"
                               id="sywat_group_name"
                               autocomplete="off"
                               value="<?php echo esc_attr( $group_name_val ); ?>"
                               placeholder="e.g. John Smith"
                               data-sywat-action="caller-name-input">
                    </div>

                    <div class="sywat-field-group">
                        <label for="sywat_group_phone">Contact Phone Number</label>
                        <input type="tel"
                               name="group_phone"
                               id="sywat_group_phone"
                               value="<?php echo esc_attr( $group_phone_val ); ?>"
                               placeholder="e.g. 817-555-1234"
                               data-sywat-action="group-phone-input">
                    </div>

                    <div class="sywat-field-group">
                        <label for="sywat_amount_paid_today">Amount Paid Today ($)</label>
                        <input type="number"
                               step="0.01"
                               min="0"
                               name="amount_paid_today"
                               id="sywat_amount_paid_today"
                               value="<?php echo esc_attr( $amount_paid_val ); ?>"
                               data-sywat-action="amount-paid-input">
                    </div>
                </div>

                <div class="sywat-table-header-bar sywat-mt-3">
                    <div>
                        <h3>Select Archer(s), Category / Rate &amp; Shooting Line(s) *</h3>
                        <div class="sywat-label-subtext">Enter each shooter once and check one or more shooting lines (no lines are pre-selected by default).</div>
                    </div>
                </div>

                <div class="sywat-table-responsive sywat-mb-3">
                    <table class="sywat-table sywat-table-mobile-cards" id="sywat_archer_intake_table">
                        <thead>
                            <tr>
                                <th>Archer Full Name *</th>
                                <?php if ( $is_multi_round ) : ?>
                                    <th>Round Format *</th>
                                <?php endif; ?>
                                <th>Category / Rate *</th>
                                <?php foreach ( $available_lines as $idx => $line ) : ?>
                                    <?php
                                        $st         = isset( $line_stats[ $idx ] ) && is_array( $line_stats[ $idx ] ) ? $line_stats[ $idx ] : array();
                                        $col_label  = isset( $st['label'] ) ? $st['label'] : sywat_reg_resolve_line_label( $line, $idx );
                                        $is_closed  = ! empty( $st['is_closed'] );
                                        $is_wl_only = ! empty( $st ) && intval( $st['rem_20'] ) <= 0 && intval( $st['rem_cub'] ) <= 0;
                                    ?>
                                    <th class="sywat-col-center">
                                        <?php echo esc_html( $col_label ); ?>
                                        <?php if ( $is_closed ) : ?>
                                            <div class="sywat-label-subtext">Closed</div>
                                        <?php elseif ( $is_wl_only ) : ?>
                                            <div class="sywat-label-subtext">Waitlist</div>
                                        <?php endif; ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="sywat-col-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="sywat_archer_rows_container"
                               data-multi-round="<?php echo $is_multi_round ? '1' : '0'; ?>"
                               data-default-profile="<?php echo esc_attr( $default_prof_key ); ?>"
                               data-lines="<?php echo esc_attr( wp_json_encode( $line_columns_payload ) ); ?>">
                            <?php foreach ( $archers_data as $i => $a ) : ?>
                                <?php
                                    $a_name  = isset( $a['name'] ) ? sanitize_text_field( $a['name'] ) : '';
                                    $a_dist  = isset( $a['distance'] ) ? sanitize_text_field( $a['distance'] ) : '20yd';
                                    $a_div   = isset( $a['division'] ) ? trim( sanitize_text_field( $a['division'] ) ) : '';
                                    $a_round = ! empty( $a['pricing_profile'] ) ? sanitize_title( $a['pricing_profile'] ) : $default_prof_key;
                                    if ( ! isset( $t_profiles[ $a_round ] ) ) {
                                        $a_round = $default_prof_key;
                                    }

                                    $a_tier = isset( $a['rate_tier'] ) ? strtolower( trim( sanitize_text_field( $a['rate_tier'] ) ) ) : '';
                                    if ( ! in_array( $a_tier, array( 'cub', 'youth', 'adult' ), true ) ) {
                                        if ( $a_dist === 'Cub' ) {
                                            $a_tier = 'cub';
                                        } elseif ( $a_div !== '' ) {
                                            $is_y_div = function_exists( 'sywat_shooting_styles_get_division_rate_tier' )
                                                ? ( sywat_shooting_styles_get_division_rate_tier( $a_div ) === 'Youth' )
                                                : ( stripos( $a_div, 'Youth' ) !== false || stripos( $a_div, 'Young Adult' ) !== false );
                                            $a_tier = $is_y_div ? 'youth' : 'adult';
                                        } else {
                                            $a_tier = '';
                                        }
                                    }

                                    $row_prof  = isset( $t_profiles[ $a_round ] ) ? $t_profiles[ $a_round ] : $default_prof;
                                    $row_youth = is_array( $row_prof ) && isset( $row_prof['youth_rate'] ) ? floatval( $row_prof['youth_rate'] ) : $init_youth_rate;
                                    $row_adult = is_array( $row_prof ) && isset( $row_prof['adult_rate'] ) ? floatval( $row_prof['adult_rate'] ) : $init_adult_rate;
                                    $row_cub   = ( is_array( $row_prof ) && isset( $row_prof['division_overrides']['cub-8-11'] ) && $row_prof['division_overrides']['cub-8-11'] !== '' )
                                        ? floatval( $row_prof['division_overrides']['cub-8-11'] )
                                        : $row_youth;

                                    $checked_lines = array();
                                    if ( $retain_form_data ) {
                                        if ( isset( $a['line_indices'] ) && is_array( $a['line_indices'] ) ) {
                                            $checked_lines = array_map( 'strval', array_map( 'sanitize_text_field', $a['line_indices'] ) );
                                        } elseif ( isset( $a['line_idx'] ) && $a['line_idx'] !== '' ) {
                                            $checked_lines = array( (string) sanitize_text_field( $a['line_idx'] ) );
                                        }
                                    }
                                ?>
                                <tr class="sywat-archer-input-row">
                                    <td data-label="Archer Full Name *">
                                        <input type="text"
                                               name="archers[<?php echo $i; ?>][name]"
                                               <?php if ( $i === 0 ) echo 'id="sywat_first_archer_name"'; ?>
                                               class="sywat-archer-name-input"
                                               placeholder="Archer Full Name *"
                                               autocomplete="off"
                                               required
                                               value="<?php echo esc_attr( $a_name ); ?>"
                                               data-sywat-action="archer-name-input">
                                        <?php if ( ! $is_multi_round ) : ?>
                                            <input type="hidden" name="archers[<?php echo $i; ?>][pricing_profile]" class="sywat-round-profile-input" value="<?php echo esc_attr( $default_prof_key ); ?>">
                                        <?php endif; ?>
                                    </td>

                                    <?php if ( $is_multi_round ) : ?>
                                        <td data-label="Round Format *">
                                            <select name="archers[<?php echo $i; ?>][pricing_profile]" class="sywat-round-profile-select" data-sywat-action="round-profile-change">
                                                <?php foreach ( $t_profiles as $p_slug => $p_rec ) : ?>
                                                    <?php
                                                        $opt_text = $p_rec['label'];
                                                        if ( ! empty( $p_rec['round_subtitle'] ) ) {
                                                            $opt_text .= ' (' . $p_rec['round_subtitle'] . ')';
                                                        }
                                                    ?>
                                                    <option value="<?php echo esc_attr( $p_slug ); ?>" <?php selected( $a_round, $p_slug ); ?>>
                                                        <?php echo esc_html( $opt_text ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    <?php endif; ?>

                                    <td data-label="Category / Rate *">
                                        <select name="archers[<?php echo $i; ?>][rate_tier]" class="sywat-rate-tier-select" required data-sywat-action="rate-tier-change">
                                            <option value="" disabled <?php selected( $a_tier, '' ); ?> hidden>Select Category *</option>
                                            <option
                                                value="cub"
                                                data-distance="Cub"
                                                data-rate-tier="Youth"
                                                data-fee="<?php echo esc_attr( number_format( $row_cub, 2, '.', '' ) ); ?>"
                                                <?php selected( $a_tier, 'cub' ); ?>
                                            >
                                                <?php echo esc_html( 'Cub [$' . number_format( $row_cub, 2 ) . ']' ); ?>
                                            </option>
                                            <option
                                                value="youth"
                                                data-distance="20yd"
                                                data-rate-tier="Youth"
                                                data-fee="<?php echo esc_attr( number_format( $row_youth, 2, '.', '' ) ); ?>"
                                                <?php selected( $a_tier, 'youth' ); ?>
                                            >
                                                <?php echo esc_html( 'Youth [$' . number_format( $row_youth, 2 ) . ']' ); ?>
                                            </option>
                                            <option
                                                value="adult"
                                                data-distance="20yd"
                                                data-rate-tier="Adult"
                                                data-fee="<?php echo esc_attr( number_format( $row_adult, 2, '.', '' ) ); ?>"
                                                <?php selected( $a_tier, 'adult' ); ?>
                                            >
                                                <?php echo esc_html( 'Adult [$' . number_format( $row_adult, 2 ) . ']' ); ?>
                                            </option>
                                        </select>
                                    </td>

                                    <?php foreach ( $available_lines as $idx => $line ) : ?>
                                        <?php
                                            $idx_str    = (string) $idx;
                                            $st         = isset( $line_stats[ $idx ] ) && is_array( $line_stats[ $idx ] ) ? $line_stats[ $idx ] : array();
                                            $col_label  = isset( $st['label'] ) ? $st['label'] : sywat_reg_resolve_line_label( $line, $idx );
                                            $is_checked = in_array( $idx_str, $checked_lines, true );
                                            $cb_aria    = 'Register archer for ' . $col_label;
                                        ?>
                                        <td class="sywat-col-center" data-label="<?php echo esc_attr( $col_label ); ?>">
                                            <input type="checkbox"
                                                   class="sywat-archer-line-cb"
                                                   name="archers[<?php echo $i; ?>][line_indices][]"
                                                   value="<?php echo esc_attr( $idx_str ); ?>"
                                                   aria-label="<?php echo esc_attr( $cb_aria ); ?>"
                                                   data-sywat-action="archer-line-toggle"
                                                   <?php checked( $is_checked ); ?>>
                                        </td>
                                    <?php endforeach; ?>

                                    <td class="sywat-col-center" data-label="Action">
                                        <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" data-sywat-action="remove-archer-row" title="Remove Archer" aria-label="Remove Archer"></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sywat-intake-footer-bar">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" data-sywat-action="add-archer-row">Add Another Archer</button>

                    <div class="sywat-intake-summary">
                        <div class="sywat-intake-total-text">
                            Group Total: <span id="sywat_group_total_disp" class="sywat-intake-total-val">$0.00</span>
                            <span id="sywat_family_cap_badge" class="sywat-family-badge sywat-hidden">🟢 Family Cap Applied</span>
                        </div>
                        <div class="sywat-intake-due-text">
                            Balance Due at Desk: <strong id="sywat_balance_due_disp" class="sywat-intake-due-val">$0.00</strong>
                        </div>
                    </div>

                    <div class="sywat-header-inline-controls">
                        <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="clear-intake-form">Clear Form</button>
                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-checkin">Save &amp; Register Shooters</button>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
}

/**
 * Dedicated Top-Level Modal: Bulk Spreadsheet Upload (.xlsx, .xls, .csv)
 * Rendered outside card containers inside .sywat-wrap so sywat-modal-manager.js
 * relocates it directly under <body> above theme/app headers.
 *
 * @param string $selected_tournament Active tournament title.
 * @param array  $available_lines     Configured shooting lines keyed by line index.
 * @param array  $tourn_data          Active tournament configuration array.
 * @param bool   $auto_open           Whether to auto-open modal on validation failure.
 */
if ( ! function_exists( 'sywat_render_import_modal' ) ) {
    function sywat_render_import_modal( $selected_tournament, $available_lines = array(), $tourn_data = array(), $auto_open = false ) {
        $backdrop_classes = 'sywat-modal-backdrop no-print';
        if ( $auto_open ) {
            $backdrop_classes .= ' active';
        }

        $configured_line_labels = array();
        if ( is_array( $available_lines ) ) {
            foreach ( $available_lines as $idx => $line ) {
                $configured_line_labels[] = sywat_reg_resolve_line_label( $line, $idx );
            }
        }

        $template_url = function_exists( 'sywat_page_url' )
            ? add_query_arg(
                array(
                    'sywat_download_import_template' => '1',
                    'selected_tournament'            => $selected_tournament,
                    '_wpnonce'                       => wp_create_nonce( 'sywat_download_import_template' ),
                ),
                sywat_page_url( 'register' )
            )
            : '';

        $modal_error = ! empty( $GLOBALS['sywat_import_modal_error'] ) ? $GLOBALS['sywat_import_modal_error'] : '';
        ?>
        <div
            id="sywat_import_modal"
            class="<?php echo esc_attr( $backdrop_classes ); ?>"
            data-auto-open="<?php echo $auto_open ? '1' : '0'; ?>"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sywat_import_modal_title"
        >
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header">
                    <h3 id="sywat_import_modal_title">Bulk Upload Shooters — <?php echo esc_html( $selected_tournament ); ?></h3>
                    <button type="button" class="sywat-modal-close" data-sywat-action="close-import-modal" aria-label="Close Bulk Upload Modal">&times;</button>
                </div>

                <form method="post" enctype="multipart/form-data" id="sywat_import_form">
                    <div class="sywat-modal-body">
                        <?php if ( ! empty( $modal_error ) ) : ?>
                            <div class="sywat-alert sywat-alert-danger"><?php echo wp_kses_post( $modal_error ); ?></div>
                        <?php endif; ?>

                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="import_shooters">
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="modal_action" value="prereg">

                        <div class="sywat-field-group">
                            <label for="import_file_input">Select Spreadsheet File (.xlsx, .xls, or .csv) *</label>
                            <input type="file" id="import_file_input" name="import_file" accept=".xlsx,.xls,.csv" required>
                        </div>

                        <div class="sywat-alert sywat-alert-info">
                            <p><strong>Standardized 8-Column Format (One Archer per Row):</strong></p>
                            <ul>
                                <li><strong>Mandatory Columns:</strong> <code>Contact Name</code>, <code>Phone</code>, <code>Archer Name</code>, <code>Line Day</code> (Friday / Saturday / Sunday), <code>Line Time</code> (HH:MM AM/PM), <code>Category / Rate</code> (Cub / Youth / Adult), and <code>Paid Status</code> (Paid / Unpaid).</li>
                                <li><strong>Optional Column:</strong> <code>Email</code>.</li>
                                <li><strong>Automatic Rules:</strong> <code>Cub</code> and <code>Youth</code> bill at the <strong>Youth</strong> rate; <code>Adult</code> bills at the <strong>Adult</strong> rate. Households sharing a <strong>Phone</strong> (or Contact Name) on the same shooting line are grouped automatically to apply the per-line <strong>Family Cap</strong>. <code>Paid</code> sets <strong>$0.00</strong> due at check-in; <code>Unpaid</code> carries the calculated individual or capped Family balance to Check-In.</li>
                            </ul>

                            <?php if ( ! empty( $configured_line_labels ) ) : ?>
                                <p><strong>Configured Lines for This Tournament:</strong> <?php echo esc_html( implode( ' • ', $configured_line_labels ) ); ?></p>
                            <?php endif; ?>

                            <?php if ( ! empty( $template_url ) ) : ?>
                                <p>
                                    <a href="<?php echo esc_url( $template_url ); ?>" class="sywat-btn sywat-btn-secondary">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="7 10 12 15 17 10"></polyline>
                                            <line x1="12" y1="15" x2="12" y2="3"></line>
                                        </svg>
                                        Download Excel Template (.xlsx)
                                    </a>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-import-modal">Cancel</button>
                        <button type="submit" id="sywat_upload_btn" class="sywat-btn sywat-btn-primary">Upload &amp; Import Shooters</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
