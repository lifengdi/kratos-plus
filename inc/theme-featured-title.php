<?php

/*
 * 「Kratos+特色标题」页面模板专用 Metabox
 *
 * 仅在页面编辑器中、且模板选为 page-featured-title.php 时显示，
 * 提供 标题 / 副标题 / 图标（Font Awesome）三个字段。
 * 数据以独立 meta key 存储（data_type = unserialize），便于模板直接读取。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('CSF')) {
    $prefix = '_kft_meta';

    CSF::createMetabox($prefix, array(
        'title'          => __('Kratos+ 特色标题', 'kratos'),
        'post_type'      => 'page',
        'page_templates' => 'page-featured-title.php',
        'data_type'      => 'unserialize',
        'context'        => 'side',
        'priority'       => 'high',
        'theme'          => 'light',
    ));

    CSF::createSection($prefix, array(
        'fields' => array(
            array(
                'id'       => 'kft_title',
                'type'     => 'text',
                'title'    => __('标题', 'kratos'),
                'subtitle' => __('留空则使用页面标题', 'kratos'),
            ),
            array(
                'id'       => 'kft_subtitle',
                'type'     => 'text',
                'title'    => __('副标题', 'kratos'),
                'subtitle' => __('留空则使用页面摘要；仍为空则不显示副标题', 'kratos'),
            ),
            array(
                'id'       => 'kft_icon',
                'type'     => 'icon',
                'title'    => __('图标', 'kratos'),
                'subtitle' => __('留空使用默认星形图标', 'kratos'),
            ),
        ),
    ));
}

/**
 * 编辑页：兼容 Gutenberg 的模板切换事件 —— 新版块编辑器的模板选择器
 * DOM 已不是 CSF 内置监听的 `.editor-page-attributes__template select`，
 * 通过 wp.data 订阅 `core/editor` 的 template 变更，动态切换 metabox 的
 * `csf-metabox-show / csf-metabox-hide` 类，无需保存刷新即可显示。
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'post.php' && $hook !== 'post-new.php') {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'page') {
        return;
    }
    wp_add_inline_script('wp-edit-post', <<<'JS'
(function(){
    if (!window.wp || !wp.data || !wp.data.subscribe) return;
    var lastTpl = null;
    var slugify = function(t){ return (t || 'default').toLowerCase().replace(/[^a-zA-Z0-9]+/g, '-'); };
    wp.data.subscribe(function(){
        var editor = wp.data.select('core/editor');
        if (!editor || !editor.getEditedPostAttribute) return;
        var tpl = editor.getEditedPostAttribute('template') || 'default';
        if (tpl === lastTpl) return;
        lastTpl = tpl;
        var slug = slugify(tpl);
        document.querySelectorAll('.csf-page-templates').forEach(function(el){
            el.classList.add('csf-metabox-hide');
            el.classList.remove('csf-metabox-show');
        });
        document.querySelectorAll('.csf-page-' + slug).forEach(function(el){
            el.classList.remove('csf-metabox-hide');
            el.classList.add('csf-metabox-show');
        });
    });
})();
JS
    );
});

/**
 * 使用「Kratos+ 特色标题」模板时，若填了图标（Font Awesome class），
 * 自动加载 FA CSS（主题内置那一份，handle 与 theme_autoload() 一致，不会重复加载）。
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('page-featured-title.php')) {
        return;
    }
    if (wp_style_is('fontawesome', 'enqueued') || wp_style_is('fontawesome', 'registered')) {
        return;
    }
    $icon = trim((string) get_post_meta(get_queried_object_id(), 'kft_icon', true));
    if ($icon === '') {
        return;
    }
    wp_enqueue_style('fontawesome', get_template_directory_uri() . '/assets/css/fontawesome.min.css', array(), FA_VERSION);
}, 20);

/**
 * 读取当前页面的特色标题配置，未填时回退到页面标题 / 摘要 / 默认图标。
 * 返回 array{title:string, subtitle:string, icon:string}
 */
function kratos_featured_title_meta($post_id = null)
{
    $post_id = $post_id ?: get_the_ID();

    $title    = trim((string) get_post_meta($post_id, 'kft_title', true));
    $subtitle = trim((string) get_post_meta($post_id, 'kft_subtitle', true));
    $icon     = trim((string) get_post_meta($post_id, 'kft_icon', true));

    if ($title === '') {
        $title = get_the_title($post_id);
    }
    if ($subtitle === '' && has_excerpt($post_id)) {
        $subtitle = get_the_excerpt($post_id);
    }

    return array(
        'title'    => $title,
        'subtitle' => $subtitle,
        'icon'     => $icon, // Font Awesome class，如 "fas fa-star"；为空时模板用内联 SVG
    );
}
