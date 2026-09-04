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

    // 原生分享按钮默认 hidden，由 JS 特性检测后放出——服务端不做 UA 判断，
    // 否则页面缓存会把某一次访问的判定结果固定给所有设备。
    $native = '<a class="post-share-btn post-share-native" href="#" hidden'
        . ' style="--share-color:#6b7785" title="' . esc_attr__('分享', 'kratos') . '">'
        . '<i class="fas fa-share-alt"></i></a>';

    $html = '<div class="post-share-buttons" data-title="' . esc_attr(get_the_title())
        . '" data-url="' . esc_attr(get_permalink()) . '">'
        . '<span class="post-share-label">' . __('分享到', 'kratos') . '</span>'
        . $native
        . $buttons
        . '</div>';

    // 回退弹层：Web Share API 不可用 / 调用失败时展示标题与链接供手动分享
    $html .= '<div class="post-share-fb-overlay" style="display:none;">'
        . '<div class="post-share-fb-card">'
        . '<p class="post-share-fb-title">' . esc_html(get_the_title()) . '</p>'
        . '<input class="post-share-fb-url" type="text" readonly value="' . esc_attr(get_permalink()) . '">'
        . '<button class="post-share-fb-copy">' . __('复制链接', 'kratos') . '</button>'
        . '<p class="post-share-fb-tip">' . __('也可使用浏览器菜单中的「分享」功能', 'kratos') . '</p>'
        . '<button class="post-share-fb-close">&times;</button>'
        . '</div></div>';

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
.post-share-fb-overlay {
    position: fixed; inset: 0; z-index: 99999;
    background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; padding: 20px;
}
.post-share-fb-card {
    background: #fff; padding: 24px 20px 16px; border-radius: 12px;
    position: relative; width: 100%; max-width: 320px; text-align: center;
}
.post-share-fb-title { margin: 0 0 12px; font-size: 15px; font-weight: 600; color: #333; line-height: 1.5; }
.post-share-fb-url {
    width: 100%; box-sizing: border-box; padding: 8px 10px; margin: 0 0 12px;
    border: 1px solid #ddd; border-radius: 6px; background: #f7f7f7;
    font-size: 13px; color: #555;
}
.post-share-fb-copy {
    width: 100%; padding: 9px 0; border: none; border-radius: 6px;
    background: #6b7785; color: #fff; font-size: 14px; cursor: pointer;
}
.post-share-fb-tip { margin: 12px 0 0; font-size: 12px; color: #999; }
.post-share-fb-close {
    position: absolute; top: 6px; right: 12px; border: none; background: none;
    font-size: 24px; color: #999; cursor: pointer; line-height: 1;
}
html[data-theme="dark"] .post-share-fb-card { background: #2a2a2a; }
html[data-theme="dark"] .post-share-fb-title { color: #eee; }
html[data-theme="dark"] .post-share-fb-url { background: #1f1f1f; border-color: #444; color: #bbb; }
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
    // 移动端扫不了自己屏幕上的二维码，改走链接回退弹层
    if(wb){e.preventDefault();if(coarse)return fbOpen();
        var o=document.querySelector(".post-share-qr-overlay");if(o){o.style.display="flex";krQR();}}
    if(e.target.closest(".post-share-qr-close")||e.target.classList.contains("post-share-qr-overlay")){
        var o=document.querySelector(".post-share-qr-overlay");if(o)o.style.display="none";
    }
});';
    }
    // 原生分享：全部判断放前端运行时，不受页面缓存影响
    $js .= '
var box=document.querySelector(".post-share-buttons");
var fb=document.querySelector(".post-share-fb-overlay");
function fbOpen(){if(fb)fb.style.display="flex";}
function fbClose(){if(fb)fb.style.display="none";}
// isSecureContext 一起判：HTTP 站点上 navigator.share 可能存在但一调用就抛
var canShare=!!(navigator.share&&window.isSecureContext);
var coarse=window.matchMedia&&matchMedia("(pointer:coarse)").matches;
var nb=box&&box.querySelector(".post-share-native");
if(nb&&coarse)nb.hidden=false;
document.addEventListener("click",function(e){
    if(e.target.closest(".post-share-native")){
        e.preventDefault();
        if(!canShare)return fbOpen();
        // 必须在手势内同步调用，中间不能 await，否则 iOS 抛 NotAllowedError
        navigator.share({title:box.getAttribute("data-title"),url:box.getAttribute("data-url")})
            .catch(function(err){
                if(err&&err.name==="AbortError")return; // 用户主动取消，不弹回退
                fbOpen();
            });
        return;
    }
    if(e.target.closest(".post-share-fb-copy")){
        var i=fb.querySelector(".post-share-fb-url");
        i.select();i.setSelectionRange(0,i.value.length);
        var ok=false;
        try{ok=document.execCommand("copy");}catch(x){}
        if(!ok&&navigator.clipboard)navigator.clipboard.writeText(i.value).then(function(){},function(){});
        e.target.textContent="' . esc_js(__('已复制', 'kratos')) . '";
        return;
    }
    if(e.target.closest(".post-share-fb-close")||e.target.classList.contains("post-share-fb-overlay"))fbClose();
});';
    $js .= '})();';
    wp_add_inline_script('kratos', $js);
}
add_action('wp_enqueue_scripts', 'kratos_social_share_assets', 20);
