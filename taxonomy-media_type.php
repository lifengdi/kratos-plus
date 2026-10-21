<?php

/**
 * media_type taxonomy archive.
 *
 * 直接把当前 term 作为 [media_library] 的 default_type 渲染。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

get_header();
$kratos_cols = kratos_layout_cols();
$term = get_queried_object();
$slug = $term ? $term->slug : 'all';
$title = $term ? $term->name : __('观影读书录', 'kratos');
?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color' ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> details">
                <div class="content">
                    <?php echo do_shortcode(sprintf('[media_library default_type="%s" title="%s"]', esc_attr($slug), esc_attr($title))); ?>
                </div>
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
