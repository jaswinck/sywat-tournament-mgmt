<?php
/**
 * View Template: Admin Edit Location (Tabbed Layout)
 * Path: includes/shops/views/view-shop-edit.php
 * Author: SYWAT Archery / TFAA
 * Version: 6.8.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
$fallback_list_url  = $can_manage_seasons ? add_query_arg( 'type', $type_filter, $page_url ) : sywat_page_url( 'shops_public' );

$curr_fac       = isset( $editing_shop['facilities'] ) && is_array( $editing_shop['facilities'] ) ? $editing_shop['facilities'] : array();
$curr_dist      = isset( $editing_shop['district'] ) ? $editing_shop['district'] : '';
$curr_type      = isset( $editing_shop['location_type'] ) ? $editing_shop['location_type'] : 'pro_shop';
$add_contacts   = isset( $editing_shop['additional_contacts'] ) && is_array( $editing_shop['additional_contacts'] ) ? $editing_shop['additional_contacts'] : array();

$raw_state = isset( $editing_shop['state'] ) ? trim( (string) $editing_shop['state'] ) : '';
$curr_st   = ( $raw_state !== '' && function_exists( 'sywat_normalize_state_code' ) ) ? sywat_normalize_state_code( $raw_state ) : $raw_state;

$state_options = array(
    'TX - Texas'      => 'TX - Texas',
    'AR - Arkansas'   => 'AR - Arkansas',
    'LA - Louisiana'  => 'LA - Louisiana',
    'NM - New Mexico' => 'NM - New Mexico',
    'OK - Oklahoma'   => 'OK - Oklahoma',
);

$has_indoor     = in_array( 'indoor_range', $curr_fac, true );
$has_outdoor    = in_array( 'outdoor_field', $curr_fac, true );
$active_sub_tab = isset( $_POST['active_tab'] ) ? sanitize_text_field( wp_unslash( $_POST['active_tab'] ) ) : 'profile';
?>

<div class="sywat-card no-print">
    <!-- UNIFIED TOP CARD HEADER BAR: TITLE (LEFT) & BACK BUTTON (RIGHT) -->
    <div class="sywat-card-header-bar">
        <div>
            <h2>🎯 Edit Location: <?php echo esc_html( $editing_shop['name'] ); ?></h2>
        </div>
        <div>
            <a href="<?php echo esc_url( $fallback_list_url ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back sywat-back-to-list-link">Back to List</a>
        </div>
    </div>

    <!-- Sub-Tabs Navigation -->
    <div class="sywat-nav-tabs sywat-mb-4">
        <button type="button" class="sywat-tab-item <?php echo ( $active_sub_tab === 'profile' ) ? 'active' : ''; ?>" id="sywat_edit_tab_btn_profile" data-sywat-tab="profile">📍 Profile &amp; Address</button>
        <button type="button" class="sywat-tab-item <?php echo ( $active_sub_tab === 'contacts' ) ? 'active' : ''; ?>" id="sywat_edit_tab_btn_contacts" data-sywat-tab="contacts">👥 Contacts</button>
        <button type="button" class="sywat-tab-item <?php echo ( $active_sub_tab === 'indoor_lines' ) ? 'active' : ''; ?> <?php echo $has_indoor ? '' : 'sywat-hidden'; ?>" id="sywat_edit_tab_btn_indoor_lines" data-sywat-tab="indoor_lines">🎯 SYWAT Indoor Lines</button>
        <button type="button" class="sywat-tab-item <?php echo ( $active_sub_tab === 'outdoor_lines' ) ? 'active' : ''; ?> <?php echo $has_outdoor ? '' : 'sywat-hidden'; ?>" id="sywat_edit_tab_btn_outdoor_lines" data-sywat-tab="outdoor_lines">🌲 SYWAT Outdoor Lines</button>
    </div>

    <form method="post" id="sywat_edit_shop_form" action="<?php echo esc_url( add_query_arg( array( 'edit_shop' => $editing_shop['id'], 'type' => $type_filter ), $page_url ) ); ?>">
        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
        <input type="hidden" name="sywat_shop_action" value="update_shop">
        <input type="hidden" name="shop_id" value="<?php echo esc_attr( $editing_shop['id'] ); ?>">
        <input type="hidden" name="active_tab" id="sywat_active_tab_input" value="<?php echo esc_attr( $active_sub_tab ); ?>">

        <!-- TAB 1: PROFILE & ADDRESS -->
        <div id="sywat_edit_tab_profile" class="sywat-edit-tab-content <?php echo ( $active_sub_tab === 'profile' ) ? '' : 'sywat-hidden'; ?>">
            <div class="sywat-form-grid-3">
                <div class="sywat-field-group">
                    <label for="sywat_edit_shop_name">Location Name *</label>
                    <input type="text" id="sywat_edit_shop_name" name="shop_name" value="<?php echo esc_attr( $editing_shop['name'] ); ?>" required>
                </div>
                <div class="sywat-field-group">
                    <label for="sywat_edit_location_type">Location Type *</label>
                    <select id="sywat_edit_location_type" name="location_type" required>
                        <option value="pro_shop" <?php selected( $curr_type, 'pro_shop' ); ?>>🎯 Shop</option>
                        <option value="club" <?php selected( $curr_type, 'club' ); ?>>🏹 Club</option>
                    </select>
                </div>
                <div class="sywat-field-group">
                    <label for="sywat_edit_shop_district">District</label>
                    <select id="sywat_edit_shop_district" name="shop_district">
                        <option value="" <?php selected( empty( $curr_dist ), true ); ?>>-- Unassigned --</option>
                        <?php foreach ( $districts as $key => $label ): ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $curr_dist, $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Facilities & Amenities Multi-Select -->
            <div class="sywat-field-group sywat-mt-3 sywat-mb-4">
                <label>Facilities &amp; Range Amenities</label>
                <div class="sywat-form-grid-4">
                    <label>
                        <input type="checkbox" name="facilities[]" value="pro_shop" <?php checked( in_array( 'pro_shop', $curr_fac, true ), true ); ?>> 🏪 Pro Shop &amp; Retail
                    </label>
                    <label>
                        <input type="checkbox" name="facilities[]" value="indoor_range" <?php checked( in_array( 'indoor_range', $curr_fac, true ), true ); ?>> 🎯 Indoor Target Range
                    </label>
                    <label>
                        <input type="checkbox" name="facilities[]" value="outdoor_field" <?php checked( in_array( 'outdoor_field', $curr_fac, true ), true ); ?>> 🌲 Outdoor Field Course
                    </label>
                    <label>
                        <input type="checkbox" name="facilities[]" value="3d_range" <?php checked( in_array( '3d_range', $curr_fac, true ), true ); ?>> 🦌 3D Animal Range
                    </label>
                </div>
            </div>

            <div class="sywat-subtitle sywat-mt-4 sywat-mb-4">Location Address Details (Optional)</div>
            <div class="sywat-form-grid-2">
                <div class="sywat-field-group">
                    <label for="sywat_edit_address_1">Address Line 1</label>
                    <input type="text" id="sywat_edit_address_1" name="shop_address_1" value="<?php echo esc_attr( isset( $editing_shop['address_1'] ) ? $editing_shop['address_1'] : '' ); ?>" placeholder="e.g. 13795 Old Denton Rd">
                </div>
                <div class="sywat-field-group">
                    <label for="sywat_edit_address_2">Address Line 2</label>
                    <input type="text" id="sywat_edit_address_2" name="shop_address_2" value="<?php echo esc_attr( isset( $editing_shop['address_2'] ) ? $editing_shop['address_2'] : '' ); ?>" placeholder="Suite, Bldg, etc.">
                </div>
            </div>
            <div class="sywat-form-grid-2 sywat-mt-3">
                <div class="sywat-field-group">
                    <label for="sywat_edit_city">City</label>
                    <input type="text" id="sywat_edit_city" name="shop_city" value="<?php echo esc_attr( isset( $editing_shop['city'] ) ? $editing_shop['city'] : '' ); ?>" placeholder="e.g. Roanoke">
                </div>
                <div class="sywat-form-grid-2">
                    <div class="sywat-field-group">
                        <label for="sywat_edit_state">State</label>
                        <select id="sywat_edit_state" name="shop_state">
                            <option value="" <?php selected( empty( $curr_st ), true ); ?>>-- Select State --</option>
                            <?php foreach ( $state_options as $st_val => $st_label ): ?>
                                <option value="<?php echo esc_attr( $st_val ); ?>" <?php selected( $curr_st, $st_val ); ?>><?php echo esc_html( $st_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sywat-field-group">
                        <label for="sywat_edit_zip">ZIP Code</label>
                        <input type="text" id="sywat_edit_zip" name="shop_zip" value="<?php echo esc_attr( isset( $editing_shop['zip'] ) ? $editing_shop['zip'] : '' ); ?>" placeholder="e.g. 76262">
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: CONTACTS -->
        <div id="sywat_edit_tab_contacts" class="sywat-edit-tab-content <?php echo ( $active_sub_tab === 'contacts' ) ? '' : 'sywat-hidden'; ?>">
            <!-- Primary Contact Section -->
            <div class="sywat-field-group sywat-mb-4">
                <label>👤 Primary Contact Person</label>
                <div class="sywat-form-grid-4">
                    <div class="sywat-field-group">
                        <label for="sywat_edit_contact_name">Primary Contact Name</label>
                        <input type="text" id="sywat_edit_contact_name" name="shop_contact" value="<?php echo esc_attr( isset( $editing_shop['contact'] ) ? $editing_shop['contact'] : '' ); ?>" placeholder="e.g. Beau Brennan">
                    </div>
                    <div class="sywat-field-group">
                        <label for="sywat_edit_contact_title">Primary Title / Role</label>
                        <input type="text" id="sywat_edit_contact_title" name="shop_contact_title" value="<?php echo esc_attr( isset( $editing_shop['contact_title'] ) ? $editing_shop['contact_title'] : '' ); ?>" placeholder="e.g. Shop Owner">
                    </div>
                    <div class="sywat-field-group">
                        <label for="sywat_edit_contact_email">Primary Email</label>
                        <input type="email" id="sywat_edit_contact_email" name="shop_email" value="<?php echo esc_attr( isset( $editing_shop['email'] ) ? $editing_shop['email'] : '' ); ?>" placeholder="e.g. beau@domain.com">
                    </div>
                    <div class="sywat-field-group">
                        <label for="sywat_edit_contact_phone">Primary Phone</label>
                        <input type="text" id="sywat_edit_contact_phone" name="shop_phone" class="sywat-phone-input" value="<?php echo esc_attr( isset( $editing_shop['phone'] ) ? $editing_shop['phone'] : '' ); ?>" placeholder="817-439-8998">
                    </div>
                </div>
            </div>

            <!-- Additional Contacts Dynamic Repeater -->
            <div class="sywat-table-header-bar">
                <h2>👥 Additional Location Contacts</h2>
                <div class="sywat-table-header-actions">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" data-sywat-action="add-shop-contact">Add Additional Contact</button>
                </div>
            </div>
            <div id="sywat_shop_contacts_container" class="sywat-mb-4">
                <?php foreach ( $add_contacts as $idx => $ac ): ?>
                    <div class="sywat-contact-row sywat-form-grid-4 sywat-mt-3">
                        <div class="sywat-field-group">
                            <label>Contact Name</label>
                            <input type="text" name="add_contact_names[]" value="<?php echo esc_attr( isset( $ac['name'] ) ? $ac['name'] : '' ); ?>" placeholder="e.g. Hannah Lange">
                        </div>
                        <div class="sywat-field-group">
                            <label>Role / Title</label>
                            <input type="text" name="add_contact_titles[]" value="<?php echo esc_attr( isset( $ac['title'] ) ? $ac['title'] : '' ); ?>" placeholder="e.g. Event Manager">
                        </div>
                        <div class="sywat-field-group">
                            <label>Email</label>
                            <input type="email" name="add_contact_emails[]" value="<?php echo esc_attr( isset( $ac['email'] ) ? $ac['email'] : '' ); ?>" placeholder="e.g. hannah@domain.com">
                        </div>
                        <div class="sywat-field-group">
                            <label>Phone</label>
                            <div class="sywat-search-input-group">
                                <input type="text" name="add_contact_phones[]" class="sywat-phone-input" value="<?php echo esc_attr( isset( $ac['phone'] ) ? $ac['phone'] : '' ); ?>" placeholder="817-439-8998">
                                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" data-sywat-action="remove-shop-contact">Remove</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p id="sywat_no_add_contacts_msg" class="sywat-empty-state-notice <?php echo empty( $add_contacts ) ? '' : 'sywat-hidden'; ?>">No additional contacts added yet. Click "Add Additional Contact" above if needed.</p>
        </div>

        <!-- TAB 3: SYWAT INDOOR LINES -->
        <div id="sywat_edit_tab_indoor_lines" class="sywat-edit-tab-content <?php echo ( $active_sub_tab === 'indoor_lines' ) ? '' : 'sywat-hidden'; ?>">
            <div class="sywat-table-header-bar">
                <h2>SYWAT Indoor Lines &amp; Capacity Templates</h2>
                <div class="sywat-table-header-actions">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" data-sywat-action="add-indoor-line">Add New Line</button>
                </div>
            </div>
            <div class="sywat-table-responsive sywat-mb-4">
                <table class="sywat-table" id="sywat_shop_indoor_lines_table">
                    <thead>
                        <tr>
                            <th class="sywat-col-left">Shooting Day</th>
                            <th class="sywat-col-left">Start Time</th>
                            <th class="sywat-col-left sywat-col-w-14">Default 20yd Cap</th>
                            <th class="sywat-col-left sywat-col-w-14">Default Cub Cap</th>
                            <th class="sywat-col-center sywat-col-w-12">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $indoor_lines = isset( $editing_shop['default_lines'] ) && is_array( $editing_shop['default_lines'] ) ? $editing_shop['default_lines'] : array(
                                array( 'day' => 'Saturday', 'time' => '10:00 AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
                                array( 'day' => 'Saturday', 'time' => '2:00 PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
                                array( 'day' => 'Sunday',   'time' => '10:00 AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
                                array( 'day' => 'Sunday',   'time' => '2:00 PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
                            );
                            foreach ( $indoor_lines as $i => $l ):
                                $day_val  = isset( $l['day'] ) ? $l['day'] : '';
                                $time_val = isset( $l['time'] ) ? $l['time'] : '';
                                if ( empty( $day_val ) && ! empty( $l['name'] ) ) {
                                    $parts    = explode( ' ', trim( $l['name'] ), 2 );
                                    $day_val  = $parts[0];
                                    $time_val = isset( $parts[1] ) ? $parts[1] : '';
                                }
                        ?>
                            <tr>
                                <td>
                                    <div class="sywat-field-group">
                                        <select name="indoor_line_days[]">
                                            <option value="" disabled <?php selected( empty( $day_val ), true ); ?>>-- Select Day --</option>
                                            <option value="Friday" <?php selected( $day_val, 'Friday' ); ?>>Friday</option>
                                            <option value="Saturday" <?php selected( $day_val, 'Saturday' ); ?>>Saturday</option>
                                            <option value="Sunday" <?php selected( $day_val, 'Sunday' ); ?>>Sunday</option>
                                        </select>
                                    </div>
                                </td>
                                <td>
                                    <div class="sywat-field-group">
                                        <input type="text" name="indoor_line_times[]" value="<?php echo esc_attr( $time_val ); ?>" placeholder="e.g. 10:00 AM" required>
                                    </div>
                                </td>
                                <td>
                                    <div class="sywat-field-group">
                                        <input type="number" name="cap_20yd[]" value="<?php echo esc_attr( isset( $l['cap_20yd'] ) ? $l['cap_20yd'] : 20 ); ?>" min="0" required>
                                    </div>
                                </td>
                                <td>
                                    <div class="sywat-field-group">
                                        <input type="number" name="cap_cub[]" value="<?php echo esc_attr( isset( $l['cap_cub'] ) ? $l['cap_cub'] : 5 ); ?>" min="0" required>
                                    </div>
                                </td>
                                <td class="sywat-col-center">
                                    <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" data-sywat-action="remove-indoor-line">Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 4: SYWAT OUTDOOR LINES -->
        <div id="sywat_edit_tab_outdoor_lines" class="sywat-edit-tab-content <?php echo ( $active_sub_tab === 'outdoor_lines' ) ? '' : 'sywat-hidden'; ?>">
            <div class="sywat-table-header-bar">
                <h2>SYWAT Outdoor Lines &amp; Schedule Templates</h2>
                <div class="sywat-table-header-actions">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" data-sywat-action="add-outdoor-line">Add New Line</button>
                </div>
            </div>
            <div class="sywat-table-responsive sywat-mb-4">
                <table class="sywat-table" id="sywat_shop_outdoor_lines_table">
                    <thead>
                        <tr>
                            <th class="sywat-col-left">Shooting Day</th>
                            <th class="sywat-col-left">Start Time</th>
                            <th class="sywat-col-center sywat-col-w-12">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $outdoor_lines = isset( $editing_shop['outdoor_lines'] ) && is_array( $editing_shop['outdoor_lines'] ) ? $editing_shop['outdoor_lines'] : array(
                                array( 'day' => 'Saturday', 'time' => '9:00 AM' ),
                                array( 'day' => 'Sunday',   'time' => '9:00 AM' ),
                            );
                            foreach ( $outdoor_lines as $i => $l ):
                                $day_val  = isset( $l['day'] ) ? $l['day'] : '';
                                $time_val = isset( $l['time'] ) ? $l['time'] : '';
                                if ( empty( $day_val ) && ! empty( $l['name'] ) ) {
                                    $parts    = explode( ' ', trim( $l['name'] ), 2 );
                                    $day_val  = $parts[0];
                                    $time_val = isset( $parts[1] ) ? $parts[1] : '';
                                }
                        ?>
                            <tr>
                                <td>
                                    <div class="sywat-field-group">
                                        <select name="outdoor_line_days[]">
                                            <option value="" disabled <?php selected( empty( $day_val ), true ); ?>>-- Select Day --</option>
                                            <option value="Friday" <?php selected( $day_val, 'Friday' ); ?>>Friday</option>
                                            <option value="Saturday" <?php selected( $day_val, 'Saturday' ); ?>>Saturday</option>
                                            <option value="Sunday" <?php selected( $day_val, 'Sunday' ); ?>>Sunday</option>
                                        </select>
                                    </div>
                                </td>
                                <td>
                                    <div class="sywat-field-group">
                                        <input type="text" name="outdoor_line_times[]" value="<?php echo esc_attr( $time_val ); ?>" placeholder="e.g. 9:00 AM" required>
                                    </div>
                                </td>
                                <td class="sywat-col-center">
                                    <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" data-sywat-action="remove-outdoor-line">Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- GLOBAL FORM ACTIONS -->
        <div class="sywat-table-header-actions sywat-mt-4">
            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Changes</button>
            <a href="<?php echo esc_url( $fallback_list_url ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back sywat-back-to-list-link">Back to List</a>
        </div>
    </form>
</div>
