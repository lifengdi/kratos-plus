/**
 * Gutenberg 代码块语言下拉。
 *  - 在 core/code 区块的"区块"设置侧边栏注入一个 SelectControl；
 *  - 选择后把 language-<lang> 写入 className 属性（保存为 <code class="language-xxx">）；
 *  - 同时同步到 <pre>，与前台 Prism / hljs / highlight.php 三引擎兼容。
 *
 * 数据来自全局 window.kratosCodehlLangs（由 PHP wp_localize 注入）。
 */
( function ( wp ) {
    if ( ! wp ) { console.warn( '[kratos-codehl] window.wp not available' ); return; }
    var missing = [];
    [ 'hooks', 'element', 'compose', 'blockEditor', 'components', 'blocks' ].forEach( function ( ns ) {
        if ( ! wp[ ns ] ) missing.push( ns );
    } );
    if ( missing.length ) {
        console.warn( '[kratos-codehl] missing wp namespaces:', missing );
        return;
    }

    var addFilter = wp.hooks.addFilter;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var Fragment = wp.element.Fragment;
    var createElement = wp.element.createElement;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;

    var LANGS = ( window.kratosCodehlLangs && window.kratosCodehlLangs.length )
        ? window.kratosCodehlLangs
        : [
            { value: '', label: '— 自动识别 —' },
            { value: 'plaintext', label: '纯文本' },
            { value: 'html', label: 'HTML' },
            { value: 'javascript', label: 'JavaScript' },
            { value: 'typescript', label: 'TypeScript' },
            { value: 'css', label: 'CSS' },
            { value: 'php', label: 'PHP' },
            { value: 'python', label: 'Python' },
            { value: 'bash', label: 'Bash / Shell' },
            { value: 'json', label: 'JSON' },
            { value: 'yaml', label: 'YAML' },
            { value: 'sql', label: 'SQL' },
            { value: 'go', label: 'Go' },
            { value: 'rust', label: 'Rust' },
        ];

    /**
     * 从 className 字符串里抽出 language-xxx；找不到返回空串。
     */
    function pickLang( className ) {
        if ( ! className ) return '';
        var m = String( className ).match( /\blanguage-([\w+#-]+)\b/ );
        return m ? m[ 1 ] : '';
    }

    /**
     * 用新语言替换/追加到 className。
     *  - lang 空 → 移除已存在的 language-xxx 项；
     *  - 否则 → 替换或追加 language-xxx。
     */
    function setLang( className, lang ) {
        var stripped = String( className || '' ).replace( /\s*\blanguage-[\w+#-]+\b/g, '' ).trim();
        if ( ! lang ) return stripped;
        return ( stripped + ' language-' + lang ).trim();
    }

    var withLanguageControl = createHigherOrderComponent( function ( BlockEdit ) {
        return function ( props ) {
            if ( props.name !== 'core/code' ) {
                return createElement( BlockEdit, props );
            }
            var lang = pickLang( props.attributes && props.attributes.className );

            return createElement(
                Fragment,
                null,
                createElement( BlockEdit, props ),
                createElement(
                    InspectorControls,
                    null,
                    createElement(
                        PanelBody,
                        { title: '代码语言', initialOpen: true },
                        createElement( SelectControl, {
                            label: '语法高亮语言',
                            help: '选择代码块的语言；前台按 language-xxx class 染色。',
                            value: lang,
                            options: LANGS,
                            onChange: function ( newLang ) {
                                props.setAttributes( {
                                    className: setLang( props.attributes && props.attributes.className, newLang ),
                                } );
                            },
                        } )
                    )
                )
            );
        };
    }, 'kratosWithLanguageControl' );

    addFilter(
        'editor.BlockEdit',
        'kratos/codehl-language-control',
        withLanguageControl
    );

    /**
     * 改写 core/code 的 raw 转换器：保留 <pre><code class="language-xxx"> 中的语言信息。
     *
     * Gutenberg 粘贴 Markdown 时由 marked/showdown 把 ```html 围栏转成
     *   <pre><code class="html language-html">...</code></pre>
     * 但 core/code 默认 schema 在 <code> 上没声明 class 属性，DOM cleaner 把 class 剥掉了。
     *
     * 注意：blocks.registerBlockType filter 注册时机晚于 core block 注册，filter 不会回作用于已注册块。
     * 所以这里直接 mutate 内存里 core/code 块的 transforms.from 数组。
     */
    function patchCoreCodeTransforms() {
        var blockType = wp.blocks.getBlockType && wp.blocks.getBlockType( 'core/code' );
        if ( ! blockType || ! blockType.transforms || ! Array.isArray( blockType.transforms.from ) ) {
            return false;
        }
        if ( blockType.__kratosCodehlPatched ) {
            return true;
        }
        var newSchema = function () {
            return {
                pre: {
                    attributes: [ 'class' ],
                    children: {
                        code: {
                            attributes: [ 'class' ],
                            children: { '#text': {}, br: {} },
                        },
                    },
                },
            };
        };
        var newTransform = function ( node ) {
            var codeEl = node.querySelector( 'code' );
            var content = codeEl ? codeEl.innerHTML : '';
            var combined = ( codeEl ? ( codeEl.getAttribute( 'class' ) || '' ) : '' )
                + ' '
                + ( node.getAttribute( 'class' ) || '' );
            var lm = combined.match( /\b(?:language|lang)-([\w+#-]+)/i );
            var attrs = { content: content };
            if ( lm ) {
                attrs.className = 'language-' + lm[ 1 ].toLowerCase();
            }
            return wp.blocks.createBlock( 'core/code', attrs );
        };
        var patched = false;
        blockType.transforms.from.forEach( function ( t ) {
            if ( t.type === 'raw' ) {
                t.schema = newSchema;
                t.transform = newTransform;
                patched = true;
            }
        } );
        if ( patched ) {
            blockType.__kratosCodehlPatched = true;
        }
        return patched;
    }

    // core/code 通常在 domReady 之前已注册，但保险起见做两次：立即一次 + domReady 一次
    if ( ! patchCoreCodeTransforms() && wp.domReady ) {
        wp.domReady( patchCoreCodeTransforms );
    }

    /**
     * 包装 wp.blocks.pasteHandler：粘贴 markdown 时，先把 ```lang 围栏替换成 raw HTML
     * <pre class="language-lang"><code class="language-lang">…</code></pre>，
     * 让 showdown 把它当 raw HTML 块原样保留，从而越过下游清洗（class 一路保留到我们的
     * core/code transform.from.raw.transform，最终写入 className 属性）。
     *
     * 必须 wrap 而不是替换：否则会跳过 WP 的 markdown→区块标准转换流程。
     */
    function escapeHtml( s ) {
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' );
    }
    /**
     * 把 markdown 文本切成"结构化片段"。围栏块独立成块（保留语言名），
     * 围栏外按 ## 标题 / 段落极简处理，其它原样作为 paragraph 文本。
     * 调用方据此直接调 wp.blocks.createBlock —— 跳过 pasteHandler 的 schema 清洗，class 100% 保留。
     */
    function parseMarkdownParts( md ) {
        if ( typeof md !== 'string' ) return { parts: [], hasFence: false };
        var parts = [];
        var lastIndex = 0;
        var fenceRe = /(^|\n)```([\w+#.-]*)[ \t]*\r?\n([\s\S]*?)\r?\n```(?=\r?\n|$)/g;
        var hasFence = false;
        var m;
        while ( ( m = fenceRe.exec( md ) ) !== null ) {
            hasFence = true;
            var pre = md.slice( lastIndex, m.index + m[ 1 ].length );
            if ( pre ) appendMdText( parts, pre );
            var lang = ( m[ 2 ] || '' ).toLowerCase();
            parts.push( { type: 'fence', lang: lang, code: m[ 3 ] } );
            lastIndex = fenceRe.lastIndex;
        }
        if ( ! hasFence ) return { parts: [], hasFence: false };
        if ( lastIndex < md.length ) appendMdText( parts, md.slice( lastIndex ) );
        return { parts: parts, hasFence: true };
    }

    function appendMdText( parts, text ) {
        // 按空行拆段，再按 # 头识别
        text.split( /\n{2,}/ ).forEach( function ( para ) {
            para = para.replace( /^[ \t]*\n+/, '' ).replace( /[ \t]*\n+$/, '' );
            if ( ! para ) return;
            var hm = para.match( /^(#{1,6})[ \t]+(.+)$/ );
            if ( hm ) {
                parts.push( { type: 'heading', level: hm[ 1 ].length, text: hm[ 2 ] } );
            } else {
                // 单段落里允许 \n 软换行
                parts.push( { type: 'paragraph', text: para } );
            }
        } );
    }

    /**
     * 把 parts 转成 Gutenberg block 实例数组。
     * 关键：fence 部分用 createBlock('core/code', { content, className })，
     * className 直接以属性形式存进 block；保存出库时就是 <!-- wp:code {"className":"language-xxx"} -->。
     */
    function partsToBlocks( parts ) {
        var blocks = [];
        parts.forEach( function ( p ) {
            if ( p.type === 'fence' ) {
                var attrs = { content: escapeHtml( p.code ) };
                if ( p.lang ) attrs.className = 'language-' + p.lang;
                blocks.push( wp.blocks.createBlock( 'core/code', attrs ) );
            } else if ( p.type === 'heading' ) {
                blocks.push( wp.blocks.createBlock( 'core/heading', {
                    level: p.level,
                    content: escapeHtml( p.text ),
                } ) );
            } else if ( p.type === 'paragraph' ) {
                blocks.push( wp.blocks.createBlock( 'core/paragraph', {
                    content: escapeHtml( p.text ).replace( /\n/g, '<br>' ),
                } ) );
            }
        } );
        return blocks;
    }
    /**
     * 拦截 paste 事件本身：Gutenberg 内部 pasteHandler 用 webpack import，包装 wp.blocks.pasteHandler
     * 拦不到内部调用。改在 paste 事件的 capture 阶段读 clipboardData.plainText，命中围栏就：
     *   1) preventDefault；
     *   2) 把围栏行预转为 raw HTML <pre class="language-xxx"><code class="language-xxx">…</code></pre>；
     *   3) 走 wp.blocks.pasteHandler 自己生成 block 并 insert。
     * 这样 showdown / 内部 cleaner 都越过去了，class 一路保留到我们的 core/code transform。
     */
    function installPasteInterceptor( root ) {
        if ( ! root || root.__kratosCodehlPasteBound ) return;
        root.__kratosCodehlPasteBound = true;
        // 在 window 上 capture 阶段绑 —— 比 document 更靠前，能在 React 委托之前拿到事件
        var win = root.defaultView || window;
        var bind = function ( target ) {
            target.addEventListener( 'paste', onPaste, true );
            target.addEventListener( 'paste', onPaste, { capture: true, passive: false } );
        };
        bind( root );
        if ( win && win !== root ) bind( win );
        function onPaste( e ) {
            try {
                var dt = e.clipboardData;
                if ( ! dt ) return;
                var plain = dt.getData( 'text/plain' );
                var clipHtml = dt.getData( 'text/html' );
                // 仅在没有 HTML（纯 markdown 文本粘贴）且含围栏时介入；
                // 有 HTML 时让 Gutenberg 走正常路径，避免破坏富文本粘贴
                if ( clipHtml || ! plain ) return;
                var parsed = parseMarkdownParts( plain );
                if ( ! parsed.hasFence ) return;
                e.preventDefault();
                e.stopPropagation();
                var blocks = partsToBlocks( parsed.parts );
                if ( ! blocks.length ) return;
                var dispatch = wp.data && wp.data.dispatch && wp.data.dispatch( 'core/block-editor' );
                if ( ! dispatch || ! dispatch.insertBlocks ) return;
                dispatch.insertBlocks( blocks );
            } catch ( err ) {
                console.warn( '[kratos-codehl] paste interceptor failed', err );
            }
        }
    }

    function tryBindIframe( frame ) {
        try {
            var doc = frame.contentDocument || ( frame.contentWindow && frame.contentWindow.document );
            if ( doc && doc.readyState !== 'loading' ) {
                installPasteInterceptor( doc );
            } else if ( frame.contentWindow ) {
                frame.addEventListener( 'load', function () { tryBindIframe( frame ); }, { once: true } );
            }
        } catch ( e ) { /* cross-origin iframe，忽略 */ }
    }
    function bindPasteInterceptors() {
        installPasteInterceptor( document );
        document.querySelectorAll( 'iframe' ).forEach( tryBindIframe );
    }
    bindPasteInterceptors();
    if ( wp.domReady ) wp.domReady( bindPasteInterceptors );

    /**
     * 直接给 contenteditable 元素绑 paste 监听 —— 这是 React 委托链的最里层，
     * 不管 React 在 root/document/window 上怎么拦截，都先于它触发。
     */
    function bindEditable( el ) {
        if ( ! el || el.__kratosCodehlPasteBound ) return;
        el.__kratosCodehlPasteBound = true;
        el.addEventListener( 'paste', function ( e ) {
            try {
                var dt = e.clipboardData;
                if ( ! dt ) return;
                var plain = dt.getData( 'text/plain' );
                var clipHtml = dt.getData( 'text/html' );
                if ( clipHtml || ! plain ) return;
                var parsed = parseMarkdownParts( plain );
                if ( ! parsed.hasFence ) return;
                e.preventDefault();
                e.stopPropagation();
                var blocks = partsToBlocks( parsed.parts );
                if ( ! blocks.length ) return;
                var dispatch = wp.data && wp.data.dispatch && wp.data.dispatch( 'core/block-editor' );
                if ( ! dispatch || ! dispatch.insertBlocks ) return;
                dispatch.insertBlocks( blocks );
            } catch ( err ) {
                console.warn( '[kratos-codehl] editable paste failed', err );
            }
        }, true );
    }
    function bindEditablesIn( root ) {
        if ( ! root || ! root.querySelectorAll ) return;
        root.querySelectorAll( '[contenteditable="true"]' ).forEach( bindEditable );
    }

    // 同时绑 iframe 与 contenteditable；用 MutationObserver 持续监视
    function watchSubtree( rootDoc ) {
        if ( ! rootDoc || ! window.MutationObserver || rootDoc.__kratosCodehlObserved ) return;
        rootDoc.__kratosCodehlObserved = true;
        bindEditablesIn( rootDoc );
        var mo = new MutationObserver( function ( mutations ) {
            for ( var i = 0; i < mutations.length; i++ ) {
                var added = mutations[ i ].addedNodes;
                for ( var j = 0; j < added.length; j++ ) {
                    var n = added[ j ];
                    if ( ! n || n.nodeType !== 1 ) continue;
                    if ( n.tagName === 'IFRAME' ) {
                        tryBindIframe( n );
                    } else if ( n.matches && n.matches( '[contenteditable="true"]' ) ) {
                        bindEditable( n );
                    } else if ( n.querySelectorAll ) {
                        n.querySelectorAll( 'iframe' ).forEach( tryBindIframe );
                        bindEditablesIn( n );
                    }
                }
            }
        } );
        mo.observe( rootDoc.body || rootDoc.documentElement, { childList: true, subtree: true } );
    }

    watchSubtree( document );
    // iframe 加载后也对其内 doc 启动 observer
    var origTryBindIframe = tryBindIframe;
    tryBindIframe = function ( frame ) {
        origTryBindIframe( frame );
        try {
            var doc = frame.contentDocument || ( frame.contentWindow && frame.contentWindow.document );
            if ( doc ) watchSubtree( doc );
        } catch ( e ) {}
    };
    // 已存在 iframe 也走一遍新版（绑 observer）
    document.querySelectorAll( 'iframe' ).forEach( tryBindIframe );
} )( window.wp );
