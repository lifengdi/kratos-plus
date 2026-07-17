<?php

/**
 * Now 页面 —— 我最近在做什么
 *
 * - 自定义文章类型 `kratos_now`：每次「更新 Now」= 发一条新 post
 *   最新一条是当前展示，历史条目按时间倒序列在下方形成 Now 时间流
 * - `page-now.php` 页面模板
 * - `[now]` 短代码：把最新一条 Now 卡片嵌入任意页面 / 说说
 *
 * 数据字段：
 *   - post_title  可选（标题栏，显示在卡片顶部一句话；留空自动生成时间戳）
 *   - post_content 正文（支持 markdown 或 HTML）
 *   - meta:kratos_now_mood  心情 emoji（自定义字段，可从后台填）
 *   - meta:kratos_now_location 地点
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

const KRATOS_NOW_CPT = 'kratos_now';

function kratos_now_register_cpt()
{
    register_post_type(KRATOS_NOW_CPT, array(
        'labels' => array(
            'name'          => __('Now 动态', 'kratos'),
            'singular_name' => __('Now 动态', 'kratos'),
            'menu_name'     => __('Now', 'kratos'),
            'add_new'       => __('新一条 Now', 'kratos'),
            'add_new_item'  => __('新一条 Now', 'kratos'),
            'edit_item'     => __('编辑 Now', 'kratos'),
            'search_items'  => __('搜索 Now', 'kratos'),
            'not_found'     => __('还没有任何 Now', 'kratos'),
            'not_found_in_trash' => __('回收站为空', 'kratos'),
        ),
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_admin_bar'   => true,
        'show_in_rest'        => true,
        'menu_position'       => 7,
        'menu_icon'           => 'dashicons-clock',
        'has_archive'         => false,
        'rewrite'             => false,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'hierarchical'        => false,
        'capability_type'     => 'post',
        'supports'            => array('title', 'editor', 'custom-fields', 'revisions'),
    ));
}
add_action('init', 'kratos_now_register_cpt');

/**
 * 后台标题占位符提示
 */
function kratos_now_title_placeholder($placeholder, $post)
{
    if ($post && $post->post_type === KRATOS_NOW_CPT) {
        return __('一句话状态（例：正在写 Kratos+ v1.2 皮肤）', 'kratos');
    }
    return $placeholder;
}
add_filter('enter_title_here', 'kratos_now_title_placeholder', 10, 2);

/**
 * 后台编辑器右侧加一个 metabox 用来填心情 / 地点，避免用户去翻自定义字段
 */
function kratos_now_metabox()
{
    add_meta_box('kratos_now_meta', __('Now 附加信息', 'kratos'), 'kratos_now_metabox_render', KRATOS_NOW_CPT, 'side', 'default');
}
add_action('add_meta_boxes', 'kratos_now_metabox');

function kratos_now_metabox_render($post)
{
    wp_nonce_field('kratos_now_meta', 'kratos_now_meta_nonce');
    $mood     = (string) get_post_meta($post->ID, 'kratos_now_mood', true);
    $location = (string) get_post_meta($post->ID, 'kratos_now_location', true);
    ?>
    <p>
        <label for="kratos_now_mood" style="display:block;margin-bottom:4px;font-weight:600;">
            <?php _e('心情 emoji（可留空）', 'kratos'); ?>
        </label>
        <input type="text" id="kratos_now_mood" name="kratos_now_mood" value="<?php echo esc_attr($mood); ?>" class="widefat" placeholder="🌤 / ☕️ / 🌙 ..." maxlength="16" />
    </p>
    <p>
        <label for="kratos_now_location" style="display:block;margin-bottom:4px;font-weight:600;">
            <?php _e('地点（可留空）', 'kratos'); ?>
        </label>
        <input type="text" id="kratos_now_location" name="kratos_now_location" value="<?php echo esc_attr($location); ?>" class="widefat" placeholder="<?php esc_attr_e('例：杭州 / 家里 / 咖啡馆', 'kratos'); ?>" />
    </p>
    <?php
}

function kratos_now_save_meta($post_id, $post)
{
    if ($post->post_type !== KRATOS_NOW_CPT) return;
    if (!isset($_POST['kratos_now_meta_nonce']) || !wp_verify_nonce($_POST['kratos_now_meta_nonce'], 'kratos_now_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $mood = isset($_POST['kratos_now_mood']) ? sanitize_text_field(wp_unslash($_POST['kratos_now_mood'])) : '';
    $location = isset($_POST['kratos_now_location']) ? sanitize_text_field(wp_unslash($_POST['kratos_now_location'])) : '';
    update_post_meta($post_id, 'kratos_now_mood', $mood);
    update_post_meta($post_id, 'kratos_now_location', $location);
}
add_action('save_post', 'kratos_now_save_meta', 10, 2);

/**
 * 空标题自动生成时间戳（沿用说说的思路，因为标题栏就是「一句话状态」，留空的话至少有个可读标识）
 */
function kratos_now_auto_title($post_id, $post, $update)
{
    if ($post->post_type !== KRATOS_NOW_CPT) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    $title = trim((string) $post->post_title);
    if ($title !== '' && strcasecmp($title, 'Auto Draft') !== 0) return;

    $ts = $post->post_date ? strtotime($post->post_date) : (int) current_time('timestamp');
    if (!$ts || $ts <= 0) $ts = (int) current_time('timestamp');
    $new_title = sprintf('%s %s', __('Now', 'kratos'), date_i18n('Y-m-d H:i', $ts));

    remove_action('save_post_' . KRATOS_NOW_CPT, 'kratos_now_auto_title', 20);
    wp_update_post(array(
        'ID'         => $post_id,
        'post_title' => $new_title,
        'post_name'  => sanitize_title($new_title),
    ));
    add_action('save_post_' . KRATOS_NOW_CPT, 'kratos_now_auto_title', 20, 3);
}
add_action('save_post_' . KRATOS_NOW_CPT, 'kratos_now_auto_title', 20, 3);

/**
 * 取最新的 N 条 Now（第一条 = current，其余 = 历史）
 *
 * @return WP_Post[]
 */
function kratos_now_get_items($limit = 20)
{
    $q = new WP_Query(array(
        'post_type'      => KRATOS_NOW_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => max(1, (int) $limit),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
        'ignore_sticky_posts' => true,
    ));
    return $q->posts;
}

/**
 * 渲染单条 Now（内部使用，供页面模板和短代码复用）
 *
 * @param WP_Post $post
 * @param bool $is_current 是否是当前最新一条（大卡片）
 */
function kratos_now_render_hero($post)
{
    if (!$post) return;
    $mood     = trim((string) get_post_meta($post->ID, 'kratos_now_mood', true));
    $location = trim((string) get_post_meta($post->ID, 'kratos_now_location', true));
    $title    = get_the_title($post);
    $content  = apply_filters('the_content', $post->post_content);
    $time_full = get_the_date(get_option('date_format') . ' ' . get_option('time_format'), $post);
    $time_ago  = human_time_diff(get_post_time('U', true, $post), current_time('timestamp', true)) . __('前', 'kratos');
    $mood_display = $mood !== '' ? $mood : '🌤';
    ?>
    <article class="knw-hero kr-card">
        <header class="knw-hero-head">
            <span class="knw-hero-ico kr-ico" aria-hidden="true"><?php echo esc_html($mood_display); ?></span>
            <span class="knw-hero-badge">NOW</span>
            <span class="knw-hero-ago" title="<?php echo esc_attr($time_full); ?>">
                <?php echo esc_html(sprintf(__('上次更新 %s前', 'kratos'), $time_ago === '' ? '' : $time_ago)); ?>
            </span>
        </header>
        <?php if ($title !== '') { ?>
            <h2 class="knw-hero-title"><?php echo esc_html($title); ?></h2>
        <?php } ?>
        <?php if (trim(wp_strip_all_tags($content)) !== '') { ?>
            <div class="knw-hero-content"><?php echo $content; ?></div>
        <?php } ?>
        <footer class="knw-hero-meta">
            <?php if ($location !== '') { ?>
                <span class="knw-hero-loc">📍 <?php echo esc_html($location); ?></span>
            <?php } ?>
            <span class="knw-hero-time"><?php echo esc_html($time_full); ?></span>
        </footer>
    </article>
    <?php
}

function kratos_now_render_timeline_item($post)
{
    if (!$post) return;
    $mood     = trim((string) get_post_meta($post->ID, 'kratos_now_mood', true));
    $location = trim((string) get_post_meta($post->ID, 'kratos_now_location', true));
    $title    = get_the_title($post);
    $content  = apply_filters('the_content', $post->post_content);
    $date_short = get_the_date('Y-m-d', $post);
    $time_short = get_the_date(get_option('time_format'), $post);
    $time_full  = get_the_date(get_option('date_format') . ' ' . get_option('time_format'), $post);
    ?>
    <article class="knw-item kr-card">
        <aside class="knw-item-side">
            <span class="knw-t-dot kr-dot" aria-hidden="true"></span>
            <time class="knw-item-date" datetime="<?php echo esc_attr(get_the_date('c', $post)); ?>" title="<?php echo esc_attr($time_full); ?>">
                <span class="knw-item-day"><?php echo esc_html($date_short); ?></span>
                <span class="knw-item-time"><?php echo esc_html($time_short); ?></span>
            </time>
        </aside>
        <div class="knw-item-body">
            <header class="knw-item-head">
                <?php if ($mood !== '') { ?>
                    <span class="knw-item-mood" aria-hidden="true"><?php echo esc_html($mood); ?></span>
                <?php } ?>
                <?php if ($title !== '') { ?>
                    <h3 class="knw-item-title"><?php echo esc_html($title); ?></h3>
                <?php } ?>
            </header>
            <?php if (trim(wp_strip_all_tags($content)) !== '') { ?>
                <div class="knw-item-content"><?php echo $content; ?></div>
            <?php } ?>
            <?php if ($location !== '') { ?>
                <footer class="knw-item-meta">
                    <span class="knw-item-loc">📍 <?php echo esc_html($location); ?></span>
                </footer>
            <?php } ?>
        </div>
    </article>
    <?php
}

// 向后兼容：`[now]` 短代码仍复用 hero 渲染
function kratos_now_render_item($post, $is_current = false)
{
    if ($is_current) {
        kratos_now_render_hero($post);
    } else {
        kratos_now_render_timeline_item($post);
    }
}

/**
 * 短代码 [now] —— 嵌入最新一条 Now 卡片（供其他页面 / 说说复用）
 */
function kratos_now_shortcode($atts)
{
    $items = kratos_now_get_items(1);
    if (empty($items)) return '';

    ob_start(); ?>
    <div class="kratos-now kratos-now-embed">
        <?php kratos_now_render_hero($items[0]); ?>
    </div>
    <?php echo kratos_now_inline_assets();
    return ob_get_clean();
}
add_shortcode('now', 'kratos_now_shortcode');

/**
 * Now 页面 body class
 */
function kratos_now_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-now.php')) {
        $classes[] = 'is-kratos-now-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_now_body_class');

/**
 * Now 页面 / 短代码内联样式（每个请求最多一次）
 */
function kratos_now_inline_assets()
{
    static $printed = false;
    if ($printed) return '';
    $printed = true;
    ob_start(); ?>
    <style>
        /* Now 页面骨架（对齐 page-now.php 当前布局）:
         *   header.kratos-now-header.kr-hd   顶部标题卡（h1 + 副标题 + 上次更新提示）
         *   .content                          页面正文（可选引导语）
         *   .kratos-now                       当前 Now 容器
         *     └─ article.knw-hero.kr-card    英雄大卡
         *     └─ .kratos-now-empty           空态兜底卡
         *   .kratos-now-history.kr-body       历史主体卡
         *     └─ h2.kratos-now-history-title
         *     └─ article.knw-item.kr-card * N 历史条目卡（左日期栏 + 右标题/正文/元信息）
         *
         * 卡片形态由 components.css + 皮肤在 .kr-hd / .kr-body / .kr-card 上写的规则接管；
         * 图标胶囊/圆点走 .kr-ico / .kr-dot 公共类。本文件只写 Now 特有排版 + 皮肤关闭态兜底。 */

        .kratos-now{margin:0 0 20px;}

        /* 皮肤关闭态：所有卡片外壳的默认背景/边框/阴影 */
        html:not([data-weekday-skin]) .kratos-now-header,
        html:not([data-weekday-skin]) .kratos-now .knw-hero,
        html:not([data-weekday-skin]) .kratos-now-history,
        html:not([data-weekday-skin]) .kratos-now-history .knw-item,
        html:not([data-weekday-skin]) .kratos-now-empty{
            background:var(--khs-card-bg,#fff);
            border:1px solid var(--khs-line,rgba(0,0,0,.06));
            box-shadow:0 1px 3px rgba(0,0,0,.04);
        }
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-header,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now .knw-hero,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-history,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-history .knw-item,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-empty{
            background:rgba(255,255,255,.04);
            border-color:rgba(255,255,255,.08);
            box-shadow:0 2px 6px rgba(0,0,0,.3);
        }

        /* ---------- 顶部标题卡 ---------- */
        .kratos-now-header{padding:24px 30px;margin:0 0 20px;}
        .kratos-now-header .knw-h-title{display:flex;align-items:center;gap:10px;margin:0;font-size:22px;font-weight:700;color:var(--khs-fg,#222);line-height:1.3;}
        .kratos-now-header .knw-h-title::before{content:"";display:inline-block;width:4px;height:20px;background:var(--khs-accent,#336699);border-radius:2px;}
        .kratos-now-header .knw-h-sub{margin:10px 0 0;padding-left:14px;font-size:14px;color:var(--khs-fg-dim,#888);line-height:1.7;}
        .kratos-now-header .knw-h-updated{margin:8px 0 0;padding-left:14px;font-size:12px;color:var(--khs-accent,#336699);letter-spacing:.3px;}

        /* ---------- 英雄大卡（当前 Now） ---------- */
        .kratos-now .knw-hero{padding:32px 36px;margin:0 0 24px;position:relative;}
        .kratos-now .knw-hero-head{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;}
        .kratos-now .knw-hero-ico{width:48px;height:48px;display:inline-flex;align-items:center;justify-content:center;font-size:26px;line-height:1;flex-shrink:0;}
        .kratos-now .knw-hero-badge{display:inline-block;font-size:11px;font-weight:800;letter-spacing:3px;color:var(--khs-accent,#336699);background:color-mix(in srgb, var(--khs-accent,#336699) 14%, transparent);padding:4px 12px;border-radius:999px;}
        .kratos-now .knw-hero-ago{font-size:12px;color:var(--khs-fg-dim,#999);letter-spacing:.5px;margin-left:auto;}
        .kratos-now .knw-hero-title{margin:0 0 14px;font-size:26px;font-weight:700;line-height:1.35;color:var(--khs-fg,#1f2937);letter-spacing:.5px;}
        .kratos-now .knw-hero-content{font-size:16px;line-height:1.9;color:var(--khs-fg-soft,var(--khs-fg,#2c2c2c));word-break:break-word;}
        .kratos-now .knw-hero-content p{margin:0 0 12px;}
        .kratos-now .knw-hero-content p:last-child{margin-bottom:0;}
        .kratos-now .knw-hero-content a{color:var(--khs-accent,#336699);}
        .kratos-now .knw-hero-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:20px;padding-top:14px;border-top:1px dashed var(--khs-line,rgba(0,0,0,.08));font-size:12px;color:var(--khs-fg-dim,#999);}
        .kratos-now .knw-hero-loc{color:var(--khs-fg-dim,#888);}
        .kratos-now .knw-hero-time{margin-left:auto;font-variant-numeric:tabular-nums;}

        /* ---------- 历史卡（主体卡 + 历史条目卡） ---------- */
        .kratos-now-history{padding:26px 30px;margin:0 0 20px;}
        .kratos-now-history-title{margin:0 0 20px;font-size:12px;font-weight:800;letter-spacing:4px;color:var(--khs-fg-dim,#999);text-transform:uppercase;display:flex;align-items:center;gap:12px;}
        .kratos-now-history-title::before{content:"";display:inline-block;width:20px;height:2px;background:var(--khs-accent,#336699);}
        .kratos-now-history-title::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,var(--khs-line,rgba(0,0,0,.12)),transparent);}

        .kratos-now-history .knw-item{display:grid;grid-template-columns:120px 1fr;gap:18px;align-items:start;padding:18px 22px;margin:0 0 14px;}
        .kratos-now-history .knw-item:last-child{margin-bottom:0;}
        .kratos-now-history .knw-item-side{display:flex;align-items:center;gap:10px;padding-top:2px;}
        .kratos-now-history .knw-t-dot{width:10px;height:10px;background:var(--khs-card-bg,#fff);border:2px solid var(--khs-accent,#336699);border-radius:50%;box-sizing:border-box;flex-shrink:0;}
        .kratos-now-history .knw-item-date{display:flex;flex-direction:column;font-variant-numeric:tabular-nums;line-height:1.2;}
        .kratos-now-history .knw-item-day{font-size:13px;font-weight:700;color:var(--khs-fg,#333);letter-spacing:.3px;}
        .kratos-now-history .knw-item-time{font-size:11px;color:var(--khs-fg-dim,#999);margin-top:3px;}
        .kratos-now-history .knw-item-body{min-width:0;}
        .kratos-now-history .knw-item-head{display:flex;align-items:center;gap:8px;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid var(--khs-line,rgba(0,0,0,.06));}
        .kratos-now-history .knw-item-mood{font-size:18px;line-height:1;flex-shrink:0;}
        .kratos-now-history .knw-item-title{margin:0;font-size:15px;font-weight:700;color:var(--khs-fg,#222);line-height:1.5;}
        .kratos-now-history .knw-item-content{font-size:14px;line-height:1.75;color:var(--khs-fg-soft,var(--khs-fg,#444));word-break:break-word;}
        .kratos-now-history .knw-item-content p{margin:0 0 6px;}
        .kratos-now-history .knw-item-content p:last-child{margin-bottom:0;}
        .kratos-now-history .knw-item-content a{color:var(--khs-accent,#336699);}
        .kratos-now-history .knw-item-meta{margin-top:8px;padding-top:8px;border-top:1px dashed var(--khs-line,rgba(0,0,0,.08));font-size:11px;color:var(--khs-fg-dim,#999);}

        /* ---------- 空态 ---------- */
        .kratos-now-empty{padding:60px 24px;text-align:center;color:var(--khs-fg-dim,#999);font-size:14px;margin-bottom:24px;}

        /* ---------- 响应式 ---------- */
        @media (max-width:576px){
            .kratos-now-header{padding:20px 22px;}
            .kratos-now-header .knw-h-title{font-size:19px;}
            .kratos-now .knw-hero{padding:22px 20px;}
            .kratos-now .knw-hero-title{font-size:22px;}
            .kratos-now .knw-hero-content{font-size:15px;}
            .kratos-now .knw-hero-ago{width:100%;margin-left:0;}
            .kratos-now-history{padding:20px 18px;}
            .kratos-now-history .knw-item{grid-template-columns:1fr;gap:10px;padding:14px 16px;}
            .kratos-now-history .knw-item-side{padding-top:0;}
        }

        /* ---------- 无皮肤兜底：暗夜模式文本色 ---------- */
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-header .knw-h-title,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now .knw-hero-title,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-history .knw-item-title,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-history .knw-item-day{color:#e8e4ec;}
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now .knw-hero-content,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-history .knw-item-content{color:#d8d8de;}
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now .knw-hero-meta,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-history .knw-item-head,
        html[data-theme="dark"]:not([data-weekday-skin]) .kratos-now-history .knw-item-meta{border-color:rgba(255,255,255,.08);}
    </style>
    <?php return ob_get_clean();
}
