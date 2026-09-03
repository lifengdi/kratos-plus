<?php

/**
 * 文章锚点分享
 * 选中正文文字后弹出分享按钮，可复制带 Text Fragment 锚点的精确链接。
 *
 * @author Dylan Li (Kratos-plus) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

function kratos_text_share_assets()
{
    if (!kratos_option('g_text_share', false)) {
        return;
    }
    if (!is_singular('post')) {
        return;
    }

    $css = '
.kratos-text-share {
    position: absolute; z-index: 9999; display: none;
    padding: 6px 12px; border-radius: 6px;
    background: var(--kr-card-bg, #333); color: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
    font-size: 13px; cursor: pointer; white-space: nowrap;
    transition: opacity .15s;
    user-select: none;
}
.kratos-text-share::after {
    content: ""; position: absolute; bottom: -6px; left: 50%; transform: translateX(-50%);
    border-left: 6px solid transparent; border-right: 6px solid transparent;
    border-top: 6px solid var(--kr-card-bg, #333);
}
.kratos-text-share i { margin-right: 4px; }
html[data-theme="dark"] .kratos-text-share { background: #444; }
html[data-theme="dark"] .kratos-text-share::after { border-top-color: #444; }
';
    wp_add_inline_style('kratos', $css);

    $copied_text = __('已复制', 'kratos');
    $copy_link_text = __('复制链接', 'kratos');

    $js = '(function(){' .
        'var pop=document.createElement("div");' .
        'pop.className="kratos-text-share";' .
        'pop.innerHTML=\'<i class="fas fa-link"></i> ' . esc_js($copy_link_text) . '\';' .
        'document.body.appendChild(pop);' .
        'var area=document.querySelector(".article-content,.entry-content,.details .content");' .
        'if(!area)return;' .
        'var hideTimer;' .
        'function hide(){pop.style.display="none";}' .
        'document.addEventListener("mouseup",function(e){' .
        'clearTimeout(hideTimer);' .
        'var s=window.getSelection();' .
        'if(!s||s.isCollapsed||s.toString().trim().length<5||!area.contains(s.anchorNode)){hide();return;}' .
        'var r=s.getRangeAt(0).getBoundingClientRect();' .
        'pop.innerHTML=\'<i class="fas fa-link"></i> ' . esc_js($copy_link_text) . '\';' .
        // display 必须先打开再测 offsetWidth/Height，display:none 时两者都是 0，居中会失效
        'pop.style.display="block";' .
        'pop.style.left=(r.left+r.width/2-pop.offsetWidth/2+window.scrollX)+"px";' .
        'pop.style.top=(r.top+window.scrollY-pop.offsetHeight-8)+"px";' .
        '});' .
        'pop.addEventListener("click",function(){' .
        'var s=window.getSelection();if(!s||s.isCollapsed)return;' .
        'var t=s.toString().trim();' .
        'var url=location.href.split("#")[0]+"#:~:text="+encodeURIComponent(t.substring(0,100));' .
        'navigator.clipboard.writeText(url).then(function(){' .
        'pop.innerHTML=\'<i class="fas fa-check"></i> ' . esc_js($copied_text) . '\';' .
        'hideTimer=setTimeout(hide,1500);' .
        '});' .
        '});' .
        'document.addEventListener("mousedown",function(e){' .
        'if(!pop.contains(e.target))hide();' .
        '});' .
        '})();';
    wp_add_inline_script('kratos', $js);
}
add_action('wp_enqueue_scripts', 'kratos_text_share_assets', 20);
