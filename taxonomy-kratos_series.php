<?php

/**
 * 系列文章分类归档模板 —— /series/<slug>/
 *
 * 视觉复用 Kratos+特色标题（page-featured-title.php）的头部与卡片体系：
 *  - 图标：term meta kratos_series_icon（未设置时由 kratos_series_get_icon() 回落 fa-solid fa-layer-group）
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

global $wp_query;
$total   = (int) $wp_query->found_posts;
$paged   = max(1, (int) get_query_var('paged'));
$per     = max(1, (int) get_option('posts_per_page'));
$start_i = ($paged - 1) * $per; // 用于生成本页起始序号

// 面包屑：从当前 term 递归向上到顶层
$ksa_ancestors = array();
if ($term) {
    $pid = (int) $term->parent;
    while ($pid > 0) {
        $p = get_term($pid, 'kratos_series');
        if (!$p || is_wp_error($p)) break;
        array_unshift($ksa_ancestors, $p);
        $pid = (int) $p->parent;
    }
}
// 子系列
$ksa_children = $term ? get_terms(array(
    'taxonomy'   => 'kratos_series',
    'parent'     => $term->term_id,
    'hide_empty' => false,
)) : array();
if (is_wp_error($ksa_children)) $ksa_children = array();
$ksa_children = kratos_series_sort_terms($ksa_children);
?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color' ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_cols['main']; ?> board">
                <div class="kratos-friend-links kratos-featured-title kratos-series-archive">
                    <nav class="ksa-breadcrumb" aria-label="<?php esc_attr_e('系列层级', 'kratos'); ?>">
                        <a href="<?php echo esc_url(home_url('/')); ?>"><?php _e('首页', 'kratos'); ?></a>
                        <span class="ksa-sep">›</span>
                        <span class="ksa-crumb-label"><?php _e('系列', 'kratos'); ?></span>
                        <?php foreach ($ksa_ancestors as $anc) : ?>
                            <span class="ksa-sep">›</span>
                            <a href="<?php echo esc_url(get_term_link($anc)); ?>"><?php echo esc_html($anc->name); ?></a>
                        <?php endforeach; ?>
                        <span class="ksa-sep">›</span>
                        <span class="ksa-current"><?php echo esc_html($series_title); ?></span>
                    </nav>
                    <header class="kfl-header kr-hd">
                        <?php if ($series_icon !== '') { ?>
                            <span class="kfl-title-icon kr-ico" aria-hidden="true">
                                <i class="<?php echo esc_attr($series_icon); ?>"></i>
                            </span>
                        <?php } ?>
                        <span class="kfl-title kr-hd-title"><?php echo esc_html($series_title); ?></span>
                        <?php if ($series_desc !== '') { ?>
                            <span class="kfl-header-divider kr-hd-divider" aria-hidden="true"></span>
                            <p class="kfl-subtitle kr-hd-sub"><?php echo esc_html($series_desc); ?></p>
                        <?php } ?>
                    </header>

                    <?php if (!empty($ksa_children)) : ?>
                        <div class="ksa-children">
                            <h3 class="ksa-children-title"><?php _e('子系列', 'kratos'); ?></h3>
                            <ul class="ksa-children-list">
                                <?php foreach ($ksa_children as $c) :
                                    $c_icon = kratos_series_get_icon($c->term_id);
                                    $c_desc = trim(strip_tags(term_description($c->term_id, 'kratos_series')));
                                ?>
                                    <li class="ksa-child-item">
                                        <a href="<?php echo esc_url(get_term_link($c)); ?>">
                                            <span class="ksa-child-icon" aria-hidden="true">
                                                <i class="<?php echo esc_attr($c_icon); ?>"></i>
                                            </span>
                                            <span class="ksa-child-body">
                                                <span class="ksa-child-name">
                                                    <?php echo esc_html($c->name); ?>
                                                    <span class="ksa-child-count"><?php echo (int) $c->count; ?></span>
                                                </span>
                                                <?php if ($c_desc !== '') : ?>
                                                    <span class="ksa-child-desc"><?php echo esc_html($c_desc); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="content kft-content ksa-content kr-card">
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
                                                <span class="ksa-meta kr-meta">
                                                    <?php // 与文章列表 meta 区完全一致（同一个 helper）；整条已被 <a> 包住，分类不能再嵌链接
                                                    echo kratos_post_meta_items_html($p->ID, array('link' => false)); ?>
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
                        --khs-bg-1:var(--kr-skin-tag-bg,#f5f5f5);--khs-bg-2:var(--kr-skin-tag-bg,#f0f0f0);--khs-bg-3:var(--kr-skin-card-soft,#ebebeb);
                        --khs-fg:var(--kr-skin-text,#333);--khs-fg-soft:var(--kr-skin-muted,#444);
                        --khs-accent:var(--kr-skin-accent,#336699);
                        --khs-line:var(--kr-skin-card-line,rgba(0,0,0,.08));--khs-line-strong:var(--kr-skin-card-line,rgba(0,0,0,.16));
                        --khs-card-bg:var(--kr-skin-card-bg,#ffffff);
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
                    .ksa-meta{font-size:12px;color:var(--khs-fg-soft);display:flex;flex-wrap:wrap;align-items:center;gap:4px 14px;}
                    .ksa-meta i{font-size:11px;opacity:.7;}
                    /* 面包屑 */
                    .ksa-breadcrumb{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin:0 0 12px;padding:0 4px;font-size:13px;color:var(--khs-fg-soft);}
                    .ksa-breadcrumb a{color:var(--khs-fg-soft);text-decoration:none;transition:color .18s ease;}
                    .ksa-breadcrumb a:hover{color:var(--khs-accent);}
                    .ksa-breadcrumb .ksa-sep{color:var(--khs-line-strong);}
                    .ksa-breadcrumb .ksa-crumb-label{opacity:.85;}
                    .ksa-breadcrumb .ksa-current{color:var(--khs-fg);font-weight:600;}
                    /* 子系列 */
                    .ksa-children{margin:0 0 18px;padding:18px 22px;background:var(--khs-card-bg);border:1px solid var(--khs-line);border-radius:14px;box-shadow:var(--khs-card-shadow);}
                    .ksa-children-title{margin:0 0 12px;font-size:15px;font-weight:600;color:var(--khs-fg);position:relative;padding-left:10px;}
                    .ksa-children-title::before{content:"";position:absolute;left:0;top:4px;bottom:4px;width:3px;background:var(--khs-accent);border-radius:2px;}
                    .ksa-children-list{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;}
                    .ksa-child-item{margin:0;}
                    .ksa-child-item > a{display:flex;gap:10px;align-items:center;padding:10px 12px;border:1px solid var(--khs-line);border-radius:8px;text-decoration:none;color:var(--khs-fg);background:var(--khs-bg-1);transition:transform .18s ease,border-color .18s ease,color .18s ease;}
                    .ksa-child-item > a:hover{transform:translateY(-1px);border-color:var(--khs-accent);color:var(--khs-accent);}
                    .ksa-child-icon{flex:0 0 auto;width:28px;height:28px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--khs-bg-2),var(--khs-bg-3));color:var(--khs-accent);}
                    .ksa-child-icon i{font-size:14px;line-height:1;}
                    .ksa-child-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;}
                    .ksa-child-name{font-size:14px;font-weight:600;line-height:1.4;display:flex;align-items:center;gap:6px;}
                    .ksa-child-count{font-size:11px;font-weight:500;padding:0 6px;background:rgba(51,102,153,.1);color:var(--khs-accent);border-radius:8px;}
                    html[data-theme="dark"] .ksa-child-count,body.dark .ksa-child-count{background:rgba(110,168,255,.15);}
                    .ksa-child-desc{font-size:12px;color:var(--khs-fg-soft);line-height:1.5;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;max-height:1.5em;}
                    @media (max-width:640px){
                        .kratos-featured-title .kfl-header{padding:18px 18px;gap:10px;}
                        .kratos-featured-title .kfl-title{font-size:19px;}
                        .kratos-featured-title .kfl-subtitle{flex-basis:100%;font-size:13px;}
                        .kratos-featured-title .kft-content{padding:18px;}
                        .ksa-item > a{padding:12px;gap:10px;}
                        .ksa-children{padding:14px 16px;}
                        .ksa-children-list{grid-template-columns:1fr;}
                    }
                </style>
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
