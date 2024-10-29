<?php

if (!defined('ABSPATH')) exit;

function abmsense_log($message, $level = 'info') {
    // Define log levels
    $valid_levels = ['error', 'warning', 'info', 'success'];
    $level = in_array(strtolower($level), $valid_levels) ? strtolower($level) : 'info';

    // Create log entry
    $log_entry = gmdate('Y-m-d') . ' ' . strtoupper($level) . ': ' . $message . PHP_EOL;

    // Use WP_Filesystem to write log
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once (ABSPATH . '/wp-admin/includes/file.php');
        WP_Filesystem();
    }

    if ($wp_filesystem) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/abmsense_log.txt';
        
        // Read existing content
        $existing_content = $wp_filesystem->get_contents($log_file);
        if ($existing_content === false) {
            $existing_content = '';
        }
        
        // Append new log entry
        $wp_filesystem->put_contents($log_file, $existing_content . $log_entry, FS_CHMOD_FILE);
    }
}


function abmsense_encrypt_data($data, $public_key_pem) {
    // Load the public key
    $public_key = openssl_pkey_get_public($public_key_pem);
    if ($public_key === false) {
        return new WP_Error('encryption_error', esc_html__('Failed to load public key', 'abmsense') . ': ' . esc_html(openssl_error_string()));
    }

    // Generate a random 256-bit AES key
    $aes_key = random_bytes(32);

    // Generate IV
    $iv = random_bytes(16);

    // JSON encode the data
    $json_data = wp_json_encode($data);
    if ($json_data === false) {
        return new WP_Error('encryption_error', esc_html__('JSON encoding failed', 'abmsense'));
    }

    // Encrypt the data with AES-256-CBC
    $encrypted_data = openssl_encrypt($json_data, 'aes-256-cbc', $aes_key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted_data === false) {
        return new WP_Error('encryption_error', esc_html__('AES encryption failed', 'abmsense') . ': ' . esc_html(openssl_error_string()));
    }

    // Encrypt the AES key with RSA public key
    $encrypted_aes_key = '';
    $result = openssl_public_encrypt($aes_key, $encrypted_aes_key, $public_key, OPENSSL_PKCS1_OAEP_PADDING);
    if ($result === false) {
        return new WP_Error('encryption_error', esc_html__('RSA encryption failed', 'abmsense') . ': ' . esc_html(openssl_error_string()));
    }

    return [
        'encrypted_data' => base64_encode($encrypted_data),
        'encrypted_key' => base64_encode($encrypted_aes_key),
        'iv' => base64_encode($iv)
    ];
}