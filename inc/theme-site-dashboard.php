<?php

/**
 * 站点数据看板 [site_dashboard]
 *
 * 把散落在各短代码里的数据层聚成一页「关于本站」：
 *   - 总览卡：建站天数 / 文章总数 / 总字数 / 评论总数 / 说说数 / 平均更新间隔
 *   - 近 N 天发布节奏（纯 CSS 柱状图，不引任何图表库）
 *   - 年度产出（复用 kratos_archives_stats_get_yearly）
 *   - 分类占比（进度条）
 *   - 最勤评论者（复用 kratos_top_commenters_get）
 *   - 可选内嵌评论者地域分布（[comment_geo header="no"]）
 *
 * 复用而非重写：
 *   - 年份统计   → inc/theme-archives-stats.php
 *   - 评论人排行 → inc/theme-comment-topcommenters.php
 *   - 字数统计   → inc/theme-reading-enhance.php 的 kratos_read_word_count()
 *   - 地域分布   → inc/theme-comment-geo.php
 *   - 建站日期   → 与年度回顾共用主题选项 `site_birthday`
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** 聚合缓存 key。 */
function kratos_dash_cache_key()
{
    return 'kratos_dashboard_agg_v1';
}

/** 缓存失效：发文 / 删文 / 评论变动。 */
function kratos_dash_flush_cache()
{
    delete_transient(kratos_dash_cache_key());
}
add_action('save_post', 'kratos_dash_flush_cache');
add_action('deleted_post', 'kratos_dash_flush_cache');
add_action('comment_post', 'kratos_dash_flush_cache');
add_action('wp_set_comment_status', 'kratos_dash_flush_cache');

/**
 * 全站总字数。
 *
 * 一次取出所有已发布文章正文逐篇计数，成本较高（大站可能几千篇），
 * 因此整个聚合结果走 transient，且此项可在后台单独关掉。
 *
 * @return int
 */
function kratos_dash_total_words()
{
    if (!kratos_option('g_dash_words', true) || !function_exists('kratos_read_word_count')) {
        return 0;
    }

    global $wpdb;
    $rows = $wpdb->get_col(
        "SELECT post_content FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status = 'publish'"
    );
    if (!is_array($rows)) {
        return 0;
    }

    $total = 0;
    foreach ($rows as $content) {
        $total += (int) kratos_read_word_count($content);
    }
    return $total;
}

/**
 * 近 N 天每天的发文数（含 0 的空日，便于画连续柱状图）。
 *
 * @param int $days
 * @return array<int, array{date:string, label:string, count:int}>
 */
function kratos_dash_recent_days($days = 30)
{
    $days = max(7, min(120, (int) $days));

    global $wpdb;
    $since = wp_date('Y-m-d', time() - ($days - 1) * DAY_IN_SECONDS);

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DATE(post_date) AS d, COUNT(*) AS c
             FROM {$wpdb->posts}
             WHERE post_type = 'post' AND post_status = 'publish'
               AND DATE(post_date) >= %s
             GROUP BY d",
            $since
        ),
        ARRAY_A
    );

    $map = array();
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $map[(string) $r['d']] = (int) $r['c'];
        }
    }

    $out = array();
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts = time() - $i * DAY_IN_SECONDS;
        $d  = wp_date('Y-m-d', $ts);
        $out[] = array(
            'date'  => $d,
            'label' => wp_date('n/j', $ts),
            'count' => isset($map[$d]) ? $map[$d] : 0,
        );
    }
    return $out;
}

/**
 * 聚合看板数据。
 *
 * @param bool $force
 * @return array
 */
function kratos_dash_aggregate($force = false)
{
    $key = kratos_dash_cache_key();
    if (!$force) {
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    global $wpdb;

    $posts = wp_count_posts('post');
    $post_total = isset($posts->publish) ? (int) $posts->publish : 0;

    $comments = wp_count_comments();
    $comment_total = isset($comments->approved) ? (int) $comments->approved : 0;

    $shuoshuo_total = 0;
    if (post_type_exists('shuoshuo')) {
        $ss = wp_count_posts('shuoshuo');
        $shuoshuo_total = isset($ss->publish) ? (int) $ss->publish : 0;
    }

    // 首篇 / 末篇发布时间 —— 用于建站天数兜底与平均更新间隔
    $first_ts = (int) strtotime((string) $wpdb->get_var(
        "SELECT post_date FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status = 'publish'
         ORDER BY post_date ASC LIMIT 1"
    ));
    $last_ts = (int) strtotime((string) $wpdb->get_var(
        "SELECT post_date FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status = 'publish'
         ORDER BY post_date DESC LIMIT 1"
    ));

    // 建站日期优先取主题选项（与年度回顾共用），未填则回落到首篇发布时间
    $birthday = trim((string) kratos_option('site_birthday', ''));
    $start_ts = $birthday !== '' ? (int) strtotime($birthday) : $first_ts;
    $run_days = $start_ts > 0 ? max(1, (int) floor((current_time('timestamp') - $start_ts) / DAY_IN_SECONDS)) : 0;

    // 平均更新间隔：从首篇到末篇的跨度 / 间隔数（篇数-1），不足两篇则为 0
    $avg_gap = 0.0;
    if ($post_total > 1 && $first_ts > 0 && $last_ts > $first_ts) {
        $avg_gap = round((($last_ts - $first_ts) / DAY_IN_SECONDS) / ($post_total - 1), 1);
    }

    $cats = get_categories(array(
        'orderby'    => 'count',
        'order'      => 'DESC',
        'hide_empty' => true,
    ));
    $cat_rows = array();
    foreach ($cats as $c) {
        $cat_rows[] = array(
            'name'  => $c->name,
            'count' => (int) $c->count,
            'url'   => get_category_link($c->term_id),
        );
    }

    $years = function_exists('kratos_archives_stats_get_yearly')
        ? kratos_archives_stats_get_yearly()
        : array();

    $data = array(
        'posts'       => $post_total,
        'comments'    => $comment_total,
        'shuoshuo'    => $shuoshuo_total,
        'words'       => kratos_dash_total_words(),
        'run_days'    => $run_days,
        'start'       => $start_ts > 0 ? wp_date('Y-m-d', $start_ts) : '',
        'avg_gap'     => $avg_gap,
        'last_post'   => $last_ts > 0 ? wp_date('Y-m-d', $last_ts) : '',
        'cats'        => $cat_rows,
        'years'       => $years,
        'days'        => kratos_dash_recent_days((int) kratos_option('g_dash_days', 30)),
        'generated'   => time(),
    );

    $ttl = max(5, (int) kratos_option('g_dash_cache_min', 180)) * MINUTE_IN_SECONDS;
    set_transient($key, $data, $ttl);

    return $data;
}

/** 单张总览卡。 */
function kratos_dash_stat_html($label, $value, $svg, $unit = '')
{
    $shown = is_numeric($value) ? number_format_i18n($value) : $value;
    return '<div class="kdb-stat kr-card"><span class="kdb-stat-icon kr-ico" aria-hidden="true">' . $svg . '</span>'
        . '<div class="kdb-stat-body">'
        . '<div class="kdb-stat-label">' . esc_html($label) . '</div>'
        . '<div class="kdb-stat-num">' . esc_html($shown)
        . ($unit !== '' ? '<span class="kdb-stat-unit">' . esc_html($unit) . '</span>' : '')
        . '</div>'
        . '</div></div>';
}

/** 一行「名称 + 进度条 + 数量」。 */
function kratos_dash_bar_html($name, $count, $max, $url = '')
{
    $pct = $max > 0 ? round($count / $max * 100, 1) : 0;
    $inner = '<span class="kdb-bar-name">' . esc_html($name) . '</span>'
        . '<span class="kdb-bar"><span class="kdb-bar-fill" style="width:' . esc_attr($pct) . '%"></span></span>'
        . '<span class="kdb-bar-num">' . esc_html(number_format_i18n($count)) . '</span>';

    return $url !== ''
        ? '<a class="kdb-bar-row" href="' . esc_url($url) . '">' . $inner . '</a>'
        : '<div class="kdb-bar-row">' . $inner . '</div>';
}

/**
 * [site_dashboard] 短码。
 *
 *   title / subtitle  页头文案
 *   days              近 N 天发布节奏（7~120，默认后台配置）
 *   cats_max          分类占比条数（0 = 不展示）
 *   years_max         年度产出条数（0 = 不展示）
 *   commenters_max    最勤评论者条数（0 = 不展示）
 *   geo               是否内嵌评论地域分布：yes/no（默认按后台配置）
 *   header            是否输出页头卡：yes/no
 */
function kratos_dash_shortcode($atts = array())
{
    $atts = shortcode_atts(array(
        'title'          => (string) kratos_option('g_dash_title', __('站点数据看板', 'kratos')),
        'subtitle'       => (string) kratos_option('g_dash_subtitle', __('这个博客到今天为止，长成了什么样子 📊', 'kratos')),
        'days'           => (int) kratos_option('g_dash_days', 30),
        'cats_max'       => (int) kratos_option('g_dash_cats_max', 10),
        'years_max'      => (int) kratos_option('g_dash_years_max', 8),
        'commenters_max' => (int) kratos_option('g_dash_commenters_max', 5),
        'geo'            => kratos_option('g_dash_geo', true) ? 'yes' : 'no',
        'header'         => 'yes',
    ), $atts, 'site_dashboard');

    $data = kratos_dash_aggregate();

    $cats_max = max(0, (int) $atts['cats_max']);
    $cats = $cats_max > 0 ? array_slice($data['cats'], 0, $cats_max) : array();

    $years_max = max(0, (int) $atts['years_max']);
    $years = $years_max > 0 ? array_slice($data['years'], 0, $years_max) : array();

    $commenters_max = max(0, (int) $atts['commenters_max']);
    $commenters = ($commenters_max > 0 && function_exists('kratos_top_commenters_get'))
        ? kratos_top_commenters_get($commenters_max)
        : array();

    // 近 N 天：短码参数可覆盖后台天数，覆盖时重算（聚合缓存里存的是后台默认天数）
    $days_want = max(7, min(120, (int) $atts['days']));
    $days = (count($data['days']) === $days_want) ? $data['days'] : kratos_dash_recent_days($days_want);
    $day_max = 0;
    foreach ($days as $d) {
        if ($d['count'] > $day_max) {
            $day_max = $d['count'];
        }
    }
    $days_total = 0;
    foreach ($days as $d) {
        $days_total += $d['count'];
    }

    $svg_chart  = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>';
    $svg_cake   = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s1.5-1 4-1 4 1 4 1 1.5-1 4-1 4 1 4 1"/><line x1="12" y1="4" x2="12" y2="7"/></svg>';
    $svg_doc    = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
    $svg_pen    = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';
    $svg_chat   = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    $svg_clock  = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
    $svg_folder = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
    $svg_cal    = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
    $svg_users  = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>';
    $svg_pulse  = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>';

    ob_start();
    ?>
    <div class="kratos-dashboard">
        <?php if ($atts['header'] !== 'no') { ?>
            <header class="kdb-header kr-hd">
                <span class="kdb-header-icon kr-ico" aria-hidden="true"><?php echo $svg_chart; ?></span>
                <h2 class="kdb-header-title kr-hd-title"><?php echo esc_html($atts['title']); ?></h2>
                <?php if ($atts['subtitle'] !== '') { ?>
                    <span class="kdb-header-divider kr-hd-divider" aria-hidden="true"></span>
                    <p class="kdb-header-subtitle kr-hd-sub"><?php echo esc_html($atts['subtitle']); ?></p>
                <?php } ?>
            </header>
        <?php } ?>

        <!-- 总览卡 -->
        <div class="kdb-stats">
            <?php
            echo kratos_dash_stat_html(__('已经运行', 'kratos'), $data['run_days'], $svg_cake, __('天', 'kratos'));
            echo kratos_dash_stat_html(__('文章总数', 'kratos'), $data['posts'], $svg_doc, __('篇', 'kratos'));
            if ($data['words'] > 0) {
                echo kratos_dash_stat_html(__('累计字数', 'kratos'), $data['words'], $svg_pen, __('字', 'kratos'));
            }
            echo kratos_dash_stat_html(__('评论总数', 'kratos'), $data['comments'], $svg_chat, __('条', 'kratos'));
            if ($data['shuoshuo'] > 0) {
                echo kratos_dash_stat_html(__('说说条数', 'kratos'), $data['shuoshuo'], $svg_pulse, __('条', 'kratos'));
            }
            if ($data['avg_gap'] > 0) {
                echo kratos_dash_stat_html(__('平均更新间隔', 'kratos'), $data['avg_gap'], $svg_clock, __('天/篇', 'kratos'));
            }
            ?>
        </div>

        <!-- 近 N 天发布节奏 -->
        <section class="kdb-section kr-card">
            <header class="kdb-section-head">
                <span class="kdb-section-icon kr-ico" aria-hidden="true"><?php echo $svg_cal; ?></span>
                <h3 class="kdb-section-title"><?php
                    /* translators: %d: 天数 */
                    printf(esc_html__('近 %d 天发布节奏', 'kratos'), count($days));
                ?></h3>
                <span class="kdb-section-note"><?php
                    /* translators: %s: 篇数 */
                    printf(esc_html__('共 %s 篇', 'kratos'), number_format_i18n($days_total));
                ?></span>
            </header>
            <?php if ($day_max === 0) { ?>
                <p class="kdb-note"><?php esc_html_e('这段时间还没有新文章。', 'kratos'); ?></p>
            <?php } else { ?>
                <div class="kdb-chart" role="img" aria-label="<?php esc_attr_e('近期发布数量柱状图', 'kratos'); ?>">
                    <?php foreach ($days as $d) {
                        // 有文章的日子至少给 8% 高度，保证一篇也看得见
                        $h = $d['count'] > 0 ? max(8, round($d['count'] / $day_max * 100)) : 0; ?>
                        <span class="kdb-chart-col" title="<?php echo esc_attr($d['date'] . ' · ' . sprintf(__('%s 篇', 'kratos'), number_format_i18n($d['count']))); ?>">
                            <span class="kdb-chart-bar<?php echo $d['count'] > 0 ? '' : ' is-zero'; ?>" style="height:<?php echo esc_attr($h); ?>%"></span>
                        </span>
                    <?php } ?>
                </div>
                <div class="kdb-chart-axis">
                    <span><?php echo esc_html($days[0]['label']); ?></span>
                    <span><?php echo esc_html($days[count($days) - 1]['label']); ?></span>
                </div>
            <?php } ?>
        </section>

        <!-- 年度产出 -->
        <?php if (!empty($years)) {
            $ymax = 0;
            foreach ($years as $y) {
                if ((int) $y['count'] > $ymax) $ymax = (int) $y['count'];
            } ?>
            <section class="kdb-section kr-card">
                <header class="kdb-section-head">
                    <span class="kdb-section-icon kr-ico" aria-hidden="true"><?php echo $svg_cal; ?></span>
                    <h3 class="kdb-section-title"><?php esc_html_e('年度产出', 'kratos'); ?></h3>
                </header>
                <div class="kdb-bars">
                    <?php foreach ($years as $y) {
                        echo kratos_dash_bar_html(
                            sprintf(__('%d 年', 'kratos'), (int) $y['year']),
                            (int) $y['count'],
                            $ymax,
                            get_year_link((int) $y['year'])
                        );
                    } ?>
                </div>
            </section>
        <?php } ?>

        <!-- 分类占比 -->
        <?php if (!empty($cats)) {
            $cmax = (int) $cats[0]['count']; ?>
            <section class="kdb-section kr-card">
                <header class="kdb-section-head">
                    <span class="kdb-section-icon kr-ico" aria-hidden="true"><?php echo $svg_folder; ?></span>
                    <h3 class="kdb-section-title"><?php esc_html_e('分类占比', 'kratos'); ?></h3>
                </header>
                <div class="kdb-bars">
                    <?php foreach ($cats as $c) {
                        echo kratos_dash_bar_html($c['name'], (int) $c['count'], $cmax, (string) $c['url']);
                    } ?>
                </div>
            </section>
        <?php } ?>

        <!-- 最勤评论者 -->
        <?php if (!empty($commenters)) {
            $mmax = (int) $commenters[0]['count']; ?>
            <section class="kdb-section kr-card">
                <header class="kdb-section-head">
                    <span class="kdb-section-icon kr-ico" aria-hidden="true"><?php echo $svg_users; ?></span>
                    <h3 class="kdb-section-title"><?php esc_html_e('最勤评论者', 'kratos'); ?></h3>
                </header>
                <div class="kdb-bars">
                    <?php foreach ($commenters as $m) {
                        echo kratos_dash_bar_html((string) $m['name'], (int) $m['count'], $mmax);
                    } ?>
                </div>
            </section>
        <?php } ?>

        <!-- 评论地域分布（内嵌，不重复输出页头） -->
        <?php if ($atts['geo'] === 'yes' && shortcode_exists('comment_geo')) {
            echo do_shortcode('[comment_geo header="no"]');
        } ?>

        <?php if (kratos_option('g_dash_show_updated', true)) { ?>
            <p class="kdb-updated"><?php
                printf(
                    /* translators: %s: 时间 */
                    esc_html__('数据更新于 %s', 'kratos'),
                    esc_html(wp_date('Y-m-d H:i', (int) $data['generated']))
                );
            ?></p>
        <?php } ?>

        <style>
            /* === 站点数据看板：通用骨架（--khs-* 变量驱动，皮肤由 components.css 别名层接管） === */
            .kratos-dashboard {
                --khs-fg: #333; --khs-fg-soft: #555; --khs-fg-dim: #777;
                --khs-accent: #336699;
                --khs-line: rgba(0, 0, 0, .08); --khs-line-strong: rgba(0, 0, 0, .16);
                --khs-card-bg: #ffffff;
                --khs-bg-2: #f0f0f0; --khs-bg-3: #ebebeb;
                --khs-card-shadow: 0 1px 3px rgba(0, 0, 0, .06);
                --khs-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .10);
                color: var(--khs-fg);
            }

            .kratos-dashboard .kdb-header {
                display: flex; align-items: center; flex-wrap: wrap; gap: 14px;
                padding: 24px 28px; margin-bottom: 18px;
                background: var(--khs-card-bg);
                border: 1px solid var(--khs-line);
                border-radius: 14px;
                box-shadow: var(--khs-card-shadow);
            }
            .kratos-dashboard .kdb-header-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 38px; height: 38px; border-radius: 10px;
                background: linear-gradient(135deg, var(--khs-bg-2) 0%, var(--khs-bg-3) 100%);
                color: var(--khs-accent);
            }
            .kratos-dashboard .kdb-header-title {
                margin: 0; font-size: 22px; font-weight: 700; line-height: 1.3; color: var(--khs-fg);
            }
            .kratos-dashboard .kdb-header-divider {
                display: inline-block; width: 1px; height: 22px; background: var(--khs-line-strong);
            }
            .kratos-dashboard .kdb-header-subtitle {
                margin: 0; font-size: 14px; line-height: 1.5; color: var(--khs-fg-soft);
            }

            /* 总览卡 */
            .kratos-dashboard .kdb-stats {
                display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 16px; margin-bottom: 22px;
            }
            .kratos-dashboard .kdb-stat {
                display: flex; align-items: center; gap: 14px;
                padding: 20px 22px;
                background: var(--khs-card-bg);
                border: 1px solid var(--khs-line);
                border-radius: 14px;
                box-shadow: var(--khs-card-shadow);
                transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
            }
            .kratos-dashboard .kdb-stat:hover {
                transform: translateY(-2px);
                box-shadow: var(--khs-card-shadow-hv);
                border-color: var(--khs-line-strong);
            }
            .kratos-dashboard .kdb-stat-icon {
                flex-shrink: 0;
                display: inline-flex; align-items: center; justify-content: center;
                width: 46px; height: 46px; border-radius: 50%;
                background: linear-gradient(135deg, var(--khs-bg-2) 0%, var(--khs-bg-3) 100%);
                color: var(--khs-accent);
            }
            .kratos-dashboard .kdb-stat-body { min-width: 0; }
            .kratos-dashboard .kdb-stat-label {
                font-size: 13px; line-height: 1.2; color: var(--khs-fg-dim);
            }
            .kratos-dashboard .kdb-stat-num {
                font-size: 28px; font-weight: 700; line-height: 1.15; color: var(--khs-fg);
            }
            .kratos-dashboard .kdb-stat-unit {
                margin-left: 4px; font-size: 13px; font-weight: 400; color: var(--khs-fg-dim);
            }

            /* section */
            .kratos-dashboard .kdb-section {
                padding: 22px 24px; margin-bottom: 18px;
                background: var(--khs-card-bg);
                border: 1px solid var(--khs-line);
                border-radius: 14px;
                box-shadow: var(--khs-card-shadow);
            }
            .kratos-dashboard .kdb-section-head {
                display: flex; align-items: center; gap: 10px;
                padding-bottom: 14px; margin-bottom: 14px;
                border-bottom: 1px solid var(--khs-line);
            }
            .kratos-dashboard .kdb-section-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 28px; height: 28px; color: var(--khs-accent);
            }
            .kratos-dashboard .kdb-section-title {
                margin: 0; font-size: 18px; font-weight: 700; line-height: 1.3; color: var(--khs-fg);
            }
            .kratos-dashboard .kdb-section-note {
                margin-left: auto; font-size: 12px; color: var(--khs-fg-dim);
            }
            .kratos-dashboard .kdb-note {
                margin: 0; font-size: 14px; color: var(--khs-fg-dim);
            }

            /* 柱状图 */
            .kratos-dashboard .kdb-chart {
                display: flex; align-items: flex-end; gap: 3px;
                height: 110px; padding-bottom: 2px;
            }
            .kratos-dashboard .kdb-chart-col {
                flex: 1; min-width: 0;
                display: flex; align-items: flex-end; justify-content: center;
                height: 100%;
            }
            .kratos-dashboard .kdb-chart-bar {
                display: block; width: 100%;
                min-height: 2px;
                border-radius: 3px 3px 0 0;
                background: linear-gradient(180deg,
                    var(--khs-accent) 0%,
                    color-mix(in srgb, var(--khs-accent) 55%, transparent) 100%);
                transition: opacity .2s ease;
            }
            .kratos-dashboard .kdb-chart-bar.is-zero {
                height: 2px !important;
                background: var(--khs-bg-3);
            }
            .kratos-dashboard .kdb-chart-col:hover .kdb-chart-bar { opacity: .75; }
            .kratos-dashboard .kdb-chart-axis {
                display: flex; justify-content: space-between;
                margin-top: 6px; font-size: 11px; color: var(--khs-fg-dim);
            }

            /* 进度条行：名称 + 条 + 数字
             * 名称列写 minmax(0, Nem)，否则 nowrap 文本会把轨道撑出容器 */
            .kratos-dashboard .kdb-bars { display: flex; flex-direction: column; gap: 10px; }
            .kratos-dashboard .kdb-bar-row {
                display: grid;
                grid-template-columns: minmax(0, 7em) minmax(0, 1fr) minmax(0, 3.5em);
                align-items: center; gap: 12px;
                font-size: 14px;
                text-decoration: none !important;
                color: inherit !important;
            }
            .kratos-dashboard a.kdb-bar-row:hover .kdb-bar-name { color: var(--khs-accent); }
            .kratos-dashboard .kdb-bar-name {
                color: var(--khs-fg-soft); font-weight: 600;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                transition: color .2s ease;
            }
            .kratos-dashboard .kdb-bar {
                display: block; height: 8px; border-radius: 999px;
                background: var(--khs-bg-3); overflow: hidden;
            }
            .kratos-dashboard .kdb-bar-fill {
                display: block; height: 100%; border-radius: 999px;
                background: linear-gradient(90deg,
                    color-mix(in srgb, var(--khs-accent) 55%, transparent) 0%,
                    var(--khs-accent) 100%);
                transition: width .4s ease;
            }
            .kratos-dashboard .kdb-bar-num {
                font-size: 13px; color: var(--khs-fg-dim); text-align: right;
                font-variant-numeric: tabular-nums;
            }

            .kratos-dashboard .kdb-updated {
                margin: 0; font-size: 12px; color: var(--khs-fg-dim); text-align: right;
            }

            @media (max-width: 900px) {
                .kratos-dashboard .kdb-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            @media (max-width: 560px) {
                .kratos-dashboard .kdb-header { padding: 18px 20px; }
                .kratos-dashboard .kdb-header-title { font-size: 19px; }
                .kratos-dashboard .kdb-header-divider { display: none; }
                .kratos-dashboard .kdb-header-subtitle { flex-basis: 100%; }
                .kratos-dashboard .kdb-stats { grid-template-columns: 1fr; gap: 12px; }
                .kratos-dashboard .kdb-stat { padding: 16px 18px; }
                .kratos-dashboard .kdb-stat-num { font-size: 23px; }
                .kratos-dashboard .kdb-section { padding: 18px; }
                .kratos-dashboard .kdb-chart { height: 84px; gap: 2px; }
                .kratos-dashboard .kdb-bar-row {
                    grid-template-columns: minmax(0, 5.5em) minmax(0, 1fr) minmax(0, 3em);
                    gap: 8px;
                }
            }

            html[data-theme="dark"] .kratos-dashboard {
                --khs-fg: #d6d8db; --khs-fg-soft: #b8bbc0; --khs-fg-dim: #8b919a;
                --khs-accent: #6ea8ff;
                --khs-line: rgba(255, 255, 255, .08); --khs-line-strong: rgba(255, 255, 255, .16);
                --khs-card-bg: #1c1f24;
                --khs-bg-2: #2a2e35; --khs-bg-3: #333842;
                --khs-card-shadow: 0 1px 2px rgba(0, 0, 0, .5);
                --khs-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .55);
            }
        </style>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('site_dashboard', 'kratos_dash_shortcode');

/**
 * 给应用了 page-site-dashboard.php 模板的页面注入 body class，
 * 让皮肤层豁免外层 .details 的装饰，避免「卡中卡」。
 */
function kratos_dash_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-site-dashboard.php')) {
        $classes[] = 'is-kratos-dashboard-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_dash_body_class');
