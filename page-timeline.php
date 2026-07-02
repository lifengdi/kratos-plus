<?php

/*
 * Template Name: 时间轴
 *
 * 按发布时间倒序展示所有文章，左侧年份/月份标签，右侧文章行。
 * 数据由 [timeline] 短码（inc/theme-timeline.php）组织。
 *
 *   - 标题 / 副标题 通过短码参数定制：[timeline title="..." subtitle="..."]
 *   - 每页条数：per_page="20"（0 = 全部）
 *   - 排除分类：exclude_cats="1,2,3"
 *
 * 后台默认值在「主题设置 → 时间轴配置」中调整；短码同名参数会覆盖后台默认值。
 *
 * 主题装饰（皮肤层对 .details 的玻璃/Bauhaus/新拟态/极简形状）会被
 * is-kratos-timeline-page body class 豁免，让短码自身的视觉完整呈现。
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
                        <?php echo do_shortcode('[timeline]'); ?>
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
