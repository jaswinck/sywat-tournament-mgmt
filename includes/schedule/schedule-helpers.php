<?php
/**
 * Sub-Module: Public Season Schedule Formatting Helpers
 * Path: includes/schedule/schedule-helpers.php
 * Description: Pure array/scalar formatters for the public season schedule. Resolves shop data & phone numbers, groups line times by day using the composite name as the single source of truth, sorts times chronologically, normalizes external registration links, and calculates chronological event statuses.
 * Compliance: Zero HTML output at include time, zero DB queries (relies entirely on injected raw payload), omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.2.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Formats the raw schedule data payload into a presentation-ready array for the View layer.
 *
 * @param array $raw_data The data bundle returned by sywat_schedule_get_raw_data().
 * @return array Formatted view payload.
 */
if ( ! function_exists( 'sywat_schedule_format_view_payload' ) ) {
    function sywat_schedule_format_view_payload( $raw_data ) {
        $formatted_tournaments = array();
        $all_shops             = isset( $raw_data['all_shops'] ) ? $raw_data['all_shops'] : array();
        $tournaments           = isset( $raw_data['tournaments'] ) ? $raw_data['tournaments'] : array();

        $today = current_time( 'Y-m-d' );

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
                $s_info = $all_shops[ $t_shop_id ];
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

            // 3. Group Shooting Lines by Day (Using 'name' as Single Source of Truth)
            $raw_lines = function_exists( 'sywat_get_tournament_lines' ) ? sywat_get_tournament_lines( $t_data ) : ( isset( $t_data['lines'] ) ? $t_data['lines'] : array() );
            $grouped_lines = array();
            
            $valid_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
            
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
                    // Strip the trailing :00 so "6:00PM" exactly matches the config view "6PM"
                    $time = str_replace( ':00', '', $time );
                    
                    if ( ! isset( $grouped_lines[ $day ] ) ) {
                        $grouped_lines[ $day ] = array();
                    }
                    $grouped_lines[ $day ][] = $time;
                }
            }

            // 3b. Sort times chronologically for each day
            foreach ( $grouped_lines as $day => &$times ) {
                usort( $times, function( $a, $b ) {
                    $time_a = strtotime( $a );
                    $time_b = strtotime( $b );
                    // Fallback to 0 if parsing fails (e.g. "TBD")
                    if ( $time_a === false ) $time_a = 0;
                    if ( $time_b === false ) $time_b = 0;
                    return $time_a - $time_b;
                });
            }
            unset( $times ); // break reference

            // 4. Registration Links Normalization
            $reg_links = function_exists( 'sywat_get_shop_registration_links' ) ? sywat_get_shop_registration_links( $t_data ) : ( isset( $t_data['registration_links'] ) ? $t_data['registration_links'] : array() );

            // 5. Build Event Card Data
            $formatted_tournaments[] = array(
                'name'             => $t_name,
                'event_type'       => isset( $t_data['event_type'] ) ? $t_data['event_type'] : 'SYWAT',
                'start_date'       => $start_date,
                'end_date'         => $end_date,
                'display_dates'    => $disp_dates,
                'status_code'      => $status_code,
                'status_label'     => $status_label,
                'shop_name'        => $shop_name,
                'shop_phone'       => $shop_phone,
                'district'         => $district,
                'address'          => $address,
                'grouped_lines'    => $grouped_lines,
                'reg_links'        => $reg_links,
                'scores_published' => ! empty( $t_data['scores_published'] ),
                'has_tfaa_url'     => ! empty( $t_data['tfaa_results_url'] ),
            );
        }

        return array(
            'target_season_slug' => $raw_data['target_season_slug'],
            'seasons'            => $raw_data['seasons'],
            'referenced_shops'   => $raw_data['referenced_shops'],
            'events'             => $formatted_tournaments,
        );
    }
}
