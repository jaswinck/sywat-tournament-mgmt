<?php
/**
 * View Template: Public Archery Shops & Clubs
 * Path: includes/shops/views/view-shop-public.php
 * Author: SYWAT Archery / TFAA
 * Version: 6.8.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$page_url    = strtok( $_SERVER['REQUEST_URI'], '?' );
$all_shops   = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
$type_filter = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : 'all';

$districts = array(
    'District 1' => 'District 1 (West TX / Panhandle)',
    'District 2' => 'District 2 (Vacant)',
    'District 3' => 'District 3 (Southeast TX)',
    'District 4' => 'District 4 (South TX)',
    'District 5' => 'District 5 (DFW Area)',
    'District 6' => 'District 6 (Central TX)',
    'District 7' => 'District 7 (East TX / LA)',
);

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

<div class="sywat-wrap">
    <!-- Sub-Tab Navigation Bar -->
    <div class="sywat-nav-tabs no-print">
        <a href="<?php echo esc_url( add_query_arg( 'type', 'all', $page_url ) ); ?>" class="sywat-tab-item <?php echo ( $type_filter === 'all' ) ? 'active' : ''; ?>">
            🌐 All Locations
        </a>
        <a href="<?php echo esc_url( add_query_arg( 'type', 'pro_shop', $page_url ) ); ?>" class="sywat-tab-item <?php echo ( $type_filter === 'pro_shop' ) ? 'active' : ''; ?>">
            🎯 Shops
        </a>
        <a href="<?php echo esc_url( add_query_arg( 'type', 'club', $page_url ) ); ?>" class="sywat-tab-item <?php echo ( $type_filter === 'club' ) ? 'active' : ''; ?>">
            🏹 Clubs
        </a>
    </div>

    <div class="sywat-card">
        <!-- UNIFIED TOP CARD HEADER BAR: TITLE (LEFT) & INLINE FILTERS (RIGHT) -->
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
                        <input type="text" id="sywat_shop_search_input" class="sywat-filter-input-md" placeholder="Search by name or contact..." autocomplete="off">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" id="sywat_shop_clear_btn">Clear</button>
                    </div>
                </div>
            </div>
        </div>

        <?php if ( empty( $filtered_shops ) ): ?>
            <p class="sywat-empty-state-notice">No archery locations found matching this category.</p>
        <?php else: ?>
            <div class="sywat-table-responsive">
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th class="sywat-col-w-32">Location &amp; Address</th>
                            <th class="sywat-col-w-12">District</th>
                            <th class="sywat-col-w-22">Range Amenities</th>
                            <th class="sywat-col-left">Contacts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $filtered_shops as $s_id => $shop ): ?>
                            <?php
                                $s_dist = isset( $shop['district'] ) ? $shop['district'] : '';
                                $s_fac  = isset( $shop['facilities'] ) && is_array( $shop['facilities'] ) ? $shop['facilities'] : array();
                                $s_type = isset( $shop['location_type'] ) ? $shop['location_type'] : 'pro_shop';

                                // Build unified contacts array (Primary + Additional)
                                $all_contacts = array();
                                if ( ! empty( $shop['contact'] ) || ! empty( $shop['email'] ) || ! empty( $shop['phone'] ) ) {
                                    $all_contacts[] = array(
                                        'name'  => isset( $shop['contact'] ) ? $shop['contact'] : '',
                                        'title' => isset( $shop['contact_title'] ) ? $shop['contact_title'] : '',
                                        'email' => isset( $shop['email'] ) ? $shop['email'] : '',
                                        'phone' => isset( $shop['phone'] ) ? $shop['phone'] : '',
                                    );
                                }
                                $s_add_cnt = isset( $shop['additional_contacts'] ) && is_array( $shop['additional_contacts'] ) ? $shop['additional_contacts'] : array();
                                foreach ( $s_add_cnt as $ac ) {
                                    if ( ! empty( $ac['name'] ) || ! empty( $ac['email'] ) || ! empty( $ac['phone'] ) ) {
                                        $all_contacts[] = $ac;
                                    }
                                }

                                // Search string for live JS search
                                $search_terms = array();
                                foreach ( $all_contacts as $c ) {
                                    $search_terms[] = isset( $c['name'] ) ? $c['name'] : '';
                                    $search_terms[] = isset( $c['title'] ) ? $c['title'] : '';
                                    $search_terms[] = isset( $c['email'] ) ? $c['email'] : '';
                                    $search_terms[] = isset( $c['phone'] ) ? $c['phone'] : '';
                                }
                                $cnt_search_str = implode( ' ', array_filter( $search_terms ) );

                                // Address formatting
                                $addr1 = isset( $shop['address_1'] ) ? $shop['address_1'] : '';
                                $addr2 = isset( $shop['address_2'] ) ? $shop['address_2'] : '';
                                $city  = isset( $shop['city'] ) ? $shop['city'] : '';
                                $state = isset( $shop['state'] ) ? $shop['state'] : '';
                                $zip   = isset( $shop['zip'] ) ? $shop['zip'] : '';

                                $addr_parts = array();
                                if ( $addr1 ) $addr_parts[] = $addr1;
                                if ( $addr2 ) $addr_parts[] = $addr2;
                                $csz = trim( $city . ( $city && $state ? ', ' : '' ) . $state . ' ' . $zip );
                                if ( $csz ) $addr_parts[] = $csz;
                                $full_address = implode( '<br>', array_map( 'esc_html', $addr_parts ) );
                            ?>
                            <tr class="sywat-shop-row" data-name="<?php echo esc_attr( strtolower( $shop['name'] ) ); ?>" data-id="<?php echo esc_attr( strtolower( $s_id ) ); ?>" data-contact="<?php echo esc_attr( strtolower( $cnt_search_str ) ); ?>" data-district="<?php echo esc_attr( strtolower( $s_dist ) ); ?>">
                                <td>
                                    <strong class="sywat-tourn-title"><?php echo esc_html( $shop['name'] ); ?></strong>
                                    <?php if ( $type_filter === 'all' ): ?>
                                        <span class="sywat-badge <?php echo ( $s_type === 'club' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg'; ?>">
                                            <?php echo ( $s_type === 'club' ) ? '🏹 Club' : '🎯 Shop'; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $full_address ) ): ?>
                                        <div class="sywat-tourn-dates">📍 <?php echo $full_address; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( ! empty( $s_dist ) ): ?>
                                        <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $s_dist ); ?></span>
                                    <?php else: ?>
                                        <span class="sywat-no-data-tag">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="sywat-badge-group">
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
                                <td class="sywat-col-left">
                                    <?php if ( empty( $all_contacts ) ): ?>
                                        <span class="sywat-no-data-tag">No contact info available</span>
                                    <?php else: ?>
                                        <?php foreach ( $all_contacts as $c ): ?>
                                            <div class="sywat-mb-4">
                                                <?php if ( ! empty( $c['name'] ) ): ?>
                                                    <strong><?php echo esc_html( $c['name'] ); ?></strong>
                                                    <?php if ( ! empty( $c['title'] ) ): ?>
                                                        <span class="sywat-meta-subtext">(<?php echo esc_html( $c['title'] ); ?>)</span>
                                                    <?php endif; ?>
                                                    <br>
                                                <?php endif; ?>

                                                <?php if ( ! empty( $c['email'] ) ): ?>
                                                    <div>📧 <a class="sywat-table-link" href="mailto:<?php echo esc_attr( $c['email'] ); ?>"><?php echo esc_html( $c['email'] ); ?></a></div>
                                                <?php endif; ?>

                                                <?php if ( ! empty( $c['phone'] ) ): ?>
                                                    <div>📞 <?php echo esc_html( $c['phone'] ); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
