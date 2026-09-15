/**
 * 评论增强：赞踩交互
 */
(function () {
    if (typeof window.KratosCommentEnhance === 'undefined') return;
    var cfg = window.KratosCommentEnhance;

    // 折叠切换
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.hc-fold-btn');
        if (!btn) return;
        e.preventDefault();
        var top = document.querySelector('[data-fold-id="' + btn.getAttribute('data-target') + '"]');
        if (top) top.classList.toggle('kc-fold-collapsed');
    });

    // 深层回复 AJAX 分页加载：服务端只输出 window.KratosDeepAnchors = [anchor_id, ...]，
    // 不列 comment_ids、不带计数。每个 anchor 的 ul.children 尾部挂两个独立按钮：
    //   .kc-deep-load     —— 加载下一页（每页 g_comment_flatten_page_size 条），服务端说 has_more=false 后按钮移除
    //   .kc-deep-collapse —— 首次加载完出现，独立切换已加载深层回复的显隐
    var i18nLoad     = cfg.i18n_deep_load     || '加载更多回复';
    var i18nLoading  = cfg.i18n_deep_loading  || '加载中…';
    var i18nCollapse = cfg.i18n_deep_collapse || '收起回复';
    var i18nExpand   = cfg.i18n_deep_expand   || '展开回复';
    // 同一 anchor id 可能在 DOM 里出现多次（置顶/热门与主列表同时渲染同一条评论）。
    // 每次出现都独立挂一份按钮/UL，共享分页状态（lastId、collapsed、fetching）。
    var deepGroups = {}; // anchor_id -> { anchorId, mirrors:[{ul,loadBtn,collapseBtn}], offset, collapsed, fetching }

    function ensureUlIn(host) {
        var ul = host.querySelector(':scope > ul.sub_children');
        if (!ul) {
            ul = document.createElement('ul');
            ul.className = 'sub_children';
            host.appendChild(ul);
        }
        return ul;
    }

    function makeCollapseBtn(g, m) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'kc-deep-collapse';
        b.textContent = g.collapsed ? i18nExpand : i18nCollapse;
        if (m.loadBtn && m.loadBtn.parentNode === m.ul) m.ul.insertBefore(b, m.loadBtn);
        else m.ul.appendChild(b);
        m.collapseBtn = b;
        b.addEventListener('click', function () {
            g.collapsed = !g.collapsed;
            g.mirrors.forEach(function (mm) {
                mm.ul.querySelectorAll(':scope > li.kratos-deep-reply').forEach(function (li) { li.hidden = g.collapsed; });
                if (mm.collapseBtn) mm.collapseBtn.textContent = g.collapsed ? i18nExpand : i18nCollapse;
                // 折叠态下隐藏所有镜像的「加载更多回复」，避免与「展开回复」并列造成语义冲突
                if (mm.loadBtn) mm.loadBtn.hidden = g.collapsed;
            });
        });
    }

    function ensureCollapseBtns(g) {
        g.mirrors.forEach(function (m) { if (!m.collapseBtn) makeCollapseBtn(g, m); });
    }

    function reorderCollapse(m) {
        // 收起按钮紧贴 loadBtn 之前（若 loadBtn 已被移除则追加末尾）
        if (!m.collapseBtn || m.collapseBtn.parentNode !== m.ul) return;
        if (m.loadBtn && m.loadBtn.parentNode === m.ul) m.ul.insertBefore(m.collapseBtn, m.loadBtn);
        else m.ul.appendChild(m.collapseBtn);
    }

    function fetchPage(g) {
        if (g.fetching) return;
        var active = g.mirrors.filter(function (m) { return m.loadBtn; });
        if (!active.length) return;
        g.fetching = true;
        active.forEach(function (m) { m.loadBtn.disabled = true; m._origTxt = m.loadBtn.textContent; m.loadBtn.textContent = i18nLoading; });
        var body = new URLSearchParams();
        body.append('action', 'kratos_load_deep_replies');
        body.append('nonce', cfg.flatten_nonce);
        body.append('anchor_id', g.anchorId);
        body.append('offset', g.offset || 0);
        fetch(cfg.ajax_url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success || !res.data) return;
            if (res.data.html) {
                g.mirrors.forEach(function (m) {
                    var tmp = document.createElement('ul');
                    tmp.innerHTML = res.data.html; // 每个镜像独立解析一份，避免共享同一批节点
                    Array.prototype.slice.call(tmp.children).forEach(function (li) {
                        var anchor = m.collapseBtn || m.loadBtn;
                        if (anchor && anchor.parentNode === m.ul) m.ul.insertBefore(li, anchor);
                        else m.ul.appendChild(li);
                    });
                });
                ensureCollapseBtns(g);
                g.mirrors.forEach(reorderCollapse);
            }
            if (typeof res.data.offset !== 'undefined') g.offset = parseInt(res.data.offset, 10) || g.offset;
            if (!res.data.has_more) {
                g.mirrors.forEach(function (m) {
                    if (m.loadBtn && m.loadBtn.parentNode) m.loadBtn.parentNode.removeChild(m.loadBtn);
                    m.loadBtn = null;
                });
            } else {
                active.forEach(function (m) { if (m.loadBtn) { m.loadBtn.textContent = m._origTxt; m.loadBtn.disabled = false; } });
            }
        })
        .catch(function () {
            active.forEach(function (m) { if (m.loadBtn) { m.loadBtn.textContent = m._origTxt; m.loadBtn.disabled = false; } });
        })
        .finally(function () { g.fetching = false; });
    }

    function makeMirror(g, host) {
        var ul = ensureUlIn(host);
        // 同一 host 已经挂过按钮（例：MutationObserver 二次触发），复用不重复挂
        for (var i = 0; i < g.mirrors.length; i++) if (g.mirrors[i].ul === ul) return g.mirrors[i];
        var loadBtn = document.createElement('button');
        loadBtn.type = 'button';
        loadBtn.className = 'kc-deep-load';
        loadBtn.textContent = i18nLoad;
        if (g.collapsed) loadBtn.hidden = true;
        ul.appendChild(loadBtn);
        var m = { ul: ul, loadBtn: loadBtn, collapseBtn: null };
        g.mirrors.push(m);
        loadBtn.addEventListener('click', function () { fetchPage(g); });
        return m;
    }

    function makeGroup(anchorId) {
        // anchor id 在 DOM 里可能重复（同一评论在置顶/热门 + 主列表各出现一次），
        // 用 querySelectorAll 抓全所有 host 并逐个挂镜像
        var hosts = document.querySelectorAll('[id="comment-' + anchorId + '"]');
        if (!hosts.length) return null;
        var g = deepGroups[anchorId];
        if (!g) {
            g = { anchorId: anchorId, mirrors: [], offset: 0, collapsed: false, fetching: false };
            deepGroups[anchorId] = g;
        }
        Array.prototype.forEach.call(hosts, function (h) { makeMirror(g, h); });
        return g;
    }

    (function setupDeepFlatten() {
        if (!cfg.flatten_on) return;
        var anchors = window.KratosDeepAnchors || [];
        anchors.forEach(function (anchorId) { makeGroup(anchorId); });

        // 兼容 AJAX 提交的新回复：新 <li> 挂 .kratos-deep-reply + data-flatten-anchor，
        // 搬到 anchor 的 ul.children 尾部（在按钮组之前），不改 offset，也不改分页状态
        var listRoot = document.querySelector('.comments .list');
        if (!listRoot || typeof MutationObserver === 'undefined') return;
        var mo = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes && m.addedNodes.forEach(function (node) {
                    if (!(node instanceof HTMLElement)) return;
                    var deepLis = node.matches && node.matches('li.kratos-deep-reply')
                        ? [node]
                        : Array.prototype.slice.call(node.querySelectorAll ? node.querySelectorAll('li.kratos-deep-reply') : []);
                    deepLis.forEach(function (li) {
                        var anchorId = li.getAttribute('data-flatten-anchor');
                        if (!anchorId) return;
                        var g = deepGroups[anchorId] || makeGroup(anchorId);
                        if (!g || !g.mirrors.length) return;
                        // 若这条 li 恰好落在某个镜像的 ul 内，视为已就位；其它镜像克隆一份补上
                        var placedIn = null;
                        for (var i = 0; i < g.mirrors.length; i++) if (li.parentNode === g.mirrors[i].ul) { placedIn = g.mirrors[i]; break; }
                        if (!placedIn) {
                            if (li.parentNode) li.parentNode.removeChild(li);
                            var first = g.mirrors[0];
                            var a0 = first.collapseBtn || first.loadBtn;
                            if (a0 && a0.parentNode === first.ul) first.ul.insertBefore(li, a0);
                            else first.ul.appendChild(li);
                            placedIn = first;
                        }
                        // 其余镜像克隆一份，位置同样贴在按钮之前
                        g.mirrors.forEach(function (m) {
                            if (m === placedIn) return;
                            var clone = li.cloneNode(true);
                            var a = m.collapseBtn || m.loadBtn;
                            if (a && a.parentNode === m.ul) m.ul.insertBefore(clone, a);
                            else m.ul.appendChild(clone);
                        });
                        ensureCollapseBtns(g);
                        g.mirrors.forEach(reorderCollapse);
                    });
                });
            });
        });
        mo.observe(listRoot, { childList: true, subtree: true });
    })();

    // 评论回复折叠（主评论列表 + 热门评论共用；按顶层评论下所有后代总数统计）
    (function setupCommentsFold() {
        var threshold = parseInt(cfg.reply_collapse || 0, 10);
        if (!threshold) return;

        var uid = 0;

        // 收集所有顶层评论：其父不是 .comment 的 li.comment
        var scopes = document.querySelectorAll('.comments .list, .hot-comments-list');
        scopes.forEach(function (scope) {
            var topLevel = Array.prototype.filter.call(
                scope.querySelectorAll('li.comment'),
                function (li) {
                    return !li.parentElement.closest('li.comment');
                }
            );

            topLevel.forEach(function (top) {
                // 顶层评论下的所有后代 .comment（DOM 顺序 = 时间正序，wp_list_comments 已保证）
                // 深层折叠已接管的 .kratos-deep-reply 排除掉，避免与「查看更多相关回复」双按钮
                var descendants = Array.prototype.filter.call(
                    top.querySelectorAll('li.comment'),
                    function (li) { return !li.classList.contains('kratos-deep-reply'); }
                );
                if (descendants.length <= threshold) return;

                var id = 'kc-fold-' + (++uid);
                top.classList.add('has-fold');
                top.setAttribute('data-fold-id', id);
                top.classList.add('kc-fold-collapsed');

                Array.prototype.forEach.call(descendants, function (li, i) {
                    if (i >= threshold) li.classList.add('hc-collapsed');
                });

                var more = descendants.length - threshold;
                var toggle = document.createElement('div');
                toggle.className = 'hc-fold-toggle';
                toggle.innerHTML = '<a href="javascript:;" class="hc-fold-btn" data-target="' + id + '">'
                    + '<span class="hc-fold-more">' + (cfg.i18n_more || '展开剩余 %d 条回复').replace('%d', more) + '</span>'
                    + '<span class="hc-fold-less">' + (cfg.i18n_less || '收起回复') + '</span>'
                    + '</a>';
                top.appendChild(toggle);
            });
        });
    })();

    document.addEventListener('click', function (e) {
        var target = e.target.closest && e.target.closest('.kc-like, .kc-dislike');
        if (!target) return;
        e.preventDefault();
        var wrap = target.closest('.kc-vote');
        if (!wrap || wrap.dataset.pending === '1') return;
        wrap.dataset.pending = '1';

        var cid  = wrap.getAttribute('data-cid');
        var type = target.classList.contains('kc-like') ? 'like' : 'dislike';

        var body = new URLSearchParams();
        body.append('action', 'kratos_comment_vote');
        body.append('nonce', cfg.nonce);
        body.append('comment_id', cid);
        body.append('type', type);

        fetch(cfg.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                var likeEl    = wrap.querySelector('.kc-like em');
                var dislikeEl = wrap.querySelector('.kc-dislike em');
                if (likeEl)    likeEl.textContent    = res.data.likes;
                if (dislikeEl) dislikeEl.textContent = res.data.dislikes;
                wrap.querySelector('.kc-like').classList.toggle('voted', res.data.current === 'like');
                wrap.querySelector('.kc-dislike').classList.toggle('voted', res.data.current === 'dislike');
            } else if (res && res.data && res.data.msg) {
                if (window.layer) layer.msg(res.data.msg); else alert(res.data.msg);
            }
        })
        .catch(function () {})
        .finally(function () { wrap.dataset.pending = '0'; });
    });
})();
