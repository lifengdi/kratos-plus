<?php
/**
 * Kratos_AI_Logger — 日志表 + 用量统计 + PII 打码
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_Logger {

    const DB_VERSION = '1';
    const OPT_DB_VER = 'kratos_ai_db_version';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'kratos_ai_log';
    }

    public static function maybe_install() {
        if (get_option(self::OPT_DB_VER) === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t = self::table();
        $sql = "CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            module VARCHAR(32) NOT NULL DEFAULT '',
            provider VARCHAR(32) NOT NULL DEFAULT '',
            model VARCHAR(64) NOT NULL DEFAULT '',
            prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
            pricing_version VARCHAR(16) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT '',
            target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            err VARCHAR(500) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY ts (ts),
            KEY user_id (user_id),
            KEY module_status (module, status)
        ) {$charset};";
        dbDelta($sql);
        update_option(self::OPT_DB_VER, self::DB_VERSION, false);
    }

    public static function log($row) {
        global $wpdb;
        $defaults = array(
            'ts' => current_time('mysql', 1),
            'user_id' => get_current_user_id(),
            'module' => '', 'provider' => '', 'model' => '',
            'prompt_tokens' => 0, 'completion_tokens' => 0,
            'cost_usd' => 0, 'pricing_version' => '',
            'status' => 'ok', 'target_id' => 0, 'err' => '',
        );
        $row = array_merge($defaults, $row);
        $row['err'] = self::scrub_err($row['err']);
        $wpdb->insert(self::table(), $row);
    }

    /** 截断 500 字 + PII 打码 */
    public static function scrub_err($s) {
        if (!$s) return '';
        $s = mb_substr((string)$s, 0, 500, 'UTF-8');
        $s = preg_replace('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', '[email]', $s);
        $s = preg_replace('/(?<!\d)1[3-9]\d{9}(?!\d)/', '[mobile]', $s);
        $s = preg_replace('/(?<!\d)\d{17}[\dxX](?!\d)/', '[idcard]', $s);
        $s = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $s);
        return $s;
    }

    /** $days=0 表示清空整表 */
    public static function purge($days = 90) {
        global $wpdb;
        $days = max(0, (int)$days);
        $t = self::table();
        if ($days === 0) {
            $wpdb->query("DELETE FROM {$t}");
            return;
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE ts < (UTC_TIMESTAMP() - INTERVAL %d DAY)", $days));
    }

    public static function purge_user($user_id) {
        global $wpdb;
        $wpdb->delete(self::table(), array('user_id' => (int)$user_id), array('%d'));
    }

    public static function usage_summary($days = 30) {
        global $wpdb;
        $t = self::table();
        $days = max(1, (int)$days);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT module, provider, model, SUM(prompt_tokens) pt, SUM(completion_tokens) ct, SUM(cost_usd) cost, COUNT(*) n
             FROM {$t} WHERE ts >= (UTC_TIMESTAMP() - INTERVAL %d DAY) GROUP BY module, provider, model", $days
        ));
    }
}
