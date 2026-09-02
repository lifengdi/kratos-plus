<?php
/**
 * AI 标签模块（M3）
 * - Meta Box：AI 建议 → 勾选 → 写入（永远二段式，不自动落库）
 * - REST：/tags/suggest /tags/apply
 * - term_meta：_kratos_ai_pending / _kratos_ai_source / _kratos_ai_created_ts
 * - post_meta：_kratos_ai_tag_pending（低权限用户 draft）/ _kratos_ai_tags_last_hash
 * - SEO 联动：term meta pending=1 时 seo_title/description 前端不生效
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_Tags {

    const META_TERM_PENDING = '_kratos_ai_pending';
    const META_TERM_SOURCE  = '_kratos_ai_source';
    const META_TERM_TS      = '_kratos_ai_created_ts';
    const META_TERM_SEO_T   = '_kratos_ai_seo_title';
    const META_TERM_SEO_D   = '_kratos_ai_seo_description';

    const META_POST_DRAFT = '_kratos_ai_tag_pending';
    const META_POST_HASH  = '_kratos_ai_tags_last_hash';

    public static function boot() {
        if (!kratos_ai_is_enabled()) return;
        if (!kratos_ai_opt('g_ai_tag_enable', 1)) return;

        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));

        // 待审的 AI 标签描述前端不输出（wp_insert_term 已把它写进 term 原生 description 字段，
        // 光靠 _kratos_ai_pending 这个 term meta 挡不住归档页渲染）
        add_filter('term_description', array(__CLASS__, 'filter_term_description'), 10, 4);
    }

    // ---------------- Meta Box ----------------

    public static function add_meta_box() {
        add_meta_box('kratos_ai_tags', __('AI 标签', 'kratos'), array(__CLASS__, 'render_meta_box'), 'post', 'side', 'default');
    }

    public static function render_meta_box($post) {
        wp_nonce_field('kratos_ai_meta_' . $post->ID, 'kratos_ai_tags_nonce');
        $draft = get_post_meta($post->ID, self::META_POST_DRAFT, true);
        $draft = is_array($draft) ? $draft : array();
        $can_create = current_user_can('manage_categories');
        ?>
        <div class="kratos-ai-tags-box" data-post="<?php echo (int)$post->ID; ?>" data-can-create="<?php echo $can_create ? 1 : 0; ?>">
            <p style="margin:4px 0 6px;color:#555;font-size:12px;">
                <?php _e('从正文抽取 5–8 个候选标签；勾选后写入。', 'kratos'); ?>
            </p>
            <p style="margin:6px 0;">
                <button type="button" class="button button-primary kratos-ai-tags-gen"><?php _e('生成建议', 'kratos'); ?></button>
                <span class="kratos-ai-tags-status" style="margin-left:8px;color:#666;font-size:12px;"></span>
            </p>
            <div class="kratos-ai-tags-list" style="max-height:280px;overflow:auto;border:1px solid #e5e5e5;border-radius:4px;padding:6px 8px;background:#fafafa;"></div>
            <p style="margin:8px 0;">
                <button type="button" class="button button-primary kratos-ai-tags-apply"><?php _e('应用勾选标签', 'kratos'); ?></button>
            </p>
            <?php if ($draft && $can_create): ?>
                <div style="margin-top:8px;padding:6px 8px;background:#fff8e5;border:1px solid #f0d786;border-radius:4px;font-size:12px;">
                    <strong><?php printf(esc_html__('待审 AI 标签建议：%d 条', 'kratos'), count($draft)); ?></strong>
                    <ul style="margin:4px 0 0 18px;padding:0;">
                        <?php foreach ($draft as $d): ?>
                            <li><?php echo esc_html($d['name']); ?>
                                <span style="color:#888;">/<?php echo esc_html($d['slug']); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin:6px 0 0;color:#8a6a00;"><?php _e('管理员可在生成后一并创建。', 'kratos'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function admin_assets($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) return;
        wp_enqueue_script(
            'kratos-ai-tags',
            get_template_directory_uri() . '/assets/js/ai-tags-panel.js',
            array('jquery', 'wp-api-fetch'),
            defined('THEME_VERSION') ? THEME_VERSION : '1.0',
            true
        );
        wp_localize_script('kratos-ai-tags', 'KratosAITags', array(
            'restRoot' => esc_url_raw(rest_url('kratos/v1/ai/')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'i18n' => array(
                'generating' => __('生成中…', 'kratos'),
                'error'      => __('生成失败', 'kratos'),
                'applied'    => __('已写入', 'kratos'),
                'noCreate'   => __('（新建标签需管理分类权限，已进入待审队列）', 'kratos'),
                'existing'   => __('已存在', 'kratos'),
                'newTag'     => __('新标签', 'kratos'),
                'noResult'   => __('未返回可用标签', 'kratos'),
            ),
        ));
    }

    // ---------------- REST ----------------

    public static function register_rest() {
        register_rest_route(Kratos_AI_REST::NAMESPACE_V1, '/tags/suggest', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_suggest'),
            'permission_callback' => array('Kratos_AI_REST', 'permission_edit_post'),
        ));
        register_rest_route(Kratos_AI_REST::NAMESPACE_V1, '/tags/apply', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_apply'),
            'permission_callback' => array('Kratos_AI_REST', 'permission_edit_post'),
        ));
    }

    public static function rest_suggest(WP_REST_Request $req) {
        $post_id = (int) $req->get_param('post_id');
        $post = get_post($post_id);
        if (!$post) return new WP_REST_Response(array('code' => 'ai_forbidden', 'message' => 'no post'), 400);
        if (!Kratos_AI_REST::rate_limit(get_current_user_id(), 'tags', 20)) {
            return new WP_REST_Response(array('code' => 'ai_rate_limited', 'message' => __('操作过快', 'kratos')), 429);
        }
        if (Kratos_AI_Client::monthly_exceeded() && !current_user_can('manage_options')) {
            return new WP_REST_Response(array('code' => 'ai_quota_exhausted', 'message' => __('本月 token 已达上限', 'kratos')), 429);
        }

        $chunks = Kratos_AI_Chunker::chunk($post, 'tags');
        $content = $chunks['chunks'][0];

        $r = Kratos_AI_Client::generate(array(
            'module' => 'tags',
            'prompt_key' => 'tags',
            'vars' => array('content' => $content),
            'post_id' => $post_id,
            'response_format' => 'json_object',
            'provider_slug' => kratos_ai_opt('g_ai_module_tags_provider', 'openai'),
            'fallback_slug' => kratos_ai_opt('g_ai_module_tags_fallback', ''),
            'temperature' => 0.2,
            'max_tokens' => 900,
        ));
        if (!$r['ok']) {
            return new WP_REST_Response(array('code' => $r['error']->get_error_code(), 'message' => $r['error']->get_error_message()), 400);
        }
        $parsed = Kratos_AI_Guards::validate_tags_json($r['text']);
        if (is_wp_error($parsed)) {
            return new WP_REST_Response(array('code' => 'ai_schema_invalid', 'message' => $parsed->get_error_message()), 400);
        }
        $max = max(1, (int) kratos_ai_opt('g_ai_tag_max_count', 8));
        $parsed = array_slice($parsed, 0, $max);
        $normalized = self::normalize_with_existing($parsed);
        $pricing = Kratos_AI_Client::pricing();
        $pricing_ver = isset($pricing['version']) ? $pricing['version'] : '';
        update_post_meta($post_id, self::META_POST_HASH, Kratos_AI_Chunker::content_hash($post));
        return array(
            'ok' => true,
            'tags' => $normalized,
            'can_create' => current_user_can('manage_categories'),
            'source' => sprintf('%s/%s@%s', $r['provider'], $r['model'], $pricing_ver),
        );
    }

    public static function rest_apply(WP_REST_Request $req) {
        $post_id = (int) $req->get_param('post_id');
        $picked  = (array) $req->get_param('tags');
        if (!$post_id || !$picked) return new WP_REST_Response(array('code' => 'ai_bad_params', 'message' => 'empty'), 400);
        $source = sanitize_text_field((string) $req->get_param('source'));
        $seo_fill = (bool) kratos_ai_opt('g_ai_tag_seo_fill', 1);

        $can_create = current_user_can('manage_categories');
        $applied = array();  // term ids
        $applied_terms = array();  // [{id,name,slug}]
        $draft = array();

        foreach ($picked as $t) {
            if (!is_array($t) || empty($t['name'])) continue;
            $name = sanitize_text_field($t['name']);
            $slug = isset($t['slug']) ? sanitize_title($t['slug']) : sanitize_title($name);
            $seo_title = isset($t['seo_title']) ? sanitize_text_field($t['seo_title']) : '';
            $desc = isset($t['description']) ? wp_strip_all_tags(preg_replace('#https?://\S+#i', '', (string)$t['description'])) : '';

            $term = self::find_existing($name, $slug);
            if ($term) {
                $applied[] = (int) $term->term_id;
                $applied_terms[] = array('id' => (int)$term->term_id, 'name' => $term->name, 'slug' => $term->slug);
                continue;
            }
            if (!$can_create) {
                $draft[] = array(
                    'name' => $name, 'slug' => $slug,
                    'seo_title' => $seo_title, 'description' => $desc,
                    'suggested_by' => get_current_user_id(),
                    'ts' => time(),
                );
                continue;
            }
            $created = wp_insert_term($name, 'post_tag', array('slug' => $slug, 'description' => $desc));
            if (is_wp_error($created)) continue;
            $tid = (int) $created['term_id'];
            $applied[] = $tid;
            $applied_terms[] = array('id' => $tid, 'name' => $name, 'slug' => $slug);
            update_term_meta($tid, self::META_TERM_TS, time());
            update_term_meta($tid, self::META_TERM_SOURCE, $source);
            if ($seo_fill) {
                update_term_meta($tid, self::META_TERM_PENDING, 1);
                if ($seo_title) update_term_meta($tid, self::META_TERM_SEO_T, $seo_title);
                if ($desc)      update_term_meta($tid, self::META_TERM_SEO_D, $desc);
            }
        }

        if ($applied) {
            wp_set_object_terms($post_id, $applied, 'post_tag', true);
        }
        if ($draft) {
            $existing = get_post_meta($post_id, self::META_POST_DRAFT, true);
            $existing = is_array($existing) ? $existing : array();
            update_post_meta($post_id, self::META_POST_DRAFT, array_merge($existing, $draft));
        } elseif ($can_create) {
            delete_post_meta($post_id, self::META_POST_DRAFT);
        }

        // 当前文章所有 post_tag（含刚写入的），便于编辑器全量同步
        $all_tag_ids = wp_get_object_terms($post_id, 'post_tag', array('fields' => 'ids'));
        $all_tag_ids = is_array($all_tag_ids) ? array_map('intval', $all_tag_ids) : array();

        return array(
            'ok' => true,
            'applied' => count($applied),
            'drafted' => count($draft),
            'applied_terms' => $applied_terms,
            'all_tag_ids' => $all_tag_ids,
        );
    }

    // ---------------- 归一化 ----------------

    /**
     * 与已有 post_tag 归一化：完全同名 / 大小写不敏感 / slug 命中 → 复用 term_id；否则 is_new=true
     */
    public static function normalize_with_existing($items) {
        $out = array();
        foreach ($items as $it) {
            $t = self::find_existing($it['name'], $it['slug']);
            $it['term_id'] = $t ? (int)$t->term_id : 0;
            $it['is_new'] = !$t;
            $it['description'] = isset($it['description']) ? wp_strip_all_tags(preg_replace('#https?://\S+#i', '', (string)$it['description'])) : '';
            $out[] = $it;
        }
        return $out;
    }

    /**
     * 名字/slug 命中已有 post_tag 时返回 WP_Term
     * 无需再全表扫一遍做 strcasecmp：MySQL 默认 *_ci collation 下 get_term_by('name') 本身就大小写不敏感。
     */
    public static function find_existing($name, $slug) {
        $by_slug = $slug ? get_term_by('slug', $slug, 'post_tag') : false;
        if ($by_slug) return $by_slug;
        $by_name = get_term_by('name', $name, 'post_tag');
        return $by_name ? $by_name : null;
    }

    // ---------------- SEO / 前端 ----------------

    /**
     * filter term_description：pending=1 的 AI 标签，前端不输出描述；后台编辑界面照常显示，
     * 否则管理员没法审阅、也改不了。
     */
    public static function filter_term_description($value, $term_id = 0, $taxonomy = '', $context = 'display') {
        if (!$value || $context === 'edit' || $context === 'raw' || is_admin()) return $value;
        if (get_term_meta($term_id, self::META_TERM_PENDING, true)) return '';
        return $value;
    }
}

add_action('init', array('Kratos_AI_Tags', 'boot'), 20);


/** CSF createOptions 的 menu_slug 与 AI 分区标题（统计窗口切换链接要拼回本分区） */
define('KRATOS_AI_MENU_PARENT', 'kratos-options');
define('KRATOS_AI_MENU_SECTION', 'AI 工具箱');
/** 本子分区标题；CSF 的子分区锚点是 sanitize_title(父) . '/' . sanitize_title(子) */
define('KRATOS_AI_MENU_SUBSECTION', 'AI 用量');

/**
 * AI 用量面板
 * 渲染在「主题选项 → AI 工具箱 → AI 用量」子分区里（CSF callback 字段），
 * 不再注册独立的 WP 后台菜单页。
 *
 * 注意：CSF 选项页整体包在一个 <form> 里，这里不能再嵌 <form>，
 * 统计窗口用链接切换（kratos_ai_days 查询参数）。
 */
function kratos_ai_render_usage_panel() {
    if (!current_user_can('manage_options')) return;
    if (!class_exists('Kratos_AI_Logger')) {
        echo '<p>' . esc_html__('SDK 未加载，先打开上方「AI 工具箱总开关」并保存。', 'kratos') . '</p>';
        return;
    }

    $windows = array(7, 30, 90, 365);
    $days = isset($_GET['kratos_ai_days']) ? (int) $_GET['kratos_ai_days'] : 30;
    if (!in_array($days, $windows, true)) $days = 30;

    $rows = Kratos_AI_Logger::usage_summary($days);
    $used = (int) get_option('kratos_ai_mtoken_' . gmdate('Ym'), 0);
    $cap  = (int) kratos_ai_opt('g_ai_monthly_token_limit', 0);
    $pricing = Kratos_AI_Client::pricing();
    $has_custom = isset($pricing['version']) && strpos((string)$pricing['version'], '+custom') !== false;
    // 必须带子分区那一段，否则锚点只到父分区、会落回第一个子分区（通用设置）
    $tab = '#tab=' . sanitize_title(KRATOS_AI_MENU_SECTION) . '/' . sanitize_title(KRATOS_AI_MENU_SUBSECTION);
    ?>
    <p style="margin:0 0 10px;">
        <strong><?php printf(esc_html__('本月已用 token：%s / %s', 'kratos'),
            number_format_i18n($used), $cap ? number_format_i18n($cap) : __('不限', 'kratos')); ?></strong>
    </p>

    <p style="margin:0 0 10px;">
        <?php esc_html_e('统计窗口：', 'kratos'); ?>
        <?php foreach ($windows as $w):
            $url = add_query_arg(array('page' => KRATOS_AI_MENU_PARENT, 'kratos_ai_days' => $w), admin_url('admin.php')) . $tab;
            ?>
            <?php if ($w === $days): ?>
                <strong style="margin-right:8px;"><?php printf(esc_html__('%d 天', 'kratos'), $w); ?></strong>
            <?php else: ?>
                <a href="<?php echo esc_url($url); ?>" style="margin-right:8px;"><?php printf(esc_html__('%d 天', 'kratos'), $w); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </p>

    <table class="widefat striped" style="margin-bottom:10px;">
        <thead><tr>
            <th><?php esc_html_e('模块', 'kratos'); ?></th>
            <th><?php esc_html_e('端点', 'kratos'); ?></th>
            <th><?php esc_html_e('模型', 'kratos'); ?></th>
            <th style="text-align:right;"><?php esc_html_e('输入 tokens', 'kratos'); ?></th>
            <th style="text-align:right;"><?php esc_html_e('输出 tokens', 'kratos'); ?></th>
            <th style="text-align:right;"><?php esc_html_e('花费 (USD)', 'kratos'); ?></th>
            <th style="text-align:right;"><?php esc_html_e('调用次数', 'kratos'); ?></th>
        </tr></thead>
        <tbody>
        <?php if ($rows):
            $eps = Kratos_AI::endpoints();
            $t_pt = $t_ct = $t_n = 0; $t_cost = 0.0;
            foreach ($rows as $r):
                $cost = Kratos_AI_Client::compute_cost($r->model, (int)$r->pt, (int)$r->ct);
                $t_pt += (int)$r->pt; $t_ct += (int)$r->ct; $t_n += (int)$r->n; $t_cost += $cost;
            ?>
            <tr>
                <td><?php echo esc_html($r->module); ?></td>
                <td><?php echo esc_html(isset($eps[$r->provider]) ? $eps[$r->provider]['label'] : $r->provider); ?></td>
                <td><?php echo esc_html($r->model); ?></td>
                <td style="text-align:right;"><?php echo number_format_i18n((int)$r->pt); ?></td>
                <td style="text-align:right;"><?php echo number_format_i18n((int)$r->ct); ?></td>
                <td style="text-align:right;">$<?php echo number_format($cost, 4); ?></td>
                <td style="text-align:right;"><?php echo (int)$r->n; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3"><strong><?php esc_html_e('合计', 'kratos'); ?></strong></td>
                <td style="text-align:right;"><strong><?php echo number_format_i18n($t_pt); ?></strong></td>
                <td style="text-align:right;"><strong><?php echo number_format_i18n($t_ct); ?></strong></td>
                <td style="text-align:right;"><strong>$<?php echo number_format($t_cost, 4); ?></strong></td>
                <td style="text-align:right;"><strong><?php echo (int)$t_n; ?></strong></td>
            </tr>
        <?php else: ?>
            <tr><td colspan="7" style="text-align:center;color:#999;"><?php esc_html_e('暂无数据', 'kratos'); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <p style="color:#666;margin:0;">
        <?php if ($has_custom): ?>
            <?php printf(esc_html__('花费按「通用设置 → 成本核算」里填写的单价实时计算；未列出的模型走默认单价 $%s / $%s（输入 / 输出，每 1K tokens）。', 'kratos'),
                esc_html($pricing['default']['input']), esc_html($pricing['default']['output'])); ?>
        <?php endif; ?>
        <?php printf(esc_html__('日志保留 %d 天，由每日 cron 清理。', 'kratos'), (int) kratos_ai_opt('g_ai_log_retention_days', 90)); ?>
    </p>
    <?php
}
