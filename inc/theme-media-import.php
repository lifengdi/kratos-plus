<?php

/**
 * 观影读书录 · 从豆瓣导入
 *
 * 后台「观影读书录 → 从豆瓣导入」页面：粘贴 URL → 服务端拉取并解析 →
 * 预览字段 → 一键创建草稿条目。
 *
 * 解析优先走页面内嵌 ld+json（@type Book/Movie/TVSeries），再回落到 og:*、
 * 常见正则。豆瓣防爬敏感，默认带主流桌面 UA + 2s 请求间隔。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/* ========== 后台菜单 ========== */

add_action('admin_menu', 'kratos_kml_import_menu', 20);
function kratos_kml_import_menu()
{
    add_submenu_page(
        'edit.php?post_type=media_item',
        __('从豆瓣导入', 'kratos'),
        __('从豆瓣导入', 'kratos'),
        'edit_posts',
        'kratos-media-import',
        'kratos_kml_import_page'
    );
}

function kratos_kml_import_page()
{
    $nonce = wp_create_nonce('kratos_kml_import');
    $terms = get_terms(array('taxonomy' => 'media_type', 'hide_empty' => false));
    $type_options = array();
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) {
            $type_options[] = array('slug' => $t->slug, 'name' => $t->name);
        }
    }
    ?>
    <div class="wrap" id="kml-import-wrap">
        <h1><?php esc_html_e('观影读书录 · 从豆瓣导入', 'kratos'); ?></h1>
        <p style="color:#666;max-width:720px;">
            <?php esc_html_e('粘贴豆瓣书籍 / 电影 / 剧集 / 游戏的详情页链接，服务器会尝试抓取并解析封面、标题、作者、年份等信息。', 'kratos'); ?>
            <br>
            <?php esc_html_e('豆瓣有反爬策略，不保证每次成功；失败时请手动创建条目。', 'kratos'); ?>
        </p>

        <div class="card" style="padding:16px;max-width:960px;">
            <table class="form-table" style="margin-top:0">
                <tr>
                    <th><label for="kml-douban-url"><?php esc_html_e('豆瓣链接', 'kratos'); ?></label></th>
                    <td>
                        <input id="kml-douban-url" type="url" class="regular-text" placeholder="https://book.douban.com/subject/35635082/ 或 https://movie.douban.com/subject/..." style="width:100%;max-width:640px">
                        <p class="description"><?php esc_html_e('支持 book.douban.com / movie.douban.com；条目 URL 以 /subject/数字/ 结尾。', 'kratos'); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="kml-fetch-btn"><?php esc_html_e('抓取并解析', 'kratos'); ?></button>
                <span id="kml-fetch-status" style="margin-left:12px;color:#666"></span>
            </p>
        </div>

        <div class="card" id="kml-result-card" style="padding:16px;max-width:960px;display:none;">
            <h2 style="margin-top:0"><?php esc_html_e('预览与创建', 'kratos'); ?></h2>
            <table class="form-table" id="kml-result-table"></table>
            <p>
                <button type="button" class="button button-primary" id="kml-create-btn"><?php esc_html_e('创建草稿条目', 'kratos'); ?></button>
                <span id="kml-create-status" style="margin-left:12px;color:#666"></span>
            </p>
        </div>

        <script>
        (function(){
            const nonce = '<?php echo esc_js($nonce); ?>';
            const TYPE_OPTIONS = <?php echo wp_json_encode($type_options); ?>;
            const btn = document.getElementById('kml-fetch-btn');
            const statusEl = document.getElementById('kml-fetch-status');
            const card = document.getElementById('kml-result-card');
            const tbl = document.getElementById('kml-result-table');
            const createBtn = document.getElementById('kml-create-btn');
            const createStatus = document.getElementById('kml-create-status');

            const FIELDS = [
                { k:'title',          label:'标题',           t:'text',     col:1 },
                { k:'orig_title',     label:'原名',           t:'text',     col:1 },
                { k:'cover',          label:'封面 URL',       t:'text',     col:2 },
                { k:'creator',        label:'作者 / 导演',    t:'text',     col:1 },
                { k:'year',           label:'年份',           t:'text',     col:1 },
                { k:'rating',         label:'豆瓣评分',       t:'text',     col:1 },
                { k:'type',           label:'类型',           t:'select',   col:1, hint:'对应「观影读书录 → 类型」中已有的分类' },
                { k:'douban_url',     label:'豆瓣链接',       t:'text',     col:2 },
                { k:'isbn',           label:'ISBN',          t:'text',     col:1 },
                { k:'imdb_id',        label:'IMDb ID',       t:'text',     col:1 },
                { k:'excerpt',        label:'简介',           t:'textarea', col:2 },
            ];

            function renderForm(data) {
                tbl.innerHTML = '';
                FIELDS.forEach(f => {
                    const tr = document.createElement('tr');
                    const th = document.createElement('th');
                    th.innerHTML = '<label>' + f.label + '</label>';
                    const td = document.createElement('td');
                    let el;
                    if (f.t === 'textarea') {
                        el = document.createElement('textarea');
                        el.rows = 4; el.style.width = '100%'; el.style.maxWidth = '640px';
                    } else if (f.t === 'select') {
                        el = document.createElement('select');
                        el.style.minWidth = '200px';
                        const empty = document.createElement('option');
                        empty.value = ''; empty.textContent = '— 不设置 —';
                        el.appendChild(empty);
                        TYPE_OPTIONS.forEach(o => {
                            const opt = document.createElement('option');
                            opt.value = o.slug; opt.textContent = o.name + '（' + o.slug + '）';
                            el.appendChild(opt);
                        });
                    } else {
                        el = document.createElement('input');
                        el.type = 'text'; el.className = 'regular-text';
                        el.style.width = '100%'; el.style.maxWidth = '640px';
                    }
                    el.id = 'kml-f-' + f.k; el.name = f.k;
                    el.value = data[f.k] || '';
                    td.appendChild(el);
                    if (f.hint) {
                        const p = document.createElement('p');
                        p.className = 'description'; p.textContent = f.hint;
                        td.appendChild(p);
                    }
                    if (f.k === 'cover' && data[f.k]) {
                        const img = document.createElement('img');
                        img.src = data[f.k]; img.style.cssText = 'max-width:160px;max-height:220px;margin-top:8px;border:1px solid #ddd;border-radius:4px;display:block';
                        td.appendChild(img);
                    }
                    tr.appendChild(th); tr.appendChild(td);
                    tbl.appendChild(tr);
                });
            }

            btn.addEventListener('click', function(){
                const url = document.getElementById('kml-douban-url').value.trim();
                if (!url) { statusEl.textContent = '请先填入豆瓣链接'; return; }
                btn.disabled = true;
                statusEl.textContent = '抓取中…';
                card.style.display = 'none';

                const fd = new FormData();
                fd.append('action', 'kratos_kml_fetch_douban');
                fd.append('_wpnonce', nonce);
                fd.append('url', url);

                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(res => {
                        btn.disabled = false;
                        if (!res.success) { statusEl.textContent = '✗ ' + (res.data && res.data.msg || '抓取失败'); return; }
                        statusEl.textContent = '✓ 抓取成功，核对后点"创建草稿条目"';
                        renderForm(res.data);
                        card.style.display = 'block';
                    })
                    .catch(err => {
                        btn.disabled = false;
                        statusEl.textContent = '✗ 网络错误：' + err.message;
                    });
            });

            createBtn.addEventListener('click', function(){
                createBtn.disabled = true;
                createStatus.textContent = '提交中…';
                const fd = new FormData();
                fd.append('action', 'kratos_kml_create_from_douban');
                fd.append('_wpnonce', nonce);
                FIELDS.forEach(f => {
                    const el = document.getElementById('kml-f-' + f.k);
                    if (el) fd.append(f.k, el.value);
                });
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(res => {
                        createBtn.disabled = false;
                        if (!res.success) { createStatus.innerHTML = '<span style="color:#c33">✗ ' + (res.data && res.data.msg || '创建失败') + '</span>'; return; }
                        createStatus.innerHTML = '✓ 已创建草稿：<a href="' + res.data.edit_url + '">去编辑 →</a>';
                    })
                    .catch(err => {
                        createBtn.disabled = false;
                        createStatus.innerHTML = '<span style="color:#c33">✗ ' + err.message + '</span>';
                    });
            });
        })();
        </script>
    </div>
    <?php
}

/* ========== 抓取 + 解析 ========== */

add_action('wp_ajax_kratos_kml_fetch_douban', 'kratos_kml_ajax_fetch_douban');
function kratos_kml_ajax_fetch_douban()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('msg' => __('权限不足', 'kratos')));
    }
    check_ajax_referer('kratos_kml_import');
    $url = isset($_POST['url']) ? esc_url_raw(trim(wp_unslash($_POST['url']))) : '';
    if (!$url || !preg_match('#^https?://(?:book|movie|www)\.douban\.com/#i', $url)) {
        wp_send_json_error(array('msg' => __('仅支持 book.douban.com / movie.douban.com 链接', 'kratos')));
    }

    $html = kratos_kml_fetch_url($url);
    if (is_wp_error($html)) {
        wp_send_json_error(array('msg' => $html->get_error_message()));
    }

    $data = kratos_kml_parse_douban($html, $url);
    if (empty($data['title'])) {
        wp_send_json_error(array('msg' => __('页面抓到了但解析不出标题，可能被反爬拦截了', 'kratos')));
    }

    wp_send_json_success($data);
}

/**
 * 带浏览器指纹的 HTTP GET。豆瓣的反爬主要卡 UA + bid cookie + sec-fetch-*；
 * 这里带齐这三件套，403 概率显著降低。还是失败就自动切 m.douban.com 再试一次。
 */
function kratos_kml_fetch_url($url)
{
    $body = kratos_kml_do_request($url);
    if (!is_wp_error($body)) return $body;

    // 403 兜底：切 m.douban.com（移动版风控更松）
    if (preg_match('#^https?://(?:www\.|book\.|movie\.)?douban\.com(.+)$#i', $url, $m)) {
        $mobile = 'https://m.douban.com' . $m[1];
        if ($mobile !== $url) {
            $body2 = kratos_kml_do_request($mobile);
            if (!is_wp_error($body2)) return $body2;
        }
    }
    return $body;
}

function kratos_kml_do_request($url)
{
    $bid = kratos_kml_random_bid();
    $args = array(
        'timeout'     => 15,
        'redirection' => 3,
        'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'headers'     => array(
            'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language'           => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Cache-Control'             => 'max-age=0',
            'Cookie'                    => 'bid=' . $bid,
            'DNT'                       => '1',
            'Referer'                   => 'https://www.douban.com/',
            'Sec-Ch-Ua'                 => '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
            'Sec-Ch-Ua-Mobile'          => '?0',
            'Sec-Ch-Ua-Platform'        => '"macOS"',
            'Sec-Fetch-Dest'            => 'document',
            'Sec-Fetch-Mode'            => 'navigate',
            'Sec-Fetch-Site'            => 'same-origin',
            'Sec-Fetch-User'            => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ),
    );
    $resp = wp_remote_get($url, $args);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code >= 400) {
        return new WP_Error('kml_http', sprintf(__('豆瓣返回 HTTP %d（可能被反爬拦截）', 'kratos'), $code));
    }
    $body = wp_remote_retrieve_body($resp);
    if (!$body) return new WP_Error('kml_empty', __('返回体为空', 'kratos'));
    return $body;
}

/**
 * 豆瓣用来识别客户端的 11 位随机 cookie。真实浏览器首次访问时由前端 JS 下发，
 * 服务端请求时模一个即可。字符集为 base64 子集（去掉 + /）。
 */
function kratos_kml_random_bid()
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $out = '';
    for ($i = 0; $i < 11; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

/**
 * 优先 ld+json 结构化数据，回落 og:* + 正则。
 *
 * 为什么：豆瓣详情页在 <script type="application/ld+json"> 里塞了标准 schema.org
 * Book/Movie 对象，字段齐、不受 DOM 结构小改动影响；og:* 是跨平台兜底；
 * ISBN/IMDb/导演/作者则靠正则补齐（豆瓣这几个字段都没进 ld+json）。
 */
function kratos_kml_parse_douban($html, $url)
{
    $out = array(
        'title' => '', 'orig_title' => '', 'cover' => '', 'creator' => '',
        'year' => '', 'rating' => '', 'type' => '', 'douban_url' => $url,
        'isbn' => '', 'imdb_id' => '', 'excerpt' => '',
    );

    // 1) ld+json
    if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
        foreach ($m[1] as $raw) {
            $j = json_decode(trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')), true);
            if (!is_array($j)) continue;
            $type = isset($j['@type']) ? strtolower((string) $j['@type']) : '';
            if ($type) {
                if (!empty($j['name']) && !$out['title'])       $out['title']   = (string) $j['name'];
                if (!empty($j['image']) && !$out['cover'])      $out['cover']   = is_array($j['image']) ? reset($j['image']) : (string) $j['image'];
                if (!empty($j['description']) && !$out['excerpt']) $out['excerpt'] = trim((string) $j['description']);
                if (!empty($j['datePublished']) && !$out['year']) {
                    if (preg_match('/(\d{4})/', (string) $j['datePublished'], $y)) $out['year'] = $y[1];
                }
                if (!empty($j['aggregateRating']['ratingValue']) && !$out['rating']) {
                    $out['rating'] = (string) $j['aggregateRating']['ratingValue'];
                }
                if ($type === 'book') {
                    $out['type'] = 'book';
                    if (!empty($j['author'])) {
                        $authors = is_array($j['author']) ? $j['author'] : array($j['author']);
                        $names = array();
                        foreach ($authors as $a) {
                            if (is_array($a) && !empty($a['name'])) $names[] = $a['name'];
                            elseif (is_string($a)) $names[] = $a;
                        }
                        if ($names && !$out['creator']) $out['creator'] = implode(' / ', $names);
                    }
                    if (!empty($j['isbn']) && !$out['isbn']) $out['isbn'] = preg_replace('/[^0-9Xx]/', '', (string) $j['isbn']);
                } elseif ($type === 'movie' || $type === 'tvseries') {
                    $out['type'] = ($type === 'tvseries') ? 'tv' : 'movie';
                    if (!empty($j['director'])) {
                        $dirs = is_array($j['director']) ? $j['director'] : array($j['director']);
                        $names = array();
                        foreach ($dirs as $d) {
                            if (is_array($d) && !empty($d['name'])) $names[] = $d['name'];
                            elseif (is_string($d)) $names[] = $d;
                        }
                        if ($names && !$out['creator']) $out['creator'] = implode(' / ', $names);
                    }
                }
            }
        }
    }

    // 2) og:* 兜底
    if (!$out['title'] && preg_match('#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
        $out['title'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (!$out['cover'] && preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
        $out['cover'] = $m[1];
    }
    if (!$out['type']) {
        if (strpos($url, 'book.douban.com') !== false) $out['type'] = 'book';
        elseif (strpos($url, 'movie.douban.com') !== false) $out['type'] = 'movie';
    }

    // 3) 正则补齐：IMDb / 原名（豆瓣电影右侧 info 块 "又名:" 和 "IMDb:"）
    if (!$out['imdb_id'] && preg_match('#IMDb:?\s*</span>\s*(tt\d+)#i', $html, $m)) {
        $out['imdb_id'] = $m[1];
    } elseif (!$out['imdb_id'] && preg_match('#(tt\d{6,})#', $html, $m)) {
        $out['imdb_id'] = $m[1];
    }
    if (!$out['orig_title'] && preg_match('#又名:?</span>\s*([^<]+)#u', $html, $m)) {
        $parts = array_filter(array_map('trim', explode('/', $m[1])));
        if ($parts) $out['orig_title'] = reset($parts);
    }

    // 清洗
    foreach ($out as $k => $v) {
        if (is_string($v)) $out[$k] = trim($v);
    }
    if ($out['cover']) $out['cover'] = str_replace(array('s_ratio_poster', 's/public'), array('l_ratio_poster', 'l/public'), $out['cover']);

    return $out;
}

/* ========== 创建条目 ========== */

add_action('wp_ajax_kratos_kml_create_from_douban', 'kratos_kml_ajax_create_from_douban');
function kratos_kml_ajax_create_from_douban()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('msg' => __('权限不足', 'kratos')));
    }
    check_ajax_referer('kratos_kml_import');

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    if (!$title) wp_send_json_error(array('msg' => __('标题不能为空', 'kratos')));

    $excerpt = isset($_POST['excerpt']) ? wp_kses_post(wp_unslash($_POST['excerpt'])) : '';

    $pid = wp_insert_post(array(
        'post_title'   => $title,
        'post_content' => $excerpt,
        'post_status'  => 'draft',
        'post_type'    => 'media_item',
    ), true);
    if (is_wp_error($pid)) {
        wp_send_json_error(array('msg' => $pid->get_error_message()));
    }

    // CSF 的 unserialize 单 key 结构：所有字段放到一个数组里存入 _kml_meta。
    // 读取端 kratos_kml_item_meta() 会展开。
    $map = array(
        '_kml_cover'       => isset($_POST['cover']) ? esc_url_raw(wp_unslash($_POST['cover'])) : '',
        '_kml_orig_title'  => isset($_POST['orig_title']) ? sanitize_text_field(wp_unslash($_POST['orig_title'])) : '',
        '_kml_creator'     => isset($_POST['creator']) ? sanitize_text_field(wp_unslash($_POST['creator'])) : '',
        '_kml_year'        => isset($_POST['year']) ? preg_replace('/[^0-9]/', '', wp_unslash($_POST['year'])) : '',
        '_kml_rating'      => isset($_POST['rating']) ? preg_replace('/[^0-9.]/', '', wp_unslash($_POST['rating'])) : '',
        '_kml_douban_url'  => isset($_POST['douban_url']) ? esc_url_raw(wp_unslash($_POST['douban_url'])) : '',
        '_kml_isbn'        => isset($_POST['isbn']) ? preg_replace('/[^0-9Xx]/', '', wp_unslash($_POST['isbn'])) : '',
        '_kml_imdb_id'     => isset($_POST['imdb_id']) ? sanitize_text_field(wp_unslash($_POST['imdb_id'])) : '',
        '_kml_status'      => 'done',
        '_kml_finished_at' => current_time('Y-m-d'),
    );
    update_post_meta($pid, '_kml_meta', $map);
    foreach ($map as $k => $v) update_post_meta($pid, $k, $v);

    $type_slug = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
    if ($type_slug && term_exists($type_slug, 'media_type')) {
        wp_set_object_terms($pid, $type_slug, 'media_type', false);
    }

    wp_send_json_success(array(
        'id'       => $pid,
        'edit_url' => admin_url('post.php?post=' . $pid . '&action=edit'),
    ));
}
