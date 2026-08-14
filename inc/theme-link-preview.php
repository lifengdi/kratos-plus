<?php

/**
 * 内链悬浮预览卡
 *
 * 正文里指向本站的链接，鼠标悬停 N 毫秒后弹出一张预览卡。支持两类目标：
 *   - **文章 / 页面**：缩略图 / 标题 / 摘要 / 日期 / 字数与阅读时长 / 评论数
 *   - **分类 / 标签 / 系列归档**：归档名 / 分类法徽章 / 描述 / 文章数 / 最近几篇
 *     （关键词自动内链 theme-auto-link.php 产出的正是这类链接，两者天然配套）
 *
 * 设计要点：
 *   - 解析在服务端：REST `GET /wp-json/kratos/v1/link-preview?url=...`
 *     文章走 url_to_postid()；归档走一份「链接路径 → term」的映射表（见
 *     kratos_lpv_term_map()）—— 核心没有 url_to_term()，逐个 get_term_link()
 *     比对又是 O(n)，所以建一次映射缓存起来，之后都是 O(1) 命中
 *   - 避免在 the_content 上再加一层正则改写 markup（正文已经被 auto-link /
 *     图片包裹 / 代码高亮等多个 filter 处理过，再插一手容易打烂结构）
 *   - 每篇文章 / 每个归档的预览载荷各自写 transient，命中即免查
 *   - **静默加载**：请求期间不显示任何「加载中」占位，数据到手才淡入卡片；
 *     鼠标已离开或请求失败则什么都不发生
 *   - 只在指针设备（hover: hover + pointer: fine）上启用，触屏不拦截点击
 *   - 只在 is_singular() 且开关打开时入队资源
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** 是否启用。 */
function kratos_lpv_enabled()
{
    return (bool) kratos_option('g_link_preview', true);
}

/** 单篇预览载荷的 transient key。 */
function kratos_lpv_cache_key($post_id)
{
    // 版本后缀：载荷结构变化（如 v2 加入 catUrl）时换 key，旧缓存自然失效
    return 'kratos_lpv_v2_' . (int) $post_id;
}

/** 文章更新时清掉自己的预览缓存。 */
function kratos_lpv_flush_post_cache($post_id)
{
    delete_transient(kratos_lpv_cache_key($post_id));
}
add_action('save_post', 'kratos_lpv_flush_post_cache');
add_action('deleted_post', 'kratos_lpv_flush_post_cache');

/**
 * 组装单篇预览载荷。
 *
 * @param int $post_id
 * @return array|null 不可预览（草稿/私密/非公开类型）时返回 null
 */
function kratos_lpv_build_payload($post_id)
{
    $post_id = (int) $post_id;
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        return null;
    }
    if (post_password_required($post)) {
        return null;
    }
    $pt = get_post_type_object($post->post_type);
    if (!$pt || empty($pt->public)) {
        return null;
    }

    $cached = get_transient(kratos_lpv_cache_key($post_id));
    if (is_array($cached)) {
        return $cached;
    }

    $excerpt_len = max(20, (int) kratos_option('g_link_preview_excerpt', 110));

    $raw = has_excerpt($post_id) ? get_the_excerpt($post_id) : $post->post_content;
    $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($raw))));
    if (mb_strlen($text) > $excerpt_len) {
        $text = mb_substr($text, 0, $excerpt_len) . '…';
    }

    $words = function_exists('kratos_read_word_count')
        ? (int) kratos_read_word_count($post->post_content)
        : 0;
    // 阅读速度与阅读增强模块保持一致的量级：中文 ~400 字/分钟
    $wpm = max(50, (int) kratos_option('g_link_preview_wpm', 400));
    $minutes = $words > 0 ? max(1, (int) ceil($words / $wpm)) : 0;

    $cats = get_the_category($post_id);
    // 分类名在卡片里要能点进归档，所以顺手带上链接（取不到时留空，前端退回纯文本）
    $cat_url = '';
    if (!empty($cats)) {
        $link = get_category_link($cats[0]->term_id);
        if (!is_wp_error($link)) {
            $cat_url = (string) $link;
        }
    }

    $payload = array(
        'found'    => true,
        'kind'     => 'post',
        'id'       => $post_id,
        'title'    => get_the_title($post_id),
        'excerpt'  => $text,
        'url'      => get_permalink($post_id),
        'date'     => get_the_date('Y-m-d', $post_id),
        'thumb'    => (string) get_the_post_thumbnail_url($post_id, 'kratos-thumbnail'),
        'comments' => (int) get_comments_number($post_id),
        'words'    => $words,
        'minutes'  => $minutes,
        'cat'      => !empty($cats) ? $cats[0]->name : '',
        'catUrl'   => $cat_url,
    );

    $ttl = max(5, (int) kratos_option('g_link_preview_cache_min', 360)) * MINUTE_IN_SECONDS;
    set_transient(kratos_lpv_cache_key($post_id), $payload, $ttl);

    return $payload;
}

/* ============================================================
 *  归档（分类 / 标签 / 系列）预览
 * ============================================================ */

/** 参与归档预览的分类法。 */
function kratos_lpv_taxonomies()
{
    $tax = array('category', 'post_tag');
    if (taxonomy_exists('kratos_series')) {
        $tax[] = 'kratos_series';
    }
    return (array) apply_filters('kratos_lpv_taxonomies', $tax);
}

/**
 * 把 URL 归一化成映射表的键。
 *
 * 同时兼容固定链接与朴素链接（`?cat=3`）两种形态：路径去掉尾斜杠，
 * 有 query 时按参数名排序后拼回去，避免参数顺序不同导致同一归档命中不到。
 *
 * @param string $url
 * @return string
 */
function kratos_lpv_url_key($url)
{
    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return '';
    }
    $path = isset($parts['path']) ? untrailingslashit($parts['path']) : '';
    if ($path === '') {
        $path = '/';
    }

    $qs = '';
    if (!empty($parts['query'])) {
        $args = array();
        wp_parse_str($parts['query'], $args);
        // 分页/预览之类的噪声参数不参与归档匹配
        unset($args['paged'], $args['page'], $args['preview'], $args['s']);
        if (!empty($args)) {
            ksort($args);
            $qs = '?' . http_build_query($args);
        }
    }

    return $path . $qs;
}

/**
 * 「归档链接路径 → term」映射表。
 *
 * 核心没有 url_to_term()。逐个 get_term_link() 比对是 O(n)，
 * 所以一次性建表缓存，之后都是 O(1) 命中；分类/标签增删改时失效。
 *
 * @return array<string, array{id:int, tax:string}>
 */
function kratos_lpv_term_map()
{
    $key = 'kratos_lpv_term_map_v1';
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }

    $max = max(1, (int) kratos_option('g_link_preview_term_max', 3000));

    $terms = get_terms(array(
        'taxonomy'   => kratos_lpv_taxonomies(),
        'hide_empty' => false,
        'number'     => $max,
    ));

    $map = array();
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) {
            $link = get_term_link($t);
            if (is_wp_error($link)) {
                continue;
            }
            $k = kratos_lpv_url_key($link);
            if ($k === '') {
                continue;
            }
            $map[$k] = array('id' => (int) $t->term_id, 'tax' => (string) $t->taxonomy);
        }
    }

    $ttl = max(5, (int) kratos_option('g_link_preview_cache_min', 360)) * MINUTE_IN_SECONDS;
    set_transient($key, $map, $ttl);

    return $map;
}

/** 分类/标签增删改时清掉映射表与该 term 的载荷缓存。 */
function kratos_lpv_flush_term_cache($term_id = 0, $tt_id = 0, $taxonomy = '')
{
    delete_transient('kratos_lpv_term_map_v1');
    if ($term_id && $taxonomy !== '') {
        delete_transient('kratos_lpv_term_' . $taxonomy . '_' . (int) $term_id);
    }
}
add_action('created_term', 'kratos_lpv_flush_term_cache', 10, 3);
add_action('edited_term', 'kratos_lpv_flush_term_cache', 10, 3);
add_action('delete_term', 'kratos_lpv_flush_term_cache', 10, 3);

/**
 * 组装归档预览载荷。
 *
 * @param int    $term_id
 * @param string $taxonomy
 * @return array|null
 */
function kratos_lpv_build_term_payload($term_id, $taxonomy)
{
    $term = get_term((int) $term_id, (string) $taxonomy);
    if (!$term || is_wp_error($term)) {
        return null;
    }

    $cache_key = 'kratos_lpv_term_' . $taxonomy . '_' . (int) $term_id;
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $link = get_term_link($term);
    if (is_wp_error($link)) {
        return null;
    }

    $excerpt_len = max(20, (int) kratos_option('g_link_preview_excerpt', 110));
    $desc = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $term->description)));
    // 自动内链约定：标签/分类描述里可写「别名: a, b」，那一行是配置不是简介，别展示
    $desc = trim(preg_replace('/^别名\s*[:：].*$/um', '', $desc));
    if (mb_strlen($desc) > $excerpt_len) {
        $desc = mb_substr($desc, 0, $excerpt_len) . '…';
    }

    $tax_obj = get_taxonomy($taxonomy);
    $tax_label = $tax_obj && !empty($tax_obj->labels->singular_name)
        ? $tax_obj->labels->singular_name
        : $taxonomy;

    // 最近几篇：给读者一个「这个归档里都有什么」的直观印象
    $recent = array();
    $recent_max = max(0, (int) kratos_option('g_link_preview_term_posts', 3));
    if ($recent_max > 0 && (int) $term->count > 0) {
        $q = new WP_Query(array(
            'post_type'              => 'any',
            'post_status'            => 'publish',
            'posts_per_page'         => $recent_max,
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'tax_query'              => array(
                array(
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => (int) $term_id,
                ),
            ),
        ));
        foreach ($q->posts as $p) {
            $recent[] = array(
                'title' => get_the_title($p),
                'date'  => get_the_date('Y-m-d', $p),
                // 卡片里的每条标题都是可点的链接，所以载荷必须带上永久链接
                'url'   => get_permalink($p),
            );
        }
    }

    $payload = array(
        'found'     => true,
        'kind'      => 'term',
        'id'        => (int) $term_id,
        'taxonomy'  => (string) $taxonomy,
        'taxLabel'  => $tax_label,
        'title'     => $term->name,
        'excerpt'   => $desc,
        'url'       => $link,
        'count'     => (int) $term->count,
        'recent'    => $recent,
    );

    $ttl = max(5, (int) kratos_option('g_link_preview_cache_min', 360)) * MINUTE_IN_SECONDS;
    set_transient($cache_key, $payload, $ttl);

    return $payload;
}

/** 注册 REST 路由。 */
function kratos_lpv_register_rest()
{
    register_rest_route('kratos/v1', '/link-preview', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'args'                => array(
            'url' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
            ),
        ),
        'callback'            => 'kratos_lpv_rest_callback',
    ));
}
add_action('rest_api_init', 'kratos_lpv_register_rest');

/** REST 回调。 */
function kratos_lpv_rest_callback($request)
{
    if (!kratos_lpv_enabled()) {
        return new WP_REST_Response(array('found' => false), 200);
    }

    $url = (string) $request->get_param('url');
    if ($url === '') {
        return new WP_REST_Response(array('found' => false), 200);
    }

    // 只接受本站链接，避免把接口变成任意 URL 的探测器
    $host_in   = wp_parse_url($url, PHP_URL_HOST);
    $host_home = wp_parse_url(home_url('/'), PHP_URL_HOST);
    if ($host_in && $host_home && strtolower($host_in) !== strtolower($host_home)) {
        return new WP_REST_Response(array('found' => false), 200);
    }

    // 先试文章/页面，再试归档 —— url_to_postid() 对归档地址返回 0，两者不会互抢
    $post_id = url_to_postid($url);
    if ($post_id) {
        $payload = kratos_lpv_build_payload($post_id);
        if ($payload) {
            return new WP_REST_Response($payload, 200);
        }
        return new WP_REST_Response(array('found' => false), 200);
    }

    if (kratos_option('g_link_preview_terms', true)) {
        $map = kratos_lpv_term_map();
        $k = kratos_lpv_url_key($url);
        if ($k !== '' && isset($map[$k])) {
            $payload = kratos_lpv_build_term_payload($map[$k]['id'], $map[$k]['tax']);
            if ($payload) {
                return new WP_REST_Response($payload, 200);
            }
        }
    }

    return new WP_REST_Response(array('found' => false), 200);
}

/** 前端资源与配置。 */
function kratos_lpv_enqueue()
{
    if (is_admin() || !kratos_lpv_enabled() || !is_singular()) {
        return;
    }

    wp_enqueue_script('kratos-link-preview', ASSET_PATH . '/assets/js/link-preview.js', array(), THEME_VERSION, true);
    wp_localize_script('kratos-link-preview', 'kratosLinkPreview', array(
        'endpoint' => esc_url_raw(rest_url('kratos/v1/link-preview')),
        'delay'    => max(80, (int) kratos_option('g_link_preview_delay', 320)),
        'scope'    => '.k-main .details .content',
        'i18n'     => array(
            'words'    => __('字', 'kratos'),
            'minutes'  => __('分钟', 'kratos'),
            'comments' => __('条评论', 'kratos'),
            'posts'    => __('篇文章', 'kratos'),
            'empty'    => __('暂无文章', 'kratos'),
        ),
    ));

    wp_add_inline_style('kratos', kratos_lpv_inline_css());
}
add_action('wp_enqueue_scripts', 'kratos_lpv_enqueue', 30);

/**
 * 预览卡样式。挂在 `.kratos-lpv` 根容器上，变量走 --khs-*，
 * 皮肤由 components.css 的别名层统一接管（无需改皮肤文件）。
 */
function kratos_lpv_inline_css()
{
    return '
    .kratos-lpv {
        --khs-fg: #333; --khs-fg-soft: #555; --khs-fg-dim: #777;
        --khs-accent: #336699;
        --khs-line: rgba(0,0,0,.08); --khs-line-strong: rgba(0,0,0,.16);
        --khs-card-bg: #fff; --khs-bg-3: #ebebeb;
        position: absolute; z-index: 9999;
        width: 320px; max-width: calc(100vw - 24px);
        padding: 14px 16px;
        background: var(--khs-card-bg);
        border: 1px solid var(--khs-line);
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(0,0,0,.14);
        color: var(--khs-fg);
        font-size: 13px; line-height: 1.6;
        opacity: 0; transform: translateY(4px);
        transition: opacity .18s ease, transform .18s ease;
        pointer-events: none;
    }
    .kratos-lpv.is-visible { opacity: 1; transform: translateY(0); pointer-events: auto; }
    .kratos-lpv .lpv-thumb-link { display: block; }
    .kratos-lpv .lpv-thumb {
        display: block; width: 100%; height: 132px;
        margin-bottom: 10px; border-radius: 8px;
        object-fit: cover; background: var(--khs-bg-3);
    }
    .kratos-lpv .lpv-title {
        margin: 0 0 6px; font-size: 15px; font-weight: 600; line-height: 1.45;
        color: var(--khs-fg);
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    /* 卡内链接：标题与「最近几篇」都可直接点进去 */
    .kratos-lpv a.lpv-link {
        color: inherit !important;
        text-decoration: none !important;
        transition: color .18s ease;
    }
    .kratos-lpv a.lpv-link:hover,
    .kratos-lpv a.lpv-link:focus-visible {
        color: var(--khs-accent) !important;
    }
    .kratos-lpv a.lpv-link:focus-visible {
        outline: 2px solid var(--khs-accent);
        outline-offset: 2px;
    }
    /* 必须显式写 font-size / line-height，不能靠 .kratos-lpv 容器继承：
     * style.css 有一条裸元素规则 `p, ul { font-size: 16px }`，直接命中元素的
     * 声明优先于继承值，否则摘要会被拉到 16px —— 比 15px 的标题还大。
     * 同理下面的 .lpv-term-list（ul）也要自己写一份。 */
    .kratos-lpv .lpv-excerpt {
        margin: 0 0 8px;
        font-size: 13px; line-height: 1.65;
        color: var(--khs-fg-soft);
        display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
    }
    .kratos-lpv .lpv-meta {
        display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
        font-size: 12px; color: var(--khs-fg-dim);
        padding-top: 8px; border-top: 1px solid var(--khs-line);
    }
    .kratos-lpv .lpv-sep { opacity: .5; }
    .kratos-lpv .lpv-cat { color: var(--khs-accent); }
    /* 分类是链接形态时用 a.lpv-cat（0,2,1）压过通用的 a 规则，保持强调色可点 */
    .kratos-lpv a.lpv-cat {
        color: var(--khs-accent);
        text-decoration: none;
        transition: opacity .18s ease;
    }
    .kratos-lpv a.lpv-cat:hover,
    .kratos-lpv a.lpv-cat:focus-visible { text-decoration: underline; opacity: .85; }
    .kratos-lpv a.lpv-cat:focus-visible {
        outline: 2px solid var(--khs-accent);
        outline-offset: 2px;
    }
    /* 归档卡 */
    .kratos-lpv .lpv-term-head {
        display: flex; align-items: center; gap: 8px; margin-bottom: 6px;
    }
    .kratos-lpv .lpv-term-badge {
        flex-shrink: 0; padding: 1px 8px;
        font-size: 11px; line-height: 1.7;
        color: var(--khs-accent);
        border: 1px solid var(--khs-line-strong);
        border-radius: 999px;
    }
    .kratos-lpv .lpv-term-title {
        margin: 0; min-width: 0;
        -webkit-line-clamp: 1;
    }
    .kratos-lpv .lpv-term-list {
        list-style: none; margin: 0 0 8px; padding: 0;
        font-size: 12px; line-height: 1.6;
    }
    .kratos-lpv .lpv-term-item {
        display: flex; align-items: baseline; gap: 8px;
        padding: 3px 0; font-size: 12px;
    }
    /* 用 a.lpv-term-item-title（0,2,1）与上面 a.lpv-link 的 `color:inherit !important`
     * 同特异性、且写在其后，以保住这行更浅的常态色；hover 由 a.lpv-link:hover
     * （0,3,1，更高）接管，两者不冲突。 */
    .kratos-lpv a.lpv-term-item-title,
    .kratos-lpv .lpv-term-item-title {
        flex: 1; min-width: 0;
        color: var(--khs-fg-soft) !important;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .kratos-lpv .lpv-term-item-date {
        flex-shrink: 0; color: var(--khs-fg-dim);
        font-variant-numeric: tabular-nums;
    }
    a.lpv-anchor { text-decoration-style: dotted !important; text-underline-offset: 2px; }
    @media (hover: none), (pointer: coarse) { .kratos-lpv { display: none !important; } }
    html[data-theme="dark"] .kratos-lpv {
        --khs-fg: #d6d8db; --khs-fg-soft: #b8bbc0; --khs-fg-dim: #8b919a;
        --khs-accent: #6ea8ff;
        --khs-line: rgba(255,255,255,.08); --khs-line-strong: rgba(255,255,255,.16);
        --khs-card-bg: #1c1f24; --khs-bg-3: #333842;
        box-shadow: 0 12px 32px rgba(0,0,0,.6);
    }
    ';
}
