<?php

/**
 * 文章热力图（Post Heatmap）
 *
 * 短码 [post_heatmap]，用于在页面（尤其是时间轴模板）中展示 GitHub 风格的
 * 文章发布热力图 + 右侧统计信息。
 *
 * 短码参数：
 *   post_type    要统计的文章类型（默认 post）
 *   year         指定年份（空则显示最近 time_range 天）
 *   time_range   最近 N 天（默认 365）
 *   width        容器宽度（默认 100%）
 *   title        标题（留空则不展示标题）
 *
 * 配色：使用 --khs-* 变量，跟随皮肤（weekday-skins.css §18 已为
 * .kratos-heatmap 做统一重映射）。
 *
 * 数据接口：AJAX action = kph_get_data。
 *
 * 参考实现：@lifengdi 的 post-heatmap 插件。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 加载热力图前端资源。
 * 只有在启用了热力图 且 页面命中短码时才入队。
 */
function kratos_heatmap_enqueue_assets()
{
    if (!kratos_option('heatmap_enabled', true)) {
        return;
    }

    global $post;
    $has_sc = false;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'post_heatmap') || has_shortcode($post->post_content, 'timeline'))) {
        $has_sc = true;
    }
    // 时间轴模板启用了自动展示时也会用到
    if (!$has_sc && is_page() && function_exists('is_page_template') && is_page_template('page-timeline.php') && kratos_option('heatmap_on_timeline', true)) {
        $has_sc = true;
    }
    if (!$has_sc) return;

    // 使用主题内联资源避免额外文件；JS 依赖 jQuery（主题自带）。
    wp_register_script('kratos-heatmap', false, array('jquery'), THEME_VERSION, true);
    wp_enqueue_script('kratos-heatmap');
    wp_localize_script('kratos-heatmap', 'kratosHeatmap', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ));
    wp_add_inline_script('kratos-heatmap', kratos_heatmap_js());
    wp_register_style('kratos-heatmap', false, array(), THEME_VERSION);
    wp_enqueue_style('kratos-heatmap');
    wp_add_inline_style('kratos-heatmap', kratos_heatmap_css());
}
add_action('wp_enqueue_scripts', 'kratos_heatmap_enqueue_assets');

/**
 * 短码入口。
 */
function kratos_heatmap_shortcode($atts)
{
    if (!kratos_option('heatmap_enabled', true)) return '';

    $default_title      = (string) kratos_option('heatmap_sc_title', __('文章热力图', 'kratos'));
    $default_post_type  = (string) kratos_option('heatmap_sc_post_type', 'post');
    $default_time_range = (int) kratos_option('heatmap_sc_time_range', 365);

    $atts = shortcode_atts(array(
        'post_type'  => $default_post_type,
        'year'       => '',
        'time_range' => $default_time_range,
        'width'      => '100%',
        'title'      => $default_title,
    ), $atts, 'post_heatmap');

    $post_type     = sanitize_key($atts['post_type']);
    $selected_year = $atts['year'] ? absint($atts['year']) : null;
    $time_range    = max(1, absint($atts['time_range']));
    $width         = esc_attr($atts['width']);
    $title         = sanitize_text_field($atts['title']);
    $heatmap_id    = 'kph-' . uniqid();

    global $wpdb;
    $earliest_year = $wpdb->get_var($wpdb->prepare(
        "SELECT YEAR(MIN(post_date)) FROM {$wpdb->posts}
         WHERE post_type = %s AND post_status = 'publish' AND post_date <= NOW()",
        $post_type
    ));
    $current_year  = (int) date('Y');
    $earliest_year = $earliest_year ? absint($earliest_year) : $current_year;
    $years         = range($earliest_year, $current_year);
    rsort($years);
    $years_max = (int) kratos_option('heatmap_years_max', 5);
    if ($years_max > 0 && count($years) > $years_max) {
        $years = array_slice($years, 0, $years_max);
    }

    $heatmap_data = kratos_heatmap_get_data($post_type, $selected_year, $time_range);

    $years_json = wp_json_encode(array_map('intval', $years));
    ob_start(); ?>
    <div class="kratos-heatmap"
         data-post-type="<?php echo esc_attr($post_type); ?>"
         data-time-range="<?php echo esc_attr($time_range); ?>"
         data-years="<?php echo esc_attr($years_json); ?>"
         style="width: <?php echo $width; ?>; max-width: 100%;">
        <?php if ($title !== '') { ?>
        <div class="kph-header kr-hd">
            <span class="kph-title-icon kr-ico" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            </span>
            <h3 class="kph-title kr-hd-title"><?php echo esc_html($title); ?></h3>
        </div>
        <?php } ?>

        <div class="kph-body kr-body">
            <div id="<?php echo esc_attr($heatmap_id); ?>" class="kph-canvas"></div>
            <script type="application/json" class="kph-data-<?php echo esc_attr($heatmap_id); ?>">
                <?php echo wp_json_encode(array(
                    'data'       => $heatmap_data['raw_data'],
                    'stats'      => $heatmap_data['stats'],
                    'year'       => $selected_year,
                    'time_range' => $time_range,
                )); ?>
            </script>
        </div>
    </div>
    <?php return ob_get_clean();
}
add_shortcode('post_heatmap', 'kratos_heatmap_shortcode');

/**
 * 查询热力图数据 + 统计信息。
 */
function kratos_heatmap_get_data($post_type = 'post', $year = null, $time_range = 365)
{
    global $wpdb;

    $data          = array();
    $total_count   = 0;
    $max_daily     = 0;
    $max_date      = '';
    $monthly_data  = array();
    $weekday_count = array_fill(0, 7, 0);

    if ($year) {
        $start_date = "$year-01-01";
        $end_date   = "$year-12-31";
        $date_where = "DATE(post_date) BETWEEN %s AND %s";
        $date_args  = array($start_date, $end_date);
        $total_days = (strtotime($end_date) - strtotime($start_date)) / 86400 + 1;
    } else {
        $start_date = date('Y-m-d', strtotime("-$time_range days"));
        $date_where = "DATE(post_date) >= %s";
        $date_args  = array($start_date);
        $total_days = $time_range;
    }

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(post_date) AS date, COUNT(ID) AS count
         FROM {$wpdb->posts}
         WHERE post_type = %s AND post_status = 'publish' AND $date_where
         GROUP BY DATE(post_date) ORDER BY date ASC",
        array_merge(array($post_type), $date_args)
    ), ARRAY_A);

    foreach ($results as $row) {
        $count = (int) $row['count'];
        $data[$row['date']] = $count;
        $total_count += $count;
        if ($count > $max_daily) {
            $max_daily = $count;
            $max_date  = $row['date'];
        }
        $ts   = strtotime($row['date']);
        $month = date('Y-m', $ts);
        if (!isset($monthly_data[$month])) $monthly_data[$month] = 0;
        $monthly_data[$month] += $count;
        $weekday_count[(int) date('w', $ts)] += $count;
    }

    // 最长断更
    $max_break_days  = 0;
    $max_break_start = '';
    $date_list       = array_keys($data);
    sort($date_list);
    if (count($date_list) > 1) {
        for ($i = 1, $n = count($date_list); $i < $n; $i++) {
            $diff = (strtotime($date_list[$i]) - strtotime($date_list[$i - 1])) / 86400;
            if ($diff > $max_break_days) {
                $max_break_days  = $diff;
                $max_break_start = $date_list[$i - 1];
            }
        }
    }

    // 高频周几
    $max_weekday       = 0;
    $max_weekday_count = 0;
    foreach ($weekday_count as $wd => $c) {
        if ($c > $max_weekday_count) {
            $max_weekday_count = $c;
            $max_weekday       = $wd;
        }
    }
    $wd_names          = array(__('周日', 'kratos'), __('周一', 'kratos'), __('周二', 'kratos'), __('周三', 'kratos'), __('周四', 'kratos'), __('周五', 'kratos'), __('周六', 'kratos'));
    $high_freq_weekday = $wd_names[$max_weekday];

    // 分类占比（仅 post）
    $category_data = array();
    if ($post_type === 'post' && $total_count > 0) {
        $category_results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.name AS cat_name, COUNT(p.ID) AS cat_count
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND tt.taxonomy = 'category' AND $date_where
             GROUP BY t.name ORDER BY cat_count DESC LIMIT 4",
            array_merge(array($post_type), $date_args)
        ), ARRAY_A);
        $total_cat = array_sum(array_column($category_results, 'cat_count'));
        foreach ($category_results as $cat) {
            if (!$cat['cat_name']) continue;
            $category_data[] = array(
                'name'    => $cat['cat_name'],
                'count'   => (int) $cat['cat_count'],
                'percent' => $total_cat > 0 ? round(($cat['cat_count'] / $total_cat) * 100, 1) : 0,
            );
        }
    }

    $daily_average   = $total_days > 0 ? round($total_count / $total_days, 2) : 0;
    $max_month       = '';
    $max_month_count = 0;
    foreach ($monthly_data as $m => $c) {
        if ($c > $max_month_count) {
            $max_month_count = $c;
            $max_month       = $m;
        }
    }

    return array(
        'raw_data' => $data,
        'stats'    => array(
            'total'             => $total_count,
            'daily_avg'         => $daily_average,
            'max_daily'         => $max_daily,
            'max_daily_date'    => $max_date,
            'max_month'         => $max_month ?: '',
            'max_month_count'   => $max_month_count,
            'category_data'     => $category_data,
            'high_freq_weekday' => $high_freq_weekday,
            'max_break_days'    => $max_break_days,
            'max_break_start'   => $max_break_start,
        ),
    );
}

/**
 * AJAX：动态获取热力图数据。
 */
function kratos_heatmap_ajax()
{
    $year       = isset($_GET['year']) && $_GET['year'] ? absint($_GET['year']) : null;
    $post_type  = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : 'post';
    $time_range = isset($_GET['time_range']) ? max(1, absint($_GET['time_range'])) : 365;
    $heatmap_data = kratos_heatmap_get_data($post_type, $year, $time_range);
    wp_send_json(array(
        'data'       => $heatmap_data['raw_data'],
        'stats'      => $heatmap_data['stats'],
        'year'       => $year,
        'time_range' => $time_range,
    ));
}
add_action('wp_ajax_kph_get_data', 'kratos_heatmap_ajax');
add_action('wp_ajax_nopriv_kph_get_data', 'kratos_heatmap_ajax');

/**
 * 内联 CSS：所有配色靠 --khs-* 变量驱动。
 */
function kratos_heatmap_css()
{
    return <<<CSS
.kratos-heatmap {
    --khs-bg-1: #f5f5f5; --khs-bg-2: #f0f0f0; --khs-bg-3: #ebebeb;
    --khs-fg: #333; --khs-fg-soft: #444; --khs-fg-dim: #777; --khs-fg-mute: #999;
    --khs-accent: #336699; --khs-accent-2: #2B5278;
    --khs-line: rgba(0,0,0,.08); --khs-line-strong: rgba(0,0,0,.16);
    --khs-card-bg: #ffffff;
    --khs-card-shadow: 0 1px 3px rgba(0,0,0,.06);
    margin-bottom: 20px;
    color: var(--khs-fg);
}
.kratos-heatmap .kph-header {
    display: flex; align-items: center; flex-wrap: wrap; gap: 14px;
    padding: 20px 24px; margin-bottom: 18px;
    background: var(--khs-card-bg);
    border: 1px solid var(--khs-line);
    border-radius: 14px;
    box-shadow: var(--khs-card-shadow);
}
.kratos-heatmap .kph-title-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: 10px;
    background: linear-gradient(135deg, var(--khs-bg-2) 0%, var(--khs-bg-3) 100%);
    color: var(--khs-accent);
}
.kratos-heatmap .kph-title {
    margin: 0; font-size: 20px; font-weight: 700; line-height: 1.3;
    color: var(--khs-fg); flex: 1;
}
.kratos-heatmap .kph-year-selector { margin-left: auto; }
.kratos-heatmap .kph-year-select {
    padding: 5px 10px; font-size: 13px;
    color: var(--khs-fg-soft);
    background: var(--khs-card-bg);
    border: 1px solid var(--khs-line-strong);
    border-radius: 6px; cursor: pointer;
    transition: border-color .2s ease, color .2s ease;
}
.kratos-heatmap .kph-year-select:hover,
.kratos-heatmap .kph-year-select:focus {
    outline: none;
    border-color: var(--khs-accent);
    color: var(--khs-fg);
}

.kratos-heatmap .kph-body {
    padding: 20px 24px;
    background: var(--khs-card-bg);
    border: 1px solid var(--khs-line);
    border-radius: 14px;
    box-shadow: var(--khs-card-shadow);
}
.kratos-heatmap .kph-canvas { min-height: 150px; }
.kratos-heatmap .kph-wrapper {
    display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap;
}
.kratos-heatmap .kph-graph {
    display: flex; align-items: flex-start; gap: 6px;
    max-width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    padding-bottom: 4px;
}
.kratos-heatmap .kph-graph::-webkit-scrollbar { height: 6px; }
.kratos-heatmap .kph-graph::-webkit-scrollbar-thumb {
    background: var(--khs-line-strong); border-radius: 3px;
}
.kratos-heatmap .kph-weekdays {
    display: flex; flex-direction: column; gap: 2px; margin-top: 14px;
    flex-shrink: 0;
    position: sticky; left: 0;
    background: var(--khs-card-bg);
    padding-right: 4px;
    z-index: 1;
}
.kratos-heatmap .kph-weekday {
    width: 13px; height: 13px; font-size: 9px; line-height: 13px; text-align: center;
    color: var(--khs-fg-mute);
}
.kratos-heatmap .kph-main { position: relative; flex-shrink: 0; }
.kratos-heatmap .kph-months { position: relative; height: 14px; }
.kratos-heatmap .kph-month {
    position: absolute; font-size: 11px; font-weight: 600;
    color: var(--khs-fg-soft); transform: translateX(-50%); white-space: nowrap;
}
.kratos-heatmap .kph-grid { display: grid !important; grid-template-rows: repeat(7, 13px) !important; gap: 2px !important; }
.kratos-heatmap .kph-cell {
    width: 13px; height: 13px; border-radius: 2px;
    background: var(--khs-line);
    cursor: pointer; transition: transform .15s ease, filter .15s ease;
}
.kratos-heatmap .kph-cell:hover { transform: scale(1.25); filter: brightness(1.05); }
.kratos-heatmap .kph-cell.level-1 { background: color-mix(in srgb, var(--khs-accent) 22%, transparent); }
.kratos-heatmap .kph-cell.level-2 { background: color-mix(in srgb, var(--khs-accent) 45%, transparent); }
.kratos-heatmap .kph-cell.level-3 { background: color-mix(in srgb, var(--khs-accent) 70%, transparent); }
.kratos-heatmap .kph-cell.level-4 { background: var(--khs-accent); }

.kph-tooltip {
    position: absolute; padding: 6px 10px;
    background: rgba(0,0,0,.85); color: #fff;
    font-size: 12px; border-radius: 4px;
    pointer-events: none; opacity: 0;
    transition: opacity .15s ease; z-index: 9999; white-space: nowrap;
    line-height: 1.5;
}

.kratos-heatmap .kph-legend {
    display: flex; align-items: center; gap: 8px;
    font-size: 11px; color: var(--khs-fg-mute);
    margin-top: 12px;
}
.kratos-heatmap .kph-legend-colors { display: flex; gap: 2px; }
.kratos-heatmap .kph-legend-cell {
    width: 12px; height: 12px; border-radius: 2px;
    background: var(--khs-line);
}
.kratos-heatmap .kph-legend-cell.level-1 { background: color-mix(in srgb, var(--khs-accent) 22%, transparent); }
.kratos-heatmap .kph-legend-cell.level-2 { background: color-mix(in srgb, var(--khs-accent) 45%, transparent); }
.kratos-heatmap .kph-legend-cell.level-3 { background: color-mix(in srgb, var(--khs-accent) 70%, transparent); }
.kratos-heatmap .kph-legend-cell.level-4 { background: var(--khs-accent); }

.kratos-heatmap .kph-year-tags {
    flex: 1 1 130px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(64px, 1fr));
    gap: 6px;
    align-content: start;
}
.kratos-heatmap .kph-year-tag {
    font-size: 13px; font-weight: 600;
    color: var(--khs-fg-soft);
    padding: 5px 12px;
    border-radius: 6px;
    background: transparent;
    border: 1px solid var(--khs-line);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    line-height: 1.4;
    white-space: nowrap;
    transition: background .2s ease, border-color .2s ease, color .2s ease;
}
.kratos-heatmap .kph-year-tag:hover {
    border-color: var(--khs-accent);
    color: var(--khs-accent);
}
.kratos-heatmap .kph-year-tag.is-active {
    background: var(--khs-accent);
    border-color: var(--khs-accent);
    color: #fff;
    cursor: default;
}
.kratos-heatmap .kph-year-tag.is-active:hover {
    color: #fff;
}

.kratos-heatmap .kph-stats {
    display: flex; flex-wrap: wrap; gap: 20px;
    margin-top: 16px; padding-top: 16px;
    border-top: 1px dashed var(--khs-line-strong);
    font-size: 13px;
}
.kratos-heatmap .kph-stats-block { flex: 1; min-width: 180px; }
.kratos-heatmap .kph-stats-block-title {
    font-size: 12px; font-weight: 700; letter-spacing: .5px;
    color: var(--khs-accent); margin-bottom: 8px;
    text-transform: uppercase;
}
.kratos-heatmap .kph-stats-item {
    display: flex; justify-content: space-between; align-items: center;
    margin: 5px 0; line-height: 1.5; color: var(--khs-fg-soft);
}
.kratos-heatmap .kph-stats-item .kph-stats-label { color: var(--khs-fg-mute); }
.kratos-heatmap .kph-stats-item .kph-stats-value { color: var(--khs-fg); font-weight: 600; }
.kratos-heatmap .kph-cat-item {
    display: flex; align-items: center; gap: 8px; margin: 6px 0;
}
.kratos-heatmap .kph-cat-name {
    width: 60px; font-size: 12px; color: var(--khs-fg-soft);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.kratos-heatmap .kph-cat-bar {
    flex: 1; height: 6px; background: var(--khs-line); border-radius: 3px; overflow: hidden;
}
.kratos-heatmap .kph-cat-fill {
    height: 100%; background: var(--khs-accent); border-radius: 3px;
    transition: width .4s ease;
}
.kratos-heatmap .kph-cat-percent {
    width: 42px; font-size: 12px; text-align: right; color: var(--khs-fg-mute);
}

@media (max-width: 720px) {
    .kratos-heatmap .kph-header { padding: 14px 16px; }
    .kratos-heatmap .kph-title { font-size: 16px; }
    .kratos-heatmap .kph-body { padding: 14px 16px; }
    .kratos-heatmap .kph-wrapper { gap: 10px; flex-direction: column; align-items: stretch; }
    .kratos-heatmap .kph-year-tags {
        order: -1;
        flex: 0 1 auto;
        display: flex;
        flex-direction: row;
        flex-wrap: wrap;
        gap: 6px;
    }
    .kratos-heatmap .kph-year-tag { font-size: 12px; padding: 4px 10px; }
    .kratos-heatmap .kph-graph { width: 100%; }
    .kratos-heatmap .kph-stats { gap: 14px; margin-top: 14px; padding-top: 14px; }
    .kratos-heatmap .kph-stats-block { min-width: 100%; }
    .kratos-heatmap .kph-cat-name { width: 72px; }
}

/* 暗夜模式 */
html[data-theme="dark"] .kratos-heatmap,
body.dark .kratos-heatmap {
    --khs-bg-1: #2a2e35; --khs-bg-2: #2a2e35; --khs-bg-3: #333842;
    --khs-fg: #d6d8db; --khs-fg-soft: #b8bbc0; --khs-fg-dim: #8b919a; --khs-fg-mute: #6f747e;
    --khs-accent: #6ea8ff; --khs-accent-2: #91bdff;
    --khs-line: rgba(255,255,255,.10); --khs-line-strong: rgba(255,255,255,.18);
    --khs-card-bg: #1c1f24;
    --khs-card-shadow: 0 1px 2px rgba(0,0,0,.5);
}
CSS;
}

/**
 * 内联 JS：渲染 + AJAX 切换。
 */
function kratos_heatmap_js()
{
    $i18n = wp_json_encode(array(
        'weekdays'      => array(__('日', 'kratos'), __('一', 'kratos'), __('二', 'kratos'), __('三', 'kratos'), __('四', 'kratos'), __('五', 'kratos'), __('六', 'kratos')),
        'months'        => array(__('1月', 'kratos'), __('2月', 'kratos'), __('3月', 'kratos'), __('4月', 'kratos'), __('5月', 'kratos'), __('6月', 'kratos'), __('7月', 'kratos'), __('8月', 'kratos'), __('9月', 'kratos'), __('10月', 'kratos'), __('11月', 'kratos'), __('12月', 'kratos')),
        'tooltip'       => __('%date%：发布 %count% 篇', 'kratos'),
        'tooltipEmpty'  => __('%date%：未发布', 'kratos'),
        'recentYear'    => __('最近一年', 'kratos'),
        'yearLabel'     => __('%d 年', 'kratos'),
        'loading'       => __('加载中...', 'kratos'),
        'loadFail'      => __('数据加载失败，请刷新重试', 'kratos'),
        'legendLess'    => __('少', 'kratos'),
        'legendMore'    => __('多', 'kratos'),
        'baseTitle'     => __('基础统计', 'kratos'),
        'rhythmTitle'   => __('发布节奏', 'kratos'),
        'categoryTitle' => __('分类占比', 'kratos'),
        'total'         => __('总发布', 'kratos'),
        'dailyAvg'      => __('日均', 'kratos'),
        'maxDaily'      => __('最高单日', 'kratos'),
        'maxMonth'      => __('最活跃月', 'kratos'),
        'highFreq'      => __('高频时段', 'kratos'),
        'maxBreak'      => __('最长断更', 'kratos'),
        'weekPrefix'    => __('每周', 'kratos'),
        'days'          => __('天', 'kratos'),
        'pieces'        => __('篇', 'kratos'),
        'none'          => __('无', 'kratos'),
    ));
    return <<<JS
(function($){
    var I18N = $i18n;
    function pad(n){return String(n).padStart(2,'0');}
    function fmt(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());}
    function level(c){if(!c)return 0;if(c<2)return 1;if(c<5)return 2;if(c<10)return 3;return 4;}
    function weekMatrix(start,end){
        var m=[],cur=new Date(start),first=new Date(start);
        first.setDate(start.getDate()-start.getDay());
        cur=new Date(first);
        var wk=new Array(7).fill(null);
        while(cur<=end){
            wk[cur.getDay()]=new Date(cur);
            if(cur.getDay()===6){m.push(wk);wk=new Array(7).fill(null);}
            cur.setDate(cur.getDate()+1);
        }
        if(wk.some(function(x){return x!==null;}))m.push(wk);
        return m;
    }
    function monthLabels(wm,cs,cg){
        var labels=[],last=-1;
        wm.forEach(function(w,i){
            var f=w.find(function(d){return d!==null;});
            if(!f)return;
            var mo=f.getMonth();
            if(mo===last)return;
            labels.push({text:I18N.months[mo],offset:i*(cs+cg)+cs/2});
            last=mo;
        });
        return labels;
    }
    function render(\$canvas,data,stats,year,timeRange){
        var start,end;
        if(year){start=new Date(year+'-01-01');end=new Date(year+'-12-31');}
        else{end=new Date();start=new Date();start.setDate(end.getDate()-timeRange);}
        var wm=weekMatrix(start,end),weeks=wm.length,cs=13,cg=2;
        \$canvas.empty();
        var \$tt=$('.kph-tooltip');
        if(!\$tt.length)\$tt=$('<div class="kph-tooltip"></div>').appendTo('body');
        var \$wrap=$('<div class="kph-wrapper"></div>');
        var \$graph=$('<div class="kph-graph"></div>');
        var \$wd=$('<div class="kph-weekdays"></div>');
        I18N.weekdays.forEach(function(d,i){
            \$wd.append('<div class="kph-weekday">'+(i%2===1?d:'')+'</div>');
        });
        \$graph.append(\$wd);
        var \$main=$('<div class="kph-main"></div>');
        var \$mo=$('<div class="kph-months"></div>');
        \$mo.css('width',(weeks*(cs+cg))+'px');
        monthLabels(wm,cs,cg).forEach(function(l){
            \$mo.append('<div class="kph-month" style="left:'+l.offset+'px;">'+l.text+'</div>');
        });
        \$main.append(\$mo);
        var \$grid=$('<div class="kph-grid"></div>');
        \$grid.css({'grid-template-columns':'repeat('+weeks+', '+cs+'px)'});
        var today=new Date();
        wm.forEach(function(week,wi){
            week.forEach(function(d,di){
                var c=0,ds='',fu=false;
                if(d){ds=fmt(d);fu=d>today;c=fu?0:(data[ds]||0);}
                var lv=level(c);
                var \$c=$('<div class="kph-cell level-'+lv+'"></div>');
                \$c.css({'grid-row':(di+1),'grid-column':(wi+1)});
                if(ds){
                    \$c.on('mouseenter',function(e){
                        var t=(c>0?I18N.tooltip.replace('%count%',c):I18N.tooltipEmpty).replace('%date%',ds);
                        \$tt.text(t).css({top:e.pageY+10,left:e.pageX+10,opacity:1});
                    }).on('mousemove',function(e){
                        \$tt.css({top:e.pageY+10,left:e.pageX+10});
                    }).on('mouseleave',function(){\$tt.css('opacity',0);});
                }
                \$grid.append(\$c);
            });
        });
        \$main.append(\$grid);
        \$graph.append(\$main);
        \$wrap.append(\$graph);
        var years=\$canvas.closest('.kratos-heatmap').data('years')||[];
        if(typeof years==='string'){try{years=JSON.parse(years);}catch(e){years=[];}}
        var \$tags=$('<div class="kph-year-tags"></div>');
        \$tags.append('<button type="button" class="kph-year-tag kr-pill'+(!year?' is-active':'')+'" data-year="">'+I18N.recentYear+'</button>');
        years.forEach(function(y){
            \$tags.append('<button type="button" class="kph-year-tag kr-pill'+(String(year)===String(y)?' is-active':'')+'" data-year="'+y+'">'+I18N.yearLabel.replace('%d',y)+'</button>');
        });
        \$wrap.append(\$tags);
        \$canvas.append(\$wrap);
        // legend
        var \$lg=$('<div class="kph-legend"></div>');
        \$lg.append('<span>'+I18N.legendLess+'</span>');
        var \$lc=$('<div class="kph-legend-colors"></div>');
        [0,1,2,3,4].forEach(function(l){\$lc.append('<div class="kph-legend-cell level-'+l+'"></div>');});
        \$lg.append(\$lc).append('<span>'+I18N.legendMore+'</span>');
        \$canvas.append(\$lg);
        // stats
        var \$st=$('<div class="kph-stats"></div>');
        var base=''+
            '<div class="kph-stats-block"><div class="kph-stats-block-title">'+I18N.baseTitle+'</div>'+
            item(I18N.total,stats.total+I18N.pieces)+
            item(I18N.dailyAvg,stats.daily_avg+I18N.pieces)+
            item(I18N.maxDaily,(stats.max_daily_date||I18N.none)+' '+stats.max_daily+I18N.pieces)+
            item(I18N.maxMonth,(stats.max_month||I18N.none)+' '+stats.max_month_count+I18N.pieces)+
            '</div>';
        var rhythm=''+
            '<div class="kph-stats-block"><div class="kph-stats-block-title">'+I18N.rhythmTitle+'</div>'+
            item(I18N.highFreq,I18N.weekPrefix+(stats.high_freq_weekday||I18N.none))+
            item(I18N.maxBreak,(stats.max_break_days||0)+I18N.days)+
            '</div>';
        \$st.append(base).append(rhythm);
        if(stats.category_data&&stats.category_data.length){
            var cat='<div class="kph-stats-block"><div class="kph-stats-block-title">'+I18N.categoryTitle+'</div>';
            stats.category_data.forEach(function(c){
                cat+='<div class="kph-cat-item">'+
                    '<span class="kph-cat-name">'+esc(c.name)+'</span>'+
                    '<span class="kph-cat-bar"><span class="kph-cat-fill" style="width:'+c.percent+'%;"></span></span>'+
                    '<span class="kph-cat-percent">'+c.percent+'%</span></div>';
            });
            cat+='</div>';
            \$st.append(cat);
        }
        \$canvas.append(\$st);
    }
    function item(l,v){return '<div class="kph-stats-item"><span class="kph-stats-label">'+l+'</span><span class="kph-stats-value">'+v+'</span></div>';}
    function esc(s){return $('<div>').text(s).html();}

    $(function(){
        $('.kratos-heatmap .kph-canvas').each(function(){
            var \$c=$(this),id=this.id,cfg=JSON.parse($('.kph-data-'+id).html()||'{}');
            if(!cfg.data)return;
            render(\$c,cfg.data,cfg.stats,cfg.year,cfg.time_range);
        });
        $(document).on('click','.kph-year-tag',function(){
            var \$t=$(this);
            if(\$t.hasClass('is-active'))return;
            var \$box=\$t.closest('.kratos-heatmap'),
                \$c=\$box.find('.kph-canvas'),
                y=\$t.data('year')||null,
                pt=\$box.data('post-type'),tr=\$box.data('time-range');
            \$c.html('<div style="padding:20px;color:var(--khs-fg-mute);">'+I18N.loading+'</div>');
            $.ajax({url:kratosHeatmap.ajaxUrl,type:'GET',dataType:'json',
                data:{action:'kph_get_data',year:y,post_type:pt,time_range:tr},
                success:function(r){render(\$c,r.data,r.stats,r.year,r.time_range);},
                error:function(){alert(I18N.loadFail);\$c.empty();}
            });
        });
    });
})(jQuery);
JS;
}
