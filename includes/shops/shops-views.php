<?php
/**
 * Sub-Module: Shop Administration Views & Modals
 * Path: includes/shops/shops-views.php
 * Author: SYWAT Archery
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_shops_tab' ) ) {
    function sywat_render_shops_tab() {
        $can_manage = function_exists( 'sywat_can_manage_seasons' ) ? sywat_can_manage_seasons() : current_user_can( 'administrator' );
        if ( ! $can_manage ) {
            echo '<p style="color:red; padding:20px;">Management access required to manage shops.</p>';
            return;
        }

        $notice          = sywat_handle_shop_actions();
        $all_shops       = sywat_get_shops();
        $edit_target_id  = isset( $_REQUEST['edit_shop'] ) ? sanitize_text_field( $_REQUEST['edit_shop'] ) : '';
        $editing_shop    = ( ! empty( $edit_target_id ) && isset( $all_shops[$edit_target_id] ) ) ? $all_shops[$edit_target_id] : null;
        $manage_staff_id = isset( $_REQUEST['manage_staff'] ) ? sanitize_text_field( $_REQUEST['manage_staff'] ) : '';
        $managing_shop   = ( ! empty( $manage_staff_id ) && isset( $all_shops[$manage_staff_id] ) ) ? $all_shops[$manage_staff_id] : null;

        $eligible_users  = get_users( array( 'role__in' => array( 'sywat_shop_manager', 'sywat_shop_user' ), 'orderby' => 'display_name' ) );
        ?>

        <div class="no-print"><?php echo $notice; ?></div>

        <?php if ( $managing_shop ): ?>
            <!-- VIEW 1: MANAGE STAFF LINKED TO SPECIFIC SHOP -->
            <?php 
                $linked_staff = array();
                $unlinked_staff = array();
                foreach ( $eligible_users as $u ) {
                    $u_shop = get_user_meta( $u->ID, 'sywat_shop_id', true );
                    if ( $u_shop === $managing_shop['id'] ) {
                        $linked_staff[] = $u;
                    } else {
                        $unlinked_staff[] = $u;
                    }
                }
            ?>
            <div class="sywat-card no-print">
                <div class="sywat-card-title">
                    <span>Linked Staff for Shop: <strong><?php echo esc_html( $managing_shop['name'] ); ?></strong></span>
                    <a href="?tab=shops" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back">Back to Shops List</a>
                </div>

                <div style="margin-bottom:20px; background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:4px;">
                    <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:bold;">Link Additional Staff User to This Shop</h4>
                    <?php if ( empty( $unlinked_staff ) ): ?>
                        <p style="color:#646970; font-style:italic; margin:0; font-size:13px;">All registered Shop Managers and Shop Users are currently linked to a shop.</p>
                    <?php else: ?>
                        <form method="post" action="?tab=shops&manage_staff=<?php echo urlencode($managing_shop['id']); ?>" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                            <input type="hidden" name="sywat_shop_action" value="assign_staff_to_shop">
                            <input type="hidden" name="target_shop_id" value="<?php echo esc_attr( $managing_shop['id'] ); ?>">
                            
                            <div style="flex:1; min-width:250px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Select Available Staff User</label>
                                <select name="staff_user_id" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                                    <?php foreach ( $unlinked_staff as $un_user ): ?>
                                        <?php 
                                            $curr_s_id = get_user_meta( $un_user->ID, 'sywat_shop_id', true );
                                            $curr_s_name = isset( $all_shops[$curr_s_id]['name'] ) ? $all_shops[$curr_s_id]['name'] : 'Unassigned';
                                        ?>
                                        <option value="<?php echo esc_attr( $un_user->ID ); ?>">
                                            <?php echo esc_html( $un_user->display_name ); ?> (Currently: <?php echo esc_html( $curr_s_name ); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-add">Link User to <?php echo esc_html( $managing_shop['name'] ); ?></button>
                        </form>
                    <?php endif; ?>
                </div>

                <h4 style="margin:15px 0 10px 0; font-size:14px; font-weight:bold;">Currently Linked Staff Accounts (<?php echo count($linked_staff); ?>)</h4>
                <?php if ( empty( $linked_staff ) ): ?>
                    <p style="color:#646970; font-style:italic; margin-bottom:15px;">No Shop Manager or Shop User accounts are currently linked to this location.</p>
                <?php else: ?>
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th>User Display Name</th>
                                <th>Username / Email</th>
                                <th>WP Role</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $linked_staff as $ls_user ): ?>
                                <tr>
                                    <td style="font-weight:bold;"><?php echo esc_html( $ls_user->display_name ); ?></td>
                                    <td><?php echo esc_html( $ls_user->user_login ); ?><br><span style="font-size:11px; color:#646970;"><?php echo esc_html( $ls_user->user_email ); ?></span></td>
                                    <td><span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( implode( ', ', $ls_user->roles ) ); ?></span></td>
                                    <td style="text-align:right;">
                                        <form method="post" action="?tab=shops&manage_staff=<?php echo urlencode($managing_shop['id']); ?>" style="margin:0;" onsubmit="return confirm('Remove <?php echo esc_js( $ls_user->display_name ); ?> from this shop?');">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_shop_action" value="remove_staff_from_shop">
                                            <input type="hidden" name="staff_user_id" value="<?php echo esc_attr( $ls_user->ID ); ?>">
                                            <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="padding:4px 8px; font-size:12px; height:auto;">Unlink Staff</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php elseif ( $editing_shop ): ?>
            <!-- VIEW 2: EDIT SHOP DETAILS & LINE DEFAULT SCHEDULING -->
            <div class="sywat-card no-print">
                <div class="sywat-card-title">
                    <span>Edit Archery Shop: <strong><?php echo esc_html( $editing_shop['name'] ); ?></strong></span>
                    <a href="?tab=shops" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear">Cancel Editing</a>
                </div>

                <form method="post" action="?tab=shops">
                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                    <input type="hidden" name="sywat_shop_action" value="update_shop">
                    <input type="hidden" name="shop_id" value="<?php echo esc_attr( $editing_shop['id'] ); ?>">

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Shop Name *</label>
                            <input type="text" name="shop_name" value="<?php echo esc_attr( $editing_shop['name'] ); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Primary Contact Name(s)</label>
                            <input type="text" name="shop_contact" value="<?php echo esc_attr( isset($editing_shop['contact']) ? $editing_shop['contact'] : '' ); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Contact Email(s)</label>
                            <input type="text" name="shop_email" value="<?php echo esc_attr( isset($editing_shop['email']) ? $editing_shop['email'] : '' ); ?>" placeholder="e.g. events@domain.com, beau@domain.com" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Contact Phone</label>
                            <input type="text" name="shop_phone" class="sywat-phone-input" value="<?php echo esc_attr( isset($editing_shop['phone']) ? $editing_shop['phone'] : '' ); ?>" placeholder="817-439-8998" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        </div>
                    </div>

                    <h4 style="margin:20px 0 10px 0; font-size:15px; font-weight:bold;">Location Address Details (Optional)</h4>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
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
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
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

                    <h4 style="margin:20px 0 10px 0; font-size:15px; font-weight:bold;">Default Shooting Lines & Capacity Templates</h4>
                    <table class="sywat-table" style="margin-bottom:20px;">
                        <thead>
                            <tr>
                                <th>Line Day / Time</th>
                                <th style="width:180px;">Default 20yd Cap</th>
                                <th style="width:180px;">Default Cub Cap</th>
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
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="display:flex; gap:10px;">
                        <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Shop Changes</button>
                        <a href="?tab=shops" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear">Cancel</a>
                    </div>
                </form>
            </div>

        <?php else: ?>
            <!-- VIEW 3: MAIN SHOPS DIRECTORY & MODAL SHOP CREATION -->
            <div class="sywat-card no-print">
                <div class="sywat-card-title">
                    <span>Shop Management</span>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <div style="display:flex; gap:5px; margin:0; align-items:center;">
                            <input type="text" id="sywat_shop_search_input" placeholder="Search shop name..." autocomplete="off" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:180px; height:32px; box-sizing:border-box;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" style="height:32px;" onclick="document.getElementById('sywat_shop_search_input').value=''; document.getElementById('sywat_shop_search_input').dispatchEvent(new Event('input'));">Clear</button>
                        </div>
                        <button type="button" class="sywat-btn sywat-btn-success sywat-btn-icon-add" style="height:32px;" onclick="openAddShopModal()">Add Shop</button>
                    </div>
                </div>

                <?php if ( empty( $all_shops ) ): ?>
                    <p style="color:#666;">No archery shops currently configured.</p>
                <?php else: ?>
                    <table class="sywat-table">
                        <thead>
                            <tr>
                                <th style="width:30%;">Shop Name & ID</th>
                                <th style="width:30%;">Contact Info</th>
                                <th style="width:15%;">Phone</th>
                                <th style="width:25%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $all_shops as $s_id => $shop ): ?>
                                <?php 
                                    $linked_count = 0;
                                    foreach ( $eligible_users as $u ) {
                                        $u_shop = get_user_meta( $u->ID, 'sywat_shop_id', true );
                                        if ( $u_shop === $s_id ) $linked_count++;
                                    }
                                    $s_contact = isset($shop['contact']) ? $shop['contact'] : '';
                                ?>
                                <tr class="sywat-shop-row" data-name="<?php echo esc_attr(strtolower($shop['name'])); ?>" data-id="<?php echo esc_attr(strtolower($s_id)); ?>" data-contact="<?php echo esc_attr(strtolower($s_contact)); ?>">
                                    <td>
                                        <strong><?php echo esc_html( $shop['name'] ); ?></strong><br>
                                        <code style="font-size:11px; color:#646970;"><?php echo esc_html( $s_id ); ?></code>
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
                                                echo '<span style="font-size:12px; color:#94a3b8;">No email provided</span>';
                                            }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html( isset($shop['phone']) ? $shop['phone'] : 'N/A' ); ?></td>
                                    <td>
                                        <div style="display:inline-flex; gap:6px; align-items:center;">
                                            <a href="?tab=shops&manage_staff=<?php echo urlencode( $s_id ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-manage">Staff (<?php echo $linked_count; ?>)</a>
                                            <a href="?tab=shops&edit_shop=<?php echo urlencode( $s_id ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit Shop</a>
                                            <form method="post" action="?tab=shops" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete this shop?');">
                                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                <input type="hidden" name="sywat_shop_action" value="delete_shop">
                                                <input type="hidden" name="shop_id" value="<?php echo esc_attr( $s_id ); ?>">
                                                <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- CREATE SHOP MODAL -->
            <div id="addShopModal" class="sywat-modal-backdrop">
                <div class="sywat-modal-content" style="max-width:540px;">
                    <div class="sywat-modal-header no-print">
                        <h3>Create New Archery Shop</h3>
                        <button type="button" class="sywat-modal-close" onclick="closeAddShopModal()">&times;</button>
                    </div>
                    <div class="sywat-modal-body">
                        <form method="post" action="?tab=shops">
                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                            <input type="hidden" name="sywat_shop_action" value="create_shop">
                            
                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Shop Name *</label>
                                <input type="text" name="shop_name" placeholder="e.g. Cinnamon Creek Ranch" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                            </div>
                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Primary Contact Name(s)</label>
                                <input type="text" name="shop_contact" placeholder="e.g. Hannah Lange / Beau Brennan" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                            </div>
                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Contact Email(s)</label>
                                <input type="text" name="shop_email" placeholder="e.g. events@cinnamoncreekranch.com, beau@cinnamoncreekranch.com" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                            </div>
                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Contact Phone</label>
                                <input type="text" name="shop_phone" class="sywat-phone-input" placeholder="817-439-8998" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                            </div>

                            <hr style="border:none; border-top:1px dashed #ccc; margin:15px 0;">
                            <span style="font-size:12px; font-weight:bold; color:#646970; display:block; margin-bottom:10px;">LOCATION ADDRESS DETAILS (OPTIONAL)</span>

                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Address Line 1</label>
                                <input type="text" name="shop_address_1" placeholder="e.g. 13795 Old Denton Rd" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                            </div>
                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">Address Line 2</label>
                                <input type="text" name="shop_address_2" placeholder="Suite, Bldg, etc." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                            </div>
                            <div style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap:10px;">
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">City</label>
                                    <input type="text" name="shop_city" placeholder="e.g. Roanoke" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                                </div>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">State</label>
                                    <select name="shop_state" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                                        <option value="">-- Select State --</option>
                                        <option value="TX">TX - Texas</option>
                                        <option value="OK">OK - Oklahoma</option>
                                        <option value="LA">LA - Louisiana</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:13px;">ZIP</label>
                                    <input type="text" name="shop_zip" placeholder="e.g. 76262" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                                </div>
                            </div>

                            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeAddShopModal()">Cancel</button>
                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-add">Create Shop Profile</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
            function openAddShopModal() {
                const modal = document.getElementById('addShopModal');
                if (modal) modal.classList.add('active');
            }

            function closeAddShopModal() {
                const modal = document.getElementById('addShopModal');
                if (modal) modal.classList.remove('active');
            }

            document.addEventListener("DOMContentLoaded", function() {
                const phoneInputs = document.querySelectorAll('.sywat-phone-input');
                phoneInputs.forEach(input => {
                    input.addEventListener('input', function(e) {
                        let digits = this.value.replace(/\D/g, '').substring(0, 10);
                        let formatted = '';
                        if (digits.length > 0) {
                            if (digits.length <= 3) {
                                formatted = digits;
                            } else if (digits.length <= 6) {
                                formatted = digits.substring(0, 3) + '-' + digits.substring(3);
                            } else {
                                formatted = digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6);
                            }
                        }
                        this.value = formatted;
                    });
                });

                const notice = document.getElementById('sywat-notice');
                if (notice) {
                    setTimeout(function() {
                        notice.style.transition = "opacity 0.5s ease";
                        notice.style.opacity = "0";
                        setTimeout(function() { notice.remove(); }, 500);
                    }, 5000);
                }

                const searchInput = document.getElementById('sywat_shop_search_input');
                if (searchInput) {
                    searchInput.addEventListener('input', function() {
                        const filter = this.value.toLowerCase().trim();
                        const rows = document.querySelectorAll('.sywat-shop-row');
                        rows.forEach(row => {
                            const name = (row.getAttribute('data-name') || '').toLowerCase();
                            const contact = (row.getAttribute('data-contact') || '').toLowerCase();
                            const shopId = (row.getAttribute('data-id') || '').toLowerCase();
                            if (name.includes(filter) || contact.includes(filter) || shopId.includes(filter)) {
                                row.style.display = '';
                            } else {
                                row.style.display = 'none';
                            }
                        });
                    });
                }
            });
            </script>
        <?php endif; ?>
        <?php
    }
}
