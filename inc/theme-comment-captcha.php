<?php

/**
 * 评论数字加减法验证码
 *
 * - 后台开关：g_comment_captcha_fieldset.g_comment_captcha
 * - 数字范围：1 ~ g_comment_captcha_fieldset.g_comment_captcha_max（默认 10）
 * - 表单注入：comment_form_after 钩子；用 transient 按 token 存正确答案，避免依赖 PHP session
 * - 服务端校验：preprocess_comment 钩子；命中错误用 wp_die（与 WP 评论提交链路兼容，前端 ajax 也会显示）
 *
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

function kratos_captcha_enabled()
{
    $opt = kratos_option('g_comment_captcha_fieldset', array());
    return !empty($opt['g_comment_captcha']);
}

function kratos_captcha_max()
{
    $opt = kratos_option('g_comment_captcha_fieldset', array());
    $max = isset($opt['g_comment_captcha_max']) ? intval($opt['g_comment_captcha_max']) : 10;
    return max(2, min(99, $max));
}

/**
 * 生成新题目并把答案写入 transient（10 分钟有效），返回 [token, x, y, op]。
 * op 在 + / - 间随机，- 时确保 x >= y 不出负数。
 */
function kratos_captcha_new_question()
{
    $max = kratos_captcha_max();
    $op = (mt_rand(0, 1) === 0) ? '+' : '-';
    $x = mt_rand(1, $max);
    $y = mt_rand(1, $max);
    if ($op === '-' && $y > $x) {
        $tmp = $x; $x = $y; $y = $tmp;
    }
    $answer = ($op === '+') ? ($x + $y) : ($x - $y);
    $token = wp_generate_password(16, false, false);
    set_transient('kratos_captcha_' . $token, (string) $answer, 10 * MINUTE_IN_SECONDS);
    return array($token, $x, $y, $op);
}

/**
 * 注入验证码到评论表单 textarea 下方那一行（表情按钮右侧）。
 *
 * 静态缓存友好：HTML 里只放空占位符，不内联 token / 题目。
 * 真正的题目由前端 DOMContentLoaded 时通过 ajax 拉取——这样即使整页 HTML 被
 * 全页缓存（WP Super Cache / Cloudflare 等）服务给所有访客，每次页面加载也都会
 * 拿到独立、新鲜的 token + 题目。
 */
function kratos_captcha_render()
{
    if (!kratos_captcha_enabled()) {
        return;
    }
    ?>
    <span class="kratos-captcha">
        <span class="kratos-captcha-q">…</span>
        <input
            type="text"
            class="kratos-captcha-input"
            inputmode="numeric"
            pattern="-?\d+"
            name="kratos_captcha"
            placeholder="<?php esc_attr_e('答案', 'kratos'); ?>"
            required="required"
            autocomplete="off">
        <input type="hidden" name="kratos_captcha_token" value="">
    </span>
    <style>
    /* 让工具栏整体按中线对齐，避免表情图标 baseline 与输入框中线错位 */
    #commentform .text-bar .tool { display: flex; align-items: center; }
    #commentform .text-bar .tool .smile { /* 表情面板是浮层，不参与 flex 排版 */ flex: 0 0 auto; }
    .kratos-captcha { display: inline-flex; align-items: center; margin-left: 12px; }
    .kratos-captcha-q { color: #666; font-size: 14px; line-height: 1; margin-right: 6px; user-select: none; }
    .kratos-captcha-input {
        width: 64px; height: 28px; padding: 2px 8px; font-size: 13px; line-height: 1.4;
        border: 1px solid #ced4da; border-radius: 3px; background: #fff; color: #333;
        outline: none; box-sizing: border-box;
    }
    .kratos-captcha-input:focus { border-color: #336699; }
    </style>
    <script>
    (function(){
        var form = document.getElementById('commentform');
        if (!form) return;
        var captcha = form.querySelector('.kratos-captcha');
        if (!captcha) return;
        // 紧跟表情按钮（id=addsmile）的 *表情面板* 之后，确保 DOM 顺序：[😀][验证码]
        var smile = form.querySelector('.text-bar .tool .smile') || form.querySelector('.text-bar .tool');
        if (smile && smile.parentNode) {
            smile.insertAdjacentElement('afterend', captcha);
        }
        // 公共刷新函数：拉一道新题填进 DOM。focusInput=true 时同时清空答案并 focus。
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        function refreshCaptcha(focusInput){
            if (!window.jQuery) return;
            jQuery.post(ajaxUrl, { action: 'kratos_captcha_refresh' }).done(function(resp){
                if (!resp || !resp.success || !resp.data) return;
                var f = document.getElementById('commentform');
                if (!f) return;
                var qEl = f.querySelector('.kratos-captcha-q');
                var tokEl = f.querySelector('input[name="kratos_captcha_token"]');
                var ansEl = f.querySelector('input[name="kratos_captcha"]');
                if (qEl) qEl.textContent = resp.data.question;
                if (tokEl) tokEl.value = resp.data.token;
                if (ansEl) {
                    ansEl.value = '';
                    if (focusInput) ansEl.focus();
                }
            });
        }
        // 静态缓存友好：页面加载后立即拉一次新题（HTML 中是空占位符）
        refreshCaptcha(false);
        // 评论 ajax 提交结束（无论成功/失败/验证码错误）后都刷新一道新题。
        // 失败时让用户立刻可以重试（focus 到输入框）；成功时静默换题，避免下次提交时复用旧 token。
        if (window.jQuery && !window.kratosCaptchaBound) {
            window.kratosCaptchaBound = true;
            jQuery(document).ajaxComplete(function(_e, xhr, settings){
                if (!settings || !settings.data || settings.data.indexOf('action=ajax_comment') === -1) return;
                var ok = xhr && xhr.status >= 200 && xhr.status < 300;
                refreshCaptcha(!ok);
            });
        }
    })();
    </script>
    <?php
}
add_action('comment_form_after', 'kratos_captcha_render');

/**
 * 提交校验：preprocess_comment 是 ajax / 普通提交都会过的钩子
 *  - 所有访客（含已登录用户）都校验；trackback / pingback 跳过
 *  - 答案错或 token 过期 → wp_die 抛错（comment_callback / wp_handle_comment_submission 都会捕获并返回错误）
 */
function kratos_captcha_validate($commentdata)
{
    if (!kratos_captcha_enabled()) {
        return $commentdata;
    }
    $type = isset($commentdata['comment_type']) ? $commentdata['comment_type'] : '';
    if ($type !== '' && $type !== 'comment') {
        return $commentdata;
    }
    $token = isset($_POST['kratos_captcha_token']) ? sanitize_text_field(wp_unslash($_POST['kratos_captcha_token'])) : '';
    $answer = isset($_POST['kratos_captcha']) ? trim(wp_unslash($_POST['kratos_captcha'])) : '';
    if ($token === '' || $answer === '') {
        wp_die(
            esc_html__('请填写验证码后再提交评论。', 'kratos'),
            esc_html__('验证码错误', 'kratos'),
            array('response' => 403, 'back_link' => true)
        );
    }
    $expected = get_transient('kratos_captcha_' . $token);
    delete_transient('kratos_captcha_' . $token); // 一次性使用，无论对错都失效
    if ($expected === false) {
        wp_die(
            esc_html__('验证码已过期，请重新输入。', 'kratos'),
            esc_html__('验证码过期', 'kratos'),
            array('response' => 403, 'back_link' => true)
        );
    }
    if ((string) intval($answer) !== (string) $expected) {
        wp_die(
            esc_html__('验证码答案错误，请重新输入。', 'kratos'),
            esc_html__('验证码错误', 'kratos'),
            array('response' => 403, 'back_link' => true)
        );
    }
    return $commentdata;
}
add_filter('preprocess_comment', 'kratos_captcha_validate', 5);

/**
 * AJAX：返回一道新验证码题目（JSON）。前端在评论提交失败时调用，自动刷新验证码。
 */
function kratos_captcha_refresh()
{
    if (!kratos_captcha_enabled()) {
        wp_send_json_error(array('message' => 'disabled'), 403);
    }
    list($token, $x, $y, $op) = kratos_captcha_new_question();
    wp_send_json_success(array(
        'token' => $token,
        'question' => $x . ' ' . $op . ' ' . $y . ' =',
    ));
}
add_action('wp_ajax_nopriv_kratos_captcha_refresh', 'kratos_captcha_refresh');
add_action('wp_ajax_kratos_captcha_refresh', 'kratos_captcha_refresh');
