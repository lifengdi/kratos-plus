<?php
/**
 * AI 工具箱主入口（M1）
 * - 注册 CSF「AI 工具箱」分区
 * - g_ai_enable=on 时 require SDK
 * - API Key 明文进入 CSF options 后即时抽出、信封加密、从 CSF options 里清空
 */
if (!defined('ABSPATH')) exit;

/**
 * 从 CSF options 读某个 key，兼容未设置 / 留空（留空视为未设置，回落 $default）
 * SDK 内所有配额 / 参数都经此读取，避免出现「后台字段与 SDK 读的 option 不是同一个」。
 */
function kratos_ai_opt($key, $default = '') {
    static $opts = null;
    if ($opts === null) $opts = get_option('kratos_options');
    if (!is_array($opts)) return $default;
    if (!isset($opts[$key]) || $opts[$key] === '' || $opts[$key] === null) return $default;
    return $opts[$key];
}

function kratos_ai_is_enabled() {
    if (!kratos_ai_opt('g_ai_enable')) return false;
    // 中国大陆合规确认：g_ai_compliance_ack 未勾时仅 admin 可用（本地调试）
    if (!kratos_ai_opt('g_ai_compliance_ack')) {
        // cron / CLI 没有当前用户，若一并挡掉则日志 GC 与批量队列永远不会执行
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return true;
        return current_user_can('manage_options');
    }
    return true;
}

/**
 * CSF options 入库【之前】把明文 Key 抽出加密，并把字段置空后再写库。
 * 用 pre_update_option_* 而不是 update_option_*：后者在写库之后才触发，
 * 明文会先落一次 wp_options（也会进 CSF 的备份 / 导出 JSON），再被覆盖。
 */
add_filter('pre_update_option_kratos_options', 'kratos_ai_capture_api_key', 10, 2);
function kratos_ai_capture_api_key($new, $old) {
    if (!is_array($new)) return $new;
    $dir = trailingslashit(get_template_directory()) . 'inc/ai/';
    $errors = array();

    // 主端点 / 备用端点：字段名 => 密文 option 名
    $pairs = array(
        'g_ai_provider_openai_base' => array('key' => 'g_ai_provider_openai_key', 'opt' => 'kratos_ai_key_openai',     'label' => '主端点'),
        'g_ai_provider_alt_base'    => array('key' => 'g_ai_provider_alt_key',    'opt' => 'kratos_ai_key_openai_alt', 'label' => '备用端点'),
    );

    foreach ($pairs as $base_field => $ep) {
        // base_url 保存时做一次 SSRF 校验（选项页文案承诺过）：不合格则回退旧值并留一条提示
        if (!empty($new[$base_field])) {
            require_once $dir . 'class-ai-ssrf.php';
            $chk = Kratos_AI_SSRF::validate_base_url($new[$base_field]);
            if (is_wp_error($chk)) {
                $new[$base_field] = (is_array($old) && isset($old[$base_field])) ? $old[$base_field] : '';
                $errors[] = $ep['label'] . ' base_url：' . $chk->get_error_message();
            }
        }

        if (empty($new[$ep['key']])) continue;
        $plain = (string) $new[$ep['key']];
        $new[$ep['key']] = '';
        // 已经是密文占位（••••）跳过
        if ($plain === '••••' || strpos($plain, 'kai1:') === 0) continue;
        require_once $dir . 'class-ai-crypto.php';
        $ct = Kratos_AI_Crypto::encrypt($plain);
        Kratos_AI_Crypto::wipe($plain);
        if (is_wp_error($ct)) {
            // 加密失败：宁可不保存，也不把明文写进库（字段上面已置空）
            $errors[] = $ep['label'] . ' API Key：' . $ct->get_error_message();
            continue;
        }
        update_option($ep['opt'], $ct, false);
    }

    if ($errors) set_transient('kratos_ai_save_errors', $errors, 60);
    return $new;
}

/**
 * 后台提示：仅保存报错（base_url SSRF 校验失败 / 加密失败）
 */
add_action('admin_notices', 'kratos_ai_admin_notices');
function kratos_ai_admin_notices() {
    if (!current_user_can('manage_options')) return;
    // 菜单 slug 是 kratos-options（连字符），kratos_options 是 option 名，别搞混
    if (empty($_GET['page']) || $_GET['page'] !== 'kratos-options') return;
    $errs = get_transient('kratos_ai_save_errors');
    if ($errs) {
        delete_transient('kratos_ai_save_errors');
        echo '<div class="notice notice-error is-dismissible"><p><strong>Kratos AI:</strong> ' . esc_html(implode('；', (array)$errs)) . '</p></div>';
    }
}

/**
 * 启动 SDK
 */
add_action('init', 'kratos_ai_boot', 5);
function kratos_ai_boot() {
    if (!kratos_ai_is_enabled()) return;
    $dir = trailingslashit(get_template_directory()) . 'inc/ai/';
    if (!file_exists($dir . 'class-kratos-ai.php')) return;
    require_once $dir . 'class-kratos-ai.php';
    Kratos_AI::boot();
}

/**
 * 切换主题时清理 cron
 * （register_deactivation_hook 是插件 API，主题里永不触发，会留下孤儿 cron）
 */
add_action('switch_theme', 'kratos_ai_deactivate');
function kratos_ai_deactivate() {
    wp_clear_scheduled_hook('kratos_ai_daily_gc');
    // 早期版本的批量摘要队列已移除，顺手清掉可能残留的 cron 与队列 option
    wp_clear_scheduled_hook('kratos_ai_summary_batch_tick');
    delete_option('kratos_ai_summary_queue');
}
