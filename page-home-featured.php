<?php

/*
 * Template Name: 特色首页
 *
 * 杂志式首页：焦点区 / 推荐位 / 分类专区（tab）/ 热门榜 / 最新文章 / 数据条。
 * 数据与 markup 由 [home_featured] 短码组织（inc/theme-home-featured.php）。
 *
 * 启用方式：
 *   1. 新建一个页面，右侧「页面属性 → 模板」选「特色首页」并发布；
 *   2. 设置 → 阅读 → 首页显示「一个静态页面」，主页选该页面；
 *      文章列表建议同时指定一个「文章页」，模块里的「进入文章列表」会指向它。
 *
 * 模块顺序、开关、各模块标题 / 副标题 / 图标、数据来源与条数：
 *   主题选项 → 特色首页
 *
 * 页面正文（编辑器里写的内容）会渲染在模块之上，可放一段站点导语；留空则不占位。
 *
 * 布局：默认全宽（col-lg-12），模块自带的双栏/三列网格已经承担了信息分区，
 * 再挂一条侧栏会把焦点区大图压窄。需要侧栏时在「主题选项 → 特色首页 →
 * 显示侧边栏」打开，此时回落到主题统一的 kratos_layout_cols() 主/侧比例。
 *
 * 主题装饰（皮肤层对 .details 的玻璃/新拟态/极简形状）由 body class
 * is-kratos-home-page 豁免，避免模块卡片外再套一层卡。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

get_header();
$kratos_hf_sidebar = kratos_option('hf_sidebar', false);
$kratos_cols = kratos_layout_cols();
$kratos_hf_main = $kratos_hf_sidebar ? $kratos_cols['main'] : 'col-lg-12'; ?>
<div class="k-main <?php echo kratos_option('top_img_switch', true) ? 'banner' : 'color' ?>" style="background:<?php echo kratos_option('g_background', '#f5f5f5'); ?>">
    <div class="container">
        <div class="row">
            <div class="<?php echo $kratos_hf_main; ?> details">
                <?php if (have_posts()) : the_post();
                    update_post_caches($posts); ?>
                    <div class="content" id="lightgallery">
                        <?php the_content(); ?>
                        <?php echo do_shortcode('[home_featured]'); ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($kratos_hf_sidebar) : ?>
                <div class="<?php echo $kratos_cols['sidebar']; ?> sidebar sticky-sidebar d-none d-lg-block">
                    <?php dynamic_sidebar('page_sidebar'); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
