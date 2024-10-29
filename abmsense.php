<?php
/*
Plugin Name: ABMsense - Visitor Identification for B2B Pipeline Growth
Description: Turn Site Visitors into Hot Leads– It's Marketing Magic! ABMsense is a powerful tool that helps you to identify and track your website visitors. It provides you with the information you need to turn your website visitors into hot leads. With ABMsense, you can track your website visitors, identify their company, and get detailed information about their visit. Go to Settings -> ABMsense to configure the plugin.
Version: 2.0.3
Author: Ashley Smith
Requires PHP: 7.2
Author URI: https://www.abmsense.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) exit;

add_action('init', function() {
    if (!headers_sent() && !session_id()) {
        session_start();
    }
}, 1);


// Define plugin prefix
define('ABMSENSE_PREFIX', 'abmsense_');

// PHP version check
if (version_compare(PHP_VERSION, '7.2', '<')) {
    deactivate_plugins(plugin_basename(__FILE__));
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
        <p><?php esc_html_e('ABMsense requires PHP version 7.2 or higher. The plugin has been deactivated.', 'abmsense'); ?></p>
        </div>
        <?php
    });
    return;
}

require_once plugin_dir_path(__FILE__) . 'activation_deactivation.php';
require_once plugin_dir_path(__FILE__) . 'settings.php';
require_once plugin_dir_path(__FILE__) . 'visitor_tracking.php';
require_once plugin_dir_path(__FILE__) . 'utility.php';

// Start session if not already started, to manage consent
add_action('muplugins_loaded', function() {
    if (!session_id()) {
        session_start();
    }
    if (!isset($_SESSION['abmsense_consent'])) {
        $_SESSION['abmsense_consent'] = false;
    }
    session_write_close();
});

// Ensure sessions are closed before REST API or loopback requests
add_action('rest_api_init', function() {
    if (session_id()) {
        session_write_close();
    }
}, 99);

add_action('admin_init', function() {
    if (session_id()) {
        session_write_close(); // Close session before admin-ajax or admin requests
    }
}, 99);

// Enqueue necessary scripts for the frontend
function abmsense_enqueue_scripts() {
    if (!is_admin() && !wp_doing_ajax()) {
        wp_enqueue_script(ABMSENSE_PREFIX . 'intent', plugin_dir_url(__FILE__) . 'js/abmsense_intent.js', array('jquery'), '1.0.0', true);
        wp_localize_script(ABMSENSE_PREFIX . 'intent', ABMSENSE_PREFIX . 'ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'security' => wp_create_nonce(ABMSENSE_PREFIX . 'ajax_nonce')
        ));

        wp_register_script(ABMSENSE_PREFIX . 'consent', plugin_dir_url(__FILE__) . 'js/abmsense_consent.js', array(), '1.0', true);
    }
}

//  Function to run the plugin
function abmsense_run() {
    ob_start();

    $exclude_keywords = array('/wp-admin/', '/yoast/', '/wp-json/yoast/v1/wincher/', '/google-site-kit/', '/plugins/', '/admin-ajax.php', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-content/plugins/abmsense/');
    $script_name = isset($_SERVER['SCRIPT_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME'])) : '';
    foreach ($exclude_keywords as $keyword) {
        if (strpos($script_name, $keyword) !== false) {
            ob_end_clean();
            return;
        }
    }

    $our_customer = isset($_SERVER['SERVER_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'])) : '';

    $is_consent_enabled = abmsense_check_consent_enabled($our_customer);
    $is_consent_given = isset($_SESSION[ABMSENSE_PREFIX . 'consent']) && $_SESSION[ABMSENSE_PREFIX . 'consent'] === true;

    if ($is_consent_given || !$is_consent_enabled) {
        require_once plugin_dir_path(__FILE__) . 'visitor_tracking.php';
    }

    ob_end_flush();
}

// Function to enqueue consent script if consent is enabled
function abmsense_maybe_enqueue_consent_script() {
    $our_customer = isset($_SERVER['SERVER_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'])) : '';

    $is_consent_given = isset($_SESSION[ABMSENSE_PREFIX . 'consent']) && $_SESSION[ABMSENSE_PREFIX . 'consent'] === true;

    if ($is_consent_given) {
        abmsense_log("Consent already given for {$our_customer}", 'debug');
        return;
    }

    $is_consent_enabled = abmsense_check_consent_enabled($our_customer);

    if ($is_consent_enabled) {
        wp_enqueue_script(
            ABMSENSE_PREFIX . 'consent',
            plugin_dir_url(__FILE__) . 'js/abmsense_consent.js',
            array(), // dependencies
            '1.0.0', // version number
            true // in footer
        );
    }
}

// Function to add defer attribute to the abmsense_consent script
function abmsense_add_defer_attribute($tag, $handle) {
    if (ABMSENSE_PREFIX . 'consent' === $handle) {
        return str_replace(' src', ' defer="defer" src', $tag);
    }
    return $tag;
}
add_filter('script_loader_tag', 'abmsense_add_defer_attribute', 10, 2);


// Function to check consent status (1-day cache)
function abmsense_check_consent_enabled($customer_name) {
    $cache_key = 'abmsense_consent_' . md5($customer_name);
    $cache_duration = 86400; // Cache for 1 day (24 hours * 60 minutes * 60 seconds)

    // Check if we have a valid cached value
    $cached_value = get_transient($cache_key);
    if ($cached_value !== false) {
        return $cached_value === '1';
    }

    // If not in cache or expired, make the API call
    $api_config = include('db_config.php');
    $api_url = $api_config['check_consent_enabled'];
    $public_key = $api_config['public_key'];

    $api_data = array('customer_name' => $customer_name);
    $encrypted_data = abmsense_encrypt_data($api_data, $public_key);

    if (!$encrypted_data) {
        abmsense_log("Encryption failed in abmsense_check_consent_enabled", 'error');
        return false;
    }

    $json_data = wp_json_encode($encrypted_data);
    if (false === $json_data) {
        abmsense_log("Failed to encode data to JSON", 'error');
        return false;
    }

    $response = wp_remote_post($api_url, array(
        'method'    => 'POST',
        'headers'   => array('Content-Type' => 'application/json'),
        'body'      => $json_data,
        'timeout'   => 30,
    ));

    if (is_wp_error($response)) {
        abmsense_log('API request to check consent failed: ' . $response->get_error_message(), 'error');
        return false;
    }

    $response_body = wp_remote_retrieve_body($response);
    $result = json_decode($response_body, true);

    if (!isset($result['success']) || $result['success'] !== true) {
        $error_message = isset($result['error']) ? $result['error'] : 'Unknown error';
        abmsense_log('API response indicates failure: ' . $error_message, 'error');
        return false;
    }

    $is_consent_enabled = isset($result['is_consent_enabled']) ? $result['is_consent_enabled'] : false;

    // Cache the result for 1 day
    set_transient($cache_key, $is_consent_enabled ? '1' : '0', $cache_duration);

    return $is_consent_enabled;
}

// Function to get visitor information using IP-API
function abmsense_get_visitor_info($ip) {
    $url = 'http://ip-api.com/csv/' . urlencode($ip) . '?fields=city,country,org';
    $response = wp_remote_get($url);

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);

    if (empty($body)) {
        return false;
    }

    $data = str_getcsv($body);
    return array(
        'city' => isset($data[1]) ? sanitize_text_field($data[1]) : '',
        'country' => isset($data[0]) ? sanitize_text_field($data[0]) : '',
        'company' => isset($data[2]) ? sanitize_text_field($data[2]) : ''
    );
}

// Function to get real IP address from headers
function abmsense_get_real_ip_from_headers() {
    $headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_X_CLIENT_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED',
        'HTTP_X_FORWARDED_HOST',
        'HTTP_X_FORWARDED_SERVER',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_X_ANONYMOUS_IP',
        'HTTP_X_ORIGINAL_FOR',
        'HTTP_FASTLY_CLIENT_IP',
        'HTTP_X_AZURE_CLIENTIP'
    ];

    foreach ($headers as $header) {
        if (isset($_SERVER[$header])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                if ($header === 'HTTP_X_FORWARDED_FOR') {
                    $ipArray = explode(',', $ip);
                    $ip = trim($ipArray[0]);
                }
                return $ip;
            }
        }
    }

    return '';
}

// Function to save temporary data using mysqli
function abmsense_temp_save() {
    global $wpdb;
    check_ajax_referer(ABMSENSE_PREFIX . 'ajax_nonce', 'security');

    if (!empty($_POST['hits'])) {
        $hits = json_decode(sanitize_text_field(wp_unslash($_POST['hits'])), true);
        $account_id = !empty($_POST['account_id']) ? sanitize_text_field(wp_unslash($_POST['account_id'])) : '';
        $visitor_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'UNKNOWN';
        $header_ip = abmsense_get_real_ip_from_headers();
        
        // Cache visitor info
        $cache_key = ABMSENSE_PREFIX . 'visitor_info_' . md5($visitor_ip);
        $visitor_info = wp_cache_get($cache_key);
        if (false === $visitor_info) {
            $visitor_info = abmsense_get_visitor_info($visitor_ip) ?: array('city' => '', 'country' => '', 'company' => '');
            wp_cache_set($cache_key, $visitor_info, '', 3600); // Cache for 1 hour
        }

        $table_name = "{$wpdb->prefix}" . ABMSENSE_PREFIX . 'temp_data';
        
        $today_date = current_time('Y-m-d');

        foreach ($hits as $hit) {
            $last_update = DateTime::createFromFormat('m-d-Y', sanitize_text_field($hit['last_update']));
            $last_update_formatted = $last_update ? $last_update->format('Y-m-d') : '';

            if ($last_update_formatted !== $today_date) {
                continue;
            }

            // Generate a unique cache key
            $cache_key = ABMSENSE_PREFIX . 'existing_record_' . md5($hit['page_title'] . $visitor_ip . $today_date);

            // Try to get the existing record from cache
            $existing_record = wp_cache_get($cache_key, ABMSENSE_PREFIX . 'records');

            if (false === $existing_record) {
                // If not in cache, query the database
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $existing_record = $wpdb->get_row($wpdb->prepare(
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    "SELECT * FROM `" . $wpdb->prefix . ABMSENSE_PREFIX . "temp_data` WHERE page_title = %s AND visitor_ip = %s AND last_update = %s",
                    $hit['page_title'], $visitor_ip, $today_date
                ));

                // Cache the result for future use (cache for 1 hour)
                wp_cache_set($cache_key, $existing_record, ABMSENSE_PREFIX, HOUR_IN_SECONDS);
            }

            if ($existing_record && !empty($account_id) && $existing_record->account_id == $account_id) {
                // Update existing record
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->update(
                    $table_name,
                    array(
                        'time_spent' => $hit['time_spent'],
                        'page_view' => $hit['page_view'],
                        'last_update' => $last_update_formatted
                    ),
                    array('id' => $existing_record->id),
                    array('%d', '%d', '%s'),
                    array('%d')
                );
                // Delete the cache after update
                wp_cache_delete(ABMSENSE_PREFIX . 'local_data_' . md5($wpdb->prefix . ABMSENSE_PREFIX . 'temp_data'), ABMSENSE_PREFIX . 'local_data');
            } elseif (!$existing_record) {
                // Insert new record
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->insert(
                    $table_name,
                    array(
                        'page_title' => $hit['page_title'],
                        'time_spent' => $hit['time_spent'],
                        'page_view' => $hit['page_view'],
                        'visitor_ip' => $visitor_ip,
                        'header_ip' => $header_ip,
                        'visitor_city' => $visitor_info['city'],
                        'visitor_country' => $visitor_info['country'],
                        'visitor_company' => $visitor_info['company'],
                        'account_id' => $account_id,
                        'last_update' => $last_update_formatted
                    ),
                    array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
                );
                // Delete the cache after insert
                wp_cache_delete(ABMSENSE_PREFIX . 'local_data_' . md5($wpdb->prefix . ABMSENSE_PREFIX . 'temp_data'), ABMSENSE_PREFIX . 'local_data');
            }

            if ($wpdb->last_error) {
                abmsense_log('ABMsense: Database operation failed: ' . $wpdb->last_error, 'error');
            }
        }

        wp_send_json_success();
    } else {
        wp_send_json_error('No hits data received');
    }
}

// Function to transfer data from temp table to main table
function abmsense_transfer_data_to_main_db() {
    global $wpdb;
    $local_table_name = $wpdb->prefix . ABMSENSE_PREFIX . 'temp_data';
    $customer_name = isset($_SERVER['SERVER_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'])) : '';

    $api_config = include('db_config.php');
    $api_upsert_url = $api_config['upsert_abmsense_main_data'];
    $public_key = $api_config['public_key'];

    // Generate a unique cache key
    $cache_key = 'abmsense_local_data_' . md5($local_table_name);

    // Try to get the data from cache
    $local_data = wp_cache_get($cache_key);

    if (false === $local_data) {
        // If not in cache, query the database
        $sql = "SELECT * FROM " . $local_table_name; // Concatenate the table name safely
    
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be prepared
        $local_data = $wpdb->get_results($sql, ARRAY_A);
    
        // Cache the result for future use (cache for 1 hour)
        wp_cache_set($cache_key, $local_data, '', HOUR_IN_SECONDS);
    }
    

    $total_records = count($local_data);

    $successful_transfers = 0;
    $failed_transfers = 0;

    foreach ($local_data as $index => $record) {
        $data = abmsense_prepare_data_for_transfer($record, $customer_name);
        $encrypted_data = abmsense_encrypt_data($data, $public_key);

        if (!$encrypted_data) {
            abmsense_log("Encryption failed for record " . ($index + 1), 'error');
            $failed_transfers++;
            continue;
        }

        $json_body = wp_json_encode($encrypted_data);
        if (false === $json_body) {
            abmsense_log("JSON encoding failed for API request", 'error');
            $failed_transfers++;
            continue;
        }

        $response = wp_remote_post($api_upsert_url, array(
            'method'    => 'POST',
            'headers'   => array('Content-Type' => 'application/json'),
            'body'      => $json_body,
            'timeout'   => 30,
        ));

        if (is_wp_error($response)) {
            abmsense_log('API request failed for record ' . ($index + 1) . ': ' . $response->get_error_message(), 'error');
            $failed_transfers++;
            continue;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $result = json_decode($response_body, true);

        if ($response_code === 200 && isset($result['success']) && $result['success'] === true) {
            $successful_transfers++;
        } else {
            $failed_transfers++;
            $error_message = isset($result['error']) ? $result['error'] : 'Unknown error';
            abmsense_log("Failed to transfer record " . ($index + 1) . ". Error: " . $error_message, 'error');
        }
    }

    $escaped_table_name = $wpdb->_real_escape($local_table_name);

    if ($successful_transfers === $total_records) {
        global $wpdb;
        
        // Sanitize the table name
        $table_name = $wpdb->prefix . 'abmsense_temp_data';
        
        // Check if the table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));
    
        if ($table_exists) {
            // Execute the TRUNCATE query
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}abmsense_temp_data");
        } else {
            abmsense_log(sprintf("Table %s does not exist.", $table_name), 'error');
        }
        // No need to log success
    } else {
        abmsense_log(sprintf("Transfer completed with %d successes and %d failures.", $successful_transfers, $failed_transfers), 'warning');
    }
}

// Function to prepare data for transfer
function abmsense_prepare_data_for_transfer($record, $customer_name) {
    return array(
        'customer_name' => $customer_name,
        'page_title' => mb_convert_encoding(html_entity_decode($record['page_title'], ENT_QUOTES, 'UTF-8'), 'UTF-8', 'UTF-8'),
        'time_spent' => intval($record['time_spent']),
        'page_view' => intval($record['page_view']),
        'visitor_ip' => $record['visitor_ip'],
        'visitor_city' => mb_convert_encoding($record['visitor_city'], 'UTF-8', 'UTF-8'),
        'visitor_country' => mb_convert_encoding($record['visitor_country'], 'UTF-8', 'UTF-8'),
        'visitor_company' => mb_convert_encoding($record['visitor_company'], 'UTF-8', 'UTF-8'),
        'account_id' => $record['account_id'],
        'last_update' => current_time('Y-m-d'),
        'header_ip' => $record['header_ip'] ?: null,
    );
}

// Function to add custom intervals for cron jobs
function abmsense_add_custom_intervals($schedules) {
    $schedules['one_hour'] = array(
        'interval' => 3600, // 1 hour in seconds
        'display' => esc_html__('Every 1 Hour', 'abmsense')
    );
    return $schedules;
}

// Function to set consent in session
function abmsense_set_consent() {
    $_SESSION['abmsense_consent'] = true;
    wp_send_json_success('Consent recorded');
}

add_action('wp_enqueue_scripts', 'abmsense_enqueue_scripts');
add_action('init', 'abmsense_run');
add_action('wp_enqueue_scripts', 'abmsense_maybe_enqueue_consent_script', 999);
add_action('wp_ajax_abmsense_temp_save', 'abmsense_temp_save');
add_action('wp_ajax_nopriv_abmsense_temp_save', 'abmsense_temp_save');
add_action('abmsense_one_hour_data_transfer', 'abmsense_transfer_data_to_main_db');
add_filter('cron_schedules', 'abmsense_add_custom_intervals');
add_action('wp_ajax_abmsense_set_consent', 'abmsense_set_consent');
add_action('wp_ajax_nopriv_abmsense_set_consent', 'abmsense_set_consent');


if (!wp_next_scheduled('abmsense_one_hour_data_transfer')) {
    wp_schedule_event(time(), 'one_hour', 'abmsense_one_hour_data_transfer');
}

register_activation_hook(__FILE__, 'abmsense_activate');