<?php
/**
 * AI 摘要模块（M2）
 * - Meta Box：生成 / 重生成 / 清空 / 保存 / 同步 excerpt
 * - REST：/summary/generate /summary/save /usage
 * - 前端卡片 .kratos-ai-summary：故意不挂公共类 kr-hd/kr-body（外壳自己承担全部视觉，
 *   挂了会被各皮肤的卡片规则套成"卡中卡"）；配色靠 --khs-* 别名跟皮肤走，
 *   根容器已登记在 assets/css/components.css 的两处 :is() 别名列表里
 * - 文章更新联动 flag/off
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_Summary {

    const META_HTML   = '_kratos_ai_summary';
    const META_STYLE  = '_kratos_ai_summary_style';
    const META_HASH   = '_kratos_ai_summary_hash';
    const META_SYNCED = '_kratos_ai_summary_synced_excerpt';
    const META_STATE  = '_kratos_ai_summary_state';

    public static function boot() {
        if (!kratos_ai_is_enabled()) return;
        if (!kratos_ai_opt('g_ai_sum_enable', 1)) return;

        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
        add_action('save_post', array(__CLASS__, 'on_save_post'), 20, 3);

        // 前端渲染
        if (kratos_ai_opt('g_ai_sum_frontend_show', 1)) {
            add_filter('the_content', array(__CLASS__, 'render_frontend'), 5);
        }

        // SEO 同步（excerpt）在保存时处理；SEO description 由 theme-seo.php 通过 filter 读 meta
        add_filter('get_the_excerpt', array(__CLASS__, 'maybe_use_ai_excerpt'), 20, 2);

        // Meta Box 静态资源
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));
    }

    // ---------------- Meta Box ----------------

    public static function add_meta_box() {
        $types = get_post_types(array('public' => true), 'names');
        unset($types['attachment']);
        foreach ($types as $t) {
            add_meta_box('kratos_ai_summary', __('AI摘要', 'kratos'), array(__CLASS__, 'render_meta_box'), $t, 'side', 'default');
        }
    }

    public static function render_meta_box($post) {
        $html = get_post_meta($post->ID, self::META_HTML, true);
        $style = get_post_meta($post->ID, self::META_STYLE, true) ?: kratos_ai_opt('g_ai_sum_style_default', 'classic');
        $state = get_post_meta($post->ID, self::META_STATE, true) ?: ($html ? 'fresh' : '');
        $synced = get_post_meta($post->ID, self::META_SYNCED, true);
        // 该文章从未设置过 → 用主题选项里的默认值
        if ($synced === '') $synced = kratos_ai_opt('g_ai_sum_sync_excerpt', 0);
        wp_nonce_field('kratos_ai_meta_' . $post->ID, 'kratos_ai_meta_nonce');
        ?>
        <div class="kratos-ai-sum-box" data-post="<?php echo (int)$post->ID; ?>">
            <p style="margin:4px 0 6px;">
                <label><strong><?php _e('风格预设', 'kratos'); ?></strong></label><br>
                <select name="kratos_ai_sum_style" class="widefat">
                    <option value="classic"<?php selected($style, 'classic'); ?>><?php _e('classic · 一段式（80–140 字）', 'kratos'); ?></option>
                    <option value="guide"<?php selected($style, 'guide'); ?>><?php _e('guide · 引导式 + 3 点收益', 'kratos'); ?></option>
                    <option value="tldr"<?php selected($style, 'tldr'); ?>><?php _e('tldr · 三行速读（≤60 字）', 'kratos'); ?></option>
                </select>
            </p>
            <p style="margin:0 0 6px;">
                <label><strong><?php _e('摘要 HTML', 'kratos'); ?></strong></label>
            </p>
            <textarea name="kratos_ai_sum_html" rows="8" class="widefat kratos-ai-sum-html" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.5;"><?php echo esc_textarea($html); ?></textarea>
            <p style="margin:6px 0;">
                <button type="button" class="button button-primary kratos-ai-sum-gen"><?php _e('生成 / 重生成', 'kratos'); ?></button>
                <button type="button" class="button kratos-ai-sum-save"><?php _e('保存摘要', 'kratos'); ?></button>
                <button type="button" class="button kratos-ai-sum-clear"><?php _e('清空', 'kratos'); ?></button>
            </p>
            <p style="margin:0 0 6px;color:#888;font-size:11px;">
                <?php _e('块编辑器下本面板不随「更新」提交，请用「保存摘要」按钮单独保存。', 'kratos'); ?>
            </p>
            <p style="margin:6px 0;">
                <label><input type="checkbox" name="kratos_ai_sum_sync_excerpt" value="1"<?php checked($synced); ?>> <?php _e('同步为文章摘录（post_excerpt）', 'kratos'); ?></label>
            </p>
            <p class="kratos-ai-sum-status" style="margin:6px 0;color:#666;font-size:12px;">
                <?php if ($state === 'stale'): ?>
                    <span style="color:#c25a00;">⚠ <?php _e('正文已变更，建议重新生成', 'kratos'); ?></span>
                <?php elseif ($state === 'fresh'): ?>
                    <span style="color:#2b7a2b;">✓ <?php _e('已同步最新正文', 'kratos'); ?></span>
                <?php elseif ($state === 'manual'): ?>
                    <span><?php _e('作者手动编辑', 'kratos'); ?></span>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    public static function admin_assets($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) return;
        wp_enqueue_script(
            'kratos-ai-summary',
            get_template_directory_uri() . '/assets/js/ai-summary-metabox.js',
            array('jquery', 'wp-api-fetch'),
            defined('THEME_VERSION') ? THEME_VERSION : '1.0',
            true
        );
        wp_localize_script('kratos-ai-summary', 'KratosAISummary', array(
            'restRoot' => esc_url_raw(rest_url('kratos/v1/ai/')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'i18n'     => array(
                'generating' => __('生成中…', 'kratos'),
                'error'      => __('生成失败', 'kratos'),
                'saved'      => __('已保存', 'kratos'),
                'cleared'    => __('已清空', 'kratos'),
                'confirmClear' => __('确定清空当前摘要？该操作会立即写入数据库。', 'kratos'),
            ),
        ));
    }

    public static function on_save_post($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (isset($_POST['kratos_ai_meta_nonce']) && wp_verify_nonce($_POST['kratos_ai_meta_nonce'], 'kratos_ai_meta_' . $post_id)) {
            if (!current_user_can('edit_post', $post_id)) return;
            if (isset($_POST['kratos_ai_sum_html'])) {
                $html = wp_unslash($_POST['kratos_ai_sum_html']);
                $clean = Kratos_AI_Guards::sanitize_html($html);
                update_post_meta($post_id, self::META_HTML, $clean);
                update_post_meta($post_id, self::META_STATE, 'manual');
            }
            if (isset($_POST['kratos_ai_sum_style'])) {
                $style = sanitize_key($_POST['kratos_ai_sum_style']);
                if (in_array($style, array('classic','guide','tldr'), true)) {
                    update_post_meta($post_id, self::META_STYLE, $style);
                }
            }
            $sync = !empty($_POST['kratos_ai_sum_sync_excerpt']);
            update_post_meta($post_id, self::META_SYNCED, $sync ? 1 : 0);
            if ($sync) {
                $html = get_post_meta($post_id, self::META_HTML, true);
                $plain = wp_strip_all_tags($html);
                if ($plain) {
                    remove_action('save_post', array(__CLASS__, 'on_save_post'), 20);
                    wp_update_post(array('ID' => $post_id, 'post_excerpt' => $plain));
                    add_action('save_post', array(__CLASS__, 'on_save_post'), 20, 3);
                }
            }
        }

        // hash 变更联动
        $new_hash = Kratos_AI_Chunker::content_hash($post);
        $old_hash = get_post_meta($post_id, self::META_HASH, true);
        if (!get_post_meta($post_id, self::META_HTML, true)) return;
        if ($new_hash === $old_hash) return;
        // 作者手工编辑过的摘要不再被标 stale，否则「manual」状态会被立刻覆盖
        if (get_post_meta($post_id, self::META_STATE, true) === 'manual') return;
        if (kratos_ai_opt('g_ai_sum_on_update', 'flag') === 'flag') {
            update_post_meta($post_id, self::META_STATE, 'stale');
        }
    }

    public static function maybe_use_ai_excerpt($excerpt, $post = null) {
        $p = $post ?: get_post();
        if (!$p) return $excerpt;
        if (!get_post_meta($p->ID, self::META_SYNCED, true)) return $excerpt;
        $html = get_post_meta($p->ID, self::META_HTML, true);
        if (!$html) return $excerpt;
        return wp_strip_all_tags($html);
    }

    // ---------------- REST ----------------

    public static function register_rest() {
        register_rest_route(Kratos_AI_REST::NAMESPACE_V1, '/summary/generate', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_generate'),
            'permission_callback' => array('Kratos_AI_REST', 'permission_edit_post'),
        ));
        register_rest_route(Kratos_AI_REST::NAMESPACE_V1, '/summary/save', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_save'),
            'permission_callback' => array('Kratos_AI_REST', 'permission_edit_post'),
        ));
        register_rest_route(Kratos_AI_REST::NAMESPACE_V1, '/usage', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'rest_usage'),
            'permission_callback' => array('Kratos_AI_REST', 'permission_manage_options'),
        ));
    }

    public static function rest_generate(WP_REST_Request $req) {
        $post_id = (int) $req->get_param('post_id');
        $style = sanitize_key($req->get_param('style'));
        if (!in_array($style, array('classic','guide','tldr'), true)) {
            $style = get_post_meta($post_id, self::META_STYLE, true) ?: kratos_ai_opt('g_ai_sum_style_default', 'classic');
        }
        if (!Kratos_AI_REST::rate_limit(get_current_user_id(), 'summary', 20)) {
            return new WP_REST_Response(array('code' => 'ai_rate_limited', 'message' => __('操作过快，稍后再试', 'kratos')), 429);
        }
        if (Kratos_AI_Client::monthly_exceeded() && !current_user_can('manage_options')) {
            return new WP_REST_Response(array('code' => 'ai_quota_exhausted', 'message' => __('本月 token 已达上限', 'kratos')), 429);
        }
        $result = self::generate_for_post($post_id, $style);
        if (is_wp_error($result)) {
            return new WP_REST_Response(array('code' => $result->get_error_code(), 'message' => $result->get_error_message()), 400);
        }
        return array(
            'ok' => true,
            'html' => $result['html'],
            'style' => $style,
            'state' => 'fresh',
        );
    }

    public static function rest_save(WP_REST_Request $req) {
        $post_id = (int) $req->get_param('post_id');
        if (!get_post($post_id)) {
            return new WP_REST_Response(array('code' => 'ai_forbidden', 'message' => 'no post'), 400);
        }
        $html = (string) $req->get_param('html');
        $style = sanitize_key($req->get_param('style'));
        $sync  = (bool) $req->get_param('sync_excerpt');
        $clean = Kratos_AI_Guards::sanitize_html($html);
        if ($clean === '') {
            delete_post_meta($post_id, self::META_HTML);
            delete_post_meta($post_id, self::META_STATE);
            delete_post_meta($post_id, self::META_HASH);
        } else {
            update_post_meta($post_id, self::META_HTML, $clean);
            update_post_meta($post_id, self::META_STATE, 'manual');
        }
        if (in_array($style, array('classic','guide','tldr'), true)) {
            update_post_meta($post_id, self::META_STYLE, $style);
        }
        update_post_meta($post_id, self::META_SYNCED, $sync ? 1 : 0);
        if ($sync && $clean !== '') {
            remove_action('save_post', array(__CLASS__, 'on_save_post'), 20);
            wp_update_post(array('ID' => $post_id, 'post_excerpt' => wp_strip_all_tags($clean)));
            add_action('save_post', array(__CLASS__, 'on_save_post'), 20, 3);
        }
        return array('ok' => true, 'html' => $clean);
    }

    public static function rest_usage(WP_REST_Request $req) {
        $days = (int) $req->get_param('days');
        $days = $days > 0 ? $days : 30;
        $rows = Kratos_AI_Logger::usage_summary($days);
        foreach ((array)$rows as $row) {
            // 用当前单价重算，与用量报表页一致
            $row->cost = Kratos_AI_Client::compute_cost($row->model, (int)$row->pt, (int)$row->ct);
        }
        return array(
            'ok' => true,
            'days' => $days,
            'summary' => $rows,
            'monthly_used' => (int) get_option('kratos_ai_mtoken_' . gmdate('Ym'), 0),
            'monthly_cap' => (int) kratos_ai_opt('g_ai_monthly_token_limit', 0),
        );
    }

    // ---------------- 核心生成 ----------------

    /** @return array{html:string}|WP_Error */
    public static function generate_for_post($post_id, $style) {
        $post = get_post($post_id);
        if (!$post) return new WP_Error('ai_forbidden', 'no post');
        $normalized = Kratos_AI_Chunker::normalize($post->post_content);
        if (!$normalized) return new WP_Error('ai_content_too_short', __('正文为空', 'kratos'));

        $per_task = (int) kratos_ai_opt('g_ai_input_token_cap_per_task', 128000);
        if (Kratos_AI_Prompt::count_tokens($normalized) > $per_task) {
            return new WP_Error('ai_content_too_long', __('正文超出单任务 token 上限', 'kratos'));
        }

        $chunks = Kratos_AI_Chunker::chunk($post, 'summary');
        // base_url / api_key / model 交给 Client 按 provider slug 自己取，模块层不传
        $provider_slug = kratos_ai_opt('g_ai_module_summary_provider', 'openai');
        $fallback_slug = kratos_ai_opt('g_ai_module_summary_fallback', '');

        $texts = array();
        foreach ($chunks['chunks'] as $chunk) {
            $r = Kratos_AI_Client::generate(array(
                'module' => 'summary',
                'prompt_key' => 'summary-' . $style,
                'vars' => array('content' => $chunk),
                'post_id' => $post_id,
                'provider_slug' => $provider_slug,
                'fallback_slug' => $fallback_slug,
                'max_tokens' => 700,
            ));
            if (!$r['ok']) return $r['error'];
            $texts[] = $r['text'];
        }
        $merged = count($texts) > 1
            ? self::reduce_summaries($texts, $style, $provider_slug, $fallback_slug, $post_id)
            : $texts[0];
        if ($merged instanceof WP_Error) return $merged;

        $clean = Kratos_AI_Guards::sanitize_html($merged);
        if (!$clean) return new WP_Error('ai_schema_invalid', __('生成结果为空或被三闸剥空', 'kratos'));

        update_post_meta($post_id, self::META_HTML, $clean);
        update_post_meta($post_id, self::META_STYLE, $style);
        update_post_meta($post_id, self::META_HASH, Kratos_AI_Chunker::content_hash($post));
        update_post_meta($post_id, self::META_STATE, 'fresh');
        return array('html' => $clean);
    }

    protected static function reduce_summaries($texts, $style, $primary, $fallback, $post_id) {
        $joined = "以下是同一篇文章各段的初步摘要，请合并为一篇最终摘要，保持原风格 [{$style}] 的字数与结构要求：\n\n" . implode("\n\n---\n\n", $texts);
        $r = Kratos_AI_Client::generate(array(
            'module' => 'summary',
            'prompt_key' => 'summary-' . $style,
            'vars' => array('content' => $joined),
            'post_id' => $post_id,
            'provider_slug' => $primary,
            'fallback_slug' => $fallback,
            'max_tokens' => 700,
        ));
        if (!$r['ok']) return $r['error'];
        return $r['text'];
    }

    // ---------------- 前端渲染 ----------------

    public static function render_frontend($content) {
        if (!is_singular() || !in_the_loop() || !is_main_query()) return $content;
        $post_id = get_the_ID();
        $html = get_post_meta($post_id, self::META_HTML, true);
        if (!$html) return $content;
        // 打字机脚本：仅在真实渲染卡片的页面注入
        wp_enqueue_script(
            'kratos-ai-summary-typewriter',
            get_template_directory_uri() . '/assets/js/ai-summary-typewriter.js',
            array(),
            defined('THEME_VERSION') ? THEME_VERSION : '1.0',
            true
        );
        $card = self::build_card($post_id, $html);
        return $card . $content;
    }

    public static function build_card($post_id, $html) {
        $style = get_post_meta($post_id, self::META_STYLE, true) ?: 'classic';
        $state = get_post_meta($post_id, self::META_STATE, true);
        $stale_note = ($state === 'stale')
            ? '<div class="kratos-ai-summary-stale">' . esc_html__('提示：正文已变更，站长可能会重新生成摘要。', 'kratos') . '</div>'
            : '';
        $safe = Kratos_AI_Guards::sanitize_html($html);
        $safe = apply_filters('kratos_ai_summary_html', $safe, $post_id, $style);
        $out  = '<section class="kratos-ai-summary kratos-ai-summary--' . esc_attr($style) . '" data-post="' . (int)$post_id . '">';
        $out .= '<div class="kratos-ai-summary-hd"><span class="kratos-ai-summary-title">' . esc_html__('AI摘要', 'kratos') . '</span></div>';
        $out .= '<div class="kratos-ai-summary-body">' . $safe . $stale_note . '</div>';
        $out .= '</section>';
        return $out;
    }
}

add_action('init', array('Kratos_AI_Summary', 'boot'), 20);
