<?php

/*
 * Template Name: 年度回顾
 *
 * 展示指定年份的博客数据长图，可下载为 PNG 分享。
 * 通过 URL 参数 ?yr_year=2026 切换年份，默认本年。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

get_header();
$kratos_cols = kratos_layout_cols();
?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color'; ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> details">
                <?php if (have_posts()) : the_post(); ?>
                    <?php if (trim(wp_strip_all_tags(get_the_content())) !== '') { ?>
                        <div class="content" style="margin-bottom:20px;">
                            <?php the_content(); ?>
                        </div>
                    <?php } ?>
                    <?php echo kratos_yr_render(); ?>
                <?php endif; ?>
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
