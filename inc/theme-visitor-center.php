<?php
/**
 * 游客中心（Visitor Center）
 *
 * 独立页面（page-visitor-center.php），根据「访客留言时填写的邮箱」异步
 * 拉取聚合信息：等级 / 走心数 / 评论总数 / 被回复数 / 陪伴天数 /
 * 成就徽章（自动 + 手动授予）/ 最近评论 / 常访文章 / 近一年活跃度。
 *
 * 身份识别：仅依赖 WP 原生留言 cookie `comment_author_email_{COOKIEHASH}`，
 * 前端读到后取 md5 交给 REST 接口查询；DB / URL 中不落明文邮箱。
 *
 * 手动徽章：主题选项「基础配置 → 游客中心」的「手动徽章」group 字段。
 * 博主在后台填明文邮箱，保存时通过 pre_save filter 转成 md5(email) 落库
 * （字段值持久化为 hash），另存 hint 供博主自己辨认。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

const KRATOS_VC_TRANSIENT_PREFIX = 'kratos_vc_';
const KRATOS_VC_TRANSIENT_TTL    = 10 * MINUTE_IN_SECONDS;
const KRATOS_VC_RECENT_LIMIT     = 10;
const KRATOS_VC_TOP_POSTS_LIMIT  = 5;

/* ============================================================
 *  开关
 * ============================================================ */

function kratos_vc_enabled()
{
    return (bool) kratos_option('g_visitor_center_enable', false);
}

/**
 * 找到使用「游客中心」模板的第一个已发布页面的 URL；结果缓存
 * 直到该 page 保存 / 删除时失效。找不到返回空串。
 */
function kratos_vc_page_url()
{
    $cached = get_transient('kratos_vc_page_url');
    if (is_string($cached)) return $cached;

    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(array(
            'key'   => '_wp_page_template',
            'value' => 'page-visitor-center.php',
        )),
    ));
    $url = !empty($pages) ? (string) get_permalink((int) $pages[0]) : '';
    set_transient('kratos_vc_page_url', $url, DAY_IN_SECONDS);
    return $url;
}

function kratos_vc_flush_page_url_cache($post_id)
{
    if (get_post_type($post_id) === 'page') {
        delete_transient('kratos_vc_page_url');
    }
}
add_action('save_post', 'kratos_vc_flush_page_url_cache');
add_action('deleted_post', 'kratos_vc_flush_page_url_cache');

/**
 * 后台「重置密钥」按钮入口
 */
function kratos_vc_admin_reset_secret()
{
    if (!current_user_can('manage_options')) wp_die(__('权限不足', 'kratos'));
    check_admin_referer('kratos_vc_reset_secret');
    delete_option('kratos_vc_secret');
    kratos_vc_get_secret(); // 立即触发再生成
    wp_safe_redirect(add_query_arg('kratos_vc_reset', '1', wp_get_referer() ?: admin_url()));
    exit;
}
add_action('admin_post_kratos_vc_reset_secret', 'kratos_vc_admin_reset_secret');

/**
 * 生成某访客邮箱的游客中心链接；未启用 / 无页面时返回空串
 */
function kratos_vc_link_for_email($email)
{
    if (!kratos_vc_enabled()) return '';
    $token = kratos_vc_encrypt_email($email);
    if ($token === '') return '';
    $base = kratos_vc_page_url();
    if ($base === '') return '';
    return add_query_arg('vc', $token, $base);
}

/* ============================================================
 *  邮箱加密令牌（AES-256-GCM）
 *
 *  用于「评论页等级徽章 → 别人的游客中心」这条链路：
 *    - 徽章渲染时服务端加密邮箱得到 token，作为 URL ?vc=<token>
 *    - REST 端接收 token 并解密还原邮箱
 *  密钥仅存服务端（wp_options），前端拿不到。首次访问自动生成 32 字节随机 key。
 * ============================================================ */

function kratos_vc_get_secret()
{
    $secret = get_option('kratos_vc_secret', '');
    if (!$secret || strlen($secret) < 44 /* base64 32 bytes ≈ 44 chars */) {
        $secret = base64_encode(random_bytes(32));
        update_option('kratos_vc_secret', $secret, false);
    }
    return base64_decode($secret);
}

/**
 * 加密邮箱 → URL-safe base64 令牌
 *
 * 结构：base64url(iv[12] || ciphertext || tag[16])
 */
function kratos_vc_encrypt_email($email)
{
    $email = strtolower(trim((string) $email));
    if ($email === '' || !function_exists('openssl_encrypt')) return '';
    $iv     = random_bytes(12);
    $tag    = '';
    $cipher = openssl_encrypt($email, 'aes-256-gcm', kratos_vc_get_secret(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return '';
    return rtrim(strtr(base64_encode($iv . $cipher . $tag), '+/', '-_'), '=');
}

/**
 * 解密令牌 → 邮箱明文；失败或篡改返回空串
 */
function kratos_vc_decrypt_token($token)
{
    if (!is_string($token) || $token === '' || !function_exists('openssl_decrypt')) return '';
    $bin = base64_decode(strtr($token, '-_', '+/'), true);
    if ($bin === false || strlen($bin) < 12 + 16 + 1) return '';
    $iv     = substr($bin, 0, 12);
    $tag    = substr($bin, -16);
    $cipher = substr($bin, 12, -16);
    $email  = openssl_decrypt($cipher, 'aes-256-gcm', kratos_vc_get_secret(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($email === false) return '';
    return is_email($email) ? strtolower($email) : '';
}

/* ============================================================
 *  聚合查询
 * ============================================================ */

/**
 * 按邮箱取一条最近评论用于反填昵称 / 头像 / URL
 */
function kratos_vc_pick_author_by_email($email)
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT comment_author, comment_author_email, comment_author_url
         FROM {$wpdb->comments}
         WHERE comment_approved = '1' AND (comment_type = '' OR comment_type = 'comment')
           AND LOWER(comment_author_email) = %s
         ORDER BY comment_ID DESC LIMIT 1",
        strtolower($email)
    ), ARRAY_A);
    return $row ?: null;
}

/**
 * 聚合此邮箱的全部指标
 *
 * @param string $email 明文邮箱（服务端已 trim + lower）
 * @return array
 */
function kratos_vc_aggregate($email)
{
    $email  = strtolower(trim((string) $email));
    if ($email === '') return array('found' => false);

    $author = kratos_vc_pick_author_by_email($email);
    if (!$author) {
        return array('found' => false);
    }

    global $wpdb;

    // 基础：总数 / 首评 / 末评 / 深夜数
    $base = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS total,
                MIN(comment_date) AS first_date,
                MAX(comment_date) AS last_date,
                SUM(CASE WHEN HOUR(comment_date) < 6 THEN 1 ELSE 0 END) AS night_cnt
         FROM {$wpdb->comments}
         WHERE comment_approved = '1' AND (comment_type = '' OR comment_type = 'comment')
           AND LOWER(comment_author_email) = %s",
        strtolower($email)
    ), ARRAY_A);

    $total     = isset($base['total']) ? (int) $base['total'] : 0;
    $first_ts  = !empty($base['first_date']) ? strtotime($base['first_date']) : 0;
    $night_cnt = isset($base['night_cnt']) ? (int) $base['night_cnt'] : 0;

    // 走心数
    $heart_key = defined('KRATOS_HEART_META_KEY') ? KRATOS_HEART_META_KEY : 'kratos_heart';
    $heart_cnt = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->comments} c
         INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID
         WHERE c.comment_approved = '1'
           AND LOWER(c.comment_author_email) = %s
           AND m.meta_key = %s AND m.meta_value = '1'",
        strtolower($email), $heart_key
    ));

    // 被回复数（自己评论的 comment_parent 又被他人评论过）
    $reply_cnt = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->comments} r
         INNER JOIN {$wpdb->comments} p ON p.comment_ID = r.comment_parent
         WHERE r.comment_approved = '1' AND r.comment_parent > 0
           AND LOWER(p.comment_author_email) = %s
           AND LOWER(r.comment_author_email) <> %s",
        strtolower($email), strtolower($email)
    ));

    // 被博主回复数
    $admin_reply_cnt = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->comments} r
         INNER JOIN {$wpdb->comments} p ON p.comment_ID = r.comment_parent
         INNER JOIN {$wpdb->users} u ON u.ID = r.user_id
         INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
         WHERE r.comment_approved = '1' AND r.comment_parent > 0
           AND LOWER(p.comment_author_email) = %s
           AND um.meta_key = %s
           AND um.meta_value LIKE %s",
        strtolower($email),
        $wpdb->prefix . 'capabilities',
        '%administrator%'
    ));

    // 陪伴天数
    $days = $first_ts ? max(1, floor((time() - $first_ts) / DAY_IN_SECONDS)) : 0;

    // 最近评论
    $recent = $wpdb->get_results($wpdb->prepare(
        "SELECT comment_ID, comment_post_ID, comment_content, comment_date
         FROM {$wpdb->comments}
         WHERE comment_approved = '1' AND (comment_type = '' OR comment_type = 'comment')
           AND LOWER(comment_author_email) = %s
         ORDER BY comment_ID DESC LIMIT %d",
        strtolower($email), KRATOS_VC_RECENT_LIMIT
    ), ARRAY_A);

    $recent_list = array();
    foreach ((array) $recent as $r) {
        $post_id  = (int) $r['comment_post_ID'];
        $is_heart = get_comment_meta((int) $r['comment_ID'], $heart_key, true) === '1';
        $recent_list[] = array(
            'id'        => (int) $r['comment_ID'],
            'excerpt'   => wp_trim_words(wp_strip_all_tags($r['comment_content']), 60, '…'),
            'post_id'   => $post_id,
            'post_title'=> get_the_title($post_id),
            'link'      => get_comment_link((int) $r['comment_ID']),
            'date'      => mysql2date('Y-m-d H:i', $r['comment_date']),
            'ts'        => strtotime($r['comment_date']),
            'heart'     => $is_heart,
        );
    }

    // 常访文章 top N
    $top_posts_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT comment_post_ID, COUNT(*) AS c
         FROM {$wpdb->comments}
         WHERE comment_approved = '1' AND (comment_type = '' OR comment_type = 'comment')
           AND LOWER(comment_author_email) = %s
         GROUP BY comment_post_ID
         ORDER BY c DESC LIMIT %d",
        strtolower($email), KRATOS_VC_TOP_POSTS_LIMIT
    ), ARRAY_A);
    $top_posts = array();
    foreach ((array) $top_posts_raw as $r) {
        $pid = (int) $r['comment_post_ID'];
        $top_posts[] = array(
            'post_id' => $pid,
            'title'   => get_the_title($pid),
            'link'    => get_permalink($pid),
            'count'   => (int) $r['c'],
        );
    }

    // 近一年活跃：按日聚合
    $activity = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(comment_date) AS d, COUNT(*) AS c
         FROM {$wpdb->comments}
         WHERE comment_approved = '1' AND (comment_type = '' OR comment_type = 'comment')
           AND LOWER(comment_author_email) = %s
           AND comment_date >= %s
         GROUP BY DATE(comment_date)",
        strtolower($email), date('Y-m-d', strtotime('-365 days'))
    ), ARRAY_A);
    $activity_map = array();
    foreach ((array) $activity as $r) {
        $activity_map[$r['d']] = (int) $r['c'];
    }

    // 等级（复用 comment-rank 的等级配置）
    $level = null;
    if (function_exists('kratos_rank_match')) {
        $matched = kratos_rank_match($total);
        if ($matched) {
            $level = array(
                'title'    => $matched['title'],
                'color'    => $matched['color'],
                'bg_color' => $matched['bg_color'],
            );
        }
    }

    // 地域
    $region = '';
    if (function_exists('kratos_ip2region_lookup')) {
        // 复用地域解析：取该邮箱最近一条评论的 IP
        $last_ip = $wpdb->get_var($wpdb->prepare(
            "SELECT comment_author_IP FROM {$wpdb->comments}
             WHERE comment_approved = '1' AND LOWER(comment_author_email) = %s
             ORDER BY comment_ID DESC LIMIT 1",
            strtolower($email)
        ));
        if ($last_ip) {
            $info = kratos_ip2region_lookup($last_ip);
            if (is_array($info)) {
                $region = trim(($info['province'] ?? '') . ' ' . ($info['city'] ?? ''));
            }
        }
    }

    // 徽章
    $badges = kratos_vc_calc_badges(array(
        'total'            => $total,
        'heart_cnt'        => $heart_cnt,
        'first_ts'         => $first_ts,
        'night_cnt'        => $night_cnt,
        'admin_reply_cnt'  => $admin_reply_cnt,
        'activity_map'     => $activity_map,
    ));

    // 手动徽章
    $manual = kratos_vc_manual_badges_for_email($email);
    foreach ($manual as $m) {
        $m['icon_svg'] = kratos_vc_icon_svg($m['icon'], 'kvc-badge-svg');
        $badges[] = array_merge($m, array('unlocked' => true, 'manual' => true));
    }

    // 邮箱脱敏
    $masked_email = kratos_vc_mask_email($email);

    return array(
        'found'          => true,
        'name'           => $author['comment_author'],
        'email_masked'   => $masked_email,
        'url'            => $author['comment_author_url'],
        'avatar'         => get_avatar_url($email, array('size' => 144)),
        'level'          => $level,
        'region'         => $region,
        'stats'          => array(
            'total'           => $total,
            'heart'           => $heart_cnt,
            'reply'           => $reply_cnt,
            'admin_reply'     => $admin_reply_cnt,
            'days'            => (int) $days,
            'first_date'      => $first_ts ? date_i18n('Y-m-d', $first_ts) : '',
        ),
        'badges'         => $badges,
        'recent'         => $recent_list,
        'top_posts'      => $top_posts,
        'activity'       => $activity_map,
        'heart_badge' => kratos_heart_badge_html(),
    );
}

function kratos_vc_mask_email($email)
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return $email;
    $local = $parts[0];
    $keep  = min(2, strlen($local));
    return substr($local, 0, $keep) . str_repeat('*', max(2, strlen($local) - $keep)) . '@' . $parts[1];
}

/* ============================================================
 *  自动徽章规则
 * ============================================================ */

/**
 * 徽章规则表
 *
 * 每条：
 *   id       — 唯一标识
 *   icon     — emoji / 图标字符
 *   name     — 徽章名
 *   desc     — 描述文案
 *   check(ctx) — 返回 [unlocked, progress_text|null]
 */
function kratos_vc_badge_rules()
{
    return array(
        array(
            'id' => 'newcomer', 'icon' => 'fas fa-seedling', 'name' => '初来乍到', 'desc' => '在本站留下第一条评论',
            'check' => function ($c) { return array($c['total'] >= 1, null); },
        ),
        array(
            'id' => 'ten', 'icon' => 'fas fa-comment-dots', 'name' => '十评达成', 'desc' => '累计评论 ≥ 10 条',
            'check' => function ($c) {
                return $c['total'] >= 10 ? array(true, null) : array(false, '还差 ' . (10 - $c['total']) . ' 条');
            },
        ),
        array(
            'id' => 'hundred', 'icon' => 'fas fa-comments', 'name' => '百评达成', 'desc' => '累计评论 ≥ 100 条',
            'check' => function ($c) {
                return $c['total'] >= 100 ? array(true, null) : array(false, '还差 ' . (100 - $c['total']) . ' 条');
            },
        ),
        array(
            'id' => 'legendary', 'icon' => 'fas fa-star', 'name' => '千评之星', 'desc' => '累计评论 ≥ 1000 条',
            'check' => function ($c) {
                return $c['total'] >= 1000 ? array(true, null) : array(false, '还差 ' . (1000 - $c['total']) . ' 条');
            },
        ),
        array(
            'id' => 'heart', 'icon' => 'fas fa-heart', 'name' => '走心之选', 'desc' => '至少一条评论被博主标记为走心',
            'check' => function ($c) { return array($c['heart_cnt'] >= 1, null); },
        ),
        array(
            'id' => 'heart_ten', 'icon' => 'fas fa-hand-holding-heart', 'name' => '十次走心', 'desc' => '累计 10 条走心评论',
            'check' => function ($c) {
                return $c['heart_cnt'] >= 10 ? array(true, null) : array(false, '还差 ' . (10 - $c['heart_cnt']) . ' 条');
            },
        ),
        array(
            'id' => 'anniversary', 'icon' => 'fas fa-award', 'name' => '年度铁粉', 'desc' => '首次评论距今满一年',
            'check' => function ($c) {
                if (!$c['first_ts']) return array(false, null);
                $d = floor((time() - $c['first_ts']) / DAY_IN_SECONDS);
                return $d >= 365 ? array(true, null) : array(false, '还差 ' . (365 - $d) . ' 天');
            },
        ),
        array(
            'id' => 'night_owl', 'icon' => 'fas fa-moon', 'name' => '深夜书生', 'desc' => '凌晨 0-6 点评论 ≥ 20 条',
            'check' => function ($c) {
                return $c['night_cnt'] >= 20 ? array(true, null) : array(false, '还差 ' . (20 - $c['night_cnt']) . ' 条');
            },
        ),
        array(
            'id' => 'blessed', 'icon' => 'fas fa-bullseye', 'name' => '博主青睐', 'desc' => '被博主亲自回复 ≥ 10 次',
            'check' => function ($c) {
                return $c['admin_reply_cnt'] >= 10 ? array(true, null) : array(false, '还差 ' . (10 - $c['admin_reply_cnt']) . ' 次');
            },
        ),
        array(
            'id' => 'streak7', 'icon' => 'fas fa-fire', 'name' => '连续七日', 'desc' => '最近一段时间内曾连续 7 天留言',
            'check' => function ($c) {
                if (empty($c['activity_map'])) return array(false, null);
                $dates = array_keys($c['activity_map']);
                sort($dates);
                $streak = 1;
                $best = 1;
                for ($i = 1; $i < count($dates); $i++) {
                    $prev = strtotime($dates[$i-1]);
                    $cur  = strtotime($dates[$i]);
                    if ($cur - $prev === DAY_IN_SECONDS) {
                        $streak++;
                        if ($streak > $best) $best = $streak;
                    } else {
                        $streak = 1;
                    }
                }
                return $best >= 7 ? array(true, null) : array(false, '当前最长 ' . $best . ' 天');
            },
        ),
    );
}

/**
 * 徽章 icon class → inline SVG（服务端预渲染，前端直接注入）
 */
function kratos_vc_icon_svg($icon_class, $extra_class = '')
{
    if (function_exists('kratos_icon') && $icon_class !== '') {
        return kratos_icon($icon_class, $extra_class);
    }
    return '';
}

function kratos_vc_calc_badges($ctx)
{
    $out = array();
    foreach (kratos_vc_badge_rules() as $rule) {
        list($unlocked, $progress) = call_user_func($rule['check'], $ctx);
        $out[] = array(
            'id'       => $rule['id'],
            'icon'     => $rule['icon'],
            'icon_svg' => kratos_vc_icon_svg($rule['icon'], 'kvc-badge-svg'),
            'name'     => $rule['name'],
            'desc'     => $rule['desc'],
            'unlocked' => (bool) $unlocked,
            'progress' => $progress,
            'manual'   => false,
        );
    }
    return $out;
}

/* ============================================================
 *  手动徽章（后台按邮箱授予）
 * ============================================================ */

function kratos_vc_manual_badges_raw()
{
    $val = kratos_option('g_visitor_center_manual_badges', array());
    return is_array($val) ? $val : array();
}

/**
 * 命中当前邮箱的手动徽章列表（按 lowercase 邮箱匹配）
 */
function kratos_vc_manual_badges_for_email($email)
{
    $email = strtolower(trim((string) $email));
    if ($email === '') return array();
    $out = array();
    foreach (kratos_vc_manual_badges_raw() as $row) {
        if (!is_array($row) || empty($row['email'])) continue;
        if (strtolower(trim((string) $row['email'])) !== $email) continue;
        $out[] = array(
            'id'         => 'manual_' . substr(md5(($row['name'] ?? '') . ($row['granted_at'] ?? '')), 0, 8),
            'icon'       => (string) ($row['icon'] ?? 'fas fa-trophy'),
            'name'       => (string) ($row['name'] ?? '博主授予'),
            'desc'       => (string) ($row['desc'] ?? ''),
            'granted_at' => (string) ($row['granted_at'] ?? ''),
        );
    }
    return $out;
}

/* ============================================================
 *  REST 接口
 * ============================================================ */

function kratos_vc_register_rest()
{
    // 用 POST + body 传参：邮箱不进 URL / access log / referer。
    // 参数二选一：
    //   - email：访问自己的档案（从留言 cookie 读）
    //   - hash ：访问别人的档案（评论页等级徽章链接过来，URL 只带 md5(email)）
    //           服务端用 SQL 反查最近评论者，命中则聚合，未命中即"未活跃"。
    register_rest_route('kratos/v1', '/visitor', array(
        'methods'  => 'POST',
        'callback' => 'kratos_vc_rest_post',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'kratos_vc_register_rest');

function kratos_vc_rest_post($request)
{
    if (!kratos_vc_enabled()) {
        return new WP_Error('vc_disabled', __('游客中心未启用', 'kratos'), array('status' => 404));
    }

    $email = '';
    $token = trim((string) $request->get_param('token'));
    if ($token !== '') {
        // 别人档案：URL 里的 AES 令牌，解密还原邮箱
        $email = kratos_vc_decrypt_token($token);
        if ($email === '') {
            return rest_ensure_response(array('found' => false));
        }
    } else {
        // 本人档案：从 cookie 读到明文邮箱，走 POST body（HTTPS）
        $email = strtolower(trim((string) $request->get_param('email')));
        if ($email === '' || !is_email($email)) {
            return new WP_Error('vc_bad_email', __('邮箱格式不合法', 'kratos'), array('status' => 400));
        }
    }

    $cache_key = KRATOS_VC_TRANSIENT_PREFIX . md5($email);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        $cached['_cached'] = true;
        return rest_ensure_response($cached);
    }

    $data = kratos_vc_aggregate($email);
    if (empty($data['found'])) {
        return rest_ensure_response(array('found' => false));
    }
    set_transient($cache_key, $data, KRATOS_VC_TRANSIENT_TTL);
    $data['_cached'] = false;
    return rest_ensure_response($data);
}

/**
 * 新评论审核通过时按邮箱 hash 让缓存失效
 */
function kratos_vc_invalidate_on_status($new_status, $old_status, $comment)
{
    if (!$comment instanceof WP_Comment) return;
    if ($new_status === $old_status) return;
    $email = (string) $comment->comment_author_email;
    if ($email === '') return;
    delete_transient(KRATOS_VC_TRANSIENT_PREFIX . md5(strtolower($email)));
}
add_action('transition_comment_status', 'kratos_vc_invalidate_on_status', 10, 3);

function kratos_vc_invalidate_on_insert($comment_id, $comment)
{
    if (!($comment instanceof WP_Comment)) {
        $comment = get_comment($comment_id);
    }
    if (!$comment) return;
    delete_transient(KRATOS_VC_TRANSIENT_PREFIX . md5(strtolower((string) $comment->comment_author_email)));
}
add_action('wp_insert_comment', 'kratos_vc_invalidate_on_insert', 10, 2);

/**
 * 走心状态变更也失效
 */
function kratos_vc_invalidate_on_heart($meta_id, $comment_id, $meta_key)
{
    if ($meta_key !== (defined('KRATOS_HEART_META_KEY') ? KRATOS_HEART_META_KEY : 'kratos_heart')) return;
    $c = get_comment((int) $comment_id);
    if (!$c) return;
    delete_transient(KRATOS_VC_TRANSIENT_PREFIX . md5(strtolower((string) $c->comment_author_email)));
}
add_action('updated_comment_meta', 'kratos_vc_invalidate_on_heart', 10, 3);
add_action('added_comment_meta',   'kratos_vc_invalidate_on_heart', 10, 3);
add_action('deleted_comment_meta', 'kratos_vc_invalidate_on_heart', 10, 3);

/* ============================================================
 *  body class + 资源入队
 * ============================================================ */

function kratos_vc_is_page_template()
{
    return is_page() && function_exists('is_page_template') && is_page_template('page-visitor-center.php');
}

function kratos_vc_body_class($classes)
{
    if (kratos_vc_is_page_template()) {
        $classes[] = 'is-kratos-visitor-center-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_vc_body_class');

function kratos_vc_enqueue()
{
    if (!kratos_vc_enabled() || !kratos_vc_is_page_template()) return;
    $ver = defined('THEME_VERSION') ? THEME_VERSION : '1.0';
    wp_enqueue_style(
        'kratos-visitor-center',
        get_template_directory_uri() . '/assets/css/visitor-center.css',
        array('kratos-components'),
        $ver
    );
    wp_enqueue_script(
        'kratos-visitor-center',
        get_template_directory_uri() . '/assets/js/visitor-center.js',
        array(),
        $ver,
        true
    );
    // 前端要用到的 SVG 图标（服务端 kratos_icon() 预渲染，前端直接注入）
    $svg = array(
        'empty'     => kratos_vc_icon_svg('fas fa-hand-sparkles', 'kvc-svg'),
        'notfound'  => kratos_vc_icon_svg('fas fa-magnifying-glass', 'kvc-svg'),
        'level'     => kratos_vc_icon_svg('fas fa-trophy', 'kvc-svg'),
        'heart'     => kratos_vc_icon_svg('fas fa-heart', 'kvc-svg'),
        'region'    => kratos_vc_icon_svg('fas fa-location-dot', 'kvc-svg'),
        'chat'      => kratos_vc_icon_svg('fas fa-comment', 'kvc-svg'),
        'home'      => kratos_vc_icon_svg('fas fa-link', 'kvc-svg'),
    );
    wp_localize_script('kratos-visitor-center', 'KratosVC', array(
        'restUrl'    => esc_url_raw(rest_url('kratos/v1/visitor')),
        'nonce'      => wp_create_nonce('wp_rest'),
        'svg'        => $svg,
        'i18n'       => array(
            'page_title'   => __('游客中心', 'kratos'),
            'page_sub'     => __('这里记录了你在本站留下的痕迹', 'kratos'),
            'empty_title'  => __('还没在本站留下痕迹', 'kratos'),
            'empty_desc'   => __('游客中心通过你留言时填写的邮箱来识别身份，先去任意文章留一条评论，再回来这里查看你的档案吧', 'kratos'),
            'empty_cta'    => __('去最新文章看看', 'kratos'),
            'not_found'    => __('没有找到该邮箱的评论记录', 'kratos'),
            'load_error'   => __('加载失败，请稍后重试', 'kratos'),
            'level'        => __('等级', 'kratos'),
            'total'        => __('评论总数', 'kratos'),
            'heart'        => __('走心评论', 'kratos'),
            'reply'        => __('被回复数', 'kratos'),
            'days'         => __('陪伴天数', 'kratos'),
            'badges'       => __('成就徽章', 'kratos'),
            'recent'       => __('最近评论', 'kratos'),
            'top_posts'    => __('最常评论的文章', 'kratos'),
            'activity'     => __('近一年活跃', 'kratos'),
            'manual_tag'   => __('特别授予', 'kratos'),
            'less'         => __('少', 'kratos'),
            'more'         => __('多', 'kratos'),
        ),
        'homeUrl'    => esc_url(home_url('/')),
    ));
}
add_action('wp_enqueue_scripts', 'kratos_vc_enqueue');

/* ============================================================
 *  内联 CSS 提示（隐藏页面标题的默认 h1）
 * ============================================================ */

// 无
