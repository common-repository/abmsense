<?php

if (!defined('ABSPATH')) exit;

// Function to track visitor information and update the databases
function abmsense_track_visitor() {
    global $wpdb;

    // Collect necessary information
    $customer_name = isset($_SERVER['SERVER_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'])) : '';
    $visitor_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $header_ip = abmsense_get_real_ip_from_headers();  // Custom function to get IP from headers
    $page_title = html_entity_decode(wp_get_document_title(), ENT_QUOTES, 'UTF-8');

    // Get visitor information (city, country, company) from a custom function
    $visitor_info = abmsense_get_visitor_info($visitor_ip);
    if (!$visitor_info) {
        return;
    }

    // Generate a unique cache key (optional, you can still cache the account_id)
    $cache_key = ABMSENSE_PREFIX . 'account_id_' . md5($visitor_ip . $header_ip . $page_title);
    $account_id = wp_cache_get($cache_key);

    // Query WordPress database for account_id if not in cache (optional)
    if (false === $account_id) {
        // If not in cache, query the WordPress temp table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $account_id = $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT account_id FROM {$wpdb->prefix}" . ABMSENSE_PREFIX . "temp_data WHERE (visitor_ip = %s OR header_ip = %s) AND page_title = %s",
            $visitor_ip, $header_ip, $page_title
        ));

        wp_cache_set($cache_key, $account_id, '', HOUR_IN_SECONDS);  // Cache the result (optional)
    }

    // If no account_id, exit the function
    if (!$account_id) return;

    // Prepare the data to be sent to the Flask API
    $query_params = [
        'customer_name'    => $customer_name,
        'visitor_ip'       => $visitor_ip,
        'header_ip'        => $header_ip,
        'account_id'       => $account_id,
        'visitor_city'     => $visitor_info['city'],
        'visitor_country'  => $visitor_info['country'],
        'visitor_company'  => $visitor_info['company'],
        'page_title'       => $page_title,
        'time_spent'       => 0,   // Initialize with 0, or adjust based on your logic
        'page_view'        => 1,   // Initialize with 1, or adjust as needed
        'last_update'      => current_time('mysql'),  // Get current time
        'is_isp_tested'    => 0    // Default value for is_isp_tested
    ];

    // Encrypt the data before sending it to the API
    $api_config = include('db_config.php');
    $public_key = $api_config['public_key'];
    $encrypted_main_data = abmsense_encrypt_data($query_params, $public_key);

    // Encode the data into JSON format
    $json_body = wp_json_encode(['data' => $encrypted_main_data]);
    if (false === $json_body) {
        abmsense_log(ABMSENSE_PREFIX . 'JSON encoding failed for main_data', 'error');
        return;
    }

    // Make the API call to the unified route on the Flask API server
    $main_data_url = $api_config['upsert_abmsense_main_data']; 
    $response = wp_remote_post($main_data_url, array(
        'method'    => 'POST',
        'headers'   => array('Content-Type' => 'application/json'),
        'body'      => $json_body,
    ));

    // Error handling for the API request
    if (is_wp_error($response)) {
        abmsense_log(ABMSENSE_PREFIX . 'API main_data request failed: ' . $response->get_error_message(), 'error');
        return;
    }

    // Invalidate the cache after updating/inserting data (optional)
    wp_cache_delete($cache_key);
}

// Hook the function to run when the WordPress page loads
add_action('wp', 'abmsense_track_visitor');
