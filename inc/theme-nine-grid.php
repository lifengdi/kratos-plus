<?php

/**
 * 九宫格图组：文章正文里插入的多图区块，前端复用说说 kratos_media_render()
 * 的整套布局（1/2/3/4/9 grid、+N 溢出、灯箱），因此不重写任何样式与 JS。
 *
 * 提供两种入口：
 *   - Gutenberg 区块 kratos/nine-grid（推荐；attributes.ids 为附件 ID 数组）
 *   - 短代码 [nine_grid ids="1,2,3"]（经典编辑器 / 手写场景）
 *
 * 同一篇文章允许多次插入：每次渲染都用递增静态计数生成独立 grid_id，并挂
 * data-lightbox-host="1" 让说说的 per-host lightGallery 初始化按块分组，
 * 避免翻页翻到同页里其它组。
 *
 * @author Dylan Li (Kratos-plus) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 统一渲染入口。$args['ids'] 为附件 ID 数组。
 */
function kratos_nine_grid_render($args)
{
    $ids = isset($args['ids']) && is_array($args['ids']) ? $args['ids'] : array();
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids)) {
        return '';
    }

    $images = array();
    foreach ($ids as $id) {
        // 用 large 尺寸作为网格里的 URL；kratos_media_attachment_map() 反查得到
        // 同一附件 ID 后，kratos_media_full_url() 会自动升级为 full 供灯箱使用。
        $url = wp_get_attachment_image_url($id, 'large');
        if ($url) {
            $images[] = $url;
        }
    }
    if (empty($images)) {
        return '';
    }

    if (!function_exists('kratos_media_render')) {
        return '';
    }

    // 灯箱资源 + 说说的 per-host 初始化脚本（后者才是按 .kss-images 分组的关键）
    if (function_exists('kratos_shuoshuo_enqueue_js')) {
        kratos_shuoshuo_enqueue_js();
    }

    static $seq = 0;
    $seq++;
    $post_id = get_the_ID();
    $grid_id = 'kng-' . ($post_id ? (int) $post_id : '0') . '-' . $seq;

    ob_start();
    // 说说 grid 的样式（.kratos-shuoshuo .kss-images / .kss-img-cell / ...）以内联
    // <style> 形式随说说渲染打印；文章页原本不走那条路径，得在这里主动吐一次。
    // kratos_shuoshuo_assets() 内部有 static $printed 幂等保护，多次调用只会真吐一次。
    if (function_exists('kratos_shuoshuo_assets')) {
        echo kratos_shuoshuo_assets();
    }
    ?>
    <div class="kratos-shuoshuo kratos-nine-grid" data-lightbox-host="1">
        <?php kratos_media_render($post_id ? (int) $post_id : 0, $images, array(), array(
            'grid_id'      => $grid_id,
            'data_src'     => true,
            'grid_size'    => 'medium',
            'grid_sizes'   => '(max-width: 640px) 31vw, 172px',
            'single_size'  => 'large',
            'single_sizes' => '(max-width: 640px) 92vw, 560px',
        )); ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Gutenberg 区块：kratos/nine-grid。attributes.ids 存附件 ID 数组。
 */
function kratos_nine_grid_register_block()
{
    if (!function_exists('register_block_type')) {
        return;
    }
    register_block_type('kratos/nine-grid', array(
        'api_version' => 3,
        'attributes'  => array(
            'ids' => array(
                'type'    => 'array',
                'default' => array(),
                'items'   => array('type' => 'number'),
            ),
        ),
        'render_callback' => function ($attrs) {
            return kratos_nine_grid_render(array(
                'ids' => isset($attrs['ids']) ? (array) $attrs['ids'] : array(),
            ));
        },
    ));
}
add_action('init', 'kratos_nine_grid_register_block', 20);

/**
 * 短代码：[nine_grid ids="12,34,56"]
 * 兼容经典编辑器；同一篇文章允许多次使用，静态计数保证 grid_id 唯一。
 */
function kratos_nine_grid_shortcode($atts)
{
    $atts = shortcode_atts(array('ids' => ''), $atts, 'nine_grid');
    $raw  = (string) $atts['ids'];
    $ids  = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw)));
    return kratos_nine_grid_render(array('ids' => $ids));
}
add_shortcode('nine_grid', 'kratos_nine_grid_shortcode');

/**
 * 编辑器 JS 入队。与 theme-gutenberg-blocks.php 的短码块脚本并列，各注册各的
 * 区块名，互不覆盖。
 */
function kratos_nine_grid_enqueue_editor()
{
    $js_rel = '/assets/js/blocks/nine-grid.js';
    $js_abs = get_template_directory() . $js_rel;
    if (!file_exists($js_abs)) {
        return;
    }
    wp_enqueue_script(
        'kratos-nine-grid-editor',
        get_template_directory_uri() . $js_rel,
        array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'),
        THEME_VERSION . '.' . filemtime($js_abs),
        true
    );
}
add_action('enqueue_block_editor_assets', 'kratos_nine_grid_enqueue_editor');
