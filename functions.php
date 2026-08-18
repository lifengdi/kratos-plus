<?php

/**
 * 模板函数
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos+ fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2025.02.08
 */

define('THEME_VERSION', '1.1.17');
// 内置 Font Awesome Free 版本（assets/css/fontawesome.min.css + assets/fonts/webfonts/）
define('FA_VERSION', '7.3.1');
// 内置 CodeMirror 版本（assets/codemirror/，取自 npm codemirror@5.62.2，
// 须与 inc/codestar-framework/fields/code_editor/code_editor.php 的 $version 一致）
define('KRATOS_CM_VERSION', '5.62.2');

/**
 * 本地 CodeMirror 资源根 URL。既给 inc/theme-extends.php 的
 * kratos_csf_local_codemirror() 入队用，也作为 code_editor 字段的 `cdnURL` 设置值
 * （CSF main.js 用它拼 CodeMirror.modeURL 懒加载语法模式），因此目录层级必须和
 * npm 包一致（lib/ addon/ mode/）。
 *
 * 定义在这里而不是 theme-extends.php：theme-options.php 由 CSF autoload 立即加载，
 * 早于 theme-extends.php 的 require，字段定义里就要用到它。
 */
function kratos_cm_base_url()
{
    return get_template_directory_uri() . '/assets/codemirror';
}

if (defined('WP_USE_THEMES') && WP_USE_THEMES === false) {
    return;
}

function kratos_dispatch_bg_task($callback) {
    if (function_exists('fastcgi_finish_request')) {
        register_shutdown_function(function () use ($callback) {
            fastcgi_finish_request();
            ignore_user_abort(true);
            set_time_limit(300);
            error_log('[kratos_bg] shutdown callback starting: ' . $callback);
            call_user_func($callback);
            error_log('[kratos_bg] shutdown callback done: ' . $callback);
        });
    } else {
        register_shutdown_function(function () use ($callback) {
            ignore_user_abort(true);
            set_time_limit(300);
            if (!headers_sent()) {
                header('Connection: close');
                header('Content-Length: 0');
            }
            if (ob_get_level()) {
                ob_end_flush();
            }
            flush();
            error_log('[kratos_bg] shutdown callback starting: ' . $callback);
            call_user_func($callback);
            error_log('[kratos_bg] shutdown callback done: ' . $callback);
        });
    }
}

// 代码高亮（必须在 CSF 之前 require —— CSF autoload 会立即加载 theme-options.php，
// 而 theme-options.php 在字段定义里调用了本文件里的 kratos_codehl_*_options() 等函数）
require get_template_directory() . '/inc/theme-codehighlight.php';

// IP 归属地数据库（同样必须在 CSF 之前 require —— theme-options.php 的「评论配置」
// content 字段会立即调用 kratos_ip2region_render_status()）
require get_template_directory() . '/inc/ip2region/ip2region-updater.php';

// 后台版本更新提示与 Release Note 展示（同上，theme-options.php「版本更新」section
// 会在字段定义时调用 kratos_render_update_section()，必须在 CSF 之前 require）
if (is_admin()) {
    require get_template_directory() . '/inc/theme-update-notice.php';
}

// 主题配置
require get_template_directory() . '/inc/codestar-framework/autoload.php';

// 更新配置
require get_template_directory() . '/inc/update-checker/autoload.php';

// 核心配置
require get_template_directory() . '/inc/theme-core.php';

// 站点配置
require get_template_directory() . '/inc/theme-setting.php';

// SEO / 社交分享 meta 统一出口
require get_template_directory() . '/inc/theme-seo.php';

// RSS 订阅扩展（按分类排除）
require get_template_directory() . '/inc/theme-rss.php';

// 文章配置
require get_template_directory() . '/inc/theme-article.php';

require get_template_directory() . '/inc/theme-thumb-placeholder.php';

// LQIP 模糊占位 —— 图片加载态渐显
require get_template_directory() . '/inc/theme-lqip.php';

// 小工具配置
require get_template_directory() . '/inc/theme-widgets.php';

// 文章增强
require get_template_directory() . '/inc/theme-shortcode.php';

// Gutenberg 区块（短码快捷入口）
require get_template_directory() . '/inc/theme-gutenberg-blocks.php';

// 添加导航目录
require get_template_directory() . '/inc/theme-navwalker.php';

// 对象存储配置
require get_template_directory() . '/inc/theme-dogecloud.php';

// ImageX 图片服务
require get_template_directory() . '/inc/theme-volcengine.php';

// SMTP 配置
require get_template_directory() . '/inc/theme-smtp.php';

require get_template_directory() . '/inc/theme-extends.php';

// 性能优化（资源按需加载 / 查询瘦身 / 数据库清理 / 运行指标）
require get_template_directory() . '/inc/theme-performance.php';

require get_template_directory() . '/inc/theme-comment-extends.php';

// 评论用户等级
require get_template_directory() . '/inc/theme-comment-rank.php';

// 走心评论
require get_template_directory() . '/inc/theme-comment-heart.php';

// 评论友链标识
require get_template_directory() . '/inc/theme-comment-link.php';

// 评论排行榜
require get_template_directory() . '/inc/theme-comment-topcommenters.php';

// 友链页面 + 友链申请
require get_template_directory() . '/inc/theme-friend-links.php';

// 博友动态（抓取友链 RSS）
require get_template_directory() . '/inc/theme-friend-feed.php';

// 说说（朋友圈式短动态）
require get_template_directory() . '/inc/theme-shuoshuo.php';

// 评论验证码
require get_template_directory() . '/inc/theme-comment-captcha.php';

// 评论蜜罐（反机器人）
require get_template_directory() . '/inc/theme-comment-honeypot.php';

// 评论赞踩 + 置顶 + 热门评论
require get_template_directory() . '/inc/theme-comment-reactions.php';

// 暗夜模式
require get_template_directory() . '/inc/theme-darkmode.php';

// 每日皮肤（周一 ~ 周日）
require get_template_directory() . '/inc/theme-weekday-skin.php';

require get_template_directory() . '/inc/theme-archives-stats.php';

// 时间轴（含 [timeline] 短码 + page-timeline.php 模板 body class）
require get_template_directory() . '/inc/theme-timeline.php';

// 文章热力图（[post_heatmap] 短码）
require get_template_directory() . '/inc/theme-post-heatmap.php';

// 每日心情灯（[mood_log] / [mood_log_input] 短码）
require get_template_directory() . '/inc/theme-mood-log.php';

// 「Kratos+ 特色标题」页面模板 metabox（标题 / 副标题 / 图标）
require get_template_directory() . '/inc/theme-featured-title.php';

// 阅读增强（阅读进度条 / 字数 & 预计阅读时间 / 更新提示条 / 相关文章）
require get_template_directory() . '/inc/theme-reading-enhance.php';

// 关键词自动内链（按标签/分类命中正文关键词，替换为归档链接）
require get_template_directory() . '/inc/theme-auto-link.php';

// 系列文章（连载教程串上下篇）
require get_template_directory() . '/inc/theme-series.php';

// 「岁月同一天」On This Day —— 短代码 / 小工具 / 首页 / 文章底部
require get_template_directory() . '/inc/theme-on-this-day.php';

// 特色首页（page-home-featured.php + [home_featured] 短码）
require get_template_directory() . '/inc/theme-home-featured.php';

// Now 页面 —— 我最近在做什么
require get_template_directory() . '/inc/theme-now.php';

// 年度回顾 / 博客生日长图
require get_template_directory() . '/inc/theme-yearly-review.php';

// 随机漫步 Stumble —— 随机跳到一篇被埋没的老文章
require get_template_directory() . '/inc/theme-stumble.php';

// 搜索结果页增强（search.php 的数据层与渲染函数）
require get_template_directory() . '/inc/theme-search.php';

// 评论者地域分布（[comment_geo] 短码，复用 inc/ip2region 离线库）
require get_template_directory() . '/inc/theme-comment-geo.php';

// 内链悬浮预览卡（正文站内链接 hover 预览）
require get_template_directory() . '/inc/theme-link-preview.php';

// 命令面板（⌘K / Ctrl+K 快速搜索与跳转）
require get_template_directory() . '/inc/theme-command-palette.php';

// 站点数据看板（[site_dashboard] 短码 + page-site-dashboard.php）
require get_template_directory() . '/inc/theme-site-dashboard.php';

// 自定义登录页（接管 wp-login.php）
require get_template_directory() . '/inc/theme-login.php';

