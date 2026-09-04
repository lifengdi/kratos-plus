<?php

/**
 * ImageX 图片服务
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2022.01.26
 */

if (!empty(kratos_option('g_imgx_fieldset')['g_imgx'])) {

    require_once 'volcengine-imagex/vendor/autoload.php';

    // Guzzle 7.11+ 会调用 symfony/deprecation-contracts 的 trigger_deprecation()。
    // 该函数由 Composer 的 files 自动加载引入，而 file identifier 的哈希在所有项目里
    // 是同一个值，靠 $GLOBALS['__composer_autoload_files'] 去重。若站点上其它插件先
    // 加载了自己那份（尤其是被 PHP-Scoper / Mozart 改过命名空间的副本——哈希不变但函数
    // 被改名），我们这份就会被跳过，全局函数始终不存在，调用时 fatal。此处兜底补上。
    if (!function_exists('trigger_deprecation')) {
        require_once __DIR__ . '/volcengine-imagex/vendor/symfony/deprecation-contracts/function.php';
    }

    // 本模块的路径计算全靠 get_home_path()，它定义在 wp-admin/includes/file.php。
    // WP 6.9 起 REST 的 /wp/v2/media 在 WP_REST_Attachments_Controller::finalize_item()
    // 里触发 wp_generate_attachment_metadata，那条链路不加载 wp-admin/includes，于是
    // imagex_upload_thumbs 调用时 fatal「Call to undefined function get_home_path()」。
    if (!function_exists('get_home_path')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    function imagex_get_client()
    {
        $imagex_client = Volc\Service\ImageX::getInstance($region = kratos_option('g_imgx_fieldset')['g_imgx_region']);
        $imagex_client->setAccessKey(kratos_option('g_imgx_fieldset')['g_imgx_accesskey']);
        $imagex_client->setSecretKey(kratos_option('g_imgx_fieldset')['g_imgx_secretkey']);

        return $imagex_client;
    }

    function imagex_upload($object, $file)
    {
        if (!@file_exists($file)) {
            return false;
        }
        if (@file_exists($file)) {
            $client = imagex_get_client();
            $params = array();
            $params["ServiceId"] = kratos_option('g_imgx_fieldset')['g_imgx_serviceid'];
            $params['UploadNum'] = 1;
            $params['StoreKeys'] = array($object);
            // 火山 SDK（inc/volcengine-imagex，vendored）在 uploadImages 里 echo 了三行
            // "requsetID ... cost ...ms" 计时日志。上传走 REST /wp/v2/media 或
            // async-upload.php，这些字节会落在 JSON 之前，编辑器报「此响应不是合法的 JSON
            // 响应」、经典媒体库报「无法完成上传」。不改 vendor（升级会覆盖），在调用处丢弃。
            ob_start();
            try {
                $response = $client->uploadImages($params, array($file));
            } catch (\Throwable $e) {
                // Guzzle 对 4xx/5xx 抛异常，SDK 不接。异常穿到 wp_handle_upload 会让整个
                // 上传请求 500，图片连本地都存不下 —— CDN 失败不该拖垮本地上传。
                $response = 'uploadImages exception: ' . $e->getMessage();
            }
            ob_end_clean();

            // 失败时 SDK 返回 "uploadImages: ..." 描述串（成功返回 commit 的 JSON）。
            // 原本这个返回值被直接丢掉，出错时前台只有一个没有原因的报错。
            if (is_string($response) && strpos($response, 'uploadImages') === 0) {
                error_log('[kratos imagex] ' . $response . ' object=' . $object);
                return false;
            }
        } else {
            return false;
        }
    }

    // 上传附件
    function imagex_upload_attachments($metadata)
    {
        if (get_option('upload_path') == '.') {
            $metadata['file'] = str_replace("./", '', $metadata['file']);
        }

        $object = str_replace("\\", '/', $metadata['file']);
        $object = str_replace(get_home_path(), '', $object);
        $object = str_replace("wp-content/uploads/", '', $object);
        $file = get_home_path() . "wp-content/uploads/" . $object;
        imagex_upload($object, $file);

        return $metadata;
    }

    if (substr_count($_SERVER['REQUEST_URI'], '/update.php') <= 0) {
        add_filter('wp_handle_upload', 'imagex_upload_attachments', 50);
    }

    // 上传缩略图
    function imagex_upload_thumbs($metadata)
    {
        if (isset($metadata['sizes']) && count($metadata['sizes']) > 0) {
            $wp_uploads = wp_upload_dir();
            $basedir = $wp_uploads['basedir'];
            $file_dir = $metadata['file'];
            $file_path = $basedir . '/' . dirname($file_dir) . '/';
            if (get_option('upload_path') == '.') {
                $file_path = str_replace("\\", '/', $file_path);
                $file_path = str_replace(get_home_path() . "./", '', $file_path);
            } else {
                $file_path = str_replace("\\", '/', $file_path);
            }
            $file_path = str_replace("./", '', $file_path);
            $object_path = str_replace(get_home_path(), '', $file_path);
            foreach ($metadata['sizes'] as $val) {
                $object = $object_path . $val['file'];
                $object = str_replace("wp-content/uploads/", '', $object);
                $file = $file_path . $val['file'];
                imagex_upload($object, $file);
            }
        }
        return $metadata;
    }

    if (substr_count($_SERVER['REQUEST_URI'], '/update.php') <= 0) {
        add_filter('wp_generate_attachment_metadata', 'imagex_upload_thumbs', 100);
    }

    // 删除文件
    function imagex_delete_remote_file($file)
    {
        $client = imagex_get_client();
        $file = str_replace("\\", '/', $file);
        $file = str_replace(get_home_path(), '', $file);
        $del_file_path = str_replace("wp-content/uploads/", '', $file);

        // 同 imagex_upload：SDK 不接 Guzzle 异常，删远端失败不该让删附件整体 500。
        try {
            $client->deleteImages(kratos_option('g_imgx_fieldset')['g_imgx_serviceid'], array($del_file_path));
        } catch (\Throwable $e) {
            error_log('[kratos imagex] deleteImages exception: ' . $e->getMessage() . ' object=' . $del_file_path);
        }

        return $file;
    }
    add_action('wp_delete_file', 'imagex_delete_remote_file', 100);

    // 修改图片地址
    function custom_upload_dir($uploads)
    {
        $upload_path = '';
        $upload_url_path = kratos_option('g_imgx_fieldset')['g_imgx_url'];

        if (empty($upload_path) || 'wp-content/uploads' == $upload_path) {
            $uploads['basedir'] = WP_CONTENT_DIR . '/uploads';
        } elseif (0 !== strpos($upload_path, ABSPATH)) {
            $uploads['basedir'] = path_join(ABSPATH, $upload_path);
        } else {
            $uploads['basedir'] = $upload_path;
        }

        $uploads['path'] = $uploads['basedir'] . $uploads['subdir'];

        if ($upload_url_path) {
            $uploads['baseurl'] = $upload_url_path;
            $uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
        }

        if (substr($upload_url_path, -1) == '/') {
            $upload_url_path = str_replace(get_home_path(), '', $upload_url_path);
        }

        return $uploads;
    }
    add_filter('upload_dir', 'custom_upload_dir');

    function imagex_setting_content_ci($content)
    {
        preg_match_all('/<img.*?(?: |\\t|\\r|\\n)?src=[\'"]?(.+?)[\'"]?(?:(?: |\\t|\\r|\\n)+.*?)?>/sim', $content, $images);
        if (!empty($images) && isset($images[1])) {
            foreach ($images[1] as $item) {
                $content = str_replace($item, $item . kratos_option('g_imgx_fieldset')['g_imgx_tmp'], $content);
            }
        }
        return $content;
    }
    add_filter('the_content', 'imagex_setting_content_ci');

    function imagex_setting_post_thumbnail_ci($html, $post_id, $post_image_id)
    {
        if (has_post_thumbnail()) {
            preg_match_all('/<img.*?(?: |\\t|\\r|\\n)?src=[\'"]?(.+?)[\'"]?(?:(?: |\\t|\\r|\\n)+.*?)?>/sim', $html, $images);
            if (!empty($images) && isset($images[1])) {
                foreach ($images[1] as $item) {
                    $html = str_replace($item, $item . kratos_option('g_imgx_fieldset')['g_imgx_tmp'], $html);
                }
            }
        }
        return $html;
    }
    add_filter('post_thumbnail_html', 'imagex_setting_post_thumbnail_ci', 10, 3);
}
