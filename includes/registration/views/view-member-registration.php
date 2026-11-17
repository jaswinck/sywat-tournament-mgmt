<?php
/**
 * View Template: Public Member Online Tournament Registration Portal
 * Path: includes/registration/views/view-member-registration.php
 * Description: Pure view template for [sywat_member_register]. Renders sign-in prompt for guests, open tournament selector, live line capacity availability table, and household multi-archer self-registration form with automatic age division/distance detection and Waitlist placement notices.
 * Compliance: Pure view layer — zero inline style attributes, zero embedded script blocks, zero inline event handlers, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
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

$icon_clipboard = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg>';
$icon_calendar  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
$icon_user      = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';

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
                    <?php echo $icon_calendar; ?> Season Schedule
                </a>
            <?php endif; ?>
            <?php if ( $is_logged_in && $profile_url !== '' ) : ?>
                <a href="<?php echo esc_url( $profile_url ); ?>" class="sywat-btn sywat-btn-secondary">
                    <?php echo $icon_user; ?> My Household
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
                    View Season Schedule
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

            <?php if ( ! empty( $submission_result['registered'] ) ) : ?>
                <div class="sywat-alert sywat-alert-success sywat-mt-3">
                    <strong>Registration Confirmed!</strong> Reserved spot(s) for <strong><?php echo esc_html( $submission_result['tournament'] ); ?></strong> on <strong><?php echo esc_html( $submission_result['line_name'] ); ?></strong>:
                    <ul>
                        <?php foreach ( $submission_result['registered'] as $reg_item ) : ?>
                            <li>
                                <strong><?php echo esc_html( $reg_item['name'] ); ?></strong> —
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
                            <li>
                                <strong><?php echo esc_html( $wl_item['name'] ); ?></strong> —
                                <?php echo esc_html( $wl_item['division'] ); ?> (<?php echo esc_html( $wl_item['distance'] ); ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $submission_result['duplicates'] ) ) : ?>
                <div class="sywat-alert sywat-alert-warning sywat-mt-3">
                    <strong>Already Registered on This Line:</strong> <?php echo esc_html( implode( ', ', $submission_result['duplicates'] ) ); ?>.
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
                        <?php echo $icon_calendar; ?> View Season Schedule
                    </a>
                </div>
            <?php endif; ?>

        <?php else : ?>
            <!-- STATE 3: OPEN TOURNAMENT SELECTOR & HOUSEHOLD SELF-REGISTRATION FORM -->
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

                $posted_line_idx = isset( $_POST['line_idx'] ) ? sanitize_text_field( wp_unslash( $_POST['line_idx'] ) ) : '';
                $first_open_idx  = '';
                foreach ( $line_availability as $l_idx => $l_info ) {
                    if ( empty( $l_info['is_closed'] ) ) {
                        if ( $first_open_idx === '' ) {
                            $first_open_idx = (string) $l_idx;
                        }
                        if ( ( $l_info['rem_20'] > 0 || $l_info['rem_cub'] > 0 ) && $posted_line_idx === '' ) {
                            $posted_line_idx = (string) $l_idx;
                            break;
                        }
                    }
                }
                if ( $posted_line_idx === '' ) {
                    $posted_line_idx = $first_open_idx;
                }
            ?>

            <!-- TOURNAMENT SWITCHER -->
            <form method="get" action="<?php echo esc_url( $form_action_url ); ?>" class="sywat-mt-3 sywat-mb-3">
                <div class="sywat-form-grid-2">
                    <div class="sywat-field-group">
                        <label for="sywat_member_reg_tournament_select"><strong>1. Select Open Tournament *</strong></label>
                        <div class="sywat-search-input-wrapper">
                            <select name="tournament" id="sywat_member_reg_tournament_select">
                                <?php foreach ( $open_tournaments as $t_key => $t_item ) : ?>
                                    <?php $opt_label = $t_item['name'] . ' — ' . $t_item['display_dates'] . ' (' . $t_item['shop_name'] . ')'; ?>
                                    <option value="<?php echo esc_attr( $t_key ); ?>" <?php selected( $selected_tournament, $t_key ); ?>>
                                        <?php echo esc_html( $opt_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="sywat-btn sywat-btn-secondary">Load Event</button>
                        </div>
                    </div>

                    <div class="sywat-field-group">
                        <label>Event Details</label>
                        <div>
                            <strong><?php echo esc_html( $active_event['shop_name'] ); ?></strong>
                            <?php if ( $active_event['event_type'] !== 'SYWAT' ) : ?>
                                <span class="sywat-badge sywat-badge-open sywat-ml-1"><?php echo esc_html( $active_event['event_type'] ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="sywat-label-subtext">
                            <?php echo esc_html( $active_event['display_dates'] ); ?>
                            <?php if ( ! empty( $format_labels ) ) : ?>
                                • Format: <?php echo esc_html( implode( ' / ', $format_labels ) ); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>

            <!-- LIVE SHOOTING LINE AVAILABILITY TABLE -->
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

            <!-- HOUSEHOLD REGISTRATION FORM -->
            <form method="post" action="<?php echo esc_url( $form_action_url ); ?>">
                <?php wp_nonce_field( 'sywat_member_self_register', 'sywat_member_reg_nonce' ); ?>
                <input type="hidden" name="sywat_member_reg_submit" value="1">
                <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $selected_tournament ); ?>">

                <div class="sywat-form-grid-2 sywat-mb-3">
                    <div class="sywat-field-group">
                        <label for="sywat_member_reg_line_idx"><strong>2. Choose Shooting Line *</strong></label>
                        <select name="line_idx" id="sywat_member_reg_line_idx" required>
                            <?php foreach ( $line_availability as $l_idx => $l_info ) : ?>
                                <?php if ( ! empty( $l_info['is_closed'] ) ) continue; ?>
                                <?php
                                    $avail_parts = array();
                                    if ( intval( $l_info['m_20'] ) > 0 ) {
                                        $avail_parts[] = '20yd: ' . intval( $l_info['rem_20'] ) . ' open';
                                    }
                                    if ( intval( $l_info['m_cub'] ) > 0 ) {
                                        $avail_parts[] = 'Cub: ' . intval( $l_info['rem_cub'] ) . ' open';
                                    }
                                    $avail_suffix = ! empty( $avail_parts ) ? ' (' . implode( ' | ', $avail_parts ) . ')' : '';
                                    if ( intval( $l_info['rem_20'] ) <= 0 && intval( $l_info['rem_cub'] ) <= 0 ) {
                                        $avail_suffix = ' (Full — Waitlist Only)';
                                    }
                                ?>
                                <option value="<?php echo esc_attr( (string) $l_idx ); ?>" <?php selected( (string) $posted_line_idx, (string) $l_idx ); ?>>
                                    <?php echo esc_html( $l_info['label'] . $avail_suffix ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label for="sywat_member_reg_phone"><strong>Contact Phone Number</strong> <span class="sywat-label-subtext">(For line updates)</span></label>
                        <input type="tel" name="contact_phone" id="sywat_member_reg_phone" value="<?php echo esc_attr( $head_phone_default ); ?>" placeholder="e.g. 817-555-1234">
                    </div>
                </div>

                <div class="sywat-table-header-bar sywat-mt-3">
                    <h3>3. Select Household Archer(s) to Register *</h3>
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
                                <th class="sywat-col-center">Select</th>
                                <th>Archer</th>
                                <th>Age Division</th>
                                <?php if ( $is_multi_round ) : ?>
                                    <th>Round Format</th>
                                <?php endif; ?>
                                <th class="sywat-col-center">Distance</th>
                                <th class="sywat-col-center">Registration Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $household as $m_key => $member ) : ?>
                                <?php
                                    $norm_name     = strtolower( trim( $member['name'] ) );
                                    $existing_info = isset( $existing_member_regs[ $norm_name ] ) ? $existing_member_regs[ $norm_name ] : null;
                                    $cb_id         = 'sywat_mreg_cb_' . sanitize_key( $m_key );
                                    $div_id        = 'sywat_mreg_div_' . sanitize_key( $m_key );
                                    $round_id      = 'sywat_mreg_rnd_' . sanitize_key( $m_key );
                                    $is_cub        = ( $member['distance'] === 'Cub' );
                                ?>
                                <tr>
                                    <td class="sywat-col-center">
                                        <input type="checkbox"
                                               id="<?php echo esc_attr( $cb_id ); ?>"
                                               name="selected_members[]"
                                               value="<?php echo esc_attr( $m_key ); ?>">
                                    </td>
                                    <td>
                                        <label for="<?php echo esc_attr( $cb_id ); ?>">
                                            <strong><?php echo esc_html( $member['name'] ); ?></strong>
                                        </label>
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
                                    <td class="sywat-col-center">
                                        <?php if ( is_array( $existing_info ) ) : ?>
                                            <span class="sywat-badge <?php echo ( strcasecmp( $existing_info['status'], 'Waitlist' ) === 0 ) ? 'sywat-badge-warning' : 'sywat-badge-checkedin'; ?>">
                                                <?php echo esc_html( $existing_info['status'] . ': ' . $existing_info['line_name'] ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="sywat-label-subtext">Not Registered</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sywat-alert sywat-alert-info sywat-mb-3">
                    <strong>Payment &amp; Waitlist Policy:</strong> Entry fees are collected at the host shop check-in desk on tournament day. If your selected line is full for an archer's target distance, that archer is automatically placed on the <strong>Waitlist</strong> at <strong>$0.00</strong> due until a spot opens.
                </div>

                <div class="sywat-header-inline-controls">
                    <button type="submit" class="sywat-btn sywat-btn-success">
                        <?php echo $icon_clipboard; ?> Complete Online Registration
                    </button>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
