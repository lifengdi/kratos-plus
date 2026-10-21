<?php

/**
 * 观影读书录（Media Library）
 *
 * 职责：
 *   - 注册 CPT `media_item` 与 taxonomy `media_type`
 *   - 首次激活种子常用类型（书籍/电影/剧集/游戏/其他），用户可在后台自由增删改
 *   - 为 term 增补 icon/color/order/unit 四个 meta，支持后台 UI 编辑
 *   - 为条目增补 metabox（封面/作者/年份/评分/状态/完成日期/外链等）
 *   - 短码 [media_library] / [media_stats]，服务端按 ?mt=slug 过滤 tab
 *   - 评论扩展：条目下的评论支持 1-10 分评分（存评论 meta `_kml_c_rate`），
 *     渲染时在评论文本前输出一个 ★N 徽章
 *   - body_class 注入 is-kratos-media-page / is-kratos-media-single，
 *     方便皮肤层 §7 透明化（避免「卡中卡」）
 *   - 列表 CSS 走 --khs-* 变量命名空间，已通过 components.css 别名层继承所有皮肤色
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ========== 注册 CPT & taxonomy ========== */

add_action('init', 'kratos_kml_register', 5);
function kratos_kml_register()
{
    register_post_type('media_item', array(
        'labels'              => array(
            'name'               => __('观影读书录', 'kratos'),
            'singular_name'      => __('条目', 'kratos'),
            'add_new'            => __('新增条目', 'kratos'),
            'add_new_item'       => __('新增条目', 'kratos'),
            'edit_item'          => __('编辑条目', 'kratos'),
            'new_item'           => __('新条目', 'kratos'),
            'view_item'          => __('查看条目', 'kratos'),
            'search_items'       => __('搜索条目', 'kratos'),
            'not_found'          => __('暂无条目', 'kratos'),
            'not_found_in_trash' => __('回收站为空', 'kratos'),
            'menu_name'          => __('观影读书录', 'kratos'),
        ),
        'public'              => true,
        'show_in_rest'        => false,
        'has_archive'         => 'media',
        'menu_icon'           => 'dashicons-book-alt',
        'menu_position'       => 20,
        'supports'            => array('title', 'editor', 'thumbnail', 'comments', 'revisions'),
        'rewrite'             => array('slug' => 'media', 'with_front' => false),
        'taxonomies'          => array('media_type'),
    ));

    register_taxonomy('media_type', array('media_item'), array(
        'labels'            => array(
            'name'          => __('类型', 'kratos'),
            'singular_name' => __('类型', 'kratos'),
            'add_new_item'  => __('新增类型', 'kratos'),
            'edit_item'     => __('编辑类型', 'kratos'),
            'menu_name'     => __('类型', 'kratos'),
        ),
        'hierarchical'      => false,
        'public'            => true,
        'show_admin_column' => true,
        'show_in_rest'      => false,
        'rewrite'           => array('slug' => 'media-type', 'with_front' => false),
    ));

    kratos_kml_seed_default_terms();

    // 自动刷新一次 rewrite 规则：首次激活 / 升级 bump 版本号时触发，
    // 避免用户必须手动到「设置 → 固定链接」保存一次才解掉详情页 404。
    // 以后若修改 CPT/taxonomy 的 slug 或 rewrite 结构，把下面常量递增一次即可。
    $rw_v = '1';
    if (get_option('kratos_media_rewrite_v') !== $rw_v) {
        flush_rewrite_rules(false);
        update_option('kratos_media_rewrite_v', $rw_v);
    }
}

/**
 * 首次种子：仅在 option kratos_media_seeded 尚未置位时执行一次，
 * 之后用户删除/改名/新增均不会被重新注入。
 */
function kratos_kml_seed_default_terms()
{
    if (get_option('kratos_media_seeded')) {
        return;
    }
    $seeds = array(
        array('name' => '书籍', 'slug' => 'book',  'icon' => "\xf0\x9f\x93\x96", 'color' => '#6a8e5a', 'order' => 10, 'unit' => '本'),
        array('name' => '电影', 'slug' => 'movie', 'icon' => "\xf0\x9f\x8e\xac", 'color' => '#4a6fa5', 'order' => 20, 'unit' => '部'),
        array('name' => '剧集', 'slug' => 'tv',    'icon' => "\xf0\x9f\x93\xba", 'color' => '#8e5aa6', 'order' => 30, 'unit' => '部'),
        array('name' => '游戏', 'slug' => 'game',  'icon' => "\xf0\x9f\x8e\xae", 'color' => '#a68c5a', 'order' => 40, 'unit' => '款'),
        array('name' => '其他', 'slug' => 'other', 'icon' => "\xf0\x9f\x93\x8c", 'color' => '#999999', 'order' => 99, 'unit' => '项'),
    );
    foreach ($seeds as $s) {
        if (term_exists($s['slug'], 'media_type')) continue;
        $r = wp_insert_term($s['name'], 'media_type', array('slug' => $s['slug']));
        if (!is_wp_error($r)) {
            update_term_meta($r['term_id'], '_kml_icon',  $s['icon']);
            update_term_meta($r['term_id'], '_kml_color', $s['color']);
            update_term_meta($r['term_id'], '_kml_order', $s['order']);
            update_term_meta($r['term_id'], '_kml_unit',  $s['unit']);
        }
    }
    update_option('kratos_media_seeded', 1);
}

/* ========== 编辑条目页：把类型从「标签输入框」替换成下拉框 ==========
 * 为什么：media_type 是 hierarchical=false，WP 默认用 tagsdiv 渲染（和文章标签同款，
 * 自由输入），对「从固定分类里选一个」的场景不直观。这里 remove 掉默认 meta box，
 * 换成单选 <select>。保留非层级的分类数据结构不变。
 */
add_action('add_meta_boxes', 'kratos_kml_replace_type_metabox', 20);
function kratos_kml_replace_type_metabox()
{
    remove_meta_box('tagsdiv-media_type', 'media_item', 'side');
    add_meta_box(
        'kml_media_type_select',
        __('类型', 'kratos'),
        'kratos_kml_render_type_metabox',
        'media_item',
        'side',
        'default'
    );
}

function kratos_kml_render_type_metabox($post)
{
    $terms = get_terms(array('taxonomy' => 'media_type', 'hide_empty' => false));
    $assigned = wp_get_object_terms($post->ID, 'media_type', array('fields' => 'slugs'));
    $current = !is_wp_error($assigned) && !empty($assigned) ? $assigned[0] : '';
    wp_nonce_field('kratos_kml_type_save', 'kratos_kml_type_nonce');
    ?>
    <p>
        <select name="kratos_kml_type" style="width:100%">
            <option value=""><?php esc_html_e('— 未分类 —', 'kratos'); ?></option>
            <?php if (!is_wp_error($terms)) foreach ($terms as $t): ?>
                <option value="<?php echo esc_attr($t->slug); ?>"<?php selected($current, $t->slug); ?>>
                    <?php echo esc_html($t->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <p style="color:#666;font-size:12px;margin:6px 0 0">
        <?php
        printf(
            /* translators: %s = 后台链接 */
            esc_html__('在 %s 管理类型（图标 / 色标 / 排序）。', 'kratos'),
            '<a href="' . esc_url(admin_url('edit-tags.php?taxonomy=media_type&post_type=media_item')) . '">' . esc_html__('类型管理页', 'kratos') . '</a>'
        );
        ?>
    </p>
    <?php
}

add_action('save_post_media_item', 'kratos_kml_save_type', 10, 2);
function kratos_kml_save_type($post_id, $post)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['kratos_kml_type_nonce']) || !wp_verify_nonce($_POST['kratos_kml_type_nonce'], 'kratos_kml_type_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $slug = isset($_POST['kratos_kml_type']) ? sanitize_key(wp_unslash($_POST['kratos_kml_type'])) : '';
    if ($slug === '') {
        wp_set_object_terms($post_id, array(), 'media_type', false);
        return;
    }
    if (term_exists($slug, 'media_type')) {
        wp_set_object_terms($post_id, $slug, 'media_type', false);
    }
}

/* ========== Term meta 后台 UI ========== */

add_action('media_type_add_form_fields', 'kratos_kml_term_add_fields');
function kratos_kml_term_add_fields()
{
    ?>
    <div class="form-field">
        <label for="kml_icon"><?php _e('图标', 'kratos'); ?></label>
        <input type="text" name="kml_icon" id="kml_icon" value="">
        <p><?php _e('支持 emoji（如 📖）或 Font Awesome 类名（如 fa-solid fa-book）。', 'kratos'); ?></p>
    </div>
    <div class="form-field">
        <label for="kml_color"><?php _e('色标', 'kratos'); ?></label>
        <input type="color" name="kml_color" id="kml_color" value="#999999">
        <p><?php _e('列表卡片左上角类型标签、详情页胶囊的底色。', 'kratos'); ?></p>
    </div>
    <div class="form-field">
        <label for="kml_order"><?php _e('排序', 'kratos'); ?></label>
        <input type="number" name="kml_order" id="kml_order" value="10">
        <p><?php _e('数字越小越靠前；列表 tab 从左到右的顺序。', 'kratos'); ?></p>
    </div>
    <div class="form-field">
        <label for="kml_unit"><?php _e('计数单位', 'kratos'); ?></label>
        <input type="text" name="kml_unit" id="kml_unit" value="">
        <p><?php _e('如 本 / 部 / 款 / 项，统计条文案"累计 54 本"用，不填则省略。', 'kratos'); ?></p>
    </div>
    <?php
}

add_action('media_type_edit_form_fields', 'kratos_kml_term_edit_fields', 10, 2);
function kratos_kml_term_edit_fields($term, $taxonomy)
{
    $m = kratos_kml_term_meta($term->term_id);
    ?>
    <tr class="form-field">
        <th><label for="kml_icon"><?php _e('图标', 'kratos'); ?></label></th>
        <td>
            <input type="text" name="kml_icon" id="kml_icon" value="<?php echo esc_attr($m['icon']); ?>">
            <p class="description"><?php _e('支持 emoji 或 Font Awesome 类名。', 'kratos'); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th><label for="kml_color"><?php _e('色标', 'kratos'); ?></label></th>
        <td>
            <input type="color" name="kml_color" id="kml_color" value="<?php echo esc_attr($m['color']); ?>">
            <p class="description"><?php _e('列表卡片类型标签底色。', 'kratos'); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th><label for="kml_order"><?php _e('排序', 'kratos'); ?></label></th>
        <td>
            <input type="number" name="kml_order" id="kml_order" value="<?php echo esc_attr($m['order']); ?>">
            <p class="description"><?php _e('数字越小越靠前。', 'kratos'); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th><label for="kml_unit"><?php _e('计数单位', 'kratos'); ?></label></th>
        <td>
            <input type="text" name="kml_unit" id="kml_unit" value="<?php echo esc_attr($m['unit']); ?>">
            <p class="description"><?php _e('如 本 / 部 / 款 / 项。', 'kratos'); ?></p>
        </td>
    </tr>
    <?php
}

add_action('created_media_type', 'kratos_kml_term_save');
add_action('edited_media_type',  'kratos_kml_term_save');
function kratos_kml_term_save($term_id)
{
    if (isset($_POST['kml_icon']))  update_term_meta($term_id, '_kml_icon',  sanitize_text_field(wp_unslash($_POST['kml_icon'])));
    if (isset($_POST['kml_color'])) update_term_meta($term_id, '_kml_color', sanitize_hex_color($_POST['kml_color']) ?: '#999999');
    if (isset($_POST['kml_order'])) update_term_meta($term_id, '_kml_order', intval($_POST['kml_order']));
    if (isset($_POST['kml_unit']))  update_term_meta($term_id, '_kml_unit',  sanitize_text_field(wp_unslash($_POST['kml_unit'])));
}

/**
 * 归一化读 term meta，缺省值兜底。
 *
 * @param int $term_id
 * @return array{icon:string,color:string,order:int,unit:string}
 */
function kratos_kml_term_meta($term_id)
{
    return array(
        'icon'  => (string) get_term_meta($term_id, '_kml_icon',  true),
        'color' => (string) (get_term_meta($term_id, '_kml_color', true) ?: '#999999'),
        'order' => (int)    get_term_meta($term_id, '_kml_order', true),
        'unit'  => (string) get_term_meta($term_id, '_kml_unit',  true),
    );
}

/**
 * 获取按 _kml_order asc 排序后的 media_type term 列表。
 *
 * @return WP_Term[]
 */
function kratos_kml_sorted_terms()
{
    $terms = get_terms(array(
        'taxonomy'   => 'media_type',
        'hide_empty' => false,
    ));
    if (is_wp_error($terms) || !is_array($terms)) return array();
    usort($terms, function ($a, $b) {
        $ao = (int) get_term_meta($a->term_id, '_kml_order', true);
        $bo = (int) get_term_meta($b->term_id, '_kml_order', true);
        if ($ao === $bo) return strcmp($a->name, $b->name);
        return $ao - $bo;
    });
    return $terms;
}

/* ========== CSF Metabox ========== */

if (class_exists('CSF')) {
    $prefix = '_kml_meta';
    CSF::createMetabox($prefix, array(
        'title'     => __('条目信息（观影读书录）', 'kratos'),
        'post_type' => 'media_item',
        'data_type' => 'unserialize',
        'context'   => 'normal',
        'priority'  => 'high',
        'theme'     => 'light',
    ));
    CSF::createSection($prefix, array(
        'fields' => array(
            array(
                'id'       => '_kml_cover',
                'type'     => 'text',
                'title'    => __('封面 URL', 'kratos'),
                'subtitle' => __('留空则回落到特色图；填值优先于特色图。', 'kratos'),
            ),
            array(
                'id'    => '_kml_orig_title',
                'type'  => 'text',
                'title' => __('原名', 'kratos'),
            ),
            array(
                'id'       => '_kml_creator',
                'type'     => 'text',
                'title'    => __('作者 / 导演 / 工作室', 'kratos'),
            ),
            array(
                'id'    => '_kml_year',
                'type'  => 'number',
                'title' => __('年份', 'kratos'),
            ),
            array(
                'id'       => '_kml_rating',
                'type'     => 'number',
                'title'    => __('个人评分', 'kratos'),
                'subtitle' => __('0-10，一位小数', 'kratos'),
                'default'  => '',
            ),
            array(
                'id'      => '_kml_status',
                'type'    => 'select',
                'title'   => __('状态', 'kratos'),
                'options' => array(
                    ''      => __('— 未设置', 'kratos'),
                    'done'  => __('已完成（已看/已读/已通关）', 'kratos'),
                    'doing' => __('进行中（在看/在读）', 'kratos'),
                    'plan'  => __('计划（想看/想读）', 'kratos'),
                ),
            ),
            array(
                'id'    => '_kml_finished_at',
                'type'  => 'date',
                'title' => __('完成日期', 'kratos'),
            ),
            array(
                'id'    => '_kml_douban_url',
                'type'  => 'text',
                'title' => __('豆瓣链接', 'kratos'),
            ),
            array(
                'id'    => '_kml_imdb_id',
                'type'  => 'text',
                'title' => __('IMDb ID', 'kratos'),
            ),
            array(
                'id'    => '_kml_isbn',
                'type'  => 'text',
                'title' => __('ISBN', 'kratos'),
            ),
            array(
                'type'    => 'subheading',
                'content' => __('划线与笔记', 'kratos'),
            ),
            array(
                'id'     => '_kml_highlights',
                'type'   => 'group',
                'title'  => __('划线条目', 'kratos'),
                'button_title' => __('新增一条', 'kratos'),
                'accordion_title_prefix' => __('划线 #', 'kratos'),
                'accordion_title_number' => true,
                'fields' => array(
                    array('id' => 'text', 'type' => 'textarea', 'title' => __('内容', 'kratos')),
                    array('id' => 'meta', 'type' => 'text',     'title' => __('页码 / 时间 / 位置', 'kratos'), 'subtitle' => __('例：P. 72 · 2024-03-10', 'kratos')),
                ),
            ),
            array(
                'type'    => 'subheading',
                'content' => __('关联博客文章', 'kratos'),
            ),
            array(
                'id'       => '_kml_related_posts',
                'type'     => 'select',
                'title'    => __('关联文章', 'kratos'),
                'subtitle' => __('手动挑选和本条目有关的博文；可多选', 'kratos'),
                'chosen'   => true,
                'ajax'     => true,
                'multiple' => true,
                'options'  => 'posts',
            ),
        ),
    ));
}

/* ========== 状态文案（按类型枚举映射） ==========
 * 底层只存 done/doing/plan 三态；前台按类型 slug 翻译成对应的「已看/已读/已通关」等。
 * 其它主题/插件可通过 'kratos_kml_status_labels' 过滤器扩展或改写。
 */
function kratos_kml_status_labels_by_type()
{
    $map = array(
        'book'  => array('done' => '已读',   'doing' => '在读',   'plan' => '想读'),
        'movie' => array('done' => '已看',   'doing' => '在看',   'plan' => '想看'),
        'tv'    => array('done' => '已追完', 'doing' => '在追',   'plan' => '想追'),
        'anime' => array('done' => '已看完', 'doing' => '在看',   'plan' => '想看'),
        'game'  => array('done' => '已通关', 'doing' => '在玩',   'plan' => '想玩'),
        'music' => array('done' => '已听',   'doing' => '在听',   'plan' => '想听'),
        'other' => array('done' => '已完成', 'doing' => '进行中', 'plan' => '计划'),
    );
    return apply_filters('kratos_kml_status_labels', $map);
}

/**
 * 根据状态 slug + 类型 term slug 返回展示文案；未匹配时回落到 other，仍无则空。
 */
function kratos_kml_status_label($status, $type_slug = '')
{
    if ($status === '' || $status === null) return '';
    $map = kratos_kml_status_labels_by_type();
    $type_slug = $type_slug ?: 'other';
    if (isset($map[$type_slug][$status])) return $map[$type_slug][$status];
    if (isset($map['other'][$status]))    return $map['other'][$status];
    return '';
}

/* ========== 条目 meta 读取 ========== */

/**
 * 归一化读条目字段。
 * CSF 的 unserialize data_type 会把这些 key 都塞进单个 '_kml_meta' 的 serialized 数组里，
 * 这里把它展开。
 */
function kratos_kml_item_meta($post_id)
{
    // CSF 的 data_type=unserialize 让每个字段独立成一条 post_meta（key 即字段 id），
    // 而豆瓣导入模块同时也写了 _kml_meta 聚合数组做兼容。读取时两边都查：
    // 优先独立 key，回落聚合数组。
    $keys = array('_kml_cover', '_kml_orig_title', '_kml_creator', '_kml_year', '_kml_rating', '_kml_status', '_kml_finished_at', '_kml_douban_url', '_kml_imdb_id', '_kml_isbn');
    $agg = get_post_meta($post_id, '_kml_meta', true);
    $agg = is_array($agg) ? $agg : array();
    $out = array();
    foreach ($keys as $k) {
        $v = get_post_meta($post_id, $k, true);
        if ($v === '' || $v === null) {
            $v = isset($agg[$k]) ? $agg[$k] : '';
        }
        $out[$k] = $v;
    }
    if ($out['_kml_cover'] === '' && has_post_thumbnail($post_id)) {
        $out['_kml_cover'] = get_the_post_thumbnail_url($post_id, 'large');
    }
    return $out;
}

/* ========== 工具：卡片渲染 ========== */

function kratos_kml_render_card($post_id)
{
    $m = kratos_kml_item_meta($post_id);
    $terms = get_the_terms($post_id, 'media_type');
    $term = (is_array($terms) && !empty($terms)) ? $terms[0] : null;
    $tm = $term ? kratos_kml_term_meta($term->term_id) : array('color' => '#999999', 'icon' => '');
    $status_cls = array('done' => '', 'doing' => 'is-doing', 'plan' => 'is-plan');
    $status = $m['_kml_status'];
    $status_text = $status ? kratos_kml_status_label($status, $term ? $term->slug : '') : '';
    $rating = $m['_kml_rating'];
    $cover  = $m['_kml_cover'];
    $sub = trim(implode(' · ', array_filter(array($m['_kml_creator'], $m['_kml_year']))));
    ?>
    <a class="kr-card kml-item" href="<?php echo esc_url(get_permalink($post_id)); ?>">
        <div class="kml-cover"<?php if ($cover) echo ' style="background-image:url(' . esc_url($cover) . ')"'; ?>>
            <?php if ($term): ?>
                <span class="kml-type-tag" style="background:<?php echo esc_attr($tm['color']); ?>"><?php echo esc_html($term->name); ?></span>
            <?php endif; ?>
            <?php if ($status_text): ?>
                <span class="kml-status <?php echo esc_attr($status_cls[$status] ?? ''); ?>"><?php echo esc_html($status_text); ?></span>
            <?php endif; ?>
            <span class="kml-rate">
                <?php if ($rating !== '' && $rating !== null): ?>
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z" fill="#e0a94f"/></svg>
                    <?php echo esc_html(number_format((float)$rating, 1)); ?>
                <?php else: ?>&mdash;<?php endif; ?>
            </span>
        </div>
        <div class="kml-info">
            <h3 class="kml-title"><?php echo esc_html(get_the_title($post_id)); ?></h3>
            <?php if ($sub !== ''): ?><div class="kml-sub"><?php echo esc_html($sub); ?></div><?php endif; ?>
        </div>
    </a>
    <?php
}

/* ========== 短码：[media_stats] ========== */

add_shortcode('media_stats', 'kratos_kml_stats_shortcode');
function kratos_kml_stats_shortcode($atts)
{
    global $wpdb;
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='media_item' AND post_status='publish'");
    $avg = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT AVG(CAST(pm_val AS DECIMAL(4,1))) FROM (
            SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, '\"_kml_rating\";s:', -1), '\"', 2) AS pm_val
            FROM $wpdb->postmeta pm
            INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s AND p.post_type='media_item' AND p.post_status='publish'
        ) t WHERE pm_val REGEXP '^[0-9]'",
        '_kml_meta'
    ));
    $year = (int) wp_date('Y');
    $year_count = 0;
    $doing = 0;
    $q = new WP_Query(array('post_type' => 'media_item', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids', 'no_found_rows' => true));
    foreach ($q->posts as $pid) {
        $m = kratos_kml_item_meta($pid);
        if (!empty($m['_kml_finished_at']) && strpos((string)$m['_kml_finished_at'], (string)$year) === 0) $year_count++;
        if ($m['_kml_status'] === 'doing') $doing++;
    }
    ob_start(); ?>
    <div class="kml-stats">
        <div class="kml-stat"><div class="kml-stat-num"><?php echo esc_html($total); ?></div><div class="kml-stat-label"><?php _e('累计条目', 'kratos'); ?></div></div>
        <div class="kml-stat"><div class="kml-stat-num"><?php echo $avg ? esc_html(number_format($avg, 1)) : '—'; ?></div><div class="kml-stat-label"><?php _e('平均评分', 'kratos'); ?></div></div>
        <div class="kml-stat"><div class="kml-stat-num"><?php echo esc_html($year_count); ?></div><div class="kml-stat-label"><?php _e('本年新增', 'kratos'); ?></div></div>
        <div class="kml-stat"><div class="kml-stat-num"><?php echo esc_html($doing); ?></div><div class="kml-stat-label"><?php _e('进行中', 'kratos'); ?></div></div>
    </div>
    <?php return ob_get_clean();
}

/* ========== 短码：[media_library] ========== */

add_shortcode('media_library', 'kratos_kml_library_shortcode');
function kratos_kml_library_shortcode($atts)
{
    // 短码未传时回落到「主题选项 → 页面与短码 → 观影读书录配置」
    $opt_title      = function_exists('kratos_option') ? kratos_option('media_sc_title', __('观影读书录', 'kratos')) : __('观影读书录', 'kratos');
    $opt_subtitle   = function_exists('kratos_option') ? kratos_option('media_sc_subtitle', __('记录读过的书、看过的影剧、玩过的游戏', 'kratos')) : __('记录读过的书、看过的影剧、玩过的游戏', 'kratos');
    $opt_icon       = function_exists('kratos_option') ? kratos_option('media_sc_icon', 'fa-solid fa-book') : 'fa-solid fa-book';
    $opt_per_page   = function_exists('kratos_option') ? (int) kratos_option('media_sc_per_page', 24) : 24;
    $opt_orderby    = function_exists('kratos_option') ? kratos_option('media_sc_orderby', 'date') : 'date';
    $opt_show_stats = function_exists('kratos_option') ? (bool) kratos_option('media_sc_show_stats', true) : true;

    $atts = shortcode_atts(array(
        'per_page'     => $opt_per_page,
        'default_type' => 'all',
        'orderby'      => $opt_orderby,
        'title'        => $opt_title,
        'subtitle'     => $opt_subtitle,
        'icon'         => $opt_icon,
        'show_stats'   => $opt_show_stats ? '1' : '0',
    ), $atts, 'media_library');

    $current = isset($_GET['mt']) ? sanitize_key($_GET['mt']) : $atts['default_type'];
    $paged = max(1, (int) (get_query_var('paged') ?: get_query_var('page') ?: (isset($_GET['pg']) ? intval($_GET['pg']) : 1)));

    $terms = kratos_kml_sorted_terms();

    $q_args = array(
        'post_type'      => 'media_item',
        'post_status'    => 'publish',
        'posts_per_page' => (int) $atts['per_page'],
        'paged'          => $paged,
        'orderby'        => $atts['orderby'],
        'order'          => 'DESC',
        // 必须开，不开 max_num_pages 恒为 0，分页按钮永远不出现，
        // 「每页条数」也就没有可感知的效果 —— kratos_lean_query_args 默认把它关了。
        'no_found_rows'  => false,
    );
    if ($current && $current !== 'all') {
        $q_args['tax_query'] = array(array(
            'taxonomy' => 'media_type',
            'field'    => 'slug',
            'terms'    => $current,
        ));
    }
    if (function_exists('kratos_lean_query_args')) {
        $q_args = kratos_lean_query_args($q_args);
    }
    $query = new WP_Query($q_args);

    // 切分类时回到第 1 页：get_pagenum_link(1) 自动剥掉 /page/N/ rewrite 段，
    // 再 remove_query_arg 去掉可能的 ?mt / ?pg / ?paged 参数，保证干净基座。
    $base_url = function_exists('get_pagenum_link') ? get_pagenum_link(1) : home_url(add_query_arg(null, null));
    $base_url = remove_query_arg(array('mt', 'pg', 'paged', 'page'), $base_url);
    ob_start(); ?>
    <div class="kratos-media-library">
        <?php if ($atts['title'] !== '' || $atts['subtitle'] !== ''): ?>
        <header class="kfl-header kr-hd">
            <?php if ($atts['title'] !== ''): ?>
            <span class="kfl-title-icon kr-ico" aria-hidden="true">
                <?php echo function_exists('kratos_fa_svg') ? kratos_fa_svg($atts['icon'] !== '' ? $atts['icon'] : 'fa-solid fa-book') : ''; ?>
            </span>
            <span class="kfl-title kr-hd-title"><?php echo esc_html($atts['title']); ?></span>
            <?php endif; ?>
            <?php if ($atts['subtitle'] !== ''): ?>
                <?php if ($atts['title'] !== ''): ?><span class="kfl-header-divider kr-hd-divider" aria-hidden="true"></span><?php endif; ?>
                <p class="kfl-subtitle kr-hd-sub"><?php echo esc_html($atts['subtitle']); ?></p>
            <?php endif; ?>
        </header>
        <?php endif; ?>

        <?php if ($atts['show_stats'] === '1' || $atts['show_stats'] === 1 || $atts['show_stats'] === true): ?>
            <?php echo do_shortcode('[media_stats]'); ?>
        <?php endif; ?>

        <div class="kml-toolbar">
            <div class="kml-tabs">
                <a class="kml-tab <?php echo ($current === 'all') ? 'is-active' : ''; ?>" href="<?php echo esc_url(remove_query_arg('mt', $base_url)); ?>"><?php _e('全部', 'kratos'); ?></a>
                <?php foreach ($terms as $t):
                    $tm = kratos_kml_term_meta($t->term_id);
                    $is = ($current === $t->slug);
                ?>
                    <a class="kml-tab <?php echo $is ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('mt', $t->slug, $base_url)); ?>"<?php if ($is) echo ' style="background:' . esc_attr($tm['color']) . ';border-color:' . esc_attr($tm['color']) . '"'; ?>>
                        <?php if ($tm['icon'] !== ''): ?><span class="kml-tab-icon"><?php echo esc_html($tm['icon']); ?></span><?php endif; ?>
                        <?php echo esc_html($t->name); ?>
                        <span class="kml-tab-count"><?php echo esc_html($t->count); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        (function(){
            // 移动端横向滚动的 tabs 列表，页面载入后把当前激活项滚到视图内，避免激活项被挤到屏外。
            var tabs = document.querySelector('.kratos-media-library .kml-tabs');
            if (!tabs) return;
            var active = tabs.querySelector('.kml-tab.is-active');
            if (!active) return;
            // 仅横向可滚动时才处理；桌面端 flex-wrap 不触发
            if (tabs.scrollWidth <= tabs.clientWidth) return;
            var target = active.offsetLeft - (tabs.clientWidth - active.offsetWidth) / 2;
            target = Math.max(0, Math.min(target, tabs.scrollWidth - tabs.clientWidth));
            tabs.scrollTo({ left: target, behavior: 'instant' in tabs.scrollTo ? 'instant' : 'auto' });
        })();
        </script>

        <?php if ($query->have_posts()): ?>
            <div class="kml-grid">
                <?php while ($query->have_posts()): $query->the_post(); kratos_kml_render_card(get_the_ID()); endwhile; ?>
            </div>

            <?php
            if ($query->max_num_pages > 1):
                $big = 999999999;
                $links = paginate_links(array(
                    'base'      => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
                    'format'    => '?paged=%#%',
                    'current'   => $paged,
                    'total'     => $query->max_num_pages,
                    'type'      => 'array',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ));
                if ($links): ?>
                    <div class="kml-page">
                        <?php foreach ($links as $link) echo str_replace(array('page-numbers', 'current'), array('', 'is-current'), $link); ?>
                    </div>
                <?php endif;
            endif; ?>
        <?php else: ?>
            <div class="kml-empty"><?php _e('这里还空着——先添加一个条目吧。', 'kratos'); ?></div>
        <?php endif; ?>
    </div>
    <?php
    wp_reset_postdata();
    return ob_get_clean();
}

/* ========== body_class 注入 ========== */

add_filter('body_class', 'kratos_kml_body_class');
function kratos_kml_body_class($classes)
{
    if (is_singular('media_item')) {
        $classes[] = 'is-kratos-media-single';
        return $classes;
    }
    if (is_tax('media_type') || is_post_type_archive('media_item')) {
        $classes[] = 'is-kratos-media-page';
        return $classes;
    }
    if (is_page_template('page-media-library.php')) {
        $classes[] = 'is-kratos-media-page';
        return $classes;
    }
    if (is_singular() && !is_feed()) {
        global $post;
        if ($post && (has_shortcode($post->post_content, 'media_library') || has_shortcode($post->post_content, 'media_stats'))) {
            $classes[] = 'is-kratos-media-page';
        }
    }
    return $classes;
}

/* ========== 入队：按需 ========== */

add_action('wp_enqueue_scripts', 'kratos_kml_enqueue', 20);
function kratos_kml_enqueue()
{
    $need = is_singular('media_item')
        || is_tax('media_type')
        || is_post_type_archive('media_item')
        || is_page_template('page-media-library.php');
    if (!$need && is_singular() && !is_feed()) {
        global $post;
        if ($post && (has_shortcode($post->post_content, 'media_library') || has_shortcode($post->post_content, 'media_stats'))) {
            $need = true;
        }
    }
    if (!$need) return;
    wp_enqueue_style(
        'kratos-media-library',
        get_template_directory_uri() . '/assets/css/kratos-media-library.css',
        array('kratos-components'),
        defined('THEME_VERSION') ? THEME_VERSION : null
    );
}

/* ========== 评论扩展：评分字段 ========== */

// 主题 comments.php 不走 WP 原生 comment_form() —— 只 do_action('comment_form_after')
// 和 'comment_form'（在 </form> 之前）。挂它才能让评分字段出现在条目评论表单里。
add_action('comment_form_after', 'kratos_kml_comment_rate_field');
function kratos_kml_comment_rate_field()
{
    if (get_post_type() !== 'media_item') return;
    ?>
    <?php
    // 10 档打分（含半星）对应文案；只给偶数（整星）配文案，半星态继承前一档。
    $labels = array(
        1 => '很差', 2 => '很差',
        3 => '较差', 4 => '较差',
        5 => '还行', 6 => '还行',
        7 => '推荐', 8 => '推荐',
        9 => '力荐', 10 => '力荐',
    );
    ?>
    <p class="kmd-cform-rate" data-top-only>
        <span class="kmd-cform-rate-label"><?php _e('我的评分', 'kratos'); ?></span>
        <span class="kml-rate-ui" role="radiogroup" aria-label="<?php esc_attr_e('为这个条目打分', 'kratos'); ?>">
            <?php for ($i = 1; $i <= 5; $i++):
                $full = $i * 2; $half = $full - 1;
            ?>
                <button type="button" class="kml-rate-star" data-full="<?php echo $full; ?>" data-half="<?php echo $half; ?>" aria-label="<?php echo esc_attr(sprintf(__('第 %d 颗星', 'kratos'), $i)); ?>">
                    <span class="kml-star">
                        <svg class="s-bg" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>
                        <svg class="s-fg" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>
                    </span>
                </button>
            <?php endfor; ?>
        </span>
        <span class="kml-rate-text" aria-live="polite"></span>
        <input type="hidden" name="kml_rate" id="kml_rate" value="0">
    </p>
    <script>
    (function(){
        var LABELS = <?php echo wp_json_encode($labels); ?>;
        var p = document.getElementById('comment_parent');
        var rateBox = document.querySelector('.kmd-cform-rate[data-top-only]');
        if (!rateBox) return;
        var ui = rateBox.querySelector('.kml-rate-ui');
        var hidden = document.getElementById('kml_rate');
        var textEl = rateBox.querySelector('.kml-rate-text');
        var stars = ui.querySelectorAll('.kml-rate-star');

        function paint(v) {
            stars.forEach(function(s){
                var full = parseInt(s.dataset.full, 10);
                var half = parseInt(s.dataset.half, 10);
                s.classList.remove('is-on', 'is-half');
                if (v >= full)      s.classList.add('is-on');
                else if (v >= half) s.classList.add('is-half');
            });
            textEl.textContent = v > 0 ? (LABELS[v] + ' · ' + (v/2).toFixed(1) + ' 星') : '';
        }
        function cur() { return parseInt(hidden.value, 10) || 0; }
        // 根据鼠标在星内的横向位置判定半 / 整星
        function valueAt(star, clientX) {
            var r = star.getBoundingClientRect();
            var isLeftHalf = (clientX - r.left) < r.width / 2;
            return isLeftHalf ? parseInt(star.dataset.half, 10) : parseInt(star.dataset.full, 10);
        }

        stars.forEach(function(s){
            s.addEventListener('mousemove', function(e){ paint(valueAt(s, e.clientX)); });
            s.addEventListener('focus',     function(){ paint(parseInt(s.dataset.full, 10)); });
            s.addEventListener('click', function(e){
                e.preventDefault();
                var v = valueAt(s, e.clientX);
                hidden.value = (cur() === v) ? 0 : v;  // 再点同一值 = 清除
                paint(cur());
            });
        });
        ui.addEventListener('mouseleave', function(){ paint(cur()); });

        // 顶级 / 回复态切换
        function sync() {
            var isReply = p && p.value && p.value !== '0';
            rateBox.style.display = isReply ? 'none' : '';
            if (isReply) { hidden.value = 0; paint(0); }
        }
        sync();
        if (p) {
            var obs = new MutationObserver(sync);
            obs.observe(p, { attributes: true, attributeFilter: ['value'] });
            p.addEventListener('change', sync);
        }
        document.addEventListener('click', function(e){
            var t = e.target;
            if (t && t.matches && (t.matches('.comment-reply-link, .comment-reply-link *, #cancel-comment-reply-link'))) {
                setTimeout(sync, 50);
            }
        }, true);
    })();
    </script>
    <?php
}

add_action('comment_post', 'kratos_kml_comment_rate_save', 10, 3);
function kratos_kml_comment_rate_save($comment_id, $approved, $commentdata)
{
    // 回复不计入评分（仅顶级评论持有本条目的打分）
    if (!empty($commentdata['comment_parent'])) return;
    if (get_post_type($commentdata['comment_post_ID']) !== 'media_item') return;
    if (!isset($_POST['kml_rate'])) return;
    $r = max(0, min(10, intval($_POST['kml_rate'])));
    if ($r > 0) {
        add_comment_meta($comment_id, '_kml_c_rate', $r);
    }
}

/**
 * 把评分徽章挂到评论作者链接之后。主题 comments.php 用 get_comment_author_link()
 * 输出作者（而不是 get_comment_author），且 WP 核心会在拼接 <a> 前把 author 字符串
 * esc_html，所以不能挂 get_comment_author 过滤器——会被转义成文本。改挂
 * get_comment_author_link，操作已经组装好的 <a> 标记，直接把徽章 HTML 追加到后面。
 *
 * 回复评论没有自己的分，也不显示父评论的分，避免混淆。
 * 支持半星：按 10 分制每 2 分一颗整星、每 1 分半颗星渲染。
 */
add_filter('get_comment_author_link', 'kratos_kml_comment_author_rate_link', 20, 3);
function kratos_kml_comment_author_rate_link($link, $author = '', $comment_id = 0)
{
    if (is_admin()) return $link;
    $comment = get_comment($comment_id);
    if (!$comment) return $link;
    if (get_post_type($comment->comment_post_ID) !== 'media_item') return $link;
    if (!empty($comment->comment_parent)) return $link;
    $r = (int) get_comment_meta($comment->comment_ID, '_kml_c_rate', true);
    if ($r <= 0) return $link;
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        $full = $i * 2;
        $half = $full - 1;
        $cls  = ($r >= $full) ? 'is-on' : (($r >= $half) ? 'is-half' : '');
        $stars .= '<span class="kml-c-rate-s ' . $cls . '">'
                . '<svg class="s-bg" viewBox="0 0 24 24" width="12" height="12"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>'
                . '<svg class="s-fg" viewBox="0 0 24 24" width="12" height="12"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>'
                . '</span>';
    }
    $num_text = (fmod($r, 2) === 0.0) ? ($r / 2) . '' : number_format($r / 2, 1);
    $badge = '<span class="kml-c-rate" title="' . esc_attr(sprintf(__('打分 %s / 5', 'kratos'), $num_text)) . '">'
           . '<span class="kml-c-rate-stars">' . $stars . '</span>'
           . '<span class="kml-c-rate-num">' . esc_html($num_text) . '</span>'
           . '</span>';
    return $link . $badge;
}
