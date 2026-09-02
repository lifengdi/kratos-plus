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
        $og_url   = get_permalink();
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
