<?php

/*
 * Template Name: 评论排行榜
 *
 * 用于展示评论排行榜的页面模板，逻辑复用 [top_commenters] 短码：
 *   - 上榜用户 / 累计评论 / 榜首评论数三张总览卡
 *   - 排行榜列表：名次 / 头像 / 用户名 / 评论等级标签 /
 *     最后一次评论时间 / 评论数；用户名填了个人网站时点击跳转
 *
 * 标题、副标题、展示数量都在「评论配置 → 通用配置 → 评论排行榜」中配置；
 * 页面正文（the_content）会渲染在列表之上，可作为引导语 / 自定义说明使用。
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
                        <?php echo do_shortcode('[top_commenters]'); ?>
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
