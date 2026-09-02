<?php
/**
 * Kratos_AI_Crypto — 信封加密（KEK + DEK, AES-256-GCM）
 * KEK 优先级：wp-config 常量 KRATOS_AI_KEK → option kratos_ai_kek（安装时随机生成）
 *
 * 默认路径就是「KEK 存 option」，后台不再就此提示什么 —— 主题本来就要求把 Key 存进数据库，
 * 再去警告数据库不安全只会让站长无从下手。想把 KEK 挪出数据库的高级用户仍可定义常量。
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_Crypto {

    const CIPHER = 'aes-256-gcm';
    const VERSION = 1;
    const OPT_KEK = 'kratos_ai_kek';
    const OPT_KEK_PREV = 'kratos_ai_kek_prev';

    /** @return string|false 32-byte binary KEK */
    public static function get_kek($version = null) {
        if (defined('KRATOS_AI_KEK') && KRATOS_AI_KEK) {
            $bin = base64_decode(KRATOS_AI_KEK, true);
            if ($bin && strlen($bin) === 32) return $bin;
        }
        $stored = get_option(self::OPT_KEK);
        if (!$stored) {
            $stored = base64_encode(random_bytes(32));
            update_option(self::OPT_KEK, $stored, false);
        }
        $bin = base64_decode($stored, true);
        return ($bin && strlen($bin) === 32) ? $bin : false;
    }

    public static function get_prev_kek() {
        $prev = get_option(self::OPT_KEK_PREV);
        if (!$prev) return false;
        $bin = base64_decode($prev, true);
        return ($bin && strlen($bin) === 32) ? $bin : false;
    }

    /**
     * @return string base64(json({v,kv,dek_iv,dek_tag,dek_ct,iv,tag,ct}))
     */
    public static function encrypt($plaintext) {
        if ($plaintext === '' || $plaintext === null) return '';
        $kek = self::get_kek();
        if ($kek === false) return new WP_Error('ai_kek_missing', __('KEK 不可用', 'kratos'));
        $dek = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, $dek, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) return new WP_Error('ai_encrypt_failed', 'encrypt failed');
        $dek_iv = random_bytes(12);
        $dek_tag = '';
        $dek_ct = openssl_encrypt($dek, self::CIPHER, $kek, OPENSSL_RAW_DATA, $dek_iv, $dek_tag);
        self::wipe($dek);
        if ($dek_ct === false) return new WP_Error('ai_encrypt_failed', 'dek wrap failed');
        $env = array(
            'v'  => self::VERSION,
            'kv' => 'current',
            'di' => base64_encode($dek_iv),
            'dt' => base64_encode($dek_tag),
            'dc' => base64_encode($dek_ct),
            'i'  => base64_encode($iv),
            't'  => base64_encode($tag),
            'c'  => base64_encode($ct),
        );
        return 'kai1:' . base64_encode(wp_json_encode($env));
    }

    public static function decrypt($blob) {
        if (!is_string($blob) || strpos($blob, 'kai1:') !== 0) return '';
        $json = base64_decode(substr($blob, 5), true);
        if (!$json) return '';
        $env = json_decode($json, true);
        if (!is_array($env) || empty($env['dc'])) return '';
        // 密文里的 kv 只是提示：encrypt() 恒写 'current'，轮换后旧密文照样标 current。
        // 所以按 current → prev 依次试解，prev 分支才真正可用（rekey 中途失败也还能读回）。
        $keks = array(self::get_kek());
        $prev = self::get_prev_kek();
        if ($prev) $keks[] = $prev;
        $dek = false;
        foreach ($keks as $kek) {
            if (!$kek) continue;
            $dek = openssl_decrypt(base64_decode($env['dc']), self::CIPHER, $kek, OPENSSL_RAW_DATA, base64_decode($env['di']), base64_decode($env['dt']));
            if ($dek !== false) break;
        }
        if ($dek === false) return '';
        $pt = openssl_decrypt(base64_decode($env['c']), self::CIPHER, $dek, OPENSSL_RAW_DATA, base64_decode($env['i']), base64_decode($env['t']));
        self::wipe($dek);
        return $pt === false ? '' : $pt;
    }

    public static function wipe(&$s) {
        if (function_exists('sodium_memzero')) {
            try { sodium_memzero($s); return; } catch (\Throwable $e) {}
        }
        $s = null; unset($s);
    }
}
