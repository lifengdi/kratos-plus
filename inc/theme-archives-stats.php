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

/**
 * 按年份统计文章数。
 * 直接走 SQL 一次性聚合，比循环 get_posts 高效得多。
 *
 * @return array<int, array{year:int, count:int}>  按年份倒序
 */
function kratos_archives_stats_get_yearly()
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT YEAR(post_date) AS y, COUNT(*) AS c
         FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status = 'publish'
         GROUP BY y ORDER BY y DESC",
        ARRAY_A
    );
    if (!$rows) {
        return array();
    }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array('year' => (int) $r['y'], 'count' => (int) $r['c']);
    }
    return $out;
}

/**
 * 按月份统计文章数。
 * @param int $limit 最多返回多少条（0 = 全部）
 * @return array<int, array{year:int, month:int, count:int}>  按时间倒序
 */
function kratos_archives_stats_get_monthly($limit = 24)
{
    global $wpdb;
    $sql = "SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, COUNT(*) AS c
            FROM {$wpdb->posts}
            WHERE post_type = 'post' AND post_status = 'publish'
            GROUP BY y, m ORDER BY y DESC, m DESC";
    if ($limit > 0) {
        $sql .= $wpdb->prepare(' LIMIT %d', $limit);
    }
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!$rows) {
        return array();
    }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            'year'  => (int) $r['y'],
            'month' => (int) $r['m'],
            'count' => (int) $r['c'],
        );
    }
    return $out;
}

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
 *   所有数量/文案默认值取自后台「归档配置」，短码同名参数可覆盖：
 *     title       页头标题（后台 archives_sc_title）
 *     subtitle    页头副标题（后台 archives_sc_subtitle）
 *     years_max   年份列表最多展示几条（后台 archives_sc_years_max；0 = 全部）
 *     months_max  月份列表最多展示几条（后台 archives_sc_months_max；0 = 不展示）
 *     tags_max    标签列表最多展示几条（后台 archives_sc_tags_max；0 = 不展示）
 *     scheme      视觉方案：parchment（默认）；目前仅 parchment 一套
 */
function kratos_archives_stats_shortcode($atts = array())
{
    // 后台默认值（「归档配置」区），短码同名参数可覆盖
    $default_title      = (string) kratos_option('archives_sc_title', __('文章归档', 'kratos'));
    $default_subtitle   = (string) kratos_option('archives_sc_subtitle', __('把写过的时间，安静收拢起来', 'kratos'));
    $default_years_max  = (int) kratos_option('archives_sc_years_max', 0);
    $default_months_max = (int) kratos_option('archives_sc_months_max', 24);
    $default_tags_max   = (int) kratos_option('archives_sc_tags_max', 20);
    if ($default_years_max < 0)  $default_years_max = 0;
    if ($default_months_max < 0) $default_months_max = 0;
    if ($default_tags_max < 0)   $default_tags_max = 0;

    $atts = shortcode_atts(array(
        'title'      => $default_title,
        'subtitle'   => $default_subtitle,
        'years_max'  => $default_years_max,
        'months_max' => $default_months_max,
        'tags_max'   => $default_tags_max,
        'scheme'     => 'parchment',
    ), $atts, 'archives_stats');

    $totals = kratos_archives_stats_get_totals();

    $categories = get_categories(array(
        'orderby'    => 'count',
        'order'      => 'DESC',
        'hide_empty' => true,
    ));

    $years_max = max(0, (int) $atts['years_max']);
    $years = kratos_archives_stats_get_yearly();
    if ($years_max > 0) {
        $years = array_slice($years, 0, $years_max);
    }

    $months_max = max(0, (int) $atts['months_max']);
    $months = $months_max > 0 ? kratos_archives_stats_get_monthly($months_max) : array();

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
    $svg_calendar = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
    $svg_clock = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';

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

        <!-- 时间归档（年/月 Tab，默认年）-->
        <?php if (!empty($years) || !empty($months)) { ?>
            <section class="kas-section kas-time-section">
                <header class="kas-section-head kas-time-head">
                    <span class="kas-section-icon" aria-hidden="true"><?php echo $svg_calendar; ?></span>
                    <h3 class="kas-section-title"><?php esc_html_e('时间归档', 'kratos'); ?></h3>
                    <div class="kas-tabs" role="tablist">
                        <?php if (!empty($years)) { ?>
                            <button type="button" class="kas-tab is-active" data-kas-tab="year" role="tab" aria-selected="true"><?php esc_html_e('按年份', 'kratos'); ?></button>
                        <?php } ?>
                        <?php if (!empty($months)) { ?>
                            <button type="button" class="kas-tab<?php echo empty($years) ? ' is-active' : ''; ?>" data-kas-tab="month" role="tab" aria-selected="<?php echo empty($years) ? 'true' : 'false'; ?>"><?php esc_html_e('按月份', 'kratos'); ?></button>
                        <?php } ?>
                    </div>
                </header>

                <?php if (!empty($years)) { ?>
                    <div class="kas-grid kas-grid-time kas-tab-panel" data-kas-panel="year">
                        <?php foreach ($years as $row) { ?>
                            <a class="kas-pill" href="<?php echo esc_url(get_year_link($row['year'])); ?>">
                                <span class="kas-pill-label"><?php
                                    /* translators: %d: 年份 */
                                    printf(esc_html__('%d 年', 'kratos'), $row['year']);
                                ?></span>
                                <span class="kas-pill-count"><?php
                                    printf(esc_html__('%d 篇', 'kratos'), $row['count']);
                                ?></span>
                            </a>
                        <?php } ?>
                    </div>
                <?php } ?>

                <?php if (!empty($months)) { ?>
                    <div class="kas-grid kas-grid-time kas-tab-panel<?php echo empty($years) ? '' : ' is-hidden'; ?>" data-kas-panel="month">
                        <?php foreach ($months as $row) { ?>
                            <a class="kas-pill" href="<?php echo esc_url(get_month_link($row['year'], $row['month'])); ?>">
                                <span class="kas-pill-label"><?php
                                    /* translators: 1: 年份, 2: 月份 */
                                    printf(esc_html__('%1$d 年 %2$d 月', 'kratos'), $row['year'], $row['month']);
                                ?></span>
                                <span class="kas-pill-count"><?php
                                    printf(esc_html__('%d 篇', 'kratos'), $row['count']);
                                ?></span>
                            </a>
                        <?php } ?>
                    </div>
                <?php } ?>
            </section>
        <?php } ?>

    </div>

    <script>
    (function(){
        document.querySelectorAll('.kratos-archives-shortcode .kas-time-section').forEach(function(sec){
            var tabs = sec.querySelectorAll('.kas-tab');
            var panels = sec.querySelectorAll('.kas-tab-panel');
            tabs.forEach(function(tab){
                tab.addEventListener('click', function(){
                    var key = tab.getAttribute('data-kas-tab');
                    tabs.forEach(function(t){
                        var active = t === tab;
                        t.classList.toggle('is-active', active);
                        t.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    panels.forEach(function(p){
                        p.classList.toggle('is-hidden', p.getAttribute('data-kas-panel') !== key);
                    });
                });
            });
        });
    })();
    </script>

    <style>
        /* === 归档统计短码：通用骨架（CSS 变量驱动） === */
        .kratos-archives-shortcode {
            /* 默认配色 = 主题 style.css 同源（白卡 + 浅灰底 + 蓝链接），
             * 跟首页 .article-panel 一致；皮肤激活时 §18 会重写所有变量。 */
            --kas-bg-1: #f5f5f5; --kas-bg-2: #f0f0f0; --kas-bg-3: #ebebeb;
            --kas-fg: #333; --kas-fg-soft: #555; --kas-fg-dim: #777; --kas-fg-mute: #999;
            --kas-accent: #336699; --kas-accent-2: #2B5278;
            --kas-line: rgba(0, 0, 0, .08); --kas-line-strong: rgba(0, 0, 0, .16);
            --kas-card-bg: #ffffff;
            --kas-card-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            --kas-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .10);
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
        .kratos-archives-shortcode .kas-grid-cat,
        .kratos-archives-shortcode .kas-grid-tag {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .kratos-archives-shortcode .kas-grid-time {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        /* 时间归档 Tab */
        .kratos-archives-shortcode .kas-time-head {
            flex-wrap: wrap;
            gap: 10px;
        }
        .kratos-archives-shortcode .kas-time-head .kas-section-title {
            margin-right: auto;
        }
        .kratos-archives-shortcode .kas-tabs {
            display: inline-flex;
            gap: 4px;
            padding: 3px;
            background: var(--kas-card-bg);
            border: 1px solid var(--kas-line);
            border-radius: 8px;
        }
        .kratos-archives-shortcode .kas-tab {
            appearance: none;
            background: transparent;
            border: none;
            padding: 5px 14px;
            border-radius: 6px;
            font-size: 13px;
            color: var(--kas-fg-dim);
            cursor: pointer;
            transition: background .2s ease, color .2s ease;
            font-family: inherit;
        }
        .kratos-archives-shortcode .kas-tab:hover {
            color: var(--kas-accent);
        }
        .kratos-archives-shortcode .kas-tab.is-active {
            background: var(--kas-accent);
            color: #fff;
        }
        .kratos-archives-shortcode .kas-tab-panel.is-hidden {
            display: none !important;
        }
        .kratos-archives-shortcode .kas-pill {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px;
            padding: 12px 16px;
            background: var(--kas-card-bg);
            border: 1px solid var(--kas-line);
            border-radius: 10px;
            color: var(--kas-fg-soft) !important;
            text-decoration: none !important;
            font-size: 14px;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease, background .2s ease;
        }
        .kratos-archives-shortcode .kas-pill:hover {
            transform: translateY(-1px);
            background: var(--kas-card-bg);
            border-color: var(--kas-line-strong);
            box-shadow: 0 4px 10px rgba(0, 0, 0, .08);
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
            background: var(--kas-card-bg);
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
            .kratos-archives-shortcode .kas-grid-cat,
            .kratos-archives-shortcode .kas-grid-tag,
            .kratos-archives-shortcode .kas-grid-time {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 560px) {
            .kratos-archives-shortcode .kas-header { padding: 18px 20px; }
            .kratos-archives-shortcode .kas-header-title { font-size: 19px; }
            .kratos-archives-shortcode .kas-header-divider { display: none; }
            .kratos-archives-shortcode .kas-header-subtitle { flex-basis: 100%; }
            .kratos-archives-shortcode .kas-totals {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .kratos-archives-shortcode .kas-total { padding: 16px 18px; }
            .kratos-archives-shortcode .kas-total-num { font-size: 24px; }
            .kratos-archives-shortcode .kas-section { padding: 18px 18px; }
            .kratos-archives-shortcode .kas-grid-cat,
            .kratos-archives-shortcode .kas-grid-tag,
            .kratos-archives-shortcode .kas-grid-time {
                grid-template-columns: 1fr;
            }
        }

        /* === parchment 方案：保留 class 锚点但不再画装饰，与主题默认一致 ===
         * 真正的羊皮纸/做旧效果由「黄绢」皮肤在 weekday-skins.css 中处理。 */

        /* === 暗夜模式：对齐 dark.css 中性灰白调（去米黄色调）；同步重写
         * --kas-bg-* 深卡色，避免 kas-header-icon / kas-total-icon / kas-section-icon
         * 这些吃 --kas-bg-2/-bg-3 渐变的元素在暗夜下依旧是一坨高亮浅灰。 */
        html[data-theme="dark"] .kratos-archives-shortcode {
            --kas-bg-1: #2a2e35;
            --kas-bg-2: #2a2e35;
            --kas-bg-3: #333842;
            --kas-fg: #d6d8db;
            --kas-fg-soft: #b8bbc0;
            --kas-fg-dim: #8b919a;
            --kas-fg-mute: #6f747e;
            --kas-accent: #6ea8ff;
            --kas-accent-2: #91bdff;
            --kas-line: rgba(255, 255, 255, .08);
            --kas-line-strong: rgba(255, 255, 255, .16);
            --kas-card-bg: #1c1f24;
            --kas-card-shadow: 0 1px 2px rgba(0, 0, 0, .5);
            --kas-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .55);
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('archives_stats', 'kratos_archives_stats_shortcode');

/**
 * 给应用了 page-archives.php 模板的页面注入 body class `is-kratos-archives-page`，
 * 让皮肤层精准豁免 §15 / §18 对外层 .details 的装饰，避免 shortcode 自己的卡片
 * 套在另一张卡片里的"盒子套盒子"。
 */
function kratos_archives_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-archives.php')) {
        $classes[] = 'is-kratos-archives-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_archives_body_class');
