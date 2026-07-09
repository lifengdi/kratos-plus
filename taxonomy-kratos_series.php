<?php

/**
 * 系列文章分类归档模板 —— /series/<slug>/
 *
 * 视觉复用 Kratos+特色标题（page-featured-title.php）的头部与卡片体系：
 *  - 图标：term meta kratos_series_icon（未设置回退默认星形 SVG）
 *  - 标题：系列名
 *  - 描述：term description（未填不展示副标题及分隔线）
 * 文章列表按 kratos_series_get_posts() 顺序（order meta + 发布时间）渲染。
 *
 * @author Dylan Li (Kratos+)
 * @license GPL-3.0 License
 */

get_header();
$kratos_cols = kratos_layout_cols();

$term = get_queried_object();
$series_title    = $term ? $term->name : '';
$series_desc     = $term ? trim(strip_tags(term_description($term->term_id, 'kratos_series'))) : '';
$series_icon     = ($term && function_exists('kratos_series_get_icon')) ? kratos_series_get_icon($term->term_id) : '';
$series_icon_raw = $term ? get_term_meta($term->term_id, 'kratos_series_icon', true) : '';
// 若用户未设置图标，展示与特色标题一致的默认星形 SVG
$has_custom_icon = is_string($series_icon_raw) && trim($series_icon_raw) !== '';

global $wp_query;
$total   = (int) $wp_query->found_posts;
$paged   = max(1, (int) get_query_var('paged'));
$per     = max(1, (int) get_option('posts_per_page'));
$start_i = ($paged - 1) * $per; // 用于生成本页起始序号
?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color' ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> board">
                <div class="kratos-friend-links kratos-featured-title kratos-series-archive">
                    <header class="kfl-header">
                        <span class="kfl-title-icon" aria-hidden="true">
                            <?php if ($has_custom_icon) { ?>
                                <i class="<?php echo esc_attr($series_icon); ?>"></i>
                            <?php } else { ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.39 4.84L20 8l-4 3.9.94 5.5L12 14.9 7.06 17.4 8 11.9 4 8l5.61-1.16L12 2z"/></svg>
                            <?php } ?>
                        </span>
                        <span class="kfl-title"><?php echo esc_html($series_title); ?></span>
                        <?php if ($series_desc !== '') { ?>
                            <span class="kfl-header-divider" aria-hidden="true"></span>
                            <p class="kfl-subtitle"><?php echo esc_html($series_desc); ?></p>
                        <?php } ?>
                    </header>

                    <div class="content kft-content ksa-content">
                        <?php if ($total === 0) { ?>
                            <p class="ksa-empty"><?php _e('该系列暂无文章', 'kratos'); ?></p>
                        <?php } else { ?>
                            <p class="ksa-total"><?php printf(esc_html__('共 %d 篇文章', 'kratos'), (int) $total); ?></p>
                            <ol class="ksa-list">
                                <?php $ksa_i = 0; while (have_posts()) : the_post(); $p = get_post(); $ksa_i++; ?>
                                    <li class="ksa-item">
                                        <a href="<?php the_permalink(); ?>" title="<?php echo esc_attr(get_the_title()); ?>">
                                            <span class="ksa-num"><?php echo (int)($start_i + $ksa_i); ?></span>
                                            <span class="ksa-body">
                                                <span class="ksa-name"><?php echo esc_html(get_the_title()); ?></span>
                                                <?php $ksa_len = max(10, (int) kratos_option('g_excerpt_length', 260));
                                                $ksa_raw = ($p->post_excerpt !== '') ? $p->post_excerpt : $p->post_content;
                                                $excerpt = trim(strip_tags(wp_trim_words($ksa_raw, $ksa_len, '…')));
                                                if ($excerpt !== '') { ?>
                                                    <span class="ksa-excerpt"><?php echo esc_html($excerpt); ?></span>
                                                <?php } ?>
                                                <span class="ksa-meta">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo esc_html(get_the_date()); ?>
                                                </span>
                                            </span>
                                        </a>
                                    </li>
                                <?php endwhile; ?>
                            </ol>
                        <?php } ?>
                    </div>
                </div>
                <?php if (function_exists('pagelist')) pagelist(); ?>
                <style>
                    .kratos-featured-title{
                        --khs-bg-1:#f5f5f5;--khs-bg-2:#f0f0f0;--khs-bg-3:#ebebeb;
                        --khs-fg:#333;--khs-fg-soft:#444;
                        --khs-accent:#336699;
                        --khs-line:rgba(0,0,0,.08);--khs-line-strong:rgba(0,0,0,.16);
                        --khs-card-bg:#ffffff;
                        --khs-card-shadow:0 1px 3px rgba(0,0,0,.06);
                        padding:0;background:transparent;max-width:100%;
                    }
                    .kratos-featured-title .kfl-header{
                        display:flex;align-items:center;flex-wrap:wrap;gap:14px;
                        padding:24px 28px;margin-bottom:18px;
                        background:var(--khs-card-bg);
                        border:1px solid var(--khs-line);
                        border-radius:14px;
                        box-shadow:var(--khs-card-shadow);
                    }
                    .kratos-featured-title .kfl-title-icon{
                        display:inline-flex;align-items:center;justify-content:center;
                        width:38px;height:38px;border-radius:10px;
                        background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
                        color:var(--khs-accent);
                    }
                    .kratos-featured-title .kfl-title-icon i{font-size:18px;line-height:1;}
                    .kratos-featured-title .kfl-title{
                        margin:0;padding:0;font-size:22px;font-weight:700;line-height:1.3;
                        color:var(--khs-fg);
                    }
                    .kratos-featured-title .kfl-header-divider{
                        display:inline-block;width:1px;height:22px;background:var(--khs-line-strong);
                    }
                    .kratos-featured-title .kfl-subtitle{
                        margin:0;padding:0;font-size:14px;line-height:1.5;color:var(--khs-fg-soft);
                    }
                    .kratos-featured-title .kft-content{
                        padding:24px 28px;
                        background:var(--khs-card-bg);
                        border:1px solid var(--khs-line);
                        border-radius:14px;
                        box-shadow:var(--khs-card-shadow);
                        color:var(--khs-fg);
                        word-wrap:break-word;
                        word-break:break-word;
                    }
                    /* 列表 */
                    .ksa-total{margin:0 0 16px;font-size:13px;color:var(--khs-fg-soft);}
                    .ksa-empty{margin:0;color:var(--khs-fg-soft);font-size:14px;}
                    .ksa-list{list-style:none;margin:0;padding:0;counter-reset:none;}
                    .ksa-item{margin:0;padding:0;}
                    .ksa-item + .ksa-item{margin-top:10px;}
                    .ksa-item > a{
                        display:flex;gap:14px;align-items:flex-start;
                        padding:14px 16px;border-radius:10px;
                        border:1px solid var(--khs-line);
                        background:var(--khs-bg-1);
                        color:var(--khs-fg);text-decoration:none;
                        transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease,color .18s ease;
                    }
                    .ksa-item > a:hover{
                        transform:translateY(-1px);
                        border-color:var(--khs-accent);
                        box-shadow:var(--khs-card-shadow);
                        color:var(--khs-accent);
                    }
                    .ksa-num{
                        flex:0 0 auto;min-width:28px;height:28px;line-height:28px;
                        text-align:center;font-size:13px;font-weight:600;
                        color:#fff;background:var(--khs-accent);border-radius:50%;
                    }
                    .ksa-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px;}
                    .ksa-name{font-size:15px;font-weight:600;line-height:1.5;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;max-height:3em;}
                    .ksa-excerpt{
                        font-size:13px;line-height:1.6;color:var(--khs-fg-soft);
                        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-overflow:ellipsis;max-height:3.2em;
                    }
                    .ksa-meta{font-size:12px;color:var(--khs-fg-soft);display:inline-flex;align-items:center;gap:6px;}
                    .ksa-meta i{font-size:11px;opacity:.7;}
                    @media (max-width:640px){
                        .kratos-featured-title .kfl-header{padding:18px 18px;gap:10px;}
                        .kratos-featured-title .kfl-title{font-size:19px;}
                        .kratos-featured-title .kfl-subtitle{flex-basis:100%;font-size:13px;}
                        .kratos-featured-title .kft-content{padding:18px;}
                        .ksa-item > a{padding:12px;gap:10px;}
                    }
                    html[data-theme="dark"] .kratos-featured-title,
                    body.dark .kratos-featured-title{
                        --khs-bg-1:#22262d;--khs-bg-2:#2a2e35;--khs-bg-3:#333842;
                        --khs-fg:#d6d8db;--khs-fg-soft:#b8bbc0;
                        --khs-accent:#6ea8ff;
                        --khs-line:rgba(255,255,255,.08);--khs-line-strong:rgba(255,255,255,.16);
                        --khs-card-bg:#1c1f24;
                    }
                </style>
            </div>
            <div class="<?php echo $kratos_cols['sidebar']; ?> sidebar sticky-sidebar d-none d-lg-block">
                <?php dynamic_sidebar('page_sidebar'); ?>
            </div>
        </div>
    </div>
</div>
<?php get_footer(); ?>
