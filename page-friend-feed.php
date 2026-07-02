<?php

/*
 * Template Name: 博友动态
 *
 * 按走心评论页面的布局展示友链中有 RSS 订阅地址的博客的更新内容。
 * 数据由 [friend_feed] 短码（inc/theme-friend-feed.php）组织：
 *   - 顶部 4 张统计卡：文章总数 / 订阅站点数 / 本月文章 / 最近更新
 *   - 下方文章卡片列表 + 分页
 *
 * 后台配置在「主题设置 → 博友动态」中调整；短码同名参数会覆盖后台默认值。
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
                        <?php echo do_shortcode('[friend_feed]'); ?>
                    </div>
                <?php endif; ?>
                <?php comments_template(); ?>
            </div>
            <div class="<?php echo $kratos_cols['sidebar']; ?> sidebar sticky-sidebar d-none d-lg-block">
                <?php dynamic_sidebar('page_sidebar'); ?>
            </div>
        </div>
    </div>
</div>
<?php get_footer(); ?>
