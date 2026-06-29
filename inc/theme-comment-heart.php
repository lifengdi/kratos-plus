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

    ob_start();
    ?>
    <div class="kratos-heart-shortcode khs-scheme-parchment" id="kratos-heart-list">
        <?php if ($title !== '' || $subtitle !== '') { ?>
            <header class="khs-header">
                <?php if ($title !== '') { ?>
                    <span class="khs-title-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-9.5-9.1C1.1 8.6 3 5 6.3 5c1.9 0 3.6 1.1 4.4 2.7C11.6 6.1 13.3 5 15.2 5c3.3 0 5.2 3.6 3.8 6.9C19.5 16.4 12 21 12 21z"/></svg>
                    </span>
                    <span class="khs-title"><?php echo esc_html($title); ?></span>
                <?php } ?>
                <?php if ($subtitle !== '') { ?>
                    <?php if ($title !== '') { ?><span class="khs-header-divider" aria-hidden="true"></span><?php } ?>
                    <p class="khs-subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php } ?>
            </header>
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
            /* 默认配色 = 主题 style.css 同源：白卡 + 浅灰底 + 蓝链接；
             * 皮肤激活时 §18 会重写所有 --khs-* 变量。 */
            --khs-bg-1:#f5f5f5;--khs-bg-2:#f0f0f0;--khs-bg-3:#ebebeb;
            --khs-fg:#333;--khs-fg-soft:#444;--khs-fg-dim:#777;--khs-fg-mute:#999;
            --khs-accent:#336699;--khs-accent-2:#2B5278;
            --khs-line:rgba(0,0,0,.08);--khs-line-strong:rgba(0,0,0,.16);
            --khs-card-bg:#ffffff;
            --khs-card-shadow:0 1px 3px rgba(0,0,0,.06);
            --khs-card-shadow-hv:0 8px 18px rgba(0,0,0,.10);
            --khs-page-on:#ffffff;
            --khs-stat-comment-1:#4a8ad8;--khs-stat-comment-2:#336699;
            --khs-stat-post-1:#5fa8b2;--khs-stat-post-2:#3d7f8a;
            --khs-stat-user-1:#7a9bcc;--khs-stat-user-2:#557aaa;
            --khs-heart-fill:#e8516e;
            padding:0;position:relative;
            background:transparent;
        }
        .kratos-heart-shortcode > *{position:relative;z-index:1;}

        /* 页头卡片：对齐归档 shortcode 的 .kas-header
         * 横向布局（图标 + 标题 + 分隔线 + 副标题），白卡 + 细边 + 微阴影 */
        .kratos-heart-shortcode .khs-header{
            display:flex;align-items:center;flex-wrap:wrap;gap:14px;
            padding:24px 28px;margin-bottom:18px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:14px;
            box-shadow:var(--khs-card-shadow);
            text-align:left;
        }
        .kratos-heart-shortcode .khs-title-icon{
            display:inline-flex;align-items:center;justify-content:center;
            width:38px;height:38px;
            border-radius:10px;
            background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
            color:var(--khs-accent);
        }
        .kratos-heart-shortcode .khs-title{
            margin:0;padding:0;
            font-size:22px;font-weight:700;line-height:1.3;
            color:var(--khs-fg);
            letter-spacing:0;
        }
        .kratos-heart-shortcode .khs-header-divider{
            display:inline-block;width:1px;height:22px;
            background:var(--khs-line-strong);
        }
        .kratos-heart-shortcode .khs-subtitle{
            margin:0;padding:0;
            font-size:14px;line-height:1.5;
            color:var(--khs-fg-soft);
        }

        /* 三张总览卡：对齐归档 .kas-totals / .kas-total */
        .kratos-heart-shortcode .khs-stats{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:16px;
            margin:0 0 22px;
        }
        .kratos-heart-shortcode .khs-stat{
            display:flex;align-items:center;gap:14px;
            padding:22px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:14px;
            box-shadow:var(--khs-card-shadow);
            transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }
        .kratos-heart-shortcode .khs-stat:hover{
            transform:translateY(-2px);
            box-shadow:var(--khs-card-shadow-hv);
            border-color:var(--khs-line-strong);
        }
        /* 中性灰圆图标 + 强调色描边，与 .kas-total-icon 视觉一致 */
        .kratos-heart-shortcode .khs-stat-icon{
            flex-shrink:0;
            display:inline-flex;align-items:center;justify-content:center;
            width:46px;height:46px;
            border-radius:50%;
            background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
            color:var(--khs-accent);
        }
        .kratos-heart-shortcode .khs-stat-body{
            display:flex;flex-direction:column;gap:2px;
            min-width:0;
        }
        .kratos-heart-shortcode .khs-stat-label{
            font-size:13px;line-height:1.2;
            color:var(--khs-fg-dim);
        }
        .kratos-heart-shortcode .khs-stat-num{
            font-size:30px;font-weight:700;line-height:1.1;
            color:var(--khs-fg);
            letter-spacing:-0.01em;
        }

        /* 走心评论卡列表 */
        .kratos-heart-shortcode .khs-list{
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
            gap:14px;
        }
        .kratos-heart-shortcode .khs-card{
            display:block;
            padding:16px 18px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:12px;
            text-decoration:none !important;
            color:inherit !important;
            box-shadow:var(--khs-card-shadow);
            transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .kratos-heart-shortcode .khs-card:hover{
            transform:translateY(-2px);
            box-shadow:var(--khs-card-shadow-hv);
            border-color:var(--khs-line-strong);
        }
        .kratos-heart-shortcode .khs-card-head{display:flex;align-items:center;gap:10px;}
        /* 头像：去掉硬编码白边（在暗夜下会形成高亮光晕），改用卡片底色描边 */
        .kratos-heart-shortcode .khs-avatar-img,
        .kratos-heart-shortcode .khs-avatar img{
            width:40px !important;height:40px !important;
            border-radius:50% !important;
            flex-shrink:0;
            border:1px solid var(--khs-line);
            box-shadow:none;
        }
        .kratos-heart-shortcode .khs-meta{flex:1;min-width:0;display:flex;flex-direction:column;}
        .kratos-heart-shortcode .khs-author{font-size:13px;font-weight:600;color:var(--khs-fg-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .kratos-heart-shortcode .khs-time{font-size:11px;color:var(--khs-fg-mute);margin-top:2px;}
        /* 心形角标：用主题变量做底，避免暗夜下 rgba(0,0,0,.06) 几乎不可见 */
        .kratos-heart-shortcode .khs-heart-icon{
            flex-shrink:0;
            display:inline-flex;align-items:center;justify-content:center;
            width:24px;height:24px;
            border-radius:50%;
            background:var(--khs-line);
        }
        .kratos-heart-shortcode .khs-heart-icon svg{fill:var(--khs-heart-fill);}
        .kratos-heart-shortcode .khs-text{
            margin:10px 0 8px;
            font-size:13px;line-height:1.7;
            color:var(--khs-fg);
            display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;
            overflow:hidden;
        }
        /* 来源文章：实线细分隔（不要 dashed strong，过抢） */
        .kratos-heart-shortcode .khs-from{
            display:flex;align-items:center;gap:4px;
            margin-top:8px;padding-top:8px;
            border-top:1px solid var(--khs-line);
            font-size:11px;color:var(--khs-fg-mute);
        }
        .kratos-heart-shortcode .khs-from span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

        .kratos-heart-shortcode .khs-empty{
            padding:36px 16px;
            text-align:center;
            color:var(--khs-fg-dim);font-size:14px;
            background:var(--khs-card-bg);
            border:1px dashed var(--khs-line-strong);
            border-radius:12px;
        }

        /* 分页：简化为纯色 accent hover，去渐变 + 阴影 + translateY，
         * 与 .kas-tab 同款克制风格，避免与卡片 hover 抢视觉。 */
        .kratos-heart-shortcode .khs-pagination{
            display:flex;justify-content:center;align-items:center;
            gap:6px;flex-wrap:wrap;
            margin-top:22px;
        }
        .kratos-heart-shortcode .khs-page{
            display:inline-flex;align-items:center;justify-content:center;
            min-width:34px;height:34px;padding:0 12px;
            font-size:13px;
            color:var(--khs-fg-soft) !important;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:8px;
            text-decoration:none !important;
            transition:background .2s ease, color .2s ease, border-color .2s ease;
        }
        .kratos-heart-shortcode .khs-page:hover{
            background:var(--khs-accent);
            color:var(--khs-page-on) !important;
            border-color:var(--khs-accent);
        }
        .kratos-heart-shortcode .khs-current{
            background:var(--khs-accent);
            color:var(--khs-page-on) !important;
            border-color:var(--khs-accent);
            cursor:default;font-weight:600;
        }
        .kratos-heart-shortcode .khs-disabled{opacity:.40;cursor:not-allowed;}
        .kratos-heart-shortcode .khs-disabled:hover{
            background:var(--khs-card-bg);
            color:var(--khs-fg-soft) !important;
            border-color:var(--khs-line);
        }
        .kratos-heart-shortcode .khs-ellipsis{
            border-color:transparent;background:transparent;
            cursor:default;color:var(--khs-fg-mute);
        }
        .kratos-heart-shortcode .khs-ellipsis:hover{
            background:transparent;
            color:var(--khs-fg-mute) !important;
            border-color:transparent;
        }
        .kratos-heart-shortcode .khs-page-info{margin-top:10px;text-align:center;font-size:12px;color:var(--khs-fg-dim);}
        .kratos-heart-shortcode .khs-page-info strong{color:var(--khs-accent);font-weight:700;margin:0 2px;}

        /* 响应式：与归档 shortcode 同断点 */
        @media (max-width:900px){
            .kratos-heart-shortcode .khs-stats{grid-template-columns:repeat(3,minmax(0,1fr));}
        }
        @media (max-width:560px){
            .kratos-heart-shortcode .khs-header{padding:18px 20px;gap:10px;}
            .kratos-heart-shortcode .khs-title{font-size:19px;}
            .kratos-heart-shortcode .khs-header-divider{display:none;}
            .kratos-heart-shortcode .khs-subtitle{flex-basis:100%;font-size:13px;}
            .kratos-heart-shortcode .khs-stats{grid-template-columns:1fr;gap:12px;}
            .kratos-heart-shortcode .khs-stat{padding:16px 18px;}
            .kratos-heart-shortcode .khs-stat-num{font-size:24px;}
            .kratos-heart-shortcode .khs-list{grid-template-columns:1fr;}
        }

        /* === parchment 方案：保留 class 锚点但不再画装饰 ===
         * 真正的做旧/锯齿/焦边由「黄绢」皮肤在 weekday-skins.css 处理。
         * 默认状态下走心区域是干净的白卡 + 浅灰底，与主题首页一致。 */
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

        /* === 暗夜模式：对齐 dark.css 中性灰白调（与归档统计 shortcode 同步） ===
         * 默认 parchment scheme 在浅模式下已无米黄装饰；暗夜模式同样不画暖褐，
         * 而是走 dark.css 同款 #1c1f24 卡片 + #6ea8ff 链接，避免在夜里出现
         * 一块"暗黄色羊皮纸"与主题其他区块（文章卡/侧边栏 widget/评论区）撞色。 */
        html[data-theme="dark"] .kratos-heart-shortcode,body.dark .kratos-heart-shortcode{
            --khs-fg:#d6d8db;--khs-fg-soft:#b8bbc0;--khs-fg-dim:#8b919a;--khs-fg-mute:#6f747e;
            --khs-accent:#6ea8ff;--khs-accent-2:#91bdff;
            --khs-line:rgba(255,255,255,.08);--khs-line-strong:rgba(255,255,255,.16);
            --khs-card-bg:#1c1f24;
            --khs-stat-comment-1:#6ea8ff;--khs-stat-comment-2:#4f86c6;
            --khs-stat-post-1:#6ea8ff;--khs-stat-post-2:#4f86c6;
            --khs-stat-user-1:#6ea8ff;--khs-stat-user-2:#4f86c6;
            --khs-heart-fill:#e8516e;
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('heart_comments', 'kratos_heart_shortcode');

/**
 * 给应用了 page-heart-comments.php 模板的页面注入 body class
 * `is-kratos-heart-page`，让皮肤层精准豁免 §15 / §18 对外层 .details 的装饰。
 */
function kratos_heart_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-heart-comments.php')) {
        $classes[] = 'is-kratos-heart-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_heart_body_class');
