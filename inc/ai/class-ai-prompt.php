<?php
/**
 * Kratos_AI_Prompt — 模板化 prompt + 粗略 token 估算
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_Prompt {

    /**
     * 装载 inc/ai/prompts/{$key}.php，返回 array{system:string,user_template:string}
     * 支持 filter kratos_ai_prompt_{$key} 覆盖（filter 只能由代码注册，无需再加数据库开关门控）
     */
    public static function render($key, $vars = array()) {
        $file = trailingslashit(get_template_directory()) . 'inc/ai/prompts/' . $key . '.php';
        $tpl = array('system' => '', 'user_template' => '<user_content>{{content}}</user_content>');
        if (file_exists($file)) {
            $loaded = include $file;
            if (is_array($loaded)) $tpl = array_merge($tpl, $loaded);
        }
        $tpl = apply_filters('kratos_ai_prompt_' . $key, $tpl, $vars);
        $content = isset($vars['content']) ? (string)$vars['content'] : '';
        $user = str_replace('{{content}}', $content, $tpl['user_template']);
        foreach ($vars as $k => $v) {
            if ($k === 'content' || !is_scalar($v)) continue;
            $user = str_replace('{{' . $k . '}}', (string)$v, $user);
        }
        return array(
            'system' => $tpl['system'],
            'user' => $user,
        );
    }

    /** 粗略 token 估算：中文 ~1.5 char/token，英文 ~4 char/token；混合按 2.5 char/token 折中 */
    public static function count_tokens($text) {
        if (!is_string($text) || $text === '') return 0;
        $len = mb_strlen($text, 'UTF-8');
        return (int) ceil($len / 2.5);
    }
}
