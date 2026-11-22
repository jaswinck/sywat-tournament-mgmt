<?php
/**
 * Shared Common Helper: Unified Archer Identity & Historical Profile Resolution Engine
 * Path: includes/common/sywat-archer-identity-helpers.php
 * Description: Single-source-of-truth identity normalization (supporting both "First Last" and "Last, First" inputs), smart person-name capitalization (normalizing all-lowercase/all-uppercase name tokens like "W ryan Smith" -> "W Ryan Smith" while preserving intentional mixed-case surnames like "McBride", generational suffixes, and bracketed tags), idempotent "LastName, FirstName" display & surname-sort formatting, given-name/nickname equivalence and stem matching, contact-verified disambiguation (with parent/child first-name protection), highest-category division resolution (max of History vs. DOB so mid-season "shooting up" and birthday "aging up" both resolve accurately), and ambiguity-safe canonical shooter resolution across Member Directory, 18-Month TFAA Standings, and Shop Registration History for Bulk Import, Phone Intake, Online Self-Registration, Walk-Ins, Check-In, and Results.
 * Compliance: Pure helper layer — zero HTML echo, zero direct SQL queries (delegates Member Directory lookup to sywat_get_bulk_member_directory_records()), O(1) request-cached hashtables, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normalize capitalization across person-name tokens (converting all-lowercase or all-uppercase
 * words like "W ryan Smith" into "W Ryan Smith", including hyphenated and apostrophe names)
 * while preserving intentional mixed-case surnames ("McBride", "MacLeod", "DeLaCruz", "O'Connor"),
 * generational suffixes ("Jr.", "Sr.", "II", "III", "IV"), and bracketed tags ("[GUEST]").
 *
 * @param string $raw_name Raw person name string.
 * @return string          Properly cased person name string.
 */
if ( ! function_exists( 'sywat_format_person_name_case' ) ) {
    function sywat_format_person_name_case( $raw_name ) {
        $trimmed = trim( preg_replace( '/[[:space:]]+/', ' ', str_replace( "\xC2\xA0", ' ', (string) $raw_name ) ) );
        if ( $trimmed === '' ) {
            return '';
        }

        // Preserve non-person placeholders and status sentinels unchanged
        $sentinels = array( '—', '-', 'n/a', 'no show', 'unassigned', 'unknown', 'tbd' );
        if ( in_array( strtolower( $trimmed ), $sentinels, true ) ) {
            return $trimmed;
        }

        // Detach any trailing bracketed/parenthesized tag (e.g., "[GUEST]", "[INC]") so it stays untouched
        $base         = $trimmed;
        $trailing_tag = '';
        if ( preg_match( '/^(.*?)(\s*(?:\[[^\]]*\]|\([^\)]*\))\s*)$/', $trimmed, $tag_matches ) ) {
            $candidate_base = trim( $tag_matches[1] );
            if ( $candidate_base !== '' ) {
                $base         = $candidate_base;
                $trailing_tag = ' ' . trim( $tag_matches[2] );
            }
        }

        $roman_suffixes   = array( 'ii', 'iii', 'iv', 'v', 'vi' );
        $ordinal_suffixes = array( '2nd', '3rd', '4th' );

        $tokens     = explode( ' ', $base );
        $formatted  = array();
        $token_last = count( $tokens ) - 1;

        foreach ( $tokens as $idx => $token ) {
            if ( $token === '' ) {
                continue;
            }

            $trailing_comma = '';
            if ( substr( $token, -1 ) === ',' ) {
                $trailing_comma = ',';
                $token          = substr( $token, 0, -1 );
            }

            if ( $token === '' ) {
                $formatted[] = $trailing_comma;
                continue;
            }

            $lower_no_dot    = rtrim( strtolower( $token ), '.' );
            $has_dot         = ( substr( $token, -1 ) === '.' );
            $is_suffix_place = ( $idx === $token_last || $trailing_comma !== '' );

            if ( $idx > 0 && $is_suffix_place && in_array( $lower_no_dot, $roman_suffixes, true ) ) {
                $formatted[] = strtoupper( $lower_no_dot ) . ( $has_dot ? '.' : '' ) . $trailing_comma;
                continue;
            }

            if ( $idx > 0 && $is_suffix_place && in_array( $lower_no_dot, $ordinal_suffixes, true ) ) {
                $formatted[] = $lower_no_dot . ( $has_dot ? '.' : '' ) . $trailing_comma;
                continue;
            }

            if ( $idx > 0 && $is_suffix_place && ( $lower_no_dot === 'jr' || $lower_no_dot === 'sr' ) ) {
                $formatted[] = ucfirst( $lower_no_dot ) . ( $has_dot ? '.' : '' ) . $trailing_comma;
                continue;
            }

            $has_upper = (bool) preg_match( '/[A-Z]/', $token );
            $has_lower = (bool) preg_match( '/[a-z]/', $token );

            // Preserve intentional mixed-case tokens (e.g., "McBride", "MacLeod", "DeLaCruz", "O'Connor")
            if ( $has_upper && $has_lower ) {
                $formatted[] = $token . $trailing_comma;
                continue;
            }

            // Normalize all-lowercase or all-uppercase tokens (including hyphenated and apostrophe names)
            $sub_parts = preg_split( '/([\'\-])/', $token, -1, PREG_SPLIT_DELIM_CAPTURE );
            $rebuilt   = '';
            foreach ( $sub_parts as $part ) {
                if ( $part === '\'' || $part === '-' || $part === '' ) {
                    $rebuilt .= $part;
                } elseif ( preg_match( '/^mc([a-z]{2,})$/i', $part, $mc_match ) ) {
                    $rebuilt .= 'Mc' . ucfirst( strtolower( $mc_match[1] ) );
                } else {
                    $rebuilt .= ucfirst( strtolower( $part ) );
                }
            }

            $formatted[] = $rebuilt . $trailing_comma;
        }

        return implode( ' ', $formatted ) . $trailing_tag;
    }
}

/**
 * Build normalized exact, First+Last (+ generational suffix), first-token, and last-token keys
 * for smart archer name matching.
 * Accepts both "First [Middle] Last [Suffix]" and "Last [Suffix], First [Middle]" inputs,
 * strips bracketed/parenthesized tags, periods, and middle initials/names (e.g., "Mark E. Tanner" -> "mark___tanner"),
 * and preserves generational suffixes (Jr, Sr, II, III, IV) so parent/child records never collide.
 *
 * @param string $raw_name Raw archer name input.
 * @return array           Associative array with 'exact', 'fl', 'first', 'last', 'suffix', 'token_count', and 'last_len'.
 */
if ( ! function_exists( 'sywat_normalize_archer_match_keys' ) ) {
    function sywat_normalize_archer_match_keys( $raw_name ) {
        $cleaned  = preg_replace( '/\[.*?\]|\(.*?\)/', '', (string) $raw_name );
        $suffixes = array( 'jr', 'sr', 'ii', 'iii', 'iv', 'v', '2nd', '3rd', '4th' );

        // Bidirectional comma support: normalize "Last [Suffix], First [Middle]" -> "First [Middle] Last [Suffix]"
        if ( strpos( $cleaned, ',' ) !== false ) {
            $comma_parts = explode( ',', $cleaned, 2 );
            $left_part   = trim( str_replace( array( '.', '"', "\xC2\xA0" ), ' ', $comma_parts[0] ) );
            $right_part  = trim( str_replace( array( '.', '"', "\xC2\xA0" ), ' ', $comma_parts[1] ) );

            if ( $left_part !== '' && $right_part !== '' ) {
                $right_norm = strtolower( preg_replace( '/[[:space:]]+/', ' ', $right_part ) );
                if ( in_array( $right_norm, $suffixes, true ) ) {
                    // Handles "John Smith, Jr." -> "John Smith Jr"
                    $cleaned = $left_part . ' ' . $right_part;
                } else {
                    // Handles "Bergh, Steve" -> "Steve Bergh" and "Smith Jr, John" -> "John Smith Jr"
                    $cleaned = $right_part . ' ' . $left_part;
                }
            }
        }

        $cleaned = str_replace( array( '.', ',', '"', "\xC2\xA0" ), ' ', $cleaned );
        $cleaned = preg_replace( '/[^a-zA-Z0-9\s\'-]/', ' ', $cleaned );
        $cleaned = strtolower( trim( preg_replace( '/[[:space:]]+/', ' ', $cleaned ) ) );

        if ( $cleaned === '' ) {
            return array(
                'exact'       => '',
                'fl'          => '',
                'first'       => '',
                'last'        => '',
                'suffix'      => '',
                'token_count' => 0,
                'last_len'    => 0,
            );
        }

        $tokens = explode( ' ', $cleaned );
        $suffix = '';

        if ( count( $tokens ) > 2 && in_array( end( $tokens ), $suffixes, true ) ) {
            $suffix = array_pop( $tokens );
        }

        $core_exact = implode( ' ', $tokens );
        $exact_key  = $suffix !== '' ? ( $core_exact . ' ' . $suffix ) : $core_exact;
        $first_tok  = isset( $tokens[0] ) ? $tokens[0] : '';
        $last_tok   = count( $tokens ) > 1 ? end( $tokens ) : $first_tok;
        $fl_key     = ( count( $tokens ) >= 2 ) ? ( $first_tok . '___' . $last_tok ) : $core_exact;

        if ( $suffix !== '' && $fl_key !== '' ) {
            $fl_key .= '___' . $suffix;
        }

        return array(
            'exact'       => $exact_key,
            'fl'          => $fl_key,
            'first'       => $first_tok,
            'last'        => $last_tok,
            'suffix'      => $suffix,
            'token_count' => count( $tokens ),
            'last_len'    => strlen( $last_tok ),
        );
    }
}

/**
 * Normalize an archer's display name into canonical "FirstName [Middle] LastName [Suffix]" order
 * when stored in "LastName [Suffix], FirstName [Middle]" format (e.g., TFAA standings rows).
 *
 * @param string $raw_name Raw archer name string.
 * @return string          Canonical "FirstName LastName" string.
 */
if ( ! function_exists( 'sywat_normalize_name_first_last' ) ) {
    function sywat_normalize_name_first_last( $raw_name ) {
        $trimmed = trim( preg_replace( '/[[:space:]]+/', ' ', str_replace( "\xC2\xA0", ' ', (string) $raw_name ) ) );
        if ( $trimmed === '' ) {
            return '';
        }

        if ( strpos( $trimmed, ',' ) === false ) {
            return sywat_format_person_name_case( $trimmed );
        }

        $suffixes    = array( 'jr', 'sr', 'ii', 'iii', 'iv', 'v', '2nd', '3rd', '4th' );
        $comma_parts = explode( ',', $trimmed, 2 );
        $left_part   = trim( $comma_parts[0] );
        $right_part  = trim( $comma_parts[1] );

        if ( $left_part === '' || $right_part === '' ) {
            return sywat_format_person_name_case( $trimmed );
        }

        $right_norm = rtrim( strtolower( $right_part ), '.' );
        if ( in_array( $right_norm, $suffixes, true ) ) {
            return sywat_format_person_name_case( trim( $left_part . ' ' . $right_part ) );
        }

        return sywat_format_person_name_case( trim( $right_part . ' ' . $left_part ) );
    }
}

/**
 * Format an archer's display name into "LastName [Suffix], FirstName [Middle]" for visual display
 * and alphabetical surname sorting while leaving underlying stored records untouched.
 *
 * Examples:
 * - "Steve Bergh"       -> "Bergh, Steve"
 * - "W ryan Smith"      -> "Smith, W Ryan"
 * - "Louis Woojin Shin" -> "Shin, Louis Woojin"
 * - "Mark E. Tanner"    -> "Tanner, Mark E."
 * - "John Smith Jr"     -> "Smith Jr, John"
 * - "Bergh, Steve"      -> "Bergh, Steve" (idempotent)
 *
 * @param string $raw_name Stored archer name.
 * @return string          Formatted "LastName, FirstName" string.
 */
if ( ! function_exists( 'sywat_format_name_last_first' ) ) {
    function sywat_format_name_last_first( $raw_name ) {
        $trimmed = trim( preg_replace( '/[[:space:]]+/', ' ', str_replace( "\xC2\xA0", ' ', (string) $raw_name ) ) );
        if ( $trimmed === '' ) {
            return '';
        }

        // Preserve non-person placeholders and status sentinels unchanged
        $sentinels = array( '—', '-', 'n/a', 'no show', 'unassigned', 'unknown', 'tbd' );
        if ( in_array( strtolower( $trimmed ), $sentinels, true ) ) {
            return $trimmed;
        }

        // Detach any trailing bracketed/parenthesized tag so it remains at the end of the formatted string
        $base         = $trimmed;
        $trailing_tag = '';
        if ( preg_match( '/^(.*?)(\s*(?:\[[^\]]*\]|\([^\)]*\))\s*)$/', $trimmed, $tag_matches ) ) {
            $candidate_base = trim( $tag_matches[1] );
            if ( $candidate_base !== '' ) {
                $base         = $candidate_base;
                $trailing_tag = ' ' . trim( $tag_matches[2] );
            }
        }

        $base     = sywat_format_person_name_case( $base );
        $suffixes = array( 'jr', 'sr', 'ii', 'iii', 'iv', 'v', '2nd', '3rd', '4th' );

        // Handle strings that already contain a comma
        if ( strpos( $base, ',' ) !== false ) {
            $comma_parts = explode( ',', $base, 2 );
            $left        = trim( $comma_parts[0] );
            $right       = trim( $comma_parts[1] );
            $right_norm  = rtrim( strtolower( $right ), '.' );

            // Convert "First Last, Jr." -> "Last Jr., First"
            if ( $left !== '' && in_array( $right_norm, $suffixes, true ) ) {
                $left_tokens = explode( ' ', $left );
                if ( count( $left_tokens ) >= 2 ) {
                    $surname    = array_pop( $left_tokens ) . ' ' . $right;
                    $first_part = implode( ' ', $left_tokens );
                    return $surname . ', ' . $first_part . $trailing_tag;
                }
            }

            // Already formatted as "Last, First"
            return $base . $trailing_tag;
        }

        $tokens = explode( ' ', $base );
        if ( count( $tokens ) < 2 ) {
            return $base . $trailing_tag;
        }

        $last_token_norm = rtrim( strtolower( end( $tokens ) ), '.' );
        if ( count( $tokens ) > 2 && in_array( $last_token_norm, $suffixes, true ) ) {
            $suffix    = array_pop( $tokens );
            $last_name = array_pop( $tokens ) . ' ' . $suffix;
        } else {
            $last_name = array_pop( $tokens );
        }

        $first_and_middle = implode( ' ', $tokens );
        if ( $first_and_middle === '' ) {
            return $last_name . $trailing_tag;
        }

        return $last_name . ', ' . $first_and_middle . $trailing_tag;
    }
}

/**
 * Return the NFAA age-category hierarchy weight for a division label/code so the system
 * can deterministically select the highest applicable category between tournament history
 * (when an archer "shoots up" mid-season) and Date of Birth (when an archer ages up on their birthday).
 *
 * Hierarchy (lowest -> highest):
 * 1: Cub (8 - 11)
 * 2: Youth (12 - 14)
 * 3: Young Adult (15 - 17)
 * 4: Adult (18 - 49) / Pro Adult
 * 5: Senior (50 - 59) / Pro Senior
 * 6: Silver Senior (60 - 69) / Pro Silver Senior
 * 7: Master Senior (70+) / Pro Master Senior
 *
 * @param string $raw_division Raw or normalized NFAA division label/code.
 * @return int                 Category rank 1..7, or 0 if empty/placeholder/unrecognized.
 */
if ( ! function_exists( 'sywat_get_division_category_rank' ) ) {
    function sywat_get_division_category_rank( $raw_division ) {
        $clean = trim( (string) $raw_division );
        if ( $clean === '' ) {
            return 0;
        }

        $lower = strtolower( $clean );
        if ( in_array( $lower, array( '-', '--', '—', '–', 'n/a', 'na', 'none', 'unassigned', 'unknown', 'no show' ), true ) ) {
            return 0;
        }

        if ( function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
            $norm = sywat_shooting_styles_normalize_division( $clean );
            if ( $norm !== '' ) {
                $lower = strtolower( $norm );
            }
        }

        if ( $lower === 'c' || preg_match( '/\bcub\b/', $lower ) ) {
            return 1;
        }
        if ( $lower === 'ya' || strpos( $lower, 'young adult' ) !== false ) {
            return 3;
        }
        if ( $lower === 'y' || preg_match( '/\byouth\b/', $lower ) ) {
            return 2;
        }
        if ( $lower === 'ms' || strpos( $lower, 'master senior' ) !== false || preg_match( '/\bmaster\b/', $lower ) ) {
            return 7;
        }
        if ( $lower === 'ss' || strpos( $lower, 'silver senior' ) !== false ) {
            return 6;
        }
        if ( $lower === 's' || preg_match( '/\bsenior\b/', $lower ) ) {
            return 5;
        }
        if ( $lower === 'a' || preg_match( '/\badult\b/', $lower ) ) {
            return 4;
        }

        return 0;
    }
}

/**
 * Pick the higher NFAA age division between a historical division and a DOB-calculated division.
 * - History wins when history places the shooter in a higher category ("shooting up").
 * - DOB wins when DOB places the shooter in the highest category (birthday age-up or no history).
 *
 * @param string $hist_division Division from 18-month TFAA or shop history.
 * @param string $dob_division  Division computed from Member Directory Date of Birth.
 * @return string               Winning division label (or empty string if neither exists).
 */
if ( ! function_exists( 'sywat_select_highest_category_division' ) ) {
    function sywat_select_highest_category_division( $hist_division, $dob_division ) {
        $hist_clean = trim( (string) $hist_division );
        $dob_clean  = trim( (string) $dob_division );

        $hist_rank = sywat_get_division_category_rank( $hist_clean );
        $dob_rank  = sywat_get_division_category_rank( $dob_clean );

        if ( $hist_rank === 0 && $dob_rank === 0 ) {
            return $hist_clean !== '' ? $hist_clean : $dob_clean;
        }

        if ( $hist_rank > $dob_rank ) {
            return $hist_clean;
        }

        if ( $dob_rank > 0 ) {
            return $dob_clean;
        }

        return $hist_clean;
    }
}

/**
 * Return the canonical given-name / nickname equivalence map where each lowercase variant
 * maps to a list of matching group root keys (supporting unisex nicknames such as "Sam" or "Alex"
 * without falsely equating "Samuel" with "Samantha").
 *
 * @return array<string, array<int, string>> Lowercase given-name token => array of canonical group keys.
 */
if ( ! function_exists( 'sywat_get_given_name_alias_map' ) ) {
    function sywat_get_given_name_alias_map() {
        static $alias_map = null;
        if ( null !== $alias_map ) {
            return $alias_map;
        }

        $groups = array(
            array( 'steven', 'stephen', 'steve', 'stevie' ),
            array( 'robert', 'rob', 'robbie', 'bob', 'bobby' ),
            array( 'william', 'will', 'bill', 'billy', 'liam' ),
            array( 'michael', 'mike', 'mikey' ),
            array( 'christopher', 'chris' ),
            array( 'matthew', 'matt' ),
            array( 'joshua', 'josh' ),
            array( 'daniel', 'dan', 'danny' ),
            array( 'thomas', 'tom', 'tommy' ),
            array( 'timothy', 'tim', 'timmy' ),
            array( 'benjamin', 'ben', 'benny' ),
            array( 'alexander', 'alex' ),
            array( 'alexandra', 'alex' ),
            array( 'nicholas', 'nicolas', 'nick' ),
            array( 'jonathan', 'johnathan', 'jon', 'john', 'johnny' ),
            array( 'joseph', 'joe', 'joey' ),
            array( 'james', 'jim', 'jimmy', 'jamie' ),
            array( 'jeffrey', 'geoffrey', 'jeff', 'geoff' ),
            array( 'patrick', 'pat', 'paddy' ),
            array( 'samuel', 'sam', 'sammy' ),
            array( 'samantha', 'sam', 'sammy' ),
            array( 'david', 'dave', 'davey' ),
            array( 'richard', 'rick', 'ricky', 'rich', 'dick' ),
            array( 'charles', 'charlie', 'chuck' ),
            array( 'edward', 'ed', 'eddie', 'ted', 'teddy' ),
            array( 'anthony', 'tony' ),
            array( 'andrew', 'andy', 'drew' ),
            array( 'kenneth', 'ken', 'kenny' ),
            array( 'kevin', 'kev' ),
            array( 'gregory', 'greg' ),
            array( 'bradley', 'brad' ),
            array( 'brandon', 'branden' ),
            array( 'zachary', 'zack', 'zach' ),
            array( 'nathaniel', 'nathan', 'nate' ),
            array( 'jacob', 'jake' ),
            array( 'lucas', 'luke' ),
            array( 'cameron', 'cam' ),
            array( 'katherine', 'kathryn', 'catherine', 'kate', 'katie', 'kathy', 'cathy' ),
            array( 'elizabeth', 'liz', 'lizzie', 'beth', 'betty', 'eliza' ),
            array( 'jennifer', 'jen', 'jenny' ),
            array( 'jessica', 'jess', 'jessie' ),
            array( 'rebecca', 'becca', 'becky' ),
            array( 'stephanie', 'steph' ),
            array( 'victoria', 'vicki', 'vicky', 'tori' ),
            array( 'abigail', 'abby' ),
            array( 'madison', 'maddie' ),
            array( 'allison', 'alison', 'ally', 'ali' ),
            array( 'christine', 'christina', 'christy', 'kristin', 'kristen', 'kris' ),
            array( 'patricia', 'patty', 'trish', 'tricia' ),
            array( 'deborah', 'debra', 'deb', 'debbie' ),
            array( 'susan', 'suzanne', 'sue', 'suzie', 'susie' ),
            array( 'margaret', 'maggie', 'meg', 'peggy' ),
        );

        $alias_map = array();
        foreach ( $groups as $group ) {
            $root = $group[0];
            foreach ( $group as $variant ) {
                if ( ! isset( $alias_map[ $variant ] ) ) {
                    $alias_map[ $variant ] = array();
                }
                if ( ! in_array( $root, $alias_map[ $variant ], true ) ) {
                    $alias_map[ $variant ][] = $root;
                }
            }
        }

        return $alias_map;
    }
}

/**
 * Determine whether two given-name tokens represent the same person (exact, nickname equivalence, or 3+ char stem).
 *
 * @param string $first_a Normalized lowercase first name A.
 * @param string $first_b Normalized lowercase first name B.
 * @return bool           True if compatible given names.
 */
if ( ! function_exists( 'sywat_are_given_names_compatible' ) ) {
    function sywat_are_given_names_compatible( $first_a, $first_b ) {
        $a = strtolower( trim( (string) $first_a ) );
        $b = strtolower( trim( (string) $first_b ) );

        if ( $a === '' || $b === '' ) {
            return false;
        }
        if ( $a === $b ) {
            return true;
        }

        $alias_map = sywat_get_given_name_alias_map();
        if ( isset( $alias_map[ $a ], $alias_map[ $b ] ) && ! empty( array_intersect( $alias_map[ $a ], $alias_map[ $b ] ) ) ) {
            return true;
        }

        // Safe prefix stem check (e.g., "steven" vs "steve", "matt" vs "matthew")
        if ( strlen( $a ) >= 3 && strlen( $b ) >= 3 ) {
            if ( strpos( $a, $b ) === 0 || strpos( $b, $a ) === 0 ) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Build and request-cache the unified shooter identity index merging:
 * 1. Member Directory (Primary Heads, Household Dependents, Unassigned Pool)
 * 2. 18-Month TFAA Standings & Historical Shooters (sywat_get_historical_shooters() + sywat_tfaa_cache)
 * 3. Shop Registration History Cache (sywat_history_profiles_cache)
 *
 * @return array{
 *     records: array<int, array>,
 *     exact: array<string, array<int, int>>,
 *     fl: array<string, array<int, int>>,
 *     last: array<string, array<int, int>>
 * }
 */
if ( ! function_exists( 'sywat_get_unified_shooter_identity_index' ) ) {
    function sywat_get_unified_shooter_identity_index() {
        static $unified_index = null;
        if ( null !== $unified_index ) {
            return $unified_index;
        }

        $records      = array();
        $exact_map    = array();
        $fl_map       = array();
        $last_map     = array();
        $placeholders = array( '-', '--', '—', '–', 'n/a', 'na', 'none', 'unassigned', 'unknown', 'no show' );

        $clean_field = function( $val ) use ( $placeholders ) {
            $str = trim( (string) $val );
            if ( $str === '' || in_array( strtolower( $str ), $placeholders, true ) ) {
                return '';
            }
            return $str;
        };

        $add_record = function( $rec ) use ( &$records, &$exact_map, &$fl_map, &$last_map ) {
            $name = sywat_normalize_name_first_last( $rec['full_name'] );
            if ( $name === '' ) {
                return;
            }
            $keys = sywat_normalize_archer_match_keys( $name );
            if ( $keys['exact'] === '' ) {
                return;
            }

            $rec['full_name'] = $name;
            $rec['keys']      = $keys;
            $idx              = count( $records );
            $records[ $idx ]  = $rec;

            $exact_map[ $keys['exact'] ][] = $idx;
            if ( $keys['fl'] !== '' ) {
                $fl_map[ $keys['fl'] ][] = $idx;
            }
            if ( $keys['token_count'] >= 2 && $keys['last_len'] >= 2 ) {
                $last_bucket              = $keys['last'] . '___' . $keys['suffix'];
                $last_map[ $last_bucket ][] = $idx;
            }
        };

        // Source 1: Member Directory (Authoritative official names, DOB, gender, email, phone)
        $member_records = function_exists( 'sywat_get_bulk_member_directory_records' )
            ? sywat_get_bulk_member_directory_records()
            : array();

        if ( is_array( $member_records ) ) {
            foreach ( $member_records as $m ) {
                if ( ! is_array( $m ) || empty( $m['display_name'] ) ) {
                    continue;
                }
                $raw_gen = isset( $m['gender'] ) ? strtolower( trim( (string) $m['gender'] ) ) : '';
                $gen_val = ( $raw_gen === 'male' || $raw_gen === 'female' ) ? ucfirst( $raw_gen ) : '';

                $add_record( array(
                    'full_name'    => (string) $m['display_name'],
                    'source'       => 'member_directory',
                    'priority'     => 3,
                    'division'     => '',
                    'style'        => '',
                    'gender'       => $gen_val,
                    'dob'          => isset( $m['dob'] ) ? trim( (string) $m['dob'] ) : '',
                    'email'        => isset( $m['email'] ) ? strtolower( trim( (string) $m['email'] ) ) : '',
                    'phone_digits' => isset( $m['phone'] ) ? preg_replace( '/\D+/', '', (string) $m['phone'] ) : '',
                    'family_id'    => ! empty( $m['head_user_id'] ) ? 'hh_' . intval( $m['head_user_id'] ) : '',
                    'system_id'    => isset( $m['system_id'] ) ? (string) $m['system_id'] : '',
                ) );
            }
        }

        // Sources 2 & 3: 18-Month TFAA Standings & Shop Registration History
        $history_sources = array(
            'tfaa_historical' => array(
                'data'     => function_exists( 'sywat_get_historical_shooters' ) ? sywat_get_historical_shooters() : array(),
                'priority' => 2,
            ),
            'tfaa_standings'  => array(
                'data'     => get_option( 'sywat_tfaa_cache', array() ),
                'priority' => 2,
            ),
            'shop_history'    => array(
                'data'     => get_transient( 'sywat_history_profiles_cache' ),
                'priority' => 1,
            ),
        );

        foreach ( $history_sources as $src_key => $src_cfg ) {
            $bundle = $src_cfg['data'];
            if ( ! is_array( $bundle ) || empty( $bundle ) ) {
                continue;
            }

            $flat_list = array();
            if ( isset( $bundle['shooters'] ) && is_array( $bundle['shooters'] ) ) {
                $flat_list = $bundle['shooters'];
            } elseif ( isset( $bundle['exact'] ) || isset( $bundle['fl'] ) ) {
                if ( isset( $bundle['exact'] ) && is_array( $bundle['exact'] ) ) {
                    $flat_list = array_values( $bundle['exact'] );
                }
                if ( isset( $bundle['fl'] ) && is_array( $bundle['fl'] ) ) {
                    foreach ( $bundle['fl'] as $fl_rec ) {
                        $flat_list[] = $fl_rec;
                    }
                }
            } else {
                $flat_list = $bundle;
            }

            $seen_in_source = array();
            foreach ( $flat_list as $rec ) {
                if ( ! is_array( $rec ) ) {
                    continue;
                }
                $r_name = isset( $rec['full_name'] ) ? trim( (string) $rec['full_name'] ) : ( isset( $rec['name'] ) ? trim( (string) $rec['name'] ) : '' );
                if ( $r_name === '' ) {
                    continue;
                }

                $r_div = $clean_field( isset( $rec['division'] ) ? $rec['division'] : '' );
                $r_sty = $clean_field( isset( $rec['style'] ) ? $rec['style'] : '' );
                $r_gen = $clean_field( isset( $rec['gender'] ) ? $rec['gender'] : '' );

                $dedup_sig = strtolower( sywat_normalize_name_first_last( $r_name ) . '|' . $r_div . '|' . $r_sty . '|' . $r_gen );
                if ( isset( $seen_in_source[ $dedup_sig ] ) ) {
                    continue;
                }
                $seen_in_source[ $dedup_sig ] = true;

                $add_record( array(
                    'full_name'    => $r_name,
                    'source'       => $src_key,
                    'priority'     => $src_cfg['priority'],
                    'division'     => $r_div,
                    'style'        => $r_sty,
                    'gender'       => $r_gen,
                    'dob'          => '',
                    'email'        => '',
                    'phone_digits' => isset( $rec['phone'] ) ? preg_replace( '/\D+/', '', (string) $rec['phone'] ) : '',
                    'family_id'    => '',
                    'system_id'    => '',
                ) );
            }
        }

        $unified_index = array(
            'records' => $records,
            'exact'   => $exact_map,
            'fl'      => $fl_map,
            'last'    => $last_map,
        );

        return $unified_index;
    }
}

/**
 * Find ranked matching shooter records across Member Directory, 18-Month TFAA Standings,
 * and Shop History.
 *
 * Match Ranks:
 * - 4: Exact normalized name match ("Steve Bergh" == "Steve Bergh")
 * - 3: First + Last + Suffix match ("Mark E. Tanner" == "Mark Tanner")
 * - 2: Contact-verified OR Given-Name Alias/Stem match on identical Last Name + Suffix ("Steven Bergh" == "Steve Bergh")
 *
 * @param string $archer_name   Raw archer name.
 * @param array  $contact_hints Optional ['email' => string, 'phone' => string].
 * @return array<int, array>    Ranked candidate records (highest rank first).
 */
if ( ! function_exists( 'sywat_find_unified_shooter_matches' ) ) {
    function sywat_find_unified_shooter_matches( $archer_name, $contact_hints = array() ) {
        $clean_name = trim( preg_replace( '/[[:space:]]+/', ' ', (string) $archer_name ) );
        if ( $clean_name === '' ) {
            return array();
        }

        $q_keys = sywat_normalize_archer_match_keys( $clean_name );
        if ( $q_keys['exact'] === '' ) {
            return array();
        }

        $index      = sywat_get_unified_shooter_identity_index();
        $records    = $index['records'];
        $hint_email = isset( $contact_hints['email'] ) ? strtolower( trim( (string) $contact_hints['email'] ) ) : '';
        $hint_phone = isset( $contact_hints['phone'] ) ? preg_replace( '/\D+/', '', (string) $contact_hints['phone'] ) : '';
        if ( strlen( $hint_phone ) > 10 && substr( $hint_phone, 0, 1 ) === '1' ) {
            $hint_phone = substr( $hint_phone, 1 );
        }

        $ranked_hits = array();

        $register_hit = function( $rec_idx, $rank ) use ( &$ranked_hits ) {
            if ( ! isset( $ranked_hits[ $rec_idx ] ) || $rank > $ranked_hits[ $rec_idx ] ) {
                $ranked_hits[ $rec_idx ] = $rank;
            }
        };

        // Rank 4: Exact match
        if ( isset( $index['exact'][ $q_keys['exact'] ] ) ) {
            foreach ( $index['exact'][ $q_keys['exact'] ] as $idx ) {
                $register_hit( $idx, 4 );
            }
        }

        // Rank 3: First + Last + Suffix match (middle-initial folding)
        if ( $q_keys['fl'] !== '' && isset( $index['fl'][ $q_keys['fl'] ] ) ) {
            foreach ( $index['fl'][ $q_keys['fl'] ] as $idx ) {
                $register_hit( $idx, 3 );
            }
        }

        // Rank 2: Last Name + Suffix bucket with Given-Name Alias/Prefix or Verified Contact Match
        if ( $q_keys['token_count'] >= 2 && $q_keys['last_len'] >= 2 ) {
            $last_bucket = $q_keys['last'] . '___' . $q_keys['suffix'];
            if ( isset( $index['last'][ $last_bucket ] ) ) {
                foreach ( $index['last'][ $last_bucket ] as $idx ) {
                    if ( isset( $ranked_hits[ $idx ] ) ) {
                        continue;
                    }
                    $cand   = $records[ $idx ];
                    $c_keys = $cand['keys'];

                    $name_compatible = sywat_are_given_names_compatible( $q_keys['first'], $c_keys['first'] );

                    // Contact match only counts when first names are compatible (never confuse parent & child sharing email/phone)
                    $contact_verified = false;
                    if ( $name_compatible ) {
                        if ( $hint_email !== '' && $cand['email'] !== '' && $hint_email === $cand['email'] ) {
                            $contact_verified = true;
                        }
                        if ( strlen( $hint_phone ) >= 10 && $cand['phone_digits'] !== '' ) {
                            $c_tel = ( strlen( $cand['phone_digits'] ) > 10 && substr( $cand['phone_digits'], 0, 1 ) === '1' )
                                ? substr( $cand['phone_digits'], 1 )
                                : $cand['phone_digits'];
                            if ( $c_tel === $hint_phone ) {
                                $contact_verified = true;
                            }
                        }
                    }

                    if ( $contact_verified || $name_compatible ) {
                        $register_hit( $idx, 2 );
                    }
                }
            }
        }

        if ( empty( $ranked_hits ) ) {
            return array();
        }

        $results = array();
        foreach ( $ranked_hits as $idx => $rank ) {
            $rec               = $records[ $idx ];
            $rec['match_rank'] = $rank;

            // Boost score when contact info also matches on Rank 3/4 candidates
            $contact_bonus = 0;
            if ( $hint_email !== '' && $rec['email'] !== '' && $hint_email === $rec['email'] ) {
                $contact_bonus += 2;
            }
            if ( strlen( $hint_phone ) >= 10 && $rec['phone_digits'] !== '' ) {
                $c_tel = ( strlen( $rec['phone_digits'] ) > 10 && substr( $rec['phone_digits'], 0, 1 ) === '1' )
                    ? substr( $rec['phone_digits'], 1 )
                    : $rec['phone_digits'];
                if ( $c_tel === $hint_phone ) {
                    $contact_bonus += 1;
                }
            }
            $rec['contact_bonus'] = $contact_bonus;
            $results[]            = $rec;
        }

        usort( $results, function( $a, $b ) {
            if ( $a['match_rank'] !== $b['match_rank'] ) {
                return ( $b['match_rank'] <=> $a['match_rank'] );
            }
            if ( $a['contact_bonus'] !== $b['contact_bonus'] ) {
                return ( $b['contact_bonus'] <=> $a['contact_bonus'] );
            }
            if ( $a['priority'] !== $b['priority'] ) {
                return ( $b['priority'] <=> $a['priority'] );
            }
            return 0;
        } );

        return $results;
    }
}

/**
 * Single-source-of-truth canonical intake profile resolver used across:
 * - Bulk Spreadsheet Import (registration-import.php)
 * - Phone & Group Registration Intake (registration-actions.php)
 * - Member Online Self-Registration (member-registration-actions.php)
 * - Walk-In & Check-In Desk Standardization (checkin-actions.php)
 *
 * Division Resolution Rule:
 * - Evaluates both Historical Division (18-Month TFAA / Shop History) and Member Directory DOB Division.
 * - Selects whichever category is higher on the NFAA ladder (max(History, DOB)) so mid-season
 *   "shooting up" and birthday "aging up" both resolve accurately.
 *
 * Enforces a strict ambiguity guard: if multiple distinct shooters match a fuzzy alias
 * (e.g., two different relatives sharing a last name and prefix), canonical_name is NOT
 * overwritten so Check-In can display the multi-profile selector safely.
 *
 * @param string $archer_name     Archer full name.
 * @param string $tournament_date Optional YYYY-MM-DD tournament start date.
 * @param array  $contact_hints   Optional hints ('email', 'phone').
 * @return array                  Standardized resolved shooter profile array.
 */
if ( ! function_exists( 'sywat_resolve_archer_intake_profile' ) ) {
    function sywat_resolve_archer_intake_profile( $archer_name, $tournament_date = '', $contact_hints = array() ) {
        $clean_name = sywat_format_person_name_case( $archer_name );
        $profile    = array(
            'canonical_name' => $clean_name,
            'was_normalized' => false,
            'matched'        => false,
            'source'         => '',
            'division'       => '',
            'distance'       => '',
            'rate_tier'      => '',
            'gender'         => '',
            'style'          => '',
            'phone'          => isset( $contact_hints['phone'] ) ? trim( (string) $contact_hints['phone'] ) : '',
            'email'          => isset( $contact_hints['email'] ) ? trim( (string) $contact_hints['email'] ) : '',
            'family_id'      => '',
            'system_id'      => '',
        );

        if ( $clean_name === '' ) {
            return $profile;
        }

        $matches = sywat_find_unified_shooter_matches( $clean_name, $contact_hints );
        if ( empty( $matches ) ) {
            return $profile;
        }

        $top_rank = $matches[0]['match_rank'];
        $top_tier = array_values( array_filter( $matches, function( $m ) use ( $top_rank ) {
            return $m['match_rank'] === $top_rank;
        } ) );

        // Count distinct canonical shooter identities in the top match tier
        $distinct_identities = array();
        foreach ( $top_tier as $cand ) {
            $id_key                         = isset( $cand['keys']['exact'] ) ? $cand['keys']['exact'] : strtolower( $cand['full_name'] );
            $distinct_identities[ $id_key ] = $cand['full_name'];
        }

        // Strict ambiguity guard: if Rank < 4 and multiple distinct people match, do not guess
        if ( $top_rank < 4 && count( $distinct_identities ) > 1 ) {
            return $profile;
        }

        $has_norm_div_fn = function_exists( 'sywat_shooting_styles_normalize_division' );
        $normalize_div   = function( $raw_div ) use ( $has_norm_div_fn ) {
            $clean_div = trim( (string) $raw_div );
            if ( $clean_div === '' || strcasecmp( $clean_div, 'No Show' ) === 0 ) {
                return '';
            }
            $norm = $has_norm_div_fn ? sywat_shooting_styles_normalize_division( $clean_div ) : $clean_div;
            return $norm !== '' ? $norm : $clean_div;
        };

        $primary_hit               = $top_tier[0];
        $official_name             = sywat_format_person_name_case( $primary_hit['full_name'] );
        $profile['matched']        = true;
        $profile['source']         = $primary_hit['source'];
        $profile['canonical_name'] = $official_name;
        $profile['was_normalized'] = ( strcasecmp( $clean_name, $official_name ) !== 0 );

        $dob_division  = '';
        $hist_division = '';
        $target_exact  = $primary_hit['keys']['exact'];

        // Merge attributes across all records belonging to this resolved shooter (Member DB + TFAA + Shop History)
        foreach ( $matches as $rec ) {
            if ( $rec['keys']['exact'] !== $target_exact ) {
                continue;
            }

            if ( $rec['source'] === 'member_directory' ) {
                $profile['source'] = 'member_directory';
                if ( $profile['system_id'] === '' && ! empty( $rec['system_id'] ) ) {
                    $profile['system_id'] = $rec['system_id'];
                }
                if ( $profile['family_id'] === '' && ! empty( $rec['family_id'] ) ) {
                    $profile['family_id'] = $rec['family_id'];
                }
                if ( $profile['email'] === '' && ! empty( $rec['email'] ) ) {
                    $profile['email'] = $rec['email'];
                }
                if ( $profile['phone'] === '' && ! empty( $rec['phone_digits'] ) ) {
                    $profile['phone'] = $rec['phone_digits'];
                }
                if ( $dob_division === '' && ! empty( $rec['dob'] ) && function_exists( 'sywat_get_eligible_age_division' ) ) {
                    $dob_division = $normalize_div( sywat_get_eligible_age_division( $rec['dob'], $tournament_date ?: null ) );
                }
            } elseif ( ! empty( $rec['division'] ) ) {
                $cand_hist_div = $normalize_div( $rec['division'] );
                if ( $cand_hist_div !== '' ) {
                    $hist_division = sywat_select_highest_category_division( $hist_division, $cand_hist_div );
                }
            }

            if ( $profile['style'] === '' && ! empty( $rec['style'] ) ) {
                $profile['style'] = trim( (string) $rec['style'] );
            }
            if ( $profile['gender'] === '' && ! empty( $rec['gender'] ) ) {
                $profile['gender'] = trim( (string) $rec['gender'] );
            }
        }

        // Select highest NFAA category between History ("shooting up") and DOB (birthday age-up)
        $winning_division = sywat_select_highest_category_division( $hist_division, $dob_division );
        if ( $winning_division !== '' ) {
            $dist = function_exists( 'sywat_shooting_styles_get_division_distance' )
                ? sywat_shooting_styles_get_division_distance( $winning_division )
                : ( stripos( $winning_division, 'Cub' ) !== false ? 'Cub' : '20yd' );
            $tier = ( $dist === 'Cub' )
                ? 'Youth'
                : ( function_exists( 'sywat_shooting_styles_get_division_rate_tier' )
                    ? sywat_shooting_styles_get_division_rate_tier( $winning_division )
                    : ( stripos( $winning_division, 'Youth' ) !== false ? 'Youth' : 'Adult' ) );

            $profile['division']  = $winning_division;
            $profile['distance']  = $dist;
            $profile['rate_tier'] = $tier;
        }

        return $profile;
    }
}

/**
 * Build a deduplicated, lightweight shooter list for client-side Registration Intake autocomplete
 * combining Member Directory records and 18-Month TFAA Standings.
 * Applies the highest-category rule (max(History, DOB)) in O(N) single-pass hashtable lookups
 * so both top and bottom search fields display accurate NFAA age divisions.
 *
 * @param string $tournament_date Optional YYYY-MM-DD reference date for DOB age division calculation.
 * @return array<int, array{name: string, division: string, style: string, gender: string}>
 */
if ( ! function_exists( 'sywat_get_unified_js_shooter_index' ) ) {
    function sywat_get_unified_js_shooter_index( $tournament_date = '' ) {
        $index   = sywat_get_unified_shooter_identity_index();
        $records = $index['records'];
        $ref_ymd = trim( (string) $tournament_date );
        if ( $ref_ymd === '' ) {
            $ref_ymd = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        $has_norm_div_fn = function_exists( 'sywat_shooting_styles_normalize_division' );
        $has_dob_div_fn  = function_exists( 'sywat_get_eligible_age_division' );
        $normalize_div   = function( $raw_div ) use ( $has_norm_div_fn ) {
            $clean_div = trim( (string) $raw_div );
            if ( $clean_div === '' || strcasecmp( $clean_div, 'No Show' ) === 0 ) {
                return '';
            }
            $norm = $has_norm_div_fn ? sywat_shooting_styles_normalize_division( $clean_div ) : $clean_div;
            return $norm !== '' ? $norm : $clean_div;
        };

        $by_name   = array();
        $fl_groups = array();

        foreach ( $records as $rec ) {
            $exact = $rec['keys']['exact'];
            if ( $exact === '' ) {
                continue;
            }

            if ( ! isset( $by_name[ $exact ] ) ) {
                $by_name[ $exact ] = array(
                    'name'      => $rec['full_name'],
                    'fl'        => $rec['keys']['fl'],
                    'hist_div'  => '',
                    'dob_div'   => '',
                    'style'     => '',
                    'gender'    => '',
                );
                if ( $rec['keys']['fl'] !== '' && strpos( $rec['keys']['fl'], '___' ) !== false ) {
                    $fl_groups[ $rec['keys']['fl'] ][] = $exact;
                }
            }

            if ( $rec['source'] === 'member_directory' ) {
                if ( $by_name[ $exact ]['dob_div'] === '' && ! empty( $rec['dob'] ) && $has_dob_div_fn ) {
                    $by_name[ $exact ]['dob_div'] = $normalize_div( sywat_get_eligible_age_division( $rec['dob'], $ref_ymd ) );
                }
            } elseif ( ! empty( $rec['division'] ) ) {
                $cand_hist = $normalize_div( $rec['division'] );
                if ( $cand_hist !== '' ) {
                    $by_name[ $exact ]['hist_div'] = sywat_select_highest_category_division( $by_name[ $exact ]['hist_div'], $cand_hist );
                }
            }

            if ( $by_name[ $exact ]['style'] === '' && ! empty( $rec['style'] ) ) {
                $by_name[ $exact ]['style'] = trim( (string) $rec['style'] );
            }
            if ( $by_name[ $exact ]['gender'] === '' && ! empty( $rec['gender'] ) ) {
                $by_name[ $exact ]['gender'] = trim( (string) $rec['gender'] );
            }
        }

        // Unambiguous First+Last backfill (e.g., "Mark E. Tanner" <-> "Mark Tanner" when only 1 identity shares that FL key)
        foreach ( $fl_groups as $fl_exacts ) {
            if ( count( $fl_exacts ) < 2 ) {
                continue;
            }
            $shared_hist   = '';
            $shared_dob    = '';
            $shared_style  = '';
            $shared_gender = '';

            foreach ( $fl_exacts as $ex_key ) {
                $shared_hist = sywat_select_highest_category_division( $shared_hist, $by_name[ $ex_key ]['hist_div'] );
                $shared_dob  = sywat_select_highest_category_division( $shared_dob, $by_name[ $ex_key ]['dob_div'] );
                if ( $shared_style === '' && $by_name[ $ex_key ]['style'] !== '' ) {
                    $shared_style = $by_name[ $ex_key ]['style'];
                }
                if ( $shared_gender === '' && $by_name[ $ex_key ]['gender'] !== '' ) {
                    $shared_gender = $by_name[ $ex_key ]['gender'];
                }
            }

            foreach ( $fl_exacts as $ex_key ) {
                if ( $by_name[ $ex_key ]['hist_div'] === '' && $shared_hist !== '' ) {
                    $by_name[ $ex_key ]['hist_div'] = $shared_hist;
                }
                if ( $by_name[ $ex_key ]['dob_div'] === '' && $shared_dob !== '' ) {
                    $by_name[ $ex_key ]['dob_div'] = $shared_dob;
                }
                if ( $by_name[ $ex_key ]['style'] === '' && $shared_style !== '' ) {
                    $by_name[ $ex_key ]['style'] = $shared_style;
                }
                if ( $by_name[ $ex_key ]['gender'] === '' && $shared_gender !== '' ) {
                    $by_name[ $ex_key ]['gender'] = $shared_gender;
                }
            }
        }

        $output = array();
        foreach ( $by_name as $entry ) {
            $output[] = array(
                'name'     => $entry['name'],
                'division' => sywat_select_highest_category_division( $entry['hist_div'], $entry['dob_div'] ),
                'style'    => $entry['style'],
                'gender'   => $entry['gender'],
            );
        }

        return $output;
    }
}
