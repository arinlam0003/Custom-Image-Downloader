<?php
/*
Plugin Name: Custom Image Downloader
Description: 下载外链图片到本地并替换链接的插件。
Version: 1.0
Author: Arinlam
*/

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 注册管理菜单
add_action('admin_menu', 'cid_register_admin_menu');

function cid_register_admin_menu() {
    add_menu_page(
        'Image Downloader',        // 页面标题
        'Image Downloader',        // 菜单标题
        'manage_options',          // 权限
        'image-downloader',        // 菜单别名
        'cid_admin_page',          // 回调函数
        'dashicons-download',      // 图标
        100                        // 位置
    );
}

// 管理页面内容
function cid_admin_page() {
    // 确保只有具有管理权限的用户可以访问
    if (!current_user_can('manage_options')) {
        return;
    }

    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $max_images_per_post_type = isset($_POST['max_images']) ? intval($_POST['max_images']) : null;
        $selected_post_types = isset($_POST['post_types']) ? $_POST['post_types'] : array();
        $results = cid_main($selected_post_types, $max_images_per_post_type);
        ?>
        <div class="wrap">
            <h1>处理结果</h1>
            <?php if ($results) : ?>
                <ul>
                    <?php foreach ($results as $result) : ?>
                        <li><?php echo esc_html($result['title']) . ': ' . $result['count'] . ' 张图片'; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p>没有找到需要处理的页面。</p>
            <?php endif; ?>
        </div>
        <?php
    }

    // 获取所有 post_type
    $all_post_types = cid_get_all_post_types();
    ?>
    <div class="wrap">
        <h1>Image Downloader</h1>
        <form method="post">
            <label for="max_images">输入每个 post_type 需要处理的最大条数：</label>
            <input type="number" name="max_images" id="max_images" min="1">
            <br><br>
            <label for="post_types">选择要处理的 post_type 类型：</label><br>
            <?php
            foreach ($all_post_types as $post_type_obj) {
                $post_type = $post_type_obj->name;
                $label = $post_type_obj->labels->singular_name;
                echo '<input type="checkbox" name="post_types[]" value="' . esc_attr($post_type) . '"> ' . esc_html($label) . '<br>';
            }
            ?>
            <br>
            <input type="submit" value="开始替换" class="button button-primary">
        </form>
    </div>
    <?php
}

// 存放图片的本地目录
$local_image_dir = ABSPATH . 'wp-content/uploads/download_image/';
$default_image_path = ABSPATH . 'wp-content/uploads/default_image.jpg'; // 默认图片路径

// 获取页面内容
function cid_get_page_content($post_id) {
    $post = get_post($post_id);
    if ($post) {
        return $post->post_content;
    }
    return '';
}

// 从页面内容获取所有图片链接（排除指定域名和本地链接）
function cid_get_image_links($page_content) {
    $image_urls = [];
    $doc = new DOMDocument();
    @$doc->loadHTML($page_content);
    $img_tags = $doc->getElementsByTagName('img');
    foreach ($img_tags as $img) {
        $img_url = $img->getAttribute('src');
        // 排除指定域名和本地连接
        if (!cid_starts_with($img_url, 'https://img.ii81.com') && !cid_starts_with($img_url, '/')) {
            $image_urls[] = $img_url;
        }
    }
    return $image_urls;
}

// 下载图片到本地
function cid_download_image($img_url) {
    global $local_image_dir, $default_image_path;
    $img_name = basename($img_url); // 获取图片的文件名
    $local_filename = $local_image_dir . $img_name; // 构建本地存储路径
    $image_content = @file_get_contents($img_url); // 下载图片内容
    if ($image_content === false) {
        return $default_image_path; // 下载失败，返回默认图片路径
    }
    file_put_contents($local_filename, $image_content); // 保存图片到本地
    return $local_filename; // 返回本地文件路径
}

// 修改页面中的图片链接为本地路径
function cid_replace_image_links($post_id, $max_images = null) {
    $page_content = cid_get_page_content($post_id); // 获取页面内容
    if (!empty($page_content)) {
        $image_urls = cid_get_image_links($page_content); // 获取页面中的所有图片链接
        $success_count = 0; // 计数器：成功处理的图片数量
        foreach ($image_urls as $img_url) {
            $local_image_path = cid_download_image($img_url); // 下载图片到本地并获取本地路径
            if ($local_image_path) {
                $page_content = str_replace($img_url, $local_image_path, $page_content); // 将图片链接替换为本地路径或默认图片路径
                $success_count++; // 成功处理图片数量增加
                // 输出处理进度到页面
                echo "<p>已成功处理页面：" . get_the_title($post_id) . "，已下载并替换 " . $success_count . " 张图片。</p>";
                flush(); // 刷新输出缓冲
            }
            // 如果达到了手工输入的条数限制，则停止处理
            if ($max_images && $success_count >= $max_images) {
                break;
            }
        }
        // 更新页面内容到数据库
        $post_data = array(
            'ID' => $post_id,
            'post_content' => $page_content,
        );
        wp_update_post($post_data); // 使用WordPress函数更新页面内容
        return array(
            'title' => get_the_title($post_id), // 获取页面标题
            'count' => $success_count, // 返回成功处理的图片数量
        );
    }
    return null;
}

// 主程序
function cid_main($post_types, $max_images_per_post_type = null) {
    $output = array(); // 存储每个页面的处理结果
    foreach ($post_types as $post_type) {
        $page_ids = cid_get_all_page_ids($post_type); // 获取指定 post_type 的所有页面的ID列表
        foreach ($page_ids as $page_id) {
            $result = cid_replace_image_links($page_id, $max_images_per_post_type); // 替换页面中的图片链接为本地路径
            if ($result) {
                $output[] = $result; // 将处理结果添加到输出数组中
            }
            // 如果达到了手工输入的条数限制，则停止处理
            if ($max_images_per_post_type && count($output) >= $max_images_per_post_type) {
                break 2;
            }
        }
    }
    return $output;
}

// 获取所有 post_type 的列表
function cid_get_all_post_types() {
    $post_types = get_post_types(array(
        'public' => true,
    ), 'objects');
    return $post_types;
}

// 获取指定 post_type 的所有页面的 ID 列表
function cid_get_all_page_ids($post_type) {
    global $wpdb;
    $query = $wpdb->prepare(
        "SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_status = 'publish'",
        $post_type
    );
    return $wpdb->get_col($query);
}

// 辅助函数：检查字符串是否以指定的前缀开头
function cid_starts_with($haystack, $needle) {
    return strpos($haystack, $needle) === 0;
}
?>
