<?php

/**
 * 博友动态（Friend Feed）
 *
 * 抓取友链（wp_links）里 link_rss 非空的站点的 RSS Feed，落库到自建表
 * `{prefix}kratos_friend_feed`，页面上按走心评论布局展示：
 *   - 顶部 4 张统计卡：抓取到的文章总数 / 订阅站点总数 / 本月文章总数 / 最近更新时间
 *   - 下方文章卡片列表：站点头像 + 文章标题 + 摘要 + 时间 + 来源站点
 *   - 分页（默认每页 20 条，可在「主题设置 → 博友动态」调整）
 *
 * 后台可设置：自动更新间隔（依赖 WP-Cron）、每页条数、页头标题/副标题、
 * 摘要长度；同时提供「立即刷新」按钮触发一次同步。
 *
 * 数据源：wp_links 表 link_visible='Y' 且 link_rss 非空的记录。
 * 去重：以 (link_id, md5(guid)) 为唯一键；GUID 缺失时降级到文章链接。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

const KRATOS_FRIEND_FEED_DB_VERSION = '1.0.0';
const KRATOS_FRIEND_FEED_CRON_HOOK  = 'kratos_friend_feed_cron_fetch';
const KRATOS_FRIEND_FEED_MAX_ITEMS  = 20; // 单站点单次最多入库文章数

/* ============================================================
 *  数据表
 * ============================================================ */

/**
 * 返回自建表全名（含 wpdb 前缀）
 */
function kratos_friend_feed_table()
{
    global $wpdb;
    return $wpdb->prefix . 'kratos_friend_feed';
}

/**
 * 创建 / 升级数据表。使用 dbDelta。
 */
function kratos_friend_feed_install_table()
{
    global $wpdb;
    $table   = kratos_friend_feed_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        link_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        site_name VARCHAR(191) NOT NULL DEFAULT '',
        site_url VARCHAR(600) NOT NULL DEFAULT '',
        site_image VARCHAR(600) NOT NULL DEFAULT '',
        guid_hash CHAR(32) NOT NULL DEFAULT '',
        guid VARCHAR(600) NOT NULL DEFAULT '',
        title VARCHAR(600) NOT NULL DEFAULT '',
        permalink VARCHAR(600) NOT NULL DEFAULT '',
        author VARCHAR(191) NOT NULL DEFAULT '',
        summary TEXT NOT NULL,
        published_gmt DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
        fetched_gmt DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
        PRIMARY KEY  (id),
        UNIQUE KEY link_guid (link_id, guid_hash),
        KEY link_id (link_id),
        KEY published_gmt (published_gmt)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('kratos_friend_feed_db_version', KRATOS_FRIEND_FEED_DB_VERSION, false);
}

/**
 * 首次访问 / 版本升级时确保表存在。
 */
function kratos_friend_feed_maybe_install()
{
    if (get_option('kratos_friend_feed_db_version') !== KRATOS_FRIEND_FEED_DB_VERSION) {
        kratos_friend_feed_install_table();
    }
}
add_action('after_switch_theme', 'kratos_friend_feed_install_table');
add_action('init', 'kratos_friend_feed_maybe_install', 5);

/* ============================================================
 *  Cron
 * ============================================================ */

/**
 * 注册自定义 cron 间隔（除了 WP 内置 hourly/twicedaily/daily）
 */
function kratos_friend_feed_cron_intervals($schedules)
{
    if (!isset($schedules['kratos_friend_feed_6h'])) {
        $schedules['kratos_friend_feed_6h'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __('每 6 小时（博友动态）', 'kratos'),
        );
    }
    if (!isset($schedules['kratos_friend_feed_12h'])) {
        $schedules['kratos_friend_feed_12h'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => __('每 12 小时（博友动态）', 'kratos'),
        );
    }
    return $schedules;
}
add_filter('cron_schedules', 'kratos_friend_feed_cron_intervals');

/**
 * 获取当前配置的 cron 间隔（默认每 6 小时）
 */
function kratos_friend_feed_current_interval()
{
    $allowed = array('hourly', 'kratos_friend_feed_6h', 'kratos_friend_feed_12h', 'twicedaily', 'daily');
    $val = (string) kratos_option('g_friend_feed_cron_interval', 'kratos_friend_feed_6h');
    return in_array($val, $allowed, true) ? $val : 'kratos_friend_feed_6h';
}

/**
 * RSS 抓取总开关。关闭时不排 Cron、立即刷新按钮不生效、前台不查表。
 */
function kratos_friend_feed_is_enabled()
{
    // 用一个显式的枚举比较，避免 kratos_option 在默认值上返回布尔 false
    // 与关闭状态混淆。
    $val = kratos_option('g_friend_feed_enabled', '1');
    return $val === '1' || $val === 1 || $val === true;
}

/**
 * 保证 cron 已注册（间隔与配置一致），配置变更时会自动重排。
 * 总开关关闭时清空排期。
 */
function kratos_friend_feed_sync_cron()
{
    if (!kratos_friend_feed_is_enabled()) {
        if (wp_next_scheduled(KRATOS_FRIEND_FEED_CRON_HOOK)) {
            wp_clear_scheduled_hook(KRATOS_FRIEND_FEED_CRON_HOOK);
        }
        return;
    }

    $desired = kratos_friend_feed_current_interval();
    $current = wp_get_schedule(KRATOS_FRIEND_FEED_CRON_HOOK);

    if ($current !== $desired) {
        wp_clear_scheduled_hook(KRATOS_FRIEND_FEED_CRON_HOOK);
        wp_schedule_event(time() + 60, $desired, KRATOS_FRIEND_FEED_CRON_HOOK);
    }
}
add_action('init', 'kratos_friend_feed_sync_cron', 20);

/**
 * 主题设置保存后重新校准 cron。开关或间隔变化都会触发重排。
 */
function kratos_friend_feed_on_options_saved($option, $old, $value)
{
    if ($option !== 'kratos_options') return;
    $old_i = is_array($old)   ? ($old['g_friend_feed_cron_interval']   ?? null) : null;
    $new_i = is_array($value) ? ($value['g_friend_feed_cron_interval'] ?? null) : null;
    $old_e = is_array($old)   ? ($old['g_friend_feed_enabled']   ?? null) : null;
    $new_e = is_array($value) ? ($value['g_friend_feed_enabled'] ?? null) : null;
    if ($old_i !== $new_i || $old_e !== $new_e) {
        wp_clear_scheduled_hook(KRATOS_FRIEND_FEED_CRON_HOOK);
        kratos_friend_feed_sync_cron();
    }
}
add_action('updated_option', 'kratos_friend_feed_on_options_saved', 10, 3);

/* ============================================================
 *  RSS 抓取
 * ============================================================ */

/**
 * 列出所有可抓取的 RSS 源（wp_links 中 link_rss 非空 + link_visible='Y'）
 */
function kratos_friend_feed_sources()
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT link_id, link_name, link_url, link_image, link_rss
         FROM {$wpdb->links}
         WHERE link_visible = 'Y' AND link_rss <> ''
         ORDER BY link_name ASC"
    );
    return is_array($rows) ? $rows : array();
}

/**
 * 兜底抓取：手动 HTTP 拉原文，补齐常见但漏声明的 XML 命名空间
 * （wfw / content / dc / atom / sy / slash / media / georss），再让
 * SimplePie 从字符串解析。仅在 fetch_feed() 直接失败时被调用。
 *
 * 返回 SimplePie 对象，失败返回 WP_Error。
 */
function kratos_friend_feed_fetch_sanitized($url)
{
    $resp = wp_safe_remote_get($url, array(
        'timeout'     => 15,
        'redirection' => 5,
        'user-agent'  => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
        'headers'     => array('Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8'),
    ));
    if (is_wp_error($resp)) return $resp;
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('http_error', 'HTTP ' . $code);
    }
    $body = (string) wp_remote_retrieve_body($resp);
    if ($body === '') return new WP_Error('empty_body', 'empty feed body');

    $ns_map = array(
        'wfw'     => 'http://wellformedweb.org/CommentAPI/',
        'content' => 'http://purl.org/rss/1.0/modules/content/',
        'dc'      => 'http://purl.org/dc/elements/1.1/',
        'atom'    => 'http://www.w3.org/2005/Atom',
        'sy'      => 'http://purl.org/rss/1.0/modules/syndication/',
        'slash'   => 'http://purl.org/rss/1.0/modules/slash/',
        'media'   => 'http://search.yahoo.com/mrss/',
        'georss'  => 'http://www.georss.org/georss',
    );

    if (preg_match('/<rss\b[^>]*>/i', $body, $m, PREG_OFFSET_CAPTURE)) {
        $tag = $m[0][0];
        $pos = $m[0][1];
        $add = '';
        foreach ($ns_map as $prefix => $uri) {
            if (preg_match('/\bxmlns:' . preg_quote($prefix, '/') . '\s*=/i', $tag)) continue;
            if (!preg_match('/<[a-z0-9_.-]*' . preg_quote($prefix, '/') . ':/i', $body)) continue;
            $add .= ' xmlns:' . $prefix . '="' . $uri . '"';
        }
        if ($add !== '') {
            $new_tag = rtrim($tag, '>') . $add . '>';
            $body = substr_replace($body, $new_tag, $pos, strlen($tag));
        }
    }

    if (!class_exists('SimplePie', false)) {
        require_once ABSPATH . WPINC . '/class-simplepie.php';
    }
    if (!function_exists('wp_feed_cache_transient_lifetime')) {
        require_once ABSPATH . WPINC . '/feed.php';
    }
    $sp = new SimplePie();
    $sp->set_raw_data($body);
    $sp->set_cache_duration(apply_filters('wp_feed_cache_transient_lifetime', 12 * HOUR_IN_SECONDS, $url));
    do_action_ref_array('wp_feed_options', array(&$sp, $url));
    $sp->init();
    $sp->handle_content_type();
    if ($sp->error()) {
        return new WP_Error('simplepie-error', $sp->error());
    }
    return $sp;
}

/**
 * 拉取单个 link_id 对应的 Feed 并写入数据库。
 * 返回 array{fetched:int, inserted:int, error:string|null}
 */
function kratos_friend_feed_fetch_one($link)
{
    $out = array('fetched' => 0, 'inserted' => 0, 'error' => null);
    if (empty($link->link_rss)) {
        $out['error'] = 'no_rss';
        return $out;
    }
    if (!function_exists('fetch_feed')) {
        require_once ABSPATH . WPINC . '/feed.php';
    }
    $feed = fetch_feed($link->link_rss);
    if (is_wp_error($feed)) {
        // 兜底：部分站点（如老张博客）的 RSS 输出缺少 wfw/content 等 xmlns
        // 声明，libxml 严格解析会直接失败。这里手动拉原文、补齐命名空间后
        // 交给 SimplePie 重新解析一次。
        $feed2 = kratos_friend_feed_fetch_sanitized($link->link_rss);
        if (is_wp_error($feed2)) {
            $out['error'] = $feed->get_error_message();
            return $out;
        }
        $feed = $feed2;
    }
    $max = (int) apply_filters('kratos_friend_feed_max_items_per_source', KRATOS_FRIEND_FEED_MAX_ITEMS, $link);
    $items = $feed->get_items(0, $max);

    if (empty($items)) return $out;

    global $wpdb;
    $table = kratos_friend_feed_table();
    $now   = current_time('mysql', 1);

    $summary_len = (int) kratos_option('g_friend_feed_summary_len', 160);
    if ($summary_len < 0) $summary_len = 0;

    foreach ($items as $item) {
        /** @var SimplePie_Item $item */
        $out['fetched']++;

        $permalink = (string) $item->get_permalink();
        $guid_raw  = (string) $item->get_id();
        if ($guid_raw === '') $guid_raw = $permalink;
        if ($guid_raw === '') continue;
        $guid_hash = md5($guid_raw);

        // 去重：同一 link_id + guid_hash 唯一
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE link_id = %d AND guid_hash = %s LIMIT 1",
            (int) $link->link_id,
            $guid_hash
        ));
        if ($exists > 0) continue;

        $title  = html_entity_decode((string) $item->get_title(), ENT_QUOTES, 'UTF-8');
        $author = '';
        if ($a = $item->get_author()) {
            $author = html_entity_decode((string) $a->get_name(), ENT_QUOTES, 'UTF-8');
        }

        $raw_desc = (string) $item->get_description();
        if ($raw_desc === '') $raw_desc = (string) $item->get_content();
        $summary  = wp_strip_all_tags($raw_desc);
        $summary  = trim(preg_replace('/\s+/u', ' ', $summary));
        if ($summary_len > 0 && function_exists('mb_strimwidth')) {
            $summary = mb_strimwidth($summary, 0, $summary_len, '…', 'UTF-8');
        }

        $ts = $item->get_gmdate('Y-m-d H:i:s');
        if (!$ts) $ts = gmdate('Y-m-d H:i:s');

        $wpdb->insert(
            $table,
            array(
                'link_id'       => (int) $link->link_id,
                'site_name'     => mb_substr((string) $link->link_name, 0, 190, 'UTF-8'),
                'site_url'      => mb_substr((string) $link->link_url, 0, 599, 'UTF-8'),
                'site_image'    => mb_substr((string) $link->link_image, 0, 599, 'UTF-8'),
                'guid_hash'     => $guid_hash,
                'guid'          => mb_substr($guid_raw, 0, 599, 'UTF-8'),
                'title'         => mb_substr($title, 0, 599, 'UTF-8'),
                'permalink'     => mb_substr($permalink, 0, 599, 'UTF-8'),
                'author'        => mb_substr($author, 0, 190, 'UTF-8'),
                'summary'       => $summary,
                'published_gmt' => $ts,
                'fetched_gmt'   => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($wpdb->insert_id) $out['inserted']++;
    }

    return $out;
}

/**
 * 抓取所有源。返回汇总统计。总开关关闭时直接返回空结果并不落盘。
 */
function kratos_friend_feed_fetch_all()
{
    if (!kratos_friend_feed_is_enabled()) {
        return array(
            'sites'    => 0,
            'ok'       => 0,
            'fetched'  => 0,
            'inserted' => 0,
            'errors'   => array(),
            'skipped'  => true,
        );
    }

    $sources = kratos_friend_feed_sources();
    $result  = array(
        'sites'    => 0,
        'ok'       => 0,
        'fetched'  => 0,
        'inserted' => 0,
        'errors'   => array(),
    );

    foreach ($sources as $link) {
        $result['sites']++;
        $r = kratos_friend_feed_fetch_one($link);
        $result['fetched']  += (int) $r['fetched'];
        $result['inserted'] += (int) $r['inserted'];
        if ($r['error']) {
            $result['errors'][] = array(
                'name'  => $link->link_name,
                'error' => $r['error'],
            );
        } else {
            $result['ok']++;
        }
    }

    update_option('kratos_friend_feed_last_run', array(
        'time'     => time(),
        'sites'    => $result['sites'],
        'ok'       => $result['ok'],
        'fetched'  => $result['fetched'],
        'inserted' => $result['inserted'],
        'errors'   => array_slice($result['errors'], 0, 20),
    ), false);

    delete_transient('kratos_friend_feed_stats');
    return $result;
}
add_action(KRATOS_FRIEND_FEED_CRON_HOOK, 'kratos_friend_feed_fetch_all');

/* ============================================================
 *  查询 / 统计
 * ============================================================ */

/**
 * 批量读取当前的 wp_links 头像 / 名称，避免遍历时逐行 SQL。
 * 返回 array<int link_id, array{name:string, url:string, image:string}>
 */
function kratos_friend_feed_link_meta_map($link_ids)
{
    $link_ids = array_values(array_unique(array_filter(array_map('intval', (array) $link_ids))));
    if (empty($link_ids)) return array();

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($link_ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT link_id, link_name, link_url, link_image FROM {$wpdb->links} WHERE link_id IN ($placeholders)",
        $link_ids
    ));

    $map = array();
    foreach ((array) $rows as $r) {
        $map[(int) $r->link_id] = array(
            'name'  => (string) $r->link_name,
            'url'   => (string) $r->link_url,
            'image' => (string) $r->link_image,
        );
    }
    return $map;
}

/**
 * 分页取文章列表。
 *
 * @param int $per_page
 * @param int $page 1-based
 * @return array{items: array<int, object>, total: int, total_pages: int, page: int, per_page: int}
 */
function kratos_friend_feed_get_items($per_page, $page)
{
    global $wpdb;
    $table    = kratos_friend_feed_table();
    $per_page = max(0, (int) $per_page);
    $page     = max(1, (int) $page);

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");

    if ($per_page === 0) {
        $items = $wpdb->get_results("SELECT * FROM $table ORDER BY published_gmt DESC");
        return array(
            'items'       => is_array($items) ? $items : array(),
            'total'       => $total,
            'total_pages' => 1,
            'page'        => 1,
            'per_page'    => 0,
        );
    }

    $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table ORDER BY published_gmt DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

    return array(
        'items'       => is_array($items) ? $items : array(),
        'total'       => $total,
        'total_pages' => $total_pages,
        'page'        => $page,
        'per_page'    => $per_page,
    );
}

/**
 * 顶部统计卡数据。
 *
 * 返回：
 *   - total       文章总数
 *   - sites       有 RSS 且已入库文章的站点数
 *   - month       本月文章数（依站点时区）
 *   - latest_gmt  最近一篇文章的 published_gmt（可能为空）
 *   - sources     有效 RSS 源总数
 */
function kratos_friend_feed_get_stats()
{
    $cached = get_transient('kratos_friend_feed_stats');
    if (is_array($cached)) return $cached;

    global $wpdb;
    $table = kratos_friend_feed_table();

    // 本月：按站点当地时区起点计算
    $month_start_local = date_i18n('Y-m-01 00:00:00');
    $month_start_gmt   = get_gmt_from_date($month_start_local, 'Y-m-d H:i:s');

    // 四项都取自同一张表，合成一条聚合 SQL（原先是四次独立扫表）
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS total,
                COUNT(DISTINCT link_id) AS sites,
                MAX(published_gmt) AS latest,
                SUM(CASE WHEN published_gmt >= %s THEN 1 ELSE 0 END) AS month_cnt
         FROM $table",
        $month_start_gmt
    ), ARRAY_A);

    $total  = isset($row['total']) ? (int) $row['total'] : 0;
    $sites  = isset($row['sites']) ? (int) $row['sites'] : 0;
    $latest = isset($row['latest']) ? (string) $row['latest'] : '';
    $month  = isset($row['month_cnt']) ? (int) $row['month_cnt'] : 0;

    $sources_total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->links} WHERE link_visible = 'Y' AND link_rss <> ''"
    );

    $stats = array(
        'total'      => $total,
        'sites'      => $sites,
        'month'      => $month,
        'latest_gmt' => $latest ?: '',
        'sources'    => $sources_total,
    );
    set_transient('kratos_friend_feed_stats', $stats, 5 * MINUTE_IN_SECONDS);
    return $stats;
}

/* ============================================================
 *  管理动作：立即刷新
 * ============================================================ */

function kratos_friend_feed_admin_refresh()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('权限不足', 'kratos'));
    }
    check_admin_referer('kratos_friend_feed_refresh');

    $redirect = wp_get_referer() ?: admin_url('themes.php');

    if (!kratos_friend_feed_is_enabled()) {
        $redirect = add_query_arg('kratos_friend_feed_disabled', 1, $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    $result = kratos_friend_feed_fetch_all();

    $redirect = add_query_arg(array(
        'kratos_friend_feed_refreshed' => 1,
        'sites'    => (int) $result['sites'],
        'ok'      => (int) $result['ok'],
        'fetched' => (int) $result['fetched'],
        'inserted' => (int) $result['inserted'],
    ), $redirect);
    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_kratos_friend_feed_refresh', 'kratos_friend_feed_admin_refresh');

function kratos_friend_feed_admin_notice()
{
    if (!current_user_can('manage_options')) return;
    if (!empty($_GET['kratos_friend_feed_disabled'])) {
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            esc_html__('博友动态抓取已关闭。请先在「主题设置 → 博友动态」中开启「启用 RSS 抓取」，再点击「立即刷新」。', 'kratos')
        );
    }
    if (empty($_GET['kratos_friend_feed_refreshed'])) return;
    $sites    = isset($_GET['sites'])    ? (int) $_GET['sites']    : 0;
    $ok       = isset($_GET['ok'])       ? (int) $_GET['ok']       : 0;
    $fetched  = isset($_GET['fetched'])  ? (int) $_GET['fetched']  : 0;
    $inserted = isset($_GET['inserted']) ? (int) $_GET['inserted'] : 0;
    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        esc_html(sprintf(
            /* translators: %1$d ok sites, %2$d total, %3$d fetched items, %4$d new inserted */
            __('博友动态刷新完成：成功 %1$d / %2$d 个站点，读取 %3$d 篇，新入库 %4$d 篇。', 'kratos'),
            $ok,
            $sites,
            $fetched,
            $inserted
        ))
    );
}
add_action('admin_notices', 'kratos_friend_feed_admin_notice');

/* ============================================================
 *  短码 [friend_feed]
 * ============================================================ */

function kratos_friend_feed_shortcode($atts)
{
    $default_title    = (string) kratos_option('g_friend_feed_sc_title', __('博友动态', 'kratos'));
    $default_subtitle = (string) kratos_option('g_friend_feed_sc_subtitle', __('订阅友链的 RSS，把大家的更新汇聚在一起 🌐', 'kratos'));
    $default_per_page = (int) kratos_option('g_friend_feed_sc_per_page', 20);
    if ($default_per_page < 0) $default_per_page = 20;

    $atts = shortcode_atts(array(
        'title'    => $default_title,
        'subtitle' => $default_subtitle,
        'per_page' => $default_per_page,
    ), $atts, 'friend_feed');

    $per_page = max(0, (int) $atts['per_page']);
    $page     = isset($_GET['ffd_page']) ? max(1, (int) $_GET['ffd_page']) : 1;

    $stats = kratos_friend_feed_get_stats();
    $paged = kratos_friend_feed_get_items($per_page, $page);

    $latest_display = '—';
    $latest_full    = '';
    if (!empty($stats['latest_gmt']) && $stats['latest_gmt'] !== '1970-01-01 00:00:00') {
        $latest_ts   = (int) get_date_from_gmt($stats['latest_gmt'], 'U');
        $latest_full = get_date_from_gmt($stats['latest_gmt'], get_option('date_format') . ' ' . get_option('time_format'));
        if ($latest_ts > 0) {
            $latest_display = human_time_diff($latest_ts, current_time('timestamp')) . __('前', 'kratos');
        }
    }

    $title    = (string) $atts['title'];
    $subtitle = (string) $atts['subtitle'];

    ob_start();
    ?>
    <div class="kratos-friend-feed" id="kratos-friend-feed-list">
        <?php if ($title !== '' || $subtitle !== '') { ?>
            <header class="kff-header kr-hd">
                <?php if ($title !== '') { ?>
                    <span class="kff-title-icon kr-ico" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg>
                    </span>
                    <span class="kff-title kr-hd-title"><?php echo esc_html($title); ?></span>
                <?php } ?>
                <?php if ($subtitle !== '') { ?>
                    <?php if ($title !== '') { ?><span class="kff-header-divider kr-hd-divider" aria-hidden="true"></span><?php } ?>
                    <p class="kff-subtitle kr-hd-sub"><?php echo esc_html($subtitle); ?></p>
                <?php } ?>
            </header>
        <?php } ?>

        <div class="kff-stats">
            <div class="kff-stat kr-card">
                <span class="kff-stat-icon kr-ico" aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </span>
                <div class="kff-stat-body">
                    <div class="kff-stat-label"><?php esc_html_e('文章总数', 'kratos'); ?></div>
                    <div class="kff-stat-num"><?php echo (int) $stats['total']; ?></div>
                </div>
            </div>
            <div class="kff-stat kr-card">
                <span class="kff-stat-icon kr-ico" aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                </span>
                <div class="kff-stat-body">
                    <div class="kff-stat-label"><?php esc_html_e('订阅站点', 'kratos'); ?></div>
                    <div class="kff-stat-num"><?php echo (int) $stats['sources']; ?></div>
                </div>
            </div>
            <div class="kff-stat kr-card">
                <span class="kff-stat-icon kr-ico" aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <div class="kff-stat-body">
                    <div class="kff-stat-label"><?php esc_html_e('本月文章', 'kratos'); ?></div>
                    <div class="kff-stat-num"><?php echo (int) $stats['month']; ?></div>
                </div>
            </div>
            <div class="kff-stat kr-card">
                <span class="kff-stat-icon kr-ico" aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </span>
                <div class="kff-stat-body">
                    <div class="kff-stat-label"><?php esc_html_e('最近更新', 'kratos'); ?></div>
                    <div class="kff-stat-num kff-stat-time" title="<?php echo esc_attr($latest_full); ?>"><?php echo esc_html($latest_display); ?></div>
                </div>
            </div>
        </div>

        <?php if (empty($paged['items'])) { ?>
            <div class="kff-empty">
                <?php if (!kratos_friend_feed_is_enabled()) {
                    esc_html_e('博友动态抓取当前已关闭。请到「主题设置 → 博友动态」中开启「启用 RSS 抓取」。', 'kratos');
                } elseif ((int) $stats['sources'] === 0) {
                    esc_html_e('还没有配置 RSS 订阅地址的友链。请到「链接」中给友链补上 link_rss 后，等待下一次自动抓取或使用后台「立即刷新」。', 'kratos');
                } else {
                    esc_html_e('还没有抓取到任何文章。请到「主题设置 → 博友动态」使用「立即刷新」触发一次抓取。', 'kratos');
                } ?>
            </div>
        <?php } else {
            $link_ids = array();
            foreach ($paged['items'] as $it) $link_ids[] = (int) $it->link_id;
            $link_map = kratos_friend_feed_link_meta_map($link_ids);
        ?>
            <div class="kff-list">
                <?php foreach ($paged['items'] as $it) {
                    $lm         = $link_map[(int) $it->link_id] ?? array();
                    // 优先使用 wp_links 里当前的字段，落库快照做兜底
                    $site_name  = $lm['name']  ?? '';
                    if ($site_name === '') $site_name = (string) $it->site_name;
                    $site_url   = $lm['url']   ?? '';
                    if ($site_url === '')  $site_url  = (string) $it->site_url;
                    $site_image = $lm['image'] ?? '';
                    if ($site_image === '') $site_image = (string) $it->site_image;
                    if ($site_name === '') $site_name = parse_url($site_url, PHP_URL_HOST) ?: __('（未命名）', 'kratos');

                    $ts_local  = (int) get_date_from_gmt($it->published_gmt, 'U');
                    $time_full = get_date_from_gmt($it->published_gmt, get_option('date_format') . ' ' . get_option('time_format'));
                    $time_rel  = $ts_local > 0 ? human_time_diff($ts_local, current_time('timestamp')) . __('前', 'kratos') : '';
                    $title     = $it->title !== '' ? $it->title : __('（无标题）', 'kratos');

                    $letter    = function_exists('kratos_friend_first_letter') ? kratos_friend_first_letter($site_name !== '' ? $site_name : $site_url) : '#';
                    $bg        = function_exists('kratos_friend_placeholder_color') ? kratos_friend_placeholder_color($site_name !== '' ? $site_name : $site_url) : 'linear-gradient(135deg,#5b7fb8,#3a5a8a)';
                ?>
                    <a class="kff-card kr-card" href="<?php echo esc_url($it->permalink); ?>" target="_blank" rel="noopener external" title="<?php echo esc_attr($title); ?>">
                        <div class="kff-card-head">
                            <span class="kfl-logo kff-logo">
                                <?php if ($site_image !== '') { ?>
                                    <img src="<?php echo esc_url($site_image); ?>" alt="<?php echo esc_attr($site_name); ?>" loading="lazy" onerror="this.parentNode.classList.add('is-fallback');this.remove();" />
                                <?php } else { ?>
                                    <span class="kfl-logo-letter" style="background:<?php echo esc_attr($bg); ?>;"><?php echo esc_html($letter); ?></span>
                                <?php } ?>
                                <?php if ($site_image !== '') { ?>
                                    <span class="kfl-logo-letter kfl-logo-fallback" style="background:<?php echo esc_attr($bg); ?>;"><?php echo esc_html($letter); ?></span>
                                <?php } ?>
                            </span>
                            <div class="kff-meta">
                                <span class="kff-site"><?php echo esc_html($site_name); ?></span>
                                <span class="kff-time" title="<?php echo esc_attr($time_full); ?>"><?php echo esc_html($time_rel); ?></span>
                            </div>
                            <span class="kff-badge" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M4 11a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6v-3zm0-7a16 16 0 0 1 16 16h-3A13 13 0 0 0 4 7V4zm2 15a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg>
                            </span>
                        </div>
                        <div class="kff-title-row"><?php echo esc_html($title); ?></div>
                        <?php if ($it->summary !== '') { ?>
                            <div class="kff-text"><?php echo esc_html($it->summary); ?></div>
                        <?php } ?>
                        <div class="kff-from">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l4-4a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-4 4a5 5 0 0 0 7 7l1-1"/></svg>
                            <span><?php echo esc_html($site_url); ?></span>
                        </div>
                    </a>
                <?php } ?>
            </div>

            <?php if ($paged['total_pages'] > 1) {
                $base_url = remove_query_arg('ffd_page');
                $build = function ($p) use ($base_url) {
                    return esc_url(add_query_arg('ffd_page', $p, $base_url) . '#kratos-friend-feed-list');
                };
                $window = 2;
                $cur    = $paged['page'];
                $total_pages = $paged['total_pages'];
                $start  = max(1, $cur - $window);
                $end    = min($total_pages, $cur + $window);
                if ($end - $start < $window * 2) {
                    if ($start === 1) $end = min($total_pages, $start + $window * 2);
                    if ($end === $total_pages) $start = max(1, $end - $window * 2);
                }
            ?>
                <nav class="kff-pagination khs-pagination" aria-label="<?php esc_attr_e('博友动态分页', 'kratos'); ?>">
                    <?php if ($cur > 1) { ?>
                        <a class="khs-page khs-page-nav" href="<?php echo $build($cur - 1); ?>" rel="prev">
                            &laquo; <?php esc_html_e('上一页', 'kratos'); ?>
                        </a>
                    <?php } ?>

                    <?php if ($start > 1) { ?>
                        <a class="khs-page" href="<?php echo $build(1); ?>">1</a>
                        <?php if ($start > 2) { ?>
                            <span class="khs-page khs-ellipsis">…</span>
                        <?php } ?>
                    <?php } ?>

                    <?php for ($p = $start; $p <= $end; $p++) {
                        if ($p === $cur) { ?>
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

                    <?php if ($cur < $total_pages) { ?>
                        <a class="khs-page khs-page-nav" href="<?php echo $build($cur + 1); ?>" rel="next">
                            <?php esc_html_e('下一页', 'kratos'); ?> &raquo;
                        </a>
                    <?php } ?>
                </nav>
            <?php } ?>
        <?php } ?>
    </div>

    <style>
        /* === 博友动态短码：与走心评论 / 时间轴同源，靠 --khs-* 变量驱动皮肤 === */
        .kratos-friend-feed {
            --khs-bg-1:#f5f5f5; --khs-bg-2:#f0f0f0; --khs-bg-3:#ebebeb;
            --khs-fg:#333; --khs-fg-soft:#444; --khs-fg-dim:#777; --khs-fg-mute:#999;
            --khs-accent:#336699; --khs-accent-2:#2B5278;
            --khs-line:rgba(0,0,0,.08); --khs-line-strong:rgba(0,0,0,.16);
            --khs-card-bg:#ffffff;
            --khs-card-shadow:0 1px 3px rgba(0,0,0,.06);
            --khs-card-shadow-hv:0 8px 18px rgba(0,0,0,.10);
            --khs-page-on:#ffffff;
            padding:0; position:relative;
            color:var(--khs-fg);
        }
        .kratos-friend-feed > *{ position:relative; z-index:1; }

        /* 页头卡片 */
        .kratos-friend-feed .kff-header{
            display:flex; align-items:center; flex-wrap:wrap; gap:14px;
            padding:24px 28px; margin-bottom:18px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:14px;
            box-shadow:var(--khs-card-shadow);
        }
        .kratos-friend-feed .kff-title-icon{
            display:inline-flex; align-items:center; justify-content:center;
            width:38px; height:38px;
            border-radius:10px;
            background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
            color:var(--khs-accent);
        }
        .kratos-friend-feed .kff-title{
            font-size:22px; font-weight:700; line-height:1.3;
            color:var(--khs-fg);
        }
        .kratos-friend-feed .kff-header-divider{
            display:inline-block; width:1px; height:22px;
            background:var(--khs-line-strong);
        }
        .kratos-friend-feed .kff-subtitle{
            margin:0; padding:0;
            font-size:14px; line-height:1.5;
            color:var(--khs-fg-soft);
        }

        /* 4 张统计卡 */
        .kratos-friend-feed .kff-stats{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:16px;
            margin:0 0 22px;
        }
        .kratos-friend-feed .kff-stat{
            display:flex; align-items:center; gap:14px;
            padding:22px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:14px;
            box-shadow:var(--khs-card-shadow);
            transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }
        .kratos-friend-feed .kff-stat:hover{
            transform:translateY(-2px);
            box-shadow:var(--khs-card-shadow-hv);
            border-color:var(--khs-line-strong);
        }
        .kratos-friend-feed .kff-stat-icon{
            flex-shrink:0;
            display:inline-flex; align-items:center; justify-content:center;
            width:46px; height:46px;
            border-radius:50%;
            background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
            color:var(--khs-accent);
        }
        .kratos-friend-feed .kff-stat-body{
            display:flex; flex-direction:column; gap:2px;
            min-width:0;
        }
        .kratos-friend-feed .kff-stat-label{
            font-size:13px; line-height:1.2;
            color:var(--khs-fg-dim);
        }
        .kratos-friend-feed .kff-stat-num{
            font-size:30px; font-weight:700; line-height:1.1;
            color:var(--khs-fg);
            letter-spacing:-0.01em;
        }
        .kratos-friend-feed .kff-stat-time{
            font-size:18px; font-weight:600;
            color:var(--khs-fg-soft);
        }

        /* 文章卡列表 */
        .kratos-friend-feed .kff-list{
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(300px,1fr));
            gap:14px;
        }
        /* 卡片：flex 列布局，让 .kff-from 恒定贴底；grid 已经把每张卡拉到本行
         * 最高高度，配合 margin-top:auto 就能让所有卡的"来源行"在同一水平线。 */
        .kratos-friend-feed .kff-card{
            display:flex;
            flex-direction:column;
            padding:16px 18px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:12px;
            text-decoration:none !important;
            color:inherit !important;
            box-shadow:var(--khs-card-shadow);
            transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .kratos-friend-feed .kff-card:hover{
            transform:translateY(-2px);
            box-shadow:var(--khs-card-shadow-hv);
            border-color:var(--khs-line-strong);
        }
        .kratos-friend-feed .kff-card-head{
            display:flex; align-items:center; gap:10px;
        }
        /* 头像：对齐友链页面 .kfl-logo 规则（圆角方形 + 双层降级） */
        .kratos-friend-feed .kfl-logo{
            flex-shrink:0;
            position:relative;
            width:42px; height:42px;
            display:inline-block;
        }
        .kratos-friend-feed .kfl-logo img{
            width:42px !important; height:42px !important;
            border-radius:10px !important;
            object-fit:cover;
            border:1px solid var(--khs-line);
            display:block;
            background:var(--khs-bg-2);
            box-shadow:none !important;
        }
        .kratos-friend-feed .kfl-logo-letter{
            width:42px; height:42px;
            border-radius:10px;
            display:inline-flex; align-items:center; justify-content:center;
            color:#fff; font-weight:800; font-size:19px; line-height:1;
            text-transform:uppercase; letter-spacing:0;
            text-shadow:0 1px 2px rgba(0,0,0,.20);
            box-shadow:0 1px 2px rgba(0,0,0,.10);
        }
        .kratos-friend-feed .kfl-logo .kfl-logo-fallback{position:absolute; inset:0; display:none;}
        .kratos-friend-feed .kfl-logo.is-fallback .kfl-logo-fallback{display:inline-flex;}
        .kratos-friend-feed .kff-meta{
            flex:1; min-width:0;
            display:flex; flex-direction:column;
        }
        .kratos-friend-feed .kff-site{
            font-size:13px; font-weight:600; color:var(--khs-fg-soft);
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        }
        .kratos-friend-feed .kff-time{
            font-size:11px; color:var(--khs-fg-mute); margin-top:2px;
        }
        .kratos-friend-feed .kff-badge{
            flex-shrink:0;
            display:inline-flex; align-items:center; justify-content:center;
            width:24px; height:24px;
            border-radius:50%;
            background:var(--khs-line);
            color:var(--khs-accent);
        }
        /* 标题：clamp 到 2 行 + 保留 2 行的最小高度，
         * 让单行标题的卡片和双行标题的卡片下方内容起始 y 一致 */
        .kratos-friend-feed .kff-title-row{
            margin:10px 0 6px;
            font-size:15px; font-weight:600; line-height:1.5;
            color:var(--khs-fg);
            display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2;
            overflow:hidden;
            /* 2 行标题的最小占位：font-size * line-height * 2 = 15 * 1.5 * 2 = 45px */
            min-height:calc(15px * 1.5 * 2);
        }
        .kratos-friend-feed .kff-card:hover .kff-title-row{
            color:var(--khs-accent);
        }
        /* 摘要：clamp 3 行 + 预留 3 行高度，避免不同长度摘要撑出不同下沉 */
        .kratos-friend-feed .kff-text{
            margin:0 0 8px;
            font-size:13px; line-height:1.7;
            color:var(--khs-fg-soft);
            display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:3;
            overflow:hidden;
            min-height:calc(13px * 1.7 * 3);
        }
        /* 来源行：flex 撑到底部，配合 .kff-card 的 flex-column 把该行钉在卡片底部；
         * grid 默认 align-items:stretch 会让同一行卡片等高，从而所有卡片的
         * 来源行水平对齐。 */
        .kratos-friend-feed .kff-from{
            display:flex; align-items:center; gap:4px;
            margin-top:auto;
            padding-top:8px;
            border-top:1px solid var(--khs-line);
            font-size:11px; color:var(--khs-fg-mute);
        }
        .kratos-friend-feed .kff-from span{
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        }

        .kratos-friend-feed .kff-empty{
            padding:36px 16px;
            text-align:center;
            color:var(--khs-fg-dim); font-size:14px;
            background:var(--khs-card-bg);
            border:1px dashed var(--khs-line-strong);
            border-radius:12px;
        }

        /* 分页：与走心评论 / 时间轴一致，复用 .khs-page* 皮肤 */
        .kratos-friend-feed .kff-pagination{
            display:flex; justify-content:center; align-items:center;
            gap:6px; flex-wrap:wrap;
            margin-top:22px;
        }
        .kratos-friend-feed .khs-page{
            display:inline-flex; align-items:center; justify-content:center;
            min-width:34px; height:34px; padding:0 12px;
            font-size:13px;
            color:var(--khs-fg-soft) !important;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:2px;
            text-decoration:none !important;
            transition:background .2s ease, color .2s ease, border-color .2s ease;
        }
        .kratos-friend-feed .khs-page:hover{
            background:var(--khs-accent);
            color:var(--khs-page-on) !important;
            border-color:var(--khs-accent);
        }
        .kratos-friend-feed .khs-current{
            background:var(--khs-accent);
            color:var(--khs-page-on) !important;
            border-color:var(--khs-accent);
            cursor:default; font-weight:600;
        }
        .kratos-friend-feed .khs-ellipsis{
            border-color:transparent; background:transparent;
            cursor:default; color:var(--khs-fg-mute);
        }
        .kratos-friend-feed .khs-ellipsis:hover{
            background:transparent;
            color:var(--khs-fg-mute) !important;
            border-color:transparent;
        }

        /* 响应式 */
        @media (max-width:1024px){
            .kratos-friend-feed .kff-stats{grid-template-columns:repeat(2,minmax(0,1fr));}
        }
        @media (max-width:560px){
            .kratos-friend-feed .kff-header{padding:18px 20px; gap:10px;}
            .kratos-friend-feed .kff-title{font-size:19px;}
            .kratos-friend-feed .kff-header-divider{display:none;}
            .kratos-friend-feed .kff-subtitle{flex-basis:100%; font-size:13px;}
            .kratos-friend-feed .kff-stats{grid-template-columns:1fr; gap:12px;}
            .kratos-friend-feed .kff-stat{padding:16px 18px;}
            .kratos-friend-feed .kff-stat-num{font-size:24px;}
            .kratos-friend-feed .kff-stat-time{font-size:15px;}
            .kratos-friend-feed .kff-list{grid-template-columns:1fr;}
        }

        /* 暗夜模式；同步重写 --khs-bg-* 深卡色，避免 kff-title-icon / kff-stat-icon /
         * kfl-logo 占位字母底这些吃 --khs-bg-2/-bg-3 渐变的元素在暗夜下依旧浅灰。 */
        html[data-theme="dark"] .kratos-friend-feed,
        body.dark .kratos-friend-feed{
            --khs-bg-1:#2a2e35; --khs-bg-2:#2a2e35; --khs-bg-3:#333842;
            --khs-fg:#d6d8db; --khs-fg-soft:#b8bbc0; --khs-fg-dim:#8b919a; --khs-fg-mute:#6f747e;
            --khs-accent:#6ea8ff; --khs-accent-2:#91bdff;
            --khs-line:rgba(255,255,255,.08); --khs-line-strong:rgba(255,255,255,.16);
            --khs-card-bg:#1c1f24;
            --khs-card-shadow:0 1px 2px rgba(0,0,0,.5);
            --khs-card-shadow-hv:0 8px 18px rgba(0,0,0,.55);
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('friend_feed', 'kratos_friend_feed_shortcode');

/**
 * page-friend-feed.php 模板 body class
 */
function kratos_friend_feed_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-friend-feed.php')) {
        $classes[] = 'is-kratos-friend-feed-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_friend_feed_body_class');
