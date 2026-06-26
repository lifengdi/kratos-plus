<?php

/**
 * 说说（朋友圈式短动态）
 *
 * - 注册自定义文章类型 `shuoshuo`，与博客文章 `post` 完全隔离：
 *   不参与首页主循环、不出现在 RSS / 搜索结果里。
 * - 后台发表时不需要填标题：保存时若为空，自动生成 `说说 YYYY-MM-DD HH:mm:ss`。
 * - 前台展示由页面模板 `page-shuoshuo.php` + 短码 `[shuoshuo_feed]` 提供。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

const KRATOS_SHUOSHUO_CPT = 'shuoshuo';

/* ============================================================
 *  注册自定义文章类型
 * ============================================================ */

function kratos_shuoshuo_register_cpt()
{
    $labels = array(
        'name'               => __('说说', 'kratos'),
        'singular_name'      => __('说说', 'kratos'),
        'menu_name'          => __('说说', 'kratos'),
        'add_new'            => __('发布说说', 'kratos'),
        'add_new_item'       => __('发布新说说', 'kratos'),
        'edit_item'          => __('编辑说说', 'kratos'),
        'new_item'           => __('新说说', 'kratos'),
        'view_item'          => __('查看说说', 'kratos'),
        'search_items'       => __('搜索说说', 'kratos'),
        'not_found'          => __('暂无说说', 'kratos'),
        'not_found_in_trash' => __('回收站为空', 'kratos'),
    );

    register_post_type(KRATOS_SHUOSHUO_CPT, array(
        'labels'              => $labels,
        'public'              => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_admin_bar'   => true,
        'show_in_nav_menus'   => true,
        'menu_position'       => 6,
        'menu_icon'           => 'dashicons-format-status',
        'has_archive'         => false,
        'rewrite'             => array('slug' => 'shuoshuo', 'with_front' => false),
        'exclude_from_search' => true,
        'publicly_queryable'  => true,
        'hierarchical'        => false,
        'capability_type'     => 'post',
        'supports'            => array('editor', 'author', 'thumbnail', 'comments', 'custom-fields'),
    ));
}
add_action('init', 'kratos_shuoshuo_register_cpt');

/**
 * CPT 注册后一次性刷新固定链接，避免 `/?shuoshuo=...` 单条页 404。
 *
 * 用 option 做幂等标记；rewrite 规则版本变了（比如改 slug）时 bump 这个版本号即可重新刷一次。
 */
function kratos_shuoshuo_maybe_flush_rewrites()
{
    $version = '1';
    if (get_option('kratos_shuoshuo_rewrite_flushed') === $version) {
        return;
    }
    flush_rewrite_rules(false);
    update_option('kratos_shuoshuo_rewrite_flushed', $version, false);
}
add_action('init', 'kratos_shuoshuo_maybe_flush_rewrites', 11);

/**
 * 把说说从首页主循环里排除，避免和博客文章混在一起。
 * 页面模板内部用自己的 WP_Query 单独取数据，不受这条过滤影响。
 */
function kratos_shuoshuo_exclude_from_home($query)
{
    if (is_admin() || !$query->is_main_query()) {
        return;
    }
    if ($query->is_home() || $query->is_feed()) {
        $types = $query->get('post_type');
        if (empty($types)) {
            $types = array('post');
        } elseif (is_string($types)) {
            $types = array($types);
        }
        $types = array_diff((array) $types, array(KRATOS_SHUOSHUO_CPT));
        $query->set('post_type', array_values($types));
    }
}
add_action('pre_get_posts', 'kratos_shuoshuo_exclude_from_home');

/* ============================================================
 *  自动标题：说说 YYYY-MM-DD HH:mm:ss
 * ============================================================ */

/**
 * 后台编辑器里把"添加标题"占位符换成提示语，引导用户直接写内容。
 */
function kratos_shuoshuo_title_placeholder($placeholder, $post)
{
    if ($post && $post->post_type === KRATOS_SHUOSHUO_CPT) {
        return __('（标题会自动生成，无需填写）', 'kratos');
    }
    return $placeholder;
}
add_filter('enter_title_here', 'kratos_shuoshuo_title_placeholder', 10, 2);

/**
 * 保存时若标题为空 / 是默认 "Auto Draft"，自动写为 "说说 + 时间戳"。
 *
 * 必须用 wp_update_post（不能直接 update_post_meta），因为标题是 post_title 字段。
 * 这里用 remove_action / add_action 自防递归。
 */
function kratos_shuoshuo_auto_title($post_id, $post, $update)
{
    if ($post->post_type !== KRATOS_SHUOSHUO_CPT) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    $title = trim((string) $post->post_title);
    $needs_title = ($title === '' || strcasecmp($title, 'Auto Draft') === 0);

    if (!$needs_title) {
        return;
    }

    $ts = $post->post_date ? strtotime($post->post_date) : false;
    if (!$ts || $ts <= 0) {
        // 新建草稿时 post_date 可能是 0000-00-00，用当前 WP 时间兜底
        $ts = (int) current_time('timestamp');
    }
    $new_title = sprintf(
        '%s %s',
        __('说说', 'kratos'),
        date_i18n('Y-m-d H:i:s', $ts)
    );

    remove_action('save_post_' . KRATOS_SHUOSHUO_CPT, 'kratos_shuoshuo_auto_title', 20);
    wp_update_post(array(
        'ID'         => $post_id,
        'post_title' => $new_title,
        'post_name'  => sanitize_title($new_title),
    ));
    add_action('save_post_' . KRATOS_SHUOSHUO_CPT, 'kratos_shuoshuo_auto_title', 20, 3);
}
add_action('save_post_' . KRATOS_SHUOSHUO_CPT, 'kratos_shuoshuo_auto_title', 20, 3);

/* ============================================================
 *  内容解析：把正文里的图片抽出来当九宫格，剩余文本单独显示
 * ============================================================ */

/**
 * 从一条说说的正文里抽出图片 url 列表 + 不含图片的纯文本。
 *
 * 处理顺序：
 *   1. 取所有 <img src="..."> 的 src（按出现顺序，去重）
 *   2. 把 <a><img></a> / <figure>...<img>...</figure> / 裸 <img> 全部从正文里删掉
 *   3. 把剩下的内容用 wpautop / 短码处理后返回
 *
 * @param string $content 原始 post_content
 * @return array{images: string[], text_html: string}
 */
function kratos_shuoshuo_split_content($content)
{
    $images = array();

    if (!empty($content) && stripos($content, '<img') !== false) {
        if (preg_match_all('#<img\b[^>]*?\bsrc=([\'"])([^\'"]+?)\1[^>]*>#i', $content, $m)) {
            foreach ($m[2] as $src) {
                $src = trim($src);
                if ($src !== '' && !in_array($src, $images, true)) {
                    $images[] = $src;
                }
            }
        }
    }

    // 移除 <figure>...</figure>（可能包含 <img> 与 <figcaption>）
    $stripped = preg_replace('#<figure\b[^>]*>.*?</figure>#is', '', $content);
    // 移除 <a><img></a>
    $stripped = preg_replace('#<a\b[^>]*>\s*<img\b[^>]*>\s*</a>#is', '', $stripped);
    // 移除裸 <img>
    $stripped = preg_replace('#<img\b[^>]*>#i', '', $stripped);
    // 清掉只剩空白的段落
    $stripped = preg_replace('#<p[^>]*>\s*(&nbsp;)?\s*</p>#i', '', $stripped);
    $stripped = trim((string) $stripped);

    // 走主题 the_content 过滤链中关键的格式化逻辑（短码 / wpautop / 表情）
    $text_html = '';
    if ($stripped !== '') {
        $text_html = do_shortcode($stripped);
        $text_html = wpautop($text_html);
        $text_html = convert_smilies($text_html);
    }

    return array(
        'images'    => $images,
        'text_html' => $text_html,
    );
}

/* ============================================================
 *  短码 [shuoshuo_feed] —— 朋友圈样式列表
 * ============================================================ */

function kratos_shuoshuo_feed_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'per_page' => 10,
    ), $atts, 'shuoshuo_feed');

    $per_page = max(1, (int) $atts['per_page']);
    $paged    = max(1, (int) get_query_var('paged') ?: (int) get_query_var('page') ?: 1);
    if ($paged < 1) $paged = 1;
    if (isset($_GET['ss_page'])) {
        $paged = max(1, (int) $_GET['ss_page']);
    }

    // 折叠阈值（可见字符数，剥 HTML 标签后再算）；0 = 关闭折叠
    $collapse_limit = (int) kratos_option('shuoshuo_collapse_limit', 300);
    if ($collapse_limit < 0) $collapse_limit = 0;

    $q = new WP_Query(array(
        'post_type'      => KRATOS_SHUOSHUO_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'ignore_sticky_posts' => true,
    ));

    ob_start();
    ?>
    <div class="kratos-shuoshuo" id="kratos-shuoshuo-feed" data-lightbox-host="1">
        <?php if (!$q->have_posts()) { ?>
            <div class="kss-empty">
                <?php esc_html_e('还没有说说，写一条吧 ✨', 'kratos'); ?>
            </div>
        <?php } else { ?>
            <ul class="kss-list">
                <?php while ($q->have_posts()) {
                    $q->the_post();
                    $post_id    = get_the_ID();
                    $author_id  = (int) get_post_field('post_author', $post_id);
                    $author     = get_the_author_meta('display_name', $author_id);
                    if (!$author) {
                        $author = get_bloginfo('name');
                    }
                    $avatar     = get_avatar($author_id, 96, '', $author, array('class' => 'kss-avatar-img'));
                    $time_human = human_time_diff(get_the_time('U', $post_id), current_time('timestamp')) . __('前', 'kratos');
                    $time_full  = get_the_date(get_option('date_format') . ' ' . get_option('time_format'), $post_id);
                    $permalink  = get_permalink($post_id);

                    $parts  = kratos_shuoshuo_split_content(get_the_content('', false, $post_id));
                    $images = $parts['images'];
                    $text   = $parts['text_html'];
                    $img_count = count($images);

                    // 没有图片时如果有特色图，把特色图当成单图
                    if ($img_count === 0 && has_post_thumbnail($post_id)) {
                        $thumb = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'large');
                        if ($thumb) {
                            $images = array($thumb);
                            $img_count = 1;
                        }
                    }

                    // 九宫格 layout 类
                    if ($img_count === 1) {
                        $grid_class = 'kss-grid-1';
                    } elseif ($img_count === 2) {
                        $grid_class = 'kss-grid-2';
                    } elseif ($img_count === 3) {
                        $grid_class = 'kss-grid-3';
                    } elseif ($img_count === 4) {
                        $grid_class = 'kss-grid-4';
                    } else {
                        $grid_class = 'kss-grid-9';
                    }

                    $love = (int) get_post_meta($post_id, 'love', true);
                    $comment_count = (int) get_comments_number($post_id);
                    $has_loved = isset($_COOKIE['love_' . $post_id]);
                ?>
                    <?php
                    // 折叠判定：剥标签后按 mb_strlen 统计可见字符；超出阈值才折叠
                    // 折叠时显示前 $collapse_limit 个字符（纯文本，截断处加 …），展开时还原完整 HTML
                    $needs_collapse = false;
                    $text_preview   = '';
                    if ($collapse_limit > 0 && $text !== '') {
                        $plain_text = trim(wp_strip_all_tags($text));
                        $plain_len  = mb_strlen($plain_text, 'UTF-8');
                        if ($plain_len > $collapse_limit) {
                            $needs_collapse = true;
                            $text_preview   = mb_substr($plain_text, 0, $collapse_limit, 'UTF-8') . '…';
                        }
                    }
                    ?>
                    <li class="kss-item">
                        <div class="kss-avatar"><?php echo $avatar; ?></div>
                        <div class="kss-body">
                            <div class="kss-author"><?php echo esc_html($author); ?></div>
                            <?php if ($text !== '') { ?>
                                <?php if ($needs_collapse) { ?>
                                    <div class="kss-text kss-collapsible is-collapsed" data-expand="<?php esc_attr_e('展开', 'kratos'); ?>" data-collapse="<?php esc_attr_e('收起', 'kratos'); ?>">
                                        <div class="kss-text-preview"><?php echo esc_html($text_preview); ?></div>
                                        <div class="kss-text-full"><?php echo $text; ?></div>
                                        <button type="button" class="kss-collapse-toggle"><?php esc_html_e('展开', 'kratos'); ?></button>
                                    </div>
                                <?php } else { ?>
                                    <div class="kss-text"><?php echo $text; ?></div>
                                <?php } ?>
                            <?php } ?>

                            <?php if ($img_count > 0) { ?>
                                <div class="kss-images <?php echo esc_attr($grid_class); ?>">
                                    <?php foreach ($images as $i => $src) {
                                        if ($i >= 9) break; // 朋友圈最多 9 张
                                    ?>
                                        <a class="kss-img-cell" href="<?php echo esc_url($src); ?>">
                                            <span class="kss-img-bg" style="background-image:url('<?php echo esc_url($src); ?>');"></span>
                                        </a>
                                    <?php } ?>
                                </div>
                            <?php } ?>

                            <div class="kss-meta">
                                <span class="kss-time" title="<?php echo esc_attr($time_full); ?>"><?php echo esc_html($time_human); ?></span>
                                <span class="kss-actions">
                                    <a class="kss-action kss-like <?php echo $has_loved ? 'done' : ''; ?>" href="javascript:;" data-id="<?php echo (int) $post_id; ?>" title="<?php esc_attr_e('点赞', 'kratos'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-9.5-9.1C1.1 8.6 3 5 6.3 5c1.9 0 3.6 1.1 4.4 2.7C11.6 6.1 13.3 5 15.2 5c3.3 0 5.2 3.6 3.8 6.9C19.5 16.4 12 21 12 21z"/></svg>
                                        <em><?php echo (int) $love; ?></em>
                                    </a>
                                    <a class="kss-action" href="<?php echo esc_url($permalink); ?>#respond" title="<?php esc_attr_e('评论', 'kratos'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                        <em><?php echo (int) $comment_count; ?></em>
                                    </a>
                                </span>
                            </div>
                        </div>
                    </li>
                <?php }
                wp_reset_postdata(); ?>
            </ul>

            <?php
            $total_pages = (int) $q->max_num_pages;
            if ($total_pages > 1) {
                $base_url = remove_query_arg('ss_page');
                $build = function ($p) use ($base_url) {
                    return esc_url(add_query_arg('ss_page', $p, $base_url) . '#kratos-shuoshuo-feed');
                };
                $window = 2;
                $start  = max(1, $paged - $window);
                $end    = min($total_pages, $paged + $window);
            ?>
                <nav class="kss-pagination" aria-label="<?php esc_attr_e('说说分页', 'kratos'); ?>">
                    <?php if ($paged > 1) { ?>
                        <a class="kss-page kss-page-nav" href="<?php echo $build($paged - 1); ?>" rel="prev">&laquo; <?php esc_html_e('上一页', 'kratos'); ?></a>
                    <?php } ?>
                    <?php if ($start > 1) { ?>
                        <a class="kss-page" href="<?php echo $build(1); ?>">1</a>
                        <?php if ($start > 2) { ?><span class="kss-page kss-ellipsis">…</span><?php } ?>
                    <?php } ?>
                    <?php for ($p = $start; $p <= $end; $p++) {
                        if ($p === $paged) { ?>
                            <span class="kss-page kss-current"><?php echo (int) $p; ?></span>
                        <?php } else { ?>
                            <a class="kss-page" href="<?php echo $build($p); ?>"><?php echo (int) $p; ?></a>
                    <?php }
                    } ?>
                    <?php if ($end < $total_pages) { ?>
                        <?php if ($end < $total_pages - 1) { ?><span class="kss-page kss-ellipsis">…</span><?php } ?>
                        <a class="kss-page" href="<?php echo $build($total_pages); ?>"><?php echo (int) $total_pages; ?></a>
                    <?php } ?>
                    <?php if ($paged < $total_pages) { ?>
                        <a class="kss-page kss-page-nav" href="<?php echo $build($paged + 1); ?>" rel="next"><?php esc_html_e('下一页', 'kratos'); ?> &raquo;</a>
                    <?php } ?>
                </nav>
            <?php } ?>
        <?php } ?>
    </div>

    <?php echo kratos_shuoshuo_assets(); ?>
    <?php
    return ob_get_clean();
}
add_shortcode('shuoshuo_feed', 'kratos_shuoshuo_feed_shortcode');

/**
 * 说说列表 / 详情公用的内联样式 + 点赞脚本。
 *
 * 详情页 `.kratos-shuoshuo-single` 在公共基础上做版式加强：
 *   - 卡片更宽、留白更多，头像更大、文字更大
 *   - 与评论区拼成一张连续卡片（评论区贴着说说卡片底部，无背景跳色）
 *
 * 整体配色策略：背景 = 主题底色（外层 .k-main 内联），卡片 = #fff，
 * 文本 = 中性灰阶，强调色统一用 #336699（与主题主色一致），
 * 仅保留点赞红 #e8516e 作为语义化信号，避免视觉色彩过载。
 */
function kratos_shuoshuo_assets()
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;
    ob_start(); ?>
    <style>
        /* ---------- 列表 / 详情共用 ---------- */
        .kratos-shuoshuo .kss-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:14px;}
        .kratos-shuoshuo .kss-item{display:flex;gap:14px;padding:18px 20px;border-radius:2px;background:#fff;border:1px solid rgba(0,0,0,.06);box-shadow:0 1px 3px rgba(0,0,0,.04);transition:box-shadow .2s ease,transform .2s ease;}
        .kratos-shuoshuo .kss-item:hover{box-shadow:0 6px 18px rgba(0,0,0,.08);transform:translateY(-1px);}
        .kratos-shuoshuo .kss-avatar{flex-shrink:0;}
        .kratos-shuoshuo .kss-avatar img,.kratos-shuoshuo .kss-avatar-img{width:48px !important;height:48px !important;border-radius:2px !important;display:block;}
        .kratos-shuoshuo .kss-body{flex:1;min-width:0;}
        .kratos-shuoshuo .kss-author{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:700;color:#1f2937;line-height:1.4;padding-bottom:8px;margin-bottom:10px;border-bottom:1px solid rgba(0,0,0,.06);}
        .kratos-shuoshuo .kss-author::before{content:"";display:inline-block;width:3px;height:14px;background:#336699;flex-shrink:0;border-radius:2px;}
        .kratos-shuoshuo .kss-text{font-size:15px;line-height:1.75;color:#2c2c2c;word-break:break-word;}

        /* ---------- 列表文本折叠：按字符数截断 ---------- */
        .kratos-shuoshuo .kss-collapsible{position:relative;}
        .kratos-shuoshuo .kss-text-preview,.kratos-shuoshuo .kss-text-full{font:inherit;color:inherit;line-height:inherit;}
        .kratos-shuoshuo .kss-collapsible.is-collapsed .kss-text-full{display:none;}
        .kratos-shuoshuo .kss-collapsible:not(.is-collapsed) .kss-text-preview{display:none;}
        .kratos-shuoshuo .kss-collapse-toggle{display:inline-flex;align-items:center;gap:4px;margin-top:6px;padding:0;font-size:13px;color:#336699;background:none;border:0;cursor:pointer;line-height:1.6;}
        .kratos-shuoshuo .kss-collapse-toggle::after{content:"";display:inline-block;width:0;height:0;margin-left:2px;border:4px solid transparent;border-top-color:#336699;transform:translateY(2px) rotate(180deg);transition:transform .2s ease;}
        .kratos-shuoshuo .kss-collapsible.is-collapsed .kss-collapse-toggle::after{transform:translateY(-1px) rotate(0deg);}
        .kratos-shuoshuo .kss-collapse-toggle:hover{color:#264e75;text-decoration:underline;}
        .kratos-shuoshuo .kss-text p{margin:0 0 6px;}
        .kratos-shuoshuo .kss-text p:last-child{margin-bottom:0;}
        .kratos-shuoshuo .kss-text a{color:#336699;}
        .kratos-shuoshuo .kss-images{display:grid;gap:4px;margin-top:10px;max-width:420px;}
        .kratos-shuoshuo .kss-images.kss-grid-1{grid-template-columns:1fr;max-width:300px;}
        .kratos-shuoshuo .kss-images.kss-grid-1 .kss-img-cell{aspect-ratio:auto;height:auto;}
        .kratos-shuoshuo .kss-images.kss-grid-1 .kss-img-bg{position:static;background-size:contain;background-repeat:no-repeat;background-position:left top;height:300px;display:block;width:100%;}
        .kratos-shuoshuo .kss-images.kss-grid-2{grid-template-columns:repeat(2,1fr);max-width:280px;}
        .kratos-shuoshuo .kss-images.kss-grid-3{grid-template-columns:repeat(3,1fr);}
        .kratos-shuoshuo .kss-images.kss-grid-4{grid-template-columns:repeat(2,1fr);max-width:280px;}
        .kratos-shuoshuo .kss-images.kss-grid-9{grid-template-columns:repeat(3,1fr);}
        .kratos-shuoshuo .kss-img-cell{position:relative;display:block;aspect-ratio:1/1;border-radius:2px;overflow:hidden;background:#f1f1f1;cursor:zoom-in;}
        .kratos-shuoshuo .kss-img-bg{position:absolute;inset:0;background-size:cover;background-position:center;transition:transform .25s ease;}
        .kratos-shuoshuo .kss-img-cell:hover .kss-img-bg{transform:scale(1.04);}
        .kratos-shuoshuo .kss-meta{display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-size:12px;color:#999;}
        .kratos-shuoshuo .kss-time{flex:1;}
        .kratos-shuoshuo .kss-actions{display:inline-flex;gap:14px;}
        .kratos-shuoshuo .kss-action{display:inline-flex;align-items:center;gap:4px;color:#999 !important;text-decoration:none !important;cursor:pointer;}
        .kratos-shuoshuo .kss-action:hover{color:#336699 !important;}
        .kratos-shuoshuo .kss-action em{font-style:normal;font-size:12px;}
        .kratos-shuoshuo .kss-like.done{color:#e8516e !important;}
        .kratos-shuoshuo .kss-like.done svg{fill:#e8516e;stroke:#e8516e;}
        .kratos-shuoshuo .kss-like.kss-just-liked svg{animation:kss-pop .35s ease;}
        @keyframes kss-pop{0%{transform:scale(1);}40%{transform:scale(1.4);}100%{transform:scale(1);}}
        .kratos-shuoshuo .kss-empty{padding:48px 16px;text-align:center;color:#999;font-size:14px;}

        .kratos-shuoshuo .kss-pagination{display:flex;justify-content:center;align-items:center;gap:6px;flex-wrap:wrap;margin-top:24px;}
        .kratos-shuoshuo .kss-page{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 12px;font-size:13px;color:#336699 !important;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:2px;text-decoration:none !important;transition:all .2s ease;}
        .kratos-shuoshuo .kss-page:hover{background:#336699;color:#fff !important;border-color:transparent;}
        .kratos-shuoshuo .kss-current{background:#336699;color:#fff !important;border-color:transparent;cursor:default;font-weight:600;}
        .kratos-shuoshuo .kss-ellipsis{border:none;background:transparent;cursor:default;color:#999;}
        .kratos-shuoshuo .kss-ellipsis:hover{background:transparent;color:#999 !important;}

        /* ---------- 详情页专属：放大版式 + 与评论拼接 ---------- */
        .kratos-shuoshuo-single{padding:0;}
        .kratos-shuoshuo-single .kss-item{padding:28px 32px;border-radius:2px 2px 0 0;border-bottom:none;box-shadow:0 1px 3px rgba(0,0,0,.04);gap:18px;cursor:default;}
        .kratos-shuoshuo-single .kss-item:hover{transform:none;box-shadow:0 1px 3px rgba(0,0,0,.04);}
        .kratos-shuoshuo-single .kss-avatar img,.kratos-shuoshuo-single .kss-avatar-img{width:56px !important;height:56px !important;border-radius:2px !important;}
        .kratos-shuoshuo-single .kss-author{font-size:16px;padding-bottom:12px;margin-bottom:14px;}
        .kratos-shuoshuo-single .kss-author::before{height:16px;}
        .kratos-shuoshuo-single .kss-text{font-size:16px;line-height:1.85;}
        .kratos-shuoshuo-single .kss-images{margin-top:14px;max-width:520px;}
        .kratos-shuoshuo-single .kss-images.kss-grid-1{max-width:420px;}
        .kratos-shuoshuo-single .kss-images.kss-grid-1 .kss-img-bg{height:380px;}
        .kratos-shuoshuo-single .kss-meta{margin-top:16px;padding-top:14px;border-top:1px dashed rgba(0,0,0,.06);font-size:13px;}
        .kratos-shuoshuo-single + .comments,
        .kratos-shuoshuo-single ~ .comments{margin-top:0 !important;border-radius:0 0 2px 2px;border-top:1px solid rgba(0,0,0,.04);}

        .kratos-shuoshuo-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:12px;padding:6px 14px;font-size:13px;color:#666 !important;background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:2px;text-decoration:none !important;transition:all .2s ease;}
        .kratos-shuoshuo-back:hover{color:#336699 !important;border-color:rgba(51,102,153,.3);}

        /* ---------- 列表页页头（标题 + 副标题） ---------- */
        .kratos-shuoshuo-header{margin:0 0 18px;padding:28px 32px;background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:2px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
        .kratos-shuoshuo-header .kss-h-title{margin:0;font-size:22px;font-weight:600;color:#222;line-height:1.3;display:flex;align-items:center;gap:10px;}
        .kratos-shuoshuo-header .kss-h-title::before{content:"";display:inline-block;width:4px;height:20px;background:#336699;border-radius:2px;}
        .kratos-shuoshuo-header .kss-h-subtitle{margin:8px 0 0;padding-left:14px;font-size:14px;color:#888;line-height:1.6;}

        @media (max-width:576px){
            .kratos-shuoshuo .kss-item{padding:14px;gap:10px;}
            .kratos-shuoshuo .kss-avatar img,.kratos-shuoshuo .kss-avatar-img{width:40px !important;height:40px !important;}
            .kratos-shuoshuo .kss-images{max-width:100%;}
            .kratos-shuoshuo .kss-images.kss-grid-1 .kss-img-bg{height:240px;}
            .kratos-shuoshuo-single .kss-item{padding:18px;gap:12px;}
            .kratos-shuoshuo-single .kss-avatar img,.kratos-shuoshuo-single .kss-avatar-img{width:46px !important;height:46px !important;}
            .kratos-shuoshuo-single .kss-text{font-size:15px;}
            .kratos-shuoshuo-single .kss-images.kss-grid-1 .kss-img-bg{height:280px;}
            .kratos-shuoshuo-header{padding:20px 22px;}
            .kratos-shuoshuo-header .kss-h-title{font-size:19px;}
            .kratos-shuoshuo-header .kss-h-subtitle{font-size:13px;}
        }

        /* ---------- 暗色模式 ---------- */
        html[data-theme="dark"] .kratos-shuoshuo .kss-item,body.dark .kratos-shuoshuo .kss-item{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.08);box-shadow:0 2px 6px rgba(0,0,0,.3);}
        html[data-theme="dark"] .kratos-shuoshuo .kss-author,body.dark .kratos-shuoshuo .kss-author{color:#e8e4ec;border-bottom-color:rgba(255,255,255,.08);}
        html[data-theme="dark"] .kratos-shuoshuo .kss-author::before,body.dark .kratos-shuoshuo .kss-author::before{background:#7e9bce;}
        html[data-theme="dark"] .kratos-shuoshuo .kss-collapse-toggle,body.dark .kratos-shuoshuo .kss-collapse-toggle{color:#7e9bce;}
        html[data-theme="dark"] .kratos-shuoshuo .kss-collapse-toggle::after,body.dark .kratos-shuoshuo .kss-collapse-toggle::after{border-top-color:#7e9bce;}
        html[data-theme="dark"] .kratos-shuoshuo .kss-text,body.dark .kratos-shuoshuo .kss-text{color:#d8d8de;}
        html[data-theme="dark"] .kratos-shuoshuo .kss-action,body.dark .kratos-shuoshuo .kss-action{color:#888;}
        html[data-theme="dark"] .kratos-shuoshuo .kss-action:hover,body.dark .kratos-shuoshuo .kss-action:hover{color:#7e9bce !important;}
        html[data-theme="dark"] .kratos-shuoshuo .kss-img-cell,body.dark .kratos-shuoshuo .kss-img-cell{background:#2a2a2a;}
        html[data-theme="dark"] .kratos-shuoshuo .kss-page,body.dark .kratos-shuoshuo .kss-page{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.08);color:#7e9bce !important;}
        html[data-theme="dark"] .kratos-shuoshuo-single .kss-meta,body.dark .kratos-shuoshuo-single .kss-meta{border-top-color:rgba(255,255,255,.08);}
        html[data-theme="dark"] .kratos-shuoshuo-back,body.dark .kratos-shuoshuo-back{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.08);color:#aaa !important;}
        html[data-theme="dark"] .kratos-shuoshuo-back:hover,body.dark .kratos-shuoshuo-back:hover{color:#7e9bce !important;border-color:rgba(126,155,206,.3);}
        html[data-theme="dark"] .kratos-shuoshuo-header,body.dark .kratos-shuoshuo-header{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.08);box-shadow:0 2px 6px rgba(0,0,0,.3);}
        html[data-theme="dark"] .kratos-shuoshuo-header .kss-h-title,body.dark .kratos-shuoshuo-header .kss-h-title{color:#e8e4ec;}
        html[data-theme="dark"] .kratos-shuoshuo-header .kss-h-title::before,body.dark .kratos-shuoshuo-header .kss-h-title::before{background:#7e9bce;}
        html[data-theme="dark"] .kratos-shuoshuo-header .kss-h-subtitle,body.dark .kratos-shuoshuo-header .kss-h-subtitle{color:#999;}
    </style>

    <script>
        (function ($) {
            if (window.kratosShuoshuoLikeBound) return;
            window.kratosShuoshuoLikeBound = true;
            $(function () {
                $(document).on('click', '.kratos-shuoshuo .kss-collapse-toggle', function (e) {
                    e.preventDefault();
                    var $box = $(this).closest('.kss-collapsible');
                    var collapsed = $box.toggleClass('is-collapsed').hasClass('is-collapsed');
                    $(this).text(collapsed ? ($box.data('expand') || '展开') : ($box.data('collapse') || '收起'));
                });

                if (typeof kratos === 'undefined' || !kratos.site) return;
                $(document).on('click', '.kratos-shuoshuo .kss-like', function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    if ($btn.hasClass('done')) {
                        if (typeof layer !== 'undefined' && kratos.repeat) {
                            layer.msg(kratos.repeat);
                        }
                        return false;
                    }
                    var id = $btn.data('id');
                    if (!id) return false;
                    $btn.addClass('done kss-just-liked');
                    setTimeout(function () { $btn.removeClass('kss-just-liked'); }, 400);
                    if (typeof layer !== 'undefined' && kratos.thanks) {
                        layer.msg(kratos.thanks);
                    }
                    $.post(kratos.site + '/wp-admin/admin-ajax.php', {
                        action: 'love',
                        um_id: id,
                        um_action: 'love'
                    }, function (data) {
                        var n = parseInt(data, 10);
                        if (!isNaN(n)) {
                            $btn.find('em').text(n);
                        }
                    });
                    return false;
                });
            });
        })(jQuery);
    </script>
    <?php
    return ob_get_clean();
}

/*
 * 注：说说页面的图片灯箱、点赞 AJAX 都直接复用主题原生能力——
 *   - lightGallery：主题在 is_page() / is_single() 自动入队 + 初始化（theme-core.php、kratos.js），
 *     只要把容器套在 id="lightgallery" 内、链接是图片后缀即可。
 *   - 点赞：套用 .btn-thumbs / data-id / data-action="love"，由 kratos.js 的 postLikeConfig() 接管。
 */
