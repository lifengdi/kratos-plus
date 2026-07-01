<?php
/**
 * 评论友链标识
 *
 * 功能：
 *   - 判断评论者填写的网站 URL 是否命中 WordPress 内置「链接（Blogroll）」列表
 *   - 命中则在作者名后追加「友链」徽章（前后台评论列表均生效）
 *   - 开关、文字、颜色可在后台设置
 *
 * 匹配策略：
 *   - 只比对 host（域名），忽略 scheme / 端口 / 路径；www. 前缀自动去掉
 *   - 空 URL 或 host 解析失败的评论者不参与匹配
 *   - 友链 host 集合用 transient 缓存 1 小时，链接增删/修改时清缓存
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

const KRATOS_BLOGROLL_HOSTS_CACHE_KEY = 'kratos_blogroll_hosts';
const KRATOS_BLOGROLL_HOSTS_CACHE_TTL = HOUR_IN_SECONDS;

/* ============================================================
 *  基础工具
 * ============================================================ */

function kratos_blogroll_enabled()
{
    return (bool) kratos_option('g_comment_blogroll_enabled', false);
}

/**
 * 归一化 host：去 scheme、去 www.、转小写
 */
function kratos_blogroll_normalize_host($url)
{
    if (!is_string($url) || $url === '') {
        return '';
    }
    // 允许用户在链接里只填域名而不带协议
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . ltrim($url, '/');
    }
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }
    $host = strtolower($host);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return $host;
}

/**
 * 读取所有已发布友链的 host 集合（带缓存）
 * @return array<string, true>
 */
function kratos_blogroll_hosts()
{
    $cached = get_transient(KRATOS_BLOGROLL_HOSTS_CACHE_KEY);
    if (is_array($cached)) {
        return $cached;
    }

    $hosts = array();
    if (function_exists('get_bookmarks')) {
        $bookmarks = get_bookmarks(array(
            'hide_invisible' => 1,
            'orderby'        => 'link_id',
            'limit'          => -1,
        ));
        foreach ((array) $bookmarks as $link) {
            $host = kratos_blogroll_normalize_host(isset($link->link_url) ? $link->link_url : '');
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
    }

    set_transient(KRATOS_BLOGROLL_HOSTS_CACHE_KEY, $hosts, KRATOS_BLOGROLL_HOSTS_CACHE_TTL);
    return $hosts;
}

function kratos_blogroll_clear_cache()
{
    delete_transient(KRATOS_BLOGROLL_HOSTS_CACHE_KEY);
}
add_action('add_link',    'kratos_blogroll_clear_cache');
add_action('edit_link',   'kratos_blogroll_clear_cache');
add_action('delete_link', 'kratos_blogroll_clear_cache');

/**
 * 判断某条评论的作者网站是否在友链列表里
 */
function kratos_blogroll_is_friend($comment)
{
    if (!$comment instanceof WP_Comment) {
        return false;
    }
    $host = kratos_blogroll_normalize_host($comment->comment_author_url);
    if ($host === '') {
        return false;
    }
    $hosts = kratos_blogroll_hosts();
    return isset($hosts[$host]);
}

/* ============================================================
 *  作者名后追加「友链」徽章
 * ============================================================ */

function kratos_blogroll_badge_html()
{
    $text = (string) kratos_option('g_comment_blogroll_badge_text', __('友链', 'kratos'));
    if (trim($text) === '') {
        $text = __('友链', 'kratos');
    }
    $color    = sanitize_hex_color((string) kratos_option('g_comment_blogroll_badge_color', '#ffffff')) ?: '#ffffff';
    $bg_start = sanitize_hex_color((string) kratos_option('g_comment_blogroll_badge_bg_start', '#38bdf8')) ?: '#38bdf8';
    $bg_end   = sanitize_hex_color((string) kratos_option('g_comment_blogroll_badge_bg_end', '#6366f1')) ?: '#6366f1';

    $style = sprintf(
        'display:inline-flex;align-items:center;gap:3px;margin-left:6px;padding:1px 8px;font-size:11px;line-height:1.5;border-radius:10px;color:%s;background:linear-gradient(135deg,%s 0%%,%s 100%%);vertical-align:middle;font-weight:500;box-shadow:0 1px 3px rgba(0,0,0,0.18);',
        esc_attr($color),
        esc_attr($bg_start),
        esc_attr($bg_end)
    );
    // 链条图标（Feather link 简化版），暗示"站点关联"
    $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr($color) . '" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 1 0-7.07-7.07l-1 1"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 1 0 7.07 7.07l1-1"/></svg>';
    return '<span class="kratos-blogroll-badge" title="' . esc_attr__('友链博主', 'kratos') . '" style="' . $style . '">' . $icon . esc_html($text) . '</span>';
}

function kratos_blogroll_append_badge($author_link, $author = '', $comment_id = 0)
{
    if (!kratos_blogroll_enabled()) {
        return $author_link;
    }

    $comment = null;
    if ($comment_id) {
        $comment = get_comment($comment_id);
    } elseif (!empty($GLOBALS['comment']) && $GLOBALS['comment'] instanceof WP_Comment) {
        $comment = $GLOBALS['comment'];
    }

    if (!$comment || !kratos_blogroll_is_friend($comment)) {
        return $author_link;
    }

    return $author_link . kratos_blogroll_badge_html();
}
// 优先级 12：晚于 rank(10)、heart(11)，让友链徽章显示在最右侧
add_filter('get_comment_author_link', 'kratos_blogroll_append_badge', 12, 3);
