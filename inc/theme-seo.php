<?php

/**
 * SEO / 社交分享（Open Graph、Twitter Card、JSON-LD 统一出口）
 *
 * 数据源复用 inc/theme-setting.php 中的 keywords() / description() / share_thumbnail_url()。
 *
 * @author Dylan Li <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

// 统一在 wp_head 早期输出 SEO meta，避免与其他插件抢位置
add_action('wp_head', 'kratos_seo_meta', 2);

function kratos_seo_meta()
{
    $is_front = is_home() || !have_posts();
    $og_image = $is_front ? kratos_option('seo_shareimg', ASSET_PATH . '/assets/img/default.jpg') : share_thumbnail_url();
    $og_url   = $is_front ? get_site_url() : get_the_permalink();
    $og_title = (is_home() && is_front_page()) ? get_bloginfo('name') : get_the_title();

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

// canonical：WordPress 自带 rel_canonical() 已挂在 wp_head，这里不重复输出。
// 归档/首页需要 canonical 时通过 wp_head 的默认行为覆盖，避免与 SEO 插件冲突。

add_action('wp_head', 'kratos_seo_jsonld', 3);

function kratos_seo_jsonld()
{
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
