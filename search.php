<?php

/**
 * 搜索结果模板
 *
 * 在此模板出现之前，搜索结果由 index.php 兜底（仅在列表上方加一行「搜索内容：」）。
 * 现在改为独立结果页：
 *   - 页头卡：关键词 + 命中总数 + 二次搜索框
 *   - 结果分组：文章（主查询，可分页）/ 说说 / 系列（仅第一页展示）
 *   - 关键词高亮 + 以命中词为中心的摘要窗口
 *   - 零结果兜底：随机漫步 + 热门标签 + 回首页
 *
 * 数据层与渲染函数在 inc/theme-search.php。
 * 后台开关「搜索配置 → 增强搜索结果页」关闭时，回落到经典列表样式。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

get_header();
$kratos_cols = kratos_layout_cols();
$kratos_kw = get_search_query();
$kratos_enhance = kratos_option('g_search_enhance', true);
?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color' ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> board">
                <?php if (!$kratos_enhance) { ?>
                    <!-- 经典模式：与旧 index.php 搜索分支一致 -->
                    <div class="article-panel">
                        <div class="search-title"><?php _e('搜索内容：', 'kratos');
                                                    the_search_query(); ?></div>
                    </div>
                    <?php
                    $kratos_list_layout = kratos_option('g_list_layout', 'classic');
                    if (have_posts()) {
                        echo '<div class="post-list layout-' . esc_attr($kratos_list_layout) . ($kratos_list_layout === 'grid' ? ' row' : '') . '">';
                        while (have_posts()) {
                            the_post();
                            if ($kratos_list_layout === 'grid') {
                                echo '<div class="col-md-6 col-sm-12 grid-col">';
                                get_template_part('/pages/page-content', get_post_format());
                                echo '</div>';
                            } else {
                                get_template_part('/pages/page-content', get_post_format());
                            }
                        }
                        echo '</div>';
                    } else { ?>
                        <div class="article-panel">
                            <div class="nothing">
                                <img src="<?php echo kratos_option('g_nothing', ASSET_PATH . '/assets/img/nothing.svg'); ?>">
                                <div class="sorry"><?php _e('很抱歉，没有找到任何内容', 'kratos'); ?></div>
                            </div>
                        </div>
                    <?php }
                    pagelist();
                } else {
                    // === 增强模式 ===
                    global $wp_query;
                    $post_total = (int) $wp_query->found_posts;

                    // 说说 / 系列只在第一页查询与展示，避免翻页时重复出现
                    $shuoshuo = is_paged() ? array() : kratos_search_shuoshuo($kratos_kw, (int) kratos_option('g_search_shuoshuo_max', 5));
                    $series   = is_paged() ? array() : kratos_search_series($kratos_kw, (int) kratos_option('g_search_series_max', 6));

                    $total = $post_total + count($shuoshuo) + count($series);

                    $svg_doc    = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                    $svg_chat   = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
                    $svg_layers = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>';
                    ?>
                    <div class="kratos-search">
                        <?php echo kratos_search_header_html($kratos_kw, $total); ?>

                        <?php if ($total === 0) {
                            echo kratos_search_empty_html($kratos_kw);
                        } else { ?>

                            <?php if (have_posts()) { ?>
                                <section class="ksr-group ksr-group-post">
                                    <?php echo kratos_search_group_head_html(__('文章', 'kratos'), $post_total, $svg_doc); ?>
                                    <?php while (have_posts()) {
                                        the_post();
                                        echo kratos_search_post_row_html(get_the_ID(), $kratos_kw);
                                    } ?>
                                </section>
                            <?php } ?>

                            <?php if (!empty($shuoshuo)) { ?>
                                <section class="ksr-group ksr-group-shuoshuo">
                                    <?php echo kratos_search_group_head_html(__('说说', 'kratos'), count($shuoshuo), $svg_chat); ?>
                                    <?php foreach ($shuoshuo as $ss) { ?>
                                        <article class="ksr-ss kr-card">
                                            <p class="ksr-ss-text"><?php
                                                echo kratos_search_highlight(kratos_search_snippet($ss->post_content, $kratos_kw, 220), $kratos_kw);
                                            ?></p>
                                            <div class="ksr-ss-meta">
                                                <span><?php echo esc_html(get_the_date('Y-m-d H:i', $ss)); ?></span>
                                                <span aria-hidden="true"> · </span>
                                                <a href="<?php echo esc_url(get_permalink($ss)); ?>"><?php esc_html_e('查看', 'kratos'); ?></a>
                                            </div>
                                        </article>
                                    <?php } ?>
                                </section>
                            <?php } ?>

                            <?php if (!empty($series)) { ?>
                                <section class="ksr-group ksr-group-series">
                                    <?php echo kratos_search_group_head_html(__('系列', 'kratos'), count($series), $svg_layers); ?>
                                    <div class="ksr-series-grid">
                                        <?php foreach ($series as $term) {
                                            $term_link = get_term_link($term);
                                            if (is_wp_error($term_link)) {
                                                continue;
                                            } ?>
                                            <a class="ksr-series kr-card" href="<?php echo esc_url($term_link); ?>">
                                                <div class="ksr-series-name"><?php echo kratos_search_highlight($term->name, $kratos_kw); ?></div>
                                                <?php if (trim((string) $term->description) !== '') { ?>
                                                    <div class="ksr-series-desc"><?php echo kratos_search_highlight($term->description, $kratos_kw); ?></div>
                                                <?php } ?>
                                                <div class="ksr-series-desc"><?php
                                                    printf(esc_html__('共 %s 篇', 'kratos'), number_format_i18n((int) $term->count));
                                                ?></div>
                                            </a>
                                        <?php } ?>
                                    </div>
                                </section>
                            <?php } ?>

                        <?php } ?>
                        <?php echo kratos_search_styles(); ?>
                    </div>
                    <?php
                    pagelist();
                }
                wp_reset_query(); ?>
            </div>
            <div class="<?php echo $kratos_cols['sidebar']; ?> sidebar sticky-sidebar d-none d-lg-block">
                <?php dynamic_sidebar('home_sidebar'); ?>
            </div>
        </div>
    </div>
</div>
<?php get_footer(); ?>
