<?php
/**
 * Kratos_AI_REST — 通用 REST 助手：Origin/Referer 校验、错误码 → 前端文案
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_REST {

    const NAMESPACE_V1 = 'kratos/v1/ai';

    /**
     * Origin/Referer 属于本站校验。缺失或字面量 null 一律拒。
     * 在 REST route 的 permission_callback 里调用。
     */
    public static function verify_origin(WP_REST_Request $req) {
        $origin = $req->get_header('origin');
        $referer = $req->get_header('referer');
        $home = wp_parse_url(home_url(), PHP_URL_HOST);

        // Origin：缺失或 "null" 直接拒
        if ($origin !== null && $origin !== '') {
            if (strcasecmp($origin, 'null') === 0) {
                return new WP_Error('ai_bad_origin', __('Origin=null 被拒', 'kratos'), array('status' => 403));
            }
            $ohost = wp_parse_url($origin, PHP_URL_HOST);
            if (!$ohost || strcasecmp($ohost, $home) !== 0) {
                return new WP_Error('ai_bad_origin', __('Origin 与本站不匹配', 'kratos'), array('status' => 403));
            }
            return true;
        }
        // 无 Origin 时用 Referer 兜底
        if ($referer) {
            $rhost = wp_parse_url($referer, PHP_URL_HOST);
            if ($rhost && strcasecmp($rhost, $home) === 0) return true;
        }
        return new WP_Error('ai_bad_origin', __('缺少同源标识', 'kratos'), array('status' => 403));
    }

    /**
     * post 编辑权限 + Origin 校验组合
     */
    public static function permission_edit_post(WP_REST_Request $req) {
        $origin = self::verify_origin($req);
        if (is_wp_error($origin)) return $origin;
        $post_id = (int) $req->get_param('post_id');
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return new WP_Error('ai_forbidden', __('无权编辑该文章', 'kratos'), array('status' => 403));
        }
        return true;
    }

    public static function permission_manage_options(WP_REST_Request $req) {
        $origin = self::verify_origin($req);
        if (is_wp_error($origin)) return $origin;
        if (!current_user_can('manage_options')) {
            return new WP_Error('ai_forbidden', __('需要管理员权限', 'kratos'), array('status' => 403));
        }
        return true;
    }

    /**
     * 简单 QPS 限流：user_id + module + 每分钟 N 次
     * 必须用 transient：wp_cache 在没有外置对象缓存时是请求级数组，计数永远读到 0，闸门等于不存在。
     */
    public static function rate_limit($user_id, $module, $limit_per_min = 20) {
        $key = 'kratos_ai_rl_' . (int)$user_id . '_' . $module . '_' . gmdate('YmdHi');
        $cur = (int) get_transient($key);
        if ($cur >= $limit_per_min) return false;
        set_transient($key, $cur + 1, 120);
        return true;
    }
}
