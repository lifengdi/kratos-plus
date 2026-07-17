<?php

/**
 * 核心函数
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos+ fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2024.08.05
 */

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// CDN 资源地址：开启"静态资源加速"时走 jsdelivr 上 lifengdi/kratos-plus 仓库当前 tag。
// jsdelivr 会按 GitHub Release 同步资源，发布新版后约 10 分钟内可用。
if (kratos_option('g_cdn', false)) {
    $asset_path = 'https://cdn.jsdelivr.net/gh/lifengdi/kratos-plus@v' . THEME_VERSION;
} else {
    $asset_path = get_template_directory_uri();
}
define('ASSET_PATH', $asset_path);

// 自动跳转主题设置
function init_theme()
{
    global $pagenow;
    if ('themes.php' == $pagenow && isset($_GET['activated'])) {
        wp_redirect(admin_url('admin.php?page=kratos-options'));
        exit;
    }
}
add_action('load-themes.php', 'init_theme');

// 语言国际化
function theme_languages()
{
    load_theme_textdomain('kratos', get_template_directory() . '/languages');
}
add_action('after_setup_theme', 'theme_languages');

/**
 * 自动给文章正文中的"裸 <img>"包一层 <a href="原图">，使 lightGallery
 * 的选择器（a[href$=".jpg/.png/..."]）能命中所有图片。
 *
 * 触发条件：图片的 src 后缀是 jpg/jpeg/png/gif/bmp/webp（与 kratos.js
 * lightGalleryConfig 选择器一致）；如果 <img> 已经被 <a> 包裹，跳过。
 *
 * 兼容：经典编辑器粘贴的图片、外链 <img>、Markdown 转换的 <img>。
 * Gutenberg 插入并显式选了"链接到媒体文件"的图片自带 <a>，不会被二次处理。
 */
function kratos_wrap_image_with_anchor($content)
{
    if (empty($content) || stripos($content, '<img') === false) {
        return $content;
    }

    return preg_replace_callback(
        '#(<a\b[^>]*>\s*)?(<img\b[^>]*?\bsrc=([\'"])([^\'"]+?)\3[^>]*>)(\s*</a>)?#i',
        function ($m) {
            // 已经有 <a> 包裹（前后都匹配到），直接保留
            if (!empty($m[1]) && !empty($m[5])) {
                return $m[0];
            }
            $src = $m[4];
            // 抽掉 query/fragment 再判后缀，避免被 ?x-tos-process=... 干扰
            $clean = preg_replace('/[?#].*$/', '', $src);
            if (!preg_match('/\.(jpe?g|png|gif|bmp|webp)$/i', $clean)) {
                return $m[0];
            }
            return '<a href="' . esc_url($src) . '">' . $m[2] . '</a>';
        },
        $content
    );
}
add_filter('the_content', 'kratos_wrap_image_with_anchor', 99);

// 资源加载
function theme_autoload()
{
    if (!is_admin()) {
        // css
        wp_enqueue_style('bootstrap', ASSET_PATH . '/assets/css/bootstrap.min.css', array(), '4.5.0');
        wp_enqueue_style('kicon', ASSET_PATH . '/assets/css/iconfont.min.css', array(), THEME_VERSION);
        wp_enqueue_style('layer', ASSET_PATH . '/assets/css/layer.min.css', array(), '3.1.1');
        if ((kratos_option('g_article_lightgallery', true) && is_single()) || (kratos_option('g_page_lightgallery', true) && is_page())) {
            wp_enqueue_script('lightgallery', ASSET_PATH . '/assets/js/lightgallery.min.js', array(), '1.4.0', true);
            wp_enqueue_style('lightgallery', ASSET_PATH . '/assets/css/lightgallery.min.css', array(), '1.4.0');
        }
        if (kratos_option('g_animate', false)) {
            wp_enqueue_style('animate', ASSET_PATH . '/assets/css/animate.min.css', array(), '4.1.1');
        }
        if (kratos_option('g_fontawesome', false)) {
            wp_enqueue_style('fontawesome', ASSET_PATH . '/assets/css/fontawesome.min.css', array(), '5.15.2');
        } else {
            // 页脚自定义社交图标使用 Font Awesome 时，按需加载 FA CSS
            $s_social_custom = kratos_option('s_social_custom', array());
            if (!empty($s_social_custom) && is_array($s_social_custom)) {
                foreach ($s_social_custom as $item) {
                    if (($item['icon_type'] ?? 'fontawesome') === 'fontawesome' && trim((string)($item['icon'] ?? '')) !== '' && trim((string)($item['url'] ?? '')) !== '') {
                        wp_enqueue_style('fontawesome', ASSET_PATH . '/assets/css/fontawesome.min.css', array(), '5.15.2');
                        break;
                    }
                }
            }
        }
        wp_enqueue_style('kratos', ASSET_PATH . '/style.css', array(), THEME_VERSION);
        // 短代码/特色页公共组件样式（kr-* 统一类的默认外观层）。
        // 必须在 style.css 之后、任何皮肤（kratos-weekday-skin）之前加载，
        // 皮肤文件通过依赖 kratos-components 锁定级联顺序，见 inc/theme-weekday-skin.php。
        wp_enqueue_style('kratos-components', ASSET_PATH . '/assets/css/components.css', array('kratos'), THEME_VERSION);
        if (is_child_theme()) {
            wp_enqueue_style('kratos-child', get_stylesheet_uri(), array(), wp_get_theme()->get('Version'));
        }
        // 自定义字体（功能配置 → 自定义字体 fieldset）
        $g_font = kratos_option('g_font_fieldset', array());
        if (!empty($g_font['g_font_enable'])) {
            $font_family = trim((string)($g_font['g_font_family'] ?? ''));
            $font_url = trim((string)($g_font['g_font_url'] ?? ''));
            $font_fallback = trim((string)($g_font['g_font_fallback'] ?? 'sans-serif'));
            if ($font_family !== '') {
                $stack = '"' . str_replace('"', '', $font_family) . '"' . ($font_fallback !== '' ? ', ' . $font_fallback : '');
                $css = '';
                if ($font_url !== '') {
                    $css .= '@import url("' . esc_url($font_url) . '");';
                }
                // 全站强制使用自定义字体，但排除代码块 / 图标 / 高亮 token，避免破坏 Prism / hljs / iconfont 显示
                $css .= '*:not([class*="icon"]):not(i):not(pre):not(code):not([class*="language-"]):not([class*="token"]){font-family:' . $stack . ';}';
                wp_add_inline_style('kratos', $css);
            }
        }
        if (kratos_option('g_adminbar', true)) {
            $admin_bar_css = "
            @media screen and (min-width: 782px) {
                .k-nav {
                    padding-top: 40px;
                }
            }
            @media screen and (max-width: 782px) {
                .k-nav {
                    padding-top: 54px;
                }
            }
            @media screen and (min-width: 992px) {
                .k-nav {
                    height: 102px;
                }
            }";
            if (current_user_can('level_10')) {
                wp_add_inline_style('kratos', $admin_bar_css);
            }
        }
        wp_add_inline_style('kratos', "
        @media screen and (min-width: 992px) {
            .k-nav .navbar-brand h1 {
                color: " . kratos_option('g_nav', '#ffffff') . ";
            }
            .k-nav .navbar-nav > li.nav-item > a {
                color: " . kratos_option('g_nav', '#ffffff') . ";
            }
        }
        ");
        if (kratos_option('g_sticky', false)) {
            wp_add_inline_style('kratos', '.sticky-sidebar {
                position: sticky;
                top: 8px;
                height: 100%;
            }');
        }
        $g_container_max = kratos_option('g_container_max', 1280);
        $g_container_max = ($g_container_max === '' || $g_container_max === false) ? 'none' : (max(960, intval($g_container_max)) . 'px');
        wp_add_inline_style('kratos', '@media (min-width: 1310px) { .k-header .container, .k-main > .container, .k-footer .container { max-width: ' . $g_container_max . ' !important; } }');



        // js
        wp_enqueue_script('bootstrap-bundle', ASSET_PATH . '/assets/js/bootstrap.bundle.min.js', array('jquery'), '4.5.0', true);
        wp_enqueue_script('layer', ASSET_PATH . '/assets/js/layer.min.js', array('jquery'), '3.1.1', true);
        wp_enqueue_script('dplayer', ASSET_PATH . '/assets/js/DPlayer.min.js', array(), THEME_VERSION, true);
        wp_enqueue_script('kratos', ASSET_PATH . '/assets/js/kratos.js', array('jquery'), THEME_VERSION, true);
        if (is_single()) {
            wp_enqueue_script('kratos-toc', ASSET_PATH . '/assets/js/toc.js', array(), THEME_VERSION, true);
        }

        $data = array(
            'site' => home_url(),
            'directory' => ASSET_PATH,
            'alipay' => kratos_option('g_donate_fieldset')['g_donate_alipay'] ?? '',
            'wechat' => kratos_option('g_donate_fieldset')['g_donate_wechat'] ?? '',
            'repeat' => __('您已经赞过了', 'kratos'),
            'thanks' => __('感谢您的支持', 'kratos'),
            'donate' => __('打赏作者', 'kratos'),
            'scan'   => __('扫码支付', 'kratos'),
        );
        wp_localize_script('kratos', 'kratos', $data);

        // 评论增强样式（赞踩 / 置顶 / 热门评论）
        if (is_singular() && comments_open()) {
            $kc_like_color    = kratos_option('g_comment_reactions_like_color', '#e74c3c');
            $kc_dislike_color = kratos_option('g_comment_reactions_dislike_color', '#7f8c8d');
            $kc_css = '
                .kc-vote{display:inline-flex;align-items:center;gap:8px;margin-right:10px;font-size:12px;color:#888;}
                .kc-vote a{color:#888;text-decoration:none;display:inline-flex;align-items:center;gap:2px;transition:color .2s;}
                .kc-vote a:hover{color:' . esc_attr($kc_like_color) . ';}
                .kc-vote .kc-dislike:hover{color:' . esc_attr($kc_dislike_color) . ';}
                .kc-vote a.voted.kc-like{color:' . esc_attr($kc_like_color) . ';}
                .kc-vote a.voted.kc-dislike{color:' . esc_attr($kc_dislike_color) . ';}
                .kc-vote em{font-style:normal;margin-left:2px;}
                .kc-sticky-badge{display:inline-block;margin-left:6px;padding:1px 6px;font-size:11px;line-height:1.5;color:#fff;background:#e74c3c;border-radius:3px;vertical-align:middle;}
                .comment.is-sticky{background:rgba(231,76,60,.04);border-left:3px solid #e74c3c;padding-left:8px;}
                .hot-comments,.sticky-comments{--hc-accent:var(--kr-skin-accent,#e74c3c);--hc-bg:var(--kr-skin-tag-bg,rgba(0,0,0,.03));background:var(--hc-bg);border-radius:6px;padding:12px 16px;margin-bottom:16px;margin-top:16px;}
                .sticky-comments{--hc-accent:#e67e22;}
                .hot-comments-title{font-size:16px;margin:0 0 10px;color:var(--hc-accent);}
                .hot-comments-list{list-style:none;padding:0;margin:0;}
                .hot-comments-list .comment{list-style:none;}
                .kc-fold-collapsed .hc-collapsed{display:none !important;}
                .hc-fold-toggle{list-style:none;padding:6px 0 2px;margin-left:60px;}
                .hc-fold-btn{color:var(--kr-skin-accent,#e74c3c);font-size:12px;text-decoration:none;cursor:pointer;}
                .hc-fold-btn:hover{opacity:.85;}
                .kc-fold-collapsed .hc-fold-less{display:none;}
                .comment:not(.kc-fold-collapsed) .hc-fold-more{display:none;}
                html[data-theme="dark"] .hot-comments,body.dark .hot-comments,html[data-theme="dark"] .sticky-comments,body.dark .sticky-comments{--hc-bg:rgba(255,255,255,.04);}
            ';
            wp_add_inline_style('kratos', $kc_css);
        }

        // 评论增强脚本（赞踩交互）—— 仅在单页且开启评论时加载
        if (is_singular() && comments_open() && kratos_option('g_comment_reactions_enabled', true)) {
            wp_enqueue_script('kratos-comment-enhance', ASSET_PATH . '/assets/js/comment-enhance.js', array(), THEME_VERSION, true);
            wp_localize_script('kratos-comment-enhance', 'KratosCommentEnhance', array(
                'ajax_url'       => admin_url('admin-ajax.php'),
                'nonce'          => wp_create_nonce('kratos_comment_vote'),
                'reply_collapse' => intval(kratos_option('g_comment_reply_collapse', 5)),
                'i18n_more'      => __('展开剩余 %d 条回复', 'kratos'),
                'i18n_less'      => __('收起回复', 'kratos'),
            ));
        }
    }
}
add_action('wp_enqueue_scripts', 'theme_autoload');

// 后台资源加载
function kratos_admin_enqueue()
{
    wp_enqueue_style('admin-custom-css', get_template_directory_uri() . '/assets/css/admin.css', array(), filemtime(get_template_directory() . '/assets/css/admin.css'));
}

add_action('admin_enqueue_scripts', 'kratos_admin_enqueue', 20);

// 前台管理员导航
if (!kratos_option('g_adminbar', true)) {
    add_filter('show_admin_bar', '__return_false');
}

// 移除自动保存、修订版本
if (kratos_option('g_post_revision', true)) {
    remove_action('post_updated', 'wp_save_post_revision');
}

// 添加友情链接
add_filter('pre_option_link_manager_enabled', '__return_true');

// 禁用转义
$qmr_work_tags = array('the_title', 'the_excerpt', 'single_post_title', 'comment_author', 'comment_text', 'link_description', 'bloginfo', 'wp_title', 'term_description', 'category_description', 'widget_title', 'widget_text');

foreach ($qmr_work_tags as $qmr_work_tag) {
    remove_filter($qmr_work_tag, 'wptexturize');
}

remove_filter('the_content', 'wptexturize');
add_filter('run_wptexturize', '__return_false');

// 禁用 Emoji
add_filter('emoji_svg_url', '__return_false');
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('admin_print_styles', 'print_emoji_styles');
remove_filter('the_content', 'wptexturize');
remove_filter('comment_text', 'wptexturize');
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('embed_head', 'print_emoji_detection_script');
remove_filter('the_content_feed', 'wp_staticize_emoji');
remove_filter('comment_text_rss', 'wp_staticize_emoji');
remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

// 禁用 Trackbacks
add_filter('xmlrpc_methods', function ($methods) {
    $methods['pingback.ping'] = '__return_false';
    $methods['pingback.extensions.getPingbacks'] = '__return_false';
    return $methods;
});
remove_action('do_pings', 'do_all_pings', 10);
remove_action('publish_post', '_publish_post_hook', 5);

// 优化 wp_head() 内容
foreach (array('rss2_head', 'commentsrss2_head', 'rss_head', 'rdf_header', 'atom_head', 'comments_atom_head', 'opml_head', 'app_head') as $action) {
    remove_action($action, 'the_generator');
}
remove_action('wp_head', 'wp_print_head_scripts', 9);
remove_action('wp_head', 'rel_canonical');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'feed_links_extra', 3);
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'index_rel_link');
remove_action('wp_head', 'parent_post_rel_link', 10);
remove_action('wp_head', 'start_post_rel_link', 10);
remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
remove_action('wp_head', 'wp_shortlink_wp_head', 10, 0);
remove_action('wp_head', 'rest_output_link_wp_head', 10);
remove_action('template_redirect', 'wp_shortlink_header', 11);
remove_action('template_redirect', 'rest_output_link_header', 11);

// 禁用 WordPress 拼写修正
remove_filter('the_title', 'capital_P_dangit', 11);
remove_filter('the_content', 'capital_P_dangit', 11);
remove_filter('comment_text', 'capital_P_dangit', 31);

// 禁用后台 Google Fonts
add_filter('style_loader_src', function ($href) {
    if (strpos($href, "fonts.googleapis.com") === false) {
        return $href;
    }
    return false;
});

// Gravatar 加速服务
if (kratos_option('g_replace_gravatar_url_fieldset')['g_replace_gravatar_url'] ?? true) {
    function replace_gravatar_url($avatar)
    {
        $gravatar_server_list = array(
            'geekzu' => 'sdn.geekzu.org',
            'loli' => 'gravatar.loli.net',
            'other' => kratos_option('g_replace_gravatar_url_fieldset')['g_custom_gravatar_server'] ?? null,
        );
        $gravatar_server = $gravatar_server_list[kratos_option('g_replace_gravatar_url_fieldset')['g_select_gravatar_server'] ?? 'geekzu'];
        $avatar = str_replace(array('www.gravatar.com', '0.gravatar.com', '1.gravatar.com', '2.gravatar.com', '3.gravatar.com', 'secure.gravatar.com'), $gravatar_server, $avatar);
        $avatar = str_replace('http://', 'https://', $avatar);

        return $avatar;
    }

    add_filter('get_avatar', 'replace_gravatar_url');
    add_filter('get_avatar_url', 'replace_gravatar_url');
}

// Kratos+ 主题自动更新（GitHub Release 为源，可选切换到 Gitee 下载）
//   - 版本探测仍以 GitHub Release 为准（API 稳定、限流宽松）；
//   - 实际 zip 下载 URL 可由主题选项「主题更新下载源」控制：
//       auto   ：按时区判断，Asia/Shanghai 等国内时区改写为 Gitee 下载
//       github ：强制走 GitHub Release 附件
//       gitee  ：强制走 Gitee Release 附件
//   - Gitee Release 由 Gitee Go 流水线在 tag 推送后独立构建（与 GitHub Release 使用同一份 note）。
$kratosPlusUpdater = PucFactory::buildUpdateChecker(
    'https://github.com/lifengdi/kratos-plus/',
    get_template_directory() . '/style.css',
    'kratos-plus'
);
$kratosPlusUpdater->setBranch('main');
if (method_exists($kratosPlusUpdater, 'getVcsApi')) {
    $kratosPlusUpdater->getVcsApi()->enableReleaseAssets();
}

if (!function_exists('kratos_plus_should_use_gitee')) {
    function kratos_plus_should_use_gitee()
    {
        $source = kratos_option('g_update_source', 'auto');
        if ($source === 'gitee') {
            return true;
        }
        if ($source === 'github') {
            return false;
        }
        // auto：按 WordPress 时区判断，命中国内常用时区走 Gitee
        $tz = function_exists('wp_timezone_string') ? wp_timezone_string() : '';
        $cnZones = array('Asia/Shanghai', 'Asia/Chongqing', 'Asia/Harbin', 'Asia/Urumqi', 'Asia/Hong_Kong', 'Asia/Macau', 'PRC');
        return in_array($tz, $cnZones, true);
    }
}

// 改写 PUC 返回的 download_url 到 Gitee Release 附件：
//   https://gitee.com/lifengdi/kratos-plus/releases/download/v<version>/kratos-plus-<version>.zip
// 注意：PUC 启用 enableReleaseAssets() 后，GitHub 侧的 download_url 是 api.github.com 的 asset 接口，
// 无法用 str_replace 直接改写，这里改为按版本号重建 Gitee URL。
$kratosPlusUpdater->addResultFilter(function ($info) {
    if (!kratos_plus_should_use_gitee()) {
        return $info;
    }
    if (!empty($info->version)) {
        $version = ltrim($info->version, 'v');
        $info->download_url = sprintf(
            'https://gitee.com/lifengdi/kratos-plus/releases/download/v%s/kratos-plus-%s.zip',
            $version,
            $version
        );
    }
    return $info;
});

// Gitee 会对 User-Agent 为 "WordPress/x.x" 的请求返回 403，下载 zip 时改用浏览器 UA。
add_filter('http_request_args', function ($args, $url) {
    if (is_string($url) && strpos($url, 'gitee.com/') !== false) {
        $args['user-agent'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    }
    return $args;
}, 10, 2);

// 禁止生成多种尺寸图片
if (kratos_option('g_removeimgsize', false)) {
    function remove_default_images($sizes)
    {
        unset($sizes['thumbnail']);
        unset($sizes['medium']);
        unset($sizes['large']);
        unset($sizes['full']);
        unset($sizes['medium_large']);
        unset($sizes['1536x1536']);
        unset($sizes['2048x2048']);
        return $sizes;
    }
    add_filter('intermediate_image_sizes_advanced', 'remove_default_images');

    remove_image_size('1536x1536');
    remove_image_size('2048x2048');
}
add_filter('big_image_size_threshold', '__return_false');

// 媒体文件使用 md5 值重命名，指定文件前缀
add_filter('wp_handle_sideload_prefilter', 'custom_upload_filter');
add_filter('wp_handle_upload_prefilter', 'custom_upload_filter');

function custom_upload_filter($file)
{
    $info = pathinfo($file['name']);

    $ext = '.' . $info['extension'];

    $prdfix = kratos_option('g_renameother_fieldset')['g_renameother_prdfix'] . '-';

    $img_mimes = array('jpg', 'JPG', 'jpeg', 'JPEG', 'gif', 'GIF', 'png', 'PNG', 'bmp', 'BMP', 'webp', 'WEBP', 'svg', 'SVG');

    $str = kratos_option('g_renameother_fieldset')['g_renameother_mime'];
    $arr = explode("|", $str);
    $arr = array_filter($arr);

    foreach ($arr as $value) {
        $compressed_mimes[] = $value;
    }

    if (kratos_option('g_renameimg', false)) {
        foreach ($img_mimes as $img_mime) {
            if ($info['extension'] == $img_mime) {
                $charid = strtolower(md5($file['name']));
                $hyphen = chr(45);
                $uuid = substr($charid, 0, 8) . $hyphen . substr($charid, 8, 4) . $hyphen . substr($charid, 12, 4) . $hyphen . substr($charid, 16, 4) . $hyphen . substr($charid, 20, 12);
                $file['name'] = $uuid . $ext;
            }
        }
    }

    if (kratos_option('g_renameother_fieldset')['g_renameother']) {
        foreach ($compressed_mimes as $compressed_mime) {
            if ($info['extension'] == $compressed_mime) {
                $file['name'] = $prdfix . $file['name'];
            }
        }
    }

    return $file;
}

// 仅搜索文章标题
if (kratos_option('g_search', false)) {
    add_filter('posts_search', 'search_enhancement', 10, 2);

    function search_enhancement($search, $wp_query)
    {
        if (!empty($search) && !empty($wp_query->query_vars['search_terms'])) {
            global $wpdb;

            $q = $wp_query->query_vars;
            $n = !empty($q['exact']) ? '' : '%';

            $search = array();

            foreach ((array)$q['search_terms'] as $term) {
                $search[] = $wpdb->prepare("$wpdb->posts.post_title LIKE %s", $n . $wpdb->esc_like($term) . $n);
            }

            if (!is_user_logged_in()) {
                $search[] = "$wpdb->posts.post_password = ''";
            }

            $search = ' AND ' . implode(' AND ', $search);
        }

        return $search;
    }
}
