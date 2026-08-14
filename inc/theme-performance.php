<?php

/**
 * 性能优化
 *
 * 集中放「减少请求 / 减少查询 / 减少写库 / 改善 LCP」这一类开关，全部可在
 * 主题选项「基础设置 → 性能优化」里逐项关闭。所有默认值都取保守侧：只关掉
 * 经典主题确定用不到的东西，不碰任何会改变页面内容的行为。
 *
 * 注意：这里不做整页缓存、不做图片转码、不做 CSS/JS 合并压缩 —— 那三件事
 * 与登录态/缓存目录/构建产物耦合太深，应交给 Nginx / 插件 / 对象存储侧。
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

/**
 * DPlayer（~180KB）原来在每个页面无条件入队，实际只有 [dplayer] / [video]
 * 短码用得到。这里在入队之后、打印之前把它摘掉，再由短码自己按需入队 ——
 * 短码在 the_content 阶段执行，footer 脚本尚未打印，此时 enqueue 仍然有效。
 */
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
 * 经典主题不使用区块样式，但 WP 仍会在每个前台页面输出
 * wp-block-library / wp-block-library-theme / classic-theme-styles /
 * global-styles（含一大段 CSS 自定义属性），合计常有 60–100KB。
 *
 * 例外：当前文章/页面本身是用区块编辑器写的（has_blocks）时不卸载，
 * 否则区块排版会散架。
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

// global styles 还会从 wp_footer 补一次，一并摘掉（不影响后台）
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
 * wp-embed.min.js 的唯一作用是让**别人**把本站文章嵌成卡片时能自适应高度，
 * 对本站访客毫无价值，却每页都加载；<link rel="alternate" ...oembed> 两个
 * 发现标签同理。注意只关"被别人嵌入"这一侧，本站嵌别人的 oEmbed 不受影响。
 */
function kratos_perf_disable_oembed()
{
    if (!kratos_perf_on('g_perf_wp_embed', true)) {
        return;
    }
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    // wp-embed 在页脚打印（wp_print_footer_scripts@20），这里两处都摘：
    // 入队阶段 deregister 管住常规路径，wp_footer 早期再 dequeue 兜底。
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

/**
 * jquery-migrate 只为兼容 jQuery 3 之前的旧写法，主题自身不需要。
 * 若装了老插件报 `$.browser` 之类错误，关掉此项即可恢复。
 */
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

/**
 * 前台不需要 Heartbeat（它只服务后台的自动保存 / 登录态续期 / 锁定提示）。
 *
 * 必须挂 wp_enqueue_scripts 而不是 init —— heartbeat 是在 WP_Scripts 初始化
 * （wp_default_scripts）时才注册的，init 阶段 deregister 打不着。
 */
function kratos_perf_kill_frontend_heartbeat()
{
    if (is_admin() || !kratos_perf_on('g_perf_heartbeat_front', true)) {
        return;
    }
    wp_dequeue_script('heartbeat');
    wp_deregister_script('heartbeat');
}
add_action('wp_enqueue_scripts', 'kratos_perf_kill_frontend_heartbeat', 1);

/**
 * 后台轮询间隔：默认 15 秒一次 admin-ajax 请求，长时间挂着后台会持续吃 CPU。
 * 文章编辑页保持较短间隔（防止多人编辑冲突提示失灵），其余页面用设定值。
 */
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
 * 跨域第一次请求要付 DNS + TCP + TLS 三次往返。把实际会用到的域提前
 * preconnect，常见能省 100–300ms 的 LCP。域名从各功能的选项里推导，
 * 没开启的功能不会输出多余的 hint。
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

    // 对象存储 / 图片处理加速域（字段见「基础设置 → 对象存储加速」）
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

    // preconnect 只对确定会用到的少数域有意义（每个都占一条连接），
    // 超过 4 个的部分降级成 dns-prefetch。
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

/**
 * 主题原来给所有缩略图都写死 loading="lazy"，包括首屏第一张 —— 那张
 * 恰恰是 LCP 元素，lazy 会让它排到最后才开始下载。这里把「本次请求的
 * 第一张图」改成 eager + fetchpriority=high，其余保持 lazy，并统一补
 * decoding="async"（解码不阻塞主线程）。
 */
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

/**
 * 正文里的 <img>（走 WP 6.0+ 的 wp_content_img_tag）同样处理。
 * 主题手写的 <img>（默认缩略图 / 文字占位图）请调用
 * kratos_perf_mark_img() 让它们也进入同一套计数。
 */
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
 * 第一张图 → eager + high，其余 → 保持原样（通常已有 lazy）。
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
        // 首图不能 lazy：把已有的 loading="lazy" 换成 eager，没有则补上
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
 * - no_found_rows：不算总数，省掉一次 SELECT FOUND_ROWS()
 * - update_post_term_cache：模板不输出分类/标签时才关（否则反而更慢）
 * - update_post_meta_cache：模板不读任何 post meta 时才关（缩略图算读 meta）
 * - lazy_load_term_meta：term meta 一律不预热
 *
 * 只有确定不分页的查询才能用（分页要靠 found_rows 算 max_num_pages）。
 * 开关关闭时原样返回，便于排查「是不是这层优化把某个列表弄空了」。
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
 * 一条评论提交下来，响应返回前真正必须做完的只有「写库」。剩下两类都是事后工作，
 * 却决定了访客等待时长：
 *
 *   1. 发信 —— 回复通知（theme-smtp.php 的 comment_notify）+ 核心的站长通知 /
 *      审核通知。开了 SMTP 时每封都要建连 + TLS 握手 + AUTH，一次提交常常两封，
 *      0.5–5s 全算在访客的 POST 里。
 *   2. 缓存失效 —— 走心/榜首/博友圈/特色首页/看板/地域分布各自 delete_transient，
 *      一次提交 6 次缓存往返。
 *
 * 做法：在 wp_insert_comment / comment_post 的**最前面**（优先级 1）把这些回调从
 * 当前钩子上摘下来，塞进队列，等响应 flush 给浏览器之后再原样调用。摘除发生在
 * 钩子执行途中 —— WP 每次迭代都会重读回调表，所以「尚未执行到」的回调可以被摘掉。
 *
 * 不重新 do_action 整个钩子（那会把插件的回调也再跑一遍），而是直接按原参数调用
 * 被摘下的那几个函数。
 */

/** 延后执行队列。 */
function &kratos_perf_deferred_queue()
{
    static $queue = array();
    return $queue;
}

/**
 * 把一个回调压入队列，并确保「响应之后」的收尾只注册一次。
 *
 * 这里不复用 functions.php 的 kratos_dispatch_bg_task()：那个只接受函数名字符串
 * （内部会把 $callback 拼进 error_log，闭包会直接 fatal），而且每条评论都写两行
 * 日志。逻辑相同，去掉日志、支持任意 callable。
 */
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

/** 响应已经发给浏览器，再干脏活。 */
function kratos_perf_run_deferred()
{
    // 先把响应彻底交出去，之后的耗时对访客不可见
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
            // 已经在响应之后，抛出去只会污染日志/中断剩余任务
            error_log('[kratos_perf] deferred task failed: ' . $e->getMessage());
        }
    }
    $queue = array();
}

/**
 * 需要延后的回调表：hook => [[callback, priority, accepted_args], ...]
 *
 * 都是主题自己或 WP 核心的命名函数，插件挂的回调一律不碰。
 */
function kratos_perf_deferrable_map()
{
    return array(
        // wp_insert_comment 在 comment_post 之前触发，挂在它上面的缓存失效要单独摘
        'wp_insert_comment' => array(
            array('kratos_rank_invalidate_cache', 10, 1),
            array('kratos_top_commenters_flush_cache', 10, 1),
            array('kratos_friend_recent_flush_cache', 10, 1),
            array('kratos_home_flush_cache', 10, 1),
        ),
        'comment_post' => array(
            // 发信（核心两封 + 主题的回复通知）
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
        // 用 has_action() 回报的真实优先级去摘 —— 别人（子主题/插件）可能以别的
        // 优先级重挂过同一个函数，按表里的写死值 remove 会摘不掉
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
 * 后台点「通过审核」时的通知邮件同理延后（那一下也是干等 SMTP）。
 * theme-smtp.php 在同一钩子上还挂了一个匿名函数，匿名回调无法 remove_action，
 * 只能连它一起走同步 —— 所以这里只摘命名的 comment_approved()。
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

/** 可清理项定义：label + 统计 SQL + 删除回调。 */
function kratos_perf_db_items()
{
    global $wpdb;

    return array(
        'revisions' => array(
            'label' => __('文章修订版本', 'kratos'),
            'desc'  => __('post_type = revision 的历史版本记录', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'",
            'clean' => "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'",
        ),
        'auto_drafts' => array(
            'label' => __('自动草稿', 'kratos'),
            'desc'  => __('点了「新建」但从未保存的空文章', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'",
            'clean' => "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'",
        ),
        'trash_posts' => array(
            'label' => __('回收站中的文章', 'kratos'),
            'desc'  => __('已移入回收站、确认不再需要的文章与页面', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'",
            'clean' => "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'",
        ),
        'orphan_postmeta' => array(
            'label' => __('孤立的文章自定义字段', 'kratos'),
            'desc'  => __('所属文章已被删除、meta 仍残留的记录', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL",
            'clean' => "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL",
        ),
        'orphan_commentmeta' => array(
            'label' => __('孤立的评论自定义字段', 'kratos'),
            'desc'  => __('所属评论已被删除、meta 仍残留的记录', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
            'clean' => "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
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
            'desc'  => __('已过期但仍留在 options 表里的 transient', 'kratos'),
            'count' => "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_%' AND option_value < UNIX_TIMESTAMP()",
            'clean' => 'callback:kratos_perf_clean_expired_transients',
        ),
    );
}

/** 统计各清理项的条数。 */
function kratos_perf_db_stats()
{
    global $wpdb;
    $out = array();
    foreach (kratos_perf_db_items() as $key => $item) {
        $out[$key] = (int) $wpdb->get_var($item['count']);
    }
    return $out;
}

/**
 * 过期 transient 要连 timeout 行和数据行一起删，单条 SQL 表达不了，
 * 单独用回调处理。
 */
function kratos_perf_clean_expired_transients()
{
    global $wpdb;
    $names = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE '\_transient\_timeout\_%' AND option_value < UNIX_TIMESTAMP()"
    );
    $n = 0;
    foreach ($names as $timeout_name) {
        $key = substr($timeout_name, strlen('_transient_timeout_'));
        // 走 delete_transient 而不是直接 DELETE：外置对象缓存下也能同步失效
        if (delete_transient($key)) {
            $n++;
        } else {
            $wpdb->delete($wpdb->options, array('option_name' => $timeout_name));
            $wpdb->delete($wpdb->options, array('option_name' => '_transient_' . $key));
            $n++;
        }
    }
    return $n;
}

/**
 * 回跳地址：主题选项页 + 定位到「性能优化」子页。
 *
 * CSF 用 URL 片段 `#tab=<父级 slug>/<子级 slug>` 记住当前子页，slug 取
 * `sanitize_title($section['title'])`（见 admin-options.class.php 的 $sub_id）。
 * 片段不会随请求发到服务端，所以 wp_get_referer() 拿不到它 —— 不显式拼回去，
 * 清理完就会落回「基础设置」的第一个子页（界面开关）。
 *
 * 这里依赖两个 section 标题，改标题时需同步改这两个字符串。
 */
function kratos_perf_options_url()
{
    $tab = sanitize_title(__('基础设置', 'kratos')) . '/' . sanitize_title(__('性能优化', 'kratos'));
    $url = wp_get_referer() ?: admin_url('admin.php?page=kratos-options');
    $url = preg_replace('/#.*$/', '', $url);
    return $url . '#tab=' . $tab;
}

/** admin-post 处理：执行清理后带结果回跳。 */
function kratos_perf_db_clean_handler()
{
    if (!current_user_can('manage_options') || !check_admin_referer('kratos_perf_db_clean')) {
        wp_die('Unauthorized', 'Forbidden', array('response' => 403));
    }

    global $wpdb;
    $what  = isset($_GET['what']) ? sanitize_key($_GET['what']) : '';
    $items = kratos_perf_db_items();
    $todo  = ($what === 'all') ? array_keys($items) : (isset($items[$what]) ? array($what) : array());

    $done = array();
    foreach ($todo as $key) {
        $sql = $items[$key]['clean'];
        if (strpos($sql, 'callback:') === 0) {
            $done[$key] = (int) call_user_func(substr($sql, 9));
            continue;
        }
        $done[$key] = (int) $wpdb->query($sql);
    }

    // 删完 posts / comments 行后，父表计数与孤立 meta 需要收尾
    if (array_intersect(array_keys($done), array('revisions', 'auto_drafts', 'trash_posts'))) {
        $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
        $wpdb->query("DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL");
    }
    if (array_intersect(array_keys($done), array('spam_comments', 'trash_comments', 'pingbacks'))) {
        $wpdb->query("DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
    }

    set_transient('kratos_perf_db_notice', $done, 60);
    wp_safe_redirect(kratos_perf_options_url());
    exit;
}
add_action('admin_post_kratos_perf_db_clean', 'kratos_perf_db_clean_handler');

/** 主题选项里的「数据库瘦身」面板（callback 字段）。 */
function kratos_perf_render_db_panel()
{
    $items = kratos_perf_db_items();
    $stats = kratos_perf_db_stats();
    $total = array_sum($stats);
    $notice = get_transient('kratos_perf_db_notice');
    if (is_array($notice)) {
        delete_transient('kratos_perf_db_notice');
        $sum = array_sum($notice);
        echo '<div class="csf-notice csf-success-notice" style="margin-bottom:12px;">'
            . sprintf(esc_html__('已清理 %s 条记录。', 'kratos'), '<strong>' . number_format_i18n($sum) . '</strong>')
            . '</div>';
    }

    echo '<p style="margin:0 0 10px;color:#666;">'
        . esc_html__('清理只删除上述类型的冗余记录，不会碰已发布内容。删除不可撤销，建议先备份数据库。', 'kratos')
        . '</p>';

    echo '<table class="widefat striped" style="max-width:720px;"><thead><tr>'
        . '<th>' . esc_html__('项目', 'kratos') . '</th>'
        . '<th style="width:90px;">' . esc_html__('条数', 'kratos') . '</th>'
        . '<th style="width:110px;">' . esc_html__('操作', 'kratos') . '</th>'
        . '</tr></thead><tbody>';

    foreach ($items as $key => $item) {
        $n = isset($stats[$key]) ? $stats[$key] : 0;
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=kratos_perf_db_clean&what=' . $key),
            'kratos_perf_db_clean'
        );
        echo '<tr><td><strong>' . esc_html($item['label']) . '</strong><br>'
            . '<span style="color:#888;font-size:12px;">' . esc_html($item['desc']) . '</span></td>'
            . '<td>' . esc_html(number_format_i18n($n)) . '</td><td>';
        if ($n > 0) {
            echo '<a class="button button-small" href="' . esc_url($url) . '" '
                . 'onclick="return confirm(\'' . esc_js(__('确认清理该项？删除不可撤销。', 'kratos')) . '\');">'
                . esc_html__('清理', 'kratos') . '</a>';
        } else {
            echo '<span style="color:#aaa;">—</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    if ($total > 0) {
        $all = wp_nonce_url(
            admin_url('admin-post.php?action=kratos_perf_db_clean&what=all'),
            'kratos_perf_db_clean'
        );
        echo '<p style="margin-top:12px;"><a class="button button-primary" href="' . esc_url($all) . '" '
            . 'onclick="return confirm(\'' . esc_js(__('确认清理全部项目？删除不可撤销。', 'kratos')) . '\');">'
            . sprintf(esc_html__('一键清理全部（%s 条）', 'kratos'), number_format_i18n($total)) . '</a></p>';
    }
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
 * 只有能 manage_options 的用户看得到，普通访客与未登录用户不受任何影响。
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
