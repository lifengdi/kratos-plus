<?php

/**
 * 搜索结果页增强
 *
 * 提供:
 *   - search.php 模板所需的全部数据层与渲染函数
 *   - 关键词高亮（单次 alternation 正则，避免二次命中已插入的 <mark> 标记）
 *   - 结果分组：文章 / 说说（CPT 默认 exclude_from_search，需独立查询）/ 系列
 *   - 零结果兜底：随机漫步 + 热门标签 + 归档入口
 *   - body class `is-kratos-search-page`
 *
 * 视觉全部走公共类 kr-hd / kr-body / kr-card / kr-ico / kr-pill / kr-btn，
 * 私有命名空间为 `ksr-*`，变量 `--khs-*` 映射见 assets/css/components.css 别名层。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 把搜索词切成 token（按空白切分，去重，长的优先）。
 *
 * @param string $kw
 * @return string[]
 */
function kratos_search_terms($kw)
{
    $kw = trim((string) $kw);
    if ($kw === '') {
        return array();
    }
    $terms = preg_split('/\s+/u', $kw, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($terms)) {
        return array();
    }
    $terms = array_values(array_unique($terms));
    usort($terms, function ($a, $b) {
        return mb_strlen($b) - mb_strlen($a);
    });
    return $terms;
}

/**
 * 关键词高亮。
 *
 * 先 strip tags + esc_html，再用「一次 alternation 替换」插入 <mark>。
 * 必须一次替换：如果逐 token 多轮替换，搜索 "mark" / "class" 这类词会命中
 * 上一轮插入的标记本身，把 HTML 打烂。
 *
 * @param string $text 原始文本（可含 HTML，会被剥离）
 * @param string $kw   搜索词
 * @return string 转义后的安全 HTML
 */
function kratos_search_highlight($text, $kw)
{
    $plain = wp_strip_all_tags((string) $text);
    $safe  = esc_html($plain);

    if (!kratos_option('g_search_highlight', true)) {
        return $safe;
    }

    $terms = kratos_search_terms($kw);
    if (empty($terms)) {
        return $safe;
    }

    $quoted = array();
    foreach ($terms as $t) {
        // 注意：token 也要先 esc_html 再 quote —— 因为 $safe 已经是转义后的文本，
        // 搜索 "a&b" 时目标串里是 "a&amp;b"。
        $e = esc_html($t);
        if ($e !== '') {
            $quoted[] = preg_quote($e, '/');
        }
    }
    if (empty($quoted)) {
        return $safe;
    }

    $out = preg_replace(
        '/(' . implode('|', $quoted) . ')/iu',
        '<mark class="ksr-hl">$1</mark>',
        $safe
    );

    return $out === null ? $safe : $out;
}

/**
 * 取一段以首个命中词为中心的摘要窗口。
 *
 * @param string $text  正文/摘要原文
 * @param string $kw    搜索词
 * @param int    $len   窗口长度（字符数）
 * @return string 未转义的纯文本（交给 kratos_search_highlight 转义）
 */
function kratos_search_snippet($text, $kw, $len = 150)
{
    $plain = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes((string) $text))));
    if ($plain === '') {
        return '';
    }
    if (mb_strlen($plain) <= $len) {
        return $plain;
    }

    $pos = false;
    foreach (kratos_search_terms($kw) as $t) {
        $p = mb_stripos($plain, $t);
        if ($p !== false) {
            $pos = $p;
            break;
        }
    }

    if ($pos === false) {
        return mb_substr($plain, 0, $len) . '…';
    }

    // 让命中词大致落在窗口 1/3 处，前后各留出上下文
    $start = max(0, $pos - (int) floor($len / 3));
    $out   = mb_substr($plain, $start, $len);
    if ($start > 0) {
        $out = '…' . $out;
    }
    if ($start + $len < mb_strlen($plain)) {
        $out .= '…';
    }
    return $out;
}

/**
 * 说说（CPT `shuoshuo`）注册时带 exclude_from_search=true，
 * 不会进主查询，需要单独查一次。
 *
 * @param string $kw
 * @param int    $limit
 * @return WP_Post[]
 */
function kratos_search_shuoshuo($kw, $limit = 5)
{
    if (!kratos_option('g_search_shuoshuo', true)) {
        return array();
    }
    if (!post_type_exists('shuoshuo') || trim((string) $kw) === '') {
        return array();
    }
    $q = new WP_Query(kratos_lean_query_args(array(
        'post_type'              => 'shuoshuo',
        'post_status'            => 'publish',
        's'                      => $kw,
        'posts_per_page'         => max(1, (int) $limit),
        'ignore_sticky_posts'    => true,
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
    ), array('no_terms' => true)));
    return $q->posts;
}

/**
 * 匹配系列（taxonomy `kratos_series`）名称/描述。
 *
 * @param string $kw
 * @param int    $limit
 * @return WP_Term[]
 */
function kratos_search_series($kw, $limit = 6)
{
    if (!kratos_option('g_search_series', true)) {
        return array();
    }
    if (!taxonomy_exists('kratos_series') || trim((string) $kw) === '') {
        return array();
    }
    $terms = get_terms(array(
        'taxonomy'   => 'kratos_series',
        'hide_empty' => true,
        'search'     => $kw,
        'number'     => max(1, (int) $limit),
    ));
    return is_wp_error($terms) ? array() : $terms;
}

/** 搜索框（结果页顶部再来一次搜索用）。 */
function kratos_search_form_html($kw)
{
    $ph = (string) kratos_option('g_search_placeholder', __('换个词再试试…', 'kratos'));
    return '<form class="ksr-form" role="search" method="get" action="' . esc_url(home_url('/')) . '">'
        . '<input class="ksr-input" type="search" name="s" value="' . esc_attr($kw) . '" placeholder="' . esc_attr($ph) . '" aria-label="' . esc_attr__('搜索', 'kratos') . '" />'
        . '<button class="ksr-submit kr-btn" type="submit">' . esc_html__('搜索', 'kratos') . '</button>'
        . '</form>';
}

/** 结果页页头（图标 + 标题 + 命中数 + 搜索框）。 */
function kratos_search_header_html($kw, $total)
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';

    $title = (string) kratos_option('g_search_title', __('搜索结果', 'kratos'));
    $sub   = $kw === ''
        ? __('输入关键词开始搜索', 'kratos')
        : sprintf(
            /* translators: 1: 关键词, 2: 命中数 */
            __('“%1$s” 共找到 %2$s 条结果', 'kratos'),
            $kw,
            number_format_i18n($total)
        );

    ob_start(); ?>
    <header class="ksr-header kr-hd">
        <span class="ksr-header-icon kr-ico" aria-hidden="true"><?php echo $svg; ?></span>
        <h2 class="ksr-header-title kr-hd-title"><?php echo esc_html($title); ?></h2>
        <span class="ksr-header-divider kr-hd-divider" aria-hidden="true"></span>
        <p class="ksr-header-subtitle kr-hd-sub"><?php echo esc_html($sub); ?></p>
        <?php echo kratos_search_form_html($kw); ?>
    </header>
    <?php
    return ob_get_clean();
}

/** 单条文章结果行。 */
function kratos_search_post_row_html($post_id, $kw)
{
    // 直接拿附件 ID（thumbnail meta 已随主查询预热），不要用 URL 再反查一遍
    $thumb_id = (int) get_post_thumbnail_id($post_id);
    $title = kratos_search_highlight(get_the_title($post_id), $kw);
    $src   = get_the_excerpt($post_id);
    if (trim((string) $src) === '') {
        $src = get_post_field('post_content', $post_id);
    }
    $snip = kratos_search_highlight(kratos_search_snippet($src, $kw), $kw);

    $cats = get_the_category($post_id);
    $cat  = !empty($cats) ? $cats[0] : null;

    ob_start(); ?>
    <article class="ksr-item kr-card">
        <?php if ($thumb_id) { ?>
            <a class="ksr-item-thumb" href="<?php echo esc_url(get_permalink($post_id)); ?>" aria-hidden="true" tabindex="-1">
                <?php echo wp_get_attachment_image($thumb_id, 'kratos-thumbnail', false, array('alt' => '')); ?>
            </a>
        <?php } ?>
        <div class="ksr-item-body">
            <h3 class="ksr-item-title">
                <a href="<?php echo esc_url(get_permalink($post_id)); ?>"><?php echo $title; ?></a>
            </h3>
            <?php if ($snip !== '') { ?>
                <p class="ksr-item-excerpt"><?php echo $snip; ?></p>
            <?php } ?>
            <div class="ksr-item-meta">
                <span class="ksr-item-date"><?php echo esc_html(get_the_date('Y-m-d', $post_id)); ?></span>
                <?php if ($cat) { ?>
                    <span class="ksr-item-sep" aria-hidden="true">·</span>
                    <a class="ksr-item-cat" href="<?php echo esc_url(get_category_link($cat->term_id)); ?>"><?php echo esc_html($cat->name); ?></a>
                <?php } ?>
                <span class="ksr-item-sep" aria-hidden="true">·</span>
                <span class="ksr-item-comments"><?php
                    printf(esc_html__('%s 条评论', 'kratos'), number_format_i18n((int) get_comments_number($post_id)));
                ?></span>
            </div>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

/** 分组小标题。 */
function kratos_search_group_head_html($label, $count, $svg)
{
    return '<header class="ksr-group-head">'
        . '<span class="ksr-group-icon kr-ico" aria-hidden="true">' . $svg . '</span>'
        . '<h3 class="ksr-group-title">' . esc_html($label) . '</h3>'
        . '<span class="ksr-group-count kr-pill">' . esc_html(number_format_i18n($count)) . '</span>'
        . '</header>';
}

/** 零结果兜底区：随机漫步 + 热门标签 + 归档入口。 */
function kratos_search_empty_html($kw)
{
    $tags_max = max(0, (int) kratos_option('g_search_empty_tags', 12));
    $tags = $tags_max > 0 ? get_terms(array(
        'taxonomy'   => 'post_tag',
        'orderby'    => 'count',
        'order'      => 'DESC',
        'hide_empty' => true,
        'number'     => $tags_max,
    )) : array();
    if (is_wp_error($tags)) {
        $tags = array();
    }

    $svg_ghost = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="15" x2="16" y2="15"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>';
    $svg_dice  = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>';

    ob_start(); ?>
    <section class="ksr-empty kr-card">
        <span class="ksr-empty-icon kr-ico" aria-hidden="true"><?php echo $svg_ghost; ?></span>
        <h3 class="ksr-empty-title"><?php
            if ($kw === '') {
                esc_html_e('还没有输入关键词', 'kratos');
            } else {
                /* translators: %s: 关键词 */
                printf(esc_html__('没有找到与「%s」相关的内容', 'kratos'), esc_html($kw));
            }
        ?></h3>
        <p class="ksr-empty-sub"><?php esc_html_e('换个说法，或者从下面这些地方逛逛：', 'kratos'); ?></p>

        <div class="ksr-empty-actions">
            <?php if (function_exists('kratos_stumble_url') && kratos_option('g_stumble', true)) { ?>
                <a class="ksr-empty-btn kr-btn" href="<?php echo kratos_stumble_url(); ?>" rel="nofollow">
                    <span class="ksr-empty-btn-ico" aria-hidden="true"><?php echo $svg_dice; ?></span>
                    <?php esc_html_e('随机漫步', 'kratos'); ?>
                </a>
            <?php } ?>
            <a class="ksr-empty-btn kr-btn" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('回到首页', 'kratos'); ?></a>
        </div>

        <?php if (!empty($tags)) { ?>
            <div class="ksr-empty-tags">
                <div class="ksr-empty-tags-label"><?php esc_html_e('热门标签', 'kratos'); ?></div>
                <div class="ksr-empty-tags-grid">
                    <?php foreach ($tags as $tag) { ?>
                        <a class="ksr-empty-tag kr-pill" href="<?php echo esc_url(get_term_link($tag)); ?>">
                            <span class="ksr-empty-tag-label"><?php echo esc_html($tag->name); ?></span>
                            <span class="ksr-empty-tag-count"><?php echo esc_html(number_format_i18n((int) $tag->count)); ?></span>
                        </a>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * 搜索页样式。骨架用 --khs-* 变量驱动，皮肤层通过 components.css
 * 的别名段（--khs-* 系列）统一接管配色。
 */
function kratos_search_styles()
{
    ob_start(); ?>
    <style>
        /* === 搜索结果页：通用骨架（CSS 变量驱动） === */
        .kratos-search {
            --khs-fg: #333; --khs-fg-soft: #555; --khs-fg-dim: #777;
            --khs-accent: #336699;
            --khs-line: rgba(0, 0, 0, .08); --khs-line-strong: rgba(0, 0, 0, .16);
            --khs-card-bg: #ffffff;
            --khs-bg-2: #f0f0f0; --khs-bg-3: #ebebeb;
            --khs-card-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            --khs-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .10);
            --khs-hl-bg: rgba(255, 213, 79, .45);
            color: var(--khs-fg);
        }

        /* 页头 */
        .kratos-search .ksr-header {
            display: flex; align-items: center; flex-wrap: wrap; gap: 14px;
            padding: 24px 28px; margin-bottom: 18px;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 14px;
            box-shadow: var(--khs-card-shadow);
        }
        .kratos-search .ksr-header-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, var(--khs-bg-2) 0%, var(--khs-bg-3) 100%);
            color: var(--khs-accent);
        }
        .kratos-search .ksr-header-title {
            margin: 0; font-size: 22px; font-weight: 700; line-height: 1.3; color: var(--khs-fg);
        }
        .kratos-search .ksr-header-divider {
            display: inline-block; width: 1px; height: 22px; background: var(--khs-line-strong);
        }
        .kratos-search .ksr-header-subtitle {
            margin: 0; font-size: 14px; line-height: 1.5; color: var(--khs-fg-soft);
        }

        /* 搜索框 */
        .kratos-search .ksr-form {
            display: flex; gap: 8px; flex-basis: 100%; margin: 4px 0 0;
        }
        .kratos-search .ksr-input {
            flex: 1; min-width: 0;
            padding: 9px 14px;
            font-size: 14px; font-family: inherit;
            color: var(--khs-fg);
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line-strong);
            border-radius: 8px;
            outline: none;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .kratos-search .ksr-input:focus {
            border-color: var(--khs-accent);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--khs-accent) 18%, transparent);
        }
        .kratos-search .ksr-submit {
            flex-shrink: 0;
            padding: 9px 20px;
            font-size: 14px; font-family: inherit;
            color: #fff; background: var(--khs-accent);
            border: 1px solid var(--khs-accent);
            border-radius: 8px;
            cursor: pointer;
            transition: opacity .2s ease, transform .2s ease;
        }
        .kratos-search .ksr-submit:hover { opacity: .88; }

        /* 分组 */
        .kratos-search .ksr-group { margin-bottom: 22px; }
        .kratos-search .ksr-group-head {
            display: flex; align-items: center; gap: 10px; margin-bottom: 12px;
        }
        .kratos-search .ksr-group-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; color: var(--khs-accent);
        }
        .kratos-search .ksr-group-title {
            margin: 0; font-size: 17px; font-weight: 700; line-height: 1.3; color: var(--khs-fg);
        }
        .kratos-search .ksr-group-count {
            padding: 1px 10px; font-size: 12px; line-height: 1.6;
            color: var(--khs-fg-dim);
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 999px;
        }

        /* 结果行 */
        .kratos-search .ksr-item {
            display: flex; gap: 16px; align-items: flex-start;
            padding: 18px 20px; margin-bottom: 12px;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 12px;
            box-shadow: var(--khs-card-shadow);
            transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }
        .kratos-search .ksr-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--khs-card-shadow-hv);
            border-color: var(--khs-line-strong);
        }
        .kratos-search .ksr-item-thumb {
            flex-shrink: 0; display: block;
            width: 120px; height: 80px;
            overflow: hidden; border-radius: 8px;
            background: var(--khs-bg-3);
        }
        .kratos-search .ksr-item-thumb img {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }
        .kratos-search .ksr-item-body { flex: 1; min-width: 0; }
        .kratos-search .ksr-item-title {
            margin: 0 0 6px; font-size: 17px; font-weight: 600; line-height: 1.4;
        }
        .kratos-search .ksr-item-title a {
            color: var(--khs-fg) !important; text-decoration: none !important;
            transition: color .2s ease;
        }
        .kratos-search .ksr-item-title a:hover { color: var(--khs-accent) !important; }
        .kratos-search .ksr-item-excerpt {
            margin: 0 0 8px; font-size: 14px; line-height: 1.7; color: var(--khs-fg-soft);
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .kratos-search .ksr-item-meta {
            display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
            font-size: 12px; color: var(--khs-fg-dim);
        }
        .kratos-search .ksr-item-meta a {
            color: var(--khs-fg-dim) !important; text-decoration: none !important;
        }
        .kratos-search .ksr-item-meta a:hover { color: var(--khs-accent) !important; }
        .kratos-search .ksr-item-sep { opacity: .5; }

        /* 说说结果 */
        .kratos-search .ksr-ss {
            padding: 16px 20px; margin-bottom: 12px;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 12px;
            box-shadow: var(--khs-card-shadow);
        }
        .kratos-search .ksr-ss-text {
            margin: 0 0 8px; font-size: 15px; line-height: 1.75; color: var(--khs-fg);
        }
        .kratos-search .ksr-ss-meta { font-size: 12px; color: var(--khs-fg-dim); }
        .kratos-search .ksr-ss-meta a { color: var(--khs-accent) !important; text-decoration: none !important; }

        /* 系列结果 */
        .kratos-search .ksr-series-grid {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px;
        }
        .kratos-search .ksr-series {
            display: block; padding: 14px 18px;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 10px;
            box-shadow: var(--khs-card-shadow);
            text-decoration: none !important;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .kratos-search .ksr-series:hover {
            transform: translateY(-1px);
            box-shadow: var(--khs-card-shadow-hv);
            border-color: var(--khs-line-strong);
        }
        .kratos-search .ksr-series-name {
            font-size: 15px; font-weight: 600; color: var(--khs-fg); margin-bottom: 4px;
        }
        .kratos-search .ksr-series-desc {
            font-size: 13px; line-height: 1.6; color: var(--khs-fg-dim);
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* 高亮 */
        .kratos-search mark.ksr-hl {
            background: var(--khs-hl-bg);
            color: inherit;
            padding: 0 2px;
            border-radius: 2px;
        }

        /* 零结果 */
        .kratos-search .ksr-empty {
            padding: 36px 28px; text-align: center;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 14px;
            box-shadow: var(--khs-card-shadow);
        }
        .kratos-search .ksr-empty-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 46px; height: 46px; margin-bottom: 14px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--khs-bg-2) 0%, var(--khs-bg-3) 100%);
            color: var(--khs-accent);
        }
        .kratos-search .ksr-empty-title {
            margin: 0 0 6px; font-size: 18px; font-weight: 700; color: var(--khs-fg);
        }
        .kratos-search .ksr-empty-sub {
            margin: 0 0 18px; font-size: 14px; color: var(--khs-fg-dim);
        }
        .kratos-search .ksr-empty-actions {
            display: flex; justify-content: center; flex-wrap: wrap; gap: 10px; margin-bottom: 22px;
        }
        .kratos-search .ksr-empty-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 18px; font-size: 14px;
            color: var(--khs-accent) !important;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line-strong);
            border-radius: 8px;
            text-decoration: none !important;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .kratos-search .ksr-empty-btn:hover {
            transform: translateY(-1px);
            border-color: var(--khs-accent);
            box-shadow: 0 4px 10px rgba(0, 0, 0, .08);
        }
        .kratos-search .ksr-empty-btn-ico { display: inline-flex; }
        .kratos-search .ksr-empty-tags-label {
            font-size: 13px; color: var(--khs-fg-dim); margin-bottom: 10px;
        }
        .kratos-search .ksr-empty-tags-grid {
            display: flex; justify-content: center; flex-wrap: wrap; gap: 8px;
        }
        .kratos-search .ksr-empty-tag {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; font-size: 13px;
            color: var(--khs-fg-soft) !important;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 999px;
            text-decoration: none !important;
            transition: color .2s ease, border-color .2s ease;
        }
        .kratos-search .ksr-empty-tag:hover {
            color: var(--khs-accent) !important;
            border-color: var(--khs-accent);
        }
        .kratos-search .ksr-empty-tag-count { font-size: 11px; color: var(--khs-fg-dim); }

        /* 响应式 */
        @media (max-width: 768px) {
            .kratos-search .ksr-series-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .kratos-search .ksr-header { padding: 18px 20px; }
            .kratos-search .ksr-header-title { font-size: 19px; }
            .kratos-search .ksr-header-divider { display: none; }
            .kratos-search .ksr-header-subtitle { flex-basis: 100%; }
            .kratos-search .ksr-item { padding: 14px 16px; gap: 12px; }
            .kratos-search .ksr-item-thumb { width: 84px; height: 62px; }
            .kratos-search .ksr-item-title { font-size: 15px; }
            .kratos-search .ksr-empty { padding: 28px 18px; }
        }

        /* 暗夜模式 */
        html[data-theme="dark"] .kratos-search {
            --khs-fg: #d6d8db; --khs-fg-soft: #b8bbc0; --khs-fg-dim: #8b919a;
            --khs-accent: #6ea8ff;
            --khs-line: rgba(255, 255, 255, .08); --khs-line-strong: rgba(255, 255, 255, .16);
            --khs-card-bg: #1c1f24;
            --khs-bg-2: #2a2e35; --khs-bg-3: #333842;
            --khs-card-shadow: 0 1px 2px rgba(0, 0, 0, .5);
            --khs-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .55);
            --khs-hl-bg: rgba(255, 213, 79, .28);
        }
    </style>
    <?php
    return ob_get_clean();
}

/** 给搜索结果页注入 body class，便于皮肤层精准定位。 */
function kratos_search_body_class($classes)
{
    if (is_search()) {
        $classes[] = 'is-kratos-search-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_search_body_class');
