<?php
/*
 * Template Name: 游客中心
 *
 * 独立页面模板：根据 WP 原生 comment cookie 读到访客邮箱后异步拉取该
 * 邮箱在本站的评论档案（等级 / 走心数 / 成就徽章 / 最近评论 / 常访文章
 * / 近一年活跃度）。
 *
 * 标题头复用「特色标题」页面的 kfl-header 视觉：页面标题作为大标题，
 * 页面摘录作为副标题（无摘录则不显示副标题及分隔线）。
 *
 * 后端聚合 / 缓存 / REST 接口 / 手动徽章：inc/theme-visitor-center.php
 * 前端：assets/js/visitor-center.js + assets/css/visitor-center.css
 *
 * 需在「主题选项 → 基础配置 → 游客中心」中开启 `g_visitor_center_enable`；
 * 未开启时 REST 接口返回 404，页面显示未启用提示。
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
                    $kvc_title    = trim((string) kratos_option('kvc_title', ''));
                    $kvc_subtitle = trim((string) kratos_option('kvc_subtitle', ''));
                    $kvc_icon     = 'fa-solid fa-id-card';
                ?>
                    <div class="kratos-friend-links kratos-featured-title kratos-visitor-center-wrap">
                        <?php if ($kvc_title !== '' || $kvc_subtitle !== '') { ?>
                            <header class="kfl-header kr-hd">
                                <?php if ($kvc_title !== '') { ?>
                                    <span class="kfl-title-icon kr-ico" aria-hidden="true">
                                        <?php echo function_exists('kratos_fa_svg') ? kratos_fa_svg($kvc_icon) : ''; ?>
                                    </span>
                                    <span class="kfl-title kr-hd-title"><?php echo esc_html($kvc_title); ?></span>
                                <?php } ?>
                                <?php if ($kvc_subtitle !== '') { ?>
                                    <?php if ($kvc_title !== '') { ?><span class="kfl-header-divider kr-hd-divider" aria-hidden="true"></span><?php } ?>
                                    <p class="kfl-subtitle kr-hd-sub"><?php echo esc_html($kvc_subtitle); ?></p>
                                <?php } ?>
                            </header>
                        <?php } ?>
                        <div class="content kft-content kr-card" id="lightgallery">
                            <?php the_content(); ?>
                            <?php if (function_exists('kratos_vc_enabled') && kratos_vc_enabled()) : ?>
                                <div class="kratos-visitor-center" id="kratos-visitor-center" data-state="loading" aria-live="polite">
                                    <div class="kvc-loading">
                                        <div class="kvc-skeleton kvc-sk-hd"></div>
                                        <div class="kvc-skeleton kvc-sk-body"></div>
                                    </div>
                                </div>
                            <?php else : ?>
                                <p style="text-align:center;padding:40px 20px;color:#888;">
                                    <?php _e('游客中心未启用，请在「主题选项 → 基础配置 → 游客中心」中开启。', 'kratos'); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <style>
                        /* 复用「特色标题」页面同款视觉（保持与 friend-links kfl-header 一致） */
                        .kratos-visitor-center-wrap{
                            --khs-bg-1:#f5f5f5;--khs-bg-2:#f0f0f0;--khs-bg-3:#ebebeb;
                            --khs-fg:#333;--khs-fg-soft:#444;
                            --khs-accent:#336699;
                            --khs-line:rgba(0,0,0,.08);--khs-line-strong:rgba(0,0,0,.16);
                            --khs-card-bg:#ffffff;
                            --khs-card-shadow:0 1px 3px rgba(0,0,0,.06);
                            padding:0;background:transparent;max-width:100%;
                        }
                        .kratos-visitor-center-wrap .kfl-header{
                            display:flex;align-items:center;flex-wrap:wrap;gap:14px;
                            padding:24px 28px;margin-bottom:18px;
                            background:var(--khs-card-bg);
                            border:1px solid var(--khs-line);
                            border-radius:14px;
                            box-shadow:var(--khs-card-shadow);
                        }
                        .kratos-visitor-center-wrap .kfl-title-icon{
                            display:inline-flex;align-items:center;justify-content:center;
                            width:38px;height:38px;border-radius:10px;
                            background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
                            color:var(--khs-accent);
                        }
                        .kratos-visitor-center-wrap .kfl-title-icon .kratos-fa-svg{width:18px;height:18px;}
                        .kratos-visitor-center-wrap .kfl-title{
                            margin:0;padding:0;font-size:22px;font-weight:700;line-height:1.3;
                            color:var(--khs-fg);
                        }
                        .kratos-visitor-center-wrap .kfl-header-divider{
                            display:inline-block;width:1px;height:22px;background:var(--khs-line-strong);
                        }
                        .kratos-visitor-center-wrap .kfl-subtitle{
                            margin:0;padding:0;font-size:14px;line-height:1.5;color:var(--khs-fg-soft);
                        }
                        .kratos-visitor-center-wrap .kft-content{
                            padding:24px 28px;
                            background:var(--khs-card-bg);
                            border:1px solid var(--khs-line);
                            border-radius:14px;
                            box-shadow:var(--khs-card-shadow);
                            color:var(--khs-fg);
                            word-wrap:break-word;word-break:break-word;
                        }
                        .kratos-visitor-center-wrap .kft-content > *:first-child{margin-top:0;}
                        .kratos-visitor-center-wrap .kft-content > *:last-child{margin-bottom:0;}
                        @media (max-width:640px){
                            .kratos-visitor-center-wrap .kfl-header{padding:18px 18px;gap:10px;}
                            .kratos-visitor-center-wrap .kfl-title{font-size:19px;}
                            .kratos-visitor-center-wrap .kfl-header-divider{display:none;}
                            .kratos-visitor-center-wrap .kfl-subtitle{flex-basis:100%;font-size:13px;}
                            .kratos-visitor-center-wrap .kft-content{padding:18px;}
                        }
                        html[data-theme="dark"] .kratos-visitor-center-wrap,
                        body.dark .kratos-visitor-center-wrap{
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
            <?php if (kratos_perf_show_sidebar()) : ?>
            <div class="<?php echo $kratos_cols['sidebar']; ?> sidebar sticky-sidebar d-none d-lg-block">
                <?php dynamic_sidebar('page_sidebar'); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
