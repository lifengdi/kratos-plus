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

/**
 * 后台主题设置页：把 CSF 硬编码从 jsDelivr 拉的 Font Awesome 4/5 换成主题内置的
 * FA Free（FA_VERSION，assets/css/fontawesome.min.css + assets/fonts/webfonts/）。
 *
 * 为什么不是直接改 codestar-framework/classes/setup.class.php：那是 vendored 的
 * 第三方框架，升级会被覆盖，所以在这里 dequeue + deregister 掉它的三个 handle
 * （csf-fa / csf-fa5 / csf-fa5-v4-shims）另起一个本地 handle。
 *
 * 内置的 fontawesome.min.css 自带 fa-v4compatibility 字体与 .fas/.far/.fab 简写别名，
 * 因此 CSF 图标选择器里的 FA5 风格 class（`fas fa-xxx`）与 v4 旧名都能正常显示，
 * 不需要再额外加载 v4-shims。
 */
function kratos_csf_local_fontawesome()
{
    $found = false;
    foreach (array('csf-fa', 'csf-fa5', 'csf-fa5-v4-shims') as $handle) {
        if (wp_style_is($handle, 'enqueued') || wp_style_is($handle, 'registered')) {
            $found = true;
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }
    // 只在 CSF 真的要用 FA 的后台页面上补，别给所有 wp-admin 页面白加一份
    if ($found) {
        wp_enqueue_style('kratos-admin-fontawesome', get_template_directory_uri() . '/assets/css/fontawesome.min.css', array(), FA_VERSION);
    }
}
add_action('admin_enqueue_scripts', 'kratos_csf_local_fontawesome', 100);

/**
 * 后台代码编辑器字段（`type => code_editor`）改用主题内置的 CodeMirror，
 * 不再从 jsDelivr 拉 lib/ 与 addon/。
 *
 * 做法与 FA 一致：在 CSF 入队之后（它挂 admin_enqueue_scripts 默认优先级 10，见
 * setup.class.php 的 add_admin_enqueue_scripts）把 csf-codemirror /
 * csf-codemirror-loadmode 两个 handle deregister，再用同名 handle 指到本地文件。
 * 不在优先级 1 抢跑，因为本地 JS 也要依赖 `csf`（CSF 主脚本）这个 handle，
 * 那时它还没注册，依赖不满足会导致脚本干脆不输出。
 *
 * htmlmixed 模式由 loadmode 懒加载，它依赖 xml / javascript / css 三个模式先就位，
 * 所以这三份直接一起入队（缺了不会报错，但正文里的 <script>/<style> 不高亮）。
 *
 * 触发条件是「CSF 自己入队了 csf-codemirror」而不是「当前是主题选项页」：
 * CSF 的 add_admin_enqueue_scripts() 会遍历**全部**已注册字段（self::$fields）跑一遍
 * enqueue()，所以带 CSF 元框的文章编辑页等后台页面也会拉 CodeMirror。按页面 slug 卡
 * 会漏掉这些页面，仍旧走 jsDelivr。反过来 CSF 没入队的页面这里也不会白加一份。
 */
function kratos_csf_local_codemirror()
{
    $has_script = wp_script_is('csf-codemirror', 'enqueued') || wp_script_is('csf-codemirror', 'registered');
    $has_style  = wp_style_is('csf-codemirror', 'enqueued') || wp_style_is('csf-codemirror', 'registered');
    if (!$has_script && !$has_style) {
        return;
    }

    $base = kratos_cm_base_url();
    $ver  = KRATOS_CM_VERSION;

    foreach (array('csf-codemirror', 'csf-codemirror-loadmode') as $handle) {
        wp_dequeue_script($handle);
        wp_deregister_script($handle);
    }
    wp_dequeue_style('csf-codemirror');
    wp_deregister_style('csf-codemirror');

    wp_enqueue_style('csf-codemirror', $base . '/lib/codemirror.min.css', array(), $ver);
    wp_enqueue_script('csf-codemirror', $base . '/lib/codemirror.min.js', array('csf'), $ver, true);
    wp_enqueue_script('csf-codemirror-loadmode', $base . '/addon/mode/loadmode.min.js', array('csf-codemirror'), $ver, true);
    foreach (array('xml', 'javascript', 'css') as $mode) {
        wp_enqueue_script('kratos-cm-mode-' . $mode, $base . '/mode/' . $mode . '/' . $mode . '.min.js', array('csf-codemirror'), $ver, true);
    }
}
add_action('admin_enqueue_scripts', 'kratos_csf_local_codemirror', 100);

/**
 * 兜底：任何仍然指向 jsDelivr 上 codemirror@<KRATOS_CM_VERSION> 的入队 URL，
 * 一律改写到本地 assets/codemirror/（两者目录层级一致，只换前缀即可）。
 *
 * 上面的 handle 替换已经覆盖 CSF 现有代码路径，这条是防它换写法/换 handle 后又偷偷
 * 走外网 —— 命中不了就原样返回，成本只有一次 strpos。
 */
function kratos_cm_localize_src($src)
{
    $remote = 'https://cdn.jsdelivr.net/npm/codemirror@' . KRATOS_CM_VERSION;
    if (strpos($src, $remote) !== 0) {
        return $src;
    }
    return kratos_cm_base_url() . substr($src, strlen($remote));
}
add_filter('style_loader_src', 'kratos_cm_localize_src');
add_filter('script_loader_src', 'kratos_cm_localize_src');

/**
 * 元信息图标：列表页 / 文章详情页 / 特色首页的「热度、评论数、点赞数、作者、
 * 日期、字数、阅读时长」统一用图标代替文字标签。
 *
 * 图标取自主题内置的 Font Awesome Free（版本见 FA_VERSION，实体在
 * assets/css/fontawesome.min.css + assets/fonts/webfonts/），全部用 solid 风格，
 * 保证一行里的线条粗细一致。FA 由 theme_autoload() 无条件入队。
 *
 * 为什么不用主题自带的 iconfont（kicon）：它的字形墨迹没有在 em 方盒里居中，
 * 且每个字形偏移量不同（i-comments 偏下 0.089em、i-calendar 0.031em），
 * 混排时对不齐。FA solid 的墨迹中心基本落在行盒中心（实测最大偏差 0.031em），
 * 配合 .kratos-meta-icon 的 flex 居中即可与文字严格对齐。
 *
 * @param string $name  语义名：category/date/comments/views/loves/author/words/time
 * @param string $label 无障碍描述，同时作为 hover 提示（原来的文字标签放这里）
 * @return string
 */
function kratos_meta_icon($name, $label = '')
{
    $icons = array(
        'category' => 'fa-folder-open', // 分类
        'date'     => 'fa-calendar-days',
        'comments' => 'fa-comment-dots',
        'views'    => 'fa-fire',      // 热度
        'loves'    => 'fa-thumbs-up',
        'author'   => 'fa-user',
        'words'    => 'fa-file-lines', // 字数（文档 + 文字行；备选 fa-align-left / fa-paragraph / fa-font）
        'time'     => 'fa-clock',     // 阅读时长
    );

    if (!isset($icons[$name])) {
        return '';
    }

    $attr = $label !== ''
        ? ' title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '" role="img"'
        : ' aria-hidden="true"';

    return '<i class="fa-solid ' . $icons[$name] . ' kratos-meta-icon"' . $attr . '></i>';
}

/**
 * 文章 meta 项集合（分类 / 日期 / 评论数 / 热度 / 点赞 / 作者）。
 *
 * 文章列表（pages/page-content.php）与系列归档（taxonomy-kratos_series.php）共用，
 * 保证两处的项目、顺序、图标、开关（g_post_comments / g_post_views / g_post_loves /
 * g_post_author）完全一致 —— 以后加减 meta 项只改这一处。
 *
 * @param int|null $post_id 默认当前循环文章
 * @param array    $args    items：要输出的项及顺序；
 *                          link：分类是否输出为链接。系列归档整条 <li> 已被一个 <a>
 *                                包住，内部再嵌 <a> 是非法 HTML，那里必须传 false。
 * @return string
 */
function kratos_post_meta_items_html($post_id = null, $args = array())
{
    $post = $post_id ? get_post($post_id) : get_post();
    if (!$post) {
        return '';
    }

    $args = array_merge(array(
        'items' => array('category', 'date', 'comments', 'views', 'loves', 'author'),
        'link'  => true,
    ), $args);

    $out = '';
    foreach ($args['items'] as $item) {
        switch ($item) {
            case 'category':
                $cats = get_the_category($post->ID);
                $body = !empty($cats)
                    ? ($args['link']
                        ? '<a href="' . esc_url(get_category_link($cats[0]->term_id)) . '">' . esc_html($cats[0]->cat_name) . '</a>'
                        : esc_html($cats[0]->cat_name))
                    : esc_html__('页面', 'kratos');
                $out .= '<span class="kr-meta-item a-meta-item a-meta-cat">'
                    . kratos_meta_icon('category', __('分类', 'kratos')) . $body . '</span>';
                break;

            case 'date':
                $out .= '<span class="kr-meta-item a-meta-item a-meta-sm-hide">'
                    . kratos_meta_icon('date', __('发布日期', 'kratos'))
                    . esc_html(get_the_date('', $post)) . '</span>';
                break;

            case 'comments':
                if (!kratos_option('g_post_comments', true)) break;
                $out .= '<span class="kr-meta-item a-meta-item a-meta-sm-hide">'
                    . kratos_meta_icon('comments', __('条评论', 'kratos'))
                    . esc_html(number_format_i18n((int) get_comments_number($post->ID))) . '</span>';
                break;

            case 'views':
                if (!kratos_option('g_post_views', true)) break;
                $views = (int) get_post_meta($post->ID, 'views', true);
                $out .= '<span class="kr-meta-item a-meta-item">'
                    . kratos_meta_icon('views', __('点热度', 'kratos'))
                    . esc_html(number_format_i18n($views)) . '</span>';
                break;

            case 'loves':
                if (!kratos_option('g_post_loves', true)) break;
                $loves = (int) get_post_meta($post->ID, 'love', true);
                $out .= '<span class="kr-meta-item a-meta-item">'
                    . kratos_meta_icon('loves', __('人点赞', 'kratos'))
                    . esc_html(number_format_i18n($loves)) . '</span>';
                break;

            case 'author':
                if (!kratos_option('g_post_author', true)) break;
                $out .= '<span class="kr-meta-item a-meta-item">'
                    . kratos_meta_icon('author', __('作者', 'kratos'))
                    . esc_html(get_the_author_meta('display_name', $post->post_author)) . '</span>';
                break;
        }
    }
    return $out;
}
