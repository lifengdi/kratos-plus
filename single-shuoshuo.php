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
                    $videos = isset($parts['videos']) ? $parts['videos'] : array();
                    $text   = $parts['text_html'];
                    // 没有媒体但有特色图时，把特色图当单图展示
                    if (!$images && !$videos && has_post_thumbnail($post_id)) {
                        $thumb = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'large');
                        if ($thumb) {
                            $images = array($thumb);
                        }
                    }

                    $love          = (int) get_post_meta($post_id, 'love', true);
                    $comment_count = (int) get_comments_number($post_id);
                    $has_loved     = isset($_COOKIE['love_' . $post_id]);
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
                                    <?php
                                    // 详情页容器更宽（.kss-images 520px / 单图 560px），
                                    // 所以申报的图位宽度比列表页大一档
                                    kratos_media_render($post_id, $images, $videos, array(
                                        'grid_id'      => 'kss-gallery-' . (int) $post_id,
                                        'grid_sizes'   => '(max-width: 640px) 31vw, 172px',
                                        'single_size'  => 'large',
                                        'single_sizes' => '(max-width: 640px) 92vw, 560px',
                                        'data_src'     => true,
                                    ));
                                    ?>
                                    <div class="kss-meta">
                                        <span class="kss-time" title="<?php echo esc_attr($time_full); ?>"><?php echo esc_html($time_full); ?> · <?php echo esc_html($time_human); ?></span>
                                        <span class="kss-actions">
                                            <a class="kss-action kss-like <?php echo $has_loved ? 'done' : ''; ?>" href="javascript:;" data-id="<?php echo (int) $post_id; ?>" title="<?php esc_attr_e('点赞', 'kratos'); ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-9.5-9.1C1.1 8.6 3 5 6.3 5c1.9 0 3.6 1.1 4.4 2.7C11.6 6.1 13.3 5 15.2 5c3.3 0 5.2 3.6 3.8 6.9C19.5 16.4 12 21 12 21z"/></svg>
                                                <em><?php echo (int) $love; ?></em>
                                            </a>
                                            <a class="kss-action" href="#respond" title="<?php esc_attr_e('评论', 'kratos'); ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                                <em><?php echo (int) $comment_count; ?></em>
                                            </a>
                                        </span>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <?php echo kratos_shuoshuo_assets(); ?>
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
