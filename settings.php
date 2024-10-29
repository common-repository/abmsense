<?php

if (!defined('ABSPATH')) exit;

// Register plugin settings in WordPress
function abmsense_register_settings() {
    $settings = [
        'customer_name' => 'sanitize_text_field',
        'report_email' => 'abmsense_sanitize_email',
        'report_frequency' => 'abmsense_sanitize_frequency',
        'display_consent_message' => 'abmsense_sanitize_checkbox',
        'show_table_menu' => 'abmsense_sanitize_checkbox'
    ];

    foreach ($settings as $setting => $sanitize_callback) {
        register_setting(
            ABMSENSE_PREFIX . 'options',
            ABMSENSE_PREFIX . $setting,
            [
                'sanitize_callback' => $sanitize_callback,
            ]
        );
    }
}

// Sanitize email addresses
function abmsense_sanitize_email($input) {
    // For multiple emails
    $emails = explode(',', $input);
    $sanitized_emails = array_map('sanitize_email', $emails);
    return implode(',', array_filter($sanitized_emails));
}

// Sanitize report frequency
function abmsense_sanitize_frequency($input) {
    $allowed_values = ['daily', 'weekly', 'monthly'];
    return in_array($input, $allowed_values) ? $input : 'monthly';
}

// Sanitize checkbox values
function abmsense_sanitize_checkbox($input) {
    return (isset($input) && true == $input) ? '1' : '0';
}

// Save plugin settings to our_customers_details table
function abmsense_save_data($customer_name, $report_email, $report_frequency, $is_consent, $date_registered, $version_installed) {
    // Include the API configuration
    $config = include('db_config.php');
    $api_url = $config['upsert_abmsense_our_customers_details'];
    $public_key = $config['public_key'];

    // Prepare data for the API request
    $data = [
        'customer_name' => $customer_name,
        'report_email' => $report_email,
        'report_frequency' => $report_frequency,
        'is_consent' => $is_consent,
        'date_registered' => $date_registered,
        'version_installed' => $version_installed,
        'sku' => 'ABM-1000-ID-MONTHLY-FREE-TIER'
    ];

    // Encrypt the data
    $encrypted_data = abmsense_encrypt_data($data, $public_key);
    if (!$encrypted_data) {
        abmsense_log("save_data Error: Encryption failed", 'error');
        return false;
    }

    // Encode the data to JSON
    $json_data = wp_json_encode($encrypted_data);
    if (false === $json_data) {
        abmsense_log("Failed to encode data to JSON", 'error');
        return false;
    }

    // Send data to the API
    $response = wp_remote_post($api_url, array(
        'method'    => 'POST',
        'headers'   => array('Content-Type' => 'application/json'),
        'body'      => $json_data,
        'timeout'   => 60,
    ));

    // Check for errors
    if (is_wp_error($response)) {
        abmsense_log("save_data Error: API request failed - " . $response->get_error_message(), 'error');
        return false;
    }

    // Check the response code and body
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    // Decode the response body
    $result = json_decode($response_body, true);

    // Check for errors in the response
    if ($response_code !== 200 || !isset($result['success']) || $result['success'] !== true) {
        $error_message = isset($result['error']) ? $result['error'] : 'Unknown error';
        abmsense_log("save_data Error: API request failed - " . $error_message, 'error');
        return false;
    }

    // Update local WordPress options
    update_option(ABMSENSE_PREFIX . 'customer_name', $customer_name);
    update_option(ABMSENSE_PREFIX . 'report_email', $report_email);
    update_option(ABMSENSE_PREFIX . 'report_frequency', $report_frequency);
    update_option(ABMSENSE_PREFIX . 'display_consent_message', $is_consent);
    update_option(ABMSENSE_PREFIX . 'date_registered', $date_registered);    // Added this line
    update_option(ABMSENSE_PREFIX . 'version_installed', $version_installed); // Added this line


    // Clear the consent cache for this customer
    $cache_key = 'abmsense_consent_' . md5($customer_name);
    delete_transient($cache_key);
    abmsense_log("abmsense_save_data: Settings data saved successfully and cache cleared", 'info');
    return true;
}

// Validate email addresses
function abmsense_validate_emails($emails) {
    foreach (explode(',', $emails) as $email) {
        if (!filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            return false;
        }
    }
    return true;
}

// Get the plugin version from the main file
function abmsense_get_version() {
    global $wp_filesystem;

    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    $file = plugin_dir_path(__FILE__) . 'abmsense.php';
    $content = $wp_filesystem->get_contents($file);

    if ($content && preg_match('/Version:\s*([^\s]+)/', $content, $matches)) {
        return $matches[1];
    }

    return 'Unknown';
}

// Display the plugin wp-admin settings page
function abmsense_settings_page() {
    $current_user = wp_get_current_user();
    $admin_email = $current_user->user_email;
    $report_email = get_option(ABMSENSE_PREFIX . 'report_email', $admin_email);
    $report_frequency = get_option(ABMSENSE_PREFIX . 'report_frequency');
    $customer_name = get_option(ABMSENSE_PREFIX . 'customer_name');
    $is_consent = get_option(ABMSENSE_PREFIX . 'display_consent_message');
    $version_installed = abmsense_get_version();
    $date_registered = gmdate('Y-m-d');

    if (isset($_POST['submit'])) {
        if (!isset($_POST[ABMSENSE_PREFIX . 'nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[ABMSENSE_PREFIX . 'nonce'])), ABMSENSE_PREFIX . 'save_settings')) {
            echo "<b><span style='color: red;'>Nonce verification failed</span></b>";
            return;
        }
        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $report_email = isset($_POST['report_email']) ? sanitize_text_field(wp_unslash($_POST['report_email'])) : '';
        $report_frequency = isset($_POST['report_frequency']) ? sanitize_text_field(wp_unslash($_POST['report_frequency'])) : '';
        $is_consent = isset($_POST[ABMSENSE_PREFIX . 'display_consent_message']) ? 1 : 0;

        if (!$customer_name || !$report_email || !$report_frequency) {
            echo "<b><span style='color: red;'>Please fill in all fields</span></b>";
        } elseif (!abmsense_validate_emails($report_email)) {
            echo "<b><span style='color: red;'>Please enter valid email addresses</span></b>";
        } else {
            abmsense_save_data($customer_name, $report_email, $report_frequency, $is_consent, $date_registered, $version_installed);

            $frequency_messages = [
                'daily' => "The visitors identification report will be sent <b>daily at 4am</b> London time to <b>$report_email</b>.",
                'weekly' => "The visitors identification report will be sent <b>every Monday at 4am</b> London time to <b>$report_email</b>.",
                'monthly' => "The visitors identification report will be sent <b>every first day of the month at 4am</b> London time to <b>$report_email</b>."
            ];

            echo "<div class='notice notice-success is-dismissible'><p>" . wp_kses_post($frequency_messages[$report_frequency]) . "</p></div>";
        }
    }
    ?>
    <div class="wrap">
        <h1>ABMsense Settings <small>(Beta)</small></h1>
        <form method="post">
            <?php settings_fields(ABMSENSE_PREFIX . 'options'); ?>
            <?php wp_nonce_field(ABMSENSE_PREFIX . 'save_settings', ABMSENSE_PREFIX . 'nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="customer_name">Your Website:</label></th>
                    <td><input type="text" name="customer_name" id="customer_name" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'] ?? ''))); ?>" readonly style="background-color: #ccc; border: 1px solid #ccc;"></td>
                </tr>
                <tr>
                    <th><label for="report_frequency">Report Frequency:</label></th>
                    <td>
                        <select name="report_frequency" id="report_frequency">
                            <option value="daily" <?php selected($report_frequency, 'daily'); ?>>Daily</option>
                            <option value="weekly" <?php selected($report_frequency, 'weekly'); ?>>Weekly</option>
                            <option value="monthly" <?php selected($report_frequency, 'monthly'); ?>>Monthly</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="report_email">Send Report to Email(s):</label></th>
                    <td><input type="text" name="report_email" id="report_email" value="<?php echo esc_attr($report_email); ?>" placeholder="Separate multiple emails with commas" style="width: 50%;"></td>
                </tr>
                <tr>
                    <th><label for="<?php echo esc_attr(ABMSENSE_PREFIX . 'display_consent_message'); ?>">Display Consent Message:</label></th>
                    <td><input type="checkbox" name="<?php echo esc_attr(ABMSENSE_PREFIX . 'display_consent_message'); ?>" id="<?php echo esc_attr(ABMSENSE_PREFIX . 'display_consent_message'); ?>" value="1" <?php checked(1, $is_consent); ?>></td>
                </tr>
                <tr>
                    <th>Suggest a feature:</th>
                    <td><a href="mailto:info@abmsense.com" target="_blank">info@abmsense.com</a></td>
                </tr>
            </table>
            <?php submit_button('Save the Settings'); ?>
        </form>
    </div>
    <?php
}

// Display the plugin wp-admin table page using mysqli
function abmsense_display_admin_page() {
    global $wpdb;

    // Ensure UTF-8 for the WordPress database connection
    add_filter('pre_option_blog_charset', function() {
        return 'utf8';
    });

    $cache_key = ABMSENSE_PREFIX . 'temp_data';
    $results = wp_cache_get($cache_key);

    if ($results === false) {
        $table_name = "{$wpdb->prefix}" . ABMSENSE_PREFIX . 'temp_data';

        // Check if the table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            )
        );

        if (empty($table_exists)) {
            echo '<p>' . esc_html__('Required database table does not exist. Please deactivate and reactivate the plugin.', 'abmsense') . '</p>';
            return;
        }

        // Fetch results from the custom table
        $esc_table_name = esc_sql($table_name);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $results = $wpdb->get_results(
            $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM `$esc_table_name` LIMIT %d",
                    1000
            ),
            ARRAY_A
        );


        if ($wpdb->last_error) {
            abmsense_log('Database error: ' . $wpdb->last_error, 'error');
            echo '<p>' . esc_html__('Error fetching data from the database. Please try again later.', 'abmsense') . '</p>';
            return;
        }

        // Cache the results
        wp_cache_set($cache_key, $results, '', HOUR_IN_SECONDS);
    }

    // Ensure proper UTF-8 output
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<div class="wrap"><h2>ABMsense Real-time Data<small> (Beta)</small></h2>';

    if (empty($results)) {
        echo '<p>No data available.</p></div>';
        return;
    }

    echo '<table class="widefat fixed" cellspacing="0"><thead><tr>';
    echo '<th>ID</th><th>Page Title</th><th>Time Spent (s)</th><th>Page Views</th><th>Visitor IP</th><th>Header IP</th><th>Visitor City</th><th>Visitor Country</th><th>Visitor Company</th><th>Account ID</th><th>Last Update</th>';
    echo '</tr></thead><tbody>';

    foreach ($results as $row) {
        echo '<tr>';
        foreach ($row as $key => $value) {
            if ($key === 'last_update') $value = gmdate('Y-m-d', strtotime($value));
            echo '<td>' . esc_html($value) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

// Enqueue the plugin admin styles
function abmsense_enqueue_admin_styles($hook) {
    // Only enqueue on ABMsense admin pages
    if ('toplevel_page_' . ABMSENSE_PREFIX . 'settings' !== $hook && ABMSENSE_PREFIX . 'page_' . ABMSENSE_PREFIX . 'table' !== $hook) {
        return;
    }
    
    wp_enqueue_style(ABMSENSE_PREFIX . 'admin-style', plugin_dir_url(__FILE__) . 'css/abmsense-admin-style.css', array(), '1.0.0');
}

// Create the plugin admin menu
function abmsense_create_admin_menu() {
    add_menu_page(
        'ABMsense',
        'ABMsense',
        'manage_options',
        ABMSENSE_PREFIX . 'settings',
        'abmsense_settings_page',
        'dashicons-visibility',
        6
    );

    add_submenu_page(
        ABMSENSE_PREFIX . 'settings',
        'ABMsense Settings',
        'Settings',
        'manage_options',
        ABMSENSE_PREFIX . 'settings',
        'abmsense_settings_page'
    );

    if (get_option(ABMSENSE_PREFIX . 'show_table_menu', false)) {
        add_submenu_page(
            ABMSENSE_PREFIX . 'settings',
            'ABMsense Data',
            'Real-time Data',
            'manage_options',
            ABMSENSE_PREFIX . 'table',
            'abmsense_display_admin_page'
        );
    }

    // Add the new "On Demand" tab
    add_submenu_page(
        ABMSENSE_PREFIX . 'settings',
        'Generate Your Report',
        'Reports',
        'manage_options',
        ABMSENSE_PREFIX . 'on_demand',
        'abmsense_on_demand_page'
    );
}

// Toggle the display of the table menu
function abmsense_toggle_table_menu($visible) {
    update_option(ABMSENSE_PREFIX . 'show_table_menu', $visible);
}

// Display the "On Demand" page
function abmsense_on_demand_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="" id="abmsense-export-form">
            <?php wp_nonce_field('abmsense_export_nonce', 'abmsense_export_nonce'); ?>
            <h2>Export Visitors Data</h2>
            <p>Choose the time range for your report:</p>
            <select name="abmsense_export_period">
                <option value="Today">Today</option>
                <option value="Yesterday">Yesterday</option>
                <option value="This_week">This week (Mon - Today)</option>
                <option value="Last_week">Last week (Mon - Sun)</option>
                <option value="Last_7_days">Last 7 days</option>
                <option value="Last_30_days">Last 30 days</option>
                <option value="All_time">All time</option>
            </select>
            <?php submit_button('Export Now', 'primary', 'abmsense_export_submit'); ?>
        </form>
        <div id="abmsense-export-status" style="display: none; margin-top: 20px; font-weight: bold;"></div>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#abmsense-export-form').on('submit', function(e) {
            e.preventDefault();
            $('#abmsense-export-status').text('Please wait...').show();
            
            var formData = $(this).serialize();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData + '&action=abmsense_export_data',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (response.data && Array.isArray(response.data) && response.data.length > 0) {
                            abmsense_generate_csv(response.data, $('select[name="abmsense_export_period"]').val());
                            $('#abmsense-export-status').text('Export completed successfully.').show();
                        } else {
                            console.warn('No data available for export');
                            $('#abmsense-export-status').text('No Results').show();
                        }
                    } else {
                        console.error('Error in AJAX response:', response.error);
                        $('#abmsense-export-status').text('Error: ' + response.error).show();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('AJAX request failed:', textStatus, errorThrown);
                    $('#abmsense-export-status').text('An error occurred. Please try again.').show();
                }
            });
        });
    });

    function abmsense_generate_csv(data, period) {
        if (!Array.isArray(data) || data.length === 0) {
            console.error('Data is not an array or is empty');
            alert('No data available for export.');
            return;
        }

        var csv = 'data:text/csv;charset=utf-8,';
        var keys = [
            'Company name', 'Lead Score', 'Visit Duration', 'Number of Visits', 'Number of employees', 
            'HQ', 'Company Email', 'Company Phone', 'Web Domain', 'Industry', 
            'Revenue', 'LinkedIn', 'Top 3 Journey pages', 'Last visited date'
        ];
        csv += keys.join(',') + '\n';

        data.forEach(function(row, index) {            
            var rowData = [
                escapeCsvValue(row.company_name),
                escapeCsvValue(row.lead_score),
                escapeCsvValue(row.visit_duration),
                escapeCsvValue(row.number_of_visits),
                escapeCsvValue(row.number_of_employees),
                escapeCsvValue(row.hq),
                escapeCsvValue(row.company_email),
                escapeCsvValue(row.company_phone),
                escapeCsvValue(row.web_domain),
                escapeCsvValue(row.industry),
                escapeCsvValue(row.revenue),
                escapeCsvValue(row.linkedin),
                escapeCsvValue(row.top_3_journey_pages),
                escapeCsvValue(row.last_visited_date)
            ];

            csv += rowData.join(',') + '\n';
        });

        var encodedUri = encodeURI(csv);
        var link = document.createElement('a');
        link.setAttribute('href', encodedUri);
        link.setAttribute('download', 'abmsense_export_' + period + '_' + new Date().toISOString().slice(0,10) + '.csv');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        console.log('CSV generation completed');
    }

    function escapeCsvValue(value) {
        if (value === null || value === undefined) {
            return '';
        }
        value = value.toString();
        value = value.replace(/"/g, '""');
        if (value.indexOf(',') !== -1 || value.indexOf('"') !== -1 || value.indexOf('\n') !== -1) {
            value = '"' + value + '"';
        }
        return value;
    }
    </script>
    <?php
}

// Handle the AJAX export request
function abmsense_handle_export() {
    check_ajax_referer('abmsense_export_nonce', 'abmsense_export_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $export_period = $_POST['abmsense_export_period'];
    $config = include('db_config.php');
    $api_url = $config['export_data_url'];
    $public_key = $config['public_key'];

    $customer_name = get_option(ABMSENSE_PREFIX . 'customer_name');
    $report_email = get_option(ABMSENSE_PREFIX . 'report_email');

    $data = [
        'customer_name' => $customer_name,
        'report_email' => $report_email,
        'export_period' => $export_period
    ];

    $encrypted_data = abmsense_encrypt_data($data, $public_key);
    if (!$encrypted_data) {
        wp_send_json_error('Encryption failed');
    }

    $response = wp_remote_post($api_url, array(
        'method'    => 'POST',
        'headers'   => array('Content-Type' => 'application/json'),
        'body'      => wp_json_encode($encrypted_data), // Using wp_json_encode
        'timeout'   => 60,
    ));

    if (is_wp_error($response)) {
        wp_send_json_error('API request failed: ' . $response->get_error_message());
    }

    $response_body = wp_remote_retrieve_body($response);
    $result = json_decode($response_body, true);

    if (!isset($result['success']) || !$result['success']) {
        wp_send_json_error($result['error'] ?? 'Unknown error occurred');
    }

    wp_send_json_success($result['data']);
}


add_action('wp_ajax_abmsense_export_data', 'abmsense_handle_export');
add_action('admin_enqueue_scripts', 'abmsense_enqueue_admin_styles');
add_action('admin_init', 'abmsense_register_settings');
add_action('admin_menu', 'abmsense_create_admin_menu');
abmsense_toggle_table_menu(true);

?>