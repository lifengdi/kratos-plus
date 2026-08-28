<?php
/**
 * 友链页面 + 友链申请
 *
 * 复用 WordPress 原生 wp_links 表和「链接」顶级菜单，不新建自定义表 / CPT：
 *   - 展示：按 link_category term 分组展示已通过（link_visible='Y'）的友链
 *   - 申请：前台表单 → wp_insert_link(link_visible='N') → 站长在
 *     wp-admin/link-manager.php 里通过 row action「通过 / 拒绝」审批
 *   - 全站已有的 [评论友链标识] 会自动跟着走：通过后 host 进入 blogroll 缓存
 *
 * 提供：
 *   - 短码 [friend_links]（配 page-friend-links.php 模板使用）
 *   - 表单 POST /wp-admin/admin-post.php?action=kratos_friend_apply
 *   - link-manager 列表：追加「状态」列 + row action 快速通过/拒绝 + 顶部
 *     待审核数量提示
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

const KRATOS_FRIEND_HOSTS_LRU_KEY   = 'kratos_friend_apply_ratelimit_';
const KRATOS_FRIEND_APPLY_COOLDOWN  = 60;    // seconds
const KRATOS_FRIEND_LOGO_ALLOWED    = array('image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml');
const KRATOS_FRIEND_LOGO_MAX_BYTES  = 512 * 1024; // 512 KB

/* ============================================================
 *  数据查询
 * ============================================================ */

/**
 * 按 link_category 分组拉取「已通过」的友链
 *
 * @param array $args
 *   - hide_empty bool 是否隐藏空分类，默认 false
 *   - orderby    string 组内 link 排序字段，默认 name
 *   - order      string ASC/DESC
 * @return array<int, array{term: WP_Term, links: array<int, object>}>
 */
function kratos_friend_get_groups($args = array())
{
    $args = wp_parse_args($args, array(
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));

    if (!function_exists('get_bookmarks')) {
        return array();
    }

    $terms = get_terms(array(
        'taxonomy'   => 'link_category',
        'hide_empty' => (bool) $args['hide_empty'],
        'orderby'    => 'name',
    ));
    if (is_wp_error($terms) || empty($terms)) {
        return array();
    }

    $groups = array();
    foreach ($terms as $term) {
        $links = get_bookmarks(array(
            'category'       => $term->term_id,
            'hide_invisible' => 1,
            'orderby'        => $args['orderby'],
            'order'          => $args['order'],
            'limit'          => -1,
        ));
        if (empty($links) && !$args['hide_empty']) {
            $groups[] = array('term' => $term, 'links' => array());
            continue;
        }
        if (!empty($links)) {
            $groups[] = array('term' => $term, 'links' => $links);
        }
    }
    return $groups;
}

/**
 * 从 URL 或字符串生成首字母占位符（去 http / www，取第一个字母数字字符）
 */
function kratos_friend_first_letter($str)
{
    if (!is_string($str)) return '#';
    $str = preg_replace('#^https?://#i', '', $str);
    $str = preg_replace('#^www\.#i', '', $str);
    if (!preg_match('/[a-zA-Z0-9\p{L}]/u', $str, $m)) {
        return '#';
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($m[0], 'UTF-8') : strtoupper($m[0]);
}

/**
 * 根据链接名 / URL 稳定生成一个占位色（HSL 渐变，饱和度和亮度固定，色相由字符串 hash 决定）
 * 让每个站点固定拿到自己的颜色。用 45° 深浅两色的线性渐变，提高白色首字母的对比度，
 * 同时避免大色块太"平"，视觉更接近品牌 logo 底色。
 */
function kratos_friend_placeholder_color($seed)
{
    $hash = crc32((string) $seed);
    $hue  = $hash % 360;
    // 主色深、副色更深，白字对比度足够；黄/青色系也不会被冲淡
    $c1 = sprintf('hsl(%d, 68%%, 44%%)', $hue);
    $c2 = sprintf('hsl(%d, 72%%, 34%%)', ($hue + 20) % 360);
    return sprintf('linear-gradient(135deg,%s 0%%,%s 100%%)', $c1, $c2);
}

/**
 * 待审核数量（供后台角标 / 通知使用）
 */
function kratos_friend_pending_count()
{
    global $wpdb;
    $sql = "SELECT COUNT(*) FROM {$wpdb->links} WHERE link_visible = 'N'";
    return (int) $wpdb->get_var($sql);
}

/**
 * 拉取最近有评论的访客（按用户去重，每人只取最新一条）
 *
 * 归并规则：
 *   - 已登录用户按 user_id 归并
 *   - 游客按 comment_author_email 归并
 *   - 匿名（无 user_id 且无邮箱）跳过
 *
 * @param int $limit 最多返回条数
 * @return array<int, array{
 *   key: string,
 *   user_id: int,
 *   name: string,
 *   url: string,
 *   avatar_html: string,
 *   comment_id: int,
 *   excerpt: string,
 *   time: int,
 *   post_id: int,
 *   post_title: string,
 *   post_url: string,
 *   comment_link: string
 * }>
 */
function kratos_friend_recent_visitors($limit = 20)
{
    $limit = max(1, (int) $limit);

    $cache_key = 'kratos_friend_recent_' . $limit;
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;
    // 取最近 200 条已审核评论作为候选池，够 20~50 个去重后的用户用了。
    // 直接靠 SQL 做 GROUP BY 归并不了「按最新时间去重」逻辑，PHP 端二次处理更简单。
    $rows = $wpdb->get_results(
        "SELECT comment_ID, user_id, comment_author, comment_author_email,
                comment_author_url, comment_content, comment_date_gmt, comment_post_ID
         FROM {$wpdb->comments}
         WHERE comment_approved = '1'
           AND (comment_type = '' OR comment_type = 'comment')
         ORDER BY comment_date DESC
         LIMIT 200"
    );

    // 候选池 200 条，循环里要按 user_id 查角色、按 comment_post_ID 取标题/链接。
    // 逐条查就是最多 400 次单行查询，这里先各用一条 IN 查询把用户与文章
    // 灌进对象缓存（文章的 term / meta 都不读，关掉预热）。
    $prime_uids = array();
    $prime_pids = array();
    foreach ((array) $rows as $r) {
        $uid = (int) $r->user_id;
        if ($uid > 0) $prime_uids[$uid] = true;
        $pid = (int) $r->comment_post_ID;
        if ($pid > 0) $prime_pids[$pid] = true;
    }
    if ($prime_uids) cache_users(array_keys($prime_uids));
    if ($prime_pids) _prime_post_caches(array_keys($prime_pids), false, false);

    $seen = array();
    $items = array();
    foreach ((array) $rows as $r) {
        $uid   = (int) $r->user_id;
        $email = (string) $r->comment_author_email;
        $u     = null;
        if ($uid > 0) {
            $u = get_userdata($uid);
            if ($u && in_array('administrator', (array) $u->roles, true)) continue;
            $key = 'u_' . $uid;
        } elseif ($email !== '') {
            $key = 'e_' . md5(strtolower($email));
        } else {
            continue; // 匿名跳过
        }
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $post_id    = (int) $r->comment_post_ID;
        $post_title = get_the_title($post_id);
        if ($post_title === '') $post_title = __('（无标题）', 'kratos');
        $post_url = get_permalink($post_id);

        // $u 在上面按 uid 取过一次，别再连查三次 get_userdata()
        if ($r->comment_author !== '') {
            $author = $r->comment_author;
        } elseif ($uid > 0 && !empty($u)) {
            $author = $u->display_name;
        } else {
            $author = $uid > 0 ? __('用户', 'kratos') : __('匿名', 'kratos');
        }

        $avatar_seed = $email !== '' ? $email : $uid;
        $avatar = get_avatar($avatar_seed, 44, '', $author, array('class' => 'kfl-visitor-avatar-img'));

        $excerpt = wp_strip_all_tags((string) $r->comment_content);
        // 截断到 120 字以内，超出加省略号
        if (function_exists('mb_strimwidth')) {
            $excerpt = mb_strimwidth($excerpt, 0, 160, '…', 'UTF-8');
        } elseif (mb_strlen($excerpt) > 80) {
            $excerpt = mb_substr($excerpt, 0, 80) . '…';
        }

        $items[] = array(
            'key'          => $key,
            'user_id'      => $uid,
            'name'         => $author,
            'url'          => (string) $r->comment_author_url,
            'avatar_html'  => $avatar,
            'comment_id'   => (int) $r->comment_ID,
            'excerpt'      => $excerpt,
            'time'         => (int) strtotime((string) $r->comment_date_gmt . ' GMT'),
            'post_id'      => $post_id,
            'post_title'   => $post_title,
            'post_url'     => $post_url,
            'comment_link' => get_comment_link((int) $r->comment_ID),
        );

        if (count($items) >= $limit) break;
    }

    set_transient($cache_key, $items, 10 * MINUTE_IN_SECONDS);
    return $items;
}

/**
 * 评论审核状态变化时清除最近访客缓存。
 */
function kratos_friend_recent_flush_cache()
{
    global $wpdb;
    $like = $wpdb->esc_like('_transient_kratos_friend_recent_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    $like2 = $wpdb->esc_like('_transient_timeout_kratos_friend_recent_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like2));
}
add_action('wp_insert_comment',     'kratos_friend_recent_flush_cache');
add_action('wp_set_comment_status', 'kratos_friend_recent_flush_cache');
add_action('edit_comment',          'kratos_friend_recent_flush_cache');
add_action('deleted_comment',       'kratos_friend_recent_flush_cache');
add_action('trashed_comment',       'kratos_friend_recent_flush_cache');
add_action('untrashed_comment',     'kratos_friend_recent_flush_cache');

/* ============================================================
 *  短码 [friend_links]
 * ============================================================ */

function kratos_friend_shortcode($atts)
{
    $default_title    = (string) kratos_option('g_friend_sc_title', __('友情链接', 'kratos'));
    $default_subtitle = (string) kratos_option('g_friend_sc_subtitle', __('感谢各位朋友的关注与支持，欢迎申请交换友链 🤝', 'kratos'));
    $default_hide_empty = (bool) kratos_option('g_friend_hide_empty', true);
    $default_form_enabled = (bool) kratos_option('g_friend_form_enabled', true);

    $atts = shortcode_atts(array(
        'title'      => $default_title,
        'subtitle'   => $default_subtitle,
        'hide_empty' => $default_hide_empty ? '1' : '0',
        'form'       => $default_form_enabled ? '1' : '0',
    ), $atts, 'friend_links');

    $groups = kratos_friend_get_groups(array(
        'hide_empty' => $atts['hide_empty'] === '1',
    ));

    $probe_enabled = (bool) kratos_option('g_friend_probe_enabled', false);
    $probe_data    = $probe_enabled ? kratos_friend_probe_get_all() : array();

    $title    = (string) $atts['title'];
    $subtitle = (string) $atts['subtitle'];

    // 表单提交后回显（session-less）：admin-post 处理完通过 URL query 带回状态
    $submit_status = isset($_GET['kfl_status']) ? sanitize_key(wp_unslash($_GET['kfl_status'])) : '';
    $submit_msg    = '';
    if ($submit_status === 'ok') {
        $submit_msg = __('申请已提交，等待站长审核通过后即可展示 🎉', 'kratos');
    } elseif ($submit_status === 'err') {
        $key = isset($_GET['kfl_reason']) ? sanitize_key(wp_unslash($_GET['kfl_reason'])) : '';
        $submit_msg = kratos_friend_reason_msg($key);
    }

    $total_links = 0;
    foreach ($groups as $g) $total_links += count($g['links']);

    // 表单校验码（防跨站）
    $nonce   = wp_create_nonce('kratos_friend_apply');
    $post_url = admin_url('admin-post.php');

    ob_start();
    ?>
    <div class="kratos-friend-links" id="kratos-friend-links">
        <?php if ($title !== '' || $subtitle !== '') { ?>
            <header class="kfl-header kr-hd">
                <?php if ($title !== '') { ?>
                    <span class="kfl-title-icon kr-ico" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 1 0-7.07-7.07l-1 1"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 1 0 7.07 7.07l1-1"/></svg>
                    </span>
                    <span class="kfl-title kr-hd-title"><?php echo esc_html($title); ?></span>
                <?php } ?>
                <?php if ($subtitle !== '') { ?>
                    <?php if ($title !== '') { ?><span class="kfl-header-divider kr-hd-divider" aria-hidden="true"></span><?php } ?>
                    <p class="kfl-subtitle kr-hd-sub"><?php echo esc_html($subtitle); ?></p>
                <?php } ?>
            </header>
        <?php } ?>

        <?php
        // 本站信息卡片
        if (kratos_option('g_friend_siteinfo_enabled', false)) {
            $si_name = (string) kratos_option('g_friend_siteinfo_name', '');
            $si_url  = (string) kratos_option('g_friend_siteinfo_url', '');
            $si_logo = (string) kratos_option('g_friend_siteinfo_logo', '');
            $si_desc = (string) kratos_option('g_friend_siteinfo_desc', '');
            $si_rss  = (string) kratos_option('g_friend_siteinfo_rss', '');
            if ($si_name === '') $si_name = get_bloginfo('name');
            if ($si_url === '')  $si_url  = home_url('/');
            if ($si_desc === '') $si_desc = get_bloginfo('description');
            if ($si_rss === '')  $si_rss  = get_bloginfo('rss2_url');
        ?>
            <section class="kfl-section kr-body kfl-siteinfo-section">
                <header class="kfl-section-head">
                    <h3 class="kfl-section-title"><?php esc_html_e('本站信息', 'kratos'); ?></h3>
                </header>
                <div class="kfl-siteinfo-card">
                    <?php if ($si_logo !== '') { ?>
                        <img class="kfl-siteinfo-logo" src="<?php echo esc_url($si_logo); ?>" alt="<?php echo esc_attr($si_name); ?>" loading="lazy" />
                    <?php } ?>
                    <?php
                    $copy_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
                    $copy_label   = esc_attr__('复制', 'kratos');
                    $copied_label = esc_attr__('已复制', 'kratos');
                    $render_copy_btn = function() use ($copy_svg, $copy_label, $copied_label) {
                        return '<button type="button" class="kfl-copy-btn kr-btn" title="' . $copy_label . '" aria-label="' . $copy_label . '" data-copied-label="' . $copied_label . '">' . $copy_svg . '</button>';
                    };
                    ?>
                    <div class="kfl-siteinfo-fields">
                        <div class="kfl-siteinfo-row">
                            <span class="kfl-siteinfo-label"><?php esc_html_e('名称', 'kratos'); ?></span>
                            <span class="kfl-siteinfo-value" data-copy="<?php echo esc_attr($si_name); ?>"><span class="kfl-siteinfo-text"><?php echo esc_html($si_name); ?></span><?php echo $render_copy_btn(); ?></span>
                        </div>
                        <div class="kfl-siteinfo-row">
                            <span class="kfl-siteinfo-label"><?php esc_html_e('地址', 'kratos'); ?></span>
                            <span class="kfl-siteinfo-value" data-copy="<?php echo esc_attr($si_url); ?>"><a href="<?php echo esc_url($si_url); ?>"><?php echo esc_html($si_url); ?></a><?php echo $render_copy_btn(); ?></span>
                        </div>
                        <?php if ($si_logo !== '') { ?>
                        <div class="kfl-siteinfo-row is-wide">
                            <span class="kfl-siteinfo-label">Logo</span>
                            <span class="kfl-siteinfo-value" data-copy="<?php echo esc_attr($si_logo); ?>"><a href="<?php echo esc_url($si_logo); ?>" target="_blank"><?php echo esc_html($si_logo); ?></a><?php echo $render_copy_btn(); ?></span>
                        </div>
                        <?php } ?>
                        <div class="kfl-siteinfo-row is-wide">
                            <span class="kfl-siteinfo-label"><?php esc_html_e('描述', 'kratos'); ?></span>
                            <span class="kfl-siteinfo-value" data-copy="<?php echo esc_attr($si_desc); ?>"><span class="kfl-siteinfo-text"><?php echo esc_html($si_desc); ?></span><?php echo $render_copy_btn(); ?></span>
                        </div>
                        <div class="kfl-siteinfo-row is-wide">
                            <span class="kfl-siteinfo-label">RSS</span>
                            <span class="kfl-siteinfo-value" data-copy="<?php echo esc_attr($si_rss); ?>"><a href="<?php echo esc_url($si_rss); ?>" target="_blank"><?php echo esc_html($si_rss); ?></a><?php echo $render_copy_btn(); ?></span>
                        </div>
                    </div>
                </div>
            </section>
        <?php } ?>

        <?php if (empty($groups) || $total_links === 0) { ?>
            <div class="kfl-empty">
                <?php esc_html_e('暂时还没有友链，欢迎来做第一个 ✨', 'kratos'); ?>
            </div>
        <?php } else { ?>
            <?php foreach ($groups as $group) {
                $term  = $group['term'];
                $links = $group['links'];
                if (empty($links)) continue;
                ?>
                <section class="kfl-section kr-body">
                    <header class="kfl-section-head">
                        <h3 class="kfl-section-title"><?php echo esc_html($term->name); ?></h3>
                        <span class="kfl-section-count kr-pill"><?php echo (int) count($links); ?></span>
                        <?php if ($term->description !== '') { ?>
                            <p class="kfl-section-desc"><?php echo esc_html($term->description); ?></p>
                        <?php } ?>
                    </header>
                    <div class="kfl-grid">
                        <?php foreach ($links as $link) {
                            $name = $link->link_name !== '' ? $link->link_name : __('（未命名）', 'kratos');
                            $url  = $link->link_url;
                            $desc = $link->link_description;
                            $img  = $link->link_image;
                            $target = $link->link_target ? $link->link_target : '_blank';
                            $letter = kratos_friend_first_letter($name !== '' ? $name : $url);
                            $bg     = kratos_friend_placeholder_color($name !== '' ? $name : $url);
                            ?>
                            <a class="kfl-item kr-card" href="<?php echo esc_url($url); ?>" target="<?php echo esc_attr($target); ?>" rel="nofollow noopener external" title="<?php echo esc_attr($name . ($desc !== '' ? ' — ' . $desc : '')); ?>">
                                <?php if ($probe_enabled && isset($probe_data[(int) $link->link_id])) {
                                    $p = $probe_data[(int) $link->link_id];
                                    $p_cls = $p['status'] === 'reachable' ? 'is-up' : 'is-down';
                                    $p_tip = $p['status'] === 'reachable' ? __('站点可达', 'kratos') : __('站点不可达', 'kratos');
                                    if (!empty($p['checked_at'])) {
                                        $p_tip .= ' · ' . human_time_diff((int) $p['checked_at'], time()) . __('前检测', 'kratos');
                                    }
                                ?>
                                    <span class="kfl-probe-dot kr-dot <?php echo esc_attr($p_cls); ?>" title="<?php echo esc_attr($p_tip); ?>"></span>
                                <?php } ?>
                                <span class="kfl-logo">
                                    <?php if ($img !== '') { ?>
                                        <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($name); ?>" loading="lazy" onerror="this.parentNode.classList.add('is-fallback');this.remove();" />
                                    <?php } else { ?>
                                        <span class="kfl-logo-letter" style="background:<?php echo esc_attr($bg); ?>;"><?php echo esc_html($letter); ?></span>
                                    <?php } ?>
                                    <?php if ($img !== '') { ?>
                                        <span class="kfl-logo-letter kfl-logo-fallback" style="background:<?php echo esc_attr($bg); ?>;"><?php echo esc_html($letter); ?></span>
                                    <?php } ?>
                                </span>
                                <span class="kfl-meta">
                                    <span class="kfl-name"><?php echo esc_html($name); ?></span>
                                    <?php if ($desc !== '') { ?>
                                        <span class="kfl-desc"><?php echo esc_html($desc); ?></span>
                                    <?php } ?>
                                </span>
                            </a>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>
        <?php } ?>

        <?php if (kratos_option('g_friend_recent_enabled', true)) {
            $recent_limit = (int) kratos_option('g_friend_recent_limit', 20);
            if ($recent_limit <= 0) $recent_limit = 20;
            $recent_title = (string) kratos_option('g_friend_recent_title', __('最近访客', 'kratos'));
            $visitors = kratos_friend_recent_visitors($recent_limit);
            if (!empty($visitors)) {
                $date_fmt = get_option('date_format') . ' ' . get_option('time_format');
        ?>
            <section class="kfl-section kr-body kfl-visitors-section">
                <?php if ($recent_title !== '') { ?>
                    <header class="kfl-section-head">
                        <h3 class="kfl-section-title"><?php echo esc_html($recent_title); ?></h3>
                        <span class="kfl-section-count kr-pill"><?php echo (int) count($visitors); ?></span>
                    </header>
                <?php } ?>
                <ul class="kfl-visitors">
                    <?php foreach ($visitors as $v) {
                        $has_url = $v['url'] !== '' && preg_match('#^https?://#i', $v['url']);
                        $time_full = $v['time'] > 0 ? wp_date($date_fmt, $v['time']) : '';
                        $time_rel  = $v['time'] > 0 ? human_time_diff($v['time'], time()) . __('前', 'kratos') : '';
                        // 用户名可点击外链，头像整体点击到评论锚点
                        $tag = $has_url ? 'a' : 'span';
                    ?>
                        <li class="kfl-visitor" tabindex="0">
                            <a class="kfl-visitor-link kr-pill" href="<?php echo esc_url($v['comment_link']); ?>" title="<?php echo esc_attr(sprintf(__('查看 %s 的评论', 'kratos'), $v['name'])); ?>">
                                <span class="kfl-visitor-avatar"><?php echo $v['avatar_html']; ?></span>
                                <span class="kfl-visitor-name"><?php echo esc_html($v['name']); ?></span>
                            </a>
                            <div class="kfl-visitor-tip" role="tooltip">
                                <div class="kfl-visitor-tip-head">
                                    <?php if ($has_url) { ?>
                                        <a class="kfl-visitor-tip-name" href="<?php echo esc_url($v['url']); ?>" target="_blank" rel="nofollow noopener external"><?php echo esc_html($v['name']); ?></a>
                                    <?php } else { ?>
                                        <span class="kfl-visitor-tip-name"><?php echo esc_html($v['name']); ?></span>
                                    <?php } ?>
                                    <?php if ($time_rel !== '') { ?>
                                        <span class="kfl-visitor-tip-time" title="<?php echo esc_attr($time_full); ?>"><?php echo esc_html($time_rel); ?></span>
                                    <?php } ?>
                                </div>
                                <div class="kfl-visitor-tip-body"><?php echo esc_html($v['excerpt']); ?></div>
                                <a class="kfl-visitor-tip-post" href="<?php echo esc_url($v['comment_link']); ?>" title="<?php echo esc_attr($v['post_title']); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <span><?php echo esc_html($v['post_title']); ?></span>
                                </a>
                            </div>
                        </li>
                    <?php } ?>
                </ul>
            </section>
        <?php } } ?>

        <?php
        // 友链申请要求（放在申请表单之前）
        if (kratos_option('g_friend_requirements_enabled', false)) {
            $req_title   = (string) kratos_option('g_friend_requirements_title', __('友链申请要求', 'kratos'));
            $req_content = (string) kratos_option('g_friend_requirements_content', '');
            if ($req_content !== '') {
        ?>
            <section class="kfl-section kr-body kfl-requirements-section">
                <?php if ($req_title !== '') { ?>
                    <header class="kfl-section-head">
                        <h3 class="kfl-section-title"><?php echo esc_html($req_title); ?></h3>
                    </header>
                <?php } ?>
                <div class="kfl-requirements-body"><?php echo do_shortcode(wp_kses_post($req_content)); ?></div>
            </section>
        <?php } } ?>

        <?php if ($atts['form'] === '1') {
            $form_intro = (string) kratos_option('g_friend_form_intro', __('填写下方表单提交友链申请，站长审核通过后会自动上线。', 'kratos'));
        ?>
            <section class="kfl-section kr-body kfl-form-section" id="kratos-friend-apply">
                <header class="kfl-section-head">
                    <h3 class="kfl-section-title"><?php esc_html_e('申请友链', 'kratos'); ?></h3>
                    <?php if ($form_intro !== '') { ?>
                        <p class="kfl-section-desc"><?php echo esc_html($form_intro); ?></p>
                    <?php } ?>
                </header>
                <?php if ($submit_msg !== '') { ?>
                    <div class="kfl-alert kfl-alert-<?php echo esc_attr($submit_status === 'ok' ? 'ok' : 'err'); ?>" role="status" aria-live="polite">
                        <span class="kfl-alert-icon" aria-hidden="true">
                            <?php if ($submit_status === 'ok') { ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <?php } else { ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?php } ?>
                        </span>
                        <span class="kfl-alert-text"><?php echo esc_html($submit_msg); ?></span>
                    </div>
                <?php } ?>
                <form class="kfl-form" method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="kratos_friend_apply" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
                    <input type="hidden" name="_kfl_redirect" value="<?php echo esc_url((is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" />
                    <!-- honeypot：机器人爱填 -->
                    <div class="kfl-hp" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
                        <label>Website<input type="text" name="kfl_hp_website" tabindex="-1" autocomplete="off" /></label>
                    </div>

                    <div class="kfl-form-row">
                        <div class="kfl-field">
                            <label class="kfl-label" for="kfl-name"><?php esc_html_e('网站名称', 'kratos'); ?> <span class="kfl-required">*</span></label>
                            <input class="kfl-input" type="text" id="kfl-name" name="link_name" required maxlength="120" placeholder="<?php esc_attr_e('网站名称', 'kratos'); ?>" />
                        </div>
                        <div class="kfl-field">
                            <label class="kfl-label" for="kfl-url"><?php esc_html_e('网站地址', 'kratos'); ?> <span class="kfl-required">*</span></label>
                            <input class="kfl-input" type="url" id="kfl-url" name="link_url" required maxlength="200" placeholder="https://example.com" />
                        </div>
                    </div>

                    <div class="kfl-form-row">
                        <div class="kfl-field">
                            <label class="kfl-label" for="kfl-image"><?php esc_html_e('Logo 地址', 'kratos'); ?></label>
                            <input class="kfl-input" type="url" id="kfl-image" name="link_image" maxlength="300" placeholder="https://example.com/logo.png" />
                            <span class="kfl-help"><?php esc_html_e('留空将展示首字母占位符', 'kratos'); ?></span>
                        </div>
                        <div class="kfl-field">
                            <label class="kfl-label" for="kfl-rss"><?php esc_html_e('RSS 订阅地址', 'kratos'); ?></label>
                            <input class="kfl-input" type="url" id="kfl-rss" name="link_rss" maxlength="300" placeholder="https://example.com/feed" />
                        </div>
                    </div>

                    <div class="kfl-form-row">
                        <div class="kfl-field kfl-field-full">
                            <label class="kfl-label" for="kfl-desc"><?php esc_html_e('网站描述', 'kratos'); ?></label>
                            <input class="kfl-input" type="text" id="kfl-desc" name="link_description" maxlength="200" placeholder="<?php esc_attr_e('一句话简介（可选）', 'kratos'); ?>" />
                        </div>
                    </div>

                    <?php if (function_exists('kratos_captcha_enabled') && kratos_captcha_enabled()) {
                        list($cap_token, $cap_x, $cap_y, $cap_op) = kratos_captcha_new_question();
                    ?>
                        <div class="kfl-form-row">
                            <div class="kfl-field kfl-field-full">
                                <label class="kfl-label" for="kfl-captcha"><?php esc_html_e('验证码', 'kratos'); ?> <span class="kfl-required">*</span></label>
                                <div class="kfl-captcha-row">
                                    <span class="kfl-captcha-q" aria-hidden="true"><?php echo esc_html($cap_x . ' ' . $cap_op . ' ' . $cap_y . ' ='); ?></span>
                                    <input class="kfl-input kfl-captcha-input" type="text" id="kfl-captcha" name="kratos_captcha" inputmode="numeric" pattern="-?\d+" required maxlength="4" autocomplete="off" placeholder="<?php esc_attr_e('答案', 'kratos'); ?>" />
                                    <button type="button" class="kfl-captcha-refresh" aria-label="<?php esc_attr_e('换一题', 'kratos'); ?>" title="<?php esc_attr_e('换一题', 'kratos'); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                    </button>
                                    <input type="hidden" name="kratos_captcha_token" value="<?php echo esc_attr($cap_token); ?>" />
                                </div>
                            </div>
                        </div>
                    <?php } ?>

                    <div class="kfl-form-actions">
                        <button type="submit" class="kfl-submit kr-btn"><?php esc_html_e('提交申请', 'kratos'); ?></button>
                    </div>
                </form>
            </section>
        <?php } ?>
    </div>

    <style>
        /* === 友链页面：复用走心 / 归档 shortcode 的 --khs-* 变量体系，
         * 让皮肤层 weekday-skins.css §18 一次性覆盖此页视觉 === */
        .kratos-friend-links{
            --khs-bg-1:#f5f5f5;--khs-bg-2:#f0f0f0;--khs-bg-3:#ebebeb;
            --khs-fg:#333;--khs-fg-soft:#444;--khs-fg-dim:#777;--khs-fg-mute:#999;
            --khs-accent:#336699;--khs-accent-2:#2B5278;
            --khs-line:rgba(0,0,0,.08);--khs-line-strong:rgba(0,0,0,.16);
            --khs-card-bg:#ffffff;
            --khs-card-shadow:0 1px 3px rgba(0,0,0,.06);
            --khs-card-shadow-hv:0 8px 18px rgba(0,0,0,.10);
            padding:0;position:relative;background:transparent;
            max-width:100%;
        }
        .kratos-friend-links > *{position:relative;z-index:1;}

        /* 页头：与走心 / 归档保持同一视觉 */
        .kratos-friend-links .kfl-header{
            display:flex;align-items:center;flex-wrap:wrap;gap:14px;
            padding:24px 28px;margin-bottom:18px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:14px;
            box-shadow:var(--khs-card-shadow);
        }
        .kratos-friend-links .kfl-title-icon{
            display:inline-flex;align-items:center;justify-content:center;
            width:38px;height:38px;border-radius:10px;
            background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
            color:var(--khs-accent);
        }
        .kratos-friend-links .kfl-title{
            margin:0;padding:0;font-size:22px;font-weight:700;line-height:1.3;
            color:var(--khs-fg);
        }
        .kratos-friend-links .kfl-header-divider{
            display:inline-block;width:1px;height:22px;background:var(--khs-line-strong);
        }
        .kratos-friend-links .kfl-subtitle{
            margin:0;padding:0;font-size:14px;line-height:1.5;color:var(--khs-fg-soft);
        }

        /* 结果提示（表单标题下方）：图标 + 文案，成功用绿色 accent、失败用红色 accent，
         * 淡入 + 轻微上滑，提交后视觉能第一时间抓住 */
        .kratos-friend-links .kfl-alert{
            display:flex;align-items:flex-start;gap:10px;
            padding:14px 16px;margin:0 0 16px;
            border-radius:10px;font-size:14px;line-height:1.55;
            border:1px solid var(--khs-line);
            background:var(--khs-card-bg);
            color:var(--khs-fg-soft);
            animation:kflAlertIn .35s cubic-bezier(.2,.7,.2,1);
        }
        .kratos-friend-links .kfl-alert-icon{
            flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;
            width:22px;height:22px;margin-top:1px;
        }
        .kratos-friend-links .kfl-alert-text{flex:1;min-width:0;font-weight:500;}
        .kratos-friend-links .kfl-alert-ok{
            border-color:rgba(46,160,67,.45);
            color:#1a7f37;
            background:rgba(46,160,67,.10);
            box-shadow:0 2px 8px rgba(46,160,67,.10);
        }
        .kratos-friend-links .kfl-alert-err{
            border-color:rgba(207,34,46,.45);
            color:#a4111e;
            background:rgba(207,34,46,.09);
            box-shadow:0 2px 8px rgba(207,34,46,.10);
        }
        @keyframes kflAlertIn{
            from{opacity:0;transform:translateY(-6px);}
            to{opacity:1;transform:translateY(0);}
        }

        /* 分组 */
        .kratos-friend-links .kfl-section{
            padding:22px 24px;margin-bottom:16px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:14px;
            box-shadow:var(--khs-card-shadow);
        }
        .kratos-friend-links .kfl-section-head{
            display:flex;align-items:center;gap:10px;flex-wrap:wrap;
            margin:0 0 18px;
        }
        .kratos-friend-links .kfl-section-title{
            margin:0;padding:0;font-size:16px;font-weight:700;color:var(--khs-fg);
        }
        .kratos-friend-links .kfl-section-count{
            display:inline-flex;align-items:center;justify-content:center;
            min-width:22px;height:22px;padding:0 8px;
            background:var(--khs-bg-2);color:var(--khs-fg-dim);
            border-radius:999px;font-size:11.5px;font-weight:600;
            font-variant-numeric:tabular-nums;
        }
        .kratos-friend-links .kfl-section-desc{
            flex-basis:100%;margin:2px 0 0;padding:0;
            font-size:13px;color:var(--khs-fg-dim);line-height:1.5;
        }

        /* 友链卡片网格：4 → 3 → 2 → 1 */
        .kratos-friend-links .kfl-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:12px;
            min-width:0;
        }
        .kratos-friend-links .kfl-item{
            position:relative;
            display:flex;align-items:center;gap:12px;
            padding:12px;min-width:0;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:10px;
            color:var(--khs-fg-soft) !important;
            text-decoration:none !important;
            overflow:hidden;
            transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease,background .2s ease;
        }
        .kratos-friend-links .kfl-item:hover{
            transform:translateY(-2px);
            box-shadow:var(--khs-card-shadow-hv);
            border-color:var(--khs-line-strong);
            color:var(--khs-accent) !important;
        }

        /* Logo 或首字母占位 */
        .kratos-friend-links .kfl-logo{
            flex-shrink:0;
            position:relative;
            width:42px;height:42px;
            display:inline-block;
        }
        .kratos-friend-links .kfl-logo img{
            width:42px !important;height:42px !important;
            border-radius:10px !important;
            object-fit:cover;
            border:1px solid var(--khs-line);
            display:block;
            background:var(--khs-bg-2);
        }
        .kratos-friend-links .kfl-logo-letter{
            width:42px;height:42px;
            border-radius:10px;
            display:inline-flex;align-items:center;justify-content:center;
            color:#fff;font-weight:800;font-size:19px;line-height:1;
            text-transform:uppercase;letter-spacing:0;
            text-shadow:0 1px 2px rgba(0,0,0,.20);
            box-shadow:0 1px 2px rgba(0,0,0,.10);
        }
        /* 有 img 时占位藏起来；img onerror 加 .is-fallback → 图消失、字母顶上 */
        .kratos-friend-links .kfl-logo .kfl-logo-fallback{position:absolute;inset:0;display:none;}
        .kratos-friend-links .kfl-logo.is-fallback .kfl-logo-fallback{display:inline-flex;}

        .kratos-friend-links .kfl-meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;overflow:hidden;}
        .kratos-friend-links .kfl-name{
            font-size:14px;font-weight:600;color:inherit;
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        }
        .kratos-friend-links .kfl-desc{
            font-size:12px;color:var(--khs-fg-mute);
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        }
        .kratos-friend-links .kfl-item:hover .kfl-desc{color:var(--khs-fg-dim);}

        /* 探活状态点 */
        .kratos-friend-links .kfl-probe-dot{
            position:absolute;top:6px;right:6px;z-index:2;
            width:8px;height:8px;border-radius:50%;
        }
        .kratos-friend-links .kfl-probe-dot.is-up{
            background:#34a853;
            box-shadow:0 0 4px rgba(52,168,83,.5);
        }
        .kratos-friend-links .kfl-probe-dot.is-down{
            background:#ea4335;
            box-shadow:0 0 4px rgba(234,67,53,.5);
        }

        /* 空状态 */
        .kratos-friend-links .kfl-empty{
            padding:36px 16px;text-align:center;
            color:var(--khs-fg-dim);font-size:14px;
            background:var(--khs-card-bg);
            border:1px dashed var(--khs-line-strong);
            border-radius:12px;margin-bottom:16px;
        }

        /* === 最近访客 ===
         * 用 flex-wrap 排列头像 + 名字胶囊；hover / focus 弹出富文本 tooltip
         * 展示评论摘要 + 相对时间 + 来源文章链接。tooltip 用 CSS，不依赖 JS */
        .kratos-friend-links .kfl-visitors{
            list-style:none;margin:0;padding:0;
            display:flex;flex-wrap:wrap;gap:8px;
        }
        .kratos-friend-links .kfl-visitor{
            position:relative;
            outline:none;
        }
        .kratos-friend-links .kfl-visitor-link{
            display:inline-flex;align-items:center;gap:8px;
            padding:6px 12px 6px 6px;
            background:var(--khs-bg-2);
            border:1px solid var(--khs-line);
            border-radius:999px;
            text-decoration:none !important;
            color:var(--khs-fg-soft) !important;
            transition:transform .18s ease,border-color .18s ease,background .18s ease,color .18s ease;
        }
        .kratos-friend-links .kfl-visitor-link:hover,
        .kratos-friend-links .kfl-visitor:focus .kfl-visitor-link,
        .kratos-friend-links .kfl-visitor:focus-within .kfl-visitor-link{
            transform:translateY(-1px);
            border-color:var(--khs-accent);
            color:var(--khs-accent) !important;
        }
        .kratos-friend-links .kfl-visitor-avatar-img,
        .kratos-friend-links .kfl-visitor-avatar img{
            width:26px !important;height:26px !important;
            border-radius:50% !important;
            border:1px solid var(--khs-line);
            display:block;
            box-shadow:none;
        }
        .kratos-friend-links .kfl-visitor-name{
            font-size:13px;font-weight:600;line-height:1;
            max-width:120px;
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        }

        /* Tooltip 卡片：position:fixed + JS 定位。fixed 相对视口，不受 .kfl-section
         * 的 overflow / 兄弟 section 的 stacking order 影响；JS 会把 left 夹在
         * 视口 [8, w-w-8] 之间，避免边缘超出。 */
        .kratos-friend-links .kfl-visitor-tip{
            position:fixed;left:0;top:0;
            width:260px;max-width:calc(100vw - 16px);
            padding:12px 14px;
            /* 部分皮肤的 --khs-card-bg 是半透明色，会让 tooltip 透出底层内容。
             * 这里用不透明底色（浅色皮肤 #fff / 深色皮肤 #1f2229），并叠加毛玻璃兜底。 */
            background:#ffffff;
            backdrop-filter:blur(6px);
            -webkit-backdrop-filter:blur(6px);
            border:1px solid var(--khs-line-strong);
            border-radius:10px;
            box-shadow:0 12px 32px rgba(0,0,0,.22);
            font-size:12.5px;line-height:1.55;color:var(--khs-fg-soft);
            opacity:0;visibility:hidden;pointer-events:none;
            transform:translateY(4px);
            transition:opacity .18s ease,transform .18s ease,visibility 0s linear .18s;
            z-index:9999;text-align:left;
        }
        /* 用 ::after 造一条透明的"鼠标桥"，覆盖 host↔tip 之间的 8px 间隙，
         * 让鼠标沿直线移入 tooltip 不会踩空到底层元素上；.is-flipped 时挪到底部 */
        .kratos-friend-links .kfl-visitor-tip::after{
            content:"";position:absolute;
            left:0;right:0;top:-10px;height:10px;
            background:transparent;
        }
        .kratos-friend-links .kfl-visitor-tip.is-flipped::after{
            top:auto;bottom:-10px;
        }
        .kratos-friend-links .kfl-visitor-tip.is-open{
            opacity:1;visibility:visible;pointer-events:auto;
            transform:translateY(0);
            transition:opacity .18s ease,transform .18s ease,visibility 0s;
        }
        /* 三角箭头：默认在顶部指向触发元素；--arrow-x 由 JS 写入
         * .is-flipped 时（tooltip 位于触发元素上方）箭头翻到底部指向下方 */
        .kratos-friend-links .kfl-visitor-tip::before{
            content:"";position:absolute;top:-6px;
            left:var(--arrow-x,50%);
            width:10px;height:10px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line-strong);
            border-right:none;border-bottom:none;
            transform:translateX(-50%) rotate(45deg);
        }
        .kratos-friend-links .kfl-visitor-tip.is-flipped::before{
            top:auto;bottom:-6px;
            border:1px solid var(--khs-line-strong);
            border-left:none;border-top:none;
        }
        html[data-theme="dark"] .kratos-friend-links .kfl-visitor-tip,
        html[data-theme="dark"] .kratos-friend-links .kfl-visitor-tip::before{
            background:#25282f;
        }

        .kratos-friend-links .kfl-visitor-tip-head{
            display:flex;align-items:baseline;justify-content:space-between;gap:8px;
            margin-bottom:6px;padding-bottom:6px;
            border-bottom:1px solid var(--khs-line);
        }
        .kratos-friend-links .kfl-visitor-tip-name{
            font-size:13px;font-weight:700;color:var(--khs-fg) !important;
            text-decoration:none !important;
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
            min-width:0;
        }
        a.kfl-visitor-tip-name:hover{color:var(--khs-accent) !important;}
        .kratos-friend-links .kfl-visitor-tip-time{
            flex-shrink:0;
            font-size:11px;color:var(--khs-fg-mute);
        }
        .kratos-friend-links .kfl-visitor-tip-body{
            margin-bottom:8px;
            color:var(--khs-fg-soft);
            word-break:break-word;
            display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:4;
            overflow:hidden;
        }
        .kratos-friend-links .kfl-visitor-tip-post{
            display:inline-flex;align-items:center;gap:4px;
            padding-top:4px;
            font-size:11.5px;color:var(--khs-fg-mute) !important;
            text-decoration:none !important;
            border-top:1px dashed var(--khs-line);
            width:100%;
            transition:color .18s ease;
        }
        .kratos-friend-links .kfl-visitor-tip-post:hover{color:var(--khs-accent) !important;}
        .kratos-friend-links .kfl-visitor-tip-post span{
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
            min-width:0;
        }

        /* 申请表单 */
        .kratos-friend-links .kfl-form-section{margin-top:20px;}
        .kratos-friend-links .kfl-form-row{
            display:grid;grid-template-columns:repeat(2,minmax(0,1fr));
            gap:12px;margin-bottom:12px;
        }
        .kratos-friend-links .kfl-field-full{grid-column:1 / -1;}
        .kratos-friend-links .kfl-field{display:flex;flex-direction:column;gap:6px;min-width:0;}
        .kratos-friend-links .kfl-label{
            font-size:13px;font-weight:600;color:var(--khs-fg-soft);
        }
        .kratos-friend-links .kfl-required{color:#d63a6b;margin-left:2px;}
        .kratos-friend-links .kfl-input{
            width:100%;padding:9px 12px;
            font-size:14px;line-height:1.5;color:var(--khs-fg);
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:8px;
            box-shadow:none;
            transition:border-color .15s ease,box-shadow .15s ease;
        }
        /* 各浏览器 placeholder 用同一套灰度，避免继承主题白色 */
        .kratos-friend-links .kfl-input::placeholder{
            color:var(--khs-fg-mute);opacity:1;
        }
        .kratos-friend-links .kfl-input::-webkit-input-placeholder{color:var(--khs-fg-mute);opacity:1;}
        .kratos-friend-links .kfl-input::-moz-placeholder{color:var(--khs-fg-mute);opacity:1;}
        .kratos-friend-links .kfl-input:-ms-input-placeholder{color:var(--khs-fg-mute);opacity:1;}
        .kratos-friend-links .kfl-input:focus{
            outline:none;
            border-color:var(--khs-accent);
            box-shadow:0 0 0 3px rgba(51,102,153,.14);
        }
        .kratos-friend-links .kfl-help{font-size:11.5px;color:var(--khs-fg-mute);}
        /* 验证码：题目胶囊 + 答案输入框 + 刷新按钮，一行横向排布 */
        .kratos-friend-links .kfl-captcha-row{
            display:flex;align-items:center;gap:8px;flex-wrap:wrap;
        }
        .kratos-friend-links .kfl-captcha-q{
            display:inline-flex;align-items:center;
            padding:9px 14px;
            font-size:15px;font-weight:600;line-height:1;
            color:var(--khs-fg);
            background:var(--khs-bg-2);
            border:1px solid var(--khs-line);
            border-radius:8px;
            font-variant-numeric:tabular-nums;
            user-select:none;
        }
        .kratos-friend-links .kfl-captcha-input{
            width:110px;flex:0 0 110px;
        }
        .kratos-friend-links .kfl-captcha-refresh{
            appearance:none;cursor:pointer;
            width:36px;height:36px;flex-shrink:0;
            display:inline-flex;align-items:center;justify-content:center;
            padding:0;
            color:var(--khs-fg-dim);
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:8px;
            transition:color .2s ease,border-color .2s ease,background .2s ease,transform .3s ease;
        }
        .kratos-friend-links .kfl-captcha-refresh:hover{
            color:var(--khs-accent);
            border-color:var(--khs-accent);
        }
        .kratos-friend-links .kfl-captcha-refresh.is-spinning svg{
            animation:kflSpin .6s linear;
        }
        @keyframes kflSpin{
            from{transform:rotate(0deg);}
            to{transform:rotate(360deg);}
        }
        .kratos-friend-links .kfl-form-actions{margin-top:6px;}
        .kratos-friend-links .kfl-submit{
            appearance:none;border:none;cursor:pointer;
            padding:10px 22px;
            font-size:14px;font-weight:600;
            color:#fff;background:var(--khs-accent);
            border-radius:8px;
            transition:background .2s ease,transform .15s ease;
        }
        .kratos-friend-links .kfl-submit:hover{
            background:var(--khs-accent-2);
            transform:translateY(-1px);
        }

        /* === 本站信息卡片 === */
        .kratos-friend-links .kfl-siteinfo-card{
            display:flex;align-items:center;gap:22px;
            padding:20px 22px;border:1px solid var(--khs-line);
            border-radius:14px;background:var(--khs-bg-1);
            position:relative;
        }
        .kratos-friend-links .kfl-siteinfo-card::before{
            content:"";position:absolute;inset:0;pointer-events:none;
            border-radius:inherit;overflow:hidden;
            background:linear-gradient(135deg, color-mix(in srgb, var(--khs-accent) 8%, transparent) 0%, transparent 60%);
        }
        .kratos-friend-links .kfl-siteinfo-logo{
            position:relative;flex-shrink:0;width:84px;height:84px;border-radius:16px;
            object-fit:cover;border:1px solid var(--khs-line);
            background:var(--khs-bg-2);
            box-shadow:0 4px 14px rgba(0,0,0,.06);
        }
        .kratos-friend-links .kfl-siteinfo-fields{
            position:relative;flex:1;min-width:0;
            display:flex;flex-direction:column;gap:10px;
        }
        .kratos-friend-links .kfl-siteinfo-row{
            display:flex;align-items:flex-start;gap:10px;font-size:14px;line-height:1.5;
            min-width:0;
        }
        .kratos-friend-links .kfl-siteinfo-label{margin-top:2px;}
        .kratos-friend-links .kfl-siteinfo-row.is-wide{grid-column:1 / -1;}
        .kratos-friend-links .kfl-siteinfo-label{
            flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;
            min-width:44px;padding:2px 10px;height:22px;
            border-radius:999px;background:var(--khs-bg-2);
            color:var(--khs-fg-dim);font-size:12px;font-weight:600;letter-spacing:.02em;
        }
        .kratos-friend-links .kfl-siteinfo-value{
            flex:1;min-width:0;display:block;
            color:var(--khs-fg);
            word-break:break-all;overflow-wrap:anywhere;
        }
        .kratos-friend-links .kfl-siteinfo-value > a,
        .kratos-friend-links .kfl-siteinfo-value > span.kfl-siteinfo-text{
            white-space:normal;word-break:break-all;overflow-wrap:anywhere;
        }
        .kratos-friend-links .kfl-siteinfo-value > .kfl-copy-btn{
            display:inline-flex;vertical-align:middle;margin-left:4px;
        }
        .kratos-friend-links .kfl-siteinfo-value a{
            color:var(--khs-accent);text-decoration:none;
        }
        .kratos-friend-links .kfl-siteinfo-value a:hover{text-decoration:underline;}
        .kratos-friend-links .kfl-copy-btn{
            position:relative;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;
            width:26px;height:26px;padding:0;margin:0;border:1px solid transparent;
            background:transparent;color:var(--khs-fg-dim);
            border-radius:8px;cursor:pointer;transition:background .18s,color .18s,border-color .18s,transform .18s;
        }
        .kratos-friend-links .kfl-copy-btn:hover{
            background:var(--khs-bg-2);color:var(--khs-accent);border-color:var(--khs-line);
        }
        .kratos-friend-links .kfl-copy-btn:active{transform:scale(.94);}
        .kratos-friend-links .kfl-copy-btn.is-copied{
            color:#28a745;background:color-mix(in srgb, #28a745 12%, transparent);border-color:color-mix(in srgb, #28a745 30%, transparent);
        }

        /* === 申请要求 === */
        .kratos-friend-links .kfl-requirements-body{
            font-size:14px;line-height:1.8;color:var(--khs-fg-soft);
            overflow-wrap:break-word;word-break:break-word;
        }
        .kratos-friend-links .kfl-requirements-body ul,
        .kratos-friend-links .kfl-requirements-body ol{
            margin:8px 0;padding-left:1.6em;
        }
        .kratos-friend-links .kfl-requirements-body li{margin:4px 0;}
        .kratos-friend-links .kfl-requirements-body p{margin:8px 0;}
        .kratos-friend-links .kfl-requirements-body a{color:var(--khs-accent);}

        /* 响应式：4 → 2 → 1 */
        @media (max-width:900px){
            .kratos-friend-links .kfl-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
        }
        @media (max-width:560px){
            .kratos-friend-links .kfl-header{padding:18px 20px;gap:10px;}
            .kratos-friend-links .kfl-title{font-size:19px;}
            .kratos-friend-links .kfl-header-divider{display:none;}
            .kratos-friend-links .kfl-subtitle{flex-basis:100%;font-size:13px;}
            .kratos-friend-links .kfl-section{padding:18px 18px;}
            .kratos-friend-links .kfl-grid{grid-template-columns:1fr;}
            .kratos-friend-links .kfl-form-row{grid-template-columns:1fr;}
            .kratos-friend-links .kfl-visitor-tip{width:220px;}
            .kratos-friend-links .kfl-siteinfo-card{flex-direction:column;align-items:center;gap:14px;padding:18px;}
            .kratos-friend-links .kfl-siteinfo-fields{grid-template-columns:1fr;gap:8px;width:100%;}
            .kratos-friend-links .kfl-siteinfo-logo{width:72px;height:72px;}
        }

        /* 暗夜：对齐 dark.css，与走心 / 归档同步；同时把 --khs-bg-* 从浅灰
         * (#f0f0f0) 改成深卡色，否则验证码题目胶囊 / 分类计数徽标这些吃 bg-2
         * 的元素在暗夜下仍然是一片高亮浅灰，与深卡对比刺眼。 */
        html[data-theme="dark"] .kratos-friend-links,body.dark .kratos-friend-links{
            --khs-bg-1:#2a2e35;--khs-bg-2:#2a2e35;--khs-bg-3:#333842;
            --khs-fg:#d6d8db;--khs-fg-soft:#b8bbc0;--khs-fg-dim:#8b919a;--khs-fg-mute:#6f747e;
            --khs-accent:#6ea8ff;--khs-accent-2:#91bdff;
            --khs-line:rgba(255,255,255,.08);--khs-line-strong:rgba(255,255,255,.16);
            --khs-card-bg:#1c1f24;
        }
        html[data-theme="dark"] .kratos-friend-links .kfl-alert-ok{
            background:rgba(46,160,67,.10);color:#7ee49a;border-color:rgba(46,160,67,.35);
        }
        html[data-theme="dark"] .kratos-friend-links .kfl-alert-err{
            background:rgba(207,34,46,.10);color:#ff8b95;border-color:rgba(207,34,46,.35);
        }
    </style>
    <?php if (function_exists('kratos_captcha_enabled') && kratos_captcha_enabled()) { ?>
    <script>
    (function(){
        var root = document.getElementById('kratos-friend-links');
        if (!root) return;
        var btn = root.querySelector('.kfl-captcha-refresh');
        var qEl = root.querySelector('.kfl-captcha-q');
        var tokEl = root.querySelector('input[name="kratos_captcha_token"]');
        var ansEl = root.querySelector('input[name="kratos_captcha"]');
        if (!btn || !qEl || !tokEl || !ansEl) return;
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        btn.addEventListener('click', function(){
            btn.classList.remove('is-spinning');
            // 强制回流触发动画重放
            void btn.offsetWidth;
            btn.classList.add('is-spinning');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function(){
                if (xhr.status < 200 || xhr.status >= 300) return;
                try{
                    var r = JSON.parse(xhr.responseText);
                    if (!r || !r.success || !r.data) return;
                    qEl.textContent = r.data.question;
                    tokEl.value = r.data.token;
                    ansEl.value = '';
                    ansEl.focus();
                } catch(e){}
            };
            xhr.send('action=kratos_captcha_refresh');
        });
    })();
    </script>
    <?php } ?>
    <script>
    (function(){
        var root = document.getElementById('kratos-friend-links');
        if (!root) return;
        var visitors = root.querySelectorAll('.kfl-visitor');
        if (!visitors.length) return;

        var MARGIN = 8;         // 距离视口边缘的最小间距
        var GAP    = 8;         // tooltip 与触发元素之间的间距
        var openTip = null;
        var openHost = null;

        // 关键：把每个 tooltip 从原 DOM 位置搬到 <body>，避免祖先节点的 transform /
        // filter / will-change 把 position:fixed 变成"相对该祖先的绝对定位"。
        // 类名保留一层 root 名字空间，样式仍然生效；因为 body 直挂，不会被任何
        // section 的 stacking context 遮挡。
        // 复用 root 的 data-theme / body.dark 变量：tooltip 移出后依然继承 :root。
        var portal = document.createElement('div');
        portal.className = 'kratos-friend-links kfl-tip-portal';
        portal.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;pointer-events:none;';
        document.body.appendChild(portal);

        function portalTip(host){
            var tip = host.querySelector(':scope > .kfl-visitor-tip');
            if (tip) {
                portal.appendChild(tip);
                host._tip = tip;
            }
            return host._tip || null;
        }

        function place(host, tip){
            tip.style.left = '0px';
            tip.style.top  = '0px';
            tip.style.setProperty('--arrow-x', '50%');

            var hrect = host.getBoundingClientRect();
            var tw = tip.offsetWidth;
            var th = tip.offsetHeight;
            var vw = window.innerWidth;
            var vh = window.innerHeight;

            var anchorX = hrect.left + hrect.width / 2;
            var left = anchorX - tw / 2;
            if (left < MARGIN) left = MARGIN;
            if (left + tw > vw - MARGIN) left = vw - MARGIN - tw;

            var below = hrect.bottom + GAP;
            var above = hrect.top - GAP - th;
            var top, flip = false;
            if (below + th <= vh - MARGIN) {
                top = below;
            } else if (above >= MARGIN) {
                top = above;
                flip = true;
            } else {
                top = Math.max(MARGIN, vh - MARGIN - th);
            }

            var arrow = anchorX - left;
            arrow = Math.max(12, Math.min(tw - 12, arrow));

            tip.style.left = left + 'px';
            tip.style.top  = top + 'px';
            tip.style.setProperty('--arrow-x', arrow + 'px');
            tip.classList.toggle('is-flipped', flip);
        }

        // 关闭延迟做小一点（120ms）—— 够跨越 host↔tip 之间的 8px 空隙，
        // 又不至于快速划过一排头像时把好几个 tooltip 同时挂着。
        // 打开新 host 时立即把其他 host 的 tooltip 收起（不走延迟），保证
        // 同一时刻只有一个 tooltip 可见。
        var CLOSE_DELAY = 120;

        function closeNow(host){
            clearTimeout(host._closeTimer);
            var tip = host._tip;
            if (!tip) return;
            tip.classList.remove('is-open');
            if (openTip === tip) { openTip = null; openHost = null; }
        }
        function scheduleClose(host){
            clearTimeout(host._closeTimer);
            host._closeTimer = setTimeout(function(){ closeNow(host); }, CLOSE_DELAY);
        }
        function cancelClose(host){
            clearTimeout(host._closeTimer);
        }
        function open(host){
            // 有别的 tooltip 开着（可能正处在 scheduleClose 的 120ms 灰度期）→ 立即关
            if (openHost && openHost !== host) closeNow(openHost);
            cancelClose(host);
            var tip = portalTip(host);
            if (!tip) return;
            tip.classList.add('is-open');
            place(host, tip);
            openTip = tip; openHost = host;
        }

        visitors.forEach(function(host){
            host.addEventListener('mouseenter', function(){ open(host); });
            host.addEventListener('mouseleave', function(){ scheduleClose(host); });
            host.addEventListener('focusin',    function(){ open(host); });
            host.addEventListener('focusout',   function(e){
                if (host.contains(e.relatedTarget) || (host._tip && host._tip.contains(e.relatedTarget))) return;
                scheduleClose(host);
            });
            var tip = portalTip(host);
            if (tip) {
                tip.addEventListener('mouseenter', function(){ cancelClose(host); });
                tip.addEventListener('mouseleave', function(){ scheduleClose(host); });
                tip.addEventListener('focusin',    function(){ cancelClose(host); });
                tip.addEventListener('focusout',   function(e){
                    if (host.contains(e.relatedTarget) || tip.contains(e.relatedTarget)) return;
                    scheduleClose(host);
                });
            }
        });

        window.addEventListener('scroll',  function(){ if (openHost && openTip) place(openHost, openTip); }, { passive: true });
        window.addEventListener('resize',  function(){ if (openHost && openTip) place(openHost, openTip); });
    })();
    </script>
    <script>
    (function(){
        var root = document.getElementById('kratos-friend-links');
        if (!root) return;
        root.addEventListener('click', function(e){
            var btn = e.target.closest('.kfl-copy-btn');
            if (!btn) return;
            var val = btn.parentElement.getAttribute('data-copy');
            if (!val) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(val).then(function(){
                    btn.classList.add('is-copied');
                    setTimeout(function(){ btn.classList.remove('is-copied'); }, 1000);
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = val;
                ta.style.cssText = 'position:fixed;left:-9999px;';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                btn.classList.add('is-copied');
                setTimeout(function(){ btn.classList.remove('is-copied'); }, 1000);
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('friend_links', 'kratos_friend_shortcode');

/* ============================================================
 *  申请表单：POST 处理
 * ============================================================ */

/**
 * 把 wp_die 的失败原因转成用户友好的中文。放页面上的 alert，不吐 wp_die 硬报错。
 */
function kratos_friend_reason_msg($key)
{
    $map = array(
        'nonce'           => __('会话已过期，请刷新后重试。', 'kratos'),
        'required'        => __('请把带 * 的字段填完。', 'kratos'),
        'url'             => __('网站地址不合法，请填写完整的 http(s):// 链接。', 'kratos'),
        'rss'             => __('RSS 地址不合法，请填写完整的 http(s):// 链接或留空。', 'kratos'),
        'image'           => __('Logo 地址不合法，请填写完整的 http(s):// 图片链接或留空。', 'kratos'),
        'hp'              => __('提交被拦截，请稍后再试。', 'kratos'),
        'cooldown'        => __('提交过于频繁，请 1 分钟后再试。', 'kratos'),
        'exists'          => __('该网址已经在友链里啦，感谢关注。', 'kratos'),
        'name_len'        => __('网站名称最长 120 字符。', 'kratos'),
        'db'              => __('保存失败，请稍后再试或联系站长。', 'kratos'),
        'captcha_empty'   => __('请填写验证码后再提交。', 'kratos'),
        'captcha_expired' => __('验证码已过期，请点击「换一题」后重新填写。', 'kratos'),
        'captcha_wrong'   => __('验证码答案错误，请点击「换一题」后重新填写。', 'kratos'),
    );
    return isset($map[$key]) ? $map[$key] : __('提交失败，请检查填写内容后重试。', 'kratos');
}

function kratos_friend_handle_apply()
{
    $redirect = isset($_POST['_kfl_redirect']) ? esc_url_raw(wp_unslash($_POST['_kfl_redirect'])) : home_url('/');
    $back = function ($status, $reason = '') use ($redirect) {
        $url = add_query_arg(array(
            'kfl_status' => $status,
            'kfl_reason' => $reason,
        ), $redirect);
        wp_safe_redirect($url . '#kratos-friend-apply');
        exit;
    };

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'kratos_friend_apply')) {
        $back('err', 'nonce');
    }

    // 表单开关
    if (!kratos_option('g_friend_form_enabled', true)) {
        $back('err', 'nonce');
    }

    // Honeypot
    if (!empty($_POST['kfl_hp_website'])) {
        $back('err', 'hp');
    }

    // 算术验证码（复用 [评论验证码] 模块，同一套 transient 存活 10 分钟）
    if (function_exists('kratos_captcha_enabled') && kratos_captcha_enabled()) {
        $cap_token  = isset($_POST['kratos_captcha_token']) ? sanitize_text_field(wp_unslash($_POST['kratos_captcha_token'])) : '';
        $cap_answer = isset($_POST['kratos_captcha']) ? trim(wp_unslash($_POST['kratos_captcha'])) : '';
        if ($cap_token === '' || $cap_answer === '') {
            $back('err', 'captcha_empty');
        }
        $expected = get_transient('kratos_captcha_' . $cap_token);
        delete_transient('kratos_captcha_' . $cap_token); // 一次性使用
        if ($expected === false) {
            $back('err', 'captcha_expired');
        }
        if ((string) intval($cap_answer) !== (string) $expected) {
            $back('err', 'captcha_wrong');
        }
    }

    // IP 冷却
    $ip = kratos_friend_client_ip();
    if ($ip !== '') {
        $rl_key = KRATOS_FRIEND_HOSTS_LRU_KEY . md5($ip);
        if (get_transient($rl_key)) {
            $back('err', 'cooldown');
        }
    }

    $name = isset($_POST['link_name']) ? sanitize_text_field(wp_unslash($_POST['link_name'])) : '';
    $url  = isset($_POST['link_url'])  ? esc_url_raw(wp_unslash($_POST['link_url']))         : '';
    $desc = isset($_POST['link_description']) ? sanitize_text_field(wp_unslash($_POST['link_description'])) : '';
    $img  = isset($_POST['link_image']) ? esc_url_raw(wp_unslash($_POST['link_image']))      : '';
    $rss  = isset($_POST['link_rss'])  ? esc_url_raw(wp_unslash($_POST['link_rss']))         : '';

    if ($name === '' || $url === '') {
        $back('err', 'required');
    }
    if (function_exists('mb_strlen') ? mb_strlen($name) > 120 : strlen($name) > 120) {
        $back('err', 'name_len');
    }
    if (!preg_match('#^https?://#i', $url)) {
        $back('err', 'url');
    }
    if ($img !== '' && !preg_match('#^https?://#i', $img)) {
        $back('err', 'image');
    }
    if ($rss !== '' && !preg_match('#^https?://#i', $rss)) {
        $back('err', 'rss');
    }

    // 去重：同 URL 已存在（不管审核状态）就拒绝
    global $wpdb;
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->links} WHERE link_url = %s",
        $url
    ));
    if ($exists > 0) {
        $back('err', 'exists');
    }

    // 决定归类到哪个 link_category：优先用后台设置的默认分类，没有就随便挑一个
    $default_term_id = (int) kratos_option('g_friend_default_category', 0);
    if ($default_term_id <= 0 || !term_exists($default_term_id, 'link_category')) {
        // 默认取 term_id 最小的 link_category（通常是 WP 初始化时创建的 Blogroll）
        $terms = get_terms(array(
            'taxonomy'   => 'link_category',
            'hide_empty' => false,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
            'number'     => 1,
        ));
        if (!is_wp_error($terms) && !empty($terms)) {
            $default_term_id = (int) $terms[0]->term_id;
        }
    }

    if (!function_exists('wp_insert_link')) {
        require_once ABSPATH . 'wp-admin/includes/bookmark.php';
    }

    $link_id = wp_insert_link(array(
        'link_name'        => $name,
        'link_url'         => $url,
        'link_description' => $desc,
        'link_image'       => $img,
        'link_rss'         => $rss,
        'link_target'      => '_blank',
        'link_visible'     => 'N',
        'link_category'    => $default_term_id > 0 ? array($default_term_id) : array(),
        'link_rating'      => 0,
        'link_notes'       => sprintf(
            "[friend_apply]\nIP: %s\nUA: %s\nTime: %s",
            $ip,
            isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 300) : '',
            current_time('mysql')
        ),
    ), true);

    if (is_wp_error($link_id) || !$link_id) {
        $back('err', 'db');
    }

    // 邮件通知
    if (kratos_option('g_friend_notify_admin', true)) {
        kratos_friend_notify_admin($link_id, $name, $url, $desc, $rss);
    }

    // 写冷却
    if ($ip !== '') {
        set_transient($rl_key, 1, KRATOS_FRIEND_APPLY_COOLDOWN);
    }

    $back('ok');
}
add_action('admin_post_kratos_friend_apply',        'kratos_friend_handle_apply');
add_action('admin_post_nopriv_kratos_friend_apply', 'kratos_friend_handle_apply');

function kratos_friend_client_ip()
{
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}

function kratos_friend_notify_admin($link_id, $name, $url, $desc, $rss)
{
    $to = get_option('admin_email');
    if (!$to) return;
    $site = get_bloginfo('name');
    $subject = sprintf(__('[%s] 收到新的友链申请：%s', 'kratos'), $site, $name);
    $review_url = admin_url('link.php?action=edit&link_id=' . (int) $link_id);
    $lines = array(
        __('你收到了一份友链申请，请审核：', 'kratos'),
        '',
        __('网站名称：', 'kratos') . $name,
        __('网站地址：', 'kratos') . $url,
        __('网站描述：', 'kratos') . ($desc !== '' ? $desc : '（未填写）'),
        __('RSS 地址：', 'kratos') . ($rss  !== '' ? $rss  : '（未填写）'),
        '',
        __('审核链接：', 'kratos') . $review_url,
    );
    wp_mail($to, $subject, implode("\n", $lines));
}

/* ============================================================
 *  后台 link-manager 扩展：状态列 / 行操作 / 待审核提示
 * ============================================================ */

/**
 * link-manager 顶部提示待审核数量
 */
function kratos_friend_admin_pending_notice()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'link-manager') return;
    $n = kratos_friend_pending_count();
    if ($n <= 0) return;
    $pending_url = add_query_arg('kfl_filter', 'pending', admin_url('link-manager.php'));
    echo '<div class="notice notice-warning"><p>' . sprintf(
        esc_html__('你有 %1$s 条友链申请待审核，%2$s', 'kratos'),
        '<strong>' . (int) $n . '</strong>',
        '<a href="' . esc_url($pending_url) . '">' . esc_html__('立即查看 →', 'kratos') . '</a>'
    ) . '</p></div>';
}
add_action('admin_notices', 'kratos_friend_admin_pending_notice');

/**
 * link-manager 顶部筛选：全部 / 仅待审核 / 仅已通过
 *
 * WP_Links_List_Table 底层调用 get_bookmarks(hide_invisible = 1)，pending 视图
 * 必须先把 hide_invisible 关掉；然后用 get_bookmarks filter（拿到的是 bookmark
 * 数组）在数组层面二次过滤。这样即绕过了 WP 硬编码的 link_visible='Y'，又不
 * 用改任何 SQL。
 */
function kratos_friend_admin_pre_get_bookmarks($args)
{
    if (!is_admin() || empty($_GET['kfl_filter'])) return $args;
    if ($_GET['kfl_filter'] === 'pending') {
        $args['hide_invisible'] = 0;
    }
    return $args;
}
add_filter('pre_get_bookmarks', 'kratos_friend_admin_pre_get_bookmarks');

function kratos_friend_admin_filter_bookmarks($bookmarks)
{
    if (!is_admin() || !is_array($bookmarks)) return $bookmarks;

    $probe = kratos_friend_admin_probe_filter();
    if (empty($_GET['kfl_filter']) && $probe === '') return $bookmarks;

    if (!empty($_GET['kfl_filter'])) {
        $want = $_GET['kfl_filter'] === 'pending' ? 'N' : ($_GET['kfl_filter'] === 'approved' ? 'Y' : '');
        if ($want !== '') {
            $bookmarks = array_filter($bookmarks, function ($b) use ($want) {
                return isset($b->link_visible) && $b->link_visible === $want;
            });
        }
    }

    if ($probe !== '') {
        $data = kratos_friend_probe_get_all();
        $bookmarks = array_filter($bookmarks, function ($b) use ($probe, $data) {
            return kratos_friend_admin_probe_status($b->link_id, $data) === $probe;
        });
    }

    return array_values($bookmarks);
}
add_filter('get_bookmarks', 'kratos_friend_admin_filter_bookmarks');

/**
 * 当前请求的探活筛选值：'' / reachable / unreachable / unknown
 */
function kratos_friend_admin_probe_filter()
{
    $v = isset($_GET['kfl_probe']) ? sanitize_key(wp_unslash($_GET['kfl_probe'])) : '';
    return in_array($v, array('reachable', 'unreachable', 'unknown'), true) ? $v : '';
}

/**
 * 单条链接的探活状态：reachable / unreachable / unknown（缓存里没有记录算未检测）
 */
function kratos_friend_admin_probe_status($link_id, $data = null)
{
    if ($data === null) $data = kratos_friend_probe_get_all();
    $row = isset($data[(int) $link_id]) ? $data[(int) $link_id] : null;
    if (!is_array($row) || empty($row['status'])) return 'unknown';
    return $row['status'] === 'reachable' ? 'reachable' : 'unreachable';
}

/**
 * 探活筛选下拉：塞到 link-manager 自带的「分类」筛选下拉后面。
 *
 * WP_Links_List_Table::extra_tablenav() 里那个 .actions（分类下拉 +「筛选」按钮）
 * 没有任何钩子，所以照 kratos_friend_bulk_cat_select() 的路子在页脚输出 JS，把
 * select 插到 #post-query-submit 前面。表单是 method="get"，随「筛选」一起提交。
 */
function kratos_friend_admin_probe_filter_select()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'link-manager') return;
    if (!kratos_option('g_friend_probe_enabled', false)) return;

    $current = kratos_friend_admin_probe_filter();
    $items = array(
        ''            => __('所有探活状态', 'kratos'),
        'reachable'   => __('可访问', 'kratos'),
        'unreachable' => __('不可访问', 'kratos'),
        'unknown'     => __('未检测', 'kratos'),
    );
    $options = '';
    foreach ($items as $k => $label) {
        $options .= '<option value="' . esc_attr($k) . '"' . selected($current, $k, false) . '>' . esc_html($label) . '</option>';
    }
    $html = '<select name="kfl_probe" id="kfl-probe-filter" style="margin-right:6px;">' . $options . '</select>';
    ?>
    <script>
    (function () {
        var submit = document.getElementById('post-query-submit');
        if (!submit) return;
        submit.insertAdjacentHTML('beforebegin', '<?php echo str_replace(array("\r", "\n"), '', addcslashes($html, "'\\")); ?>');
    })();
    </script>
    <?php
}
add_action('admin_footer', 'kratos_friend_admin_probe_filter_select');

/**
 * 在 link-manager 列表标题区加筛选表单
 */
function kratos_friend_admin_filter_form()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'link-manager') return;
    $current = isset($_GET['kfl_filter']) ? sanitize_key(wp_unslash($_GET['kfl_filter'])) : '';
    $base = admin_url('link-manager.php');
    $tab = function ($key, $label) use ($current, $base) {
        $url = $key === '' ? $base : add_query_arg('kfl_filter', $key, $base);
        $cls = $key === $current ? 'current' : '';
        return '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    };
    echo '<ul class="subsubsub" style="margin-top:6px;">';
    echo '<li>' . $tab('', __('全部', 'kratos')) . ' |</li>';
    echo '<li>' . $tab('pending', sprintf(__('待审核（%d）', 'kratos'), kratos_friend_pending_count())) . ' |</li>';
    echo '<li>' . $tab('approved', __('已通过', 'kratos')) . '</li>';
    echo '</ul><div style="clear:both;"></div>';
}
add_action('admin_notices', 'kratos_friend_admin_filter_form', 20);

/* ============================================================
 *  link-manager 批量操作：批量设置 / 追加 link_category
 * ============================================================ */

/**
 * 往批量操作下拉里加两项。
 *
 * WP_Links_List_Table::get_bulk_actions() 只给了「删除」，但
 * WP_List_Table::bulk_actions() 会跑 bulk_actions-{screen_id} 过滤器，
 * 所以这里挂 link-manager 即可。
 */
function kratos_friend_bulk_actions($actions)
{
    $actions['kfl_set_cat'] = __('设置分类（替换原有）', 'kratos');
    $actions['kfl_add_cat'] = __('追加分类', 'kratos');
    return $actions;
}
add_filter('bulk_actions-link-manager', 'kratos_friend_bulk_actions');

/**
 * 目标分类选择框。
 *
 * link-manager 没有 restrict_manage_posts 之类的钩子，只能在页脚输出一份
 * 模板，再用 JS 塞到上下两处 .bulkactions 里（表单是 method="get"，select
 * 带 name 就能随批量提交一起过来）。
 */
function kratos_friend_bulk_cat_select()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'link-manager') return;

    $terms = get_terms(array(
        'taxonomy'   => 'link_category',
        'hide_empty' => false,
        'orderby'    => 'name',
    ));
    if (is_wp_error($terms) || empty($terms)) return;

    $options = '<option value="">' . esc_html__('— 选择友链分类 —', 'kratos') . '</option>';
    foreach ($terms as $term) {
        $options .= '<option value="' . (int) $term->term_id . '">' . esc_html($term->name) . '</option>';
    }
    ?>
    <script>
    (function () {
        var html = '<select name="kfl_bulk_cat" class="kfl-bulk-cat" style="margin-right:6px;"><?php echo str_replace(array("\r", "\n"), '', addcslashes($options, "'\\")); ?></select>';
        document.querySelectorAll('#posts-filter .bulkactions').forEach(function (box) {
            var submit = box.querySelector('input[type="submit"]');
            if (!submit) return;
            submit.insertAdjacentHTML('beforebegin', html);
        });
        // 两处 select 保持同步，避免上面选了、提交下面那个
        var sels = document.querySelectorAll('#posts-filter select[name="kfl_bulk_cat"]');
        sels.forEach(function (s) {
            s.addEventListener('change', function () {
                sels.forEach(function (o) { o.value = s.value; });
            });
        });
    })();
    </script>
    <?php
}
add_action('admin_footer', 'kratos_friend_bulk_cat_select');

/**
 * 执行批量分类。
 *
 * 挂 load-link-manager.php：admin.php 里这个钩子跑在 include link-manager.php
 * 之前，而 link-manager.php 自己只处理 'delete'，其它 action 会被它直接
 * redirect 掉，所以必须抢在它之前处理完并自行跳转。
 */
function kratos_friend_bulk_handle_cat()
{
    $action = '';
    foreach (array('action', 'action2') as $key) {
        if (!empty($_REQUEST[$key]) && $_REQUEST[$key] !== '-1') {
            $action = sanitize_key(wp_unslash($_REQUEST[$key]));
            break;
        }
    }
    if ($action !== 'kfl_set_cat' && $action !== 'kfl_add_cat') return;

    if (!current_user_can('manage_links')) wp_die(__('权限不足', 'kratos'));
    check_admin_referer('bulk-bookmarks');

    $sendback = remove_query_arg(
        array('action', 'action2', 'kfl_bulk_cat', 'linkcheck', '_wpnonce', '_wp_http_referer', 'kfl_cat_done', 'kfl_cat_err'),
        wp_get_referer() ?: admin_url('link-manager.php')
    );

    $term_id = isset($_REQUEST['kfl_bulk_cat']) ? (int) $_REQUEST['kfl_bulk_cat'] : 0;
    $ids     = isset($_REQUEST['linkcheck']) ? array_map('intval', (array) wp_unslash($_REQUEST['linkcheck'])) : array();
    $ids     = array_filter(array_unique($ids));

    if ($term_id <= 0 || !term_exists($term_id, 'link_category')) {
        wp_safe_redirect(add_query_arg('kfl_cat_err', 'noterm', $sendback));
        exit;
    }
    if (empty($ids)) {
        wp_safe_redirect(add_query_arg('kfl_cat_err', 'nolink', $sendback));
        exit;
    }

    if (!function_exists('wp_set_link_cats')) {
        require_once ABSPATH . 'wp-admin/includes/bookmark.php';
    }

    $done = 0;
    foreach ($ids as $link_id) {
        $link = get_bookmark($link_id);
        if (!$link) continue;

        if ($action === 'kfl_add_cat') {
            $current = wp_get_object_terms($link_id, 'link_category', array('fields' => 'ids'));
            $current = is_wp_error($current) ? array() : array_map('intval', $current);
            if (in_array($term_id, $current, true)) continue;
            $cats = array_merge($current, array($term_id));
        } else {
            $cats = array($term_id);
        }

        wp_set_link_cats($link_id, $cats);
        $done++;
    }

    if (function_exists('kratos_blogroll_clear_cache')) {
        kratos_blogroll_clear_cache();
    }

    wp_safe_redirect(add_query_arg('kfl_cat_done', $done, $sendback));
    exit;
}
add_action('load-link-manager.php', 'kratos_friend_bulk_handle_cat');

/**
 * 批量分类结果提示
 */
function kratos_friend_bulk_cat_notice()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'link-manager') return;

    if (isset($_GET['kfl_cat_done'])) {
        $n = (int) $_GET['kfl_cat_done'];
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
            _n('已更新 %s 条友链的分类。', '已更新 %s 条友链的分类。', $n, 'kratos'),
            number_format_i18n($n)
        )) . '</p></div>';
        return;
    }
    if (isset($_GET['kfl_cat_err'])) {
        $err = sanitize_key(wp_unslash($_GET['kfl_cat_err']));
        $msg = $err === 'noterm'
            ? __('请先在批量操作旁边选择一个友链分类。', 'kratos')
            : __('请先勾选要修改的友链。', 'kratos');
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }
}
add_action('admin_notices', 'kratos_friend_bulk_cat_notice', 19);

/**
 * link-manager 列表加「状态」列
 */
function kratos_friend_admin_add_column($columns)
{
    $new = array();
    foreach ($columns as $k => $v) {
        $new[$k] = $v;
        if ($k === 'name') {
            $new['kfl_status'] = __('状态', 'kratos');
        }
    }
    if (!isset($new['kfl_status'])) $new['kfl_status'] = __('状态', 'kratos');
    return $new;
}
add_filter('manage_link-manager_columns', 'kratos_friend_admin_add_column');

/**
 * 状态列内容 + row action「通过 / 拒绝」
 */
function kratos_friend_admin_render_column($column, $link_id)
{
    if ($column !== 'kfl_status') return;
    $link = get_bookmark((int) $link_id);
    if (!$link) return;

    if ($link->link_visible === 'Y') {
        echo '<span style="color:#1a7f37;font-weight:600;">' . esc_html__('已通过', 'kratos') . '</span>';
    } else {
        echo '<span style="color:#a4111e;font-weight:600;">' . esc_html__('待审核', 'kratos') . '</span>';
    }

    // 快速操作按钮
    $approve_url = wp_nonce_url(
        add_query_arg(array('action' => 'kratos_friend_approve', 'link_id' => $link_id), admin_url('admin-post.php')),
        'kratos_friend_approve_' . $link_id
    );
    $reject_url = wp_nonce_url(
        add_query_arg(array('action' => 'kratos_friend_reject', 'link_id' => $link_id), admin_url('admin-post.php')),
        'kratos_friend_reject_' . $link_id
    );

    echo '<div class="row-actions" style="visibility:visible;position:static;padding-top:2px;">';
    if ($link->link_visible === 'Y') {
        echo '<span><a href="' . esc_url($reject_url) . '" style="color:#a4111e;">' . esc_html__('设为待审核', 'kratos') . '</a></span>';
    } else {
        echo '<span><a href="' . esc_url($approve_url) . '" style="color:#1a7f37;font-weight:600;">' . esc_html__('通过', 'kratos') . '</a> | </span>';
        echo '<span><a href="' . esc_url($reject_url) . '" style="color:#a4111e;" onclick="return confirm(\'' . esc_js(__('确定拒绝该申请？此操作会删除该链接。', 'kratos')) . '\');">' . esc_html__('拒绝', 'kratos') . '</a></span>';
    }
    echo '</div>';
}
add_action('manage_link_custom_column', 'kratos_friend_admin_render_column', 10, 2);

/**
 * 处理审批
 */
function kratos_friend_admin_handle_approve()
{
    $link_id = isset($_GET['link_id']) ? (int) $_GET['link_id'] : 0;
    if (!$link_id || !current_user_can('manage_links')) wp_die(__('权限不足', 'kratos'));
    check_admin_referer('kratos_friend_approve_' . $link_id);

    if (!function_exists('wp_update_link')) {
        require_once ABSPATH . 'wp-admin/includes/bookmark.php';
    }
    wp_update_link(array('link_id' => $link_id, 'link_visible' => 'Y'));

    // 清除友链 host 缓存，让评论友链徽章立刻生效
    if (function_exists('kratos_blogroll_clear_cache')) {
        kratos_blogroll_clear_cache();
    }

    wp_safe_redirect(wp_get_referer() ?: admin_url('link-manager.php'));
    exit;
}
add_action('admin_post_kratos_friend_approve', 'kratos_friend_admin_handle_approve');

/**
 * 拒绝：直接删除记录
 */
function kratos_friend_admin_handle_reject()
{
    $link_id = isset($_GET['link_id']) ? (int) $_GET['link_id'] : 0;
    if (!$link_id || !current_user_can('manage_links')) wp_die(__('权限不足', 'kratos'));
    check_admin_referer('kratos_friend_reject_' . $link_id);

    $link = get_bookmark($link_id);
    if ($link) {
        if ($link->link_visible === 'Y') {
            // 已通过 → 设回待审核
            if (!function_exists('wp_update_link')) {
                require_once ABSPATH . 'wp-admin/includes/bookmark.php';
            }
            wp_update_link(array('link_id' => $link_id, 'link_visible' => 'N'));
        } else {
            // 待审核 → 直接删除
            if (!function_exists('wp_delete_link')) {
                require_once ABSPATH . 'wp-admin/includes/bookmark.php';
            }
            wp_delete_link($link_id);
        }
        if (function_exists('kratos_blogroll_clear_cache')) {
            kratos_blogroll_clear_cache();
        }
    }

    wp_safe_redirect(wp_get_referer() ?: admin_url('link-manager.php'));
    exit;
}
add_action('admin_post_kratos_friend_reject', 'kratos_friend_admin_handle_reject');

/**
 * WP admin bar 待审核角标
 */
function kratos_friend_admin_bar($wp_admin_bar)
{
    if (!current_user_can('manage_links')) return;
    $n = kratos_friend_pending_count();
    if ($n <= 0) return;
    $wp_admin_bar->add_node(array(
        'id'    => 'kratos-friend-pending',
        'title' => sprintf('%s <span class="awaiting-mod">%d</span>', esc_html__('友链待审核', 'kratos'), $n),
        'href'  => add_query_arg('kfl_filter', 'pending', admin_url('link-manager.php')),
    ));
}
add_action('admin_bar_menu', 'kratos_friend_admin_bar', 90);

/**
 * page-friend-links.php 模板注入 body class，方便皮肤层豁免外层 .details 装饰
 */
function kratos_friend_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-friend-links.php')) {
        $classes[] = 'is-kratos-friend-links-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_friend_body_class');

/* ============================================================
 *  友链探活：定时检测 + 缓存 + 后台展示
 * ============================================================ */

const KRATOS_FRIEND_PROBE_OPTION   = 'kratos_friend_probe';
const KRATOS_FRIEND_PROBE_HOOK     = 'kratos_friend_probe_event';
const KRATOS_FRIEND_PROBE_TIMEOUT  = 5;
const KRATOS_FRIEND_PROBE_BATCH    = 5;

/**
 * 读取探活缓存（全量）
 */
function kratos_friend_probe_get_all()
{
    $data = get_option(KRATOS_FRIEND_PROBE_OPTION, array());
    return is_array($data) ? $data : array();
}

/**
 * 探测单条 URL 可达性（HEAD 请求）
 */
function kratos_friend_probe_check_url($url)
{
    $resp = wp_remote_head($url, array(
        'timeout'     => KRATOS_FRIEND_PROBE_TIMEOUT,
        'redirection' => 3,
        'sslverify'   => false,
        'user-agent'  => 'Mozilla/5.0 (compatible; KratosPlusProbe/1.0)',
    ));
    if (is_wp_error($resp)) {
        return 'unreachable';
    }
    $code = wp_remote_retrieve_response_code($resp);
    return ($code >= 200 && $code < 400) ? 'reachable' : 'unreachable';
}

/**
 * 批量探测所有已通过友链并写入缓存
 */
function kratos_friend_probe_run()
{
    if (!kratos_option('g_friend_probe_enabled', false)) {
        return;
    }

    global $wpdb;
    $links = $wpdb->get_results(
        "SELECT link_id, link_url FROM {$wpdb->links} WHERE link_visible = 'Y' AND link_url != ''"
    );
    if (empty($links)) {
        update_option(KRATOS_FRIEND_PROBE_OPTION, array(), false);
        return;
    }

    $data = kratos_friend_probe_get_all();
    $now  = time();
    $i    = 0;

    foreach ($links as $link) {
        $status = kratos_friend_probe_check_url($link->link_url);
        $data[(int) $link->link_id] = array(
            'status'     => $status,
            'checked_at' => $now,
        );
        $i++;
        if ($i % KRATOS_FRIEND_PROBE_BATCH === 0) {
            sleep(1);
        }
    }

    // 清理已删除的 link_id
    $valid_ids = array_map(function ($l) { return (int) $l->link_id; }, $links);
    $data = array_intersect_key($data, array_flip($valid_ids));

    update_option(KRATOS_FRIEND_PROBE_OPTION, $data, false);
}
add_action(KRATOS_FRIEND_PROBE_HOOK, 'kratos_friend_probe_run');

/**
 * 探测单条友链并更新缓存（后台手动触发用）
 */
function kratos_friend_probe_single($link_id)
{
    $link = get_bookmark((int) $link_id);
    if (!$link || $link->link_url === '') {
        return null;
    }

    $status = kratos_friend_probe_check_url($link->link_url);
    $data = kratos_friend_probe_get_all();
    $data[(int) $link_id] = array(
        'status'     => $status,
        'checked_at' => time(),
    );
    update_option(KRATOS_FRIEND_PROBE_OPTION, $data, false);
    return $status;
}

/**
 * Cron 调度管理：开关/频率变更时重新注册
 */
function kratos_friend_probe_schedule()
{
    $enabled  = (bool) kratos_option('g_friend_probe_enabled', false);
    $interval = (string) kratos_option('g_friend_probe_interval', 'daily');
    if (!in_array($interval, array('hourly', 'twicedaily', 'daily'), true)) {
        $interval = 'daily';
    }

    $next = wp_next_scheduled(KRATOS_FRIEND_PROBE_HOOK);

    if (!$enabled) {
        if ($next) {
            wp_clear_scheduled_hook(KRATOS_FRIEND_PROBE_HOOK);
        }
        return;
    }

    // 已调度但频率变了 → 先清再重建
    if ($next) {
        $current = wp_get_schedule(KRATOS_FRIEND_PROBE_HOOK);
        if ($current !== $interval) {
            wp_clear_scheduled_hook(KRATOS_FRIEND_PROBE_HOOK);
            $next = false;
        }
    }

    if (!$next) {
        wp_schedule_event(time() + 60, $interval, KRATOS_FRIEND_PROBE_HOOK);
    }
}
add_action('init', 'kratos_friend_probe_schedule');

/**
 * 主题选项保存后立即重新调度（不等下次 init）
 */
function kratos_friend_probe_on_options_save()
{
    kratos_friend_probe_schedule();
}
add_action('csf_kratos_options_saved', 'kratos_friend_probe_on_options_save');

/**
 * 主题切换时清理 cron
 */
function kratos_friend_probe_deactivate()
{
    wp_clear_scheduled_hook(KRATOS_FRIEND_PROBE_HOOK);
}
add_action('switch_theme', 'kratos_friend_probe_deactivate');

/* --- 后台 link-manager：探活状态列 --- */

function kratos_friend_probe_admin_column($columns)
{
    if (!kratos_option('g_friend_probe_enabled', false)) {
        return $columns;
    }
    $new = array();
    foreach ($columns as $k => $v) {
        $new[$k] = $v;
        if ($k === 'kfl_status') {
            $new['kfl_probe'] = __('探活', 'kratos');
        }
    }
    if (!isset($new['kfl_probe'])) {
        $new['kfl_probe'] = __('探活', 'kratos');
    }
    return $new;
}
add_filter('manage_link-manager_columns', 'kratos_friend_probe_admin_column', 11);

function kratos_friend_probe_admin_column_render($column, $link_id)
{
    if ($column !== 'kfl_probe') return;

    $data = kratos_friend_probe_get_all();
    $link_id = (int) $link_id;

    if (!isset($data[$link_id])) {
        echo '<span style="color:#8b919a;">' . esc_html__('未检测', 'kratos') . '</span>';
    } else {
        $entry = $data[$link_id];
        $is_ok = $entry['status'] === 'reachable';
        $color = $is_ok ? '#1a7f37' : '#a4111e';
        $label = $is_ok ? __('可达', 'kratos') : __('不可达', 'kratos');
        $dot   = $is_ok ? '🟢' : '🔴';
        echo '<span style="color:' . esc_attr($color) . ';font-weight:600;">' . $dot . ' ' . esc_html($label) . '</span>';

        if (!empty($entry['checked_at'])) {
            $time_str = human_time_diff((int) $entry['checked_at'], time()) . __('前', 'kratos');
            $full_time = wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $entry['checked_at']);
            echo '<br><span style="color:#8b919a;font-size:12px;" title="' . esc_attr($full_time) . '">' . esc_html($time_str) . '</span>';
        }
    }

    // 「立即检测」行操作
    $probe_url = wp_nonce_url(
        add_query_arg(array('action' => 'kratos_friend_probe_single', 'link_id' => $link_id), admin_url('admin-post.php')),
        'kratos_friend_probe_' . $link_id
    );
    echo '<div class="row-actions" style="visibility:visible;position:static;padding-top:2px;">';
    echo '<span><a href="' . esc_url($probe_url) . '">' . esc_html__('立即检测', 'kratos') . '</a></span>';
    echo '</div>';
}
add_action('manage_link_custom_column', 'kratos_friend_probe_admin_column_render', 11, 2);

/**
 * 处理后台单条探测请求
 */
function kratos_friend_probe_admin_handle()
{
    $link_id = isset($_GET['link_id']) ? (int) $_GET['link_id'] : 0;
    if (!$link_id || !current_user_can('manage_links')) {
        wp_die(__('权限不足', 'kratos'));
    }
    check_admin_referer('kratos_friend_probe_' . $link_id);

    kratos_friend_probe_single($link_id);

    wp_safe_redirect(wp_get_referer() ?: admin_url('link-manager.php'));
    exit;
}
add_action('admin_post_kratos_friend_probe_single', 'kratos_friend_probe_admin_handle');
