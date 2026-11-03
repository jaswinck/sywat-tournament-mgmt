<?php
/**
 * View Template: Admin Manage Shop Staff
 * Path: includes/shops/views/view-shop-staff.php
 * Author: SYWAT Archery
 * Version: 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
    <!-- UNIFIED TOP CARD HEADER BAR: TITLE (LEFT) & BACK BUTTON (RIGHT) -->
    <div class="sywat-card-header-bar">
        <div>
            <h2>🎯 Linked Staff: <?php echo esc_html( $managing_shop['name'] ); ?></h2>
        </div>
        <div>
            <a href="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back">Back to List</a>
        </div>
    </div>

    <!-- LINK ADDITIONAL STAFF USER TOOLBAR -->
    <div style="margin-bottom:20px; background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:6px;">
        <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:700; color:#1e293b;">Link Additional Staff User to This Location</h4>
        <?php if ( empty( $unlinked_staff ) ): ?>
            <p style="color:#646970; font-style:italic; margin:0; font-size:13px;">All registered Shop Managers and Shop Users are currently linked to a location.</p>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url( add_query_arg( array('manage_staff' => $managing_shop['id'], 'type' => $type_filter), $page_url ) ); ?>" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                <input type="hidden" name="sywat_shop_action" value="assign_staff_to_shop">
                <input type="hidden" name="target_shop_id" value="<?php echo esc_attr( $managing_shop['id'] ); ?>">
                
                <div style="flex:1; min-width:250px;">
                    <label style="display:block; font-weight:700; margin-bottom:4px; font-size:12px; color:#1e293b;">Select Available Staff User</label>
                    <select name="staff_user_id" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; height:38px; background:#fff;" required>
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
                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-add" style="height:38px;">Link User to <?php echo esc_html( $managing_shop['name'] ); ?></button>
            </form>
        <?php endif; ?>
    </div>

    <!-- CURRENTLY LINKED ACCOUNTS TABLE -->
    <h4 style="margin:20px 0 10px 0; font-size:14px; font-weight:700; color:#1e293b;">Currently Linked Staff Accounts (<?php echo count($linked_staff); ?>)</h4>
    <?php if ( empty( $linked_staff ) ): ?>
        <p style="color:#646970; font-style:italic; margin-bottom:15px;">No Shop Manager or Shop User accounts are currently linked to this location.</p>
    <?php else: ?>
        <div class="sywat-table-responsive">
            <table class="sywat-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">Name</th>
                        <th style="text-align:left;">Username</th>
                        <th style="text-align:left;">Email</th>
                        <th class="sywat-col-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $linked_staff as $ls_user ): ?>
                        <tr>
                            <td style="font-weight:700; color:#0f172a; text-align:left;"><?php echo esc_html( $ls_user->display_name ); ?></td>
                            <td style="text-align:left;"><?php echo esc_html( $ls_user->user_login ); ?></td>
                            <td style="text-align:left;"><?php echo esc_html( $ls_user->user_email ); ?></td>
                            <td class="sywat-col-right">
                                <form method="post" action="<?php echo esc_url( add_query_arg( array('manage_staff' => $managing_shop['id'], 'type' => $type_filter), $page_url ) ); ?>" style="margin:0; display:inline-block;" onsubmit="return confirm('Remove <?php echo esc_js( $ls_user->display_name ); ?> from this location?');">
                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                    <input type="hidden" name="sywat_shop_action" value="remove_staff_from_shop">
                                    <input type="hidden" name="staff_user_id" value="<?php echo esc_attr( $ls_user->ID ); ?>">
                                    <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Unlink Staff</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
