<?php

/**
 * 主题页眉
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2023.03.30
 */
?>
<!DOCTYPE html>
<html lang="<?php bloginfo('language'); ?>">

<head>
    <meta charset="UTF-8">
    <?php
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">';
    echo '<meta name="format-detection" content="telphone=no, date=no, address=no, email=no">';
    echo '<meta name="theme-color" content="' . kratos_option('g_chrome', '#282a2c') . '">';

    if (kratos_option('g_icon')) {
        echo '<link rel="shortcut icon" href="' . kratos_option("g_icon") . '">';
    }
    wp_head();
    // 这行是 Kratos 原有写法：它无视分组，把 jQuery 强行打印在 </head> 之前，
    // 且是同步 <script>，会阻塞解析与首次绘制（gzip 后 31KB）——「标题已经变了、
    // 正文还是白的」就来自这里。主题自己的脚本全都在页脚，头部没人用 $，
    // 因此默认不再打印，改由页脚随依赖一起加载。
    // 若有插件在 <head> 里就要用 jQuery，关掉「性能优化 → jQuery 移到页脚」即可恢复。
    if (!function_exists('kratos_perf_on') || !kratos_perf_on('g_perf_jquery_footer', true)) {
        wp_print_scripts('jquery');
    }
    mourning();
    if (kratos_option('seo_statistical')) {
        echo kratos_option('seo_statistical');
    }
    ?>
</head>
<?php flush(); ?>

<body>
    <?php wp_body_open(); ?>
    <style id="k-header-stack">
        /* 全局锁定 .k-header 层级：
           部分皮肤（silk 等）会给 .k-header/.k-main/.k-footer 都设 z-index:1，
           导致手机端 .banner（290px）从 50/59px 高的 .k-header 溢出后被 .k-main 覆盖，
           出现「banner 图片仅顶部一小条可见，下方只剩纯色」。 */
        .k-header { position: relative !important; z-index: 5 !important; }
    </style>
    <?php
    $nav_sticky_pc = kratos_option('nav_sticky_pc', false);
    $nav_sticky_pad = kratos_option('nav_sticky_pad', false);
    $nav_sticky_mobile = kratos_option('nav_sticky_mobile', false);
    $nav_sticky_bg = kratos_option('nav_sticky_bg', '#24292e');
    if ($nav_sticky_pc || $nav_sticky_pad || $nav_sticky_mobile) :
        $sticky_mq = array();
        if ($nav_sticky_mobile) { $sticky_mq[] = '(max-width: 767.98px)'; }
        if ($nav_sticky_pad)    { $sticky_mq[] = '(min-width: 768px) and (max-width: 991.98px)'; }
        if ($nav_sticky_pc)     { $sticky_mq[] = '(min-width: 992px)'; }
    ?>
    <style id="k-nav-sticky-style">
        /* 抬高 .k-header 层级，避免某些皮肤（如 silk）给 .k-header/.k-main
           都设 z-index:1 造成的堆叠上下文天花板把 fixed 导航压在下面。
           只在 nav 真正吸顶时应用，避免非吸顶状态下改变 .k-header 的定位上下文
           导致 nav 初始位置偏移。 */
        .k-header.k-has-sticky-nav {
            position: relative !important;
            z-index: 1030 !important;
        }
        @media <?php echo implode(',', $sticky_mq); ?> {
            .k-nav.nav-sticky {
                position: fixed !important;
                top: 0;
                left: 0;
                right: 0;
                background: <?php echo esc_attr($nav_sticky_bg); ?> !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                animation: kNavSlideDown 0.3s ease-out;
                z-index: 1030;
            }
            /* 每日皮肤（浅色模式）：跟随皮肤主标题色 */
            html[data-weekday-skin]:not([data-theme="dark"]) .k-nav.nav-sticky {
                background: var(--kr-skin-heading, <?php echo esc_attr($nav_sticky_bg); ?>) !important;
            }
            /* 朱砂：吸顶用朱红渐变 + 顶部金线 */
            html[data-weekday-skin="vermilion"]:not([data-theme="dark"]) .k-nav.nav-sticky {
                background: linear-gradient(180deg, #A61E1A, #8E1815) !important;
                box-shadow: 0 4px 18px rgba(122, 21, 18, 0.28);
                border-bottom: none;
            }
            html[data-weekday-skin="vermilion"]:not([data-theme="dark"]) .k-nav.nav-sticky::before {
                content: "";
                position: absolute;
                top: 0;
                left: 8px;
                right: 8px;
                height: 2px;
                background: linear-gradient(90deg, transparent, #E5C97A 20%, #E5C97A 80%, transparent);
                opacity: 0.7;
            }
            /* 金融 · 盘口：吸顶用金融红渐变 + 下沿细金线 */
            html[data-weekday-skin="bourse"]:not([data-theme="dark"]) .k-nav.nav-sticky {
                background: linear-gradient(180deg, #C8102E, #98071F) !important;
                box-shadow: 0 4px 14px rgba(20, 32, 44, 0.18);
                border-bottom: 2px solid #B08A3E;
            }
            /* 暗夜模式：跟随暗夜次级底色 */
            html[data-theme="dark"] .k-nav.nav-sticky {
                background: var(--kr-bg-elev, #1a1d22) !important;
            }
        }
        @keyframes kNavSlideDown {
            from { transform: translateY(-100%); }
            to   { transform: translateY(0); }
        }
    </style>
    <script id="k-nav-sticky-script">
    (function () {
        var enabled = {
            mobile: <?php echo $nav_sticky_mobile ? 'true' : 'false'; ?>,
            pad:    <?php echo $nav_sticky_pad ? 'true' : 'false'; ?>,
            pc:     <?php echo $nav_sticky_pc ? 'true' : 'false'; ?>
        };
        function activeForViewport() {
            var w = window.innerWidth;
            if (w < 768)  return enabled.mobile;
            if (w < 992)  return enabled.pad;
            return enabled.pc;
        }
        function onScroll() {
            var nav = document.querySelector('.k-nav');
            var header = document.querySelector('.k-header');
            if (!nav) return;
            if (activeForViewport() && window.pageYOffset > 100) {
                nav.classList.add('nav-sticky');
                if (header) header.classList.add('k-has-sticky-nav');
            } else {
                nav.classList.remove('nav-sticky');
                if (header) header.classList.remove('k-has-sticky-nav');
            }
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        document.addEventListener('DOMContentLoaded', onScroll);
    })();
    </script>
    <?php endif; ?>
    <?php if (!kratos_option('top_img_switch', true)) : ?>
    <style id="k-nav-noimg-style">
        /* 图片导航关闭：无 banner 兜底，nav 进入正常流，.k-header 自适应高度 */
        .k-header {
            height: auto !important;
        }
        .k-nav:not(.nav-sticky) {
            position: relative !important;
        }
        /* 桌面：navbar 内容高 70px。管理条不需要在这里让位 —— 核心的
           html{margin-top} 已经把文档下推，只有吸顶态（fixed）要偏移，
           见 theme-core.php 里 is_admin_bar_showing() 那段。 */
        .k-main.color {
            padding-top: 20px !important;
        }
        /* 图片导航关闭时，导航跟随皮肤 / 暗夜模式 */
        html[data-weekday-skin]:not([data-theme="dark"]) .k-nav:not(.nav-sticky) {
            background: var(--kr-skin-heading, <?php echo esc_attr(kratos_option('top_color', '#24292e')); ?>) !important;
        }
        /* 金融 · 盘口：常态导航同样是金融红渐变（与 demo 的行情页头一致） */
        html[data-weekday-skin="bourse"]:not([data-theme="dark"]) .k-nav:not(.nav-sticky) {
            background: linear-gradient(180deg, #C8102E, #98071F) !important;
        }
        html[data-theme="dark"] .k-nav:not(.nav-sticky) {
            background: var(--kr-bg-elev, #1a1d22) !important;
        }
        /* 手机端：确保 logo 图片与汉堡按钮不被 .k-header 硬限高压扁 */
        @media (max-width: 991.98px) {
            .k-nav .navbar {
                min-height: 56px;
            }
            .k-nav .navbar-brand {
                display: inline-flex;
                align-items: center;
                line-height: 1.2;
            }
            .k-nav .navbar-brand img {
                max-height: 32px;
                width: auto;
                height: auto;
                object-fit: contain;
            }
            .k-nav .navbar-toggler {
                padding: 10px;
            }
            /* 菜单展开面板：给一个可读背景，避免手机端菜单浮在页面上无底色 */
            .k-nav .navbar-collapse {
                margin-top: 4px;
                padding: 8px 12px;
                background: inherit;
                border-radius: 4px;
            }
        }
    </style>
    <?php endif; ?>
    <div class="k-header">
        <nav class="k-nav navbar navbar-expand-lg navbar-light fixed-top" <?php echo kratos_option('top_img_switch', true) ? '' : 'style="background:' . kratos_option('top_color', '#24292e') . '"'; ?>>
            <div class="container">
                <a class="navbar-brand" href="<?php echo get_option('home'); ?>">
                    <?php
                    if (kratos_option('g_logo')) {
                        echo '<img src="' . kratos_option('g_logo') . '"><h1 style="display:none">' . get_bloginfo('name') . '</h1>';
                    } else {
                        echo '<h1>' . get_bloginfo('name') . '</h1>';
                    }
                    ?>
                </a>
                <?php if (has_nav_menu('header_menu')) { ?>
                    <button class="navbar-toggler navbar-toggler-right" id="navbutton" type="button" data-bs-toggle="collapse" data-bs-target="#navbarResponsive" aria-controls="navbarResponsive" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="line first-line"></span>
                        <span class="line second-line"></span>
                        <span class="line third-line"></span>
                    </button>
                <?php }
                if (has_nav_menu('header_menu')) {
                    wp_nav_menu(array(
                        'theme_location'  => 'header_menu',
                        'depth'           => 2,
                        'container'       => 'div',
                        'container_class' => 'collapse navbar-collapse',
                        'container_id'    => 'navbarResponsive',
                        'menu_class'      => 'navbar-nav ms-auto',
                        'walker'          => new WP_Bootstrap_Navwalker(),
                    ));
                }
                ?>
            </div>
        </nav>
        <?php if (kratos_option('top_img_switch', true)) { ?>
            <div class="banner">
                <div class="overlay"></div>
                <div class="content text-center" style="background-image: url(<?php echo kratos_option('top_img', ASSET_PATH . '/assets/img/background.jpg'); ?>);">
                    <div class="introduce animate__animated animate__fadeInUp">
                        <?php
                        if (is_category() || is_tag()) {
                            echo '<div class="title">' . single_cat_title('', false) . '</div>';
                            echo '<div class="mate">' . strip_tags(category_description()) . '</div>';
                        } else {
                            echo '<div class="title">' . kratos_option('top_title', 'Kratos-plus') . '</div>';
                            echo '<div class="mate">' . kratos_option('top_describe', __('专注于用户阅读体验的响应式博客主题', 'kratos')) . '</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>