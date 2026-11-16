<?php
/**
 * Sub-Module: Tournament Configuration Helpers, Formatters & Parsing Engines
 * Path: includes/tournaments/tournament-helpers.php
 * Description: Pure scalar/array formatting helpers, standardized 30-minute AM/PM line time option builders, chronological tournament & line comparators, public online registration window gatekeepers, TFAA name matchers, and spreadsheet parsers.
 * Compliance: Zero HTML output at include time, zero database queries inside formatting helpers, O(N) hoisted sort key pre-computation, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central Permission Checker for Season/Shop Administration
 */
if ( ! function_exists( 'sywat_can_manage_seasons' ) ) {
    function sywat_can_manage_seasons() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        $allowed_roles = array( 'administrator', 'tfaa_webmaster', 'tfaa_admin' );
        return (bool) array_intersect( $allowed_roles, (array) $user->roles );
    }
}

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
 * Formats line time tokens cleanly (e.g. "6" or "6:00" -> "6PM", "6:30" -> "6:30PM", "10:00" -> "10AM").
 * Enforces Archery rule: 6 o'clock lines are ALWAYS PM (never 6AM).
 *
 * @param string $token Time string token.
 * @return string Formatted time token.
 */
if ( ! function_exists( 'sywat_format_line_time_token' ) ) {
    function sywat_format_line_time_token( $token ) {
        $token = trim( (string) $token );
        if ( empty( $token ) ) return '';

        if ( preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i', $token, $m ) ) {
            $hour     = intval( $m[1] );
            $mins     = isset( $m[2] ) ? $m[2] : '';
            $meridiem = isset( $m[3] ) ? strtoupper( $m[3] ) : '';

            if ( $mins === '00' ) {
                $mins = '';
            }

            $min_str = ! empty( $mins ) ? ':' . $mins : '';

            if ( empty( $meridiem ) ) {
                if ( $hour === 6 || $hour === 12 || ( $hour >= 1 && $hour <= 5 ) ) {
                    $meridiem = 'PM';
                } else {
                    $meridiem = 'AM';
                }
            }

            // Enforce Archery rule: 6 o'clock is ALWAYS PM (6PM or 6:30PM)
            if ( $hour === 6 ) {
                $meridiem = 'PM';
            }

            return $hour . $min_str . $meridiem;
        }

        return strtoupper( $token );
    }
}

/**
 * Returns the standardized 30-minute AM/PM start time options map.
 * Keys are canonical storage tokens (e.g. "10AM", "6PM", "6:30PM");
 * values are human-readable 12-hour AM/PM labels (e.g. "10:00 AM", "6:00 PM", "6:30 PM").
 *
 * @return array<string, string> Canonical token => display label map.
 */
if ( ! function_exists( 'sywat_get_standard_line_time_options' ) ) {
    function sywat_get_standard_line_time_options() {
        static $options = null;
        if ( $options !== null ) {
            return $options;
        }

        $options = array();
        // 7:00 AM (420 mins) through 9:30 PM (1290 mins) in 30-minute steps
        for ( $mins = 7 * 60; $mins <= ( 21 * 60 ) + 30; $mins += 30 ) {
            $hour_24  = intdiv( $mins, 60 );
            $minute   = $mins % 60;
            $meridiem = ( $hour_24 >= 12 ) ? 'PM' : 'AM';
            $hour_12  = $hour_24 % 12;
            if ( $hour_12 === 0 ) {
                $hour_12 = 12;
            }

            $min_padded = str_pad( (string) $minute, 2, '0', STR_PAD_LEFT );
            $token      = $hour_12 . ( $minute === 0 ? '' : ':' . $min_padded ) . $meridiem;
            $label      = $hour_12 . ':' . $min_padded . ' ' . $meridiem;

            $options[ $token ] = $label;
        }

        return $options;
    }
}

/**
 * Renders HTML <option> tags for the standardized 30-minute AM/PM line start time dropdown.
 * Normalizes legacy stored values ("6:00 PM", "6PM", "10:00 AM") so existing rows pre-select accurately,
 * and preserves non-30-minute legacy tokens as a selected fallback option so existing data is never lost.
 *
 * @param string $selected_val        Currently saved time value.
 * @param bool   $include_placeholder Whether to prepend a disabled placeholder option.
 * @return string Escaped <option> HTML markup.
 */
if ( ! function_exists( 'sywat_render_line_time_options' ) ) {
    function sywat_render_line_time_options( $selected_val = '', $include_placeholder = false ) {
        $options    = sywat_get_standard_line_time_options();
        $normalized = ( trim( (string) $selected_val ) !== '' )
            ? sywat_format_line_time_token( (string) $selected_val )
            : '';

        $html = '';
        if ( $include_placeholder ) {
            $html .= '<option value="" disabled' . selected( $normalized, '', false ) . '>-- Select Time --</option>';
        }

        // Preserve any legacy off-interval time (e.g. 6:15PM) so opening Edit never silently alters saved data
        if ( $normalized !== '' && ! isset( $options[ $normalized ] ) ) {
            $html .= '<option value="' . esc_attr( $normalized ) . '" selected>' . esc_html( trim( (string) $selected_val ) ) . '</option>';
        }

        foreach ( $options as $token => $label ) {
            $html .= '<option value="' . esc_attr( $token ) . '"' . selected( $normalized, $token, false ) . '>' . esc_html( $label ) . '</option>';
        }

        return $html;
    }
}

/**
 * Sorts an associative array of tournaments ($tournaments[ $t_name => $t_data ]) in place using
 * a hoisted O(N) pre-computed 4-tier sort key map (zero regexes or lookups inside the comparator):
 *   1. Start Date (YYYY-MM-DD ascending; undated events sort last)
 *   2. Event Tier (TFAA State -> National -> SYWAT) & End Date (YYYY-MM-DD ascending)
 *   3. Host Shop Name (A -> Z natural case-insensitive)
 *   4. Event Title (A -> Z natural case-insensitive)
 *
 * @param array $tournaments Associative array of tournaments passed by reference.
 * @param array $all_shops   Optional pre-fetched master shops map for O(1) shop name resolution.
 * @return void
 */
if ( ! function_exists( 'sywat_sort_tournaments_chronologically' ) ) {
    function sywat_sort_tournaments_chronologically( &$tournaments, $all_shops = array() ) {
        if ( ! is_array( $tournaments ) || count( $tournaments ) <= 1 ) {
            return;
        }

        if ( empty( $all_shops ) ) {
            if ( function_exists( 'sywat_get_shops' ) ) {
                $all_shops = sywat_get_shops();
            } elseif ( function_exists( 'sywat_get_all_shops' ) ) {
                $all_shops = sywat_get_all_shops();
            }
        }
        if ( ! is_array( $all_shops ) ) {
            $all_shops = array();
        }

        // Build O(1) shop lookup maps once outside the tournament loop
        $shop_id_to_name   = array();
        $norm_name_to_name = array();
        foreach ( $all_shops as $s_id => $s_info ) {
            if ( is_array( $s_info ) ) {
                $title = ! empty( $s_info['name'] )
                    ? trim( (string) $s_info['name'] )
                    : ( ! empty( $s_info['shop_name'] )
                        ? trim( (string) $s_info['shop_name'] )
                        : ( ! empty( $s_info['title'] ) ? trim( (string) $s_info['title'] ) : '' ) );
                if ( $title !== '' ) {
                    $shop_id_to_name[ (string) $s_id ]      = $title;
                    $norm_name_to_name[ strtolower( $title ) ] = $title;
                }
            }
        }

        // Single O(N) pass: pre-compute scalar sort keys for every tournament
        $sort_keys = array();
        foreach ( $tournaments as $t_key => $t_data ) {
            $t_name = ( is_array( $t_data ) && ! empty( $t_data['name'] ) ) ? trim( (string) $t_data['name'] ) : trim( (string) $t_key );

            $start_date = ( is_array( $t_data ) && ! empty( $t_data['start_date'] ) ) ? trim( (string) $t_data['start_date'] ) : '9999-12-31';
            $end_date   = ( is_array( $t_data ) && ! empty( $t_data['end_date'] ) ) ? trim( (string) $t_data['end_date'] ) : $start_date;

            $ev_type = ( is_array( $t_data ) && ! empty( $t_data['event_type'] ) ) ? trim( (string) $t_data['event_type'] ) : 'SYWAT';
            if ( strcasecmp( $ev_type, 'TFAA State' ) === 0 ) {
                $tier_weight = 1;
            } elseif ( strcasecmp( $ev_type, 'National' ) === 0 ) {
                $tier_weight = 2;
            } else {
                $tier_weight = 3;
            }

            // O(1) host shop name resolution
            $shop_name = '';
            $s_id      = ( is_array( $t_data ) && ! empty( $t_data['shop_id'] ) ) ? (string) $t_data['shop_id'] : '';
            if ( $s_id !== '' && isset( $shop_id_to_name[ $s_id ] ) ) {
                $shop_name = $shop_id_to_name[ $s_id ];
            } elseif ( is_array( $t_data ) ) {
                if ( ! empty( $t_data['official_shop_name'] ) ) {
                    $shop_name = trim( (string) $t_data['official_shop_name'] );
                } elseif ( ! empty( $t_data['shop_name'] ) ) {
                    $shop_name = trim( (string) $t_data['shop_name'] );
                } elseif ( ! empty( $t_data['location_display'] ) ) {
                    $shop_name = trim( (string) $t_data['location_display'] );
                }
            }

            if ( $shop_name === '' && $t_name !== '' ) {
                $base_title = preg_replace( '/\s*[\-\#]\s*\d+$/i', '', $t_name );
                $norm_base  = strtolower( trim( $base_title ) );
                $shop_name  = isset( $norm_name_to_name[ $norm_base ] ) ? $norm_name_to_name[ $norm_base ] : ( $base_title !== '' ? $base_title : $t_name );
            }

            $sort_keys[ $t_key ] = array(
                'start' => $start_date,
                'tier'  => $tier_weight,
                'end'   => $end_date,
                'shop'  => $shop_name,
                'name'  => $t_name,
            );
        }

        // Pure scalar comparison inside uksort (zero regexes or lookups)
        uksort( $tournaments, function( $key_a, $key_b ) use ( $sort_keys ) {
            $ka = $sort_keys[ $key_a ];
            $kb = $sort_keys[ $key_b ];

            if ( $ka['start'] !== $kb['start'] ) {
                return ( $ka['start'] < $kb['start'] ) ? -1 : 1;
            }
            if ( $ka['tier'] !== $kb['tier'] ) {
                return ( $ka['tier'] < $kb['tier'] ) ? -1 : 1;
            }
            if ( $ka['end'] !== $kb['end'] ) {
                return ( $ka['end'] < $kb['end'] ) ? -1 : 1;
            }

            $shop_cmp = strnatcasecmp( $ka['shop'], $kb['shop'] );
            if ( $shop_cmp !== 0 ) {
                return $shop_cmp;
            }

            return strnatcasecmp( $ka['name'], $kb['name'] );
        } );
    }
}

/**
 * Chronological comparator for tournament shooting lines.
 * Orders by day of week (Mon-Thu -> Friday -> Saturday -> Sunday) and 24-hour start time.
 *
 * @param array $a First line slot array.
 * @param array $b Second line slot array.
 * @return int Comparison result (-1, 0, 1).
 */
if ( ! function_exists( 'sywat_compare_tournament_lines' ) ) {
    function sywat_compare_tournament_lines( $a, $b ) {
        if ( ! is_array( $a ) || ! is_array( $b ) ) {
            return 0;
        }

        $day_weights = array(
            'mon'       => 1,
            'monday'    => 1,
            'tue'       => 2,
            'tues'      => 2,
            'tuesday'   => 2,
            'wed'       => 3,
            'wednesday' => 3,
            'thu'       => 4,
            'thur'      => 4,
            'thurs'     => 4,
            'thursday'  => 4,
            'fri'       => 5,
            'friday'    => 5,
            'sat'       => 6,
            'saturday'  => 6,
            'sun'       => 7,
            'sunday'    => 7,
        );

        $extract_sort_key = function( $line ) use ( $day_weights ) {
            $name_raw = isset( $line['name'] ) ? trim( (string) $line['name'] ) : '';
            $day_raw  = isset( $line['day'] ) ? trim( (string) $line['day'] ) : '';
            $time_raw = isset( $line['time'] ) ? trim( (string) $line['time'] ) : '';

            if ( ( $day_raw === '' || $time_raw === '' ) && $name_raw !== '' ) {
                $parts = preg_split( '/\s+/', $name_raw, 2 );
                if ( $day_raw === '' && isset( $parts[0] ) ) {
                    $day_raw = $parts[0];
                }
                if ( $time_raw === '' && isset( $parts[1] ) ) {
                    $time_raw = $parts[1];
                }
            }

            $day_norm   = strtolower( preg_replace( '/[^a-z]/i', '', $day_raw ) );
            $day_weight = isset( $day_weights[ $day_norm ] ) ? $day_weights[ $day_norm ] : 99;

            $minutes = 9999;
            if ( $time_raw !== '' && preg_match( '/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $time_raw, $m ) ) {
                $hour     = intval( $m[1] );
                $mins     = ( isset( $m[2] ) && $m[2] !== '' ) ? intval( $m[2] ) : 0;
                $meridiem = isset( $m[3] ) ? strtoupper( $m[3] ) : '';

                if ( $meridiem === '' ) {
                    if ( $hour === 6 || $hour === 12 || ( $hour >= 1 && $hour <= 5 ) ) {
                        $meridiem = 'PM';
                    } else {
                        $meridiem = 'AM';
                    }
                }

                // Archery rule: 6 o'clock lines are always PM
                if ( $hour === 6 ) {
                    $meridiem = 'PM';
                }

                if ( $meridiem === 'PM' && $hour < 12 ) {
                    $hour += 12;
                } elseif ( $meridiem === 'AM' && $hour === 12 ) {
                    $hour = 0;
                }

                $minutes = ( $hour * 60 ) + $mins;
            } elseif ( $time_raw !== '' ) {
                $parsed = strtotime( '1970-01-01 ' . $time_raw );
                if ( $parsed !== false ) {
                    $minutes = ( intval( date( 'G', $parsed ) ) * 60 ) + intval( date( 'i', $parsed ) );
                }
            }

            return array( $day_weight, $minutes, $name_raw );
        };

        list( $day_a, $min_a, $name_a ) = $extract_sort_key( $a );
        list( $day_b, $min_b, $name_b ) = $extract_sort_key( $b );

        if ( $day_a !== $day_b ) {
            return ( $day_a < $day_b ) ? -1 : 1;
        }
        if ( $min_a !== $min_b ) {
            return ( $min_a < $min_b ) ? -1 : 1;
        }
        return strnatcasecmp( $name_a, $name_b );
    }
}

/**
 * Universal Token & Qualifier Matcher for TFAA Tournament Names
 * Resolves local short names (e.g., "Fort Grard - 1", "Archery HQ - 1") to official TFAA index strings.
 */
if ( ! function_exists( 'sywat_match_tfaa_tournament_name' ) ) {
    function sywat_match_tfaa_tournament_name( $sywat_name, $tfaa_name ) {
        $get_qualifier = function( $str ) {
            if ( preg_match( '/(?:[\s\-\#]+)(\d+)\s*$/i', $str, $m ) ) {
                return (int)$m[1];
            }
            return null;
        };

        $sywat_q = $get_qualifier( $sywat_name );
        $tfaa_q  = $get_qualifier( $tfaa_name );

        if ( $sywat_q !== null && $tfaa_q !== null && $sywat_q !== $tfaa_q ) {
            return false;
        }

        $normalize_tokens = function( $str ) {
            $str = preg_replace( '/(?:[\s\-\#]+)(\d+)\s*$/i', '', $str );
            $str = strtolower( $str );
            $str = str_replace( array( '&', '-', '_', '/', ',', '.', '\'' ), ' ', $str );
            
            $noise  = array( 'archery', 'club', 'guns', 'outdoors', 'shop', 'shops', 'pro', 'academy', 'range', 'center', 'and' );
            $words  = explode( ' ', $str );
            $tokens = array();

            foreach ( $words as $w ) {
                $w = trim( $w );
                if ( ! empty( $w ) && ! in_array( $w, $noise ) && strlen( $w ) > 1 ) {
                    $tokens[] = $w;
                }
            }
            return $tokens;
        };

        $sywat_tokens = $normalize_tokens( $sywat_name );
        $tfaa_tokens  = $normalize_tokens( $tfaa_name );

        if ( empty( $sywat_tokens ) ) {
            $clean_s = strtolower( trim( preg_replace( '/\s+/', ' ', str_replace( '-', ' ', $sywat_name ) ) ) );
            $clean_t = strtolower( trim( preg_replace( '/\s+/', ' ', str_replace( '-', ' ', $tfaa_name ) ) ) );
            return ( stripos( $clean_t, $clean_s ) !== false || stripos( $clean_s, $clean_t ) !== false );
        }

        $raw_tfaa_lower = strtolower( $tfaa_name );
        foreach ( $sywat_tokens as $token ) {
            if ( strpos( $raw_tfaa_lower, $token ) === false && ! in_array( $token, $tfaa_tokens ) ) {
                return false;
            }
        }

        return true;
    }
}

/**
 * Parses raw human line string into structured line slot arrays.
 * Enforces standardized day labels, named slot titles, and chronological Fri->Sat->Sun order.
 *
 * @param string $line_str      Raw schedule string from import file or input.
 * @param int    $default_20yd  Default 20-yard line capacity.
 * @param int    $default_cub   Default Cub line capacity.
 * @return array                Array of structured line slot definitions.
 */
if ( ! function_exists( 'sywat_parse_imported_line_schedule' ) ) {
    function sywat_parse_imported_line_schedule( $line_str, $default_20yd = 20, $default_cub = 5 ) {
        $lines = array();
        if ( empty( trim( $line_str ) ) ) {
            return array(
                array( 'day' => 'Saturday', 'time' => '10AM', 'name' => 'Saturday 10AM', 'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub ),
                array( 'day' => 'Saturday', 'time' => '2PM',  'name' => 'Saturday 2PM',  'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub ),
                array( 'day' => 'Sunday',   'time' => '10AM', 'name' => 'Sunday 10AM',   'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub ),
                array( 'day' => 'Sunday',   'time' => '2PM',  'name' => 'Sunday 2PM',    'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub ),
            );
        }

        $lower = strtolower( $line_str );

        // 1. Friday Line Check
        $fri_match_str = '';
        if ( preg_match( '/fri(?:day)?[\s\-]*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $line_str, $m ) ) {
            $fri_match_str = $m[0];
            $time          = sywat_format_line_time_token( $m[1] );
            $lines[]       = array( 'day' => 'Friday', 'time' => $time, 'name' => 'Friday ' . $time, 'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub );
        }

        // 2. Saturday & Sunday Checks
        $has_sat = ( strpos( $lower, 'sat' ) !== false );
        $has_sun = ( strpos( $lower, 'sun' ) !== false && strpos( $lower, 'no sunday' ) === false );

        // Remove Friday clause to isolate Sat/Sun time tokens
        $sat_sun_str = ! empty( $fri_match_str ) ? str_replace( $fri_match_str, '', $line_str ) : $line_str;

        $times = array();
        if ( preg_match_all( '/\b(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\b/i', $sat_sun_str, $time_matches ) ) {
            foreach ( $time_matches[1] as $t ) {
                $formatted = sywat_format_line_time_token( $t );
                if ( ! empty( $formatted ) && ! in_array( $formatted, $times ) ) {
                    $times[] = $formatted;
                }
            }
        }

        if ( empty( $times ) ) {
            $times = array( '10AM', '2PM' );
        }

        if ( $has_sat ) {
            foreach ( $times as $t ) {
                $lines[] = array( 'day' => 'Saturday', 'time' => $t, 'name' => 'Saturday ' . $t, 'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub );
            }
        }

        if ( $has_sun ) {
            foreach ( $times as $t ) {
                $lines[] = array( 'day' => 'Sunday', 'time' => $t, 'name' => 'Sunday ' . $t, 'cap_20yd' => $default_20yd, 'cap_cub' => $default_cub );
            }
        }

        if ( count( $lines ) > 1 ) {
            usort( $lines, 'sywat_compare_tournament_lines' );
        }

        return $lines;
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
            $lib_path  = plugin_dir_path( __FILE__ ) . '../lib/SimpleXLSX.php';
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
                        'start_date'           => isset( $data[0] ) ? trim( (string) $data[0] ) : '',
                        'end_date'             => isset( $data[1] ) ? trim( (string) $data[1] ) : '',
                        'host_shop_city'       => isset( $data[2] ) ? trim( (string) $data[2] ) : '',
                        'line_times'           => isset( $data[3] ) ? trim( (string) $data[3] ) : '',
                        'event_type'           => isset( $data[4] ) ? trim( (string) $data[4] ) : '',
                        'national_event_title' => isset( $data[5] ) ? trim( (string) $data[5] ) : '',
                        'notes'                => isset( $data[6] ) ? trim( (string) $data[6] ) : '',
                    );
                }
            }
            fclose( $handle );
        }

        return $raw_rows;
    }
}

/**
 * Resolves standard line capacity defaults (20yd and Cub) for a given shop dataset array, shop ID, or shop name string.
 *
 * @param array|string $shop_data_or_id Shop dataset array, shop ID, or shop name string.
 * @return array Array containing 'cap_20yd' and 'cap_cub'.
 */
if ( ! function_exists( 'sywat_get_shop_capacity_defaults' ) ) {
    function sywat_get_shop_capacity_defaults( $shop_data_or_id ) {
        $cap_20yd  = 20;
        $cap_cub   = 5;
        $shop_data = array();

        if ( is_string( $shop_data_or_id ) && ! empty( $shop_data_or_id ) ) {
            $master_shops = function_exists( 'sywat_get_all_shops' ) ? sywat_get_all_shops() : ( function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array() );
            if ( ! is_array( $master_shops ) ) {
                $master_shops = array();
            }

            if ( isset( $master_shops[$shop_data_or_id] ) && is_array( $master_shops[$shop_data_or_id] ) ) {
                $shop_data = $master_shops[$shop_data_or_id];
            } else {
                $search_str = strtolower( trim( $shop_data_or_id ) );
                foreach ( $master_shops as $s_id => $s_info ) {
                    if ( is_array( $s_info ) ) {
                        $s_name  = isset( $s_info['name'] ) ? strtolower( trim( $s_info['name'] ) ) : '';
                        $s_title = isset( $s_info['title'] ) ? strtolower( trim( $s_info['title'] ) ) : '';
                        $s_key   = strtolower( trim( (string) $s_id ) );

                        if ( $s_key === $search_str || $s_name === $search_str || $s_title === $search_str ) {
                            $shop_data = $s_info;
                            break;
                        }
                    }
                }
            }
        } elseif ( is_array( $shop_data_or_id ) ) {
            $shop_data = $shop_data_or_id;
        }

        if ( is_array( $shop_data ) && ! empty( $shop_data ) ) {
            // 1. Check top-level legacy keys first
            if ( isset( $shop_data['cap_20yd'] ) && ! empty( $shop_data['cap_20yd'] ) ) {
                $cap_20yd = intval( $shop_data['cap_20yd'] );
            } elseif ( isset( $shop_data['capacity_20yd'] ) && ! empty( $shop_data['capacity_20yd'] ) ) {
                $cap_20yd = intval( $shop_data['capacity_20yd'] );
            } elseif ( isset( $shop_data['lanes_20yd'] ) && ! empty( $shop_data['lanes_20yd'] ) ) {
                $cap_20yd = intval( $shop_data['lanes_20yd'] );
            } elseif ( isset( $shop_data['max_20yd'] ) && ! empty( $shop_data['max_20yd'] ) ) {
                $cap_20yd = intval( $shop_data['max_20yd'] );
            } elseif ( isset( $shop_data['cap_20'] ) && ! empty( $shop_data['cap_20'] ) ) {
                $cap_20yd = intval( $shop_data['cap_20'] );
            }

            if ( isset( $shop_data['cap_cub'] ) && ! empty( $shop_data['cap_cub'] ) ) {
                $cap_cub = intval( $shop_data['cap_cub'] );
            } elseif ( isset( $shop_data['capacity_cub'] ) && ! empty( $shop_data['capacity_cub'] ) ) {
                $cap_cub = intval( $shop_data['capacity_cub'] );
            } elseif ( isset( $shop_data['cub_cap'] ) && ! empty( $shop_data['cub_cap'] ) ) {
                $cap_cub = intval( $shop_data['cub_cap'] );
            } elseif ( isset( $shop_data['max_cub'] ) && ! empty( $shop_data['max_cub'] ) ) {
                $cap_cub = intval( $shop_data['max_cub'] );
            }

            // 2. Check nested 'default_lines' configuration (Takes precedence)
            if ( isset( $shop_data['default_lines'] ) && is_array( $shop_data['default_lines'] ) && isset( $shop_data['default_lines'][0] ) ) {
                $first_line = $shop_data['default_lines'][0];
                if ( isset( $first_line['cap_20yd'] ) && $first_line['cap_20yd'] !== '' ) {
                    $cap_20yd = intval( $first_line['cap_20yd'] );
                }
                if ( isset( $first_line['cap_cub'] ) && $first_line['cap_cub'] !== '' ) {
                    $cap_cub = intval( $first_line['cap_cub'] );
                }
            }
        }

        return array(
            'cap_20yd' => $cap_20yd,
            'cap_cub'  => $cap_cub,
        );
    }
}

/**
 * Builds the default 4-line template using host shop capacity limits and canonical time tokens.
 *
 * @param array|string $shop_data_or_id Optional shop dataset array, shop ID, or shop name string.
 * @return array Default lines template.
 */
if ( ! function_exists( 'sywat_get_default_tournament_lines' ) ) {
    function sywat_get_default_tournament_lines( $shop_data_or_id = array() ) {
        $caps = sywat_get_shop_capacity_defaults( $shop_data_or_id );
        return array(
            array( 'day' => 'Saturday', 'time' => '10AM', 'name' => 'Saturday 10AM', 'cap_20yd' => $caps['cap_20yd'], 'cap_cub' => $caps['cap_cub'] ),
            array( 'day' => 'Saturday', 'time' => '2PM',  'name' => 'Saturday 2PM',  'cap_20yd' => $caps['cap_20yd'], 'cap_cub' => $caps['cap_cub'] ),
            array( 'day' => 'Sunday',   'time' => '10AM', 'name' => 'Sunday 10AM',   'cap_20yd' => $caps['cap_20yd'], 'cap_cub' => $caps['cap_cub'] ),
            array( 'day' => 'Sunday',   'time' => '2PM',  'name' => 'Sunday 2PM',    'cap_20yd' => $caps['cap_20yd'], 'cap_cub' => $caps['cap_cub'] ),
        );
    }
}

if ( ! function_exists( 'sywat_normalize_dist' ) ) {
    function sywat_normalize_dist( $dist_str ) {
        return ( strcasecmp( trim( $dist_str ), 'cub' ) === 0 || strcasecmp( trim( $dist_str ), '10yd' ) === 0 || strcasecmp( trim( $dist_str ), '10 yards' ) === 0 ) ? 'Cub' : '20yd';
    }
}

if ( ! function_exists( 'sywat_get_line_max_cap' ) ) {
    function sywat_get_line_max_cap( $line_data, $dist ) {
        if ( $dist === 'Cub' ) {
            return isset( $line_data['cap_cub'] ) ? intval( $line_data['cap_cub'] ) : 5;
        }
        return isset( $line_data['cap_20yd'] ) ? intval( $line_data['cap_20yd'] ) : 20;
    }
}

/**
 * Counts registered archers for a specific line and target distance.
 * Explicitly excludes Waitlisted and Cancelled archers from physical line occupancy counts.
 */
if ( ! function_exists( 'sywat_count_registered' ) ) {
    function sywat_count_registered( $t_name, $l_idx, $dist, $registrations ) {
        $count = 0;
        $norm_target_dist = sywat_normalize_dist( $dist );

        foreach ( $registrations as $reg ) {
            if ( ! isset( $reg['tournament'] ) || strcasecmp( trim( $reg['tournament'] ), trim( $t_name ) ) !== 0 ) {
                continue;
            }

            // Exclude Waitlisted and Cancelled archers from active target butt occupancy
            $status = isset( $reg['status'] ) ? trim( $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Waitlist' ) === 0 || strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            $reg_dist = isset( $reg['distance'] ) ? sywat_normalize_dist( $reg['distance'] ) : '20yd';
            if ( $reg_dist !== $norm_target_dist ) {
                continue;
            }

            // Match by numerical array index (e.g. 0 === 0)
            $match_idx = isset( $reg['line_index'] ) && (string)$reg['line_index'] === (string)$l_idx;

            // Fallback match: check if line_name or line_index contains the line text name
            $match_name = false;
            if ( isset( $reg['line_name'] ) && is_string( $l_idx ) && strcasecmp( trim( $reg['line_name'] ), trim( $l_idx ) ) === 0 ) {
                $match_name = true;
            }
            if ( isset( $reg['line_index'] ) && is_string( $reg['line_index'] ) && ! is_numeric( $reg['line_index'] ) ) {
                if ( (string)$reg['line_index'] === (string)$l_idx ) {
                    $match_name = true;
                }
            }

            if ( $match_idx || $match_name ) {
                $count++;
            }
        }
        return $count;
    }
}

/**
 * Gets total active registered archers count for a tournament.
 * Excludes Waitlisted and Cancelled archers.
 */
if ( ! function_exists( 'sywat_get_tournament_count' ) ) {
    function sywat_get_tournament_count( $t_name, $registrations ) {
        $count = 0;
        foreach ( $registrations as $reg ) {
            if ( isset( $reg['tournament'] ) && strcasecmp( trim( $reg['tournament'] ), trim( $t_name ) ) === 0 ) {
                $status = isset( $reg['status'] ) ? trim( $reg['status'] ) : '';
                if ( strcasecmp( $status, 'Waitlist' ) === 0 || strcasecmp( $status, 'Cancelled' ) === 0 ) {
                    continue;
                }
                $count++;
            }
        }
        return $count;
    }
}

if ( ! function_exists( 'sywat_format_tournament_dates' ) ) {
    function sywat_format_tournament_dates( $start_date, $end_date ) {
        if ( empty( $start_date ) && empty( $end_date ) ) return '';
        if ( empty( $end_date ) || $start_date === $end_date ) {
            return date( 'F j, Y', strtotime( $start_date ) );
        }

        $s_time = strtotime( $start_date );
        $e_time = strtotime( $end_date );

        if ( date( 'Y-m', $s_time ) === date( 'Y-m', $e_time ) ) {
            return date( 'F j', $s_time ) . ' – ' . date( 'j, Y', $e_time );
        } elseif ( date( 'Y', $s_time ) === date( 'Y', $e_time ) ) {
            return date( 'M j', $s_time ) . ' – ' . date( 'M j, Y', $e_time );
        }
        return date( 'M j, Y', $s_time ) . ' – ' . date( 'M j, Y', $e_time );
    }
}

/**
 * Retrieves tournament shooting lines sorted chronologically (Friday -> Saturday -> Sunday, then by time)
 * while preserving each line's original array index key (uasort) so stored registration line_index mappings remain intact.
 *
 * @param array $t_data Tournament configuration array or raw lines array.
 * @return array Key-preserved, chronologically sorted line slots.
 */
if ( ! function_exists( 'sywat_get_tournament_lines' ) ) {
    function sywat_get_tournament_lines( $t_data ) {
        if ( ! is_array( $t_data ) ) {
            return array();
        }
        $lines = ( isset( $t_data['lines'] ) && is_array( $t_data['lines'] ) ) ? $t_data['lines'] : $t_data;
        if ( count( $lines ) > 1 ) {
            uasort( $lines, 'sywat_compare_tournament_lines' );
        }
        return $lines;
    }
}

if ( ! function_exists( 'sywat_get_tournament_shop_id' ) ) {
    function sywat_get_tournament_shop_id( $t_data ) {
        if ( is_array( $t_data ) && isset( $t_data['shop_id'] ) && ! empty( $t_data['shop_id'] ) ) {
            return $t_data['shop_id'];
        }
        return '';
    }
}

/**
 * Resolves host archery shop display name with smart waterfall resolution.
 *
 * @param array  $t_data    Tournament configuration array.
 * @param string $t_name    Optional tournament title.
 * @param array  $all_shops Optional master shops array.
 * @return string Resolved shop display name.
 */
if ( ! function_exists( 'sywat_get_tournament_shop_name' ) ) {
    function sywat_get_tournament_shop_name( $t_data, $t_name = '', $all_shops = array() ) {
        if ( empty( $all_shops ) && function_exists( 'sywat_get_all_shops' ) ) {
            $all_shops = sywat_get_all_shops();
        } elseif ( empty( $all_shops ) && function_exists( 'sywat_get_shops' ) ) {
            $all_shops = sywat_get_shops();
        }
        if ( ! is_array( $all_shops ) ) {
            $all_shops = array();
        }

        // 1. Direct shop_id lookup in $all_shops
        $s_id = function_exists( 'sywat_get_tournament_shop_id' ) ? sywat_get_tournament_shop_id( $t_data ) : ( is_array( $t_data ) && isset( $t_data['shop_id'] ) ? $t_data['shop_id'] : '' );
        if ( ! empty( $s_id ) && isset( $all_shops[$s_id] ) && is_array( $all_shops[$s_id] ) ) {
            $s_info = $all_shops[$s_id];
            $name   = ! empty( $s_info['name'] ) ? $s_info['name'] : ( ! empty( $s_info['shop_name'] ) ? $s_info['shop_name'] : ( ! empty( $s_info['title'] ) ? $s_info['title'] : '' ) );
            if ( ! empty( $name ) ) return $name;
        }

        // 2. Stored explicit shop name metadata in tournament data
        if ( is_array( $t_data ) ) {
            if ( ! empty( $t_data['official_shop_name'] ) ) return $t_data['official_shop_name'];
            if ( ! empty( $t_data['shop_name'] ) ) return $t_data['shop_name'];
            if ( ! empty( $t_data['location_display'] ) ) return $t_data['location_display'];
        }

        // Build shop lookup map by title
        $shop_map = array();
        foreach ( $all_shops as $id => $info ) {
            if ( is_array( $info ) ) {
                $title = ! empty( $info['name'] ) ? $info['name'] : ( ! empty( $info['shop_name'] ) ? $info['shop_name'] : ( ! empty( $info['title'] ) ? $info['title'] : '' ) );
                if ( ! empty( $title ) ) {
                    $shop_map[ strtolower( trim( $title ) ) ] = $title;
                }
            }
        }

        // 3. Fallback: Parse shop name from tournament title (e.g. "Archery HQ-1" -> "Archery HQ")
        $title_to_parse = ! empty( $t_name ) ? $t_name : ( is_array( $t_data ) && isset( $t_data['name'] ) ? $t_data['name'] : '' );
        if ( ! empty( $title_to_parse ) ) {
            $base_title = preg_replace( '/\s*[\-\#]\s*\d+$/i', '', trim( $title_to_parse ) );
            $norm_base  = strtolower( trim( $base_title ) );

            if ( isset( $shop_map[$norm_base] ) ) {
                return $shop_map[$norm_base];
            }

            foreach ( $shop_map as $norm_shop => $official_title ) {
                if ( ! empty( $norm_shop ) && strpos( $norm_base, $norm_shop ) !== false ) {
                    return $official_title;
                }
            }

            if ( ! empty( $base_title ) && strcasecmp( $base_title, 'TFAA State Indoor Championship' ) !== 0 ) {
                return $base_title;
            }
        }

        return 'Host Archery Shop';
    }
}

if ( ! function_exists( 'sywat_get_tournament_season_slug' ) ) {
    function sywat_get_tournament_season_slug( $t_data ) {
        if ( is_array( $t_data ) && isset( $t_data['season_slug'] ) && ! empty( $t_data['season_slug'] ) ) {
            return $t_data['season_slug'];
        }
        if ( is_array( $t_data ) && isset( $t_data['season'] ) && ! empty( $t_data['season'] ) ) {
            return $t_data['season'];
        }
        $active_season = function_exists('sywat_get_active_season') ? sywat_get_active_season() : array();
        return isset( $active_season['slug'] ) ? $active_season['slug'] : 'indoor-2026-2027';
    }
}

// Check if an archer is already registered on a specific line
if ( ! function_exists( 'sywat_is_archer_on_line' ) ) {
    function sywat_is_archer_on_line( $t_name, $l_idx, $archer_name, $registrations ) {
        $norm_target_name = strtolower( trim( $archer_name ) );
        if ( empty( $norm_target_name ) ) return false;

        foreach ( $registrations as $reg ) {
            if ( ! isset( $reg['tournament'] ) || strcasecmp( trim( $reg['tournament'] ), trim( $t_name ) ) !== 0 ) {
                continue;
            }

            $reg_name = '';
            if ( ! empty( $reg['name'] ) ) {
                $reg_name = strtolower( trim( $reg['name'] ) );
            } elseif ( ! empty( $reg['archer_name'] ) ) {
                $reg_name = strtolower( trim( $reg['archer_name'] ) );
            }

            if ( $reg_name === $norm_target_name ) {
                $match_idx = isset( $reg['line_index'] ) && (string)$reg['line_index'] === (string)$l_idx;
                $match_name = false;
                if ( isset( $reg['line_name'] ) && is_string( $l_idx ) && strcasecmp( trim( $reg['line_name'] ), trim( $l_idx ) ) === 0 ) {
                    $match_name = true;
                }
                if ( isset( $reg['line_index'] ) && is_string( $reg['line_index'] ) && ! is_numeric( $reg['line_index'] ) ) {
                    if ( (string)$reg['line_index'] === (string)$l_idx ) {
                        $match_name = true;
                    }
                }

                if ( $match_idx || $match_name ) {
                    return true;
                }
            }
        }
        return false;
    }
}

/**
 * Resolves a host shop's default Public Online Registration policy (On/Off + Days Before Event).
 * Supports O(1) lookup when a pre-fetched $all_shops array is supplied during batch imports.
 *
 * @param array|string $shop_data_or_id Shop record array, shop ID, or shop name.
 * @param array        $all_shops       Optional pre-fetched shops directory map.
 * @return array{public_reg_default: string, public_reg_days_before: int}
 */
if ( ! function_exists( 'sywat_get_shop_public_reg_defaults' ) ) {
    function sywat_get_shop_public_reg_defaults( $shop_data_or_id, $all_shops = array() ) {
        $enabled     = '0';
        $days_before = 15;
        $shop_data   = array();

        if ( is_array( $shop_data_or_id ) ) {
            $shop_data = $shop_data_or_id;
        } elseif ( is_string( $shop_data_or_id ) && trim( $shop_data_or_id ) !== '' ) {
            if ( empty( $all_shops ) ) {
                if ( function_exists( 'sywat_get_shops' ) ) {
                    $all_shops = sywat_get_shops();
                } elseif ( function_exists( 'sywat_get_all_shops' ) ) {
                    $all_shops = sywat_get_all_shops();
                }
            }
            if ( is_array( $all_shops ) ) {
                $lookup_key = trim( $shop_data_or_id );
                if ( isset( $all_shops[ $lookup_key ] ) && is_array( $all_shops[ $lookup_key ] ) ) {
                    $shop_data = $all_shops[ $lookup_key ];
                } else {
                    $search_lower = strtolower( $lookup_key );
                    foreach ( $all_shops as $s_id => $s_info ) {
                        if ( is_array( $s_info ) ) {
                            $s_name  = isset( $s_info['name'] ) ? strtolower( trim( (string) $s_info['name'] ) ) : '';
                            $s_title = isset( $s_info['title'] ) ? strtolower( trim( (string) $s_info['title'] ) ) : '';
                            $s_key   = strtolower( trim( (string) $s_id ) );
                            if ( $s_key === $search_lower || $s_name === $search_lower || $s_title === $search_lower ) {
                                $shop_data = $s_info;
                                break;
                            }
                        }
                    }
                }
            }
        }

        if ( is_array( $shop_data ) && ! empty( $shop_data ) ) {
            $enabled = ! empty( $shop_data['public_reg_default'] ) ? '1' : '0';
            if ( isset( $shop_data['public_reg_days_before'] ) && $shop_data['public_reg_days_before'] !== '' ) {
                $days_before = max( 0, min( 365, intval( $shop_data['public_reg_days_before'] ) ) );
            }
        }

        return array(
            'public_reg_default'     => $enabled,
            'public_reg_days_before' => $days_before,
        );
    }
}

/**
 * Evaluates a tournament's Public Online Registration gatekeeper status dynamically on read.
 * Compares today's date (site timezone) against (start_date - days_before) and end_date.
 *
 * @param array  $t_data    Tournament configuration array.
 * @param string $today_ymd Optional 'YYYY-MM-DD' override for deterministic evaluation.
 * @return array{
 *     status: string,
 *     is_open: bool,
 *     enabled: bool,
 *     days_before: int,
 *     opens_date: string,
 *     opens_label: string,
 *     reason: string
 * }
 */
if ( ! function_exists( 'sywat_get_public_registration_status' ) ) {
    function sywat_get_public_registration_status( $t_data, $today_ymd = '' ) {
        $enabled     = is_array( $t_data ) && ! empty( $t_data['public_reg_enabled'] );
        $days_before = ( is_array( $t_data ) && isset( $t_data['public_reg_days_before'] ) && $t_data['public_reg_days_before'] !== '' )
            ? max( 0, min( 365, intval( $t_data['public_reg_days_before'] ) ) )
            : 15;

        if ( ! $enabled ) {
            return array(
                'status'      => 'closed',
                'is_open'     => false,
                'enabled'     => false,
                'days_before' => $days_before,
                'opens_date'  => '',
                'opens_label' => '',
                'reason'      => 'disabled',
            );
        }

        $today = ( $today_ymd !== '' )
            ? trim( (string) $today_ymd )
            : ( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' ) );

        $start_date = ( is_array( $t_data ) && ! empty( $t_data['start_date'] ) ) ? trim( (string) $t_data['start_date'] ) : '';
        $end_date   = ( is_array( $t_data ) && ! empty( $t_data['end_date'] ) ) ? trim( (string) $t_data['end_date'] ) : $start_date;

        if ( $end_date !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) && $today > $end_date ) {
            return array(
                'status'      => 'closed',
                'is_open'     => false,
                'enabled'     => true,
                'days_before' => $days_before,
                'opens_date'  => '',
                'opens_label' => '',
                'reason'      => 'past',
            );
        }

        $opens_date  = '';
        $opens_label = '';

        if ( $start_date !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
            $start_ts = strtotime( $start_date . ' 00:00:00' );
            if ( $start_ts !== false ) {
                $opens_ts    = ( $days_before > 0 ) ? strtotime( '-' . $days_before . ' days', $start_ts ) : $start_ts;
                $opens_date  = ( $opens_ts !== false ) ? date( 'Y-m-d', $opens_ts ) : $start_date;
                $opens_label = ( $opens_ts !== false ) ? date( 'M j', $opens_ts ) : '';

                if ( $days_before > 0 && $today < $opens_date ) {
                    return array(
                        'status'      => 'opens_soon',
                        'is_open'     => false,
                        'enabled'     => true,
                        'days_before' => $days_before,
                        'opens_date'  => $opens_date,
                        'opens_label' => $opens_label,
                        'reason'      => 'opens_soon',
                    );
                }
            }
        }

        return array(
            'status'      => 'open',
            'is_open'     => true,
            'enabled'     => true,
            'days_before' => $days_before,
            'opens_date'  => $opens_date,
            'opens_label' => $opens_label,
            'reason'      => 'open',
        );
    }
}
