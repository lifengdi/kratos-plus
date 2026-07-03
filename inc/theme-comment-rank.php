<?php
/**
 * 评论用户等级
 *
 * 功能：
 *   - 按评论数划分等级，给每条评论作者名后追加头衔徽章
 *   - 头衔/门槛/样式都从主题设置（评论配置 → 通用配置 → 用户等级）读取
 *   - 前后台都生效（hook get_comment_author_link，所有列表/详情都过这个 filter）
 *
 * 计数策略：
 *   - 已登录用户按 user_id 统计 → 同一账号换邮箱也算同一人
 *   - 游客按 comment_author_email 统计
 *   - 用 transient 缓存 1 小时；新评论审核通过 / 状态变更时主动清除作者缓存
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

const KRATOS_RANK_CACHE_PREFIX = 'kratos_rank_count_';
const KRATOS_RANK_CACHE_TTL    = HOUR_IN_SECONDS;

/* ============================================================
 *  配置读取
 * ============================================================ */

function kratos_rank_enabled()
{
    return (bool) kratos_option('g_comment_rank_enabled', true);
}

/**
 * 默认等级配置（5 档）
 */
function kratos_rank_defaults()
{
    return array(
        array('threshold' => 0,   'title' => '新人',   'color' => '#ffffff', 'bg_color' => '#9ca3af'),
        array('threshold' => 5,   'title' => '常客',   'color' => '#ffffff', 'bg_color' => '#3b82f6'),
        array('threshold' => 20,  'title' => '熟客',   'color' => '#ffffff', 'bg_color' => '#10b981'),
        array('threshold' => 50,  'title' => '大佬',   'color' => '#ffffff', 'bg_color' => '#f59e0b'),
        array('threshold' => 100, 'title' => '传奇',   'color' => '#ffffff', 'bg_color' => '#ef4444'),
    );
}

/**
 * 读取等级配置（合并默认值，按 threshold 升序排）
 * @return array
 */
function kratos_rank_levels()
{
    $opt = kratos_option('g_comment_rank_levels', null);
    if (!is_array($opt) || empty($opt)) {
        return kratos_rank_defaults();
    }

    $levels = array();
    foreach ($opt as $row) {
        if (!is_array($row)) continue;
        $threshold = isset($row['threshold']) ? max(0, intval($row['threshold'])) : 0;
        $title     = isset($row['title']) ? trim((string) $row['title']) : '';
        if ($title === '') continue;
        $levels[] = array(
            'threshold' => $threshold,
            'title'     => $title,
            'color'     => isset($row['color']) && $row['color'] !== '' ? $row['color'] : '#ffffff',
            'bg_color'  => isset($row['bg_color']) && $row['bg_color'] !== '' ? $row['bg_color'] : '#9ca3af',
        );
    }

    if (empty($levels)) {
        return kratos_rank_defaults();
    }

    usort($levels, function ($a, $b) {
        return $a['threshold'] - $b['threshold'];
    });

    return $levels;
}

/* ============================================================
 *  评论计数
 * ============================================================ */

/**
 * 统计某评论作者的已审核评论数（含 trackback/pingback 也计入？这里只算 comment）
 *
 * @param WP_Comment $comment
 * @return int
 */
function kratos_rank_count_for_comment($comment)
{
    if (!($comment instanceof WP_Comment)) {
        return 0;
    }

    $user_id = (int) $comment->user_id;
    $email   = (string) $comment->comment_author_email;

    if ($user_id > 0) {
        $key = 'u_' . $user_id;
        $where = array('user_id' => $user_id);
    } elseif ($email !== '') {
        $key = 'e_' . md5(strtolower($email));
        $where = array('email' => $email);
    } else {
        // 匿名（无邮箱）：按作者名兜底，但同名很容易撞，所以直接返回 0
        return 0;
    }

    // 进程内缓存（单次请求 N 条评论只查一次）
    static $local = array();
    if (isset($local[$key])) {
        return $local[$key];
    }

    $cache_key = KRATOS_RANK_CACHE_PREFIX . $key;
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        $local[$key] = (int) $cached;
        return $local[$key];
    }

    global $wpdb;
    if (isset($where['user_id'])) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments}
             WHERE comment_approved = '1'
               AND (comment_type = '' OR comment_type = 'comment')
               AND user_id = %d",
            $where['user_id']
        ));
    } else {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments}
             WHERE comment_approved = '1'
               AND (comment_type = '' OR comment_type = 'comment')
               AND comment_author_email = %s",
            $where['email']
        ));
    }

    set_transient($cache_key, $count, KRATOS_RANK_CACHE_TTL);
    $local[$key] = $count;
    return $count;
}

/**
 * 评论审核状态变化时清除作者缓存
 */
function kratos_rank_invalidate_cache($comment_id)
{
    $comment = get_comment($comment_id);
    if (!$comment) return;

    if ((int) $comment->user_id > 0) {
        delete_transient(KRATOS_RANK_CACHE_PREFIX . 'u_' . (int) $comment->user_id);
    }
    if ($comment->comment_author_email) {
        delete_transient(KRATOS_RANK_CACHE_PREFIX . 'e_' . md5(strtolower($comment->comment_author_email)));
    }
}
add_action('wp_insert_comment', 'kratos_rank_invalidate_cache');
add_action('wp_set_comment_status', 'kratos_rank_invalidate_cache');
add_action('edit_comment', 'kratos_rank_invalidate_cache');
add_action('deleted_comment', 'kratos_rank_invalidate_cache');
add_action('trashed_comment', 'kratos_rank_invalidate_cache');
add_action('untrashed_comment', 'kratos_rank_invalidate_cache');

/* ============================================================
 *  等级匹配 / 徽章 HTML
 * ============================================================ */

/**
 * 根据评论数返回匹配的等级（取 threshold 不超过 count 的最高一级）
 * @return array|null
 */
function kratos_rank_match($count)
{
    $levels = kratos_rank_levels();
    $matched = null;
    foreach ($levels as $level) {
        if ($count >= $level['threshold']) {
            $matched = $level;
        } else {
            break;
        }
    }
    return $matched;
}

function kratos_rank_is_admin_comment($comment)
{
    $user_id = (int) $comment->user_id;
    if ($user_id <= 0) {
        return false;
    }
    $user = get_userdata($user_id);
    return $user && user_can($user, 'manage_options');
}

function kratos_rank_admin_badge_html()
{
    $text  = (string) kratos_option('g_comment_admin_badge_text', __('管理', 'kratos'));
    $color = sanitize_hex_color((string) kratos_option('g_comment_admin_badge_color', '#ffffff')) ?: '#ffffff';
    $bg    = sanitize_hex_color((string) kratos_option('g_comment_admin_badge_bg', '#e74c3c')) ?: '#e74c3c';

    $style = sprintf(
        'display:inline-block;margin-left:6px;padding:1px 7px;font-size:11px;line-height:1.5;border-radius:3px;color:%s;background:%s;vertical-align:middle;font-weight:500;',
        esc_attr($color),
        esc_attr($bg)
    );

    return sprintf(
        '<span class="kratos-rank-badge kratos-admin-badge" title="%s" style="%s">%s</span>',
        esc_attr($text),
        $style,
        esc_html($text)
    );
}

function kratos_rank_badge_html($comment)
{
    if ((bool) kratos_option('g_comment_admin_badge_enabled', true) && kratos_rank_is_admin_comment($comment)) {
        return kratos_rank_admin_badge_html();
    }

    $count = kratos_rank_count_for_comment($comment);
    $level = kratos_rank_match($count);
    if (!$level) {
        return '';
    }

    $color    = sanitize_hex_color($level['color']) ?: '#ffffff';
    $bg_color = sanitize_hex_color($level['bg_color']) ?: '#9ca3af';
    $title    = $level['title'];

    $style = sprintf(
        'display:inline-block;margin-left:6px;padding:1px 7px;font-size:11px;line-height:1.5;border-radius:3px;color:%s;background:%s;vertical-align:middle;font-weight:500;',
        esc_attr($color),
        esc_attr($bg_color)
    );

    return sprintf(
        '<span class="kratos-rank-badge" title="%s（%d 条评论）" style="%s">%s</span>',
        esc_attr($title . ' · ' . $count),
        $count,
        $style,
        esc_html($title)
    );
}

/* ============================================================
 *  注入到评论作者链接
 * ============================================================ */

/**
 * get_comment_author_link 是 WordPress 渲染评论作者超链接的核心 filter，
 * 前台主题模板、wp-admin 评论列表、ajax 评论提交都会过这里。
 */
function kratos_rank_append_badge($author_link, $author = '', $comment_id = 0)
{
    if (!kratos_rank_enabled()) {
        return $author_link;
    }

    // WP 4.1+ 三参数；老版本只传 author_link
    $comment = null;
    if ($comment_id) {
        $comment = get_comment($comment_id);
    } elseif (!empty($GLOBALS['comment']) && $GLOBALS['comment'] instanceof WP_Comment) {
        $comment = $GLOBALS['comment'];
    }

    if (!$comment) {
        return $author_link;
    }

    $badge = kratos_rank_badge_html($comment);
    if ($badge === '') {
        return $author_link;
    }

    return $author_link . $badge;
}
add_filter('get_comment_author_link', 'kratos_rank_append_badge', 10, 3);
