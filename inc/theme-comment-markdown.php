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
        // safeMode 是 1.7 才有的；PUC 的 vendor 副本可能被上游回退到 1.6，故先探测再调。
        if (method_exists($parsedown, 'setSafeMode')) {
            $parsedown->setSafeMode(true);
        }
        $parsedown->setMarkupEscaped(true);
        $parsedown->setBreaksEnabled(true);
    }

    // preprocess_comment 阶段 comment_post()（theme-article.php）已对全文跑过 htmlspecialchars，
    // 入库的是 &gt; / &lt; / &quot;。不先还原，Parsedown 认不出 "> 引用" 和 "<autolink>"。
    // 还原后仍有 setMarkupEscaped + wp_kses_post 两道兜底，不降低安全性。
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

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

    // 取值逐项对齐正文（style.css 的 .k-main .details .article .content *
    // 与 dark.css 的暗色覆盖），只是选择器换成评论正文容器。
    // 间距按评论区收一半（正文 16/24px → 8/12px），字号用 em 从评论的 13px 基准
    // 按正文的比例缩放（正文 15px 基准下 h1 22px ≈ 1.47em），否则标题会比作者名还大。
    // 改动正文这些取值时，这里要同步。
    $c = '.k-main .details .comments .comment .info .content';
    $d = 'html[data-theme="dark"] ' . $c;
    $css = <<<CSS
$c h1, $c h2, $c h3, $c h4, $c h5, $c h6 { margin: 12px 0 8px; font-weight: 500; line-height: 1.25; }
$c h1 { font-size: 1.47em; }
$c h2 { font-size: 1.33em; }
$c h3 { font-size: 1.2em; }
$c h4 { font-size: 1.07em; }
$c h5 { font-size: 1em; }
$c h6 { font-size: .87em; }
$c > *:first-child { margin-top: 0; }
$c ul, $c ol { margin: 0 0 8px; padding-left: 22px; }
$c p { margin-bottom: 8px; }
$c > *:last-child { margin-bottom: 0; }
$c li p { margin-bottom: 4px; line-height: inherit; }
$c blockquote { margin: 8px 0; padding: 8px 12px; border-left: 6px solid #dce6f0; background: #f2f7fb; color: #819198; font-size: 1em; }
$c blockquote p { margin-bottom: 8px; }
$c blockquote p:last-child { margin-bottom: 0; }
$c hr { margin: 12px 0; height: 1px; border: none; border-top: 1px solid #a5a5a5; }
$c pre:not([class*="language-"]):not(.hljs) { margin: 8px 0; padding: 11px 16px; border-radius: 4px; background-color: #f8f8f8; overflow-x: auto; word-wrap: break-word; word-break: break-all; font-size: 14px; line-height: 1.7; }
$c code:not([class*="language-"]):not(.hljs) { margin: 0 3px; padding: 2px 4px; border-radius: 4px; background-color: #eff0f1; color: #e83e8c; word-break: inherit; }
$c pre code:not([class*="language-"]):not(.hljs) { margin: 0; padding: 0; background-color: transparent; color: inherit; }
$c kbd { display: inline-block; margin: -3px 1.6px 0; padding: 2.4px 9.6px; border: 1px solid #adb3b9; border-radius: 3px; background-color: #e1e3e5; box-shadow: 0 1px 0 rgba(12, 13, 14, .2), 0 0 0 2px #fff inset; color: #242729; vertical-align: middle; text-shadow: 0 1px 0 #fff; white-space: nowrap; font-size: 10px; line-height: 1.5; }
$c table { display: block; overflow: auto; margin: 8px 0; width: 100%; border-collapse: collapse; word-break: keep-all; }
$c table th, $c table td { padding: 8px 16px; border: 1px solid #e9ebec; }
$c table th { font-weight: bold; }
$c table tr:nth-child(2n) { background-color: #f8f8f8; }
$c img { max-width: 100%; height: auto; }
$d blockquote { background: #1a2129; border-left-color: #2c3a4a; color: var(--kr-fg-muted); }
$d hr { border-top-color: var(--kr-border); }
$d pre:not([class*="language-"]):not(.hljs) { background-color: var(--kr-code-bg); color: var(--kr-fg); }
$d code:not([class*="language-"]):not(.hljs) { background-color: var(--kr-code-bg); color: var(--kr-code-fg); }
$d kbd { background-color: #2a2f37; border-color: #3a4150; color: var(--kr-fg-strong); box-shadow: 0 1px 0 rgba(0, 0, 0, .4), 0 0 0 2px #14171a inset; text-shadow: none; }
$d table th, $d table td { border-color: var(--kr-border); }
$d table tr:nth-child(2n) { background-color: rgba(255, 255, 255, .03); }
.kratos-md-hint { font-size: 12px; color: #999; margin-top: 4px; }
.kratos-md-hint i { margin-right: 3px; }
CSS;
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
