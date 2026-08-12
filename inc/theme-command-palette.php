<?php

/**
 * 命令面板（⌘K / Ctrl+K）
 *
 * 一个入口聚合站内高频动作：
 *   - 全站搜索（输入即增量搜文章，回车走完整搜索结果页）
 *   - 跳转特色页（归档 / 时间轴 / 说说 / 友链 / 博友圈 / 走心评论 /
 *     榜首评论人 / Now / 年度回顾 / 数据看板）—— 按「页面实际使用的模板」
 *     动态发现，站长没建的页面不会出现在列表里
 *   - 切换暗色 / 亮色
 *   - 切换皮肤（仅当「前端皮肤切换器」开关打开时出现）
 *   - 随机漫步
 *
 * 快捷键提示按访客操作系统显示：macOS 显示 ⌘K，Windows / Linux 显示 Ctrl+K
 * （由 JS 侧检测 navigator 平台，服务端不做 UA 嗅探，避免被页面缓存污染）。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** 是否启用。 */
function kratos_cmdk_enabled()
{
    return (bool) kratos_option('g_cmdk', true);
}

/**
 * 「页面模板 → 展示名 + 图标」清单。
 * 只有站长真的建了对应模板的页面，才会出现在命令面板里。
 */
function kratos_cmdk_template_map()
{
    return array(
        'page-home-featured.php'  => array('label' => __('特色首页', 'kratos'),   'icon' => 'home'),
        'page-archives.php'       => array('label' => __('文章归档', 'kratos'),   'icon' => 'archive'),
        'page-timeline.php'       => array('label' => __('时间轴', 'kratos'),     'icon' => 'clock'),
        'page-shuoshuo.php'       => array('label' => __('说说', 'kratos'),       'icon' => 'chat'),
        'page-heart-comments.php' => array('label' => __('走心评论', 'kratos'),   'icon' => 'heart'),
        'page-top-commenters.php' => array('label' => __('榜首评论人', 'kratos'), 'icon' => 'trophy'),
        'page-friend-links.php'   => array('label' => __('友情链接', 'kratos'),   'icon' => 'link'),
        'page-friend-feed.php'    => array('label' => __('博友圈', 'kratos'),     'icon' => 'globe'),
        'page-now.php'            => array('label' => __('Now · 我在做什么', 'kratos'), 'icon' => 'flag'),
        'page-yearly-review.php'  => array('label' => __('年度回顾', 'kratos'),   'icon' => 'gift'),
        'page-site-dashboard.php' => array('label' => __('站点数据看板', 'kratos'), 'icon' => 'chart'),
    );
}

/**
 * 单个页面是否允许出现在命令面板。
 *
 * 由页面编辑器右侧「Kratos+ 命令面板」metabox 控制（见本文件底部）。
 * meta 缺失 = 从未保存过该选项 = 默认展示，这样老页面升级后不会集体消失。
 *
 * @param int $post_id
 * @return bool
 */
function kratos_cmdk_page_visible($post_id)
{
    $raw = get_post_meta((int) $post_id, 'cmdk_show', true);
    if ($raw === '' || $raw === null) {
        return true;
    }
    return (bool) $raw;
}

/**
 * 发现可在命令面板中跳转的页面，返回 [{label, url, icon}]。
 *
 * 两部分：
 *   1. 特色页 —— 按「页面实际使用的模板」发现，用固定的中文名与图标
 *   2. 其它普通页面 —— 按菜单顺序/标题取前 N 个
 * 两部分都受每页的「在命令面板中展示」开关约束（默认展示）。
 *
 * 结果缓存 1 小时；页面保存（含改这个开关）时失效。
 */
function kratos_cmdk_pages()
{
    $key = 'kratos_cmdk_pages_v2';
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }

    $out  = array();
    $seen = array();

    // 1. 特色页（模板驱动，固定名称与图标）
    foreach (kratos_cmdk_template_map() as $tpl => $meta) {
        $pages = get_posts(array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'meta_key'         => '_wp_page_template',
            'meta_value'       => $tpl,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ));
        if (empty($pages)) {
            continue;
        }
        $pid = (int) $pages[0];
        $seen[$pid] = true;
        if (!kratos_cmdk_page_visible($pid)) {
            continue;
        }
        $out[] = array(
            'label' => $meta['label'],
            'url'   => get_permalink($pid),
            'icon'  => $meta['icon'],
        );
    }

    // 2. 其它普通页面（关于 / 留言板 / 自建页等）
    $others_max = max(0, (int) kratos_option('g_cmdk_pages_max', 30));
    if ($others_max > 0) {
        $others = get_posts(array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'posts_per_page'   => $others_max + count($seen),
            'orderby'          => array('menu_order' => 'ASC', 'title' => 'ASC'),
            'fields'           => 'ids',
            'suppress_filters' => false,
        ));
        $added = 0;
        foreach ($others as $pid) {
            $pid = (int) $pid;
            if (isset($seen[$pid]) || !kratos_cmdk_page_visible($pid)) {
                continue;
            }
            $out[] = array(
                'label' => get_the_title($pid),
                'url'   => get_permalink($pid),
                'icon'  => 'doc',
            );
            if (++$added >= $others_max) {
                break;
            }
        }
    }

    set_transient($key, $out, HOUR_IN_SECONDS);
    return $out;
}

/** 页面增删改（含改「在命令面板中展示」开关）时清掉页面清单缓存。 */
function kratos_cmdk_flush_pages_cache($post_id = 0)
{
    if ($post_id && get_post_type($post_id) !== 'page') {
        return;
    }
    delete_transient('kratos_cmdk_pages_v2');
}
add_action('save_post', 'kratos_cmdk_flush_pages_cache');
add_action('deleted_post', 'kratos_cmdk_flush_pages_cache');

/** 增量搜索 REST 路由。 */
function kratos_cmdk_register_rest()
{
    register_rest_route('kratos/v1', '/cmdk-search', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'args'                => array(
            'q' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
        'callback'            => 'kratos_cmdk_rest_search',
    ));
}
add_action('rest_api_init', 'kratos_cmdk_register_rest');

/** 增量搜索回调：返回若干条文章标题 + 链接。 */
function kratos_cmdk_rest_search($request)
{
    if (!kratos_cmdk_enabled()) {
        return new WP_REST_Response(array('items' => array()), 200);
    }

    $q = trim((string) $request->get_param('q'));
    if (mb_strlen($q) < 1) {
        return new WP_REST_Response(array('items' => array()), 200);
    }

    $limit = max(1, min(20, (int) kratos_option('g_cmdk_search_max', 8)));

    $query = new WP_Query(array(
        's'                      => $q,
        'post_type'              => array('post', 'page'),
        'post_status'            => 'publish',
        'posts_per_page'         => $limit,
        'ignore_sticky_posts'    => true,
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
    ));

    $items = array();
    foreach ($query->posts as $p) {
        $items[] = array(
            'title' => get_the_title($p),
            'url'   => get_permalink($p),
            'date'  => get_the_date('Y-m-d', $p),
            'type'  => $p->post_type === 'page' ? __('页面', 'kratos') : __('文章', 'kratos'),
        );
    }

    return new WP_REST_Response(array('items' => $items), 200);
}

/** 前端资源与配置。 */
function kratos_cmdk_enqueue()
{
    if (is_admin() || !kratos_cmdk_enabled()) {
        return;
    }

    wp_enqueue_style('kratos-command-palette', ASSET_PATH . '/assets/css/command-palette.css', array('kratos-components'), THEME_VERSION);
    wp_enqueue_script('kratos-command-palette', ASSET_PATH . '/assets/js/command-palette.js', array(), THEME_VERSION, true);

    // 皮肤命令只看本模块自己的开关（默认关闭 —— 皮肤条目多，列出来会把面板刷满）。
    // 与页脚「前端皮肤切换器」按钮完全独立：皮肤覆盖的还原由 wp_head 的 inline
    // 脚本负责，而 kratos_weekday_override_enabled() 已把本开关纳入判定，
    // 所以只开命令面板、不开页脚按钮时，选皮肤照样能持久生效。
    $skins = array();
    if (kratos_option('g_cmdk_show_skins', false) && function_exists('kratos_weekday_options')) {
        foreach (kratos_weekday_options() as $slug => $label) {
            $skins[] = array('slug' => $slug, 'label' => $label);
        }
    }

    wp_localize_script('kratos-command-palette', 'kratosCmdK', array(
        'searchEndpoint' => esc_url_raw(rest_url('kratos/v1/cmdk-search')),
        'searchUrl'      => esc_url_raw(home_url('/')),
        'home'           => esc_url_raw(home_url('/')),
        'pages'          => kratos_option('g_cmdk_show_pages', true) ? kratos_cmdk_pages() : array(),
        // 随机漫步入口：随机漫步总开关 + 本模块的展示开关（与页脚按钮开关独立）
        'stumble'        => (function_exists('kratos_stumble_url')
            && kratos_option('g_stumble', true)
            && kratos_option('g_cmdk_show_stumble', true))
            ? kratos_stumble_url() : '',
        'skins'          => $skins,
        'skinStorage'    => function_exists('kratos_weekday_switcher_storage_key')
            ? kratos_weekday_switcher_storage_key() : '',
        'skinSentinel'   => function_exists('kratos_weekday_switcher_default_sentinel')
            ? kratos_weekday_switcher_default_sentinel() : '',
        // 暗夜入口：暗夜总开关 + 本模块的展示开关。
        // 刻意不看 g_darkmode_toggle —— 后者仅决定页脚是否挂切换按钮；
        // dark.css / dark.js 在总开关打开时就已加载，切换能力始终在，
        // 所以「开了暗夜但关了页脚按钮」时，命令面板正是唯一的切换入口。
        'darkEnabled'    => (bool) kratos_option('g_darkmode', false)
            && (bool) kratos_option('g_cmdk_show_dark', true),
        'showButton'     => (bool) kratos_option('g_cmdk_button', true),
        'debounce'       => max(80, (int) kratos_option('g_cmdk_debounce', 220)),
        'i18n'           => array(
            'placeholder'  => (string) kratos_option('g_cmdk_placeholder', __('搜索文章，或输入命令…', 'kratos')),
            'hintMac'      => __('⌘K 唤出', 'kratos'),
            'hintWin'      => __('Ctrl+K 唤出', 'kratos'),
            'open'         => __('打开命令面板', 'kratos'),
            'close'        => __('关闭', 'kratos'),
            'groupSearch'  => __('搜索结果', 'kratos'),
            'groupPages'   => __('页面', 'kratos'),
            'groupActions' => __('操作', 'kratos'),
            'groupSkins'   => __('皮肤', 'kratos'),
            'searchAll'    => __('在全站搜索「%s」', 'kratos'),
            'toggleDark'   => __('切换暗色 / 亮色', 'kratos'),
            'stumble'      => __('随机漫步 · 随机一篇老文章', 'kratos'),
            'goHome'       => __('回到首页', 'kratos'),
            'skinDefault'  => __('恢复默认外观', 'kratos'),
            'empty'        => __('没有匹配的结果', 'kratos'),
            'navHint'      => __('↑↓ 选择 · Enter 打开 · Esc 关闭', 'kratos'),
        ),
    ));
}
add_action('wp_enqueue_scripts', 'kratos_cmdk_enqueue', 30);

/* ============================================================
 *  页面编辑器 Metabox：是否在命令面板展示
 * ============================================================
 * 与「特色标题」metabox 同一套写法（CSF + data_type unserialize，
 * 字段各自独立成 meta key），便于 kratos_cmdk_page_visible() 直接读。
 *
 * 注意 meta 缺失时按「展示」处理（见 kratos_cmdk_page_visible）：
 * CSF 的 switcher 只有页面保存过才会写入 meta，若把缺失当成关闭，
 * 升级本版本后所有既有页面都会从面板里消失。
 */
if (class_exists('CSF')) {
    $kratos_cmdk_prefix = '_kcmdk_meta';

    CSF::createMetabox($kratos_cmdk_prefix, array(
        'title'     => __('Kratos+ 命令面板', 'kratos'),
        'post_type' => 'page',
        'data_type' => 'unserialize',
        'context'   => 'side',
        'priority'  => 'default',
        'theme'     => 'light',
    ));

    CSF::createSection($kratos_cmdk_prefix, array(
        'fields' => array(
            array(
                'id'       => 'cmdk_show',
                'type'     => 'switcher',
                'title'    => __('在命令面板中展示', 'kratos'),
                'subtitle' => __('关闭后，这个页面不会出现在 ⌘K / Ctrl+K 命令面板的页面列表里', 'kratos'),
                'default'  => true,
            ),
        ),
    ));
}
