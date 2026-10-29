<?php
/**
 * Module: TFAA Data & Standings Sync Engine
 * Description: Synchronizes archer membership records, caches state standings data, 
 *              and verifies TFAA shooter eligibility across active tournament rosters.
 * Author: SYWAT Archery
 * Version: 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Schedule WP-Cron Daily Sync
if ( ! wp_next_scheduled( 'sywat_daily_tfaa_sync_event' ) ) {
    wp_schedule_event( time(), 'daily', 'sywat_daily_tfaa_sync_event' );
}
if ( ! has_action( 'sywat_daily_tfaa_sync_event', 'sywat_sync_tfaa_standings' ) ) {
    add_action( 'sywat_daily_tfaa_sync_event', 'sywat_sync_tfaa_standings' );
}

// Standalone Sync Button Shortcode: [sywat_tfaa_sync_btn]
add_shortcode( 'sywat_tfaa_sync_btn', 'render_sywat_tfaa_sync_btn' );

function render_sywat_tfaa_sync_btn() {
    if ( ! is_user_logged_in() ) return '';
    
    $message = '';
    if ( isset($_POST['sywat_tfaa_action']) && $_POST['sywat_tfaa_action'] === 'sync_now' && check_admin_referer('sywat_tfaa_sync_nonce') ) {
        $res = sywat_sync_tfaa_standings();
        if ( ! empty($res['success']) ) {
            $msg_class = $res['count'] > 0 ? 'sywat-alert-success' : 'sywat-alert-danger';
            
            $message = '<div class="sywat-alert ' . $msg_class . '">';
            $message .= $res['count'] > 0 ? '🟢 Synced ' : '⚠️ Synced ';
            $message .= intval($res['count']) . ' shooters from TFAA! ';
            if (isset($res['debug'])) {
                $message .= '<span style="font-weight:normal; font-style:italic;">(' . esc_html($res['debug']) . ')</span>';
            }
            $message .= '</div>';
        } else {
            $message = '<div class="sywat-alert sywat-alert-danger">⚠️ Sync failed: ' . esc_html($res['message']) . '</div>';
        }
    }

    $tfaa_cache = get_option( 'sywat_tfaa_cache', array() );
    $tfaa_count = isset( $tfaa_cache['count'] ) ? intval($tfaa_cache['count']) : 0;
    $tfaa_updated = isset( $tfaa_cache['updated_at'] ) ? $tfaa_cache['updated_at'] : 'Never';

    ob_start();
    echo $message;
    ?>
    <form method="post" style="display:inline-flex; align-items:center; gap:10px; margin:10px 0;">
        <?php wp_nonce_field('sywat_tfaa_sync_nonce'); ?>
        <input type="hidden" name="sywat_tfaa_action" value="sync_now">
        <span style="font-size: 12px; color: #646970;">
            TFAA Cache: <strong><?php echo $tfaa_count; ?></strong> Shooters (Updated: <?php echo esc_html($tfaa_updated); ?>)
        </span>
        <button type="submit" class="sywat-btn sywat-btn-secondary sywat-btn-icon-sync" style="height: 32px;">Sync TFAA Standings Now</button>
    </form>
    <?php
    return ob_get_clean();
}

if ( ! function_exists( 'sywat_parse_tfaa_div_gen' ) ) {
    function sywat_parse_tfaa_div_gen( $text ) {
        if ( strlen( $text ) > 80 ) return array( 'division' => '', 'gender' => '' );

        // Strict word boundaries so "Female" doesn't falsely trigger "Male"
        $has_female = preg_match( '/\bfemale\b/i', $text );
        $has_male   = preg_match( '/\bmale\b/i', $text );

        if ( ( ! $has_female && ! $has_male ) || ( $has_female && $has_male ) ) {
            return array( 'division' => '', 'gender' => '' );
        }

        $gen = $has_female ? 'Female' : 'Male';
        $div = '';

        if ( stripos( $text, 'Master Senior' ) !== false || stripos( $text, 'Master' ) !== false ) $div = 'Master Senior (70+)';
        elseif ( stripos( $text, 'Silver Senior' ) !== false ) $div = 'Silver Senior (60 - 69)';
        elseif ( stripos( $text, 'Senior' ) !== false ) $div = 'Senior (50 - 59)';
        elseif ( stripos( $text, 'Young Adult' ) !== false ) $div = 'Young Adult (15 - 17)';
        elseif ( stripos( $text, 'Youth' ) !== false ) $div = 'Youth (12 - 14)';
        elseif ( stripos( $text, 'Cub' ) !== false ) $div = 'Cub (8 - 11)';
        elseif ( stripos( $text, 'Adult' ) !== false ) $div = 'Adult (18 - 49)';

        if ( empty( $div ) ) return array( 'division' => '', 'gender' => '' );
        return array( 'division' => $div, 'gender' => $gen );
    }
}

if ( ! function_exists( 'sywat_parse_tfaa_style' ) ) {
    function sywat_parse_tfaa_style( $text ) {
        if ( strlen( $text ) > 80 ) return '';
        $style = '';
        $is_pro = ( stripos( $text, 'Pro' ) !== false );

        // Check "Modern Bowhunter" BEFORE "Bowhunter Freestyle"
        if ( stripos( $text, 'Freestyle Limited Recurve' ) !== false || stripos( $text, 'FSLR' ) !== false || stripos( $text, 'FSL-R' ) !== false ) $style = 'FSL-R – Freestyle Limited Recurve (or Olympic Recurve)';
        elseif ( stripos( $text, 'Freestyle Limited' ) !== false || stripos( $text, 'FSL' ) !== false ) $style = 'FSL – Freestyle Limited';
        elseif ( stripos( $text, 'Modern Bowhunter' ) !== false || stripos( $text, 'MBH' ) !== false ) $style = 'MBH – Modern Bowhunter';
        elseif ( stripos( $text, 'Bowhunter Freestyle' ) !== false || stripos( $text, 'BHFS' ) !== false ) $style = 'BHFS – Bowhunter Freestyle';
        elseif ( stripos( $text, 'Barebow Compound' ) !== false || stripos( $text, 'BBC' ) !== false ) $style = 'BBC – Barebow Compound';
        elseif ( stripos( $text, 'Barebow Recurve' ) !== false || stripos( $text, 'BBR' ) !== false ) $style = 'BBR – Barebow Recurve';
        elseif ( stripos( $text, 'Traditional' ) !== false || stripos( $text, 'TRAD' ) !== false ) $style = 'TRAD – Traditional';
        elseif ( stripos( $text, 'Longbow' ) !== false || stripos( $text, 'LB' ) !== false ) $style = 'LB – Longbow';
        elseif ( stripos( $text, 'Genesis' ) !== false || stripos( $text, 'GEN' ) !== false ) $style = 'Genesis';
        elseif ( stripos( $text, 'Freestyle' ) !== false || stripos( $text, 'FS' ) !== false ) $style = $is_pro ? 'FS Pro – Freestyle Pro' : 'FS – Freestyle';

        return $style;
    }
}

if ( ! function_exists( 'sywat_sync_tfaa_standings' ) ) {
    function sywat_sync_tfaa_standings() {
        $url = 'https://texasfieldarchery.org/results/sywat';
        $args = array( 
            'timeout' => 25, 
            'sslverify' => false, 
            'headers' => array( 
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'
            ) 
        );

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) return array( 'success' => false, 'message' => $response->get_error_message() );
        
        $body = wp_remote_retrieve_body( $response );
        $debug_log = array();
        
        // Remove literal returns
        $body = str_replace( array( "\r", "\n" ), " ", $body );
        $debug_log[] = "Pass 1 HTML Size: " . strlen($body);

        if ( stripos( $body, '0 Entries' ) !== false || stripos( $body, 'Cub Male' ) === false ) {
            preg_match_all( '/<option[^>]*value=["\']([^"\']+)["\'][^>]*>([^<]+)<\/option>/i', $body, $matches, PREG_SET_ORDER );
            foreach ( $matches as $m ) {
                $val = trim( $m[1] );
                if ( ! empty( $val ) && stripos( $val, 'Select' ) === false && preg_match('/\d{4}/', $val) ) {
                    $season_url = $url . '?Season=' . urlencode( $val );
                    $debug_log[] = "Refetching Season: " . $val;
                    $response2 = wp_remote_get( $season_url, $args );
                    if ( ! is_wp_error( $response2 ) ) {
                        $body = wp_remote_retrieve_body( $response2 );
                        $body = str_replace( array( "\r", "\n" ), " ", $body );
                        $debug_log[] = "Pass 2 HTML Size: " . strlen($body);
                    }
                    break;
                }
            }
        }

        // 1. Add spaces around all HTML tags so strip_tags doesn't fuse words together!
        $spaced_body = str_replace( '>', '> ', $body );
        $spaced_body = str_replace( '<', ' <', $spaced_body );

        // 2. Convert block-level structure tags and table rows to actual newlines
        $clean_html = preg_replace( '/<\/?(?:tr|h[1-6]|div|p|br|li|ul|table)[^>]*>/is', "\n", $spaced_body );
        
        // 3. Strip all the remaining tags
        $plain_text = wp_strip_all_tags( $clean_html );
        
        // 4. Break into clean lines
        $lines = explode( "\n", $plain_text );
        $debug_log[] = "Total Lines: " . count($lines);

        $exact_cache = array();
        $fl_cache    = array();
        $total_count = 0;

        $current_div   = '';
        $current_gen   = 'Male';
        $current_style = '';
        
        $stat_divs = 0;
        $stat_styles = 0;
        $stat_archers = 0;

        foreach ( $lines as $raw_line ) {
            $line = trim( preg_replace( '/\s+/', ' ', $raw_line ) );
            if ( empty( $line ) ) continue;

            if ( stripos( $line, 'Filters:' ) !== false || stripos( $line, 'Search' ) !== false || stripos( $line, 'Entries' ) !== false ) {
                continue;
            }

            $div_check = sywat_parse_tfaa_div_gen( $line );
            if ( ! empty( $div_check['division'] ) ) {
                $current_div = $div_check['division'];
                $current_gen = $div_check['gender'];
                $stat_divs++;
                continue;
            }

            $style_check = sywat_parse_tfaa_style( $line );
            if ( ! empty( $style_check ) ) {
                $current_style = $style_check;
                if ( stripos( $line, 'Pro' ) !== false && $current_style === 'FS – Freestyle' ) {
                    $current_style = 'FS Pro – Freestyle Pro';
                }
                $stat_styles++;
                continue;
            }

            // Looks for: Number + Space + (Last Name, First Name) + Space + Score Number
            if ( preg_match( '/^\d+\s+([A-Za-z\-\.\'\s]+,\s*[A-Za-z\-\.\'\s]+?)(?=\s+\d|$)/', $line, $matches ) ) {
                
                if ( empty( $current_div ) || empty( $current_style ) ) continue;

                $raw_name = trim( $matches[1] );
                
                // Flip "Last, First" to "First Last"
                $parts = explode( ',', $raw_name, 2 );
                $formatted_name = trim( $parts[1] ) . ' ' . trim( $parts[0] );

                $norm_key = strtolower( preg_replace( '/\s+/', ' ', $formatted_name ) );
                if ( empty( $norm_key ) || strlen( $norm_key ) < 3 ) continue;

                $name_parts  = explode( ' ', $norm_key );
                $fl_key = ( count( $name_parts ) > 1 ) ? reset( $name_parts ) . '___' . end( $name_parts ) : $norm_key;

                $entry = array(
                    'full_name' => $formatted_name,
                    'division'  => $current_div,
                    'gender'    => $current_gen,
                    'style'     => $current_style
                );

                if ( ! isset( $exact_cache[$norm_key] ) ) $exact_cache[$norm_key] = array();
                $is_dup = false;
                foreach ( $exact_cache[$norm_key] as $e ) {
                    if ( $e['division'] === $entry['division'] && $e['style'] === $entry['style'] ) {
                        $is_dup = true; break;
                    }
                }
                if ( ! $is_dup ) {
                    $exact_cache[$norm_key][] = $entry;
                    $stat_archers++;
                    $total_count++;
                }

                if ( ! isset( $fl_cache[$fl_key] ) ) $fl_cache[$fl_key] = array();
                $is_fl_dup = false;
                foreach ( $fl_cache[$fl_key] as $fe ) {
                    if ( $fe['division'] === $entry['division'] && $fe['style'] === $entry['style'] ) {
                        $is_fl_dup = true; break;
                    }
                }
                if ( ! $is_fl_dup ) {
                    $fl_cache[$fl_key][] = $entry;
                }
            }
        }

        $debug_log[] = "Found Divs: $stat_divs | Styles: $stat_styles | Archers: $stat_archers";
        
        $update_string = current_time( 'mysql' );
        if ( $total_count > 0 ) {
            $cache_payload = array(
                'updated_at' => $update_string,
                'count'      => $total_count,
                'exact'      => $exact_cache,
                'fl'         => $fl_cache
            );
            update_option( 'sywat_tfaa_cache', $cache_payload, false );
        } else {
            $update_string = "FAIL [ " . implode( ' | ', $debug_log ) . " ]";
        }

        $debug_str = implode(' | ', $debug_log);
        return array( 'success' => true, 'count' => $total_count, 'updated_at' => $update_string, 'debug' => $debug_str );
    }
}
