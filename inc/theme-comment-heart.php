<?php
/**
 * 走心评论
 *
 * 功能：
 *   - 在 WP 后台「评论」列表里通过 row action / bulk action 标记或取消"走心评论"
 *   - 被标记的评论：在评论作者名后面追加「走心」徽章（前后台均生效）
 *   - 提供 [heart_comments] 短码，在页面展示走心评论：
 *     · 走心评论数量、来自文章数量、参与用户数量
 *     · 评论卡片：用户头像 / 用户名 / 评论时间 / 来自文章（点击跳转到该评论锚点）
 *     · 支持 title / subtitle 自定义标题和副标题
 *
 * 存储：comment meta `kratos_heart` = '1' 表示该评论被标记为走心评论。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

const KRATOS_HEART_META_KEY = 'kratos_heart';

/* ============================================================
 *  基础工具
 * ============================================================ */

function kratos_heart_is_marked($comment_id)
{
    return (string) get_comment_meta((int) $comment_id, KRATOS_HEART_META_KEY, true) === '1';
}

function kratos_heart_set($comment_id, $marked)
{
    $comment_id = (int) $comment_id;
    if (!$comment_id) return;
    if ($marked) {
        update_comment_meta($comment_id, KRATOS_HEART_META_KEY, '1');
    } else {
        delete_comment_meta($comment_id, KRATOS_HEART_META_KEY);
    }
}

/* ============================================================
 *  作者名后追加「走心」徽章
 * ============================================================ */

function kratos_heart_badge_html()
{
    $text     = (string) kratos_option('g_comment_heart_badge_text', __('走心', 'kratos'));
    if (trim($text) === '') {
        $text = __('走心', 'kratos');
    }
    $color    = sanitize_hex_color((string) kratos_option('g_comment_heart_badge_color', '#ffffff')) ?: '#ffffff';
    $bg_start = sanitize_hex_color((string) kratos_option('g_comment_heart_badge_bg_start', '#ff6b8b')) ?: '#ff6b8b';
    $bg_end   = sanitize_hex_color((string) kratos_option('g_comment_heart_badge_bg_end', '#ff8e53')) ?: '#ff8e53';

    $style = sprintf(
        'display:inline-flex;align-items:center;gap:3px;margin-left:6px;padding:1px 8px;font-size:11px;line-height:1.5;border-radius:10px;color:%s;background:linear-gradient(135deg,%s 0%%,%s 100%%);vertical-align:middle;font-weight:500;box-shadow:0 1px 3px rgba(0,0,0,0.18);',
        esc_attr($color),
        esc_attr($bg_start),
        esc_attr($bg_end)
    );
    $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="' . esc_attr($color) . '" style="vertical-align:middle;"><path d="M12 21s-7.5-4.6-9.5-9.1C1.1 8.6 3 5 6.3 5c1.9 0 3.6 1.1 4.4 2.7C11.6 6.1 13.3 5 15.2 5c3.3 0 5.2 3.6 3.8 6.9C19.5 16.4 12 21 12 21z"/></svg>';
    return '<span class="kratos-heart-badge" title="' . esc_attr__('走心评论', 'kratos') . '" style="' . $style . '">' . $icon . esc_html($text) . '</span>';
}

function kratos_heart_append_badge($author_link, $author = '', $comment_id = 0)
{
    if (!$comment_id) {
        if (!empty($GLOBALS['comment']) && $GLOBALS['comment'] instanceof WP_Comment) {
            $comment_id = (int) $GLOBALS['comment']->comment_ID;
        }
    }
    if (!$comment_id) return $author_link;
    if (!kratos_heart_is_marked($comment_id)) return $author_link;
    return $author_link . kratos_heart_badge_html();
}
// 优先级 11，跑在 kratos_rank_append_badge 后面，保证「走心」徽章在「等级」徽章之后
add_filter('get_comment_author_link', 'kratos_heart_append_badge', 11, 3);

/* ============================================================
 *  WP 后台：评论列表 row action / bulk action / 状态列
 * ============================================================ */

/**
 * 评论列表「快速操作」加上 标记/取消走心
 */
function kratos_heart_row_actions($actions, $comment)
{
    $comment_id = (int) $comment->comment_ID;
    $is_marked = kratos_heart_is_marked($comment_id);

    $url = wp_nonce_url(
        add_query_arg(
            array(
                'action' => $is_marked ? 'kratos_heart_unmark' : 'kratos_heart_mark',
                'c'      => $comment_id,
            ),
            admin_url('admin-post.php')
        ),
        'kratos_heart_toggle_' . $comment_id
    );

    $label = $is_marked ? __('取消走心', 'kratos') : __('标记走心', 'kratos');
    $color = $is_marked ? '#999' : '#ff6b8b';

    $actions['kratos_heart'] = '<a href="' . esc_url($url) . '" style="color:' . $color . ';">' . esc_html($label) . '</a>';
    return $actions;
}
add_filter('comment_row_actions', 'kratos_heart_row_actions', 10, 2);

/**
 * 单个标记 / 取消（admin-post 入口）
 */
function kratos_heart_handle_toggle()
{
    $comment_id = isset($_GET['c']) ? (int) $_GET['c'] : 0;
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    if (!$comment_id || !current_user_can('moderate_comments')) {
        wp_die(__('权限不足', 'kratos'));
    }
    check_admin_referer('kratos_heart_toggle_' . $comment_id);

    if ($action === 'kratos_heart_mark') {
        kratos_heart_set($comment_id, true);
    } elseif ($action === 'kratos_heart_unmark') {
        kratos_heart_set($comment_id, false);
    }

    wp_safe_redirect(wp_get_referer() ?: admin_url('edit-comments.php'));
    exit;
}
add_action('admin_post_kratos_heart_mark', 'kratos_heart_handle_toggle');
add_action('admin_post_kratos_heart_unmark', 'kratos_heart_handle_toggle');

/**
 * 批量操作
 */
function kratos_heart_bulk_actions($actions)
{
    $actions['kratos_heart_mark']   = __('标记为走心评论', 'kratos');
    $actions['kratos_heart_unmark'] = __('取消走心评论', 'kratos');
    return $actions;
}
add_filter('bulk_actions-edit-comments', 'kratos_heart_bulk_actions');

function kratos_heart_handle_bulk($redirect_to, $action, $comment_ids)
{
    if ($action !== 'kratos_heart_mark' && $action !== 'kratos_heart_unmark') {
        return $redirect_to;
    }
    if (!current_user_can('moderate_comments')) {
        return $redirect_to;
    }
    $count = 0;
    foreach ((array) $comment_ids as $cid) {
        kratos_heart_set($cid, $action === 'kratos_heart_mark');
        $count++;
    }
    return add_query_arg('kratos_heart_done', $count, $redirect_to);
}
add_filter('handle_bulk_actions-edit-comments', 'kratos_heart_handle_bulk', 10, 3);

function kratos_heart_bulk_notice()
{
    if (!empty($_GET['kratos_heart_done'])) {
        $n = (int) $_GET['kratos_heart_done'];
        echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
            esc_html__('已处理 %d 条评论的走心标记。', 'kratos'),
            $n
        ) . '</p></div>';
    }
}
add_action('admin_notices', 'kratos_heart_bulk_notice');

/**
 * 评论列表新增「走心」列，方便查看与筛选
 */
function kratos_heart_add_column($columns)
{
    $new = array();
    foreach ($columns as $k => $v) {
        $new[$k] = $v;
        if ($k === 'response') {
            $new['kratos_heart'] = __('走心', 'kratos');
        }
    }
    if (!isset($new['kratos_heart'])) {
        $new['kratos_heart'] = __('走心', 'kratos');
    }
    return $new;
}
add_filter('manage_edit-comments_columns', 'kratos_heart_add_column');

function kratos_heart_render_column($column, $comment_id)
{
    if ($column !== 'kratos_heart') return;
    if (kratos_heart_is_marked($comment_id)) {
        echo '<span title="' . esc_attr__('已标记为走心评论', 'kratos') . '" style="color:#ff6b8b;font-size:16px;">&#10084;</span>';
    } else {
        echo '<span style="color:#ddd;font-size:16px;">&#9825;</span>';
    }
}
add_action('manage_comments_custom_column', 'kratos_heart_render_column', 10, 2);

/**
 * 评论列表筛选下拉：仅显示走心评论
 */
function kratos_heart_admin_filter_select()
{
    if (!function_exists('get_current_screen')) return;
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-comments') return;
    $current = isset($_GET['kratos_heart_filter']) ? $_GET['kratos_heart_filter'] : '';
    echo '<select name="kratos_heart_filter" style="margin-left:6px;">';
    echo '<option value="">' . esc_html__('所有评论', 'kratos') . '</option>';
    echo '<option value="1"' . selected($current, '1', false) . '>' . esc_html__('仅走心评论', 'kratos') . '</option>';
    echo '</select>';
}
add_action('restrict_manage_comments', 'kratos_heart_admin_filter_select');

function kratos_heart_admin_filter_query($query)
{
    if (!is_admin()) return;
    if (empty($_GET['kratos_heart_filter']) || $_GET['kratos_heart_filter'] !== '1') return;
    $meta_query = (array) $query->query_vars['meta_query'];
    $meta_query[] = array(
        'key'   => KRATOS_HEART_META_KEY,
        'value' => '1',
    );
    $query->query_vars['meta_query'] = $meta_query;
}
add_action('pre_get_comments', 'kratos_heart_admin_filter_query');

/* ============================================================
 *  数据查询
 * ============================================================ */

/**
 * 拉取走心评论列表（已审核）
 *
 * @param int $number 0 表示不限
 * @param int $offset 偏移量
 * @return WP_Comment[]
 */
function kratos_heart_get_comments($number = 0, $offset = 0)
{
    $args = array(
        'status'     => 'approve',
        'meta_key'   => KRATOS_HEART_META_KEY,
        'meta_value' => '1',
        'orderby'    => 'comment_date_gmt',
        'order'      => 'DESC',
    );
    if ($number > 0) {
        $args['number'] = $number;
    }
    if ($offset > 0) {
        $args['offset'] = $offset;
    }
    return get_comments($args);
}

/**
 * 走心评论总数（用于分页）
 */
function kratos_heart_get_total()
{
    $args = array(
        'status'     => 'approve',
        'meta_key'   => KRATOS_HEART_META_KEY,
        'meta_value' => '1',
        'count'      => true,
    );
    return (int) get_comments($args);
}

/**
 * 走心评论统计：评论数 / 文章数 / 用户数
 *
 * 用一次全量查询统计，命中后写 transient 5 分钟，标记/取消时清除。
 */
function kratos_heart_get_stats()
{
    $cache_key = 'kratos_heart_stats';
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $comments = kratos_heart_get_comments(0);

    $post_ids = array();
    $user_keys = array();
    foreach ($comments as $c) {
        $post_ids[(int) $c->comment_post_ID] = true;
        if ((int) $c->user_id > 0) {
            $user_keys['u_' . (int) $c->user_id] = true;
        } elseif ($c->comment_author_email) {
            $user_keys['e_' . md5(strtolower($c->comment_author_email))] = true;
        } else {
            $user_keys['n_' . md5((string) $c->comment_author)] = true;
        }
    }

    $stats = array(
        'comments' => count($comments),
        'posts'    => count($post_ids),
        'users'    => count($user_keys),
    );

    set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);
    return $stats;
}

/**
 * 在评论标记 / 状态变化时清掉统计缓存
 */
function kratos_heart_flush_stats_cache()
{
    delete_transient('kratos_heart_stats');
}
add_action('added_comment_meta',   'kratos_heart_flush_stats_cache');
add_action('updated_comment_meta', 'kratos_heart_flush_stats_cache');
add_action('deleted_comment_meta', 'kratos_heart_flush_stats_cache');
add_action('wp_set_comment_status', 'kratos_heart_flush_stats_cache');
add_action('deleted_comment',       'kratos_heart_flush_stats_cache');
add_action('trashed_comment',       'kratos_heart_flush_stats_cache');
add_action('untrashed_comment',     'kratos_heart_flush_stats_cache');

/* ============================================================
 *  短码 [heart_comments]
 * ============================================================ */

function kratos_heart_shortcode($atts)
{
    // 从后台读默认值
    $default_title    = (string) kratos_option('g_comment_heart_sc_title', __('走心评论', 'kratos'));
    $default_subtitle = (string) kratos_option('g_comment_heart_sc_subtitle', __('那些温暖过我的留言，每一条都值得被看见 ❤', 'kratos'));
    $default_per_page = (int) kratos_option('g_comment_heart_sc_per_page', 100);
    if ($default_per_page < 0) $default_per_page = 100;

    $atts = shortcode_atts(array(
        'title'    => $default_title,
        'subtitle' => $default_subtitle,
        'per_page' => $default_per_page,
    ), $atts, 'heart_comments');

    $per_page = max(0, (int) $atts['per_page']);
    $stats    = kratos_heart_get_stats();
    $total    = (int) $stats['comments'];

    // 分页
    $total_pages = ($per_page > 0 && $total > 0) ? (int) ceil($total / $per_page) : 1;
    $current_page = isset($_GET['khc_page']) ? max(1, (int) $_GET['khc_page']) : 1;
    if ($current_page > $total_pages) $current_page = $total_pages;

    $offset = $per_page > 0 ? ($current_page - 1) * $per_page : 0;
    $comments = kratos_heart_get_comments($per_page, $offset);

    $title    = (string) $atts['title'];
    $subtitle = (string) $atts['subtitle'];

    // 配色方案（后台可选择）
    $valid_schemes = array('parchment', 'sakura', 'mint', 'sky', 'lavender', 'sunset');
    $scheme = (string) kratos_option('g_comment_heart_scheme', 'parchment');
    if (!in_array($scheme, $valid_schemes, true)) {
        $scheme = 'parchment';
    }

    ob_start();
    ?>
    <div class="kratos-heart-shortcode khs-scheme-<?php echo esc_attr($scheme); ?>" id="kratos-heart-list">
        <?php if ($title !== '' || $subtitle !== '') { ?>
            <div class="khs-header">
                <?php if ($title !== '') { ?>
                    <div class="khs-title-row">
                        <span class="khs-title-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="#f59e0b"><path d="M12 21s-7.5-4.6-9.5-9.1C1.1 8.6 3 5 6.3 5c1.9 0 3.6 1.1 4.4 2.7C11.6 6.1 13.3 5 15.2 5c3.3 0 5.2 3.6 3.8 6.9C19.5 16.4 12 21 12 21z"/></svg>
                        </span>
                        <h3 class="khs-title"><?php echo esc_html($title); ?></h3>
                    </div>
                <?php } ?>
                <?php if ($subtitle !== '') { ?>
                    <p class="khs-subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php } ?>
            </div>
        <?php } ?>

        <div class="khs-stats">
            <div class="khs-stat khs-stat-comment">
                <span class="khs-stat-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-9.5-9.1C1.1 8.6 3 5 6.3 5c1.9 0 3.6 1.1 4.4 2.7C11.6 6.1 13.3 5 15.2 5c3.3 0 5.2 3.6 3.8 6.9C19.5 16.4 12 21 12 21z" fill="currentColor"/></svg>
                </span>
                <div class="khs-stat-body">
                    <div class="khs-stat-label"><?php esc_html_e('走心评论', 'kratos'); ?></div>
                    <div class="khs-stat-num"><?php echo (int) $stats['comments']; ?></div>
                </div>
            </div>
            <div class="khs-stat khs-stat-post">
                <span class="khs-stat-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="14" y2="17"/></svg>
                </span>
                <div class="khs-stat-body">
                    <div class="khs-stat-label"><?php esc_html_e('来自文章', 'kratos'); ?></div>
                    <div class="khs-stat-num"><?php echo (int) $stats['posts']; ?></div>
                </div>
            </div>
            <div class="khs-stat khs-stat-user">
                <span class="khs-stat-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <div class="khs-stat-body">
                    <div class="khs-stat-label"><?php esc_html_e('参与用户', 'kratos'); ?></div>
                    <div class="khs-stat-num"><?php echo (int) $stats['users']; ?></div>
                </div>
            </div>
        </div>

        <?php if (empty($comments)) { ?>
            <div class="khs-empty">
                <?php esc_html_e('暂时还没有走心评论，期待你的留言 ✨', 'kratos'); ?>
            </div>
        <?php } else { ?>
            <div class="khs-list">
                <?php foreach ($comments as $c) {
                    $post_id    = (int) $c->comment_post_ID;
                    $post_title = get_the_title($post_id);
                    if ($post_title === '') {
                        $post_title = __('（无标题）', 'kratos');
                    }
                    $comment_link = get_comment_link($c);
                    $author       = $c->comment_author ? $c->comment_author : __('匿名', 'kratos');
                    $avatar       = get_avatar($c, 56, '', $author, array('class' => 'khs-avatar-img'));
                    $time_human   = human_time_diff(get_comment_date('U', $c->comment_ID), current_time('timestamp')) . __('前', 'kratos');
                    $time_full    = get_comment_date(get_option('date_format') . ' ' . get_option('time_format'), $c->comment_ID);
                    $excerpt      = wp_strip_all_tags(get_comment_text($c->comment_ID));
                    $excerpt      = function_exists('mb_strimwidth') ? mb_strimwidth($excerpt, 0, 120, '…', 'UTF-8') : (mb_strlen($excerpt) > 60 ? mb_substr($excerpt, 0, 60) . '…' : $excerpt);
                ?>
                    <a class="khs-card" href="<?php echo esc_url($comment_link); ?>" title="<?php echo esc_attr($post_title); ?>">
                        <div class="khs-card-head">
                            <span class="khs-avatar"><?php echo $avatar; ?></span>
                            <div class="khs-meta">
                                <span class="khs-author"><?php echo esc_html($author); ?></span>
                                <span class="khs-time" title="<?php echo esc_attr($time_full); ?>"><?php echo esc_html($time_human); ?></span>
                            </div>
                            <span class="khs-heart-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#f59e0b"><path d="M12 21s-7.5-4.6-9.5-9.1C1.1 8.6 3 5 6.3 5c1.9 0 3.6 1.1 4.4 2.7C11.6 6.1 13.3 5 15.2 5c3.3 0 5.2 3.6 3.8 6.9C19.5 16.4 12 21 12 21z"/></svg>
                            </span>
                        </div>
                        <div class="khs-text"><?php echo esc_html($excerpt); ?></div>
                        <div class="khs-from">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            <span><?php echo esc_html($post_title); ?></span>
                        </div>
                    </a>
                <?php } ?>
            </div>

            <?php if ($total_pages > 1) {
                $base_url = remove_query_arg('khc_page');
                $build = function ($page) use ($base_url) {
                    return esc_url(add_query_arg('khc_page', $page, $base_url) . '#kratos-heart-list');
                };
                // 滑动窗口最多 5 个页码
                $window = 2;
                $start  = max(1, $current_page - $window);
                $end    = min($total_pages, $current_page + $window);
                if ($end - $start < $window * 2) {
                    if ($start === 1) $end = min($total_pages, $start + $window * 2);
                    if ($end === $total_pages) $start = max(1, $end - $window * 2);
                }
            ?>
                <nav class="khs-pagination" aria-label="<?php esc_attr_e('走心评论分页', 'kratos'); ?>">
                    <?php if ($current_page > 1) { ?>
                        <a class="khs-page khs-page-nav" href="<?php echo $build($current_page - 1); ?>" rel="prev">
                            &laquo; <?php esc_html_e('上一页', 'kratos'); ?>
                        </a>
                    <?php } else { ?>
                        <span class="khs-page khs-page-nav khs-disabled">&laquo; <?php esc_html_e('上一页', 'kratos'); ?></span>
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
                    <?php } else { ?>
                        <span class="khs-page khs-page-nav khs-disabled"><?php esc_html_e('下一页', 'kratos'); ?> &raquo;</span>
                    <?php } ?>
                </nav>
                <div class="khs-page-info">
                    <?php
                    printf(
                        esc_html__('第 %1$s / %2$s 页 · 共 %3$s 条', 'kratos'),
                        '<strong>' . (int) $current_page . '</strong>',
                        '<strong>' . (int) $total_pages . '</strong>',
                        '<strong>' . (int) $total . '</strong>'
                    );
                    ?>
                </div>
            <?php } ?>
        <?php } ?>
    </div>

    <style>
        /* === 走心评论短码：通用骨架（用 CSS 变量驱动各方案配色） === */
        .kratos-heart-shortcode{
            --khs-bg-1:#f5e7c4;--khs-bg-2:#efd9a9;--khs-bg-3:#e9cc91;
            --khs-fg:#3a2a10;--khs-fg-soft:#5a3a14;--khs-fg-dim:#7a5a26;--khs-fg-mute:#a08658;
            --khs-accent:#7a3f12;--khs-accent-2:#a86028;
            --khs-line:rgba(120,80,30,.22);--khs-line-strong:rgba(120,80,30,.40);
            --khs-card-bg:rgba(255,250,232,.78);
            --khs-card-shadow:0 1px 3px rgba(120,80,30,.10);
            --khs-card-shadow-hv:0 8px 18px rgba(120,80,30,.22);
            --khs-page-on:#fdf3d9;
            --khs-stat-comment-1:#b8501c;--khs-stat-comment-2:#7a2912;
            --khs-stat-post-1:#c08038;--khs-stat-post-2:#7a4a18;
            --khs-stat-user-1:#a87838;--khs-stat-user-2:#6a4818;
            --khs-heart-fill:#a04018;
            padding:32px 28px;border-radius:14px;position:relative;overflow:hidden;
            background:linear-gradient(135deg,var(--khs-bg-1) 0%,var(--khs-bg-2) 50%,var(--khs-bg-3) 100%);
            box-shadow:0 6px 24px rgba(0,0,0,.08);
        }
        .kratos-heart-shortcode > *{position:relative;z-index:1;}

        .kratos-heart-shortcode .khs-header{text-align:center;margin-bottom:22px;}
        .kratos-heart-shortcode .khs-title-row{display:flex;align-items:center;justify-content:center;gap:8px;}
        .kratos-heart-shortcode .khs-title{margin:0;font-size:24px;font-weight:700;color:var(--khs-fg-soft);letter-spacing:1px;}
        .kratos-heart-shortcode .khs-title-icon{display:inline-flex;animation:khs-beat 1.6s infinite;}
        .kratos-heart-shortcode .khs-subtitle{margin:8px 0 0;font-size:13px;color:var(--khs-fg-dim);line-height:1.6;}

        .kratos-heart-shortcode .khs-stats{display:flex;justify-content:center;gap:16px;flex-wrap:wrap;margin:0 auto 24px;max-width:760px;}
        .kratos-heart-shortcode .khs-stat{flex:1 1 200px;min-width:180px;display:flex;align-items:center;gap:14px;padding:18px 22px;background:var(--khs-card-bg);border-radius:14px;border:1px solid var(--khs-line);box-shadow:var(--khs-card-shadow);transition:all .25s ease;}
        .kratos-heart-shortcode .khs-stat:hover{transform:translateY(-2px);box-shadow:var(--khs-card-shadow-hv);border-color:var(--khs-line-strong);}
        .kratos-heart-shortcode .khs-stat-icon{flex-shrink:0;width:46px;height:46px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;color:#fff;background:linear-gradient(135deg,var(--khs-accent-2) 0%,var(--khs-accent) 100%);box-shadow:0 4px 10px rgba(0,0,0,.18);}
        .kratos-heart-shortcode .khs-stat-comment .khs-stat-icon{background:linear-gradient(135deg,var(--khs-stat-comment-1) 0%,var(--khs-stat-comment-2) 100%);}
        .kratos-heart-shortcode .khs-stat-post .khs-stat-icon{background:linear-gradient(135deg,var(--khs-stat-post-1) 0%,var(--khs-stat-post-2) 100%);}
        .kratos-heart-shortcode .khs-stat-user .khs-stat-icon{background:linear-gradient(135deg,var(--khs-stat-user-1) 0%,var(--khs-stat-user-2) 100%);}
        .kratos-heart-shortcode .khs-stat-body{flex:1;min-width:0;text-align:left;}
        .kratos-heart-shortcode .khs-stat-label{font-size:13px;color:var(--khs-fg-dim);letter-spacing:1px;line-height:1.4;}
        .kratos-heart-shortcode .khs-stat-num{margin-top:2px;font-size:28px;font-weight:700;color:var(--khs-accent);line-height:1.15;}

        .kratos-heart-shortcode .khs-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;}
        .kratos-heart-shortcode .khs-card{display:block;padding:14px 16px;background:var(--khs-card-bg);border-radius:12px;border:1px solid var(--khs-line);text-decoration:none !important;color:inherit !important;box-shadow:var(--khs-card-shadow);transition:all .25s ease;position:relative;}
        .kratos-heart-shortcode .khs-card:hover{transform:translateY(-3px);box-shadow:var(--khs-card-shadow-hv);border-color:var(--khs-line-strong);}
        .kratos-heart-shortcode .khs-card-head{display:flex;align-items:center;gap:10px;}
        .kratos-heart-shortcode .khs-avatar-img,.kratos-heart-shortcode .khs-avatar img{width:40px !important;height:40px !important;border-radius:50% !important;flex-shrink:0;border:2px solid rgba(255,255,255,.85);box-shadow:0 2px 6px rgba(0,0,0,.10);}
        .kratos-heart-shortcode .khs-meta{flex:1;min-width:0;display:flex;flex-direction:column;}
        .kratos-heart-shortcode .khs-author{font-size:13px;font-weight:600;color:var(--khs-fg-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .kratos-heart-shortcode .khs-time{font-size:11px;color:var(--khs-fg-mute);margin-top:2px;}
        .kratos-heart-shortcode .khs-heart-icon{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:rgba(0,0,0,.06);}
        .kratos-heart-shortcode .khs-heart-icon svg{fill:var(--khs-heart-fill);}
        .kratos-heart-shortcode .khs-text{margin:10px 0 8px;font-size:13px;line-height:1.7;color:var(--khs-fg);display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;}
        .kratos-heart-shortcode .khs-from{display:flex;align-items:center;gap:4px;font-size:11px;color:var(--khs-fg-mute);border-top:1px dashed var(--khs-line-strong);padding-top:8px;}
        .kratos-heart-shortcode .khs-from span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

        .kratos-heart-shortcode .khs-empty{padding:36px 16px;text-align:center;color:var(--khs-fg-dim);font-size:14px;background:var(--khs-card-bg);border-radius:12px;border:1px dashed var(--khs-line-strong);}

        .kratos-heart-shortcode .khs-pagination{display:flex;justify-content:center;align-items:center;gap:6px;flex-wrap:wrap;margin-top:22px;}
        .kratos-heart-shortcode .khs-page{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 12px;font-size:13px;color:var(--khs-fg-soft) !important;background:var(--khs-card-bg);border:1px solid var(--khs-line);border-radius:10px;text-decoration:none !important;transition:all .2s ease;}
        .kratos-heart-shortcode .khs-page:hover{background:linear-gradient(135deg,var(--khs-accent-2) 0%,var(--khs-accent) 100%);color:var(--khs-page-on) !important;border-color:transparent;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.18);}
        .kratos-heart-shortcode .khs-current{background:linear-gradient(135deg,var(--khs-accent-2) 0%,var(--khs-accent) 100%);color:var(--khs-page-on) !important;border-color:transparent;cursor:default;font-weight:600;}
        .kratos-heart-shortcode .khs-current:hover{transform:none;}
        .kratos-heart-shortcode .khs-disabled{opacity:.40;cursor:not-allowed;}
        .kratos-heart-shortcode .khs-disabled:hover{background:var(--khs-card-bg);color:var(--khs-fg-soft) !important;border-color:var(--khs-line);transform:none;box-shadow:none;}
        .kratos-heart-shortcode .khs-ellipsis{border:none;background:transparent;cursor:default;color:var(--khs-fg-mute);}
        .kratos-heart-shortcode .khs-ellipsis:hover{background:transparent;color:var(--khs-fg-mute) !important;transform:none;box-shadow:none;}
        .kratos-heart-shortcode .khs-page-info{margin-top:10px;text-align:center;font-size:12px;color:var(--khs-fg-dim);}
        .kratos-heart-shortcode .khs-page-info strong{color:var(--khs-accent);font-weight:700;margin:0 2px;}

        @keyframes khs-beat{0%,100%{transform:scale(1);}30%{transform:scale(1.18);}60%{transform:scale(0.95);}}

        /* === 方案：羊皮纸（默认，含做旧 + 噪点 + 焦痕 + 锯齿纸边） === */
        .kratos-heart-shortcode.khs-scheme-parchment{
            border-radius:8px;
            background:
                radial-gradient(ellipse at top left, rgba(120,80,30,.18), transparent 55%),
                radial-gradient(ellipse at bottom right, rgba(120,80,30,.20), transparent 60%),
                radial-gradient(ellipse at center, rgba(255,243,206,.45), transparent 70%),
                linear-gradient(135deg,#f5e7c4 0%,#efd9a9 50%,#e9cc91 100%);
            box-shadow:inset 0 0 60px rgba(120,80,30,.28),inset 0 0 120px rgba(120,80,30,.16),0 6px 24px rgba(120,80,30,.18),0 2px 6px rgba(120,80,30,.12);
            clip-path:polygon(0% 6px,6px 3px,12px 0%,92% 4px,96% 0%,100% 8px,99% 50%,100% 92%,96% 100%,8% 99%,4% 100%,0% 94%);
            font-family:Georgia,"PingFang SC","Songti SC","SimSun",serif;
        }
        .kratos-heart-shortcode.khs-scheme-parchment::before{content:"";position:absolute;inset:0;pointer-events:none;opacity:.40;mix-blend-mode:multiply;border-radius:inherit;
            background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 0.55  0 0 0 0 0.40  0 0 0 0 0.20  0 0 0 0.6 0'/></filter><rect width='200' height='200' filter='url(%23n)'/></svg>");
            background-size:240px 240px;}
        .kratos-heart-shortcode.khs-scheme-parchment::after{content:"";position:absolute;inset:0;pointer-events:none;border-radius:inherit;
            background:
                radial-gradient(circle at 0 0, rgba(86,52,18,.32), transparent 90px),
                radial-gradient(circle at 100% 0, rgba(86,52,18,.28), transparent 100px),
                radial-gradient(circle at 0 100%, rgba(86,52,18,.30), transparent 110px),
                radial-gradient(circle at 100% 100%, rgba(86,52,18,.34), transparent 95px);}
        .kratos-heart-shortcode.khs-scheme-parchment .khs-subtitle,
        .kratos-heart-shortcode.khs-scheme-parchment .khs-time,
        .kratos-heart-shortcode.khs-scheme-parchment .khs-from,
        .kratos-heart-shortcode.khs-scheme-parchment .khs-page-info{font-style:italic;}
        .kratos-heart-shortcode.khs-scheme-parchment .khs-page-info strong{font-style:normal;}
        .kratos-heart-shortcode.khs-scheme-parchment .khs-avatar img,
        .kratos-heart-shortcode.khs-scheme-parchment .khs-avatar-img{filter:sepia(.18);}

        /* === 方案：樱花粉 === */
        .kratos-heart-shortcode.khs-scheme-sakura{
            --khs-bg-1:#fff5f7;--khs-bg-2:#ffe0e9;--khs-bg-3:#ffc8d8;
            --khs-fg:#5b1a2c;--khs-fg-soft:#7a2645;--khs-fg-dim:#a14668;--khs-fg-mute:#c87f9a;
            --khs-accent:#d63a6b;--khs-accent-2:#ec6090;
            --khs-line:rgba(214,58,107,.18);--khs-line-strong:rgba(214,58,107,.36);
            --khs-card-bg:rgba(255,255,255,.78);
            --khs-stat-comment-1:#ec6090;--khs-stat-comment-2:#b82656;
            --khs-stat-post-1:#f490b4;--khs-stat-post-2:#c83a78;
            --khs-stat-user-1:#e87aaa;--khs-stat-user-2:#a83668;
            --khs-heart-fill:#d63a6b;
            box-shadow:0 8px 24px rgba(214,58,107,.10);
        }

        /* === 方案：薄荷绿 === */
        .kratos-heart-shortcode.khs-scheme-mint{
            --khs-bg-1:#f0fbf5;--khs-bg-2:#d3f0e0;--khs-bg-3:#b2e3c8;
            --khs-fg:#0f3d28;--khs-fg-soft:#155a3d;--khs-fg-dim:#387a5a;--khs-fg-mute:#7aa890;
            --khs-accent:#1f7a52;--khs-accent-2:#3aa878;
            --khs-line:rgba(31,122,82,.18);--khs-line-strong:rgba(31,122,82,.36);
            --khs-card-bg:rgba(255,255,255,.80);
            --khs-stat-comment-1:#3aa878;--khs-stat-comment-2:#1a6244;
            --khs-stat-post-1:#5cc196;--khs-stat-post-2:#2a8058;
            --khs-stat-user-1:#7ad0a8;--khs-stat-user-2:#3a9070;
            --khs-heart-fill:#1f7a52;
            box-shadow:0 8px 24px rgba(31,122,82,.10);
        }

        /* === 方案：天空蓝 === */
        .kratos-heart-shortcode.khs-scheme-sky{
            --khs-bg-1:#f1f8ff;--khs-bg-2:#d6ebff;--khs-bg-3:#b3d8f5;
            --khs-fg:#0e2c4d;--khs-fg-soft:#13406b;--khs-fg-dim:#3a6892;--khs-fg-mute:#7a9bbf;
            --khs-accent:#1f5fa8;--khs-accent-2:#3a85d6;
            --khs-line:rgba(31,95,168,.18);--khs-line-strong:rgba(31,95,168,.36);
            --khs-card-bg:rgba(255,255,255,.80);
            --khs-stat-comment-1:#3a85d6;--khs-stat-comment-2:#1a4a8a;
            --khs-stat-post-1:#5ca0e8;--khs-stat-post-2:#2a65b0;
            --khs-stat-user-1:#7ab4ee;--khs-stat-user-2:#3a72c0;
            --khs-heart-fill:#1f5fa8;
            box-shadow:0 8px 24px rgba(31,95,168,.10);
        }

        /* === 方案：薰衣草紫 === */
        .kratos-heart-shortcode.khs-scheme-lavender{
            --khs-bg-1:#f7f2ff;--khs-bg-2:#e4d6ff;--khs-bg-3:#cbb3f0;
            --khs-fg:#2c1a5b;--khs-fg-soft:#3d2680;--khs-fg-dim:#5e44a3;--khs-fg-mute:#9a85c9;
            --khs-accent:#6634c2;--khs-accent-2:#8a5cd6;
            --khs-line:rgba(102,52,194,.18);--khs-line-strong:rgba(102,52,194,.36);
            --khs-card-bg:rgba(255,255,255,.80);
            --khs-stat-comment-1:#8a5cd6;--khs-stat-comment-2:#52248e;
            --khs-stat-post-1:#a280e0;--khs-stat-post-2:#6638a8;
            --khs-stat-user-1:#b89ce8;--khs-stat-user-2:#7244b8;
            --khs-heart-fill:#6634c2;
            box-shadow:0 8px 24px rgba(102,52,194,.10);
        }

        /* === 方案：日落橙 === */
        .kratos-heart-shortcode.khs-scheme-sunset{
            --khs-bg-1:#fff4e0;--khs-bg-2:#ffd9b3;--khs-bg-3:#ffb077;
            --khs-fg:#4a2308;--khs-fg-soft:#6a3210;--khs-fg-dim:#92501a;--khs-fg-mute:#bf8a5a;
            --khs-accent:#c25a18;--khs-accent-2:#e88030;
            --khs-line:rgba(194,90,24,.20);--khs-line-strong:rgba(194,90,24,.40);
            --khs-card-bg:rgba(255,255,255,.78);
            --khs-stat-comment-1:#e88030;--khs-stat-comment-2:#a04018;
            --khs-stat-post-1:#f0a05c;--khs-stat-post-2:#c2601c;
            --khs-stat-user-1:#f5b888;--khs-stat-user-2:#a85820;
            --khs-heart-fill:#c25a18;
            box-shadow:0 8px 24px rgba(194,90,24,.12);
        }

        /* 移动端取消羊皮纸锯齿边 */
        @media (max-width:576px){
            .kratos-heart-shortcode{padding:22px 16px;}
            .kratos-heart-shortcode.khs-scheme-parchment{clip-path:none;border-radius:6px;}
        }

        /* === 暗夜模式：所有方案统一深色覆盖 === */
        html[data-theme="dark"] .kratos-heart-shortcode,body.dark .kratos-heart-shortcode{
            --khs-bg-1:#1f1d22;--khs-bg-2:#26242a;--khs-bg-3:#1a1820;
            --khs-fg:#e8e4ec;--khs-fg-soft:#f3eef5;--khs-fg-dim:#b4adb9;--khs-fg-mute:#8a8390;
            --khs-line:rgba(255,255,255,.10);--khs-line-strong:rgba(255,255,255,.22);
            --khs-card-bg:rgba(255,255,255,.06);
            box-shadow:0 8px 24px rgba(0,0,0,.45) !important;
        }
        /* 暗夜模式下羊皮纸保留暖褐 */
        html[data-theme="dark"] .kratos-heart-shortcode.khs-scheme-parchment,body.dark .kratos-heart-shortcode.khs-scheme-parchment{
            --khs-fg:#f3e1bd;--khs-fg-soft:#ffe7b8;--khs-fg-dim:#d8b878;--khs-fg-mute:#a8946a;
            --khs-accent:#c08838;--khs-accent-2:#e0a85c;
            --khs-line:rgba(255,200,120,.22);--khs-line-strong:rgba(255,200,120,.40);
            --khs-card-bg:rgba(255,225,180,.06);
            background:
                radial-gradient(ellipse at top left, rgba(255,200,120,.10), transparent 55%),
                radial-gradient(ellipse at bottom right, rgba(255,180,90,.12), transparent 60%),
                radial-gradient(ellipse at center, rgba(80,55,25,.55), transparent 70%),
                linear-gradient(135deg,#3a2c14 0%,#2e2210 50%,#251a0c 100%) !important;
            box-shadow:inset 0 0 80px rgba(0,0,0,.55),0 6px 24px rgba(0,0,0,.45) !important;
        }
        html[data-theme="dark"] .kratos-heart-shortcode.khs-scheme-parchment::before,body.dark .kratos-heart-shortcode.khs-scheme-parchment::before{opacity:.30;mix-blend-mode:overlay;}
        html[data-theme="dark"] .kratos-heart-shortcode.khs-scheme-parchment::after,body.dark .kratos-heart-shortcode.khs-scheme-parchment::after{
            background:
                radial-gradient(circle at 0 0, rgba(0,0,0,.45), transparent 90px),
                radial-gradient(circle at 100% 0, rgba(0,0,0,.40), transparent 100px),
                radial-gradient(circle at 0 100%, rgba(0,0,0,.45), transparent 110px),
                radial-gradient(circle at 100% 100%, rgba(0,0,0,.50), transparent 95px);
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('heart_comments', 'kratos_heart_shortcode');
