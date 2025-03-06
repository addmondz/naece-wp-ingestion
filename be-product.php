<?php

define('WP_USE_THEMES', false);
require_once('../wp-config.php');

ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

function process_csv_batch()
{
    global $wpdb;

    // Start timing the process
    $start_time = microtime(true);

    // 1. Get and validate inputs
    $csv_file = $_POST['csvFile'] ?? '';
    $csv_source = $_POST['csvSource'] ?? '';
    $main_cat = $_POST['mainCategory'] ?? '';
    $sub_cat = $_POST['subCategory'] ?? '';
    $offset = (int)($_POST['offset'] ?? 0);
    $batch_size = (int)($_POST['batchSize'] ?? 500);

    if ($csv_source === 'DLC') {
        if (!$csv_file) {
            return send_response('Missing required fields', 0, false);
        }
    } else {
        if (!$csv_file || !$main_cat || !$sub_cat || !$csv_source) {
            return send_response('Missing required fields', 0, false);
        }
    }

    $csv_path = __DIR__ . '/csv/product/' . $csv_file;
    if (!file_exists($csv_path)) {
        return send_response('CSV file not found', 0, false);
    }

    // 2. Read CSV batch
    $handle = fopen($csv_path, 'r');
    if (!$handle) {
        return send_response('Unable to open CSV file', 0, false);
    }

    // Read headers
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return send_response('CSV file is empty or invalid', 0, false);
    }

    if ($csv_source === 'DLC') {
        // DLC required fields
        $required_fields = [
            'Product ID',
            'Model Number',
            'Naece Id'
        ];
    } else {
        // ENERGY STAR required fields
        $required_fields = [
            'ENERGY STAR Unique ID',
            'Model Number',
            'Naece Id'
            // Add the required columns
        ];
    }

    // Check if all required fields exist in the headers
    $missing_fields = array_diff($required_fields, $headers);
    if (!empty($missing_fields)) {
        fclose($handle);
        return send_response('Missing required fields: ' . implode(', ', $missing_fields), 0, false);
    }


    // Skip to current offset
    for ($i = 0; $i < $offset; $i++) fgetcsv($handle);

    // Get batch data
    $batch = [];
    $total_records = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $total_records++;
        if (count($batch) < $batch_size) {
            $batch[] = array_combine($headers, $row);
        }
    }
    fclose($handle);

    if (empty($batch)) {
        return send_response('No more records to process', 0, true, $total_records);
    }

    // 3. Process batch
    $stats = ['fetched' => 0, 'duplicates' => 0, 'nulls' => 0, 'processed' => 0];

    try {
        $wpdb->query('START TRANSACTION');

        foreach ($batch as $item) {
            // Ensure the key names are clean
            $cleaned_item = [];
            foreach ($item as $key => $value) {
                $clean_key = trim($key, "\xEF\xBB\xBF");
                $cleaned_item[$clean_key] = $value;
            }
            $item = $cleaned_item;

            if ($csv_source === 'DLC') {
                $api_id = $item['Product ID'] ?? '';
            } else {
                $api_id = $item['ENERGY STAR Unique ID'] ?? '';
            }

            if (!$api_id) {
                $stats['nulls']++;
                continue;
            }

            // Check duplicate
            if ($wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_apiid' AND meta_value = %s LIMIT 1",
                $api_id
            ))) {
                $stats['duplicates']++;
                continue;
            }

            // Create post
            $post_id = wp_insert_post([
                'post_title'  => $item['Model Number'] ?? 'No Title',
                'post_status' => 'publish',
                'comment_status' => 'open',
                'post_type'   => 'job_listing'
            ]);

            if (!$post_id) continue;

            $stats['fetched']++;

            // Add meta data
            if ($csv_source === 'DLC') {
                $meta = [
                    '_apiid' => $api_id,
                    '_case27_listing_type' => 'productsb',
                    '_main_category' => $item['Qualified Product List'],
                    '_sub_category' => $item['Category'],
                ];
            } else {
                $meta = [
                    '_apiid' => $api_id,
                    '_case27_listing_type' => 'productsb',
                    '_main_category' => $main_cat,
                    '_sub_category' => $sub_cat
                ];
            }

            // Add other fields as meta
            foreach ($item as $key => $value) {
                if ($key !== 'id' && $value) {
                    // $meta['_' . sanitize_key($key)] = $value; // sanitize_key will remove space
                    $meta['_' . strtolower(str_replace(' ', '_', preg_replace('/[^A-Za-z0-9\/ ]/', '', $key)))] = $value;
                }
            }

            // Bulk meta insert
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }

            // Handle parent relation
            if (!empty($item['Naece Id'])) {
                $parent_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_apiid' AND meta_value = %s LIMIT 1",
                    $item['Naece Id']
                ));

                if ($parent_id) {
                    $wpdb->insert(
                        $wpdb->prefix . 'mylisting_relations',
                        [
                            'parent_listing_id' => $parent_id,
                            'child_listing_id'  => $post_id,
                            'field_key'         => 'related_listing',
                            'item_order'        => 0
                        ],
                        ['%d', '%d', '%s', '%d']
                    );
                }
            }

            $stats['processed']++;
        }

        $wpdb->query('COMMIT');

        // Calculate process time
        $process_time = round(microtime(true) - $start_time, 1);

        return send_response(
            'Batch processed successfully',
            // max(0, $total_records - ($offset + $batch_size)), // no need to deduct $offset due to the $ttoal already remove the offset
            max(0, $total_records - $batch_size),
            true,
            $total_records,
            array_merge($stats, ['process_time' => $process_time])
        );
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');

        // Include process time even for errors
        $process_time = round(microtime(true) - $start_time, 1);
        return send_response(
            'Error: ' . $e->getMessage(),
            0,
            false,
            0,
            ['process_time' => $process_time]
        );
    }
}

function send_response($message, $remaining, $success = true, $total = 0, $stats = [])
{
    $response_data = array_merge([
        'message' => $message,
        'remaining' => $remaining,
        'total_records' => $total
    ], $stats);

    if ($success) {
        wp_send_json_success($response_data); // Sends JSON with a 200 status
    } else {
        wp_send_json_error($response_data); // Sends JSON with a 400+ status
    }
}

process_csv_batch();
