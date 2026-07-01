<?php

/**
 * 主题选项
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos+ fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2025.02.08
 */

defined('ABSPATH') || exit;

$prefix = 'kratos_options';

if (!function_exists('kratos_option')) {
    function kratos_option($name, $default = false)
    {

        $options = get_option('kratos_options');

        if (isset($options[$name])) {
            return $options[$name];
        }

        return $default;
    }
}

if (!function_exists('kratos_layout_cols')) {
    function kratos_layout_cols($single_full = false)
    {
        $main = (int) kratos_option('g_main_col', 8);
        $side = (int) kratos_option('g_sidebar_col', 4);
        $main = max(1, min(12, $main));
        $side = max(0, min(12 - $main, $side));
        return array(
            'main_full' => 'col-lg-12',
            'main' => 'col-lg-' . ($single_full ? 12 : $main),
            'sidebar' => 'col-lg-' . ($side > 0 ? $side : 4),
            'has_sidebar' => $side > 0 && !$single_full,
        );
    }
}

function getrobots()
{
    $site_url = parse_url(site_url());
    $web_url = get_bloginfo('url');
    $path = (!empty($site_url['path'])) ? $site_url['path'] : '';

    $robots = "User-agent: *\n\n";
    $robots .= "Disallow: $path/wp-admin/\n";
    $robots .= "Disallow: $path/wp-includes/\n";
    $robots .= "Disallow: $path/wp-content/plugins/\n";
    $robots .= "Disallow: $path/wp-content/themes/\n\n";
    $robots .= "Sitemap: $web_url/wp-sitemap.xml\n";

    return $robots;
}

CSF::createOptions($prefix, array(
    'menu_title' => __('主题设置', 'kratos'),
    'menu_slug' => 'kratos-options',
    'show_search' => false,
    'show_all_options' => false,
    'sticky_header' => false,
    'admin_bar_menu_icon' => 'dashicons-admin-generic',
    'framework_title' => '主题设置<small style="margin-left:10px">Kratos+ v' . THEME_VERSION . '</small>',
    'theme' => 'light',
    'footer_credit' => '感谢使用 Kratos+ 主题进行创作。本主题基于 <a target="_blank" href="https://github.com/seatonjiang/kratos">Kratos</a>（GPL-3.0）二次开发。',
));

CSF::createSection($prefix, array(
    'id' => 'global_fields',
    'title' => __('全站配置', 'kratos'),
    'icon' => 'fas fa-rocket',
));

CSF::createSection($prefix, array(
    'parent' => 'global_fields',
    'title' => __('功能配置', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 'g_adminbar',
            'type' => 'switcher',
            'title' => __('前台管理员导航', 'kratos'),
            'subtitle' => __('启用/禁用前台管理员导航', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_login',
            'type' => 'switcher',
            'title' => __('侧边栏后台入口', 'kratos'),
            'subtitle' => __('启用/禁用个人简介头像进入后台功能', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_sticky',
            'type' => 'switcher',
            'title' => __('侧边栏随动', 'kratos'),
            'subtitle' => __('启用/禁用小工具侧边栏随动功能', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_search',
            'type' => 'switcher',
            'title' => __('搜索增强', 'kratos'),
            'subtitle' => __('启用/禁用仅搜索文章标题', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_thumbnail',
            'type' => 'switcher',
            'title' => __('特色图片', 'kratos'),
            'subtitle' => __('启用/禁用文章特色图片', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_rip',
            'type' => 'switcher',
            'title' => __('哀悼功能', 'kratos'),
            'subtitle' => __('启用/禁用全站黑白功能', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_animate',
            'type' => 'switcher',
            'title' => __('CSS 动画库', 'kratos'),
            'subtitle' => __('启用/禁用 animate.css 效果', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_fontawesome',
            'type' => 'switcher',
            'title' => __('Font Awesome', 'kratos'),
            'subtitle' => __('启用/禁用 Font Awesome Free 字体', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_cdn',
            'type' => 'switcher',
            'title' => __('静态资源加速', 'kratos'),
            'subtitle' => __('启用/禁用静态资源加速（jsDelivr）', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_renameimg',
            'type' => 'switcher',
            'title' => __('自定义图片类型的文件名', 'kratos'),
            'subtitle' => __('启用/禁用 图片类型的文件名改为 MD5 值', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_removeimgsize',
            'type' => 'switcher',
            'title' => __('禁止生成缩略图', 'kratos'),
            'subtitle' => __('启用/禁用生成多种尺寸图片资源', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_gutenberg',
            'type' => 'switcher',
            'title' => __('Gutenberg 编辑器', 'kratos'),
            'subtitle' => __('启用/禁用 Gutenberg 编辑器', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_page_lightgallery',
            'type' => 'switcher',
            'title' => __('页面图片灯箱', 'kratos'),
            'subtitle' => __('启用/禁用页面图片灯箱功能', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_excerpt_length',
            'type' => 'text',
            'title' => __('文章简介缩略', 'kratos'),
            'subtitle' => __('文章简介显示的字符数量', 'kratos'),
            'default' => '260',
        ),
        array(
            'id' => 'g_replace_gravatar_url_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('Gravatar 加速服务', 'kratos'),
                ),
                array(
                    'id' => 'g_replace_gravatar_url',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('开启/关闭 Gravatar 加速服务功能', 'kratos'),
                ),
                array(
                    'id' => 'g_select_gravatar_server',
                    'type' => 'select',
                    'title' => __('Gravatar 加速服务地址', 'kratos'),
                    'subtitle' => __('请选择 Gravatar 加速服务地址', 'kratos'),
                    'options' => array(
                        'loli' => __('Loli 加速服务', 'kratos'),
                        'geekzu' => __('极客族加速服务', 'kratos'),
                        'other' => __('自定义加速服务', 'kratos'),
                    ),
                    'desc' => __('国内用户推荐「极客族加速服务」，海外用户推荐「Loli 加速服务」。', 'kratos'),
                    'dependency' => array('g_replace_gravatar_url', '==', 'true'),
                ),
                array(
                    'id' => 'g_custom_gravatar_server',
                    'type' => 'text',
                    'title' => __('自定义 Gravatar 加速服务地址', 'kratos'),
                    'subtitle' => __('请输入 Gravatar 加速服务地址', 'kratos'),
                    'desc' => __('直接输入网址即可，不需要协议头和最后的斜杠。', 'kratos'),
                    'placeholder' => 'secure.gravatar.com',
                    'dependency' => array('g_replace_gravatar_url|g_select_gravatar_server', '==|==', 'true|other'),
                ),
            ),
            'default' => array(
                'g_replace_gravatar_url' => 1,
                'g_select_gravatar_server' => 'geekzu',
            )
        ),
        array(
            'id' => 'g_renameother_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('附件重命名', 'kratos'),
                ),
                array(
                    'id' => 'g_renameother',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('开启/关闭附件重命名', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_renameother_prdfix',
                    'type' => 'text',
                    'title' => __('文件前缀', 'kratos'),
                    'subtitle' => __('前缀与文件名之间会用 - 连接', 'kratos'),
                ),
                array(
                    'id' => 'g_renameother_mime',
                    'type' => 'text',
                    'title' => __('文件类型', 'kratos'),
                    'subtitle' => __('每个类型之间用 | 隔开', 'kratos'),
                ),
            ),
            'default' => array(
                'g_renameother' => false,
                'g_renameother_prdfix' => 'kratos',
                'g_renameother_mime' => 'tar|zip|gz|gzip|rar|7z',
            ),
        ),
        array(
            'id' => 'g_wechat_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('微信二维码', 'kratos'),
                ),
                array(
                    'id' => 'g_wechat',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('开启/关闭微信二维码', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_wechat_img',
                    'type' => 'upload',
                    'title' =>  __('二维码图片', 'kratos'),
                    'library' => 'image',
                    'preview' => true,
                    'subtitle' => __('浮动显示在页面右下角', 'kratos'),
                ),
            ),
            'default' => array(
                'g_wechat' => false,
                'g_wechat_img' => get_template_directory_uri() . '/assets/img/200.png',
            ),
        ),
        array(
            'id' => 'g_font_fieldset',
            'type' => 'fieldset',
            'title' => __('自定义字体', 'kratos'),
            'subtitle' => __('启用后通过 CDN 加载自定义字体，并在全站（除代码块/图标外）强制使用', 'kratos'),
            'fields' => array(
                array(
                    'id' => 'g_font_enable',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('启用/关闭自定义字体', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_font_family',
                    'type' => 'text',
                    'title' => __('字体名称', 'kratos'),
                    'subtitle' => __('CSS font-family 值，如 HarmonyOS Sans SC', 'kratos'),
                ),
                array(
                    'id' => 'g_font_url',
                    'type' => 'text',
                    'title' => __('CDN URL', 'kratos'),
                    'subtitle' => __('字体的 CSS / @font-face 文件地址，留空则不加载远程文件（依赖系统已安装该字体）', 'kratos'),
                ),
                array(
                    'id' => 'g_font_fallback',
                    'type' => 'text',
                    'title' => __('字体兜底栈', 'kratos'),
                    'subtitle' => __('当首选字体未加载时使用的备用字体栈，如 sans-serif 或 -apple-system, BlinkMacSystemFont, sans-serif', 'kratos'),
                ),
            ),
            'default' => array(
                'g_font_enable' => false,
                'g_font_family' => '',
                'g_font_url' => '',
                'g_font_fallback' => 'sans-serif',
            ),
        ),
        array(
            'id' => 'g_main_col',
            'type' => 'slider',
            'title' => __('主体宽度', 'kratos'),
            'subtitle' => __('基于 Bootstrap 12 栅格的主体内容列宽，建议与侧边栏宽度之和等于 12', 'kratos'),
            'min' => 5,
            'max' => 11,
            'step' => 1,
            'default' => 8,
        ),
        array(
            'id' => 'g_sidebar_col',
            'type' => 'slider',
            'title' => __('侧边栏宽度', 'kratos'),
            'subtitle' => __('基于 Bootstrap 12 栅格的侧边栏列宽，建议与主体宽度之和等于 12', 'kratos'),
            'min' => 1,
            'max' => 7,
            'step' => 1,
            'default' => 4,
        ),
        array(
            'id' => 'g_container_max',
            'type' => 'number',
            'title' => __('页面主体最大宽度 (px)', 'kratos'),
            'subtitle' => __('在大屏幕下页面容器的最大宽度，最小 960，留空表示不限制（自适应屏幕宽度）', 'kratos'),
            'min' => 960,
            'default' => 1280,
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'global_fields',
    'title' => __('代码高亮', 'kratos'),
    'icon' => 'fas fa-code',
    'fields' => array(
        array(
            'id' => 'g_codehl',
            'type' => 'switcher',
            'title' => __('代码高亮', 'kratos'),
            'subtitle' => __('启用/禁用文章代码块的语法高亮显示', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_codehl_engine',
            'type' => 'select',
            'title' => __('高亮方案', 'kratos'),
            'subtitle' => __('Prism.js 与 highlight.js 为前端方案，highlight.php 为服务端渲染', 'kratos'),
            'options' => array(
                'prism' => 'Prism.js (推荐)',
                'hljs' => 'highlight.js',
                'highlight_php' => 'highlight.php (服务端)',
            ),
            'default' => 'prism',
            'dependency' => array('g_codehl', '==', 'true'),
        ),
        array(
            'id' => 'g_codehl_source',
            'type' => 'button_set',
            'title' => __('资源加载方式', 'kratos'),
            'subtitle' => __('CDN 加载速度更快；本地缓存切到本地后会一次性预下载所有 Prism 语言/主题与 hljs 主题（约 2MB），无需 .htaccess/Nginx 配置，跨服务器通用', 'kratos'),
            'options' => array(
                'cdn' => 'CDN',
                'local' => __('本地缓存', 'kratos'),
            ),
            'default' => 'cdn',
            'dependency' => array('g_codehl|g_codehl_engine', '==|any', 'true|prism,hljs'),
        ),
        array(
            'type' => 'callback',
            'title' => __('本地缓存状态', 'kratos'),
            'function' => 'kratos_codehl_render_warmup_panel',
            'dependency' => array('g_codehl|g_codehl_source', '==|==', 'true|local'),
        ),
        array(
            'id' => 'g_codehl_cdn_base',
            'type' => 'text',
            'title' => __('CDN 根路径', 'kratos'),
            'subtitle' => __('npm 风格 CDN 根 URL，留空使用默认 jsdelivr。可换成 unpkg 或国内镜像', 'kratos'),
            'default' => 'https://cdn.jsdelivr.net/npm',
            'dependency' => array('g_codehl|g_codehl_source', '==|==', 'true|cdn'),
        ),
        array(
            'id' => 'g_codehl_theme_prism',
            'type' => 'select',
            'title' => __('Prism 主题', 'kratos'),
            'subtitle' => __('Prism 官方核心主题 + prism-themes 社区扩展，共 45 款', 'kratos'),
            'options' => kratos_codehl_prism_options(),
            'default' => 'core/prism-tomorrow',
            'dependency' => array('g_codehl|g_codehl_engine', '==|==', 'true|prism'),
        ),
        array(
            'id' => 'g_codehl_theme_hljs',
            'type' => 'select',
            'title' => __('highlight 主题', 'kratos'),
            'subtitle' => __('highlight.js 官方主题（73 款），highlight.js 与 highlight.php 共享配色', 'kratos'),
            'options' => kratos_codehl_hljs_options(),
            'default' => 'github-dark',
            'dependency' => array('g_codehl|g_codehl_engine', '==|any', 'true|hljs,highlight_php'),
        ),
        array(
            'type' => 'callback',
            'title' => __('主题预览', 'kratos'),
            'function' => 'kratos_codehl_render_preview',
            'dependency' => array('g_codehl', '==', 'true'),
        ),
        array(
            'id' => 'g_codehl_linenum',
            'type' => 'switcher',
            'title' => __('显示行号', 'kratos'),
            'subtitle' => __('仅 Prism.js 与 highlight.js 方案生效', 'kratos'),
            'default' => false,
            'dependency' => array('g_codehl|g_codehl_engine', '==|any', 'true|prism,hljs'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'global_fields',
    'title' => __('暗夜模式', 'kratos'),
    'icon' => 'fas fa-moon',
    'fields' => array(
        array(
            'id' => 'g_darkmode',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('启用/关闭暗夜模式（含时间段自动切换、跟随系统、手动切换按钮）', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_darkmode_default',
            'type' => 'button_set',
            'title' => __('默认模式', 'kratos'),
            'subtitle' => __('用户首次访问且未手动切换时的默认呈现', 'kratos'),
            'options' => array(
                'light'    => __('浅色', 'kratos'),
                'dark'     => __('暗色', 'kratos'),
                'auto'     => __('跟随系统', 'kratos'),
                'schedule' => __('按时间段', 'kratos'),
            ),
            'default' => 'light',
            'dependency' => array('g_darkmode', '==', 'true'),
        ),
        array(
            'id' => 'g_darkmode_start',
            'type' => 'text',
            'title' => __('暗色开始时间', 'kratos'),
            'subtitle' => __('24 小时制 HH:MM，例如 19:00', 'kratos'),
            'default' => '19:00',
            'placeholder' => '19:00',
            'attributes' => array(
                'type' => 'time',
            ),
            'dependency' => array('g_darkmode|g_darkmode_default', '==|==', 'true|schedule'),
        ),
        array(
            'id' => 'g_darkmode_end',
            'type' => 'text',
            'title' => __('暗色结束时间', 'kratos'),
            'subtitle' => __('24 小时制 HH:MM，例如 07:00；当结束时间小于开始时间时表示跨午夜', 'kratos'),
            'default' => '07:00',
            'placeholder' => '07:00',
            'attributes' => array(
                'type' => 'time',
            ),
            'dependency' => array('g_darkmode|g_darkmode_default', '==|==', 'true|schedule'),
        ),
        array(
            'id' => 'g_darkmode_toggle',
            'type' => 'switcher',
            'title' => __('前台切换按钮', 'kratos'),
            'subtitle' => __('在页面右下角显示浮动的明/暗切换按钮，访客手动切换会覆盖默认模式（保存在浏览器本地）', 'kratos'),
            'text_on' => __('显示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => true,
            'dependency' => array('g_darkmode', '==', 'true'),
        ),
        array(
            'id' => 'g_darkmode_remember_days',
            'type' => 'number',
            'title' => __('用户偏好记住天数', 'kratos'),
            'subtitle' => __('访客手动切换的偏好保留天数，0 表示永久保留', 'kratos'),
            'min' => 0,
            'max' => 365,
            'default' => 30,
            'dependency' => array('g_darkmode|g_darkmode_toggle', '==|==', 'true|true'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'global_fields',
    'title' => __('每日皮肤', 'kratos'),
    'icon' => 'fas fa-palette',
    'fields' => array(
        array(
            'id' => 'g_weekday_skin_mode',
            'type' => 'button_set',
            'title' => __('皮肤模式', 'kratos'),
            'subtitle' => __('off：使用默认外观；auto：按访客本地时区每天自动切换；locked：全站锁定为某一套皮肤。auto 模式与暗夜模式共存，暗夜模式优先级更高。', 'kratos'),
            'options' => array(
                'off'    => __('关闭', 'kratos'),
                'auto'   => __('按星期自动切换', 'kratos'),
                'locked' => __('锁定单一皮肤', 'kratos'),
            ),
            'default' => 'off',
        ),
        array(
            'id' => 'g_weekday_skin_locked',
            'type' => 'select',
            'title' => __('锁定皮肤', 'kratos'),
            'subtitle' => __('仅在「锁定单一皮肤」模式下生效', 'kratos'),
            'options' => array(
                'mon'       => __('周一 · 清玻', 'kratos'),
                'tue'       => __('周二 · 拼贴', 'kratos'),
                'wed'       => __('周三 · 凝脂', 'kratos'),
                'thu'       => __('周四 · 素白', 'kratos'),
                'fri'       => __('周五 · 琥珀', 'kratos'),
                'sat'       => __('周六 · 海滨', 'kratos'),
                'sun'       => __('周日 · 金辉', 'kratos'),
                'parchment' => __('温色 · 羊皮', 'kratos'),
                'silk'      => __('笺页 · 黄绢', 'kratos'),
            ),
            'default' => 'mon',
            'dependency' => array('g_weekday_skin_mode', '==', 'locked'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'global_fields',
    'title' => __('颜色配置', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 'g_background',
            'type' => 'color',
            'default' => '#f5f5f5',
            'title' =>  __('全站背景颜色', 'kratos'),
            'subtitle' => __('全站页面的背景颜色', 'kratos'),
        ),
        array(
            'id' => 'g_nav',
            'type' => 'color',
            'default' => '#ffffff',
            'title' =>  __('导航栏文字颜色', 'kratos'),
            'subtitle' => __('导航栏中站点标题以及一级导航的颜色', 'kratos'),
        ),
        array(
            'id' => 'g_chrome',
            'type' => 'color',
            'default' => '#282a2c',
            'title' =>  __('Chrome 导航栏颜色', 'kratos'),
            'subtitle' => __('移动端 Chrome 浏览器导航栏颜色', 'kratos'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'global_fields',
    'title' => __('图片配置', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 'g_logo',
            'type' => 'upload',
            'title' => __('站点 Logo', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'subtitle' => __('不上传图片则显示站点标题', 'kratos'),
        ),
        array(
            'id' => 'g_icon',
            'type' => 'upload',
            'title' =>  __('Favicon 图标', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'subtitle' => __('浏览器收藏夹和地址栏中显示的图标', 'kratos'),
        ),
        array(
            'id' => 'g_404',
            'type' => 'upload',
            'title' =>  __('404 页面图片', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'default' => get_template_directory_uri() . '/assets/img/404.jpg',
            'subtitle' => __('图片显示出来是 404 的形状', 'kratos'),
        ),
        array(
            'id' => 'g_nothing',
            'type' => 'upload',
            'title' =>  __('无内容图片', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'default' => get_template_directory_uri() . '/assets/img/nothing.svg',
            'subtitle' => __('当搜索不到文章或分类没有文章时显示', 'kratos'),
        ),
        array(
            'id' => 'g_postthumbnail',
            'type' => 'upload',
            'title' =>  __('默认特色图', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'default' => get_template_directory_uri() . '/assets/img/default.jpg',
            'subtitle' => __('当文章中没有图片且没有特色图时显示', 'kratos'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'global_fields',
    'title' => __('首页轮播', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 'g_carousel',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('开启/关闭首页轮播功能', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'carousel_group',
            'type' => 'group',
            'title' => '首页轮播',
            'subtitle' => '点击添加轮播内容，最多添加 7 个轮播内容',
            'min' => 1,
            'max' => 7,
            'fields' => array(
                array(
                    'id' => 'c_id',
                    'type' => 'text',
                    'title' =>  __('唯一标识', 'kratos'),
                    'subtitle' =>  __('仅用于轮播标识，可以作为备注使用', 'kratos'),
                ),
                array(
                    'id' => 'c_img',
                    'type' => 'upload',
                    'title' => __('轮播图片', 'kratos'),
                    'subtitle' =>  __('可以直接填写图片链接，也可以上传图片', 'kratos'),
                    'library' => 'image',
                    'preview' => true,
                ),
                array(
                    'id' => 'c_url',
                    'type' => 'text',
                    'title' =>  __('网址链接', 'kratos'),
                    'subtitle' =>  __('需要填写完整的链接地址，包含协议头', 'kratos'),
                ),
                array(
                    'id' => 'c_title',
                    'type' => 'text',
                    'title' =>  __('轮播标题', 'kratos'),
                    'subtitle' =>  __('选填项目，如果不填则不显示', 'kratos'),
                ),
                array(
                    'id' => 'c_subtitle',
                    'type' => 'textarea',
                    'title' =>  __('轮播简介', 'kratos'),
                    'subtitle' =>  __('选填项目，如果不填则不显示', 'kratos'),
                ),
                array(
                    'id' => 'c_color',
                    'type' => 'color',
                    'default' => '#000',
                    'title' =>  __('文字颜色', 'kratos'),
                    'subtitle' => __('轮播标题和简介的颜色', 'kratos'),
                ),
            ),
        ),
    )
));

CSF::createSection($prefix, array(
    'parent' => 'global_fields',
    'title' => __('第三方配置', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'type' => 'notice',
            'style' => 'info',
            'content' => '提示：<strong>DogeCloud 云存储</strong> 与 <strong>火山引擎 ImageX</strong>请勿同时开启！',
        ),
        array(
            'id' => 'g_cos_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('DogeCloud 云存储', 'kratos'),
                ),
                array(
                    'type' => 'submessage',
                    'style' => 'info',
                    'content' => 'DogeCloud 云存储提供<strong> 10 GB </strong>的免费存储额度，<strong> 20 GB </strong>每月的免费 CDN 额度，<a target="_blank" href="https://console.dogecloud.com/register.html?iuid=614">立即注册</a>',
                ),
                array(
                    'id' => 'g_cos',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('开启/关闭 DogeCloud 云存储', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_cos_bucketname',
                    'type' => 'text',
                    'title' => __('空间名称', 'kratos'),
                    'subtitle' => __('空间名称可在空间基本信息中查看', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.dogecloud.com/oss/list">点击这里</a>查询空间名称', 'kratos'),
                ),
                array(
                    'id' => 'g_cos_url',
                    'type' => 'text',
                    'title' => __('加速域名', 'kratos'),
                    'subtitle' => __('域名结尾不要添加 /', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.dogecloud.com/oss/list">点击这里</a>查询加速域名', 'kratos'),
                ),
                array(
                    'id' => 'g_cos_accesskey',
                    'type' => 'text',
                    'title' => __('AccessKey', 'kratos'),
                    'subtitle' => __('出于安全考虑，建议周期性地更换密钥', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.dogecloud.com/user/keys">点击这里</a>查询 AccessKey', 'kratos'),
                ),
                array(
                    'id' => 'g_cos_secretkey',
                    'type' => 'text',
                    'attributes' => array(
                        'type' => 'password',
                    ),
                    'title' => __('SecretKey', 'kratos'),
                    'subtitle' => __('出于安全考虑，建议周期性地更换密钥', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.dogecloud.com/user/keys">点击这里</a>查询 SecretKey', 'kratos'),
                ),
            ),
            'default' => array(
                'g_cos' => false,
                'g_cos_bucketname' => '',
                'g_cos_url' => '',
                'g_cos_accesskey' => '',
                'g_cos_secretkey' => '',
            ),
        ),
        array(
            'id' => 'g_imgx_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('火山引擎 ImageX', 'kratos'),
                ),
                array(
                    'type' => 'submessage',
                    'style' => 'info',
                    'content' => '火山引擎 ImageX 提供<strong> 10 GB </strong>的免费存储额度，<strong> 10 GB </strong>每月的免费 CDN 额度，<strong> 20 TB </strong>每月的图像处理额度，<a target="_blank" href="https://www.volcengine.com/products/imagex?utm_content=ImageX&utm_medium=i4vj9y&utm_source=u7g4zk&utm_term=ImageX-kratos">立即注册</a>',
                ),
                array(
                    'id' => 'g_imgx',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('开启/关闭 火山引擎 ImageX', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_region',
                    'type' => 'select',
                    'title' => __('加速地域', 'kratos'),
                    'subtitle' => __('加速地域在创建服务的时候进行选择', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.volcengine.com/imagex/service_manage/">点击这里</a>查询加速地域', 'kratos'),
                    'options' => array(
                        'cn-north-1' => __('国内', 'kratos'),
                        'us-east-1' => __('美东', 'kratos'),
                        'ap-singapore-1' => __('新加坡', 'kratos')
                    ),
                ),
                array(
                    'id' => 'g_imgx_serviceid',
                    'type' => 'text',
                    'title' => __('服务 ID', 'kratos'),
                    'subtitle' => __('服务 ID 可在图片服务管理中查看', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.volcengine.com/imagex/service_manage/">点击这里</a>查询服务 ID', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_url',
                    'type' => 'text',
                    'title' => __('加速域名', 'kratos'),
                    'subtitle' => __('域名结尾不要添加 /', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.volcengine.com/imagex/service_manage/">点击这里</a>查询加速域名', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_tmp',
                    'type' => 'text',
                    'title' => __('处理模板', 'kratos'),
                    'subtitle' => __('处理模板可在图片处理配置中查看', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.volcengine.com/imagex/image_template/">点击这里</a>查询处理模板', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_accesskey',
                    'type' => 'text',
                    'title' => __('AccessKey', 'kratos'),
                    'subtitle' => __('出于安全考虑，建议周期性地更换密钥', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.volcengine.com/iam/keymanage/">点击这里</a>查询 AccessKey', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_secretkey',
                    'type' => 'text',
                    'attributes' => array(
                        'type' => 'password',
                    ),
                    'title' => __('SecretKey', 'kratos'),
                    'subtitle' => __('出于安全考虑，建议周期性地更换密钥', 'kratos'),
                    'desc' => __('<a target="_blank" href="https://console.volcengine.com/iam/keymanage/">点击这里</a>查询 SecretKey', 'kratos'),
                ),
            ),
            'default' => array(
                'g_imgx' => false,
                'g_imgx_region' => 'cn-north-1',
                "g_imgx_serviceid" => "",
                "g_imgx_url" => "",
                "g_imgx_tmp" => "",
                "g_imgx_accesskey" => "",
                "g_imgx_secretkey" => "",
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'title' => __('收录配置', 'kratos'),
    'icon' => 'fas fa-camera',
    'fields' => array(
        array(
            'id' => 'seo_shareimg',
            'type' => 'upload',
            'title' =>  __('分享图片', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'default' => get_template_directory_uri() . '/assets/img/default.jpg',
            'subtitle' => __('用于搜索引擎或社交工具抓取时使用', 'kratos'),
        ),
        array(
            'id' => 'seo_keywords',
            'type' => 'text',
            'title' => __('关键词', 'kratos'),
            'subtitle' =>  __('每个关键词之间需要用 , 分割', 'kratos'),
        ),
        array(
            'id' => 'seo_description',
            'type' => 'textarea',
            'title' => __('站点描述', 'kratos'),
            'subtitle' =>  __('网站首页的描述信息', 'kratos'),
        ),
        array(
            'id' => 'seo_statistical',
            'title' => __('统计代码', 'kratos'),
            'subtitle' => __('<span style="color:red">输入代码时请注意辨别代码安全性</span>', 'kratos'),
            'type' => 'code_editor',
            'settings' => array(
                'theme' => 'default',
                'mode' => 'htmlmixed',
            ),
            'sanitize' => false,
            'default' => '<script></script>',
        ),
        array(
            'id' => 'seo_robots_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('robots.txt 配置', 'kratos'),
                ),
                array(
                    'type' => 'content',
                    'content' => '<ul> <li>' . __('- 需要 ', 'kratos') . '<a href="' . admin_url('options-reading.php') . '" target="_blank">' . __('设置-阅读-对搜索引擎的可见性', 'kratos') . '</a>' . __(' 是开启的状态，以下配置才会生效', 'kratos') . '</li><li>' . __('- 如果网站根目录下已经有 robots.txt 文件，下面的配置不会生效', 'kratos') . '</li><li>' . __('- 点击 ', 'kratos') . '<a href="' . home_url() . '/robots.txt" target="_blank">robots.txt</a>' . __(' 查看配置是否生效，如果网站开启了 CDN，可能需要刷新缓存才会生效', 'kratos') . '</li></ul>',
                ),
                array(
                    'id' => 'seo_robots',
                    'type' => 'textarea',
                ),
            ),
            'default' => array(
                'seo_robots' => getrobots(),
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'title' => __('文章配置', 'kratos'),
    'icon' => 'fas fa-file-alt',
    'fields' => array(
        array(
            'id' => 'g_163mic',
            'type' => 'switcher',
            'title' => __('网易云音乐', 'kratos'),
            'subtitle' => __('启用/禁用网易云音乐自动播放功能', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_post_comments',
            'type' => 'switcher',
            'title' => __('评论数量展示', 'kratos'),
            'subtitle' => __('启用/禁用首页及文章页面展示阅读数量的功能', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_post_views',
            'type' => 'switcher',
            'title' => __('热度数量展示', 'kratos'),
            'subtitle' => __('启用/禁用首页及文章页面展示热度数量的功能', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_post_loves',
            'type' => 'switcher',
            'title' => __('点赞数量展示', 'kratos'),
            'subtitle' => __('启用/禁用首页及文章页面展示点赞数量的功能', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_post_author',
            'type' => 'switcher',
            'title' => __('作者名称展示', 'kratos'),
            'subtitle' => __('启用/禁用首页展示作者名称的功能', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_post_revision',
            'type' => 'switcher',
            'title' => __('附加功能', 'kratos'),
            'subtitle' => __('启用/禁用文章自动保存、修订版本功能', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_image_filter',
            'type' => 'switcher',
            'title' => __('按类型筛选媒体库功能', 'kratos'),
            'subtitle' => __('启用/禁用按类型筛选媒体库功能功能', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_article_lightgallery',
            'type' => 'switcher',
            'title' => __('文章图片灯箱', 'kratos'),
            'subtitle' => __('启用/禁用文章图片灯箱功能', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_article_widgets',
            'type' => 'image_select',
            'title' => __('页面布局', 'kratos'),
            'subtitle' => __('差异在于侧边栏小工具，仅在文章页面生效', 'kratos'),
            'options' => array(
                'one_side' => get_template_directory_uri() . '/assets/img/options/col-12.png',
                'two_side' => get_template_directory_uri() . '/assets/img/options/col-8.png',
            ),
            'default' => 'two_side',
        ),
        array(
            'id' => 'g_cc_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('知识共享协议', 'kratos'),
                ),
                array(
                    'id' => 'g_cc_switch',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('开启/关闭 知识共享协议', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_cc',
                    'type' => 'select',
                    'title' => __('协议名称', 'kratos'),
                    'subtitle' => __('选择文章的知识共享协议', 'kratos'),
                    'options' => array(
                        'one' => __('知识共享署名 4.0 国际许可协议', 'kratos'),
                        'two' => __('知识共享署名-非商业性使用 4.0 国际许可协议', 'kratos'),
                        'three' => __('知识共享署名-禁止演绎 4.0 国际许可协议', 'kratos'),
                        'four' => __('知识共享署名-非商业性使用-禁止演绎 4.0 国际许可协议', 'kratos'),
                        'five' => __('知识共享署名-相同方式共享 4.0 国际许可协议', 'kratos'),
                        'six' => __('知识共享署名-非商业性使用-相同方式共享 4.0 国际许可协议', 'kratos'),
                    ),
                ),
            ),
            'default' => array(
                'g_cc_switch' => false,
                'g_cc' => 'one',
            ),
        ),
        array(
            'id' => 'g_article_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('文章 HOT 标签', 'kratos'),
                ),
                array(
                    'id' => 'g_article_comment',
                    'type' => 'text',
                    'title' => __('评论数', 'kratos'),
                    'subtitle' => __('填写显示 HOT 标签需要的评论数', 'kratos'),
                ),
                array(
                    'id' => 'g_article_love',
                    'type' => 'text',
                    'title' => __('点赞数', 'kratos'),
                    'subtitle' => __('填写显示 HOT 标签需要的点赞数', 'kratos'),
                ),
            ),
            'default' => array(
                'g_article_comment' => '20',
                'g_article_love' => '200',
            ),
        ),
        array(
            'id' => 'g_donate_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('文章打赏', 'kratos'),
                ),
                array(
                    'id' => 'g_donate',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('开启/关闭 文章打赏', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_donate_wechat',
                    'type' => 'upload',
                    'title' =>  __('微信二维码', 'kratos'),
                    'library' => 'image',
                    'preview' => true,
                ),
                array(
                    'id' => 'g_donate_alipay',
                    'type' => 'upload',
                    'title' =>  __('支付宝二维码', 'kratos'),
                    'library' => 'image',
                    'preview' => true,
                ),
            ),
            'default' => array(
                'g_donate' => false,
                'g_donate_wechat' => get_template_directory_uri() . '/assets/img/200.png',
                'g_donate_alipay' => get_template_directory_uri() . '/assets/img/200.png',
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'comment_fields',
    'title' => __('评论配置', 'kratos'),
    'icon' => 'fas fa-comments',
));

CSF::createSection($prefix, array(
    'parent' => 'comment_fields',
    'title' => __('通用配置', 'kratos'),
    'icon' => 'fas fa-cog',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('评论附加信息', 'kratos'),
        ),
        array(
            'id' => 'g_comment_info_enabled',
            'type' => 'switcher',
            'title' => __('显示附加信息', 'kratos'),
            'subtitle' => __('在每条评论后追加访客的浏览器 / 系统 / 归属地等信息', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_comment_info_display',
            'type' => 'checkbox',
            'title' => __('显示项', 'kratos'),
            'subtitle' => __('选择哪些信息会展示在评论后方', 'kratos'),
            'options' => array(
                'browser'  => __('浏览器', 'kratos'),
                'os'       => __('系统', 'kratos'),
                'location' => __('归属地（需先到「IP 归属地数据库」更新数据库）', 'kratos'),
            ),
            'default' => array('browser', 'os', 'location'),
            'inline' => true,
            'dependency' => array('g_comment_info_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('用户等级', 'kratos'),
        ),
        array(
            'id' => 'g_comment_rank_enabled',
            'type' => 'switcher',
            'title' => __('显示用户等级', 'kratos'),
            'subtitle' => __('根据评论数量给评论作者展示等级头衔（前后台评论列表均生效）', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_comment_rank_levels',
            'type' => 'group',
            'title' => __('等级配置', 'kratos'),
            'subtitle' => __('按评论数从低到高添加等级，匹配规则：取门槛 ≤ 评论数的最高一级', 'kratos'),
            'button_title' => __('添加等级', 'kratos'),
            'accordion_title_prefix' => __('等级', 'kratos'),
            'accordion_title_number' => true,
            'fields' => array(
                array(
                    'id' => 'threshold',
                    'type' => 'number',
                    'title' => __('评论数门槛', 'kratos'),
                    'subtitle' => __('达到该评论数即解锁此等级', 'kratos'),
                    'min' => 0,
                    'default' => 0,
                ),
                array(
                    'id' => 'title',
                    'type' => 'text',
                    'title' => __('头衔', 'kratos'),
                    'subtitle' => __('显示在评论作者名后方的称号', 'kratos'),
                ),
                array(
                    'id' => 'color',
                    'type' => 'color',
                    'title' => __('文字颜色', 'kratos'),
                    'default' => '#ffffff',
                ),
                array(
                    'id' => 'bg_color',
                    'type' => 'color',
                    'title' => __('背景颜色', 'kratos'),
                    'default' => '#9ca3af',
                ),
            ),
            'default' => array(
                array('threshold' => 0,   'title' => '新人', 'color' => '#ffffff', 'bg_color' => '#9ca3af'),
                array('threshold' => 5,   'title' => '常客', 'color' => '#ffffff', 'bg_color' => '#3b82f6'),
                array('threshold' => 20,  'title' => '熟客', 'color' => '#ffffff', 'bg_color' => '#10b981'),
                array('threshold' => 50,  'title' => '大佬', 'color' => '#ffffff', 'bg_color' => '#f59e0b'),
                array('threshold' => 100, 'title' => '传奇', 'color' => '#ffffff', 'bg_color' => '#ef4444'),
            ),
            'dependency' => array('g_comment_rank_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('评论验证码', 'kratos'),
        ),
        array(
            'id' => 'g_comment_captcha',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('在评论表单中加入"X + Y = ?"算术验证，简单挡机器人', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_comment_captcha_max',
            'type' => 'number',
            'title' => __('数字最大值', 'kratos'),
            'subtitle' => __('运算用的随机数上限（1 到该值），默认 10', 'kratos'),
            'min' => 5,
            'max' => 99,
            'default' => 10,
            'dependency' => array('g_comment_captcha', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('友链标识', 'kratos'),
        ),
        array(
            'id' => 'g_comment_blogroll_enabled',
            'type' => 'switcher',
            'title' => __('显示友链标识', 'kratos'),
            'subtitle' => __('评论者填写的网站 URL 命中「链接（Blogroll）」列表时，在作者名后追加「友链」徽章。仅比对域名（忽略 www./协议/路径）', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_comment_blogroll_badge_text',
            'type' => 'text',
            'title' => __('徽章文字', 'kratos'),
            'subtitle' => __('展示在作者名后的徽章文字（建议 1~4 个汉字）', 'kratos'),
            'default' => __('友链', 'kratos'),
            'dependency' => array('g_comment_blogroll_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_blogroll_badge_color',
            'type' => 'color',
            'title' => __('徽章文字颜色', 'kratos'),
            'subtitle' => __('徽章文字与图标颜色', 'kratos'),
            'default' => '#ffffff',
            'dependency' => array('g_comment_blogroll_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_blogroll_badge_bg_start',
            'type' => 'color',
            'title' => __('徽章渐变起始色', 'kratos'),
            'subtitle' => __('胶囊背景渐变左侧颜色', 'kratos'),
            'default' => '#38bdf8',
            'dependency' => array('g_comment_blogroll_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_blogroll_badge_bg_end',
            'type' => 'color',
            'title' => __('徽章渐变结束色', 'kratos'),
            'subtitle' => __('胶囊背景渐变右侧颜色', 'kratos'),
            'default' => '#6366f1',
            'dependency' => array('g_comment_blogroll_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('走心评论', 'kratos'),
        ),
        array(
            'id' => 'g_comment_heart_badge_text',
            'type' => 'text',
            'title' => __('徽章文字', 'kratos'),
            'subtitle' => __('被标记的评论作者名后展示的徽章文字（建议 1~4 个汉字）', 'kratos'),
            'default' => __('走心', 'kratos'),
        ),
        array(
            'id' => 'g_comment_heart_badge_color',
            'type' => 'color',
            'title' => __('徽章文字颜色', 'kratos'),
            'subtitle' => __('徽章文字与图标颜色', 'kratos'),
            'default' => '#ffffff',
        ),
        array(
            'id' => 'g_comment_heart_badge_bg_start',
            'type' => 'color',
            'title' => __('徽章渐变起始色', 'kratos'),
            'subtitle' => __('胶囊背景渐变左侧颜色', 'kratos'),
            'default' => '#ff6b8b',
        ),
        array(
            'id' => 'g_comment_heart_badge_bg_end',
            'type' => 'color',
            'title' => __('徽章渐变结束色', 'kratos'),
            'subtitle' => __('胶囊背景渐变右侧颜色', 'kratos'),
            'default' => '#ff8e53',
        ),
        array(
            'id' => 'g_comment_heart_sc_title',
            'type' => 'text',
            'title' => __('短码默认标题', 'kratos'),
            'subtitle' => __('[heart_comments] 短码未传 title 时使用；留空则不展示标题', 'kratos'),
            'default' => __('走心评论', 'kratos'),
        ),
        array(
            'id' => 'g_comment_heart_sc_subtitle',
            'type' => 'text',
            'title' => __('短码默认副标题', 'kratos'),
            'subtitle' => __('[heart_comments] 短码未传 subtitle 时使用；留空则不展示副标题', 'kratos'),
            'default' => __('那些温暖过我的留言，每一条都值得被看见 ❤', 'kratos'),
        ),
        array(
            'id' => 'g_comment_heart_sc_per_page',
            'type' => 'number',
            'title' => __('短码每页条数', 'kratos'),
            'subtitle' => __('短码列表分页大小，短码可通过 per_page 参数覆盖；填 0 表示不分页全部展示', 'kratos'),
            'min' => 0,
            'max' => 1000,
            'default' => 100,
        ),
        array(
            'type' => 'subheading',
            'content' => __('评论排行榜', 'kratos'),
        ),
        array(
            'id' => 'g_comment_top_sc_title',
            'type' => 'text',
            'title' => __('短码默认标题', 'kratos'),
            'subtitle' => __('[top_commenters] 短码未传 title 时使用；留空则不展示标题', 'kratos'),
            'default' => __('评论排行榜', 'kratos'),
        ),
        array(
            'id' => 'g_comment_top_sc_subtitle',
            'type' => 'text',
            'title' => __('短码默认副标题', 'kratos'),
            'subtitle' => __('[top_commenters] 短码未传 subtitle 时使用；留空则不展示副标题', 'kratos'),
            'default' => __('感谢每一位活跃的朋友，你们的留言让这里更热闹 🎉', 'kratos'),
        ),
        array(
            'id' => 'g_comment_top_sc_limit',
            'type' => 'number',
            'title' => __('展示数量', 'kratos'),
            'subtitle' => __('排行榜显示的用户数量，短码可通过 limit 参数覆盖', 'kratos'),
            'min' => 1,
            'max' => 200,
            'default' => 20,
        ),
        array(
            'type' => 'content',
            'content' =>
                '<div style="padding:16px 18px;background:linear-gradient(135deg,#f4f8ff 0%,#e6efff 100%);border:1px solid #cad9f5;border-radius:12px;color:#243a5e;line-height:1.8;font-size:13px;">'
                . '<p style="margin:0 0 10px;font-size:14px;font-weight:600;color:#336699;">' . __('🏆 评论排行榜使用说明', 'kratos') . '</p>'
                . '<p style="margin:0 0 6px;"><strong>' . __('1. 数据来源：', 'kratos') . '</strong>' . __('统计每位评论者的已审核评论数（已登录用户按账号归并、游客按邮箱归并；无邮箱的匿名评论不参与排行）。', 'kratos') . '</p>'
                . '<p style="margin:0 0 6px;"><strong>' . __('2. 展示字段：', 'kratos') . '</strong>' . __('头像 / 用户名 / 评论数 / 最后一次评论时间；若网站命中「链接（Blogroll）」列表则追加友链徽章（需在上方"友链标识"开启），用户名可点击跳转到对方站点。', 'kratos') . '</p>'
                . '<p style="margin:0 0 4px;"><strong>' . __('3. 短码使用：', 'kratos') . '</strong>' . __('在任意页面 / 文章插入下方短码即可展示；也可使用「评论排行榜」页面模板直接创建一个专属页面。', 'kratos') . '</p>'
                . '<ul style="margin:6px 0 8px 22px;padding:0;list-style:disc;">'
                . '<li><code style="background:#fff;padding:2px 8px;border-radius:4px;color:#336699;">[top_commenters]</code>　' . __('使用上方后台默认值', 'kratos') . '</li>'
                . '<li><code style="background:#fff;padding:2px 8px;border-radius:4px;color:#336699;">[top_commenters title="活跃榜" subtitle="话痨挑战" limit="10"]</code></li>'
                . '</ul>'
                . '<p style="margin:0;color:#5b6d8a;">' . __('💡 结果缓存 30 分钟，评论审核 / 删除时会自动清除。', 'kratos') . '</p>'
                . '</div>',
        ),
        array(
            'type' => 'content',
            'content' =>
                '<div style="padding:16px 18px;background:linear-gradient(135deg,#fff7f3 0%,#ffeae0 100%);border:1px solid #ffd9c8;border-radius:12px;color:#5c3b30;line-height:1.8;font-size:13px;">'
                . '<p style="margin:0 0 10px;font-size:14px;font-weight:600;color:#ff6b8b;">' . __('💖 走心评论使用说明', 'kratos') . '</p>'
                . '<p style="margin:0 0 6px;"><strong>' . __('1. 标记走心：', 'kratos') . '</strong>'
                . sprintf(
                    /* translators: %s 为后台评论列表链接 */
                    __('进入 %s ，鼠标悬停在某条评论上，点击行操作中的「<span style="color:#ff6b8b;">标记走心</span>」即可；也可以勾选多条评论后，使用顶部「批量操作 → 标记为走心评论」一次处理多条。', 'kratos'),
                    '<a href="' . esc_url(admin_url('edit-comments.php')) . '" target="_blank">' . esc_html__('评论菜单', 'kratos') . '</a>'
                )
                . '</p>'
                . '<p style="margin:0 0 6px;"><strong>' . __('2. 取消走心：', 'kratos') . '</strong>' . __('在评论列表的「走心」筛选下拉中切换到「仅走心评论」，再点击行操作中的「取消走心」即可。', 'kratos') . '</p>'
                . '<p style="margin:0 0 6px;"><strong>' . __('3. 前台展示：', 'kratos') . '</strong>' . __('被标记的评论，作者名后会自动追加一个粉橙色的「💗 走心」徽章（评论列表 / 文章详情 / 后台评论列表均生效）。', 'kratos') . '</p>'
                . '<p style="margin:0 0 4px;"><strong>' . __('4. 短码使用：', 'kratos') . '</strong>' . __('在任意页面 / 文章中插入下方短码，即可展示走心评论统计与列表（评论数 / 来自文章数 / 参与用户数 + 走心评论卡片）。', 'kratos') . '</p>'
                . '<ul style="margin:6px 0 8px 22px;padding:0;list-style:disc;">'
                . '<li><code style="background:#fff;padding:2px 8px;border-radius:4px;color:#ff6b8b;">[heart_comments]</code>　' . __('使用上方后台默认值（标题 / 副标题 / 每页条数）', 'kratos') . '</li>'
                . '<li><code style="background:#fff;padding:2px 8px;border-radius:4px;color:#ff6b8b;">[heart_comments title="走心留言" subtitle="温暖过我的瞬间" per_page="50"]</code></li>'
                . '</ul>'
                . '<p style="margin:0 0 4px;color:#8a6a5d;">' . __('参数说明：', 'kratos') . '<code>title</code> ' . __('标题（留空则隐藏）', 'kratos') . '；<code>subtitle</code> ' . __('副标题（留空则隐藏）', 'kratos') . '；<code>per_page</code> ' . __('每页条数，0 表示不分页全部展示。', 'kratos') . '</p>'
                . '<p style="margin:0;color:#8a6a5d;">' . __('💡 分页通过 URL 参数 ?khc_page=2 控制，短码已自动渲染上一页 / 下一页与页码按钮，默认每页 100 条。', 'kratos') . '</p>'
                . '</div>',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'comment_fields',
    'title' => __('IP 归属地数据库', 'kratos'),
    'icon' => 'fas fa-map-marker-alt',
    'fields' => array(
        array(
            'type' => 'content',
            'content' => function_exists('kratos_ip2region_render_status') ? kratos_ip2region_render_status() : '',
        ),
        array(
            'id' => 'comment_ip2region_frequency',
            'type' => 'select',
            'title' => __('自动更新频率', 'kratos'),
            'subtitle' => __('ip2region 数据更新较慢，通常「每月」就足够', 'kratos'),
            'options' => array(
                'hourly'   => __('每小时', 'kratos'),
                'daily'    => __('每天', 'kratos'),
                'weekly'   => __('每周', 'kratos'),
                'monthly'  => __('每月（推荐）', 'kratos'),
                'disabled' => __('禁用自动更新', 'kratos'),
            ),
            'default' => 'monthly',
        ),
        array(
            'id' => 'comment_ip2region_ipv6',
            'type' => 'switcher',
            'title' => __('启用 IPv6 数据库', 'kratos'),
            'subtitle' => __('额外下载 IPv6 数据库（约 36MB），适用于大量移动网络访问者', 'kratos'),
            'default' => false,
        ),
    ),
));

CSF::createSection($prefix, array(
    'title' =>  __('邮件配置', 'kratos'),
    'icon' => 'fas fa-envelope',
    'fields' => array(
        array(
            'id' => 'm_smtp',
            'type' => 'switcher',
            'title' => __('SMTP 服务', 'kratos'),
            'subtitle' => __('启用/禁用 SMTP 服务', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'm_host',
            'type' => 'text',
            'title' => __('邮件服务器', 'kratos'),
            'subtitle' => __('填写发件服务器地址', 'kratos'),
            'placeholder' => __('smtp.example.com', 'kratos'),
        ),
        array(
            'id' => 'm_port',
            'type' => 'text',
            'title' => __('服务器端口', 'kratos'),
            'subtitle' => __('填写发件服务器端口', 'kratos'),
            'placeholder' => __('465', 'kratos'),
        ),
        array(
            'id' => 'm_sec',
            'type' => 'text',
            'title' => __('授权方式', 'kratos'),
            'subtitle' => __('填写登录鉴权的方式', 'kratos'),
            'placeholder' => __('ssl', 'kratos'),
        ),
        array(
            'id' => 'm_username',
            'type' => 'text',
            'title' => __('邮箱帐号', 'kratos'),
            'subtitle' => __('填写邮箱账号', 'kratos'),
            'placeholder' => __('user@example.com', 'kratos'),
        ),
        array(
            'id' => 'm_passwd',
            'type' => 'text',
            'title' => __('邮箱密码', 'kratos'),
            'subtitle' => __('填写邮箱密码', 'kratos'),
            'attributes' => array(
                'type' => 'password',
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'top_fields',
    'title' => __('顶部配置', 'kratos'),
    'icon' => 'fas fa-window-maximize',
));

CSF::createSection($prefix, array(
    'parent' => 'top_fields',
    'title' => __('图片导航', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 'top_img_switch',
            'type' => 'switcher',
            'title' => __('图片导航', 'kratos'),
            'subtitle' => __('启用/禁用 图片导航', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'top_img',
            'type' => 'upload',
            'title' =>  __('顶部图片', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'default' => get_template_directory_uri() . '/assets/img/background.jpg',
        ),
        array(
            'id' => 'top_title',
            'type' => 'text',
            'title' => __('图片标题', 'kratos'),
            'default' => __('Kratos+', 'kratos'),
        ),
        array(
            'id' => 'top_describe',
            'type' => 'text',
            'title' => __('标题描述', 'kratos'),
            'default' => __('专注于用户阅读体验的响应式博客主题', 'kratos'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'top_fields',
    'title' => __('颜色导航', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 'top_color',
            'type' => 'color',
            'default' => '#24292e',
            'title' =>  __('颜色导航', 'kratos'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'shuoshuo_fields',
    'title' => __('说说配置', 'kratos'),
    'icon' => 'far fa-comment-dots',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('页面顶部展示在「说说」模板（page-shuoshuo.php）页面顶部，可留空隐藏。', 'kratos'),
        ),
        array(
            'id' => 'shuoshuo_title',
            'type' => 'text',
            'title' => __('页面标题', 'kratos'),
            'default' => __('我的说说', 'kratos'),
        ),
        array(
            'id' => 'shuoshuo_subtitle',
            'type' => 'text',
            'title' => __('页面副标题', 'kratos'),
            'default' => __('记录碎碎念，分享小确幸', 'kratos'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('列表展示', 'kratos'),
        ),
        array(
            'id' => 'shuoshuo_per_page',
            'type' => 'number',
            'title' => __('每页条数', 'kratos'),
            'subtitle' => __('「说说」模板（page-shuoshuo.php）每页显示多少条说说。', 'kratos'),
            'default' => 10,
            'attributes' => array(
                'min' => 1,
                'step' => 1,
            ),
        ),
        array(
            'id' => 'shuoshuo_collapse_limit',
            'type' => 'number',
            'title' => __('折叠字数阈值', 'kratos'),
            'subtitle' => __('列表中说说正文超过该字数时折叠，并显示「展开」。设为 0 关闭折叠功能。', 'kratos'),
            'default' => 300,
            'attributes' => array(
                'min' => 0,
                'step' => 10,
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'footer_fields',
    'title' => __('页脚配置', 'kratos'),
    'icon' => 'far fa-window-maximize',
));

CSF::createSection($prefix, array(
    'parent' => 'footer_fields',
    'title' => __('社交图标', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 's_social_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('国内平台', 'kratos'),
                ),
                array(
                    'id' => 's_sina_url',
                    'type' => 'text',
                    'title' => __('新浪微博', 'kratos'),
                    'placeholder' => __('https://weibo.com/xxxxx', 'kratos'),
                ),
                array(
                    'id' => 's_bilibili_url',
                    'type' => 'text',
                    'title' => __('哔哩哔哩', 'kratos'),
                    'placeholder' => __('https://space.bilibili.com/xxxxx', 'kratos'),
                ),
                array(
                    'id' => 's_coding_url',
                    'type' => 'text',
                    'title' => __('CODING', 'kratos'),
                    'placeholder' => __('https://xxxxx.coding.net/u/xxxxx', 'kratos'),
                ),
                array(
                    'id' => 's_gitee_url',
                    'type' => 'text',
                    'title' => __('码云', 'kratos'),
                    'placeholder' => __('https://gitee.com/xxxxx', 'kratos'),
                ),
                array(
                    'id' => 's_douban_url',
                    'type' => 'text',
                    'title' => __('豆瓣', 'kratos'),
                    'placeholder' => __('https://www.douban.com/people/xxxxx', 'kratos'),
                ),
            ),
        ),
        array(
            'id' => 's_social_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('海外平台', 'kratos'),
                ),
                array(
                    'id' => 's_twitter_url',
                    'type' => 'text',
                    'title' => __('Twitter', 'kratos'),
                    'placeholder' => __('https://twitter.com/xxxxx', 'kratos'),
                ),
                array(
                    'id' => 's_telegram_url',
                    'type' => 'text',
                    'title' => __('Telegram', 'kratos'),
                    'placeholder' => __('https://t.me/xxxxx', 'kratos'),
                ),
                array(
                    'id' => 's_linkedin_url',
                    'type' => 'text',
                    'title' => __('LinkedIn', 'kratos'),
                    'placeholder' => __('https://www.linkedin.com/in/xxxxx', 'kratos'),
                ),
                array(
                    'id' => 's_youtube_url',
                    'type' => 'text',
                    'title' => __('YouTube', 'kratos'),
                    'placeholder' => __('https://www.youtube.com/channel/xxxxx', 'kratos'),
                ),
                array(
                    'id' => 's_github_url',
                    'type' => 'text',
                    'title' => __('Github', 'kratos'),
                    'placeholder' => __('https://github.com/xxxxx', 'kratos'),
                ),
                array(
                    'id' => 's_stackflow_url',
                    'type' => 'text',
                    'title' => __('Stack Overflow', 'kratos'),
                    'placeholder' => __('https://stackoverflow.com/users/xxxxx', 'kratos'),
                ),
            ),
        ),
        array(
            'id' => 's_social_fieldset',
            'type' => 'fieldset',
            'fields' => array(
                array(
                    'type' => 'subheading',
                    'content' => __('其他', 'kratos'),
                ),
                array(
                    'id' => 's_email_url',
                    'type' => 'text',
                    'title' => __('电子邮箱', 'kratos'),
                    'placeholder' => __('mailto:xxxxx@example.com', 'kratos'),
                ),
            ),
            'default' => array(
                "s_sina_url" => "",
                "s_bilibili_url" => "",
                "s_coding_url" => "",
                "s_gitee_url" => "",
                "s_douban_url" => "",
                "s_twitter_url" => "",
                "s_telegram_url" => "",
                "s_linkedin_url" => "",
                "s_youtube_url" => "",
                "s_github_url" => "",
                "s_stackflow_url" => "",
                "s_email_url" => ""
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'footer_fields',
    'title' => __('备案信息', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 's_icp',
            'type' => 'text',
            'title' => __('工信部备案信息', 'kratos'),
            'subtitle' => __('由<a target="_blank" href="https://beian.miit.gov.cn/">工业和信息化部政务服务平台</a>提供', 'kratos'),
            'placeholder' => __('冀ICP证XXXXXX号', 'kratos'),
        ),
        array(
            'id' => 's_gov',
            'type' => 'text',
            'title' => __('公安备案信息', 'kratos'),
            'subtitle' => __('由<a target="_blank" href="http://www.beian.gov.cn/">全国互联网安全管理服务平台</a>提供', 'kratos'),
            'placeholder' => __('冀公网安备 XXXXXXXXXXXXX 号', 'kratos'),
        ),
        array(
            'id' => 's_gov_link',
            'type' => 'text',
            'title' => __('公安备案链接', 'kratos'),
            'subtitle' => __('由<a target="_blank" href="http://www.beian.gov.cn/">全国互联网安全管理服务平台</a>提供', 'kratos'),
            'placeholder' => __('http://www.beian.gov.cn/portal/registerSystemInfo?recordcode=xxxxx', 'kratos'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'footer_fields',
    'title' => __('版权信息', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 's_copyright',
            'type' => 'textarea',
            'title' => __('版权信息', 'kratos'),
            'default' => 'COPYRIGHT © ' . wp_date('Y') . ' ' . get_bloginfo('name') . '. ALL RIGHTS RESERVED.',
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'ad_fields',
    'title' => __('广告配置', 'kratos'),
    'icon' => 'fas fa-ad',
));

CSF::createSection($prefix, array(
    'parent' => 'ad_fields',
    'title' => __('文章广告', 'kratos'),
    'icon' => 'fas fa-arrow-right',
    'fields' => array(
        array(
            'id' => 'single_ad_top_group',
            'type' => 'group',
            'title' => '文章顶部广告',
            'subtitle' => '点击添加广告，最多添加 3 个顶部广告',
            'min' => 1,
            'max' => 3,
            'fields' => array(
                array(
                    'id' => 'ad_id',
                    'type' => 'text',
                    'title' =>  __('唯一标识', 'kratos'),
                    'subtitle' =>  __('仅用于识别广告内容，可以作为备注使用', 'kratos'),
                ),
                array(
                    'id' => 'ad_img',
                    'type' => 'upload',
                    'title' => __('轮播图片', 'kratos'),
                    'subtitle' =>  __('可以直接填写图片链接，也可以上传图片', 'kratos'),
                    'library' => 'image',
                    'preview' => true,
                ),
                array(
                    'id' => 'ad_url',
                    'type' => 'text',
                    'title' =>  __('网址链接', 'kratos'),
                    'subtitle' =>  __('需要填写完整的链接地址，包含协议头', 'kratos'),
                ),
                array(
                    'id' => 'ad_switcher',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('开启/关闭此条广告', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                    'default' => true
                ),
            ),
        ),
        array(
            'id' => 'single_ad_bottom_group',
            'type' => 'group',
            'title' => '文章底部广告',
            'subtitle' => '点击添加广告，最多添加 3 个底部广告',
            'min' => 1,
            'max' => 3,
            'fields' => array(
                array(
                    'id' => 'ad_id',
                    'type' => 'text',
                    'title' =>  __('唯一标识', 'kratos'),
                    'subtitle' =>  __('仅用于识别广告内容，可以作为备注使用', 'kratos'),
                ),
                array(
                    'id' => 'ad_img',
                    'type' => 'upload',
                    'title' => __('轮播图片', 'kratos'),
                    'subtitle' =>  __('可以直接填写图片链接，也可以上传图片', 'kratos'),
                    'library' => 'image',
                    'preview' => true,
                ),
                array(
                    'id' => 'ad_url',
                    'type' => 'text',
                    'title' =>  __('网址链接', 'kratos'),
                    'subtitle' =>  __('需要填写完整的链接地址，包含协议头', 'kratos'),
                ),
                array(
                    'id' => 'ad_switcher',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('开启/关闭此条广告', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                    'default' => true
                ),
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'title' => __('备份恢复', 'kratos'),
    'icon' => 'fas fa-undo',
    'fields' => array(
        array(
            'type' => 'backup',
        ),
    ),
));

CSF::createSection($prefix, array(
    'title' => __('关于主题', 'kratos'),
    'icon' => 'fas fa-question-circle',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('基础信息', 'kratos'),
        ),
        array(
            'type' => 'content',
            'content' => '<ul style="margin: 0 auto;">'
                . '<li>' . __('主题名称：', 'kratos') . 'Kratos+</li>'
                . '<li>' . __('主题版本：', 'kratos') . THEME_VERSION . '</li>'
                . '<li>' . __('PHP 版本：', 'kratos') . PHP_VERSION . '</li>'
                . '<li>' . __('WordPress 版本：', 'kratos') . $wp_version . '</li>'
                . '<li>' . __('User Agent 信息：', 'kratos') . '<span id="user-agent"></span></li>'
                . '</ul><script>document.getElementById("user-agent").textContent = navigator.userAgent;</script>',
        ),
        array(
            'type' => 'subheading',
            'content' => __('微信赞赏', 'kratos'),
        ),
        array(
            'type' => 'content',
            'content' => '<div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;padding:20px 24px;background:linear-gradient(135deg,#fff7f3 0%,#ffeae0 100%);border-radius:14px;border:1px solid #ffd9c8;">'
                . '<div style="flex:0 0 auto;">'
                . '<img src="' . esc_url(get_template_directory_uri() . '/assets/img/reward.png') . '" alt="' . esc_attr__('微信赞赏', 'kratos') . '" style="width:160px;height:160px;display:block;border-radius:10px;background:#fff;padding:8px;box-shadow:0 4px 14px rgba(255,107,71,0.18);" />'
                . '</div>'
                . '<div style="flex:1 1 240px;min-width:240px;color:#5c3b30;line-height:1.7;">'
                . '<p style="margin:0 0 6px;font-size:16px;font-weight:600;color:#ff6b47;">' . __('如果这个主题让你的小站更温暖，欢迎请作者喝杯热咖啡 ☕', 'kratos') . '</p>'
                . '<p style="margin:0 0 4px;font-size:13px;">' . __('开源不易，每一份赞赏都是继续打磨主题的动力。', 'kratos') . '</p>'
                . '<p style="margin:0;font-size:13px;color:#8a6a5d;">' . __('打开微信扫一扫左侧二维码，即可向作者表达你的支持。感谢有你 ❤', 'kratos') . '</p>'
                . '</div>'
                . '</div>',
        ),
        array(
            'type' => 'subheading',
            'content' => __('版权声明', 'kratos'),
        ),
        array(
            'type' => 'content',
            'content' => __(
                '<p>本主题 <strong>Kratos+</strong> 由 <a href="https://www.lifengdi.com" target="_blank">Dylan Li</a> 在 <a href="https://github.com/seatonjiang/kratos" target="_blank">Kratos</a> 主题（原作者 Seaton Jiang）的基础上二次开发，新增可视化代码高亮、布局自定义、评论数学验证码等功能。</p>'
                . '<p>本主题继承原主题 <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank">GNU GPL-3.0</a> 协议许可，原作者及所有引用第三方组件的版权署名均予以保留。再次分发须遵守 GPL-3.0 协议要求，包括开源、保留版权声明和许可信息。</p>',
                'kratos'
            ),
        ),
    ),
));
