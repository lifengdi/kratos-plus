/*
 * Kratos-plus 特色首页 —— 分类专区 tab 切换
 *
 * 同一页面可能存在多个分类专区实例（短码可重复插入），故所有查询都限定在
 * 最近的 .khf-cat 容器内，避免互相串台。无 jQuery 依赖。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */
(function () {
    'use strict';

    function activate(box, tab) {
        var panelId = tab.getAttribute('data-panel');
        if (!panelId) {
            return;
        }
        box.querySelectorAll('.khf-tab').forEach(function (t) {
            t.classList.remove('is-active');
            t.setAttribute('aria-selected', 'false');
        });
        box.querySelectorAll('.khf-cat-panel').forEach(function (p) {
            p.classList.remove('is-active');
        });
        tab.classList.add('is-active');
        tab.setAttribute('aria-selected', 'true');
        var panel = box.querySelector('[data-panel-id="' + panelId + '"]');
        if (panel) {
            panel.classList.add('is-active');
        }
    }

    function bind() {
        document.querySelectorAll('.kratos-home .khf-cat').forEach(function (box) {
            box.querySelectorAll('.khf-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    activate(box, tab);
                });
                // 键盘左右方向键在 tab 之间移动（tablist 语义）
                tab.addEventListener('keydown', function (e) {
                    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
                        return;
                    }
                    var tabs = Array.prototype.slice.call(box.querySelectorAll('.khf-tab'));
                    var i = tabs.indexOf(tab);
                    var next = tabs[e.key === 'ArrowRight' ? i + 1 : i - 1];
                    if (next) {
                        e.preventDefault();
                        next.focus();
                        activate(box, next);
                    }
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
