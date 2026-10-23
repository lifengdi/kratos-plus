<?php

/**
 * media_item 条目详情模板
 *
 * 结构：
 *   hero（封面 + 元信息 + 评分 + 操作）
 *   心得体会（the_content）
 *   划线笔记（预留，读 post_meta _kml_highlights 数组）
 *   关联文章 / 相关推荐（同 term 的其他条目）
 *   上下条
 *   评论区（复用主题 comments.php）
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

get_header();
$kratos_cols = kratos_layout_cols();
?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color' ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> details">
                <?php if (have_posts()) : the_post();
                    $pid = get_the_ID();
                    $m   = kratos_kml_item_meta($pid);
                    $terms = get_the_terms($pid, 'media_type');
                    $term  = (is_array($terms) && !empty($terms)) ? $terms[0] : null;
                    $tm    = $term ? kratos_kml_term_meta($term->term_id) : array('color' => '#999999', 'icon' => '');
                    $status = $m['_kml_status'];
                    $status_text = $status ? kratos_kml_status_label($status, $term ? $term->slug : '') : '';
                    $rating = $m['_kml_rating'];
                    $full_stars = $rating ? (int) round(((float)$rating) / 2) : 0;
                ?>
                <div class="kratos-media-single">

                    <!-- hero -->
                    <div class="kr-body">
                        <div class="kmd-hero">
                            <div class="kmd-cover"<?php if ($m['_kml_cover']) echo ' style="background-image:url(' . esc_url($m['_kml_cover']) . ')"'; ?>></div>
                            <div class="kmd-head">
                                <div class="kmd-pills">
                                    <?php if ($term): ?>
                                        <span class="kr-pill kmd-pill-type" style="--kml-pill-c:<?php echo esc_attr($tm['color']); ?>">
                                            <?php if ($tm['icon']): ?><?php echo esc_html($tm['icon']); ?> <?php endif; ?><?php echo esc_html($term->name); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($status_text): ?>
                                        <span class="kr-pill kmd-pill-status is-<?php echo esc_attr($status); ?>"><?php echo esc_html($status_text); ?></span>
                                    <?php endif; ?>
                                </div>
                                <h1><?php the_title(); ?></h1>
                                <?php if ($m['_kml_orig_title']): ?>
                                    <p class="kmd-orig"><?php echo esc_html($m['_kml_orig_title']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="kmd-body">
                                <dl class="kmd-info-row">
                                    <?php if ($m['_kml_creator']): ?><dt>作者 / 导演</dt><dd><?php echo esc_html($m['_kml_creator']); ?></dd><?php endif; ?>
                                    <?php if ($m['_kml_year']): ?><dt>年份</dt><dd><?php echo esc_html($m['_kml_year']); ?></dd><?php endif; ?>
                                    <?php if ($m['_kml_isbn']): ?><dt>ISBN</dt><dd><?php echo esc_html($m['_kml_isbn']); ?></dd><?php endif; ?>
                                    <?php if ($m['_kml_imdb_id']): ?><dt>IMDb</dt><dd><?php echo esc_html($m['_kml_imdb_id']); ?></dd><?php endif; ?>
                                    <?php if ($m['_kml_finished_at']): ?><dt>完成于</dt><dd><?php echo esc_html(mysql2date(get_option('date_format'), $m['_kml_finished_at'])); ?></dd><?php endif; ?>
                                    <?php if ($m['_kml_douban_url']): ?><dt>豆瓣</dt><dd><a href="<?php echo esc_url($m['_kml_douban_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($m['_kml_douban_url']); ?></a></dd><?php endif; ?>
                                </dl>

                                <?php if ($rating !== '' && $rating !== null): ?>
                                <div class="kmd-rating">
                                    <div class="kmd-rating-num"><?php echo esc_html(number_format((float)$rating, 1)); ?></div>
                                    <div>
                                        <div class="kmd-stars">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <svg class="<?php echo $i <= $full_stars ? 'is-on' : ''; ?>" viewBox="0 0 24 24" width="16" height="16"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="kmd-rating-text"><?php _e('个人评分', 'kratos'); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="kmd-actions">
                                    <a class="kr-btn" href="#respond" onclick="var ta=document.getElementById('comment');if(ta){ta.scrollIntoView({behavior:'smooth',block:'center'});setTimeout(function(){ta.focus()},450);}return false;"><?php _e('写一则想法', 'kratos'); ?></a>
                                    <?php if ($m['_kml_douban_url']): ?>
                                        <a class="kr-btn is-ghost" href="<?php echo esc_url($m['_kml_douban_url']); ?>" target="_blank" rel="noopener"><?php _e('查看豆瓣页', 'kratos'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 心得体会 -->
                    <?php if (trim(get_the_content()) !== ''): ?>
                    <div class="kr-body">
                        <h2 class="kmd-section-title"><?php _e('心得体会', 'kratos'); ?></h2>
                        <div class="kmd-review"><?php the_content(); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- 划线笔记 -->
                    <?php
                    $highlights = get_post_meta($pid, '_kml_highlights', true);
                    if (!is_array($highlights) && isset($m['_kml_highlights']) && is_array($m['_kml_highlights'])) {
                        $highlights = $m['_kml_highlights'];
                    }
                    if (is_array($highlights) && !empty($highlights)): ?>
                    <div class="kr-body">
                        <h2 class="kmd-section-title"><?php _e('划线与笔记', 'kratos'); ?></h2>
                        <div class="kmd-highlights">
                            <?php foreach ($highlights as $h):
                                if (!is_array($h)) $h = array('text' => $h, 'meta' => '');
                                $text = isset($h['text']) ? trim($h['text']) : '';
                                if ($text === '') continue;
                                ?>
                                <div class="kmd-highlight">
                                    <?php echo nl2br(esc_html($text)); ?>
                                    <?php if (!empty($h['meta'])): ?>
                                        <div class="kmd-highlight-meta"><?php echo esc_html($h['meta']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 关联博客文章 -->
                    <?php
                    $related_ids = get_post_meta($pid, '_kml_related_posts', true);
                    if (!is_array($related_ids) && isset($m['_kml_related_posts']) && is_array($m['_kml_related_posts'])) {
                        $related_ids = $m['_kml_related_posts'];
                    }
                    $related_ids = is_array($related_ids) ? array_filter(array_map('intval', $related_ids)) : array();
                    if ($related_ids):
                        $rel_q = new WP_Query(array(
                            'post_type'      => 'post',
                            'post__in'       => $related_ids,
                            'orderby'        => 'post__in',
                            'posts_per_page' => count($related_ids),
                            'ignore_sticky_posts' => true,
                            'no_found_rows'  => true,
                        ));
                        if ($rel_q->have_posts()): ?>
                        <div class="kr-body">
                            <h2 class="kmd-section-title"><?php _e('关联博客文章', 'kratos'); ?></h2>
                            <div class="kmd-highlights">
                                <?php while ($rel_q->have_posts()): $rel_q->the_post(); ?>
                                    <a class="kmd-highlight" href="<?php the_permalink(); ?>" style="display:block">
                                        <?php the_title(); ?>
                                        <div class="kmd-highlight-meta">
                                            <?php echo esc_html(get_the_date()); ?>
                                            <?php $views = function_exists('kratos_get_post_views') ? kratos_get_post_views(get_the_ID()) : 0; if ($views): ?> · <?php printf(__('阅读 %s', 'kratos'), esc_html($views)); ?><?php endif; ?>
                                            <?php $c = get_comments_number(); if ($c): ?> · <?php printf(__('评论 %d', 'kratos'), (int)$c); ?><?php endif; ?>
                                        </div>
                                    </a>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    <?php endif;
                        wp_reset_postdata();
                    endif; ?>

                    <!-- 相关推荐 -->
                    <?php if ($term):
                        $related = new WP_Query(array(
                            'post_type'      => 'media_item',
                            'posts_per_page' => 4,
                            'post__not_in'   => array($pid),
                            'orderby'        => 'rand',
                            'tax_query'      => array(array('taxonomy' => 'media_type', 'field' => 'term_id', 'terms' => $term->term_id)),
                            'no_found_rows'  => true,
                        ));
                        if ($related->have_posts()): ?>
                        <div class="kr-body">
                            <h2 class="kmd-section-title"><?php _e('你可能也会喜欢', 'kratos'); ?></h2>
                            <div class="kmd-related">
                                <?php while ($related->have_posts()): $related->the_post(); kratos_kml_render_card(get_the_ID()); endwhile; ?>
                            </div>
                        </div>
                    <?php endif;
                        wp_reset_postdata();
                    endif; ?>

                    <!-- 评论 -->
                    <?php if (comments_open() || get_comments_number()) {
                        comments_template();
                    } ?>

                </div>
                <?php endif; ?>
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
