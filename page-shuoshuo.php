<?php

/*
 * Template Name: 说说
 *
 * 把页面正文渲染在朋友圈式说说列表之上（可作引导语 / 自定义说明）。
 * 列表本身由 [shuoshuo_feed] 短码渲染，数据来自自定义文章类型 `shuoshuo`。
 *
 * 后台「说说」菜单里发布即可，标题为空时会自动生成 "说说 YYYY-MM-DD HH:mm:ss"。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

get_header();
$kratos_cols = kratos_layout_cols();

$kratos_ss_title    = trim((string) kratos_option('shuoshuo_title', ''));
$kratos_ss_subtitle = trim((string) kratos_option('shuoshuo_subtitle', ''));
?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color' ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> details">
                <?php if (have_posts()) : the_post();
                    update_post_caches($posts); ?>
                    <?php if ($kratos_ss_title !== '' || $kratos_ss_subtitle !== '') { ?>
                        <header class="kratos-shuoshuo-header kr-hd">
                            <?php if ($kratos_ss_title !== '') { ?>
                                <h1 class="kss-h-title kr-hd-title"><?php echo esc_html($kratos_ss_title); ?></h1>
                            <?php } ?>
                            <?php if ($kratos_ss_subtitle !== '') { ?>
                                <p class="kss-h-subtitle kr-hd-sub"><?php echo esc_html($kratos_ss_subtitle); ?></p>
                            <?php } ?>
                        </header>
                    <?php } ?>
                    <div class="content" id="lightgallery">
                        <?php the_content(); ?>
                        <?php
                        $kratos_ss_per_page = max(1, (int) kratos_option('shuoshuo_per_page', 10));
                        echo do_shortcode('[shuoshuo_feed per_page="' . $kratos_ss_per_page . '"]');
                        ?>
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
