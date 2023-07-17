<?php
/*
Plugin Name: Custom Articles Pro
Plugin URI: https://example.com/custom-articles-pro
Description: Добавляє пост кожен день з використанням API
Version: 1.1
Author: Anatolii
Author URI: https://example.com/custom-articles-pro
*/

register_activation_hook(__FILE__, 'cap_activate');

register_deactivation_hook(__FILE__, 'cap_deactivate');

function cap_activate() {
    cap_schedule_cron_event();
}

function cap_deactivate() {
    cap_unschedule_cron_event();
}

function cap_schedule_cron_event() {
    if (!wp_next_scheduled('cap_daily_cron_event')) {
        wp_schedule_event(time(), 'daily', 'cap_daily_cron_event');
    }
}

function cap_unschedule_cron_event() {
    wp_clear_scheduled_hook('cap_daily_cron_event');
}

function cap_process_cron_event() {
    $url = 'https://my.api.mockaroo.com/posts.json';
    $headers = array(
        'X-API-Key' => '413dfbf0'
    );
    
    $response = wp_remote_get($url, array('headers' => $headers));
    
    if (is_wp_error($response)) return error_log('Ошибка при запросе к API: ' . $response->get_error_message());
    
    $body = wp_remote_retrieve_body($response);
    $articles = json_decode($body);
    
    foreach ($articles as $article) {
        $existing_post = get_page_by_title($article->title, OBJECT, 'post');
        
        if ($existing_post) continue;
        $post_data = array(
            'post_title'   => $article->title,
            'post_content' => $article->content,
            'post_status'  => 'publish',
            'post_author'  => cap_get_administrator_id(),
            'post_date'    => cap_get_random_date(),
        );
        
        $post_id = wp_insert_post($post_data);
        
        if ($post_id) continue;
        if (!empty($article->rating)) add_post_meta($post_id, 'rating', $article->rating, true);

        if (!empty($article->site_link)) add_post_meta($post_id, 'site_link', $article->site_link, true);

        if (!empty($article->category)) {
            $categories = array_map('trim', explode(',', $article->category));
            $category_ids = array();
            
            foreach ($categories as $category) {
                $category_id = cap_get_category_id($category);
                
                if (!$category_id) {
                    $new_category = wp_insert_term($category, 'category');
                    
                    if (!is_wp_error($new_category) && isset($new_category['term_id'])) {
                        $category_id = $new_category['term_id'];
                    } else {
                        error_log('Ошибка при создании категории: ' . $category);
                        continue;
                    }
                }
                
                $category_ids[] = $category_id;
            }
            
            wp_set_post_categories($post_id, $category_ids);
        }
        
        if (!empty($article->image)) {
            $image_url = $article->image; 
            $image_name = basename($image_url); 

            $extension = pathinfo($image_name, PATHINFO_EXTENSION);
            $unique_name = uniqid() . '.' . $extension;
        
            $upload_dir = wp_upload_dir(); 
            $image_path = $upload_dir['path'] . '/' . $unique_name; 
            $image_data = file_get_contents($image_url); 
        
            if (file_put_contents($image_path, $image_data)) {
                // add to media wp
                $attachment = array(
                    'guid'           => $upload_dir['url'] . '/' . $unique_name,
                    'post_mime_type' => mime_content_type($image_path),
                    'post_title'     => preg_replace('/\.[^.]+$/', '', $unique_name),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                );
                $image_id = wp_insert_attachment($attachment, $image_path);
        
                if ($image_id) set_post_thumbnail($post_id, $image_id);
            }
        }
    }
}

add_action('cap_daily_cron_event', 'cap_process_cron_event');

// id first adm user
function cap_get_administrator_id() {
    $administrators = get_users(array('role' => 'administrator'));
    if (!empty($administrators)) {
        return $administrators[0]->ID;
    }
    return 0;
}

// get id category
function cap_get_category_id($category_name) {
    $category = get_term_by('name', $category_name, 'category');
    if ($category) {
        return $category->term_id;
    }
    return 0;
}

function cap_upload_featured_image($image_url, $post_id) {
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    $upload_dir = wp_upload_dir();
    $image_data = file_get_contents($image_url);
    $filename = basename($image_url);
    
    if (!$image_data) {
        error_log('Ошибка при загрузке изображения: ' . $image_url);
        return 0;
    }
    
    $file_path = $upload_dir['path'] . '/' . $filename;

    // save in server
    file_put_contents($file_path, $image_data);
    
    $wp_filetype = wp_check_filetype($filename, null);

    $attachment = array(
        'guid'           => $upload_dir['url'] . '/' . $filename,
        'post_mime_type' => $wp_filetype['type'],
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => '',
        'post_status'    => 'inherit',
    );
    
    $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);
    
    if (!is_wp_error($attachment_id)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        return $attachment_id;
    } else {
        error_log('Помилка при додаванні зображення: ' . $attachment_id->get_error_message());
        return 0;
    }
}

function cap_get_random_date() {
    $current_time = current_time('timestamp');
    $random_timestamp = rand($current_time - 30 * DAY_IN_SECONDS, $current_time);
    return date('Y-m-d H:i:s', $random_timestamp);
}

function display_articles_shortcode($atts) {

    $atts = shortcode_atts(array(
        'limit' => 5,
        'sort' => 'date',
        'ids' => '',
        'enqueue_styles' => 'true',
    ), $atts);

    $orderby = 'date';
    if ($atts['sort'] === 'title') {
        $orderby = 'title';
    } elseif ($atts['sort'] === 'rating') {
        $orderby = 'meta_value_num';
        $meta_key = 'rating';
    }

    $categories = get_categories();

    $category_names = array();
    foreach ($categories as $category) {
        $category_names[] = $category->slug;
    }
    $category_names = implode(',', $category_names);

    $post_ids = array();
    if (!empty($atts['ids'])) {
        $post_ids = explode(',', $atts['ids']);
        $post_ids = array_map('intval', $post_ids);
        $post_ids = array_filter($post_ids);
    }

    $args = array(
        'post_type' => 'post',
        'posts_per_page' => $atts['limit'],
        'orderby' => $orderby,
        'meta_key' => isset($meta_key) ? $meta_key : '',
        'post__in' => $post_ids,
        'category_name' => $category_names,
    );
    $query = new WP_Query($args);


    // all articles
    ob_start();
    if ($query->have_posts()) {
        echo '<div class="box-articles">';
        if (isset($atts['title']) && !empty($atts['title'])) {
            echo '<h2>' . esc_html($atts['title']) . '</h2>';
        }
        
        while ($query->have_posts()) {
            $query->the_post();
            include 'template.php';
        }

        echo '</div>';

    } else {
        echo 'No articles found.';
    }
    wp_reset_postdata();
    return ob_get_clean();
}

function enqueue_article_styles() {
    wp_enqueue_style( 'article-styles', plugin_dir_url( __FILE__ ) . 'assets/css/style.css' );
}

function register_display_articles_shortcode() {
    add_shortcode('display_articles', 'display_articles_shortcode');
    add_action( 'wp_enqueue_scripts', 'enqueue_article_styles' );
}

add_action('init', 'register_display_articles_shortcode');
