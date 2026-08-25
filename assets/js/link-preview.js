/**
 * 内链悬浮预览卡
 *  - 扫描正文内指向本站的链接，hover 停留 delay 毫秒后请求预览数据并弹卡
 *  - 单实例卡片复用（整页只有一个 .kratos-lpv 节点），随目标链接定位
 *  - 结果按 href 在内存中缓存，同一链接不重复请求
 *  - 触屏 / 无 hover 能力的设备直接不启用
 *
 * @author Dylan Li (Kratos-plus)
 * @license GPL-3.0 License
 */
(function () {
    'use strict';

    var cfg = window.kratosLinkPreview;
    if (!cfg || !cfg.endpoint) return;

    // 只在真正有悬停能力的指针设备上启用
    if (window.matchMedia && !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    var DELAY = parseInt(cfg.delay, 10) || 320;
    var i18n = cfg.i18n || {};
    var cache = {};      // href -> payload | 'none'
    var pending = {};    // href -> true（请求中，避免并发重复）
    var card = null;
    var showTimer = null;
    var hideTimer = null;
    var activeAnchor = null;

    function ensureCard() {
        if (card) return card;
        card = document.createElement('div');
        card.className = 'kratos-lpv';
        card.setAttribute('role', 'tooltip');
        // 鼠标移到卡片上时不收起，方便点进去
        card.addEventListener('mouseenter', function () {
            clearTimeout(hideTimer);
        });
        card.addEventListener('mouseleave', scheduleHide);
        document.body.appendChild(card);
        return card;
    }

    function isInternalPostLink(a) {
        if (!a || !a.getAttribute) return false;
        var raw = a.getAttribute('href') || '';
        if (raw === '' || raw.charAt(0) === '#') return false;
        if (/^(mailto:|tel:|javascript:)/i.test(raw)) return false;
        if (a.hostname !== window.location.hostname) return false;
        // 排除指向当前页自身的锚点链接
        if (a.pathname === window.location.pathname && !a.search) return false;
        // 图片链接（灯箱用）不预览
        if (/\.(jpe?g|png|gif|webp|bmp|svg)$/i.test(a.pathname)) return false;
        if (a.querySelector && a.querySelector('img')) return false;
        return true;
    }

    function positionCard(anchor) {
        var r = anchor.getBoundingClientRect();
        var cw = card.offsetWidth;
        var ch = card.offsetHeight;
        var margin = 10;

        var left = window.pageXOffset + r.left;
        // 右侧溢出则右对齐到视口内
        var maxLeft = window.pageXOffset + window.innerWidth - cw - margin;
        if (left > maxLeft) left = maxLeft;
        var minLeft = window.pageXOffset + margin;
        if (left < minLeft) left = minLeft;

        // 默认放在链接下方，下方空间不够则翻到上方
        var top = window.pageYOffset + r.bottom + 8;
        if (r.bottom + ch + 8 > window.innerHeight && r.top - ch - 8 > 0) {
            top = window.pageYOffset + r.top - ch - 8;
        }

        card.style.left = Math.round(left) + 'px';
        card.style.top = Math.round(top) + 'px';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function renderPayload(d) {
        if (d.kind === 'term') {
            renderTerm(d);
            return;
        }

        var html = '';
        if (d.thumb) {
            html += '<a class="lpv-thumb-link" href="' + esc(d.url) + '" tabindex="-1" aria-hidden="true">'
                + '<img class="lpv-thumb" src="' + esc(d.thumb) + '" alt="" loading="lazy" />'
                + '</a>';
        }
        html += '<h4 class="lpv-title">'
            + '<a class="lpv-link" href="' + esc(d.url) + '">' + esc(d.title) + '</a>'
            + '</h4>';
        if (d.excerpt) {
            html += '<p class="lpv-excerpt">' + esc(d.excerpt) + '</p>';
        }

        var bits = [];
        if (d.date) bits.push('<span>' + esc(d.date) + '</span>');
        if (d.cat) {
            // 有分类链接就渲染成真链接，可以从卡片直接点进分类归档
            bits.push(d.catUrl
                ? '<a class="lpv-cat" href="' + esc(d.catUrl) + '">' + esc(d.cat) + '</a>'
                : '<span class="lpv-cat">' + esc(d.cat) + '</span>');
        }
        if (d.minutes) {
            bits.push('<span>' + esc(d.words) + ' ' + esc(i18n.words || '字')
                + ' · ' + esc(d.minutes) + ' ' + esc(i18n.minutes || '分钟') + '</span>');
        }
        if (d.comments) {
            bits.push('<span>' + esc(d.comments) + ' ' + esc(i18n.comments || '条评论') + '</span>');
        }
        if (bits.length) {
            html += '<div class="lpv-meta">' + bits.join('<span class="lpv-sep" aria-hidden="true">·</span>') + '</div>';
        }

        card.innerHTML = html;
    }

    /**
     * 归档卡（分类 / 标签 / 系列）：无缩略图，改用分类法徽章 + 文章数 + 最近几篇。
     * 归档名与每条文章标题都是真链接，可以直接从卡片里点进去。
     */
    function renderTerm(d) {
        var html = '<div class="lpv-term-head">'
            + '<span class="lpv-term-badge">' + esc(d.taxLabel) + '</span>'
            + '<h4 class="lpv-title lpv-term-title">'
            + '<a class="lpv-link" href="' + esc(d.url) + '">' + esc(d.title) + '</a>'
            + '</h4>'
            + '</div>';

        if (d.excerpt) {
            html += '<p class="lpv-excerpt">' + esc(d.excerpt) + '</p>';
        }

        if (d.recent && d.recent.length) {
            html += '<ul class="lpv-term-list">';
            d.recent.forEach(function (p) {
                html += '<li class="lpv-term-item">'
                    + '<a class="lpv-term-item-title lpv-link" href="' + esc(p.url) + '">' + esc(p.title) + '</a>'
                    + '<span class="lpv-term-item-date">' + esc(p.date) + '</span>'
                    + '</li>';
            });
            html += '</ul>';
        }

        html += '<div class="lpv-meta"><span>'
            + (d.count ? esc(d.count) + ' ' + esc(i18n.posts || '篇文章') : esc(i18n.empty || '暂无文章'))
            + '</span></div>';

        card.innerHTML = html;
    }

    function show(anchor) {
        var href = anchor.href;
        var hit = cache[href];

        ensureCard();

        if (hit === 'none') return;

        if (hit) {
            reveal(hit, anchor);
            return;
        }

        // 静默加载：请求期间不显示任何占位，页面上不会闪出「加载中」。
        // 数据到手且鼠标仍在同一个链接上，才淡入卡片；失败/无结果什么都不发生。
        if (pending[href]) return;
        pending[href] = true;

        fetch(cfg.endpoint + '?url=' + encodeURIComponent(href), {
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json();
        }).then(function (d) {
            delete pending[href];
            if (!d || !d.found) {
                cache[href] = 'none';
                return;
            }
            cache[href] = d;
            if (activeAnchor === anchor) reveal(d, anchor);
        }).catch(function () {
            delete pending[href];
            cache[href] = 'none';
        });
    }

    /**
     * 渲染并定位后再显示。
     * 顺序很重要：先写内容 → 再量尺寸定位 → 最后加 is-visible。
     * 若先显示后定位，卡片会在目标位置之外闪一帧。
     */
    function reveal(payload, anchor) {
        renderPayload(payload);
        positionCard(anchor);
        card.classList.add('is-visible');
    }

    function hide() {
        if (!card) return;
        card.classList.remove('is-visible');
    }

    /**
     * 延时收起。这段延时同时充当「从链接移动到卡片」的宽容窗口 ——
     * 卡片与链接之间有 8px 间隙，鼠标穿过间隙时既不在链接上也不在卡片上，
     * 收得太快就点不到卡片里的标题链接了。
     */
    function scheduleHide() {
        clearTimeout(showTimer);
        hideTimer = setTimeout(hide, 260);
    }

    function bind(a) {
        a.classList.add('lpv-anchor');
        a.addEventListener('mouseenter', function () {
            clearTimeout(hideTimer);
            clearTimeout(showTimer);
            activeAnchor = a;
            showTimer = setTimeout(function () { show(a); }, DELAY);
        });
        a.addEventListener('mouseleave', function () {
            if (activeAnchor === a) activeAnchor = null;
            scheduleHide();
        });
    }

    function init() {
        var scope = document.querySelector(cfg.scope || '.k-main .details .content');
        if (!scope) return;
        var links = scope.querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
            if (isInternalPostLink(links[i])) bind(links[i]);
        }
        // 滚动时卡片跟着链接跑意义不大，直接收起，避免脱离锚点悬在半空
        window.addEventListener('scroll', hide, { passive: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
