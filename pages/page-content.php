<?php

/**
 * 文章列表
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2022.02.20
 */
$kratos_layout = kratos_option('g_list_layout', 'classic');
$kratos_thumb = kratos_option('g_thumbnail', true);

// 置顶 / 热门角标。缎带图标只在卡片直角上成立，时序流没有逐篇卡片，
// 那套布局改用日期下的一枚文字小标签。
$kratos_is_top = is_sticky();
$kratos_hot_comment = kratos_option('g_article_fieldset')['g_article_comment'] ?? '20';
$kratos_hot_love = kratos_option('g_article_fieldset')['g_article_love'] ?? '200';
$kratos_is_hot = !$kratos_is_top && (findSinglecomments($post->ID) >= $kratos_hot_comment || get_post_meta($post->ID, 'love', true) >= $kratos_hot_love);
$kratos_flag_layout = ($kratos_layout === 'chronicle'); ?>
<div class="article-panel">
    <?php if ($kratos_layout === 'chronicle') { ?>
        <div class="a-date">
            <span class="a-date-day"><?php echo esc_html(get_the_date('d')); ?></span>
            <span class="a-date-ym"><?php printf(esc_html__('%1$s月 / %2$s', 'kratos'), get_the_date('n'), get_the_date('Y')); ?></span>
            <?php if ($kratos_is_top) { ?>
                <span class="a-date-flag is-top"><?php _e('置顶', 'kratos'); ?></span>
            <?php } elseif ($kratos_is_hot) { ?>
                <span class="a-date-flag is-hot"><?php _e('热门', 'kratos'); ?></span>
            <?php } ?>
        </div>
    <?php }
    if (!$kratos_flag_layout) { ?>
        <span class="a-card">
            <?php if ($kratos_is_top) { ?><i class="kicon i-card-top"></i><?php } elseif ($kratos_is_hot) { ?><i class="kicon i-card-hot"></i><?php } ?>
        </span>
    <?php }
    if ($kratos_thumb) { ?>
        <div class="a-thumb">
            <a href="<?php the_permalink(); ?>">
                <?php post_thumbnail(); ?>
            </a>
        </div>
    <?php } ?>
    <div class="a-post <?php echo $kratos_thumb ?: 'a-none'; ?>">
        <div class="header">
            <h3 class="title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
        </div>
        <div class="content">
            <p><?php echo wp_trim_words(get_the_excerpt(), 260); ?></p>
        </div>
    </div>
    <div class="a-meta">
        <div class="a-meta-items kr-meta">
            <?php echo kratos_post_meta_items_html(); ?>
        </div>
    </div>
</div>
