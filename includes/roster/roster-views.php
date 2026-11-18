<?php
/**
 * Sub-Module: Archer Roster Views Renderer
 * Path: includes/roster/roster-views.php
 * Description: Renders the Registered Archer Roster modal with alphabetical active shooters, Type / Rate indicators, multi-round format & promotional entry indicators, and a direct header trigger to open the dedicated Waitlist Management Modal (#sywat_waitlist_modal) without scrolling.
 * Author: SYWAT Archery
 * Version: 7.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper: Resolve clean Manage Tournaments base URL using central page registry.
 *
 * @return string URL to Manage Tournaments screen.
 */
if ( ! function_exists( 'sywat_roster_manage_base_url' ) ) {
    function sywat_roster_manage_base_url() {
        if ( function_exists( 'sywat_page_url' ) ) {
            return sywat_page_url( 'manage_tournaments' );
        }
        return get_permalink();
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
            'filter_line_idx' => $filter_line_idx,
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

        // Build Tournament- & Line-Scoped Family Groups array for JS dynamic dropdowns & labels
        if ( function_exists( 'sywat_waitlist_build_family_labels_by_line' ) ) {
            $existing_families_by_line = sywat_waitlist_build_family_labels_by_line( $selected_tournament, $available_lines, $registrations );
        } else {
            $existing_families_by_line = array();
            $family_members_by_id      = array();

            foreach ( $registrations as $r ) {
                if ( ! isset( $r['tournament'] ) || strcasecmp( trim( (string) $r['tournament'] ), trim( (string) $selected_tournament ) ) !== 0 ) {
                    continue;
                }

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

        // Filter registrations for this tournament & line filter
        $filtered_registrations = array_filter( $registrations, function( $reg ) use ( $selected_tournament, $filter_line_idx, $available_lines ) {
            if ( ! isset( $reg['tournament'] ) || strcasecmp( trim( (string) $reg['tournament'] ), trim( (string) $selected_tournament ) ) !== 0 ) {
                return false;
            }
            if ( (string) $filter_line_idx !== 'all' && (string) $filter_line_idx !== '' ) {
                $match_idx  = isset( $reg['line_index'] ) && (string) $reg['line_index'] === (string) $filter_line_idx;
                $match_name = isset( $available_lines[ $filter_line_idx ]['name'] ) && isset( $reg['line_name'] ) && strcasecmp( trim( $reg['line_name'] ), trim( $available_lines[ $filter_line_idx ]['name'] ) ) === 0;
                if ( ! $match_idx && ! $match_name ) {
                    return false;
                }
            }
            return true;
        } );

        // Resolve Target Archer record for the Edit Modal (supports both active & waitlisted records)
        $target_archer = null;
        if ( is_array( $modal_edit_archer ) ) {
            $target_archer = $modal_edit_archer;
        } elseif ( ! empty( $modal_edit_archer ) ) {
            foreach ( $filtered_registrations as $reg ) {
                if ( isset( $reg['id'] ) && (string) $reg['id'] === (string) $modal_edit_archer ) {
                    $target_archer = $reg;
                    break;
                }
            }
        }

        // Separate Active Shooters (sorted A–Z) from Waitlisted Archers (managed via dedicated Waitlist Modal)
        $active_registrations  = array();
        $filtered_waitlist_cnt = 0;

        foreach ( $filtered_registrations as $reg ) {
            $status = isset( $reg['status'] ) ? trim( (string) $reg['status'] ) : '';
            if ( strcasecmp( $status, 'Waitlist' ) === 0 ) {
                $filtered_waitlist_cnt++;
                continue;
            }
            if ( strcasecmp( $status, 'Cancelled' ) === 0 ) {
                continue;
            }
            $active_registrations[] = $reg;
        }

        usort( $active_registrations, function( $a, $b ) {
            return strcasecmp( isset( $a['name'] ) ? trim( $a['name'] ) : '', isset( $b['name'] ) ? trim( $b['name'] ) : '' );
        } );

        $waitlist_target_line = ( (string) $filter_line_idx !== '' ) ? (string) $filter_line_idx : 'all';
        $waitlist_modal_url   = ( $filtered_waitlist_cnt > 0 && function_exists( 'sywat_waitlist_get_modal_url' ) )
            ? sywat_waitlist_get_modal_url( $selected_tournament, $waitlist_target_line, $close_target_url )
            : '';

        // Enqueue Roster Client Scripts
        if ( function_exists( 'sywat_render_roster_scripts' ) ) {
            sywat_render_roster_scripts( $existing_families_by_line, $selected_tournament );
        }
        ?>

        <div class="sywat-modal-backdrop active no-print" id="sywat_roster_modal" data-sywat-close-url="<?php echo esc_url( $close_target_url ); ?>">
            <div class="sywat-modal-content sywat-modal-xl">

                <!-- Standard Pinned Header -->
                <div class="sywat-modal-header">
                    <div>
                        <h3>👥 Registered Archer Roster</h3>
                        <div class="sywat-subtitle">(<?php echo esc_html( $selected_tournament ); ?>)</div>
                    </div>
                    <button type="button" class="sywat-modal-close" aria-label="Close Roster Modal" data-sywat-action="close-roster-modal" data-close-url="<?php echo esc_url( $close_target_url ); ?>">&times;</button>
                </div>

                <!-- Scrollable Modal Body -->
                <div class="sywat-modal-body">

                    <!-- Shaded Filter & Search Controls Form -->
                    <div class="sywat-roster-filter-bar">
                        <form method="get" action="<?php echo esc_url( $manage_base_url ); ?>" class="sywat-roster-filter-form">
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

                            <!-- Filter by Line Dropdown (Formatted Schedule Titles) -->
                            <div class="sywat-filter-field">
                                <label for="filter_line_idx">Filter by Line</label>
                                <select name="filter_line_idx" id="filter_line_idx" data-sywat-action="filter-roster-line">
                                    <option value="all" <?php selected( $filter_line_idx, 'all' ); ?>>(All Lines)</option>
                                    <?php foreach ( $available_lines as $idx => $line ) : ?>
                                        <?php $line_disp_name = sywat_roster_format_line_display( $line, $idx ); ?>
                                        <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string) $filter_line_idx, (string) $idx ); ?>>
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

                        <?php if ( (string) $filter_line_idx !== 'all' && (string) $filter_line_idx !== '' && ! empty( $active_registrations ) ) : ?>
                            <div class="sywat-filter-field">
                                <form method="post" action="<?php echo esc_url( $roster_post_url ); ?>" data-sywat-confirm="Are you sure you want to clear ALL active archers from this line?">
                                    <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                    <input type="hidden" name="sywat_action" value="purge_line">
                                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                    <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
                                    <input type="hidden" name="modal_action" value="manage">
                                    <input type="hidden" name="redirect_action" value="manage">
                                    <?php if ( ! empty( $return_to ) ) : ?>
                                        <input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete">Clear Line Roster</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Active Registered Archers Table (Sorted Alphabetically) -->
                    <div class="sywat-table-header-bar sywat-modal-section-header">
                        <h2>Active Roster (<?php echo intval( count( $active_registrations ) ); ?>)</h2>
                        <?php if ( $filtered_waitlist_cnt > 0 && ! empty( $waitlist_modal_url ) ) : ?>
                            <div class="sywat-table-header-controls">
                                <a href="<?php echo esc_url( $waitlist_modal_url ); ?>" class="sywat-btn sywat-btn-warning sywat-waitlist-trigger-btn" title="Open Dedicated Waitlist Management Window">
                                    <?php echo function_exists( 'sywat_waitlist_clock_svg' ) ? sywat_waitlist_clock_svg() : ''; ?>
                                    <span>View Waitlist (<?php echo intval( $filtered_waitlist_cnt ); ?>)</span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ( empty( $active_registrations ) ) : ?>
                        <p class="sywat-label-subtext">No active archers found matching your filter criteria.</p>
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
                                            $reg_id      = isset( $reg['id'] ) ? $reg['id'] : '';
                                            $badge_type  = ! empty( $reg['is_walkin'] ) || ( isset( $reg['type'] ) && $reg['type'] === 'Walk-In' ) ? 'Walk-In' : 'Pre-Reg';
                                            $badge_class = ( $badge_type === 'Walk-In' ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg';

                                            $paid_status   = isset( $reg['paid'] ) ? trim( (string) $reg['paid'] ) : '';
                                            $is_promo_free = ! empty( $reg['is_promo_free'] ) || strcasecmp( $paid_status, 'Promo (Free)' ) === 0 || stripos( $paid_status, 'Promo' ) === 0;
                                            $lidx          = function_exists( 'sywat_roster_resolve_line_idx' ) ? sywat_roster_resolve_line_idx( $reg, $available_lines ) : ( isset( $reg['line_index'] ) ? (string) $reg['line_index'] : '0' );

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

                                            $edit_archer_args = array(
                                                'tab'             => 'manage',
                                                'view_tournament' => $selected_tournament,
                                                'modal_action'    => 'manage',
                                                'edit_archer'     => $reg_id,
                                                'filter_line_idx' => $filter_line_idx,
                                            );
                                            if ( ! empty( $return_to ) ) {
                                                $edit_archer_args['return_to'] = $return_to;
                                            }
                                            $edit_archer_url = add_query_arg( $edit_archer_args, $manage_base_url );
                                        ?>
                                        <tr class="sywat-manage-row" data-name="<?php echo esc_attr( strtolower( $reg['name'] ) ); ?>">
                                            <td>
                                                <strong><?php echo esc_html( $reg['name'] ); ?></strong>
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
                                                    <!-- Edit Button Link -->
                                                    <a href="<?php echo esc_url( $edit_archer_url ); ?>" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit">Edit</a>

                                                    <!-- Remove Form -->
                                                    <form method="post" action="<?php echo esc_url( $roster_post_url ); ?>" data-sywat-confirm="<?php echo esc_attr( 'Are you sure you want to remove ' . $reg['name'] . '?' ); ?>">
                                                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                                                        <input type="hidden" name="sywat_action" value="delete_shooter">
                                                        <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                        <input type="hidden" name="modal_action" value="manage">
                                                        <input type="hidden" name="filter_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
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
                    <?php endif; ?>
                </div>

                <!-- Standard Pinned Footer -->
                <div class="sywat-modal-footer">
                    <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="close-roster-modal" data-close-url="<?php echo esc_url( $close_target_url ); ?>">Close Roster</button>
                </div>
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
