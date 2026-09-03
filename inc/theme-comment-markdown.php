<?php

/**
 * 评论 Markdown 支持
 * 用 Parsedown 解析评论正文，safe mode 防 XSS。
 *
 * @author Dylan Li (Kratos-plus) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 解析评论正文中的 Markdown。
 * 优先级 1：必须排在整条 comment_text 链最前面，拿到的才是纯原始文本。
 * 若排在后面，setMarkupEscaped 会把 convert_smilies（20）或其它插件
 * （UA/系统徽标等）已经插入的 HTML 一起转义成实体。
 */
function kratos_comment_markdown_filter($text)
{
    if (!kratos_option('g_comment_markdown', false)) {
        return $text;
    }

    static $parsedown = null;
    if ($parsedown === null) {
        $file = get_template_directory() . '/inc/update-checker/vendor/Parsedown.php';
        if (!class_exists('Parsedown') && file_exists($file)) {
            require_once $file;
        }
        if (!class_exists('Parsedown')) {
            return $text;
        }
        $parsedown = new Parsedown();
        // PUC 里 vendor 的 Parsedown 是 1.6.0，没有 1.7 才加入的 setSafeMode()（调用会 fatal）。
        // 用 setMarkupEscaped() 挡住内联 HTML，剩下的 javascript: URL 等交给下面的 wp_kses_post()。
        $parsedown->setMarkupEscaped(true);
        $parsedown->setBreaksEnabled(true);
    }

    return wp_kses_post($parsedown->text($text));
}
add_filter('comment_text', 'kratos_comment_markdown_filter', 1);

/**
 * 禁止 wpautop 重复处理已经由 Parsedown 生成的 HTML。
 */
function kratos_comment_markdown_skip_wpautop($text)
{
    if (!kratos_option('g_comment_markdown', false)) {
        return $text;
    }
    // wpautop 挂在 comment_text@30，这里在 29 标记，30 时跳过
    remove_filter('comment_text', 'wpautop', 30);
    return $text;
}
add_filter('comment_text', 'kratos_comment_markdown_skip_wpautop', 29);

/**
 * 评论区 Markdown 样式 + 提示文案。
 */
function kratos_comment_markdown_assets()
{
    if (!kratos_option('g_comment_markdown', false)) {
        return;
    }
    if (!is_singular() || !comments_open()) {
        return;
    }

    $css = '
.comment-content pre { background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; margin: 8px 0; }
.comment-content code { background: #f0f0f0; padding: 1px 4px; border-radius: 3px; font-size: .9em; }
.comment-content pre code { background: none; padding: 0; }
.comment-content blockquote { border-left: 3px solid var(--kr-skin-accent, #0abbef); padding: 4px 12px; margin: 8px 0; color: #666; }
.comment-content table { border-collapse: collapse; margin: 8px 0; }
.comment-content th, .comment-content td { border: 1px solid #ddd; padding: 6px 10px; }
.comment-content img { max-width: 100%; height: auto; }
html[data-theme="dark"] .comment-content pre { background: #2a2a2a; }
html[data-theme="dark"] .comment-content code { background: #333; }
html[data-theme="dark"] .comment-content blockquote { color: #aaa; }
html[data-theme="dark"] .comment-content th, html[data-theme="dark"] .comment-content td { border-color: #444; }
.kratos-md-hint { font-size: 12px; color: #999; margin-top: 4px; }
.kratos-md-hint i { margin-right: 3px; }
';
    wp_add_inline_style('kratos', $css);

    // 评论框下方加 Markdown 提示
    $js = '(function(){' .
        'var ta=document.getElementById("comment");if(!ta)return;' .
        'var h=document.createElement("div");h.className="kratos-md-hint";' .
        'h.innerHTML=\'<i class="fab fa-markdown"></i> ' . esc_js(__('支持 Markdown 语法', 'kratos')) . '\';' .
        'ta.parentNode.insertBefore(h,ta.nextSibling);' .
        '})();';
    wp_add_inline_script('kratos', $js);
}
add_action('wp_enqueue_scripts', 'kratos_comment_markdown_assets', 20);
