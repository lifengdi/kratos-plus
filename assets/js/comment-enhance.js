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
                var descendants = top.querySelectorAll('li.comment');
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
