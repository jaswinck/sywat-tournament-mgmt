<?php
/**
 * Sub-Module: Archer Roster Modal Views
 * Path: includes/roster/roster-modals.php
 * Description: Renders the Edit Archer Registration modal with Registration Status (Pre-Reg vs. Waitlist) synchronization, database-driven division/style controls, and line-scoped family group assignment.
 * Author: SYWAT Archery
 * Version: 7.0.0
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
        $open_edit_modal = ! empty( $target_archer );

        $manage_base_url = function_exists( 'sywat_roster_manage_base_url' )
            ? sywat_roster_manage_base_url()
            : ( function_exists( 'sywat_page_url' ) ? sywat_page_url( 'manage_tournaments' ) : get_permalink() );

        $return_to       = isset( $_REQUEST['return_to'] ) ? sanitize_key( wp_unslash( $_REQUEST['return_to'] ) ) : '';
        $filter_line_idx = isset( $_REQUEST['filter_line_idx'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['filter_line_idx'] ) ) : '';

        $edit_action_args = array(
            'tab'             => 'manage',
            'view_tournament' => $selected_tournament,
            'modal_action'    => 'manage',
        );
        if ( $filter_line_idx !== '' ) {
            $edit_action_args['filter_line_idx'] = $filter_line_idx;
        }
        if ( ! empty( $return_to ) ) {
            $edit_action_args['return_to'] = $return_to;
        }

        $edit_form_action = add_query_arg(
            $edit_action_args,
            $manage_base_url
        );

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
        ?>
        <div class="sywat-modal-backdrop sywat-nested-modal <?php echo $open_edit_modal ? 'active' : ''; ?>" id="sywat_edit_archer_modal">
            <div class="sywat-modal-content sywat-modal-md">
                <div class="sywat-modal-header">
                    <div>
                        <h3>✏️ Edit Archer Registration</h3>
                        <div class="sywat-subtitle">Update registration status, division, shooting line, or payment category details.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" aria-label="Close Edit Archer Modal" data-sywat-action="close-edit-archer-modal">&times;</button>
                </div>
                <?php if ( $target_archer ) : ?>
                    <?php
                        $curr_line           = isset( $target_archer['line_index'] ) ? (string) $target_archer['line_index'] : '0';
                        $curr_fam_id         = isset( $target_archer['family_id'] ) ? trim( (string) $target_archer['family_id'] ) : '';
                        $modal_line_families = isset( $existing_families_by_line[ $curr_line ] ) ? $existing_families_by_line[ $curr_line ] : array();
                        $raw_status          = isset( $target_archer['status'] ) ? trim( (string) $target_archer['status'] ) : 'Pre-Reg';
                        $curr_status         = ( strcasecmp( $raw_status, 'Waitlist' ) === 0 )
                            ? 'Waitlist'
                            : ( ( strcasecmp( $raw_status, 'Cancelled' ) === 0 ) ? 'Cancelled' : 'Pre-Reg' );

                        $curr_paid           = isset( $target_archer['paid'] ) ? $target_archer['paid'] : '';
                        $is_family_member    = ( $curr_paid === 'Family' ) || ( $curr_status === 'Waitlist' && $curr_fam_id !== '' );
                        $curr_pay_opt        = $is_family_member ? 'Family' : ( ( $curr_paid === 'Field Governor' ) ? 'Field Governor' : 'Individual' );

                        $raw_curr_div = isset( $target_archer['division'] ) ? trim( (string) $target_archer['division'] ) : '';
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

                        $curr_gen = isset( $target_archer['gender'] ) ? $target_archer['gender'] : 'Male';

                        $raw_curr_sty = isset( $target_archer['style'] ) ? trim( (string) $target_archer['style'] ) : '';
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
                    ?>
                    <form method="post" action="<?php echo esc_url( $edit_form_action ); ?>">
                        <div class="sywat-modal-body">
                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                            <input type="hidden" name="sywat_action" value="save_edit">
                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                            <input type="hidden" name="modal_action" value="manage">
                            <input type="hidden" name="registration_id" value="<?php echo esc_attr( $target_archer['id'] ); ?>">
                            <?php if ( $filter_line_idx !== '' ) : ?>
                                <input type="hidden" name="filter_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
                            <?php endif; ?>
                            <?php if ( ! empty( $return_to ) ) : ?>
                                <input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
                            <?php endif; ?>

                            <div class="sywat-form-grid-2">
                                <div class="sywat-field-group sywat-full-width">
                                    <label for="edit_name">Full Name</label>
                                    <input type="text" id="edit_name" name="edit_name" value="<?php echo esc_attr( $target_archer['name'] ); ?>" required>
                                </div>

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
                                        <?php if ( $curr_status === 'Cancelled' ) : ?>
                                            <option value="Cancelled" selected>Cancelled</option>
                                        <?php endif; ?>
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
                                        <?php if ( $curr_sty === '' ) : ?>
                                            <option value="" selected>-- Select Style --</option>
                                        <?php endif; ?>
                                        <?php foreach ( $archer_style_options as $sty_val => $sty_label ) : ?>
                                            <option value="<?php echo esc_attr( $sty_val ); ?>" <?php selected( $curr_sty, $sty_val ); ?>>
                                                <?php echo esc_html( $sty_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="sywat-field-group">
                                    <label for="edit_pay_option">Paid Option</label>
                                    <select name="edit_pay_option" id="edit_pay_option" required data-sywat-action="roster-edit-pay-change">
                                        <option value="Individual" <?php selected( $curr_pay_opt, 'Individual' ); ?>>Individual Rate ($25 / $30)</option>
                                        <option value="Family" <?php selected( $curr_pay_opt, 'Family' ); ?>>Family Group ($75 Cap for 3+ Archers)</option>
                                        <option value="Field Governor" <?php selected( $curr_pay_opt, 'Field Governor' ); ?>>Field Governor (Free)</option>
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
                                    <input type="number" step="0.01" min="0" id="edit_balance_due" name="edit_balance_due" value="<?php echo esc_attr( isset( $target_archer['balance_due'] ) ? $target_archer['balance_due'] : 0 ); ?>">
                                </div>

                                <div class="sywat-field-group">
                                    <label for="edit_distance_display">Distance (Calculated)</label>
                                    <input type="text" id="edit_distance_display" class="sywat-input-disabled-calc" value="<?php echo esc_attr( $calc_dist ); ?>" disabled>
                                </div>
                            </div>

                            <div class="sywat-calculated-status-box">
                                <span class="sywat-calculated-status-label">Calculated Paid Status:</span>
                                <span id="edit_paid_status_display" class="sywat-calculated-status-value"><?php echo esc_html( $default_paid_label ); ?></span>
                            </div>
                        </div>

                        <div class="sywat-modal-footer">
                            <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-edit-archer-modal">Cancel</button>
                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Changes</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
