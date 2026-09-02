/**
 * Kratos-plus 短码区块 - Gutenberg 注册脚本（无构建步骤，浏览器端 IIFE）。
 *
 * 与 inc/theme-gutenberg-blocks.php 的 kratos_blocks_defs() 一一对应。
 *   wrap   -> RichText 编辑 content
 *   card   -> PlainText 编辑 title + RichText 编辑 content
 *   value  -> TextControl 编辑单字段
 *
 * 全部为动态块（save 返回 null），渲染由 PHP render_callback 完成。
 */
( function ( wp ) {
    if ( ! wp || ! wp.blocks ) return;

    var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var registerBlockType = wp.blocks.registerBlockType;
    var RichText = wp.blockEditor.RichText;
    var useBlockProps = wp.blockEditor.useBlockProps || function () { return {}; };
    var TextControl = wp.components.TextControl;
    var PlainText = wp.blockEditor.PlainText;

    var CATEGORY = 'kratos-blocks';
    // 与 PHP 块名对应；icon 用 dashicons 名（Gutenberg 内置图标）
    var DEFS = [
        // wrap
        { name: 'h2title', kind: 'wrap', title: '特色标题',  icon: 'heading',           keywords: [ 'h2', '标题' ] },
        { name: 'success', kind: 'wrap', title: '绿色背景栏', icon: 'yes-alt',           keywords: [ 'success', '提示' ], color: '#28a745' },
        { name: 'info',    kind: 'wrap', title: '蓝色背景栏', icon: 'info',              keywords: [ 'info', '提示' ],   color: '#17a2b8' },
        { name: 'warning', kind: 'wrap', title: '黄色背景栏', icon: 'warning',           keywords: [ 'warning', '警告' ], color: '#ffc107' },
        { name: 'danger',  kind: 'wrap', title: '红色背景栏', icon: 'dismiss',           keywords: [ 'danger', '错误' ], color: '#dc3545' },
        { name: 'kbd',     kind: 'wrap', title: '键盘文本',   icon: 'editor-code',       keywords: [ 'kbd', '按键' ] },
        { name: 'mark',    kind: 'wrap', title: '内容标记',   icon: 'edit',              keywords: [ 'mark', '高亮' ] },
        { name: 'reply',   kind: 'wrap', title: '回复可见',   icon: 'hidden',            keywords: [ 'reply', '隐藏' ] },

        // card
        { name: 'successbox', kind: 'card', title: '绿色面板', icon: 'yes',           color: '#28a745' },
        { name: 'infobox',    kind: 'card', title: '蓝色面板', icon: 'info-outline',  color: '#17a2b8' },
        { name: 'warningbox', kind: 'card', title: '黄色面板', icon: 'flag',          color: '#ffc107' },
        { name: 'dangerbox',  kind: 'card', title: '红色面板', icon: 'dismiss',       color: '#dc3545' },
        { name: 'accordion',  kind: 'card', title: '折叠面板', icon: 'arrow-down-alt2' },

        // value
        { name: 'striped',  kind: 'value', title: '进度条',     icon: 'chart-bar',  attr: 'percent', placeholder: '0-100', help: '百分比数字（不带 %）' },
        { name: 'bdbtn',    kind: 'value', title: '下载按钮',   icon: 'download',   attr: 'url',     placeholder: 'https://…', help: '下载链接 URL' },
        { name: 'music',    kind: 'value', title: '网易云音乐', icon: 'format-audio', attr: 'songId', placeholder: '网易云歌曲 ID', help: '歌曲数字 ID' },
        { name: 'vqq',      kind: 'value', title: '腾讯视频',   icon: 'video-alt3', attr: 'vid',     placeholder: 'vid', help: '视频 vid' },
        { name: 'youtube',  kind: 'value', title: 'YouTube',    icon: 'video-alt2', attr: 'videoId', placeholder: 'YouTube ID', help: 'YouTube 视频 ID' },
        { name: 'bilibili', kind: 'value', title: '哔哩哔哩',   icon: 'video-alt',  attr: 'bvid',    placeholder: 'BVxxxxxx', help: 'B 站 BV 号' }
    ];

    DEFS.forEach( function ( def ) {
        var attrs = {};
        if ( def.kind === 'value' ) {
            attrs[ def.attr ] = { type: 'string', default: '' };
        } else {
            attrs.content = { type: 'string', default: '' };
            if ( def.kind === 'card' ) attrs.title = { type: 'string', default: '' };
        }

        registerBlockType( 'kratos/' + def.name, {
            apiVersion: 3,
            title: def.title,
            description: '[' + def.name + '] 短码',
            category: CATEGORY,
            icon: def.icon || 'shortcode',
            keywords: ( def.keywords || [] ).concat( [ def.name, 'kratos' ] ),
            attributes: attrs,
            // 动态块；服务端通过 render_callback 输出
            edit: function ( props ) {
                var a = props.attributes;
                var setAttr = props.setAttributes;
                var blockProps = useBlockProps( {
                    className: 'kratos-block kratos-block--' + def.name + ( def.kind === 'wrap' ? ' kratos-block--wrap' : '' ),
                    style: def.color ? { borderLeftColor: def.color } : undefined
                } );

                if ( def.kind === 'value' ) {
                    return el( 'div', blockProps,
                        el( TextControl, {
                            label: def.placeholder || def.title,
                            help: def.help || '',
                            value: a[ def.attr ] || '',
                            onChange: function ( v ) {
                                var n = {};
                                n[ def.attr ] = v;
                                setAttr( n );
                            }
                        } )
                    );
                }

                var children = [];

                if ( def.kind === 'card' ) {
                    // 标题栏：图标 + 输入框，flex 行布局保证 ::before 失效时图标仍可见
                    children.push(
                        el( 'div', {
                            key: 'title',
                            className: 'kratos-block__title'
                        },
                            el( 'span', {
                                className: 'kratos-block__icon',
                                'aria-hidden': 'true'
                            } ),
                            el( PlainText, {
                                className: 'kratos-block__title-input',
                                value: a.title || '',
                                placeholder: __( '标题内容', 'kratos' ),
                                onChange: function ( v ) { setAttr( { title: v } ); }
                            } )
                        )
                    );
                }

                var richText = el( RichText, {
                    key: 'content',
                    tagName: 'div',
                    className: 'kratos-block__content',
                    value: a.content || '',
                    // 限定可用格式 —— 避免在背景栏里嵌入图片/链接造成 shortcode 解析异常
                    allowedFormats: [ 'core/bold', 'core/italic', 'core/code', 'core/link' ],
                    placeholder: def.name === 'reply'
                        ? __( '此处内容回复后可见…', 'kratos' )
                        : __( '在此输入内容…', 'kratos' ),
                    onChange: function ( v ) { setAttr( { content: v } ); }
                } );

                // 背景栏（wrap 类且非纯文本类）：图标 + 内容 flex 横排
                var ALERT_KINDS = { success: 1, info: 1, warning: 1, danger: 1 };
                if ( def.kind === 'wrap' && ALERT_KINDS[ def.name ] ) {
                    children.push(
                        el( 'div', {
                            key: 'row',
                            className: 'kratos-block__row'
                        },
                            el( 'span', {
                                className: 'kratos-block__icon',
                                'aria-hidden': 'true'
                            } ),
                            richText
                        )
                    );
                } else {
                    children.push( richText );
                }

                return el( 'div', blockProps, children );
            },
            save: function () { return null; }
        } );
    } );

    /* -------------------------------------------------------------- */
    /* kratos/search —— 主题搜索小工具的 Gutenberg 入口                  */
    /* 与传统 widget_search 输出一致；编辑器内只显示一个简化预览 + 标题   */
    /* 设置项，渲染走 PHP render_callback                                */
    /* -------------------------------------------------------------- */
    registerBlockType( 'kratos/search', {
        apiVersion: 3,
        title: '搜索（Kratos-plus）',
        description: '主题自带样式的搜索小工具，与侧栏 Search widget 输出一致',
        category: 'widgets',
        icon: 'search',
        keywords: [ 'search', '搜索', 'kratos' ],
        attributes: {
            title: { type: 'string', default: '' }
        },
        edit: function ( props ) {
            var a = props.attributes;
            var setAttr = props.setAttributes;
            var blockProps = useBlockProps( {
                className: 'kratos-block kratos-block--search'
            } );
            return el( 'div', blockProps,
                el( TextControl, {
                    label: __( '标题（可选）', 'kratos' ),
                    help: __( '留空则不显示标题', 'kratos' ),
                    value: a.title || '',
                    onChange: function ( v ) { setAttr( { title: v } ); }
                } ),
                el( 'div', {
                    className: 'kratos-block__search-preview',
                    style: {
                        display: 'flex',
                        gap: '4px',
                        marginTop: '8px'
                    }
                },
                    el( 'input', {
                        type: 'text',
                        disabled: true,
                        placeholder: __( '搜点什么呢?', 'kratos' ),
                        style: {
                            flex: '1 1 auto',
                            padding: '6px 10px',
                            border: '1px solid #ced4da',
                            borderRadius: '4px',
                            background: '#fff'
                        }
                    } ),
                    el( 'button', {
                        type: 'button',
                        disabled: true,
                        style: {
                            padding: '6px 14px',
                            border: 0,
                            borderRadius: '4px',
                            background: '#336699',
                            color: '#fff',
                            cursor: 'default'
                        }
                    }, __( '搜索', 'kratos' ) )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
