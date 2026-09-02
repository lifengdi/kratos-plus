<?php
/**
 * OpenAI 兼容协议 Provider（DeepSeek / Moonshot / 智谱 / OpenRouter / vLLM 共用）
 */
if (!defined('ABSPATH')) exit;

interface Kratos_AI_Provider {
    /**
     * @param array $messages [{role:'system'|'user'|'assistant',content:string}]
     * @param array $opts model, temperature, max_tokens, response_format, base_url, api_key, timeout
     * @return array{ok:bool,text:string,usage:array,model:string,error:?WP_Error,http_code:int}
     */
    public function chat(array $messages, array $opts = array());

    public function slug();
}

class Kratos_AI_Provider_OpenAI implements Kratos_AI_Provider {

    /** 同一份协议实现可注册多次，各自代表一个端点（主 / 备用） */
    protected $slug;

    public function __construct($slug = 'openai') {
        $this->slug = $slug;
    }

    public function slug() { return $this->slug; }

    public function chat(array $messages, array $opts = array()) {
        $base = isset($opts['base_url']) ? rtrim($opts['base_url'], '/') : '';
        $key  = isset($opts['api_key']) ? $opts['api_key'] : '';
        if (!$base || !$key) {
            return $this->err('ai_key_missing', __('未配置 base_url 或 API Key', 'kratos'));
        }
        $ssrf = Kratos_AI_SSRF::validate_base_url($base);
        if (is_wp_error($ssrf)) return $this->err('ai_key_invalid', $ssrf->get_error_message());

        $endpoint = self::build_endpoint($base, 'chat/completions');
        $body = array(
            'model'       => isset($opts['model']) ? $opts['model'] : 'gpt-4o-mini',
            'messages'    => $messages,
            'temperature' => isset($opts['temperature']) ? (float)$opts['temperature'] : 0.4,
        );
        if (!empty($opts['max_tokens'])) $body['max_tokens'] = (int)$opts['max_tokens'];
        if (!empty($opts['response_format'])) {
            $body['response_format'] = is_array($opts['response_format'])
                ? $opts['response_format']
                : array('type' => (string)$opts['response_format']);
        }
        $idem = isset($opts['idempotency_key']) ? $opts['idempotency_key'] : '';

        $args = array(
            'method'  => 'POST',
            'timeout' => isset($opts['timeout']) ? (int)$opts['timeout'] : 60,
            // 0：pre_http_request 只校验首个 URL，允许重定向等于把 SSRF 校验绕过去
            'redirection' => 0,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($body),
            '_kratos_ai' => true,
        );
        if ($idem) $args['headers']['Idempotency-Key'] = $idem;

        do_action('kratos_ai_before_request', $endpoint, $args);
        $resp = wp_remote_post($endpoint, $args);
        do_action('kratos_ai_after_response', $endpoint, $resp);

        if (is_wp_error($resp)) {
            $code = $resp->get_error_code() === 'http_request_failed' ? 'ai_provider_timeout' : 'ai_provider_5xx';
            return $this->err($code, $resp->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($resp);
        $raw = wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($status === 429) {
            Kratos_AI_Cache::cool_provider($this->slug(), 60);
            return $this->err('ai_quota_exhausted', 'HTTP 429', $status);
        }
        if ($status === 401 || $status === 403) {
            return $this->err('ai_key_invalid', 'HTTP ' . $status, $status);
        }
        if ($status >= 500) {
            return $this->err('ai_provider_5xx', 'HTTP ' . $status, $status);
        }
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            $msg = isset($json['error']['message']) ? $json['error']['message'] : ('HTTP ' . $status);
            return $this->err('ai_provider_5xx', $msg, $status);
        }
        $text = isset($json['choices'][0]['message']['content']) ? (string)$json['choices'][0]['message']['content'] : '';
        $usage = isset($json['usage']) && is_array($json['usage']) ? $json['usage'] : array();
        return array(
            'ok' => true,
            'text' => $text,
            'usage' => array(
                'prompt_tokens'     => isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : 0,
                'completion_tokens' => isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : 0,
            ),
            'model' => isset($json['model']) ? (string)$json['model'] : $body['model'],
            'error' => null,
            'http_code' => $status,
        );
    }

    /**
     * 拼接 endpoint：兼容以下 base_url 形态，均指向同一路径
     *   https://api.openai.com                  → https://api.openai.com/v1/chat/completions
     *   https://api.openai.com/v1                → https://api.openai.com/v1/chat/completions
     *   https://api.openai.com/v1/chat/completions → 原样
     *   https://ark.cn-beijing.volces.com/api/v3 → https://ark.cn-beijing.volces.com/api/v3/chat/completions
     *   https://xxx/openai/v1/                   → https://xxx/openai/v1/chat/completions
     */
    public static function build_endpoint($base, $path = 'chat/completions') {
        $base = rtrim($base, '/');
        $path = ltrim($path, '/');
        // 若 base 已经指向具体 endpoint，原样返回
        if (preg_match('#/' . preg_quote($path, '#') . '$#i', $base)) return $base;
        // 若 base 以版本号结尾（/v1、/v2、/api/v3 等），只需追加 path
        if (preg_match('#/v\d+$#i', $base) || preg_match('#/api/v\d+$#i', $base)) {
            return $base . '/' . $path;
        }
        // 否则按 OpenAI 官方默认走 /v1/
        return $base . '/v1/' . $path;
    }

    private function err($code, $message, $http_code = 0) {
        return array(
            'ok' => false, 'text' => '', 'usage' => array(), 'model' => '',
            'error' => new WP_Error($code, $message),
            'http_code' => $http_code,
        );
    }
}
