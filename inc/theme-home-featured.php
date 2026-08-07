<?php

/**
 * 特色首页（Featured Home）
 *
 * 提供:
 *   - [home_featured] 短码：按后台编排的模块顺序渲染杂志式首页。
 *     七个模块：
 *       hero      焦点区   —— 1 篇大图主推 + 右侧若干次推
 *       recommend 推荐位   —— 三列图上文下卡片（置顶 / 指定分类 / 指定标签 / 手选 ID）
 *       category  分类专区 —— tab 切分类，每个 panel = 左侧特色文 + 右侧列表
 *       hot       热门榜   —— 按 views meta 排序，带序号徽章
 *       latest    最新文章 —— 缩略图 + 标题的紧凑列表
 *       comment   最近评论 —— 头像 + 评论摘要 + 所属文章的二/三列卡片
 *       stat      数据条   —— 文章 / 分类 / 标签 / 评论四项总数
 *     每个模块的标题、副标题、图标、数据来源、条数均可在后台配置；
 *     模块顺序与开关由 sorter 字段 hf_modules 决定。
 *   - 页面模板 page-home-featured.php（后台模板名「特色首页」），
 *     注意：本文件的注释里不能出现「Template Name」字面量 —— WordPress 会扫描
 *     主题目录下所有 PHP 文件的头部注释，命中即当成一个页面模板注册，
 *     会在后台模板下拉里多出一条以该行残留文字命名的假模板。
 *     并注入 body class `is-kratos-home-page`，让皮肤层豁免外层 .details 装饰。
 *
 * 视觉：markup 全程挂公共类 kr-hd / kr-hd-title / kr-hd-sub / kr-hd-divider /
 * kr-body / kr-card / kr-ico / kr-pill / kr-btn / kr-dot，配色走 --khs-*，
 * 因此 8 套每日皮肤零改动生效（详见 assets/css/home-featured.css 头注释）。
 *
 * 后台配置：主题选项 → 特色首页
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** 模块 slug → 后台展示名。顺序即 sorter 的默认启用顺序。 */
function kratos_home_module_labels()
{
    return array(
        'hero'      => __('焦点区', 'kratos'),
        'recommend' => __('推荐位', 'kratos'),
        'category'  => __('分类专区', 'kratos'),
        'hot'       => __('热门榜', 'kratos'),
        'latest'    => __('最新文章', 'kratos'),
        'comment'   => __('最近评论', 'kratos'),
        'stat'      => __('数据条', 'kratos'),
    );
}

/**
 * 把新增模块补进已保存的 hf_modules。
 *
 * sorter 字段只渲染数据库里已存在的条目（见 codestar-framework/fields/sorter），
 * 所以主题升级后新增的模块 slug 若不写回选项，后台「模块编排」里根本看不到它、
 * 也就无法启用。这里在进后台时把缺失的 slug 追加到「已启用」末尾（与全新安装
 * 默认全部启用保持一致），已有模块的顺序与启用状态不动。
 */
function kratos_home_sync_modules_option()
{
    $labels = kratos_home_module_labels();
    $opts   = get_option('kratos_options');
    if (!is_array($opts) || !isset($opts['hf_modules']) || !is_array($opts['hf_modules'])) {
        return; // 从未保存过 → kratos_home_enabled_modules() 会走 default，无需补
    }

    $value    = $opts['hf_modules'];
    $enabled  = isset($value['enabled']) && is_array($value['enabled']) ? $value['enabled'] : array();
    $disabled = isset($value['disabled']) && is_array($value['disabled']) ? $value['disabled'] : array();
    $known    = array_merge(array_keys($enabled), array_keys($disabled));

    $added = false;
    foreach ($labels as $slug => $label) {
        if (!in_array($slug, $known, true)) {
            $enabled[$slug] = $label;
            $added = true;
        }
    }
    if (!$added) {
        return;
    }

    $opts['hf_modules'] = array('enabled' => $enabled, 'disabled' => $disabled);
    update_option('kratos_options', $opts);
    kratos_home_flush_cache();
}
add_action('admin_init', 'kratos_home_sync_modules_option');

/**
 * 取当前启用的模块 slug 列表（按后台拖拽顺序）。
 * sorter 字段值形如 array('enabled' => array(slug => label), 'disabled' => ...)。
 *
 * @return string[]
 */
function kratos_home_enabled_modules()
{
    $labels = kratos_home_module_labels();
    $value  = kratos_option('hf_modules', array());

    if (!is_array($value) || empty($value['enabled']) || !is_array($value['enabled'])) {
        return array_keys($labels);
    }

    $out = array();
    foreach (array_keys($value['enabled']) as $slug) {
        if (isset($labels[$slug])) {
            $out[] = $slug;
        }
    }
    return $out;
}

/**
 * 渲染模块标题卡。标题为空则整个标题卡不输出。
 *
 * @param string $title    大标题
 * @param string $subtitle 副标题
 * @param string $icon     Font Awesome class（如 fas fa-fire）
 * @param string $more_url 右侧按钮链接，空则不显示按钮
 * @param string $more_txt 右侧按钮文案
 * @return string
 */
function kratos_home_header_html($title, $subtitle, $icon, $more_url = '', $more_txt = '')
{
    $title = trim((string) $title);
    if ($title === '') {
        return '';
    }

    $html  = '<div class="khf-hd kr-hd">';
    $html .= kratos_home_icon_html($icon);
    $html .= '<span class="khf-hd-title kr-hd-title">' . esc_html($title) . '</span>';
    if (trim((string) $subtitle) !== '') {
        $html .= '<span class="khf-hd-sub kr-hd-sub">' . esc_html($subtitle) . '</span>';
    }
    $html .= '<span class="khf-hd-divider kr-hd-divider"></span>';
    if ($more_url !== '' && $more_txt !== '') {
        $html .= '<a class="khf-hd-more kr-btn" href="' . esc_url($more_url) . '">' . esc_html($more_txt) . '</a>';
    }
    $html .= '</div>';
    return $html;
}

/** 图标胶囊。图标为空则不输出（保持标题卡左对齐）。 */
function kratos_home_icon_html($icon)
{
    $icon = trim((string) $icon);
    if ($icon === '') {
        return '';
    }
    return '<span class="khf-ico kr-ico"><i class="' . esc_attr($icon) . '"></i></span>';
}

/**
 * 特色首页专用的两档大图尺寸。
 *
 * 主题原有的 kratos-thumbnail 只有 512×288，用来渲染焦点区大图（渲染宽度可达
 * ~700px，2x 屏 ~1400px）必然被放大到糊；三档缩略图此前都取这一个尺寸，所以
 * 越大的位置越模糊。这里补两档，小图（列表 mini 78×56）继续复用 512 的那档。
 *
 * 注意：新注册的尺寸只对「注册之后上传」的图片自动生成。升级前已入库的图片没有
 * 对应文件，wp_get_attachment_image() 会回落到原图 —— 视觉上不糊（反而更清晰），
 * 只是体积偏大；站长可用「Regenerate Thumbnails」类插件补齐后恢复最优体积。
 */
function kratos_home_register_image_sizes()
{
    add_image_size('kratos-home-lg', 1280, 720, true); // 焦点区主推大图（16:9）
    add_image_size('kratos-home-md', 760, 475, true);  // 推荐位卡片 / 分类专区特色文（16:10）
}
add_action('after_setup_theme', 'kratos_home_register_image_sizes');

/**
 * 缩略图 HTML。
 * 优先特色图（走 wp_get_attachment_image 以命中 LQIP / attributes filter），
 * 其次正文首图，再次文字占位图 / 默认图 —— 与 post_thumbnail() 的回落顺序一致。
 *
 * @param WP_Post $post
 * @param string  $size 注册过的图片尺寸名，见 kratos_home_register_image_sizes()
 * @return string
 */
function kratos_home_thumb_html($post, $size = 'kratos-thumbnail')
{
    if (!is_a($post, 'WP_Post')) {
        return '';
    }

    if (has_post_thumbnail($post)) {
        return wp_get_attachment_image(get_post_thumbnail_id($post), $size, false, array(
            'alt' => esc_attr(get_the_title($post)),
        ));
    }

    if (preg_match('/<img (.*?)src="(.+?)".*?>/', (string) $post->post_content, $m) && !empty($m[2])) {
        return '<img src="' . esc_url($m[2]) . '" alt="' . esc_attr(get_the_title($post)) . '" loading="lazy" />';
    }

    if (function_exists('kratos_default_thumb_is_text_mode') && kratos_default_thumb_is_text_mode()) {
        return kratos_default_thumb_html($post);
    }

    return '<img src="' . esc_url(kratos_option('g_postthumbnail', ASSET_PATH . '/assets/img/default.jpg'))
        . '" alt="' . esc_attr(get_the_title($post)) . '" loading="lazy" />';
}

/**
 * 缩略图链接块（<a> 包 <span class="khf-thumb">）。
 *
 * @param WP_Post $post
 * @param string  $extra_class 追加到 .khf-thumb 上的类（如 khf-thumb-mini）
 * @param string  $size        图片尺寸档：kratos-home-lg / -md / kratos-thumbnail
 */
function kratos_home_thumb_link($post, $extra_class = '', $size = 'kratos-thumbnail')
{
    $cls = 'khf-thumb' . ($extra_class !== '' ? ' ' . $extra_class : '');
    return '<a class="khf-thumb-link" href="' . esc_url(get_permalink($post)) . '" title="' . esc_attr(get_the_title($post)) . '">'
        . '<span class="' . esc_attr($cls) . '">' . kratos_home_thumb_html($post, $size) . '</span></a>';
}

/** 摘要（去短码 / 去 HTML，按字数截断）。 */
function kratos_home_excerpt($post, $words = 60)
{
    $text = has_excerpt($post) ? get_the_excerpt($post) : strip_shortcodes((string) $post->post_content);
    $text = wp_strip_all_tags($text);
    return wp_trim_words($text, $words, '…');
}

/** 文章热度（views meta）。 */
function kratos_home_views($post_id)
{
    return (int) get_post_meta($post_id, 'views', true);
}

/** 首个分类名 + 链接，无分类返回空数组。 */
function kratos_home_primary_cat($post_id)
{
    $cats = get_the_category($post_id);
    if (empty($cats) || !is_array($cats)) {
        return array();
    }
    return array('name' => $cats[0]->name, 'url' => get_category_link($cats[0]->term_id));
}

/** 元信息行：分类 · 日期 · 热度（按需拼装，用 · 分隔）。 */
function kratos_home_meta_html($post, $show = array('cat', 'date', 'views'))
{
    $parts = array();

    if (in_array('cat', $show, true)) {
        $cat = kratos_home_primary_cat($post->ID);
        if (!empty($cat)) {
            $parts[] = '<a href="' . esc_url($cat['url']) . '">' . esc_html($cat['name']) . '</a>';
        }
    }
    if (in_array('date', $show, true)) {
        $parts[] = '<span>' . esc_html(get_the_date(get_option('date_format'), $post)) . '</span>';
    }
    if (in_array('short_date', $show, true)) {
        $parts[] = '<span>' . esc_html(get_the_date('m-d', $post)) . '</span>';
    }
    if (in_array('views', $show, true)) {
        $parts[] = '<span>' . sprintf(esc_html__('热度 %s', 'kratos'), number_format_i18n(kratos_home_views($post->ID))) . '</span>';
    }
    if (in_array('comments', $show, true)) {
        $parts[] = '<span>' . sprintf(esc_html__('%s 评论', 'kratos'), number_format_i18n((int) $post->comment_count)) . '</span>';
    }

    if (empty($parts)) {
        return '';
    }
    return '<div class="khf-meta">' . implode('<i class="khf-sep">·</i>', $parts) . '</div>';
}

/** 把「1,2,3」形式的 ID 串解析成 int[]。 */
function kratos_home_parse_ids($raw)
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map('intval', $raw)));
    }
    $arr = array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen');
    return array_values(array_filter(array_map('intval', $arr)));
}

/** 通用查询封装：只取需要的字段，关闭分页计数。 */
function kratos_home_query($args)
{
    $args = array_merge(array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
        'posts_per_page'      => 5,
    ), $args);
    return get_posts($args);
}

/**
 * 置顶文章（不足时用最新文章补齐）。
 *
 * @param int   $count
 * @param int[] $exclude
 * @return WP_Post[]
 */
function kratos_home_sticky_posts($count, $exclude = array())
{
    $out    = array();
    $sticky = array_diff((array) get_option('sticky_posts'), $exclude);

    if (!empty($sticky)) {
        $out = kratos_home_query(array(
            'post__in'       => array_map('intval', $sticky),
            'orderby'        => 'post__in',
            'posts_per_page' => $count,
        ));
    }

    if (count($out) < $count) {
        $have = array_merge($exclude, wp_list_pluck($out, 'ID'));
        $fill = kratos_home_query(array(
            'post__not_in'   => $have,
            'posts_per_page' => $count - count($out),
        ));
        $out = array_merge($out, $fill);
    }

    return $out;
}

/* =========================================================================
 * 模块 1：hero 焦点区
 * ========================================================================= */

function kratos_home_render_hero()
{
    $side_count = max(0, (int) kratos_option('hf_hero_side_count', 2));
    $source     = (string) kratos_option('hf_hero_source', 'sticky');
    $total      = 1 + $side_count;

    $posts = $source === 'sticky'
        ? kratos_home_sticky_posts($total)
        : kratos_home_query(array('posts_per_page' => $total));

    if (empty($posts)) {
        return '';
    }

    $main = array_shift($posts);

    $html  = '<section class="khf-module khf-module-hero">';
    $html .= kratos_home_header_html(
        kratos_option('hf_hero_title', ''),
        kratos_option('hf_hero_sub', ''),
        kratos_option('hf_hero_icon', 'fas fa-star')
    );
    $html .= '<div class="khf-hero">';

    $html .= '<article class="khf-hero-main kr-card">';
    // 焦点区主推：整站最大的图位（16:9，宽度可达容器的 ~60%），必须走 lg 档
    $html .= kratos_home_thumb_link($main, '', 'kratos-home-lg');
    $html .= '<div class="khf-hero-body">';
    if (is_sticky($main->ID)) {
        $html .= '<div class="khf-meta"><span class="khf-pill kr-pill">' . esc_html__('置顶', 'kratos') . '</span></div>';
    }
    $html .= kratos_home_meta_html($main, array('cat', 'date', 'views'));
    $html .= '<a href="' . esc_url(get_permalink($main)) . '"><h2 class="khf-hero-title">' . esc_html(get_the_title($main)) . '</h2></a>';
    $html .= '<p class="khf-hero-excerpt">' . esc_html(kratos_home_excerpt($main, 70)) . '</p>';
    $html .= '</div></article>';

    if (!empty($posts)) {
        $html .= '<div class="khf-hero-side">';
        foreach ($posts as $p) {
            $html .= '<article class="khf-hero-sub kr-card">';
            $html .= kratos_home_thumb_link($p);
            $html .= '<div class="khf-hero-sub-main">';
            $html .= '<a href="' . esc_url(get_permalink($p)) . '"><h3 class="khf-hero-sub-title">' . esc_html(get_the_title($p)) . '</h3></a>';
            $html .= kratos_home_meta_html($p, array('cat', 'short_date'));
            $html .= '</div></article>';
        }
        $html .= '</div>';
    }

    $html .= '</div></section>';
    return $html;
}

/* =========================================================================
 * 模块 2：recommend 推荐位
 * ========================================================================= */

function kratos_home_render_recommend()
{
    $count  = max(1, (int) kratos_option('hf_rec_count', 3));
    $source = (string) kratos_option('hf_rec_source', 'sticky');

    if ($source === 'cat') {
        // 'cat' 传逗号串时 WP_Query 走 OR 且默认含子分类，与模块三的取文方式一致
        $cats  = kratos_home_parse_ids(kratos_option('hf_rec_cats', array()));
        $posts = empty($cats) ? array() : kratos_home_query(array(
            'cat'            => implode(',', $cats),
            'posts_per_page' => $count,
        ));
    } elseif ($source === 'tag') {
        $tag = trim((string) kratos_option('hf_rec_tag', ''));
        $posts = $tag === '' ? array() : kratos_home_query(array(
            'tag'            => $tag,
            'posts_per_page' => $count,
        ));
    } elseif ($source === 'ids') {
        $ids = kratos_home_parse_ids(kratos_option('hf_rec_ids', ''));
        $posts = empty($ids) ? array() : kratos_home_query(array(
            'post__in'       => $ids,
            'orderby'        => 'post__in',
            'posts_per_page' => $count,
        ));
    } else {
        $posts = kratos_home_sticky_posts($count);
    }

    $html  = '<section class="khf-module khf-module-recommend">';
    $html .= kratos_home_header_html(
        kratos_option('hf_rec_title', __('编辑推荐', 'kratos')),
        kratos_option('hf_rec_sub', __('值得一读的长文', 'kratos')),
        kratos_option('hf_rec_icon', 'fas fa-thumbtack'),
        (string) kratos_option('hf_rec_more_url', ''),
        __('全部推荐', 'kratos')
    );

    if (empty($posts)) {
        return $html . '<div class="khf-empty kr-card">' . esc_html__('暂无推荐文章', 'kratos') . '</div></section>';
    }

    $html .= '<div class="khf-grid3">';
    foreach ($posts as $p) {
        $html .= '<article class="khf-rec kr-card">';
        $html .= kratos_home_thumb_link($p, '', 'kratos-home-md');
        $html .= '<div class="khf-rec-body">';
        $html .= kratos_home_meta_html($p, array('cat', 'short_date'));
        $html .= '<a href="' . esc_url(get_permalink($p)) . '"><h3 class="khf-rec-title">' . esc_html(get_the_title($p)) . '</h3></a>';
        $html .= '<p class="khf-rec-excerpt">' . esc_html(kratos_home_excerpt($p, 50)) . '</p>';
        $html .= kratos_home_meta_html($p, array('views', 'comments'));
        $html .= '</div></article>';
    }
    $html .= '</div></section>';
    return $html;
}

/* =========================================================================
 * 模块 3：category 分类专区（tab 切换）
 * ========================================================================= */

function kratos_home_render_category()
{
    $term_ids = kratos_home_parse_ids(kratos_option('hf_cat_terms', array()));
    $count    = max(1, (int) kratos_option('hf_cat_count', 5));
    $feature  = (bool) kratos_option('hf_cat_feature', true);

    // 未指定分类时，自动取文章数最多的前 3 个分类
    if (empty($term_ids)) {
        $auto = get_categories(array('orderby' => 'count', 'order' => 'DESC', 'number' => 3, 'hide_empty' => true));
        $term_ids = is_array($auto) ? wp_list_pluck($auto, 'term_id') : array();
    }
    if (empty($term_ids)) {
        return '';
    }

    $uid   = wp_unique_id('khf-cat-');
    $tabs  = '';
    $panes = '';
    $i     = 0;

    foreach ($term_ids as $term_id) {
        $term = get_term($term_id, 'category');
        if (!$term || is_wp_error($term)) {
            continue;
        }

        $posts = kratos_home_query(array(
            'cat'            => (int) $term_id,
            'posts_per_page' => $feature ? $count + 1 : $count,
        ));
        if (empty($posts)) {
            continue;
        }

        $active  = $i === 0;
        $pane_id = $uid . '-' . (int) $term_id;

        $tabs .= '<button type="button" class="khf-tab' . ($active ? ' is-active' : '') . '"'
            . ' role="tab" aria-selected="' . ($active ? 'true' : 'false') . '"'
            . ' data-panel="' . esc_attr($pane_id) . '">' . esc_html($term->name) . '</button>';

        // --khf-cat-rows 传「设定条数」而非实际条数：文章数不足的分类也按满格留白，
        // 切 tab 时面板高度不跳（CSS 用它算 .khf-list 的 min-height）。
        $pane  = '<div class="khf-cat-panel' . ($active ? ' is-active' : '') . '" role="tabpanel"'
            . ' data-panel-id="' . esc_attr($pane_id) . '"'
            . ' style="--khf-cat-rows:' . (int) $count . '">';
        $pane .= '<div class="khf-cat-layout">';

        if ($feature) {
            $lead  = array_shift($posts);
            $pane .= '<article class="khf-cat-feature">';
            $pane .= kratos_home_thumb_link($lead, '', 'kratos-home-md');
            $pane .= kratos_home_meta_html($lead, array('short_date', 'views'));
            $pane .= '<a href="' . esc_url(get_permalink($lead)) . '"><h3 class="khf-cat-feature-title">' . esc_html(get_the_title($lead)) . '</h3></a>';
            $pane .= '<p class="khf-cat-feature-excerpt">' . esc_html(kratos_home_excerpt($lead, 50)) . '</p>';
            $pane .= '</article>';
        }

        $pane .= '<div class="khf-list">';
        foreach ($posts as $p) {
            $pane .= '<a class="khf-list-item" href="' . esc_url(get_permalink($p)) . '">'
                . '<span class="khf-dot kr-dot"></span>'
                . '<span class="khf-list-title">' . esc_html(get_the_title($p)) . '</span>'
                . '<span class="khf-list-date">' . esc_html(get_the_date('m-d', $p)) . '</span></a>';
        }
        $pane .= '</div></div>';

        // 分类归档入口
        $pane .= '<div class="khf-cat-foot"><a class="kr-btn" href="' . esc_url(get_category_link($term_id)) . '">'
            . sprintf(esc_html__('查看「%s」全部文章 →', 'kratos'), esc_html($term->name)) . '</a></div>';
        $pane .= '</div>';

        $panes .= $pane;
        $i++;
    }

    if ($tabs === '') {
        return '';
    }

    $title = (string) kratos_option('hf_cat_title', __('分类专区', 'kratos'));
    $sub   = (string) kratos_option('hf_cat_sub', '');

    $html  = '<section class="khf-module khf-module-category">';
    $html .= '<div class="khf-cat kr-card kr-body">';
    $html .= '<div class="khf-cat-head kr-hd">';
    $html .= kratos_home_icon_html(kratos_option('hf_cat_icon', 'fas fa-layer-group'));
    if (trim($title) !== '') {
        $html .= '<span class="khf-hd-title kr-hd-title">' . esc_html($title) . '</span>';
    }
    if (trim($sub) !== '') {
        $html .= '<span class="khf-hd-sub kr-hd-sub">' . esc_html($sub) . '</span>';
    }
    $html .= '<div class="khf-cat-tabs" role="tablist">' . $tabs . '</div>';
    $html .= '</div>' . $panes . '</div></section>';
    return $html;
}

/* =========================================================================
 * 模块 4：hot 热门榜
 * ========================================================================= */

/**
 * 按热度取文章。没有 views meta 的文章排不进 meta 排序，
 * 因此不足条数时用最新文章补齐，避免新站首页热榜空一片。
 */
function kratos_home_hot_posts($count, $days)
{
    $args = array(
        'posts_per_page' => $count,
        'meta_key'       => 'views',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
    );
    if ($days > 0) {
        $args['date_query'] = array(array('after' => $days . ' days ago'));
    }
    $posts = kratos_home_query($args);

    if (count($posts) < $count) {
        $fill = kratos_home_query(array(
            'posts_per_page' => $count - count($posts),
            'post__not_in'   => wp_list_pluck($posts, 'ID'),
        ));
        $posts = array_merge($posts, $fill);
    }
    return $posts;
}

function kratos_home_render_hot()
{
    $count = max(1, (int) kratos_option('hf_hot_count', 5));
    $days  = max(0, (int) kratos_option('hf_hot_days', 30));
    $posts = kratos_home_hot_posts($count, $days);

    if (empty($posts)) {
        return '';
    }

    $html  = '<div class="khf-col khf-col-hot">';
    $html .= kratos_home_header_html(
        kratos_option('hf_hot_title', __('热门榜', 'kratos')),
        kratos_option('hf_hot_sub', $days > 0 ? sprintf(__('近 %d 天热度', 'kratos'), $days) : __('全站热度', 'kratos')),
        kratos_option('hf_hot_icon', 'fas fa-fire')
    );

    $show_thumb = (bool) kratos_option('hf_hot_thumb', false);

    $rank = 0;
    foreach ($posts as $p) {
        $rank++;
        $html .= '<article class="khf-hot-item kr-card' . ($rank <= 3 ? ' is-top' : '') . '">';
        $html .= '<span class="khf-rank">' . esc_html(number_format_i18n($rank)) . '</span>';
        $html .= '<div class="khf-hot-main">';
        $html .= '<a href="' . esc_url(get_permalink($p)) . '"><div class="khf-hot-title">' . esc_html(get_the_title($p)) . '</div></a>';
        $html .= kratos_home_meta_html($p, array('cat', 'views', 'comments'));
        $html .= '</div>';
        // 缩略图排在标题右侧（DOM 顺序即视觉顺序，无需 order 反转）
        if ($show_thumb) {
            $html .= kratos_home_thumb_link($p, 'khf-thumb-mini');
        }
        $html .= '</article>';
    }

    $more = trim((string) kratos_option('hf_hot_more_url', ''));
    if ($more !== '') {
        $html .= '<a class="khf-more kr-btn" href="' . esc_url($more) . '">' . esc_html__('查看完整热榜 →', 'kratos') . '</a>';
    }

    return $html . '</div>';
}

/* =========================================================================
 * 模块 5：latest 最新文章
 * ========================================================================= */

function kratos_home_render_latest()
{
    $count = max(1, (int) kratos_option('hf_latest_count', 5));
    $posts = kratos_home_query(array('posts_per_page' => $count));

    if (empty($posts)) {
        return '';
    }

    $html  = '<div class="khf-col khf-col-latest">';
    $html .= kratos_home_header_html(
        kratos_option('hf_latest_title', __('最新文章', 'kratos')),
        kratos_option('hf_latest_sub', __('刚刚更新', 'kratos')),
        kratos_option('hf_latest_icon', 'fas fa-pen-nib')
    );

    $show_thumb = (bool) kratos_option('hf_latest_thumb', true);

    foreach ($posts as $p) {
        $html .= '<article class="khf-latest-item kr-card' . ($show_thumb ? '' : ' is-nothumb') . '">';
        if ($show_thumb) {
            $html .= kratos_home_thumb_link($p, 'khf-thumb-mini');
        }
        $html .= '<div class="khf-latest-main">';
        $html .= '<a href="' . esc_url(get_permalink($p)) . '"><div class="khf-latest-title">' . esc_html(get_the_title($p)) . '</div></a>';
        $html .= kratos_home_meta_html($p, array('cat', 'short_date'));
        $html .= '</div></article>';
    }

    // 「进入文章列表」：优先后台填的地址，否则指向「文章页」（设置 → 阅读），最后回落首页
    $more = trim((string) kratos_option('hf_latest_more_url', ''));
    if ($more === '') {
        $page_for_posts = (int) get_option('page_for_posts');
        $more = $page_for_posts ? (string) get_permalink($page_for_posts) : home_url('/');
    }
    $html .= '<a class="khf-more kr-btn" href="' . esc_url($more) . '">' . esc_html__('进入文章列表 →', 'kratos') . '</a>';

    return $html . '</div>';
}

/* =========================================================================
 * 模块 6：comment 最近评论
 * ========================================================================= */

/**
 * 最近的已通过评论。
 * 排除 pingback / trackback，排除挂在非公开文章下的评论（避免泄露草稿/私密内容）。
 *
 * @param int  $count
 * @param bool $skip_admin 是否排除具备 manage_options 权限用户（博主）的评论
 * @return WP_Comment[]
 */
function kratos_home_recent_comments($count, $skip_admin = false)
{
    $args = array(
        'status'      => 'approve',
        'type'        => 'comment',
        'post_status' => 'publish',
        'post_type'   => 'post',
        'orderby'     => 'comment_date_gmt',
        'order'       => 'DESC',
        'number'      => $count,
    );

    if ($skip_admin) {
        // 博主可能有多个管理员账号，全部排除；匿名评论（user_id = 0）不受影响
        $admins = get_users(array('role' => 'administrator', 'fields' => 'ID'));
        if (!empty($admins)) {
            $args['author__not_in'] = array_map('intval', $admins);
        }
    }

    $comments = get_comments($args);
    return is_array($comments) ? $comments : array();
}

function kratos_home_render_comment()
{
    $count   = max(1, (int) kratos_option('hf_cmt_count', 6));
    $words   = max(5, (int) kratos_option('hf_cmt_words', 20));
    $cols    = (int) kratos_option('hf_cmt_cols', 2) === 3 ? 3 : 2;
    $avatar  = (bool) kratos_option('hf_cmt_avatar', true);
    $comments = kratos_home_recent_comments($count, (bool) kratos_option('hf_cmt_skip_admin', false));

    $html  = '<section class="khf-module khf-module-comment">';
    $html .= kratos_home_header_html(
        kratos_option('hf_cmt_title', __('最近评论', 'kratos')),
        kratos_option('hf_cmt_sub', __('大家正在聊', 'kratos')),
        kratos_option('hf_cmt_icon', 'fas fa-comment-dots'),
        (string) kratos_option('hf_cmt_more_url', ''),
        __('全部评论', 'kratos')
    );

    if (empty($comments)) {
        return $html . '<div class="khf-empty kr-card">' . esc_html__('暂无评论', 'kratos') . '</div></section>';
    }

    $html .= '<div class="khf-cmts khf-cmts-' . $cols . '">';
    foreach ($comments as $c) {
        // 表情是 WP smilies 形式的 :name: 文本码（见 inc/theme-article.php 的
        // smilies_reset()），评论列表靠 comment_text 上的 convert_smilies() 渲染，
        // 这里读的是原始 comment_content，必须自己转一次否则只剩字面量 :smile:。
        // 顺序：先截断纯文本 → 再 esc_html → 最后 convert_smilies 输出 <img>，
        // 所以 $text 已是安全 HTML，下面直接拼接不能再 esc_html。
        // （shortcode 只含 `:`/字母数字/`_`/`-`，不会被 esc_html 破坏匹配。）
        $text = wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) $c->comment_content)), $words, '…');
        $text = convert_smilies(esc_html($text));
        $link = get_comment_link($c);

        $html .= '<article class="khf-cmt kr-card">';
        if ($avatar) {
            $html .= '<span class="khf-cmt-avatar">' . get_avatar($c, 80, '', esc_attr($c->comment_author)) . '</span>';
        }
        $html .= '<div class="khf-cmt-main">';
        $html .= '<div class="khf-cmt-head">';
        // 用 get_comment_author_link() 而非 get_comment_author()：等级徽章、走心徽章、
        // 友链徽章都挂在 get_comment_author_link filter 上（优先级 10 / 11 / 12，
        // 见 theme-comment-rank / -heart / -link.php），取纯文本作者名就全丢了。
        // 该函数把 comment_ID 作为第三个参数传给 filter，无需依赖 $GLOBALS['comment']。
        // 返回值是 WP 已转义的 HTML，不能再 esc_html。
        $html .= '<span class="khf-cmt-author">' . get_comment_author_link($c) . '</span>';
        // 两端都用 GMT 时间戳做差，避免站点时区与 UTC 混算出现「0 分钟前」或负值
        $html .= '<span class="khf-cmt-time">' . esc_html(sprintf(
            /* translators: %s: 人类可读的时间差，如「3 天」 */
            __('%s前', 'kratos'),
            human_time_diff((int) strtotime($c->comment_date_gmt . ' GMT'), time())
        )) . '</span>';
        $html .= '</div>';
        $html .= '<a class="khf-cmt-text" href="' . esc_url($link) . '">' . $text . '</a>';
        $html .= '<div class="khf-meta"><a href="' . esc_url($link) . '">'
            . esc_html(sprintf(__('评论于《%s》', 'kratos'), get_the_title((int) $c->comment_post_ID)))
            . '</a></div>';
        $html .= '</div></article>';
    }
    $html .= '</div></section>';
    return $html;
}

/* =========================================================================
 * 模块 7：stat 数据条
 * ========================================================================= */

function kratos_home_render_stat()
{
    $posts = wp_count_posts('post');
    $items = array(
        array(
            'icon'  => (string) kratos_option('hf_stat_icon_post', 'fas fa-pen-fancy'),
            'num'   => isset($posts->publish) ? (int) $posts->publish : 0,
            'label' => __('篇文章', 'kratos'),
        ),
        array(
            'icon'  => (string) kratos_option('hf_stat_icon_cat', 'fas fa-folder-open'),
            'num'   => (int) wp_count_terms(array('taxonomy' => 'category', 'hide_empty' => true)),
            'label' => __('个分类', 'kratos'),
        ),
        array(
            'icon'  => (string) kratos_option('hf_stat_icon_tag', 'fas fa-tags'),
            'num'   => (int) wp_count_terms(array('taxonomy' => 'post_tag', 'hide_empty' => true)),
            'label' => __('个标签', 'kratos'),
        ),
        array(
            'icon'  => (string) kratos_option('hf_stat_icon_comment', 'fas fa-comments'),
            'num'   => (int) wp_count_comments()->approved,
            'label' => __('条评论', 'kratos'),
        ),
    );

    $html  = '<section class="khf-module khf-module-stat">';
    $html .= kratos_home_header_html(
        kratos_option('hf_stat_title', ''),
        kratos_option('hf_stat_sub', ''),
        kratos_option('hf_stat_icon', 'fas fa-chart-simple')
    );
    $html .= '<div class="khf-stats">';
    foreach ($items as $it) {
        $html .= '<div class="khf-stat kr-card">' . kratos_home_icon_html($it['icon'])
            . '<div class="khf-stat-main">'
            . '<div class="khf-stat-num">' . esc_html(number_format_i18n($it['num'])) . '</div>'
            . '<div class="khf-stat-label">' . esc_html($it['label']) . '</div>'
            . '</div></div>';
    }
    $html .= '</div></section>';
    return $html;
}

/* =========================================================================
 * 短码入口 + 缓存
 * ========================================================================= */

/** 单个模块渲染分发。hot / latest 返回的是列，由外层拼成双栏。 */
function kratos_home_render_module($slug)
{
    switch ($slug) {
        case 'hero':
            return kratos_home_render_hero();
        case 'recommend':
            return kratos_home_render_recommend();
        case 'category':
            return kratos_home_render_category();
        case 'hot':
            return kratos_home_render_hot();
        case 'latest':
            return kratos_home_render_latest();
        case 'comment':
            return kratos_home_render_comment();
        case 'stat':
            return kratos_home_render_stat();
    }
    return '';
}

/**
 * 拼装全部模块。
 * hot 与 latest 若相邻启用，合并为一行双栏（.khf-two）；只启用其中之一时独立成段。
 */
function kratos_home_render_all()
{
    $modules = kratos_home_enabled_modules();
    $html    = '';

    for ($i = 0; $i < count($modules); $i++) {
        $slug = $modules[$i];

        if (($slug === 'hot' || $slug === 'latest') && isset($modules[$i + 1])
            && in_array($modules[$i + 1], array('hot', 'latest'), true)) {
            $pair = kratos_home_render_module($slug) . kratos_home_render_module($modules[$i + 1]);
            if (trim($pair) !== '') {
                $html .= '<section class="khf-module khf-module-two"><div class="khf-two">' . $pair . '</div></section>';
            }
            $i++; // 跳过已消费的下一个模块
            continue;
        }

        $part = kratos_home_render_module($slug);
        if (trim($part) === '') {
            continue;
        }
        if ($slug === 'hot' || $slug === 'latest') {
            $part = '<section class="khf-module khf-module-two"><div class="khf-two khf-two-single">' . $part . '</div></section>';
        }
        $html .= $part;
    }

    return $html === '' ? '' : '<div class="kratos-home">' . $html . '</div>';
}

/** [home_featured] 短码。 */
function kratos_home_featured_shortcode($atts = array())
{
    if (!kratos_option('hf_enabled', true)) {
        return '';
    }

    $minutes = max(0, (int) kratos_option('hf_cache_minutes', 10));
    // 登录用户不吃缓存：站长改完文章要能立刻看到效果
    $use_cache = $minutes > 0 && !is_user_logged_in();
    $key       = 'kratos_hf_html';

    if ($use_cache) {
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
    }

    $html = kratos_home_render_all();

    if ($use_cache && $html !== '') {
        set_transient($key, $html, $minutes * MINUTE_IN_SECONDS);
    }

    return $html;
}
add_shortcode('home_featured', 'kratos_home_featured_shortcode');

/** 清缓存：文章、分类标签、评论、主题选项变动都会影响首页内容。 */
function kratos_home_flush_cache()
{
    delete_transient('kratos_hf_html');
}
add_action('save_post', 'kratos_home_flush_cache');
add_action('deleted_post', 'kratos_home_flush_cache');
add_action('edited_term', 'kratos_home_flush_cache');
add_action('delete_term', 'kratos_home_flush_cache');
add_action('wp_insert_comment', 'kratos_home_flush_cache');
add_action('transition_comment_status', 'kratos_home_flush_cache');
add_action('csf_kratos_options_saved', 'kratos_home_flush_cache');

/* =========================================================================
 * 资源加载 + body class
 * ========================================================================= */

/** 当前请求是否用到特色首页（模板命中或正文含短码）。 */
function kratos_home_is_active()
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-home-featured.php')) {
        return true;
    }
    global $post;
    return is_a($post, 'WP_Post') && has_shortcode((string) $post->post_content, 'home_featured');
}

function kratos_home_enqueue_assets()
{
    if (!kratos_option('hf_enabled', true) || !kratos_home_is_active()) {
        return;
    }

    // 模块图标用 Font Awesome；主题「Font Awesome」开关未开时也要保证图标可见。
    // 句柄与 theme-core.php 一致，重复入队会被 WP 去重。
    wp_enqueue_style('fontawesome', ASSET_PATH . '/assets/css/fontawesome.min.css', array(), '5.15.2');

    wp_enqueue_style('kratos-home-featured', ASSET_PATH . '/assets/css/home-featured.css', array('kratos-components'), THEME_VERSION);
    wp_enqueue_script('kratos-home-featured', ASSET_PATH . '/assets/js/home-featured.js', array(), THEME_VERSION, true);
}
add_action('wp_enqueue_scripts', 'kratos_home_enqueue_assets');

/**
 * 注入 body class `is-kratos-home-page`，让皮肤层豁免外层 .details 的
 * 背景/边框/阴影/内边距，避免模块卡片再套一层卡（「卡中卡」）。
 */
function kratos_home_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-home-featured.php')) {
        $classes[] = 'is-kratos-home-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_home_body_class');
