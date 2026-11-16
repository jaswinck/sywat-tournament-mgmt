<?php
/**
 * View Template: Admin Shop Directory & Creation / Import Modals
 * Path: includes/shops/views/view-shop-directory.php
 * Description: Renders the administrative Shop & Club directory table, 2-step CSV/XLSX import modal, and Create New Location modal with repeatable Default Online Registration Links.
 * Author: SYWAT Archery / TFAA
 * Version: 6.9.0
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
    $s_type = isset( $shop['location_type'] ) ? $shop['location_type'] : 'pro_shop';
    if ( $type_filter === 'all' || $s_type === $type_filter ) {
        $filtered_shops[ $s_id ] = $shop;
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
                if ( $type_filter === 'pro_shop' ) echo '🎯 Shops';
                elseif ( $type_filter === 'club' ) echo '🏹 Clubs';
                else echo '🌐 All Locations';
            ?>
        </h2>

        <div class="sywat-header-inline-controls">
            <!-- Clean Inline District Filter -->
            <div class="sywat-field-group">
                <label for="sywat_district_filter_select">District:</label>
                <select id="sywat_district_filter_select" class="sywat-filter-select-md">
                    <option value="">All Districts</option>
                    <option value="unassigned">Unassigned Only</option>
                    <?php foreach ( $districts as $key => $label ): ?>
                        <option value="<?php echo esc_attr( strtolower( $key ) ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Clean Inline Search & Clear -->
            <div class="sywat-field-group">
                <label for="sywat_shop_search_input">Search Location:</label>
                <div class="sywat-search-input-group">
                    <input type="text" id="sywat_shop_search_input" class="sywat-filter-input-md" placeholder="Search by shop or contact..." autocomplete="off">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" id="sywat_shop_clear_btn">Clear</button>
                </div>
            </div>

            <?php if ( $can_manage_seasons || $is_admin ): ?>
                <div class="sywat-table-header-actions">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-export" id="sywat_open_import_shops_btn">Import Locations</button>
                    <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" id="sywat_open_add_shop_btn">Add Location</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( empty( $filtered_shops ) ): ?>
        <p class="sywat-empty-state-notice">No locations found matching this view category.</p>
    <?php else: ?>
        <div class="sywat-table-responsive">
            <table class="sywat-table">
                <thead>
                    <tr>
                        <th class="sywat-col-w-24">Location Name &amp; ID</th>
                        <th class="sywat-col-w-10">District</th>
                        <th class="sywat-col-w-10">Facilities</th>
                        <th class="sywat-col-w-22">Contact Info</th>
                        <th class="sywat-col-w-14">Phone</th>
                        <th class="sywat-col-center sywat-col-w-20">Actions</th>
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
                            $s_contact = isset( $shop['contact'] ) ? $shop['contact'] : '';
                            $s_dist    = isset( $shop['district'] ) ? $shop['district'] : '';
                            $s_fac     = isset( $shop['facilities'] ) && is_array( $shop['facilities'] ) ? $shop['facilities'] : array();
                            $s_type    = isset( $shop['location_type'] ) ? $shop['location_type'] : 'pro_shop';
                            $s_add_cnt = isset( $shop['additional_contacts'] ) && is_array( $shop['additional_contacts'] ) ? $shop['additional_contacts'] : array();

                            $all_cnt_names  = array_filter( array_merge( array( $s_contact ), array_column( $s_add_cnt, 'name' ) ) );
                            $cnt_search_str = implode( ' ', $all_cnt_names );
                        ?>
                        <tr class="sywat-shop-row" data-name="<?php echo esc_attr( strtolower( $shop['name'] ) ); ?>" data-id="<?php echo esc_attr( strtolower( $s_id ) ); ?>" data-contact="<?php echo esc_attr( strtolower( $cnt_search_str ) ); ?>" data-district="<?php echo esc_attr( strtolower( $s_dist ) ); ?>">
                            <td>
                                <strong><?php echo esc_html( $shop['name'] ); ?></strong>
                                <?php if ( $type_filter === 'all' ): ?>
                                    <span class="sywat-badge <?php echo ( $s_type === 'club' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg'; ?>">
                                        <?php echo ( $s_type === 'club' ) ? '🏹 Club' : '🎯 Shop'; ?>
                                    </span>
                                <?php endif; ?>
                                <div class="sywat-meta-subtext"><?php echo esc_html( $s_id ); ?></div>
                            </td>
                            <td>
                                <?php if ( ! empty( $s_dist ) ): ?>
                                    <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $s_dist ); ?></span>
                                <?php else: ?>
                                    <span class="sywat-no-data-tag">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="sywat-badge-group-vertical">
                                    <?php if ( in_array( 'pro_shop', $s_fac, true ) ): ?>
                                        <span class="sywat-badge sywat-badge-prereg">🏪 Pro Shop</span>
                                    <?php endif; ?>
                                    <?php if ( in_array( 'indoor_range', $s_fac, true ) ): ?>
                                        <span class="sywat-badge sywat-badge-open">🎯 Indoor</span>
                                    <?php endif; ?>
                                    <?php if ( in_array( 'outdoor_field', $s_fac, true ) ): ?>
                                        <span class="sywat-badge sywat-badge-walkin">🌲 Field</span>
                                    <?php endif; ?>
                                    <?php if ( in_array( '3d_range', $s_fac, true ) ): ?>
                                        <span class="sywat-badge sywat-badge-registered">🦌 3D</span>
                                    <?php endif; ?>
                                    <?php if ( empty( $s_fac ) ): ?>
                                        <span class="sywat-no-data-tag">None listed</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo esc_html( $s_contact ? $s_contact : 'N/A' ); ?></strong>
                                <?php if ( ! empty( $s_add_cnt ) ): ?>
                                    <?php
                                        $more_titles = array();
                                        foreach ( $s_add_cnt as $ac ) {
                                            $more_titles[] = ( $ac['name'] ?? 'Contact' ) . ' (' . ( $ac['title'] ?? 'Staff' ) . ') - ' . ( $ac['email'] ?? 'No email' );
                                        }
                                    ?>
                                    <span class="sywat-badge sywat-badge-muted" title="<?php echo esc_attr( implode( "\n", $more_titles ) ); ?>">
                                        +<?php echo count( $s_add_cnt ); ?> more
                                    </span>
                                <?php endif; ?>
                                <br>
                                <?php
                                    $raw_email = isset( $shop['email'] ) ? $shop['email'] : '';
                                    if ( ! empty( $raw_email ) ) {
                                        $email_parts = preg_split( '/[\s,\/]+/', $raw_email );
                                        foreach ( $email_parts as $e_part ) {
                                            $e_clean = trim( $e_part );
                                            if ( $e_clean ) {
                                                echo '<div><a class="sywat-table-link" href="mailto:' . esc_attr( $e_clean ) . '">' . esc_html( $e_clean ) . '</a></div>';
                                            }
                                        }
                                    } else {
                                        echo '<span class="sywat-no-data-tag">No email</span>';
                                    }
                                ?>
                            </td>
                            <td><?php echo esc_html( isset( $shop['phone'] ) ? $shop['phone'] : 'N/A' ); ?></td>
                            <td class="sywat-col-center">
                                <div class="sywat-table-actions-vertical">
                                    <?php if ( $can_manage_seasons || $is_admin ): ?>
                                        <a href="<?php echo esc_url( add_query_arg( array( 'manage_staff' => $s_id, 'type' => $type_filter ), $page_url ) ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-full sywat-btn-icon-manage">Staff (<?php echo $linked_count; ?>)</a>
                                        <a href="<?php echo esc_url( add_query_arg( array( 'edit_shop' => $s_id, 'type' => $type_filter ), $page_url ) ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-full sywat-btn-icon-edit">Edit</a>
                                        <form method="post" action="<?php echo esc_url( add_query_arg( 'type', $type_filter, $page_url ) ); ?>" class="sywat-table-action-form" data-sywat-confirm="Are you sure you want to delete this location?">
                                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                            <input type="hidden" name="sywat_shop_action" value="delete_shop">
                                            <input type="hidden" name="shop_id" value="<?php echo esc_attr( $s_id ); ?>">
                                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-full sywat-btn-icon-delete">Delete</button>
                                        </form>
                                    <?php elseif ( ! empty( $user_shop_id ) && $s_id === $user_shop_id ): ?>
                                        <a href="<?php echo esc_url( add_query_arg( array( 'manage_staff' => $s_id, 'type' => $type_filter ), $page_url ) ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-full sywat-btn-icon-manage">Staff (<?php echo $linked_count; ?>)</a>
                                        <a href="<?php echo esc_url( add_query_arg( array( 'edit_shop' => $s_id, 'type' => $type_filter ), $page_url ) ); ?>" class="sywat-btn sywat-btn-success sywat-btn-full sywat-btn-icon-edit">Edit My Shop</a>
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
<div id="importShopsModal" class="sywat-modal-backdrop <?php echo $has_preview ? 'active' : ''; ?>">
    <div class="sywat-modal-content <?php echo $has_preview ? 'sywat-modal-lg' : 'sywat-modal-md'; ?>">
        <div class="sywat-modal-header no-print">
            <h3>📥 <?php echo $has_preview ? 'Review Import Preview' : 'Import Locations &amp; Contacts'; ?></h3>
            <button type="button" class="sywat-modal-close" id="sywat_close_import_shops_btn" aria-label="Close Import Locations Modal">&times;</button>
        </div>
        <div class="sywat-modal-body">
            <?php if ( $has_preview ): ?>
                <!-- STAGE 2: DRY-RUN PREVIEW TABLE & CONFIRMATION -->
                <div class="sywat-alert sywat-alert-info">
                    <strong>File:</strong> <code><?php echo esc_html( $import_preview['file_name'] ); ?></code>
                    <span>(Mode: <?php echo esc_html( $import_preview['mode'] === 'skip_existing' ? 'Add New Only' : 'Safe Merge & Update' ); ?>)</span>
                    <div class="sywat-badge-group sywat-mt-3">
                        <span class="sywat-badge sywat-badge-open">🟢 <?php echo intval( $import_preview['summary']['new'] ); ?> New</span>
                        <span class="sywat-badge sywat-badge-walkin">🟡 <?php echo intval( $import_preview['summary']['update'] ); ?> Updates</span>
                        <?php if ( $import_preview['summary']['skip'] > 0 ): ?>
                            <span class="sywat-badge sywat-badge-muted">⚪ <?php echo intval( $import_preview['summary']['skip'] ); ?> Skipped</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sywat-table-responsive sywat-mb-4">
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th class="sywat-col-w-14">Action</th>
                                <th class="sywat-col-w-24">Location Name</th>
                                <th class="sywat-col-w-10">Type</th>
                                <th class="sywat-col-w-12">District</th>
                                <th class="sywat-col-w-20">Address</th>
                                <th class="sywat-col-w-20">Primary Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $import_preview['items'] as $p_item ): ?>
                                <tr>
                                    <td>
                                        <?php if ( $p_item['status'] === 'new' ): ?>
                                            <span class="sywat-badge sywat-badge-open">🟢 Create New</span>
                                        <?php elseif ( $p_item['status'] === 'update' ): ?>
                                            <span class="sywat-badge sywat-badge-walkin">🟡 Update</span>
                                        <?php else: ?>
                                            <span class="sywat-badge sywat-badge-muted">⚪ Skipped</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo esc_html( $p_item['name'] ); ?></strong></td>
                                    <td>
                                        <span class="sywat-badge <?php echo ( $p_item['location_type'] === 'club' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg'; ?>">
                                            <?php echo ( $p_item['location_type'] === 'club' ) ? '🏹 Club' : '🎯 Shop'; ?>
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
                                            <div class="sywat-meta-subtext"><?php echo esc_html( $p_item['phone'] ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sywat-modal-footer">
                    <form method="post" action="<?php echo esc_url( add_query_arg( 'type', $type_filter, $page_url ) ); ?>">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_shop_action" value="cancel_import_shops">
                        <button type="submit" class="sywat-btn sywat-btn-secondary">Cancel &amp; Start Over</button>
                    </form>
                    <form method="post" action="<?php echo esc_url( add_query_arg( 'type', $type_filter, $page_url ) ); ?>">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_shop_action" value="confirm_import_shops">
                        <button type="submit" class="sywat-btn sywat-btn-primary">Confirm &amp; Import <?php echo count( $import_preview['items'] ); ?> Locations</button>
                    </form>
                </div>

            <?php else: ?>
                <!-- STAGE 1: FILE UPLOAD FORM -->
                <form method="post" action="<?php echo esc_url( add_query_arg( 'type', $type_filter, $page_url ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                    <input type="hidden" name="sywat_shop_action" value="preview_import_shops">

                    <p class="sywat-subtitle sywat-mb-4">
                        Upload an Excel (<code>.xlsx</code> / <code>.xls</code>) or <code>.csv</code> file containing your master shop directory and contact details.
                    </p>

                    <div class="sywat-field-group">
                        <label for="sywat_shop_import_file">Select File (.xlsx, .xls, .csv) *</label>
                        <input type="file" id="sywat_shop_import_file" name="shop_import_file" accept=".xlsx,.xls,.csv" required>
                    </div>

                    <div class="sywat-field-group sywat-mt-3">
                        <label for="sywat_shop_import_mode">Import Mode</label>
                        <select id="sywat_shop_import_mode" name="import_mode">
                            <option value="upsert">Safe Merge &amp; Update (Update Existing &amp; Add New)</option>
                            <option value="skip_existing">Add New Locations Only (Skip Existing)</option>
                        </select>
                    </div>

                    <div class="sywat-alert sywat-alert-info sywat-notice-banner sywat-mt-3">
                        💡 <strong>Expected Column Headers:</strong><br>
                        <code>Shop/Club</code>, <code>Type</code>, <code>District</code>, <code>Address Line 1</code>, <code>Address Line 2</code>, <code>City</code>, <code>State</code>, <code>Zip</code>, <code>Primary Contact</code>, <code>Primary Title</code>, <code>Primary Email</code>, <code>Primary Phone</code>, <code>Additional Contact</code>, <code>Additional Title</code>, <code>Additional Email</code>, <code>Additional Phone</code>
                    </div>

                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary" id="sywat_cancel_import_shops_btn">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-primary">Upload &amp; Generate Preview</button>
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
            <button type="button" class="sywat-modal-close" id="sywat_close_add_shop_btn" aria-label="Close Create Location Modal">&times;</button>
        </div>
        <div class="sywat-modal-body">
            <form method="post" action="<?php echo esc_url( add_query_arg( 'type', $type_filter, $page_url ) ); ?>">
                <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                <input type="hidden" name="sywat_shop_action" value="create_shop">

                <!-- Side-by-Side 3-Column Grid for Name, Type, and District -->
                <div class="sywat-form-grid-3">
                    <div class="sywat-field-group">
                        <label for="sywat_new_shop_name">Location Name *</label>
                        <input type="text" id="sywat_new_shop_name" name="shop_name" placeholder="e.g. Cinnamon Creek Ranch" required>
                    </div>
                    <div class="sywat-field-group">
                        <label for="sywat_new_location_type">Location Type *</label>
                        <select id="sywat_new_location_type" name="location_type" required>
                            <option value="pro_shop" <?php selected( $type_filter, 'pro_shop' ); ?>>🎯 Shop</option>
                            <option value="club" <?php selected( $type_filter, 'club' ); ?>>🏹 Club</option>
                        </select>
                    </div>
                    <div class="sywat-field-group">
                        <label for="sywat_new_shop_district">District</label>
                        <select id="sywat_new_shop_district" name="shop_district">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ( $districts as $key => $label ): ?>
                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="sywat-form-grid-2 sywat-mt-3">
                    <div class="sywat-field-group">
                        <label for="sywat_new_shop_contact">Primary Contact Name</label>
                        <input type="text" id="sywat_new_shop_contact" name="shop_contact" placeholder="e.g. Beau Brennan">
                    </div>
                    <div class="sywat-field-group">
                        <label for="sywat_new_shop_phone">Primary Contact Phone</label>
                        <input type="text" id="sywat_new_shop_phone" name="shop_phone" class="sywat-phone-input" placeholder="817-439-8998">
                    </div>
                </div>

                <div class="sywat-field-group sywat-mt-3">
                    <label for="sywat_new_shop_email">Primary Contact Email</label>
                    <input type="email" id="sywat_new_shop_email" name="shop_email" placeholder="e.g. events@cinnamoncreekranch.com">
                </div>

                <!-- Facilities Multi-Select -->
                <div class="sywat-field-group sywat-mt-3">
                    <label>Facilities &amp; Range Amenities</label>
                    <div class="sywat-form-grid-2">
                        <label>
                            <input type="checkbox" name="facilities[]" value="pro_shop"> 🏪 Pro Shop &amp; Retail
                        </label>
                        <label>
                            <input type="checkbox" name="facilities[]" value="indoor_range" checked> 🎯 Indoor Target Range
                        </label>
                        <label>
                            <input type="checkbox" name="facilities[]" value="outdoor_field"> 🌲 Outdoor Field Course
                        </label>
                        <label>
                            <input type="checkbox" name="facilities[]" value="3d_range"> 🦌 3D Animal Range
                        </label>
                    </div>
                </div>

                <hr>
                <div class="sywat-subtitle sywat-mb-4">LOCATION ADDRESS DETAILS (OPTIONAL)</div>

                <div class="sywat-field-group">
                    <label for="sywat_new_shop_address_1">Address Line 1</label>
                    <input type="text" id="sywat_new_shop_address_1" name="shop_address_1" placeholder="e.g. 13795 Old Denton Rd">
                </div>
                <div class="sywat-field-group sywat-mt-3">
                    <label for="sywat_new_shop_address_2">Address Line 2</label>
                    <input type="text" id="sywat_new_shop_address_2" name="shop_address_2" placeholder="Suite, Bldg, etc.">
                </div>
                <div class="sywat-form-grid-3 sywat-mt-3">
                    <div class="sywat-field-group">
                        <label for="sywat_new_shop_city">City</label>
                        <input type="text" id="sywat_new_shop_city" name="shop_city" placeholder="e.g. Roanoke">
                    </div>
                    <div class="sywat-field-group">
                        <label for="sywat_new_shop_state">State</label>
                        <select id="sywat_new_shop_state" name="shop_state">
                            <option value="">-- Select State --</option>
                            <option value="TX">TX - Texas</option>
                            <option value="OK">OK - Oklahoma</option>
                            <option value="LA">LA - Louisiana</option>
                        </select>
                    </div>
                    <div class="sywat-field-group">
                        <label for="sywat_new_shop_zip">ZIP</label>
                        <input type="text" id="sywat_new_shop_zip" name="shop_zip" placeholder="e.g. 76262">
                    </div>
                </div>

                <!-- Default External Online Registration Links Repeater -->
                <hr class="sywat-mt-4 sywat-mb-4">
                <div class="sywat-table-header-bar">
                    <div>
                        <div class="sywat-subtitle">🔗 DEFAULT ONLINE REGISTRATION LINKS (OPTIONAL)</div>
                        <div class="sywat-meta-subtext">Configure external registration links (such as FareHarbor) that auto-fill when creating tournaments for this location.</div>
                    </div>
                    <div class="sywat-table-header-actions">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" data-sywat-action="add-create-shop-reg-link">Add Registration Link</button>
                    </div>
                </div>
                <div id="sywat_create_shop_reg_links_container" class="sywat-mb-4"></div>
                <p id="sywat_create_no_reg_links_msg" class="sywat-empty-state-notice">No external registration links configured. Click "Add Registration Link" above if needed.</p>

                <div class="sywat-modal-footer">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" id="sywat_cancel_add_shop_btn">Cancel</button>
                    <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-add">Create Profile</button>
                </div>
            </form>
        </div>
    </div>
</div>
