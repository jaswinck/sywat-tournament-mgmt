<?php
/**
 * Sub-Module: Check-In Desk Modals
 * Path: includes/checkin/checkin-modals.php
 * Description: Renders Check-In, Edit Archer, and Add Walk-In modals with live unpaid balance targets, Rate tier selection, multi-round format selection, First-Time/Lapsed Shooter Promotional Free Entry support, database-driven shooting styles, age divisions & pricing profiles, retired-record preservation, and household-wide unpaid family balance enforcement.
 * Compliance: Pure view layer — zero DB writes, zero inline style/script blocks, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render modal popups for Check-In, Edit Archer, and Add Walk-In.
 *
 * @param array $data Aggregated Check-In Desk dataset.
 * @return void
 */
if ( ! function_exists( 'sywat_desk_render_modals' ) ) {
    function sywat_desk_render_modals( $data ) {
        $modal_archer              = $data['modal_archer'];
        $modal_edit_archer         = $data['modal_edit_archer'];
        $selected_tournament       = $data['selected_tournament'];
        $selected_line_idx         = $data['selected_line_idx'];
        $search_query              = $data['search_query'];
        $lines                     = $data['lines'];
        $closed_lines              = $data['closed_lines'];
        $divs                      = isset( $data['divs'] ) && is_array( $data['divs'] ) ? $data['divs'] : array();
        $active_divs               = isset( $data['active_divs'] ) && is_array( $data['active_divs'] )
            ? $data['active_divs']
            : array_values( array_filter( $divs, function( $d ) { return $d !== 'No Show'; } ) );
        $genders                   = isset( $data['genders'] ) && is_array( $data['genders'] ) ? $data['genders'] : array( 'Male', 'Female', 'No Show' );
        $sywat_styles              = isset( $data['sywat_styles'] ) && is_array( $data['sywat_styles'] ) ? $data['sywat_styles'] : array();
        $active_styles             = isset( $data['active_styles'] ) && is_array( $data['active_styles'] )
            ? $data['active_styles']
            : array_values( array_filter( $sywat_styles, function( $s ) { return $s !== 'No Show'; } ) );
        $legacy_div_map            = isset( $data['legacy_div_map'] ) && is_array( $data['legacy_div_map'] ) ? $data['legacy_div_map'] : array();
        $existing_families         = $data['existing_families'];
        $existing_families_by_line = isset( $data['existing_families_by_line'] ) ? $data['existing_families_by_line'] : array();
        $all_tourn_regs            = isset( $data['registrations'] ) ? $data['registrations'] : array();

        // Active Tournament Pricing & Round Profiles
        $t_profiles       = isset( $data['pricing_profiles'] ) && is_array( $data['pricing_profiles'] ) ? $data['pricing_profiles'] : array();
        $is_multi_round   = ! empty( $data['is_multi_round'] ) || count( $t_profiles ) > 1;
        $default_prof_key = ! empty( $data['default_pricing_profile'] )
            ? (string) $data['default_pricing_profile']
            : ( ! empty( $t_profiles ) ? (string) key( $t_profiles ) : 'indoor' );
        $default_prof     = isset( $t_profiles[ $default_prof_key ] )
            ? $t_profiles[ $default_prof_key ]
            : ( ! empty( $t_profiles ) ? reset( $t_profiles ) : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() ) );
        $t_context        = ! empty( $data['selected_tournament_data'] ) ? $data['selected_tournament_data'] : $selected_tournament;

        $active_cap_amount = 75.00;
        if ( ! empty( $t_profiles ) ) {
            $max_cap = 0.00;
            foreach ( $t_profiles as $tp ) {
                if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_amount'] ) ) {
                    $max_cap = max( $max_cap, floatval( $tp['family_cap_amount'] ) );
                }
            }
            if ( $max_cap > 0 ) {
                $active_cap_amount = $max_cap;
            }
        } elseif ( is_array( $default_prof ) && isset( $default_prof['family_cap_amount'] ) ) {
            $active_cap_amount = floatval( $default_prof['family_cap_amount'] );
        }

        // Fast-Loop Context Persistence with 3-tier fail-safe checking
        $walkin_context = array();
        if ( ! empty( $data['walkin_loop_context'] ) ) {
            $walkin_context = $data['walkin_loop_context'];
        } elseif ( ! empty( $GLOBALS['sywat_walkin_loop_context'] ) ) {
            $walkin_context = $GLOBALS['sywat_walkin_loop_context'];
        } elseif ( isset( $_POST['sywat_action'] ) && $_POST['sywat_action'] === 'add_walkin_next' ) {
            $walkin_context = array(
                'primary_contact' => isset( $_POST['walkin_group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['walkin_group_name'] ) ) : '',
                'phone'           => isset( $_POST['walkin_group_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['walkin_group_phone'] ) ) : '',
                'line_idx'        => isset( $_POST['walkin_line_index'] ) ? sanitize_text_field( wp_unslash( $_POST['walkin_line_index'] ) ) : $selected_line_idx,
                'pricing_profile' => isset( $_POST['walkin_pricing_profile'] ) ? sanitize_title( wp_unslash( $_POST['walkin_pricing_profile'] ) ) : $default_prof_key,
                'family_id'       => isset( $_POST['walkin_family_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['walkin_family_group_id'] ) ) : '',
                'last_added_name' => isset( $_POST['walkin_name'] ) ? sanitize_text_field( wp_unslash( $_POST['walkin_name'] ) ) : '',
            );
        }

        $is_walkin_active = ! empty( $walkin_context );
        $saved_contact    = isset( $walkin_context['primary_contact'] ) ? $walkin_context['primary_contact'] : '';
        $saved_phone      = isset( $walkin_context['phone'] ) ? $walkin_context['phone'] : '';
        $saved_line_idx   = isset( $walkin_context['line_idx'] ) ? $walkin_context['line_idx'] : $selected_line_idx;
        $saved_round_slug = ! empty( $walkin_context['pricing_profile'] ) ? sanitize_title( (string) $walkin_context['pricing_profile'] ) : $default_prof_key;
        if ( ! empty( $t_profiles ) && ! isset( $t_profiles[ $saved_round_slug ] ) ) {
            $saved_round_slug = $default_prof_key;
        }
        $saved_fam_id     = isset( $walkin_context['family_id'] ) ? $walkin_context['family_id'] : '';
        $last_added_name  = isset( $walkin_context['last_added_name'] ) ? $walkin_context['last_added_name'] : '';
        $session_archers  = isset( $walkin_context['session_archers'] ) ? $walkin_context['session_archers'] : array();
        $session_total    = isset( $walkin_context['session_total'] ) ? floatval( $walkin_context['session_total'] ) : 0.00;
        $is_capped        = ! empty( $walkin_context['is_capped'] );
        $session_cap_amt  = isset( $walkin_context['cap_amount'] ) && floatval( $walkin_context['cap_amount'] ) > 0
            ? floatval( $walkin_context['cap_amount'] )
            : $active_cap_amount;
        ?>
        <!-- CHECK-IN MODAL (PRE-REG) -->
        <?php if ( $modal_archer ) : ?>
        <?php
            $modal_due      = isset( $modal_archer['balance_due'] ) ? floatval( $modal_archer['balance_due'] ) : 0;
            $curr_paid_raw  = isset( $modal_archer['paid'] ) ? trim( (string) $modal_archer['paid'] ) : '';
            $is_family      = strcasecmp( $curr_paid_raw, 'Family' ) === 0;
            $is_promo_check = ! empty( $modal_archer['is_promo_free'] )
                || strcasecmp( $curr_paid_raw, 'Promo (Free)' ) === 0
                || stripos( $curr_paid_raw, 'Promo' ) === 0;

            $curr_div_check = isset( $modal_archer['division'] ) ? trim( (string) $modal_archer['division'] ) : '';
            if ( isset( $legacy_div_map[ $curr_div_check ] ) ) {
                $curr_div_check = $legacy_div_map[ $curr_div_check ];
            }
            if ( $curr_div_check !== '' && strcasecmp( $curr_div_check, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
                $curr_div_check = sywat_shooting_styles_normalize_division( $curr_div_check );
            }

            $raw_sty_check = isset( $modal_archer['style'] ) ? trim( (string) $modal_archer['style'] ) : '';
            if ( $raw_sty_check === '—' ) {
                $raw_sty_check = '';
            }
            if ( $raw_sty_check !== '' && strcasecmp( $raw_sty_check, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_style' ) ) {
                $raw_sty_check = sywat_shooting_styles_normalize_style( $raw_sty_check );
            }

            $checkin_round = ! empty( $modal_archer['pricing_profile'] )
                ? sanitize_title( (string) $modal_archer['pricing_profile'] )
                : $default_prof_key;
            $checkin_round_options = $t_profiles;
            if ( $checkin_round !== '' && ! isset( $checkin_round_options[ $checkin_round ] ) ) {
                $legacy_rp = function_exists( 'sywat_find_pricing_profile' ) ? sywat_find_pricing_profile( $checkin_round ) : null;
                if ( is_array( $legacy_rp ) ) {
                    $checkin_round_options[ $checkin_round ] = $legacy_rp;
                } else {
                    $checkin_round = $default_prof_key;
                }
            }

            if ( $curr_div_check !== '' && function_exists( 'sywat_shooting_styles_get_division_rate_tier' ) ) {
                $is_youth_check = ( sywat_shooting_styles_get_division_rate_tier( $curr_div_check ) === 'Youth' );
            } else {
                $is_youth_check = ( stripos( $curr_div_check, 'Cub' ) !== false || stripos( $curr_div_check, 'Youth' ) !== false || stripos( $curr_div_check, 'Young Adult' ) !== false );
            }

            $calc_dist_check = function_exists( 'sywat_shooting_styles_get_division_distance' )
                ? sywat_shooting_styles_get_division_distance( $curr_div_check )
                : ( ( stripos( $curr_div_check, 'Cub' ) !== false ) ? 'Cub' : '20yd' );

            $fallback_ind_fee = 0.00;
            if ( ! $is_promo_check && strcasecmp( $curr_paid_raw, 'Field Governor' ) !== 0 ) {
                $fallback_ind_fee = function_exists( 'sywat_get_archer_individual_fee' )
                    ? sywat_get_archer_individual_fee( $t_context, $curr_div_check, $calc_dist_check, $raw_sty_check, $checkin_round, '' )
                    : ( $is_youth_check ? 25.00 : 30.00 );
            }

            $household_info = function_exists( 'sywat_get_household_balance_info' )
                ? sywat_get_household_balance_info( $modal_archer, $all_tourn_regs, $lines )
                : null;

            $effective_due = $household_info ? floatval( $household_info['effective_due'] ) : $modal_due;
            if ( ! $household_info && $is_family ) {
                $fam_id = ! empty( $modal_archer['family_id'] ) ? trim( (string) $modal_archer['family_id'] ) : ( ! empty( $modal_archer['booking_id'] ) ? trim( (string) $modal_archer['booking_id'] ) : '' );
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
            }

            $is_family_balance = $is_family || ( $household_info && ! empty( $household_info['has_family'] ) );
            $display_due       = $is_family_balance ? $effective_due : ( $modal_due > 0 ? $modal_due : $fallback_ind_fee );

            $checkin_line_idx = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $modal_archer, $lines ) : ( isset( $modal_archer['line_index'] ) ? $modal_archer['line_index'] : '0' );
            $checkin_families = isset( $existing_families_by_line[ $checkin_line_idx ] ) ? $existing_families_by_line[ $checkin_line_idx ] : $existing_families;

            if ( $is_promo_check ) {
                $curr_pay_opt = 'Promo (Free)';
            } elseif ( $is_family ) {
                $curr_pay_opt = 'Family';
            } elseif ( strcasecmp( $curr_paid_raw, 'Field Governor' ) === 0 ) {
                $curr_pay_opt = 'Field Governor';
            } else {
                $curr_pay_opt = 'Individual';
            }
        ?>
        <div class="sywat-modal-backdrop active">
            <div class="sywat-modal-content sywat-modal-sm">
                <div class="sywat-modal-header no-print">
                    <h3>Check In Archer</h3>
                    <form method="post">
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $selected_line_idx ); ?>">
                        <button type="submit" name="sywat_action" value="cancel" class="sywat-modal-close" formnovalidate>&times;</button>
                    </form>
                </div>
                <div class="sywat-modal-body">
                    <form method="post">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $selected_line_idx ); ?>">
                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $search_query ); ?>">
                        <input type="hidden" name="reg_id" value="<?php echo esc_attr( $modal_archer['id'] ); ?>">

                        <?php if ( ! $is_multi_round && count( $checkin_round_options ) <= 1 ) : ?>
                            <input type="hidden" name="checkin_pricing_profile" id="checkin_pricing_profile" value="<?php echo esc_attr( $checkin_round ); ?>">
                        <?php endif; ?>

                        <div class="sywat-field-group sywat-field-relative">
                            <label>Name:</label>
                            <input type="text" name="checkin_name" id="checkin_archer_name" value="<?php echo esc_attr( $modal_archer['name'] ); ?>" autocomplete="off" class="sywat-input-bold">
                            <div id="checkin_tfaa_badge"></div>
                            <div id="checkin_tfaa_pills" class="sywat-pill-box"></div>
                        </div>

                        <?php if ( $is_multi_round || count( $checkin_round_options ) > 1 ) : ?>
                            <div class="sywat-field-group">
                                <label for="checkin_pricing_profile">Round Format:</label>
                                <select name="checkin_pricing_profile" id="checkin_pricing_profile" required>
                                    <?php foreach ( $checkin_round_options as $r_slug => $r_rec ) : ?>
                                        <?php
                                            $r_label = $r_rec['label'];
                                            if ( ! empty( $r_rec['round_subtitle'] ) ) {
                                                $r_label .= ' (' . $r_rec['round_subtitle'] . ')';
                                            }
                                        ?>
                                        <option value="<?php echo esc_attr( $r_slug ); ?>" <?php selected( $checkin_round, $r_slug ); ?>>
                                            <?php echo esc_html( $r_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if ( $effective_due > 0 ) : ?>
                            <div id="sywat_unpaid_warning_banner"
                                 class="sywat-alert sywat-alert-danger sywat-alert-center"
                                 data-orig-due="<?php echo esc_attr( number_format( $display_due, 2, '.', '' ) ); ?>"
                                 data-is-family-balance="<?php echo $is_family_balance ? '1' : '0'; ?>">
                                <div class="sywat-alert-title">
                                    ⚠️ <?php echo $is_family_balance ? 'UNPAID FAMILY BALANCE' : 'UNPAID BALANCE'; ?>: $<span id="checkin_unpaid_amount_disp"><?php echo number_format( $display_due, 2 ); ?></span>
                                </div>
                                <div class="sywat-alert-subtext">
                                    <?php if ( $is_family_balance ) : ?>
                                        Family Cap Group Outstanding Balance. Collect Payment Onsite.
                                    <?php else : ?>
                                        Please See Event Staff for Payment
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="sywat-field-group">
                                <label class="sywat-checkbox-label-danger">
                                    <input type="checkbox" name="checkin_payment_verified" id="sywat_payment_verified_check" value="1" required class="sywat-checkbox-auto" onchange="toggleUnpaidBanner(this.checked)">
                                    Payment / Resolution Verified with Event Staff
                                </label>
                            </div>
                        <?php endif; ?>

                        <div class="sywat-field-group">
                            <label>Division:</label>
                            <select name="checkin_division" id="checkin_division" required onchange="handleStyleRestrictions('checkin'); evaluateTfaaStatus('checkin');">
                                <?php
                                $curr_div = $curr_div_check;
                                if ( $curr_div === '—' ) {
                                    $curr_div = '';
                                }

                                $checkin_div_options = array();
                                foreach ( $active_divs as $d ) {
                                    if ( $d === 'No Show' ) {
                                        continue;
                                    }
                                    $checkin_div_options[ $d ] = $d;
                                }
                                if ( $curr_div !== '' && $curr_div !== 'No Show' && ! isset( $checkin_div_options[ $curr_div ] ) ) {
                                    $checkin_div_options[ $curr_div ] = $curr_div . ' (Retired)';
                                }
                                if ( $curr_div === 'No Show' ) {
                                    $checkin_div_options['No Show'] = 'No Show';
                                }

                                echo '<option value="" disabled ' . ( empty( $curr_div ) ? 'selected' : '' ) . ' hidden>Select Division</option>';
                                foreach ( $checkin_div_options as $d_val => $d_label ) {
                                    echo '<option value="' . esc_attr( $d_val ) . '" ' . selected( $curr_div, $d_val, false ) . '>' . esc_html( $d_label ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label>Gender:</label>
                            <select name="checkin_gender" id="checkin_gender" required onchange="evaluateTfaaStatus('checkin')">
                                <?php
                                $curr_gen = isset( $modal_archer['gender'] ) && in_array( $modal_archer['gender'], $genders, true ) ? $modal_archer['gender'] : '';
                                echo '<option value="" disabled ' . ( empty( $curr_gen ) ? 'selected' : '' ) . ' hidden>Select Gender</option>';
                                foreach ( $genders as $g ) {
                                    if ( $g === 'No Show' && $curr_gen !== 'No Show' ) {
                                        continue;
                                    }
                                    echo '<option value="' . esc_attr( $g ) . '" ' . selected( $curr_gen, $g, false ) . '>' . esc_html( $g ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label>Style:</label>
                            <select name="checkin_style" id="checkin_style" required onchange="if(window.handleStyleChange) handleStyleChange('checkin'); evaluateTfaaStatus('checkin');">
                                <?php
                                $curr_sty = $raw_sty_check;

                                $checkin_style_options = array();
                                foreach ( $active_styles as $s ) {
                                    if ( $s === 'No Show' ) {
                                        continue;
                                    }
                                    $checkin_style_options[ $s ] = $s;
                                }
                                if ( $curr_sty !== '' && $curr_sty !== 'No Show' && ! isset( $checkin_style_options[ $curr_sty ] ) ) {
                                    $checkin_style_options[ $curr_sty ] = $curr_sty . ' (Retired)';
                                }
                                if ( $curr_sty === 'No Show' ) {
                                    $checkin_style_options['No Show'] = 'No Show';
                                }

                                echo '<option value="" disabled ' . ( empty( $curr_sty ) ? 'selected' : '' ) . ' hidden>Select Style</option>';
                                foreach ( $checkin_style_options as $s_val => $s_label ) {
                                    echo '<option value="' . esc_attr( $s_val ) . '" ' . selected( $curr_sty, $s_val, false ) . '>' . esc_html( $s_label ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="checkin_pay_option">Rate:</label>
                            <select name="checkin_pay_option" id="checkin_pay_option" required onchange="toggleFamilyBox('checkin')">
                                <option value="Individual" <?php selected( $curr_pay_opt, 'Individual' ); ?>>Individual</option>
                                <option value="Family" <?php selected( $curr_pay_opt, 'Family' ); ?>>Family</option>
                                <option value="Field Governor" <?php selected( $curr_pay_opt, 'Field Governor' ); ?>>Field Governor</option>
                                <option value="Promo (Free)" <?php selected( $curr_pay_opt, 'Promo (Free)' ); ?>>Promotional Free Entry ($0.00)</option>
                            </select>
                        </div>

                        <div class="sywat-field-group <?php echo ( $curr_pay_opt === 'Family' ) ? '' : 'sywat-hidden'; ?>" id="checkin_family_box">
                            <label>Line Family Group:</label>
                            <select name="checkin_family_group_id" id="checkin_family_group_id">
                                <option value="">[ + Start New Family Group for This Line ]</option>
                                <?php
                                $curr_fam_id = isset( $modal_archer['family_id'] ) ? $modal_archer['family_id'] : '';
                                foreach ( $checkin_families as $fid => $flabel ) {
                                    echo '<option value="' . esc_attr( $fid ) . '" ' . selected( $curr_fam_id, $fid, false ) . '>' . esc_html( $flabel ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sywat-modal-form-actions">
                            <button type="submit" name="sywat_action" value="cancel" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" formnovalidate>Cancel</button>
                            <button type="submit" name="sywat_action" value="save_checkin" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin">Complete Check-In</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- EDIT ARCHER MODAL -->
        <?php if ( $modal_edit_archer ) : ?>
        <?php
            $edit_line_idx = function_exists( 'sywat_desk_resolve_line_idx' ) ? sywat_desk_resolve_line_idx( $modal_edit_archer, $lines ) : ( isset( $modal_edit_archer['line_index'] ) ? $modal_edit_archer['line_index'] : '0' );
            $edit_families = isset( $existing_families_by_line[ $edit_line_idx ] ) ? $existing_families_by_line[ $edit_line_idx ] : $existing_families;

            $edit_round = ! empty( $modal_edit_archer['pricing_profile'] )
                ? sanitize_title( (string) $modal_edit_archer['pricing_profile'] )
                : $default_prof_key;
            $edit_round_options = $t_profiles;
            if ( $edit_round !== '' && ! isset( $edit_round_options[ $edit_round ] ) ) {
                $legacy_edit_rp = function_exists( 'sywat_find_pricing_profile' ) ? sywat_find_pricing_profile( $edit_round ) : null;
                if ( is_array( $legacy_edit_rp ) ) {
                    $edit_round_options[ $edit_round ] = $legacy_edit_rp;
                } else {
                    $edit_round = $default_prof_key;
                }
            }

            $curr_edit_paid_raw = isset( $modal_edit_archer['paid'] ) ? trim( (string) $modal_edit_archer['paid'] ) : '';
            $is_edit_promo      = ! empty( $modal_edit_archer['is_promo_free'] )
                || strcasecmp( $curr_edit_paid_raw, 'Promo (Free)' ) === 0
                || stripos( $curr_edit_paid_raw, 'Promo' ) === 0;

            if ( $is_edit_promo ) {
                $curr_edit_pay_opt = 'Promo (Free)';
            } elseif ( strcasecmp( $curr_edit_paid_raw, 'Family' ) === 0 ) {
                $curr_edit_pay_opt = 'Family';
            } elseif ( strcasecmp( $curr_edit_paid_raw, 'Field Governor' ) === 0 ) {
                $curr_edit_pay_opt = 'Field Governor';
            } else {
                $curr_edit_pay_opt = 'Individual';
            }

            $edit_orig_due = isset( $modal_edit_archer['balance_due'] ) ? floatval( $modal_edit_archer['balance_due'] ) : 0.00;
        ?>
        <div class="sywat-modal-backdrop active">
            <div class="sywat-modal-content sywat-modal-sm">
                <div class="sywat-modal-header no-print">
                    <h3>Edit Archer Registration</h3>
                    <form method="post">
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $selected_line_idx ); ?>">
                        <button type="submit" name="sywat_action" value="cancel" class="sywat-modal-close" formnovalidate>&times;</button>
                    </form>
                </div>
                <div class="sywat-modal-body">
                    <form method="post">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $selected_line_idx ); ?>">
                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $search_query ); ?>">
                        <input type="hidden" name="reg_id" value="<?php echo esc_attr( $modal_edit_archer['id'] ); ?>">

                        <?php if ( ! $is_multi_round && count( $edit_round_options ) <= 1 ) : ?>
                            <input type="hidden" name="edit_pricing_profile" id="edit_pricing_profile" value="<?php echo esc_attr( $edit_round ); ?>">
                        <?php endif; ?>

                        <div class="sywat-field-group sywat-field-relative">
                            <label>Full Name:</label>
                            <input type="text" name="edit_name" id="edit_archer_name" value="<?php echo esc_attr( $modal_edit_archer['name'] ); ?>" autocomplete="off" required>
                        </div>

                        <?php if ( $is_multi_round || count( $edit_round_options ) > 1 ) : ?>
                            <div class="sywat-field-group">
                                <label for="edit_pricing_profile">Round Format:</label>
                                <select name="edit_pricing_profile" id="edit_pricing_profile" required>
                                    <?php foreach ( $edit_round_options as $r_slug => $r_rec ) : ?>
                                        <?php
                                            $r_label = $r_rec['label'];
                                            if ( ! empty( $r_rec['round_subtitle'] ) ) {
                                                $r_label .= ' (' . $r_rec['round_subtitle'] . ')';
                                            }
                                        ?>
                                        <option value="<?php echo esc_attr( $r_slug ); ?>" <?php selected( $edit_round, $r_slug ); ?>>
                                            <?php echo esc_html( $r_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="sywat-field-group">
                            <label>Shooting Line:</label>
                            <select name="edit_line_index" id="edit_line_index" required onchange="updateEditFamilyOptions(this.value)">
                                <?php $curr_line = isset( $modal_edit_archer['line_index'] ) ? $modal_edit_archer['line_index'] : ''; ?>
                                <?php foreach ( $lines as $idx => $line ) : ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $curr_line, (string) $idx ); ?>>
                                        <?php echo esc_html( $line['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label>Division:</label>
                            <select name="edit_division" id="edit_division" required onchange="handleStyleRestrictions('edit');">
                                <?php
                                $raw_div = isset( $modal_edit_archer['division'] ) ? trim( (string) $modal_edit_archer['division'] ) : '';
                                if ( $raw_div === '—' ) {
                                    $raw_div = '';
                                }
                                if ( isset( $legacy_div_map[ $raw_div ] ) ) {
                                    $raw_div = $legacy_div_map[ $raw_div ];
                                }
                                if ( $raw_div !== '' && strcasecmp( $raw_div, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
                                    $raw_div = sywat_shooting_styles_normalize_division( $raw_div );
                                }
                                $curr_div = $raw_div;

                                $edit_div_options = array();
                                foreach ( $active_divs as $d ) {
                                    if ( $d === 'No Show' ) {
                                        continue;
                                    }
                                    $edit_div_options[ $d ] = $d;
                                }
                                if ( $curr_div !== '' && $curr_div !== 'No Show' && ! isset( $edit_div_options[ $curr_div ] ) ) {
                                    $edit_div_options[ $curr_div ] = $curr_div . ' (Retired)';
                                }
                                if ( $curr_div === 'No Show' ) {
                                    $edit_div_options['No Show'] = 'No Show';
                                }

                                echo '<option value="" disabled ' . ( empty( $curr_div ) ? 'selected' : '' ) . ' hidden>Select Division</option>';
                                foreach ( $edit_div_options as $d_val => $d_label ) {
                                    echo '<option value="' . esc_attr( $d_val ) . '" ' . selected( $curr_div, $d_val, false ) . '>' . esc_html( $d_label ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label>Gender:</label>
                            <select name="edit_gender" id="edit_gender" required>
                                <?php
                                $curr_gen = isset( $modal_edit_archer['gender'] ) && in_array( $modal_edit_archer['gender'], $genders, true ) ? $modal_edit_archer['gender'] : '';
                                echo '<option value="" disabled ' . ( empty( $curr_gen ) ? 'selected' : '' ) . ' hidden>Select Gender</option>';
                                foreach ( $genders as $g ) {
                                    if ( $g === 'No Show' && $curr_gen !== 'No Show' ) {
                                        continue;
                                    }
                                    echo '<option value="' . esc_attr( $g ) . '" ' . selected( $curr_gen, $g, false ) . '>' . esc_html( $g ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label>Style:</label>
                            <select name="edit_style" id="edit_style" required onchange="if(window.handleStyleChange) handleStyleChange('edit');">
                                <?php
                                $raw_sty = isset( $modal_edit_archer['style'] ) ? trim( (string) $modal_edit_archer['style'] ) : '';
                                if ( $raw_sty === '—' ) {
                                    $raw_sty = '';
                                }
                                if ( $raw_sty !== '' && strcasecmp( $raw_sty, 'No Show' ) !== 0 && function_exists( 'sywat_shooting_styles_normalize_style' ) ) {
                                    $raw_sty = sywat_shooting_styles_normalize_style( $raw_sty );
                                }
                                $curr_sty = $raw_sty;

                                $edit_style_options = array();
                                foreach ( $active_styles as $s ) {
                                    if ( $s === 'No Show' ) {
                                        continue;
                                    }
                                    $edit_style_options[ $s ] = $s;
                                }
                                if ( $curr_sty !== '' && $curr_sty !== 'No Show' && ! isset( $edit_style_options[ $curr_sty ] ) ) {
                                    $edit_style_options[ $curr_sty ] = $curr_sty . ' (Retired)';
                                }
                                if ( $curr_sty === 'No Show' ) {
                                    $edit_style_options['No Show'] = 'No Show';
                                }

                                echo '<option value="" disabled ' . ( empty( $curr_sty ) ? 'selected' : '' ) . ' hidden>Select Style</option>';
                                foreach ( $edit_style_options as $s_val => $s_label ) {
                                    echo '<option value="' . esc_attr( $s_val ) . '" ' . selected( $curr_sty, $s_val, false ) . '>' . esc_html( $s_label ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="edit_pay_option">Rate:</label>
                            <select name="edit_pay_option" id="edit_pay_option" required onchange="toggleFamilyBox('edit')">
                                <option value="Individual" <?php selected( $curr_edit_pay_opt, 'Individual' ); ?>>Individual</option>
                                <option value="Family" <?php selected( $curr_edit_pay_opt, 'Family' ); ?>>Family</option>
                                <option value="Field Governor" <?php selected( $curr_edit_pay_opt, 'Field Governor' ); ?>>Field Governor</option>
                                <option value="Promo (Free)" <?php selected( $curr_edit_pay_opt, 'Promo (Free)' ); ?>>Promotional Free Entry ($0.00)</option>
                            </select>
                        </div>

                        <div class="sywat-field-group <?php echo ( $curr_edit_pay_opt === 'Family' ) ? '' : 'sywat-hidden'; ?>" id="edit_family_box">
                            <label>Line Family Group:</label>
                            <select name="edit_family_group_id" id="edit_family_group_id">
                                <option value="">[ + Start New Family Group for This Line ]</option>
                                <?php
                                $curr_fam_id = isset( $modal_edit_archer['family_id'] ) ? $modal_edit_archer['family_id'] : '';
                                foreach ( $edit_families as $fid => $flabel ) {
                                    echo '<option value="' . esc_attr( $fid ) . '" ' . selected( $curr_fam_id, $fid, false ) . '>' . esc_html( $flabel ) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="edit_balance_due">Balance Due ($):</label>
                            <input type="number" step="0.01" min="0" id="edit_balance_due" name="edit_balance_due" data-orig-due="<?php echo esc_attr( number_format( $edit_orig_due, 2, '.', '' ) ); ?>" value="<?php echo esc_attr( $edit_orig_due ); ?>">
                        </div>

                        <div class="sywat-modal-form-actions-split">
                            <button type="submit" name="sywat_action" value="delete_archer" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" onclick="return confirm('⚠️ Are you sure you want to PERMANENTLY delete this archer?');" formnovalidate>Delete</button>
                            
                            <div class="sywat-modal-form-actions-right">
                                <button type="submit" name="sywat_action" value="cancel" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" formnovalidate>Cancel</button>
                                <button type="submit" name="sywat_action" value="save_edit" class="sywat-btn sywat-btn-primary sywat-btn-icon-save">Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ADD WALK-IN MODAL (AUTOMATED FEE, PROMO & FAMILY CAP ENGINE) -->
        <div id="walkinModal" class="sywat-modal-backdrop <?php echo $is_walkin_active ? 'active' : ''; ?>">
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>📋 Add Walk-In Registration</h3>
                        <div class="sywat-subtitle">Register walk-in archers, assign shooting lines, and calculate fees on-site.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" onclick="closeWalkinModal()">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <?php if ( ! empty( $last_added_name ) ) : ?>
                        <div class="sywat-alert sywat-alert-success sywat-modal-row-spacing">
                            ✅ <strong><?php echo esc_html( $last_added_name ); ?></strong> registered and checked in successfully! Enter next archer below.
                        </div>
                    <?php endif; ?>

                    <form method="post" id="sywat_walkin_form">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="selected_line_idx" value="<?php echo esc_attr( $selected_line_idx ); ?>">
                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $search_query ); ?>">
                        <input type="hidden" name="walkin_session_archers_json" value="<?php echo esc_attr( wp_json_encode( $session_archers ) ); ?>">

                        <?php if ( ! $is_multi_round ) : ?>
                            <input type="hidden" name="walkin_pricing_profile" id="walkin_pricing_profile" value="<?php echo esc_attr( $default_prof_key ); ?>">
                        <?php endif; ?>

                        <!-- Persistent Group Intake Header -->
                        <div class="sywat-intake-group-wrapper">
                            <div class="sywat-form-grid-2">
                                <div class="sywat-field-group sywat-field-relative">
                                    <label for="walkin_group_name">Primary Contact / Group Name:</label>
                                    <input type="text" name="walkin_group_name" id="walkin_group_name" value="<?php echo esc_attr( $saved_contact ); ?>" placeholder="e.g. John Smith" autocomplete="off" oninput="if(window.handleTfaaAutocomplete) window.handleTfaaAutocomplete(this); if(window.handleWalkinGroupInput) window.handleWalkinGroupInput(this);">
                                    <div class="sywat-tfaa-autocomplete-dropdown sywat-hidden"></div>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="walkin_group_phone">Phone Number:</label>
                                    <input type="text" name="walkin_group_phone" id="walkin_group_phone" value="<?php echo esc_attr( $saved_phone ); ?>" placeholder="XXX-XXX-XXXX" autocomplete="off" onkeyup="if(window.formatPhoneNumber) window.formatPhoneNumber(this)">
                                </div>
                            </div>

                            <div class="sywat-form-grid-2 sywat-modal-row-spacing">
                                <div class="sywat-field-group">
                                    <label for="walkin_line_index">Default Shooting Line:</label>
                                    <select name="walkin_line_index" id="walkin_line_index" required onchange="updateWalkinFamilyOptions(this.value)">
                                        <?php foreach ( $lines as $idx => $line ) : ?>
                                            <?php $is_c = in_array( (string) $idx, $closed_lines, true ); ?>
                                            <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $saved_line_idx, (string) $idx ); ?><?php echo $is_c ? ' disabled' : ''; ?>>
                                                <?php echo esc_html( $line['name'] ) . ( $is_c ? ' (Closed)' : '' ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="walkin_family_group_id">Link Existing Line Family Group:</label>
                                    <select name="walkin_family_group_id" id="walkin_family_group_id">
                                        <option value="">[ Auto-Create Group for Primary Contact ]</option>
                                        <?php
                                        $fam_found = false;
                                        foreach ( $existing_families as $fid => $flabel ) {
                                            if ( $fid === $saved_fam_id ) {
                                                $fam_found = true;
                                            }
                                            echo '<option value="' . esc_attr( $fid ) . '" ' . selected( $saved_fam_id, $fid, false ) . '>' . esc_html( $flabel ) . '</option>';
                                        }
                                        if ( ! empty( $saved_fam_id ) && ! $fam_found ) {
                                            echo '<option value="' . esc_attr( $saved_fam_id ) . '" selected>' . esc_html( $saved_contact . ' (Family Cap)' ) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="sywat-field-group sywat-modal-row-spacing">
                                <label class="sywat-checkbox-label">
                                    <input type="checkbox" name="walkin_is_courtesy" value="1" class="sywat-checkbox-auto">
                                    Field Governor / Staff Courtesy (Free Registration)
                                </label>
                            </div>
                        </div>

                        <!-- Archer Intake Section -->
                        <div class="sywat-modal-section-header">
                            <h2>🏹 Archer Details</h2>
                        </div>

                        <div class="<?php echo $is_multi_round ? 'sywat-form-grid-2' : 'sywat-field-group'; ?>">
                            <div class="sywat-field-group sywat-field-relative">
                                <label for="walkin_name_input">Archer Full Name *</label>
                                <input type="text" name="walkin_name" id="walkin_name_input" required placeholder="Type archer name..." autocomplete="off" class="sywat-input-bold" oninput="if(window.handleTfaaAutocomplete) window.handleTfaaAutocomplete(this);">
                                <div id="walkin_tfaa_badge"></div>
                                <div id="walkin_tfaa_pills" class="sywat-pill-box"></div>
                                <div class="sywat-tfaa-autocomplete-dropdown sywat-hidden"></div>
                            </div>

                            <?php if ( $is_multi_round ) : ?>
                                <div class="sywat-field-group">
                                    <label for="walkin_pricing_profile">Round Format *</label>
                                    <select name="walkin_pricing_profile" id="walkin_pricing_profile" required onchange="if(window.evaluateTfaaStatus) evaluateTfaaStatus('walkin');">
                                        <?php foreach ( $t_profiles as $p_slug => $p_rec ) : ?>
                                            <?php
                                                $opt_label = $p_rec['label'];
                                                if ( ! empty( $p_rec['round_subtitle'] ) ) {
                                                    $opt_label .= ' (' . $p_rec['round_subtitle'] . ')';
                                                }
                                            ?>
                                            <option value="<?php echo esc_attr( $p_slug ); ?>" <?php selected( $saved_round_slug, $p_slug ); ?>>
                                                <?php echo esc_html( $opt_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="sywat-form-grid-3 sywat-modal-row-spacing">
                            <div class="sywat-field-group">
                                <label for="walkin_division">Division *</label>
                                <select name="walkin_division" id="walkin_division" required onchange="handleStyleRestrictions('walkin'); evaluateTfaaStatus('walkin');">
                                    <option value="" disabled selected hidden>Select Division</option>
                                    <?php foreach ( $active_divs as $d ) : ?>
                                        <?php if ( $d === 'No Show' ) continue; ?>
                                        <option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="walkin_gender">Gender *</label>
                                <select name="walkin_gender" id="walkin_gender" required onchange="evaluateTfaaStatus('walkin')">
                                    <option value="" disabled selected hidden>Select Gender</option>
                                    <?php foreach ( $genders as $g ) : ?>
                                        <?php if ( $g === 'No Show' ) continue; ?>
                                        <option value="<?php echo esc_attr( $g ); ?>"><?php echo esc_html( $g ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="walkin_style">Style *</label>
                                <select name="walkin_style" id="walkin_style" required onchange="if(window.handleStyleChange) handleStyleChange('walkin'); evaluateTfaaStatus('walkin');">
                                    <option value="" disabled selected hidden>Select Style</option>
                                    <?php foreach ( $active_styles as $s ) : ?>
                                        <?php if ( $s === 'No Show' ) continue; ?>
                                        <option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Dynamic Intake Roster & Live Balance Summary Card -->
                        <?php if ( ! empty( $session_archers ) ) : ?>
                            <div class="sywat-calculated-status-box sywat-modal-row-spacing">
                                <div>
                                    <div class="sywat-calculated-status-label">👥 Session Roster Intake:</div>
                                    <div class="sywat-label-subtext">
                                        <?php
                                        $roster_items = array();
                                        foreach ( $session_archers as $idx => $sa ) {
                                            $num            = $idx + 1;
                                            $promo_tag      = ! empty( $sa['is_promo'] ) ? ' <em>(Promo)</em>' : '';
                                            $roster_items[] = '<strong>' . $num . '. ' . esc_html( $sa['name'] ) . '</strong> ($' . number_format( floatval( $sa['fee'] ), 2 ) . ')' . $promo_tag;
                                        }
                                        echo implode( ' &bull; ', $roster_items );
                                        ?>
                                    </div>
                                </div>
                                <div class="sywat-intake-summary">
                                    <div class="sywat-calculated-status-value">Total Due: $<?php echo number_format( $session_total, 2 ); ?></div>
                                    <?php if ( $is_capped ) : ?>
                                        <span class="sywat-family-badge">🟢 Family Cap Applied ($<?php echo esc_html( number_format( $session_cap_amt, 2 ) ); ?> Max)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Action Footer with Dual Save Options -->
                        <div class="sywat-modal-form-actions-split">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeWalkinModal()">Cancel</button>
                            
                            <div class="sywat-modal-form-actions-right">
                                <button type="submit" name="sywat_action" value="add_walkin_next" class="sywat-btn sywat-btn-success sywat-btn-icon-add">Save & Add Next Archer</button>
                                <button type="submit" name="sywat_action" value="add_walkin" class="sywat-btn sywat-btn-primary sywat-btn-icon-checkin">Register & Complete</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
