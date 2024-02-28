<?php
/**
 * Plugin Name: User File Management
 * Plugin URI:  https://m3bg.com/
 * Description: Allows users to upload files, admins to view all uploads, and provides a delete option with confirmation.
 * Version:     2.0
 * Author:      Simeon Bakalov
 * Author URI:  https://m3bg.com
 */

register_activation_hook(__FILE__, 'custom_file_uploader_activate');
function custom_file_uploader_activate() {
    add_option('custom_file_uploader_allowed_types', 'image/jpeg, image/png, application/pdf');
    add_option('custom_file_uploader_allowed_roles', 'subscriber, contributor');
}

add_action('admin_menu', 'custom_file_uploader_menu');
function custom_file_uploader_menu() {
    add_options_page('Custom File Uploader Settings', 'File Uploader', 'manage_options', 'custom-file-uploader', 'custom_file_uploader_options_page');
}

function custom_file_uploader_options_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    echo '<div class="wrap"><form action="options.php" method="post">';
    settings_fields('custom_file_uploader');
    do_settings_sections('custom-file-uploader');
    submit_button();
    echo '</form></div>';
}

add_action('admin_init', 'custom_file_uploader_settings_init');
function custom_file_uploader_settings_init() {
    register_setting('custom_file_uploader', 'custom_file_uploader_allowed_types');
    register_setting('custom_file_uploader', 'custom_file_uploader_allowed_roles');
    add_settings_section('custom_file_uploader_section', 'Settings', null, 'custom-file-uploader');
    add_settings_field('custom_file_uploader_field_types', 'Allowed File Types', 'custom_file_uploader_field_types_callback', 'custom-file-uploader', 'custom_file_uploader_section');
    add_settings_field('custom_file_uploader_field_roles', 'Allowed Roles', 'custom_file_uploader_field_roles_callback', 'custom-file-uploader', 'custom_file_uploader_section');
}

function custom_file_uploader_field_types_callback() {
    $setting = get_option('custom_file_uploader_allowed_types');
    echo "<input type='text' name='custom_file_uploader_allowed_types' value='$setting' />";
}

function custom_file_uploader_field_roles_callback() {
    $setting = get_option('custom_file_uploader_allowed_roles');
    echo "<input type='text' name='custom_file_uploader_allowed_roles' value='$setting' />";
}

add_action('admin_post_upload_user_file', 'handle_file_upload');
add_action('admin_post_nopriv_upload_user_file', 'handle_file_upload');

function custom_file_uploader_change_upload_dir($dir) {
    $user_id = get_current_user_id();
    $custom_directory = '/custom_uploads/user_' . $user_id;

    if (!file_exists($dir['basedir'] . $custom_directory)) {
        wp_mkdir_p($dir['basedir'] . $custom_directory);
    }

    return array(
        'path'   => $dir['basedir'] . $custom_directory,
        'url'    => $dir['baseurl'] . $custom_directory,
        'subdir' => $custom_directory,
    ) + $dir;
}


function handle_file_upload() {
    if (!isset($_POST['user_file_upload_nonce']) || !wp_verify_nonce($_POST['user_file_upload_nonce'], 'user_file_upload') || !current_user_can('upload_files')) {
        wp_die('Security check failed or unauthorized access.');
    }

    if (isset($_FILES['user_file']) && current_user_can('upload_files')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $file = $_FILES['user_file'];

        $extension_to_mime = [
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            
        ];

        $allowed_extensions = array_map('trim', explode(',', get_option('custom_file_uploader_allowed_types')));
        $allowed_types = [];
        foreach ($allowed_extensions as $ext) {
            if (isset($extension_to_mime[$ext])) {
                $allowed_types[] = $extension_to_mime[$ext];
            }
        }

        $file_info = wp_check_filetype(basename($file['name']));
        if (!in_array($file_info['type'], $allowed_types)) {
            wp_die('File type not allowed. Detected type is ' . $file_info['type']);
        }

        $upload_overrides = ['test_form' => false];
        add_filter('upload_dir', 'custom_file_uploader_change_upload_dir');
        $uploaded_file = wp_handle_upload($file, $upload_overrides);
        remove_filter('upload_dir', 'custom_file_uploader_change_upload_dir');

        if (isset($uploaded_file['file'])) {
            $file_loc = $uploaded_file['file'];
            $file_name = basename($file['name']);
            $file_type = wp_check_filetype($file_loc);

            $attachment = [
                'post_mime_type' => $file_type['type'],
                'post_title' => preg_replace('/\.[^.]+$/', '', $file_name),
                'post_content' => '',
                'post_status' => 'inherit',
                'guid' => $uploaded_file['url']
            ];

            $attach_id = wp_insert_attachment($attachment, $file_loc);
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $file_loc);
            wp_update_attachment_metadata($attach_id, $attach_data);
        }
    }
    if (isset($_POST['redirect_url'])) {
        wp_redirect(esc_url_raw($_POST['redirect_url']));
        exit;
    }
}

function list_all_user_uploads_shortcode() {
    if (!current_user_can('administrator')) {
        return '<p>You do not have permission to view this content.</p>';
    }

    $upload_form_html = '<div class="custom-upload-form" style="margin-bottom: 20px;">
        <h2>Upload a File</h2>
        <form action="' . esc_url(admin_url('admin-post.php')) . '" method="post" enctype="multipart/form-data">
            <input type="file" name="bistrev_file" required>
            <input type="hidden" name="action" value="upload_user_file_to_bistrev">
            ' . wp_nonce_field('bistrev_file_upload', 'bistrev_file_upload_nonce', true, false) . '
            <input type="submit" value="Upload File">
        </form>
    </div>';

    echo $upload_form_html;

    $output = '<form action="" method="get">
        <input type="text" name="search_query" placeholder="Search files or users..." value="' . (isset($_GET['search_query']) ? esc_attr($_GET['search_query']) : '') . '">
        <input type="submit" value="Search">
    </form><br />';

    $current_page = get_query_var('paged') ? get_query_var('paged') : 1;
    $posts_per_page = 10;

    $query_args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => $posts_per_page,
        'paged' => $current_page,
    ];

    if (!empty($_GET['search_query'])) {
        $search_query = sanitize_text_field($_GET['search_query']);
        $query_args['s'] = $search_query;
    }

    $uploads_query = new WP_Query($query_args);
    $output .= '<div class="all-user-uploads">';

    if ($uploads_query->have_posts()) {
        $output .= '<div class="file-list">';
        while ($uploads_query->have_posts()) {
            $uploads_query->the_post();
            $file_url = wp_get_attachment_url(get_the_ID());
            $file_title = get_the_title();
            $upload_date = get_the_date();
            $upload_time = get_the_time();
            $user_id = get_post_field('post_author', get_the_ID());
            $user_info = get_userdata($user_id);
            $username = $user_info->user_login;

            $output .= '<div class="file-item">';
            $output .= '<div class="file-info">';
            $output .= '<div class="file-title"><a href="' . esc_url($file_url) . '" target="_blank" download>' . esc_html($file_title) . '</a></div>';
            $output .= '<div class="file-meta">Uploaded by ' . esc_html($username) . ' on ' . esc_html($upload_date) . ' at ' . esc_html($upload_time) . '</div>';
            $output .= '</div>';
            $output .= '</div>';
        }
        $output .= '</div>';

        $big = 999999999;
        $output .= paginate_links(array(
            'base' => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
            'format' => '?paged=%#%',
            'current' => max(1, $current_page),
            'total' => $uploads_query->max_num_pages,
            'add_args' => array(
                'search_query' => urlencode($search_query),
            ),
        ));
    } else {
        $output .= '<p>No files have been uploaded yet.</p>';
    }

    $output .= '</div>';
    wp_reset_postdata();

    return $output;
}

add_shortcode('list_all_user_uploads', 'list_all_user_uploads_shortcode');

function handle_delete_user_file() {
    if (current_user_can('administrator') && isset($_GET['file_id']) && wp_verify_nonce($_GET['_wpnonce'], 'delete-file_' . $_GET['file_id'])) {
        $file_id = intval($_GET['file_id']);
        wp_delete_attachment($file_id, true);
        wp_redirect(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : home_url());
        exit;
    }
    wp_die('You are not allowed to delete this file.');
}

add_action('admin_post_delete_user_file', 'handle_delete_user_file');

function custom_user_upload_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>You need to be logged in to upload files.</p>';
    }

    $form_html = '<div class="custom-upload-form">
	<form id="fileUploadForm" action="' . esc_url(admin_url('admin-post.php')) . '" method="post" enctype="multipart/form-data">
		<input type="file" name="user_file" required>
		<input type="hidden" name="action" value="upload_user_file">
		<input type="hidden" name="redirect_url" value="' . esc_url(get_permalink()) . '">
		' . wp_nonce_field('user_file_upload', 'user_file_upload_nonce', true, false) . '
		<input type="submit" value="Upload File">
	</form>
</div>';

    return $form_html;
}

add_shortcode('custom_user_upload_form', 'custom_user_upload_form_shortcode');



function delete_custom_upload_image_sizes($metadata) {
    $sizes_to_keep = ['thumbnail', 'medium', 'large'];

    $upload_dir = wp_upload_dir();
    $path = pathinfo($metadata['file']);

    foreach($metadata['sizes'] as $size => $file_info) {
        if (!in_array($size, $sizes_to_keep)) {
            $file_path = $upload_dir['basedir'] . '/' . $path['dirname'] . '/' . $file_info['file'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
    }

    return $metadata;
}
add_filter('wp_generate_attachment_metadata', 'delete_custom_upload_image_sizes');



function list_files_from_custom_uploads() {
    if (!is_user_logged_in()) {
        return 'You must be logged in to view your files.';
    }

    $user_id = get_current_user_id();
    $upload_dir = wp_upload_dir();
    $custom_uploads_dir = $upload_dir['basedir'] . '/custom_uploads/user_' . $user_id;

    if (!file_exists($custom_uploads_dir)) {
        return 'You have no files uploaded.';
    }

    $files = array_diff(scandir($custom_uploads_dir), array('..', '.'));
    if (empty($files)) {
        return 'No files found in your upload directory.';
    }

    $output = '<ul class="custom-uploads-list">';
    foreach ($files as $file) {
        $file_path = $custom_uploads_dir . '/' . $file;
        $file_url = $upload_dir['baseurl'] . '/custom_uploads/user_' . $user_id . '/' . $file;

        $file_time = filemtime($file_path);
        $formatted_time = date('F d, Y H:i:s', $file_time);

        $output .= '<div class="file"><a href="' . esc_url($file_url) . '" target="_blank" download>Download</a> - ' . esc_html($file) . ' <br /> <sup>' . $formatted_time . '</sup></div>';
    }
    $output .= '</ul>';

    return $output;
}
add_shortcode('list_custom_uploads', 'list_files_from_custom_uploads');
// To use this shortcode, simply add [list_custom_uploads] to any post or page where you want the list of files to appear


add_action('admin_post_upload_user_file_to_bistrev', 'handle_file_upload_to_bistrev');
add_action('admin_post_nopriv_upload_user_file_to_bistrev', 'handle_file_upload_to_bistrev');

function handle_file_upload_to_bistrev() {
    if (!isset($_POST['bistrev_file_upload_nonce']) || !wp_verify_nonce($_POST['bistrev_file_upload_nonce'], 'bistrev_file_upload') || !current_user_can('upload_files')) {
        wp_die('Security check failed or unauthorized access.');
    }

    if (isset($_FILES['bistrev_file']) && current_user_can('upload_files')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $file = $_FILES['bistrev_file'];

        add_filter('upload_dir', function ($dir) {
            return [
                'path'   => $dir['basedir'] . '/bistrev_files',
                'url'    => $dir['baseurl'] . '/bistrev_files',
                'subdir' => '/bistrev_files',
            ] + $dir;
        });

        $upload_overrides = ['test_form' => false];
        $uploaded_file = wp_handle_upload($file, $upload_overrides);

        remove_filter('upload_dir', function ($dir) {});

        if ($uploaded_file && !isset($uploaded_file['error'])) {
            wp_redirect(wp_get_referer());
            exit;
        } else {
            wp_die('There was an error uploading your file. The error is: ' . $uploaded_file['error']);
        }
    }
}


register_deactivation_hook(__FILE__, 'custom_file_uploader_deactivate');
function custom_file_uploader_deactivate() {
    delete_option('custom_file_uploader_allowed_types');
    delete_option('custom_file_uploader_allowed_roles');
}

add_action('admin_enqueue_scripts', 'custom_file_uploader_admin_styles');
function custom_file_uploader_admin_styles() {
    wp_enqueue_style('custom-file-uploader-admin-style', plugin_dir_url(__FILE__) . '/css/admin-style.css');
}

add_action('wp_enqueue_scripts', 'custom_file_uploader_frontend_styles');
function custom_file_uploader_frontend_styles() {
    wp_enqueue_style('custom-file-uploader-frontend-style', plugin_dir_url(__FILE__) . '/css/frontend-style.css');
}

add_action('wp_enqueue_scripts', 'custom_file_uploader_frontend_js');
function custom_file_uploader_frontend_js() {
    wp_enqueue_script('custom-file-uploader-frontend-js', plugin_dir_url(__FILE__) . '/js/custom-file-upload.js');
}
