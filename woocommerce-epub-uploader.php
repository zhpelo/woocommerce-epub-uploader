<?php

/**
 * Plugin Name: WooCommerce EPUB Uploader
 * Description: 上传EPUB文件，自动生成商品。
 * Version: 1.0
 * Author: zhpelo
 * Requires Plugins: woocommerce
 */

// mac  /Applications/calibre.app/Contents/MacOS/ebook-meta

if (!defined('ABSPATH')) exit;

define('WEU_PLUGIN_DIR', plugin_dir_path(__FILE__));


class WC_Epub_Uploader
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_upload_epub_product', [$this, 'handle_upload']);
        // 添加EPUB到允许的MIME类型
        add_filter('upload_mimes', [$this, 'allow_epub_upload']);
        add_filter('woocommerce_downloadable_file_allowed_mime_types', [$this, 'allow_epub_download']);
    }

    // 添加EPUB MIME类型到允许上传列表
    public function allow_epub_upload($mimes)
    {
        $mimes['epub'] = 'application/epub+zip';
        return $mimes;
    }

    // 添加EPUB到WooCommerce允许的下载文件MIME类型
    public function allow_epub_download($mime_types)
    {
        $mime_types['epub'] = 'application/epub+zip';
        return $mime_types;
    }

    public function add_admin_menu()
    {
        add_submenu_page(
            'edit.php?post_type=product',
            'Upload EPUB Product',
            'Upload EPUB',
            'manage_woocommerce',
            'upload-epub-product',
            [$this, 'render_upload_form']
        );
    }

    public function render_upload_form()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions.'));
        }
?>
        <div class="wrap">
            <h2>Upload EPUB as Product</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('upload_epub_nonce', 'epub_nonce'); ?>
                <input type="hidden" name="action" value="upload_epub_product">
                <table class="form-table">
                    <tr>
                        <th scope="row">EPUB File</th>
                        <td><input type="file" name="epub_file" accept=".epub" required /></td>
                    </tr>
                    <tr>
                        <th scope="row">Price ($)</th>
                        <td><input type="number" step="0.01" name="price" min="0" value="1" /></td>
                    </tr>
                </table>
                <?php submit_button('Upload & Create Product'); ?>
            </form>
        </div>
<?php
    }

    public function handle_upload()
    {
        if (!wp_verify_nonce($_POST['epub_nonce'] ?? '', 'upload_epub_nonce')) {
            wp_die('Security check failed.');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions.');
        }

        if (empty($_FILES['epub_file']) || $_FILES['epub_file']['error'] !== UPLOAD_ERR_OK) {
            wp_die('File upload error.');
        }

        $file = $_FILES['epub_file'];
        $price = floatval($_POST['price'] ?? 9.99);

        // Validate file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'epub') {
            wp_die('Only .epub files are allowed.');
        }

        // Copy to temp instead of moving (to avoid permission issues)
        $temp_file = wp_tempnam('epub_', sys_get_temp_dir()."/") . '.epub';
        if (!copy($file['tmp_name'], $temp_file)) {
            wp_die('Failed to copy uploaded file.');
        }

        // Remove the temporary file created by WordPress
        @unlink($file['tmp_name']);

        // 使用 calibre 的 ebook-meta 命令解析 EPUB 文件
        $meta = $this->extract_epub_metadata($temp_file);
        // 提取封面
        $cover_path = $this->extract_epub_cover($temp_file);
        // Create product
        $product_id = $this->create_product(
            $meta,
            $cover_path,
            $price,
            $temp_file
        );

        // Clean up
        unlink($temp_file);
        if ($cover_path && file_exists($cover_path)) {
            unlink($cover_path);
        }

        wp_redirect(admin_url("post.php?post={$product_id}&action=edit&epub_success=1"));
        exit;
    }

    // 使用 ebook-meta 命令提取 EPUB 元数据
    private function extract_epub_metadata($epub_file)
    {
        // 执行 ebook-meta 命令获取元数据
        $command = sprintf('ebook-meta "%s" 2>/dev/null', $epub_file);
        $output = shell_exec($command);

        if (!$output) {
            throw new Exception('Failed to extract metadata using ebook-meta command.');
        }

        // 解析输出文本以提取元数据
        $meta = array();
        $meta['title'] = 'Untitled EPUB';
        $meta['description'] = '';
        $meta['subjects'] = array();
        $meta['authors'] = array();
        $meta['published_date'] = '';

        // 按行分割输出
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // 查找标题
            if (strpos($line, 'Title               :') === 0) {
                $meta['title'] = trim(substr($line, 21));
            }
            // 查找描述
            elseif (strpos($line, 'Description:') === 0) {
                $meta['description'] = trim(substr($line, 12));
            }
            // 查找标签/主题
            elseif (strpos($line, 'Tags                :') === 0) {
                $tags_string = trim(substr($line, 21));
                if (!empty($tags_string)) {
                    $meta['subjects'] = array_map('trim', explode(',', $tags_string));
                }
            }
            // 查找作者
            elseif (strpos($line, 'Author(s)           :') === 0) {
                $authors_string = trim(substr($line, 21));
                if (!empty($authors_string)) {
                    // 移除方括号中的内容，只保留作者名列表
                    $authors_string = preg_replace('/\[.*?\]/', '', $authors_string);
                    $meta['authors'] = array_map('trim', explode(',', $authors_string));
                }
            }
            // 查找发布日期
            elseif (strpos($line, 'Published           :') === 0) {
                $published_string = trim(substr($line, 21));
                if (!empty($published_string)) {
                    // 尝试解析日期
                    $date = substr($published_string, 0, 10); // 提取 YYYY-MM-DD 部分
                    if (strtotime($date)) {
                        $meta['published_date'] = $date;
                    }
                }
            }
        }

        return $meta;
    }

    // 使用 ebook-meta 命令提取 EPUB 封面
    private function extract_epub_cover($epub_file)
    {
        // 创建临时封面文件路径
        $cover_path = sys_get_temp_dir() . '/epub_cover_' . uniqid() . '.jpg';

        // 执行 ebook-meta 命令提取封面
        $command = sprintf(
            'ebook-meta "%s" --get-cover="%s" 2>/dev/null',
            $epub_file,
            $cover_path
        );


        $result = shell_exec($command);

        // 检查封面是否成功提取
        if (file_exists($cover_path) && filesize($cover_path) > 0) {
            return $cover_path;
        } else {
            // 清理可能创建的空文件
            if (file_exists($cover_path)) {
                unlink($cover_path);
            }
            return null;
        }
    }

    private function create_product($meta, $cover_path, $price, $epub_file_path)
    {
        $title = sanitize_text_field($meta['title'] ?? 'Untitled EPUB');

        $product = new WC_Product_Simple();
        $product->set_name($title);
        $product->set_regular_price($price);
        $product->set_virtual(true);
        $product->set_downloadable(true);

        $upload_dir = wp_upload_dir();
        $ext = pathinfo($epub_file_path);

        $new_file_path = '/' . uniqid() . "." . $ext['extension'];
        $new_all_file_path = $upload_dir['path'] . $new_file_path;
        $download_url = $upload_dir['url'] . $new_file_path;


        // 直接移动EPUB文件到上传目录
        copy($epub_file_path, $new_all_file_path);

        // 设置下载文件
        $download = new WC_Product_Download();
        $download->set_name($title);
        $download->set_file($download_url);
        $product->set_downloads([$download]);

        // Set tags from subjects
        if (!empty($meta['subjects'])) {
            $tags = array_map('sanitize_title', $meta['subjects']);
            $tag_ids = [];
            foreach ($meta['subjects'] as $subject) {
                $term = wp_create_term(sanitize_text_field($subject), 'product_tag');
                if (!is_wp_error($term)) {
                    $tag_ids[] = $term['term_id'];
                }
            }
            $product->set_tag_ids($tag_ids);
        }

        $product_id = $product->save();

        // Set featured image
        if ($cover_path && file_exists($cover_path)) {
            $attachment_id = $this->media_handle_upload_from_path($cover_path, $product_id);
            if ($attachment_id && !is_wp_error($attachment_id)) {
                set_post_thumbnail($product_id, $attachment_id);
            }
        }

        return $product_id;
    }

    private function media_handle_upload_from_path($file_path, $parent_post_id = 0)
    {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $filename = basename($file_path);
        $upload_file = wp_upload_bits($filename, null, file_get_contents($file_path));
        if ($upload_file['error']) return false;

        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_parent'    => $parent_post_id,
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attachment_id = wp_insert_attachment($attachment, $upload_file['file'], $parent_post_id);
        if (!is_wp_error($attachment_id)) {
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload_file['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);
        }

        return $attachment_id;
    }
}

new WC_Epub_Uploader();
