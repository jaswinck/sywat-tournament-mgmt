<?php
/**
 * View Template: Public Member Online Tournament Registration Portal
 * Path: includes/registration/views/view-member-registration.php
 * Description: Pure view template for [sywat_member_register]. Renders sign-in prompt for guests, selected tournament event details, live line capacity availability table, and household multi-archer / multi-line checkbox matrix form displaying archer names as "LastName, FirstName" (with zero pre-checked defaults, per-line status badges, automatic age division/distance detection, line-by-line confirmation breakdown, and Season Schedule cancel/return navigation).
 * Compliance: Pure view layer — zero inline style attributes, zero embedded script blocks, zero inline event handlers, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_logged_in         = ! empty( $view_data['is_logged_in'] );
$login_url            = isset( $view_data['login_url'] ) ? $view_data['login_url'] : '';
$schedule_url         = isset( $view_data['schedule_url'] ) ? $view_data['schedule_url'] : '';
$profile_url          = isset( $view_data['profile_url'] ) ? $view_data['profile_url'] : '';
$form_action_url      = isset( $view_data['form_action_url'] ) ? $view_data['form_action_url'] : '';
$open_tournaments     = isset( $view_data['open_tournaments'] ) && is_array( $view_data['open_tournaments'] ) ? $view_data['open_tournaments'] : array();
$selected_tournament  = isset( $view_data['selected_tournament'] ) ? (string) $view_data['selected_tournament'] : '';
$line_availability    = isset( $view_data['line_availability'] ) && is_array( $view_data['line_availability'] ) ? $view_data['line_availability'] : array();
$household            = isset( $view_data['household'] ) && is_array( $view_data['household'] ) ? $view_data['household'] : array();
$existing_member_regs = isset( $view_data['existing_member_regs'] ) && is_array( $view_data['existing_member_regs'] ) ? $view_data['existing_member_regs'] : array();
$division_options     = isset( $view_data['division_options'] ) && is_array( $view_data['division_options'] ) ? $view_data['division_options'] : array();
$submission_result    = isset( $view_data['submission_result'] ) && is_array( $view_data['submission_result'] ) ? $view_data['submission_result'] : null;
$has_lf_formatter     = function_exists( 'sywat_format_name_last_first' );

$icon_clipboard = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg>';

$active_event = ( $selected_tournament !== '' && isset( $open_tournaments[ $selected_tournament ] ) )
    ? $open_tournaments[ $selected_tournament ]
    : null;
?>

<div class="sywat-public-card">
    <div class="sywat-card-header-bar">
        <div>
            <h2><?php echo $icon_clipboard; ?> Online Tournament Registration</h2>
            <div class="sywat-subtitle">
                Register yourself and linked household family members for open TFAA / SYWAT tournaments.
            </div>
        </div>
        <div class="sywat-header-inline-controls">
            <?php if ( $schedule_url !== '' ) : ?>
                <a href="<?php echo esc_url( $schedule_url ); ?>" class="sywat-btn sywat-btn-secondary">
                    📅 Season Schedule
                </a>
            <?php endif; ?>
            <?php if ( $is_logged_in && $profile_url !== '' ) : ?>
                <a href="<?php echo esc_url( $profile_url ); ?>" class="sywat-btn sywat-btn-secondary">
                    👤 My Household
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( ! $is_logged_in ) : ?>
        <!-- STATE 1: GUEST VISITOR SIGN-IN PROMPT -->
        <div class="sywat-alert sywat-alert-info sywat-mt-3">
            <strong>TFAA Member Account Required:</strong> Please sign in with your TFAA member account to register yourself or linked household family members online.
        </div>
        <div class="sywat-header-inline-controls sywat-mt-3">
            <?php if ( $login_url !== '' ) : ?>
                <a href="<?php echo esc_url( $login_url ); ?>" class="sywat-btn sywat-btn-primary">
                    Sign In to Register
                </a>
            <?php endif; ?>
            <?php if ( $schedule_url !== '' ) : ?>
                <a href="<?php echo esc_url( $schedule_url ); ?>" class="sywat-btn sywat-btn-secondary">
                    📅 View Season Schedule
                </a>
            <?php endif; ?>
        </div>

    <?php else : ?>
        <!-- POST-SUBMISSION RESULT NOTICES -->
        <?php if ( is_array( $submission_result ) && ! empty( $submission_result['submitted'] ) ) : ?>
            <?php if ( ! empty( $submission_result['errors'] ) ) : ?>
                <div class="sywat-alert sywat-alert-danger sywat-mt-3">
                    <strong>Registration Could Not Be Completed:</strong>
                    <ul>
                        <?php foreach ( $submission_result['errors'] as $err_msg ) : ?>
                            <li><?php echo esc_html( $err_msg ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php
                $line_summaries = isset( $submission_result['line_summaries'] ) && is_array( $submission_result['line_summaries'] )
                    ? $submission_result['line_summaries']
                    : array();
            ?>

            <?php if ( ! empty( $line_summaries ) ) : ?>
                <?php if ( ! empty( $submission_result['registered'] ) ) : ?>
                    <div class="sywat-alert sywat-alert-success sywat-mt-3">
                        <strong>Registration Confirmed for <?php echo esc_html( $submission_result['tournament'] ); ?>!</strong>
                        <?php foreach ( $line_summaries as $l_sum ) : ?>
                            <?php if ( empty( $l_sum['registered'] ) ) continue; ?>
                            <div class="sywat-mt-2">
                                <strong><?php echo esc_html( $l_sum['line_name'] ); ?></strong>
                                — Line Balance Due at Check-In: <strong>$<?php echo esc_html( number_format( floatval( $l_sum['total_due'] ), 2 ) ); ?></strong>
                                <?php if ( ! empty( $l_sum['family_cap_applied'] ) ) : ?>
                                    <span class="sywat-badge sywat-badge-open sywat-ml-1">Household Family Cap Applied</span>
                                <?php endif; ?>
                                <ul>
                                    <?php foreach ( $l_sum['registered'] as $reg_item ) : ?>
                                        <?php $reg_disp_name = $has_lf_formatter ? sywat_format_name_last_first( $reg_item['name'] ) : $reg_item['name']; ?>
                                        <li>
                                            <strong><?php echo esc_html( $reg_disp_name ); ?></strong> —
                                            <?php echo esc_html( $reg_item['division'] ); ?> (<?php echo esc_html( $reg_item['distance'] ); ?>)
                                            <?php if ( ! empty( $reg_item['is_promo_free'] ) ) : ?>
                                                <span class="sywat-badge sywat-badge-open sywat-ml-1">First-Time/Lapsed Promo ($0.00)</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                        <div class="sywat-mt-2">
                            <strong>Total Estimated Balance Due at Check-In Desk:</strong> $<?php echo esc_html( number_format( floatval( $submission_result['total_due'] ), 2 ) ); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $submission_result['waitlisted'] ) ) : ?>
                    <div class="sywat-alert sywat-alert-warning sywat-mt-3">
                        <strong>Added to Shooting Line Waitlist:</strong> The selected line(s) were at capacity for the target distance, so the following archer(s) were placed on the <strong>Waitlist</strong> for <strong><?php echo esc_html( $submission_result['tournament'] ); ?></strong> with <strong>$0.00</strong> due until a spot opens:
                        <?php foreach ( $line_summaries as $l_sum ) : ?>
                            <?php if ( empty( $l_sum['waitlisted'] ) ) continue; ?>
                            <div class="sywat-mt-2">
                                <strong><?php echo esc_html( $l_sum['line_name'] ); ?>:</strong>
                                <ul>
                                    <?php foreach ( $l_sum['waitlisted'] as $wl_item ) : ?>
                                        <?php $wl_disp_name = $has_lf_formatter ? sywat_format_name_last_first( $wl_item['name'] ) : $wl_item['name']; ?>
                                        <li>
                                            <strong><?php echo esc_html( $wl_disp_name ); ?></strong> —
                                            <?php echo esc_html( $wl_item['division'] ); ?> (<?php echo esc_html( $wl_item['distance'] ); ?>)
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <?php if ( ! empty( $submission_result['registered'] ) ) : ?>
                    <div class="sywat-alert sywat-alert-success sywat-mt-3">
                        <strong>Registration Confirmed!</strong> Reserved spot(s) for <strong><?php echo esc_html( $submission_result['tournament'] ); ?></strong> on <strong><?php echo esc_html( $submission_result['line_name'] ); ?></strong>:
                        <ul>
                            <?php foreach ( $submission_result['registered'] as $reg_item ) : ?>
                                <?php $reg_disp_name = $has_lf_formatter ? sywat_format_name_last_first( $reg_item['name'] ) : $reg_item['name']; ?>
                                <li>
                                    <strong><?php echo esc_html( $reg_disp_name ); ?></strong> —
                                    <?php echo esc_html( $reg_item['division'] ); ?> (<?php echo esc_html( $reg_item['distance'] ); ?>)
                                    <?php if ( ! empty( $reg_item['is_promo_free'] ) ) : ?>
                                        <span class="sywat-badge sywat-badge-open sywat-ml-1">First-Time/Lapsed Promo ($0.00)</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div>
                            <strong>Estimated Balance Due at Check-In Desk:</strong> $<?php echo esc_html( number_format( floatval( $submission_result['total_due'] ), 2 ) ); ?>
                            <?php if ( ! empty( $submission_result['family_cap_applied'] ) ) : ?>
                                <span class="sywat-badge sywat-badge-open sywat-ml-1">Household Family Cap Applied</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $submission_result['waitlisted'] ) ) : ?>
                    <div class="sywat-alert sywat-alert-warning sywat-mt-3">
                        <strong>Added to Shooting Line Waitlist:</strong> The selected line was at capacity for the target distance, so the following archer(s) were placed on the <strong>Waitlist</strong> for <strong><?php echo esc_html( $submission_result['tournament'] ); ?></strong> (<strong><?php echo esc_html( $submission_result['line_name'] ); ?></strong>) with <strong>$0.00</strong> due until a spot opens:
                        <ul>
                            <?php foreach ( $submission_result['waitlisted'] as $wl_item ) : ?>
                                <?php $wl_disp_name = $has_lf_formatter ? sywat_format_name_last_first( $wl_item['name'] ) : $wl_item['name']; ?>
                                <li>
                                    <strong><?php echo esc_html( $wl_disp_name ); ?></strong> —
                                    <?php echo esc_html( $wl_item['division'] ); ?> (<?php echo esc_html( $wl_item['distance'] ); ?>)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( ! empty( $submission_result['duplicates'] ) ) : ?>
                <div class="sywat-alert sywat-alert-warning sywat-mt-3">
                    <strong>Already Registered on Selected Line(s):</strong> <?php echo esc_html( implode( ', ', $submission_result['duplicates'] ) ); ?>.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( empty( $open_tournaments ) || ! is_array( $active_event ) ) : ?>
            <!-- STATE 2: NO TOURNAMENTS CURRENTLY OPEN -->
            <div class="sywat-alert sywat-alert-info sywat-mt-3">
                <strong>No Tournaments Currently Open for Online Registration:</strong> Check the Season Schedule to view upcoming opening dates (<code>Opens [Date]</code>) or external host registration links.
            </div>
            <?php if ( $schedule_url !== '' ) : ?>
                <div class="sywat-mt-3">
                    <a href="<?php echo esc_url( $schedule_url ); ?>" class="sywat-btn sywat-btn-primary">
                        📅 View Season Schedule
                    </a>
                </div>
            <?php endif; ?>

        <?php else : ?>
            <!-- STATE 3: SELECTED EVENT DETAILS & PER-ARCHER MULTI-LINE MATRIX FORM -->
            <?php
                $t_profiles     = isset( $active_event['pricing_profiles'] ) && is_array( $active_event['pricing_profiles'] ) ? $active_event['pricing_profiles'] : array();
                $is_multi_round = count( $t_profiles ) > 1;
                $default_prof   = ! empty( $t_profiles ) ? reset( $t_profiles ) : array();
                $default_p_slug = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';

                $format_labels = array();
                foreach ( $t_profiles as $tp ) {
                    $lbl = isset( $tp['label'] ) ? $tp['label'] : '';
                    if ( ! empty( $tp['round_subtitle'] ) ) {
                        $lbl .= ' (' . $tp['round_subtitle'] . ')';
                    }
                    if ( $lbl !== '' ) {
                        $format_labels[] = $lbl;
                    }
                }

                $head_phone_default = '';
                foreach ( $household as $hm ) {
                    if ( ! empty( $hm['is_head'] ) && ! empty( $hm['phone'] ) ) {
                        $head_phone_default = $hm['phone'];
                        break;
                    }
                }
                if ( isset( $_POST['contact_phone'] ) ) {
                    $head_phone_default = sanitize_text_field( wp_unslash( $_POST['contact_phone'] ) );
                }
            ?>

            <!-- SELECTED TOURNAMENT EVENT DETAILS -->
            <div class="sywat-alert sywat-alert-info sywat-mt-3 sywat-mb-3">
                <div>
                    <strong><?php echo esc_html( $active_event['name'] ); ?></strong>
                    <?php if ( $active_event['event_type'] !== 'SYWAT' ) : ?>
                        <span class="sywat-badge sywat-badge-open sywat-ml-1"><?php echo esc_html( $active_event['event_type'] ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="sywat-label-subtext">
                    <strong>Host:</strong> <?php echo esc_html( $active_event['shop_name'] ); ?>
                    • <strong>Dates:</strong> <?php echo esc_html( $active_event['display_dates'] ); ?>
                    <?php if ( ! empty( $format_labels ) ) : ?>
                        • <strong>Format:</strong> <?php echo esc_html( implode( ' / ', $format_labels ) ); ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- LIVE SHOOTING LINE AVAILABILITY SUMMARY -->
            <div class="sywat-table-header-bar sywat-mt-3">
                <h3>Shooting Line Availability</h3>
            </div>
            <div class="sywat-table-responsive sywat-mb-4">
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th>Shooting Line</th>
                            <th class="sywat-col-center">20yd Spots</th>
                            <th class="sywat-col-center">Cub (10yd) Spots</th>
                            <th class="sywat-col-center">Line Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $line_availability as $l_idx => $l_info ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $l_info['label'] ); ?></strong></td>
                                <td class="sywat-col-center">
                                    <?php if ( intval( $l_info['m_20'] ) <= 0 ) : ?>
                                        <span class="sywat-label-subtext">N/A (Cubs Only)</span>
                                    <?php else : ?>
                                        <strong><?php echo intval( $l_info['rem_20'] ); ?></strong> of <?php echo intval( $l_info['m_20'] ); ?> open
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-center">
                                    <?php if ( intval( $l_info['m_cub'] ) <= 0 ) : ?>
                                        <span class="sywat-label-subtext">N/A (20yd Only)</span>
                                    <?php else : ?>
                                        <strong><?php echo intval( $l_info['rem_cub'] ); ?></strong> of <?php echo intval( $l_info['m_cub'] ); ?> open
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-center">
                                    <?php if ( ! empty( $l_info['is_closed'] ) ) : ?>
                                        <span class="sywat-badge sywat-badge-muted">Closed</span>
                                    <?php elseif ( intval( $l_info['rem_20'] ) > 0 || intval( $l_info['rem_cub'] ) > 0 ) : ?>
                                        <span class="sywat-badge sywat-badge-open">Open</span>
                                    <?php else : ?>
                                        <span class="sywat-badge sywat-badge-warning">Waitlist Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- HOUSEHOLD MULTI-LINE REGISTRATION MATRIX FORM -->
            <form method="post" action="<?php echo esc_url( $form_action_url ); ?>">
                <?php wp_nonce_field( 'sywat_member_self_register', 'sywat_member_reg_nonce' ); ?>
                <input type="hidden" name="sywat_member_reg_submit" value="1">
                <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $selected_tournament ); ?>">

                <div class="sywat-form-grid-2 sywat-mb-3">
                    <div class="sywat-field-group">
                        <label for="sywat_member_reg_phone"><strong>Contact Phone Number</strong> <span class="sywat-label-subtext">(For line updates)</span></label>
                        <input type="tel" name="contact_phone" id="sywat_member_reg_phone" value="<?php echo esc_attr( $head_phone_default ); ?>" placeholder="e.g. 817-555-1234">
                    </div>
                </div>

                <div class="sywat-table-header-bar sywat-mt-3">
                    <div>
                        <h3>Select Household Archer(s) &amp; Shooting Line(s) *</h3>
                        <div class="sywat-label-subtext">Check one or more shooting lines for each household archer you wish to register.</div>
                    </div>
                    <?php if ( $profile_url !== '' ) : ?>
                        <a href="<?php echo esc_url( $profile_url ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-sm">
                            + Manage Family Members
                        </a>
                    <?php endif; ?>
                </div>

                <div class="sywat-table-responsive sywat-mb-3">
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th>Archer</th>
                                <th>Age Division</th>
                                <?php if ( $is_multi_round ) : ?>
                                    <th>Round Format</th>
                                <?php endif; ?>
                                <th class="sywat-col-center">Distance</th>
                                <?php foreach ( $line_availability as $l_idx => $l_info ) : ?>
                                    <th class="sywat-col-center">
                                        <?php echo esc_html( $l_info['label'] ); ?>
                                        <?php if ( ! empty( $l_info['is_closed'] ) ) : ?>
                                            <div class="sywat-label-subtext">Closed</div>
                                        <?php elseif ( intval( $l_info['rem_20'] ) <= 0 && intval( $l_info['rem_cub'] ) <= 0 ) : ?>
                                            <div class="sywat-label-subtext">Waitlist</div>
                                        <?php endif; ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $household as $m_key => $member ) : ?>
                                <?php
                                    $norm_name        = strtolower( trim( $member['name'] ) );
                                    $disp_member_name = ! empty( $member['display_name'] )
                                        ? $member['display_name']
                                        : ( $has_lf_formatter ? sywat_format_name_last_first( $member['name'] ) : $member['name'] );

                                    $member_regs = isset( $existing_member_regs[ $norm_name ] ) && is_array( $existing_member_regs[ $norm_name ] )
                                        ? $existing_member_regs[ $norm_name ]
                                        : array();
                                    $by_line_idx  = isset( $member_regs['by_line_idx'] ) && is_array( $member_regs['by_line_idx'] )
                                        ? $member_regs['by_line_idx']
                                        : array();
                                    $by_line_name = isset( $member_regs['by_line_name'] ) && is_array( $member_regs['by_line_name'] )
                                        ? $member_regs['by_line_name']
                                        : array();

                                    $div_id   = 'sywat_mreg_div_' . sanitize_key( $m_key );
                                    $round_id = 'sywat_mreg_rnd_' . sanitize_key( $m_key );
                                    $is_cub   = ( $member['distance'] === 'Cub' );
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $disp_member_name ); ?></strong>
                                        <div class="sywat-meta-subtext">
                                            <?php echo esc_html( $member['role_label'] ); ?>
                                            <?php if ( ! empty( $member['nfaa_number'] ) && $member['nfaa_number'] !== 'Unassigned' ) : ?>
                                                • NFAA #<?php echo esc_html( $member['nfaa_number'] ); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <label for="<?php echo esc_attr( $div_id ); ?>" class="sywat-label-subtext">Division</label>
                                        <select name="member_divisions[<?php echo esc_attr( $m_key ); ?>]" id="<?php echo esc_attr( $div_id ); ?>">
                                            <?php if ( ! empty( $division_options ) ) : ?>
                                                <?php foreach ( $division_options as $div_opt ) : ?>
                                                    <option value="<?php echo esc_attr( $div_opt['label'] ); ?>" <?php selected( $member['division'], $div_opt['label'] ); ?>>
                                                        <?php echo esc_html( $div_opt['label'] ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <option value="<?php echo esc_attr( $member['division'] ); ?>" selected>
                                                    <?php echo esc_html( $member['division'] ); ?>
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                    <?php if ( $is_multi_round ) : ?>
                                        <td>
                                            <label for="<?php echo esc_attr( $round_id ); ?>" class="sywat-label-subtext">Round</label>
                                            <select name="member_rounds[<?php echo esc_attr( $m_key ); ?>]" id="<?php echo esc_attr( $round_id ); ?>">
                                                <?php foreach ( $t_profiles as $p_slug => $p_rec ) : ?>
                                                    <option value="<?php echo esc_attr( $p_slug ); ?>" <?php selected( $default_p_slug, $p_slug ); ?>>
                                                        <?php echo esc_html( $p_rec['label'] ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    <?php else : ?>
                                        <input type="hidden" name="member_rounds[<?php echo esc_attr( $m_key ); ?>]" value="<?php echo esc_attr( $default_p_slug ); ?>">
                                    <?php endif; ?>
                                    <td class="sywat-col-center">
                                        <span class="sywat-badge <?php echo $is_cub ? 'sywat-badge-cubs' : 'sywat-badge-20yd'; ?>">
                                            <?php echo $is_cub ? 'Cub (10yd)' : '20yd'; ?>
                                        </span>
                                    </td>
                                    <?php foreach ( $line_availability as $l_idx => $l_info ) : ?>
                                        <?php
                                            $l_idx_str     = (string) $l_idx;
                                            $norm_lbl      = strtolower( trim( (string) $l_info['label'] ) );
                                            $existing_line = isset( $by_line_idx[ $l_idx_str ] )
                                                ? $by_line_idx[ $l_idx_str ]
                                                : ( isset( $by_line_name[ $norm_lbl ] ) ? $by_line_name[ $norm_lbl ] : null );
                                            $is_closed     = ! empty( $l_info['is_closed'] );
                                            $cell_cb_id    = 'sywat_mreg_cb_' . sanitize_key( $m_key ) . '_l' . sanitize_key( $l_idx_str );
                                            $cell_aria     = $disp_member_name . ' — ' . $l_info['label'];
                                        ?>
                                        <td class="sywat-col-center">
                                            <?php if ( is_array( $existing_line ) ) : ?>
                                                <span class="sywat-badge <?php echo ( strcasecmp( $existing_line['status'], 'Waitlist' ) === 0 ) ? 'sywat-badge-warning' : 'sywat-badge-checkedin'; ?>">
                                                    <?php echo esc_html( $existing_line['status'] ); ?>
                                                </span>
                                            <?php elseif ( $is_closed ) : ?>
                                                <span class="sywat-label-subtext">Closed</span>
                                            <?php else : ?>
                                                <input type="checkbox"
                                                       id="<?php echo esc_attr( $cell_cb_id ); ?>"
                                                       name="member_lines[<?php echo esc_attr( $m_key ); ?>][]"
                                                       value="<?php echo esc_attr( $l_idx_str ); ?>"
                                                       aria-label="<?php echo esc_attr( $cell_aria ); ?>">
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sywat-alert sywat-alert-info sywat-mb-3">
                    <strong>Payment &amp; Waitlist Policy:</strong> Entry fees are collected at the host shop check-in desk on tournament day (Household Family Caps apply per shooting line). If a selected line is full for an archer's target distance, that archer is automatically placed on that line's <strong>Waitlist</strong> at <strong>$0.00</strong> due until a spot opens.
                </div>

                <div class="sywat-header-inline-controls">
                    <button type="submit" class="sywat-btn sywat-btn-success">
                        <?php echo $icon_clipboard; ?> Complete Online Registration
                    </button>
                    <?php if ( $schedule_url !== '' ) : ?>
                        <a href="<?php echo esc_url( $schedule_url ); ?>" class="sywat-btn sywat-btn-secondary">
                            Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
