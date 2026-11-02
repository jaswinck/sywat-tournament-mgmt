<?php
/**
 * View Template: Admin Edit Location (Tabbed Layout)
 * Path: includes/shops/views/view-shop-edit.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$curr_fac     = isset( $editing_shop['facilities'] ) && is_array( $editing_shop['facilities'] ) ? $editing_shop['facilities'] : array();
$curr_dist    = isset( $editing_shop['district'] ) ? $editing_shop['district'] : '';
$curr_type    = isset( $editing_shop['location_type'] ) ? $editing_shop['location_type'] : 'pro_shop';
$add_contacts = isset( $editing_shop['additional_contacts'] ) && is_array( $editing_shop['additional_contacts'] ) ? $editing_shop['additional_contacts'] : array();
?>

<div class="sywat-card no-print">
    <div class="sywat-card-title">
        <span>Edit Location: <strong><?php echo esc_html( $editing_shop['name'] ); ?></strong></span>
        <a href="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear">Cancel Editing</a>
    </div>

    <!-- Sub-Tabs Navigation -->
    <div class="sywat-nav-tabs" style="margin-bottom:20px;">
        <button type="button" class="sywat-tab-item active" id="sywat_edit_tab_btn_profile" onclick="switchEditShopTab('profile')">📍 Profile & Address</button>
        <button type="button" class="sywat-tab-item" id="sywat_edit_tab_btn_contacts" onclick="switchEditShopTab('contacts')">👥 Contacts</button>
        <button type="button" class="sywat-tab-item" id="sywat_edit_tab_btn_lines" onclick="switchEditShopTab('lines')">🎯 Shooting Lines</button>
    </div>

    <form method="post" action="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>">
        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
        <input type="hidden" name="sywat_shop_action" value="update_shop">
        <input type="hidden" name="shop_id" value="<?php echo esc_attr( $editing_shop['id'] ); ?>">

        <!-- TAB 1: PROFILE & ADDRESS -->
        <div id="sywat_edit_tab_profile" class="sywat-edit-tab-content">
            <div class="sywat-form-grid-3" style="margin-bottom:15px;">
                <div>
                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Location Name *</label>
                    <input type="text" name="shop_name" value="<?php echo esc_attr( $editing_shop['name'] ); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                </div>
                <div>
                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Location Type *</label>
                    <select name="location_type" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                        <option value="pro_shop" <?php selected( $curr_type, 'pro_shop' ); ?>>🏪 Shop</option>
                        <option value="club" <?php selected( $curr_type, 'club' ); ?>>🌲 Club</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">TFAA District</label>
                    <select name="shop_district" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        <option value="" <?php selected( empty($curr_dist), true ); ?>>-- Unassigned --</option>
                        <?php foreach ( $districts as $key => $label ): ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $curr_dist, $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Facilities & Amenities Multi-Select -->
            <div style="background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:20px;">
                <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:bold; color:#0f172a;">Facilities & Range Amenities</h4>
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

            <h4 style="margin:20px 0 10px 0; font-size:15px; font-weight:bold;">Location Address Details (Optional)</h4>
            <div class="sywat-form-grid-2" style="margin-bottom:15px;">
                <div>
                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Address Line 1</label>
                    <input type="text" name="shop_address_1" value="<?php echo esc_attr( isset($editing_shop['address_1']) ? $editing_shop['address_1'] : '' ); ?>" placeholder="e.g. 13795 Old Denton Rd" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
                <div>
                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Address Line 2</label>
                    <input type="text" name="shop_address_2" value="<?php echo esc_attr( isset($editing_shop['address_2']) ? $editing_shop['address_2'] : '' ); ?>" placeholder="Suite, Bldg, etc." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
                <div>
                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">City</label>
                    <input type="text" name="shop_city" value="<?php echo esc_attr( isset($editing_shop['city']) ? $editing_shop['city'] : '' ); ?>" placeholder="e.g. Roanoke" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
                <div class="sywat-form-grid-2" style="gap:10px;">
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">State</label>
                        <?php $curr_st = isset($editing_shop['state']) ? $editing_shop['state'] : ''; ?>
                        <select name="shop_state" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                            <option value="" <?php selected( empty($curr_st), true ); ?>>-- Select State --</option>
                            <option value="TX" <?php selected( $curr_st, 'TX' ); ?>>TX - Texas</option>
                            <option value="OK" <?php selected( $curr_st, 'OK' ); ?>>OK - Oklahoma</option>
                            <option value="LA" <?php selected( $curr_st, 'LA' ); ?>>LA - Louisiana</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">ZIP Code</label>
                        <input type="text" name="shop_zip" value="<?php echo esc_attr( isset($editing_shop['zip']) ? $editing_shop['zip'] : '' ); ?>" placeholder="e.g. 76262" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: CONTACTS -->
        <div id="sywat_edit_tab_contacts" class="sywat-edit-tab-content" style="display:none;">
            <!-- Primary Contact Section -->
            <div style="background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:15px;">
                <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:bold; color:#0f172a;">👤 Primary Contact Person</h4>
                <div class="sywat-form-grid-3" style="gap:10px;">
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Primary Contact Name</label>
                        <input type="text" name="shop_contact" value="<?php echo esc_attr( isset($editing_shop['contact']) ? $editing_shop['contact'] : '' ); ?>" placeholder="e.g. Beau Brennan" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Primary Email</label>
                        <input type="email" name="shop_email" value="<?php echo esc_attr( isset($editing_shop['email']) ? $editing_shop['email'] : '' ); ?>" placeholder="e.g. beau@domain.com" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;">Primary Phone</label>
                        <input type="text" name="shop_phone" class="sywat-phone-input" value="<?php echo esc_attr( isset($editing_shop['phone']) ? $editing_shop['phone'] : '' ); ?>" placeholder="817-439-8998" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                    </div>
                </div>
            </div>

            <!-- Additional Contacts Dynamic Repeater -->
            <div style="background:#ffffff; padding:15px; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h4 style="margin:0; font-size:14px; font-weight:bold; color:#0f172a;">👥 Additional Location Contacts</h4>
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addShopContactRow()">Add Additional Contact</button>
                </div>
                <div id="sywat_shop_contacts_container" style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ( $add_contacts as $idx => $ac ): ?>
                        <div class="sywat-contact-row sywat-form-grid-4" style="align-items:end; background:#f8fafc; padding:10px; border:1px solid #e2e8f0; border-radius:4px;">
                            <div>
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:11px;">Contact Name</label>
                                <input type="text" name="add_contact_names[]" value="<?php echo esc_attr( isset($ac['name']) ? $ac['name'] : '' ); ?>" placeholder="e.g. Hannah Lange" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:11px;">Role / Title</label>
                                <input type="text" name="add_contact_titles[]" value="<?php echo esc_attr( isset($ac['title']) ? $ac['title'] : '' ); ?>" placeholder="e.g. Event Manager" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:11px;">Email</label>
                                <input type="email" name="add_contact_emails[]" value="<?php echo esc_attr( isset($ac['email']) ? $ac['email'] : '' ); ?>" placeholder="e.g. hannah@domain.com" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                            </div>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <div style="flex:1;">
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:11px;">Phone</label>
                                    <input type="text" name="add_contact_phones[]" class="sywat-phone-input" value="<?php echo esc_attr( isset($ac['phone']) ? $ac['phone'] : '' ); ?>" placeholder="817-439-8998" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                                </div>
                                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="height:32px;" onclick="removeShopContactRow(this)">Remove</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ( empty( $add_contacts ) ): ?>
                    <p id="sywat_no_add_contacts_msg" style="color:#64748b; font-style:italic; font-size:12px; margin:5px 0 0 0;">No additional contacts added yet. Click "Add Additional Contact" above if needed.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 3: SHOOTING LINES -->
        <div id="sywat_edit_tab_lines" class="sywat-edit-tab-content" style="display:none;">
            <!-- Default Shooting Lines Repeater Table -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h4 style="margin:0; font-size:15px; font-weight:bold;">Default Shooting Lines & Capacity Templates</h4>
                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addShopLineRow()">Add New Line</button>
            </div>
            <div class="sywat-table-responsive" style="margin-bottom:20px;">
                <table class="sywat-table" id="sywat_shop_lines_table">
                    <thead>
                        <tr>
                            <th>Line Day / Time</th>
                            <th style="width:180px;">Default 20yd Cap</th>
                            <th style="width:180px;">Default Cub Cap</th>
                            <th style="width:110px; text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                            $lines = isset( $editing_shop['default_lines'] ) && is_array( $editing_shop['default_lines'] ) ? $editing_shop['default_lines'] : array(
                                array( 'name' => 'Saturday 10AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
                                array( 'name' => 'Saturday 2PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
                                array( 'name' => 'Sunday 10AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
                                array( 'name' => 'Sunday 2PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
                            );
                            foreach ( $lines as $i => $l ): 
                        ?>
                            <tr>
                                <td style="padding:6px;"><input type="text" name="line_names[]" value="<?php echo esc_attr( $l['name'] ); ?>" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
                                <td style="padding:6px;"><input type="number" name="cap_20yd[]" value="<?php echo esc_attr( $l['cap_20yd'] ); ?>" min="0" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
                                <td style="padding:6px;"><input type="number" name="cap_cub[]" value="<?php echo esc_attr( $l['cap_cub'] ); ?>" min="0" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
                                <td style="padding:6px; text-align:center;">
                                    <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="height:32px;" onclick="removeShopLineRow(this)">Remove</button>
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
            <a href="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear">Cancel</a>
        </div>
    </form>
</div>
