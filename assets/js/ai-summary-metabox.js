(function ($) {
    'use strict';
    if (!window.KratosAISummary) return;
    var K = window.KratosAISummary;

    function $box() { return $('.kratos-ai-sum-box'); }

    // 文本注入：消息可能来自 provider，不能当 HTML 拼
    function setStatus(msg, color) {
        $box().find('.kratos-ai-sum-status')
            .empty()
            .append($('<span>').css('color', color || '#666').text(msg));
    }

    // 新文章正文还没落库，取编辑器现场内容随请求带上
    function editorContent() {
        try {
            if (window.wp && wp.data && wp.data.select('core/editor')) {
                var c = wp.data.select('core/editor').getEditedPostContent();
                if (c) return c;
            }
        } catch (e) {}
        if (window.tinymce) {
            var ed = window.tinymce.get('content');
            if (ed && !ed.isHidden()) return ed.getContent();
        }
        var $c = $('#content');
        return $c.length ? String($c.val() || '') : '';
    }

    function payload() {
        var $b = $box();
        return {
            post_id: parseInt($b.data('post'), 10),
            html: $b.find('.kratos-ai-sum-html').val(),
            style: $b.find('select[name=kratos_ai_sum_style]').val(),
            sync_excerpt: $b.find('input[name=kratos_ai_sum_sync_excerpt]').prop('checked') ? 1 : 0
        };
    }

    function post(route, data, $btn, onDone) {
        $btn.prop('disabled', true);
        return $.ajax({
            url: K.restRoot + route,
            method: 'POST',
            headers: { 'X-WP-Nonce': K.nonce, 'Content-Type': 'application/json' },
            data: JSON.stringify(data)
        }).done(function (r) {
            if (r && r.ok) {
                onDone(r);
            } else {
                setStatus(K.i18n.error + ': ' + ((r && r.message) || ''), '#b23');
            }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || xhr.statusText || K.i18n.error;
            setStatus(K.i18n.error + ': ' + msg, '#b23');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    }

    $(document).on('click', '.kratos-ai-sum-gen', function (e) {
        e.preventDefault();
        var d = payload();
        setStatus(K.i18n.generating, '#666');
        post('summary/generate', { post_id: d.post_id, style: d.style, content: editorContent() }, $(this), function (r) {
            $box().find('.kratos-ai-sum-html').val(r.html);
            setStatus('✓ ' + (r.state || 'fresh'), '#2b7a2b');
        });
    });

    $(document).on('click', '.kratos-ai-sum-save', function (e) {
        e.preventDefault();
        post('summary/save', payload(), $(this), function (r) {
            $box().find('.kratos-ai-sum-html').val(r.html);
            setStatus(K.i18n.saved, '#2b7a2b');
        });
    });

    $(document).on('click', '.kratos-ai-sum-clear', function (e) {
        e.preventDefault();
        if (!window.confirm(K.i18n.confirmClear)) return;
        var d = payload();
        d.html = '';
        post('summary/save', d, $(this), function () {
            $box().find('.kratos-ai-sum-html').val('');
            setStatus(K.i18n.cleared, '#666');
        });
    });
})(jQuery);
