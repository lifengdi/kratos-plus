<?php
/**
 * wp-cli 命令：
 *   wp kratos ai:test          打通链路（发送一条极短 prompt）
 *   wp kratos ai:rekey --new-kek=<base64_32B>  KEK 轮换
 */
if (!defined('WP_CLI') || !WP_CLI) return;

class Kratos_AI_CLI {

    /**
     * 打通 provider 链路
     *
     * ## OPTIONS
     * [--provider=<slug>]
     * : 端点 slug：openai（主端点，默认）或 openai_alt（备用端点）
     *
     * ## EXAMPLES
     *   wp kratos ai:test
     *   wp kratos ai:test --provider=openai_alt
     */
    public static function test($args, $assoc) {
        $slug = isset($assoc['provider']) ? $assoc['provider'] : 'openai';
        $eps = Kratos_AI::endpoints();
        if (!isset($eps[$slug])) {
            WP_CLI::error('未知端点 ' . $slug . '，可用：' . implode(' / ', array_keys($eps)));
        }
        $cfg = Kratos_AI::provider_config($slug);
        if (!$cfg['base_url'] || !$cfg['api_key'] || !$cfg['model']) {
            WP_CLI::error($eps[$slug]['label'] . ' 的 base_url / API Key / Model 未配置完整');
        }
        $r = Kratos_AI_Client::generate(array(
            'module' => 'test',
            'prompt_key' => 'ping',
            'vars' => array('content' => 'ping'),
            'provider_slug' => $slug,
            'temperature' => 0,
            'max_tokens' => 32,
        ));
        if (!$r['ok']) {
            WP_CLI::error($r['error']->get_error_code() . ': ' . $r['error']->get_error_message());
        }
        WP_CLI::success('OK. model=' . $r['model'] . ' text=' . mb_substr($r['text'], 0, 200));
    }

    /**
     * KEK 轮换：读旧 KEK 解密所有密文、用新 KEK 重加密回写
     *
     * ## OPTIONS
     * --new-kek=<base64>
     * : 新 KEK（32 字节 base64）
     */
    public static function rekey($args, $assoc) {
        if (empty($assoc['new-kek'])) WP_CLI::error('--new-kek 必填');
        $new = base64_decode($assoc['new-kek'], true);
        if (!$new || strlen($new) !== 32) WP_CLI::error('new-kek 必须是 32 字节 base64');
        $old_kek = get_option(Kratos_AI_Crypto::OPT_KEK);
        if (!$old_kek) WP_CLI::error('未找到旧 KEK option，可能已使用常量 KEK；请手动迁移');

        $keys_to_rewrap = wp_list_pluck(Kratos_AI::endpoints(), 'key_opt');
        $decrypted = array();
        foreach ($keys_to_rewrap as $opt) {
            $blob = get_option($opt, '');
            if (!$blob) continue;
            $pt = Kratos_AI_Crypto::decrypt($blob);
            if ($pt === '') WP_CLI::error("解密 {$opt} 失败");
            $decrypted[$opt] = $pt;
        }
        update_option(Kratos_AI_Crypto::OPT_KEK_PREV, $old_kek, false);
        update_option(Kratos_AI_Crypto::OPT_KEK, base64_encode($new), false);
        foreach ($decrypted as $opt => $pt) {
            $ct = Kratos_AI_Crypto::encrypt($pt);
            if (is_wp_error($ct)) WP_CLI::error("重加密 {$opt} 失败");
            update_option($opt, $ct, false);
            Kratos_AI_Crypto::wipe($decrypted[$opt]);
        }
        WP_CLI::success('KEK 已轮换。旧 KEK 保留在 kratos_ai_kek_prev，验收后请手动删除该 option。');
    }

    /** 清空日志 */
    public static function log_purge($args, $assoc) {
        Kratos_AI_Logger::purge(0);
        WP_CLI::success('日志表已清空');
    }
}

WP_CLI::add_command('kratos ai:test', array('Kratos_AI_CLI', 'test'));
WP_CLI::add_command('kratos ai:rekey', array('Kratos_AI_CLI', 'rekey'));
WP_CLI::add_command('kratos ai:log-purge', array('Kratos_AI_CLI', 'log_purge'));
