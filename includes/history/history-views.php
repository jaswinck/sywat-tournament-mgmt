<?php
/**
 * Sub-Module: Historical TFAA Results Importer View Renderer
 * Path: includes/history/history-views.php
 * Description: Renders the [sywat_history_import] administrative portal for discovering historical TFAA seasons, selecting single test seasons or full multi-season batches, monitoring chunked AJAX progress, and purging imported seasons.
 * Compliance: Pure view layer — zero output at include time, zero direct SQL queries, zero inline style attributes, zero embedded script blocks, zero inline JS event handlers, accessible inline SVGs, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the TFAA Historical Results Importer administrative interface ([sywat_history_import]).
 *
 * @return string Buffered HTML output.
 */
if ( ! function_exists( 'sywat_render_history_importer_view' ) ) {
    function sywat_render_history_importer_view() {
        if ( ! defined( 'SYWAT_ENABLE_HISTORY_IMPORTER' ) || ! SYWAT_ENABLE_HISTORY_IMPORTER ) {
            return '<div class="sywat-wrap"><div class="sywat-alert sywat-alert-danger">The TFAA Historical Importer module is currently disabled.</div></div>';
        }

        if ( ! function_exists( 'sywat_can_manage_seasons' ) || ! sywat_can_manage_seasons() ) {
            return '<div class="sywat-wrap"><div class="sywat-alert sywat-alert-danger">Access denied. Administrator or Season Manager privileges are required to import historical TFAA results.</div></div>';
        }

        if ( function_exists( 'sywat_history_enqueue_assets' ) ) {
            sywat_history_enqueue_assets();
        }

        $results_url = function_exists( 'sywat_page_url' ) ? sywat_page_url( 'results' ) : '';

        ob_start();
        ?>
        <div class="sywat-wrap" id="sywat_history_importer_app">
            <div class="sywat-card no-print">

                <!-- Top Card Header Bar -->
                <div class="sywat-card-header-bar">
                    <div>
                        <h2>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="7 10 12 15 17 10"></polyline>
                                <line x1="12" y1="15" x2="12" y2="3"></line>
                            </svg>
                            Import Historical TFAA Results
                        </h2>
                        <div class="sywat-subtitle">
                            Migrate archived seasons, tournament scorecards, and season standings from texasfieldarchery.org as independent historical records.
                        </div>
                    </div>

                    <div class="sywat-header-inline-controls">
                        <button type="button" id="sywat_history_discover_btn" class="sywat-btn sywat-btn-primary" data-sywat-action="discover-seasons">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="23 4 23 10 17 10"></polyline>
                                <polyline points="1 20 1 14 7 14"></polyline>
                                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                            </svg>
                            Discover TFAA Seasons
                        </button>

                        <?php if ( $results_url !== '' ) : ?>
                            <a href="<?php echo esc_url( $results_url ); ?>" class="sywat-btn sywat-btn-secondary">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                    <polyline points="15 3 21 3 21 9"></polyline>
                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                </svg>
                                View Results &amp; Standings
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dynamic Feedback Alert Banner -->
                <div id="sywat_history_status_banner" class="sywat-alert sywat-hidden" role="status" aria-live="polite"></div>

                <!-- Operational Guidance Notice -->
                <div class="sywat-notice-box sywat-mt-3 sywat-mb-4">
                    <strong>Migration Architecture:</strong> Imported seasons are saved with <strong>Archived</strong> status so they populate <strong>Results &amp; Standings</strong> without appearing in active registration or check-in dropdowns. Historical shooter rows remain decoupled from live Member Directory accounts, and events are processed one tournament per AJAX request to prevent server timeouts. Select a single season first to verify formatting before running a multi-season batch.
                </div>

                <!-- Live Chunked AJAX Progress Panel -->
                <div id="sywat_history_progress_box" class="sywat-notice-box sywat-mt-3 sywat-mb-4 sywat-hidden">
                    <div class="sywat-table-header-bar">
                        <strong id="sywat_history_progress_label">Preparing historical migration...</strong>
                        <span id="sywat_history_progress_stats">0 / 0 events</span>
                    </div>

                    <progress id="sywat_history_progress_bar" max="100" value="0" class="sywat-w-100"></progress>

                    <div class="sywat-mt-3">
                        <button type="button" id="sywat_history_stop_btn" class="sywat-btn sywat-btn-danger" data-sywat-action="stop-import">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="6" y="6" width="12" height="12"></rect>
                            </svg>
                            Stop After Current Event
                        </button>
                    </div>
                </div>

                <!-- Season Discovery & Selection Table -->
                <div id="sywat_history_seasons_section" class="sywat-mt-3">
                    <div class="sywat-table-header-bar">
                        <h2>Discovered TFAA Seasons (<span id="sywat_history_season_count">0</span>)</h2>

                        <div class="sywat-table-header-controls">
                            <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="select-unimported">
                                Select Unimported
                            </button>
                            <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="select-all">
                                Select All
                            </button>
                            <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="clear-selection">
                                Clear
                            </button>
                            <button type="button" id="sywat_history_run_btn" class="sywat-btn sywat-btn-success" data-sywat-action="start-import" disabled>
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                                </svg>
                                Import Selected Seasons
                            </button>
                        </div>
                    </div>

                    <div class="sywat-table-responsive">
                        <table class="sywat-table" id="sywat_history_seasons_table">
                            <thead>
                                <tr>
                                    <th class="sywat-col-center">
                                        <input type="checkbox" id="sywat_history_check_all" data-sywat-action="toggle-all-checkboxes" aria-label="Select all discovered seasons">
                                    </th>
                                    <th>TFAA Season</th>
                                    <th class="sywat-col-center">Type</th>
                                    <th class="sywat-col-center">Date Window</th>
                                    <th class="sywat-col-center">Status</th>
                                    <th class="sywat-col-center">Imported Events</th>
                                    <th class="sywat-col-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="sywat_history_seasons_tbody">
                                <tr>
                                    <td colspan="7" class="sywat-col-center">
                                        Click <strong>Discover TFAA Seasons</strong> above to load available seasons from texasfieldarchery.org.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Live Migration Activity Log -->
                <div id="sywat_history_log_section" class="sywat-mt-3 sywat-hidden">
                    <div class="sywat-table-header-bar">
                        <h3>Migration Activity Log</h3>
                        <div class="sywat-table-header-controls">
                            <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="clear-log">
                                Clear Log
                            </button>
                        </div>
                    </div>
                    <div id="sywat_history_log_list" class="sywat-notice-box" role="log" aria-live="polite"></div>
                </div>

            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
