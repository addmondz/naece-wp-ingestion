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

    if (!$csv_file) {
        return send_response('Missing required fields', 0, false);
    }

    $csv_path = __DIR__ . '/csv/manufacturer/' . $csv_file;
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

    // if ($csv_source === 'DLC') {
    //     // DLC required fields
    //     $required_fields = [
    //         'Product ID',
    //         'Model Number',
    //         'Naece Id'
    //     ];
    // } else {
    //     // ENERGY STAR required fields
    //     $required_fields = [
    //         'ENERGY STAR Unique ID',
    //         'Model Number',
    //         'Naece Id'
    //         // Add the required columns
    //     ];
    // }

    // // Check if all required fields exist in the headers
    // $missing_fields = array_diff($required_fields, $headers);
    // if (!empty($missing_fields)) {
    //     fclose($handle);
    //     return send_response('Missing required fields: ' . implode(', ', $missing_fields), 0, false);
    // }

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

            $api_id = sanitize_text_field($item['Api Id'] ?? '');
            if (!$api_id) {
                $stats['nulls']++;
                continue;
            }

            // Check if the API ID already exists
            $existing_post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_apiid',
                $api_id
            ));

            $name           = sanitize_text_field($item['NAECE Name'] ?? 'No Title');
            $description    = sanitize_textarea_field($item['Description'] ?? '');
            $phone          = sanitize_text_field($item['Phone'] ?? '');
            $phone2         = sanitize_text_field($item['Phone 2'] ?? '');
            $phone3         = sanitize_text_field($item['Phone 3'] ?? '');
            $email          = sanitize_email($item['Email'] ?? '');
            $email2         = sanitize_text_field($item['Email 2'] ?? '');
            $email3         = sanitize_text_field($item['Email 3'] ?? '');
            $logo_url       = esc_url_raw($item['Logo'] ?? '');
            $industry       = sanitize_text_field($item['Industry'] ?? '');
            $address        = sanitize_text_field($item['Address'] ?? '');
            $streetaddress  = sanitize_text_field($item['Street Address'] ?? '');
            $zip            = sanitize_text_field($item['Zip Code'] ?? '');
            $city           = sanitize_text_field($item['City'] ?? '');
            $state          = sanitize_text_field($item['State'] ?? '');
            $rcountry       = sanitize_text_field($item['Registered Country'] ?? '');
            $website        = esc_url_raw($item['Website'] ?? '');
            $energy_name    = sanitize_text_field($item['EnergyStar Name'] ?? '');
            $dlc_name       = sanitize_text_field($item['DLC Name'] ?? '');
            $NAECE_Name     = sanitize_text_field($item['NAECE Name'] ?? '');

            if ($existing_post_id) {
                // Update existing post
                $post_data = array(
                    'ID'           => $existing_post_id,
                    'post_title'   => $name,
                    'post_content' => $description,
                    'post_status'  => 'publish',
                    'comment_status' => 'open',
                );
                wp_update_post($post_data);

                $stats['updated']++;
            } else {
                // Insert new post
                $post_data = array(
                    'post_title'     => $name,
                    'post_content'   => $description,
                    'post_status'    => 'publish',
                    'comment_status' => 'open',
                    'post_type'      => 'job_listing',
                );
                $existing_post_id = wp_insert_post($post_data);
                $stats['inserted']++;
            }

            if ($existing_post_id) {
                // Update post meta data
                $meta_fields = [
                    '_apiid'            => $api_id,
                    '_job_email'        => $email,
                    '_job_email2'       => $email2,
                    '_job_email3'       => $email3,
                    '_job_phone'        => $phone,
                    '_job_phone2'       => $phone2,
                    '_job_phone3'       => $phone3,
                    '_case27_listing_type' => 'brands',
                    '_address'          => $address,
                    '_street_address'   => $streetaddress,
                    '_zippostal-code'   => $zip,
                    '_citydistrict'     => $city,
                    '_stateprovince'    => $state,
                    '_country'          => $rcountry,
                    '_industry'         => $industry,
                    '_job_website'      => $website,
                    '_EnergyName'       => $energy_name,
                    '_DLCName'          => $dlc_name,
                    '_NAECE_Name'       => $NAECE_Name
                ];

                foreach ($meta_fields as $key => $value) {
                    update_post_meta($existing_post_id, $key, $value);
                }

                // Handle Logo Upload
                if (!empty($logo_url)) {
                    $logo_serialized = upload_image_from_url($logo_url);
                    update_post_meta($existing_post_id, '_job_logo', $logo_serialized ?: '');
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
