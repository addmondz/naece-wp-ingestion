<?php
define('WP_USE_THEMES', false);
require_once('../wp-config.php');

global $wpdb;

print_r('Starting...');
print_r('<br>');
print_r('<br>');

$query = "
    SELECT pm1.meta_value AS main_category, pm2.meta_value AS sub_category, COUNT(p.ID) as total_count
    FROM {$wpdb->posts} p
    JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_main_category'
    JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_sub_category'
    WHERE p.post_status = 'publish' AND p.post_type = 'job_listing'
    GROUP BY pm1.meta_value, pm2.meta_value
    ORDER BY total_count DESC
";

// print_r($query);

$results = $wpdb->get_results($query);

if ($results) {
    foreach ($results as $row) {
        echo "Main Category: " . esc_html($row->main_category) . "<br>";
        echo "Sub Category: " . esc_html($row->sub_category) . "<br>";
        echo "Count: " . esc_html($row->total_count) . "<br><br>";
    }
} else {
    print_r('No results found');
}
