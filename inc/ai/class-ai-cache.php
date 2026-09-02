<?php
/**
 * Kratos_AI_Cache — 结果缓存 + 幂等键
 * 有外置对象缓存时走专属 wp_cache group（草稿摘要不落 wp_options）；
 * 没有时必须回落 transient —— 否则 wp_cache 只是请求级数组，缓存永不命中、每次都真调 API。
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_Cache {

    const GROUP = 'kratos_ai';
    const TTL = 86400;

    public static function key($provider, $model, $prompt_key, $input_hash) {
        return hash('sha256', $provider . '|' . $model . '|' . $prompt_key . '|' . $input_hash);
    }

    protected static function persistent() {
        return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }

    public static function get($key) {
        $v = self::persistent()
            ? wp_cache_get($key, self::GROUP)
            : get_transient('kratos_ai_r_' . $key);
        return ($v === false) ? null : $v;
    }

    public static function set($key, $value) {
        if (self::persistent()) {
            wp_cache_set($key, $value, self::GROUP, self::TTL);
        } else {
            set_transient('kratos_ai_r_' . $key, $value, self::TTL);
        }
    }

    /** idempotency_key = sha1(module|post_id|content_hash) */
    public static function idempotency_key($module, $post_id, $content_hash) {
        return sha1($module . '|' . (int)$post_id . '|' . $content_hash);
    }

    /** 冷却当前 provider N 秒（429） */
    public static function cool_provider($provider, $seconds = 60) {
        set_transient('kratos_ai_cool_' . md5($provider), 1, $seconds);
    }

    public static function is_cooling($provider) {
        return (bool) get_transient('kratos_ai_cool_' . md5($provider));
    }
}
