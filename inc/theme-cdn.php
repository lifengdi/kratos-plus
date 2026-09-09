<?php

/**
 * CDN 域名重写
 *
 * 把站内静态资源 URL 的 host 换成 CDN host，按文件后缀白 / 黑名单过滤。
 *
 * 选项字段（theme-options.php 「性能与资源」）：
 *   g_cdn_rewrite        开关
 *   g_cdn_rewrite_host   CDN 域名（可带协议，可带路径前缀）
 *   g_cdn_rewrite_mode   include | exclude
 *   g_cdn_rewrite_exts   逗号 / 空格 / 换行分隔的后缀列表（不带点）
 *
 * @license GPL-3.0
 */

defined('ABSPATH') || exit;

/**
 * 解析并缓存 CDN 配置（每请求解析一次）。
 * 返回 false 表示未启用或配置不完整。
 */
function kratos_cdn_cfg()
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    if (!kratos_option('g_cdn_rewrite')) return $cfg = false;

    $host = trim((string)kratos_option('g_cdn_rewrite_host', ''));
    if ($host === '') return $cfg = false;

    // 允许填 `cdn.example.com` / `//cdn.example.com` / `https://cdn.example.com[/path]`
    if (!preg_match('#^https?://#i', $host) && strpos($host, '//') !== 0) {
        $host = '//' . $host;
    }
    $cdn_base = rtrim($host, '/');

    $mode = kratos_option('g_cdn_rewrite_mode', 'include') === 'exclude' ? 'exclude' : 'include';

    $raw  = (string)kratos_option('g_cdn_rewrite_exts', '');
    $exts = array_filter(array_map(function ($s) {
        return strtolower(ltrim(trim($s), '.'));
    }, preg_split('/[\s,;]+/', $raw)));
    $exts = array_flip(array_values($exts));

    // include 模式下没填后缀就等于不生效，避免误替换整站
    if ($mode === 'include' && !$exts) return $cfg = false;

    $site = wp_parse_url(home_url('/'));
    $site_host = isset($site['host']) ? strtolower($site['host']) : '';
    if ($site_host === '') return $cfg = false;

    return $cfg = compact('cdn_base', 'mode', 'exts', 'site_host');
}

/**
 * 判断某个 URL 是否命中重写规则；命中则返回替换后的 URL，否则原样返回。
 */
function kratos_cdn_rewrite_url($url)
{
    if (!is_string($url) || $url === '') return $url;
    $cfg = kratos_cdn_cfg();
    if (!$cfg) return $url;

    // 只处理绝对 / 协议相对 URL；相对路径不动，避免破坏站内锚点
    if (!preg_match('#^(https?:)?//#i', $url)) return $url;

    $p = wp_parse_url($url);
    if (empty($p['host']) || strtolower($p['host']) !== $cfg['site_host']) return $url;

    $path = isset($p['path']) ? $p['path'] : '';
    if ($path === '') return $url;

    // 后缀过滤：取 path 最后一段的 . 之后，忽略 query
    $ext = '';
    if (($dot = strrpos($path, '.')) !== false && strrpos($path, '/') < $dot) {
        $ext = strtolower(substr($path, $dot + 1));
    }

    $listed = $ext !== '' && isset($cfg['exts'][$ext]);
    if ($cfg['mode'] === 'include' && !$listed) return $url;
    if ($cfg['mode'] === 'exclude' && ($ext === '' || $listed)) return $url;

    $tail = $path;
    if (!empty($p['query']))    $tail .= '?' . $p['query'];
    if (!empty($p['fragment'])) $tail .= '#' . $p['fragment'];

    return $cfg['cdn_base'] . $tail;
}

/** srcset 是逗号分隔的 `url descriptor` 对，逐项重写。 */
function kratos_cdn_rewrite_srcset($srcset)
{
    if (!is_string($srcset) || strpos($srcset, '//') === false) return $srcset;
    $parts = preg_split('/\s*,\s*/', $srcset);
    foreach ($parts as &$p) {
        $seg = preg_split('/\s+/', trim($p), 2);
        if (!$seg) continue;
        $seg[0] = kratos_cdn_rewrite_url($seg[0]);
        $p = implode(' ', $seg);
    }
    return implode(', ', $parts);
}

/** wp_calculate_image_srcset 过滤器：数组形式的 srcset。 */
function kratos_cdn_filter_srcset_array($sources)
{
    if (!is_array($sources)) return $sources;
    foreach ($sources as &$s) {
        if (!empty($s['url'])) $s['url'] = kratos_cdn_rewrite_url($s['url']);
    }
    return $sources;
}

/** 正文里裸写的 <img src / srcset / <source / <video / <audio / <a href 等，用一遍正则收尾。 */
function kratos_cdn_filter_content($html)
{
    if (!is_string($html) || $html === '' || !kratos_cdn_cfg()) return $html;

    $html = preg_replace_callback(
        '#\b(src|href|data-src|data-original|poster)=(["\'])([^"\']+)\2#i',
        function ($m) {
            return $m[1] . '=' . $m[2] . kratos_cdn_rewrite_url($m[3]) . $m[2];
        },
        $html
    );
    $html = preg_replace_callback(
        '#\bsrcset=(["\'])([^"\']+)\1#i',
        function ($m) {
            return 'srcset=' . $m[1] . kratos_cdn_rewrite_srcset($m[2]) . $m[1];
        },
        $html
    );
    return $html;
}

// 静态资源入队（放行到最后，让其它 CDN/加速类过滤先跑）
add_filter('style_loader_src',  'kratos_cdn_rewrite_url', 9999);
add_filter('script_loader_src', 'kratos_cdn_rewrite_url', 9999);

// 媒体库 URL
add_filter('wp_get_attachment_url',       'kratos_cdn_rewrite_url', 9999);
add_filter('wp_get_attachment_image_src', function ($image) {
    if (is_array($image) && !empty($image[0])) $image[0] = kratos_cdn_rewrite_url($image[0]);
    return $image;
}, 9999);
add_filter('wp_calculate_image_srcset', 'kratos_cdn_filter_srcset_array', 9999);

// 正文与小工具文本
add_filter('the_content',       'kratos_cdn_filter_content', 9999);
add_filter('post_thumbnail_html', 'kratos_cdn_filter_content', 9999);
add_filter('widget_text_content', 'kratos_cdn_filter_content', 9999);
