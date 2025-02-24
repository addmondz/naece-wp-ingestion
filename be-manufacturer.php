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
            $api_id = sanitize_text_field($item['Api Id'] ?? '');

            if (!$api_id) {
                $stats['nulls']++;
                continue;
            }

            // Skip if api_id is empty or already exists
            if (post_exists_by_meta('_apiid', $api_id)) {
                $stats['duplicates']++;
                continue;
            }

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
            $NAECE_Name         = sanitize_text_field($item['NAECE Name'] ?? '');

            $post_data = array(
                'post_title'     => $name,
                'post_content'   => $description,
                'post_status'    => 'publish',
                'comment_status' => 'open',
                'post_type'      => 'job_listing',
            );

            $post_id = wp_insert_post($post_data);

            if ($post_id) {
                update_post_meta($post_id, '_apiid', $api_id);
                update_post_meta($post_id, '_job_email', $email);
                update_post_meta($post_id, '_job_email2', $email2);
                update_post_meta($post_id, '_job_email3', $email3);
                update_post_meta($post_id, '_job_phone', $phone);
                update_post_meta($post_id, '_job_phone2', $phone2);
                update_post_meta($post_id, '_job_phone3', $phone3);
                update_post_meta($post_id, '_case27_listing_type', 'brands');
                update_post_meta($post_id, '_address', $address);
                update_post_meta($post_id, '_street_address', $streetaddress);
                update_post_meta($post_id, '_zippostal-code', $zip);
                update_post_meta($post_id, '_citydistrict', $city);
                update_post_meta($post_id, '_stateprovince', $state);
                update_post_meta($post_id, '_country', $rcountry);
                update_post_meta($post_id, '_industry', $industry);
                update_post_meta($post_id, '_job_website', $website);
                update_post_meta($post_id, '_EnergyName', $energy_name);
                update_post_meta($post_id, '_DLCName', $dlc_name);
                update_post_meta($post_id, '_NAECE_Name', $NAECE_Name);

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
                } else {
                    // If no logo URL is provided, insert an empty string as _job_logo
                    $wpdb->insert(
                        $wpdb->postmeta,
                        array(
                            'post_id'    => $post_id,
                            'meta_key'   => '_job_logo',
                            'meta_value' => '',
                        ),
                        array('%d', '%s', '%s')
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
