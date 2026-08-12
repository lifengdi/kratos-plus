/**
 * 命令面板（⌘K / Ctrl+K）
 *
 *  - 快捷键：macOS ⌘K，Windows / Linux Ctrl+K（提示文案随系统变化）
 *  - 输入即增量搜索文章（去抖），同时对「页面 / 操作 / 皮肤」命令做本地模糊过滤
 *  - 键盘：↑↓ 选择、Enter 执行、Esc 关闭；打开时锁滚动、关闭时焦点还原
 *
 * @author Dylan Li (Kratos+)
 * @license GPL-3.0 License
 */
(function () {
    'use strict';

    var cfg = window.kratosCmdK;
    if (!cfg) return;

    var i18n = cfg.i18n || {};
    var DEBOUNCE = parseInt(cfg.debounce, 10) || 220;

    // 平台判定：优先用 userAgentData.platform，回落到 platform / userAgent
    var IS_MAC = (function () {
        var p = '';
        try {
            if (navigator.userAgentData && navigator.userAgentData.platform) {
                p = navigator.userAgentData.platform;
            } else {
                p = navigator.platform || navigator.userAgent || '';
            }
        } catch (e) { p = ''; }
        return /mac|iphone|ipad|ipod/i.test(p);
    })();

    var root = null;
    var input = null;
    var listEl = null;
    var open = false;
    var items = [];        // 当前可见项 [{label, sub, group, run}]
    var cursor = 0;
    var searchTimer = null;
    var lastFocus = null;
    var reqSeq = 0;

    var ICONS = {
        home: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        archive: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
        clock: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        chat: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        heart: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>',
        trophy: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v6a6 6 0 0 1-12 0z"/><path d="M6 5H3v2a4 4 0 0 0 4 4"/><path d="M18 5h3v2a4 4 0 0 1-4 4"/><line x1="12" y1="15" x2="12" y2="19"/><line x1="8" y1="21" x2="16" y2="21"/></svg>',
        link: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>',
        globe: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
        flag: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>',
        gift: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>',
        chart: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        search: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        doc: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        moon: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
        dice: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>',
        palette: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="8.5" cy="10.5" r="1.2"/><circle cx="15.5" cy="10.5" r="1.2"/><circle cx="12" cy="15.5" r="1.2"/></svg>',
        /* 页脚唤出按钮专用：终端提示符造型（`>_`）。
         * 不能再用放大镜 —— 页脚工具栈里紧挨着的就是站内搜索按钮，
         * 两个放大镜并排会让人以为按错了或重复渲染。 */
        command: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4" width="19" height="16" rx="2.5"/><polyline points="7.5 10 10 12.5 7.5 15"/><line x1="12.5" y1="15.5" x2="16.5" y2="15.5"/></svg>'
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function fmt(tpl, val) {
        return String(tpl || '').replace('%s', val);
    }

    /* ---------------- 静态命令 ---------------- */

    function pageCommands() {
        return (cfg.pages || []).map(function (p) {
            return {
                group: i18n.groupPages,
                label: p.label,
                icon: ICONS[p.icon] || ICONS.doc,
                run: function () { window.location.href = p.url; }
            };
        });
    }

    function actionCommands() {
        var out = [];

        if (cfg.darkEnabled) {
            out.push({
                group: i18n.groupActions,
                label: i18n.toggleDark,
                icon: ICONS.moon,
                run: function () {
                    // 优先点页脚原生开关，让 dark.js 保持唯一的模式来源
                    var btn = document.querySelector('.f-toolbox .darkmode');
                    if (btn) {
                        close();
                        btn.click();
                        return;
                    }
                    var html = document.documentElement;
                    var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    html.setAttribute('data-theme', next);
                    try {
                        localStorage.setItem('kratos_theme_mode', next);
                        localStorage.setItem('kratos_theme_mode_ts', String(Date.now()));
                    } catch (e) {}
                    close();
                }
            });
        }

        if (cfg.stumble) {
            out.push({
                group: i18n.groupActions,
                label: i18n.stumble,
                icon: ICONS.dice,
                run: function () { window.location.href = cfg.stumble; }
            });
        }

        out.push({
            group: i18n.groupActions,
            label: i18n.goHome,
            icon: ICONS.home,
            run: function () { window.location.href = cfg.home; }
        });

        return out;
    }

    function skinCommands() {
        var skins = cfg.skins || [];
        if (!skins.length || !cfg.skinStorage) return [];

        function apply(value) {
            try { localStorage.setItem(cfg.skinStorage, value); } catch (e) {}
            // 重新加载：让 skin-switcher.js 在下一次启动时还原并注入对应皮肤 CSS，
            // 避免在这里重抄一份「slug → CSS 文件」的注入逻辑
            window.location.reload();
        }

        var out = skins.map(function (s) {
            return {
                group: i18n.groupSkins,
                label: s.label,
                sub: s.slug,
                icon: ICONS.palette,
                run: function () { apply(s.slug); }
            };
        });

        out.push({
            group: i18n.groupSkins,
            label: i18n.skinDefault,
            icon: ICONS.palette,
            run: function () { apply(cfg.skinSentinel); }
        });

        return out;
    }

    function staticCommands() {
        return pageCommands().concat(actionCommands()).concat(skinCommands());
    }

    /* ---------------- 过滤与渲染 ---------------- */

    function matches(cmd, q) {
        if (!q) return true;
        var hay = (cmd.label + ' ' + (cmd.sub || '')).toLowerCase();
        return hay.indexOf(q.toLowerCase()) !== -1;
    }

    function render(searchItems) {
        var q = input.value.trim();
        items = [];

        if (q) {
            items.push({
                group: i18n.groupSearch,
                label: fmt(i18n.searchAll, q),
                icon: ICONS.search,
                run: function () {
                    window.location.href = cfg.searchUrl + '?s=' + encodeURIComponent(q);
                }
            });
            (searchItems || []).forEach(function (it) {
                items.push({
                    group: i18n.groupSearch,
                    label: it.title,
                    sub: it.type + ' · ' + it.date,
                    icon: ICONS.doc,
                    run: function () { window.location.href = it.url; }
                });
            });
        }

        staticCommands().forEach(function (c) {
            if (matches(c, q)) items.push(c);
        });

        if (cursor >= items.length) cursor = items.length ? items.length - 1 : 0;

        if (!items.length) {
            listEl.innerHTML = '<div class="cmdk-empty">' + esc(i18n.empty) + '</div>';
            return;
        }

        var html = '';
        var lastGroup = null;
        items.forEach(function (it, idx) {
            if (it.group !== lastGroup) {
                html += '<div class="cmdk-group">' + esc(it.group) + '</div>';
                lastGroup = it.group;
            }
            html += '<div class="cmdk-item' + (idx === cursor ? ' is-active' : '') + '"'
                + ' role="option" data-idx="' + idx + '"'
                + ' aria-selected="' + (idx === cursor ? 'true' : 'false') + '">'
                + '<span class="cmdk-item-ico kr-ico" aria-hidden="true">' + (it.icon || ICONS.doc) + '</span>'
                + '<span class="cmdk-item-label">' + esc(it.label) + '</span>'
                + (it.sub ? '<span class="cmdk-item-sub">' + esc(it.sub) + '</span>' : '')
                + '</div>';
        });
        listEl.innerHTML = html;
    }

    function moveCursor(delta) {
        if (!items.length) return;
        cursor = (cursor + delta + items.length) % items.length;
        var nodes = listEl.querySelectorAll('.cmdk-item');
        for (var i = 0; i < nodes.length; i++) {
            var on = parseInt(nodes[i].getAttribute('data-idx'), 10) === cursor;
            nodes[i].classList.toggle('is-active', on);
            nodes[i].setAttribute('aria-selected', on ? 'true' : 'false');
            if (on && nodes[i].scrollIntoView) {
                nodes[i].scrollIntoView({ block: 'nearest' });
            }
        }
    }

    function execute() {
        var it = items[cursor];
        if (it && typeof it.run === 'function') it.run();
    }

    /* ---------------- 增量搜索 ---------------- */

    function scheduleSearch() {
        clearTimeout(searchTimer);
        var q = input.value.trim();
        if (!q) {
            render([]);
            return;
        }
        render([]); // 先用本地命令即时响应，网络结果回来再补
        searchTimer = setTimeout(function () {
            var seq = ++reqSeq;
            fetch(cfg.searchEndpoint + '?q=' + encodeURIComponent(q), {
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); })
              .then(function (d) {
                  // 丢弃过期响应，避免慢请求覆盖新输入的结果
                  if (seq !== reqSeq || !open) return;
                  render((d && d.items) || []);
              }).catch(function () {});
        }, DEBOUNCE);
    }

    /* ---------------- 打开 / 关闭 ---------------- */

    function build() {
        if (root) return;

        root = document.createElement('div');
        root.className = 'kratos-cmdk';
        root.setAttribute('hidden', 'hidden');
        root.innerHTML =
            '<div class="cmdk-backdrop"></div>'
            + '<div class="cmdk-panel kr-card" role="dialog" aria-modal="true" aria-label="' + esc(i18n.open) + '">'
            + '  <div class="cmdk-head">'
            + '    <span class="cmdk-head-ico" aria-hidden="true">' + ICONS.search + '</span>'
            + '    <input class="cmdk-input" type="text" autocomplete="off" spellcheck="false" placeholder="' + esc(i18n.placeholder) + '" aria-label="' + esc(i18n.placeholder) + '" />'
            + '    <kbd class="cmdk-kbd">' + esc(IS_MAC ? '⌘K' : 'Ctrl+K') + '</kbd>'
            + '  </div>'
            + '  <div class="cmdk-list" role="listbox"></div>'
            + '  <div class="cmdk-foot">' + esc(i18n.navHint) + '</div>'
            + '</div>';

        document.body.appendChild(root);

        input = root.querySelector('.cmdk-input');
        listEl = root.querySelector('.cmdk-list');

        root.querySelector('.cmdk-backdrop').addEventListener('click', close);
        input.addEventListener('input', scheduleSearch);

        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); moveCursor(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); moveCursor(-1); }
            else if (e.key === 'Enter') { e.preventDefault(); execute(); }
            else if (e.key === 'Escape') { e.preventDefault(); close(); }
        });

        listEl.addEventListener('mousemove', function (e) {
            var it = e.target.closest ? e.target.closest('.cmdk-item') : null;
            if (!it) return;
            var idx = parseInt(it.getAttribute('data-idx'), 10);
            if (idx === cursor) return;
            cursor = idx;
            moveCursor(0);
        });

        listEl.addEventListener('click', function (e) {
            var it = e.target.closest ? e.target.closest('.cmdk-item') : null;
            if (!it) return;
            cursor = parseInt(it.getAttribute('data-idx'), 10);
            execute();
        });
    }

    function openPalette() {
        build();
        if (open) return;
        open = true;
        lastFocus = document.activeElement;
        root.removeAttribute('hidden');
        // 强制一帧后再加类，让过渡动画生效
        requestAnimationFrame(function () { root.classList.add('is-open'); });
        document.documentElement.classList.add('cmdk-lock');
        input.value = '';
        cursor = 0;
        render([]);
        input.focus();
    }

    function close() {
        if (!open || !root) return;
        open = false;
        clearTimeout(searchTimer);
        root.classList.remove('is-open');
        document.documentElement.classList.remove('cmdk-lock');
        setTimeout(function () { if (!open) root.setAttribute('hidden', 'hidden'); }, 200);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    /* ---------------- 入口 ---------------- */

    function isTypingTarget(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
    }

    function mountButton() {
        if (!cfg.showButton) return;
        var box = document.querySelector('.f-toolbox');
        if (!box) return;

        var btn = document.createElement('div');
        btn.className = 'cmdk-launch';
        btn.setAttribute('role', 'button');
        btn.setAttribute('tabindex', '0');
        var hint = IS_MAC ? i18n.hintMac : i18n.hintWin;
        btn.setAttribute('aria-label', i18n.open + ' · ' + hint);
        btn.setAttribute('title', i18n.open + ' · ' + hint);
        btn.innerHTML = '<span class="cmdk-launch-ico" aria-hidden="true">' + ICONS.command + '</span>';
        btn.addEventListener('click', openPalette);
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPalette(); }
        });
        // .f-toolbox 是 column-reverse，插到最前 = 视觉最上方
        box.insertBefore(btn, box.firstChild);
    }

    document.addEventListener('keydown', function (e) {
        var hotkey = (e.key === 'k' || e.key === 'K') && (IS_MAC ? e.metaKey : e.ctrlKey);
        if (hotkey) {
            e.preventDefault();
            open ? close() : openPalette();
            return;
        }
        if (e.key === 'Escape' && open) {
            e.preventDefault();
            close();
            return;
        }
        // 在非输入态按 / 也能唤出（常见站内搜索习惯）
        if (e.key === '/' && !open && !isTypingTarget(document.activeElement)) {
            e.preventDefault();
            openPalette();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountButton);
    } else {
        mountButton();
    }

    // 供其它脚本 / 自定义按钮调用
    window.kratosOpenCommandPalette = openPalette;
})();
