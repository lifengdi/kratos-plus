<?php
/**
 * 评论排行榜
 *
 * 功能：
 *   - 统计每位评论者的已审核评论数，按评论数降序展示 TOP N
 *   - 每人展示：头像 / 用户名 / 评论数 / 最后一次评论时间 / 友链标签（若命中 Blogroll）
 *   - 若填写了个人网站，用户名即为跳转链接（在新标签打开）
 *   - 展示数量 / 标题 / 副标题在「评论配置 → 通用配置 → 评论排行榜」中配置
 *
 * 数据聚合：
 *   - 已登录用户按 user_id 归并（同一账号换邮箱仍算同一人）
 *   - 游客按 comment_author_email 归并
 *   - 匿名（无邮箱）不参与排行
 *   - 结果 transient 缓存 30 分钟，评论审核/删除时清除
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

const KRATOS_TOP_COMMENTERS_CACHE_KEY = 'kratos_top_commenters';
const KRATOS_TOP_COMMENTERS_CACHE_TTL = 30 * MINUTE_IN_SECONDS;

/* ============================================================
 *  数据查询
 * ============================================================ */

/**
 * 拉取评论排行榜数据。
 *
 * @param int $limit 展示数量（>0）
 * @return array<int, array{
 *   key: string,
 *   user_id: int,
 *   name: string,
 *   email: string,
 *   url: string,
 *   count: int,
 *   last_time: int,
 *   avatar_html: string,
 *   is_friend: bool,
 *   comment_id: int
 * }>
 */
function kratos_top_commenters_get($limit = 20)
{
    $limit = max(1, (int) $limit);

    $cache_key = KRATOS_TOP_COMMENTERS_CACHE_KEY . '_' . $limit;
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;

    // 分两条 SQL：登录用户按 user_id 归并 + 游客按邮箱归并；再合并排序取前 N。
    // 只统计已审核的普通评论（不含 trackback/pingback）。
    $rows_user = $wpdb->get_results(
        "SELECT
            user_id,
            COUNT(*)                          AS cnt,
            MAX(comment_ID)                   AS last_cid,
            MAX(UNIX_TIMESTAMP(comment_date)) AS last_time
         FROM {$wpdb->comments}
         WHERE comment_approved = '1'
           AND (comment_type = '' OR comment_type = 'comment')
           AND user_id > 0
         GROUP BY user_id
         ORDER BY cnt DESC
         LIMIT " . ($limit * 2),
        ARRAY_A
    );

    // 游客：只统计未登录（user_id = 0）且填写了邮箱的评论
    $rows_guest = $wpdb->get_results($wpdb->prepare(
        "SELECT
            comment_author_email              AS email,
            COUNT(*)                          AS cnt,
            MAX(comment_ID)                   AS last_cid,
            MAX(UNIX_TIMESTAMP(comment_date)) AS last_time
         FROM {$wpdb->comments}
         WHERE comment_approved = '1'
           AND (comment_type = '' OR comment_type = 'comment')
           AND user_id = 0
           AND comment_author_email <> ''
         GROUP BY comment_author_email
         ORDER BY cnt DESC
         LIMIT %d",
        $limit * 2
    ), ARRAY_A);

    $items = array();

    foreach ((array) $rows_user as $r) {
        $uid  = (int) $r['user_id'];
        $user = get_userdata($uid);
        if (!$user) continue;

        // 用「该用户最新一条评论」的字段作为展示信息（避免用户改邮箱后头像/名字不同步）
        $latest = get_comment((int) $r['last_cid']);
        $email  = $user->user_email;
        $name   = $latest && $latest->comment_author !== '' ? $latest->comment_author : $user->display_name;
        $url    = $latest ? $latest->comment_author_url : $user->user_url;
        $avatar = get_avatar($email, 64, '', $name, array('class' => 'ktc-avatar-img'));

        $items[] = array(
            'key'         => 'u_' . $uid,
            'user_id'     => $uid,
            'name'        => $name,
            'email'       => (string) $email,
            'url'         => (string) $url,
            'count'       => (int) $r['cnt'],
            'last_time'   => (int) $r['last_time'],
            'avatar_html' => $avatar,
            'is_friend'   => $latest ? kratos_top_commenters_is_friend((string) $url) : false,
            'comment_id'  => (int) $r['last_cid'],
        );
    }

    foreach ((array) $rows_guest as $r) {
        $email = (string) $r['email'];
        if ($email === '') continue;

        $latest = get_comment((int) $r['last_cid']);
        if (!$latest) continue;

        $name = $latest->comment_author !== '' ? $latest->comment_author : __('匿名', 'kratos');
        $url  = (string) $latest->comment_author_url;

        $avatar = get_avatar($email, 64, '', $name, array('class' => 'ktc-avatar-img'));

        $items[] = array(
            'key'         => 'e_' . md5(strtolower($email)),
            'user_id'     => 0,
            'name'        => $name,
            'email'       => $email,
            'url'         => $url,
            'count'       => (int) $r['cnt'],
            'last_time'   => (int) $r['last_time'],
            'avatar_html' => $avatar,
            'is_friend'   => kratos_top_commenters_is_friend($url),
            'comment_id'  => (int) $r['last_cid'],
        );
    }

    // 排序：评论数 DESC，同数再按最后一次评论时间 DESC
    usort($items, function ($a, $b) {
        if ($a['count'] !== $b['count']) {
            return $b['count'] - $a['count'];
        }
        return $b['last_time'] - $a['last_time'];
    });

    $items = array_slice($items, 0, $limit);

    set_transient($cache_key, $items, KRATOS_TOP_COMMENTERS_CACHE_TTL);
    return $items;
}

/**
 * 判断 URL 是否命中友链列表。复用 theme-comment-link.php 的 host 集合。
 */
function kratos_top_commenters_is_friend($url)
{
    if (!function_exists('kratos_blogroll_normalize_host') || !function_exists('kratos_blogroll_hosts')) {
        return false;
    }
    $host = kratos_blogroll_normalize_host((string) $url);
    if ($host === '') return false;
    $hosts = kratos_blogroll_hosts();
    return isset($hosts[$host]);
}

/**
 * 评论审核状态 / 内容变化时清除全部排行榜缓存。
 * transient 用了 limit 后缀，这里遍历删除常见 limit 值即可。
 */
function kratos_top_commenters_flush_cache()
{
    global $wpdb;
    // 直接把该前缀的所有 transient 全清掉（避免用户后台改 count 后残留旧缓存）
    $like = $wpdb->esc_like('_transient_' . KRATOS_TOP_COMMENTERS_CACHE_KEY . '_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    $like2 = $wpdb->esc_like('_transient_timeout_' . KRATOS_TOP_COMMENTERS_CACHE_KEY . '_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like2));
}
add_action('wp_insert_comment',      'kratos_top_commenters_flush_cache');
add_action('wp_set_comment_status',  'kratos_top_commenters_flush_cache');
add_action('edit_comment',           'kratos_top_commenters_flush_cache');
add_action('deleted_comment',        'kratos_top_commenters_flush_cache');
add_action('trashed_comment',        'kratos_top_commenters_flush_cache');
add_action('untrashed_comment',      'kratos_top_commenters_flush_cache');

/* ============================================================
 *  短码 [top_commenters]
 * ============================================================ */

function kratos_top_commenters_shortcode($atts)
{
    $default_title    = (string) kratos_option('g_comment_top_sc_title', __('评论排行榜', 'kratos'));
    $default_subtitle = (string) kratos_option('g_comment_top_sc_subtitle', __('感谢每一位活跃的朋友，你们的留言让这里更热闹 🎉', 'kratos'));
    $default_limit    = (int) kratos_option('g_comment_top_sc_limit', 20);
    if ($default_limit <= 0) $default_limit = 20;

    $atts = shortcode_atts(array(
        'title'    => $default_title,
        'subtitle' => $default_subtitle,
        'limit'    => $default_limit,
    ), $atts, 'top_commenters');

    $limit = max(1, (int) $atts['limit']);
    $items = kratos_top_commenters_get($limit);

    $title    = (string) $atts['title'];
    $subtitle = (string) $atts['subtitle'];

    // 头三名用皇冠色
    $medal_colors = array('#f5b942', '#c0c0c0', '#cd7f32');

    // 汇总统计（用于三张总览卡）：参与用户 / 总评论数 / 走心评论数
    $total_users    = count($items);
    $total_comments = 0;
    foreach ($items as $it) {
        $total_comments += (int) $it['count'];
    }
    // 走心评论：优先复用 [heart_comments] 短码的统计接口
    $heart_count = 0;
    if (function_exists('kratos_heart_get_stats')) {
        $heart_stats = kratos_heart_get_stats();
        $heart_count = isset($heart_stats['comments']) ? (int) $heart_stats['comments'] : 0;
    }

    $date_fmt = get_option('date_format') . ' ' . get_option('time_format');

    ob_start();
    ?>
    <div class="kratos-topcommenters" id="kratos-topcommenters-list">
        <?php if ($title !== '' || $subtitle !== '') { ?>
            <header class="ktc-header">
                <?php if ($title !== '') { ?>
                    <span class="ktc-title-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15l-5.5 3 2-6-5-4h6L12 2l2.5 6h6l-5 4 2 6z"/></svg>
                    </span>
                    <span class="ktc-title"><?php echo esc_html($title); ?></span>
                <?php } ?>
                <?php if ($subtitle !== '') { ?>
                    <?php if ($title !== '') { ?><span class="ktc-header-divider" aria-hidden="true"></span><?php } ?>
                    <p class="ktc-subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php } ?>
            </header>
        <?php } ?>

        <div class="ktc-stats">
            <div class="ktc-stat ktc-stat-user">
                <span class="ktc-stat-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <div class="ktc-stat-body">
                    <div class="ktc-stat-label"><?php esc_html_e('上榜用户', 'kratos'); ?></div>
                    <div class="ktc-stat-num"><?php echo (int) $total_users; ?></div>
                </div>
            </div>
            <div class="ktc-stat ktc-stat-comment">
                <span class="ktc-stat-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </span>
                <div class="ktc-stat-body">
                    <div class="ktc-stat-label"><?php esc_html_e('累计评论', 'kratos'); ?></div>
                    <div class="ktc-stat-num"><?php echo (int) $total_comments; ?></div>
                </div>
            </div>
            <div class="ktc-stat ktc-stat-heart">
                <span class="ktc-stat-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-9.5-9.1C1.1 8.6 3 5 6.3 5c1.9 0 3.6 1.1 4.4 2.7C11.6 6.1 13.3 5 15.2 5c3.3 0 5.2 3.6 3.8 6.9C19.5 16.4 12 21 12 21z"/></svg>
                </span>
                <div class="ktc-stat-body">
                    <div class="ktc-stat-label"><?php esc_html_e('走心评论', 'kratos'); ?></div>
                    <div class="ktc-stat-num"><?php echo (int) $heart_count; ?></div>
                </div>
            </div>
        </div>

        <?php if (empty($items)) { ?>
            <div class="ktc-empty">
                <?php esc_html_e('暂时还没有评论，快来抢占榜首吧 ✨', 'kratos'); ?>
            </div>
        <?php } else { ?>
            <ol class="ktc-list">
                <?php foreach ($items as $idx => $it) {
                    $rank      = $idx + 1;
                    $medal_bg  = isset($medal_colors[$idx]) ? $medal_colors[$idx] : '';
                    $has_url   = $it['url'] !== '' && preg_match('#^https?://#i', $it['url']);
                    $time_full = $it['last_time'] > 0 ? wp_date($date_fmt, $it['last_time']) : '';
                    $time_rel  = $it['last_time'] > 0 ? human_time_diff($it['last_time'], current_time('timestamp')) . __('前', 'kratos') : '';

                    // 名称 span：外层根据是否有 url 决定 <a>
                    $name_html = '<span class="ktc-name">' . esc_html($it['name']) . '</span>';
                    if ($has_url) {
                        $name_html = '<a class="ktc-name ktc-name-link" href="' . esc_url($it['url']) . '" target="_blank" rel="nofollow noopener external">' . esc_html($it['name']) . '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="ktc-name-arrow"><path d="M7 17L17 7"/><path d="M8 7h9v9"/></svg></a>';
                    }
                ?>
                    <li class="ktc-row<?php echo $rank <= 3 ? ' ktc-row-top' : ''; ?>">
                        <span class="ktc-rank<?php echo $rank <= 3 ? ' ktc-rank-medal' : ''; ?>"<?php echo $medal_bg ? ' style="background:' . esc_attr($medal_bg) . ';"' : ''; ?>><?php echo (int) $rank; ?></span>
                        <span class="ktc-avatar"><?php echo $it['avatar_html']; ?></span>
                        <div class="ktc-body">
                            <div class="ktc-body-row1">
                                <?php echo $name_html; ?>
                                <?php if ($it['is_friend'] && kratos_top_commenters_friend_badge_enabled()) { ?>
                                    <?php echo kratos_top_commenters_friend_badge_html(); ?>
                                <?php } ?>
                            </div>
                            <div class="ktc-body-row2">
                                <span class="ktc-count" title="<?php printf(esc_attr__('%d 条评论', 'kratos'), (int) $it['count']); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    <span class="ktc-count-num"><?php echo (int) $it['count']; ?></span>
                                    <span class="ktc-count-label"><?php esc_html_e('条', 'kratos'); ?></span>
                                </span>
                                <?php if ($time_rel !== '') { ?>
                                    <span class="ktc-dot" aria-hidden="true">·</span>
                                    <span class="ktc-lasttime" title="<?php echo esc_attr($time_full); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <span><?php echo esc_html($time_rel); ?></span>
                                    </span>
                                <?php } ?>
                            </div>
                        </div>
                    </li>
                <?php } ?>
            </ol>
        <?php } ?>
    </div>

    <style>
        /* === 评论排行榜短码：复用走心评论 khs-* 视觉体系，独立 ktc-* 命名空间 === */
        .kratos-topcommenters{
            --khs-bg-1:#f5f5f5;--khs-bg-2:#f0f0f0;--khs-bg-3:#ebebeb;
            --khs-fg:#333;--khs-fg-soft:#444;--khs-fg-dim:#777;--khs-fg-mute:#999;
            --khs-accent:#336699;--khs-accent-2:#2B5278;
            --khs-line:rgba(0,0,0,.08);--khs-line-strong:rgba(0,0,0,.16);
            --khs-card-bg:#ffffff;
            --khs-card-shadow:0 1px 3px rgba(0,0,0,.06);
            --khs-card-shadow-hv:0 8px 18px rgba(0,0,0,.10);
            padding:0;position:relative;background:transparent;
        }
        .kratos-topcommenters > *{position:relative;z-index:1;}

        /* 页头：与走心/归档保持同一视觉 */
        .kratos-topcommenters .ktc-header{
            display:flex;align-items:center;flex-wrap:wrap;gap:14px;
            padding:24px 28px;margin-bottom:18px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:14px;
            box-shadow:var(--khs-card-shadow);
            text-align:left;
        }
        .kratos-topcommenters .ktc-title-icon{
            display:inline-flex;align-items:center;justify-content:center;
            width:38px;height:38px;
            border-radius:10px;
            background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
            color:var(--khs-accent);
        }
        .kratos-topcommenters .ktc-title{
            margin:0;padding:0;font-size:22px;font-weight:700;line-height:1.3;
            color:var(--khs-fg);letter-spacing:0;
        }
        .kratos-topcommenters .ktc-header-divider{
            display:inline-block;width:1px;height:22px;background:var(--khs-line-strong);
        }
        .kratos-topcommenters .ktc-subtitle{
            margin:0;padding:0;font-size:14px;line-height:1.5;color:var(--khs-fg-soft);
        }

        /* 三张总览卡 */
        .kratos-topcommenters .ktc-stats{
            display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;
            margin:0 0 22px;
        }
        .kratos-topcommenters .ktc-stat{
            display:flex;align-items:center;gap:14px;padding:22px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:14px;
            box-shadow:var(--khs-card-shadow);
            transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease;
        }
        .kratos-topcommenters .ktc-stat:hover{
            transform:translateY(-2px);
            box-shadow:var(--khs-card-shadow-hv);
            border-color:var(--khs-line-strong);
        }
        .kratos-topcommenters .ktc-stat-icon{
            flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;
            width:46px;height:46px;border-radius:50%;
            background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
            color:var(--khs-accent);
        }
        .kratos-topcommenters .ktc-stat-body{display:flex;flex-direction:column;gap:2px;min-width:0;}
        .kratos-topcommenters .ktc-stat-label{font-size:13px;line-height:1.2;color:var(--khs-fg-dim);}
        .kratos-topcommenters .ktc-stat-num{font-size:30px;font-weight:700;line-height:1.1;color:var(--khs-fg);letter-spacing:-0.01em;}

        /* 榜单列表：对齐归档页 kas-pill 视觉体系
         *   - 桌面每行 4 位
         *   - 卡片：名次徽标 + 头像 + 名字/时间信息一行两行紧凑排布
         *   - 悬停微上浮 + 强调色边框，与 .kas-pill:hover 同款克制风格 */
        .kratos-topcommenters .ktc-list{
            list-style:none;margin:0;padding:0;
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:12px;
        }
        .kratos-topcommenters .ktc-row{
            position:relative;
            display:flex;align-items:center;gap:10px;
            padding:10px 12px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:10px;
            box-shadow:var(--khs-card-shadow);
            transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease,background .2s ease;
        }
        .kratos-topcommenters .ktc-row:hover{
            transform:translateY(-1px);
            box-shadow:0 4px 10px rgba(0,0,0,.08);
            border-color:var(--khs-line-strong);
        }

        /* 名次徽标 */
        .kratos-topcommenters .ktc-rank{
            flex-shrink:0;
            display:inline-flex;align-items:center;justify-content:center;
            min-width:24px;height:22px;padding:0 6px;
            border-radius:11px;
            background:var(--khs-bg-2);
            color:var(--khs-fg-soft);
            font-weight:700;font-size:11px;
            font-variant-numeric:tabular-nums;
            letter-spacing:-.02em;
        }
        .kratos-topcommenters .ktc-rank::before{content:"#";opacity:.7;margin-right:1px;}
        .kratos-topcommenters .ktc-rank-medal{
            color:#fff;font-size:12px;
            box-shadow:0 1px 2px rgba(0,0,0,.18);
        }
        .kratos-topcommenters .ktc-rank-medal::before{opacity:.9;}

        /* 头像 */
        .kratos-topcommenters .ktc-avatar{flex-shrink:0;display:inline-block;line-height:0;}
        .kratos-topcommenters .ktc-avatar-img,
        .kratos-topcommenters .ktc-avatar img{
            width:36px !important;height:36px !important;
            border-radius:50% !important;
            border:1px solid var(--khs-line);
            box-shadow:none;
        }

        /* 用户名（第一行）+ 评论数·时间（第二行） */
        .kratos-topcommenters .ktc-body{
            flex:1;min-width:0;
            display:flex;flex-direction:column;gap:2px;
        }
        .kratos-topcommenters .ktc-body-row1{
            display:flex;align-items:center;gap:5px;min-width:0;
        }
        .kratos-topcommenters .ktc-body-row2{
            display:flex;align-items:center;gap:5px;
            min-width:0;flex-wrap:wrap;
        }
        .kratos-topcommenters .ktc-name{
            font-size:13.5px;font-weight:600;
            color:var(--khs-fg-soft) !important;
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;
        }
        .kratos-topcommenters .ktc-name-link{
            display:inline-flex;align-items:center;gap:3px;
            text-decoration:none !important;
            transition:color .2s ease;
            overflow:hidden;text-overflow:ellipsis;
        }
        .kratos-topcommenters .ktc-name-link:hover{color:var(--khs-accent) !important;}
        .kratos-topcommenters .ktc-name-arrow{flex-shrink:0;opacity:.5;transition:opacity .2s ease,transform .2s ease;}
        .kratos-topcommenters .ktc-name-link:hover .ktc-name-arrow{opacity:1;transform:translate(1px,-1px);}

        /* 友链徽章 —— 紧凑版，只保留图标 + 一个字 */
        .kratos-topcommenters .ktc-friend-badge{
            flex-shrink:0;
            display:inline-flex;align-items:center;gap:2px;
            padding:0 6px;
            font-size:10px;line-height:1.5;font-weight:500;
            border-radius:8px;
            color:#fff;
            background:linear-gradient(135deg,#38bdf8 0%,#6366f1 100%);
            box-shadow:0 1px 2px rgba(0,0,0,.15);
        }
        .kratos-topcommenters .ktc-friend-badge svg{width:9px;height:9px;}

        /* 评论数：内联小信息条，图标 + 数字 + 单位；字号 / 颜色与时间行完全一致 */
        .kratos-topcommenters .ktc-count{
            flex-shrink:0;
            display:inline-flex;align-items:center;gap:3px;
            font-size:11px;line-height:1.5;
            color:var(--khs-fg-mute);
        }
        .kratos-topcommenters .ktc-count svg{flex-shrink:0;width:11px;height:11px;}
        .kratos-topcommenters .ktc-count-num{
            font-variant-numeric:tabular-nums;
        }

        /* 内联分隔点 */
        .kratos-topcommenters .ktc-dot{
            flex-shrink:0;color:var(--khs-fg-mute);
            font-size:11px;line-height:1;
            user-select:none;
        }

        /* 最后评论时间：小字，图标色与评论数图标统一 */
        .kratos-topcommenters .ktc-lasttime{
            display:inline-flex;align-items:center;gap:3px;
            font-size:11px;color:var(--khs-fg-mute);
            min-width:0;
        }
        .kratos-topcommenters .ktc-lasttime svg{flex-shrink:0;width:11px;height:11px;}
        .kratos-topcommenters .ktc-lasttime > span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

        .kratos-topcommenters .ktc-empty{
            padding:36px 16px;text-align:center;
            color:var(--khs-fg-dim);font-size:14px;
            background:var(--khs-card-bg);
            border:1px dashed var(--khs-line-strong);
            border-radius:12px;
        }

        /* 响应式：与归档 kas-grid 保持同断点（4 → 2 → 1） */
        @media (max-width:900px){
            .kratos-topcommenters .ktc-list{grid-template-columns:repeat(2,minmax(0,1fr));}
        }
        @media (max-width:560px){
            .kratos-topcommenters .ktc-header{padding:18px 20px;gap:10px;}
            .kratos-topcommenters .ktc-title{font-size:19px;}
            .kratos-topcommenters .ktc-header-divider{display:none;}
            .kratos-topcommenters .ktc-subtitle{flex-basis:100%;font-size:13px;}
            .kratos-topcommenters .ktc-stats{grid-template-columns:1fr;gap:12px;}
            .kratos-topcommenters .ktc-stat{padding:16px 18px;}
            .kratos-topcommenters .ktc-stat-num{font-size:24px;}
            .kratos-topcommenters .ktc-list{grid-template-columns:1fr;}
        }

        /* 暗夜模式：与走心评论/归档保持同一中性灰白调 */
        html[data-theme="dark"] .kratos-topcommenters,body.dark .kratos-topcommenters{
            --khs-fg:#d6d8db;--khs-fg-soft:#b8bbc0;--khs-fg-dim:#8b919a;--khs-fg-mute:#6f747e;
            --khs-accent:#6ea8ff;--khs-accent-2:#91bdff;
            --khs-line:rgba(255,255,255,.08);--khs-line-strong:rgba(255,255,255,.16);
            --khs-card-bg:#1c1f24;
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('top_commenters', 'kratos_top_commenters_shortcode');

/* ============================================================
 *  友链徽章（复用 theme-comment-link.php 的配置项）
 * ============================================================ */

function kratos_top_commenters_friend_badge_enabled()
{
    return function_exists('kratos_blogroll_enabled') && kratos_blogroll_enabled();
}

/**
 * 榜单里的友链徽章：读的是同一套「友链标识」配置，保证前后视觉一致。
 */
function kratos_top_commenters_friend_badge_html()
{
    $text = (string) kratos_option('g_comment_blogroll_badge_text', __('友链', 'kratos'));
    if (trim($text) === '') $text = __('友链', 'kratos');
    $color    = sanitize_hex_color((string) kratos_option('g_comment_blogroll_badge_color', '#ffffff')) ?: '#ffffff';
    $bg_start = sanitize_hex_color((string) kratos_option('g_comment_blogroll_badge_bg_start', '#38bdf8')) ?: '#38bdf8';
    $bg_end   = sanitize_hex_color((string) kratos_option('g_comment_blogroll_badge_bg_end', '#6366f1')) ?: '#6366f1';

    $style = sprintf(
        'color:%s;background:linear-gradient(135deg,%s 0%%,%s 100%%);',
        esc_attr($color),
        esc_attr($bg_start),
        esc_attr($bg_end)
    );
    $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr($color) . '" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 1 0-7.07-7.07l-1 1"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 1 0 7.07 7.07l1-1"/></svg>';
    return '<span class="ktc-friend-badge" title="' . esc_attr__('友链博主', 'kratos') . '" style="' . $style . '">' . $icon . esc_html($text) . '</span>';
}

/**
 * 应用了 page-top-commenters.php 模板的页面注入 body class，
 * 让皮肤层可以精准豁免对外层 .details 的装饰。
 */
function kratos_top_commenters_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-top-commenters.php')) {
        $classes[] = 'is-kratos-topcommenters-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_top_commenters_body_class');
