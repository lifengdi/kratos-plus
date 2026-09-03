<?php

/**
 * 社交分享按钮
 * 文章底部显示一排社交平台分享按钮（微博、微信、Twitter/X、QQ 等）。
 *
 * @author Dylan Li (Kratos-plus) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

function kratos_social_share_platforms()
{
    return array(
        'weibo'   => array('label' => __('微博', 'kratos'), 'icon' => 'fab fa-weibo',       'color' => '#e6162d'),
        'wechat'  => array('label' => __('微信', 'kratos'), 'icon' => 'fab fa-weixin',      'color' => '#07c160'),
        'twitter' => array('label' => 'X',                  'icon' => 'fab fa-x-twitter',   'color' => '#000'),
        'qq'      => array('label' => 'QQ',                 'icon' => 'fab fa-qq',           'color' => '#12b7f5'),
        'douban'  => array('label' => __('豆瓣', 'kratos'), 'icon' => 'fas fa-book',        'color' => '#007722'),
        'linkedin'=> array('label' => 'LinkedIn',           'icon' => 'fab fa-linkedin-in', 'color' => '#0077b5'),
    );
}

/**
 * 输出分享按钮 HTML（在 the_content 末尾追加）。
 */
function kratos_social_share_content($content)
{
    if (!kratos_option('g_social_share', false)) {
        return $content;
    }
    if (!is_singular('post') || !is_main_query()) {
        return $content;
    }

    $enabled = (array) kratos_option('g_social_share_platforms', array('weibo', 'wechat', 'twitter', 'qq'));
    $all     = kratos_social_share_platforms();

    $title   = rawurlencode(get_the_title());
    $url     = rawurlencode(get_permalink());
    // 不能用 get_the_excerpt()：无手写摘要时 wp_trim_excerpt() 会再跑一遍 the_content
    // 过滤器链，本函数就挂在 the_content 上，会无限递归直到内存耗尽。
    $raw     = get_post_field('post_excerpt');
    if ($raw === '') {
        $raw = get_post_field('post_content');
    }
    $summary = rawurlencode(wp_trim_words(wp_strip_all_tags(strip_shortcodes($raw)), 50, '…'));

    $buttons = '';
    foreach ($enabled as $key) {
        if (!isset($all[$key])) continue;
        $p = $all[$key];
        $share_url = '';
        $extra_attr = '';

        switch ($key) {
            case 'weibo':
                $share_url = "https://service.weibo.com/share/share.php?url={$url}&title={$title}";
                break;
            case 'wechat':
                // 微信用二维码弹层，JS 处理
                $extra_attr = ' data-share="wechat"';
                break;
            case 'twitter':
                $share_url = "https://x.com/intent/tweet?url={$url}&text={$title}";
                break;
            case 'qq':
                $share_url = "https://connect.qq.com/widget/shareqq/index.html?url={$url}&title={$title}&summary={$summary}";
                break;
            case 'douban':
                $share_url = "https://www.douban.com/share/service?href={$url}&name={$title}";
                break;
            case 'linkedin':
                $share_url = "https://www.linkedin.com/sharing/share-offsite/?url={$url}";
                break;
        }

        $href = $share_url ? esc_url($share_url) : '#';
        $target = $share_url ? ' target="_blank" rel="noopener noreferrer"' : '';
        $buttons .= '<a class="post-share-btn" href="' . $href . '"' . $target . $extra_attr
            . ' style="--share-color:' . esc_attr($p['color']) . '"'
            . ' title="' . esc_attr($p['label']) . '">'
            . '<i class="' . esc_attr($p['icon']) . '"></i></a>';
    }

    if (empty($buttons)) {
        return $content;
    }

    $html = '<div class="post-share-buttons">'
        . '<span class="post-share-label">' . __('分享到', 'kratos') . '</span>'
        . $buttons
        . '</div>';

    // 微信二维码弹层（QR 用纯 CSS + table 实现，无外部依赖）
    if (in_array('wechat', $enabled, true)) {
        $html .= '<div class="post-share-qr-overlay" style="display:none;">'
            . '<div class="post-share-qr-card">'
            . '<p>' . __('微信扫一扫分享', 'kratos') . '</p>'
            . '<div class="post-share-qr" data-url="' . esc_attr(get_permalink()) . '"></div>'
            . '<button class="post-share-qr-close">&times;</button>'
            . '</div></div>';
    }

    return $content . $html;
}
add_filter('the_content', 'kratos_social_share_content', 98);

function kratos_social_share_assets()
{
    if (!kratos_option('g_social_share', false)) {
        return;
    }
    if (!is_singular('post')) {
        return;
    }

    $css = '
.post-share-buttons { display: flex; align-items: center; gap: 10px; margin: 24px 0 16px; padding-top: 16px; border-top: 1px solid #eee; flex-wrap: wrap; }
.post-share-label { font-size: 13px; color: #999; }
.post-share-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--share-color, #666); color: #fff !important;
    font-size: 16px; text-decoration: none !important; transition: opacity .2s, transform .2s;
}
.post-share-btn:hover { opacity: .85; transform: scale(1.1); }
html[data-theme="dark"] .post-share-buttons { border-top-color: #444; }
.post-share-qr-overlay {
    position: fixed; inset: 0; z-index: 99999;
    background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center;
}
.post-share-qr-card {
    background: #fff; padding: 24px; border-radius: 12px; text-align: center;
    position: relative; min-width: 240px;
}
.post-share-qr-card p { margin: 0 0 12px; font-size: 14px; color: #333; }
.post-share-qr-close {
    position: absolute; top: 8px; right: 12px; border: none; background: none;
    font-size: 24px; color: #999; cursor: pointer; line-height: 1;
}
.post-share-qr canvas { display: block; margin: 0 auto; }
html[data-theme="dark"] .post-share-qr-card { background: #2a2a2a; }
html[data-theme="dark"] .post-share-qr-card p { color: #ddd; }
';
    wp_add_inline_style('kratos', $css);

    // 微信二维码：用轻量级 QR 生成（纯 JS，无外部依赖）
    $enabled = (array) kratos_option('g_social_share_platforms', array('weibo', 'wechat', 'twitter', 'qq'));
    $has_wechat = in_array('wechat', $enabled, true);

    $js = '(function(){';
    if ($has_wechat) {
        // ponytail: 二维码走外部 api.qrserver.com（纯 JS 生成 QR 矩阵体积太大）；
        // 若不接受把文章 URL 交给第三方，改内置编码库替换 img.src 即可。
        $js .= '
function krQR(){
    var c=document.querySelector(".post-share-qr");if(!c||c.firstChild)return;
    var img=document.createElement("img");
    img.width=180;img.height=180;img.alt="QR";img.loading="lazy";
    img.src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data="+encodeURIComponent(c.getAttribute("data-url"));
    c.appendChild(img);
}
document.addEventListener("click",function(e){
    var wb=e.target.closest("[data-share=wechat]");
    if(wb){e.preventDefault();var o=document.querySelector(".post-share-qr-overlay");if(o){o.style.display="flex";krQR();}}
    if(e.target.closest(".post-share-qr-close")||e.target.classList.contains("post-share-qr-overlay")){
        var o=document.querySelector(".post-share-qr-overlay");if(o)o.style.display="none";
    }
});';
    }
    $js .= '})();';
    wp_add_inline_script('kratos', $js);
}
add_action('wp_enqueue_scripts', 'kratos_social_share_assets', 20);
