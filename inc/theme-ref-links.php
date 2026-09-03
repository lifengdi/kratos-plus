<?php

/**
 * 外链汇总 / 参考文献
 * 自动提取文章正文中的外部链接，在文末生成"参考链接"汇总区。
 *
 * @author Dylan Li (Kratos-plus) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 提取正文中的外链并在文末追加参考文献列表。
 * 优先级 96：在短代码展开（11）之后、社交分享（98）之前。
 */
function kratos_ref_links_content($content)
{
    if (!kratos_option('g_ref_links', false)) {
        return $content;
    }
    if (!is_singular('post') || !is_main_query()) {
        return $content;
    }

    $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

    // 匹配所有 <a href="..."> 但排除 <iframe> 内的
    if (!preg_match_all('/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
        return $content;
    }

    $links = array();
    $seen  = array();
    foreach ($matches as $m) {
        $href = trim($m[1]);

        // 跳过锚点、javascript:、mailto:、站内链接
        if (
            empty($href) ||
            $href[0] === '#' ||
            stripos($href, 'javascript:') === 0 ||
            stripos($href, 'mailto:') === 0
        ) {
            continue;
        }

        $parsed = wp_parse_url($href);
        if (empty($parsed['host'])) {
            continue;
        }

        // 站内链接（含子域名）跳过
        if (strcasecmp($parsed['host'], $home_host) === 0 || str_ends_with(strtolower($parsed['host']), '.' . strtolower($home_host))) {
            continue;
        }

        // 锚内容是图片/视频等媒体（灯箱图、视频封面），不是文字引用，跳过
        if (preg_match('/<(img|picture|video|audio|source|svg|iframe|figure)\b/i', $m[2])) {
            continue;
        }

        // 直链媒体/文档附件跳过（图片原图、下载链接）
        if (preg_match('/\.(jpe?g|png|gif|webp|avif|bmp|svg|ico|mp4|webm|ogv|mov|m4v|mp3|wav|ogg|flac|m4a|aac|pdf|zip|rar|7z|tar|gz|bz2|xz|docx?|xlsx?|pptx?|odt|ods|odp|csv|epub|mobi|dmg|pkg|exe|msi|apk|deb|rpm|iso)$/i', (string) ($parsed['path'] ?? ''))) {
            continue;
        }

        // 去重（按完整 URL）
        $clean_url = strtok($href, '#');
        if (isset($seen[$clean_url])) {
            continue;
        }
        $seen[$clean_url] = true;

        $text = wp_strip_all_tags($m[2]);
        if (empty($text) || strlen($text) > 200) {
            $text = $parsed['host'];
        }

        $links[] = array('url' => $href, 'text' => $text, 'host' => $parsed['host']);
    }

    if (empty($links)) {
        return $content;
    }

    $title = kratos_option('g_ref_links_title', '参考链接');
    $html  = '<div class="kratos-ref-links kr-card krl-wrap">';
    $html .= '<div class="krl-head">';
    $html .= '<span class="kr-ico krl-icon"><i class="fas fa-external-link-alt"></i></span>';
    $html .= '<h4 class="krl-title">' . esc_html($title) . '</h4>';
    $html .= '<span class="kr-pill krl-count">' . count($links) . '</span>';
    $html .= '</div>';
    $html .= '<ol class="krl-list">';
    $i = 0;
    foreach ($links as $link) {
        $i++;
        $html .= '<li class="krl-item">'
            . '<span class="krl-num">' . $i . '</span>'
            . '<a class="krl-link" href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">'
            . '<span class="krl-text">' . esc_html($link['text']) . '</span>'
            . '<span class="krl-host">' . esc_html($link['host']) . '</span>'
            . '</a></li>';
    }
    $html .= '</ol></div>';

    return $content . $html;
}
add_filter('the_content', 'kratos_ref_links_content', 96);

function kratos_ref_links_assets()
{
    if (!kratos_option('g_ref_links', false)) {
        return;
    }
    if (!is_singular('post')) {
        return;
    }

    $css = '
.kratos-ref-links {
    --krl-accent: var(--khs-accent, var(--kr-skin-accent, #4b7bec));
    --krl-fg: var(--khs-fg, var(--kr-skin-text, #1f2933));
    --krl-fg-dim: var(--khs-fg-dim, var(--kr-skin-muted, #8a949e));
    --krl-line: var(--khs-line, var(--kr-skin-card-line, rgba(0,0,0,.08)));
    --krl-card-bg: var(--khs-card-bg, var(--kr-skin-card-bg, #fff));
    margin: 28px 0 8px; padding: 18px 20px 14px;
    background: var(--krl-card-bg); border: 1px solid var(--krl-line); border-radius: 10px;
}
.kratos-ref-links .krl-head { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.kratos-ref-links .krl-icon {
    flex: none; width: 26px; height: 26px; border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; color: #fff; background: var(--krl-accent);
}
.kratos-ref-links .krl-title { flex: 1 1 auto; margin: 0; font-size: 15px; font-weight: 600; color: var(--krl-fg); }
.kratos-ref-links .krl-count {
    flex: none; padding: 1px 9px; border-radius: 999px; font-size: 12px; line-height: 1.6;
    color: var(--krl-fg-dim); background: color-mix(in srgb, var(--krl-accent) 10%, transparent);
}
.kratos-ref-links .krl-list { margin: 0; padding: 0; list-style: none; counter-reset: none; }
.kratos-ref-links .krl-item { display: flex; align-items: baseline; gap: 8px; margin: 0; padding: 0; }
.kratos-ref-links .krl-item + .krl-item { border-top: 1px dashed var(--krl-line); }
.kratos-ref-links .krl-link {
    flex: 1 1 auto; min-width: 0;
    display: flex; align-items: baseline; gap: 8px; padding: 7px 6px;
    color: var(--krl-fg); text-decoration: none; border-radius: 6px;
}
.kratos-ref-links .krl-num {
    flex: none; width: 20px; padding-top: 7px; text-align: right;
    font-size: 12px; color: var(--krl-fg-dim); font-variant-numeric: tabular-nums;
}
.kratos-ref-links .krl-text {
    flex: 1 1 auto; min-width: 0; font-size: 14px; line-height: 1.7;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.kratos-ref-links .krl-host { flex: none; font-size: 12px; color: var(--krl-fg-dim); }
.kratos-ref-links .krl-link:hover { background: color-mix(in srgb, var(--krl-accent) 6%, transparent); }
.kratos-ref-links .krl-link:hover .krl-text { color: var(--krl-accent); text-decoration: underline; }
@media (max-width: 480px) {
    .kratos-ref-links { padding: 14px 14px 10px; }
    .kratos-ref-links .krl-host { display: none; }
}
';
    wp_add_inline_style('kratos', $css);
}
add_action('wp_enqueue_scripts', 'kratos_ref_links_assets', 20);
