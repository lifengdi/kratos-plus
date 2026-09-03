<?php

/**
 * 阅读模式（无干扰）
 * 一键切换到纯净阅读视图，隐藏侧边栏、导航、评论等，只保留正文。
 *
 * @author Dylan Li (Kratos-plus) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

function kratos_reading_mode_assets()
{
    if (!kratos_option('g_reading_mode', false)) {
        return;
    }
    if (!is_singular('post')) {
        return;
    }

    $css = '
body.is-reading-mode .k-header,
body.is-reading-mode .k-main .sidebar,
body.is-reading-mode .k-footer,
body.is-reading-mode .gotop,
body.is-reading-mode .comments,
body.is-reading-mode .post-navigation,
body.is-reading-mode .kratos-related,
body.is-reading-mode .kratos-series,
body.is-reading-mode .f-toolbox,
body.is-reading-mode .f-toolbox-inner,
body.is-reading-mode .post-share-buttons,
body.is-reading-mode .post-ref-links { display: none !important; }
body.is-reading-mode .k-main > .container { max-width: 780px; }
/* 正文列的栅格类由主题选项决定（col-lg-8/9/12 都可能），按 .details 兜住 */
body.is-reading-mode .k-main .details {
    flex: 0 0 100%; max-width: 100%;
}
body.is-reading-mode .k-main { padding-top: 24px; }
.kratos-reading-mode-btn {
    display: inline-flex; align-items: center; gap: 4px;
    cursor: pointer; color: #888; font-size: 13px; transition: color .2s;
}
.kratos-reading-mode-btn:hover { color: var(--kr-skin-accent, #0abbef); }
.kratos-reading-mode-exit {
    position: fixed; top: 16px; right: 16px; z-index: 9999;
    padding: 8px 16px; border-radius: 6px; border: 1px solid #ddd;
    background: var(--kr-card-bg, #fff); color: #666;
    cursor: pointer; font-size: 13px; box-shadow: 0 2px 8px rgba(0,0,0,.1);
    display: none; transition: opacity .2s;
}
body.is-reading-mode .kratos-reading-mode-exit { display: block; }
html[data-theme="dark"] .kratos-reading-mode-exit { background: #2a2a2a; color: #ccc; border-color: #444; }
';
    wp_add_inline_style('kratos', $css);

    $js = '(function(){' .
        'var exit=document.createElement("button");' .
        'exit.className="kratos-reading-mode-exit";' .
        'exit.textContent=' . wp_json_encode(__('退出阅读模式', 'kratos')) . ';' .
        'exit.addEventListener("click",function(){document.body.classList.remove("is-reading-mode");});' .
        'document.body.appendChild(exit);' .
        'document.addEventListener("click",function(e){' .
        'if(e.target.closest(".kratos-reading-mode-btn")){' .
        'e.preventDefault();document.body.classList.toggle("is-reading-mode");}' .
        '});' .
        'document.addEventListener("keydown",function(e){' .
        'if(e.key==="Escape"&&document.body.classList.contains("is-reading-mode")){' .
        'document.body.classList.remove("is-reading-mode");}' .
        '});' .
        '})();';
    wp_add_inline_script('kratos', $js);
}
add_action('wp_enqueue_scripts', 'kratos_reading_mode_assets', 20);

/**
 * 在文章 meta 区域输出阅读模式按钮（供模板调用）。
 */
function kratos_reading_mode_button()
{
    if (!kratos_option('g_reading_mode', false)) {
        return '';
    }
    return '<span class="kratos-reading-mode-btn" title="' . esc_attr__('阅读模式', 'kratos') . '"><i class="fas fa-book-open"></i> ' . __('阅读模式', 'kratos') . '</span>';
}
