(function () {
    'use strict';
    // 尊重用户偏好：减少动效时直接跳过
    try {
        if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    } catch (e) {}

    function type(root) {
        if (root.dataset.kaiTyped === '1') return;
        root.dataset.kaiTyped = '1';

        // 收集所有文本节点：保留原 DOM 结构（含 <p><ul><li><strong>）
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var nodes = [], n;
        while ((n = walker.nextNode())) {
            if (!n.nodeValue) continue;
            // Array.from 按码点切分，兼容 emoji / 中文
            nodes.push({ node: n, chars: Array.from(n.nodeValue) });
            n.nodeValue = '';
        }
        if (!nodes.length) return;

        root.classList.add('is-typing');
        var i = 0, j = 0;
        var speed = parseInt(root.dataset.kaiSpeed, 10) || 22; // ms/char
        var newlineDelay = 90;                                 // 文本节点之间轻微停顿

        function tick() {
            if (i >= nodes.length) {
                root.classList.remove('is-typing');
                root.classList.add('is-typed');
                return;
            }
            var cur = nodes[i];
            if (j < cur.chars.length) {
                cur.node.nodeValue += cur.chars[j++];
                setTimeout(tick, speed);
            } else {
                i++; j = 0;
                setTimeout(tick, newlineDelay);
            }
        }
        setTimeout(tick, 120);
    }

    function boot() {
        var list = document.querySelectorAll('.kratos-ai-summary .kratos-ai-summary-body');
        if (!list.length) return;

        // 使用 IntersectionObserver：卡片进入视口时才开始，避免深锚下没看到就"打"完
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (e.isIntersecting) {
                        type(e.target);
                        io.unobserve(e.target);
                    }
                });
            }, { threshold: 0.15 });
            list.forEach(function (el) { io.observe(el); });
            // 兜底：卡片若始终不进视口（藏在折叠容器 / display:none 里），
            // 8s 后直接整段显示，避免文本被清空后永远不填回来
            setTimeout(function () {
                list.forEach(function (el) {
                    if (el.dataset.kaiTyped !== '1') { io.unobserve(el); reveal(el); }
                });
            }, 8000);
        } else {
            list.forEach(type);
        }
    }

    // 不走打字动画，直接标记完成（文本此时还没被清空，原样留着即可）
    function reveal(root) {
        root.dataset.kaiTyped = '1';
        root.classList.add('is-typed');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
