<?php
/**
 * Module: Master Tournament Portal & Management (Snippet 25)
 * Description: Handles tournament creation, line capacity configuration, FareHarbor/CSV roster
 *              import engine, archer roster editing, host vs. TFAA fee split accounting,
 *              Zelle QR rendering, and print invoice generation.
 * Admin Shortcodes: [sywat_admin], [sywat_manage], or [sywat_manage_tournaments]
 * Volunteer Shortcode: [sywat_volunteer_hub]
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( shortcode_exists( 'sywat_admin' ) )              remove_shortcode( 'sywat_admin' );
if ( shortcode_exists( 'sywat_manage' ) )             remove_shortcode( 'sywat_manage' );
if ( shortcode_exists( 'sywat_manage_tournaments' ) ) remove_shortcode( 'sywat_manage_tournaments' );
if ( shortcode_exists( 'sywat_volunteer_hub' ) )      remove_shortcode( 'sywat_volunteer_hub' );

add_shortcode( 'sywat_admin',              'render_sywat_master_portal' );
add_shortcode( 'sywat_manage',             'render_sywat_master_portal' );
add_shortcode( 'sywat_manage_tournaments', 'render_sywat_master_portal' );
add_shortcode( 'sywat_volunteer_hub',      'render_sywat_volunteer_hub' );

if ( ! function_exists( 'render_sywat_master_portal' ) ) {
    function render_sywat_master_portal() {
        // 0. Permissions Check
        $user = wp_get_current_user();
        if ( is_user_logged_in() && ! array_intersect( array( 'administrator', 'management' ), (array) $user->roles ) ) {
            return '<p style="color:red; padding:20px;">Access denied. Management access required.</p>';
        }

        ob_start();
        global $wpdb;

        // Media Library path for Zelle QR Code Image
        $raw_qr_path  = get_option( 'sywat_zelle_qr_url', '/wp-content/uploads/zelle-qr.png' );
        $zelle_qr_url = ( strpos( $raw_qr_path, 'http' ) === 0 ) ? $raw_qr_path : site_url( $raw_qr_path );

        // 1. Fetch Master Data
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

        $edit_target      = isset( $_REQUEST['edit_tournament'] ) ? sanitize_text_field( $_REQUEST['edit_tournament'] ) : '';
        $form_name        = ! empty( $edit_target ) ? $edit_target : '';
        $form_lines       = ( ! empty( $edit_target ) && isset( $tournaments[$edit_target] ) ) ? $tournaments[$edit_target] : $default_lines;
        $form_line_count  = isset( $_POST['line_count'] ) ? intval( $_POST['line_count'] ) : count( $form_lines );
        if ( $form_line_count < 1 ) $form_line_count = 1;
        if ( $form_line_count > 6 ) $form_line_count = 6;

        $normalize_dist = function( $dist_str ) {
            return ( strcasecmp( trim( $dist_str ), 'cub' ) === 0 || strcasecmp( trim( $dist_str ), '10yd' ) === 0 || strcasecmp( trim( $dist_str ), '10 yards' ) === 0 ) ? 'Cub' : '20yd';
        };

        $get_line_max_cap = function( $line_data, $dist ) {
            if ( $dist === 'Cub' ) {
                return isset( $line_data['cap_cub'] ) ? intval( $line_data['cap_cub'] ) : 5;
            }
            return isset( $line_data['cap_20yd'] ) ? intval( $line_data['cap_20yd'] ) : 20;
        };

        $count_registered = function( $t_name, $l_idx, $dist ) use ( &$registrations, $normalize_dist ) {
            $count = 0;
            foreach ( $registrations as $reg ) {
                if ( isset( $reg['tournament'], $reg['line_index'], $reg['distance'] )
                    && strcasecmp( $reg['tournament'], $t_name ) === 0
                    && (string)$reg['line_index'] === (string)$l_idx
                    && $normalize_dist( $reg['distance'] ) === $dist ) {
                    $count++;
                }
            }
            return $count;
        };

        $detect_paid_category = function($text) {
            $str = is_array($text) ? implode(' ', $text) : (string)$text;
            if (preg_match('/max\.?\s*per\s*family|family/i', $str)) {
                return 'Family';
            }
            if (preg_match('/cub\/youth\/young\s*adult|cub|youth|young\s*adult/i', $str)) {
                return 'Youth';
            }
            if (preg_match('/adult\s*categories|adult/i', $str)) {
                return 'Adult';
            }
            return '';
        };

        $extract_due_amount = function($str) {
            $clean = preg_replace('/[^\d\.]/', '', (string)$str);
            return floatval($clean);
        };

        $divs = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)', 'Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)');
        $genders = array('Male', 'Female');
        $sywat_styles = array(
            'FS – Freestyle',
            'FS Pro – Freestyle Pro',
            'FSL – Freestyle Limited',
            'FSLR – Freestyle Limited Recurve (or Olympic Recurve)',
            'BHFS – Bowhunter Freestyle',
            'MBH – Modern Bowhunter',
            'BB Barebow',
            'BBC – Barebow Compound',
            'BBR – Barebow Recurve',
            'TRAD – Traditional',
            'LB – Longbow',
            'Genesis'
        );

        $calculate_paid_status = function($pay_option, $division) {
            if ($pay_option === 'Family') return 'Family';
            if ($pay_option === 'Field Governor') return 'Field Governor';
            $youth_divs = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)');
            return in_array($division, $youth_divs) ? 'Youth' : 'Adult';
        };

        $sanitize_style_for_division = function($style, $division) {
            if (strpos($style, 'Genesis') !== false && $division !== 'Cub (8 - 11)') {
                return 'FS – Freestyle';
            }
            $is_pro_fsl_trad = (
                strpos($style, 'FS Pro') !== false ||
                strpos($style, 'FSL –') !== false ||
                strpos($style, 'TRAD') !== false ||
                stripos($style, 'Traditional') !== false
            );
            $allowed_adult_senior = array('Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)');
            if ($is_pro_fsl_trad && !in_array($division, $allowed_adult_senior)) {
                return 'FS – Freestyle';
            }
            $is_lb = (strpos($style, 'LB') !== false || stripos($style, 'Longbow') !== false);
            if ($is_lb && $division !== 'Adult (18 - 49)') {
                return 'FS – Freestyle';
            }
            return $style;
        };

        // Helper: Compute Financial Accounting Totals & Invoice Totals
        $calculate_accounting_totals = function($regs_list) {
            $g1_youth  = 0;
            $g2_adult  = 0;
            $g4_fg     = 0;
            $g5_noshow = 0;
            $family_archers = 0;
            $family_groups  = array();

            $youth_divs = array('Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)');

            foreach ($regs_list as $r) {
                $is_checked_in = ! empty( $r['checked_in'] );
                $is_no_show_div = (isset($r['division']) && $r['division'] === 'No Show');

                if ( ! $is_checked_in || $is_no_show_div ) {
                    $g5_noshow++;
                    continue;
                }

                $paid_cat = isset($r['paid']) ? $r['paid'] : '';
                $div      = isset($r['division']) ? $r['division'] : '';

                if ($paid_cat === 'Field Governor') {
                    $g4_fg++;
                } elseif ($paid_cat === 'Family') {
                    $family_archers++;
                    
                    if (!empty($r['family_id'])) {
                        $fam_key = $r['family_id'];
                    } elseif (!empty($r['booking_id'])) {
                        $fam_key = $r['booking_id'];
                    } else {
                        $name_parts = array_values(array_filter(explode(' ', trim($r['name']))));
                        $last_name  = !empty($name_parts) ? strtolower(end($name_parts)) : 'fam';
                        $line_idx   = isset($r['line_index']) ? $r['line_index'] : '0';
                        $fam_key    = 'line_' . $line_idx . '_last_' . $last_name;
                    }
                    $family_groups[$fam_key] = true;

                } else {
                    if (in_array($div, $youth_divs) || $paid_cat === 'Youth') {
                        $g1_youth++;
                    } else {
                        $g2_adult++;
                    }
                }
            }

            $unique_families    = count($family_groups);
            $total_transactions = $g1_youth + $g2_adult + $unique_families + $g4_fg;
            $total_archers      = count($regs_list);
            $total_checked_in   = $total_archers - $g5_noshow;

            $tot_g1 = $g1_youth * 25;
            $tot_g2 = $g2_adult * 30;
            $tot_g3 = $unique_families * 75;
            $tot_fg = $g4_fg * 0;

            $total_sywat_fees = $tot_g1 + $tot_g2 + $tot_g3 + $tot_fg;
            $host_retains     = $total_sywat_fees * 0.50;
            $tfaa_to_pay      = $total_sywat_fees * 0.50;

            return array(
                'group1_youth'       => $g1_youth,
                'tot_g1'             => $tot_g1,
                'group2_adult'       => $g2_adult,
                'tot_g2'             => $tot_g2,
                'group3_families'    => $unique_families,
                'tot_g3'             => $tot_g3,
                'family_archers'     => $family_archers,
                'group4_fg'          => $g4_fg,
                'tot_fg'             => $tot_fg,
                'group5_noshow'      => $g5_noshow,
                'total_transactions' => $total_transactions,
                'total_archers'      => $total_archers,
                'total_checked_in'   => $total_checked_in,
                'total_sywat_fees'   => $total_sywat_fees,
                'host_retains'       => $host_retains,
                'tfaa_to_pay'        => $tfaa_to_pay
            );
        };

        // Active View / Modal States
        $selected_tournament = isset( $_REQUEST['view_tournament'] ) ? sanitize_text_field( $_REQUEST['view_tournament'] ) : '';
        $modal_action        = isset( $_REQUEST['modal_action'] ) ? sanitize_text_field( $_REQUEST['modal_action'] ) : 'manage';
        $open_modal          = ! empty( $selected_tournament ) && isset( $tournaments[$selected_tournament] );

        $available_lines = $open_modal ? $tournaments[$selected_tournament] : array();
        $filter_line_idx = isset( $_REQUEST['filter_line_idx'] ) ? sanitize_text_field( $_REQUEST['filter_line_idx'] ) : 'all';
        $search_query    = isset( $_REQUEST['search_shooter'] ) ? trim( sanitize_text_field( $_REQUEST['search_shooter'] ) ) : '';

        $is_specific_line = ($filter_line_idx !== 'all' && $filter_line_idx !== '' && $filter_line_idx !== null);

        // 2. Action Handlers
        $is_valid_nonce = (isset($_POST['sywat_nonce']) && wp_verify_nonce($_POST['sywat_nonce'], 'sywat_desk_action'));
        $action = isset($_POST['sywat_action']) ? $_POST['sywat_action'] : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action && !$is_valid_nonce && $action !== 'cancel') {
            $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_valid_nonce) {
            
            if ( $action === 'sync_tfaa_now' ) {
                if ( function_exists( 'sywat_sync_tfaa_standings' ) ) {
                    $res = sywat_sync_tfaa_standings();
                    if ( ! empty( $res['success'] ) ) {
                        $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">🟢 <strong>TFAA Standings Synced!</strong> Successfully cached ' . intval($res['count']) . ' active shooters from TFAA.</div>';
                    } else {
                        $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ <strong>Sync Failed:</strong> ' . esc_html( $res['message'] ) . '</div>';
                    }
                } else {
                    $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">⚠️ TFAA Standings Sync snippet is not active. Please activate the <code>sywat-tfaa-sync</code> snippet.</div>';
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
                $t_name    = sanitize_text_field( $_POST['tournament_name'] );
                $old_name  = sanitize_text_field( $_POST['original_tournament_name'] );
                $new_slug  = sanitize_title( $t_name );
                
                // Save explicit tournament dates for invoice
                $new_dates = isset($_POST['tournament_dates']) ? sanitize_text_field($_POST['tournament_dates']) : '';
                update_option('sywat_dates_' . $new_slug, $new_dates);

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

                $tournaments[$t_name] = $new_lines;
                update_option( 'sywat_tournaments_list', $tournaments );
                update_option( 'sywat_active_tournaments', array_values( array_unique( $active_tournaments ) ) );
                update_option( 'sywat_current_tournament', $t_name );
                update_option( 'sywat_line_config', $new_lines );

                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Tournament <strong>' . esc_html( $t_name ) . '</strong> saved successfully!</div>';
                $active_tab = 'manage';
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

            if ( $action === 'delete_shooter' ) {
                $delete_id     = sanitize_text_field( $_POST['registration_id'] );
                $tourn_context = sanitize_text_field( $_POST['view_tournament'] );
                $t_slug        = sanitize_title( $tourn_context );
                
                $deleted_name = '';
                $new_registrations = array();

                foreach ( $registrations as $reg ) {
                    if ( isset( $reg['id'] ) && $reg['id'] === $delete_id ) {
                        $deleted_name = isset( $reg['name'] ) ? $reg['name'] : 'Archer';
                        delete_option( 'sywat_t_' . $t_slug . '_' . $delete_id );
                        continue;
                    }
                    $new_registrations[] = $reg;
                }

                $registrations = $new_registrations;
                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully removed <strong>' . esc_html( $deleted_name ) . '</strong> from the roster.</div>';
                $open_modal   = true;
                $modal_action = 'manage';
            }

            if ( $action === 'save_edit' ) {
                $edit_id       = sanitize_text_field( $_POST['registration_id'] );
                $tourn_context = sanitize_text_field( $_POST['view_tournament'] );
                $t_slug        = sanitize_title( $tourn_context );
                
                $opt_key = 'sywat_t_' . $t_slug . '_' . $edit_id;
                $reg = get_option($opt_key);
                
                if ($reg) {
                    $line_idx  = sanitize_text_field($_POST['edit_line_index']);
                    $line_name = isset($tournaments[$tourn_context][$line_idx]['name']) ? $tournaments[$tourn_context][$line_idx]['name'] : 'N/A';
                    $division  = sanitize_text_field($_POST['edit_division']);
                    $distance  = ($division === 'Cub (8 - 11)') ? 'Cub' : '20yd';
                    $pay_opt   = sanitize_text_field($_POST['edit_pay_option']);
                    $style     = sanitize_text_field($_POST['edit_style']);
                    $due_val   = isset($_POST['edit_balance_due']) ? floatval($_POST['edit_balance_due']) : 0;

                    $reg['name']        = sanitize_text_field($_POST['edit_name']);
                    $reg['line_index']  = $line_idx;
                    $reg['line_name']   = $line_name;
                    $reg['distance']    = $distance;
                    $reg['division']    = $division;
                    $reg['gender']      = sanitize_text_field($_POST['edit_gender']);
                    $reg['style']       = $sanitize_style_for_division($style, $division);
                    $reg['paid']        = $calculate_paid_status($pay_opt, $division);
                    $reg['balance_due'] = $due_val;

                    if ($reg['paid'] === 'Family') {
                        if (!empty($_POST['edit_family_group_id'])) {
                            $reg['family_id'] = sanitize_text_field($_POST['edit_family_group_id']);
                        } elseif (!empty($_POST['edit_new_family_name'])) {
                            $reg['family_id']   = 'fam_' . sanitize_title($_POST['edit_new_family_name']);
                            $reg['family_name'] = sanitize_text_field($_POST['edit_new_family_name']);
                        } elseif (empty($reg['family_id'])) {
                            $reg['family_id']   = uniqid('fam_');
                        }
                    } else {
                        unset($reg['family_id']);
                        unset($reg['family_name']);
                    }

                    update_option($opt_key, $reg, false);

                    foreach ($registrations as &$r) {
                        if (isset($r['id']) && $r['id'] === $edit_id) {
                            $r = $reg;
                            break;
                        }
                    }
                    unset($r);
                    $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully updated archer: <strong>' . esc_html($reg['name']) . '</strong>.</div>';
                }
                $open_modal   = true;
                $modal_action = 'manage';
            }

            if ( $action === 'register_shooter' ) {
                $shooter_name = trim( sanitize_text_field( $_POST['shooter_name'] ) );
                $distance     = $normalize_dist( sanitize_text_field( $_POST['shooter_distance'] ) );
                $type         = sanitize_text_field( $_POST['shooter_type'] );
                $line_idx     = sanitize_text_field( $_POST['target_line_idx'] );
                $tourn_name   = sanitize_text_field( $_POST['selected_tournament'] );
                $t_slug       = sanitize_title( $tourn_name );

                $line_data = isset( $tournaments[$tourn_name][$line_idx] ) ? $tournaments[$tourn_name][$line_idx] : array();
                $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ((int)$line_idx + 1);

                if ( ! empty( $shooter_name ) ) {
                    $is_duplicate = false;
                    foreach ( $registrations as $existing ) {
                        if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] )
                            && strcasecmp ( $existing['tournament'], $tourn_name ) === 0
                            && (string)$existing['line_index'] === (string)$line_idx
                            && strcasecmp ( trim ( $existing['name'] ), $shooter_name ) === 0 
                            && strcasecmp ( $existing['distance'], $distance ) === 0 ) {
                            $is_duplicate = true;
                            break;
                        }
                    }

                    $current_count = $count_registered( $tourn_name, $line_idx, $distance );
                    $max_capacity  = $get_line_max_cap( $line_data, $distance );

                    if ( $is_duplicate ) {
                        $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger"><strong>' . esc_html( $shooter_name ) . '</strong> is already registered for ' . esc_html( $line_name ) . ' (' . esc_html( $distance ) . ').</div>';
                    } elseif ( $current_count >= $max_capacity ) {
                        $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Cannot add archer. <strong>' . esc_html( $line_name ) . '</strong> has reached maximum ' . esc_html( $distance ) . ' capacity (' . $current_count . '/' . $max_capacity . ').</div>';
                    } else {
                        $default_paid = ($distance === 'Cub') ? 'Youth' : 'Adult';
                        $default_div  = ($distance === 'Cub') ? 'Cub (8 - 11)' : 'Adult (18 - 49)';

                        $new_reg = array(
                            'id'          => uniqid( 'reg_' ),
                            'tournament'  => $tourn_name,
                            'line_index'  => $line_idx,
                            'line_name'   => $line_name,
                            'name'        => $shooter_name,
                            'distance'    => $distance,
                            'division'    => $default_div,
                            'paid'        => $default_paid,
                            'balance_due' => 0,
                            'status'      => $type,
                            'created_at'  => current_time( 'mysql' ),
                        );
                        
                        update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                        $registrations[] = $new_reg;

                        $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully added ' . esc_html( $shooter_name ) . ' to ' . esc_html( $line_name ) . ' (' . esc_html( $distance ) . ')!</div>';
                    }
                }
                $open_modal   = true;
                $modal_action = 'prereg';
            }

            if ( $action === 'import_shooters' ) {
                $tourn_name      = sanitize_text_field( $_POST['selected_tournament'] );
                $target_line_idx = isset( $_POST['target_line_idx'] ) ? sanitize_text_field( $_POST['target_line_idx'] ) : '0';
                $t_lines         = isset( $tournaments[$tourn_name] ) ? $tournaments[$tourn_name] : array();
                $t_slug          = sanitize_title( $tourn_name );

                $imported_count = 0;
                $skipped_dup    = 0;
                $skipped_cap    = 0;

                $raw_text_payload = ! empty( $_POST['pasted_text'] ) ? $_POST['pasted_text'] : '';
                $file_day_context = '';

                if ( ! empty( $raw_text_payload ) ) {
                    $lines = explode( "\n", $raw_text_payload );
                    $current_dist = '20yd';
                    $current_paid_context = '';
                    $in_intro = false;

                    $blacklist_phrases = array(
                        'sywat', 'cinnamon creek', 'intro to archery', 'full name', 'adult categories',
                        'cub/youth/young adult'
                    );
                    $blacklist_words = array(
                        'contact', 'total', 'due', 'bookings', 'archers', 'generated'
                    );

                    foreach ( $lines as $raw_line ) {
                        $clean_line = trim( $raw_line );
                        if ( empty( $clean_line ) ) continue;

                        if ( stripos( $clean_line, 'Intro To Archery' ) !== false || stripos( $clean_line, 'Intro to Archery' ) !== false ) {
                            $in_intro = true;
                            continue;
                        }
                        if ( $in_intro ) continue;

                        $detected_hdr_paid = $detect_paid_category($clean_line);
                        if (!empty($detected_hdr_paid)) {
                            $current_paid_context = $detected_hdr_paid;
                        }

                        if ( ( stripos( $clean_line, '10 YARDS' ) !== false || stripos( $clean_line, '10 YD' ) !== false ) && stripos( $clean_line, 'SYWAT' ) !== false ) {
                            $current_dist = 'Cub';
                            continue;
                        }
                        if ( ( stripos( $clean_line, '20 YARDS' ) !== false || stripos( $clean_line, '20 YD' ) !== false ) && stripos( $clean_line, 'SYWAT' ) !== false ) {
                            $current_dist = '20yd';
                            continue;
                        }

                        if ( strpos( $clean_line, ',' ) !== false ) continue;
                        if ( strpos( $clean_line, '@' ) !== false ) continue;
                        if ( strpos( $clean_line, '$' ) !== false ) continue;
                        if ( preg_match( '/[0-9]/', $clean_line ) ) continue;

                        $is_blacklisted = false;
                        foreach ( $blacklist_phrases as $phrase ) {
                            if ( stripos( $clean_line, $phrase ) !== false ) {
                                $is_blacklisted = true;
                                break;
                            }
                        }
                        if ( ! $is_blacklisted ) {
                            foreach ( $blacklist_words as $kw ) {
                                if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/i', $clean_line ) ) {
                                    $is_blacklisted = true;
                                    break;
                                }
                            }
                        }
                        if ( $is_blacklisted ) continue;

                        $clean_line = trim( ltrim( $clean_line, '| ' ) );
                        if ( strlen( $clean_line ) < 2 || strlen( $clean_line ) > 60 ) continue;

                        $line_idx  = $target_line_idx;
                        $line_data = isset( $t_lines[$line_idx] ) ? $t_lines[$line_idx] : array();
                        $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line 1';

                        $dist_val = $normalize_dist( $current_dist );

                        $is_duplicate = false;
                        foreach ( $registrations as $existing ) {
                            if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] )
                                && strcasecmp ( $existing['tournament'], $tourn_name ) === 0
                                && (string)$existing['line_index'] === (string)$line_idx
                                && strcasecmp ( trim ( $existing['name'] ), $clean_line ) === 0 
                                && strcasecmp ( $existing['distance'], $dist_val ) === 0 ) {
                                $is_duplicate = true;
                                break;
                            }
                        }

                        if ( $is_duplicate ) {
                            $skipped_dup++;
                            continue;
                        }

                        $current_count = $count_registered( $tourn_name, $line_idx, $dist_val );
                        $max_capacity  = $get_line_max_cap( $line_data, $dist_val );

                        if ( $current_count >= $max_capacity ) {
                            $skipped_cap++;
                            continue;
                        }

                        $final_paid = !empty($current_paid_context) ? $current_paid_context : ($dist_val === 'Cub' ? 'Youth' : 'Adult');
                        $final_div  = ($final_paid === 'Youth') ? ($dist_val === 'Cub' ? 'Cub (8 - 11)' : 'Youth (12 - 14)') : 'Adult (18 - 49)';

                        $shared_fam_id = ($final_paid === 'Family') ? uniqid('fam_') : '';

                        $new_reg = array(
                            'id'          => uniqid( 'reg_' ),
                            'tournament'  => $tourn_name,
                            'line_index'  => $line_idx,
                            'line_name'   => $line_name,
                            'name'        => $clean_line,
                            'distance'    => $dist_val,
                            'division'    => $final_div,
                            'paid'        => $final_paid,
                            'family_id'   => $shared_fam_id,
                            'balance_due' => 0,
                            'status'      => 'Pre-Reg',
                            'created_at'  => current_time( 'mysql' ),
                        );
                        
                        update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                        $registrations[] = $new_reg;
                        $imported_count++;
                    }

                } elseif ( isset( $_FILES['import_file'] ) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK ) {
                    $handle = fopen( $_FILES['import_file']['tmp_name'], 'r' );
                    if ( $handle !== false ) {
                        
                        $base_line_name = isset($t_lines[$target_line_idx]['name']) ? $t_lines[$target_line_idx]['name'] : '';
                        $day_context = ! empty( $file_day_context ) ? $file_day_context : '';
                        if ( empty( $day_context ) ) {
                            if (stripos($base_line_name, 'Sunday') !== false) $day_context = 'Sunday';
                            elseif (stripos($base_line_name, 'Saturday') !== false) $day_context = 'Saturday';
                            elseif (stripos($base_line_name, 'Friday') !== false) $day_context = 'Friday';
                        }

                        $row_index = 0;
                        $is_fh_format = false;
                        $booking_col_idx = -1;
                        $name_col_idx = -1;
                        $contact_col_idx = -1;
                        $pax_col_idx  = -1;
                        $due_col_idx  = -1;
                        $current_dist = '20yd';

                        while ( ( $data = fgetcsv( $handle, 4000, ',' ) ) !== false ) {
                            $row_index++;
                            if ( empty( array_filter( $data ) ) ) continue;

                            $first_cell  = isset($data[0]) ? trim($data[0]) : '';
                            $second_cell = isset($data[1]) ? trim($data[1]) : ''; 

                            $is_section_header = false;

                            if ( stripos($first_cell, '10 YARDS') !== false || stripos($first_cell, '10 YD') !== false ) {
                                $current_dist = 'Cub';
                                $is_section_header = true;
                            } elseif ( stripos($first_cell, '20 YARDS') !== false || stripos($first_cell, '20 YD') !== false ) {
                                $current_dist = '20yd';
                                $is_section_header = true;
                            }

                            if ( $is_section_header ) {
                                if ( preg_match('/([0-9]{1,2}):[0-9]{2}\s*(AM|PM)/i', $second_cell, $time_matches) ) {
                                    $hour = $time_matches[1];
                                    $ampm = strtoupper($time_matches[2]);
                                    $search_str = $hour . $ampm;
                                    
                                    if ( ! empty( $t_lines ) ) {
                                        foreach ( $t_lines as $idx => $l_data ) {
                                            $check_name = isset($l_data['name']) ? $l_data['name'] : '';
                                            
                                            if ( $day_context && stripos($check_name, $day_context) === false ) {
                                                continue; 
                                            }

                                            if ( stripos( str_replace(' ', '', $check_name), $search_str ) !== false ) {
                                                $target_line_idx = (string)$idx;
                                                break;
                                            }
                                        }
                                    }
                                }
                                continue;
                            }

                            $is_header_row = false;
                            foreach ($data as $idx => $val) {
                                if (strcasecmp(trim($val), 'Booking ID') === 0) { $booking_col_idx = $idx; $is_header_row = true; }
                                if (strcasecmp(trim($val), 'Full Name') === 0) { $name_col_idx = $idx; $is_header_row = true; }
                                if (strcasecmp(trim($val), 'Contact') === 0) { $contact_col_idx = $idx; $is_header_row = true; }
                                if (strcasecmp(trim($val), 'Pax') === 0 || strcasecmp(trim($val), 'Customers') === 0) { $pax_col_idx = $idx; }
                                if (strcasecmp(trim($val), 'Due') === 0) { $due_col_idx = $idx; }
                            }

                            if ($is_header_row) {
                                $is_fh_format = true;
                                continue;
                            }

                            $raw_names = '';
                            $booking_id_val = ($booking_col_idx !== -1 && isset($data[$booking_col_idx])) ? trim($data[$booking_col_idx]) : '';
                            $matched_line_idx  = $target_line_idx; 
                            $dist_val  = $normalize_dist( $current_dist ); 

                            $row_paid_cat = $detect_paid_category($data);
                            $row_due_val  = ($due_col_idx !== -1 && isset($data[$due_col_idx])) ? $extract_due_amount($data[$due_col_idx]) : 0;

                            if ($is_fh_format) {
                                if ($name_col_idx !== -1 && isset($data[$name_col_idx])) {
                                    $raw_names = trim($data[$name_col_idx]);
                                }
                                
                                if (empty($raw_names) && $contact_col_idx !== -1 && isset($data[$contact_col_idx])) {
                                    $contact_cell = trim($data[$contact_col_idx]);
                                    $contact_parts = preg_split('/\r\n|\r|\n/', $contact_cell);
                                    if (!empty($contact_parts)) {
                                        $raw_names = trim($contact_parts[0]);
                                    }
                                }
                                
                                if (empty($raw_names) || strtolower(trim($raw_names)) === 'full name') continue;
                            } else {
                                $col_name     = isset( $data[0] ) ? trim( sanitize_text_field( $data[0] ) ) : '';
                                $col_line     = isset( $data[1] ) ? trim( sanitize_text_field( $data[1] ) ) : '';
                                $col_distance = isset( $data[2] ) ? trim( sanitize_text_field( $data[2] ) ) : '';

                                if ( $row_index === 1 && ( strcasecmp( $col_name, 'name' ) === 0 || strcasecmp( $col_name, 'shooter name' ) === 0 ) ) {
                                    continue;
                                }
                                
                                $raw_names = $col_name;

                                if ( ! empty( $col_line ) && ! empty( $t_lines ) ) {
                                    foreach ( $t_lines as $idx => $l_data ) {
                                        if ( isset( $l_data['name'] ) && strcasecmp( trim( $l_data['name'] ), $col_line ) === 0 ) {
                                            $matched_line_idx  = (string)$idx;
                                            break;
                                        }
                                    }
                                }
                                
                                if (!empty($col_distance)) {
                                    $dist_val = $normalize_dist( $col_distance );
                                }
                            }

                            if (empty($raw_names)) continue;

                            $is_blacklisted_row = false;
                            $blacklist_words = array('total', 'bookings', 'archers', 'generated');
                            foreach ($blacklist_words as $kw) {
                                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $raw_names) || (isset($data[0]) && preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $data[0]))) {
                                    $is_blacklisted_row = true;
                                    break;
                                }
                            }
                            if ($is_blacklisted_row) continue;

                            $matched_line_name = isset( $t_lines[$matched_line_idx]['name'] ) ? $t_lines[$matched_line_idx]['name'] : 'Line 1';
                            $line_data = isset( $t_lines[$matched_line_idx] ) ? $t_lines[$matched_line_idx] : array();

                            $split_names = array();
                            $pax = ($pax_col_idx !== -1 && isset($data[$pax_col_idx])) ? intval($data[$pax_col_idx]) : 1;

                            if ($is_fh_format) {
                                $clean_separators = str_ireplace(array(' and ', ' & '), ',', $raw_names);
                                
                                if (strpos($clean_separators, ',') !== false) {
                                    $parts = explode(',', $clean_separators);
                                    foreach ($parts as $p) {
                                        if (trim($p)) $split_names[] = trim($p);
                                    }
                                } elseif ($pax > 1) {
                                    $words = array_values(array_filter(explode(' ', $raw_names)));
                                    
                                    if (count($words) >= ($pax * 2)) {
                                        $words_per_name = floor(count($words) / $pax);
                                        $extra_words = count($words) % $pax;
                                        $offset = 0;
                                        for ($i = 0; $i < $pax; $i++) {
                                            $length = $words_per_name + ($i < $extra_words ? 1 : 0);
                                            $split_names[] = implode(' ', array_slice($words, $offset, $length));
                                            $offset += $length;
                                        }
                                    } else {
                                        for ($i = 1; $i <= $pax; $i++) {
                                            $split_names[] = trim($raw_names) . " ($i)";
                                        }
                                    }
                                } else {
                                    $split_names[] = $raw_names;
                                }
                            } else {
                                $split_names = preg_split('/\r\n|\r|\n/', $raw_names);
                            }

                            $final_paid = !empty($row_paid_cat) ? $row_paid_cat : ($dist_val === 'Cub' ? 'Youth' : 'Adult');
                            $shared_fam_id = ($final_paid === 'Family') ? (!empty($booking_id_val) ? $booking_id_val : uniqid('fam_')) : '';
                            
                            foreach ($split_names as $single_name) {
                                $clean_name = trim(sanitize_text_field($single_name));
                                if (empty($clean_name)) continue;
                                
                                $is_duplicate = false;
                                foreach ( $registrations as $existing ) {
                                    if ( isset ( $existing['tournament'], $existing['line_index'], $existing['name'], $existing['distance'] )
                                        && strcasecmp ( $existing['tournament'], $tourn_name ) === 0
                                        && (string)$existing['line_index'] === (string)$matched_line_idx
                                        && strcasecmp ( trim ( $existing['name'] ), $clean_name ) === 0 
                                        && strcasecmp ( $existing['distance'], $dist_val ) === 0 ) {
                                        $is_duplicate = true;
                                        break;
                                    }
                                }

                                if ( $is_duplicate ) {
                                    $skipped_dup++;
                                    continue;
                                }

                                $current_count = $count_registered( $tourn_name, $matched_line_idx, $dist_val );
                                $max_capacity  = $get_line_max_cap( $line_data, $dist_val );

                                if ( $current_count >= $max_capacity ) {
                                    $skipped_cap++;
                                    continue;
                                }

                                $final_div  = ($final_paid === 'Youth') ? ($dist_val === 'Cub' ? 'Cub (8 - 11)' : 'Youth (12 - 14)') : 'Adult (18 - 49)';

                                $new_reg = array(
                                    'id'          => uniqid( 'reg_' ),
                                    'tournament'  => $tourn_name,
                                    'line_index'  => $matched_line_idx,
                                    'line_name'   => $matched_line_name,
                                    'name'        => $clean_name,
                                    'distance'    => $dist_val,
                                    'division'    => $final_div,
                                    'paid'        => $final_paid,
                                    'booking_id'  => $booking_id_val,
                                    'family_id'   => $shared_fam_id,
                                    'balance_due' => $row_due_val,
                                    'status'      => 'Pre-Reg',
                                    'created_at'  => current_time( 'mysql' ),
                                );

                                update_option( 'sywat_t_' . $t_slug . '_' . $new_reg['id'], $new_reg, false );
                                $registrations[] = $new_reg;
                                $imported_count++;
                            }
                        }
                        fclose( $handle );
                    }
                }

                $msg = 'Successfully imported ' . $imported_count . ' shooter(s) as Pre-Reg.';
                if ( $skipped_dup > 0 ) $msg .= ' Skipped ' . $skipped_dup . ' duplicate(s).';
                if ( $skipped_cap > 0 ) $msg .= ' Skipped ' . $skipped_cap . ' due to capacity limits.';
                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">' . esc_html( $msg ) . '</div>';

                $open_modal   = true;
                $modal_action = 'prereg';
            }

            if ( $action === 'purge_line' ) {
                $tourn_name = sanitize_text_field( $_POST['selected_tournament'] );
                $line_idx   = sanitize_text_field( $_POST['target_line_idx'] );
                $t_slug     = sanitize_title( $tourn_name );

                $line_data = isset( $tournaments[$tourn_name][$line_idx] ) ? $tournaments[$tourn_name][$line_idx] : array();
                $line_name = isset( $line_data['name'] ) ? $line_data['name'] : 'Line ' . ((int)$line_idx + 1);

                $purged_count = 0;
                foreach ( $registrations as $k => $reg ) {
                    if ( isset( $reg['tournament'], $reg['line_index'] )
                        && strcasecmp( $reg['tournament'], $tourn_name ) === 0
                        && (string)$reg['line_index'] === (string)$line_idx ) {
                        
                        delete_option( 'sywat_t_' . $t_slug . '_' . $reg['id'] );
                        $purged_count++;
                        unset($registrations[$k]);
                    }
                }

                $registrations = array_values($registrations);
                $notice = '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Successfully cleared <strong>' . $purged_count . '</strong> archer(s) from <strong>' . esc_html( $line_name ) . '</strong>.</div>';
                $open_modal   = true;
                $modal_action = isset( $_POST['redirect_action'] ) ? sanitize_text_field( $_POST['redirect_action'] ) : 'prereg';
            }
        }

        $get_tournament_count = function( $t_name ) use ( &$registrations ) {
            $count = 0;
            foreach ( $registrations as $reg ) {
                if ( isset( $reg['tournament'] ) && strcasecmp( $reg['tournament'], $t_name ) === 0 ) {
                    $count++;
                }
            }
            return $count;
        };

        $modal_edit_id = isset( $_REQUEST['open_edit_id'] ) ? sanitize_text_field( $_REQUEST['open_edit_id'] ) : '';
        $modal_edit_archer = null;
        if ( $modal_edit_id ) {
            foreach ( $registrations as $reg ) {
                if ( isset( $reg['id'] ) && $reg['id'] === $modal_edit_id ) {
                    $modal_edit_archer = $reg;
                    break;
                }
            }
        }

        $filtered_registrations = array();
        if ( $open_modal && ! $modal_edit_archer ) {
            $filtered_registrations = array_filter( $registrations, function( $reg ) use ( $selected_tournament, $filter_line_idx, $available_lines ) {
                if ( ! isset( $reg['tournament'] ) || strcasecmp( $reg['tournament'], $selected_tournament ) !== 0 ) {
                    return false;
                }
                if ( $filter_line_idx !== 'all' ) {
                    $match_idx  = isset( $reg['line_index'] ) && (string)$reg['line_index'] === (string)$filter_line_idx;
                    $match_name = isset( $available_lines[$filter_line_idx]['name'] ) && isset( $reg['line_name'] ) && strcasecmp( trim( $reg['line_name'] ), trim( $available_lines[$filter_line_idx]['name'] ) ) === 0;
                    if ( ! $match_idx && ! $match_name ) {
                        return false;
                    }
                }
                return true;
            });

            usort( $filtered_registrations, function( $a, $b ) {
                $name_a = isset( $a['name'] ) ? trim( $a['name'] ) : '';
                $name_b = isset( $b['name'] ) ? trim( $b['name'] ) : '';
                return strcasecmp( $name_a, $name_b );
            });
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
            .sywat-card-title { font-size: 1.1em; font-weight: 600; color: #1d2327; margin: 0 0 15px 0; border-bottom: 1px solid #dcdcde; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
            .sywat-subtitle { font-size: 13px; color: #646970; margin-top: -10px; margin-bottom: 15px; }
            
            .sywat-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
            .sywat-table th { background-color: #f6f7f7; text-align: left; padding: 10px 12px; font-weight: 600; color: #1d2327; border-bottom: 1px solid #c3c4c7; }
            .sywat-table td { padding: 12px; border-bottom: 1px solid #f0f0f1; color: #2c3338; vertical-align: middle; }
            
            .sywat-btn-primary { background-color: #2271b1; border: 1px solid #2271b1; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; text-decoration: none; font-size: 13px; height: 32px; white-space: nowrap; box-sizing: border-box; }
            .sywat-btn-primary, .sywat-btn-primary:visited, .sywat-btn-primary:focus, .sywat-btn-primary:active { color: #ffffff !important; }
            .sywat-btn-primary:hover { background-color: #135e96; border-color: #135e96; color: #ffffff !important; }
            
            .sywat-btn-secondary { background-color: #ffffff; border: 1px solid #2271b1; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; text-decoration: none; font-size: 13px; height: 32px; white-space: nowrap; box-sizing: border-box; }
            .sywat-btn-secondary, .sywat-btn-secondary:visited, .sywat-btn-secondary:focus, .sywat-btn-secondary:active { color: #2271b1 !important; }
            .sywat-btn-secondary:hover { background-color: #2271b1; color: #ffffff !important; }

            .sywat-btn-success { background-color: #008a20; border: 1px solid #008a20; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; text-decoration: none; font-size: 13px; height: 32px; white-space: nowrap; box-sizing: border-box; }
            .sywat-btn-success, .sywat-btn-success:visited, .sywat-btn-success:focus, .sywat-btn-success:active { color: #ffffff !important; }
            .sywat-btn-success:hover { background-color: #007017; border-color: #007017; color: #ffffff !important; }

            .sywat-btn-danger { background-color: #dc3545; border: 1px solid #dc3545; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; text-decoration: none; font-size: 13px; height: 32px; white-space: nowrap; box-sizing: border-box; }
            .sywat-btn-danger, .sywat-btn-danger:visited, .sywat-btn-danger:focus, .sywat-btn-danger:active { color: #ffffff !important; }
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
            
            .sywat-filter-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 15px; }
            .sywat-form-group { display: flex; flex-direction: column; flex: 1; min-width: 140px; }
            .sywat-form-group label { font-size: 12px; font-weight: 600; color: #1d2327; margin-bottom: 4px; }
            .sywat-form-group select, .sywat-form-group input[type="text"], .sywat-form-group input[type="number"], .sywat-form-group input[type="file"], .sywat-form-group textarea { width: 100%; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; background-color: #fff; box-sizing: border-box; }

            @media print {
                @page {
                    size: letter <?php echo ( $modal_action === 'print' ) ? 'landscape' : 'portrait'; ?>;
                    margin: 0.4in;
                }

                /* MULTI-PAGE SAFE PRINT CSS */
                #wpadminbar, #adminmenumain, #wpfooter, 
                header, footer, nav, aside, iframe, .no-print,
                .ast-site-header, .site-header, .ast-footer, .site-footer, .site-branding,
                .update-nag, .notice {
                    display: none !important;
                }

                html, body, #wpwrap, #wpcontent, #wpbody, #wpbody-content, 
                #page, #content, #primary, main, .ast-container,
                .sywat-wrap, .sywat-modal-backdrop, .sywat-modal-content, .sywat-modal-body {
                    position: static !important;
                    display: block !important;
                    float: none !important;
                    width: 100% !important;
                    height: auto !important;
                    max-height: none !important;
                    min-height: 0 !important;
                    overflow: visible !important;
                    background: #ffffff !important;
                    box-shadow: none !important;
                    border: none !important;
                    padding: 0 !important;
                    margin: 0 !important;
                    transform: none !important;
                }

                .sywat-modal-header, .sywat-modal-body > :not(#sywat_print_area) {
                    display: none !important;
                }

                #sywat_print_area {
                    display: block !important;
                    position: static !important; 
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    overflow: visible !important;
                }

                .sywat-table, .invoice-fee-table {
                    width: 100% !important;
                    border-collapse: collapse !important;
                    table-layout: auto !important;
                    page-break-after: auto !important;
                }
                .sywat-table thead, .invoice-fee-table thead {
                    display: table-header-group !important;
                }
                .sywat-table tr, .invoice-fee-table tr {
                    page-break-inside: avoid !important;
                    break-inside: avoid !important;
                }
                .sywat-table th, .invoice-fee-table th {
                    background-color: #f1f5f9 !important;
                    border: 1px solid #000 !important;
                    padding: 4px 6px !important;
                    font-size: 10px !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .sywat-table td, .invoice-fee-table td { 
                    border: 1px solid #000 !important; 
                    padding: 3px 5px !important;
                    font-size: 9px !important; 
                    vertical-align: middle !important;
                }
            }
        </style>

        <div class="sywat-wrap">
            <div class="no-print">
                <?php echo $notice; ?>
            </div>

            <!-- NAVIGATION TABS -->
            <div class="sywat-nav-tabs no-print">
                <a href="?tab=manage" class="sywat-tab-item <?php echo $active_tab === 'manage' ? 'active' : ''; ?>">
                    Tournaments & Rosters
                </a>
                <a href="?tab=create" class="sywat-tab-item <?php echo $active_tab === 'create' ? 'active' : ''; ?>">
                    <?php echo ! empty( $edit_target ) ? 'Edit Tournament Config' : 'Create New Tournament'; ?>
                </a>
            </div>

            <?php if ( $active_tab === 'manage' ): ?>
                <!-- TAB 1: TOURNAMENTS TABLE -->
                <div class="sywat-card no-print">
                    <div class="sywat-card-title">
                        <span>Configured Tournaments</span>
                        <form method="post" style="margin: 0; display: inline-flex; align-items: center; gap: 10px;">
                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                            <input type="hidden" name="sywat_action" value="sync_tfaa_now">
                            <span style="font-size: 12px; color: #646970; font-weight: normal;">
                                TFAA Standings Cache: <strong><?php echo $tfaa_count; ?></strong> Shooters (Updated: <?php echo esc_html($tfaa_updated); ?>)
                            </span>
                            <button type="submit" class="sywat-btn-secondary" style="height: 30px; font-size: 12px;">🔄 Sync TFAA Standings Now</button>
                        </form>
                    </div>
                    <div class="sywat-subtitle">Overview of configured tournaments, shooting lines, and archer administration.</div>

                    <?php if ( empty( $tournaments ) ): ?>
                        <p style="color:#646970; font-style:italic;">No tournaments created yet. Click on <strong>"Create New Tournament"</strong> to configure your first tournament.</p>
                    <?php else: ?>
                        <table class="sywat-table">
                            <thead>
                                <tr>
                                    <th style="width: 20%;">Tournament Name</th>
                                    <th style="width: 14%;">Volunteer Hub</th>
                                    <th style="width: 35%;">Line Slot Availability (20yd / Cub)</th>
                                    <th style="width: 17%;">Shooter Management</th>
                                    <th style="width: 14%; text-align: right;">Tournament Management</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $tournaments as $t_name => $t_lines ): ?>
                                    <?php 
                                        $line_count = is_array( $t_lines ) ? count( $t_lines ) : 0;
                                        $reg_count  = $get_tournament_count( $t_name );
                                        $is_active_hub = in_array( $t_name, $active_tournaments );
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html( $t_name ); ?></strong>
                                            <div style="font-size: 12px; color: #646970; margin-top: 2px;">
                                                <?php echo $line_count . ' ' . ( $line_count === 1 ? 'line' : 'lines' ); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <form method="post" style="margin: 0;">
                                                <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                <input type="hidden" name="sywat_action" value="toggle_active_tournament">
                                                <input type="hidden" name="tournament_name" value="<?php echo esc_attr( $t_name ); ?>">
                                                <?php if ( $is_active_hub ): ?>
                                                    <button type="submit" class="sywat-btn-success" style="padding: 4px 8px; font-size: 11px; height: auto;" title="Click to hide from Volunteer Hub">🟢 Active (Hide)</button>
                                                <?php else: ?>
                                                    <button type="submit" class="sywat-btn-secondary" style="padding: 4px 8px; font-size: 11px; height: auto;" title="Click to show on Volunteer Hub">⚪ Hidden (Activate)</button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if ( ! empty( $t_lines ) && is_array( $t_lines ) ): ?>
                                                <div style="display: flex; flex-direction: column; gap: 5px;">
                                                    <?php foreach ( $t_lines as $idx => $line ): ?>
                                                        <?php 
                                                            $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ($idx + 1);
                                                            $c_20   = $count_registered( $t_name, $idx, '20yd' );
                                                            $m_20   = $get_line_max_cap( $line, '20yd' );
                                                            $rem_20 = max( 0, $m_20 - $c_20 );

                                                            $c_cub   = $count_registered( $t_name, $idx, 'Cub' );
                                                            $m_cub   = $get_line_max_cap( $line, 'Cub' );
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
                                            <?php else: ?>
                                                <span style="color: #646970; font-style: italic; font-size: 13px;">No lines configured</span>
                                            <?php endif; ?>
                                            <div style="margin-top: 8px; padding-top: 6px; border-top: 1px dashed #c3c4c7; font-size: 12px; color: #1d2327;">
                                                <strong>Total Registered:</strong> <?php echo $reg_count; ?> <?php echo ( $reg_count === 1 ? 'archer' : 'archers' ); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=manage" class="sywat-btn-primary" style="width: 100%; justify-content: center;">
                                                    Manage Shooters
                                                </a>
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=prereg" class="sywat-btn-primary" style="width: 100%; justify-content: center;">
                                                    Add Pre-Reg / CSV
                                                </a>
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=print" class="sywat-btn-primary" style="width: 100%; justify-content: center;">
                                                    Print Roster
                                                </a>
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode( $t_name ); ?>&modal_action=invoice" class="sywat-btn-primary" style="width: 100%; justify-content: center; background:#0284c7; border-color:#0284c7;">
                                                    📄 Generate Invoice
                                                </a>
                                            </div>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-end;">
                                                <a href="/sywat-desk/" class="sywat-btn-success" style="width: 100%; max-width: 130px; justify-content: center;">
                                                    Check-In Desk
                                                </a>
                                                <a href="/sywat-enter-scores/?selected_tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn-primary" style="width: 100%; max-width: 130px; justify-content: center;">
                                                    Enter Scores
                                                </a>
                                                <a href="?tab=create&edit_tournament=<?php echo urlencode( $t_name ); ?>" class="sywat-btn-primary" style="width: 100%; max-width: 130px; justify-content: center;">
                                                    Edit Config
                                                </a>
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

            <?php else: ?>
                <!-- TAB 2: CREATE / EDIT TOURNAMENT FORM -->
                <div class="sywat-card no-print">
                    <h2 class="sywat-card-title"><?php echo ! empty( $edit_target ) ? 'Edit Tournament: ' . esc_html( $edit_target ) : 'Create New Tournament'; ?></h2>
                    <div class="sywat-subtitle">Define tournament name, line times, and archer capacity limits.</div>

                    <form method="post" action="?tab=create<?php echo ! empty( $edit_target ) ? '&edit_tournament=' . urlencode($edit_target) : ''; ?>">
                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                        <input type="hidden" name="sywat_action" value="save_tournament">
                        <input type="hidden" name="original_tournament_name" value="<?php echo esc_attr( $form_name ); ?>">

                        <div style="margin-bottom: 20px;">
                            <label style="display:block; font-weight:600; margin-bottom:5px;">Tournament Name</label>
                            <input type="text" name="tournament_name" value="<?php echo esc_attr( $form_name ); ?>" placeholder="e.g. CCR October 2026" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;" required>
                        </div>

                        <?php $t_dates = get_option('sywat_dates_' . sanitize_title($form_name), ''); ?>
                        <div style="margin-bottom: 20px;">
                            <label style="display:block; font-weight:600; margin-bottom:5px;">Tournament Dates (For Invoice)</label>
                            <input type="text" name="tournament_dates" value="<?php echo esc_attr( $t_dates ); ?>" placeholder="e.g. Oct 24 & 25, 2026" style="width:100%; padding:8px; border:1px solid #8c8f94; border-radius:4px;">
                            <span style="font-size:11px; color:#646970;">The exact date string you want printed on the invoice (e.g. "Oct 24 & 25, 2026").</span>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display:block; font-weight:600; margin-bottom:5px;">Number of Shooting Lines (1 - 6)</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <select name="line_count" style="padding:6px 10px; border:1px solid #8c8f94; border-radius:4px; width:130px; height:34px;">
                                    <?php for ($i = 1; $i <= 6; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected( $form_line_count, $i ); ?>><?php echo $i; ?> Line<?php echo $i > 1 ? 's' : ''; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <button type="submit" class="sywat-btn-primary">Update Line Rows</button>
                            </div>
                        </div>

                        <h3 style="font-size: 15px; font-weight: 600; margin-top: 25px; margin-bottom: 10px;">Shooting Lines & Capacity Configuration</h3>
                        <table class="sywat-table" style="margin-bottom: 20px;">
                            <thead>
                                <tr>
                                    <th>Line Day / Time</th>
                                    <th style="width: 180px;">20yd Capacity</th>
                                    <th style="width: 180px;">Cub Capacity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i = 0; $i < $form_line_count; $i++): ?>
                                    <?php 
                                        $fallback  = isset($default_lines[$i]) ? $default_lines[$i] : array( 'name' => 'Line ' . ($i + 1), 'cap_20yd' => 20, 'cap_cub' => 5 );
                                        $line_name = isset($form_lines[$i]['name']) ? $form_lines[$i]['name'] : $fallback['name'];
                                        $cap_20yd  = isset($form_lines[$i]['cap_20yd']) ? $form_lines[$i]['cap_20yd'] : $fallback['cap_20yd'];
                                        $cap_cub   = isset($form_lines[$i]['cap_cub']) ? $form_lines[$i]['cap_cub'] : $fallback['cap_cub'];
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="text" name="line_names[]" value="<?php echo esc_attr( $line_name ); ?>" placeholder="e.g. Saturday 10AM" style="width:100%; padding:6px; border:1px solid #8c8f94; border-radius:4px;" required>
                                        </td>
                                        <td>
                                            <input type="number" name="cap_20yd[]" value="<?php echo esc_attr( $cap_20yd ); ?>" min="0" style="width:100%; padding:6px; border:1px solid #8c8f94; border-radius:4px;" required>
                                        </td>
                                        <td>
                                            <input type="number" name="cap_cub[]" value="<?php echo esc_attr( $cap_cub ); ?>" min="0" style="width:100%; padding:6px; border:1px solid #8c8f94; border-radius:4px;" required>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>

                        <button type="submit" class="sywat-btn-primary" style="height: auto; padding: 10px 20px; font-size: 14px;">
                            Save Tournament & Config
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- POP-UP MODAL WINDOW -->
            <div class="sywat-modal-backdrop <?php echo $open_modal ? 'active' : ''; ?>" id="sywat_modal">
                <div class="sywat-modal-content">
                    <div class="sywat-modal-header no-print">
                        <h3>
                            <?php 
                                if ($modal_action === 'invoice') echo 'TFAA Tournament Invoice';
                                elseif ($modal_action === 'print') echo 'Print Checked In Roster';
                                elseif ($modal_action === 'prereg') echo 'Add Pre-Registrations & Bulk Import';
                                else echo 'Manage Archers';
                            ?>: 
                            <?php echo esc_html( $selected_tournament ); ?>
                        </h3>
                        <button type="button" class="sywat-modal-close" onclick="closeSywatModal()">&times;</button>
                    </div>
                    
                    <div class="sywat-modal-body">
                        <div class="no-print">
                            <?php if ( ! empty( $notice ) ): ?>
                                <div style="margin-bottom: 15px;"><?php echo $notice; ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if ( $open_modal ): ?>
                            
                            <?php if ( $modal_action === 'prereg' ): ?>
                                <!-- MODAL ACTION 1: PRE-REG QUICK ADD & FILE IMPORT -->
                                <div class="no-print" style="display:flex; flex-direction:column; gap:20px;">
                                    <div style="background:#f6f7f7; padding:15px; border:1px solid #c3c4c7; border-radius:4px;">
                                        <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:600;">1. Quick Add Single Archer</h4>
                                        <form method="post">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_action" value="register_shooter">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="modal_action" value="prereg">

                                            <div class="sywat-filter-row" style="margin-bottom:0;">
                                                <div class="sywat-form-group" style="flex:2;">
                                                    <label>Archer Name *</label>
                                                    <input type="text" name="shooter_name" placeholder="Full Name" required>
                                                </div>
                                                <div class="sywat-form-group">
                                                    <label>Line</label>
                                                    <select name="target_line_idx" required>
                                                        <?php foreach ( $available_lines as $idx => $line ): ?>
                                                            <option value="<?php echo esc_attr( $idx ); ?>">
                                                                <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="sywat-form-group" style="min-width:90px; flex:0.8;">
                                                    <label>Distance</label>
                                                    <select name="shooter_distance" required>
                                                        <option value="20yd">20yd</option>
                                                        <option value="Cub">Cub</option>
                                                    </select>
                                                </div>
                                                <div class="sywat-form-group" style="min-width:95px; flex:1;">
                                                    <label>Status</label>
                                                    <select name="shooter_type" required>
                                                        <option value="Pre-Reg">Pre-Reg</option>
                                                        <option value="Walk-In">Walk-In</option>
                                                    </select>
                                                </div>
                                                <div class="sywat-form-group" style="flex:0 0 auto;">
                                                    <button type="submit" class="sywat-btn-primary">Add Archer</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>

                                    <div style="background:#f6f7f7; padding:15px; border:1px solid #c3c4c7; border-radius:4px;">
                                        <h4 style="margin:0 0 10px 0; font-size:14px; font-weight:600;">2. Bulk Upload Roster File (FareHarbor CSV)</h4>
                                        <form method="post" enctype="multipart/form-data" id="sywat_import_form">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_action" value="import_shooters">
                                            <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="modal_action" value="prereg">

                                            <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom: 10px;">
                                                <div class="sywat-form-group" style="flex:1; min-width:200px;">
                                                    <label>Select Target Shooting Line</label>
                                                    <select name="target_line_idx" required>
                                                        <?php foreach ( $available_lines as $idx => $line ): ?>
                                                            <option value="<?php echo esc_attr( $idx ); ?>">
                                                                <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="sywat-form-group" style="flex:2; min-width:250px;">
                                                    <label>Select File (.csv, .txt)</label>
                                                    <input type="file" id="import_file_input" name="import_file" accept=".csv,.txt" style="padding:4px;">
                                                </div>
                                                <button type="submit" id="sywat_upload_btn" class="sywat-btn-primary">Upload & Parse File</button>
                                            </div>

                                            <details style="margin-top: 10px;">
                                                <summary style="font-size: 12px; font-weight: 600; color: #2271b1; cursor: pointer;">Or paste shop report text directly...</summary>
                                                <div style="margin-top: 8px;">
                                                    <textarea name="pasted_text" rows="5" placeholder="Paste report text directly here if preferred..." style="width: 100%; font-family: monospace; font-size: 12px;"></textarea>
                                                </div>
                                            </details>
                                        </form>
                                    </div>

                                    <div>
                                        <h4 style="margin:0 0 8px 0; font-size:14px; font-weight:600;">Current Line Slot Usage</h4>
                                        <table class="sywat-table">
                                            <thead>
                                                <tr>
                                                    <th>Line Name</th>
                                                    <th>20yd Capacity</th>
                                                    <th>Cub Capacity</th>
                                                    <th style="text-align: right;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ( $available_lines as $idx => $line ): ?>
                                                    <?php 
                                                        $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1);
                                                        $c_20 = $count_registered( $selected_tournament, $idx, '20yd' );
                                                        $m_20 = $get_line_max_cap( $line, '20yd' );
                                                        $c_cub = $count_registered( $selected_tournament, $idx, 'Cub' );
                                                        $m_cub = $get_line_max_cap( $line, 'Cub' );
                                                        $total_line_shooters = $c_20 + $c_cub;
                                                    ?>
                                                    <tr>
                                                        <td><strong><?php echo esc_html( $line_title ); ?></strong></td>
                                                        <td>
                                                            <?php echo $c_20 . ' / ' . $m_20; ?>
                                                            <span class="sywat-badge <?php echo ($c_20 >= $m_20) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>">
                                                                <?php echo ($c_20 >= $m_20) ? 'Full' : 'Open'; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php echo $c_cub . ' / ' . $m_cub; ?>
                                                            <span class="sywat-badge <?php echo ($c_cub >= $m_cub) ? 'sywat-badge-full' : 'sywat-badge-open'; ?>">
                                                                <?php echo ($c_cub >= $m_cub) ? 'Full' : 'Open'; ?>
                                                            </span>
                                                        </td>
                                                        <td style="text-align: right;">
                                                            <?php if ( $total_line_shooters > 0 ): ?>
                                                                <form method="post" style="display: inline-block; margin: 0;" onsubmit="return confirm('Are you sure you want to clear ALL <?php echo $total_line_shooters; ?> archers from <?php echo esc_js( $line_title ); ?>?');">
                                                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                                    <input type="hidden" name="sywat_action" value="purge_line">
                                                                    <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                                    <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $idx ); ?>">
                                                                    <input type="hidden" name="redirect_action" value="prereg">
                                                                    <button type="submit" class="sywat-btn-danger" style="padding: 3px 8px; font-size: 11px; height: auto;">Clear Line</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span style="font-size: 12px; color: #94a3b8;">(Empty)</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            <?php elseif ( $modal_action === 'manage' ): ?>
                                <!-- MODAL ACTION 2: MANAGE ARCHERS VIEW -->
                                <?php if ( $modal_edit_archer ): ?>
                                    <div class="no-print" style="background:#f6f7f7; padding:15px; border:1px solid #c3c4c7; border-radius:4px; margin-bottom:15px;">
                                        <h4 style="margin:0 0 15px 0; font-size:15px; font-weight:600;">Edit Archer Registration</h4>
                                        <form method="post">
                                            <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                            <input type="hidden" name="sywat_action" value="save_edit">
                                            <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                            <input type="hidden" name="modal_action" value="manage">
                                            <input type="hidden" name="registration_id" value="<?php echo esc_attr( $modal_edit_archer['id'] ); ?>">
                                            <input type="hidden" name="filter_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
                                            <input type="hidden" name="search_shooter" value="<?php echo esc_attr( $search_query ); ?>">

                                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                                                <div class="sywat-form-group">
                                                    <label>Full Name</label>
                                                    <input type="text" name="edit_name" value="<?php echo esc_attr($modal_edit_archer['name']); ?>" required>
                                                </div>
                                                <div class="sywat-form-group">
                                                    <label>Shooting Line</label>
                                                    <select name="edit_line_index" required>
                                                        <?php $curr_line = isset($modal_edit_archer['line_index']) ? $modal_edit_archer['line_index'] : ''; ?>
                                                        <?php foreach ($available_lines as $idx => $line): ?>
                                                            <option value="<?php echo esc_attr($idx); ?>" <?php selected((string)$curr_line, (string)$idx); ?>>
                                                                <?php echo esc_html(isset($line['name']) ? $line['name'] : 'Line ' . ($idx + 1)); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="sywat-form-group">
                                                    <label>Division</label>
                                                    <select name="edit_division" id="edit_division" required onchange="handleDivisionChange('edit');">
                                                        <?php
                                                        $curr_div = isset($modal_edit_archer['division']) ? $modal_edit_archer['division'] : 'Adult (18 - 49)';
                                                        foreach ($divs as $d) {
                                                            echo '<option value="' . esc_attr($d) . '" ' . selected($curr_div, $d, false) . '>' . esc_html($d) . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                </div>

                                                <div class="sywat-form-group">
                                                    <label>Gender</label>
                                                    <select name="edit_gender" required>
                                                        <?php
                                                        $curr_gen = isset($modal_edit_archer['gender']) ? $modal_edit_archer['gender'] : 'Male';
                                                        foreach ($genders as $g) {
                                                            echo '<option value="' . esc_attr($g) . '" ' . selected($curr_gen, $g, false) . '>' . esc_html($g) . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                </div>

                                                <div class="sywat-form-group" style="grid-column: 1 / -1;">
                                                    <label>Style</label>
                                                    <select name="edit_style" id="edit_style" required>
                                                        <?php
                                                        $curr_sty = isset($modal_edit_archer['style']) ? $modal_edit_archer['style'] : '';
                                                        foreach ($sywat_styles as $s) {
                                                            echo '<option value="' . esc_attr($s) . '" ' . selected($curr_sty, $s, false) . '>' . esc_html($s) . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                </div>

                                                <div class="sywat-form-group">
                                                    <label>Paid Option</label>
                                                    <select name="edit_pay_option" id="edit_pay_option" required onchange="updatePaidStatusDisplay('edit');">
                                                        <?php
                                                        $curr_paid = isset($modal_edit_archer['paid']) ? $modal_edit_archer['paid'] : '';
                                                        $curr_pay_opt = ($curr_paid === 'Family') ? 'Family' : (($curr_paid === 'Field Governor') ? 'Field Governor' : 'Individual');
                                                        ?>
                                                        <option value="Individual" <?php selected($curr_pay_opt, 'Individual'); ?>>Individual</option>
                                                        <option value="Family" <?php selected($curr_pay_opt, 'Family'); ?>>Family</option>
                                                        <option value="Field Governor" <?php selected($curr_pay_opt, 'Field Governor'); ?>>Field Governor</option>
                                                    </select>
                                                </div>

                                                <div class="sywat-form-group" id="edit_family_group_box" style="<?php echo ($curr_pay_opt === 'Family') ? '' : 'display:none;'; ?>">
                                                    <label>Assign to Family Group</label>
                                                    <select name="edit_family_group_id">
                                                        <option value="">[ + Create New Family Group ]</option>
                                                        <?php
                                                        $curr_fam_id = isset($modal_edit_archer['family_id']) ? $modal_edit_archer['family_id'] : '';
                                                        foreach ($existing_families as $fid => $flabel) {
                                                            echo '<option value="' . esc_attr($fid) . '" ' . selected($curr_fam_id, $fid, false) . '>' . esc_html($flabel) . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                    <input type="text" name="edit_new_family_name" placeholder="Or type new family name..." style="margin-top:5px;">
                                                </div>

                                                <div class="sywat-form-group">
                                                    <label>Balance Due ($)</label>
                                                    <input type="number" step="0.01" min="0" name="edit_balance_due" value="<?php echo esc_attr(isset($modal_edit_archer['balance_due']) ? $modal_edit_archer['balance_due'] : 0); ?>">
                                                </div>

                                                <div class="sywat-form-group">
                                                    <label>Distance (Calculated)</label>
                                                    <input type="text" id="edit_distance_display" value="<?php echo ($curr_div === 'Cub (8 - 11)') ? 'Cub' : '20yd'; ?>" disabled style="background:#f9f9f9; font-weight:bold;">
                                                </div>
                                            </div>

                                            <div style="background:#e5f5fa; padding:10px; border-radius:4px; border:1px solid #bfe7f3; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
                                                <span style="font-size:13px; color:#0073aa; font-weight:600;">Calculated Paid Status:</span>
                                                <span id="edit_paid_status_display" style="font-size:15px; font-weight:bold; color:#1d2327;"><?php echo esc_html($curr_paid ?: 'Adult'); ?></span>
                                            </div>

                                            <div style="display:flex; justify-content:flex-end; gap:10px;">
                                                <a href="?tab=manage&view_tournament=<?php echo urlencode($selected_tournament); ?>&modal_action=manage" class="sywat-btn-secondary">Cancel</a>
                                                <button type="submit" class="sywat-btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="no-print" style="margin-bottom: 15px;">
                                        <div class="sywat-filter-row">
                                            <form method="get" style="display: flex; gap: 12px; flex: 1; align-items: flex-end; margin: 0;">
                                                <input type="hidden" name="tab" value="manage">
                                                <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                <input type="hidden" name="modal_action" value="manage">

                                                <div class="sywat-form-group">
                                                    <label for="filter_line_idx">Filter by Line</label>
                                                    <select name="filter_line_idx" id="filter_line_idx" onchange="this.form.submit()">
                                                        <option value="all" <?php selected( $filter_line_idx, 'all' ); ?>>(All Lines)</option>
                                                        <?php if ( ! empty( $available_lines ) ): ?>
                                                            <?php foreach ( $available_lines as $idx => $line ): ?>
                                                                <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$filter_line_idx, (string)$idx ); ?>>
                                                                    <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                            </form>

                                            <div class="sywat-form-group" style="flex: 2;">
                                                <label for="search_shooter">Search Archer Name</label>
                                                <div style="display:flex; gap:5px; align-items:center;">
                                                    <input type="text" name="search_shooter" id="search_shooter" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Type archer name..." autocomplete="off" style="width:100%;" onkeydown="if(event.key === 'Enter') { event.preventDefault(); return false; }" >
                                                    <button type="button" class="sywat-btn-secondary" style="height:34px; padding:0 12px;" onclick="document.getElementById('search_shooter').value=''; document.getElementById('search_shooter').dispatchEvent(new Event('input'));">Clear</button>
                                                </div>
                                            </div>

                                            <?php if ( $filter_line_idx !== 'all' && ! empty( $filtered_registrations ) ): ?>
                                                <?php 
                                                    $filtered_line_name = isset( $available_lines[$filter_line_idx]['name'] ) ? $available_lines[$filter_line_idx]['name'] : 'this line';
                                                ?>
                                                <div class="sywat-form-group" style="flex: 0 0 auto;">
                                                    <form method="post" style="margin: 0;" onsubmit="return confirm('Are you sure you want to clear ALL <?php echo count( $filtered_registrations ); ?> archers from <?php echo esc_js( $filtered_line_name ); ?>?');">
                                                        <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                        <input type="hidden" name="sywat_action" value="purge_line">
                                                        <input type="hidden" name="selected_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                        <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                        <input type="hidden" name="target_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
                                                        <input type="hidden" name="redirect_action" value="manage">
                                                        <button type="submit" class="sywat-btn-danger">Clear Line Roster</button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="no-print">
                                        <?php if ( empty( $filtered_registrations ) ): ?>
                                            <p style="color: #646970; font-style: italic; font-size: 13px; margin: 20px 0;">No archers found matching your filter criteria.</p>
                                        <?php else: ?>
                                            <table class="sywat-table">
                                                <thead>
                                                    <tr>
                                                        <th>Shooter Name</th>
                                                        <th>Type</th>
                                                        <th>Line</th>
                                                        <th>Distance</th>
                                                        <th style="text-align: right;">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ( $filtered_registrations as $reg ): ?>
                                                        <?php 
                                                            $reg_id      = isset( $reg['id'] ) ? $reg['id'] : '';
                                                            $badge_type  = isset( $reg['status'] ) ? $reg['status'] : ( isset( $reg['type'] ) ? $reg['type'] : 'Pre-Reg' );
                                                            $badge_class = ( strcasecmp( $badge_type, 'Walk-In' ) === 0 ) ? 'sywat-badge-walkin' : 'sywat-badge-prereg';
                                                        ?>
                                                        <tr class="sywat-manage-row" data-name="<?php echo esc_attr(strtolower($reg['name'])); ?>">
                                                            <td><strong><?php echo esc_html( $reg['name'] ); ?></strong></td>
                                                            <td><span class="sywat-badge <?php echo $badge_class; ?>"><?php echo esc_html( $badge_type ); ?></span></td>
                                                            <td><?php echo esc_html( isset( $reg['line_name'] ) ? $reg['line_name'] : 'Line 1' ); ?></td>
                                                            <td><?php echo esc_html( isset( $reg['distance'] ) ? $reg['distance'] : '20yd' ); ?></td>
                                                            <td style="text-align: right; display:flex; gap:6px; justify-content:flex-end;">
                                                                <form method="post" style="margin: 0;">
                                                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                                    <input type="hidden" name="modal_action" value="manage">
                                                                    <input type="hidden" name="open_edit_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                                    <input type="hidden" name="filter_line_idx" value="<?php echo esc_attr( $filter_line_idx ); ?>">
                                                                    <button type="submit" class="sywat-btn-primary" style="background:#6c757d; border-color:#6c757d; padding: 4px 10px; font-size: 12px; height: auto;">✏️ Edit</button>
                                                                </form>

                                                                <form method="post" style="margin: 0;" onsubmit="return confirm('Are you sure you want to remove <?php echo esc_js( $reg['name'] ); ?>?');">
                                                                    <?php wp_nonce_field('sywat_desk_action', 'sywat_nonce'); ?>
                                                                    <input type="hidden" name="sywat_action" value="delete_shooter">
                                                                    <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                                                    <input type="hidden" name="registration_id" value="<?php echo esc_attr( $reg_id ); ?>">
                                                                    <button type="submit" class="sywat-btn-danger" style="padding: 4px 10px; font-size: 12px; height: auto;">Remove</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                            <?php elseif ( $modal_action === 'invoice' ): ?>
                                <!-- MODAL ACTION 4: GENERATE INVOICE VIEW -->
                                <?php 
                                    $all_tournament_regs = array_filter( $registrations, function($r) use ($selected_tournament) {
                                        return isset($r['tournament']) && strcasecmp($r['tournament'], $selected_tournament) === 0;
                                    });
                                    $acct = $calculate_accounting_totals($all_tournament_regs);

                                    $t_dates = get_option('sywat_dates_' . sanitize_title($selected_tournament), '');
                                    $tourn_dates_str = !empty($t_dates) ? $t_dates : $selected_tournament;

                                    $zelle_qr_url = "https://ccrstaffshooters.com/wp-content/uploads/2026/10/zelle-qr.png"; 
                                ?>
                                <div class="no-print" style="margin-bottom: 15px; display: flex; justify-content: flex-end;">
                                    <button type="button" class="sywat-btn-primary" onclick="window.print()" style="height: 34px;">Print / Save Invoice PDF</button>
                                </div>

                                <div id="sywat_print_area">
                                    <style>
                                        .invoice-box { font-family: Arial, sans-serif; color: #000; width: 100%; max-width: 800px; margin: 0 auto; padding: 10px; box-sizing: border-box; }
                                        .invoice-title { text-align: center; font-size: 20px; font-weight: bold; margin-bottom: 2px; }
                                        .invoice-subtitle { text-align: center; font-size: 11px; margin-bottom: 15px; font-style: italic; }
                                        .invoice-header-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                                        .invoice-header-table td { padding: 4px 6px; font-size: 12px; }
                                        .invoice-header-label { font-weight: bold; width: 28%; white-space: nowrap; }
                                        .invoice-header-val { background-color: #DAE6F5 !important; border: 1px solid #7092BE !important; font-weight: bold; padding: 4px 8px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                                        
                                        .invoice-fee-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                                        .invoice-fee-table th { background-color: #f1f5f9 !important; border: 1px solid #000 !important; padding: 6px; font-size: 12px; text-align: left; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                                        .invoice-fee-table td { border: 1px solid #000 !important; padding: 6px; font-size: 12px; }
                                        .blue-shade { background-color: #DAE6F5 !important; font-weight: bold; text-align: right; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                                        
                                        .invoice-footer { text-align: center; font-size: 11px; margin-top: 15px; line-height: 1.5; }
                                        .invoice-footer-bold { font-weight: bold; color: #1e3a8a; }
                                    </style>

                                    <div class="invoice-box">
                                        <div class="invoice-title">TFAA TOURNAMENT INVOICE</div>
                                        <div class="invoice-subtitle">(Fill in all the Blue Shade Boxes)</div>

                                        <table class="invoice-header-table">
                                            <tr>
                                                <td class="invoice-header-label">SYWAT Tournament Date:</td>
                                                <td class="invoice-header-val"><?php echo esc_html($tourn_dates_str); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="invoice-header-label">Archery Shop/Club Name:</td>
                                                <td class="invoice-header-val">Cinnamon Creek Ranch</td>
                                            </tr>
                                            <tr>
                                                <td class="invoice-header-label">Contact Name:</td>
                                                <td class="invoice-header-val">Hannah Lange / Beau Brennan</td>
                                            </tr>
                                            <tr>
                                                <td class="invoice-header-label">Email Address:</td>
                                                <td class="invoice-header-val">events@cinnamoncreekranch.com / beau@cinnamoncreekranch.com</td>
                                            </tr>
                                            <tr>
                                                <td class="invoice-header-label">Phone:</td>
                                                <td class="invoice-header-val">817-439-8998</td>
                                            </tr>
                                        </table>

                                        <div style="font-weight:bold; font-size:13px; margin-bottom:6px;">Registration Tournament Fees:</div>
                                        <table class="invoice-fee-table">
                                            <thead>
                                                <tr>
                                                    <th style="width: 50%;">Fee Class</th>
                                                    <th style="width: 15%; text-align: center;">#</th>
                                                    <th style="width: 15%; text-align: right;">$</th>
                                                    <th style="width: 20%; text-align: right;">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Cub/Youth/Young Adult</td>
                                                    <td class="blue-shade" style="text-align: center;"><?php echo $acct['group1_youth']; ?></td>
                                                    <td style="text-align: right;">$25</td>
                                                    <td class="blue-shade">$<?php echo number_format($acct['tot_g1'], 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Adult/Senior/Silver Senior/Master</td>
                                                    <td class="blue-shade" style="text-align: center;"><?php echo $acct['group2_adult']; ?></td>
                                                    <td style="text-align: right;">$30</td>
                                                    <td class="blue-shade">$<?php echo number_format($acct['tot_g2'], 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Family</td>
                                                    <td class="blue-shade" style="text-align: center;"><?php echo $acct['group3_families']; ?></td>
                                                    <td style="text-align: right;">$75</td>
                                                    <td class="blue-shade">$<?php echo number_format($acct['tot_g3'], 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td>
                                                        Field Governor<br>
                                                        <span style="font-size:10px; font-style:italic;">(A Field Governor has 1 free SYWAT per weekend)</span>
                                                    </td>
                                                    <td class="blue-shade" style="text-align: center;"><?php echo $acct['group4_fg']; ?></td>
                                                    <td style="text-align: right;">$0</td>
                                                    <td class="blue-shade">$0.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="3" style="font-weight: bold; text-align: right;">TOTAL SYWAT FEES</td>
                                                    <td class="blue-shade">$<?php echo number_format($acct['total_sywat_fees'], 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td colspan="3" style="font-weight: bold; text-align: right;">Host Retains 50%</td>
                                                    <td class="blue-shade">$<?php echo number_format($acct['host_retains'], 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td colspan="3" style="font-weight: bold; text-align: right;">TFAA to be Paid 50%</td>
                                                    <td class="blue-shade">$<?php echo number_format($acct['tfaa_to_pay'], 2); ?></td>
                                                </tr>
                                            </tbody>
                                        </table>

                                        <div class="invoice-footer">
                                            <p>Please remit TFAA Payment via Check or Zelle and a copy of this Invoice within 7 days to:</p>
                                            <p class="invoice-footer-bold">
                                                Texas Field Archery Association<br>
                                                C/O Anna Hanson<br>
                                                722 Cannon Ball Dr.<br>
                                                Spring TX 77380<br>
                                                Email: anna.hanson@resolute-tx.com
                                            </p>
                                            <p style="margin-top: 8px;">
                                                Send Money with Zelle®, Tag: <strong style="color:#1e3a8a;">tfaachecking</strong>
                                            </p>
                                            
                                            <div style="margin-top: 10px; text-align: center;">
                                                <img src="<?php echo esc_url($zelle_qr_url); ?>" alt="Zelle QR Code" style="width: 120px; height: auto; display: inline-block;" loading="eager">
                                            </div>

                                            <p style="font-weight: bold; font-size: 13px; margin-top: 10px;">Thank You for Hosting!</p>
                                        </div>
                                    </div>
                                </div>

                            <?php else: ?>
                                <!-- MODAL ACTION 3: PRINTABLE ROSTER VIEW -->
                                <div class="no-print" style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 10px;">
                                    <form method="get" action="" style="display: flex; gap: 12px; align-items: center; margin: 0;">
                                        <input type="hidden" name="tab" value="manage">
                                        <input type="hidden" name="view_tournament" value="<?php echo esc_attr( $selected_tournament ); ?>">
                                        <input type="hidden" name="modal_action" value="print">

                                        <div class="sywat-form-group" style="min-width: 220px;">
                                            <label for="filter_line_idx_print">Filter Roster by Line</label>
                                            <select name="filter_line_idx" id="filter_line_idx_print" onchange="this.form.submit()">
                                                <option value="all" <?php selected( $filter_line_idx, 'all' ); ?>>Entire Weekend (All Lines)</option>
                                                <?php if ( ! empty( $available_lines ) ): ?>
                                                    <?php foreach ( $available_lines as $idx => $line ): ?>
                                                        <option value="<?php echo esc_attr( $idx ); ?>" <?php selected( (string)$filter_line_idx, (string)$idx ); ?>>
                                                            <?php echo esc_html( isset( $line['name'] ) ? $line['name'] : 'Line ' . ((int)$idx + 1) ); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </form>
                                    <button type="button" class="sywat-btn-primary" onclick="window.print()" style="height: 34px;">Print / Save PDF</button>
                                </div>

                                <div id="sywat_print_area">
                                    <?php $acct = $calculate_accounting_totals($filtered_registrations); ?>
                                    <div style="margin-bottom: 12px; border-bottom: 2px solid #1d2327; padding-bottom: 8px;">
                                        <h2 style="margin: 0; font-size: 18px; color: #1d2327;">TFAA SYWAT Tournament Roster</h2>
                                        <div style="font-size: 12px; color: #475569; font-weight: 600; margin-top: 4px;">
                                            Tournament: <?php echo esc_html( $selected_tournament ); ?> 
                                            | 
                                            <?php 
                                            if ($is_specific_line && isset($available_lines[$filter_line_idx])) {
                                                echo 'Line: ' . esc_html($available_lines[$filter_line_idx]['name']);
                                            } else {
                                                echo 'Entire Weekend';
                                            }
                                            ?>
                                            | Total Roster Archers: <?php echo $acct['total_archers']; ?>
                                        </div>

                                        <!-- FINANCIAL ACCOUNTING SUMMARY BLOCK -->
                                        <div style="margin-top: 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px; font-size: 11px; color: #0f172a;">
                                            <strong>📊 ACCOUNTING & FINANCIAL SUMMARY:</strong><br>
                                            • <strong>Cub / Youth / YA:</strong> <?php echo $acct['group1_youth']; ?> Paid Transactions<br>
                                            • <strong>Adult / Senior / SS / MS:</strong> <?php echo $acct['group2_adult']; ?> Paid Transactions<br>
                                            • <strong>Family Category:</strong> <?php echo $acct['group3_families']; ?> Unique Paid Families (<?php echo $acct['family_archers']; ?> Checked-In Archers)<br>
                                            • <strong>Field Governor:</strong> <?php echo $acct['group4_fg']; ?> Transactions (Free)<br>
                                            • <strong>No Show:</strong> <?php echo $acct['group5_noshow']; ?> Archers (Not Checked In)<br>
                                            <div style="margin-top: 3px; border-top: 1px dashed #94a3b8; padding-top: 3px; font-weight: bold; color: #1e293b;">
                                                Total Processed Transactions: <?php echo $acct['total_transactions']; ?> &nbsp;|&nbsp; Checked-In Archers: <?php echo $acct['total_checked_in']; ?> / <?php echo $acct['total_archers']; ?> Total Roster
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ( empty( $filtered_registrations ) && ! $is_specific_line ): ?>
                                        <p style="color: #646970; font-style: italic;">No archer records available for this filter.</p>
                                    <?php else: ?>
                                        <table class="sywat-table">
                                            <thead>
                                                <tr>
                                                    <th style="width: 4%;">#</th>
                                                    <th style="width: 22%;">Name</th>
                                                    <th style="width: 14%;">Line</th>
                                                    <th style="width: 11%;">Division</th>
                                                    <th style="width: 7%;">Gender</th>
                                                    <th style="width: 16%;">Style</th>
                                                    <th style="width: 12%;">Paid</th>
                                                    <th style="width: 7%; text-align: center;">Score</th>
                                                    <th style="width: 7%; text-align: center;">X Count</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $num = 1; foreach ( $filtered_registrations as $reg ): ?>
                                                    <?php 
                                                        $is_checked_in = ! empty( $reg['checked_in'] );
                                                        $is_no_show    = ( isset( $reg['division'] ) && $reg['division'] === 'No Show' );

                                                        if ( $is_no_show ) {
                                                            $division_disp = 'No Show';
                                                            $gender_disp   = 'No Show';
                                                            $style_disp    = 'No Show';
                                                            $print_score   = '';
                                                            $print_x       = '';
                                                        } elseif ( $is_checked_in ) {
                                                            $division_disp = ! empty( $reg['division'] ) ? esc_html( $reg['division'] ) : '';
                                                            $gender_disp   = ! empty( $reg['gender'] ) ? esc_html( $reg['gender'] ) : '';
                                                            $style_disp    = ! empty( $reg['style'] ) ? esc_html( $reg['style'] ) : '';
                                                            $print_score   = ( isset( $reg['score'] ) && $reg['score'] !== '' ) ? esc_html( $reg['score'] ) : '';
                                                            $print_x       = ( isset( $reg['x_count'] ) && $reg['x_count'] !== '' ) ? esc_html( $reg['x_count'] ) : '';
                                                        } else {
                                                            $division_disp = 'A | S | SS | MS<br>C | Y | YA | PRO';
                                                            $gender_disp   = 'M | F';
                                                            $style_disp    = 'FS | FSL | BB | FLS-R | BHFS | MBH | TRAD | BBR | BBC | LB | GENESIS';
                                                            $print_score   = '';
                                                            $print_x       = '';
                                                        }

                                                        $due_amt = isset($reg['balance_due']) ? floatval($reg['balance_due']) : 0;
                                                        if ($due_amt > 0) {
                                                            $paid_cat = 'DUE: $' . number_format($due_amt, 2);
                                                        } else {
                                                            $paid_cat = ! empty( $reg['paid'] ) ? $reg['paid'] : (! empty( $reg['paid_category'] ) ? $reg['paid_category'] : 'Youth / Adult / Fam');
                                                            
                                                            if ( $paid_cat === 'Family' ) {
                                                                $fam_id_str = ! empty( $reg['family_id'] ) ? $reg['family_id'] : ( ! empty( $reg['booking_id'] ) ? $reg['booking_id'] : '' );
                                                                if ( $fam_id_str ) {
                                                                    $paid_cat .= ' <span style="font-size:8px;">(' . esc_html( $fam_id_str ) . ')</span>';
                                                                }
                                                            }
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td><strong><?php echo $num++; ?></strong></td>
                                                        <td><strong><?php echo esc_html( $reg['name'] ); ?></strong></td>
                                                        <td style="font-size: 9px;"><?php echo esc_html( isset( $reg['line_name'] ) ? $reg['line_name'] : '' ); ?></td>
                                                        <td style="font-size: 8px; line-height: 1.1;"><?php echo $division_disp; ?></td>
                                                        <td style="font-size: 9px;"><?php echo $gender_disp; ?></td>
                                                        <td style="font-size: 8px; line-height: 1.1;"><?php echo $style_disp; ?></td>
                                                        <td class="paid-col" style="<?php echo ($due_amt > 0) ? 'color:#dc3545;' : ''; ?>"><strong><?php echo $paid_cat; ?></strong></td>
                                                        <td style="border-bottom: 1px solid #1d2327; text-align: center; font-weight: bold;"><?php echo $print_score; ?></td>
                                                        <td style="border-bottom: 1px solid #1d2327; text-align: center; font-weight: bold;"><?php echo $print_x; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>

                                                <?php 
                                                if ( $is_specific_line && $num <= 100 ) {
                                                    while ( $num <= 100 ) {
                                                        ?>
                                                        <tr>
                                                            <td><strong><?php echo $num++; ?></strong></td>
                                                            <td></td>
                                                            <td style="font-size: 9px;"><?php echo $is_specific_line ? esc_html($available_lines[$filter_line_idx]['name']) : ''; ?></td>
                                                            <td style="font-size: 8px; line-height: 1.1;">A | S | SS | MS<br>C | Y | YA | PRO</td>
                                                            <td style="font-size: 9px;">M | F</td>
                                                            <td style="font-size: 8px; line-height: 1.1;">FS | FSL | BB | FLS-R | BHFS | MBH | TRAD | BBR | BBC | LB | GENESIS</td>
                                                            <td class="paid-col"><strong>YOUTH | ADULT | FAMILY</strong></td>
                                                            <td style="border-bottom: 1px solid #1d2327; text-align: center;"></td>
                                                            <td style="border-bottom: 1px solid #1d2327; text-align: center;"></td>
                                                        </tr>
                                                        <?php
                                                    }
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
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

        function updatePaidStatusDisplay(type) {
            const payOption  = document.getElementById(type + '_pay_option');
            const division   = document.getElementById(type + '_division');
            const displayEl  = document.getElementById(type + '_paid_status_display');
            const familyBox  = document.getElementById(type + '_family_group_box');
            
            if(!payOption || !division || !displayEl) return;

            let calculatedStatus = 'Adult';
            if (payOption.value === 'Family') {
                calculatedStatus = 'Family';
                if (familyBox) familyBox.style.display = '';
            } else {
                if (familyBox) familyBox.style.display = 'none';
                if (payOption.value === 'Field Governor') {
                    calculatedStatus = 'Field Governor';
                } else {
                    const youthDivs = ['Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)'];
                    if (youthDivs.includes(division.value)) {
                        calculatedStatus = 'Youth';
                    } else {
                        calculatedStatus = 'Adult';
                    }
                }
            }
            displayEl.textContent = calculatedStatus;
        }

        function handleStyleRestrictions(type) {
            const divisionEl = document.getElementById(type + '_division');
            const styleEl    = document.getElementById(type + '_style');
            if (!divisionEl || !styleEl) return;

            const currentDiv = divisionEl.value;

            for (let i = 0; i < styleEl.options.length; i++) {
                const opt = styleEl.options[i];
                const val = opt.value;
                let isDisabled = false;

                if (val.indexOf('Genesis') !== -1) {
                    if (currentDiv !== 'Cub (8 - 11)') isDisabled = true;
                }

                const isFSPro = val.indexOf('FS Pro') !== -1;
                const isFSL   = val.indexOf('FSL –') !== -1;
                const isTRAD  = val.indexOf('TRAD') !== -1 || val.toLowerCase().indexOf('traditional') !== -1;

                if (isFSPro || isFSL || isTRAD) {
                    if (!['Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)'].includes(currentDiv)) {
                        isDisabled = true;
                    }
                }

                const isLB = val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1;
                if (isLB && currentDiv !== 'Adult (18 - 49)') {
                    isDisabled = true;
                }

                opt.disabled = isDisabled;

                if (isDisabled && styleEl.value === val) {
                    styleEl.value = 'FS – Freestyle';
                }
            }
        }

        function handleDivisionChange(type) {
            const divisionEl = document.getElementById(type + '_division');
            const distDispEl = document.getElementById(type + '_distance_display');
            if (!divisionEl) return;

            const currentDiv = divisionEl.value;
            const calculatedDist = (currentDiv === 'Cub (8 - 11)') ? 'Cub' : '20yd';

            if (distDispEl) {
                distDispEl.value = calculatedDist;
            }

            updatePaidStatusDisplay(type);
            handleStyleRestrictions(type);
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

            if (document.getElementById('edit_division')) handleDivisionChange('edit');

            const manageSearchInput = document.getElementById('search_shooter');
            if (manageSearchInput) {
                manageSearchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase();
                    const rows = document.querySelectorAll('.sywat-manage-row');
                    
                    rows.forEach(row => {
                        const name = row.getAttribute('data-name');
                        if (name && name.includes(filter)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
                manageSearchInput.dispatchEvent(new Event('input'));
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
}

function render_sywat_volunteer_hub() {
    $user = wp_get_current_user();
    if ( is_user_logged_in() && ! array_intersect( array( 'administrator', 'management', 'staff_shooter', 'non-shooting-member' ), (array) $user->roles ) ) {
        return '<p style="color:red; padding:20px;">Access denied. Staff access required.</p>';
    }

    ob_start();
    global $wpdb;

    $tournaments = get_option( 'sywat_tournaments_list', array() );
    if ( ! is_array( $tournaments ) ) $tournaments = array();

    $active_tournaments = get_option( 'sywat_active_tournaments', array() );
    if ( ! is_array( $active_tournaments ) ) $active_tournaments = array();

    $display_tournaments = array();
    foreach ($tournaments as $t_name => $t_lines) {
        if ( in_array( $t_name, $active_tournaments ) ) {
            $display_tournaments[$t_name] = $t_lines;
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

    $normalize_dist = function( $dist_str ) {
        return ( strcasecmp( trim( $dist_str ), 'cub' ) === 0 || strcasecmp( trim( $dist_str ), '10yd' ) === 0 || strcasecmp( trim( $dist_str ), '10 yards' ) === 0 ) ? 'Cub' : '20yd';
    };

    $get_line_max_cap = function( $line_data, $dist ) {
        if ( $dist === 'Cub' ) {
            return isset( $line_data['cap_cub'] ) ? intval( $line_data['cap_cub'] ) : 5;
        }
        return isset( $line_data['cap_20yd'] ) ? intval( $line_data['cap_20yd'] ) : 20;
    };

    $count_registered = function( $t_name, $l_idx, $dist ) use ( &$registrations, $normalize_dist ) {
        $count = 0;
        foreach ( $registrations as $reg ) {
            if ( isset( $reg['tournament'], $reg['line_index'], $reg['distance'] )
                && strcasecmp( $reg['tournament'], $t_name ) === 0
                && (string)$reg['line_index'] === (string)$l_idx
                && $normalize_dist( $reg['distance'] ) === $dist ) {
                $count++;
            }
        }
        return $count;
    };

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
        @media (max-width: 650px) {
            .sywat-vhub-grid { grid-template-columns: 1fr; }
        }
        .sywat-vhub-line-box { background: #f8f9fa; border: 1px solid #dcdcde; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 12px; }
        .sywat-vhub-line-name { font-size: 1.1em; font-weight: 700; color: #1d2327; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .sywat-vhub-stat { font-size: 15px; color: #334155; line-height: 1.6; }
        .sywat-vhub-stat-num { font-size: 16px; font-weight: 700; color: #0f172a; }
        .sywat-vhub-actions { display: flex; gap: 10px; margin-top: 10px; }
        .sywat-vhub-btn-checkin { flex: 1; background: #008a20; color: #ffffff !important; border: 1px solid #008a20; text-align: center; padding: 11px 10px; border-radius: 5px; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; transition: background 0.15s ease; }
        .sywat-vhub-btn-checkin:hover { background: #007017; text-decoration: none; }
        .sywat-vhub-btn-scores { flex: 1; background: #2271b1; color: #ffffff !important; border: 1px solid #2271b1; text-align: center; padding: 11px 10px; border-radius: 5px; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; transition: background 0.15s ease; }
        .sywat-vhub-btn-scores:hover { background: #135e96; text-decoration: none; }
        .sywat-vhub-badge { font-size: 13px; padding: 4px 8px; border-radius: 4px; font-weight: 700; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    </style>

    <div class="sywat-vhub-wrap">
        <?php if ( empty( $display_tournaments ) ): ?>
            <div class="sywat-vhub-card">
                <p style="color:#646970; font-style:italic; margin:0;">No active tournaments currently enabled for the Volunteer Hub.</p>
            </div>
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
                            <div class="sywat-vhub-day-title">
                                📅 <?php echo esc_html( $day_label ); ?> Lines
                            </div>
                            <div class="sywat-vhub-grid">
                                <?php foreach ( $group_item_list as $item ): ?>
                                    <?php 
                                        $idx        = $item['idx'];
                                        $line       = $item['line'];
                                        $line_title = isset( $line['name'] ) ? $line['name'] : 'Line ' . ($idx + 1);

                                        $c_20       = $count_registered( $t_name, $idx, '20yd' );
                                        $m_20       = $get_line_max_cap( $line, '20yd' );
                                        $rem_20     = max( 0, $m_20 - $c_20 );

                                        $c_cub      = $count_registered( $t_name, $idx, 'Cub' );
                                        $m_cub      = $get_line_max_cap( $line, 'Cub' );
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
