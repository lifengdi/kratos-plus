<?php
/**
 * Kratos_AI_Guards — 输出三闸：Schema / XSS / 合规审核
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_Guards {

    /** wp_kses 白名单：段落基础标签 + 站内 <a> */
    public static function allowed_html() {
        return apply_filters('kratos_ai_allowed_html', array(
            'p' => array(), 'br' => array(),
            'strong' => array(), 'em' => array(),
            'ul' => array(), 'ol' => array(), 'li' => array(),
            'blockquote' => array(),
            'code' => array(),
            'a' => array('href' => true, 'rel' => true, 'title' => true),
        ));
    }

    /** 清洗 AI 返回的 HTML 片段 */
    public static function sanitize_html($html) {
        if (!is_string($html) || $html === '') return '';
        $clean = wp_kses($html, self::allowed_html());
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $clean = preg_replace_callback('#<a\s+([^>]*)>(.*?)</a>#is', function($m) use ($site_host) {
            $attrs = $m[1];
            $anchor = $m[2];
            if (!preg_match('/href\s*=\s*"([^"]*)"/i', $attrs, $h)) {
                return $anchor;
            }
            $href = trim($h[1]);
            if (!preg_match('#^https?://#i', $href)) return $anchor;
            $url = esc_url_raw($href);
            if (!$url) return $anchor;
            $host = wp_parse_url($url, PHP_URL_HOST);
            if (!$host || strcasecmp($host, $site_host) !== 0) {
                return $anchor;
            }
            return '<a href="' . esc_attr($url) . '" rel="nofollow ugc noopener">' . $anchor . '</a>';
        }, $clean);
        return $clean;
    }

    /** JSON schema 校验（标签） */
    public static function validate_tags_json($text) {
        if (!is_string($text) || $text === '') return new WP_Error('ai_schema_invalid', 'empty');
        $text = trim($text);
        if ($text[0] !== '{' && $text[0] !== '[') {
            if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
        }
        $data = json_decode($text, true);
        if (!is_array($data) || empty($data['tags']) || !is_array($data['tags'])) {
            return new WP_Error('ai_schema_invalid', 'not_object_with_tags');
        }
        $out = array();
        foreach ($data['tags'] as $t) {
            if (!is_array($t) || empty($t['name'])) continue;
            $out[] = array(
                'name'        => sanitize_text_field($t['name']),
                'slug'        => isset($t['slug']) ? sanitize_title($t['slug']) : sanitize_title($t['name']),
                'seo_title'   => isset($t['seo_title']) ? sanitize_text_field($t['seo_title']) : '',
                'description' => isset($t['description']) ? self::sanitize_html($t['description']) : '',
                'is_new'      => !empty($t['is_new']),
            );
        }
        if (!$out) return new WP_Error('ai_schema_invalid', 'no_valid_tag');
        return $out;
    }

    /**
     * 合规审核（输入 / 输出）
     * @param string $context 'input'|'output'
     * @return true|WP_Error
     */
    public static function compliance($text, $context = 'input') {
        $blacklist = (array) apply_filters('kratos_ai_compliance_blacklist', array());
        foreach ($blacklist as $kw) {
            $kw = trim((string)$kw);
            if ($kw !== '' && mb_stripos($text, $kw) !== false) {
                return new WP_Error('ai_moderation_blocked', sprintf(__('内容命中审核关键词 (%s)', 'kratos'), $context));
            }
        }
        $filtered = apply_filters('kratos_ai_compliance_filter', true, $text, $context);
        if (is_wp_error($filtered)) return $filtered;
        return true;
    }
}
