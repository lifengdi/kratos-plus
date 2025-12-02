<?php

/**
 * 扩展功能
 * @author Dylan Li
 * @license GPL-3.0 License
 * @version 2025.12.02
 */

// copyright
function feed_copyright($content) {
    if(is_single() or is_feed()) {
		$sitename = get_bloginfo('name');
		$siteurl = home_url();
		$content.= '<blockquote style="margin:10px 0;font-size:16px;">除非注明，否则均为<a rel="bookmark" title="'.$sitename.'" href="'.$siteurl.'">'.$sitename.'</a>原创文章，转载必须以链接形式标明本文链接';
		$content.= '<p>本文链接：<a rel="bookmark" title="'.get_the_title().'" href="'.get_permalink().'">'.get_permalink().'</a></p></blockquote>';
    }
    return $content;
}
add_filter ('the_content', 'feed_copyright');


// 自定义分类短代码
function custom_category_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'orderby' => 'name',
        'order' => 'ASC',
        'hide_empty' => 0,
    ), $atts, 'custom_categories' );

    $categories = get_categories( $atts );
    $output = '<span class="custom-category-links">';
    $first = true;
    foreach ( $categories as $category ) {
        if (!$first) {
            $output .= ' ';
        }
        $output .= '<a href="' . get_category_link( $category->term_id ) . '" class="category-link">' . $category->name . '</a>';
        $first = false;
    }
    $output .= '</span>';
    return $output;
}
add_shortcode( 'custom_categories', 'custom_category_shortcode' );

// 自定义日期归档短代码
function custom_date_archive_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'type' => 'monthly',
        'format' => 'F Y',
        'show_post_count' => 1,
    ), $atts, 'custom_date_archive' );

    $archives = wp_get_archives( array(
        'type' => $atts['type'],
        'format' => 'custom',
        'echo' => 0,
        'before' => '',
        'after' => '',
        'show_post_count' => $atts['show_post_count']
    ) );
    $archive_links = explode( '</li>', $archives );
    $output = '<span class="custom-category-links">';
    $first = true;
    foreach ( $archive_links as $link ) {
        if ( trim( $link ) ) {
            if (!$first) {
                $output .= ' ';
            }
            $link = str_replace( '<li>', '', $link );
            $link = str_replace( '<a ', '<a class="category-link" ', $link );
            $output .= $link;
            $first = false;
        }
    }
    $output .= '</span>';
    return $output;
}
add_shortcode( 'custom_date_archive', 'custom_date_archive_shortcode' );

// 自定义标签短代码
function custom_tag_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'orderby' => 'name',
        'order' => 'ASC',
        'hide_empty' => 0,
    ), $atts, 'custom_tags' );

    $tags = get_tags( $atts );
    $output = '<span class="custom-category-links">';
    $first = true;
    foreach ( $tags as $tag ) {
        if (!$first) {
            $output .= ' ';
        }
        $output .= '<a href="' . get_tag_link( $tag->term_id ) . '" class="category-link">' . $tag->name . '</a>';
        $first = false;
    }
    $output .= '</span>';
    return $output;
}
add_shortcode( 'custom_tags', 'custom_tag_shortcode' );

// 添加 CSS 样式
function custom_archive_plugin_styles() {
    echo '<style>
        .custom-category-links .category-link {
            margin-right: 12px;
            text-decoration: none;
        }
        .custom-category-links .category-link:hover {
            text-decoration: underline;
        }
    </style>';
}
add_action( 'wp_head', 'custom_archive_plugin_styles' );