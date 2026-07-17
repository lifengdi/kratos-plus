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
    $cache_key = 'kratos_yr_' . $year;

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
        foreach (array_slice($post_rows, 0, 3) as $r) {
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
                $years = array();
                for ($y = (int) current_time('Y'); $y >= (int) current_time('Y') - 5; $y--) $years[] = $y;
                foreach ($years as $y) {
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
                    <h3 class="kyr-h"><?php esc_html_e('年度热文 TOP 3', 'kratos'); ?></h3>
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

            <footer class="kyr-footer">
                <span class="kyr-site-url"><?php echo esc_html(preg_replace('#^https?://#', '', rtrim($site_url, '/'))); ?></span>
                <span class="kyr-generated"><?php echo esc_html(date_i18n(get_option('date_format'), current_time('timestamp'))); ?></span>
            </footer>
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
        .kratos-yr-wrap{max-width:760px;margin:0 auto;}
        .kyr-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;}
        .kyr-download{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;font-size:13px;background:linear-gradient(135deg,#c46a2b,#e8823b);color:#fff !important;border:none;border-radius:999px;cursor:pointer;box-shadow:0 4px 14px rgba(196,106,43,.35);transition:transform .2s ease,box-shadow .2s ease;}
        .kyr-download:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(196,106,43,.45);}
        .kyr-year-switch{display:inline-flex;gap:4px;background:rgba(0,0,0,.04);padding:4px;border-radius:999px;}
        .kyr-year{padding:4px 12px;font-size:12px;color:#666 !important;text-decoration:none !important;border-radius:999px;}
        .kyr-year.is-active{background:#fff;color:#c46a2b !important;box-shadow:0 1px 3px rgba(0,0,0,.08);font-weight:600;}

        .kratos-yearly-review{background:linear-gradient(180deg,#fffaf3 0%,#fff5e6 100%);padding:36px 32px;border-radius:12px;color:#2c2320;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB",sans-serif;}
        .kyr-brand{display:flex;align-items:center;gap:14px;margin-bottom:24px;}
        .kyr-avatar{width:52px;height:52px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.1);}
        .kyr-blog{font-size:13px;color:#8a5a2b;letter-spacing:1px;}
        .kyr-title{font-size:24px;font-weight:700;color:#2c2320;line-height:1.3;}

        .kyr-stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
        .kyr-stat{background:#fff;border-radius:10px;padding:16px 8px;text-align:center;box-shadow:0 2px 8px rgba(196,106,43,.06);}
        .kyr-stat-num{font-size:22px;font-weight:800;color:#c46a2b;line-height:1;}
        .kyr-stat-label{margin-top:6px;font-size:11px;color:#8a6a5a;letter-spacing:.5px;}

        .kyr-birthday{background:rgba(196,106,43,.08);padding:12px 16px;border-radius:8px;text-align:center;font-size:13px;color:#8a5a2b;margin-bottom:20px;}
        .kyr-birthday strong{color:#c46a2b;font-size:16px;}

        .kyr-section{background:#fff;border-radius:10px;padding:18px 20px;margin-bottom:14px;box-shadow:0 2px 8px rgba(196,106,43,.05);}
        .kyr-h{margin:0 0 14px;font-size:14px;font-weight:700;color:#2c2320;letter-spacing:1px;display:flex;align-items:center;gap:8px;}
        .kyr-h::before{content:"";display:inline-block;width:3px;height:14px;background:#c46a2b;border-radius:2px;}

        .kyr-monthly{display:grid;grid-template-columns:repeat(12,1fr);gap:4px;align-items:end;height:110px;}
        .kyr-mo{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;position:relative;}
        .kyr-mo-bar{display:block;width:100%;max-width:32px;background:linear-gradient(180deg,#e8823b,#c46a2b);border-radius:3px 3px 0 0;min-height:4px;}
        .kyr-mo-c{position:absolute;top:-2px;font-size:9px;color:#c46a2b;font-weight:700;transform:translateY(-100%);}
        .kyr-mo-m{margin-top:4px;font-size:10px;color:#8a6a5a;}

        .kyr-top-posts{list-style:none;margin:0;padding:0;counter-reset:none;}
        .kyr-top-posts li{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px dashed rgba(196,106,43,.15);font-size:13px;}
        .kyr-top-posts li:last-child{border-bottom:none;}
        .kyr-rank{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:linear-gradient(135deg,#f4c37a,#c46a2b);color:#fff;border-radius:6px;font-size:11px;font-weight:800;}
        .kyr-tp-title{flex:1;color:#2c2320;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .kyr-tp-c{flex-shrink:0;font-size:11px;color:#8a6a5a;}

        .kyr-commenters{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;}
        .kyr-commenter{text-align:center;}
        .kyr-c-avatar{width:44px;height:44px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,.06);}
        .kyr-c-name{margin-top:6px;font-size:11px;color:#2c2320;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .kyr-c-num{font-size:11px;color:#c46a2b;font-weight:700;}

        .kyr-tags{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:baseline;}
        .kyr-tag{color:#c46a2b;font-weight:600;}

        .kyr-message{background:linear-gradient(135deg,#fff5e6,#ffe6c8);padding:24px;border-radius:10px;text-align:center;position:relative;margin-bottom:14px;}
        .kyr-quote{position:absolute;top:8px;left:16px;font-size:36px;color:rgba(196,106,43,.25);font-family:Georgia,serif;line-height:1;}
        .kyr-message p{margin:0;font-size:14px;line-height:1.8;color:#2c2320;font-style:italic;}

        .kyr-footer{display:flex;justify-content:space-between;font-size:11px;color:#8a6a5a;margin-top:8px;padding:0 4px;}

        @media (max-width:576px){
            .kratos-yearly-review{padding:24px 18px;}
            .kyr-title{font-size:20px;}
            .kyr-stat-row{grid-template-columns:repeat(2,1fr);}
            .kyr-commenters{grid-template-columns:repeat(5,1fr);}
            .kyr-c-avatar{width:36px;height:36px;}
        }
    </style>
    <script>
        (function(){
            if (window.kratosYrBound) return;
            window.kratosYrBound = true;
            function bind(){
                var btn = document.getElementById('kyr-download-btn');
                var node = document.getElementById('kratos-yearly-review');
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

    wp_enqueue_script('html2canvas', 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js', array(), '1.4.1', true);
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
    if (!is_home() && !is_front_page()) return;
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
    <div style="background:linear-gradient(135deg,#c46a2b,#e8823b);color:#fff;text-align:center;padding:10px 16px;font-size:14px;">
        🎂 <?php printf(esc_html__('今天是本博客 %d 岁生日 →', 'kratos'), $age_years); ?>
        <a href="<?php echo esc_url($link); ?>" style="color:#fff !important;text-decoration:underline;margin-left:6px;font-weight:600;"><?php esc_html_e('查看专属长图', 'kratos'); ?></a>
    </div>
    <?php
}
add_action('wp_body_open', 'kratos_yr_birthday_hint');
