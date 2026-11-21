<?php
/**
 * Sub-Module: Tournament Modals View Layer
 * Path: includes/tournaments/tournament-modals.php
 * Description: Renders the streamlined Add/Edit Tournament modal (with Season-filtered Pricing & Round Format selection, per-tournament Public Online Registration On/Off & lead-time overrides inherited from host shop defaults, External Online Registration Links, standardized 30-minute AM/PM shooting line dropdowns, shop-default line reset, and active-only shop event sequence counting), Bulk Schedule Import modals, tournament-scoped Roster/Invoice/Print modal container, and TFAA Sync Progress modal.
 * Compliance: Pure view layer — zero inline style attributes, zero embedded script blocks, omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 7.19.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the Add / Edit Tournament Modal
 *
 * @param array $data Central data bundle from data engine.
 */
if ( ! function_exists( 'sywat_render_add_edit_tournament_modal' ) ) {
    function sywat_render_add_edit_tournament_modal( $data ) {
        $tournaments        = $data['tournaments'];
        $can_manage_seasons = $data['can_manage_seasons'];
        $user_shop_id       = $data['user_shop_id'];
        $all_shops          = $data['all_shops'];
        $seasons            = $data['seasons'];

        $open_tourn_modal = isset( $_REQUEST['open_add_tournament'] ) || isset( $_REQUEST['edit_tournament_modal'] );
        $edit_t_target    = isset( $_REQUEST['edit_tournament_modal'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['edit_tournament_modal'] ) ) : '';
        $is_edit_mode     = ! empty( $edit_t_target );

        $edit_t_data = ( $is_edit_mode && isset( $tournaments[ $edit_t_target ] ) ) ? $tournaments[ $edit_t_target ] : null;

        // Build shop lookup mapping (canonical IDs, unprefixed slugs, and shop names) before resolving shop records
        $shop_lookup_map = array();
        if ( is_array( $all_shops ) ) {
            foreach ( $all_shops as $s_id => $s_data ) {
                $s_id_str   = (string) $s_id;
                $s_id_lower = strtolower( trim( $s_id_str ) );
                if ( $s_id_lower !== '' ) {
                    $shop_lookup_map[ $s_id_lower ] = $s_id_str;
                    if ( strpos( $s_id_lower, 'shop_' ) === 0 ) {
                        $unprefixed = substr( $s_id_lower, 5 );
                        if ( $unprefixed !== '' ) {
                            $shop_lookup_map[ $unprefixed ] = $s_id_str;
                        }
                    }
                }

                if ( is_array( $s_data ) ) {
                    $title = ! empty( $s_data['name'] ) ? $s_data['name'] : ( ! empty( $s_data['shop_name'] ) ? $s_data['shop_name'] : ( ! empty( $s_data['title'] ) ? $s_data['title'] : '' ) );
                    if ( ! empty( $title ) ) {
                        $norm_title = strtolower( trim( $title ) );
                        $slug_title = sanitize_title( $title );
                        $shop_lookup_map[ $norm_title ] = $s_id_str;
                        if ( $slug_title !== '' ) {
                            $shop_lookup_map[ $slug_title ]           = $s_id_str;
                            $shop_lookup_map[ 'shop_' . $slug_title ] = $s_id_str;
                        }
                    }
                }
            }
        }

        // Helper: Resolve any stored shop ID, unprefixed slug, or shop name to its canonical key in $all_shops
        $resolve_canonical_shop_id = function( $raw_shop_id, $t_record = null ) use ( $all_shops, $shop_lookup_map ) {
            $candidates = array();
            if ( ! empty( $raw_shop_id ) ) {
                $candidates[] = (string) $raw_shop_id;
            }
            if ( is_array( $t_record ) ) {
                foreach ( array( 'shop_id', 'shop_name', 'official_shop_name', 'location_display', 'location' ) as $field_key ) {
                    if ( ! empty( $t_record[ $field_key ] ) && is_string( $t_record[ $field_key ] ) ) {
                        $candidates[] = $t_record[ $field_key ];
                    }
                }
            }

            foreach ( $candidates as $cand ) {
                $trimmed = trim( $cand );
                if ( $trimmed === '' ) {
                    continue;
                }
                if ( isset( $all_shops[ $trimmed ] ) ) {
                    return $trimmed;
                }
                $lower = strtolower( $trimmed );
                if ( isset( $shop_lookup_map[ $lower ] ) ) {
                    return $shop_lookup_map[ $lower ];
                }
                $slug = sanitize_title( $trimmed );
                if ( $slug !== '' ) {
                    if ( isset( $shop_lookup_map[ $slug ] ) ) {
                        return $shop_lookup_map[ $slug ];
                    }
                    if ( isset( $shop_lookup_map[ 'shop_' . $slug ] ) ) {
                        return $shop_lookup_map[ 'shop_' . $slug ];
                    }
                }
            }

            return (string) $raw_shop_id;
        };

        $raw_edit_shop = $edit_t_data ? sywat_get_tournament_shop_id( $edit_t_data ) : ( ! empty( $user_shop_id ) ? $user_shop_id : '' );
        $edit_t_shop   = $resolve_canonical_shop_id( $raw_edit_shop, $edit_t_data );
        $edit_t_season = $edit_t_data ? sywat_get_tournament_season_slug( $edit_t_data ) : ( function_exists( 'sywat_get_active_season' ) ? sywat_get_active_season()['slug'] : '' );
        $edit_t_start  = ( is_array( $edit_t_data ) && isset( $edit_t_data['start_date'] ) ) ? $edit_t_data['start_date'] : '';
        $edit_t_end    = ( is_array( $edit_t_data ) && isset( $edit_t_data['end_date'] ) ) ? $edit_t_data['end_date'] : '';

        $edit_tfaa_val = '';
        if ( is_array( $edit_t_data ) ) {
            if ( ! empty( $edit_t_data['tfaa_event_id'] ) ) {
                $edit_tfaa_val = $edit_t_data['tfaa_event_id'];
            } elseif ( ! empty( $edit_t_data['tfaa_results_url'] ) ) {
                $edit_tfaa_val = $edit_t_data['tfaa_results_url'];
            }
        }

        // Resolve active Pricing & Round Profiles and Season -> Profile inheritance map
        $all_pricing_profiles = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( false ) : array();
        $season_profiles_map  = array();
        foreach ( $seasons as $s_slug_key => $s_val ) {
            if ( function_exists( 'sywat_get_season_pricing_profiles' ) ) {
                $s_profs_list = sywat_get_season_pricing_profiles( $s_val, $seasons );
                $s_slugs      = array_keys( $s_profs_list );
                if ( in_array( 'field', $s_slugs, true ) && in_array( 'field-50', $s_slugs, true ) && count( $s_slugs ) > 2 ) {
                    $s_slugs = array( 'field', 'field-50' );
                }
                $season_profiles_map[ $s_slug_key ] = $s_slugs;
            }
        }

        $selected_t_profiles = array();
        if ( $is_edit_mode && is_array( $edit_t_data ) && function_exists( 'sywat_get_tournament_pricing_profiles' ) ) {
            $resolved_t_profs    = sywat_get_tournament_pricing_profiles( $edit_t_data, $tournaments, $seasons );
            $selected_t_profiles = array_keys( $resolved_t_profs );
        } elseif ( ! empty( $edit_t_season ) && isset( $season_profiles_map[ $edit_t_season ] ) ) {
            $selected_t_profiles = $season_profiles_map[ $edit_t_season ];
        } elseif ( function_exists( 'sywat_get_default_pricing_profile' ) ) {
            $def_p = sywat_get_default_pricing_profile();
            if ( ! empty( $def_p['slug'] ) ) {
                $selected_t_profiles = array( $def_p['slug'] );
            }
        }

        $active_season_slugs = ( ! empty( $edit_t_season ) && isset( $season_profiles_map[ $edit_t_season ] ) )
            ? $season_profiles_map[ $edit_t_season ]
            : array();

        // Helper: Normalize a shop's configured default lines (day, time, cap_20yd, cap_cub)
        $build_normalized_shop_lines = function( $shop_item, $fallback_20, $fallback_cub ) {
            if ( is_array( $shop_item ) && ! empty( $shop_item['default_lines'] ) && is_array( $shop_item['default_lines'] ) ) {
                $raw = $shop_item['default_lines'];
            } elseif ( function_exists( 'sywat_get_default_tournament_lines' ) ) {
                $raw = sywat_get_default_tournament_lines( $shop_item );
            } else {
                $raw = array(
                    array( 'day' => 'Saturday', 'time' => '10AM', 'cap_20yd' => $fallback_20, 'cap_cub' => $fallback_cub ),
                    array( 'day' => 'Saturday', 'time' => '2PM',  'cap_20yd' => $fallback_20, 'cap_cub' => $fallback_cub ),
                    array( 'day' => 'Sunday',   'time' => '10AM', 'cap_20yd' => $fallback_20, 'cap_cub' => $fallback_cub ),
                    array( 'day' => 'Sunday',   'time' => '2PM',  'cap_20yd' => $fallback_20, 'cap_cub' => $fallback_cub ),
                );
            }

            $out = array();
            if ( is_array( $raw ) ) {
                foreach ( $raw as $line ) {
                    if ( ! is_array( $line ) ) {
                        continue;
                    }
                    $d = isset( $line['day'] ) ? trim( (string) $line['day'] ) : '';
                    $t = isset( $line['time'] ) ? trim( (string) $line['time'] ) : ( isset( $line['start_time'] ) ? trim( (string) $line['start_time'] ) : '' );
                    if ( ( $d === '' || $t === '' ) && ! empty( $line['name'] ) ) {
                        $parts = explode( ' ', trim( (string) $line['name'] ), 2 );
                        if ( $d === '' ) {
                            $d = $parts[0];
                        }
                        if ( $t === '' && isset( $parts[1] ) ) {
                            $t = $parts[1];
                        }
                    }
                    if ( function_exists( 'sywat_format_line_time_token' ) && $t !== '' ) {
                        $t = sywat_format_line_time_token( $t );
                    }
                    $out[] = array(
                        'day'      => $d,
                        'time'     => $t !== '' ? $t : '10AM',
                        'cap_20yd' => isset( $line['cap_20yd'] ) ? intval( $line['cap_20yd'] ) : intval( $fallback_20 ),
                        'cap_cub'  => isset( $line['cap_cub'] ) ? intval( $line['cap_cub'] ) : intval( $fallback_cub ),
                    );
                }
            }
            return $out;
        };

        // Resolve target shop key for default line capacity & template initialization
        $target_shop_key = $edit_t_shop;
        if ( empty( $target_shop_key ) && ! empty( $user_shop_id ) ) {
            $target_shop_key = $resolve_canonical_shop_id( $user_shop_id );
        }
        if ( empty( $target_shop_key ) && ! empty( $all_shops ) && is_array( $all_shops ) ) {
            $shop_keys       = array_keys( $all_shops );
            $target_shop_key = reset( $shop_keys );
        }

        $init_shop_data = ( ! empty( $target_shop_key ) && isset( $all_shops[ $target_shop_key ] ) ) ? $all_shops[ $target_shop_key ] : array();

        // Resolve Public Online Registration defaults (from shop on create, or tournament record on edit)
        $init_reg_defaults = function_exists( 'sywat_get_shop_public_reg_defaults' )
            ? sywat_get_shop_public_reg_defaults( $init_shop_data, $all_shops )
            : array(
                'public_reg_default'     => ( is_array( $init_shop_data ) && ! empty( $init_shop_data['public_reg_default'] ) ) ? '1' : '0',
                'public_reg_days_before' => ( is_array( $init_shop_data ) && isset( $init_shop_data['public_reg_days_before'] ) && $init_shop_data['public_reg_days_before'] !== '' )
                    ? max( 0, min( 365, intval( $init_shop_data['public_reg_days_before'] ) ) )
                    : 15,
            );

        if ( $is_edit_mode && is_array( $edit_t_data ) ) {
            $edit_public_reg_enabled = ! empty( $edit_t_data['public_reg_enabled'] ) ? '1' : '0';
            $edit_public_reg_days    = ( isset( $edit_t_data['public_reg_days_before'] ) && $edit_t_data['public_reg_days_before'] !== '' )
                ? max( 0, min( 365, intval( $edit_t_data['public_reg_days_before'] ) ) )
                : intval( $init_reg_defaults['public_reg_days_before'] );
        } else {
            $edit_public_reg_enabled = ( ! empty( $edit_t_shop ) || ! $can_manage_seasons ) ? $init_reg_defaults['public_reg_default'] : '0';
            $edit_public_reg_days    = ( ! empty( $edit_t_shop ) || ! $can_manage_seasons ) ? intval( $init_reg_defaults['public_reg_days_before'] ) : 15;
        }

        // Resolve saved external registration links for this specific tournament
        $edit_t_reg_links = array();
        if ( $is_edit_mode && is_array( $edit_t_data ) && ! empty( $edit_t_data['registration_links'] ) && is_array( $edit_t_data['registration_links'] ) ) {
            $edit_t_reg_links = function_exists( 'sywat_get_shop_registration_links' )
                ? sywat_get_shop_registration_links( $edit_t_data )
                : $edit_t_data['registration_links'];
        }

        if ( function_exists( 'sywat_get_shop_capacity_defaults' ) ) {
            $shop_caps       = sywat_get_shop_capacity_defaults( $init_shop_data );
            $default_cap_20  = intval( $shop_caps['cap_20yd'] );
            $default_cap_cub = intval( $shop_caps['cap_cub'] );
        } else {
            $default_cap_20  = ( is_array( $init_shop_data ) && isset( $init_shop_data['cap_20yd'] ) ) ? intval( $init_shop_data['cap_20yd'] ) : ( ( is_array( $init_shop_data ) && isset( $init_shop_data['capacity_20yd'] ) ) ? intval( $init_shop_data['capacity_20yd'] ) : ( ( is_array( $init_shop_data ) && isset( $init_shop_data['cap_20'] ) ) ? intval( $init_shop_data['cap_20'] ) : 20 ) );
            $default_cap_cub = ( is_array( $init_shop_data ) && isset( $init_shop_data['cap_cub'] ) ) ? intval( $init_shop_data['cap_cub'] ) : ( ( is_array( $init_shop_data ) && isset( $init_shop_data['capacity_cub'] ) ) ? intval( $init_shop_data['capacity_cub'] ) : ( ( is_array( $init_shop_data ) && isset( $init_shop_data['cub_cap'] ) ) ? intval( $init_shop_data['cub_cap'] ) : 5 ) );
        }

        $default_lines = $build_normalized_shop_lines( $init_shop_data, $default_cap_20, $default_cap_cub );
        if ( ! empty( $default_lines ) ) {
            $default_cap_20  = intval( $default_lines[0]['cap_20yd'] );
            $default_cap_cub = intval( $default_lines[0]['cap_cub'] );
        }

        $edit_t_lines = $edit_t_data ? sywat_get_tournament_lines( $edit_t_data ) : $default_lines;
        if ( empty( $edit_t_lines ) || ! is_array( $edit_t_lines ) ) {
            $edit_t_lines = $default_lines;
        }

        // Display titles for read-only edit fields
        $shop_display_name   = isset( $all_shops[ $edit_t_shop ]['name'] ) ? $all_shops[ $edit_t_shop ]['name'] : ( isset( $edit_t_data['shop_name'] ) ? $edit_t_data['shop_name'] : 'Host Archery Shop' );
        $season_display_name = isset( $seasons[ $edit_t_season ]['name'] ) ? $seasons[ $edit_t_season ]['name'] : $edit_t_season;

        // Calculate existing active (non-historical) tournament counts & start dates per shop for auto-naming logic
        $shop_counts = array();
        $shop_events = array();
        foreach ( $tournaments as $t_name => $t_info ) {
            if ( is_array( $t_info ) && ! empty( $t_info['historical_import'] ) ) {
                continue;
            }
            $s_id = $resolve_canonical_shop_id( sywat_get_tournament_shop_id( $t_info ), $t_info );
            if ( ! empty( $s_id ) ) {
                $shop_counts[ $s_id ] = isset( $shop_counts[ $s_id ] ) ? $shop_counts[ $s_id ] + 1 : 1;
                $start                = ! empty( $t_info['start_date'] ) ? $t_info['start_date'] : '';
                if ( ! empty( $start ) ) {
                    $shop_events[ $s_id ][] = array(
                        'name'       => $t_name,
                        'start_date' => $start,
                    );
                }
            }
        }

        // Sort events chronologically per shop
        foreach ( $shop_events as $s_id => &$evts ) {
            usort( $evts, function( $a, $b ) {
                return strcmp( $a['start_date'], $b['start_date'] );
            } );
        }
        unset( $evts );
        ?>
        <div class="sywat-modal-backdrop <?php echo $open_tourn_modal ? 'active' : ''; ?>" id="sywat_add_tourn_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <!-- STANDARDIZED MODAL HEADER -->
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>🎯 <?php echo $is_edit_mode ? 'Edit Tournament Configuration' : 'Create New Tournament'; ?></h3>
                        <div class="sywat-subtitle">Set up tournament details, dates, round pricing formats, public online registration, registration links, and shooting line capacity limits.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" onclick="closeAddTournamentModal()" aria-label="Close Tournament Configuration Modal">&times;</button>
                </div>

                <form method="post" action="?tab=manage" id="sywat_tournament_config_form" onsubmit="return validateTournamentLines();">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field( 'sywat_desk_action', 'sywat_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="save_tournament">
                        <input type="hidden" name="original_tournament_name" value="<?php echo esc_attr( $edit_t_target ); ?>">

                        <!-- ROW 1: HOST SHOP (LEFT) | DATES (RIGHT) -->
                        <div class="sywat-form-grid-2">
                            <div class="sywat-field-group">
                                <label>Host Archery Shop *</label>
                                <?php if ( $is_edit_mode ) : ?>
                                    <input type="hidden"
                                           name="shop_id"
                                           id="sywat_modal_shop_id"
                                           value="<?php echo esc_attr( $edit_t_shop ); ?>"
                                           data-cap20="<?php echo esc_attr( $default_cap_20 ); ?>"
                                           data-capcub="<?php echo esc_attr( $default_cap_cub ); ?>"
                                           data-public-reg-default="<?php echo esc_attr( $init_reg_defaults['public_reg_default'] ); ?>"
                                           data-public-reg-days="<?php echo esc_attr( $init_reg_defaults['public_reg_days_before'] ); ?>"
                                           data-default-lines="<?php echo esc_attr( wp_json_encode( $default_lines ) ); ?>">
                                    <input type="text" value="<?php echo esc_attr( $shop_display_name ); ?>" readonly class="sywat-input-disabled-calc">
                                <?php elseif ( $can_manage_seasons ) : ?>
                                    <select name="shop_id" id="sywat_modal_shop_id" onchange="sywatAutoGenerateTournamentName()" required>
                                        <option value="" <?php selected( empty( $edit_t_shop ), true ); ?>>Select Shop</option>
                                        <?php foreach ( $all_shops as $s_id => $s_name ) : ?>
                                            <?php
                                                $count       = isset( $shop_counts[ $s_id ] ) ? $shop_counts[ $s_id ] : 0;
                                                $events_json = isset( $shop_events[ $s_id ] ) ? esc_attr( wp_json_encode( $shop_events[ $s_id ] ) ) : '[]';
                                                $s_title     = is_array( $s_name ) && isset( $s_name['name'] ) ? $s_name['name'] : ( is_string( $s_name ) ? $s_name : '' );

                                                if ( function_exists( 'sywat_get_shop_capacity_defaults' ) ) {
                                                    $s_caps   = sywat_get_shop_capacity_defaults( $s_name );
                                                    $s_cap20  = intval( $s_caps['cap_20yd'] );
                                                    $s_capcub = intval( $s_caps['cap_cub'] );
                                                } else {
                                                    $s_cap20  = is_array( $s_name ) && isset( $s_name['cap_20yd'] ) ? intval( $s_name['cap_20yd'] ) : ( ( is_array( $s_name ) && isset( $s_name['capacity_20yd'] ) ) ? intval( $s_name['capacity_20yd'] ) : ( ( is_array( $s_name ) && isset( $s_name['cap_20'] ) ) ? intval( $s_name['cap_20'] ) : 20 ) );
                                                    $s_capcub = is_array( $s_name ) && isset( $s_name['cap_cub'] ) ? intval( $s_name['cap_cub'] ) : ( ( is_array( $s_name ) && isset( $s_name['capacity_cub'] ) ) ? intval( $s_name['capacity_cub'] ) : ( ( is_array( $s_name ) && isset( $s_name['cub_cap'] ) ) ? intval( $s_name['cub_cap'] ) : 5 ) );
                                                }

                                                $s_reg_defaults = function_exists( 'sywat_get_shop_public_reg_defaults' )
                                                    ? sywat_get_shop_public_reg_defaults( $s_name, $all_shops )
                                                    : array(
                                                        'public_reg_default'     => ( is_array( $s_name ) && ! empty( $s_name['public_reg_default'] ) ) ? '1' : '0',
                                                        'public_reg_days_before' => ( is_array( $s_name ) && isset( $s_name['public_reg_days_before'] ) && $s_name['public_reg_days_before'] !== '' )
                                                            ? max( 0, min( 365, intval( $s_name['public_reg_days_before'] ) ) )
                                                            : 15,
                                                    );

                                                $s_def_lines = $build_normalized_shop_lines( $s_name, $s_cap20, $s_capcub );
                                                if ( ! empty( $s_def_lines ) ) {
                                                    $s_cap20  = intval( $s_def_lines[0]['cap_20yd'] );
                                                    $s_capcub = intval( $s_def_lines[0]['cap_cub'] );
                                                }
                                            ?>
                                            <option value="<?php echo esc_attr( $s_id ); ?>"
                                                    data-name="<?php echo esc_attr( $s_title ); ?>"
                                                    data-count="<?php echo $count; ?>"
                                                    data-events="<?php echo $events_json; ?>"
                                                    data-cap20="<?php echo esc_attr( $s_cap20 ); ?>"
                                                    data-capcub="<?php echo esc_attr( $s_capcub ); ?>"
                                                    data-public-reg-default="<?php echo esc_attr( $s_reg_defaults['public_reg_default'] ); ?>"
                                                    data-public-reg-days="<?php echo esc_attr( $s_reg_defaults['public_reg_days_before'] ); ?>"
                                                    data-default-lines="<?php echo esc_attr( wp_json_encode( $s_def_lines ) ); ?>"
                                                    <?php selected( $edit_t_shop, $s_id ); ?>>
                                                <?php echo esc_html( $s_title ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : ?>
                                    <input type="hidden"
                                           name="shop_id"
                                           id="sywat_modal_shop_id"
                                           value="<?php echo esc_attr( $target_shop_key ); ?>"
                                           data-cap20="<?php echo esc_attr( $default_cap_20 ); ?>"
                                           data-capcub="<?php echo esc_attr( $default_cap_cub ); ?>"
                                           data-public-reg-default="<?php echo esc_attr( $init_reg_defaults['public_reg_default'] ); ?>"
                                           data-public-reg-days="<?php echo esc_attr( $init_reg_defaults['public_reg_days_before'] ); ?>"
                                           data-default-lines="<?php echo esc_attr( wp_json_encode( $default_lines ) ); ?>">
                                    <input type="text" value="<?php echo esc_attr( isset( $all_shops[ $target_shop_key ]['name'] ) ? $all_shops[ $target_shop_key ]['name'] : 'Current Shop' ); ?>" readonly class="sywat-input-disabled-calc">
                                <?php endif; ?>
                            </div>

                            <div class="sywat-form-grid-2 sywat-grid-compact">
                                <div class="sywat-field-group">
                                    <label>Start Date *</label>
                                    <input type="date" name="start_date" id="sywat_modal_start_date" value="<?php echo esc_attr( $edit_t_start ); ?>" onchange="sywatAutoGenerateTournamentName()" required>
                                </div>
                                <div class="sywat-field-group">
                                    <label>End Date (Optional)</label>
                                    <input type="date" name="end_date" id="sywat_modal_end_date" value="<?php echo esc_attr( $edit_t_end ); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- ROW 2: SEASON (LEFT) | TOURNAMENT NAME WITH INLINE LOCK (RIGHT) -->
                        <div class="sywat-form-grid-2 sywat-modal-row-spacing">
                            <div class="sywat-field-group">
                                <label>Competition Season *</label>
                                <?php if ( $is_edit_mode ) : ?>
                                    <input type="hidden" name="season_slug" id="sywat_modal_season_slug" value="<?php echo esc_attr( $edit_t_season ); ?>">
                                    <input type="text" value="<?php echo esc_attr( $season_display_name ); ?>" readonly class="sywat-input-disabled-calc">
                                <?php else : ?>
                                    <select name="season_slug" id="sywat_modal_season_slug" required>
                                        <?php foreach ( $seasons as $s_slug => $s_item ) : ?>
                                            <option value="<?php echo esc_attr( $s_slug ); ?>" <?php selected( $edit_t_season, $s_slug ); ?>>
                                                <?php echo esc_html( $s_item['name'] ); ?> (<?php echo esc_html( ucfirst( $s_item['status'] ) ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <div class="sywat-field-group">
                                <label>Tournament Name * <span class="sywat-label-subtext" id="sywat_name_status_label">(Auto-generated)</span></label>
                                <div class="sywat-input-group">
                                    <input type="text"
                                           name="tournament_name"
                                           id="sywat_modal_tournament_name"
                                           value="<?php echo esc_attr( $edit_t_target ); ?>"
                                           placeholder="Select shop and date to auto-generate..."
                                           oninput="sywatMarkNameAsCustomized()"
                                           <?php echo ( $is_edit_mode ? ( ! $can_manage_seasons ? 'readonly class="sywat-input-disabled-calc"' : '' ) : 'readonly class="sywat-input-disabled-calc"' ); ?>
                                           required>
                                    <?php if ( ! $is_edit_mode && $can_manage_seasons ) : ?>
                                        <button type="button"
                                                id="sywat_toggle_name_lock_btn"
                                                class="sywat-btn sywat-btn-secondary"
                                                onclick="sywatToggleTournamentNameLock(this)">🔒 Locked</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- ROW 3: OPTIONAL TFAA EVENT ID / DIRECT RESULTS URL OVERRIDE -->
                        <div class="sywat-form-grid-1 sywat-modal-row-spacing">
                            <div class="sywat-field-group">
                                <label for="sywat_modal_tfaa_event_id">TFAA Results Event ID / Direct URL <span class="sywat-label-subtext">(Optional Override — leave blank for automatic season/date matching)</span></label>
                                <input type="text"
                                       name="tfaa_event_id"
                                       id="sywat_modal_tfaa_event_id"
                                       value="<?php echo esc_attr( $edit_tfaa_val ); ?>"
                                       placeholder="e.g. 76 or https://texasfieldarchery.org/results/76">
                            </div>
                        </div>

                        <!-- ROW 4: OFFERED ROUND / PRICING FORMAT(S) (SEASON-FILTERED) -->
                        <?php if ( ! empty( $all_pricing_profiles ) ) : ?>
                            <div class="sywat-form-grid-1 sywat-modal-row-spacing"
                                 id="sywat_modal_pricing_profiles_wrap"
                                 data-season-profiles="<?php echo esc_attr( wp_json_encode( $season_profiles_map ) ); ?>">
                                <div class="sywat-field-group">
                                    <label>Offered Round / Pricing Format(s) * <span class="sywat-label-subtext">(Select all formats offered at this event)</span></label>
                                    <div class="sywat-form-grid-2">
                                        <?php foreach ( $all_pricing_profiles as $p_slug => $p_rec ) : ?>
                                            <?php
                                                $is_checked      = in_array( $p_slug, $selected_t_profiles, true );
                                                $is_visible_prof = empty( $active_season_slugs )
                                                    || in_array( $p_slug, $active_season_slugs, true )
                                                    || $is_checked;
                                                $rate_summary    = function_exists( 'sywat_format_pricing_profile_summary' )
                                                    ? sywat_format_pricing_profile_summary( $p_rec )
                                                    : '';
                                            ?>
                                            <label class="sywat-tourn-pricing-option<?php echo $is_visible_prof ? '' : ' sywat-hidden'; ?>"
                                                   data-profile-slug="<?php echo esc_attr( $p_slug ); ?>">
                                                <input type="checkbox"
                                                       name="tournament_pricing_profiles[]"
                                                       class="sywat-tourn-pricing-profile-cb"
                                                       value="<?php echo esc_attr( $p_slug ); ?>"
                                                       <?php checked( $is_checked ); ?>>
                                                <strong><?php echo esc_html( $p_rec['label'] ); ?></strong>
                                                <?php if ( ! empty( $p_rec['round_subtitle'] ) ) : ?>
                                                    <span class="sywat-label-subtext">(<?php echo esc_html( $p_rec['round_subtitle'] ); ?>)</span>
                                                <?php endif; ?>
                                                <?php if ( $rate_summary !== '' ) : ?>
                                                    <div class="sywat-label-subtext"><?php echo esc_html( $rate_summary ); ?></div>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- ROW 5: PUBLIC ONLINE REGISTRATION (SYWAT MEMBER PORTAL) -->
                        <div class="sywat-form-grid-2 sywat-modal-row-spacing">
                            <div class="sywat-field-group">
                                <label for="sywat_modal_public_reg_enabled">Public Online Registration (SYWAT Portal)</label>
                                <select name="public_reg_enabled" id="sywat_modal_public_reg_enabled">
                                    <option value="0" <?php selected( $edit_public_reg_enabled, '0' ); ?>>Off (External Link or Phone Intake Only)</option>
                                    <option value="1" <?php selected( $edit_public_reg_enabled, '1' ); ?>>On (Members Can Register Online via SYWAT)</option>
                                </select>
                            </div>
                            <div class="sywat-field-group">
                                <label for="sywat_modal_public_reg_days_before">Open Online Registration (Days Before Event) <span class="sywat-label-subtext">(0 = immediate)</span></label>
                                <input type="number"
                                       name="public_reg_days_before"
                                       id="sywat_modal_public_reg_days_before"
                                       value="<?php echo esc_attr( $edit_public_reg_days ); ?>"
                                       min="0"
                                       max="365"
                                       required>
                            </div>
                        </div>

                        <!-- ROW 6: EXTERNAL ONLINE REGISTRATION LINKS (PER-TOURNAMENT, PROGRESSIVE DISCLOSURE) -->
                        <div class="sywat-table-header-bar sywat-modal-section-header">
                            <h2>🔗 External Online Registration Links <span class="sywat-label-subtext">(Optional)</span></h2>
                            <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" data-sywat-action="add-tourn-reg-link">Add Registration Link</button>
                        </div>

                        <div id="sywat_tourn_reg_links_container"
                             class="sywat-mb-4<?php echo empty( $edit_t_reg_links ) ? ' sywat-hidden' : ''; ?>">
                            <?php foreach ( $edit_t_reg_links as $rl ) : ?>
                                <div class="sywat-reg-link-row sywat-form-grid-2 sywat-mt-3">
                                    <div class="sywat-field-group">
                                        <label>Link Label <span class="sywat-label-subtext">(Optional for single link)</span></label>
                                        <input type="text" name="tourn_reg_link_labels[]" value="<?php echo esc_attr( isset( $rl['label'] ) ? $rl['label'] : '' ); ?>" placeholder="e.g. 10-yard lines (Cubs)">
                                    </div>
                                    <div class="sywat-field-group">
                                        <label>Registration URL *</label>
                                        <div class="sywat-search-input-group">
                                            <input type="text" name="tourn_reg_link_urls[]" value="<?php echo esc_attr( isset( $rl['url'] ) ? $rl['url'] : '' ); ?>" placeholder="https://fareharbor.com/embeds/book/...">
                                            <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" data-sywat-action="remove-tourn-reg-link">Remove</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- STANDARDIZED SUB-HEADER -->
                        <div class="sywat-table-header-bar sywat-modal-section-header">
                            <h2>📋 Shooting Lines & Capacity Templates</h2>
                            <div class="sywat-table-header-actions">
                                <button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="reset-tourn-lines">Reset to Shop Defaults</button>
                                <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-add" onclick="addTournamentLineRow()">Add New Line</button>
                            </div>
                        </div>

                        <!-- COMPACT REPEATER TABLE -->
                        <div class="sywat-table-responsive">
                            <table class="sywat-table sywat-modal-repeater-table"
                                   id="sywat_tourn_lines_table"
                                   data-is-edit="<?php echo $is_edit_mode ? '1' : '0'; ?>">
                                <thead>
                                    <tr>
                                        <th class="sywat-col-day">Shooting Day</th>
                                        <th class="sywat-col-time">Start Time</th>
                                        <th class="sywat-col-center sywat-col-cap">20yd Cap</th>
                                        <th class="sywat-col-center sywat-col-cap">Cub Cap</th>
                                        <th class="sywat-col-center sywat-col-action-sm">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $edit_t_lines as $i => $l ) : ?>
                                        <?php
                                            $day_val  = isset( $l['day'] ) ? $l['day'] : '';
                                            $time_val = isset( $l['time'] ) ? $l['time'] : ( isset( $l['start_time'] ) ? $l['start_time'] : '' );

                                            if ( ( empty( $day_val ) || empty( $time_val ) ) && ! empty( $l['name'] ) ) {
                                                $parts = explode( ' ', trim( $l['name'] ), 2 );
                                                if ( empty( $day_val ) ) {
                                                    $day_val = $parts[0];
                                                }
                                                if ( empty( $time_val ) && isset( $parts[1] ) ) {
                                                    $time_val = $parts[1];
                                                }
                                            }

                                            if ( function_exists( 'sywat_format_line_time_token' ) && ! empty( $time_val ) ) {
                                                $time_val = sywat_format_line_time_token( $time_val );
                                            }

                                            $cap_20yd = isset( $l['cap_20yd'] ) ? $l['cap_20yd'] : $default_cap_20;
                                            $cap_cub  = isset( $l['cap_cub'] ) ? $l['cap_cub'] : $default_cap_cub;
                                        ?>
                                        <tr>
                                            <td>
                                                <select name="line_days[]" onchange="checkTournamentLineDuplicate(this);">
                                                    <option value="" disabled <?php selected( empty( $day_val ), true ); ?>>-- Select --</option>
                                                    <option value="Friday" <?php selected( $day_val, 'Friday' ); ?>>Friday</option>
                                                    <option value="Saturday" <?php selected( $day_val, 'Saturday' ); ?>>Saturday</option>
                                                    <option value="Sunday" <?php selected( $day_val, 'Sunday' ); ?>>Sunday</option>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="line_times[]" required onchange="checkTournamentLineDuplicate(this);">
                                                    <?php
                                                        if ( function_exists( 'sywat_render_line_time_options' ) ) {
                                                            echo sywat_render_line_time_options( $time_val );
                                                        } else {
                                                            echo '<option value="' . esc_attr( $time_val ) . '" selected>' . esc_html( $time_val ) . '</option>';
                                                        }
                                                    ?>
                                                </select>
                                            </td>
                                            <td class="sywat-col-center">
                                                <input type="number" name="cap_20yd[]" value="<?php echo esc_attr( $cap_20yd ); ?>" min="0" class="sywat-col-num-input" required>
                                            </td>
                                            <td class="sywat-col-center">
                                                <input type="number" name="cap_cub[]" value="<?php echo esc_attr( $cap_cub ); ?>" min="0" class="sywat-col-num-input" required>
                                            </td>
                                            <td class="sywat-col-center">
                                                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-sm" onclick="removeTournamentLineRow(this)">Remove</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeAddTournamentModal()">Cancel</button>
                        <button type="submit" name="sywat_action" value="save_tournament" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Save Tournament Configuration</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}

/**
 * Render Bulk Schedule Upload & Dry-Run Preview Overlays
 *
 * @param array $data Central data bundle from data engine.
 */
if ( ! function_exists( 'sywat_render_import_schedule_modal' ) ) {
    function sywat_render_import_schedule_modal( $data ) {
        $seasons        = isset( $data['seasons'] ) ? $data['seasons'] : array();
        $current_season = isset( $_GET['filter_season'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_season'] ) ) : ( function_exists( 'sywat_get_active_season' ) ? sywat_get_active_season()['slug'] : '' );

        // Handle Cancel Request
        if ( isset( $_POST['sywat_action'] ) && $_POST['sywat_action'] === 'cancel_schedule_import' ) {
            delete_transient( 'sywat_pending_schedule_import_' . get_current_user_id() );
        }

        // Retrieve active dry-run preview transient
        $transient_key = 'sywat_pending_schedule_import_' . get_current_user_id();
        $processed     = get_transient( $transient_key );

        // Automatically trigger preview overlay whenever transient exists
        $show_preview = ! empty( $processed ) && ( ! empty( $processed['valid_tournaments'] ) || ! empty( $processed['rejected_rows'] ) );

        $valid_list    = ( $show_preview && isset( $processed['valid_tournaments'] ) ) ? $processed['valid_tournaments'] : array();
        $rejected_list = ( $show_preview && isset( $processed['rejected_rows'] ) ) ? $processed['rejected_rows'] : array();
        $target_season = ( $show_preview && isset( $processed['target_season'] ) ) ? $processed['target_season'] : $current_season;
        ?>
        <!-- BULK SCHEDULE IMPORT UPLOAD MODAL -->
        <div class="sywat-modal-backdrop" id="sywat_import_schedule_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>📁 Bulk Import Season Schedule</h3>
                        <div class="sywat-subtitle">Upload a 7-column Excel (.xlsx) or CSV file to build the season schedule and line templates in one click.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" onclick="closeImportScheduleModal()" aria-label="Close Bulk Import Schedule Modal">&times;</button>
                </div>
                <form method="post" action="?tab=manage" enctype="multipart/form-data">
                    <div class="sywat-modal-body">
                        <?php wp_nonce_field( 'sywat_import_schedule_nonce', 'sywat_import_schedule_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="import_schedule_file">

                        <div class="sywat-form-grid-1">
                            <div class="sywat-field-group">
                                <label>Target Competition Season *</label>
                                <select name="import_target_season" required>
                                    <?php foreach ( $seasons as $s_slug => $s_item ) : ?>
                                        <?php $s_name = is_array( $s_item ) && isset( $s_item['name'] ) ? $s_item['name'] : $s_slug; ?>
                                        <option value="<?php echo esc_attr( $s_slug ); ?>" <?php selected( $s_slug, $target_season ); ?>>
                                            <?php echo esc_html( $s_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sywat-field-group sywat-modal-row-spacing">
                                <label>Select Schedule Spreadsheet File (.xlsx, .csv) *</label>
                                <input type="file" name="schedule_file" accept=".xlsx, .xls, .csv, .txt" required>
                            </div>
                        </div>
                    </div>
                    <div class="sywat-modal-footer">
                        <button type="button" class="sywat-btn sywat-btn-secondary sywat-btn-icon-clear" onclick="closeImportScheduleModal()">Cancel</button>
                        <button type="submit" class="sywat-btn sywat-btn-primary sywat-btn-icon-import">Preview & Validate Schedule</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ( $show_preview ) : ?>
        <!-- DRY-RUN PREVIEW MODAL OVERLAY (AUTOMATIC DISPLAY) -->
        <div class="sywat-modal-backdrop active" id="sywat_import_preview_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>🔍 Schedule Import Dry-Run Preview</h3>
                        <div class="sywat-subtitle">Target Season: <strong><?php echo esc_html( isset( $seasons[ $target_season ]['name'] ) ? $seasons[ $target_season ]['name'] : $target_season ); ?></strong></div>
                    </div>
                    <form method="post" action="?tab=manage" class="sywat-form-inline">
                        <input type="hidden" name="sywat_action" value="cancel_schedule_import">
                        <button type="submit" class="sywat-modal-close" title="Close Preview" aria-label="Close Import Preview Modal">&times;</button>
                    </form>
                </div>
                <div class="sywat-modal-body">
                    <?php if ( ! empty( $rejected_list ) ) : ?>
                        <div class="sywat-alert sywat-alert-danger sywat-modal-row-spacing">
                            <strong>⚠️ <?php echo count( $rejected_list ); ?> Row(s) Rejected / Prohibited:</strong>
                            <ul class="sywat-rejected-list">
                                <?php foreach ( $rejected_list as $rej ) : ?>
                                    <li><strong>Row <?php echo intval( $rej['row_num'] ); ?>:</strong> "<?php echo esc_html( $rej['shop_name'] ); ?>" — <em><?php echo esc_html( $rej['reason'] ); ?></em></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="sywat-table-header-bar">
                        <h2>🟢 Valid Tournaments Ready to Create (<?php echo count( $valid_list ); ?>)</h2>
                    </div>

                    <div class="sywat-table-responsive">
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th>Tournament Title</th>
                                    <th>Dates</th>
                                    <th>Host / Location</th>
                                    <th>Event Type</th>
                                    <th class="sywat-col-center">Lines</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $valid_list as $vt ) : ?>
                                    <?php $line_cnt = count( $vt['line_slots'] ); ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $vt['tournament_name'] ); ?></strong></td>
                                        <td><?php echo esc_html( $vt['start_date_formatted'] . ( $vt['start_date_formatted'] !== $vt['end_date_formatted'] ? ' to ' . $vt['end_date_formatted'] : '' ) ); ?></td>
                                        <td><?php echo esc_html( ! empty( $vt['official_shop_name'] ) ? $vt['official_shop_name'] : $vt['location_display'] ); ?></td>
                                        <td><span class="sywat-badge"><?php echo esc_html( $vt['event_type'] ); ?></span></td>
                                        <td class="sywat-col-center"><?php echo $line_cnt . ' ' . ( $line_cnt === 1 ? 'Line' : 'Lines' ); ?></td>
                                        <td><small><?php echo esc_html( ! empty( $vt['notes'] ) ? $vt['notes'] : '—' ); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="sywat-modal-footer">
                    <form method="post" action="?tab=manage">
                        <?php wp_nonce_field( 'sywat_confirm_schedule_nonce', 'sywat_confirm_schedule_nonce' ); ?>
                        <input type="hidden" name="sywat_action" value="confirm_schedule_import">

                        <button type="submit" name="sywat_action" value="cancel_schedule_import" class="sywat-btn sywat-btn-secondary">Cancel</button>
                        <button type="submit" name="sywat_action" value="confirm_schedule_import" class="sywat-btn sywat-btn-success sywat-btn-icon-save">Confirm & Create <?php echo count( $valid_list ); ?> Tournaments</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }
}

/**
 * Render Delegated Archer / Roster / Invoice Modal Container
 * Pre-scopes registrations to the selected tournament before delegating to sub-modal renderers.
 *
 * @param array $data Central data bundle from data engine.
 */
if ( ! function_exists( 'sywat_render_delegated_modal_container' ) ) {
    function sywat_render_delegated_modal_container( $data ) {
        $tournaments   = $data['tournaments'];
        $registrations = $data['registrations'];

        $selected_tournament = isset( $_REQUEST['view_tournament'] ) ? sanitize_text_field( $_REQUEST['view_tournament'] ) : '';
        $modal_action        = isset( $_REQUEST['modal_action'] ) ? sanitize_text_field( $_REQUEST['modal_action'] ) : 'manage';
        $open_modal          = ! empty( $selected_tournament ) && isset( $tournaments[ $selected_tournament ] );

        $modal_edit_archer = isset( $_REQUEST['edit_archer'] ) ? sanitize_text_field( $_REQUEST['edit_archer'] ) : '';

        $available_lines  = $open_modal ? sywat_get_tournament_lines( $tournaments[ $selected_tournament ] ) : array();
        $filter_line_idx  = isset( $_REQUEST['filter_line_idx'] ) ? sanitize_text_field( $_REQUEST['filter_line_idx'] ) : 'all';
        $search_query     = isset( $_REQUEST['search_shooter'] ) ? trim( sanitize_text_field( $_REQUEST['search_shooter'] ) ) : '';
        $is_specific_line = ( $filter_line_idx !== 'all' && $filter_line_idx !== '' && $filter_line_idx !== null );

        $modal_registrations = array();
        if ( $open_modal && is_array( $registrations ) ) {
            $target_norm = trim( (string) $selected_tournament );
            foreach ( $registrations as $reg ) {
                if ( is_array( $reg ) && isset( $reg['tournament'] ) && strcasecmp( trim( (string) $reg['tournament'] ), $target_norm ) === 0 ) {
                    $modal_registrations[] = $reg;
                }
            }
        }
        ?>
        <div class="sywat-modal-backdrop <?php echo $open_modal ? 'active' : ''; ?>" id="sywat_modal">
            <div class="sywat-modal-content sywat-modal-xl">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>
                            <?php
                                if ( $modal_action === 'invoice' ) echo '📄 TFAA Tournament Invoice';
                                elseif ( $modal_action === 'print' ) echo '🖨️ Print Checked In Roster';
                                elseif ( $modal_action === 'prereg' ) echo '📝 Register Shooters & Bulk Import';
                                else echo '👥 Registered Archer Roster';
                            ?>
                        </h3>
                        <div class="sywat-subtitle"><?php echo esc_html( $selected_tournament ); ?></div>
                    </div>
                    <button type="button" class="sywat-modal-close" onclick="closeSywatModal()" aria-label="Close Modal">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <?php if ( $open_modal ) : ?>
                        <?php
                            if ( $modal_action === 'prereg' && function_exists( 'sywat_render_registration_modal' ) ) {
                                sywat_render_registration_modal( $selected_tournament, $available_lines, $modal_registrations );
                            } elseif ( $modal_action === 'manage' && function_exists( 'sywat_render_roster_modal' ) ) {
                                sywat_render_roster_modal( $selected_tournament, $available_lines, $modal_registrations, $filter_line_idx, $search_query, $modal_edit_archer, $tournaments );
                            } elseif ( $modal_action === 'invoice' && function_exists( 'sywat_render_invoice_modal' ) ) {
                                sywat_render_invoice_modal( $selected_tournament, $modal_registrations );
                            } elseif ( $modal_action === 'print' && function_exists( 'sywat_render_print_modal' ) ) {
                                sywat_render_print_modal( $selected_tournament, $available_lines, $modal_registrations, $filter_line_idx, $is_specific_line );
                            }
                        ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * Render TFAA Scores Batch Sync Progress Modal
 *
 * @param array $data Central data bundle from data engine.
 */
if ( ! function_exists( 'sywat_render_tfaa_sync_progress_modal' ) ) {
    function sywat_render_tfaa_sync_progress_modal( $data ) {
        ?>
        <!-- TFAA SCORES BATCH SYNC PROGRESS MODAL -->
        <div class="sywat-modal-backdrop" id="sywat_tfaa_sync_modal">
            <div class="sywat-modal-content sywat-modal-lg">
                <div class="sywat-modal-header no-print">
                    <div>
                        <h3>🔄 TFAA Scorecards Batch Sync</h3>
                        <div class="sywat-subtitle">Real-time progress and event scorecard sync activity log.</div>
                    </div>
                    <button type="button" class="sywat-modal-close" onclick="sywatCloseTfaaSyncModal()" aria-label="Close TFAA Sync Progress Modal">&times;</button>
                </div>
                <div class="sywat-modal-body">
                    <div class="sywat-progress-bar-track">
                        <div class="sywat-progress-bar-fill" id="sywat_tfaa_sync_progress_fill"></div>
                    </div>
                    <div id="sywat_tfaa_sync_status_text" class="sywat-subtitle sywat-mt-3">Initialising sync queue...</div>

                    <div class="sywat-table-header-bar sywat-modal-section-header">
                        <h2>📋 Live Activity Log</h2>
                    </div>
                    <div id="sywat_tfaa_sync_log_console" class="sywat-log-console"></div>
                </div>
                <div class="sywat-modal-footer">
                    <button type="button" class="sywat-btn sywat-btn-secondary" id="sywat_tfaa_sync_close_btn" onclick="sywatCloseTfaaSyncModal()">Close</button>
                </div>
            </div>
        </div>
        <?php
    }
}
