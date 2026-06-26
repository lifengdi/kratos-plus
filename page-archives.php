<?php

/*
 * Template Name: 文章归档
 *
 * 渲染整站统计 + 分类列表 + 标签列表，视觉与走心评论 parchment 方案一致。
 * 数据由 [archives_stats] 短码（inc/theme-archives-stats.php）统一组织。
 *
 *   - 标题 / 副标题 通过短码参数定制：[archives_stats title="..." subtitle="..."]
 *   - 标签列表条数: tags_max="20"（0 不展示）
 *   - 页面正文 the_content() 会显示在统计区上方，可作为引导语 / 自定义说明
 *
 * 主题装饰（皮肤层 §5/§15 对 .details 的玻璃/Bauhaus/新拟态/极简形状）会被
 * is-kratos-archives-page body class 豁免，让短码自身的视觉完整呈现。
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
                        <?php echo do_shortcode('[archives_stats]'); ?>
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
