/**
 * Kratos-plus 九宫格图组区块（kratos/nine-grid）。
 *
 * 动态块：attributes.ids 存附件 ID 数组，save 返回 null，前端由 PHP
 * kratos_nine_grid_render() 复用说说 kratos_media_render() 渲染。
 *
 * 编辑器 UI：
 *   - 空态：MediaPlaceholder，多选上传 / 从媒体库选择
 *   - 有内容：预览小九宫格 + 工具栏「编辑图片」重开媒体库 + 侧栏「清空」
 */
( function ( wp ) {
    if ( ! wp || ! wp.blocks ) return;

    var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;

    var registerBlockType = wp.blocks.registerBlockType;
    var blockEditor = wp.blockEditor || wp.editor;
    var MediaPlaceholder = blockEditor.MediaPlaceholder;
    var MediaUpload = blockEditor.MediaUpload;
    var MediaUploadCheck = blockEditor.MediaUploadCheck;
    var BlockControls = blockEditor.BlockControls;
    var InspectorControls = blockEditor.InspectorControls;
    var useBlockProps = blockEditor.useBlockProps || function () { return {}; };

    var components = wp.components;
    var ToolbarGroup = components.ToolbarGroup || components.Toolbar;
    var ToolbarButton = components.ToolbarButton;
    var Button = components.Button;
    var PanelBody = components.PanelBody;

    function pickThumb( media ) {
        if ( ! media ) return '';
        if ( media.sizes ) {
            if ( media.sizes.thumbnail && media.sizes.thumbnail.url ) return media.sizes.thumbnail.url;
            if ( media.sizes.medium && media.sizes.medium.url ) return media.sizes.medium.url;
        }
        return media.url || '';
    }

    registerBlockType( 'kratos/nine-grid', {
        apiVersion: 3,
        title: __( '九宫格图组', 'kratos' ),
        description: __( '以说说九宫格样式展示多张图片，同一篇文章可插入多个。', 'kratos' ),
        category: 'kratos-blocks',
        icon: 'grid-view',
        keywords: [ __( '九宫格', 'kratos' ), __( '相册', 'kratos' ), 'gallery' ],
        attributes: {
            ids: { type: 'array', default: [], items: { type: 'number' } },
            // 仅用于编辑器预览缓存（不入库序列化时也会存，无副作用）
            previews: { type: 'array', default: [] }
        },
        supports: { anchor: false, html: false },

        edit: function ( props ) {
            var attrs = props.attributes;
            var ids = attrs.ids || [];
            var previews = attrs.previews || [];
            var blockProps = useBlockProps( { className: 'kratos-nine-grid-editor' } );

            function onSelect( medias ) {
                var arr = Array.isArray( medias ) ? medias : [ medias ];
                var newIds = [];
                var newPreviews = [];
                arr.forEach( function ( m ) {
                    if ( m && m.id ) {
                        newIds.push( m.id );
                        newPreviews.push( pickThumb( m ) );
                    }
                } );
                props.setAttributes( { ids: newIds, previews: newPreviews } );
            }

            if ( ! ids.length ) {
                return el(
                    'div',
                    blockProps,
                    el( MediaPlaceholder, {
                        icon: 'grid-view',
                        labels: {
                            title: __( '九宫格图组', 'kratos' ),
                            instructions: __( '上传图片或从媒体库中选择，最多 9 张会完整显示，超过后自动折叠为 “+N” 灯箱。', 'kratos' )
                        },
                        accept: 'image/*',
                        allowedTypes: [ 'image' ],
                        multiple: 'add',
                        gallery: true,
                        onSelect: onSelect,
                        value: ids
                    } )
                );
            }

            var extra = ids.length > 9 ? ( ids.length - 9 ) : 0;
            var gridClass = 'kng-preview kng-preview-' + ( ids.length === 1 ? 1 : ids.length === 2 ? 2 : ids.length === 3 ? 3 : ids.length === 4 ? 4 : 9 );

            var cells = ids.slice( 0, 9 ).map( function ( id, i ) {
                var src = previews[ i ] || '';
                var isMore = extra > 0 && i === 8;
                return el(
                    'div',
                    { className: 'kng-cell' + ( isMore ? ' kng-cell-more' : '' ), key: id },
                    src ? el( 'img', { src: src, alt: '' } ) : el( 'span', { className: 'kng-cell-empty' }, '#' + id ),
                    isMore ? el( 'span', { className: 'kng-cell-mask' }, '+' + extra ) : null
                );
            } );

            return el(
                Fragment,
                {},
                el(
                    BlockControls,
                    {},
                    el(
                        MediaUploadCheck,
                        {},
                        el( MediaUpload, {
                            multiple: true,
                            gallery: true,
                            allowedTypes: [ 'image' ],
                            value: ids,
                            onSelect: onSelect,
                            render: function ( o ) {
                                return el( ToolbarGroup, {}, el( ToolbarButton, {
                                    icon: 'edit',
                                    label: __( '编辑图片', 'kratos' ),
                                    onClick: o.open
                                } ) );
                            }
                        } )
                    )
                ),
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        { title: __( '九宫格图组', 'kratos' ), initialOpen: true },
                        el( 'p', { style: { margin: '0 0 8px' } },
                            __( '已选 ', 'kratos' ) + ids.length + __( ' 张图片。', 'kratos' ) +
                            ( extra > 0 ? __( '前 8 张平铺，第 9 格显示 “+', 'kratos' ) + extra + __( '” 遮罩，点击进灯箱翻阅全部。', 'kratos' ) : '' )
                        ),
                        el( Button, {
                            variant: 'secondary',
                            isDestructive: true,
                            onClick: function () {
                                props.setAttributes( { ids: [], previews: [] } );
                            }
                        }, __( '清空图片', 'kratos' ) )
                    )
                ),
                el( 'div', blockProps, el( 'div', { className: gridClass }, cells ) )
            );
        },

        save: function () { return null; }
    } );
} )( window.wp );
