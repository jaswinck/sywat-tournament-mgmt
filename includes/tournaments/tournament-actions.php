<?php
/**
 * Sub-Module: Action Handlers for Tournaments, Seasons, and Bulk Schedule Importer
 * Path: includes/tournaments/tournament-actions.php
 * Author: SYWAT Archery
 * Version: 5.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Converts Excel Serial Dates or standard date strings to 'YYYY-MM-DD'
 *
 * @param string|int $val Raw date value from spreadsheet
 * @return string Formatted date string
 */
if ( ! function_exists( 'sywat_format_imported_date' ) ) {
    function sywat_format_imported_date( $val ) {
        $val = trim( (string) $val );
        if ( empty( $val ) ) return '';

        // Handle Excel Date Serial Numbers (e.g. 46318 -> 2026-10-23)
        if ( is_numeric( $val ) && intval( $val ) > 30000 ) {
            $unix = ( intval( $val ) - 25569 ) * 86400;
            return date( 'Y-m-d', $unix );
        }

        $time = strtotime( $val );
        return ( $time !== false ) ? date( 'Y-m-d', $time ) : $val;
    }
}

/**
 * Universal Spreadsheet File Parser (.xlsx via SimpleXLSX and .csv fallback)
 *
 * @param string $file_path Path to uploaded file
 * @param string $file_name Original file name
 * @return array Parsed row arrays
 */
if ( ! function_exists( 'sywat_parse_schedule_file_rows' ) ) {
    function sywat_parse_schedule_file_rows( $file_path, $file_name ) {
        $raw_rows = array();
        $ext      = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

        // 1. Excel (.xlsx) Parser via includes/lib/SimpleXLSX.php
        if ( $ext === 'xlsx' ) {
            $lib_path = plugin_dir_path( __FILE__ ) . '../lib/SimpleXLSX.php';
            if ( file_exists( $lib_path ) && ! class_exists( 'SimpleXLSX' ) ) {
                require_once $lib_path;
            }

            if ( class_exists( 'SimpleXLSX' ) && ( $xlsx = SimpleXLSX::parse( $file_path ) ) ) {
                foreach ( $xlsx->rows() as $idx => $r ) {
                    if ( $idx === 0 ) continue; // Skip header row
                    if ( count( $r ) >= 3 ) {
                        $raw_rows[] = array(
                            'start_date'           => isset( $r[0] ) ? trim( (string) $r[0] ) : '',
                            'end_date'             => isset( $r[1] ) ? trim( (string) $r[1] ) : '',
                            'host_shop_city'       => isset( $r[2] ) ? trim( (string) $r[2] ) : '',
                            'line_times'           => isset( $r[3] ) ? trim( (string) $r[3] ) : '',
                            'event_type'           => isset( $r[4] ) ? trim( (string) $r[4] ) : '',
                            'national_event_title' => isset( $r[5] ) ? trim( (string) $r[5] ) : '',
                            'notes'                => isset( $r[6] ) ? trim( (string) $r[6] ) : '',
                        );
                    }
                }
                if ( ! empty( $raw_rows ) ) {
                    return $raw_rows;
                }
            }
        }

        // 2. CSV Fallback
        if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
            $header = fgetcsv( $handle, 1000, ',' );
            while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
                if ( count( $data ) >= 3 ) {
                    $raw_rows[] = array(
                        'start_date'           => isset( $data[0] ) ? trim( $data[0] ) : '',
                        'end_date'             => isset( $data[1] ) ? trim( $data[1] ) : '',
                        'host_shop_city'       => isset( $data[2] ) ? trim( $data[2] ) : '',
                        'line_times'           => isset( $data[3] ) ? trim( $data[3] ) : '',
                        'event_type'           => isset( $data[4] ) ? trim( $data[4] ) : '',
                        'national_event_title' => isset( $data[5] ) ? trim( $data[5] ) : '',
                        'notes'                => isset( $data[6] ) ? trim( $data[6] ) : '',
                    );
                }
            }
            fclose( $handle );
        }

        return $raw_rows;
    }
}

/**
 * Parses raw human line string into structured line slot arrays.
 * Enforces standardized day labels: "Friday", "Saturday", "Sunday".
 *
 * @param string $line_str      Raw schedule string from import file or input.
 * @param int    $default_20yd  Default 20-yard line capacity.
 * @param int    $default_cub   Default Cub line capacity.
 * @return array                Array of structured line slot definitions.
 */
if ( ! function_exists( 'sywat_parse_imported_line_schedule' ) ) {
    function sywat_parse_imported_line_schedule( $line_str, $default_20yd = 28, $default_cub = 4 ) {
        $lines = array();
        if ( empty( trim( $line_str ) ) ) {
            return array(
                array( 'day' => 'Saturday', 'start_time' => '10AM', 'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub ),
                array( 'day' => 'Saturday', 'start_time' => '2PM',  'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub ),
                array( 'day' => 'Sunday',   'start_time' => '10AM', 'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub ),
                array( 'day' => 'Sunday',   'start_time' => '2PM',  'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub ),
            );
        }

        $lower = strtolower( $line_str );

        // 1. Friday Line Check
        if ( preg_match( '/fri(?:day)?[\s\-]*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $line_str, $m ) ) {
            $time = strtoupper( str_replace( ' ', '', $m[1] ) );
            if ( strpos( $time, 'AM' ) === false && strpos( $time, 'PM' ) === false ) {
                $time .= 'PM';
            }
            $lines[] = array( 'day' => 'Friday', 'start_time' => $time, 'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub );
        }

        // 2. Saturday & Sunday Checks
        $has_sat = ( strpos( $lower, 'sat' ) !== false );
        $has_sun = ( strpos( $lower, 'sun' ) !== false && strpos( $lower, 'no sunday' ) === false );

        $times = array();
        if ( preg_match_all( '/\b(\d{1,2}(?::\d{2})?)\b/', $line_str, $time_matches ) ) {
            foreach ( $time_matches[1] as $t ) {
                $num = intval( explode( ':', $t )[0] );
                $formatted = ( $num >= 6 && $num <= 11 ) ? $num . 'AM' : $num . 'PM';
                if ( ! in_array( $formatted, $times ) ) {
                    $times[] = $formatted;
                }
            }
        }

        if ( empty( $times ) ) {
            $times = array( '10AM', '2PM' );
        }

        if ( $has_sat ) {
            foreach ( $times as $t ) {
                $lines[] = array( 'day' => 'Saturday', 'start_time' => $t, 'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub );
            }
        }

        if ( $has_sun ) {
            foreach ( $times as $t ) {
                $lines[] = array( 'day' => 'Sunday', 'start_time' => $t, 'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub );
            }
        }

        return $lines;
    }
}

/**
 * Validates uploaded schedule rows against master Shop Directory & prohibits database duplicates.
 *
 * @param array  $raw_rows       Imported raw spreadsheet rows.
 * @param string $target_season  Slug of target season.
 * @return array                 Structured array containing valid tournaments and rejected rows.
 */
if ( ! function_exists( 'sywat_process_schedule_import_rows' ) ) {
    function sywat_process_schedule_import_rows( $raw_rows, $target_season ) {
        $all_shops   = function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : get_option( 'sywat_shops_list', array() );
        $known_shops = array();

        // Multi-Key Shop Directory Extraction ('name', 'shop_name', 'title')
        foreach ( $all_shops as $s_id => $s_data ) {
            if ( is_array( $s_data ) ) {
                $title = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : ( ! empty( $s_data['title'] ) ? $s_data['title'] : '' ) );
                if ( ! empty( $title ) ) {
                    $known_shops[ strtolower( trim( $title ) ) ] = trim( $title );
                }
            }
        }

        $all_existing_tournaments = get_option( 'sywat_tournaments_list', array() );
        $valid_tournaments        = array();
        $rejected_rows            = array();
        $shop_counters            = array();
        $holiday_keywords         = array( 'thanksgiving', 'thanskgiving', 'christmas', 'new years', 'no sywat\'s', 'no sywats' );

        foreach ( $raw_rows as $index => $row ) {
            $start_date     = trim( isset( $row['start_date'] ) ? $row['start_date'] : '' );
            $raw_host_city  = trim( isset( $row['host_shop_city'] ) ? $row['host_shop_city'] : '' );
            $event_type     = trim( isset( $row['event_type'] ) ? $row['event_type'] : 'SYWAT' );
            $national_title = trim( isset( $row['national_event_title'] ) ? $row['national_event_title'] : '' );
            $notes          = trim( isset( $row['notes'] ) ? $row['notes'] : '' );

            // Silent Skip: Holiday placeholders or blank rows
            if ( empty( $start_date ) || in_array( strtolower( $raw_host_city ), $holiday_keywords ) ) {
                continue;
            }

            $start_date_formatted = sywat_format_imported_date( $start_date );
            $end_date_formatted   = ! empty( $row['end_date'] ) ? sywat_format_imported_date( $row['end_date'] ) : $start_date_formatted;

            // -------------------------------------------------------------
            // DUPLICATE GUARD: Prohibit duplicate event on same date & shop
            // -------------------------------------------------------------
            $is_duplicate   = false;
            $duplicate_name = '';

            foreach ( $all_existing_tournaments as $t_key => $ex ) {
                $ex_season = isset( $ex['season'] ) ? $ex['season'] : ( isset( $ex['season_slug'] ) ? $ex['season_slug'] : '' );
                if ( $ex_season !== $target_season ) {
                    continue;
                }

                $ex_shop  = ! empty( $ex['shop_name'] ) ? strtolower( trim( $ex['shop_name'] ) ) : ( ! empty( $ex['official_shop_name'] ) ? strtolower( trim( $ex['official_shop_name'] ) ) : '' );
                $ex_start = ! empty( $ex['start_date'] ) ? $ex['start_date'] : '';

                // Match local shop name & start date
                if ( ! empty( $raw_host_city ) && isset( $known_shops[ strtolower( $raw_host_city ) ] ) ) {
                    if ( strtolower( $known_shops[ strtolower( $raw_host_city ) ] ) === $ex_shop && $start_date_formatted === $ex_start ) {
                        $is_duplicate   = true;
                        $duplicate_name = isset( $ex['name'] ) ? $ex['name'] : $t_key;
                        break;
                    }
                }

                // Match National Event Title & start date
                if ( strcasecmp( $event_type, 'National' ) === 0 && ! empty( $national_title ) ) {
                    $ex_title = isset( $ex['name'] ) ? $ex['name'] : $t_key;
                    if ( strtolower( trim( $ex_title ) ) === strtolower( trim( $national_title ) ) && $start_date_formatted === $ex_start ) {
                        $is_duplicate   = true;
                        $duplicate_name = $ex_title;
                        break;
                    }
                }
            }

            if ( $is_duplicate ) {
                $rejected_rows[] = array(
                    'row_num'   => $index + 2,
                    'shop_name' => $raw_host_city,
                    'reason'    => 'DUPLICATE PROHIBITED: "' . $duplicate_name . '" already exists on ' . $start_date_formatted . ' in season ' . $target_season . '.',
                );
                continue;
            }

            // Branch 1: National / External Events
            if ( strcasecmp( $event_type, 'National' ) === 0 ) {
                $final_title = ! empty( $national_title ) ? $national_title : $raw_host_city;
                $valid_tournaments[] = array(
                    'tournament_name'      => $final_title,
                    'official_shop_name'   => '',
                    'location_display'     => $raw_host_city,
                    'start_date_formatted' => $start_date_formatted,
                    'end_date_formatted'   => $end_date_formatted,
                    'event_type'           => 'National',
                    'line_slots'           => array(),
                    'registration_enabled' => false,
                    'notes'                => $notes,
                );
                continue;
            }

            // Branch 2: TFAA State Championship
            if ( strcasecmp( $event_type, 'TFAA State' ) === 0 ) {
                $norm_shop = strtolower( $raw_host_city );
                $official_shop = isset( $known_shops[$norm_shop] ) ? $known_shops[$norm_shop] : $raw_host_city;
                $valid_tournaments[] = array(
                    'tournament_name'      => 'TFAA State Indoor Championship',
                    'official_shop_name'   => $official_shop,
                    'location_display'     => $official_shop,
                    'start_date_formatted' => $start_date_formatted,
                    'end_date_formatted'   => $end_date_formatted,
                    'event_type'           => 'TFAA State',
                    'line_slots'           => sywat_parse_imported_line_schedule( isset( $row['line_times'] ) ? $row['line_times'] : '' ),
                    'registration_enabled' => true,
                    'notes'                => $notes,
                );
                continue;
            }

            // Branch 3: Standard SYWAT Circuit Shoots
            $norm_shop = strtolower( $raw_host_city );
            if ( isset( $known_shops[$norm_shop] ) ) {
                $official_shop = $known_shops[$norm_shop];
                if ( ! isset( $shop_counters[$official_shop] ) ) {
                    $shop_counters[$official_shop] = 1;
                }

                $tournament_name = $official_shop . '-' . $shop_counters[$official_shop];
                $shop_counters[$official_shop]++;

                $valid_tournaments[] = array(
                    'tournament_name'      => $tournament_name,
                    'official_shop_name'   => $official_shop,
                    'location_display'     => $official_shop,
                    'start_date_formatted' => $start_date_formatted,
                    'end_date_formatted'   => $end_date_formatted,
                    'event_type'           => 'SYWAT',
                    'line_slots'           => sywat_parse_imported_line_schedule( isset( $row['line_times'] ) ? $row['line_times'] : '' ),
                    'registration_enabled' => true,
                    'notes'                => $notes,
                );
            } else {
                $rejected_rows[] = array(
                    'row_num'   => $index + 2,
                    'shop_name' => $raw_host_city,
                    'reason'    => 'Host Shop Name not found in master Shop Directory.',
                );
            }
        }

        return array(
            'valid_tournaments' => $valid_tournaments,
            'rejected_rows'     => $rejected_rows,
        );
    }
}

/**
 * Master Controller Function for Tournament & Season POST Actions.
 *
 * @param array  $tournaments         Reference to tournaments array.
 * @param array  $active_tournaments  Reference to active tournaments array.
 * @param array  $registrations       Reference to registrations array.
 * @param bool   $can_manage_seasons  Capability flag for season configuration.
 * @param string $user_shop_id        Shop ID of current logged in user.
 * @return string                     HTML alert notice markup.
 */
if ( ! function_exists( 'sywat_handle_tournament_post_actions' ) ) {
    function sywat_handle_tournament_post_actions( &$tournaments, &$active_tournaments, &$registrations, $can_manage_seasons, $user_shop_id ) {
        $notice = '';
        $action = isset( $_POST['sywat_action'] ) ? sanitize_text_field( $_POST['sywat_action'] ) : '';

        // Action: Register Shooter Intake Form
        if ( $action === 'register_shooter' && function_exists( 'sywat_handle_register_shooter' ) ) {
            return sywat_handle_register_shooter( $tournaments, $registrations );
        }

        // Action: Import Shooters (FareHarbor CSV / Raw Text)
        if ( $action === 'import_shooters' && function_exists( 'sywat_handle_import_shooters' ) ) {
            return sywat_handle_import_shooters( $tournaments, $registrations );
        }

        // Action: Save Edited Archer
        if ( $action === 'save_edit' && function_exists( 'sywat_handle_save_edit' ) ) {
            return sywat_handle_save_edit( $tournaments, $registrations );
        }

        // Action: Delete Single Shooter
        if ( $action === 'delete_shooter' && function_exists( 'sywat_handle_delete_shooter' ) ) {
            return sywat_handle_delete_shooter( $registrations );
        }

        // Action: Purge Entire Line
        if ( $action === 'purge_line' && function_exists( 'sywat_handle_purge_line' ) ) {
            return sywat_handle_purge_line( $tournaments, $registrations );
        }

        // Action: Save Season Configuration
        if ( $action === 'save_season' ) {
            if ( ! $can_manage_seasons ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators and TFAA webmasters can configure seasons.</div>';
            }

            $seasons     = sywat_get_seasons();
            $orig_slug   = isset( $_POST['original_season_slug'] ) ? sanitize_text_field( $_POST['original_season_slug'] ) : '';
            $s_type      = sanitize_text_field( $_POST['season_type'] );
            $start_date  = sanitize_text_field( $_POST['start_date'] );
            $end_date    = sanitize_text_field( $_POST['end_date'] );
            $s_status    = sanitize_text_field( $_POST['season_status'] );
            $s_best_n    = intval( $_POST['season_best_n'] );

            // Validate Season Type Selection
            if ( empty( $s_type ) || ! in_array( $s_type, array( 'Indoor', 'Outdoor', 'Field 50' ) ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a valid Season Type (Indoor, Outdoor, or Field 50).</div>';
            }

            if ( empty( $start_date ) || empty( $end_date ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select both Start and End dates for the season.</div>';
            }

            // Automatically Calculate Year Label from Start and End Dates
            $s_start_year = date( 'Y', strtotime( $start_date ) );
            $s_end_year   = date( 'Y', strtotime( $end_date ) );

            if ( $s_start_year === $s_end_year ) {
                $year_label = $s_start_year;
            } else {
                $year_label = $s_start_year . '–' . $s_end_year;
            }

            // Automatically Generate Season Name & Slug
            $s_name = $year_label . ' ' . $s_type;
            $s_slug = ! empty( $orig_slug ) ? $orig_slug : sanitize_title( $s_name );

            if ( $s_status === 'active' ) {
                foreach ( $seasons as $k => &$s ) {
                    $s['status'] = 'archived';
                }
                unset( $s );
            }

            $seasons[$s_slug] = array(
                'slug'         => $s_slug,
                'name'         => $s_name,
                'type'         => $s_type,
                'start_date'   => $start_date,
                'end_date'     => $end_date,
                'year_label'   => $year_label,
                'status'       => $s_status,
                'best_n_count' => $s_best_n > 0 ? $s_best_n : 3,
            );

            update_option( 'sywat_seasons_list', $seasons );
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Season <strong>' . esc_html( $s_name ) . '</strong> saved successfully!</div>';
        }

        // Action: Upload & Stage Bulk Schedule File (.xlsx or .csv)
        if ( $action === 'import_schedule_file' ) {
            check_admin_referer( 'sywat_import_schedule_nonce', 'sywat_import_schedule_nonce' );

            if ( empty( $_FILES['schedule_file']['tmp_name'] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please select a valid schedule file (.csv or .xlsx) to upload.</div>';
            }

            $target_season = isset( $_POST['import_target_season'] ) ? sanitize_text_field( $_POST['import_target_season'] ) : '2026-2027 Indoor';
            $file_path     = $_FILES['schedule_file']['tmp_name'];
            $file_name     = $_FILES['schedule_file']['name'];

            $raw_rows      = sywat_parse_schedule_file_rows( $file_path, $file_name );
            $processed     = sywat_process_schedule_import_rows( $raw_rows, $target_season );
            $processed['target_season'] = $target_season;

            set_transient( 'sywat_pending_schedule_import_' . get_current_user_id(), $processed, 3600 );

            $valid_cnt    = count( $processed['valid_tournaments'] );
            $rejected_cnt = count( $processed['rejected_rows'] );

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-info">🔍 <strong>Schedule Preview Generated:</strong> Found ' . $valid_cnt . ' valid tournament(s) and ' . $rejected_cnt . ' rejected/prohibited row(s). Please review the preview modal below to confirm creation.</div>';
        }

        // Action: Confirm & Execute Bulk Schedule Creation
        if ( $action === 'confirm_schedule_import' ) {
            check_admin_referer( 'sywat_confirm_schedule_nonce', 'sywat_confirm_schedule_nonce' );

            $transient_key = 'sywat_pending_schedule_import_' . get_current_user_id();
            $processed     = get_transient( $transient_key );

            if ( ! $processed || empty( $processed['valid_tournaments'] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Import session expired or no valid tournaments found. Please try uploading the file again.</div>';
            }

            $target_season = isset( $processed['target_season'] ) ? $processed['target_season'] : '2026-2027 Indoor';
            $created_count = 0;

            foreach ( $processed['valid_tournaments'] as $t ) {
                $t_name = $t['tournament_name'];

                $tournaments[$t_name] = array(
                    'name'                 => $t_name,
                    'season'               => $target_season,
                    'shop_name'            => $t['official_shop_name'],
                    'location_display'     => $t['location_display'],
                    'start_date'           => $t['start_date_formatted'],
                    'end_date'             => $t['end_date_formatted'],
                    'event_type'           => $t['event_type'],
                    'notes'                => sanitize_textarea_field( $t['notes'] ),
                    'lines'                => $t['line_slots'],
                    'registration_enabled' => $t['registration_enabled'],
                    'scores_published'     => false,
                    'created_at'           => current_time( 'mysql' ),
                );

                if ( ! in_array( $t_name, $active_tournaments ) ) {
                    $active_tournaments[] = $t_name;
                }

                $created_count++;
            }

            update_option( 'sywat_tournaments_list', $tournaments );
            update_option( 'sywat_active_tournaments', array_values( array_unique( $active_tournaments ) ) );
            delete_transient( $transient_key );

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🚀 <strong>Schedule Imported Successfully!</strong> Created ' . intval( $created_count ) . ' new tournament(s) for season <strong>' . esc_html( $target_season ) . '</strong>.</div>';
        }

        // Action: Sync TFAA Cache
        if ( $action === 'sync_tfaa_now' && function_exists( 'sywat_sync_tfaa_standings' ) ) {
            $res = sywat_sync_tfaa_standings();
            $notice = ! empty( $res['success'] ) 
                ? '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 <strong>TFAA Standings Synced!</strong> Successfully cached ' . intval( $res['count'] ) . ' active shooters from TFAA.</div>'
                : '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
        }

        // Action: Toggle Volunteer Hub Display
        if ( $action === 'toggle_active_tournament' ) {
            $target_t = sanitize_text_field( $_POST['tournament_name'] );
            if ( in_array( $target_t, $active_tournaments ) ) {
                $active_tournaments = array_diff( $active_tournaments, array( $target_t ) );
            } else {
                $active_tournaments[] = $target_t;
            }
            $active_tournaments = array_values( array_unique( $active_tournaments ) );
            update_option( 'sywat_active_tournaments', $active_tournaments );
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Updated Volunteer Hub status for <strong>' . esc_html( $target_t ) . '</strong>.</div>';
        }

        // Action: Global Push All Weekend Scores to Season Standings
        if ( $action === 'global_push_standings' ) {
            if ( ! $can_manage_seasons ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. Only administrators and TFAA webmasters can push global standings.</div>';
            }

            $pushed_count = 0;
            $now_time     = current_time( 'mysql' );

            foreach ( $tournaments as $t_name => &$t_data ) {
                $t_data['scores_published'] = true;
                $t_data['published_at']     = $now_time;
                $pushed_count++;
            }
            unset( $t_data );

            update_option( 'sywat_tournaments_list', $tournaments );

            if ( function_exists( 'sywat_recalculate_season_standings' ) ) {
                sywat_recalculate_season_standings();
            }

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🚀 <strong>Weekend Standings Pushed!</strong> Successfully published scores across ' . $pushed_count . ' tournament(s) to Season Standings.</div>';
        }

        // Action: Toggle Push / Publish Scores to Season Standings (Single Event Fallback)
        if ( $action === 'toggle_publish_standings' ) {
            $t_name = sanitize_text_field( $_POST['tournament_name'] );
            if ( isset( $tournaments[$t_name] ) ) {
                $is_published = ! empty( $tournaments[$t_name]['scores_published'] );
                $tournaments[$t_name]['scores_published'] = ! $is_published;

                if ( ! $is_published ) {
                    $tournaments[$t_name]['published_at'] = current_time( 'mysql' );
                    $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Scores for <strong>' . esc_html( $t_name ) . '</strong> have been 🚀 <strong>Pushed to Season Standings</strong>!</div>';
                } else {
                    unset( $tournaments[$t_name]['published_at'] );
                    $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Scores for <strong>' . esc_html( $t_name ) . '</strong> have been 🔴 <strong>Hidden from Season Standings</strong>.</div>';
                }

                update_option( 'sywat_tournaments_list', $tournaments );
            }
        }

        // Action: Save / Edit Tournament Config
        if ( $action === 'save_tournament' ) {
            $t_name      = sanitize_text_field( $_POST['tournament_name'] );
            $old_name    = sanitize_text_field( $_POST['original_tournament_name'] );
            $season_slug = sanitize_text_field( $_POST['season_slug'] );
            $new_slug    = sanitize_title( $t_name );

            $start_date  = sanitize_text_field( $_POST['start_date'] );
            $end_date    = sanitize_text_field( $_POST['end_date'] );
            if ( empty( $end_date ) ) $end_date = $start_date;

            $assigned_shop_id = $can_manage_seasons ? sanitize_text_field( $_POST['shop_id'] ) : $user_shop_id;
            if ( empty( $assigned_shop_id ) ) $assigned_shop_id = $user_shop_id;
            
            $formatted_dates = sywat_format_tournament_dates( $start_date, $end_date );
            update_option( 'sywat_dates_' . $new_slug, $formatted_dates );

            // Process Dynamic Shooting Lines Repeater (Split Day & Time + Deduplication)
            $new_lines = array();
            $seen_combos = array();

            if ( ! empty( $_POST['line_days'] ) && is_array( $_POST['line_days'] ) ) {
                foreach ( $_POST['line_days'] as $index => $day ) {
                    $clean_day  = trim( sanitize_text_field( $day ) );
                    $clean_time = isset( $_POST['line_times'][$index] ) ? trim( sanitize_text_field( $_POST['line_times'][$index] ) ) : '';
                    if ( ! empty( $clean_day ) && ! empty( $clean_time ) ) {
                        $combo_key = strtolower( $clean_day . '_' . $clean_time );
                        if ( ! in_array( $combo_key, $seen_combos ) ) {
                            $seen_combos[] = $combo_key;
                            $new_lines[]   = array(
                                'day'      => $clean_day,
                                'time'     => $clean_time,
                                'name'     => trim( $clean_day . ' ' . $clean_time ),
                                'cap_20yd' => isset( $_POST['cap_20yd'][$index] ) ? intval( $_POST['cap_20yd'][$index] ) : 20,
                                'cap_cub'  => isset( $_POST['cap_cub'][$index] ) ? intval( $_POST['cap_cub'][$index] ) : 5,
                            );
                        }
                    }
                }
            } elseif ( ! empty( $_POST['line_names'] ) && is_array( $_POST['line_names'] ) ) {
                // Fallback for legacy single line string POSTs
                foreach ( $_POST['line_names'] as $index => $name ) {
                    $clean_name = trim( sanitize_text_field( $name ) );
                    if ( ! empty( $clean_name ) ) {
                        $parts     = explode( ' ', $clean_name, 2 );
                        $combo_key = strtolower( $clean_name );
                        if ( ! in_array( $combo_key, $seen_combos ) ) {
                            $seen_combos[] = $combo_key;
                            $new_lines[]   = array(
                                'day'      => $parts[0],
                                'time'     => isset( $parts[1] ) ? $parts[1] : '',
                                'name'     => $clean_name,
                                'cap_20yd' => isset( $_POST['cap_20yd'][$index] ) ? intval( $_POST['cap_20yd'][$index] ) : 20,
                                'cap_cub'  => isset( $_POST['cap_cub'][$index] ) ? intval( $_POST['cap_cub'][$index] ) : 5,
                            );
                        }
                    }
                }
            }

            // Carry forward existing publishing metadata if present
            $lookup_handle = ( ! empty( $old_name ) && isset( $tournaments[$old_name] ) ) ? $old_name : $t_name;
            $is_scores_pub = isset( $tournaments[$lookup_handle]['scores_published'] ) ? $tournaments[$lookup_handle]['scores_published'] : false;
            $published_at  = isset( $tournaments[$lookup_handle]['published_at'] ) ? $tournaments[$lookup_handle]['published_at'] : '';

            if ( ! empty( $old_name ) && $old_name !== $t_name && isset( $tournaments[$old_name] ) ) {
                unset( $tournaments[$old_name] );
                $old_slug = sanitize_title( $old_name );

                foreach ( $registrations as &$reg ) {
                    if ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $old_name ) === 0 ) {
                        delete_option( 'sywat_t_' . $old_slug . '_' . $reg['id'] );
                        $reg['tournament'] = $t_name;
                        update_option( 'sywat_t_' . $new_slug . '_' . $reg['id'], $reg, false );
                    }
                }
                unset( $reg );

                if ( in_array( $old_name, $active_tournaments ) ) {
                    $active_tournaments = array_diff( $active_tournaments, array( $old_name ) );
                    $active_tournaments[] = $t_name;
                }
            } else {
                if ( ! in_array( $t_name, $active_tournaments ) ) {
                    $active_tournaments[] = $t_name;
                }
            }

            $tournaments[$t_name] = array(
                'shop_id'          => $assigned_shop_id,
                'season_slug'      => $season_slug,
                'start_date'       => $start_date,
                'end_date'         => $end_date,
                'lines'            => $new_lines,
                'scores_published' => $is_scores_pub,
            );

            if ( ! empty( $published_at ) ) {
                $tournaments[$t_name]['published_at'] = $published_at;
            }

            update_option( 'sywat_tournaments_list', $tournaments );
            update_option( 'sywat_active_tournaments', array_values( array_unique( $active_tournaments ) ) );
            update_option( 'sywat_current_tournament', $t_name );
            update_option( 'sywat_line_config', $new_lines );

            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Tournament <strong>' . esc_html( $t_name ) . '</strong> saved successfully!</div>';
        }

        // Action: Delete Tournament
        if ( $action === 'delete_tournament' ) {
            $del_tournament = sanitize_text_field( $_POST['tournament_name'] );
            if ( isset( $tournaments[$del_tournament] ) ) {
                unset( $tournaments[$del_tournament] );
                update_option( 'sywat_tournaments_list', $tournaments );

                $active_tournaments = array_diff( $active_tournaments, array( $del_tournament ) );
                update_option( 'sywat_active_tournaments', array_values( $active_tournaments ) );

                $del_slug = sanitize_title( $del_tournament );
                delete_option( 'sywat_dates_' . $del_slug );

                foreach ( $registrations as $reg ) {
                    if ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $del_tournament ) === 0 ) {
                        delete_option( 'sywat_t_' . $del_slug . '_' . $reg['id'] );
                    }
                }

                $registrations = array_values( array_filter( $registrations, function( $reg ) use ( $del_tournament ) {
                    return ! ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $del_tournament ) === 0 );
                }) );

                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully deleted tournament <strong>' . esc_html( $del_tournament ) . '</strong> and purged all archers.</div>';
            }
        }

        return $notice;
    }
}
