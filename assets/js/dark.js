/*!
 * Kratos+ 暗夜模式前端脚本
 *  - 监听系统主题（auto 模式）
 *  - 按时间段自动切换（schedule 模式）
 *  - 提供右下角浮动切换按钮，手动切换写入 localStorage 覆盖默认
 */
(function () {
    'use strict';

    var cfg = window.kratosDarkMode;
    if (!cfg || !cfg.enabled) return;

    var STORAGE = cfg.storage || 'kratos_theme_mode';
    var STORAGE_TS = cfg.storageTs || 'kratos_theme_mode_ts';
    var html = document.documentElement;
    var ls = (function () { try { return window.localStorage; } catch (e) { return null; } })();

    function parseHM(t) {
        if (!t) return 0;
        var p = String(t).split(':');
        return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0);
    }

    function inSchedule(now) {
        var s = parseHM(cfg.start);
        var e = parseHM(cfg.end);
        if (s === e) return false;
        var cur = now.getHours() * 60 + now.getMinutes();
        return s < e ? (cur >= s && cur < e) : (cur >= s || cur < e);
    }

    function systemMode() {
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }

    function autoMode() {
        var d = cfg.default;
        if (d === 'dark' || d === 'light') return d;
        if (d === 'auto') return systemMode();
        if (d === 'schedule') return inSchedule(new Date()) ? 'dark' : 'light';
        return 'light';
    }

    function getSavedMode() {
        if (!ls) return null;
        var m = ls.getItem(STORAGE);
        if (m !== 'light' && m !== 'dark') return null;
        if (cfg.remember > 0) {
            var ts = parseInt(ls.getItem(STORAGE_TS) || '0', 10) || 0;
            if (ts && (Date.now() - ts) > cfg.remember * 86400000) {
                try { ls.removeItem(STORAGE); ls.removeItem(STORAGE_TS); } catch (e) {}
                return null;
            }
        }
        return m;
    }

    function applyMode(mode) {
        html.setAttribute('data-theme', mode);
        updateToggleIcon(mode);
    }

    function setManual(mode) {
        if (ls) {
            try {
                ls.setItem(STORAGE, mode);
                ls.setItem(STORAGE_TS, String(Date.now()));
            } catch (e) {}
        }
        applyMode(mode);
    }

    function currentMode() {
        return html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    /* --- 切换按钮：服务端渲染在 footer.php 的 .f-toolbox 中，这里只绑定行为 --- */
    var btn = null;

    function updateToggleIcon(mode) {
        if (!btn) return;
        var toDark = mode !== 'dark';
        btn.setAttribute('aria-label', toDark ? cfg.i18n.toDark : cfg.i18n.toLight);
        btn.setAttribute('title', toDark ? cfg.i18n.toDark : cfg.i18n.toLight);
        btn.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
    }

    function mountToggle() {
        if (!cfg.toggle) return;
        btn = document.querySelector('.k-footer .f-toolbox .darkmode');
        if (!btn) return;
        var handler = function (e) {
            e.preventDefault();
            setManual(currentMode() === 'dark' ? 'light' : 'dark');
        };
        btn.addEventListener('click', handler);
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') handler(e);
        });
        document.body.classList.add('has-kratos-darkmode-toggle');
        updateToggleIcon(currentMode());
    }

    /* --- 自动跟随：仅当用户没有手动覆盖时生效 --- */
    function autoRefresh() {
        if (getSavedMode()) return;
        applyMode(autoMode());
    }

    /* 系统主题改变时同步（auto 模式） */
    if (window.matchMedia && cfg.default === 'auto') {
        var mql = window.matchMedia('(prefers-color-scheme: dark)');
        var listener = function () { autoRefresh(); };
        if (mql.addEventListener) mql.addEventListener('change', listener);
        else if (mql.addListener) mql.addListener(listener);
    }

    /* 时间段定时刷新（schedule 模式） */
    if (cfg.default === 'schedule') {
        setInterval(autoRefresh, 60 * 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountToggle);
    } else {
        mountToggle();
    }

    /* --- 对外入口 ---
     * 供命令面板等其它组件切换明暗，不必各自重抄一遍
     * 「写 localStorage + 写 data-theme + 同步按钮图标」这套逻辑。
     * 注意页脚切换按钮可以被单独关掉（g_darkmode_toggle），
     * 那种情况下本文件依旧加载、切换依旧可用，只是没有按钮可点，
     * 所以这个入口不能依赖按钮存在。 */
    window.kratosDark = {
        current: currentMode,
        set: function (mode) {
            if (mode !== 'dark' && mode !== 'light') return;
            setManual(mode);
        },
        toggle: function () {
            setManual(currentMode() === 'dark' ? 'light' : 'dark');
        }
    };
})();
