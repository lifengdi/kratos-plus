<?php

/**
 * 单条说说详情页
 *
 * 展示单条说说为一张朋友圈卡片（头像 / 作者 / 文本 / 九宫格 / 时间），
 * 下方挂载主题的评论区。样式与 page-shuoshuo.php 列表项保持一致。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

get_header();
$kratos_cols = kratos_layout_cols();

$kratos_ss_bg = kratos_option('g_background', '#f5f5f5');
$kratos_ss_has_banner = kratos_option('top_img_switch', true);

/*
 * 找一个可点回的"说说列表页"链接：
 * 优先选最近发布的、使用 page-shuoshuo.php 模板的页面。找不到就回首页。
 */
$kratos_ss_back_url = home_url('/');
$kratos_ss_back_pages = get_posts(array(
    'post_type'      => 'page',
    'posts_per_page' => 1,
    'meta_key'       => '_wp_page_template',
    'meta_value'     => 'page-shuoshuo.php',
    'fields'         => 'ids',
    'no_found_rows'  => true,
    'suppress_filters' => false,
));
if (!empty($kratos_ss_back_pages)) {
    $kratos_ss_back_url = get_permalink($kratos_ss_back_pages[0]);
}
?>
<div class="k-main <?php echo $kratos_ss_has_banner ? 'banner' : 'color'; ?>" style="background:<?php echo esc_attr($kratos_ss_bg); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> details">
                <?php if (have_posts()) : the_post();
                    update_post_caches($posts);

                    $post_id    = get_the_ID();
                    $author_id  = (int) get_post_field('post_author', $post_id);
                    $author     = get_the_author_meta('display_name', $author_id);
                    if (!$author) {
                        $author = get_bloginfo('name');
                    }
                    $avatar     = get_avatar($author_id, 112, '', $author, array('class' => 'kss-avatar-img'));
                    $time_human = human_time_diff(get_the_time('U', $post_id), current_time('timestamp')) . __('前', 'kratos');
                    $time_full  = get_the_date(get_option('date_format') . ' ' . get_option('time_format'), $post_id);

                    $parts  = kratos_shuoshuo_split_content(get_the_content('', false, $post_id));
                    $images = $parts['images'];
                    $text   = $parts['text_html'];
                    $img_count = count($images);

                    if ($img_count === 0 && has_post_thumbnail($post_id)) {
                        $thumb = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'large');
                        if ($thumb) {
                            $images = array($thumb);
                            $img_count = 1;
                        }
                    }

                    if ($img_count === 1)      $grid_class = 'kss-grid-1';
                    elseif ($img_count === 2)  $grid_class = 'kss-grid-2';
                    elseif ($img_count === 3)  $grid_class = 'kss-grid-3';
                    elseif ($img_count === 4)  $grid_class = 'kss-grid-4';
                    else                       $grid_class = 'kss-grid-9';
                ?>
                    <a class="kratos-shuoshuo-back" href="<?php echo esc_url($kratos_ss_back_url); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        <?php esc_html_e('返回说说', 'kratos'); ?>
                    </a>
                    <div class="kratos-shuoshuo kratos-shuoshuo-single" id="lightgallery" data-lightbox-host="1">
                        <ul class="kss-list">
                            <li class="kss-item">
                                <div class="kss-avatar"><?php echo $avatar; ?></div>
                                <div class="kss-body">
                                    <div class="kss-author"><?php echo esc_html($author); ?></div>
                                    <?php if ($text !== '') { ?>
                                        <div class="kss-text"><?php echo $text; ?></div>
                                    <?php } ?>
                                    <?php if ($img_count > 0) {
                                        $extra = $img_count > 9 ? ($img_count - 9) : 0;
                                    ?>
                                        <div class="kss-images <?php echo esc_attr($grid_class); ?>" id="kss-gallery-<?php echo (int) $post_id; ?>">
                                            <?php foreach ($images as $i => $src) {
                                                $is_hidden = ($i >= 9);
                                                $is_last_visible_with_more = ($extra > 0 && $i === 8);
                                            ?>
                                                <a class="kss-img-cell<?php echo $is_hidden ? ' kss-img-hidden' : ''; ?><?php echo $is_last_visible_with_more ? ' kss-img-more' : ''; ?>" href="<?php echo esc_url($src); ?>" data-src="<?php echo esc_url($src); ?>"<?php echo $is_hidden ? ' aria-hidden="true"' : ''; ?>>
                                                    <span class="kss-img-bg" style="background-image:url('<?php echo esc_url($src); ?>');"></span>
                                                    <?php if ($is_last_visible_with_more) { ?>
                                                        <span class="kss-img-more-mask">+<?php echo (int) $extra; ?></span>
                                                    <?php } ?>
                                                </a>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>
                                    <div class="kss-meta">
                                        <span class="kss-time" title="<?php echo esc_attr($time_full); ?>"><?php echo esc_html($time_full); ?> · <?php echo esc_html($time_human); ?></span>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <?php echo kratos_shuoshuo_assets(); ?>
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
