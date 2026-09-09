<?php

/**
 * CDN 域名重写
 *
 * @license GPL-3.0
 */

defined('ABSPATH') || exit;

/**
 * 解析并缓存 CDN 配置（每请求解析一次）。
 * 返回 false 表示未启用或配置不完整。
 *
 * @param bool $reset 仅供自检用：清掉 static 缓存
 */
function kratos_cdn_cfg($reset = false)
{
    static $cfg = null;
    if ($reset) {
        $cfg = null;
        return false;
    }
    if ($cfg !== null) return $cfg;

    if (!kratos_option('g_cdn_rewrite')) return $cfg = false;

    // 只取域名（含端口）：填 cdn.example.com / //cdn.example.com /
    // https://cdn.example.com 都行，协议在重写时沿用原 URL 的
    $host = trim((string)kratos_option('g_cdn_rewrite_host', ''));
    $host = preg_replace('#^(?:https?:)?//#i', '', $host);
    $cdn_host = strtolower(trim(strtok($host, '/')));
    if ($cdn_host === '') return $cfg = false;

    $mode = kratos_option('g_cdn_rewrite_mode', 'include') === 'exclude' ? 'exclude' : 'include';

    $exts = array_filter(array_map(function ($s) {
        return strtolower(ltrim(trim($s), '.'));
    }, preg_split('/[\s,;]+/', (string)kratos_option('g_cdn_rewrite_exts', ''))));
    $exts = array_flip(array_values($exts));

    // include 模式下没填后缀就等于不生效，避免误替换整站
    if ($mode === 'include' && !$exts) return $cfg = false;

    $site_host = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
    if ($site_host === '') return $cfg = false;

    return $cfg = compact('cdn_host', 'mode', 'exts', 'site_host');
}

/** 按 include / exclude 规则判断某个 path 的后缀该不该走 CDN。 */
function kratos_cdn_ext_allowed($path, $cfg)
{
    $ext = '';
    if (($dot = strrpos($path, '.')) !== false && strrpos($path, '/') < $dot) {
        $ext = strtolower(substr($path, $dot + 1));
    }
    if ($ext === '') return false;

    $listed = isset($cfg['exts'][$ext]);
    if ($cfg['mode'] === 'include') return $listed;

    // exclude 是「列表外全部替换」，会连带命中页面与接口入口；
    // 不挡的话 .html 固链、xmlrpc.php、/feed 会被指到 CDN 而 404。
    // include 模式不设此限 —— 那是用户逐个点名的结果，尊重用户。
    $never = array('php' => 1, 'html' => 1, 'htm' => 1, 'xml' => 1, 'json' => 1, 'rss' => 1, 'atom' => 1);
    return !$listed && !isset($never[$ext]);
}

/**
 * 判断某个 URL 是否命中重写规则；命中则返回替换后的 URL，否则原样返回。
 *
 */
function kratos_cdn_rewrite_url($url)
{
    $cfg = kratos_cdn_cfg();
    if (!$cfg || !is_string($url) || $url === '') return $url;

    // 先还原转义斜杠按普通 URL 处理，返回时按原样转回
    $escaped = strpos($url, '\/') !== false;
    $plain   = $escaped ? str_replace('\/', '/', $url) : $url;

    // 已经指向 CDN：整页正则可能重复命中，保证幂等
    if (stripos($plain, '//' . $cfg['cdn_host']) !== false) return $url;

    $p = wp_parse_url($plain);
    if (!is_array($p)) return $url;

    if (!empty($p['host'])) {
        // 外站域名一律不动（含已在别的 CDN / 图床上的资源）
        if (strtolower($p['host']) !== $cfg['site_host']) return $url;
    } elseif (strpos($plain, '/') !== 0) {
        // 站内相对路径但不是根相对（如 assets/a.png），基准取决于当前文档，不动
        return $url;
    }

    $path = isset($p['path']) ? $p['path'] : '';
    if ($path === '' || !kratos_cdn_ext_allowed($path, $cfg)) return $url;

    // 协议沿用原 URL：http 站不会被硬拗成 https，根相对则输出协议相对
    $new = (isset($p['scheme']) ? $p['scheme'] . ':' : '') . '//' . $cfg['cdn_host'] . $path;
    if (isset($p['query']) && $p['query'] !== '')       $new .= '?' . $p['query'];
    if (isset($p['fragment']) && $p['fragment'] !== '') $new .= '#' . $p['fragment'];

    return $escaped ? str_replace('/', '\/', $new) : $new;
}

/**
 * 重写一段 HTML 里的资源 URL。
 *
 */
function kratos_cdn_filter_content($contents)
{
    $cfg = kratos_cdn_cfg();
    if (!$cfg || !is_string($contents) || $contents === '') return $contents;

    $ext_re = $cfg['mode'] === 'include'
        ? implode('|', array_map('preg_quote', array_keys($cfg['exts'])))
        : '[a-z0-9]{2,5}';

    $re = '#(?:(?:["\'\s=>,;]|url\()\K|^)[^"\'\s(=>,;]+\.(?:' . $ext_re . ')(\?[^\/?\\\\"\'\s)>,]+)?(?:(?=[\\\\"\'\s)>,&]|/[?"\'\s)>,])|$)#i';

    $out = preg_replace_callback($re, function ($m) {
        return kratos_cdn_rewrite_url($m[0]);
    }, $contents);

    // 回溯超限等情况下 preg_* 返回 null，此时宁可原样输出也不能丢页面
    return $out === null ? $contents : $out;
}

/**
 * 前台整页输出缓冲。
 *
 */
function kratos_cdn_start_buffer()
{
    if (!kratos_cdn_cfg()) return;
    if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') return;
    if (is_trackback() || is_robots() || is_preview()) return;

    ob_start('kratos_cdn_end_buffer');
}

/**
 * 只在最终 flush 时重写：中途 chunk 可能把一条 URL 劈成两半，
 * 对半截 URL 跑正则会把它改坏。
 */
function kratos_cdn_end_buffer($contents, $phase)
{
    if (!($phase & (PHP_OUTPUT_HANDLER_FINAL | PHP_OUTPUT_HANDLER_END))) return $contents;
    return kratos_cdn_filter_content($contents);
}

add_action('template_redirect', 'kratos_cdn_start_buffer', 1);
