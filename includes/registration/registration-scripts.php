<?php
/**
 * Sub-Module: Registration Engine Client Scripts Enqueuer
 * Path: includes/registration/registration-scripts.php
 * Author: SYWAT Archery
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_registration_scripts' ) ) {
    function sywat_render_registration_scripts() {
        $tfaa_cache   = get_option( 'sywat_tfaa_cache', array() );
        $phone_cache  = get_option( 'sywat_shooter_phones_cache', array() );
        $js_tfaa_data = array();

        // Extract shooter profiles robustly regardless of DB cache structure
        if ( is_array( $tfaa_cache ) ) {
            $shooters = array();
            if ( isset( $tfaa_cache['shooters'] ) && is_array( $tfaa_cache['shooters'] ) ) {
                $shooters = $tfaa_cache['shooters'];
            } elseif ( isset( $tfaa_cache['exact'] ) && is_array( $tfaa_cache['exact'] ) ) {
                foreach ( $tfaa_cache['exact'] as $name_key => $records ) {
                    if ( is_array( $records ) && ! empty( $records ) ) {
                        $rec = $records[0];
                        $shooters[] = array(
                            'name'     => isset( $rec['full_name'] ) ? $rec['full_name'] : $name_key,
                            'division' => isset( $rec['division'] ) ? $rec['division'] : '',
                            'gender'   => isset( $rec['gender'] ) ? $rec['gender'] : '',
                            'style'    => isset( $rec['style'] ) ? $rec['style'] : '',
                        );
                    }
                }
            } else {
                foreach ( $tfaa_cache as $k => $v ) {
                    if ( is_array( $v ) ) {
                        $name = isset( $v['name'] ) ? $v['name'] : ( isset( $v['full_name'] ) ? $v['full_name'] : ( is_string( $k ) ? $k : '' ) );
                        if ( ! empty( $name ) ) {
                            $shooters[] = array(
                                'name'     => $name,
                                'division' => isset( $v['division'] ) ? $v['division'] : '',
                                'gender'   => isset( $v['gender'] ) ? $v['gender'] : '',
                                'style'    => isset( $v['style'] ) ? $v['style'] : '',
                            );
                        }
                    }
                }
            }

            foreach ( $shooters as $s ) {
                $name = isset( $s['name'] ) ? $s['name'] : ( isset( $s['full_name'] ) ? $s['full_name'] : '' );
                if ( ! empty( $name ) ) {
                    $js_tfaa_data[] = array(
                        'name'     => $name,
                        'division' => isset( $s['division'] ) ? $s['division'] : '',
                        'gender'   => isset( $s['gender'] ) ? $s['gender'] : '',
                        'style'    => isset( $s['style'] ) ? $s['style'] : '',
                    );
                }
            }
        }

        // Determine script URL relative to plugin root
        $js_url = defined( 'SYWAT_PLUGIN_URL' ) 
            ? SYWAT_PLUGIN_URL . 'assets/js/sywat-registration.js' 
            : plugins_url( '../../assets/js/sywat-registration.js', __FILE__ );

        // Enqueue external JavaScript file
        wp_enqueue_script( 'sywat-registration-js', $js_url, array(), '5.0.0', true );

        // Localize database caches to JS window object safely
        wp_localize_script( 'sywat-registration-js', 'sywatRegData', array(
            'tfaaShooters' => array_values( $js_tfaa_data ),
            'phoneCache'   => is_array( $phone_cache ) ? $phone_cache : array(),
        ) );
    }
}
