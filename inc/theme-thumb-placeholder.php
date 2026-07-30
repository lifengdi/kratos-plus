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
 * skin 预设走 <span> 以吃 CSS 变量；其余预设走 <img>。
 */
function kratos_default_thumb_html($post, $w = 512, $h = 288)
{
    $url = kratos_default_thumb_url($post, $w, $h);
    $alt = is_object($post) ? esc_attr(get_the_title($post)) : '';
    return '<img src="' . $url . '" alt="' . $alt . '" loading="lazy" />';
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
/* 默认：独立位置（如相关文章 span）用 aspect-ratio 撑高 */
.kratos-thumb-ph{
  container-type:inline-size;
  display:grid;place-items:center;
  box-sizing:border-box;
  width:100%;aspect-ratio:16/9;
  background:var(--kr-skin-accent,#5B8DEF);
  color:#fff;
  font-size:min(18.6cqw,72px);
  font-family:-apple-system,"Segoe UI","PingFang SC",sans-serif;
  font-weight:700;line-height:1;
  user-select:none;letter-spacing:-0.5px;
  white-space:nowrap;
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.12);
  overflow:hidden;text-align:center;
}
/* 桌面端 .a-thumb 是 256×144 明确尺寸，img 原有规则 width/height:100%，无需改；
   只需保证 span 版跟 img 一致行为。 */
.a-thumb{position:relative}
.a-thumb .kratos-thumb-ph{width:100%;height:100%;aspect-ratio:auto}
/* 手机端 .a-thumb 用 padding-bottom hack（height:0），
   原 img 规则 height:auto 会让图片按自然比例撑高溢出容器，文字视觉偏下——
   把 img/span 都绝对定位铺满 .a-thumb。 */
@media screen and (max-width: 768px){
  .k-main .board .article-panel .a-thumb > a{
    position:absolute;top:0;left:0;right:0;bottom:0;display:block;overflow:hidden;
  }
  .k-main .board .article-panel .a-thumb > a > img,
  .k-main .board .article-panel .a-thumb .kratos-thumb-ph{
    position:absolute;top:0;left:0;
    width:100%;height:100%;
    max-width:100%;
    aspect-ratio:auto;
    object-fit:fill;
  }
}
/* cqw = 容器宽度%；aspect-ratio:3/2 下短边 = 宽度 × 2/3；再用 min() 加绝对上限，防止宽卡片下字号失控 */
.kratos-thumb-ph[data-len="1"]{font-size:min(24cqw,120px)}
.kratos-thumb-ph[data-len="2"]{font-size:min(20cqw,96px)}
.kratos-thumb-ph[data-len="3"]{font-size:min(16cqw,80px)}
.kratos-thumb-ph[data-len="4"]{font-size:min(13cqw,68px)}
.kratos-thumb-ph[data-len="5"]{font-size:min(11cqw,56px)}
.kratos-thumb-ph[data-len="6"]{font-size:min(10cqw,50px)}
.kratos-thumb-ph[data-len="7"]{font-size:min(9cqw,44px)}
.kratos-thumb-ph[data-len="8"]{font-size:min(8cqw,40px)}
.kratos-thumb-ph[data-len="9"]{font-size:min(7cqw,36px)}
.kratos-thumb-ph[data-len="10"]{font-size:min(6.4cqw,32px)}
.kratos-thumb-ph[data-len="11"]{font-size:min(5.8cqw,30px)}
.kratos-thumb-ph[data-len="12"]{font-size:min(5.3cqw,28px)}
.kratos-thumb-ph[data-len="13"]{font-size:min(4.9cqw,26px)}
.kratos-thumb-ph[data-len="14"]{font-size:min(4.5cqw,24px)}
/* container query 不支持时的老浏览器兜底 */
@supports not (font-size: 1cqw){
  .kratos-thumb-ph{font-size:clamp(20px,6vw,64px)}
}
.kratos-thumb-ph.kt-ph-font-serif{font-family:Georgia,"Songti SC",serif}
.kratos-thumb-ph.kt-ph-font-mono{font-family:"JetBrains Mono",Consolas,monospace}
/* 完整标题模式：允许折行，最多 3 行，超出省略。
   用高特异性 + !important 兜住 .a-thumb 后代选择器与移动端媒体查询覆盖。 */
.kratos-thumb-ph.is-wrap,
.a-thumb .kratos-thumb-ph.is-wrap,
.k-main .board .article-panel .a-thumb .kratos-thumb-ph.is-wrap{
  white-space:normal !important;
  word-break:break-word;
  overflow-wrap:anywhere;
  line-height:1.2 !important;
  letter-spacing:0 !important;
  padding:3% 5% !important;
  font-size:min(16cqw,60px) !important;
  display:-webkit-box !important;
  -webkit-box-orient:vertical;
  -webkit-line-clamp:5;
  line-clamp:5;
  place-items:unset;
  text-align:center;
}
@supports not (font-size: 1cqw){
  .kratos-thumb-ph.is-wrap{font-size:clamp(18px,6vw,48px) !important}
}
</style>
    <?php
}, 20);
