<?php
/**
 * Kratos_AI_Client — 编排层：三闸 + 缓存 + 幂等 + fallback + 日志
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_Client {

    /** slug => Kratos_AI_Provider 实例 */
    protected static $providers = null;

    public static function providers() {
        if (self::$providers === null) {
            $list = array();
            foreach (array_keys(Kratos_AI::endpoints()) as $slug) {
                $list[$slug] = new Kratos_AI_Provider_OpenAI($slug);
            }
            $list = apply_filters('kratos_ai_providers', $list);
            self::$providers = $list;
        }
        return self::$providers;
    }

    public static function get_provider($slug) {
        $p = self::providers();
        return isset($p[$slug]) ? $p[$slug] : null;
    }

    /**
     * 通用生成入口：模块层用
     * @param array $args {
     *   module: 'summary'|'tags',
     *   prompt_key: string,
     *   vars: array,
     *   post_id: int,
     *   response_format?: 'json_object',
     *   temperature?: float,
     *   max_tokens?: int,
     *   provider_slug?: string,
     *   fallback_slug?: string,
     *   model?: string,
     *   base_url?: string,
     *   api_key?: string,
     * }
     * @return array{ok:bool,text:string,usage:array,model:string,provider:string,error:?WP_Error}
     */
    public static function generate($args) {
        $module = isset($args['module']) ? $args['module'] : 'generic';
        $post_id = isset($args['post_id']) ? (int)$args['post_id'] : 0;
        $prompt_key = isset($args['prompt_key']) ? $args['prompt_key'] : '';
        $vars = isset($args['vars']) ? (array)$args['vars'] : array();
        $content = isset($vars['content']) ? (string)$vars['content'] : '';

        // Per-request token cap
        $cap_req  = (int) kratos_ai_opt('g_ai_input_token_cap_per_request', 32000);
        if (Kratos_AI_Prompt::count_tokens($content) > $cap_req) {
            return self::fail('ai_content_too_long', 'input exceeds per_request cap');
        }
        // Input compliance
        $chk = Kratos_AI_Guards::compliance($content, 'input');
        if (is_wp_error($chk)) {
            self::log_row($module, '', '', 0, 0, 'ai_moderation_blocked', $post_id, $chk->get_error_message());
            return self::fail('ai_moderation_blocked', $chk->get_error_message());
        }

        $rendered = Kratos_AI_Prompt::render($prompt_key, $vars);
        $messages = array();
        if ($rendered['system']) $messages[] = array('role' => 'system', 'content' => $rendered['system']);
        $messages[] = array('role' => 'user', 'content' => $rendered['user']);

        $primary_slug  = self::normalize_slug(isset($args['provider_slug']) ? $args['provider_slug'] : 'openai', 'openai');
        $fallback_slug = self::normalize_slug(isset($args['fallback_slug']) ? $args['fallback_slug'] : '', '');
        // model 留空则取该端点自己配置的模型；先解出来，缓存键才能区分不同模型
        $model = isset($args['model']) ? (string)$args['model'] : '';
        if ($model === '') {
            $cfg = Kratos_AI::provider_config($primary_slug);
            $model = $cfg['model'];
        }

        $opts = array(
            'model'           => $model,
            'temperature'     => isset($args['temperature']) ? (float)$args['temperature'] : (float)kratos_ai_opt('g_ai_temperature', 0.4),
            'response_format' => isset($args['response_format']) ? $args['response_format'] : null,
            'max_tokens'      => isset($args['max_tokens']) ? (int)$args['max_tokens'] : 0,
            'base_url'        => isset($args['base_url']) ? $args['base_url'] : '',
            'api_key'         => isset($args['api_key']) ? $args['api_key'] : '',
            'idempotency_key' => Kratos_AI_Cache::idempotency_key($module, $post_id, sha1($rendered['user'])),
            'timeout'         => 60,
        );

        // Cache hit
        $cache_key = Kratos_AI_Cache::key($primary_slug, $opts['model'] ?: 'default', $prompt_key, sha1($rendered['user']));
        $cached = Kratos_AI_Cache::get($cache_key);
        if (is_array($cached) && !empty($cached['ok'])) {
            $cached['cached'] = true;
            return $cached;
        }

        $result = self::dispatch($primary_slug, $messages, $opts);
        if (!$result['ok'] && $fallback_slug && $fallback_slug !== $primary_slug) {
            $code = $result['error'] instanceof WP_Error ? $result['error']->get_error_code() : '';
            if (in_array($code, array('ai_provider_5xx','ai_provider_timeout','ai_quota_exhausted'), true)) {
                $result = self::dispatch($fallback_slug, $messages, $opts);
            }
        }

        if (!$result['ok']) {
            $err = $result['error'] instanceof WP_Error ? $result['error'] : new WP_Error('ai_provider_5xx', 'unknown');
            self::log_row($module, $result['provider'], $result['model'], 0, 0, $err->get_error_code(), $post_id, $err->get_error_message());
            return array(
                'ok' => false, 'text' => '', 'usage' => array(),
                'model' => $result['model'], 'provider' => $result['provider'],
                'error' => $err,
            );
        }

        // Output compliance
        $out_chk = Kratos_AI_Guards::compliance($result['text'], 'output');
        if (is_wp_error($out_chk)) {
            self::log_row($module, $result['provider'], $result['model'],
                (int)($result['usage']['prompt_tokens'] ?? 0),
                (int)($result['usage']['completion_tokens'] ?? 0),
                'ai_moderation_blocked', $post_id, $out_chk->get_error_message());
            return self::fail('ai_moderation_blocked', $out_chk->get_error_message());
        }

        $pt = (int)($result['usage']['prompt_tokens'] ?? 0);
        $ct = (int)($result['usage']['completion_tokens'] ?? 0);
        $cost = self::compute_cost($result['model'], $pt, $ct);
        self::log_row($module, $result['provider'], $result['model'], $pt, $ct, 'ok', $post_id, '');
        self::accumulate_monthly_tokens($pt + $ct);

        $final = array(
            'ok' => true,
            'text' => $result['text'],
            'usage' => $result['usage'],
            'model' => $result['model'],
            'provider' => $result['provider'],
            'cost_usd' => $cost,
            'error' => null,
            'cached' => false,
        );
        Kratos_AI_Cache::set($cache_key, $final);
        return $final;
    }

    /** 已注册的 provider slug 才放行，否则回落 $default（防止旧选项值/脏数据打到不存在的端点） */
    protected static function normalize_slug($slug, $default = 'openai') {
        $slug = is_string($slug) ? trim($slug) : '';
        if ($slug === '') return $default;
        $registered = self::providers();
        return isset($registered[$slug]) ? $slug : $default;
    }

    protected static function dispatch($slug, $messages, $opts) {
        $provider = self::get_provider($slug);
        if (!$provider) {
            return array(
                'ok' => false, 'text' => '', 'usage' => array(), 'model' => '',
                'provider' => $slug,
                'error' => new WP_Error('ai_provider_5xx', 'provider not registered: ' . $slug),
            );
        }
        if (Kratos_AI_Cache::is_cooling($slug)) {
            return array(
                'ok' => false, 'text' => '', 'usage' => array(), 'model' => $opts['model'],
                'provider' => $slug,
                'error' => new WP_Error('ai_quota_exhausted', 'provider cooling'),
            );
        }
        // 凭据按端点取：fallback 必须用它自己那套 base_url/key/model，
        // 沿用主端点的凭据等于拿 A 的 key 去打 B 的地址。
        // 经 kratos_ai_providers filter 注册的第三方 provider 不在 endpoints() 里，保留调用方传入的 opts。
        $eps = Kratos_AI::endpoints();
        if (isset($eps[$slug])) {
            $cfg = Kratos_AI::provider_config($slug);
            $opts['base_url'] = $cfg['base_url'];
            $opts['api_key']  = $cfg['api_key'];
            if (empty($opts['model'])) $opts['model'] = $cfg['model'];
        }
        $r = $provider->chat($messages, $opts);
        $r['provider'] = $slug;
        return $r;
    }

    protected static function fail($code, $msg) {
        return array(
            'ok' => false, 'text' => '', 'usage' => array(),
            'model' => '', 'provider' => '',
            'error' => new WP_Error($code, $msg),
        );
    }

    public static function compute_cost($model, $pt, $ct) {
        $pricing = self::pricing();
        $models = isset($pricing['models']) ? $pricing['models'] : array();
        $rate = isset($models[$model]) ? $models[$model] : (isset($pricing['default']) ? $pricing['default'] : array());
        $in  = isset($rate['input'])  ? (float)$rate['input']  : 0.0;
        $out = isset($rate['output']) ? (float)$rate['output'] : 0.0;
        return round(($pt / 1000.0) * $in + ($ct / 1000.0) * $out, 6);
    }

    /**
     * 单价表：内置默认值 → 主题选项里用户填的单价覆盖 → filter
     * 用户填的是「USD / 1K tokens」，与 compute_cost() 的算法一致。
     */
    public static function pricing() {
        static $cached = null;
        if ($cached !== null) return $cached;

        $p = include trailingslashit(get_template_directory()) . 'inc/ai/pricing.php';
        if (!is_array($p)) $p = array('version' => '', 'default' => array('input' => 0, 'output' => 0), 'models' => array());
        if (!isset($p['models']) || !is_array($p['models'])) $p['models'] = array();

        $rows = kratos_ai_opt('g_ai_pricing', array());
        $custom = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $model = isset($row['model']) ? trim((string)$row['model']) : '';
                if ($model === '') continue;
                $p['models'][$model] = array(
                    'input'  => (float) (isset($row['input']) ? $row['input'] : 0),
                    'output' => (float) (isset($row['output']) ? $row['output'] : 0),
                );
                $custom++;
            }
        }
        $din = kratos_ai_opt('g_ai_pricing_default_in', null);
        $dout = kratos_ai_opt('g_ai_pricing_default_out', null);
        if ($din !== null || $dout !== null) {
            $p['default'] = array(
                'input'  => (float) ($din !== null ? $din : $p['default']['input']),
                'output' => (float) ($dout !== null ? $dout : $p['default']['output']),
            );
        }
        if ($custom) $p['version'] = (isset($p['version']) ? $p['version'] : '') . '+custom';

        $cached = apply_filters('kratos_ai_pricing', $p);
        return $cached;
    }

    protected static function log_row($module, $provider, $model, $pt, $ct, $status, $target_id, $err) {
        $pricing = self::pricing();
        Kratos_AI_Logger::log(array(
            'module' => $module,
            'provider' => (string)$provider,
            'model' => (string)$model,
            'prompt_tokens' => (int)$pt,
            'completion_tokens' => (int)$ct,
            'cost_usd' => self::compute_cost($model, $pt, $ct),
            'pricing_version' => isset($pricing['version']) ? $pricing['version'] : '',
            'status' => $status,
            'target_id' => (int)$target_id,
            'err' => (string)$err,
        ));
    }

    protected static function accumulate_monthly_tokens($n) {
        if ($n <= 0) return;
        $key = 'kratos_ai_mtoken_' . gmdate('Ym');
        $cur = (int) get_option($key, 0);
        update_option($key, $cur + (int)$n, false);
    }

    /** 当月已用 token 是否超月度上限 */
    public static function monthly_exceeded() {
        $cap = (int) kratos_ai_opt('g_ai_monthly_token_limit', 0);
        if ($cap <= 0) return false;
        $used = (int) get_option('kratos_ai_mtoken_' . gmdate('Ym'), 0);
        return $used >= $cap;
    }
}
