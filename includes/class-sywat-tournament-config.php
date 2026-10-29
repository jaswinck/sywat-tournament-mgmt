<?php
/**
 * Module: Tournament Configuration, Master Portal & Volunteer Hub
 * Description: Shortcode routing, master admin portal shell, modal tournament CRUD with date pickers & host shop scoping, live tournament name search, and Volunteer Hub portal view.
 * Author: SYWAT Archery
 * Version: 2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Shortcode Bindings
if ( shortcode_exists( 'sywat_admin' ) )              remove_shortcode( 'sywat_admin' );
if ( shortcode_exists( 'sywat_manage' ) )             remove_shortcode( 'sywat_manage' );
if ( shortcode_exists( 'sywat_manage_tournaments' ) ) remove_shortcode( 'sywat_manage_tournaments' );
if ( shortcode_exists( 'sywat_volunteer_hub' ) )      remove_shortcode( 'sywat_volunteer_hub' );

add_shortcode( 'sywat_admin',              'render_sywat_master_portal' );
add_shortcode( 'sywat_manage',             'render_sywat_master_portal' );
add_shortcode( 'sywat_manage_tournaments', 'render_sywat_master_portal' );
add_shortcode( 'sywat_volunteer_hub',      'render_sywat_volunteer_hub' );

// Core Helper Functions
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

if ( ! function_exists( 'sywat_count_registered' ) ) {
    function sywat_count_registered( $t_name, $l_idx, $dist, $registrations ) {
        $count = 0;
        foreach ( $registrations as $reg ) {
            if ( isset( $reg['tournament'], $reg['line_index'], $reg['distance'] )
                && strcasecmp( $reg['tournament'], $t_name ) === 0
                && (string)$reg['line_index'] === (string)$l_idx
                && sywat_normalize_dist( $reg['distance'] ) === $dist ) {
                $count++;
            }
        }
        return $count;
    }
}

if ( ! function_exists( 'sywat_get_tournament_count' ) ) {
    function sywat_get_tournament_count( $t_name, $registrations ) {
        $count = 0;
        foreach ( $registrations as $reg ) {
            if ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $t_name ) === 0 ) {
                $count++;
            }
        }
        return $count;
    }
}

// Helper: Format Start & End Dates nicely (e.g. "October 24 – 25, 2026" or "Oct 24, 2026")
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

// Helper: Extract Lines Array from Tournament Data (Backwards Compatible)
if ( ! function_exists( 'sywat_get_tournament_lines' ) ) {
    function sywat_get_tournament_lines( $t_data ) {
        if ( ! is_array( $t_data ) ) return array();
        if ( isset( $t_data['lines'] ) && is_array( $t_data['lines'] ) ) {
            return $t_data['lines'];
        }
        return $t_data; // Legacy format where $t_data was directly an array of lines
    }
}

// Helper: Extract Shop ID from Tournament Data (Backwards Compatible)
if ( ! function_exists( 'sywat_get_tournament_shop_id' ) ) {
    function sywat_get_tournament_shop_id( $t_data ) {
        if ( is_array( $t_data ) && isset( $t_data['shop_id'] ) && ! empty( $t_data['shop_id'] ) ) {
            return $t_data['shop_id'];
        }
        return 'shop_ccr'; // Default fallback for pre-existing tournaments
    }
}

// Master Admin Portal Controller
if ( ! function_exists( 'render_sywat_master_portal' ) ) {
    function render_sywat_master_portal() {
        $user = wp_get_current_user();
        if ( is_user_logged_in() && ! array_intersect( array( 'administrator', 'management', 'sywat_shop_manager' ), (array) $user->roles ) ) {
            return '<p style="color:red; padding:20px;">Access denied. Management access required.</p>';
        }

        ob_start();
        global $wpdb;

        $is_admin     = current_user_can( 'administrator' );
        $user_shop_id = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';
        $all_shops    = function_exists( 'sywat_get_shops' ) ? sywat_get_shops() : array();

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        $active_tournaments = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_tournaments ) ) $active_tournaments = array();

        $registrations = array();
        $results = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%'" );
        foreach ($results as $row) {
            $reg_data = maybe_unserialize($row->option_value);
            if (is_array($reg_data) && isset($reg_data['id'])) {
                $registrations[] = $reg_data;
            }
        }

        $notice = '';
        $active_tab = isset( $_REQUEST['tab'] ) ? sanitize_text_field( $_REQUEST['tab'] ) : 'manage';

        $default_lines = array(
            array( 'name' => 'Saturday 10AM', 'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Saturday 2PM',  'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Sunday 10AM',   'cap_20yd' => 20, 'cap_cub' => 5 ),
            array( 'name' => 'Sunday 2PM',    'cap_20yd' => 20, 'cap_cub' => 5 ),
        );

        $selected_tournament = isset( $_REQUEST['view_tournament'] ) ? sanitize_text_field( $_REQUEST['view_tournament'] ) : '';
        $modal_action        = isset( $_REQUEST['modal_action'] ) ? sanitize_text_field( $_REQUEST['modal_action'] ) : 'manage';
        $open_modal          = ! empty( $selected_tournament ) && isset( $tournaments[$selected_tournament] );

        $open_tourn_modal    = isset( $_REQUEST['open_add_tournament'] ) || isset( $_REQUEST['edit_tournament_modal'] );
        $edit_t_target       = isset( $_REQUEST['edit_tournament_modal'] ) ? sanitize_text_field( $_REQUEST['edit_tournament_modal'] ) : '';
        
        $edit_t_data         = ( ! empty( $edit_t_target ) && isset( $tournaments[$edit_t_target] ) ) ? $tournaments[$edit_t_target] : null;
        $edit_t_lines        = $edit_t_data ? sywat_get_tournament_lines( $edit_t_data ) : $default_lines;
        $edit_t_shop         = $edit_t_data ? sywat_get_tournament_shop_id( $edit_t_data ) : ( $is_admin ? '' : $user_shop_id );
        $edit_t_start        = ( is_array( $edit_t_data ) && isset( $edit_t_data['start_date'] ) ) ? $edit_t_data['start_date'] : '';
        $edit_t_end          = ( is_array( $edit_t_data ) && isset( $edit_t_data['end_date'] ) ) ? $edit_t_data['end_date'] : '';

        $form_line_count     = isset( $_POST['line_count'] ) ? intval( $_POST['line_count'] ) : count( $edit_t_lines );
        if ( $form_line_count < 1 ) $form_line_count = 1;
        if ( $form_line_count > 6 ) $form_line_count = 6;

        $available_lines = $open_modal ? sywat_get_tournament_lines( $tournaments[$selected_tournament] ) : array();
        $filter_line_idx = isset( $_REQUEST['filter_line_idx'] ) ? sanitize_text_field( $_REQUEST['filter_line_idx'] ) : 'all';
        $search_query    = isset( $_REQUEST['search_shooter'] ) ? trim( sanitize_text_field( $_REQUEST['search_shooter'] ) ) : '';
        $is_specific_line = ($filter_line_idx !== 'all' && $filter_line_idx !== '' && $filter_line_idx !== null);

        // POST Action Handlers
        $is_valid_nonce = (isset($_POST['sywat_nonce']) && wp_verify_nonce($_POST['sywat_nonce'], 'sywat_desk_action'));
        $action = isset($_POST['sywat_action']) ? $_POST['sywat_action'] : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action && !$is_valid_nonce && $action !== 'cancel') {
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce) {
            
            if ( $action === 'sync_tfaa_now' ) {
                if ( function_exists( 'sywat_sync_tfaa_standings' ) ) {
                    $res = sywat_sync_tfaa_standings();
                    $notice = ! empty( $res['success'] ) 
                        ? '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 <strong>TFAA Standings Synced!</strong> Successfully cached ' . intval($res['count']) . ' active shooters from TFAA.</div>'
                        : '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
                }
            }

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

            if ( $action === 'save_tournament' ) {
                $t_name   = sanitize_text_field( $_POST['tournament_name'] );
                $old_name = sanitize_text_field( $_POST['original_tournament_name'] );
                $new_slug = sanitize_title( $t_name );

                $start_date = sanitize_text_field( $_POST['start_date'] );
                $end_date   = sanitize_text_field( $_POST['end_date'] );
                if ( empty( $end_date ) ) $end_date = $start_date;

                // Enforce shop assignment: Admins can assign to any shop, non-admins auto-lock to their own shop
                $assigned_shop_id = $is_admin ? sanitize_text_field( $_POST['shop_id'] ) : $user_shop_id;
                if ( empty( $assigned_shop_id ) ) $assigned_shop_id = $user_shop_id;
                
                $formatted_dates = sywat_format_tournament_dates( $start_date, $end_date );
                update_option( 'sywat_dates_' . $new_slug, $formatted_dates );

                $new_lines = array();
                if ( ! empty( $_POST['line_names'] ) ) {
                    foreach ( $_POST['line_names'] as $index => $name ) {
                        if ( $index < $form_line_count ) {
                            $new_lines[] = array(
                                'name'     => sanitize_text_field( $name ),
                                'cap_20yd' => intval( $_POST['cap_20yd'][$index] ),
                                'cap_cub'  => intval( $_POST['cap_cub'][$index] ),
                            );
                        }
                    }
                }

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
                    unset($reg);

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
                    'shop_id'    => $assigned_shop_id,
                    'start_date' => $start_date,
                    'end_date'   => $end_date,
                    'lines'      => $new_lines,
                );

                update_option( 'sywat_tournaments_list', $tournaments );
                update_option( 'sywat_active_tournaments', array_values( array_unique( $active_tournaments ) ) );
                update_option( 'sywat_current_tournament', $t_name );
                update_option( 'sywat_line_config', $new_lines );

                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Tournament <strong>' . esc_html( $t_name ) . '</strong> saved successfully!</div>';
                $active_tab = 'manage';
                $open_tourn_modal = false;
            }

            if ( $action === 'delete_tournament' ) {
                $del_tournament = sanitize_text_field( $_POST['tournament_name'] );
                if ( isset( $tournaments[$del_tournament] ) ) {
                    unset( $tournaments[$del_tournament] );
                    update_option( 'sywat_tournaments_list', $tournaments );

                    $active_tournaments = array_diff( $active_tournaments, array( $del_tournament ) );
                    update_option( 'sywat_active_tournaments', array_values( $active_tournaments ) );

                    $del_slug = sanitize_title( $del_tournament );
                    delete_option('sywat_dates_' . $del_slug);

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

            // Sub-Module Delegated Action Handlers
            if ( $action === 'register_shooter' && function_exists( 'sywat_handle_register_shooter' ) ) {
                $notice = sywat_handle_register_shooter( $tournaments, $registrations );
                $open_modal = true; $modal_action = 'prereg';
            }

            if ( $action === 'import_shooters' && function_exists( 'sywat_handle_import_shooters' ) ) {
                $target_line_idx = isset( $_POST['target_line_idx'] ) ? sanitize_text_field( $_POST['target_line_idx'] ) : '0';
                $notice = sywat_handle_import_shooters( $selected_tournament, $target_line_idx, $tournaments, $registrations );
                $open_modal = true; $modal_action = 'prereg';
            }

            if ( $action === 'save_edit' && function_exists( 'sywat_handle_save_edit' ) ) {
                $notice = sywat_handle_save_edit( $tournaments, $registrations );
                $open_modal = true; $modal_action = 'manage';
            }

            if ( $action === 'delete_shooter' && function_exists( 'sywat_handle_delete_shooter' ) ) {
                $notice = sywat_handle_delete_shooter( $registrations );
                $open_modal = true; $modal_action = 'manage';
            }

            if ( $action === 'purge_line' && function_exists( 'sywat_handle_purge_line' ) ) {
                $notice = sywat_handle_purge_line( $tournaments, $registrations );
                $open_modal = true; $modal_action = isset( $_POST['redirect_action'] ) ? sanitize_text_field( $_POST['redirect_action'] ) : 'prereg';
            }
        }

        // Filter Tournaments by Shop Scoping: Non-Admins only see tournaments hosted by their shop
        $scoped_tournaments = array();
        foreach ( $tournaments as $t_name => $t_data ) {
            $t_shop = sywat_get_tournament_shop_id( $t_data );
            if ( $is_admin || $t_shop === $user_shop_id ) {
                $scoped_tournaments[$t_name] = $t_data;
            }
        }

        $modal_edit_id = isset( $_REQUEST['open_edit_id'] ) ? sanitize_text_field( $_REQUEST['open_edit_id'] ) : '';
        $modal_edit_archer = null;
        if ( $modal_edit_id ) {
            foreach ( $registrations as $reg ) {
                if ( isset( $reg['id'] ) && $reg['id'] === $modal_edit_id ) {
                    $modal_edit_archer = $reg; break;
                }
            }
        }

        $existing_families = array();
        if ($open_modal) {
            foreach ($registrations as $reg) {
                if (isset($reg['tournament']) && strcasecmp($reg['tournament'], $selected_tournament) === 0) {
                    if (isset($reg['paid']) && $reg['paid'] === 'Family' && !empty($reg['family_id'])) {
                        $fam_id = $reg['family_id'];
                        $fam_label = !empty($reg['family_name']) ? $reg['family_name'] : (!empty($reg['booking_id']) ? 'Booking ' . $reg['booking_id'] : 'Family ID ' . substr($fam_id, -6));
                        $existing_families[$fam_id] = $fam_label;
                    }
                }
            }
        }

        $tfaa_cache = get_option( 'sywat_tfaa_cache', array() );
        $tfaa_count = isset( $tfaa_cache['count'] ) ? $tfaa_cache['count'] : 0;
        $tfaa_updated = isset( $tfaa_cache['updated_at'] ) ? $tfaa_cache['updated_at'] : 'Never';
        ?>

        <style>
            .sywat-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #2c3338; max-width: 1100px; margin: 0 auto; }
            .sywat-nav-tabs { display: flex; border-bottom: 1px solid #c3c4c7; margin-bottom: 20px; gap: 5px; }
            .sywat-tab-item { padding: 10px 18px; font-weight: 600; text-decoration: none; color: #50575e; background: #f6f7f7; border: 1px solid #c3c4c7; border-bottom: none; border-top-left-radius: 4px; border-top-right-radius: 4px; font-size: 14px; }
            .sywat-tab-item.active { background: #ffffff; color: #1d2327; border-bottom: 1px solid #ffffff; margin-bottom: -1px; }
            
            .sywat-card { background: #ffffff; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); padding: 20px; margin-bottom: 20px; }
            .sywat-card-title { font-size: 1.1em; font-weight: 600; color: #1d2327; margin: 0 0 15px 0; border-bottom: 1px solid #dcdcde; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
            .sywat-subtitle { font-size: 13px; color: #646970; margin-top: -10px; margin-bottom: 15px; }
            
            .sywat-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
            .sywat-table th { background-color: #f6f7f7; text-align: center; padding: 10px 12px; font-weight: 600; color: #1d2327; border-bottom: 1px solid #c3c4c7; }
            .sywat-table td { padding: 12px; border-bottom: 1px solid #f0f0f1; color: #2c3338; vertical-align: middle; }
            
            .sywat-btn-primary { background-color: #2271b1; border: 1px solid #2271b1; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; text-decoration: none; font-size: 13px; height: 32px; white-space: nowrap; box-sizing: border-box; color: #ffffff !important; }
            .sywat-btn-primary:hover { background-color: #135e96; border-color: #135e96; color: #ffffff !important; }
            
            .sywat-btn-secondary { background-color: #ffffff; border: 1px solid #2271b1; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; text-decoration: none; font-size: 13px; height: 32px; white-space: nowrap; box-sizing: border-box; color: #2271b1 !important; }
            .sywat-btn-secondary:hover { background-color: #2271b1; color: #ffffff !important; }

            .sywat-btn-success { background-color: #008a20; border: 1px solid #008a20; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; text-decoration: none; font-size: 13px; height: 32px; white-space: nowrap; box-sizing: border-box; color: #ffffff !important; }
            .sywat-btn-success:hover { background-color: #007017; border-color: #007017; color: #ffffff !important; }

            .sywat-btn-danger { background-color: #dc3545; border: 1px solid #dc3545; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; text-decoration: none; font-size: 13px; height: 32px; white-space: nowrap; box-sizing: border-box; color: #ffffff !important; }
            .sywat-btn-danger:hover { background-color: #bd2130; border-color: #b21f2d; color: #ffffff !important; }

            .sywat-badge { display: inline-block; padding: 2px 6px; font-size: 11px; font-weight: 700; border-radius: 3px; }
            .sywat-badge-prereg { background-color: #e5f5fa; color: #0073aa; border: 1px solid #bfe7f3; }
            .sywat-badge-walkin { background-color: #fcf8e3; color: #8a6d3b; border: 1px solid #faebcc; }
            .sywat-badge-open { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .sywat-badge-full { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

            .sywat-alert { padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
            .sywat-alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .sywat-alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

            .sywat-modal-backdrop { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.55); z-index: 99999; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box; }
            .sywat-modal-backdrop.active { display: flex; }
            .sywat-modal-content { background: #fff; width: 100%; max-width: 900px; border-radius: 6px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; }
            .sywat-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #dcdcde; background: #f6f7f7; }
            .sywat-modal-header h3 { margin: 0; font-size: 1.1em; color: #1d2327; font-weight: 600; }
            .sywat-modal-close { background: none; border: none; font-size: 22px; font-weight: bold; color: #646970; cursor: pointer; line-height: 1; }
            .sywat-modal-close:hover { color: #d63638; }
            .sywat-modal-body { padding: 20px; overflow-y: auto; }
        </style>

        <div class="sywat-wrap">
            <div class="no-print"><?php echo $notice; ?></div>

            <div class="sywat-nav-tabs no-print">
                <a href="?tab=manage" class="sywat-tab-item active">Tournament Management</a>
                <?php if ( $is_admin ): ?>
                    <a href="?tab=shops" class="sywat-tab-item">Shop Management</a>
                <?php endif; ?>
            </div>

            <?php if ( $active_tab === 'shops' && $is_admin ): ?>
                <!-- TAB 2: SHOP MANAGEMENT -->
                <?php if ( function_exists( 'sywat_render_shops_tab' ) ) sywat_render_shops_tab(); ?>

            <?php else: ?>
                <!-- TAB 1: TOURNAMENTS TABLE -->
                <div class="sywat-card no-print">
                    <div class="sywat-card-title">
                        <span>Configured Tournaments</span>
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <div style="display:flex; gap:5px; margin:0; align-items:center;">
                                <input type="text" id="sywat_tournament_search_input" placeholder="Search tournament name..." autocomplete="off" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:180px;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }">
                                <button type="button" class="sywat-btn-secondary" style="font-size:13px; line-height:22px; height:30px;" onclick="document.getElementById('sywat_tournament_search_input').value=''; document.getElementById('sywat_tournament_search_input').dispatchEvent(new Event('input'));">Clear</button>
                            </div>
                            <form method="post" style="margin: 0; display: inline-flex; align-items: center; gap: 10px;">
                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                <input type="hidden" name="sywat_action" value="sync_tfaa_now">
                                <span style="font-size: 12px; color: #646970; font-weight: normal;">TFAA Standings: <strong><?php echo $tfaa_count; ?></strong> Shooters</span>
                                <button type="submit" class="sywat-btn-secondary" style="height: 30px; font-size: 12px;">🔄 Sync TFAA</button>
                            </form>
                            <button type="button" class="sywat-btn-success" style="height: 30px; font-size: 12px;" onclick="openAddTournamentModal()">+ Add Tournament</button>
                        </div>
                    </div>
                    <div class="sywat-subtitle">Overview of configured tournaments, shooting lines, and archer administration.</div>

                    <?php if ( empty( $scoped_tournaments ) ): ?>
                        <p style="color:#646970; font-style:italic;">No tournaments created yet for this location. Click on <strong>"+ Add Tournament"</strong> to configure your first tournament.</p>
                    <?php else: ?>
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th style="width: 22%; text-align: center;">Tournament & Location</th>
                                    <th style="width: 12%; text-align: center;">Volunteer Hub</th>
                                    <th style="width: 35%; text-align: center;">Line Slot Availability (20yd / Cub)</th>
                                    <th style="width: 16%; text-align: center;">Shooter Management</th>
                                    <th style="width: 15%; text-align: center;">Tournament Management</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $scoped_tournaments as $t_name => $t_data ): ?>
                                    <?php 
                                        $t_lines    = sywat_get_tournament_lines( $t_data );
                                        $t_shop_id  = sywat_get_tournament_shop_id( $t_data );
                                        $t_shop_name= isset( $all_shops[$t_shop_id]['name'] ) ? $all_shops[$t_shop_id]['name'] : 'Cinnamon Creek Ranch';

                                        $s_date     = ( is_array( $t_data ) && isset( $t_data['start_date'] ) ) ? $t_data['start_date'] : '';
                                        $e_date     = ( is_array( $t_data ) && isset( $t_data['end_date'] ) ) ? $t_data['end_date'] : '';
                                        $disp_dates = sywat_format_tournament_dates( $s_date, $e_date );
                                        if ( empty( $disp_dates ) ) {
                                            $disp_dates = get_option( 'sywat_dates_' . sanitize_title( $t_name ), 'Dates Not Set' );
                                        }

                                        $line_count = is_array( $t_lines ) ? count( $t_lines ) : 0;
                                        $reg_count  = sywat_get_tournament_count( $t_name, $registrations );
                                        $is_active_hub = in_array( $t_name, $active_tournaments );
                                    ?>
                                    <tr class="sywat-tournament-row" data-name="<?php echo esc_attr(strtolower($t_name)); ?>" data-shop="<?php echo esc_attr(strtolower($t_shop_name)); ?>">
                                        <td>
                                            <strong><?php echo esc_html( $t_name ); ?></strong>
                                            <div style="font-size: 12px; color: #2271b1; font-weight: 600; margin-top: 2px;">📍 <?php echo esc_html( $t_shop_name ); ?></div>
                                            <div style="font-size: 11px; color: #646970; margin-top: 2px;">📅 <?php echo esc_html( $disp_dates ); ?></div>
                                            <div style="font-size: 11px; color: #646970; margin-top: 1px;"><?php echo $line_count . ' ' . ( $line_count === 1 ? 'line' : 'lines' ); ?></div>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ( $t_shop_id === 'shop_ccr' ): ?>
                                                <form method="post" style="margin: 0;">
                                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                    <input type="hidden" name="sywat_action" value="toggle_active_tournament">
                                                    <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                    <?php if ( $is_active_hub ): ?>
                                                        <button type="submit" class="sywat-btn-success" style="padding: 4px 8px; font-size: 11px; height: auto;">🟢 Active (Hide)</button>
                                                    <?php else: ?>
                                                        <button type="submit" class="sywat-btn-secondary" style="padding: 4px 8px; font-size: 11px; height: auto;">⚪ Hidden (Activate)</button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #8c8f94; font-size: 12px; font-weight: 600;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( ! empty( $t_lines ) && is_array( $t_lines ) ): ?>
                                                <div style="display: flex; flex-direction: column; gap: 5px;">
                                                    <?php foreach ( $t_lines as $idx => $line ): ?>
                                                        <?php 
                                                            $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ($idx + 1);
                                                            $c_20 = sywat_count_registered( $t_name, $idx, '20yd', $registrations );
                                                            $m_20 = sywat_get_line_max_cap( $line, '20yd' );
                                                            $rem_20 = max( 0, $m_20 - $c_20 );
                                                            $c_cub = sywat_count_registered( $t_name, $idx, 'Cub', $registrations );
                                                            $m_cub = sywat_get_line_max_cap( $line, 'Cub' );
                                                            $rem_cub = max( 0, $m_cub - $c_cub );
                                                        ?>
                                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f8f9fa; padding: 5px 10px; border-radius: 4px; border: 1px solid #e2e8f0; font-size: 12px;">
                                                            <strong style="color: #1d2327;"><?php echo esc_html( $line_title ); ?></strong>
                                                            <div style="display: flex; gap: 10px; color: #50575e; align-items: center;">
                                                                <span>20yd: <strong><?php echo $c_20; ?>/<?php echo $m_20; ?></strong> <span style="color: <?php echo ($rem_20 > 0) ? '#008a20' : '#d63638'; ?>; font-weight: 600;">(<?php echo $rem_20; ?> open)</span></span>
                                                                <span>Cub: <strong><?php echo $c_cub; ?>/<?php echo $m_cub; ?></strong> <span style="color: <?php echo ($rem_cub > 0) ? '#008a20' : '#d63638'; ?>; font-weight: 600;">(<?php echo $rem_cub; ?> open)</span></span>
                                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=print&filter_line_idx=<?php echo $idx; ?>" style="margin-left:5px; color:#2271b1; text-decoration:none; font-weight:bold;">🖨️ Print Line</a>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="margin-top: 8px; padding-top: 6px; border-top: 1px dashed #c3c4c7; font-size: 12px; color: #1d2327;">
                                                <strong>Total Registered:</strong> <?php echo $reg_count; ?> <?php echo ( $reg_count === 1 ? 'archer' : 'archers' ); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=manage" class="sywat-btn-primary" style="width: 100%; justify-content: center;">Manage Shooters</a>
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=prereg" class="sywat-btn-primary" style="width: 100%; justify-content: center;">Add Pre-Reg / CSV</a>
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=print" class="sywat-btn-primary" style="width: 100%; justify-content: center;">Print Roster</a>
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=invoice" class="sywat-btn-primary" style="width: 100%; justify-content: center; background:#0284c7; border-color:#0284c7;">📄 Generate Invoice</a>
                                            </div>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-end;">
                                                <a href="/sywat-desk/" class="sywat-btn-success" style="width: 100%; max-width: 130px; justify-content: center;">Check-In Desk</a>
                                                <a href="/sywat-enter-scores/?selected_tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn-primary" style="width: 100%; max-width: 130px; justify-content: center;">Enter Scores</a>
                                                <a href="?edit_tournament_modal=<?php echo urlencode( $t_name ); ?>" class="sywat-btn-primary" style="width: 100%; max-width: 130px; justify-content: center;">Edit Config</a>
                                                <form method="post" style="margin: 0; width: 100%; max-width: 130px;" onsubmit="return confirm('Are you sure you want to delete \'<?php echo esc_js( $t_name ); ?>\'? This permanently purges all registered archers.');">
                                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                    <input type="hidden" name="sywat_action" value="delete_tournament">
                                                    <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                    <button type="submit" class="sywat-btn-danger" style="width: 100%; justify-content: center;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- MODAL 1: ADD / EDIT TOURNAMENT MODAL -->
            <div class="sywat-modal-backdrop <?php echo $open_tourn_modal ? 'active' : ''; ?>" id="sywat_add_tourn_modal">
                <div class="sywat-modal-content" style="max-width: 700px;">
                    <div class="sywat-modal-header no-print">
                        <h3><?php echo ! empty( $edit_t_target ) ? 'Edit Tournament Configuration' : 'Create New Tournament'; ?></h3>
                        <button type="button" class="sywat-modal-close" onclick="closeAddTournamentModal()">&times;</button>
                    </div>
                    <div class="sywat-modal-body">
                        <form method="post" action="?tab=manage">
                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                            <input type="hidden" name="sywat_action" value="save_tournament">
                            <input type="hidden" name="original_tournament_name" value="<?php echo esc_attr( $edit_t_target ); ?>">

                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom: 15px;">
                                <div>
                                    <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Tournament Name *</label>
                                    <input type="text" name="tournament_name" value="<?php echo esc_attr( $edit_t_target ); ?>" placeholder="e.g. CCR October 2026" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;" required>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Host Archery Shop *</label>
                                    <?php if ( $is_admin ): ?>
                                        <select name="shop_id" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; height:37px;" required>
                                            <option value="" <?php selected( empty( $edit_t_shop ), true ); ?>>Select Shop</option>
                                            <?php foreach ( $all_shops as $s_id => $s_item ): ?>
                                                <option value="<?php echo esc_attr( $s_id ); ?>" <?php selected( $edit_t_shop, $s_id ); ?>>
                                                    <?php echo esc_html( $s_item['name'] ); ?> (<?php echo esc_html( $s_id ); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="shop_id" value="<?php echo esc_attr( $user_shop_id ); ?>">
                                        <input type="text" value="<?php echo esc_attr( isset($all_shops[$user_shop_id]['name']) ? $all_shops[$user_shop_id]['name'] : 'Current Shop' ); ?>" readonly style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px; background:#f0f0f1; font-weight:bold;">
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom: 20px;">
                                <div>
                                    <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Start Date *</label>
                                    <input type="date" name="start_date" value="<?php echo esc_attr( $edit_t_start ); ?>" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;" required>
                                </div>
                                <div>
                                    <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">End Date (Optional for multi-day)</label>
                                    <input type="date" name="end_date" value="<?php echo esc_attr( $edit_t_end ); ?>" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;">
                                </div>
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label style="display:block; font-weight:600; margin-bottom:5px; font-size:13px;">Number of Shooting Lines (1 - 6)</label>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <select name="line_count" style="padding:6px 10px; border:1px solid #8c8f94; border-radius:4px; width:130px; height:34px;">
                                        <?php for ($i = 1; $i <= 6; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php selected( $form_line_count, $i ); ?>><?php echo $i; ?> Line<?php echo $i > 1 ? 's' : ''; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <button type="submit" name="sywat_action" value="update_line_rows" class="sywat-btn-primary" formnovalidate>Update Line Rows</button>
                                </div>
                            </div>

                            <h4 style="font-size: 14px; font-weight: 600; margin-top: 15px; margin-bottom: 10px;">Shooting Lines & Capacity Templates</h4>
                            <table class="sywat-table" style="margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                        <th>Line Day / Time</th>
                                        <th style="width: 140px;">20yd Capacity</th>
                                        <th style="width: 140px;">Cub Capacity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($i = 0; $i < $form_line_count; $i++): ?>
                                        <?php 
                                            $fallback  = isset($default_lines[$i]) ? $default_lines[$i] : array( 'name' => 'Line ' . ($i + 1), 'cap_20yd' => 20, 'cap_cub' => 5 );
                                            $line_name = isset($edit_t_lines[$i]['name']) ? $edit_t_lines[$i]['name'] : $fallback['name'];
                                            $cap_20yd  = isset($edit_t_lines[$i]['cap_20yd']) ? $edit_t_lines[$i]['cap_20yd'] : $fallback['cap_20yd'];
                                            $cap_cub   = isset($edit_t_lines[$i]['cap_cub']) ? $edit_t_lines[$i]['cap_cub'] : $fallback['cap_cub'];
                                        ?>
                                        <tr>
                                            <td><input type="text" name="line_names[]" value="<?php echo esc_attr( $line_name ); ?>" placeholder="e.g. Saturday 10AM" style="width:100%; padding:6px; border:1px solid #8c8f94; border-radius:4px;" required></td>
                                            <td><input type="number" name="cap_20yd[]" value="<?php echo esc_attr( $cap_20yd ); ?>" min="0" style="width:100%; padding:6px; border:1px solid #8c8f94; border-radius:4px;" required></td>
                                            <td><input type="number" name="cap_cub[]" value="<?php echo esc_attr( $cap_cub ); ?>" min="0" style="width:100%; padding:6px; border:1px solid #8c8f94; border-radius:4px;" required></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>

                            <div style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="sywat-btn-secondary" onclick="closeAddTournamentModal()">Cancel</button>
                                <button type="submit" name="sywat_action" value="save_tournament" class="sywat-btn-success" style="height:auto; padding:8px 16px;">Save Tournament Configuration</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- MODAL 2: DELEGATED SHOOTER / ROSTER / INVOICE MODAL -->
            <div class="sywat-modal-backdrop <?php echo $open_modal ? 'active' : ''; ?>" id="sywat_modal">
                <div class="sywat-modal-content">
                    <div class="sywat-modal-header no-print">
                        <h3>
                            <?php 
                                if ($modal_action === 'invoice') echo 'TFAA Tournament Invoice';
                                elseif ($modal_action === 'print') echo 'Print Checked In Roster';
                                elseif ($modal_action === 'prereg') echo 'Add Pre-Registrations & Bulk Import';
                                else echo 'Manage Archers';
                            ?>: <?php echo esc_html( $selected_tournament ); ?>
                        </h3>
                        <button type="button" class="sywat-modal-close" onclick="closeSywatModal()">&times;</button>
                    </div>
                    <div class="sywat-modal-body">
                        <?php if ( $open_modal ): ?>
                            <?php 
                                if ( $modal_action === 'prereg' && function_exists( 'sywat_render_registration_modal' ) ) {
                                    sywat_render_registration_modal( $selected_tournament, $available_lines, $registrations );
                                } elseif ( $modal_action === 'manage' && function_exists( 'sywat_render_roster_modal' ) ) {
                                    sywat_render_roster_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $search_query, $modal_edit_archer, $existing_families );
                                } elseif ( $modal_action === 'invoice' && function_exists( 'sywat_render_invoice_modal' ) ) {
                                    sywat_render_invoice_modal( $selected_tournament, $registrations );
                                } elseif ( $modal_action === 'print' && function_exists( 'sywat_render_print_modal' ) ) {
                                    sywat_render_print_modal( $selected_tournament, $available_lines, $registrations, $filter_line_idx, $is_specific_line );
                                }
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function openAddTournamentModal() {
            const modal = document.getElementById('sywat_add_tourn_modal');
            if (modal) modal.classList.add('active');
        }

        function closeAddTournamentModal() {
            const modal = document.getElementById('sywat_add_tourn_modal');
            if (modal) modal.classList.remove('active');
            const url = new URL(window.location.href);
            url.searchParams.delete('open_add_tournament');
            url.searchParams.delete('edit_tournament_modal');
            window.history.replaceState({}, document.title, url.toString());
        }

        function closeSywatModal() {
            const modal = document.getElementById('sywat_modal');
            if (modal) modal.classList.remove('active');
            const url = new URL(window.location.href);
            url.searchParams.delete('view_tournament');
            url.searchParams.delete('modal_action');
            url.searchParams.delete('filter_line_idx');
            url.searchParams.delete('search_shooter');
            window.history.replaceState({}, document.title, url.toString());
        }

        document.addEventListener("DOMContentLoaded", function() {
            const notice = document.getElementById('sywat-notice');
            if (notice) {
                setTimeout(function() {
                    notice.style.transition = "opacity 0.5s ease";
                    notice.style.opacity = "0";
                    setTimeout(function() { notice.remove(); }, 500);
                }, 5000);
            }

            // Client-side tournament search filter
            const tournSearchInput = document.getElementById('sywat_tournament_search_input');
            if (tournSearchInput) {
                tournSearchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase().trim();
                    const rows = document.querySelectorAll('.sywat-tournament-row');
                    
                    rows.forEach(row => {
                        const name = (row.getAttribute('data-name') || '').toLowerCase();
                        const shop = (row.getAttribute('data-shop') || '').toLowerCase();
                        
                        if (name.includes(filter) || shop.includes(filter)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
}

// Volunteer Hub Renderer
if ( ! function_exists( 'render_sywat_volunteer_hub' ) ) {
    function render_sywat_volunteer_hub() {
        $user = wp_get_current_user();
        if ( is_user_logged_in() && ! array_intersect( array( 'administrator', 'management', 'staff_shooter', 'non-shooting-member', 'sywat_shop_manager', 'sywat_shop_user' ), (array) $user->roles ) ) {
            return '<p style="color:red; padding:20px;">Access denied. Staff access required.</p>';
        }

        ob_start();
        global $wpdb;

        $tournaments = get_option( 'sywat_tournaments_list', array() );
        if ( ! is_array( $tournaments ) ) $tournaments = array();

        $active_tournaments = get_option( 'sywat_active_tournaments', array() );
        if ( ! is_array( $active_tournaments ) ) $active_tournaments = array();

        $user_shop_id = function_exists( 'sywat_get_current_user_shop_id' ) ? sywat_get_current_user_shop_id() : 'shop_ccr';
        $is_admin     = current_user_can( 'administrator' );

        $display_tournaments = array();
        foreach ($tournaments as $t_name => $t_data) {
            $t_shop = sywat_get_tournament_shop_id( $t_data );
            if ( in_array( $t_name, $active_tournaments ) && ( $is_admin || $t_shop === $user_shop_id ) ) {
                $display_tournaments[$t_name] = sywat_get_tournament_lines( $t_data );
            }
        }

        $registrations = array();
        $results = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'sywat_t_%'" );
        foreach ($results as $row) {
            $reg_data = maybe_unserialize($row->option_value);
            if (is_array($reg_data) && isset($reg_data['id'])) {
                $registrations[] = $reg_data;
            }
        }

        $count_checked_in = function( $t_name, $l_idx ) use ( &$registrations ) {
            $count = 0;
            foreach ( $registrations as $reg ) {
                if ( isset( $reg['tournament'], $reg['line_index'] )
                    && strcasecmp( $reg['tournament'], $t_name ) === 0
                    && (string)$reg['line_index'] === (string)$l_idx
                    && ! empty( $reg['checked_in'] ) ) {
                    $count++;
                }
            }
            return $count;
        };
        ?>
        <style>
            .sywat-vhub-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 1000px; margin: 0 auto; color: #2c3338; }
            .sywat-vhub-card { background: #ffffff; border: 1px solid #c3c4c7; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 20px; }
            .sywat-vhub-title { font-size: 1.3em; font-weight: 700; color: #1d2327; margin: 0 0 15px 0; border-bottom: 2px solid #2271b1; padding-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
            .sywat-vhub-day-title { font-size: 1.05em; font-weight: 700; color: #1e293b; margin: 15px 0 8px 0; border-bottom: 1px dashed #cbd5e1; padding-bottom: 4px; display: flex; align-items: center; gap: 6px; }
            .sywat-vhub-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 10px; margin-bottom: 15px; }
            @media (max-width: 650px) { .sywat-vhub-grid { grid-template-columns: 1fr; } }
            .sywat-vhub-line-box { background: #f8f9fa; border: 1px solid #dcdcde; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 12px; }
            .sywat-vhub-line-name { font-size: 1.1em; font-weight: 700; color: #1d2327; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
            .sywat-vhub-stat { font-size: 15px; color: #334155; line-height: 1.6; }
            .sywat-vhub-stat-num { font-size: 16px; font-weight: 700; color: #0f172a; }
            .sywat-vhub-actions { display: flex; gap: 10px; margin-top: 10px; }
            .sywat-vhub-btn-checkin { flex: 1; background: #008a20; color: #ffffff !important; border: 1px solid #008a20; text-align: center; padding: 11px 10px; border-radius: 5px; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; }
            .sywat-vhub-btn-scores { flex: 1; background: #2271b1; color: #ffffff !important; border: 1px solid #2271b1; text-align: center; padding: 11px 10px; border-radius: 5px; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; }
            .sywat-vhub-badge { font-size: 13px; padding: 4px 8px; border-radius: 4px; font-weight: 700; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        </style>

        <div class="sywat-vhub-wrap">
            <?php if ( empty( $display_tournaments ) ): ?>
                <div class="sywat-vhub-card"><p style="color:#646970; font-style:italic; margin:0;">No active tournaments currently enabled for the Volunteer Hub.</p></div>
            <?php else: ?>
                <?php foreach ( $display_tournaments as $t_name => $t_lines ): ?>
                    <div class="sywat-vhub-card">
                        <div class="sywat-vhub-title">
                            <span>🎯 <?php echo esc_html( $t_name ); ?></span>
                            <span style="font-size: 12px; padding: 3px 8px; border-radius: 4px; font-weight: 700; background: #e2e8f0; color: #334155;"><?php echo is_array($t_lines) ? count($t_lines) : 0; ?> Lines</span>
                        </div>

                        <?php if ( ! empty( $t_lines ) && is_array( $t_lines ) ): ?>
                            <?php
                                $day_groups = array();
                                foreach ( $t_lines as $idx => $line ) {
                                    $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ($idx + 1);
                                    $day_key = 'Other Lines';
                                    if ( stripos( $line_title, 'Saturday' ) !== false ) $day_key = 'Saturday';
                                    elseif ( stripos( $line_title, 'Sunday' ) !== false ) $day_key = 'Sunday';
                                    elseif ( stripos( $line_title, 'Friday' ) !== false ) $day_key = 'Friday';

                                    $day_groups[$day_key][] = array( 'idx' => $idx, 'line' => $line );
                                }
                            ?>

                            <?php foreach ( $day_groups as $day_label => $group_item_list ): ?>
                                <div class="sywat-vhub-day-title">📅 <?php echo esc_html( $day_label ); ?> Lines</div>
                                <div class="sywat-vhub-grid">
                                    <?php foreach ( $group_item_list as $item ): ?>
                                        <?php 
                                            $idx        = $item['idx'];
                                            $line       = $item['line'];
                                            $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ($idx + 1);

                                            $c_20       = sywat_count_registered( $t_name, $idx, '20yd', $registrations );
                                            $m_20       = sywat_get_line_max_cap( $line, '20yd' );
                                            $rem_20     = max( 0, $m_20 - $c_20 );

                                            $c_cub      = sywat_count_registered( $t_name, $idx, 'Cub', $registrations );
                                            $m_cub      = sywat_get_line_max_cap( $line, 'Cub' );
                                            $rem_cub    = max( 0, $m_cub - $c_cub );

                                            $tot_reg    = $c_20 + $c_cub;
                                            $c_in       = $count_checked_in( $t_name, $idx );

                                            $checkin_url = '/sywat-desk/?selected_tournament=' . urlencode($t_name) . '&selected_line_idx=' . urlencode($idx);
                                            $scores_url  = '/sywat-enter-scores/?selected_tournament=' . urlencode($t_name) . '&selected_line_idx=' . urlencode($idx);
                                        ?>
                                        <div class="sywat-vhub-line-box">
                                            <div>
                                                <div class="sywat-vhub-line-name">
                                                    <span><?php echo esc_html( $line_title ); ?></span>
                                                    <span class="sywat-vhub-badge"><?php echo $c_in . ' / ' . $tot_reg . ' Checked In'; ?></span>
                                                </div>
                                                <div class="sywat-vhub-stat">
                                                    <strong>20yd:</strong> <span class="sywat-vhub-stat-num"><?php echo $c_20 . '/' . $m_20; ?></span> <span style="color:<?php echo ($rem_20 > 0) ? '#008a20' : '#d63638'; ?>; font-weight:600;">(<?php echo $rem_20; ?> open)</span><br>
                                                    <strong>Cub:</strong> <span class="sywat-vhub-stat-num"><?php echo $c_cub . '/' . $m_cub; ?></span> <span style="color:<?php echo ($rem_cub > 0) ? '#008a20' : '#d63638'; ?>; font-weight:600;">(<?php echo $rem_cub; ?> open)</span>
                                                </div>
                                            </div>
                                            <div class="sywat-vhub-actions">
                                                <a href="<?php echo esc_url($checkin_url); ?>" class="sywat-vhub-btn-checkin">📋 Check-In Desk</a>
                                                <a href="<?php echo esc_url($scores_url); ?>" class="sywat-vhub-btn-scores">✏️ Enter Scores</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color:#646970; font-style:italic; margin:0;">No shooting lines configured for this tournament.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
