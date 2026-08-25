<?php

/**
 * 每日皮肤（周一 ~ 周日）
 *
 * 提供三种模式：
 *   - auto    按访客本地时区，每天自动切到当天皮肤
 *   - locked  全站固定为某一套皮肤
 *   - off     不启用，回到 style.css 默认外观
 *
 * 实现策略：
 *   - 7 套皮肤的 CSS 变量集中在 assets/css/weekday-skins.css 里，按
 *     html[data-weekday-skin="mon|tue|..."] 选择器作用。
 *   - 启用后，PHP 在 wp_head 早期注入 inline script，由浏览器读取
 *     new Date().getDay() 把对应 attr 写到 <html> 上。这样在 CDN /
 *     页面缓存场景下也能按访客本地时区切换，不会被 PHP 渲染时间锁死。
 *   - locked 模式直接在 PHP 渲染阶段把 attr 写到 <html>（通过
 *     language_attributes filter），不依赖 JS。
 *
 * 与暗夜模式（inc/theme-darkmode.php）的关系：
 *   两者作用在同一个 <html> 的不同 attr 上（data-theme vs data-weekday-skin），
 *   weekday-skins.css 的所有覆盖规则用 :not([data-theme="dark"]) 兜底，
 *   暗夜模式优先。
 *
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/** JS 端 getDay() 返回 0=周日 ~ 6=周六，这里映射到 CSS attr 用的 slug。 */
function kratos_weekday_slugs()
{
    return array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
}

/** 后台「锁定皮肤」下拉的可选值，包含中英文 label。
 *  注意：parchment 是仅供「锁定单一皮肤」使用的额外皮肤，不参与 auto 模式
 *  按 slug 索引（kratos_weekday_slugs 仍是 sun~sat 7 天）。 */
function kratos_weekday_options()
{
    return array(
        'mon' => __('周一 · 清玻', 'kratos'),
        'tue' => __('周二 · 拼贴', 'kratos'),
        'wed' => __('周三 · 凝脂', 'kratos'),
        'thu' => __('周四 · 素白', 'kratos'),
        'fri' => __('周五 · 琥珀', 'kratos'),
        'sat' => __('周六 · 海滨', 'kratos'),
        'sun' => __('周日 · 金辉', 'kratos'),
        'parchment' => __('羊皮', 'kratos'),
        'silk'      => __('黄绢', 'kratos'),
        'vermilion' => __('朱砂', 'kratos'),
        'morandi'   => __('莫兰迪 · 柔和', 'kratos'),
        'mist'      => __('莫兰迪 · 雾霭', 'kratos'),
        'linen'     => __('莫兰迪 · 亚麻', 'kratos'),
        'porcelain' => __('莫兰迪 · 青瓷', 'kratos'),
        'lavender'  => __('莫兰迪 · 薰衣草', 'kratos'),
        'retro'     => __('复古 · 牛皮纸', 'kratos'),
        'web1998'   => __('复古 · 千禧网页', 'kratos'),
        'ebook'     => __('电子书 · 纸墨', 'kratos'),
        'bookfold'  => __('书卷 · 半开卷', 'kratos'),
        'bourse'    => __('金融 · 盘口', 'kratos'),
    );
}

/** 前端皮肤切换器（开发版）是否开启。独立于皮肤模式，off 时也可用。
 *  注意：这个开关只管**页脚那个弹出面板按钮**，不代表「访客能否覆盖皮肤」。
 *  后者见 kratos_weekday_override_enabled()。 */
function kratos_weekday_switcher_enabled()
{
    return (bool) kratos_option('g_weekday_skin_switcher', false);
}

/**
 * 「访客本地皮肤覆盖」能力是否开启。
 *
 * 皮肤覆盖的持久化并不依赖 skin-switcher.js —— 真正生效的是 wp_head 里的
 * inline 脚本（读 localStorage → 写 data-weekday-skin → 注入皮肤 CSS，
 * 见 kratos_weekday_head_inline / kratos_weekday_switcher_head_css）。
 * skin-switcher.js 只是页脚那个弹出面板的 UI。
 *
 * 因此凡是「能让访客选皮肤」的入口，都要让这段 inline 脚本生效。目前有两个：
 *   1. 页脚皮肤切换器按钮（g_weekday_skin_switcher）
 *   2. 命令面板的皮肤分组（g_cmdk + g_cmdk_show_skins）
 * 两者相互独立，任一开启即需要覆盖能力。
 *
 * 这里直接读选项而不调用命令面板模块的函数，避免两个模块间产生加载顺序依赖。
 */
function kratos_weekday_override_enabled()
{
    if (kratos_weekday_switcher_enabled()) {
        return true;
    }
    return (bool) kratos_option('g_cmdk', true) && (bool) kratos_option('g_cmdk_show_skins', false);
}

/**
 * 「额外皮肤」slug → 独立 CSS 文件名映射。
 * 工作日 mon~sun 不在此列（它们共用 weekday-skins.css，见 kratos_weekday_css_map）。
 */
function kratos_weekday_variant_files()
{
    return array(
        'mist'      => 'morandi-mist.css',
        'linen'     => 'morandi-linen.css',
        'porcelain' => 'morandi-porcelain.css',
        'lavender'  => 'morandi-lavender.css',
        'parchment' => 'parchment.css',
        'silk'      => 'silk.css',
        'vermilion' => 'vermilion.css',
        'morandi'   => 'morandi.css',
        'retro'     => 'retro.css',
        'web1998'   => 'web1998.css',
        'ebook'     => 'ebook.css',
        'bookfold'  => 'bookfold.css',
        'bourse'    => 'bourse.css',
    );
}

/**
 * 全部皮肤 slug → CSS 文件 URL 的映射，供前端切换器按需注入 <link>。
 * mon~sun 共用 weekday-skins.css（内部按 attr 锁定），额外皮肤各自独立文件。
 */
function kratos_weekday_css_map()
{
    $map = array();
    foreach (kratos_weekday_slugs() as $slug) {
        $map[$slug] = ASSET_PATH . '/assets/css/weekday-skins.css';
    }
    foreach (kratos_weekday_variant_files() as $slug => $file) {
        $map[$slug] = ASSET_PATH . '/assets/css/skins/' . $file;
    }
    return $map;
}

/** 切换器 localStorage 键名 + 「默认外观」哨兵值。 */
function kratos_weekday_switcher_storage_key()
{
    return 'kratos_skin_override';
}
function kratos_weekday_switcher_default_sentinel()
{
    return '__default__';
}

/** 把任意输入归一化成已知 slug，未命中回退到周一。 */
function kratos_weekday_normalize_slug($value)
{
    $value = is_string($value) ? strtolower(trim($value)) : '';
    $valid = kratos_weekday_options();
    return isset($valid[$value]) ? $value : 'mon';
}

/** 读取并整理所有相关选项。 */
function kratos_weekday_settings()
{
    $mode = kratos_option('g_weekday_skin_mode', 'off');
    $allowed = array('off', 'auto', 'locked');
    if (!in_array($mode, $allowed, true)) {
        $mode = 'off';
    }

    return array(
        'mode'    => $mode,
        'locked'  => kratos_weekday_normalize_slug(kratos_option('g_weekday_skin_locked', 'mon')),
        'slugs'   => kratos_weekday_slugs(),
        'labels'  => kratos_weekday_options(),
    );
}

/**
 * 早期内联脚本：在样式表渲染前把 data-weekday-skin 写到 <html> 上，避免 FOUC。
 *
 * 本主题的 header.php 直接写死 <html lang="..."> 而非调用 language_attributes()，
 * 所以无法用 language_attributes filter 注入属性；统一改走 wp_head priority 1
 * 的 inline script 由 JS 设置 documentElement 属性。
 *
 *   - auto 模式：JS 读 new Date().getDay() 决定 slug（访客本地时区）
 *   - locked 模式：PHP 把 locked slug 序列化进 cfg，JS 原样写入
 */
function kratos_weekday_head_inline()
{
    $s = kratos_weekday_settings();
    // 用「覆盖能力」而不是「页脚按钮」判定：命令面板的皮肤分组也需要这段脚本
    // 在下次加载时还原访客选择，它与页脚按钮相互独立。
    $switcher = kratos_weekday_override_enabled();
    // 模式为 off 且访客也不能覆盖：无需注入任何早期脚本。
    if ($s['mode'] === 'off' && !$switcher) {
        return;
    }
    $cfg = wp_json_encode(array(
        'mode'     => $s['mode'],
        'slugs'    => $s['slugs'],
        'locked'   => $s['locked'],
        'switcher' => $switcher,
        'storage'  => kratos_weekday_switcher_storage_key(),
        'sentinel' => kratos_weekday_switcher_default_sentinel(),
        'cssMap'   => $switcher ? kratos_weekday_css_map() : new stdClass(),
    ));
    // 早期解析当前皮肤：localStorage 覆盖（切换器开启时）优先于站点 auto/locked。
    // 这里只把 data-weekday-skin 写到 <html>（避免颜色 FOUC），并把解析结果暂存到
    // window.__kratosSkin，供后续注入 CSS 用。CSS <link> 的注入延后到 priority 99
    // （见 kratos_weekday_switcher_head_css），此时 components.css 已在 DOM 中，
    // 动态 link 追加到其后才能保证「皮肤晚于 components」的级联顺序。
    echo "<script>(function(){try{var c=" . $cfg . ";var h=document.documentElement;var ov=null;"
        . "if(c.switcher){try{ov=window.localStorage.getItem(c.storage);}catch(e){}}"
        . "var slug=null;"
        . "if(ov===c.sentinel){slug=null;}"
        . "else if(ov&&c.cssMap[ov]){slug=ov;}"
        . "else if(c.mode==='locked'){slug=c.locked;}"
        . "else if(c.mode==='auto'){slug=c.slugs[new Date().getDay()];}"
        . "if(slug){h.setAttribute('data-weekday-skin',slug);}"
        . "window.__kratosSkin={slug:slug,url:(slug&&c.cssMap[slug])?c.cssMap[slug]:null};"
        . "}catch(e){}})();</script>\n";
}

/**
 * 在 wp_head 末尾（components.css 已打印后）按需注入被 localStorage 覆盖的皮肤 CSS。
 * 只在访客可覆盖皮肤时输出（页脚切换器或命令面板皮肤分组任一开启）；
 * 无覆盖或该 CSS 已由服务端入队时跳过。仍在 <head> 内、body 渲染前完成，
 * 故不会出现可见的样式闪烁。
 */
function kratos_weekday_switcher_head_css()
{
    if (!kratos_weekday_override_enabled()) {
        return;
    }
    echo "<script>(function(){try{var s=window.__kratosSkin;if(!s||!s.url)return;"
        . "var ls=document.getElementsByTagName('link');for(var i=0;i<ls.length;i++){if(ls[i].rel==='stylesheet'&&ls[i].href&&ls[i].href.indexOf(s.url)!==-1)return;}"
        . "var l=document.createElement('link');l.rel='stylesheet';l.href=s.url;l.setAttribute('data-kratos-skin-dyn','');document.head.appendChild(l);"
        . "}catch(e){}})();</script>\n";
}
add_action('wp_head', 'kratos_weekday_switcher_head_css', 99);
add_action('wp_head', 'kratos_weekday_head_inline', 1);

/**
 * 入队 CSS。所有模式（auto/locked）都需要加载，仅 off 时跳过。
 */
function kratos_weekday_enqueue()
{
    if (is_admin()) {
        return;
    }
    $s = kratos_weekday_settings();
    // 模式为 off 但切换器开启：服务端不预入队任何皮肤（默认外观），皮肤 CSS 由
    // 切换器按需动态注入；无覆盖时保持默认外观。
    if ($s['mode'] === 'off') {
        return;
    }
    /**
     * 额外皮肤（羊皮 / 黄绢 / 朱砂 / 莫兰迪家族）拆到独立 CSS 文件，且每个文件
     * 已内嵌通用规则副本（选择器锁定该 slug），加载它就完整自足；无需再叠加
     * weekday-skins.css。工作日 mon~sun（auto 模式或 locked 命中 mon~sun）仍
     * 使用统一的 weekday-skins.css。
     */
    $variant_files = kratos_weekday_variant_files();
    if ($s['mode'] === 'locked' && isset($variant_files[$s['locked']])) {
        // extra-skin：只加载该独立文件
        wp_enqueue_style(
            'kratos-weekday-skin',
            ASSET_PATH . '/assets/css/skins/' . $variant_files[$s['locked']],
            array('kratos', 'kratos-components'),
            THEME_VERSION
        );
    } else {
        // 工作日皮肤（auto 或 locked 命中 mon~sun）
        wp_enqueue_style(
            'kratos-weekday-skin',
            ASSET_PATH . '/assets/css/weekday-skins.css',
            array('kratos', 'kratos-components'),
            THEME_VERSION
        );
    }
}
add_action('wp_enqueue_scripts', 'kratos_weekday_enqueue', 25);

/**
 * 朱砂皮肤专属：鼠标点击烟花特效。
 * 仅当当前生效皮肤为 vermilion 时挂载脚本；粒子色板取朱红/金/浅金，与皮肤 accent/decor 呼应。
 */
function kratos_weekday_vermilion_fireworks()
{
    if (is_admin()) return;
    $s = kratos_weekday_settings();
    // auto 模式下 vermilion 不在每日轮播里（只出现在 locked），所以站点侧只有 locked=vermilion 才需要它；
    // 但访客可本地覆盖皮肤时（页脚切换器 / 命令面板），任何页面都可能变成 vermilion，
    // 故这种情况下也注入，由脚本内的 attr 判定决定是否真的挂载。
    $site_vermilion = ($s['mode'] === 'locked' && $s['locked'] === 'vermilion');
    if (!$site_vermilion && !kratos_weekday_override_enabled()) return;

    $js = <<<'JS'
(function(){
    // 只在当前实际生效的皮肤是朱砂时挂载：皮肤可能被访客本地覆盖成别的皮肤或
    // 「默认外观」，此时 <html> 上不再是 vermilion，不应再有烟花。
    if (document.documentElement.getAttribute('data-weekday-skin') !== 'vermilion') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var colors = ['#C0392B','#E74C3C','#F1C40F','#E8B62D','#FFEDB5'];
    var canvas, ctx, dpr = Math.min(window.devicePixelRatio || 1, 2);
    var particles = [], raf = null;

    function ensureCanvas(){
        if (canvas) return;
        canvas = document.createElement('canvas');
        canvas.style.cssText = 'position:fixed;left:0;top:0;width:100%;height:100%;pointer-events:none;z-index:99999';
        (document.body || document.documentElement).appendChild(canvas);
        ctx = canvas.getContext('2d', {alpha:true});
        resize();
        window.addEventListener('resize', resize, {passive:true});
    }
    function resize(){
        if (!canvas) return;
        canvas.width = Math.floor(innerWidth * dpr);
        canvas.height = Math.floor(innerHeight * dpr);
        canvas.style.width = innerWidth + 'px';
        canvas.style.height = innerHeight + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function spawn(x, y){
        var count = 14 + (Math.random()*6|0);
        for (var i=0;i<count;i++){
            var angle = Math.random() * Math.PI * 2;
            var speed = 1 + Math.random() * 2.2;
            particles.push({
                x:x, y:y,
                vx:Math.cos(angle)*speed,
                vy:Math.sin(angle)*speed,
                life:1,
                size:0.8 + Math.random()*1.2,
                color: colors[(Math.random()*colors.length)|0]
            });
        }
    }
    function tick(){
        raf = null;
        // 用半透明擦除代替 clearRect：老帧的粒子被一层薄薄的透明层反复叠加，
        // 逐渐变淡形成拖尾/消散烟雾感。destination-out 只擦透明度不涂色。
        ctx.globalCompositeOperation = 'destination-out';
        ctx.fillStyle = 'rgba(0,0,0,0.18)';
        ctx.fillRect(0, 0, innerWidth, innerHeight);
        ctx.globalCompositeOperation = 'source-over';
        for (var i=particles.length-1;i>=0;i--){
            var p = particles[i];
            p.vy += 0.035;
            p.vx *= 0.965;
            p.vy *= 0.965;
            p.x += p.vx;
            p.y += p.vy;
            p.life -= 0.022;
            if (p.life <= 0){ particles.splice(i,1); continue; }
            // 消散阶段：后半段生命里粒子逐渐缩小 + 提高发光模糊
            var fade = p.life < 0.5 ? p.life * 2 : 1;
            ctx.globalAlpha = p.life;
            ctx.fillStyle = p.color;
            ctx.shadowColor = p.color;
            ctx.shadowBlur = 6 * fade;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size * (0.4 + 0.6 * fade), 0, Math.PI*2);
            ctx.fill();
        }
        ctx.shadowBlur = 0;
        ctx.globalAlpha = 1;
        if (particles.length) raf = requestAnimationFrame(tick);
        else {
            // 无粒子后再淡出几帧，把残留拖尾清干净
            fadeOut();
        }
    }
    function fadeOut(){
        var frames = 0;
        function step(){
            ctx.globalCompositeOperation = 'destination-out';
            ctx.fillStyle = 'rgba(0,0,0,0.3)';
            ctx.fillRect(0, 0, innerWidth, innerHeight);
            ctx.globalCompositeOperation = 'source-over';
            if (++frames < 12 && !particles.length) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }
    window.addEventListener('click', function(e){
        ensureCanvas();
        spawn(e.clientX, e.clientY);
        if (!raf) raf = requestAnimationFrame(tick);
    }, {passive:true, capture:true});
})();
JS;
    wp_register_script('kratos-vermilion-fireworks', '', array(), THEME_VERSION, true);
    wp_enqueue_script('kratos-vermilion-fireworks');
    wp_add_inline_script('kratos-vermilion-fireworks', $js);
}
add_action('wp_enqueue_scripts', 'kratos_weekday_vermilion_fireworks', 30);

/**
 * 前端皮肤切换器（开发版）：入队样式 + 脚本，并把皮肤清单/URL/存储键喂给 JS。
 * 独立于皮肤模式，仅由 g_weekday_skin_switcher 开关控制。
 */
function kratos_weekday_switcher_enqueue()
{
    if (is_admin() || !kratos_weekday_switcher_enabled()) {
        return;
    }

    wp_enqueue_style(
        'kratos-skin-switcher',
        ASSET_PATH . '/assets/css/skin-switcher.css',
        array('kratos'),
        THEME_VERSION
    );

    wp_enqueue_script(
        'kratos-skin-switcher',
        ASSET_PATH . '/assets/js/skin-switcher.js',
        array(),
        THEME_VERSION,
        true
    );

    // 皮肤清单：按 kratos_weekday_options() 的顺序，附中文 label 与 CSS URL。
    $labels = kratos_weekday_options();
    $css    = kratos_weekday_css_map();
    $skins  = array();
    foreach ($labels as $slug => $label) {
        if (!isset($css[$slug])) {
            continue;
        }
        $skins[] = array(
            'slug'  => $slug,
            'label' => $label,
            'url'   => $css[$slug],
        );
    }

    // 站点皮肤配置：供「恢复默认」清除本地覆盖后，在 JS 端重算站点当前皮肤
    // （auto 按访客本地星期、locked 用锁定皮肤、off 无皮肤），无需刷新页面。
    $s = kratos_weekday_settings();

    wp_localize_script('kratos-skin-switcher', 'kratosSkinSwitcher', array(
        'storage'  => kratos_weekday_switcher_storage_key(),
        'sentinel' => kratos_weekday_switcher_default_sentinel(),
        'skins'    => $skins,
        'site'     => array(
            'mode'   => $s['mode'],
            'locked' => $s['locked'],
            'slugs'  => $s['slugs'],
        ),
        'i18n'     => array(
            'title'     => __('切换皮肤', 'kratos'),
            'subtitle'  => __('人生要勇于尝试', 'kratos'),
            'default'   => __('默认外观', 'kratos'),
            'restore'   => __('恢复默认（清除本地设置）', 'kratos'),
            'close'     => __('关闭', 'kratos'),
            'current'   => __('当前', 'kratos'),
        ),
    ));
}
add_action('wp_enqueue_scripts', 'kratos_weekday_switcher_enqueue', 30);

