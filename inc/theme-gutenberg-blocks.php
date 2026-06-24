<?php

/**
 * Gutenberg 区块：把 theme-shortcode.php 里的 19 个 TinyMCE 按钮在 Gutenberg
 * 编辑器中也提供"快捷入口"。所有区块为动态块（save 返回 null），render_callback
 * 把属性拼回原 shortcode 字符串再 do_shortcode()，复用既有 PHP 渲染逻辑：
 * 评论权限校验（[reply]）、DPlayer 实例计数、iframe 拼装等无需重写。
 *
 * @author Dylan Li (Kratos+) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 区块定义。按"渲染输出"的形态分四类：
 *   wrap   -> [sc]content[/sc]                    （RichText content）
 *   card   -> [sc title="t"]content[/sc]          （PlainText title + RichText content）
 *   value  -> [sc]value[/sc]                      （单一字段，TextControl 输入）
 *   accordion / reply 与 wrap/card 同形，只是编辑器侧 UI 不同
 */
function kratos_blocks_defs()
{
    return array(
        // wrap - 仅 content
        'h2title'   => array('shortcode' => 'h2title',   'kind' => 'wrap'),
        'success'   => array('shortcode' => 'success',   'kind' => 'wrap'),
        'info'      => array('shortcode' => 'info',      'kind' => 'wrap'),
        'warning'   => array('shortcode' => 'warning',   'kind' => 'wrap'),
        'danger'    => array('shortcode' => 'danger',    'kind' => 'wrap'),
        'kbd'       => array('shortcode' => 'kbd',       'kind' => 'wrap'),
        'mark'      => array('shortcode' => 'mark',      'kind' => 'wrap'),
        'reply'     => array('shortcode' => 'reply',     'kind' => 'wrap'),

        // card - title + content
        'successbox' => array('shortcode' => 'successbox', 'kind' => 'card'),
        'infobox'    => array('shortcode' => 'infobox',    'kind' => 'card'),
        'warningbox' => array('shortcode' => 'warningbox', 'kind' => 'card'),
        'dangerbox'  => array('shortcode' => 'dangerbox',  'kind' => 'card'),
        'accordion'  => array('shortcode' => 'accordion',  'kind' => 'card'),

        // value - 单字段（值即 shortcode 内容）
        'striped'  => array('shortcode' => 'striped',  'kind' => 'value', 'attr' => 'percent'),
        'bdbtn'    => array('shortcode' => 'bdbtn',    'kind' => 'value', 'attr' => 'url'),
        'music'    => array('shortcode' => 'music',    'kind' => 'value', 'attr' => 'songId'),
        'vqq'      => array('shortcode' => 'vqq',      'kind' => 'value', 'attr' => 'vid'),
        'youtube'  => array('shortcode' => 'youtube',  'kind' => 'value', 'attr' => 'videoId'),
        'bilibili' => array('shortcode' => 'bilibili', 'kind' => 'value', 'attr' => 'bvid'),
    );
}

/**
 * 把块属性拼回 shortcode 字符串，再交给 do_shortcode。
 * 这是复用 theme-shortcode.php 全部业务逻辑的关键 —— 不直接生成 HTML。
 */
function kratos_blocks_render($attrs, $def)
{
    $sc = $def['shortcode'];

    if ($def['kind'] === 'value') {
        $val = isset($attrs[$def['attr']]) ? trim((string) $attrs[$def['attr']]) : '';
        if ($val === '') {
            return '';
        }
        return do_shortcode('[' . $sc . ']' . $val . '[/' . $sc . ']');
    }

    $content = isset($attrs['content']) ? (string) $attrs['content'] : '';

    if ($def['kind'] === 'card') {
        $title = isset($attrs['title']) ? trim((string) $attrs['title']) : '';
        // 防止 title 中的 " [ ] 破坏外层短码语法
        $title = str_replace(array('"', '[', ']'), array('&quot;', '', ''), $title);
        if ($title !== '') {
            return do_shortcode('[' . $sc . ' title="' . $title . '"]' . $content . '[/' . $sc . ']');
        }
        return do_shortcode('[' . $sc . ']' . $content . '[/' . $sc . ']');
    }

    // wrap
    return do_shortcode('[' . $sc . ']' . $content . '[/' . $sc . ']');
}

/**
 * 注册全部 19 个块。属性集合按 kind 装配；服务端属性必须与客户端一致，否则
 * render_callback 拿不到数据。
 */
function kratos_blocks_register()
{
    if (!function_exists('register_block_type')) {
        return;
    }

    foreach (kratos_blocks_defs() as $name => $def) {
        $attributes = array();
        if ($def['kind'] === 'value') {
            $attributes[$def['attr']] = array('type' => 'string', 'default' => '');
        } else {
            $attributes['content'] = array('type' => 'string', 'default' => '');
            if ($def['kind'] === 'card') {
                $attributes['title'] = array('type' => 'string', 'default' => '');
            }
        }

        register_block_type('kratos/' . $name, array(
            'attributes' => $attributes,
            'render_callback' => function ($attrs) use ($def) {
                return kratos_blocks_render($attrs, $def);
            },
        ));
    }
}
add_action('init', 'kratos_blocks_register', 20);

/**
 * 独立块：kratos/search —— 给 Gutenberg 编辑器一个"主题搜索小工具"入口。
 *
 * 与传统 widget_search::widget() 输出完全一致（同 DOM、同 placeholder、同 i18n
 * 文案），便于和小工具页拖入的搜索保持视觉一致。
 *
 * 单实例输入框 id（searchform / search-widgets / searchsubmit）保留与传统
 * widget 一致 —— 同一页面只放一个该块，否则与 widget 冲突。
 */

/**
 * 检测当前 render_callback 是否被 WP_Widget_Block 调用（即在小工具区渲染）。
 * 该类的 widget() 方法会在 stack 顶层；用 backtrace 反查最稳。
 */
function kratos_is_widget_block_context()
{
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15) as $f) {
        if (isset($f['class']) && $f['class'] === 'WP_Widget_Block') {
            return true;
        }
    }
    return false;
}

function kratos_block_search_render($attrs)
{
    $title = isset($attrs['title']) ? trim((string) $attrs['title']) : '';

    $inner = '';
    if ($title !== '') {
        $inner .= '<div class="title">' . esc_html($title) . '</div>';
    }
    $inner .= '<div class="item"> <form role="search" method="get" id="searchform" class="searchform" action="'
        . esc_url(home_url('/'))
        . '"> <div class="input-group mt-2 mb-2"> <input type="text" name="s" id="search-widgets" class="form-control" placeholder="'
        . esc_attr(__('搜点什么呢?', 'kratos'))
        . '"> <div class="input-group-append"> <button class="btn btn-primary btn-search" type="submit" id="searchsubmit">'
        . esc_html(__('搜索', 'kratos'))
        . '</button> </div> </div> </form></div>';

    // 小工具区：外层已被 WP_Widget_Block 包装为 <div class="widget widget_block w-search">，
    // 不再叠一层 .widget.w-search，避免重复包裹（padding/border 叠加）
    if (kratos_is_widget_block_context()) {
        return $inner;
    }

    // 文章/页面正文：自己包一层 .widget.w-search，让主题既有 .widget.w-search 选择器生效
    return '<div class="widget w-search">' . $inner . '</div>';
}

/**
 * 让 widget block 容器在内含 kratos/search 时携带 w-search 类，
 * 这样在小工具区也能匹配主题 .widget.w-search 样式（保留 widget_block 类不动）。
 * 该 filter 在 WP 5.8+ 由 WP_Widget_Block 提供。
 */
function kratos_widget_block_classname($classname, $block_name)
{
    if ($block_name === 'kratos/search') {
        return trim($classname . ' w-search');
    }
    return $classname;
}
add_filter('widget_block_dynamic_classname', 'kratos_widget_block_classname', 10, 2);

function kratos_block_search_register()
{
    if (!function_exists('register_block_type')) {
        return;
    }
    register_block_type('kratos/search', array(
        'attributes' => array(
            'title' => array('type' => 'string', 'default' => ''),
        ),
        'render_callback' => 'kratos_block_search_render',
    ));
}
add_action('init', 'kratos_block_search_register', 20);

/**
 * 区块插入器分类。WP 5.8+ 用 block_categories_all，旧版本用 block_categories；
 * 两者签名兼容，同时挂上即可。
 */
function kratos_blocks_register_category($cats)
{
    foreach ((array) $cats as $c) {
        if (isset($c['slug']) && $c['slug'] === 'kratos-blocks') {
            return $cats;
        }
    }
    return array_merge(array(
        array(
            'slug'  => 'kratos-blocks',
            'title' => __('Kratos+ 短码', 'kratos'),
            'icon'  => null,
        ),
    ), (array) $cats);
}
add_filter('block_categories_all', 'kratos_blocks_register_category');
add_filter('block_categories', 'kratos_blocks_register_category');

/**
 * 加载编辑器侧 JS（注册块）+ CSS（编辑器内可视化预览）。
 */
function kratos_blocks_enqueue_editor()
{
    $js_rel  = '/assets/js/blocks/blocks.js';
    $css_rel = '/assets/css/blocks-editor.css';
    $js_abs  = get_template_directory() . $js_rel;
    $css_abs = get_template_directory() . $css_rel;

    wp_enqueue_script(
        'kratos-blocks-editor',
        get_template_directory_uri() . $js_rel,
        array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'),
        THEME_VERSION . '.' . (file_exists($js_abs) ? filemtime($js_abs) : '0'),
        true
    );

    wp_enqueue_style(
        'kratos-blocks-editor',
        get_template_directory_uri() . $css_rel,
        array(),
        THEME_VERSION . '.' . (file_exists($css_abs) ? filemtime($css_abs) : '0')
    );
}
add_action('enqueue_block_editor_assets', 'kratos_blocks_enqueue_editor');

/**
 * 前台样式：让 [success]/[info]/... 等短码渲染出的 .alert / .card 与
 * 编辑器内预览保持一致（浅色背景、左侧色带、SVG 图标）。
 *
 * 依赖 'kratos' 主样式 → 必须排在 style.css 之后入队，确保特异性相同时由本表覆盖。
 */
function kratos_blocks_enqueue_front()
{
    $css_rel = '/assets/css/blocks-front.css';
    $css_abs = get_template_directory() . $css_rel;
    if (!file_exists($css_abs)) {
        return;
    }
    wp_enqueue_style(
        'kratos-blocks-front',
        get_template_directory_uri() . $css_rel,
        array('kratos'),
        THEME_VERSION . '.' . filemtime($css_abs)
    );
}
add_action('wp_enqueue_scripts', 'kratos_blocks_enqueue_front', 30);
