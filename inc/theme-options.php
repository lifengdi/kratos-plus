<?php

/**
 * 主题选项
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
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

if (!function_exists('kratos_single_ad_fields')) {
    function kratos_single_ad_fields()
    {
        return array(
            array(
                'id' => 'ad_id',
                'type' => 'text',
                'title' => __('唯一标识', 'kratos'),
                'subtitle' => __('仅用于识别，可作备注', 'kratos'),
            ),
            array(
                'id' => 'ad_type',
                'type' => 'button_set',
                'title' => __('广告类型', 'kratos'),
                'subtitle' => __('图片广告用自定义图片 + 跳转链接，谷歌广告用 AdSense 代码', 'kratos'),
                'options' => array(
                    'image'   => __('图片广告', 'kratos'),
                    'adsense' => __('谷歌广告', 'kratos'),
                ),
                'default' => 'image',
            ),
            array(
                'id' => 'ad_img',
                'type' => 'upload',
                'title' => __('轮播图片', 'kratos'),
                'subtitle' => __('可填图片链接，也可上传', 'kratos'),
                'library' => 'image',
                'preview' => true,
                'dependency' => array('ad_type', '==', 'image'),
            ),
            array(
                'id' => 'ad_url',
                'type' => 'text',
                'title' => __('网址链接', 'kratos'),
                'subtitle' => __('需含协议头的完整地址', 'kratos'),
                'dependency' => array('ad_type', '==', 'image'),
            ),
            array(
                'id' => 'ad_adsense_client',
                'type' => 'text',
                'title' => __('AdSense 客户端 ID', 'kratos'),
                'subtitle' => __('AdSense 代码里的 data-ad-client，形如 ca-pub-1234567890123456', 'kratos'),
                'placeholder' => 'ca-pub-XXXXXXXXXXXXXXXX',
                'dependency' => array('ad_type', '==', 'adsense'),
            ),
            array(
                'id' => 'ad_adsense_slot',
                'type' => 'text',
                'title' => __('AdSense 广告位 ID', 'kratos'),
                'subtitle' => __('AdSense 代码里的 data-ad-slot，形如 1234567890', 'kratos'),
                'placeholder' => 'XXXXXXXXXX',
                'dependency' => array('ad_type', '==', 'adsense'),
            ),
            array(
                'id' => 'ad_adsense_format',
                'type' => 'text',
                'title' => __('广告格式 (data-ad-format)', 'kratos'),
                'subtitle' => __('auto / fluid / rectangle / horizontal / vertical，留空为 auto', 'kratos'),
                'default' => 'auto',
                'dependency' => array('ad_type', '==', 'adsense'),
            ),
            array(
                'id' => 'ad_adsense_responsive',
                'type' => 'switcher',
                'title' => __('自适应宽度', 'kratos'),
                'subtitle' => __('对应 data-full-width-responsive，建议保持开启', 'kratos'),
                'text_on' => __('开启', 'kratos'),
                'text_off' => __('关闭', 'kratos'),
                'default' => true,
                'dependency' => array('ad_type', '==', 'adsense'),
            ),
            array(
                'id' => 'ad_switcher',
                'type' => 'switcher',
                'title' => __('功能开关', 'kratos'),
                'subtitle' => __('是否投放此条广告', 'kratos'),
                'text_on' => __('开启', 'kratos'),
                'text_off' => __('关闭', 'kratos'),
                'default' => true,
            ),
        );
    }
}

/**
 * 友链"要求内容"富文本编辑器渲染回调。
 *
 * CSF 自带的 wp_editor 字段在当前 WP 版本上不会输出 tinyMCEPreInit.mceInit.csf_wp_editor，
 * 导致前端 JS 静默 return，只剩裸 textarea。改用 callback 字段直接调 wp_editor()，
 * 走 WP 标准路径，稳定可用。表单字段名保持 kratos_options[g_friend_requirements_content]，
 * 兼容旧存储。
 */
/**
 * 在主题设置页临时禁用 githuber-md 的编辑器接管。
 *
 * githuber-md 通过 the_editor / wp_editor_settings 等 filter 把原生 TinyMCE
 * 换成自己的 Markdown 编辑器，主题设置里我们只想要原生富文本，所以在渲染
 * 我们这个字段时把它的所有 hook 摘掉；渲染完再让它自然恢复（进程结束）。
 */
function kratos_is_theme_options_page()
{
    if (!is_admin()) return false;
    if (isset($_GET['page']) && $_GET['page'] === 'kratos-options') return true;
    if (function_exists('get_current_screen')) {
        $s = get_current_screen();
        if ($s && strpos($s->id, 'kratos-options') !== false) return true;
    }
    return false;
}

/**
 * 在主题设置页遍历所有已注册 hook，把回调所属类/文件名含 "githuber" 的 callback 摘掉。
 * 必须尽早执行 —— githuber-md 会在 admin_init/admin_enqueue_scripts 时期就把编辑器
 * 相关 filter 挂上、把它自己的 assets 入队。在 CSF 渲染回调里再摘就晚了。
 */
function kratos_friend_requirements_disable_githuber_md()
{
    if (!kratos_is_theme_options_page()) {
        return;
    }
    global $wp_filter;
    if (empty($wp_filter) || !is_array($wp_filter)) {
        return;
    }
    foreach ($wp_filter as $tag => $hook) {
        if (!is_object($hook) || empty($hook->callbacks)) continue;
        foreach ($hook->callbacks as $priority => $cbs) {
            foreach ($cbs as $id => $cb) {
                $fn = $cb['function'];
                $owner = '';
                if (is_array($fn) && isset($fn[0])) {
                    $owner = is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0];
                } elseif (is_string($fn)) {
                    $owner = $fn;
                } elseif ($fn instanceof Closure) {
                    try {
                        $r = new ReflectionFunction($fn);
                        $owner = (string) $r->getFileName() . '|' . (string) $r->getName();
                        if ($scope = $r->getClosureScopeClass()) {
                            $owner .= '|' . $scope->getName();
                        }
                    } catch (Exception $e) { $owner = ''; }
                }
                if ($owner !== '' && stripos($owner, 'githuber') !== false) {
                    unset($wp_filter[$tag]->callbacks[$priority][$id]);
                }
            }
        }
    }
}
// 尽早跑：plugins_loaded 之后、admin_init/admin_enqueue_scripts 之前
add_action('admin_init', 'kratos_friend_requirements_disable_githuber_md', 1);
add_action('admin_enqueue_scripts', 'kratos_friend_requirements_disable_githuber_md', 1);
// 兜底：githuber 有些 hook 是 muplugins_loaded / init 期注册的，多打几次也无害
add_action('init', 'kratos_friend_requirements_disable_githuber_md', 1);

/**
 * CSF 保存时只会遍历它自己声明的字段，callback 字段没有 id，
 * 所以 wp_editor 提交的 kratos_options[g_friend_requirements_content] 会被丢弃。
 * 这里在写库前把 $_POST 里的值补回去。
 */
add_filter('csf_kratos_options_save', function ($data) {
    // 兼容 AJAX 保存（CSF 会把 data 作为 JSON 字符串放到 $_POST['data']）与常规表单提交。
    $raw = null;
    if (isset($_POST['data']) && is_string($_POST['data'])) {
        $decoded = json_decode(wp_unslash($_POST['data']), true);
        if (is_array($decoded) && isset($decoded['kratos_options']['g_friend_requirements_content'])) {
            $raw = $decoded['kratos_options']['g_friend_requirements_content'];
        }
    }
    if ($raw === null && isset($_POST['kratos_options']['g_friend_requirements_content'])) {
        $raw = wp_unslash($_POST['kratos_options']['g_friend_requirements_content']);
    }
    if ($raw !== null) {
        if (!is_array($data)) $data = array();
        $data['g_friend_requirements_content'] = wp_kses_post((string) $raw);
    }
    return $data;
}, 10, 1);

function kratos_friend_requirements_editor_render()
{
    // 兜底再摘一次（万一 admin_init 之后还有插件在渲染前挂了回来）
    kratos_friend_requirements_disable_githuber_md();

    $opts  = get_option('kratos_options', array());
    $value = isset($opts['g_friend_requirements_content']) ? (string) $opts['g_friend_requirements_content'] : '';
    /*
     * CSF 已经在外层包了一层 <div class="csf-fieldset">（title 20% + fieldset 80% 布局），
     * 这里不要再嵌套。用 kratos-wpeditor-wrap 兜住宽度，避免 wp_editor 内部固定宽度撑出 fieldset。
     */
    echo '<div class="kratos-wpeditor-wrap" style="max-width:100%;">';
    wp_editor($value, 'g_friend_requirements_content_editor', array(
        'textarea_name' => 'kratos_options[g_friend_requirements_content]',
        'textarea_rows' => 10,
        'media_buttons' => true,
        'tinymce'       => true,
        'quicktags'     => true,
        'wpautop'       => false,
        'editor_class'  => 'kratos-wpeditor',
    ));
    echo '</div>';
    ?>
    <script>
    /*
     * CSF 通过 AJAX 保存表单（serializeArray），并不会触发原生 form.submit 事件，
     * 而 TinyMCE 只在 submit 时才把 iframe 里的内容同步回底层 <textarea>。
     * 这里在"保存/发布"按钮被点时，先调 tinymce.triggerSave() 把内容写回 textarea，
     * 再让 CSF 继续处理。
     */
    (function () {
        function sync() {
            if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
                try { window.tinymce.triggerSave(); } catch (e) {}
            }
        }
        document.addEventListener('mousedown', function (e) {
            var t = e.target;
            if (!t) return;
            if (t.matches && (t.matches('.csf-save, .csf-save *'))) sync();
        }, true);
        document.addEventListener('submit', function () { sync(); }, true);
    })();
    </script>
    <style>
        .csf-field-callback .kratos-wpeditor-wrap { display:block; width:100%; }
        .csf-field-callback .kratos-wpeditor-wrap .wp-editor-wrap,
        .csf-field-callback .kratos-wpeditor-wrap .wp-editor-container,
        .csf-field-callback .kratos-wpeditor-wrap .wp-editor-tools { width:100% !important; box-sizing:border-box; }
        .csf-field-callback .kratos-wpeditor-wrap textarea.wp-editor-area { width:100% !important; }
        .csf-field-callback .kratos-wpeditor-wrap .mce-tinymce,
        .csf-field-callback .kratos-wpeditor-wrap .mce-container,
        .csf-field-callback .kratos-wpeditor-wrap .mce-container-body { max-width:100% !important; }
    </style>
    <?php
}

/**
 * 在「恢复全部」按钮后面插入「站长交流」入口（新窗口打开论坛，带上本站域名）。
 *
 * CSF 的 .csf-buttons 里没有任何 do_action 钩子，为了不改动 vendor 的
 * codestar-framework，这里在 admin_footer 用 JS 把按钮插到 .csf-reset-all 之后
 * （顶部工具条与页脚各一组，两处都插；没有 reset-all 时退化为追加到组末尾）。
 */
function kratos_options_bbs_button()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'kratos-options') {
        return;
    }

    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $url  = add_query_arg('from', $host, 'https://bbs.lifengdi.com');
    ?>
    <script>
    (function () {
        var url = <?php echo wp_json_encode($url); ?>;
        var label = <?php echo wp_json_encode(__('站长交流', 'kratos')); ?>;
        var groups = document.querySelectorAll('.csf-buttons');
        for (var i = 0; i < groups.length; i++) {
            if (groups[i].querySelector('.kratos-bbs-link')) continue;
            var reset = groups[i].querySelector('.csf-reset-all');
            /* 不自己拼元素，而是克隆同组里的「恢复全部」input 再改写：
             * 这一组按钮的观感来自 WP 后台的 input.button 规则 + 框架的
             * .csf-buttons .button（line-height:26px），换成 <a> 或 <button>
             * 元素都会因为默认 padding / 字号 / 盒模型不同而高低不齐。
             * 克隆能保证节点类型与继承链完全一致，只需去掉红色警示类和
             * submit 语义。没有 reset 按钮可克隆时才退回自建 input。 */
            var a;
            if (reset) {
                a = reset.cloneNode(false);
                a.removeAttribute('name');
                a.removeAttribute('data-confirm');
                a.className = 'button button-secondary kratos-bbs-link';
            } else {
                a = document.createElement('input');
                a.className = 'button button-secondary kratos-bbs-link';
            }
            a.type = 'button';
            a.value = label;
            a.addEventListener('click', function () {
                window.open(url, '_blank', 'noopener');
            });
            if (reset && reset.nextSibling) {
                groups[i].insertBefore(a, reset.nextSibling);
            } else {
                groups[i].appendChild(a);
            }
        }
    })();
    </script>
    <?php
}
add_action('admin_footer', 'kratos_options_bbs_button');

CSF::createOptions($prefix, array(
    'menu_title' => __('主题设置', 'kratos'),
    'menu_slug' => 'kratos-options',
    // 每个顶级分组在 WP 左侧菜单里注册一个子项（CSF 默认行为，保留）。
    //
    // 注意副作用：多出的 9 个子项把 WP 菜单顶到约 900px，落进 wp-admin/js/common.js
    // 的临界区 —— 比视口高一点时 setPinMenu() 会摘掉 body.sticky-menu，而 pinMenu()
    // 又因算不出正的 menuTop 直接 unpinMenu()，结果菜单彻底随页面滚。
    // 该问题由 kratos_csf_sticky_offset_script()（inc/theme-core.php）里接管
    // core 的 .pin-menu 事件来解决，不是靠删菜单项。
    'show_sub_menu' => true,
    'show_search' => false,
    'show_all_options' => false,
    // 顶部条（含「保存」按钮）吸顶，CSF 内置能力：滚动时给 .csf-header 加
    // .csf-sticky，把 .csf-header-inner 切成 position:fixed;top:32px。
    // 侧栏菜单的吸顶不在框架内，见 assets/css/admin.css 的 .csf-nav-normal 段。
    'sticky_header' => true,
    'admin_bar_menu_icon' => 'dashicons-admin-generic',
    'framework_title' => '主题设置<small style="margin-left:10px">Kratos-plus v' . THEME_VERSION . '</small>',
    'theme' => 'light',
    'footer_credit' => '感谢使用 Kratos-plus 主题进行创作。本主题基于 <a target="_blank" href="https://github.com/seatonjiang/kratos">Kratos</a>（GPL-3.0）二次开发。',
));

CSF::createSection($prefix, array(
    'id' => 'kp_basic',
    'title' => __('基础设置', 'kratos'),
    'icon' => 'fas fa-rocket',
));

CSF::createSection($prefix, array(
    'parent' => 'kp_basic',
    'title' => __('界面开关', 'kratos'),
    'icon' => 'fas fa-toggle-on',
    'fields' => array(
        array(
            'id' => 'g_adminbar',
            'type' => 'switcher',
            'title' => __('前台管理员导航', 'kratos'),
            'subtitle' => __('登录后在前台顶部显示 WordPress 管理条', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_login',
            'type' => 'switcher',
            'title' => __('侧边栏后台入口', 'kratos'),
            'subtitle' => __('点侧边栏「关于我」头像直接进后台', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_sticky',
            'type' => 'switcher',
            'title' => __('侧边栏随动', 'kratos'),
            'subtitle' => __('侧边栏随页面滚动吸顶', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_search',
            'type' => 'switcher',
            'title' => __('搜索增强', 'kratos'),
            'subtitle' => __('搜索时只匹配标题，不匹配正文', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_thumbnail',
            'type' => 'switcher',
            'title' => __('特色图片', 'kratos'),
            'subtitle' => __('列表与文章页是否显示特色图', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_list_layout',
            'type' => 'radio',
            'title' => __('文章列表布局', 'kratos'),
            'subtitle' => __('首页 / 分类 / 归档 / 搜索等列表的样式', 'kratos'),
            'options' => array(
                'classic'  => __('经典图文左右式（默认）', 'kratos'),
                'enhanced' => __('图文左右式增强版（左右交替 + 阴影浮起）', 'kratos'),
                'magazine' => __('经典大图卡片（大图在上 / 标题摘要在下）', 'kratos'),
                'grid'     => __('网格卡片（双列图上文下）', 'kratos'),
                'minimal'  => __('极简列表（无图 / 突出标题）', 'kratos'),
            ),
            'default' => 'classic',
        ),
        array(
            'id' => 'g_excerpt_length',
            'type' => 'text',
            'title' => __('文章简介缩略', 'kratos'),
            'subtitle' => __('列表摘要的字符数', 'kratos'),
            'default' => '260',
        ),
        array(
            'id' => 'g_page_lightgallery',
            'type' => 'switcher',
            'title' => __('页面图片灯箱', 'kratos'),
            'subtitle' => __('页面（非文章）内的图片点击放大', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_rip',
            'type' => 'switcher',
            'title' => __('哀悼功能', 'kratos'),
            'subtitle' => __('全站转为黑白，用于哀悼日', 'kratos'),
            'default' => false,
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
                    'subtitle' => __('页面右下角浮动显示微信二维码', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_wechat_img',
                    'type' => 'upload',
                    'title' =>  __('二维码图片', 'kratos'),
                    'library' => 'image',
                    'preview' => true,
                    'subtitle' => __('显示在页面右下角', 'kratos'),
                ),
            ),
            'default' => array(
                'g_wechat' => false,
                'g_wechat_img' => get_template_directory_uri() . '/assets/img/200.png',
            ),
        ),
    ),
));


CSF::createSection($prefix, array(
    'parent' => 'kp_basic',
    'title' => __('性能与资源', 'kratos'),
    'icon' => 'fas fa-bolt',
    'fields' => array(
        array(
            'id' => 'g_cdn',
            'type' => 'switcher',
            'title' => __('静态资源加速', 'kratos'),
            'subtitle' => __('主题静态资源改由 jsDelivr 分发', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_animate',
            'type' => 'switcher',
            'title' => __('CSS 动画库', 'kratos'),
            'subtitle' => __('加载 animate.css，供正文自定义动画使用', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_disable_emoji',
            'type' => 'switcher',
            'title' => __('禁用 WP Emoji', 'kratos'),
            'subtitle' => __('不再加载 wp-emoji 脚本与 s.w.org 的 emoji 图片。现代浏览器原生支持 emoji，国内访问更快', 'kratos'),
            'default' => true,
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
                    'subtitle' => __('用国内镜像替换 Gravatar 头像域名', 'kratos'),
                ),
                array(
                    'id' => 'g_select_gravatar_server',
                    'type' => 'select',
                    'title' => __('Gravatar 加速服务地址', 'kratos'),
                    'subtitle' => __('镜像服务商', 'kratos'),
                    'options' => array(
                        'loli' => __('Loli 加速服务', 'kratos'),
                        'geekzu' => __('极客族加速服务', 'kratos'),
                        'other' => __('自定义加速服务', 'kratos'),
                    ),
                    'desc' => __('国内推荐极客族，海外推荐 Loli。', 'kratos'),
                    'dependency' => array('g_replace_gravatar_url', '==', 'true'),
                ),
                array(
                    'id' => 'g_custom_gravatar_server',
                    'type' => 'text',
                    'title' => __('自定义 Gravatar 加速服务地址', 'kratos'),
                    'subtitle' => __('自定义镜像域名', 'kratos'),
                    'desc' => __('只填域名，不带协议头和结尾斜杠。', 'kratos'),
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
            'id' => 'g_font_fieldset',
            'type' => 'fieldset',
            'title' => __('自定义字体', 'kratos'),
            'subtitle' => __('全站换用自定义字体（代码块与图标除外）', 'kratos'),
            'fields' => array(
                array(
                    'id' => 'g_font_enable',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('是否启用', 'kratos'),
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
                    'subtitle' => __('@font-face 样式表地址；留空则只用系统已装字体', 'kratos'),
                ),
                array(
                    'id' => 'g_font_fallback',
                    'type' => 'text',
                    'title' => __('字体兜底栈', 'kratos'),
                    'subtitle' => __('首选字体缺失时的备用栈，如 -apple-system, sans-serif', 'kratos'),
                ),
            ),
            'default' => array(
                'g_font_enable' => false,
                'g_font_family' => '',
                'g_font_url' => '',
                'g_font_fallback' => 'sans-serif',
            ),
        ),
    ),
));


CSF::createSection($prefix, array(
    'parent' => 'kp_basic',
    'title' => __('后台与媒体', 'kratos'),
    'icon' => 'fas fa-photo-video',
    'fields' => array(
        array(
            'id' => 'g_gutenberg',
            'type' => 'switcher',
            'title' => __('Gutenberg 编辑器', 'kratos'),
            'subtitle' => __('关闭后文章编辑回到经典编辑器', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_classic_widgets',
            'type' => 'switcher',
            'title' => __('经典小工具编辑器', 'kratos'),
            'subtitle' => __('「外观 → 小工具」回到经典拖拽界面', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_renameimg',
            'type' => 'switcher',
            'title' => __('自定义图片类型的文件名', 'kratos'),
            'subtitle' => __('上传的图片文件名改为 MD5，避免中文名出错', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_removeimgsize',
            'type' => 'switcher',
            'title' => __('禁止生成缩略图', 'kratos'),
            'subtitle' => __('上传图片时不再生成各尺寸副本，省磁盘', 'kratos'),
            'default' => false,
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
                    'subtitle' => __('按规则重命名上传的非图片附件', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_renameother_prdfix',
                    'type' => 'text',
                    'title' => __('文件前缀', 'kratos'),
                    'subtitle' => __('与原文件名之间用 - 连接', 'kratos'),
                ),
                array(
                    'id' => 'g_renameother_mime',
                    'type' => 'text',
                    'title' => __('文件类型', 'kratos'),
                    'subtitle' => __('多个后缀用 | 隔开', 'kratos'),
                ),
            ),
            'default' => array(
                'g_renameother' => false,
                'g_renameother_prdfix' => 'kratos',
                'g_renameother_mime' => 'tar|zip|gz|gzip|rar|7z',
            ),
        ),
    ),
));


CSF::createSection($prefix, array(
    'parent' => 'kp_basic',
    'title' => __('布局尺寸', 'kratos'),
    'icon' => 'fas fa-ruler-combined',
    'fields' => array(
        array(
            'id' => 'g_main_col',
            'type' => 'slider',
            'title' => __('主体宽度', 'kratos'),
            'subtitle' => __('12 栅格中主体占几列，建议与侧栏之和为 12', 'kratos'),
            'min' => 5,
            'max' => 11,
            'step' => 1,
            'default' => 8,
        ),
        array(
            'id' => 'g_sidebar_col',
            'type' => 'slider',
            'title' => __('侧边栏宽度', 'kratos'),
            'subtitle' => __('12 栅格中侧栏占几列，建议与主体之和为 12', 'kratos'),
            'min' => 1,
            'max' => 7,
            'step' => 1,
            'default' => 4,
        ),
        array(
            'id' => 'g_container_max',
            'type' => 'number',
            'title' => __('页面主体最大宽度 (px)', 'kratos'),
            'subtitle' => __('大屏下容器最宽多少，最小 960；留空则不限制', 'kratos'),
            'min' => 960,
            'default' => 1280,
        ),
    ),
));


CSF::createSection($prefix, array(
    'parent' => 'kp_basic',
    'title' => __('性能优化', 'kratos'),
    'icon' => 'fas fa-tachometer-alt',
    'fields' => array(
        array(
            'type' => 'submessage',
            'style' => 'info',
            'content' => __('以下开关只关掉经典主题用不到的资源与轮询，不改变页面内容。若插件依赖被关掉的资源（最常见是 jQuery Migrate），单独关掉对应项即可。', 'kratos'),
        ),
        array(
            'id' => 'g_perf_asset_ondemand',
            'type' => 'switcher',
            'title' => __('播放器脚本按需加载', 'kratos'),
            'subtitle' => __('DPlayer 只在含 [dplayer] / [video] 的文章加载，其余页面不再加载', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_block_css',
            'type' => 'switcher',
            'title' => __('卸载区块编辑器前台样式', 'kratos'),
            'subtitle' => __('省掉每页 60–100KB 区块 CSS；用区块编辑器写的文章会自动跳过', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_wp_embed',
            'type' => 'switcher',
            'title' => __('移除 wp-embed.js 与 oEmbed 探测', 'kratos'),
            'subtitle' => __('该脚本只服务「别人嵌入本站文章」，本站嵌别人的内容不受影响', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_jquery_migrate',
            'type' => 'switcher',
            'title' => __('移除 jQuery Migrate', 'kratos'),
            'subtitle' => __('只为兼容老写法；若插件报 $.browser 之类错误就关掉', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_jquery_footer',
            'type' => 'switcher',
            'title' => __('jQuery 移到页脚', 'kratos'),
            'subtitle' => __('WordPress 默认把它打印在 <head> 且同步执行，会阻塞首屏渲染；若有插件在头部就要用 $ 则关掉', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_heartbeat_front',
            'type' => 'switcher',
            'title' => __('前台禁用 Heartbeat', 'kratos'),
            'subtitle' => __('它只服务后台自动保存，前台跑它等于空转 admin-ajax.php', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_heartbeat_interval',
            'type' => 'number',
            'title' => __('后台 Heartbeat 间隔（秒）', 'kratos'),
            'subtitle' => __('默认 15 秒一次，调大可降后台 CPU；编辑页自动收紧到 30 秒内。填 0 不改动', 'kratos'),
            'min' => 0,
            'max' => 300,
            'default' => 60,
        ),
        array(
            'id' => 'g_perf_hints',
            'type' => 'switcher',
            'title' => __('资源域预连接', 'kratos'),
            'subtitle' => __('给已启用的加速域提前建连，省一次 DNS + TLS 往返', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_hints_hosts',
            'type' => 'textarea',
            'title' => __('额外预连接域名', 'kratos'),
            'subtitle' => __('每行一个域名，用于自建 CDN / 图床等主题推导不到的域', 'kratos'),
            'default' => '',
            'dependency' => array('g_perf_hints', '==', 'true'),
        ),
        array(
            'id' => 'g_perf_lcp',
            'type' => 'switcher',
            'title' => __('首屏图片优先加载', 'kratos'),
            'subtitle' => __('首图（通常就是 LCP 元素）优先下载，其余图片保持延迟加载', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_img_responsive',
            'type' => 'switcher',
            'title' => __('图片按屏幕尺寸下发', 'kratos'),
            'subtitle' => __('给缺 srcset 的缩略图与正文图补上多档图源，并按图位真实宽度写 sizes；手机不再下载整张原图', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_img_mobile_quality',
            'type' => 'select',
            'title' => __('移动端图片画质', 'kratos'),
            'subtitle' => __('手机多为 3 倍屏，取满会和 2x 桌面屏拿同一档大图；压到 2x 左右肉眼几乎无差别', 'kratos'),
            'options' => array(
                'high' => __('高清 · 3 倍屏取满', 'kratos'),
                'balanced' => __('均衡 · 约 2 倍屏（推荐）', 'kratos'),
                'saver' => __('省流 · 约 1.5 倍屏', 'kratos'),
            ),
            'default' => 'balanced',
            'dependency' => array('g_perf_img_responsive', '==', 'true'),
        ),
        array(
            'id' => 'g_perf_img_cdn_tpl',
            'type' => 'text',
            'title' => __('图床缩放模板', 'kratos'),
            'subtitle' => __('留空则只使用本地已生成的缩略图尺寸', 'kratos'),
            'desc' => __('占位符：<code>{w}</code> 宽 / <code>{h}</code> 高 / <code>{ext}</code> 格式。填写后，本地没有对应尺寸文件的老图也能由图床实时缩放（火山引擎 ImageX 示例：<code>~tplv-服务ID-image:{w}:0.{ext}</code>，具体值以「图片处理配置」里创建的模板为准）。', 'kratos'),
            'default' => '',
            'dependency' => array('g_perf_img_responsive', '==', 'true'),
        ),
        array(
            'id' => 'g_perf_comment_async',
            'type' => 'switcher',
            'title' => __('评论提交后台化', 'kratos'),
            'subtitle' => __('通知邮件与缓存失效改在响应之后做。开了 SMTP 时能省掉访客 1–5 秒的等待', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_query_lean',
            'type' => 'switcher',
            'title' => __('次级查询瘦身', 'kratos'),
            'subtitle' => __('相关文章、系列、Now 等非分页查询跳过总数统计与无用的缓存预热', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_mobile_no_sidebar',
            'type' => 'switcher',
            'title' => __('移动端不输出侧边栏', 'kratos'),
            'subtitle' => __('按 UA 判定移动端时后端直接跳过侧边栏 HTML 与小工具查询；开启页面缓存请启用移动端分桶', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_perf_adminbar_metrics',
            'type' => 'switcher',
            'title' => __('工具条显示运行指标', 'kratos'),
            'subtitle' => __('前台管理条显示「查询数 · 耗时 · 内存」，仅管理员可见', 'kratos'),
            'default' => true,
        ),
        array(
            'type' => 'subheading',
            'content' => __('数据库瘦身', 'kratos'),
        ),
        array(
            'type' => 'callback',
            'function' => 'kratos_perf_render_db_panel',
        ),
        array(
            'type' => 'subheading',
            'content' => __('运行环境', 'kratos'),
        ),
        array(
            'type' => 'callback',
            'function' => 'kratos_perf_render_health_panel',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_basic',
    'title' => __('图片配置', 'kratos'),
    'icon' => 'fas fa-image',
    'fields' => array(
        array(
            'id' => 'g_logo',
            'type' => 'upload',
            'title' => __('站点 Logo', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'subtitle' => __('留空则显示站点标题', 'kratos'),
        ),
        array(
            'id' => 'g_icon',
            'type' => 'upload',
            'title' =>  __('Favicon 图标', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'subtitle' => __('浏览器标签页与收藏夹图标', 'kratos'),
        ),
        array(
            'id' => 'g_404',
            'type' => 'upload',
            'title' =>  __('404 页面图片', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'default' => get_template_directory_uri() . '/assets/img/404.jpg',
            'subtitle' => __('图片加载失败时的占位图', 'kratos'),
        ),
        array(
            'id' => 'g_nothing',
            'type' => 'upload',
            'title' =>  __('无内容图片', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'default' => get_template_directory_uri() . '/assets/img/nothing.svg',
            'subtitle' => __('搜索无结果或分类为空时显示', 'kratos'),
        ),
        array(
            'id' => 'g_postthumbnail_mode',
            'type' => 'radio',
            'title' => __('默认特色图模式', 'kratos'),
            'subtitle' => __('无特色图、正文也没图时怎么办', 'kratos'),
            'options' => array(
                'image' => __('上传图片', 'kratos'),
                'text'  => __('文字 + 颜色渲染', 'kratos'),
            ),
            'default' => 'image',
        ),
        array(
            'id' => 'g_postthumbnail',
            'type' => 'upload',
            'title' =>  __('默认特色图', 'kratos'),
            'library' => 'image',
            'preview' => true,
            'default' => get_template_directory_uri() . '/assets/img/default.jpg',
            'subtitle' => __('无特色图且正文无图时显示', 'kratos'),
            'dependency' => array('g_postthumbnail_mode', '==', 'image'),
        ),
        array(
            'id' => 'g_postthumbnail_preset',
            'type' => 'image_select',
            'title' => __('渲染预设', 'kratos'),
            'subtitle' => __('占位图的视觉风格', 'kratos'),
            'options' => array(
                'solid'    => array('image' => get_template_directory_uri() . '/assets/img/thumb-ph/solid.svg',    'label' => __('纯色', 'kratos')),
                'gradient' => array('image' => get_template_directory_uri() . '/assets/img/thumb-ph/gradient.svg', 'label' => __('渐变', 'kratos')),
                'retro'    => array('image' => get_template_directory_uri() . '/assets/img/thumb-ph/retro.svg',    'label' => __('复古', 'kratos')),
                'grid'     => array('image' => get_template_directory_uri() . '/assets/img/thumb-ph/grid.svg',     'label' => __('暗黑网格', 'kratos')),
                'notion'   => array('image' => get_template_directory_uri() . '/assets/img/thumb-ph/notion.svg',   'label' => __('Notion 风', 'kratos')),
            ),
            'default' => 'solid',
            'dependency' => array('g_postthumbnail_mode', '==', 'text'),
        ),
        array(
            'id' => 'g_postthumbnail_text_source',
            'type' => 'select',
            'title' => __('文字来源', 'kratos'),
            'options' => array(
                'title_initial'   => __('标题首字（默认）', 'kratos'),
                'title_two'       => __('标题前两字/首词', 'kratos'),
                'title_full'      => __('完整标题（长标题自动折行）', 'kratos'),
                'category'        => __('分类名', 'kratos'),
                'custom'          => __('自定义固定字符', 'kratos'),
            ),
            'default' => 'title_initial',
            'dependency' => array('g_postthumbnail_mode', '==', 'text'),
        ),
        array(
            'id' => 'g_postthumbnail_text_custom',
            'type' => 'text',
            'title' => __('自定义文字', 'kratos'),
            'subtitle' => __('建议不超过 4 个字', 'kratos'),
            'default' => 'Kratos-plus',
            'dependency' => array('g_postthumbnail_text_source|g_postthumbnail_mode', '==|==', 'custom|text'),
        ),
        array(
            'id' => 'g_postthumbnail_bg_mode',
            'type' => 'select',
            'title' => __('底色模式', 'kratos'),
            'subtitle' => __('仅 Solid 预设生效，其他预设自带底色', 'kratos'),
            'options' => array(
                'hash'  => __('哈希调色板（按文章稳定生成）', 'kratos'),
                'fixed' => __('固定色', 'kratos'),
            ),
            'default' => 'hash',
            'dependency' => array('g_postthumbnail_mode|g_postthumbnail_preset', '==|==', 'text|solid'),
        ),
        array(
            'id' => 'g_postthumbnail_bg_fixed',
            'type' => 'color',
            'title' => __('固定底色', 'kratos'),
            'default' => '#5B8DEF',
            'dependency' => array('g_postthumbnail_mode|g_postthumbnail_preset|g_postthumbnail_bg_mode', '==|==|==', 'text|solid|fixed'),
        ),
        array(
            'id' => 'g_postthumbnail_palette',
            'type' => 'select',
            'title' => __('调色板', 'kratos'),
            'options' => array(
                'material' => __('Material（明亮活泼）', 'kratos'),
                'pastel'   => __('Pastel（马卡龙）', 'kratos'),
                'morandi'  => __('Morandi（莫兰迪灰调）', 'kratos'),
                'retro'    => __('Retro（复古土色）', 'kratos'),
                'ink'      => __('Ink（水墨深色）', 'kratos'),
            ),
            'default' => 'material',
            'dependency' => array('g_postthumbnail_mode|g_postthumbnail_preset', '==|any', 'text|solid,gradient,notion'),
        ),
        array(
            'id' => 'g_postthumbnail_fg_mode',
            'type' => 'select',
            'title' => __('文字颜色', 'kratos'),
            'options' => array(
                'auto'  => __('自动（按底色亮度选黑/白）', 'kratos'),
                'white' => __('白色', 'kratos'),
                'black' => __('黑色', 'kratos'),
            ),
            'default' => 'auto',
            'dependency' => array('g_postthumbnail_mode', '==', 'text'),
        ),
        array(
            'id' => 'g_postthumbnail_font',
            'type' => 'select',
            'title' => __('字体族', 'kratos'),
            'options' => array(
                'sans'  => __('无衬线', 'kratos'),
                'serif' => __('衬线（Georgia/宋体）', 'kratos'),
                'mono'  => __('等宽', 'kratos'),
            ),
            'default' => 'sans',
            'dependency' => array('g_postthumbnail_mode', '==', 'text'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_basic',
    'title' => __('首页轮播', 'kratos'),
    'icon' => 'fas fa-images',
    'fields' => array(
        array(
            'id' => 'g_carousel',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('首页顶部显示轮播图', 'kratos'),
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
                    'subtitle' =>  __('仅用于识别，可作备注', 'kratos'),
                ),
                array(
                    'id' => 'c_img',
                    'type' => 'upload',
                    'title' => __('轮播图片', 'kratos'),
                    'subtitle' =>  __('可填图片链接，也可上传', 'kratos'),
                    'library' => 'image',
                    'preview' => true,
                ),
                array(
                    'id' => 'c_url',
                    'type' => 'text',
                    'title' =>  __('网址链接', 'kratos'),
                    'subtitle' =>  __('需含协议头的完整地址', 'kratos'),
                ),
                array(
                    'id' => 'c_title',
                    'type' => 'text',
                    'title' =>  __('轮播标题', 'kratos'),
                    'subtitle' =>  __('选填，留空则不显示', 'kratos'),
                ),
                array(
                    'id' => 'c_subtitle',
                    'type' => 'textarea',
                    'title' =>  __('轮播简介', 'kratos'),
                    'subtitle' =>  __('选填，留空则不显示', 'kratos'),
                ),
                array(
                    'id' => 'c_color',
                    'type' => 'color',
                    'default' => '#000',
                    'title' =>  __('文字颜色', 'kratos'),
                    'subtitle' => __('标题与简介的文字颜色', 'kratos'),
                ),
            ),
        ),
    )
));

CSF::createSection($prefix, array(
    'parent' => 'kp_basic',
    'title' => __('对象存储加速', 'kratos'),
    'icon' => 'fas fa-cloud',
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
                    'id' => 'g_cos',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('上传的媒体改存 DogeCloud', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_cos_bucketname',
                    'type' => 'text',
                    'title' => __('空间名称', 'kratos'),
                    'subtitle' => __('在空间基本信息里查看', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.dogecloud.com/oss/list">空间列表</a>查看', 'kratos'),
                ),
                array(
                    'id' => 'g_cos_url',
                    'type' => 'text',
                    'title' => __('加速域名', 'kratos'),
                    'subtitle' => __('结尾不要带 /', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.dogecloud.com/oss/list">空间列表</a>查看', 'kratos'),
                ),
                array(
                    'id' => 'g_cos_accesskey',
                    'type' => 'text',
                    'title' => __('AccessKey', 'kratos'),
                    'subtitle' => __('建议定期更换', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.dogecloud.com/user/keys">密钥管理</a>查看', 'kratos'),
                ),
                array(
                    'id' => 'g_cos_secretkey',
                    'type' => 'text',
                    'attributes' => array(
                        'type' => 'password',
                    ),
                    'title' => __('SecretKey', 'kratos'),
                    'subtitle' => __('建议定期更换', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.dogecloud.com/user/keys">密钥管理</a>查看', 'kratos'),
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
                    'id' => 'g_imgx',
                    'type' => 'switcher',
                    'title' => __('功能开关', 'kratos'),
                    'subtitle' => __('上传的媒体改存火山引擎 ImageX', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_region',
                    'type' => 'select',
                    'title' => __('加速地域', 'kratos'),
                    'subtitle' => __('创建服务时选定的地域', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.volcengine.com/imagex/service_manage/">服务管理</a>查看', 'kratos'),
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
                    'subtitle' => __('在图片服务管理里查看', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.volcengine.com/imagex/service_manage/">服务管理</a>查看', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_url',
                    'type' => 'text',
                    'title' => __('加速域名', 'kratos'),
                    'subtitle' => __('结尾不要带 /', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.volcengine.com/imagex/service_manage/">服务管理</a>查看', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_tmp',
                    'type' => 'text',
                    'title' => __('处理模板', 'kratos'),
                    'subtitle' => __('在图片处理配置里查看', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.volcengine.com/imagex/image_template/">图片处理配置</a>查看', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_accesskey',
                    'type' => 'text',
                    'title' => __('AccessKey', 'kratos'),
                    'subtitle' => __('建议定期更换', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.volcengine.com/iam/keymanage/">密钥管理</a>查看', 'kratos'),
                ),
                array(
                    'id' => 'g_imgx_secretkey',
                    'type' => 'text',
                    'attributes' => array(
                        'type' => 'password',
                    ),
                    'title' => __('SecretKey', 'kratos'),
                    'subtitle' => __('建议定期更换', 'kratos'),
                    'desc' => __('在 <a target="_blank" href="https://console.volcengine.com/iam/keymanage/">密钥管理</a>查看', 'kratos'),
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
    'parent' => 'kp_basic',
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
            'subtitle' => __('搜索引擎与社交平台抓取时使用', 'kratos'),
        ),
        array(
            'id' => 'seo_keywords',
            'type' => 'text',
            'title' => __('关键词', 'kratos'),
            'subtitle' =>  __('多个关键词用 , 分隔', 'kratos'),
        ),
        array(
            'id' => 'seo_description',
            'type' => 'textarea',
            'title' => __('站点描述', 'kratos'),
            'subtitle' =>  __('首页的描述信息', 'kratos'),
        ),
        array(
            'id' => 'seo_twitter_site',
            'type' => 'text',
            'title' => __('Twitter / X 账号', 'kratos'),
            'subtitle' => __('形如 @username，用于 twitter:site；留空则不输出', 'kratos'),
        ),
        array(
            'id' => 'seo_statistical',
            'title' => __('统计代码', 'kratos'),
            'subtitle' => __('<span style="color:red">粘贴前请确认代码来源可信</span>', 'kratos'),
            'type' => 'code_editor',
            'settings' => array(
                'theme' => 'default',
                'mode' => 'htmlmixed',
                // 语法模式（htmlmixed）由 CSF main.js 的 CodeMirror.autoLoadMode 懒加载，
                // URL 前缀取自这里；指到主题内置的那份，避免回落到 jsDelivr。
                // 目录与资源入队见 inc/theme-extends.php 的 kratos_csf_local_codemirror()。
                'cdnURL' => kratos_cm_base_url(),
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
    'parent' => 'kp_basic',
    'title' => __('RSS 订阅', 'kratos'),
    'icon' => 'fas fa-rss',
    'fields' => array(
        array(
            'type' => 'content',
            'content' => '<ul><li>' . __('- 每页输出条数与「摘要 / 全文」由 ', 'kratos') . '<a href="' . admin_url('options-reading.php') . '" target="_blank">' . __('设置-阅读', 'kratos') . '</a>' . __(' 控制，此处只做分类级别的内容过滤', 'kratos') . '</li><li>' . __('- 点击 ', 'kratos') . '<a href="' . home_url() . '/feed" target="_blank">/feed</a>' . __(' 查看配置是否生效，如果网站开启了 CDN 或缓存插件，可能需要刷新缓存', 'kratos') . '</li></ul>',
        ),
        array(
            'id' => 'g_rss_exclude_cats',
            'type' => 'checkbox',
            'title' => __('排除分类', 'kratos'),
            'subtitle' => __('这些分类的文章不进 RSS 订阅；留空则输出全部', 'kratos'),
            'options' => (function () {
                $out = array();
                $terms = function_exists('get_terms') ? get_terms(array(
                    'taxonomy'   => 'category',
                    'hide_empty' => false,
                )) : array();
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $t) {
                        $out[(int) $t->term_id] = $t->name;
                    }
                }
                return $out;
            })(),
            'inline' => true,
            'default' => array(),
        ),
        array(
            'id' => 'g_rss_exclude_children',
            'type' => 'switcher',
            'title' => __('连同子分类', 'kratos'),
            'subtitle' => __('被排除分类的子分类也一并排除', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_rss_block_term_feed',
            'type' => 'switcher',
            'title' => __('屏蔽分类 Feed', 'kratos'),
            'subtitle' => __('直接访问被排除分类的 Feed（/category/xxx/feed）时返回空列表；关闭则该分类 Feed 仍可正常订阅', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_rss_exclude_comments',
            'type' => 'switcher',
            'title' => __('同步过滤评论 Feed', 'kratos'),
            'subtitle' => __('评论 Feed 里不再输出被排除分类文章下的评论', 'kratos'),
            'default' => true,
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_basic',
    'title' => __('邮件配置', 'kratos'),
    'icon' => 'fas fa-envelope',
    'fields' => array(
        array(
            'id' => 'm_smtp',
            'type' => 'switcher',
            'title' => __('SMTP 服务', 'kratos'),
            'subtitle' => __('用 SMTP 发信，替代 PHP 默认 mail()', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'm_host',
            'type' => 'text',
            'title' => __('邮件服务器', 'kratos'),
            'subtitle' => __('发件服务器地址', 'kratos'),
            'placeholder' => __('smtp.example.com', 'kratos'),
        ),
        array(
            'id' => 'm_port',
            'type' => 'text',
            'title' => __('服务器端口', 'kratos'),
            'subtitle' => __('发件服务器端口', 'kratos'),
            'placeholder' => __('465', 'kratos'),
        ),
        array(
            'id' => 'm_sec',
            'type' => 'text',
            'title' => __('授权方式', 'kratos'),
            'subtitle' => __('加密方式', 'kratos'),
            'placeholder' => __('ssl', 'kratos'),
        ),
        array(
            'id' => 'm_username',
            'type' => 'text',
            'title' => __('邮箱帐号', 'kratos'),
            'subtitle' => __('邮箱账号', 'kratos'),
            'placeholder' => __('user@example.com', 'kratos'),
        ),
        array(
            'id' => 'm_passwd',
            'type' => 'text',
            'title' => __('邮箱密码', 'kratos'),
            'subtitle' => __('邮箱密码或授权码', 'kratos'),
            'attributes' => array(
                'type' => 'password',
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'kp_skin',
    'title' => __('皮肤与配色', 'kratos'),
    'icon' => 'fas fa-palette',
));

CSF::createSection($prefix, array(
    'parent' => 'kp_skin',
    'title' => __('每日皮肤', 'kratos'),
    'icon' => 'fas fa-palette',
    'fields' => array(
        array(
            'id' => 'g_weekday_skin_mode',
            'type' => 'button_set',
            'title' => __('皮肤模式', 'kratos'),
            'subtitle' => __('off 默认外观；auto 按访客时区每天换一套；locked 锁定一套。暗夜模式优先级更高。', 'kratos'),
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
            'subtitle' => __('仅锁定模式下生效', 'kratos'),
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
                'vermilion' => __('喜庆 · 朱砂', 'kratos'),
                'morandi'   => __('柔和 · 莫兰迪', 'kratos'),
                'mist'      => __('清新 · 莫兰迪雾霭', 'kratos'),
                'linen'     => __('暖调 · 莫兰迪亚麻', 'kratos'),
                'porcelain' => __('清雅 · 莫兰迪青瓷', 'kratos'),
                'lavender'  => __('柔美 · 莫兰迪薰衣草', 'kratos'),
                'retro'     => __('复古 · 牛皮纸', 'kratos'),
                'web1998'   => __('复古 · 千禧网页', 'kratos'),
                'ebook'     => __('电子书 · 纸墨', 'kratos'),
                'bookfold'  => __('书卷 · 半开卷', 'kratos'),
                'bourse'    => __('金融 · 盘口', 'kratos'),
            ),
            'default' => 'mon',
            'dependency' => array('g_weekday_skin_mode', '==', 'locked'),
        ),
        array(
            'id' => 'g_weekday_skin_switcher',
            'type' => 'switcher',
            'title' => __('前端皮肤切换器', 'kratos'),
            'subtitle' => __('页脚工具栈多一个「皮肤」按钮，访客可自行切换。', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_weekday_skin_cmdk_hint',
            'type' => 'content',
            'content' => '<div style="padding:10px 12px;background:#eef4fb;border:1px solid #cfe0f2;border-radius:8px;line-height:1.8;color:#3f5f80;">'
                . __('命令面板里的皮肤分组在 <strong>全站配置 → 命令面板 → 展示皮肤切换</strong>，与上面的页脚按钮相互独立 —— 只开命令面板那一项，也能让访客选皮肤并在下次访问时保持。', 'kratos')
                . '</div>',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_skin',
    'title' => __('暗夜模式', 'kratos'),
    'icon' => 'fas fa-moon',
    'fields' => array(
        array(
            'id' => 'g_darkmode',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('含时间段自动切换、跟随系统与手动切换', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_darkmode_default',
            'type' => 'button_set',
            'title' => __('默认模式', 'kratos'),
            'subtitle' => __('首次访问、尚未手动切换时的呈现', 'kratos'),
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
            'subtitle' => __('24 小时制，如 19:00', 'kratos'),
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
            'subtitle' => __('24 小时制，如 07:00；小于开始时间即视为跨午夜', 'kratos'),
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
            'subtitle' => __('右下角显示明/暗切换按钮；访客的选择存在其浏览器本地', 'kratos'),
            'text_on' => __('显示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => true,
            'dependency' => array('g_darkmode', '==', 'true'),
        ),
        array(
            'id' => 'g_darkmode_cmdk_hint',
            'type' => 'content',
            'content' => '<div style="padding:10px 12px;background:#eef4fb;border:1px solid #cfe0f2;border-radius:8px;line-height:1.8;color:#3f5f80;">'
                . __('命令面板里的「切换暗色 / 亮色」入口在 <strong>全站配置 → 命令面板 → 展示暗色切换</strong>，与上面的页脚按钮相互独立 —— 隐藏了页脚按钮，命令面板依然可以切换。', 'kratos')
                . '</div>',
            'dependency' => array('g_darkmode', '==', 'true'),
        ),
        array(
            'id' => 'g_darkmode_remember_days',
            'type' => 'number',
            'title' => __('用户偏好记住天数', 'kratos'),
            'subtitle' => __('访客选择的保留天数，0 为永久', 'kratos'),
            'min' => 0,
            'max' => 365,
            'default' => 30,
            'dependency' => array('g_darkmode|g_darkmode_toggle', '==|==', 'true|true'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_skin',
    'title' => __('颜色配置', 'kratos'),
    'icon' => 'fas fa-fill-drip',
    'fields' => array(
        array(
            'id' => 'g_background',
            'type' => 'color',
            'default' => '#f5f5f5',
            'title' =>  __('全站背景颜色', 'kratos'),
            'subtitle' => __('页面背景色', 'kratos'),
        ),
        array(
            'id' => 'g_nav',
            'type' => 'color',
            'default' => '#ffffff',
            'title' =>  __('导航栏文字颜色', 'kratos'),
            'subtitle' => __('导航栏标题与一级菜单的文字色', 'kratos'),
        ),
        array(
            'id' => 'g_chrome',
            'type' => 'color',
            'default' => '#282a2c',
            'title' =>  __('Chrome 导航栏颜色', 'kratos'),
            'subtitle' => __('移动端浏览器地址栏配色', 'kratos'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'kp_chrome',
    'title' => __('顶部与页脚', 'kratos'),
    'icon' => 'fas fa-window-maximize',
));

CSF::createSection($prefix, array(
    'parent' => 'kp_chrome',
    'title' => __('顶部 · 图片导航', 'kratos'),
    'icon' => 'fas fa-file-image',
    'fields' => array(
        array(
            'id' => 'top_img_switch',
            'type' => 'switcher',
            'title' => __('图片导航', 'kratos'),
            'subtitle' => __('导航区用背景图', 'kratos'),
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
            'default' => __('Kratos-plus', 'kratos'),
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
    'parent' => 'kp_chrome',
    'title' => __('顶部 · 颜色导航', 'kratos'),
    'icon' => 'fas fa-swatchbook',
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
    'parent' => 'kp_chrome',
    'title' => __('顶部 · 导航吸顶', 'kratos'),
    'icon' => 'fas fa-thumbtack',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('向下滚动时导航固定在顶部，可按设备分别开启。', 'kratos'),
        ),
        array(
            'id' => 'nav_sticky_pc',
            'type' => 'switcher',
            'title' => __('PC 端吸顶', 'kratos'),
            'subtitle' => __('桌面端（≥ 992px）', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'nav_sticky_pad',
            'type' => 'switcher',
            'title' => __('平板端吸顶', 'kratos'),
            'subtitle' => __('平板（768 ~ 991px）', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'nav_sticky_mobile',
            'type' => 'switcher',
            'title' => __('手机端吸顶', 'kratos'),
            'subtitle' => __('手机（< 768px）', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'nav_sticky_bg',
            'type' => 'color',
            'title' => __('吸顶背景色（默认主题）', 'kratos'),
            'subtitle' => __('仅默认外观下生效；启用皮肤或暗夜时跟随皮肤配色。', 'kratos'),
            'default' => '#24292e',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_chrome',
    'title' => __('自定义代码', 'kratos'),
    'icon' => 'fas fa-code',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('原样注入到前台页面。仅在需要接入第三方统计、验证代码、A/B 脚本等场景时使用；填错会影响前台渲染。留空即关闭。', 'kratos'),
        ),
        array(
            'id' => 'g_custom_head_code',
            'type' => 'textarea',
            'title' => __('前台 Head 代码', 'kratos'),
            'subtitle' => __('输出到前台 &lt;/head&gt; 之前（wp_head 尾部）', 'kratos'),
            'sanitize' => false,
            'attributes' => array(
                'rows' => 8,
                'style' => 'font-family:Consolas,Monaco,monospace;',
                'placeholder' => "<!-- 例如：统计 / 站点验证 -->\n<meta name=\"baidu-site-verification\" content=\"xxxxx\" />",
            ),
        ),
        array(
            'id' => 'g_custom_footer_code',
            'type' => 'textarea',
            'title' => __('前台 Footer 代码', 'kratos'),
            'subtitle' => __('输出到前台 &lt;/body&gt; 之前（wp_footer 尾部）', 'kratos'),
            'sanitize' => false,
            'attributes' => array(
                'rows' => 8,
                'style' => 'font-family:Consolas,Monaco,monospace;',
                'placeholder' => "<!-- 例如：客服组件 / 异步脚本 -->\n<script>/* your code */</script>",
            ),
        ),
        array(
            'id' => 'g_custom_code_admin_only',
            'type' => 'switcher',
            'title' => __('仅登录用户可见', 'kratos'),
            'subtitle' => __('调试期使用：仅在当前用户已登录时注入上述代码', 'kratos'),
            'default' => false,
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_chrome',
    'title' => __('页脚 · 社交图标', 'kratos'),
    'icon' => 'fas fa-share-alt',
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
        array(
            'type' => 'subheading',
            'content' => __('自定义图标', 'kratos'),
        ),
        array(
            'id' => 's_social_custom',
            'type' => 'group',
            'title' => __('自定义社交图标', 'kratos'),
            'subtitle' => __('预置平台之外可自行追加，显示在预置图标之后。', 'kratos'),
            'button_title' => __('添加图标', 'kratos'),
            'accordion_title_prefix' => __('图标', 'kratos'),
            'accordion_title_number' => true,
            'fields' => array(
                array(
                    'id' => 'title',
                    'type' => 'text',
                    'title' => __('名称', 'kratos'),
                    'subtitle' => __('用作悬停提示与无障碍标签', 'kratos'),
                ),
                array(
                    'id' => 'url',
                    'type' => 'text',
                    'title' => __('链接', 'kratos'),
                    'placeholder' => 'https://example.com',
                ),
                array(
                    'id' => 'icon_type',
                    'type' => 'button_set',
                    'title' => __('图标类型', 'kratos'),
                    'options' => array(
                        'fontawesome' => __('Font Awesome', 'kratos'),
                        'image' => __('图片', 'kratos'),
                    ),
                    'default' => 'fontawesome',
                ),
                array(
                    'id' => 'icon',
                    'type' => 'icon',
                    'title' => __('图标', 'kratos'),
                    'subtitle' => __('从 Font Awesome 图标库选择', 'kratos'),
                    'dependency' => array('icon_type', '==', 'fontawesome'),
                ),
                array(
                    'id' => 'icon_image',
                    'type' => 'upload',
                    'title' => __('图片', 'kratos'),
                    'subtitle' => __('建议 32×32 以上的正方形透明 PNG/SVG', 'kratos'),
                    'library' => 'image',
                    'preview' => true,
                    'dependency' => array('icon_type', '==', 'image'),
                ),
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_chrome',
    'title' => __('页脚 · 备案信息', 'kratos'),
    'icon' => 'fas fa-shield-alt',
    'fields' => array(
        array(
            'id' => 's_icp',
            'type' => 'text',
            'title' => __('工信部备案信息', 'kratos'),
            'subtitle' => __('查询入口：<a target="_blank" href="https://beian.miit.gov.cn/">工信部政务服务平台</a>', 'kratos'),
            'placeholder' => __('冀ICP证XXXXXX号', 'kratos'),
        ),
        array(
            'id' => 's_gov',
            'type' => 'text',
            'title' => __('公安备案信息', 'kratos'),
            'subtitle' => __('查询入口：<a target="_blank" href="http://www.beian.gov.cn/">全国互联网安全管理服务平台</a>', 'kratos'),
            'placeholder' => __('冀公网安备 XXXXXXXXXXXXX 号', 'kratos'),
        ),
        array(
            'id' => 's_gov_link',
            'type' => 'text',
            'title' => __('公安备案链接', 'kratos'),
            'subtitle' => __('查询入口：<a target="_blank" href="http://www.beian.gov.cn/">全国互联网安全管理服务平台</a>', 'kratos'),
            'placeholder' => __('http://www.beian.gov.cn/portal/registerSystemInfo?recordcode=xxxxx', 'kratos'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_chrome',
    'title' => __('页脚 · 版权信息', 'kratos'),
    'icon' => 'fas fa-copyright',
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
    'parent' => 'kp_chrome',
    'title' => __('登录页', 'kratos'),
    'icon' => 'fas fa-sign-in-alt',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('功能开关', 'kratos'),
        ),
        array(
            'id' => 'g_login_enable',
            'type' => 'switcher',
            'title' => __('启用自定义登录页', 'kratos'),
            'subtitle' => __('接管 wp-login.php 换成双栏简洁风；关闭则用 WordPress 默认页', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_login_register_show',
            'type' => 'switcher',
            'title' => __('显示注册 Tab', 'kratos'),
            'subtitle' => __('显示「注册」入口，仍受 WordPress 注册开关约束', 'kratos'),
            'text_on' => __('显示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => true,
            'dependency' => array('g_login_enable', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('左侧品牌栏', 'kratos'),
        ),
        array(
            'id' => 'g_login_brand_show',
            'type' => 'switcher',
            'title' => __('显示品牌栏', 'kratos'),
            'subtitle' => __('关闭则为单栏居中卡片', 'kratos'),
            'text_on' => __('显示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => true,
            'dependency' => array('g_login_enable', '==', 'true'),
        ),
        array(
            'id' => 'g_login_brand_eyebrow',
            'type' => 'text',
            'title' => __('眼眉小标', 'kratos'),
            'subtitle' => __('标题上方的小字，自动转大写并加字距', 'kratos'),
            'default' => 'WELCOME · 欢迎回来',
            'dependency' => array('g_login_enable|g_login_brand_show', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_login_brand_title',
            'type' => 'textarea',
            'title' => __('主标题', 'kratos'),
            'subtitle' => __('支持 HTML，&lt;em&gt; 内的文字显示为主色斜体', 'kratos'),
            'default' => '在这里，<em>写下</em><br>属于你的每日思绪。',
            'dependency' => array('g_login_enable|g_login_brand_show', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_login_brand_desc',
            'type' => 'textarea',
            'title' => __('描述段落', 'kratos'),
            'default' => 'Kratos-plus 是一款为写作者打造的 WordPress 主题，简洁、有序、可自定义。登录后开始你的创作之旅。',
            'dependency' => array('g_login_enable|g_login_brand_show', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_login_brand_bg',
            'type' => 'upload',
            'title' => __('背景图（可选）', 'kratos'),
            'subtitle' => __('品牌栏底图；留空则用默认网格纹理 + 渐变', 'kratos'),
            'default' => '',
            'dependency' => array('g_login_enable|g_login_brand_show', '==|==', 'true|true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('底部数据条', 'kratos'),
        ),
        array(
            'type' => 'content',
            'content' => __('<p style="color:#8a8a8a;margin:0 0 12px;font-size:12px;">数值可用动态令牌，展示时自动替换：<code>{posts}</code> 文章 · <code>{tags}</code> 标签 · <code>{comments}</code> 评论 · <code>{users}</code> 用户；也可直接填静态文本。</p>', 'kratos'),
            'dependency' => array('g_login_enable|g_login_brand_show', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_login_stat_1_show',
            'type' => 'switcher',
            'title' => __('数据 1 · 显示', 'kratos'),
            'text_on' => __('显示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => true,
            'dependency' => array('g_login_enable|g_login_brand_show', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_login_stat_1_value',
            'type' => 'text',
            'title' => __('数据 1 · 数值', 'kratos'),
            'default' => '{posts}',
            'dependency' => array('g_login_enable|g_login_brand_show|g_login_stat_1_show', '==|==|==', 'true|true|true'),
        ),
        array(
            'id' => 'g_login_stat_1_label',
            'type' => 'text',
            'title' => __('数据 1 · 说明', 'kratos'),
            'default' => 'ARTICLES',
            'dependency' => array('g_login_enable|g_login_brand_show|g_login_stat_1_show', '==|==|==', 'true|true|true'),
        ),
        array(
            'id' => 'g_login_stat_2_show',
            'type' => 'switcher',
            'title' => __('数据 2 · 显示', 'kratos'),
            'text_on' => __('显示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => true,
            'dependency' => array('g_login_enable|g_login_brand_show', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_login_stat_2_value',
            'type' => 'text',
            'title' => __('数据 2 · 数值', 'kratos'),
            'default' => '{tags}',
            'dependency' => array('g_login_enable|g_login_brand_show|g_login_stat_2_show', '==|==|==', 'true|true|true'),
        ),
        array(
            'id' => 'g_login_stat_2_label',
            'type' => 'text',
            'title' => __('数据 2 · 说明', 'kratos'),
            'default' => 'TAGS',
            'dependency' => array('g_login_enable|g_login_brand_show|g_login_stat_2_show', '==|==|==', 'true|true|true'),
        ),
        array(
            'id' => 'g_login_stat_3_show',
            'type' => 'switcher',
            'title' => __('数据 3 · 显示', 'kratos'),
            'text_on' => __('显示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => true,
            'dependency' => array('g_login_enable|g_login_brand_show', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_login_stat_3_value',
            'type' => 'text',
            'title' => __('数据 3 · 数值', 'kratos'),
            'default' => '{comments}',
            'dependency' => array('g_login_enable|g_login_brand_show|g_login_stat_3_show', '==|==|==', 'true|true|true'),
        ),
        array(
            'id' => 'g_login_stat_3_label',
            'type' => 'text',
            'title' => __('数据 3 · 说明', 'kratos'),
            'default' => 'COMMENTS',
            'dependency' => array('g_login_enable|g_login_brand_show|g_login_stat_3_show', '==|==|==', 'true|true|true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('自定义登录 URL', 'kratos'),
        ),
        array(
            'id' => 'g_login_custom_url_enabled',
            'type' => 'switcher',
            'title' => __('启用自定义登录 URL', 'kratos'),
            'subtitle' => __('之后只能从下面的 slug 进后台，直访 /wp-login.php 会返回 404', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => false,
            'dependency' => array('g_login_enable', '==', 'true'),
        ),
        array(
            'id' => 'g_login_custom_url_slug',
            'type' => 'text',
            'title' => __('登录 slug', 'kratos'),
            'subtitle' => __('访问路径为 <code>' . esc_html(home_url('/')) . '{slug}/</code>；只能包含小写字母、数字、连字符', 'kratos'),
            'default' => 'sign-in',
            'dependency' => array('g_login_enable|g_login_custom_url_enabled', '==|==', 'true|true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('反机器人（登录 / 注册 / 找回密码）', 'kratos'),
        ),
        array(
            'id' => 'g_login_honeypot_enabled',
            'type' => 'switcher',
            'title' => __('蜜罐', 'kratos'),
            'subtitle' => __('用隐藏字段 + 最短填写耗时识别机器人，用户无感知', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
            'dependency' => array('g_login_enable', '==', 'true'),
        ),
        array(
            'id' => 'g_login_honeypot_min_seconds',
            'type' => 'number',
            'title' => __('最短提交耗时（秒）', 'kratos'),
            'subtitle' => __('从打开页面到提交低于此秒数即判为机器人', 'kratos'),
            'default' => 2,
            'dependency' => array('g_login_enable|g_login_honeypot_enabled', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_login_captcha_enabled',
            'type' => 'switcher',
            'title' => __('数字验证码', 'kratos'),
            'subtitle' => __('让用户算一道「3 + 5 =」，进一步挡机器人', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => false,
            'dependency' => array('g_login_enable', '==', 'true'),
        ),
        array(
            'id' => 'g_login_captcha_max',
            'type' => 'number',
            'title' => __('运算数上限', 'kratos'),
            'subtitle' => __('两个操作数的最大值', 'kratos'),
            'default' => 20,
            'dependency' => array('g_login_enable|g_login_captcha_enabled', '==|==', 'true|true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('底部小字', 'kratos'),
        ),
        array(
            'id' => 'g_login_footer_note',
            'type' => 'text',
            'title' => __('页脚版权文本', 'kratos'),
            'subtitle' => __('卡片底部的小字，留空则不显示', 'kratos'),
            'default' => '© Kratos-plus · 由 Dylan Li 二次开发',
            'dependency' => array('g_login_enable', '==', 'true'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'kp_post',
    'title' => __('文章与阅读', 'kratos'),
    'icon' => 'fas fa-file-alt',
));

CSF::createSection($prefix, array(
    'parent' => 'kp_post',
    'title' => __('文章配置', 'kratos'),
    'icon' => 'fas fa-file-alt',
    'fields' => array(
        array(
            'id' => 'g_163mic',
            'type' => 'switcher',
            'title' => __('网易云音乐', 'kratos'),
            'subtitle' => __('正文中的网易云外链自动播放', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_post_comments',
            'type' => 'switcher',
            'title' => __('评论数量展示', 'kratos'),
            'subtitle' => __('列表与文章页显示评论数', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_post_views',
            'type' => 'switcher',
            'title' => __('热度数量展示', 'kratos'),
            'subtitle' => __('列表与文章页显示热度（浏览量）', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_post_loves',
            'type' => 'switcher',
            'title' => __('点赞数量展示', 'kratos'),
            'subtitle' => __('列表与文章页显示点赞数', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_post_author',
            'type' => 'switcher',
            'title' => __('作者名称展示', 'kratos'),
            'subtitle' => __('列表显示作者名', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_post_revision',
            'type' => 'switcher',
            'title' => __('禁用文章修订版本', 'kratos'),
            'subtitle' => __('保存文章时不再留 revision 历史，减少数据库膨胀（不影响自动保存）', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_post_autosave_off',
            'type' => 'switcher',
            'title' => __('禁用编辑器自动保存', 'kratos'),
            'subtitle' => __('编辑器不再每分钟自动存稿。浏览器崩溃或断网时将没有草稿可恢复，请自行勤按保存', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_post_copyright',
            'type' => 'switcher',
            'title' => __('版权声明', 'kratos'),
            'subtitle' => __('文章末尾与 RSS 输出「除非注明，否则均为……原创文章」及本文链接', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_image_filter',
            'type' => 'switcher',
            'title' => __('按类型筛选媒体库功能', 'kratos'),
            'subtitle' => __('媒体库顶部增加按文件类型筛选', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_article_lightgallery',
            'type' => 'switcher',
            'title' => __('文章图片灯箱', 'kratos'),
            'subtitle' => __('文章正文内的图片点击放大', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_article_widgets',
            'type' => 'image_select',
            'title' => __('页面布局', 'kratos'),
            'subtitle' => __('仅文章页生效，区别在于用哪个侧边栏小工具区', 'kratos'),
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
                    'subtitle' => __('文章末尾显示版权协议声明', 'kratos'),
                    'text_on' => __('开启', 'kratos'),
                    'text_off' => __('关闭', 'kratos'),
                ),
                array(
                    'id' => 'g_cc',
                    'type' => 'select',
                    'title' => __('协议名称', 'kratos'),
                    'subtitle' => __('采用的 CC 协议', 'kratos'),
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
                    'subtitle' => __('评论数达到此值即标 HOT', 'kratos'),
                ),
                array(
                    'id' => 'g_article_love',
                    'type' => 'text',
                    'title' => __('点赞数', 'kratos'),
                    'subtitle' => __('点赞数达到此值即标 HOT', 'kratos'),
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
                    'subtitle' => __('文章末尾显示打赏区', 'kratos'),
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
    'parent' => 'kp_post',
    'title' => __('阅读增强', 'kratos'),
    'icon' => 'fas fa-book-reader',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('阅读进度条', 'kratos'),
        ),
        array(
            'id' => 'g_read_progress_enabled',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('文章页顶部随滚动增长的进度条', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_read_progress_color_mode',
            'type' => 'button_set',
            'title' => __('颜色方案', 'kratos'),
            'subtitle' => __('「跟随皮肤」取当前皮肤的强调色，随每日皮肤自动变化', 'kratos'),
            'options' => array(
                'skin'   => __('跟随皮肤', 'kratos'),
                'custom' => __('自定义', 'kratos'),
            ),
            'default' => 'skin',
            'dependency' => array('g_read_progress_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_read_progress_color',
            'type' => 'color',
            'title' => __('自定义颜色', 'kratos'),
            'subtitle' => __('自定义方案下生效，跟随皮肤时作为回退色', 'kratos'),
            'default' => '#0abbef',
            'dependency' => array('g_read_progress_enabled|g_read_progress_color_mode', '==|==', 'true|custom'),
        ),
        array(
            'id' => 'g_read_progress_height',
            'type' => 'number',
            'title' => __('进度条高度 (px)', 'kratos'),
            'min' => 1,
            'max' => 10,
            'default' => 3,
            'dependency' => array('g_read_progress_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('字数与预计阅读时间', 'kratos'),
        ),
        array(
            'id' => 'g_read_wordcount_enabled',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('标题下方显示「约 N 字 · N 分钟阅读」', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_read_wpm',
            'type' => 'number',
            'title' => __('阅读速度 (字/分钟)', 'kratos'),
            'subtitle' => __('用于估算时长，中文默认 300 字/分钟', 'kratos'),
            'min' => 50,
            'max' => 1000,
            'default' => 300,
            'dependency' => array('g_read_wordcount_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_read_wordcount_text',
            'type' => 'text',
            'title' => __('字数文案', 'kratos'),
            'subtitle' => __('占位符：%words% 字数', 'kratos'),
            'default' => __('约 %words% 字', 'kratos'),
            'dependency' => array('g_read_wordcount_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_read_time_text',
            'type' => 'text',
            'title' => __('阅读时间文案', 'kratos'),
            'subtitle' => __('占位符：%minutes% 分钟数', 'kratos'),
            'default' => __('%minutes% 分钟阅读', 'kratos'),
            'dependency' => array('g_read_wordcount_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('文章更新提示条', 'kratos'),
        ),
        array(
            'id' => 'g_read_update_notice_enabled',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('久未更新的文章在正文顶部显示提示条', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_read_update_notice_days',
            'type' => 'number',
            'title' => __('提示阈值 (天)', 'kratos'),
            'subtitle' => __('最后更新距今超过多少天才提示', 'kratos'),
            'min' => 1,
            'default' => 180,
            'dependency' => array('g_read_update_notice_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_read_update_notice_text',
            'type' => 'text',
            'title' => __('提示文案', 'kratos'),
            'subtitle' => __('占位符：%date% 更新日期、%days% 距今天数', 'kratos'),
            'default' => __('本文最后更新于 %date%，距今已 %days% 天，其中的信息可能已经发生变化，请注意甄别。', 'kratos'),
            'dependency' => array('g_read_update_notice_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('内链悬浮预览卡', 'kratos'),
        ),
        array(
            'id' => 'g_link_preview',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('悬停正文里的站内链接弹出预览卡：文章显示缩略图与摘要，归档显示描述与最近文章。触屏设备自动不启用', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_link_preview_delay',
            'type' => 'text',
            'title' => __('悬停触发延时(毫秒)', 'kratos'),
            'subtitle' => __('停留多久才弹卡，避免划过就触发。最小 80，默认 320', 'kratos'),
            'default' => '320',
            'dependency' => array('g_link_preview', '==', 'true'),
        ),
        array(
            'id' => 'g_link_preview_terms',
            'type' => 'switcher',
            'title' => __('预览分类 / 标签 / 系列归档', 'kratos'),
            'subtitle' => __('归档链接也弹卡。「关键词自动内链」生成的正是这类链接，建议一起开', 'kratos'),
            'default' => true,
            'dependency' => array('g_link_preview', '==', 'true'),
        ),
        array(
            'id' => 'g_link_preview_term_posts',
            'type' => 'text',
            'title' => __('归档卡展示最近几篇', 'kratos'),
            'subtitle' => __('归档卡里列出最近几篇，填 0 只显示总数。默认 3', 'kratos'),
            'default' => '3',
            'dependency' => array('g_link_preview|g_link_preview_terms', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_link_preview_term_max',
            'type' => 'text',
            'title' => __('归档映射表容量', 'kratos'),
            'subtitle' => __('主题自建的「链接 → 归档」映射表容量上限，默认 3000，一般无需改', 'kratos'),
            'default' => '3000',
            'dependency' => array('g_link_preview|g_link_preview_terms', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_link_preview_excerpt',
            'type' => 'text',
            'title' => __('摘要字数', 'kratos'),
            'subtitle' => __('卡片摘要的字数上限，默认 110', 'kratos'),
            'default' => '110',
            'dependency' => array('g_link_preview', '==', 'true'),
        ),
        array(
            'id' => 'g_link_preview_wpm',
            'type' => 'text',
            'title' => __('阅读速度(字/分钟)', 'kratos'),
            'subtitle' => __('用于算卡片上的阅读时长，默认 400', 'kratos'),
            'default' => '400',
            'dependency' => array('g_link_preview', '==', 'true'),
        ),
        array(
            'id' => 'g_link_preview_cache_min',
            'type' => 'text',
            'title' => __('预览缓存时长(分钟)', 'kratos'),
            'subtitle' => __('预览数据缓存多久，文章保存时自动失效。最小 5，默认 360', 'kratos'),
            'default' => '360',
            'dependency' => array('g_link_preview', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('相关文章推荐', 'kratos'),
        ),
        array(
            'id' => 'g_read_related_enabled',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('文章末尾按标签/分类推荐相关文章', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_read_related_title',
            'type' => 'text',
            'title' => __('区块标题', 'kratos'),
            'default' => __('相关文章', 'kratos'),
            'dependency' => array('g_read_related_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_read_related_style',
            'type' => 'radio',
            'title' => __('展示样式', 'kratos'),
            'subtitle' => __('grid 图上文下、list 左图右文、compact 无图单行', 'kratos'),
            'options' => array(
                'grid'    => __('网格卡片（默认）', 'kratos'),
                'list'    => __('横向列表', 'kratos'),
                'compact' => __('极简列表', 'kratos'),
            ),
            'default' => 'grid',
            'dependency' => array('g_read_related_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_read_related_thumb',
            'type' => 'switcher',
            'title' => __('展示特色图片', 'kratos'),
            'subtitle' => __('grid / list 下是否显示特色图，compact 恒无图', 'kratos'),
            'default' => true,
            'dependency' => array('g_read_related_enabled|g_read_related_style', '==|any', 'true|grid,list'),
        ),
        array(
            'id' => 'g_read_related_limit',
            'type' => 'number',
            'title' => __('展示数量', 'kratos'),
            'subtitle' => __('最多推荐几篇', 'kratos'),
            'min' => 2,
            'max' => 12,
            'default' => 6,
            'dependency' => array('g_read_related_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('LQIP 模糊占位（图片加载态渐显）', 'kratos'),
        ),
        array(
            'id' => 'g_lqip_enabled',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('图片加载前显示模糊占位、加载后淡入。已有图片需在「工具 → LQIP 回填」里补一次。', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_lqip_width',
            'type' => 'number',
            'title' => __('占位图宽度 (px)', 'kratos'),
            'subtitle' => __('越大越清晰、体积也越大，推荐 20~32。', 'kratos'),
            'min' => 8,
            'max' => 64,
            'default' => 24,
            'dependency' => array('g_lqip_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_lqip_transition',
            'type' => 'number',
            'title' => __('淡入时长 (ms)', 'kratos'),
            'min' => 100,
            'max' => 2000,
            'default' => 400,
            'dependency' => array('g_lqip_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('系列文章（连载教程 · 上下篇串联）', 'kratos'),
        ),
        array(
            'id' => 'g_series_enabled',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('在「文章 → 系列」里管理，文章顶部显示所属系列', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_series_title_tpl',
            'type' => 'text',
            'title' => __('系列标题文案', 'kratos'),
            'subtitle' => __('占位符：%series% 系列名', 'kratos'),
            'default' => __('系列：%series%', 'kratos'),
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_position_tpl',
            'type' => 'text',
            'title' => __('位置提示文案', 'kratos'),
            'subtitle' => __('占位符：%index% 第几篇、%total% 总篇数', 'kratos'),
            'default' => __('第 %index% 篇 / 共 %total% 篇', 'kratos'),
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_default_open',
            'type' => 'switcher',
            'title' => __('默认展开列表', 'kratos'),
            'subtitle' => __('关闭则默认收起，只显示系列名与位置', 'kratos'),
            'default' => false,
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_prev_text',
            'type' => 'text',
            'title' => __('系列上一篇文案', 'kratos'),
            'subtitle' => __('留空则用默认「&lt; 系列上一篇」', 'kratos'),
            'default' => __('&lt; 系列上一篇', 'kratos'),
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_next_text',
            'type' => 'text',
            'title' => __('系列下一篇文案', 'kratos'),
            'subtitle' => __('留空则用默认「系列下一篇 &gt;」', 'kratos'),
            'default' => __('系列下一篇 &gt;', 'kratos'),
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_replace_navi',
            'type' => 'switcher',
            'title' => __('替换默认上下篇导航', 'kratos'),
            'subtitle' => __('系列文章底部隐藏 WordPress 自带的上下篇，避免与系列内跳转重复', 'kratos'),
            'default' => true,
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_slug',
            'type' => 'text',
            'title' => __('系列 URL 前缀', 'kratos'),
            'subtitle' => __('归档地址为 /<前缀>/<slug>/，只能用小写字母、数字、连字符；留空为 series', 'kratos'),
            'default' => 'series',
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_max_depth',
            'type' => 'number',
            'title' => __('系列最大层级', 'kratos'),
            'subtitle' => __('最多几级（顶层算 1，建议 3）；列表与归档页按此层级展开子系列。', 'kratos'),
            'help' => __('保存后：若已有系列的父级层级超出该限制，编辑/新增 term 时会自动上提到允许的最深祖先，并在后台顶部提示；已渲染的层级过深内容会被裁剪。层级越深卡片越紧凑，超过 5 级已不适合卡片布局。', 'kratos'),
            'min' => 1,
            'max' => 5,
            'default' => 3,
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_intro',
            'type' => 'wp_editor',
            'title' => __('系列开头文字', 'kratos'),
            'subtitle' => __('显示在系列盒子之后、正文之前；支持富文本与短码', 'kratos'),
            'help' => __('可用占位符：%series%（系列名）、%index%（本篇序号）、%total%（系列总篇数）、%title%（文章标题）、%date%（发布日期）、%series_link%（系列归档地址）。单个系列可在「文章 → 系列 → 编辑」里替换 / 追加 / 关闭；单篇可在编辑页右侧「系列文章排序」面板关闭。', 'kratos'),
            'height' => '180px',
            'default' => '',
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_intro_scope',
            'type' => 'select',
            'title' => __('开头文字显示范围', 'kratos'),
            'options' => array(
                'all'   => __('系列内每篇都显示', 'kratos'),
                'first' => __('仅系列第一篇显示', 'kratos'),
                'off'   => __('不显示', 'kratos'),
            ),
            'default' => 'all',
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_intro_head_on',
            'type' => 'switcher',
            'title' => __('开头文字带标题头', 'kratos'),
            'subtitle' => __('在富文本上方加一行带图标的小标题', 'kratos'),
            'default' => false,
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_intro_head_text',
            'type' => 'text',
            'title' => __('开头标题头文案', 'kratos'),
            'subtitle' => __('纯文本，支持上面那组占位符', 'kratos'),
            'default' => '本系列导读',
            'dependency' => array('g_series_enabled|g_series_intro_head_on', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_series_intro_head_icon',
            'type' => 'icon',
            'title' => __('开头标题头图标', 'kratos'),
            'subtitle' => __('留空则不显示图标', 'kratos'),
            'default' => 'fas fa-book-open',
            'dependency' => array('g_series_enabled|g_series_intro_head_on', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_series_outro',
            'type' => 'wp_editor',
            'title' => __('系列结尾文字', 'kratos'),
            'subtitle' => __('显示在正文末尾（版权声明之前）；支持富文本与短码', 'kratos'),
            'height' => '180px',
            'default' => '',
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_outro_scope',
            'type' => 'select',
            'title' => __('结尾文字显示范围', 'kratos'),
            'options' => array(
                'all'  => __('系列内每篇都显示', 'kratos'),
                'last' => __('仅系列最后一篇显示', 'kratos'),
                'off'  => __('不显示', 'kratos'),
            ),
            'default' => 'all',
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_outro_head_on',
            'type' => 'switcher',
            'title' => __('结尾文字带标题头', 'kratos'),
            'default' => false,
            'dependency' => array('g_series_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_series_outro_head_text',
            'type' => 'text',
            'title' => __('结尾标题头文案', 'kratos'),
            'subtitle' => __('纯文本，支持上面那组占位符', 'kratos'),
            'default' => '继续阅读本系列',
            'dependency' => array('g_series_enabled|g_series_outro_head_on', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_series_outro_head_icon',
            'type' => 'icon',
            'title' => __('结尾标题头图标', 'kratos'),
            'subtitle' => __('留空则不显示图标', 'kratos'),
            'default' => 'fas fa-list-ol',
            'dependency' => array('g_series_enabled|g_series_outro_head_on', '==|==', 'true|true'),
        ),
        array(
            'type'    => 'content',
            'content' => '<div style="padding:10px 14px;background:#f6f9fc;border-left:3px solid #336699;border-radius:3px;line-height:1.7;font-size:13px;">'
                . '<strong>' . __('短码 [kratos_series_list]', 'kratos') . '</strong><br>'
                . __('在任意页面 / 文章正文中插入短码，可渲染<strong>所有系列的树形卡片列表</strong>，含图标、描述、文章数，父子系列自动缩进。', 'kratos') . '<br><br>'
                . '<strong>' . __('基本用法：', 'kratos') . '</strong><br>'
                . '<code>[kratos_series_list]</code><br><br>'
                . '<strong>' . __('可选参数：', 'kratos') . '</strong>'
                . '<ul style="margin:6px 0 0 18px;padding:0;">'
                . '<li><code>parent</code> — ' . __('起点父级 term_id（默认 0，表示从顶层开始）', 'kratos') . '</li>'
                . '<li><code>depth</code> — ' . __('展开层级深度（默认 3）', 'kratos') . '</li>'
                . '<li><code>hide_empty</code> — ' . __('是否隐藏无文章的系列，yes / no（默认 no）', 'kratos') . '</li>'
                . '</ul>'
                . '<strong>' . __('示例：', 'kratos') . '</strong><br>'
                . '<code>[kratos_series_list depth="2" hide_empty="yes"]</code><br>'
                . '<span style="color:#888;">' . __('仅展开 2 层，隐藏空系列', 'kratos') . '</span><br><br>'
                . '<strong>' . __('推荐用法：', 'kratos') . '</strong>'
                . __('新建一个页面（可选「Kratos-plus 特色标题」模板配头图），正文粘贴短码，即得「所有系列」总览页；再把该页加入菜单即可。', 'kratos')
                . '</div>',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_post',
    'title' => __('代码高亮', 'kratos'),
    'icon' => 'fas fa-code',
    'fields' => array(
        array(
            'id' => 'g_codehl',
            'type' => 'switcher',
            'title' => __('代码高亮', 'kratos'),
            'subtitle' => __('正文代码块着色', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_codehl_engine',
            'type' => 'select',
            'title' => __('高亮方案', 'kratos'),
            'subtitle' => __('Prism.js / highlight.js 走前端，highlight.php 在服务端渲染', 'kratos'),
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
            'subtitle' => __('CDN 更快；切到本地会一次性下载全部语言与主题（约 2MB），无需改服务器配置', 'kratos'),
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
            'subtitle' => __('npm 风格 CDN 根地址，留空用 jsDelivr；可换 unpkg 或国内镜像', 'kratos'),
            'default' => 'https://cdn.jsdelivr.net/npm',
            'dependency' => array('g_codehl|g_codehl_source', '==|==', 'true|cdn'),
        ),
        array(
            'id' => 'g_codehl_theme_prism',
            'type' => 'select',
            'title' => __('Prism 主题', 'kratos'),
            'subtitle' => __('官方主题 + prism-themes 社区扩展，共 45 款', 'kratos'),
            'options' => kratos_codehl_prism_options(),
            'default' => 'core/prism-tomorrow',
            'dependency' => array('g_codehl|g_codehl_engine', '==|==', 'true|prism'),
        ),
        array(
            'id' => 'g_codehl_theme_hljs',
            'type' => 'select',
            'title' => __('highlight 主题', 'kratos'),
            'subtitle' => __('官方主题 73 款；highlight.js 与 highlight.php 共用', 'kratos'),
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
            'subtitle' => __('仅 Prism.js 与 highlight.js 生效', 'kratos'),
            'default' => false,
            'dependency' => array('g_codehl|g_codehl_engine', '==|any', 'true|prism,hljs'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_post',
    'title' => __('关键词自动内链', 'kratos'),
    'icon' => 'fas fa-link',
    'fields' => array(
        array(
            'type' => 'content',
            'content' => '<div style="line-height:1.8;">'
                . __('在文章正文中扫描站点已有的<strong>标签 / 分类</strong>名称，命中则自动替换为对应归档页链接。渲染结果按 <code>文章 + 修改时间 + terms 版本</code> 缓存到 object cache，标签/分类增删改会自动整体失效。', 'kratos')
                . '<br>'
                . __('仅在单篇文章正文（<code>the_content</code>）中生效，列表页摘要 / 归档页 / RSS 不受影响。', 'kratos')
                . '</div>',
        ),
        array(
            'id' => 'g_autolink_enabled',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('正文中的关键词自动变成站内链接', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_autolink_include_tag',
            'type' => 'switcher',
            'title' => __('包含标签', 'kratos'),
            'subtitle' => __('把标签名当作关键词', 'kratos'),
            'default' => true,
            'dependency' => array('g_autolink_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_autolink_include_cat',
            'type' => 'switcher',
            'title' => __('包含分类', 'kratos'),
            'subtitle' => __('把分类名当作关键词', 'kratos'),
            'default' => true,
            'dependency' => array('g_autolink_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_autolink_min_length',
            'type' => 'number',
            'title' => __('关键词最小长度', 'kratos'),
            'subtitle' => __('短于此长度的关键词不匹配', 'kratos'),
            'min' => 1,
            'max' => 20,
            'default' => 2,
            'dependency' => array('g_autolink_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_autolink_max_per_kw',
            'type' => 'number',
            'title' => __('每关键词最大替换次数', 'kratos'),
            'subtitle' => __('同一关键词在一篇内最多替换几次，推荐 1', 'kratos'),
            'min' => 1,
            'max' => 10,
            'default' => 1,
            'dependency' => array('g_autolink_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_autolink_max_total',
            'type' => 'number',
            'title' => __('每篇最大链接总数', 'kratos'),
            'subtitle' => __('单篇内自动内链总数上限，避免链接海', 'kratos'),
            'min' => 1,
            'max' => 50,
            'default' => 6,
            'dependency' => array('g_autolink_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_autolink_new_window',
            'type' => 'switcher',
            'title' => __('新窗口打开', 'kratos'),
            'subtitle' => __('是否在新标签页打开', 'kratos'),
            'default' => false,
            'dependency' => array('g_autolink_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_autolink_nofollow',
            'type' => 'switcher',
            'title' => __('添加 nofollow', 'kratos'),
            'subtitle' => __('给自动内链加 rel="nofollow"', 'kratos'),
            'default' => false,
            'dependency' => array('g_autolink_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_autolink_exclude_ids',
            'type' => 'text',
            'title' => __('排除的 term ID', 'kratos'),
            'subtitle' => __('排除的标签/分类 ID，用逗号或空格分隔', 'kratos'),
            'default' => '',
            'dependency' => array('g_autolink_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_autolink_custom_map',
            'type' => 'textarea',
            'title' => __('自定义关键词映射', 'kratos'),
            'subtitle' => __('每行一条 <code>关键词 =&gt; URL</code>，用于标签分类之外的关键词', 'kratos'),
            'default' => '',
            'dependency' => array('g_autolink_enabled', '==', 'true'),
        ),
        array(
            'type' => 'content',
            'content' => '<div style="line-height:1.8;color:#666;">'
                . '<strong>' . __('小贴士：', 'kratos') . '</strong>'
                . '<ul style="margin:6px 0 0 18px;padding:0;">'
                . '<li>' . __('可以在标签/分类的<strong>描述</strong>里写 <code>别名: 关键词1, 关键词2</code>，这些别名也会参与匹配', 'kratos') . '</li>'
                . '<li>' . __('长关键词优先匹配（例如"机器学习"优先于"学习"）', 'kratos') . '</li>'
                . '<li>' . __('当前文章自身所属的标签/分类不会被自链', 'kratos') . '</li>'
                . '<li>' . __('位于 <code>&lt;a&gt; / &lt;code&gt; / &lt;pre&gt; / &lt;h1-h6&gt;</code> 内的文字不会被替换', 'kratos') . '</li>'
                . '</ul>'
                . '</div>',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_post',
    'title' => __('文章广告', 'kratos'),
    'icon' => 'fas fa-ad',
    'fields' => array(
        array(
            'id' => 'single_ad_top_group',
            'type' => 'group',
            'title' => '文章顶部广告',
            'subtitle' => '点击添加广告，最多添加 3 个顶部广告；无需展示时可全部删除',
            'min' => 0,
            'max' => 3,
            'fields' => kratos_single_ad_fields(),
        ),
        array(
            'id' => 'single_ad_bottom_group',
            'type' => 'group',
            'title' => '文章底部广告',
            'subtitle' => '点击添加广告，最多添加 3 个底部广告；无需展示时可全部删除',
            'min' => 0,
            'max' => 3,
            'fields' => kratos_single_ad_fields(),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'comment_fields',
    'title' => __('评论互动', 'kratos'),
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
            'subtitle' => __('每条评论后附上访客的浏览器 / 系统 / 归属地', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_comment_info_display',
            'type' => 'checkbox',
            'title' => __('显示项', 'kratos'),
            'subtitle' => __('展示哪几项', 'kratos'),
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
            'id' => 'g_comment_placeholder',
            'type' => 'text',
            'title' => __('评论框提示词', 'kratos'),
            'subtitle' => __('评论输入框内的提示文字', 'kratos'),
            'default' => __('说点什么吧…', 'kratos'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('用户等级', 'kratos'),
        ),
        array(
            'id' => 'g_comment_rank_enabled',
            'type' => 'switcher',
            'title' => __('显示用户等级', 'kratos'),
            'subtitle' => __('按评论数给作者挂等级头衔，前后台都生效', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_comment_rank_levels',
            'type' => 'group',
            'title' => __('等级配置', 'kratos'),
            'subtitle' => __('按门槛从低到高添加，取不超过其评论数的最高一级', 'kratos'),
            'button_title' => __('添加等级', 'kratos'),
            'accordion_title_prefix' => __('等级', 'kratos'),
            'accordion_title_number' => true,
            'fields' => array(
                array(
                    'id' => 'threshold',
                    'type' => 'number',
                    'title' => __('评论数门槛', 'kratos'),
                    'subtitle' => __('达到多少条评论解锁', 'kratos'),
                    'min' => 0,
                    'default' => 0,
                ),
                array(
                    'id' => 'title',
                    'type' => 'text',
                    'title' => __('头衔', 'kratos'),
                    'subtitle' => __('作者名后显示的称号', 'kratos'),
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
            'id' => 'g_comment_admin_badge_enabled',
            'type' => 'switcher',
            'title' => __('显示管理员标签', 'kratos'),
            'subtitle' => __('管理员评论时改挂专属徽章', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
            'dependency' => array('g_comment_rank_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_admin_badge_text',
            'type' => 'text',
            'title' => __('管理员徽章文字', 'kratos'),
            'subtitle' => __('徽章文字，建议 1~4 个字', 'kratos'),
            'default' => __('管理', 'kratos'),
            'dependency' => array('g_comment_rank_enabled|g_comment_admin_badge_enabled', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_comment_admin_badge_color',
            'type' => 'color',
            'title' => __('管理员徽章文字颜色', 'kratos'),
            'default' => '#ffffff',
            'dependency' => array('g_comment_rank_enabled|g_comment_admin_badge_enabled', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_comment_admin_badge_bg',
            'type' => 'color',
            'title' => __('管理员徽章背景颜色', 'kratos'),
            'default' => '#e74c3c',
            'dependency' => array('g_comment_rank_enabled|g_comment_admin_badge_enabled', '==|==', 'true|true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('蜜罐反机器人', 'kratos'),
        ),
        array(
            'id' => 'g_comment_honeypot_enabled',
            'type' => 'switcher',
            'title' => __('启用蜜罐', 'kratos'),
            'subtitle' => __('用隐藏诱饵字段 + 提交耗时拦机器人，真人无感', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_comment_honeypot_min_seconds',
            'type' => 'number',
            'title' => __('最短提交时间（秒）', 'kratos'),
            'subtitle' => __('从打开页面到提交低于此秒数即判为机器人，默认 3', 'kratos'),
            'min' => 1,
            'max' => 60,
            'default' => 3,
            'dependency' => array('g_comment_honeypot_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('评论赞踩', 'kratos'),
        ),
        array(
            'id' => 'g_comment_reactions_enabled',
            'type' => 'switcher',
            'title' => __('启用赞踩', 'kratos'),
            'subtitle' => __('访客可给评论点赞/踩，用 Cookie 防重复', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_comment_reactions_like_icon',
            'type' => 'icon',
            'title' => __('赞图标', 'kratos'),
            'subtitle' => __('点「添加图标」从 Font Awesome 里选', 'kratos'),
            'default' => 'fas fa-thumbs-up',
            'dependency' => array('g_comment_reactions_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_reactions_dislike_icon',
            'type' => 'icon',
            'title' => __('踩图标', 'kratos'),
            'subtitle' => __('从 Font Awesome 图标库选择', 'kratos'),
            'default' => 'fas fa-thumbs-down',
            'dependency' => array('g_comment_reactions_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_reactions_like_text',
            'type' => 'text',
            'title' => __('赞按钮提示文字', 'kratos'),
            'default' => __('赞', 'kratos'),
            'dependency' => array('g_comment_reactions_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_reactions_dislike_text',
            'type' => 'text',
            'title' => __('踩按钮提示文字', 'kratos'),
            'default' => __('踩', 'kratos'),
            'dependency' => array('g_comment_reactions_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_reactions_like_color',
            'type' => 'color',
            'title' => __('赞图标激活色', 'kratos'),
            'default' => '#e74c3c',
            'dependency' => array('g_comment_reactions_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_reactions_dislike_color',
            'type' => 'color',
            'title' => __('踩图标激活色', 'kratos'),
            'default' => '#7f8c8d',
            'dependency' => array('g_comment_reactions_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('热门评论', 'kratos'),
        ),
        array(
            'id' => 'g_comment_hot_enabled',
            'type' => 'switcher',
            'title' => __('启用热门评论', 'kratos'),
            'subtitle' => __('评论区顶部置顶本文点赞最高的几条', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_comment_hot_threshold',
            'type' => 'number',
            'title' => __('最少点赞数', 'kratos'),
            'subtitle' => __('点赞数达到多少才算热门', 'kratos'),
            'min' => 1,
            'default' => 5,
            'dependency' => array('g_comment_hot_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_hot_limit',
            'type' => 'number',
            'title' => __('展示条数', 'kratos'),
            'subtitle' => __('最多显示几条', 'kratos'),
            'min' => 1,
            'max' => 20,
            'default' => 3,
            'dependency' => array('g_comment_hot_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_reply_collapse',
            'type' => 'number',
            'title' => __('回复折叠阈值', 'kratos'),
            'subtitle' => __('一条评论下超过多少条回复就折叠，0 为从不折叠', 'kratos'),
            'min' => 0,
            'max' => 50,
            'default' => 5,
        ),
        array(
            'type' => 'subheading',
            'content' => __('评论验证码', 'kratos'),
        ),
        array(
            'id' => 'g_comment_captcha',
            'type' => 'switcher',
            'title' => __('功能开关', 'kratos'),
            'subtitle' => __('提交前要求算一道加减题', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_comment_captcha_max',
            'type' => 'number',
            'title' => __('数字最大值', 'kratos'),
            'subtitle' => __('随机数上限，默认 10', 'kratos'),
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
            'subtitle' => __('评论者网址在友链列表里时，给他挂「友链」徽章（只比对域名）', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_comment_blogroll_badge_text',
            'type' => 'text',
            'title' => __('徽章文字', 'kratos'),
            'subtitle' => __('徽章文字，建议 1~4 个字', 'kratos'),
            'default' => __('友链', 'kratos'),
            'dependency' => array('g_comment_blogroll_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_blogroll_badge_color',
            'type' => 'color',
            'title' => __('徽章文字颜色', 'kratos'),
            'subtitle' => __('文字与图标颜色', 'kratos'),
            'default' => '#ffffff',
            'dependency' => array('g_comment_blogroll_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_blogroll_badge_bg_start',
            'type' => 'color',
            'title' => __('徽章渐变起始色', 'kratos'),
            'subtitle' => __('背景渐变起始色', 'kratos'),
            'default' => '#38bdf8',
            'dependency' => array('g_comment_blogroll_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_comment_blogroll_badge_bg_end',
            'type' => 'color',
            'title' => __('徽章渐变结束色', 'kratos'),
            'subtitle' => __('背景渐变结束色', 'kratos'),
            'default' => '#6366f1',
            'dependency' => array('g_comment_blogroll_enabled', '==', 'true'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'comment_fields',
    'title' => __('走心评论', 'kratos'),
    'icon' => 'fas fa-heart',
    'fields' => array(
        array(
            'id' => 'g_comment_heart_badge_text',
            'type' => 'text',
            'title' => __('徽章文字', 'kratos'),
            'subtitle' => __('徽章文字，建议 1~4 个字', 'kratos'),
            'default' => __('走心', 'kratos'),
        ),
        array(
            'id' => 'g_comment_heart_badge_color',
            'type' => 'color',
            'title' => __('徽章文字颜色', 'kratos'),
            'subtitle' => __('文字与图标颜色', 'kratos'),
            'default' => '#ffffff',
        ),
        array(
            'id' => 'g_comment_heart_badge_bg_start',
            'type' => 'color',
            'title' => __('徽章渐变起始色', 'kratos'),
            'subtitle' => __('背景渐变起始色', 'kratos'),
            'default' => '#ff6b8b',
        ),
        array(
            'id' => 'g_comment_heart_badge_bg_end',
            'type' => 'color',
            'title' => __('徽章渐变结束色', 'kratos'),
            'subtitle' => __('背景渐变结束色', 'kratos'),
            'default' => '#ff8e53',
        ),
        array(
            'id' => 'g_comment_heart_sc_title',
            'type' => 'text',
            'title' => __('短码默认标题', 'kratos'),
            'subtitle' => __('[heart_comments] 未传 title 时用它；留空则不显示标题', 'kratos'),
            'default' => __('走心评论', 'kratos'),
        ),
        array(
            'id' => 'g_comment_heart_sc_subtitle',
            'type' => 'text',
            'title' => __('短码默认副标题', 'kratos'),
            'subtitle' => __('[heart_comments] 未传 subtitle 时用它；留空则不显示副标题', 'kratos'),
            'default' => __('那些温暖过我的留言，每一条都值得被看见 ❤', 'kratos'),
        ),
        array(
            'id' => 'g_comment_heart_sc_per_page',
            'type' => 'number',
            'title' => __('短码每页条数', 'kratos'),
            'subtitle' => __('每页条数，短码可用 per_page 覆盖；填 0 不分页', 'kratos'),
            'min' => 0,
            'max' => 1000,
            'default' => 100,
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
    'title' => __('评论地域分布', 'kratos'),
    'icon' => 'fas fa-map-marked-alt',
    'fields' => array(
        array(
            'id' => 'g_comment_geo_notice',
            'type' => 'content',
            'content' => '<div style="padding:12px 14px;background:#f7f3ea;border:1px solid #e3d9c4;border-radius:8px;line-height:1.8;">'
                . '<p style="margin:0 0 6px;"><strong>' . __('短码：', 'kratos') . '</strong><code>[comment_geo]</code></p>'
                . '<p style="margin:0 0 6px;">' . __('归属地解析完全走主题内置的 ip2region 离线库（「评论配置 → 通用配置」里可下载/更新），不请求任何外部 API。', 'kratos') . '</p>'
                . '<p style="margin:0;color:#8a6a5d;">' . __('💡 统计结果按 IP 聚合后缓存，新评论通过审核时自动失效重算。', 'kratos') . '</p>'
                . '</div>',
        ),
        array(
            'id' => 'g_comment_geo_title',
            'type' => 'text',
            'title' => __('短码默认标题', 'kratos'),
            'default' => __('评论者地域分布', 'kratos'),
        ),
        array(
            'id' => 'g_comment_geo_subtitle',
            'type' => 'text',
            'title' => __('短码默认副标题', 'kratos'),
            'default' => __('看看这些留言是从哪些地方寄来的 🗺', 'kratos'),
        ),
        array(
            'id' => 'g_comment_geo_regions_max',
            'type' => 'text',
            'title' => __('省份榜条数', 'kratos'),
            'subtitle' => __('只统计中国大陆及港澳台省级行政区，填 0 不展示', 'kratos'),
            'default' => '12',
        ),
        array(
            'id' => 'g_comment_geo_countries_max',
            'type' => 'text',
            'title' => __('国家/地区榜条数', 'kratos'),
            'subtitle' => __('填 0 不展示', 'kratos'),
            'default' => '8',
        ),
        array(
            'id' => 'g_comment_geo_cities_max',
            'type' => 'text',
            'title' => __('城市榜条数', 'kratos'),
            'subtitle' => __('默认 0 不展示。城市粒度受 IP 库精度限制，样本少时参考价值有限', 'kratos'),
            'default' => '0',
        ),
        array(
            'id' => 'g_comment_geo_ip_max',
            'type' => 'text',
            'title' => __('参与统计的唯一 IP 上限', 'kratos'),
            'subtitle' => __('取评论最多的前 N 个 IP 解析，越大越准但重算越慢。默认 2000', 'kratos'),
            'default' => '2000',
        ),
        array(
            'id' => 'g_comment_geo_cache_min',
            'type' => 'text',
            'title' => __('缓存时长(分钟)', 'kratos'),
            'subtitle' => __('统计结果缓存多久，最小 5 分钟，默认 720', 'kratos'),
            'default' => '720',
        ),
        array(
            'id' => 'g_comment_geo_show_updated',
            'type' => 'switcher',
            'title' => __('展示统计更新时间', 'kratos'),
            'default' => true,
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'comment_fields',
    'title' => __('评论排行榜', 'kratos'),
    'icon' => 'fas fa-trophy',
    'fields' => array(
        array(
            'id' => 'g_comment_top_sc_title',
            'type' => 'text',
            'title' => __('短码默认标题', 'kratos'),
            'subtitle' => __('[top_commenters] 未传 title 时用它；留空则不显示标题', 'kratos'),
            'default' => __('评论排行榜', 'kratos'),
        ),
        array(
            'id' => 'g_comment_top_sc_subtitle',
            'type' => 'text',
            'title' => __('短码默认副标题', 'kratos'),
            'subtitle' => __('[top_commenters] 未传 subtitle 时用它；留空则不显示副标题', 'kratos'),
            'default' => __('感谢每一位活跃的朋友，你们的留言让这里更热闹 🎉', 'kratos'),
        ),
        array(
            'id' => 'g_comment_top_sc_limit',
            'type' => 'number',
            'title' => __('展示数量', 'kratos'),
            'subtitle' => __('上榜人数，短码可用 limit 覆盖', 'kratos'),
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
            'subtitle' => __('IP 库更新不频繁，每月一次即可', 'kratos'),
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
            'subtitle' => __('多下一份 IPv6 库（约 36MB），移动网络访客多时才需要', 'kratos'),
            'default' => false,
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'kp_pages',
    'title' => __('特色页面', 'kratos'),
    'icon' => 'fas fa-star',
));

CSF::createSection($prefix, array(
    'parent' => 'kp_pages',
    'title' => __('特色首页', 'kratos'),
    'icon' => 'fas fa-home',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('杂志式首页。新建页面选「特色首页」模板，再在「设置 → 阅读」里设为主页。模块可拖拽排序，标题 / 副标题 / 图标留空即隐藏。', 'kratos'),
        ),
        array(
            'id' => 'hf_enabled',
            'type' => 'switcher',
            'title' => __('启用特色首页', 'kratos'),
            'subtitle' => __('关闭后 [home_featured] 不输出，模板页只显示正文', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'hf_modules',
            'type' => 'sorter',
            'title' => __('模块编排', 'kratos'),
            'subtitle' => __('拖拽排序，拖到「已停用」即隐藏；热门榜与最新文章相邻时自动并成双栏', 'kratos'),
            'enabled_title' => __('已启用', 'kratos'),
            'disabled_title' => __('已停用', 'kratos'),
            'default' => array(
                'enabled' => array(
                    'hero'      => __('焦点区', 'kratos'),
                    'recommend' => __('推荐位', 'kratos'),
                    'category'  => __('分类专区', 'kratos'),
                    'hot'       => __('热门榜', 'kratos'),
                    'latest'    => __('最新文章', 'kratos'),
                    'comment'   => __('最近评论', 'kratos'),
                    'stat'      => __('数据条', 'kratos'),
                ),
                'disabled' => array(),
            ),
            'dependency' => array('hf_enabled', '==', 'true'),
        ),
        array(
            'id' => 'hf_sidebar',
            'type' => 'switcher',
            'title' => __('显示侧边栏', 'kratos'),
            'subtitle' => __('建议关闭：模块自带双栏/网格，再加侧栏会把焦点大图压窄。开启后用「页面侧边栏」小工具区', 'kratos'),
            'text_on' => __('显示', 'kratos'),
            'text_off' => __('全宽', 'kratos'),
            'default' => false,
            'dependency' => array('hf_enabled', '==', 'true'),
        ),
        array(
            'id' => 'hf_cache_minutes',
            'type' => 'number',
            'title' => __('缓存时长（分钟）', 'kratos'),
            'subtitle' => __('模块 HTML 缓存多久，0 为不缓存；内容变动时自动失效，登录用户始终看实时', 'kratos'),
            'min' => 0,
            'default' => 10,
            'dependency' => array('hf_enabled', '==', 'true'),
        ),

        array(
            'type' => 'subheading',
            'content' => __('模块一 · 焦点区', 'kratos'),
        ),
        array(
            'id' => 'hf_hero_title',
            'type' => 'text',
            'title' => __('标题', 'kratos'),
            'subtitle' => __('留空则大图直接开场，推荐留空', 'kratos'),
            'default' => '',
        ),
        array(
            'id' => 'hf_hero_sub',
            'type' => 'text',
            'title' => __('副标题', 'kratos'),
            'default' => '',
        ),
        array(
            'id' => 'hf_hero_icon',
            'type' => 'icon',
            'title' => __('图标', 'kratos'),
            'subtitle' => __('标题左侧图标胶囊里的图标', 'kratos'),
            'default' => 'fas fa-star',
        ),
        array(
            'id' => 'hf_hero_source',
            'type' => 'button_set',
            'title' => __('文章来源', 'kratos'),
            'subtitle' => __('置顶文章不够时自动用最新文章补齐', 'kratos'),
            'options' => array(
                'sticky' => __('置顶文章', 'kratos'),
                'latest' => __('最新文章', 'kratos'),
            ),
            'default' => 'sticky',
        ),
        array(
            'id' => 'hf_hero_side_count',
            'type' => 'number',
            'title' => __('右侧次推条数', 'kratos'),
            'subtitle' => __('大图右侧的小卡数量，0 为只留大图', 'kratos'),
            'min' => 0,
            'max' => 4,
            'default' => 2,
        ),

        array(
            'type' => 'subheading',
            'content' => __('模块二 · 推荐位', 'kratos'),
        ),
        array(
            'id' => 'hf_rec_title',
            'type' => 'text',
            'title' => __('标题', 'kratos'),
            'default' => __('编辑推荐', 'kratos'),
        ),
        array(
            'id' => 'hf_rec_sub',
            'type' => 'text',
            'title' => __('副标题', 'kratos'),
            'default' => __('值得一读的长文', 'kratos'),
        ),
        array(
            'id' => 'hf_rec_icon',
            'type' => 'icon',
            'title' => __('图标', 'kratos'),
            'default' => 'fas fa-thumbtack',
        ),
        array(
            'id' => 'hf_rec_source',
            'type' => 'button_set',
            'title' => __('文章来源', 'kratos'),
            'options' => array(
                'sticky' => __('置顶文章', 'kratos'),
                'cat'    => __('指定分类', 'kratos'),
                'tag'    => __('指定标签', 'kratos'),
                'ids'    => __('手选文章 ID', 'kratos'),
            ),
            'default' => 'sticky',
        ),
        array(
            'id' => 'hf_rec_cats',
            'type' => 'checkbox',
            'title' => __('推荐分类', 'kratos'),
            'subtitle' => __('取这些分类（含子分类）的最新文章；留空则不展示', 'kratos'),
            'options' => (function () {
                $out = array();
                $terms = function_exists('get_terms') ? get_terms(array(
                    'taxonomy'   => 'category',
                    'hide_empty' => false,
                )) : array();
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $t) {
                        $out[(int) $t->term_id] = $t->name;
                    }
                }
                return $out;
            })(),
            'inline' => true,
            'default' => array(),
            'dependency' => array('hf_rec_source', '==', 'cat'),
        ),
        array(
            'id' => 'hf_rec_tag',
            'type' => 'text',
            'title' => __('标签别名', 'kratos'),
            'subtitle' => __('填标签别名，如 featured；该标签下的最新文章进入推荐位', 'kratos'),
            'dependency' => array('hf_rec_source', '==', 'tag'),
        ),
        array(
            'id' => 'hf_rec_ids',
            'type' => 'text',
            'title' => __('文章 ID', 'kratos'),
            'subtitle' => __('逗号分隔，如 128,96,73，按填写顺序展示', 'kratos'),
            'dependency' => array('hf_rec_source', '==', 'ids'),
        ),
        array(
            'id' => 'hf_rec_count',
            'type' => 'number',
            'title' => __('展示条数', 'kratos'),
            'subtitle' => __('建议 3 或 6，与三列网格对齐', 'kratos'),
            'min' => 1,
            'default' => 3,
        ),
        array(
            'id' => 'hf_rec_more_url',
            'type' => 'text',
            'title' => __('「全部推荐」链接', 'kratos'),
            'subtitle' => __('留空则不显示按钮', 'kratos'),
            'default' => '',
        ),

        array(
            'type' => 'subheading',
            'content' => __('模块三 · 分类专区', 'kratos'),
        ),
        array(
            'id' => 'hf_cat_title',
            'type' => 'text',
            'title' => __('标题', 'kratos'),
            'default' => __('分类专区', 'kratos'),
        ),
        array(
            'id' => 'hf_cat_sub',
            'type' => 'text',
            'title' => __('副标题', 'kratos'),
            'default' => '',
        ),
        array(
            'id' => 'hf_cat_icon',
            'type' => 'icon',
            'title' => __('图标', 'kratos'),
            'default' => 'fas fa-layer-group',
        ),
        array(
            'id' => 'hf_cat_terms',
            'type' => 'checkbox',
            'title' => __('参与的分类', 'kratos'),
            'subtitle' => __('每个分类一个 tab；留空则自动取文章数最多的 3 个', 'kratos'),
            'options' => (function () {
                $out = array();
                $terms = function_exists('get_terms') ? get_terms(array(
                    'taxonomy'   => 'category',
                    'hide_empty' => false,
                )) : array();
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $t) {
                        $out[(int) $t->term_id] = $t->name;
                    }
                }
                return $out;
            })(),
            'inline' => true,
            'default' => array(),
        ),
        array(
            'id' => 'hf_cat_feature',
            'type' => 'switcher',
            'title' => __('展示分类特色文章', 'kratos'),
            'subtitle' => __('每个 tab 左侧放该分类最新一篇的大图与摘要，右侧为标题列表', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'hf_cat_count',
            'type' => 'number',
            'title' => __('每个分类列表条数', 'kratos'),
            'subtitle' => __('不含左侧特色文章', 'kratos'),
            'min' => 1,
            'default' => 5,
        ),

        array(
            'type' => 'subheading',
            'content' => __('模块四 · 热门榜', 'kratos'),
        ),
        array(
            'id' => 'hf_hot_title',
            'type' => 'text',
            'title' => __('标题', 'kratos'),
            'default' => __('热门榜', 'kratos'),
        ),
        array(
            'id' => 'hf_hot_sub',
            'type' => 'text',
            'title' => __('副标题', 'kratos'),
            'default' => __('近 30 天热度', 'kratos'),
        ),
        array(
            'id' => 'hf_hot_icon',
            'type' => 'icon',
            'title' => __('图标', 'kratos'),
            'default' => 'fas fa-fire',
        ),
        array(
            'id' => 'hf_hot_days',
            'type' => 'number',
            'title' => __('统计窗口（天）', 'kratos'),
            'subtitle' => __('只看最近 N 天发布的文章，0 为不限', 'kratos'),
            'min' => 0,
            'default' => 30,
        ),
        array(
            'id' => 'hf_hot_count',
            'type' => 'number',
            'title' => __('展示条数', 'kratos'),
            'min' => 1,
            'default' => 5,
        ),
        array(
            'id' => 'hf_hot_thumb',
            'type' => 'switcher',
            'title' => __('展示缩略图', 'kratos'),
            'subtitle' => __('每条标题右侧显示缩略图，无特色图时按默认规则回落', 'kratos'),
            'text_on' => __('展示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'hf_hot_more_url',
            'type' => 'text',
            'title' => __('「查看完整热榜」链接', 'kratos'),
            'subtitle' => __('留空则不显示按钮；可指向热力图或自建排行页', 'kratos'),
            'default' => '',
        ),

        array(
            'type' => 'subheading',
            'content' => __('模块五 · 最新文章', 'kratos'),
        ),
        array(
            'id' => 'hf_latest_title',
            'type' => 'text',
            'title' => __('标题', 'kratos'),
            'default' => __('最新文章', 'kratos'),
        ),
        array(
            'id' => 'hf_latest_sub',
            'type' => 'text',
            'title' => __('副标题', 'kratos'),
            'default' => __('刚刚更新', 'kratos'),
        ),
        array(
            'id' => 'hf_latest_icon',
            'type' => 'icon',
            'title' => __('图标', 'kratos'),
            'default' => 'fas fa-pen-nib',
        ),
        array(
            'id' => 'hf_latest_count',
            'type' => 'number',
            'title' => __('展示条数', 'kratos'),
            'min' => 1,
            'default' => 5,
        ),
        array(
            'id' => 'hf_latest_thumb',
            'type' => 'switcher',
            'title' => __('展示缩略图', 'kratos'),
            'subtitle' => __('关闭后只留标题与元信息，列表更紧凑', 'kratos'),
            'text_on' => __('展示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'hf_latest_more_url',
            'type' => 'text',
            'title' => __('「进入文章列表」链接', 'kratos'),
            'subtitle' => __('留空则指向「设置 → 阅读」里的文章页，未设置时指向首页', 'kratos'),
            'default' => '',
        ),

        array(
            'type' => 'subheading',
            'content' => __('模块六 · 最近评论', 'kratos'),
        ),
        array(
            'id' => 'hf_cmt_title',
            'type' => 'text',
            'title' => __('标题', 'kratos'),
            'default' => __('最近评论', 'kratos'),
        ),
        array(
            'id' => 'hf_cmt_sub',
            'type' => 'text',
            'title' => __('副标题', 'kratos'),
            'default' => __('大家正在聊', 'kratos'),
        ),
        array(
            'id' => 'hf_cmt_icon',
            'type' => 'icon',
            'title' => __('图标', 'kratos'),
            'default' => 'fas fa-comment-dots',
        ),
        array(
            'id' => 'hf_cmt_count',
            'type' => 'number',
            'title' => __('展示条数', 'kratos'),
            'subtitle' => __('填列数的整数倍，末行不留空位', 'kratos'),
            'min' => 1,
            'default' => 6,
        ),
        array(
            'id' => 'hf_cmt_cols',
            'type' => 'button_set',
            'title' => __('列数', 'kratos'),
            'options' => array(
                '2' => __('两列', 'kratos'),
                '3' => __('三列', 'kratos'),
            ),
            'default' => '2',
        ),
        array(
            'id' => 'hf_cmt_words',
            'type' => 'number',
            'title' => __('评论摘要字数', 'kratos'),
            'subtitle' => __('超出截断，卡片内最多两行', 'kratos'),
            'min' => 5,
            'default' => 20,
        ),
        array(
            'id' => 'hf_cmt_avatar',
            'type' => 'switcher',
            'title' => __('展示头像', 'kratos'),
            'text_on' => __('展示', 'kratos'),
            'text_off' => __('隐藏', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'hf_cmt_skip_admin',
            'type' => 'switcher',
            'title' => __('排除博主评论', 'kratos'),
            'subtitle' => __('不显示管理员自己的评论，只留访客发言', 'kratos'),
            'text_on' => __('排除', 'kratos'),
            'text_off' => __('不排除', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'hf_cmt_more_url',
            'type' => 'text',
            'title' => __('「全部评论」链接', 'kratos'),
            'subtitle' => __('留空则不显示按钮；可填「走心评论」或「榜首评论人」页地址', 'kratos'),
            'default' => '',
        ),

        array(
            'type' => 'subheading',
            'content' => __('模块七 · 数据条', 'kratos'),
        ),
        array(
            'id' => 'hf_stat_title',
            'type' => 'text',
            'title' => __('标题', 'kratos'),
            'subtitle' => __('留空则四张小卡直接铺开，推荐留空', 'kratos'),
            'default' => '',
        ),
        array(
            'id' => 'hf_stat_sub',
            'type' => 'text',
            'title' => __('副标题', 'kratos'),
            'default' => '',
        ),
        array(
            'id' => 'hf_stat_icon',
            'type' => 'icon',
            'title' => __('标题图标', 'kratos'),
            'default' => 'fas fa-chart-simple',
        ),
        array(
            'id' => 'hf_stat_icon_post',
            'type' => 'icon',
            'title' => __('文章数图标', 'kratos'),
            'default' => 'fas fa-pen-fancy',
        ),
        array(
            'id' => 'hf_stat_icon_cat',
            'type' => 'icon',
            'title' => __('分类数图标', 'kratos'),
            'default' => 'fas fa-folder-open',
        ),
        array(
            'id' => 'hf_stat_icon_tag',
            'type' => 'icon',
            'title' => __('标签数图标', 'kratos'),
            'default' => 'fas fa-tags',
        ),
        array(
            'id' => 'hf_stat_icon_comment',
            'type' => 'icon',
            'title' => __('评论数图标', 'kratos'),
            'default' => 'fas fa-comments',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_pages',
    'title' => __('说说配置', 'kratos'),
    'icon' => 'far fa-comment-dots',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('显示在「说说」模板页顶部，留空则隐藏。', 'kratos'),
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
            'subtitle' => __('说说页每页显示多少条。', 'kratos'),
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
            'subtitle' => __('超过该字数就折叠并显示「展开」，0 为不折叠。', 'kratos'),
            'default' => 300,
            'attributes' => array(
                'min' => 0,
                'step' => 10,
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_pages',
    'title' => __('归档配置', 'kratos'),
    'icon' => 'fas fa-archive',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('显示在「文章归档」模板页与 [archives_stats] 顶部，留空则隐藏；短码同名参数优先。', 'kratos'),
        ),
        array(
            'id' => 'archives_sc_title',
            'type' => 'text',
            'title' => __('页面标题', 'kratos'),
            'subtitle' => __('[archives_stats] 未传 title 时用它；留空则不显示标题', 'kratos'),
            'default' => __('文章归档', 'kratos'),
        ),
        array(
            'id' => 'archives_sc_subtitle',
            'type' => 'text',
            'title' => __('页面副标题', 'kratos'),
            'subtitle' => __('[archives_stats] 未传 subtitle 时用它；留空则不显示副标题', 'kratos'),
            'default' => __('把写过的时间，安静收拢起来', 'kratos'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('列表展示', 'kratos'),
        ),
        array(
            'id' => 'archives_sc_years_max',
            'type' => 'number',
            'title' => __('年份最多条数', 'kratos'),
            'subtitle' => __('按年份最多展示多少年，0 为全部；短码可用 years_max 覆盖', 'kratos'),
            'default' => 0,
            'attributes' => array(
                'min' => 0,
                'step' => 1,
            ),
        ),
        array(
            'id' => 'archives_sc_months_max',
            'type' => 'number',
            'title' => __('月份最多条数', 'kratos'),
            'subtitle' => __('按月份最多展示多少个月，0 为不显示月份 Tab；短码可用 months_max 覆盖', 'kratos'),
            'default' => 24,
            'attributes' => array(
                'min' => 0,
                'step' => 1,
            ),
        ),
        array(
            'id' => 'archives_sc_tags_max',
            'type' => 'number',
            'title' => __('标签最多条数', 'kratos'),
            'subtitle' => __('标签区块最多展示多少个，0 为不显示；短码可用 tags_max 覆盖', 'kratos'),
            'default' => 20,
            'attributes' => array(
                'min' => 0,
                'step' => 1,
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_pages',
    'title' => __('时间轴配置', 'kratos'),
    'icon' => 'fas fa-stream',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('显示在「时间轴」模板页与 [timeline] 顶部，留空则隐藏；短码同名参数优先。', 'kratos'),
        ),
        array(
            'id' => 'timeline_sc_title',
            'type' => 'text',
            'title' => __('页面标题', 'kratos'),
            'subtitle' => __('[timeline] 未传 title 时用它；留空则不显示标题', 'kratos'),
            'default' => __('时间轴', 'kratos'),
        ),
        array(
            'id' => 'timeline_sc_subtitle',
            'type' => 'text',
            'title' => __('页面副标题', 'kratos'),
            'subtitle' => __('[timeline] 未传 subtitle 时用它；留空则不显示副标题', 'kratos'),
            'default' => __('把每一次写作，钉在属于它的那一天', 'kratos'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('列表展示', 'kratos'),
        ),
        array(
            'id' => 'timeline_sc_per_page',
            'type' => 'number',
            'title' => __('每页条数', 'kratos'),
            'subtitle' => __('每页显示多少篇，短码可用 per_page 覆盖；填 0 不分页', 'kratos'),
            'default' => 20,
            'attributes' => array(
                'min' => 0,
                'step' => 1,
            ),
        ),
        array(
            'id' => 'timeline_sc_exclude_cats',
            'type' => 'checkbox',
            'title' => __('排除分类', 'kratos'),
            'subtitle' => __('这些分类的文章不进时间轴；短码可用 exclude_cats="1,2,3" 覆盖', 'kratos'),
            'options' => (function () {
                $out = array();
                $terms = function_exists('get_terms') ? get_terms(array(
                    'taxonomy'   => 'category',
                    'hide_empty' => false,
                )) : array();
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $t) {
                        $out[(int) $t->term_id] = $t->name;
                    }
                }
                return $out;
            })(),
            'inline' => true,
            'default' => array(),
        ),
        array(
            'type' => 'subheading',
            'content' => __('文章热力图', 'kratos'),
        ),
        array(
            'id' => 'heatmap_enabled',
            'type' => 'switcher',
            'title' => __('启用热力图', 'kratos'),
            'subtitle' => __('用 [post_heatmap] 在任意文章或页面插入 GitHub 风格发文热力图', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'heatmap_on_timeline',
            'type' => 'switcher',
            'title' => __('时间轴页自动展示', 'kratos'),
            'subtitle' => __('时间轴页自动在列表上方插一张热力图，不用手写短码', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'dependency' => array('heatmap_enabled', '==', 'true'),
            'default' => true,
        ),
        array(
            'id' => 'heatmap_sc_title',
            'type' => 'text',
            'title' => __('默认标题', 'kratos'),
            'subtitle' => __('[post_heatmap] 未传 title 时用它；留空则不显示标题', 'kratos'),
            'dependency' => array('heatmap_enabled', '==', 'true'),
            'default' => __('文章热力图', 'kratos'),
        ),
        array(
            'id' => 'heatmap_sc_post_type',
            'type' => 'text',
            'title' => __('默认文章类型', 'kratos'),
            'subtitle' => __('统计哪种文章类型，如 post / shuoshuo；短码可用 post_type 覆盖', 'kratos'),
            'dependency' => array('heatmap_enabled', '==', 'true'),
            'default' => 'post',
        ),
        array(
            'id' => 'heatmap_sc_time_range',
            'type' => 'number',
            'title' => __('默认时间范围（天）', 'kratos'),
            'subtitle' => __('未选年份时看最近多少天；短码可用 time_range 覆盖', 'kratos'),
            'dependency' => array('heatmap_enabled', '==', 'true'),
            'default' => 365,
            'attributes' => array('min' => 30, 'step' => 1),
        ),
        array(
            'id' => 'heatmap_years_max',
            'type' => 'number',
            'title' => __('年份标签最多显示', 'kratos'),
            'subtitle' => __('右侧年份标签最多列几年（倒序），填 0 为全部', 'kratos'),
            'dependency' => array('heatmap_enabled', '==', 'true'),
            'default' => 5,
            'attributes' => array('min' => 0, 'step' => 1),
        ),
        array(
            'type' => 'content',
            'content' => __(
                '<div style="padding:12px 14px;background:#f6f8fa;border:1px solid #e1e4e8;border-radius:6px;line-height:1.8;">'
                . '<b>短码使用说明：</b><br>'
                . '<code>[post_heatmap]</code>  显示默认设置的热力图<br>'
                . '<code>[post_heatmap title="我的写作日历" year="2025"]</code>  指定标题与年份<br>'
                . '<code>[post_heatmap post_type="shuoshuo" time_range="180"]</code>  统计说说，最近 180 天<br>'
                . '<code>[post_heatmap width="900px"]</code>  自定义容器宽度<br><br>'
                . '<b>支持参数：</b><br>'
                . '• <code>title</code>：标题文本，留空则不显示标题<br>'
                . '• <code>post_type</code>：文章类型 slug，默认 <code>post</code><br>'
                . '• <code>year</code>：指定年份（如 <code>2024</code>），留空显示最近 <code>time_range</code> 天<br>'
                . '• <code>time_range</code>：最近多少天（默认 <code>365</code>）<br>'
                . '• <code>width</code>：容器宽度（默认 <code>100%</code>）<br><br>'
                . '</div>',
                'kratos'
            ),
        ),

    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_pages',
    'title' => __('数据看板', 'kratos'),
    'icon' => 'fas fa-chart-bar',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('新建页面选「站点数据看板」模板即可访问，也可用 [site_dashboard] 嵌入任意页面。数据复用归档统计 / 评论排行 / 阅读增强 / 地域分布。', 'kratos'),
        ),
        array(
            'id' => 'g_dash_title',
            'type' => 'text',
            'title' => __('页头标题', 'kratos'),
            'default' => __('站点数据看板', 'kratos'),
        ),
        array(
            'id' => 'g_dash_subtitle',
            'type' => 'text',
            'title' => __('页头副标题', 'kratos'),
            'default' => __('这个博客到今天为止，长成了什么样子 📊', 'kratos'),
        ),
        array(
            'id' => 'g_dash_days',
            'type' => 'text',
            'title' => __('发布节奏天数', 'kratos'),
            'subtitle' => __('柱状图看最近多少天，7~120，默认 30', 'kratos'),
            'default' => '30',
        ),
        array(
            'id' => 'g_dash_words',
            'type' => 'switcher',
            'title' => __('统计累计字数', 'kratos'),
            'subtitle' => __('需逐篇读正文计数；上千篇的站点若重算偏慢可关掉', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_dash_years_max',
            'type' => 'text',
            'title' => __('年度产出条数', 'kratos'),
            'subtitle' => __('填 0 不展示', 'kratos'),
            'default' => '8',
        ),
        array(
            'id' => 'g_dash_cats_max',
            'type' => 'text',
            'title' => __('分类占比条数', 'kratos'),
            'subtitle' => __('填 0 不展示', 'kratos'),
            'default' => '10',
        ),
        array(
            'id' => 'g_dash_commenters_max',
            'type' => 'text',
            'title' => __('最勤评论者条数', 'kratos'),
            'subtitle' => __('复用「评论排行榜」的数据，填 0 不展示', 'kratos'),
            'default' => '5',
        ),
        array(
            'id' => 'g_dash_geo',
            'type' => 'switcher',
            'title' => __('内嵌评论地域分布', 'kratos'),
            'subtitle' => __('看板底部嵌入 [comment_geo]，不重复输出它的页头卡', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_dash_cache_min',
            'type' => 'text',
            'title' => __('缓存时长(分钟)', 'kratos'),
            'subtitle' => __('统计结果缓存多久，内容变动时自动失效。最小 5，默认 180', 'kratos'),
            'default' => '180',
        ),
        array(
            'id' => 'g_dash_show_updated',
            'type' => 'switcher',
            'title' => __('展示数据更新时间', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_dash_notice',
            'type' => 'content',
            'content' => '<div style="padding:12px 14px;background:#f7f3ea;border:1px solid #e3d9c4;border-radius:8px;line-height:1.8;">'
                . '<p style="margin:0 0 6px;"><strong>' . __('建站天数取自：', 'kratos') . '</strong>'
                . __('「年度回顾 → 建站日期」（<code>site_birthday</code>）；没填时自动回落到最早一篇文章的发布时间。', 'kratos') . '</p>'
                . '<p style="margin:0;color:#8a6a5d;">' . __('💡 平均更新间隔 = 首篇到末篇的天数跨度 ÷ 间隔数，只有两篇以上才会展示。', 'kratos') . '</p>'
                . '</div>',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_pages',
    'title' => __('年度回顾', 'kratos'),
    'icon' => 'far fa-calendar-check',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('年度数据长图，可一键存为 PNG。新建页面选「年度回顾」模板，或用 [yearly_review year="2026"] 嵌入。', 'kratos'),
        ),
        array(
            'id' => 'site_birthday',
            'type' => 'text',
            'title' => __('建站日期', 'kratos'),
            'subtitle' => __('YYYY-MM-DD，用于算「陪伴天数」与生日提示', 'kratos'),
            'default' => '',
        ),
        array(
            'id' => 'yr_message',
            'type' => 'textarea',
            'title' => __('送给读者的一句话', 'kratos'),
            'subtitle' => __('显示在年度长图底部', 'kratos'),
            'default' => __('感谢每一位读者的陪伴，我们下一年见 🥂', 'kratos'),
        ),
        array(
            'id' => 'yr_birthday_hint',
            'type' => 'switcher',
            'title' => __('生日当天首页提示', 'kratos'),
            'subtitle' => __('生日当天首页顶部显示提示条，引导访客看专属长图', 'kratos'),
            'default' => false,
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_pages',
    'title' => __('Now 页面', 'kratos'),
    'icon' => 'far fa-clock',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('展示「我最近在做什么」。新建页面选「Now」模板，内容来自后台「Now」菜单里的条目。', 'kratos'),
        ),
        array(
            'id' => 'now_page_title',
            'type' => 'text',
            'title' => __('页面标题', 'kratos'),
            'default' => __('Now', 'kratos'),
        ),
        array(
            'id' => 'now_page_subtitle',
            'type' => 'textarea',
            'title' => __('页面副标题', 'kratos'),
            'subtitle' => __('显示在标题下方的一句介绍', 'kratos'),
            'default' => __('这是我最近在做的事、在想的事、在学的事。', 'kratos'),
        ),
        array(
            'id' => 'now_show_history',
            'type' => 'switcher',
            'title' => __('展示历史条目', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'now_history_limit',
            'type' => 'number',
            'title' => __('历史条目数量', 'kratos'),
            'default' => 20,
            'attributes' => array('min' => 1, 'step' => 1),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_pages',
    'title' => __('岁月同一天', 'kratos'),
    'icon' => 'far fa-calendar-alt',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('展示往年同月同日发布的文章 / 说说。可用 [on_this_day]，也能挂到小工具、首页顶部或文章底部。', 'kratos'),
        ),
        array(
            'id' => 'otd_enable',
            'type' => 'switcher',
            'title' => __('启用', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'otd_title',
            'type' => 'text',
            'title' => __('标题', 'kratos'),
            'default' => __('岁月同一天', 'kratos'),
        ),
        array(
            'id' => 'otd_subtitle',
            'type' => 'text',
            'title' => __('副标题', 'kratos'),
            'default' => __('回望过去的今天，你在写什么', 'kratos'),
        ),
        array(
            'id' => 'otd_post_types',
            'type' => 'checkbox',
            'title' => __('包含的文章类型', 'kratos'),
            'options' => array(
                'post'     => __('博客文章 (post)', 'kratos'),
                'shuoshuo' => __('说说 (shuoshuo)', 'kratos'),
            ),
            'default' => array('post'),
        ),
        array(
            'id' => 'otd_limit',
            'type' => 'number',
            'title' => __('最多条数', 'kratos'),
            'default' => 20,
            'attributes' => array('min' => 1, 'step' => 1),
        ),
        array(
            'id' => 'otd_show_thumb',
            'type' => 'switcher',
            'title' => __('展示缩略图', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'otd_home_position',
            'type' => 'select',
            'title' => __('首页位置', 'kratos'),
            'options' => array(
                'none' => __('不展示', 'kratos'),
                'top'  => __('首页主循环顶部', 'kratos'),
            ),
            'default' => 'none',
        ),
        array(
            'id' => 'otd_after_post',
            'type' => 'switcher',
            'title' => __('在文章底部自动展示', 'kratos'),
            'subtitle' => __('只在今天确实有往年内容时显示。', 'kratos'),
            'default' => false,
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_pages',
    'title' => __('每日心情灯', 'kratos'),
    'icon' => 'fas fa-smile-beam',
    'fields' => array(
        array(
            'id' => 'mood_log_enabled',
            'type' => 'switcher',
            'title' => __('启用心情灯', 'kratos'),
            'subtitle' => __('用 [mood_log] 展示心情热力图；仅站长可录入，每天一格 + 一句话', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'mood_log_public_notes',
            'type' => 'switcher',
            'title' => __('公开显示一句话', 'kratos'),
            'subtitle' => __('关闭后访客只看到心情等级，看不到当天那句话', 'kratos'),
            'text_on' => __('公开', 'kratos'),
            'text_off' => __('仅自己可见', 'kratos'),
            'dependency' => array('mood_log_enabled', '==', 'true'),
            'default' => true,
        ),
        array(
            'id' => 'mood_log_sc_title',
            'type' => 'text',
            'title' => __('默认标题', 'kratos'),
            'subtitle' => __('[mood_log] 未传 title 时用它；留空则不显示标题', 'kratos'),
            'dependency' => array('mood_log_enabled', '==', 'true'),
            'default' => __('情绪热力图', 'kratos'),
        ),
        array(
            'id' => 'mood_log_sc_time_range',
            'type' => 'number',
            'title' => __('默认时间范围（天）', 'kratos'),
            'subtitle' => __('未选年份时看最近多少天；短码可用 time_range 覆盖', 'kratos'),
            'dependency' => array('mood_log_enabled', '==', 'true'),
            'default' => 365,
            'attributes' => array('min' => 30, 'step' => 1),
        ),
        array(
            'id' => 'mood_log_years_max',
            'type' => 'number',
            'title' => __('年份标签最多显示', 'kratos'),
            'subtitle' => __('右侧年份标签最多列几年（倒序），填 0 为全部', 'kratos'),
            'dependency' => array('mood_log_enabled', '==', 'true'),
            'default' => 5,
            'attributes' => array('min' => 0, 'step' => 1),
        ),
        array(
            'type' => 'content',
            'content' => __(
                '<div style="padding:12px 14px;background:#f6f8fa;border:1px solid #e1e4e8;border-radius:6px;line-height:1.8;">'
                . '<b>短码使用说明：</b><br>'
                . '<code>[mood_log]</code>  情绪热力图；登录站长会自动附带今日录入卡<br>'
                . '<code>[mood_log year="2025"]</code>  指定年份<br>'
                . '<code>[mood_log show_input="no"]</code>  强制不显示录入卡（仅展示热力图）<br>'
                . '<code>[mood_log_input]</code>  仅显示录入卡（非站长返回空）<br><br>'
                . '<b>心情等级：</b>1 低落 / 2 平淡 / 3 尚可 / 4 愉悦 / 5 高光。每天覆盖式保存，同日多次提交只保留最后一次。<br>'
                . '</div>',
                'kratos'
            ),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'kp_social',
    'title' => __('站点互联', 'kratos'),
    'icon' => 'fas fa-users',
));

CSF::createSection($prefix, array(
    'parent' => 'kp_social',
    'title' => __('友链配置', 'kratos'),
    'icon' => 'fas fa-link',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('展示', 'kratos'),
        ),
        array(
            'id' => 'g_friend_sc_title',
            'type' => 'text',
            'title' => __('页面标题', 'kratos'),
            'subtitle' => __('[friend_links] 未传 title 时用它；留空则不显示标题', 'kratos'),
            'default' => __('友情链接', 'kratos'),
        ),
        array(
            'id' => 'g_friend_sc_subtitle',
            'type' => 'text',
            'title' => __('页面副标题', 'kratos'),
            'subtitle' => __('[friend_links] 未传 subtitle 时用它；留空则不显示副标题', 'kratos'),
            'default' => __('感谢各位朋友的关注与支持，欢迎申请交换友链 🤝', 'kratos'),
        ),
        array(
            'id' => 'g_friend_hide_empty',
            'type' => 'switcher',
            'title' => __('隐藏空分类', 'kratos'),
            'subtitle' => __('空分类不显示在页面上', 'kratos'),
            'default' => true,
        ),
        array(
            'type' => 'subheading',
            'content' => __('本站信息', 'kratos'),
        ),
        array(
            'id' => 'g_friend_siteinfo_enabled',
            'type' => 'switcher',
            'title' => __('展示本站信息', 'kratos'),
            'subtitle' => __('列表上方展示本站信息卡，方便对方复制交换所需信息', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_friend_siteinfo_name',
            'type' => 'text',
            'title' => __('站点名称', 'kratos'),
            'subtitle' => __('留空则用「设置 → 常规」的站点标题', 'kratos'),
            'default' => '',
            'dependency' => array('g_friend_siteinfo_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_friend_siteinfo_url',
            'type' => 'text',
            'title' => __('站点地址', 'kratos'),
            'subtitle' => __('留空则用站点首页地址', 'kratos'),
            'default' => '',
            'dependency' => array('g_friend_siteinfo_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_friend_siteinfo_logo',
            'type' => 'text',
            'title' => __('Logo 地址', 'kratos'),
            'subtitle' => __('Logo / 头像地址，留空则不显示', 'kratos'),
            'default' => '',
            'dependency' => array('g_friend_siteinfo_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_friend_siteinfo_desc',
            'type' => 'text',
            'title' => __('站点描述', 'kratos'),
            'subtitle' => __('留空则用「设置 → 常规」的副标题', 'kratos'),
            'default' => '',
            'dependency' => array('g_friend_siteinfo_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_friend_siteinfo_rss',
            'type' => 'text',
            'title' => __('RSS 订阅地址', 'kratos'),
            'subtitle' => __('留空则用站点默认 Feed', 'kratos'),
            'default' => '',
            'dependency' => array('g_friend_siteinfo_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('申请要求', 'kratos'),
        ),
        array(
            'id' => 'g_friend_requirements_enabled',
            'type' => 'switcher',
            'title' => __('展示申请要求', 'kratos'),
            'subtitle' => __('列表上方展示申请要求', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_friend_requirements_title',
            'type' => 'text',
            'title' => __('区块标题', 'kratos'),
            'default' => __('友链申请要求', 'kratos'),
            'dependency' => array('g_friend_requirements_enabled', '==', 'true'),
        ),
        array(
            'type' => 'callback',
            'title' => __('要求内容', 'kratos'),
            'subtitle' => __('支持 HTML；是否显示由上方开关决定', 'kratos'),
            'function' => 'kratos_friend_requirements_editor_render',
        ),
        array(
            'type' => 'subheading',
            'content' => __('探活检测', 'kratos'),
        ),
        array(
            'id' => 'g_friend_probe_enabled',
            'type' => 'switcher',
            'title' => __('启用探活检测', 'kratos'),
            'subtitle' => __('定时检测友链是否可达，结果显示在卡片与后台列表', 'kratos'),
            'default' => false,
        ),
        array(
            'id' => 'g_friend_probe_interval',
            'type' => 'select',
            'title' => __('探测频率', 'kratos'),
            'subtitle' => __('每隔多久检测一轮', 'kratos'),
            'options' => array(
                'daily'      => __('每天一次', 'kratos'),
                'twicedaily' => __('每天两次', 'kratos'),
                'hourly'     => __('每小时', 'kratos'),
            ),
            'default' => 'daily',
            'dependency' => array('g_friend_probe_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('最近访客', 'kratos'),
        ),
        array(
            'id' => 'g_friend_recent_enabled',
            'type' => 'switcher',
            'title' => __('展示最近访客', 'kratos'),
            'subtitle' => __('展示最近来评论过的访客，按人去重', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_friend_recent_title',
            'type' => 'text',
            'title' => __('区块标题', 'kratos'),
            'subtitle' => __('留空则不显示标题', 'kratos'),
            'default' => __('最近访客', 'kratos'),
            'dependency' => array('g_friend_recent_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_friend_recent_limit',
            'type' => 'number',
            'title' => __('展示数量', 'kratos'),
            'subtitle' => __('最多展示几位，去重后不足则少展示', 'kratos'),
            'min' => 1,
            'max' => 100,
            'default' => 20,
            'dependency' => array('g_friend_recent_enabled', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('申请表单', 'kratos'),
        ),
        array(
            'id' => 'g_friend_form_enabled',
            'type' => 'switcher',
            'title' => __('开启申请表单', 'kratos'),
            'subtitle' => __('关闭则隐藏页面末尾的申请表单', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_friend_form_intro',
            'type' => 'text',
            'title' => __('表单引导文案', 'kratos'),
            'subtitle' => __('显示在表单标题下方，留空则不显示', 'kratos'),
            'default' => __('填写下方表单提交友链申请，站长审核通过后会自动上线。', 'kratos'),
            'dependency' => array('g_friend_form_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_friend_default_category',
            'type' => 'select',
            'title' => __('默认分类', 'kratos'),
            'subtitle' => __('新申请归入哪个链接分类；未选则用最早创建的那个（通常是 Blogroll）', 'kratos'),
            'options' => (function () {
                $out = array(0 => __('— 自动选择 —', 'kratos'));
                $terms = function_exists('get_terms') ? get_terms(array('taxonomy' => 'link_category', 'hide_empty' => false)) : array();
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $t) $out[(int) $t->term_id] = $t->name;
                }
                return $out;
            })(),
            'default' => 0,
            'dependency' => array('g_friend_form_enabled', '==', 'true'),
        ),
        array(
            'id' => 'g_friend_notify_admin',
            'type' => 'switcher',
            'title' => __('邮件通知管理员', 'kratos'),
            'subtitle' => __('新申请时发信到「设置 → 常规」的管理员邮箱', 'kratos'),
            'default' => true,
            'dependency' => array('g_friend_form_enabled', '==', 'true'),
        ),
        array(
            'type' => 'content',
            'content' =>
                '<div style="padding:16px 18px;background:linear-gradient(135deg,#f4f9ff 0%,#e6f1fe 100%);border:1px solid #c9dcf4;border-radius:12px;color:#243a5e;line-height:1.8;font-size:13px;">'
                . '<p style="margin:0 0 10px;font-size:14px;font-weight:600;color:#336699;">' . __('🔗 友链管理说明', 'kratos') . '</p>'
                . '<p style="margin:0 0 6px;"><strong>' . __('1. 数据存储：', 'kratos') . '</strong>'
                . sprintf(
                    __('复用 WordPress 原生「链接」（wp_links）表，与「%s」共享数据。已通过的友链自动出现在「评论友链标识」的匹配列表中。', 'kratos'),
                    '<a href="' . esc_url(admin_url('link-manager.php')) . '" target="_blank">' . esc_html__('链接管理', 'kratos') . '</a>'
                )
                . '</p>'
                . '<p style="margin:0 0 6px;"><strong>' . __('2. 分类管理：', 'kratos') . '</strong>'
                . sprintf(
                    __('前台按 link_category 分组展示，请到 %s 里创建分类，然后手动新增或编辑链接时选择分类。', 'kratos'),
                    '<a href="' . esc_url(admin_url('edit-tags.php?taxonomy=link_category')) . '" target="_blank">' . esc_html__('链接分类目录', 'kratos') . '</a>'
                )
                . '</p>'
                . '<p style="margin:0 0 6px;"><strong>' . __('3. 审核流程：', 'kratos') . '</strong>'
                . sprintf(
                    __('新申请以 link_visible = "N" 保存；到 %s 页面顶部会显示「待审核」筛选，行内操作可以「通过」或「拒绝」（拒绝会直接删除该记录）。审核通过后自动清除评论友链缓存。', 'kratos'),
                    '<a href="' . esc_url(admin_url('link-manager.php?kfl_filter=pending')) . '" target="_blank">' . esc_html__('链接管理', 'kratos') . '</a>'
                )
                . '</p>'
                . '<p style="margin:0 0 4px;"><strong>' . __('4. 页面使用：', 'kratos') . '</strong>' . __('新建页面时模板选「友情链接」；或在任意页面 / 文章插入 <code style="background:#fff;padding:2px 8px;border-radius:4px;color:#336699;">[friend_links]</code>。', 'kratos') . '</p>'
                . '<p style="margin:0;color:#5b6d8a;">' . __('💡 短码参数：', 'kratos') . '<code>title</code> ' . __('标题；', 'kratos') . '<code>subtitle</code> ' . __('副标题；', 'kratos') . '<code>hide_empty="0"</code> ' . __('展示空分类；', 'kratos') . '<code>form="0"</code> ' . __('隐藏表单。', 'kratos') . '</p>'
                . '</div>',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_social',
    'title' => __('博友动态', 'kratos'),
    'icon' => 'fas fa-rss',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('总开关', 'kratos'),
        ),
        array(
            'id' => 'g_friend_feed_enabled',
            'type' => 'switcher',
            'title' => __('启用 RSS 抓取', 'kratos'),
            'subtitle' => __('关闭后停止抓取、短码显示空态；已抓到的数据不删，重开即恢复。', 'kratos'),
            'text_on' => __('开启', 'kratos'),
            'text_off' => __('关闭', 'kratos'),
            'default' => true,
        ),
        array(
            'type' => 'subheading',
            'content' => __('页面展示', 'kratos'),
        ),
        array(
            'id' => 'g_friend_feed_sc_title',
            'type' => 'text',
            'title' => __('页面标题', 'kratos'),
            'subtitle' => __('[friend_feed] 未传 title 时用它；留空则不显示标题', 'kratos'),
            'default' => __('博友动态', 'kratos'),
        ),
        array(
            'id' => 'g_friend_feed_sc_subtitle',
            'type' => 'text',
            'title' => __('页面副标题', 'kratos'),
            'subtitle' => __('[friend_feed] 未传 subtitle 时用它；留空则不显示副标题', 'kratos'),
            'default' => __('订阅友链的 RSS，把大家的更新汇聚在一起 🌐', 'kratos'),
        ),
        array(
            'id' => 'g_friend_feed_sc_per_page',
            'type' => 'number',
            'title' => __('每页条数', 'kratos'),
            'subtitle' => __('每页显示多少篇，短码可用 per_page 覆盖；填 0 不分页', 'kratos'),
            'default' => 20,
            'attributes' => array(
                'min'  => 0,
                'step' => 1,
            ),
        ),
        array(
            'id' => 'g_friend_feed_summary_len',
            'type' => 'number',
            'title' => __('摘要长度', 'kratos'),
            'subtitle' => __('卡片摘要字数上限，0 为不截断', 'kratos'),
            'default' => 160,
            'attributes' => array(
                'min'  => 0,
                'step' => 10,
            ),
        ),
        array(
            'type' => 'subheading',
            'content' => __('抓取任务', 'kratos'),
        ),
        array(
            'id' => 'g_friend_feed_cron_interval',
            'type' => 'select',
            'title' => __('自动更新间隔', 'kratos'),
            'subtitle' => __('依赖 WordPress Cron，保存后下次触发时按新间隔重排。', 'kratos'),
            'options' => array(
                'hourly'                 => __('每小时', 'kratos'),
                'kratos_friend_feed_6h'  => __('每 6 小时', 'kratos'),
                'kratos_friend_feed_12h' => __('每 12 小时', 'kratos'),
                'twicedaily'             => __('每 12 小时（WP 内置 twicedaily）', 'kratos'),
                'daily'                  => __('每天', 'kratos'),
            ),
            'default' => 'kratos_friend_feed_6h',
            'dependency' => array('g_friend_feed_enabled', '==', 'true'),
        ),
        array(
            'type' => 'content',
            'content' => (function () {
                $enabled = function_exists('kratos_friend_feed_is_enabled') ? kratos_friend_feed_is_enabled() : true;
                $last  = get_option('kratos_friend_feed_last_run');
                $next  = wp_next_scheduled('kratos_friend_feed_cron_fetch');
                $fmt   = get_option('date_format') . ' ' . get_option('time_format');

                $refresh_url = wp_nonce_url(
                    add_query_arg('action', 'kratos_friend_feed_refresh', admin_url('admin-post.php')),
                    'kratos_friend_feed_refresh'
                ) . '#tab=' . sanitize_title(__('博友动态', 'kratos'));

                $html  = '<div style="padding:16px 18px;background:linear-gradient(135deg,#f4f9ff 0%,#e6f1fe 100%);border:1px solid #c9dcf4;border-radius:12px;color:#243a5e;line-height:1.9;font-size:13px;">';
                if (!$enabled) {
                    $html .= '<p style="margin:0 0 10px;padding:8px 12px;background:#fff3f2;border:1px solid #f0c4c0;border-radius:8px;color:#8b2a2a;font-weight:600;">⚠ ' . esc_html__('RSS 抓取当前已关闭：Cron 已停排，「立即刷新」按钮不会生效。', 'kratos') . '</p>';
                }
                $html .= '<p style="margin:0 0 10px;font-size:14px;font-weight:600;color:#336699;">' . esc_html__('📡 博友动态使用说明', 'kratos') . '</p>';
                $html .= '<p style="margin:0 0 6px;"><strong>' . esc_html__('1. 数据来源：', 'kratos') . '</strong>' .
                    sprintf(
                        /* translators: %s admin link-manager URL */
                        wp_kses(__('抓取「%s」中已通过（link_visible=Y）且填写了「RSS 地址」的友链，通过 SimplePie 拉取 Feed 后落库到自建表 <code>{prefix}kratos_friend_feed</code>。', 'kratos'), array('code' => array(), 'a' => array('href' => array(), 'target' => array()))),
                        '<a href="' . esc_url(admin_url('link-manager.php')) . '" target="_blank">' . esc_html__('链接管理', 'kratos') . '</a>'
                    ) . '</p>';
                $html .= '<p style="margin:0 0 6px;"><strong>' . esc_html__('2. 前台使用：', 'kratos') . '</strong>' . esc_html__('新建页面时模板选「博友动态」；或在任意页面插入短码 ', 'kratos') . '<code style="background:#fff;padding:2px 8px;border-radius:4px;color:#336699;">[friend_feed]</code>。</p>';
                $html .= '<p style="margin:0 0 6px;"><strong>' . esc_html__('3. 页面结构：', 'kratos') . '</strong>' . esc_html__('顶部 4 张统计卡（文章总数 / 订阅站点 / 本月文章 / 最近更新时间），下方按发布时间倒序展示卡片列表并分页。', 'kratos') . '</p>';
                $html .= '<p style="margin:0 0 6px;"><strong>' . esc_html__('4. 分页参数：', 'kratos') . '</strong>' . esc_html__('通过 URL 参数 ?ffd_page=2 控制，短码已自动渲染上一页/下一页与页码按钮。', 'kratos') . '</p>';

                $html .= '<hr style="border:none;border-top:1px dashed #c9dcf4;margin:10px 0;">';
                $html .= '<p style="margin:0 0 6px;"><strong>' . esc_html__('上次抓取：', 'kratos') . '</strong>';
                if (is_array($last) && !empty($last['time'])) {
                    $html .= esc_html(date_i18n($fmt, (int) $last['time'])) . '　';
                    $html .= sprintf(
                        /* translators: %1$d ok, %2$d total, %3$d fetched, %4$d inserted */
                        esc_html__('成功 %1$d / %2$d 站点，读取 %3$d 篇，新入库 %4$d 篇。', 'kratos'),
                        (int) $last['ok'],
                        (int) $last['sites'],
                        (int) $last['fetched'],
                        (int) $last['inserted']
                    );
                    if (!empty($last['errors'])) {
                        $html .= '<br><span style="color:#a04040;">' . esc_html__('失败站点：', 'kratos');
                        $err_names = array();
                        foreach ((array) $last['errors'] as $e) {
                            $err_names[] = (string) ($e['name'] ?? '');
                        }
                        $html .= esc_html(implode('、', array_filter($err_names)));
                        $html .= '</span>';
                    }
                } else {
                    $html .= '<em style="color:#8a99b5;">' . esc_html__('（尚未抓取过）', 'kratos') . '</em>';
                }
                $html .= '</p>';

                $html .= '<p style="margin:0 0 10px;"><strong>' . esc_html__('下次自动抓取：', 'kratos') . '</strong>';
                if (!$enabled) {
                    $html .= '<em style="color:#8a99b5;">' . esc_html__('（已停用，未排期）', 'kratos') . '</em>';
                } else {
                    $html .= $next ? esc_html(date_i18n($fmt, (int) $next)) : '<em style="color:#8a99b5;">' . esc_html__('（未排期，保存本页后会自动注册）', 'kratos') . '</em>';
                }
                $html .= '</p>';

                $html .= '<p style="margin:0;">';
                if ($enabled) {
                    $html .= '<a href="' . esc_url($refresh_url) . '" class="button button-primary" style="background:#336699;border-color:#2b5278;">' . esc_html__('立即刷新', 'kratos') . '</a>';
                    $html .= '<span style="margin-left:12px;color:#5b6d8a;">' . esc_html__('手动触发一次抓取，无需等待下次 Cron；抓取过程同步执行，可能耗时数秒。', 'kratos') . '</span>';
                } else {
                    $html .= '<button type="button" class="button" disabled>' . esc_html__('立即刷新（已停用）', 'kratos') . '</button>';
                    $html .= '<span style="margin-left:12px;color:#8b6a70;">' . esc_html__('开启上方「启用 RSS 抓取」后此按钮才会生效。', 'kratos') . '</span>';
                }
                $html .= '</p>';
                $html .= '</div>';
                return $html;
            })(),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'kp_ux',
    'title' => __('交互增强', 'kratos'),
    'icon' => 'fas fa-magic',
));

CSF::createSection($prefix, array(
    'parent' => 'kp_ux',
    'title' => __('命令面板', 'kratos'),
    'icon' => 'fas fa-terminal',
    'fields' => array(
        array(
            'id' => 'g_cmdk_notice',
            'type' => 'content',
            'content' => '<div style="padding:12px 14px;background:#f7f3ea;border:1px solid #e3d9c4;border-radius:8px;line-height:1.8;">'
                . '<p style="margin:0 0 6px;"><strong>' . __('唤出方式：', 'kratos') . '</strong>'
                . __('macOS 按 <code>⌘K</code>，Windows / Linux 按 <code>Ctrl+K</code>；非输入状态下按 <code>/</code> 也可唤出。快捷键提示会按访客的操作系统自动切换。', 'kratos') . '</p>'
                . '<p style="margin:0;color:#8a6a5d;">' . __('💡 面板聚合了：站内搜索、页面跳转（只列出你实际建了的页面）、暗色切换、随机漫步，以及可选的皮肤切换。', 'kratos') . '</p>'
                . '</div>',
        ),
        array(
            'id' => 'g_cmdk',
            'type' => 'switcher',
            'title' => __('命令面板', 'kratos'),
            'subtitle' => __('用 ⌘K / Ctrl+K 唤出命令面板', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_cmdk_button',
            'type' => 'switcher',
            'title' => __('页脚唤出按钮', 'kratos'),
            'subtitle' => __('页脚工具栈加一个入口按钮，方便不知道快捷键的访客', 'kratos'),
            'default' => true,
            'dependency' => array('g_cmdk', '==', 'true'),
        ),
        array(
            'id' => 'g_cmdk_placeholder',
            'type' => 'text',
            'title' => __('输入框占位文案', 'kratos'),
            'default' => __('搜索文章，或输入命令…', 'kratos'),
            'dependency' => array('g_cmdk', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('决定面板里出现哪些分组。只影响命令面板，与各功能自己的页脚按钮相互独立。', 'kratos'),
        ),
        array(
            'id' => 'g_cmdk_show_pages',
            'type' => 'switcher',
            'title' => __('展示页面跳转', 'kratos'),
            'subtitle' => __('面板里列出站内页面；个别页面可在「编辑页面 → Kratos-plus 命令面板」里排除', 'kratos'),
            'default' => true,
            'dependency' => array('g_cmdk', '==', 'true'),
        ),
        array(
            'id' => 'g_cmdk_pages_max',
            'type' => 'text',
            'title' => __('普通页面条数上限', 'kratos'),
            'subtitle' => __('特色页之外最多列几个普通页面，填 0 只列特色页。默认 30', 'kratos'),
            'default' => '30',
            'dependency' => array('g_cmdk|g_cmdk_show_pages', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_cmdk_show_dark',
            'type' => 'switcher',
            'title' => __('展示暗色切换', 'kratos'),
            'subtitle' => __('需先开启暗夜模式总开关。与页脚按钮相互独立，关掉页脚按钮时这里就是唯一入口', 'kratos'),
            'default' => true,
            'dependency' => array('g_cmdk', '==', 'true'),
        ),
        array(
            'id' => 'g_cmdk_show_stumble',
            'type' => 'switcher',
            'title' => __('展示随机漫步', 'kratos'),
            'subtitle' => __('需先开启随机漫步总开关，与其页脚按钮相互独立', 'kratos'),
            'default' => true,
            'dependency' => array('g_cmdk', '==', 'true'),
        ),
        array(
            'id' => 'g_cmdk_show_skins',
            'type' => 'switcher',
            'title' => __('展示皮肤切换', 'kratos'),
            'subtitle' => __('默认关闭：皮肤条目多会占满面板。与「前端皮肤切换器」相互独立，只开这一项也能让访客选皮肤', 'kratos'),
            'default' => false,
            'dependency' => array('g_cmdk', '==', 'true'),
        ),
        array(
            'type' => 'subheading',
            'content' => __('搜索行为', 'kratos'),
        ),
        array(
            'id' => 'g_cmdk_search_max',
            'type' => 'text',
            'title' => __('增量搜索结果条数', 'kratos'),
            'subtitle' => __('面板里实时显示几条结果，最大 20，默认 8', 'kratos'),
            'default' => '8',
            'dependency' => array('g_cmdk', '==', 'true'),
        ),
        array(
            'id' => 'g_cmdk_debounce',
            'type' => 'text',
            'title' => __('搜索去抖延时(毫秒)', 'kratos'),
            'subtitle' => __('停手多久后才发请求，避免每敲一个字都查库。最小 80，默认 220', 'kratos'),
            'default' => '220',
            'dependency' => array('g_cmdk', '==', 'true'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_ux',
    'title' => __('搜索结果页', 'kratos'),
    'icon' => 'fas fa-search',
    'fields' => array(
        array(
            'id' => 'g_search_enhance',
            'type' => 'switcher',
            'title' => __('增强搜索结果页', 'kratos'),
            'subtitle' => __('独立的搜索结果页：页头卡 + 关键词高亮 + 结果分组 + 零结果推荐', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_search_title',
            'type' => 'text',
            'title' => __('结果页标题', 'kratos'),
            'default' => __('搜索结果', 'kratos'),
            'dependency' => array('g_search_enhance', '==', 'true'),
        ),
        array(
            'id' => 'g_search_placeholder',
            'type' => 'text',
            'title' => __('搜索框占位文案', 'kratos'),
            'default' => __('换个词再试试…', 'kratos'),
            'dependency' => array('g_search_enhance', '==', 'true'),
        ),
        array(
            'id' => 'g_search_highlight',
            'type' => 'switcher',
            'title' => __('关键词高亮', 'kratos'),
            'subtitle' => __('标题与摘要中给命中词加底色', 'kratos'),
            'default' => true,
            'dependency' => array('g_search_enhance', '==', 'true'),
        ),
        array(
            'id' => 'g_search_shuoshuo',
            'type' => 'switcher',
            'title' => __('搜索结果包含说说', 'kratos'),
            'subtitle' => __('说说默认不进 WordPress 搜索，开启后单独查一次并分组展示', 'kratos'),
            'default' => true,
            'dependency' => array('g_search_enhance', '==', 'true'),
        ),
        array(
            'id' => 'g_search_shuoshuo_max',
            'type' => 'text',
            'title' => __('说说结果条数', 'kratos'),
            'default' => '5',
            'dependency' => array('g_search_shuoshuo|g_search_enhance', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_search_series',
            'type' => 'switcher',
            'title' => __('搜索结果包含系列', 'kratos'),
            'subtitle' => __('匹配系列名与描述，单独分组展示', 'kratos'),
            'default' => true,
            'dependency' => array('g_search_enhance', '==', 'true'),
        ),
        array(
            'id' => 'g_search_series_max',
            'type' => 'text',
            'title' => __('系列结果条数', 'kratos'),
            'default' => '6',
            'dependency' => array('g_search_series|g_search_enhance', '==|==', 'true|true'),
        ),
        array(
            'id' => 'g_search_empty_tags',
            'type' => 'text',
            'title' => __('零结果推荐标签数', 'kratos'),
            'subtitle' => __('零结果时推荐几个热门标签，填 0 为不推荐', 'kratos'),
            'default' => '12',
            'dependency' => array('g_search_enhance', '==', 'true'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_ux',
    'title' => __('随机漫步', 'kratos'),
    'icon' => 'fas fa-random',
    'fields' => array(
        array(
            'id' => 'g_stumble',
            'type' => 'switcher',
            'title' => __('随机漫步', 'kratos'),
            'subtitle' => __('随机跳到一篇被埋没的老文章。关闭只隐藏入口，[stumble] 短码始终可用', 'kratos'),
            'default' => true,
        ),
        array(
            'id' => 'g_stumble_button',
            'type' => 'switcher',
            'title' => __('展示页脚按钮', 'kratos'),
            'subtitle' => __('页脚工具栈显示「随机漫步」按钮', 'kratos'),
            'default' => true,
            'dependency' => array('g_stumble', '==', 'true'),
        ),
        array(
            'id' => 'g_stumble_cmdk_hint',
            'type' => 'content',
            'content' => '<div style="padding:10px 12px;background:#eef4fb;border:1px solid #cfe0f2;border-radius:8px;line-height:1.8;color:#3f5f80;">'
                . __('命令面板里的「随机漫步」入口在 <strong>全站配置 → 命令面板 → 展示随机漫步</strong>，与这里的页脚按钮相互独立。', 'kratos')
                . '</div>',
            'dependency' => array('g_stumble', '==', 'true'),
        ),
        array(
            'id' => 'g_stumble_min_age',
            'type' => 'text',
            'title' => __('老文章阈值(天)', 'kratos'),
            'subtitle' => __('只在发布超过这些天的文章里随机，填 0 为不限。默认 180', 'kratos'),
            'default' => '180',
            'dependency' => array('g_stumble', '==', 'true'),
        ),
        array(
            'id' => 'g_stumble_pool_size',
            'type' => 'text',
            'title' => __('候选池大小', 'kratos'),
            'subtitle' => __('每天取评论最少、发布最早的 N 篇组池再随机；越大越偏冷门。默认 200', 'kratos'),
            'default' => '200',
            'dependency' => array('g_stumble', '==', 'true'),
        ),
    ),
));

CSF::createSection($prefix, array(
    'id' => 'kp_system',
    'title' => __('系统维护', 'kratos'),
    'icon' => 'fas fa-tools',
));

CSF::createSection($prefix, array(
    'parent' => 'kp_system',
    'title' => __('版本更新', 'kratos'),
    'icon' => 'fas fa-cloud-download-alt',
    'fields' => array(
        array(
            'type' => 'subheading',
            'content' => __('更新下载源', 'kratos'),
        ),
        array(
            'id' => 'g_update_source',
            'type' => 'select',
            'title' => __('主题更新下载源', 'kratos'),
            'subtitle' => __('升级包从哪里下载；自动模式按站点时区判断，国内走 Gitee，其他走 GitHub', 'kratos'),
            'options' => array(
                'auto'   => __('自动（按时区，推荐）', 'kratos'),
                'github' => __('强制 GitHub', 'kratos'),
                'gitee'  => __('强制 Gitee（国内加速）', 'kratos'),
            ),
            'default' => 'auto',
        ),
        array(
            'type' => 'subheading',
            'content' => __('版本检查', 'kratos'),
        ),
        array(
            'type' => 'content',
            'content' => function_exists('kratos_render_update_section') ? kratos_render_update_section() : '',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_system',
    'title' => __('备份恢复', 'kratos'),
    'icon' => 'fas fa-undo',
    'fields' => array(
        array(
            'type' => 'backup',
        ),
    ),
));

CSF::createSection($prefix, array(
    'parent' => 'kp_system',
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
                . '<li>' . __('主题名称：', 'kratos') . 'Kratos-plus</li>'
                . '<li>' . __('主题版本：', 'kratos') . THEME_VERSION . '</li>'
                . '<li>' . __('PHP 版本：', 'kratos') . PHP_VERSION . '</li>'
                . '<li>' . __('WordPress 版本：', 'kratos') . $wp_version . '</li>'
                . '<li>' . __('User Agent 信息：', 'kratos') . '<span id="user-agent"></span></li>'
                . '</ul><script>document.getElementById("user-agent").textContent = navigator.userAgent;</script>',
        ),
        array(
            'type' => 'subheading',
            'content' => __('主题论坛', 'kratos'),
        ),
        array(
            'type' => 'content',
            'content' => '<div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding:20px 24px;background:linear-gradient(135deg,#f2f8ff 0%,#e6f1ff 100%);border-radius:14px;border:1px solid #cfe3fb;">'
                . '<div style="flex:1 1 240px;min-width:240px;color:#2f4a66;line-height:1.7;">'
                . '<p style="margin:0 0 6px;font-size:16px;font-weight:600;color:#2874d0;">' . __('Kratos-plus 主题论坛', 'kratos') . '</p>'
                . '<p style="margin:0 0 4px;font-size:13px;">' . __('遇到使用问题、想反馈 Bug 或提交功能建议，都欢迎到论坛发帖交流。', 'kratos') . '</p>'
                . '<p style="margin:0;font-size:13px;color:#5b7799;">' . __('也可以在论坛里分享你的建站配置与皮肤搭配，和其他站长互相取经。', 'kratos') . '</p>'
                . '</div>'
                . '<div style="flex:0 0 auto;">'
                . '<a href="https://bbs.lifengdi.com/" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:10px 22px;background:#2874d0;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600;box-shadow:0 4px 14px rgba(40,116,208,0.24);">' . __('前往主题论坛', 'kratos') . ' &rarr;</a>'
                . '<p style="margin:8px 0 0;font-size:12px;color:#5b7799;text-align:center;">bbs.lifengdi.com</p>'
                . '</div>'
                . '</div>',
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
                '<p>本主题 <strong>Kratos-plus</strong> 由 <a href="https://www.lifengdi.com" target="_blank">Dylan Li</a> 在 <a href="https://github.com/seatonjiang/kratos" target="_blank">Kratos</a> 主题（原作者 Seaton Jiang）的基础上二次开发，新增可视化代码高亮、布局自定义、评论数学验证码等功能。</p>'
                . '<p>本主题继承原主题 <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank">GNU GPL-3.0</a> 协议许可，原作者及所有引用第三方组件的版权署名均予以保留。再次分发须遵守 GPL-3.0 协议要求，包括开源、保留版权声明和许可信息。</p>',
                'kratos'
            ),
        ),
    ),
));
