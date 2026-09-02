<?php

/**
 * 站点相关函数
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2023.04.05
 */

// 标题配置：核心 title-tag 的分段（title / page / tagline / site），
// 站点名与「第 N 页」由核心按同样顺序拼装，这里只补首页的副标题回落。
add_filter('document_title_parts', function ($parts) {
    if ((is_home() || is_front_page()) && empty($parts['tagline'])) {
        $tagline = get_bloginfo('description', 'display');
        if ($tagline) {
            $parts['tagline'] = $tagline;
        }
    }
    return $parts;
});

// Keywords 配置
function keywords()
{
    global $post;
    $keywords = '';
    if (is_home()) {
        $keywords = kratos_option('seo_keywords');
    } elseif (is_single()) {
        $keywords = get_post_meta($post->ID, "seo_keywords_value", true);
        if ($keywords == '') {
            $tags = wp_get_post_tags($post->ID);
            foreach ($tags as $tag) {
                $keywords = $keywords . $tag->name . ",";
            }
            $keywords = rtrim($keywords, ',');
        }
    } elseif (is_page()) {
        $keywords = get_post_meta($post->ID, "seo_keywords_value", true);
        if ($keywords == '') {
            $keywords = kratos_option('seo_keywords');
        }
    } else {
        $keywords = single_tag_title('', false);
    }
    return trim(esc_attr(strip_tags($keywords)));
}

// Description 配置
function description()
{
    global $post;
    $description = '';
    if (is_home()) {
        $description = kratos_option('seo_description');
    } elseif (is_single()) {
        $description = get_post_meta($post->ID, "seo_description_value", true);
        if ($description == '') {
            $description = get_the_excerpt();
        }
        if ($description == '') {
            $description = str_replace("\n", "", mb_strimwidth(strip_tags($post->post_content), 0, 200, "…", 'utf-8'));
        }
    } elseif (is_category()) {
        $description = category_description();
    } elseif (is_tag()) {
        $description = tag_description();
    } elseif (is_page()) {
        $description = get_post_meta($post->ID, "seo_description_value", true);
        if ($description == '') {
            $description = kratos_option('seo_description');
        }
    }
    // 作者页 / 日期归档 / 搜索页等无专属描述的场景，回落到站点描述，避免空 meta
    if (trim(strip_tags((string) $description)) === '') {
        $description = kratos_option('seo_description', get_bloginfo('description'));
    }
    return trim(esc_attr(strip_tags($description)));
}

// robots.txt 配置
add_filter('robots_txt', function ($output, $public) {
    if ('0' == $public) {
        return "User-agent: *\nDisallow: /\n";
    } else {
        $custom = kratos_option('seo_robots_fieldset')['seo_robots'] ?? '';
        if (empty($custom)) {
            return $output;
        }
        $custom = esc_attr(strip_tags($custom));
        // 核心的 WP_Sitemaps::add_robots() 挂在本 filter 的 priority 0，已经把
        // 「Sitemap: .../wp-sitemap.xml」写进 $output；自定义内容整体替换会把它一起吃掉，
        // 站点地图从此不再被声明。这里把原有的 Sitemap 行显式续回去。
        if (stripos($custom, 'sitemap:') === false && preg_match_all('/^[ \t]*Sitemap:.*$/mi', $output, $m)) {
            $custom = rtrim($custom, "\r\n") . "\n\n" . implode("\n", array_map('trim', $m[0])) . "\n";
        }
        return $custom;
    }
}, 10, 2);

// 哀悼黑白站点
function mourning()
{
    if (kratos_option('g_rip', false)) {
        echo '<style type="text/css">html{filter: grayscale(100%);-webkit-filter: grayscale(100%);-moz-filter: grayscale(100%);-ms-filter: grayscale(100%);-o-filter: grayscale(100%);filter: progid:DXImageTransform.Microsoft.BasicImage(grayscale=1);filter: gray;-webkit-filter: grayscale(1); } </style>';
    }
}

// 抓取图片链接（搜索引擎或者社交工具分享时抓取图片的链接）
function share_thumbnail_url()
{
    global $post;
    if (!is_object($post))
        return;
    if (has_post_thumbnail($post->ID)) {
        $post_thumbnail_id = get_post_thumbnail_id($post);
        // Return array|false Array of image data, or boolean false if no image is available.
        $img = wp_get_attachment_image_src($post_thumbnail_id, 'full');
        $img && $img = $img[0];
    } else {
        $content = $post->post_content;
        preg_match_all('/<img.*?(?: |\\t|\\r|\\n)?src=[\'"]?(.+?)[\'"]?(?:(?: |\\t|\\r|\\n)+.*?); ?>/sim', $content, $strResult, PREG_PATTERN_ORDER);
        if (!empty($strResult[1])) {
            $img = $strResult[1][0];
        } else {
            $img = kratos_option('seo_shareimg', ASSET_PATH . '/assets/img/default.jpg');
        }
    }
    return $img;
}

// 支持上传 svg
add_filter('upload_mimes', 'upload_svg');
function upload_svg($existing_mimes = array())
{
    $existing_mimes['svg'] = 'image/svg+xml';
    return $existing_mimes;
}

/**
 * 特色页面的分页地址：专属模板页走路径（/slug/page/2/），短代码插在别处走参数（?xx_page=2）。
 *
 * 说说 / 走心评论 / 时间轴 / 博友圈 的内容虽由短代码渲染，但用专属模板建出来的页面
 * 本身就是一个独立列表页，URL 应当和文章列表一致。而同一个短代码可以插进任意页面，
 * 甚至一页插两个（两个独立分页器），路径里放不下两个页码 —— 那种场景继续用参数。
 *
 * 路径分页要求站点启用了固定链接：朴素结构（?p=123）下没有 /page/N/ 可用。
 * 注意 Page 的 /slug/2/ 形式不能用 —— 核心 redirect_canonical() 会按正文
 * <!--nextpage--> 的分页数判定「页码越界」并 301 回第一页；/slug/page/N/ 才落到 paged。
 */
function kratos_featured_pagers()
{
    return (array) apply_filters('kratos_featured_pagers', array(
        'ss_page'  => 'page-shuoshuo.php',
        'khc_page' => 'page-heart-comments.php',
        'tl_page'  => 'page-timeline.php',
        'ffd_page' => 'page-friend-feed.php',
    ));
}

/** 当前请求是否处于「专属模板页 + 可用路径分页」状态 */
function kratos_featured_path_paging($template)
{
    return (bool) get_option('permalink_structure')
        && is_page()
        && function_exists('is_page_template')
        && is_page_template($template);
}

/** 当前页码：路径式读 paged，参数式读 $_GET[$param] */
function kratos_featured_current_page($template, $param)
{
    if (kratos_featured_path_paging($template)) {
        return max(1, (int) get_query_var('paged'));
    }

    return isset($_GET[$param]) ? max(1, (int) $_GET[$param]) : 1;
}

/**
 * 分页链接（未转义，调用方自己 esc_url）。
 *
 * @param int    $page     目标页码
 * @param string $template 专属模板文件名
 * @param string $param    参数式分页的参数名
 * @param string $anchor   锚点，形如 '#kratos-shuoshuo-feed'
 */
function kratos_featured_page_url($page, $template, $param, $anchor = '')
{
    $page = max(1, (int) $page);

    if (kratos_featured_path_paging($template)) {
        $base = get_permalink();
        $url  = $page > 1 ? user_trailingslashit(trailingslashit($base) . 'page/' . $page, 'paged') : $base;
    } else {
        $url = $page > 1 ? add_query_arg($param, $page, remove_query_arg($param)) : remove_query_arg($param);
    }

    return $url . $anchor;
}
