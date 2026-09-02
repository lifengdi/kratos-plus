<?php
/**
 * Kratos_AI — SDK bootstrap
 * 只在 g_ai_enable=on 时被 inc/theme-ai.php require。
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI {

    const OPT_KEY_OPENAI = 'kratos_ai_key_openai';
    const OPT_KEY_ALT    = 'kratos_ai_key_openai_alt';

    /**
     * slug => 该端点在 CSF options 里的字段前缀 + 密文 option 名
     * 两个端点都跑 OpenAI 兼容协议，区别只在 base_url / key / model。
     */
    public static function endpoints() {
        return array(
            'openai'     => array('base' => 'g_ai_provider_openai_base', 'model' => 'g_ai_provider_openai_model', 'key_opt' => self::OPT_KEY_OPENAI,  'label' => __('主端点', 'kratos')),
            'openai_alt' => array('base' => 'g_ai_provider_alt_base',    'model' => 'g_ai_provider_alt_model',    'key_opt' => self::OPT_KEY_ALT,     'label' => __('备用端点', 'kratos')),
        );
    }

    public static function boot() {
        $dir = trailingslashit(get_template_directory()) . 'inc/ai/';
        require_once $dir . 'class-ai-crypto.php';
        require_once $dir . 'class-ai-cache.php';
        require_once $dir . 'class-ai-prompt.php';
        require_once $dir . 'class-ai-logger.php';
        require_once $dir . 'class-ai-ssrf.php';
        require_once $dir . 'class-ai-guards.php';
        require_once $dir . 'class-ai-chunker.php';
        require_once $dir . 'providers/openai.php';
        require_once $dir . 'class-ai-client.php';
        require_once $dir . 'class-ai-rest.php';

        Kratos_AI_Logger::maybe_install();

        add_filter('pre_http_request', array('Kratos_AI_SSRF', 'pre_http_request'), 10, 3);

        if (!wp_next_scheduled('kratos_ai_daily_gc')) {
            wp_schedule_event(time() + 300, 'daily', 'kratos_ai_daily_gc');
        }
        add_action('kratos_ai_daily_gc', array(__CLASS__, 'daily_gc'));

        if (defined('WP_CLI') && WP_CLI) {
            require_once $dir . 'wp-cli.php';
        }

        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'register_eraser'));
    }

    public static function daily_gc() {
        $days = (int) kratos_ai_opt('g_ai_log_retention_days', 90);
        Kratos_AI_Logger::purge($days);
    }

    /**
     * 从主题选项读取指定端点的配置（含 API Key 解密）
     * 未登记的 slug 返回空配置，调用方会得到 ai_key_missing。
     * @return array{base_url:string,api_key:string,model:string,slug:string}
     */
    public static function provider_config($slug = 'openai') {
        $eps = self::endpoints();
        if (!isset($eps[$slug])) {
            return array('slug' => $slug, 'base_url' => '', 'model' => '', 'api_key' => '');
        }
        $ep = $eps[$slug];
        $base  = esc_url_raw((string) kratos_ai_opt($ep['base'], ''));
        $model = sanitize_text_field((string) kratos_ai_opt($ep['model'], ''));
        $enc = get_option($ep['key_opt'], '');
        $key = $enc ? Kratos_AI_Crypto::decrypt($enc) : '';
        return array(
            'slug' => $slug,
            'base_url' => $base,
            // 主端点保留 gpt-4o-mini 兜底；备用端点不兜底，留空即视为未配置
            'model' => $model ?: ($slug === 'openai' ? 'gpt-4o-mini' : ''),
            'api_key' => (string)$key,
        );
    }

    public static function save_api_key($slug, $plaintext) {
        $eps = self::endpoints();
        if (!isset($eps[$slug])) return false;
        $ct = Kratos_AI_Crypto::encrypt($plaintext);
        if (is_wp_error($ct)) return $ct;
        update_option($eps[$slug]['key_opt'], $ct, false);
        Kratos_AI_Crypto::wipe($plaintext);
        return true;
    }

    public static function register_exporter($exporters) {
        $exporters['kratos-ai'] = array(
            'exporter_friendly_name' => __('Kratos AI 日志', 'kratos'),
            'callback' => array(__CLASS__, 'privacy_export'),
        );
        return $exporters;
    }

    public static function register_eraser($erasers) {
        $erasers['kratos-ai'] = array(
            'eraser_friendly_name' => __('Kratos AI 日志', 'kratos'),
            'callback' => array(__CLASS__, 'privacy_erase'),
        );
        return $erasers;
    }

    public static function privacy_export($email, $page = 1) {
        global $wpdb;
        $user = get_user_by('email', $email);
        $data_to_export = array();
        if ($user) {
            $t = Kratos_AI_Logger::table();
            $rows = $wpdb->get_results($wpdb->prepare("SELECT ts,module,provider,model,status,target_id FROM {$t} WHERE user_id=%d", $user->ID));
            $items = array();
            foreach ((array)$rows as $r) {
                $items[] = array(
                    'name' => 'kratos_ai_log#' . $r->ts,
                    'value' => sprintf('module=%s provider=%s model=%s status=%s target=%d',
                        $r->module, $r->provider, $r->model, $r->status, $r->target_id),
                );
            }
            if ($items) {
                $data_to_export[] = array(
                    'group_id' => 'kratos-ai-log',
                    'group_label' => __('Kratos AI 日志', 'kratos'),
                    'item_id' => 'kratos-ai-log-' . $user->ID,
                    'data' => $items,
                );
            }
        }
        return array('data' => $data_to_export, 'done' => true);
    }

    public static function privacy_erase($email, $page = 1) {
        $user = get_user_by('email', $email);
        $removed = 0;
        if ($user) {
            Kratos_AI_Logger::purge_user($user->ID);
            $removed = 1;
        }
        return array(
            'items_removed' => (bool)$removed,
            'items_retained' => false,
            'messages' => array(),
            'done' => true,
        );
    }
}
