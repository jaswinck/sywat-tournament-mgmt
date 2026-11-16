<?php
/**
 * Sub-Module: Public Season Schedule Formatting Helpers
 * Path: includes/schedule/schedule-helpers.php
 * Description: Pure array/scalar formatters for the public season schedule. Resolves shop data & phone numbers, groups line times by day using the composite name as the single source of truth, detects per-line and event-level distance restrictions (Cubs Only / 20 YD Only) from line capacities, sorts times chronologically, normalizes external registration links, calculates chronological event statuses, and builds Sunday-Saturday calendar month grids.
 * Compliance: Zero HTML output at include time, zero DB queries (relies entirely on injected raw payload), omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects whether an individual shooting line is restricted to Cubs Only or 20 YD Only
 * based on its configured 20yd/Cub slot capacities or explicit text labels.
 *
 * @param array  $line      Raw shooting line configuration array.
 * @param string $raw_label Optional raw line label/time text to inspect for explicit tags.
 * @return array{code: string, label: string}
 */
if ( ! function_exists( 'sywat_schedule_detect_line_restriction' ) ) {
    function sywat_schedule_detect_line_restriction( $line, $raw_label = '' ) {
        $text = is_string( $raw_label ) ? trim( $raw_label ) : '';
        if ( $text === '' && is_array( $line ) && ! empty( $line['name'] ) ) {
            $text = trim( (string) $line['name'] );
        }

        // 1. Explicit text tag check (takes precedence if an admin typed it in the line name)
        if ( $text !== '' ) {
            if ( preg_match( '/\bcubs?\s*only\b/i', $text ) ) {
                return array(
                    'code'  => 'cubs_only',
                    'label' => 'Cubs Only',
                );
            }
            if ( preg_match( '/\b(20\s*y(ar)?ds?|adult)\s*only\b/i', $text ) ) {
                return array(
                    'code'  => '20yd_only',
                    'label' => '20 YD Only',
                );
            }
        }

        // 2. Capacity-based detection (20yd = 0 & Cub > 0 => Cubs Only; Cub = 0 & 20yd > 0 => 20 YD Only)
        if ( is_array( $line ) ) {
            $has_20yd = false;
            $val_20yd = 0;
            foreach ( array( 'cap_20yd', 'capacity_20yd', 'max_20yd', '20yd' ) as $key_20 ) {
                if ( isset( $line[ $key_20 ] ) && $line[ $key_20 ] !== '' && is_numeric( $line[ $key_20 ] ) ) {
                    $has_20yd = true;
                    $val_20yd = (int) $line[ $key_20 ];
                    break;
                }
            }

            $has_cub = false;
            $val_cub = 0;
            foreach ( array( 'cap_cub', 'capacity_cub', 'max_cub', 'cub', 'cap_10yd', 'capacity_10yd' ) as $key_cub ) {
                if ( isset( $line[ $key_cub ] ) && $line[ $key_cub ] !== '' && is_numeric( $line[ $key_cub ] ) ) {
                    $has_cub = true;
                    $val_cub = (int) $line[ $key_cub ];
                    break;
                }
            }

            if ( $has_20yd && $has_cub ) {
                if ( $val_20yd <= 0 && $val_cub > 0 ) {
                    return array(
                        'code'  => 'cubs_only',
                        'label' => 'Cubs Only',
                    );
                }
                if ( $val_cub <= 0 && $val_20yd > 0 ) {
                    return array(
                        'code'  => '20yd_only',
                        'label' => '20 YD Only',
                    );
                }
            }
        }

        return array(
            'code'  => '',
            'label' => '',
        );
    }
}

/**
 * Formats the raw schedule data payload into a presentation-ready array for the View layer,
 * including both the chronological List View events and the Sunday-Saturday Calendar View grids.
 *
 * @param array $raw_data The data bundle returned by sywat_schedule_get_raw_data().
 * @return array Formatted view payload.
 */
if ( ! function_exists( 'sywat_schedule_format_view_payload' ) ) {
    function sywat_schedule_format_view_payload( $raw_data ) {
        $formatted_tournaments = array();
        $all_shops             = isset( $raw_data['all_shops'] ) ? $raw_data['all_shops'] : array();
        $tournaments           = isset( $raw_data['tournaments'] ) ? $raw_data['tournaments'] : array();
        $seasons               = isset( $raw_data['seasons'] ) ? $raw_data['seasons'] : array();
        $target_season_slug    = isset( $raw_data['target_season_slug'] ) ? $raw_data['target_season_slug'] : '';

        $today            = current_time( 'Y-m-d' );
        $results_base_url = function_exists( 'sywat_page_url' ) ? sywat_page_url( 'results' ) : '';

        // Build a quick name-to-shop map for fallback resolution
        $shop_lookup_map = array();
        foreach ( $all_shops as $s_id => $s_data ) {
            if ( is_array( $s_data ) ) {
                $s_name = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : '' );
                if ( ! empty( $s_name ) ) {
                    $shop_lookup_map[ strtolower( trim( $s_name ) ) ] = $s_id;
                }
            }
        }

        $event_idx = 0;
        foreach ( $tournaments as $t_name => $t_data ) {
            // 1. Dates & Status Resolution
            $start_date = isset( $t_data['start_date'] ) ? $t_data['start_date'] : '';
            $end_date   = ! empty( $t_data['end_date'] ) ? $t_data['end_date'] : $start_date;
            $disp_dates = function_exists( 'sywat_format_tournament_dates' ) ? sywat_format_tournament_dates( $start_date, $end_date ) : $start_date;
            
            $status_code  = 'upcoming';
            $status_label = 'Upcoming';
            
            if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
                if ( $today > $end_date ) {
                    $status_code  = 'completed';
                    $status_label = 'Completed';
                } elseif ( $today >= $start_date && $today <= $end_date ) {
                    $status_code  = 'active';
                    $status_label = 'In Progress';
                } else {
                    // Check if it's coming up this weekend (within the next 5 days)
                    $days_until = ( strtotime( $start_date ) - strtotime( $today ) ) / 86400;
                    if ( $days_until > 0 && $days_until <= 5 ) {
                        $status_code  = 'this_weekend';
                        $status_label = 'This Weekend';
                    }
                }
            }

            // 2. Shop Data & Phone Resolution
            $t_shop_id = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : ( isset( $t_data['shop_id'] ) ? $t_data['shop_id'] : '' );
            $shop_name = function_exists( 'sywat_get_tournament_shop_name' ) ? sywat_get_tournament_shop_name( $t_data, $t_name, $all_shops ) : ( ! empty( $t_data['shop_name'] ) ? $t_data['shop_name'] : '' );
            
            if ( empty( $t_shop_id ) ) {
                $norm_shop = strtolower( trim( $shop_name ) );
                if ( isset( $shop_lookup_map[ $norm_shop ] ) ) {
                    $t_shop_id = $shop_lookup_map[ $norm_shop ];
                }
            }

            $district   = '';
            $address    = '';
            $shop_phone = '';
            
            if ( ! empty( $t_shop_id ) && isset( $all_shops[ $t_shop_id ] ) ) {
                $s_info   = $all_shops[ $t_shop_id ];
                $district = isset( $s_info['district'] ) ? $s_info['district'] : '';
                
                if ( ! empty( $s_info['phone'] ) ) {
                    $shop_phone = $s_info['phone'];
                }
                
                $addr_parts = array();
                if ( ! empty( $s_info['address_1'] ) ) $addr_parts[] = $s_info['address_1'];
                if ( ! empty( $s_info['address_2'] ) ) $addr_parts[] = $s_info['address_2'];
                
                $city_state_zip = trim( 
                    ( ! empty( $s_info['city'] ) ? $s_info['city'] : '' ) . 
                    ( ! empty( $s_info['city'] ) && ! empty( $s_info['state'] ) ? ', ' : ' ' ) . 
                    ( ! empty( $s_info['state'] ) ? $s_info['state'] : '' ) . ' ' . 
                    ( ! empty( $s_info['zip'] ) ? $s_info['zip'] : '' )
                );
                
                if ( ! empty( $city_state_zip ) ) {
                    $addr_parts[] = $city_state_zip;
                }
                $address = implode( ', ', $addr_parts );
            }

            // 3. Group Shooting Lines by Day & Detect Per-Line Distance Restrictions
            $raw_lines     = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : ( isset( $t_data['lines'] ) ? $t_data['lines'] : array() );
            $grouped_lines = array();
            $valid_days    = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

            $total_slot_count = 0;
            $cubs_only_count  = 0;
            $yd20_only_count  = 0;

            foreach ( $raw_lines as $l ) {
                $day  = '';
                $time = '';

                if ( ! empty( $l['name'] ) ) {
                    $name_parts = explode( ' ', trim( $l['name'] ), 2 );
                    if ( count( $name_parts ) === 2 && in_array( strtolower( $name_parts[0] ), $valid_days, true ) ) {
                        $day  = ucfirst( strtolower( $name_parts[0] ) );
                        $time = trim( $name_parts[1] );
                    } else {
                        $day  = 'Other';
                        $time = trim( $l['name'] );
                    }
                } else {
                    $day  = isset( $l['day'] ) && ! empty( $l['day'] ) ? trim( $l['day'] ) : 'Other';
                    $time = isset( $l['time'] ) ? trim( $l['time'] ) : ( isset( $l['start_time'] ) ? trim( $l['start_time'] ) : '' );
                }
                
                if ( ! empty( $time ) ) {
                    $restriction = sywat_schedule_detect_line_restriction( $l, $time );

                    // Strip trailing :00 and redundant parenthetical restriction text from the time label
                    $clean_time = str_replace( ':00', '', $time );
                    if ( $restriction['code'] !== '' ) {
                        $clean_time = trim( preg_replace( '/\s*\((?:cubs?|20\s*y(?:ar)?ds?|adult)\s*only\)\s*/i', ' ', $clean_time ) );
                    }

                    $total_slot_count++;
                    if ( $restriction['code'] === 'cubs_only' ) {
                        $cubs_only_count++;
                    } elseif ( $restriction['code'] === '20yd_only' ) {
                        $yd20_only_count++;
                    }

                    if ( ! isset( $grouped_lines[ $day ] ) ) {
                        $grouped_lines[ $day ] = array();
                    }
                    $grouped_lines[ $day ][] = array(
                        'time'              => $clean_time,
                        'restriction_code'  => $restriction['code'],
                        'restriction_label' => $restriction['label'],
                    );
                }
            }

            // 3b. Sort line slots chronologically for each day
            foreach ( $grouped_lines as $day => &$slots ) {
                usort( $slots, function( $a, $b ) {
                    $time_a = strtotime( $a['time'] );
                    $time_b = strtotime( $b['time'] );
                    if ( $time_a === false ) $time_a = 0;
                    if ( $time_b === false ) $time_b = 0;
                    return $time_a - $time_b;
                } );
            }
            unset( $slots );

            // 3c. Summarize event-level distance restriction scope
            $line_restriction_scope = '';
            $line_restriction_label = '';
            if ( $total_slot_count > 0 ) {
                if ( $cubs_only_count === $total_slot_count ) {
                    $line_restriction_scope = 'all_cubs';
                    $line_restriction_label = 'Cubs Only';
                } elseif ( $yd20_only_count === $total_slot_count ) {
                    $line_restriction_scope = 'all_20yd';
                    $line_restriction_label = '20 YD Only';
                } elseif ( $cubs_only_count > 0 || $yd20_only_count > 0 ) {
                    $line_restriction_scope = 'mixed';
                    $line_restriction_label = 'Line Restrictions';
                }
            }

            // 4. Registration Links & Action URLs Normalization
            $reg_links = function_exists( 'sywat_get_shop_registration_links' ) ? sywat_get_shop_registration_links( $t_data ) : ( isset( $t_data['registration_links'] ) ? $t_data['registration_links'] : array() );
            $maps_url  = ! empty( $address )
                ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode( $shop_name . ', ' . $address )
                : '';
            $results_url = ! empty( $results_base_url )
                ? $results_base_url . '?view=tournaments&tournament=' . urlencode( $t_name )
                : '';

            // 5. Build Event Card Data
            $formatted_tournaments[] = array(
                'id'                     => $event_idx,
                'name'                   => $t_name,
                'event_type'             => isset( $t_data['event_type'] ) ? $t_data['event_type'] : 'SYWAT',
                'start_date'             => $start_date,
                'end_date'               => $end_date,
                'display_dates'          => $disp_dates,
                'status_code'            => $status_code,
                'status_label'           => $status_label,
                'shop_name'              => $shop_name,
                'shop_phone'             => $shop_phone,
                'district'               => $district,
                'address'                => $address,
                'maps_url'               => $maps_url,
                'grouped_lines'          => $grouped_lines,
                'line_restriction_scope' => $line_restriction_scope,
                'line_restriction_label' => $line_restriction_label,
                'reg_links'              => $reg_links,
                'scores_published'       => ! empty( $t_data['scores_published'] ),
                'has_tfaa_url'           => ! empty( $t_data['tfaa_results_url'] ),
                'results_url'            => $results_url,
            );
            $event_idx++;
        }

        $active_season_data = isset( $seasons[ $target_season_slug ] ) ? $seasons[ $target_season_slug ] : array();
        $calendar_data      = sywat_schedule_build_calendar_months( $formatted_tournaments, $active_season_data, $today );

        return array(
            'target_season_slug' => $target_season_slug,
            'seasons'            => $seasons,
            'referenced_shops'   => $raw_data['referenced_shops'],
            'events'             => $formatted_tournaments,
            'calendar'           => $calendar_data,
        );
    }
}

/**
 * Builds Sunday-Saturday calendar month grids covering the target season and maps every
 * formatted tournament across each date from start_date through end_date.
 *
 * @param array  $formatted_events Presentation-ready event items from sywat_schedule_format_view_payload().
 * @param array  $season_data      Optional target season metadata ('start_date', 'end_date').
 * @param string $today            Current site date in 'YYYY-MM-DD' format.
 * @return array{today: string, default_ym: string, months: array}
 */
if ( ! function_exists( 'sywat_schedule_build_calendar_months' ) ) {
    function sywat_schedule_build_calendar_months( $formatted_events, $season_data = array(), $today = '' ) {
        if ( empty( $today ) ) {
            $today = current_time( 'Y-m-d' );
        }

        $events_by_date = array();
        $min_ym         = '';
        $max_ym         = '';

        foreach ( $formatted_events as $evt ) {
            $s_date = isset( $evt['start_date'] ) ? trim( (string) $evt['start_date'] ) : '';
            $e_date = ! empty( $evt['end_date'] ) ? trim( (string) $evt['end_date'] ) : $s_date;

            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s_date ) ) {
                continue;
            }
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $e_date ) || $e_date < $s_date ) {
                $e_date = $s_date;
            }

            $s_ym = substr( $s_date, 0, 7 );
            $e_ym = substr( $e_date, 0, 7 );
            if ( $min_ym === '' || $s_ym < $min_ym ) {
                $min_ym = $s_ym;
            }
            if ( $max_ym === '' || $e_ym > $max_ym ) {
                $max_ym = $e_ym;
            }

            // Expand tournament across each day it runs (midday timestamps avoid DST shifts; 14-day safety cap)
            $s_ts     = strtotime( $s_date . ' 12:00:00' );
            $e_ts     = strtotime( $e_date . ' 12:00:00' );
            $max_span = $s_ts + ( 14 * 86400 );
            if ( $e_ts > $max_span ) {
                $e_ts = $max_span;
            }

            for ( $cur_ts = $s_ts; $cur_ts <= $e_ts; $cur_ts += 86400 ) {
                $d_key = gmdate( 'Y-m-d', $cur_ts );
                if ( ! isset( $events_by_date[ $d_key ] ) ) {
                    $events_by_date[ $d_key ] = array();
                }
                $events_by_date[ $d_key ][] = $evt;
            }
        }

        // Expand month bounds to include season start/end dates when configured within an 18-month span
        if ( is_array( $season_data ) ) {
            $sec_start = isset( $season_data['start_date'] ) ? trim( (string) $season_data['start_date'] ) : '';
            $sec_end   = isset( $season_data['end_date'] ) ? trim( (string) $season_data['end_date'] ) : '';
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sec_start ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sec_end ) && $sec_end >= $sec_start ) {
                $ss_ym = substr( $sec_start, 0, 7 );
                $se_ym = substr( $sec_end, 0, 7 );
                if ( $min_ym === '' || $ss_ym < $min_ym ) {
                    $min_ym = $ss_ym;
                }
                if ( $max_ym === '' || $se_ym > $max_ym ) {
                    $max_ym = $se_ym;
                }
            }
        }

        $today_ym = substr( $today, 0, 7 );
        if ( $min_ym === '' || $max_ym === '' ) {
            $min_ym = $today_ym;
            $max_ym = $today_ym;
        }

        $months       = array();
        $first_active = '';

        try {
            $cursor = new DateTimeImmutable( $min_ym . '-01 12:00:00' );
            $limit  = new DateTimeImmutable( $max_ym . '-01 12:00:00' );
            $guard  = 0;

            while ( $cursor <= $limit && $guard < 24 ) {
                $ym           = $cursor->format( 'Y-m' );
                $label        = $cursor->format( 'F Y' );
                $days_in_mon  = (int) $cursor->format( 't' );
                $first_dow    = (int) $cursor->format( 'w' ); // 0 = Sunday .. 6 = Saturday
                $grid_start   = $cursor->modify( '-' . $first_dow . ' days' );
                $total_cells  = (int) ( ceil( ( $first_dow + $days_in_mon ) / 7 ) * 7 );
                $days         = array();

                for ( $i = 0; $i < $total_cells; $i++ ) {
                    $cell_dt     = $grid_start->modify( '+' . $i . ' days' );
                    $cell_date   = $cell_dt->format( 'Y-m-d' );
                    $in_month    = ( $cell_dt->format( 'Y-m' ) === $ym );
                    $cell_events = isset( $events_by_date[ $cell_date ] ) ? $events_by_date[ $cell_date ] : array();

                    if ( $in_month && $first_active === '' ) {
                        foreach ( $cell_events as $ce ) {
                            $st = isset( $ce['status_code'] ) ? $ce['status_code'] : '';
                            if ( $st === 'active' || $st === 'this_weekend' || $st === 'upcoming' ) {
                                $first_active = $ym;
                                break;
                            }
                        }
                    }

                    $days[] = array(
                        'date'             => $cell_date,
                        'day_num'          => (int) $cell_dt->format( 'j' ),
                        'day_padded'       => $cell_dt->format( 'd' ),
                        'is_current_month' => $in_month,
                        'is_today'         => ( $cell_date === $today ),
                        'events'           => $cell_events,
                    );
                }

                $months[] = array(
                    'ym'    => $ym,
                    'label' => $label,
                    'days'  => $days,
                );

                $cursor = $cursor->modify( '+1 month' );
                $guard++;
            }
        } catch ( Exception $e ) {
            $months = array();
        }

        // Resolve default starting month: current month if inside season, else first active/upcoming month, else first month
        $default_ym = '';
        foreach ( $months as $m ) {
            if ( $m['ym'] === $today_ym ) {
                $default_ym = $today_ym;
                break;
            }
        }
        if ( $default_ym === '' && $first_active !== '' ) {
            $default_ym = $first_active;
        }
        if ( $default_ym === '' && ! empty( $months ) ) {
            $default_ym = $months[0]['ym'];
        }

        return array(
            'today'      => $today,
            'default_ym' => $default_ym,
            'months'     => $months,
        );
    }
}
