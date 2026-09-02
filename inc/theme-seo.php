<?php

/**
 * SEO / 社交分享（Open Graph、Twitter Card、JSON-LD 统一出口）
 *
 * 数据源复用 inc/theme-setting.php 中的 keywords() / description() / share_thumbnail_url()。
 *
 * @author Dylan Li <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

/**
 * 已装 SEO 插件（Yoast / Rank Math / AIOSEO / SEOPress）时主题整体让位。
 *
 * 这些插件都会自己输出 canonical、description、Open Graph、Twitter Card 和 JSON-LD，
 * 主题再输出一份就会出现两条 description、两个不同的 og:image、以及两份
 * BreadcrumbList —— 后者是 Search Console 明确会报冲突的重复实体。
 */
function kratos_seo_plugin_active()
{
    return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION');
}

// 统一在 wp_head 早期输出 SEO meta，避免与其他插件抢位置
add_action('wp_head', 'kratos_seo_meta', 2);

function kratos_seo_meta()
{
    if (kratos_seo_plugin_active()) {
        return;
    }

    // 只有单篇（文章/页面/CPT）才能用全局 $post 取 URL 与标题：归档页的全局 $post
    // 被 WP::register_globals() 设成了本页第一篇文章，直接用会让分类页自称某篇文章。
    $is_singular = is_singular();
    $is_front    = is_front_page() || is_home();

    $og_image = $is_singular ? share_thumbnail_url() : kratos_option('seo_shareimg', ASSET_PATH . '/assets/img/default.jpg');

    if ($is_singular) {
        $og_url   = kratos_seo_singular_self_url() ?: get_permalink();
        $og_title = get_the_title();
    } elseif ($is_front) {
        $og_url   = home_url('/');
        $og_title = get_bloginfo('name');
    } else {
        $og_url   = kratos_seo_current_url();
        $og_title = wp_get_document_title();
    }

    $desc = description();

    $og_type = 'website';
    if (is_singular('post')) {
        $og_type = 'article';
    } elseif (is_author()) {
        $og_type = 'profile';
    }

    $twitter_site = kratos_option('seo_twitter_site', '');

    echo '<meta name="keywords" itemprop="keywords" content="' . keywords() . '">' . "\n";
    echo '<meta name="description" itemprop="description" content="' . $desc . '">' . "\n";
    echo '<meta itemprop="image" content="' . esc_url($og_image) . '">' . "\n";

    echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($og_url) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
    echo '<meta property="og:locale" content="' . esc_attr(get_bloginfo('language')) . '">' . "\n";

    if (is_singular('post')) {
        global $post;
        if ($post) {
            echo '<meta property="article:published_time" content="' . esc_attr(get_the_date('c', $post)) . '">' . "\n";
            echo '<meta property="article:modified_time" content="' . esc_attr(get_the_modified_date('c', $post)) . '">' . "\n";
            $author_url = get_author_posts_url($post->post_author);
            echo '<meta property="article:author" content="' . esc_url($author_url) . '">' . "\n";
            foreach (get_the_category($post->ID) as $cat) {
                echo '<meta property="article:section" content="' . esc_attr($cat->name) . '">' . "\n";
            }
            foreach (get_the_tags($post->ID) ?: [] as $tag) {
                echo '<meta property="article:tag" content="' . esc_attr($tag->name) . '">' . "\n";
            }
        }
    }

    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    if ($twitter_site !== '') {
        echo '<meta name="twitter:site" content="' . esc_attr($twitter_site) . '">' . "\n";
    }
    echo '<meta name="twitter:title" content="' . esc_attr($og_title) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<meta name="twitter:image" content="' . esc_url($og_image) . '">' . "\n";

    if (is_single() || is_singular()) {
        global $post;
        if ($post) {
            $author_id = $post->post_author;
            echo '<meta name="twitter:creator" content="' . esc_attr(get_the_author_meta('nickname', $author_id)) . '">' . "\n";
        }
    }
}

/**
 * 当前请求的「干净」URL：保留分页，丢掉 ?replytocom / ?utm_* 之类的查询串。
 */
function kratos_seo_current_url()
{
    $paged = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));
    $url   = $paged > 1 ? get_pagenum_link($paged) : home_url(add_query_arg(array(), $GLOBALS['wp']->request));

    if (is_search()) {
        $url = get_search_link();
    }

    return $url;
}

/**
 * 特色页面的查询参数式分页（?khc_page=2 / ?ss_page / ?tl_page / ?ffd_page）。
 *
 * 这些页面是 is_singular('page')，翻页靠查询参数；第 2 页装的是**另一批内容**，
 * 不是第 1 页的重复。此时 canonical 若仍指回不带参数的页面地址（rel_canonical()
 * 的默认行为），第 2 页起会被判成重复页而整页不进索引 —— 说说、走心评论、时间轴、
 * 博友圈的历史内容就此全部收不到。所以这里让 canonical 自指、带上分页参数。
 *
 * 筛选/排序类参数（kratos_heart_filter、kfl_filter、year 等）不在此列：那是同一批
 * 内容的不同切面，带进 canonical 会把索引切碎。
 *
 * @return string 带分页参数的自指 URL；当前不在分页态时返回 ''
 */
/** 当前请求是否落在「特色页面 + 路径分页」的专属模板页上 */
function kratos_seo_is_featured_paged()
{
    if (!function_exists('kratos_featured_pagers')) {
        return false;
    }
    foreach (kratos_featured_pagers() as $template) {
        if (kratos_featured_path_paging($template)) {
            return true;
        }
    }

    return false;
}

function kratos_seo_pagination_params()
{
    return (array) apply_filters('kratos_seo_pagination_params', array('khc_page', 'ss_page', 'tl_page', 'ffd_page'));
}

function kratos_seo_singular_self_url()
{
    // 专属模板页走路径分页（/slug/page/2/）：canonical 必须带上页码，
    // 否则 rel_canonical() 会把每一页都指回第一页，第 2 页起整页判重复。
    $paged = (int) get_query_var('paged');
    if ($paged > 1 && kratos_seo_is_featured_paged()) {
        return user_trailingslashit(trailingslashit(get_permalink()) . 'page/' . $paged, 'paged');
    }

    $args = array();
    foreach (kratos_seo_pagination_params() as $key) {
        if (isset($_GET[$key]) && (int) $_GET[$key] > 1) {
            $args[$key] = (int) $_GET[$key];
        }
    }

    return $args ? add_query_arg($args, get_permalink()) : '';
}

/**
 * canonical。
 *
 * theme-core.php 移除了 WP 自带的 rel_canonical()（它也只覆盖单篇），因此这里统一输出：
 * 单篇交回核心函数处理（含评论分页 / 多页文章的 cpage、page 逻辑），归档/首页用干净 URL。
 * 已装 SEO 插件时让位（见 kratos_seo_plugin_active()），避免两条 canonical。
 */
add_action('wp_head', 'kratos_seo_canonical', 1);

function kratos_seo_canonical()
{
    if (kratos_seo_plugin_active()) {
        return;
    }

    if (is_singular()) {
        // 特色页面的参数分页要自指，其余交回核心（含 cpage / page 逻辑）
        $self = kratos_seo_singular_self_url();
        if ($self) {
            echo '<link rel="canonical" href="' . esc_url($self) . '">' . "\n";
            return;
        }
        rel_canonical();
        return;
    }

    if (is_404()) {
        return;
    }

    $url = is_front_page() && !is_paged() ? home_url('/') : kratos_seo_current_url();
    if ($url) {
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    }
}

add_action('wp_head', 'kratos_seo_jsonld', 3);

function kratos_seo_jsonld()
{
    if (kratos_seo_plugin_active()) {
        return;
    }

    $site_name = get_bloginfo('name');
    $site_url  = home_url('/');
    $logo      = kratos_option('g_icon', kratos_option('seo_shareimg', ASSET_PATH . '/assets/img/default.jpg'));
    $publisher = [
        '@type' => 'Organization',
        'name'  => $site_name,
        'logo'  => ['@type' => 'ImageObject', 'url' => $logo],
    ];

    $data = null;

    if (is_front_page() || is_home()) {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $site_name,
            'url'      => $site_url,
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => $site_url . '?s={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
    } elseif (is_singular('post')) {
        global $post;
        if ($post) {
            $img = share_thumbnail_url();
            $data = [
                '@context' => 'https://schema.org',
                '@type'    => 'BlogPosting',
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => get_permalink($post)],
                'headline'         => get_the_title($post),
                'description'      => description(),
                'image'            => $img ? [$img] : [],
                'datePublished'    => get_the_date('c', $post),
                'dateModified'     => get_the_modified_date('c', $post),
                'author'           => [
                    '@type' => 'Person',
                    'name'  => get_the_author_meta('display_name', $post->post_author),
                    'url'   => get_author_posts_url($post->post_author),
                ],
                'publisher'        => $publisher,
            ];

            $crumbs = [[
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => __('首页', 'kratos'),
                'item'     => $site_url,
            ]];
            $pos = 2;
            foreach (get_the_category($post->ID) as $cat) {
                $crumbs[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => $cat->name,
                    'item'     => get_category_link($cat->term_id),
                ];
                break;
            }
            $crumbs[] = [
                '@type'    => 'ListItem',
                'position' => $pos,
                'name'     => get_the_title($post),
                'item'     => get_permalink($post),
            ];
            kratos_seo_print_jsonld([
                '@context'        => 'https://schema.org',
                '@type'           => 'BreadcrumbList',
                'itemListElement' => $crumbs,
            ]);
        }
    } elseif (is_singular('page')) {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebPage',
            'name'     => get_the_title(),
            'url'      => get_permalink(),
            'description' => description(),
            'publisher'   => $publisher,
        ];
    } elseif (is_category() || is_tag() || is_tax() || is_archive()) {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'CollectionPage',
            'name'     => wp_get_document_title(),
            'url'      => home_url(add_query_arg([], $GLOBALS['wp']->request)),
            'description' => description(),
            'publisher'   => $publisher,
        ];
    } elseif (is_author()) {
        $author = get_queried_object();
        if ($author) {
            $data = [
                '@context' => 'https://schema.org',
                '@type'    => 'ProfilePage',
                'mainEntity' => [
                    '@type' => 'Person',
                    'name'  => $author->display_name,
                    'url'   => get_author_posts_url($author->ID),
                ],
            ];
        }
    }

    if ($data) {
        kratos_seo_print_jsonld($data);
    }
}

function kratos_seo_print_jsonld($data)
{
    echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}

/**
 * 噪声查询参数 301 回干净 URL。
 *
 * canonical 只是「提示」，?replytocom / ?utm_* 这类地址照样会被抓取、进索引、
 * 分散权重（replytocom 尤其严重：每篇文章 × 每条评论 = 一批地址）。
 * 这里在 template_redirect 阶段直接 301 到去掉这些参数后的地址，从源头掐掉。
 *
 * 注意：搜索（s）、分页（paged / page / cpage）、预览等有语义的参数一律不动。
 */
add_action('template_redirect', 'kratos_seo_strip_noise_params', 0);

function kratos_seo_strip_noise_params()
{
    if (empty($_GET) || is_admin() || is_robots() || is_feed() || is_trackback()
        || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
        || is_preview() || is_customize_preview()
        || strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'GET') {
        return;
    }

    $noise = kratos_seo_noise_keys(array_keys($_GET));
    if (!$noise) {
        return;
    }

    // add_query_arg(array()) 返回当前 REQUEST_URI（含子目录），去参后仍是站内相对地址
    $current = add_query_arg(array());
    $clean   = remove_query_arg($noise, $current);
    if ($clean && $clean !== $current) {
        wp_safe_redirect($clean, 301);
        exit;
    }
}

/**
 * 从查询参数名里挑出噪声参数。纯函数，便于单测。
 *
 * @param array $keys
 * @return array
 */
function kratos_seo_noise_keys($keys)
{
    $prefixes = array('utm_', 'spm_', 'hmsr', 'hmpl', 'hmcu', 'hmkw', 'hmci');
    $exact    = array('replytocom', 'fbclid', 'gclid', 'dclid', 'msclkid', 'yclid', 'ttclid', 'spm', 'from', 'ref', 'share_source', 'share_medium', '_ga');

    $noise = array();
    foreach ((array) $keys as $key) {
        $key = (string) $key;
        if (in_array($key, $exact, true)) {
            $noise[] = $key;
            continue;
        }
        foreach ($prefixes as $prefix) {
            if (strpos($key, $prefix) === 0) {
                $noise[] = $key;
                break;
            }
        }
    }

    return $noise;
}

/**
 * robots：搜索结果页、404、列表分页第二页起 noindex,follow。
 *
 * follow 保留，让爬虫顺着分页把文章都发现掉，只是不把「第 N 页」本身收进索引。
 * 单篇文章的多页（page）/ 评论分页（cpage）不在此列（rel_canonical 已指回主地址）。
 */
add_filter('wp_robots', 'kratos_seo_robots');

function kratos_seo_robots($robots)
{
    if (kratos_seo_plugin_active()) {
        return $robots;
    }

    // 用 paged 而不是 is_paged()：paged 只在列表/归档翻页时有值，单篇的多页正文
    // （<!--nextpage-->）与评论分页走的是 page / cpage，不该被 noindex。
    // 另外静态首页下 is_singular() 恒为 true，用它做排除会连列表分页一起放过。
    // 特色页面的路径分页（/slug/page/2/）装的是另一批内容，不是重复页，保持可索引
    if (is_search() || is_404() || ((int) get_query_var('paged') > 1 && !kratos_seo_is_featured_paged())) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
        unset($robots['index']);
    }

    return $robots;
}

/**
 * 专属模板页上的旧参数分页 301 到路径形式（?ss_page=2 → /slug/page/2/）。
 *
 * 两套地址并存本身就是重复内容，且旧链接已在外部流传，必须显式并到新地址。
 * 短代码插在其它页面时不命中此处（kratos_featured_path_paging() 为 false），仍走参数。
 */
add_action('template_redirect', 'kratos_seo_redirect_featured_paging', 2);

function kratos_seo_redirect_featured_paging()
{
    if (!is_page() || is_preview() || !function_exists('kratos_featured_pagers')
        || strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'GET') {
        return;
    }

    foreach (kratos_featured_pagers() as $param => $template) {
        if (!isset($_GET[$param]) || !kratos_featured_path_paging($template)) {
            continue;
        }
        $page = max(1, (int) $_GET[$param]);
        $url  = kratos_featured_page_url($page, $template, $param);
        if (!$url) {
            continue;
        }
        // 页面上的筛选/排序参数（kratos_heart_filter 等）要带过去，别把访客的筛选条件丢了
        $extra = array();
        foreach (wp_unslash($_GET) as $key => $value) {
            if ($key !== $param && is_scalar($value)) {
                $extra[sanitize_key($key)] = sanitize_text_field((string) $value);
            }
        }
        if ($extra) {
            $url = add_query_arg($extra, $url);
        }
        wp_redirect($url, 301);
        exit;
    }
}

/**
 * 附件页 301 到父文章（无父则到文件本体）。
 *
 * 附件页只有一张图 + 标题，是典型的薄内容，且与父文章重复；WP 为每个上传文件
 * 都生成一个可抓取地址，媒体库大的站点这部分能占到索引量的一半。
 */
add_action('template_redirect', 'kratos_seo_redirect_attachment', 1);

function kratos_seo_redirect_attachment()
{
    if (!is_attachment() || is_preview()) {
        return;
    }

    $post = get_queried_object();
    if (!$post instanceof WP_Post) {
        return;
    }

    $url = $post->post_parent ? get_permalink($post->post_parent) : wp_get_attachment_url($post->ID);
    if ($url) {
        wp_redirect($url, 301);
        exit;
    }
}

/**
 * 面包屑 JSON-LD：页面 / 分类 / 标签 / 自定义分类法 / 日期归档。
 *
 * 文章的面包屑在 kratos_seo_jsonld() 里随 BlogPosting 一起输出，这里补齐其余类型，
 * 让 SERP 上的层级路径不只在文章页出现。分页第二页起不输出（那些页面已 noindex）。
 */
add_action('wp_head', 'kratos_seo_breadcrumb_jsonld', 4);

function kratos_seo_breadcrumb_jsonld()
{
    if (kratos_seo_plugin_active() || is_paged() || is_404() || is_search() || is_front_page()) {
        return;
    }

    $trail = array();

    if (is_singular('page')) {
        $ancestors = array_reverse(get_post_ancestors(get_queried_object_id()));
        foreach ($ancestors as $id) {
            $trail[] = array(get_the_title($id), get_permalink($id));
        }
        $trail[] = array(get_the_title(), get_permalink());
    } elseif (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if (!$term instanceof WP_Term) {
            return;
        }
        foreach (array_reverse(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy')) as $id) {
            $parent = get_term($id, $term->taxonomy);
            if ($parent instanceof WP_Term) {
                $trail[] = array($parent->name, get_term_link($parent));
            }
        }
        $trail[] = array($term->name, get_term_link($term));
    } elseif (is_year() || is_month() || is_day()) {
        $y = (int) get_query_var('year');
        $m = (int) get_query_var('monthnum');
        $d = (int) get_query_var('day');
        $trail[] = array(sprintf(__('%s 年', 'kratos'), $y), get_year_link($y));
        if ($m) {
            $trail[] = array(sprintf(__('%s 月', 'kratos'), $m), get_month_link($y, $m));
        }
        if ($m && $d) {
            $trail[] = array(sprintf(__('%s 日', 'kratos'), $d), get_day_link($y, $m, $d));
        }
    } else {
        return;
    }

    $items = array(array(
        '@type'    => 'ListItem',
        'position' => 1,
        'name'     => __('首页', 'kratos'),
        'item'     => home_url('/'),
    ));
    $pos = 2;
    foreach ($trail as $crumb) {
        list($name, $url) = $crumb;
        if (!$name || is_wp_error($url) || !$url) {
            continue;
        }
        $items[] = array(
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => $name,
            'item'     => $url,
        );
    }
    if (count($items) < 2) {
        return;
    }

    kratos_seo_print_jsonld(array(
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ));
}
