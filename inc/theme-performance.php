<?php

/**
 * 性能优化
 *
 * 「减少请求 / 减少查询 / 减少写库 / 改善 LCP」这一类开关，全部可在主题选项
 * 「基础设置 → 性能优化」里逐项关闭。不含整页缓存、图片转码、CSS/JS 合并压缩。
 *
 * @author Dylan Li (Kratos+)
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** 开关读取（性能项统一前缀 g_perf_）。 */
function kratos_perf_on($key, $default = true)
{
    return (bool) kratos_option($key, $default);
}

/* ============================================================
 *  A1 播放器脚本按需加载
 * ============================================================ */

/** 正文没有播放器短码时把 DPlayer 摘掉，改由短码自己按需入队。 */
function kratos_perf_dequeue_player()
{
    if (is_admin() || !kratos_perf_on('g_perf_asset_ondemand', true)) {
        return;
    }

    if (kratos_perf_needs_player()) {
        return;
    }

    wp_dequeue_script('dplayer');
}
add_action('wp_enqueue_scripts', 'kratos_perf_dequeue_player', 99);

/** 当前请求的正文里是否出现播放器短码 / DPlayer 容器。 */
function kratos_perf_needs_player()
{
    if (!is_singular()) {
        return false;
    }
    $post = get_post();
    if (!$post) {
        return false;
    }
    $c = (string) $post->post_content;
    return has_shortcode($c, 'dplayer')
        || has_shortcode($c, 'video')
        || strpos($c, 'dplayer-') !== false
        || strpos($c, 'new DPlayer') !== false;
}

/* ============================================================
 *  A2 卸载 Gutenberg 前台样式
 * ============================================================ */

/**
 * 卸载区块样式（wp-block-library / classic-theme-styles / global-styles 等）。
 * 当前文章本身用区块编辑器写的（has_blocks）时不卸载。
 */
function kratos_perf_dequeue_block_css()
{
    if (is_admin() || !kratos_perf_on('g_perf_block_css', true)) {
        return;
    }
    if (is_singular()) {
        $post = get_post();
        if ($post && function_exists('has_blocks') && has_blocks($post->post_content)) {
            return;
        }
    }

    foreach (array('wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles') as $handle) {
        wp_dequeue_style($handle);
        wp_deregister_style($handle);
    }
}
add_action('wp_enqueue_scripts', 'kratos_perf_dequeue_block_css', 100);

/** 摘掉 wp_footer 阶段补输出的 global styles 与 SVG 滤镜。 */
function kratos_perf_remove_global_styles()
{
    if (is_admin() || !kratos_perf_on('g_perf_block_css', true)) {
        return;
    }
    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
    remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
    remove_action('wp_footer', 'wp_global_styles_render_svg_filters');
}
add_action('wp', 'kratos_perf_remove_global_styles');

/* ============================================================
 *  A3 移除 wp-embed.js 与 oEmbed 探测标签
 * ============================================================ */

/**
 * 关闭「被别人嵌入」这一侧：wp-embed.js + oEmbed 发现标签。
 * 本站嵌别人的 oEmbed 不受影响。
 */
function kratos_perf_disable_oembed()
{
    if (!kratos_perf_on('g_perf_wp_embed', true)) {
        return;
    }
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    add_action('wp_enqueue_scripts', function () {
        wp_dequeue_script('wp-embed');
        wp_deregister_script('wp-embed');
    }, 100);
    add_action('wp_footer', function () {
        wp_dequeue_script('wp-embed');
    }, 1);
}
add_action('init', 'kratos_perf_disable_oembed');

/* ============================================================
 *  A4 移除 jQuery Migrate
 * ============================================================ */

/** 把 jquery-migrate 从 jquery 的依赖里摘掉。 */
function kratos_perf_remove_jquery_migrate($scripts)
{
    if (is_admin() || !kratos_perf_on('g_perf_jquery_migrate', true)) {
        return;
    }
    if (!empty($scripts->registered['jquery'])) {
        $deps = &$scripts->registered['jquery']->deps;
        $deps = array_diff($deps, array('jquery-migrate'));
    }
}
add_action('wp_default_scripts', 'kratos_perf_remove_jquery_migrate');

/* ============================================================
 *  A5 Heartbeat 节流
 * ============================================================ */

/** 前台卸掉 Heartbeat（挂 wp_enqueue_scripts，init 阶段还没注册）。 */
function kratos_perf_kill_frontend_heartbeat()
{
    if (is_admin() || !kratos_perf_on('g_perf_heartbeat_front', true)) {
        return;
    }
    wp_dequeue_script('heartbeat');
    wp_deregister_script('heartbeat');
}
add_action('wp_enqueue_scripts', 'kratos_perf_kill_frontend_heartbeat', 1);

/** 后台轮询间隔取设定值（15–300 秒），文章编辑页最多 30 秒。 */
function kratos_perf_heartbeat_settings($settings)
{
    $sec = (int) kratos_option('g_perf_heartbeat_interval', 60);
    if ($sec <= 0) {
        return $settings;
    }
    $sec = max(15, min(300, $sec));

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && $screen->base === 'post') {
        $sec = min($sec, 30);
    }

    $settings['interval'] = $sec;
    return $settings;
}
add_filter('heartbeat_settings', 'kratos_perf_heartbeat_settings');

/* ============================================================
 *  A7 资源域 preconnect / dns-prefetch
 * ============================================================ */

/**
 * 输出资源域 hint。域名从各功能的选项里推导，没开启的功能不输出。
 * 前 4 个走 preconnect，其余降级 dns-prefetch。
 */
function kratos_perf_resource_hints($urls, $relation_type)
{
    if (!kratos_perf_on('g_perf_hints', true)) {
        return $urls;
    }

    $hosts = array();

    if (kratos_option('g_cdn', false)) {
        $hosts[] = 'https://cdn.jsdelivr.net';
    }

    // Gravatar 加速域
    $grav = kratos_option('g_replace_gravatar_url_fieldset', array());
    if (!empty($grav['g_replace_gravatar_url'])) {
        $map = array('loli' => 'gravatar.loli.net', 'geekzu' => 'sdn.geekzu.org');
        $srv = isset($grav['g_select_gravatar_server']) ? $grav['g_select_gravatar_server'] : 'geekzu';
        if ($srv === 'other') {
            $custom = isset($grav['g_custom_gravatar_server']) ? trim((string) $grav['g_custom_gravatar_server']) : '';
            if ($custom !== '') {
                $hosts[] = 'https://' . preg_replace('#^https?://#', '', untrailingslashit($custom));
            }
        } elseif (isset($map[$srv])) {
            $hosts[] = 'https://' . $map[$srv];
        }
    }

    // 对象存储 / 图片处理加速域
    $storages = array(
        'g_cos_fieldset'  => array('g_cos', 'g_cos_url'),
        'g_imgx_fieldset' => array('g_imgx', 'g_imgx_url'),
    );
    foreach ($storages as $set => $keys) {
        list($enable_key, $url_key) = $keys;
        $fs = kratos_option($set, array());
        if (!is_array($fs) || empty($fs[$enable_key]) || empty($fs[$url_key])) {
            continue;
        }
        $hosts[] = 'https://' . preg_replace('#^https?://#', '', untrailingslashit(trim((string) $fs[$url_key])));
    }

    // 手填的额外域（每行一个）
    $extra = (string) kratos_option('g_perf_hints_hosts', '');
    foreach (preg_split('/[\r\n,]+/', $extra) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $hosts[] = preg_match('#^https?://#', $line) ? untrailingslashit($line)
            : 'https://' . untrailingslashit($line);
    }

    $hosts = array_values(array_unique(array_filter($hosts)));
    if (empty($hosts)) {
        return $urls;
    }

    if ($relation_type === 'preconnect') {
        return array_merge($urls, array_slice($hosts, 0, 4));
    }
    if ($relation_type === 'dns-prefetch') {
        return array_merge($urls, array_slice($hosts, 4));
    }
    return $urls;
}
add_filter('wp_resource_hints', 'kratos_perf_resource_hints', 10, 2);

/* ============================================================
 *  A8 LCP 图片优化
 * ============================================================ */

/** 本次请求已出现的图片计数。 */
function kratos_perf_img_seen($increment = true)
{
    static $n = 0;
    if ($increment) {
        $n++;
    }
    return $n;
}

/** 首图判定：只在前台、且尚未出现过图片时成立。 */
function kratos_perf_is_first_img()
{
    return !is_admin() && kratos_perf_on('g_perf_lcp', true) && kratos_perf_img_seen() === 1;
}

/** wp_get_attachment_image() 的属性：统一 decoding=async，首图 eager + high。 */
function kratos_perf_attachment_image_attributes($attr)
{
    if (is_admin() || !kratos_perf_on('g_perf_lcp', true)) {
        return $attr;
    }
    if (empty($attr['decoding'])) {
        $attr['decoding'] = 'async';
    }
    if (kratos_perf_is_first_img()) {
        $attr['loading'] = 'eager';
        $attr['fetchpriority'] = 'high';
    }
    return $attr;
}
add_filter('wp_get_attachment_image_attributes', 'kratos_perf_attachment_image_attributes', 20);

/** 正文里的 <img>（WP 6.0+ 的 wp_content_img_tag）同样处理。 */
function kratos_perf_content_img_tag($filtered_image)
{
    if (is_admin() || !kratos_perf_on('g_perf_lcp', true)) {
        return $filtered_image;
    }
    return kratos_perf_mark_img($filtered_image);
}
add_filter('wp_content_img_tag', 'kratos_perf_content_img_tag', 20);

/**
 * 给一段 <img ...> HTML 补 decoding/loading/fetchpriority。
 * 第一张图 → eager + high，其余保持原样。主题里手写的 <img> 都要过这一层，
 * 否则不进首图计数、也拿不到属性。
 *
 * @param string $html 单个 <img> 标签
 * @return string
 */
function kratos_perf_mark_img($html)
{
    if (!is_string($html) || strpos($html, '<img') === false) {
        return $html;
    }
    if (is_admin() || !kratos_perf_on('g_perf_lcp', true)) {
        return $html;
    }

    $first = kratos_perf_is_first_img();

    if (strpos($html, 'decoding=') === false) {
        $html = preg_replace('/<img\b/', '<img decoding="async"', $html, 1);
    }
    if ($first) {
        if (preg_match('/\bloading\s*=\s*["\']lazy["\']/i', $html)) {
            $html = preg_replace('/\bloading\s*=\s*["\']lazy["\']/i', 'loading="eager"', $html, 1);
        } else {
            $html = preg_replace('/<img\b/', '<img loading="eager"', $html, 1);
        }
        if (stripos($html, 'fetchpriority=') === false) {
            $html = preg_replace('/<img\b/', '<img fetchpriority="high"', $html, 1);
        }
    }
    return $html;
}

/* ============================================================
 *  B2 次级查询瘦身
 * ============================================================ */

/**
 * 给主题自己的**非分页**次级查询补上省查询的参数。
 *
 * - no_found_rows：不算总数
 * - update_post_term_cache：模板不输出分类/标签时才关（否则反而更慢）
 * - update_post_meta_cache：模板不读任何 post meta 时才关（缩略图算读 meta）
 * - lazy_load_term_meta：term meta 一律不预热
 *
 * 分页查询不能用（要靠 found_rows 算 max_num_pages）。
 *
 * @param array $args WP_Query 参数
 * @param array $opts no_terms / no_meta：确认模板不用 term / meta 时置 true
 * @return array
 */
function kratos_lean_query_args(array $args, array $opts = array())
{
    if (!kratos_perf_on('g_perf_query_lean', true)) {
        return $args;
    }

    $opts = array_merge(array('no_terms' => false, 'no_meta' => false), $opts);

    if (!isset($args['no_found_rows'])) {
        $args['no_found_rows'] = true;
    }
    if (!isset($args['lazy_load_term_meta'])) {
        $args['lazy_load_term_meta'] = false;
    }
    if ($opts['no_terms'] && !isset($args['update_post_term_cache'])) {
        $args['update_post_term_cache'] = false;
    }
    if ($opts['no_meta'] && !isset($args['update_post_meta_cache'])) {
        $args['update_post_meta_cache'] = false;
    }
    return $args;
}

/* ============================================================
 *  评论提交提速：发信与缓存失效挪到响应之后
 * ============================================================ */

/**
 * 评论提交时，把发信（核心通知 + 主题回复通知）与各处缓存失效从钩子上摘下来
 * 入队，等响应 flush 给浏览器之后再按原参数调用。
 */

/** 延后执行队列。 */
function &kratos_perf_deferred_queue()
{
    static $queue = array();
    return $queue;
}

/** 把一个回调压入队列，并确保收尾只注册一次。 */
function kratos_perf_defer($callback, array $args = array())
{
    $queue   = &kratos_perf_deferred_queue();
    $queue[] = array('cb' => $callback, 'args' => $args);

    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    register_shutdown_function('kratos_perf_run_deferred');
}

/** 先把响应彻底交出去，再执行队列里的任务。 */
function kratos_perf_run_deferred()
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (!headers_sent()) {
            header('Connection: close');
        }
        while (ob_get_level()) {
            ob_end_flush();
        }
        flush();
    }
    ignore_user_abort(true);
    @set_time_limit(120);

    $queue = &kratos_perf_deferred_queue();
    foreach ($queue as $item) {
        if (!is_callable($item['cb'])) {
            continue;
        }
        try {
            call_user_func_array($item['cb'], $item['args']);
        } catch (Throwable $e) {
            error_log('[kratos_perf] deferred task failed: ' . $e->getMessage());
        }
    }
    $queue = array();
}

/**
 * 需要延后的回调表：hook => [[callback, priority, accepted_args], ...]
 * 只含主题自己与 WP 核心的命名函数，插件挂的回调一律不碰。
 */
function kratos_perf_deferrable_map()
{
    return array(
        'wp_insert_comment' => array(
            array('kratos_rank_invalidate_cache', 10, 1),
            array('kratos_top_commenters_flush_cache', 10, 1),
            array('kratos_friend_recent_flush_cache', 10, 1),
            array('kratos_home_flush_cache', 10, 1),
        ),
        'comment_post' => array(
            // 发信
            array('wp_new_comment_notify_moderator', 10, 1),
            array('wp_new_comment_notify_postauthor', 10, 1),
            array('comment_notify', 10, 3),
            // 缓存失效
            array('kratos_comment_geo_flush_cache', 10, 1),
            array('kratos_dash_flush_cache', 10, 1),
        ),
    );
}

/**
 * 摘除 + 入队。挂在各 hook 的优先级 1，即所有目标回调之前。
 * 用 has_action() 回报的真实优先级去摘（别人可能以别的优先级重挂过）。
 *
 * @param mixed ...$args 原钩子参数，原样转交给延后执行的回调
 */
function kratos_perf_defer_comment_hooks(...$args)
{
    if (!kratos_perf_on('g_perf_comment_async', true)) {
        return $args[0];
    }

    $hook = current_filter();
    $map  = kratos_perf_deferrable_map();
    if (empty($map[$hook])) {
        return $args[0];
    }

    foreach ($map[$hook] as $entry) {
        list($cb, $priority, $accepted) = $entry;
        $found = has_action($hook, $cb);
        if ($found === false) {
            continue;
        }
        remove_action($hook, $cb, is_int($found) ? $found : $priority);
        kratos_perf_defer($cb, array_slice($args, 0, $accepted));
    }

    return $args[0];
}
add_action('wp_insert_comment', 'kratos_perf_defer_comment_hooks', 1, 2);
add_action('comment_post', 'kratos_perf_defer_comment_hooks', 1, 3);

/**
 * 后台「通过审核」的通知邮件同样延后。只摘命名的 comment_approved()，
 * theme-smtp.php 挂在同一钩子上的匿名函数无法 remove_action。
 */
function kratos_perf_defer_comment_approved($comment)
{
    if (!kratos_perf_on('g_perf_comment_async', true)) {
        return;
    }
    if (has_action('comment_unapproved_to_approved', 'comment_approved')) {
        remove_action('comment_unapproved_to_approved', 'comment_approved', 10);
        kratos_perf_defer('comment_approved', array($comment));
    }
}
add_action('comment_unapproved_to_approved', 'kratos_perf_defer_comment_approved', 1, 1);

/* ============================================================
 *  B6 数据库瘦身
 * ============================================================ */

/**
 * object_id 一定是 posts.ID 的分类法名单，拼成可嵌进 SQL 的 IN 列表。
 * 判定口径是 object_type **全部**都是 post type —— 有交集就放行会把友链分类
 * （object_id 是 wp_links.link_id）、用户标签当成孤立行删掉。
 *
 * @return string 形如 "'category','post_tag'"，无可用分类法时返回空串
 */
function kratos_perf_post_taxonomies_in()
{
    $post_types = get_post_types(array(), 'names');
    $names      = array();
    foreach (get_taxonomies(array(), 'objects') as $tax) {
        $types = (array) $tax->object_type;
        if (!empty($types) && !array_diff($types, $post_types)) {
            $names[] = "'" . esc_sql($tax->name) . "'";
        }
    }
    return implode(',', $names);
}

/**
 * 过期 transient 的统计 SQL。
 * 单站点两类都在 options；多站点的 site transient 在 sitemeta，故拼成两个子查询相加。
 */
function kratos_perf_expired_transients_count_sql()
{
    global $wpdb;

    $local = "SELECT COUNT(*) FROM {$wpdb->options}
              WHERE option_name LIKE '\_transient\_timeout\_%'
                AND option_value + 0 < UNIX_TIMESTAMP()";

    if (!is_multisite()) {
        return "SELECT COUNT(*) FROM {$wpdb->options}
                WHERE (option_name LIKE '\_transient\_timeout\_%' OR option_name LIKE '\_site\_transient\_timeout\_%')
                  AND option_value + 0 < UNIX_TIMESTAMP()";
    }

    $network = (int) get_current_network_id();
    return "SELECT ($local) + (
                SELECT COUNT(*) FROM {$wpdb->sitemeta}
                WHERE site_id = {$network}
                  AND meta_key LIKE '\_site\_transient\_timeout\_%'
                  AND meta_value + 0 < UNIX_TIMESTAMP()
            )";
}

/**
 * 可清理项定义：label + desc + 统计 SQL + 删除 SQL 或回调。
 *
 * 'sweeps' => true 标记「文章类」项目：删完 posts 行会连带跑孤立字段 /
 * 孤立分类关联的全表收尾（见 kratos_perf_db_clean_ajax()）。
 * 'detail' => 'callback:fn' 提供面板上的命中明细文案。
 */
function kratos_perf_db_items()
{
    global $wpdb;

    $tax_in = kratos_perf_post_taxonomies_in();

    return array(
        'revisions' => array(
            'label'  => __('文章修订版本', 'kratos'),
            'desc'   => __('post_type = revision 的历史版本记录', 'kratos'),
            'sweeps' => true,
            'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'",
            'clean' => "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'",
        ),
        'auto_drafts' => array(
            'label'  => __('自动草稿', 'kratos'),
            'desc'   => __('点了「新建」但从未保存的空文章', 'kratos'),
            'sweeps' => true,
            'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'",
            'clean' => "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'",
        ),
        'trash_posts' => array(
            'label'  => __('回收站中的文章', 'kratos'),
            'desc'   => __('已移入回收站的文章与页面（附件与自定义类型见下一项）', 'kratos'),
            'sweeps' => true,
            'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_type IN ('post', 'page')",
            'clean' => "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_type IN ('post', 'page')",
        ),
        // 排除 attachment（磁盘文件会留成孤儿）、revision（上面单独一项）、nav_menu_item
        'trash_others' => array(
            'label'  => __('回收站中的其它内容类型', 'kratos'),
            'desc'   => __('说说等自定义类型在回收站里的内容（不含附件与菜单项），插件的业务类型也算在内', 'kratos'),
            'sweeps' => true,
            'detail' => 'callback:kratos_perf_trash_others_detail',
            'count' => "SELECT COUNT(*) FROM {$wpdb->posts}
                        WHERE post_status = 'trash'
                          AND post_type NOT IN ('post', 'page', 'attachment', 'revision', 'nav_menu_item')",
            'clean' => "DELETE FROM {$wpdb->posts}
                        WHERE post_status = 'trash'
                          AND post_type NOT IN ('post', 'page', 'attachment', 'revision', 'nav_menu_item')",
        ),
        'orphan_postmeta' => array(
            'label' => __('孤立的文章自定义字段', 'kratos'),
            'desc'  => __('所属文章已被删除、meta 仍残留的记录', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL",
            'clean' => "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL",
        ),
        // $tax_in 为空时退化成 SELECT 0，避免拼出 `IN ()`
        'orphan_term_rel' => array(
            'label' => __('孤立的分类关联', 'kratos'),
            'desc'  => __('所属文章已被删除、分类/标签关联仍残留的记录（不含友链分类）', 'kratos'),
            'count' => $tax_in === '' ? 'SELECT 0' : "SELECT COUNT(*)
                        FROM {$wpdb->term_relationships} tr
                        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                        LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                        WHERE p.ID IS NULL AND tt.taxonomy IN ($tax_in)",
            'clean' => 'callback:kratos_perf_clean_orphan_term_relationships',
        ),
        'orphan_commentmeta' => array(
            'label' => __('孤立的评论自定义字段', 'kratos'),
            'desc'  => __('所属评论已被删除、meta 仍残留的记录', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
            'clean' => "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
        ),
        'orphan_comments' => array(
            'label' => __('孤立的评论', 'kratos'),
            'desc'  => __('所属文章已被删除、评论仍残留的记录', 'kratos'),
            // comment_post_ID = 0 不算孤立：那是「不挂在文章上」的合法插件数据
            'count' => "SELECT COUNT(*) FROM {$wpdb->comments} c LEFT JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID WHERE p.ID IS NULL AND c.comment_post_ID <> 0",
            'clean' => 'callback:kratos_perf_clean_orphan_comments',
        ),
        'spam_comments' => array(
            'label' => __('垃圾评论', 'kratos'),
            'desc'  => __('被标记为 spam 的评论', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'",
            'clean' => "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'",
        ),
        'trash_comments' => array(
            'label' => __('回收站中的评论', 'kratos'),
            'desc'  => __('已移入回收站的评论', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'",
            'clean' => "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'",
        ),
        'pingbacks' => array(
            'label' => __('Pingback / Trackback', 'kratos'),
            'desc'  => __('主题已禁用该功能，历史记录可清', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type IN ('pingback', 'trackback')",
            'clean' => "DELETE FROM {$wpdb->comments} WHERE comment_type IN ('pingback', 'trackback')",
        ),
        'expired_transients' => array(
            'label' => __('过期的临时缓存', 'kratos'),
            'desc'  => __('已过期但仍留在 options 表里的 transient（按缓存项计数）', 'kratos'),
            'count' => kratos_perf_expired_transients_count_sql(),
            'clean' => 'callback:kratos_perf_clean_expired_transients',
        ),
    );
}

const KRATOS_PERF_DB_STATS_CACHE = 'kratos_perf_db_stats';
const KRATOS_PERF_DB_LOCK        = 'kratos_perf_db_cleaning';

/** 单项条数超过这个量级就在面板上出黄色警示（清理可能是分钟级）。 */
const KRATOS_PERF_DB_SLOW_ROWS = 50000;

/** 清理并发锁的存活时间，只作 fatal / 超时后的兜底（正常路径跑完即释放）。 */
const KRATOS_PERF_DB_LOCK_TTL = 10 * MINUTE_IN_SECONDS;

/**
 * 「回收站中的其它内容类型」命中了哪些 post_type，各多少条。
 * 命中范围里可能有插件的业务对象，故把明细摆到面板上。
 *
 * @return string 形如「说说（shuoshuo）× 12」，没有命中返回空串
 */
function kratos_perf_trash_others_detail()
{
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts}
         WHERE post_status = 'trash'
           AND post_type NOT IN ('post', 'page', 'attachment', 'revision', 'nav_menu_item')
         GROUP BY post_type ORDER BY n DESC"
    );
    if (empty($rows)) {
        return '';
    }

    $parts = array();
    foreach ($rows as $row) {
        $obj   = get_post_type_object($row->post_type);
        $name  = ($obj && !empty($obj->labels->singular_name)) ? $obj->labels->singular_name : $row->post_type;
        // 带上 slug：插件的 label 常有重名
        $parts[] = sprintf('%s（%s）× %s', $name, $row->post_type, number_format_i18n((int) $row->n));
    }
    return implode('、', $parts);
}

/**
 * 统计条数 + 明细文案，两者同缓存 5 分钟，清理后主动失效。
 *
 * @param bool $force 跳过缓存，重新统计
 * @return array{stats: array<string,int>, details: array<string,string>}
 */
function kratos_perf_db_state($force = false)
{
    $items = kratos_perf_db_items();

    if (!$force) {
        $cached = get_transient(KRATOS_PERF_DB_STATS_CACHE);
        // 清理项增减后旧缓存的键会对不上，键不一致就重算
        if (is_array($cached) && isset($cached['stats'], $cached['details'])
            && array_keys($cached['stats']) === array_keys($items)) {
            return $cached;
        }
    }

    global $wpdb;
    $state = array('stats' => array(), 'details' => array());
    foreach ($items as $key => $item) {
        $state['stats'][$key] = (int) $wpdb->get_var($item['count']);

        if ($state['stats'][$key] > 0 && !empty($item['detail']) && strpos($item['detail'], 'callback:') === 0) {
            $state['details'][$key] = (string) call_user_func(substr($item['detail'], 9));
        }
    }
    set_transient(KRATOS_PERF_DB_STATS_CACHE, $state, 5 * MINUTE_IN_SECONDS);
    return $state;
}

/** 使统计缓存失效。 */
function kratos_perf_db_stats_flush()
{
    delete_transient(KRATOS_PERF_DB_STATS_CACHE);
}

/** 过期 transient 超过这个量就改走批量 SQL，以下则逐个删。 */
const KRATOS_PERF_TRANSIENT_BULK_ROWS = 2000;

/** 失效 options / site-options 缓存组（批量 SQL 绕过了 API，需手动清）。 */
function kratos_perf_flush_option_caches()
{
    if (!function_exists('wp_using_ext_object_cache') || !wp_using_ext_object_cache()) {
        return;
    }
    if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group') && function_exists('wp_cache_flush_group')
        && wp_cache_flush_group('options') && wp_cache_flush_group('site-options')) {
        return;
    }
    wp_cache_flush();
}

/**
 * 批量删除过期 transient：timeout 行与数据行一条 SQL 带走。
 * 从 timeout 行一侧 LEFT JOIN 数据行，孤立的 timeout 行也能删掉。
 * SUBSTRING 起点是前缀长度 +1：`_transient_timeout_` 20，`_site_transient_timeout_` 25。
 *
 * @return bool 有任何一条 SQL 失败返回 false
 */
function kratos_perf_delete_expired_transients_bulk()
{
    global $wpdb;

    $ok = true;

    // 普通 transient（单站 / 多站都在 options）
    $ok = $ok && false !== $wpdb->query(
        "DELETE t, d FROM {$wpdb->options} t
         LEFT JOIN {$wpdb->options} d ON d.option_name = CONCAT('_transient_', SUBSTRING(t.option_name, 20))
         WHERE t.option_name LIKE '\_transient\_timeout\_%'
           AND t.option_value + 0 < UNIX_TIMESTAMP()"
    );

    if (is_multisite()) {
        // 多站点的 site transient 在 sitemeta，按 site_id 隔开网络
        $ok = $ok && false !== $wpdb->query($wpdb->prepare(
            "DELETE t, d FROM {$wpdb->sitemeta} t
             LEFT JOIN {$wpdb->sitemeta} d
               ON d.site_id = t.site_id AND d.meta_key = CONCAT('_site_transient_', SUBSTRING(t.meta_key, 25))
             WHERE t.site_id = %d
               AND t.meta_key LIKE '\_site\_transient\_timeout\_%'
               AND t.meta_value + 0 < UNIX_TIMESTAMP()",
            get_current_network_id()
        ));
    } else {
        // 单站点下 site transient 也落在 options
        $ok = $ok && false !== $wpdb->query(
            "DELETE t, d FROM {$wpdb->options} t
             LEFT JOIN {$wpdb->options} d ON d.option_name = CONCAT('_site_transient_', SUBSTRING(t.option_name, 25))
             WHERE t.option_name LIKE '\_site\_transient\_timeout\_%'
               AND t.option_value + 0 < UNIX_TIMESTAMP()"
        );
    }

    if ($ok) {
        kratos_perf_flush_option_caches();
    }
    return $ok;
}

/**
 * 清理过期 transient：timeout 行与数据行一起删，site transient 同样纳入。
 * 按量分流，见 KRATOS_PERF_TRANSIENT_BULK_ROWS。
 *
 * @return int|false 清掉的缓存项数，SQL 失败返回 false
 */
function kratos_perf_clean_expired_transients()
{
    global $wpdb;

    if (is_multisite()) {
        // 多站点下 site transient 在 wp_sitemeta，得分两处取
        $names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_timeout\_%'
               AND option_value + 0 < UNIX_TIMESTAMP()"
        );
        $names = array_merge($names, (array) $wpdb->get_col($wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->sitemeta}
             WHERE site_id = %d
               AND meta_key LIKE '\_site\_transient\_timeout\_%'
               AND meta_value + 0 < UNIX_TIMESTAMP()",
            get_current_network_id()
        )));
    } else {
        $names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE (option_name LIKE '\_transient\_timeout\_%' OR option_name LIKE '\_site\_transient\_timeout\_%')
               AND option_value + 0 < UNIX_TIMESTAMP()"
        );
    }
    $total = count($names);
    if ($total === 0) {
        return 0;
    }

    // 计数用快照条数：批量 SQL 的 affected_rows 含 timeout + 数据两行，口径对不上 count SQL
    if ($total > KRATOS_PERF_TRANSIENT_BULK_ROWS) {
        return kratos_perf_delete_expired_transients_bulk() ? $total : false;
    }

    // 有外置对象缓存时 delete_transient() 只动缓存、恒返回 true，条数减不掉，
    // 必须落到表上
    $ext = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

    // 计数口径 = 缓存项数，与 count SQL（数 timeout 行）一致；实删行数是它的两倍
    $n = 0;
    foreach ($names as $timeout_name) {
        $is_site = strpos($timeout_name, '_site_transient_timeout_') === 0;
        $prefix  = $is_site ? '_site_transient_' : '_transient_';
        $key     = substr($timeout_name, strlen($prefix . 'timeout_'));

        // 无外置缓存时优先走 API，让内存缓存同步失效
        $deleted = $ext ? false : ($is_site ? delete_site_transient($key) : delete_transient($key));
        if (!$deleted) {
            // 多站点的 site transient 在 sitemeta，走 API 让它管 site-options 缓存
            if ($is_site && is_multisite()) {
                $ok1 = delete_site_option($timeout_name);
                $ok2 = delete_site_option($prefix . $key);
            } else {
                // 其余都在 options 表（单站点下 site option 就是普通 option，
                // 缓存组同为 'options'）
                $data_name = $prefix . $key;
                $ok1 = $wpdb->delete($wpdb->options, array('option_name' => $timeout_name));
                $ok2 = $wpdb->delete($wpdb->options, array('option_name' => $data_name));
                if ($ok1 === false || $ok2 === false) {
                    return false;
                }
                wp_cache_delete($timeout_name, 'options');
                wp_cache_delete($data_name, 'options');
            }
            // 两条都没删到 = 已被别处删掉，本次没清到东西，不计数
            if (!$ok1 && !$ok2) {
                continue;
            }
        }
        $n++;
    }

    // 循环里删的是单键，autoload 行的 alloptions 整块缓存在这里统一重读一次
    if ($n > 0 && $ext) {
        wp_load_alloptions(true);
    }

    return $n;
}

/**
 * 清理孤立评论（comment_post_ID 指向已不存在的文章）连带其 commentmeta。
 * `comment_post_ID <> 0` 与 count SQL 三处口径必须一致。
 *
 * @return int|false 删掉的评论数，SQL 失败返回 false
 */
function kratos_perf_clean_orphan_comments()
{
    global $wpdb;

    // 先删 meta：评论行还在时才 JOIN 得到
    $meta = $wpdb->query(
        "DELETE cm FROM {$wpdb->commentmeta} cm
         INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
         LEFT JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
         WHERE p.ID IS NULL AND c.comment_post_ID <> 0"
    );

    $deleted = $wpdb->query(
        "DELETE c FROM {$wpdb->comments} c
         LEFT JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
         WHERE p.ID IS NULL AND c.comment_post_ID <> 0"
    );

    if ($meta === false || $deleted === false) {
        return false;
    }
    return (int) $deleted;
}

/**
 * 清理结束后统一失效缓存（直删 SQL 绕过 WP API，对象缓存不会自动失效）。
 * 优先按分组清，分组不支持或有 drop-in 是 no-op 时退回全量 flush。
 */
function kratos_perf_db_flush_caches()
{
    if (!function_exists('wp_using_ext_object_cache') || !wp_using_ext_object_cache()) {
        return;
    }

    // 'counts' 是 wp_count_posts() / wp_count_comments() 的结果缓存（后台列表页
    // 顶部的「回收站 (N)」）；'options' / 'site-options' 兜过期 transient 的批量路径
    $groups = array(
        'posts', 'post_meta', 'comment', 'comment_meta', 'terms', 'term_meta', 'bookmark',
        'counts', 'options', 'site-options',
    );

    // term 关系缓存的组名是「{分类法}_relationships」
    foreach (get_taxonomies(array(), 'names') as $taxonomy) {
        $groups[] = $taxonomy . '_relationships';
    }

    if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group') && function_exists('wp_cache_flush_group')) {
        $ok = true;
        foreach ($groups as $group) {
            if (!wp_cache_flush_group($group)) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return;
        }
    }

    wp_cache_flush();
}

/**
 * 清理孤立的 term_relationships（object_id 指向已不存在的文章），并重算受影响
 * 的分类计数。删除范围由 kratos_perf_post_taxonomies_in() 收窄，否则会清掉友链分类。
 *
 * @return int|false 删掉的关联数，SQL 失败返回 false
 */
function kratos_perf_clean_orphan_term_relationships()
{
    global $wpdb;

    $in = kratos_perf_post_taxonomies_in();
    if ($in === '') {
        return 0;
    }

    // 先记下受影响的 term_taxonomy_id，删完后重算 count
    $rows = $wpdb->get_results(
        "SELECT DISTINCT tr.term_taxonomy_id, tt.taxonomy
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
         WHERE p.ID IS NULL AND tt.taxonomy IN ($in)"
    );

    $deleted = $wpdb->query(
        "DELETE tr FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
         WHERE p.ID IS NULL AND tt.taxonomy IN ($in)"
    );
    if ($deleted === false) {
        return false;
    }
    $deleted = (int) $deleted;

    if ($deleted > 0 && !empty($rows)) {
        $by_tax = array();
        foreach ($rows as $row) {
            $by_tax[$row->taxonomy][] = (int) $row->term_taxonomy_id;
        }
        foreach ($by_tax as $taxonomy => $ids) {
            wp_update_term_count_now($ids, $taxonomy);
        }
    }

    return $deleted;
}

/**
 * AJAX 处理：执行清理并回传「本次删了多少」+「全部项目的最新条数」。
 * what = 单项 key / 'all' / 'refresh'（只重新统计，不删东西）。
 */
function kratos_perf_db_clean_ajax()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'kratos')), 403);
    }
    check_ajax_referer('kratos_perf_db_clean', 'nonce');

    // 一轮全清可能是分钟级，默认 max_execution_time 兜不住。
    // 时限与并发锁同寿：worker 被干掉的时刻和锁过期的时刻一致，不会出现
    // 「锁没了任务还在跑」或「任务卡死锁一直不放」的错位。
    ignore_user_abort(true);
    @set_time_limit(KRATOS_PERF_DB_LOCK_TTL);

    global $wpdb;
    $what  = isset($_POST['what']) ? sanitize_key(wp_unslash($_POST['what'])) : '';
    $items = kratos_perf_db_items();

    if ($what === 'refresh') {
        kratos_perf_db_stats_flush();
        kratos_perf_db_send_state(0, __('条数已刷新。', 'kratos'));
    }

    $todo = ($what === 'all') ? array_keys($items) : (isset($items[$what]) ? array($what) : array());

    if (empty($todo)) {
        wp_send_json_error(array('message' => __('清理项不存在。', 'kratos')), 400);
    }

    // 并发锁：避免两个标签页同时把整轮跑两遍
    if (get_transient(KRATOS_PERF_DB_LOCK)) {
        wp_send_json_error(array('message' => __('已有清理任务在执行，请稍候重试。', 'kratos')), 409);
    }
    set_transient(KRATOS_PERF_DB_LOCK, 1, KRATOS_PERF_DB_LOCK_TTL);

    $post_keys    = array_intersect($todo, array('revisions', 'auto_drafts', 'trash_posts', 'trash_others'));
    $comment_keys = array_intersect($todo, array('spam_comments', 'trash_comments', 'pingbacks'));

    // 已批准的 pingback 计入 posts.comment_count，删完要重算，故先取文章 id
    //（spam / trash 不计入 comment_count，无需处理）
    $recount_posts = array();
    if (in_array('pingbacks', $todo, true)) {
        $recount_posts = array_map('intval', (array) $wpdb->get_col(
            "SELECT DISTINCT comment_post_ID FROM {$wpdb->comments}
             WHERE comment_type IN ('pingback', 'trackback') AND comment_approved = '1'"
        ));
    }

    // 回调与裸 SQL 同一约定：false = 失败，其余转 int
    $done   = array();
    $errors = array();
    foreach ($todo as $key) {
        $sql = $items[$key]['clean'];

        $res = (strpos($sql, 'callback:') === 0)
            ? call_user_func(substr($sql, 9))
            : $wpdb->query($sql);

        if ($res === false) {
            $errors[$key] = $wpdb->last_error;
            $done[$key]   = 0;
            continue;
        }
        $done[$key] = (int) $res;
    }

    // 收尾：删完 posts / comments 行后处理父表计数与孤立 meta，行数计入总数。
    // 只在本轮真的删掉了行时才跑，避免白扫全表。
    $extra     = 0;
    $post_hits = array_sum(array_intersect_key($done, array_flip($post_keys)));
    $cmt_hits  = array_sum(array_intersect_key($done, array_flip($comment_keys)));

    if ($post_hits > 0) {
        // 先清「父文章已删」的 revision，再扫孤立 postmeta，否则这批 revision 的
        // meta 扫不到会残留到下一轮
        $n = $wpdb->query(
            "DELETE r FROM {$wpdb->posts} r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_parent
             WHERE r.post_type = 'revision' AND r.post_parent <> 0 AND p.ID IS NULL"
        );
        $extra += $n === false ? 0 : (int) $n;

        $n = $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
        $extra += $n === false ? 0 : (int) $n;

        $rel = kratos_perf_clean_orphan_term_relationships();
        $extra += $rel === false ? 0 : (int) $rel;

        // 文章的评论不在这里顺手删，交给独立的「孤立的评论」项显式确认
    }
    if ($cmt_hits > 0) {
        $n = $wpdb->query("DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
        $extra += $n === false ? 0 : (int) $n;
    }

    // 派生数据修正要在 flush 之前跑（它读的是库里的新数字）
    foreach ($recount_posts as $id) {
        if (get_post($id)) {
            wp_update_comment_count_now($id);
        }
    }

    kratos_perf_db_flush_caches();

    delete_transient(KRATOS_PERF_DB_LOCK);
    kratos_perf_db_stats_flush();

    // 全失败直接报错，部分失败在提示里带上项目名
    if (!empty($errors) && count($errors) === count($todo)) {
        wp_send_json_error(array('message' => sprintf(
            __('清理失败：%s', 'kratos'),
            implode('; ', array_map(function ($key, $msg) use ($items) {
                return $items[$key]['label'] . '（' . ($msg !== '' ? $msg : __('未知数据库错误', 'kratos')) . '）';
            }, array_keys($errors), $errors))
        )), 500);
    }

    $cleaned = array_sum($done) + $extra;
    $message = sprintf(__('已清理 %s 条记录。', 'kratos'), number_format_i18n($cleaned));
    if (!empty($errors)) {
        $labels = array();
        foreach (array_keys($errors) as $key) {
            $labels[] = $items[$key]['label'];
        }
        $message .= ' ' . sprintf(__('以下项目失败：%s', 'kratos'), implode('、', $labels));
    }

    kratos_perf_db_send_state($cleaned, $message);
}
add_action('wp_ajax_kratos_perf_db_clean', 'kratos_perf_db_clean_ajax');

/**
 * 回传面板的最新状态（条数 / 明细 / 按钮文案 / 警示条）并结束请求。
 * 清理与「重新统计」两条路径共用，JS 只需处理一种响应结构。
 */
function kratos_perf_db_send_state($cleaned, $message)
{
    $state     = kratos_perf_db_state(true);
    $stats     = $state['stats'];
    $formatted = array();
    foreach ($stats as $key => $n) {
        $formatted[$key] = number_format_i18n($n);
    }
    $total = array_sum($stats);

    $items = kratos_perf_db_items();
    $slow  = array();
    foreach ($stats as $key => $n) {
        if ($n >= KRATOS_PERF_DB_SLOW_ROWS && isset($items[$key])) {
            $slow[] = $items[$key]['label'];
        }
    }

    wp_send_json_success(array(
        'cleaned'         => (int) $cleaned,
        'slow'            => empty($slow) ? '' : kratos_perf_db_slow_text($slow),
        'message'         => $message,
        'stats'           => $stats,
        'stats_formatted' => $formatted,
        'details'         => $state['details'],
        'total'           => $total,
        'total_formatted' => number_format_i18n($total),
        'all_label'       => sprintf(__('一键清理全部（%s 条）', 'kratos'), number_format_i18n($total)),
    ));
}

/**
 * 大数据量警示文案，首屏渲染与 AJAX 更新共用。
 *
 * @param string[] $labels 超过阈值的项目名
 */
function kratos_perf_db_slow_text($labels)
{
    return sprintf(
        __('%2$s 已超过 %1$s 条，清理可能耗时数分钟，请勿关闭页面。若提示失败，先点「重新统计」看条数是否已下降，再决定是否重试。', 'kratos'),
        number_format_i18n(KRATOS_PERF_DB_SLOW_ROWS),
        implode('、', $labels)
    );
}

/**
 * 主题选项里的「数据库瘦身」面板（callback 字段）。
 * 清理走 AJAX 原地更新条数与按钮，JS 内联，依赖 jQuery 与 ajaxurl。
 */
function kratos_perf_render_db_panel()
{
    $items   = kratos_perf_db_items();
    $state   = kratos_perf_db_state();
    $stats   = $state['stats'];
    $details = $state['details'];
    $total   = array_sum($stats);

    echo '<div class="kratos-perf-db" data-nonce="' . esc_attr(wp_create_nonce('kratos_perf_db_clean')) . '">';

    echo '<div class="kratos-perf-db-notice" style="display:none;margin-bottom:12px;"></div>';

    echo '<p style="margin:0 0 10px;color:#666;">'
        . esc_html__('清理只删除下表所列的冗余记录，不碰已发布内容。删除不可撤销，建议先备份数据库。', 'kratos')
        . '</p>';

    // 大数据量警示
    $slow = array();
    foreach ($stats as $key => $n) {
        if ($n >= KRATOS_PERF_DB_SLOW_ROWS) {
            $slow[] = $items[$key]['label'];
        }
    }
    echo '<div class="kratos-perf-db-slow" style="'
        . (empty($slow) ? 'display:none;' : '')
        . 'margin:0 0 12px;padding:10px 12px;border-left:4px solid #d97706;background:#fffbeb;color:#92400e;font-size:13px;">'
        . esc_html(empty($slow) ? '' : kratos_perf_db_slow_text($slow))
        . '</div>';

    echo '<table class="widefat striped" style="max-width:720px;"><thead><tr>'
        . '<th>' . esc_html__('项目', 'kratos') . '</th>'
        . '<th style="width:90px;">' . esc_html__('条数', 'kratos') . '</th>'
        . '<th style="width:110px;">' . esc_html__('操作', 'kratos') . '</th>'
        . '</tr></thead><tbody>';

    foreach ($items as $key => $item) {
        $n      = isset($stats[$key]) ? $stats[$key] : 0;
        $detail = isset($details[$key]) ? $details[$key] : '';
        // data-sweeps 挂在 <tr> 上：按钮会被 applyState() 重建，行不会
        echo '<tr data-key="' . esc_attr($key) . '"' . (empty($item['sweeps']) ? '' : ' data-sweeps="1"')
            . '><td><strong>' . esc_html($item['label']) . '</strong><br>'
            . '<span style="color:#888;font-size:12px;">' . esc_html($item['desc'])
            . (empty($item['sweeps']) ? '' : esc_html(__('。会一并清理孤立的自定义字段与分类关联', 'kratos'))) . '</span>'
            . '<span class="kratos-perf-db-detail" style="display:' . ($detail !== '' ? 'block' : 'none') . ';margin-top:4px;color:#a4111e;font-size:12px;">'
            . esc_html($detail !== '' ? sprintf(__('命中类型：%s', 'kratos'), $detail) : '')
            . '</span></td>'
            . '<td class="kratos-perf-db-count">' . esc_html(number_format_i18n($n)) . '</td>'
            . '<td class="kratos-perf-db-act">';
        if ($n > 0) {
            echo '<button type="button" class="button button-small kratos-perf-db-clean" data-what="' . esc_attr($key) . '">'
                . esc_html__('清理', 'kratos') . '</button>';
        } else {
            echo '<span style="color:#aaa;">—</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<p style="margin-top:12px;">';
    echo '<span class="kratos-perf-db-all-wrap" style="' . ($total > 0 ? '' : 'display:none;') . 'margin-right:8px;">'
        . '<button type="button" class="button button-primary kratos-perf-db-clean" data-what="all">'
        . esc_html(sprintf(__('一键清理全部（%s 条）', 'kratos'), number_format_i18n($total)))
        . '</button></span>';
    // 条数是 5 分钟缓存，留个手动刷新的口子
    echo '<button type="button" class="button kratos-perf-db-refresh">' . esc_html__('重新统计', 'kratos') . '</button>';
    echo '</p>';

    echo '</div>';

    $i18n = array(
        'confirm_one' => __('确认清理该项？删除不可撤销。', 'kratos'),
        'confirm_post' => __("确认清理该项？删除不可撤销。\n\n「孤立的文章自定义字段」与「孤立的分类关联」会一并清理。", 'kratos'),
        'confirm_all' => __("确认清理全部项目？删除不可撤销。\n\n回收站文章的评论会作为孤立评论一并清理，实际条数可能高于表格显示。", 'kratos'),
        'working'     => __('清理中…', 'kratos'),
        'counting'    => __('统计中…', 'kratos'),
        'clean'       => __('清理', 'kratos'),
        'refresh'     => __('重新统计', 'kratos'),
        'failed'      => __('清理失败，请重试。', 'kratos'),
        'expired'     => __('请求已失效，请刷新页面后重试。', 'kratos'),
        'detail_pre'  => __('命中类型：%s', 'kratos'),
        'dash'        => '—',
    );
    ?>
    <script>
    (function ($) {
        var i18n = <?php echo wp_json_encode($i18n); ?>;

        // 把服务端回传的状态套回面板：条数、明细、行内按钮、总数按钮、警示条
        function applyState($panel, d) {
            $panel.find('tbody tr').each(function () {
                var $row = $(this),
                    key  = $row.data('key');
                if (!(key in d.stats)) {
                    return;
                }
                $row.find('.kratos-perf-db-count').text(d.stats_formatted[key]);

                var $detail = $row.find('.kratos-perf-db-detail'),
                    text    = (d.details && d.details[key]) || '';
                if (text) {
                    $detail.text(i18n.detail_pre.replace('%s', text)).show();
                } else {
                    $detail.text('').hide();
                }

                var $act = $row.find('.kratos-perf-db-act');
                if (d.stats[key] > 0) {
                    $act.html($('<button type="button" class="button button-small kratos-perf-db-clean">')
                        .attr('data-what', key).text(i18n.clean));
                } else {
                    $act.html($('<span style="color:#aaa;">').text(i18n.dash));
                }
            });

            var $allWrap = $panel.find('.kratos-perf-db-all-wrap');
            if (d.total > 0) {
                $allWrap.show().find('.kratos-perf-db-clean').text(d.all_label);
            } else {
                $allWrap.hide();
            }

            var $slow = $panel.find('.kratos-perf-db-slow');
            if (d.slow) {
                $slow.text(d.slow).show();
            } else {
                $slow.text('').hide();
            }
        }

        // 清理与「重新统计」共用一次请求，区别只在 what 与按钮文案
        function request($panel, $btn, what, busyText, idleText) {
            var $note = $panel.find('.kratos-perf-db-notice');

            // 期间锁掉整个面板的按钮
            $panel.find('.kratos-perf-db-clean, .kratos-perf-db-refresh').prop('disabled', true);
            $btn.text(busyText);
            $note.hide();

            $.post(window.ajaxurl, {
                action: 'kratos_perf_db_clean',
                what: what,
                nonce: $panel.data('nonce')
            }).done(function (res) {
                if (!res || !res.success) {
                    $note.attr('class', 'kratos-perf-db-notice csf-notice csf-notice-danger')
                        .text((res && res.data && res.data.message) || i18n.failed).show();
                    return;
                }
                applyState($panel, res.data);
                $note.attr('class', 'kratos-perf-db-notice csf-notice csf-notice-success')
                    .text(res.data.message).show();
            }).fail(function (xhr) {
                // wp_send_json_error() 都带非 2xx 状态码，消息从 responseJSON 取
                var body = (xhr && xhr.responseJSON) || null,
                    msg  = body && body.data && body.data.message;

                // nonce 过期时 WP 直接 wp_die(-1)，响应体不是 JSON，
                // 靠这点与带消息的 403（权限不足）区分开
                if (!msg) {
                    var raw = $.trim((xhr && xhr.responseText) || '');
                    msg = (raw === '-1' || raw === '0' || (xhr && xhr.status === 403))
                        ? i18n.expired
                        : i18n.failed;
                }
                $note.attr('class', 'kratos-perf-db-notice csf-notice csf-notice-danger')
                    .text(msg).show();
            }).always(function () {
                // 成功分支重建过按钮；失败分支按钮还在原地，把「清理中…」还原回去
                if ($btn.closest('.kratos-perf-db').length && $btn.text() === busyText) {
                    $btn.text(idleText);
                }
                $panel.find('.kratos-perf-db-clean, .kratos-perf-db-refresh').prop('disabled', false);
            });
        }

        $(document).on('click', '.kratos-perf-db .kratos-perf-db-clean', function (e) {
            e.preventDefault();

            var $btn = $(this),
                what = $btn.data('what'),
                text;

            if (what === 'all') {
                text = i18n.confirm_all;
            } else {
                text = $btn.closest('tr').data('sweeps') ? i18n.confirm_post : i18n.confirm_one;
            }
            if (!window.confirm(text)) {
                return;
            }
            request($btn.closest('.kratos-perf-db'), $btn, what, i18n.working, i18n.clean);
        });

        $(document).on('click', '.kratos-perf-db .kratos-perf-db-refresh', function (e) {
            e.preventDefault();
            var $btn = $(this);
            request($btn.closest('.kratos-perf-db'), $btn, 'refresh', i18n.counting, i18n.refresh);
        });
    })(jQuery);
    </script>
    <?php
}

/* ============================================================
 *  B7 站点健康 / 运行指标
 * ============================================================ */

/** 主题选项里的「运行环境」面板（callback 字段）。 */
function kratos_perf_render_health_panel()
{
    global $wpdb, $wp_version;

    $obj_cache = wp_using_ext_object_cache();
    $cron_off  = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    $overdue   = 0;
    $crons = _get_cron_array();
    if (is_array($crons)) {
        foreach ($crons as $ts => $hooks) {
            if ($ts < time() - 600) {
                $overdue += count($hooks);
            }
        }
    }

    $db_size = (float) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(data_length + index_length) / 1024 / 1024 FROM information_schema.TABLES WHERE table_schema = %s",
            DB_NAME
        )
    );

    $rows = array(
        array(__('PHP 版本', 'kratos'), PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=') ? 'ok' : 'warn'),
        array(__('WordPress 版本', 'kratos'), $wp_version, 'ok'),
        array(__('MySQL 版本', 'kratos'), $wpdb->db_version(), 'ok'),
        array(__('PHP 内存上限', 'kratos'), ini_get('memory_limit'), 'ok'),
        array(__('本页内存峰值', 'kratos'), size_format(memory_get_peak_usage(true)), 'ok'),
        array(__('本页数据库查询数', 'kratos'), number_format_i18n(get_num_queries()), get_num_queries() > 200 ? 'warn' : 'ok'),
        array(__('数据库体积', 'kratos'), $db_size ? sprintf('%.1f MB', $db_size) : '—', 'ok'),
        array(__('外置对象缓存', 'kratos'), $obj_cache ? __('已启用（Redis / Memcached）', 'kratos') : __('未启用（建议装 Redis Object Cache）', 'kratos'), $obj_cache ? 'ok' : 'warn'),
        array(__('OPcache', 'kratos'), (function_exists('opcache_get_status') && @opcache_get_status(false)) ? __('已启用', 'kratos') : __('未启用', 'kratos'), 'ok'),
        array(__('WP_DEBUG', 'kratos'), (defined('WP_DEBUG') && WP_DEBUG) ? __('开启（生产环境建议关闭）', 'kratos') : __('关闭', 'kratos'), (defined('WP_DEBUG') && WP_DEBUG) ? 'warn' : 'ok'),
        array(__('WP-Cron', 'kratos'), $cron_off ? __('已改用系统 crontab', 'kratos') : __('随访问触发（默认）', 'kratos'), 'ok'),
        array(__('逾期未执行的计划任务', 'kratos'), number_format_i18n($overdue), $overdue > 0 ? 'warn' : 'ok'),
    );

    echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
    foreach ($rows as $r) {
        $color = $r[2] === 'warn' ? '#d97706' : '#16a34a';
        echo '<tr><td style="width:220px;"><strong>' . esc_html($r[0]) . '</strong></td>'
            . '<td style="color:' . $color . ';">' . esc_html($r[1]) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p style="margin-top:10px;color:#888;font-size:12px;">'
        . esc_html__('「本页」两项指的是当前这张选项页自身的开销，仅作量级参考；前台每个页面的实时指标见管理员工具条。', 'kratos')
        . '</p>';
}

/**
 * 管理员工具条上的实时指标：查询数 / 生成耗时 / 内存峰值。
 * 只有能 manage_options 的用户看得到。
 */
function kratos_perf_adminbar_metrics($wp_admin_bar)
{
    if (is_admin() || !current_user_can('manage_options')) {
        return;
    }
    if (!kratos_perf_on('g_perf_adminbar_metrics', true)) {
        return;
    }

    $title = sprintf(
        '%d q · %s s · %s',
        get_num_queries(),
        number_format(timer_stop(0, 3), 3),
        size_format(memory_get_peak_usage(true), 1)
    );

    $wp_admin_bar->add_node(array(
        'id'    => 'kratos-perf',
        'title' => '<span class="ab-icon dashicons dashicons-performance" style="top:2px;"></span>' . esc_html($title),
        'href'  => admin_url('admin.php?page=kratos-options'),
        'meta'  => array('title' => __('数据库查询数 · 页面生成耗时 · 内存峰值', 'kratos')),
    ));
}
// 优先级放到最后，让计数尽可能接近页面真实值
add_action('admin_bar_menu', 'kratos_perf_adminbar_metrics', 999);
