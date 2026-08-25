<?php

/*
 * Template Name: 友情链接
 *
 * 用于展示友情链接的页面模板，逻辑复用 [friend_links] 短码：
 *   - 按 link_category 分组展示已通过的友链（logo / 名称 / 描述 / 跳转）
 *   - 无 Logo 时展示首字母占位符
 *   - 底部可选的申请友链表单
 *
 * 数据存储：直接复用 WordPress 原生 wp_links（顶级菜单「链接」）；
 * 新申请以 link_visible='N' 落库，站长在「链接 → 链接管理」审核后展示。
 *
 * 页面正文（the_content）会渲染在友链列表之上，可作为引导语 / 自定义说明使用。
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
                        <?php echo do_shortcode('[friend_links]'); ?>
                    </div>
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
