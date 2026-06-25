<?php

/*
 * Template Name: 走心评论
 *
 * 用于展示走心评论的页面模板，逻辑复用 [heart_comments] 短码：
 *   - 走心评论数量 / 来自文章数量 / 参与用户数量
 *   - 走心评论卡片列表（用户头像 / 昵称 / 时间 / 来自文章，点击跳转）
 *   - 分页（默认每页 100 条，可在「评论配置 → 通用配置 → 走心评论」中调整）
 *
 * 标题、副标题、每页条数、暖色背景三色都在「评论配置 → 通用配置 → 走心评论」中
 * 配置；页面正文（the_content）会渲染在走心评论列表之上，可作为引导语 / 自定义
 * 说明使用。无默认页面标题。
 *
 * main 区域沿用主题默认背景，只有短码本身的卡片保持暖黄色系。
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
                        <?php echo do_shortcode('[heart_comments]'); ?>
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
