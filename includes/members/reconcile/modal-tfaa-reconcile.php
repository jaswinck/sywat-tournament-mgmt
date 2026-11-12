<?php
/**
 * POC Sub-Module: TFAA Gender & Pro Status Reconciliation Modal View
 * Path: includes/members/reconcile/modal-tfaa-reconcile.php
 * Description: Renders the on-demand TFAA Gender & Pro Status reconciliation modal (#sywat_tfaa_reconcile_modal) when triggered via ?reconcile_tfaa=1. Zero overhead on normal Member Directory page loads.
 * Compliance: Zero inline styles, zero inline JS handlers, zero embedded scripts. Follows .sywat-modal-backdrop > .sywat-modal-content > .sywat-modal-header / .sywat-modal-body contract. Self-contained for easy removal post-POC.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_tfaa_reconcile_modal' ) ) {
    /**
     * Render the TFAA Gender & Pro Status reconciliation overlay when requested.
     */
    function sywat_render_tfaa_reconcile_modal() {
        if ( ! current_user_can( 'administrator' ) ) {
            return;
        }

        $is_open = ( isset( $_GET['reconcile_tfaa'] ) && sanitize_text_field( wp_unslash( $_GET['reconcile_tfaa'] ) ) === '1' );
        if ( ! $is_open ) {
            return;
        }

        $report = function_exists( 'sywat_get_tfaa_reconciliation_report' )
            ? sywat_get_tfaa_reconciliation_report()
            : array();

        $matched_updates  = isset( $report['matched_updates'] ) && is_array( $report['matched_updates'] ) ? $report['matched_updates'] : array();
        $unmatched_names  = isset( $report['unmatched_names'] ) && is_array( $report['unmatched_names'] ) ? $report['unmatched_names'] : array();
        $mappable_members = isset( $report['mappable_members'] ) && is_array( $report['mappable_members'] ) ? $report['mappable_members'] : array();
        $stats            = isset( $report['stats'] ) && is_array( $report['stats'] ) ? $report['stats'] : array();

        $stat_tfaa      = isset( $stats['tfaa_total'] ) ? intval( $stats['tfaa_total'] ) : 0;
        $stat_ready     = isset( $stats['ready_updates'] ) ? intval( $stats['ready_updates'] ) : 0;
        $stat_unmatched = isset( $stats['unmatched'] ) ? intval( $stats['unmatched'] ) : 0;
        $stat_synced    = isset( $stats['already_synced'] ) ? intval( $stats['already_synced'] ) : 0;
        ?>

        <!-- POC MODAL: TFAA GENDER & PRO STATUS RECONCILIATION -->
        <div class="sywat-modal-backdrop active" id="sywat_tfaa_reconcile_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <h3>🧬 Reconcile TFAA Genders &amp; Pro Status</h3>
                    <button type="button" class="sywat-modal-close" data-sywat-action="close-reconcile-modal">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <div class="sywat-alert sywat-alert-info">
                        🔍 <strong>POC Data Reconciliation:</strong> Compares synced TFAA score/roster cache against your Member Directory to backfill missing <strong>Gender</strong> and <strong>Pro Archer</strong> attributes without altering already-populated gender values.
                    </div>

                    <!-- SUMMARY METRIC CARDS -->
                    <div class="sywat-form-grid-4 sywat-mt-3">
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">TFAA Synced Shooters</span>
                            <h3><?php echo $stat_tfaa; ?></h3>
                        </div>
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">Ready to Backfill</span>
                            <h3><?php echo $stat_ready; ?></h3>
                        </div>
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">Not in Directory</span>
                            <h3><?php echo $stat_unmatched; ?></h3>
                        </div>
                        <div class="sywat-metric-box">
                            <span class="sywat-meta-subtext">Already Complete</span>
                            <h3><?php echo $stat_synced; ?></h3>
                        </div>
                    </div>

                    <?php if ( empty( $matched_updates ) && empty( $unmatched_names ) ): ?>
                        <p class="sywat-empty-state-notice sywat-mt-4">✅ All synced TFAA shooters are already matched and up to date in your Member Directory!</p>
                        <div class="sywat-modal-actions sywat-mt-4">
                            <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-reconcile-modal">Close</button>
                        </div>
                    <?php else: ?>
                        <form method="post" action="?tab=members&subtab=directory" id="sywat_tfaa_reconcile_form">
                            <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                            <input type="hidden" name="sywat_action" value="reconcile_tfaa_data">

                            <!-- SECTION A: MATCHED DIRECTORY MEMBERS READY FOR BACKFILL -->
                            <?php if ( ! empty( $matched_updates ) ): ?>
                                <div class="sywat-card-header-bar sywat-mt-4">
                                    <div>
                                        <h3>🟢 Matched Directory Members (<?php echo count( $matched_updates ); ?>)</h3>
                                        <div class="sywat-subtitle">Exact name matches found in your Member Directory missing Gender and/or Pro status.</div>
                                    </div>
                                    <div class="sywat-table-header-actions">
                                        <label class="sywat-checkbox-label">
                                            <input type="checkbox" id="sywat_reconcile_select_all" checked>
                                            Select / Deselect All
                                        </label>
                                    </div>
                                </div>

                                <div class="sywat-table-responsive sywat-mt-2">
                                    <table class="sywat-table">
                                        <thead>
                                            <tr>
                                                <th class="sywat-col-center sywat-col-w-8">Apply</th>
                                                <th class="sywat-col-w-30">Member Name</th>
                                                <th class="sywat-col-w-26">Directory Account Type</th>
                                                <th class="sywat-col-center sywat-col-w-18">Gender Update</th>
                                                <th class="sywat-col-center sywat-col-w-18">Pro Status Update</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $matched_updates as $row ): ?>
                                                <?php
                                                    $t_key    = $row['target_key'];
                                                    $acc_type = $row['account_type'];
                                                    $new_gen  = $row['new_gender'];
                                                    $new_pro  = $row['new_is_pro'];
                                                ?>
                                                <tr>
                                                    <td class="sywat-col-center">
                                                        <input type="checkbox" class="sywat-reconcile-target-cb" name="reconcile_targets[]" value="<?php echo esc_attr( $t_key ); ?>" checked>
                                                        <input type="hidden" name="reconcile_target_gender[<?php echo esc_attr( $t_key ); ?>]" value="<?php echo esc_attr( $new_gen ); ?>">
                                                        <input type="hidden" name="reconcile_target_pro[<?php echo esc_attr( $t_key ); ?>]" value="<?php echo esc_attr( $new_pro ); ?>">
                                                    </td>
                                                    <td>
                                                        <strong><?php echo esc_html( $row['display_name'] ); ?></strong>
                                                    </td>
                                                    <td>
                                                        <?php if ( $acc_type === 'head' ): ?>
                                                            <span class="sywat-badge sywat-badge-open">👑 Head of Household</span>
                                                        <?php elseif ( $acc_type === 'dependent' ): ?>
                                                            <span class="sywat-badge sywat-badge-muted">🏠 Dependent</span>
                                                            <?php if ( ! empty( $row['head_name'] ) ): ?>
                                                                <div class="sywat-meta-subtext">Head: <?php echo esc_html( $row['head_name'] ); ?></div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="sywat-badge sywat-badge-unassigned">📁 Unassigned Pool</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="sywat-col-center">
                                                        <?php if ( ! empty( $new_gen ) ): ?>
                                                            <span class="sywat-badge sywat-badge-prereg">
                                                                <?php echo esc_html( ucfirst( $new_gen ) ); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="sywat-meta-subtext"><?php echo esc_html( ucfirst( $row['current_gender'] ) ); ?> (Keep)</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="sywat-col-center">
                                                        <?php if ( $new_pro === 'yes' ): ?>
                                                            <span class="sywat-badge sywat-badge-pro">⭐ Set Pro</span>
                                                        <?php else: ?>
                                                            <span class="sywat-meta-subtext"><?php echo ( $row['current_is_pro'] === 'yes' ) ? '⭐ Pro (Keep)' : '—'; ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <!-- SECTION B: TFAA ARCHERS NOT FOUND IN MEMBER DIRECTORY -->
                            <?php if ( ! empty( $unmatched_names ) ): ?>
                                <div class="sywat-card-header-bar sywat-mt-4">
                                    <div>
                                        <h3>🟡 TFAA Shooters Not in Member Directory (<?php echo count( $unmatched_names ); ?>)</h3>
                                        <div class="sywat-subtitle">Choose whether to leave alone, map to an existing member (nickname/spelling), add to Unassigned Pool, or dismiss.</div>
                                    </div>
                                </div>

                                <div class="sywat-table-responsive sywat-mt-2">
                                    <table class="sywat-table">
                                        <thead>
                                            <tr>
                                                <th class="sywat-col-w-24">TFAA Shooter Name</th>
                                                <th class="sywat-col-center sywat-col-w-16">Detected Info</th>
                                                <th class="sywat-col-w-28">Action</th>
                                                <th class="sywat-col-w-32">Map to Existing Member (If Mapping)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $unmatched_names as $u_row ): ?>
                                                <?php
                                                    $n_key = $u_row['norm_name'];
                                                    $u_gen = $u_row['gender'];
                                                    $u_pro = $u_row['is_pro'];
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo esc_html( $u_row['display_name'] ); ?></strong>
                                                        <input type="hidden" name="unmatched_display_name[<?php echo esc_attr( $n_key ); ?>]" value="<?php echo esc_attr( $u_row['display_name'] ); ?>">
                                                        <input type="hidden" name="unmatched_gender[<?php echo esc_attr( $n_key ); ?>]" value="<?php echo esc_attr( $u_gen ); ?>">
                                                        <input type="hidden" name="unmatched_pro[<?php echo esc_attr( $n_key ); ?>]" value="<?php echo esc_attr( $u_pro ); ?>">
                                                    </td>
                                                    <td class="sywat-col-center">
                                                        <?php if ( ! empty( $u_gen ) ): ?>
                                                            <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( ucfirst( $u_gen ) ); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ( $u_pro === 'yes' ): ?>
                                                            <span class="sywat-badge sywat-badge-pro sywat-ml-1">⭐ Pro</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="sywat-field-group">
                                                            <select name="unmatched_action[<?php echo esc_attr( $n_key ); ?>]" class="sywat-unmatched-action-select" data-norm-key="<?php echo esc_attr( $n_key ); ?>">
                                                                <option value="ignore" selected>Leave Alone (Review Later)</option>
                                                                <option value="add_unassigned">➕ Add to Unassigned Pool</option>
                                                                <option value="map_member">🔗 Map to Existing Member</option>
                                                                <option value="dismiss">🚫 Dismiss / Non-Member</option>
                                                            </select>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="sywat-field-group">
                                                            <select name="unmatched_map_target[<?php echo esc_attr( $n_key ); ?>]" id="sywat_map_target_<?php echo esc_attr( sanitize_html_class( $n_key ) ); ?>" disabled>
                                                                <option value="">-- Select Directory Member --</option>
                                                                <?php foreach ( $mappable_members as $mm ): ?>
                                                                    <option value="<?php echo esc_attr( $mm['target_key'] ); ?>">
                                                                        <?php echo esc_html( $mm['display_name'] ); ?> (<?php echo esc_html( ucfirst( $mm['account_type'] ) ); ?>)
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <div class="sywat-modal-actions sywat-mt-4">
                                <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-reconcile-modal">Cancel</button>
                                <button type="submit" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Apply Reconciliation Updates</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
