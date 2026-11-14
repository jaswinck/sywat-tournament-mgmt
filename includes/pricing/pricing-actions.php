<?php
/**
 * Sub-Module: Pricing Profiles, Round Formats & Promoter POST Action Handlers
 * Path: includes/pricing/pricing-actions.php
 * Description: Processes nonce-verified POST mutations for adding, editing, reordering, retiring, setting default, deleting, and resetting SYWAT / TFAA pricing profiles, household family cap rules, and first-time/lapsed shooter promotional rules.
 * Compliance: Pure action layer — zero HTML echo at include time, zero direct DB calls (delegates to pricing-data.php), omitted closing PHP tag.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Processes POST requests submitted from the Pricing & Promoter administration portal.
 *
 * @return string HTML alert notice markup, or empty string when no action was processed.
 */
if ( ! function_exists( 'sywat_pricing_handle_post_actions' ) ) {
    function sywat_pricing_handle_post_actions() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['sywat_pricing_action'] ) ) {
            return '';
        }

        if ( ! function_exists( 'sywat_can_manage_seasons' ) || ! sywat_can_manage_seasons() ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Access denied. TFAA Leadership privileges are required to modify pricing profiles.</div>';
        }

        $nonce = isset( $_POST['sywat_pricing_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sywat_pricing_nonce'] ) ) : '';
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'sywat_pricing_action' ) ) {
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Security token expired. Please refresh the page and try again.</div>';
        }

        $action = sanitize_key( wp_unslash( $_POST['sywat_pricing_action'] ) );

        // 1. Add or Edit Pricing & Round Profile
        if ( $action === 'save_pricing_profile' ) {
            $orig_slug      = isset( $_POST['orig_profile_slug'] ) ? sanitize_title( wp_unslash( $_POST['orig_profile_slug'] ) ) : '';
            $label          = isset( $_POST['profile_label'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['profile_label'] ) ) ) : '';
            $round_subtitle = isset( $_POST['profile_round_subtitle'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['profile_round_subtitle'] ) ) ) : '';
            $code           = isset( $_POST['profile_code'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['profile_code'] ) ) ) ) : '';
            $aliases        = isset( $_POST['profile_aliases'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['profile_aliases'] ) ) ) : '';
            $sort_order     = isset( $_POST['profile_sort_order'] ) ? max( 1, intval( wp_unslash( $_POST['profile_sort_order'] ) ) ) : 10;
            $status         = ( isset( $_POST['profile_status'] ) && sanitize_key( wp_unslash( $_POST['profile_status'] ) ) === 'retired' ) ? 'retired' : 'active';
            $is_default     = ! empty( $_POST['profile_is_default'] );

            if ( $label === '' ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Format Display Name is required (e.g., Indoor, Field, Field 50, Known 3D, 900).</div>';
            }

            if ( $code === '' ) {
                $code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $label ) );
            }
            if ( $code === '' ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Short Export Code is required.</div>';
            }

            // Base Tier & Special Category Rates
            $raw_youth = isset( $_POST['youth_rate'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['youth_rate'] ) ) ) : '25';
            $raw_adult = isset( $_POST['adult_rate'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['adult_rate'] ) ) ) : '30';
            $raw_pro   = isset( $_POST['pro_rate'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['pro_rate'] ) ) ) : '';
            $raw_fg    = isset( $_POST['field_governor_rate'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['field_governor_rate'] ) ) ) : '0';

            if ( ! is_numeric( $raw_youth ) || floatval( $raw_youth ) < 0 ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please enter a valid non-negative Youth Tier Rate.</div>';
            }
            if ( ! is_numeric( $raw_adult ) || floatval( $raw_adult ) < 0 ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please enter a valid non-negative Adult+ Tier Rate.</div>';
            }
            if ( $raw_pro !== '' && ( ! is_numeric( $raw_pro ) || floatval( $raw_pro ) < 0 ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Pro Category Rate must be blank or a valid non-negative dollar amount.</div>';
            }
            if ( ! is_numeric( $raw_fg ) || floatval( $raw_fg ) < 0 ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Please enter a valid non-negative Field Governor Rate.</div>';
            }

            $youth_rate          = round( floatval( $raw_youth ), 2 );
            $adult_rate          = round( floatval( $raw_adult ), 2 );
            $pro_rate            = ( $raw_pro !== '' ) ? round( floatval( $raw_pro ), 2 ) : '';
            $field_governor_rate = round( floatval( $raw_fg ), 2 );

            // Optional Per-Division Price Overrides
            $division_overrides = array();
            if ( isset( $_POST['division_overrides'] ) && is_array( $_POST['division_overrides'] ) ) {
                $raw_overrides = wp_unslash( $_POST['division_overrides'] );
                foreach ( $raw_overrides as $div_slug_raw => $ov_amount_raw ) {
                    $clean_div_slug = sanitize_title( (string) $div_slug_raw );
                    $clean_amount   = trim( sanitize_text_field( (string) $ov_amount_raw ) );
                    if ( $clean_div_slug !== '' && $clean_amount !== '' && is_numeric( $clean_amount ) && floatval( $clean_amount ) >= 0 ) {
                        $division_overrides[ $clean_div_slug ] = round( floatval( $clean_amount ), 2 );
                    }
                }
            }

            // Household Family Cap Rules
            $family_cap_enabled = ! empty( $_POST['family_cap_enabled'] );
            $raw_cap_amount     = isset( $_POST['family_cap_amount'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['family_cap_amount'] ) ) ) : '75';
            $family_cap_amount  = ( is_numeric( $raw_cap_amount ) && floatval( $raw_cap_amount ) >= 0 ) ? round( floatval( $raw_cap_amount ), 2 ) : 75.00;
            $family_min_archers = isset( $_POST['family_min_archers'] ) ? max( 2, intval( wp_unslash( $_POST['family_min_archers'] ) ) ) : 3;
            $family_cap_scope   = ( isset( $_POST['family_cap_scope'] ) && sanitize_key( wp_unslash( $_POST['family_cap_scope'] ) ) === 'tournament' ) ? 'tournament' : 'line';

            // First-Time / Lapsed Shooter Promotional Free Entry Rules
            $promo_enabled        = ! empty( $_POST['promo_enabled'] );
            $promo_label          = isset( $_POST['promo_label'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['promo_label'] ) ) ) : '';
            if ( $promo_label === '' ) {
                $promo_label = 'First-Time / Lapsed Shooter Promo';
            }
            $promo_lookback_value = isset( $_POST['promo_lookback_value'] ) ? max( 1, intval( wp_unslash( $_POST['promo_lookback_value'] ) ) ) : 24;
            $promo_lookback_unit  = ( isset( $_POST['promo_lookback_unit'] ) && sanitize_key( wp_unslash( $_POST['promo_lookback_unit'] ) ) === 'years' ) ? 'years' : 'months';

            $promo_history_profiles = array();
            if ( isset( $_POST['promo_history_profiles'] ) && is_array( $_POST['promo_history_profiles'] ) ) {
                $raw_hp = wp_unslash( $_POST['promo_history_profiles'] );
                foreach ( $raw_hp as $hp_val ) {
                    $clean_hp = sanitize_title( (string) $hp_val );
                    if ( $clean_hp !== '' && ! in_array( $clean_hp, $promo_history_profiles, true ) ) {
                        $promo_history_profiles[] = $clean_hp;
                    }
                }
            }

            $promo_max_uses    = isset( $_POST['promo_max_uses'] ) ? max( 0, intval( wp_unslash( $_POST['promo_max_uses'] ) ) ) : 1;
            $promo_usage_scope = isset( $_POST['promo_usage_scope'] ) ? sanitize_key( wp_unslash( $_POST['promo_usage_scope'] ) ) : 'season';
            if ( ! in_array( $promo_usage_scope, array( 'season', 'lookback', 'lifetime' ), true ) ) {
                $promo_usage_scope = 'season';
            }

            $profiles = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( true ) : array();

            // Prevent duplicate display labels across different profile slugs
            foreach ( $profiles as $existing_slug => $existing_rec ) {
                if ( $existing_slug !== $orig_slug && strcasecmp( $existing_rec['label'], $label ) === 0 ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">A pricing format named <strong>' . esc_html( $label ) . '</strong> already exists.</div>';
                }
            }

            if ( $orig_slug !== '' && isset( $profiles[ $orig_slug ] ) ) {
                $target_slug = $orig_slug;
            } else {
                $base_slug = sanitize_title( $label );
                if ( $base_slug === '' ) {
                    $base_slug = sanitize_title( $code );
                }
                $target_slug = $base_slug;
                $counter     = 2;
                while ( isset( $profiles[ $target_slug ] ) ) {
                    $target_slug = $base_slug . '-' . $counter;
                    $counter++;
                }
            }

            if ( empty( $promo_history_profiles ) ) {
                $promo_history_profiles = array( $target_slug );
            }

            // If marked as new global default, clear default flag on other records first
            if ( $is_default && $status === 'active' ) {
                foreach ( $profiles as &$p_item ) {
                    $p_item['is_default'] = false;
                }
                unset( $p_item );
            }

            $profiles[ $target_slug ] = array(
                'slug'                   => $target_slug,
                'label'                  => $label,
                'round_subtitle'         => $round_subtitle,
                'code'                   => $code,
                'aliases'                => $aliases,
                'sort_order'             => $sort_order,
                'status'                 => $status,
                'is_default'             => $is_default,
                'youth_rate'             => $youth_rate,
                'adult_rate'             => $adult_rate,
                'pro_rate'               => $pro_rate,
                'field_governor_rate'    => $field_governor_rate,
                'division_overrides'     => $division_overrides,
                'family_cap_enabled'     => $family_cap_enabled,
                'family_cap_amount'      => $family_cap_amount,
                'family_min_archers'     => $family_min_archers,
                'family_cap_scope'       => $family_cap_scope,
                'promo_enabled'          => $promo_enabled,
                'promo_label'            => $promo_label,
                'promo_lookback_value'   => $promo_lookback_value,
                'promo_lookback_unit'    => $promo_lookback_unit,
                'promo_history_profiles' => $promo_history_profiles,
                'promo_max_uses'         => $promo_max_uses,
                'promo_usage_scope'      => $promo_usage_scope,
            );

            if ( function_exists( 'sywat_save_pricing_profiles' ) ) {
                sywat_save_pricing_profiles( $profiles );
            }

            $verb = ( $orig_slug !== '' ) ? 'updated' : 'added';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Pricing format <strong>' . esc_html( $label ) . '</strong> ' . esc_html( $verb ) . ' successfully.</div>';
        }

        // 2. Toggle Pricing Profile Active / Retired Status
        if ( $action === 'toggle_profile_status' ) {
            $slug     = isset( $_POST['profile_slug'] ) ? sanitize_title( wp_unslash( $_POST['profile_slug'] ) ) : '';
            $profiles = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( true ) : array();

            if ( $slug === '' || ! isset( $profiles[ $slug ] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Pricing format not found.</div>';
            }

            $new_status = ( $profiles[ $slug ]['status'] === 'retired' ) ? 'active' : 'retired';

            if ( $new_status === 'retired' ) {
                $active_count = 0;
                foreach ( $profiles as $p_rec ) {
                    if ( $p_rec['status'] === 'active' ) {
                        $active_count++;
                    }
                }
                if ( $active_count <= 1 ) {
                    return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">At least one active pricing format must remain enabled.</div>';
                }
            }

            $profiles[ $slug ]['status'] = $new_status;
            if ( $new_status === 'retired' ) {
                $profiles[ $slug ]['is_default'] = false;
            }

            if ( function_exists( 'sywat_save_pricing_profiles' ) ) {
                sywat_save_pricing_profiles( $profiles );
            }

            $state_label = ( $new_status === 'active' ) ? 'activated' : 'retired';
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Pricing format <strong>' . esc_html( $profiles[ $slug ]['label'] ) . '</strong> ' . esc_html( $state_label ) . '.</div>';
        }

        // 3. Set Active Pricing Profile as Global Default
        if ( $action === 'set_default_profile' ) {
            $slug     = isset( $_POST['profile_slug'] ) ? sanitize_title( wp_unslash( $_POST['profile_slug'] ) ) : '';
            $profiles = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( true ) : array();

            if ( $slug === '' || ! isset( $profiles[ $slug ] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Pricing format not found.</div>';
            }

            foreach ( $profiles as $p_key => &$p_rec ) {
                $p_rec['is_default'] = ( $p_key === $slug );
                if ( $p_key === $slug ) {
                    $p_rec['status'] = 'active';
                }
            }
            unset( $p_rec );

            if ( function_exists( 'sywat_save_pricing_profiles' ) ) {
                sywat_save_pricing_profiles( $profiles );
            }

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success"><strong>' . esc_html( $profiles[ $slug ]['label'] ) . '</strong> is now set as the global default pricing format.</div>';
        }

        // 4. Delete Pricing Profile
        if ( $action === 'delete_pricing_profile' ) {
            $slug     = isset( $_POST['profile_slug'] ) ? sanitize_title( wp_unslash( $_POST['profile_slug'] ) ) : '';
            $profiles = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( true ) : array();

            if ( $slug === '' || ! isset( $profiles[ $slug ] ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Pricing format not found.</div>';
            }

            if ( count( $profiles ) <= 1 ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Cannot delete the last remaining pricing format.</div>';
            }

            $deleted_label = $profiles[ $slug ]['label'];
            unset( $profiles[ $slug ] );

            if ( function_exists( 'sywat_save_pricing_profiles' ) ) {
                sywat_save_pricing_profiles( $profiles );
            }

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Pricing format <strong>' . esc_html( $deleted_label ) . '</strong> removed.</div>';
        }

        // 5. Move Pricing Profile Up / Down
        if ( $action === 'move_pricing_profile' ) {
            $slug      = isset( $_POST['profile_slug'] ) ? sanitize_title( wp_unslash( $_POST['profile_slug'] ) ) : '';
            $direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
            $profiles  = function_exists( 'sywat_get_pricing_profiles' ) ? sywat_get_pricing_profiles( true ) : array();

            if ( $slug === '' || ! isset( $profiles[ $slug ] ) || ! in_array( $direction, array( 'up', 'down' ), true ) ) {
                return '<div id="sywat-notice" class="sywat-alert sywat-alert-danger">Unable to reorder pricing format.</div>';
            }

            $ordered_list = array_values( $profiles );
            $target_index = -1;
            foreach ( $ordered_list as $idx => $rec ) {
                if ( $rec['slug'] === $slug ) {
                    $target_index = $idx;
                    break;
                }
            }

            $swap_index = ( $direction === 'up' ) ? ( $target_index - 1 ) : ( $target_index + 1 );
            if ( $target_index >= 0 && isset( $ordered_list[ $swap_index ] ) ) {
                $temp                          = $ordered_list[ $target_index ];
                $ordered_list[ $target_index ] = $ordered_list[ $swap_index ];
                $ordered_list[ $swap_index ]   = $temp;

                $reindexed = array();
                $pos       = 1;
                foreach ( $ordered_list as $rec ) {
                    $rec['sort_order']         = $pos;
                    $reindexed[ $rec['slug'] ] = $rec;
                    $pos++;
                }

                if ( function_exists( 'sywat_save_pricing_profiles' ) ) {
                    sywat_save_pricing_profiles( $reindexed );
                }
            }

            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Pricing format <strong>' . esc_html( $profiles[ $slug ]['label'] ) . '</strong> moved ' . esc_html( $direction ) . '.</div>';
        }

        // 6. Reset All Pricing Profiles to Default SYWAT / TFAA Presets
        if ( $action === 'reset_pricing_defaults' ) {
            if ( function_exists( 'sywat_pricing_reset_defaults' ) ) {
                sywat_pricing_reset_defaults();
            }
            return '<div id="sywat-notice" class="sywat-alert sywat-alert-success">Pricing formats, family cap limits, and promoter presets have been restored to defaults.</div>';
        }

        return '';
    }
}
