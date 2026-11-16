<?php
/**
 * View Template: Public Season Schedule
 * Path: includes/schedule/views/view-schedule-public.php
 * Description: Renders the public season schedule table, filter bar, and external registration links.
 * Compliance: Zero inline style attributes, zero embedded script blocks, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Extract payload data
$target_season_slug = isset( $view_data['target_season_slug'] ) ? $view_data['target_season_slug'] : '';
$seasons            = isset( $view_data['seasons'] ) ? $view_data['seasons'] : array();
$referenced_shops   = isset( $view_data['referenced_shops'] ) ? $view_data['referenced_shops'] : array();
$events             = isset( $view_data['events'] ) ? $view_data['events'] : array();

// Build District Filter Options
$filter_districts = array();
foreach ( $referenced_shops as $s_id => $s_data ) {
    if ( ! empty( $s_data['district'] ) && ! in_array( $s_data['district'], $filter_districts, true ) ) {
        $filter_districts[] = $s_data['district'];
    }
}
sort( $filter_districts );

// Sort Referenced Shops for Dropdown
$filter_shops = array();
foreach ( $referenced_shops as $s_id => $s_data ) {
    $s_name = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : '' );
    if ( ! empty( $s_name ) ) {
        $filter_shops[ $s_id ] = $s_name;
    }
}
asort( $filter_shops );

// Utility Icons
$icon_pin      = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
$icon_ext_link = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline></svg>';
$icon_info     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
?>

<div class="sywat-public-card">
    <!-- HEADER BAR: TITLE (LEFT) & INLINE SEASON SELECTOR (RIGHT) -->
    <div class="sywat-card-header-bar">
        <div>
            <h2>📅 Season Schedule</h2>
            <div class="sywat-subtitle">
                View upcoming archery tournaments, event details, and online registration links.
            </div>
        </div>

        <form method="get" id="sywat_schedule_season_form" class="sywat-header-inline-select">
            <label for="sywat_schedule_season_select"><strong>Season:</strong></label>
            <select name="season" id="sywat_schedule_season_select">
                <?php foreach ( $seasons as $slug => $s ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $target_season_slug, $slug ); ?>>
                        <?php echo esc_html( $s['name'] ); ?> (<?php echo esc_html( ucfirst( $s['status'] ) ); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript>
                <button type="submit" class="sywat-btn sywat-btn-secondary">Go</button>
            </noscript>
        </form>
    </div>

    <!-- FILTER BAR -->
    <div class="sywat-filter-bar">
        <?php if ( count( $filter_districts ) > 0 ) : ?>
            <div class="sywat-field-group">
                <label for="sywat_schedule_filter_district">District</label>
                <select id="sywat_schedule_filter_district">
                    <option value="all">All Districts</option>
                    <?php foreach ( $filter_districts as $d_name ) : ?>
                        <option value="<?php echo esc_attr( strtolower( $d_name ) ); ?>"><?php echo esc_html( $d_name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <?php if ( count( $filter_shops ) > 1 ) : ?>
            <div class="sywat-field-group">
                <label for="sywat_schedule_filter_shop">Host Location / Shop</label>
                <select id="sywat_schedule_filter_shop">
                    <option value="all">All Locations</option>
                    <?php foreach ( $filter_shops as $s_id => $s_name ) : ?>
                        <option value="<?php echo esc_attr( strtolower( $s_name ) ); ?>"><?php echo esc_html( $s_name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="sywat-field-group">
            <label for="sywat_schedule_search">Search Event</label>
            <div class="sywat-search-input-wrapper">
                <input type="text" id="sywat_schedule_search" placeholder="Type event name or city...">
                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" id="sywat_schedule_search_clear">Reset Filters</button>
            </div>
        </div>
    </div>

    <!-- DYNAMIC EVENT COUNT BANNER -->
    <div id="sywat_schedule_counter" class="sywat-counter-banner">
        🎯 Total Scheduled Events: <strong><?php echo count( $events ); ?></strong>
    </div>

    <?php if ( empty( $events ) ) : ?>
        <p class="sywat-empty-notice">No tournaments are currently scheduled for this season.</p>
    <?php else : ?>
        <div class="sywat-table-responsive">
            <table class="sywat-table">
                <thead>
                    <tr>
                        <th class="sywat-col-w-20">Date &amp; Status</th>
                        <th class="sywat-col-w-32">Event &amp; Location</th>
                        <th class="sywat-col-w-24">Shooting Lines</th>
                        <th class="sywat-col-w-24">Registration / Results</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $events as $evt ) : ?>
                        <?php
                            $status_class = 'sywat-badge-prereg'; // Default blue (upcoming)
                            if ( $evt['status_code'] === 'completed' ) {
                                $status_class = 'sywat-badge-muted';
                            } elseif ( $evt['status_code'] === 'active' ) {
                                $status_class = 'sywat-badge-open';
                            } elseif ( $evt['status_code'] === 'this_weekend' ) {
                                $status_class = 'sywat-badge-pending';
                            }

                            // Build Maps URL for location if address is available
                            $maps_url = ! empty( $evt['address'] ) 
                                ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode( $evt['shop_name'] . ', ' . $evt['address'] ) 
                                : '';
                            
                            // Determine search payload for live JS filtering
                            $search_payload = strtolower( $evt['name'] . ' ' . $evt['shop_name'] . ' ' . $evt['address'] );
                        ?>
                        <tr class="sywat-schedule-row" 
                            data-name="<?php echo esc_attr( $search_payload ); ?>" 
                            data-shop="<?php echo esc_attr( strtolower( $evt['shop_name'] ) ); ?>" 
                            data-district="<?php echo esc_attr( strtolower( $evt['district'] ) ); ?>">
                            
                            <!-- 1. DATE & STATUS -->
                            <td>
                                <strong><?php echo esc_html( $evt['display_dates'] ); ?></strong>
                                <div class="sywat-mt-1">
                                    <span class="sywat-badge <?php echo esc_attr( $status_class ); ?>">
                                        <?php echo esc_html( $evt['status_label'] ); ?>
                                    </span>
                                </div>
                            </td>

                            <!-- 2. EVENT & LOCATION -->
                            <td>
                                <strong class="sywat-tourn-title"><?php echo esc_html( $evt['name'] ); ?></strong>
                                <div class="sywat-mt-1 sywat-mb-1">
                                    <?php if ( $evt['event_type'] !== 'SYWAT' ) : ?>
                                        <span class="sywat-badge sywat-badge-open"><?php echo esc_html( $evt['event_type'] ); ?></span>
                                    <?php endif; ?>
                                    
                                    <?php if ( ! empty( $evt['district'] ) ) : ?>
                                        <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $evt['district'] ); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="sywat-tourn-shop">
                                    <strong><?php echo esc_html( $evt['shop_name'] ); ?></strong>
                                </div>
                                
                                <?php if ( ! empty( $evt['address'] ) ) : ?>
                                    <div class="sywat-label-subtext sywat-mt-1">
                                        <?php echo $icon_pin; ?> 
                                        <?php if ( ! empty( $maps_url ) ) : ?>
                                            <a href="<?php echo esc_url( $maps_url ); ?>" target="_blank" rel="noopener noreferrer" class="sywat-table-link">
                                                <?php echo esc_html( $evt['address'] ); ?>
                                            </a>
                                        <?php else : ?>
                                            <?php echo esc_html( $evt['address'] ); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- 3. SHOOTING LINES (Grouped by Day) -->
                            <td>
                                <?php if ( ! empty( $evt['grouped_lines'] ) ) : ?>
                                    <div class="sywat-schedule-lines-stack">
                                        <?php foreach ( $evt['grouped_lines'] as $day => $times ) : ?>
                                            <div class="sywat-mb-1">
                                                <strong class="sywat-text-subtle"><?php echo esc_html( $day ); ?>:</strong>
                                                <?php foreach ( $times as $time ) : ?>
                                                    <span class="sywat-badge sywat-badge-muted"><?php echo esc_html( $time ); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else : ?>
                                    <span class="sywat-label-subtext">No lines configured</span>
                                <?php endif; ?>
                            </td>

                            <!-- 4. REGISTRATION / RESULTS -->
                            <td>
                                <div class="sywat-table-actions-vertical">
                                    <?php if ( $evt['status_code'] === 'completed' ) : ?>
                                        
                                        <?php if ( $evt['scores_published'] || $evt['has_tfaa_url'] ) : ?>
                                            <a href="<?php echo esc_url( sywat_page_url( 'results' ) . '?view=tournaments&tournament=' . urlencode( $evt['name'] ) ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-full">
                                                🏆 View Results
                                            </a>
                                        <?php else : ?>
                                            <span class="sywat-badge sywat-badge-pending sywat-badge-full">
                                                <?php echo $icon_info; ?> Results Pending
                                            </span>
                                        <?php endif; ?>
                                        
                                    <?php else : ?>

                                        <?php if ( ! empty( $evt['reg_links'] ) ) : ?>
                                            <?php foreach ( $evt['reg_links'] as $link ) : ?>
                                                <?php $btn_label = ! empty( $link['label'] ) ? $link['label'] : 'Register Online'; ?>
                                                <a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="sywat-btn sywat-btn-success sywat-btn-full sywat-btn-sm">
                                                    <?php echo $icon_ext_link; ?> <?php echo esc_html( $btn_label ); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php elseif ( ! empty( $evt['shop_phone'] ) ) : ?>
                                            <span class="sywat-badge sywat-badge-muted sywat-badge-full">
                                                📞 Contact Shop: <?php echo esc_html( $evt['shop_phone'] ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="sywat-badge sywat-badge-muted sywat-badge-full">
                                                📞 Contact Host to Register
                                            </span>
                                        <?php endif; ?>

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
