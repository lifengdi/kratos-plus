<?php
/**
 * Font Awesome → inline SVG 渲染。
 *
 * 前台不再加载 FA 的 CSS + woff2 字体（~356KB），改为服务端查表输出 inline SVG。
 * 后台（CSF icon picker）仍加载字体版，不受影响。
 *
 * SVG path 数据来自 @fortawesome/fontawesome-free 7.3.1（MIT），
 * 按 subset 拆分为 assets/svg/fa-{solid,regular,brands}.php，
 * 每个文件返回 array('icon-name' => ['viewBox', 'pathData'])。
 */

defined('ABSPATH') || exit;

/**
 * 已加载的 subset 缓存（同一请求内不重复 require）。
 * @var array<string, array>
 */
$GLOBALS['_kratos_fa_svg_cache'] = array();

/**
 * FA v4 → v5/v6/v7 图标名映射（仅收录本主题实际用到的旧名）。
 * FA7 fontawesome.min.css 的 v4-compatibility 层也做同样的事，
 * 但我们现在不加载那份 CSS 了，所以这里显式映射。
 */
function kratos_fa_legacy_map()
{
    static $map = array(
        'fa-calendar-alt'      => 'calendar-days',
        'fa-calendar-check'    => 'calendar-check',
        'fa-cloud-download-alt'=> 'cloud-arrow-down',
        'fa-external-link-alt' => 'arrow-up-right-from-square',
        'fa-file-alt'          => 'file-lines',
        'fa-map-marked-alt'    => 'map-location-dot',
        'fa-map-marker-alt'    => 'location-dot',
        'fa-photo-video'       => 'photo-film',
        'fa-question-circle'   => 'circle-question',
        'fa-ruler-combined'    => 'ruler-combined',
        'fa-share-alt'         => 'share-nodes',
        'fa-shield-alt'        => 'shield-halved',
        'fa-sign-in-alt'       => 'right-to-bracket',
        'fa-smile-beam'        => 'face-smile-beam',
        'fa-tachometer-alt'    => 'gauge-high',
        'fa-info-circle'       => 'circle-info',
    );
    return $map;
}

/**
 * 解析 FA class 字符串，返回 [subset, iconName]。
 *
 * 支持格式：
 *   'fas fa-heart'          → ['solid', 'heart']
 *   'fa-solid fa-heart'     → ['solid', 'heart']
 *   'far fa-clock'          → ['regular', 'clock']
 *   'fab fa-weibo'          → ['brands', 'weibo']
 *   'fa fa-heart'           → ['solid', 'heart']  (v4 compat)
 */
function kratos_fa_parse_class($fa_class)
{
    $parts = preg_split('/\s+/', trim($fa_class));
    $subset = 'solid';
    $name   = '';

    foreach ($parts as $p) {
        switch ($p) {
            case 'fas': case 'fa-solid': case 'fa':
                $subset = 'solid'; break;
            case 'far': case 'fa-regular':
                $subset = 'regular'; break;
            case 'fab': case 'fa-brands':
                $subset = 'brands'; break;
            default:
                if (strpos($p, 'fa-') === 0 && $name === '') {
                    $name = $p;
                }
        }
    }

    if ($name === '') {
        return array('', '');
    }

    // v4 别名映射
    $legacy = kratos_fa_legacy_map();
    if (isset($legacy[$name])) {
        $name = $legacy[$name];
    } else {
        $name = substr($name, 3); // 去掉 'fa-' 前缀
    }

    return array($subset, $name);
}

/**
 * 加载指定 subset 的 SVG 数据（lazy，每 subset 只加载一次）。
 */
function kratos_fa_load_subset($subset)
{
    if (isset($GLOBALS['_kratos_fa_svg_cache'][$subset])) {
        return $GLOBALS['_kratos_fa_svg_cache'][$subset];
    }
    $file = get_template_directory() . '/assets/svg/fa-' . $subset . '.php';
    if (!file_exists($file)) {
        $GLOBALS['_kratos_fa_svg_cache'][$subset] = array();
        return array();
    }
    $data = require $file;
    $GLOBALS['_kratos_fa_svg_cache'][$subset] = $data;
    return $data;
}

/**
 * 把 FA class 渲染为 inline SVG。
 *
 * 返回的 <svg> 继承当前文本颜色（fill: currentColor），
 * 尺寸默认 1em（与文字行内对齐，和 <i> 版行为一致）。
 *
 * @param string $fa_class   FA class，如 'fas fa-heart'
 * @param string $extra_class 追加到 <svg> 的 CSS class（如 'kratos-meta-icon'）
 * @param array  $attrs       额外 HTML 属性（title / aria-label / aria-hidden 等）
 * @return string  SVG HTML，找不到图标则返回空字符串
 */
function kratos_fa_svg($fa_class, $extra_class = '', $attrs = array())
{
    list($subset, $name) = kratos_fa_parse_class($fa_class);
    if ($name === '') {
        return '';
    }

    $data = kratos_fa_load_subset($subset);
    if (!isset($data[$name])) {
        return '';
    }

    $icon = $data[$name];
    $viewBox = $icon[0];
    $paths   = $icon[1];

    // 构建 path 元素
    if (is_array($paths)) {
        $path_html = '';
        foreach ($paths as $d) {
            $path_html .= '<path fill="currentColor" d="' . $d . '"/>';
        }
    } else {
        $path_html = '<path fill="currentColor" d="' . $paths . '"/>';
    }

    // CSS class
    $cls = 'kratos-fa-svg';
    if ($extra_class !== '') {
        $cls .= ' ' . $extra_class;
    }

    // 额外属性
    $attr_str = '';
    $has_aria = false;
    foreach ($attrs as $k => $v) {
        $attr_str .= ' ' . $k . '="' . esc_attr($v) . '"';
        if ($k === 'aria-label' || $k === 'aria-hidden') {
            $has_aria = true;
        }
    }
    if (!$has_aria) {
        $attr_str .= ' aria-hidden="true"';
    }

    return '<svg class="' . esc_attr($cls) . '" xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewBox . '"' . $attr_str . '>' . $path_html . '</svg>';
}

/**
 * kicon 图标名 → FA class 映射。
 * 用于将主题原有的 kicon 图标统一迁移到 FA inline SVG。
 * 无 FA 对应的平台图标走 kratos_custom_svg()。
 */
function kratos_kicon_map()
{
    static $map = array(
        // UI 图标
        'i-book'       => 'fas fa-book',
        'i-tabnew'     => 'fas fa-clock',
        'i-tabhot'     => 'fas fa-fire',
        'i-tabrandom'  => 'fas fa-shuffle',
        'i-card-top'   => 'fas fa-thumbtack',
        'i-card-hot'   => 'fas fa-fire',
        'i-user'       => 'fas fa-user',
        'i-cemail'     => 'fas fa-envelope',
        'i-url'        => 'fas fa-link',
        'i-face'       => 'fas fa-smile-wink',
        'i-larrows'    => 'fas fa-angles-left',
        'i-rarrows'    => 'fas fa-angles-right',
        'i-reply'      => 'fas fa-reply',
        'i-find'       => 'fas fa-magnifying-glass',
        'i-up'         => 'fas fa-chevron-up',
        'i-like'       => 'fas fa-heart',
        'i-donate'     => 'fas fa-hand-holding-heart',
        'i-download'   => 'fas fa-download',
        'i-plus'       => 'fas fa-plus',
        'i-hot'        => 'fas fa-fire-flame-curved',
        // 社交（有 FA 对应）
        'i-github'     => 'fab fa-github',
        'i-sina'       => 'fab fa-weibo',
        'i-twitter'    => 'fab fa-x-twitter',
        'i-youtube'    => 'fab fa-youtube',
        'i-linkedin'   => 'fab fa-linkedin-in',
        'i-telegram'   => 'fab fa-telegram',
        'i-email'      => 'fas fa-envelope',
        'i-wechat'     => 'fab fa-weixin',
        'i-stackflow'  => 'fab fa-stack-overflow',
        // 社交（无 FA，走 custom）
        'i-gitee'      => 'custom:gitee',
        'i-bilibili'   => 'custom:bilibili',
        'i-coding'     => 'custom:coding',
        'i-douban'     => 'custom:douban',
    );
    return $map;
}

/**
 * FA 没有的平台图标 SVG path。
 * viewBox 统一 0 0 24 24，path 来自 Simple Icons（CC0）。
 */
function kratos_custom_icons()
{
    static $icons = array(
        'gitee' => array(
            '0 0 24 24',
            'M11.984 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.016 0zm6.09 5.333c.328 0 .593.266.592.593v1.482a.594.594 0 0 1-.593.592H9.777c-.982 0-1.778.796-1.778 1.778v5.63c0 .327.266.592.593.592h5.63c.982 0 1.778-.796 1.778-1.778v-.296a.593.593 0 0 0-.592-.593h-4.15a.592.592 0 0 1-.592-.592v-1.482a.593.593 0 0 1 .593-.592h6.815c.327 0 .593.265.593.592v3.408a4 4 0 0 1-4 4H5.926a.593.593 0 0 1-.593-.593V9.778a4.444 4.444 0 0 1 4.445-4.444h8.296z',
        ),
        'bilibili' => array(
            '0 0 24 24',
            'M7.172 2.757L10.414 6h3.171l3.243-3.242a1 1 0 0 1 1.415 1.415l-1.829 1.827L18.5 6A3.5 3.5 0 0 1 22 9.5v8a3.5 3.5 0 0 1-3.5 3.5h-13A3.5 3.5 0 0 1 2 17.5v-8A3.5 3.5 0 0 1 5.5 6h2.085L5.757 4.171a1 1 0 0 1 1.415-1.415zM18.5 8h-13a1.5 1.5 0 0 0-1.493 1.356L4 9.5v8a1.5 1.5 0 0 0 1.356 1.493L5.5 19h13a1.5 1.5 0 0 0 1.493-1.356L20 17.5v-8A1.5 1.5 0 0 0 18.5 8zM8 11a1 1 0 0 1 1 1v2a1 1 0 0 1-2 0v-2a1 1 0 0 1 1-1zm8 0a1 1 0 0 1 1 1v2a1 1 0 0 1-2 0v-2a1 1 0 0 1 1-1z',
        ),
        'coding' => array(
            '0 0 140 100',
            'M40.5464 53.1636V57.4294C40.5464 59.7873 42.4577 61.6973 44.8149 61.6973C47.1721 61.6973 49.0834 59.7873 49.0834 57.4315V53.1656C49.0834 50.8078 47.1721 48.8977 44.8149 48.8977C42.4577 48.8977 40.5464 50.8078 40.5464 53.1636ZM85.697 33.0278C82.5275 33.0278 79.4769 33.5086 76.621 34.4001C74.2782 35.1291 72.0658 36.1343 70.0294 37.3743C69.6155 37.6369 69.0091 37.6576 68.5786 37.4156C66.5132 36.155 64.2853 35.1353 61.9126 34.398C59.0587 33.5086 56.0112 33.0278 52.8438 33.0278C39.2271 32.8902 26.4724 42.5367 24.169 56.0768C21.998 67.9208 28.5916 79.7938 39.2985 85.0328C57.3792 94.2905 80.7953 94.3525 98.9308 85.1859C109.816 80.0203 116.57 68.0304 114.37 56.0602C112.058 42.5305 99.3054 32.8881 85.697 33.0278ZM137.193 62.3778C135.698 66.4575 132.02 69.4906 127.577 70.0677C125.831 70.2838 124.059 70.4007 122.26 70.4172C115.078 90.2035 92.6994 100.181 69.2678 99.9969C45.8466 100.186 23.4415 90.1984 16.2746 70.4172C14.4793 70.3997 12.7129 70.2859 10.9734 70.0698C6.43076 69.4875 2.68693 66.3355 1.25065 62.1193C-0.399825 57.3126 -0.418451 51.541 1.20512 46.7291H1.20719C2.47997 42.6474 5.98787 39.5946 10.2563 38.942C12.6715 38.5635 15.1581 38.365 17.6757 38.3784C23.901 25.2365 35.3446 16.0099 49.9371 11.2352C56.7439 9.02733 62.9494 5.33337 68.1326 0.461516C68.7018 -0.154834 69.8359 -0.154834 70.404 0.463584C72.9568 2.86176 75.7693 4.98693 78.7971 6.79151C81.8611 8.61678 85.1455 10.1153 88.5975 11.2352C103.189 16.0088 114.635 25.2375 120.859 38.3784C123.376 38.365 125.863 38.5635 128.278 38.942C132.544 39.5904 136.061 42.6577 137.327 46.7291C138.977 51.6299 138.936 57.5049 137.193 62.3778ZM97.9892 57.4294C97.9892 59.7873 96.0779 61.6973 93.7207 61.6973C91.3635 61.6973 89.4522 59.7873 89.4522 57.4315V53.1656C89.4522 50.8078 91.3635 48.8977 93.7207 48.8977C96.0779 48.8977 97.9892 50.8078 97.9892 53.1636V57.4294Z'
        ),
        'douban' => [
            '0 0 24 24',
            'M15.273 15H5V7h14v8h-1.624l-1.3 4H21v2H3v-2h4.612L6.8 16.5l1.902-.618L9.715 19h4.259l1.3-4zM3.5 3h17v2h-17V3zM7 9v4h10V9H7z'
        ],
    );
    return $icons;
}

/**
 * 渲染自定义平台图标 SVG（非 FA）。
 */
function kratos_custom_svg($name, $extra_class = '', $attrs = array())
{
    $icons = kratos_custom_icons();
    if (!isset($icons[$name])) {
        return '';
    }

    $icon    = $icons[$name];
    $viewBox = $icon[0];
    $paths   = $icon[1];

    if (is_array($paths)) {
        $path_html = '';
        foreach ($paths as $d) {
            $path_html .= '<path fill="currentColor" d="' . $d . '"/>';
        }
    } else {
        $path_html = '<path fill="currentColor" d="' . $paths . '"/>';
    }

    $cls = 'kratos-fa-svg';
    if ($extra_class !== '') {
        $cls .= ' ' . $extra_class;
    }

    $attr_str = '';
    $has_aria = false;
    foreach ($attrs as $k => $v) {
        $attr_str .= ' ' . $k . '="' . esc_attr($v) . '"';
        if ($k === 'aria-label' || $k === 'aria-hidden') {
            $has_aria = true;
        }
    }
    if (!$has_aria) {
        $attr_str .= ' aria-hidden="true"';
    }

    return '<svg class="' . esc_attr($cls) . '" xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewBox . '"' . $attr_str . '>' . $path_html . '</svg>';
}

/**
 * 统一图标接口：接受 FA class、kicon class 或 custom:name，返回 inline SVG。
 *
 * @param string $icon_class  'fas fa-heart' / 'kicon i-book' / 'i-book' / 'custom:gitee'
 * @param string $extra_class 追加到 <svg> 的 CSS class
 * @param array  $attrs       额外 HTML 属性
 * @return string
 */
function kratos_icon($icon_class, $extra_class = '', $attrs = array())
{
    $icon_class = trim($icon_class);

    // custom:name 直接走自定义
    if (strpos($icon_class, 'custom:') === 0) {
        return kratos_custom_svg(substr($icon_class, 7), $extra_class, $attrs);
    }

    // kicon class → FA 映射
    $kicon_map = kratos_kicon_map();
    // 匹配 'kicon i-xxx' 或纯 'i-xxx'
    if (preg_match('/\bi-([\w-]+)/', $icon_class, $m)) {
        $kicon_key = 'i-' . $m[1];
        if (isset($kicon_map[$kicon_key])) {
            $mapped = $kicon_map[$kicon_key];
            if (strpos($mapped, 'custom:') === 0) {
                return kratos_custom_svg(substr($mapped, 7), $extra_class, $attrs);
            }
            return kratos_fa_svg($mapped, $extra_class, $attrs);
        }
    }

    // FA class 直接渲染
    return kratos_fa_svg($icon_class, $extra_class, $attrs);
}
