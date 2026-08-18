<?php

/**
 * RSS 订阅扩展
 *
 * 在 WordPress 原生 Feed 之上增加「按分类排除」能力：
 *   - 站点主 Feed（/feed、作者 / 标签 / 搜索 / 日期归档等派生 Feed）过滤掉指定分类的文章
 *   - 可选把子分类一起算进排除范围
 *   - 可选连被排除分类自身的分类 Feed（/category/xxx/feed）也一并置空
 *   - 可选让评论 Feed 同步隐藏这些文章下的评论
 *
 * 过滤走 `pre_get_posts` 的 `tax_query`（而不是 `category__not_in`），
 * 原因是 `category__not_in` 会和分类 Feed 自身的 `cat` 查询在同一层冲突；
 * 追加一条 `NOT IN` 的 tax_query 子句可以与既有条件共存。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 读取后台勾选的排除分类，按需展开子分类。
 *
 * @return int[] 去重后的 term_id 列表；未配置时返回空数组
 */
function kratos_rss_excluded_term_ids()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $raw = kratos_option('g_rss_exclude_cats', array());
    $ids = array();
    foreach ((array) $raw as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    if ($ids && kratos_option('g_rss_exclude_children', true)) {
        foreach ($ids as $id) {
            $children = get_term_children($id, 'category');
            if (!is_wp_error($children) && $children) {
                $ids = array_merge($ids, array_map('intval', $children));
            }
        }
    }

    $cache = array_values(array_unique($ids));
    return $cache;
}

/**
 * 取当前查询命中的分类 term_id（用于判断是不是「被排除分类自己的 Feed」）。
 *
 * 在 pre_get_posts 阶段 queried_object 还没建好，只能读查询变量：
 * `cat` 可能是逗号分隔的多个 ID（含负号形式的排除），`category_name`
 * 可能是 `parent/child` 这样的层级 slug，取最后一段。
 *
 * @param WP_Query $query
 * @return int[]
 */
function kratos_rss_queried_category_ids($query)
{
    $ids = array();

    $cat = $query->get('cat');
    if ($cat !== '' && $cat !== null) {
        foreach (explode(',', (string) $cat) as $piece) {
            $id = (int) trim($piece);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    foreach ((array) $query->get('category__in') as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    $slug = (string) $query->get('category_name');
    if ($slug !== '') {
        $parts = array_filter(explode('/', $slug));
        $leaf  = $parts ? end($parts) : '';
        if ($leaf !== '') {
            $term = get_term_by('slug', $leaf, 'category');
            if ($term && !is_wp_error($term)) {
                $ids[] = (int) $term->term_id;
            }
        }
    }

    return array_values(array_unique($ids));
}

/**
 * 主 Feed 查询过滤。
 *
 * @param WP_Query $query
 * @return void
 */
function kratos_rss_filter_feed_query($query)
{
    if (is_admin() || !$query->is_feed() || !$query->is_main_query()) {
        return;
    }

    $excluded = kratos_rss_excluded_term_ids();
    if (!$excluded) {
        return;
    }

    // 直接访问某个被排除分类的 Feed：按开关决定「置空」还是「放行」。
    $current = kratos_rss_queried_category_ids($query);
    if ($current && array_intersect($current, $excluded)) {
        if (kratos_option('g_rss_block_term_feed', true)) {
            $query->set('post__in', array(0));
        }
        return;
    }

    $tax_query = $query->get('tax_query');
    $tax_query = is_array($tax_query) ? $tax_query : array();
    $tax_query[] = array(
        'taxonomy' => 'category',
        'field'    => 'term_id',
        'terms'    => $excluded,
        'operator' => 'NOT IN',
        // 子分类已在 kratos_rss_excluded_term_ids() 里展开，这里不要再展开一次。
        'include_children' => false,
    );
    $query->set('tax_query', $tax_query);
}
add_action('pre_get_posts', 'kratos_rss_filter_feed_query');

/**
 * 被排除分类对应的 term_taxonomy_id（评论 Feed 的 SQL 子查询要用）。
 *
 * @return int[]
 */
function kratos_rss_excluded_tt_ids()
{
    global $wpdb;

    $ids = kratos_rss_excluded_term_ids();
    if (!$ids) {
        return array();
    }

    $in  = implode(',', array_map('intval', $ids));
    $out = $wpdb->get_col(
        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
         WHERE taxonomy = 'category' AND term_id IN ({$in})"
    );

    return array_map('intval', (array) $out);
}

/**
 * 评论 Feed 过滤：隐藏被排除分类下文章的评论。
 *
 * 评论 Feed 的 SQL 不经过 WP_Query 的 tax_query，只能在 where 上补一条
 * `NOT IN (子查询)`。单篇文章的评论 Feed（`/post/feed`）同样受影响，这与
 * 「该分类不对外输出」的语义一致。
 *
 * @param string $where
 * @return string
 */
function kratos_rss_filter_comment_feed_where($where)
{
    global $wpdb;

    if (!is_comment_feed() || !kratos_option('g_rss_exclude_comments', true)) {
        return $where;
    }

    $tt_ids = kratos_rss_excluded_tt_ids();
    if (!$tt_ids) {
        return $where;
    }

    $in = implode(',', $tt_ids);
    $where .= " AND {$wpdb->comments}.comment_post_ID NOT IN (
        SELECT object_id FROM {$wpdb->term_relationships}
        WHERE term_taxonomy_id IN ({$in})
    )";

    return $where;
}
add_filter('comment_feed_where', 'kratos_rss_filter_comment_feed_where');
