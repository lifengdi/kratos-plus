<?php

/**
 * 年度回顾 / 博客生日长图
 *
 * - page-yearly-review.php 页面模板 + [yearly_review] 短代码
 * - 通过 URL 参数 ?yr_year=2026 指定回顾年份，默认取上一年（1 月 1 日 ~ 1 月 15 日仍可翻到刚过去的一年）
 * - html2canvas 前端导出为 PNG 长图
 * - 若主题选项设置了「建站日期」，命中生日当天首页顶部弹出提示条
 *
 * 数据聚合结果 transient 缓存 6 小时（当年）/ 30 天（历史年份）
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 聚合指定年份的博客数据
 *
 * @param int $year
 * @return array
 */
function kratos_yr_aggregate($year)
{
    $year = (int) $year;
    if ($year < 1970 || $year > 2999) $year = (int) current_time('Y');

    $current_year = (int) current_time('Y');
    $ttl = ($year >= $current_year) ? (6 * HOUR_IN_SECONDS) : (30 * DAY_IN_SECONDS);
    $cache_key = 'kratos_yr_v2_' . $year;

    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached['_ok'])) {
        return $cached;
    }

    global $wpdb;
    $start = "$year-01-01 00:00:00";
    $end   = "$year-12-31 23:59:59";

    // 本年文章
    $post_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_content, post_date, comment_count
         FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status = 'publish'
           AND post_date BETWEEN %s AND %s
         ORDER BY post_date ASC",
        $start,
        $end
    ), ARRAY_A);
    $posts_count = is_array($post_rows) ? count($post_rows) : 0;

    // 本年说说
    $shuoshuo_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = 'shuoshuo' AND post_status = 'publish'
           AND post_date BETWEEN %s AND %s",
        $start,
        $end
    ));

    // 字数（按标签剥离后 mb_strlen）+ 找最长文章
    $total_words = 0;
    $longest = null;
    $daily = array_fill(0, 366, 0); // day-of-year → count
    foreach ((array) $post_rows as $r) {
        $plain = trim(wp_strip_all_tags(strip_shortcodes($r['post_content'])));
        $len = mb_strlen($plain, 'UTF-8');
        $total_words += $len;
        if (!$longest || $len > $longest['words']) {
            $longest = array(
                'id'    => (int) $r['ID'],
                'title' => (string) $r['post_title'],
                'words' => $len,
                'link'  => get_permalink((int) $r['ID']),
            );
        }
        $doy = (int) date('z', strtotime($r['post_date']));
        if (isset($daily[$doy])) $daily[$doy]++;
    }

    // 本年评论数
    $comments_received = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->comments} c
         INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
         WHERE c.comment_approved = '1'
           AND c.comment_date BETWEEN %s AND %s
           AND p.post_type IN ('post','shuoshuo')",
        $start,
        $end
    ));

    // Top 3 评论最多的文章（本年发布 的）
    $top_posts = array();
    if (!empty($post_rows)) {
        usort($post_rows, function ($a, $b) {
            return ((int) $b['comment_count']) <=> ((int) $a['comment_count']);
        });
        foreach (array_slice($post_rows, 0, 10) as $r) {
            $top_posts[] = array(
                'id'       => (int) $r['ID'],
                'title'    => (string) $r['post_title'],
                'link'     => get_permalink((int) $r['ID']),
                'comments' => (int) $r['comment_count'],
            );
        }
    }

    // 年度 Top 5 评论者（按本年评论数）
    $top_commenters = $wpdb->get_results($wpdb->prepare(
        "SELECT comment_author, comment_author_email, COUNT(*) AS cnt
         FROM {$wpdb->comments}
         WHERE comment_approved = '1'
           AND comment_type IN ('', 'comment')
           AND comment_date BETWEEN %s AND %s
           AND comment_author_email != %s
         GROUP BY comment_author_email
         ORDER BY cnt DESC
         LIMIT 5",
        $start,
        $end,
        get_option('admin_email')
    ), ARRAY_A);
    $top_commenters_out = array();
    foreach ((array) $top_commenters as $c) {
        $top_commenters_out[] = array(
            'name'   => (string) $c['comment_author'],
            'count'  => (int) $c['cnt'],
            'avatar' => get_avatar_url($c['comment_author_email'], array('size' => 96)),
        );
    }

    // 年度标签 top 10（按本年发布文章的 term 出现次数）
    $tag_rows = array();
    if ($posts_count > 0) {
        $tag_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.name, COUNT(*) AS cnt
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'post_tag'
               AND p.post_type = 'post' AND p.post_status = 'publish'
               AND p.post_date BETWEEN %s AND %s
             GROUP BY t.term_id
             ORDER BY cnt DESC
             LIMIT 10",
            $start,
            $end
        ), ARRAY_A);
    }
    $top_tags = array();
    foreach ((array) $tag_rows as $r) {
        $top_tags[] = array('name' => (string) $r['name'], 'count' => (int) $r['cnt']);
    }

    // 月度写作分布（1-12）
    $monthly = array_fill(1, 12, 0);
    foreach ((array) $post_rows as $r) {
        $m = (int) date('n', strtotime($r['post_date']));
        if (isset($monthly[$m])) $monthly[$m]++;
    }

    // 建站日期 & 陪伴天数
    $birthday = (string) kratos_option('site_birthday', '');
    $birthday_ts = $birthday ? strtotime($birthday) : 0;
    $days_since_birth = ($birthday_ts && $birthday_ts > 0)
        ? max(1, (int) floor((current_time('timestamp') - $birthday_ts) / DAY_IN_SECONDS))
        : 0;

    $data = array(
        '_ok'               => true,
        'year'              => $year,
        'posts_count'       => $posts_count,
        'shuoshuo_count'    => $shuoshuo_count,
        'total_words'       => (int) $total_words,
        'comments_received' => $comments_received,
        'longest'           => $longest,
        'top_posts'         => $top_posts,
        'top_commenters'    => $top_commenters_out,
        'top_tags'          => $top_tags,
        'monthly'           => $monthly,
        'days_since_birth'  => $days_since_birth,
        'birthday'          => $birthday,
    );
    set_transient($cache_key, $data, $ttl);
    return $data;
}

/**
 * 渲染长图内容（供页面模板 / 短代码复用）
 */
function kratos_yr_render($year = null)
{
    if ($year === null) {
        $year = isset($_GET['yr_year']) ? (int) $_GET['yr_year'] : (int) current_time('Y');
        // 默认取上一年，除非当前年份已经过半
        if (!isset($_GET['yr_year'])) {
            $now_month = (int) current_time('n');
            if ($now_month < 4) $year = (int) current_time('Y') - 1;
        }
    }
    $year = (int) $year;

    $data = kratos_yr_aggregate($year);
    $site_name = get_bloginfo('name');
    $site_url  = home_url('/');

    // 分享二维码指向的目标地址：优先取 page-yearly-review.php 模板页面，附带 yr_year 年份参数；
    // 找不到模板页时回退到首页 + 年份参数
    $share_base = '';
    $yr_pages = get_posts(array(
        'post_type'      => 'page',
        'posts_per_page' => 1,
        'meta_key'       => '_wp_page_template',
        'meta_value'     => 'page-yearly-review.php',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));
    if (!empty($yr_pages)) {
        $share_base = get_permalink($yr_pages[0]);
    } else {
        $share_base = $site_url;
    }
    $share_url = esc_url(add_query_arg('yr_year', (int) $year, $share_base));
    $author_name = (string) kratos_option('g_signature', $site_name);
    $author_avatar = get_avatar_url(get_option('admin_email'), array('size' => 160));
    $message = trim((string) kratos_option('yr_message', __('感谢每一位读者的陪伴，我们下一年见 🥂', 'kratos')));

    // 月度分布图 —— 归一化到 0-100 高度
    $max_m = 0;
    foreach ($data['monthly'] as $c) if ($c > $max_m) $max_m = $c;
    if ($max_m < 1) $max_m = 1;

    ob_start(); ?>
    <div class="kratos-yr-wrap">
        <div class="kyr-actions">
            <button type="button" class="kyr-download" id="kyr-download-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?php esc_html_e('下载长图', 'kratos'); ?>
            </button>
            <div class="kyr-year-switch">
                <?php
                $cur_year = (int) current_time('Y');
                $birthday = (string) kratos_option('site_birthday', '');
                $bd_ts    = $birthday ? strtotime($birthday) : 0;
                $start_year = ($bd_ts > 0) ? (int) date('Y', $bd_ts) : ($cur_year - 5);
                if ($start_year > $cur_year) $start_year = $cur_year;
                $max_n = (int) apply_filters('kratos_yr_year_switch_max', 8);
                if ($max_n < 1) $max_n = 1;
                if (($cur_year - $start_year + 1) > $max_n) $start_year = $cur_year - $max_n + 1;
                for ($y = $cur_year; $y >= $start_year; $y--) {
                    $url = esc_url(add_query_arg('yr_year', $y));
                    $active = $y === $year ? ' is-active' : '';
                    echo '<a class="kyr-year' . $active . '" href="' . $url . '">' . (int) $y . '</a>';
                }
                ?>
            </div>
        </div>

        <div class="kratos-yearly-review" id="kratos-yearly-review">
            <div class="kyr-brand">
                <img class="kyr-avatar" src="<?php echo esc_url($author_avatar); ?>" alt="" crossorigin="anonymous">
                <div class="kyr-brand-text">
                    <div class="kyr-blog"><?php echo esc_html($site_name); ?></div>
                    <div class="kyr-title"><?php echo (int) $year; ?> · <?php esc_html_e('年度回顾', 'kratos'); ?></div>
                </div>
            </div>

            <div class="kyr-stat-row">
                <div class="kyr-stat">
                    <div class="kyr-stat-num"><?php echo (int) $data['posts_count']; ?></div>
                    <div class="kyr-stat-label"><?php esc_html_e('本年文章', 'kratos'); ?></div>
                </div>
                <div class="kyr-stat">
                    <div class="kyr-stat-num"><?php echo number_format_i18n(round($data['total_words'] / 1000, 1), 1); ?>k</div>
                    <div class="kyr-stat-label"><?php esc_html_e('总字数', 'kratos'); ?></div>
                </div>
                <div class="kyr-stat">
                    <div class="kyr-stat-num"><?php echo (int) $data['shuoshuo_count']; ?></div>
                    <div class="kyr-stat-label"><?php esc_html_e('说说', 'kratos'); ?></div>
                </div>
                <div class="kyr-stat">
                    <div class="kyr-stat-num"><?php echo (int) $data['comments_received']; ?></div>
                    <div class="kyr-stat-label"><?php esc_html_e('收到评论', 'kratos'); ?></div>
                </div>
            </div>

            <?php if ($data['days_since_birth'] > 0) { ?>
                <div class="kyr-birthday">
                    <?php printf(
                        esc_html__('本博客已经陪伴你 %s 天', 'kratos'),
                        '<strong>' . number_format_i18n($data['days_since_birth']) . '</strong>'
                    ); ?>
                </div>
            <?php } ?>

            <section class="kyr-section">
                <h3 class="kyr-h"><?php esc_html_e('月度写作节奏', 'kratos'); ?></h3>
                <div class="kyr-monthly">
                    <?php for ($m = 1; $m <= 12; $m++) {
                        $c = (int) $data['monthly'][$m];
                        $h = max(4, (int) round($c / $max_m * 100));
                    ?>
                        <div class="kyr-mo" title="<?php echo esc_attr($m . ' 月 · ' . $c . ' 篇'); ?>">
                            <span class="kyr-mo-bar" style="height:<?php echo (int) $h; ?>%;"></span>
                            <span class="kyr-mo-c"><?php echo $c > 0 ? (int) $c : ''; ?></span>
                            <span class="kyr-mo-m"><?php echo (int) $m; ?></span>
                        </div>
                    <?php } ?>
                </div>
            </section>

            <?php if (!empty($data['top_posts'])) { ?>
                <section class="kyr-section">
                    <h3 class="kyr-h"><?php esc_html_e('年度热文 TOP 10', 'kratos'); ?></h3>
                    <ol class="kyr-top-posts">
                        <?php foreach ($data['top_posts'] as $i => $p) { ?>
                            <li>
                                <span class="kyr-rank">#<?php echo (int) ($i + 1); ?></span>
                                <span class="kyr-tp-title"><?php echo esc_html($p['title']); ?></span>
                                <span class="kyr-tp-c"><?php echo (int) $p['comments']; ?> <?php esc_html_e('评论', 'kratos'); ?></span>
                            </li>
                        <?php } ?>
                    </ol>
                </section>
            <?php } ?>

            <?php if (!empty($data['top_commenters'])) { ?>
                <section class="kyr-section">
                    <h3 class="kyr-h"><?php esc_html_e('年度知己 TOP 5', 'kratos'); ?></h3>
                    <div class="kyr-commenters">
                        <?php foreach ($data['top_commenters'] as $c) { ?>
                            <div class="kyr-commenter">
                                <img class="kyr-c-avatar" src="<?php echo esc_url($c['avatar']); ?>" alt="" crossorigin="anonymous">
                                <div class="kyr-c-name"><?php echo esc_html($c['name']); ?></div>
                                <div class="kyr-c-num"><?php echo (int) $c['count']; ?></div>
                            </div>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>

            <?php if (!empty($data['top_tags'])) { ?>
                <section class="kyr-section">
                    <h3 class="kyr-h"><?php esc_html_e('年度关键词', 'kratos'); ?></h3>
                    <div class="kyr-tags">
                        <?php $i = 0; foreach ($data['top_tags'] as $t) {
                            $size = max(12, 22 - $i * 1.2);
                            $i++;
                        ?>
                            <span class="kyr-tag" style="font-size:<?php echo (float) $size; ?>px;"><?php echo esc_html($t['name']); ?></span>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>

            <?php if ($message !== '') { ?>
                <section class="kyr-message">
                    <div class="kyr-quote">"</div>
                    <p><?php echo esc_html($message); ?></p>
                </section>
            <?php } ?>

            <section class="kyr-share">
                <div class="kyr-qr" id="kyr-qr" data-url="<?php echo esc_attr($share_url); ?>"></div>
                <div class="kyr-share-text">
                    <div class="kyr-share-title"><?php esc_html_e('扫码查看本页', 'kratos'); ?></div>
                    <div class="kyr-share-sub"><?php esc_html_e('分享给朋友，一起回顾这一年', 'kratos'); ?></div>
                    <div class="kyr-share-meta">
                        <span class="kyr-site-url"><?php echo esc_html(preg_replace('#^https?://#', '', rtrim($site_url, '/'))); ?></span>
                        <span class="kyr-generated"><?php echo esc_html(date_i18n(get_option('date_format'), current_time('timestamp'))); ?></span>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <?php echo kratos_yr_inline_assets($year);
    return ob_get_clean();
}

function kratos_yr_shortcode($atts)
{
    $atts = shortcode_atts(array('year' => ''), $atts, 'yearly_review');
    $year = $atts['year'] !== '' ? (int) $atts['year'] : null;
    return kratos_yr_render($year);
}
add_shortcode('yearly_review', 'kratos_yr_shortcode');

function kratos_yr_inline_assets($year)
{
    static $printed = false;
    if ($printed) return '';
    $printed = true;
    ob_start(); ?>
    <style>
        /* 颜色令牌：默认走「羊皮」外观（皮肤关闭态），皮肤开启时自动吃 --kr-skin-* 跟随皮肤配色 */
        .kratos-yr-wrap{
            --kyr-accent:   var(--kr-skin-accent, #c46a2b);
            --kyr-accent-2: var(--kr-skin-accent-hover, #e8823b);
            --kyr-text:     var(--kr-skin-text, #2c2320);
            --kyr-heading:  var(--kr-skin-heading, #2c2320);
            --kyr-muted:    var(--kr-skin-muted, #8a6a5a);
            --kyr-card:     var(--kr-skin-card-bg, #fff);
            --kyr-line:     var(--kr-skin-card-line, rgba(196,106,43,.15));
            --kyr-bg:       var(--kr-skin-card-bg, linear-gradient(180deg,#fffdf9 0%,#fff9f0 100%));
            max-width:760px;margin:0 auto;
        }
        .kyr-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;}
        .kyr-download{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;font-size:13px;background:linear-gradient(135deg,var(--kyr-accent),var(--kyr-accent-2));color:#fff !important;border:none;border-radius:999px;cursor:pointer;box-shadow:0 4px 14px var(--kyr-accent-2);transition:transform .2s ease,box-shadow .2s ease;}
        .kyr-download:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(196,106,43,.45);}
        .kyr-year-switch{display:inline-flex;gap:4px;background:rgba(0,0,0,.04);padding:4px;border-radius:999px;}
        .kyr-year{padding:4px 12px;font-size:12px;color:var(--kyr-muted) !important;text-decoration:none !important;border-radius:999px;}
        .kyr-year.is-active{background:var(--kyr-card);color:var(--kyr-accent) !important;box-shadow:0 1px 3px rgba(0,0,0,.08);font-weight:600;}

        .kratos-yearly-review{background:var(--kyr-bg);padding:36px 32px;border-radius:12px;color:var(--kyr-text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB",sans-serif;}
        .kyr-brand{display:flex;align-items:center;gap:14px;margin-bottom:24px;}
        .kyr-avatar{width:52px;height:52px;border-radius:50%;border:2px solid var(--kyr-card);box-shadow:0 2px 8px rgba(0,0,0,.1);}
        .kyr-blog{font-size:13px;color:var(--kyr-accent);letter-spacing:1px;}
        .kyr-title{font-size:24px;font-weight:700;color:var(--kyr-heading);line-height:1.3;}

        .kyr-stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
        .kyr-stat{background:var(--kyr-card);border:1px solid var(--kyr-line);border-radius:10px;padding:16px 8px;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.06);}
        .kyr-stat-num{font-size:22px;font-weight:800;color:var(--kyr-accent);line-height:1;}
        .kyr-stat-label{margin-top:6px;font-size:11px;color:var(--kyr-muted);letter-spacing:.5px;}

        .kyr-birthday{background:var(--kyr-card);border:1px solid var(--kyr-line);padding:12px 16px;border-radius:8px;text-align:center;font-size:13px;color:var(--kyr-accent);box-shadow:0 2px 10px rgba(0,0,0,.06);margin-bottom:20px;}
        .kyr-birthday strong{color:var(--kyr-accent);font-size:16px;}

        .kyr-section{background:var(--kyr-card);border:1px solid var(--kyr-line);border-radius:10px;padding:18px 20px;margin-bottom:14px;box-shadow:0 2px 10px rgba(0,0,0,.05);}
        .kyr-h{margin:0 0 14px;font-size:14px;font-weight:700;color:var(--kyr-heading);letter-spacing:1px;display:flex;align-items:center;gap:8px;}
        .kyr-h::before{content:"";display:inline-block;width:3px;height:14px;background:var(--kyr-accent);border-radius:2px;}

        .kyr-monthly{display:grid;grid-template-columns:repeat(12,1fr);gap:4px;align-items:end;height:110px;}
        .kyr-mo{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;position:relative;}
        .kyr-mo-bar{display:block;width:100%;max-width:32px;background:linear-gradient(180deg,var(--kyr-accent-2),var(--kyr-accent));border-radius:3px 3px 0 0;min-height:4px;}
        .kyr-mo-c{position:absolute;top:-2px;font-size:9px;color:var(--kyr-accent);font-weight:700;transform:translateY(-100%);}
        .kyr-mo-m{margin-top:4px;font-size:10px;color:var(--kyr-muted);}

        .kyr-top-posts{list-style:none;margin:0;padding:0;counter-reset:none;}
        .kyr-top-posts li{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px dashed var(--kyr-line);font-size:13px;}
        .kyr-top-posts li:last-child{border-bottom:none;}
        .kyr-rank{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:linear-gradient(135deg,var(--kyr-accent-2),var(--kyr-accent));color:#fff;border-radius:6px;font-size:11px;font-weight:800;}
        .kyr-tp-title{flex:1;color:var(--kyr-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .kyr-tp-c{flex-shrink:0;font-size:11px;color:var(--kyr-muted);}

        .kyr-commenters{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;}
        .kyr-commenter{text-align:center;}
        .kyr-c-avatar{width:44px;height:44px;border-radius:50%;border:2px solid var(--kyr-card);box-shadow:0 2px 4px rgba(0,0,0,.06);}
        .kyr-c-name{margin-top:6px;font-size:11px;color:var(--kyr-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .kyr-c-num{font-size:11px;color:var(--kyr-accent);font-weight:700;}

        .kyr-tags{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:baseline;}
        .kyr-tag{color:var(--kyr-accent);font-weight:600;}

        .kyr-message{background:var(--kr-skin-quote-bg, linear-gradient(135deg,#fff5e6,#ffe6c8));padding:24px;border-radius:10px;text-align:center;position:relative;margin-bottom:14px;}
        .kyr-quote{position:absolute;top:8px;left:16px;font-size:36px;var(--kyr-text);font-family:Georgia,serif;line-height:1;}
        .kyr-message p{margin:0;font-size:14px;line-height:1.8;color:var(--kyr-text);font-style:italic;}

        .kyr-share{display:flex;align-items:flex-start;gap:16px;background:var(--kyr-card);border:1px solid var(--kyr-line);border-radius:10px;padding:16px 20px;margin-bottom:14px;box-shadow:0 2px 10px rgba(0,0,0,.05);}
        .kyr-qr{flex-shrink:0;width:88px;height:88px;background:var(--kyr-card);border:1px solid var(--kyr-line);border-radius:8px;padding:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);display:flex;align-items:center;justify-content:center;}
        .kyr-qr img,.kyr-qr canvas{width:100%;height:100%;display:block;}
        .kyr-share-text{flex:1;min-width:0;}
        .kyr-share-title{font-size:14px;font-weight:700;color:var(--kyr-heading);}
        .kyr-share-sub{margin-top:6px;font-size:12px;color:var(--kyr-muted);line-height:1.6;}
        .kyr-share-meta{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-top:12px;padding-top:10px;border-top:1px dashed var(--kyr-line);font-size:11px;color:var(--kyr-accent);}
        .kyr-share-meta .kyr-site-url{flex:1;min-width:0;word-break:break-all;line-height:1.5;}
        .kyr-share-meta .kyr-generated{flex-shrink:0;white-space:nowrap;color:var(--kyr-muted);}

        @media (max-width:576px){
            .kratos-yearly-review{padding:24px 18px;}
            .kyr-title{font-size:20px;}
            .kyr-stat-row{grid-template-columns:repeat(2,1fr);}
            .kyr-commenters{grid-template-columns:repeat(5,1fr);}
            .kyr-c-avatar{width:36px;height:36px;}
            .kyr-share{flex-direction:column;align-items:center;text-align:center;gap:12px;}
            .kyr-share-meta{flex-direction:column;align-items:center;text-align:center;gap:3px;}
        }
    </style>
    <script>
        (function(){
            if (window.kratosYrBound) return;
            window.kratosYrBound = true;
            function renderQr(){
                var box = document.getElementById('kyr-qr');
                if (!box || box.dataset.done) return;
                if (typeof QRCode !== 'function') { setTimeout(renderQr, 200); return; }
                box.dataset.done = '1';
                try {
                    new QRCode(box, {
                        text: box.getAttribute('data-url'),
                        width: 160,
                        height: 160,
                        colorDark: '#2c2320',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } catch (e) {
                    box.dataset.done = '';
                    console.error('QR generate failed:', e);
                }
                // 注意：不要手动隐藏 canvas —— qrcodejs 会异步把 canvas 换成 img 并自行处理显隐，
                // 手动同步隐藏 canvas 会导致部分移动端浏览器在异步 img 就绪前一片空白。
            }
            function bind(){
                var btn = document.getElementById('kyr-download-btn');
                var node = document.getElementById('kratos-yearly-review');
                renderQr();
                if (!btn || !node) return;
                btn.addEventListener('click', function(){
                    if (typeof html2canvas !== 'function') {
                        alert('<?php echo esc_js(__('图片生成库尚未加载，请稍候…', 'kratos')); ?>');
                        return;
                    }
                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js(__('生成中…', 'kratos')); ?>';
                    html2canvas(node, {scale: 2, useCORS: true, backgroundColor: null, logging: false}).then(function(canvas){
                        var link = document.createElement('a');
                        link.download = '<?php echo esc_js(get_bloginfo('name') . '-review-' . $year); ?>.png';
                        link.href = canvas.toDataURL('image/png');
                        link.click();
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js(__('下载长图', 'kratos')); ?>';
                    }).catch(function(err){
                        console.error(err);
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js(__('下载长图', 'kratos')); ?>';
                        alert('<?php echo esc_js(__('生成失败，请检查控制台。', 'kratos')); ?>');
                    });
                });
            }
            if (document.readyState !== 'loading') bind();
            else document.addEventListener('DOMContentLoaded', bind);
        })();
    </script>
    <?php return ob_get_clean();
}

/**
 * 页面模板命中时加载 html2canvas
 */
function kratos_yr_enqueue()
{
    $need = false;
    if (is_page() && function_exists('is_page_template') && is_page_template('page-yearly-review.php')) {
        $need = true;
    }
    global $post;
    if (!$need && is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'yearly_review')) {
        $need = true;
    }
    if (!$need) return;

    wp_enqueue_script('html2canvas', get_template_directory_uri() . '/assets/js/html2canvas.min.js', array(), '1.4.1', true);
    wp_enqueue_script('qrcodejs', get_template_directory_uri() . '/assets/js/qrcode.min.js', array(), '1.0.0', true);
}
add_action('wp_enqueue_scripts', 'kratos_yr_enqueue');

/**
 * Body class
 */
function kratos_yr_body_class($classes)
{
    if (is_page() && function_exists('is_page_template') && is_page_template('page-yearly-review.php')) {
        $classes[] = 'is-kratos-yearly-review-page';
    }
    return $classes;
}
add_filter('body_class', 'kratos_yr_body_class');

/**
 * 生日当天首页顶部小提示
 */
function kratos_yr_birthday_hint()
{
//     if (!is_home() && !is_front_page()) return;
    if (!kratos_option('yr_birthday_hint', false)) return;
    $birthday = (string) kratos_option('site_birthday', '');
    if (!$birthday) return;
    $ts = strtotime($birthday);
    if (!$ts) return;

    $today_md = current_time('m-d');
    $bd_md = date('m-d', $ts);
    if ($today_md !== $bd_md) return;

    $age_years = (int) current_time('Y') - (int) date('Y', $ts);
    if ($age_years < 1) return;

    // 找一个 page-yearly-review.php 模板的页面
    $link = '?yr_year=' . (int) current_time('Y');
    $pages = get_posts(array(
        'post_type' => 'page', 'posts_per_page' => 1,
        'meta_key' => '_wp_page_template', 'meta_value' => 'page-yearly-review.php',
        'fields' => 'ids', 'no_found_rows' => true,
    ));
    if (!empty($pages)) $link = get_permalink($pages[0]);
    ?>
    <div style="background:linear-gradient(130deg, var(--kr-skin-tag-bg, rgba(0, 0, 0, .35)), transparent);text-align:center;padding:10px 16px;font-size:14px;">
        🎂 <?php printf(esc_html__('今天是本博客 %d 岁生日 ', 'kratos'), $age_years); ?>
        <a href="<?php echo esc_url($link); ?>" style="color:var(--kr-skin-link);text-decoration:underline;margin-left:6px;font-weight:600;"><?php esc_html_e('查看专属长图', 'kratos'); ?></a>
    </div>
    <?php
}
add_action('wp_body_open', 'kratos_yr_birthday_hint');
