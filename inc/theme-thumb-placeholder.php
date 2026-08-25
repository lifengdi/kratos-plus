<?php
/**
 * 默认特色图 · 文字+颜色渲染
 *
 * 当文章无特色图、正文亦无图时，按主题选项渲染文字占位图。
 * 唯一对外函数：kratos_default_thumb_html($post, $w, $h) —— 返回 <div class="k-thumb-ph"> 片段，
 * 5 个预设的形态与配色完全在浏览器端由 CSS 完成，PHP 只算 hash 色板与提取文字。
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
 * 返回可直接 echo 的 HTML。
 * 输出纯 div + CSS 变量，5 个预设的形态与配色完全由浏览器渲染，
 * PHP 只算 hash 色板与提取文字。文字换行、字号自适应交给 CSS。
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
/* 外壳：优先填满父容器（thumbnail 位一般是显式尺寸或 aspect-ratio 容器），
   父容器没高度时用 aspect-ratio:16/9 兜底。文字换行/字号自适应由 CSS 完成。 */
.k-thumb-ph{
  container-type:inline-size;
  position:relative;
  display:flex;align-items:center;justify-content:center;
  box-sizing:border-box;
  width:100%;height:100%;
  aspect-ratio:16/9;
  background-color:var(--kt-bg,#5B8DEF);
  background-image:none;
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

/* 宿主容器无论是显式 px 尺寸、aspect-ratio 还是 padding-top hack，都统一走
   "position:relative 承接 + 内部 div 绝对铺满" 的路径，稳过 <a>/<span> 中间层。 */
.a-thumb{position:relative}
.kratos-related-thumb.kratos-related-thumb-ph,
.kotd-thumb.kotd-thumb-ph{position:relative;background:transparent}
.a-thumb .k-thumb-ph,
.kratos-related-thumb-ph .k-thumb-ph,
.kotd-thumb-ph .k-thumb-ph,
.khf-thumb .k-thumb-ph{
  position:absolute;inset:0;
  width:100%;height:100%;
  aspect-ratio:auto;
  border-radius:inherit;
}
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

/* gradient: 从 --kt-c1 到 --kt-c2 的对角线渐变，白字。
   用 background-image 而不是 shorthand，避免被外层 background 短属性重置成透明 */
.k-thumb-ph.is-preset-gradient{
  background-color:var(--kt-c1,#5B8DEF);
  background-image:linear-gradient(135deg,var(--kt-c1,#5B8DEF) 0%,var(--kt-c2,#8E7DBE) 100%);
  color:#fff;
}

/* retro: 米色底 + 双重描边 + Playfair 衬线 */
.k-thumb-ph.is-preset-retro{
  background-color:#EFE7D6;
  background-image:none;
  color:#3A2A1A;
  font-family:'Playfair Display',Georgia,'Songti SC',serif;
  box-shadow:
    inset 0 0 0 2px #3A2A1A,
    inset 0 0 0 3px #EFE7D6,
    inset 0 0 0 4px #3A2A1A;
}

/* grid: 深底 + 40px 网格线（repeating-linear-gradient 两层交叉） */
.k-thumb-ph.is-preset-grid{
  background-color:#111111;
  background-image:
    repeating-linear-gradient(to right, rgba(255,255,255,.06) 0 1px, transparent 1px 40px),
    repeating-linear-gradient(to bottom, rgba(255,255,255,.06) 0 1px, transparent 1px 40px);
  color:#fff;
}

/* notion: 米色底 + 中央方块（吃 --kt-accent），文字放方块内 */
.k-thumb-ph.is-preset-notion{
  background-color:#F1EEE8;
  background-image:none;
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
