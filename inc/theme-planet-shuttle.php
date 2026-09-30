<?php

/**
 * 星球穿梭 Planet Shuttle —— 到勾选分类下的友链中随机穿梭一个
 *
 * - 端点：自定义 slug（默认 /shuttle/），由 rewrite 规则映射到 kratos_shuttle=1
 * - 过渡页展示本站信息 + 目标站点信息，倒计时后 JS 自动 replace 跳转
 * - 页面不索引；容器 class 走 khs 命名空间，皮肤零改动自动适配
 * - `?to=<link_id>` 可指定目标（前提：属于勾选分类白名单），否则从白名单里随机
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

function kratos_shuttle_slug()
{
    $raw = function_exists('kratos_option') ? kratos_option('g_shuttle_slug', 'shuttle') : 'shuttle';
    $slug = sanitize_title($raw);
    return $slug !== '' ? $slug : 'shuttle';
}

function kratos_shuttle_allowed_categories()
{
    $raw = function_exists('kratos_option') ? kratos_option('g_shuttle_categories', array()) : array();
    if (!is_array($raw)) {
        $raw = array($raw);
    }
    return array_values(array_filter(array_map('intval', $raw)));
}

function kratos_shuttle_url()
{
    return esc_url(home_url('/' . kratos_shuttle_slug() . '/'));
}

// 注册 query var + rewrite
add_filter('query_vars', function ($vars) {
    $vars[] = 'kratos_shuttle';
    return $vars;
});

add_action('init', function () {
    $slug = kratos_shuttle_slug();
    add_rewrite_rule('^' . preg_quote($slug, '/') . '/?$', 'index.php?kratos_shuttle=1', 'top');
});

// slug 变更时自动 flush（值变化才刷，避免每次保存都刷）
add_action('csf_kratos_options_save_after', function () {
    $current = kratos_shuttle_slug();
    $last = get_option('kratos_shuttle_slug_cache');
    if ($current !== $last) {
        update_option('kratos_shuttle_slug_cache', $current);
        flush_rewrite_rules(false);
    }
});

// body class
add_filter('body_class', function ($classes) {
    if (get_query_var('kratos_shuttle')) {
        $classes[] = 'is-kratos-shuttle-page';
    }
    return $classes;
});

// 端点渲染
add_action('template_redirect', function () {
    if (!get_query_var('kratos_shuttle')) {
        return;
    }

    if (!function_exists('kratos_option') || !kratos_option('g_shuttle_enable', true)) {
        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    status_header(200);
    nocache_headers();
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow', true);
    }

    // 目标挑选
    $forced_id = isset($_GET['to']) ? (int) $_GET['to'] : 0;
    $target = kratos_shuttle_pick($forced_id);

    // 载入渲染模板
    kratos_shuttle_render($target);
    exit;
});

/**
 * 挑选一个目标友链。
 *
 * @param int $forced_id 若给定且属于白名单分类，则强制选它
 * @return object|null   WP bookmark 对象；无候选时返回 null
 */
function kratos_shuttle_pick($forced_id = 0)
{
    $cats = kratos_shuttle_allowed_categories();
    if (empty($cats)) {
        return null;
    }

    if ($forced_id > 0) {
        $bm = get_bookmark($forced_id);
        if ($bm && !empty($bm->link_url)) {
            $bm_terms = wp_get_object_terms($bm->link_id, 'link_category', array('fields' => 'ids'));
            if (!is_wp_error($bm_terms) && array_intersect($cats, $bm_terms)) {
                return $bm;
            }
        }
    }

    $bookmarks = get_bookmarks(array(
        'category'        => implode(',', $cats),
        'orderby'         => 'rand',
        'limit'           => 1,
        'hide_invisible'  => 1,
    ));
    return !empty($bookmarks) ? $bookmarks[0] : null;
}

/**
 * 输出穿梭过渡页。
 */
function kratos_shuttle_render($target)
{
    $countdown = max(1, (int) kratos_option('g_shuttle_countdown', 5));
    $tip = trim((string) kratos_option('g_shuttle_tip', ''));
    if ($tip === '') {
        $tip = __('即将穿梭至一个未知星球，请系好安全带 ✨', 'kratos');
    }
    $open_blank = (bool) kratos_option('g_shuttle_open_blank', false);

    $site_name = get_bloginfo('name');
    $site_desc = get_bloginfo('description');
    $site_url  = home_url('/');
    $site_logo = '';
    if (function_exists('kratos_option')) {
        $site_logo = kratos_option('g_logo', '');
    }

    // 独立 HTML 骨架（不套 get_header/get_footer，仅通过 wp_head 加载样式与皮肤属性）
    ?><!DOCTYPE html>
<html lang="<?php bloginfo('language'); ?>">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(sprintf(__('星球穿梭 · %s', 'kratos'), $site_name)); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class('kratos-shuttle-standalone'); ?>>
<?php
    if (!$target) { ?>
        <div class="kratos-shuttle-viewport" style="background:<?php echo esc_attr(kratos_option('g_background', '#f5f5f5')); ?>">
            <div class="container">
                <section class="kratos-shuttle kr-body">
                    <header class="kr-hd" style="padding:20px;">
                        <div class="kr-ico"><?php echo kratos_icon('fas fa-satellite'); ?></div>
                        <h1 class="kr-hd-title"><?php _e('星际信号丢失', 'kratos'); ?></h1>
                        <div class="kr-hd-sub"><?php _e('๐·°(৹˃̵﹏˂̵৹)°·๐', 'kratos'); ?></div>
                        <div class="kr-hd-divider"></div>
                    </header>
                    <div class="shuttle-actions">
                        <a class="kr-btn" href="<?php echo esc_url($site_url); ?>"><?php _e('返回首页', 'kratos'); ?></a>
                    </div>
                </section>
            </div>
        </div>
        <?php wp_footer(); ?>
        </body></html>
        <?php
        return;
    }

    // 目标信息
    $t_url = $target->link_url;
    $t_name = $target->link_name;
    $t_desc = $target->link_description;
    $t_img = $target->link_image;
    $t_host = wp_parse_url($t_url, PHP_URL_HOST);
    $t_letter = mb_substr(preg_replace('/^www\./', '', (string) $t_host), 0, 1);
    ?>
    <div class="kratos-shuttle-viewport" style="background:<?php echo esc_attr(kratos_option('g_background', '#f5f5f5')); ?>">
        <div class="container">
            <section class="kratos-shuttle kr-body"
                     data-target="<?php echo esc_attr($t_url); ?>"
                     data-countdown="<?php echo esc_attr($countdown); ?>"
                     data-blank="<?php echo $open_blank ? '1' : '0'; ?>">
                <header class="kr-hd" style="padding:20px;">
                    <div class="kr-ico"><?php echo kratos_icon('fas fa-rocket'); ?></div>
                    <h1 class="kr-hd-title"><?php _e('星球穿梭中', 'kratos'); ?></h1>
                    <div class="kr-hd-sub"><?php echo esc_html($tip); ?></div>
                    <div class="kr-hd-divider"></div>
                </header>

                <div class="shuttle-stage">
                    <div class="shuttle-node shuttle-from kr-card">
                        <div class="shuttle-node-label"><?php _e('出发', 'kratos'); ?></div>
                        <?php if ($site_logo) : ?>
                            <img class="shuttle-node-logo" src="<?php echo esc_url($site_logo); ?>" alt="<?php echo esc_attr($site_name); ?>">
                        <?php else : ?>
                            <div class="shuttle-node-letter kr-ico"><?php echo esc_html(mb_substr($site_name, 0, 1)); ?></div>
                        <?php endif; ?>
                        <div class="shuttle-node-name"><?php echo esc_html($site_name); ?></div>
                        <?php if ($site_desc) : ?>
                            <div class="shuttle-node-desc"><?php echo esc_html($site_desc); ?></div>
                        <?php endif; ?>
                        <div class="shuttle-node-host"><?php echo esc_html(wp_parse_url($site_url, PHP_URL_HOST)); ?></div>
                    </div>

                    <div class="shuttle-track" aria-hidden="true">
                        <span class="shuttle-track-line"></span>
                        <span class="shuttle-track-ship"><?php echo kratos_icon('fas fa-rocket'); ?></span>
                        <span class="shuttle-track-count"><?php echo esc_html($countdown); ?></span>
                    </div>

                    <div class="shuttle-node shuttle-to kr-card">
                        <div class="shuttle-node-label"><?php _e('目标', 'kratos'); ?></div>
                        <?php if ($t_img) : ?>
                            <img class="shuttle-node-logo" src="<?php echo esc_url($t_img); ?>" alt="<?php echo esc_attr($t_name); ?>">
                        <?php else : ?>
                            <div class="shuttle-node-letter kr-ico"><?php echo esc_html(strtoupper($t_letter ?: '?')); ?></div>
                        <?php endif; ?>
                        <div class="shuttle-node-name"><?php echo esc_html($t_name); ?></div>
                        <?php if ($t_desc) : ?>
                            <div class="shuttle-node-desc"><?php echo esc_html($t_desc); ?></div>
                        <?php endif; ?>
                        <div class="shuttle-node-host"><?php echo esc_html($t_host); ?></div>
                    </div>
                </div>

                <div class="shuttle-actions">
                    <button type="button" class="kr-btn shuttle-go"><?php _e('立即穿梭', 'kratos'); ?></button>
                    <button type="button" class="kr-btn shuttle-cancel"><?php _e('返回本站', 'kratos'); ?></button>
                    <a class="kr-btn shuttle-again" href="<?php echo esc_url(kratos_shuttle_url()); ?>"><?php _e('再抽一个', 'kratos'); ?></a>
                </div>
            </section>
        </div>
    </div>

    <meta name="referrer" content="no-referrer-when-downgrade">

    <script>
    (function () {
        var el = document.querySelector('.kratos-shuttle[data-target]');
        if (!el) return;
        var target = el.getAttribute('data-target');
        var blank = el.getAttribute('data-blank') === '1';
        var left = parseInt(el.getAttribute('data-countdown'), 10) || 5;
        var countEl = el.querySelector('.shuttle-track-count');
        var goBtn = el.querySelector('.shuttle-go');
        var cancelBtn = el.querySelector('.shuttle-cancel');
        var cancelled = false;
        var timer;

        function jump() {
            if (cancelled) return;
            if (blank) { window.open(target, '_blank', 'noopener'); location.href = '<?php echo esc_js($site_url); ?>'; }
            else { location.replace(target); }
        }
        function tick() {
            if (cancelled) return;
            left -= 1;
            if (countEl) countEl.textContent = left > 0 ? left : 0;
            if (left <= 0) { clearInterval(timer); jump(); return; }
        }
        timer = setInterval(tick, 1000);

        goBtn && goBtn.addEventListener('click', function () { clearInterval(timer); jump(); });
        cancelBtn && cancelBtn.addEventListener('click', function () {
            cancelled = true; clearInterval(timer);
            if (document.referrer && document.referrer.indexOf(location.host) !== -1) history.back();
            else location.href = '<?php echo esc_js($site_url); ?>';
        });
    })();
    </script>
    <?php wp_footer(); ?>
    </body></html>
    <?php
}

// 短码入口
add_shortcode('shuttle_link', function ($atts) {
    $a = shortcode_atts(array('text' => __('星球穿梭', 'kratos')), $atts, 'shuttle_link');
    return '<a class="kr-btn kratos-shuttle-inline" href="' . kratos_shuttle_url() . '" rel="nofollow">'
        . esc_html($a['text']) . '</a>';
});

// 内联基础样式（形态/布局；配色由 --khs-* 别名系统提供）
add_action('wp_head', function () {
    if (!get_query_var('kratos_shuttle')) {
        return;
    }
    ?>
    <style id="kratos-shuttle-inline">
    body.kratos-shuttle-standalone { margin: 0; padding: 0; min-height: 100vh; }
    .kratos-shuttle-viewport { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 0; }
    .kratos-shuttle-viewport .container { width: 100%; max-width: 1040px; padding: 0 16px; margin: 0 auto; }
    .kratos-shuttle { max-width: 960px; margin: 0 auto; padding: 32px; background: var(--khs-card-bg, #fff); color: var(--khs-fg, #222); border: 1px solid var(--khs-line, #eee); border-radius: 12px; }
    .kratos-shuttle .kr-hd { text-align: center; margin-bottom: 24px; }
    .kratos-shuttle .kr-hd-title { font-size: 26px; margin: 12px 0 6px; }
    .kratos-shuttle .kr-hd-sub { color: var(--khs-fg-dim, #888); font-size: 14px; }
    .kratos-shuttle .kr-hd-divider { width: 48px; height: 3px; background: var(--khs-accent, #4a86e8); margin: 14px auto 0; border-radius: 2px; }
    .kratos-shuttle .kr-ico { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 12px; background: var(--khs-accent, #4a86e8); color: #fff; font-size: 20px; }
    .kratos-shuttle .shuttle-stage { display: grid; grid-template-columns: minmax(0,1fr) 120px minmax(0,1fr); gap: 16px; align-items: stretch; margin: 24px 0; }
    .kratos-shuttle .shuttle-node { padding: 20px; border: 1px solid var(--khs-line, #eee); border-radius: 10px; background: var(--khs-bg-1, #fafafa); text-align: center; display: flex; flex-direction: column; align-items: center; gap: 8px; }
    .kratos-shuttle .shuttle-node-label { font-size: 12px; letter-spacing: 2px; color: var(--khs-fg-dim, #999); text-transform: uppercase; }
    .kratos-shuttle .shuttle-node-logo { width: 64px; height: 64px; border-radius: 12px; object-fit: cover; background: #fff; }
    .kratos-shuttle .shuttle-node-letter { width: 64px; height: 64px; font-size: 26px; font-weight: 600; }
    .kratos-shuttle .shuttle-node-name { font-size: 17px; font-weight: 600; }
    .kratos-shuttle .shuttle-node-desc { font-size: 13px; color: var(--khs-fg-soft, #666); line-height: 1.6; }
    .kratos-shuttle .shuttle-node-host { font-size: 12px; color: var(--khs-fg-dim, #999); font-family: ui-monospace, Menlo, monospace; }
    .kratos-shuttle .shuttle-track { position: relative; display: flex; align-items: center; justify-content: center; min-height: 120px; }
    .kratos-shuttle .shuttle-track-line { position: absolute; left: 0; right: 0; top: 50%; height: 2px; background: repeating-linear-gradient(90deg, var(--khs-accent, #4a86e8) 0 6px, transparent 6px 12px); transform: translateY(-50%); }
    .kratos-shuttle .shuttle-track-ship { position: absolute; top: 50%; left: 0; transform: translate(-50%, -50%); width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--khs-accent, #4a86e8); color: #fff; animation: kratos-shuttle-fly 1.6s linear infinite; }
    @keyframes kratos-shuttle-fly { 0% { left: 0; } 100% { left: 100%; } }
    .kratos-shuttle .shuttle-track-count { position: relative; z-index: 1; display: inline-flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 50%; background: var(--khs-card-bg, #fff); border: 2px solid var(--khs-accent, #4a86e8); color: var(--khs-accent, #4a86e8); font-size: 28px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .kratos-shuttle .shuttle-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-top: 8px; }
    .kratos-shuttle .kr-btn { display: inline-flex; align-items: center; padding: 8px 18px; border-radius: 8px; background: var(--khs-accent, #4a86e8); color: #fff; border: 1px solid transparent; cursor: pointer; font-size: 14px; text-decoration: none; }
    .kratos-shuttle .kr-btn.shuttle-cancel, .kratos-shuttle .kr-btn.shuttle-again { background: transparent; color: var(--khs-accent, #4a86e8); border-color: var(--khs-accent, #4a86e8); }
    .kratos-shuttle .kr-btn:hover { opacity: .9; }
    @media (max-width: 768px) {
        .kratos-shuttle { padding: 20px; margin: 20px 12px; }
        .kratos-shuttle .shuttle-stage { grid-template-columns: 1fr; gap: 10px; }
        .kratos-shuttle .shuttle-track { min-height: 72px; transform: rotate(90deg); width: 72px; margin: 0 auto; }
        .kratos-shuttle .shuttle-track-count { width: 52px; height: 52px; font-size: 22px; transform: rotate(-90deg); }
        .kratos-shuttle .shuttle-node-logo, .kratos-shuttle .shuttle-node-letter { width: 56px; height: 56px; }
    }
    </style>
    <?php
}, 20);
