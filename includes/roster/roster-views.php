<?php
/**
 * Sub-Module: Archer Roster Views Renderer
 * Path: includes/roster/roster-views.php
 * Description: Renders the Registered Archer Roster workspace inside the delegated modal container with single-pass tournament pre-scoping (preventing O(N) scans across archived historical tournaments), client-side line & search filtering (eliminating page-reload flicker when switching shooting lines), serialized per-row archer JSON payloads for instantaneous Edit Archer modal opening without HTTP reloads, conditional line-scoped family map construction, surname-sorted active shooters formatted as "LastName, FirstName", dual-format live search indexing ("Last, First" and "First Last"), Type / Rate indicators, multi-round format & promotional entry indicators, and a direct header trigger to open the dedicated Waitlist Management Modal (#sywat_waitlist_modal).
 * Compliance: Pure view layer — zero output at include time, zero inline styles, zero inline JS event handlers, O(N) tournament-scoped filtering, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper: Resolve clean Manage Tournaments base URL using central page registry,
 * preserving active filter_season when present.
 *
 * @return string URL to Manage Tournaments screen.
 */
if ( ! function_exists( 'sywat_roster_manage_base_url' ) ) {
    function sywat_roster_manage_base_url() {
        $base_url = function_exists( 'sywat_page_url' )
            ? sywat_page_url( 'manage_tournaments' )
            : get_permalink();

        if ( ! empty( $_REQUEST['filter_season'] ) && ! empty( $base_url ) ) {
            $base_url = add_query_arg(
                'filter_season',
                sanitize_text_field( wp_unslash( $_REQUEST['filter_season'] ) ),
                $base_url
            );
        }

        return $base_url;
    }
}

/**
 * Helper: Resolve formatted shooting line display label.
 *
 * @param array      $line Line data array.
 * @param string|int $idx  Line index.
 * @return string          Normalized line label.
 */
if ( ! function_exists( 'sywat_roster_format_line_display' ) ) {
    function sywat_roster_format_line_display( $line, $idx ) {
        if ( function_exists( 'sywat_waitlist_format_line_label' ) ) {
            return sywat_waitlist_format_line_label( $line, $idx );
        }

        $line_disp_name = '';
        if ( is_array( $line ) ) {
            if ( ! empty( $line['name'] ) ) {
                $line_disp_name = $line['name'];
            } else {
                $d_str          = isset( $line['day'] ) ? $line['day'] : '';
                $t_str          = isset( $line['time'] ) ? $line['time'] : ( isset( $line['start_time'] ) ? $line['start_time'] : '' );
                $line_disp_name = trim( $d_str . ' ' . $t_str );
            }
        }

        if ( empty( $line_disp_name ) ) {
            $line_disp_name = 'Line ' . ( (int) $idx + 1 );
        }

        $line_disp_name = preg_replace( '/\b6(?::00)?\s*AM\b/i', '6PM', $line_disp_name );
        $line_disp_name = preg_replace( '/\b6:30\s*AM\b/i', '6:30PM', $line_disp_name );
        $line_disp_name = preg_replace( '/(\d{1,2}):00\s*(AM|PM)/i', '$1$2', $line_disp_name );

        return $line_disp_name;
    }
}

/**
 * Component Renderer: Archer Roster Management Table & Direct Waitlist Modal Trigger
 *
 * @param string $selected_tournament Active tournament name.
 * @param array  $available_lines     Configured shooting lines for the tournament.
 * @param array  $registrations       Master registrations list.
 * @param string $filter_line_idx     Currently filtered line index or 'all'.
 * @param string $search_query        Initial search query string.
 * @param mixed  $modal_edit_archer   Target archer ID or record array for Edit modal.
 * @param array  $tournaments         Optional tournaments configuration array.
 * @return void
 */
if ( ! function_exists( 'sywat_render_roster_modal' ) ) {
    function sywat_render_roster_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $search_query, $modal_edit_archer = '', $tournaments = array() ) {

        $manage_base_url = sywat_roster_manage_base_url();
        $return_to       = isset( $_REQUEST['return_to'] ) ? sanitize_key( wp_unslash( $_REQUEST['return_to'] ) ) : '';

        $close_target_url = $manage_base_url;
        if ( $return_to === 'register' && function_exists( 'sywat_page_url' ) ) {
            $close_target_url = add_query_arg(
                array(
                    'selected_tournament' => $selected_tournament,
                ),
                sywat_page_url( 'register' )
            );
        }

        $roster_post_args = array(
            'tab'             => 'manage',
            'view_tournament' => $selected_tournament,
            'modal_action'    => 'manage',
            'filter_line_idx' => $filter_line_idx !== '' ? $filter_line_idx : 'all',
        );
        if ( ! empty( $return_to ) ) {
            $roster_post_args['return_to'] = $return_to;
        }
        $roster_post_url = add_query_arg( $roster_post_args, $manage_base_url );

        // Resolve offered Pricing & Round Profiles for multi-round display badges
        $t_data         = ( is_array( $tournaments ) && isset( $tournaments[ $selected_tournament ] ) )
            ? $tournaments[ $selected_tournament ]
            : $selected_tournament;
        $t_profiles     = function_exists( 'sywat_get_tournament_pricing_profiles' )
            ? sywat_get_tournament_pricing_profiles( $t_data )
            : array();
        $is_multi_round = count( $t_profiles ) > 1;

        // Single-pass pre-scope to active tournament so downstream family, line, and edit lookups never scan historical events
        $tournament_registrations = array();
        $has_family_entries       = false;
        $target_tourn_norm        = trim( (string) $selected_tournament );

        if ( is_array( $registrations ) && $target_tourn_norm !== '' ) {
            foreach ( $registrations as $r ) {
                if ( ! is_array( $r ) || ! isset( $r['tournament'] ) ) {
                    continue;
                }
                if ( strcasecmp( trim( (string) $r['tournament'] ), $target_tourn_norm ) !== 0 ) {
                    continue;
                }
                $tournament_registrations[] = $r;

                if ( ! $has_family_entries ) {
                    $is_fam_row = ( isset( $r['paid'] ) && strcasecmp( trim( (string) $r['paid'] ), 'Family' ) === 0 )
                               || ( ! empty( $r['rate_type'] ) && stripos( (string) $r['rate_type'], 'family' ) !== false )
                               || ! empty( $r['family_id'] );
                    if ( $is_fam_row ) {
                        $has_family_entries = true;
                    }
                }
            }
        }

        // Build Tournament- & Line-Scoped Family Groups array only when family entries exist on this tournament
        $existing_families_by_line = array();
        if ( $has_family_entries ) {
            if ( function_exists( 'sywat_waitlist_build_family_labels_by_line' ) ) {
                $existing_families_by_line = sywat_waitlist_build_family_labels_by_line( $selected_tournament, $available_lines, $tournament_registrations );
            } else {
                $family_members_by_id = array();

                foreach ( $tournament_registrations as $r ) {
                    $fid = ! empty( $r['family_id'] ) ? trim( (string) $r['family_id'] ) : '';
                    if ( empty( $fid ) ) {
                        continue;
                    }

                    $is_fam = ( isset( $r['paid'] ) && strcasecmp( $r['paid'], 'Family' ) === 0 )
                           || ( ! empty( $r['rate_type'] ) && stripos( $r['rate_type'], 'family' ) !== false );

                    if ( ! $is_fam ) {
                        continue;
                    }

                    $resolved_l_idx = function_exists( 'sywat_roster_resolve_line_idx' )
                        ? sywat_roster_resolve_line_idx( $r, $available_lines )
                        : ( isset( $r['line_index'] ) ? (string) $r['line_index'] : '0' );

                    if ( ! isset( $family_members_by_id[ $fid ] ) ) {
                        $family_members_by_id[ $fid ] = array(
                            'line_idx'    => $resolved_l_idx,
                            'family_name' => ! empty( $r['family_name'] ) ? trim( (string) $r['family_name'] ) : '',
                            'names'       => array(),
                        );
                    }
                    if ( ! empty( $r['name'] ) ) {
                        $family_members_by_id[ $fid ]['names'][] = trim( $r['name'] );
                    }
                }

                foreach ( $family_members_by_id as $fid => $fam_info ) {
                    $l_idx     = $fam_info['line_idx'];
                    $raw_fname = $fam_info['family_name'];
                    $names     = $fam_info['names'];

                    if ( ! empty( $raw_fname ) && stripos( $raw_fname, 'fam_' ) === false && stripos( $raw_fname, 'Booking' ) === false && strlen( $raw_fname ) > 2 ) {
                        $label = $raw_fname;
                    } elseif ( ! empty( $names ) ) {
                        if ( count( $names ) === 1 ) {
                            $label = $names[0] . ' Group';
                        } elseif ( count( $names ) <= 3 ) {
                            $last_names = array_map( function( $n ) {
                                $p = explode( ' ', trim( $n ) );
                                return end( $p );
                            }, $names );
                            $label = implode( ' / ', array_unique( $last_names ) ) . ' Group';
                        } else {
                            $p     = explode( ' ', trim( $names[0] ) );
                            $label = end( $p ) . ' Family Group (' . count( $names ) . ' Archers)';
                        }
                    } else {
                        $label = 'Family Group (' . substr( $fid, -6 ) . ')';
                    }

                    if ( ! isset( $existing_families_by_line[ $l_idx ] ) ) {
                        $existing_families_by_line[ $l_idx ] = array();
                    }
                    $existing_families_by_line[ $l_idx ][ $fid ] = $label;
                }
            }
        }

        // Resolve Target Archer record if explicitly requested via URL param (backward compatibility)
        $target_archer = null;
        if ( is_array( $modal_edit_archer ) ) {
            $target_archer = $modal_edit_archer;
        } elseif ( ! empty( $modal_edit_archer ) ) {
            foreach ( $tournament_registrations as $reg ) {
                if ( isset( $reg['id'] ) && (string) $reg['id'] === (string) $modal_edit_archer ) {
                    $target_archer = $reg;
                    break;
                }
            }
        }

        // Separate ALL tournament Active Shooters (sorted by Surname "LastName, FirstName") from Waitlisted Archers
        // so switching shooting lines filters instantaneously in JS with zero page reloads.
        $has_lf_formatter     = function_exists( 'sywat_format_name_last_first' );
        $active_registrations = array();
        $waitlist_counts      = array( 'all' => 0 );
        $active_line_counts   = array( 'all' => 0 );

        foreach ( $tournament_registrations as $reg ) {
            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }

            $lidx = function_exists( 'sywat_roster_resolve_line_idx' )
                ? sywat_roster_resolve_line_idx( $reg, $available_lines )
                : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

            if ( strcasecmp( $status, 'Waitlist' ) === 0 ) {
                $waitlist_counts['all']++;
                if ( ! isset( $waitlist_counts[ $lidx ] ) ) {
                    $waitlist_counts[ $lidx ] = 0;
                }
                $waitlist_counts[ $lidx ]++;
                continue;
            }

            $raw_name               = isset( $reg['name'] ) ? trim( (string) $reg['name'] ) : '';
            $reg['display_name']    = $has_lf_formatter ? sywat_format_name_last_first( $raw_name ) : $raw_name;
            $reg['_resolved_lidx']  = $lidx;
            $active_registrations[] = $reg;

            $active_line_counts['all']++;
            if ( ! isset( $active_line_counts[ $lidx ] ) ) {
                $active_line_counts[ $lidx ] = 0;
            }
            $active_line_counts[ $lidx ]++;
        }

        usort( $active_registrations, function( $a, $b ) {
            $disp_a = isset( $a['display_name'] ) ? trim( (string) $a['display_name'] ) : ( isset( $a['name'] ) ? trim( (string) $a['name'] ) : '' );
            $disp_b = isset( $b['display_name'] ) ? trim( (string) $b['display_name'] ) : ( isset( $b['name'] ) ? trim( (string) $b['name'] ) : '' );
            return strcasecmp( $disp_a, $disp_b );
        } );

        $active_filter_line    = ( (string) $filter_line_idx !== '' ) ? (string) $filter_line_idx : 'all';
        $is_specific_line      = ( $active_filter_line !== 'all' );
        $initial_active_count  = isset( $active_line_counts[ $active_filter_line ] ) ? intval( $active_line_counts[ $active_filter_line ] ) : 0;
        $initial_waitlist_cnt  = isset( $waitlist_counts[ $active_filter_line ] ) ? intval( $waitlist_counts[ $active_filter_line ] ) : 0;
        $waitlist_base_url     = function_exists( 'sywat_waitlist_get_modal_url' )
            ? sywat_waitlist_get_modal_url( $selected_tournament, $active_filter_line, $close_target_url )
            : '';

        // Enqueue Roster Client Scripts
        if ( function_exists( 'sywat_render_roster_scripts' ) ) {
            sywat_render_roster_scripts( $existing_families_by_line, $selected_tournament );
        }
        ?>

        <div class="sywat-roster-workspace no-print"
             id="sywat_roster_modal"
             data-sywat-close-url="<?php echo esc_url( $close_target_url ); ?>"
             data-return-to="<?php echo esc_attr( $return_to ); ?>"
             data-selected-tournament="<?php echo esc_attr( $selected_tournament ); ?>">

            <!-- Shaded Filter & Search Controls Bar (Client-Side Instant Filtering) -->
            <div class="sywat-roster-filter-bar">
                <form method="get" action="<?php echo esc_url( $manage_base_url ); ?>" class="sywat-roster-filter-form" id="sywat_roster_filter_form">
                    <input type="hidden" name="tab" value="manage">
                    <input type="hidden" name="modal_action" value="manage">
                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                    <?php if ( ! empty( $return_to ) ) : ?>
                        <input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
                    <?php endif; ?>

                    <!-- Read-Only Active Tournament Field -->
                    <div class="sywat-filter-field">
                        <label for="sywat_active_tournament_display">Active Tournament</label>
                        <input type="text" id="sywat_active_tournament_display" value="<?php echo esc_attr( $selected_tournament ); ?>" readonly class="sywat-input-disabled-calc">
                    </div>

                    <!-- Filter by Line Dropdown (Instant Client-Side Line Filter) -->
                    <div class="sywat-filter-field">
                        <label for="filter_line_idx">Filter by Line</label>
                        <select name="filter_line_idx" id="filter_line_idx" data-sywat-action="filter-roster-line">
                            <option value="all" <?php selected( $active_filter_line, 'all' ); ?>>(All Lines)</option>
                            <?php foreach ( $available_lines as $idx => $line ) : ?>
                                <?php $line_disp_name = sywat_roster_format_line_display( $line, $idx ); ?>
                                <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( $active_filter_line, (string) $idx ); ?>>
                                    <?php echo esc_html( $line_disp_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Search Archer Name Input -->
                    <div class="sywat-filter-field">
                        <label for="search_shooter">Search Archer Name</label>
                        <div class="sywat-search-input-group">
                            <input type="text" name="search_shooter" id="search_shooter" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Type archer name..." autocomplete="off">
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" data-sywat-action="clear-roster-search">Clear</button>
                        </div>
                    </div>
                </form>

                <!-- Clear Line Roster Action (Shown when filtered to a specific line with active shooters) -->
                <div class="sywat-filter-field<?php echo ( $is_specific_line && $initial_active_count > 0 ) ? '' : ' sywat-hidden'; ?>" id="sywat_roster_purge_wrap">
                    <form method="post" action="<?php echo esc_url( $roster_post_url ); ?>" id="sywat_roster_purge_form" data-sywat-confirm="Are you sure you want to clear ALL active archers from this line?">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="purge_line">
                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                        <input type="hidden" name="target_line_idx" id="sywat_roster_purge_target_line" value="<?php echo esc_attr( $is_specific_line ? $active_filter_line : '' ); ?>">
                        <input type="hidden" name="filter_line_idx" id="sywat_roster_purge_filter_line" value="<?php echo esc_attr( $active_filter_line ); ?>">
                        <input type="hidden" name="modal_action" value="manage">
                        <input type="hidden" name="redirect_action" value="manage">
                        <?php if ( ! empty( $return_to ) ) : ?>
                            <input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
                        <?php endif; ?>
                        <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Clear Line Roster</button>
                    </form>
                </div>
            </div>

            <!-- Active Registered Archers Table (Sorted Alphabetically by Surname) -->
            <div class="sywat-table-header-bar sywat-modal-section-header">
                <h2>Active Roster (<span id="sywat_roster_active_count"><?php echo intval( $initial_active_count ); ?></span>)</h2>
                <?php if ( ! empty( $waitlist_base_url ) ) : ?>
                    <div class="sywat-table-header-controls<?php echo ( $initial_waitlist_cnt > 0 ) ? '' : ' sywat-hidden'; ?>"
                         id="sywat_roster_waitlist_wrap"
                         data-waitlist-counts="<?php echo esc_attr( wp_json_encode( $waitlist_counts ) ); ?>"
                         data-close-url="<?php echo esc_url( $close_target_url ); ?>">
                        <a href="<?php echo esc_url( $waitlist_base_url ); ?>"
                           id="sywat_roster_waitlist_link"
                           class="sywat-btn sywat-btn-warning sywat-waitlist-trigger-btn"
                           title="Open Dedicated Waitlist Management Window">
                            <?php echo function_exists( 'sywat_waitlist_clock_svg' ) ? sywat_waitlist_clock_svg() : ''; ?>
                            <span>View Waitlist (<span id="sywat_roster_waitlist_count"><?php echo intval( $initial_waitlist_cnt ); ?></span>)</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( empty( $active_registrations ) ) : ?>
                <p class="sywat-label-subtext">No active archers found for this tournament.</p>
            <?php else : ?>
                <div class="sywat-table-responsive">
                    <table class="sywat-table sywat-roster-table">
                        <thead>
                            <tr>
                                <th class="sywat-col-roster-name">Shooter Name</th>
                                <th class="sywat-col-roster-type sywat-col-center">Type / Rate</th>
                                <th class="sywat-col-roster-line">Line</th>
                                <th class="sywat-col-roster-dist sywat-col-center">Distance</th>
                                <th class="sywat-col-roster-action sywat-col-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $active_registrations as $reg ) : ?>
                                <?php
                                    $reg_id      = isset( $reg['id'] ) ? (string) $reg['id'] : '';
                                    $badge_type  = ! empty( $reg['is_walkin'] ) || ( isset( $reg['type'] ) && $reg['type'] === 'Walk-In' ) ? 'Walk-In' : 'Pre-Reg';
                                    $badge_class = ( $badge_type === 'Walk-In' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg';

                                    $disp_archer_name = ! empty( $reg['display_name'] )
                                        ? $reg['display_name']
                                        : ( $has_lf_formatter ? sywat_format_name_last_first( $reg['name'] ) : $reg['name'] );
                                    $search_tokens    = strtolower( trim( $disp_archer_name . ' ' . $reg['name'] ) );

                                    $paid_status   = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
                                    $is_promo_free = ! empty( $reg['is_promo_free'] ) || strcasecmp( $paid_status, 'Promo (Free)' ) === 0 || stripos( $paid_status, 'Promo' ) === 0;
                                    $lidx          = isset( $reg['_resolved_lidx'] )
                                        ? (string) $reg['_resolved_lidx']
                                        : ( function_exists( 'sywat_roster_resolve_line_idx' ) ? sywat_roster_resolve_line_idx( $reg, $available_lines ) : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' ) );

                                    $round_slug  = ! empty( $reg['pricing_profile'] ) ? sanitize_title( (string) $reg['pricing_profile'] ) : '';
                                    $round_label = '';
                                    if ( $is_multi_round && $round_slug !== '' ) {
                                        if ( isset( $t_profiles[ $round_slug ] ) ) {
                                            $round_label = $t_profiles[ $round_slug ]['label'];
                                        } elseif ( function_exists( 'sywat_find_pricing_profile' ) ) {
                                            $rp = sywat_find_pricing_profile( $round_slug );
                                            if ( is_array( $rp ) && ! empty( $rp['label'] ) ) {
                                                $round_label = $rp['label'];
                                            }
                                        }
                                    }

                                    $fam_label = '';
                                    if ( $paid_status === 'Family' && ! empty( $reg['family_id'] ) ) {
                                        if ( isset( $existing_families_by_line[ $lidx ][ $reg['family_id'] ] ) ) {
                                            $fam_label = $existing_families_by_line[ $lidx ][ $reg['family_id'] ];
                                        }
                                    }

                                    $line_disp = '';
                                    if ( isset( $available_lines[ $lidx ] ) ) {
                                        $line_disp = sywat_roster_format_line_display( $available_lines[ $lidx ], $lidx );
                                    }
                                    if ( empty( $line_disp ) ) {
                                        $line_disp = ! empty( $reg['line_name'] ) ? $reg['line_name'] : 'Line 1';
                                    }

                                    $raw_div = isset( $reg['division'] ) ? trim( (string) $reg['division'] ) : '';
                                    $norm_div = ( $raw_div !== '' && $raw_div !== '—' && function_exists( 'sywat_shooting_styles_normalize_division' ) )
                                        ? sywat_shooting_styles_normalize_division( $raw_div )
                                        : ( ( $raw_div !== '' && $raw_div !== '—' ) ? $raw_div : 'Adult (18 - 49)' );

                                    $raw_sty = isset( $reg['style'] ) ? trim( (string) $reg['style'] ) : '';
                                    $norm_sty = ( $raw_sty !== '' && $raw_sty !== '—' && function_exists( 'sywat_shooting_styles_normalize_style' ) )
                                        ? sywat_shooting_styles_normalize_style( $raw_sty )
                                        : ( ( $raw_sty !== '—' ) ? $raw_sty : '' );

                                    $raw_status  = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : 'Pre-Reg';
                                    $norm_status = ( strcasecmp( $raw_status, 'Waitlist' ) === 0 )
                                        ? 'Waitlist'
                                        : ( ( strcasecmp( $raw_status, 'Cancelled' ) === 0 ) ? 'Cancelled' : 'Pre-Reg' );

                                    $archer_payload = array(
                                        'id'              => $reg_id,
                                        'name'            => isset( $reg['name'] ) ? (string) $reg['name'] : '',
                                        'line_index'      => $lidx,
                                        'status'          => $norm_status,
                                        'division'        => $norm_div,
                                        'gender'          => isset( $reg['gender'] ) && $reg['gender'] !== '' ? (string) $reg['gender'] : 'Male',
                                        'style'           => $norm_sty,
                                        'paid'            => $paid_status,
                                        'is_promo_free'   => $is_promo_free ? 1 : 0,
                                        'family_id'       => isset( $reg['family_id'] ) ? trim( (string) $reg['family_id'] ) : '',
                                        'balance_due'     => isset( $reg['balance_due'] ) ? number_format( floatval( $reg['balance_due'] ), 2, '.', '' ) : '0.00',
                                        'pricing_profile' => $round_slug,
                                    );

                                    $is_row_hidden = ( $is_specific_line && $lidx !== $active_filter_line );
                                ?>
                                <tr class="sywat-manage-row<?php echo $is_row_hidden ? ' sywat-hidden' : ''; ?>"
                                    data-id="<?php echo esc_attr( $reg_id ); ?>"
                                    data-line-idx="<?php echo esc_attr( $lidx ); ?>"
                                    data-name="<?php echo esc_attr( $search_tokens ); ?>"
                                    data-archer="<?php echo esc_attr( wp_json_encode( $archer_payload ) ); ?>">
                                    <td>
                                        <strong><?php echo esc_html( $disp_archer_name ); ?></strong>
                                        <?php if ( $round_label !== '' ) : ?>
                                            <span class="sywat-badge sywat-badge-prereg"><?php echo esc_html( $round_label ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $fam_label ) ) : ?>
                                            <div>
                                                <span class="sywat-chip-family">🟢 <?php echo esc_html( $fam_label ); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sywat-col-center">
                                        <span class="sywat-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_type ); ?></span>
                                        <?php if ( $is_promo_free ) : ?>
                                            <br><span class="sywat-badge sywat-badge-open">Promo ($0.00)</span>
                                        <?php elseif ( $paid_status === 'Family' ) : ?>
                                            <br><span class="sywat-badge-family-cap">Family Cap</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $line_disp ); ?></td>
                                    <td class="sywat-col-center"><?php echo esc_html( isset( $reg['distance'] ) ? $reg['distance'] : '20yd' ); ?></td>
                                    <td class="sywat-col-roster-action sywat-col-center">
                                        <div class="sywat-modal-roster-actions">
                                            <!-- Instant Client-Side Edit Modal Trigger (Zero Page Reload) -->
                                            <button type="button" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit" data-sywat-action="open-edit-archer-modal">Edit</button>

                                            <!-- Remove Form -->
                                            <form method="post" action="<?php echo esc_url( $roster_post_url ); ?>" class="sywat-roster-row-form" data-sywat-confirm="<?php echo esc_attr( 'Are you sure you want to remove ' . $reg['name'] . '?' ); ?>">
                                                <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                <input type="hidden" name="sywat_action" value="delete_shooter">
                                                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="modal_action" value="manage">
                                                <input type="hidden" name="filter_line_idx" class="sywat-roster-hidden-line" value="<?php echo esc_attr( $active_filter_line ); ?>">
                                                <input type="hidden" name="registration_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                <?php if ( ! empty( $return_to ) ) : ?>
                                                    <input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Remove</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="sywat-label-subtext<?php echo ( $initial_active_count > 0 ) ? ' sywat-hidden' : ''; ?>" id="sywat_roster_empty_filter_msg">
                    No active archers found matching your filter criteria.
                </p>
            <?php endif; ?>

            <!-- Standard Pinned Footer -->
            <div class="sywat-modal-footer">
                <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-roster-modal" data-close-url="<?php echo esc_url( $close_target_url ); ?>">Close Roster</button>
            </div>
        </div>

        <!-- Render Edit Archer Modal Component -->
        <?php
        if ( function_exists( 'sywat_render_edit_archer_modal' ) ) {
            sywat_render_edit_archer_modal( $target_archer, $selected_tournament, $available_lines, $existing_families_by_line );
        }
        ?>
        <?php
    }
}
