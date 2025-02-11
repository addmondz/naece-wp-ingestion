<?php

define('WP_USE_THEMES', false);
require_once('../wp-config.php');

ini_set('max_execution_time', 0); // No time limit
ini_set('memory_limit', '-1');    // Unlimited memory

// Validate Fields
$main_category = $_POST['mainCategory'] ?? '';
$sub_category = $_POST['subCategory'] ?? '';
$csvFile = $_POST['csvFile'] ?? '';

$fields = [
    'mainCategory' => $main_category,
    'subCategory'  => $sub_category,
    'csvFile'      => $csvFile,
];
$fields = array_map('sanitize_text_field', $fields);
$missing = array_keys(array_filter($fields, fn($v) => empty($v)));
if (!empty($missing)) {
    echo json_encode([
        'message'   => 'The following required fields are missing or empty: ' . implode(', ', $missing),
        'remaining' => 0
    ]);
    exit;
}

// Get the offset and batch size from the POST request
$offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
$batch_size = isset($_POST['batchSize']) ? (int)$_POST['batchSize'] : 50;

// manually insert the fields
// $csvFile = 'certified-boilers-2025-02-02.csv';
// $csvFile = 'certified-ventilating-fans-2025-02-02.csv';
// $main_category = 'lighting';
// $sub_category = 'light-bulbs';

// append csv directory 
$csvFile = __DIR__ . '/csv/product/' . $csvFile;

// Read the CSV file content and remove BOM
$csvContent = str_replace("\r\n", "\n", preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($csvFile)));

// Convert CSV content into an array of rows
$lines = array_map('str_getcsv', array_filter(explode("\n", trim($csvContent))));

// Initialize counters
$fetched_count = 0;
$duplicate_count = 0;
$null_count = 0;
$processed_count = 0;  // Track processed rows

$naece_id_found = 0;
$naece_id_not_found = 0;

// Check if there are any rows in the CSV file
if ($lines) {
    $headers = array_shift($lines); // Extract headers
    $total_records = count($lines);
    $chunks = array_chunk($lines, $batch_size); // Split the data into chunks

    // Calculate remaining records properly
    $remaining = max(0, $total_records - $offset);

    // If no more records to process
    if ($offset >= $total_records) {
        echo json_encode([
            'message' => 'No more records to process.',
            'remaining' => 0,
            'total_records' => $total_records
        ]);
        exit;
    }

    // Get the current chunk based on the offset
    $current_chunk = array_slice($chunks, $offset / $batch_size, 1);

    if (empty($current_chunk)) {
        echo json_encode([
            'message' => 'No more records to process.',
            'remaining' => count($lines) - $offset,
        ]);
        exit;
    }

    // Process the current batch
    $batch = $current_chunk[0];
    global $wpdb;
    $wpdb->query('START TRANSACTION'); // Start transaction for batch processing

    foreach ($batch as $row) {
        $item = array_combine($headers, $row);

        // Extract API ID
        $api_id = sanitize_text_field($item['ENERGY STAR Unique ID'] ?? '');

        // Skip if API ID is empty or already exists
        if (empty($api_id)) {
            $null_count++;
            continue;
        }

        if (post_exists_by_meta('_apiid', $api_id)) {
            $duplicate_count++;
            continue;
        }

        $name         = sanitize_text_field($item['Energy Star Partner'] ?? 'No Title');
        $brand        = sanitize_text_field($item['Brand Name'] ?? 'Default Value 1');
        $model_name   = sanitize_text_field($item['model_name'] ?? 'Default Value 2');
        $model_number = sanitize_text_field($item['Model Number'] ?? '');

        $post_data = array(
            'post_title'   => $model_number,
            'post_status'  => 'draft',
            'post_type'    => 'job_listing',
        );

        $post_id = wp_insert_post($post_data); // Insert post

        if ($post_id) {
            $fetched_count++;
            update_post_meta($post_id, '_apiid', $api_id);

            // Add static meta fields for listing type, category, etc.
            update_post_meta($post_id, '_case27_listing_type', 'productsb');
            update_post_meta($post_id, '_main_category', $main_category);
            update_post_meta($post_id, '_sub_category', $sub_category);

            // Loop through each row and update post meta
            foreach ($item as $key => $value) {
                if ($key === 'id') continue;
                $meta_key = '_' . sanitize_key($key);
                $meta_value = !empty($value) ? sanitize_text_field($value) : '';

                update_post_meta($post_id, $meta_key, $meta_value);
            }

            // Process logo URL if present
            $logo_url = sanitize_text_field($item['Logo'] ?? '');
            if (!empty($logo_url)) {
                $logo_serialized = upload_image_from_url($logo_url);
                if ($logo_serialized) {
                    $wpdb->insert(
                        $wpdb->postmeta,
                        array(
                            'post_id'    => $post_id,
                            'meta_key'   => '_job_logo',
                            'meta_value' => $logo_serialized,
                        ),
                        array('%d', '%s', '%s')
                    );
                }
            }

            $naece_id = sanitize_text_field($item['Naece Id'] ?? null);
            if ($naece_id) {

                $parent_post_id = $wpdb->get_var($wpdb->prepare("
                    SELECT post_id 
                    FROM wp_postmeta
                    WHERE meta_value = %s 
                    AND meta_key = '_apiid'
                ", $naece_id));

                if ($parent_post_id) { // Ensure we have a valid parent_post_id before inserting
                    $relations_table = $wpdb->prefix . 'mylisting_relations';
                    $wpdb->insert(
                        $relations_table,
                        array(
                            'parent_listing_id' => $parent_post_id,
                            'child_listing_id'  => $post_id,
                            'field_key'         => 'related_listing',
                            'item_order'        => 0,
                        ),
                        array('%d', '%d', '%s', '%d')
                    );
                }
                $naece_id_found++;
            } else {
                $naece_id_not_found++;
            }
        }

        $processed_count++;  // Increment processed count for each row processed
    }

    $wpdb->query('COMMIT'); // Commit the transaction

    // Return JSON response with remaining records and counts
    $remaining = count($lines) - ($offset + $batch_size);
    echo json_encode([
        'message' => 'Batch processed successfully.',
        'fetched' => $fetched_count,
        'duplicates' => $duplicate_count,
        'nulls' => $null_count,
        'processed' => $processed_count,
        'total_records' => $total_records ?? 0,
        'remaining' => $remaining < 0 ? 0 : $remaining
    ]);
    exit;
} else {
    echo json_encode([
        'message' => 'CSV file is empty or could not be read.',
        'remaining' => 0
    ]);
    exit;
}
