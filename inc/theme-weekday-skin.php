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
 * @author Dylan Li (Kratos+ fork) <https://www.lifengdi.com>
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
    );
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
    if ($s['mode'] === 'off') {
        return;
    }
    $cfg = wp_json_encode(array(
        'mode'   => $s['mode'],
        'slugs'  => $s['slugs'],
        'locked' => $s['locked'],
    ));
    echo "<script>(function(){try{var c=" . $cfg . ";var slug=c.mode==='locked'?c.locked:c.slugs[new Date().getDay()];document.documentElement.setAttribute('data-weekday-skin',slug);}catch(e){}})();</script>\n";
}
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
    if ($s['mode'] === 'off') {
        return;
    }
    /**
     * 额外皮肤（羊皮 / 黄绢 / 朱砂 / 莫兰迪家族）拆到独立 CSS 文件，且每个文件
     * 已内嵌通用规则副本（选择器锁定该 slug），加载它就完整自足；无需再叠加
     * weekday-skins.css。工作日 mon~sun（auto 模式或 locked 命中 mon~sun）仍
     * 使用统一的 weekday-skins.css。
     */
    $variant_files = array(
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
    );
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
    if ($s['mode'] === 'off') return;
    // auto 模式下 vermilion 不在每日轮播里（只出现在 locked），因此只在 locked=vermilion 才注入
    if ($s['mode'] !== 'locked' || $s['locked'] !== 'vermilion') return;

    $js = <<<'JS'
(function(){
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

