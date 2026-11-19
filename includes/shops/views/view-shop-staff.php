<?php
/**
 * View Template: Admin Manage Shop Staff
 * Path: includes/shops/views/view-shop-staff.php
 * Description: Renders linked and available shop staff accounts formatted and sorted by surname ("LastName, FirstName") while preserving canonical display_name values in confirmation dialogs.
 * Compliance: Pure view template — zero direct SQL queries, zero embedded script blocks, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$has_lf_formatter = function_exists( 'sywat_format_name_last_first' );
$linked_staff     = array();
$unlinked_staff   = array();

foreach ( $eligible_users as $u ) {
    $u_shop    = get_user_meta( $u->ID, 'sywat_shop_id', true );
    $disp_name = $has_lf_formatter ? sywat_format_name_last_first( $u->display_name ) : $u->display_name;
    $entry     = array(
        'user'      => $u,
        'disp_name' => $disp_name,
    );

    if ( $u_shop === $managing_shop['id'] ) {
        $linked_staff[] = $entry;
    } else {
        $unlinked_staff[] = $entry;
    }
}

$sort_by_surname = function( $a, $b ) {
    return strcasecmp( $a['disp_name'], $b['disp_name'] );
};

usort( $linked_staff, $sort_by_surname );
usort( $unlinked_staff, $sort_by_surname );
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
                
                <div class="sywat-field-group" style="flex:1; min-width:250px;">
                    <label>Select Available Staff User</label>
                    <select name="staff_user_id" required>
                        <?php foreach ( $unlinked_staff as $un_item ): ?>
                            <?php 
                                $un_user     = $un_item['user'];
                                $curr_s_id   = get_user_meta( $un_user->ID, 'sywat_shop_id', true );
                                $curr_s_name = isset( $all_shops[$curr_s_id]['name'] ) ? $all_shops[$curr_s_id]['name'] : 'Unassigned';
                            ?>
                            <option value="<?php echo esc_attr( $un_user->ID ); ?>">
                                <?php echo esc_html( $un_item['disp_name'] ); ?> (Currently: <?php echo esc_html( $curr_s_name ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-add">Link User to <?php echo esc_html( $managing_shop['name'] ); ?></button>
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
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th class="sywat-col-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $linked_staff as $ls_item ): ?>
                        <?php $ls_user = $ls_item['user']; ?>
                        <tr>
                            <td><strong style="color:#0f172a;"><?php echo esc_html( $ls_item['disp_name'] ); ?></strong></td>
                            <td><?php echo esc_html( $ls_user->user_login ); ?></td>
                            <td><?php echo esc_html( $ls_user->user_email ); ?></td>
                            <td class="sywat-col-right">
                                <form method="post" action="<?php echo esc_url( add_query_arg( array('manage_staff' => $managing_shop['id'], 'type' => $type_filter), $page_url ) ); ?>" class="sywat-table-action-form" onsubmit="return confirm('Remove <?php echo esc_js( $ls_user->display_name ); ?> from this location?');">
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
