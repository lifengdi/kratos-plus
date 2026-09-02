<?php

/**
 * 时间轴（Timeline）
 *
 * 提供:
 *   - [timeline] 短码：按发布时间倒序渲染文章，左侧年份/月份标签，右侧文章行。
 *     每行展示：标题（跳转文章）+ 日期 + 热度 + 评论数。
 *     支持分页，页码按钮沿用走心评论 (.khs-page) 的皮肤/暗夜适配样式。
 *   - 给应用了 page-timeline.php 模板的页面注入 body class
 *     `is-kratos-timeline-page`，让皮肤层豁免外层 .details 装饰，避免"盒子套盒子"。
 *
 * 配色跟随皮肤：使用 --khs-* 变量，复用 weekday-skins.css §18 已有的重映射规则。
 *
 * 后台配置：主题设置 → 时间轴配置
 *   - timeline_sc_title       页头标题
 *   - timeline_sc_subtitle    页头副标题
 *   - timeline_sc_per_page    每页条数（0 = 全部）
 *   - timeline_sc_exclude_cats 排除的分类 term_id 列表
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 解析短码 exclude_cats 参数（逗号分隔的 term_id 字符串 → int[]），
 * 未传则回落到后台配置。
 *
 * @param mixed $raw 短码参数值（字符串/数组/null）
 * @return int[]
 */
function kratos_timeline_parse_exclude_cats($raw)
{
    if ($raw === null || $raw === '' || $raw === false) {
        $opt = kratos_option('timeline_sc_exclude_cats', array());
        if (is_string($opt)) {
            $opt = array_filter(array_map('trim', explode(',', $opt)), 'strlen');
        }
        $opt = is_array($opt) ? $opt : array();
        return array_values(array_unique(array_map('intval', $opt)));
    }
    if (is_array($raw)) {
        return array_values(array_unique(array_map('intval', $raw)));
    }
    $arr = array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen');
    return array_values(array_unique(array_map('intval', $arr)));
}

/**
 * 拉取时间轴文章列表。使用 WP_Query 支持分页 + 排除分类。
 *
 * @param int   $paged        当前页码（1-based）
 * @param int   $per_page     每页条数，0 表示不分页
 * @param int[] $exclude_cats 排除的分类 term_id 列表
 * @return WP_Query
 */
function kratos_timeline_query($paged, $per_page, $exclude_cats)
{
    $args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'ignore_sticky_posts' => true,
    );
    if ($per_page > 0) {
        $args['posts_per_page'] = $per_page;
        $args['paged']          = max(1, $paged);
    } else {
        $args['posts_per_page'] = -1;
        $args['no_found_rows']  = true;
    }
    if (!empty($exclude_cats)) {
        $args['category__not_in'] = $exclude_cats;
    }
    return new WP_Query($args);
}

/**
 * [timeline] 短码主入口
 *
 * 参数（可覆盖后台默认值）：
 *   title         页头标题
 *   subtitle      页头副标题
 *   per_page      每页条数（0 = 全部）
 *   exclude_cats  排除分类 term_id 列表，逗号分隔字符串
 */
function kratos_timeline_shortcode($atts = array())
{
    $default_title    = (string) kratos_option('timeline_sc_title', __('时间轴', 'kratos'));
    $default_subtitle = (string) kratos_option('timeline_sc_subtitle', __('把每一次写作，钉在属于它的那一天', 'kratos'));
    $default_per_page = (int) kratos_option('timeline_sc_per_page', 20);
    if ($default_per_page < 0) $default_per_page = 0;

    $atts = shortcode_atts(array(
        'title'        => $default_title,
        'subtitle'     => $default_subtitle,
        'per_page'     => $default_per_page,
        'exclude_cats' => null,
    ), $atts, 'timeline');

    $per_page     = max(0, (int) $atts['per_page']);
    $exclude_cats = kratos_timeline_parse_exclude_cats($atts['exclude_cats']);

    $current_page = $per_page > 0 ? kratos_featured_current_page('page-timeline.php', 'tl_page') : 1;

    $query = kratos_timeline_query($current_page, $per_page, $exclude_cats);
    $total_pages = $per_page > 0 ? (int) $query->max_num_pages : 1;
    if ($total_pages < 1) $total_pages = 1;
    if ($current_page > $total_pages) $current_page = $total_pages;

    $title    = (string) $atts['title'];
    $subtitle = (string) $atts['subtitle'];

    ob_start();
    ?>
    <div class="kratos-timeline" id="kratos-timeline-list">
        <?php if ($title !== '' || $subtitle !== '') { ?>
            <header class="ktl-header kr-hd">
                <?php if ($title !== '') { ?>
                    <span class="ktl-title-icon kr-ico" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </span>
                    <span class="ktl-title kr-hd-title"><?php echo esc_html($title); ?></span>
                <?php } ?>
                <?php if ($subtitle !== '') { ?>
                    <?php if ($title !== '') { ?><span class="ktl-header-divider kr-hd-divider" aria-hidden="true"></span><?php } ?>
                    <p class="ktl-subtitle kr-hd-sub"><?php echo esc_html($subtitle); ?></p>
                <?php } ?>
            </header>
        <?php } ?>

        <?php if (kratos_option('heatmap_enabled', true) && kratos_option('heatmap_on_timeline', true)) {
            echo do_shortcode('[post_heatmap title=""]');
        } ?>

        <?php if (!$query->have_posts()) { ?>
            <div class="ktl-empty">
                <?php esc_html_e('暂时还没有文章，敬请期待 ✍️', 'kratos'); ?>
            </div>
        <?php } else { ?>
            <?php
            $rows = array();
            while ($query->have_posts()) {
                $query->the_post();
                $post_id   = get_the_ID();
                $timestamp = (int) get_post_time('U', false);
                $rows[] = array(
                    'id'       => $post_id,
                    'title'    => get_the_title($post_id),
                    'link'     => get_permalink($post_id),
                    'year'     => (int) get_the_date('Y'),
                    'month'    => (int) get_the_date('n'),
                    'md'       => get_the_date('m-d'),
                    'views'    => (int) get_post_meta($post_id, 'views', true),
                    'comments' => (int) get_comments_number($post_id),
                );
            }
            wp_reset_postdata();

            // 按 年 → 月 分组渲染（本页范围内）
            $current_year  = null;
            $current_month = null;
            ?>
            <div class="ktl-body kr-body">
                <div class="ktl-spine" aria-hidden="true"></div>
                <?php foreach ($rows as $row) {
                    $y = $row['year'];
                    $m = $row['month'];
                    if ($current_year !== $y) {
                        if ($current_year !== null) {
                            echo '</div>'; // .ktl-month
                            echo '</div>'; // .ktl-year
                        }
                        $current_year  = $y;
                        $current_month = null;
                        echo '<div class="ktl-year">';
                        echo '<div class="ktl-year-head">'
                            . '<span class="ktl-year-badge">' . esc_html($y) . '</span>'
                            . '<span class="ktl-year-dot kr-dot" aria-hidden="true"></span>'
                            . '</div>';
                    }
                    if ($current_month !== $m) {
                        if ($current_month !== null) {
                            echo '</div>'; // .ktl-month
                        }
                        $current_month = $m;
                        $month_label = sprintf('%d - %02d', $y, $m);
                        echo '<div class="ktl-month">';
                        echo '<div class="ktl-month-head">'
                            . '<span class="ktl-month-label">' . esc_html($month_label) . '</span>'
                            . '<span class="ktl-month-dot kr-dot" aria-hidden="true"></span>'
                            . '</div>';
                    } ?>
                    <div class="ktl-item">
                        <span class="ktl-item-dot kr-dot" aria-hidden="true"></span>
                        <a class="ktl-item-title" href="<?php echo esc_url($row['link']); ?>"><?php echo esc_html($row['title']); ?></a>
                        <span class="ktl-item-meta">
                            <span class="ktl-item-date"><?php echo esc_html($row['md']); ?></span>
                            <span class="ktl-item-sep">/</span>
                            <span class="ktl-item-views"><?php
                                /* translators: %d: 热度数量 */
                                printf(esc_html__('%d 点热度', 'kratos'), $row['views']);
                            ?></span>
                            <span class="ktl-item-sep">/</span>
                            <span class="ktl-item-comments"><?php
                                /* translators: %d: 评论数量 */
                                printf(esc_html__('%d 条评论', 'kratos'), $row['comments']);
                            ?></span>
                        </span>
                    </div>
                <?php } ?>
                <?php if ($current_year !== null) {
                    echo '</div>'; // .ktl-month
                    echo '</div>'; // .ktl-year
                } ?>
            </div>

            <?php if ($total_pages > 1) {
                $build = function ($page) {
                    return esc_url(kratos_featured_page_url($page, 'page-timeline.php', 'tl_page', '#kratos-timeline-list'));
                };
                $window = 2;
                $start  = max(1, $current_page - $window);
                $end    = min($total_pages, $current_page + $window);
                if ($end - $start < $window * 2) {
                    if ($start === 1) $end = min($total_pages, $start + $window * 2);
                    if ($end === $total_pages) $start = max(1, $end - $window * 2);
                }
            ?>
                <nav class="ktl-pagination khs-pagination" aria-label="<?php esc_attr_e('时间轴分页', 'kratos'); ?>">
                    <?php if ($current_page > 1) { ?>
                        <a class="khs-page khs-page-nav" href="<?php echo $build($current_page - 1); ?>" rel="prev">
                            &laquo; <?php esc_html_e('上一页', 'kratos'); ?>
                        </a>
                    <?php } ?>

                    <?php if ($start > 1) { ?>
                        <a class="khs-page" href="<?php echo $build(1); ?>">1</a>
                        <?php if ($start > 2) { ?>
                            <span class="khs-page khs-ellipsis">…</span>
                        <?php } ?>
                    <?php } ?>

                    <?php for ($p = $start; $p <= $end; $p++) {
                        if ($p === $current_page) { ?>
                            <span class="khs-page khs-current"><?php echo (int) $p; ?></span>
                        <?php } else { ?>
                            <a class="khs-page" href="<?php echo $build($p); ?>"><?php echo (int) $p; ?></a>
                    <?php }
                    } ?>

                    <?php if ($end < $total_pages) { ?>
                        <?php if ($end < $total_pages - 1) { ?>
                            <span class="khs-page khs-ellipsis">…</span>
                        <?php } ?>
                        <a class="khs-page" href="<?php echo $build($total_pages); ?>"><?php echo (int) $total_pages; ?></a>
                    <?php } ?>

                    <?php if ($current_page < $total_pages) { ?>
                        <a class="khs-page khs-page-nav" href="<?php echo $build($current_page + 1); ?>" rel="next">
                            <?php esc_html_e('下一页', 'kratos'); ?> &raquo;
                        </a>
                    <?php } ?>
                </nav>
            <?php } ?>
        <?php } ?>
    </div>

    <style>
        /* === 时间轴短码：与走心评论 / 归档统计同源，靠 --khs-* 变量驱动皮肤 === */
        .kratos-timeline {
            --khs-bg-1: #f5f5f5; --khs-bg-2: #f0f0f0; --khs-bg-3: #ebebeb;
            --khs-fg: #333; --khs-fg-soft: #444; --khs-fg-dim: #777; --khs-fg-mute: #999;
            --khs-accent: #336699; --khs-accent-2: #2B5278;
            --khs-line: rgba(0, 0, 0, .08); --khs-line-strong: #999;
            --khs-card-bg: #ffffff;
            --khs-card-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            --khs-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .10);
            --khs-page-on: #ffffff;
            padding: 0;
            position: relative;
            color: var(--khs-fg);
        }
        .kratos-timeline > * { position: relative; z-index: 1; }

        /* 页头卡片：横排 图标 + 标题 + 分隔线 + 副标题 */
        .kratos-timeline .ktl-header {
            display: flex; align-items: center; flex-wrap: wrap; gap: 14px;
            padding: 24px 28px; margin-bottom: 24px;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 14px;
            box-shadow: var(--khs-card-shadow);
        }
        .kratos-timeline .ktl-title-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 38px; height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--khs-bg-2) 0%, var(--khs-bg-3) 100%);
            color: var(--khs-accent);
        }
        .kratos-timeline .ktl-title {
            margin: 0; padding: 0;
            font-size: 22px; font-weight: 700; line-height: 1.3;
            color: var(--khs-fg);
        }
        .kratos-timeline .ktl-header-divider {
            display: inline-block; width: 1px; height: 22px;
            background: var(--khs-line-strong);
        }
        .kratos-timeline .ktl-subtitle {
            margin: 0; padding: 0;
            font-size: 14px; line-height: 1.5;
            color: var(--khs-fg-soft);
        }

        .kratos-timeline .ktl-empty {
            padding: 36px 16px;
            text-align: center;
            color: var(--khs-fg-dim); font-size: 14px;
            background: var(--khs-card-bg);
            border: 1px dashed var(--khs-line-strong);
            border-radius: 12px;
        }

        /* 主体：卡片背景 + 左侧标签 + 竖线 + 右侧内容
         * 卡片配色与 .ktl-header 一致（--khs-card-bg / --khs-line / --khs-card-shadow）。
         *
         * 几何：padding-left 从 128 → 152，把内容整体右移 24px，让年/月标签
         * 距卡片内缘有呼吸空间；相应地 .ktl-spine 也右移 24（120→144）。
         * .ktl-year-head / .ktl-month-head 的 margin-left 保持 -128，正好落在
         * 「卡片内缘 + 24px」的位置。 */
        .kratos-timeline .ktl-body {
            position: relative;
            padding: 26px 28px 26px 152px;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 14px;
            box-shadow: var(--khs-card-shadow);
        }
        .kratos-timeline .ktl-spine {
            position: absolute;
            top: 22px; bottom: 40px;
            left: 144px;
            width: 2px;
            background: var(--khs-line-strong);
            opacity: .55;
            border-radius: 1px;
        }

        .kratos-timeline .ktl-year {
            position: relative;
            padding: 0 0 6px;
        }
        .kratos-timeline .ktl-year-head {
            position: relative;
            margin: 4px 0 18px -128px;
            height: 34px;
            display: flex; align-items: center;
        }
        .kratos-timeline .ktl-year-badge {
            display: inline-flex; align-items: center;
            padding: 4px 14px;
            font-size: 22px; font-weight: 700;
            color: var(--khs-fg);
            background: var(--khs-bg-2);
            border-radius: 6px;
            line-height: 1.4;
            letter-spacing: 0.5px;
        }
        /* 年份圆点：坐落于 spine 中心（桌面 145、移动 99），比月份圆点更大
         * 桌面：head 起点 24，left = 145 - 24 - 9 = 112
         * 移动：见下方 @media */
        .kratos-timeline .ktl-year-dot {
            position: absolute;
            left: 112px;
            top: 50%;
            width: 18px; height: 18px;
            margin-top: -9px;
            border-radius: 50%;
            background: var(--khs-accent, var(--khs-line-strong));
            z-index: 2;
            box-sizing: border-box;
        }

        .kratos-timeline .ktl-month {
            position: relative;
            padding: 0 0 18px;
        }
        .kratos-timeline .ktl-month-head {
            position: relative;
            margin: 6px 0 10px -128px;
            display: flex; align-items: center; gap: 12px;
            min-height: 22px;
        }
        .kratos-timeline .ktl-month-label {
            display: inline-flex; align-items: center;
            width: 88px;
            font-size: 15px; font-weight: 600;
            color: var(--khs-fg-soft);
            line-height: 1.4;
            letter-spacing: 0.3px;
        }
        /* 月份圆点：桌面 head 起点 24，left = 145 - 24 - 7 = 114 */
        .kratos-timeline .ktl-month-dot {
            position: absolute;
            left: 114px;
            top: 50%;
            width: 14px; height: 14px;
            margin-top: -7px;
            border-radius: 50%;
            background: var(--khs-line-strong);
            z-index: 2;
            box-sizing: border-box;
        }

        .kratos-timeline .ktl-item {
            position: relative;
            display: flex; align-items: center; flex-wrap: wrap; gap: 12px;
            padding: 6px 4px 6px 20px;
            line-height: 1.7;
        }
        .kratos-timeline .ktl-item-dot {
            position: absolute;
            /* 与 .ktl-spine（left:144, w:2, 中心 145）对齐：
             *   dot 中心 = 父 padding-left + left + width/2
             *   桌面：152 + (-12) + 5 = 145 ✓
             *   移动：106 + (-12) + 5 = 99  ✓ */
            left: -11px;
            top: 50%;
            width: 8px; height: 8px;
            margin-top: -4px;
            border-radius: 50%;
            background: var(--khs-line-strong);
            box-shadow: 0 0 0 3px var(--khs-card-bg);
            box-sizing: border-box;
            z-index: 2;
            transition: border-color .2s ease, background .2s ease, transform .2s ease;
        }
        .kratos-timeline .ktl-item:hover .ktl-item-dot {
            border-color: var(--khs-accent);
            background: var(--khs-accent);
            transform: scale(1.15);
        }
        .kratos-timeline .ktl-item-title {
            font-size: 15px; font-weight: 500;
            color: var(--khs-fg) !important;
            text-decoration: none !important;
            transition: color .2s ease;
        }
        .kratos-timeline .ktl-item-title:hover {
            color: var(--khs-accent) !important;
        }
        .kratos-timeline .ktl-item-meta {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 13px;
            color: var(--khs-fg-mute);
        }
        .kratos-timeline .ktl-item-sep {
            opacity: .5;
        }

        /* 分页：复用 .khs-page*，无需重写 */
        .kratos-timeline .ktl-pagination {
            margin-top: 26px;
        }
        /* 分页按钮：在时间轴容器内也提供一份 fallback（页面里只有 [timeline]
         * 而没有 [heart_comments] 时，避免依赖走心样式表） */
        .kratos-timeline .khs-page {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 34px; height: 34px; padding: 0 12px;
            font-size: 13px;
            color: var(--khs-fg-soft) !important;
            background: var(--khs-card-bg);
            border: 1px solid var(--khs-line);
            border-radius: 2px;
            text-decoration: none !important;
            transition: background .2s ease, color .2s ease, border-color .2s ease;
        }
        .kratos-timeline .khs-page:hover {
            background: var(--khs-accent);
            color: var(--khs-page-on) !important;
            border-color: var(--khs-accent);
        }
        .kratos-timeline .khs-current {
            background: var(--khs-accent);
            color: var(--khs-page-on) !important;
            border-color: var(--khs-accent);
            cursor: default; font-weight: 600;
        }
        .kratos-timeline .khs-ellipsis {
            border-color: transparent; background: transparent;
            cursor: default; color: var(--khs-fg-mute);
        }
        .kratos-timeline .khs-ellipsis:hover {
            background: transparent;
            color: var(--khs-fg-mute) !important;
            border-color: transparent;
        }
        .kratos-timeline .khs-pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 6px; flex-wrap: wrap;
        }

        /* 响应式 */
        @media (max-width: 720px) {
            /* 移动端：卡内左 padding 从 152 → 106；spine 从 144 → 98（中心=99）；
             * item dot center = 106 + (-12) + 5 = 99 ✓；month dot 也随之左移。 */
            .kratos-timeline .ktl-body { padding: 22px 18px 22px 106px; }
            .kratos-timeline .ktl-spine { left: 98px; top: 18px; bottom: 18px; }
            .kratos-timeline .ktl-year-head { margin-left: -88px; }
            .kratos-timeline .ktl-month-head { margin-left: -88px; gap: 8px; }
            .kratos-timeline .ktl-month-label { width: 66px; font-size: 13px; }
            .kratos-timeline .ktl-month-dot { left: 74px; }
            .kratos-timeline .ktl-year-dot  { left: 72px; }
            /* 窄屏左栏（badge 起点 x≈18 → 圆点左缘 x=90，仅 ~72px）放不下 18px
             * 加字距的年份，皮肤又给 badge 叠了 letter-spacing:2px+边框，会压到竖线
             * 圆点上。收到 16px + 紧内距，让 badge 稳落在圆点左侧（不动几何/圆点位置）。 */
            .kratos-timeline .ktl-year-badge { font-size: 16px; padding: 2px 8px; }
            .kratos-timeline .ktl-item { gap: 8px; }
            .kratos-timeline .ktl-item-title { font-size: 14px; }
            .kratos-timeline .ktl-item-meta { font-size: 12px; width: 100%; }
        }
        @media (max-width: 480px) {
            .kratos-timeline .ktl-header { padding: 18px 20px; gap: 10px; }
            .kratos-timeline .ktl-title { font-size: 19px; }
            .kratos-timeline .ktl-header-divider { display: none; }
            .kratos-timeline .ktl-subtitle { flex-basis: 100%; font-size: 13px; }
        }

        /* 暗夜模式：对齐 dark.css 中性灰白调；同步把 --khs-bg-* 从浅灰改成深卡色，
         * 否则 ktl-title-icon / ktl-year-badge 这类吃 --khs-bg-2/-bg-3 渐变的元素
         * 在暗夜下仍是一坨高亮浅灰，与深卡对比刺眼。 */
        html[data-theme="dark"] .kratos-timeline,
        body.dark .kratos-timeline {
            --khs-bg-1: #2a2e35;
            --khs-bg-2: #2a2e35;
            --khs-bg-3: #333842;
            --khs-fg: #d6d8db;
            --khs-fg-soft: #b8bbc0;
            --khs-fg-dim: #8b919a;
            --khs-fg-mute: #6f747e;
            --khs-accent: #6ea8ff;
            --khs-accent-2: #91bdff;
            --khs-line: rgba(255, 255, 255, .08);
            --khs-line-strong: rgba(255, 255, 255, .16);
            --khs-card-bg: #1c1f24;
            --khs-card-shadow: 0 1px 2px rgba(0, 0, 0, .5);
            --khs-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .55);
        }
        html[data-theme="dark"] .kratos-timeline .ktl-year-badge,
        body.dark .kratos-timeline .ktl-year-badge {
            background: rgba(255, 255, 255, .06);
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('timeline', 'kratos_timeline_shortcode');

/**
 * 给应用了 page-timeline.php 模板的页面注入 body class
 * `is-kratos-timeline-page`，让皮肤层豁免外层 .details 的装饰。
 */
function kratos_timeline_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-timeline.php')) {
        $classes[] = 'is-kratos-timeline-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_timeline_body_class');
