<?php

/**
 * 评论者地域分布 [comment_geo]
 *
 * 把已通过审核的评论按 IP 归属地聚合，输出「省份 / 国家地区」两张 TOP 榜 +
 * 三张总览卡。归属地解析复用 inc/ip2region 离线库（已内置，无需外部 API），
 * 走 wpcdi_get_comment_info()（inc/theme-comment-extends.php）以复用其中
 * 国家/省份/城市的中英映射表，避免在本文件重抄一份 200 行字典。
 *
 * 性能：
 *   - 先按 IP 分组聚合评论数（一条 SQL），再对「唯一 IP」逐个解析，
 *     不是对每条评论解析
 *   - 唯一 IP 数上限可配（默认 3000），超出部分按评论数排序后截断
 *   - 聚合结果写 transient（默认 12 小时），新评论通过审核时失效
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** transient key（改聚合逻辑时手动 bump 版本号即可整体失效）。 */
function kratos_comment_geo_cache_key()
{
    return 'kratos_comment_geo_agg_v1';
}

/** 清空聚合缓存。 */
function kratos_comment_geo_flush_cache()
{
    delete_transient(kratos_comment_geo_cache_key());
}
add_action('comment_post', 'kratos_comment_geo_flush_cache');
add_action('wp_set_comment_status', 'kratos_comment_geo_flush_cache');
add_action('deleted_comment', 'kratos_comment_geo_flush_cache');

/**
 * 把一个 IP 解析成 ['country' => .., 'region' => .., 'city' => ..]（中文优先）。
 *
 * 复用 wpcdi_get_comment_info() 的 location 串（形如「中国-广东-深圳」），
 * 它内部已经做了 ip2region 查询 + 国家/省/市中英映射 + 无效值清洗。
 *
 * @param string $ip
 * @return array{country:string, region:string, city:string}
 */
function kratos_comment_geo_resolve_ip($ip)
{
    $empty = array('country' => '', 'region' => '', 'city' => '');

    if (!function_exists('wpcdi_get_comment_info')) {
        return $empty;
    }

    // 第二个参数是 User-Agent，这里只关心地理位置，传空串即可
    $info = wpcdi_get_comment_info($ip, '');
    $loc  = isset($info['location']) ? trim((string) $info['location']) : '';

    // 默认值 / 失败态一律视为未知
    if ($loc === '' || $loc === '未知' || $loc === '定位失败' || $loc === '未知位置' || $loc === '本地') {
        return $empty;
    }

    $parts = array_values(array_filter(array_map('trim', explode('-', $loc)), function ($v) {
        return $v !== '' && $v !== '未知';
    }));
    if (empty($parts)) {
        return $empty;
    }

    return array(
        'country' => $parts[0],
        'region'  => isset($parts[1]) ? $parts[1] : '',
        'city'    => isset($parts[2]) ? $parts[2] : '',
    );
}

/**
 * 聚合评论地域分布。
 *
 * @param bool $force 跳过缓存重新聚合
 * @return array{
 *   regions: array<int, array{name:string, count:int}>,
 *   countries: array<int, array{name:string, count:int}>,
 *   cities: array<int, array{name:string, count:int}>,
 *   total_comments:int, resolved_comments:int, unique_ips:int,
 *   region_count:int, country_count:int, generated:int
 * }
 */
function kratos_comment_geo_aggregate($force = false)
{
    $key = kratos_comment_geo_cache_key();
    if (!$force) {
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    global $wpdb;

    $ip_max = max(1, (int) kratos_option('g_comment_geo_ip_max', 2000));

    // 一条 SQL 按 IP 聚合，避免逐条评论解析
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT comment_author_IP AS ip, COUNT(*) AS c
             FROM {$wpdb->comments}
             WHERE comment_approved = '1'
               AND comment_type IN ('', 'comment')
               AND comment_author_IP <> ''
             GROUP BY comment_author_IP
             ORDER BY c DESC
             LIMIT %d",
            $ip_max
        ),
        ARRAY_A
    );

    $regions   = array();
    $countries = array();
    $cities    = array();
    $total     = 0;
    $resolved  = 0;

    if (is_array($rows)) {
        foreach ($rows as $r) {
            $ip  = (string) $r['ip'];
            $cnt = (int) $r['c'];
            $total += $cnt;

            $geo = kratos_comment_geo_resolve_ip($ip);
            if ($geo['country'] === '') {
                continue;
            }
            $resolved += $cnt;

            $country = $geo['country'];
            $countries[$country] = (isset($countries[$country]) ? $countries[$country] : 0) + $cnt;

            // 省份榜只收中国大陆/港澳台的省级行政区；海外没有可比的省级粒度，
            // 统一按国家计入国家榜，避免榜单里混进「加利福尼亚」这类不同粒度的条目。
            $is_cn = (mb_strpos($country, '中国') === 0);
            if ($is_cn && $geo['region'] !== '') {
                $regions[$geo['region']] = (isset($regions[$geo['region']]) ? $regions[$geo['region']] : 0) + $cnt;
            }

            if ($geo['city'] !== '') {
                $cities[$geo['city']] = (isset($cities[$geo['city']]) ? $cities[$geo['city']] : 0) + $cnt;
            }
        }
    }

    arsort($regions);
    arsort($countries);
    arsort($cities);

    $flatten = function ($assoc) {
        $out = array();
        foreach ($assoc as $name => $count) {
            $out[] = array('name' => (string) $name, 'count' => (int) $count);
        }
        return $out;
    };

    $data = array(
        'regions'           => $flatten($regions),
        'countries'         => $flatten($countries),
        'cities'            => $flatten($cities),
        'total_comments'    => $total,
        'resolved_comments' => $resolved,
        'unique_ips'        => is_array($rows) ? count($rows) : 0,
        'region_count'      => count($regions),
        'country_count'     => count($countries),
        'generated'         => time(),
    );

    $ttl = max(5, (int) kratos_option('g_comment_geo_cache_min', 720)) * MINUTE_IN_SECONDS;
    set_transient($key, $data, $ttl);

    return $data;
}

/** 渲染一行「名称 + 进度条 + 数量」。 */
function kratos_comment_geo_bar_html($name, $count, $max, $rank)
{
    $pct = $max > 0 ? round($count / $max * 100, 1) : 0;
    return '<div class="kcg-row">'
        . '<span class="kcg-rank">' . esc_html($rank) . '</span>'
        . '<span class="kcg-name">' . esc_html($name) . '</span>'
        . '<span class="kcg-bar"><span class="kcg-bar-fill" style="width:' . esc_attr($pct) . '%"></span></span>'
        . '<span class="kcg-num">' . esc_html(number_format_i18n($count)) . '</span>'
        . '</div>';
}

/** 渲染一张总览卡。 */
function kratos_comment_geo_stat_html($label, $value, $svg)
{
    return '<div class="kcg-stat kr-card"><span class="kcg-stat-icon kr-ico" aria-hidden="true">' . $svg . '</span>'
        . '<div class="kcg-stat-body">'
        . '<div class="kcg-stat-label">' . esc_html($label) . '</div>'
        . '<div class="kcg-stat-num">' . esc_html(is_numeric($value) ? number_format_i18n($value) : $value) . '</div>'
        . '</div></div>';
}

/**
 * [comment_geo] 短码。
 *
 *   title      标题（默认取后台 g_comment_geo_title）
 *   subtitle   副标题
 *   regions_max   省份榜条数（默认后台配置，0 = 不展示）
 *   countries_max 国家/地区榜条数（0 = 不展示）
 *   cities_max    城市榜条数（0 = 不展示，默认 0）
 *   header     是否输出标题卡：yes/no（默认 yes；嵌在数据看板里时传 no）
 */
function kratos_comment_geo_shortcode($atts = array())
{
    $atts = shortcode_atts(array(
        'title'         => (string) kratos_option('g_comment_geo_title', __('评论者地域分布', 'kratos')),
        'subtitle'      => (string) kratos_option('g_comment_geo_subtitle', __('看看这些留言是从哪些地方寄来的 🗺', 'kratos')),
        'regions_max'   => (int) kratos_option('g_comment_geo_regions_max', 12),
        'countries_max' => (int) kratos_option('g_comment_geo_countries_max', 8),
        'cities_max'    => (int) kratos_option('g_comment_geo_cities_max', 0),
        'header'        => 'yes',
    ), $atts, 'comment_geo');

    $data = kratos_comment_geo_aggregate();

    $regions   = array_slice($data['regions'], 0, max(0, (int) $atts['regions_max']));
    $countries = array_slice($data['countries'], 0, max(0, (int) $atts['countries_max']));
    $cities    = array_slice($data['cities'], 0, max(0, (int) $atts['cities_max']));

    $svg_map    = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>';
    $svg_pin    = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
    $svg_globe  = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
    $svg_chat   = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    $svg_city   = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>';

    ob_start();
    ?>
    <div class="kratos-comment-geo">
        <?php if ($atts['header'] !== 'no') { ?>
            <header class="kcg-header kr-hd">
                <span class="kcg-header-icon kr-ico" aria-hidden="true"><?php echo $svg_map; ?></span>
                <h2 class="kcg-header-title kr-hd-title"><?php echo esc_html($atts['title']); ?></h2>
                <?php if ($atts['subtitle'] !== '') { ?>
                    <span class="kcg-header-divider kr-hd-divider" aria-hidden="true"></span>
                    <p class="kcg-header-subtitle kr-hd-sub"><?php echo esc_html($atts['subtitle']); ?></p>
                <?php } ?>
            </header>
        <?php } ?>

        <?php if ($data['resolved_comments'] === 0) { ?>
            <div class="kcg-empty kr-card">
                <?php esc_html_e('还没有可用于统计的归属地数据。请确认「主题选项 → 评论配置」里的 IP 归属地数据库已下载，并且已有通过审核的评论。', 'kratos'); ?>
            </div>
        <?php } else { ?>

            <div class="kcg-stats">
                <?php
                echo kratos_comment_geo_stat_html(__('覆盖省份', 'kratos'), $data['region_count'], $svg_pin);
                echo kratos_comment_geo_stat_html(__('覆盖国家/地区', 'kratos'), $data['country_count'], $svg_globe);
                echo kratos_comment_geo_stat_html(__('已定位评论', 'kratos'), $data['resolved_comments'], $svg_chat);
                ?>
            </div>

            <?php if (!empty($regions)) {
                $max = (int) $regions[0]['count']; ?>
                <section class="kcg-section kr-card">
                    <header class="kcg-section-head">
                        <span class="kcg-section-icon kr-ico" aria-hidden="true"><?php echo $svg_pin; ?></span>
                        <h3 class="kcg-section-title"><?php esc_html_e('省份榜', 'kratos'); ?></h3>
                    </header>
                    <div class="kcg-list">
                        <?php foreach ($regions as $i => $row) {
                            echo kratos_comment_geo_bar_html($row['name'], (int) $row['count'], $max, $i + 1);
                        } ?>
                    </div>
                </section>
            <?php } ?>

            <?php if (!empty($countries)) {
                $max = (int) $countries[0]['count']; ?>
                <section class="kcg-section kr-card">
                    <header class="kcg-section-head">
                        <span class="kcg-section-icon kr-ico" aria-hidden="true"><?php echo $svg_globe; ?></span>
                        <h3 class="kcg-section-title"><?php esc_html_e('国家 / 地区榜', 'kratos'); ?></h3>
                    </header>
                    <div class="kcg-list">
                        <?php foreach ($countries as $i => $row) {
                            echo kratos_comment_geo_bar_html($row['name'], (int) $row['count'], $max, $i + 1);
                        } ?>
                    </div>
                </section>
            <?php } ?>

            <?php if (!empty($cities)) {
                $max = (int) $cities[0]['count']; ?>
                <section class="kcg-section kr-card">
                    <header class="kcg-section-head">
                        <span class="kcg-section-icon kr-ico" aria-hidden="true"><?php echo $svg_city; ?></span>
                        <h3 class="kcg-section-title"><?php esc_html_e('城市榜', 'kratos'); ?></h3>
                    </header>
                    <div class="kcg-list">
                        <?php foreach ($cities as $i => $row) {
                            echo kratos_comment_geo_bar_html($row['name'], (int) $row['count'], $max, $i + 1);
                        } ?>
                    </div>
                </section>
            <?php } ?>

            <?php if (kratos_option('g_comment_geo_show_updated', true)) { ?>
                <p class="kcg-updated"><?php
                    printf(
                        /* translators: %s: 时间 */
                        esc_html__('统计更新于 %s', 'kratos'),
                        esc_html(wp_date('Y-m-d H:i', (int) $data['generated']))
                    );
                ?></p>
            <?php } ?>

        <?php } ?>

        <style>
            /* === 评论者地域分布：通用骨架（--khs-* 变量驱动，皮肤由 components.css 别名层接管） === */
            .kratos-comment-geo {
                --khs-fg: #333; --khs-fg-soft: #555; --khs-fg-dim: #777;
                --khs-accent: #336699;
                --khs-line: rgba(0, 0, 0, .08); --khs-line-strong: rgba(0, 0, 0, .16);
                --khs-card-bg: #ffffff;
                --khs-bg-2: #f0f0f0; --khs-bg-3: #ebebeb;
                --khs-card-shadow: 0 1px 3px rgba(0, 0, 0, .06);
                --khs-card-shadow-hv: 0 8px 18px rgba(0, 0, 0, .10);
                color: var(--khs-fg);
            }

            .kratos-comment-geo .kcg-header {
                display: flex; align-items: center; flex-wrap: wrap; gap: 14px;
                padding: 24px 28px; margin-bottom: 18px;
                background: var(--khs-card-bg);
                border: 1px solid var(--khs-line);
                border-radius: 14px;
                box-shadow: var(--khs-card-shadow);
            }
            .kratos-comment-geo .kcg-header-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 38px; height: 38px; border-radius: 10px;
                background: linear-gradient(135deg, var(--khs-bg-2) 0%, var(--khs-bg-3) 100%);
                color: var(--khs-accent);
            }
            .kratos-comment-geo .kcg-header-title {
                margin: 0; font-size: 22px; font-weight: 700; line-height: 1.3; color: var(--khs-fg);
            }
            .kratos-comment-geo .kcg-header-divider {
                display: inline-block; width: 1px; height: 22px; background: var(--khs-line-strong);
            }
            .kratos-comment-geo .kcg-header-subtitle {
                margin: 0; font-size: 14px; line-height: 1.5; color: var(--khs-fg-soft);
            }

            /* 总览卡 */
            .kratos-comment-geo .kcg-stats {
                display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 16px; margin-bottom: 22px;
            }
            .kratos-comment-geo .kcg-stat {
                display: flex; align-items: center; gap: 14px;
                padding: 20px 22px;
                background: var(--khs-card-bg);
                border: 1px solid var(--khs-line);
                border-radius: 14px;
                box-shadow: var(--khs-card-shadow);
                transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
            }
            .kratos-comment-geo .kcg-stat:hover {
                transform: translateY(-2px);
                box-shadow: var(--khs-card-shadow-hv);
                border-color: var(--khs-line-strong);
            }
            .kratos-comment-geo .kcg-stat-icon {
                flex-shrink: 0;
                display: inline-flex; align-items: center; justify-content: center;
                width: 46px; height: 46px; border-radius: 50%;
                background: linear-gradient(135deg, var(--khs-bg-2) 0%, var(--khs-bg-3) 100%);
                color: var(--khs-accent);
            }
            .kratos-comment-geo .kcg-stat-body { min-width: 0; }
            .kratos-comment-geo .kcg-stat-label {
                font-size: 13px; line-height: 1.2; color: var(--khs-fg-dim);
            }
            .kratos-comment-geo .kcg-stat-num {
                font-size: 28px; font-weight: 700; line-height: 1.15; color: var(--khs-fg);
            }

            /* 榜单 section */
            .kratos-comment-geo .kcg-section {
                padding: 22px 24px; margin-bottom: 18px;
                background: var(--khs-card-bg);
                border: 1px solid var(--khs-line);
                border-radius: 14px;
                box-shadow: var(--khs-card-shadow);
            }
            .kratos-comment-geo .kcg-section-head {
                display: flex; align-items: center; gap: 10px;
                padding-bottom: 14px; margin-bottom: 14px;
                border-bottom: 1px solid var(--khs-line);
            }
            .kratos-comment-geo .kcg-section-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 28px; height: 28px; color: var(--khs-accent);
            }
            .kratos-comment-geo .kcg-section-title {
                margin: 0; font-size: 18px; font-weight: 700; line-height: 1.3; color: var(--khs-fg);
            }

            /* 榜单行：序号 + 名称 + 进度条 + 数字
             * 名称列用 minmax(0, ...) 防止 nowrap 文本把轨道撑开 */
            .kratos-comment-geo .kcg-list { display: flex; flex-direction: column; gap: 10px; }
            .kratos-comment-geo .kcg-row {
                display: grid;
                grid-template-columns: 26px minmax(0, 5.5em) minmax(0, 1fr) minmax(0, 3.5em);
                align-items: center; gap: 12px;
                font-size: 14px;
            }
            .kratos-comment-geo .kcg-rank {
                font-size: 12px; color: var(--khs-fg-dim); text-align: center;
                font-variant-numeric: tabular-nums;
            }
            .kratos-comment-geo .kcg-name {
                color: var(--khs-fg-soft); font-weight: 600;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .kratos-comment-geo .kcg-bar {
                display: block; height: 8px; border-radius: 999px;
                background: var(--khs-bg-3); overflow: hidden;
            }
            .kratos-comment-geo .kcg-bar-fill {
                display: block; height: 100%; border-radius: 999px;
                background: linear-gradient(90deg,
                    color-mix(in srgb, var(--khs-accent) 55%, transparent) 0%,
                    var(--khs-accent) 100%);
                transition: width .4s ease;
            }
            .kratos-comment-geo .kcg-num {
                font-size: 13px; color: var(--khs-fg-dim); text-align: right;
                font-variant-numeric: tabular-nums;
            }

            .kratos-comment-geo .kcg-empty {
                padding: 26px 24px; font-size: 14px; line-height: 1.8;
                color: var(--khs-fg-dim);
                background: var(--khs-card-bg);
                border: 1px dashed var(--khs-line-strong);
                border-radius: 12px;
            }
            .kratos-comment-geo .kcg-updated {
                margin: 0; font-size: 12px; color: var(--khs-fg-dim); text-align: right;
            }

            @media (max-width: 900px) {
                .kratos-comment-geo .kcg-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            @media (max-width: 560px) {
                .kratos-comment-geo .kcg-header { padding: 18px 20px; }
                .kratos-comment-geo .kcg-header-title { font-size: 19px; }
                .kratos-comment-geo .kcg-header-divider { display: none; }
                .kratos-comment-geo .kcg-header-subtitle { flex-basis: 100%; }
                .kratos-comment-geo .kcg-stats { grid-template-columns: 1fr; gap: 12px; }
                .kratos-comment-geo .kcg-stat { padding: 16px 18px; }
                .kratos-comment-geo .kcg-stat-num { font-size: 23px; }
                .kratos-comment-geo .kcg-section { padding: 18px; }
                .kratos-comment-geo .kcg-row {
                    grid-template-columns: 22px minmax(0, 4.5em) minmax(0, 1fr) minmax(0, 3em);
                    gap: 8px;
                }
            }

            html[data-theme="dark"] .kratos-comment-geo {
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
add_shortcode('comment_geo', 'kratos_comment_geo_shortcode');
