<?php

/**
 * 系列文章（Series）
 *  - 注册 kratos_series 自定义分类（非分层）关联到 post
 *  - 编辑器侧栏 metabox：系列内排序（整数，越小越靠前）
 *  - 前台单篇文章在正文顶部渲染系列盒子：系列名 + 位置 + 文章列表 + 上下篇
 *
 * 系列内排序读取 meta `_kratos_series_order`；缺省时按发布时间升序（旧 → 新，符合连载）。
 *
 * @author Dylan Li (Kratos+) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 默认值：作为「数据库无值」与后台首次渲染的兜底
 */
function kratos_series_defaults()
{
    return array(
        'g_series_enabled'         => true,
        'g_series_title_tpl'       => '系列：%series%',
        'g_series_position_tpl'    => '第 %index% 篇 / 共 %total% 篇',
        'g_series_default_open'    => false,
        'g_series_replace_navi'    => true,
    );
}

add_filter('default_option_kratos_options', function ($default) {
    $defs = kratos_series_defaults();
    if (is_array($default)) return array_merge($defs, $default);
    return $defs;
}, 10, 1);

add_filter('option_kratos_options', function ($value) {
    if (!is_array($value)) return $value;
    foreach (kratos_series_defaults() as $k => $v) {
        if (!array_key_exists($k, $value) || $value[$k] === '' || $value[$k] === null) {
            $value[$k] = $v;
        }
    }
    return $value;
}, 10, 1);

/**
 * 注册 kratos_series taxonomy。
 * 非分层（类似 tag），便于扁平管理。
 */
add_action('init', function () {
    register_taxonomy('kratos_series', array('post'), array(
        'labels' => array(
            'name'              => __('系列', 'kratos'),
            'singular_name'     => __('系列', 'kratos'),
            'search_items'      => __('搜索系列', 'kratos'),
            'all_items'         => __('所有系列', 'kratos'),
            'edit_item'         => __('编辑系列', 'kratos'),
            'update_item'       => __('更新系列', 'kratos'),
            'add_new_item'      => __('新增系列', 'kratos'),
            'new_item_name'     => __('新系列名称', 'kratos'),
            'menu_name'         => __('系列', 'kratos'),
            'view_item'         => __('查看系列', 'kratos'),
            'back_to_items'     => __('← 返回系列列表', 'kratos'),
            'not_found'         => __('未找到系列', 'kratos'),
            'no_terms'          => __('暂无系列', 'kratos'),
            'items_list'        => __('系列列表', 'kratos'),
            'items_list_navigation' => __('系列列表导航', 'kratos'),
            'parent_item'       => __('父级系列', 'kratos'),
            'parent_item_colon' => __('父级系列：', 'kratos'),
        ),
        'public'            => true,
        'hierarchical'      => true,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => array('slug' => 'series'),
    ));

    // 主题版本变化 / 首次启用时自动 flush rewrite rules（避免 /series/xxx 404）。
    // 用 option 版本号做去重，正常请求下不会重复 flush。
    $flushed = get_option('kratos_series_rewrite_version');
    if ($flushed !== THEME_VERSION) {
        flush_rewrite_rules(false);
        update_option('kratos_series_rewrite_version', THEME_VERSION);
    }
}, 11);

// 切换到本主题时也强制 flush 一次
add_action('after_switch_theme', function () {
    flush_rewrite_rules(false);
    update_option('kratos_series_rewrite_version', defined('THEME_VERSION') ? THEME_VERSION : '0');
});

/**
 * 系列 term 的自定义图标（Font Awesome class）
 * 通过 CSF taxonomy options 在「文章 → 系列 → 编辑」页新增字段
 */
if (class_exists('CSF')) {
    CSF::createTaxonomyOptions('_kratos_series_term_meta', array(
        'taxonomy'  => 'kratos_series',
        'data_type' => 'unserialize',
    ));
    CSF::createSection('_kratos_series_term_meta', array(
        'fields' => array(
            array(
                'id'       => 'kratos_series_icon',
                'type'     => 'icon',
                'title'    => __('系列图标', 'kratos'),
                'subtitle' => __('留空则使用默认图标（fa-layer-group）', 'kratos'),
            ),
        ),
    ));
}

/**
 * 读取指定系列 term 的图标 class；无自定义时回退默认
 */
function kratos_series_get_icon($term_id)
{
    $icon = get_term_meta($term_id, 'kratos_series_icon', true);
    $icon = is_string($icon) ? trim($icon) : '';
    return $icon !== '' ? $icon : 'fas fa-layer-group';
}

/**
 * 系列页面（is_tax('kratos_series'))可能不属于主题原生资源逻辑，
 * 但若图标是 Font Awesome，前台单篇文章需要 FA 样式。
 * 若图标带 fa- 前缀且当前主题未启用 fontawesome，则临时入队。
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;
    if (!kratos_option('g_series_enabled', true)) return;

    $need_fa = false;
    if (is_singular('post')) {
        $term = kratos_series_get_current_term(get_the_ID());
        if ($term) {
            $icon = get_term_meta($term->term_id, 'kratos_series_icon', true);
            if ($icon && strpos($icon, 'fa') !== false) $need_fa = true;
        }
    } elseif (is_tax('kratos_series')) {
        // 归档模板使用 fas fa-clock 展示日期，且 term 图标也是 FA
        $need_fa = true;
    }
    if (!$need_fa) return;
    if (wp_style_is('fontawesome', 'enqueued') || wp_style_is('fontawesome', 'registered')) return;
    wp_enqueue_style('fontawesome', get_template_directory_uri() . '/assets/css/fontawesome.min.css', array(), '5.15.2');
}, 25);

/**
 * 编辑器侧栏 metabox：系列内排序值
 */
if (class_exists('CSF')) {
    $prefix_series = '_kratos_series_meta';
    CSF::createMetabox($prefix_series, array(
        'title'     => __('系列文章排序', 'kratos'),
        'post_type' => 'post',
        'data_type' => 'unserialize',
        'context'   => 'side',
        'priority'  => 'default',
        'theme'     => 'light',
    ));
    CSF::createSection($prefix_series, array(
        'fields' => array(
            array(
                'id'       => 'kratos_series_order',
                'type'     => 'number',
                'title'    => __('本文在系列中的顺序', 'kratos'),
                'subtitle' => __('数字越小越靠前；留空则按发布时间自动排序', 'kratos'),
                'default'  => '',
            ),
        ),
    ));
}

/**
 * 获取当前文章所属的第一个系列 term
 */
function kratos_series_get_current_term($post_id)
{
    $terms = get_the_terms($post_id, 'kratos_series');
    if (empty($terms) || is_wp_error($terms)) return null;
    return $terms[0];
}

/**
 * 拉取系列内所有文章，按 order meta 升序、其次按发布时间升序
 * 返回 array of WP_Post
 */
function kratos_series_get_posts($term_id)
{
    $q = new WP_Query(array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
        'ignore_sticky_posts' => 1,
        'tax_query'      => array(
            array('taxonomy' => 'kratos_series', 'field' => 'term_id', 'terms' => $term_id),
        ),
        'orderby' => 'date',
        'order'   => 'ASC',
    ));
    $posts = $q->posts;
    // 有 order meta 的按 order 升序；无 order meta 的按发布时间升序（放到有 order 的后面）
    usort($posts, function ($a, $b) {
        $oa = get_post_meta($a->ID, 'kratos_series_order', true);
        $ob = get_post_meta($b->ID, 'kratos_series_order', true);
        $ha = ($oa !== '' && $oa !== null && $oa !== false);
        $hb = ($ob !== '' && $ob !== null && $ob !== false);
        if ($ha && $hb) {
            $ia = (int) $oa; $ib = (int) $ob;
            if ($ia !== $ib) return $ia <=> $ib;
            return strtotime($a->post_date) <=> strtotime($b->post_date);
        }
        if ($ha) return -1;
        if ($hb) return 1;
        return strtotime($a->post_date) <=> strtotime($b->post_date);
    });
    return $posts;
}

/**
 * 渲染系列盒子（供 single.php 直接调用；插入正文顶部）
 * 位置：文章 header 之后、正文之前
 */
function kratos_series_render_box()
{
    if (!kratos_option('g_series_enabled', true)) return;
    if (!is_singular('post')) return;

    $post_id = get_the_ID();
    $term = kratos_series_get_current_term($post_id);
    if (!$term) return;

    $posts = kratos_series_get_posts($term->term_id);
    if (empty($posts) || count($posts) < 2) return;

    $total = count($posts);
    $index = 0;
    foreach ($posts as $i => $p) {
        if ((int)$p->ID === (int)$post_id) { $index = $i + 1; break; }
    }
    if ($index === 0) return;

    $title_tpl = kratos_option('g_series_title_tpl', __('系列：%series%', 'kratos'));
    $pos_tpl   = kratos_option('g_series_position_tpl', __('第 %index% 篇 / 共 %total% 篇', 'kratos'));
    $open      = (bool) kratos_option('g_series_default_open', true);

    $title = str_replace('%series%', esc_html($term->name), $title_tpl);
    $pos   = str_replace(
        array('%index%', '%total%'),
        array((int)$index, (int)$total),
        $pos_tpl
    );

    echo '<div class="kratos-series' . ($open ? ' is-open' : '') . '">';
    echo '<div class="kratos-series-head">';
    echo '<div class="kratos-series-titlewrap">';
    echo '<i class="' . esc_attr(kratos_series_get_icon($term->term_id)) . ' kratos-series-icon"></i>';
    echo '<a class="kratos-series-title" href="' . esc_url(get_term_link($term)) . '">' . wp_kses_post($title) . '</a>';
    echo '<span class="kratos-series-pos">' . esc_html($pos) . '</span>';
    echo '</div>';
    echo '<button type="button" class="kratos-series-toggle" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-label="' . esc_attr__('展开/收起系列列表', 'kratos') . '"><i class="fas fa-chevron-down"></i></button>';
    echo '</div>';

    echo '<ol class="kratos-series-list">';
    foreach ($posts as $i => $p) {
        $is_current = ((int)$p->ID === (int)$post_id);
        $classes = 'kratos-series-item' . ($is_current ? ' is-current' : '');
        echo '<li class="' . esc_attr($classes) . '">';
        echo '<span class="kratos-series-num">' . ((int)$i + 1) . '</span>';
        if ($is_current) {
            echo '<span class="kratos-series-name">' . esc_html($p->post_title) . '</span>';
            echo '<span class="kratos-series-badge">' . esc_html__('当前', 'kratos') . '</span>';
        } else {
            echo '<a class="kratos-series-name" href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html($p->post_title) . '</a>';
        }
        echo '</li>';
    }
    echo '</ol>';
    echo '</div>';
}

/**
 * 渲染系列上下篇导航（供 single.php 在原上一篇/下一篇位置调用）。
 * 使用主题原生 .post-navigation / .nav-previous / .nav-next 结构，保持样式一致。
 */
function kratos_series_render_nav()
{
    if (!kratos_option('g_series_enabled', true)) return;
    if (!is_singular('post')) return;
    $post_id = get_the_ID();
    $term = kratos_series_get_current_term($post_id);
    if (!$term) return;
    $posts = kratos_series_get_posts($term->term_id);
    if (empty($posts)) return;
    $idx = -1;
    foreach ($posts as $i => $p) {
        if ((int)$p->ID === (int)$post_id) { $idx = $i; break; }
    }
    if ($idx < 0) return;
    $prev = ($idx > 0) ? $posts[$idx - 1] : null;
    $next = ($idx < count($posts) - 1) ? $posts[$idx + 1] : null;
    if (!$prev && !$next) return;

    echo '<nav class="navigation post-navigation clearfix" role="navigation">';
    if ($prev) {
        echo '<div class="nav-previous clearfix"><a title="' . esc_attr($prev->post_title) . '" href="' . esc_url(get_permalink($prev->ID)) . '">' . __('&lt; 系列上一篇', 'kratos') . '</a></div>';
    }
    if ($next) {
        echo '<div class="nav-next"><a title="' . esc_attr($next->post_title) . '" href="' . esc_url(get_permalink($next->ID)) . '">' . __('系列下一篇 &gt;', 'kratos') . '</a></div>';
    }
    echo '</nav>';
}

/**
 * 前台资源：CSS + 折叠 JS 内联
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin() || !is_singular('post')) return;
    if (!kratos_option('g_series_enabled', true)) return;

    $accent = 'var(--kr-skin-accent, #0abbef)';
    $css = '';
    $css .= '.kratos-series{margin:0 0 24px;padding:0;background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden}';
    $css .= '.kratos-series-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;background:linear-gradient(90deg,rgba(10,187,239,.06),transparent);border-bottom:1px solid rgba(0,0,0,.05)}';
    $css .= '.kratos-series-titlewrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1;min-width:0}';
    $css .= '.kratos-series-icon{color:' . $accent . ';font-size:16px}';
    $css .= '.kratos-series-title{font-size:16px;font-weight:600;color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
    $css .= '.kratos-series-title:hover{color:' . $accent . '}';
    $css .= '.kratos-series-pos{font-size:12px;color:#888;padding:2px 8px;background:rgba(0,0,0,.04);border-radius:10px}';
    $css .= '.kratos-series-toggle{flex:0 0 auto;background:none;border:none;color:#888;cursor:pointer;padding:4px 6px;font-size:14px;transition:transform .25s}';
    $css .= '.kratos-series-toggle:hover{color:' . $accent . '}';
    $css .= '.kratos-series:not(.is-open) .kratos-series-toggle i{transform:rotate(-90deg)}';
    $css .= '.kratos-series-toggle i{transition:transform .25s;display:inline-block}';
    $css .= '.kratos-series-list{list-style:none;margin:0;padding:6px 0;max-height:600px;overflow:auto;transition:max-height .3s ease,padding .3s ease,opacity .2s}';
    $css .= '.kratos-series:not(.is-open) .kratos-series-list{max-height:0;padding-top:0;padding-bottom:0;opacity:0;overflow:hidden}';
    $css .= '.kratos-series-item{display:flex;align-items:center;gap:10px;padding:8px 16px;font-size:14px;line-height:1.5;border-left:3px solid transparent}';
    $css .= '.kratos-series-item.is-current{background:rgba(10,187,239,.06);border-left-color:' . $accent . '}';
    $css .= '.kratos-series-num{flex:0 0 auto;min-width:22px;height:22px;line-height:22px;text-align:center;background:rgba(0,0,0,.06);color:#666;border-radius:50%;font-size:12px}';
    $css .= '.kratos-series-item.is-current .kratos-series-num{background:' . $accent . ';color:#fff}';
    $css .= '.kratos-series-name{flex:1;color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
    $css .= 'a.kratos-series-name:hover{color:' . $accent . '}';
    $css .= '.kratos-series-item.is-current .kratos-series-name{font-weight:600}';
    $css .= '.kratos-series-badge{flex:0 0 auto;font-size:11px;padding:1px 6px;background:' . $accent . ';color:#fff;border-radius:3px}';
    // 暗色
    $css .= '[data-theme="dark"] .kratos-series{background:#1e1f22;border-color:rgba(255,255,255,.08);box-shadow:0 1px 3px rgba(0,0,0,.4)}';
    $css .= '[data-theme="dark"] .kratos-series-head{background:linear-gradient(90deg,rgba(10,187,239,.1),transparent);border-bottom-color:rgba(255,255,255,.08)}';
    $css .= '[data-theme="dark"] .kratos-series-pos{background:rgba(255,255,255,.06);color:#aaa}';
    $css .= '[data-theme="dark"] .kratos-series-num{background:rgba(255,255,255,.08);color:#bbb}';

    wp_register_style('kratos-series', false);
    wp_enqueue_style('kratos-series');
    wp_add_inline_style('kratos-series', $css);

    $js = '(function(){document.addEventListener("click",function(e){var t=e.target.closest(".kratos-series-toggle");if(!t)return;var box=t.closest(".kratos-series");if(!box)return;var open=box.classList.toggle("is-open");t.setAttribute("aria-expanded",open?"true":"false");});})();';
    wp_register_script('kratos-series', '', array(), THEME_VERSION, true);
    wp_enqueue_script('kratos-series');
    wp_add_inline_script('kratos-series', $js);
}, 30);

/**
 * 前台系列归档 /series/<slug>/：主查询按 kratos_series_order（升序）+ 发布时间（升序）排列，
 * 让 pagelist() 分页与系列内顺序保持一致。
 * 用 LEFT JOIN 引入 meta，无 order 的记录 CAST 后为 0；因此对未设置 order 的文章依旧稳定按 date 兜底。
 * 为了让"未设置 order 的文章"排到"有 order 的文章"之后，用 IS NULL 优先级排序。
 */
add_action('pre_get_posts', function ($q) {
    if (is_admin() || !$q->is_main_query()) return;
    if (!$q->is_tax('kratos_series')) return;
    // EXISTS OR NOT EXISTS 保证所有文章都进入结果集（不过滤掉未设置 order 的）
    $q->set('meta_query', array(
        'relation' => 'OR',
        'ordered'   => array('key' => 'kratos_series_order', 'compare' => 'EXISTS'),
        'unordered' => array('key' => 'kratos_series_order', 'compare' => 'NOT EXISTS'),
    ));
    $q->set('orderby', array('ordered' => 'DESC', 'meta_value_num' => 'ASC', 'date' => 'ASC'));
});

/**
 * 后台「所有文章」列表新增「系列」筛选下拉框
 */
add_action('restrict_manage_posts', function ($post_type) {
    if ($post_type !== 'post') return;
    $tax = get_taxonomy('kratos_series');
    if (!$tax) return;
    $selected = isset($_GET['kratos_series']) ? sanitize_text_field($_GET['kratos_series']) : '';
    wp_dropdown_categories(array(
        'show_option_all' => __('所有系列', 'kratos'),
        'taxonomy'        => 'kratos_series',
        'name'            => 'kratos_series',
        'orderby'         => 'name',
        'selected'        => $selected,
        'hierarchical'    => true,
        'depth'           => 3,
        'show_count'      => true,
        'hide_empty'      => false,
        'value_field'     => 'slug',
    ));
});

/**
 * 判断当前文章是否属于某个系列（模板中用于决定是否隐藏默认上下篇导航）
 */
function kratos_series_current_has_series($post_id = null)
{
    if (!kratos_option('g_series_enabled', true)) return false;
    $post_id = $post_id ?: get_the_ID();
    return (bool) kratos_series_get_current_term($post_id);
}
