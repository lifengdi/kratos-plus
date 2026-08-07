<?php
/**
 * 自定义登录页（wp-login.php 接管）
 * 通过 login_enqueue_scripts / login_header / login_footer / login_message / login_headerurl
 * 等钩子重塑 wp-login.php 的视觉与结构，保留 WordPress 原生的表单处理、nonce、错误提示流程。
 *
 * @package Kratos+
 */

if (!defined('ABSPATH')) exit;

/** 是否启用自定义登录页 */
function kratos_login_enabled() {
    return (bool) kratos_option('g_login_enable', true);
}

/** 令牌替换：{posts}/{tags}/{comments}/{users} */
function kratos_login_replace_tokens($text) {
    if ($text === '' || strpos($text, '{') === false) return $text;
    $posts    = wp_count_posts()->publish ?? 0;
    $tags     = wp_count_terms(array('taxonomy' => 'post_tag', 'hide_empty' => false));
    if (is_wp_error($tags)) $tags = 0;
    $comments = wp_count_comments()->approved ?? 0;
    $users    = count_users();
    $users    = isset($users['total_users']) ? $users['total_users'] : 0;
    $map = array(
        '{posts}'    => number_format_i18n($posts),
        '{tags}'     => number_format_i18n((int) $tags),
        '{comments}' => number_format_i18n($comments),
        '{users}'    => number_format_i18n($users),
    );
    return strtr($text, $map);
}

/** 入队登录页 CSS/JS */
function kratos_login_enqueue() {
    if (!kratos_login_enabled()) return;
    wp_enqueue_style('kratos-login', get_template_directory_uri() . '/assets/css/login.css', array(), THEME_VERSION);
    wp_enqueue_script('kratos-login', get_template_directory_uri() . '/assets/js/login.js', array(), THEME_VERSION, true);

    // 主题色跟随全站皮肤：把当前主题的 accent/heading 等注入到登录页
    $accent = '#2F3A2E';
    $inline = ":root{--kr-login-accent:{$accent};}";
    wp_add_inline_style('kratos-login', $inline);
}
add_action('login_enqueue_scripts', 'kratos_login_enqueue');

/** 登录页 Logo 链接改为首页 */
function kratos_login_headerurl() {
    return home_url('/');
}
add_filter('login_headerurl', 'kratos_login_headerurl');

/** 登录页 Logo alt 文本改为站点名 */
function kratos_login_headertext() {
    return get_bloginfo('name');
}
add_filter('login_headertext', 'kratos_login_headertext');

/** 在 <body> 起始处（login_header 早期）注入品牌栏 + 顶部返回首页按钮 */
function kratos_login_render_brand() {
    if (!kratos_login_enabled()) return;

    $show_brand = (bool) kratos_option('g_login_brand_show', true);

    echo '<button type="button" class="kratos-login-theme-toggle" id="kratosLoginThemeToggle" aria-label="' . esc_attr__('切换主题', 'kratos') . '">'
        . '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>'
        . '</button>';

    if (!$show_brand) return;

    $bg_img   = trim((string) kratos_option('g_login_brand_bg', ''));
    $eyebrow  = (string) kratos_option('g_login_brand_eyebrow', 'WELCOME · 欢迎回来');
    $title    = (string) kratos_option('g_login_brand_title', '在这里，<em>写下</em><br>属于你的每日思绪。');
    $desc     = (string) kratos_option('g_login_brand_desc', 'Kratos+ 是一款为写作者打造的 WordPress 主题，简洁、有序、可自定义。登录后开始你的创作之旅。');

    $stat_defaults = array(
        1 => array('v' => '{posts}',    'l' => 'ARTICLES'),
        2 => array('v' => '{tags}',     'l' => 'TAGS'),
        3 => array('v' => '{comments}', 'l' => 'COMMENTS'),
    );
    $stats = array();
    for ($i = 1; $i <= 3; $i++) {
        if (!kratos_option('g_login_stat_' . $i . '_show', true)) continue;
        $val = kratos_login_replace_tokens((string) kratos_option('g_login_stat_' . $i . '_value', $stat_defaults[$i]['v']));
        $lab = (string) kratos_option('g_login_stat_' . $i . '_label', $stat_defaults[$i]['l']);
        if ($val === '' && $lab === '') continue;
        $stats[] = array('v' => $val, 'l' => $lab);
    }

    $style = '';
    if ($bg_img !== '') {
        $style = ' style="background-image:linear-gradient(rgba(30,26,20,.35),rgba(30,26,20,.35)),url(' . esc_url($bg_img) . ');background-size:cover;background-position:center;"';
    }

    echo '<aside class="kratos-login-brand"' . $style . '>';
    echo '<a class="kratos-login-brand-logo" href="' . esc_url(home_url('/')) . '" title="' . esc_attr__('返回首页', 'kratos') . '"><span class="dot"></span>' . esc_html(get_bloginfo('name')) . '</a>';
    echo '<div class="kratos-login-brand-hero">';
    if ($eyebrow !== '') echo '<div class="kratos-login-brand-eyebrow">' . esc_html($eyebrow) . '</div>';
    if ($title !== '')   echo '<h1 class="kratos-login-brand-title">' . wp_kses_post($title) . '</h1>';
    if ($desc !== '')    echo '<p class="kratos-login-brand-desc">' . esc_html($desc) . '</p>';
    echo '</div>';
    if ($stats) {
        echo '<div class="kratos-login-brand-meta">';
        foreach ($stats as $s) {
            echo '<div><strong>' . esc_html($s['v']) . '</strong><span>' . esc_html($s['l']) . '</span></div>';
        }
        echo '</div>';
    }
    echo '</aside>';

    echo '<section class="kratos-login-panel">';
}
add_action('login_header', 'kratos_login_render_brand', 1);

/** 版权小字：紧跟在提交按钮之后（在 #login 内部） */
function kratos_login_render_foot_note() {
    if (!kratos_login_enabled()) return;
    $note = (string) kratos_option('g_login_footer_note', '© Kratos+ · 由 Dylan Li 二次开发');
    if ($note === '') return;
    echo '<div class="kratos-login-foot-note">' . esc_html($note) . '</div>';
}
add_action('login_footer', 'kratos_login_render_foot_note', 5);

/** 关闭 panel 容器（放到最晚，确保在 </div id=login> 之后） */
function kratos_login_close_panel() {
    if (!kratos_login_enabled()) return;
    echo '</section>';
}
add_action('login_footer', 'kratos_login_close_panel', 9999);

/** 在表单顶部注入 Tabs（登录 / 注册）与副标题 */
function kratos_login_inject_tabs($message) {
    if (!kratos_login_enabled()) return $message;

    // 注意：wp-login.php 里的 $action 是文件作用域局部变量，不是 global；
    // 从 $_REQUEST 直接读，兼容 GET/POST 两种请求。
    $act = '';
    if (isset($_REQUEST['action'])) {
        $act = sanitize_key(wp_unslash($_REQUEST['action']));
    }
    // 兼容 checkemail：/wp-login.php?checkemail=confirm|registered|newpass 无 action 但语义属找回/注册回执
    if ($act === '' && isset($_REQUEST['checkemail'])) {
        $act = 'checkemail';
    }
    // 归一化 action → 三个大流程之一：login / register / lostpassword
    // rp/resetpass/checkemail 属于找回密码后续步骤；空 action 或 login 视为登录
    $lost_actions = array('lostpassword', 'retrievepassword', 'rp', 'resetpass', 'checkemail');
    if (in_array($act, $lost_actions, true)) {
        $current = 'lostpassword';
    } elseif ($act === 'register') {
        $current = 'register';
    } elseif ($act === '' || $act === 'login') {
        $current = 'login';
    } else {
        $current = ''; // 未知 action（如 confirm_admin_email、logout 回执等）不高亮任何 tab
    }
    $show_reg = (bool) kratos_option('g_login_register_show', true) && get_option('users_can_register');

    $subs = array(
        'login'        => __('使用你的账号继续访问', 'kratos'),
        'register'     => __('创建账号，加入社区', 'kratos'),
        'lostpassword' => __('输入邮箱，我们会发送重置链接', 'kratos'),
    );
    $sub = isset($subs[$current]) ? $subs[$current] : $subs['login'];

    $is_lost = ($current === 'lostpassword');

    $html  = '<div class="kratos-login-head">';
    $html .= '<div class="kratos-login-tabs">';
    $html .= '<a class="kratos-login-tab' . ($current === 'login' ? ' is-active' : '') . '" href="' . esc_url(wp_login_url()) . '">' . esc_html__('登录', 'kratos') . '</a>';
    if ($show_reg) {
        $html .= '<a class="kratos-login-tab' . ($current === 'register' ? ' is-active' : '') . '" href="' . esc_url(wp_registration_url()) . '">' . esc_html__('注册', 'kratos') . '</a>';
    }
    if ($is_lost) {
        $html .= '<span class="kratos-login-tab is-active">' . esc_html__('找回密码', 'kratos') . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="kratos-login-sub">' . esc_html($sub) . '</div>';
    $html .= '</div>';

    return $html . $message;
}
add_filter('login_message', 'kratos_login_inject_tabs');

/** ============ 自定义登录 URL ============ */
function kratos_login_custom_url_enabled() {
    return kratos_login_enabled() && (bool) kratos_option('g_login_custom_url_enabled', false);
}
function kratos_login_custom_slug() {
    $slug = (string) kratos_option('g_login_custom_url_slug', 'sign-in');
    $slug = strtolower(trim($slug, "/ \t\n\r\0\x0B"));
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    return $slug !== '' ? $slug : 'sign-in';
}

/** 让 wp_login_url() / wp_registration_url() / wp_lostpassword_url() 输出自定义 URL */
function kratos_login_filter_url($url) {
    if (!kratos_login_custom_url_enabled()) return $url;
    $slug = kratos_login_custom_slug();
    $url  = str_replace(site_url('wp-login.php'), home_url('/' . $slug . '/'), $url);
    return $url;
}
add_filter('login_url',          'kratos_login_filter_url', 10, 1);
add_filter('logout_url',         'kratos_login_filter_url', 10, 1);

/** 退出后跳到首页，避免 wp-login.php?loggedout=true 被 404 拦截 */
add_filter('logout_redirect', function ($redirect_to, $requested, $user) {
    if (!kratos_login_custom_url_enabled()) return $redirect_to;
    if (empty($redirect_to) || strpos($redirect_to, 'wp-login.php') !== false) {
        return home_url('/');
    }
    return $redirect_to;
}, 10, 3);
add_filter('lostpassword_url',   'kratos_login_filter_url', 10, 1);
add_filter('register_url',       'kratos_login_filter_url', 10, 1);
add_filter('site_url', 'kratos_login_filter_scheme_url', 10, 3);
add_filter('network_site_url', 'kratos_login_filter_scheme_url', 10, 3);
function kratos_login_filter_scheme_url($url, $path, $scheme) {
    if (!kratos_login_custom_url_enabled()) return $url;
    if ($scheme !== 'login' && $scheme !== 'login_post') return $url;
    if (strpos($url, 'wp-login.php') === false) return $url;
    $slug = kratos_login_custom_slug();
    return str_replace('wp-login.php', $slug, $url);
}

/** 拦截：命中自定义 slug → 走 wp-login.php；直连 wp-login.php GET → 404 */
function kratos_login_intercept() {
    if (!kratos_login_custom_url_enabled()) return;

    global $pagenow;
    $slug = kratos_login_custom_slug();
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = trim((string) parse_url($request_uri, PHP_URL_PATH), '/');
    $path_parts = explode('/', $path);
    $last = end($path_parts);

    // 命中自定义 slug → 重写为 wp-login.php 内部继续处理（无论是否已登录，logout/resetpass 都要能走通）
    if ($last === $slug || $path === $slug) {
        // 已登录用户访问登录页（无 action 或 action=login 且未要求 reauth）→ 直接跳到管理后台，
        // 避免登录成功后再次访问自定义登录 URL 仍显示登录表单
        if (is_user_logged_in() && empty($_REQUEST['reauth'])) {
            $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
            if ($action === '' || $action === 'login') {
                $redirect_to = !empty($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : admin_url();
                wp_safe_redirect($redirect_to);
                exit;
            }
        }
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        $target = ABSPATH . 'wp-login.php';
        if (file_exists($target)) {
            $_SERVER['REQUEST_URI']  = '/wp-login.php' . ($qs ? ('?' . $qs) : '');
            $_SERVER['SCRIPT_NAME']  = '/wp-login.php';
            $_SERVER['PHP_SELF']     = '/wp-login.php';
            // require 会把 wp-login.php 的文件级变量落到本函数作用域；
            // PHP 8+ 下部分渲染分支未先赋值即使用 $user_login / $error 会告警，
            // 这里预置为空，wp-login.php 自身的赋值会覆盖，不影响行为。
            $user_login = '';
            $error = '';
            require $target;
            exit;
        }
    }

    // 已登录用户不再 404 wp-login.php（避免自锁）
    if (is_user_logged_in()) return;

    // 直连 wp-login.php → 404
    if ($pagenow === 'wp-login.php') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

        $action = isset($_GET['action']) ? $_GET['action'] : '';
        // 仅放行"必须直连 wp-login.php"的 action：
        // - logout / postpass：POST 之外的 GET 回执
        // - rp / resetpass：邮件里的密码重置一次性链接（带 nonce）
        // - confirm_admin_email：管理员邮箱确认链接
        // register / lostpassword / retrievepassword 走自定义 slug（/{slug}/?action=...），
        // 直连 wp-login.php 时统一 404，避免自定义 URL 的隐藏被这两个 action 旁路。
        $allow_actions = array('logout', 'postpass', 'rp', 'resetpass', 'confirm_admin_email');
        if (in_array($action, $allow_actions, true)) return;
        if (!empty($_GET['loggedout'])) return;
        if (!empty($_GET['key']) && !empty($_GET['login'])) return;

        kratos_login_send_404();
    }
}
add_action('init', 'kratos_login_intercept', 1);

/** 未登录访问 /wp-admin → 404（放行 admin-ajax / admin-post 等无需登录的入口）
 *
 *  必须挂在 init：wp-admin/admin.php 的顺序是
 *  require wp-load.php（内部 do_action('init')）→ auth_redirect() → do_action('admin_init')，
 *  未登录用户在 auth_redirect() 就被重定向走了，admin_init 永远不会触发。
 *  admin_init 上再挂一次仅作兜底（例如某些直接进入的 wp-admin 脚本不走 admin.php 的鉴权分支）。 */
function kratos_login_block_admin() {
    if (!kratos_login_custom_url_enabled()) return;
    if (is_user_logged_in()) return;
    if (!is_admin()) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (defined('DOING_CRON') && DOING_CRON) return;
    if (defined('WP_INSTALLING') && WP_INSTALLING) return;

    // 这些入口设计上允许未登录访问（AJAX / 表单回调 / 安装升级），不能 404
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $allow  = array('admin-ajax.php', 'admin-post.php', 'install.php', 'upgrade.php', 'load-scripts.php', 'load-styles.php');
    if (in_array($script, $allow, true)) return;

    kratos_login_send_404();
}
add_action('init', 'kratos_login_block_admin', 1);
add_action('admin_init', 'kratos_login_block_admin', 0);

/** 重定向到一个 WordPress 天然 404 的路径，让浏览器地址栏切换并走标准 404 渲染流程
 *  （避免 init 阶段 inline include 404.php 时主查询未执行导致模板异常，
 *  同时让地址栏不再停留在原始被拦截的 URL，如 /wp-login.php） */
function kratos_login_send_404() {
    nocache_headers();
    wp_safe_redirect(home_url('/404'), 302);
    exit;
}

/** ============ 数字验证码 ============ */
function kratos_login_captcha_enabled() {
    return kratos_login_enabled() && (bool) kratos_option('g_login_captcha_enabled', false);
}
function kratos_login_captcha_max() {
    return max(5, (int) kratos_option('g_login_captcha_max', 20));
}
function kratos_login_captcha_new() {
    $max = kratos_login_captcha_max();
    $x = wp_rand(1, $max);
    $y = wp_rand(1, $max);
    $ops = array('+', '-');
    $op = $ops[array_rand($ops)];
    if ($op === '-' && $y > $x) { list($x, $y) = array($y, $x); }
    $answer = ($op === '+') ? $x + $y : $x - $y;
    $token  = wp_generate_password(16, false, false);
    set_transient('kratos_login_captcha_' . $token, (string) $answer, 10 * MINUTE_IN_SECONDS);
    return array($token, $x, $y, $op);
}

/** 在表单末尾追加验证码 + 蜜罐字段 */
function kratos_login_form_extra() {
    if (!kratos_login_enabled()) return;

    // 蜜罐
    if ((bool) kratos_option('g_login_honeypot_enabled', true)) {
        echo '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">';
        echo '<label for="kratos_login_hp_email">Email (leave empty)</label>';
        echo '<input type="text" id="kratos_login_hp_email" name="kratos_login_hp_email" value="" autocomplete="off" tabindex="-1">';
        echo '<input type="hidden" name="kratos_login_hp_ts" value="' . esc_attr(time()) . '">';
        echo '</div>';
    }

    // 数字验证码
    if (kratos_login_captcha_enabled()) {
        list($token, $x, $y, $op) = kratos_login_captcha_new();
        $sym = $op === '+' ? '+' : '−';
        ?>
        <p class="kratos-login-captcha">
            <label for="kratos_login_captcha"><?php esc_html_e('数字验证', 'kratos'); ?></label>
            <span class="kratos-login-captcha-row">
                <span class="kratos-login-captcha-q"><?php echo (int)$x . ' ' . esc_html($sym) . ' ' . (int)$y; ?> =</span>
                <input type="text" inputmode="numeric" pattern="-?[0-9]*" name="kratos_login_captcha" id="kratos_login_captcha" class="input" autocomplete="off" required />
                <input type="hidden" name="kratos_login_captcha_token" value="<?php echo esc_attr($token); ?>">
            </span>
        </p>
        <?php
    }
}
add_action('login_form',          'kratos_login_form_extra');
add_action('register_form',       'kratos_login_form_extra');
add_action('lostpassword_form',   'kratos_login_form_extra');

/** 校验：登录 */
function kratos_login_authenticate($user, $username, $password) {
    if (!kratos_login_enabled()) return $user;
    // 只在有实际登录请求时校验
    if (empty($_POST) || empty($username) && empty($password)) return $user;
    $err = kratos_login_check_bot_and_captcha();
    if ($err) return $err;
    return $user;
}
add_filter('authenticate', 'kratos_login_authenticate', 30, 3);

/** 校验：注册 */
function kratos_login_register_check($errors, $sanitized_user_login, $user_email) {
    if (!kratos_login_enabled()) return $errors;
    $err = kratos_login_check_bot_and_captcha();
    if (is_wp_error($err)) {
        foreach ($err->get_error_codes() as $code) {
            $errors->add($code, $err->get_error_message($code));
        }
    }
    return $errors;
}
add_filter('registration_errors', 'kratos_login_register_check', 10, 3);

/** 校验：找回密码 */
function kratos_login_lostpw_check($errors) {
    if (!kratos_login_enabled()) return $errors;
    if (empty($_POST['user_login'])) return $errors;
    $err = kratos_login_check_bot_and_captcha();
    if (is_wp_error($err)) {
        foreach ($err->get_error_codes() as $code) {
            $errors->add($code, $err->get_error_message($code));
        }
    }
    return $errors;
}
add_action('lostpassword_post', 'kratos_login_lostpw_check');

/** 蜜罐 + 验证码统一校验 */
function kratos_login_check_bot_and_captcha() {
    // 蜜罐
    if ((bool) kratos_option('g_login_honeypot_enabled', true)) {
        $trap = isset($_POST['kratos_login_hp_email']) ? trim(wp_unslash($_POST['kratos_login_hp_email'])) : '';
        if ($trap !== '') {
            return new WP_Error('kratos_login_bot', __('<strong>错误</strong>：请求异常，请刷新页面重试。', 'kratos'));
        }
        $min = max(1, (int) kratos_option('g_login_honeypot_min_seconds', 2));
        $ts  = isset($_POST['kratos_login_hp_ts']) ? (int) $_POST['kratos_login_hp_ts'] : 0;
        if ($ts > 0 && (time() - $ts) < $min) {
            return new WP_Error('kratos_login_too_fast', __('<strong>错误</strong>：提交过快，请稍候再试。', 'kratos'));
        }
    }

    // 数字验证码
    if (kratos_login_captcha_enabled()) {
        $token  = isset($_POST['kratos_login_captcha_token']) ? sanitize_text_field(wp_unslash($_POST['kratos_login_captcha_token'])) : '';
        $answer = isset($_POST['kratos_login_captcha']) ? trim(wp_unslash($_POST['kratos_login_captcha'])) : '';
        if ($token === '' || $answer === '') {
            return new WP_Error('kratos_login_captcha_empty', __('<strong>错误</strong>：请填写数字验证结果。', 'kratos'));
        }
        $expected = get_transient('kratos_login_captcha_' . $token);
        delete_transient('kratos_login_captcha_' . $token);
        if ($expected === false) {
            return new WP_Error('kratos_login_captcha_expired', __('<strong>错误</strong>：验证码已过期，请刷新页面重试。', 'kratos'));
        }
        if (!hash_equals((string) $expected, (string) $answer)) {
            return new WP_Error('kratos_login_captcha_wrong', __('<strong>错误</strong>：数字验证结果不正确。', 'kratos'));
        }
    }
    return null;
}

/** 给 body 添加自定义 class 便于 CSS 命中 */
function kratos_login_body_class($classes) {
    if (!kratos_login_enabled()) return $classes;
    $classes[] = 'kratos-login';
    if ((bool) kratos_option('g_login_brand_show', true)) {
        $classes[] = 'kratos-login-has-brand';
    }
    return $classes;
}
add_filter('login_body_class', 'kratos_login_body_class');
