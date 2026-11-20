<?php
/**
 * Sub-Module: Registration Engine Actions Handler
 * Path: includes/registration/registration-actions.php
 * Description: Handles multi-archer phone intake with per-archer multi-line checkbox matrix support (archers[i][line_indices][], with backward-compatible single-line line_idx fallback), enforces that every named archer selects at least one shooting line (zero silent default-line fallbacks), expands multi-line entries in chronological tournament line order, resolves Member Directory DOB & history profiles via sywat_resolve_archer_intake_profile() without guessing divisions for unmatched shooters, evaluates round/pricing profiles, First-Time/Lapsed Shooter promoter free-entry rules, and dynamic Household Family Caps (line or tournament scope), handles waitlist triage ('all' vs. 'remainder') with full line_indices[] preservation, and persists records atomically.
 * Compliance: Pure action layer — zero output at include time, zero inline style attributes, zero inline JS event handlers (delegated data-sywat-action attributes only), hoisted O(1) capacity & duplicate maps, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'sywat_detect_paid_category' ) ) {
    function sywat_detect_paid_category( $text ) {
        $str = is_array( $text ) ? implode( ' ', $text ) : (string) $text;
        if ( preg_match( '/max\.?\s*per\s*family|family/i', $str ) ) {
            return 'Family';
        }
        if ( preg_match( '/cub\/youth\/young\s*adult|cub|youth|young\s*adult/i', $str ) ) {
            return 'Youth';
        }
        if ( preg_match( '/adult\s*categories|adult/i', $str ) ) {
            return 'Adult';
        }
        return '';
    }
}

if ( ! function_exists( 'sywat_extract_due_amount' ) ) {
    function sywat_extract_due_amount( $str ) {
        $clean = preg_replace( '/[^\d\.]/', '', (string) $str );
        return floatval( $clean );
    }
}

/**
 * Resolve a raw line index or line title string to its canonical array index key in $t_lines.
 *
 * @param string|int $raw_line_idx Raw submitted line index or title.
 * @param array      $t_lines      Tournament shooting lines array.
 * @return string                  Canonical string line index key, or empty string if unresolved.
 */
if ( ! function_exists( 'sywat_registration_resolve_line_idx' ) ) {
    function sywat_registration_resolve_line_idx( $raw_line_idx, $t_lines ) {
        $idx_str = trim( (string) $raw_line_idx );
        if ( $idx_str === '' ) {
            return '';
        }
        if ( ! is_array( $t_lines ) || empty( $t_lines ) ) {
            return $idx_str;
        }

        if ( isset( $t_lines[ $idx_str ] ) ) {
            return $idx_str;
        }
        if ( is_numeric( $idx_str ) && isset( $t_lines[ (int) $idx_str ] ) ) {
            return (string) (int) $idx_str;
        }

        foreach ( $t_lines as $l_key => $l_data ) {
            if ( is_array( $l_data ) && ! empty( $l_data['name'] ) && strcasecmp( trim( (string) $l_data['name'] ), $idx_str ) === 0 ) {
                return (string) $l_key;
            }
        }

        return '';
    }
}

/**
 * Resolve the list of canonical line index keys requested for a single archer row,
 * preserving chronological tournament line order ($t_lines key order) and deduplicating entries.
 *
 * @param array  $archer_row       Raw submitted archer row array.
 * @param string $fallback_line    Optional legacy single-line fallback index (when line_indices[] is absent).
 * @param array  $t_lines          Chronologically ordered tournament lines map.
 * @return array<int, string>      Ordered list of resolved canonical line index keys.
 */
if ( ! function_exists( 'sywat_registration_extract_row_line_indices' ) ) {
    function sywat_registration_extract_row_line_indices( $archer_row, $fallback_line, $t_lines ) {
        $raw_candidates = array();

        if ( isset( $archer_row['line_indices'] ) && is_array( $archer_row['line_indices'] ) ) {
            foreach ( $archer_row['line_indices'] as $raw_idx ) {
                $clean = trim( sanitize_text_field( (string) $raw_idx ) );
                if ( $clean !== '' ) {
                    $raw_candidates[] = $clean;
                }
            }
        } elseif ( isset( $archer_row['line_idx'] ) && trim( (string) $archer_row['line_idx'] ) !== '' ) {
            $raw_candidates[] = trim( sanitize_text_field( (string) $archer_row['line_idx'] ) );
        } elseif ( trim( (string) $fallback_line ) !== '' ) {
            $raw_candidates[] = trim( sanitize_text_field( (string) $fallback_line ) );
        }

        if ( empty( $raw_candidates ) ) {
            return array();
        }

        $resolved_set = array();
        foreach ( $raw_candidates as $cand ) {
            $canonical = sywat_registration_resolve_line_idx( $cand, $t_lines );
            if ( $canonical !== '' ) {
                $resolved_set[ $canonical ] = true;
            }
        }

        if ( empty( $resolved_set ) ) {
            return array();
        }

        if ( is_array( $t_lines ) && ! empty( $t_lines ) ) {
            $ordered = array();
            foreach ( array_keys( $t_lines ) as $t_key ) {
                $key_str = (string) $t_key;
                if ( isset( $resolved_set[ $key_str ] ) ) {
                    $ordered[] = $key_str;
                    unset( $resolved_set[ $key_str ] );
                }
            }
            foreach ( array_keys( $resolved_set ) as $rem_key ) {
                $ordered[] = (string) $rem_key;
            }
            return $ordered;
        }

        return array_map( 'strval', array_keys( $resolved_set ) );
    }
}

/**
 * Handles multi-archer phone intake with per-archer multi-line checkbox matrices,
 * Member Directory & history profile lookup, round/pricing profile fee calculation,
 * First-Time/Lapsed Shooter promoter evaluation, active-only household family capping,
 * waitlists, and persistence.
 *
 * @param array $tournaments   Master tournament configuration array.
 * @param array $registrations Master registration state array reference.
 * @return string              Rendered HTML notice message.
 */
if ( ! function_exists( 'sywat_handle_register_shooter' ) ) {
    function sywat_handle_register_shooter( $tournaments, &$registrations ) {
        $group_name       = trim( sanitize_text_field( wp_unslash( $_POST['group_name'] ?? '' ) ) );
        $group_phone      = trim( sanitize_text_field( wp_unslash( $_POST['group_phone'] ?? '' ) ) );
        $legacy_def_line  = trim( sanitize_text_field( wp_unslash( $_POST['default_line_idx'] ?? '' ) ) );
        $tourn_name       = sanitize_text_field( wp_unslash( $_POST['selected_tournament'] ?? '' ) );
        $amount_paid      = isset( $_POST['amount_paid_today'] ) ? max( 0.00, floatval( wp_unslash( $_POST['amount_paid_today'] ) ) ) : 0.00;
        $allow_partial    = isset( $_POST['allow_partial'] ) && wp_unslash( $_POST['allow_partial'] ) === '1';
        $waitlist_mode    = sanitize_text_field( wp_unslash( $_POST['waitlist_mode'] ?? '' ) );
        $t_slug           = sanitize_title( $tourn_name );

        $t_data     = isset( $tournaments[ $tourn_name ] ) ? $tournaments[ $tourn_name ] : array();
        $tourn_date = ( is_array( $t_data ) && ! empty( $t_data['start_date'] ) ) ? (string) $t_data['start_date'] : '';
        $t_lines    = function_exists( 'sywat_get_tournament_lines' )
            ? sywat_get_tournament_lines( $t_data )
            : ( isset( $t_data['lines'] ) ? $t_data['lines'] : array() );

        // Resolve offered Pricing & Round Profiles for this tournament
        $t_profiles       = function_exists( 'sywat_get_tournament_pricing_profiles' )
            ? sywat_get_tournament_pricing_profiles( ! empty( $t_data ) ? $t_data : $tourn_name )
            : array();
        $default_prof     = ! empty( $t_profiles )
            ? reset( $t_profiles )
            : ( function_exists( 'sywat_get_default_pricing_profile' ) ? sywat_get_default_pricing_profile() : array() );
        $default_prof_key = is_array( $default_prof ) && ! empty( $default_prof['slug'] ) ? $default_prof['slug'] : 'indoor';

        $closed_lines_raw = get_option( 'sywat_closed_lines_' . $t_slug, array() );
        $closed_lines_map = is_array( $closed_lines_raw )
            ? array_flip( array_map( 'trim', array_map( 'strval', $closed_lines_raw ) ) )
            : array();

        $archers_raw = isset( $_POST['archers'] ) && is_array( $_POST['archers'] ) ? wp_unslash( $_POST['archers'] ) : array();

        if ( empty( $archers_raw ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please add at least one archer name.</div>';
        }

        $legacy_youth_divisions = array( 'Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)' );
        $valid_entries          = array();
        $missing_line_archers   = array();
        $has_named_archer       = false;

        foreach ( $archers_raw as $a ) {
            if ( ! is_array( $a ) ) {
                continue;
            }
            $name = trim( sanitize_text_field( $a['name'] ?? '' ) );
            if ( $name === '' ) {
                continue;
            }
            $has_named_archer = true;

            $row_line_indices = sywat_registration_extract_row_line_indices( $a, $legacy_def_line, $t_lines );
            if ( empty( $row_line_indices ) ) {
                $missing_line_archers[] = $name;
                continue;
            }

            $raw_tier = strtolower( trim( sanitize_text_field( $a['rate_tier'] ?? '' ) ) );
            $division = trim( sanitize_text_field( $a['division'] ?? '' ) );

            // Resolve Member Directory (DOB age division, gender, contact) & cached shop/TFAA history once per archer row
            $resolved_profile = function_exists( 'sywat_resolve_archer_intake_profile' )
                ? sywat_resolve_archer_intake_profile( $name, $tourn_date, array( 'phone' => $group_phone ) )
                : array();

            $canonical_name = ! empty( $resolved_profile['canonical_name'] )
                ? trim( (string) $resolved_profile['canonical_name'] )
                : $name;

            if ( in_array( $raw_tier, array( 'cub', 'youth', 'adult' ), true ) ) {
                if ( $raw_tier === 'cub' ) {
                    $distance  = 'Cub';
                    $paid_tier = 'Youth';
                } elseif ( $raw_tier === 'youth' ) {
                    $distance  = '20yd';
                    $paid_tier = 'Youth';
                } else {
                    $distance  = '20yd';
                    $paid_tier = 'Adult';
                }
            } else {
                // Legacy fallback if distance/division were posted directly
                $distance = function_exists( 'sywat_normalize_dist' )
                    ? sywat_normalize_dist( sanitize_text_field( $a['distance'] ?? '20yd' ) )
                    : sanitize_text_field( $a['distance'] ?? '20yd' );

                if ( $distance === 'Cub' ) {
                    $paid_tier = 'Youth';
                } elseif ( $division !== '' && function_exists( 'sywat_shooting_styles_get_division_rate_tier' ) ) {
                    $paid_tier = sywat_shooting_styles_get_division_rate_tier( $division );
                } elseif ( $division !== '' ) {
                    $paid_tier = in_array( $division, $legacy_youth_divisions, true ) ? 'Youth' : 'Adult';
                } elseif ( ! empty( $resolved_profile['rate_tier'] ) ) {
                    $paid_tier = $resolved_profile['rate_tier'];
                    if ( isset( $resolved_profile['distance'] ) && $resolved_profile['distance'] === 'Cub' ) {
                        $distance = 'Cub';
                    }
                } else {
                    $paid_tier = 'Adult';
                }
            }

            if ( $division !== '' && function_exists( 'sywat_shooting_styles_normalize_division' ) ) {
                $division = sywat_shooting_styles_normalize_division( $division );
            }

            // Populate verified NFAA division from Member Directory / 18-Month TFAA history when it matches
            // the selected distance & rate tier; otherwise leave blank ('') so Check-In resolves or prompts cleanly.
            if ( $division === '' && ! empty( $resolved_profile['division'] ) ) {
                $res_dist_ok = empty( $resolved_profile['distance'] ) || $resolved_profile['distance'] === $distance;
                $res_tier_ok = empty( $resolved_profile['rate_tier'] ) || $resolved_profile['rate_tier'] === $paid_tier;
                if ( $res_dist_ok && $res_tier_ok ) {
                    $division = function_exists( 'sywat_shooting_styles_normalize_division' )
                        ? sywat_shooting_styles_normalize_division( $resolved_profile['division'] )
                        : trim( (string) $resolved_profile['division'] );
                }
            }

            $raw_round = ! empty( $a['pricing_profile'] ) ? sanitize_title( (string) $a['pricing_profile'] ) : $default_prof_key;
            if ( ! empty( $t_profiles ) && ! isset( $t_profiles[ $raw_round ] ) ) {
                $raw_round = $default_prof_key;
            }

            $archer_phone = ! empty( $group_phone ) ? $group_phone : ( isset( $resolved_profile['phone'] ) ? $resolved_profile['phone'] : '' );
            $archer_email = isset( $resolved_profile['email'] ) ? $resolved_profile['email'] : '';

            // Expand one entry per checked shooting line in chronological line order
            foreach ( $row_line_indices as $resolved_idx ) {
                $valid_entries[] = array(
                    'name'            => $canonical_name,
                    'division'        => $division,
                    'paid_tier'       => $paid_tier,
                    'distance'        => $distance,
                    'pricing_profile' => $raw_round,
                    'line_idx'        => $resolved_idx,
                    'gender'          => isset( $resolved_profile['gender'] ) ? $resolved_profile['gender'] : '',
                    'style'           => isset( $resolved_profile['style'] ) ? $resolved_profile['style'] : '',
                    'phone'           => $archer_phone,
                    'email'           => $archer_email,
                );
            }
        }

        if ( ! $has_named_archer ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please enter at least one valid archer name.</div>';
        }

        if ( ! empty( $missing_line_archers ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please check at least one shooting line for: <strong>' . esc_html( implode( ', ', $missing_line_archers ) ) . '</strong>.</div>';
        }

        // Hoist O(1) existing registration occupancy and duplicate lookup maps once outside loops
        $existing_occ_map  = array();
        $existing_dup_map  = array();
        $has_norm_dist_fn  = function_exists( 'sywat_normalize_dist' );
        $has_line_label_fn = function_exists( 'sywat_resolve_tournament_line_label' );

        if ( is_array( $registrations ) ) {
            foreach ( $registrations as $ex ) {
                if ( ! is_array( $ex ) || ! isset( $ex['tournament'] ) ) {
                    continue;
                }
                if ( strcasecmp( trim( (string) $ex['tournament'] ), trim( $tourn_name ) ) !== 0 ) {
                    continue;
                }

                $ex_status = isset( $ex['status'] ) ? trim( (string) $ex['status'] ) : '';
                if ( strcasecmp( $ex_status, 'Cancelled' ) === 0 ) {
                    continue;
                }

                $ex_l_idx = isset( $ex['line_index'] ) ? trim( (string) $ex['line_index'] ) : '';
                if ( $ex_l_idx === '' ) {
                    continue;
                }

                $ex_name_raw = ! empty( $ex['name'] ) ? (string) $ex['name'] : ( ! empty( $ex['archer_name'] ) ? (string) $ex['archer_name'] : '' );
                $ex_name_low = strtolower( trim( $ex_name_raw ) );
                if ( $ex_name_low !== '' ) {
                    $existing_dup_map[ $ex_l_idx . '___' . $ex_name_low ] = true;
                }

                if ( strcasecmp( $ex_status, 'Waitlist' ) !== 0 ) {
                    $raw_ex_dist = isset( $ex['distance'] ) ? (string) $ex['distance'] : '20yd';
                    $norm_ex_d   = $has_norm_dist_fn
                        ? sywat_normalize_dist( $raw_ex_dist )
                        : ( ( strcasecmp( trim( $raw_ex_dist ), 'Cub' ) === 0 || strcasecmp( trim( $raw_ex_dist ), '10yd' ) === 0 ) ? 'Cub' : '20yd' );
                    $occ_key     = $ex_l_idx . '___' . $norm_ex_d;
                    if ( ! isset( $existing_occ_map[ $occ_key ] ) ) {
                        $existing_occ_map[ $occ_key ] = 0;
                    }
                    $existing_occ_map[ $occ_key ]++;
                }
            }
        }

        // Tally requested slots per resolved line/distance combo (excluding already-registered duplicates)
        $line_dist_tallies = array();
        foreach ( $valid_entries as $entry ) {
            $l_idx    = $entry['line_idx'];
            $dist     = $entry['distance'];
            $name_low = strtolower( trim( $entry['name'] ) );
            if ( isset( $existing_dup_map[ $l_idx . '___' . $name_low ] ) ) {
                continue;
            }

            $key = $l_idx . '___' . $dist;
            if ( ! isset( $line_dist_tallies[ $key ] ) ) {
                $line_dist_tallies[ $key ] = array(
                    'line_idx' => $l_idx,
                    'distance' => $dist,
                    'count'    => 0,
                );
            }
            $line_dist_tallies[ $key ]['count']++;
        }

        // Compute open slots per resolved line/distance (respecting closed lines)
        $remaining_open_slots = array();
        $capacity_overflows   = array();

        foreach ( $line_dist_tallies as $key => $tally ) {
            $l_idx     = $tally['line_idx'];
            $dist      = $tally['distance'];
            $req_cnt   = $tally['count'];
            $line_data = isset( $t_lines[ $l_idx ] ) ? $t_lines[ $l_idx ] : array();
            $line_name = $has_line_label_fn
                ? sywat_resolve_tournament_line_label( $line_data, $l_idx )
                : ( isset( $line_data['name'] ) ? $line_data['name'] : ( is_numeric( $l_idx ) ? 'Line ' . ( (int) $l_idx + 1 ) : $l_idx ) );
            $raw_lname = ( is_array( $line_data ) && ! empty( $line_data['name'] ) ) ? trim( (string) $line_data['name'] ) : '';

            $is_line_closed = isset( $closed_lines_map[ (string) $l_idx ] )
                           || ( $raw_lname !== '' && isset( $closed_lines_map[ $raw_lname ] ) )
                           || ( $line_name !== '' && isset( $closed_lines_map[ $line_name ] ) );

            $current_count = isset( $existing_occ_map[ $key ] ) ? intval( $existing_occ_map[ $key ] ) : 0;
            $max_capacity  = function_exists( 'sywat_get_line_max_cap' )
                ? sywat_get_line_max_cap( $line_data, $dist )
                : 0;

            if ( ! $max_capacity || $max_capacity <= 0 ) {
                $max_capacity = ( $dist === 'Cub' ) ? 5 : 20;
            }

            $open_slots                   = $is_line_closed ? 0 : max( 0, $max_capacity - $current_count );
            $remaining_open_slots[ $key ] = $open_slots;

            if ( $req_cnt > $open_slots ) {
                $capacity_overflows[] = array(
                    'line_idx'   => $l_idx,
                    'line_name'  => $line_name,
                    'distance'   => $dist,
                    'requested'  => $req_cnt,
                    'open_slots' => $open_slots,
                );
            }
        }

        if ( ! empty( $capacity_overflows ) && empty( $waitlist_mode ) && ! $allow_partial ) {
            $overflow_details   = array();
            $max_open_available = 0;

            foreach ( $capacity_overflows as $ov ) {
                $overflow_details[] = 'Line <strong>' . esc_html( $ov['line_name'] ) . '</strong> (' . esc_html( $ov['distance'] ) . ') has <strong>' . intval( $ov['open_slots'] ) . ' open spot(s)</strong>, but you requested <strong>' . intval( $ov['requested'] ) . ' archer(s)</strong>.';
                if ( $ov['open_slots'] > $max_open_available ) {
                    $max_open_available = $ov['open_slots'];
                }
            }

            $hidden_archers_html = '';
            foreach ( $archers_raw as $i => $a ) {
                if ( ! is_array( $a ) ) {
                    continue;
                }
                $idx_int              = intval( $i );
                $hidden_archers_html .= '<input type="hidden" name="archers[' . $idx_int . '][name]" value="' . esc_attr( $a['name'] ?? '' ) . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . $idx_int . '][rate_tier]" value="' . esc_attr( $a['rate_tier'] ?? '' ) . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . $idx_int . '][distance]" value="' . esc_attr( $a['distance'] ?? '' ) . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . $idx_int . '][division]" value="' . esc_attr( $a['division'] ?? '' ) . '">';
                $hidden_archers_html .= '<input type="hidden" name="archers[' . $idx_int . '][pricing_profile]" value="' . esc_attr( $a['pricing_profile'] ?? $default_prof_key ) . '">';

                if ( isset( $a['line_indices'] ) && is_array( $a['line_indices'] ) ) {
                    foreach ( $a['line_indices'] as $l_val ) {
                        $hidden_archers_html .= '<input type="hidden" name="archers[' . $idx_int . '][line_indices][]" value="' . esc_attr( $l_val ) . '">';
                    }
                } elseif ( isset( $a['line_idx'] ) && $a['line_idx'] !== '' ) {
                    $hidden_archers_html .= '<input type="hidden" name="archers[' . $idx_int . '][line_indices][]" value="' . esc_attr( $a['line_idx'] ) . '">';
                }
            }

            $base_hidden_fields  = wp_nonce_field( 'sywat_desk_action', 'sywat_nonce', true, false );
            $base_hidden_fields .= '<input type="hidden" name="sywat_action" value="register_shooter">';
            $base_hidden_fields .= '<input type="hidden" name="selected_tournament" value="' . esc_attr( $tourn_name ) . '">';
            $base_hidden_fields .= '<input type="hidden" name="group_name" value="' . esc_attr( $group_name ) . '">';
            $base_hidden_fields .= '<input type="hidden" name="group_phone" value="' . esc_attr( $group_phone ) . '">';
            $base_hidden_fields .= '<input type="hidden" name="amount_paid_today" value="' . esc_attr( $amount_paid ) . '">';
            $base_hidden_fields .= $hidden_archers_html;

            $prompt_html  = '<div class="sywat-overflow-dialog">';
            $prompt_html .= '<div class="sywat-overflow-title">⚠️ GROUP CAPACITY OVERFLOW WARNING</div>';
            $prompt_html .= '<div class="sywat-overflow-body">' . implode( '<br>', $overflow_details ) . '</div>';
            $prompt_html .= '<p class="sywat-overflow-subtext">No archers were registered yet. Please select how you would like to handle this group:</p>';
            $prompt_html .= '<div class="sywat-overflow-actions">';

            $prompt_html .= '<form method="post" class="sywat-overflow-form">' . $base_hidden_fields;
            $prompt_html .= '<input type="hidden" name="waitlist_mode" value="all">';
            $prompt_html .= '<button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-block">🕒 Waitlist ENTIRE Group (Keep Group Together - $0 Due)</button>';
            $prompt_html .= '</form>';

            if ( $max_open_available > 0 ) {
                $prompt_html .= '<form method="post" class="sywat-overflow-form">' . $base_hidden_fields;
                $prompt_html .= '<input type="hidden" name="waitlist_mode" value="remainder">';
                $prompt_html .= '<button type="submit" class="sywat-btn sywat-btn-warning-solid sywat-btn-block">⚠️ Book Available Spots (' . intval( $max_open_available ) . ') &amp; Waitlist Remainder</button>';
                $prompt_html .= '</form>';
            }

            $prompt_html .= '<button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-block" data-sywat-action="dismiss-overflow-modal">Cancel &amp; Change Line</button>';
            $prompt_html .= '</div></div>';

            $GLOBALS['sywat_modal_notice'] = $prompt_html;
            return '';
        }

        $family_base_name = ! empty( $group_name ) ? $group_name : $valid_entries[0]['name'];

        // Determine if any offered profile uses tournament-wide vs line-scoped family caps
        $use_tourn_wide_cap = false;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['family_cap_enabled'] ) && isset( $tp['family_cap_scope'] ) && $tp['family_cap_scope'] === 'tournament' ) {
                $use_tourn_wide_cap = true;
                break;
            }
        }

        // Load full registration history once if promo checks are active on any offered profile
        $all_regs_for_promo = null;
        foreach ( $t_profiles as $tp ) {
            if ( ! empty( $tp['promo_enabled'] ) && function_exists( 'sywat_get_all_registrations' ) ) {
                $all_regs_for_promo = sywat_get_all_registrations();
                break;
            }
        }

        // Pass 1: Duplicate/capacity triage, Promo eligibility check, and active-only group stats
        $triaged_archers     = array();
        $active_group_stats  = array();
        $skipped_duplicates  = array();
        $skipped_full        = array();
        $claimed_promo_names = array();
        $batch_seen_pairs    = array();

        foreach ( $valid_entries as $archer ) {
            $l_idx      = $archer['line_idx'];
            $dist       = $archer['distance'];
            $name       = $archer['name'];
            $name_low   = strtolower( trim( $name ) );
            $round_slug = $archer['pricing_profile'];
            $key        = $l_idx . '___' . $dist;
            $pair_key   = $l_idx . '___' . $name_low;
            $line_data  = isset( $t_lines[ $l_idx ] ) ? $t_lines[ $l_idx ] : array();
            $line_name  = $has_line_label_fn
                ? sywat_resolve_tournament_line_label( $line_data, $l_idx )
                : ( isset( $line_data['name'] ) ? $line_data['name'] : ( is_numeric( $l_idx ) ? 'Line ' . ( (int) $l_idx + 1 ) : $l_idx ) );

            $is_duplicate = isset( $existing_dup_map[ $pair_key ] ) || isset( $batch_seen_pairs[ $pair_key ] );
            if ( $is_duplicate ) {
                $skipped_duplicates[] = $name . ' (' . $line_name . ')';
                continue;
            }
            $batch_seen_pairs[ $pair_key ] = true;

            $is_waitlist = false;
            if ( $waitlist_mode === 'all' ) {
                $is_waitlist = true;
            } elseif ( isset( $remaining_open_slots[ $key ] ) && $remaining_open_slots[ $key ] <= 0 ) {
                if ( $waitlist_mode === 'remainder' ) {
                    $is_waitlist = true;
                } else {
                    $skipped_full[] = $name . ' (' . $line_name . ' - ' . $dist . ')';
                    continue;
                }
            }

            if ( ! $is_waitlist && isset( $remaining_open_slots[ $key ] ) ) {
                $remaining_open_slots[ $key ]--;
            }

            // Evaluate First-Time / Lapsed Shooter Promotional Free Entry eligibility (at most once per shooter)
            $is_promo_free = false;
            if ( ! $is_waitlist && ! isset( $claimed_promo_names[ $name_low ] ) && function_exists( 'sywat_check_archer_promo_eligibility' ) ) {
                $promo_check = sywat_check_archer_promo_eligibility(
                    $name,
                    ! empty( $t_data ) ? $t_data : $tourn_name,
                    $round_slug,
                    is_array( $all_regs_for_promo ) ? $all_regs_for_promo : $registrations
                );
                if ( ! empty( $promo_check['eligible'] ) ) {
                    $is_promo_free                    = true;
                    $claimed_promo_names[ $name_low ] = true;
                }
            }

            $fee_div_hint = ( $archer['division'] !== '' )
                ? $archer['division']
                : ( $dist === 'Cub' ? 'Cub (8 - 11)' : '' );

            $ind_rate = $is_promo_free
                ? 0.00
                : ( function_exists( 'sywat_get_archer_individual_fee' )
                    ? sywat_get_archer_individual_fee( ! empty( $t_data ) ? $t_data : $tourn_name, $fee_div_hint, $dist, '', $round_slug, $archer['paid_tier'] )
                    : ( $archer['paid_tier'] === 'Youth' ? 25.00 : 30.00 ) );

            $prof_rec = function_exists( 'sywat_resolve_active_pricing_profile' )
                ? sywat_resolve_active_pricing_profile( ! empty( $t_data ) ? $t_data : $tourn_name, $round_slug )
                : $default_prof;

            $cap_bucket = $use_tourn_wide_cap ? 'tournament' : (string) $l_idx;

            if ( ! $is_waitlist && ! $is_promo_free ) {
                if ( ! isset( $active_group_stats[ $cap_bucket ] ) ) {
                    $active_group_stats[ $cap_bucket ] = array(
                        'count'       => 0,
                        'raw_sum'     => 0.00,
                        'cap_enabled' => false,
                        'cap_amount'  => 0.00,
                        'min_archers' => 99,
                    );
                }
                $active_group_stats[ $cap_bucket ]['count']++;
                $active_group_stats[ $cap_bucket ]['raw_sum'] += $ind_rate;

                if ( is_array( $prof_rec ) && ! empty( $prof_rec['family_cap_enabled'] ) ) {
                    $active_group_stats[ $cap_bucket ]['cap_enabled'] = true;
                    $p_cap = isset( $prof_rec['family_cap_amount'] ) ? floatval( $prof_rec['family_cap_amount'] ) : 75.00;
                    $p_min = isset( $prof_rec['family_min_archers'] ) ? max( 2, intval( $prof_rec['family_min_archers'] ) ) : 3;
                    if ( $p_cap > $active_group_stats[ $cap_bucket ]['cap_amount'] ) {
                        $active_group_stats[ $cap_bucket ]['cap_amount'] = $p_cap;
                    }
                    if ( $p_min < $active_group_stats[ $cap_bucket ]['min_archers'] ) {
                        $active_group_stats[ $cap_bucket ]['min_archers'] = $p_min;
                    }
                }
            }

            $archer['line_name']     = $line_name;
            $archer['is_waitlist']   = $is_waitlist;
            $archer['is_promo_free'] = $is_promo_free;
            $archer['ind_rate']      = $ind_rate;
            $archer['cap_bucket']    = $cap_bucket;
            $triaged_archers[]       = $archer;
        }

        // Pass 2: Assign fees, apply dynamic Household Family Cap, and persist
        $added_count             = 0;
        $waitlisted_count        = 0;
        $promo_applied_names     = array();
        $family_primary_assigned = array();
        $remaining_payment       = $amount_paid;
        $created_timestamp       = current_time( 'mysql' );
        $shared_tourn_fam_id     = 'fam_' . $t_slug . '_' . sanitize_title( $family_base_name );
        $has_persist_fn          = function_exists( 'sywat_waitlist_persist_record' );

        foreach ( $triaged_archers as $archer ) {
            $l_idx         = $archer['line_idx'];
            $dist          = $archer['distance'];
            $name          = $archer['name'];
            $line_name     = $archer['line_name'];
            $round_slug    = $archer['pricing_profile'];
            $is_waitlist   = $archer['is_waitlist'];
            $is_promo_free = $archer['is_promo_free'];
            $ind_rate      = $archer['ind_rate'];
            $cap_bucket    = $archer['cap_bucket'];

            $bucket_stat = isset( $active_group_stats[ $cap_bucket ] ) ? $active_group_stats[ $cap_bucket ] : null;
            $min_needed  = ( is_array( $bucket_stat ) && $bucket_stat['min_archers'] !== 99 ) ? $bucket_stat['min_archers'] : 3;

            $is_family_eligible = (
                ! $is_waitlist &&
                ! $is_promo_free &&
                is_array( $bucket_stat ) &&
                ! empty( $bucket_stat['cap_enabled'] ) &&
                $bucket_stat['count'] >= $min_needed &&
                $bucket_stat['raw_sum'] > $bucket_stat['cap_amount']
            );

            if ( $is_waitlist ) {
                $reg_status      = 'Waitlist';
                $paid_status     = 'Waitlist';
                $initial_fee     = 0.00;
                $due_amount      = 0.00;
                $paid_for_archer = 0.00;
            } elseif ( $is_promo_free ) {
                $reg_status            = 'Pre-Reg';
                $paid_status           = 'Promo (Free)';
                $initial_fee           = 0.00;
                $due_amount            = 0.00;
                $paid_for_archer       = 0.00;
                $promo_applied_names[] = $name;
            } else {
                $reg_status = 'Pre-Reg';
                if ( $is_family_eligible ) {
                    $paid_status = 'Family';
                    if ( empty( $family_primary_assigned[ $cap_bucket ] ) ) {
                        $initial_fee                            = floatval( $bucket_stat['cap_amount'] );
                        $family_primary_assigned[ $cap_bucket ] = true;
                    } else {
                        $initial_fee = 0.00;
                    }
                } else {
                    $paid_status = $archer['paid_tier'];
                    $initial_fee = $ind_rate;
                }

                if ( $remaining_payment >= $initial_fee ) {
                    $paid_for_archer   = $initial_fee;
                    $due_amount        = 0.00;
                    $remaining_payment = round( $remaining_payment - $initial_fee, 2 );
                } else {
                    $paid_for_archer   = $remaining_payment;
                    $due_amount        = round( $initial_fee - $remaining_payment, 2 );
                    $remaining_payment = 0.00;
                }
            }

            $fam_id_val = $use_tourn_wide_cap
                ? $shared_tourn_fam_id
                : ( 'fam_' . $t_slug . '_l' . sanitize_title( $l_idx ) . '_' . sanitize_title( $family_base_name ) );

            $new_reg = array(
                'id'              => uniqid( 'reg_' ),
                'tournament'      => $tourn_name,
                'line_index'      => $l_idx,
                'line_name'       => $line_name,
                'name'            => $name,
                'phone'           => $archer['phone'],
                'email'           => $archer['email'],
                'distance'        => $dist,
                'division'        => $archer['division'],
                'pricing_profile' => $round_slug,
                'is_promo_free'   => $is_promo_free,
                'gender'          => $archer['gender'],
                'style'           => $archer['style'],
                'paid'            => $paid_status,
                'rate_type'       => $archer['paid_tier'],
                'family_id'       => $fam_id_val,
                'family_name'     => $family_base_name,
                'amount_paid'     => $paid_for_archer,
                'balance_due'     => $due_amount,
                'status'          => $reg_status,
                'created_at'      => $created_timestamp,
            );

            if ( $has_persist_fn ) {
                sywat_waitlist_persist_record( $new_reg, $registrations );
            } else {
                update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                $master_regs = get_option( 'sywat_registrations_' . $t_slug, array() );
                if ( ! is_array( $master_regs ) ) {
                    $master_regs = array();
                }
                $master_regs[ $new_reg['id'] ] = $new_reg;
                update_option( 'sywat_registrations_' . $t_slug, $master_regs, false );
                $registrations[] = $new_reg;
            }

            if ( $is_waitlist ) {
                $waitlisted_count++;
            } else {
                $added_count++;
            }
        }

        if ( ! empty( $group_phone ) && ( $added_count + $waitlisted_count ) > 0 ) {
            $phone_cache = get_option( 'sywat_shooter_phones_cache', array() );
            if ( ! is_array( $phone_cache ) ) {
                $phone_cache = array();
            }
            foreach ( $valid_entries as $archer ) {
                if ( ! empty( $archer['name'] ) ) {
                    $norm_name                 = strtolower( trim( $archer['name'] ) );
                    $phone_cache[ $norm_name ] = $group_phone;
                }
            }
            update_option( 'sywat_shooter_phones_cache', $phone_cache, false );
        }

        $GLOBALS['sywat_last_added_count']   = $added_count;
        $GLOBALS['sywat_skipped_duplicates'] = $skipped_duplicates;
        $GLOBALS['sywat_skipped_full']       = $skipped_full;

        $msg = '';
        if ( $added_count > 0 ) {
            $promo_suffix = ! empty( $promo_applied_names )
                ? ' (First-Time/Lapsed Promo $0.00 applied to: ' . esc_html( implode( ', ', $promo_applied_names ) ) . ')'
                : '';
            $msg .= '<div class="sywat-alert sywat-alert-success">Successfully registered ' . intval( $added_count ) . ' entry/entries under \'' . esc_html( $family_base_name ) . '\'' . $promo_suffix . '.</div>';
        }
        if ( $waitlisted_count > 0 ) {
            $msg .= '<div class="sywat-alert sywat-alert-warning">🕒 Added ' . intval( $waitlisted_count ) . ' entry/entries to the WAITLIST under \'' . esc_html( $family_base_name ) . '\' ($0 Balance Due).</div>';
        }
        if ( ! empty( $skipped_duplicates ) ) {
            $msg .= '<div class="sywat-alert sywat-alert-danger">⚠️ DUPLICATE BLOCKED: ' . implode( ', ', array_map( 'esc_html', $skipped_duplicates ) ) . ' — already registered on the selected shooting line(s)!</div>';
        }
        if ( ! empty( $skipped_full ) ) {
            $msg .= '<div class="sywat-alert sywat-alert-danger">⚠️ LINE FULL BLOCKED: ' . implode( ', ', array_map( 'esc_html', $skipped_full ) ) . ' — target shooting line is at maximum capacity!</div>';
        }

        return $msg;
    }
}
