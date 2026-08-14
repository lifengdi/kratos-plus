<?php

/**
 * 阅读增强
 *  - 阅读进度条（顶部随滚动增长）
 *  - 文章字数 & 预计阅读时间
 *  - 文章更新提示条（post_modified 距 post_date 超过阈值时提示）
 *  - 相关文章推荐（按标签/分类匹配）
 *
 * @author Dylan Li (Kratos+) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 阅读增强模块所有配置项的默认值。
 * 作为「数据库无值」以及「后台表单初次渲染」的统一兜底：
 *  - 通过 option_kratos_options / default_option_kratos_options 过滤器补进 get_option 结果
 *    → CSF 渲染 admin 表单时能读到这份默认，输入框有回显
 *  - kratos_option($k, $default) 读取时也用同一份默认，保证前台行为一致
 */
function kratos_read_defaults()
{
    return array(
        'g_read_progress_enabled'      => true,
        'g_read_progress_color_mode'   => 'skin',
        'g_read_progress_color'        => '#0abbef',
        'g_read_progress_height'       => 3,
        'g_read_wordcount_enabled'     => true,
        'g_read_wpm'                   => 300,
        'g_read_wordcount_text'        => '约 %words% 字',
        'g_read_time_text'             => '%minutes% 分钟阅读',
        'g_read_update_notice_enabled' => false,
        'g_read_update_notice_days'    => 180,
        'g_read_update_notice_text'    => '本文最后更新于 %date%，距今已 %days% 天，其中的信息可能已经发生变化，请注意甄别。',
        'g_read_related_enabled'       => true,
        'g_read_related_title'         => '相关文章',
        'g_read_related_style'         => 'grid',
        'g_read_related_thumb'         => true,
        'g_read_related_limit'         => 6,
    );
}

/**
 * kratos_options 整行不存在时，让 get_option 返回一份包含阅读增强默认的数组，
 * 这样 CSF 首次打开设置页也能看到默认值。
 */
add_filter('default_option_kratos_options', function ($default) {
    $defs = kratos_read_defaults();
    if (is_array($default)) {
        return array_merge($defs, $default);
    }
    return $defs;
}, 10, 1);

/**
 * kratos_options 已存在但缺少阅读增强的新 key 时，补上默认值。
 * 使得升级到本版本的老用户，无需重新保存也能看到默认回显 & 生效。
 */
add_filter('option_kratos_options', function ($value) {
    if (!is_array($value)) {
        return $value;
    }
    foreach (kratos_read_defaults() as $k => $v) {
        // 缺 key、或 CSF 保存后写入空串 —— 都兜底为默认值
        if (!array_key_exists($k, $value) || $value[$k] === '' || $value[$k] === null) {
            $value[$k] = $v;
        }
    }
    return $value;
}, 10, 1);

/**
 * 统计文章字数（中英文混合，中文按字计，英文按词计）。
 */
function kratos_read_word_count($content)
{
    $text = wp_strip_all_tags((string) $content);
    $text = preg_replace('/\s+/u', ' ', $text);
    // 中文字符数
    preg_match_all('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]/u', $text, $cn);
    $cn_count = isset($cn[0]) ? count($cn[0]) : 0;
    // 去掉中文后按空格分词
    $ascii = preg_replace('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]/u', ' ', $text);
    $ascii = trim(preg_replace('/\s+/u', ' ', $ascii));
    $en_count = ($ascii === '') ? 0 : count(preg_split('/\s+/', $ascii));
    return $cn_count + $en_count;
}

/**
 * 是否在正文（主查询的 single）中。
 */
function kratos_read_is_single_main()
{
    return is_singular('post') && in_the_loop() && is_main_query();
}

/**
 * 构建更新提示条 HTML（不满足条件返回空字符串）。
 */
function kratos_read_build_update_notice()
{
    if (!kratos_read_is_single_main()) {
        return '';
    }
    if (!kratos_option('g_read_update_notice_enabled', false)) {
        return '';
    }
    $days_threshold = max(1, (int) kratos_option('g_read_update_notice_days', 180));
    $post_id = get_the_ID();
    $modified = get_post_modified_time('U', true, $post_id);
    $published = get_post_time('U', true, $post_id);
    if (!$modified || !$published) {
        return '';
    }
    $now = current_time('timestamp', true);
    $days_since_modified = (int) floor(($now - $modified) / DAY_IN_SECONDS);
    if ($days_since_modified < $days_threshold) {
        return '';
    }
    $tpl = kratos_option(
        'g_read_update_notice_text',
        __('本文最后更新于 %date%，距今已 %days% 天，其中的信息可能已经发生变化，请注意甄别。', 'kratos')
    );
    $msg = str_replace(
        array('%date%', '%days%'),
        array(
            esc_html(get_the_modified_date('', $post_id)),
            (int) $days_since_modified,
        ),
        $tpl
    );
    return '<div class="kratos-update-notice"><i class="fas fa-info-circle"></i><span>' . wp_kses_post($msg) . '</span></div>';
}

/**
 * 输出更新提示条（供 single.php 在顶部广告之前调用）。
 */
function kratos_read_render_update_notice()
{
    echo kratos_read_build_update_notice();
}

/**
 * 渲染字数 & 预计阅读时间的 meta 片段（供 single.php 直接调用）。
 */
function kratos_read_render_meta()
{
    if (!kratos_option('g_read_wordcount_enabled', true)) {
        return;
    }
    $post = get_post();
    if (!$post) return;
    $words = kratos_read_word_count($post->post_content);
    $wpm = max(50, (int) kratos_option('g_read_wpm', 300));
    $minutes = max(1, (int) ceil($words / $wpm));
    $words_tpl = kratos_option('g_read_wordcount_text', __('约 %words% 字', 'kratos'));
    $time_tpl  = kratos_option('g_read_time_text', __('%minutes% 分钟阅读', 'kratos'));
    $words_str = str_replace('%words%', number_format_i18n($words), $words_tpl);
    $time_str  = str_replace('%minutes%', (string) $minutes, $time_tpl);
    // 图标只作为前缀，文案照后台「字数文案 / 阅读时间文案」原样展示；
    // 文字已经把语义说清楚，图标标记为 aria-hidden，避免读屏重复播报。
    echo '<span class="kratos-read-words">'
        . kratos_meta_icon('words')
        . esc_html($words_str) . '</span>';
    echo '<span class="kratos-read-time">'
        . kratos_meta_icon('time')
        . esc_html($time_str) . '</span>';
}

/**
 * 相关文章推荐：先按共享 tag 数排序，其次按同分类。
 */
function kratos_read_get_related($post_id, $limit)
{
    $tags = wp_get_post_tags($post_id, array('fields' => 'ids'));
    $cats = wp_get_post_categories($post_id);
    $args = array(
        'post__not_in'        => array($post_id),
        'posts_per_page'      => $limit,
        'ignore_sticky_posts' => 1,
        'post_status'         => 'publish',
        'no_found_rows'       => true,
        'orderby'             => 'rand',
    );
    if (!empty($tags)) {
        $args['tag__in'] = $tags;
    } elseif (!empty($cats)) {
        $args['category__in'] = $cats;
    } else {
        return array();
    }
    $q = new WP_Query(kratos_lean_query_args($args, array('no_terms' => true)));
    return $q->posts;
}

/**
 * 相关文章渲染（供 single.php 直接调用）。
 */
function kratos_read_render_related()
{
    if (!kratos_option('g_read_related_enabled', true)) {
        return;
    }
    if (!is_singular('post')) return;
    $post_id = get_the_ID();
    $limit = max(2, min(12, (int) kratos_option('g_read_related_limit', 6)));
    $posts = kratos_read_get_related($post_id, $limit);
    if (empty($posts)) return;

    $title = kratos_option('g_read_related_title', __('相关文章', 'kratos'));
    $style = kratos_option('g_read_related_style', 'grid');
    $show_thumb = (bool) kratos_option('g_read_related_thumb', true);
    $allowed_styles = array('grid', 'list', 'compact');
    if (!in_array($style, $allowed_styles, true)) $style = 'grid';
    // list / compact 强制无图（compact 为无图紧凑列表）
    if ($style === 'compact') $show_thumb = false;

    echo '<div class="kratos-related kr-card kratos-related-' . esc_attr($style) . ($show_thumb ? '' : ' no-thumb') . '">';
    if (!empty($title)) {
        echo '<div class="kratos-related-title">' . esc_html($title) . '</div>';
    }
    echo '<ul class="kratos-related-items">';
    foreach ($posts as $p) {
        echo '<li class="kratos-related-item">';
        echo '<a href="' . esc_url(get_permalink($p->ID)) . '" title="' . esc_attr($p->post_title) . '">';
        if ($show_thumb) {
            $thumb = get_the_post_thumbnail_url($p->ID, 'medium');
            if (!$thumb) {
                if (function_exists('kratos_default_thumb_is_text_mode') && kratos_default_thumb_is_text_mode()) {
                    $thumb = kratos_default_thumb_url($p, 512, 288);
                } else {
                    $thumb = kratos_option('g_postthumbnail', get_template_directory_uri() . '/assets/img/default.jpg');
                }
            }
            $thumb_url = (strpos($thumb, 'data:') === 0) ? $thumb : esc_url($thumb);
            echo '<span class="kratos-related-thumb" style="background-image:url(' . esc_attr($thumb_url) . ')"></span>';
        }
        echo '<span class="kratos-related-name">' . esc_html($p->post_title) . '</span>';
        echo '<span class="kratos-related-date">' . esc_html(get_the_date('', $p)) . '</span>';
        echo '</a></li>';
    }
    echo '</ul></div>';
}

/**
 * 前端资源：CSS 内联 + 阅读进度条 JS 内联（都很短，避免多一次请求）。
 */
function kratos_read_enqueue()
{
    if (is_admin()) return;
    if (!is_singular('post')) return;

    $progress_enabled = (bool) kratos_option('g_read_progress_enabled', true);
    $wordcount_enabled = (bool) kratos_option('g_read_wordcount_enabled', true);
    $update_enabled = (bool) kratos_option('g_read_update_notice_enabled', false);
    $related_enabled = (bool) kratos_option('g_read_related_enabled', true);

    if (!$progress_enabled && !$wordcount_enabled && !$update_enabled && !$related_enabled) {
        return;
    }

    $progress_color_mode = kratos_option('g_read_progress_color_mode', 'skin'); // skin | custom
    $progress_color = kratos_option('g_read_progress_color', '#0abbef');
    $progress_height = max(1, (int) kratos_option('g_read_progress_height', 3));
    // 跟随皮肤：优先用每日皮肤 --kr-skin-accent，回退到用户自定义色
    $progress_bg = ($progress_color_mode === 'skin')
        ? 'var(--kr-skin-accent, ' . esc_attr($progress_color) . ')'
        : esc_attr($progress_color);

    $css = '';
    if ($progress_enabled) {
        $css .= '.kratos-read-progress{position:fixed;top:0;left:0;height:' . $progress_height . 'px;width:0;background:' . $progress_bg . ';z-index:9999;transition:width .1s linear;pointer-events:none}';
    }
    if ($wordcount_enabled) {
    }
    if ($update_enabled) {
        $css .= '.kratos-update-notice{display:flex;align-items:center;gap:10px;padding:12px 16px;margin:0 0 20px;background:#fff8e1;border-left:4px solid #f0ad4e;border-radius:4px;color:#6b4a00;font-size:14px;line-height:1.6}';
        $css .= '.kratos-update-notice i{color:#f0ad4e;font-size:18px}';
        $css .= '[data-theme="dark"] .kratos-update-notice{background:#3a2f14;border-left-color:#c78a2b;color:#f5d999}';
    }
    if ($related_enabled) {
        $accent = 'var(--kr-skin-accent, #0abbef)';
        // 通用
        $css .= '.kratos-related{margin:24px 0;padding:20px;background:var(--kr-skin-card-bg,#fff);border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.06);color:var(--kr-skin-text,inherit)}';
        $css .= '.kratos-related-title{font-size:18px;font-weight:600;margin-bottom:16px;padding-left:10px;border-left:4px solid ' . $accent . ';color:var(--kr-skin-heading,inherit)}';
        $css .= '.kratos-related-items{list-style:none;margin:0;padding:0}';
        $css .= '.kratos-related-item a{display:block;color:inherit;text-decoration:none;transition:transform .2s,color .2s}';
        $css .= '.kratos-related-item a:hover{color:' . $accent . '}';
        // grid（默认）
        $css .= '.kratos-related-grid .kratos-related-items{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px}';
        $css .= '.kratos-related-grid .kratos-related-item a{border-radius:4px;overflow:hidden}';
        $css .= '.kratos-related-grid .kratos-related-item a:hover{transform:translateY(-3px)}';
        $css .= '.kratos-related-grid .kratos-related-thumb{display:block;width:100%;padding-top:56%;background-size:cover;background-position:center;background-color:#eee;border-radius:4px}';
        $css .= '.kratos-related-grid .kratos-related-name{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-overflow:ellipsis;font-size:14px;line-height:1.4;margin-top:8px;max-height:2.8em}';
        $css .= '.kratos-related-grid .kratos-related-date{display:block;font-size:12px;color:#999;margin-top:4px}';
        $css .= '.kratos-related-grid.no-thumb .kratos-related-item a{padding:10px 12px;background:rgba(0,0,0,.02);border-radius:4px}';
        // list（横向卡片：左图右文）
        $css .= '.kratos-related-list .kratos-related-item{margin-bottom:12px}';
        $css .= '.kratos-related-list .kratos-related-item:last-child{margin-bottom:0}';
        $css .= '.kratos-related-list .kratos-related-item a{display:flex;align-items:center;gap:14px;padding:10px;border-radius:4px;background:rgba(0,0,0,.02)}';
        $css .= '.kratos-related-list .kratos-related-item a:hover{background:rgba(0,0,0,.05)}';
        $css .= '.kratos-related-list .kratos-related-thumb{flex:0 0 120px;height:72px;background-size:cover;background-position:center;background-color:#eee;border-radius:4px}';
        $css .= '.kratos-related-list .kratos-related-name{flex:1;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:15px;line-height:1.5;max-height:3em}';
        $css .= '.kratos-related-list .kratos-related-date{flex:0 0 auto;font-size:12px;color:#999}';
        $css .= '.kratos-related-list.no-thumb .kratos-related-item a{padding:10px 14px}';
        // compact（无图紧凑列表）
        $css .= '.kratos-related-compact .kratos-related-items{display:block}';
        $css .= '.kratos-related-compact .kratos-related-item{border-bottom:1px dashed rgba(0,0,0,.08)}';
        $css .= '.kratos-related-compact .kratos-related-item:last-child{border-bottom:none}';
        $css .= '.kratos-related-compact .kratos-related-item a{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 4px}';
        $css .= '.kratos-related-compact .kratos-related-item a::before{content:"•";color:' . $accent . ';font-weight:bold}';
        $css .= '.kratos-related-compact .kratos-related-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:14px}';
        $css .= '.kratos-related-compact .kratos-related-date{flex:0 0 auto;font-size:12px;color:#999}';
        // 暗色
        $css .= '[data-theme="dark"] .kratos-related{background:#1e1f22;box-shadow:0 1px 3px rgba(0,0,0,.4)}';
        $css .= '[data-theme="dark"] .kratos-related-date{color:#888}';
        $css .= '[data-theme="dark"] .kratos-related-list .kratos-related-item a,[data-theme="dark"] .kratos-related-grid.no-thumb .kratos-related-item a{background:rgba(255,255,255,.04)}';
        $css .= '[data-theme="dark"] .kratos-related-list .kratos-related-item a:hover{background:rgba(255,255,255,.08)}';
        $css .= '[data-theme="dark"] .kratos-related-compact .kratos-related-item{border-bottom-color:rgba(255,255,255,.08)}';
    }
    if ($css !== '') {
        wp_register_style('kratos-read', false);
        wp_enqueue_style('kratos-read');
        wp_add_inline_style('kratos-read', $css);
    }

    if ($progress_enabled) {
        $js = '(function(){var b=document.createElement("div");b.className="kratos-read-progress";document.body.appendChild(b);var t;function u(){var h=document.documentElement,d=document.body,sh=(h.scrollHeight||d.scrollHeight)-h.clientHeight;var st=h.scrollTop||d.scrollTop;var p=sh>0?Math.min(100,Math.max(0,st/sh*100)):0;b.style.width=p+"%";}window.addEventListener("scroll",function(){if(t)return;t=requestAnimationFrame(function(){t=null;u();});},{passive:true});u();})();';
        wp_register_script('kratos-read', '', array(), THEME_VERSION, true);
        wp_enqueue_script('kratos-read');
        wp_add_inline_script('kratos-read', $js);
    }
}
add_action('wp_enqueue_scripts', 'kratos_read_enqueue', 30);
