<?php

/**
 * 响应式图片 —— 让每张 <img> 拿到与实际显示尺寸匹配的图源
 *
 * 主题里有三类图片走的不是 wp_get_attachment_image()，因此既没有 srcset
 * 也没有尺寸后缀，手机上会去下载整张原图（实测首页焦点图曾达 1.99MB PNG）：
 *
 *   1. 「文章没有特色图 → 取正文第一张图」的回落分支（列表页 / 特色首页）；
 *   2. 由 get_the_post_thumbnail_url() 拿到裸 URL 再手写 <img> 的模块
 *      （搜索结果、岁月同一天、相关文章）；
 *   3. 正文里用编辑器之外的方式插入、没有 wp-image-N class 的 <img>
 *      —— WordPress 自己的 wp_filter_content_tags() 认不出附件，不会补 srcset。
 *
 * 本模块做三件事：
 *   - 用 URL 反查附件 ID（结果缓存），把上述 1、2 改走 wp_get_attachment_image()，
 *     从而自动获得 srcset / width / height / LQIP / LCP 属性；
 *   - 给第 3 类补 srcset + sizes；
 *   - 修正 sizes：WordPress 默认写 100vw，手机会因此仍挑最大候选图。按主题各图位
 *     的真实渲染宽度给出 sizes，才真正省下字节。
 *
 * 另外可选启用 CDN 缩放：老图在注册新尺寸之前上传，本地没有对应尺寸文件，
 * WordPress 只能回落原图。此时若图床支持 URL 缩放（如火山引擎 ImageX 的处理模板），
 * 填一条模板即可把任意宽度合成出来，无需重新生成缩略图。
 *
 * @author Dylan Li (Kratos-plus) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** 总开关。 */
function kratos_img_on()
{
    return function_exists('kratos_perf_on')
        ? kratos_perf_on('g_perf_img_responsive', true)
        : true;
}

/* ============================================================
 *  URL → 附件 ID
 * ============================================================ */

/**
 * 由图片 URL 反查附件 ID。
 *
 * attachment_url_to_postid() 是一次 meta 查询，且对「带尺寸后缀」「带图床处理参数」
 * 的 URL 认不出来，所以这里先把 URL 还原成原图地址再查。
 * 能拿到文章 ID 的场合请优先用 kratos_img_first_content_image_id()，那条路
 * 只在首次渲染查一次库。
 *
 * @param string $url
 * @return int 命中的附件 ID，未命中返回 0
 */
function kratos_img_id_from_url($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return 0;
    }

    static $local = array();
    if (isset($local[$url])) {
        return $local[$url];
    }

    // 只做进程内缓存。跨请求缓存要落 transient，而无外置对象缓存时每次
    // get_transient() 本身就是一次 options 查询 —— 实测那样做首页反而 +5 条查询，
    // 等于把省下的查询又花了回去。跨请求的复用改由调用方用 post meta 承担，
    // 见 kratos_img_first_content_image_id()。
    return $local[$url] = (int) attachment_url_to_postid(kratos_img_normalize_url($url));
}

/**
 * 「文章正文第一张图」对应的附件 ID，结果记在文章 meta 上。
 *
 * URL 反查附件是一次 meta 查询，而列表页每篇文章都可能命中这条回落分支。
 * 存成 post meta 之后，第二次起随文章 meta 一起被 WordPress 批量预热，
 * 不再产生额外查询；未命中也记 0，避免反复白查。
 *
 * @param WP_Post|int $post
 * @param string      $url 已从正文里抓到的第一张图 URL
 * @return int
 */
function kratos_img_first_content_image_id($post, $url)
{
    $post_id = is_object($post) ? (int) $post->ID : (int) $post;
    if (!$post_id || $url === '') {
        return 0;
    }

    $meta = get_post_meta($post_id, '_kratos_first_img_id', true);
    if ($meta !== '') {
        return (int) $meta;
    }

    $id = kratos_img_id_from_url($url);
    update_post_meta($post_id, '_kratos_first_img_id', $id);

    return $id;
}

/** 正文改动后作废缓存的附件 ID。 */
function kratos_img_flush_first_image_id($post_id)
{
    delete_post_meta($post_id, '_kratos_first_img_id');
}
add_action('post_updated', 'kratos_img_flush_first_image_id');

/**
 * 把图片 URL 还原为「原图」地址：去查询串、去图床处理模板后缀、去 -WxH 尺寸后缀。
 *
 * @param string $url
 * @return string
 */
function kratos_img_normalize_url($url)
{
    $url = strtok((string) $url, '?');

    // ImageX 处理模板（主题选项里配置的固定后缀）直接截掉
    $tmp = kratos_img_imagex_tmp();
    if ($tmp !== '' && substr($url, -strlen($tmp)) === $tmp) {
        $url = substr($url, 0, -strlen($tmp));
    }

    // xxx-1024x768.jpg → xxx.jpg
    return preg_replace('/-\d+x\d+(?=\.[a-z0-9]{2,5}$)/i', '', $url);
}

/** ImageX 的固定处理模板后缀（未启用时为空串）。 */
function kratos_img_imagex_tmp()
{
    $set = kratos_option('g_imgx_fieldset');
    if (!is_array($set) || empty($set['g_imgx']) || empty($set['g_imgx_tmp'])) {
        return '';
    }
    return (string) $set['g_imgx_tmp'];
}

/* ============================================================
 *  CDN 缩放（可选）
 * ============================================================ */

/**
 * CDN 缩放模板，形如 `~tplv-abcd1234-image:{w}:0.{ext}`。
 * 占位符：{w} 目标宽 / {h} 目标高（不限高时模板里写 0）/ {ext} 输出格式。
 * 留空表示不启用。
 */
function kratos_img_cdn_pattern()
{
    return trim((string) kratos_option('g_perf_img_cdn_tpl', ''));
}

/** CDN 缩放要生成哪些宽度档。 */
function kratos_img_cdn_widths()
{
    return apply_filters('kratos_img_cdn_widths', array(360, 480, 720, 960, 1280, 1600));
}

/**
 * 给一个图床 URL 套上 CDN 缩放参数。
 *
 * 只处理站点上传目录（图床加速域）下的图片：外链图、主题自带的 assets 图片
 * 套上模板只会 404。
 *
 * @param string $url
 * @param int    $w   目标宽度
 * @param int    $h   目标高度，0 表示按比例
 * @param string $ext 输出格式，默认 webp
 * @return string 不满足条件时原样返回
 */
function kratos_img_cdn_resize($url, $w, $h = 0, $ext = 'webp')
{
    $pattern = kratos_img_cdn_pattern();
    if ($pattern === '' || !kratos_img_is_uploaded($url)) {
        return $url;
    }

    return kratos_img_normalize_url($url) . str_replace(
        array('{w}', '{h}', '{ext}'),
        array((int) $w, (int) $h, $ext),
        $pattern
    );
}

/** URL 是否位于本站上传目录 / 图床加速域。 */
function kratos_img_is_uploaded($url)
{
    $uploads = wp_get_upload_dir();
    if (empty($uploads['baseurl'])) {
        return false;
    }
    $base = set_url_scheme($uploads['baseurl'], 'relative');
    return strpos(set_url_scheme((string) $url, 'relative'), $base) === 0;
}

/**
 * 用 CDN 缩放合成一条 srcset。
 *
 * @param string $url
 * @param int    $max 原图宽度；>0 时不生成超过它的档位（放大没有意义）
 * @return string 未启用 CDN 缩放时返回空串
 */
function kratos_img_cdn_srcset($url, $max = 0)
{
    if (kratos_img_cdn_pattern() === '' || !kratos_img_is_uploaded($url)) {
        return '';
    }

    $parts = array();
    foreach (kratos_img_cdn_widths() as $w) {
        if ($max > 0 && $w > $max) {
            continue;
        }
        $parts[] = kratos_img_cdn_resize($url, $w) . ' ' . (int) $w . 'w';
    }
    return implode(', ', $parts);
}

/* ============================================================
 *  sizes 修正
 * ============================================================ */

/**
 * 各图位的真实渲染宽度。
 *
 * 键为注册的尺寸名；`w` 是该尺寸注册时的宽度（用于从 array(w,h) 形态反查是哪一档），
 * `sizes` 是要写进标签的 sizes 属性。
 *
 * 这里刻意把宽度写成字面量，而不是调 wp_get_registered_image_subsizes() ——
 * 那个函数会读 large_crop / medium_crop / medium_large_crop 三个非 autoload 选项，
 * 实测每个页面因此多 3 条查询。宽度的定义源是：
 *   kratos-thumbnail  → inc/theme-article.php
 *   kratos-home-lg/md → inc/theme-home-featured.php
 * 改那边的注册宽度时记得同步这里。
 */
function kratos_img_slot_sizes()
{
    static $map = null;
    if ($map === null) {
        $factor = kratos_img_mobile_factor();
        $mobile = function ($vw) use ($factor) {
            return (int) round($vw * $factor);
        };
        $map = apply_filters('kratos_img_slot_sizes', array(
            // 焦点区主推大图：桌面栏宽约 700px，手机整宽
            'kratos-home-lg'   => array('w' => 1280, 'sizes' => '(max-width: 767px) ' . $mobile(96) . 'vw, 700px'),
            // 推荐位 / 分类专区特色文卡片：桌面两列，约 380px
            'kratos-home-md'   => array('w' => 760,  'sizes' => '(max-width: 767px) ' . $mobile(96) . 'vw, 380px'),
            // 列表页缩略图：桌面固定约 260px
            'kratos-thumbnail' => array('w' => 512,  'sizes' => '(max-width: 767px) ' . $mobile(92) . 'vw, 260px'),
        ));
    }
    return $map;
}

/* ============================================================
 *  移动端画质档（DPR 上限）
 * ============================================================ */

/**
 * 移动端申报宽度的折算系数。
 *
 * 浏览器挑图按「CSS 槽位宽 × 设备像素比」算物理像素，而手机的 DPR 普遍是 3。
 * 于是 390px 宽的手机在 96vw 的槽位上会索要 1122px，跟一块 2x 桌面屏（700×2=1400）
 * 落到同一档候选图上 —— 这不是 srcset 没生效，是 3 倍屏真的要这么多像素。
 *
 * 手机上 2x 与 3x 的差别肉眼几乎分辨不出，所以这里给移动端**申报一个缩小的
 * 槽位宽度**，等效于把 DPR 上限压到 2 左右：
 *   高清 1.0 → 不折算（3x 屏拿满）
 *   均衡 0.7 → 3x 屏约等于 2.1x
 *   省流 0.5 → 3x 屏约等于 1.5x
 *
 * 只在服务端判定为移动端时折算，桌面浏览器把窗口拖窄不受影响（那时 DPR 通常是 1~2）。
 * 与「移动端不输出侧边栏」一样依赖 UA，因此开了整页缓存必须按设备分桶。
 *
 * @return float
 */
function kratos_img_mobile_factor()
{
    if (!kratos_img_on() || !wp_is_mobile()) {
        return 1.0;
    }

    switch ((string) kratos_option('g_perf_img_mobile_quality', 'balanced')) {
        case 'high':
            return 1.0;
        case 'saver':
            return 0.5;
        default:
            return 0.7;
    }
}

/**
 * 折算生效时关掉 WordPress 的 `sizes="auto"`。
 *
 * WP 6.7+ 给懒加载图片在 sizes 前插 `auto,`，支持该特性的浏览器会改用元素的
 * 实际布局宽度，我们申报的缩小值就被忽略了（照样乘 DPR 挑大图）。要压 DPR
 * 就必须让申报值生效，因此这两者只能取一个。
 */
add_filter('wp_img_tag_add_auto_sizes', function ($add) {
    return kratos_img_mobile_factor() < 1.0 ? false : $add;
});

/**
 * 取某图位的 sizes 属性。
 *
 * @param string $name 注册的尺寸名
 * @return string 未登记的尺寸返回空串
 */
function kratos_img_slot_sizes_attr($name)
{
    $map = kratos_img_slot_sizes();
    return isset($map[$name]['sizes']) ? $map[$name]['sizes'] : '';
}

/**
 * 覆盖 WordPress 默认的 `100vw` sizes。
 *
 * 默认值让手机（DPR 2~3）算出的目标宽度接近 1000px，于是仍旧挑最大候选图，
 * srcset 等于白写。按图位给出真实宽度后，390px 宽的手机只会去取 720w 档。
 */
function kratos_img_fix_sizes($sizes, $size)
{
    if (!kratos_img_on()) {
        return $sizes;
    }

    $map = kratos_img_slot_sizes();

    // wp_get_attachment_image() 内部把尺寸名折算成 array(w, h) 才调本 filter，
    // 所以这里两种形态都要认：直接按名字命中，或按宽度反查是哪一档。
    if (is_array($size)) {
        $w = isset($size[0]) ? (int) $size[0] : 0;
        if ($w <= 0) {
            return $sizes;
        }
        foreach ($map as $slot) {
            if ((int) $slot['w'] === $w) {
                return $slot['sizes'];
            }
        }
        return $sizes;
    }

    return isset($map[$size]['sizes']) ? $map[$size]['sizes'] : $sizes;
}
add_filter('wp_calculate_image_sizes', 'kratos_img_fix_sizes', 10, 2);

/* ============================================================
 *  对外主入口：由 URL 生成一个尽量优的 <img>
 * ============================================================ */

/**
 * 由图片 URL 输出 <img>。
 *
 * 能反查到附件就走 wp_get_attachment_image()（自动带 srcset / 宽高 / LQIP /
 * 首图优先级）；反查不到（外链图、附件已删）则退回手写标签，此时若配置了
 * CDN 缩放模板，仍能靠它拿到 srcset。
 *
 * @param string $url
 * @param string $size  注册过的尺寸名
 * @param array  $attrs 附加属性（alt / class / loading ...）
 * @param WP_Post|int|null $post 该图所属文章；传了就用 post meta 缓存反查结果
 * @return string
 */
function kratos_img_tag_from_url($url, $size = 'kratos-thumbnail', $attrs = array(), $post = null)
{
    $url = (string) $url;
    if ($url === '') {
        return '';
    }

    $attrs = wp_parse_args($attrs, array('loading' => 'lazy'));

    if (kratos_img_on()) {
        // 有文章上下文时走 meta 缓存版本（第二次渲染起零查询）
        $id = $post !== null
            ? kratos_img_first_content_image_id($post, $url)
            : kratos_img_id_from_url($url);
        if ($id) {
            $html = wp_get_attachment_image($id, $size, false, $attrs);
            if ($html) {
                return $html;
            }
        }
    }

    // 回落：手写标签
    $srcset = kratos_img_on() ? kratos_img_cdn_srcset($url) : '';
    if ($srcset !== '') {
        $slot_sizes      = kratos_img_slot_sizes_attr($size);
        $attrs['srcset'] = $srcset;
        $attrs['sizes']  = $slot_sizes !== '' ? $slot_sizes : '100vw';
    }
    $attrs['decoding'] = isset($attrs['decoding']) ? $attrs['decoding'] : 'async';

    $out = '<img src="' . esc_url($url) . '"';
    foreach ($attrs as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $out .= ' ' . $k . '="' . esc_attr($v) . '"';
    }
    $out .= ' />';

    return function_exists('kratos_perf_mark_img') ? kratos_perf_mark_img($out) : $out;
}

/**
 * 由图片 URL 得到「适合该图位宽度」的 URL（供只能吐 URL 的场合用，
 * 如内链预览卡的 JSON、og:image）。
 *
 * @param string $url
 * @param int    $w
 * @return string
 */
function kratos_img_url_at_width($url, $w)
{
    if (!kratos_img_on()) {
        return $url;
    }
    $resized = kratos_img_cdn_resize($url, $w);
    return $resized !== '' ? $resized : $url;
}

/* ============================================================
 *  正文里认不出附件的 <img>：补 srcset
 * ============================================================ */

/**
 * WordPress 只给带 `wp-image-N` class 的正文图片补 srcset。用其它方式
 * 插入的图片（外部编辑器、旧文章、短码拼的 HTML）会原图直出，这里按 URL
 * 反查附件补上。
 *
 * @param string $tag
 * @param string $context
 * @param int    $attachment_id
 * @return string
 */
function kratos_img_content_srcset($tag, $context, $attachment_id)
{
    if (!kratos_img_on() || strpos($tag, 'srcset=') !== false) {
        return $tag;
    }

    if (!$attachment_id && preg_match('/src=["\']([^"\']+)["\']/i', $tag, $m)) {
        $attachment_id = kratos_img_id_from_url($m[1]);
    }
    if (!$attachment_id) {
        return $tag;
    }

    $srcset = wp_get_attachment_image_srcset($attachment_id, 'large');
    if (!$srcset) {
        return $tag;
    }
    $sizes = wp_get_attachment_image_sizes($attachment_id, 'large');

    $add = ' srcset="' . esc_attr($srcset) . '"';
    if ($sizes) {
        $add .= ' sizes="' . esc_attr($sizes) . '"';
    }

    return preg_replace('/<img\s/i', '<img' . $add . ' ', $tag, 1);
}
add_filter('wp_content_img_tag', 'kratos_img_content_srcset', 15, 3);
