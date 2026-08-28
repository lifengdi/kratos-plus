<?php
/**
 * ip2region 数据库管理：定时更新 + 查询封装
 *
 * 数据来源：lionsoul2014/ip2region GitHub master 分支 data/ 目录
 * 存储位置：wp-content/uploads/kratos-plus/ip2region/*.xdb（不打进主题包）
 *
 * 设置入口：「主题设置 → 全站配置 → 评论配置」
 *  - 频率/IPv6 开关复用 CSF（kratos_options），避免独立后台页
 *  - 「立即更新」按钮通过 admin-post + nonce 触发
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\\ip2region\\xdb\\Searcher', false)) {
    require_once __DIR__ . '/Searcher.class.php';
}

/* ============================================================
 *  常量 / 路径
 * ============================================================ */

const KRATOS_IP2REGION_OPT       = 'kratos_ip2region_runtime';   // 运行时状态：上次更新时间、状态文案
const KRATOS_IP2REGION_CRON_HOOK = 'kratos_ip2region_update_cron';
const KRATOS_IP2REGION_MIN_BYTES = 600000;

/**
 * 按主题「更新下载源」选项返回 ip2region xdb 的下载源候选列表（按优先级排序，依次尝试）。
 *
 * 复用 kratos_plus_should_use_gitee()（inc/theme-core.php），与主题自身升级源逻辑一致：
 *   auto   ：Asia/Shanghai 等国内时区走加速源，其他走 GitHub
 *   github ：强制 GitHub raw（仅一个候选）
 *   gitee  ：走加速源候选链（Gitee 官方仓库不允许匿名 raw；名称保留兼容既有选项）
 *
 * 加速源候选（IPv4）：
 *   1) jsDelivr CDN 镜像 GitHub（~11MB，jsDelivr 单文件 20MB 上限内）
 *   2) ghproxy.com 反代
 *   3) ghfast.top 反代（备用）
 *   4) 直连 GitHub raw（兜底）
 *
 * 加速源候选（IPv6，~36MB 超 jsDelivr 上限，跳过 jsDelivr）：
 *   1) mirror.ghproxy.com 反代
 *   2) ghfast.top 反代
 *   3) gh-proxy.com 反代
 *   4) 直连 GitHub raw（兜底）
 */
function kratos_ip2region_v4_urls() {
    $github = 'https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v4.xdb';
    if (function_exists('kratos_plus_should_use_gitee') && kratos_plus_should_use_gitee()) {
        return [
            'https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/ip2region_v4.xdb',
            'https://mirror.ghproxy.com/https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v4.xdb',
            'https://ghfast.top/https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v4.xdb',
            $github,
        ];
    }
    return [$github];
}

function kratos_ip2region_v6_urls() {
    $github = 'https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v6.xdb';
    if (function_exists('kratos_plus_should_use_gitee') && kratos_plus_should_use_gitee()) {
        return [
            'https://mirror.ghproxy.com/https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v6.xdb',
            'https://ghfast.top/https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v6.xdb',
            'https://gh-proxy.com/https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v6.xdb',
            $github,
        ];
    }
    return [$github];
}

function kratos_ip2region_dir() {
    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'kratos-plus/ip2region';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    return $dir;
}

function kratos_ip2region_v4_path() {
    return kratos_ip2region_dir() . '/ip2region_v4.xdb';
}

function kratos_ip2region_v6_path() {
    return kratos_ip2region_dir() . '/ip2region_v6.xdb';
}

/* ============================================================
 *  设置读写：
 *    - 用户配置（频率/IPv6）从 CSF 的 kratos_options 读
 *    - 运行时状态（上次更新时间、状态文案）存独立 option，避免污染主题设置
 * ============================================================ */

function kratos_ip2region_get_frequency() {
    $allowed = ['hourly', 'daily', 'weekly', 'monthly', 'disabled'];
    $val = function_exists('kratos_option') ? kratos_option('comment_ip2region_frequency', 'monthly') : 'monthly';
    return in_array($val, $allowed, true) ? $val : 'monthly';
}

function kratos_ip2region_ipv6_enabled() {
    return function_exists('kratos_option') ? (bool) kratos_option('comment_ip2region_ipv6', false) : false;
}

function kratos_ip2region_get_runtime() {
    $defaults = [
        'last_update_v4' => 0,
        'last_update_v6' => 0,
        'last_status'    => '',
    ];
    $opt = get_option(KRATOS_IP2REGION_OPT, []);
    return array_merge($defaults, is_array($opt) ? $opt : []);
}

function kratos_ip2region_update_runtime(array $patch) {
    update_option(KRATOS_IP2REGION_OPT, array_merge(kratos_ip2region_get_runtime(), $patch), false);
}

/* ============================================================
 *  下载 / 校验 / 替换
 * ============================================================ */

function kratos_ip2region_download_xdb($url, $target_path) {
    $tmp_path = $target_path . '.tmp';

    // 大文件下载给足 timeout（IPv6 xdb ~36MB，慢线路可能超过 30s）；
    // 反代不通时依赖上层 kratos_ip2region_download_xdb_multi 切换到下一个候选，无需在此单点缩短。
    $response = wp_remote_get($url, [
        'timeout'     => 60,
        'redirection' => 5,
        'stream'      => true,
        'filename'    => $tmp_path,
        'user-agent'  => 'Kratos-Plus/' . (defined('THEME_VERSION') ? THEME_VERSION : '1.0'),
    ]);

    if (is_wp_error($response)) {
        @unlink($tmp_path);
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        @unlink($tmp_path);
        return new WP_Error('http_error', sprintf('下载失败：HTTP %d', $code));
    }

    if (!file_exists($tmp_path) || filesize($tmp_path) < KRATOS_IP2REGION_MIN_BYTES) {
        @unlink($tmp_path);
        return new WP_Error('size_error', '下载文件大小异常，可能是网络中断或源被限速');
    }

    try {
        \ip2region\xdb\Util::verifyFromFile($tmp_path);
    } catch (\Throwable $e) {
        @unlink($tmp_path);
        return new WP_Error('xdb_invalid', 'xdb 文件校验失败：' . $e->getMessage());
    }

    if (!@rename($tmp_path, $target_path)) {
        @unlink($tmp_path);
        return new WP_Error('rename_failed', '替换数据库文件失败，请检查目录写权限');
    }

    return true;
}

/**
 * 依次尝试多个候选源下载，命中第一个成功的即返回。
 * 返回：成功时 true；全部失败时返回携带聚合错误信息的 WP_Error。
 */
function kratos_ip2region_download_xdb_multi(array $urls, $target_path) {
    $errors = [];
    foreach ($urls as $url) {
        $r = kratos_ip2region_download_xdb($url, $target_path);
        if (!is_wp_error($r)) {
            return true;
        }
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $errors[] = $host . ': ' . $r->get_error_message();
    }
    return new WP_Error('all_sources_failed', '全部源均失败 — ' . implode(' | ', $errors));
}

/**
 * 执行一次更新
 * @return array {ok:bool, message:string}
 */
function kratos_ip2region_run_update() {
    $messages = [];
    $patch    = [];
    $any_fail = false;

    $r4 = kratos_ip2region_download_xdb_multi(kratos_ip2region_v4_urls(), kratos_ip2region_v4_path());
    if (is_wp_error($r4)) {
        $any_fail   = true;
        $messages[] = 'IPv4 ' . $r4->get_error_message();
    } else {
        $patch['last_update_v4'] = time();
        $messages[] = sprintf('IPv4 数据库更新成功（%s）', size_format(filesize(kratos_ip2region_v4_path())));
    }

    if (kratos_ip2region_ipv6_enabled()) {
        $r6 = kratos_ip2region_download_xdb_multi(kratos_ip2region_v6_urls(), kratos_ip2region_v6_path());
        if (is_wp_error($r6)) {
            $any_fail   = true;
            $messages[] = 'IPv6 ' . $r6->get_error_message();
        } else {
            $patch['last_update_v6'] = time();
            $messages[] = sprintf('IPv6 数据库更新成功（%s）', size_format(filesize(kratos_ip2region_v6_path())));
        }
    }

    $patch['last_status'] = sprintf('[%s] %s', wp_date('Y-m-d H:i:s'), implode('；', $messages));
    kratos_ip2region_update_runtime($patch);

    return ['ok' => !$any_fail, 'message' => implode('；', $messages)];
}

/* ============================================================
 *  WP-Cron
 * ============================================================ */

add_action(KRATOS_IP2REGION_CRON_HOOK, 'kratos_ip2region_run_update');

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => '每月一次',
        ];
    }
    return $schedules;
});

function kratos_ip2region_reschedule_cron() {
    $frequency = kratos_ip2region_get_frequency();

    if ($frequency === 'disabled') {
        if (wp_next_scheduled(KRATOS_IP2REGION_CRON_HOOK)) {
            wp_clear_scheduled_hook(KRATOS_IP2REGION_CRON_HOOK);
        }
        return;
    }

    // 现有排期已经是目标频率就什么都别做。
    // 这个函数挂在 init 上，会在每个请求（含每个前台请求）跑一次；
    // 早先无条件 clear + schedule，等于每请求两次 UPDATE wp_options
    // 改写整个 cron 数组（那是个很大的 option）。
    if (wp_get_schedule(KRATOS_IP2REGION_CRON_HOOK) === $frequency) {
        return;
    }

    wp_clear_scheduled_hook(KRATOS_IP2REGION_CRON_HOOK);
    wp_schedule_event(time() + HOUR_IN_SECONDS, $frequency, KRATOS_IP2REGION_CRON_HOOK);
}

add_action('init', 'kratos_ip2region_reschedule_cron');

// 主题设置保存后，按新频率重排 cron（CSF 提供 csf_{unique}_saved 钩子）
add_action('csf_kratos_options_saved', 'kratos_ip2region_reschedule_cron');

// 主题切走时清理 cron
add_action('switch_theme', function () {
    wp_clear_scheduled_hook(KRATOS_IP2REGION_CRON_HOOK);
});

/* ============================================================
 *  查询封装（供评论扩展调用）
 * ============================================================ */

/**
 * 查询 IP 归属地
 * @param string $ip
 * @return array {country:string, region:string, city:string}
 */
function kratos_ip2region_lookup($ip) {
    $empty = ['country' => '', 'region' => '', 'city' => ''];

    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return $empty;
    }

    // 私有 / 保留 / 回环 IP 直接返回「本地」，不查 ip2region（它会返回一串 Reserved）
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['country' => '本地', 'region' => '', 'city' => ''];
    }

    $is_ipv6 = (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    $db_path = $is_ipv6 ? kratos_ip2region_v6_path() : kratos_ip2region_v4_path();

    if (!file_exists($db_path)) {
        return $empty;
    }

    static $searchers = ['v4' => null, 'v6' => null];
    $key = $is_ipv6 ? 'v6' : 'v4';

    try {
        if ($searchers[$key] === null) {
            $version = $is_ipv6 ? \ip2region\xdb\IPv6::default() : \ip2region\xdb\IPv4::default();
            $searchers[$key] = \ip2region\xdb\Searcher::newWithFileOnly($version, $db_path);
        }

        $region = $searchers[$key]->search($ip);
        if (!is_string($region) || $region === '') {
            return $empty;
        }
    } catch (\Throwable $e) {
        return $empty;
    }

    // xdb 记录有两种字段布局，必须先判定再取字段，否则会把「城市」当成「省份」：
    //   新版（ip2region 3.x，本主题下载的 ip2region_v4/v6.xdb 即此格式）：
    //       国家|省份|城市|ISP|国家码   例：中国|江苏省|南京市|电信|CN
    //   旧版（ip2region 2.x 的 ip2region.xdb）：
    //       国家|区域|省份|城市|ISP     例：中国|0|江苏省|南京市|电信
    // 判定依据：新版最后一段是两位字母国家码，旧版该段是 ISP（中文或较长英文）。
    // 无效值：'0'、空串、'Reserved'（保留段）、'Unknown'、'未知'
    $parts = explode('|', $region);
    $invalid = ['', '0', 'reserved', 'unknown', '未知', 'n/a'];
    $clean = function ($v) use ($invalid) {
        $v = isset($v) ? trim((string) $v) : '';
        return in_array(strtolower($v), $invalid, true) ? '' : $v;
    };

    $is_new_layout = isset($parts[4]) && preg_match('/^[A-Za-z]{2}$/', trim((string) $parts[4]));
    $region_idx = $is_new_layout ? 1 : 2;
    $city_idx   = $is_new_layout ? 2 : 3;

    return [
        'country' => $clean($parts[0] ?? ''),
        'region'  => $clean($parts[$region_idx] ?? ''),
        'city'    => $clean($parts[$city_idx] ?? ''),
    ];
}

/* ============================================================
 *  「立即更新」处理（admin-post）
 * ============================================================ */

add_action('admin_post_kratos_ip2region_update_now', function () {
    if (!current_user_can('manage_options')) {
        wp_die('权限不足');
    }
    check_admin_referer('kratos_ip2region_update_now');

    kratos_ip2region_update_runtime(['last_status' => sprintf('[%s] 后台下载已调度，请稍后刷新查看结果…', wp_date('Y-m-d H:i:s'))]);
    kratos_dispatch_bg_task('kratos_ip2region_run_update');

    set_transient('kratos_ip2region_flash_' . get_current_user_id(), [
        'ok'      => true,
        'message' => '后台下载已调度，数据库将在后台更新，请稍后刷新页面查看结果。',
    ], MINUTE_IN_SECONDS);

    $redirect = wp_get_referer() ?: admin_url('admin.php?page=kratos-options');
    wp_safe_redirect($redirect);
    exit;
});


// 在主题设置页顶部展示「立即更新」结果（如果有）
add_action('admin_notices', function () {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || strpos((string) $screen->id, 'kratos-options') === false) {
        return;
    }
    $key = 'kratos_ip2region_flash_' . get_current_user_id();
    $flash = get_transient($key);
    if (!$flash) {
        return;
    }
    delete_transient($key);
    $class = !empty($flash['ok']) ? 'notice-success' : 'notice-error';
    printf(
        '<div class="notice %s is-dismissible"><p>%s</p></div>',
        esc_attr($class),
        esc_html($flash['message'])
    );
});

/* ============================================================
 *  状态面板渲染（嵌入到 CSF 评论配置 section）
 * ============================================================ */

function kratos_ip2region_render_status() {
    $runtime    = kratos_ip2region_get_runtime();
    $v4_exists  = file_exists(kratos_ip2region_v4_path());
    $v6_exists  = file_exists(kratos_ip2region_v6_path());
    $v4_size    = $v4_exists ? size_format(filesize(kratos_ip2region_v4_path())) : '—';
    $v6_size    = $v6_exists ? size_format(filesize(kratos_ip2region_v6_path())) : '—';
    $v4_time    = !empty($runtime['last_update_v4']) ? wp_date('Y-m-d H:i:s', $runtime['last_update_v4']) : '从未更新';
    $v6_time    = !empty($runtime['last_update_v6']) ? wp_date('Y-m-d H:i:s', $runtime['last_update_v6']) : '从未更新';
    $next       = wp_next_scheduled(KRATOS_IP2REGION_CRON_HOOK);
    $next_text  = $next ? wp_date('Y-m-d H:i:s', $next) : '未排程';
    $ipv6_on    = kratos_ip2region_ipv6_enabled();

    $update_url = wp_nonce_url(
        admin_url('admin-post.php?action=kratos_ip2region_update_now'),
        'kratos_ip2region_update_now'
    ) . '#tab=' . sanitize_title(__('评论配置', 'kratos')) . '/' . sanitize_title(__('IP 归属地数据库', 'kratos'));

    $dot_ok   = '<span style="color:#46b450;font-weight:bold">●</span>';
    $dot_off  = '<span style="color:#a0a5aa;font-weight:bold">○</span>';
    $dot_err  = '<span style="color:#dc3232;font-weight:bold">●</span>';

    ob_start();
    ?>
    <div class="kratos-ip2region-status" style="background:#f8f9fa;border:1px solid #e1e5eb;border-radius:4px;padding:14px 18px;margin-bottom:8px">
        <table style="width:100%;border-collapse:collapse;font-size:13px;line-height:1.8">
            <tr>
                <td style="width:140px;color:#555">IPv4 数据库</td>
                <td>
                    <?php echo $v4_exists ? $dot_ok . ' 已就绪' : $dot_err . ' 未下载'; ?>
                    &nbsp;|&nbsp; 大小：<code><?php echo esc_html($v4_size); ?></code>
                    &nbsp;|&nbsp; 上次更新：<code><?php echo esc_html($v4_time); ?></code>
                </td>
            </tr>
            <tr>
                <td style="color:#555">IPv6 数据库</td>
                <td>
                    <?php
                    if (!$ipv6_on) {
                        echo $dot_off . ' 已禁用';
                    } else {
                        echo $v6_exists ? $dot_ok . ' 已就绪' : $dot_err . ' 未下载';
                    }
                    ?>
                    &nbsp;|&nbsp; 大小：<code><?php echo esc_html($v6_size); ?></code>
                    &nbsp;|&nbsp; 上次更新：<code><?php echo esc_html($v6_time); ?></code>
                </td>
            </tr>
            <tr>
                <td style="color:#555">下次自动更新</td>
                <td><code><?php echo esc_html($next_text); ?></code></td>
            </tr>
            <?php if (!empty($runtime['last_status'])): ?>
            <tr>
                <td style="color:#555">上次执行结果</td>
                <td style="color:#666"><code><?php echo esc_html($runtime['last_status']); ?></code></td>
            </tr>
            <?php endif; ?>
        </table>
        <div style="margin-top:14px">
            <a href="<?php echo esc_url($update_url); ?>"
               class="button button-primary"
               onclick="return confirm('确定立即更新数据库？IPv4 约 10MB，IPv6 约 36MB。');">立即更新</a>
            <?php
            $use_cn    = function_exists('kratos_plus_should_use_gitee') && kratos_plus_should_use_gitee();
            $src_url   = 'https://github.com/lionsoul2014/ip2region';
            $src_label = $use_cn ? 'lionsoul2014/ip2region（国内加速：jsDelivr / ghproxy 镜像候选链）' : 'lionsoul2014/ip2region @ GitHub';
            ?>
            <span style="color:#888;margin-left:10px">数据来源：<a href="<?php echo esc_url($src_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($src_label); ?></a>（Apache-2.0）</span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
