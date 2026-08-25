<?php

/*
 * Template Name: Kratos+特色标题
 *
 * 空白页面模板：复用友链页面（[friend_links]）的标题头视觉，
 * 页面正文（the_content）渲染在标题下方，无侧边逻辑差异。
 *
 * 标题取自页面本身的标题，副标题取自页面摘录（excerpt），
 * 未填写摘录时不展示副标题及分隔线。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

get_header();
$kratos_cols = kratos_layout_cols(); ?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color' ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> details">
                <?php if (have_posts()) : the_post();
                    update_post_caches($posts);
                    $kft_meta     = function_exists('kratos_featured_title_meta') ? kratos_featured_title_meta() : array('title' => get_the_title(), 'subtitle' => (has_excerpt() ? get_the_excerpt() : ''), 'icon' => '');
                    $kft_title    = $kft_meta['title'];
                    $kft_subtitle = $kft_meta['subtitle'];
                    $kft_icon     = $kft_meta['icon'];
                ?>
                    <div class="kratos-friend-links kratos-featured-title">
                        <?php if ($kft_title !== '' || $kft_subtitle !== '') { ?>
                            <header class="kfl-header kr-hd">
                                <?php if ($kft_title !== '') { ?>
                                    <span class="kfl-title-icon kr-ico" aria-hidden="true">
                                        <?php // 未配置图标时回落 FA 的星形，和系列页等处的图标同一套字体
                                        ?><i class="<?php echo esc_attr($kft_icon !== '' ? $kft_icon : 'fa-solid fa-star'); ?>"></i>
                                    </span>
                                    <span class="kfl-title kr-hd-title"><?php echo esc_html($kft_title); ?></span>
                                <?php } ?>
                                <?php if ($kft_subtitle !== '') { ?>
                                    <?php if ($kft_title !== '') { ?><span class="kfl-header-divider kr-hd-divider" aria-hidden="true"></span><?php } ?>
                                    <p class="kfl-subtitle kr-hd-sub"><?php echo esc_html($kft_subtitle); ?></p>
                                <?php } ?>
                            </header>
                        <?php } ?>
                        <div class="content kft-content kr-card" id="lightgallery">
                            <?php the_content(); ?>
                        </div>
                    </div>
                    <style>
                        /* 复用友链页面 kfl-header 的变量体系与视觉；仅内联头部所需的最小样式，
                         * 避免依赖 [friend_links] 短码首次渲染时才注入的 <style> 块 */
                        .kratos-featured-title{
                            --khs-bg-1:#f5f5f5;--khs-bg-2:#f0f0f0;--khs-bg-3:#ebebeb;
                            --khs-fg:#333;--khs-fg-soft:#444;
                            --khs-accent:#336699;
                            --khs-line:rgba(0,0,0,.08);--khs-line-strong:rgba(0,0,0,.16);
                            --khs-card-bg:#ffffff;
                            --khs-card-shadow:0 1px 3px rgba(0,0,0,.06);
                            padding:0;background:transparent;max-width:100%;
                        }
                        .kratos-featured-title .kfl-header{
                            display:flex;align-items:center;flex-wrap:wrap;gap:14px;
                            padding:24px 28px;margin-bottom:18px;
                            background:var(--khs-card-bg);
                            border:1px solid var(--khs-line);
                            border-radius:14px;
                            box-shadow:var(--khs-card-shadow);
                        }
                        .kratos-featured-title .kfl-title-icon{
                            display:inline-flex;align-items:center;justify-content:center;
                            width:38px;height:38px;border-radius:10px;
                            background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
                            color:var(--khs-accent);
                        }
                        .kratos-featured-title .kfl-title-icon i{font-size:18px;line-height:1;}
                        .kratos-featured-title .kfl-title{
                            margin:0;padding:0;font-size:22px;font-weight:700;line-height:1.3;
                            color:var(--khs-fg);
                        }
                        .kratos-featured-title .kfl-header-divider{
                            display:inline-block;width:1px;height:22px;background:var(--khs-line-strong);
                        }
                        .kratos-featured-title .kfl-subtitle{
                            margin:0;padding:0;font-size:14px;line-height:1.5;color:var(--khs-fg-soft);
                        }
                        .kratos-featured-title .kft-content{
                            padding:24px 28px;
                            background:var(--khs-card-bg);
                            border:1px solid var(--khs-line);
                            border-radius:14px;
                            box-shadow:var(--khs-card-shadow);
                            color:var(--khs-fg);
                            word-wrap:break-word;
                            word-break:break-word;
                        }
                        .kratos-featured-title .kft-content > *:first-child{margin-top:0;}
                        .kratos-featured-title .kft-content > *:last-child{margin-bottom:0;}
                        @media (max-width:640px){
                            .kratos-featured-title .kfl-header{padding:18px 18px;gap:10px;}
                            .kratos-featured-title .kfl-title{font-size:19px;}
                            .kratos-featured-title .kfl-header-divider{display:none;}
                            .kratos-featured-title .kfl-subtitle{flex-basis:100%;font-size:13px;}
                            .kratos-featured-title .kft-content{padding:18px;}
                        }
                        html[data-theme="dark"] .kratos-featured-title,
                        body.dark .kratos-featured-title{
                            --khs-bg-1:#2a2e35;--khs-bg-2:#2a2e35;--khs-bg-3:#333842;
                            --khs-fg:#d6d8db;--khs-fg-soft:#b8bbc0;
                            --khs-accent:#6ea8ff;
                            --khs-line:rgba(255,255,255,.08);--khs-line-strong:rgba(255,255,255,.16);
                            --khs-card-bg:#1c1f24;
                        }
                    </style>
                <?php endif; ?>
                <?php comments_template(); ?>
            </div>
            <?php if ( kratos_perf_show_sidebar() ) : ?>
            <div class="<?php echo $kratos_cols['sidebar']; ?> sidebar sticky-sidebar d-none d-lg-block">
                <?php dynamic_sidebar('page_sidebar'); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
