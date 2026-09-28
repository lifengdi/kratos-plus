/**
 * 游客中心前端
 *
 * 从 WP 原生 comment cookie 读邮箱 → 通过 POST body 传给 REST 接口 → 渲染。
 * 邮箱不进 URL / DOM / referer / access log；服务端按邮箱直接查（md5 单向
 * 不可反查，只做缓存 key 与手动徽章匹配用）。
 */
(function () {
    'use strict';

    var root = document.getElementById('kratos-visitor-center');
    if (!root || typeof KratosVC === 'undefined') return;

    var I18N = KratosVC.i18n || {};

    /* ---------- 读 comment cookie ---------- */
    function readEmailFromCookie() {
        var m = document.cookie.match(/comment_author_email_[^=]+=([^;]+)/);
        if (!m) return null;
        try { return decodeURIComponent(m[1]).trim().toLowerCase(); } catch (e) { return null; }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    /* ---------- 渲染 ---------- */
    var SVG = KratosVC.svg || {};

    // 页面顶部标题头由 page-visitor-center.php 服务端渲染（复用「特色标题」kfl-header 视觉），
    // JS 不再输出 kr-hd

    function renderEmpty() {
        root.dataset.state = 'empty';
        root.innerHTML =
            '<div class="kr-body kvc-identity-card kvc-empty">' +
              '<div class="kvc-empty-ico kr-ico">' + (SVG.empty || '') + '</div>' +
              '<h2 class="kvc-empty-title">' + escapeHtml(I18N.empty_title || '') + '</h2>' +
              '<p class="kvc-empty-desc">' + escapeHtml(I18N.empty_desc || '') + '</p>' +
              '<a href="' + escapeHtml(KratosVC.homeUrl) + '" class="kr-btn">' + escapeHtml(I18N.empty_cta || '') + '</a>' +
            '</div>';
    }

    function renderNotFound() {
        root.dataset.state = 'notfound';
        root.innerHTML =
            '<div class="kr-body kvc-identity-card kvc-empty">' +
              '<div class="kvc-empty-ico kr-ico">' + (SVG.notfound || '') + '</div>' +
              '<h2 class="kvc-empty-title">' + escapeHtml(I18N.not_found || '') + '</h2>' +
              '<a href="' + escapeHtml(KratosVC.homeUrl) + '" class="kr-btn">' + escapeHtml(I18N.empty_cta || '') + '</a>' +
            '</div>';
    }

    function renderError() {
        root.dataset.state = 'error';
        root.innerHTML = '<div class="kr-body kvc-identity-card kvc-empty"><p>' + escapeHtml(I18N.load_error || '') + '</p></div>';
    }

    function renderData(d) {
        root.dataset.state = 'loaded';

        // 等级徽章：与评论区 .kratos-rank-badge 保持同款（小 span，自定义 bg/color，无图标）
        var levelHtml = '';
        if (d.level) {
            levelHtml = '<span class="kratos-rank-badge kvc-level" style="display:inline-block;padding:1px 7px;font-size:11px;line-height:1.5;border-radius:3px;font-weight:500;background:'
                + escapeHtml(d.level.bg_color) + ';color:' + escapeHtml(d.level.color) + ';">'
                + escapeHtml(d.level.title) + '</span>';
        }

        var lastActiveHtml = d.recent
            ? '<span class="kvc-pill-active-at">最近活跃于：' + d.recent[0].date + '</span>'
            : '';

        var urlHtml = d.url
            ? '<span class="kr-pill kvc-pill-home">' + (SVG.home || '') + '<a target="_blank" href="' + d.url + '">个人主页</a></span>'
            : '';
        var regionHtml = d.region
            ? '<span class="kr-pill kvc-pill-region">' + (SVG.region || '') + '' + escapeHtml(d.region) + '</span>'
            : '';

        var stats = [
            { num: d.stats.total, label: I18N.total },
            { num: d.stats.heart, label: I18N.heart },
            { num: d.stats.reply, label: I18N.reply },
            { num: d.stats.days,  label: I18N.days },
        ];
        var statsHtml = stats.map(function (s) {
            return '<div class="kr-card kvc-stat"><div class="kvc-stat-num">' + s.num + '</div><div class="kvc-stat-label">' + escapeHtml(s.label) + '</div></div>';
        }).join('');

        var badgesHtml = (d.badges || []).map(function (b) {
            var cls = 'kvc-badge' + (b.unlocked ? '' : ' is-locked') + (b.manual ? ' is-manual' : '');
            var tail = b.manual
                ? ' <em class="kvc-badge-tag">' + escapeHtml(I18N.manual_tag) + '</em>'
                : (!b.unlocked && b.progress ? ' <em class="kvc-badge-progress">(' + escapeHtml(b.progress) + ')</em>' : '');
            return '<div class="' + cls + '" title="' + escapeHtml(b.desc || '') + '">' +
                ((b.icon_svg) ? '<span class="kvc-badge-ico">' + (b.icon_svg || '') + '</span>' : '') +
                     '<span class="kvc-badge-name">' + escapeHtml(b.name) + tail + '</span>' +
                   '</div>';
        }).join('');

        var recentHtml = (d.recent || []).map(function (r) {
            var heart = r.heart ? d.heart_badge : '';
            return '<div class="kvc-comment">' +
                     '<div class="kr-ico kvc-comment-ico">' + (SVG.chat || '') + '</div>' +
                     '<div class="kvc-comment-body">' +
                       '<div class="kvc-comment-meta">' +
                         '在 <a href="' + escapeHtml(r.link) + '">' + escapeHtml(r.post_title) + '</a> 中说：' +
                       '</div>' +
                       '<p class="kvc-comment-text">' + (r.excerpt || '') + '</p>' +
                       '<div class="kvc-comment-meta">' +
                         '<span>' + escapeHtml(r.date) + '</span>'  + heart +
                       '</div>' +
                     '</div>' +
                   '</div>';
        }).join('');

        var topPostsHtml = (d.top_posts || []).map(function (p) {
            return '<a class="kvc-top-post" href="' + escapeHtml(p.link) + '">' +
                     '<span class="kvc-top-post-title">' + escapeHtml(p.title) + '</span>' +
                     '<span class="kvc-top-post-count">' + p.count + '</span>' +
                   '</a>';
        }).join('');

        var heatHtml = buildHeatmap(d.activity || {});

        root.innerHTML =
            '<div class="kr-body kvc-identity-card">' +
              '<div class="kvc-identity">' +
                '<div class="kvc-avatar"><img src="' + escapeHtml(d.avatar) + '" alt=""></div>' +
                '<div class="kvc-id-main">' +
                  '<h2 class="kvc-name">' + escapeHtml(d.name || '') + '</h2>' +
                  '<div class="kvc-meta-row">' + levelHtml + lastActiveHtml + '</div>' +
                  '<div class="kvc-meta-row">' + urlHtml + regionHtml + '</div>' +
                '</div>' +
              '</div>' +
            '</div>' +
            '<div class="kvc-stats">' + statsHtml + '</div>' +
            '<div class="kr-body kvc-identity-card">' +
              '<h3 class="kvc-section-title"><span class="kr-dot"></span>' + escapeHtml(I18N.badges) + '</h3>' +
              '<div class="kvc-badges">' + badgesHtml + '</div>' +
            '</div>' +
            (recentHtml ? (
              '<div class="kr-body kvc-identity-card">' +
                '<h3 class="kvc-section-title"><span class="kr-dot"></span>' + escapeHtml(I18N.recent) + '</h3>' +
                '<div class="kvc-comment-list">' + recentHtml + '</div>' +
              '</div>'
            ) : '') +
            (topPostsHtml ? (
              '<div class="kr-body kvc-identity-card">' +
                '<h3 class="kvc-section-title"><span class="kr-dot"></span>' + escapeHtml(I18N.top_posts) + '</h3>' +
                '<div class="kvc-top-posts">' + topPostsHtml + '</div>' +
              '</div>'
            ) : '') +
            '<div class="kr-body kvc-identity-card">' +
              '<h3 class="kvc-section-title"><span class="kr-dot"></span>' + escapeHtml(I18N.activity) + '</h3>' +
              heatHtml +
            '</div>';
        bindHeatmapTooltip();
    }

    function bindHeatmapTooltip() {
        var grid = root.querySelector('.kvc-heat-grid');
        if (!grid) return;
        var tip = document.querySelector('.kvc-heat-tooltip');
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'kvc-heat-tooltip';
            document.body.appendChild(tip);
        }
        var tplHas = I18N.heat_tooltip || '%date%：%count% 条评论';
        var tplNone = I18N.heat_tooltip_empty || '%date%：无活跃';
        grid.addEventListener('mouseover', function (e) {
            var c = e.target.closest('.kvc-heat-cell');
            if (!c || !grid.contains(c)) return;
            var date = c.getAttribute('data-date');
            if (!date) return;
            var count = parseInt(c.getAttribute('data-count') || '0', 10);
            tip.textContent = (count > 0 ? tplHas : tplNone).replace('%date%', date).replace('%count%', count);
            tip.style.opacity = '1';
        });
        grid.addEventListener('mousemove', function (e) {
            if (tip.style.opacity !== '1') return;
            tip.style.top = (e.pageY + 12) + 'px';
            tip.style.left = (e.pageX + 12) + 'px';
        });
        grid.addEventListener('mouseleave', function () {
            tip.style.opacity = '0';
        });
    }

    function buildHeatmap(map) {
        var today = new Date();
        var start = new Date(today);
        start.setDate(start.getDate() - 364);
        start.setDate(start.getDate() - start.getDay());
        var maxCount = 1;
        for (var k in map) if (map.hasOwnProperty(k)) if (map[k] > maxCount) maxCount = map[k];

        var weeks = 53;
        var weekdays = (I18N.weekdays && I18N.weekdays.length === 7) ? I18N.weekdays : ['日','一','二','三','四','五','六'];
        var months = (I18N.months && I18N.months.length === 12) ? I18N.months : ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];
        var CS = 12, CG = 3; // 单元 12px + 间距 3px（与 CSS 保持一致）

        // 星期列：与 kph 一致，只显示奇数索引（一/三/五）
        var wdHtml = '';
        for (var wi = 0; wi < 7; wi++) {
            wdHtml += '<div class="kvc-heat-weekday">' + escapeHtml(wi % 2 === 1 ? weekdays[wi] : '') + '</div>';
        }

        // 月份标签：扫描每列首个日期，遇到新月份就在对应偏移打一个标签
        var monthLabels = [];
        var lastMonth = -1;
        var cells = '';
        for (var w = 0; w < weeks; w++) {
            var colFirstDate = null;
            for (var d = 0; d < 7; d++) {
                var dt = new Date(start);
                dt.setDate(dt.getDate() + w * 7 + d);
                if (colFirstDate === null) colFirstDate = dt;
                if (dt > today) { cells += '<div class="kvc-heat-cell is-blank"></div>'; continue; }
                var key = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
                var v = map[key] || 0;
                var lvl = 0;
                if (v > 0) {
                    var r = v / maxCount;
                    lvl = r >= 0.75 ? 4 : r >= 0.5 ? 3 : r >= 0.25 ? 2 : 1;
                }
                cells += '<div class="kvc-heat-cell level-' + lvl + '" data-date="' + key + '" data-count="' + v + '"></div>';
            }
            var mo = colFirstDate.getMonth();
            if (mo !== lastMonth) {
                monthLabels.push('<div class="kvc-heat-month" style="left:' + (w * (CS + CG) + CS / 2) + 'px;">' + escapeHtml(months[mo]) + '</div>');
                lastMonth = mo;
            }
        }

        var monthsWidth = weeks * (CS + CG);
        var monthsHtml = '<div class="kvc-heat-months" style="width:' + monthsWidth + 'px;">' + monthLabels.join('') + '</div>';

        var legend =
            '<div class="kvc-heat-legend">' +
              '<span>' + escapeHtml(I18N.less) + '</span>' +
              '<span class="kvc-heat-cell level-0"></span>' +
              '<span class="kvc-heat-cell level-1"></span>' +
              '<span class="kvc-heat-cell level-2"></span>' +
              '<span class="kvc-heat-cell level-3"></span>' +
              '<span class="kvc-heat-cell level-4"></span>' +
              '<span>' + escapeHtml(I18N.more) + '</span>' +
            '</div>';

        return '<div class="kvc-heat">' +
            '<div class="kvc-heat-graph">' +
              '<div class="kvc-heat-weekdays">' + wdHtml + '</div>' +
              '<div class="kvc-heat-main">' +
                monthsHtml +
                '<div class="kvc-heat-grid">' + cells + '</div>' +
              '</div>' +
            '</div>' +
            legend +
        '</div>';
    }

    /* ---------- 主流程 ----------
     * 三种入口：
     *   1. URL 带 ?vc=<md5>：查看某人的档案（评论徽章跳转过来）
     *   2. 无参数 + 有 cookie：查看自己的档案
     *   3. 无参数 + 无 cookie：显示引导态
     */
    var urlToken = (function () {
        // AES 令牌为 URL-safe base64（含 A-Za-z0-9_-），长度可变
        var m = window.location.search.match(/[?&]vc=([A-Za-z0-9_-]+)/);
        return m ? m[1] : '';
    })();

    var body;
    if (urlToken) {
        body = { token: urlToken };
    } else {
        var email = readEmailFromCookie();
        if (!email) { renderEmpty(); return; }
        body = { email: email };
    }

    fetch(KratosVC.restUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': KratosVC.nonce || ''
        },
        body: JSON.stringify(body)
    })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (!data || data.found === false) { renderNotFound(); return; }
            renderData(data);
        })
        .catch(function () { renderError(); });
})();
