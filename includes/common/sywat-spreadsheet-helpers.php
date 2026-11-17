<?php
/**
 * Shared Common Helper: Native .xlsx Spreadsheet Builder & Parser
 * Path: includes/common/sywat-spreadsheet-helpers.php
 * Description: Provides pure, zero-dependency OpenXML (.xlsx) template generation with Excel Data Validation dropdowns and hardened .xlsx worksheet parsing using PHP ZipArchive and SimpleXML.
 * Compliance: Pure helper layer — zero database queries, zero output at include time, zero inline styles, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Return the standardized 9-column header labels for bulk registration spreadsheets.
 *
 * @return array Ordered list of 9 column header strings (Columns A–I).
 */
if ( ! function_exists( 'sywat_get_import_template_headers' ) ) {
    function sywat_get_import_template_headers() {
        return array(
            'Contact Name',
            'Phone',
            'Email',
            'Archer Name',
            'Line Day (Friday / Saturday / Sunday)',
            'Line Time (HH:MM AM/PM)',
            'Distance (10 yds / 20 yds)',
            'Rate Tier (Youth / Adult)',
            'Paid Status (Paid / Unpaid)',
        );
    }
}

/**
 * Build the standard 31 thirty-minute time slots from 7:00 AM through 10:00 PM.
 *
 * @return array Ordered list of formatted 12-hour time strings (e.g., "7:00 AM", "7:30 AM", ..., "10:00 PM").
 */
if ( ! function_exists( 'sywat_get_standard_import_time_slots' ) ) {
    function sywat_get_standard_import_time_slots() {
        $slots = array();
        for ( $hour = 7; $hour <= 22; $hour++ ) {
            $minutes_list = ( $hour === 22 ) ? array( 0 ) : array( 0, 30 );
            foreach ( $minutes_list as $min ) {
                $ampm = ( $hour >= 12 ) ? 'PM' : 'AM';
                $h12  = $hour % 12;
                if ( $h12 === 0 ) {
                    $h12 = 12;
                }
                $slots[] = $h12 . ':' . str_pad( (string) $min, 2, '0', STR_PAD_LEFT ) . ' ' . $ampm;
            }
        }
        return $slots;
    }
}

/**
 * Normalize any time string or Excel day-fraction into a canonical alphanumeric key
 * (e.g., "09:00 AM", "9:00 AM", "9AM", "09:00:00" -> "9am"; "6:30 PM", "18:30" -> "630pm").
 *
 * @param string $raw_time Raw time string from spreadsheet or tournament line config.
 * @return string          Canonical lowercase alphanumeric time token (e.g., "10am", "630pm").
 */
if ( ! function_exists( 'sywat_normalize_import_line_time' ) ) {
    function sywat_normalize_import_line_time( $raw_time ) {
        $val = trim( (string) $raw_time );
        if ( $val === '' ) {
            return '';
        }

        // Convert Excel numeric day fraction (e.g., 0.416666 -> 10:00 AM)
        if ( is_numeric( $val ) && (float) $val > 0 && (float) $val < 1 ) {
            $total_seconds = (int) round( (float) $val * 86400 );
            $val           = gmdate( 'g:i A', $total_seconds );
        }

        // Handle 24-hour or HH:MM:SS formats without AM/PM (e.g., "14:00:00", "14:00", "10:00:00")
        if ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $val, $m ) ) {
            $hour = (int) $m[1];
            $min  = (int) $m[2];
            $ampm = ( $hour >= 12 && $hour < 24 ) ? 'PM' : 'AM';
            $h12  = $hour % 12;
            if ( $h12 === 0 ) {
                $h12 = 12;
            }
            $val = $h12 . ':' . str_pad( (string) $min, 2, '0', STR_PAD_LEFT ) . ' ' . $ampm;
        }

        // Handle HH:MM:SS AM/PM (e.g., "10:00:00 AM" -> "10:00 AM")
        $val = preg_replace( '/(\d{1,2}:\d{2}):\d{2}\s*(AM|PM)/i', '$1 $2', $val );

        // Strip leading zero on hour ("09:00 AM" -> "9:00 AM", "09AM" -> "9AM")
        $val = preg_replace( '/\b0(\d)(?::(\d{2}))?\s*(AM|PM)\b/i', '$1:$2 $3', $val );
        $val = str_replace( ': ', ' ', $val );

        // Strip ":00" minutes ("10:00 AM" -> "10 AM")
        $val = preg_replace( '/\b(\d{1,2}):00\s*(AM|PM)\b/i', '$1 $2', $val );

        return strtolower( preg_replace( '/[^a-z0-9]/i', '', $val ) );
    }
}

/**
 * Format a tournament line's time cleanly as "10:00 AM" or "6:30 PM" for Excel display.
 *
 * @param string $raw_time Raw line time string.
 * @return string          Formatted 12-hour time string (e.g., "10:00 AM").
 */
if ( ! function_exists( 'sywat_format_template_line_time' ) ) {
    function sywat_format_template_line_time( $raw_time ) {
        $val = trim( (string) $raw_time );
        if ( $val === '' ) {
            return '';
        }

        if ( preg_match( '/^(\d{1,2})(?::(\d{2}))?(?::\d{2})?\s*(AM|PM)$/i', $val, $m ) ) {
            $h    = (int) $m[1];
            $min  = isset( $m[2] ) && $m[2] !== '' ? $m[2] : '00';
            $ampm = strtoupper( $m[3] );
            return $h . ':' . $min . ' ' . $ampm;
        }

        if ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $val, $m ) ) {
            $hour = (int) $m[1];
            $min  = $m[2];
            $ampm = ( $hour >= 12 && $hour < 24 ) ? 'PM' : 'AM';
            $h12  = $hour % 12;
            if ( $h12 === 0 ) {
                $h12 = 12;
            }
            return $h12 . ':' . $min . ' ' . $ampm;
        }

        return $val;
    }
}

/**
 * Convert an Excel column letter reference (e.g., "A", "I", "AA") to a 0-based column index.
 *
 * @param string $col_letters Uppercase or lowercase column letters.
 * @return int                0-based column index.
 */
if ( ! function_exists( 'sywat_xlsx_col_letter_to_index' ) ) {
    function sywat_xlsx_col_letter_to_index( $col_letters ) {
        $letters = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $col_letters ) );
        $len     = strlen( $letters );
        $index   = 0;
        for ( $i = 0; $i < $len; $i++ ) {
            $index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
        }
        return max( 0, $index - 1 );
    }
}

/**
 * Build a native .xlsx workbook with strict Excel Data Validation dropdowns:
 * - Col A (Contact Name), Col C (Email), Col D (Archer Name): Unrestricted text
 * - Col B (Phone): Formatted as explicit Text (@) with 10-digit prompt
 * - Col E (Line Day): Strict Stop dropdown -> Friday, Saturday, Sunday
 * - Col F (Line Time): Strict Stop dropdown -> 30-min increments 7:00 AM–10:00 PM ('Tournament Info'!$G$2:$G$32)
 * - Col G (Distance): Strict Stop dropdown -> 10 yds, 20 yds
 * - Col H (Rate Tier): Strict Stop dropdown -> Youth, Adult
 * - Col I (Paid Status): Strict Stop dropdown -> Paid, Unpaid
 * - Sheet 2 ("Tournament Info"): Active tournament lines, capacities, rates, Family Cap info, and G2:G32 time slots.
 *
 * @param string $filepath       Destination file path for the generated .xlsx archive.
 * @param string $tourn_name     Selected tournament name.
 * @param array  $tourn_data     Tournament configuration array.
 * @param array  $t_lines        Tournament lines array.
 * @param array  $pricing_info   Optional pre-resolved pricing summary ['adult_rate' => string, 'youth_rate' => string, 'family_cap' => string].
 * @return bool                  True on success, false if ZipArchive cannot build the file.
 */
if ( ! function_exists( 'sywat_build_import_template_xlsx' ) ) {
    function sywat_build_import_template_xlsx( $filepath, $tourn_name, $tourn_data = array(), $t_lines = array(), $pricing_info = array() ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return false;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            return false;
        }

        $allowed_days   = array( 'Friday', 'Saturday', 'Sunday' );
        $time_slots     = sywat_get_standard_import_time_slots();
        $line_info_rows = array();
        $first_day      = 'Saturday';
        $first_time     = '10:00 AM';
        $found_example  = false;

        if ( is_array( $t_lines ) ) {
            foreach ( $t_lines as $ld ) {
                $day  = is_array( $ld ) && ! empty( $ld['day'] ) ? trim( (string) $ld['day'] ) : '';
                $time = is_array( $ld ) && ! empty( $ld['time'] )
                    ? trim( (string) $ld['time'] )
                    : ( is_array( $ld ) && ! empty( $ld['start_time'] ) ? trim( (string) $ld['start_time'] ) : '' );
                $name = is_array( $ld ) && ! empty( $ld['name'] ) ? trim( (string) $ld['name'] ) : '';

                if ( ( $day === '' || $time === '' ) && $name !== '' ) {
                    if ( preg_match( '/\b(Friday|Saturday|Sunday)\b/i', $name, $dm ) ) {
                        $day = ucfirst( strtolower( $dm[1] ) );
                    }
                    if ( preg_match( '/\b(\d{1,2}(?::\d{2})?\s*(?:AM|PM))\b/i', $name, $tm ) ) {
                        $time = $tm[1];
                    }
                }

                $fmt_time = sywat_format_template_line_time( $time );

                if ( ! $found_example && in_array( $day, $allowed_days, true ) && in_array( $fmt_time, $time_slots, true ) ) {
                    $first_day     = $day;
                    $first_time    = $fmt_time;
                    $found_example = true;
                }

                $cap_20  = is_array( $ld ) && isset( $ld['max_20yd'] ) ? (int) $ld['max_20yd'] : ( is_array( $ld ) && isset( $ld['cap_20yd'] ) ? (int) $ld['cap_20yd'] : 20 );
                $cap_cub = is_array( $ld ) && isset( $ld['max_cub'] ) ? (int) $ld['max_cub'] : ( is_array( $ld ) && isset( $ld['cap_cub'] ) ? (int) $ld['cap_cub'] : 5 );

                $line_info_rows[] = array(
                    'label'   => $name !== '' ? $name : trim( $day . ' ' . $fmt_time ),
                    'day'     => $day !== '' ? $day : '—',
                    'time'    => $fmt_time !== '' ? $fmt_time : '—',
                    'cap_20'  => $cap_20,
                    'cap_cub' => $cap_cub,
                );
            }
        }

        $adult_rate = isset( $pricing_info['adult_rate'] ) ? (string) $pricing_info['adult_rate'] : '30.00';
        $youth_rate = isset( $pricing_info['youth_rate'] ) ? (string) $pricing_info['youth_rate'] : '25.00';
        $family_cap = isset( $pricing_info['family_cap'] ) ? (string) $pricing_info['family_cap'] : 'Configured per active tournament profile';

        // Shared String Table Builder
        $shared_strings = array();
        $shared_index   = array();
        $add_ss         = function( $text ) use ( &$shared_strings, &$shared_index ) {
            $str = (string) $text;
            if ( ! isset( $shared_index[ $str ] ) ) {
                $shared_index[ $str ] = count( $shared_strings );
                $shared_strings[]     = $str;
            }
            return $shared_index[ $str ];
        };

        $xml_esc = function( $text ) {
            return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
        };

        // 1. [Content_Types].xml
        $content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        // 2. _rels/.rels
        $rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        // 3. xl/_rels/workbook.xml.rels
        $wb_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';

        // 4. xl/workbook.xml
        $workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="Roster Import" sheetId="1" r:id="rId1"/>'
            . '<sheet name="Tournament Info" sheetId="2" r:id="rId2"/>'
            . '</sheets>'
            . '</workbook>';

        // 5. xl/styles.xml (xf 0=General, 1=Dark Header, 2=Text @ for Phone, 3=Time Display, 4=Subheader)
        $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1">'
            . '<numFmt numFmtId="164" formatCode="[$-409]h:mm AM/PM;@"/>'
            . '</numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF1E3A5F"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="FFCBD5E1"/></left>'
            . '<right style="thin"><color rgb="FFCBD5E1"/></right>'
            . '<top style="thin"><color rgb="FFCBD5E1"/></top>'
            . '<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>'
            . '<diagonal/>'
            . '</border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';

        // 6. Build Sheet 1: Roster Import
        $headers     = sywat_get_import_template_headers();
        $col_letters = array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I' );

        $sheet1_rows_xml = '<row r="1" ht="24" customHeight="1">';
        foreach ( $headers as $c_idx => $h_text ) {
            $ref              = $col_letters[ $c_idx ] . '1';
            $s_id             = $add_ss( $h_text );
            $sheet1_rows_xml .= '<c r="' . $ref . '" s="1" t="s"><v>' . $s_id . '</v></c>';
        }
        $sheet1_rows_xml .= '</row>';

        // Row 2: Pre-populated Example Row matching active tournament's first line
        $sheet1_rows_xml .= '<row r="2">'
            . '<c r="A2" t="s"><v>' . $add_ss( 'John Smith' ) . '</v></c>'
            . '<c r="B2" s="2" t="s"><v>' . $add_ss( '817-555-1234' ) . '</v></c>'
            . '<c r="C2" t="s"><v>' . $add_ss( 'john@example.com' ) . '</v></c>'
            . '<c r="D2" t="s"><v>' . $add_ss( 'John Smith' ) . '</v></c>'
            . '<c r="E2" t="s"><v>' . $add_ss( $first_day ) . '</v></c>'
            . '<c r="F2" s="3" t="s"><v>' . $add_ss( $first_time ) . '</v></c>'
            . '<c r="G2" t="s"><v>' . $add_ss( '20 yds' ) . '</v></c>'
            . '<c r="H2" t="s"><v>' . $add_ss( 'Adult' ) . '</v></c>'
            . '<c r="I2" t="s"><v>' . $add_ss( 'Paid' ) . '</v></c>'
            . '</row>';

        // Rows 3..250: Pre-format Phone (Col B -> Text @) and Line Time (Col F -> Time Display)
        for ( $r = 3; $r <= 250; $r++ ) {
            $sheet1_rows_xml .= '<row r="' . $r . '">'
                . '<c r="B' . $r . '" s="2"/>'
                . '<c r="F' . $r . '" s="3"/>'
                . '</row>';
        }

        // Data Validations:
        // - Col B: Phone 10-digit length guidance (warning)
        // - Col E: Line Day strict dropdown (stop) -> Friday, Saturday, Sunday
        // - Col F: Line Time strict dropdown (stop) -> 30-min increments 7:00 AM–10:00 PM ('Tournament Info'!$G$2:$G$32)
        // - Col G: Distance strict dropdown (stop) -> 10 yds, 20 yds
        // - Col H: Rate Tier strict dropdown (stop) -> Youth, Adult
        // - Col I: Paid Status strict dropdown (stop) -> Paid, Unpaid
        $last_time_row = count( $time_slots ) + 1;
        $dv_items      = array();

        $dv_items[] = '<dataValidation type="textLength" operator="greaterThanOrEqual" errorStyle="warning" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
            . ' promptTitle="Phone Number" prompt="Enter 10-digit phone number (e.g. 817-555-1234). Used to group households on the same line for the Family Cap."'
            . ' errorTitle="Check Phone Number" error="Phone numbers must contain at least 10 digits (e.g. 817-555-1234)."'
            . ' sqref="B2:B250"><formula1>10</formula1></dataValidation>';

        $dv_items[] = '<dataValidation type="list" errorStyle="stop" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
            . ' promptTitle="Line Day" prompt="Select Friday, Saturday, or Sunday from the dropdown."'
            . ' errorTitle="Invalid Line Day" error="Please select Friday, Saturday, or Sunday from the dropdown list."'
            . ' sqref="E2:E250"><formula1>"Friday,Saturday,Sunday"</formula1></dataValidation>';

        $dv_items[] = '<dataValidation type="list" errorStyle="stop" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
            . ' promptTitle="Line Time (30-Min Increments)" prompt="Select a shooting line time (7:00 AM – 10:00 PM in 30-minute increments). Must match a line configured on this tournament."'
            . ' errorTitle="Invalid Line Time" error="Please select a valid 30-minute time slot from the dropdown list."'
            . ' sqref="F2:F250"><formula1>\'Tournament Info\'!$G$2:$G$' . $last_time_row . '</formula1></dataValidation>';

        $dv_items[] = '<dataValidation type="list" errorStyle="stop" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
            . ' promptTitle="Target Distance" prompt="Select 10 yds (Cubs — always Youth rate) or 20 yds."'
            . ' errorTitle="Invalid Distance" error="Please select either 10 yds or 20 yds."'
            . ' sqref="G2:G250"><formula1>"10 yds,20 yds"</formula1></dataValidation>';

        $dv_items[] = '<dataValidation type="list" errorStyle="stop" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
            . ' promptTitle="Rate Tier" prompt="Select Youth or Adult. Note: 10 yds automatically locks in the Youth rate."'
            . ' errorTitle="Invalid Rate Tier" error="Please select either Youth or Adult."'
            . ' sqref="H2:H250"><formula1>"Youth,Adult"</formula1></dataValidation>';

        $dv_items[] = '<dataValidation type="list" errorStyle="stop" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
            . ' promptTitle="Paid Status" prompt="Select Paid ($0.00 due at check-in) or Unpaid (carries balance to Check-In)."'
            . ' errorTitle="Invalid Paid Status" error="Please select either Paid or Unpaid."'
            . ' sqref="I2:I250"><formula1>"Paid,Unpaid"</formula1></dataValidation>';

        $sheet1_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>'
            . '<col min="1" max="1" width="22" customWidth="1"/>'
            . '<col min="2" max="2" width="18" customWidth="1"/>'
            . '<col min="3" max="3" width="28" customWidth="1"/>'
            . '<col min="4" max="4" width="24" customWidth="1"/>'
            . '<col min="5" max="5" width="34" customWidth="1"/>'
            . '<col min="6" max="6" width="24" customWidth="1"/>'
            . '<col min="7" max="7" width="26" customWidth="1"/>'
            . '<col min="8" max="8" width="24" customWidth="1"/>'
            . '<col min="9" max="9" width="26" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>' . $sheet1_rows_xml . '</sheetData>'
            . '<dataValidations count="' . count( $dv_items ) . '">' . implode( '', $dv_items ) . '</dataValidations>'
            . '</worksheet>';

        // 7. Build Sheet 2: Tournament Info Reference Tab + Col G 30-Minute Dropdown Lookup List
        $tourn_date_disp = ( is_array( $tourn_data ) && ! empty( $tourn_data['start_date'] ) ) ? (string) $tourn_data['start_date'] : 'Not Set';

        $meta_pairs = array(
            array( 'Tournament Name', $tourn_name !== '' ? $tourn_name : 'General Template', 'Replace Example Row 2 on the Roster Import tab before uploading.' ),
            array( 'Start Date', $tourn_date_disp, 'Used to calculate NFAA age divisions for archers in the Member Directory.' ),
            array( 'Adult Rate (20 yds)', '$' . $adult_rate, 'Applied when Distance = 20 yds and Rate Tier = Adult.' ),
            array( 'Youth Rate (10 yds & 20 yds)', '$' . $youth_rate, '10 yds always bills at Youth rate; 20 yds uses Rate Tier (Youth vs. Adult).' ),
            array( 'Household Family Cap', $family_cap, 'Automatically calculated per line using matching Phone (or Contact Name).' ),
        );

        $left_rows    = array();
        $left_rows[1] = '<c r="A1" s="1" t="s"><v>' . $add_ss( 'Active Tournament Reference' ) . '</v></c>'
            . '<c r="B1" s="1" t="s"><v>' . $add_ss( 'Configured Value' ) . '</v></c>'
            . '<c r="C1" s="1" t="s"><v>' . $add_ss( 'Import Rules & Notes' ) . '</v></c>';

        $r_num = 2;
        foreach ( $meta_pairs as $mp ) {
            $left_rows[ $r_num ] = '<c r="A' . $r_num . '" s="4" t="s"><v>' . $add_ss( $mp[0] ) . '</v></c>'
                . '<c r="B' . $r_num . '" t="s"><v>' . $add_ss( $mp[1] ) . '</v></c>'
                . '<c r="C' . $r_num . '" t="s"><v>' . $add_ss( $mp[2] ) . '</v></c>';
            $r_num++;
        }

        $r_num++; // Spacer row (Row 7)
        $left_rows[ $r_num ] = '<c r="A' . $r_num . '" s="1" t="s"><v>' . $add_ss( 'Configured Line Name' ) . '</v></c>'
            . '<c r="B' . $r_num . '" s="1" t="s"><v>' . $add_ss( 'Line Day (Col E)' ) . '</v></c>'
            . '<c r="C' . $r_num . '" s="1" t="s"><v>' . $add_ss( 'Line Time (Col F)' ) . '</v></c>'
            . '<c r="D' . $r_num . '" s="1" t="s"><v>' . $add_ss( '20yd Max Cap' ) . '</v></c>'
            . '<c r="E' . $r_num . '" s="1" t="s"><v>' . $add_ss( 'Cub (10yd) Max Cap' ) . '</v></c>';
        $r_num++;

        foreach ( $line_info_rows as $li ) {
            $left_rows[ $r_num ] = '<c r="A' . $r_num . '" t="s"><v>' . $add_ss( $li['label'] ) . '</v></c>'
                . '<c r="B' . $r_num . '" t="s"><v>' . $add_ss( $li['day'] ) . '</v></c>'
                . '<c r="C' . $r_num . '" t="s"><v>' . $add_ss( $li['time'] ) . '</v></c>'
                . '<c r="D' . $r_num . '"><v>' . (int) $li['cap_20'] . '</v></c>'
                . '<c r="E' . $r_num . '"><v>' . (int) $li['cap_cub'] . '</v></c>';
            $r_num++;
        }

        $right_rows    = array();
        $right_rows[1] = '<c r="G1" s="1" t="s"><v>' . $add_ss( 'Valid Line Times (30-Min)' ) . '</v></c>';
        foreach ( $time_slots as $slot_idx => $slot_label ) {
            $slot_row                = $slot_idx + 2;
            $right_rows[ $slot_row ] = '<c r="G' . $slot_row . '" s="3" t="s"><v>' . $add_ss( $slot_label ) . '</v></c>';
        }

        $max_sheet2_row = max( $r_num - 1, $last_time_row );
        $info_rows      = array();
        for ( $sr = 1; $sr <= $max_sheet2_row; $sr++ ) {
            $cells_xml = '';
            if ( isset( $left_rows[ $sr ] ) ) {
                $cells_xml .= $left_rows[ $sr ];
            }
            if ( isset( $right_rows[ $sr ] ) ) {
                $cells_xml .= $right_rows[ $sr ];
            }
            $row_attrs   = ( $sr === 1 || $sr === 8 ) ? ' ht="22" customHeight="1"' : '';
            $info_rows[] = '<row r="' . $sr . '"' . $row_attrs . '>' . $cells_xml . '</row>';
        }

        $sheet2_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols>'
            . '<col min="1" max="1" width="28" customWidth="1"/>'
            . '<col min="2" max="2" width="24" customWidth="1"/>'
            . '<col min="3" max="3" width="56" customWidth="1"/>'
            . '<col min="4" max="4" width="16" customWidth="1"/>'
            . '<col min="5" max="5" width="20" customWidth="1"/>'
            . '<col min="6" max="6" width="4" customWidth="1"/>'
            . '<col min="7" max="7" width="24" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>' . implode( '', $info_rows ) . '</sheetData>'
            . '</worksheet>';

        // 8. xl/sharedStrings.xml
        $ss_count = count( $shared_strings );
        $ss_items = '';
        foreach ( $shared_strings as $s_val ) {
            $ss_items .= '<si><t xml:space="preserve">' . $xml_esc( $s_val ) . '</t></si>';
        }
        $shared_strings_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $ss_count . '" uniqueCount="' . $ss_count . '">'
            . $ss_items
            . '</sst>';

        $zip->addFromString( '[Content_Types].xml', $content_types_xml );
        $zip->addFromString( '_rels/.rels', $rels_xml );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', $wb_rels_xml );
        $zip->addFromString( 'xl/workbook.xml', $workbook_xml );
        $zip->addFromString( 'xl/styles.xml', $styles_xml );
        $zip->addFromString( 'xl/sharedStrings.xml', $shared_strings_xml );
        $zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet1_xml );
        $zip->addFromString( 'xl/worksheets/sheet2.xml', $sheet2_xml );

        return $zip->close();
    }
}

/**
 * Hardened lightweight .xlsx parser using PHP ZipArchive and SimpleXML.
 * Suppresses libxml warnings and guards against empty sheets or custom XML structures.
 *
 * @param string $filepath Path to the uploaded .xlsx file.
 * @return array           2D array of parsed worksheet rows.
 */
if ( ! function_exists( 'sywat_parse_simple_xlsx' ) ) {
    function sywat_parse_simple_xlsx( $filepath ) {
        $rows = array();
        if ( ! class_exists( 'ZipArchive' ) || ! is_readable( $filepath ) ) {
            return $rows;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $filepath ) !== true ) {
            return $rows;
        }

        $prev_libxml = libxml_use_internal_errors( true );

        // 1. Parse Shared Strings Table
        $shared_strings = array();
        $ss_idx         = $zip->locateName( 'xl/sharedStrings.xml' );
        if ( $ss_idx !== false ) {
            $ss_raw = $zip->getFromIndex( $ss_idx );
            $xml    = is_string( $ss_raw ) && $ss_raw !== '' ? simplexml_load_string( $ss_raw ) : false;
            if ( $xml !== false && isset( $xml->si ) ) {
                foreach ( $xml->si as $si ) {
                    if ( isset( $si->t ) ) {
                        $shared_strings[] = (string) $si->t;
                    } elseif ( isset( $si->r ) ) {
                        $rich_text = '';
                        foreach ( $si->r as $run ) {
                            if ( isset( $run->t ) ) {
                                $rich_text .= (string) $run->t;
                            }
                        }
                        $shared_strings[] = $rich_text;
                    } else {
                        $shared_strings[] = '';
                    }
                }
            }
        }

        // 2. Inspect styles.xml to detect Time-formatted cell style indexes
        $time_xf_indexes = array();
        $st_idx          = $zip->locateName( 'xl/styles.xml' );
        if ( $st_idx !== false ) {
            $st_raw = $zip->getFromIndex( $st_idx );
            $st_xml = is_string( $st_raw ) && $st_raw !== '' ? simplexml_load_string( $st_raw ) : false;
            if ( $st_xml !== false ) {
                $custom_time_numfmts = array( 18, 19, 20, 21, 45, 46, 47 );
                if ( isset( $st_xml->numFmts->numFmt ) ) {
                    foreach ( $st_xml->numFmts->numFmt as $nf ) {
                        $nf_id   = (int) $nf['numFmtId'];
                        $nf_code = strtolower( (string) $nf['formatCode'] );
                        if ( strpos( $nf_code, 'h' ) !== false && strpos( $nf_code, 'm' ) !== false ) {
                            $custom_time_numfmts[] = $nf_id;
                        }
                    }
                }
                if ( isset( $st_xml->cellXfs->xf ) ) {
                    $xf_i = 0;
                    foreach ( $st_xml->cellXfs->xf as $xf ) {
                        $nf_id = (int) $xf['numFmtId'];
                        if ( in_array( $nf_id, $custom_time_numfmts, true ) ) {
                            $time_xf_indexes[ $xf_i ] = true;
                        }
                        $xf_i++;
                    }
                }
            }
        }

        // 3. Parse first worksheet
        $sheet_idx = $zip->locateName( 'xl/worksheets/sheet1.xml' );
        if ( $sheet_idx !== false ) {
            $sheet_raw = $zip->getFromIndex( $sheet_idx );
            $xml       = is_string( $sheet_raw ) && $sheet_raw !== '' ? simplexml_load_string( $sheet_raw ) : false;
            if ( $xml !== false && isset( $xml->sheetData->row ) ) {
                foreach ( $xml->sheetData->row as $row ) {
                    if ( ! isset( $row->c ) ) {
                        continue;
                    }
                    $current_row = array();
                    $col_idx     = 0;
                    foreach ( $row->c as $c ) {
                        $cell_ref     = isset( $c['r'] ) ? (string) $c['r'] : '';
                        $expected_idx = $cell_ref !== '' ? sywat_xlsx_col_letter_to_index( $cell_ref ) : $col_idx;

                        while ( $col_idx < $expected_idx ) {
                            $current_row[] = '';
                            $col_idx++;
                        }

                        $val      = isset( $c->v ) ? (string) $c->v : '';
                        $cell_t   = isset( $c['t'] ) ? (string) $c['t'] : '';
                        $style_id = isset( $c['s'] ) ? (int) $c['s'] : -1;

                        if ( $cell_t === 's' ) {
                            $val = isset( $shared_strings[ (int) $val ] ) ? $shared_strings[ (int) $val ] : '';
                        } elseif ( $cell_t === 'inlineStr' && isset( $c->is->t ) ) {
                            $val = (string) $c->is->t;
                        } elseif ( is_numeric( $val ) ) {
                            $num_val = (float) $val;
                            if ( ( $num_val > 0 && $num_val < 1 ) || ( $style_id >= 0 && isset( $time_xf_indexes[ $style_id ] ) ) ) {
                                $day_frac      = fmod( $num_val, 1.0 );
                                $total_seconds = (int) round( $day_frac * 86400 );
                                $val           = gmdate( 'g:i A', $total_seconds );
                            }
                        }
                        $current_row[] = trim( $val );
                        $col_idx++;
                    }
                    $rows[] = $current_row;
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors( $prev_libxml );
        $zip->close();

        return $rows;
    }
}
