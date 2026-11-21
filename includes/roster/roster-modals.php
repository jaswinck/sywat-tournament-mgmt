<?php
/**
 * Sub-Module: Archer Roster Modal Views
 * Path: includes/roster/roster-modals.php
 * Description: Renders the Edit Archer Registration modal unconditionally in the DOM (supporting both server-side deep-link pre-population and instantaneous client-side population from serialized roster row data without HTTP reloads), with Registration Status (Pre-Reg vs. Waitlist) synchronization, multi-round format selection, database-driven division/style/rate controls, Promotional Free Entry support, and line-scoped family group assignment.
 * Compliance: Pure view layer — zero DB writes, zero inline style/script attributes, delegated data-sywat-action attributes, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render Edit Archer Registration Popup Modal Component.
 *
 * @param array|null $target_archer             Target archer registration record or null.
 * @param string     $selected_tournament       Active tournament name.
 * @param array      $available_lines           Tournament shooting lines array.
 * @param array      $existing_families_by_line Line-scoped family group lookup map.
 * @return void
 */
if ( ! function_exists( 'sywat_render_edit_archer_modal' ) ) {
    function sywat_render_edit_archer_modal( $target_archer, $selected_tournament, $available_lines, $existing_families_by_line ) {
        $has_target      = is_array( $target_archer ) && ! empty( $target_archer );
        $open_edit_modal = $has_target;

        $manage_base_url = function_exists( 'sywat_roster_manage_base_url' )
            ? sywat_roster_manage_base_url()
            : ( function_exists( 'sywat_page_url' ) ? sywat_page_url( 'manage_tournaments' ) : get_permalink() );

        $return_to       = isset( $_REQUEST['return_to'] ) ? sanitize_key( wp_unslash( $_REQUEST['return_to'] ) ) : '';
        $filter_line_idx = isset( $_REQUEST['filter_line_idx'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['filter_line_idx'] ) ) : 'all';

        $edit_action_args = array(
            'tab'             => 'manage',
            'view_tournament' => $selected_tournament,
            'modal_action'    => 'manage',
            'filter_line_idx' => $filter_line_idx !== '' ? $filter_line_idx : 'all',
        );
        if ( ! empty( $return_to ) ) {
            $edit_action_args['return_to'] = $return_to;
        }

        $edit_form_action = add_query_arg(
            $edit_action_args,
            $manage_base_url
        );

        // Resolve active Pricing & Round Profiles for this tournament
        $t_profiles       = ( ! empty( $selected_tournament ) && function_exists( 'sywat_get_tournament_pricing_profiles' ) )
            ? sywat_get_tournament_pricing_profiles( $selected_tournament )
            : array();
        $is_multi_round   = count( $t_profiles ) > 1;
        $default_prof     = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';

        // Load active database-configured age divisions (with safe NFAA fallback)
        $division_options = array();
        $all_div_records  = array();
        if ( function_exists( 'sywat_get_division_records' ) ) {
            $all_div_records = sywat_get_division_records( true );
            if ( is_array( $all_div_records ) ) {
                foreach ( $all_div_records as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    if ( isset( $rec['status'] ) && $rec['status'] !== 'active' ) {
                        continue;
                    }
                    $division_options[ $rec['label'] ] = array(
                        'label'     => $rec['label'],
                        'distance'  => ( isset( $rec['distance'] ) && $rec['distance'] === 'Cub' ) ? 'Cub' : '20yd',
                        'rate_tier' => ( isset( $rec['rate_tier'] ) && $rec['rate_tier'] === 'Youth' ) ? 'Youth' : 'Adult',
                    );
                }
            }
        }

        if ( empty( $division_options ) ) {
            $fallback_divs = array(
                array( 'label' => 'Cub (8 - 11)',           'distance' => 'Cub',  'rate_tier' => 'Youth' ),
                array( 'label' => 'Youth (12 - 14)',        'distance' => '20yd', 'rate_tier' => 'Youth' ),
                array( 'label' => 'Young Adult (15 - 17)',  'distance' => '20yd', 'rate_tier' => 'Youth' ),
                array( 'label' => 'Adult (18 - 49)',        'distance' => '20yd', 'rate_tier' => 'Adult' ),
                array( 'label' => 'Senior (50 - 59)',       'distance' => '20yd', 'rate_tier' => 'Adult' ),
                array( 'label' => 'Silver Senior (60 - 69)','distance' => '20yd', 'rate_tier' => 'Adult' ),
                array( 'label' => 'Master Senior (70+)',    'distance' => '20yd', 'rate_tier' => 'Adult' ),
            );
            foreach ( $fallback_divs as $fd ) {
                $division_options[ $fd['label'] ] = $fd;
            }
        }

        // Load active database-configured shooting styles (with safe NFAA fallback)
        $style_options     = array();
        $all_style_records = array();
        if ( function_exists( 'sywat_get_shooting_styles' ) ) {
            $all_style_records = sywat_get_shooting_styles( true );
            if ( is_array( $all_style_records ) ) {
                foreach ( $all_style_records as $rec ) {
                    if ( empty( $rec['label'] ) ) {
                        continue;
                    }
                    if ( isset( $rec['status'] ) && $rec['status'] !== 'active' ) {
                        continue;
                    }
                    $style_options[ $rec['label'] ] = $rec['label'];
                }
            }
        }

        if ( empty( $style_options ) ) {
            $fallback_styles = array(
                'FS – Freestyle',
                'FS Pro – Freestyle Pro',
                'FSL – Freestyle Limited',
                'FSLR – Freestyle Limited Recurve (or Olympic Recurve)',
                'BHFS – Bowhunter Freestyle',
                'MBH – Modern Bowhunter',
                'BB – Barebow',
                'BBC – Barebow Compound',
                'BBR – Barebow Recurve',
                'TRAD – Traditional',
                'LB – Longbow',
                'Genesis',
            );
            foreach ( $fallback_styles as $fs ) {
                $style_options[ $fs ] = $fs;
            }
        }

        $genders = array( 'Male', 'Female' );

        // Resolve initial field values (supports both server-side deep-link and blank client-populated states)
        $reg_id_val          = ( $has_target && isset( $target_archer['id'] ) ) ? (string) $target_archer['id'] : '';
        $edit_name_val       = ( $has_target && isset( $target_archer['name'] ) ) ? (string) $target_archer['name'] : '';
        $curr_line           = ( $has_target && isset( $target_archer['line_index'] ) ) ? (string) $target_archer['line_index'] : '0';
        $curr_fam_id         = ( $has_target && isset( $target_archer['family_id'] ) ) ? trim( (string) $target_archer['family_id'] ) : '';
        $modal_line_families = isset( $existing_families_by_line[ $curr_line ] ) ? $existing_families_by_line[ $curr_line ] : array();
        $raw_status          = ( $has_target && isset( $target_archer['status'] ) ) ? trim( (string) $target_archer['status'] ) : 'Pre-Reg';
        $curr_status         = ( strcasecmp( $raw_status, 'Waitlist' ) === 0 )
            ? 'Waitlist'
            : ( ( strcasecmp( $raw_status, 'Cancelled' ) === 0 ) ? 'Cancelled' : 'Pre-Reg' );

        // Resolve archer's assigned Round / Pricing Profile
        $curr_round = ( $has_target && ! empty( $target_archer['pricing_profile'] ) )
            ? sanitize_title( (string) $target_archer['pricing_profile'] )
            : $default_prof_key;

        $round_options = $t_profiles;
        if ( $curr_round !== '' && ! isset( $round_options[ $curr_round ] ) ) {
            $legacy_prof = function_exists( 'sywat_find_pricing_profile' ) ? sywat_find_pricing_profile( $curr_round ) : null;
            if ( is_array( $legacy_prof ) ) {
                $round_options[ $curr_round ] = $legacy_prof;
            } else {
                $curr_round = $default_prof_key;
            }
        }

        $active_prof = isset( $round_options[ $curr_round ] ) ? $round_options[ $curr_round ] : $default_prof;
        $youth_rate  = is_array( $active_prof ) && isset( $active_prof['youth_rate'] ) ? floatval( $active_prof['youth_rate'] ) : 25.00;
        $adult_rate  = is_array( $active_prof ) && isset( $active_prof['adult_rate'] ) ? floatval( $active_prof['adult_rate'] ) : 30.00;
        $cap_enabled = is_array( $active_prof ) && ! empty( $active_prof['family_cap_enabled'] );
        $cap_amount  = is_array( $active_prof ) && isset( $active_prof['family_cap_amount'] ) ? floatval( $active_prof['family_cap_amount'] ) : 75.00;
        $cap_min     = is_array( $active_prof ) && isset( $active_prof['family_min_archers'] ) ? max( 2, intval( $active_prof['family_min_archers'] ) ) : 3;

        $ind_rate_label = ( abs( $youth_rate - $adult_rate ) < 0.01 )
            ? sprintf( 'Individual Rate ($%s)', number_format( $adult_rate, 0 ) )
            : sprintf( 'Individual Rate ($%s / $%s)', number_format( $youth_rate, 0 ), number_format( $adult_rate, 0 ) );

        $fam_rate_label = $cap_enabled
            ? sprintf( 'Family Group ($%s Cap for %d+ Archers)', number_format( $cap_amount, 0 ), $cap_min )
            : 'Family Group';

        $curr_paid        = ( $has_target && isset( $target_archer['paid'] ) ) ? trim( (string) $target_archer['paid'] ) : '';
        $is_promo_free    = $has_target && ( ! empty( $target_archer['is_promo_free'] )
            || strcasecmp( $curr_paid, 'Promo (Free)' ) === 0
            || stripos( $curr_paid, 'Promo' ) === 0 );
        $is_family_member = ( strcasecmp( $curr_paid, 'Family' ) === 0 ) || ( $curr_status === 'Waitlist' && $curr_fam_id !== '' );

        if ( $is_promo_free ) {
            $curr_pay_opt = 'Promo (Free)';
        } elseif ( $is_family_member ) {
            $curr_pay_opt = 'Family';
        } elseif ( strcasecmp( $curr_paid, 'Field Governor' ) === 0 ) {
            $curr_pay_opt = 'Field Governor';
        } else {
            $curr_pay_opt = 'Individual';
        }

        $raw_curr_div = ( $has_target && isset( $target_archer['division'] ) ) ? trim( (string) $target_archer['division'] ) : '';
        if ( $raw_curr_div !== '' && $raw_curr_div !== '—' && function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
            $curr_div = sywat_shooting_styles_normalize_division( $raw_curr_div );
        } else {
            $curr_div = ( $raw_curr_div !== '' && $raw_curr_div !== '—' ) ? $raw_curr_div : 'Adult (18 - 49)';
        }

        // Preserve retired or custom division currently assigned to this archer
        $archer_division_options = $division_options;
        if ( $curr_div !== '' && ! isset( $archer_division_options[ $curr_div ] ) ) {
            $retired_dist = function_exists( 'sywat_shooting_styles_get_division_distance' )
                ? sywat_shooting_styles_get_division_distance( $curr_div )
                : ( ( stripos( $curr_div, 'Cub' ) !== false ) ? 'Cub' : '20yd' );
            $retired_tier = function_exists( 'sywat_shooting_styles_get_division_rate_tier' )
                ? sywat_shooting_styles_get_division_rate_tier( $curr_div )
                : 'Adult';
            $archer_division_options[ $curr_div ] = array(
                'label'     => $curr_div . ' (Retired)',
                'distance'  => $retired_dist,
                'rate_tier' => $retired_tier,
            );
        }

        $curr_gen = ( $has_target && ! empty( $target_archer['gender'] ) ) ? (string) $target_archer['gender'] : 'Male';

        $raw_curr_sty = ( $has_target && isset( $target_archer['style'] ) ) ? trim( (string) $target_archer['style'] ) : '';
        if ( $raw_curr_sty !== '' && $raw_curr_sty !== '—' && function_exists( 'sywat_shooting_styles_normalize_style' ) ) {
            $curr_sty = sywat_shooting_styles_normalize_style( $raw_curr_sty );
        } else {
            $curr_sty = ( $raw_curr_sty !== '—' ) ? $raw_curr_sty : '';
        }

        // Preserve retired or legacy shooting style currently assigned to this archer
        $archer_style_options = $style_options;
        if ( $curr_sty !== '' && ! isset( $archer_style_options[ $curr_sty ] ) ) {
            $archer_style_options[ $curr_sty ] = $curr_sty . ' (Retired)';
        }

        $calc_dist = function_exists( 'sywat_shooting_styles_get_division_distance' )
            ? sywat_shooting_styles_get_division_distance( $curr_div )
            : ( ( $curr_div === 'Cub (8 - 11)' ) ? 'Cub' : '20yd' );

        $default_paid_label = $curr_paid !== ''
            ? $curr_paid
            : ( function_exists( 'sywat_shooting_styles_get_division_rate_tier' ) ? sywat_shooting_styles_get_division_rate_tier( $curr_div ) : 'Adult' );

        $balance_due_val = ( $has_target && isset( $target_archer['balance_due'] ) ) ? $target_archer['balance_due'] : '0.00';
        ?>
        <div class="sywat-modal-backdrop sywat-nested-modal <?php echo $open_edit_modal ? 'active' : ''; ?>" id="sywat_edit_archer_modal">
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header">
                    <div>
                        <h3>✏️ Edit Archer Registration</h3>
                        <div class="sywat-subtitle">Update registration status, round format, division, shooting line, or rate details.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" aria-label="Close Edit Archer Modal" data-sywat-action="close-edit-archer-modal">&times;</button>
                </div>

                <form method="post" action="<?php echo esc_url( $edit_form_action ); ?>" id="sywat_edit_archer_form">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="save_edit">
                        <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="modal_action" value="manage">
                        <input type="hidden" name="registration_id" id="edit_registration_id" value="<?php echo esc_attr( $reg_id_val ); ?>">
                        <input type="hidden" name="filter_line_idx" id="edit_filter_line_idx" value="<?php echo esc_attr( $filter_line_idx !== '' ? $filter_line_idx : 'all' ); ?>">
                        <?php if ( ! empty( $return_to ) ) : ?>
                            <input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
                        <?php endif; ?>

                        <?php if ( ! $is_multi_round && count( $round_options ) <= 1 ) : ?>
                            <input type="hidden" name="edit_pricing_profile" id="edit_pricing_profile_hidden" value="<?php echo esc_attr( $curr_round ); ?>">
                        <?php endif; ?>

                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group <?php echo ( $is_multi_round || count( $round_options ) > 1 ) ? '' : 'sywat-full-width'; ?>">
                                <label for="edit_name">Full Name</label>
                                <input type="text" id="edit_name" name="edit_name" value="<?php echo esc_attr( $edit_name_val ); ?>" required>
                            </div>

                            <?php if ( $is_multi_round || count( $round_options ) > 1 ) : ?>
                                <div class="sywat-field-group">
                                    <label for="edit_pricing_profile">Round Format</label>
                                    <select name="edit_pricing_profile" id="edit_pricing_profile" required data-sywat-action="roster-edit-round-change">
                                        <?php foreach ( $round_options as $r_slug => $r_rec ) : ?>
                                            <?php
                                                $r_label = $r_rec['label'];
                                                if ( ! empty( $r_rec['round_subtitle'] ) ) {
                                                    $r_label .= ' (' . $r_rec['round_subtitle'] . ')';
                                                }
                                            ?>
                                            <option value="<?php echo esc_attr( $r_slug ); ?>" <?php selected( $curr_round, $r_slug ); ?>>
                                                <?php echo esc_html( $r_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="sywat-field-group">
                                <label for="edit_line_index">Shooting Line</label>
                                <select name="edit_line_index" id="edit_line_index" required data-sywat-action="roster-edit-line-change">
                                    <?php foreach ( $available_lines as $idx => $line ) : ?>
                                        <?php
                                            $line_option_label = function_exists( 'sywat_roster_format_line_display' )
                                                ? sywat_roster_format_line_display( $line, $idx )
                                                : ( isset( $line['name'] ) ? $line['name'] : 'Line ' . ( (int) $idx + 1 ) );
                                        ?>
                                        <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $curr_line, (string) $idx ); ?>>
                                            <?php echo esc_html( $line_option_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="edit_status">Registration Status</label>
                                <select name="edit_status" id="edit_status" required>
                                    <option value="Pre-Reg" <?php selected( $curr_status, 'Pre-Reg' ); ?>>Pre-Reg (Active Roster)</option>
                                    <option value="Waitlist" <?php selected( $curr_status, 'Waitlist' ); ?>>Waitlist Queue ($0.00 Due)</option>
                                    <option value="Cancelled" id="edit_status_cancelled_opt" class="<?php echo ( $curr_status === 'Cancelled' ) ? '' : 'sywat-hidden'; ?>" <?php selected( $curr_status, 'Cancelled' ); ?>>Cancelled</option>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="edit_division">Division</label>
                                <select name="edit_division" id="edit_division" required data-sywat-action="roster-edit-division-change">
                                    <?php foreach ( $archer_division_options as $div_val => $div_meta ) : ?>
                                        <option
                                            value="<?php echo esc_attr( $div_val ); ?>"
                                            data-distance="<?php echo esc_attr( $div_meta['distance'] ); ?>"
                                            data-rate-tier="<?php echo esc_attr( $div_meta['rate_tier'] ); ?>"
                                            <?php selected( $curr_div, $div_val ); ?>
                                        >
                                            <?php echo esc_html( $div_meta['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="edit_gender">Gender</label>
                                <select name="edit_gender" id="edit_gender" required>
                                    <?php foreach ( $genders as $g ) : ?>
                                        <option value="<?php echo esc_attr( $g ); ?>" <?php selected( $curr_gen, $g ); ?>>
                                            <?php echo esc_html( $g ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="edit_style">Style</label>
                                <select name="edit_style" id="edit_style" required data-sywat-action="roster-edit-style-change">
                                    <option value="" <?php selected( $curr_sty, '' ); ?>>-- Select Style --</option>
                                    <?php foreach ( $archer_style_options as $sty_val => $sty_label ) : ?>
                                        <option value="<?php echo esc_attr( $sty_val ); ?>" <?php selected( $curr_sty, $sty_val ); ?>>
                                            <?php echo esc_html( $sty_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="edit_pay_option">Rate</label>
                                <select name="edit_pay_option" id="edit_pay_option" required data-sywat-action="roster-edit-pay-change">
                                    <option value="Individual" <?php selected( $curr_pay_opt, 'Individual' ); ?>><?php echo esc_html( $ind_rate_label ); ?></option>
                                    <option value="Family" <?php selected( $curr_pay_opt, 'Family' ); ?>><?php echo esc_html( $fam_rate_label ); ?></option>
                                    <option value="Field Governor" <?php selected( $curr_pay_opt, 'Field Governor' ); ?>>Field Governor (Free)</option>
                                    <option value="Promo (Free)" <?php selected( $curr_pay_opt, 'Promo (Free)' ); ?>>Promotional Free Entry ($0.00)</option>
                                </select>
                            </div>

                            <div class="sywat-field-group <?php echo ( $curr_pay_opt !== 'Family' ) ? 'sywat-hidden' : ''; ?>" id="edit_family_group_box">
                                <label for="edit_family_group_id">Assign to Line Family Group</label>
                                <select name="edit_family_group_id" id="edit_family_group_id">
                                    <option value="">[ + Start New Family Group for This Line ]</option>
                                    <?php foreach ( $modal_line_families as $fid => $flabel ) : ?>
                                        <option value="<?php echo esc_attr( $fid ); ?>" <?php selected( $curr_fam_id, $fid ); ?>>
                                            <?php echo esc_html( $flabel ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="edit_balance_due">Balance Due ($)</label>
                                <input type="number" step="0.01" min="0" id="edit_balance_due" name="edit_balance_due" value="<?php echo esc_attr( $balance_due_val ); ?>">
                            </div>

                            <div class="sywat-field-group">
                                <label for="edit_distance_display">Distance (Calculated)</label>
                                <input type="text" id="edit_distance_display" class="sywat-input-disabled-calc" value="<?php echo esc_attr( $calc_dist ); ?>" disabled>
                            </div>
                        </div>

                        <div class="sywat-calculated-status-box">
                            <span class="sywat-calculated-status-label">Calculated Rate:</span>
                            <span id="edit_paid_status_display" class="sywat-calculated-status-value"><?php echo esc_html( $default_paid_label ); ?></span>
                        </div>
                    </div>

                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-edit-archer-modal">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
