<?php

/**
 * 系列文章（Series）
 *  - 注册 kratos_series 自定义分类（非分层）关联到 post
 *  - 编辑器侧栏 metabox：系列内排序（整数，越小越靠前）
 *  - 前台单篇文章在正文顶部渲染系列盒子：系列名 + 位置 + 文章列表 + 上下篇
 *
 * 系列内排序读取 meta `_kratos_series_order`；缺省时按发布时间升序（旧 → 新，符合连载）。
 *
 * @author Dylan Li (Kratos+) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 默认值：作为「数据库无值」与后台首次渲染的兜底
 */
function kratos_series_defaults()
{
    return array(
        'g_series_enabled'         => true,
        'g_series_title_tpl'       => '系列：%series%',
        'g_series_position_tpl'    => '第 %index% 篇 / 共 %total% 篇',
        'g_series_default_open'    => false,
        'g_series_replace_navi'    => true,
        'g_series_max_depth'       => 3,
    );
}

/**
 * 读取系列最大层级（顶层为第 1 层），限制在 1-5 之间
 */
function kratos_series_max_depth()
{
    $d = (int) kratos_option('g_series_max_depth', 3);
    if ($d < 1) $d = 1;
    if ($d > 5) $d = 5;
    return $d;
}

/**
 * 计算 term 的深度（顶层=1）
 */
function kratos_series_term_depth($term_id)
{
    $t = get_term($term_id, 'kratos_series');
    if (!$t || is_wp_error($t)) return 1;
    $depth = 1;
    $pid = (int) $t->parent;
    while ($pid > 0 && $depth < 20) {
        $p = get_term($pid, 'kratos_series');
        if (!$p || is_wp_error($p)) break;
        $depth++;
        $pid = (int) $p->parent;
    }
    return $depth;
}

/**
 * 新增 term 前拦截：若父级深度 = 最大层级，则该 term 会成为 max+1 级，拒绝
 */
function kratos_series_pre_insert_term($term, $taxonomy, $args = array())
{
    if ($taxonomy !== 'kratos_series') return $term;
    if (is_wp_error($term)) return $term;
    $parent = isset($args['parent']) ? (int) $args['parent'] : 0;
    if ($parent <= 0 && isset($_POST['parent'])) $parent = (int) $_POST['parent'];
    if ($parent <= 0) return $term;
    $max = kratos_series_max_depth();
    $parent_depth = kratos_series_term_depth($parent);
    if ($parent_depth >= $max) {
        return new WP_Error(
            'kratos_series_depth_exceeded',
            sprintf(__('创建失败：所选父系列已在第 %1$d 级，超过当前最大层级 %2$d 级。请在「主题设置 → 阅读增强 → 系列文章」调大层级，或选择更浅的父系列。', 'kratos'), $parent_depth, $max)
        );
    }
    return $term;
}

/**
 * 编辑 term 时拦截 parent 变更：若变更后深度会超过最大层级，则拒绝
 */
function kratos_series_pre_update_term($data, $term_id, $taxonomy, $args)
{
    if ($taxonomy !== 'kratos_series') return $data;
    $new_parent = isset($data['parent']) ? (int) $data['parent'] : 0;
    if ($new_parent <= 0) return $data;
    $max = kratos_series_max_depth();
    $parent_depth = kratos_series_term_depth($new_parent);
    // 加上当前 term 自己 + 其子孙深度，也不能超
    $descendant_depth = kratos_series_max_descendant_depth($term_id);
    $projected = $parent_depth + $descendant_depth;
    if ($projected > $max) {
        set_transient('kratos_series_depth_err_' . get_current_user_id(), sprintf(
            __('更新失败：将系列移到该父级后总深度会达到 %1$d 级，超过限制 %2$d 级。', 'kratos'),
            $projected, $max
        ), 60);
        // 保留原 parent，阻止提升
        $data['parent'] = (int) get_term($term_id, 'kratos_series')->parent;
    }
    return $data;
}

/**
 * 计算 term 的最大子孙深度（term 自身 = 1，无子孙时返回 1）
 */
function kratos_series_max_descendant_depth($term_id)
{
    $children = get_terms(array(
        'taxonomy'   => 'kratos_series',
        'parent'     => $term_id,
        'hide_empty' => false,
        'fields'     => 'ids',
    ));
    if (is_wp_error($children) || empty($children)) return 1;
    $max = 1;
    foreach ($children as $cid) {
        $d = 1 + kratos_series_max_descendant_depth($cid);
        if ($d > $max) $max = $d;
    }
    return $max;
}

/**
 * 后台提示 update 被拦截的错误
 */
add_action('admin_notices', function () {
    $key = 'kratos_series_depth_err_' . get_current_user_id();
    $msg = get_transient($key);
    if (!$msg) return;
    delete_transient($key);
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
});

add_filter('default_option_kratos_options', function ($default) {
    $defs = kratos_series_defaults();
    if (is_array($default)) return array_merge($defs, $default);
    return $defs;
}, 10, 1);

add_filter('option_kratos_options', function ($value) {
    if (!is_array($value)) return $value;
    foreach (kratos_series_defaults() as $k => $v) {
        if (!array_key_exists($k, $value) || $value[$k] === '' || $value[$k] === null) {
            $value[$k] = $v;
        }
    }
    return $value;
}, 10, 1);

/**
 * 注册 kratos_series taxonomy。
 * 非分层（类似 tag），便于扁平管理。
 */
add_action('init', function () {
    register_taxonomy('kratos_series', array('post'), array(
        'labels' => array(
            'name'              => __('系列', 'kratos'),
            'singular_name'     => __('系列', 'kratos'),
            'search_items'      => __('搜索系列', 'kratos'),
            'all_items'         => __('所有系列', 'kratos'),
            'edit_item'         => __('编辑系列', 'kratos'),
            'update_item'       => __('更新系列', 'kratos'),
            'add_new_item'      => __('新增系列', 'kratos'),
            'new_item_name'     => __('新系列名称', 'kratos'),
            'menu_name'         => __('系列', 'kratos'),
            'view_item'         => __('查看系列', 'kratos'),
            'back_to_items'     => __('← 返回系列列表', 'kratos'),
            'not_found'         => __('未找到系列', 'kratos'),
            'no_terms'          => __('暂无系列', 'kratos'),
            'items_list'        => __('系列列表', 'kratos'),
            'items_list_navigation' => __('系列列表导航', 'kratos'),
            'parent_item'       => __('父级系列', 'kratos'),
            'parent_item_colon' => __('父级系列：', 'kratos'),
        ),
        'public'            => true,
        'hierarchical'      => true,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => array('slug' => kratos_series_slug()),
    ));

    // 限制系列层级：新增/编辑前拦截，父级超限直接返回 WP_Error 阻止保存
    add_filter('pre_insert_term', 'kratos_series_pre_insert_term', 10, 3);
    add_filter('wp_update_term_data', 'kratos_series_pre_update_term', 10, 4);

    // 主题版本或 slug 变化 / 首次启用时自动 flush rewrite rules（避免 /series/xxx 404）。
    $slug     = kratos_series_slug();
    $flag     = THEME_VERSION . '|' . $slug;
    $flushed  = get_option('kratos_series_rewrite_version');
    if ($flushed !== $flag) {
        flush_rewrite_rules(false);
        update_option('kratos_series_rewrite_version', $flag);
    }
}, 11);

/**
 * 读取系列 URL 前缀。仅允许小写字母、数字、连字符；非法或留空时回退 'series'。
 */
function kratos_series_slug()
{
    $raw = kratos_option('g_series_slug', 'series');
    $raw = is_string($raw) ? strtolower(trim($raw)) : '';
    $raw = preg_replace('/[^a-z0-9\-]/', '', $raw);
    $raw = trim($raw, '-');
    return $raw !== '' ? $raw : 'series';
}

// 主题设置里改 slug 后立即 flush 一次
add_action('update_option_kratos_options', function ($old, $new) {
    $old_slug = is_array($old) && isset($old['g_series_slug']) ? $old['g_series_slug'] : '';
    $new_slug = is_array($new) && isset($new['g_series_slug']) ? $new['g_series_slug'] : '';
    if ($old_slug !== $new_slug) {
        // 下一次 init 会读取新 slug；此处仅清 flag 强制 flush
        delete_option('kratos_series_rewrite_version');
    }
}, 10, 2);

// 切换到本主题时也强制 flush 一次
add_action('after_switch_theme', function () {
    flush_rewrite_rules(false);
    update_option('kratos_series_rewrite_version', defined('THEME_VERSION') ? THEME_VERSION : '0');
});

/**
 * 系列 term 的自定义图标（Font Awesome class）
 * 通过 CSF taxonomy options 在「文章 → 系列 → 编辑」页新增字段
 */
if (class_exists('CSF')) {
    CSF::createTaxonomyOptions('_kratos_series_term_meta', array(
        'taxonomy'  => 'kratos_series',
        'data_type' => 'unserialize',
    ));
    CSF::createSection('_kratos_series_term_meta', array(
        'fields' => array(
            array(
                'id'       => 'kratos_series_icon',
                'type'     => 'icon',
                'title'    => __('系列图标', 'kratos'),
                'subtitle' => __('留空则使用默认图标（fa-layer-group）', 'kratos'),
            ),
            array(
                'id'       => 'kratos_series_term_order',
                'type'     => 'number',
                'title'    => __('同级排序', 'kratos'),
                'subtitle' => __('数字越小越靠前；留空则排到最后并按名称排序', 'kratos'),
                'default'  => '',
            ),
        ),
    ));
}

/**
 * 按 term meta `kratos_series_term_order` 升序、其次按名称升序排序 term 数组
 * 未设置排序值的排到末尾（视为极大值），前台/短码统一走这个入口
 */
function kratos_series_sort_terms($terms)
{
    if (empty($terms) || !is_array($terms)) return $terms;
    usort($terms, function ($a, $b) {
        $oa = get_term_meta($a->term_id, 'kratos_series_term_order', true);
        $ob = get_term_meta($b->term_id, 'kratos_series_term_order', true);
        $oa = ($oa === '' || $oa === null) ? PHP_INT_MAX : (int) $oa;
        $ob = ($ob === '' || $ob === null) ? PHP_INT_MAX : (int) $ob;
        if ($oa !== $ob) return $oa <=> $ob;
        return strnatcasecmp((string) $a->name, (string) $b->name);
    });
    return $terms;
}

/**
 * 系列 term 列表页：新增「同级排序」列 + 快速编辑支持
 */
add_filter('manage_edit-kratos_series_columns', function ($columns) {
    $new = array();
    foreach ($columns as $k => $v) {
        $new[$k] = $v;
        if ($k === 'slug') {
            $new['kratos_series_term_order'] = __('同级排序', 'kratos');
        }
    }
    if (!isset($new['kratos_series_term_order'])) {
        $new['kratos_series_term_order'] = __('同级排序', 'kratos');
    }
    return $new;
});

add_filter('manage_kratos_series_custom_column', function ($content, $column_name, $term_id) {
    if ($column_name !== 'kratos_series_term_order') return $content;
    $v = get_term_meta($term_id, 'kratos_series_term_order', true);
    $display = ($v === '' || $v === null) ? '—' : (int) $v;
    // data-* 属性供 quick edit JS 回填；span 便于 JS 定位
    return '<span class="kratos-series-term-order" data-order="' . esc_attr(($v === '' || $v === null) ? '' : (int) $v) . '">' . esc_html($display) . '</span>';
}, 10, 3);

// 列表页表头点击排序
add_filter('manage_edit-kratos_series_sortable_columns', function ($columns) {
    $columns['kratos_series_term_order'] = 'kratos_series_term_order';
    return $columns;
});

add_action('pre_get_terms', function ($query) {
    if (!is_admin()) return;
    $vars = $query->query_vars;
    if (empty($vars['taxonomy']) || !in_array('kratos_series', (array) $vars['taxonomy'], true)) return;
    if (empty($_GET['orderby']) || $_GET['orderby'] !== 'kratos_series_term_order') return;
    $query->query_vars['meta_key'] = 'kratos_series_term_order';
    $query->query_vars['orderby']  = 'meta_value_num';
});

// 快速编辑 / 批量编辑：注入输入框
add_action('quick_edit_custom_box', function ($column_name, $screen, $taxonomy) {
    if ($taxonomy !== 'kratos_series' || $column_name !== 'kratos_series_term_order') return;
    wp_nonce_field('kratos_series_term_order_qe', 'kratos_series_term_order_nonce');
    ?>
    <fieldset>
        <div class="inline-edit-col">
            <label>
                <span class="title"><?php _e('同级排序', 'kratos'); ?></span>
                <span class="input-text-wrap">
                    <input type="number" name="kratos_series_term_order" class="kratos-series-term-order-input" value="" step="1" />
                </span>
            </label>
            <p class="description" style="margin:4px 0 0;"><?php _e('数字越小越靠前；留空则排到最后', 'kratos'); ?></p>
        </div>
    </fieldset>
    <?php
}, 10, 3);

// 保存 quick edit / 常规编辑
add_action('edited_kratos_series', 'kratos_series_save_term_order');
add_action('create_kratos_series', 'kratos_series_save_term_order');
function kratos_series_save_term_order($term_id)
{
    if (!current_user_can('manage_categories')) return;
    // quick edit 场景
    if (isset($_POST['kratos_series_term_order_nonce'])) {
        if (!wp_verify_nonce($_POST['kratos_series_term_order_nonce'], 'kratos_series_term_order_qe')) return;
    }
    if (!isset($_POST['kratos_series_term_order'])) return;
    $raw = trim((string) $_POST['kratos_series_term_order']);
    if ($raw === '') {
        delete_term_meta($term_id, 'kratos_series_term_order');
    } else {
        update_term_meta($term_id, 'kratos_series_term_order', (int) $raw);
    }
}

// 打开 quick edit 时从行读值回填 input
add_action('admin_footer-edit-tags.php', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->taxonomy !== 'kratos_series') return;
    ?>
    <script>
    (function ($) {
        if (typeof inlineEditTax === 'undefined') return;
        var _edit = inlineEditTax.edit;
        inlineEditTax.edit = function (id) {
            _edit.apply(this, arguments);
            var tid = 0;
            if (typeof id === 'object') tid = parseInt($(id).attr('id').replace(/[^0-9]/g, ''), 10);
            else tid = parseInt(id, 10);
            if (!tid) return;
            var $row = $('#tag-' + tid);
            var val = $row.find('.kratos-series-term-order').data('order');
            $('.inline-edit-row').find('input.kratos-series-term-order-input').val(val == null ? '' : val);
        };
    })(jQuery);
    </script>
    <?php
});

/**
 * 读取指定系列 term 的图标 class；无自定义时回退默认
 */
function kratos_series_get_icon($term_id)
{
    $icon = get_term_meta($term_id, 'kratos_series_icon', true);
    $icon = is_string($icon) ? trim($icon) : '';
    return $icon !== '' ? $icon : 'fas fa-layer-group';
}

/**
 * 系列页面（is_tax('kratos_series'))可能不属于主题原生资源逻辑，
 * 但若图标是 Font Awesome，前台单篇文章需要 FA 样式。
 * 若图标带 fa- 前缀且当前主题未启用 fontawesome，则临时入队。
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;
    if (!kratos_option('g_series_enabled', true)) return;

    $need_fa = false;
    if (is_singular('post')) {
        $term = kratos_series_get_current_term(get_the_ID());
        if ($term) {
            $icon = get_term_meta($term->term_id, 'kratos_series_icon', true);
            if ($icon && strpos($icon, 'fa') !== false) $need_fa = true;
        }
    } elseif (is_tax('kratos_series')) {
        // 归档模板使用 fas fa-clock 展示日期，且 term 图标也是 FA
        $need_fa = true;
    }
    if (!$need_fa) return;
    if (wp_style_is('fontawesome', 'enqueued') || wp_style_is('fontawesome', 'registered')) return;
    wp_enqueue_style('fontawesome', get_template_directory_uri() . '/assets/css/fontawesome.min.css', array(), '5.15.2');
}, 25);

/**
 * 编辑器侧栏 metabox：系列内排序值
 */
if (class_exists('CSF')) {
    $prefix_series = '_kratos_series_meta';
    CSF::createMetabox($prefix_series, array(
        'title'     => __('系列文章排序', 'kratos'),
        'post_type' => 'post',
        'data_type' => 'unserialize',
        'context'   => 'side',
        'priority'  => 'default',
        'theme'     => 'light',
    ));
    CSF::createSection($prefix_series, array(
        'fields' => array(
            array(
                'id'       => 'kratos_series_order',
                'type'     => 'number',
                'title'    => __('本文在系列中的顺序', 'kratos'),
                'subtitle' => __('数字越小越靠前；留空则按发布时间自动排序', 'kratos'),
                'default'  => '',
            ),
        ),
    ));
}

/**
 * 获取当前文章所属的第一个系列 term
 */
function kratos_series_get_current_term($post_id)
{
    $terms = get_the_terms($post_id, 'kratos_series');
    if (empty($terms) || is_wp_error($terms)) return null;
    return $terms[0];
}

/**
 * 拉取系列内所有文章，按 order meta 升序、其次按发布时间升序
 * 返回 array of WP_Post
 */
function kratos_series_get_posts($term_id)
{
    $q = new WP_Query(array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
        'ignore_sticky_posts' => 1,
        'tax_query'      => array(
            array(
                'taxonomy'         => 'kratos_series',
                'field'            => 'term_id',
                'terms'            => $term_id,
                'include_children' => false,
            ),
        ),
        // 保存时已确保每篇文章都有 kratos_series_order，直接用 INNER JOIN meta_value_num
        'meta_key' => 'kratos_series_order',
        'orderby'  => array('meta_value_num' => 'ASC', 'date' => 'ASC'),
    ));
    return $q->posts;
}

/**
 * 保存文章时：如果挂了系列且未设置 kratos_series_order，自动置为该系列内 MAX(order)+1
 */
add_action('save_post_post', 'kratos_series_ensure_order', 20, 3);
function kratos_series_ensure_order($post_id, $post, $update)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if ($post->post_status === 'auto-draft' || $post->post_status === 'trash') return;
    $terms = get_the_terms($post_id, 'kratos_series');
    if (empty($terms) || is_wp_error($terms)) return;
    $existing = get_post_meta($post_id, 'kratos_series_order', true);
    if ($existing !== '' && $existing !== null && $existing !== false) return;
    // 以第一个系列作为参照
    $tid = (int) $terms[0]->term_id;
    $next = kratos_series_next_order($tid);
    update_post_meta($post_id, 'kratos_series_order', $next);
}

/**
 * 返回指定系列内的下一个 order（该系列内 MAX + 1，无则 1）
 */
function kratos_series_next_order($term_id)
{
    global $wpdb;
    $sql = $wpdb->prepare(
        "SELECT MAX(CAST(pm.meta_value AS SIGNED)) FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm.post_id
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         WHERE pm.meta_key = %s AND tt.taxonomy = %s AND tt.term_id = %d",
        'kratos_series_order', 'kratos_series', $term_id
    );
    $max = (int) $wpdb->get_var($sql);
    return $max + 1;
}

/**
 * 一次性回填：历史文章 & 迁移到新版时，把「已挂系列但无 order」的补齐。
 * 用 option 版本号幂等，只在管理员访问后台时跑一次，跑完更新版本号。
 */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (get_option('kratos_series_backfill_v1') === '1') return;
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT DISTINCT tr.object_id AS post_id, tt.term_id
         FROM {$wpdb->term_taxonomy} tt
         INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
         INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = tr.object_id AND pm.meta_key = 'kratos_series_order'
         WHERE tt.taxonomy = 'kratos_series'
           AND p.post_status IN ('publish','future','draft','private','pending')
           AND (pm.meta_id IS NULL OR pm.meta_value = '')
         ORDER BY p.post_date ASC"
    );
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $post_id = (int) $r->post_id;
            $tid = (int) $r->term_id;
            if (!$post_id || !$tid) continue;
            $existing = get_post_meta($post_id, 'kratos_series_order', true);
            if ($existing !== '' && $existing !== null && $existing !== false) continue;
            update_post_meta($post_id, 'kratos_series_order', kratos_series_next_order($tid));
        }
    }
    update_option('kratos_series_backfill_v1', '1');
});

/**
 * 渲染系列盒子（供 single.php 直接调用；插入正文顶部）
 * 位置：文章 header 之后、正文之前
 */
function kratos_series_render_box()
{
    if (!kratos_option('g_series_enabled', true)) return;
    if (!is_singular('post')) return;

    $post_id = get_the_ID();
    $term = kratos_series_get_current_term($post_id);
    if (!$term) return;

    $posts = kratos_series_get_posts($term->term_id);
    if (empty($posts)) return;

    $total = count($posts);
    $index = 0;
    foreach ($posts as $i => $p) {
        if ((int)$p->ID === (int)$post_id) { $index = $i + 1; break; }
    }
    if ($index === 0) return;

    $title_tpl = kratos_option('g_series_title_tpl', __('系列：%series%', 'kratos'));
    $pos_tpl   = kratos_option('g_series_position_tpl', __('第 %index% 篇 / 共 %total% 篇', 'kratos'));
    $open      = (bool) kratos_option('g_series_default_open', true);

    $title = str_replace('%series%', esc_html($term->name), $title_tpl);
    $pos   = str_replace(
        array('%index%', '%total%'),
        array((int)$index, (int)$total),
        $pos_tpl
    );

    // 祖先链（面包屑）
    $ancestors = array();
    $pid_i = (int) $term->parent;
    while ($pid_i > 0) {
        $p_i = get_term($pid_i, 'kratos_series');
        if (!$p_i || is_wp_error($p_i)) break;
        array_unshift($ancestors, $p_i);
        $pid_i = (int) $p_i->parent;
    }
    echo '<div class="kratos-series' . ($open ? ' is-open' : '') . '">';
    echo '<div class="kratos-series-head">';
    echo '<div class="kratos-series-titlewrap">';
    echo '<i class="' . esc_attr(kratos_series_get_icon($term->term_id)) . ' kratos-series-icon"></i>';
    echo '<a class="kratos-series-title" href="' . esc_url(get_term_link($term)) . '">' . wp_kses_post($title) . '</a>';
    echo '<span class="kratos-series-pos">' . esc_html($pos) . '</span>';
    echo '</div>';
    echo '<button type="button" class="kratos-series-toggle" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-label="' . esc_attr__('展开/收起系列列表', 'kratos') . '"><i class="fas fa-chevron-down"></i></button>';
    echo '</div>';

    // 面包屑（仅有祖先时展示）
    if (!empty($ancestors)) {
        echo '<nav class="kratos-series-crumbs" aria-label="' . esc_attr__('系列层级', 'kratos') . '">';
        $crumbs = array();
        foreach ($ancestors as $anc) {
            $crumbs[] = '<a href="' . esc_url(get_term_link($anc)) . '">' . esc_html($anc->name) . '</a>';
        }
        $crumbs[] = '<span class="kratos-series-crumb-current">' . esc_html($term->name) . '</span>';
        echo implode('<span class="kratos-series-crumb-sep">›</span>', $crumbs);
        echo '</nav>';
    }


    echo '<ol class="kratos-series-list">';
    foreach ($posts as $i => $p) {
        $is_current = ((int)$p->ID === (int)$post_id);
        $classes = 'kratos-series-item' . ($is_current ? ' is-current' : '');
        echo '<li class="' . esc_attr($classes) . '">';
        echo '<span class="kratos-series-num">' . ((int)$i + 1) . '</span>';
        if ($is_current) {
            echo '<span class="kratos-series-name">' . esc_html($p->post_title) . '</span>';
            echo '<span class="kratos-series-badge">' . esc_html__('当前', 'kratos') . '</span>';
        } else {
            echo '<a class="kratos-series-name" href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html($p->post_title) . '</a>';
        }
        echo '</li>';
    }
    echo '</ol>';
    echo '</div>';
}

/**
 * 渲染系列上下篇导航（供 single.php 在原上一篇/下一篇位置调用）。
 * 使用主题原生 .post-navigation / .nav-previous / .nav-next 结构，保持样式一致。
 */
function kratos_series_render_nav()
{
    if (!kratos_option('g_series_enabled', true)) return;
    if (!is_singular('post')) return;
    $post_id = get_the_ID();
    $term = kratos_series_get_current_term($post_id);
    if (!$term) return;
    $posts = kratos_series_get_posts($term->term_id);
    if (empty($posts)) return;
    $idx = -1;
    foreach ($posts as $i => $p) {
        if ((int)$p->ID === (int)$post_id) { $idx = $i; break; }
    }
    if ($idx < 0) return;
    $prev = ($idx > 0) ? $posts[$idx - 1] : null;
    $next = ($idx < count($posts) - 1) ? $posts[$idx + 1] : null;
    if (!$prev && !$next) return;

    echo '<nav class="navigation post-navigation clearfix" role="navigation">';
    if ($prev) {
        echo '<div class="nav-previous clearfix"><a title="' . esc_attr($prev->post_title) . '" href="' . esc_url(get_permalink($prev->ID)) . '">' . __('&lt; 系列上一篇', 'kratos') . '</a></div>';
    }
    if ($next) {
        echo '<div class="nav-next"><a title="' . esc_attr($next->post_title) . '" href="' . esc_url(get_permalink($next->ID)) . '">' . __('系列下一篇 &gt;', 'kratos') . '</a></div>';
    }
    echo '</nav>';
}

/**
 * 前台资源：CSS + 折叠 JS 内联
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin() || !is_singular('post')) return;
    if (!kratos_option('g_series_enabled', true)) return;

    // 使用每日皮肤 / 暗夜模式的 CSS 变量，随皮肤自动切换
    $accent   = 'var(--kr-skin-accent, #0abbef)';
    $card_bg  = 'var(--kr-skin-card-bg, #fff)';
    $card_line = 'var(--kr-skin-card-line, rgba(0,0,0,.08))';
    $text     = 'var(--kr-skin-text, inherit)';
    $muted    = 'var(--kr-skin-muted, #888)';
    $tag_bg   = 'var(--kr-skin-tag-bg, rgba(0,0,0,.04))';
    $css = '';
    $css .= '.kratos-series{margin:0 0 24px;padding:0;background:' . $card_bg . ';border:1px solid ' . $card_line . ';border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;color:' . $text . '}';
    $css .= '.kratos-series-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;background:linear-gradient(90deg,' . $tag_bg . ',transparent);border-bottom:1px solid ' . $card_line . '}';
    $css .= '.kratos-series-titlewrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1;min-width:0}';
    $css .= '.kratos-series-icon{color:' . $accent . ';font-size:16px}';
    $css .= '.kratos-series-title{font-size:16px;font-weight:600;color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
    $css .= '.kratos-series-title:hover{color:' . $accent . '}';
    $css .= '.kratos-series-pos{font-size:12px;color:' . $muted . ';padding:2px 8px;background:' . $tag_bg . ';border-radius:10px}';
    $css .= '.kratos-series-toggle{flex:0 0 auto;background:none;border:none;color:' . $muted . ';cursor:pointer;padding:4px 6px;font-size:14px;transition:transform .25s}';
    $css .= '.kratos-series-toggle:hover{color:' . $accent . '}';
    $css .= '.kratos-series:not(.is-open) .kratos-series-toggle i{transform:rotate(-90deg)}';
    $css .= '.kratos-series-toggle i{transition:transform .25s;display:inline-block}';
    $css .= '.kratos-series-list{list-style:none;margin:0;padding:6px 0;max-height:600px;overflow:auto;transition:max-height .3s ease,padding .3s ease,opacity .2s}';
    $css .= '.kratos-series:not(.is-open) .kratos-series-list{max-height:0;padding-top:0;padding-bottom:0;opacity:0;overflow:hidden}';
    $css .= '.kratos-series-item{display:flex;align-items:center;gap:10px;padding:8px 16px;font-size:14px;line-height:1.5;border-left:3px solid transparent}';
    $css .= '.kratos-series-item.is-current{background:' . $tag_bg . ';border-left-color:' . $accent . '}';
    $css .= '.kratos-series-num{flex:0 0 auto;min-width:22px;height:22px;line-height:22px;text-align:center;background:' . $tag_bg . ';color:' . $muted . ';border-radius:50%;font-size:12px}';
    $css .= '.kratos-series-item.is-current .kratos-series-num{background:' . $accent . ';color:#fff}';
    $css .= '.kratos-series-name{flex:1;color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
    $css .= 'a.kratos-series-name:hover{color:' . $accent . '}';
    $css .= '.kratos-series-item.is-current .kratos-series-name{font-weight:600}';
    $css .= '.kratos-series-badge{flex:0 0 auto;font-size:11px;padding:1px 6px;background:' . $accent . ';color:#fff;border-radius:3px}';
    // 面包屑
    $css .= '.kratos-series-crumbs{padding:8px 16px;font-size:12px;color:' . $muted . ';border-bottom:1px solid ' . $card_line . ';display:flex;flex-wrap:wrap;align-items:center;gap:6px;line-height:1.6;transition:max-height .3s ease,padding .3s ease,opacity .2s;max-height:200px;overflow:hidden}';
    $css .= '.kratos-series-crumbs a{color:' . $muted . ';text-decoration:none}';
    $css .= '.kratos-series-crumbs a:hover{color:' . $accent . '}';
    $css .= '.kratos-series-crumb-sep{color:' . $muted . ';opacity:.6}';
    $css .= '.kratos-series-crumb-current{color:' . $text . ';font-weight:600}';
    $css .= '.kratos-series:not(.is-open) .kratos-series-crumbs{max-height:0;padding-top:0;padding-bottom:0;opacity:0;border-bottom-width:0}';

    wp_register_style('kratos-series', false);
    wp_enqueue_style('kratos-series');
    wp_add_inline_style('kratos-series', $css);

    $js = '(function(){document.addEventListener("click",function(e){var t=e.target.closest(".kratos-series-toggle");if(!t)return;var box=t.closest(".kratos-series");if(!box)return;var open=box.classList.toggle("is-open");t.setAttribute("aria-expanded",open?"true":"false");});})();';
    wp_register_script('kratos-series', '', array(), THEME_VERSION, true);
    wp_enqueue_script('kratos-series');
    wp_add_inline_script('kratos-series', $js);
}, 30);

/**
 * 前台系列归档 /series/<slug>/：主查询按 kratos_series_order（升序）+ 发布时间（升序）排列，
 * 让 pagelist() 分页与系列内顺序保持一致。
 * 用 LEFT JOIN 引入 meta，无 order 的记录 CAST 后为 0；因此对未设置 order 的文章依旧稳定按 date 兜底。
 * 为了让"未设置 order 的文章"排到"有 order 的文章"之后，用 IS NULL 优先级排序。
 */
add_action('pre_get_posts', function ($q) {
    if (is_admin() || !$q->is_main_query()) return;
    if (!$q->is_tax('kratos_series')) return;
    // 父系列归档不聚合子系列文章。WordPress 归档默认用 taxonomy+term query_var，
    // 不会填 tax_query，需显式重写为 tax_query 并关闭 include_children。
    $slug = $q->get('kratos_series');
    if (!$slug) $slug = $q->get('term');
    if (!$slug) return;
    $q->set('kratos_series', '');
    $q->set('term', '');
    $q->set('tax_query', array(
        array(
            'taxonomy'         => 'kratos_series',
            'field'            => 'slug',
            'terms'            => $slug,
            'include_children' => false,
        ),
    ));
});

/**
 * 系列归档排序：SQL 层 LEFT JOIN meta，排序规则：
 *   1) 有 order meta 排在无 order 的前面（IS NULL 排后）
 *   2) 有 order 内按数值升
 *   3) 兜底按发布时间升
 */
add_action('pre_get_posts', function ($q) {
    if (is_admin() || !$q->is_main_query()) return;
    if (!$q->is_tax('kratos_series')) return;
    // 保存时已保证每篇文章都有 kratos_series_order → 走 INNER JOIN meta_value_num，能用 postmeta 索引
    $q->set('meta_key', 'kratos_series_order');
    $q->set('orderby', array('meta_value_num' => 'ASC', 'date' => 'ASC'));
}, 20);

/**
 * 后台「所有文章」列表新增「系列排序」列 + 快速编辑支持
 */
add_filter('manage_post_posts_columns', function ($cols) {
    $new = array();
    foreach ($cols as $k => $v) {
        $new[$k] = $v;
        // 插在 taxonomy-kratos_series 列后面（如果没有则插在 date 前）
        if ($k === 'taxonomy-kratos_series') {
            $new['kratos_series_order'] = __('系列排序', 'kratos');
        }
    }
    if (!isset($new['kratos_series_order'])) {
        $tmp = array();
        foreach ($new as $k => $v) {
            if ($k === 'date') $tmp['kratos_series_order'] = __('系列排序', 'kratos');
            $tmp[$k] = $v;
        }
        $new = $tmp;
    }
    return $new;
});
add_action('manage_post_posts_custom_column', function ($col, $post_id) {
    if ($col !== 'kratos_series_order') return;
    $order = get_post_meta($post_id, 'kratos_series_order', true);
    $has_series = (bool) get_the_terms($post_id, 'kratos_series');
    if (!$has_series) {
        echo '<span style="color:#a7aaad">—</span>';
    } else {
        echo '<span class="kratos-series-order-val">' . ($order === '' ? '—' : (int) $order) . '</span>';
    }
    // 隐藏字段供快速编辑 JS 读取
    echo '<span class="kratos-series-order-raw" style="display:none">' . esc_html($order === '' ? '' : (int) $order) . '</span>';
}, 10, 2);
add_filter('manage_edit-post_sortable_columns', function ($cols) {
    $cols['kratos_series_order'] = 'kratos_series_order';
    return $cols;
});
add_action('pre_get_posts', function ($q) {
    if (!is_admin() || !$q->is_main_query()) return;
    if ($q->get('orderby') !== 'kratos_series_order') return;
    $q->set('meta_key', 'kratos_series_order');
    $q->set('orderby', 'meta_value_num');
});

/**
 * 快速编辑：在 inline-edit-post 里注入「系列排序」字段
 */
add_action('quick_edit_custom_box', function ($column_name, $post_type) {
    if ($post_type !== 'post' || $column_name !== 'kratos_series_order') return;
    wp_nonce_field('kratos_series_order_qe', 'kratos_series_order_qe_nonce');
    ?>
    <fieldset class="inline-edit-col-right">
        <div class="inline-edit-col">
            <label>
                <span class="title"><?php _e('系列排序', 'kratos'); ?></span>
                <span class="input-text-wrap"><input type="number" name="kratos_series_order" class="ptitle" value="" style="width:6em"></span>
            </label>
        </div>
    </fieldset>
    <?php
}, 10, 2);
add_action('save_post_post', function ($post_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['kratos_series_order_qe_nonce']) || !wp_verify_nonce($_POST['kratos_series_order_qe_nonce'], 'kratos_series_order_qe')) return;
    if (!current_user_can('edit_post', $post_id)) return;
    $raw = isset($_POST['kratos_series_order']) ? trim((string) $_POST['kratos_series_order']) : '';
    if ($raw === '') return; // 空值不动，保持保存兜底逻辑
    update_post_meta($post_id, 'kratos_series_order', (int) $raw);
}, 5, 2);

// 快速编辑打开时，把当前值填入输入框
add_action('admin_footer-edit.php', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'post') return;
    ?>
    <script>
    (function($){
        if (!window.inlineEditPost) return;
        var _edit = inlineEditPost.edit;
        inlineEditPost.edit = function(id){
            _edit.apply(this, arguments);
            var post_id = 0;
            if (typeof(id) === 'object') post_id = parseInt(this.getId(id), 10);
            if (!post_id) return;
            var $row = $('#post-' + post_id);
            var val = $row.find('.kratos-series-order-raw').text();
            $('#edit-' + post_id).find('input[name="kratos_series_order"]').val(val);
        };
    })(jQuery);
    </script>
    <?php
});

/**
 * 后台「所有文章」列表新增「系列」筛选下拉框
 */
add_action('restrict_manage_posts', function ($post_type) {
    if ($post_type !== 'post') return;
    $tax = get_taxonomy('kratos_series');
    if (!$tax) return;
    $selected = isset($_GET['kratos_series']) ? sanitize_text_field($_GET['kratos_series']) : '';
    wp_dropdown_categories(array(
        'show_option_all' => __('所有系列', 'kratos'),
        'taxonomy'        => 'kratos_series',
        'name'            => 'kratos_series',
        'orderby'         => 'name',
        'selected'        => $selected,
        'hierarchical'    => true,
        'depth'           => 3,
        'show_count'      => true,
        'hide_empty'      => false,
        'value_field'     => 'slug',
    ));
});

/**
 * 短码 [kratos_series_list]：所有系列的树形卡片列表
 * 属性：
 *   parent   父级 term_id（默认 0 顶层）
 *   depth    递归展开深度（默认 3）
 *   hide_empty  是否隐藏无文章的系列（默认 no）
 * 用法：新建页面 → 内容中插入 [kratos_series_list]
 */
add_shortcode('kratos_series_list', 'kratos_series_list_shortcode');
function kratos_series_list_shortcode($atts = array())
{
    $atts = shortcode_atts(array(
        'parent'     => 0,
        'depth'      => kratos_series_max_depth(),
        'hide_empty' => 'no',
    ), $atts, 'kratos_series_list');

    $hide_empty = in_array(strtolower($atts['hide_empty']), array('1', 'true', 'yes'), true);
    $terms = get_terms(array(
        'taxonomy'   => 'kratos_series',
        'hide_empty' => $hide_empty,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));
    if (is_wp_error($terms) || empty($terms)) {
        return '<div class="ksl-empty">' . esc_html__('暂无系列', 'kratos') . '</div>';
    }

    // 按 parent 建树，并按同级排序 meta 排序
    $tree = array();
    foreach ($terms as $t) {
        $tree[$t->parent][] = $t;
    }
    foreach ($tree as $pid => $group) {
        $tree[$pid] = kratos_series_sort_terms($group);
    }
    // 若任一 term 用了 FA 图标，且当前页未加载 FA，则临时入队
    foreach ($terms as $t) {
        $ic = get_term_meta($t->term_id, 'kratos_series_icon', true);
        if ($ic && strpos($ic, 'fa') !== false) {
            if (!wp_style_is('fontawesome', 'enqueued') && !wp_style_is('fontawesome', 'registered')) {
                wp_enqueue_style('fontawesome', get_template_directory_uri() . '/assets/css/fontawesome.min.css', array(), '5.15.2');
            }
            break;
        }
    }

    ob_start();
    echo '<div class="kratos-series-list-wrap">';
    kratos_series_list_render_branch($tree, (int) $atts['parent'], 0, (int) $atts['depth']);
    echo '</div>';
    kratos_series_list_render_style();
    return ob_get_clean();
}

function kratos_series_list_render_branch($tree, $parent_id, $level, $max_depth)
{
    if (!isset($tree[$parent_id])) return;
    if ($level >= $max_depth) return;
    $tag = $level === 0 ? 'ul' : 'ul';
    echo '<' . $tag . ' class="ksl-list ksl-level-' . (int)$level . '">';
    foreach ($tree[$parent_id] as $term) {
        $icon = function_exists('kratos_series_get_icon') ? kratos_series_get_icon($term->term_id) : 'fas fa-layer-group';
        $icon_raw = get_term_meta($term->term_id, 'kratos_series_icon', true);
        $has_icon = is_string($icon_raw) && trim($icon_raw) !== '';
        $desc = trim(strip_tags(term_description($term->term_id, 'kratos_series')));
        $count = (int) $term->count;
        $has_children = !empty($tree[$term->term_id]);

        echo '<li class="ksl-item' . ($has_children ? ' has-children' : '') . '">';
        echo '<a class="ksl-card" href="' . esc_url(get_term_link($term)) . '">';
        echo '<span class="ksl-icon" aria-hidden="true">';
        if ($has_icon) {
            echo '<i class="' . esc_attr($icon) . '"></i>';
        } else {
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.39 4.84L20 8l-4 3.9.94 5.5L12 14.9 7.06 17.4 8 11.9 4 8l5.61-1.16L12 2z"/></svg>';
        }
        echo '</span>';
        echo '<span class="ksl-body">';
        echo '<span class="ksl-name">' . esc_html($term->name);
        echo ' <span class="ksl-count">' . (int)$count . '</span>';
        echo '</span>';
        if ($desc !== '') {
            echo '<span class="ksl-desc">' . esc_html($desc) . '</span>';
        }
        echo '</span>';
        echo '</a>';

        // 仅在下一层还允许展开时才输出 children 容器，避免出现空 div
        if ($has_children && ($level + 1) < $max_depth) {
            echo '<div class="ksl-children">';
            kratos_series_list_render_branch($tree, $term->term_id, $level + 1, $max_depth);
            echo '</div>';
        }
        echo '</li>';
    }
    echo '</' . $tag . '>';
}

function kratos_series_list_render_style()
{
    static $printed = false;
    if ($printed) return;
    $printed = true;
    ?>
    <style>
    .kratos-series-list-wrap{--ksl-fg:var(--kr-skin-text,#333);--ksl-fg-soft:var(--kr-skin-muted,#666);--ksl-accent:var(--kr-skin-accent,#336699);--ksl-line:var(--kr-skin-card-line,rgba(0,0,0,.08));--ksl-card-bg:var(--kr-skin-card-bg,#fff);--ksl-child-bg:var(--kr-skin-tag-bg,rgba(0,0,0,.02));--ksl-shadow:0 1px 3px rgba(0,0,0,.06);}
    .kratos-series-list-wrap .ksl-list{list-style:none;margin:0;padding:0;}
    .kratos-series-list-wrap .ksl-level-0{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;}
    /* 顶级父系列（有子系列）占满整行，作为章节标题栏 */
    .kratos-series-list-wrap .ksl-level-0 > .ksl-item.has-children{grid-column:1 / -1;}
    .kratos-series-list-wrap .ksl-item{margin:0;}
    .kratos-series-list-wrap .ksl-card{display:flex;gap:12px;align-items:center;padding:16px;border:1px solid var(--ksl-line);border-radius:12px;background:var(--ksl-card-bg);box-shadow:var(--ksl-shadow);text-decoration:none;color:var(--ksl-fg);transition:transform .18s ease,border-color .18s ease,color .18s ease;}
    .kratos-series-list-wrap .ksl-card:hover{transform:translateY(-2px);border-color:var(--ksl-accent);color:var(--ksl-accent);}
    .kratos-series-list-wrap .ksl-icon{flex:0 0 auto;width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(0,0,0,.03),rgba(0,0,0,.06));color:var(--ksl-accent);}
    html[data-theme="dark"] .kratos-series-list-wrap .ksl-icon,body.dark .kratos-series-list-wrap .ksl-icon{background:linear-gradient(135deg,rgba(255,255,255,.04),rgba(255,255,255,.08));}
    .kratos-series-list-wrap .ksl-icon i{font-size:16px;line-height:1;}
    .kratos-series-list-wrap .ksl-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px;}
    .kratos-series-list-wrap .ksl-name{font-size:16px;font-weight:600;line-height:1.4;display:flex;align-items:center;gap:8px;}
    .kratos-series-list-wrap .ksl-count{font-size:11px;font-weight:500;padding:1px 8px;background:rgba(51,102,153,.1);color:var(--ksl-accent);border-radius:10px;}
    .kratos-series-list-wrap .ksl-desc{font-size:13px;color:var(--ksl-fg-soft);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;max-height:3em;}
    /* 子级 */
    .kratos-series-list-wrap .ksl-children{margin-top:10px;padding:10px 12px;background:var(--ksl-child-bg);border-radius:8px;}
    /* 1 级子系列：响应式网格平铺（不受父级卡片挤压，最多自动布满） */
    .kratos-series-list-wrap .ksl-level-1{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;}
    /* 2 级及以下：紧凑纵向堆叠 + 左侧竖线体现层级 */
    .kratos-series-list-wrap [class*="ksl-level-"]:not(.ksl-level-0):not(.ksl-level-1){display:flex;flex-direction:column;gap:6px;padding-left:14px;border-left:2px solid var(--ksl-line);margin-top:6px;}
    /* 卡片：1 级实心边框；2+ 级虚线兜底 */
    .kratos-series-list-wrap [class*="ksl-level-"]:not(.ksl-level-0) .ksl-card{padding:8px 10px;border-radius:6px;box-shadow:none;}
    .kratos-series-list-wrap .ksl-level-1 .ksl-card{background:var(--ksl-card-bg);border:1px solid var(--ksl-line);}
    .kratos-series-list-wrap [class*="ksl-level-"]:not(.ksl-level-0):not(.ksl-level-1) .ksl-card{background:transparent;border:1px dashed transparent;}
    .kratos-series-list-wrap [class*="ksl-level-"]:not(.ksl-level-0) .ksl-card:hover{transform:none;border:1px solid var(--ksl-accent);background:var(--ksl-card-bg);}
    .kratos-series-list-wrap [class*="ksl-level-"]:not(.ksl-level-0) .ksl-icon{width:26px;height:26px;border-radius:6px;}
    .kratos-series-list-wrap [class*="ksl-level-"]:not(.ksl-level-0) .ksl-icon i{font-size:12px;}
    .kratos-series-list-wrap [class*="ksl-level-"]:not(.ksl-level-0) .ksl-name{font-size:14px;font-weight:500;}
    .kratos-series-list-wrap [class*="ksl-level-"]:not(.ksl-level-0) .ksl-desc{font-size:12px;-webkit-line-clamp:1;max-height:1.5em;}
    @media (max-width:640px){.kratos-series-list-wrap .ksl-level-0{grid-template-columns:1fr;}}
    </style>
    <?php
}

/**
 * 判断当前文章是否属于某个系列（模板中用于决定是否隐藏默认上下篇导航）
 */
function kratos_series_current_has_series($post_id = null)
{
    if (!kratos_option('g_series_enabled', true)) return false;
    $post_id = $post_id ?: get_the_ID();
    return (bool) kratos_series_get_current_term($post_id);
}
