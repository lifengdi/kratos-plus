<?php
/**
 * 评论蜜罐 —— 防机器人
 * @author Dylan Li
 * @license GPL-3.0
 */
if (!defined('ABSPATH')) exit;

/**
 * 在评论表单内注入隐藏字段 + 提交时间戳
 */
function kratos_honeypot_render_fields()
{
    static $rendered = false;
    if ($rendered) return;
    if (!kratos_option('g_comment_honeypot_enabled', true)) return;
    $rendered = true;
    // 视觉隐藏但对 bot 可见的字段
    echo '<p class="kratos-hp-field" aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
        . '<label>Website URL (do not fill)</label>'
        . '<input type="text" name="kratos_url_confirm" tabindex="-1" autocomplete="off" value="">'
        . '</p>';
    echo '<input type="hidden" name="kratos_hp_ts" value="' . esc_attr(time()) . '">';
}
add_action('comment_form_after_fields', 'kratos_honeypot_render_fields');
add_action('comment_form_logged_in_after', 'kratos_honeypot_render_fields');
// 主题自定义的 comments.php 表单直接 do_action('comment_form')，同样触发上面
add_action('comment_form', 'kratos_honeypot_render_fields', 5);

/**
 * 提交前校验
 */
function kratos_honeypot_check($commentdata)
{
    if (!kratos_option('g_comment_honeypot_enabled', true)) return $commentdata;
    // 后台或已认证的高权限用户放行
    if (is_admin() || (is_user_logged_in() && current_user_can('moderate_comments'))) {
        return $commentdata;
    }

    // 蜜罐字段有值 → 直接毙
    if (!empty($_POST['kratos_url_confirm'])) {
        wp_die(__('评论提交异常，请刷新页面重试。', 'kratos'), __('评论被拦截', 'kratos'), array('response' => 403, 'back_link' => true));
    }

    // 时间戳过快
    $min_seconds = max(1, intval(kratos_option('g_comment_honeypot_min_seconds', 3)));
    if (!empty($_POST['kratos_hp_ts'])) {
        $ts = intval($_POST['kratos_hp_ts']);
        if ($ts > 0 && (time() - $ts) < $min_seconds) {
            wp_die(__('评论提交过快，请稍后重试。', 'kratos'), __('评论被拦截', 'kratos'), array('response' => 403, 'back_link' => true));
        }
    }

    return $commentdata;
}
add_filter('preprocess_comment', 'kratos_honeypot_check', 5);
