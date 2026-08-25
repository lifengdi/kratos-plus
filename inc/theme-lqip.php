<?php

/**
 * LQIP（Low-Quality Image Placeholder）—— 图片加载态模糊渐显
 *
 * 上传附件时把原图缩到 24px 宽 → 输出 WebP → base64 → 存入附件 meta `_kratos_lqip`。
 * 前端渲染 <img> 时：
 *   - 通过 background-image 显示这张 base64 缩略图（浏览器自动放大 → 天然模糊）
 *   - <img> 本身 opacity:0，加载完成后 JS 打 data-loaded 淡入
 * 与 theme-thumb-placeholder.php 互不冲突：
 *   - 那个模块处理「没有特色图」时的 SVG 占位
 *   - 本模块处理「有图但未加载完」的渐显
 *
 * @author Dylan Li (Kratos-plus) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

function kratos_lqip_defaults()
{
    return array(
        'g_lqip_enabled'   => false,
        'g_lqip_width'     => 24,
        'g_lqip_transition' => 400,
    );
}

add_filter('default_option_kratos_options', function ($default) {
    $defs = kratos_lqip_defaults();
    if (is_array($default)) {
        return array_merge($defs, $default);
    }
    return $defs;
}, 10, 1);

add_filter('option_kratos_options', function ($value) {
    if (!is_array($value)) {
        return $value;
    }
    foreach (kratos_lqip_defaults() as $k => $v) {
        if (!array_key_exists($k, $value) || $value[$k] === '' || $value[$k] === null) {
            $value[$k] = $v;
        }
    }
    return $value;
}, 10, 1);

/**
 * 生成单张附件的 LQIP。成功返回 data URI，失败返回 WP_Error / false。
 * 复用逻辑：上传钩子、后台批量回填、WP-CLI 都调它。
 */
function kratos_lqip_generate($attachment_id)
{
    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) {
        return new WP_Error('lqip_no_file', 'attachment file not found');
    }
    // SVG / GIF 首帧要么无意义要么会失败，直接跳过
    $mime = get_post_mime_type($attachment_id);
    if (!$mime || strpos($mime, 'image/') !== 0) {
        return new WP_Error('lqip_not_image', 'not an image');
    }
    if (in_array($mime, array('image/svg+xml', 'image/gif'), true)) {
        return new WP_Error('lqip_skip_mime', 'skip svg/gif');
    }

    $editor = wp_get_image_editor($file);
    if (is_wp_error($editor)) {
        return $editor;
    }

    $width = max(8, min(64, (int) kratos_option('g_lqip_width', 24)));
    $editor->resize($width, null, false);
    $editor->set_quality(60);

    $out_mime = 'image/webp';
    $tmp      = wp_tempnam('lqip') . '.webp';
    $saved    = $editor->save($tmp, $out_mime);
    if (is_wp_error($saved)) {
        // 服务器不支持 WebP 编码 → 回退 JPEG
        $out_mime = 'image/jpeg';
        $tmp      = wp_tempnam('lqip') . '.jpg';
        $saved    = $editor->save($tmp, $out_mime);
    }
    if (is_wp_error($saved)) {
        return $saved;
    }

    $bin = @file_get_contents($saved['path']);
    @unlink($saved['path']);
    if (!$bin) {
        return new WP_Error('lqip_read_fail', 'read tmp failed');
    }

    $data_uri = 'data:' . $out_mime . ';base64,' . base64_encode($bin);
    update_post_meta($attachment_id, '_kratos_lqip', $data_uri);
    return $data_uri;
}

/**
 * 附件生成尺寸时顺带算 LQIP。
 */
add_filter('wp_generate_attachment_metadata', function ($metadata, $attachment_id) {
    if (!kratos_option('g_lqip_enabled', false)) {
        return $metadata;
    }
    kratos_lqip_generate($attachment_id);
    return $metadata;
}, 10, 2);

/**
 * 通过 wp_get_attachment_image_* 系列输出的 <img>（列表页缩略图、模板里 the_post_thumbnail 等）
 * 注入 background-image 占位 + has-lqip 类。
 */
add_filter('wp_get_attachment_image_attributes', function ($attr, $attachment) {
    if (!kratos_option('g_lqip_enabled', false)) {
        return $attr;
    }
    if (!is_object($attachment) || empty($attachment->ID)) {
        return $attr;
    }
    $lqip = get_post_meta($attachment->ID, '_kratos_lqip', true);
    if (!$lqip) {
        return $attr;
    }
    $attr['class']    = trim(($attr['class'] ?? '') . ' has-lqip');
    $extra_style      = "background-image:url('" . esc_attr($lqip) . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
    $attr['style']    = ($attr['style'] ?? '') . $extra_style;
    $attr['loading']  = $attr['loading'] ?? 'lazy';
    $attr['decoding'] = 'async';
    return $attr;
}, 10, 2);

/**
 * 文章正文里由 the_content 输出的 <img>（编辑器插入的图）
 * 通过 class="wp-image-N" 反查 attachment_id。
 */
add_filter('wp_content_img_tag', function ($filtered_image, $context, $attachment_id) {
    if (!kratos_option('g_lqip_enabled', false)) {
        return $filtered_image;
    }
    if (!$attachment_id) {
        // WordPress 有时不传，用正则从 class 里挖
        if (preg_match('/wp-image-(\d+)/', $filtered_image, $m)) {
            $attachment_id = (int) $m[1];
        }
    }
    if (!$attachment_id) {
        return $filtered_image;
    }
    $lqip = get_post_meta($attachment_id, '_kratos_lqip', true);
    if (!$lqip) {
        return $filtered_image;
    }
    // 追加 class
    if (preg_match('/\sclass=(["\'])(.*?)\1/i', $filtered_image, $m)) {
        $new_class = trim($m[2] . ' has-lqip');
        $filtered_image = str_replace($m[0], ' class=' . $m[1] . $new_class . $m[1], $filtered_image);
    } else {
        $filtered_image = preg_replace('/<img\s/i', '<img class="has-lqip" ', $filtered_image, 1);
    }
    // 追加 style
    $bg = "background-image:url('" . esc_attr($lqip) . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
    if (preg_match('/\sstyle=(["\'])(.*?)\1/i', $filtered_image, $m)) {
        $new_style = rtrim($m[2], '; ') . ';' . $bg;
        $filtered_image = str_replace($m[0], ' style=' . $m[1] . $new_style . $m[1], $filtered_image);
    } else {
        $filtered_image = preg_replace('/<img\s/i', '<img style="' . $bg . '" ', $filtered_image, 1);
    }
    return $filtered_image;
}, 10, 3);

/**
 * 前端 inline CSS + JS：opacity 淡入。
 * 只在开关开启时输出，避免所有页面白挂 KB。
 */
add_action('wp_enqueue_scripts', function () {
    if (!kratos_option('g_lqip_enabled', false)) {
        return;
    }
    $ms  = max(100, min(2000, (int) kratos_option('g_lqip_transition', 400)));
    // 关键：背景图挂在 <img> 元素本身，不能对 img 做 opacity:0（会连背景一起隐藏）。
    // 加载中让 img 内容透明（color:transparent 兜住 alt 文本、真实像素浏览器本来就还没画），
    // 背景占位自然露出；加载完成后用 filter 淡出模糊感（真图已覆盖背景，此处只做过渡）。
    $css = "
        img.has-lqip{
            color:transparent;
            transition:filter {$ms}ms ease, transform {$ms}ms ease;
            filter:blur(0);
        }
        img.has-lqip:not([data-loaded]){
            /* 未加载时无需模糊 —— 背景图本身已是模糊的低清图 */
        }
        img.has-lqip[data-loaded=\"1\"]{
            background-image:none !important;
        }
        @media (prefers-reduced-motion: reduce){
            img.has-lqip{transition:none;}
        }
    ";
    wp_add_inline_style('kratos', $css);

    $js = "
        (function(){
            function mark(img){ img.setAttribute('data-loaded','1'); }
            function scan(root){
                (root || document).querySelectorAll('img.has-lqip:not([data-loaded])').forEach(function(img){
                    if (img.complete && img.naturalWidth > 0) { mark(img); return; }
                    img.addEventListener('load',  function(){ mark(img); }, { once: true });
                    img.addEventListener('error', function(){ mark(img); }, { once: true });
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function(){ scan(); });
            } else {
                scan();
            }
            // 兼容 AJAX/PJAX 后新插入的 img
            if (window.MutationObserver) {
                new MutationObserver(function(muts){
                    muts.forEach(function(m){
                        m.addedNodes && m.addedNodes.forEach(function(n){
                            if (n.nodeType === 1) {
                                if (n.tagName === 'IMG') scan(n.parentNode);
                                else scan(n);
                            }
                        });
                    });
                }).observe(document.body, { childList: true, subtree: true });
            }
        })();
    ";
    wp_add_inline_script('kratos', $js);
}, 20);

/* ---------------- 后台批量回填 ---------------- */

add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        __('LQIP 回填', 'kratos'),
        __('LQIP 回填', 'kratos'),
        'manage_options',
        'kratos-lqip-backfill',
        'kratos_lqip_backfill_page'
    );
});

function kratos_lqip_backfill_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $total   = (int) wp_count_posts('attachment')->inherit;
    $done    = kratos_lqip_count_done();
    $pending = max(0, $total - $done);
    $nonce   = wp_create_nonce('kratos_lqip_backfill');
    ?>
    <div class="wrap">
        <h1><?php _e('LQIP 回填', 'kratos'); ?></h1>
        <p><?php _e('为媒体库中已有的图片附件生成模糊占位（LQIP）。SVG / GIF 会跳过；已生成的不会重复。', 'kratos'); ?></p>
        <p>
            <strong><?php _e('附件总数：', 'kratos'); ?></strong><span id="lqip-total"><?php echo esc_html($total); ?></span>
            <strong><?php _e('已生成：', 'kratos'); ?></strong><span id="lqip-done"><?php echo esc_html($done); ?></span>
            <strong><?php _e('待处理：', 'kratos'); ?></strong><span id="lqip-pending"><?php echo esc_html($pending); ?></span>
        </p>
        <p>
            <button type="button" class="button button-primary" id="lqip-start"><?php _e('开始回填', 'kratos'); ?></button>
            <button type="button" class="button" id="lqip-stop" disabled><?php _e('停止', 'kratos'); ?></button>
        </p>
        <div id="lqip-log" style="max-height:280px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:10px;font-family:monospace;font-size:12px;"></div>
        <script>
        (function(){
            var running = false, offset = 0;
            var $log = document.getElementById('lqip-log');
            function log(msg){ var d=document.createElement('div'); d.textContent=msg; $log.appendChild(d); $log.scrollTop=$log.scrollHeight; }
            function refreshCounts(done, pending){
                document.getElementById('lqip-done').textContent = done;
                document.getElementById('lqip-pending').textContent = pending;
            }
            function step(){
                if (!running) return;
                var form = new FormData();
                form.append('action', 'kratos_lqip_backfill');
                form.append('_wpnonce', '<?php echo esc_js($nonce); ?>');
                form.append('offset', offset);
                fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res || !res.success) { log('错误：' + (res && res.data ? res.data : '未知')); running=false; toggle(false); return; }
                        var d = res.data;
                        log('批次处理 ' + d.processed + ' 张（成功 ' + d.ok + '，跳过 ' + d.skip + '，失败 ' + d.fail + '）');
                        refreshCounts(d.done, d.pending);
                        if (d.finished) { log('全部完成 ✓'); running=false; toggle(false); return; }
                        offset = d.next_offset;
                        setTimeout(step, 200);
                    })
                    .catch(function(e){ log('请求失败：' + e); running=false; toggle(false); });
            }
            function toggle(on){
                document.getElementById('lqip-start').disabled = on;
                document.getElementById('lqip-stop').disabled = !on;
            }
            document.getElementById('lqip-start').addEventListener('click', function(){
                running = true; offset = 0; toggle(true); log('开始…'); step();
            });
            document.getElementById('lqip-stop').addEventListener('click', function(){
                running = false; toggle(false); log('已停止');
            });
        })();
        </script>
    </div>
    <?php
}

function kratos_lqip_count_done()
{
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_kratos_lqip'
         WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
    );
}

add_action('wp_ajax_kratos_lqip_backfill', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('permission denied');
    }
    check_ajax_referer('kratos_lqip_backfill', '_wpnonce');

    $batch  = 10;
    $offset = max(0, (int) ($_POST['offset'] ?? 0));

    // 查找尚未生成 LQIP 的图片附件（不用 NOT EXISTS 子查询避免慢）
    $q = new WP_Query(kratos_lean_query_args(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp', 'image/bmp'),
        'posts_per_page' => $batch,
        'offset'         => $offset,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => array(
            array('key' => '_kratos_lqip', 'compare' => 'NOT EXISTS'),
        ),
    ), array('no_terms' => true)));

    $ok = $skip = $fail = 0;
    foreach ($q->posts as $id) {
        $r = kratos_lqip_generate($id);
        if (is_wp_error($r)) {
            if (in_array($r->get_error_code(), array('lqip_skip_mime', 'lqip_not_image'), true)) {
                $skip++;
                // 打个标记免得下次又查出来
                update_post_meta($id, '_kratos_lqip', '');
            } else {
                $fail++;
            }
        } else {
            $ok++;
        }
    }

    $processed = count($q->posts);
    $done      = kratos_lqip_count_done();
    $total     = (int) wp_count_posts('attachment')->inherit;
    $finished  = $processed < $batch;

    wp_send_json_success(array(
        'processed'   => $processed,
        'ok'          => $ok,
        'skip'        => $skip,
        'fail'        => $fail,
        'done'        => $done,
        'pending'     => max(0, $total - $done),
        'next_offset' => 0,     // meta_query NOT EXISTS 会自动跳过已处理的，永远查前 N 条即可
        'finished'    => $finished,
    ));
});
