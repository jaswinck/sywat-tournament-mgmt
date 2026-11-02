<?php
/**
 * View Template: Admin Manage Shop Staff
 * Path: includes/shops/views/view-shop-staff.php
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
    <div class="sywat-card-title">
        <span>Linked Staff for Location: <strong><?php echo esc_html( $managing_shop['name'] ); ?></strong></span>
        <a href="<?php echo esc_url( add_query_arg('type', $type_filter, $page_url) ); ?>" class="sywat-btn sywat-btn-secondary sywat-btn-icon-back">Back to List</a>
    </div>

    <div style="margin-bottom:20px; background:#f8fafc; padding:15px; border:1px solid #cbd5e1; border-radius:4px;">
        <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:bold;">Link Additional Staff User to This Location</h4>
        <?php if ( empty( $unlinked_staff ) ): ?>
            <p style="color:#646970; font-style:italic; margin:0; font-size:13px;">All registered Shop Managers and Shop Users are currently linked to a location.</p>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url( add_query_arg( array('manage_staff' => $managing_shop['id'], 'type' => $type_filter), $page_url ) ); ?>" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
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
        <div class="sywat-table-responsive">
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
                                <form method="post" action="<?php echo esc_url( add_query_arg( array('manage_staff' => $managing_shop['id'], 'type' => $type_filter), $page_url ) ); ?>" style="margin:0;" onsubmit="return confirm('Remove <?php echo esc_js( $ls_user->display_name ); ?> from this location?');">
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
        </div>
    <?php endif; ?>
</div>
