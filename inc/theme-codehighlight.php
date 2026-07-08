<?php

/**
 * 代码高亮：支持 Prism.js / highlight.js / highlight.php 三种方案
 * 前端方案（Prism / hljs）支持 CDN 与本地缓存两种加载方式
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

define('KRATOS_PRISM_VER', '1.29.0');
define('KRATOS_PRISM_THEMES_VER', '1.9.0');
define('KRATOS_HLJS_VER', '11.10.0');
define('KRATOS_CODEHL_DEFAULT_CDN', 'https://cdn.jsdelivr.net/npm');

/**
 * 返回浏览器可用的 highlight.js UMD 主脚本 URL。
 *  - npm 包 highlight.js 的 lib/*.js 都是 CommonJS（require('./core')），<script src=> 直接加载会报错；
 *  - 浏览器可用的 UMD 构建在 @highlightjs/cdn-assets npm 包，所有主流 npm CDN（jsdelivr/unpkg/国内镜像）都能命中。
 */
function kratos_codehl_hljs_js_rel()
{
    return '@highlightjs/cdn-assets@' . KRATOS_HLJS_VER . '/highlight.min.js';
}

function kratos_codehl_hljs_js_url()
{
    return kratos_codehl_asset_url(kratos_codehl_hljs_js_rel());
}

/**
 * Prism 主题清单：核心 8 个 + prism-themes 社区扩展 37 个。
 * 值 = [显示名, 包路径(相对包根)] —— 包前缀 (prismjs / prism-themes) 由 build_url 拼接。
 */
function kratos_codehl_prism_themes()
{
    return array(
        // ===== Prism 官方核心主题 =====
        'core/prism' => array('Default', 'prismjs@' . KRATOS_PRISM_VER . '/themes/prism.min.css'),
        'core/prism-coy' => array('Coy', 'prismjs@' . KRATOS_PRISM_VER . '/themes/prism-coy.min.css'),
        'core/prism-dark' => array('Dark', 'prismjs@' . KRATOS_PRISM_VER . '/themes/prism-dark.min.css'),
        'core/prism-funky' => array('Funky', 'prismjs@' . KRATOS_PRISM_VER . '/themes/prism-funky.min.css'),
        'core/prism-okaidia' => array('Okaidia', 'prismjs@' . KRATOS_PRISM_VER . '/themes/prism-okaidia.min.css'),
        'core/prism-solarizedlight' => array('Solarized Light', 'prismjs@' . KRATOS_PRISM_VER . '/themes/prism-solarizedlight.min.css'),
        'core/prism-tomorrow' => array('Tomorrow Night', 'prismjs@' . KRATOS_PRISM_VER . '/themes/prism-tomorrow.min.css'),
        'core/prism-twilight' => array('Twilight', 'prismjs@' . KRATOS_PRISM_VER . '/themes/prism-twilight.min.css'),
        // ===== prism-themes 社区扩展包 =====
        'ext/a11y-dark' => array('A11y Dark', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-a11y-dark.css'),
        'ext/atom-dark' => array('Atom Dark', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-atom-dark.css'),
        'ext/base16-ateliersulphurpool-light' => array('Base16 Ateliersulphurpool Light', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-base16-ateliersulphurpool.light.css'),
        'ext/cb' => array('CB', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-cb.css'),
        'ext/coldark-cold' => array('Coldark Cold', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-coldark-cold.css'),
        'ext/coldark-dark' => array('Coldark Dark', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-coldark-dark.css'),
        'ext/coy-without-shadows' => array('Coy without Shadows', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-coy-without-shadows.css'),
        'ext/darcula' => array('Darcula', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-darcula.css'),
        'ext/dracula' => array('Dracula', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-dracula.css'),
        'ext/duotone-dark' => array('Duotone Dark', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-duotone-dark.css'),
        'ext/duotone-earth' => array('Duotone Earth', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-duotone-earth.css'),
        'ext/duotone-forest' => array('Duotone Forest', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-duotone-forest.css'),
        'ext/duotone-light' => array('Duotone Light', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-duotone-light.css'),
        'ext/duotone-sea' => array('Duotone Sea', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-duotone-sea.css'),
        'ext/duotone-space' => array('Duotone Space', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-duotone-space.css'),
        'ext/ghcolors' => array('GH Colors', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-ghcolors.css'),
        'ext/gruvbox-dark' => array('Gruvbox Dark', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-gruvbox-dark.css'),
        'ext/gruvbox-light' => array('Gruvbox Light', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-gruvbox-light.css'),
        'ext/holi-theme' => array('Holi', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-holi-theme.css'),
        'ext/hopscotch' => array('Hopscotch', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-hopscotch.css'),
        'ext/laserwave' => array('Laserwave', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-laserwave.css'),
        'ext/lucario' => array('Lucario', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-lucario.css'),
        'ext/material-dark' => array('Material Dark', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-material-dark.css'),
        'ext/material-light' => array('Material Light', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-material-light.css'),
        'ext/material-oceanic' => array('Material Oceanic', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-material-oceanic.css'),
        'ext/night-owl' => array('Night Owl', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-night-owl.css'),
        'ext/nord' => array('Nord', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-nord.css'),
        'ext/one-dark' => array('One Dark', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-one-dark.css'),
        'ext/one-light' => array('One Light', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-one-light.css'),
        'ext/pojoaque' => array('Pojoaque', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-pojoaque.css'),
        'ext/shades-of-purple' => array('Shades of Purple', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-shades-of-purple.css'),
        'ext/solarized-dark-atom' => array('Solarized Dark Atom', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-solarized-dark-atom.css'),
        'ext/synthwave84' => array('Synthwave 84', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-synthwave84.css'),
        'ext/vs' => array('Visual Studio (Prism)', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-vs.css'),
        'ext/vsc-dark-plus' => array('VSC Dark+', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-vsc-dark-plus.css'),
        'ext/xonokai' => array('Xonokai', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-xonokai.css'),
        'ext/z-touch' => array('Z-Touch', 'prism-themes@' . KRATOS_PRISM_THEMES_VER . '/themes/prism-z-touch.css'),
    );
}

/**
 * highlight.js 主题清单：73 个官方主题（v11.10.0 src/styles）
 */
function kratos_codehl_hljs_themes()
{
    $names = array(
        '1c-light' => '1C Light', 'a11y-dark' => 'A11y Dark', 'a11y-light' => 'A11y Light',
        'agate' => 'Agate', 'an-old-hope' => 'An Old Hope', 'androidstudio' => 'Android Studio',
        'arduino-light' => 'Arduino Light', 'arta' => 'Arta', 'ascetic' => 'Ascetic',
        'atom-one-dark' => 'Atom One Dark', 'atom-one-dark-reasonable' => 'Atom One Dark Reasonable',
        'atom-one-light' => 'Atom One Light', 'brown-paper' => 'Brown Paper',
        'codepen-embed' => 'CodePen Embed', 'color-brewer' => 'Color Brewer',
        'dark' => 'Dark', 'default' => 'Default', 'devibeans' => 'DeVibeans',
        'docco' => 'Docco', 'far' => 'FAR', 'felipec' => 'Felipec', 'foundation' => 'Foundation',
        'github' => 'GitHub', 'github-dark' => 'GitHub Dark', 'github-dark-dimmed' => 'GitHub Dark Dimmed',
        'gml' => 'GML', 'googlecode' => 'Google Code',
        'gradient-dark' => 'Gradient Dark', 'gradient-light' => 'Gradient Light',
        'grayscale' => 'Grayscale', 'hybrid' => 'Hybrid', 'idea' => 'IDEA',
        'intellij-light' => 'IntelliJ Light', 'ir-black' => 'IR Black',
        'isbl-editor-dark' => 'Isbl Editor Dark', 'isbl-editor-light' => 'Isbl Editor Light',
        'kimbie-dark' => 'Kimbie Dark', 'kimbie-light' => 'Kimbie Light',
        'lightfair' => 'Lightfair', 'lioshi' => 'Lioshi', 'magula' => 'Magula',
        'mono-blue' => 'Mono Blue', 'monokai' => 'Monokai', 'monokai-sublime' => 'Monokai Sublime',
        'night-owl' => 'Night Owl', 'nnfx-dark' => 'NNFX Dark', 'nnfx-light' => 'NNFX Light',
        'nord' => 'Nord', 'obsidian' => 'Obsidian',
        'panda-syntax-dark' => 'Panda Syntax Dark', 'panda-syntax-light' => 'Panda Syntax Light',
        'paraiso-dark' => 'Paraiso Dark', 'paraiso-light' => 'Paraiso Light',
        'pojoaque' => 'Pojoaque', 'purebasic' => 'PureBASIC',
        'qtcreator-dark' => 'Qt Creator Dark', 'qtcreator-light' => 'Qt Creator Light',
        'rainbow' => 'Rainbow', 'routeros' => 'RouterOS',
        'school-book' => 'School Book', 'shades-of-purple' => 'Shades of Purple',
        'srcery' => 'Srcery', 'stackoverflow-dark' => 'StackOverflow Dark',
        'stackoverflow-light' => 'StackOverflow Light', 'sunburst' => 'Sunburst',
        'tokyo-night-dark' => 'Tokyo Night Dark', 'tokyo-night-light' => 'Tokyo Night Light',
        'tomorrow-night-blue' => 'Tomorrow Night Blue', 'tomorrow-night-bright' => 'Tomorrow Night Bright',
        'vs' => 'Visual Studio', 'vs2015' => 'Visual Studio 2015',
        'xcode' => 'Xcode', 'xt256' => 'XT256',
    );
    ksort($names);
    return $names;
}

function kratos_codehl_prism_options()
{
    $opts = array();
    foreach (kratos_codehl_prism_themes() as $key => $info) {
        $prefix = (strpos($key, 'core/') === 0) ? '' : '[扩展] ';
        $opts[$key] = $prefix . $info[0];
    }
    return $opts;
}

function kratos_codehl_hljs_options()
{
    return kratos_codehl_hljs_themes();
}

function kratos_codehl_prism_theme_path($key)
{
    $themes = kratos_codehl_prism_themes();
    if (!isset($themes[$key])) {
        $key = 'core/prism-tomorrow';
    }
    return $themes[$key][1];
}

function kratos_codehl_hljs_theme_path($key)
{
    $themes = kratos_codehl_hljs_themes();
    if (!isset($themes[$key])) {
        $key = 'github-dark';
    }
    return 'highlight.js@' . KRATOS_HLJS_VER . '/styles/' . $key . '.min.css';
}

function kratos_codehl_enabled()
{
    return (bool) kratos_option('g_codehl', false);
}

function kratos_codehl_cdn_base()
{
    $base = trim((string) kratos_option('g_codehl_cdn_base', KRATOS_CODEHL_DEFAULT_CDN));
    if ($base === '') {
        $base = KRATOS_CODEHL_DEFAULT_CDN;
    }
    return rtrim($base, '/');
}

function kratos_codehl_local_root()
{
    $up = wp_get_upload_dir();
    return array(
        'dir' => trailingslashit($up['basedir']) . 'kratos-codehl',
        'url' => trailingslashit($up['baseurl']) . 'kratos-codehl',
    );
}

/**
 * 解析单个 npm 资源的加载地址。
 *  - $pkg_path: 形如 "prismjs@1.29.0/themes/prism-tomorrow.min.css"
 * 返回 URL 字符串。本地模式下若文件未缓存会触发下载；下载失败回退 CDN。
 */
function kratos_codehl_asset_url($pkg_path)
{
    $source = kratos_option('g_codehl_source', 'cdn');
    $cdn_url = kratos_codehl_cdn_base() . '/' . ltrim($pkg_path, '/');
    if ($source !== 'local') {
        return $cdn_url;
    }

    $root = kratos_codehl_local_root();
    $rel = ltrim($pkg_path, '/');
    $local_file = trailingslashit($root['dir']) . $rel;
    $local_url = trailingslashit($root['url']) . $rel;

    if (file_exists($local_file) && filesize($local_file) > 0) {
        return $local_url;
    }
    return $cdn_url;
}

/**
 * 一次性把所有"动态懒加载"资源（Prism 语言组件 + 全部 prism-themes/hljs 主题）抓到本地。
 * 在切换到"本地缓存"模式时由后台触发，之后纯静态服务，与 Apache/Nginx/Caddy/IIS 无关。
 *
 * 设计取舍：
 *  - 总下载量 ~5MB（Prism 语言 ~3MB + prism-themes ~600KB + hljs themes ~1.5MB），可接受；
 *  - 已存在的文件跳过；
 *  - 抓取出错的语言静默跳过（多数语言长期稳定，少量小众失败不影响整体体验）；
 *  - 通过 admin-post 端点触发，避免长时间阻塞页面渲染。
 */
function kratos_codehl_warmup_all()
{
    @set_time_limit(300);
    $stats = array('ok' => 0, 'fail' => 0, 'skip' => 0);
    $jobs = array();

    // (1) Prism 全部语言组件（200+）
    $prism_langs = kratos_codehl_prism_language_list();
    foreach ($prism_langs as $lang) {
        $jobs[] = 'prismjs@' . KRATOS_PRISM_VER . '/components/prism-' . $lang . '.min.js';
    }
    // (2) Prism 全部主题
    foreach (kratos_codehl_prism_themes() as $info) {
        $jobs[] = $info[1];
    }
    // (3) highlight.js 全部主题
    foreach (kratos_codehl_hljs_themes() as $key => $name) {
        $jobs[] = 'highlight.js@' . KRATOS_HLJS_VER . '/styles/' . $key . '.min.css';
    }
    // (4) highlight.js 浏览器 UMD 主脚本（@highlightjs/cdn-assets 包）
    $jobs[] = kratos_codehl_hljs_js_rel();

    $root = kratos_codehl_local_root();
    foreach ($jobs as $rel) {
        $local_file = trailingslashit($root['dir']) . $rel;
        if (file_exists($local_file) && filesize($local_file) > 0) {
            $stats['skip']++;
            continue;
        }
        $cdn_url = kratos_codehl_cdn_base() . '/' . $rel;
        if (kratos_codehl_fetch_to_local($cdn_url, $local_file)) {
            $stats['ok']++;
        } else {
            $stats['fail']++;
        }
    }
    update_option('kratos_codehl_warmup_at', time(), false);
    update_option('kratos_codehl_warmup_stats', $stats, false);
    return $stats;
}

/**
 * Prism 语言短名清单（v1.29.0 components/prism-*.js，去掉 prefix 与 .min.js）。
 * 来源 prismjs/components.js 的 languages 键。
 */
function kratos_codehl_prism_language_list()
{
    return array(
        'core', 'markup', 'css', 'clike', 'javascript',
        'abap', 'abnf', 'actionscript', 'ada', 'agda', 'al', 'antlr4', 'apacheconf', 'apex', 'apl',
        'applescript', 'aql', 'arduino', 'arff', 'armasm', 'arturo', 'asciidoc', 'aspnet', 'asm6502',
        'asmatmel', 'autohotkey', 'autoit', 'avisynth', 'avro-idl', 'awk',
        'bash', 'basic', 'batch', 'bbcode', 'bbj', 'bicep', 'birb', 'bison', 'bnf', 'bqn', 'brainfuck',
        'brightscript', 'bro', 'bsl',
        'c', 'cfscript', 'chaiscript', 'cil', 'cilkc', 'cilkcpp', 'clojure', 'cmake', 'cobol', 'coffeescript',
        'concurnas', 'cooklang', 'coq', 'cpp', 'crystal', 'csharp', 'cshtml', 'csp', 'css-extras', 'csv',
        'cue', 'cypher',
        'd', 'dart', 'dataweave', 'dax', 'dhall', 'diff', 'django', 'dns-zone-file', 'docker', 'dot',
        'ebnf', 'editorconfig', 'eiffel', 'ejs', 'elixir', 'elm', 'erb', 'erlang', 'etlua', 'excel-formula',
        'factor', 'false', 'firestore-security-rules', 'flow', 'fortran', 'fsharp', 'ftl',
        'gap', 'gcode', 'gdscript', 'gedcom', 'gettext', 'gherkin', 'git', 'glsl', 'gml', 'gn',
        'go', 'go-module', 'gradle', 'graphql', 'groovy',
        'haml', 'handlebars', 'haskell', 'haxe', 'hcl', 'hlsl', 'hoon', 'hpkp', 'hsts', 'http', 'ichigojam',
        'icon', 'icu-message-format', 'idris', 'iecst', 'ignore', 'inform7', 'ini', 'io',
        'j', 'java', 'javadoc', 'javadoclike', 'javastacktrace', 'jexl', 'jolie', 'jq', 'js-extras',
        'js-templates', 'jsdoc', 'json', 'json5', 'jsonp', 'jsstacktrace', 'jsx', 'julia',
        'keepalived', 'keyman', 'kotlin', 'kumir', 'kusto',
        'latex', 'latte', 'less', 'lilypond', 'liquid', 'lisp', 'livescript', 'llvm', 'log', 'lolcode',
        'lua',
        'magma', 'makefile', 'markdown', 'markup-templating', 'mata', 'matlab', 'maxscript', 'mel',
        'mermaid', 'metafont', 'mizar', 'mongodb', 'monkey', 'moonscript', 'n1ql', 'n4js', 'nand2tetris-hdl',
        'naniscript', 'nasm', 'neon', 'nevod', 'nginx', 'nim', 'nix', 'nsis',
        'objectivec', 'ocaml', 'odin', 'opencl', 'openqasm', 'oz',
        'parigp', 'parser', 'pascal', 'pascaligo', 'psl', 'pcaxis', 'peoplecode', 'perl', 'php', 'phpdoc',
        'php-extras', 'plant-uml', 'plsql', 'powerquery', 'powershell', 'processing', 'prolog', 'promql',
        'properties', 'protobuf', 'pug', 'puppet', 'pure', 'purebasic', 'purescript', 'python',
        'q', 'qore', 'qsharp', 'r', 'racket', 'reason', 'regex', 'rego', 'renpy', 'rescript', 'rest',
        'rip', 'roboconf', 'robotframework', 'ruby', 'rust',
        'sas', 'sass', 'scala', 'scheme', 'scss', 'shell-session', 'smali', 'smalltalk', 'smarty',
        'sml', 'solidity', 'solution-file', 'soy', 'sparql', 'splunk-spl', 'sqf', 'sql', 'squirrel',
        'stan', 'stata', 'iecst', 'stylus', 'supercollider', 'swift', 'systemd',
        't4-templating', 't4-cs', 't4-vb', 'tap', 'tcl', 'tt2', 'textile', 'toml', 'tremor', 'turtle',
        'twig', 'typescript', 'typoscript', 'unrealscript', 'uorazor', 'uri', 'v', 'vala', 'vbnet',
        'velocity', 'verilog', 'vhdl', 'vim', 'visual-basic', 'warpscript', 'wasm', 'web-idl',
        'wgsl', 'wiki', 'wolfram', 'wren',
        'xeora', 'xml-doc', 'xojo', 'xquery', 'yaml', 'yang', 'zig',
    );
}

/**
 * AJAX/admin-post 端点：仅管理员可触发，从后台"立即预下载"按钮调用
 */
function kratos_codehl_admin_post_warmup()
{
    if (!current_user_can('manage_options') || !check_admin_referer('kratos_codehl_warmup')) {
        wp_die('Unauthorized', 'Forbidden', array('response' => 403));
    }
    kratos_dispatch_bg_task('kratos_codehl_warmup_all');
    set_transient('kratos_codehl_warmup_notice', array('ok' => 0, 'fail' => 0, 'skip' => 0, 'pending' => true), 60);
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=kratos-options'));
    exit;
}
add_action('admin_post_kratos_codehl_warmup', 'kratos_codehl_admin_post_warmup');


/**
 * 切到"本地缓存"模式时自动触发一次预下载（在 update_option 钩子里）
 */
function kratos_codehl_on_options_save($old, $new)
{
    $old_src = is_array($old) && isset($old['g_codehl_source']) ? $old['g_codehl_source'] : 'cdn';
    $new_src = is_array($new) && isset($new['g_codehl_source']) ? $new['g_codehl_source'] : 'cdn';
    if ($new_src === 'local' && $old_src !== 'local') {
        kratos_dispatch_bg_task('kratos_codehl_warmup_all');
    }
}
add_action('update_option_kratos_options', 'kratos_codehl_on_options_save', 10, 2);

/**
 * 后台 admin notice：显示上次预下载成功/失败/跳过统计
 */
/**
 * 后台 CSF callback 渲染：显示本地缓存当前文件数 / 总大小 / 上次预下载时间，并提供"立即预下载"按钮
 */
function kratos_codehl_render_warmup_panel()
{
    $root = kratos_codehl_local_root();
    $count = 0; $bytes = 0;
    if (is_dir($root['dir'])) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root['dir'], \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) { $count++; $bytes += $f->getSize(); } }
    }
    $when = (int) get_option('kratos_codehl_warmup_at', 0);
    $stats = get_option('kratos_codehl_warmup_stats', array());
    $url = wp_nonce_url(admin_url('admin-post.php?action=kratos_codehl_warmup'), 'kratos_codehl_warmup')
        . '#tab=' . sanitize_title(__('全站配置', 'kratos')) . '/' . sanitize_title(__('代码高亮', 'kratos'));
    $human = size_format($bytes);
    ?>
    <div class="kratos-codehl-warmup">
        <p style="margin:0 0 8px"><?php
            printf(__('已缓存 <strong>%1$d</strong> 个文件 / <strong>%2$s</strong>', 'kratos'), $count, $human);
            if ($when) {
                echo '　<span style="color:#888">'
                    . sprintf(__('上次预下载：%s', 'kratos'), date_i18n('Y-m-d H:i', $when))
                    . '</span>';
            }
        ?></p>
        <p style="margin:0">
            <a href="<?php echo esc_url($url); ?>" class="button button-secondary"><?php _e('立即重新预下载', 'kratos'); ?></a>
            <span style="color:#888;margin-left:10px;font-size:12px"><?php _e('适用于 Apache / Nginx / Caddy / IIS 等任意服务器', 'kratos'); ?></span>
        </p>
    </div>
    <?php
}

function kratos_codehl_warmup_notice()
{
    $stats = get_transient('kratos_codehl_warmup_notice');
    if (!$stats || !is_array($stats)) {
        return;
    }
    if (!empty($stats['pending'])) {
        printf('<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html('代码高亮资源正在后台下载中，请稍后刷新页面查看结果。'));
        return;
    }
    delete_transient('kratos_codehl_warmup_notice');
    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        esc_html(sprintf('代码高亮资源预下载完成：成功 %d 个，跳过 %d 个，失败 %d 个。', $stats['ok'], $stats['skip'], $stats['fail']))
    );
}
add_action('admin_notices', 'kratos_codehl_warmup_notice');

function kratos_codehl_fetch_to_local($remote_url, $local_file)
{
    $dir = dirname($local_file);
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return false;
    }
    $resp = wp_remote_get($remote_url, array(
        'timeout' => 15,
        'redirection' => 5,
        'sslverify' => true,
    ));
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        return false;
    }
    $body = wp_remote_retrieve_body($resp);
    if ($body === '' || $body === false) {
        return false;
    }
    return (bool) file_put_contents($local_file, $body);
}

function kratos_codehl_assets()
{
    if (!kratos_codehl_enabled() || is_admin()) {
        return;
    }
    $engine = kratos_option('g_codehl_engine', 'prism');
    $line_num = (bool) kratos_option('g_codehl_linenum', false);

    if ($engine === 'prism') {
        $theme_key = kratos_option('g_codehl_theme_prism', 'core/prism-tomorrow');
        wp_enqueue_style('kratos-prism', kratos_codehl_asset_url(kratos_codehl_prism_theme_path($theme_key)), array(), KRATOS_PRISM_VER);
        wp_enqueue_script('kratos-prism-core', kratos_codehl_asset_url('prismjs@' . KRATOS_PRISM_VER . '/components/prism-core.min.js'), array(), KRATOS_PRISM_VER, true);
        wp_enqueue_script('kratos-prism-autoloader', kratos_codehl_asset_url('prismjs@' . KRATOS_PRISM_VER . '/plugins/autoloader/prism-autoloader.min.js'), array('kratos-prism-core'), KRATOS_PRISM_VER, true);
        wp_enqueue_style('kratos-prism-toolbar', kratos_codehl_asset_url('prismjs@' . KRATOS_PRISM_VER . '/plugins/toolbar/prism-toolbar.min.css'), array(), KRATOS_PRISM_VER);
        wp_enqueue_script('kratos-prism-toolbar', kratos_codehl_asset_url('prismjs@' . KRATOS_PRISM_VER . '/plugins/toolbar/prism-toolbar.min.js'), array('kratos-prism-core'), KRATOS_PRISM_VER, true);
        wp_enqueue_script('kratos-prism-copy', kratos_codehl_asset_url('prismjs@' . KRATOS_PRISM_VER . '/plugins/copy-to-clipboard/prism-copy-to-clipboard.min.js'), array('kratos-prism-toolbar'), KRATOS_PRISM_VER, true);
        // 主题已有 .k-main .details .toolbar 的卡片样式（margin/padding/background/box-shadow），
        // Prism toolbar 插件复用了 .toolbar class，需要把 wrapper 完全重置成定位层，仅给按钮上轻量样式。
        wp_add_inline_style('kratos-prism-toolbar', 'div.code-toolbar{position:relative}div.code-toolbar>.toolbar,.k-main .details div.code-toolbar>.toolbar{position:absolute!important;top:.4em!important;right:.4em!important;margin:0!important;padding:0!important;background:transparent!important;box-shadow:none!important;-webkit-box-shadow:none!important;-moz-box-shadow:none!important;border:0!important;width:auto!important;opacity:0!important;transition:opacity .15s ease!important;z-index:10;pointer-events:auto}div.code-toolbar:hover>.toolbar,div.code-toolbar:focus-within>.toolbar,.k-main .details div.code-toolbar:hover>.toolbar,.k-main .details div.code-toolbar:focus-within>.toolbar{opacity:1!important}div.code-toolbar>.toolbar>.toolbar-item{display:inline-block;margin-left:4px;padding:0;background:transparent;box-shadow:none}div.code-toolbar>.toolbar>.toolbar-item>button,div.code-toolbar>.toolbar>.toolbar-item>a,div.code-toolbar>.toolbar>.toolbar-item>span{font-size:12px!important;line-height:1!important;padding:4px 10px!important;margin:0!important;border-radius:3px!important;box-shadow:none!important;background:rgba(255,255,255,.18)!important;color:#fff!important;border:1px solid rgba(255,255,255,.3)!important;cursor:pointer;text-shadow:none!important;font-weight:normal!important}div.code-toolbar>.toolbar>.toolbar-item>button:hover,div.code-toolbar>.toolbar>.toolbar-item>a:hover{background:rgba(255,255,255,.32)!important;color:#fff!important;border-color:rgba(255,255,255,.5)!important}');
        // CDN 模式拼 CDN 路径；本地模式直接走 uploads 静态路径。
        // 用户切到本地模式时会自动预下载所有 Prism 语言组件，无需任何服务器层 rewrite，跨 Apache/Nginx/Caddy/IIS 通用。
        if (kratos_option('g_codehl_source', 'cdn') === 'local') {
            $root = kratos_codehl_local_root();
            $components_path = trailingslashit($root['url']) . 'prismjs@' . KRATOS_PRISM_VER . '/components/';
        } else {
            $components_path = kratos_codehl_cdn_base() . '/prismjs@' . KRATOS_PRISM_VER . '/components/';
        }
        // 仅设置 Prism autoloader 的语言组件路径（必需配置，不是兜底）。
        // 服务端 fenced/autodetect 过滤器已保证 <pre>/<code> 都带 language-xxx，前端不再做任何 class 兜底。
        wp_add_inline_script(
            'kratos-prism-autoloader',
            'if(window.Prism&&Prism.plugins&&Prism.plugins.autoloader){Prism.plugins.autoloader.languages_path="' . esc_js($components_path) . '";}',
            'after'
        );
        if ($line_num) {
            wp_enqueue_style('kratos-prism-linenum', kratos_codehl_asset_url('prismjs@' . KRATOS_PRISM_VER . '/plugins/line-numbers/prism-line-numbers.min.css'), array(), KRATOS_PRISM_VER);
            wp_enqueue_script('kratos-prism-linenum', kratos_codehl_asset_url('prismjs@' . KRATOS_PRISM_VER . '/plugins/line-numbers/prism-line-numbers.min.js'), array('kratos-prism-core'), KRATOS_PRISM_VER, true);
            wp_add_inline_script('kratos-prism-linenum', 'document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("pre > code").forEach(function(c){var p=c.parentNode;if(p&&!p.classList.contains("line-numbers")){p.classList.add("line-numbers");}});});');
        }
        return;
    }

    if ($engine === 'hljs') {
        $theme_key = kratos_option('g_codehl_theme_hljs', 'github-dark');
        wp_enqueue_style('kratos-hljs', kratos_codehl_asset_url(kratos_codehl_hljs_theme_path($theme_key)), array(), KRATOS_HLJS_VER);
        // 用 @highlightjs/cdn-assets 包的 UMD 浏览器版（npm 主包 highlight.js 的 lib/*.js 是 CommonJS，浏览器用不了）
        wp_enqueue_script('kratos-hljs', kratos_codehl_hljs_js_url(), array(), KRATOS_HLJS_VER, true);
        $inline = 'document.addEventListener("DOMContentLoaded",function(){if(!window.hljs)return;'
            . 'window.hljs.configure({ignoreUnescapedHTML:true});'
            . 'document.querySelectorAll("pre code").forEach(function(b){window.hljs.highlightElement(b);if(b.parentNode&&b.parentNode.tagName==="PRE"){b.parentNode.classList.add("hljs");}});';
        if ($line_num) {
            $inline .= 'document.querySelectorAll("pre code.hljs").forEach(function(b){var lines=b.innerHTML.split("\n");if(lines.length&&lines[lines.length-1]===""){lines.pop();}b.innerHTML=lines.map(function(l,i){return "<span class=\\"hljs-ln-line\\" data-line=\\""+(i+1)+"\\">"+l+"</span>";}).join("\n");});';
        }
        $inline .= '});';
        wp_add_inline_script('kratos-hljs', $inline);
        if ($line_num) {
            wp_add_inline_style('kratos-hljs', '.hljs-ln-line{display:inline-block;width:100%;}.hljs-ln-line::before{content:attr(data-line);display:inline-block;width:2.5em;padding-right:1em;margin-right:.5em;color:#888;text-align:right;border-right:1px solid #555;user-select:none;}');
        }
        return;
    }

    if ($engine === 'highlight_php') {
        $theme_key = kratos_option('g_codehl_theme_hljs', 'github-dark');
        // 服务端渲染只需配色样式，CDN/本地切换共享前端的设置
        wp_enqueue_style('kratos-hljs-style', kratos_codehl_asset_url(kratos_codehl_hljs_theme_path($theme_key)), array(), KRATOS_HLJS_VER);
    }
}
add_action('wp_enqueue_scripts', 'kratos_codehl_assets', 30);

/**
 * 给 Gutenberg 编辑器的 core/code 区块加一个"语言"下拉。
 * 选择后会把 language-xxx 写入 <code> 的 className，前台按 class 渲染，跨三引擎通用。
 */
function kratos_codehl_enqueue_block_editor()
{
    if (!kratos_codehl_enabled()) {
        return;
    }
    wp_enqueue_script(
        'kratos-codehl-block-editor',
        get_template_directory_uri() . '/assets/js/codehl-block-editor.js',
        array('wp-hooks', 'wp-element', 'wp-compose', 'wp-block-editor', 'wp-components', 'wp-blocks', 'wp-dom-ready', 'wp-data'),
        THEME_VERSION . '.' . filemtime(get_template_directory() . '/assets/js/codehl-block-editor.js'),
        true
    );
    // 用 Prism 主流语言子集做下拉 —— 完整 270+ 列出来太长；高级用户仍可在"附加 CSS class"里手填
    $langs = array(
        array('value' => '', 'label' => __('— 自动识别 —', 'kratos')),
        array('value' => 'plaintext', 'label' => __('纯文本', 'kratos')),
        array('value' => 'html', 'label' => 'HTML'),
        array('value' => 'xml', 'label' => 'XML'),
        array('value' => 'css', 'label' => 'CSS'),
        array('value' => 'scss', 'label' => 'SCSS / Sass'),
        array('value' => 'less', 'label' => 'Less'),
        array('value' => 'javascript', 'label' => 'JavaScript'),
        array('value' => 'typescript', 'label' => 'TypeScript'),
        array('value' => 'jsx', 'label' => 'JSX'),
        array('value' => 'tsx', 'label' => 'TSX'),
        array('value' => 'json', 'label' => 'JSON'),
        array('value' => 'yaml', 'label' => 'YAML'),
        array('value' => 'toml', 'label' => 'TOML'),
        array('value' => 'markdown', 'label' => 'Markdown'),
        array('value' => 'php', 'label' => 'PHP'),
        array('value' => 'python', 'label' => 'Python'),
        array('value' => 'ruby', 'label' => 'Ruby'),
        array('value' => 'java', 'label' => 'Java'),
        array('value' => 'kotlin', 'label' => 'Kotlin'),
        array('value' => 'scala', 'label' => 'Scala'),
        array('value' => 'go', 'label' => 'Go'),
        array('value' => 'rust', 'label' => 'Rust'),
        array('value' => 'c', 'label' => 'C'),
        array('value' => 'cpp', 'label' => 'C++'),
        array('value' => 'csharp', 'label' => 'C#'),
        array('value' => 'objectivec', 'label' => 'Objective-C'),
        array('value' => 'swift', 'label' => 'Swift'),
        array('value' => 'dart', 'label' => 'Dart'),
        array('value' => 'lua', 'label' => 'Lua'),
        array('value' => 'perl', 'label' => 'Perl'),
        array('value' => 'r', 'label' => 'R'),
        array('value' => 'haskell', 'label' => 'Haskell'),
        array('value' => 'erlang', 'label' => 'Erlang'),
        array('value' => 'elixir', 'label' => 'Elixir'),
        array('value' => 'sql', 'label' => 'SQL'),
        array('value' => 'graphql', 'label' => 'GraphQL'),
        array('value' => 'bash', 'label' => 'Bash / Shell'),
        array('value' => 'powershell', 'label' => 'PowerShell'),
        array('value' => 'batch', 'label' => 'Batch'),
        array('value' => 'docker', 'label' => 'Dockerfile'),
        array('value' => 'nginx', 'label' => 'Nginx'),
        array('value' => 'apacheconf', 'label' => 'Apache'),
        array('value' => 'ini', 'label' => 'INI'),
        array('value' => 'diff', 'label' => 'Diff'),
        array('value' => 'git', 'label' => 'Git'),
        array('value' => 'http', 'label' => 'HTTP'),
        array('value' => 'regex', 'label' => 'RegExp'),
        array('value' => 'vim', 'label' => 'Vim Script'),
        array('value' => 'makefile', 'label' => 'Makefile'),
        array('value' => 'protobuf', 'label' => 'Protobuf'),
        array('value' => 'solidity', 'label' => 'Solidity'),
    );
    wp_localize_script('kratos-codehl-block-editor', 'kratosCodehlLangs', $langs);
}
add_action('enqueue_block_editor_assets', 'kratos_codehl_enqueue_block_editor');

/**
 * 懒加载 highlight.php 库并返回配置好的 Highlighter 实例（失败时返回 null）
 */
function kratos_codehl_get_highlighter()
{
    static $hl = null;
    static $tried = false;
    if ($hl !== null || $tried) {
        return $hl;
    }
    $tried = true;
    $autoloader = get_template_directory() . '/inc/highlight-php/Highlight/Autoloader.php';
    if (!file_exists($autoloader)) {
        return null;
    }
    require_once $autoloader;
    if (!class_exists('\\Highlight\\Autoloader', false) === false) {
        // already registered
    }
    static $registered = false;
    if (!$registered) {
        spl_autoload_register(array('\\Highlight\\Autoloader', 'load'));
        $registered = true;
    }
    if (!class_exists('\\Highlight\\Highlighter')) {
        return null;
    }
    $hl = new \Highlight\Highlighter();
    $hl->setAutodetectLanguages(array(
        'bash', 'c', 'cpp', 'csharp', 'css', 'diff', 'go', 'html', 'http', 'ini',
        'java', 'javascript', 'json', 'kotlin', 'less', 'lua', 'makefile', 'markdown',
        'nginx', 'objectivec', 'perl', 'php', 'plaintext', 'powershell', 'python',
        'ruby', 'rust', 'scss', 'shell', 'sql', 'swift', 'typescript', 'xml', 'yaml',
    ));
    return $hl;
}

/**
 * Markdown 围栏块语言识别。
 *
 * 作者在 Gutenberg / 经典编辑器代码块里习惯写 Markdown 围栏：
 *   ```html              （首行：3 个反引号 + 语言名）
 *   <div>...</div>
 *   ```                  （末行：3 个反引号）
 *
 * 这个过滤器（priority 10，跑在 autodetect_filter 11 / server_render 12 之前）：
 *  - 检测 <pre><code> 内容是否包裹在一对围栏中；
 *  - 提取首行语言名 → 写入 <code class="language-xxx">；
 *  - 从代码内容中剥掉首尾围栏行；
 *  - 后续 autodetect / server_render 看到已有 language- class，自动跳过 highlightAuto。
 *
 * 优先级链：手动 class（最高）→ 围栏标记 → 自动识别 → plaintext。
 * 三种引擎（Prism / hljs / highlight.php）通用。
 */
function kratos_codehl_fenced_lang_filter($content)
{
    if (!kratos_codehl_enabled() || is_admin()) {
        return $content;
    }
    if (strpos($content, '<code') === false) {
        return $content;
    }
    return preg_replace_callback(
        '#<pre([^>]*)>\s*<code([^>]*)>(.*?)</code>\s*</pre>#is',
        function ($m) {
            $pre_attr = $m[1];
            $code_attr = $m[2];
            $code_html = $m[3];
            // 已经手动标了 language- / lang-，作者意图明确，跳过
            if (preg_match('/class="[^"]*\b(?:language|lang)-[\w+#-]+/i', $code_attr)) {
                return $m[0];
            }
            // 解码代码内容看首尾行
            $code_text = html_entity_decode($code_html, ENT_QUOTES | ENT_HTML5);
            $code_text = str_replace("\r\n", "\n", $code_text);
            // ``` 或 ~~~ 围栏，首行紧跟语言（可空）；前后允许任意空白
            // 语言名允许字母/数字/_+#- (覆盖 c++/c#/objective-c 这种)
            if (!preg_match('/^\s*(?:```|~~~)[ \t]*([\w+#.-]*)[ \t]*\R(.*?)\R[ \t]*(?:```|~~~)\s*$/s', $code_text, $fm)) {
                return $m[0];
            }
            $lang = strtolower(trim($fm[1]));
            $body = $fm[2];
            if ($lang === '') {
                $lang = 'plaintext';
            }
            // 重新编码 body 为安全 HTML（避免再注入到 <code> 内被解析）
            $new_code_html = esc_html($body);
            // 加 class 到 <code>
            if (preg_match('/class="([^"]*)"/i', $code_attr)) {
                $code_attr = preg_replace('/class="([^"]*)"/i', 'class="$1 language-' . $lang . '"', $code_attr);
            } else {
                $code_attr .= ' class="language-' . $lang . '"';
            }
            // 同步到 <pre>（避开主题 .pre 默认样式 / 让 Prism pre[class*="language-"] 命中）
            if (preg_match('/class="([^"]*)"/i', $pre_attr)) {
                if (!preg_match('/class="[^"]*\blanguage-' . preg_quote($lang, '/') . '\b/i', $pre_attr)) {
                    $pre_attr = preg_replace('/class="([^"]*)"/i', 'class="$1 language-' . $lang . '"', $pre_attr);
                }
            } else {
                $pre_attr .= ' class="language-' . $lang . '"';
            }
            return '<pre' . $pre_attr . '><code' . $code_attr . '>' . $new_code_html . '</code></pre>';
        },
        $content
    );
}
add_filter('the_content', 'kratos_codehl_fenced_lang_filter', 10);

/**
 * <pre>/<code> 之间双向同步 language-xxx class（仅传播作者意图，不做语言猜测）。
 *  - Gutenberg 把 className 写在 <pre> 上、<code> 没有 → 同步到 <code>，让前端引擎按 class 渲染；
 *  - 反之 <code> 有、<pre> 没 → 同步到 <pre>，让 Prism `pre[class*="language-"]` 主题选择器命中
 *    并避开主题 style.css 里 `pre:not([class*="language-"])` 的默认样式覆盖。
 *  - 没有任何 language- 标记的 <pre><code> 不动；让前端引擎自行决定（hljs 自动检测 / Prism 不染色）。
 */
function kratos_codehl_normalize_lang_class($content)
{
    if (!kratos_codehl_enabled() || is_admin()) {
        return $content;
    }
    if (strpos($content, '<code') === false) {
        return $content;
    }
    return preg_replace_callback(
        '#(<pre([^>]*)>\s*<code)([^>]*)>(.*?)(</code>\s*</pre>)#is',
        function ($m) {
            $pre_attr = $m[2];
            $code_attr = $m[3];
            $code_html = $m[4];
            $code_has = preg_match('/class="[^"]*\b(?:language|lang)-([\w+#-]+)/i', $code_attr, $clm);
            $pre_has = preg_match('/class="[^"]*\b(?:language|lang)-([\w+#-]+)/i', $pre_attr, $plm);
            if (!$code_has && !$pre_has) {
                return $m[0]; // 都没有，不干预
            }
            $lang = strtolower($code_has ? $clm[1] : $plm[1]);
            if (!$code_has) {
                if (preg_match('/class="([^"]*)"/i', $code_attr)) {
                    $code_attr = preg_replace('/class="([^"]*)"/i', 'class="$1 language-' . $lang . '"', $code_attr);
                } else {
                    $code_attr .= ' class="language-' . $lang . '"';
                }
            }
            if (!$pre_has) {
                if (preg_match('/class="([^"]*)"/i', $pre_attr)) {
                    $pre_attr = preg_replace('/class="([^"]*)"/i', 'class="$1 language-' . $lang . '"', $pre_attr);
                } else {
                    $pre_attr .= ' class="language-' . $lang . '"';
                }
            }
            return '<pre' . $pre_attr . '><code' . $code_attr . '>' . $code_html . $m[5];
        },
        $content
    );
}
add_filter('the_content', 'kratos_codehl_normalize_lang_class', 11);

function kratos_codehl_server_render($content)
{
    if (!kratos_codehl_enabled() || is_admin()) {
        return $content;
    }
    if (kratos_option('g_codehl_engine', 'prism') !== 'highlight_php') {
        return $content;
    }
    if (strpos($content, '<code') === false) {
        return $content;
    }
    $hl = kratos_codehl_get_highlighter();
    if (!$hl) {
        return $content;
    }

    return preg_replace_callback(
        '#<pre([^>]*)><code([^>]*)>(.*?)</code></pre>#is',
        function ($m) use ($hl) {
            $pre_attr = $m[1];
            $code_attr = $m[2];
            $code = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);
            $lang = '';
            if (preg_match('/class="[^"]*language-([\w-]+)/i', $code_attr, $lm)
                || preg_match('/class="[^"]*lang-([\w-]+)/i', $code_attr, $lm)
                || preg_match('/class="[^"]*language-([\w-]+)/i', $pre_attr, $lm)
                || preg_match('/class="[^"]*lang-([\w-]+)/i', $pre_attr, $lm)) {
                $lang = strtolower($lm[1]);
            }
            // 不再做 highlightAuto 自动猜测：没有 language- 标记的代码块原样输出，
            // 由作者通过 Gutenberg 语言下拉 / Markdown 围栏 / 手填 class 显式标注。
            if (!$lang) {
                return $m[0];
            }
            try {
                $r = $hl->highlight($lang, $code);
                $highlighted = $r->value;
                $cls = 'hljs language-' . $r->language;
            } catch (\Exception $e) {
                return $m[0];
            }
            $pre_attr_with_cls = preg_match('/class="([^"]*)"/i', $pre_attr)
                ? preg_replace('/class="([^"]*)"/i', 'class="$1 hljs"', $pre_attr)
                : $pre_attr . ' class="hljs"';
            return '<pre' . $pre_attr_with_cls . '><code class="' . esc_attr($cls) . '">' . $highlighted . '</code></pre>';
        },
        $content
    );
}
add_filter('the_content', 'kratos_codehl_server_render', 12);

/**
 * 给"代码高亮"section 的预览/缓存面板加点视觉细节（圆角、最大高度）。
 * 用 admin_head 而不是 admin_print_styles 是因为 admin_head 一定能在 CSF 之前输出。
 */
function kratos_codehl_admin_styles()
{
    echo '<style id="kratos-codehl-admin">'
        . '.kratos-codehl-preview pre{border-radius:6px;overflow:auto;max-height:340px}'
        . '.kratos-codehl-preview pre code{display:block;padding:14px 16px;font-size:13px;line-height:1.6}'
        . '</style>';
}
add_action('admin_head', 'kratos_codehl_admin_styles');

/**
 * 后台主题选项页预览渲染（CSF callback 字段）
 * 通过监听 g_codehl_engine / g_codehl_theme_prism / g_codehl_theme_hljs 三个 select 的变化，
 * 动态切换 <link id="kratos-codehl-preview-css"> 的 href，使预览代码立刻应用新主题。
 */
function kratos_codehl_render_preview()
{
    $cdn = KRATOS_CODEHL_DEFAULT_CDN; // 后台预览不走本地缓存，固定 CDN
    $prism_map = array();
    foreach (kratos_codehl_prism_themes() as $key => $info) {
        $prism_map[$key] = $cdn . '/' . $info[1];
    }
    $hljs_map = array();
    foreach (kratos_codehl_hljs_themes() as $key => $name) {
        $hljs_map[$key] = $cdn . '/highlight.js@' . KRATOS_HLJS_VER . '/styles/' . $key . '.min.css';
    }
    $sample = 'function greet(name) {' . "\n"
        . '  // 主题预览' . "\n"
        . '  const msg = `Hello, ' . '${' . 'name}!`;' . "\n"
        . '  if (name) {' . "\n"
        . '    console.log(msg);' . "\n"
        . '    return true;' . "\n"
        . '  }' . "\n"
        . '  return false;' . "\n"
        . '}' . "\n\n"
        . "greet('Kratos');";
    ?>
    <div class="kratos-codehl-preview" style="margin-top:8px">
        <link rel="stylesheet" id="kratos-codehl-preview-css" href="">
        <pre id="kratos-codehl-preview-pre" style="margin:0"><code id="kratos-codehl-preview-code" class="language-javascript"><?php echo esc_html($sample); ?></code></pre>
        <p class="csf--help" style="margin-top:6px;color:#888;font-size:12px">
            <?php _e('实时预览：切换上方 “高亮方案 / 主题” 选项即可查看效果（与文章前台一致）', 'kratos'); ?>
        </p>
    </div>
    <script>
    (function(){
        if (window.kratosCodehlPreviewBound) { return; }
        window.kratosCodehlPreviewBound = true;
        var prismMap = <?php echo wp_json_encode($prism_map); ?>;
        var hljsMap = <?php echo wp_json_encode($hljs_map); ?>;
        var hljsLoaded = false;
        function findField(id){
            return document.querySelector('[data-depend-id="' + id + '"]') || document.querySelector('[name$="['+id+']"]');
        }
        function val(id){
            var el = findField(id);
            return el ? el.value : '';
        }
        function loadHljsOnce(cb){
            if (hljsLoaded) { cb && cb(); return; }
            var s = document.createElement('script');
            s.src = '<?php echo esc_js($cdn . '/' . kratos_codehl_hljs_js_rel()); ?>';
            s.onload = function(){ hljsLoaded = true; cb && cb(); };
            document.head.appendChild(s);
        }
        var originalSample = null;
        function resetCodeNode(){
            // hljs / Prism 的 highlightElement 会写 dataset.highlighted 并替换 innerHTML，
            // 在同一个节点上重复调用会被跳过。所以每次刷新都"克隆替换"一份纯净 <code> 节点。
            var pre = document.getElementById('kratos-codehl-preview-pre');
            var oldCode = document.getElementById('kratos-codehl-preview-code');
            if (!pre || !oldCode) return null;
            if (originalSample === null) { originalSample = oldCode.textContent; }
            var fresh = document.createElement('code');
            fresh.id = 'kratos-codehl-preview-code';
            fresh.textContent = originalSample;
            pre.replaceChild(fresh, oldCode);
            return fresh;
        }
        function refresh(){
            var engine = val('g_codehl_engine') || 'prism';
            var link = document.getElementById('kratos-codehl-preview-css');
            var code = resetCodeNode();
            if (!link || !code) return;
            if (engine === 'prism') {
                var k = val('g_codehl_theme_prism') || 'core/prism-tomorrow';
                link.href = prismMap[k] || '';
                code.className = 'language-javascript';
                if (window.Prism) {
                    Prism.highlightElement(code);
                } else {
                    var s = document.createElement('script');
                    s.src = '<?php echo esc_js($cdn); ?>/prismjs@<?php echo esc_js(KRATOS_PRISM_VER); ?>/components/prism-core.min.js';
                    s.onload = function(){
                        var s2 = document.createElement('script');
                        s2.src = '<?php echo esc_js($cdn); ?>/prismjs@<?php echo esc_js(KRATOS_PRISM_VER); ?>/components/prism-clike.min.js';
                        s2.onload = function(){
                            var s3 = document.createElement('script');
                            s3.src = '<?php echo esc_js($cdn); ?>/prismjs@<?php echo esc_js(KRATOS_PRISM_VER); ?>/components/prism-javascript.min.js';
                            s3.onload = function(){ if (window.Prism) Prism.highlightElement(code); };
                            document.head.appendChild(s3);
                        };
                        document.head.appendChild(s2);
                    };
                    document.head.appendChild(s);
                }
            } else {
                var k2 = val('g_codehl_theme_hljs') || 'github-dark';
                link.href = hljsMap[k2] || '';
                // hljs 11.x 只看 language-xxx，不要预先加 .hljs（否则会抛 "previously highlighted" 跳过）
                code.className = 'language-javascript';
                loadHljsOnce(function(){
                    if (window.hljs) {
                        window.hljs.configure({ ignoreUnescapedHTML: true });
                        window.hljs.highlightElement(code);
                    }
                });
            }
        }
        function bind(){
            ['g_codehl_engine','g_codehl_theme_prism','g_codehl_theme_hljs'].forEach(function(id){
                var el = findField(id);
                if (el && !el.kratosBound) { el.addEventListener('change', refresh); el.kratosBound = true; }
            });
        }
        // CSF 用 jQuery 渲染选项页，DOM 可能晚于此脚本初始化；多次绑定保平安
        if (document.readyState !== 'loading') { bind(); refresh(); }
        else document.addEventListener('DOMContentLoaded', function(){ bind(); refresh(); });
        setTimeout(function(){ bind(); refresh(); }, 800);
    })();
    </script>
    <?php
}
