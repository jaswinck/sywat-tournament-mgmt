<?php
/**
 * Sub-Module: Registration Engine Component & Modal Views
 * Path: includes/registration/registration-modals.php
 * Description: Renders the Phone & Group Intake form with simplified 3-tier Category / Rate selection (Cub 10yd, Youth 20yd, Adult 20yd), dynamic Round Format selection (single or dual-round events such as Field + Field 50), Current Line Slot Usage table with clickable Waitlist badges/buttons, and the dedicated top-level Bulk Spreadsheet Import Modal (#sywat_import_modal).
 * Compliance: Zero output at include time, zero inline styles, delegated data-sywat-action modal triggers, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper: Resolve a human-readable shooting line label from line config array.
 * Prevents "Line 1, Line 2" fallback when lines are stored with day/time keys.
 */
if ( ! function_exists( 'sywat_reg_resolve_line_label' ) ) {
    function sywat_reg_resolve_line_label( $line, $idx ) {
        $label = '';
        if ( is_array( $line ) ) {
            if ( ! empty( $line['name'] ) ) {
                $label = trim( $line['name'] );
            } else {
                $day_str  = isset( $line['day'] ) ? trim( $line['day'] ) : '';
                $time_str = isset( $line['time'] ) ? trim( $line['time'] ) : ( isset( $line['start_time'] ) ? trim( $line['start_time'] ) : '' );
                if ( ! empty( $day_str ) && ! empty( $time_str ) ) {
                    $label = trim( $day_str . ' ' . $time_str );
                } elseif ( ! empty( $day_str ) ) {
                    $label = $day_str;
                } elseif ( ! empty( $time_str ) ) {
                    $label = $time_str;
                }
            }
        } elseif ( is_string( $line ) && trim( $line ) !== '' ) {
            $label = trim( $line );
        }

        if ( $label === '' ) {
            return 'Line ' . ( (int) $idx + 1 );
        }

        // Normalize line times consistently with Manage Tournaments view
        $label = preg_replace( '/\b6(?::00)?\s*AM\b/i', '6PM', $label );
        $label = preg_replace( '/\b6:30\s*AM\b/i', '6:30PM', $label );
        $label = preg_replace( '/(\d{1,2}):00\s*(AM|PM)/i', '$1$2', $label );

        return $label;
    }
}

/**
 * Main Dispatcher: Registration Forms & Line Usage Component Container
 */
if ( ! function_exists( 'sywat_render_registration_modal' ) ) {
    function sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations ) {
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
                    <strong>⚠️ DUPLICATE REGISTRATION BLOCKED:</strong> The following archer(s) were NOT registered because they are already signed up on this line:
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

            <!-- Component 1: Phone & Group Intake Form -->
            <?php sywat_render_phone_intake_form( $selected_tournament, $available_lines ); ?>

            <!-- Component 2: Line Slot Usage Table -->
            <?php sywat_render_line_slot_usage_table( $selected_tournament, $available_lines, $registrations ); ?>

        </div>
        <?php
    }
}

/**
 * Component 1: Phone & Multi-Archer Group Registration Intake Form (3-Tier Category / Rate)
 */
if ( ! function_exists( 'sywat_render_phone_intake_form' ) ) {
    function sywat_render_phone_intake_form( $selected_tournament, $available_lines ) {
        $current_default_line = isset( $_REQUEST['default_line_idx'] )
            ? sanitize_text_field( wp_unslash( $_REQUEST['default_line_idx'] ) )
            : ( isset( $_REQUEST['target_line_idx'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['target_line_idx'] ) ) : 0 );

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
                    'line_idx'        => $current_default_line,
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
        ?>
        <div class="sywat-intake-card">
            <div class="sywat-table-header-bar">
                <h2>📞 Phone & Group Registration Intake</h2>
                <?php if ( ! empty( $format_badges ) ) : ?>
                    <span class="sywat-badge sywat-badge-prereg">Format: <?php echo esc_html( implode( ' / ', $format_badges ) ); ?></span>
                <?php endif; ?>
            </div>
            
            <form method="post" id="sywat_phone_intake_form">
                <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                <input type="hidden" name="sywat_action" value="register_shooter">
                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                <input type="hidden" name="modal_action" value="prereg">

                <div class="sywat-form-grid-4 sywat-intake-group-wrapper">
                    <div class="sywat-field-group">
                        <label for="sywat_group_name">Caller / Primary Archer Name</label>
                        <input type="text" name="group_name" id="sywat_group_name" value="<?php echo esc_attr( $group_name_val ); ?>" placeholder="e.g. John Smith" oninput="handleCallerNameInput(this)">
                    </div>

                    <div class="sywat-field-group">
                        <label for="sywat_group_phone">Contact Phone Number</label>
                        <input type="tel" name="group_phone" id="sywat_group_phone" value="<?php echo esc_attr( $group_phone_val ); ?>" placeholder="e.g. 817-555-1234" oninput="formatPhoneNumber(this)">
                    </div>

                    <div class="sywat-field-group">
                        <label for="sywat_default_line_idx">Default Shooting Line *</label>
                        <select name="default_line_idx" id="sywat_default_line_idx" required onchange="updateAllArcherLines(this.value)">
                            <?php foreach ( $available_lines as $idx => $line ) : ?>
                                <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $current_default_line, (string) $idx ); ?>>
                                    <?php echo esc_html( sywat_reg_resolve_line_label( $line, $idx ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sywat-field-group">
                        <label for="sywat_amount_paid_today">Amount Paid Today ($)</label>
                        <input type="number" step="0.01" min="0" name="amount_paid_today" id="sywat_amount_paid_today" value="<?php echo esc_attr( $amount_paid_val ); ?>" oninput="recalculateGroupTotal()">
                    </div>
                </div>

                <div class="sywat-archer-header-row">
                    <div class="sywat-col-archer-name">Archer Full Name *</div>
                    <?php if ( $is_multi_round ) : ?>
                        <div class="sywat-col-archer-dist">Round Format *</div>
                    <?php endif; ?>
                    <div class="sywat-col-archer-div">Category / Rate *</div>
                    <div class="sywat-col-archer-line">Line Override</div>
                    <div class="sywat-col-archer-action"></div>
                </div>

                <div id="sywat_archer_rows_container" data-multi-round="<?php echo $is_multi_round ? '1' : '0'; ?>" data-default-profile="<?php echo esc_attr( $default_prof_key ); ?>">
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

                            $a_line = isset( $a['line_idx'] ) ? sanitize_text_field( $a['line_idx'] ) : $current_default_line;
                        ?>
                        <div class="sywat-archer-input-row">
                            <div class="sywat-field-group sywat-col-archer-name">
                                <input type="text" name="archers[<?php echo $i; ?>][name]" <?php if ( $i === 0 ) echo 'id="sywat_first_archer_name"'; ?> class="sywat-archer-name-input" placeholder="Archer Full Name *" autocomplete="off" required value="<?php echo esc_attr( $a_name ); ?>" oninput="handleArcherNameInput(this); recalculateGroupTotal();">
                            </div>

                            <?php if ( $is_multi_round ) : ?>
                                <div class="sywat-field-group sywat-col-archer-dist">
                                    <select name="archers[<?php echo $i; ?>][pricing_profile]" class="sywat-round-profile-select" onchange="recalculateGroupTotal()">
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
                                </div>
                            <?php else : ?>
                                <input type="hidden" name="archers[<?php echo $i; ?>][pricing_profile]" class="sywat-round-profile-input" value="<?php echo esc_attr( $default_prof_key ); ?>">
                            <?php endif; ?>

                            <div class="sywat-field-group sywat-col-archer-div">
                                <select name="archers[<?php echo $i; ?>][rate_tier]" class="sywat-rate-tier-select" required onchange="handleRateTierChange(this);">
                                    <option value="" disabled <?php selected( $a_tier, '' ); ?> hidden>Select Category *</option>
                                    <option
                                        value="cub"
                                        data-distance="Cub"
                                        data-rate-tier="Youth"
                                        data-fee="<?php echo esc_attr( number_format( $row_cub, 2, '.', '' ) ); ?>"
                                        <?php selected( $a_tier, 'cub' ); ?>
                                    >
                                        <?php echo esc_html( 'Cub (10yd) [$' . number_format( $row_cub, 2 ) . ']' ); ?>
                                    </option>
                                    <option
                                        value="youth"
                                        data-distance="20yd"
                                        data-rate-tier="Youth"
                                        data-fee="<?php echo esc_attr( number_format( $row_youth, 2, '.', '' ) ); ?>"
                                        <?php selected( $a_tier, 'youth' ); ?>
                                    >
                                        <?php echo esc_html( 'Youth (20yd) [$' . number_format( $row_youth, 2 ) . ']' ); ?>
                                    </option>
                                    <option
                                        value="adult"
                                        data-distance="20yd"
                                        data-rate-tier="Adult"
                                        data-fee="<?php echo esc_attr( number_format( $row_adult, 2, '.', '' ) ); ?>"
                                        <?php selected( $a_tier, 'adult' ); ?>
                                    >
                                        <?php echo esc_html( 'Adult (20yd) [$' . number_format( $row_adult, 2 ) . ']' ); ?>
                                    </option>
                                </select>
                            </div>

                            <div class="sywat-field-group sywat-col-archer-line">
                                <select name="archers[<?php echo $i; ?>][line_idx]" class="sywat-line-override-select" onchange="recalculateGroupTotal()">
                                    <?php foreach ( $available_lines as $idx => $line ) : ?>
                                        <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $a_line, (string) $idx ); ?>>
                                            <?php echo esc_html( sywat_reg_resolve_line_label( $line, $idx ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-col-archer-action">
                                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" onclick="removeArcherRow(this)" title="Remove Archer" aria-label="Remove Archer"></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="sywat-intake-footer-bar">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addArcherRow()">Add Another Archer</button>

                    <div class="sywat-intake-summary">
                        <div class="sywat-intake-total-text">
                            Group Total: <span id="sywat_group_total_disp" class="sywat-intake-total-val">$0.00</span>
                            <span id="sywat_family_cap_badge" class="sywat-family-badge sywat-hidden">🟢 Family Cap Applied</span>
                        </div>
                        <div class="sywat-intake-due-text">
                            Balance Due at Desk: <strong id="sywat_balance_due_disp" class="sywat-intake-due-val">$0.00</strong>
                        </div>
                    </div>

                    <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-checkin">Save & Register Shooters</button>
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
                            <p><strong>Standardized 9-Column Format (One Archer per Row):</strong></p>
                            <ul>
                                <li><strong>Mandatory Columns:</strong> <code>Contact Name</code>, <code>Phone</code>, <code>Archer Name</code>, <code>Line Day</code> (Friday / Saturday / Sunday), <code>Line Time</code> (HH:MM AM/PM), <code>Distance</code> (10 yds / 20 yds), <code>Rate Tier</code> (Youth / Adult), and <code>Paid Status</code> (Paid / Unpaid).</li>
                                <li><strong>Optional Column:</strong> <code>Email</code>.</li>
                                <li><strong>Automatic Rules:</strong> <code>10 yds</code> always locks in the <strong>Youth</strong> rate. Households sharing a <strong>Phone</strong> (or Contact Name) on the same shooting line are grouped automatically to apply the per-line <strong>Family Cap</strong>. <code>Paid</code> sets <strong>$0.00</strong> due at check-in; <code>Unpaid</code> carries the calculated individual or capped Family balance to Check-In.</li>
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

/**
 * Component 2: Line Slot Usage Table with Direct Manage Roster & Waitlist Modal Links
 */
if ( ! function_exists( 'sywat_render_line_slot_usage_table' ) ) {
    function sywat_render_line_slot_usage_table( $selected_tournament, $available_lines, $registrations ) {
        $register_base_url = function_exists( 'sywat_page_url' )
            ? add_query_arg( array( 'selected_tournament' => $selected_tournament ), sywat_page_url( 'register' ) )
            : '';
        ?>
        <div class="sywat-intake-card">
            <div class="sywat-table-header-bar">
                <h2>📊 Current Line Slot Usage</h2>
            </div>
            <div class="sywat-table-responsive">
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th class="sywat-col-line-name">Line Name</th>
                            <th class="sywat-col-cap-20yd sywat-col-center">20yd Capacity</th>
                            <th class="sywat-col-cap-cub sywat-col-center">Cub Capacity</th>
                            <th class="sywat-col-line-action sywat-col-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $available_lines as $idx => $line ) : ?>
                            <?php
                                $line_title          = sywat_reg_resolve_line_label( $line, $idx );
                                $raw_line_name       = is_array( $line ) && ! empty( $line['name'] ) ? trim( (string) $line['name'] ) : '';
                                $c_20                = sywat_count_registered( $selected_tournament, $idx, '20yd', $registrations );
                                $m_20                = sywat_get_line_max_cap( $line, '20yd' );
                                $c_cub               = sywat_count_registered( $selected_tournament, $idx, 'Cub', $registrations );
                                $m_cub               = sywat_get_line_max_cap( $line, 'Cub' );
                                $total_line_shooters = $c_20 + $c_cub;

                                $waitlisted_20  = 0;
                                $waitlisted_cub = 0;

                                foreach ( $registrations as $r ) {
                                    if ( ! isset( $r['tournament'] ) || strcasecmp( trim( $r['tournament'] ), trim( $selected_tournament ) ) !== 0 ) {
                                        continue;
                                    }

                                    $match_idx  = isset( $r['line_index'] ) && (string) $r['line_index'] === (string) $idx;
                                    $match_name = false;
                                    if ( isset( $r['line_name'] ) && is_string( $idx ) && strcasecmp( trim( $r['line_name'] ), trim( $idx ) ) === 0 ) {
                                        $match_name = true;
                                    }
                                    if ( isset( $r['line_name'] ) && $raw_line_name !== '' && strcasecmp( trim( $r['line_name'] ), $raw_line_name ) === 0 ) {
                                        $match_name = true;
                                    }
                                    if ( isset( $r['line_index'] ) && is_string( $r['line_index'] ) && ! is_numeric( $r['line_index'] ) ) {
                                        if ( (string) $r['line_index'] === (string) $idx ) {
                                            $match_name = true;
                                        }
                                    }

                                    if ( $match_idx || $match_name ) {
                                        if ( isset( $r['status'] ) && strcasecmp( trim( $r['status'] ), 'Waitlist' ) === 0 ) {
                                            if ( isset( $r['distance'] ) && strcasecmp( trim( $r['distance'] ), 'Cub' ) === 0 ) {
                                                $waitlisted_cub++;
                                            } else {
                                                $waitlisted_20++;
                                            }
                                        }
                                    }
                                }

                                $total_line_waitlisted = $waitlisted_20 + $waitlisted_cub;

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
                            <tr>
                                <td><strong><?php echo esc_html( $line_title ); ?></strong></td>
                                <td class="sywat-col-center">
                                    <strong><?php echo $c_20; ?></strong> / <?php echo $m_20; ?>
                                    <span class="sywat-badge <?php echo ( $c_20 >= $m_20 ) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ( $c_20 >= $m_20 ) ? 'Full' : 'Open'; ?></span>
                                    <?php if ( $waitlisted_20 > 0 ) : ?>
                                        <div>
                                            <?php if ( function_exists( 'sywat_waitlist_render_badge_link' ) ) : ?>
                                                <?php sywat_waitlist_render_badge_link( $selected_tournament, $waitlisted_20, $idx, $register_base_url ); ?>
                                            <?php else : ?>
                                                <span class="sywat-waitlist-pill">🕒 <?php echo $waitlisted_20; ?> Waitlisted</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-center">
                                    <strong><?php echo $c_cub; ?></strong> / <?php echo $m_cub; ?>
                                    <span class="sywat-badge <?php echo ( $c_cub >= $m_cub ) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>"><?php echo ( $c_cub >= $m_cub ) ? 'Full' : 'Open'; ?></span>
                                    <?php if ( $waitlisted_cub > 0 ) : ?>
                                        <div>
                                            <?php if ( function_exists( 'sywat_waitlist_render_badge_link' ) ) : ?>
                                                <?php sywat_waitlist_render_badge_link( $selected_tournament, $waitlisted_cub, $idx, $register_base_url ); ?>
                                            <?php else : ?>
                                                <span class="sywat-waitlist-pill">🕒 <?php echo $waitlisted_cub; ?> Waitlisted</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="sywat-col-center">
                                    <div class="sywat-table-actions-vertical">
                                        <a href="<?php echo esc_url( $manage_url ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-manage sywat-btn-full">
                                            Manage Roster
                                        </a>
                                        <?php if ( $total_line_waitlisted > 0 && ! empty( $waitlist_modal_url ) ) : ?>
                                            <a href="<?php echo esc_url( $waitlist_modal_url ); ?>" class="sywat-btn sywat-btn-warning sywat-btn-full sywat-waitlist-trigger-btn" title="Open Waitlist Management Window">
                                                <?php echo function_exists( 'sywat_waitlist_clock_svg' ) ? sywat_waitlist_clock_svg() : ''; ?>
                                                <span>Waitlist (<?php echo intval( $total_line_waitlisted ); ?>)</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ( $total_line_shooters > 0 || $total_line_waitlisted > 0 ) : ?>
                                            <form method="post" class="sywat-table-action-form" data-confirm-msg="<?php echo esc_attr( $confirm_msg ); ?>" onsubmit="return confirm(this.dataset.confirmMsg);">
                                                <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                <input type="hidden" name="sywat_action" value="purge_line">
                                                <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                                <input type="hidden" name="redirect_action" value="prereg">
                                                <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete sywat-btn-full">Clear Line</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
