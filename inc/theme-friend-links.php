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
            <header class="kfl-header">
                <?php if ($title !== '') { ?>
                    <span class="kfl-title-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 1 0-7.07-7.07l-1 1"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 1 0 7.07 7.07l1-1"/></svg>
                    </span>
                    <span class="kfl-title"><?php echo esc_html($title); ?></span>
                <?php } ?>
                <?php if ($subtitle !== '') { ?>
                    <?php if ($title !== '') { ?><span class="kfl-header-divider" aria-hidden="true"></span><?php } ?>
                    <p class="kfl-subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php } ?>
            </header>
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
                <section class="kfl-section">
                    <header class="kfl-section-head">
                        <h3 class="kfl-section-title"><?php echo esc_html($term->name); ?></h3>
                        <span class="kfl-section-count"><?php echo (int) count($links); ?></span>
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
                            <a class="kfl-item" href="<?php echo esc_url($url); ?>" target="<?php echo esc_attr($target); ?>" rel="nofollow noopener external" title="<?php echo esc_attr($name . ($desc !== '' ? ' — ' . $desc : '')); ?>">
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

        <?php if ($atts['form'] === '1') {
            $form_intro = (string) kratos_option('g_friend_form_intro', __('填写下方表单提交友链申请，站长审核通过后会自动上线。', 'kratos'));
        ?>
            <section class="kfl-section kfl-form-section" id="kratos-friend-apply">
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

                    <div class="kfl-form-actions">
                        <button type="submit" class="kfl-submit"><?php esc_html_e('提交申请', 'kratos'); ?></button>
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
        }
        .kratos-friend-links .kfl-item{
            display:flex;align-items:center;gap:12px;
            padding:12px;
            background:var(--khs-card-bg);
            border:1px solid var(--khs-line);
            border-radius:10px;
            color:var(--khs-fg-soft) !important;
            text-decoration:none !important;
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

        .kratos-friend-links .kfl-meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;}
        .kratos-friend-links .kfl-name{
            font-size:14px;font-weight:600;color:inherit;
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        }
        .kratos-friend-links .kfl-desc{
            font-size:12px;color:var(--khs-fg-mute);
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        }
        .kratos-friend-links .kfl-item:hover .kfl-desc{color:var(--khs-fg-dim);}

        /* 空状态 */
        .kratos-friend-links .kfl-empty{
            padding:36px 16px;text-align:center;
            color:var(--khs-fg-dim);font-size:14px;
            background:var(--khs-card-bg);
            border:1px dashed var(--khs-line-strong);
            border-radius:12px;margin-bottom:16px;
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
        }

        /* 暗夜：对齐 dark.css，与走心 / 归档同步 */
        html[data-theme="dark"] .kratos-friend-links,body.dark .kratos-friend-links{
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
        'nonce'    => __('会话已过期，请刷新后重试。', 'kratos'),
        'required' => __('请把带 * 的字段填完。', 'kratos'),
        'url'      => __('网站地址不合法，请填写完整的 http(s):// 链接。', 'kratos'),
        'rss'      => __('RSS 地址不合法，请填写完整的 http(s):// 链接或留空。', 'kratos'),
        'image'    => __('Logo 地址不合法，请填写完整的 http(s):// 图片链接或留空。', 'kratos'),
        'hp'       => __('提交被拦截，请稍后再试。', 'kratos'),
        'cooldown' => __('提交过于频繁，请 1 分钟后再试。', 'kratos'),
        'exists'   => __('该网址已经在友链里啦，感谢关注。', 'kratos'),
        'name_len' => __('网站名称最长 120 字符。', 'kratos'),
        'db'       => __('保存失败，请稍后再试或联系站长。', 'kratos'),
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
    if (!is_admin() || empty($_GET['kfl_filter']) || !is_array($bookmarks)) return $bookmarks;
    $want = $_GET['kfl_filter'] === 'pending' ? 'N' : ($_GET['kfl_filter'] === 'approved' ? 'Y' : '');
    if ($want === '') return $bookmarks;
    return array_values(array_filter($bookmarks, function ($b) use ($want) {
        return isset($b->link_visible) && $b->link_visible === $want;
    }));
}
add_filter('get_bookmarks', 'kratos_friend_admin_filter_bookmarks');

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
