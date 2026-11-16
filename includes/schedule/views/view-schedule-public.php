<?php
/**
 * View Template: Public Season Schedule (List View & Monthly Calendar View)
 * Path: includes/schedule/views/view-schedule-public.php
 * Description: Renders the public season schedule with an instant List View / Calendar View switcher, shared filter bar, current-weekend jump control, per-line and event-level Cubs Only / 20 YD Only indicators, stacked shop phone links, Sunday-Saturday monthly calendar grid displaying all matching events per day, and event details modal.
 * Compliance: Pure view layer — zero inline style attributes, zero embedded script blocks, modal outside forms, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Extract payload data
$target_season_slug = isset( $view_data['target_season_slug'] ) ? $view_data['target_season_slug'] : '';
$seasons            = isset( $view_data['seasons'] ) ? $view_data['seasons'] : array();
$referenced_shops   = isset( $view_data['referenced_shops'] ) ? $view_data['referenced_shops'] : array();
$events             = isset( $view_data['events'] ) ? $view_data['events'] : array();
$calendar           = isset( $view_data['calendar'] ) && is_array( $view_data['calendar'] ) ? $view_data['calendar'] : array();

$cal_months     = isset( $calendar['months'] ) && is_array( $calendar['months'] ) ? $calendar['months'] : array();
$cal_default_ym = isset( $calendar['default_ym'] ) ? $calendar['default_ym'] : '';

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

// Determine whether a current/upcoming weekend target exists for the Jump button
$jump_target_label = '';
foreach ( $events as $evt_check ) {
    $code = isset( $evt_check['status_code'] ) ? $evt_check['status_code'] : '';
    if ( $code === 'active' || $code === 'this_weekend' ) {
        $jump_target_label = 'This Weekend';
        break;
    }
    if ( $jump_target_label === '' && $code === 'upcoming' ) {
        $jump_target_label = 'Next Event';
    }
}

// Build compact event lookup map for Calendar Event Modal rendering
$events_lookup = array();
foreach ( $events as $evt_item ) {
    $eid                   = isset( $evt_item['id'] ) ? (int) $evt_item['id'] : count( $events_lookup );
    $events_lookup[ $eid ] = $evt_item;
}

// Utility Icons
$icon_pin      = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
$icon_ext_link = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline></svg>';
$icon_calendar = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
$icon_list     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>';

$weekdays = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
?>

<div class="sywat-public-card">
    <!-- HEADER BAR: TITLE (LEFT), VIEW SWITCHER & INLINE SEASON SELECTOR (RIGHT) -->
    <div class="sywat-card-header-bar">
        <div>
            <h2>📅 Season Schedule</h2>
            <div class="sywat-subtitle">
                View upcoming archery tournaments, event details, and online registration links.
            </div>
        </div>

        <div class="sywat-schedule-header-controls">
            <div class="sywat-view-toggle" role="group" aria-label="Schedule display mode">
                <button type="button" class="sywat-view-toggle-btn is-active" id="sywat_schedule_view_list" aria-pressed="true">
                    <?php echo $icon_list; ?> List View
                </button>
                <button type="button" class="sywat-view-toggle-btn" id="sywat_schedule_view_calendar" aria-pressed="false">
                    <?php echo $icon_calendar; ?> Calendar View
                </button>
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
    </div>

    <!-- FILTER BAR (SHARED ACROSS LIST & CALENDAR VIEWS) -->
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
                <input type="text" id="sywat_schedule_search" placeholder="Type event name, city, or line type...">
                <?php if ( $jump_target_label !== '' ) : ?>
                    <button type="button" class="sywat-btn sywat-btn-secondary" id="sywat_schedule_jump_weekend">
                        <?php echo $icon_calendar; ?> <?php echo esc_html( $jump_target_label ); ?>
                    </button>
                <?php endif; ?>
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
        <!-- =================================================================
             1. LIST VIEW PANEL (DEFAULT)
             ================================================================= -->
        <div id="sywat_schedule_list_panel">
            <div class="sywat-table-responsive">
                <table class="sywat-table">
                    <thead>
                        <tr>
                            <th class="sywat-col-w-20">Date &amp; Status</th>
                            <th class="sywat-col-w-32">Event &amp; Location</th>
                            <th class="sywat-col-w-24">Shooting Lines</th>
                            <th class="sywat-col-w-24 sywat-col-left">Registration / Results</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $events as $evt ) : ?>
                            <?php
                                $status_class = 'sywat-badge-prereg'; // Default blue (upcoming)
                                if ( $evt['status_code'] === 'completed' ) {
                                    $status_class = 'sywat-badge-muted';
                                } elseif ( $evt['status_code'] === 'active' || $evt['status_code'] === 'this_weekend' ) {
                                    $status_class = 'sywat-badge-open';
                                }

                                $restr_scope    = isset( $evt['line_restriction_scope'] ) ? $evt['line_restriction_scope'] : '';
                                $restr_label    = isset( $evt['line_restriction_label'] ) ? $evt['line_restriction_label'] : '';
                                $maps_url       = ! empty( $evt['maps_url'] ) ? $evt['maps_url'] : '';
                                $results_url    = ! empty( $evt['results_url'] ) ? $evt['results_url'] : '';
                                $search_payload = strtolower( trim( $evt['name'] . ' ' . $evt['shop_name'] . ' ' . $evt['address'] . ' ' . $restr_label ) );
                            ?>
                            <tr class="sywat-schedule-row" 
                                data-name="<?php echo esc_attr( $search_payload ); ?>" 
                                data-shop="<?php echo esc_attr( strtolower( $evt['shop_name'] ) ); ?>" 
                                data-district="<?php echo esc_attr( strtolower( $evt['district'] ) ); ?>"
                                data-status="<?php echo esc_attr( $evt['status_code'] ); ?>">
                                
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

                                        <?php if ( $restr_scope === 'all_cubs' ) : ?>
                                            <span class="sywat-badge sywat-badge-cubs"><?php echo esc_html( $restr_label ); ?></span>
                                        <?php elseif ( $restr_scope === 'all_20yd' ) : ?>
                                            <span class="sywat-badge sywat-badge-20yd"><?php echo esc_html( $restr_label ); ?></span>
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

                                <!-- 3. SHOOTING LINES (Grouped by Day, Stacked for Alignment) -->
                                <td>
                                    <?php if ( ! empty( $evt['grouped_lines'] ) ) : ?>
                                        <div class="sywat-schedule-lines-stack">
                                            <?php foreach ( $evt['grouped_lines'] as $day => $slots ) : ?>
                                                <div class="sywat-mb-2">
                                                    <div><strong class="sywat-text-subtle"><?php echo esc_html( $day ); ?>:</strong></div>
                                                    <div class="sywat-mt-1 sywat-line-pills-wrap">
                                                        <?php foreach ( $slots as $slot ) : ?>
                                                            <?php
                                                                $slot_time   = is_array( $slot ) && isset( $slot['time'] ) ? $slot['time'] : (string) $slot;
                                                                $slot_code   = is_array( $slot ) && isset( $slot['restriction_code'] ) ? $slot['restriction_code'] : '';
                                                                $slot_rlabel = is_array( $slot ) && isset( $slot['restriction_label'] ) ? $slot['restriction_label'] : '';

                                                                $pill_class = 'sywat-badge-muted';
                                                                $pill_text  = $slot_time;
                                                                if ( $slot_code === 'cubs_only' && $slot_rlabel !== '' ) {
                                                                    $pill_class = 'sywat-badge-cubs';
                                                                    $pill_text  = $slot_time . ' · ' . $slot_rlabel;
                                                                } elseif ( $slot_code === '20yd_only' && $slot_rlabel !== '' ) {
                                                                    $pill_class = 'sywat-badge-20yd';
                                                                    $pill_text  = $slot_time . ' · ' . $slot_rlabel;
                                                                }
                                                            ?>
                                                            <span class="sywat-badge <?php echo esc_attr( $pill_class ); ?>"><?php echo esc_html( $pill_text ); ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
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
                                            
                                            <?php if ( ( $evt['scores_published'] || $evt['has_tfaa_url'] ) && ! empty( $results_url ) ) : ?>
                                                <a href="<?php echo esc_url( $results_url ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-full">
                                                    🏆 View Results
                                                </a>
                                            <?php else : ?>
                                                <span class="sywat-badge sywat-badge-pending sywat-badge-full">
                                                    ⏳ Results Pending
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
                                                <?php $tel_href = preg_replace( '/[^0-9+]/', '', (string) $evt['shop_phone'] ); ?>
                                                <div class="sywat-label-subtext sywat-mt-1">
                                                    <div>📞 <strong>Contact Shop:</strong></div>
                                                    <div>
                                                        <?php if ( ! empty( $tel_href ) ) : ?>
                                                            <a href="<?php echo esc_attr( 'tel:' . $tel_href ); ?>" class="sywat-table-link">
                                                                <?php echo esc_html( $evt['shop_phone'] ); ?>
                                                            </a>
                                                        <?php else : ?>
                                                            <?php echo esc_html( $evt['shop_phone'] ); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else : ?>
                                                <div class="sywat-label-subtext sywat-mt-1">
                                                    📞 <strong>Contact Host to Register</strong>
                                                </div>
                                            <?php endif; ?>

                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- =================================================================
             2. CALENDAR VIEW PANEL (SUNDAY - SATURDAY MONTHLY GRID)
             ================================================================= -->
        <div id="sywat_schedule_calendar_panel"
             class="sywat-cal-panel sywat-hidden"
             data-default-ym="<?php echo esc_attr( $cal_default_ym ); ?>"
             data-events="<?php echo esc_attr( wp_json_encode( $events_lookup ) ); ?>">

            <div class="sywat-cal-header">
                <button type="button" class="sywat-btn sywat-btn-secondary sywat-cal-nav-prev" id="sywat_cal_prev_btn">
                    &laquo; Previous
                </button>

                <div class="sywat-cal-title-group">
                    <h3 class="sywat-cal-month-title" id="sywat_cal_month_title"></h3>
                    <button type="button" class="sywat-btn sywat-btn-secondary sywat-cal-today-btn" id="sywat_cal_today_btn">
                        Today
                    </button>
                </div>

                <button type="button" class="sywat-btn sywat-btn-secondary sywat-cal-nav-next" id="sywat_cal_next_btn">
                    Next &raquo;
                </button>
            </div>

            <div class="sywat-cal-legend" aria-hidden="true">
                <span class="sywat-cal-legend-item"><span class="sywat-cal-dot is-current"></span> This Weekend / In Progress</span>
                <span class="sywat-cal-legend-item"><span class="sywat-cal-dot is-upcoming"></span> Upcoming</span>
                <span class="sywat-cal-legend-item"><span class="sywat-cal-dot is-completed"></span> Completed</span>
            </div>

            <?php foreach ( $cal_months as $m_idx => $month ) : ?>
                <?php
                    $is_default_month = ( $month['ym'] === $cal_default_ym ) || ( $cal_default_ym === '' && $m_idx === 0 );
                    $month_classes    = 'sywat-cal-month' . ( $is_default_month ? '' : ' sywat-hidden' );
                ?>
                <div class="<?php echo esc_attr( $month_classes ); ?>"
                     data-ym="<?php echo esc_attr( $month['ym'] ); ?>"
                     data-label="<?php echo esc_attr( $month['label'] ); ?>">

                    <div class="sywat-cal-scroll-wrap">
                        <div class="sywat-cal-grid" role="grid" aria-label="<?php echo esc_attr( $month['label'] ); ?>">
                            <?php foreach ( $weekdays as $day_name ) : ?>
                                <div class="sywat-cal-dow" role="columnheader"><?php echo esc_html( $day_name ); ?></div>
                            <?php endforeach; ?>

                            <?php foreach ( $month['days'] as $cell ) : ?>
                                <?php
                                    $cell_classes = 'sywat-cal-day';
                                    if ( empty( $cell['is_current_month'] ) ) {
                                        $cell_classes .= ' is-outside';
                                    }
                                    if ( ! empty( $cell['is_today'] ) ) {
                                        $cell_classes .= ' is-today';
                                    }
                                    $day_events = isset( $cell['events'] ) && is_array( $cell['events'] ) ? $cell['events'] : array();
                                ?>
                                <div class="<?php echo esc_attr( $cell_classes ); ?>" role="gridcell" data-date="<?php echo esc_attr( $cell['date'] ); ?>">
                                    <div class="sywat-cal-day-header">
                                        <span class="sywat-cal-day-num"><?php echo esc_html( $cell['day_padded'] ); ?></span>
                                    </div>

                                    <div class="sywat-cal-day-events">
                                        <?php foreach ( $day_events as $d_evt ) : ?>
                                            <?php
                                                $chip_status = isset( $d_evt['status_code'] ) ? $d_evt['status_code'] : 'upcoming';
                                                $chip_class  = 'sywat-cal-event-chip is-upcoming';
                                                if ( $chip_status === 'completed' ) {
                                                    $chip_class = 'sywat-cal-event-chip is-completed';
                                                } elseif ( $chip_status === 'active' || $chip_status === 'this_weekend' ) {
                                                    $chip_class = 'sywat-cal-event-chip is-current';
                                                }

                                                $d_rscope = isset( $d_evt['line_restriction_scope'] ) ? $d_evt['line_restriction_scope'] : '';
                                                $d_rlabel = isset( $d_evt['line_restriction_label'] ) ? $d_evt['line_restriction_label'] : '';

                                                $chip_label = $d_evt['name'];
                                                if ( ( $d_rscope === 'all_cubs' || $d_rscope === 'all_20yd' ) && $d_rlabel !== '' ) {
                                                    $chip_label .= ' (' . $d_rlabel . ')';
                                                } elseif ( $d_rscope === 'mixed' && $d_rlabel !== '' ) {
                                                    $chip_label .= ' · ' . $d_rlabel;
                                                }

                                                $d_search   = strtolower( trim( $d_evt['name'] . ' ' . $d_evt['shop_name'] . ' ' . $d_evt['address'] . ' ' . $d_rlabel ) );
                                                $chip_title = ! empty( $d_evt['shop_name'] ) && $d_evt['shop_name'] !== $d_evt['name']
                                                    ? $chip_label . ' — ' . $d_evt['shop_name']
                                                    : $chip_label;
                                            ?>
                                            <button type="button"
                                                    class="<?php echo esc_attr( $chip_class ); ?>"
                                                    title="<?php echo esc_attr( $chip_title ); ?>"
                                                    data-event-id="<?php echo esc_attr( (string) $d_evt['id'] ); ?>"
                                                    data-name="<?php echo esc_attr( $d_search ); ?>"
                                                    data-shop="<?php echo esc_attr( strtolower( $d_evt['shop_name'] ) ); ?>"
                                                    data-district="<?php echo esc_attr( strtolower( $d_evt['district'] ) ); ?>"
                                                    data-status="<?php echo esc_attr( $chip_status ); ?>">
                                                <?php echo esc_html( $chip_label ); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- EVENT DETAILS MODAL (OUTSIDE ALL FORMS PER CONTRACT) -->
<div class="sywat-modal-backdrop" id="sywat_schedule_event_modal" role="dialog" aria-modal="true" aria-labelledby="sywat_schedule_event_modal_title">
    <div class="sywat-modal-content sywat-modal-md">
        <div class="sywat-modal-header">
            <h3 id="sywat_schedule_event_modal_title">Tournament Details</h3>
            <button type="button" class="sywat-modal-close" id="sywat_schedule_event_modal_close" aria-label="Close modal">&times;</button>
        </div>
        <div class="sywat-modal-body" id="sywat_schedule_event_modal_body"></div>
        <div class="sywat-modal-footer">
            <button type="button" class="sywat-btn sywat-btn-secondary" id="sywat_schedule_event_modal_done">Close</button>
        </div>
    </div>
</div>
