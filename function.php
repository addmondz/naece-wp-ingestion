<?php

define('WP_USE_THEMES', false);
require_once('../wp-config.php');

ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

function process_csv_batch() {
    global $wpdb;
    
    // Start timing the process
    $start_time = microtime(true);
    
    // 1. Get and validate inputs
    $csv_file = $_POST['csvFile'] ?? '';
    $main_cat = $_POST['mainCategory'] ?? '';
    $sub_cat = $_POST['subCategory'] ?? '';
    $offset = (int)($_POST['offset'] ?? 0);
    $batch_size = (int)($_POST['batchSize'] ?? 500);

    if (!$csv_file || !$main_cat || !$sub_cat) {
        return send_response('Missing required fields', 0);
    }

    $csv_path = __DIR__ . '/csv/product/' . $csv_file;
    if (!file_exists($csv_path)) {
        return send_response('CSV file not found', 0);
    }

    // 2. Read CSV batch
    $handle = fopen($csv_path, 'r');
    $headers = fgetcsv($handle);
    
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
        return send_response('No more records to process', 0, $total_records);
    }

    // 3. Process batch
    $stats = ['fetched' => 0, 'duplicates' => 0, 'nulls' => 0, 'processed' => 0];
    
    try {
        $wpdb->query('START TRANSACTION');

        foreach ($batch as $item) {
            $api_id = $item['ENERGY STAR Unique ID'] ?? '';
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
                'post_status' => 'draft',
                'post_type'   => 'job_listing'
            ]);

            if (!$post_id) continue;

            $stats['fetched']++;

            // Add meta data
            $meta = [
                '_apiid' => $api_id,
                '_case27_listing_type' => 'productsb',
                '_main_category' => $main_cat,
                '_sub_category' => $sub_cat
            ];

            // Add other fields as meta
            foreach ($item as $key => $value) {
                if ($key !== 'id' && $value) {
                    $meta['_' . sanitize_key($key)] = $value;
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
            max(0, $total_records - ($offset + $batch_size)),
            $total_records,
            array_merge($stats, ['process_time' => $process_time])
        );

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        
        // Include process time even for errors
        $process_time = round(microtime(true) - $start_time, 1);
        return send_response('Error: ' . $e->getMessage(), 0, 0, ['process_time' => $process_time]);
    }
}

function send_response($message, $remaining, $total = 0, $stats = []) {
    echo json_encode([
        'data' => array_merge([
            'message' => $message,
            'remaining' => $remaining,
            'total_records' => $total
        ], $stats)
    ]);
    exit;
}

process_csv_batch();
