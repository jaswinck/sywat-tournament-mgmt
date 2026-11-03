<?php
/**
 * Lightweight Standalone Excel (.xlsx) Parser for WordPress / PHP
 * Path: includes/lib/SimpleXLSX.php
 * Version: 1.2.0
 * 
 * Dependencies: PHP ZipArchive & SimpleXML (Standard in PHP 7.4+ & WP Core)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SimpleXLSX' ) ) {

class SimpleXLSX {

    private $sheets = array();
    private $sharedStrings = array();
    private $error = '';

    /**
     * Static Factory: Parse uploaded .xlsx file
     */
    public static function parse( $filename ) {
        $xlsx = new self();
        if ( $xlsx->_parse( $filename ) ) {
            return $xlsx;
        }
        return false;
    }

    public function getError() {
        return $this->error;
    }

    public function sheetsCount() {
        return count( $this->sheets );
    }

    public function rows( $sheetIndex = 0 ) {
        if ( isset( $this->sheets[$sheetIndex] ) ) {
            return $this->sheets[$sheetIndex];
        }
        return array();
    }

    private function _parse( $filename ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->error = 'PHP ZipArchive extension is required on your web server.';
            return false;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $filename ) !== true ) {
            $this->error = 'Could not open XLSX file archive.';
            return false;
        }

        // 1. Read Shared Strings
        if ( ( $index = $zip->locateName( 'xl/sharedStrings.xml' ) ) !== false ) {
            $data = $zip->getFromIndex( $index );
            $xml = @simplexml_load_string( $data );
            if ( $xml && isset( $xml->si ) ) {
                foreach ( $xml->si as $si ) {
                    if ( isset( $si->t ) ) {
                        $this->sharedStrings[] = (string) $si->t;
                    } elseif ( isset( $si->r ) ) {
                        $str = '';
                        foreach ( $si->r as $r ) {
                            $str .= (string) $r->t;
                        }
                        $this->sharedStrings[] = $str;
                    } else {
                        $this->sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Locate Worksheets
        $sheetFiles = array();
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( preg_match( '/xl\/worksheets\/sheet\d+\.xml/i', $name ) ) {
                $sheetFiles[] = $name;
            }
        }

        usort( $sheetFiles, function( $a, $b ) {
            preg_match( '/sheet(\d+)\.xml/i', $a, $m1 );
            preg_match( '/sheet(\d+)\.xml/i', $b, $m2 );
            return intval( isset($m1[1]) ? $m1[1] : 0 ) - intval( isset($m2[1]) ? $m2[1] : 0 );
        });

        if ( empty( $sheetFiles ) ) {
            $zip->close();
            $this->error = 'No worksheets found in XLSX file.';
            return false;
        }

        // 3. Extract Rows & Cells
        foreach ( $sheetFiles as $sIndex => $sheetFile ) {
            $sheetData = $zip->getFromName( $sheetFile );
            if ( empty( $sheetData ) ) continue;

            $xml = @simplexml_load_string( $sheetData );
            if ( ! $xml || ! isset( $xml->sheetData->row ) ) {
                $this->sheets[$sIndex] = array();
                continue;
            }

            $rows = array();
            foreach ( $xml->sheetData->row as $row ) {
                $rowCells = array();

                if ( isset( $row->c ) ) {
                    foreach ( $row->c as $c ) {
                        $r   = (string) $c['r'];
                        $t   = (string) $c['t'];
                        $val = '';

                        if ( isset( $c->v ) ) {
                            $val = (string) $c->v;
                        } elseif ( isset( $c->is->t ) ) {
                            $val = (string) $c->is->t;
                        } elseif ( isset( $c->is->r ) ) {
                            $str = '';
                            foreach ( $c->is->r as $r_node ) {
                                $str .= (string) $r_node->t;
                            }
                            $val = $str;
                        }

                        if ( $t === 's' && isset( $this->sharedStrings[intval($val)] ) ) {
                            $val = $this->sharedStrings[intval($val)];
                        }

                        $colAlpha = preg_replace( '/[0-9]/', '', $r );
                        $colIdx   = $this->columnLetterToIndex( $colAlpha );

                        $rowCells[$colIdx] = $val;
                    }
                }

                if ( ! empty( $rowCells ) ) {
                    $maxCol = max( array_keys( $rowCells ) );
                    $contiguousRow = array();
                    for ( $col = 0; $col <= $maxCol; $col++ ) {
                        $contiguousRow[$col] = isset( $rowCells[$col] ) ? $rowCells[$col] : '';
                    }
                    $rows[] = $contiguousRow;
                }
            }

            $this->sheets[$sIndex] = $rows;
        }

        $zip->close();
        return true;
    }

    private function columnLetterToIndex( $col ) {
        $col = strtoupper( $col );
        $len = strlen( $col );
        $idx = 0;
        for ( $i = 0; $i < $len; $i++ ) {
            $idx = $idx * 26 + ( ord( $col[$i] ) - 64 );
        }
        return $idx - 1;
    }
}

}
