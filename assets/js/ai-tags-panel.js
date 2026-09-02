(function ($) {
    'use strict';
    if (!window.KratosAITags) return;
    var K = window.KratosAITags;
    var lastSource = '';

    function $box() { return $('.kratos-ai-tags-box'); }

    /**
     * 把服务端已写入的 post_tag 立即同步进编辑器内部状态：
     *  - Gutenberg：wp.data.dispatch('core/editor').editPost({tags: [id...]})
     *  - Classic：直接把 name 追加到 #new-tag-post_tag 输入框，调 window.tagBox.flushTags
     * 不同步的话，编辑器下一次「更新」会用旧的 tags 数组覆盖 DB，把 AI 标签冲掉。
     */
    function syncEditor(appliedTerms, allTagIds) {
        // Gutenberg (block editor)
        if (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) {
            try {
                var cur = wp.data.select('core/editor').getEditedPostAttribute('tags') || [];
                var next = Array.isArray(cur) ? cur.slice() : [];
                (allTagIds || []).forEach(function (id) {
                    if (next.indexOf(id) === -1) next.push(id);
                });
                wp.data.dispatch('core/editor').editPost({ tags: next });
                // 触发一次自动保存草稿以确保 REST 层的持久状态与 UI 一致（可选）
                return;
            } catch (e) {}
        }
        // Classic editor tag box
        var $tagsDiv = $('#tagsdiv-post_tag');
        var $newInput = $tagsDiv.find('#new-tag-post_tag, input.newtag');
        if ($tagsDiv.length && $newInput.length && window.tagBox && typeof window.tagBox.flushTags === 'function') {
            var names = (appliedTerms || []).map(function (t) { return t.name; }).filter(Boolean);
            if (!names.length) return;
            $newInput.val(names.join(', '));
            window.tagBox.userAction = 'add';
            window.tagBox.flushTags($tagsDiv, false, 1);
        }
    }

    function setStatus(msg, color) {
        $box().find('.kratos-ai-tags-status').text(msg).css('color', color || '#666');
    }

    function renderList(tags, canCreate) {
        var $list = $box().find('.kratos-ai-tags-list').empty();
        if (!tags.length) {
            $list.html('<p style="color:#999;">' + K.i18n.noResult + '</p>');
            return;
        }
        tags.forEach(function (t, i) {
            var badge = t.is_new
                ? '<span style="display:inline-block;padding:1px 6px;font-size:11px;background:#ffedd5;color:#9a3412;border-radius:3px;margin-left:6px;">' + K.i18n.newTag + '</span>'
                : '<span style="display:inline-block;padding:1px 6px;font-size:11px;background:#dcfce7;color:#166534;border-radius:3px;margin-left:6px;">' + K.i18n.existing + '</span>';
            var disabledNote = (t.is_new && !canCreate)
                ? '<span style="color:#888;font-size:11px;margin-left:6px;">' + K.i18n.noCreate + '</span>'
                : '';
            var row = $('<div class="kratos-ai-tag-row" style="padding:4px 0;border-bottom:1px dashed #eee;"></div>');
            row.append(
                '<label style="display:flex;align-items:flex-start;gap:6px;cursor:pointer;">' +
                    '<input type="checkbox" class="kratos-ai-tag-pick" data-i="' + i + '"' + (t.is_new && !canCreate ? '' : ' checked') + '>' +
                    '<div style="flex:1;">' +
                        '<div><strong>' + $('<div>').text(t.name).html() + '</strong>' +
                        '<span style="color:#888;font-size:11px;">/' + $('<div>').text(t.slug).html() + '</span>' +
                        badge + disabledNote + '</div>' +
                        (t.seo_title ? '<div style="font-size:11px;color:#555;">SEO: ' + $('<div>').text(t.seo_title).html() + '</div>' : '') +
                        (t.description ? '<div style="font-size:11px;color:#666;">' + $('<div>').text(t.description).html() + '</div>' : '') +
                    '</div>' +
                '</label>'
            );
            row.data('tag', t);
            $list.append(row);
        });
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

    $(document).on('click', '.kratos-ai-tags-gen', function (e) {
        e.preventDefault();
        var $b = $box();
        var post_id = parseInt($b.data('post'), 10);
        var canCreate = parseInt($b.data('can-create'), 10) === 1;
        var $btn = $(this).prop('disabled', true);
        setStatus(K.i18n.generating);
        $.ajax({
            url: K.restRoot + 'tags/suggest',
            method: 'POST',
            headers: { 'X-WP-Nonce': K.nonce, 'Content-Type': 'application/json' },
            data: JSON.stringify({ post_id: post_id, content: editorContent() })
        }).done(function (r) {
            if (r && r.ok) {
                lastSource = r.source || '';
                renderList(r.tags || [], !!r.can_create);
                setStatus('✓', '#2b7a2b');
            } else {
                setStatus(K.i18n.error + ': ' + (r && r.message ? r.message : ''), '#b23');
            }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || xhr.statusText;
            setStatus(K.i18n.error + ': ' + msg, '#b23');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.kratos-ai-tags-apply', function (e) {
        e.preventDefault();
        var $b = $box();
        var post_id = parseInt($b.data('post'), 10);
        var picked = [];
        $b.find('.kratos-ai-tag-row').each(function () {
            var $cb = $(this).find('.kratos-ai-tag-pick');
            if (!$cb.prop('checked')) return;
            picked.push($(this).data('tag'));
        });
        if (!picked.length) {
            setStatus(K.i18n.error + ': 未勾选', '#b23');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: K.restRoot + 'tags/apply',
            method: 'POST',
            headers: { 'X-WP-Nonce': K.nonce, 'Content-Type': 'application/json' },
            data: JSON.stringify({ post_id: post_id, tags: picked, source: lastSource })
        }).done(function (r) {
            if (r && r.ok) {
                syncEditor(r.applied_terms || [], r.all_tag_ids || []);
                setStatus(K.i18n.applied + ': ' + r.applied + (r.drafted ? ' / draft ' + r.drafted : ''), '#2b7a2b');
            } else {
                setStatus(K.i18n.error, '#b23');
            }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || xhr.statusText;
            setStatus(K.i18n.error + ': ' + msg, '#b23');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
