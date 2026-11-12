<?php
/**
 * View Template: Admin Edit Location (Tabbed Layout)
 * Path: includes/shops/views/view-shop-edit.php
 * Author: SYWAT Archery / TFAA
 * Version: 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
$fallback_list_url  = $can_manage_seasons ? add_query_arg( 'type', $type_filter, $page_url ) : sywat_page_url( 'shops_public' );

$curr_fac       = isset( $editing_shop['facilities'] ) && is_array( $editing_shop['facilities'] ) ? $editing_shop['facilities'] : array();
$curr_dist      = isset( $editing_shop['district'] ) ? $editing_shop['district'] : '';
$curr_type      = isset( $editing_shop['location_type'] ) ? $editing_shop['location_type'] : 'pro_shop';
$add_contacts   = isset( $editing_shop['additional_contacts'] ) && is_array( $editing_shop['additional_contacts'] ) ? $editing_shop['additional_contacts'] : array();

$has_indoor     = in_array( 'indoor_range', $curr_fac );
$has_outdoor    = in_array( 'outdoor_field', $curr_fac );
$active_sub_tab = isset( $_POST['active_tab'] ) ? sanitize_text_field( $_POST['active_tab'] ) : 'profile';
?>

<div class="sywat-card no-print">
    <!-- UNIFIED TOP CARD HEADER BAR: TITLE (LEFT) & BACK BUTTON (RIGHT) -->
    <div class="sywat-card-header-bar">
        <div>
            <h2>🎯 Edit Location: <?php echo esc_html( $editing_shop['name'] ); ?></h2>
        </div>
        <div>
            <a href="<?php echo esc_url( $fallback_list_url ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back" onclick="return confirmBackToList(event);">Back to List</a>
        </div>
    </div>

    <!-- Sub-Tabs Navigation -->
    <div class="sywat-nav-tabs" style="margin-bottom:20px;">
        <button type="button" class="sywat-tab-item <?php echo ($active_sub_tab === 'profile') ? 'active' : ''; ?>" id="sywat_edit_tab_btn_profile" onclick="switchEditShopTab('profile')">📍 Profile & Address</button>
        <button type="button" class="sywat-tab-item <?php echo ($active_sub_tab === 'contacts') ? 'active' : ''; ?>" id="sywat_edit_tab_btn_contacts" onclick="switchEditShopTab('contacts')">👥 Contacts</button>
        <button type="button" class="sywat-tab-item <?php echo ($active_sub_tab === 'indoor_lines') ? 'active' : ''; ?>" id="sywat_edit_tab_btn_indoor_lines" onclick="switchEditShopTab('indoor_lines')" style="<?php echo $has_indoor ? '' : 'display:none;'; ?>">🎯 SYWAT Indoor Lines</button>
        <button type="button" class="sywat-tab-item <?php echo ($active_sub_tab === 'outdoor_lines') ? 'active' : ''; ?>" id="sywat_edit_tab_btn_outdoor_lines" onclick="switchEditShopTab('outdoor_lines')" style="<?php echo $has_outdoor ? '' : 'display:none;'; ?>">🌲 SYWAT Outdoor Lines</button>
    </div>

    <form method="post" id="sywat_edit_shop_form" action="<?php echo esc_url( add_query_arg( array('edit_shop' => $editing_shop['id'], 'type' => $type_filter), $page_url ) ); ?>">
        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
        <input type="hidden" name="sywat_shop_action" value="update_shop">
        <input type="hidden" name="shop_id" value="<?php echo esc_attr( $editing_shop['id'] ); ?>">
        <input type="hidden" name="active_tab" id="sywat_active_tab_input" value="<?php echo esc_attr( $active_sub_tab ); ?>">

        <!-- TAB 1: PROFILE & ADDRESS -->
        <div id="sywat_edit_tab_profile" class="sywat-edit-tab-content" style="<?php echo ($active_sub_tab === 'profile') ? 'display:block;' : 'display:none;'; ?>">
            <div class="sywat-form-grid-3">
                <div class="sywat-field-group">
                    <label>Location Name *</label>
                    <input type="text" name="shop_name" value="<?php echo esc_attr( $editing_shop['name'] ); ?>" required>
                </div>
                <div class="sywat-field-group">
                    <label>Location Type *</label>
                    <select name="location_type" required>
                        <option value="pro_shop" <?php selected( $curr_type, 'pro_shop' ); ?>>🎯 Shop</option>
                        <option value="club" <?php selected( $curr_type, 'club' ); ?>>🏹 Club</option>
                    </select>
                </div>
                <div class="sywat-field-group">
                    <label>District</label>
                    <select name="shop_district">
                        <option value="" <?php selected( empty($curr_dist), true ); ?>>-- Unassigned --</option>
                        <?php foreach ( $districts as $key => $label ): ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $curr_dist, $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Facilities & Amenities Multi-Select -->
            <div style="background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:6px; margin-top:15px; margin-bottom:20px;">
                <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:700; color:#1e293b;">Facilities & Range Amenities</h4>
                <div class="sywat-form-grid-4" style="gap:10px;">
                    <label style="font-size:13px; display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="checkbox" name="facilities[]" value="pro_shop" <?php checked( in_array('pro_shop', $curr_fac), true ); ?>> 🏪 Pro Shop & Retail
                    </label>
                    <label style="font-size:13px; display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="checkbox" name="facilities[]" value="indoor_range" <?php checked( in_array('indoor_range', $curr_fac), true ); ?>> 🎯 Indoor Target Range
                    </label>
                    <label style="font-size:13px; display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="checkbox" name="facilities[]" value="outdoor_field" <?php checked( in_array('outdoor_field', $curr_fac), true ); ?>> 🌲 Outdoor Field Course
                    </label>
                    <label style="font-size:13px; display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="checkbox" name="facilities[]" value="3d_range" <?php checked( in_array('3d_range', $curr_fac), true ); ?>> 🦌 3D Animal Range
                    </label>
                </div>
            </div>

            <h4 style="margin:20px 0 10px 0; font-size:15px; font-weight:700; color:#1e293b;">Location Address Details (Optional)</h4>
            <div class="sywat-form-grid-2">
                <div class="sywat-field-group">
                    <label>Address Line 1</label>
                    <input type="text" name="shop_address_1" value="<?php echo esc_attr( isset($editing_shop['address_1']) ? $editing_shop['address_1'] : '' ); ?>" placeholder="e.g. 13795 Old Denton Rd">
                </div>
                <div class="sywat-field-group">
                    <label>Address Line 2</label>
                    <input type="text" name="shop_address_2" value="<?php echo esc_attr( isset($editing_shop['address_2']) ? $editing_shop['address_2'] : '' ); ?>" placeholder="Suite, Bldg, etc.">
                </div>
            </div>
            <div class="sywat-form-grid-2" style="margin-top:12px;">
                <div class="sywat-field-group">
                    <label>City</label>
                    <input type="text" name="shop_city" value="<?php echo esc_attr( isset($editing_shop['city']) ? $editing_shop['city'] : '' ); ?>" placeholder="e.g. Roanoke">
                </div>
                <div class="sywat-form-grid-2">
                    <div class="sywat-field-group">
                        <label>State</label>
                        <?php $curr_st = isset($editing_shop['state']) ? $editing_shop['state'] : ''; ?>
                        <select name="shop_state">
                            <option value="" <?php selected( empty($curr_st), true ); ?>>-- Select State --</option>
                            <option value="TX" <?php selected( $curr_st, 'TX' ); ?>>TX - Texas</option>
                            <option value="OK" <?php selected( $curr_st, 'OK' ); ?>>OK - Oklahoma</option>
                            <option value="LA" <?php selected( $curr_st, 'LA' ); ?>>LA - Louisiana</option>
                        </select>
                    </div>
                    <div class="sywat-field-group">
                        <label>ZIP Code</label>
                        <input type="text" name="shop_zip" value="<?php echo esc_attr( isset($editing_shop['zip']) ? $editing_shop['zip'] : '' ); ?>" placeholder="e.g. 76262">
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: CONTACTS -->
        <div id="sywat_edit_tab_contacts" class="sywat-edit-tab-content" style="<?php echo ($active_sub_tab === 'contacts') ? 'display:block;' : 'display:none;'; ?>">
            <!-- Primary Contact Section -->
            <div style="background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:15px;">
                <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:700; color:#1e293b;">👤 Primary Contact Person</h4>
                <div class="sywat-form-grid-4">
                    <div class="sywat-field-group">
                        <label>Primary Contact Name</label>
                        <input type="text" name="shop_contact" value="<?php echo esc_attr( isset($editing_shop['contact']) ? $editing_shop['contact'] : '' ); ?>" placeholder="e.g. Beau Brennan">
                    </div>
                    <div class="sywat-field-group">
                        <label>Primary Title / Role</label>
                        <input type="text" name="shop_contact_title" value="<?php echo esc_attr( isset($editing_shop['contact_title']) ? $editing_shop['contact_title'] : '' ); ?>" placeholder="e.g. Shop Owner">
                    </div>
                    <div class="sywat-field-group">
                        <label>Primary Email</label>
                        <input type="email" name="shop_email" value="<?php echo esc_attr( isset($editing_shop['email']) ? $editing_shop['email'] : '' ); ?>" placeholder="e.g. beau@domain.com">
                    </div>
                    <div class="sywat-field-group">
                        <label>Primary Phone</label>
                        <input type="text" name="shop_phone" class="sywat-phone-input" value="<?php echo esc_attr( isset($editing_shop['phone']) ? $editing_shop['phone'] : '' ); ?>" placeholder="817-439-8998">
                    </div>
                </div>
            </div>

            <!-- Additional Contacts Dynamic Repeater -->
            <div style="background:#ffffff; padding:15px; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h4 style="margin:0; font-size:14px; font-weight:700; color:#1e293b;">👥 Additional Location Contacts</h4>
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addShopContactRow()">Add Additional Contact</button>
                </div>
                <div id="sywat_shop_contacts_container" style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ( $add_contacts as $idx => $ac ): ?>
                        <div class="sywat-contact-row" style="display:grid; grid-template-columns: 1fr 1fr 1.5fr 1fr auto; gap:10px; align-items:end; background:#f8fafc; padding:10px; border:1px solid #e2e8f0; border-radius:4px;">
                            <div class="sywat-field-group">
                                <label style="font-size:11px;">Contact Name</label>
                                <input type="text" name="add_contact_names[]" value="<?php echo esc_attr( isset($ac['name']) ? $ac['name'] : '' ); ?>" placeholder="e.g. Hannah Lange">
                            </div>
                            <div class="sywat-field-group">
                                <label style="font-size:11px;">Role / Title</label>
                                <input type="text" name="add_contact_titles[]" value="<?php echo esc_attr( isset($ac['title']) ? $ac['title'] : '' ); ?>" placeholder="e.g. Event Manager">
                            </div>
                            <div class="sywat-field-group">
                                <label style="font-size:11px;">Email</label>
                                <input type="email" name="add_contact_emails[]" value="<?php echo esc_attr( isset($ac['email']) ? $ac['email'] : '' ); ?>" placeholder="e.g. hannah@domain.com">
                            </div>
                            <div class="sywat-field-group">
                                <label style="font-size:11px;">Phone</label>
                                <input type="text" name="add_contact_phones[]" class="sywat-phone-input" value="<?php echo esc_attr( isset($ac['phone']) ? $ac['phone'] : '' ); ?>" placeholder="817-439-8998">
                            </div>
                            <div>
                                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" onclick="removeShopContactRow(this)">Remove</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ( empty( $add_contacts ) ): ?>
                    <p id="sywat_no_add_contacts_msg" style="color:#64748b; font-style:italic; font-size:12px; margin:5px 0 0 0;">No additional contacts added yet. Click "Add Additional Contact" above if needed.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 3: SYWAT INDOOR LINES -->
        <div id="sywat_edit_tab_indoor_lines" class="sywat-edit-tab-content" style="<?php echo ($active_sub_tab === 'indoor_lines') ? 'display:block;' : 'display:none;'; ?>">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h4 style="margin:0; font-size:15px; font-weight:700; color:#1e293b;">SYWAT Indoor Lines & Capacity Templates</h4>
                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addShopIndoorLineRow()">Add New Line</button>
            </div>
            <div class="sywat-table-responsive" style="margin-bottom:20px;">
                <table class="sywat-table" id="sywat_shop_indoor_lines_table">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Shooting Day</th>
                            <th style="text-align:left;">Start Time</th>
                            <th style="width:180px; text-align:left;">Default 20yd Cap</th>
                            <th style="width:180px; text-align:left;">Default Cub Cap</th>
                            <th class="sywat-col-center" style="width:110px;">Action</th>
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
                                $day_val  = isset($l['day']) ? $l['day'] : '';
                                $time_val = isset($l['time']) ? $l['time'] : '';
                                if ( empty($day_val) && !empty($l['name']) ) {
                                    $parts    = explode(' ', trim($l['name']), 2);
                                    $day_val  = $parts[0];
                                    $time_val = isset($parts[1]) ? $parts[1] : '';
                                }
                        ?>
                            <tr>
                                <td>
                                    <div class="sywat-field-group">
                                        <select name="indoor_line_days[]" onchange="checkShopLineDuplicate(this);">
                                            <option value="" disabled <?php selected( empty($day_val), true ); ?>>-- Select Day --</option>
                                            <option value="Friday" <?php selected( $day_val, 'Friday' ); ?>>Friday</option>
                                            <option value="Saturday" <?php selected( $day_val, 'Saturday' ); ?>>Saturday</option>
                                            <option value="Sunday" <?php selected( $day_val, 'Sunday' ); ?>>Sunday</option>
                                        </select>
                                    </div>
                                </td>
                                <td>
                                    <div class="sywat-field-group">
                                        <input type="text" name="indoor_line_times[]" value="<?php echo esc_attr( $time_val ); ?>" placeholder="e.g. 10:00 AM" required onchange="checkShopLineDuplicate(this);">
                                    </div>
                                </td>
                                <td>
                                    <div class="sywat-field-group">
                                        <input type="number" name="cap_20yd[]" value="<?php echo esc_attr( isset($l['cap_20yd']) ? $l['cap_20yd'] : 20 ); ?>" min="0" required>
                                    </div>
                                </td>
                                <td>
                                    <div class="sywat-field-group">
                                        <input type="number" name="cap_cub[]" value="<?php echo esc_attr( isset($l['cap_cub']) ? $l['cap_cub'] : 5 ); ?>" min="0" required>
                                    </div>
                                </td>
                                <td class="sywat-col-center">
                                    <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" onclick="removeShopIndoorLineRow(this)">Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 4: SYWAT OUTDOOR LINES -->
        <div id="sywat_edit_tab_outdoor_lines" class="sywat-edit-tab-content" style="<?php echo ($active_sub_tab === 'outdoor_lines') ? 'display:block;' : 'display:none;'; ?>">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h4 style="margin:0; font-size:15px; font-weight:700; color:#1e293b;">SYWAT Outdoor Lines & Schedule Templates</h4>
                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addShopOutdoorLineRow()">Add New Line</button>
            </div>
            <div class="sywat-table-responsive" style="margin-bottom:20px;">
                <table class="sywat-table" id="sywat_shop_outdoor_lines_table">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Shooting Day</th>
                            <th style="text-align:left;">Start Time</th>
                            <th class="sywat-col-center" style="width:110px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                            $outdoor_lines = isset( $editing_shop['outdoor_lines'] ) && is_array( $editing_shop['outdoor_lines'] ) ? $editing_shop['outdoor_lines'] : array(
                                array( 'day' => 'Saturday', 'time' => '9:00 AM' ),
                                array( 'day' => 'Sunday',   'time' => '9:00 AM' ),
                            );
                            foreach ( $outdoor_lines as $i => $l ): 
                                $day_val  = isset($l['day']) ? $l['day'] : '';
                                $time_val = isset($l['time']) ? $l['time'] : '';
                                if ( empty($day_val) && !empty($l['name']) ) {
                                    $parts    = explode(' ', trim($l['name']), 2);
                                    $day_val  = $parts[0];
                                    $time_val = isset($parts[1]) ? $parts[1] : '';
                                }
                        ?>
                            <tr>
                                <td>
                                    <div class="sywat-field-group">
                                        <select name="outdoor_line_days[]" onchange="checkShopLineDuplicate(this);">
                                            <option value="" disabled <?php selected( empty($day_val), true ); ?>>-- Select Day --</option>
                                            <option value="Friday" <?php selected( $day_val, 'Friday' ); ?>>Friday</option>
                                            <option value="Saturday" <?php selected( $day_val, 'Saturday' ); ?>>Saturday</option>
                                            <option value="Sunday" <?php selected( $day_val, 'Sunday' ); ?>>Sunday</option>
                                        </select>
                                    </div>
                                </td>
                                <td>
                                    <div class="sywat-field-group">
                                        <input type="text" name="outdoor_line_times[]" value="<?php echo esc_attr( $time_val ); ?>" placeholder="e.g. 9:00 AM" required onchange="checkShopLineDuplicate(this);">
                                    </div>
                                </td>
                                <td class="sywat-col-center">
                                    <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" onclick="removeShopOutdoorLineRow(this)">Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- GLOBAL FORM ACTIONS -->
        <div style="display:flex; gap:10px; margin-top:20px; padding-top:15px; border-top:1px solid #cbd5e1;">
            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Changes</button>
            <a href="<?php echo esc_url( $fallback_list_url ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back" onclick="return confirmBackToList(event);">Back to List</a>
        </div>
    </form>
</div>
