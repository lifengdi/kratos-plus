<?php

/**
 * 模板函数
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos+ fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2025.02.08
 */

define('THEME_VERSION', '1.0.0');

if (defined('WP_USE_THEMES') && WP_USE_THEMES === false) {
    return;
}

// 代码高亮（必须在 CSF 之前 require —— CSF autoload 会立即加载 theme-options.php，
// 而 theme-options.php 在字段定义里调用了本文件里的 kratos_codehl_*_options() 等函数）
require get_template_directory() . '/inc/theme-codehighlight.php';

// IP 归属地数据库（同样必须在 CSF 之前 require —— theme-options.php 的「评论配置」
// content 字段会立即调用 kratos_ip2region_render_status()）
require get_template_directory() . '/inc/ip2region/ip2region-updater.php';

// 主题配置
require get_template_directory() . '/inc/codestar-framework/autoload.php';

// 更新配置
require get_template_directory() . '/inc/update-checker/autoload.php';

// 核心配置
require get_template_directory() . '/inc/theme-core.php';

// 站点配置
require get_template_directory() . '/inc/theme-setting.php';

// 文章配置
require get_template_directory() . '/inc/theme-article.php';

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

require get_template_directory() . '/inc/theme-comment-extends.php';

// 评论用户等级
require get_template_directory() . '/inc/theme-comment-rank.php';

// 走心评论
require get_template_directory() . '/inc/theme-comment-heart.php';

// 说说（朋友圈式短动态）
require get_template_directory() . '/inc/theme-shuoshuo.php';

// 评论验证码
require get_template_directory() . '/inc/theme-comment-captcha.php';

// 暗夜模式
require get_template_directory() . '/inc/theme-darkmode.php';

// 每日皮肤（周一 ~ 周日）
require get_template_directory() . '/inc/theme-weekday-skin.php';
