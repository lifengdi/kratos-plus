<?php

/**
 * SMTP 配置
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2022.04.29
 */

if (kratos_option('m_smtp', false)) {
    function mail_smtp($phpmailer)
    {
        $phpmailer->isSMTP();
        $phpmailer->SMTPAuth = true;
        $phpmailer->CharSet = "utf-8";
        $phpmailer->SMTPSecure = kratos_option('m_sec');
        $phpmailer->Port = kratos_option('m_port');
        $phpmailer->Host = kratos_option('m_host');
        $phpmailer->From = kratos_option('m_username');
        $phpmailer->Username = kratos_option('m_username');
        $phpmailer->Password = kratos_option('m_passwd');
    }
    add_action('phpmailer_init', 'mail_smtp');
}

// Debug
function wp_mail_debug($wp_error)
{
    return error_log(print_r($wp_error, true));
}
// add_action('wp_mail_failed', 'wp_mail_debug', 10, 1);

function comment_approved($comment)
{
    if (is_email($comment->comment_author_email)) {
        $wp_email = kratos_option('m_username');
        $to = trim($comment->comment_author_email);
        $post_link = get_permalink($comment->comment_post_ID);
        $subject = __('[通知]您的留言已经通过审核', 'kratos');
        $message = '
            <div style="background:#ececec;width: 100%;padding: 50px 0;text-align:center;">
            <div style="background:#fff;width:750px;text-align:left;position:relative;margin:0 auto;font-size:14px;line-height:1.5;">
                    <div style="zoom:1;padding:25px 40px;background:#518bcb; border-bottom:1px solid #467ec3;">
                        <h1 style="color:#fff; font-size:25px;line-height:30px; margin:0;"><a href="' . get_option('home') . '" style="text-decoration: none;color: #FFF;">' . htmlspecialchars_decode(get_option('blogname'), ENT_QUOTES) . '</a></h1>
                    </div>
                <div style="padding:35px 40px 30px;">
                    <h2 style="font-size:18px;margin:5px 0;">' . __('您好，', 'kratos') . trim($comment->comment_author) . '：</h2>
                    <p style="color:#313131;line-height:20px;font-size:15px;margin:20px 0;">' . __('您的留言已经通过了管理员的审核，摘要信息如下：', 'kratos') . '</p>
                        <table cellspacing="0" style="font-size:14px;text-align:center;border:1px solid #ccc;table-layout:fixed;width:500px;">
                            <thead>
                                <tr>
                                    <th style="padding:5px 0;text-indent:8px;border:1px solid #eee;border-width:0 1px 1px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:normal;color:#a0a0a0;background:#eee;border-color:#dfdfdf;" width="280px;">' . __('文章', 'kratos') . '</th>
                                    <th style="padding:5px 0;text-indent:8px;border:1px solid #eee;border-width:0 1px 1px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:normal;color:#a0a0a0;background:#eee;border-color:#dfdfdf;" width="270px;">' . __('内容', 'kratos') . '</th>
                                    <th style="padding:5px 0;text-indent:8px;border:1px solid #eee;border-width:0 1px 1px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:normal;color:#a0a0a0;background:#eee;border-color:#dfdfdf;" width="110px;">' . __('操作', 'kratos') . '</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding:5px 0;text-indent:8px;border:1px solid #eee;border-width:0 1px 1px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">《' . get_the_title($comment->comment_post_ID) . '》</td>
                                    <td style="padding:5px 0;text-indent:8px;border:1px solid #eee;border-width:0 1px 1px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . trim($comment->comment_content) . '</td>
                                    <td style="padding:5px 0;text-indent:8px;border:1px solid #eee;border-width:0 1px 1px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><a href="' . get_comment_link($comment->comment_ID) . '" style="color:#1E5494;text-decoration:none;vertical-align:middle;" target="_blank">查看留言</a></td>
                                </tr>
                            </tbody>
                        </table>
                        <br>
                    <div style="font-size:13px;color:#a0a0a0;padding-top:10px">' . __('该邮件由系统自动发出，如果不是您本人操作，请忽略此邮件。', 'kratos') . '</div>
                    <div class="qmSysSign" style="padding-top:20px;font-size:12px;color:#a0a0a0;">
                        <p style="color:#a0a0a0;line-height:18px;font-size:12px;margin:5px 0;">' . htmlspecialchars_decode(get_option('blogname'), ENT_QUOTES) . '</p>
                        <p style="color:#a0a0a0;line-height:18px;font-size:12px;margin:5px 0;"><span style="border-bottom:1px dashed #ccc;" t="5" times="">' . wp_date("Y年m月d日", time()) . '</span></p>
                    </div>
                </div>
            </div>
        </div>';
        $from = "From: \"" . htmlspecialchars_decode(get_option('blogname'), ENT_QUOTES) . "\" <$wp_email>";
        $headers = "$from\nContent-Type: text/html; charset=" . get_option('blog_charset') . "\n";
        wp_mail($to, $subject, $message, $headers);
    }
}
add_action('comment_unapproved_to_approved', 'comment_approved');

/**
 * 邮件中的评论内容格式化：把 :shortcode: 表情转成 <img>（使用主题表情图片的绝对地址），
 * 未匹配到的原样保留。返回适用于邮件的 HTML。
 */
function kratos_mail_format_comment($content)
{
    $content = trim((string) $content);
    if ($content === '') {
        return '';
    }

    // 评论存储时经 htmlspecialchars 转义（见 theme-article.php），先解回再处理
    $content = htmlspecialchars_decode($content, ENT_QUOTES);

    // 用主题的 smilies map 手动替换 :shortcode:，
    // 用绝对 URL（含站点域名）确保邮件客户端能加载。
    if (function_exists('kratos_get_smilies_map')) {
        $map = kratos_get_smilies_map();
        if (!empty($map)) {
            $base_url = defined('ASSET_PATH') ? ASSET_PATH : get_template_directory_uri();
            // 站内 ASSET_PATH 若指向 jsdelivr CDN，邮件客户端可访问；
            // 若是相对/本地路径，需补全为站点域名。
            if (strpos($base_url, '//') === false) {
                $base_url = home_url($base_url);
            }
            uksort($map, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
            foreach ($map as $shortcode => $file) {
                $url = rtrim($base_url, '/') . '/assets/img/smilies/' . ltrim($file, '/');
                $img = '<img src="' . esc_url($url) . '" alt="' . esc_attr($shortcode) . '" style="height:22px;width:22px;vertical-align:middle;display:inline-block;border:0;margin:0 2px;" />';
                // shortcode 里含正则元字符（`:`、`/`），用 preg_quote 转义
                $content = preg_replace('/' . preg_quote($shortcode, '/') . '/u', $img, $content);
            }
        }
    }

    return wpautop($content);
}

/**
 * 评论回复邮件通知（气泡对话式模板）
 * 参考：wp-dylan-custom-plugin/dylan-smtp.php
 */
function comment_notify($comment_ID, $comment_approved = null, $commentdata = null)
{
    // 统一钩子参数
    if (is_array($comment_approved)) {
        $comment_approved = null;
    }

    // 基础校验
    $comment = get_comment($comment_ID);
    if (!$comment || is_wp_error($comment)) {
        return;
    }
    if (empty($comment->comment_parent) || $comment->comment_parent == 0) {
        return;
    }
    if ($comment->comment_approved !== '1') {
        return;
    }

    // 父评论校验
    $parent_comment = get_comment($comment->comment_parent);
    if (!$parent_comment || is_wp_error($parent_comment)) {
        return;
    }
    $parent_email = trim($parent_comment->comment_author_email);
    if (empty($parent_email) || !is_email($parent_email)) {
        return;
    }

    // 文章校验
    $post = get_post($comment->comment_post_ID);
    if (!$post || is_wp_error($post)) {
        return;
    }
    $post_title = $post->post_title ?: __('未命名文章', 'kratos');

    // 站点/评论者信息
    $site_name = get_bloginfo('name') ?: 'WordPress';
    $site_url = esc_url(get_bloginfo('url'));
    $comment_link = esc_url(get_comment_link($parent_comment));

    // 头像加速域名：读取主题选项「Gravatar 加速服务」，未启用则回退 secure.gravatar.com
    $gravatar_fs = kratos_option('g_replace_gravatar_url_fieldset');
    $gravatar_enabled = is_array($gravatar_fs) ? ($gravatar_fs['g_replace_gravatar_url'] ?? true) : true;
    if ($gravatar_enabled) {
        $server_map = array(
            'geekzu' => 'sdn.geekzu.org',
            'loli'   => 'gravatar.loli.net',
            'other'  => is_array($gravatar_fs) ? ($gravatar_fs['g_custom_gravatar_server'] ?? '') : '',
        );
        $server_key = is_array($gravatar_fs) ? ($gravatar_fs['g_select_gravatar_server'] ?? 'geekzu') : 'geekzu';
        $gravatar_host = $server_map[$server_key] ?? 'sdn.geekzu.org';
        if (empty($gravatar_host)) {
            $gravatar_host = 'secure.gravatar.com';
        }
    } else {
        $gravatar_host = 'secure.gravatar.com';
    }
    $reply_email = trim($comment->comment_author_email);
    $parent_avatar = esc_url('https://' . $gravatar_host . '/avatar/' . md5(strtolower($parent_email)) . '?s=32&d=identicon&r=g');
    $reply_avatar = esc_url('https://' . $gravatar_host . '/avatar/' . md5(strtolower($reply_email)) . '?s=32&d=identicon&r=g');

    $wp_email = kratos_option('m_username') ?: get_option('admin_email');
    $subject = sprintf(__('您在《%1$s》的评论有新回复 - %2$s', 'kratos'), esc_html($post_title), $site_name);

    $message = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html__('您的评论有新回复', 'kratos') . '</title>
    <style>
        body { background: #f8f5f0; padding: 20px !important; font-family: "微软雅黑", "PingFang SC", Arial, sans-serif; }
        .email-wrapper { max-width: 750px; margin: 0 auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow: hidden; }
        .header { padding: 20px 0; text-align: center; border-bottom: 1px solid #f0f0f0; }
        .header .logo { display: inline-flex; align-items: center; gap: 8px; font-size: 18px; font-weight: bold; color: #333; text-decoration: none; }
        .title-bar { padding: 10px 20px; font-size: 16px; color: #333; border-bottom: 1px solid #f0f0f0; text-align: center;}
        .comment-section { padding: 10px 20px; }
        .comment-item { margin-bottom: 30px; display: flex; flex-direction: column; align-items: flex-end; }
        .comment-item.reply { align-items: flex-start; }
        .comment-meta { font-size: 14px; color: #999; margin-bottom: 10px; width: 100%; text-align: center !important; }
        .comment-author-wrap { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .comment-item .comment-author-wrap { justify-content: flex-end; }
        .comment-item.reply .comment-author-wrap { justify-content: flex-start; }
        .comment-author { font-size: 15px; font-weight: 500; color: #333; }
        .comment-avatar {
            width: 32px !important;
            height: 32px !important;
            border-radius: 50% !important;
            flex-shrink: 0 !important;
            object-fit: cover !important;
            border: 0 none !important;
            display: block !important;
        }
        .comment-bubble { background: #f0f0f0; padding: 12px 16px; border-radius: 8px; line-height: 1.6; font-size: 15px; color: #333; max-width: 80%; }
        .comment-item.reply .comment-bubble { background: #e6f3ff; }
        .action-btn { display: block; width: 160px; line-height: 1.5; padding: 10px 0; background: #21759b; color: #fff; text-align: center; border-radius: 4px; text-decoration: none; margin: 30px auto; font-size: 16px; }
        .footer { padding: 10px 20px;  border-top: 1px solid #f0f0f0; }
        @media (max-width: 600px) {
            .comment-section { padding: 15px 20px; }
            .comment-avatar { width: 32px !important; height: 32px !important; }
            .comment-bubble { max-width: 90%; }
            .action-btn { width: 140px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="header">
            <a href="' . $site_url . '" class="logo" target="_blank">
                <span>' . esc_html($site_name) . '</span>
            </a>
        </div>
        <div class="title-bar">
            ' . sprintf(esc_html__('敬启者，您在《%s》的评论有新回复', 'kratos'), esc_html($post_title)) . '
        </div>
        <div class="comment-section">
            <div class="comment-item">
                <div class="comment-meta">' . esc_html(get_comment_date('Y-m-d H:i:s', $parent_comment->comment_ID)) . '</div>
                <div class="comment-author-wrap">
                    <div class="comment-author">' . esc_html($parent_comment->comment_author) . '</div>
                    <img class="comment-avatar"
                         src="' . $parent_avatar . '"
                         alt="' . esc_attr($parent_comment->comment_author) . '"
                         width="32" height="32">
                </div>
                <div class="comment-bubble">
                    ' . kratos_mail_format_comment($parent_comment->comment_content) . '
                </div>
            </div>
            <div class="comment-item reply">
                <div class="comment-meta">' . esc_html(get_comment_date('Y-m-d H:i:s', $comment->comment_ID)) . '</div>
                <div class="comment-author-wrap">
                    <img class="comment-avatar"
                         src="' . $reply_avatar . '"
                         alt="' . esc_attr($comment->comment_author) . '"
                         width="32" height="32">
                    <div class="comment-author">' . esc_html($comment->comment_author) . '</div>
                </div>
                <div class="comment-bubble">
                    ' . kratos_mail_format_comment($comment->comment_content) . '
                </div>
            </div>
        </div>
        <a href="' . $comment_link . '" class="action-btn" target="_blank">' . esc_html__('查看完整内容', 'kratos') . '</a>
        <div class="footer">
            <span style="text-align: left; display: block;">' . esc_html__('顺颂商祺', 'kratos') . '</span>
            <span style="text-align: right; display: block;">' . esc_html($site_name) . ' ' . esc_html__('团队', 'kratos') . '</span>
            <span style="text-align: right; display: block;">' . esc_html(wp_date('Y年m月d日')) . '</span>
            <p style="text-align: center; font-size: 12px; color: #999;">' . sprintf(esc_html__('此邮件由 %s 自动发送，请勿直接回复', 'kratos'), esc_html($site_name)) . '</p>
        </div>
    </div>
</body>
</html>';

    $from = 'From: "' . htmlspecialchars_decode($site_name, ENT_QUOTES) . '" <' . $wp_email . '>';
    $headers = array(
        'Content-Type: text/html; charset=' . get_option('blog_charset'),
        $from,
    );
    wp_mail($parent_email, $subject, $message, $headers);
}
add_action('comment_post', 'comment_notify', 10, 3);
add_action('comment_unapproved_to_approved', function ($comment) {
    if (is_object($comment)) {
        comment_notify($comment->comment_ID);
    }
}, 10, 1);
