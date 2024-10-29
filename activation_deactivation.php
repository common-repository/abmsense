<?php

if (!defined('ABSPATH')) exit;

// Function to activate the plugin: creates the necessary temporary table using mysqli
function abmsense_activate() {
    global $wpdb;
    $table_name = "{$wpdb->prefix}" . ABMSENSE_PREFIX . 'temp_data';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        page_title text NOT NULL,
        time_spent int NOT NULL,
        page_view int NOT NULL,
        visitor_ip varchar(100) NOT NULL,
        header_ip varchar(100),
        visitor_city varchar(100),
        visitor_country varchar(100),
        visitor_company varchar(255),
        account_id varchar(100) NOT NULL,
        last_update date NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Set a transient to indicate that the plugin has just been activated
    set_transient(ABMSENSE_PREFIX . 'activation', true, 30);
}

// Function to set the plugin settings to default values
function abmsense_complete_activation() {
    // Check if the activation flag is set
    if (!get_transient(ABMSENSE_PREFIX . 'activation')) {
        return;
    }

    delete_transient(ABMSENSE_PREFIX . 'activation');
    abmsense_update_plugin_data();
}

function abmsense_version_check() {
    $current_version = abmsense_get_version();
    $stored_version = get_option(ABMSENSE_PREFIX . 'version_installed');

    // Return true if versions are different or if stored_version doesn't exist
    return ($current_version !== $stored_version || !$stored_version);
}

// Function to deactivate the plugin
function abmsense_deactivate() {
    delete_transient(ABMSENSE_PREFIX . 'activation');
    delete_transient(ABMSENSE_PREFIX . 'upgrade_completed');
}

// Function to handle plugin upgrades
function abmsense_upgrade_completed() {
    $upgrade_completed = get_transient(ABMSENSE_PREFIX . 'upgrade_completed');
    
    if (!$upgrade_completed && abmsense_version_check()) {
        // Set a transient to prevent multiple upgrade runs
        set_transient(ABMSENSE_PREFIX . 'upgrade_completed', true, 30);
        
        // Update plugin data
        abmsense_update_plugin_data();
        
        // Run any additional upgrade tasks
        abmsense_run_version_updates();
    }
}

// New centralized function to update plugin data
function abmsense_update_plugin_data() {
    $current_user = wp_get_current_user();
    $server_name = isset($_SERVER['SERVER_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'])) : '';
    $admin_email = $current_user->user_email ?: 'no-reply@' . $server_name;
    
    // Get existing values or set defaults
    $report_email = get_option(ABMSENSE_PREFIX . 'report_email') ?: $admin_email;
    $report_frequency = get_option(ABMSENSE_PREFIX . 'report_frequency') ?: 'daily';
    $is_consent = get_option(ABMSENSE_PREFIX . 'is_consent') ?: 0;
    $date_registered = get_option(ABMSENSE_PREFIX . 'date_registered') ?: gmdate('Y-m-d');
    $version_installed = abmsense_get_version();

    // Save/update all plugin data
    abmsense_save_data($server_name, $report_email, $report_frequency, $is_consent, $date_registered, $version_installed);
}

function abmsense_run_version_updates() {
    // Add any version-specific upgrade tasks here    
    // Clear any caches that might affect the plugin
    wp_cache_flush();
    delete_transient(ABMSENSE_PREFIX . 'upgrade_completed');
}

add_action('admin_init', 'abmsense_complete_activation');
add_action('admin_init', 'abmsense_upgrade_completed');
register_deactivation_hook(__FILE__, 'abmsense_deactivate');

// // Optional: Add this if you want to force an upgrade check on plugins page
// function abmsense_check_version_on_plugins_page($upgrader_object, $options) {
//     if ($options['action'] === 'update' && $options['type'] === 'plugin') {
//         // Check if our plugin was updated
//         $our_plugin = plugin_basename(__FILE__);
//         if (isset($options['plugins']) && in_array($our_plugin, $options['plugins'])) {
//             delete_transient(ABMSENSE_PREFIX . 'upgrade_completed');
//         }
//     }
// }
// add_action('upgrader_process_complete', 'abmsense_check_version_on_plugins_page', 10, 2);