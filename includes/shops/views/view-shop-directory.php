<?php
/**
 * View Template: Admin Shop Directory & Creation / Import Modals
 * Path: includes/shops/views/view-shop-directory.php
 * Author: SYWAT Archery / TFAA
 * Version: 5.1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$can_manage_seasons = function_exists( 'sywat_can_manage_seasons' ) && sywat_can_manage_seasons();
$user_shop_id       = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : '';
$is_admin           = current_user_can( 'administrator' );

// Check for Active 2-Step Import Preview Transient
$user_id        = get_current_user_id();
$import_preview = get_transient( 'sywat_shop_import_preview_' . $user_id );
$has_preview    = ! empty( $import_preview ) && ! empty( $import_preview['items'] );

$filtered_shops = array();
foreach ( $all_shops as $s_id => $shop ) {
    $s_type = isset($shop['location_type']) ? $shop['location_type'] : 'pro_shop';
    if ( $type_filter === 'all' || $s_type === $type_filter ) {
        $filtered_shops[$s_id] = $shop;
    }
}

// Enforce Alphabetical Sorting by Location Name
uasort( $filtered_shops, function( $a, $b ) {
    $name_a = isset( $a['name'] ) ? $a['name'] : '';
    $name_b = isset( $b['name'] ) ? $b['name'] : '';
    return strcasecmp( $name_a, $name_b );
});
?>

<div class="sywat-card no-print">
    <!-- UNIFIED TOP CARD HEADER BAR: TITLE (LEFT) & INLINE CONTROLS / ACTIONS (RIGHT) -->
    <div class="sywat-card-header-bar">
        <h2>
            <?php 
                if ($type_filter === 'pro_shop') echo '🎯 Shops';
                elseif ($type_filter === 'club') echo '🏹 Clubs';
                else echo '🌐 All Locations';
            ?>
        </h2>

        <div class="sywat-header-inline-controls">
            <!-- Clean Inline District Filter -->
            <div class="sywat-field-group" style="width:auto;">
                <label for="sywat_district_filter_select">District:</label>
                <select id="sywat_district_filter_select" style="min-width:180px;">
                    <option value="">All Districts</option>
                    <option value="unassigned">Unassigned Only</option>
                    <?php foreach ( $districts as $key => $label ): ?>
                        <option value="<?php echo esc_attr( strtolower($key) ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Clean Inline Search & Clear -->
            <div class="sywat-field-group" style="width:auto;">
                <label for="sywat_shop_search_input">Search Location:</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" id="sywat_shop_search_input" placeholder="Search by shop or contact..." autocomplete="off" style="min-width:200px;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="document.getElementById('sywat_shop_search_input').value=''; document.getElementById('sywat_district_filter_select').value=''; sywatFilterShops();">Clear</button>
                </div>
            </div>

            <?php if ( $can_manage_seasons || $is_admin ): ?>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-export" onclick="openImportShopsModal()">Import Locations</button>
                    <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" onclick="openAddShopModal()">Add Location</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( empty( $filtered_shops ) ): ?>
        <p style="color:#666; padding:15px 0;">No locations found matching this view category.</p>
    <?php else: ?>
        <div class="sywat-table-responsive">
            <table class="sywat-table">
                <thead>
                    <tr>
                        <th style="width:20%;">Location Name & ID</th>
                        <th style="width:10%;">District</th>
                        <th style="width:14%;">Facilities</th>
                        <th style="width:20%;">Contact Info</th>
                        <th style="width:11%;">Phone</th>
                        <th class="sywat-col-right" style="width:25%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $filtered_shops as $s_id => $shop ): ?>
                        <?php 
                            $linked_count = 0;
                            foreach ( $eligible_users as $u ) {
                                $u_shop = get_user_meta( $u->ID, 'sywat_shop_id', true );
                                if ( $u_shop === $s_id ) $linked_count++;
                            }
                            $s_contact  = isset($shop['contact']) ? $shop['contact'] : '';
                            $s_dist     = isset($shop['district']) ? $shop['district'] : '';
                            $s_fac      = isset($shop['facilities']) && is_array($shop['facilities']) ? $shop['facilities'] : array();
                            $s_type     = isset($shop['location_type']) ? $shop['location_type'] : 'pro_shop';
                            $s_add_cnt  = isset($shop['additional_contacts']) && is_array($shop['additional_contacts']) ? $shop['additional_contacts'] : array();

                            $all_cnt_names = array_filter( array_merge( array($s_contact), array_column($s_add_cnt, 'name') ) );
                            $cnt_search_str = implode( ' ', $all_cnt_names );
                        ?>
                        <tr class="sywat-shop-row" data-name="<?php echo esc_attr(strtolower($shop['name'])); ?>" data-id="<?php echo esc_attr(strtolower($s_id)); ?>" data-contact="<?php echo esc_attr(strtolower($cnt_search_str)); ?>" data-district="<?php echo esc_attr(strtolower($s_dist)); ?>">
                            <td>
                                <strong><?php echo esc_html( $shop['name'] ); ?></strong>
                                <?php if ($type_filter === 'all'): ?>
                                    <span class="sywat-badge" style="background:<?php echo ($s_type === 'club') ? '#fef3c7; color:#b45309;' : '#e0f2fe; color:#0369a1;'; ?> font-size:10px; padding:2px 6px; margin-left:4px;">
                                        <?php echo ($s_type === 'club') ? '🏹 Club' : '🎯 Shop'; ?>
                                    </span>
                                <?php endif; ?>
                                <br>
                                <code style="font-size:11px; color:#646970;"><?php echo esc_html( $s_id ); ?></code>
                            </td>
                            <td>
                                <?php if ( ! empty( $s_dist ) ): ?>
                                    <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $s_dist ); ?></span>
                                <?php else: ?>
                                    <span style="font-size:12px; color:#94a3b8; font-style:italic;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; flex-wrap:wrap; gap:3px;">
                                    <?php if ( in_array( 'pro_shop', $s_fac ) ): ?>
                                        <span class="sywat-badge" style="background:#e0f2fe; color:#0369a1;">🏪 Pro Shop</span>
                                    <?php endif; ?>
                                    <?php if ( in_array( 'indoor_range', $s_fac ) ): ?>
                                        <span class="sywat-badge" style="background:#f0fdf4; color:#15803d;">🎯 Indoor</span>
                                    <?php endif; ?>
                                    <?php if ( in_array( 'outdoor_field', $s_fac ) ): ?>
                                        <span class="sywat-badge" style="background:#fef3c7; color:#b45309;">🌲 Field</span>
                                    <?php endif; ?>
                                    <?php if ( in_array( '3d_range', $s_fac ) ): ?>
                                        <span class="sywat-badge" style="background:#fae8ff; color:#86198f;">🦌 3D</span>
                                    <?php endif; ?>
                                    <?php if ( empty( $s_fac ) ): ?>
                                        <span style="font-size:11px; color:#94a3b8;">None listed</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo esc_html( $s_contact ? $s_contact : 'N/A' ); ?></strong>
                                <?php if ( ! empty($s_add_cnt) ): ?>
                                    <span class="sywat-badge" style="background:#f1f5f9; color:#475569; font-size:10px; cursor:help;" title="<?php foreach($s_add_cnt as $ac) { echo esc_attr(($ac['name'] ?? 'Contact') . ' (' . ($ac['title'] ?? 'Staff') . ') - ' . ($ac['email'] ?? 'No email') . "\n"); } ?>">
                                        +<?php echo count($s_add_cnt); ?> more
                                    </span>
                                <?php endif; ?>
                                <br>
                                <?php 
                                    $raw_email = isset($shop['email']) ? $shop['email'] : '';
                                    if ( ! empty( $raw_email ) ) {
                                        $email_parts = preg_split( '/[\s,\/]+/', $raw_email );
                                        foreach ( $email_parts as $e_part ) {
                                            $e_clean = trim( $e_part );
                                            if ( $e_clean ) {
                                                echo '<a href="mailto:' . esc_attr($e_clean) . '" style="font-size:12px; color:#0073aa; word-break:break-all; display:block; text-decoration:none;">' . esc_html($e_clean) . '</a>';
                                            }
                                        }
                                    } else {
                                        echo '<span style="font-size:12px; color:#94a3b8;">No email</span>';
                                    }
                                ?>
                            </td>
                            <td><?php echo esc_html( isset($shop['phone']) ? $shop['phone'] : 'N/A' ); ?></td>
                            <td class="sywat-col-right">
                                <div class="sywat-table-actions" style="white-space:nowrap; display:inline-flex; gap:6px; justify-content:flex-end;">
                                    <?php if ( $can_manage_seasons || $is_admin ): ?>
                                        <a href="<?php echo esc_url( add_query_arg( array('manage_staff' => $s_id, 'type' => $type_filter), $page_url ) ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-manage">Staff (<?php echo $linked_count; ?>)</a>
                                        <a href="<?php echo esc_url( add_query_arg( array('edit_shop' => $s_id, 'type' => $type_filter), $page_url ) ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit</a>
                                        <form method="post" action="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>" class="sywat-table-action-form" onsubmit="return confirm('Are you sure you want to delete this location?');">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_shop_action" value="delete_shop">
                                            <input type="hidden" name="shop_id" value="<?php echo esc_attr( $s_id ); ?>">
                                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Delete</button>
                                        </form>
                                    <?php elseif ( ! empty( $user_shop_id ) && $s_id === $user_shop_id ): ?>
                                        <a href="<?php echo esc_url( add_query_arg( array('manage_staff' => $s_id, 'type' => $type_filter), $page_url ) ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-manage">Staff (<?php echo $linked_count; ?>)</a>
                                        <a href="<?php echo esc_url( add_query_arg( array('edit_shop' => $s_id, 'type' => $type_filter), $page_url ) ); ?>" class="sywat-btn sywat-btn-success sywat-btn-icon-edit">Edit My Shop</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- IMPORT SHOPS MODAL -->
<div id="importShopsModal" class="sywat-modal-backdrop" style="display:none;">
    <div class="sywat-modal-content <?php echo $has_preview ? 'sywat-modal-lg' : 'sywat-modal-md'; ?>">
        <div class="sywat-modal-header no-print">
            <h3>📥 <?php echo $has_preview ? 'Review Import Preview' : 'Import Locations & Contacts'; ?></h3>
            <button type="button" class="sywat-modal-close" onclick="closeImportShopsModal()">&times;</button>
        </div>
        <div class="sywat-modal-body">
            <?php if ( $has_preview ): ?>
                <!-- STAGE 2: DRY-RUN PREVIEW TABLE & CONFIRMATION -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <strong style="font-size:13px; color:#1e293b;">File:</strong> <code><?php echo esc_html( $import_preview['file_name'] ); ?></code>
                        <span style="font-size:12px; color:#64748b; margin-left:10px;">(Mode: <?php echo esc_html( $import_preview['mode'] === 'skip_existing' ? 'Add New Only' : 'Safe Merge & Update' ); ?>)</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <span class="sywat-badge" style="background:#dcfce7; color:#15803d; font-weight:bold; padding:4px 8px; font-size:11px;">
                            🟢 <?php echo intval( $import_preview['summary']['new'] ); ?> New
                        </span>
                        <span class="sywat-badge" style="background:#fef3c7; color:#b45309; font-weight:bold; padding:4px 8px; font-size:11px;">
                            🟡 <?php echo intval( $import_preview['summary']['update'] ); ?> Updates
                        </span>
                        <?php if ( $import_preview['summary']['skip'] > 0 ): ?>
                            <span class="sywat-badge" style="background:#f1f5f9; color:#64748b; font-weight:bold; padding:4px 8px; font-size:11px;">
                                ⚪ <?php echo intval( $import_preview['summary']['skip'] ); ?> Skipped
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="max-height:360px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:15px;">
                    <table class="sywat-table" style="font-size:12px;">
                        <thead style="position:sticky; top:0; background:#f1f5f9; z-index:2;">
                            <tr>
                                <th style="width:14%;">Action</th>
                                <th style="width:26%;">Location Name</th>
                                <th style="width:10%;">Type</th>
                                <th style="width:12%;">District</th>
                                <th style="width:20%;">Address</th>
                                <th style="width:18%;">Primary Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $import_preview['items'] as $p_item ): ?>
                                <tr>
                                    <td>
                                        <?php if ( $p_item['status'] === 'new' ): ?>
                                            <span class="sywat-badge" style="background:#dcfce7; color:#15803d; font-size:10px; padding:2px 6px;">🟢 Create New</span>
                                        <?php elseif ( $p_item['status'] === 'update' ): ?>
                                            <span class="sywat-badge" style="background:#fef3c7; color:#b45309; font-size:10px; padding:2px 6px;">🟡 Update</span>
                                        <?php else: ?>
                                            <span class="sywat-badge" style="background:#f1f5f9; color:#64748b; font-size:10px; padding:2px 6px;">⚪ Skipped</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo esc_html( $p_item['name'] ); ?></strong></td>
                                    <td>
                                        <span class="sywat-badge" style="background:<?php echo ($p_item['location_type'] === 'club') ? '#fef3c7; color:#b45309;' : '#e0f2fe; color:#0369a1;'; ?> font-size:10px;">
                                            <?php echo ($p_item['location_type'] === 'club') ? '🏹 Club' : '🎯 Shop'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html( $p_item['district'] ? $p_item['district'] : 'Unassigned' ); ?></td>
                                    <td>
                                        <?php 
                                            $full_addr = array_filter( array( $p_item['address_1'], $p_item['city'], $p_item['state'], $p_item['zip'] ) );
                                            echo esc_html( ! empty( $full_addr ) ? implode( ', ', $full_addr ) : 'No address' );
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html( $p_item['contact'] ? $p_item['contact'] : 'N/A' ); ?></strong>
                                        <?php if ( ! empty( $p_item['phone'] ) ): ?>
                                            <br><span style="color:#64748b; font-size:11px;"><?php echo esc_html( $p_item['phone'] ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:20px;">
                    <form method="post" action="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>" style="margin:0;">
                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                        <input type="hidden" name="sywat_shop_action" value="cancel_import_shops">
                        <button type="submit" class="sywat-btn sywat-btn-secondary">Cancel & Start Over</button>
                    </form>
                    <form method="post" action="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>" style="margin:0;">
                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                        <input type="hidden" name="sywat_shop_action" value="confirm_import_shops">
                        <button type="submit" class="sywat-btn sywat-btn-primary">Confirm & Import <?php echo count($import_preview['items']); ?> Locations</button>
                    </form>
                </div>

            <?php else: ?>
                <!-- STAGE 1: FILE UPLOAD FORM -->
                <form method="post" action="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_shop_action" value="preview_import_shops">

                    <div style="margin-bottom:15px;">
                        <p style="font-size:13px; color:#475569; margin-top:0; line-height:1.5;">
                            Upload an Excel (<code>.xlsx</code> / <code>.xls</code>) or <code>.csv</code> file containing your master shop directory and contact details.
                        </p>
                    </div>

                    <div class="sywat-field-group">
                        <label>Select File (.xlsx, .xls, .csv) *</label>
                        <input type="file" name="shop_import_file" accept=".xlsx,.xls,.csv" required style="background:#fff;">
                    </div>

                    <div class="sywat-field-group" style="margin-top:12px;">
                        <label>Import Mode</label>
                        <select name="import_mode">
                            <option value="upsert">Safe Merge & Update (Update Existing & Add New)</option>
                            <option value="skip_existing">Add New Locations Only (Skip Existing)</option>
                        </select>
                    </div>

                    <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; padding:12px; font-size:12px; color:#0369a1; line-height:1.4; margin-top:15px; margin-bottom:15px;">
                        💡 <strong>Expected Column Headers:</strong><br>
                        <code>Shop/Club</code>, <code>Type</code>, <code>District</code>, <code>Address Line 1</code>, <code>Address Line 2</code>, <code>City</code>, <code>State</code>, <code>Zip</code>, <code>Primary Contact</code>, <code>Primary Title</code>, <code>Primary Email</code>, <code>Primary Phone</code>, <code>Additional Contact</code>, <code>Additional Title</code>, <code>Additional Email</code>, <code>Additional Phone</code>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                        <button type="button" class="sywat-btn sywat-btn-secondary" onclick="closeImportShopsModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-primary">Upload & Generate Preview</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- CREATE SHOP MODAL -->
<div id="addShopModal" class="sywat-modal-backdrop">
    <div class="sywat-modal-content sywat-modal-md">
        <div class="sywat-modal-header no-print">
            <h3>Create New Location</h3>
            <button type="button" class="sywat-modal-close" onclick="closeAddShopModal()">&times;</button>
        </div>
        <div class="sywat-modal-body">
            <form method="post" action="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>">
                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                <input type="hidden" name="sywat_shop_action" value="create_shop">
                
                <!-- Side-by-Side 3-Column Grid for Name, Type, and District -->
                <div class="sywat-form-grid-3">
                    <div class="sywat-field-group">
                        <label>Location Name *</label>
                        <input type="text" name="shop_name" placeholder="e.g. Cinnamon Creek Ranch" required>
                    </div>
                    <div class="sywat-field-group">
                        <label>Location Type *</label>
                        <select name="location_type" required>
                            <option value="pro_shop" <?php selected( $type_filter, 'pro_shop' ); ?>>🎯 Shop</option>
                            <option value="club" <?php selected( $type_filter, 'club' ); ?>>🏹 Club</option>
                        </select>
                    </div>
                    <div class="sywat-field-group">
                        <label>District</label>
                        <select name="shop_district">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ( $districts as $key => $label ): ?>
                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="sywat-form-grid-2" style="margin-top:12px;">
                    <div class="sywat-field-group">
                        <label>Primary Contact Name</label>
                        <input type="text" name="shop_contact" placeholder="e.g. Beau Brennan">
                    </div>
                    <div class="sywat-field-group">
                        <label>Primary Contact Phone</label>
                        <input type="text" name="shop_phone" class="sywat-phone-input" placeholder="817-439-8998">
                    </div>
                </div>

                <div class="sywat-field-group" style="margin-top:12px;">
                    <label>Primary Contact Email</label>
                    <input type="email" name="shop_email" placeholder="e.g. events@cinnamoncreekranch.com">
                </div>

                <!-- Facilities Multi-Select -->
                <div style="background:#f8fafc; padding:12px; border:1px solid #cbd5e1; border-radius:6px; margin-top:12px; margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; font-size:12px; color:#0f172a; margin-bottom:8px;">Facilities & Range Amenities</label>
                    <div class="sywat-form-grid-2" style="gap:8px;">
                        <label style="font-size:12px; display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" name="facilities[]" value="pro_shop"> 🏪 Pro Shop & Retail
                        </label>
                        <label style="font-size:12px; display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" name="facilities[]" value="indoor_range" checked> 🎯 Indoor Target Range
                        </label>
                        <label style="font-size:12px; display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" name="facilities[]" value="outdoor_field"> 🌲 Outdoor Field Course
                        </label>
                        <label style="font-size:12px; display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" name="facilities[]" value="3d_range"> 🦌 3D Animal Range
                        </label>
                    </div>
                </div>

                <hr style="border:none; border-top:1px dashed #ccc; margin:15px 0;">
                <span style="font-size:12px; font-weight:bold; color:#646970; display:block; margin-bottom:10px;">LOCATION ADDRESS DETAILS (OPTIONAL)</span>

                <div class="sywat-field-group">
                    <label>Address Line 1</label>
                    <input type="text" name="shop_address_1" placeholder="e.g. 13795 Old Denton Rd">
                </div>
                <div class="sywat-field-group" style="margin-top:12px;">
                    <label>Address Line 2</label>
                    <input type="text" name="shop_address_2" placeholder="Suite, Bldg, etc.">
                </div>
                <div class="sywat-form-grid-3" style="margin-top:12px;">
                    <div class="sywat-field-group">
                        <label>City</label>
                        <input type="text" name="shop_city" placeholder="e.g. Roanoke">
                    </div>
                    <div class="sywat-field-group">
                        <label>State</label>
                        <select name="shop_state">
                            <option value="">-- Select State --</option>
                            <option value="TX">TX - Texas</option>
                            <option value="OK">OK - Oklahoma</option>
                            <option value="LA">LA - Louisiana</option>
                        </select>
                    </div>
                    <div class="sywat-field-group">
                        <label>ZIP</label>
                        <input type="text" name="shop_zip" placeholder="e.g. 76262">
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeAddShopModal()">Cancel</button>
                    <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-add">Create Profile</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openImportShopsModal() {
    var modal = document.getElementById('importShopsModal');
    if (modal) modal.style.display = 'flex';
}
function closeImportShopsModal() {
    var modal = document.getElementById('importShopsModal');
    if (modal) modal.style.display = 'none';
}

<?php if ( $has_preview ): ?>
document.addEventListener('DOMContentLoaded', function() {
    openImportShopsModal();
});
<?php endif; ?>
</script>
