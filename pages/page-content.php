<?php

/**
 * 文章列表
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2022.02.20
 */
?>
<div class="article-panel">
    <span class="a-card">
        <?php $article_comment = kratos_option('g_article_fieldset')['g_article_comment'] ?? '20';
        $article_love = kratos_option('g_article_fieldset')['g_article_love'] ?? '200';
        if (is_sticky()) { ?>
            <i class="kicon i-card-top"></i>
        <?php } elseif (findSinglecomments($post->ID) >= $article_comment || get_post_meta($post->ID, 'love', true) >= $article_love) { ?>
            <i class="kicon i-card-hot"></i>
        <?php } ?>
    </span>
    <?php if (kratos_option('g_thumbnail', true)) { ?>
        <div class="a-thumb">
            <a href="<?php the_permalink(); ?>">
                <?php post_thumbnail(); ?>
            </a>
        </div>
    <?php } ?>
    <div class="a-post <?php echo kratos_option('g_thumbnail', true) ?: 'a-none'; ?>">
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
