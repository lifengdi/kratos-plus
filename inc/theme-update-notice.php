<?php

/*
 * Kratos-plus 主题后台更新提示与版本说明
 *
 * 独立于 PUC 的轻量更新模块：定期请求 GitHub Releases API，与 THEME_VERSION 比对，
 * 在「主题设置 → 版本更新」section 展示：
 *   - 当前版本 Release Note（直接内嵌渲染）
 *   - 新版本卡片（有更新时显示，支持 Thickbox 弹窗查看完整 Release Note）
 *
 * - 数据缓存：transient 12h（区分 latest / by-tag 两个 key）
 * - 手动强制刷新：管理页面加 `?kratos_check_update=1` 触发
 * - Markdown 渲染：复用 PUC 内置的 Parsedown（inc/update-checker/vendor/Parsedown.php）
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KRATOS_UPDATE_REPO', 'lifengdi/kratos-plus');
define('KRATOS_UPDATE_CACHE_KEY', 'kratos_plus_latest_release');
define('KRATOS_UPDATE_CACHE_TTL', 12 * HOUR_IN_SECONDS);

/**
 * 底层：请求 GitHub Releases API 的某个端点并缓存。
 *
 * @param string $endpoint 相对 endpoint，例如 "releases/latest" 或 "releases/tags/v1.1.1"
 * @param string $cache_key
 * @param bool   $force
 * @return array|null 解析后的 release 数据，失败返回 null
 */
function kratos_release_api($endpoint, $cache_key, $force = false)
{
    if (!$force) {
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['tag'])) {
            return $cached;
        }
        // 空数组视为失败短缓存，直接返回 null 不再发请求
        if (is_array($cached)) {
            return null;
        }
    }

    $resp = wp_remote_get(
        'https://api.github.com/repos/' . KRATOS_UPDATE_REPO . '/' . ltrim($endpoint, '/'),
        array(
            'timeout' => 8,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Kratos-Plus-Theme',
            ),
        )
    );

    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        set_transient($cache_key, array(), 10 * MINUTE_IN_SECONDS);
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data) || empty($data['tag_name'])) {
        set_transient($cache_key, array(), 10 * MINUTE_IN_SECONDS);
        return null;
    }

    $tag = (string) $data['tag_name'];
    $release = array(
        'tag'          => $tag,
        'version'      => ltrim($tag, 'vV'),
        'name'         => (string) ($data['name'] ?? $tag),
        'body'         => (string) ($data['body'] ?? ''),
        'url'          => (string) ($data['html_url'] ?? ''),
        'published_at' => (string) ($data['published_at'] ?? ''),
    );
    set_transient($cache_key, $release, KRATOS_UPDATE_CACHE_TTL);
    return $release;
}

/**
 * 拉取最新 Release。
 */
function kratos_fetch_latest_release($force = false)
{
    return kratos_release_api('releases/latest', KRATOS_UPDATE_CACHE_KEY, $force);
}

/**
 * 根据 tag 拉取指定 Release。
 */
function kratos_fetch_release_by_tag($tag, $force = false)
{
    $tag = ltrim((string) $tag, 'vV');
    $tag = 'v' . $tag;
    $key = 'kratos_plus_release_' . md5($tag);
    return kratos_release_api('releases/tags/' . rawurlencode($tag), $key, $force);
}

/**
 * 是否有新版本
 */
function kratos_has_update()
{
    $release = kratos_fetch_latest_release();
    if (!$release || empty($release['version'])) {
        return false;
    }
    return version_compare($release['version'], THEME_VERSION, '>');
}

/**
 * Markdown → HTML（Parsedown）。
 */
function kratos_render_release_body($body)
{
    $parsedown_path = get_template_directory() . '/inc/update-checker/vendor/Parsedown.php';
    if (!class_exists('Parsedown') && file_exists($parsedown_path)) {
        require_once $parsedown_path;
    }
    if (class_exists('Parsedown')) {
        $pd = new Parsedown();
        // 该版 Parsedown（PUC 内置 ParsedownModern）不支持 setSafeMode；
        // release 内容由本仓库自维护，无 XSS 风险
        if (method_exists($pd, 'setSafeMode')) {
            $pd->setSafeMode(true);
        }
        return $pd->text((string) $body);
    }
    return '<pre style="white-space:pre-wrap;">' . esc_html((string) $body) . '</pre>';
}

/**
 * 处理手动强制刷新（清缓存）—— 在管理页加载早期触发即可。
 */
add_action('admin_init', function () {
    if (!current_user_can('update_themes')) {
        return;
    }
    if (isset($_GET['kratos_check_update'])) {
        delete_transient(KRATOS_UPDATE_CACHE_KEY);
        // 清掉当前版本 tag 的缓存，让「版本更新」页面重新拉取
        $current_key = 'kratos_plus_release_' . md5('v' . THEME_VERSION);
        delete_transient($current_key);
        kratos_fetch_latest_release(true);

        // 处理完后重定向去掉 kratos_check_update 参数，避免它污染当前页面上其它
        // 以 REQUEST_URI 为基准生成的后台链接（菜单、add_query_arg 等）。
        // 302 无 fragment 时浏览器会继承请求 URL 的 fragment，tab hash 会保留。
        $redirect = remove_query_arg('kratos_check_update');
        wp_safe_redirect($redirect);
        exit;
    }
});

/**
 * Thickbox 弹窗：AJAX 端点，输出 Parsedown 渲染的 Release Note。
 * 支持通过 `tag` 参数指定版本；缺省时展示最新版。
 */
add_action('wp_ajax_kratos_release_notes', function () {
    if (!current_user_can('update_themes')) {
        wp_die(esc_html__('无权访问', 'kratos'));
    }

    $tag = isset($_GET['tag']) ? sanitize_text_field(wp_unslash($_GET['tag'])) : '';
    $release = $tag ? kratos_fetch_release_by_tag($tag) : kratos_fetch_latest_release();

    if (!$release) {
        wp_die(esc_html__('未能获取版本信息，稍后再试。', 'kratos'));
    }

    $body_html = kratos_render_release_body($release['body']);
    $published = $release['published_at']
        ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($release['published_at']))
        : '';

    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <title><?php echo esc_html($release['name']); ?></title>
        <?php wp_admin_css('common', true); ?>
        <style>
            body{margin:0;padding:20px 28px;font:14px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327;background:#fff;}
            .kratos-rn-head{margin:0 0 16px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;}
            .kratos-rn-head h1{margin:0 0 4px;font-size:20px;font-weight:600;}
            .kratos-rn-head .meta{font-size:12px;color:#646970;}
            .kratos-rn-body h1,.kratos-rn-body h2,.kratos-rn-body h3{margin:20px 0 8px;line-height:1.35;}
            .kratos-rn-body h2{font-size:17px;font-weight:600;}
            .kratos-rn-body h3{font-size:15px;font-weight:600;}
            .kratos-rn-body ul,.kratos-rn-body ol{padding-left:22px;}
            .kratos-rn-body li{margin:4px 0;}
            .kratos-rn-body code{background:#f6f7f7;padding:1px 6px;border-radius:4px;font-size:12.5px;color:#2b5278;}
            .kratos-rn-body pre{background:#f6f7f7;padding:12px 14px;border-radius:6px;overflow:auto;}
            .kratos-rn-body a{color:#2271b1;text-decoration:none;}
            .kratos-rn-body a:hover{text-decoration:underline;}
            .kratos-rn-body blockquote{margin:8px 0;padding:6px 12px;border-left:3px solid #d0d5dd;color:#4b5563;background:#fafafa;}
        </style>
    </head>
    <body>
        <div class="kratos-rn-head">
            <h1><?php echo esc_html($release['name']); ?></h1>
            <div class="meta">
                <?php
                echo esc_html('Tag: ' . $release['tag']);
                if ($published !== '') {
                    echo ' &nbsp;·&nbsp; ' . esc_html__('发布时间', 'kratos') . '：' . esc_html($published);
                }
                echo ' &nbsp;·&nbsp; <a href="' . esc_url($release['url']) . '" target="_blank" rel="noopener">GitHub</a>';
                ?>
            </div>
        </div>
        <div class="kratos-rn-body">
            <?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
    </body>
    </html>
    <?php
    exit;
});

/**
 * 生成「版本更新」section 的 HTML —— 由 CSF content 字段直接输出。
 */
function kratos_render_update_section()
{
    $current  = kratos_fetch_release_by_tag('v' . THEME_VERSION);
    $latest   = kratos_fetch_latest_release();
    $has_new  = kratos_has_update();

    // Thickbox 环境（仅一次即可）
    if (function_exists('add_thickbox')) {
        add_thickbox();
    }

    // CSF 子分区 tab id = sanitize_title(父) . '/' . sanitize_title(子)，斜杠不能一起进 sanitize_title（会被剔除）
    $refresh_url = esc_url(add_query_arg('kratos_check_update', '1'))
        . '#tab=' . sanitize_title(__('系统维护', 'kratos')) . '/' . sanitize_title(__('版本更新', 'kratos'));

    ob_start(); ?>
    <div class="kratos-vu-wrap" style="margin-top:4px;">
        <div class="kratos-vu-toolbar" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
            <span style="font-size:13px;color:#646970;">
                <?php esc_html_e('当前版本：', 'kratos'); ?>
                <code>v<?php echo esc_html(THEME_VERSION); ?></code>
                <?php if ($latest) : ?>
                    &nbsp;·&nbsp;
                    <?php esc_html_e('远端最新：', 'kratos'); ?>
                    <code>v<?php echo esc_html($latest['version']); ?></code>
                <?php endif; ?>
            </span>
            <a href="<?php echo $refresh_url; ?>" class="button button-secondary">
                <?php esc_html_e('重新检测', 'kratos'); ?>
            </a>
        </div>

        <?php if ($has_new && $latest) :
            $latest_notes_url = add_query_arg(array(
                'action'    => 'kratos_release_notes',
                'TB_iframe' => 'true',
                'width'     => 760,
                'height'    => 640,
            ), admin_url('admin-ajax.php'));
        ?>
        <div class="kratos-vu-card kratos-vu-new" style="padding:16px 20px;margin-bottom:18px;border:1px solid #c3d4e6;border-left:4px solid #2271b1;background:#f0f6fc;border-radius:8px;">
            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;">
                <strong style="font-size:15px;color:#2271b1;">
                    <?php esc_html_e('发现新版本', 'kratos'); ?>
                    v<?php echo esc_html($latest['version']); ?>
                </strong>
                <span style="color:#646970;font-size:12px;">
                    <?php echo esc_html($latest['name']); ?>
                </span>
                <span style="flex:1 1 auto;"></span>
                <a href="<?php echo esc_url($latest_notes_url); ?>" class="button button-primary thickbox" title="<?php echo esc_attr($latest['name']); ?>">
                    <?php esc_html_e('查看新版本 Release Note', 'kratos'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('themes.php')); ?>" class="button">
                    <?php esc_html_e('前往主题页面升级', 'kratos'); ?>
                </a>
                <a href="<?php echo esc_url($latest['url']); ?>" target="_blank" rel="noopener" class="button kratos-vu-gh-btn">
                    <svg aria-hidden="true" viewBox="0 0 16 16" width="14" height="14" style="vertical-align:-2px;margin-right:6px;fill:currentColor;"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2 .37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>
                    GitHub
                </a>
            </div>
        </div>
        <?php elseif ($latest) : ?>
        <div class="kratos-vu-card" style="padding:12px 16px;margin-bottom:18px;border:1px solid #d9e5cf;border-left:4px solid #46b450;background:#f4f9ec;border-radius:8px;color:#3c763d;">
            <?php esc_html_e('当前已是最新版本 🎉', 'kratos'); ?>
        </div>
        <?php endif; ?>

        <div class="kratos-vu-current" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px 22px;">
            <h3 style="margin:0 0 12px;font-size:15px;font-weight:600;color:#1d2327;">
                <?php esc_html_e('当前版本 Release Note', 'kratos'); ?>
                <span style="color:#646970;font-weight:400;font-size:13px;">
                    v<?php echo esc_html(THEME_VERSION); ?>
                </span>
            </h3>
            <?php if ($current) : ?>
                <div class="kratos-rn-body" style="font-size:13.5px;line-height:1.7;color:#2c3338;">
                    <?php echo kratos_render_release_body($current['body']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
                <p style="margin:12px 0 0;font-size:12px;color:#646970;">
                    <a href="<?php echo esc_url($current['url']); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('在 GitHub 上查看本版本 Release', 'kratos'); ?>
                    </a>
                </p>
            <?php else : ?>
                <p style="margin:0;color:#646970;">
                    <?php esc_html_e('未能获取当前版本的 Release Note（可能该版本尚未在 GitHub 发布，或网络暂不可达）。', 'kratos'); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <style>
        .kratos-vu-current .kratos-rn-body h1,
        .kratos-vu-current .kratos-rn-body h2,
        .kratos-vu-current .kratos-rn-body h3{margin:16px 0 6px;line-height:1.35;}
        .kratos-vu-current .kratos-rn-body h2{font-size:15.5px;font-weight:600;}
        .kratos-vu-current .kratos-rn-body h3{font-size:14px;font-weight:600;}
        .kratos-vu-current .kratos-rn-body ul,.kratos-vu-current .kratos-rn-body ol{padding-left:22px;}
        .kratos-vu-current .kratos-rn-body code{background:#f6f7f7;padding:1px 6px;border-radius:4px;font-size:12.5px;color:#2b5278;}
        .kratos-vu-current .kratos-rn-body a{color:#2271b1;}
        /* GitHub 按钮：仿官方 dark button，与 WP 蓝 primary 形成层次 */
        .kratos-vu-gh-btn.button{
            display:inline-flex;align-items:center;
            background:#24292f;border-color:#24292f;color:#fff;
            box-shadow:none;text-shadow:none;
        }
        .kratos-vu-gh-btn.button:hover,
        .kratos-vu-gh-btn.button:focus{
            background:#1c2128;border-color:#1c2128;color:#fff;
        }
        .kratos-vu-gh-btn.button:active{
            background:#101418;border-color:#101418;color:#fff;
        }
    </style>
    <?php
    return ob_get_clean();
}
