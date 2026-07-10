<?php
/**
 * 评论赞踩 + 置顶 + 热门评论
 * @author Dylan Li
 * @license GPL-3.0
 */
if (!defined('ABSPATH')) exit;

/* ============================================================
 * 默认值一次性迁移 —— CSF 只在选项为空时写入默认，
 * 新字段追加后不会自动落库，这里补齐
 * ============================================================ */
function kratos_comment_reactions_migrate_defaults()
{
    $flag = 'kratos_comment_reactions_defaults_v3';
    if (get_option($flag)) return;

    $defaults = array(
        'g_comment_honeypot_enabled'        => true,
        'g_comment_honeypot_min_seconds'    => 3,
        'g_comment_reactions_enabled'       => true,
        'g_comment_reactions_like_icon'     => 'fas fa-thumbs-up',
        'g_comment_reactions_dislike_icon'  => 'fas fa-thumbs-down',
        'g_comment_reactions_like_text'     => '赞',
        'g_comment_reactions_dislike_text'  => '踩',
        'g_comment_reactions_like_color'    => '#e74c3c',
        'g_comment_reactions_dislike_color' => '#7f8c8d',
        'g_comment_hot_enabled'             => true,
        'g_comment_hot_threshold'           => 5,
        'g_comment_hot_limit'               => 3,
        'g_comment_reply_collapse'          => 5,
    );

    $options = get_option('kratos_options');
    if (!is_array($options)) $options = array();

    $changed = false;
    foreach ($defaults as $k => $v) {
        // 只补写「完全不存在」或「空串」的键，不覆盖用户已选值
        if (!array_key_exists($k, $options) || $options[$k] === '' || $options[$k] === null) {
            $options[$k] = $v;
            $changed = true;
        }
    }

    if ($changed) update_option('kratos_options', $options);
    update_option($flag, 1);
}
add_action('admin_init', 'kratos_comment_reactions_migrate_defaults');
add_action('init', 'kratos_comment_reactions_migrate_defaults');

/* ============================================================
 * 通用工具
 * ============================================================ */
function kratos_comment_meta_int($comment_id, $key)
{
    return (int) get_comment_meta($comment_id, $key, true);
}

function kratos_comment_vote_cookie_key($comment_id)
{
    return 'kratos_voted_' . intval($comment_id);
}

/* ============================================================
 * AJAX 赞踩
 * ============================================================ */
function kratos_comment_vote_ajax()
{
    if (!kratos_option('g_comment_reactions_enabled', true)) {
        wp_send_json_error(array('msg' => '功能未开启'), 403);
    }

    check_ajax_referer('kratos_comment_vote', 'nonce');

    $comment_id = isset($_POST['comment_id']) ? intval($_POST['comment_id']) : 0;
    $type       = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';

    if ($comment_id <= 0 || !in_array($type, array('like', 'dislike'), true)) {
        wp_send_json_error(array('msg' => '参数错误'), 400);
    }

    $comment = get_comment($comment_id);
    if (!$comment || $comment->comment_approved != '1') {
        wp_send_json_error(array('msg' => '评论不存在'), 404);
    }

    $cookie_key = kratos_comment_vote_cookie_key($comment_id);
    $prev       = isset($_COOKIE[$cookie_key]) ? sanitize_key($_COOKIE[$cookie_key]) : '';

    // 已投同类 → 撤销
    $likes    = kratos_comment_meta_int($comment_id, 'kratos_likes');
    $dislikes = kratos_comment_meta_int($comment_id, 'kratos_dislikes');

    if ($prev === $type) {
        // 撤销
        if ($type === 'like')    $likes = max(0, $likes - 1);
        else                     $dislikes = max(0, $dislikes - 1);
        setcookie($cookie_key, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        unset($_COOKIE[$cookie_key]);
        $current = '';
    } elseif ($prev === 'like' && $type === 'dislike') {
        $likes = max(0, $likes - 1);
        $dislikes++;
        setcookie($cookie_key, 'dislike', time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        $_COOKIE[$cookie_key] = 'dislike';
        $current = 'dislike';
    } elseif ($prev === 'dislike' && $type === 'like') {
        $dislikes = max(0, $dislikes - 1);
        $likes++;
        setcookie($cookie_key, 'like', time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        $_COOKIE[$cookie_key] = 'like';
        $current = 'like';
    } else {
        if ($type === 'like') $likes++;
        else                  $dislikes++;
        setcookie($cookie_key, $type, time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        $_COOKIE[$cookie_key] = $type;
        $current = $type;
    }

    update_comment_meta($comment_id, 'kratos_likes', $likes);
    update_comment_meta($comment_id, 'kratos_dislikes', $dislikes);

    wp_send_json_success(array(
        'likes'    => $likes,
        'dislikes' => $dislikes,
        'current'  => $current,
    ));
}
add_action('wp_ajax_kratos_comment_vote', 'kratos_comment_vote_ajax');
add_action('wp_ajax_nopriv_kratos_comment_vote', 'kratos_comment_vote_ajax');

/* ============================================================
 * 在页面加载阶段入队 FontAwesome —— 单页 + 评论开启时
 * ============================================================ */
function kratos_comment_reactions_enqueue_assets()
{
    if (!is_singular() || !comments_open()) return;
    if (!kratos_option('g_comment_reactions_enabled', true)) return;
    if (!wp_style_is('fontawesome', 'enqueued') && !wp_style_is('fontawesome', 'registered')) {
        wp_enqueue_style('fontawesome', get_template_directory_uri() . '/assets/css/fontawesome.min.css', array(), '5.15.2');
    }
}
add_action('wp_enqueue_scripts', 'kratos_comment_reactions_enqueue_assets', 20);

/* ============================================================
 * 渲染赞踩按钮 —— 供 comment_callbacks 内部调用
 * ============================================================ */
function kratos_render_comment_reactions($comment_id)
{
    if (!kratos_option('g_comment_reactions_enabled', true)) return '';

    $likes    = kratos_comment_meta_int($comment_id, 'kratos_likes');
    $dislikes = kratos_comment_meta_int($comment_id, 'kratos_dislikes');
    $voted    = isset($_COOKIE[kratos_comment_vote_cookie_key($comment_id)])
        ? sanitize_key($_COOKIE[kratos_comment_vote_cookie_key($comment_id)]) : '';

    $like_active    = $voted === 'like' ? ' voted' : '';
    $dislike_active = $voted === 'dislike' ? ' voted' : '';

    $like_icon    = trim((string) kratos_option('g_comment_reactions_like_icon', 'fas fa-thumbs-up'));
    $dislike_icon = trim((string) kratos_option('g_comment_reactions_dislike_icon', 'fas fa-thumbs-down'));
    if ($like_icon === '')    $like_icon    = 'fas fa-thumbs-up';
    if ($dislike_icon === '') $dislike_icon = 'fas fa-thumbs-down';
    $like_text    = kratos_option('g_comment_reactions_like_text', __('赞', 'kratos'));
    $dislike_text = kratos_option('g_comment_reactions_dislike_text', __('踩', 'kratos'));

    return '<span class="kc-vote" data-cid="' . intval($comment_id) . '">'
        . '<a href="javascript:;" class="kc-like' . $like_active . '" title="' . esc_attr($like_text) . '">'
        . '<i class="' . esc_attr($like_icon) . '"></i><em>' . intval($likes) . '</em></a>'
        . '<a href="javascript:;" class="kc-dislike' . $dislike_active . '" title="' . esc_attr($dislike_text) . '">'
        . '<i class="' . esc_attr($dislike_icon) . '"></i><em>' . intval($dislikes) . '</em></a>'
        . '</span>';
}

/* ============================================================
 * 置顶 —— 后台评论列表行操作
 * ============================================================ */
function kratos_comment_sticky_row_action($actions, $comment)
{
    if (!current_user_can('moderate_comments')) return $actions;
    $is_sticky = kratos_comment_meta_int($comment->comment_ID, 'kratos_sticky');
    $nonce     = wp_create_nonce('kratos_toggle_sticky_' . $comment->comment_ID);
    $url       = admin_url('admin-post.php?action=kratos_toggle_sticky&comment_id=' . $comment->comment_ID . '&_wpnonce=' . $nonce);
    $label     = $is_sticky ? __('取消置顶', 'kratos') : __('置顶', 'kratos');
    $actions['kratos_sticky'] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    return $actions;
}
add_filter('comment_row_actions', 'kratos_comment_sticky_row_action', 10, 2);

function kratos_toggle_sticky_handler()
{
    if (!current_user_can('moderate_comments')) wp_die('权限不足');
    $comment_id = isset($_GET['comment_id']) ? intval($_GET['comment_id']) : 0;
    if (!$comment_id) wp_die('参数错误');
    check_admin_referer('kratos_toggle_sticky_' . $comment_id);

    $current = kratos_comment_meta_int($comment_id, 'kratos_sticky');
    if ($current) {
        delete_comment_meta($comment_id, 'kratos_sticky');
    } else {
        update_comment_meta($comment_id, 'kratos_sticky', 1);
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url('edit-comments.php'));
    exit;
}
add_action('admin_post_kratos_toggle_sticky', 'kratos_toggle_sticky_handler');

/* ============================================================
 * 置顶 —— 主列表中移除已置顶的顶层评论（连同其所有后代），
 * 因为它们已在独立的「置顶评论区」渲染
 * ============================================================ */
function kratos_comments_filter_out_sticky($comments)
{
    if (empty($comments) || is_admin()) return $comments;

    // 收集所有 sticky 的顶层评论 ID
    $sticky_top_ids = array();
    foreach ($comments as $c) {
        if (empty($c->comment_parent) && kratos_comment_meta_int($c->comment_ID, 'kratos_sticky')) {
            $sticky_top_ids[intval($c->comment_ID)] = true;
        }
    }
    if (empty($sticky_top_ids)) return $comments;

    // 构建 comment_ID => parent 映射，递归标记 sticky 子孙
    $to_remove = $sticky_top_ids;
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($comments as $c) {
            $cid = intval($c->comment_ID);
            $pid = intval($c->comment_parent);
            if (!isset($to_remove[$cid]) && $pid && isset($to_remove[$pid])) {
                $to_remove[$cid] = true;
                $changed = true;
            }
        }
    }

    $filtered = array();
    foreach ($comments as $c) {
        if (!isset($to_remove[intval($c->comment_ID)])) $filtered[] = $c;
    }
    return $filtered;
}
add_filter('comments_array', 'kratos_comments_filter_out_sticky', 20);

/* ============================================================
 * 内部：渲染一组顶层评论 + 各自后代（复用主题 comment_callbacks）
 * ============================================================ */
function kratos_render_comment_group($post_id, $top_comments, $wrap_class, $title, $title_icon)
{
    if (empty($top_comments) || !function_exists('comment_callbacks')) return '';

    $render_args = array(
        'max_depth' => (int) get_option('thread_comments_depth', 5),
    );

    ob_start();
    echo '<div class="' . esc_attr($wrap_class) . ' mb-3">';
    $icon_class = (strpos($title_icon, 'fa') === 0) ? $title_icon : ('kicon ' . $title_icon);
    echo '<h4 class="hot-comments-title"><i class="' . esc_attr($icon_class) . '"></i> ' . esc_html($title) . '</h4>';
    echo '<ul class="hot-comments-list list">';
    foreach ($top_comments as $c) {
        comment_callbacks($c, $render_args, 1);
        $replies = kratos_collect_descendants($post_id, $c->comment_ID);
        if (!empty($replies)) {
            echo '<ul class="children">';
            foreach ($replies as $r) {
                comment_callbacks($r, $render_args, 2);
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</li>'; // 闭合 comment_callbacks 开的顶层 <li>
    }
    echo '</ul></div>';
    return ob_get_clean();
}

/* ============================================================
 * 置顶评论区 —— 顶部独立展示
 * ============================================================ */
function kratos_render_sticky_comments($post_id)
{
    $comments = get_comments(array(
        'post_id'    => $post_id,
        'status'     => 'approve',
        'type'       => 'comment',
        'parent'     => 0,
        'meta_key'   => 'kratos_sticky',
        'meta_value' => 1,
        'orderby'    => 'comment_date_gmt',
        'order'      => 'DESC',
    ));
    return kratos_render_comment_group($post_id, $comments, 'sticky-comments', __('置顶评论', 'kratos'), 'fas fa-thumbtack');
}

/* ============================================================
 * 热门评论 —— 前端渲染（排除置顶评论）
 * ============================================================ */
function kratos_render_hot_comments($post_id)
{
    if (!kratos_option('g_comment_hot_enabled', true)) return '';

    $threshold = max(1, intval(kratos_option('g_comment_hot_threshold', 5)));
    $limit     = max(1, intval(kratos_option('g_comment_hot_limit', 3)));

    // 先取置顶评论 ID，从热门评论中排除
    $sticky_ids = get_comments(array(
        'post_id'    => $post_id,
        'status'     => 'approve',
        'type'       => 'comment',
        'parent'     => 0,
        'meta_key'   => 'kratos_sticky',
        'meta_value' => 1,
        'fields'     => 'ids',
    ));

    $args = array(
        'post_id'    => $post_id,
        'status'     => 'approve',
        'type'       => 'comment',
        'parent'     => 0,
        'number'     => $limit,
        'meta_key'   => 'kratos_likes',
        'orderby'    => 'meta_value_num',
        'order'      => 'DESC',
        'meta_query' => array(
            array(
                'key'     => 'kratos_likes',
                'value'   => $threshold,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ),
        ),
    );
    if (!empty($sticky_ids)) $args['comment__not_in'] = $sticky_ids;

    $comments = get_comments($args);
    return kratos_render_comment_group($post_id, $comments, 'hot-comments', __('热门评论', 'kratos'), 'i-hot');
}

/**
 * 递归收集某评论下所有后代（按时间正序）
 */
function kratos_collect_descendants($post_id, $parent_id)
{
    static $all_cache = array();
    if (!isset($all_cache[$post_id])) {
        $all_cache[$post_id] = get_comments(array(
            'post_id' => $post_id,
            'status'  => 'approve',
            'type'    => 'comment',
            'orderby' => 'comment_date_gmt',
            'order'   => 'ASC',
        ));
    }
    $result = array();
    $stack  = array(intval($parent_id));
    while ($stack) {
        $pid = array_shift($stack);
        foreach ($all_cache[$post_id] as $c) {
            if (intval($c->comment_parent) === $pid) {
                $result[] = $c;
                $stack[] = intval($c->comment_ID);
            }
        }
    }
    return $result;
}
