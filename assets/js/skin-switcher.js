/*!
 * Kratos+ 前端皮肤切换器（开发版）
 *  - 在工具箱「皮肤」按钮上挂载弹出面板，列出全部皮肤 + 默认外观
 *  - 选中即改 <html data-weekday-skin> 并按需注入对应皮肤 CSS <link>
 *  - 选择写入 localStorage，仅在当前浏览器生效，不影响站点默认与其他访客
 *  - 早期 FOUC 处理见 inc/theme-weekday-skin.php 的 head inline
 */
(function () {
    'use strict';

    var cfg = window.kratosSkinSwitcher;
    if (!cfg || !cfg.skins || !cfg.skins.length) return;

    var STORAGE = cfg.storage || 'kratos_skin_override';
    var SENTINEL = cfg.sentinel || '__default__';
    var i18n = cfg.i18n || {};
    var html = document.documentElement;
    var ls = (function () { try { return window.localStorage; } catch (e) { return null; } })();

    /* slug -> url 查表 */
    var urlBySlug = {};
    for (var i = 0; i < cfg.skins.length; i++) urlBySlug[cfg.skins[i].slug] = cfg.skins[i].url;

    var btn = null, panel = null, open = false;

    function getSaved() {
        if (!ls) return null;
        try { return ls.getItem(STORAGE); } catch (e) { return null; }
    }

    function save(val) {
        if (!ls) return;
        try { ls.setItem(STORAGE, val); } catch (e) {}
    }

    /* 站点配置下当前应生效的皮肤（无本地覆盖时的默认）：
     * locked → 锁定皮肤；auto → 按访客本地星期；off/其它 → 无皮肤(null)。 */
    function siteSlug() {
        var site = cfg.site || {};
        if (site.mode === 'locked') return (site.locked && urlBySlug[site.locked]) ? site.locked : null;
        if (site.mode === 'auto' && site.slugs && site.slugs.length === 7) {
            var s = site.slugs[new Date().getDay()];
            return urlBySlug[s] ? s : null;
        }
        return null;
    }

    /* 当前生效 slug：优先 localStorage 覆盖，否则读 <html> 上的 attr */
    function currentSlug() {
        var saved = getSaved();
        if (saved === SENTINEL) return SENTINEL;
        if (saved && urlBySlug[saved]) return saved;
        var attr = html.getAttribute('data-weekday-skin');
        return (attr && urlBySlug[attr]) ? attr : SENTINEL;
    }

    /* 确保 url 对应的皮肤 CSS 已加载（服务端已入队则跳过；否则复用/新建一个动态 link） */
    function ensureCss(url) {
        if (!url) return;
        var links = document.getElementsByTagName('link');
        for (var j = 0; j < links.length; j++) {
            if (links[j].rel === 'stylesheet' && links[j].href && links[j].href.indexOf(url) !== -1) return;
        }
        var dyn = document.querySelector('link[data-kratos-skin-dyn]');
        if (dyn) { dyn.href = url; return; }
        dyn = document.createElement('link');
        dyn.rel = 'stylesheet';
        dyn.href = url;
        dyn.setAttribute('data-kratos-skin-dyn', '');
        document.head.appendChild(dyn);
    }

    function applySlug(slug) {
        if (slug === SENTINEL) {
            html.removeAttribute('data-weekday-skin');
            save(SENTINEL);
        } else {
            ensureCss(urlBySlug[slug]);
            html.setAttribute('data-weekday-skin', slug);
            save(slug);
        }
        refreshActive();
    }

    /* 恢复默认：清除本地覆盖，回到跟随站点配置（auto/locked/off）。
     * 与「默认外观」不同——后者是把覆盖钉死为无皮肤。 */
    function restore() {
        if (ls) { try { ls.removeItem(STORAGE); } catch (e) {} }
        var s = siteSlug();
        if (s) {
            ensureCss(urlBySlug[s]);
            html.setAttribute('data-weekday-skin', s);
        } else {
            html.removeAttribute('data-weekday-skin');
        }
        refreshActive();
    }

    function refreshActive() {
        if (!panel) return;
        var cur = currentSlug();
        var items = panel.querySelectorAll('.skin-switcher-item');
        for (var k = 0; k < items.length; k++) {
            var on = items[k].getAttribute('data-slug') === cur;
            items[k].classList.toggle('is-current', on);
            items[k].setAttribute('aria-checked', on ? 'true' : 'false');
        }
    }

    function buildPanel() {
        panel = document.createElement('div');
        panel.className = 'skin-switcher-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', i18n.title || '切换皮肤');
        panel.hidden = true;

        var head = document.createElement('div');
        head.className = 'skin-switcher-head';
        var t = document.createElement('div');
        t.className = 'skin-switcher-t';
        t.textContent = i18n.title || '切换皮肤';
        var sub = document.createElement('div');
        sub.className = 'skin-switcher-sub';
        sub.textContent = i18n.subtitle || '';
        head.appendChild(t);
        head.appendChild(sub);
        panel.appendChild(head);

        var list = document.createElement('div');
        list.className = 'skin-switcher-list';
        list.setAttribute('role', 'radiogroup');

        // 默认外观
        list.appendChild(makeItem(SENTINEL, i18n.default || '默认外观'));
        for (var m = 0; m < cfg.skins.length; m++) {
            list.appendChild(makeItem(cfg.skins[m].slug, cfg.skins[m].label));
        }
        panel.appendChild(list);

        // 底部：恢复默认（清除本地设置）
        var foot = document.createElement('div');
        foot.className = 'skin-switcher-foot';
        var reset = document.createElement('button');
        reset.type = 'button';
        reset.className = 'skin-switcher-reset';
        reset.textContent = i18n.restore || '恢复默认（清除本地设置）';
        reset.addEventListener('click', function () {
            restore();
            closePanel();
        });
        foot.appendChild(reset);
        panel.appendChild(foot);

        document.body.appendChild(panel);
    }

    function makeItem(slug, label) {
        var it = document.createElement('button');
        it.type = 'button';
        it.className = 'skin-switcher-item';
        it.setAttribute('role', 'radio');
        it.setAttribute('data-slug', slug);
        it.setAttribute('aria-checked', 'false');
        var dot = document.createElement('span');
        dot.className = 'skin-switcher-dot skin-dot-' + slug;
        var name = document.createElement('span');
        name.className = 'skin-switcher-name';
        name.textContent = label;
        it.appendChild(dot);
        it.appendChild(name);
        it.addEventListener('click', function () {
            applySlug(this.getAttribute('data-slug'));
            closePanel();
        });
        return it;
    }

    function openPanel() {
        if (!panel) buildPanel();
        refreshActive();
        panel.hidden = false;
        // 强制回流后加类，触发过渡
        void panel.offsetWidth;
        panel.classList.add('is-open');
        open = true;
        btn.setAttribute('aria-expanded', 'true');
        document.addEventListener('mousedown', onOutside, true);
        document.addEventListener('keydown', onKey, true);
    }

    function closePanel() {
        if (!panel || !open) return;
        panel.classList.remove('is-open');
        open = false;
        btn.setAttribute('aria-expanded', 'false');
        document.removeEventListener('mousedown', onOutside, true);
        document.removeEventListener('keydown', onKey, true);
        var p = panel;
        setTimeout(function () { if (!open) p.hidden = true; }, 220);
    }

    function togglePanel() { open ? closePanel() : openPanel(); }

    function onOutside(e) {
        if (panel.contains(e.target) || btn.contains(e.target)) return;
        closePanel();
    }

    function onKey(e) {
        if (e.key === 'Escape') { closePanel(); btn.focus(); }
    }

    function mount() {
        btn = document.querySelector('.k-footer .f-toolbox .skin-switcher');
        if (!btn) return;
        document.body.classList.add('has-kratos-skin-switcher');
        btn.addEventListener('click', function (e) { e.preventDefault(); togglePanel(); });
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); togglePanel(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
