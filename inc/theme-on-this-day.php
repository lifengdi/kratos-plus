<?php

/**
 * 今日在历史上的这一天（On This Day）
 *
 * - 短码 [on_this_day]：卡片形态列表，展示历史年份中同月同日发布的文章 / 说说
 * - 小工具 widget_on_this_day：侧栏紧凑版
 * - 首页 / 文章底部自动嵌入（可在主题选项里控制）
 *
 * 数据用 transient 缓存 24 小时（key = kratos_otd_YYYYMMDD），当天首次访问后其它请求秒开。
 * 当天没有任何历史内容时短码返回空串，避免占位。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 查询「今天在历史上的这一天」发过的所有帖子。
 *
 * @param array $post_types 要包含的文章类型
 * @param int   $limit      最多返回条数
 * @return array 每条结构：{id, title, permalink, date, years_ago, thumb, excerpt, post_type}
 */
function kratos_otd_query($post_types = array('post'), $limit = 20)
{
    // CSF 的 checkbox 保存格式是 array('post' => '1', 'shuoshuo' => '0')，
    // 直接对 values 做 sanitize_key 会把 '1'/'0' 当值。需要按"值真"筛出 key。
    $post_types = (array) $post_types;
    $is_assoc = array_keys($post_types) !== range(0, count($post_types) - 1);
    if ($is_assoc) {
        $post_types = array_keys(array_filter($post_types, function ($v) {
            return !empty($v) && $v !== '0';
        }));
    }
    $post_types = array_values(array_filter(array_map('sanitize_key', $post_types)));
    if (empty($post_types)) {
        $post_types = array('post');
    }
    sort($post_types);

    $today_m = (int) current_time('m');
    $today_d = (int) current_time('d');
    $today_y = (int) current_time('Y');
    // v3：确保 thumb 用 post ID 取，避免旧缓存的空 thumb 干扰
    $cache_key = 'kratos_otd_v1_' . current_time('Ymd') . '_' . md5(implode(',', $post_types) . '_' . (int) $limit);

    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $q = new WP_Query(kratos_lean_query_args(array(
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => max(1, (int) $limit),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'ignore_sticky_posts' => true,
        'no_found_rows'  => true,
        // WP_Query 的 date_query 里 year 只接受整数或整数数组，无法用 before/inclusive
        // 表达"年份<今年"。用顶层 before 子键 + month/day 一起写：
        // 「同月同日 & 早于今年 1 月 1 日」== 历史年份的今天。
        'date_query' => array(
            array(
                'month'  => $today_m,
                'day'    => $today_d,
                'before' => array('year' => $today_y, 'month' => 1, 'day' => 1),
            ),
        ),
    ), array('no_terms' => true)));

    $items = array();
    if ($q->have_posts()) {
        foreach ($q->posts as $p) {
            $post_year = (int) get_the_date('Y', $p);
            $years_ago = max(1, $today_y - $post_year);
            // 与文章列表 post_thumbnail() 保持一致的三段回退：
            // 1) 特色图（kratos-thumbnail 尺寸）
            // 2) 正文首张 <img>
            // 3) 主题选项 g_postthumbnail 默认封面（与列表兜底同源）
            $thumb = '';
            if (has_post_thumbnail($p->ID)) {
                $src = wp_get_attachment_image_src(get_post_thumbnail_id($p->ID), 'kratos-thumbnail');
                if (is_array($src)) $thumb = (string) $src[0];
            }
            if ($thumb === '' && !empty($p->post_content) && preg_match('#<img[^>]+src=[\'"]([^\'"]+)[\'"]#i', $p->post_content, $mm)) {
                $thumb = $mm[1];
            }
            if ($thumb === '') {
                if (function_exists('kratos_default_thumb_is_text_mode') && kratos_default_thumb_is_text_mode()) {
                    $thumb = kratos_default_thumb_url($p, 512, 288);
                } else {
                    $thumb = (string) kratos_option('g_postthumbnail', ASSET_PATH . '/assets/img/default.jpg');
                }
            }
            $items[] = array(
                'id'        => (int) $p->ID,
                'title'     => get_the_title($p),
                'permalink' => get_permalink($p),
                'date'      => get_the_date(get_option('date_format'), $p),
                'years_ago' => $years_ago,
                'thumb'     => $thumb,
                'excerpt'   => wp_trim_words(wp_strip_all_tags(strip_shortcodes($p->post_content)), 60, '…'),
                'post_type' => $p->post_type,
            );
        }
    }
    wp_reset_postdata();

    // 缓存到今天结束（简化：24h）
    set_transient($cache_key, $items, DAY_IN_SECONDS);

    return $items;
}

/**
 * 短码 [on_this_day]
 */
function kratos_otd_shortcode($atts)
{
    if (!kratos_option('otd_enable', true)) {
        return '';
    }

    $default_title    = (string) kratos_option('otd_title', __('岁月同一天', 'kratos'));
    $default_subtitle = (string) kratos_option('otd_subtitle', __('回望过去的今天，你在写什么', 'kratos'));
    $default_types = (array) kratos_option('otd_post_types', array('post'));
    // CSF checkbox 存的是 array('post'=>'1','shuoshuo'=>'0')，直接 implode 会得到 "1,0"
    $is_assoc = array_keys($default_types) !== range(0, count($default_types) - 1);
    if ($is_assoc) {
        $default_types = array_keys(array_filter($default_types, function ($v) {
            return !empty($v) && $v !== '0';
        }));
    }
    if (empty($default_types)) $default_types = array('post');
    $default_show_thumb = (bool) kratos_option('otd_show_thumb', true);
    $default_limit    = (int) kratos_option('otd_limit', 20);

    $atts = shortcode_atts(array(
        'title'      => $default_title,
        'subtitle'   => $default_subtitle,
        'post_types' => implode(',', $default_types),
        'show_thumb' => $default_show_thumb ? 1 : 0,
        'limit'      => $default_limit,
        'variant'    => 'full', // full | compact（compact 用于首页顶部，紧凑横条）
    ), $atts, 'on_this_day');
    $variant = ($atts['variant'] === 'compact') ? 'compact' : 'full';

    $types = array_values(array_filter(array_map('trim', explode(',', (string) $atts['post_types']))));
    $items = kratos_otd_query($types, (int) $atts['limit']);
    if (empty($items)) {
        return '';
    }

    $show_thumb = (bool) $atts['show_thumb'];
    $title = trim((string) $atts['title']);
    $subtitle = trim((string) $atts['subtitle']);
    $today_label = date_i18n(__('n 月 j 日', 'kratos'), current_time('timestamp'));

    if ($variant === 'compact') {
        ob_start(); ?>
        <div class="kratos-otd kratos-otd-compact">
            <div class="kotd-c-inner">
                <div class="kotd-c-head">
                    <span class="kotd-c-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </span>
                    <span class="kotd-c-label"><?php echo esc_html($title !== '' ? $title : __('岁月同一天', 'kratos')); ?></span>
                    <span class="kotd-c-today"><?php echo esc_html($today_label); ?></span>
                </div>
                <ul class="kotd-c-list">
                    <?php foreach (array_slice($items, 0, 5) as $it) { ?>
                        <li class="kotd-c-item">
                            <span class="kotd-c-badge"><?php echo esc_html(sprintf(__('%d 年前', 'kratos'), $it['years_ago'])); ?></span>
                            <a class="kotd-c-title-link" href="<?php echo esc_url($it['permalink']); ?>" title="<?php echo esc_attr($it['title']); ?>"><?php echo esc_html($it['title']); ?></a>
                            <span class="kotd-c-date"><?php echo esc_html($it['date']); ?></span>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
        <?php echo kratos_otd_inline_assets();
        return ob_get_clean();
    }

    ob_start(); ?>
    <div class="kratos-otd kr-hd">
        <?php if ($title !== '' || $subtitle !== '') { ?>
            <header class="kratos-otd-header">
                <span class="kotd-icon kr-ico" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </span>
                <?php if ($title !== '') { ?>
                    <h2 class="kotd-title kr-hd-title"><?php echo esc_html($title); ?>
                        <span class="kotd-today kr-pill"><?php echo esc_html($today_label); ?></span>
                    </h2>
                <?php } ?>
                <?php if ($subtitle !== '') { ?>
                    <p class="kotd-subtitle kr-hd-sub"><?php echo esc_html($subtitle); ?></p>
                <?php } ?>
                <span class="kotd-divider kr-hd-divider"></span>
            </header>
        <?php } ?>
        <div class="kratos-otd-body">
            <ul class="kotd-list">
                <?php foreach ($items as $it) { ?>
                    <li class="kotd-item">
                        <?php if ($show_thumb && !empty($it['thumb'])) { ?>
                            <a class="kotd-thumb" href="<?php echo esc_url($it['permalink']); ?>" aria-hidden="true" tabindex="-1">
                                <span class="kotd-thumb-bg" style="background-image:url('<?php echo esc_attr((strpos($it['thumb'], 'data:') === 0) ? $it['thumb'] : esc_url($it['thumb'])); ?>');"></span>
                            </a>
                        <?php } ?>
                        <div class="kotd-main">
                            <div class="kotd-meta">
                                <span class="kotd-badge kr-pill"><?php echo esc_html(sprintf(_n('%d 年前', '%d 年前', $it['years_ago'], 'kratos'), $it['years_ago'])); ?></span>
                                <span class="kotd-date"><?php echo esc_html($it['date']); ?></span>
                            </div>
                            <a class="kotd-title-link" href="<?php echo esc_url($it['permalink']); ?>"><?php echo esc_html($it['title']); ?></a>
                            <?php if (!empty($it['excerpt'])) { ?>
                                <p class="kotd-excerpt"><?php echo esc_html($it['excerpt']); ?></p>
                            <?php } ?>
                        </div>
                    </li>
                <?php } ?>
            </ul>
        </div>
    </div>
    <?php echo kratos_otd_inline_assets();
    return ob_get_clean();
}
add_shortcode('on_this_day', 'kratos_otd_shortcode');

/**
 * 一次性输出 OTD 内联样式（每个请求最多一次）
 */
function kratos_otd_inline_assets()
{
    static $printed = false;
    if ($printed) return '';
    $printed = true;
    ob_start(); ?>
    <style>
        .kratos-otd{margin:24px 0;border:var(--kr-shape-border);border-radius:var(--kr-shape-radius);box-shadow:0 1px 3px rgba(0,0,0,.04);}
        .kratos-otd-header{position:relative;padding:22px 26px 16px;}
        .kratos-otd .kotd-icon{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;background:linear-gradient(135deg,#ffd7a5,#ff9d5c);color:#fff;border-radius:8px;margin-right:10px;vertical-align:middle;box-shadow:0 2px 6px rgba(255,157,92,.35);}
        .kratos-otd .kotd-title{display:inline-flex;align-items:center;gap:10px;margin:0;font-size:20px;font-weight:600;color:#222;vertical-align:middle;}
        .kratos-otd .kotd-today{display:inline-flex;align-items:center;height:22px;padding:0 10px;background:color-mix(in srgb, var(--khs-accent,#336699) 10%, transparent);color:var(--khs-accent,#336699);border-radius:999px;font-size:12px;font-weight:500;letter-spacing:.3px;}
        .kratos-otd .kotd-subtitle{margin:8px 0 0;font-size:13px;color:#888;line-height:1.6;}
        .kratos-otd .kotd-divider{display:block;height:1px;margin-top:12px;background:linear-gradient(90deg,var(--kr-skin-tag-bg, rgba(0, 0, 0, .35)),transparent);}
        .kratos-otd-body{padding:4px 26px;}
        .kratos-otd .kotd-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;}
        .kratos-otd .kotd-item{display:flex;gap:14px;margin:0 -20px;padding:16px 20px;border-bottom:1px solid rgba(0,0,0,.06);transition:background .2s ease;}
        .kratos-otd .kotd-item:last-child{border-bottom:none;}
        .kratos-otd .kotd-item:hover{background:color-mix(in srgb, var(--khs-accent,#336699) 4%, transparent);}
        .kratos-otd .kotd-thumb{position:relative;flex-shrink:0;width:96px;height:96px;border-radius:2px;overflow:hidden;background:#f1f1f1;}
        .kratos-otd .kotd-thumb-bg{position:absolute;inset:0;background-size:cover;background-position:center;transition:transform .3s ease;}
        .kratos-otd .kotd-thumb:hover .kotd-thumb-bg{transform:scale(1.05);}
        .kratos-otd .kotd-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:6px;}
        .kratos-otd .kotd-meta{display:flex;align-items:center;gap:10px;font-size:12px;color:#999;}
        .kratos-otd .kotd-badge{display:inline-flex;align-items:center;height:20px;padding:0 10px;background:color-mix(in srgb, var(--khs-accent,#336699) 10%, transparent);color:var(--khs-accent,#336699);border-radius:999px;font-size:12px;font-weight:600;letter-spacing:.3px;}
        .kratos-otd .kotd-title-link{font-size:15px;font-weight:600;text-decoration:none !important;line-height:1.4;}
        .kratos-otd .kotd-excerpt{margin:0;font-size:13px;line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
        @media (max-width:576px){
            .kratos-otd-header{padding:18px 20px 12px;}
            .kratos-otd .kotd-title{font-size:18px;}
            .kratos-otd-body{padding:2px 14px;}
            .kratos-otd .kotd-item{margin:0 -14px;padding:14px;gap:10px;}
            .kratos-otd .kotd-thumb{width:72px;height:72px;}
        }
        html[data-theme="dark"] .kratos-otd-header,body.dark .kratos-otd-header,
        html[data-theme="dark"] .kratos-otd-body,body.dark .kratos-otd-body{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.08);box-shadow:0 2px 6px rgba(0,0,0,.3);}
        html[data-theme="dark"] .kratos-otd .kotd-item,body.dark .kratos-otd .kotd-item{border-bottom-color:rgba(255,255,255,.08);}
        html[data-theme="dark"] .kratos-otd .kotd-title,body.dark .kratos-otd .kotd-title{color:#e8e4ec;}
        html[data-theme="dark"] .kratos-otd .kotd-subtitle,body.dark .kratos-otd .kotd-subtitle,
        html[data-theme="dark"] .kratos-otd .kotd-excerpt,body.dark .kratos-otd .kotd-excerpt{color:#aaa;}
        html[data-theme="dark"] .kratos-otd .kotd-title-link,body.dark .kratos-otd .kotd-title-link{color:#e8e4ec !important;}
        html[data-theme="dark"] .kratos-otd .kotd-title-link:hover,body.dark .kratos-otd .kotd-title-link:hover{color:#7e9bce !important;}

        /* 首页顶部紧凑版：与文章列表卡片风格对齐（无独立标题卡、贴合 .article-panel 直角 + 浅阴影） */
        .kratos-otd-compact{margin:0 0 23px;background:var(--kr-skin-card-bg);border:var(--kr-shape-border);border-radius:var(--kr-shape-radius);box-shadow:0 1px 2px rgba(0,0,0,.1);overflow:hidden;}
        .kratos-otd-compact .kotd-c-inner{padding:14px 20px;}
        .kratos-otd-compact .kotd-c-head{display:flex;align-items:center;gap:10px;padding-bottom:10px;margin-bottom:8px;border-bottom:1px dashed var(--khs-line,rgba(0,0,0,.08));}
        .kratos-otd-compact .kotd-c-icon{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:color-mix(in srgb, var(--khs-accent,#336699) 12%, transparent);color:var(--khs-accent,#336699);border-radius:6px;flex-shrink:0;}
        .kratos-otd-compact .kotd-c-label{font-size:14px;font-weight:600;color:var(--khs-fg,#333);letter-spacing:.5px;}
        .kratos-otd-compact .kotd-c-today{margin-left:auto;font-size:12px;color:var(--khs-fg-dim,#999);}
        .kratos-otd-compact .kotd-c-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px;}
        .kratos-otd-compact .kotd-c-item{display:flex;align-items:center;gap:10px;padding:6px 0;font-size:13px;line-height:1.6;}
        .kratos-otd-compact .kotd-c-badge{flex-shrink:0;display:inline-flex;align-items:center;height:20px;padding:0 9px;font-size:11px;font-weight:600;color:var(--khs-accent,#336699);background:color-mix(in srgb, var(--khs-accent,#336699) 10%, transparent);border-radius:999px;letter-spacing:.3px;}
        .kratos-otd-compact .kotd-c-title-link{flex:1;min-width:0;color:var(--khs-fg,#333) !important;text-decoration:none !important;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .kratos-otd-compact .kotd-c-title-link:hover{color:var(--khs-accent,#336699) !important;}
        .kratos-otd-compact .kotd-c-date{flex-shrink:0;font-size:11px;color:var(--khs-fg-dim,#999);}
        @media (max-width:576px){
            .kratos-otd-compact .kotd-c-inner{padding:12px 14px;}
            .kratos-otd-compact .kotd-c-date{display:none;}
        }
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-otd-compact{background:rgba(255,255,255,.04);box-shadow:0 2px 6px rgba(0,0,0,.3);}
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-otd-compact .kotd-c-label,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-otd-compact .kotd-c-title-link{color:#d8d8de !important;}

        /* 侧栏紧凑版 */
        .widget .kratos-otd-widget{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px;}
        .widget .kratos-otd-widget li{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px dashed rgba(0,0,0,.06);}
        .widget .kratos-otd-widget li:last-child{border-bottom:none;}
        .widget .kratos-otd-widget .kotd-w-badge{flex-shrink:0;display:inline-flex;align-items:center;height:20px;padding:0 8px;background:color-mix(in srgb, var(--khs-accent,#336699) 10%, transparent);color:var(--khs-accent,#336699);border-radius:999px;font-size:11px;font-weight:600;}
        .widget .kratos-otd-widget .kotd-w-title{flex:1;font-size:13px;color:#333 !important;text-decoration:none !important;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .widget .kratos-otd-widget .kotd-w-title:hover{color:#336699 !important;}
        html[data-theme="dark"] .widget .kratos-otd-widget .kotd-w-title,body.dark .widget .kratos-otd-widget .kotd-w-title{color:#d8d8de !important;}
    </style>
    <?php return ob_get_clean();
}

// 文章底部注入直接写在 single.php 里（the_content 过滤器会被相关文章 / 阅读增强
// 等多处消费，用它注入无法稳定命中"正文之后、评论区之前"的位置）。

/**
 * 侧栏小工具（紧凑版）
 */
class widget_on_this_day extends WP_Widget
{
    public function __construct()
    {
        parent::__construct(false, __('Kratos+ - 岁月同一天', 'kratos'), array(
            'description' => __('展示历史年份中同月同日发布的文章 / 说说', 'kratos'),
        ));
    }

    public function widget($args, $instance)
    {
        if (!kratos_option('otd_enable', true)) return;

        $title = !empty($instance['title']) ? $instance['title'] : __('岁月同一天', 'kratos');
        $limit = !empty($instance['limit']) ? (int) $instance['limit'] : 5;
        $types = (array) kratos_option('otd_post_types', array('post'));
        if (empty($types)) $types = array('post');

        $items = kratos_otd_query($types, $limit);
        if (empty($items)) return;

        echo $args['before_widget'];
        echo $args['before_title'] . apply_filters('widget_title', $title) . $args['after_title'];
        ?>
        <ul class="kratos-otd-widget">
            <?php foreach ($items as $it) { ?>
                <li>
                    <span class="kotd-w-badge"><?php echo esc_html(sprintf(__('%d 年前', 'kratos'), $it['years_ago'])); ?></span>
                    <a class="kotd-w-title" href="<?php echo esc_url($it['permalink']); ?>" title="<?php echo esc_attr($it['title']); ?>"><?php echo esc_html($it['title']); ?></a>
                </li>
            <?php } ?>
        </ul>
        <?php
        echo kratos_otd_inline_assets();
        echo $args['after_widget'];
    }

    public function update($new_instance, $old_instance)
    {
        return array(
            'title' => sanitize_text_field($new_instance['title']),
            'limit' => max(1, (int) $new_instance['limit']),
        );
    }

    public function form($instance)
    {
        $title = isset($instance['title']) ? $instance['title'] : __('岁月同一天', 'kratos');
        $limit = isset($instance['limit']) ? (int) $instance['limit'] : 5;
        ?>
        <p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('标题：', 'kratos'); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" /></p>
        <p><label for="<?php echo $this->get_field_id('limit'); ?>"><?php _e('展示数量：', 'kratos'); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('limit'); ?>" name="<?php echo $this->get_field_name('limit'); ?>" type="number" min="1" step="1" value="<?php echo esc_attr($limit); ?>" /></p>
        <?php
    }
}

function kratos_otd_register_widget()
{
    register_widget('widget_on_this_day');
}
add_action('widgets_init', 'kratos_otd_register_widget');
