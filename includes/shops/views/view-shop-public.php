<?php
/**
 * View Template: Public Archery Shops & Clubs Directory
 * Path: includes/shops/views/view-shop-public.php
 * Version: 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$page_url    = strtok( $_SERVER['REQUEST_URI'], '?' );
$all_shops   = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
$type_filter = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'all';

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
    $s_type = isset($shop['location_type']) ? $shop['location_type'] : 'pro_shop';
    if ( $type_filter === 'all' || $s_type === $type_filter ) {
        $filtered_shops[$s_id] = $shop;
    }
}
?>

<div class="sywat-wrap">
    <!-- Sub-Tab Navigation Bar -->
    <div class="sywat-nav-tabs no-print">
        <a href="<?php echo esc_url( add_query_arg( 'type', 'all', $page_url ) ); ?>" class="sywat-tab-item <?php echo ($type_filter === 'all') ? 'active' : ''; ?>">
            🌐 All Locations
        </a>
        <a href="<?php echo esc_url( add_query_arg( 'type', 'pro_shop', $page_url ) ); ?>" class="sywat-tab-item <?php echo ($type_filter === 'pro_shop') ? 'active' : ''; ?>">
            🏪 Shops
        </a>
        <a href="<?php echo esc_url( add_query_arg( 'type', 'club', $page_url ) ); ?>" class="sywat-tab-item <?php echo ($type_filter === 'club') ? 'active' : ''; ?>">
            🌲 Clubs
        </a>
    </div>

    <div class="sywat-card">
        <div class="sywat-card-title">
            <span>
                <?php 
                    if ($type_filter === 'pro_shop') echo 'TFAA Pro Shops Directory';
                    elseif ($type_filter === 'club') echo 'TFAA Archery Clubs Directory';
                    else echo 'TFAA Shops & Archery Clubs Directory';
                ?>
            </span>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <!-- District Filter Dropdown -->
                <select id="sywat_district_filter_select" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; height:32px;">
                    <option value="">All Districts</option>
                    <option value="unassigned">Unassigned Only</option>
                    <?php foreach ( $districts as $key => $label ): ?>
                        <option value="<?php echo esc_attr( strtolower($key) ); ?>"><?php echo esc_html( $key ); ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Live Search Input -->
                <div style="display:flex; gap:5px; margin:0; align-items:center; flex-wrap:wrap;">
                    <input type="text" id="sywat_shop_search_input" placeholder="Search name..." autocomplete="off" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:180px; height:32px; box-sizing:border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height:32px;" onclick="document.getElementById('sywat_shop_search_input').value=''; document.getElementById('sywat_district_filter_select').value=''; sywatFilterShops();">Clear</button>
                </div>
            </div>
        </div>

        <?php if ( empty( $filtered_shops ) ): ?>
            <p style="color:#666; padding:15px 0;">No archery locations found matching this category.</p>
        <?php else: ?>
            <div class="sywat-table-responsive">
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th style="width:28%; text-align:left;">Location & Address</th>
                            <th style="width:12%; text-align:left;">District</th>
                            <th style="width:22%; text-align:left;">Range Amenities</th>
                            <th style="width:38%; text-align:left;">Contacts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $filtered_shops as $s_id => $shop ): ?>
                            <?php 
                                $s_dist = isset($shop['district']) ? $shop['district'] : '';
                                $s_fac  = isset($shop['facilities']) && is_array($shop['facilities']) ? $shop['facilities'] : array();
                                $s_type = isset($shop['location_type']) ? $shop['location_type'] : 'pro_shop';
                                
                                // Build unified contacts array (Primary + Additional)
                                $all_contacts = array();
                                if ( !empty($shop['contact']) || !empty($shop['email']) || !empty($shop['phone']) ) {
                                    $all_contacts[] = array(
                                        'name'  => isset($shop['contact']) ? $shop['contact'] : '',
                                        'title' => isset($shop['contact_title']) ? $shop['contact_title'] : '',
                                        'email' => isset($shop['email']) ? $shop['email'] : '',
                                        'phone' => isset($shop['phone']) ? $shop['phone'] : ''
                                    );
                                }
                                $s_add_cnt = isset($shop['additional_contacts']) && is_array($shop['additional_contacts']) ? $shop['additional_contacts'] : array();
                                foreach ( $s_add_cnt as $ac ) {
                                    if ( !empty($ac['name']) || !empty($ac['email']) || !empty($ac['phone']) ) {
                                        $all_contacts[] = $ac;
                                    }
                                }

                                // Build search string from all contact data so live search still works
                                $search_terms = array();
                                foreach ($all_contacts as $c) {
                                    $search_terms[] = isset($c['name']) ? $c['name'] : '';
                                    $search_terms[] = isset($c['title']) ? $c['title'] : '';
                                    $search_terms[] = isset($c['email']) ? $c['email'] : '';
                                    $search_terms[] = isset($c['phone']) ? $c['phone'] : '';
                                }
                                $cnt_search_str = implode( ' ', array_filter($search_terms) );

                                // Address formatting
                                $addr1 = isset($shop['address_1']) ? $shop['address_1'] : '';
                                $addr2 = isset($shop['address_2']) ? $shop['address_2'] : '';
                                $city  = isset($shop['city']) ? $shop['city'] : '';
                                $state = isset($shop['state']) ? $shop['state'] : '';
                                $zip   = isset($shop['zip']) ? $shop['zip'] : '';

                                $addr_parts = array();
                                if ($addr1) $addr_parts[] = $addr1;
                                if ($addr2) $addr_parts[] = $addr2;
                                $csz = trim($city . ($city && $state ? ', ' : '') . $state . ' ' . $zip);
                                if ($csz) $addr_parts[] = $csz;
                                $full_address = implode('<br>', array_map('esc_html', $addr_parts));
                            ?>
                            <tr class="sywat-shop-row" data-name="<?php echo esc_attr(strtolower($shop['name'])); ?>" data-id="<?php echo esc_attr(strtolower($s_id)); ?>" data-contact="<?php echo esc_attr(strtolower($cnt_search_str)); ?>" data-district="<?php echo esc_attr(strtolower($s_dist)); ?>">
                                <td style="text-align:left;">
                                    <strong style="font-size:15px; color:#0f172a;"><?php echo esc_html( $shop['name'] ); ?></strong>
                                    <?php if ($type_filter === 'all'): ?>
                                        <span class="sywat-badge" style="background:<?php echo ($s_type === 'club') ? '#fef3c7; color:#b45309;' : '#e0f2fe; color:#0369a1;'; ?> font-size:10px; padding:2px 6px; margin-left:4px;">
                                            <?php echo ($s_type === 'club') ? '🌲 Club' : '🏪 Shop'; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $full_address ) ): ?>
                                        <div style="display:flex; gap:6px; font-size:12px; color:#64748b; margin-top:4px; line-height:1.3;">
                                            <span style="flex-shrink:0;">📍</span>
                                            <div><?php echo $full_address; ?></div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:left;">
                                    <?php if ( ! empty( $s_dist ) ): ?>
                                        <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $s_dist ); ?></span>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:#94a3b8; font-style:italic;">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:left;">
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
                                <td style="text-align:left;">
                                    <?php if ( empty( $all_contacts ) ): ?>
                                        <span style="font-size:12px; color:#94a3b8; font-style:italic;">No contact info available</span>
                                    <?php else: ?>
                                        <div style="display:flex; flex-direction:column; gap:12px; text-align:left;">
                                            <?php foreach ( $all_contacts as $c ): ?>
                                                <div style="font-size:12px; line-height:1.4; text-align:left;">
                                                    <?php if ( !empty($c['name']) ): ?>
                                                        <strong style="color:#0f172a;"><?php echo esc_html($c['name']); ?></strong>
                                                        <?php if ( !empty($c['title']) ): ?>
                                                            <span style="color:#64748b; font-size:11px;">(<?php echo esc_html($c['title']); ?>)</span>
                                                        <?php endif; ?>
                                                        <br>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ( !empty($c['email']) ): ?>
                                                        <span style="color:#64748b; margin-right:4px;">📧</span><a href="mailto:<?php echo esc_attr($c['email']); ?>" style="color:#0073aa; text-decoration:none; word-break:break-all;"><?php echo esc_html($c['email']); ?></a><br>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ( !empty($c['phone']) ): ?>
                                                        <span style="color:#64748b; margin-right:4px;">📞</span><span style="color:#334155;"><?php echo esc_html($c['phone']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
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
