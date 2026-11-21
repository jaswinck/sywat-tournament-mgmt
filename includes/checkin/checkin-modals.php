<?php
/**
 * Sub-Module: Check-In Desk Modals
 * Path: includes/checkin/checkin-modals.php
 * Description: Renders Check-In (#sywat_checkin_modal), Edit Archer (#sywat_desk_edit_modal), and Add Walk-In (#walkinModal) modals unconditionally in the DOM so sywat-modal-manager.js portals them on load and assets/js/sywat-checkin.js populates and opens them instantaneously (< 10ms) with zero page reload. Supports live unpaid balance warnings, Rate tier selection, multi-round format selection, First-Time/Lapsed Shooter Promotional Free Entry, database-driven shooting styles & age divisions, retired-record preservation, and household-wide unpaid family balance enforcement.
 * Compliance: Pure view layer — zero DB/option queries, zero inline styles, zero inline JS attributes (data-sywat-action delegation only), Modal Markup Contract (Section V.3), omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.3.0
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
        $modal_archer              = isset( $data['modal_archer'] ) && is_array( $data['modal_archer'] ) ? $data['modal_archer'] : null;
        $modal_edit_archer         = isset( $data['modal_edit_archer'] ) && is_array( $data['modal_edit_archer'] ) ? $data['modal_edit_archer'] : null;
        $selected_tournament       = isset( $data['selected_tournament'] ) ? (string) $data['selected_tournament'] : '';
        $selected_line_idx         = isset( $data['selected_line_idx'] ) ? (string) $data['selected_line_idx'] : 'all';
        $search_query              = isset( $data['search_query'] ) ? (string) $data['search_query'] : '';
        $lines                     = isset( $data['lines'] ) && is_array( $data['lines'] ) ? $data['lines'] : array();
        $closed_lines              = isset( $data['closed_lines'] ) && is_array( $data['closed_lines'] ) ? $data['closed_lines'] : array();
        $divs                      = isset( $data['divs'] ) && is_array( $data['divs'] ) ? $data['divs'] : array();
        $active_divs               = isset( $data['active_divs'] ) && is_array( $data['active_divs'] )
            ? $data['active_divs']
            : array_values( array_filter( $divs, function( $d ) { return $d !== 'No Show'; } ) );
        $genders                   = isset( $data['genders'] ) && is_array( $data['genders'] ) ? $data['genders'] : array( 'Male', 'Female', 'No Show' );
        $sywat_styles              = isset( $data['sywat_styles'] ) && is_array( $data['sywat_styles'] ) ? $data['sywat_styles'] : array();
        $active_styles             = isset( $data['active_styles'] ) && is_array( $data['active_styles'] )
            ? $data['active_styles']
            : array_values( array_filter( $sywat_styles, function( $s ) { return $s !== 'No Show'; } ) );
        $existing_families         = isset( $data['existing_families'] ) && is_array( $data['existing_families'] ) ? $data['existing_families'] : array();
        $existing_families_by_line = isset( $data['existing_families_by_line'] ) && is_array( $data['existing_families_by_line'] ) ? $data['existing_families_by_line'] : array();

        // Active Tournament Pricing & Round Profiles (pre-resolved in checkin-data.php & checkin-helpers.php)
        $t_profiles         = isset( $data['pricing_profiles'] ) && is_array( $data['pricing_profiles'] ) ? $data['pricing_profiles'] : array();
        $all_round_profiles = isset( $data['modal_round_profiles'] ) && is_array( $data['modal_round_profiles'] ) && ! empty( $data['modal_round_profiles'] )
            ? $data['modal_round_profiles']
            : $t_profiles;
        $is_multi_round     = ! empty( $data['is_multi_round'] ) || count( $t_profiles ) > 1;
        $default_prof_key   = ! empty( $data['default_pricing_profile'] )
            ? (string) $data['default_pricing_profile']
            : ( ! empty( $t_profiles ) ? (string) key( $t_profiles ) : 'indoor' );
        $default_prof       = isset( $t_profiles[ $default_prof_key ] )
            ? $t_profiles[ $default_prof_key ]
            : ( ! empty( $t_profiles ) ? reset( $t_profiles ) : array() );

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

        // Fast-Loop Context Persistence for Walk-In Modal
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
        $saved_fam_id    = isset( $walkin_context['family_id'] ) ? $walkin_context['family_id'] : '';
        $last_added_name = isset( $walkin_context['last_added_name'] ) ? $walkin_context['last_added_name'] : '';
        $session_archers = isset( $walkin_context['session_archers'] ) ? $walkin_context['session_archers'] : array();
        $session_total   = isset( $walkin_context['session_total'] ) ? floatval( $walkin_context['session_total'] ) : 0.00;
        $is_capped       = ! empty( $walkin_context['is_capped'] );
        $session_cap_amt = isset( $walkin_context['cap_amount'] ) && floatval( $walkin_context['cap_amount'] ) > 0
            ? floatval( $walkin_context['cap_amount'] )
            : $active_cap_amount;

        // Pre-populate Check-In Modal initial values (empty when idle; populated if server-fallback POST occurred)
        $checkin_payload   = ( $modal_archer && isset( $modal_archer['modal_payload'] ) && is_array( $modal_archer['modal_payload'] ) ) ? $modal_archer['modal_payload'] : array();
        $checkin_id        = isset( $checkin_payload['id'] ) ? (string) $checkin_payload['id'] : ( $modal_archer && isset( $modal_archer['id'] ) ? (string) $modal_archer['id'] : '' );
        $checkin_name      = isset( $checkin_payload['name'] ) ? (string) $checkin_payload['name'] : ( $modal_archer && isset( $modal_archer['name'] ) ? (string) $modal_archer['name'] : '' );
        $checkin_round     = ! empty( $checkin_payload['pricing_profile'] ) ? (string) $checkin_payload['pricing_profile'] : $default_prof_key;
        $curr_div          = isset( $checkin_payload['division'] ) ? (string) $checkin_payload['division'] : '';
        $curr_gen          = ( isset( $checkin_payload['gender'] ) && in_array( $checkin_payload['gender'], $genders, true ) ) ? (string) $checkin_payload['gender'] : '';
        $curr_sty          = isset( $checkin_payload['style'] ) ? (string) $checkin_payload['style'] : '';
        $curr_pay_opt      = isset( $checkin_payload['pay_option'] ) ? (string) $checkin_payload['pay_option'] : 'Individual';
        $curr_fam_id       = isset( $checkin_payload['family_id'] ) ? (string) $checkin_payload['family_id'] : '';
        $effective_due     = isset( $checkin_payload['effective_due'] ) ? floatval( $checkin_payload['effective_due'] ) : 0.00;
        $display_due       = isset( $checkin_payload['display_due'] ) ? floatval( $checkin_payload['display_due'] ) : 0.00;
        $is_family_balance = ! empty( $checkin_payload['is_family_balance'] );
        $checkin_line_idx  = isset( $checkin_payload['line_index'] ) ? (string) $checkin_payload['line_index'] : '0';
        $checkin_families  = isset( $existing_families_by_line[ $checkin_line_idx ] ) ? $existing_families_by_line[ $checkin_line_idx ] : $existing_families;

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
        ?>
        <!-- CHECK-IN MODAL (PRE-REG — UNCONDITIONAL DOM FOR ZERO-RELOAD CLIENT OPEN) -->
        <div id="sywat_checkin_modal" class="sywat-modal-backdrop<?php echo $modal_archer ? ' active' : ''; ?>">
            <div class="sywat-modal-content sywat-modal-sm">
                <div class="sywat-modal-header no-print">
                    <h3>Check In Archer</h3>
                    <button type="button" class="sywat-modal-close" aria-label="Close modal" data-sywat-action="close-desk-checkin-modal">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <form method="post" id="sywat_desk_checkin_form">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="selected_line_idx" class="sywat-desk-hidden-line" value="<?php echo esc_attr( $selected_line_idx ); ?>">
                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $search_query ); ?>">
                        <input type="hidden" name="reg_id" id="checkin_reg_id" value="<?php echo esc_attr( $checkin_id ); ?>">

                        <?php if ( ! $is_multi_round && count( $all_round_profiles ) <= 1 ) : ?>
                            <input type="hidden" name="checkin_pricing_profile" id="checkin_pricing_profile" value="<?php echo esc_attr( $checkin_round ); ?>">
                        <?php endif; ?>

                        <div class="sywat-field-group sywat-field-relative">
                            <label for="checkin_archer_name">Name:</label>
                            <input type="text" name="checkin_name" id="checkin_archer_name" value="<?php echo esc_attr( $checkin_name ); ?>" autocomplete="off" class="sywat-input-bold">
                            <div id="checkin_tfaa_badge"></div>
                            <div id="checkin_tfaa_pills" class="sywat-pill-box"></div>
                        </div>

                        <?php if ( $is_multi_round || count( $all_round_profiles ) > 1 ) : ?>
                            <div class="sywat-field-group">
                                <label for="checkin_pricing_profile">Round Format:</label>
                                <select name="checkin_pricing_profile" id="checkin_pricing_profile" required data-sywat-action="sync-checkin-balance">
                                    <?php foreach ( $all_round_profiles as $r_slug => $r_rec ) : ?>
                                        <?php
                                            $r_label = isset( $r_rec['label'] ) ? (string) $r_rec['label'] : (string) $r_slug;
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

                        <div id="sywat_unpaid_warning_banner"
                             class="sywat-alert sywat-alert-danger sywat-alert-center<?php echo ( $effective_due > 0 ) ? '' : ' sywat-hidden'; ?>"
                             data-orig-due="<?php echo esc_attr( number_format( $display_due, 2, '.', '' ) ); ?>"
                             data-is-family-balance="<?php echo $is_family_balance ? '1' : '0'; ?>">
                            <div class="sywat-alert-title" id="checkin_unpaid_title_disp">
                                ⚠️ <?php echo $is_family_balance ? 'UNPAID FAMILY BALANCE' : 'UNPAID BALANCE'; ?>: $<span id="checkin_unpaid_amount_disp"><?php echo number_format( $display_due, 2 ); ?></span>
                            </div>
                            <div class="sywat-alert-subtext" id="checkin_unpaid_subtext_disp">
                                <?php if ( $is_family_balance ) : ?>
                                    Family Cap Group Outstanding Balance. Collect Payment Onsite.
                                <?php else : ?>
                                    Please See Event Staff for Payment
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="sywat-field-group<?php echo ( $effective_due > 0 ) ? '' : ' sywat-hidden'; ?>" id="sywat_payment_verified_check_group">
                            <label class="sywat-checkbox-label-danger" for="sywat_payment_verified_check">
                                <input type="checkbox" name="checkin_payment_verified" id="sywat_payment_verified_check" value="1"<?php echo ( $effective_due > 0 ) ? ' required' : ''; ?> class="sywat-checkbox-auto" data-sywat-action="toggle-unpaid-banner">
                                Payment / Resolution Verified with Event Staff
                            </label>
                        </div>

                        <div class="sywat-field-group">
                            <label for="checkin_division">Division:</label>
                            <select name="checkin_division" id="checkin_division" required data-sywat-action="checkin-division-change">
                                <option value="" disabled <?php echo empty( $curr_div ) ? 'selected' : ''; ?> hidden>Select Division</option>
                                <?php foreach ( $checkin_div_options as $d_val => $d_label ) : ?>
                                    <option value="<?php echo esc_attr( $d_val ); ?>" <?php selected( $curr_div, $d_val ); ?>><?php echo esc_html( $d_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="checkin_gender">Gender:</label>
                            <select name="checkin_gender" id="checkin_gender" required data-sywat-action="checkin-gender-change">
                                <option value="" disabled <?php echo empty( $curr_gen ) ? 'selected' : ''; ?> hidden>Select Gender</option>
                                <?php foreach ( $genders as $g ) : ?>
                                    <?php if ( $g === 'No Show' && $curr_gen !== 'No Show' ) : ?>
                                        <?php continue; ?>
                                    <?php endif; ?>
                                    <option value="<?php echo esc_attr( $g ); ?>" <?php selected( $curr_gen, $g ); ?>><?php echo esc_html( $g ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="checkin_style">Style:</label>
                            <select name="checkin_style" id="checkin_style" required data-sywat-action="checkin-style-change">
                                <option value="" disabled <?php echo empty( $curr_sty ) ? 'selected' : ''; ?> hidden>Select Style</option>
                                <?php foreach ( $checkin_style_options as $s_val => $s_label ) : ?>
                                    <option value="<?php echo esc_attr( $s_val ); ?>" <?php selected( $curr_sty, $s_val ); ?>><?php echo esc_html( $s_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="checkin_pay_option">Rate:</label>
                            <select name="checkin_pay_option" id="checkin_pay_option" required data-sywat-action="checkin-pay-change">
                                <option value="Individual" <?php selected( $curr_pay_opt, 'Individual' ); ?>>Individual</option>
                                <option value="Family" <?php selected( $curr_pay_opt, 'Family' ); ?>>Family</option>
                                <option value="Field Governor" <?php selected( $curr_pay_opt, 'Field Governor' ); ?>>Field Governor</option>
                                <option value="Promo (Free)" <?php selected( $curr_pay_opt, 'Promo (Free)' ); ?>>Promotional Free Entry ($0.00)</option>
                            </select>
                        </div>

                        <div class="sywat-field-group <?php echo ( $curr_pay_opt === 'Family' ) ? '' : 'sywat-hidden'; ?>" id="checkin_family_box">
                            <label for="checkin_family_group_id">Line Family Group:</label>
                            <select name="checkin_family_group_id" id="checkin_family_group_id">
                                <option value="">[ + Start New Family Group for This Line ]</option>
                                <?php foreach ( $checkin_families as $fid => $flabel ) : ?>
                                    <option value="<?php echo esc_attr( $fid ); ?>" <?php selected( $curr_fam_id, $fid ); ?>><?php echo esc_html( $flabel ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-modal-form-actions">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" data-sywat-action="close-desk-checkin-modal">Cancel</button>
                            <button type="submit" name="sywat_action" value="save_checkin" class="sywat-btn sywat-btn-success sywat-btn-icon-checkin">Complete Check-In</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php
        // Pre-populate Edit Modal initial values (empty when idle; populated if server-fallback POST occurred)
        $edit_payload       = ( $modal_edit_archer && isset( $modal_edit_archer['modal_payload'] ) && is_array( $modal_edit_archer['modal_payload'] ) ) ? $modal_edit_archer['modal_payload'] : array();
        $edit_id            = isset( $edit_payload['id'] ) ? (string) $edit_payload['id'] : ( $modal_edit_archer && isset( $modal_edit_archer['id'] ) ? (string) $modal_edit_archer['id'] : '' );
        $edit_name          = isset( $edit_payload['name'] ) ? (string) $edit_payload['name'] : ( $modal_edit_archer && isset( $modal_edit_archer['name'] ) ? (string) $modal_edit_archer['name'] : '' );
        $edit_round         = ! empty( $edit_payload['pricing_profile'] ) ? (string) $edit_payload['pricing_profile'] : $default_prof_key;
        $curr_line          = isset( $edit_payload['line_index'] ) ? (string) $edit_payload['line_index'] : '';
        $curr_edit_div      = isset( $edit_payload['division'] ) ? (string) $edit_payload['division'] : '';
        $curr_edit_gen      = ( isset( $edit_payload['gender'] ) && in_array( $edit_payload['gender'], $genders, true ) ) ? (string) $edit_payload['gender'] : '';
        $curr_edit_sty      = isset( $edit_payload['style'] ) ? (string) $edit_payload['style'] : '';
        $curr_edit_pay_opt  = isset( $edit_payload['pay_option'] ) ? (string) $edit_payload['pay_option'] : 'Individual';
        $curr_edit_fam_id   = isset( $edit_payload['family_id'] ) ? (string) $edit_payload['family_id'] : '';
        $edit_orig_due      = isset( $edit_payload['balance_due'] ) ? floatval( $edit_payload['balance_due'] ) : 0.00;
        $edit_families      = ( $curr_line !== '' && isset( $existing_families_by_line[ $curr_line ] ) ) ? $existing_families_by_line[ $curr_line ] : $existing_families;

        $edit_div_options = array();
        foreach ( $active_divs as $d ) {
            if ( $d === 'No Show' ) {
                continue;
            }
            $edit_div_options[ $d ] = $d;
        }
        if ( $curr_edit_div !== '' && $curr_edit_div !== 'No Show' && ! isset( $edit_div_options[ $curr_edit_div ] ) ) {
            $edit_div_options[ $curr_edit_div ] = $curr_edit_div . ' (Retired)';
        }
        if ( $curr_edit_div === 'No Show' ) {
            $edit_div_options['No Show'] = 'No Show';
        }

        $edit_style_options = array();
        foreach ( $active_styles as $s ) {
            if ( $s === 'No Show' ) {
                continue;
            }
            $edit_style_options[ $s ] = $s;
        }
        if ( $curr_edit_sty !== '' && $curr_edit_sty !== 'No Show' && ! isset( $edit_style_options[ $curr_edit_sty ] ) ) {
            $edit_style_options[ $curr_edit_sty ] = $curr_edit_sty . ' (Retired)';
        }
        if ( $curr_edit_sty === 'No Show' ) {
            $edit_style_options['No Show'] = 'No Show';
        }
        ?>
        <!-- EDIT ARCHER MODAL (UNCONDITIONAL DOM FOR ZERO-RELOAD CLIENT OPEN) -->
        <div id="sywat_desk_edit_modal" class="sywat-modal-backdrop<?php echo $modal_edit_archer ? ' active' : ''; ?>">
            <div class="sywat-modal-content sywat-modal-sm">
                <div class="sywat-modal-header no-print">
                    <h3>Edit Archer Registration</h3>
                    <button type="button" class="sywat-modal-close" aria-label="Close modal" data-sywat-action="close-desk-edit-modal">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <form method="post" id="sywat_desk_edit_form">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="selected_line_idx" class="sywat-desk-hidden-line" value="<?php echo esc_attr( $selected_line_idx ); ?>">
                        <input type="hidden" name="search_query" class="sywat-hidden-search" value="<?php echo esc_attr( $search_query ); ?>">
                        <input type="hidden" name="reg_id" id="edit_reg_id" value="<?php echo esc_attr( $edit_id ); ?>">

                        <?php if ( ! $is_multi_round && count( $all_round_profiles ) <= 1 ) : ?>
                            <input type="hidden" name="edit_pricing_profile" id="edit_pricing_profile" value="<?php echo esc_attr( $edit_round ); ?>">
                        <?php endif; ?>

                        <div class="sywat-field-group sywat-field-relative">
                            <label for="edit_archer_name">Full Name:</label>
                            <input type="text" name="edit_name" id="edit_archer_name" value="<?php echo esc_attr( $edit_name ); ?>" autocomplete="off" required>
                        </div>

                        <?php if ( $is_multi_round || count( $all_round_profiles ) > 1 ) : ?>
                            <div class="sywat-field-group">
                                <label for="edit_pricing_profile">Round Format:</label>
                                <select name="edit_pricing_profile" id="edit_pricing_profile" required data-sywat-action="sync-edit-balance">
                                    <?php foreach ( $all_round_profiles as $r_slug => $r_rec ) : ?>
                                        <?php
                                            $r_label = isset( $r_rec['label'] ) ? (string) $r_rec['label'] : (string) $r_slug;
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
                            <label for="edit_line_index">Shooting Line:</label>
                            <select name="edit_line_index" id="edit_line_index" required data-sywat-action="edit-line-change">
                                <?php foreach ( $lines as $idx => $line ) : ?>
                                    <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $curr_line, (string) $idx ); ?>>
                                        <?php echo esc_html( $line['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="edit_division">Division:</label>
                            <select name="edit_division" id="edit_division" required data-sywat-action="edit-division-change">
                                <option value="" disabled <?php echo empty( $curr_edit_div ) ? 'selected' : ''; ?> hidden>Select Division</option>
                                <?php foreach ( $edit_div_options as $d_val => $d_label ) : ?>
                                    <option value="<?php echo esc_attr( $d_val ); ?>" <?php selected( $curr_edit_div, $d_val ); ?>><?php echo esc_html( $d_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="edit_gender">Gender:</label>
                            <select name="edit_gender" id="edit_gender" required>
                                <option value="" disabled <?php echo empty( $curr_edit_gen ) ? 'selected' : ''; ?> hidden>Select Gender</option>
                                <?php foreach ( $genders as $g ) : ?>
                                    <?php if ( $g === 'No Show' && $curr_edit_gen !== 'No Show' ) : ?>
                                        <?php continue; ?>
                                    <?php endif; ?>
                                    <option value="<?php echo esc_attr( $g ); ?>" <?php selected( $curr_edit_gen, $g ); ?>><?php echo esc_html( $g ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="edit_style">Style:</label>
                            <select name="edit_style" id="edit_style" required data-sywat-action="edit-style-change">
                                <option value="" disabled <?php echo empty( $curr_edit_sty ) ? 'selected' : ''; ?> hidden>Select Style</option>
                                <?php foreach ( $edit_style_options as $s_val => $s_label ) : ?>
                                    <option value="<?php echo esc_attr( $s_val ); ?>" <?php selected( $curr_edit_sty, $s_val ); ?>><?php echo esc_html( $s_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="edit_pay_option">Rate:</label>
                            <select name="edit_pay_option" id="edit_pay_option" required data-sywat-action="edit-pay-change">
                                <option value="Individual" <?php selected( $curr_edit_pay_opt, 'Individual' ); ?>>Individual</option>
                                <option value="Family" <?php selected( $curr_edit_pay_opt, 'Family' ); ?>>Family</option>
                                <option value="Field Governor" <?php selected( $curr_edit_pay_opt, 'Field Governor' ); ?>>Field Governor</option>
                                <option value="Promo (Free)" <?php selected( $curr_edit_pay_opt, 'Promo (Free)' ); ?>>Promotional Free Entry ($0.00)</option>
                            </select>
                        </div>

                        <div class="sywat-field-group <?php echo ( $curr_edit_pay_opt === 'Family' ) ? '' : 'sywat-hidden'; ?>" id="edit_family_box">
                            <label for="edit_family_group_id">Line Family Group:</label>
                            <select name="edit_family_group_id" id="edit_family_group_id">
                                <option value="">[ + Start New Family Group for This Line ]</option>
                                <?php foreach ( $edit_families as $fid => $flabel ) : ?>
                                    <option value="<?php echo esc_attr( $fid ); ?>" <?php selected( $curr_edit_fam_id, $fid ); ?>><?php echo esc_html( $flabel ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sywat-field-group">
                            <label for="edit_balance_due">Balance Due ($):</label>
                            <input type="number" step="0.01" min="0" id="edit_balance_due" name="edit_balance_due" data-orig-due="<?php echo esc_attr( number_format( $edit_orig_due, 2, '.', '' ) ); ?>" value="<?php echo esc_attr( number_format( $edit_orig_due, 2, '.', '' ) ); ?>">
                        </div>

                        <div class="sywat-modal-form-actions-split">
                            <button type="submit" name="sywat_action" value="delete_archer" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" data-sywat-action="confirm-delete-archer" formnovalidate>Delete</button>
                            
                            <div class="sywat-modal-form-actions-right">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" data-sywat-action="close-desk-edit-modal">Cancel</button>
                                <button type="submit" name="sywat_action" value="save_edit" class="sywat-btn sywat-btn-primary sywat-btn-icon-save">Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ADD WALK-IN MODAL (AUTOMATED FEE, PROMO & FAMILY CAP ENGINE) -->
        <div id="walkinModal" class="sywat-modal-backdrop <?php echo $is_walkin_active ? 'active' : ''; ?>">
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>📋 Add Walk-In Registration</h3>
                        <div class="sywat-subtitle">Register walk-in archers, assign shooting lines, and calculate fees on-site.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" aria-label="Close modal" data-sywat-action="close-desk-walkin-modal">&times;</button>
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
                        <input type="hidden" name="selected_line_idx" class="sywat-desk-hidden-line" value="<?php echo esc_attr( $selected_line_idx ); ?>">
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
                                    <input type="text" name="walkin_group_name" id="walkin_group_name" value="<?php echo esc_attr( $saved_contact ); ?>" placeholder="e.g. John Smith" autocomplete="off">
                                    <div class="sywat-tfaa-autocomplete-dropdown sywat-hidden"></div>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="walkin_group_phone">Phone Number:</label>
                                    <input type="text" name="walkin_group_phone" id="walkin_group_phone" value="<?php echo esc_attr( $saved_phone ); ?>" placeholder="XXX-XXX-XXXX" autocomplete="off" data-sywat-action="format-desk-phone">
                                </div>
                            </div>

                            <div class="sywat-form-grid-2 sywat-modal-row-spacing">
                                <div class="sywat-field-group">
                                    <label for="walkin_line_index">Default Shooting Line:</label>
                                    <select name="walkin_line_index" id="walkin_line_index" required data-sywat-action="walkin-line-change">
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
                                <input type="text" name="walkin_name" id="walkin_name_input" required placeholder="Type archer name..." autocomplete="off" class="sywat-input-bold">
                                <div id="walkin_tfaa_badge"></div>
                                <div id="walkin_tfaa_pills" class="sywat-pill-box"></div>
                                <div class="sywat-tfaa-autocomplete-dropdown sywat-hidden"></div>
                            </div>

                            <?php if ( $is_multi_round ) : ?>
                                <div class="sywat-field-group">
                                    <label for="walkin_pricing_profile">Round Format *</label>
                                    <select name="walkin_pricing_profile" id="walkin_pricing_profile" required data-sywat-action="walkin-round-change">
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
                                <select name="walkin_division" id="walkin_division" required data-sywat-action="walkin-division-change">
                                    <option value="" disabled selected hidden>Select Division</option>
                                    <?php foreach ( $active_divs as $d ) : ?>
                                        <?php if ( $d === 'No Show' ) continue; ?>
                                        <option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="walkin_gender">Gender *</label>
                                <select name="walkin_gender" id="walkin_gender" required data-sywat-action="walkin-gender-change">
                                    <option value="" disabled selected hidden>Select Gender</option>
                                    <?php foreach ( $genders as $g ) : ?>
                                        <?php if ( $g === 'No Show' ) continue; ?>
                                        <option value="<?php echo esc_attr( $g ); ?>"><?php echo esc_html( $g ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group">
                                <label for="walkin_style">Style *</label>
                                <select name="walkin_style" id="walkin_style" required data-sywat-action="walkin-style-change">
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
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" data-sywat-action="close-desk-walkin-modal">Cancel</button>
                            
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
