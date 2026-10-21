<?php

/*
 * Template Name: Kratos-plus 观影读书录
 *
 * 展示所有 media_item 条目，由 [media_library] 短码渲染。
 * body class is-kratos-media-page 让皮肤层 §7 卸掉外层 .details 装饰。
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
                <?php if (have_posts()) : the_post(); ?>
                    <div class="content" id="lightgallery">
                        <?php the_content(); ?>
                        <?php echo do_shortcode('[media_library]'); ?>
                    </div>
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
