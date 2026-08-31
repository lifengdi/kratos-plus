<?php

/**
 * 核心函数
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
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
        wp_enqueue_style('bootstrap', ASSET_PATH . '/assets/css/bootstrap.min.css', array(), '5.3.3');
        wp_enqueue_style('kicon', ASSET_PATH . '/assets/css/iconfont.min.css', array(), THEME_VERSION);
        wp_enqueue_style('layer', ASSET_PATH . '/assets/css/layer.min.css', array(), '3.1.1');
        if ((kratos_option('g_article_lightgallery', true) && is_single()) || (kratos_option('g_page_lightgallery', true) && is_page())) {
            wp_enqueue_script('lightgallery', ASSET_PATH . '/assets/js/lightgallery.min.js', array(), '1.4.0', true);
            wp_enqueue_style('lightgallery', ASSET_PATH . '/assets/css/lightgallery.min.css', array(), '1.4.0');
        }
        if (kratos_option('g_animate', false)) {
            wp_enqueue_style('animate', ASSET_PATH . '/assets/css/animate.min.css', array(), '4.1.1');
        }
        // Font Awesome 无条件加载：列表页 / 文章详情页 / 特色首页的元信息图标
        // （热度、评论数、点赞数、作者、日期、字数、阅读时长，见 kratos_meta_icon()）
        // 已经属于主题核心 UI，不存在开关（曾经的 g_fontawesome 选项已移除）：主题自带
        // FA Free 实体，前台/后台都用本地这一份，页脚自定义社交图标同理无需按需入队。
        wp_enqueue_style('fontawesome', ASSET_PATH . '/assets/css/fontawesome.min.css', array(), FA_VERSION);
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
            // 注意：CSF fieldset 的 'default' 只在字段首次显示时套用，用户保存过
            // 但没填过这一栏时存的是空串，故这里再兜一次空串 → 'sans-serif'。
            $font_fallback = trim((string)($g_font['g_font_fallback'] ?? ''));
            if ($font_fallback === '') { $font_fallback = 'sans-serif'; }
            if ($font_family !== '') {
                $stack = '"' . str_replace('"', '', $font_family) . '"';
                // 字体表独立入队，与主样式表并行下载，避免 @import 的串行阻塞。
                // font-display 的 swap 行为由字体表自身声明（Google Fonts / 主流 CDN 默认 swap），
                // 主题这层无法从外部改写 @font-face 描述符，只在加载路径上做优化。
                if ($font_url !== '') {
                    $font_host = wp_parse_url($font_url, PHP_URL_HOST);
                    if ($font_host) {
                        // 预连接字体域，缩短 DNS/TLS 握手（对跨域字体文件尤其有效）。
                        add_filter('wp_resource_hints', function ($hints, $relation) use ($font_host) {
                            if ($relation === 'preconnect') {
                                $hints[] = array('href' => '//' . $font_host, 'crossorigin');
                            }
                            return $hints;
                        }, 10, 2);
                    }
                    wp_enqueue_style('kratos-custom-font', $font_url, array(), null);
                }
                // 走「字体令牌头尾注入」层，两个变量都用逗号做接缝：
                //   --kr-user-font           "<用户字体>",       （尾逗号，插到皮肤栈头）
                //   --kr-user-font-fallback  , <用户 fallback>   （前导逗号，接在皮肤栈尾）
                // 皮肤内 --kr-skin-font: var(--kr-user-font,) <皮肤栈> var(--kr-user-font-fallback,);
                // 最终展开：<用户字体>, <皮肤栈>, <用户 fallback>
                // ——用户字体优先，中间落回皮肤原栈（保留皮肤观感与中文回落链），
                //   最外层再回落到用户配置的通用族（sans-serif/serif/...）。
                // 未启用自定义字体时两变量均未定义，var() 各自回退为空，皮肤栈原样。
                $css = 'html{--kr-user-font:' . $stack . ',;'
                     . '--kr-user-font-fallback:, ' . $font_fallback . ';}';
                wp_add_inline_style('kratos', $css);
            }
        }
        /*
         * 管理条（wp_admin_bar）与导航的避让。
         *
         * 核心自己会输出 `html { margin-top: 32px }`（≤782px 为 46px，见
         * wp-includes/admin-bar.php 的 _admin_bar_bump_cb），整个文档已经被下推，
         * 所以 absolute / relative 定位的 .k-nav 天然就在管理条下方 —— 这里**不能**
         * 再加 padding-top 或抬高 height，那是二次补偿，会把导航条整体撑高
         * （70px → 110px），也是「开了前台管理员导航后导航条变高」的原因。
         *
         * 真正需要补偿的只有吸顶态：.k-nav.nav-sticky 是 position:fixed，不吃
         * html 的 margin-top，会被管理条盖住，所以按管理条高度给它 top 偏移。
         * header.php 的 #k-nav-sticky-style 在 wp_head 之后输出且写了 top:0，
         * 故这里必须用 !important 才压得住。
         *
         * 判定用 is_admin_bar_showing() 而不是 current_user_can('level_10')：
         * 编辑、作者等非管理员登录后同样会看到管理条，用户在个人资料里关掉
         * 「显示工具栏」时也应当不补偿。
         */
        if (is_admin_bar_showing()) {
            wp_add_inline_style('kratos', "
            @media screen and (min-width: 783px) {
                .k-nav.nav-sticky { top: 32px !important; }
            }
            @media screen and (max-width: 782px) {
                .k-nav.nav-sticky { top: 46px !important; }
            }");
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
        wp_enqueue_script('bootstrap-bundle', ASSET_PATH . '/assets/js/bootstrap.bundle.min.js', array(), '5.3.3', true);
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

/**
 * 主题设置页的两处交互补丁（CSF 框架本身没有，写在这里避免改 vendored 源码）：
 *
 * 1. 侧栏吸顶的偏移量：把顶部条（.csf-header-inner）的实测高度写进 CSS 变量
 *    --kratos-csf-header-h，供 assets/css/admin.css 的 .csf-nav-normal 计算
 *    sticky 的 top 与 max-height。为什么不在 CSS 里写死：顶部条高度受标题字号、
 *    后台语言换行、浏览器缩放影响，写死会差出十几像素，表现为侧栏第一项被压在
 *    顶部条底下。顶部条 sticky 后变成 position:fixed，但高度不变，随时可测。
 *
 * 2. 切换菜单时把内容区滚回顶部：CSF 换 section 只是 show/hide DOM，不动滚动位置，
 *    所以在页面中段点另一个菜单，新 section 是从中间露出来的。
 */
function kratos_csf_sticky_offset_script()
{
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    if ($page !== 'kratos-options') {
        return;
    }
    ?>
    <script>
    (function () {
        function sync() {
            var inner = document.querySelector('.csf-options .csf-header-inner');
            if (!inner) {
                return;
            }
            // 用 getBoundingClientRect 而不是 offsetHeight：后者取整，缩放比例非 100%
            // 时会差出小数，表现为侧栏和顶部条之间留一条发丝缝或压掉 1px
            document.documentElement.style.setProperty('--kratos-csf-header-h', inner.getBoundingClientRect().height + 'px');
        }
        sync();
        window.addEventListener('resize', sync);
        // 顶部条里的「未保存」提示条出现/消失会改变高度，用 observer 跟一下
        var target = document.querySelector('.csf-options .csf-header-inner');
        if (target && window.ResizeObserver) {
            new ResizeObserver(sync).observe(target);
        }

        // 接管 WP 左侧菜单的吸顶：解绑 core 的 pin-menu，改用自己的一套定位
        //
        // core 的逻辑（wp-admin/js/common.js 的 setPinMenu / pinMenu）分三档：
        //   菜单 + 管理条 < 视口          → body.sticky-menu（position:fixed），稳定
        //   菜单高于视口且页面够长        → pinMenu() 跟着滚动方向在 fixed(top/bottom:0)
        //                                  与 absolute(top:N) 之间来回切，写行内样式
        //   其余                          → unpinMenu() 清空行内样式，菜单随页面滚
        //
        // 它有两个已知的坑，本页两个都会踩：
        //   1. `height.menu + height.adminbar + 20 > height.wpwrap` 这道门槛 —— 页面一旦不
        //      比菜单长多少就直接 unpin。CSF 切到内容少的分区时 #wpwrap 变矮，行内的
        //      position:fixed;bottom:0 就被清掉了，看起来就是"菜单突然不吸顶了"。
        //   2. 高度只在 resetHeights() 里更新，触发点是 scroll / resize / 几个后台事件。
        //      CSF 换分区只是 show/hide DOM，一个都不触发，于是它拿着过期高度做判断，
        //      表现更像随机丢样式。
        //
        // 所以这里整段自己实现：菜单装得下就用 core 的 .sticky-menu，装不下就按滚动方向
        // 平移 fixed 的 top（向下滚到底 = 菜单底边贴视口底，等效于 core 的 bottom:0；向上滚
        // 回去 = 菜单顶边贴管理条下沿）。每次都现场量高度，不缓存，也就没有过期问题。
        // 注意：不给 #adminmenuwrap 开内部滚动来解决"菜单比视口高"——子菜单是
        // position:absolute 的悬浮飞出层，父级 overflow 非 visible 会把它们裁掉，
        // Windows 的实体滚动条下还会多出横向滚动条（详见 assets/css/admin.css）。
        if (window.jQuery) {
            // 必须在 ready 回调里做：common.js 在 <head> 加载、其 ready 回调先注册先执行，
            // 本脚本在页脚，若在解析期就 off，那时 core 还没绑上，等于没解绑。
            window.jQuery(function ($) {
                var wrap = document.getElementById('adminmenuwrap'),
                    $body = $('body'),
                    desktop = window.matchMedia ? window.matchMedia('(min-width: 783px)') : null,
                    curTop = null,      // 当前 fixed 的 top（null = 没在接管）
                    lastPos = window.pageYOffset || 0;

                if (!wrap) {
                    return;
                }

                // core 把 setPinMenu 绑在一长串事件上（common.js 末尾）：
                //   wp-pin-menu wp-window-resized.pin-menu postboxes-columnchange.pin-menu
                //   postbox-toggled.pin-menu wp-collapse-menu.pin-menu wp-scroll-start.pin-menu
                // 注意 `wp-pin-menu` **没有命名空间**，off('.pin-menu') 摘不掉，要单独 off；
                // 漏掉任何一个，它就会在折叠菜单、开关 metabox 等时机再跑一遍，把下面写的
                // 定位覆盖掉。scroll.pin-menu 绑在 window 上，也要一起摘。
                $(window).off('.pin-menu');
                $(document).off('.pin-menu').off('wp-pin-menu');

                function setStyle(position, top) {
                    // 只在值真的变了才写，避免和下面的 MutationObserver 互相触发
                    if (wrap.style.position !== position) {
                        wrap.style.position = position;
                    }
                    if (wrap.style.top !== top) {
                        wrap.style.top = top;
                    }
                    if (wrap.style.bottom !== '') {
                        wrap.style.bottom = '';
                    }
                }

                function update() {
                    var responsive = (desktop && !desktop.matches) || !!$('#adminmenu').data('wp-responsive');

                    // 窄屏是 core 的滑出式菜单，别插手，把自己写的定位撤掉
                    if (responsive) {
                        curTop = null;
                        setStyle('', '');
                        return;
                    }

                    var bar    = document.getElementById('wpadminbar'),
                        barH   = bar ? bar.getBoundingClientRect().height : 0,
                        menuH  = wrap.getBoundingClientRect().height,
                        winH   = window.innerHeight,
                        pos    = window.pageYOffset || 0,
                        maxTop = barH,             // 菜单顶边贴管理条下沿
                        minTop = winH - menuH;     // 菜单底边贴视口底（菜单比视口高时为负）

                    // 装得进视口：交给 core 自己的 .sticky-menu，不写行内样式
                    if (menuH > 0 && menuH + barH <= winH) {
                        curTop = null;
                        setStyle('', '');
                        if (!$body.hasClass('sticky-menu')) {
                            $body.addClass('sticky-menu');
                        }
                        lastPos = pos;
                        return;
                    }

                    // 菜单高于视口：自己按滚动方向平移 fixed 的 top
                    // .sticky-menu 的 position:fixed 会和这里的行内 top 打架（它没有 top），
                    // 而且 core 在这一档也是摘掉它的，保持一致
                    if ($body.hasClass('sticky-menu')) {
                        $body.removeClass('sticky-menu');
                    }

                    if (curTop === null) {
                        curTop = maxTop;
                    }

                    var delta = pos - lastPos;

                    if (pos <= 0) {
                        // 到顶（含 overscroll）：顶边归位
                        curTop = maxTop;
                    } else if (delta > 0) {
                        // 向下滚：菜单相对上移，最多到底边贴视口底
                        curTop = Math.max(minTop, curTop - delta);
                    } else if (delta < 0) {
                        // 向上滚：菜单相对下移，最多到顶边贴管理条
                        curTop = Math.min(maxTop, curTop - delta);
                    }

                    setStyle('fixed', Math.round(curTop) + 'px');
                    lastPos = pos;
                }

                update();

                // scroll 用 passive，滚动时不阻塞合成
                window.addEventListener('scroll', update, { passive: true });
                window.addEventListener('resize', update);

                // 菜单高度会变：折叠按钮、当前分组子菜单展开/收起
                if (window.ResizeObserver) {
                    new ResizeObserver(update).observe(wrap);
                }

                // 摘不掉的最后一路：wpResponsive.activate()/deactivate()（窗口跨 782px 断点）
                // **直接调用** setPinMenu()，不经过任何可解绑的事件，setPinMenu 又是闭包内的
                // 私有函数、没法替换。它会改 body 的 class（加/摘 sticky-menu），所以盯 class
                // 变化再跑一次 update()。update() 每步都先比较再写，不会自激循环。
                if (window.MutationObserver) {
                    new MutationObserver(update).observe(document.body, { attributes: true, attributeFilter: ['class'] });
                }

                if (desktop) {
                    var onChange = function () { curTop = null; update(); };
                    if (desktop.addEventListener) {
                        desktop.addEventListener('change', onChange);
                    } else if (desktop.addListener) {
                        desktop.addListener(onChange);
                    }
                }
            });
        }

        // 切换菜单后把内容区顶部对齐到顶部条下沿
        function scrollToContentTop() {
            var wrap = document.querySelector('.csf-options .csf-wrapper');
            if (!wrap) {
                return;
            }
            var bar    = document.getElementById('wpadminbar'),
                inner  = document.querySelector('.csf-options .csf-header-inner'),
                offset = (bar ? bar.getBoundingClientRect().height : 0)
                       + (inner ? inner.getBoundingClientRect().height : 0),
                top    = wrap.getBoundingClientRect().top + window.pageYOffset - offset;

            // 已经在内容顶部上方（还没滚过去）就不要往下拽用户
            if (window.pageYOffset <= top) {
                return;
            }
            window.scrollTo({ top: Math.max(0, top) });
        }

        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('.csf-options .csf-nav a[data-tab-id]') : null;
            if (!link) {
                return;
            }
            // 让 CSF 自己的 tab 切换先跑完（它绑在同一次点击上），再调整滚动位置
            requestAnimationFrame(scrollToContentTop);
        });
    })();
    </script>
    <?php
}
add_action('admin_footer', 'kratos_csf_sticky_offset_script');

// 前台管理员导航
if (!kratos_option('g_adminbar', true)) {
    add_filter('show_admin_bar', '__return_false');
}

// 禁用文章修订版本（g_post_revision）——只关 revision，不影响自动保存
if (kratos_option('g_post_revision', true)) {
    remove_action('post_updated', 'wp_save_post_revision');
}

/**
 * 禁用编辑器自动保存（g_post_autosave_off，默认关）。
 *
 * 自动保存和修订版本是两回事，各走各的路，所以要分别处理：
 *  - 经典编辑器：靠 wp-includes 的 autosave.js（handle `autosave`）按
 *    AUTOSAVE_INTERVAL 定时 POST wp_autosave，deregister 掉即停；
 *  - 区块编辑器：自动保存在 wp-editor 内部实现，摘不掉脚本，只能把编辑器设置里的
 *    autosaveInterval 拉大（单位秒，这里给 24 小时，等价于一次编辑会话内不会触发）。
 *
 * 「新建文章」时落的那条 auto-draft 是 WP 打开编辑页就写的，与自动保存无关，
 * 这里不动它；积攒的空草稿在「基础设置 → 性能优化 → 数据库瘦身」里清。
 */
function kratos_disable_autosave()
{
    if (!kratos_option('g_post_autosave_off', false)) {
        return;
    }
    wp_dequeue_script('autosave');
    wp_deregister_script('autosave');
}
add_action('admin_enqueue_scripts', 'kratos_disable_autosave', 100);

function kratos_disable_autosave_block_editor($settings)
{
    if (kratos_option('g_post_autosave_off', false)) {
        $settings['autosaveInterval'] = DAY_IN_SECONDS;
    }
    return $settings;
}
add_filter('block_editor_settings_all', 'kratos_disable_autosave_block_editor');

// 添加友情链接
add_filter('pre_option_link_manager_enabled', '__return_true');

// 禁用转义
$qmr_work_tags = array('the_title', 'the_excerpt', 'single_post_title', 'comment_author', 'comment_text', 'link_description', 'bloginfo', 'wp_title', 'term_description', 'category_description', 'widget_title', 'widget_text');

foreach ($qmr_work_tags as $qmr_work_tag) {
    remove_filter($qmr_work_tag, 'wptexturize');
}

remove_filter('the_content', 'wptexturize');
add_filter('run_wptexturize', '__return_false');

// 禁用 Emoji（不加载 wp-emoji-release.min.js，不请求 s.w.org twemoji SVG）
// 必须在 init/admin_init 里 remove——WP 的默认 emoji hook 在 default-filters.php 阶段才注册
if (kratos_option('g_disable_emoji', true)) {
    add_action('init', function () {
        foreach ([
            ['wp_head',                 'print_emoji_detection_script', 7],
            ['embed_head',              'print_emoji_detection_script'],
            ['wp_print_footer_scripts', '_print_emoji_detection_script'],  // 真正输出 <script src=wp-emoji-release.min.js> 的源头
            ['wp_enqueue_scripts',      'wp_enqueue_emoji_styles'],        // WP 6.2+
            ['enqueue_embed_scripts',   'wp_enqueue_emoji_styles'],
            ['wp_print_styles',         'print_emoji_styles'],
            ['admin_print_styles',      'print_emoji_styles'],
        ] as $h) remove_action($h[0], $h[1], $h[2] ?? 10);

        foreach ([
            ['the_content_feed', 'wp_staticize_emoji'],
            ['comment_text_rss', 'wp_staticize_emoji'],
            ['wp_mail',          'wp_staticize_emoji_for_email'],
        ] as $h) remove_filter($h[0], $h[1]);

        add_filter('emoji_svg_url', '__return_false');
        add_filter('tiny_mce_plugins', fn($p) => is_array($p) ? array_diff($p, ['wpemoji']) : []);
        add_filter('wp_resource_hints', fn($urls, $rel) => $rel === 'dns-prefetch'
            ? array_diff($urls, [apply_filters('emoji_svg_url', 'https://s.w.org/images/core/emoji/')])
            : $urls, 10, 2);
    });

    add_action('admin_init', function () {
        remove_action('admin_print_scripts',        'print_emoji_detection_script');
        remove_action('admin_print_footer_scripts', '_print_emoji_detection_script');
    });
}

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

// Kratos-plus 主题自动更新（GitHub Release 为源，可选切换到 Gitee 下载）
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

/**
 * 前台自定义 Head / Footer 代码注入
 * 主题选项 → 顶部与页脚 → 自定义代码
 */
if (!function_exists('kratos_custom_code_should_output')) {
    function kratos_custom_code_should_output()
    {
        if (is_admin() || is_feed() || is_robots() || is_trackback()) {
            return false;
        }
        if (kratos_option('g_custom_code_admin_only') && !is_user_logged_in()) {
            return false;
        }
        return true;
    }
}

add_action('wp_head', function () {
    if (!kratos_custom_code_should_output()) {
        return;
    }
    $code = (string) kratos_option('g_custom_head_code');
    if (trim($code) !== '') {
        echo "\n" . $code . "\n";
    }
}, 999);

add_action('wp_footer', function () {
    if (!kratos_custom_code_should_output()) {
        return;
    }
    $code = (string) kratos_option('g_custom_footer_code');
    if (trim($code) !== '') {
        echo "\n" . $code . "\n";
    }
}, 999);
