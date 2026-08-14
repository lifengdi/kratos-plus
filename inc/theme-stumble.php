<?php

/**
 * 随机漫步 Stumble —— 随机跳到一篇被埋没的老文章
 *
 * - 端点：/?kratos_stumble=1 → 302 重定向到候选池里随机一篇
 * - 候选池 = 发布时间早于 N 天(默认 180) + 评论数最少的老文章，偏向长尾曝光
 * - 候选池按天缓存 transient(key = kratos_stumble_pool_YYYYMMDD)，避免每次点击全表扫描
 * - 页脚右下角提供一个入口按钮(见 footer.php)，样式复用搜索按钮
 *
 * 可用过滤器：
 *   kratos_stumble_min_age_days  (int)   最小文章年龄天数，默认 180
 *   kratos_stumble_pool_size     (int)   候选池上限，默认 200
 *   kratos_stumble_query_args    (array) 直接改写 WP_Query 参数
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 注册 query var。
 */
add_filter('query_vars', function ($vars) {
    $vars[] = 'kratos_stumble';
    return $vars;
});

/**
 * 监听端点并重定向。
 */
add_action('template_redirect', function () {
    if (!get_query_var('kratos_stumble')) {
        return;
    }

    // 端点本身不应被索引
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow', true);
    }

    $current = is_singular() ? (int) get_queried_object_id() : 0;
    $id = kratos_stumble_pick($current);

    $target = $id ? get_permalink($id) : home_url('/');
    wp_safe_redirect($target, 302);
    exit;
});

/**
 * 从候选池随机取一篇被埋没的老文章。
 *
 * @param int $exclude_id 需要排除的文章 ID(通常是当前正在看的这篇)
 * @return int 命中的文章 ID；候选为空时返回 0
 */
function kratos_stumble_pick($exclude_id = 0)
{
    $opt_age  = function_exists('kratos_option') ? kratos_option('g_stumble_min_age', 180) : 180;
    $opt_pool = function_exists('kratos_option') ? kratos_option('g_stumble_pool_size', 200) : 200;

    $min_age  = max(0, (int) apply_filters('kratos_stumble_min_age_days', (int) $opt_age));
    $pool_max = max(1, (int) apply_filters('kratos_stumble_pool_size', (int) $opt_pool));

    $pool_key = 'kratos_stumble_pool_' . current_time('Ymd') . '_' . $min_age . '_' . $pool_max;

    $pool = get_transient($pool_key);
    if (!is_array($pool)) {
        $args = array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'fields'              => 'ids',
            'posts_per_page'      => $pool_max,
            'ignore_sticky_posts' => true,
            // 偏向长尾：评论最少、发布最早的优先进池
            'orderby'             => array('comment_count' => 'ASC', 'date' => 'ASC'),
            'no_found_rows'       => true,
        );
        if ($min_age > 0) {
            $args['date_query'] = array(array(
                'before' => date('Y-m-d', strtotime("-{$min_age} days")),
            ));
        }
        $args = apply_filters('kratos_stumble_query_args', $args, $min_age, $pool_max);

        $q = new WP_Query(kratos_lean_query_args($args, array('no_terms' => true, 'no_meta' => true)));
        $pool = array_map('intval', $q->posts);
        // 新站候选为空时退而用「全部已发布文章」，避免按钮永远打不开
        if (empty($pool)) {
            $q = new WP_Query(kratos_lean_query_args(array(
                'post_type'           => 'post',
                'post_status'         => 'publish',
                'fields'              => 'ids',
                'posts_per_page'      => $pool_max,
                'ignore_sticky_posts' => true,
                'orderby'             => 'rand',
                'no_found_rows'       => true,
            ), array('no_terms' => true, 'no_meta' => true)));
            $pool = array_map('intval', $q->posts);
        }
        set_transient($pool_key, $pool, DAY_IN_SECONDS);
    }

    if ($exclude_id) {
        $pool = array_values(array_diff($pool, array((int) $exclude_id)));
    }
    if (empty($pool)) {
        return 0;
    }
    return (int) $pool[array_rand($pool)];
}

/**
 * 随机漫步端点 URL。
 *
 * @return string
 */
function kratos_stumble_url()
{
    return esc_url(add_query_arg('kratos_stumble', '1', home_url('/')));
}

/**
 * 短码 [stumble text="随机漫步"]。
 */
add_shortcode('stumble', function ($atts) {
    $a = shortcode_atts(array('text' => __('随机漫步', 'kratos')), $atts, 'stumble');
    return '<a class="kr-btn kratos-stumble-inline" href="' . kratos_stumble_url() . '" rel="nofollow">'
        . esc_html($a['text']) . '</a>';
});
