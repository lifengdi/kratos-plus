<?php

/**
 * 暗夜模式
 * @author Dylan Li (Kratos+ fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 拿到暗夜模式相关的所有配置；统一在前后端注入。
 */
function kratos_darkmode_settings()
{
    $enabled = (bool) kratos_option('g_darkmode', false);
    $default = kratos_option('g_darkmode_default', 'light');
    $allowed = array('light', 'dark', 'auto', 'schedule');
    if (!in_array($default, $allowed, true)) {
        $default = 'light';
    }

    $start = kratos_darkmode_normalize_time(kratos_option('g_darkmode_start', '19:00'), '19:00');
    $end   = kratos_darkmode_normalize_time(kratos_option('g_darkmode_end', '07:00'), '07:00');

    return array(
        'enabled'   => $enabled,
        'default'   => $default,
        'start'     => $start,
        'end'       => $end,
        'toggle'    => (bool) kratos_option('g_darkmode_toggle', true),
        'remember'  => (int) kratos_option('g_darkmode_remember_days', 30),
        'storage'   => 'kratos_theme_mode',
        'storageTs' => 'kratos_theme_mode_ts',
    );
}

/**
 * 把任意输入归一化成 HH:MM；非法输入回退默认值。
 */
function kratos_darkmode_normalize_time($value, $fallback)
{
    if (!is_string($value)) {
        return $fallback;
    }
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m)) {
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
    return $fallback;
}

/**
 * 早期内联脚本：在样式表加载前把 data-theme 设到 <html> 上，避免暗夜模式下首屏白闪（FOUC）。
 * 优先级 1 早于 wp_print_styles。
 */
function kratos_darkmode_head_inline()
{
    $s = kratos_darkmode_settings();
    if (!$s['enabled']) {
        return;
    }
    $cfg = wp_json_encode(array(
        'default'   => $s['default'],
        'start'     => $s['start'],
        'end'       => $s['end'],
        'storage'   => $s['storage'],
        'storageTs' => $s['storageTs'],
        'remember'  => $s['remember'],
    ));
    echo "<script>(function(){try{var c=" . $cfg . ";var ls;try{ls=window.localStorage;}catch(e){ls=null;}var saved=null,savedTs=0;if(ls){saved=ls.getItem(c.storage);savedTs=parseInt(ls.getItem(c.storageTs)||'0',10)||0;if(saved&&c.remember>0&&savedTs){var maxAge=c.remember*86400000;if(Date.now()-savedTs>maxAge){ls.removeItem(c.storage);ls.removeItem(c.storageTs);saved=null;}}}var mode=saved;if(!mode||(mode!=='light'&&mode!=='dark')){var d=c.default;if(d==='dark'||d==='light'){mode=d;}else if(d==='auto'){mode=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}else if(d==='schedule'){var now=new Date();var cur=now.getHours()*60+now.getMinutes();var p=function(t){var x=(t||'').split(':');return (parseInt(x[0],10)||0)*60+(parseInt(x[1],10)||0);};var s=p(c.start),e=p(c.end);var inDark=(s===e)?false:(s<e?(cur>=s&&cur<e):(cur>=s||cur<e));mode=inDark?'dark':'light';}else{mode='light';}}document.documentElement.setAttribute('data-theme',mode);}catch(err){}})();</script>\n";
}
add_action('wp_head', 'kratos_darkmode_head_inline', 1);

/**
 * 入队样式 + 脚本。
 */
function kratos_darkmode_enqueue()
{
    $s = kratos_darkmode_settings();
    if (!$s['enabled'] || is_admin()) {
        return;
    }

    wp_enqueue_style(
        'kratos-darkmode',
        ASSET_PATH . '/assets/css/dark.css',
        array('kratos'),
        THEME_VERSION
    );

    wp_enqueue_script(
        'kratos-darkmode',
        ASSET_PATH . '/assets/js/dark.js',
        array(),
        THEME_VERSION,
        true
    );

    wp_localize_script('kratos-darkmode', 'kratosDarkMode', array(
        'enabled'   => true,
        'default'   => $s['default'],
        'start'     => $s['start'],
        'end'       => $s['end'],
        'toggle'    => $s['toggle'],
        'remember'  => $s['remember'],
        'storage'   => $s['storage'],
        'storageTs' => $s['storageTs'],
        'i18n'      => array(
            'toLight' => __('切换为浅色模式', 'kratos'),
            'toDark'  => __('切换为暗色模式', 'kratos'),
        ),
    ));
}
add_action('wp_enqueue_scripts', 'kratos_darkmode_enqueue', 30);
