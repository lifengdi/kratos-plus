<?php

/*
 * Template Name: Now
 *
 * 展示「我最近在做什么」：一张大卡片是最新一条 Now，下方是历史 Now 时间流。
 * 数据来自 CPT `kratos_now`，后台「Now」菜单里发一条即可。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

get_header();
$kratos_cols = kratos_layout_cols();

$now_title     = trim((string) kratos_option('now_page_title', __('Now', 'kratos')));
$now_subtitle  = trim((string) kratos_option('now_page_subtitle', __('这是我最近在做的事、在想的事、在学的事。', 'kratos')));
$show_history  = (bool) kratos_option('now_show_history', true);
$history_limit = max(1, (int) kratos_option('now_history_limit', 20));

$items = kratos_now_get_items($show_history ? $history_limit + 1 : 1);
$current = !empty($items) ? array_shift($items) : null;
$history = $items;

$last_update_hint = '';
if ($current) {
    $last_update_hint = sprintf(
        __('上次更新于 %s（%s前）', 'kratos'),
        get_the_date(get_option('date_format'), $current),
        human_time_diff(get_post_time('U', true, $current), current_time('timestamp', true))
    );
}
?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color'; ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> details">
                <?php if (have_posts()) : the_post(); ?>
                    <?php if ($now_title !== '' || $now_subtitle !== '') { ?>
                        <header class="kratos-now-header kr-hd">
                            <?php if ($now_title !== '') { ?>
                                <h1 class="knw-h-title kr-hd-title"><?php echo esc_html($now_title); ?></h1>
                            <?php } ?>
                            <?php if ($now_subtitle !== '') { ?>
                                <p class="knw-h-sub kr-hd-sub"><?php echo esc_html($now_subtitle); ?></p>
                            <?php } ?>
                            <?php if ($last_update_hint !== '') { ?>
                                <p class="knw-h-updated"><?php echo esc_html($last_update_hint); ?></p>
                            <?php } ?>
                        </header>
                    <?php } ?>

                    <?php if (trim(wp_strip_all_tags(get_the_content())) !== '') { ?>
                        <div class="content" style="margin-bottom:20px;">
                            <?php the_content(); ?>
                        </div>
                    <?php } ?>

                    <div class="kratos-now">
                        <?php if ($current) {
                            kratos_now_render_item($current, true);
                        } else { ?>
                            <div class="kratos-now-empty">
                                <?php esc_html_e('还没有 Now 记录，去后台「Now」写第一条吧。', 'kratos'); ?>
                            </div>
                        <?php } ?>
                    </div>

                    <?php if ($show_history && !empty($history)) { ?>
                        <div class="kratos-now-history kr-body">
                            <h2 class="kratos-now-history-title"><?php esc_html_e('Then', 'kratos'); ?></h2>
                            <?php foreach ($history as $p) {
                                kratos_now_render_item($p, false);
                            } ?>
                        </div>
                    <?php } ?>

                    <?php echo kratos_now_inline_assets(); ?>
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
