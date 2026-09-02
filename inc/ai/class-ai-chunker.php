<?php
/**
 * Kratos_AI_Chunker — 长文切片策略
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_Chunker {

    /**
     * @param WP_Post|int $post
     * @param string $strategy 'summary'|'tags'
     * @param string|null $raw_override 编辑器未保存的正文（新文章）
     * @return array{chunks:string[], strategy:string}
     */
    public static function chunk($post, $strategy = 'summary', $raw_override = null) {
        $p = is_object($post) ? $post : get_post($post);
        // 新文章尚未落库，编辑器现场内容优先
        $raw = ($raw_override !== null && trim((string)$raw_override) !== '')
            ? (string)$raw_override
            : ($p ? (string)$p->post_content : '');
        $text = self::normalize($raw);
        $result = apply_filters('kratos_ai_content_chunk', null, $p, $strategy, $text);
        if (is_array($result) && !empty($result['chunks'])) return $result;

        $per_request_tokens = (int) kratos_ai_opt('g_ai_input_token_cap_per_request', 32000);
        if ($per_request_tokens < 1024) $per_request_tokens = 32000;
        $per_request_chars = $per_request_tokens * 2;

        $tokens = Kratos_AI_Prompt::count_tokens($text);
        if ($strategy === 'tags') {
            return array('chunks' => array(self::tags_slice($text)), 'strategy' => 'tags-slice');
        }
        if ($tokens <= 8000 || mb_strlen($text) <= 16000) {
            return array('chunks' => array($text), 'strategy' => 'single');
        }
        $chunks = self::split_by_len($text, $per_request_chars);
        return array('chunks' => $chunks, 'strategy' => 'map-reduce');
    }

    public static function normalize($raw) {
        $s = wp_strip_all_tags((string)$raw);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    public static function content_hash($post) {
        $p = is_object($post) ? $post : get_post($post);
        if (!$p) return '';
        return sha1(self::normalize($p->post_content));
    }

    public static function tags_slice($text) {
        $head = mb_substr($text, 0, 1000);
        $tail = mb_substr($text, max(0, mb_strlen($text) - 500));
        $h2 = '';
        if (preg_match_all('/(?:^|\s)#{2,3}\s*([^\n]{1,120})/u', $text, $m)) {
            foreach ($m[1] as $i => $h) {
                if ($i >= 10) break;
                $h2 .= "\n[h2] " . mb_substr($h, 0, 200);
            }
        }
        return $head . $h2 . "\n" . $tail;
    }

    public static function split_by_len($text, $chunk_chars) {
        $out = array();
        $len = mb_strlen($text);
        $chunk_chars = max(2000, (int)$chunk_chars);
        for ($i = 0; $i < $len; $i += $chunk_chars) {
            $out[] = mb_substr($text, $i, $chunk_chars);
        }
        return $out;
    }
}
