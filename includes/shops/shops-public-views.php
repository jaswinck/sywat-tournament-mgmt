<?php
/**
 * Sub-Module: Public Archery Shops & Clubs Directory View
 * Path: includes/shops/shops-public-views.php
 * Author: SYWAT Archery / TFAA
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render Public Read-Only Directory Layout
 */
if ( ! function_exists( 'sywat_render_public_shops_directory' ) ) {
    function sywat_render_public_shops_directory() {
        $page_url    = strtok( $_SERVER['REQUEST_URI'], '?' );
        $all_shops   = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();
        $type_filter = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'all';

        $districts = array(
            'District 1' => 'District 1 (Panhandle / West Texas)',
            'District 2' => 'District 2 (North Texas / DFW)',
            'District 3' => 'District 3 (East Texas)',
            'District 4' => 'District 4 (Central / Hill Country)',
            'District 5' => 'District 5 (South Texas / Gulf)',
        );

        // Filter shops by category tab
        $filtered_shops = array();
        foreach ( $all_shops as $s_id => $shop ) {
            $s_type = isset($shop['location_type']) ? $shop['location_type'] : 'pro_shop';
            if ( $type_filter === 'all' || $s_type === $type_filter ) {
                $filtered_shops[$s_id] = $shop;
            }
        }

        ob_start();
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
                        <div style="display:flex; gap:5px; margin:0; align-items:center;">
                            <input type="text" id="sywat_shop_search_input" placeholder="Search name..." autocomplete="off" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:180px; height:32px; box-sizing:border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height:32px;" onclick="document.getElementById('sywat_shop_search_input').value=''; document.getElementById('sywat_district_filter_select').value=''; sywatFilterShops();">Clear</button>
                        </div>
                    </div>
                </div>

                <?php if ( empty( $filtered_shops ) ): ?>
                    <p style="color:#666; padding:15px 0;">No archery locations found matching this category.</p>
                <?php else: ?>
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th style="width:32%;">Location & Address</th>
                                <th style="width:14%;">District</th>
                                <th style="width:24%;">Range Amenities</th>
                                <th style="width:18%;">Contact Info</th>
                                <th style="width:12%;">Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $filtered_shops as $s_id => $shop ): ?>
                                <?php 
                                    $s_contact = isset($shop['contact']) ? $shop['contact'] : '';
                                    $s_dist    = isset($shop['district']) ? $shop['district'] : '';
                                    $s_fac     = isset($shop['facilities']) && is_array($shop['facilities']) ? $shop['facilities'] : array();
                                    $s_type    = isset($shop['location_type']) ? $shop['location_type'] : 'pro_shop';

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
                                <tr class="sywat-shop-row" data-name="<?php echo esc_attr(strtolower($shop['name'])); ?>" data-id="<?php echo esc_attr(strtolower($s_id)); ?>" data-contact="<?php echo esc_attr(strtolower($s_contact)); ?>" data-district="<?php echo esc_attr(strtolower($s_dist)); ?>">
                                    <td>
                                        <strong style="font-size:15px; color:#0f172a;"><?php echo esc_html( $shop['name'] ); ?></strong>
                                        <?php if ($type_filter === 'all'): ?>
                                            <span class="sywat-badge" style="background:<?php echo ($s_type === 'club') ? '#fef3c7; color:#b45309;' : '#e0f2fe; color:#0369a1;'; ?> font-size:10px; padding:2px 6px; margin-left:4px;">
                                                <?php echo ($s_type === 'club') ? '🌲 Club' : '🏪 Shop'; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $full_address ) ): ?>
                                            <div style="font-size:12px; color:#64748b; margin-top:4px; line-height:1.3;">
                                                📍 <?php echo $full_address; ?>
                                            </div>
                                        <?php endif; ?>
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
                                        <strong><?php echo esc_html( $s_contact ? $s_contact : 'N/A' ); ?></strong><br>
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
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <script>
        function sywatFilterShops() {
            const searchVal = (document.getElementById('sywat_shop_search_input')?.value || '').toLowerCase().trim();
            const distVal = (document.getElementById('sywat_district_filter_select')?.value || '').toLowerCase().trim();
            const rows = document.querySelectorAll('.sywat-shop-row');

            rows.forEach(row => {
                const name = (row.getAttribute('data-name') || '').toLowerCase();
                const contact = (row.getAttribute('data-contact') || '').toLowerCase();
                const shopId = (row.getAttribute('data-id') || '').toLowerCase();
                const district = (row.getAttribute('data-district') || '').toLowerCase();

                const matchesSearch = !searchVal || name.includes(searchVal) || contact.includes(searchVal) || shopId.includes(searchVal);
                let matchesDistrict = false;

                if (!distVal) {
                    matchesDistrict = true;
                } else if (distVal === 'unassigned') {
                    matchesDistrict = !district || district === 'unassigned';
                } else {
                    matchesDistrict = district === distVal;
                }

                if (matchesSearch && matchesDistrict) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.getElementById('sywat_shop_search_input');
            if (searchInput) searchInput.addEventListener('input', sywatFilterShops);

            const distSelect = document.getElementById('sywat_district_filter_select');
            if (distSelect) distSelect.addEventListener('change', sywatFilterShops);
        });
        </script>
        <?php
        return ob_get_clean();
    }
}

/**
 * Shortcode Handler: [sywat_public_shops]
 */
if ( ! function_exists( 'sywat_public_shops_shortcode' ) ) {
    function sywat_public_shops_shortcode() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        return sywat_render_public_shops_directory();
    }
    add_shortcode( 'sywat_public_shops', 'sywat_public_shops_shortcode' );
}
