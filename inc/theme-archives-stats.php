<?php

/**
 * 博客归档统计
 *
 * 提供:
 *   - [archives_stats] 短码：渲染「文章归档」页头 + 4 张总览统计卡 +
 *     分类列表 + 标签列表。视觉与走心评论一致（米色 parchment 风格）。
 *   - 给应用了 page-archives.php 模板的页面注入 body class
 *     `is-kratos-archives-page`，让皮肤层（weekday-skins.css）能精准
 *     豁免 §5/§15 对外层 .details 的装饰，保留 shortcode 自身视觉。
 *
 * 数据来源：
 *   - 文章总数: wp_count_posts('post')->publish
 *   - 评论总数: wp_count_comments()->approved
 *   - 标签总数: wp_count_terms('post_tag')（仅统计有文章的标签）
 *   - 友链总数: get_bookmarks() 数量
 *   - 分类列表: get_categories(['orderby'=>'count','order'=>'DESC'])
 *   - 标签列表: get_terms('post_tag', ['orderby'=>'count','order'=>'DESC','number'=>20])
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** 给 page-archives.php 模板的页面注入 body class，让皮肤层 §15 形状层豁免。 */
function kratos_archives_body_class($classes)
{
    if (is_page_template('page-archives.php')) {
        $classes[] = 'is-kratos-archives-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_archives_body_class');

/** 统计四项总数。 */
function kratos_archives_stats_get_totals()
{
    $posts = wp_count_posts('post');
    $post_total = isset($posts->publish) ? (int) $posts->publish : 0;

    $comments = wp_count_comments();
    $comment_total = isset($comments->approved) ? (int) $comments->approved : 0;

    $tag_total = (int) wp_count_terms(array(
        'taxonomy'   => 'post_tag',
        'hide_empty' => true,
    ));

    $links = get_bookmarks(array('hide_invisible' => 1));
    $link_total = is_array($links) ? count($links) : 0;

    return array(
        'posts'    => $post_total,
        'comments' => $comment_total,
        'tags'     => $tag_total,
        'links'    => $link_total,
    );
}

/** 渲染单个总数卡。 */
function kratos_archives_stats_render_total($label, $value, $svg)
{
    return '<div class="kas-total"><span class="kas-total-icon" aria-hidden="true">' . $svg . '</span>'
        . '<div class="kas-total-body">'
        . '<div class="kas-total-label">' . esc_html($label) . '</div>'
        . '<div class="kas-total-num">' . esc_html(number_format_i18n($value)) . '</div>'
        . '</div></div>';
}

/**
 * [archives_stats] 短码主入口。
 *   支持参数：
 *     title    页头标题（默认"文章归档"）
 *     subtitle 页头副标题
 *     tags_max 标签列表最多展示几条（默认 20，0 表示不展示）
 *     scheme   视觉方案：parchment（默认）；目前仅 parchment 一套
 */
function kratos_archives_stats_shortcode($atts = array())
{
    $atts = shortcode_atts(array(
        'title'    => __('文章归档', 'kratos'),
        'subtitle' => __('把写过的时间，安静收拢起来', 'kratos'),
        'tags_max' => 20,
        'scheme'   => 'parchment',
    ), $atts, 'archives_stats');

    $totals = kratos_archives_stats_get_totals();

    $categories = get_categories(array(
        'orderby'    => 'count',
        'order'      => 'DESC',
        'hide_empty' => true,
    ));

    $tags_max = max(0, (int) $atts['tags_max']);
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

    $valid_schemes = array('parchment');
    $scheme = in_array($atts['scheme'], $valid_schemes, true) ? $atts['scheme'] : 'parchment';

    // SVG 图标
    $svg_doc = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>';
    $svg_chat = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    $svg_tag = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>';
    $svg_link = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
    $svg_folder = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
    $svg_tag_section = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>';

    ob_start();
    ?>
    <div class="kratos-archives-shortcode kas-scheme-<?php echo esc_attr($scheme); ?>">

        <!-- 页头：图标 + 标题 + 副标题 -->
        <header class="kas-header">
            <span class="kas-header-icon" aria-hidden="true"><?php echo $svg_doc; ?></span>
            <h2 class="kas-header-title"><?php echo esc_html($atts['title']); ?></h2>
            <?php if ($atts['subtitle'] !== '') { ?>
                <span class="kas-header-divider" aria-hidden="true"></span>
                <p class="kas-header-subtitle"><?php echo esc_html($atts['subtitle']); ?></p>
            <?php } ?>
        </header>

        <!-- 四张总览卡 -->
        <div class="kas-totals">
            <?php
            echo kratos_archives_stats_render_total(__('文章总数', 'kratos'), $totals['posts'], $svg_doc);
            echo kratos_archives_stats_render_total(__('评论总数', 'kratos'), $totals['comments'], $svg_chat);
            echo kratos_archives_stats_render_total(__('标签总数', 'kratos'), $totals['tags'], $svg_tag);
            echo kratos_archives_stats_render_total(__('友链总数', 'kratos'), $totals['links'], $svg_link);
            ?>
        </div>

        <!-- 分类统计 -->
        <?php if (!empty($categories)) { ?>
            <section class="kas-section">
                <header class="kas-section-head">
                    <span class="kas-section-icon" aria-hidden="true"><?php echo $svg_folder; ?></span>
                    <h3 class="kas-section-title"><?php esc_html_e('分类统计', 'kratos'); ?></h3>
                </header>
                <div class="kas-grid kas-grid-cat">
                    <?php foreach ($categories as $cat) { ?>
                        <a class="kas-pill" href="<?php echo esc_url(get_category_link($cat->term_id)); ?>">
                            <span class="kas-pill-label"><?php echo esc_html($cat->name); ?></span>
                            <span class="kas-pill-count"><?php
                                /* translators: %d: 篇数 */
                                printf(esc_html__('%d 篇', 'kratos'), (int) $cat->count);
                            ?></span>
                        </a>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>

        <!-- 标签统计 -->
        <?php if (!empty($tags)) { ?>
            <section class="kas-section">
                <header class="kas-section-head">
                    <span class="kas-section-icon" aria-hidden="true"><?php echo $svg_tag_section; ?></span>
                    <h3 class="kas-section-title"><?php esc_html_e('标签统计', 'kratos'); ?></h3>
                </header>
                <div class="kas-grid kas-grid-tag">
                    <?php foreach ($tags as $tag) { ?>
                        <a class="kas-pill" href="<?php echo esc_url(get_term_link($tag)); ?>" title="<?php echo esc_attr($tag->name); ?>">
                            <span class="kas-pill-label"><?php echo esc_html($tag->name); ?></span>
                            <span class="kas-pill-count"><?php
                                printf(esc_html__('%d 篇', 'kratos'), (int) $tag->count);
                            ?></span>
                        </a>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>

    </div>

    <style>
        /* === 归档统计短码：通用骨架（CSS 变量驱动） === */
        .kratos-archives-shortcode {
            --kas-bg-1: #f5e7c4; --kas-bg-2: #efd9a9; --kas-bg-3: #e9cc91;
            --kas-fg: #3a2a10; --kas-fg-soft: #5a3a14; --kas-fg-dim: #7a5a26; --kas-fg-mute: #a08658;
            --kas-accent: #7a3f12; --kas-accent-2: #a86028;
            --kas-line: rgba(120, 80, 30, .22); --kas-line-strong: rgba(120, 80, 30, .40);
            --kas-card-bg: rgba(255, 250, 232, .78);
            --kas-card-shadow: 0 1px 3px rgba(120, 80, 30, .10);
            --kas-card-shadow-hv: 0 8px 18px rgba(120, 80, 30, .22);
            padding: 0;
            position: relative;
            color: var(--kas-fg);
        }

        /* 页头卡片 */
        .kratos-archives-shortcode .kas-header {
            display: flex; align-items: center; flex-wrap: wrap; gap: 14px;
            padding: 24px 28px; margin-bottom: 18px;
            background: var(--kas-card-bg);
            border: 1px solid var(--kas-line);
            border-radius: 14px;
            box-shadow: var(--kas-card-shadow);
        }
        .kratos-archives-shortcode .kas-header-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 38px; height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--kas-bg-2) 0%, var(--kas-bg-3) 100%);
            color: var(--kas-accent);
        }
        .kratos-archives-shortcode .kas-header-title {
            margin: 0; padding: 0;
            font-size: 22px; font-weight: 700; line-height: 1.3;
            color: var(--kas-fg);
        }
        .kratos-archives-shortcode .kas-header-divider {
            display: inline-block; width: 1px; height: 22px;
            background: var(--kas-line-strong);
        }
        .kratos-archives-shortcode .kas-header-subtitle {
            margin: 0; padding: 0;
            font-size: 14px; line-height: 1.5;
            color: var(--kas-fg-soft);
        }

        /* 四张总览卡 */
        .kratos-archives-shortcode .kas-totals {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .kratos-archives-shortcode .kas-total {
            display: flex; align-items: center; gap: 14px;
            padding: 22px 22px;
            background: var(--kas-card-bg);
            border: 1px solid var(--kas-line);
            border-radius: 14px;
            box-shadow: var(--kas-card-shadow);
            transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }
        .kratos-archives-shortcode .kas-total:hover {
            transform: translateY(-2px);
            box-shadow: var(--kas-card-shadow-hv);
            border-color: var(--kas-line-strong);
        }
        .kratos-archives-shortcode .kas-total-icon {
            flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            width: 46px; height: 46px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--kas-bg-2) 0%, var(--kas-bg-3) 100%);
            color: var(--kas-accent);
        }
        .kratos-archives-shortcode .kas-total-body {
            display: flex; flex-direction: column; gap: 2px;
            min-width: 0;
        }
        .kratos-archives-shortcode .kas-total-label {
            font-size: 13px; line-height: 1.2;
            color: var(--kas-fg-dim);
        }
        .kratos-archives-shortcode .kas-total-num {
            font-size: 30px; font-weight: 700; line-height: 1.1;
            color: var(--kas-fg);
            letter-spacing: -0.01em;
        }

        /* 分类/标签 section */
        .kratos-archives-shortcode .kas-section {
            padding: 22px 24px;
            margin-bottom: 18px;
            background: var(--kas-card-bg);
            border: 1px solid var(--kas-line);
            border-radius: 14px;
            box-shadow: var(--kas-card-shadow);
        }
        .kratos-archives-shortcode .kas-section-head {
            display: flex; align-items: center; gap: 10px;
            padding-bottom: 14px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--kas-line);
        }
        .kratos-archives-shortcode .kas-section-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px;
            color: var(--kas-accent);
        }
        .kratos-archives-shortcode .kas-section-title {
            margin: 0; padding: 0;
            font-size: 18px; font-weight: 700; line-height: 1.3;
            color: var(--kas-fg);
        }
        .kratos-archives-shortcode .kas-grid {
            display: grid;
            gap: 12px;
        }
        .kratos-archives-shortcode .kas-grid-cat {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .kratos-archives-shortcode .kas-grid-tag {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .kratos-archives-shortcode .kas-pill {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px;
            padding: 12px 16px;
            background: rgba(255, 250, 232, .60);
            border: 1px solid var(--kas-line);
            border-radius: 10px;
            color: var(--kas-fg-soft) !important;
            text-decoration: none !important;
            font-size: 14px;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease, background .2s ease;
        }
        .kratos-archives-shortcode .kas-pill:hover {
            transform: translateY(-1px);
            background: rgba(255, 250, 232, .92);
            border-color: var(--kas-line-strong);
            box-shadow: 0 4px 10px rgba(120, 80, 30, .12);
            color: var(--kas-accent) !important;
        }
        .kratos-archives-shortcode .kas-pill-label {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
            flex: 1;
        }
        .kratos-archives-shortcode .kas-pill-count {
            flex-shrink: 0;
            padding: 2px 10px;
            background: rgba(255, 250, 232, .92);
            border: 1px solid var(--kas-line);
            border-radius: 999px;
            font-size: 12px;
            color: var(--kas-fg-dim);
            line-height: 1.5;
        }

        /* 响应式 */
        @media (max-width: 900px) {
            .kratos-archives-shortcode .kas-totals {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .kratos-archives-shortcode .kas-grid-tag {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 560px) {
            .kratos-archives-shortcode .kas-header { padding: 18px 20px; }
            .kratos-archives-shortcode .kas-header-title { font-size: 19px; }
            .kratos-archives-shortcode .kas-totals {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .kratos-archives-shortcode .kas-total { padding: 16px 18px; }
            .kratos-archives-shortcode .kas-total-num { font-size: 24px; }
            .kratos-archives-shortcode .kas-section { padding: 18px 18px; }
            .kratos-archives-shortcode .kas-grid-cat,
            .kratos-archives-shortcode .kas-grid-tag {
                grid-template-columns: 1fr;
            }
        }

        /* === parchment 方案：羊皮纸做旧 + 噪点纸纹（与走心评论一致） === */
        .kratos-archives-shortcode.kas-scheme-parchment {
            padding: 28px 24px;
            border-radius: 8px;
            background:
                radial-gradient(ellipse at top left, rgba(120, 80, 30, .18), transparent 55%),
                radial-gradient(ellipse at bottom right, rgba(120, 80, 30, .20), transparent 60%),
                radial-gradient(ellipse at center, rgba(255, 243, 206, .45), transparent 70%),
                linear-gradient(135deg, #f5e7c4 0%, #efd9a9 50%, #e9cc91 100%);
            box-shadow: inset 0 0 60px rgba(120, 80, 30, .18), 0 6px 24px rgba(120, 80, 30, .14);
            position: relative;
            overflow: hidden;
        }
        .kratos-archives-shortcode.kas-scheme-parchment::before {
            content: ""; position: absolute; inset: 0; pointer-events: none;
            opacity: .35; mix-blend-mode: multiply; border-radius: inherit;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 0.55  0 0 0 0 0.40  0 0 0 0 0.20  0 0 0 0.6 0'/></filter><rect width='200' height='200' filter='url(%23n)'/></svg>");
            background-size: 240px 240px;
        }

        /* === 暗夜模式 === */
        html[data-theme="dark"] .kratos-archives-shortcode {
            --kas-fg: #e8d8b8;
            --kas-fg-soft: #d0b88a;
            --kas-fg-dim: #a89070;
            --kas-fg-mute: #806848;
            --kas-accent: #d8a868;
            --kas-accent-2: #c89048;
            --kas-line: rgba(216, 168, 104, .22);
            --kas-line-strong: rgba(216, 168, 104, .40);
            --kas-card-bg: rgba(40, 32, 22, .72);
            --kas-card-shadow: 0 2px 6px rgba(0, 0, 0, .3);
            --kas-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .45);
        }
        html[data-theme="dark"] .kratos-archives-shortcode .kas-pill {
            background: rgba(40, 32, 22, .55);
        }
        html[data-theme="dark"] .kratos-archives-shortcode .kas-pill:hover {
            background: rgba(40, 32, 22, .85);
        }
        html[data-theme="dark"] .kratos-archives-shortcode .kas-pill-count {
            background: rgba(40, 32, 22, .85);
        }
        html[data-theme="dark"] .kratos-archives-shortcode.kas-scheme-parchment {
            background:
                radial-gradient(ellipse at top left, rgba(216, 168, 104, .12), transparent 55%),
                radial-gradient(ellipse at bottom right, rgba(216, 168, 104, .10), transparent 60%),
                linear-gradient(135deg, #2a2218 0%, #1f1a12 50%, #18130c 100%);
            box-shadow: inset 0 0 60px rgba(0, 0, 0, .35), 0 6px 24px rgba(0, 0, 0, .35);
        }
        html[data-theme="dark"] .kratos-archives-shortcode.kas-scheme-parchment::before {
            opacity: .25; mix-blend-mode: overlay;
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('archives_stats', 'kratos_archives_stats_shortcode');
