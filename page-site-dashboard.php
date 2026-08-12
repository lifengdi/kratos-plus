<?php

/*
 * Template Name: 站点数据看板
 *
 * 「关于本站」的数据页：建站天数 / 文章与字数 / 发布节奏 / 年度产出 /
 * 分类占比 / 最勤评论者 / 评论者地域分布。
 * 数据由 [site_dashboard] 短码（inc/theme-site-dashboard.php）统一组织，
 * 全部复用既有模块的数据层（归档统计 / 评论排行 / 阅读增强 / 地域分布）。
 *
 *   - 标题 / 副标题：[site_dashboard title="..." subtitle="..."]
 *   - 发布节奏天数：days="30"（7~120）
 *   - 各榜条数：cats_max / years_max / commenters_max（0 = 不展示）
 *   - 地域分布：geo="no" 可关掉
 *   - 页面正文 the_content() 显示在看板上方，可写引导语
 *
 * 皮肤层对 .details 的装饰由 is-kratos-dashboard-page body class 豁免。
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
                    update_post_caches($posts); ?>
                    <div class="content" id="lightgallery">
                        <?php the_content(); ?>
                        <?php echo do_shortcode('[site_dashboard]'); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="<?php echo $kratos_cols['sidebar']; ?> sidebar sticky-sidebar d-none d-lg-block">
                <?php dynamic_sidebar('page_sidebar'); ?>
            </div>
        </div>
    </div>
</div>
<?php get_footer(); ?>
