<?php

/**
 * 关键词自动内链
 *  - 在文章正文渲染时（the_content 过滤器）扫描当前站点的标签 / 分类关键词，
 *    命中则替换为对应 term 归档链接。
 *  - 使用 DOMDocument 遍历文本节点，跳过 <a>/<code>/<pre>/<h1-6> 内部，避免破坏已有链接与代码块。
 *  - 结果按 post_id + post_modified_gmt + terms_version 缓存到 object cache，
 *    命中缓存开销 ≈ 一次 GET；term 增删改时 bump 全局 terms_version 整体失效。
 *  - 仅在单篇文章正文（is_singular('post') + 主查询）启用；列表页摘要不参与。
 *
 * @author Dylan Li (Kratos+) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

function kratos_autolink_defaults()
{
    return array(
        'g_autolink_enabled'      => false,
        'g_autolink_include_tag'  => true,
        'g_autolink_include_cat'  => true,
        'g_autolink_max_per_kw'   => 1,
        'g_autolink_max_total'    => 6,
        'g_autolink_min_length'   => 2,
        'g_autolink_new_window'   => false,
        'g_autolink_nofollow'     => false,
        'g_autolink_exclude_ids'  => '',
        'g_autolink_custom_map'   => '',
    );
}

add_filter('default_option_kratos_options', function ($default) {
    $defs = kratos_autolink_defaults();
    if (is_array($default)) {
        return array_merge($defs, $default);
    }
    return $defs;
}, 10, 1);

add_filter('option_kratos_options', function ($value) {
    if (!is_array($value)) {
        return $value;
    }
    foreach (kratos_autolink_defaults() as $k => $v) {
        if (!array_key_exists($k, $value) || $value[$k] === '' || $value[$k] === null) {
            $value[$k] = $v;
        }
    }
    return $value;
}, 10, 1);

/**
 * terms 版本号：term 增/删/改时 bump，用于让缓存整体失效。
 */
function kratos_autolink_terms_version()
{
    $v = get_option('kratos_autolink_terms_version');
    if (!$v) {
        $v = '1';
        update_option('kratos_autolink_terms_version', $v, false);
    }
    return $v;
}

function kratos_autolink_bump_version()
{
    $v = (int) get_option('kratos_autolink_terms_version', 1);
    update_option('kratos_autolink_terms_version', (string) ($v + 1), false);
    wp_cache_delete('map', 'kratos_autolink');
}
add_action('created_term', 'kratos_autolink_bump_version', 10, 0);
add_action('edited_term',  'kratos_autolink_bump_version', 10, 0);
add_action('delete_term',  'kratos_autolink_bump_version', 10, 0);
// 主题选项保存时也清 map 缓存（自定义映射 / 排除 ID / 最小长度变更需要立即生效）
add_action('update_option_kratos_options', 'kratos_autolink_bump_version', 10, 0);
add_action('add_option_kratos_options',    'kratos_autolink_bump_version', 10, 0);

/**
 * 构建关键词 → 链接映射。
 * 结构：[ ['keyword' => '...', 'url' => '...', 'term_id' => N, 'taxonomy' => 'post_tag'|'category'|'custom'], ... ]
 * 按 keyword 长度倒序排列，长关键词优先匹配，避免子串误伤。
 */
function kratos_autolink_build_map()
{
    $cached = wp_cache_get('map', 'kratos_autolink');
    if (is_array($cached)) {
        return $cached;
    }

    $map = array();
    $seen = array(); // 关键词去重（按 lowercase）

    $exclude_ids = array_filter(array_map('intval', preg_split('/[,\s]+/', (string) kratos_option('g_autolink_exclude_ids', ''))));
    $min_len = max(1, (int) kratos_option('g_autolink_min_length', 2));

    $taxonomies = array();
    if (kratos_option('g_autolink_include_tag', true)) {
        $taxonomies[] = 'post_tag';
    }
    if (kratos_option('g_autolink_include_cat', true)) {
        $taxonomies[] = 'category';
    }

    foreach ($taxonomies as $tax) {
        $terms = get_terms(array(
            'taxonomy'   => $tax,
            'hide_empty' => false,
        ));
        if (is_wp_error($terms) || !is_array($terms)) {
            continue;
        }
        foreach ($terms as $term) {
            if (in_array((int) $term->term_id, $exclude_ids, true)) {
                continue;
            }
            $names = array($term->name);
            // 从 term description 里取一行 "别名: a,b,c"
            if (!empty($term->description) && preg_match('/(?:别名|aliases?)\s*[:：]\s*([^\r\n]+)/iu', $term->description, $m)) {
                foreach (preg_split('/[,，、|\s]+/u', $m[1]) as $alias) {
                    $alias = trim($alias);
                    if ($alias !== '') {
                        $names[] = $alias;
                    }
                }
            }
            foreach ($names as $name) {
                $name = trim($name);
                if ($name === '' || mb_strlen($name, 'UTF-8') < $min_len) {
                    continue;
                }
                $key = mb_strtolower($name, 'UTF-8');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $map[] = array(
                    'keyword'  => $name,
                    'url'      => get_term_link($term),
                    'term_id'  => (int) $term->term_id,
                    'taxonomy' => $tax,
                );
            }
        }
    }

    // 自定义映射：每行 "关键词 => URL"（兼容全角 ＝＞ 与 ->）
    $custom = trim((string) kratos_option('g_autolink_custom_map', ''));
    if ($custom !== '') {
        // CSF textarea 保存时会把 > 编码成 &gt;，先解码
        $custom = html_entity_decode($custom, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 归一化分隔符
        $custom = str_replace(array('＝＞', '=＞', '＝>', '->', '→'), '=>', $custom);
        foreach (preg_split('/\r?\n/', $custom) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=>') === false) {
                continue;
            }
            $parts = explode('=>', $line, 2);
            $kw = trim($parts[0]);
            $url = trim($parts[1]);
            if ($kw === '' || $url === '' || mb_strlen($kw, 'UTF-8') < $min_len) {
                continue;
            }
            $key = mb_strtolower($kw, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $map[] = array(
                'keyword'  => $kw,
                'url'      => $url,
                'term_id'  => 0,
                'taxonomy' => 'custom',
            );
        }
    }

    // 按关键词长度倒序，长优先
    usort($map, function ($a, $b) {
        return mb_strlen($b['keyword'], 'UTF-8') - mb_strlen($a['keyword'], 'UTF-8');
    });

    wp_cache_set('map', $map, 'kratos_autolink', HOUR_IN_SECONDS);
    return $map;
}

/**
 * 主过滤器：在文章正文里做替换。
 */
function kratos_autolink_filter_content($content)
{
    if (!kratos_option('g_autolink_enabled', false)) {
        return $content;
    }
    if (is_feed() || is_admin()) {
        return $content;
    }
    if (!is_singular('post')) {
        return $content;
    }
    if (!is_string($content) || $content === '') {
        return $content;
    }

    $post = get_post();
    if (!$post) {
        return $content;
    }

    $cache_key = 'c_' . $post->ID . '_' . md5('v6|' . $post->post_modified_gmt . '|' . kratos_autolink_terms_version() . '|' . kratos_autolink_options_signature());
    $cached = wp_cache_get($cache_key, 'kratos_autolink');
    if (is_string($cached)) {
        return $cached;
    }

    $map = kratos_autolink_build_map();
    if (!$map) {
        wp_cache_set($cache_key, $content, 'kratos_autolink', DAY_IN_SECONDS);
        return $content;
    }

    // 只排除「链接到当前文章自身 URL」的情况；文章自身所属的 tag/category 归档页仍允许链接
    $current_url = get_permalink($post->ID);

    $result = kratos_autolink_process_html($content, $map, array(), $current_url);
    wp_cache_set($cache_key, $result, 'kratos_autolink', DAY_IN_SECONDS);
    return $result;
}
add_filter('the_content', 'kratos_autolink_filter_content', 20);

/**
 * 前台注入知乎风格的自动内链样式。
 */
function kratos_autolink_inline_style()
{
    if (!kratos_option('g_autolink_enabled', false) || !is_singular('post')) {
        return;
    }
    ?>
<style id="kratos-autolink-style">
.kratos-auto-link{
    color:#175199;
    background:transparent;
    padding:0 2px;
    text-decoration:none !important;
    border-bottom:none;
    transition:background-color .15s ease,color .15s ease;
}
.kratos-auto-link:hover,
.kratos-auto-link:focus{
    color:#0f3d75;
    background:rgba(23,81,153,.08);
    text-decoration:none !important;
}
.kratos-auto-link .kratos-auto-link-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    vertical-align:baseline;
    margin-left:2px;
    width:12px;
    height:12px;
    color:#175199;
    opacity:.75;
    transition:opacity .15s ease;
}
.kratos-auto-link:hover .kratos-auto-link-badge{ opacity:1; }
.kratos-auto-link .kratos-auto-link-badge svg{ width:10px; height:10px; display:block; }
.kratos-auto-link .kratos-auto-link-badge[data-tax="category"]{ color:#c75450; }
html[data-theme="dark"] .kratos-auto-link{
    color:#4e8fd6;
}
html[data-theme="dark"] .kratos-auto-link:hover,
html[data-theme="dark"] .kratos-auto-link:focus{
    color:#7fb0e6;
    background:rgba(78,143,214,.12);
}
html[data-theme="dark"] .kratos-auto-link .kratos-auto-link-badge{ color:#7fb0e6; }
html[data-theme="dark"] .kratos-auto-link .kratos-auto-link-badge[data-tax="category"]{ color:#e67874; }
</style>
    <?php
}
add_action('wp_head', 'kratos_autolink_inline_style', 99);

/**
 * 影响输出的选项签名，参与缓存 key，防止改配置后不刷新。
 */
function kratos_autolink_options_signature()
{
    $keys = array_keys(kratos_autolink_defaults());
    $sig = array();
    foreach ($keys as $k) {
        $sig[$k] = kratos_option($k, '');
    }
    return md5(serialize($sig));
}

/**
 * DOMDocument 遍历文本节点做替换，跳过链接 / 代码 / 标题内部。
 */
function kratos_autolink_process_html($html, $map, $self_term_ids, $current_url)
{
    $max_per_kw  = max(1, (int) kratos_option('g_autolink_max_per_kw', 1));
    $max_total   = max(1, (int) kratos_option('g_autolink_max_total', 6));
    $new_window  = (bool) kratos_option('g_autolink_new_window', false);
    $nofollow    = (bool) kratos_option('g_autolink_nofollow', false);

    // 过滤掉自身 term
    $map = array_values(array_filter($map, function ($item) use ($self_term_ids, $current_url) {
        if ($item['taxonomy'] !== 'custom' && in_array((int) $item['term_id'], $self_term_ids, true)) {
            return false;
        }
        if (!empty($item['url']) && $item['url'] === $current_url) {
            return false;
        }
        return true;
    }));
    if (!$map) {
        return $html;
    }

    $counts = array();       // 每关键词已替换次数
    $total  = 0;             // 总替换次数

    // 用 HTML entities 转码，避免 DOMDocument 把 UTF-8 中文识别为 Latin-1
    $wrapped = '<div id="kratos-autolink-root">' . $html . '</div>';
    if (function_exists('mb_encode_numericentity')) {
        $wrapped = mb_encode_numericentity($wrapped, array(0x80, 0x10FFFF, 0, 0x1FFFFF), 'UTF-8');
    }

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // LIBXML_HTML_NOIMPLIED + LIBXML_HTML_NODEFDTD：防止 DOM 补 <html><body>
    $ok = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        return $html;
    }

    $root = $dom->getElementById('kratos-autolink-root');
    if (!$root) {
        return $html;
    }

    $skip_tags = array('a', 'code', 'pre', 'kbd', 'samp', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'textarea', 'button');

    $xpath = new DOMXPath($dom);
    $text_nodes = array();
    foreach ($xpath->query('.//text()', $root) as $node) {
        // 跳过位于 skip 标签内的文本
        $skip = false;
        for ($p = $node->parentNode; $p && $p !== $root; $p = $p->parentNode) {
            if ($p->nodeType === XML_ELEMENT_NODE && in_array(strtolower($p->nodeName), $skip_tags, true)) {
                $skip = true;
                break;
            }
        }
        if (!$skip) {
            $text_nodes[] = $node;
        }
    }

    foreach ($text_nodes as $text_node) {
        if ($total >= $max_total) {
            break;
        }
        $text = $text_node->nodeValue;
        if ($text === '' || trim($text) === '') {
            continue;
        }

        // 依次尝试每个关键词；命中后把文本节点拆成 [before, <a>keyword</a>, after] 结构
        $segments = array(array('type' => 'text', 'value' => $text));

        foreach ($map as $item) {
            if ($total >= $max_total) {
                break;
            }
            $kw = $item['keyword'];
            $done = isset($counts[$kw]) ? $counts[$kw] : 0;
            if ($done >= $max_per_kw) {
                continue;
            }

            $new_segments = array();
            foreach ($segments as $seg) {
                if ($seg['type'] !== 'text' || $done >= $max_per_kw || $total >= $max_total) {
                    $new_segments[] = $seg;
                    continue;
                }
                $str = $seg['value'];
                // 大小写不敏感查找
                $offset = 0;
                $pieces = array();
                $lower_str = mb_strtolower($str, 'UTF-8');
                $lower_kw  = mb_strtolower($kw, 'UTF-8');
                $kw_len_bytes = strlen($kw); // byte length in UTF-8
                $lower_kw_bytes = strlen($lower_kw);

                while ($done < $max_per_kw && $total < $max_total) {
                    $pos = strpos($lower_str, $lower_kw, $offset);
                    if ($pos === false) {
                        break;
                    }
                    // 英文关键词加词边界：前后不能是字母/数字
                    if (preg_match('/^[a-zA-Z0-9_]+$/', $kw)) {
                        $before = $pos > 0 ? $str[$pos - 1] : '';
                        $after  = isset($str[$pos + $lower_kw_bytes]) ? $str[$pos + $lower_kw_bytes] : '';
                        if (preg_match('/[a-zA-Z0-9_]/', $before) || preg_match('/[a-zA-Z0-9_]/', $after)) {
                            $offset = $pos + $lower_kw_bytes;
                            continue;
                        }
                    }
                    $pieces[] = array('type' => 'text', 'value' => substr($str, $offset, $pos - $offset));
                    $pieces[] = array('type' => 'link', 'value' => substr($str, $pos, $lower_kw_bytes), 'url' => $item['url'], 'taxonomy' => $item['taxonomy']);
                    $offset = $pos + $lower_kw_bytes;
                    $done++;
                    $total++;
                }
                if (!$pieces) {
                    $new_segments[] = $seg;
                    continue;
                }
                $pieces[] = array('type' => 'text', 'value' => substr($str, $offset));
                foreach ($pieces as $p) {
                    if ($p['type'] === 'text' && $p['value'] === '') {
                        continue;
                    }
                    $new_segments[] = $p;
                }
            }
            $segments = $new_segments;
            $counts[$kw] = $done;
        }

        // 如果没有拆分，跳过
        $has_link = false;
        foreach ($segments as $seg) {
            if ($seg['type'] === 'link') {
                $has_link = true;
                break;
            }
        }
        if (!$has_link) {
            continue;
        }

        // 用新节点替换原文本节点
        $parent = $text_node->parentNode;
        foreach ($segments as $seg) {
            if ($seg['type'] === 'text') {
                $parent->insertBefore($dom->createTextNode($seg['value']), $text_node);
            } else {
                $a = $dom->createElement('a');
                $a->setAttribute('href', $seg['url']);
                $a->setAttribute('class', 'kratos-auto-link');
                if ($new_window) {
                    $a->setAttribute('target', '_blank');
                    $a->setAttribute('rel', $nofollow ? 'noopener noreferrer nofollow' : 'noopener noreferrer');
                } elseif ($nofollow) {
                    $a->setAttribute('rel', 'nofollow');
                }
                $a->appendChild($dom->createTextNode($seg['value']));
                $tax = isset($seg['taxonomy']) ? $seg['taxonomy'] : '';
                if ($tax === 'post_tag' || $tax === 'category') {
                    $badge = $dom->createElement('sup');
                    $badge->setAttribute('class', 'kratos-auto-link-badge');
                    $badge->setAttribute('data-tax', $tax);
                    $badge->setAttribute('aria-label', $tax === 'category' ? '分类' : '标签');
                    // SVG 图标：分类=文件夹 / 标签=价签
                    $svg_tag  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>';
                    $svg_cat  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
                    $svg = $tax === 'category' ? $svg_cat : $svg_tag;
                    // 用 DOMDocumentFragment 插 SVG
                    $frag = $dom->createDocumentFragment();
                    @$frag->appendXML($svg);
                    if ($frag->hasChildNodes()) {
                        $badge->appendChild($frag);
                    }
                    $a->appendChild($badge);
                }
                $parent->insertBefore($a, $text_node);
            }
        }
        $parent->removeChild($text_node);
    }

    // 导出 root 内部 HTML
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    // 把数字实体解回 UTF-8 字符
    if (function_exists('mb_decode_numericentity')) {
        $out = mb_decode_numericentity($out, array(0x80, 0x10FFFF, 0, 0x1FFFFF), 'UTF-8');
    }
    return $out;
}
