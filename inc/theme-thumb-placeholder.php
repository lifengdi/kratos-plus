<?php
/**
 * 默认特色图 · 文字+颜色渲染
 *
 * 当文章无特色图、正文亦无图时，按主题选项渲染文字占位图。
 * 提供两个对外函数：
 *   - kratos_default_thumb_html($post, $w=600, $h=400): 输出 <img> 或 <span>，供模板直接 echo
 *   - kratos_default_thumb_url($post, $w=600, $h=400): 输出 data:image/svg+xml URL，供 JSON/图床等场景使用
 */

if (!defined('ABSPATH')) exit;

/**
 * 6 套调色板；每套 8 色，crc32(post) % 8 取索引。
 */
function kratos_thumb_ph_palette($name)
{
    $palettes = array(
        'material' => array('#5B8DEF', '#E85A71', '#2E8B57', '#F5A623', '#8E7DBE', '#00A5A8', '#D97B8B', '#4A90B8'),
        'pastel'   => array('#F6A5C0', '#FDBB74', '#7CD6C7', '#B9A6E4', '#F5D77A', '#A8D8B9', '#F4A6A6', '#9CC5E4'),
        'morandi'  => array('#B8A99A', '#8FA69C', '#A89BAB', '#C4B8A8', '#9AA5B4', '#B5A29A', '#8CA3A0', '#A69A8B'),
        'retro'    => array('#8B5A3C', '#A67C52', '#6B4423', '#B08968', '#7A5230', '#946B3E', '#5C3A21', '#8C6239'),
        'ink'      => array('#2C3E50', '#34495E', '#1A2530', '#2E4053', '#212F3D', '#3B4A5A', '#17202A', '#2C3F52'),
    );
    return isset($palettes[$name]) ? $palettes[$name] : $palettes['material'];
}

/**
 * 从 WP_Post 抽取渲染文字。
 */
function kratos_thumb_ph_text($post)
{
    $src = kratos_option('g_postthumbnail_text_source', 'title_initial');
    $custom = kratos_option('g_postthumbnail_text_custom', 'Kratos+');
    $title = is_object($post) ? get_the_title($post) : '';
    $title = trim(wp_strip_all_tags($title));

    switch ($src) {
        case 'custom':
            $text = $custom;
            break;
        case 'category':
            $cats = is_object($post) ? get_the_category($post->ID) : array();
            $text = (!empty($cats) && isset($cats[0]->name)) ? $cats[0]->name : $title;
            break;
        case 'title_full':
            // 完整标题，交由前端 CSS 自然折行；SVG 端会另行截断
            $text = $title;
            break;
        case 'title_two':
            // 英文（首字符是 ASCII 字母/数字）取首个空白分隔单词；中文取前两字
            if (preg_match('/^[A-Za-z0-9]/', $title)) {
                $parts = preg_split('/\s+/', $title, 2);
                $text = isset($parts[0]) ? $parts[0] : $title;
                // 单词过长截断，避免撑爆卡片
                if (mb_strlen($text, 'UTF-8') > 8) {
                    $text = mb_substr($text, 0, 8, 'UTF-8');
                }
            } else {
                $text = mb_substr($title, 0, 2, 'UTF-8');
            }
            break;
        case 'title_initial':
        default:
            $text = mb_substr($title, 0, 1, 'UTF-8');
            break;
    }
    if ($text === '' || $text === null) $text = 'K';
    // 英文单字符大写
    if (preg_match('/^[a-z]$/', $text)) $text = strtoupper($text);
    return $text;
}

/**
 * 皮肤 slug → accent 色（用于 SVG 端 skin 模式渲染，与各皮肤 CSS 内 --kr-skin-accent 一致）。
 * auto 模式下按服务器时区估算当日 slug；locked 模式用锁定 slug；
 * 前端 localStorage 覆盖或客户端时区差异时可能略有偏差，属可接受近似。
 */
function kratos_thumb_ph_skin_accent()
{
    $map = array(
        'mon' => '#4A90D9', 'tue' => '#C03A2B', 'wed' => '#5E7CA6',
        'thu' => '#1A2C42', 'fri' => '#C8862E', 'sat' => '#2E84B5', 'sun' => '#C28A2A',
        'mist'      => '#8AA79D', 'linen'     => '#C89B84',
        'porcelain' => '#8AAFBF', 'lavender'  => '#A896B4',
        'parchment' => '#6E4F2F', 'silk'      => '#A6321E',
        'vermilion' => '#C8322A', 'morandi'   => '#A68B73',
        'retro'     => '#C89B3C', 'web1998'   => '#1084D0',
        'ebook'     => '#4A4A4A', 'bookfold'  => '#33477E',
        'bourse'    => '#C8102E',
    );
    $slug = null;
    if (function_exists('kratos_weekday_settings')) {
        $s = kratos_weekday_settings();
        if ($s['mode'] === 'locked') {
            $slug = $s['locked'];
        } elseif ($s['mode'] === 'auto') {
            $wd = (int) current_time('w'); // 0=Sun … 6=Sat
            $week = array('sun','mon','tue','wed','thu','fri','sat');
            $slug = $week[$wd];
        }
    }
    if ($slug && isset($map[$slug])) return $map[$slug];
    return '#5B8DEF';
}

/**
 * 底色计算。
 */
function kratos_thumb_ph_bg($post)
{
    $mode = kratos_option('g_postthumbnail_bg_mode', 'hash');
    if ($mode === 'fixed') {
        return kratos_option('g_postthumbnail_bg_fixed', '#5B8DEF');
    }
    $palette = kratos_thumb_ph_palette(kratos_option('g_postthumbnail_palette', 'material'));
    $seed = is_object($post) ? ($post->post_name ?: (string)$post->ID) : 'kratos';
    $idx = abs(crc32($seed)) % count($palette);
    return $palette[$idx];
}

/**
 * 亮度自动前景色。
 */
function kratos_thumb_ph_fg($bg)
{
    $mode = kratos_option('g_postthumbnail_fg_mode', 'auto');
    if ($mode === 'white') return '#ffffff';
    if ($mode === 'black') return '#111111';
    if (!$bg) return '#ffffff';
    $hex = ltrim($bg, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6) return '#ffffff';
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $lum = (299 * $r + 587 * $g + 114 * $b) / 1000;
    return $lum > 150 ? '#111111' : '#ffffff';
}

/**
 * 统一字号 ratio 表：字号 = 短边 × ratio。
 * SVG 版直接乘短边得像素；<span> 版把 ratio 转成 cqw（container query width, 容器宽度%）。
 * 两版共用同一张表，保证同长度文字视觉大小一致。
 */
function kratos_thumb_ph_ratio($len)
{
    if ($len >= 14) return 0.070;
    if ($len >= 12) return 0.085;
    if ($len >= 10) return 0.100;
    if ($len >= 8)  return 0.120;
    if ($len >= 7)  return 0.140;
    if ($len >= 5)  return 0.180;
    if ($len >= 4)  return 0.220;
    if ($len >= 3)  return 0.280;
    if ($len >= 2)  return 0.360;
    return 0.480;
}

function kratos_thumb_ph_font_family($font)
{
    switch ($font) {
        case 'serif': return "Georgia, 'Songti SC', 'STSong', serif";
        case 'mono':  return "'JetBrains Mono', 'SFMono-Regular', Consolas, monospace";
        case 'sans':
        default:      return "-apple-system, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif";
    }
}

/**
 * 生成 SVG 字符串（内联，非 base64）。
 */
function kratos_thumb_ph_svg($post, $w = 512, $h = 288)
{
    $preset = kratos_option('g_postthumbnail_preset', 'solid');
    // 兼容旧数据：数据库里若仍存 skin 值（已下线），回退到 solid
    if ($preset === 'skin') {
        $preset = 'solid';
    }
    $text = kratos_thumb_ph_text($post);
    $is_full = kratos_option('g_postthumbnail_text_source', 'title_initial') === 'title_full';
    if ($is_full && mb_strlen($text, 'UTF-8') > 60) {
        $text = mb_substr($text, 0, 60, 'UTF-8');
    }
    $bg   = kratos_thumb_ph_bg($post);
    if (!$bg) $bg = '#5B8DEF';
    $fg   = kratos_thumb_ph_fg($bg);
    $font = kratos_thumb_ph_font_family(kratos_option('g_postthumbnail_font', 'sans'));

    $text_e = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $font_e = htmlspecialchars($font, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $short  = min($w, $h);
    $len    = mb_strlen($text, 'UTF-8');
    // 完整标题模式走多行折行，字号由 short 直接给一个饱满起始值（后面会按行数迭代缩），
    // 不再吃 ratio 表（表是给单行短文本设计的，长文本会被压得极小）。
    if ($is_full && $len > 6) {
        $fs = (int) round($short * 0.22);
    } else {
        $ratio  = kratos_thumb_ph_ratio($len);
        $fs     = (int) round($short * $ratio);
    }

    $cx = $w / 2;
    $cy = $h / 2;
    // 缩略图容器长宽比不固定（256×144、300×200、自适应等），slice 会裁掉短轴内容。
    // 统一用 none 让 SVG 拉伸铺满容器，文字用 text-anchor+y 已在中心，微形变可忽略。
    $ratio_mode = 'none';

    switch ($preset) {
        case 'gradient':
            $palette = kratos_thumb_ph_palette(kratos_option('g_postthumbnail_palette', 'material'));
            $seed = is_object($post) ? ($post->post_name ?: (string)$post->ID) : 'kratos';
            $i1 = abs(crc32($seed)) % count($palette);
            $i2 = ($i1 + 3) % count($palette);
            $c1 = $palette[$i1]; $c2 = $palette[$i2];
            $bg_el = '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
                   . '<stop offset="0" stop-color="' . $c1 . '"/>'
                   . '<stop offset="1" stop-color="' . $c2 . '"/></linearGradient></defs>'
                   . '<rect width="' . $w . '" height="' . $h . '" fill="url(#g)"/>';
            $fg = '#ffffff';
            break;

        case 'retro':
            $bg_r = '#EFE7D6'; $fg_r = '#3A2A1A';
            $bg_el = '<rect width="' . $w . '" height="' . $h . '" fill="' . $bg_r . '"/>'
                   . '<rect x="16" y="16" width="' . ($w - 32) . '" height="' . ($h - 32) . '" fill="none" stroke="' . $fg_r . '" stroke-width="4" vector-effect="non-scaling-stroke"/>'
                   . '<rect x="24" y="24" width="' . ($w - 48) . '" height="' . ($h - 48) . '" fill="none" stroke="' . $fg_r . '" stroke-width="1" vector-effect="non-scaling-stroke"/>';
            $fg = $fg_r;
            $font_e = htmlspecialchars("'Playfair Display', Georgia, serif", ENT_QUOTES | ENT_XML1, 'UTF-8');
            $ratio_mode = 'none';
            break;

        case 'grid':
            $bg_g = '#111111';
            $bg_el = '<rect width="' . $w . '" height="' . $h . '" fill="' . $bg_g . '"/>';
            for ($x = 0; $x <= $w; $x += 40) {
                $bg_el .= '<line x1="' . $x . '" y1="0" x2="' . $x . '" y2="' . $h . '" stroke="rgba(255,255,255,.06)" stroke-width="1"/>';
            }
            for ($y = 0; $y <= $h; $y += 40) {
                $bg_el .= '<line x1="0" y1="' . $y . '" x2="' . $w . '" y2="' . $y . '" stroke="rgba(255,255,255,.06)" stroke-width="1"/>';
            }
            $fg = '#ffffff';
            break;

        case 'notion':
            $palette = kratos_thumb_ph_palette(kratos_option('g_postthumbnail_palette', 'morandi'));
            $seed = is_object($post) ? ($post->post_name ?: (string)$post->ID) : 'kratos';
            $accent = $palette[abs(crc32($seed)) % count($palette)];
            $bg_el = '<rect width="' . $w . '" height="' . $h . '" fill="#F1EEE8"/>';
            // 完整标题模式：中央方块放大到 0.8*short，几乎铺满画布，给多行文字留空间
            $sq_ratio = ($is_full && $len > 6) ? 0.85 : 0.5;
            $sq = (int)($short * $sq_ratio);
            $rx = (int)($sq * 0.22);
            $sx = ($w - $sq) / 2; $sy = ($h - $sq) / 2;
            $bg_el .= '<rect x="' . $sx . '" y="' . $sy . '" width="' . $sq . '" height="' . $sq . '" rx="' . $rx . '" fill="' . $accent . '"/>';
            $fg = '#ffffff';
            if (!($is_full && $len > 6)) {
                $fs = (int)($sq * 0.5);
            }
            // 完整标题模式下把 usable 宽度限制到方块内，wrap 才不会溢出方块
            $notion_usable_w = $sq;
            break;

        case 'solid':
        default:
            $bg_el = '<rect width="' . $w . '" height="' . $h . '" fill="' . $bg . '"/>';
            break;
    }

    // 完整标题模式：估算字宽 + 贪心分行 + 多 tspan 输出。
    // CJK/全角按 1.0em、ASCII 按 0.55em 估宽；总行数超上限则整体缩字号后重排。
    $text_svg = '';
    if ($is_full && $len > 6) {
        $pad_ratio = 0.05;
        // notion 预设文字画在中央方块内，用方块宽度作为可用宽度
        // 5% 安全余量抵消字宽估算误差
        $usable = (isset($notion_usable_w) ? $notion_usable_w * 0.9 : $w * (1 - $pad_ratio * 2)) * 0.95;
        $max_lines = 5;
        $line_gap = 1.2;
        $cur_fs = $fs;
        // 迭代缩字号：行数超上限，或任一行的估宽超过 usable（防英文/混排溢出）
        for ($try = 0; $try < 10; $try++) {
            $lines = kratos_thumb_ph_wrap_lines($text, $cur_fs, $usable);
            $overflow = false;
            foreach ($lines as $ln) {
                if (kratos_thumb_ph_measure_em($ln) * $cur_fs > $usable) { $overflow = true; break; }
            }
            if (!$overflow && count($lines) <= $max_lines) break;
            $cur_fs = (int) max(10, round($cur_fs * 0.9));
            if ($cur_fs <= 10) break;
        }
        // 若仍超行，截尾并在末行加省略号
        if (count($lines) > $max_lines) {
            $lines = array_slice($lines, 0, $max_lines);
            $lines[$max_lines - 1] = mb_substr($lines[$max_lines - 1], 0, max(1, mb_strlen($lines[$max_lines - 1], 'UTF-8') - 1), 'UTF-8') . '…';
        }
        $n = count($lines);
        // 首行 y 偏移：让整块文本视觉居中。行高 = cur_fs * line_gap。
        $total_h = ($n - 1) * $cur_fs * $line_gap;
        $y0 = $cy - $total_h / 2;
        $tspans = '';
        foreach ($lines as $i => $ln) {
            $ln_e = htmlspecialchars($ln, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $ty = $y0 + $i * $cur_fs * $line_gap;
            $tspans .= '<tspan x="' . $cx . '" y="' . $ty . '" dy="-0.05em">' . $ln_e . '</tspan>';
        }
        $text_svg = '<text font-family="' . $font_e . '" font-size="' . $cur_fs . '" font-weight="700" fill="' . $fg . '" text-anchor="middle" dominant-baseline="central">' . $tspans . '</text>';
    } else {
        $text_svg = '<text x="' . $cx . '" y="' . $cy . '" dy="-0.05em" font-family="' . $font_e . '" font-size="' . $fs . '" font-weight="700" fill="' . $fg . '" text-anchor="middle" dominant-baseline="central">' . $text_e . '</text>';
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="' . $ratio_mode . '" width="' . $w . '" height="' . $h . '">'
         . $bg_el
         . $text_svg
         . '</svg>';
    return $svg;
}

/**
 * 按估算字宽贪心分行。返回若干行字符串。
 * 规则：CJK/全角 = 1.0em，ASCII/半角 = 0.55em。ASCII 单词尽量整体成行，超长强制截。
 */
function kratos_thumb_ph_wrap_lines($text, $fs, $usable_px)
{
    // 字宽估算（bold 700 sans-serif，含安全余量）：
    // - 大写字母/数字 ≈ 0.72em，小写字母 ≈ 0.58em，CJK 全角 ≈ 1.0em，ASCII 标点/空格 ≈ 0.35em
    $max_em = $usable_px / max(1, $fs);
    $lines = array();
    $line = '';
    $line_em = 0;
    $len = mb_strlen($text, 'UTF-8');
    $i = 0;
    while ($i < $len) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        if (preg_match('/^[A-Za-z0-9]$/', $ch)) {
            // 抓整个 ASCII 词（含数字）
            $word = '';
            while ($i < $len) {
                $c = mb_substr($text, $i, 1, 'UTF-8');
                if (!preg_match('/^[A-Za-z0-9]$/', $c)) break;
                $word .= $c;
                $i++;
            }
            $w_em = kratos_thumb_ph_measure_em($word);
            if ($line_em + $w_em > $max_em && $line !== '') {
                $lines[] = $line;
                $line = ''; $line_em = 0;
            }
            // 单词本身超行宽：按字符逐个塞，塞不下就换行
            if ($w_em > $max_em) {
                $wlen = strlen($word);
                for ($k = 0; $k < $wlen; $k++) {
                    $cc = $word[$k];
                    $c_em = kratos_thumb_ph_measure_em($cc);
                    if ($line_em + $c_em > $max_em && $line !== '') {
                        $lines[] = $line; $line = ''; $line_em = 0;
                    }
                    $line .= $cc; $line_em += $c_em;
                }
            } else {
                $line .= $word; $line_em += $w_em;
            }
        } else {
            $em = kratos_thumb_ph_measure_em($ch);
            if ($line_em + $em > $max_em && $line !== '') {
                $lines[] = $line;
                $line = ''; $line_em = 0;
            }
            if (!($line === '' && $ch === ' ')) {
                $line .= $ch;
                $line_em += $em;
            }
            $i++;
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines;
}

/**
 * 估算字符串在 bold 700 sans-serif 下的宽度（单位 em）。
 */
function kratos_thumb_ph_measure_em($s)
{
    $em = 0.0;
    $n = mb_strlen($s, 'UTF-8');
    for ($i = 0; $i < $n; $i++) {
        $ch = mb_substr($s, $i, 1, 'UTF-8');
        if (preg_match('/^[MW]$/', $ch))              $em += 0.95;
        elseif (preg_match('/^[A-Z]$/', $ch))         $em += 0.80;
        elseif (preg_match('/^[0-9]$/', $ch))         $em += 0.70;
        elseif (preg_match('/^[mw]$/', $ch))          $em += 0.85;
        elseif (preg_match('/^[a-z]$/', $ch))         $em += 0.60;
        elseif (preg_match('/^[\x20-\x7E]$/', $ch))   $em += 0.40;
        else                                          $em += 1.0;
    }
    return $em;
}

/**
 * 返回 data URL。
 */
function kratos_default_thumb_url($post, $w = 512, $h = 288)
{
    $svg = kratos_thumb_ph_svg($post, $w, $h);
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * 返回可直接 echo 的 HTML。
 * 输出纯 div + CSS 变量，5 个预设的形态与配色完全由浏览器渲染，
 * PHP 只算 hash 色板与提取文字。文字换行、字号自适应交给 CSS。
 * 需要 data URL（如 JSON/transient）的场景走 kratos_default_thumb_url()。
 */
function kratos_default_thumb_html($post, $w = 512, $h = 288)
{
    $preset = kratos_option('g_postthumbnail_preset', 'solid');
    if ($preset === 'skin') $preset = 'solid';
    $allowed = array('solid', 'gradient', 'retro', 'grid', 'notion');
    if (!in_array($preset, $allowed, true)) $preset = 'solid';

    $text   = kratos_thumb_ph_text($post);
    $is_full = kratos_option('g_postthumbnail_text_source', 'title_initial') === 'title_full';
    $len    = mb_strlen($text, 'UTF-8');
    if ($is_full && $len > 60) {
        $text = mb_substr($text, 0, 60, 'UTF-8');
        $len  = 60;
    }
    $wrap = ($is_full && $len > 6);

    // 色板 hash：与 SVG 端保持一致（同 post 出同色），前端只需读 CSS 变量。
    $bg     = kratos_thumb_ph_bg($post);
    if (!$bg) $bg = '#5B8DEF';
    $fg     = kratos_thumb_ph_fg($bg);

    $seed = is_object($post) ? ($post->post_name ?: (string)$post->ID) : 'kratos';

    // gradient：从 palette 取两色
    $g_palette = kratos_thumb_ph_palette(kratos_option('g_postthumbnail_palette', 'material'));
    $gi1 = abs(crc32($seed)) % count($g_palette);
    $gi2 = ($gi1 + 3) % count($g_palette);
    $c1 = $g_palette[$gi1]; $c2 = $g_palette[$gi2];

    // notion：从 morandi 色板取 accent
    $n_palette = kratos_thumb_ph_palette(kratos_option('g_postthumbnail_palette', 'morandi'));
    $accent = $n_palette[abs(crc32($seed)) % count($n_palette)];

    $font = kratos_option('g_postthumbnail_font', 'sans');
    $font_class = '';
    if ($font === 'serif') $font_class = ' kt-ph-font-serif';
    elseif ($font === 'mono') $font_class = ' kt-ph-font-mono';

    $alt = is_object($post) ? get_the_title($post) : '';
    $text_e = esc_html($text);
    $alt_e  = esc_attr($alt);

    $style = sprintf(
        '--kt-bg:%s;--kt-fg:%s;--kt-c1:%s;--kt-c2:%s;--kt-accent:%s;',
        esc_attr($bg), esc_attr($fg), esc_attr($c1), esc_attr($c2), esc_attr($accent)
    );

    $classes = 'k-thumb-ph is-preset-' . $preset . $font_class . ($wrap ? ' is-wrap' : '');
    $data_len = min($len, 14);

    // notion 预设有内嵌方块承载文字；其余预设文字直接放外层。
    if ($preset === 'notion') {
        $inner = '<span class="k-thumb-ph-inner"><span class="k-thumb-ph-text">' . $text_e . '</span></span>';
    } else {
        $inner = '<span class="k-thumb-ph-text">' . $text_e . '</span>';
    }

    return '<div class="' . $classes . '" data-len="' . (int)$data_len
         . '" style="' . $style . '" role="img" aria-label="' . $alt_e . '">'
         . $inner
         . '</div>';
}

/**
 * 是否启用文字渲染模式。
 */
function kratos_default_thumb_is_text_mode()
{
    return kratos_option('g_postthumbnail_mode', 'image') === 'text';
}

/**
 * 保存主题选项后清理会缓存 thumb 的 transient。
 * 仅当默认特色图相关字段真正变化时才清，避免每次保存都全表 DELETE + wp_cache_flush。
 * 相关文章/往日回顾/年度回顾把 thumb URL 存进了 transient，改预设后必须清缓存。
 */
function kratos_default_thumb_flush_caches()
{
    global $wpdb;
    // 保存主题选项即无差别清 otd/yr 相关 transient（用 wp_options 与 site_transient 兜底）
    $patterns = array(
        '_transient_kratos_otd_%',
        '_transient_timeout_kratos_otd_%',
        '_transient_kratos_yr_%',
        '_transient_timeout_kratos_yr_%',
    );
    foreach ($patterns as $p) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $p
        ));
    }
    // 若启用对象缓存（Redis/Memcached），transient 不落库，需按已知 key 主动删
    // otd/yr 的 key 含日期/年份，无法穷举——保守起见清一次 alloptions 组
    wp_cache_delete('alloptions', 'options');
}
add_action('csf_kratos_options_saved', 'kratos_default_thumb_flush_caches');

/**
 * 前台样式：仅 skin 预设 / bg_mode=skin 时生效的 <span> 覆盖。
 */
add_action('wp_head', function () {
    if (!kratos_default_thumb_is_text_mode()) return;
    ?>
<style id="kratos-thumb-ph-inline">
/* 外壳：容器查询做字号自适应，纯 CSS 承载 5 个预设的形态与配色。
   PHP 端只算 hash 色（写进 --kt-c1/--kt-c2/--kt-accent/--kt-bg/--kt-fg），
   文字换行由浏览器 line-clamp 兜底，不再服务端估宽。 */
.k-thumb-ph{
  container-type:inline-size;
  position:relative;
  display:flex;align-items:center;justify-content:center;
  box-sizing:border-box;
  width:100%;aspect-ratio:16/9;
  background:var(--kt-bg,#5B8DEF);
  color:var(--kt-fg,#fff);
  font-family:-apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
  font-weight:700;line-height:1;
  user-select:none;letter-spacing:-0.02em;
  overflow:hidden;text-align:center;
}
.k-thumb-ph.kt-ph-font-serif{font-family:Georgia,"Songti SC","STSong",serif}
.k-thumb-ph.kt-ph-font-mono{font-family:"JetBrains Mono","SFMono-Regular",Consolas,monospace}
.k-thumb-ph .k-thumb-ph-text{
  display:block;max-width:100%;
  white-space:nowrap;overflow:hidden;text-overflow:clip;
}

/* 尺寸容器覆盖：.a-thumb 桌面固定尺寸、移动端 padding-bottom hack */
.a-thumb{position:relative}
.a-thumb .k-thumb-ph{width:100%;height:100%;aspect-ratio:auto}
@media screen and (max-width: 768px){
  .k-main .board .article-panel .a-thumb > a{
    position:absolute;inset:0;display:block;overflow:hidden;
  }
  .k-main .board .article-panel .a-thumb > a > img,
  .k-main .board .article-panel .a-thumb .k-thumb-ph{
    position:absolute;inset:0;width:100%;height:100%;max-width:100%;
    aspect-ratio:auto;object-fit:fill;
  }
}

/* 短文本字号自适应：按 data-len 分档（同 SVG 端 ratio 表校准），min() 加绝对上限 */
.k-thumb-ph[data-len="1"] .k-thumb-ph-text{font-size:min(24cqw,120px)}
.k-thumb-ph[data-len="2"] .k-thumb-ph-text{font-size:min(20cqw,96px)}
.k-thumb-ph[data-len="3"] .k-thumb-ph-text{font-size:min(16cqw,80px)}
.k-thumb-ph[data-len="4"] .k-thumb-ph-text{font-size:min(13cqw,68px)}
.k-thumb-ph[data-len="5"] .k-thumb-ph-text{font-size:min(11cqw,56px)}
.k-thumb-ph[data-len="6"] .k-thumb-ph-text{font-size:min(10cqw,50px)}
.k-thumb-ph[data-len="7"] .k-thumb-ph-text{font-size:min(9cqw,44px)}
.k-thumb-ph[data-len="8"] .k-thumb-ph-text{font-size:min(8cqw,40px)}
.k-thumb-ph[data-len="9"] .k-thumb-ph-text{font-size:min(7cqw,36px)}
.k-thumb-ph[data-len="10"] .k-thumb-ph-text{font-size:min(6.4cqw,32px)}
.k-thumb-ph[data-len="11"] .k-thumb-ph-text{font-size:min(5.8cqw,30px)}
.k-thumb-ph[data-len="12"] .k-thumb-ph-text{font-size:min(5.3cqw,28px)}
.k-thumb-ph[data-len="13"] .k-thumb-ph-text{font-size:min(4.9cqw,26px)}
.k-thumb-ph[data-len="14"] .k-thumb-ph-text{font-size:min(4.5cqw,24px)}
@supports not (font-size: 1cqw){
  .k-thumb-ph .k-thumb-ph-text{font-size:clamp(20px,6vw,64px)}
}

/* 完整标题模式：允许换行，line-clamp 5 行封顶（浏览器原生排版比 PHP 估宽准） */
.k-thumb-ph.is-wrap .k-thumb-ph-text,
.a-thumb .k-thumb-ph.is-wrap .k-thumb-ph-text,
.k-main .board .article-panel .a-thumb .k-thumb-ph.is-wrap .k-thumb-ph-text{
  white-space:normal;
  word-break:break-word;
  overflow-wrap:anywhere;
  line-height:1.2;
  letter-spacing:0;
  padding:0 4%;
  font-size:min(11cqw,44px);
  display:-webkit-box;
  -webkit-box-orient:vertical;
  -webkit-line-clamp:5;
  line-clamp:5;
  overflow:hidden;text-overflow:ellipsis;
}
@supports not (font-size: 1cqw){
  .k-thumb-ph.is-wrap .k-thumb-ph-text{font-size:clamp(16px,4.5vw,36px)}
}

/* ============ 预设 ============ */
/* solid: 使用 --kt-bg / --kt-fg，已由基类承载，无需追加 */
.k-thumb-ph.is-preset-solid{
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.08);
}

/* gradient: 从 --kt-c1 到 --kt-c2 的对角线渐变，白字 */
.k-thumb-ph.is-preset-gradient{
  background:linear-gradient(135deg,var(--kt-c1,#5B8DEF) 0%,var(--kt-c2,#8E7DBE) 100%);
  color:#fff;
}

/* retro: 米色底 + 双重描边 + Playfair 衬线 */
.k-thumb-ph.is-preset-retro{
  background:#EFE7D6;
  color:#3A2A1A;
  font-family:'Playfair Display',Georgia,'Songti SC',serif;
  box-shadow:
    inset 0 0 0 4px #3A2A1A,
    inset 0 0 0 5px #EFE7D6,
    inset 0 0 0 6px #3A2A1A;
}

/* grid: 深底 + 40px 网格线（repeating-linear-gradient 两层交叉） */
.k-thumb-ph.is-preset-grid{
  background:
    repeating-linear-gradient(to right, rgba(255,255,255,.06) 0 1px, transparent 1px 40px),
    repeating-linear-gradient(to bottom, rgba(255,255,255,.06) 0 1px, transparent 1px 40px),
    #111111;
  color:#fff;
}

/* notion: 米色底 + 中央方块（吃 --kt-accent），文字放方块内 */
.k-thumb-ph.is-preset-notion{
  background:#F1EEE8;
  color:#fff;
}
.k-thumb-ph.is-preset-notion .k-thumb-ph-inner{
  display:flex;align-items:center;justify-content:center;
  width:50cqw;max-width:50%;aspect-ratio:1;
  border-radius:11cqw;
  background:var(--kt-accent,#B8A99A);
  box-shadow:0 1cqw 3cqw rgba(0,0,0,.04);
}
/* 完整标题模式下方块放大到 85%，给多行文字留空间 */
.k-thumb-ph.is-preset-notion.is-wrap .k-thumb-ph-inner{
  width:85cqw;max-width:85%;
}
.k-thumb-ph.is-preset-notion .k-thumb-ph-text{
  padding:0 8%;
  font-size:min(11cqw,44px);
}
.k-thumb-ph.is-preset-notion[data-len="1"] .k-thumb-ph-text{font-size:min(18cqw,80px)}
.k-thumb-ph.is-preset-notion[data-len="2"] .k-thumb-ph-text{font-size:min(15cqw,70px)}
.k-thumb-ph.is-preset-notion[data-len="3"] .k-thumb-ph-text{font-size:min(12cqw,56px)}
.k-thumb-ph.is-preset-notion[data-len="4"] .k-thumb-ph-text{font-size:min(10cqw,48px)}
.k-thumb-ph.is-preset-notion.is-wrap .k-thumb-ph-text{
  white-space:normal;word-break:break-word;overflow-wrap:anywhere;
  line-height:1.2;font-size:min(9cqw,36px);
  display:-webkit-box;-webkit-box-orient:vertical;
  -webkit-line-clamp:5;line-clamp:5;
  overflow:hidden;text-overflow:ellipsis;
}
@supports not (font-size: 1cqw){
  .k-thumb-ph.is-preset-notion .k-thumb-ph-inner{width:50%;border-radius:8%}
  .k-thumb-ph.is-preset-notion.is-wrap .k-thumb-ph-inner{width:85%}
  .k-thumb-ph.is-preset-notion .k-thumb-ph-text{font-size:clamp(16px,4vw,36px)}
}
</style>
    <?php
}, 20);
