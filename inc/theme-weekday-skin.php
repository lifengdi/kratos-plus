<?php

/**
 * 每日皮肤（周一 ~ 周日）
 *
 * 提供三种模式：
 *   - auto    按访客本地时区，每天自动切到当天皮肤
 *   - locked  全站固定为某一套皮肤
 *   - off     不启用，回到 style.css 默认外观
 *
 * 实现策略：
 *   - 7 套皮肤的 CSS 变量集中在 assets/css/weekday-skins.css 里，按
 *     html[data-weekday-skin="mon|tue|..."] 选择器作用。
 *   - 启用后，PHP 在 wp_head 早期注入 inline script，由浏览器读取
 *     new Date().getDay() 把对应 attr 写到 <html> 上。这样在 CDN /
 *     页面缓存场景下也能按访客本地时区切换，不会被 PHP 渲染时间锁死。
 *   - locked 模式直接在 PHP 渲染阶段把 attr 写到 <html>（通过
 *     language_attributes filter），不依赖 JS。
 *
 * 与暗夜模式（inc/theme-darkmode.php）的关系：
 *   两者作用在同一个 <html> 的不同 attr 上（data-theme vs data-weekday-skin），
 *   weekday-skins.css 的所有覆盖规则用 :not([data-theme="dark"]) 兜底，
 *   暗夜模式优先。
 *
 * @author Dylan Li (Kratos+ fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** JS 端 getDay() 返回 0=周日 ~ 6=周六，这里映射到 CSS attr 用的 slug。 */
function kratos_weekday_slugs()
{
    return array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
}

/** 后台「锁定皮肤」下拉的可选值，包含中英文 label。
 *  注意：parchment 是仅供「锁定单一皮肤」使用的额外皮肤，不参与 auto 模式
 *  按 slug 索引（kratos_weekday_slugs 仍是 sun~sat 7 天）。 */
function kratos_weekday_options()
{
    return array(
        'mon' => __('周一 · 清玻', 'kratos'),
        'tue' => __('周二 · 拼贴', 'kratos'),
        'wed' => __('周三 · 凝脂', 'kratos'),
        'thu' => __('周四 · 素白', 'kratos'),
        'fri' => __('周五 · 琥珀', 'kratos'),
        'sat' => __('周六 · 海滨', 'kratos'),
        'sun' => __('周日 · 金辉', 'kratos'),
        'parchment' => __('羊皮', 'kratos'),
        'silk'      => __('黄绢', 'kratos'),
    );
}

/** 把任意输入归一化成已知 slug，未命中回退到周一。 */
function kratos_weekday_normalize_slug($value)
{
    $value = is_string($value) ? strtolower(trim($value)) : '';
    $valid = kratos_weekday_options();
    return isset($valid[$value]) ? $value : 'mon';
}

/** 读取并整理所有相关选项。 */
function kratos_weekday_settings()
{
    $mode = kratos_option('g_weekday_skin_mode', 'off');
    $allowed = array('off', 'auto', 'locked');
    if (!in_array($mode, $allowed, true)) {
        $mode = 'off';
    }

    return array(
        'mode'    => $mode,
        'locked'  => kratos_weekday_normalize_slug(kratos_option('g_weekday_skin_locked', 'mon')),
        'slugs'   => kratos_weekday_slugs(),
        'labels'  => kratos_weekday_options(),
    );
}

/**
 * 早期内联脚本：在样式表渲染前把 data-weekday-skin 写到 <html> 上，避免 FOUC。
 *
 * 本主题的 header.php 直接写死 <html lang="..."> 而非调用 language_attributes()，
 * 所以无法用 language_attributes filter 注入属性；统一改走 wp_head priority 1
 * 的 inline script 由 JS 设置 documentElement 属性。
 *
 *   - auto 模式：JS 读 new Date().getDay() 决定 slug（访客本地时区）
 *   - locked 模式：PHP 把 locked slug 序列化进 cfg，JS 原样写入
 */
function kratos_weekday_head_inline()
{
    $s = kratos_weekday_settings();
    if ($s['mode'] === 'off') {
        return;
    }
    $cfg = wp_json_encode(array(
        'mode'   => $s['mode'],
        'slugs'  => $s['slugs'],
        'locked' => $s['locked'],
    ));
    echo "<script>(function(){try{var c=" . $cfg . ";var slug=c.mode==='locked'?c.locked:c.slugs[new Date().getDay()];document.documentElement.setAttribute('data-weekday-skin',slug);}catch(e){}})();</script>\n";
}
add_action('wp_head', 'kratos_weekday_head_inline', 1);

/**
 * 入队 CSS。所有模式（auto/locked）都需要加载，仅 off 时跳过。
 */
function kratos_weekday_enqueue()
{
    if (is_admin()) {
        return;
    }
    $s = kratos_weekday_settings();
    if ($s['mode'] === 'off') {
        return;
    }
    wp_enqueue_style(
        'kratos-weekday-skin',
        ASSET_PATH . '/assets/css/weekday-skins.css',
        array('kratos'),
        THEME_VERSION
    );
}
add_action('wp_enqueue_scripts', 'kratos_weekday_enqueue', 25);

